<?php

declare(strict_types=1);

namespace Amor\Api\Delivery;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Fg\FgRepository;
use Amor\Api\SpecialOrder\SpecialOrderFgAllocationRepository;
use PDO;

/**
 * Business orchestration for the Phase 5 Draft DO document — the
 * counterpart to Production\ProductionService / Fg\FgService, but sourced
 * from Phase 2's authoritative store-level PO (never Production/FG) and
 * ending in a printable, shippable document instead of a stock write
 * itself (see ShipmentService for the one and only stock-writing action).
 *
 * Core rules enforced here (task sections throughout):
 *   - DO identity is (tanggal, store_id) — ONE per store per day,
 *     enforced by migration 0006's uq_delivery_order_open_store, never
 *     scoped by shipment_group or factory (a store's demand can span both
 *     Karangtengah and Cibadak — see DoTargetService's own docblock).
 *   - Draft/preprinted DO NEVER writes to stock_ledger — this class never
 *     imports or calls anything that touches it.
 *   - planned_qty always comes from live Phase 2 PO (po_store_item),
 *     never hand-typed as the default workflow.
 *   - refreshFromPo() never reduces a product's planned_qty below its
 *     already-shipped quantity (task section 7/8/30's explicit floor).
 *   - DO numbers are server-generated, transactional, and reuse the exact
 *     legacy format ("DO/KRM/{seq:3}/{romanMonth}/{yyyy}", audited from
 *     amorcakes-manufacturing-v5-slate(2).html's doNomor()) via the
 *     already-built DocumentSequenceService.
 */
final class DoService
{
    private DoRepository $repo;
    private DoTargetService $targets;
    private FgRepository $fg;

