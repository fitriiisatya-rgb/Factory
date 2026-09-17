<?php

declare(strict_types=1);

/**
 * Pesanan Toko — read-only presentation over the existing Phase 2 PO
 * schema (po_batch/po_item/po_store_item). The actual import/revision
 * upload flow is intentionally NOT reimplemented here — it stays on
 * api/_import-po/ (the existing, already-validated PoImporter pipeline);
 * this page only queries and displays what that importer already wrote.
 */

$storeIdParam = isset($_GET['storeId']) && $_GET['storeId'] !== '' ? (int) $_GET['storeId'] : null;
$searchTerm = trim((string) ($_GET['q'] ?? ''));

$batch = $pdo->prepare('SELECT * FROM po_batch WHERE tanggal = ? AND factory_id = ?');
$batch->execute([$uiTanggal, $uiFactoryId]);
$batchRow = $batch->fetch();

$summary = ['target' => 0.0, 'awal' => 0.0, 'revisi' => 0.0, 'stores' => 0, 'products' => 0];
$storeRows = [];
$productRows = [];
$storeName = null;

if ($batchRow !== null) {
    $sums = $pdo->prepare(
        'SELECT COALESCE(SUM(po_awal),0) a, COALESCE(SUM(po_revisi),0) r, COUNT(DISTINCT product_id) p
         FROM po_item WHERE po_batch_id = ?'
    );
    $sums->execute([$batchRow['po_batch_id']]);
    $s = $sums->fetch();
    $summary['awal'] = (float) $s['a'];
    $summary['revisi'] = (float) $s['r'];
    $summary['target'] = $summary['awal'] + $summary['revisi'];
    $summary['products'] = (int) $s['p'];

    $storeCount = $pdo->prepare(
        'SELECT COUNT(DISTINCT si.store_id) FROM po_store_item si
         INNER JOIN po_item i ON i.po_item_id = si.po_item_id WHERE i.po_batch_id = ?'
    );
    $storeCount->execute([$batchRow['po_batch_id']]);
    $summary['stores'] = (int) $storeCount->fetchColumn();

    if ($storeIdParam !== null) {
        // Per-product breakdown for ONE store.
        $stmt = $pdo->prepare(
            'SELECT p.product_id, p.name AS product_name, si.po_awal, si.po_revisi, s.canonical_name AS store_name
             FROM po_store_item si
             INNER JOIN po_item i ON i.po_item_id = si.po_item_id
             INNER JOIN product p ON p.product_id = i.product_id
             INNER JOIN store s ON s.store_id = si.store_id
             WHERE i.po_batch_id = ? AND si.store_id = ?
             ORDER BY p.name'
        );
        $stmt->execute([$batchRow['po_batch_id'], $storeIdParam]);
        $productRows = $stmt->fetchAll();
        $storeName = $productRows[0]['store_name'] ?? null;
    } else {
        // Aggregated by store.
        $sql = 'SELECT s.store_id, s.canonical_name AS store_name,
                        COUNT(DISTINCT si.po_item_id) AS product_count,
                        SUM(si.po_awal) AS awal, SUM(si.po_revisi) AS revisi
                 FROM po_store_item si
                 INNER JOIN po_item i ON i.po_item_id = si.po_item_id
                 INNER JOIN store s ON s.store_id = si.store_id
                 WHERE i.po_batch_id = ?';
        $params = [$batchRow['po_batch_id']];
        if ($searchTerm !== '') {
            $sql .= ' AND s.canonical_name LIKE ?';
            $params[] = '%' . $searchTerm . '%';
        }
        $sql .= ' GROUP BY s.store_id, s.canonical_name ORDER BY s.canonical_name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $storeRows = $stmt->fetchAll();
    }
}

$history = $pdo->prepare('SELECT * FROM po_batch WHERE factory_id = ? ORDER BY tanggal DESC, updated_at DESC LIMIT 8');
$history->execute([$uiFactoryId]);
$historyRows = $history->fetchAll();
?>
<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="pesanan-toko">
    <div class="field"><label>Tanggal</label><input type="date" name="tanggal" value="<?= ui_esc($uiTanggal) ?>"></div>
    <div class="field"><label>Pabrik</label>
      <select name="factoryId">
        <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['factory_id'] ?>" <?= $uiFactoryId === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Cari Toko</label><input type="text" name="q" value="<?= ui_esc($searchTerm) ?>" placeholder="Nama toko..."></div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
    <a href="/api/_import-po/" class="btn btn-secondary">Import / Revisi PO</a>
  </form>
