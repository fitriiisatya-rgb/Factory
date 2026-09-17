<?php

declare(strict_types=1);

namespace Amor\Api\Production;

use PDO;

/**
 * Persistence for the Phase 3 production document
 * (production_run/production_item, both from the original 0001 schema,
 * extended additively by migration 0004 — no parallel truth table created).
 *
 * production_run is the "document": one row per (tanggal, division_id),
 * carrying the lifecycle status/version. production_item is a line per
 * product within that document. Mirrors the PoRepository split (current
 * state vs read helpers) established in Phase 2.
 */
final class ProductionRepository
{
    public function findDivision(PDO $pdo, int $divisionId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT d.division_id, d.name, d.factory_id, d.is_verification, f.name AS factory_name
             FROM division d INNER JOIN factory f ON f.factory_id = d.factory_id
             WHERE d.division_id = ?'
        );
        $stmt->execute([$divisionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findProduct(PDO $pdo, int $productId): ?array
    {
        $stmt = $pdo->prepare('SELECT product_id, name, division_id FROM product WHERE product_id = ?');
        $stmt->execute([$productId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array|null the production_run row, row-locked (FOR UPDATE) if found */
    public function lockExistingRun(PDO $pdo, string $tanggal, int $divisionId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM production_run WHERE tanggal = ? AND division_id = ? FOR UPDATE');
        $stmt->execute([$tanggal, $divisionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findRunByDateDivision(PDO $pdo, string $tanggal, int $divisionId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM production_run WHERE tanggal = ? AND division_id = ?');
        $stmt->execute([$tanggal, $divisionId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array|null row-locked (FOR UPDATE) */
    public function lockRunById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM production_run WHERE production_run_id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findRunById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT r.*, d.name AS division_name, d.factory_id, f.name AS factory_name
             FROM production_run r
             INNER JOIN division d ON d.division_id = r.division_id
             INNER JOIN factory f ON f.factory_id = d.factory_id
             WHERE r.production_run_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array> */
    public function findRuns(PDO $pdo, ?string $tanggal, ?int $factoryId, ?int $divisionId, ?string $status): array
    {
        $sql = 'SELECT r.*, d.name AS division_name, d.factory_id, f.name AS factory_name
                FROM production_run r
                INNER JOIN division d ON d.division_id = r.division_id
                INNER JOIN factory f ON f.factory_id = d.factory_id
                WHERE 1=1';
        $params = [];
        if ($tanggal !== null) {
            $sql .= ' AND r.tanggal = ?';
            $params[] = $tanggal;
        }
        if ($factoryId !== null) {
            $sql .= ' AND d.factory_id = ?';
            $params[] = $factoryId;
        }
        if ($divisionId !== null) {
            $sql .= ' AND r.division_id = ?';
            $params[] = $divisionId;
        }
        if ($status !== null) {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY r.tanggal DESC, f.name, d.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function createRun(PDO $pdo, string $tanggal, int $divisionId, int $userId, ?int $sourcePoBatchVersion): array
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO production_run
                    (tanggal, division_id, status, created_by, version, source_po_batch_version, created_at)
                 VALUES (?, ?, 'draft', ?, 1, ?, UTC_TIMESTAMP())"
            );
            $stmt->execute([$tanggal, $divisionId, $userId, $sourcePoBatchVersion]);
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $row = $this->lockExistingRun($pdo, $tanggal, $divisionId);
                if ($row !== null) {
                    return $row;
                }
            }
            throw $e;
        }
        return $this->lockExistingRun($pdo, $tanggal, $divisionId);
    }

    /** @return array<int,array> keyed by product_id */
    public function findItems(PDO $pdo, int $runId): array
    {
        $stmt = $pdo->prepare(
            'SELECT pi.*, p.name AS product_name
             FROM production_item pi
             INNER JOIN product p ON p.product_id = pi.product_id
             WHERE pi.production_run_id = ?
             ORDER BY p.name'
        );
        $stmt->execute([$runId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['product_id']] = $r;
        }
        return $out;
    }

    public function insertItem(PDO $pdo, int $runId, int $productId, float $targetSnapshot, float $aktual = 0.0): void
    {
        // A freshly-created line with no PO target yet and no actual is
        // neither "sesuai" nor "tidak_sesuai" in any meaningful sense — but
        // the column has no NULL/"belum" state (pre-existing enum from the
        // original 0001 schema, kept as-is), so default to tidak_sesuai and
        // let it flip to sesuai the moment actual reaches target.
        $status = ($targetSnapshot > 0 && $aktual >= $targetSnapshot) ? 'sesuai' : 'tidak_sesuai';
        $stmt = $pdo->prepare(
            'INSERT INTO production_item (production_run_id, product_id, target, status, aktual, reject, keterangan)
             VALUES (?, ?, ?, ?, ?, 0, NULL)'
        );
        $stmt->execute([$runId, $productId, $targetSnapshot, $status, $aktual]);
    }

    public function updateItemTarget(PDO $pdo, int $productionItemId, float $targetSnapshot): void
    {
        $stmt = $pdo->prepare('UPDATE production_item SET target = ? WHERE production_item_id = ?');
        $stmt->execute([$targetSnapshot, $productionItemId]);
    }

    /** Snapshot semantics: $aktual REPLACES the stored value, never added to it. */
    public function updateItemActual(PDO $pdo, int $productionItemId, float $targetSnapshot, float $aktual, ?string $keterangan): void
    {
        $status = $aktual >= $targetSnapshot && $targetSnapshot > 0 ? 'sesuai' : 'tidak_sesuai';
        $stmt = $pdo->prepare('UPDATE production_item SET aktual = ?, keterangan = ?, status = ? WHERE production_item_id = ?');
        $stmt->execute([$aktual, $keterangan, $status, $productionItemId]);
    }

    /**
     * Bumps production_run.version and applies $setClause/$setParams in the
     * same UPDATE (optimistic concurrency — 0 rows affected means someone
     * else moved the version first). Never includes "version" in $setClause,
     * mirroring Amor\Api\Versioning::update (kept separate here only because
     * this table's identity column is production_run_id, and callers here
     * already hold a FOR UPDATE lock from lockRunById, so a plain expected-
     * version WHERE is enough — no extra currentVersion lookup on the happy
     * path).
     */
    /** @return bool true if a row was actually updated (expectedVersion matched), false on a version conflict */
    public function bumpVersion(PDO $pdo, int $runId, int $expectedVersion, string $setClause, array $setParams): bool
    {
        $sql = "UPDATE production_run SET {$setClause}, version = version + 1, updated_at = UTC_TIMESTAMP() "
             . 'WHERE production_run_id = ? AND version = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([...$setParams, $runId, $expectedVersion]);
        return $stmt->rowCount() > 0;
    }
}
