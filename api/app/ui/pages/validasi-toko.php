<?php

declare(strict_types=1);

/**
 * Admin — "Validasi Kesesuaian Fisik & Sistem" for the stores' Retur /
 * Reject / Mutasi items (migration 0026), the page Admin used in the old
 * Google Apps Script. Only items whose transaction the Atasan approved
 * (or that need no Atasan) appear. Per item: Sesuai / Tidak Sesuai +
 * catatan. For a Reject, Sesuai also picks the follow-up (potong tagihan
 * or ganti barang), so the whole decision is made here in one tap.
 * Everything goes through /api/store-reports/*.
 */

$isAdmin = in_array('ADMIN', $ui['roles'], true);
$storeOpts = $pdo->query("SELECT store_id, canonical_name FROM store WHERE active = 1 AND canonical_name <> 'NON-OUTLET / PERORANGAN' ORDER BY canonical_name")->fetchAll();
$waitingAtasan = (int) $pdo->query("SELECT COUNT(*) FROM store_report WHERE status = 'menunggu_atasan'")->fetchColumn();
$mutasiSelisih = (int) $pdo->query("SELECT COUNT(*) FROM mutasi_request WHERE status = 'discrepancy'")->fetchColumn();
?>
<div class="btn-group section" style="flex-wrap:wrap;">
  <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=dashboard-retur">📊 Dashboard Retur &amp; Reject</a>
  <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=mutasi-review&status=discrepancy">🔁 Selisih Mutasi<?= $mutasiSelisih > 0 ? ' (' . $mutasiSelisih . ')' : '' ?></a>
  <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=alasan-reject">🗂 Master Alasan Reject</a>
  <a class="btn btn-secondary btn-sm" id="vt-export" href="#">⬇ Export Excel (CSV)</a>
</div>
<?php if ($waitingAtasan > 0): ?>
<div class="alert alert-warning section">⏳ <?= $waitingAtasan ?> transaksi toko masih menunggu persetujuan Atasan — belum tampil di sini sampai disetujui.</div>
<?php endif; ?>

<div class="card section">
  <div class="filter-bar" style="flex-wrap:wrap;gap:var(--space-2);">
    <label>Pabrik <select id="vt-factory"><option value="">Semua Pabrik</option>
      <?php foreach ($factories as $f): ?><option value="<?= (int) $f['factory_id'] ?>"><?= ui_esc($f['name']) ?></option><?php endforeach; ?></select></label>
    <label>Jenis <select id="vt-jenis"><option value="">Semua Jenis</option><option value="retur">Retur</option><option value="reject">Reject</option><option value="mutasi">Mutasi</option></select></label>
    <label>Toko <select id="vt-store"><option value="">Semua Toko</option>
      <?php foreach ($storeOpts as $s): ?><option value="<?= (int) $s['store_id'] ?>"><?= ui_esc($s['canonical_name']) ?></option><?php endforeach; ?></select></label>
    <label>Dari <input type="date" id="vt-from"></label>
    <label>Sampai <input type="date" id="vt-to"></label>
    <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="vt-wait" checked> Hanya yang Menunggu Validasi</label>
    <button type="button" class="btn btn-primary" id="vt-apply">🔍 Terapkan</button>
  </div>
  <div class="kpi-grid" style="margin-top:var(--space-3);">
    <div class="kpi-card"><div class="kpi-label">Menunggu</div><div class="kpi-value" id="vt-s-wait" style="color:var(--warning)">0</div></div>
    <div class="kpi-card"><div class="kpi-label">Sesuai</div><div class="kpi-value" id="vt-s-ok" style="color:var(--success)">0</div></div>
    <div class="kpi-card"><div class="kpi-label">Tidak Sesuai</div><div class="kpi-value" id="vt-s-no" style="color:var(--danger)">0</div></div>
  </div>
</div>
<div id="vt-list"></div>

