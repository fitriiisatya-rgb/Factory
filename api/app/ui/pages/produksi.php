<?php

declare(strict_types=1);

use Amor\Api\Production\ProductionService;

$divisionIdParam = isset($_GET['divisionId']) && $_GET['divisionId'] !== '' ? (int) $_GET['divisionId'] : null;
$statusParam = isset($_GET['status']) && $_GET['status'] !== '' ? (string) $_GET['status'] : null;
$runIdParam = isset($_GET['runId']) ? (int) $_GET['runId'] : null;

$divisions = $pdo->prepare('SELECT division_id, name FROM division WHERE factory_id = ? AND is_verification = 0 ORDER BY name');
$divisions->execute([$uiFactoryId]);
$divisionRows = $divisions->fetchAll();

$service = new ProductionService($pdo);

// Aggregate KPIs across every item matching the current filters (tanggal+factory[+division][+status]).
$sql = 'SELECT pi.target, pi.aktual FROM production_item pi
        INNER JOIN production_run r ON r.production_run_id = pi.production_run_id
        INNER JOIN division d ON d.division_id = r.division_id
        WHERE r.tanggal = ? AND d.factory_id = ?';
$params = [$uiTanggal, $uiFactoryId];
if ($divisionIdParam !== null) {
    $sql .= ' AND r.division_id = ?';
    $params[] = $divisionIdParam;
}
if ($statusParam !== null) {
    $sql .= ' AND r.status = ?';
    $params[] = $statusParam;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$kpi = ['target' => 0.0, 'actual' => 0.0, 'notProduced' => 0, 'belowTarget' => 0, 'onTarget' => 0, 'over' => 0];
foreach ($stmt->fetchAll() as $row) {
    $target = (float) $row['target'];
    $actual = (float) $row['aktual'];
    $kpi['target'] += $target;
    $kpi['actual'] += $actual;
    $remaining = max(0.0, $target - $actual);
    $over = max(0.0, $actual - $target);
    if ($actual <= 0.0001) {
        $kpi['notProduced']++;
    } elseif ($over > 0.0001) {
        $kpi['over']++;
    } elseif ($remaining > 0.0001) {
        $kpi['belowTarget']++;
    } else {
        $kpi['onTarget']++;
    }
}
$kpi['remaining'] = max(0.0, $kpi['target'] - $kpi['actual']);

// Run list (one row per division/day) matching filters.
$runsSql = "SELECT r.*, d.name AS division_name FROM production_run r
            INNER JOIN division d ON d.division_id = r.division_id
            WHERE r.tanggal = ? AND d.factory_id = ?";
$runsParams = [$uiTanggal, $uiFactoryId];
if ($divisionIdParam !== null) {
    $runsSql .= ' AND r.division_id = ?';
    $runsParams[] = $divisionIdParam;
}
if ($statusParam !== null) {
    $runsSql .= ' AND r.status = ?';
    $runsParams[] = $statusParam;
}
$runsSql .= ' ORDER BY d.name';
$runsStmt = $pdo->prepare($runsSql);
$runsStmt->execute($runsParams);
$runRows = $runsStmt->fetchAll();

$divisionsWithRun = array_column($runRows, 'division_id');
$divisionsWithoutRun = array_filter($divisionRows, static fn ($d) => !in_array((int) $d['division_id'], array_map('intval', $divisionsWithRun), true));

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
    <?php if ($runRows === [] && $divisionsWithoutRun === []): ?>
    <tr><td colspan="6"><?= ui_empty_state('Tidak ada divisi produksi', 'Pabrik ini belum punya divisi produksi.') ?></td></tr>
    <?php endif; ?>
    <?php foreach ($runRows as $r):
      $itemsSum = $pdo->prepare('SELECT COALESCE(SUM(target),0) t, COALESCE(SUM(aktual),0) a FROM production_item WHERE production_run_id = ?');
      $itemsSum->execute([$r['production_run_id']]);
      $sums = $itemsSum->fetch();
      $target = (float) $sums['t']; $actual = (float) $sums['a'];
    ?>
    <tr>
      <td><?= ui_esc($r['division_name']) ?></td>
      <td class="num"><?= ui_fmt_num($target) ?></td>
      <td class="num"><?= ui_fmt_num($actual) ?></td>
      <td class="num"><?= ui_fmt_num(max(0.0, $target - $actual)) ?></td>
      <td><?= ui_badge(ui_doc_status_label($r['status'])) ?></td>
      <td><a class="btn btn-primary btn-sm" href="/api/_ui-preview/?page=produksi&tanggal=<?= urlencode($uiTanggal) ?>&factoryId=<?= $uiFactoryId ?>&runId=<?= (int) $r['production_run_id'] ?>">Buka</a></td>
    </tr>
    <?php endforeach; ?>
    <?php foreach ($divisionsWithoutRun as $d): ?>
    <tr>
      <td><?= ui_esc($d['name']) ?></td>
      <td class="num">-</td><td class="num">-</td><td class="num">-</td>
      <td><?= ui_badge('Belum Dimulai') ?></td>
      <td>
        <button type="button" class="btn btn-secondary btn-sm" data-action="create-run" data-division-id="<?= (int) $d['division_id'] ?>">Buat/Buka Draft</button>
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
    <thead><tr><th>Produk</th><th class="num">Target</th><th class="num">Actual</th><th>Status</th><th>Catatan</th></tr></thead>
    <tbody>
    <?php foreach ($runDetail['items'] as $it): ?>
    <tr>
      <td><?= ui_esc($it['productName']) ?></td>
      <td class="num"><?= ui_fmt_num($it['target']) ?></td>
      <td class="num">
        <?php if ($editable): ?>
        <input type="number" step="0.01" min="0" style="width:6rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="actual" value="<?= ui_fmt_num($it['actual']) ?>">
        <?php else: ?><?= ui_fmt_num($it['actual']) ?><?php endif; ?>
      </td>
      <td><?= ui_badge($it['displayStatusLabel']) ?></td>
      <td><?php if ($editable): ?><input type="text" style="width:9rem;" data-product-id="<?= (int) $it['productId'] ?>" data-field="keterangan" value="<?= ui_esc((string) ($it['keterangan'] ?? '')) ?>"><?php else: ?><?= ui_esc((string) ($it['keterangan'] ?? '')) ?><?php endif; ?></td>
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
      var notesInput = document.querySelector('#run-form [data-field="keterangan"][data-product-id="' + pid + '"]');
      items.push({ productId: parseInt(pid, 10), actualQty: parseFloat(input.value || '0'), keterangan: notesInput ? notesInput.value : '' });
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
