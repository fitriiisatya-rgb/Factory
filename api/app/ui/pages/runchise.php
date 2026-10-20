<?php

declare(strict_types=1);

/**
 * Admin "Mapping Runchise" (migration 0024): upload the Runchise product
 * catalog, map store → Runchise location and product → Runchise product,
 * and download the "Impor Stok Masuk" file of any confirmed surat jalan.
 * Stores download their own file from the Bakery Portal / QR page.
 */

if (array_intersect(['ADMIN'], $ui['roles']) === []) {
    echo ui_empty_state('Akses Ditolak', 'Halaman ini khusus Admin.');
    return;
}

$recent = $pdo->query(
    "SELECT sh.shipment_id, sh.tanggal, sh.runchise_downloaded_at, sh.runchise_downloaded_by, s.canonical_name, s.runchise_location,
            COALESCE(o.doc_no, sodo.doc_no, rdo.doc_no, sh.no_sj) AS doc_no, r.status AS receipt_status
     FROM shipment sh
     INNER JOIN store s ON s.store_id = sh.store_id
     INNER JOIN shipment_receipt r ON r.shipment_id = sh.shipment_id AND r.status IN ('confirmed_ok','confirmed_discrepancy','verified')
     LEFT JOIN delivery_order o ON o.delivery_order_id = sh.delivery_order_id
     LEFT JOIN special_order_do sodo ON sodo.special_order_do_id = sh.special_order_do_id
     LEFT JOIN replacement_do rdo ON rdo.replacement_do_id = sh.replacement_do_id
     WHERE sh.status = 'active' AND sh.tanggal >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
     ORDER BY sh.tanggal DESC, s.canonical_name LIMIT 300"
)->fetchAll();
?>
<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-2);">1. Katalog produk Runchise</h3>
  <p style="color:var(--text-muted);font-size:var(--text-sm);margin-bottom:var(--space-3);">
    Di Runchise buka <b>Produk → Stok → Stok Masuk → Impor</b>, unduh <code>impor_template_stock_ins.csv</code>, lalu upload di sini.
    Produk factory yang kodenya sama dengan SKU Runchise langsung terpetakan. Upload ulang setiap ada produk Runchise baru.
  </p>
  <div id="rc-cat-info" style="font-size:var(--text-sm);margin-bottom:var(--space-2);color:var(--text-muted);">Memuat...</div>
  <input type="file" id="rc-cat-file" accept=".csv,text/csv">
  <button type="button" class="btn btn-primary" id="rc-cat-upload" style="margin-left:var(--space-2);">Upload Katalog</button>
</div>

<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-2);">2. Produk</h3>
  <p style="color:var(--text-muted);font-size:var(--text-sm);margin-bottom:var(--space-2);">
    <b>Stok</b> = masuk file (pilih produk Runchise-nya). <b>Bukan stok</b> / <b>Topping</b> = tidak masuk file.
    Produk yang belum diatur tidak ikut file dan ditampilkan ke toko sebagai "tidak ikut file".
  </p>
  <div class="filter-bar" style="margin-bottom:var(--space-2);">
    <input type="search" id="rc-prod-q" placeholder="Cari produk..." style="max-width:260px;">
    <label style="font-size:var(--text-sm);"><input type="checkbox" id="rc-prod-unmapped" checked> Hanya yang belum diatur</label>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Produk Factory</th><th>Kode</th><th>Pengaturan</th><th>Produk Runchise</th><th></th></tr></thead>
    <tbody id="rc-prod-rows"><tr><td colspan="5" style="color:var(--text-faint);">Memuat...</td></tr></tbody>
  </table></div>
  <datalist id="rc-catalog-list"></datalist>
</div>

<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-2);">3. Toko → Lokasi Runchise</h3>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Toko</th><th>Lokasi Runchise</th><th></th></tr></thead>
    <tbody id="rc-store-rows"><tr><td colspan="3" style="color:var(--text-faint);">Memuat...</td></tr></tbody>
  </table></div>
</div>

