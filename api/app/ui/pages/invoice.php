<?php

declare(strict_types=1);

use Amor\Api\Invoice\InvoiceService;
use Amor\Api\Invoice\NotaPotonganService;

$storeId = isset($_GET['storeId']) && $_GET['storeId'] !== '' ? (int) $_GET['storeId'] : null;
$dateFrom = isset($_GET['dateFrom']) && $_GET['dateFrom'] !== '' ? (string) $_GET['dateFrom'] : null;
$dateTo = isset($_GET['dateTo']) && $_GET['dateTo'] !== '' ? (string) $_GET['dateTo'] : null;

$service = new InvoiceService($pdo);
$stores = $pdo->query('SELECT store_id, canonical_name FROM store WHERE active = 1 ORDER BY canonical_name')->fetchAll();
$invoices = $service->listAll($storeId, $dateFrom, $dateTo);
$notas = (new NotaPotonganService($pdo))->list($storeId, $dateFrom, $dateTo);
$ready = $service->readySummary();
$readyInvoiceCount = count($ready['deliveries']) + count($ready['mutasiInvoices']);
$readyNotaCount = count($ready['mutasiNotas']);

$kpi = ['count' => count($invoices), 'total' => 0.0, 'nota' => 0.0];
foreach ($invoices as $inv) {
    $kpi['total'] += $inv['total'];
}
foreach ($notas as $n) {
    $kpi['nota'] += $n['total'];
}
$kindLabels = ['do' => 'DO', 'do_khusus' => 'DO Khusus', 'mutasi' => 'Mutasi masuk', 'periode' => 'Periode'];

$defaultDateFrom = date('Y-m-01');
$defaultDateTo = date('Y-m-d');
?>
<div class="card section">
  <div class="card-head"><h2 class="card-title">Terbitkan Invoice per DO</h2></div>
  <div class="page-subtitle" style="margin-top:-8px;margin-bottom:var(--space-3);">
    Satu invoice untuk setiap DO yang sudah dikonfirmasi toko (Reject saat terima sudah dikurangi otomatis). Mutasi: toko tujuan mendapat invoice, toko asal mendapat Nota Potongan.
    Reject di Toko yang disetujui menjadi <b>Nota Potongan</b> atas invoice DO-nya — invoice tidak diubah.
  </div>
  <?php if ($readyInvoiceCount === 0 && $readyNotaCount === 0): ?>
    <div class="alert alert-success">Semua DO yang sudah dikonfirmasi toko sudah ditagih.</div>
  <?php else: ?>
    <div class="alert alert-warning"><b><?= $readyInvoiceCount ?> invoice</b><?= $readyNotaCount > 0 ? ' dan <b>' . $readyNotaCount . ' nota potongan</b>' : '' ?> siap diterbitkan.</div>
    <div class="table-scroll" style="max-height:260px;overflow:auto;"><table class="data-table">
      <thead><tr><th>Dokumen</th><th>Toko</th><th>Akan terbit</th></tr></thead>
      <tbody>
      <?php foreach ($ready['deliveries'] as $d): ?>
      <tr><td><?= ui_esc($d['docNo']) ?></td><td><?= ui_esc($d['storeName']) ?></td><td>Invoice (<?= ui_esc($kindLabels[$d['kind']]) ?>)</td></tr>
      <?php endforeach; ?>
      <?php foreach ($ready['mutasiInvoices'] as $m): ?>
      <tr><td><?= ui_esc($m['docNo']) ?></td><td><?= ui_esc($m['storeName']) ?></td><td>Invoice (Mutasi masuk)</td></tr>
      <?php endforeach; ?>
      <?php foreach ($ready['mutasiNotas'] as $m): ?>
      <tr><td><?= ui_esc($m['docNo']) ?></td><td><?= ui_esc($m['storeName']) ?></td><td>Nota Potongan (Mutasi keluar)</td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <button type="button" class="btn btn-primary" id="btn-generate-ready" style="margin-top:var(--space-3);">Terbitkan semua (<?= $readyInvoiceCount ?> invoice<?= $readyNotaCount > 0 ? ', ' . $readyNotaCount . ' nota' : '' ?>)</button>
  <?php endif; ?>