<style>
  .vt-item { border:1px solid var(--border); border-left:4px solid var(--primary); border-radius:var(--radius); padding:12px 14px; margin-bottom:10px; background:var(--surface); }
  .vt-item.reject { border-left-color:var(--danger); } .vt-item.mutasi { border-left-color:var(--success); }
  .vt-item.done-sesuai { opacity:.8; background:var(--success-soft); } .vt-item.done-tidak_sesuai { opacity:.85; background:var(--danger-soft); }
  .vt-head { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; }
  .vt-meta { font-size:var(--text-xs); color:var(--text-muted); }
  .vt-title { font-weight:700; margin-top:2px; }
  .vt-sub { font-size:var(--text-sm); color:var(--text-muted); margin-top:2px; }
  .vt-jb { display:inline-block; font-size:10px; font-weight:800; padding:2px 7px; border-radius:5px; margin-right:6px; }
  .vt-jb.retur { background:var(--primary-soft); color:var(--primary); } .vt-jb.reject { background:var(--danger-soft); color:var(--danger); } .vt-jb.mutasi { background:var(--success-soft); color:var(--success); }
  .vt-qty { font-size:1.05rem; font-weight:800; text-align:right; white-space:nowrap; }
  .vt-chip { display:inline-block; font-size:11px; font-weight:700; padding:3px 9px; border-radius:20px; margin-top:4px; }
  .vt-chip.menunggu { background:var(--warning-soft); color:var(--warning); } .vt-chip.sesuai { background:var(--success-soft); color:var(--success); } .vt-chip.tidak_sesuai { background:var(--danger-soft); color:var(--danger); }
  .vt-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
  .vt-photos { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; } .vt-photos img { width:72px; height:72px; object-fit:cover; border-radius:6px; border:1px solid var(--border); cursor:pointer; }
  .vt-note { display:none; margin-top:8px; } .vt-note textarea { width:100%; min-height:56px; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var CAN_ACT = <?= $isAdmin ? 'true' : 'false' ?>;
  var JL = { retur: 'Retur', reject: 'Reject', mutasi: 'Mutasi' };
  var SL = { menunggu: 'Menunggu Validasi', sesuai: 'Sesuai', tidak_sesuai: 'Tidak Sesuai' };
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var num = function (n) { return (Math.round((Number(n) || 0) * 100) / 100).toLocaleString('id-ID'); };
  var rp = function (n) { return 'Rp ' + Math.round(Number(n) || 0).toLocaleString('id-ID'); };
  function qs() {
    return 'factoryId=' + $('vt-factory').value + '&jenis=' + $('vt-jenis').value + '&storeId=' + $('vt-store').value +
      '&from=' + $('vt-from').value + '&to=' + $('vt-to').value;
  }
  $('vt-export').addEventListener('click', function (e) { e.preventDefault(); location.href = '/api/store-reports/export?' + qs(); });
  $('vt-apply').addEventListener('click', load);

  function load() {
    var list = $('vt-list');
    list.innerHTML = '<div class="card"><div class="empty-state">Memuat...</div></div>';
    Amor.apiFetch('/api/store-reports/validasi?' + qs() + '&onlyWaiting=' + ($('vt-wait').checked ? '1' : '0')).then(function (res) {
      $('vt-s-wait').textContent = res.ringkasan.menunggu; $('vt-s-ok').textContent = res.ringkasan.sesuai; $('vt-s-no').textContent = res.ringkasan.tidakSesuai;
      list.innerHTML = '';
      if (!res.items.length) { list.innerHTML = '<div class="card"><div class="empty-state">Tidak ada item untuk filter ini.</div></div>'; return; }
      res.items.forEach(function (it) { list.appendChild(card(it)); });
    }).catch(function (e) { list.innerHTML = ''; Amor.toast(e.message, 'danger'); });
  }

  function card(it) {
    var el = document.createElement('div');
    el.className = 'vt-item ' + it.jenis + (it.validasiStatus !== 'menunggu' ? ' done-' + it.validasiStatus : '');
    var sub = [];
    if (it.alasan) sub.push('Alasan: ' + it.alasan);
    if (it.expDate) sub.push('Exp: ' + it.expDate);
    if (it.destinationStoreName) sub.push('Ke toko: ' + it.destinationStoreName);
    if (it.notes) sub.push('Catatan: ' + it.notes);
    var approved = it.approvalMode === 'otomatis' ? 'tanpa Atasan (otomatis)' : (it.approvedByName ? 'Atasan: ' + it.approvedByName : '');
    var decided = it.validasiStatus !== 'menunggu';
    var canAct = CAN_ACT && (!decided || (it.jenis === 'mutasi' && it.itemStatus !== 'cancelled'));
    el.innerHTML =
      '<div class="vt-head"><div>' +
        '<div class="vt-meta">' + esc(it.tanggal) + ' · ' + esc(it.storeName) + ' · ' + esc(it.pabrik || '-') + ' · ' + esc(it.reportNo || it.docNo) + (approved ? ' · ' + esc(approved) : '') + '</div>' +
        '<div class="vt-title"><span class="vt-jb ' + it.jenis + '">' + JL[it.jenis] + '</span>' + esc(it.productName) + '</div>' +
        (sub.length ? '<div class="vt-sub">' + esc(sub.join(' · ')) + '</div>' : '') +
        (it.validasiStatus === 'tidak_sesuai' && it.validasiNote ? '<div class="vt-sub" style="color:var(--danger)">Catatan: ' + esc(it.validasiNote) + '</div>' : '') +
        (it.jenis === 'mutasi' && it.itemStatus === 'cancelled' ? '<div class="vt-sub" style="color:var(--danger)">Mutasi dibatalkan — tagihan tidak pindah.</div>' : '') +
        (it.validasiByName && decided ? '<div class="vt-meta">Divalidasi oleh: ' + esc(it.validasiByName) + (it.tindakLanjut ? ' · ' + (it.tindakLanjut === 'ganti' ? 'Ganti barang' : 'Potong tagihan') : '') + '</div>' : '') +
      '</div><div><div class="vt-qty">' + num(it.qty) + ' pcs</div><div class="vt-meta" style="text-align:right">' + rp(it.total) + '</div>' +
        '<div style="text-align:right"><span class="vt-chip ' + it.validasiStatus + '">' + SL[it.validasiStatus] + '</span></div></div></div>' +
      '<div class="vt-photos"></div>' +
      '<div class="vt-actions">' +
        (it.evidenceCount ? '<button type="button" class="btn btn-secondary btn-sm" data-act="photos">📷 Foto (' + it.evidenceCount + ')</button>' : '') +
        (it.reportId ? '<a class="btn btn-secondary btn-sm" target="_blank" href="/api/_ui-preview/print-store-report.php?id=' + it.reportId + '&back=' + encodeURIComponent('/api/_ui-preview/?page=validasi-toko') + '">🖨 Bukti</a>' : '') +
        (canAct && it.jenis !== 'reject' ? '<button type="button" class="btn btn-success btn-sm" data-act="ok">✅ Sesuai</button>' : '') +
        (canAct && it.jenis === 'reject' ? '<button type="button" class="btn btn-success btn-sm" data-act="potong">✅ Sesuai → Potong tagihan</button><button type="button" class="btn btn-success btn-sm" data-act="ganti">✅ Sesuai → Ganti barang</button>' : '') +
        (canAct ? '<button type="button" class="btn btn-danger btn-sm" data-act="no">❌ Tidak Sesuai</button>' : '') +
      '</div>' +
      '<div class="vt-note"><textarea placeholder="Catatan ketidaksesuaian (misalnya selisih qty, barang tidak ada fisiknya)"></textarea>' +
        '<button type="button" class="btn btn-danger btn-sm" data-act="send-no" style="margin-top:6px">Kirim "Tidak Sesuai"</button></div>';
    el.querySelectorAll('[data-act]').forEach(function (b) {
      b.addEventListener('click', function () {
        var act = b.dataset.act;
        if (act === 'photos') return photos(it, el, b);
        if (act === 'no') { var n = el.querySelector('.vt-note'); n.style.display = n.style.display === 'block' ? 'none' : 'block'; return; }
        if (act === 'send-no') {
          var note = el.querySelector('textarea').value.trim();
          if (!note) { Amor.toast('Tulis catatan ketidaksesuaian dulu.', 'warning'); return; }
          if (it.jenis === 'mutasi') {
            Amor.confirmModal({
              title: 'Batalkan mutasi ini?',
              body: 'Tidak Sesuai untuk Mutasi = mutasi DIBATALKAN: tagihan ' + num(it.qty) + ' pcs ' + it.productName + ' tidak pindah ke ' + (it.destinationStoreName || 'toko tujuan') + ' dan tetap menjadi tagihan ' + it.storeName + '.',
              confirmLabel: 'Ya, Batalkan Mutasi',
              danger: true,
            }).then(function (ok) { if (ok) send(it, el, 'tidak_sesuai', note, null); });
            return;
          }
          return send(it, el, 'tidak_sesuai', note, null);
        }
        if (act === 'ok') return send(it, el, 'sesuai', '', null);
        if (act === 'potong' || act === 'ganti') {
          Amor.confirmModal({
            title: act === 'ganti' ? 'Sesuai → Ganti barang?' : 'Sesuai → Potong tagihan?',
            body: act === 'ganti'
              ? 'Tagihan toko dipotong (Nota Potongan) DAN dibuat Pesanan Khusus pengganti ' + num(it.qty) + ' pcs ' + it.productName + ' untuk ' + it.storeName + '.'
              : 'Dibuat Nota Potongan ' + num(it.qty) + ' pcs × harga untuk ' + it.storeName + '.',
            confirmLabel: 'Ya, Simpan',
          }).then(function (ok) { if (ok) send(it, el, 'sesuai', '', act); });
        }
      });
    });
    return el;
  }

  function send(it, el, status, note, tl) {
    el.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    Amor.apiFetch('/api/store-reports/validasi', { method: 'POST', body: { type: it.itemType, id: it.itemId, status: status, note: note, tindakLanjut: tl } })
      .then(function () {
        Amor.toast('Validasi tersimpan: ' + SL[status] + '.', 'success');
        if ($('vt-wait').checked) { el.remove(); } else { load(); return; }
        Amor.apiFetch('/api/store-reports/validasi?' + qs() + '&onlyWaiting=0').then(function (res) {
          $('vt-s-wait').textContent = res.ringkasan.menunggu; $('vt-s-ok').textContent = res.ringkasan.sesuai; $('vt-s-no').textContent = res.ringkasan.tidakSesuai;
        }).catch(function () {});
      })
      .catch(function (e) { el.querySelectorAll('button').forEach(function (b) { b.disabled = false; }); Amor.toast(e.message, 'danger'); });
  }

  function photos(it, el, btn) {
    var box = el.querySelector('.vt-photos');
    if (box.childElementCount) { box.innerHTML = ''; return; }
    btn.disabled = true;
    Amor.apiFetch('/api/store-reports/items/' + it.itemType + '/' + it.itemId + '/evidence').then(function (rows) {
      btn.disabled = false;
      rows.forEach(function (r) {
        var img = document.createElement('img');
        img.src = r.url; img.alt = 'Bukti';
        img.addEventListener('click', function () { Amor.imageLightbox(r.url); });
        box.appendChild(img);
      });
    }).catch(function (e) { btn.disabled = false; Amor.toast(e.message, 'danger'); });
  }

  load();
});
</script>
