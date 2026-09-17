<?php

declare(strict_types=1);

use Amor\Api\Delivery\DoService;

$statusParam = isset($_GET['status']) && $_GET['status'] !== '' ? (string) $_GET['status'] : null;
$searchTerm = trim((string) ($_GET['q'] ?? ''));

$service = new DoService($pdo);
$storesWithPo = $service->storesWithPo($uiTanggal, $uiFactoryId);
$doRows = $service->listDosForFactory($uiTanggal, $uiFactoryId);
$doByStore = [];
foreach ($doRows as $d) {
    $doByStore[$d['storeId']] = $d;
}

$kpi = ['totalToko' => count($storesWithPo), 'dibuat' => 0, 'preprint' => 0, 'sebagian' => 0, 'penuh' => 0];
foreach ($doRows as $d) {
    $kpi['dibuat']++;
    if ($d['status'] === 'preprinted') {
        $kpi['preprint']++;
    }
    if ($d['status'] === 'shipped') {
        $kpi['penuh']++;
    } elseif ((float) $d['totalShipped'] > 0.0001) {
        $kpi['sebagian']++;
    }
}
$kpi['belumDibuat'] = max(0, $kpi['totalToko'] - $kpi['dibuat']);

// Merge: every store with PO gets one row, whether or not a DO exists yet.
$rows = [];
foreach ($storesWithPo as $s) {
    $d = $doByStore[$s['storeId']] ?? null;
    if ($searchTerm !== '' && stripos($s['storeName'], $searchTerm) === false && ($d === null || stripos((string) $d['docNo'], $searchTerm) === false)) {
        continue;
    }
    if ($statusParam !== null && ($d === null || $d['status'] !== $statusParam)) {
        continue;
    }
    $rows[] = ['store' => $s, 'do' => $d];
}
?>
<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="delivery-order">
    <div class="field"><label>Tanggal</label><input type="date" name="tanggal" value="<?= ui_esc($uiTanggal) ?>"></div>
    <div class="field"><label>Pabrik</label>
      <select name="factoryId">
        <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['factory_id'] ?>" <?= $uiFactoryId === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Status</label>
      <select name="status">
        <option value="">Semua Status</option>
        <?php foreach (['draft' => 'Draft', 'preprinted' => 'Sudah Preprint', 'shipped' => 'Terkirim Penuh', 'cancelled' => 'Dibatalkan'] as $code => $label): ?>
        <option value="<?= $code ?>" <?= $statusParam === $code ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Cari DO/Toko</label><input type="text" name="q" value="<?= ui_esc($searchTerm) ?>" placeholder="Nama toko atau no DO..."></div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>
</div>

<div class="kpi-grid">
  <?= ui_kpi_card(['label' => 'Total Toko', 'value' => (string) $kpi['totalToko'], 'icon' => 'building', 'color' => 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'DO Dibuat', 'value' => (string) $kpi['dibuat'], 'icon' => 'file', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'Sudah Preprint', 'value' => (string) $kpi['preprint'], 'icon' => 'file', 'color' => 'warning']) ?>
  <?= ui_kpi_card(['label' => 'Sebagian Dikirim', 'value' => (string) $kpi['sebagian'], 'icon' => 'truck', 'color' => 'warning']) ?>
  <?= ui_kpi_card(['label' => 'Terkirim Penuh', 'value' => (string) $kpi['penuh'], 'icon' => 'truck', 'color' => 'success']) ?>
  <?= ui_kpi_card(['label' => 'Belum Dibuat', 'value' => (string) $kpi['belumDibuat'], 'icon' => 'file', 'color' => 'neutral']) ?>
</div>

<div class="btn-group section">
  <button type="button" class="btn btn-primary" id="btn-bulk-generate">Generate Draft DO Semua Toko</button>
  <a class="btn btn-secondary" href="/api/_ui-preview/print-do-bulk.php?tanggal=<?= urlencode($uiTanggal) ?>&factoryId=<?= $uiFactoryId ?>" target="_blank">Print Semua DO</a>
