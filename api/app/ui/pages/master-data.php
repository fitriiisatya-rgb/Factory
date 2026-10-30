<?php

declare(strict_types=1);

/**
 * Master Data: Produk, Toko, Alias Toko, Divisi, Pabrik. Leader Admin can
 * add / edit / delete on every tab; Admin Pabrik (and other roles) only
 * read. Delete goes through MasterDataService, which refuses a record that
 * is already used anywhere (a used product/store can be set Nonaktif
 * instead). Product and store add/edit reuse the existing
 * POST/PUT /api/products and /api/stores endpoints.
 */

$tab = (string) ($_GET['tab'] ?? 'produk');
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$tabs = ['produk' => 'Produk', 'toko' => 'Toko', 'alias-toko' => 'Alias Toko', 'divisi' => 'Divisi', 'pabrik' => 'Pabrik'];
if (!isset($tabs[$tab])) {
    $tab = 'produk';
}
$canEdit = in_array('ADMIN', $ui['roles'], true) && !\Amor\Api\Auth::isFactoryAdmin();
$divisionOptions = $pdo->query('SELECT d.division_id, d.name, f.name AS factory_name FROM division d INNER JOIN factory f ON f.factory_id = d.factory_id ORDER BY f.factory_id, d.name')->fetchAll();
$factoryOptions = $pdo->query('SELECT factory_id, code, name FROM factory ORDER BY factory_id')->fetchAll();
$storeOptions = $tab === 'alias-toko' ? $pdo->query('SELECT store_id, canonical_name FROM store ORDER BY canonical_name')->fetchAll() : [];
$rupiah = static fn ($v): string => 'Rp' . number_format((float) $v, 0, ',', '.');
$addLabels = ['produk' => 'Tambah Produk', 'toko' => 'Tambah Toko', 'alias-toko' => 'Tambah Alias', 'divisi' => 'Tambah Divisi', 'pabrik' => 'Tambah Pabrik'];
$actions = static fn (array $r): string => '<td style="white-space:nowrap;"><button type="button" class="btn btn-secondary btn-sm md-edit" data-row="' . ui_esc((string) json_encode($r, JSON_UNESCAPED_UNICODE)) . '">Edit</button> '
    . '<button type="button" class="btn btn-danger btn-sm md-delete" data-row="' . ui_esc((string) json_encode($r, JSON_UNESCAPED_UNICODE)) . '">Hapus</button></td>';
?>
<div class="filter-bar">
  <div class="btn-group">
    <?php foreach ($tabs as $key => $label): ?>
    <a class="btn <?= $tab === $key ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="/api/_ui-preview/?page=master-data&tab=<?= $key ?>&tanggal=<?= urlencode($uiTanggal) ?>&factoryId=<?= $uiFactoryId ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;margin-left:auto;">
    <input type="hidden" name="page" value="master-data">
    <input type="hidden" name="tab" value="<?= ui_esc($tab) ?>">
    <div class="field"><label>Cari</label><input type="text" name="q" value="<?= ui_esc($searchTerm) ?>"></div>
    <button type="submit" class="btn btn-secondary">Cari</button>
    <?php if ($canEdit): ?><button type="button" class="btn btn-primary" id="md-add"><?= $addLabels[$tab] ?></button><?php endif; ?>
  </form>
</div>
<?php if ($canEdit): ?>
<p style="color:var(--text-muted);font-size:var(--text-sm);margin:0 0 var(--space-3);">Hapus hanya bisa untuk data yang belum pernah dipakai. Produk/toko yang sudah ada transaksinya: Edit → Status <b>Nonaktif</b>, supaya riwayat & invoice tetap utuh.</p>
<?php endif; ?>

