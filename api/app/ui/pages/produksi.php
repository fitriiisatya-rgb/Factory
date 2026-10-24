<?php

declare(strict_types=1);

use Amor\Api\Auth;
use Amor\Api\Production\ProductionService;
use Amor\Api\Production\ProductionTargetService;
use Amor\Api\SpecialOrder\NormalizedSourceType;
use Amor\Api\SpecialOrder\SpecialOrderRepository;

$divisionIdParam = isset($_GET['divisionId']) && $_GET['divisionId'] !== '' ? (int) $_GET['divisionId'] : null;
$statusParam = isset($_GET['status']) && $_GET['status'] !== '' ? (string) $_GET['status'] : null;
$runIdParam = isset($_GET['runId']) ? (int) $_GET['runId'] : null;

$divisions = $pdo->prepare('SELECT division_id, name FROM division WHERE factory_id = ? AND is_verification = 0 ORDER BY name');
$divisions->execute([$uiFactoryId]);
$divisionRows = $divisions->fetchAll();

// Division-scoped users (see Auth::requireDivisionAccess()'s own docblock —
// DEFAULT-DENY: a non-ADMIN/PPIC user with zero user_division_access rows
// has NO division access at all) only see the divisions they're assigned
// to in this picker — zero assignments means an EMPTY picker, never "all
// divisions" (that was the prior, since-corrected opt-in behavior). This
// is UI-level filtering, not a second access-control layer — the real
// enforcement is server-side in ProductionService's own
// requireProductionScopedDivision(), called on every read/write this page
// drives.
$isUnscopedRole = array_intersect(['ADMIN', 'PPIC'], Auth::currentRoles()) !== [];
$scopedDivisionIds = Auth::currentDivisionIds();
$noDivisionAssignment = false;
if (!$isUnscopedRole) {
    if ($scopedDivisionIds === []) {
        $noDivisionAssignment = true;
        $divisionRows = [];
    } else {
        $divisionRows = array_values(array_filter($divisionRows, static fn ($d) => in_array((int) $d['division_id'], $scopedDivisionIds, true)));
        // Task's own explicit UI rule: "If only one assigned division: you
        // may auto-select it" — only when the operator hasn't already
        // picked a division themselves via the query string.
        if ($divisionIdParam === null && count($divisionRows) === 1) {
            $divisionIdParam = (int) $divisionRows[0]['division_id'];
        }
    }
}

$service = new ProductionService($pdo);
$targetService = new ProductionTargetService();
$specialOrderRepo = new SpecialOrderRepository();

// ---------------------------------------------------------------------
// BUGFIX (real 2026-09-26 UAT): PO demand must be visible on this page
// BEFORE any production_run/production_item exists — a Production Draft
// is the execution/realisasi document, PO is the demand source, and the
// operator must never have to create a draft just to SEE the target.
// Target is therefore always the LIVE PO target (ProductionTargetService
// — po_awal + po_revisi, PB already excluded there), grouped by division;
// this is the SAME live-target formula ProductionService::buildRunDto()
// already uses for a single run's own detail view, applied here to the
// whole-factory overview so the two never disagree and no PO revision
// requires the page to be reopened to "unfreeze" — never a second target
// source, and production_item is read ONLY for actual/execution state.
// ---------------------------------------------------------------------
$liveProducts = $targetService->targetsByProduct($pdo, $uiTanggal, $uiFactoryId, $divisionIdParam);
$liveTargetByDivision = [];
$unroutedProducts = [];
foreach ($liveProducts as $p) {
    if ($p['divisionId'] === null) {
        // Store PO for a product with no division: no division will ever
        // see it, so the admin must be told (and fix it in Master Data).
        if ($p['target'] > 0.0001) {
            $unroutedProducts[] = ($p['productName'] ?? ('Produk #' . ($p['productId'] ?? '?'))) . ' (' . ui_fmt_num((float) $p['target']) . ')';
        }
        continue;
    }
    $liveTargetByDivision[$p['divisionId']] = ($liveTargetByDivision[$p['divisionId']] ?? 0.0) + $p['target'];
}

// Actual/status per division — execution state only (never a target
// source): at most one production_run per (tanggal, division_id) per the
// schema's own unique key, so this is a simple per-division lookup.
// submitted_by/updated_at are additive display-only columns for the admin
// division dashboard (Last Updated / Submitted By) — never used for any
// business decision.
$actualSql = "SELECT r.production_run_id, r.division_id, r.status, r.updated_at, r.submitted_at,
                     COALESCE(r.submitted_by_operator_name, u.full_name) AS submitted_by_name, COALESCE(SUM(pi.aktual), 0) AS actual_sum,
                     COALESCE(SUM(pi.reject), 0) AS reject_sum
              FROM production_run r
              INNER JOIN division d ON d.division_id = r.division_id
              LEFT JOIN production_item pi ON pi.production_run_id = r.production_run_id
              LEFT JOIN users u ON u.user_id = r.submitted_by
              WHERE r.tanggal = ? AND d.factory_id = ?";
$actualParams = [$uiTanggal, $uiFactoryId];
if ($divisionIdParam !== null) {
    $actualSql .= ' AND r.division_id = ?';
    $actualParams[] = $divisionIdParam;
}
if ($statusParam !== null) {
    $actualSql .= ' AND r.status = ?';
    $actualParams[] = $statusParam;
}
$actualSql .= ' GROUP BY r.production_run_id, r.division_id, r.status, r.updated_at, r.submitted_at, r.submitted_by_operator_name, u.full_name';
$actualStmt = $pdo->prepare($actualSql);
$actualStmt->execute($actualParams);
$runByDivision = [];
foreach ($actualStmt->fetchAll() as $row) {
    $runByDivision[(int) $row['division_id']] = [
        'runId' => (int) $row['production_run_id'],
        'status' => $row['status'],
        'actual' => (float) $row['actual_sum'],
        'reject' => (float) $row['reject_sum'],
        'updatedAt' => $row['updated_at'],
        'submittedAt' => $row['submitted_at'],
        'submittedByName' => $row['submitted_by_name'],
    ];
}

// Divisions actually shown in the summary table: every division for this
// factory (or just the one selected), EXCEPT — when a status filter is
// active — a division with no run at all never matches any explicit
// status choice (draft/submitted/reopened), so it is excluded exactly as
// before (a status filter's meaning is unchanged by this fix).
$displayDivisions = $divisionIdParam !== null
    ? array_values(array_filter($divisionRows, static fn ($d) => (int) $d['division_id'] === $divisionIdParam))
    : $divisionRows;
if ($statusParam !== null) {
    $displayDivisions = array_values(array_filter($displayDivisions, static fn ($d) => isset($runByDivision[(int) $d['division_id']])));
}

