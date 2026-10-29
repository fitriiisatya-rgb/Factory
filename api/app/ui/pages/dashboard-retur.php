<?php

declare(strict_types=1);

/**
 * Dashboard Retur & Reject (migration 0026) — the old Google Apps Script
 * dashboard: totals, the date with the most Retur and the Retur trend per
 * date, Top 10 Retur and Top 10 Reject products, plus per-store totals.
 * Admin/PPIC see every store; an Atasan only their own stores.
 */

$isAtasanOnly = array_intersect(['ADMIN', 'PPIC'], $ui['roles']) === [];
if ($isAtasanOnly) {
    $st = $pdo->prepare("SELECT s.store_id, s.canonical_name FROM user_store_access usa INNER JOIN store s ON s.store_id = usa.store_id WHERE usa.user_id = ? ORDER BY s.canonical_name");
    $st->execute([(int) $ui['userId']]);
    $storeOpts = $st->fetchAll();
} else {
    $storeOpts = $pdo->query("SELECT store_id, canonical_name FROM store WHERE active = 1 AND canonical_name <> 'NON-OUTLET / PERORANGAN' ORDER BY canonical_name")->fetchAll();
}
$tz = new \DateTimeZone('Asia/Jakarta');
$from = (new \DateTimeImmutable('first day of this month', $tz))->format('Y-m-d');
$to = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
?>
<div class="card section">
  <div style="font-size:var(--text-sm);color:var(--text-muted);margin-bottom:8px;"><?= $isAtasanOnly ? 'Menampilkan data toko binaan Anda saja.' : 'Menampilkan data seluruh toko.' ?></div>
  <div class="filter-bar" style="flex-wrap:wrap;gap:var(--space-2);">
    <label>Toko <select id="db-store"><option value="">Semua Toko</option>
      <?php foreach ($storeOpts as $s): ?><option value="<?= (int) $s['store_id'] ?>"><?= ui_esc($s['canonical_name']) ?></option><?php endforeach; ?></select></label>
    <label>Pabrik <select id="db-factory"><?php if (!\Amor\Api\Auth::isFactoryAdmin()): ?><option value="">Semua Pabrik</option><?php endif; ?>
      <?php foreach ($factories as $f): ?><option value="<?= (int) $f['factory_id'] ?>"><?= ui_esc($f['name']) ?></option><?php endforeach; ?></select></label>
    <label>Dari <input type="date" id="db-from" value="<?= ui_esc($from) ?>"></label>
    <label>Sampai <input type="date" id="db-to" value="<?= ui_esc($to) ?>"></label>
    <button type="button" class="btn btn-primary" id="db-apply">🔍 Terapkan</button>
    <a class="btn btn-secondary" href="#" id="db-export">⬇ Export Excel (CSV)</a>
  </div>
</div>
<div class="kpi-grid section">
  <div class="kpi-card"><div class="kpi-label">Total Qty Retur</div><div class="kpi-value" id="db-retur" style="color:var(--primary)">0</div><div class="kpi-foot"><span id="db-retur-rp"></span></div></div>
  <div class="kpi-card"><div class="kpi-label">Total Qty Reject</div><div class="kpi-value" id="db-reject" style="color:var(--danger)">0</div><div class="kpi-foot"><span id="db-reject-rp"></span></div></div>
  <div class="kpi-card"><div class="kpi-label">Total Qty Mutasi</div><div class="kpi-value" id="db-mutasi" style="color:var(--success)">0</div></div>
</div>
<div class="db-grid">
  <div class="card"><h3 class="card-title">📅 Tanggal Retur Terbanyak</h3><div id="db-peak"></div><div id="db-trend" style="max-height:260px;overflow-y:auto;margin-top:8px;"></div></div>
  <div class="card"><h3 class="card-title">🏪 Per Toko</h3><div id="db-toko"></div></div>
  <div class="card"><h3 class="card-title">🏆 Top 10 Produk Retur</h3><div id="db-top-retur"></div></div>
  <div class="card"><h3 class="card-title">🏆 Top 10 Produk Reject</h3><div id="db-top-reject"></div></div>
