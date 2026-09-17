<?php

declare(strict_types=1);

use Amor\Api\Dashboard\DashboardService;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\Delivery\DoService;

$service = new DashboardService($pdo, new DoService($pdo), new DoRepository());
$summary = $service->summary($uiTanggal, $uiFactoryId);
$k = $summary['kpi'];
?>
<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="dashboard">
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

<div class="kpi-grid">
  <?= ui_kpi_card(['label' => 'PO Target', 'value' => ui_fmt_num($k['poTarget']), 'icon' => 'cart', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'Produksi Aktual', 'value' => ui_fmt_num($k['productionActual']), 'icon' => 'factory', 'color' => 'primary', 'progressPct' => $k['productionActualPct'], 'foot' => 'dari PO Target']) ?>
  <?= ui_kpi_card(['label' => 'FG Tersedia', 'value' => ui_fmt_num($k['fgAvailable']), 'icon' => 'box', 'color' => 'success', 'progressPct' => $k['fgAvailablePct'], 'foot' => 'stok saat ini']) ?>
  <?= ui_kpi_card(['label' => 'DO Dibuat', 'value' => $k['doCreated'] . ' / ' . $k['doTotal'], 'icon' => 'file', 'color' => 'primary', 'progressPct' => $k['doCreatedPct'], 'foot' => 'dari toko dengan PO']) ?>
  <?= ui_kpi_card(['label' => 'Sudah Terkirim', 'value' => $k['shipped'] . ' / ' . $k['doCreated'], 'icon' => 'truck', 'color' => 'warning', 'progressPct' => $k['shippedPct'], 'foot' => 'dari DO dibuat']) ?>
</div>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Progress Operasional Hari Ini</h2></div>
  <div class="pipeline">
    <?php foreach ($summary['pipeline'] as $i => $step): $done = $step['pct'] >= 100; ?>
    <div class="pipeline-step">
      <div class="pipeline-dot <?= $done ? 'done' : ($step['pct'] > 0 ? 'active' : '') ?>"><?= $done ? '&check;' : ($i + 1) ?></div>
      <div class="pipeline-meta">
        <div class="pipeline-label"><?= ui_esc($step['label']) ?></div>
        <div class="pipeline-value"><?= ui_fmt_num($step['value']) ?> / <?= ui_fmt_num($step['total']) ?></div>
        <div class="pipeline-pct" style="color:<?= $done ? 'var(--success)' : 'var(--primary)' ?>"><?= $step['pct'] ?>%</div>
      </div>
    </div>
    <?php if ($i < count($summary['pipeline']) - 1): ?><span class="pipeline-arrow">&#8594;</span><?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid-2 section">
  <div class="card">
    <div class="card-head"><h2 class="card-title">Tren Produksi vs Target (7 Hari Terakhir)</h2></div>
    <?= ui_bar_chart($summary['trend']) ?>
  </div>
  <div class="card">
    <div class="card-head"><h2 class="card-title">Komposisi Produksi Hari Ini</h2></div>
    <?php
      $palette = ['#3d7bfa', '#f59e0b', '#22c55e', '#a855f7', '#06b6d4', '#ef4444', '#7b8aa8'];
      $slices = [];
      foreach ($summary['divisionComposition'] as $idx => $row) {
          $slices[] = ['label' => $row['divisionName'], 'value' => $row['value'], 'color' => $palette[$idx % count($palette)]];
      }
      if ($slices === []) {
          echo ui_empty_state('Belum ada produksi', 'Belum ada produksi tercatat untuk tanggal/pabrik ini.');
      } else {
          echo ui_donut_chart($slices, 'Total', ui_fmt_num($k['productionActual']));
      }
    ?>
  </div>
</div>