$kpi = ['target' => 0.0, 'actual' => 0.0, 'notProduced' => 0, 'belowTarget' => 0, 'onTarget' => 0, 'over' => 0];
$divisionSummaryRows = [];
foreach ($displayDivisions as $d) {
    $divId = (int) $d['division_id'];
    $target = $liveTargetByDivision[$divId] ?? 0.0;
    $run = $runByDivision[$divId] ?? null;
    $actual = $run['actual'] ?? 0.0;
    $remaining = max(0.0, $target - $actual);
    $over = max(0.0, $actual - $target);
    $displayStatus = ProductionService::classifyDisplayStatus($actual, $remaining, $over);

    $kpi['target'] += $target;
    $kpi['actual'] += $actual;
    // A division with ZERO live target (no PO demand at all today) is not
    // outstanding production work — it must never inflate "Belum
    // Diproduksi" (task's own explicit "Divisions with Target = 0 should
    // not be counted as outstanding production work", worked example:
    // "Basic: No Target" is excluded from "1/2 active divisions
    // submitted"). classifyDisplayStatus() has no "no demand" branch of its
    // own (actual<=0 always reads as not_produced), so that exclusion is
    // applied here, one level up, rather than inside the shared classifier
    // — Ceklis Produksi's own per-row/per-item status elsewhere in this
    // file is unaffected, this only skips the division-level KPI tally.
    if ($target > 0.0001) {
        $kpiKey = ['not_produced' => 'notProduced', 'below_target' => 'belowTarget', 'on_target' => 'onTarget', 'overproduction' => 'over'][$displayStatus['code']];
        $kpi[$kpiKey]++;
    }

    $divisionSummaryRows[] = [
        'divisionId' => $divId,
        'divisionName' => $d['name'],
        'target' => $target,
        'actual' => $actual,
        'reject' => $run['reject'] ?? 0.0,
        'remaining' => $remaining,
        'runId' => $run['runId'] ?? null,
        'statusLabel' => $run !== null ? ui_doc_status_label($run['status']) : 'Belum Dimulai',
        'hasTarget' => $target > 0.0001,
        'lastUpdated' => $run['updatedAt'] ?? $run['submittedAt'] ?? null,
        'submittedByName' => $run['submittedByName'] ?? null,
    ];
}
// "Belum Diproduksi" (the top KPI's not-produced count) only counts
// divisions that actually HAVE demand — a division with zero target is not
// outstanding work (task's own explicit "Divisions with Target = 0 should
// not be counted as outstanding production work").
$activeDivisionCount = count(array_filter($divisionSummaryRows, static fn ($r) => $r['hasTarget']));
$kpi['remaining'] = max(0.0, $kpi['target'] - $kpi['actual']);

// A shared division account (one division, not ADMIN/PPIC) gets the short
// version of this page: no factory/division/status filters, no KPI grid,
// no per-division table — just its own day and its own worksheet, which
// opens by itself when that day's document already exists.
$fieldSimple = !$isUnscopedRole && count($divisionRows) === 1;
$fieldRow = $fieldSimple ? ($divisionSummaryRows[0] ?? null) : null;
if ($fieldSimple && $runIdParam === null && $fieldRow !== null && $fieldRow['runId'] !== null) {
    $runIdParam = (int) $fieldRow['runId'];
}

// Pesanan Khusus / Non-Toko for this division on this date (by Tanggal
// Dibutuhkan). On a day with no store PO the worksheet never opens, so
// without this the division could not see these orders at all.
$fieldSpecialRows = [];
if ($fieldSimple && $fieldRow !== null && $fieldRow['runId'] === null) {
    // Same rows as Task per Divisi, minus store PO (shown above as Target):
    // Pesanan Khusus, Non-Toko and replacement-reject demand.
    $fieldTasks = (new \Amor\Api\Production\ProductionTaskService($pdo))->tasksForDivision($uiTanggal, (int) $fieldRow['divisionId'], null, null);
    $fieldSpecialRows = array_values(array_filter($fieldTasks['tasks'], static fn ($t) => $t['source'] !== \Amor\Api\Production\ProductionTaskService::SOURCE_PO_REGULER));
}

$runDetail = null;
$runDetailError = null;
$nonRegularRows = [];
if ($runIdParam !== null) {
    try {
        $runDetail = $service->getRun($runIdParam);
        // Non-Regular demand (Pesanan Khusus Toko / Pesanan Non-Toko) for
        // the SAME division+date, merged into this ONE editable table so
        // an operator works from a single worksheet (task's own "Business
        // Goal" — do NOT make the operator switch pages to see every
        // source of demand). Reuses SpecialOrderRepository::
        // findProductionDemandItems() — the SAME read ProductionTaskService
        // and produksi-task-per-divisi.php already use — and is written
        // back through the EXISTING POST /api/special-orders/{id}/actual
        // endpoint, never a new demand/actual store.
        $rawNonRegular = $specialOrderRepo->findProductionDemandItems($pdo, [
            'divisionId' => (int) $runDetail['divisionId'],
            'tanggal' => $uiTanggal,
        ]);
        foreach ($rawNonRegular as $r) {
            $srcType = $r['source_type'] === 'toko_khusus' ? 'pesanan_khusus' : 'pesanan_non_toko';
            $normalizedSourceType = NormalizedSourceType::fromSpecialOrder((string) $r['source_type'], $r['non_store_source'] ?? null);
            $sourceLabel = $srcType === 'pesanan_khusus' ? 'Pesanan Khusus' : NormalizedSourceType::label($normalizedSourceType);
            $who = $srcType === 'pesanan_khusus' ? ($r['store_name'] ?? '-') : ($r['customer_name'] ?? '-');
            $nonRegularRows[] = [
                'source' => $srcType,
                'sourceLabel' => $sourceLabel,
                'productName' => $r['item_name_snapshot'],
                'reference' => $r['order_no'] . ' — ' . $who,
                'target' => (float) $r['qty'],
                'aktual' => (float) $r['aktual_produksi'],
                'reject' => (float) $r['reject_produksi'],
                'catatan' => $r['special_note'],
                'operatorName' => $r['actual_updated_by_operator_name'] ?? null,
                'itemId' => (int) $r['special_order_item_id'],
                'orderId' => (int) $r['special_order_id'],
                'orderVersion' => (int) $r['order_version'],
            ];
        }
        // Target for special rows = what still has to be PRODUCED (order qty
        // minus FG already allocated to it), same as Task per Divisi.
        if ($nonRegularRows !== []) {
            $netByItem = [];
            foreach ((new \Amor\Api\Production\ProductionTaskService($pdo))->tasksForDivision($uiTanggal, (int) $runDetail['divisionId'], null, null)['tasks'] as $t) {
                if ($t['itemId'] !== null && in_array($t['source'], ['pesanan_khusus', 'pesanan_non_toko'], true)) {
                    $netByItem[(int) $t['itemId']] = (float) $t['target'];
                }
            }
            foreach ($nonRegularRows as $k => $r) {
                if (isset($netByItem[$r['itemId']])) {
                    $nonRegularRows[$k]['target'] = $netByItem[$r['itemId']];
                }
            }
        }
    } catch (\Throwable $e) {
        $runDetailError = $e->getMessage();
    }
}
?>
<?php if ($fieldSimple): ?>
<div class="card section day-card">
  <form method="get" class="day-card-date">
    <input type="hidden" name="page" value="produksi">
    <input type="hidden" name="factoryId" value="<?= $uiFactoryId ?>">
    <label for="day-date">Tanggal produksi</label>
    <input type="date" id="day-date" name="tanggal" value="<?= ui_esc($uiTanggal) ?>" onchange="this.form.submit()">
  </form>
  <div class="day-card-title"><?= ui_esc((string) $divisionRows[0]['name']) ?></div>
  <?php if ($fieldRow !== null): ?>
  <div class="day-card-stats">
    <div><span>Target</span><strong><?= ui_fmt_num($fieldRow['target']) ?></strong></div>
    <div><span>Hasil</span><strong><?= ui_fmt_num($fieldRow['actual']) ?></strong></div>
    <div><span>Reject</span><strong><?= ui_fmt_num($fieldRow['reject'] ?? 0) ?></strong></div>
    <div><span>Status</span><?= $fieldRow['hasTarget'] || $fieldRow['runId'] !== null ? ui_badge($fieldRow['statusLabel']) : ui_badge('Belum ada PO') ?></div>
  </div>
  <?php if ($fieldRow['runId'] === null): ?>
    <?php if (!$fieldRow['hasTarget']): ?>
    <p class="day-card-note">PO untuk tanggal ini <b>belum ada</b>. Produksi tetap bisa diisi — target muncul otomatis begitu PO diupload.</p>
    <?php endif; ?>
    <button type="button" class="btn btn-primary btn-block" data-action="create-run" data-division-id="<?= (int) $fieldRow['divisionId'] ?>">Mulai Isi Produksi</button>
    <?php if ($fieldSpecialRows !== []): ?>
    <div class="day-card-special" style="margin-top:var(--space-3);">
      <p class="day-card-note"><strong>Pesanan Khusus / Non-Toko / Pengganti Reject</strong> untuk tanggal ini (<?= count($fieldSpecialRows) ?> item):</p>
      <ul style="margin:var(--space-2) 0;padding-left:1.2em;">
        <?php foreach ($fieldSpecialRows as $sr): ?>
        <li><?= ui_esc((string) $sr['taskName']) ?> — <strong><?= ui_fmt_num((float) $sr['target']) ?></strong>
          <span style="color:var(--text-muted);">(<?= ui_esc((string) $sr['sourceLabel']) ?><?= $sr['reference'] ? ' · ' . ui_esc((string) $sr['reference']) : '' ?>)</span></li>
        <?php endforeach; ?>
      </ul>
      <a class="btn btn-<?= $fieldRow['hasTarget'] ? 'secondary' : 'primary' ?> btn-block" href="/api/_ui-preview/?page=produksi-task-per-divisi&amp;tanggal=<?= urlencode($uiTanggal) ?>&amp;factoryId=<?= $uiFactoryId ?>&amp;divisionId=<?= (int) $fieldRow['divisionId'] ?>">Isi Pesanan Khusus / Pengganti</a>
    </div>
    <?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php else: ?>