</div>

<details class="card section"<?= \Amor\Api\Auth::isFactoryAdmin() ? ' style="display:none"' : '' ?>>
  <summary class="card-title" style="cursor:pointer;">Invoice per periode (manual, opsional)</summary>
  <div class="page-subtitle" style="margin:var(--space-2) 0 var(--space-4);">
    Menggabungkan semua pengiriman satu toko yang belum ditagih dalam rentang tanggal menjadi satu invoice. Biasanya tidak perlu — gunakan "Terbitkan Invoice per DO" di atas.
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
</details>

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

<div class="kpi-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));">
  <?= ui_kpi_card(['label' => 'Total Invoice (' . $kpi['count'] . ')', 'value' => ui_fmt_money($kpi['total']), 'icon' => 'file', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'Nota Potongan (' . count($notas) . ')', 'value' => ui_fmt_money($kpi['nota']), 'icon' => 'alert', 'color' => 'warning']) ?>
  <?= ui_kpi_card(['label' => 'Tagihan Bersih', 'value' => ui_fmt_money($kpi['total'] - $kpi['nota']), 'icon' => 'box', 'color' => 'success']) ?>
</div>

<div class="table-card section">
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No. Invoice</th><th>Tanggal</th><th>Toko</th><th>Pabrik</th><th>Jenis</th><th class="num">Total</th><th class="num">Nota Potongan</th><th class="num">Bersih</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if ($invoices === []): ?>
    <tr><td colspan="9"><?= ui_empty_state('Belum ada invoice', 'Gunakan form di atas untuk membuat invoice baru dari data pengiriman yang sudah dikonfirmasi.') ?></td></tr>
    <?php else: foreach ($invoices as $inv): ?>
    <tr>
      <td><a href="/api/_ui-preview/?page=invoice-detail&invoiceId=<?= (int) $inv['invoiceId'] ?>"><?= ui_esc($inv['invoiceNo']) ?></a></td>
      <td><?= ui_esc($inv['tanggal']) ?></td>
      <td><?= ui_esc($inv['storeName']) ?></td>
      <td><?= ui_esc($inv['factoryName'] ?? '-') ?></td>
      <td><?= ui_esc($kindLabels[$inv['sourceKind']] ?? 'Periode') ?></td>
      <td class="num"><?= ui_fmt_money($inv['total']) ?></td>
      <td class="num"><?= $inv['notaTotal'] > 0 ? '&minus;' . ui_fmt_money($inv['notaTotal']) : '-' ?></td>
      <td class="num"><b><?= ui_fmt_money($inv['total'] - $inv['notaTotal']) ?></b></td>
      <td class="row-actions">
        <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=invoice-detail&invoiceId=<?= (int) $inv['invoiceId'] ?>">Lihat</a>
        <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/print-invoice.php?invoiceId=<?= (int) $inv['invoiceId'] ?>" target="_blank">Print</a>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<div class="card section" id="nota-potongan">
  <div class="card-head"><h2 class="card-title">Nota Potongan</h2></div>
  <p class="page-subtitle" style="margin:-8px 0 var(--space-3);">Potongan atas invoice yang sudah terbit: Reject di Toko yang disetujui, dan barang yang dimutasi ke toko lain. Tagihan bersih toko = invoice &minus; nota potongan.</p>
  <?php if ($notas === []): ?>
    <?= ui_empty_state('Belum ada nota potongan', 'Nota terbit otomatis saat Reject di Toko disetujui, atau saat Mutasi diterbitkan dari tombol di atas.') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No. Nota</th><th>Tanggal</th><th>Toko</th><th>Alasan</th><th>Atas Invoice</th><th class="num">Potongan</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($notas as $n): ?>
    <tr data-nota="<?= (int) $n['notaId'] ?>" data-no="<?= ui_esc($n['notaNo']) ?>">
      <td><?= ui_esc($n['notaNo']) ?></td>
      <td><?= ui_esc($n['tanggal']) ?></td>
      <td><?= ui_esc($n['storeName']) ?></td>
      <td><?= ui_badge($n['alasanLabel']) ?><div style="font-size:.8rem;color:var(--text-faint);"><?= ui_esc((string) $n['keterangan']) ?></div></td>
      <td><?= $n['invoiceId'] !== null ? '<a href="/api/_ui-preview/?page=invoice-detail&invoiceId=' . (int) $n['invoiceId'] . '">' . ui_esc((string) $n['invoiceNo']) . '</a>' : '<span style="color:var(--text-faint)">menyusul</span>' ?></td>
      <td class="num">&minus;<?= ui_fmt_money($n['total']) ?></td>
      <td class="row-actions">
        <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/print-nota.php?notaId=<?= (int) $n['notaId'] ?>" target="_blank">Print</a>
        <button type="button" class="btn btn-secondary btn-sm btn-void-nota">Batalkan</button>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<script>
