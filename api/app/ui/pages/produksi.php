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
// opt-in: a user with zero user_division_access rows is unrestricted, same
// as before this rework) only see the divisions they're assigned to in
// this picker. This is UI-level filtering, not a second access-control
// layer — the real enforcement is server-side in ProductionService's own
// requireProductionScopedDivision(), called on every read/write this page
// drives.
$scopedDivisionIds = Auth::currentDivisionIds();
if ($scopedDivisionIds !== [] && array_intersect(['ADMIN', 'PPIC'], Auth::currentRoles()) === []) {
    $divisionRows = array_values(array_filter($divisionRows, static fn ($d) => in_array((int) $d['division_id'], $scopedDivisionIds, true)));
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
foreach ($liveProducts as $p) {
    if ($p['divisionId'] === null) {
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
                     u.full_name AS submitted_by_name, COALESCE(SUM(pi.aktual), 0) AS actual_sum,
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
$actualSql .= ' GROUP BY r.production_run_id, r.division_id, r.status, r.updated_at, r.submitted_at, u.full_name';
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
                'itemId' => (int) $r['special_order_item_id'],
                'orderId' => (int) $r['special_order_id'],
                'orderVersion' => (int) $r['order_version'],
            ];
        }
    } catch (\Throwable $e) {
        $runDetailError = $e->getMessage();
    }
}
?>
<?= ui_produksi_tabs('produksi', $uiTanggal, $uiFactoryId) ?>
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

  <div style="display:flex;gap:var(--space-2);align-items:center;flex-wrap:wrap;margin-bottom:var(--space-3);">
    <input type="text" id="rd-search" placeholder="Cari produk..." style="min-width:200px;">
    <select id="rd-source-filter">
      <option value="">Semua</option>
      <option value="po_reguler">Toko Reguler (PO Awal + Revisi)</option>
      <option value="non_reguler">Non Reguler (Pesanan)</option>
    </select>
  </div>

  <form id="run-form" data-run-id="<?= (int) $runDetail['productionRunId'] ?>" data-expected-version="<?= (int) $runDetail['version'] ?>">
  <div class="table-scroll"><table class="data-table" id="rd-table">
    <thead><tr><th>No</th><th>Produk</th><th>Sumber</th><th class="num">Target</th><th>Hasil Produksi</th><th class="num">Actual</th><th class="num">Reject Produksi</th><th>Status</th><th>Catatan</th></tr></thead>
    <tbody>
    <?php $no = 1; foreach ($runDetail['items'] as $it): ?>
    <tr class="rd-row" data-source="po_reguler" data-search="<?= ui_esc(mb_strtolower($it['productName'])) ?>">
      <td><?= $no++ ?></td>
      <td><?= ui_esc($it['productName']) ?></td>
      <td><?= ui_task_source_badge('po_reguler', 'Toko Reguler') ?></td>
      <td class="num"><?= ui_fmt_num($it['liveTarget']) ?><?php if ($it['targetChangedSinceDraft']): ?> <span class="badge badge-warning" title="Target PO berubah sejak draft dibuat">berubah</span><?php endif; ?></td>
      <td>
        <?php if ($editable): ?>
        <div class="btn-group rd-sesuai-group" data-product-id="<?= (int) $it['productId'] ?>" data-target="<?= ui_esc((string) $it['liveTarget']) ?>">
          <button type="button" class="btn btn-sm rd-sesuai-btn <?= abs($it['actual'] - $it['liveTarget']) < 0.01 && $it['actual'] > 0 ? 'btn-primary' : 'btn-secondary' ?>" data-value="sesuai">Sesuai</button>
          <button type="button" class="btn btn-sm rd-sesuai-btn <?= !(abs($it['actual'] - $it['liveTarget']) < 0.01 && $it['actual'] > 0) ? 'btn-danger' : 'btn-secondary' ?>" data-value="tidak_sesuai">Tidak Sesuai</button>
        </div>
        <?php else: ?><span style="color:var(--text-faint);">-</span><?php endif; ?>
      </td>
      <td class="num">
        <?php if ($editable): ?>
        <input type="number" step="0.01" min="0" style="width:6rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="actual" value="<?= ui_fmt_num($it['actual']) ?>" <?= abs($it['actual'] - $it['liveTarget']) < 0.01 && $it['actual'] > 0 ? 'disabled' : '' ?>>
        <?php else: ?><?= ui_fmt_num($it['actual']) ?><?php endif; ?>
      </td>
      <td class="num">
        <?php if ($editable): ?>
        <input type="number" step="0.01" min="0" style="width:6rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="reject" value="<?= ui_fmt_num($it['reject']) ?>">
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
          <button type="button" class="btn btn-sm nr-sesuai-btn <?= !(abs($nr['aktual'] - $nr['target']) < 0.01 && $nr['aktual'] > 0) ? 'btn-danger' : 'btn-secondary' ?>" data-value="tidak_sesuai">Tidak Sesuai</button>
        </div>
      </td>
      <td class="num">
        <input type="number" step="0.01" min="0" style="width:6rem;text-align:right;" class="nr-actual" data-item-id="<?= $nr['itemId'] ?>" data-order-id="<?= $nr['orderId'] ?>" data-order-version="<?= $nr['orderVersion'] ?>" value="<?= ui_fmt_num($nr['aktual']) ?>" <?= abs($nr['aktual'] - $nr['target']) < 0.01 && $nr['aktual'] > 0 ? 'disabled' : '' ?>>
      </td>
      <td class="num"><input type="number" step="0.01" min="0" style="width:6rem;text-align:right;" class="nr-reject" data-item-id="<?= $nr['itemId'] ?>" value="<?= ui_fmt_num($nr['reject']) ?>"></td>
      <td><?= $nr['aktual'] <= 0.0001 ? ui_badge('Belum Diproduksi') : ($nr['aktual'] + 0.0001 >= $nr['target'] ? ui_badge('Sesuai Target') : ui_badge('Belum Sesuai Target')) ?></td>
      <td><?= $nr['catatan'] ? ui_esc((string) $nr['catatan']) : '-' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($editable): ?>
  <div class="btn-group" style="margin-top:var(--space-4);">
    <button type="button" class="btn btn-primary" id="btn-save-draft">Simpan Draft</button>
    <button type="button" class="btn btn-success" id="btn-submit-run">Submit Produksi</button>
  </div>
  <?php elseif ($runDetail['status'] === 'submitted'): ?>
  <div class="btn-group" style="margin-top:var(--space-4);">
    <button type="button" class="btn btn-warning" id="btn-reopen-run">Buka Kembali / Reopen</button>
  </div>
  <?php endif; ?>
  <?php if ($nonRegularRows !== []): ?>
  <div class="btn-group" style="margin-top:var(--space-3);">
    <button type="button" class="btn btn-secondary" id="btn-save-non-regular">Simpan Actual &amp; Reject (Non Reguler)</button>
  </div>
  <?php endif; ?>
  </form>
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
    group.querySelectorAll('.rd-sesuai-btn, .nr-sesuai-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var isSesuai = btn.getAttribute('data-value') === 'sesuai';
        group.querySelectorAll('.rd-sesuai-btn, .nr-sesuai-btn').forEach(function (b) {
          var bIsSesuai = b.getAttribute('data-value') === 'sesuai';
          b.className = 'btn btn-sm ' + (b === btn ? (isSesuai ? 'btn-primary' : 'btn-danger') : 'btn-secondary');
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

  // Cari Produk / Sumber filters (client-side, same pattern as Task per
  // Divisi's own filter bar) over the already-rendered combined rows.
  var searchEl = document.getElementById('rd-search');
  var sourceEl = document.getElementById('rd-source-filter');
  function applyFilters() {
    var q = (searchEl.value || '').toLowerCase().trim();
    var src = sourceEl.value;
    document.querySelectorAll('#rd-table tbody tr.rd-row').forEach(function (tr) {
      var rowSrc = tr.getAttribute('data-source');
      var matchQ = q === '' || tr.getAttribute('data-search').indexOf(q) !== -1;
      var matchSrc = src === '' || (src === 'non_reguler' ? rowSrc !== 'po_reguler' : rowSrc === src);
      tr.style.display = (matchQ && matchSrc) ? '' : 'none';
    });
  }
  if (searchEl) { searchEl.addEventListener('input', applyFilters); sourceEl.addEventListener('change', applyFilters); }

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

  var saveNonRegularBtn = document.getElementById('btn-save-non-regular');
  if (saveNonRegularBtn) saveNonRegularBtn.addEventListener('click', async function () {
    saveNonRegularBtn.disabled = true;
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
    try {
      for (var orderId in byOrder) {
        if (!byOrder.hasOwnProperty(orderId)) continue;
        await Amor.apiFetch('/api/special-orders/' + orderId + '/actual', { method: 'POST', body: byOrder[orderId] });
      }
      Amor.toast('Actual & Reject Non Reguler disimpan.', 'success');
      setTimeout(function () { location.reload(); }, 500);
    } catch (e) { Amor.toast(e.message, 'danger'); saveNonRegularBtn.disabled = false; }
  });

  var saveBtn = document.getElementById('btn-save-draft');
  if (saveBtn) saveBtn.addEventListener('click', async function () {
    saveBtn.disabled = true;
    try {
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
