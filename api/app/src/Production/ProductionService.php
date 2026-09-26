<?php

declare(strict_types=1);

namespace Amor\Api\Production;

use Amor\Api\ApiException;
use PDO;

/**
 * Business orchestration for the Phase 3 production/SPK actual document —
 * the counterpart to Import\PoImporter, but for the production_run/
 * production_item lifecycle instead of PO upload.
 *
 * Core rules enforced here (task sections 1-17):
 *   - production actual NEVER mutates PO target (ProductionTargetService is
 *     read-only; this class never writes po_batch/po_item/po_store_item).
 *   - identity = (tanggal, factory_id via division, product_id) — numeric
 *     IDs only, never raw names.
 *   - remaining/overproduction are ALWAYS computed from the LIVE current PO
 *     target, never from the frozen production_item.target snapshot — see
 *     buildItemDto(). The snapshot exists only to detect and warn about
 *     drift ("Target berubah sejak draft dibuat"), never to compute
 *     remaining itself.
 *   - actual_qty is snapshot semantics: every write REPLACES the stored
 *     value, never adds to it (draft 40 -> edit to 45 -> stored 45, not 85).
 *   - Finishgood & Packing (division.is_verification = 1) is out of Phase 3
 *     scope entirely — audited against the legacy frontend's isFG branch
 *     (amorcakes-manufacturing-v5-slate(2).html: "Cek Kesesuaian Barang
 *     Diterima" instead of "Ceklis Produksi Harian"), which is a receiving-
 *     verification workflow, not production actual entry. Using the
 *     existing is_verification column (set correctly for both factories'
 *     FG&Packing divisions by Phase 1's Phase1Importer) rather than
 *     matching the division name string.
 */