<div class="table-card">
<?php if ($tab === 'produk'):
  $sql = 'SELECT p.product_id, p.name, p.kategori, p.aktif, p.harga, p.hpp, p.version, p.division_id, d.name AS division_name FROM product p LEFT JOIN division d ON d.division_id = p.division_id WHERE 1=1';
  $params = [];
  if ($searchTerm !== '') { $sql .= ' AND p.name LIKE ?'; $params[] = '%' . $searchTerm . '%'; }
  $sql .= ' ORDER BY p.name LIMIT 300';
  $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
  ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Kode</th><th>Nama</th><th>Kategori</th><th>Divisi</th><th class="num">Harga</th><th>Status</th><?= $canEdit ? '<th>Aksi</th>' : '' ?></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="7"><?= ui_empty_state('Tidak ada produk yang cocok', '') ?></td></tr>
    <?php else: foreach ($rows as $r): ?>
    <tr>
      <td>#<?= (int) $r['product_id'] ?></td>
      <td><?= ui_esc($r['name']) ?></td>
      <td><?= ui_esc((string) ($r['kategori'] ?? '-')) ?></td>
      <td><?= ui_esc((string) ($r['division_name'] ?? '-')) ?></td>
      <td class="num"><?= $rupiah($r['harga']) ?></td>
      <td><?= (int) $r['aktif'] === 1 ? ui_badge('Aktif') : ui_badge('Nonaktif') ?></td>
      <?= $canEdit ? $actions(['id' => (int) $r['product_id'], 'name' => $r['name'], 'kategori' => $r['kategori'], 'divisionId' => $r['division_id'] !== null ? (int) $r['division_id'] : null, 'harga' => (float) $r['harga'], 'hpp' => (float) $r['hpp'], 'aktif' => (int) $r['aktif'] === 1, 'version' => (int) $r['version']]) : '' ?>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
  <p style="padding:0 var(--space-4) var(--space-4);color:var(--text-muted);font-size:var(--text-xs);">Menampilkan maksimal 300 baris. Gunakan pencarian untuk mempersempit.</p>

<?php elseif ($tab === 'toko'):
  $sql = 'SELECT store_id, canonical_name, email, channel, active, version FROM store WHERE 1=1';
  $params = [];
  if ($searchTerm !== '') { $sql .= ' AND canonical_name LIKE ?'; $params[] = '%' . $searchTerm . '%'; }
  $sql .= ' ORDER BY canonical_name LIMIT 300';
  $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
  ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Store ID</th><th>Nama Toko</th><th>Email Penerimaan</th><th>Channel</th><th>Status</th><?= $canEdit ? '<th>Aksi</th>' : '' ?></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="6"><?= ui_empty_state('Tidak ada toko yang cocok', '') ?></td></tr>
    <?php else: foreach ($rows as $r): ?>
    <tr>
      <td>#<?= (int) $r['store_id'] ?></td>
      <td><?= ui_esc($r['canonical_name']) ?></td>
      <td><?= ui_esc((string) ($r['email'] ?? '')) ?: '<span style="color:var(--text-faint);">belum diisi</span>' ?></td>
      <td><?= ui_esc((string) ($r['channel'] ?? '-')) ?></td>
      <td><?= (int) $r['active'] === 1 ? ui_badge('Aktif') : ui_badge('Nonaktif') ?></td>
      <?= $canEdit ? $actions(['id' => (int) $r['store_id'], 'name' => $r['canonical_name'], 'email' => $r['email'], 'channel' => $r['channel'], 'active' => (int) $r['active'] === 1, 'version' => (int) $r['version']]) : '' ?>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'alias-toko'):
  $sql = 'SELECT sa.store_alias_id, sa.store_id, sa.raw_name, sa.factory_hint, s.canonical_name FROM store_alias sa INNER JOIN store s ON s.store_id = sa.store_id WHERE 1=1';
  $params = [];
  if ($searchTerm !== '') { $sql .= ' AND (sa.raw_name LIKE ? OR s.canonical_name LIKE ?)'; $params[] = '%' . $searchTerm . '%'; $params[] = '%' . $searchTerm . '%'; }
  $sql .= ' ORDER BY s.canonical_name LIMIT 300';
  $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
  ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Alias (Nama di File PO)</th><th>Toko Resmi</th><th>Petunjuk Pabrik</th><?= $canEdit ? '<th>Aksi</th>' : '' ?></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="4"><?= ui_empty_state('Tidak ada alias yang cocok', '') ?></td></tr>
    <?php else: foreach ($rows as $r): ?>
    <tr><td><?= ui_esc($r['raw_name']) ?></td><td><?= ui_esc($r['canonical_name']) ?></td><td><?= ui_esc((string) ($r['factory_hint'] ?? '-')) ?></td>
      <?= $canEdit ? $actions(['id' => (int) $r['store_alias_id'], 'name' => $r['raw_name'], 'storeId' => (int) $r['store_id'], 'factoryHint' => $r['factory_hint']]) : '' ?></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'divisi'):
  $rows = $pdo->query('SELECT d.division_id, d.name, d.is_verification, f.name AS factory_name, (SELECT COUNT(*) FROM product p WHERE p.division_id = d.division_id) AS products FROM division d INNER JOIN factory f ON f.factory_id = d.factory_id ORDER BY f.name, d.name')->fetchAll();
  ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Divisi</th><th>Pabrik</th><th>Tipe</th><th class="num">Jumlah Produk</th><?= $canEdit ? '<th>Aksi</th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr><td><?= ui_esc($r['name']) ?></td><td><?= ui_esc($r['factory_name']) ?></td><td><?= (int) $r['is_verification'] === 1 ? 'Verifikasi FG' : 'Produksi' ?></td><td class="num"><?= (int) $r['products'] ?></td>
      <?= $canEdit ? $actions(['id' => (int) $r['division_id'], 'name' => $r['name']]) : '' ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

