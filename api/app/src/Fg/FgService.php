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
 *     reserves for that product+factory — preflighted for every
 *     negative-delta item, in deterministic product_id order, BEFORE any
 *     ledger row is written, so a blocked correction on one product line
 *     never leaves the rest of the batch partially posted. See
 *     Delivery\ShipmentService's own docblock for the shared canonical
 *     lock order (stock_balance row FIRST, then a locking read of the
 *     active reservation sum) every General-FG-decreasing writer in this
 *     codebase now follows.
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
     * PATCH /api/fg/{id} — draft/reopened only. $items is a list of
     * {productId, fgVerified, packed, notes?}; every write REPLACES that
     * product's stored qty/packed_qty (never additive). $refreshSource
     * pulls newly-submitted divisions' products into the batch; it never
     * touches an item that already has fgVerified>0 (see class docblock —
     * never retroactively invalidates operator work already entered).
     */
    public function patchDraft(int $batchId, int $expectedVersion, array $items, bool $refreshSource, int $userId, ?string $requestId): array
    {
        $batch = $this->repo->lockBatchById($this->pdo, $batchId);
        if ($batch === null) {
            throw new ApiException(404, 'NOT_FOUND', 'FG document not found');
        }
        $this->assertEditable($batch);
        $factory = $this->requireFactory((int) $batch['factory_id']);

        if ($refreshSource) {
            $this->refreshSource($batchId, (string) $batch['tanggal'], (int) $batch['factory_id']);
        }

        $existingItems = $this->repo->findItems($this->pdo, $batchId);
        $touched = 0;
        foreach ($items as $line) {
            $productId = (int) ($line['productId'] ?? 0);
            if ($productId <= 0 || !isset($existingItems[$productId])) {
                throw new ApiException(400, 'UNKNOWN_PRODUCT_FOR_BATCH', "Product {$productId} is not part of this FG document — use refreshSource to pull in newly-submitted production first");
            }
            $item = $existingItems[$productId];
            $snapshot = (float) $item['production_actual_snapshot'];
            $fgVerified = (float) ($line['fgVerified'] ?? 0);
            $packed = (float) ($line['packed'] ?? 0);
            if ($fgVerified < 0 || $packed < 0) {
                throw new ApiException(400, 'INVALID_QTY', 'fgVerified/packed cannot be negative');
            }
            if ($packed > $fgVerified) {
                throw new ApiException(400, 'PACKED_EXCEEDS_VERIFIED', "Product {$productId}: packed ({$packed}) cannot exceed FG verified ({$fgVerified})");
            }
            if ($fgVerified > $snapshot) {
                throw new ApiException(400, 'FG_EXCEEDS_PRODUCTION', "Product {$productId}: FG verified ({$fgVerified}) cannot exceed production actual ({$snapshot})");
            }
            $notes = isset($line['notes']) ? (string) $line['notes'] : null;
            $this->repo->updateItemValues($this->pdo, (int) $item['fg_item_id'], $fgVerified, $packed, $notes);
            $touched++;
        }

        $bumped = $this->repo->bumpVersion($this->pdo, $batchId, $expectedVersion, 'status = status', []);
        $this->assertVersionBumpSucceeded($batchId, $expectedVersion, $bumped);

        \Amor\Api\Audit::write(
            $this->pdo, $requestId, $userId, 'fg.draft.edit', 'fg_batch', (string) $batchId,
            'ok', $expectedVersion, $expectedVersion + 1, ['itemsTouched' => $touched, 'refreshSource' => $refreshSource]
        );

        $batch = $this->repo->findBatchById($this->pdo, $batchId);
        return $this->buildBatchDto($batch, $factory);
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
            $posted = $this->repo->postedQtyForItem($this->pdo, (int) $item['fg_item_id']);
            $deltas[$productId] = (float) $item['packed_qty'] - $posted;
        }

        // GLOBAL FG RESERVATION SAFETY (cross-flow deep-check fix): a
        // downward correction here is a General-FG-decreasing write, the
        // SAME class of write ShipmentService::ship() and
        // SpecialOrderFgAllocationService::consumeForDispatch() are
        // already gated on — physical stock may never drop below what a
        // special/non-regular order has ACTIVELY reserved. Preflighted
        // for EVERY negative-delta item, in deterministic (ascending
        // product_id) lock order, BEFORE any stock_ledger row is written
        // for this submit — so a blocked correction on one product can
        // never leave a partially-applied FG batch (some products posted,
        // others not). Positive/zero deltas never conflict with a
        // reservation (they only ever grow physical stock) and skip this
        // check entirely, per the canonical lock order every General-FG
        // writer in this codebase now shares: lock stock_balance row
        // FIRST, then locking-read the active reservation sum, then
        // validate, then write.
        $negativeProductIds = array_keys(array_filter($deltas, static fn ($d) => $d < -0.0001));
        sort($negativeProductIds);
        foreach ($negativeProductIds as $productId) {
            $delta = $deltas[$productId];
            $balanceRow = $this->doRepo->lockBalance($this->pdo, $productId, $locationId);
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
        $stmt = $this->pdo->prepare(
            "SELECT pi.product_id, p.name AS product_name, SUM(pi.aktual) AS actual
             FROM production_item pi
             INNER JOIN product p ON p.product_id = pi.product_id
             WHERE pi.production_run_id IN ({$placeholders})
             GROUP BY pi.product_id, p.name
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
        $storeId = $this->repo->unallocatedStoreId($this->pdo);
        foreach ($actuals as $productId => $a) {
            if (isset($existingItems[$productId])) {
                $this->repo->updateItemSnapshot($this->pdo, (int) $existingItems[$productId]['fg_item_id'], $a['actual']);
            } else {
                $this->repo->insertItem($this->pdo, $batchId, $productId, $storeId, $a['actual']);
            }
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
            'available' => $available,
            'notes' => $item['keterangan'],
            'fgStatusCode' => $fgStatus['code'],
            'fgStatusLabel' => $fgStatus['label'],
            'packingStatusCode' => $packingStatus['code'],
            'packingStatusLabel' => $packingStatus['label'],
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
}
