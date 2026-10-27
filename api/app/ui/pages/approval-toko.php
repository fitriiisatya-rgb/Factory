<?php

declare(strict_types=1);

/**
 * Atasan (Head Store) — approve the Retur / Reject / Mutasi transactions
 * of the stores they supervise (migration 0026), like the old Google Apps
 * Script "Approval" page: tick one or more (or "Pilih Semua") and Setujui.
 * Only after approval do the items reach Admin and the mutasi destination.
 */

$storeNames = [];
$st = $pdo->prepare('SELECT s.canonical_name FROM user_store_access usa INNER JOIN store s ON s.store_id = usa.store_id WHERE usa.user_id = ? ORDER BY s.canonical_name');
$st->execute([(int) $ui['userId']]);
$storeNames = $st->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="card section">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;">
    <div style="font-size:var(--text-sm);color:var(--text-muted);">Toko binaan: <b style="color:var(--text)"><?= $storeNames === [] ? 'belum ada — minta Admin menambahkan di Kelola User' : ui_esc(implode(', ', $storeNames)) ?></b></div>
    <div class="btn-group">
      <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=dashboard-retur">📊 Dashboard Statistik</a>
      <button type="button" class="btn btn-secondary btn-sm" id="ap-refresh">🔄 Muat Ulang</button>
    </div>
  </div>
</div>
<div id="ap-list"></div>
<div class="card" id="ap-bar" style="display:none;position:sticky;bottom:12px;z-index:5;">
  <div class="btn-group" style="width:100%;">
    <button type="button" class="btn btn-secondary" id="ap-all" style="flex:1">☑️ Pilih Semua</button>
    <button type="button" class="btn btn-success" id="ap-ok" style="flex:1" disabled>✅ Setujui (<span id="ap-n">0</span>)</button>
  </div>
