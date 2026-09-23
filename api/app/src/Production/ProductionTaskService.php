<?php

declare(strict_types=1);

namespace Amor\Api\Production;

use Amor\Api\ApiException;
use Amor\Api\SpecialOrder\NormalizedSourceType;
use Amor\Api\SpecialOrder\SpecialOrderFgAllocationService;
use Amor\Api\SpecialOrder\SpecialOrderRepository;
use PDO;

/**
 * "Task per Divisi" — Production's consolidated task list, combining
 * demand from all four operational sources (task's own "Business Goal"):
 * PO Reguler, Pesanan Khusus Toko, Pesanan Non-Toko, Replacement Reject.
 *
 * This is a READ-ONLY aggregation over data that already lives elsewhere
 * — it never becomes a second source of truth (task's own explicit "Do
 * NOT create a second independent Actual Produksi source"):
 *   - PO Reguler rows reuse ProductionTargetService (live PO target) +
 *     ProductionRepository's existing production_item.aktual/reject —
 *     the EXACT same numbers Ceklis Produksi shows. Aggregated per
 *     product (task's own "PO REGULER: may be aggregated per product/
 *     division/date"), and read-only HERE — edited only through Ceklis
 *     Produksi's own PATCH /api/production/{id} (avoids "operators input
 *     Actual twice").
 *   - Pesanan Khusus Toko / Pesanan Non-Toko rows reuse
 *     SpecialOrderRepository::findProductionDemandItems() — the SAME
 *     query "Order Masuk / Demand Tambahan" already uses — one row PER
 *     special_order_item, never aggregated (task's own "do NOT aggregate
 *     if Catatan Khusus/order reference/customer differs"). These ARE
 *     editable here (via POST /api/special-orders/{id}/actual), because
 *     nowhere else in the app has ever offered actual/reject entry for
 *     them.
 *   - Replacement Reject: that module does not exist yet. Task's own
 *     explicit instruction: "do NOT fabricate records" — this always
 *     contributes zero rows, never an estimate.
 *
 * Target/Actual/Reject/Sisa semantics (task's own exact rules):
 *   Target       = required good output
 *   Actual       = GOOD output only (never includes reject)
 *   Reject       = bad/rejected output, tracked separately, never
 *                  subtracted from Sisa
 *   Sisa         = max(0, Target - Actual)   -- NOT Target-(Actual+Reject)
 *   Status       = Belum Diproduksi (actual=0) / Belum Selesai (0<actual<target)
 *                  / Selesai (actual>=target) -- a derived display value,
 *                  never persisted (task's own "do not invent a complex
 *                  lifecycle if not needed").
 */
final class ProductionTaskService
{
    public const SOURCE_PO_REGULER = 'po_reguler';
    public const SOURCE_PESANAN_KHUSUS = 'pesanan_khusus';
    public const SOURCE_PESANAN_NON_TOKO = 'pesanan_non_toko';
    public const SOURCE_REPLACEMENT_REJECT = 'replacement_reject';

    private ProductionRepository $productionRepo;
    private ProductionTargetService $targets;
    private SpecialOrderRepository $specialOrderRepo;
    private SpecialOrderFgAllocationService $allocSvc;

    public function __construct(private PDO $pdo)
    {
        $this->productionRepo = new ProductionRepository();
        $this->targets = new ProductionTargetService();
        $this->specialOrderRepo = new SpecialOrderRepository();
        $this->allocSvc = new SpecialOrderFgAllocationService($this->pdo);
    }

    /** GET /api/production-tasks — Task per Divisi for ONE division+date. */
    public function tasksForDivision(string $tanggal, int $divisionId, ?string $sourceFilter, ?string $statusFilter): array
    {
        $division = $this->productionRepo->findDivision($this->pdo, $divisionId);
        if ($division === null) {
            throw new ApiException(404, 'DIVISION_NOT_FOUND', 'Division not found');
        }
        if ((int) $division['is_verification'] === 1) {
            throw new ApiException(400, 'DIVISION_OUT_OF_SCOPE', "'{$division['name']}' is a Finishgood & Packing verification division — not part of production task tracking");
        }

        $tasks = $this->buildTasks($tanggal, (int) $division['factory_id'], $divisionId, $sourceFilter);
        if ($statusFilter !== null) {
            $tasks = array_values(array_filter($tasks, static fn ($t) => $t['statusCode'] === $statusFilter));
        }

        return [
            'tanggal' => $tanggal,
            'divisionId' => $divisionId,
            'divisionName' => $division['name'],
            'factoryId' => (int) $division['factory_id'],
            'factoryName' => $division['factory_name'],
            'tasks' => $tasks,
            'summary' => $this->buildSummary($tasks),
        ];
    }

