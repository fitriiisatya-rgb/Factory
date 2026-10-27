<?php

declare(strict_types=1);

/**
 * Master Alasan Reject (migration 0026) — the list a store picks from when
 * it reports a Reject (the old "Master Alasan Reject" sheet). Admin adds,
 * renames, reorders or switches a reason off; a switched-off reason stays
 * on past reports but is no longer offered.
 */

$canEdit = in_array('ADMIN', $ui['roles'], true);
?>
<div class="btn-group section">
  <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=validasi-toko">← Retur, Reject &amp; Mutasi</a>
</div>
<?php if ($canEdit): ?>
<div class="card section">
  <div class="filter-bar" style="gap:var(--space-2);flex-wrap:wrap;">
    <label style="flex:1;min-width:220px;">Alasan baru <input type="text" id="ar-name" maxlength="150" placeholder="mis. Kemasan Sobek"></label>
    <label>Urutan <input type="number" id="ar-sort" value="500" style="width:90px"></label>
    <button type="button" class="btn btn-primary" id="ar-add">+ Tambah</button>
  </div>
</div>
<?php endif; ?>
<div class="card section">
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Urutan</th><th>Alasan</th><th>Status</th><?= $canEdit ? '<th>Aksi</th>' : '' ?></tr></thead>
    <tbody id="ar-body"><tr><td colspan="4">Memuat...</td></tr></tbody>
  </table></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var CAN = <?= $canEdit ? 'true' : 'false' ?>;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var body = document.getElementById('ar-body');
  function save(id, data) {
    return Amor.apiFetch(id ? '/api/reject-reasons/' + id : '/api/reject-reasons', { method: id ? 'PUT' : 'POST', body: data })
      .then(function () { Amor.toast('Tersimpan.', 'success'); load(); })
      .catch(function (e) { Amor.toast(e.message, 'danger'); });
  }
  function load() {
    Amor.apiFetch('/api/reject-reasons?all=1').then(function (rows) {
      body.innerHTML = rows.map(function (r) {
        return '<tr data-id="' + r.id + '"><td>' + (CAN ? '<input type="number" class="ar-sort" value="' + r.sortOrder + '" style="width:80px">' : r.sortOrder) + '</td>' +
          '<td>' + (CAN ? '<input type="text" class="ar-n" value="' + esc(r.name) + '" maxlength="150" style="width:100%">' : esc(r.name)) + '</td>' +
          '<td>' + (r.active ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-neutral">Nonaktif</span>') + '</td>' +
          (CAN ? '<td class="row-actions"><button type="button" class="btn btn-secondary btn-sm ar-save">Simpan</button>' +
            '<button type="button" class="btn btn-sm ' + (r.active ? 'btn-danger' : 'btn-success') + ' ar-toggle">' + (r.active ? 'Nonaktifkan' : 'Aktifkan') + '</button></td>' : '') + '</tr>';
      }).join('') || '<tr><td colspan="4">Belum ada alasan.</td></tr>';
      body.querySelectorAll('tr[data-id]').forEach(function (tr) {
        var r = rows.filter(function (x) { return String(x.id) === tr.dataset.id; })[0];
        var sv = tr.querySelector('.ar-save'), tg = tr.querySelector('.ar-toggle');
        if (sv) sv.addEventListener('click', function () { save(r.id, { name: tr.querySelector('.ar-n').value, sortOrder: parseInt(tr.querySelector('.ar-sort').value, 10) || 0, active: r.active }); });
        if (tg) tg.addEventListener('click', function () { save(r.id, { name: r.name, sortOrder: r.sortOrder, active: !r.active }); });
      });
    }).catch(function (e) { Amor.toast(e.message, 'danger'); });
  }
  var add = document.getElementById('ar-add');
  if (add) add.addEventListener('click', function () {
    var n = document.getElementById('ar-name').value.trim();
    if (!n) { Amor.toast('Tulis nama alasan dulu.', 'warning'); return; }
    save(null, { name: n, sortOrder: parseInt(document.getElementById('ar-sort').value, 10) || 0, active: true }).then(function () { document.getElementById('ar-name').value = ''; });
  });
  load();
});
</script>