<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-2);">4. File stok masuk per surat jalan (14 hari terakhir, sudah dikonfirmasi)</h3>
  <?php if ($recent === []): ?>
    <?= ui_empty_state('Belum ada', 'Belum ada surat jalan yang dikonfirmasi toko.') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Tanggal</th><th>Toko</th><th>No. DO / SJ</th><th>Lokasi Runchise</th><th>Sudah diunduh</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
    <tr>
      <td><?= ui_esc((string) $r['tanggal']) ?></td>
      <td><?= ui_esc((string) $r['canonical_name']) ?></td>
      <td><?= ui_esc((string) ($r['doc_no'] ?? ('SJ-' . $r['shipment_id']))) ?></td>
      <td><?= $r['runchise_location'] !== null ? ui_esc((string) $r['runchise_location']) : '<span class="badge badge-danger">Belum diatur</span>' ?></td>
      <td><?= $r['runchise_downloaded_at'] !== null ? ui_esc(substr((string) $r['runchise_downloaded_at'], 0, 16) . ' UTC · ' . (string) $r['runchise_downloaded_by']) : '-' ?></td>
      <td><button type="button" class="btn btn-secondary btn-sm" data-rc-preview="<?= (int) $r['shipment_id'] ?>">Pratinjau &amp; Unduh</button></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<script>
