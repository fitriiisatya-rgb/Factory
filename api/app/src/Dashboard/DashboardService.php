<?php

declare(strict_types=1);

namespace Amor\Api\Dashboard;

use Amor\Api\Delivery\DoRepository;
use Amor\Api\Delivery\DoService;
use PDO;

/**
 * READ-ONLY aggregation for the redesigned UI's Dashboard page. Every
 * query here is a plain SELECT against tables Phase 1-5 already own — this
 * class never INSERTs/UPDATEs anything, and it is not on the write path of
 * any existing document (deleting it would only make the dashboard blank,
 * never corrupt PO/Production/FG/DO/Shipment data).
 *
 * Denominator convention for the operational pipeline (documented here
 * since it's a real judgment call, not fabricated data): PO Toko is the
 * pipeline's baseline (100% once any PO exists for the day); Produksi and
 * FG & Packing are both shown as a fraction of that SAME PO Toko qty
 * total, so all three qty-based stages share one denominator exactly like
 * the reference mockup's "X / 12.930" pattern. Delivery Order and
 * Pengiriman are inherently COUNT-based (how many stores have a DO / how
 * many of those DOs are fully shipped), not qty-based, so they use their
 * own natural denominators (stores-with-PO, and DOs-created).
 */
final class DashboardService
{
    public function __construct(private PDO $pdo, private DoService $doService, private DoRepository $doRepository)
    {
    }

    public function summary(string $tanggal, int $factoryId): array
    {
        $poTarget = $this->poTargetQty($tanggal, $factoryId);
        $productionActual = $this->productionActualQty($tanggal, $factoryId);
        $fgVerifiedToday = $this->fgVerifiedQty($tanggal, $factoryId);
        $fgAvailableNow = $this->fgAvailableStock($factoryId);
        $storesWithPo = count($this->doService->storesWithPo($tanggal, $factoryId));
        $doRows = $this->doService->listDosForFactory($tanggal, $factoryId);
        $doCreated = count($doRows);
        $doShipped = count(array_filter($doRows, static fn ($d) => $d['status'] === 'shipped'));

        return [
            'tanggal' => $tanggal,
            'factoryId' => $factoryId,
            'kpi' => [
                'poTarget' => $poTarget,
                'productionActual' => $productionActual,
                'productionActualPct' => self::pct($productionActual, $poTarget),
                'fgAvailable' => $fgAvailableNow,
                'fgAvailablePct' => self::pct($fgAvailableNow, $poTarget),
                'doCreated' => $doCreated,
                'doTotal' => $storesWithPo,
                'doCreatedPct' => self::pct((float) $doCreated, (float) $storesWithPo),
                'shipped' => $doShipped,
                'shippedPct' => self::pct((float) $doShipped, (float) $doCreated),
            ],
            'pipeline' => [
                ['label' => 'PO Toko', 'value' => $poTarget, 'total' => $poTarget, 'pct' => self::pct($poTarget, $poTarget)],
                ['label' => 'Produksi', 'value' => $productionActual, 'total' => $poTarget, 'pct' => self::pct($productionActual, $poTarget)],
                ['label' => 'FG & Packing', 'value' => $fgVerifiedToday, 'total' => $poTarget, 'pct' => self::pct($fgVerifiedToday, $poTarget)],
                ['label' => 'Delivery Order', 'value' => (float) $doCreated, 'total' => (float) $storesWithPo, 'pct' => self::pct((float) $doCreated, (float) $storesWithPo)],
                ['label' => 'Pengiriman', 'value' => (float) $doShipped, 'total' => (float) $doCreated, 'pct' => self::pct((float) $doShipped, (float) $doCreated)],
            ],
            'trend' => $this->productionTrend($tanggal, $factoryId, 7),
            'divisionComposition' => $this->divisionComposition($tanggal, $factoryId),
            'topFgStock' => $this->topFgStock($factoryId, 5),
            'deliveryOrders' => $doRows,
            'recentActivity' => $this->recentActivity(15),
        ];
    }

