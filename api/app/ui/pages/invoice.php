<?php

declare(strict_types=1);

use Amor\Api\Invoice\InvoiceService;

$storeId = isset($_GET['storeId']) && $_GET['storeId'] !== '' ? (int) $_GET['storeId'] : null;
$dateFrom = isset($_GET['dateFrom']) && $_GET['dateFrom'] !== '' ? (string) $_GET['dateFrom'] : null;
$dateTo = isset($_GET['dateTo']) && $_GET['dateTo'] !== '' ? (string) $_GET['dateTo'] : null;

$service = new InvoiceService($pdo);
$stores = $pdo->query('SELECT store_id, canonical_name FROM store WHERE active = 1 ORDER BY canonical_name')->fetchAll();
$invoices = $service->listAll($storeId, $dateFrom, $dateTo);

$kpi = ['count' => count($invoices), 'total' => 0.0];
foreach ($invoices as $inv) {
    $kpi['total'] += $inv['total'];
}

$defaultDateFrom = date('Y-m-01');
$defaultDateTo = date('Y-m-d');
?>
<div class="card section">
  <div class="card-head"><h2 class="card-title">Buat Invoice Baru</h2></div>
  <div class="page-subtitle" style="margin-top:-8px;margin-bottom:var(--space-4);">
    Invoice dihitung otomatis dari Shipment yang sudah dikonfirmasi toko (Reject sudah dikurangi secara otomatis) dan Mutasi antar-toko yang sudah selesai, untuk satu toko dan satu periode tanggal.
  </div>
  <div style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <div class="field"><label>Toko</label>
      <select id="gen-store-id">
        <option value="">-- Pilih Toko --</option>
        <?php foreach ($stores as $s): ?>
        <option value="<?= (int) $s['store_id'] ?>"><?= ui_esc($s['canonical_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Tanggal Awal</label><input type="date" id="gen-date-from" value="<?= ui_esc($defaultDateFrom) ?>"></div>
    <div class="field"><label>Tanggal Akhir</label><input type="date" id="gen-date-to" value="<?= ui_esc($defaultDateTo) ?>"></div>
    <button type="button" class="btn btn-secondary" id="btn-preview-invoice">Pratinjau</button>
    <button type="button" class="btn btn-primary" id="btn-generate-invoice" disabled>Generate Invoice</button>
  </div>

  <div id="invoice-preview-box" style="display:none;margin-top:var(--space-4);"></div>
</div>

<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="invoice">
    <div class="field"><label>Toko</label>
      <select name="storeId">
        <option value="">Semua Toko</option>
        <?php foreach ($stores as $s): ?>
        <option value="<?= (int) $s['store_id'] ?>" <?= $storeId === (int) $s['store_id'] ? 'selected' : '' ?>><?= ui_esc($s['canonical_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Dari Tanggal</label><input type="date" name="dateFrom" value="<?= ui_esc((string) $dateFrom) ?>"></div>
    <div class="field"><label>Sampai Tanggal</label><input type="date" name="dateTo" value="<?= ui_esc((string) $dateTo) ?>"></div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>
</div>

<div class="kpi-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));">
  <?= ui_kpi_card(['label' => 'Jumlah Invoice', 'value' => (string) $kpi['count'], 'icon' => 'file', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'Total Nilai', 'value' => ui_fmt_money($kpi['total']), 'icon' => 'box', 'color' => 'success']) ?>
</div>

<div class="table-card section">
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No. Invoice</th><th>Tanggal</th><th>Toko</th><th class="num">Total</th><th>Dibuat</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if ($invoices === []): ?>
    <tr><td colspan="6"><?= ui_empty_state('Belum ada invoice', 'Gunakan form di atas untuk membuat invoice baru dari data pengiriman yang sudah dikonfirmasi.') ?></td></tr>
    <?php else: foreach ($invoices as $inv): ?>
    <tr>
      <td><a href="/api/_ui-preview/?page=invoice-detail&invoiceId=<?= (int) $inv['invoiceId'] ?>"><?= ui_esc($inv['invoiceNo']) ?></a></td>
      <td><?= ui_esc($inv['tanggal']) ?></td>
      <td><?= ui_esc($inv['storeName']) ?></td>
      <td class="num"><?= ui_fmt_money($inv['total']) ?></td>
      <td><?= ui_esc($inv['createdAt']) ?></td>
      <td class="row-actions">
        <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=invoice-detail&invoiceId=<?= (int) $inv['invoiceId'] ?>">Lihat</a>
        <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/print-invoice.php?invoiceId=<?= (int) $inv['invoiceId'] ?>" target="_blank">Print</a>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<script>
(function () {
  var storeSel = document.getElementById('gen-store-id');
  var dateFromInput = document.getElementById('gen-date-from');
  var dateToInput = document.getElementById('gen-date-to');
  var previewBtn = document.getElementById('btn-preview-invoice');
  var generateBtn = document.getElementById('btn-generate-invoice');
  var box = document.getElementById('invoice-preview-box');

  function renderPreview(data) {
    var html = '<div class="alert alert-' + (data.items.length > 0 ? 'success' : 'warning') + '">'
      + data.shipmentCount + ' pengiriman, ' + data.mutasiOutCount + ' mutasi keluar, ' + data.mutasiInCount + ' mutasi masuk ditemukan untuk ' + data.storeName + '.</div>';
    if (data.items.length === 0) {
      html += '<p>Tidak ada yang bisa ditagihkan untuk toko dan periode ini.</p>';
    } else {
      html += '<table class="data-table"><thead><tr><th>Produk</th><th class="num">Qty DO</th><th class="num">Qty Invoice</th><th class="num">Harga</th><th class="num">Subtotal</th></tr></thead><tbody>';
      data.items.forEach(function (it) {
        html += '<tr><td>' + it.productName + '</td><td class="num">' + it.qtyDo + '</td><td class="num">' + it.qtyInvoice + '</td><td class="num">'
          + Amor.fmtRupiah(it.harga) + '</td><td class="num">' + Amor.fmtRupiah(it.subtotal) + '</td></tr>';
      });
      html += '</tbody></table><p style="text-align:right;margin-top:var(--space-3);font-weight:600;">Total: ' + Amor.fmtRupiah(data.total) + '</p>';
    }
    box.innerHTML = html;
    box.style.display = 'block';
  }

  function collectRange() {
    var storeId = storeSel.value;
    var dateFrom = dateFromInput.value;
    var dateTo = dateToInput.value;
    if (!storeId || !dateFrom || !dateTo) { Amor.toast('Pilih toko dan rentang tanggal terlebih dahulu.', 'warning'); return null; }
    return { storeId: storeId, dateFrom: dateFrom, dateTo: dateTo };
  }

  previewBtn.addEventListener('click', async function () {
    var range = collectRange();
    if (!range) return;
    previewBtn.disabled = true;
    try {
      var data = await Amor.apiFetch('/api/invoices/preview?storeId=' + encodeURIComponent(range.storeId) + '&dateFrom=' + encodeURIComponent(range.dateFrom) + '&dateTo=' + encodeURIComponent(range.dateTo));
      renderPreview(data);
      generateBtn.disabled = data.items.length === 0;
    } catch (e) { Amor.toast(e.message, 'danger'); }
    previewBtn.disabled = false;
  });

  generateBtn.addEventListener('click', async function () {
    var range = collectRange();
    if (!range) return;
    var ok = await Amor.confirmModal({
      title: 'Generate Invoice?',
      body: 'Semua pengiriman dan mutasi yang tercakup akan ditandai sudah ditagihkan dan tidak bisa ditagihkan ulang pada invoice lain.',
      confirmLabel: 'Ya, Generate',
    });
    if (!ok) return;
    generateBtn.disabled = true;
    try {
      var data = await Amor.apiFetch('/api/invoices', { method: 'POST', body: { storeId: parseInt(range.storeId, 10), dateFrom: range.dateFrom, dateTo: range.dateTo } });
      Amor.toast('Invoice ' + data.invoiceNumber + ' dibuat.', 'success');
      setTimeout(function () { location.href = '/api/_ui-preview/?page=invoice-detail&invoiceId=' + data.invoiceId; }, 700);
    } catch (e) { Amor.toast(e.message, 'danger'); generateBtn.disabled = false; }
  });
})();
</script>