// app.js (window.Amor) loads after the page body — start once it is there.
document.addEventListener('DOMContentLoaded', function () {
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function num(n) { return (Math.round((Number(n) || 0) * 100) / 100).toLocaleString('id-ID'); }
  var data = null;
  var modeLabel = { stok: 'Stok', abaikan: 'Bukan stok', topping: 'Topping' };

  function drawProducts() {
    var q = document.getElementById('rc-prod-q').value.trim().toLowerCase();
    var onlyUnmapped = document.getElementById('rc-prod-unmapped').checked;
    var rows = data.products.filter(function (p) {
      if (onlyUnmapped && p.mode) return false;
      return !q || (p.name + ' ' + (p.codes || '') + ' ' + (p.runchiseName || '')).toLowerCase().indexOf(q) !== -1;
    }).slice(0, 300);
    var tb = document.getElementById('rc-prod-rows');
    if (!rows.length) { tb.innerHTML = '<tr><td colspan="5" style="color:var(--text-faint);">' + (onlyUnmapped ? 'Semua produk sudah diatur.' : 'Tidak ada produk.') + '</td></tr>'; return; }
    tb.innerHTML = rows.map(function (p) {
      var opts = ['', 'stok', 'abaikan', 'topping'].map(function (m) { return '<option value="' + m + '"' + ((p.mode || '') === m ? ' selected' : '') + '>' + (m ? modeLabel[m] : 'Belum diatur') + '</option>'; }).join('');
      return '<tr data-pid="' + p.productId + '"><td>' + esc(p.name) + '</td><td>' + esc(p.codes || '-') + '</td>'
        + '<td><select class="rc-mode">' + opts + '</select></td>'
        + '<td><input type="text" class="rc-sku" list="rc-catalog-list" placeholder="SKU Runchise" value="' + esc(p.sku || '') + '" style="width:9rem;"> '
        + '<span style="font-size:var(--text-xs);color:var(--text-muted);">' + esc(p.runchiseName || '') + '</span></td>'
        + '<td><button type="button" class="btn btn-primary btn-sm rc-save-prod">Simpan</button></td></tr>';
    }).join('');
  }
  function drawStores() {
    var opts = function (cur) { return '<option value="">— belum diatur —</option>' + data.locations.map(function (l) { return '<option' + (l === cur ? ' selected' : '') + '>' + esc(l) + '</option>'; }).join(''); };
    document.getElementById('rc-store-rows').innerHTML = data.stores.map(function (s) {
      return '<tr data-sid="' + s.storeId + '"><td>' + esc(s.name) + '</td><td><select class="rc-loc">' + opts(s.location) + '</select>'
        + (s.location ? '' : ' <span class="badge badge-danger">Belum diatur</span>') + '</td>'
        + '<td><button type="button" class="btn btn-primary btn-sm rc-save-store">Simpan</button></td></tr>';
    }).join('');
  }
  async function load() {
    data = await Amor.apiFetch('/api/admin/runchise/mapping');
    var unmapped = data.products.filter(function (p) { return !p.mode; }).length;
    document.getElementById('rc-cat-info').innerHTML = data.catalog.length
      ? 'Katalog: <b>' + data.catalog.length + '</b> produk Runchise (upload ' + esc((data.catalogUploadedAt || '').slice(0, 16)) + ' UTC). Produk factory belum diatur: <b>' + unmapped + '</b>.'
      : '<span class="badge badge-danger">Katalog belum diupload</span> Produk factory belum diatur: <b>' + unmapped + '</b>.';
    document.getElementById('rc-catalog-list').innerHTML = data.catalog.map(function (c) { return '<option value="' + esc(c.sku) + '">' + esc(c.name) + '</option>'; }).join('');
    drawProducts(); drawStores();
  }

  document.getElementById('rc-prod-q').addEventListener('input', drawProducts);
  document.getElementById('rc-prod-unmapped').addEventListener('change', drawProducts);
  document.getElementById('rc-cat-upload').addEventListener('click', function () {
    var f = document.getElementById('rc-cat-file').files[0];
    if (!f) { Amor.toast('Pilih file katalog dulu.', 'danger'); return; }
    var reader = new FileReader();
    reader.onload = async function () {
      try {
        var r = await Amor.apiFetch('/api/admin/runchise/catalog', { method: 'POST', body: { csv: String(reader.result) } });
        Amor.toast('Katalog tersimpan: ' + r.catalogProducts + ' produk, ' + r.autoMapped + ' produk factory langsung terpetakan.', 'success');
        load();
      } catch (e) { Amor.toast(e.message, 'danger'); }
    };
    reader.readAsText(f);
  });
  document.addEventListener('click', async function (e) {
    var b = e.target.closest('.rc-save-prod, .rc-save-store, [data-rc-preview], [data-rc-download]');
    if (!b) return;
    try {
      if (b.classList.contains('rc-save-prod')) {
        var tr = b.closest('tr');
        var mode = tr.querySelector('.rc-mode').value;
        await Amor.apiFetch('/api/admin/runchise/products/' + tr.dataset.pid, { method: 'POST', body: { mode: mode || null, sku: tr.querySelector('.rc-sku').value.trim() } });
        Amor.toast('Produk tersimpan.', 'success'); load();
      } else if (b.classList.contains('rc-save-store')) {
        var ts = b.closest('tr');
        await Amor.apiFetch('/api/admin/runchise/stores/' + ts.dataset.sid, { method: 'POST', body: { location: ts.querySelector('.rc-loc').value } });
        Amor.toast('Lokasi tersimpan.', 'success'); load();
      } else if (b.hasAttribute('data-rc-preview')) {
        var id = b.getAttribute('data-rc-preview');
        var p = await Amor.apiFetch('/api/admin/runchise/shipments/' + id);
        var html = p.ready
          ? '<div>Lokasi: <b>' + esc(p.location) + '</b> · Tanggal: <b>' + esc(p.tanggal) + '</b><br>' + p.productCount + ' produk · ' + num(p.totalQty) + ' pcs · Alasan: ' + esc(p.alasan) + '</div>'
          : '<div class="alert alert-danger">' + esc(p.message) + '</div>';
        if (p.excluded.length) html += '<div style="margin-top:8px;"><b>Tidak ikut file:</b><br>' + p.excluded.map(function (x) { return esc(x.name) + ' (' + num(x.qty) + ') — ' + esc(x.reason); }).join('<br>') + '</div>';
        if (p.downloadedAt) html += '<div class="alert alert-warning" style="margin-top:8px;">File ini sudah diunduh pada ' + esc(p.downloadedAt.slice(0, 16).replace('T', ' ')) + ' UTC oleh ' + esc(p.downloadedBy) + '. Jangan impor dua kali agar stok tidak dobel.'
          + (p.changedSinceDownload ? '<br><b>Konfirmasi sudah berubah sejak file diunduh — file lama tidak sesuai lagi.</b>' : '') + '</div>';
        var ok = await Amor.confirmModal({ title: 'File stok masuk Runchise', body: '', bodyHtml: html, confirmLabel: p.ready ? 'Unduh file' : 'Tutup' });
        if (ok && p.ready) { window.location.href = '/api/admin/runchise/shipments/' + id + '/download'; setTimeout(function () { location.reload(); }, 1500); }
      }
    } catch (err) { Amor.toast(err.message, 'danger'); }
  });
  load().catch(function (e) { Amor.toast(e.message, 'danger'); });
});
</script>