    private SpecialOrderFgAllocationRepository $allocRepo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new DoRepository();
        $this->targets = new DoTargetService();
        $this->fg = new FgRepository();
        $this->allocRepo = new SpecialOrderFgAllocationRepository();
    }

    /** GET /api/do/preview — live PO demand for a store/date, no document created. */
    public function loadPreview(string $tanggal, int $storeId): array
    {
        $store = $this->requireStore($storeId);
        $demand = $this->targets->storeDemandByProduct($this->pdo, $tanggal, $storeId);
        return [
            'tanggal' => $tanggal,
            'storeId' => $storeId,
            'storeName' => $store['canonical_name'],
            'items' => array_values($demand),
            'summary' => ['productCount' => count($demand), 'totalPlanned' => array_sum(array_column($demand, 'planned'))],
        ];
    }

    /** @return array<int,array{storeId:int,storeName:string}> */
    public function storesWithPo(string $tanggal, int $factoryId): array
    {
        $this->requireFactory($factoryId);
        return $this->targets->storesWithPo($this->pdo, $tanggal, $factoryId);
    }

    /**
     * POST /api/do — creates today's DO for (tanggal,storeId) if one
     * doesn't already exist (any OPEN one — status not in
     * shipped/cancelled), snapshotting the current cross-factory PO
     * demand. Idempotent at the (tanggal,storeId) identity level, same as
     * Production/FG's createDraft.
     */
    public function createDraft(string $tanggal, int $storeId, int $userId): array
    {
        $store = $this->requireStore($storeId);

        $existing = $this->repo->lockExistingDo($this->pdo, $tanggal, $storeId);
        if ($existing !== null) {
            return $this->buildDoDto($existing, $store);
        }

        $demand = $this->targets->storeDemandByProduct($this->pdo, $tanggal, $storeId);
        if ($demand === []) {
            throw new ApiException(400, 'NO_PO_DEMAND', 'No PO demand found for this store/date — nothing to generate a DO from');
        }

        $sourceVersions = $this->snapshotSourceVersions($tanggal, $demand);
        $docNo = $this->repo->allocateDoNumber($this->pdo, $tanggal);
        $do = $this->repo->createDo($this->pdo, $tanggal, $storeId, $docNo, json_encode($sourceVersions), $userId);
        $doId = (int) $do['delivery_order_id'];

        foreach ($demand as $productId => $d) {
            $this->repo->insertDoItem($this->pdo, $doId, $productId, $d['planned']);
        }

        $do = $this->repo->lockExistingDo($this->pdo, $tanggal, $storeId);
        return $this->buildDoDto($do, $store);
    }

    public function getDo(int $doId): array
    {
        $do = $this->repo->findDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Delivery Order not found');
        }
        return $this->buildDoDto($do, $do);
    }

    /** @return array<int,array> */
    public function listDos(?string $tanggal, ?int $storeId, ?string $status): array
    {
        $dos = $this->repo->findDos($this->pdo, $tanggal, $storeId, $status);
        return $this->buildListDtos($dos);
    }

    /** @return array<int,array> */
    public function listDosForFactory(string $tanggal, int $factoryId): array
    {
        $this->requireFactory($factoryId);
        $dos = $this->repo->findDosForFactory($this->pdo, $tanggal, $factoryId);
        return $this->buildListDtos($dos);
    }

    /**
     * POST /api/do/{id}/refresh-po — re-syncs planned_qty from the live
     * store PO. Allowed while draft/preprinted only; never reduces a
     * product below its already-shipped quantity (task section 7/8/30).
     */
    public function refreshFromPo(int $doId, int $expectedVersion, int $userId, ?string $requestId): array
    {
        $do = $this->repo->lockDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Delivery Order not found');
        }
        $this->assertRefreshable($do);
        $store = $this->requireStore((int) $do['store_id']);
        $tanggal = (string) $do['tanggal'];

        $demand = $this->targets->storeDemandByProduct($this->pdo, $tanggal, (int) $do['store_id']);
        $shippedByProduct = $this->repo->shippedQtyByProduct($this->pdo, $doId);
        $existingItems = $this->repo->findDoItems($this->pdo, $doId);

        $touched = 0;
        foreach ($demand as $productId => $d) {
            $shipped = $shippedByProduct[$productId] ?? 0.0;
            $newPlanned = max($d['planned'], $shipped); // never below already-shipped
            if (isset($existingItems[$productId])) {
                if (abs((float) $existingItems[$productId]['planned_qty'] - $newPlanned) > 0.0001) {
                    $this->repo->updateDoItemPlanned($this->pdo, (int) $existingItems[$productId]['delivery_order_item_id'], $newPlanned);
                    $touched++;
                }
            } else {
                $this->repo->insertDoItem($this->pdo, $doId, $productId, $newPlanned);
                $touched++;
            }
        }
        // Products no longer in live PO demand at all: floor to already-shipped
        // (never deleted, never silently zeroed below what physically shipped).
        foreach ($existingItems as $productId => $item) {
            if (!isset($demand[$productId])) {
                $shipped = $shippedByProduct[$productId] ?? 0.0;
                if (abs((float) $item['planned_qty'] - $shipped) > 0.0001) {
                    $this->repo->updateDoItemPlanned($this->pdo, (int) $item['delivery_order_item_id'], $shipped);
                    $touched++;
                }
            }
        }

        $sourceVersions = $this->snapshotSourceVersions($tanggal, $demand);
        $bumped = $this->repo->bumpVersion($this->pdo, $doId, $expectedVersion, 'source_po_version_json = ?', [json_encode($sourceVersions)]);
        $this->assertVersionBumpSucceeded($doId, $expectedVersion, $bumped);

        Audit::write(
            $this->pdo, $requestId, $userId, 'do.refresh_from_po', 'delivery_order', (string) $doId,
            'ok', $expectedVersion, $expectedVersion + 1, ['itemsTouched' => $touched]
        );

        $do = $this->repo->findDoById($this->pdo, $doId);
        return $this->buildDoDto($do, $store);
    }

    /** POST /api/do/{id}/preprint — draft or preprinted -> preprinted. Reprinting is allowed and does not create a new DO. */
    public function preprint(int $doId, int $expectedVersion, int $userId, ?string $requestId): array
    {
        $do = $this->repo->lockDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Delivery Order not found');
        }
        if (!in_array($do['status'], ['draft', 'preprinted'], true)) {
            throw new ApiException(409, 'INVALID_STATUS', "Only a draft or preprinted document can be (re)preprinted (current status: {$do['status']})");
        }

        $bumped = $this->repo->bumpVersion(
            $this->pdo, $doId, $expectedVersion,
            "status = 'preprinted', preprinted_at = UTC_TIMESTAMP(), preprinted_by = ?", [$userId]
        );
        $this->assertVersionBumpSucceeded($doId, $expectedVersion, $bumped);

        Audit::write($this->pdo, $requestId, $userId, 'do.preprint', 'delivery_order', (string) $doId, 'ok', $expectedVersion, $expectedVersion + 1, null);

        $do = $this->repo->findDoById($this->pdo, $doId);
        return $this->buildDoDto($do, $do);
    }

    /**
     * POST /api/do/{id}/cancel — draft/preprinted with ZERO shipped qty
     * only (task section 23/30: "cannot cancel if already shipped
     * quantities exist"). No hard delete.
     */
    public function cancel(int $doId, int $expectedVersion, string $reason, int $userId, ?string $requestId): array
    {
        if (trim($reason) === '') {
            throw new ApiException(400, 'REASON_REQUIRED', 'A cancellation reason is required');
        }
        $do = $this->repo->lockDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Delivery Order not found');
        }
        if (!in_array($do['status'], ['draft', 'preprinted'], true)) {
            throw new ApiException(409, 'INVALID_STATUS', "Only a draft or preprinted document can be cancelled (current status: {$do['status']})");
        }
        $shippedByProduct = $this->repo->shippedQtyByProduct($this->pdo, $doId);
        if (array_sum($shippedByProduct) > 0.0001) {
            throw new ApiException(409, 'CANNOT_CANCEL_SHIPPED', 'This DO already has shipped quantity — cancellation is not allowed once any shipment exists');
        }

        $bumped = $this->repo->bumpVersion(
            $this->pdo, $doId, $expectedVersion,
            "status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), cancelled_by = ?, cancel_reason = ?", [$userId, $reason]
        );
        $this->assertVersionBumpSucceeded($doId, $expectedVersion, $bumped);

        Audit::write($this->pdo, $requestId, $userId, 'do.cancel', 'delivery_order', (string) $doId, 'ok', $expectedVersion, $expectedVersion + 1, ['reason' => $reason]);

        $do = $this->repo->findDoById($this->pdo, $doId);
        return $this->buildDoDto($do, $do);
    }

    /**
     * POST /api/do/generate-bulk — "Generate Draft DO untuk Semua Toko"
     * (task section 26). Safe/idempotent: an existing open DO is skipped,
     * never duplicated. Uses the same per-store createDraft() logic (full
     * cross-factory demand aggregation) rather than the single batched
     * allStoreDemandForFactory() query, which is intentionally reserved
     * for the (read-only, higher-volume) list screen — a few dozen extra
     * per-store SELECTs on a once-a-day bulk-generate action is not the
     * "one query per cell" pattern task section 37 warns against.
     */
    public function generateBulk(string $tanggal, int $factoryId, int $userId): array
    {
        $factory = $this->requireFactory($factoryId);
        $stores = $this->targets->storesWithPo($this->pdo, $tanggal, $factoryId);

        $created = 0;
        $alreadyExisted = 0;
        $errors = [];
        foreach ($stores as $s) {
            try {
                $existing = $this->repo->lockExistingDo($this->pdo, $tanggal, $s['storeId']);
                if ($existing !== null) {
                    $alreadyExisted++;
                    continue;
                }
                $this->createDraft($tanggal, $s['storeId'], $userId);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = ['storeId' => $s['storeId'], 'storeName' => $s['storeName'], 'message' => $e->getMessage()];
            }
        }

        return [
            'tanggal' => $tanggal,
            'factoryId' => $factoryId,
            'factoryName' => $factory['name'],
            'created' => $created,
            'alreadyExisted' => $alreadyExisted,
            'errors' => $errors,
            'storesConsidered' => count($stores),
        ];
    }

    /** @return array<int,int> factoryId => po_batch.version, for every factory in $demand */
    private function snapshotSourceVersions(string $tanggal, array $demand): array
    {
        $factoryIds = array_unique(array_column($demand, 'factoryId'));
        $out = [];
        foreach ($factoryIds as $factoryId) {
            $out[(string) $factoryId] = $this->targets->currentPoBatchVersion($this->pdo, $tanggal, (int) $factoryId);
        }
        return $out;
    }

    /** @return array{changed:bool,details:array} */
    private function sourceChangeCheck(string $tanggal, ?string $storedJson): array
    {
        $stored = $storedJson !== null ? (json_decode($storedJson, true) ?? []) : [];
        $details = [];
        $changed = false;
        foreach ($stored as $factoryId => $storedVersion) {
            $current = $this->targets->currentPoBatchVersion($this->pdo, $tanggal, (int) $factoryId);
            $mismatch = $current !== (int) $storedVersion;
            if ($mismatch) {
                $changed = true;
            }
            $details[] = ['factoryId' => (int) $factoryId, 'storedVersion' => (int) $storedVersion, 'currentVersion' => $current, 'mismatch' => $mismatch];
        }
        return ['changed' => $changed, 'details' => $details];
    }

    private function assertRefreshable(array $do): void
    {
        if (!in_array($do['status'], ['draft', 'preprinted'], true)) {
            throw new ApiException(409, 'INVALID_STATUS', "Only a draft or preprinted document can be refreshed from PO (current status: {$do['status']})");
        }
    }

    private function assertVersionBumpSucceeded(int $doId, int $expectedVersion, bool $bumped): void
    {
        if (!$bumped) {
            $current = \Amor\Api\Versioning::currentVersion($this->pdo, 'delivery_order', 'delivery_order_id', $doId);
            throw new ApiException(409, 'VERSION_CONFLICT', 'The document was modified by someone else', ['currentVersion' => $current]);
        }
    }

    private function requireStore(int $storeId): array
    {
        $store = $this->repo->findStore($this->pdo, $storeId);
        if ($store === null) {
            throw new ApiException(404, 'STORE_NOT_FOUND', 'Store not found');
        }
        return $store;
    }

    private function requireFactory(int $factoryId): array
    {
        $factory = $this->repo->findFactory($this->pdo, $factoryId);
        if ($factory === null) {
            throw new ApiException(404, 'FACTORY_NOT_FOUND', 'Factory not found');
        }
        return $factory;
    }

    /** @return array<int,array> */
    private function buildListDtos(array $doRows): array
    {
        $doIds = array_map(static fn ($d) => (int) $d['delivery_order_id'], $doRows);
        $planned = $this->repo->sumPlannedByDo($this->pdo, $doIds);
        $shipped = $this->repo->sumShippedByDo($this->pdo, $doIds);
        $lastShipment = $this->repo->lastShipmentByDo($this->pdo, $doIds);

        return array_map(function ($do) use ($planned, $shipped, $lastShipment) {
            $doId = (int) $do['delivery_order_id'];
            $totalPlanned = $planned[$doId] ?? 0.0;
            $totalShipped = $shipped[$doId] ?? 0.0;
            $last = $lastShipment[$doId] ?? null;
            return [
                'doId' => $doId,
                'docNo' => $do['doc_no'],
                'tanggal' => $do['tanggal'],
                'storeId' => (int) $do['store_id'],
                'storeName' => $do['store_name'],
                'status' => $do['status'],
                'version' => (int) $do['version'],
                'totalPlanned' => $totalPlanned,
                'totalShipped' => $totalShipped,
                'totalRemaining' => max(0.0, $totalPlanned - $totalShipped),
                'lastShipmentAt' => $last['shipped_at'] ?? null,
                'lastShipmentGroup' => $last['shipment_group'] ?? null,
            ];
        }, $doRows);
    }

    private function buildDoDto(array $do, array $storeish): array
    {
        $doId = (int) $do['delivery_order_id'];
        $tanggal = (string) $do['tanggal'];
        $storeName = $storeish['store_name'] ?? $storeish['canonical_name'] ?? null;

        $items = [];
        $totalPlanned = 0.0;
        $totalShipped = 0.0;
        $shippedByProduct = $this->repo->shippedQtyByProduct($this->pdo, $doId);
        $statusCounts = ['belum_dikirim' => 0, 'sebagian_dikirim' => 0, 'terkirim_penuh' => 0];

        $rawItems = $this->repo->findDoItems($this->pdo, $doId);
        foreach ($rawItems as $productId => $item) {
            $planned = (float) $item['planned_qty'];
            $shippedQty = $shippedByProduct[$productId] ?? 0.0;
            $remaining = max(0.0, $planned - $shippedQty);
            $itemStatus = self::classifyItemStatus($shippedQty, $planned);

            // GLOBAL FG RESERVATION (cross-flow deep-check fix): this is
            // the SAME "physical minus active special reservations"
            // formula Delivery\ShipmentService::preview()/ship() enforce
            // — pengiriman.php's own ship form reads fgAvailable straight
            // from here, so showing the raw unreserved qty_on_hand here
            // would let an operator TYPE a qty the server then rejects,
            // even though the number on screen looked available.
            $available = null;
            $factoryId = $item['factory_id'] !== null ? (int) $item['factory_id'] : null;
            if ($factoryId !== null) {
                $factory = $this->repo->findFactory($this->pdo, $factoryId);
                if ($factory !== null) {
                    $locationId = $this->fg->findOrCreateLocationForFactory($this->pdo, $factoryId, $factory['name']);
                    $balance = $this->fg->findBalance($this->pdo, $productId, $locationId);
                    $physical = $balance !== null ? (float) $balance['qty_on_hand'] : $this->fg->sumLedger($this->pdo, $productId, $locationId);
                    $reservedForSpecial = $this->allocRepo->sumActiveAllocatedForProductFactory($this->pdo, $productId, $factoryId);
                    $available = max(0.0, $physical - $reservedForSpecial);
                }
            }

            $items[] = [
                'doItemId' => (int) $item['delivery_order_item_id'],
                'productId' => $productId,
                'productName' => $item['product_name'],
                'divisionName' => $item['division_name'],
                'factoryId' => $factoryId,
                'plannedQty' => $planned,
                'alreadyShippedQty' => $shippedQty,
                'remainingToShip' => $remaining,
                'fgAvailable' => $available,
                'itemStatusCode' => $itemStatus['code'],
                'itemStatusLabel' => $itemStatus['label'],
            ];
            $totalPlanned += $planned;
            $totalShipped += $shippedQty;
            $statusCounts[$itemStatus['code']]++;
        }

        $sourceCheck = $this->sourceChangeCheck($tanggal, $do['source_po_version_json'] ?? null);

        return [
            'doId' => $doId,
            'docNo' => $do['doc_no'],
            'tanggal' => $tanggal,
            'storeId' => (int) $do['store_id'],
            'storeName' => $storeName,
            'status' => $do['status'],
            'version' => (int) $do['version'],
            'createdBy' => $do['created_by'] !== null ? (int) $do['created_by'] : null,
            'preprintedAt' => $do['preprinted_at'],
            'preprintedBy' => $do['preprinted_by'] !== null ? (int) $do['preprinted_by'] : null,
            'shippedAt' => $do['shipped_at'],
            'cancelledAt' => $do['cancelled_at'],
            'cancelledBy' => $do['cancelled_by'] !== null ? (int) $do['cancelled_by'] : null,
            'cancelReason' => $do['cancel_reason'],
            // Read-only passthrough of the already-existing (0001 schema)
            // delivery_order.catatan column — no API currently writes it,
            // so this is null today, but the print template reads it
            // through this DTO rather than querying the table directly
            // (task's own "use existing service, no duplicate
            // calculations" rule for the print redesign).
            'catatan' => $do['catatan'] ?? null,
            'sourcePoChanged' => $sourceCheck['changed'],
            'sourcePoChangedDetails' => $sourceCheck['details'],
            'items' => $items,
            'summary' => [
                'totalPlanned' => $totalPlanned,
                'totalShipped' => $totalShipped,
                'totalRemaining' => max(0.0, $totalPlanned - $totalShipped),
                'productCount' => count($rawItems),
                'jumlahBelumDikirim' => $statusCounts['belum_dikirim'],
                'jumlahSebagianDikirim' => $statusCounts['sebagian_dikirim'],
                'jumlahTerkirimPenuh' => $statusCounts['terkirim_penuh'],
                'fullyFulfilled' => count($rawItems) > 0 && $statusCounts['terkirim_penuh'] === count($rawItems),
            ],
        ];
    }

    /** @return array{code:string,label:string} */
    private static function classifyItemStatus(float $shipped, float $planned): array
    {
        $eps = 0.0001;
        if ($shipped <= $eps) {
            return ['code' => 'belum_dikirim', 'label' => 'Belum Dikirim'];
        }
        if ($shipped + $eps >= $planned) {
            return ['code' => 'terkirim_penuh', 'label' => 'Terkirim Penuh'];
        }
        return ['code' => 'sebagian_dikirim', 'label' => 'Sebagian Dikirim'];
    }
}