<?php else: // pabrik
  $rows = $factoryOptions;
  ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Kode</th><th>Nama Pabrik</th><?= $canEdit ? '<th>Aksi</th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr><td><?= ui_esc($r['code']) ?></td><td><?= ui_esc($r['name']) ?></td>
      <?= $canEdit ? $actions(['id' => (int) $r['factory_id'], 'name' => $r['name'], 'code' => $r['code']]) : '' ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>

<?php if ($canEdit): ?>
<script>
(function () {
  var TAB = <?= json_encode($tab) ?>;
  var DIVISIONS = <?= json_encode(array_map(static fn ($d) => ['id' => (int) $d['division_id'], 'label' => $d['name'] . ' (' . $d['factory_name'] . ')'], $divisionOptions), JSON_UNESCAPED_UNICODE) ?>;
  var FACTORIES = <?= json_encode(array_map(static fn ($f) => ['id' => (int) $f['factory_id'], 'label' => $f['name']], $factoryOptions), JSON_UNESCAPED_UNICODE) ?>;
  var STORES = <?= json_encode(array_map(static fn ($s) => ['id' => (int) $s['store_id'], 'label' => $s['canonical_name']], $storeOptions), JSON_UNESCAPED_UNICODE) ?>;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function field(label, html) { return '<label style="display:block;margin-bottom:10px;color:var(--text);">' + label + '<br>' + html + '</label>'; }
  function input(name, value, type, extra) { return '<input class="input" style="width:100%;" name="' + name + '" type="' + (type || 'text') + '" value="' + esc(value) + '"' + (extra || '') + '>'; }
  function select(name, options, value, allowEmpty) {
    return '<select class="input" style="width:100%;" name="' + name + '">' + (allowEmpty ? '<option value="">' + allowEmpty + '</option>' : '') +
      options.map(function (o) { return '<option value="' + esc(o.id) + '"' + (String(o.id) === String(value) ? ' selected' : '') + '>' + esc(o.label) + '</option>'; }).join('') + '</select>';
  }
  function val(name) { var el = document.querySelector('.modal [name="' + name + '"]'); return el ? el.value.trim() : ''; }
  var ACTIVE = [{ id: '1', label: 'Aktif' }, { id: '0', label: 'Nonaktif' }];

  var FORMS = {
    produk: {
      noun: 'produk',
      body: function (r) {
        return field('Nama produk', input('name', r.name)) + field('Kategori', input('kategori', r.kategori)) +
          field('Divisi', select('divisionId', DIVISIONS, r.divisionId, '— pilih divisi —')) +
          field('Harga jual (Rp)', input('harga', r.harga != null ? r.harga : 0, 'number', ' min="0" step="1"')) +
          (r.id ? field('Status', select('aktif', ACTIVE, r.aktif ? '1' : '0')) : '');
      },
      save: function (r) {
        var body = { name: val('name'), kategori: val('kategori') || null, divisionId: val('divisionId') ? parseInt(val('divisionId'), 10) : null, harga: parseFloat(val('harga') || '0') };
        if (!r.id) return Amor.apiFetch('/api/products', { method: 'POST', body: body });
        body.aktif = val('aktif') === '1'; body.version = r.version;
        return Amor.apiFetch('/api/products/' + r.id, { method: 'PUT', body: body });
      },
      del: function (r) { return '/api/products/' + r.id; },
    },
    toko: {
      noun: 'toko',
      body: function (r) {
        return field('Nama toko', input('name', r.name)) +
          field('Channel', select('channel', [{ id: 'ownership', label: 'ownership' }, { id: 'franchise', label: 'franchise' }], r.channel, '— kosong —')) +
          (r.id ? field('Email penerimaan', input('email', r.email, 'email', ' placeholder="kosongkan bila tidak ada"')) + field('Status', select('active', ACTIVE, r.active ? '1' : '0')) : '');
      },
      save: function (r) {
        if (!r.id) return Amor.apiFetch('/api/stores', { method: 'POST', body: { canonicalName: val('name'), channel: val('channel') || null } });
        return Amor.apiFetch('/api/stores/' + r.id, { method: 'PUT', body: { version: r.version, canonicalName: val('name'), channel: val('channel') || null, email: val('email'), active: val('active') === '1' } });
      },
      del: function (r) { return '/api/stores/' + r.id; },
    },
    'alias-toko': {
      noun: 'alias',
      body: function (r) {
        return field('Alias (nama toko seperti tertulis di file PO)', input('name', r.name)) + field('Toko resmi', select('storeId', STORES, r.storeId)) +
          field('Petunjuk pabrik', select('factoryHint', [{ id: 'karangtengah', label: 'karangtengah' }, { id: 'cibadak', label: 'cibadak' }], r.factoryHint, '— kosong —'));
      },
      save: function (r) {
        var storeId = parseInt(val('storeId'), 10);
        if (!r.id) return Amor.apiFetch('/api/stores/' + storeId + '/aliases', { method: 'POST', body: { rawName: val('name'), factoryHint: val('factoryHint') || null } });
        return Amor.apiFetch('/api/store-aliases/' + r.id, { method: 'PUT', body: { rawName: val('name'), storeId: storeId, factoryHint: val('factoryHint') || null } });
      },
      del: function (r) { return '/api/store-aliases/' + r.id; },
    },
    divisi: {
      noun: 'divisi',
      body: function (r) {
        return field('Nama divisi', input('name', r.name)) + (r.id ? '<p style="font-size:var(--text-xs);">Pabrik dan tipe divisi tidak bisa diubah setelah dibuat (riwayat produksi terikat ke sana).</p>' :
          field('Pabrik', select('factoryId', FACTORIES, '')) + field('Tipe', select('isVerification', [{ id: '0', label: 'Produksi' }, { id: '1', label: 'Verifikasi FG' }], '0')));
      },
      save: function (r) {
        if (!r.id) return Amor.apiFetch('/api/divisions', { method: 'POST', body: { name: val('name'), factoryId: parseInt(val('factoryId'), 10), isVerification: val('isVerification') === '1' } });
        return Amor.apiFetch('/api/divisions/' + r.id, { method: 'PUT', body: { name: val('name') } });
      },
      del: function (r) { return '/api/divisions/' + r.id; },
    },
    pabrik: {
      noun: 'pabrik',
      body: function (r) {
        return (r.id ? '<p style="font-size:var(--text-xs);">Kode <b>' + esc(r.code) + '</b> tidak bisa diubah (dipakai di nomor DO & upload PO).</p>' : field('Kode (2–10 huruf, misalnya KTG)', input('code', ''))) +
          field('Nama pabrik', input('name', r.name));
      },
      save: function (r) {
        if (!r.id) return Amor.apiFetch('/api/factories', { method: 'POST', body: { code: val('code'), name: val('name') } });
        return Amor.apiFetch('/api/factories/' + r.id, { method: 'PUT', body: { name: val('name') } });
      },
      del: function (r) { return '/api/factories/' + r.id; },
    },
  };
  var F = FORMS[TAB];

  function openForm(r) {
    Amor.confirmModal({
      title: (r.id ? 'Edit ' : 'Tambah ') + F.noun,
      bodyHtml: F.body(r),
      confirmLabel: 'Simpan',
      pendingLabel: 'Menyimpan...',
      onConfirm: function () { return F.save(r); },
    }).then(function (ok) { if (ok) { Amor.toast('Tersimpan', 'success'); window.location.reload(); } });
  }
  document.getElementById('md-add').addEventListener('click', function () { openForm({}); });
  document.querySelectorAll('.md-edit').forEach(function (b) { b.addEventListener('click', function () { openForm(JSON.parse(b.dataset.row)); }); });
  document.querySelectorAll('.md-delete').forEach(function (b) {
    b.addEventListener('click', function () {
      var r = JSON.parse(b.dataset.row);
      Amor.confirmModal({
        title: 'Hapus ' + F.noun + '?',
        body: '"' + r.name + '" akan dihapus permanen. Kalau sudah pernah dipakai di transaksi, sistem menolak dan menyarankan Nonaktif.',
        confirmLabel: 'Ya, Hapus', danger: true, pendingLabel: 'Menghapus...',
        onConfirm: function () { return Amor.apiFetch(F.del(r), { method: 'DELETE' }); },
      }).then(function (ok) { if (ok) { Amor.toast('Terhapus', 'success'); window.location.reload(); } });
    });
  });
})();
</script>
<?php endif; ?>
