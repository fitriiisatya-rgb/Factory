<?php

declare(strict_types=1);

/**
 * Structural report shell for the current tanggal+factory selection,
 * built entirely from DashboardService's own real (additive, read-only)
 * aggregation — no Invoice/Revenue numbers, since those don't exist yet
 * (explicitly out of scope: "DO NOT build Invoice/Revenue reports yet").
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

<div class="alert alert-warning">Laporan Invoice/Piutang/Retur belum tersedia — menunggu Phase 6/7.</div>
