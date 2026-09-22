<?php

declare(strict_types=1);

use Amor\Api\ApiException;
use Amor\Api\SpecialOrder\SpecialOrderService;

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$service = new SpecialOrderService($pdo);
$order = null;
$viewError = null;
try {
    $order = $service->getOrder($id);
} catch (ApiException $e) {
    $viewError = $e->getMessage();
}
$sourceLabels = ['konsumen_langsung' => 'Konsumen Langsung', 'cs' => 'CS', 'sales_executive' => 'Sales Executive', 'umum' => 'Umum'];
?>
<?php if ($viewError !== null): ?>
<div class="alert alert-danger"><?= ui_esc($viewError) ?></div>
<a class="btn btn-secondary" href="?page=pesanan-non-toko">&larr; Kembali ke Pesanan Non-Toko</a>
<?php else: ?>
<a class="btn btn-secondary" style="margin-bottom:var(--space-3);" href="?page=pesanan-non-toko">&larr; Kembali ke Pesanan Non-Toko</a>

<div class="card section">
  <div class="card-head">
    <div>
      <h2 class="card-title"><?= ui_esc($order['orderNo']) ?></h2>
      <div class="page-subtitle" style="margin-top:4px;"><?= ui_esc($sourceLabels[$order['nonStoreSource']] ?? (string) $order['nonStoreSource']) ?> &middot; <?= ui_esc((string) $order['customerName']) ?><?= $order['isMultiDivision'] ? ' &middot; Multi Divisi' : '' ?><?= $order['isMultiFactory'] ? ' &middot; Multi Factory' : '' ?></div>
    </div>
    <?= ui_badge(ui_special_order_status_label($order['status'])) ?>
  </div>

  <?= ui_special_order_timeline($order['status']) ?>

  <div class="kpi-grid kpi-grid-4">
    <?= ui_kpi_card(['label' => 'Kontak', 'value' => (string) ($order['customerContact'] ?? '-'), 'icon' => 'user', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Pengiriman / Pickup', 'value' => $order['fulfillmentType'] === 'pengiriman' ? 'Pengiriman' : ($order['fulfillmentType'] === 'pickup' ? 'Pickup' : '-'), 'icon' => 'truck', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Tanggal Dibutuhkan', 'value' => $order['requiredDate'] . ($order['requiredTime'] ? ' · ' . substr((string) $order['requiredTime'], 0, 5) : ''), 'icon' => 'chart', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Factory Tujuan', 'value' => $order['isMultiFactory'] ? 'Multi Factory (' . count($order['factoryNames']) . ')' : (string) ($order['factoryNames'][0] ?? '-'), 'icon' => 'box', 'color' => 'neutral', 'detail' => true]) ?>
  </div>
  <?php if (($order['deliveryAddress'] ?? '') !== ''): ?>
  <div class="alert alert-info" style="margin-top:var(--space-3);"><b>Alamat/Tujuan:</b> <?= ui_esc((string) $order['deliveryAddress']) ?></div>
  <?php endif; ?>
  <?php if (($order['generalNote'] ?? '') !== ''): ?>
  <div class="alert alert-info" style="margin-top:var(--space-3);"><b>Catatan Umum:</b> <?= nl2br(ui_esc((string) $order['generalNote'])) ?></div>
  <?php endif; ?>
  <?php if ($order['status'] === 'cancelled' && ($order['cancelReason'] ?? '') !== ''): ?>
  <div class="alert alert-danger" style="margin-top:var(--space-3);"><b>Dibatalkan:</b> <?= ui_esc((string) $order['cancelReason']) ?></div>
  <?php endif; ?>

  <h3 class="card-title" style="margin:var(--space-4) 0 var(--space-2);">Item Pesanan</h3>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Item</th><th>Tipe</th><th>Divisi Produksi</th><th>Factory</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Charge</th><th class="num">Subtotal</th><th>Catatan Khusus</th></tr></thead>
    <tbody>
    <?php foreach ($order['items'] as $it): ?>
    <tr>
      <td><?= ui_esc($it['itemName']) ?></td>
      <td><?= $it['itemType'] === 'existing_product' ? '<span class="badge badge-success">Produk Existing</span>' : '<span class="badge badge-warning">Item Khusus</span>' ?></td>
      <td><span class="badge badge-primary"><?= ui_esc($it['divisionName']) ?></span></td>
      <td><span class="badge badge-neutral"><?= ui_esc($it['factoryName']) ?></span></td>
      <td class="num"><?= ui_fmt_num($it['qty']) ?></td>
      <td class="num"><?= ui_fmt_num($it['unitPrice']) ?></td>
      <td class="num"><?= ui_fmt_num($it['charge']) ?></td>
      <td class="num"><?= ui_fmt_num($it['subtotal']) ?></td>
      <td style="max-width:220px;overflow-wrap:anywhere;"><?= $it['specialNote'] ? ui_esc($it['specialNote']) : '-' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <div class="subpanel section" style="margin-top:var(--space-4);">
    <div class="subpanel-title">Informasi Produksi</div>
    <?= ui_special_order_routing_info($order['productionRouting']) ?>
  </div>

  <div style="margin-top:var(--space-4);display:flex;gap:var(--space-3);align-items:center;flex-wrap:wrap;">
    <?php if ($order['status'] === 'draft'): ?>
      <button type="button" class="btn btn-primary" id="btn-confirm-order" data-id="<?= (int) $order['orderId'] ?>" data-version="<?= (int) $order['version'] ?>">Konfirmasi Pesanan</button>
      <button type="button" class="btn btn-secondary" id="btn-cancel-order" data-id="<?= (int) $order['orderId'] ?>" data-version="<?= (int) $order['version'] ?>">Batalkan</button>
    <?php elseif ($order['status'] === 'confirmed'): ?>
      <button type="button" class="btn btn-primary" id="btn-send-production" data-id="<?= (int) $order['orderId'] ?>" data-version="<?= (int) $order['version'] ?>">Kirim ke Produksi</button>
      <button type="button" class="btn btn-secondary" id="btn-cancel-order" data-id="<?= (int) $order['orderId'] ?>" data-version="<?= (int) $order['version'] ?>">Batalkan</button>
    <?php elseif (in_array($order['status'], ['sent_to_production', 'in_production', 'ready'], true)): ?>
      <span style="color:var(--text-faint);">Status Produksi:</span>
      <button type="button" class="btn btn-secondary btn-sm" data-status-btn="in_production" data-id="<?= (int) $order['orderId'] ?>" data-version="<?= (int) $order['version'] ?>">Sedang Diproduksi</button>
      <button type="button" class="btn btn-secondary btn-sm" data-status-btn="ready" data-id="<?= (int) $order['orderId'] ?>" data-version="<?= (int) $order['version'] ?>">Siap</button>
      <button type="button" class="btn btn-secondary btn-sm" data-status-btn="completed" data-id="<?= (int) $order['orderId'] ?>" data-version="<?= (int) $order['version'] ?>">Selesai</button>
      <button type="button" class="btn btn-secondary" id="btn-cancel-order" data-id="<?= (int) $order['orderId'] ?>" data-version="<?= (int) $order['version'] ?>">Batalkan</button>
    <?php else: ?>
      <span style="color:var(--text-faint);">Pesanan ini sudah <?= mb_strtolower(ui_special_order_status_label($order['status'])) ?> — tidak ada tindakan lagi.</span>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  async function withReload(fn) {
    try { await fn(); window.location.reload(); } catch (e) { Amor.toast(e.message, 'error'); }
  }
  var confirmBtn = document.getElementById('btn-confirm-order');
  if (confirmBtn) {
    confirmBtn.addEventListener('click', function () {
      withReload(function () {
        return Amor.apiFetch('/api/special-orders/' + confirmBtn.dataset.id + '/confirm', { method: 'POST', body: { expectedVersion: parseInt(confirmBtn.dataset.version, 10) } });
      });
    });
  }
  var sendBtn = document.getElementById('btn-send-production');
  if (sendBtn) {
    sendBtn.addEventListener('click', function () {
      withReload(function () {
        return Amor.apiFetch('/api/special-orders/' + sendBtn.dataset.id + '/send-to-production', { method: 'POST', body: { expectedVersion: parseInt(sendBtn.dataset.version, 10) } });
      });
    });
  }
  document.querySelectorAll('[data-status-btn]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      withReload(function () {
        return Amor.apiFetch('/api/special-orders/' + btn.dataset.id + '/status', { method: 'POST', body: { expectedVersion: parseInt(btn.dataset.version, 10), status: btn.dataset.statusBtn } });
      });
    });
  });
  var cancelBtn = document.getElementById('btn-cancel-order');
  if (cancelBtn) {
    cancelBtn.addEventListener('click', async function () {
      var ok = await Amor.confirmModal({ title: 'Batalkan Pesanan', body: 'Tindakan ini tidak dapat dibatalkan. Masukkan alasan pembatalan pada dialog berikutnya.', confirmLabel: 'Ya, Batalkan', danger: true });
      if (!ok) return;
      var reason = prompt('Alasan pembatalan:');
      if (!reason) return;
      withReload(function () {
        return Amor.apiFetch('/api/special-orders/' + cancelBtn.dataset.id + '/cancel', { method: 'POST', body: { expectedVersion: parseInt(cancelBtn.dataset.version, 10), reason: reason } });
      });
    });
  }
})();
</script>
<?php endif; ?>