</div>
<style>
  .ap-card { border:1px solid var(--border); border-radius:var(--radius); padding:14px; margin-bottom:10px; background:var(--surface); }
  .ap-check { display:flex; gap:10px; align-items:flex-start; cursor:pointer; }
  .ap-check input { width:22px; height:22px; margin-top:2px; accent-color:var(--success); }
  .ap-top { display:flex; justify-content:space-between; font-weight:700; gap:8px; }
  .ap-meta { font-size:var(--text-xs); color:var(--text-muted); }
  .ap-row { font-size:var(--text-sm); padding:7px 0; border-top:1px solid var(--border); margin-top:7px; }
  .ap-jb { display:inline-block; font-size:10px; font-weight:800; padding:1px 7px; border-radius:6px; margin-left:6px; }
  .ap-jb.retur { background:var(--primary-soft); color:var(--primary); } .ap-jb.reject { background:var(--danger-soft); color:var(--danger); } .ap-jb.mutasi { background:var(--success-soft); color:var(--success); }
  .ap-total { font-weight:700; padding-top:8px; border-top:1px solid var(--border); margin-top:8px; display:flex; justify-content:space-between; gap:8px; align-items:center; }
  .ap-photos { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; } .ap-photos img { width:64px; height:64px; object-fit:cover; border-radius:6px; cursor:pointer; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var JL = { retur: 'Retur', reject: 'Reject', mutasi: 'Mutasi' };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var num = function (n) { return (Math.round((Number(n) || 0) * 100) / 100).toLocaleString('id-ID'); };
  var rp = function (n) { return 'Rp ' + Math.round(Number(n) || 0).toLocaleString('id-ID'); };
  var list = document.getElementById('ap-list'), bar = document.getElementById('ap-bar');

  function count() {
    var n = list.querySelectorAll('.ap-cb:checked').length;
    document.getElementById('ap-n').textContent = n;
    document.getElementById('ap-ok').disabled = n === 0;
  }
  function load() {
    bar.style.display = 'none';
    list.innerHTML = '<div class="card"><div class="empty-state">Memuat...</div></div>';
    Amor.apiFetch('/api/store-reports/pending').then(function (rows) {
      list.innerHTML = '';
      if (!rows.length) { list.innerHTML = '<div class="card"><div class="empty-state">Tidak ada transaksi yang menunggu persetujuan saat ini.</div></div>'; return; }
      rows.forEach(function (r) {
        var c = document.createElement('div');
        c.className = 'ap-card';
        c.innerHTML =
          '<label class="ap-check"><input type="checkbox" class="ap-cb" value="' + r.reportId + '"><div style="flex:1">' +
          '<div class="ap-top"><span>' + esc(r.storeName) + '</span><span>' + esc(r.tanggal) + '</span></div>' +
          '<div class="ap-meta">ID Transaksi: ' + esc(r.docNo) + '</div></div></label>' +
          r.items.map(function (it) {
            return '<div class="ap-row"><b>' + esc(it.productName) + '</b><span class="ap-jb ' + it.jenis + '">' + JL[it.jenis] + '</span><br>' +
              'Qty: ' + num(it.qty) + (it.expDate ? ' · Exp: ' + esc(it.expDate) : '') + (it.alasan ? ' · Alasan: ' + esc(it.alasan) : '') +
              (it.destinationStoreName ? ' · Ke Toko: ' + esc(it.destinationStoreName) : '') +
              (it.evidenceCount ? ' · <a href="#" data-photo="' + it.itemType + '/' + it.itemId + '">📷 ' + it.evidenceCount + ' foto</a>' : '') +
              '<div class="ap-photos"></div></div>';
          }).join('') +
          '<div class="ap-total"><span>Total Nilai: ' + rp(r.total) + '</span>' +
          '<a class="btn btn-secondary btn-sm" target="_blank" href="/api/_ui-preview/print-store-report.php?id=' + r.reportId + '&back=' + encodeURIComponent('/api/_ui-preview/?page=approval-toko') + '">🖨 Bukti</a></div>';
        c.querySelector('.ap-cb').addEventListener('change', count);
        c.querySelectorAll('[data-photo]').forEach(function (a) {
          a.addEventListener('click', function (e) {
            e.preventDefault();
            var box = a.parentElement.querySelector('.ap-photos');
            if (box.childElementCount) { box.innerHTML = ''; return; }
            Amor.apiFetch('/api/store-reports/items/' + a.dataset.photo + '/evidence').then(function (ev) {
              ev.forEach(function (x) { var img = document.createElement('img'); img.src = x.url; img.addEventListener('click', function () { Amor.imageLightbox(x.url); }); box.appendChild(img); });
            }).catch(function (err) { Amor.toast(err.message, 'danger'); });
          });
        });
        list.appendChild(c);
      });
      bar.style.display = 'block';
      document.getElementById('ap-all').textContent = '☑️ Pilih Semua';
      count();
    }).catch(function (e) { list.innerHTML = ''; Amor.toast(e.message, 'danger'); });
  }
  document.getElementById('ap-all').addEventListener('click', function () {
    var boxes = list.querySelectorAll('.ap-cb');
    var all = boxes.length > 0 && Array.prototype.every.call(boxes, function (b) { return b.checked; });
    boxes.forEach(function (b) { b.checked = !all; });
    this.textContent = all ? '☑️ Pilih Semua' : '☐ Batal Pilih Semua';
    count();
  });
  document.getElementById('ap-ok').addEventListener('click', function () {
    var ids = Array.prototype.map.call(list.querySelectorAll('.ap-cb:checked'), function (b) { return parseInt(b.value, 10); });
    if (!ids.length) return;
    Amor.confirmModal({
      title: 'Setujui ' + ids.length + ' transaksi?',
      body: 'Setelah disetujui, laporan diteruskan ke Admin pabrik (dan ke toko tujuan untuk mutasi). Toko tidak bisa mengedit lagi.',
      confirmLabel: 'Ya, Setujui',
      pendingLabel: 'Memproses...',
      onConfirm: function () { return Amor.apiFetch('/api/store-reports/approve', { method: 'POST', body: { reportIds: ids } }); },
    }).then(function (ok) { if (ok) { Amor.toast(ids.length + ' transaksi disetujui.', 'success'); load(); } });
  });
  document.getElementById('ap-refresh').addEventListener('click', load);
  load();
});
</script>
