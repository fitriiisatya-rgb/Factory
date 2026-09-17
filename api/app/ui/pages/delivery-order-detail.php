<?php

declare(strict_types=1);

use Amor\Api\Delivery\DoRepository;
use Amor\Api\Delivery\DoService;

$doId = isset($_GET['doId']) ? (int) $_GET['doId'] : 0;
$service = new DoService($pdo);
$repo = new DoRepository();

$do = null;
$viewError = null;
try {
    $do = $service->getDo($doId);
} catch (\Throwable $e) {
    $viewError = $e->getMessage();
}

$shipments = [];
if ($do !== null) {
    foreach ($repo->findShipmentsForDo($pdo, $doId) as $sh) {
        $sh['items'] = $repo->findShipmentItems($pdo, (int) $sh['shipment_id']);
        $shipments[] = $sh;
    }
}
?>
<?php if ($viewError !== null): ?>
<div class="alert alert-danger"><?= ui_esc($viewError) ?></div>
<?php else: ?>
<?php $editable = in_array($do['status'], ['draft', 'preprinted'], true); ?>

<div class="card section" id="do-form" data-do-id="<?= (int) $do['doId'] ?>" data-expected-version="<?= (int) $do['version'] ?>">
  <div class="card-head">
    <div>
      <h2 class="card-title"><?= ui_esc($do['docNo']) ?></h2>
      <div class="page-subtitle" style="margin-top:4px;"><?= ui_esc((string) $do['storeName']) ?> &middot; <?= ui_esc($do['tanggal']) ?></div>
    </div>
    <?= ui_badge(ui_do_status_label($do['status'])) ?>
  </div>

  <?php if ($do['sourcePoChanged']): ?>
  <div class="alert alert-warning">PO sumber toko ini sudah berubah versi sejak DO dibuat/disegarkan terakhir kali. Item DO tidak diubah otomatis — gunakan "Segarkan dari PO" di bawah untuk menyamakan.</div>
  <?php endif; ?>
  <?php if ($do['cancelledAt'] !== null): ?>
  <div class="alert alert-danger">Dibatalkan pada <?= ui_esc((string) $do['cancelledAt']) ?> — alasan: <?= ui_esc((string) $do['cancelReason']) ?></div>
  <?php endif; ?>

  <div class="kpi-grid" style="grid-template-columns:repeat(6,minmax(0,1fr));">
    <?= ui_kpi_card(['label' => 'Planned', 'value' => ui_fmt_num($do['summary']['totalPlanned']), 'icon' => 'cart', 'color' => 'primary']) ?>
    <?= ui_kpi_card(['label' => 'Shipped', 'value' => ui_fmt_num($do['summary']['totalShipped']), 'icon' => 'truck', 'color' => 'success']) ?>
    <?= ui_kpi_card(['label' => 'Remaining', 'value' => ui_fmt_num($do['summary']['totalRemaining']), 'icon' => 'box', 'color' => 'warning']) ?>
    <?= ui_kpi_card(['label' => 'Belum Dikirim', 'value' => (string) $do['summary']['jumlahBelumDikirim'], 'icon' => 'file', 'color' => 'neutral']) ?>
    <?= ui_kpi_card(['label' => 'Sebagian Dikirim', 'value' => (string) $do['summary']['jumlahSebagianDikirim'], 'icon' => 'file', 'color' => 'warning']) ?>
    <?= ui_kpi_card(['label' => 'Terkirim Penuh', 'value' => (string) $do['summary']['jumlahTerkirimPenuh'], 'icon' => 'file', 'color' => 'success']) ?>
  </div>

  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Produk</th><th>Divisi</th><th class="num">Planned</th><th class="num">Sudah Dikirim</th><th class="num">Sisa</th><th class="num">FG Available</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($do['items'] as $it): ?>
    <tr>
      <td><?= ui_esc($it['productName']) ?></td>
      <td><?= ui_esc((string) $it['divisionName']) ?></td>
      <td class="num"><?= ui_fmt_num($it['plannedQty']) ?></td>
      <td class="num"><?= ui_fmt_num($it['alreadyShippedQty']) ?></td>
      <td class="num"><?= ui_fmt_num($it['remainingToShip']) ?></td>
      <td class="num"><?= $it['fgAvailable'] !== null ? ui_fmt_num($it['fgAvailable']) : '-' ?></td>
      <td><?= ui_badge($it['itemStatusLabel']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:var(--space-4);flex-wrap:wrap;gap:var(--space-3);">
    <div class="btn-group">
      <a class="btn btn-secondary" href="/api/_ui-preview/print-do.php?doId=<?= (int) $do['doId'] ?>" target="_blank">Print Preview</a>
      <?php if ($editable): ?>
      <button type="button" class="btn btn-secondary" id="btn-preprint">Tandai Preprint</button>
      <button type="button" class="btn btn-secondary" id="btn-refresh-po">Segarkan dari PO</button>
      <?php endif; ?>
      <?php if ($editable && (float) $do['summary']['totalRemaining'] > 0.0001): ?>
      <a class="btn btn-primary" href="/api/_ui-preview/?page=pengiriman&doId=<?= (int) $do['doId'] ?>">Buat Pengiriman</a>
      <?php endif; ?>
    </div>
    <?php if ($editable && (float) $do['summary']['totalShipped'] <= 0.0001): ?>
    <button type="button" class="btn btn-danger" id="btn-cancel-do">Batalkan DO</button>
    <?php endif; ?>
  </div>
</div>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Riwayat Pengiriman</h2></div>
  <?php if ($shipments === []): ?>
    <?= ui_empty_state('Belum ada pengiriman', 'Gunakan tombol "Buat Pengiriman" di atas untuk mengirim toko ini.') ?>
  <?php else: foreach ($shipments as $sh): ?>
  <div class="table-scroll" style="margin-bottom:var(--space-4);"><table class="data-table">
    <thead><tr><th colspan="3">Shipment #<?= (int) $sh['shipment_id'] ?> &middot; Grup <?= ui_esc((string) $sh['shipment_group']) ?> &middot; <?= ui_badge(ui_shipment_status_label((string) $sh['status'])) ?> &middot; <?= ui_esc((string) $sh['shipped_at']) ?></th></tr>
    <tr><th>Produk</th><th class="num">Qty</th><th>Catatan</th></tr></thead>
    <tbody>
    <?php foreach ($sh['items'] as $it): ?>
    <tr><td><?= ui_esc($it['product_name']) ?></td><td class="num"><?= ui_fmt_num((float) $it['qty']) ?></td><td><?= ui_esc((string) ($it['notes'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endforeach; endif; ?>
</div>

<script>
(function () {
  var form = document.getElementById('do-form');
  var doId = form.getAttribute('data-do-id');
  var version = parseInt(form.getAttribute('data-expected-version'), 10);

  var preprintBtn = document.getElementById('btn-preprint');
  if (preprintBtn) preprintBtn.addEventListener('click', async function () {
    preprintBtn.disabled = true;
    try {
      await Amor.apiFetch('/api/do/' + doId + '/preprint', { method: 'POST', body: { expectedVersion: version } });
      Amor.toast('DO ditandai preprinted.', 'success');
      setTimeout(function () { location.reload(); }, 500);
    } catch (e) { Amor.toast(e.message, 'danger'); preprintBtn.disabled = false; }
  });

  var refreshBtn = document.getElementById('btn-refresh-po');
  if (refreshBtn) refreshBtn.addEventListener('click', async function () {
    refreshBtn.disabled = true;
    try {
      await Amor.apiFetch('/api/do/' + doId + '/refresh-po', { method: 'POST', body: { expectedVersion: version } });
      Amor.toast('DO disegarkan dari PO terbaru.', 'success');
      setTimeout(function () { location.reload(); }, 500);
    } catch (e) { Amor.toast(e.message, 'danger'); refreshBtn.disabled = false; }
  });

  var cancelBtn = document.getElementById('btn-cancel-do');
  if (cancelBtn) cancelBtn.addEventListener('click', async function () {
    var ok = await Amor.confirmModal({ title: 'Batalkan DO ini?', body: 'DO ini belum memiliki qty terkirim sama sekali. Tindakan ini tidak bisa dibatalkan.', confirmLabel: 'Ya, Batalkan', danger: true });
    if (!ok) return;
    var reason = prompt('Alasan pembatalan (wajib):');
    if (!reason) return;
    cancelBtn.disabled = true;
    try {
      await Amor.apiFetch('/api/do/' + doId + '/cancel', { method: 'POST', body: { expectedVersion: version, reason: reason } });
      Amor.toast('DO dibatalkan.', 'success');
      setTimeout(function () { location.reload(); }, 500);
    } catch (e) { Amor.toast(e.message, 'danger'); cancelBtn.disabled = false; }
  });
})();
</script>
<?php endif; ?>
