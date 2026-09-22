<?php

declare(strict_types=1);

/**
 * Production's "Order Masuk / Demand Tambahan" inbox — every item from a
 * Pesanan Khusus Toko / Pesanan Non-Toko order that has already been sent
 * to Production, grouped by its OWN division_id (task's own "Production
 * inbox must group/filter items by their own division" — a multi-division
 * order's items appear under each of their own divisions, never bundled
 * under one). A separate page from produksi.php's own Ceklis Produksi
 * actual-entry screen (task's own "Do not overload the existing
 * production actual-entry screen").
 */

use Amor\Api\SpecialOrder\SpecialOrderService;

$divisionIdParam = isset($_GET['divisionId']) && $_GET['divisionId'] !== '' ? (int) $_GET['divisionId'] : null;
$statusParam = (string) ($_GET['status'] ?? '');
$sourceParam = (string) ($_GET['sourceType'] ?? '');
$tanggalParam = (string) ($_GET['requiredDate'] ?? '');

$divisions = $pdo->prepare('SELECT division_id, name FROM division WHERE factory_id = ? ORDER BY name');
$divisions->execute([$uiFactoryId]);
$divisionRows = $divisions->fetchAll();

$service = new SpecialOrderService($pdo);
$inbox = $service->productionInbox(array_filter([
    'factoryId' => $uiFactoryId,
    'divisionId' => $divisionIdParam,
    'status' => $statusParam !== '' ? $statusParam : null,
    'sourceType' => $sourceParam !== '' ? $sourceParam : null,
    'tanggal' => $tanggalParam !== '' ? $tanggalParam : null,
]));
?>
<?= ui_produksi_tabs('produksi-demand', $uiTanggal, $uiFactoryId) ?>

<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="produksi-demand">
    <div class="field"><label>Pabrik</label>
      <select name="factoryId" onchange="this.form.submit()">
        <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['factory_id'] ?>" <?= $uiFactoryId === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Divisi</label>
      <select name="divisionId" onchange="this.form.submit()">
        <option value="">Semua Divisi</option>
        <?php foreach ($divisionRows as $d): ?>
        <option value="<?= (int) $d['division_id'] ?>" <?= $divisionIdParam === (int) $d['division_id'] ? 'selected' : '' ?>><?= ui_esc($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Tanggal Dibutuhkan</label><input type="date" name="requiredDate" value="<?= ui_esc($tanggalParam) ?>"></div>
    <div class="field"><label>Sumber</label>
      <select name="sourceType" onchange="this.form.submit()">
        <option value="">Semua Sumber</option>
        <option value="toko_khusus" <?= $sourceParam === 'toko_khusus' ? 'selected' : '' ?>>Pesanan Khusus Toko</option>
        <option value="non_toko" <?= $sourceParam === 'non_toko' ? 'selected' : '' ?>>Pesanan Non-Toko</option>
      </select>
    </div>
    <div class="field"><label>Status</label>
      <select name="status" onchange="this.form.submit()">
        <option value="">Semua Status</option>
        <?php foreach (['sent_to_production', 'in_production', 'ready', 'completed'] as $st): ?>
        <option value="<?= $st ?>" <?= $statusParam === $st ? 'selected' : '' ?>><?= ui_esc(ui_special_order_status_label($st)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>
</div>

<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-3);">Rincian Demand Produksi (per sumber, tetap terpisah)</h3>
  <div class="kpi-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));">
    <?= ui_kpi_card(['label' => 'Pesanan Khusus Toko', 'value' => ui_fmt_num($inbox['demandBreakdown']['pesananKhususToko']), 'icon' => 'cart', 'color' => 'primary']) ?>
    <?= ui_kpi_card(['label' => 'Pesanan Non-Toko', 'value' => ui_fmt_num($inbox['demandBreakdown']['pesananNonToko']), 'icon' => 'user', 'color' => 'primary']) ?>
    <?= ui_kpi_card(['label' => 'PO Reguler', 'value' => '—', 'icon' => 'file', 'color' => 'neutral']) ?>
    <?= ui_kpi_card(['label' => 'Replacement Reject', 'value' => '—', 'icon' => 'file', 'color' => 'neutral']) ?>
  </div>
  <p style="color:var(--text-muted);font-size:var(--text-xs);margin-top:var(--space-2);"><?= ui_esc($inbox['demandBreakdown']['note']) ?></p>
</div>

<?php if ($inbox['divisions'] === []): ?>
<div class="card section"><?= ui_empty_state('Belum ada Order Masuk / Demand Tambahan', 'Belum ada Pesanan Khusus Toko atau Pesanan Non-Toko yang dikirim ke Produksi sesuai filter ini.') ?></div>
<?php else: foreach ($inbox['divisions'] as $div): ?>
<div class="table-card section">
  <div class="card-head" style="padding:var(--space-4) var(--space-4) 0;">
    <h2 class="card-title"><?= ui_esc($div['divisionName']) ?></h2>
    <span class="badge badge-neutral"><?= count($div['items']) ?> item</span>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No. Pesanan</th><th>Sumber</th><th>Item</th><th class="num">Qty</th><th class="num">FG Tersedia</th><th class="num">Kebutuhan Produksi</th><th>Tanggal Dibutuhkan</th><th>Catatan Khusus</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($div['items'] as $it): ?>
    <tr>
      <td><?= ui_esc($it['orderNo']) ?></td>
      <td><?= ui_esc($it['sourceLabel']) ?></td>
      <td><?= ui_esc($it['itemName']) ?><?= $it['charge'] > 0.0001 ? ' <span style="color:var(--text-muted);font-size:var(--text-xs);">(+charge ' . ui_fmt_num($it['charge']) . ')</span>' : '' ?></td>
      <td class="num"><?= ui_fmt_num($it['qty']) ?></td>
      <td class="num"><?= $it['fgAvailable'] !== null ? ui_fmt_num($it['fgAvailable']) : '-' ?></td>
      <td class="num"><?= $it['productionNeed'] !== null ? ui_fmt_num($it['productionNeed']) : '-' ?></td>
      <td><?= ui_esc($it['requiredDate']) ?><?= $it['requiredTime'] ? ' · ' . ui_esc(substr($it['requiredTime'], 0, 5)) : '' ?></td>
      <td style="max-width:200px;overflow-wrap:anywhere;"><?= $it['specialNote'] ? ui_esc($it['specialNote']) : '-' ?></td>
      <td><?= ui_badge(ui_special_order_status_label($it['status'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endforeach; endif; ?>
