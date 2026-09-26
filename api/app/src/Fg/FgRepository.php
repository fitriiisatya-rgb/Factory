<?php

declare(strict_types=1);

namespace Amor\Api\Fg;

use PDO;

/**
 * Persistence for the Phase 4 FG/Packing document
 * (fg_batch/fg_batch_source/fg_item, all from the original 0001 schema,
 * extended additively by migration 0005) plus the stock_ledger/
 * stock_balance writes FG submission produces. Mirrors the
 * Production\ProductionRepository split established in Phase 3.
 */
final class FgRepository
{
    private ?int $unallocatedStoreId = null;

    /**
     * Every fg_item needs SOME store_id to satisfy the NOT NULL FK
     * inherited from the original 0001 schema — Phase 4 does no per-store
     * allocation (that is Phase 5's DO concern), so every row uses this
     * one synthetic placeholder, exactly like PoResolver::unallocatedStoreId()
     * already does for unallocated PO demand.
     */
    public function unallocatedStoreId(PDO $pdo): int
    {
        if ($this->unallocatedStoreId !== null) {
            return $this->unallocatedStoreId;
        }
        $stmt = $pdo->prepare('SELECT store_id FROM store WHERE canonical_name = ?');
        $stmt->execute(['NON-OUTLET / PERORANGAN']);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException("Synthetic store 'NON-OUTLET / PERORANGAN' not found — Phase 0 seeding must run first.");
        }
        $this->unallocatedStoreId = (int) $id;
        return $this->unallocatedStoreId;
    }

    public function findFactory(PDO $pdo, int $factoryId): ?array
    {
        $stmt = $pdo->prepare('SELECT factory_id, code, name FROM factory WHERE factory_id = ?');
        $stmt->execute([$factoryId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Lazily finds-or-creates this factory's own `location` row (migration
     * 0005 adds location.factory_id but seeds no rows — see that
     * migration's own docblock). Guarded against a concurrent-create race
     * by location's UNIQUE KEY on name, same pattern as
     * PoRepository::findOrCreateBatch.
     */
    public function findOrCreateLocationForFactory(PDO $pdo, int $factoryId, string $factoryName): int
    {
        $stmt = $pdo->prepare('SELECT location_id FROM location WHERE factory_id = ?');
        $stmt->execute([$factoryId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $name = 'GUDANG ' . mb_strtoupper($factoryName);
        try {
            $stmt = $pdo->prepare('INSERT INTO location (name, factory_id) VALUES (?, ?)');
            $stmt->execute([$name, $factoryId]);
            return (int) $pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $stmt = $pdo->prepare('SELECT location_id FROM location WHERE factory_id = ?');
                $stmt->execute([$factoryId]);
                $id = $stmt->fetchColumn();
                if ($id !== false) {
                    return (int) $id;
                }
            }
            throw $e;
        }
    }

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

    /** @return array|null row-locked (FOR UPDATE) */
    public function lockExistingBatch(PDO $pdo, string $tanggal, int $factoryId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM fg_batch WHERE tanggal = ? AND factory_id = ? FOR UPDATE');
        $stmt->execute([$tanggal, $factoryId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array|null row-locked (FOR UPDATE) */
    public function lockBatchById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM fg_batch WHERE fg_batch_id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findBatchById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT b.*, f.name AS factory_name FROM fg_batch b
             INNER JOIN factory f ON f.factory_id = b.factory_id
             WHERE b.fg_batch_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array> */
    public function findBatches(PDO $pdo, ?string $tanggal, ?int $factoryId, ?string $status): array
    {
        $sql = 'SELECT b.*, f.name AS factory_name FROM fg_batch b
                INNER JOIN factory f ON f.factory_id = b.factory_id WHERE 1=1';
        $params = [];
        if ($tanggal !== null) {
            $sql .= ' AND b.tanggal = ?';
            $params[] = $tanggal;
        }
        if ($factoryId !== null) {
            $sql .= ' AND b.factory_id = ?';
            $params[] = $factoryId;
        }
        if ($status !== null) {
            $sql .= ' AND b.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY b.tanggal DESC, f.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function createBatch(PDO $pdo, string $tanggal, int $factoryId, int $userId): array
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO fg_batch (tanggal, factory_id, status, created_by, version, created_at)
                 VALUES (?, ?, 'draft', ?, 1, UTC_TIMESTAMP())"
            );
            $stmt->execute([$tanggal, $factoryId, $userId]);
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $row = $this->lockExistingBatch($pdo, $tanggal, $factoryId);
                if ($row !== null) {
                    return $row;
                }
            }
            throw $e;
        }
        return $this->lockExistingBatch($pdo, $tanggal, $factoryId);
    }

    /** @return array<int,int> production_run_id => source_version, for this batch */
    public function findBatchSources(PDO $pdo, int $batchId): array
    {
        $stmt = $pdo->prepare('SELECT production_run_id, source_version FROM fg_batch_source WHERE fg_batch_id = ?');
        $stmt->execute([$batchId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['production_run_id']] = (int) $r['source_version'];
        }
        return $out;
    }

    public function upsertBatchSource(PDO $pdo, int $batchId, int $productionRunId, int $sourceVersion): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO fg_batch_source (fg_batch_id, production_run_id, source_version) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE source_version = VALUES(source_version)'
        );
        $stmt->execute([$batchId, $productionRunId, $sourceVersion]);
    }

    /** @return array<int,array> keyed by product_id */
    public function findItems(PDO $pdo, int $batchId): array
    {
        $stmt = $pdo->prepare(
            'SELECT fi.*, p.name AS product_name
             FROM fg_item fi
             INNER JOIN product p ON p.product_id = fi.product_id
             WHERE fi.fg_batch_id = ?
             ORDER BY p.name'
        );
        $stmt->execute([$batchId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['product_id']] = $r;
        }
        return $out;
    }

    public function insertItem(PDO $pdo, int $batchId, int $productId, int $storeId, float $productionActualSnapshot): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO fg_item (fg_batch_id, product_id, store_id, qty, packed_qty, production_actual_snapshot, status, keterangan)
             VALUES (?, ?, ?, 0, 0, ?, 'belum_dicek', NULL)"
        );
        $stmt->execute([$batchId, $productId, $storeId, $productionActualSnapshot]);
    }

    public function updateItemSnapshot(PDO $pdo, int $fgItemId, float $productionActualSnapshot): void
    {
        $stmt = $pdo->prepare('UPDATE fg_item SET production_actual_snapshot = ? WHERE fg_item_id = ?');
        $stmt->execute([$productionActualSnapshot, $fgItemId]);
    }

    /**
     * Snapshot semantics: $fgVerified/$packed/$reject/$hilang REPLACE the
     * stored values, never added to them. $reject (FG-side reject) and
     * $hilang (physically lost/missing during FG/packing handling) are
     * migration 0014's own additive columns — independent from each other
     * and from Production's own reject (production_item.reject /
     * special_order_item.reject_produksi), never merged into Actual.
     */
    public function updateItemValues(PDO $pdo, int $fgItemId, float $fgVerified, float $packed, float $reject, float $hilang, ?string $notes): void
    {
        $status = $fgVerified > 0 ? 'dicek' : 'belum_dicek';
        $stmt = $pdo->prepare('UPDATE fg_item SET qty = ?, packed_qty = ?, reject_qty = ?, hilang_qty = ?, keterangan = ?, status = ? WHERE fg_item_id = ?');
        $stmt->execute([$fgVerified, $packed, $reject, $hilang, $notes, $status, $fgItemId]);
    }

    /** @return bool true if a row was actually updated (expectedVersion matched), false on a version conflict */
    public function bumpVersion(PDO $pdo, int $batchId, int $expectedVersion, string $setClause, array $setParams): bool
    {
        $sql = "UPDATE fg_batch SET {$setClause}, version = version + 1, updated_at = UTC_TIMESTAMP() "
             . 'WHERE fg_batch_id = ? AND version = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([...$setParams, $batchId, $expectedVersion]);
        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------------------
    // stock_ledger / stock_balance — the sole stock-truth writes in Phase 4
    // ------------------------------------------------------------------

    /**
     * Authoritative "how much has already been posted for this fg_item" —
     * always re-derived from stock_ledger itself (never a separate cache
     * column), matching stock_balance's own documented "cache, not primary
     * truth" philosophy. This is what makes resubmit-after-correction post
     * only the DELTA (task's compensating-movement rule).
     */
    public function postedQtyForItem(PDO $pdo, int $fgItemId): float
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(qty_delta), 0) FROM stock_ledger WHERE source_type = 'fg_item' AND source_id = ?"
        );
        $stmt->execute([$fgItemId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Posts exactly one compensating stock_ledger row (qty_delta=$delta,
     * may be negative for a downward correction) and keeps stock_balance
     * in sync in the same transaction. Never called with $delta === 0.0 by
     * FgService — a no-op delta simply posts nothing (idempotent retries,
     * P4-12).
     */
    public function postLedgerDelta(
        PDO $pdo,
        int $productId,
        int $locationId,
        float $delta,
        string $eventDate,
        int $fgItemId,
        ?int $userId
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO stock_ledger
                (product_id, location_id, event_type, qty_delta, source_type, source_id, event_date, created_at, created_by, notes)
             VALUES (?, ?, 'production_in', ?, 'fg_item', ?, ?, UTC_TIMESTAMP(), ?, NULL)"
        );
        $stmt->execute([$productId, $locationId, $delta, $fgItemId, $eventDate, $userId]);
        $ledgerId = (int) $pdo->lastInsertId();

        $upsert = $pdo->prepare(
            'INSERT INTO stock_balance (product_id, location_id, qty_on_hand, last_ledger_id, updated_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand), last_ledger_id = VALUES(last_ledger_id), updated_at = UTC_TIMESTAMP()'
        );
        $upsert->execute([$productId, $locationId, $delta, $ledgerId]);

        return $ledgerId;
    }

    public function findBalance(PDO $pdo, int $productId, int $locationId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM stock_balance WHERE product_id = ? AND location_id = ?');
        $stmt->execute([$productId, $locationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Authoritative, ledger-derived balance — used whenever a stock_balance row is missing or must be cross-checked (P4-29). */
    public function sumLedger(PDO $pdo, int $productId, int $locationId): float
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty_delta), 0) FROM stock_ledger WHERE product_id = ? AND location_id = ?');
        $stmt->execute([$productId, $locationId]);
        return (float) $stmt->fetchColumn();
    }

    public function findLastMovement(PDO $pdo, int $productId, int $locationId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM stock_ledger WHERE product_id = ? AND location_id = ? ORDER BY stock_ledger_id DESC LIMIT 1'
        );
        $stmt->execute([$productId, $locationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
