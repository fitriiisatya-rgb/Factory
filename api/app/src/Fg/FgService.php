<?php

declare(strict_types=1);

namespace Amor\Api\Fg;

use Amor\Api\ApiException;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\SpecialOrder\SpecialOrderFgAllocationRepository;
use PDO;

/**
 * Business orchestration for the Phase 4 FG/Packing document — the
 * counterpart to Production\ProductionService, but sourced from Phase 3's
 * submitted production actual instead of Phase 2's PO target, and ending
 * in a real stock_ledger/stock_balance write instead of a read-only target.
 *
 * Core rules enforced here (task sections throughout):
 *   - only a production_run with status='submitted' is a valid FG source
 *     (FgTargetService); draft/reopened runs contribute nothing.
 *   - fg_verified (fg_item.qty) and packed (fg_item.packed_qty) are BOTH
 *     snapshot semantics: every write REPLACES the stored value, never
 *     adds to it — identical discipline to Production's actual_qty.
 *   - validation ceiling (fg_verified <= production_actual_snapshot,
 *     packed <= fg_verified) is checked against the SNAPSHOT captured at
 *     draft-creation/refresh time, never a live-recomputed number — so a
 *     later Production reopen can never retroactively invalidate an
 *     in-progress FG edit. Live production_run state is used ONLY for the
 *     separate, non-blocking sourceInconsistency warning (see
 *     buildSourceInconsistency()).
 *   - stock_ledger is the sole stock-truth write. Every submit (including
 *     a resubmit after reopen) posts exactly the DELTA between this fg_item's
 *     packed_qty and whatever has already been posted for it
 *     (FgRepository::postedQtyForItem, always re-derived from stock_ledger
 *     itself) — never the full packed_qty again. A delta of 0 posts
 *     nothing at all (idempotent retries never double-post).
 *   - draft/reopened never write to stock_ledger. Only submit() does,
 *     inside the same transaction as the version bump.
 *   - GLOBAL FG RESERVATION SAFETY (cross-flow deep-check fix): a
 *     downward correction (delta < 0) can never drop physical
 *     stock_balance below what special_order_fg_allocation actively
 *     reserves for that product+factory — validated for every
 *     negative-delta item, in deterministic product_id order, BEFORE any
 *     ledger row is written, so a blocked correction on one product line
 *     never leaves the rest of the batch partially posted. See
 *     Delivery\ShipmentService's own docblock for the shared canonical
 *     lock order (stock_balance row FIRST, then a locking read of the
 *     active reservation sum) every General-FG-decreasing writer in this
 *     codebase now follows.
 *   - FINAL ATOMICITY PATCH: stock_balance is locked (SELECT ... FOR
 *     UPDATE) for EVERY product this submit touches — not just
 *     negative-delta ones. This is what makes this class's own
 *     establishment of a product's FIRST store allocation (via
 *     posted_packed_qty, see markAllItemsPosted()) atomic with
 *     Delivery\ShipmentService::ship()'s hasAnyStoreAllocation() check —
 *     ship() cannot observe a hybrid "some of this submit committed"
 *     state, because both writers serialize on the SAME stock_balance
 *     row for the SAME product+location before either may proceed to
 *     read or establish store-allocation state. No new schema was
 *     needed for this — stock_balance already existed as the outermost
 *     lock in the canonical order for every product that has ever
 *     posted FG before.
 */
