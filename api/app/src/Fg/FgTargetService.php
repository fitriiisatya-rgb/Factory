<?php

declare(strict_types=1);

namespace Amor\Api\Fg;

use PDO;

/**
 * Read-only aggregation of the CURRENT authoritative Production actual
 * into a per-product FG source — the Phase 4 analogue of Production's own
 * ProductionTargetService (which reads Phase 2 PO, read-only, to produce a
 * production target). This reads Phase 3 production_run/production_item,
 * read-only, to produce an FG source.
 *
 * Core eligibility rule (task section "SOURCE RULE"): only a
 * production_run with status='submitted' counts as a valid FG source.
 * 'draft' and 'reopened' runs are explicitly excluded — a division mid-
 * correction contributes NOTHING to FG until it is resubmitted. This is
 * intentionally stricter than Production's own PO-target read (which has
 * no such status gate), because FG verifying against an in-flux number
 * would be meaningless.
 *
 * A factory's FG source is the SUM of production_item.aktual for a given
 * product across every SUBMITTED production_run belonging to any division
 * of that factory on that date — one factory can have several divisions
 * (Karangtengah: Roti & Bollen, Basic, Donat/Mochi/AKB, Pastry, Cookies;
 * Cibadak: Bolu), each with its own production_run, and Phase 4's fg_batch
 * is scoped per (tanggal, factory_id), not per division (matching
 * fg_batch's own existing UNIQUE KEY from the original 0001 schema).
 * Finishgood & Packing (division.is_verification=1) never has a
 * production_run at all (Phase 3 excludes it), so it never contributes
 * here either — no special-casing needed.
 */
final class FgTargetService
{
    /** @return array<int,array{productionRunId:int,divisionId:int,divisionName:string,version:int}> every SUBMITTED run for this factory+date */
    public function eligibleProductionRuns(PDO $pdo, string $tanggal, int $factoryId): array
    {
        $stmt = $pdo->prepare(
            "SELECT r.production_run_id, r.division_id, d.name AS division_name, r.version
             FROM production_run r
             INNER JOIN division d ON d.division_id = r.division_id
             WHERE r.tanggal = ? AND d.factory_id = ? AND r.status = 'submitted'
             ORDER BY d.name"
        );
        $stmt->execute([$tanggal, $factoryId]);
        return array_map(static fn ($r) => [
            'productionRunId' => (int) $r['production_run_id'],
            'divisionId' => (int) $r['division_id'],
            'divisionName' => $r['division_name'],
            'version' => (int) $r['version'],
        ], $stmt->fetchAll());
    }

    /**
     * Live production actual per product, summed across every eligible
     * (SUBMITTED) run for this factory+date. Excludes draft/reopened runs
     * entirely — see class docblock.
     * @return array<int,array{productId:int,productName:string,actual:float}> keyed by product_id
     */
    public function productionActualByProduct(PDO $pdo, string $tanggal, int $factoryId): array
    {
        $stmt = $pdo->prepare(
            "SELECT pi.product_id, p.name AS product_name, SUM(pi.aktual) AS actual
             FROM production_run r
             INNER JOIN division d ON d.division_id = r.division_id
             INNER JOIN production_item pi ON pi.production_run_id = r.production_run_id
             INNER JOIN product p ON p.product_id = pi.product_id
             WHERE r.tanggal = ? AND d.factory_id = ? AND r.status = 'submitted'
             GROUP BY pi.product_id, p.name
             ORDER BY p.name"
        );
        $stmt->execute([$tanggal, $factoryId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $productId = (int) $r['product_id'];
            $out[$productId] = [
                'productId' => $productId,
                'productName' => $r['product_name'],
                'actual' => (float) $r['actual'],
            ];
        }
        return $out;
    }

    /** Live current version+status of one production_run — used to detect drift since an fg_batch_source row was recorded. */
    public function currentRunState(PDO $pdo, int $productionRunId): ?array
    {
        $stmt = $pdo->prepare('SELECT production_run_id, version, status FROM production_run WHERE production_run_id = ?');
        $stmt->execute([$productionRunId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
