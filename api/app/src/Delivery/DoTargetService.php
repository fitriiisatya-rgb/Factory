<?php

declare(strict_types=1);

namespace Amor\Api\Delivery;

use PDO;

/**
 * Read-only aggregation of the CURRENT authoritative store-level PO demand
 * into a per-product Draft DO source — the Phase 5 analogue of Production's
 * ProductionTargetService and FG's FgTargetService. Reads Phase 2's
 * po_batch/po_item/po_store_item, read-only, never writes to them.
 *
 * A DO's identity is (tanggal, store_id) ONLY — never scoped to one
 * factory (see migration 0006's own docblock and
 * docs/mysql-do-shipment-phase5-reservation-v1.md §8.2). A single store
 * can have PO demand from BOTH Karangtengah and Cibadak on the same date
 * (Karangtengah makes non-Bolu, Cibadak makes Bolu; one retail store can
 * order both), so this service aggregates across EVERY po_batch that
 * mentions the store on that date, regardless of factory — planned_qty is
 * always po_awal+po_revisi per (product,store) from po_store_item (PB is
 * never part of po_item/po_store_item at all, so it is structurally
 * already excluded — nothing to filter here).
 */
final class DoTargetService
{
    /**
     * @return array<int,array{productId:int,productName:string,divisionId:?int,divisionName:?string,factoryId:int,factoryName:string,poAwal:float,poRevisi:float,planned:float}>
     *         keyed by product_id
     */
    public function storeDemandByProduct(PDO $pdo, string $tanggal, int $storeId): array
    {
        $stmt = $pdo->prepare(
            'SELECT p.product_id, p.name AS product_name, p.division_id, d.name AS division_name,
                    b.factory_id, f.name AS factory_name, si.po_awal, si.po_revisi
             FROM po_store_item si
             INNER JOIN po_item i ON i.po_item_id = si.po_item_id
             INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id
             INNER JOIN product p ON p.product_id = i.product_id
             LEFT JOIN division d ON d.division_id = p.division_id
             INNER JOIN factory f ON f.factory_id = b.factory_id
             WHERE b.tanggal = ? AND si.store_id = ?
             ORDER BY p.name'
        );
        $stmt->execute([$tanggal, $storeId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $productId = (int) $r['product_id'];
            $poAwal = (float) $r['po_awal'];
            $poRevisi = (float) $r['po_revisi'];
            $out[$productId] = [
                'productId' => $productId,
                'productName' => $r['product_name'],
                'divisionId' => $r['division_id'] !== null ? (int) $r['division_id'] : null,
                'divisionName' => $r['division_name'],
                'factoryId' => (int) $r['factory_id'],
                'factoryName' => $r['factory_name'],
                'poAwal' => $poAwal,
                'poRevisi' => $poRevisi,
                'planned' => $poAwal + $poRevisi,
            ];
        }
        return $out;
    }

    /**
     * Every store with at least one PO line for this factory+date — the
     * store picker list for the UAT wizard's "choose factory -> show
     * stores with PO" step. Filtering by factory here is a UI convenience
     * only; it never scopes the DO itself (see class docblock).
     * @return array<int,array{storeId:int,storeName:string}>
     */
    public function storesWithPo(PDO $pdo, string $tanggal, int $factoryId): array
    {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT s.store_id, s.canonical_name
             FROM po_store_item si
             INNER JOIN po_item i ON i.po_item_id = si.po_item_id
             INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id
             INNER JOIN store s ON s.store_id = si.store_id
             WHERE b.tanggal = ? AND b.factory_id = ? AND (si.po_awal > 0 OR si.po_revisi > 0)
             ORDER BY s.canonical_name'
        );
        $stmt->execute([$tanggal, $factoryId]);
        return array_map(static fn ($r) => [
            'storeId' => (int) $r['store_id'],
            'storeName' => $r['canonical_name'],
        ], $stmt->fetchAll());
    }

    /**
     * ALL stores' demand for a factory+date in ONE query (avoids N+1 when
     * bulk-generating DOs for every store — task section 37).
     * @return array<int,array<int,array{productId:int,productName:string,poAwal:float,poRevisi:float,planned:float}>>
     *         keyed by store_id, then product_id
     */
    public function allStoreDemandForFactory(PDO $pdo, string $tanggal, int $factoryId): array
    {
        $stmt = $pdo->prepare(
            'SELECT si.store_id, p.product_id, p.name AS product_name, si.po_awal, si.po_revisi
             FROM po_store_item si
             INNER JOIN po_item i ON i.po_item_id = si.po_item_id
             INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id
             INNER JOIN product p ON p.product_id = i.product_id
             WHERE b.tanggal = ? AND b.factory_id = ? AND (si.po_awal > 0 OR si.po_revisi > 0)'
        );
        $stmt->execute([$tanggal, $factoryId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $storeId = (int) $r['store_id'];
            $productId = (int) $r['product_id'];
            $poAwal = (float) $r['po_awal'];
            $poRevisi = (float) $r['po_revisi'];
            $out[$storeId][$productId] = [
                'productId' => $productId,
                'productName' => $r['product_name'],
                'poAwal' => $poAwal,
                'poRevisi' => $poRevisi,
                'planned' => $poAwal + $poRevisi,
            ];
        }
        return $out;
    }

    /** Live po_batch.version for one factory+date, or null if no PO exists yet. */
    public function currentPoBatchVersion(PDO $pdo, string $tanggal, int $factoryId): ?int
    {
        $stmt = $pdo->prepare('SELECT version FROM po_batch WHERE tanggal = ? AND factory_id = ?');
        $stmt->execute([$tanggal, $factoryId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }
}
