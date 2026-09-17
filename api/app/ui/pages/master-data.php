<?php

declare(strict_types=1);

/**
 * Read-only master data browser. No create/edit/delete actions here yet —
 * the existing product/store admin APIs support updates, but a full edit
 * UI is out of scope for this preview pass (task's own "prefer controlled
 * edit over destructive default" plus limited time — safer to ship
 * nothing than a half-built edit flow). Creating/editing master data
 * stays on the existing flows (api/_import-po/'s new-product review, and
 * direct API calls) for now.
 */

$tab = (string) ($_GET['tab'] ?? 'produk');
$searchTerm = trim((string) ($_GET['q'] ?? ''));
$tabs = ['produk' => 'Produk', 'toko' => 'Toko', 'alias-toko' => 'Alias Toko', 'divisi' => 'Divisi', 'pabrik' => 'Pabrik'];
if (!isset($tabs[$tab])) {
    $tab = 'produk';
}
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
  </form>
</div>

<div class="table-card">
<?php if ($tab === 'produk'):
  $sql = 'SELECT p.product_id, p.name, p.kategori, p.aktif, d.name AS division_name FROM product p LEFT JOIN division d ON d.division_id = p.division_id WHERE 1=1';
  $params = [];
  if ($searchTerm !== '') { $sql .= ' AND p.name LIKE ?'; $params[] = '%' . $searchTerm . '%'; }
  $sql .= ' ORDER BY p.name LIMIT 300';
  $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
  ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Kode</th><th>Nama</th><th>Kategori</th><th>Divisi</th><th>Status</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="5"><?= ui_empty_state('Tidak ada produk yang cocok', '') ?></td></tr>
    <?php else: foreach ($rows as $r): ?>
    <tr>
      <td>#<?= (int) $r['product_id'] ?></td>
      <td><?= ui_esc($r['name']) ?></td>
      <td><?= ui_esc((string) ($r['kategori'] ?? '-')) ?></td>
      <td><?= ui_esc((string) ($r['division_name'] ?? '-')) ?></td>
      <td><?= (int) $r['aktif'] === 1 ? ui_badge('Aktif') : ui_badge('Nonaktif') ?></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
  <p style="padding:0 var(--space-4) var(--space-4);color:var(--text-muted);font-size:var(--text-xs);">Menampilkan maksimal 300 baris. Gunakan pencarian untuk mempersempit.</p>

<?php elseif ($tab === 'toko'):
  $sql = 'SELECT store_id, canonical_name, channel, active FROM store WHERE 1=1';
  $params = [];
  if ($searchTerm !== '') { $sql .= ' AND canonical_name LIKE ?'; $params[] = '%' . $searchTerm . '%'; }
  $sql .= ' ORDER BY canonical_name LIMIT 300';
  $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
  ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Store ID</th><th>Nama Toko</th><th>Channel</th><th>Status</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="4"><?= ui_empty_state('Tidak ada toko yang cocok', '') ?></td></tr>
    <?php else: foreach ($rows as $r): ?>
    <tr>
      <td>#<?= (int) $r['store_id'] ?></td>
      <td><?= ui_esc($r['canonical_name']) ?></td>
      <td><?= ui_esc((string) ($r['channel'] ?? '-')) ?></td>
      <td><?= (int) $r['active'] === 1 ? ui_badge('Aktif') : ui_badge('Nonaktif') ?></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'alias-toko'):
  $sql = 'SELECT sa.raw_name, sa.factory_hint, s.canonical_name FROM store_alias sa INNER JOIN store s ON s.store_id = sa.store_id WHERE 1=1';
  $params = [];
  if ($searchTerm !== '') { $sql .= ' AND (sa.raw_name LIKE ? OR s.canonical_name LIKE ?)'; $params[] = '%' . $searchTerm . '%'; $params[] = '%' . $searchTerm . '%'; }
  $sql .= ' ORDER BY s.canonical_name LIMIT 300';
  $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
  ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Alias (Nama Mentah)</th><th>Toko Resmi</th><th>Petunjuk Pabrik</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="3"><?= ui_empty_state('Tidak ada alias yang cocok', '') ?></td></tr>
    <?php else: foreach ($rows as $r): ?>
    <tr><td><?= ui_esc($r['raw_name']) ?></td><td><?= ui_esc($r['canonical_name']) ?></td><td><?= ui_esc((string) ($r['factory_hint'] ?? '-')) ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>

<?php elseif ($tab === 'divisi'):
  $rows = $pdo->query('SELECT d.division_id, d.name, d.is_verification, f.name AS factory_name FROM division d INNER JOIN factory f ON f.factory_id = d.factory_id ORDER BY f.name, d.name')->fetchAll();
  ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Divisi</th><th>Pabrik</th><th>Tipe</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr><td><?= ui_esc($r['name']) ?></td><td><?= ui_esc($r['factory_name']) ?></td><td><?= (int) $r['is_verification'] === 1 ? 'Verifikasi FG' : 'Produksi' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

<?php else: // pabrik
  $rows = $pdo->query('SELECT factory_id, code, name FROM factory ORDER BY factory_id')->fetchAll();
  ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Kode</th><th>Nama Pabrik</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr><td><?= ui_esc($r['code']) ?></td><td><?= ui_esc($r['name']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
