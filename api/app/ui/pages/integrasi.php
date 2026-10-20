<?php

declare(strict_types=1);

/**
 * Admin "Integrasi Finhub" — create/revoke the key finhub uses to READ
 * shipments and store receipts (migration 0022). The raw key is shown
 * once; the server stores only its hash.
 */

if (array_intersect(['ADMIN'], $ui['roles']) === []) {
    echo ui_empty_state('Akses Ditolak', 'Halaman ini khusus Admin.');
    return;
}

$baseUrl = rtrim((string) \Amor\Api\Config::get('APP_BASE_URL', ''), '/');
if ($baseUrl === '') {
    $baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$feedUrl = $baseUrl . '/api/integration/v1/shipments';
?>
<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-2);">Sambungan ke finhub</h3>
  <p style="color:var(--text-muted);font-size:var(--text-sm);margin-bottom:var(--space-3);">
    Toko mengonfirmasi penerimaan <strong>satu kali</strong>, di factory (portal toko atau QR di surat jalan).
    finhub hanya <strong>membaca</strong> hasilnya: surat jalan, jumlah dikirim, diterima baik, reject, dan kurang per produk,
    lalu membuat file Stok Masuk Runchise. Kunci ini tidak bisa mengubah data apa pun di factory.
  </p>
  <div class="field" style="max-width:420px;"><label for="ik-name">Nama kunci</label>
    <input type="text" id="ik-name" value="finhub" maxlength="80"></div>
  <button type="button" class="btn btn-primary" id="btn-ik-issue" style="margin-top:var(--space-2);">Buat Kunci Baru</button>
  <div id="ik-new" style="display:none;margin-top:var(--space-3);" class="alert alert-success"></div>
</div>

<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-2);">Kunci yang ada</h3>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Nama</th><th>Awalan</th><th>Dibuat</th><th>Terakhir dipakai</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody id="ik-rows"><tr><td colspan="6" style="color:var(--text-faint);">Memuat...</td></tr></tbody>
  </table></div>
</div>

<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-2);">Untuk pembuat finhub</h3>
  <p style="color:var(--text-muted);font-size:var(--text-sm);">Permintaan (hanya baca):</p>
  <pre style="white-space:pre-wrap;font-size:var(--text-sm);">GET <?= ui_esc($feedUrl) ?>?from=2026-10-01&amp;to=2026-10-01
Header: X-Api-Key: &lt;kunci&gt;</pre>
  <p style="color:var(--text-muted);font-size:var(--text-sm);">
    Rentang maksimal 31 hari. Tiap surat jalan berisi toko, No. DO/SJ, status konfirmasi, dan per produk:
    <code>shippedQty</code>, <code>receivedGoodQty</code>, <code>rejectQty</code>, <code>shortageQty</code>
    (kosong selama toko belum konfirmasi). Untuk stok masuk Runchise pakai <code>receivedGoodQty</code>.
  </p>
</div>

<script>
// app.js (window.Amor) loads after the page body — start once it is there.
document.addEventListener('DOMContentLoaded', function () {
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function fmt(s) { return s ? esc(s.replace('T', ' ').slice(0, 16)) + ' UTC' : '-'; }
  async function load() {
    var tb = document.getElementById('ik-rows');
    try {
      var rows = await Amor.apiFetch('/api/admin/integration-keys');
      if (!rows.length) { tb.innerHTML = '<tr><td colspan="6" style="color:var(--text-faint);">Belum ada kunci.</td></tr>'; return; }
      tb.innerHTML = rows.map(function (r) {
        return '<tr><td>' + esc(r.name) + '</td><td><code>' + esc(r.keyPrefix) + '…</code></td><td>' + fmt(r.createdAt) + (r.createdBy ? '<div style="color:var(--text-faint);font-size:var(--text-xs);">' + esc(r.createdBy) + '</div>' : '') + '</td>'
          + '<td>' + fmt(r.lastUsedAt) + '</td><td>' + (r.active ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge">Dicabut</span>') + '</td>'
          + '<td>' + (r.active ? '<button type="button" class="btn btn-danger btn-sm" data-revoke="' + r.id + '">Cabut</button>' : '') + '</td></tr>';
      }).join('');
      tb.querySelectorAll('[data-revoke]').forEach(function (b) {
        b.addEventListener('click', async function () {
          var ok = await Amor.confirmModal({ title: 'Cabut kunci ini?', body: 'finhub yang memakai kunci ini langsung tidak bisa membaca data lagi.', confirmLabel: 'Ya, Cabut' });
          if (!ok) return;
          try { await Amor.apiFetch('/api/admin/integration-keys/' + b.getAttribute('data-revoke') + '/revoke', { method: 'POST', body: {} }); Amor.toast('Kunci dicabut.', 'success'); load(); }
          catch (e) { Amor.toast(e.message, 'danger'); }
        });
      });
    } catch (e) { tb.innerHTML = '<tr><td colspan="6">' + esc(e.message) + '</td></tr>'; }
  }
  document.getElementById('btn-ik-issue').addEventListener('click', async function () {
    var btn = this; btn.disabled = true;
    try {
      var r = await Amor.apiFetch('/api/admin/integration-keys', { method: 'POST', body: { name: document.getElementById('ik-name').value } });
      var box = document.getElementById('ik-new');
      box.style.display = '';
      box.innerHTML = '<strong>Kunci baru — salin sekarang, hanya ditampilkan sekali:</strong>'
        + '<div style="margin-top:6px;"><input type="text" readonly id="ik-raw" style="width:100%;font-family:monospace;" value="' + esc(r.rawKey) + '"></div>'
        + '<button type="button" class="btn btn-secondary btn-sm" id="ik-copy" style="margin-top:6px;">Salin</button>';
      document.getElementById('ik-copy').addEventListener('click', function () { var i = document.getElementById('ik-raw'); i.select(); try { navigator.clipboard.writeText(i.value); } catch (e) { document.execCommand('copy'); } Amor.toast('Disalin.', 'success'); });
      load();
    } catch (e) { Amor.toast(e.message, 'danger'); }
    btn.disabled = false;
  });
  load();
});
</script>