</div>

<?php if ($batchRow === null): ?>
  <div class="card"><?= ui_empty_state('Belum ada PO', 'Belum ada PO untuk tanggal & pabrik ini. Gunakan tombol "Import / Revisi PO" untuk mengimpor file PO.') ?></div>
<?php else: ?>

<div class="kpi-grid">
  <?= ui_kpi_card(['label' => 'Total Target', 'value' => ui_fmt_num($summary['target']), 'icon' => 'cart', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'PO Awal', 'value' => ui_fmt_num($summary['awal']), 'icon' => 'file', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'PO Tambahan/Revisi', 'value' => ui_fmt_num($summary['revisi']), 'icon' => 'file', 'color' => 'warning']) ?>
  <?= ui_kpi_card(['label' => 'Jumlah Toko', 'value' => (string) $summary['stores'], 'icon' => 'building', 'color' => 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'Jumlah Produk', 'value' => (string) $summary['products'], 'icon' => 'box', 'color' => 'neutral']) ?>
</div>

<div class="table-card section">
  <?php if ($storeIdParam !== null): ?>
  <div style="padding:var(--space-4) var(--space-4) 0;">
    <a href="/api/_ui-preview/?page=pesanan-toko&tanggal=<?= urlencode($uiTanggal) ?>&factoryId=<?= $uiFactoryId ?>">&larr; Semua Toko</a>
    <h3 style="margin-top:var(--space-2);">Detail PO — <?= ui_esc((string) $storeName) ?></h3>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Produk</th><th class="num">PO Awal</th><th class="num">PO Revisi</th><th class="num">Target</th></tr></thead>
    <tbody>
    <?php if ($productRows === []): ?>
    <tr><td colspan="4"><?= ui_empty_state('Toko ini tidak punya item PO', '') ?></td></tr>
    <?php else: foreach ($productRows as $r): ?>
    <tr>
      <td><?= ui_esc($r['product_name']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['po_awal']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['po_revisi']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['po_awal'] + (float) $r['po_revisi']) ?></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Toko</th><th class="num">Jumlah Produk</th><th class="num">PO Awal</th><th class="num">PO Revisi</th><th class="num">Target</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if ($storeRows === []): ?>
    <tr><td colspan="6"><?= ui_empty_state('Tidak ada toko yang cocok', 'Coba ubah kata kunci pencarian.') ?></td></tr>
    <?php else: foreach ($storeRows as $r): ?>
    <tr>
      <td><?= ui_esc($r['store_name']) ?></td>
      <td class="num"><?= (int) $r['product_count'] ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['awal']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['revisi']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['awal'] + (float) $r['revisi']) ?></td>
      <td class="row-actions">
        <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=pesanan-toko&tanggal=<?= urlencode($uiTanggal) ?>&factoryId=<?= $uiFactoryId ?>&storeId=<?= (int) $r['store_id'] ?>">Lihat Item</a>
        <a class="btn btn-primary btn-sm" href="/api/_ui-preview/?page=delivery-order&tanggal=<?= urlencode($uiTanggal) ?>&factoryId=<?= $uiFactoryId ?>">Buat DO</a>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Riwayat PO — <?= ui_esc($uiFactoryName) ?></h2></div>
  <?php if ($historyRows === []): ?>
    <?= ui_empty_state('Belum ada riwayat', '') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Tanggal</th><th class="num">Versi</th><th>Terakhir Diperbarui</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($historyRows as $h): ?>
    <tr>
      <td><?= ui_esc($h['tanggal']) ?></td>
      <td class="num"><?= (int) $h['version'] ?></td>
      <td><?= ui_esc((string) ($h['updated_at'] ?? $h['created_at'])) ?></td>
      <td><a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=pesanan-toko&tanggal=<?= urlencode($h['tanggal']) ?>&factoryId=<?= $uiFactoryId ?>">Lihat</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