<div class="grid-2 section">
  <div class="card">
    <div class="card-head"><h2 class="card-title">Delivery Order Hari Ini</h2>
      <a href="/api/_ui-preview/?page=delivery-order&tanggal=<?= urlencode($uiTanggal) ?>&factoryId=<?= $uiFactoryId ?>" class="btn btn-secondary btn-sm">Lihat Semua</a></div>
    <?php if ($summary['deliveryOrders'] === []): ?>
      <?= ui_empty_state('Belum ada DO hari ini', 'Buat Draft DO dari halaman Delivery Order.') ?>
    <?php else: ?>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Toko</th><th>DO No.</th><th class="num">Planned</th><th class="num">Shipped</th><th class="num">Remaining</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($summary['deliveryOrders'], 0, 6) as $d): ?>
      <tr>
        <td><?= ui_esc($d['storeName']) ?></td>
        <td><a href="/api/_ui-preview/?page=delivery-order-detail&doId=<?= (int) $d['doId'] ?>"><?= ui_esc($d['docNo']) ?></a></td>
        <td class="num"><?= ui_fmt_num($d['totalPlanned']) ?></td>
        <td class="num"><?= ui_fmt_num($d['totalShipped']) ?></td>
        <td class="num"><?= ui_fmt_num($d['totalRemaining']) ?></td>
        <td><?= ui_badge(ui_do_status_label($d['status'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><h2 class="card-title">Stok FG Tersedia (Top 5)</h2></div>
    <?php if ($summary['topFgStock'] === []): ?>
      <?= ui_empty_state('Belum ada stok FG', '') ?>
    <?php else: ?>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Produk</th><th class="num">Stok</th></tr></thead>
      <tbody>
      <?php foreach ($summary['topFgStock'] as $row): ?>
      <tr><td><?= ui_esc($row['productName']) ?></td><td class="num"><?= ui_fmt_num($row['qty']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Aktivitas Terbaru</h2></div>
  <?php
    $actionLabels = [
        'po.import' => 'PO diimpor/direvisi', 'po.product.create_from_import' => 'Produk baru dibuat dari import PO',
        'product.create' => 'Produk ditambahkan', 'product.update' => 'Produk diperbarui', 'product.alias.create' => 'Alias produk ditambahkan',
        'store.create' => 'Toko ditambahkan', 'store.update' => 'Toko diperbarui', 'store.alias.create' => 'Alias toko ditambahkan',
        'production.draft.create' => 'Draft Produksi dibuat', 'production.draft.edit' => 'Draft Produksi disimpan',
        'production.submit' => 'Produksi disubmit', 'production.reopen' => 'Produksi dibuka kembali',
        'production.overproduction' => 'Overproduction tercatat', 'production.target_changed_from_po_revision' => 'Target produksi berubah (revisi PO)',
        'fg.draft.create' => 'Draft FG dibuat', 'fg.draft.edit' => 'Draft FG disimpan',
        'fg.submit' => 'FG disubmit', 'fg.reopen' => 'FG dibuka kembali', 'fg.source_inconsistency' => 'Ketidaksesuaian sumber FG',
        'do.preprint' => 'DO dicetak (preprint)', 'do.refresh_from_po' => 'DO disegarkan dari PO', 'do.cancel' => 'DO dibatalkan', 'do.ship' => 'Pengiriman dikonfirmasi',
    ];
  ?>
  <?php if ($summary['recentActivity'] === []): ?>
    <?= ui_empty_state('Belum ada aktivitas', '') ?>
  <?php else: ?>
  <div class="activity-list">
    <?php foreach ($summary['recentActivity'] as $a): ?>
    <div class="activity-item">
      <div class="activity-icon"><?= ui_icon('file') ?></div>
      <div>
        <div class="activity-text"><?= ui_esc($actionLabels[$a['action']] ?? $a['action']) ?></div>
        <div class="activity-meta"><?= ui_esc($a['record_type']) ?> #<?= ui_esc((string) $a['record_key']) ?><?= $a['username'] ? ' &middot; ' . ui_esc($a['username']) : '' ?></div>
      </div>
      <div class="activity-time"><?= ui_esc((string) $a['event_at']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
