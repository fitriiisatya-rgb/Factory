<?php

declare(strict_types=1);

use Amor\Api\Invoice\InvoiceService;

$invoiceId = isset($_GET['invoiceId']) ? (int) $_GET['invoiceId'] : 0;
$service = new InvoiceService($pdo);

$invoice = null;
$viewError = null;
try {
    $invoice = $service->getDetail($invoiceId);
} catch (\Throwable $e) {
    $viewError = $e->getMessage();
}
?>
<?php if ($viewError !== null): ?>
<div class="alert alert-danger"><?= ui_esc($viewError) ?></div>
<a class="btn btn-secondary" href="/api/_ui-preview/?page=invoice">&larr; Kembali ke Daftar Invoice</a>
<?php else: ?>
<div class="card section" id="invoice-card" data-invoice-id="<?= (int) $invoice['invoiceId'] ?>">
  <div class="card-head">
    <div>
      <h2 class="card-title"><?= ui_esc($invoice['invoiceNumber']) ?></h2>
      <div class="page-subtitle" style="margin-top:4px;"><?= ui_esc($invoice['customer']['storeName']) ?> &middot; <?= ui_esc($invoice['invoiceDate']) ?></div>
    </div>
    <a class="btn btn-secondary" href="/api/_ui-preview/?page=invoice">&larr; Kembali</a>
  </div>

  <div class="kpi-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));">
    <?= ui_kpi_card(['label' => 'Total Tagihan', 'value' => ui_fmt_money($invoice['summary']['total']), 'icon' => 'box', 'color' => 'success']) ?>
    <?= ui_kpi_card(['label' => 'Jumlah Pengiriman', 'value' => (string) count($invoice['shipments']), 'icon' => 'truck', 'color' => 'primary']) ?>
    <?= ui_kpi_card(['label' => 'Jumlah Mutasi', 'value' => (string) count($invoice['mutasi']), 'icon' => 'bolt', 'color' => 'neutral']) ?>
  </div>

  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Produk</th><th class="num">Qty DO</th><th class="num">Qty Invoice</th><th class="num">Harga</th><th class="num">Subtotal</th></tr></thead>
    <tbody>
    <?php foreach ($invoice['items'] as $it): ?>
    <tr>
      <td><?= ui_esc($it['productName']) ?></td>
      <td class="num"><?= ui_fmt_num($it['qtyDo']) ?></td>
      <td class="num"><?= ui_fmt_num($it['qty']) ?></td>
      <td class="num"><?= ui_fmt_money($it['unitPrice']) ?></td>
      <td class="num"><?= ui_fmt_money($it['subtotal']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:var(--space-4);flex-wrap:wrap;gap:var(--space-3);">
    <div class="btn-group">
      <a class="btn btn-secondary" href="/api/_ui-preview/print-invoice.php?invoiceId=<?= (int) $invoice['invoiceId'] ?>" target="_blank">Print Invoice</a>
    </div>
    <button type="button" class="btn btn-danger" id="btn-void-invoice">Batalkan Invoice</button>
  </div>
</div>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Pengiriman Terkait</h2></div>
  <?php if ($invoice['shipments'] === []): ?>
    <?= ui_empty_state('Tidak ada pengiriman', 'Invoice ini dibuat hanya dari Mutasi.') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Shipment</th><th>Tanggal</th><th>No. DO</th></tr></thead>
    <tbody>
    <?php foreach ($invoice['shipments'] as $sh): ?>
    <tr><td>#<?= (int) $sh['shipmentId'] ?></td><td><?= ui_esc($sh['tanggal']) ?></td><td><?= ui_esc((string) ($sh['doDocNo'] ?? '-')) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Mutasi Terkait</h2></div>
  <?php if ($invoice['mutasi'] === []): ?>
    <?= ui_empty_state('Tidak ada mutasi', 'Invoice ini tidak dipengaruhi Mutasi antar-toko.') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No. Dokumen</th><th>Arah</th><th>Produk</th><th class="num">Qty</th><th>Dari Toko</th><th>Ke Toko</th></tr></thead>
    <tbody>
    <?php foreach ($invoice['mutasi'] as $m): ?>
    <tr>
      <td><?= ui_esc((string) ($m['docNo'] ?? '-')) ?></td>
      <td><?= ui_badge($m['direction'] === 'out' ? 'Keluar' : 'Masuk') ?></td>
      <td><?= ui_esc($m['productName']) ?></td>
      <td class="num"><?= ui_fmt_num($m['qty']) ?></td>
      <td><?= ui_esc((string) ($m['sourceStoreName'] ?? '-')) ?></td>
      <td><?= ui_esc((string) ($m['destinationStoreName'] ?? '-')) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<script>
(function () {
  var card = document.getElementById('invoice-card');
  var invoiceId = card.getAttribute('data-invoice-id');
  var voidBtn = document.getElementById('btn-void-invoice');
  voidBtn.addEventListener('click', async function () {
    var ok = await Amor.confirmModal({
      title: 'Batalkan Invoice ini?',
      body: 'Semua pengiriman dan mutasi yang tercakup akan dilepas kembali dan bisa ditagihkan pada invoice lain. Tindakan ini tidak bisa dibatalkan.',
      confirmLabel: 'Ya, Batalkan',
      danger: true,
    });
    if (!ok) return;
    var reason = prompt('Alasan pembatalan (wajib):');
    if (!reason) return;
    voidBtn.disabled = true;
    try {
      await Amor.apiFetch('/api/invoices/' + invoiceId + '/void', { method: 'POST', body: { reason: reason } });
      Amor.toast('Invoice dibatalkan.', 'success');
      setTimeout(function () { location.href = '/api/_ui-preview/?page=invoice'; }, 700);
    } catch (e) { Amor.toast(e.message, 'danger'); voidBtn.disabled = false; }
  });
})();
</script>
<?php endif; ?>