final class FgService
{
    private FgRepository $repo;
    private FgTargetService $targets;
    private DoRepository $doRepo;
    private SpecialOrderFgAllocationRepository $allocRepo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new FgRepository();
        $this->targets = new FgTargetService();
        $this->doRepo = new DoRepository();
        $this->allocRepo = new SpecialOrderFgAllocationRepository();
    }

    /** GET /api/fg/target — live SUBMITTED Production actual, no document created. */
    public function loadTarget(string $tanggal, int $factoryId, ?int $divisionId): array
    {
        $factory = $this->requireFactory($factoryId);
        $runs = $this->targets->eligibleProductionRuns($this->pdo, $tanggal, $factoryId);
        if ($divisionId !== null) {
            $runs = array_values(array_filter($runs, static fn ($r) => $r['divisionId'] === $divisionId));
        }
        $items = $this->targets->productionActualByProduct($this->pdo, $tanggal, $factoryId);
        if ($divisionId !== null) {
            // Preview-only filter (task step 3, "optional source division
            // filter") — re-aggregate from just the matching runs' own
            // production_item rows so the preview reflects only that
            // division, without changing what createDraft() will later
            // aggregate (always the whole factory).
            $items = $this->productionActualForRuns($runs);
        }

        return [
            'tanggal' => $tanggal,
            'factoryId' => $factoryId,
            'factoryName' => $factory['name'],
            'eligibleRuns' => $runs,
            'items' => array_values($items),
            'summary' => ['productCount' => count($items), 'divisionCount' => count($runs)],
        ];
    }

    /**
     * POST /api/fg — creates today's draft for (tanggal,factoryId) if one
     * doesn't already exist, snapshotting the current SUBMITTED production
     * actual for every product across every eligible division. Idempotent
     * at the (tanggal,factoryId) identity level, same as Production's
     * createDraft.
     */
    public function createDraft(string $tanggal, int $factoryId, int $userId): array
    {
        $factory = $this->requireFactory($factoryId);

        $existing = $this->repo->lockExistingBatch($this->pdo, $tanggal, $factoryId);
        if ($existing !== null) {
            return $this->buildBatchDto($existing, $factory);
        }

        $runs = $this->targets->eligibleProductionRuns($this->pdo, $tanggal, $factoryId);
        if ($runs === []) {
            throw new ApiException(400, 'NO_ELIGIBLE_PRODUCTION', 'No SUBMITTED production found for this factory/date — nothing to verify yet');
        }

        $batch = $this->repo->createBatch($this->pdo, $tanggal, $factoryId, $userId);
        $batchId = (int) $batch['fg_batch_id'];
        foreach ($runs as $run) {
            $this->repo->upsertBatchSource($this->pdo, $batchId, $run['productionRunId'], $run['version']);
        }

        $storeId = $this->repo->unallocatedStoreId($this->pdo);
        $actuals = $this->targets->productionActualByProduct($this->pdo, $tanggal, $factoryId);
        foreach ($actuals as $productId => $a) {
            $this->repo->insertItem($this->pdo, $batchId, $productId, $storeId, $a['actual']);
        }

        $batch = $this->repo->lockExistingBatch($this->pdo, $tanggal, $factoryId);
        return $this->buildBatchDto($batch, $factory);
    }

    public function getBatch(int $batchId): array
    {
        $batch = $this->repo->findBatchById($this->pdo, $batchId);
        if ($batch === null) {
            throw new ApiException(404, 'NOT_FOUND', 'FG document not found');
        }
        return $this->buildBatchDto($batch, $batch);
    }

    /** @return array<int,array> */
    public function listBatches(?string $tanggal, ?int $factoryId, ?string $status): array
    {
        $batches = $this->repo->findBatches($this->pdo, $tanggal, $factoryId, $status);
        return array_map(fn ($b) => $this->buildBatchDto($b, $b, includeItems: false), $batches);
    }

    /**
     * PATCH /api/fg/{id} — draft/reopened only.
     *
     * $items (Per Produk edits): list of {productId, fgVerified, packed,
     * reject?, hilang?, sesuaiVerified?, sesuaiPacking?, notes?}; every
     * write REPLACES that product's stored qty/packed_qty/reject_qty/
     * hilang_qty (never additive). Rejected with PRODUCT_IN_BREAKDOWN_MODE
     * for any product currently split across stores — see class docblock
     * "Option A": once a product has real per-store rows, its product-
     * level fields are DERIVED (read-only) until an explicit collapse.
     *
     * $storeItems (Breakdown Toko edits): list of {productId, rows:[
     * {storeId, fgVerified, packed, reject?, hilang?, sesuaiVerified?,
     * sesuaiPacking?, notes?}]}. The FIRST store-level edit for a product
     * still in Per Produk mode EXPLODES it (see explodeToStores()) —
     * every subsequent product-level total is then simply
     * SUM(these store rows), never a second, independently-writable
     * number (task's own "no double counting" rule, satisfied here by
     * construction: buildItemDto()/submit() both read
     * FgRepository::findItems()'s aggregate, never a second store-side
     * total).
     *
     * $collapseProductIds: explicit "Kembali ke Per Produk" action — merges
     * a product's store rows back into ONE row (SUM of qty/packed_qty/
     * reject_qty/hilang_qty, so totals never change across the switch —
     * "switching modes never doubles").
     *
     * $refreshSource pulls newly-submitted divisions' products into the
     * batch and refreshes production_actual_snapshot on EVERY existing row
     * (Per Produk or Breakdown Toko); it never touches an item that
     * already has fgVerified>0 for a brand-new product, and never
     * auto-invalidates an already-entered store row (see class docblock).
     *
     * sesuaiVerified/sesuaiPacking are client-declared INTENT (the Sesuai
     * button), same pattern and same "never trust a disabled UI input
     * alone" rule as ProductionService::patchDraft()'s own sesuai check:
     * for a Per Produk line, sesuaiVerified=true requires fgVerified to
     * equal the CURRENT production_actual_snapshot; for a store row,
     * sesuaiVerified=true requires that STORE's own fgVerified to equal
     * its OWN live PO target (FgTargetService::storeBreakdownForProduct(),
     * PO Awal + latest Revisi, PB ignored — reused, never reformulated).
     * Either way sesuaiPacking=true requires packed to equal that SAME
     * line's own fgVerified. reject_qty/hilang_qty are independent FG-side
     * columns (migration 0014) — never merged into Actual/Verified, never
     * confused with Production's own reject. Keterangan is required
     * whenever Verified or Packing was marked Tidak Sesuai, or Reject>0, or
     * Hilang>0 (task's own explicit rule) — checked server-side, not just
     * a client hint, for BOTH Per Produk lines and individual store rows.
     */
    public function patchDraft(
        int $batchId,
        int $expectedVersion,
        array $items,
        bool $refreshSource,
        int $userId,
        ?string $requestId,
        array $storeItems = [],
        array $collapseProductIds = []
    ): array {
        $batch = $this->repo->lockBatchById($this->pdo, $batchId);
        if ($batch === null) {
            throw new ApiException(404, 'NOT_FOUND', 'FG document not found');
        }
        $this->assertEditable($batch);
        $factory = $this->requireFactory((int) $batch['factory_id']);
        $tanggal = (string) $batch['tanggal'];
        $factoryId = (int) $batch['factory_id'];

        if ($refreshSource) {
            $this->refreshSource($batchId, $tanggal, $factoryId);
        }

        foreach ($collapseProductIds as $productId) {
            $productId = (int) $productId;
            // Collapsing merges every store row into ONE placeholder row
            // against the synthetic unallocated store — which would erase
            // exactly the per-store ownership DoRepository::
            // sumShippedForStore()/sumPackedForStore() need to keep
            // enforcing "Store A cannot consume Store B's ready FG" (task's
            // own "do not corrupt historical shipment ownership" rule).
            // Refused outright the moment ANY of this product's stores has
            // ANY already-shipped quantity — there is no safe way to
            // un-attribute a real, already-departed shipment back to an
            // anonymous pool.
            $rawRows = $this->repo->findItemRowsForProduct($this->pdo, $batchId, $productId);
            foreach ($rawRows as $row) {
                $storeId = (int) $row['store_id'];
                if ($storeId === $this->repo->unallocatedStoreId($this->pdo)) {
                    continue;
                }
                // forUpdate=true — see DoRepository::sumPackedForStore()'s
                // own docblock on why a plain SELECT here could miss a
                // concurrent shipment under REPEATABLE READ.
                $shipped = $this->doRepo->sumShippedForStore($this->pdo, $storeId, $productId, $factoryId, true);
                if ($shipped > 0.0001) {
                    $store = $this->doRepo->findStore($this->pdo, $storeId);
                    $storeName = $store['canonical_name'] ?? "Toko #{$storeId}";
                    throw new ApiException(
                        409,
                        'CANNOT_COLLAPSE_STORE_ALREADY_SHIPPED',
                        "Produk ini tidak bisa digabung kembali ke Per Produk: Toko {$storeName} sudah memiliki pengiriman ({$shipped} pcs) atas alokasi per-Toko ini."
                    );
                }
            }
            $this->collapseToProduct($batchId, $productId);
        }

        $existingItems = $this->repo->findItems($this->pdo, $batchId);
        $touched = 0;
        foreach ($items as $line) {
            $productId = (int) ($line['productId'] ?? 0);
            if ($productId <= 0 || !isset($existingItems[$productId])) {
                throw new ApiException(400, 'UNKNOWN_PRODUCT_FOR_BATCH', "Product {$productId} is not part of this FG document — use refreshSource to pull in newly-submitted production first");
            }
            $item = $existingItems[$productId];
            if ($item['mode'] === 'breakdownToko') {
                throw new ApiException(409, 'PRODUCT_IN_BREAKDOWN_MODE', "Produk {$item['product_name']}: sudah dipecah per Toko (Breakdown Toko) — edit via input per-Toko, atau gunakan 'Kembali ke Per Produk' untuk menggabungkan kembali");
            }
            $snapshot = (float) $item['production_actual_snapshot'];
            $fgVerified = (float) ($line['fgVerified'] ?? 0);
            $packed = (float) ($line['packed'] ?? 0);
            $reject = isset($line['reject']) ? (float) $line['reject'] : (float) ($item['reject_qty'] ?? 0);
            $hilang = isset($line['hilang']) ? (float) $line['hilang'] : (float) ($item['hilang_qty'] ?? 0);
            if ($fgVerified < 0 || $packed < 0 || $reject < 0 || $hilang < 0) {
                throw new ApiException(400, 'INVALID_QTY', 'fgVerified/packed/reject/hilang cannot be negative');
            }
            if ($packed > $fgVerified) {
                throw new ApiException(400, 'PACKED_EXCEEDS_VERIFIED', "Product {$productId}: packed ({$packed}) cannot exceed FG verified ({$fgVerified})");
            }
            if ($fgVerified > $snapshot) {
                throw new ApiException(400, 'FG_EXCEEDS_PRODUCTION', "Product {$productId}: FG verified ({$fgVerified}) cannot exceed production actual ({$snapshot})");
            }
            // sesuaiVerified/sesuaiPacking are THREE-STATE: absent (a
            // caller that never participates in the Sesuai/Tidak Sesuai UX
            // at all — every pre-existing caller/test, which must keep
            // working exactly as before this task) vs explicit true
            // (Sesuai — validated below) vs explicit false (the operator
            // actively clicked Tidak Sesuai — only THIS state, not merely
            // "flag absent", triggers the Keterangan-required rule below).
            $sesuaiVerified = $line['sesuaiVerified'] ?? null;
            $sesuaiPacking = $line['sesuaiPacking'] ?? null;
            if ($sesuaiVerified === true && abs($fgVerified - $snapshot) > 0.01) {
                throw new ApiException(400, 'SESUAI_VERIFIED_MISMATCH', "Product {$productId}: status Sesuai requires FG Verified ({$fgVerified}) to equal Target FG / Hasil Produksi ({$snapshot})");
            }
            if ($sesuaiPacking === true && abs($packed - $fgVerified) > 0.01) {
                throw new ApiException(400, 'SESUAI_PACKING_MISMATCH', "Product {$productId}: status Sesuai requires Packed ({$packed}) to equal FG Verified ({$fgVerified})");
            }
            $notes = isset($line['notes']) ? trim((string) $line['notes']) : trim((string) ($item['keterangan'] ?? ''));
            if (($notes === '') && ($sesuaiVerified === false || $sesuaiPacking === false || $reject > 0.0001 || $hilang > 0.0001)) {
                throw new ApiException(400, 'NOTES_REQUIRED', "Product {$productId}: Keterangan wajib diisi jika Verified/Packing Tidak Sesuai, atau Reject/Hilang > 0");
            }
            $this->repo->updateItemValues($this->pdo, (int) $item['fg_item_id'], $fgVerified, $packed, $reject, $hilang, $notes !== '' ? $notes : null);
            $touched++;
        }

        $storeRowsTouched = 0;
        $storesChanged = [];
        foreach ($storeItems as $entry) {
            $productId = (int) ($entry['productId'] ?? 0);
            $rows = (array) ($entry['rows'] ?? []);
            if ($productId <= 0 || !isset($existingItems[$productId])) {
                throw new ApiException(400, 'UNKNOWN_PRODUCT_FOR_BATCH', "Product {$productId} is not part of this FG document — use refreshSource to pull in newly-submitted production first");
            }
            $product = $existingItems[$productId];
            $snapshot = (float) $product['production_actual_snapshot'];

            if ($product['mode'] === 'perProduk') {
                $this->explodeToStores($batchId, $productId, $tanggal, $factoryId, $product);
            } else {
                // Already exploded — still sync in any store that appeared
                // in the live PO target since the last explode/refresh (a
                // PO revision adding a new store never removes a row an
                // operator already entered — see refreshSource()'s own
                // docblock, same rule applied here for a store edit made
                // without an explicit Refresh Produksi Terbaru first).
                $this->syncStoreRows($batchId, $productId, $tanggal, $factoryId, $snapshot);
            }

            $targetRows = $this->targets->storeBreakdownForProduct($this->pdo, $tanggal, $factoryId, $productId);
            $targetByStore = [];
            foreach ($targetRows as $t) {
                $targetByStore[$t['storeId']] = $t;
            }
            $rawRows = $this->repo->findItemRowsForProduct($this->pdo, $batchId, $productId);
            $rawByStore = [];
            foreach ($rawRows as $r) {
                $rawByStore[(int) $r['store_id']] = $r;
            }

            foreach ($rows as $row) {
                $storeId = (int) ($row['storeId'] ?? 0);
                $changed = $this->applyStoreRow($product, $targetByStore, $rawByStore, $storeId, $row);
                if ($changed) {
                    $storesChanged[$storeId] = true;
                }
                $storeRowsTouched++;
            }

            // Global ceiling, re-checked against the FULL set of this
            // product's rows (not just the ones touched this call) — the
            // same FG_EXCEEDS_PRODUCTION rule Per Produk enforces, now
            // applied to SUM(store rows) instead of a single row's value.
            $afterRows = $this->repo->findItemRowsForProduct($this->pdo, $batchId, $productId);
            $totalVerified = array_sum(array_map(static fn ($r) => (float) $r['qty'], $afterRows));
            if ($totalVerified > $snapshot + 0.0001) {
                throw new ApiException(400, 'FG_EXCEEDS_PRODUCTION', "Product {$productId}: total FG verified across stores ({$totalVerified}) cannot exceed production actual ({$snapshot})");
            }
        }

        // A store's Packing submission is scoped to (batch, store), never
        // to any one product — the instant ANY row belonging to that
        // store actually changes (via THIS generic path, e.g. Breakdown
        // Toko's own Reject/Hilang/Keterangan save in FG Verifikasi — the
        // only fields it lets an operator edit; packed_qty itself is only
        // ever echoed back unchanged there), a prior "Sudah Disubmit" for
        // that store must no longer claim to reflect the numbers the
        // server actually has now (task's own "edit after submit ->
        // Perlu Submit Ulang" rule). A no-op resave (identical values)
        // never invalidates anything.
        foreach (array_keys($storesChanged) as $storeId) {
            $this->repo->invalidatePackingSubmission($this->pdo, $batchId, $storeId);
        }

        $bumped = $this->repo->bumpVersion($this->pdo, $batchId, $expectedVersion, 'status = status', []);
        $this->assertVersionBumpSucceeded($batchId, $expectedVersion, $bumped);

        \Amor\Api\Audit::write(
            $this->pdo, $requestId, $userId, 'fg.draft.edit', 'fg_batch', (string) $batchId,
            'ok', $expectedVersion, $expectedVersion + 1, [
                'itemsTouched' => $touched,
                'storeRowsTouched' => $storeRowsTouched,
                'collapsed' => array_values(array_map('intval', $collapseProductIds)),
                'refreshSource' => $refreshSource,
            ]
        );

        $batch = $this->repo->findBatchById($this->pdo, $batchId);
        return $this->buildBatchDto($batch, $factory);
    }

    /**
     * Validates and writes ONE (productId, storeId) row within an active
     * store-level PATCH — shared by patchDraft()'s generic storeItems
     * path (Breakdown Toko's own save) and submitStorePacking()'s
     * dedicated "Submit Packing [Store]" action, so both enforce EXACTLY
     * the same rules (PACKED_EXCEEDS_VERIFIED, STORE_FG_EXCEEDS_TARGET,
     * SESUAI_*_MISMATCH, NOTES_REQUIRED) — never two slightly different
     * copies of this validation to drift apart.
     *
     * @return bool true if this row's own persisted values actually
     *   changed (used to decide whether to invalidate a store's Packing
     *   submission state — a same-value resave never does).
     */
    private function applyStoreRow(array $product, array $targetByStore, array $rawByStore, int $storeId, array $row): bool
    {
        if (!isset($targetByStore[$storeId]) || !isset($rawByStore[$storeId])) {
            throw new ApiException(400, 'UNKNOWN_STORE_FOR_PRODUCT', "Toko {$storeId} bukan bagian dari target PO produk {$product['product_name']} pada tanggal/pabrik ini");
        }
        $target = (float) $targetByStore[$storeId]['target'];
        $storeName = $targetByStore[$storeId]['storeName'];
        $raw = $rawByStore[$storeId];

        $fgVerified = (float) ($row['fgVerified'] ?? 0);
        $packed = (float) ($row['packed'] ?? 0);
        $reject = isset($row['reject']) ? (float) $row['reject'] : (float) ($raw['reject_qty'] ?? 0);
        $hilang = isset($row['hilang']) ? (float) $row['hilang'] : (float) ($raw['hilang_qty'] ?? 0);
        if ($fgVerified < 0 || $packed < 0 || $reject < 0 || $hilang < 0) {
            throw new ApiException(400, 'INVALID_QTY', 'fgVerified/packed/reject/hilang cannot be negative');
        }
        if ($packed > $fgVerified) {
            throw new ApiException(400, 'PACKED_EXCEEDS_VERIFIED', "Produk {$product['product_name']} / Toko {$storeName}: packed ({$packed}) cannot exceed FG verified ({$fgVerified})");
        }
        if ($fgVerified > $target + 0.0001) {
            throw new ApiException(400, 'STORE_FG_EXCEEDS_TARGET', "Produk {$product['product_name']} / Toko {$storeName}: FG verified ({$fgVerified}) cannot exceed store target ({$target})");
        }
        $sesuaiVerified = $row['sesuaiVerified'] ?? null;
        $sesuaiPacking = $row['sesuaiPacking'] ?? null;
        if ($sesuaiVerified === true && abs($fgVerified - $target) > 0.01) {
            throw new ApiException(400, 'SESUAI_VERIFIED_MISMATCH', "Produk {$product['product_name']} / Toko {$storeName}: status Sesuai requires FG Verified ({$fgVerified}) to equal Target Toko ({$target})");
        }
        if ($sesuaiPacking === true && abs($packed - $fgVerified) > 0.01) {
            throw new ApiException(400, 'SESUAI_PACKING_MISMATCH', "Produk {$product['product_name']} / Toko {$storeName}: status Sesuai requires Packed ({$packed}) to equal FG Verified ({$fgVerified})");
        }
        $notes = isset($row['notes']) ? trim((string) $row['notes']) : trim((string) ($raw['keterangan'] ?? ''));
        if (($notes === '') && ($sesuaiVerified === false || $sesuaiPacking === false || $reject > 0.0001 || $hilang > 0.0001)) {
            throw new ApiException(400, 'NOTES_REQUIRED', "Produk {$product['product_name']} / Toko {$storeName}: Keterangan wajib diisi jika Verified/Packing Tidak Sesuai, atau Reject/Hilang > 0");
        }

        $changed = abs($fgVerified - (float) $raw['qty']) > 0.0001
            || abs($packed - (float) $raw['packed_qty']) > 0.0001
            || abs($reject - (float) $raw['reject_qty']) > 0.0001
            || abs($hilang - (float) $raw['hilang_qty']) > 0.0001
            || trim((string) ($raw['keterangan'] ?? '')) !== $notes;

        $this->repo->updateItemValues($this->pdo, (int) $raw['fg_item_id'], $fgVerified, $packed, $reject, $hilang, $notes !== '' ? $notes : null);
        return $changed;
    }

    /**
     * POST /api/fg/{id}/packing-submit — "Submit Packing [Store]", the
     * ONE action that both writes a store's Packing rows AND, in the
     * SAME transaction (Idempotency::handle() already wraps this whole
     * call in one — see its own docblock: a thrown exception rolls back
     * everything, so nothing here is ever partially committed), records
     * that store's real, persisted submission event —
     * fg_store_packing_submission (migration 0015). This is what
     * PACK-SUBMIT-01..10 require: submission is about the ACTION
     * completing, never about packed_qty happening to equal target (a
     * store may legitimately submit Tidak Sesuai with packed < target,
     * or packed = 0 with a valid discrepancy note — both pass through
     * applyStoreRow()'s own unchanged validation and both still count as
     * a real, complete submission).
     *
     * Never marks submitted before validation succeeds: every row is
     * validated (and the same FG_EXCEEDS_PRODUCTION ceiling re-checked)
     * BEFORE upsertPackingSubmission() is ever called, and if ANY row
     * throws, the whole request rolls back before that point is reached.
     *
     * Distinct from patchDraft()'s generic storeItems path: this is the
     * ONLY place a Packing submission is ever created, scoped to exactly
     * one store per call — Breakdown Toko's own "Simpan Breakdown Toko"
     * (FG Verifikasi) keeps using the generic PATCH, which can
     * INVALIDATE an existing submission (via applyStoreRow()'s own
     * "changed" signal, see patchDraft()'s own storesChanged loop) but
     * never creates one.
     */
    public function submitStorePacking(int $batchId, int $expectedVersion, int $storeId, array $rows, int $userId, ?string $requestId): array
    {
        $batch = $this->repo->lockBatchById($this->pdo, $batchId);
        if ($batch === null) {
            throw new ApiException(404, 'NOT_FOUND', 'FG document not found');
        }
        $this->assertEditable($batch);
        $factory = $this->requireFactory((int) $batch['factory_id']);
        $tanggal = (string) $batch['tanggal'];
        $factoryId = (int) $batch['factory_id'];

        if ($rows === []) {
            throw new ApiException(400, 'NO_ROWS', 'Tidak ada baris produk untuk disubmit pada Toko ini');
        }

        $existingItems = $this->repo->findItems($this->pdo, $batchId);
        $storeRowsTouched = 0;
        foreach ($rows as $row) {
            $productId = (int) ($row['productId'] ?? 0);
            if ($productId <= 0 || !isset($existingItems[$productId])) {
                throw new ApiException(400, 'UNKNOWN_PRODUCT_FOR_BATCH', "Product {$productId} is not part of this FG document");
            }
            $product = $existingItems[$productId];
            if ($product['mode'] !== 'breakdownToko') {
                // A product still entirely in default Per Produk mode has
                // no real per-store fgVerified yet — see fg-packing.php's
                // own "Belum di-Breakdown Toko" read-only row docblock.
                // The client already excludes such rows from this
                // payload; re-validated here too, never trusted client-
                // side alone.
                throw new ApiException(409, 'PRODUCT_NOT_EXPLODED', "Produk {$product['product_name']}: belum di-Breakdown Toko — tidak bisa disubmit per Toko");
            }
            $rowStoreId = (int) ($row['storeId'] ?? $storeId);
            if ($rowStoreId !== $storeId) {
                throw new ApiException(400, 'STORE_MISMATCH', "Baris untuk produk {$product['product_name']} tidak cocok dengan Toko yang sedang disubmit");
            }

            $targetRows = $this->targets->storeBreakdownForProduct($this->pdo, $tanggal, $factoryId, $productId);
            $targetByStore = [];
            foreach ($targetRows as $t) {
                $targetByStore[$t['storeId']] = $t;
            }
            $rawRows = $this->repo->findItemRowsForProduct($this->pdo, $batchId, $productId);
            $rawByStore = [];
            foreach ($rawRows as $r) {
                $rawByStore[(int) $r['store_id']] = $r;
            }

            $this->applyStoreRow($product, $targetByStore, $rawByStore, $storeId, $row);
            $storeRowsTouched++;

            $afterRows = $this->repo->findItemRowsForProduct($this->pdo, $batchId, $productId);
            $totalVerified = array_sum(array_map(static fn ($r) => (float) $r['qty'], $afterRows));
            $snapshot = (float) $product['production_actual_snapshot'];
            if ($totalVerified > $snapshot + 0.0001) {
                throw new ApiException(400, 'FG_EXCEEDS_PRODUCTION', "Product {$productId}: total FG verified across stores ({$totalVerified}) cannot exceed production actual ({$snapshot})");
            }
        }

        $bumped = $this->repo->bumpVersion($this->pdo, $batchId, $expectedVersion, 'status = status', []);
        $this->assertVersionBumpSucceeded($batchId, $expectedVersion, $bumped);

        // Reachable only once EVERY row above validated and wrote
        // successfully, and the version bump itself succeeded — the
        // submission record is the LAST thing this call does.
        $this->repo->upsertPackingSubmission($this->pdo, $batchId, $storeId, $userId);

        \Amor\Api\Audit::write(
            $this->pdo, $requestId, $userId, 'fg.packing.submit_store', 'fg_batch', (string) $batchId,
            'ok', $expectedVersion, $expectedVersion + 1, ['storeId' => $storeId, 'rowsTouched' => $storeRowsTouched]
        );

        $batch = $this->repo->findBatchById($this->pdo, $batchId);
        return $this->buildBatchDto($batch, $factory);
    }

    /**
     * Explodes a Per Produk product into real per-store fg_item rows, one
     * per store in its LIVE PO target (FgTargetService::
     * storeBreakdownForProduct(), reused — never a new formula). Only
     * allowed while the placeholder row is still all-zero: there is no
     * safe way to infer how an already-entered aggregate number should be
     * split across stores, so this deliberately refuses to guess (task's
     * own "do not invent a new business formula" rule) rather than
     * silently assigning the whole total to one store or dividing it
     * evenly. The placeholder row is deleted and replaced by the new
     * store rows — never left behind as a stray extra row, which is what
     * keeps "product total = SUM(store rows)" true by construction from
     * this point on.
     */
    private function explodeToStores(int $batchId, int $productId, string $tanggal, int $factoryId, array $product): void
    {
        $qty = (float) $product['qty'];
        $packed = (float) $product['packed_qty'];
        $reject = (float) $product['reject_qty'];
        $hilang = (float) $product['hilang_qty'];
        if ($qty > 0.0001 || $packed > 0.0001 || $reject > 0.0001 || $hilang > 0.0001) {
            throw new ApiException(409, 'MODE_SWITCH_REQUIRES_ZERO_PER_PRODUK', "Produk {$product['product_name']}: pindah ke mode Breakdown Toko hanya bisa dilakukan sebelum FG Verified/Packing/Reject/Hilang diisi pada mode Per Produk. Kosongkan (reset ke 0) dahulu, atau lanjutkan di mode Per Produk.");
        }
        $targetRows = $this->targets->storeBreakdownForProduct($this->pdo, $tanggal, $factoryId, $productId);
        if ($targetRows === []) {
            throw new ApiException(400, 'NO_STORE_TARGET_FOR_PRODUCT', "Produk {$product['product_name']}: tidak memiliki target per-Toko dari PO pada tanggal/pabrik ini — tidak bisa dipindah ke mode Breakdown Toko.");
        }
        $snapshot = (float) $product['production_actual_snapshot'];
        $this->repo->deleteItem($this->pdo, (int) $product['fg_item_id']);
        foreach ($targetRows as $t) {
            $this->repo->insertItem($this->pdo, $batchId, $productId, $t['storeId'], $snapshot);
        }
    }

    /** Inserts a zero row for any store present in the live PO target but not yet represented among this product's fg_item rows. */
    private function syncStoreRows(int $batchId, int $productId, string $tanggal, int $factoryId, float $snapshot): void
    {
        $targetRows = $this->targets->storeBreakdownForProduct($this->pdo, $tanggal, $factoryId, $productId);
        $existingRaw = $this->repo->findItemRowsForProduct($this->pdo, $batchId, $productId);
        $existingStoreIds = array_map(static fn ($r) => (int) $r['store_id'], $existingRaw);
        foreach ($targetRows as $t) {
            if (!in_array($t['storeId'], $existingStoreIds, true)) {
                $this->repo->insertItem($this->pdo, $batchId, $productId, $t['storeId'], $snapshot);
            }
        }
    }

    /**
     * Explicit "Kembali ke Per Produk" action — merges every store row
     * this product currently has back into ONE placeholder row against
     * the synthetic unallocated store. qty/packed_qty/reject_qty/
     * hilang_qty are the exact SUM of the rows being merged (never
     * re-derived or rounded), so a collapse can never change a product's
     * totals — only a Per Produk resubmit can be a no-op is guaranteed:
     * collapse-then-immediately-recompute always equals what
     * findItems()'s aggregate already reported before the collapse.
     * A no-op if the product is already Per Produk.
     */
    private function collapseToProduct(int $batchId, int $productId): void
    {
        $rows = $this->repo->findItemRowsForProduct($this->pdo, $batchId, $productId);
        if (count($rows) <= 1) {
            $unallocatedStoreId = $this->repo->unallocatedStoreId($this->pdo);
            if ($rows === [] || (int) $rows[0]['store_id'] === $unallocatedStoreId) {
                return;
            }
        }
        $qty = 0.0;
        $packed = 0.0;
        $reject = 0.0;
        $hilang = 0.0;
        $snapshot = 0.0;
        $notesParts = [];
        foreach ($rows as $r) {
            $qty += (float) $r['qty'];
            $packed += (float) $r['packed_qty'];
            $reject += (float) $r['reject_qty'];
            $hilang += (float) $r['hilang_qty'];
            $snapshot = (float) $r['production_actual_snapshot'];
            $note = trim((string) ($r['keterangan'] ?? ''));
            if ($note !== '' && !in_array($note, $notesParts, true)) {
                $notesParts[] = $note;
            }
            $this->repo->deleteItem($this->pdo, (int) $r['fg_item_id']);
        }
        $unallocatedStoreId = $this->repo->unallocatedStoreId($this->pdo);
        $this->repo->insertItem($this->pdo, $batchId, $productId, $unallocatedStoreId, $snapshot);
        $newItems = $this->repo->findItemRowsForProduct($this->pdo, $batchId, $productId);
        $newFgItemId = (int) $newItems[0]['fg_item_id'];
        $notes = $notesParts !== [] ? implode('; ', $notesParts) : null;
        $this->repo->updateItemValues($this->pdo, $newFgItemId, $qty, $packed, $reject, $hilang, $notes);
    }

    /**
     * POST /api/fg/{id}/submit — draft or reopened -> submitted. Posts
     * exactly one compensating stock_ledger row per item whose packed_qty
     * differs from what's already posted (see class docblock). A
     * production-source inconsistency (some contributing production_run
     * changed version, or is no longer 'submitted', since it was recorded)
     * is a WARNING ONLY — submission proceeds either way, recorded in
     * audit_log.
     */
    public function submit(int $batchId, int $expectedVersion, int $userId, ?string $requestId): array
    {
        $batch = $this->repo->lockBatchById($this->pdo, $batchId);
        if ($batch === null) {
            throw new ApiException(404, 'NOT_FOUND', 'FG document not found');
        }
        $this->assertEditable($batch);
        $factory = $this->requireFactory((int) $batch['factory_id']);
        $factoryId = (int) $batch['factory_id'];
        $tanggal = (string) $batch['tanggal'];
        $locationId = $this->repo->findOrCreateLocationForFactory($this->pdo, $factoryId, $factory['name']);

        $items = $this->repo->findItems($this->pdo, $batchId);
        if ($items === []) {
            throw new ApiException(400, 'EMPTY_FG_BATCH', 'This FG document has no product lines yet — nothing to submit');
        }

        // Pre-flight: a Production source refresh may have lowered a
        // product's production_actual_snapshot below an already-entered
        // fgVerified (task's own explicit rule — never auto-reduce
        // fgVerified, but never silently let it post stock beyond current
        // Production either). Checked BEFORE any stock_ledger write below,
        // so a blocked submit is guaranteed to have posted nothing at all.
        foreach ($items as $productId => $item) {
            $snapshot = (float) $item['production_actual_snapshot'];
            $fgVerified = (float) $item['qty'];
            if ($fgVerified - $snapshot > 0.0001) {
                throw new ApiException(409, 'FG_EXCEEDS_PRODUCTION', "Produk {$item['product_name']}: FG Verified ({$fgVerified}) melebihi Production Actual terbaru ({$snapshot}) — perbaiki FG Verified sebelum submit.");
            }
        }

        $deltas = [];
        foreach ($items as $productId => $item) {
            // $item is the AGGREGATE view (FgRepository::findItems()) — for
            // a Breakdown Toko product this sums packed_qty across every
            // store row, and postedQtyForProduct() sums stock_ledger
            // across every one of those rows' fg_item_ids, so the delta
            // below is computed against the product's WHOLE posting
            // history regardless of how many store rows it currently has,
            // or had at any earlier submit/reopen/resubmit cycle.
            $posted = $this->repo->postedQtyForProduct($this->pdo, $item['fg_item_ids']);
            $deltas[$productId] = (float) $item['packed_qty'] - $posted;
        }

        // GLOBAL FG RESERVATION SAFETY (cross-flow deep-check fix): a
        // downward correction here is a General-FG-decreasing write, the
        // SAME class of write ShipmentService::ship() and
        // SpecialOrderFgAllocationService::consumeForDispatch() are
        // already gated on — physical stock may never drop below what a
        // special/non-regular order has ACTIVELY reserved. Preflighted
        // in deterministic (ascending product_id) lock order, BEFORE any
        // stock_ledger row is written for this submit — so a blocked
        // correction on one product can never leave a partially-applied
        // FG batch (some products posted, others not). Per the canonical
        // lock order every General-FG writer in this codebase now
        // shares: lock stock_balance row FIRST, then locking-read the
        // active reservation sum, then validate, then write.
        //
        // FINAL ATOMICITY PATCH: stock_balance is now locked for EVERY
        // product this submit touches — not just negative-delta ones.
        // This is the deliberate, minimal change that closes the mode-
        // transition race the prior pass's own delivery report disclosed
        // as an architecture risk: stock_balance(product, location) is
        // already the FIRST lock in the canonical order and already
        // exists (or is safely lockable-as-absent) for any product that
        // has ever posted FG before — reusing it as the SAME mutex that
        // also governs "is this product's store-allocation state being
        // established right now" means Delivery\ShipmentService::ship()'s
        // hasAnyStoreAllocation() check (also FOR UPDATE-gated, see that
        // method's own docblock) can never observe a hybrid state: either
        // this submit has fully committed before ship() ever reads that
        // flag, or ship() holds the lock first and submit() waits behind
        // it. No new table or column was needed — only reusing an
        // existing lock more consistently. The actual RESERVATION-SAFETY
        // validation itself is unchanged and still applies to
        // negative-delta products only (positive/zero deltas never
        // conflict with a reservation — they only ever grow physical
        // stock).
        $allProductIds = array_keys($items);
        sort($allProductIds);
        foreach ($allProductIds as $productId) {
            $delta = $deltas[$productId];
            $balanceRow = $this->doRepo->lockBalance($this->pdo, $productId, $locationId);
            if ($delta < -0.0001) {
                $physical = $balanceRow !== null ? (float) $balanceRow['qty_on_hand'] : 0.0;
                $reservedSpecial = $this->allocRepo->sumActiveAllocatedForProductFactory($this->pdo, $productId, $factoryId);
                $newPhysical = $physical + $delta;
                if ($newPhysical < $reservedSpecial - 0.0001) {
                    $maxDown = max(0.0, $physical - $reservedSpecial);
                    $productName = $items[$productId]['product_name'];
                    throw new ApiException(
                        409,
                        'FG_CORRECTION_BELOW_RESERVED',
                        "Tidak dapat mengurangi FG {$productName} sebanyak " . abs($delta) . " pcs. "
                        . "Stok fisik: {$physical} pcs. Sudah dialokasikan: {$reservedSpecial} pcs. "
                        . "Maksimal koreksi turun: {$maxDown} pcs."
                    );
                }
            }
        }

        // STORE-SPECIFIC FG OWNERSHIP (FINAL CORE BLOCKER fix): a store
        // row's packed_qty is allowed to be corrected DOWNWARD (e.g. after
        // a reopen), but never below what has ALREADY been shipped to
        // that specific store via an active Regular shipment — otherwise
        // this submit would retroactively make a real, already-departed
        // shipment exceed what this store ever legitimately owned.
        // Checked for EVERY real store row this batch touches (ascending
        // store_id/product_id lock order — FgRepository::
        // distinctRealStoreProductPairs()'s own docblock), locking
        // store_fg_balance SECOND, after stock_balance, per the canonical
        // lock order documented in both migration 0014's extension and
        // Delivery\ShipmentService's own docblock — never the reverse, so
        // a concurrent ship() for the same store+product can never
        // deadlock against this submit. sumPackedForStore's
        // $includeBatchId=$batchId makes the check reflect what THIS
        // store row's packed_qty will become the instant this submit
        // commits, even though the batch itself is still 'draft'/
        // 'reopened' (not yet 'submitted') at the moment this runs.
        $storeProductPairs = $this->repo->distinctRealStoreProductPairs($this->pdo, $batchId);
        foreach ($storeProductPairs as $pair) {
            $this->doRepo->lockStoreFgBalance($this->pdo, $pair['storeId'], $pair['productId'], $locationId);
            // forUpdate=true — see DoRepository::sumPackedForStore()'s own
            // docblock: without it, a REPEATABLE READ snapshot established
            // earlier in this transaction could still hide a concurrent
            // ShipmentService::ship()'s already-committed shipment_item
            // row even after genuinely waiting on the lock above.
            $storePacked = $this->doRepo->sumPackedForStore($this->pdo, $pair['storeId'], $pair['productId'], $factoryId, $batchId, true);
            $storeShipped = $this->doRepo->sumShippedForStore($this->pdo, $pair['storeId'], $pair['productId'], $factoryId, true);
            if ($storePacked < $storeShipped - 0.0001) {
                $store = $this->doRepo->findStore($this->pdo, $pair['storeId']);
                $storeName = $store['canonical_name'] ?? "Toko #{$pair['storeId']}";
                $productName = $items[$pair['productId']]['product_name'] ?? "Produk #{$pair['productId']}";
                throw new ApiException(
                    409,
                    'STORE_PACKED_BELOW_SHIPPED',
                    "Produk {$productName} / Toko {$storeName}: FG Packed ({$storePacked}) tidak boleh kurang dari yang sudah dikirim ke toko ini ({$storeShipped}). "
                    . "Minimal FG Packed untuk toko ini: {$storeShipped}."
                );
            }
        }

        $postings = [];
        foreach ($items as $productId => $item) {
            $delta = $deltas[$productId];
            if (abs($delta) > 0.0001) {
                $ledgerId = $this->repo->postLedgerDelta(
                    $this->pdo, $productId, $locationId, $delta, $tanggal, (int) $item['fg_item_id'], $userId
                );
                $postings[] = ['productId' => $productId, 'delta' => $delta, 'ledgerId' => $ledgerId];
            }
        }

        // Freezes posted_packed_qty = packed_qty for every row this batch
        // has — AFTER the ledger postings above, so store_fg_balance's own
        // "ready" reads (DoRepository::sumPackedForStore) start counting
        // this batch's current numbers as real/on-the-shelf from this
        // point forward, regardless of whether a LATER reopen puts this
        // batch back into an in-progress editing state (see
        // FgRepository::markAllItemsPosted()'s own docblock).
        $this->repo->markAllItemsPosted($this->pdo, $batchId);

        $sourceWarning = $this->buildSourceInconsistency($batchId);

        $bumped = $this->repo->bumpVersion(
            $this->pdo, $batchId, $expectedVersion,
            "status = 'submitted', submitted_by = ?, submitted_at = UTC_TIMESTAMP()", [$userId]
        );
        $this->assertVersionBumpSucceeded($batchId, $expectedVersion, $bumped);

        \Amor\Api\Audit::write(
            $this->pdo, $requestId, $userId, 'fg.submit', 'fg_batch', (string) $batchId,
            'ok', $expectedVersion, $expectedVersion + 1, ['postings' => $postings, 'sourceInconsistency' => $sourceWarning]
        );
        if ($sourceWarning['inconsistent']) {
            \Amor\Api\Audit::write($this->pdo, $requestId, $userId, 'fg.source_inconsistency', 'fg_batch', (string) $batchId, 'ok', null, null, $sourceWarning);
        }

        $batch = $this->repo->findBatchById($this->pdo, $batchId);
        $dto = $this->buildBatchDto($batch, $factory);
        $dto['submitPostings'] = $postings;
        return $dto;
    }

    /**
     * POST /api/fg/{id}/reopen — submitted -> reopened only. Never touches
     * stock_ledger (task: "reopen itself does not change stock" — only a
     * later submit's delta can). submitted_at/submitted_by are PRESERVED.
     */
    public function reopen(int $batchId, int $expectedVersion, string $reason, int $userId, ?string $requestId): array
    {
        if (trim($reason) === '') {
            throw new ApiException(400, 'REASON_REQUIRED', 'A reopen reason is required');
        }
        $batch = $this->repo->lockBatchById($this->pdo, $batchId);
        if ($batch === null) {
            throw new ApiException(404, 'NOT_FOUND', 'FG document not found');
        }
        if ($batch['status'] !== 'submitted') {
            throw new ApiException(409, 'INVALID_STATUS', "Only a submitted document can be reopened (current status: {$batch['status']})");
        }
        $factory = $this->requireFactory((int) $batch['factory_id']);

        $bumped = $this->repo->bumpVersion(
            $this->pdo, $batchId, $expectedVersion,
            "status = 'reopened', reopened_by = ?, reopened_at = UTC_TIMESTAMP(), reopen_reason = ?", [$userId, $reason]
        );
        $this->assertVersionBumpSucceeded($batchId, $expectedVersion, $bumped);

        \Amor\Api\Audit::write(
            $this->pdo, $requestId, $userId, 'fg.reopen', 'fg_batch', (string) $batchId,
            'ok', $expectedVersion, $expectedVersion + 1, ['reason' => $reason]
        );

        $batch = $this->repo->findBatchById($this->pdo, $batchId);
        return $this->buildBatchDto($batch, $factory);
    }

    /**
     * GET /api/fg/store-breakdown — "Breakdown Toko" (FG Mode B), read-only.
     * See FgTargetService::storeBreakdownForProduct()'s own docblock for
     * why this never writes anything and can never double-count against
     * the single Per Produk fg_item row.
     */
    public function storeBreakdown(string $tanggal, int $factoryId, int $productId): array
    {
        $factory = $this->requireFactory($factoryId);
        // HOTFIX (live UAT 2026-09-26 Karangtengah) — "Only stores with
        // target > 0" (no batch exists yet at this preview stage, so there
        // is no "already-entered" row to ever preserve here — see
        // batchProductStores() for that exception once a batch exists).
        $rows = array_values(array_filter(
            $this->targets->storeBreakdownForProduct($this->pdo, $tanggal, $factoryId, $productId),
            static fn ($r) => $r['target'] > 0.0001
        ));
        return [
            'tanggal' => $tanggal,
            'factoryId' => $factoryId,
            'factoryName' => $factory['name'],
            'productId' => $productId,
            'stores' => $rows,
            'totalTarget' => array_sum(array_column($rows, 'target')),
        ];
    }

    /**
     * GET /api/fg/{id}/items/{productId}/stores — the WRITABLE Breakdown
     * Toko table's data source for an existing FG batch. Unlike
     * storeBreakdown() above (a target-only preview, independent of any
     * batch — used before a batch exists, or just for reference), this
     * merges the same live PO target with whatever has ACTUALLY been
     * entered so far for this product in THIS batch: 0s across every
     * store (fgItemId null, exploded=false) before the product is ever
     * exploded, and each store row's own persisted fgVerified/packed/
     * reject/hilang/notes/status once it has been. Never writes anything.
     */
    public function batchProductStores(int $batchId, int $productId): array
    {
        $batch = $this->repo->findBatchById($this->pdo, $batchId);
        if ($batch === null) {
            throw new ApiException(404, 'NOT_FOUND', 'FG document not found');
        }
        $this->requireFactory((int) $batch['factory_id']);

        $targetRows = $this->targets->storeBreakdownForProduct($this->pdo, (string) $batch['tanggal'], (int) $batch['factory_id'], $productId);
        $existingRaw = $this->repo->findItemRowsForProduct($this->pdo, $batchId, $productId);
        $byStore = [];
        foreach ($existingRaw as $r) {
            $byStore[(int) $r['store_id']] = $r;
        }
        $unallocatedStoreId = $this->repo->unallocatedStoreId($this->pdo);
        $exploded = false;
        foreach ($existingRaw as $r) {
            if ((int) $r['store_id'] !== $unallocatedStoreId) {
                $exploded = true;
                break;
            }
        }

        $factoryId = (int) $batch['factory_id'];
        // Migration 0015 — Packing's own real, persisted submission truth
        // (never inferred from packed_qty vs target, which cannot tell
        // "never submitted" apart from "submitted with a low/zero
        // number" — see FgService::submitStorePacking()'s own docblock).
        // Scoped to (batch, store), so the SAME value is attached to
        // every one of this product's store rows for a given store — the
        // client groups rows by store anyway (fg-packing.php's
        // groupByStore()) and reads this once per store group.
        $submissionsByStore = $this->repo->findPackingSubmissionsForBatch($this->pdo, $batchId);
        $rows = [];
        foreach ($targetRows as $t) {
            $existing = $byStore[$t['storeId']] ?? null;
            // HOTFIX (live UAT 2026-09-26 Karangtengah) — "Only stores with
            // target > 0" — but NEVER at the cost of hiding a store row
            // that already has real data (a PO revision can legitimately
            // drop a store's target to 0 after FG was already entered/
            // shipped against the OLD target; that case must stay visible
            // — flagged via needsReview below, never silently dropped from
            // the view — see this method's own docblock and Delivery\
            // ShipmentService's "PO revision must not silently reclaim
            // packed/shipped stock" rule, applied here to display too).
            //
            // MOBILE-FIRST REWORK fix: "$existing !== null" alone is NOT
            // enough to mean "has real data" — explodeToStores() creates a
            // real fg_item row for EVERY store storeBreakdownForProduct()
            // returns, including target=0 ones (it has no reason to filter
            // those out itself; nothing every writes to them afterward in
            // the normal flow) — so a freshly-exploded product would
            // wrongly un-hide its own target=0 stores again the moment
            // this ran, exactly the bug this hotfix was supposed to fix.
            // The exception now requires the existing row to actually
            // carry a nonzero qty/packed/reject/hilang — an explode-created
            // zero row is treated exactly like "no row at all".
            $existingHasRealData = $existing !== null && (
                (float) $existing['qty'] > 0.0001
                || (float) $existing['packed_qty'] > 0.0001
                || (float) $existing['reject_qty'] > 0.0001
                || (float) $existing['hilang_qty'] > 0.0001
            );
            if ($t['target'] <= 0.0001 && !$existingHasRealData) {
                continue;
            }
            $fgVerified = $existing !== null ? (float) $existing['qty'] : 0.0;
            // "Perlu Review Ulang" — same DERIVED, non-blocking, read-time
            // pattern already established for Production (see
            // produksi.php's own $needsReview): a PO revision can lower
            // this store's target after FG was already entered/shipped
            // against the OLD, higher target. We never silently reclaim
            // that already-packed/already-shipped stock (task's own
            // explicit rule) — this is purely a flag for a human to look
            // at, computed live from the SAME canonical sources the
            // shipment guard itself uses (never a second source of truth).
            $shippedForStore = $t['target'] > 0 || $fgVerified > 0
                ? $this->doRepo->sumShippedForStore($this->pdo, $t['storeId'], $productId, $factoryId)
                : 0.0;
            $needsReview = ($t['target'] < $shippedForStore - 0.0001) || ($t['target'] < $fgVerified - 0.0001);
            $submission = $submissionsByStore[$t['storeId']] ?? null;
            $rows[] = [
                'storeId' => $t['storeId'],
                'storeName' => $t['storeName'],
                'target' => $t['target'],
                'fgItemId' => $existing !== null ? (int) $existing['fg_item_id'] : null,
                'fgVerified' => $fgVerified,
                'packed' => $existing !== null ? (float) $existing['packed_qty'] : 0.0,
                'reject' => $existing !== null ? (float) $existing['reject_qty'] : 0.0,
                'hilang' => $existing !== null ? (float) $existing['hilang_qty'] : 0.0,
                'notes' => $existing !== null ? $existing['keterangan'] : null,
                'status' => $existing !== null ? $existing['status'] : 'belum_dicek',
                'shippedQty' => $shippedForStore,
                'needsReview' => $needsReview,
                // null = this store's Packing has NEVER been submitted at
                // all (not even once). 'submitted' = the real, persisted
                // event completed and nothing has changed since.
                // 'stale' = it WAS submitted, but a later edit changed
                // this store's own data — submittedAt/By are kept as
                // "last known submission" history even while stale.
                'packingSubmittedStatus' => $submission['status'] ?? null,
                'packingSubmittedAt' => $submission['submitted_at'] ?? null,
                'packingSubmittedBy' => $submission['submitted_by_name'] ?? null,
            ];
        }

        return [
            'fgBatchId' => $batchId,
            'productId' => $productId,
            'exploded' => $exploded,
            'stores' => $rows,
            'totalTarget' => array_sum(array_column($rows, 'target')),
            'totalVerified' => array_sum(array_column($rows, 'fgVerified')),
            'totalPacked' => array_sum(array_column($rows, 'packed')),
        ];
    }

    /**
     * GET /api/fg/availability — factory/product balance (never trapped to
     * one business date, one fg_batch, or one store — see
     * docs/mysql-schema-v1.md §5.1: `location` is deliberately its own
     * table precisely so stock can be scoped by physical location, here
     * one location per factory, migration 0005). Falls back to a live
     * SUM(stock_ledger) if no stock_balance row exists yet (nothing
     * posted), matching stock_balance's own documented "cache, not primary
     * truth" contract.
     */
    public function availability(int $factoryId, int $productId): array
    {
        $factory = $this->requireFactory($factoryId);
        $locationId = $this->repo->findOrCreateLocationForFactory($this->pdo, $factoryId, $factory['name']);
        $balance = $this->repo->findBalance($this->pdo, $productId, $locationId);
        $available = $balance !== null ? (float) $balance['qty_on_hand'] : $this->repo->sumLedger($this->pdo, $productId, $locationId);
        $last = $this->repo->findLastMovement($this->pdo, $productId, $locationId);

        return [
            'factoryId' => $factoryId,
            'factoryName' => $factory['name'],
            'productId' => $productId,
            'locationId' => $locationId,
            'available' => $available,
            'lastMovement' => $last !== null ? [
                'stockLedgerId' => (int) $last['stock_ledger_id'],
                'eventType' => $last['event_type'],
                'qtyDelta' => (float) $last['qty_delta'],
                'eventDate' => $last['event_date'],
                'createdAt' => $last['created_at'],
            ] : null,
        ];
    }

    /**
     * @param array<int,array{productId:int,divisionId:int,divisionName:string,version:int}> $runs
     * @return array<int,array{productId:int,productName:string,actual:float}>
     */
    private function productionActualForRuns(array $runs): array
    {
        if ($runs === []) {
            return [];
        }
        $runIds = array_column($runs, 'productionRunId');
        $placeholders = implode(',', array_fill(0, count($runIds), '?'));
        // Same "target > 0" data-layer filter as FgTargetService::
        // productionActualByProduct() — see that method's own docblock;
        // this is only the division-scoped PREVIEW re-aggregation (loadTarget's
        // optional $divisionId filter), but must agree with it exactly.
        $stmt = $this->pdo->prepare(
            "SELECT pi.product_id, p.name AS product_name, SUM(pi.aktual) AS actual
             FROM production_item pi
             INNER JOIN product p ON p.product_id = pi.product_id
             WHERE pi.production_run_id IN ({$placeholders})
             GROUP BY pi.product_id, p.name
             HAVING SUM(pi.aktual) > 0.0001
             ORDER BY p.name"
        );
        $stmt->execute($runIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $productId = (int) $r['product_id'];
            $out[$productId] = ['productId' => $productId, 'productName' => $r['product_name'], 'actual' => (float) $r['actual']];
        }
        return $out;
    }

    /**
     * Pulls the LATEST submitted Production state into this batch's source
     * bookkeeping: updates fg_batch_source.source_version for every
     * eligible run (unconditionally — this is pure attribution metadata,
     * never a reason to withhold an update), and refreshes every existing
     * fg_item's production_actual_snapshot to the CURRENT live actual.
     *
     * The snapshot refresh is ALWAYS applied, regardless of whether the
     * operator has already entered a real fgVerified value — fgVerified/
     * packed_qty are never read or written here at all. This was a real
     * bug until this fix: an earlier version only refreshed the snapshot
     * while fgVerified was still 0, which meant the snapshot silently
     * froze forever the moment any FG work began, even though
     * source_version kept updating — so the "Ketidaksesuaian Sumber"
     * warning could disappear while the number underneath stayed stale.
     * The correct safety net for "fgVerified now exceeds the refreshed
     * snapshot" lives in submit()'s own pre-flight check, not here — this
     * method's only job is to make the snapshot true.
     */
    private function refreshSource(int $batchId, string $tanggal, int $factoryId): void
    {
        $runs = $this->targets->eligibleProductionRuns($this->pdo, $tanggal, $factoryId);
        foreach ($runs as $run) {
            $this->repo->upsertBatchSource($this->pdo, $batchId, $run['productionRunId'], $run['version']);
        }

        $actuals = $this->targets->productionActualByProduct($this->pdo, $tanggal, $factoryId);
        $existingItems = $this->repo->findItems($this->pdo, $batchId);
        $unallocatedStoreId = $this->repo->unallocatedStoreId($this->pdo);
        foreach ($actuals as $productId => $a) {
            if (!isset($existingItems[$productId])) {
                $this->repo->insertItem($this->pdo, $batchId, $productId, $unallocatedStoreId, $a['actual']);
                continue;
            }
            $product = $existingItems[$productId];
            if ($product['mode'] === 'perProduk') {
                $this->repo->updateItemSnapshot($this->pdo, (int) $product['fg_item_id'], $a['actual']);
                continue;
            }
            // Breakdown Toko: the snapshot is a ceiling on the SUM across
            // this product's store rows, never a per-store allocation —
            // so the SAME refreshed value is written to every one of this
            // product's rows (never divided). New stores that appeared in
            // the live PO target since the last explode/refresh are synced
            // in as fresh zero rows; an existing store row is NEVER
            // removed even if it later drops out of target, matching the
            // "never retroactively invalidate operator work" rule already
            // governing production_actual_snapshot itself.
            foreach ($product['fg_item_ids'] as $fgItemId) {
                $this->repo->updateItemSnapshot($this->pdo, $fgItemId, $a['actual']);
            }
            $this->syncStoreRows($batchId, $productId, $tanggal, $factoryId, $a['actual']);
        }
    }

    /**
     * POST /api/fg/{id}/refresh-source — the explicit, standalone "Refresh
     * Produksi Terbaru" action for an EXISTING draft/reopened FG batch.
     * Unlike patchDraft's own $refreshSource flag (which requires
     * resubmitting the whole items form), this needs nothing but the
     * batch id + expectedVersion — exactly the gap the real cPanel UAT
     * report identified ("Muat Produksi Submitted" only re-displays an
     * existing batch, it never refreshes it).
     *
     * Writes ZERO stock_ledger rows (only submit() ever posts stock) and
     * never changes fgVerified/packed_qty — only fg_batch_source's
     * source_version and fg_item.production_actual_snapshot. If the
     * refresh reveals fgVerified > the new snapshot for any item, that
     * item is reported back as a blocking discrepancy: its fgVerified is
     * left exactly as stored (never auto-reduced), but submit() will
     * refuse to proceed until an admin lowers it.
     */
    public function refreshProductionSource(int $batchId, int $expectedVersion, int $userId, ?string $requestId): array
    {
        $batch = $this->repo->lockBatchById($this->pdo, $batchId);
        if ($batch === null) {
            throw new ApiException(404, 'NOT_FOUND', 'FG document not found');
        }
        $this->assertEditable($batch);
        $factory = $this->requireFactory((int) $batch['factory_id']);

        $before = $this->repo->findItems($this->pdo, $batchId);
        $this->refreshSource($batchId, (string) $batch['tanggal'], (int) $batch['factory_id']);
        $after = $this->repo->findItems($this->pdo, $batchId);

        $changedSnapshots = [];
        $blocking = [];
        foreach ($after as $productId => $item) {
            $oldSnapshot = isset($before[$productId]) ? (float) $before[$productId]['production_actual_snapshot'] : null;
            $newSnapshot = (float) $item['production_actual_snapshot'];
            if ($oldSnapshot === null || abs($oldSnapshot - $newSnapshot) > 0.0001) {
                $changedSnapshots[] = [
                    'productId' => $productId, 'productName' => $item['product_name'],
                    'previousSnapshot' => $oldSnapshot, 'newSnapshot' => $newSnapshot,
                ];
            }
            $fgVerified = (float) $item['qty'];
            if ($fgVerified - $newSnapshot > 0.0001) {
                $blocking[] = [
                    'productId' => $productId, 'productName' => $item['product_name'],
                    'fgVerified' => $fgVerified, 'newSnapshot' => $newSnapshot,
                ];
            }
        }

        $bumped = $this->repo->bumpVersion($this->pdo, $batchId, $expectedVersion, 'status = status', []);
        $this->assertVersionBumpSucceeded($batchId, $expectedVersion, $bumped);

        \Amor\Api\Audit::write(
            $this->pdo, $requestId, $userId, 'fg.source_refresh', 'fg_batch', (string) $batchId,
            'ok', $expectedVersion, $expectedVersion + 1,
            ['changedSnapshots' => $changedSnapshots, 'blockingDiscrepancies' => $blocking]
        );

        $batch = $this->repo->findBatchById($this->pdo, $batchId);
        $dto = $this->buildBatchDto($batch, $factory);
        $dto['refreshChangedSnapshots'] = $changedSnapshots;
        $dto['verifiedExceedsProductionBlocking'] = $blocking;
        return $dto;
    }

    /** @return array{inconsistent:bool,runs:array} */
    private function buildSourceInconsistency(int $batchId): array
    {
        $sources = $this->repo->findBatchSources($this->pdo, $batchId);
        $inconsistent = false;
        $details = [];
        foreach ($sources as $runId => $storedVersion) {
            $current = $this->targets->currentRunState($this->pdo, $runId);
            if ($current === null) {
                continue;
            }
            $mismatch = (int) $current['version'] !== $storedVersion || $current['status'] !== 'submitted';
            if ($mismatch) {
                $inconsistent = true;
            }
            $details[] = [
                'productionRunId' => $runId,
                'storedVersion' => $storedVersion,
                'currentVersion' => (int) $current['version'],
                'currentStatus' => $current['status'],
                'mismatch' => $mismatch,
            ];
        }
        return ['inconsistent' => $inconsistent, 'runs' => $details];
    }

    private function assertEditable(array $batch): void
    {
        if (!in_array($batch['status'], ['draft', 'reopened'], true)) {
            throw new ApiException(409, 'INVALID_STATUS', "Only a draft or reopened document can be edited (current status: {$batch['status']})");
        }
    }

    private function assertVersionBumpSucceeded(int $batchId, int $expectedVersion, bool $bumped): void
    {
        if (!$bumped) {
            $current = \Amor\Api\Versioning::currentVersion($this->pdo, 'fg_batch', 'fg_batch_id', $batchId);
            throw new ApiException(409, 'VERSION_CONFLICT', 'The document was modified by someone else', ['currentVersion' => $current]);
        }
    }

    private function requireFactory(int $factoryId): array
    {
        $factory = $this->repo->findFactory($this->pdo, $factoryId);
        if ($factory === null) {
            throw new ApiException(404, 'FACTORY_NOT_FOUND', 'Factory not found');
        }
        \Amor\Api\Auth::requireFactoryAccess($factoryId);
        return $factory;
    }

    private function buildBatchDto(array $batch, array $factoryish, bool $includeItems = true): array
    {
        $batchId = (int) $batch['fg_batch_id'];
        $factoryId = (int) $batch['factory_id'];
        $factoryName = $factoryish['factory_name'] ?? $factoryish['name'] ?? null;
        $locationId = $this->repo->findOrCreateLocationForFactory($this->pdo, $factoryId, (string) $factoryName);

        $items = [];
        $totalActual = 0.0;
        $totalVerified = 0.0;
        $totalPacked = 0.0;
        $statusCounts = ['belum_diverifikasi' => 0, 'sebagian_terverifikasi' => 0, 'sesuai_produksi' => 0, 'selisih' => 0, 'melebihi_produksi' => 0];

        $rawItems = $this->repo->findItems($this->pdo, $batchId);
        foreach ($rawItems as $productId => $item) {
            // HOTFIX (live UAT 2026-09-26 Karangtengah) — "FG must show
            // ONLY products with target > 0" applied as a display-layer
            // safety net too, not just at the source query (see
            // FgTargetService::productionActualByProduct()'s own
            // docblock): a batch created/refreshed BEFORE this hotfix may
            // already have a zero-target fg_item row persisted (migration
            // 0014 is live on cPanel — no backfill/cleanup migration ships
            // with this hotfix), so this skip is what makes an
            // ALREADY-EXISTING live batch correct immediately, without a
            // schema change. Never hides a row that has real work entered
            // against it (fgVerified/packed/reject/hilang > 0), even if a
            // later Production reopen dropped its snapshot back to 0 — only
            // a row nobody has ever touched is skipped.
            if (!self::isVisibleItem($item)) {
                continue;
            }
            $dto = $this->buildItemDto($item, $batch['status'], $locationId);
            $totalActual += $dto['productionActualSnapshot'];
            $totalVerified += $dto['fgVerified'];
            $totalPacked += $dto['packed'];
            $statusCounts[$dto['fgStatusCode']]++;
            if ($includeItems) {
                $items[] = $dto;
            }
        }

        $sourceInconsistency = $this->buildSourceInconsistency($batchId);

        $dto = [
            'fgBatchId' => $batchId,
            'tanggal' => $batch['tanggal'],
            'factoryId' => $factoryId,
            'factoryName' => $factoryName,
            'status' => $batch['status'],
            'version' => (int) $batch['version'],
            'createdBy' => $batch['created_by'] !== null ? (int) $batch['created_by'] : null,
            'submittedBy' => $batch['submitted_by'] !== null ? (int) $batch['submitted_by'] : null,
            'submittedAt' => $batch['submitted_at'],
            'reopenedBy' => $batch['reopened_by'] !== null ? (int) $batch['reopened_by'] : null,
            'reopenedAt' => $batch['reopened_at'],
            'reopenReason' => $batch['reopen_reason'],
            'sourceInconsistency' => $sourceInconsistency['inconsistent'],
            'sourceInconsistencyDetails' => $sourceInconsistency['runs'],
            'summary' => [
                'productionActualTotal' => $totalActual,
                'fgVerifiedTotal' => $totalVerified,
                'packedTotal' => $totalPacked,
                'varianceTotal' => $totalActual - $totalVerified,
                'productCount' => count($rawItems),
                'jumlahBelumDiverifikasi' => $statusCounts['belum_diverifikasi'],
                'jumlahSebagianTerverifikasi' => $statusCounts['sebagian_terverifikasi'],
                'jumlahSesuaiProduksi' => $statusCounts['sesuai_produksi'],
                'jumlahSelisih' => $statusCounts['selisih'],
                'jumlahMelebihiProduksi' => $statusCounts['melebihi_produksi'],
            ],
        ];
        if ($includeItems) {
            $dto['items'] = $items;
        }
        return $dto;
    }

    private function buildItemDto(array $item, string $batchStatus, int $locationId): array
    {
        $productId = (int) $item['product_id'];
        $snapshot = (float) $item['production_actual_snapshot'];
        $fgVerified = (float) $item['qty'];
        $packed = (float) $item['packed_qty'];
        $reject = (float) ($item['reject_qty'] ?? 0);
        $hilang = (float) ($item['hilang_qty'] ?? 0);
        // variance_fg = production_actual - fg_verified (agreed Phase 4
        // definition): positive means "this much Production is not yet
        // verified into FG". FG_EXCEEDS_PRODUCTION already blocks
        // fgVerified > snapshot in normal operator flow, so this should
        // never go negative outside that blocked case.
        $variance = $snapshot - $fgVerified;

        $balance = $this->repo->findBalance($this->pdo, $productId, $locationId);
        $available = $balance !== null ? (float) $balance['qty_on_hand'] : $this->repo->sumLedger($this->pdo, $productId, $locationId);

        $fgStatus = self::classifyFgStatus($fgVerified, $snapshot, $batchStatus);
        $packingStatus = self::classifyPackingStatus($packed, $fgVerified);

        return [
            'productId' => $productId,
            'productName' => $item['product_name'],
            'productionActualSnapshot' => $snapshot,
            'fgVerified' => $fgVerified,
            'variance' => $variance,
            'packed' => $packed,
            'reject' => $reject,
            'hilang' => $hilang,
            'available' => $available,
            'notes' => $item['keterangan'],
            'fgStatusCode' => $fgStatus['code'],
            'fgStatusLabel' => $fgStatus['label'],
            'packingStatusCode' => $packingStatus['code'],
            'packingStatusLabel' => $packingStatus['label'],
            // 'mode'/'storeCount' expose FgRepository::findItems()'s own
            // aggregation state — 'breakdownToko' the moment ANY row for
            // this product belongs to a real store, never a separately
            // persisted flag (see that method's own docblock).
            'mode' => $item['mode'] ?? 'perProduk',
            'storeCount' => $item['row_count'] ?? 1,
        ];
    }

    /**
     * Presentation-only FG classification (task's exact 5 labels).
     * "Sebagian Terverifikasi" vs "Selisih" is the one judgment call this
     * service makes: both describe 0 < fgVerified < snapshot, but
     * "Sebagian" (still being worked) applies while the batch is
     * draft/reopened, and "Selisih" (a settled variance) applies once the
     * batch is submitted — documented in README/report, not silently
     * assumed.
     * @return array{code:string,label:string}
     */
    private static function classifyFgStatus(float $fgVerified, float $snapshot, string $batchStatus): array
    {
        $eps = 0.0001;
        if ($fgVerified <= $eps) {
            return ['code' => 'belum_diverifikasi', 'label' => 'Belum Diverifikasi'];
        }
        if ($fgVerified - $snapshot > $eps) {
            return ['code' => 'melebihi_produksi', 'label' => 'Melebihi Produksi'];
        }
        if (abs($fgVerified - $snapshot) <= $eps) {
            return ['code' => 'sesuai_produksi', 'label' => 'Sesuai Produksi'];
        }
        return $batchStatus === 'submitted'
            ? ['code' => 'selisih', 'label' => 'Selisih']
            : ['code' => 'sebagian_terverifikasi', 'label' => 'Sebagian Terverifikasi'];
    }

    /**
     * Presentation-only Packing classification (task's exact 4 labels).
     * "Packing Melebihi FG" is defensive only — PATCH validation already
     * rejects packed > fgVerified on every save, so this state should be
     * unreachable in normal operation.
     * @return array{code:string,label:string}
     */
    private static function classifyPackingStatus(float $packed, float $fgVerified): array
    {
        $eps = 0.0001;
        if ($packed <= $eps) {
            return ['code' => 'belum_dipacking', 'label' => 'Belum Dipacking'];
        }
        if ($packed - $fgVerified > $eps) {
            return ['code' => 'packing_melebihi_fg', 'label' => 'Packing Melebihi FG'];
        }
        if (abs($packed - $fgVerified) <= $eps) {
            return ['code' => 'selesai_dipacking', 'label' => 'Selesai Dipacking'];
        }
        return ['code' => 'sebagian_dipacking', 'label' => 'Sebagian Dipacking'];
    }

    /**
     * HOTFIX (live UAT 2026-09-26 Karangtengah) — "FG must show ONLY
     * products with target > 0", applied uniformly wherever a fg_item
     * AGGREGATE (FgRepository::findItems()'s per-product shape — qty/
     * packed_qty/reject_qty/hilang_qty are the SUM across every row) is
     * about to be shown. A row is visible the moment there is SOMETHING to
     * show for it: a positive production_actual_snapshot (the common
     * case), or real already-entered data (fgVerified/packed/reject/
     * hilang) — the latter guards against ever hiding genuine operator
     * work purely because a LATER Production reopen happened to drop the
     * snapshot back to 0.
     */
    private static function isVisibleItem(array $item): bool
    {
        $eps = 0.0001;
        return (float) $item['production_actual_snapshot'] > $eps
            || (float) $item['qty'] > $eps
            || (float) $item['packed_qty'] > $eps
            || (float) $item['reject_qty'] > $eps
            || (float) $item['hilang_qty'] > $eps;
    }
}