    private function poTargetQty(string $tanggal, int $factoryId): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(i.po_awal + i.po_revisi), 0) FROM po_item i
             INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id
             WHERE b.tanggal = ? AND b.factory_id = ?'
        );
        $stmt->execute([$tanggal, $factoryId]);
        return (float) $stmt->fetchColumn();
    }

    private function productionActualQty(string $tanggal, int $factoryId): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(pi.aktual), 0) FROM production_item pi
             INNER JOIN production_run r ON r.production_run_id = pi.production_run_id
             INNER JOIN division d ON d.division_id = r.division_id
             WHERE r.tanggal = ? AND d.factory_id = ?'
        );
        $stmt->execute([$tanggal, $factoryId]);
        return (float) $stmt->fetchColumn();
    }

    private function fgVerifiedQty(string $tanggal, int $factoryId): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(fi.qty), 0) FROM fg_item fi
             INNER JOIN fg_batch b ON b.fg_batch_id = fi.fg_batch_id
             WHERE b.tanggal = ? AND b.factory_id = ?'
        );
        $stmt->execute([$tanggal, $factoryId]);
        return (float) $stmt->fetchColumn();
    }

    /** Live total FG stock on hand at this factory's location — not date-scoped (a real-time snapshot, matching what Pengiriman actually draws from). */
    private function fgAvailableStock(int $factoryId): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(sb.qty_on_hand), 0) FROM stock_balance sb
             INNER JOIN location l ON l.location_id = sb.location_id
             WHERE l.factory_id = ?'
        );
        $stmt->execute([$factoryId]);
        return (float) $stmt->fetchColumn();
    }

    /** @return array<int,array{label:string,target:float,actual:float}> oldest first, $days including $tanggal */
    private function productionTrend(string $tanggal, int $factoryId, int $days): array
    {
        $end = new \DateTime($tanggal);
        $start = (clone $end)->modify('-' . ($days - 1) . ' days');
        $startStr = $start->format('Y-m-d');
        $endStr = $end->format('Y-m-d');

        $poByDate = [];
        $stmt = $this->pdo->prepare(
            'SELECT b.tanggal, SUM(i.po_awal + i.po_revisi) AS total FROM po_item i
             INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id
             WHERE b.factory_id = ? AND b.tanggal BETWEEN ? AND ?
             GROUP BY b.tanggal'
        );
        $stmt->execute([$factoryId, $startStr, $endStr]);
        foreach ($stmt->fetchAll() as $r) {
            $poByDate[$r['tanggal']] = (float) $r['total'];
        }

        $actualByDate = [];
        $stmt = $this->pdo->prepare(
            'SELECT r.tanggal, SUM(pi.aktual) AS total FROM production_item pi
             INNER JOIN production_run r ON r.production_run_id = pi.production_run_id
             INNER JOIN division d ON d.division_id = r.division_id
             WHERE d.factory_id = ? AND r.tanggal BETWEEN ? AND ?
             GROUP BY r.tanggal'
        );
        $stmt->execute([$factoryId, $startStr, $endStr]);
        foreach ($stmt->fetchAll() as $r) {
            $actualByDate[$r['tanggal']] = (float) $r['total'];
        }

        $out = [];
        $cursor = clone $start;
        while ($cursor <= $end) {
            $d = $cursor->format('Y-m-d');
            $out[] = [
                'label' => $cursor->format('d M'),
                'target' => $poByDate[$d] ?? 0.0,
                'actual' => $actualByDate[$d] ?? 0.0,
            ];
            $cursor->modify('+1 day');
        }
        return $out;
    }

    /** @return array<int,array{divisionName:string,value:float}> today's production actual grouped by division, this factory only */
    private function divisionComposition(string $tanggal, int $factoryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.name AS division_name, SUM(pi.aktual) AS total FROM production_item pi
             INNER JOIN production_run r ON r.production_run_id = pi.production_run_id
             INNER JOIN division d ON d.division_id = r.division_id
             WHERE r.tanggal = ? AND d.factory_id = ?
             GROUP BY d.division_id, d.name
             HAVING total > 0
             ORDER BY total DESC'
        );
        $stmt->execute([$tanggal, $factoryId]);
        return array_map(static fn ($r) => ['divisionName' => $r['division_name'], 'value' => (float) $r['total']], $stmt->fetchAll());
    }

    /** @return array<int,array{productId:int,productName:string,qty:float}> */
    private function topFgStock(int $factoryId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.product_id, p.name AS product_name, sb.qty_on_hand FROM stock_balance sb
             INNER JOIN location l ON l.location_id = sb.location_id
             INNER JOIN product p ON p.product_id = sb.product_id
             WHERE l.factory_id = ? AND sb.qty_on_hand > 0
             ORDER BY sb.qty_on_hand DESC LIMIT ' . (int) $limit
        );
        $stmt->execute([$factoryId]);
        return array_map(static fn ($r) => [
            'productId' => (int) $r['product_id'], 'productName' => $r['product_name'], 'qty' => (float) $r['qty_on_hand'],
        ], $stmt->fetchAll());
    }

    /** @return array<int,array> most recent audit_log rows, newest first, joined to the acting username where available */
    private function recentActivity(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.event_at, h.action, h.record_type, h.record_key, h.status, u.username
             FROM audit_log h LEFT JOIN users u ON u.user_id = h.user_id
             ORDER BY h.event_at DESC LIMIT ' . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private static function pct(float $numerator, float $denominator): int
    {
        if ($denominator <= 0.0001) {
            return 0;
        }
        return (int) round(min(100, max(0, ($numerator / $denominator) * 100)));
    }
}