    /**
     * GET /api/production-tasks/factory — every real production division
     * in a factory, for "Print Semua Divisi" (one division per print
     * page) and any future "all divisions at once" view. No filters —
     * always the full, unfiltered task list per division, matching what
     * a physical worksheet needs.
     */
    public function tasksForFactory(string $tanggal, int $factoryId): array
    {
        $stmt = $this->pdo->prepare('SELECT division_id FROM division WHERE factory_id = ? AND is_verification = 0 ORDER BY name');
        $stmt->execute([$factoryId]);
        $divisionIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $factoryNameStmt = $this->pdo->prepare('SELECT name FROM factory WHERE factory_id = ?');
        $factoryNameStmt->execute([$factoryId]);
        $factoryName = $factoryNameStmt->fetchColumn();
        if ($factoryName === false) {
            throw new ApiException(404, 'FACTORY_NOT_FOUND', 'Factory not found');
        }

        $divisions = [];
        foreach ($divisionIds as $divisionId) {
            $divisions[] = $this->tasksForDivision($tanggal, $divisionId, null, null);
        }

        return [
            'tanggal' => $tanggal,
            'factoryId' => $factoryId,
            'factoryName' => $factoryName,
            'divisions' => $divisions,
        ];
    }

    /** @return array<int,array> */
    private function buildTasks(string $tanggal, int $factoryId, int $divisionId, ?string $sourceFilter): array
    {
        $tasks = [];

        if ($sourceFilter === null || $sourceFilter === self::SOURCE_PO_REGULER) {
            $liveTargets = $this->targets->targetsByProduct($this->pdo, $tanggal, $factoryId, $divisionId);
            $run = $this->productionRepo->findRunByDateDivision($this->pdo, $tanggal, $divisionId);
            $items = $run !== null ? $this->productionRepo->findItems($this->pdo, (int) $run['production_run_id']) : [];
            foreach ($liveTargets as $productId => $t) {
                $item = $items[$productId] ?? null;
                $aktual = $item !== null ? (float) $item['aktual'] : 0.0;
                $reject = $item !== null ? (float) $item['reject'] : 0.0;
                $tasks[] = $this->buildTaskRow(
                    self::SOURCE_PO_REGULER,
                    'PO Reguler',
                    $t['productName'],
                    null,
                    (float) $t['target'],
                    $aktual,
                    $reject,
                    $item['keterangan'] ?? null,
                    false,
                    null,
                    null,
                    $productId
                );
            }
        }

        if ($sourceFilter === null || $sourceFilter === self::SOURCE_PESANAN_KHUSUS || $sourceFilter === self::SOURCE_PESANAN_NON_TOKO) {
            $rows = $this->specialOrderRepo->findProductionDemandItems($this->pdo, ['divisionId' => $divisionId, 'tanggal' => $tanggal]);
            foreach ($rows as $r) {
                $srcType = $r['source_type'] === 'toko_khusus' ? self::SOURCE_PESANAN_KHUSUS : self::SOURCE_PESANAN_NON_TOKO;
                if ($sourceFilter !== null && $sourceFilter !== $srcType) {
                    continue;
                }
                // Line-level traceability must retain the real sub-source
                // (task's own "Final Blocker Fix" Section G: "line-level
                // traceability MUST retain actual source") — $srcType
                // itself stays the existing 2-way po_reguler/pesanan_khusus/
                // pesanan_non_toko/replacement_reject filter+badge-color
                // axis (unchanged, still used by the Sumber filter buttons
                // and ui_task_source_badge()'s color), but the LABEL text
                // now shows CS/Sales Executive/Konsumen Langsung/Umum
                // instead of collapsing every non-toko row into "Pesanan
                // Non-Toko".
                $normalizedSourceType = NormalizedSourceType::fromSpecialOrder((string) $r['source_type'], $r['non_store_source'] ?? null);
                $sourceLabel = $srcType === self::SOURCE_PESANAN_KHUSUS ? 'Pesanan Khusus' : NormalizedSourceType::label($normalizedSourceType);
                $who = $srcType === self::SOURCE_PESANAN_KHUSUS ? ($r['store_name'] ?? '-') : ($r['customer_name'] ?? '-');
                $reference = $r['order_no'] . ' — ' . $who;
                // Existing FG Allocation Bridge: the production TARGET only
                // shows the qty that actually still requires production —
                // whatever this item already has committed from General FG
                // (active or already consumed, never released) is netted
                // out here, before Actual/Reject entry even starts (task's
                // own worked example: order 40, allocated 35 -> target 5,
                // never 40).
                $allocatedFromGeneralFg = $r['item_type'] === 'existing_product' && $r['product_id'] !== null
                    ? $this->allocSvc->allocationSummaryForItem((int) $r['special_order_item_id'])['committed']
                    : 0.0;
                $target = max(0.0, (float) $r['qty'] - $allocatedFromGeneralFg);
                $tasks[] = $this->buildTaskRow(
                    $srcType,
                    $sourceLabel,
                    $r['item_name_snapshot'],
                    $reference,
                    $target,
                    (float) $r['aktual_produksi'],
                    (float) $r['reject_produksi'],
                    $r['special_note'],
                    true,
                    (int) $r['special_order_item_id'],
                    (int) $r['special_order_id'],
                    null,
                    (int) $r['order_version']
                );
            }
        }

        // Replacement Reject — module does not exist yet; deliberately
        // zero rows (task's own explicit "do NOT fabricate records").
        // The source enum/type above is already ready for it the moment
        // that module ships — no further Task per Divisi change needed.

        return $tasks;
    }