(function () {
  var storeSel = document.getElementById('gen-store-id');
  var dateFromInput = document.getElementById('gen-date-from');
  var dateToInput = document.getElementById('gen-date-to');
  var previewBtn = document.getElementById('btn-preview-invoice');
  var generateBtn = document.getElementById('btn-generate-invoice');
  var box = document.getElementById('invoice-preview-box');

  function escInv(t) { var d = document.createElement('div'); d.textContent = String(t); return d.innerHTML; }

  function renderPreview(data) {
    var html = '<div class="alert alert-' + (data.items.length > 0 ? 'success' : 'warning') + '">'
      + data.shipmentCount + ' pengiriman, ' + data.mutasiOutCount + ' mutasi keluar, ' + data.mutasiInCount + ' mutasi masuk'
      + ' ditemukan untuk ' + escInv(data.storeName) + '.</div>';
    if (data.items.length === 0) {
      html += '<p>Tidak ada yang bisa ditagihkan untuk toko dan periode ini.</p>';
    } else {
      html += '<table class="data-table"><thead><tr><th>Produk</th><th class="num">Qty DO</th><th class="num">Qty Invoice</th><th class="num">Harga</th><th class="num">Subtotal</th></tr></thead><tbody>';
      data.items.forEach(function (it) {
        html += '<tr><td>' + escInv(it.productName) + '</td><td class="num">' + it.qtyDo + '</td><td class="num">' + it.qtyInvoice + '</td><td class="num">'
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

  var readyBtn = document.getElementById('btn-generate-ready');
  if (readyBtn) readyBtn.addEventListener('click', async function () {
    var ok = await Amor.confirmModal({
      title: 'Terbitkan semua invoice?',
      body: 'Setiap DO yang sudah dikonfirmasi toko mendapat satu invoice. Pengiriman yang sudah ditagih tidak bisa ditagih ulang.',
      confirmLabel: 'Ya, Terbitkan',
    });
    if (!ok) return;
    readyBtn.disabled = true;
    try {
      var r = await Amor.apiFetch('/api/invoices/generate-ready', { method: 'POST', body: {} });
      Amor.toast(r.invoices.length + ' invoice' + (r.notas.length ? ' dan ' + r.notas.length + ' nota potongan' : '') + ' diterbitkan.', 'success');
      setTimeout(function () { location.reload(); }, 800);
    } catch (e) { Amor.toast(e.message, 'danger'); readyBtn.disabled = false; }
  });

  document.querySelectorAll('.btn-void-nota').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var row = btn.closest('tr');
      var reason = prompt('Batalkan nota ' + row.dataset.no + '? Tulis alasannya (wajib):');
      if (reason === null || reason.trim() === '') return;
      try {
        await Amor.apiFetch('/api/nota-potongan/' + row.dataset.nota + '/void', { method: 'POST', body: { reason: reason } });
        Amor.toast('Nota dibatalkan.', 'success');
        location.reload();
      } catch (e) { Amor.toast(e.message, 'danger'); }
    });
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
