<?php

declare(strict_types=1);

use Amor\Api\Production\ProductionService;
use Amor\Api\Production\ProductionTargetService;

$divisionIdParam = isset($_GET['divisionId']) && $_GET['divisionId'] !== '' ? (int) $_GET['divisionId'] : null;
$statusParam = isset($_GET['status']) && $_GET['status'] !== '' ? (string) $_GET['status'] : null;
$runIdParam = isset($_GET['runId']) ? (int) $_GET['runId'] : null;

$divisions = $pdo->prepare('SELECT division_id, name FROM division WHERE factory_id = ? AND is_verification = 0 ORDER BY name');
$divisions->execute([$uiFactoryId]);
$divisionRows = $divisions->fetchAll();

$service = new ProductionService($pdo);
$targetService = new ProductionTargetService();

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
$actualSql = 'SELECT r.production_run_id, r.division_id, r.status, COALESCE(SUM(pi.aktual), 0) AS actual_sum
              FROM production_run r
              INNER JOIN division d ON d.division_id = r.division_id
              LEFT JOIN production_item pi ON pi.production_run_id = r.production_run_id
              WHERE r.tanggal = ? AND d.factory_id = ?';
$actualParams = [$uiTanggal, $uiFactoryId];
if ($divisionIdParam !== null) {
    $actualSql .= ' AND r.division_id = ?';
    $actualParams[] = $divisionIdParam;
}
if ($statusParam !== null) {
    $actualSql .= ' AND r.status = ?';
    $actualParams[] = $statusParam;
}
$actualSql .= ' GROUP BY r.production_run_id, r.division_id, r.status';
$actualStmt = $pdo->prepare($actualSql);
$actualStmt->execute($actualParams);
$runByDivision = [];
foreach ($actualStmt->fetchAll() as $row) {
    $runByDivision[(int) $row['division_id']] = [
        'runId' => (int) $row['production_run_id'],
        'status' => $row['status'],
        'actual' => (float) $row['actual_sum'],
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
    // classifyDisplayStatus() always returns not_produced when actual<=0
    // (which includes the "no production_run at all yet" case, actual=0),
    // so no special-case branch is needed here.
    $kpiKey = ['not_produced' => 'notProduced', 'below_target' => 'belowTarget', 'on_target' => 'onTarget', 'overproduction' => 'over'][$displayStatus['code']];
    $kpi[$kpiKey]++;

    $divisionSummaryRows[] = [
        'divisionId' => $divId,
        'divisionName' => $d['name'],
        'target' => $target,
        'actual' => $actual,
        'remaining' => $remaining,
        'runId' => $run['runId'] ?? null,
        'statusLabel' => $run !== null ? ui_doc_status_label($run['status']) : 'Belum Dimulai',
    ];
}
$kpi['remaining'] = max(0.0, $kpi['target'] - $kpi['actual']);

$runDetail = null;
$runDetailError = null;
if ($runIdParam !== null) {
    try {
        $runDetail = $service->getRun($runIdParam);
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

<div class="table-card section">
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Divisi</th><th class="num">Target</th><th class="num">Actual</th><th class="num">Sisa</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if ($divisionSummaryRows === []): ?>
    <tr><td colspan="6"><?= ui_empty_state('Tidak ada divisi produksi', 'Pabrik ini belum punya divisi produksi.') ?></td></tr>
    <?php endif; ?>
    <?php foreach ($divisionSummaryRows as $row): ?>
    <tr>
      <td><?= ui_esc($row['divisionName']) ?></td>
      <td class="num"><?= ui_fmt_num($row['target']) ?></td>
      <td class="num"><?= ui_fmt_num($row['actual']) ?></td>
      <td class="num"><?= ui_fmt_num($row['remaining']) ?></td>
      <td><?= ui_badge($row['statusLabel']) ?></td>
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
  <?php $editable = in_array($runDetail['status'], ['draft', 'reopened'], true); ?>
  <div class="card-head">
    <h2 class="card-title"><?= ui_esc($runDetail['divisionName']) ?> — <?= ui_esc($runDetail['tanggal']) ?></h2>
    <?= ui_badge(ui_doc_status_label($runDetail['status'])) ?>
  </div>
  <?php if (!empty($runDetail['reopenReason'])): ?>
  <div class="alert alert-warning">Alasan dibuka kembali: <?= ui_esc((string) $runDetail['reopenReason']) ?></div>
  <?php endif; ?>

  <form id="run-form" data-run-id="<?= (int) $runDetail['productionRunId'] ?>" data-expected-version="<?= (int) $runDetail['version'] ?>">
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Produk</th><th class="num">Target</th><th class="num">Actual</th><th class="num">Reject Produksi</th><th>Status</th><th>Catatan</th></tr></thead>
    <tbody>
    <?php foreach ($runDetail['items'] as $it): ?>
    <tr>
      <td><?= ui_esc($it['productName']) ?></td>
      <td class="num"><?= ui_fmt_num($it['liveTarget']) ?><?php if ($it['targetChangedSinceDraft']): ?> <span class="badge badge-warning" title="Target PO berubah sejak draft dibuat">berubah</span><?php endif; ?></td>
      <td class="num">
        <?php if ($editable): ?>
        <input type="number" step="0.01" min="0" style="width:6rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="actual" value="<?= ui_fmt_num($it['actual']) ?>">
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
  </form>
  <?php else: ?>
    <?= ui_empty_state('Draft tidak ditemukan', '') ?>
  <?php endif; ?>
</div>

<script>
(function () {
  function collectItems() {
    var items = [];
    document.querySelectorAll('#run-form [data-field="actual"]').forEach(function (input) {
      var pid = input.getAttribute('data-product-id');
      var rejectInput = document.querySelector('#run-form [data-field="reject"][data-product-id="' + pid + '"]');
      var notesInput = document.querySelector('#run-form [data-field="keterangan"][data-product-id="' + pid + '"]');
      items.push({
        productId: parseInt(pid, 10),
        actualQty: parseFloat(input.value || '0'),
        rejectQty: parseFloat(rejectInput ? (rejectInput.value || '0') : '0'),
        notes: notesInput ? notesInput.value : '',
      });
    });
    return items;
  }
  var form = document.getElementById('run-form');
  if (!form) return;
  var runId = form.getAttribute('data-run-id');
  var version = parseInt(form.getAttribute('data-expected-version'), 10);

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