<?= ui_produksi_tabs('produksi', $uiTanggal, $uiFactoryId) ?>
<?php endif; ?>
<?php if (!$fieldSimple && $unroutedProducts !== []): ?>
<div class="alert alert-danger"><strong><?= count($unroutedProducts) ?> produk di PO belum punya divisi</strong>, jadi tidak muncul di divisi mana pun: <?= ui_esc(implode(', ', $unroutedProducts)) ?>. Atur divisinya di <a href="/api/_ui-preview/?page=master-data">Master Data</a>.</div>
<?php endif; ?>
<?php if ($noDivisionAssignment): ?>
<div class="alert alert-danger">User belum memiliki assignment divisi produksi. Hubungi Admin untuk mendapatkan akses ke divisi produksi Anda.</div>
<?php endif; ?>
<?php if (!$fieldSimple): ?>
<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="produksi">
    <div class="field"><label>Tanggal</label><input type="date" name="tanggal" value="<?= ui_esc($uiTanggal) ?>"></div>
    <div class="field"><label>Pabrik</label>
      <select name="factoryId">
        <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['factory_id'] ?>" <?= $uiFactoryId === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Divisi</label>
      <select name="divisionId">
        <option value="">Semua Divisi</option>
        <?php foreach ($divisionRows as $d): ?>
        <option value="<?= (int) $d['division_id'] ?>" <?= $divisionIdParam === (int) $d['division_id'] ? 'selected' : '' ?>><?= ui_esc($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Status</label>
      <select name="status">
        <option value="">Semua Status</option>
        <?php foreach (['draft' => 'Draft', 'submitted' => 'Sudah Disubmit', 'reopened' => 'Dibuka Kembali'] as $code => $label): ?>
        <option value="<?= $code ?>" <?= $statusParam === $code ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>
</div>

<div class="kpi-grid">
  <?= ui_kpi_card(['label' => 'Target Produksi', 'value' => ui_fmt_num($kpi['target']), 'icon' => 'cart', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'Actual Produksi', 'value' => ui_fmt_num($kpi['actual']), 'icon' => 'factory', 'color' => 'primary', 'progressPct' => ui_fmt_pct($kpi['actual'], $kpi['target'])]) ?>
  <?= ui_kpi_card(['label' => 'Sisa Produksi', 'value' => ui_fmt_num($kpi['remaining']), 'icon' => 'box', 'color' => 'warning']) ?>
  <?= ui_kpi_card(['label' => 'Belum Diproduksi', 'value' => (string) $kpi['notProduced'], 'icon' => 'file', 'color' => 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'Belum Sesuai Target', 'value' => (string) $kpi['belowTarget'], 'icon' => 'file', 'color' => 'warning']) ?>
  <?= ui_kpi_card(['label' => 'Sesuai Target', 'value' => (string) $kpi['onTarget'], 'icon' => 'file', 'color' => 'success']) ?>
  <?= ui_kpi_card(['label' => 'Overproduction', 'value' => (string) $kpi['over'], 'icon' => 'file', 'color' => 'warning']) ?>
</div>

<?php
$activeSubmittedCount = count(array_filter($divisionSummaryRows, static fn ($r) => $r['hasTarget'] && $r['statusLabel'] === 'Sudah Disubmit'));
?>
<div class="table-card section">
  <div class="card-head" style="padding:var(--space-4) var(--space-4) 0;">
    <h2 class="card-title">Progress per Divisi</h2>
    <span style="color:var(--text-muted);font-size:var(--text-sm);"><?= $activeSubmittedCount ?> / <?= $activeDivisionCount ?> divisi aktif sudah submit</span>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Divisi</th><th class="num">Target</th><th class="num">Actual</th><th class="num">Reject</th><th class="num">Sisa</th><th>Status</th><th>Terakhir Diperbarui</th><th>Disubmit Oleh</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if ($divisionSummaryRows === []): ?>
    <tr><td colspan="9"><?= ui_empty_state('Tidak ada divisi produksi', 'Pabrik ini belum punya divisi produksi.') ?></td></tr>
    <?php endif; ?>
    <?php foreach ($divisionSummaryRows as $row): ?>
    <tr>
      <td><?= ui_esc($row['divisionName']) ?><?php if (!$row['hasTarget']): ?> <span style="color:var(--text-faint);font-size:var(--text-xs);">(Tanpa Target)</span><?php endif; ?></td>
      <td class="num"><?= ui_fmt_num($row['target']) ?></td>
      <td class="num"><?= ui_fmt_num($row['actual']) ?></td>
      <td class="num"><?= ui_fmt_num($row['reject'] ?? 0) ?></td>
      <td class="num"><?= ui_fmt_num($row['remaining']) ?></td>
      <td><?= $row['hasTarget'] ? ui_badge($row['statusLabel']) : ui_badge('No Target') ?></td>
      <td style="font-size:var(--text-sm);color:var(--text-muted);"><?= $row['lastUpdated'] !== null ? ui_fmt_datetime_id((string) $row['lastUpdated']) : '-' ?></td>
      <td style="font-size:var(--text-sm);color:var(--text-muted);"><?= $row['submittedByName'] !== null ? ui_esc((string) $row['submittedByName']) : '-' ?></td>
      <td>
      <?php if ($row['runId'] !== null): ?>
        <a class="btn btn-primary btn-sm" href="/api/_ui-preview/?page=produksi&tanggal=<?= urlencode($uiTanggal) ?>&factoryId=<?= $uiFactoryId ?>&runId=<?= $row['runId'] ?>">Buka</a>
      <?php else: ?>
        <button type="button" class="btn btn-secondary btn-sm" data-action="create-run" data-division-id="<?= $row['divisionId'] ?>">Buat/Buka Draft</button>
      <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php endif; ?>

<?php if ($runIdParam !== null): ?>
<div class="card section" id="run-detail">
  <?php if ($runDetailError !== null): ?>
    <div class="alert alert-danger"><?= ui_esc($runDetailError) ?></div>
  <?php elseif ($runDetail !== null): ?>
  <?php
    $editable = in_array($runDetail['status'], ['draft', 'reopened'], true);
    // "Perlu Review Ulang" — a submitted document whose live PO target has
    // drifted from what was submitted against (task's own "do NOT silently
    // pretend the submitted report is still final"). This is a DERIVED
    // read-time badge, never a persisted status: targetChangedSincePoRevision
    // is already computed by buildRunDto() from the SAME live-target
    // comparison submit() itself warns about, so nothing here is a second
    // source of truth. The document's real status stays 'submitted' —
    // Admin/PPIC still use the existing Reopen action to actually act on it.
    $needsReview = $runDetail['status'] === 'submitted' && $runDetail['targetChangedSincePoRevision'];
  ?>
  <div class="card-head">
    <h2 class="card-title"><?= ui_esc($runDetail['divisionName']) ?> — <?= ui_esc($runDetail['tanggal']) ?></h2>
    <div style="display:flex;gap:var(--space-2);">
      <?= ui_badge(ui_doc_status_label($runDetail['status'])) ?>
      <?php if ($needsReview): ?><span class="badge badge-danger">Perlu Review Ulang</span><?php endif; ?>
    </div>
  </div>
  <?php if ($needsReview): ?>
  <div class="alert alert-warning">Target PO berubah (revisi) sejak dokumen ini disubmit. Angka Actual yang tersimpan TIDAK diubah otomatis — tinjau baris yang bertanda "berubah", lalu Buka Kembali / Reopen jika perlu direvisi.</div>
  <?php endif; ?>
  <?php if (!empty($runDetail['reopenReason'])): ?>
  <div class="alert alert-warning">Alasan dibuka kembali: <?= ui_esc((string) $runDetail['reopenReason']) ?></div>
  <?php endif; ?>
  <?php
    // Migration 0019 — "Siapa Anda?" names from the shared division login.
    // "Terakhir diupdate oleh" only shows when it differs from the creator.
    $opCreated = $runDetail['createdByOperatorName'] ?? null;
    $opUpdated = $runDetail['lastUpdatedByOperatorName'] ?? null;
    $opSubmitted = $runDetail['submittedByOperatorName'] ?? null;
    $opParts = [];
    if ($opCreated !== null) {
        $opParts[] = 'Dibuat oleh: <strong>' . ui_esc((string) $opCreated) . '</strong>';
    }
    if ($opUpdated !== null && $opUpdated !== $opCreated && $opUpdated !== $opSubmitted) {
        $opParts[] = 'Terakhir diupdate oleh: <strong>' . ui_esc((string) $opUpdated) . '</strong>';
    }
    if ($opSubmitted !== null) {
        // Reopen keeps submitted_by as history (existing behavior), so a
        // reopened document says "Sebelumnya disubmit oleh".
        $opParts[] = ($runDetail['status'] === 'reopened' ? 'Sebelumnya disubmit oleh' : 'Disubmit oleh') . ': <strong>' . ui_esc((string) $opSubmitted) . '</strong>';
    }
  ?>
  <?php if ($opParts !== []): ?>
  <p class="run-operators" style="font-size:var(--text-sm);color:var(--text-muted);margin:0 0 var(--space-3);"><?= implode(' &middot; ', $opParts) ?></p>
  <?php endif; ?>

  <div class="ws-toolbar">
    <div class="ws-progress"><span><strong id="ws-filled">0</strong> / <span id="ws-total">0</span> produk terisi</span><div class="ws-bar"><span id="ws-bar-fill"></span></div></div>
    <div class="ws-search">
      <input type="search" id="rd-search" placeholder="Cari produk...">
      <select id="rd-source-filter"<?= $nonRegularRows === [] ? ' hidden' : '' ?>>
        <option value="">Semua sumber</option>
        <option value="po_reguler">Toko Reguler</option>
        <option value="non_reguler">Pesanan Khusus / Non-Toko</option>
      </select>
    </div>
    <div class="ws-chips" role="tablist">
      <button type="button" class="ws-chip active" data-chip="">Semua</button>
      <button type="button" class="ws-chip" data-chip="empty">Belum diisi</button>
      <button type="button" class="ws-chip" data-chip="short">Kurang / Reject</button>
      <button type="button" class="ws-chip" data-chip="ok">Sesuai</button>
    </div>
    <?php if ($editable): ?>
    <button type="button" class="btn btn-secondary btn-block" id="btn-all-sesuai">&#10003; Isi semua yang belum diisi = Sesuai target</button>
    <?php endif; ?>
  </div>

  <?php
  // A PO revision can add products for this division after the draft was
  // opened; they only enter the worksheet through a target refresh.
  $newPoProducts = 0;
  if ($editable) {
      $runDivStmt = $pdo->prepare('SELECT factory_id FROM division WHERE division_id = ?');
      $runDivStmt->execute([(int) $runDetail['divisionId']]);
      $runFactoryId = (int) $runDivStmt->fetchColumn();
      $inRun = array_flip(array_map(static fn ($i) => (int) $i['productId'], $runDetail['items']));
      foreach ($targetService->targetsByProduct($pdo, (string) $runDetail['tanggal'], $runFactoryId, (int) $runDetail['divisionId']) as $pid => $t) {
          if (!isset($inRun[(int) $pid]) && (float) $t['target'] > 0.0001) {
              $newPoProducts++;
          }
      }
  }
  ?>
  <?php if ($newPoProducts > 0): ?>
  <div class="alert alert-warning" style="margin-bottom:var(--space-3);">
    Ada <strong><?= $newPoProducts ?> produk baru</strong> dari PO revisi untuk divisi ini yang belum ada di lembar ini.
    <button type="button" class="btn btn-primary btn-sm" id="btn-refresh-targets" style="margin-left:var(--space-2);">Tambahkan ke lembar</button>
  </div>
  <?php endif; ?>
  <form id="run-form" data-run-id="<?= (int) $runDetail['productionRunId'] ?>" data-expected-version="<?= (int) $runDetail['version'] ?>">
  <div class="table-scroll"><table class="data-table" id="rd-table">
    <thead><tr><th>No</th><th>Produk</th><th>Sumber</th><th class="num">Target</th><th>Hasil Produksi</th><th class="num">Actual</th><th class="num">Reject Produksi</th><th>Status</th><th>Catatan</th></tr></thead>
    <tbody>
    <?php $no = 1; foreach ($runDetail['items'] as $it): ?>
    <tr class="rd-row" data-source="po_reguler" data-search="<?= ui_esc(mb_strtolower($it['productName'])) ?>"<?= $editable && (float) $it['liveTarget'] <= 0.0001 ? ' data-nopo="1"' : '' ?>>
      <td><?= $no++ ?></td>
      <td><?= ui_esc($it['productName']) ?></td>
      <td><?= ui_task_source_badge('po_reguler', 'Toko Reguler') ?></td>
      <td class="num"><?= ui_fmt_num($it['liveTarget']) ?><?php if ($it['targetChangedSinceDraft']): ?> <span class="badge badge-warning" title="Target PO berubah sejak draft dibuat">berubah</span><?php endif; ?></td>
      <td>
        <?php if ($editable && (float) $it['liveTarget'] <= 0.0001): ?>
        <span style="color:var(--text-faint);font-size:var(--text-xs);">belum ada PO</span>
        <?php elseif ($editable): ?>
        <div class="btn-group rd-sesuai-group" data-product-id="<?= (int) $it['productId'] ?>" data-target="<?= ui_esc((string) $it['liveTarget']) ?>">
          <button type="button" class="btn btn-sm rd-sesuai-btn <?= abs($it['actual'] - $it['liveTarget']) < 0.01 && $it['actual'] > 0 ? 'btn-primary' : 'btn-secondary' ?>" data-value="sesuai">Sesuai</button>
          <button type="button" class="btn btn-sm rd-sesuai-btn <?= !(abs($it['actual'] - $it['liveTarget']) < 0.01 && $it['actual'] > 0) && ($it['actual'] > 0 || $it['reject'] > 0) ? 'btn-danger' : 'btn-secondary' ?>" data-value="tidak_sesuai">Tidak Sesuai</button>
        </div>
        <?php else: ?><span style="color:var(--text-faint);">-</span><?php endif; ?>
      </td>
      <td class="num">
        <?php if ($editable): ?>
        <input type="number" step="0.01" min="0" style="width:6rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="actual" value="<?= ui_num_input($it['actual']) ?>" <?= abs($it['actual'] - $it['liveTarget']) < 0.01 && $it['actual'] > 0 ? 'disabled' : '' ?>>
        <?php else: ?><?= ui_fmt_num($it['actual']) ?><?php endif; ?>
      </td>
      <td class="num">
        <?php if ($editable): ?>
        <input type="number" step="0.01" min="0" style="width:6rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="reject" value="<?= ui_num_input($it['reject']) ?>">
        <?php else: ?><?= ui_fmt_num($it['reject']) ?><?php endif; ?>
      </td>
      <td><?= ui_badge($it['displayStatusLabel']) ?></td>
      <td><?php if ($editable): ?><input type="text" style="width:9rem;" data-product-id="<?= (int) $it['productId'] ?>" data-field="keterangan" value="<?= ui_esc((string) ($it['notes'] ?? '')) ?>"><?php else: ?><?= ui_esc((string) ($it['notes'] ?? '')) ?><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    <?php foreach ($nonRegularRows as $nr): ?>
    <tr class="rd-row" data-source="<?= ui_esc($nr['source']) ?>" data-search="<?= ui_esc(mb_strtolower($nr['productName'] . ' ' . $nr['reference'])) ?>">
      <td><?= $no++ ?></td>
      <td><?= ui_esc($nr['productName']) ?><div style="color:var(--text-muted);font-size:var(--text-xs);"><?= ui_esc($nr['reference']) ?></div></td>
      <td><?= ui_task_source_badge($nr['source'], $nr['sourceLabel']) ?></td>
      <td class="num"><?= ui_fmt_num($nr['target']) ?></td>
      <td>
        <div class="btn-group nr-sesuai-group" data-item-id="<?= $nr['itemId'] ?>" data-target="<?= ui_esc((string) $nr['target']) ?>">
          <button type="button" class="btn btn-sm nr-sesuai-btn <?= abs($nr['aktual'] - $nr['target']) < 0.01 && $nr['aktual'] > 0 ? 'btn-primary' : 'btn-secondary' ?>" data-value="sesuai">Sesuai</button>
          <button type="button" class="btn btn-sm nr-sesuai-btn <?= !(abs($nr['aktual'] - $nr['target']) < 0.01 && $nr['aktual'] > 0) && ($nr['aktual'] > 0 || $nr['reject'] > 0) ? 'btn-danger' : 'btn-secondary' ?>" data-value="tidak_sesuai">Tidak Sesuai</button>
        </div>
      </td>
      <td class="num">
        <input type="number" step="0.01" min="0" style="width:6rem;text-align:right;" class="nr-actual" data-item-id="<?= $nr['itemId'] ?>" data-order-id="<?= $nr['orderId'] ?>" data-order-version="<?= $nr['orderVersion'] ?>" value="<?= ui_num_input($nr['aktual']) ?>" <?= abs($nr['aktual'] - $nr['target']) < 0.01 && $nr['aktual'] > 0 ? 'disabled' : '' ?>>
      </td>
      <td class="num"><input type="number" step="0.01" min="0" style="width:6rem;text-align:right;" class="nr-reject" data-item-id="<?= $nr['itemId'] ?>" value="<?= ui_num_input($nr['reject']) ?>"></td>
      <td><?= $nr['aktual'] <= 0.0001 ? ui_badge('Belum Diproduksi') : ($nr['aktual'] + 0.0001 >= $nr['target'] ? ui_badge('Sesuai Target') : ui_badge('Belum Sesuai Target')) ?></td>
      <td><?= $nr['catatan'] ? ui_esc((string) $nr['catatan']) : '-' ?><?php if (!empty($nr['operatorName'])): ?><div class="nr-operator" style="font-size:var(--text-xs);color:var(--text-muted);">Diisi oleh: <?= ui_esc((string) $nr['operatorName']) ?></div><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($editable): ?>
  <div class="btn-group" style="margin-top:var(--space-4);">
    <button type="button" class="btn btn-primary" id="btn-save-draft">Simpan Draft</button>
    <button type="button" class="btn btn-success" id="btn-submit-run">Submit Produksi</button>
  </div>
  <?php elseif ($runDetail['status'] === 'submitted' && array_intersect(['ADMIN', 'PPIC'], $ui['roles']) !== []): ?>
  <div class="btn-group" style="margin-top:var(--space-4);">
    <button type="button" class="btn btn-warning" id="btn-reopen-run">Buka Kembali / Reopen</button>
  </div>
  <?php elseif ($runDetail['status'] === 'submitted'): ?>
  <p class="day-card-note" style="margin-top:var(--space-3);">Sudah disubmit. Kalau ada angka yang perlu dikoreksi, minta Admin membuka kembali dokumen ini.</p>
  <?php endif; ?>
  <?php if ($nonRegularRows !== []): ?>
  <div class="btn-group" style="margin-top:var(--space-3);">
    <button type="button" class="btn btn-secondary" id="btn-save-non-regular">Simpan Actual &amp; Reject (Non Reguler)</button>
  </div>
  <?php endif; ?>
  </form>
  <?php
  // Serah terima ke FG (migration 0025) + "Mau produksi apa?".
  $hoService = new \Amor\Api\Production\HandoverService($pdo);
  $hoList = $hoService->listForRun((int) $runDetail['productionRunId']);
  $hoHanded = $hoService->handedByProduct((int) $runDetail['productionRunId']);
  $hoMade = array_sum(array_map(static fn ($i) => (float) $i['actual'], $runDetail['items']));
  $hoGiven = array_sum($hoHanded);
  $inSheet = array_flip(array_map(static fn ($i) => (int) $i['productId'], $runDetail['items']));
  $addable = [];
  if ($editable) {
      $ap = $pdo->prepare('SELECT product_id, name FROM product WHERE division_id = ? AND aktif = 1 ORDER BY name');
      $ap->execute([(int) $runDetail['divisionId']]);
      foreach ($ap->fetchAll(PDO::FETCH_ASSOC) as $p) {
          if (!isset($inSheet[(int) $p['product_id']])) {
              $addable[] = ['id' => (int) $p['product_id'], 'name' => (string) $p['name']];
          }
      }
  }
  $hoRows = array_map(static fn ($i) => ['id' => (int) $i['productId'], 'name' => (string) $i['productName'], 'made' => (float) $i['actual'], 'handed' => (float) ($hoHanded[(int) $i['productId']] ?? 0)], $runDetail['items']);
  ?>
  <?php if ($editable): ?>
  <div class="ho-add" style="margin-top:var(--space-4);position:relative;">
    <label style="font-weight:700;font-size:var(--text-sm);display:block;margin-bottom:4px;">Mau produksi apa? <span style="font-weight:400;color:var(--text-muted);">(produk yang belum ada di lembar ini)</span></label>
    <input type="search" id="ho-add-q" placeholder="Ketik nama produk, mis. donut ring..." autocomplete="off" style="width:100%;">
    <div id="ho-add-list" style="display:none;position:absolute;left:0;right:0;z-index:40;background:var(--surface);border:1px solid var(--border);border-radius:10px;max-height:240px;overflow:auto;box-shadow:0 10px 30px rgba(0,0,0,.35);"></div>
  </div>
  <?php endif; ?>
  <div class="card" style="margin-top:var(--space-4);padding:var(--space-3);" id="ho-card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
      <h3 class="card-title" style="margin:0;">Serah terima ke FG</h3>
      <span style="font-size:var(--text-sm);color:var(--text-muted);">Sudah jadi <b><?= ui_fmt_num($hoMade) ?></b> · Diserahkan <b><?= ui_fmt_num($hoGiven) ?></b> · Belum diserahkan <b><?= ui_fmt_num(max(0, $hoMade - $hoGiven)) ?></b></span>
    </div>
    <?php if ($editable): ?>
    <p style="font-size:var(--text-sm);color:var(--text-muted);margin:6px 0;">Setiap kali barang dibawa ke FG, tekan <b>Serahkan ke FG</b> dan isi jumlah yang dibawa saat itu. Bisa berkali-kali sehari.</p>
    <button type="button" class="btn btn-primary" id="btn-handover">Serahkan ke FG &rarr;</button>
    <?php elseif ($runDetail['status'] === 'submitted'): ?>
    <p style="font-size:var(--text-sm);color:var(--text-muted);margin:6px 0;">Produksi sudah disubmit — semua hasilnya sudah terhitung di FG.</p>
    <?php endif; ?>
    <?php if ($hoList !== []): ?>
    <div style="margin-top:var(--space-3);">
      <?php foreach (array_reverse($hoList) as $h): ?>
      <div style="border-top:1px solid var(--border);padding:8px 0;">
        <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">
          <b>#<?= (int) $h['seq'] ?> · <?= ui_esc(ui_fmt_datetime_id((string) $h['createdAt'])) ?></b>
          <?php if ($editable): ?><button type="button" class="btn btn-secondary btn-sm" data-ho-edit="<?= (int) $h['handoverId'] ?>">Koreksi</button><?php endif; ?>
        </div>
        <div style="font-size:var(--text-sm);color:var(--text-muted);">
          <?= ui_esc(implode(' · ', array_map(static fn ($it) => $it['productName'] . ' ' . ui_fmt_num($it['qty']), $h['items']))) ?>
          <?= $h['createdBy'] ? ' — oleh ' . ui_esc((string) $h['createdBy']) : '' ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var RUN = <?= (int) $runDetail['productionRunId'] ?>;
    var ROWS = <?= json_encode($hoRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var ADDABLE = <?= json_encode($addable, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var HO = <?= json_encode(array_map(static fn ($h) => ['id' => $h['handoverId'], 'seq' => $h['seq'], 'items' => $h['items']], $hoList), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function num(n) { return (Math.round((Number(n) || 0) * 100) / 100).toLocaleString('id-ID'); }
    function form() { return document.getElementById('run-form'); }
    function liveMade(id) {
      var inp = document.querySelector('#run-form [data-field="actual"][data-product-id="' + id + '"]');
      return inp ? (parseFloat(inp.value) || 0) : 0;
    }
    async function saveSheet() {
      var f = form(); if (!f) return;
      var items = [];
      f.querySelectorAll('[data-field="actual"]').forEach(function (inp) {
        var pid = inp.getAttribute('data-product-id');
        var rej = f.querySelector('[data-field="reject"][data-product-id="' + pid + '"]');
        var note = f.querySelector('[data-field="keterangan"][data-product-id="' + pid + '"]');
        items.push({ productId: parseInt(pid, 10), actualQty: parseFloat(inp.value) || 0, rejectQty: rej ? (parseFloat(rej.value) || 0) : undefined, notes: note ? note.value : undefined });
      });
      var d = await Amor.apiFetch('/api/production/' + RUN, { method: 'PATCH', body: { expectedVersion: parseInt(f.getAttribute('data-expected-version'), 10), items: items } });
      f.setAttribute('data-expected-version', d.version);
    }
    function qtyModal(title, intro, rows, confirmLabel) {
      var html = '<p style="font-size:var(--text-sm);color:var(--text-muted);margin:0 0 8px">' + intro + '</p>'
        + rows.map(function (r) { return '<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border)"><span style="flex:1"><b style="font-size:13px">' + esc(r.name) + '</b><br><small style="color:var(--text-muted)">' + esc(r.sub) + '</small></span><input type="number" class="ho-q" data-id="' + r.id + '" min="0" step="any" max="' + r.max + '" value="' + r.value + '" style="width:5.5rem;text-align:right"></div>'; }).join('');
      return Amor.confirmModal({ title: title, body: '', bodyHtml: html, confirmLabel: confirmLabel, onConfirm: function () {
        var out = []; document.querySelectorAll('.modal .ho-q').forEach(function (i) { out.push({ productId: parseInt(i.getAttribute('data-id'), 10), qty: parseFloat(i.value) || 0, max: parseFloat(i.max) }); });
        window.__hoOut = out; return Promise.resolve();
      } });
    }
    var hb = document.getElementById('btn-handover');
    if (hb) hb.addEventListener('click', async function () {
      try { await saveSheet(); } catch (e) { Amor.toast(e.message, 'danger'); return; }
      var rows = ROWS.map(function (r) { var made = liveMade(r.id); return { id: r.id, name: r.name, max: Math.max(0, made - r.handed), sub: 'sudah jadi ' + num(made) + ' · sudah diserahkan ' + num(r.handed) }; })
        .filter(function (r) { return r.max > 0.0001; }).map(function (r) { r.value = r.max; return r; });
      if (!rows.length) { Amor.toast('Belum ada hasil yang bisa diserahkan. Isi dulu jumlah "sudah jadi" (kolom Actual), lalu Simpan.', 'warning'); return; }
      var ok = await qtyModal('Serahkan ke FG', 'Isi jumlah yang <b>dibawa ke FG sekarang</b>. Sisanya tetap di produksi dan bisa diserahkan nanti.', rows, 'Serahkan ke FG');
      if (!ok) return;
      var items = (window.__hoOut || []).filter(function (x) { return x.qty > 0; });
      var bad = (window.__hoOut || []).filter(function (x) { return x.qty > x.max + 0.0001; });
      if (bad.length) { Amor.toast('Jumlah melebihi yang sudah jadi dan belum diserahkan.', 'danger'); return; }
      if (!items.length) { Amor.toast('Tidak ada jumlah yang diisi.', 'warning'); return; }
      try { await Amor.apiFetch('/api/production/' + RUN + '/handovers', { method: 'POST', body: { items: items.map(function (x) { return { productId: x.productId, qty: x.qty }; }) } }); Amor.toast('Serah terima tersimpan. Barang langsung masuk ke FG.', 'success'); setTimeout(function () { location.reload(); }, 700); }
      catch (e) { Amor.toast(e.message, 'danger'); }
    });
    document.querySelectorAll('[data-ho-edit]').forEach(function (b) {
      b.addEventListener('click', async function () {
        var h = HO.filter(function (x) { return String(x.id) === b.getAttribute('data-ho-edit'); })[0];
        var rows = h.items.map(function (it) { var r = ROWS.filter(function (x) { return x.id === it.productId; })[0] || { made: it.qty, handed: it.qty };
          var max = Math.max(0, liveMade(it.productId) - (r.handed - it.qty)); return { id: it.productId, name: it.productName, max: max, value: it.qty, sub: 'diserahkan ' + num(it.qty) + ' · isi 0 untuk menarik kembali' }; });
        var ok = await qtyModal('Koreksi serah terima #' + h.seq, 'Bisa dikoreksi selama produksi belum disubmit (tidak bisa di bawah yang sudah diverifikasi FG).', rows, 'Simpan koreksi');
        if (!ok) return;
        try { await Amor.apiFetch('/api/production/handovers/' + h.id, { method: 'PATCH', body: { items: (window.__hoOut || []).map(function (x) { return { productId: x.productId, qty: x.qty }; }) } }); Amor.toast('Koreksi tersimpan.', 'success'); setTimeout(function () { location.reload(); }, 700); }
        catch (e) { Amor.toast(e.message, 'danger'); }
      });
    });
    var q = document.getElementById('ho-add-q'), list = document.getElementById('ho-add-list');
    if (q) {
      q.addEventListener('input', function () {
        var t = q.value.toLowerCase().trim();
        if (t.length < 2) { list.style.display = 'none'; return; }
        var hits = ADDABLE.filter(function (p) { return p.name.toLowerCase().indexOf(t) !== -1; }).slice(0, 12);
        list.innerHTML = hits.length ? hits.map(function (p) { return '<div class="ho-hit" data-id="' + p.id + '" style="padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border)">' + esc(p.name) + '</div>'; }).join('') : '<div style="padding:9px 12px;color:var(--text-muted)">Tidak ditemukan di produk divisi ini.</div>';
        list.style.display = '';
      });
      list.addEventListener('click', async function (e) {
        var hit = e.target.closest('.ho-hit'); if (!hit) return;
        list.style.display = 'none';
        try {
          await saveSheet().catch(function () {});
          var f = form();
          await Amor.apiFetch('/api/production/' + RUN, { method: 'PATCH', body: { expectedVersion: parseInt(f.getAttribute('data-expected-version'), 10), items: [{ productId: parseInt(hit.getAttribute('data-id'), 10), actualQty: 0 }] } });
          Amor.toast(hit.textContent + ' ditambahkan ke lembar.', 'success'); setTimeout(function () { location.reload(); }, 600);
        } catch (err) { Amor.toast(err.message, 'danger'); }
      });
    }
  });
  </script>
  <?php else: ?>
    <?= ui_empty_state('Draft tidak ditemukan', '') ?>
  <?php endif; ?>
</div>

<script>
(function () {
  // Sesuai/Tidak Sesuai — clicking Sesuai auto-fills Actual to the row's
  // own live Target and locks the input (a convenience only; the server
  // ALWAYS re-derives the live target itself and rejects a mismatched
  // payload — see ProductionService::patchDraft()'s own "sesuai" check,
  // task's own "Do not trust disabled UI input only"). Tidak Sesuai
  // unlocks manual entry. Applies to both the PO Reguler rows (rd-*) and
  // the merged Non-Regular rows (nr-*) — same UX, two different save paths.
  function wireSesuaiGroup(group, actualInput) {
    // Keep the buttons' own rd-/nr-sesuai-btn class: it used to be wiped by
    // a className reset on the first click, after which switching between
    // Sesuai and Tidak Sesuai no longer restyled the other button.
    var buttons = group.querySelectorAll('.rd-sesuai-btn, .nr-sesuai-btn');
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var isSesuai = btn.getAttribute('data-value') === 'sesuai';
        buttons.forEach(function (b) {
          b.classList.remove('btn-primary', 'btn-danger', 'btn-secondary');
          b.classList.add(b === btn ? (isSesuai ? 'btn-primary' : 'btn-danger') : 'btn-secondary');
        });
        group.setAttribute('data-sesuai', isSesuai ? '1' : '0');
        if (isSesuai) {
          actualInput.value = group.getAttribute('data-target');
          actualInput.disabled = true;
        } else {
          actualInput.disabled = false;
        }
      });
    });
  }
  document.querySelectorAll('.rd-sesuai-group').forEach(function (group) {
    var pid = group.getAttribute('data-product-id');
    var actualInput = document.querySelector('#run-form [data-field="actual"][data-product-id="' + pid + '"]');
    if (actualInput) { group.setAttribute('data-sesuai', actualInput.disabled ? '1' : '0'); wireSesuaiGroup(group, actualInput); }
  });
  document.querySelectorAll('.nr-sesuai-group').forEach(function (group) {
    var itemId = group.getAttribute('data-item-id');
    var actualInput = document.querySelector('.nr-actual[data-item-id="' + itemId + '"]');
    if (actualInput) { wireSesuaiGroup(group, actualInput); }
  });

  // Row state for the toolbar: ok = Sesuai chosen, short = a number was
  // typed that is not the target (or a reject), empty = not filled yet.
  // Purely a view of what is on screen; saving is unchanged.
  function rowState(tr) {
    var group = tr.querySelector('.rd-sesuai-group, .nr-sesuai-group');
    var actual = tr.querySelector('[data-field="actual"], .nr-actual');
    var reject = tr.querySelector('[data-field="reject"], .nr-reject');
    if (tr.hasAttribute('data-nopo') && actual) {
      // No PO yet: nothing to be "Sesuai" against, the qty is typed in.
      var na = parseFloat(actual.value || '0'), nr = parseFloat(reject && reject.value || '0');
      return nr > 0 ? 'short' : (na > 0 ? 'ok' : 'empty');
    }
    if (!group || !actual) return 'done';
    var sesuaiBtn = group.querySelector('[data-value="sesuai"]');
    if (sesuaiBtn && sesuaiBtn.classList.contains('btn-primary')) return 'ok';
    var a = parseFloat(actual.value || '0'), r = parseFloat(reject && reject.value || '0');
    var open = group.querySelector('[data-value="tidak_sesuai"]').classList.contains('btn-danger');
    if (a > 0 || r > 0 || open) return 'short';
    return 'empty';
  }
  var searchEl = document.getElementById('rd-search');
  var sourceEl = document.getElementById('rd-source-filter');
  var chip = '';
  function applyFilters() {
    var q = searchEl ? (searchEl.value || '').toLowerCase().trim() : '';
    var src = sourceEl ? sourceEl.value : '';
    var filled = 0, total = 0;
    document.querySelectorAll('#rd-table tbody tr.rd-row').forEach(function (tr) {
      var st = rowState(tr);
      tr.setAttribute('data-state', st);
      tr.classList.toggle('is-open', st === 'short' || tr.hasAttribute('data-nopo'));
      if (st !== 'done') { total++; if (st !== 'empty') filled++; }
      var rowSrc = tr.getAttribute('data-source');
      var matchQ = q === '' || tr.getAttribute('data-search').indexOf(q) !== -1;
      var matchSrc = src === '' || (src === 'non_reguler' ? rowSrc !== 'po_reguler' : rowSrc === src);
      var matchChip = chip === '' || st === chip;
      tr.style.display = (matchQ && matchSrc && matchChip) ? '' : 'none';
    });
    var f = document.getElementById('ws-filled'), t = document.getElementById('ws-total'), bar = document.getElementById('ws-bar-fill');
    if (f) { f.textContent = filled; t.textContent = total; bar.style.width = (total ? Math.round(filled * 100 / total) : 0) + '%'; }
  }
  if (searchEl) { searchEl.addEventListener('input', applyFilters); sourceEl.addEventListener('change', applyFilters); }
  document.querySelectorAll('.ws-chip').forEach(function (c) {
    c.addEventListener('click', function () {
      document.querySelectorAll('.ws-chip').forEach(function (x) { x.classList.toggle('active', x === c); });
      chip = c.getAttribute('data-chip'); applyFilters();
    });
  });
  var rdTable = document.getElementById('rd-table');
  if (rdTable) {
    rdTable.addEventListener('click', function (e) { if (e.target.closest('.rd-sesuai-btn, .nr-sesuai-btn')) setTimeout(applyFilters, 0); });
    rdTable.addEventListener('input', function () { applyFilters(); });
  }
  var allBtn = document.getElementById('btn-all-sesuai');
  if (allBtn) allBtn.addEventListener('click', async function () {
    var rows = Array.prototype.filter.call(document.querySelectorAll('#rd-table tbody tr.rd-row'), function (tr) { return rowState(tr) === 'empty' && !tr.hasAttribute('data-nopo'); });
    if (rows.length === 0) { Amor.toast('Semua produk sudah terisi.', 'info'); return; }
    var ok = await Amor.confirmModal({ title: 'Tandai Sesuai target?', body: rows.length + ' produk yang belum diisi akan diisi sama dengan targetnya. Produk yang kurang tetap bisa diubah satu per satu sebelum disimpan.' });
    if (!ok) return;
    rows.forEach(function (tr) { var b = tr.querySelector('[data-value="sesuai"]'); if (b) b.click(); });
    applyFilters();
    Amor.toast(rows.length + ' produk ditandai Sesuai. Jangan lupa Simpan.', 'success');
  });
  applyFilters();

  function collectItems() {
    var items = [];
    document.querySelectorAll('#run-form [data-field="actual"]').forEach(function (input) {
      var pid = input.getAttribute('data-product-id');
      var rejectInput = document.querySelector('#run-form [data-field="reject"][data-product-id="' + pid + '"]');
      var notesInput = document.querySelector('#run-form [data-field="keterangan"][data-product-id="' + pid + '"]');
      var sesuaiGroup = document.querySelector('.rd-sesuai-group[data-product-id="' + pid + '"]');
      items.push({
        productId: parseInt(pid, 10),
        actualQty: parseFloat(input.value || '0'),
        rejectQty: parseFloat(rejectInput ? (rejectInput.value || '0') : '0'),
        notes: notesInput ? notesInput.value : '',
        sesuai: sesuaiGroup ? sesuaiGroup.getAttribute('data-sesuai') === '1' : false,
      });
    });
    return items;
  }
  var form = document.getElementById('run-form');
  if (!form) return;
  var runId = form.getAttribute('data-run-id');
  var version = parseInt(form.getAttribute('data-expected-version'), 10);

  // Pesanan Khusus / Non-Toko rows on this worksheet are saved through
  // their own endpoint. Simpan Draft and Submit save them too, so values
  // filled by "Isi semua = Sesuai" are never lost on the reload.
  async function saveNonRegular() {
    var byOrder = {};
    document.querySelectorAll('.nr-actual').forEach(function (input) {
      var orderId = input.getAttribute('data-order-id');
      var itemId = input.getAttribute('data-item-id');
      var orderVersion = parseInt(input.getAttribute('data-order-version'), 10);
      var rejectInput = document.querySelector('.nr-reject[data-item-id="' + itemId + '"]');
      if (!byOrder[orderId]) byOrder[orderId] = { expectedVersion: orderVersion, items: [] };
      byOrder[orderId].items.push({
        itemId: parseInt(itemId, 10),
        aktualProduksi: parseFloat(input.value || '0'),
        rejectProduksi: parseFloat(rejectInput ? (rejectInput.value || '0') : '0'),
      });
    });
    for (var orderId in byOrder) {
      if (!byOrder.hasOwnProperty(orderId)) continue;
      await Amor.apiFetch('/api/special-orders/' + orderId + '/actual', { method: 'POST', body: byOrder[orderId] });
    }
  }
  var saveNonRegularBtn = document.getElementById('btn-save-non-regular');
  if (saveNonRegularBtn) saveNonRegularBtn.addEventListener('click', async function () {
    saveNonRegularBtn.disabled = true;
    try {
      await saveNonRegular();
      Amor.toast('Actual & Reject Non Reguler disimpan.', 'success');
      setTimeout(function () { location.reload(); }, 500);
    } catch (e) { Amor.toast(e.message, 'danger'); saveNonRegularBtn.disabled = false; }
  });
  var refreshBtn = document.getElementById('btn-refresh-targets');
  if (refreshBtn) refreshBtn.addEventListener('click', async function () {
    refreshBtn.disabled = true;
    try {
      await Amor.apiFetch('/api/production/' + runId, { method: 'PATCH', body: { expectedVersion: version, items: collectItems(), refreshTargets: true } });
      Amor.toast('Produk baru dari PO revisi ditambahkan.', 'success');
      setTimeout(function () { location.reload(); }, 500);
    } catch (e) { Amor.toast(e.message, 'danger'); refreshBtn.disabled = false; }
  });

  var saveBtn = document.getElementById('btn-save-draft');
  if (saveBtn) saveBtn.addEventListener('click', async function () {
    saveBtn.disabled = true;
    try {
      await saveNonRegular();
      var data = await Amor.apiFetch('/api/production/' + runId, { method: 'PATCH', body: { expectedVersion: version, items: collectItems() } });
      Amor.toast('Draft Produksi disimpan.', 'success');
      version = data.version;
      form.setAttribute('data-expected-version', version);
    } catch (e) { Amor.toast(e.message, 'danger'); }
    saveBtn.disabled = false;
  });

  var submitBtn = document.getElementById('btn-submit-run');
  if (submitBtn) submitBtn.addEventListener('click', async function () {
    var ok = await Amor.confirmModal({ title: 'Submit Produksi?', body: 'Data akan menjadi sumber untuk FG & Packing. Pastikan angka Actual sudah benar.', confirmLabel: 'Ya, Submit' });
    if (!ok) return;
    submitBtn.disabled = true;
    try {
      await saveNonRegular();
      await Amor.apiFetch('/api/production/' + runId, { method: 'PATCH', body: { expectedVersion: version, items: collectItems() } });
      var data = await Amor.apiFetch('/api/production/' + runId + '/submit', { method: 'POST', body: { expectedVersion: version + 1 } });
      Amor.toast('Produksi berhasil disubmit.', 'success');
      setTimeout(function () { location.reload(); }, 600);
    } catch (e) { Amor.toast(e.message, 'danger'); submitBtn.disabled = false; }
  });

  var reopenBtn = document.getElementById('btn-reopen-run');
  if (reopenBtn) reopenBtn.addEventListener('click', async function () {
    var reason = prompt('Alasan membuka kembali produksi ini (wajib):');
    if (!reason) return;
    reopenBtn.disabled = true;
    try {
      await Amor.apiFetch('/api/production/' + runId + '/reopen', { method: 'POST', body: { expectedVersion: version, reason: reason } });
      Amor.toast('Produksi dibuka kembali.', 'success');
      setTimeout(function () { location.reload(); }, 600);
    } catch (e) { Amor.toast(e.message, 'danger'); reopenBtn.disabled = false; }
  });
})();
</script>
<?php endif; ?>

<script>
document.querySelectorAll('[data-action="create-run"]').forEach(function (btn) {
  btn.addEventListener('click', async function () {
    btn.disabled = true;
    try {
      var data = await Amor.apiFetch('/api/production', { method: 'POST', body: { tanggal: <?= json_encode($uiTanggal) ?>, divisionId: parseInt(btn.getAttribute('data-division-id'), 10) } });
      Amor.toast('Draft Produksi dibuat.', 'success');
      location.href = '/api/_ui-preview/?page=produksi&tanggal=<?= urlencode($uiTanggal) ?>&factoryId=<?= $uiFactoryId ?>&runId=' + data.productionRunId;
    } catch (e) { Amor.toast(e.message, 'danger'); btn.disabled = false; }
  });
});
</script>
