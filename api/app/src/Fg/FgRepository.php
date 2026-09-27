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

    /**
     * AGGREGATE view, keyed by product_id — one product can now back
     * MULTIPLE fg_item rows (one per store, once "Breakdown Toko" mode is
     * exploded for that product; see FgService::explodeToStores()).
     * qty/packed_qty/reject_qty/hilang_qty here are the SUM across every
     * row belonging to this product — this is the single authoritative
     * "product total = SUM(store rows)" aggregation point every existing
     * caller (patchDraft's ceiling check, submit()'s posting, buildItemDto)
     * already relies on, so none of them need to know or care whether a
     * product is currently split across stores. 'fg_item_id' is the ANCHOR
     * (lowest id among this product's rows) — the single id submit() posts
     * its one aggregated stock_ledger row against. 'mode' is 'perProduk'
     * when every row still belongs to the synthetic unallocated store
     * (FgRepository::unallocatedStoreId), 'breakdownToko' the moment ANY
     * row belongs to a real store — this is the sole source of truth for
     * which UI mode a product is in, never a separately persisted flag.
     * @return array<int,array>
     */
    public function findItems(PDO $pdo, int $batchId): array
    {
        $unallocatedStoreId = $this->unallocatedStoreId($pdo);
        $stmt = $pdo->prepare(
            'SELECT fi.*, p.name AS product_name
             FROM fg_item fi
             INNER JOIN product p ON p.product_id = fi.product_id
             WHERE fi.fg_batch_id = ?
             ORDER BY p.name, fi.fg_item_id'
        );
        $stmt->execute([$batchId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $productId = (int) $r['product_id'];
            if (!isset($out[$productId])) {
                $out[$productId] = [
                    'fg_item_id' => (int) $r['fg_item_id'],
                    'fg_batch_id' => (int) $r['fg_batch_id'],
                    'product_id' => $productId,
                    'product_name' => $r['product_name'],
                    'store_id' => (int) $r['store_id'],
                    'production_actual_snapshot' => (float) $r['production_actual_snapshot'],
                    'qty' => 0.0,
                    'packed_qty' => 0.0,
                    'reject_qty' => 0.0,
                    'hilang_qty' => 0.0,
                    'status' => $r['status'],
                    'keterangan' => $r['keterangan'],
                    'row_count' => 0,
                    'fg_item_ids' => [],
                    'mode' => 'perProduk',
                ];
            }
            $out[$productId]['qty'] += (float) $r['qty'];
            $out[$productId]['packed_qty'] += (float) $r['packed_qty'];
            $out[$productId]['reject_qty'] += (float) $r['reject_qty'];
            $out[$productId]['hilang_qty'] += (float) $r['hilang_qty'];
            $out[$productId]['row_count']++;
            $out[$productId]['fg_item_ids'][] = (int) $r['fg_item_id'];
            if ((int) $r['store_id'] !== $unallocatedStoreId) {
                $out[$productId]['mode'] = 'breakdownToko';
            }
        }
        return $out;
    }

    /**
     * Raw, per-store fg_item rows for ONE product in this batch — the data
     * source for the writable Breakdown Toko table. Ordered by store name
     * for a stable UI listing.
     * @return array<int,array>
     */
    public function findItemRowsForProduct(PDO $pdo, int $batchId, int $productId): array
    {
        $stmt = $pdo->prepare(
            'SELECT fi.*, s.canonical_name AS store_name
             FROM fg_item fi
             INNER JOIN store s ON s.store_id = fi.store_id
             WHERE fi.fg_batch_id = ? AND fi.product_id = ?
             ORDER BY s.canonical_name'
        );
        $stmt->execute([$batchId, $productId]);
        return $stmt->fetchAll();
    }

    public function insertItem(PDO $pdo, int $batchId, int $productId, int $storeId, float $productionActualSnapshot): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO fg_item (fg_batch_id, product_id, store_id, qty, packed_qty, production_actual_snapshot, status, keterangan)
             VALUES (?, ?, ?, 0, 0, ?, 'belum_dicek', NULL)"
        );
        $stmt->execute([$batchId, $productId, $storeId, $productionActualSnapshot]);
    }

    /**
     * Every distinct (store_id, product_id) pair with a REAL store row in
     * this batch (the synthetic unallocatedStoreId is excluded — a Per
     * Produk row is never store-owned, so it can never be shipped-against
     * by a Regular store shipment and the store-ready guard would always
     * be a no-op for it). Used by FgService::submit()'s
     * STORE_PACKED_BELOW_SHIPPED check to know exactly which store+
     * product combinations this submit could affect. Sorted ascending so
     * every caller locks store_fg_balance rows in the SAME deterministic
     * order (deadlock avoidance, same discipline as the existing
     * ascending-product_id lock order in FgService::submit()'s special-
     * reservation preflight).
     * @return array<int,array{storeId:int,productId:int}>
     */
    public function distinctRealStoreProductPairs(PDO $pdo, int $batchId): array
    {
        $unallocatedStoreId = $this->unallocatedStoreId($pdo);
        $stmt = $pdo->prepare(
            'SELECT DISTINCT store_id, product_id FROM fg_item
             WHERE fg_batch_id = ? AND store_id != ?
             ORDER BY store_id, product_id'
        );
        $stmt->execute([$batchId, $unallocatedStoreId]);
        return array_map(static fn ($r) => ['storeId' => (int) $r['store_id'], 'productId' => (int) $r['product_id']], $stmt->fetchAll());
    }

    /**
     * Used only by explode/collapse (mode switching) — deletes ONE
     * fg_item row outright. Never called on a row that has already posted
     * stock (FgService guards this — mode switching is a draft/reopened-
     * only operation, same as every other patchDraft edit, and submit()
     * is the only writer of stock_ledger).
     */
    public function deleteItem(PDO $pdo, int $fgItemId): void
    {
        $stmt = $pdo->prepare('DELETE FROM fg_item WHERE fg_item_id = ?');
        $stmt->execute([$fgItemId]);
    }

    public function updateItemSnapshot(PDO $pdo, int $fgItemId, float $productionActualSnapshot): void
    {
        $stmt = $pdo->prepare('UPDATE fg_item SET production_actual_snapshot = ? WHERE fg_item_id = ?');
        $stmt->execute([$productionActualSnapshot, $fgItemId]);
    }

    /**
     * Freezes posted_packed_qty = packed_qty for every row in this batch —
     * called ONLY by FgService::submit(), AFTER stock_ledger has been
     * posted, never by patchDraft/reopen. This is the ONLY writer of
     * posted_packed_qty; see migration 0014's own docblock for why a
     * store's shipment-facing "ready" total must read this frozen value,
     * never the live packed_qty a reopened-but-not-yet-resubmitted batch
     * might already be mid-edit on.
     */
    public function markAllItemsPosted(PDO $pdo, int $batchId): void
    {
        $stmt = $pdo->prepare('UPDATE fg_item SET posted_packed_qty = packed_qty WHERE fg_batch_id = ?');
        $stmt->execute([$batchId]);
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

    /**
     * Migration 0015 — real persisted Packing submission state per (FG
     * batch, store). ONE row per (fg_batch_id, store_id): 'submitted'
     * right after a successful "Submit Packing [Store]" call
     * (Fg\FgService::submitStorePacking()), flipped to 'stale' (never
     * deleted — submitted_at/submitted_by remain visible as "last known
     * submission" history) the moment a later write actually changes
     * that store's own fg_item data via ANY path.
     */
    public function findPackingSubmission(PDO $pdo, int $batchId, int $storeId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM fg_store_packing_submission WHERE fg_batch_id = ? AND store_id = ?');
        $stmt->execute([$batchId, $storeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array> keyed by store_id — every store this batch has EVER had a Packing submission for (currently 'submitted' or since-invalidated to 'stale'), joined to the submitting user's display name. A store with no row at all has never been submitted. */
    public function findPackingSubmissionsForBatch(PDO $pdo, int $batchId): array
    {
        $stmt = $pdo->prepare(
            'SELECT sp.*, u.full_name AS submitted_by_name
             FROM fg_store_packing_submission sp
             INNER JOIN users u ON u.user_id = sp.submitted_by
             WHERE sp.fg_batch_id = ?'
        );
        $stmt->execute([$batchId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['store_id']] = $r;
        }
        return $out;
    }

    /** Creates or refreshes this store's submission record to 'submitted', with a FRESH submitted_at/submitted_by — always wins over any prior 'stale' state (a resubmit is exactly that: a brand-new submission event, not a resurrection of the old one). $userId must come from the authenticated session (Auth::requireRole()'s own return value), never from request payload — enforced by every caller in FgService/FgController. */
    public function upsertPackingSubmission(PDO $pdo, int $batchId, int $storeId, int $userId): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO fg_store_packing_submission (fg_batch_id, store_id, status, submitted_at, submitted_by, invalidated_at, updated_at)
             VALUES (?, ?, 'submitted', UTC_TIMESTAMP(), ?, NULL, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE status = 'submitted', submitted_at = VALUES(submitted_at), submitted_by = VALUES(submitted_by), invalidated_at = NULL, updated_at = VALUES(updated_at)"
        );
        $stmt->execute([$batchId, $storeId, $userId]);
    }

    /** Flips this store's EXISTING 'submitted' record to 'stale' — a no-op if none exists yet (never submitted) or if it is already 'stale' (nothing to re-invalidate). Never deletes the row. */
    public function invalidatePackingSubmission(PDO $pdo, int $batchId, int $storeId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE fg_store_packing_submission SET status = 'stale', invalidated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE fg_batch_id = ? AND store_id = ? AND status = 'submitted'"
        );
        $stmt->execute([$batchId, $storeId]);
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
     * Same as postedQtyForItem() but summed across EVERY fg_item_id
     * belonging to one product (a Breakdown Toko product posts under
     * several store-level fg_item rows, but must still post to
     * stock_ledger exactly ONCE per product per submit — see
     * FgService::submit()'s own docblock). Anchoring the single posted
     * row to the LOWEST fg_item_id among them (the "anchor") is what makes
     * this re-derivable: postedQtyForProduct() always sums whatever is
     * already there, so a resubmit after reopen still posts only the
     * delta, whether the product was Per Produk or Breakdown Toko at
     * either point in time.
     * @param int[] $fgItemIds
     */
    public function postedQtyForProduct(PDO $pdo, array $fgItemIds): float
    {
        if ($fgItemIds === []) {
            return 0.0;
        }
        $placeholders = implode(',', array_fill(0, count($fgItemIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(qty_delta), 0) FROM stock_ledger WHERE source_type = 'fg_item' AND source_id IN ({$placeholders})"
        );
        $stmt->execute($fgItemIds);
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