</div>

<div class="table-card section">
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Toko</th><th>DO No.</th><th class="num">Planned</th><th class="num">Shipped</th><th class="num">Remaining</th><th>Status</th><th>Pengiriman Terakhir</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
    <tr><td colspan="8"><?= ui_empty_state('Tidak ada data', 'Tidak ada toko dengan PO yang cocok untuk tanggal/pabrik/filter ini.') ?></td></tr>
    <?php else: foreach ($rows as $row): $s = $row['store']; $d = $row['do']; ?>
    <tr>
      <td><?= ui_esc($s['storeName']) ?></td>
      <?php if ($d !== null): ?>
      <td><a href="/api/_ui-preview/?page=delivery-order-detail&doId=<?= (int) $d['doId'] ?>"><?= ui_esc($d['docNo']) ?></a></td>
      <td class="num"><?= ui_fmt_num($d['totalPlanned']) ?></td>
      <td class="num"><?= ui_fmt_num($d['totalShipped']) ?></td>
      <td class="num"><?= ui_fmt_num($d['totalRemaining']) ?></td>
      <td><?= ui_badge(ui_do_status_label($d['status'])) ?></td>
      <td><?= $d['lastShipmentAt'] !== null ? ui_esc((string) $d['lastShipmentAt']) : '-' ?></td>
      <td class="row-actions">
        <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=delivery-order-detail&doId=<?= (int) $d['doId'] ?>">Lihat</a>
        <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/print-do.php?doId=<?= (int) $d['doId'] ?>" target="_blank">Print</a>
        <?php if (in_array($d['status'], ['draft', 'preprinted'], true) && (float) $d['totalRemaining'] > 0.0001): ?>
        <a class="btn btn-primary btn-sm" href="/api/_ui-preview/?page=pengiriman&doId=<?= (int) $d['doId'] ?>">Buat Pengiriman</a>
        <?php endif; ?>
      </td>
      <?php else: ?>
      <td colspan="5" style="color:var(--text-faint);">belum ada DO</td>
      <td><?= ui_badge('Belum Dimulai') ?></td>
      <td>
        <button type="button" class="btn btn-primary btn-sm" data-action="create-do" data-store-id="<?= (int) $s['storeId'] ?>">Generate Draft DO</button>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<script>
document.getElementById('btn-bulk-generate').addEventListener('click', async function () {
  var ok = await Amor.confirmModal({ title: 'Generate Draft DO untuk Semua Toko?', body: 'Toko yang sudah punya DO terbuka akan dilewati (aman, tidak duplikat).', confirmLabel: 'Ya, Generate' });
  if (!ok) return;
  this.disabled = true;
  try {
    var data = await Amor.apiFetch('/api/do/generate-bulk', { method: 'POST', body: { tanggal: <?= json_encode($uiTanggal) ?>, factoryId: <?= $uiFactoryId ?> } });
    Amor.toast('Dibuat: ' + data.created + ', sudah ada: ' + data.alreadyExisted + '.', 'success');
    setTimeout(function () { location.reload(); }, 700);
  } catch (e) { Amor.toast(e.message, 'danger'); this.disabled = false; }
});
document.querySelectorAll('[data-action="create-do"]').forEach(function (btn) {
  btn.addEventListener('click', async function () {
    btn.disabled = true;
    try {
      var data = await Amor.apiFetch('/api/do', { method: 'POST', body: { tanggal: <?= json_encode($uiTanggal) ?>, storeId: parseInt(btn.getAttribute('data-store-id'), 10) } });
      Amor.toast('Draft DO dibuat: ' + data.docNo, 'success');
      location.href = '/api/_ui-preview/?page=delivery-order-detail&doId=' + data.doId;
    } catch (e) { Amor.toast(e.message, 'danger'); btn.disabled = false; }
  });
});
</script>