    private function buildTaskRow(
        string $source,
        string $sourceLabel,
        string $taskName,
        ?string $reference,
        float $target,
        float $aktual,
        float $reject,
        ?string $catatan,
        bool $editable,
        ?int $itemId,
        ?int $orderId,
        ?int $productId,
        ?int $orderVersion = null
    ): array {
        $sisa = max(0.0, $target - $aktual);
        [$statusCode, $statusLabel] = self::classifyStatus($aktual, $target);
        return [
            'source' => $source,
            'sourceLabel' => $sourceLabel,
            'taskName' => $taskName,
            'reference' => $reference,
            'target' => $target,
            'aktual' => $aktual,
            'reject' => $reject,
            'sisa' => $sisa,
            'catatanKhusus' => $catatan,
            'statusCode' => $statusCode,
            'statusLabel' => $statusLabel,
            'editable' => $editable,
            'itemId' => $itemId,
            'orderId' => $orderId,
            'productId' => $productId,
            'orderVersion' => $orderVersion,
        ];
    }

    /**
     * Task's own exact status rule, in order:
     *   1. actual == 0          -> Belum Diproduksi
     *   2. actual >= target     -> Selesai (also covers overproduction —
     *                              Task per Divisi has no separate
     *                              "Overproduction" state; that remains
     *                              Ceklis Produksi's own richer
     *                              classification, untouched)
     *   3. otherwise            -> Belum Selesai
     * @return array{0:string,1:string}
     */
    private static function classifyStatus(float $aktual, float $target): array
    {
        $eps = 0.0001;
        if ($aktual <= $eps) {
            return ['belum_diproduksi', 'Belum Diproduksi'];
        }
        if ($aktual + $eps >= $target) {
            return ['selesai', 'Selesai'];
        }
        return ['belum_selesai', 'Belum Selesai'];
    }

    /** @param array<int,array> $tasks */
    private function buildSummary(array $tasks): array
    {
        $totalTarget = array_sum(array_column($tasks, 'target'));
        $totalAktual = array_sum(array_column($tasks, 'aktual'));
        $totalReject = array_sum(array_column($tasks, 'reject'));
        $sisa = max(0.0, $totalTarget - $totalAktual);
        $progressPct = $totalTarget > 0.0001 ? round(($totalAktual / $totalTarget) * 100, 1) : 0.0;
        return [
            'totalProdukTask' => count($tasks),
            'totalTarget' => $totalTarget,
            'totalAktual' => $totalAktual,
            'totalReject' => $totalReject,
            'sisaTarget' => $sisa,
            'progressPct' => $progressPct,
        ];
    }
}
