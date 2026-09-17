<?php

declare(strict_types=1);

namespace Amor\Api\Production;

use PDO;

/**
 * Read-only aggregation of the CURRENT authoritative PO demand into a
 * per-product production target — the "live" half of Phase 3's dual target
 * model (docs task section 4: live current-demand target vs the
 * versioned/audited production_item.target snapshot).
 *
 * This NEVER writes to po_batch/po_item/po_store_item and NEVER reads
 * production_run/production_item — it is a pure query over Phase 2's
 * already-authoritative PO state, reused as-is (task: "Do not break or
 * redesign Phase 2 PO").
 *
 * Aggregation identity is exactly (tanggal, factory_id, product_id):
 * po_item is already a per-(po_batch, product_id) rollup across every
 * store (see PoRepository::applyLines — po_item.po_awal/po_revisi are the
 * SUM of that product's po_store_item rows), and po_batch is keyed on
 * (tanggal, factory_id) — so no additional per-store summation is needed
 * here; summing across stores again would double-count. PB (po_item.pb) is
 * never included in target — it was already excluded from po_awal/
 * po_revisi by Phase 2's own PoMerger/PoImporter, so this service does not
 * need (and must not add) any PB-exclusion logic of its own.
 */
final class ProductionTargetService
{
    /**
     * The po_batch.version this factory+date's PO state is currently at, or
     * null if no PO has ever been uploaded for this factory+date. Used to
     * detect "PO revised since this production draft was created/refreshed"
     * without diffing every product individually.
     */
    public function currentPoBatchVersion(PDO $pdo, string $tanggal, int $factoryId): ?int
    {
        $stmt = $pdo->prepare('SELECT version FROM po_batch WHERE tanggal = ? AND factory_id = ?');
        $stmt->execute([$tanggal, $factoryId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /**
     * Live production target per product for a factory+date, optionally
     * filtered to one division. target = po_awal + po_revisi (PB excluded),
     * matching the audited PO-Phase-2 committed totals exactly (task:
     * "production actual must NEVER mutate PO target" — this is read-only).
     *
     * @return array<int,array{productId:int,productName:string,divisionId:?int,divisionName:?string,poAwal:float,poRevisi:float,target:float}>
     *         keyed by product_id
     */
    public function targetsByProduct(PDO $pdo, string $tanggal, int $factoryId, ?int $divisionId = null): array
    {
        $sql = 'SELECT p.product_id, p.name AS product_name, p.division_id, d.name AS division_name,
                       i.po_awal, i.po_revisi
                FROM po_batch b
                INNER JOIN po_item i ON i.po_batch_id = b.po_batch_id
                INNER JOIN product p ON p.product_id = i.product_id
                LEFT JOIN division d ON d.division_id = p.division_id
                WHERE b.tanggal = ? AND b.factory_id = ?';
        $params = [$tanggal, $factoryId];
        if ($divisionId !== null) {
            $sql .= ' AND p.division_id = ?';
            $params[] = $divisionId;
        }
        $sql .= ' ORDER BY p.name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

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
                'poAwal' => $poAwal,
                'poRevisi' => $poRevisi,
                'target' => $poAwal + $poRevisi,
            ];
        }
        return $out;
    }

    /** Live target for exactly one product, or 0.0 if it has no PO demand for this factory+date. */
    public function targetForProduct(PDO $pdo, string $tanggal, int $factoryId, int $productId): float
    {
        $stmt = $pdo->prepare(
            'SELECT i.po_awal, i.po_revisi FROM po_batch b
             INNER JOIN po_item i ON i.po_batch_id = b.po_batch_id
             WHERE b.tanggal = ? AND b.factory_id = ? AND i.product_id = ?'
        );
        $stmt->execute([$tanggal, $factoryId, $productId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return 0.0;
        }
        return (float) $row['po_awal'] + (float) $row['po_revisi'];
    }
}
