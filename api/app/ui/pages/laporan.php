<?php

declare(strict_types=1);

/**
 * Structural report shell for the current tanggal+factory selection,
 * built entirely from DashboardService's own real (additive, read-only)
 * aggregation, PLUS (migration 0018) two period-based financial/quality
 * sections — Omset/Penjualan per toko&periode (from the real `invoice`
 * table) and Retur & Reject per periode (from `retur_request` and
 * `shipment_receipt_item`). Both sections use their OWN date-range filter
 * (independent of the tanggal/factoryId selector above, which is a
 * single-day operational-funnel filter) since a financial/quality report
 * is naturally period-based, not single-day. Piutang/Payment reporting
 * remains out of scope (the `payment` table is still unused — confirmed
 * with the user as a future phase).
 */

use Amor\Api\Dashboard\DashboardService;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\Delivery\DoService;

$service = new DashboardService($pdo, new DoService($pdo), new DoRepository());
$s = $service->summary($uiTanggal, $uiFactoryId);
$k = $s['kpi'];

$shipmentTotalQty = $k0 = 0.0;
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(si.qty),0) FROM shipment_item si INNER JOIN shipment sh ON sh.shipment_id = si.shipment_id
     WHERE sh.tanggal = ? AND sh.factory_id = ? AND sh.status = 'active'"
);
$stmt->execute([$uiTanggal, $uiFactoryId]);
$shipmentTotalQty = (float) $stmt->fetchColumn();

function report_row(string $label, string $left, string $leftVal, string $right, string $rightVal, int $pct): void
{
    ?>
    <div style="display:flex;align-items:center;gap:var(--space-4);padding:var(--space-3) 0;border-bottom:1px solid var(--border);">
      <div style="flex:1;font-weight:600;"><?= ui_esc($label) ?></div>
      <div style="width:160px;text-align:right;"><?= ui_esc($left) ?>: <b><?= ui_esc($leftVal) ?></b></div>
      <div style="width:160px;text-align:right;"><?= ui_esc($right) ?>: <b><?= ui_esc($rightVal) ?></b></div>
      <div class="kpi-progress" style="width:120px;margin-top:0;"><span style="width:<?= $pct ?>%"></span></div>
      <div style="width:44px;text-align:right;font-weight:700;"><?= $pct ?>%</div>
    </div>
    <?php
}
?>
<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="laporan">
    <div class="field"><label>Tanggal</label><input type="date" name="tanggal" value="<?= ui_esc($uiTanggal) ?>"></div>
    <div class="field"><label>Pabrik</label>
      <select name="factoryId">
        <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['factory_id'] ?>" <?= $uiFactoryId === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>
</div>

<div class="card section">
  <div class="card-head"><h2 class="card-title">PO vs Produksi</h2></div>
  <?php report_row('PO Target vs Produksi Aktual', 'PO Target', ui_fmt_num($k['poTarget']), 'Produksi Aktual', ui_fmt_num($k['productionActual']), $k['productionActualPct']); ?>
</div>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Produksi vs FG</h2></div>
  <?php report_row('Produksi Aktual vs FG Terverifikasi', 'Produksi Aktual', ui_fmt_num($k['productionActual']), 'FG Terverifikasi', ui_fmt_num($s['pipeline'][2]['value']), ui_fmt_pct($s['pipeline'][2]['value'], $k['productionActual'])); ?>
</div>

<div class="card section">
  <div class="card-head"><h2 class="card-title">FG vs Pengiriman</h2></div>
  <?php report_row('FG Terverifikasi vs Qty Terkirim', 'FG Terverifikasi', ui_fmt_num($s['pipeline'][2]['value']), 'Qty Terkirim', ui_fmt_num($shipmentTotalQty), ui_fmt_pct($shipmentTotalQty, $s['pipeline'][2]['value'])); ?>
</div>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Fulfillment Delivery Order</h2></div>
  <?php report_row('DO Dibuat dari Toko dengan PO', 'DO Dibuat', (string) $k['doCreated'], 'Toko dengan PO', (string) $k['doTotal'], $k['doCreatedPct']); ?>
  <?php report_row('DO Terkirim Penuh dari DO Dibuat', 'Terkirim Penuh', (string) $k['shipped'], 'DO Dibuat', (string) $k['doCreated'], $k['shippedPct']); ?>
</div>