final class ProductionService
{
    private ProductionRepository $repo;
    private ProductionTargetService $targets;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new ProductionRepository();
        $this->targets = new ProductionTargetService();
    }

    /** GET /api/production/target — live PO-derived target, no document created. */
    public function loadTarget(string $tanggal, int $divisionId): array
    {
        $division = $this->requireProductionScopedDivision($divisionId);
        $factoryId = (int) $division['factory_id'];
        $items = $this->targets->targetsByProduct($this->pdo, $tanggal, $factoryId, $divisionId);
        $poBatchVersion = $this->targets->currentPoBatchVersion($this->pdo, $tanggal, $factoryId);

        return [
            'tanggal' => $tanggal,
            'divisionId' => $divisionId,
            'divisionName' => $division['name'],
            'factoryId' => $factoryId,
            'factoryName' => $division['factory_name'],
            'poBatchVersion' => $poBatchVersion,
            'items' => array_values($items),
            'summary' => [
                'targetProduksi' => array_sum(array_column($items, 'target')),
                'productCount' => count($items),
            ],
        ];
    }

    /**
     * POST /api/production — creates today's draft for (tanggal,divisionId)
     * if one doesn't already exist yet, snapshotting the CURRENT live PO
     * target for every product in that division that has PO demand. If a
     * run already exists (any status), returns it unchanged — creation is
     * idempotent at the (tanggal,divisionId) identity level in addition to
     * whatever Idempotency-Key replay the caller also uses.
     */
    public function createDraft(string $tanggal, int $divisionId, int $userId): array
    {
        $division = $this->requireProductionScopedDivision($divisionId);
        $factoryId = (int) $division['factory_id'];

        $existing = $this->repo->lockExistingRun($this->pdo, $tanggal, $divisionId);
        if ($existing !== null) {
            return $this->buildRunDto($existing, $division);
        }

        $poBatchVersion = $this->targets->currentPoBatchVersion($this->pdo, $tanggal, $factoryId);
        $run = $this->repo->createRun($this->pdo, $tanggal, $divisionId, $userId, $poBatchVersion);

        $liveTargets = $this->targets->targetsByProduct($this->pdo, $tanggal, $factoryId, $divisionId);
        foreach ($liveTargets as $productId => $t) {
            $this->repo->insertItem($this->pdo, (int) $run['production_run_id'], $productId, $t['target']);
        }

        $run = $this->repo->lockExistingRun($this->pdo, $tanggal, $divisionId);
        return $this->buildRunDto($run, $division);
    }

    public function getRun(int $runId): array
    {
        $run = $this->repo->findRunById($this->pdo, $runId);
        if ($run === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Production document not found');
        }
        return $this->buildRunDto($run, $run);
    }

    /** @return array<int,array> */
    public function listRuns(?string $tanggal, ?int $factoryId, ?int $divisionId, ?string $status): array
    {
        $runs = $this->repo->findRuns($this->pdo, $tanggal, $factoryId, $divisionId, $status);
        return array_map(fn ($run) => $this->buildRunDto($run, $run, includeItems: false), $runs);
    }

    /**
     * PATCH /api/production/{id} — draft/reopened only. $items is a list of
     * {productId, actualQty, notes?}; every write REPLACES that product's
     * stored actual_qty (never additive — see class docblock). $refreshTargets
     * pulls newly-appearing PO products into the run and refreshes every
     * existing item's target SNAPSHOT to the current live PO value; it never
     * touches actual_qty — an explicit, separate action from editing actuals,
     * so a target refresh can never silently discard operator input.
     */
    public function patchDraft(int $runId, int $expectedVersion, array $items, bool $refreshTargets, int $userId, ?string $requestId): array
    {
        $run = $this->repo->lockRunById($this->pdo, $runId);
        if ($run === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Production document not found');
        }
        $this->assertEditable($run);
        $division = $this->requireProductionScopedDivision((int) $run['division_id']);
        $factoryId = (int) $division['factory_id'];

        $sourcePoBatchVersion = (int) $run['source_po_batch_version'];
        if ($refreshTargets) {
            $sourcePoBatchVersion = $this->refreshItemTargets($runId, (string) $run['tanggal'], $factoryId, (int) $run['division_id']);
        }

        $existingItems = $this->repo->findItems($this->pdo, $runId);
        $touched = 0;
        foreach ($items as $line) {
            $productId = (int) ($line['productId'] ?? 0);
            if ($productId <= 0 || !isset($existingItems[$productId])) {
                throw new ApiException(400, 'UNKNOWN_PRODUCT_FOR_RUN', "Product {$productId} is not part of this production document — use refreshTargets to pull in newly-appearing PO products first");
            }
            $product = $this->repo->findProduct($this->pdo, $productId);
            if ($product === null || (int) ($product['division_id'] ?? 0) !== (int) $run['division_id']) {
                throw new ApiException(400, 'PRODUCT_DIVISION_MISMATCH', "Product {$productId} does not belong to this document's division — cross-factory/division production pollution is not allowed");
            }
            $actualQty = (float) ($line['actualQty'] ?? 0);
            if ($actualQty < 0) {
                throw new ApiException(400, 'INVALID_ACTUAL_QTY', 'actualQty cannot be negative');
            }
            $item = $existingItems[$productId];
            // rejectQty is OPTIONAL per line — if a caller doesn't send it
            // (e.g. an older cached client), the existing stored reject
            // value is kept as-is rather than silently reset to 0. This is
            // deliberately different from actualQty's own "always required"
            // handling: reject only just gained a write path with this
            // task, so nothing may ever assume every caller already sends it.
            $rejectQty = isset($line['rejectQty']) ? (float) $line['rejectQty'] : (float) $item['reject'];
            if ($rejectQty < 0) {
                throw new ApiException(400, 'INVALID_REJECT_QTY', 'rejectQty cannot be negative');
            }
            $notes = isset($line['notes']) ? (string) $line['notes'] : null;
            $this->repo->updateItemActual($this->pdo, (int) $item['production_item_id'], (float) $item['target'], $actualQty, $rejectQty, $notes);
            $touched++;
        }

        $bumped = $this->repo->bumpVersion(
            $this->pdo, $runId, $expectedVersion,
            'source_po_batch_version = ?', [$sourcePoBatchVersion]
        );
        $this->assertVersionBumpSucceeded($runId, $expectedVersion, $bumped);

        \Amor\Api\Audit::write(
            $this->pdo, $requestId, $userId, 'production.draft.edit', 'production_run', (string) $runId,
            'ok', $expectedVersion, $expectedVersion + 1,
            ['itemsTouched' => $touched, 'refreshTargets' => $refreshTargets]
        );

        $run = $this->repo->findRunById($this->pdo, $runId);
        return $this->buildRunDto($run, $run);
    }

    /**
     * POST /api/production/{id}/submit — draft or reopened -> submitted.
     * Target-changed-since-draft and overproduction are WARNINGS ONLY —
     * submission proceeds either way (task section 15/2), but both are
     * recorded permanently in audit_log so the drift is never silently lost
     * even though production_item.target itself stays frozen at whatever it
     * was during the draft (a true point-in-time record of what the
     * operator was working against).
     */
    public function submit(int $runId, int $expectedVersion, int $userId, ?string $requestId): array
    {
        $run = $this->repo->lockRunById($this->pdo, $runId);
        if ($run === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Production document not found');
        }
        $this->assertEditable($run);
        $division = $this->requireProductionScopedDivision((int) $run['division_id']);
        $factoryId = (int) $division['factory_id'];

        $items = $this->repo->findItems($this->pdo, $runId);
        if ($items === []) {
            throw new ApiException(400, 'EMPTY_PRODUCTION', 'This document has no product lines yet — nothing to submit');
        }

        $liveTargets = $this->targets->targetsByProduct($this->pdo, (string) $run['tanggal'], $factoryId, (int) $run['division_id']);
        $warnings = ['targetChanged' => [], 'overproduction' => []];
        foreach ($items as $productId => $item) {
            $live = $liveTargets[$productId]['target'] ?? 0.0;
            $snapshot = (float) $item['target'];
            $aktual = (float) $item['aktual'];
            if (abs($live - $snapshot) > 0.0001) {
                $warnings['targetChanged'][] = ['productId' => $productId, 'snapshotTarget' => $snapshot, 'liveTarget' => $live];
            }
            if ($aktual > $live) {
                $warnings['overproduction'][] = ['productId' => $productId, 'target' => $live, 'actual' => $aktual, 'over' => $aktual - $live];
            }
        }

        $bumped = $this->repo->bumpVersion(
            $this->pdo, $runId, $expectedVersion,
            "status = 'submitted', submitted_by = ?, submitted_at = UTC_TIMESTAMP()", [$userId]
        );
        $this->assertVersionBumpSucceeded($runId, $expectedVersion, $bumped);

        \Amor\Api\Audit::write(
            $this->pdo, $requestId, $userId, 'production.submit', 'production_run', (string) $runId,
            'ok', $expectedVersion, $expectedVersion + 1, ['warnings' => $warnings]
        );
        if ($warnings['targetChanged'] !== []) {
            \Amor\Api\Audit::write($this->pdo, $requestId, $userId, 'production.target_changed_from_po_revision', 'production_run', (string) $runId, 'ok', null, null, $warnings['targetChanged']);
        }
        if ($warnings['overproduction'] !== []) {
            \Amor\Api\Audit::write($this->pdo, $requestId, $userId, 'production.overproduction', 'production_run', (string) $runId, 'ok', null, null, $warnings['overproduction']);
        }

        $run = $this->repo->findRunById($this->pdo, $runId);
        $dto = $this->buildRunDto($run, $run);
        $dto['submitWarnings'] = $warnings;
        return $dto;
    }

    /**
     * POST /api/production/{id}/reopen — submitted -> reopened only.
     * Caller (controller) must already have enforced the restricted role.
     * submitted_at/submitted_by are PRESERVED as historical audit trail
     * (mirrors the legacy markCeklisReopened_ pattern) — Status is the only
     * field a consumer should read to know current state.
     */
    public function reopen(int $runId, int $expectedVersion, string $reason, int $userId, ?string $requestId): array
    {
        if (trim($reason) === '') {
            throw new ApiException(400, 'REASON_REQUIRED', 'A reopen reason is required');
        }
        $run = $this->repo->lockRunById($this->pdo, $runId);
        if ($run === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Production document not found');
        }
        if ($run['status'] !== 'submitted') {
            throw new ApiException(409, 'INVALID_STATUS', "Only a submitted document can be reopened (current status: {$run['status']})");
        }

        $bumped = $this->repo->bumpVersion(
            $this->pdo, $runId, $expectedVersion,
            "status = 'reopened', reopened_by = ?, reopened_at = UTC_TIMESTAMP(), reopen_reason = ?", [$userId, $reason]
        );
        $this->assertVersionBumpSucceeded($runId, $expectedVersion, $bumped);

        \Amor\Api\Audit::write(
            $this->pdo, $requestId, $userId, 'production.reopen', 'production_run', (string) $runId,
            'ok', $expectedVersion, $expectedVersion + 1, ['reason' => $reason]
        );

        $run = $this->repo->findRunById($this->pdo, $runId);
        return $this->buildRunDto($run, $run);
    }

    private function refreshItemTargets(int $runId, string $tanggal, int $factoryId, int $divisionId): int
    {
        $liveTargets = $this->targets->targetsByProduct($this->pdo, $tanggal, $factoryId, $divisionId);
        $existingItems = $this->repo->findItems($this->pdo, $runId);
        foreach ($liveTargets as $productId => $t) {
            if (isset($existingItems[$productId])) {
                $this->repo->updateItemTarget($this->pdo, (int) $existingItems[$productId]['production_item_id'], $t['target']);
            } else {
                $this->repo->insertItem($this->pdo, $runId, $productId, $t['target']);
            }
        }
        return (int) $this->targets->currentPoBatchVersion($this->pdo, $tanggal, $factoryId);
    }

    private function assertEditable(array $run): void
    {
        if (!in_array($run['status'], ['draft', 'reopened'], true)) {
            throw new ApiException(409, 'INVALID_STATUS', "Only a draft or reopened document can be edited (current status: {$run['status']})");
        }
    }

    /**
     * $bumped is bumpVersion()'s own rowCount()>0 result — the only reliable
     * signal that expectedVersion actually matched. Re-deriving success from
     * "is currentVersion now exactly expectedVersion+1" is WRONG and was a
     * real bug here: when a caller's expectedVersion is stale by exactly the
     * one bump some other request already applied, currentVersion equals
     * expectedVersion+1 by coincidence even though THIS caller's own UPDATE
     * matched zero rows — silently letting a stale write through instead of
     * rejecting it.
     */
    private function assertVersionBumpSucceeded(int $runId, int $expectedVersion, bool $bumped): void
    {
        if (!$bumped) {
            $current = \Amor\Api\Versioning::currentVersion($this->pdo, 'production_run', 'production_run_id', $runId);
            throw new ApiException(409, 'VERSION_CONFLICT', 'The document was modified by someone else', ['currentVersion' => $current]);
        }
    }

    private function requireProductionScopedDivision(int $divisionId): array
    {
        $division = $this->repo->findDivision($this->pdo, $divisionId);
        if ($division === null) {
            throw new ApiException(404, 'DIVISION_NOT_FOUND', 'Division not found');
        }
        if ((int) $division['is_verification'] === 1) {
            throw new ApiException(400, 'DIVISION_OUT_OF_SCOPE', "'{$division['name']}' is a Finishgood & Packing verification division — not part of Phase 3 production actual (that belongs to the future FG/Packing module)");
        }
        return $division;
    }

    private function buildRunDto(array $run, array $divisionish, bool $includeItems = true): array
    {
        $runId = (int) $run['production_run_id'];
        $tanggal = (string) $run['tanggal'];
        $divisionId = (int) $run['division_id'];
        $factoryId = (int) ($divisionish['factory_id'] ?? 0);

        $currentPoBatchVersion = $this->targets->currentPoBatchVersion($this->pdo, $tanggal, $factoryId);
        $liveTargets = $this->targets->targetsByProduct($this->pdo, $tanggal, $factoryId, $divisionId);

        $items = [];
        $totalTarget = 0.0;
        $totalActual = 0.0;
        $totalReject = 0.0;
        $totalRemaining = 0.0;
        $totalOverproduction = 0.0;
        $anyTargetChanged = false;
        $displayStatusCounts = ['not_produced' => 0, 'below_target' => 0, 'on_target' => 0, 'overproduction' => 0];

        // Summary totals must reflect live targets regardless of $includeItems
        // (list views still show correct Target/Actual/Remaining sums), so the
        // loop always runs; only the per-item array is withheld for list views.
        foreach ($this->repo->findItems($this->pdo, $runId) as $productId => $item) {
            $dto = $this->buildItemDto($item, $liveTargets[$productId]['target'] ?? 0.0);
            if ($dto['targetChangedSinceDraft']) {
                $anyTargetChanged = true;
            }
            $totalTarget += $dto['liveTarget'];
            $totalActual += $dto['actual'];
            $totalReject += $dto['reject'];
            $totalRemaining += $dto['remaining'];
            $totalOverproduction += $dto['overproduction'];
            $displayStatusCounts[$dto['displayStatusCode']]++;
            if ($includeItems) {
                $items[] = $dto;
            }
        }

        $dto = [
            'productionRunId' => $runId,
            'tanggal' => $tanggal,
            'divisionId' => $divisionId,
            'divisionName' => $divisionish['division_name'] ?? $divisionish['name'] ?? null,
            'factoryId' => $factoryId,
            'factoryName' => $divisionish['factory_name'] ?? null,
            'status' => $run['status'],
            'version' => (int) $run['version'],
            'sourcePoBatchVersion' => $run['source_po_batch_version'] !== null ? (int) $run['source_po_batch_version'] : null,
            'currentPoBatchVersion' => $currentPoBatchVersion,
            'targetChangedSincePoRevision' => $anyTargetChanged || ($run['source_po_batch_version'] !== null && $currentPoBatchVersion !== null && (int) $run['source_po_batch_version'] !== $currentPoBatchVersion),
            'createdBy' => $run['created_by'] !== null ? (int) $run['created_by'] : null,
            'submittedBy' => $run['submitted_by'] !== null ? (int) $run['submitted_by'] : null,
            'submittedAt' => $run['submitted_at'],
            'reopenedBy' => $run['reopened_by'] !== null ? (int) $run['reopened_by'] : null,
            'reopenedAt' => $run['reopened_at'],
            'reopenReason' => $run['reopen_reason'],
            'summary' => [
                'targetProduksi' => $totalTarget,
                'actualProduksi' => $totalActual,
                'rejectProduksi' => $totalReject,
                'sisaProduksi' => $totalRemaining,
                'overproduction' => $totalOverproduction,
                'productCount' => count($liveTargets),
                // Optional, additive-only counts by the new display classification
                // (task: "Tambahkan summary jika mudah... Prioritas utama adalah
                // per-row status label"). Never used for any business decision.
                'jumlahBelumDiproduksi' => $displayStatusCounts['not_produced'],
                'jumlahBelumSesuaiTarget' => $displayStatusCounts['below_target'],
                'jumlahSesuaiTarget' => $displayStatusCounts['on_target'],
                'jumlahOverproduction' => $displayStatusCounts['overproduction'],
            ],
        ];
        if ($includeItems) {
            $dto['items'] = $items;
        }
        return $dto;
    }

    private function buildItemDto(array $item, float $liveTarget): array
    {
        $actual = (float) $item['aktual'];
        $reject = (float) $item['reject'];
        $snapshot = (float) $item['target'];
        $remaining = max(0.0, $liveTarget - $actual);
        $overproduction = max(0.0, $actual - $liveTarget);
        $displayStatus = self::classifyDisplayStatus($actual, $remaining, $overproduction);
        return [
            'productId' => (int) $item['product_id'],
            'productName' => $item['product_name'],
            'targetSnapshot' => $snapshot,
            'liveTarget' => $liveTarget,
            'actual' => $actual,
            'reject' => $reject,
            'remaining' => $remaining,
            'overproduction' => $overproduction,
            // Internal DB status (production_item.status, 'sesuai'/'tidak_sesuai')
            // — UNCHANGED, kept only for backward compatibility with anything
            // already reading it. displayStatusCode/displayStatusLabel below
            // are the presentation-layer classification a human should read;
            // see classifyDisplayStatus()'s own docblock.
            'status' => $item['status'],
            'displayStatusCode' => $displayStatus['code'],
            'displayStatusLabel' => $displayStatus['label'],
            'notes' => $item['keterangan'],
            'targetChangedSinceDraft' => abs($liveTarget - $snapshot) > 0.0001,
        ];
    }

    /**
     * Presentation-only classification of a product line's production
     * status — derived purely from the already-computed $remaining/
     * $overproduction (both already use the LIVE target per the class
     * docblock's core rule, never the frozen snapshot), so this can never
     * disagree with what the UI already shows for those two numbers. Does
     * NOT touch production_item.status (the DB enum 'sesuai'/'tidak_sesuai'
     * kept as-is for compatibility) — this is purely a friendlier label for
     * humans, computed fresh on every read, never stored.
     *
     * Rules (exact, in order — task's own spec):
     *   1. actual == 0                    -> Belum Diproduksi (not_produced)
     *   2. 0 < actual < live target        -> Belum Sesuai Target (below_target)
     *   3. actual == live target           -> Sesuai Target (on_target)
     *   4. actual > live target            -> Overproduction (overproduction)
     *
     * @return array{code:string,label:string}
     */
    public static function classifyDisplayStatus(float $actual, float $remaining, float $overproduction): array
    {
        $eps = 0.0001;
        if ($actual <= $eps) {
            return ['code' => 'not_produced', 'label' => 'Belum Diproduksi'];
        }
        if ($overproduction > $eps) {
            return ['code' => 'overproduction', 'label' => 'Overproduction'];
        }
        if ($remaining > $eps) {
            return ['code' => 'below_target', 'label' => 'Belum Sesuai Target'];
        }
        return ['code' => 'on_target', 'label' => 'Sesuai Target'];
    }
}
