<?php

declare(strict_types=1);

use Amor\Api\ApiException;
use Amor\Api\SpecialOrder\SpecialOrderDoService;

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$service = new SpecialOrderDoService($pdo);
$do = null;
$viewError = null;
try {
    $do = $service->getDo($id);
} catch (ApiException $e) {
    $viewError = $e->getMessage();
}
?>
<?php if ($viewError !== null): ?>
<div class="alert alert-danger"><?= ui_esc($viewError) ?></div>
<a class="btn btn-secondary" href="?page=delivery-order-khusus-non-toko">&larr; Kembali ke DO Pesanan Khusus / Non-Toko</a>
<?php else: ?>
<a class="btn btn-secondary" style="margin-bottom:var(--space-3);" href="?page=delivery-order-khusus-non-toko">&larr; Kembali ke DO Pesanan Khusus / Non-Toko</a>

<div class="card section">
  <div class="card-head">
    <div>
      <h2 class="card-title"><?= ui_esc($do['docNo']) ?></h2>
      <div class="page-subtitle" style="margin-top:4px;"><span class="badge badge-neutral"><?= ui_esc($do['sourceLabel']) ?></span> &middot; Pesanan <?= ui_esc($do['orderNo']) ?></div>
    </div>
    <?= ui_badge(ucfirst($do['status'])) ?>
  </div>

  <div class="kpi-grid kpi-grid-4">
    <?= ui_kpi_card(['label' => 'Tanggal', 'value' => (string) $do['tanggal'], 'icon' => 'chart', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Toko/Customer', 'value' => (string) ($do['sourceType'] === 'toko_khusus' ? ($do['storeName'] ?? '-') : ($do['customerName'] ?? '-')), 'icon' => 'user', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Kontak', 'value' => (string) ($do['customerContact'] ?? '-'), 'icon' => 'user', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Dibuat Oleh', 'value' => (string) ($do['createdByName'] ?? '-'), 'icon' => 'user', 'color' => 'neutral', 'detail' => true]) ?>
  </div>
  <?php if (($do['deliveryAddress'] ?? '') !== ''): ?>
  <div class="alert alert-info" style="margin-top:var(--space-3);"><b>Alamat/Tujuan:</b> <?= ui_esc((string) $do['deliveryAddress']) ?></div>
  <?php endif; ?>
  <?php if ($do['status'] === 'cancelled' && ($do['cancelReason'] ?? '') !== ''): ?>
  <div class="alert alert-danger" style="margin-top:var(--space-3);"><b>Dibatalkan:</b> <?= ui_esc((string) $do['cancelReason']) ?></div>
  <?php endif; ?>

  <h3 class="card-title" style="margin:var(--space-4) 0 var(--space-2);">Item DO</h3>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Item</th><th>Divisi</th><th>Factory</th><th class="num">Qty Direncanakan</th><th class="num">Qty Aktual Dikirim</th></tr></thead>
    <tbody>
    <?php foreach ($do['items'] as $it): ?>
    <tr>
      <td><?= ui_esc($it['itemName']) ?></td>
      <td><span class="badge badge-primary"><?= ui_esc($it['divisionName']) ?></span></td>
      <td><span class="badge badge-neutral"><?= ui_esc($it['factoryName']) ?></span></td>
      <td class="num"><?= ui_fmt_num($it['plannedQty']) ?></td>
      <td class="num"><?= $it['actualShipQty'] !== null ? ui_fmt_num($it['actualShipQty']) : '-' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <div style="margin-top:var(--space-4);display:flex;gap:var(--space-3);align-items:center;flex-wrap:wrap;">
    <?php if (in_array($do['status'], ['draft', 'ready'], true)): ?>
      <button type="button" class="btn btn-primary" id="btn-ship-do" data-id="<?= (int) $do['doId'] ?>" data-version="<?= (int) $do['version'] ?>">Kirim DO</button>
      <button type="button" class="btn btn-secondary" id="btn-cancel-do" data-id="<?= (int) $do['doId'] ?>" data-version="<?= (int) $do['version'] ?>">Batalkan</button>
    <?php else: ?>
      <span style="color:var(--text-faint);">DO ini sudah <?= mb_strtolower(ucfirst($do['status'])) ?> — tidak ada tindakan lagi.</span>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  async function withReload(fn) {
    try { await fn(); window.location.reload(); } catch (e) { Amor.toast(e.message, 'danger'); }
  }
  var shipBtn = document.getElementById('btn-ship-do');
  if (shipBtn) {
    shipBtn.addEventListener('click', async function () {
      var ok = await Amor.confirmModal({ title: 'Kirim DO', body: 'DO ini akan ditandai sebagai dikirim. Lanjutkan?' });
      if (!ok) return;
      withReload(function () {
        return Amor.apiFetch('/api/special-order-do/' + shipBtn.dataset.id + '/ship', { method: 'POST', body: { expectedVersion: parseInt(shipBtn.dataset.version, 10) } });
      });
    });
  }
  var cancelBtn = document.getElementById('btn-cancel-do');
  if (cancelBtn) {
    cancelBtn.addEventListener('click', async function () {
      var ok = await Amor.confirmModal({ title: 'Batalkan DO', body: 'Tindakan ini tidak dapat dibatalkan. Masukkan alasan pembatalan pada dialog berikutnya.', confirmLabel: 'Ya, Batalkan', danger: true });
      if (!ok) return;
      var reason = prompt('Alasan pembatalan:');
      if (!reason) return;
      withReload(function () {
        return Amor.apiFetch('/api/special-order-do/' + cancelBtn.dataset.id + '/cancel', { method: 'POST', body: { expectedVersion: parseInt(cancelBtn.dataset.version, 10), reason: reason } });
      });
    });
  }
})();
</script>
<?php endif; ?>