<div class="grid-2 section">
  <div class="card">
    <div class="card-head"><h2 class="card-title">Ringkasan Pengiriman</h2></div>
    <?php if ($s['deliveryOrders'] === []): ?>
      <?= ui_empty_state('Belum ada DO', '') ?>
    <?php else: ?>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Toko</th><th>DO No</th><th class="num">Shipped</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($s['deliveryOrders'] as $d): ?>
      <tr><td><?= ui_esc($d['storeName']) ?></td><td><?= ui_esc($d['docNo']) ?></td><td class="num"><?= ui_fmt_num($d['totalShipped']) ?></td><td><?= ui_badge(ui_do_status_label($d['status'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><h2 class="card-title">Stok FG (Top 5)</h2></div>
    <?php if ($s['topFgStock'] === []): ?>
      <?= ui_empty_state('Belum ada stok FG', '') ?>
    <?php else: ?>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Produk</th><th class="num">Stok</th></tr></thead>
      <tbody>
      <?php foreach ($s['topFgStock'] as $row): ?>
      <tr><td><?= ui_esc($row['productName']) ?></td><td class="num"><?= ui_fmt_num($row['qty']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<?php
$omzetDateFrom = isset($_GET['omzetDateFrom']) && $_GET['omzetDateFrom'] !== '' ? (string) $_GET['omzetDateFrom'] : date('Y-m-01');
$omzetDateTo = isset($_GET['omzetDateTo']) && $_GET['omzetDateTo'] !== '' ? (string) $_GET['omzetDateTo'] : date('Y-m-d');

$omzetStmt = $pdo->prepare(
    'SELECT s.store_id, s.canonical_name AS store_name, COUNT(*) AS invoice_count, SUM(i.total) AS total_omset
     FROM invoice i
     INNER JOIN store s ON s.store_id = i.store_id
     WHERE i.tanggal BETWEEN ? AND ?
     GROUP BY s.store_id, s.canonical_name
     ORDER BY total_omset DESC'
);
$omzetStmt->execute([$omzetDateFrom, $omzetDateTo]);
$omzetRows = $omzetStmt->fetchAll();
$omzetTotal = 0.0;
$omzetInvoiceCount = 0;
foreach ($omzetRows as $r) {
    $omzetTotal += (float) $r['total_omset'];
    $omzetInvoiceCount += (int) $r['invoice_count'];
}
?>
<div class="card section">
  <div class="card-head"><h2 class="card-title">Laporan Omset / Penjualan per Toko &amp; Periode</h2></div>
  <p style="color:var(--text-faint);font-size:.85rem;margin-top:-8px;margin-bottom:var(--space-3);">Dihitung dari Invoice yang sudah di-generate (migration 0018) — bukan ekstrapolasi dari DO/Shipment.</p>
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;margin-bottom:var(--space-4);">
    <input type="hidden" name="page" value="laporan">
    <input type="hidden" name="tanggal" value="<?= ui_esc($uiTanggal) ?>">
    <input type="hidden" name="factoryId" value="<?= $uiFactoryId ?>">
    <div class="field"><label>Dari Tanggal</label><input type="date" name="omzetDateFrom" value="<?= ui_esc($omzetDateFrom) ?>"></div>
    <div class="field"><label>Sampai Tanggal</label><input type="date" name="omzetDateTo" value="<?= ui_esc($omzetDateTo) ?>"></div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>

  <div class="kpi-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:var(--space-4);">
    <?= ui_kpi_card(['label' => 'Total Omset', 'value' => ui_fmt_money($omzetTotal), 'icon' => 'box', 'color' => 'success']) ?>
    <?= ui_kpi_card(['label' => 'Jumlah Invoice', 'value' => (string) $omzetInvoiceCount, 'icon' => 'file', 'color' => 'primary']) ?>
  </div>

  <?php if ($omzetRows === []): ?>
    <?= ui_empty_state('Belum ada Invoice', 'Belum ada invoice yang di-generate untuk periode ini.') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Toko</th><th class="num">Jumlah Invoice</th><th class="num">Total Omset</th></tr></thead>
    <tbody>
    <?php foreach ($omzetRows as $r): ?>
    <tr>
      <td><?= ui_esc($r['store_name']) ?></td>
      <td class="num"><?= (int) $r['invoice_count'] ?></td>
      <td class="num"><?= ui_fmt_money((float) $r['total_omset']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php
$returDateFrom = isset($_GET['returDateFrom']) && $_GET['returDateFrom'] !== '' ? (string) $_GET['returDateFrom'] : date('Y-m-01');
$returDateTo = isset($_GET['returDateTo']) && $_GET['returDateTo'] !== '' ? (string) $_GET['returDateTo'] : date('Y-m-d');

$returStmt = $pdo->prepare(
    "SELECT s.store_id, s.canonical_name AS store_name,
            SUM(CASE WHEN r.status = 'verified' THEN 1 ELSE 0 END) AS verified_count,
            SUM(CASE WHEN r.status = 'verified' THEN r.qty ELSE 0 END) AS verified_qty,
            SUM(CASE WHEN r.status = 'waiting_admin_verification' THEN 1 ELSE 0 END) AS waiting_count,
            SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
     FROM retur_request r
     INNER JOIN store s ON s.store_id = r.store_id
     WHERE r.retur_date BETWEEN ? AND ?
     GROUP BY s.store_id, s.canonical_name
     ORDER BY verified_qty DESC"
);
$returStmt->execute([$returDateFrom, $returDateTo]);
$returRows = $returStmt->fetchAll();

$rejectStmt = $pdo->prepare(
    "SELECT s.store_id, s.canonical_name AS store_name,
            SUM(sri.reject_qty) AS reported_reject_qty,
            SUM(CASE WHEN sri.disposition = 'reject_final' THEN COALESCE(sri.approved_reject_qty, 0) ELSE 0 END) AS reject_final_qty,
            SUM(CASE WHEN sri.disposition = 'kirim_ulang' THEN COALESCE(sri.approved_reject_qty, 0) ELSE 0 END) AS kirim_ulang_qty,
            SUM(CASE WHEN sri.disposition = 'pending' THEN sri.reject_qty ELSE 0 END) AS pending_qty
     FROM shipment_receipt_item sri
     INNER JOIN shipment_receipt sr ON sr.shipment_receipt_id = sri.shipment_receipt_id
     INNER JOIN shipment sh ON sh.shipment_id = sr.shipment_id
     INNER JOIN store s ON s.store_id = sh.store_id
     WHERE sh.tanggal BETWEEN ? AND ? AND sri.reject_qty > 0
     GROUP BY s.store_id, s.canonical_name
     ORDER BY reported_reject_qty DESC"
);
$rejectStmt->execute([$returDateFrom, $returDateTo]);
$rejectRows = $rejectStmt->fetchAll();

$returTotalQty = 0.0;
foreach ($returRows as $r) {
    $returTotalQty += (float) $r['verified_qty'];
}
$rejectTotalQty = 0.0;
foreach ($rejectRows as $r) {
    $rejectTotalQty += (float) $r['reported_reject_qty'];
}
?>
<div class="card section">
  <div class="card-head"><h2 class="card-title">Laporan Retur &amp; Reject per Periode</h2></div>
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;margin-bottom:var(--space-4);">
    <input type="hidden" name="page" value="laporan">
    <input type="hidden" name="tanggal" value="<?= ui_esc($uiTanggal) ?>">
    <input type="hidden" name="factoryId" value="<?= $uiFactoryId ?>">
    <input type="hidden" name="omzetDateFrom" value="<?= ui_esc($omzetDateFrom) ?>">
    <input type="hidden" name="omzetDateTo" value="<?= ui_esc($omzetDateTo) ?>">
    <div class="field"><label>Dari Tanggal</label><input type="date" name="returDateFrom" value="<?= ui_esc($returDateFrom) ?>"></div>
    <div class="field"><label>Sampai Tanggal</label><input type="date" name="returDateTo" value="<?= ui_esc($returDateTo) ?>"></div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>

  <div class="kpi-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:var(--space-4);">
    <?= ui_kpi_card(['label' => 'Qty Retur Terverifikasi', 'value' => ui_fmt_num($returTotalQty), 'icon' => 'box', 'color' => 'warning']) ?>
    <?= ui_kpi_card(['label' => 'Qty Reject Dilaporkan', 'value' => ui_fmt_num($rejectTotalQty), 'icon' => 'box', 'color' => 'danger']) ?>
  </div>

  <div class="grid-2">
    <div>
      <h3 style="margin-bottom:var(--space-3);">Retur per Toko</h3>
      <?php if ($returRows === []): ?>
        <?= ui_empty_state('Belum ada Retur', 'Tidak ada pengajuan Retur pada periode ini.') ?>
      <?php else: ?>
      <div class="table-scroll"><table class="data-table">
        <thead><tr><th>Toko</th><th class="num">Terverifikasi</th><th class="num">Qty</th><th class="num">Menunggu</th><th class="num">Ditolak</th></tr></thead>
        <tbody>
        <?php foreach ($returRows as $r): ?>
        <tr>
          <td><?= ui_esc($r['store_name']) ?></td>
          <td class="num"><?= (int) $r['verified_count'] ?></td>
          <td class="num"><?= ui_fmt_num((float) $r['verified_qty']) ?></td>
          <td class="num"><?= (int) $r['waiting_count'] ?></td>
          <td class="num"><?= (int) $r['rejected_count'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <div>
      <h3 style="margin-bottom:var(--space-3);">Reject per Toko</h3>
      <?php if ($rejectRows === []): ?>
        <?= ui_empty_state('Belum ada Reject', 'Tidak ada reject yang dilaporkan toko pada periode ini.') ?>
      <?php else: ?>
      <div class="table-scroll"><table class="data-table">
        <thead><tr><th>Toko</th><th class="num">Dilaporkan</th><th class="num">Reject Final</th><th class="num">Kirim Ulang</th><th class="num">Belum Diputuskan</th></tr></thead>
        <tbody>
        <?php foreach ($rejectRows as $r): ?>
        <tr>
          <td><?= ui_esc($r['store_name']) ?></td>
          <td class="num"><?= ui_fmt_num((float) $r['reported_reject_qty']) ?></td>
          <td class="num"><?= ui_fmt_num((float) $r['reject_final_qty']) ?></td>
          <td class="num"><?= ui_fmt_num((float) $r['kirim_ulang_qty']) ?></td>
          <td class="num"><?= ui_fmt_num((float) $r['pending_qty']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="alert alert-warning">Laporan Piutang/Pembayaran belum tersedia — menunggu fase berikutnya.</div>