</div>
<style>
  .db-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:var(--space-3); }
  .db-grid > .card { min-width:0; overflow:hidden; }
  .db-bar { display:flex; align-items:center; gap:8px; margin-top:9px; font-size:var(--text-sm); }
  .db-rank { width:22px; color:var(--text-faint); font-weight:700; font-size:12px; }
  .db-main { flex:1; min-width:0; } .db-lbl { display:flex; justify-content:space-between; gap:8px; }
  .db-lbl span:first-child { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .db-track { height:8px; background:var(--surface-2); border-radius:6px; margin-top:4px; overflow:hidden; }
  .db-fill { height:100%; border-radius:6px; background:var(--primary); } .db-fill.reject { background:var(--danger); }
  .db-peak { background:var(--warning-soft); color:var(--warning); border-radius:var(--radius); padding:12px; }
  .db-peak b { font-size:1.15rem; display:block; }
  .db-empty { color:var(--text-muted); font-size:var(--text-sm); padding:10px 0; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var num = function (n) { return (Math.round((Number(n) || 0) * 100) / 100).toLocaleString('id-ID'); };
  var rp = function (n) { return 'Rp ' + Math.round(Number(n) || 0).toLocaleString('id-ID'); };
  function qs() { return 'storeId=' + $('db-store').value + '&factoryId=' + $('db-factory').value + '&from=' + $('db-from').value + '&to=' + $('db-to').value; }
  function bars(el, list, cls) {
    if (!list.length) { el.innerHTML = '<div class="db-empty">Tidak ada data untuk filter ini.</div>'; return; }
    var max = list[0].qty || 1;
    el.innerHTML = list.map(function (x, i) {
      return '<div class="db-bar"><div class="db-rank">#' + (i + 1) + '</div><div class="db-main"><div class="db-lbl"><span>' + esc(x.nama) + '</span><b>' + num(x.qty) + ' pcs</b></div>' +
        '<div class="db-track"><div class="db-fill ' + cls + '" style="width:' + Math.round(x.qty / max * 100) + '%"></div></div></div></div>';
    }).join('');
  }
  function load() {
    Amor.apiFetch('/api/store-reports/dashboard?' + qs()).then(function (d) {
      $('db-retur').textContent = num(d.totalRetur); $('db-reject').textContent = num(d.totalReject); $('db-mutasi').textContent = num(d.totalMutasi);
      $('db-retur-rp').textContent = 'Nilai ' + rp(d.nilaiRetur); $('db-reject-rp').textContent = 'Nilai ' + rp(d.nilaiReject);
      $('db-peak').innerHTML = d.tanggalTerbanyak
        ? '<div class="db-peak"><b>' + esc(d.tanggalTerbanyak) + '</b>' + num(d.tanggalTerbanyakQty) + ' pcs retur pada tanggal ini</div>'
        : '<div class="db-empty">Belum ada data retur untuk filter ini.</div>';
      var maxT = Math.max.apply(null, d.perTanggal.map(function (x) { return x.qty; }).concat([1]));
      $('db-trend').innerHTML = d.perTanggal.map(function (x) {
        return '<div class="db-bar"><div style="width:86px;color:var(--text-muted);font-size:12px">' + esc(x.tanggal) + '</div><div class="db-main"><div class="db-track"><div class="db-fill" style="width:' + Math.round(x.qty / maxT * 100) + '%"></div></div></div><b style="width:40px;text-align:right">' + num(x.qty) + '</b></div>';
      }).join('');
      $('db-toko').innerHTML = d.perToko.length
        ? '<div class="table-scroll"><table class="data-table" style="min-width:0"><thead><tr><th>Toko</th><th class="num">Retur</th><th class="num">Reject</th></tr></thead><tbody>' +
          d.perToko.map(function (t) { return '<tr><td>' + esc(t.nama) + '</td><td class="num">' + num(t.retur) + '</td><td class="num">' + num(t.reject) + '</td></tr>'; }).join('') + '</tbody></table></div>'
        : '<div class="db-empty">Tidak ada data untuk filter ini.</div>';
      bars($('db-top-retur'), d.topRetur, '');
      bars($('db-top-reject'), d.topReject, 'reject');
    }).catch(function (e) { Amor.toast(e.message, 'danger'); });
  }
  $('db-apply').addEventListener('click', load);
  $('db-export').addEventListener('click', function (e) { e.preventDefault(); location.href = '/api/store-reports/export?' + qs(); });
  load();
});
</script>
