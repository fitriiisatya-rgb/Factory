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
      <tr><td><?= ui_esc($d['storeName']) ?></td><td><?= ui_esc($d['docNo']) ?></td><td class="num"><?= ui_fmt_num($d['totalShipped']) ?></td><td><?= ui_badge(ui_do_status_display($d)) ?></td></tr>
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

// Nota Potongan (migration 0021) reduce what a store owes. A nota counts in
// the period of the invoice it credits (the goods' own period), or its own
// date while that delivery is not invoiced yet.
$notaStoreStmt = $pdo->prepare(
    'SELECT n.store_id, COUNT(*) AS nota_count, SUM(n.total) AS nota_total
     FROM nota_potongan n
     LEFT JOIN invoice i ON i.invoice_id = n.invoice_id
     WHERE COALESCE(i.tanggal, n.tanggal) BETWEEN ? AND ?
     GROUP BY n.store_id'
);
$notaStoreStmt->execute([$omzetDateFrom, $omzetDateTo]);
$notaByStore = [];
foreach ($notaStoreStmt->fetchAll() as $r) {
    $notaByStore[(int) $r['store_id']] = ['count' => (int) $r['nota_count'], 'total' => (float) $r['nota_total']];
}
$storeNames = [];
foreach ($omzetRows as $i => $r) {
    $n = $notaByStore[(int) $r['store_id']] ?? ['count' => 0, 'total' => 0.0];
    $omzetRows[$i]['nota_total'] = $n['total'];
    $omzetRows[$i]['nota_count'] = $n['count'];
    unset($notaByStore[(int) $r['store_id']]);
}
foreach ($notaByStore as $sid => $n) {
    // A store with notas but no invoice in this period still shows up.
    $nm = $pdo->prepare('SELECT canonical_name FROM store WHERE store_id = ?');
    $nm->execute([$sid]);
    $omzetRows[] = ['store_id' => $sid, 'store_name' => (string) $nm->fetchColumn(), 'invoice_count' => 0, 'total_omset' => 0.0, 'nota_total' => $n['total'], 'nota_count' => $n['count']];
}

// Omset per Pabrik: every invoice line belongs to the factory that makes
// the product (product -> division -> factory), so a store invoice that
// mixes Karangtengah and Cibadak goods is split correctly. Uses the
// invoiced qty/subtotal (Reject and Mutasi already applied), never DO qty.
$omzetFactoryStmt = $pdo->prepare(
    "SELECT COALESCE(f.name, 'Tanpa Pabrik') AS factory_name,
            SUM(it.qty_invoice) AS qty_terjual, SUM(it.subtotal) AS total_omset
     FROM invoice i
     INNER JOIN invoice_item it ON it.invoice_id = i.invoice_id
     LEFT JOIN product p ON p.product_id = it.product_id
     LEFT JOIN division d ON d.division_id = COALESCE(it.division_id, p.division_id)
     LEFT JOIN factory f ON f.factory_id = d.factory_id
     WHERE i.tanggal BETWEEN ? AND ?
     GROUP BY COALESCE(f.name, 'Tanpa Pabrik')
     ORDER BY total_omset DESC"
);
$omzetFactoryStmt->execute([$omzetDateFrom, $omzetDateTo]);
$omzetFactoryRows = $omzetFactoryStmt->fetchAll();
$notaFactoryStmt = $pdo->prepare(
    "SELECT COALESCE(f.name, 'Tanpa Pabrik') AS factory_name, SUM(it.qty) AS qty, SUM(it.subtotal) AS total
     FROM nota_potongan n
     INNER JOIN nota_potongan_item it ON it.nota_potongan_id = n.nota_potongan_id
     LEFT JOIN invoice i ON i.invoice_id = n.invoice_id
     LEFT JOIN product p ON p.product_id = it.product_id
     LEFT JOIN division d ON d.division_id = p.division_id
     LEFT JOIN factory f ON f.factory_id = d.factory_id
     WHERE COALESCE(i.tanggal, n.tanggal) BETWEEN ? AND ?
     GROUP BY COALESCE(f.name, 'Tanpa Pabrik')"
);
$notaFactoryStmt->execute([$omzetDateFrom, $omzetDateTo]);

// Omset Non-Toko = what was actually SHIPPED (owner decision Oct 2026):
// shipped qty x the order's unit price, plus each line's charge and extra
// packaging once it has shipped. Factory = the producing division of each
// line (so one order across two factories is split correctly). Dated by
// the order's tanggal kebutuhan; cancelled orders never count.
$nonTokoShippedSql = "SELECT sdi.special_order_item_id, SUM(sdsi.qty) AS shipped
     FROM special_order_do_shipment_item sdsi
     INNER JOIN shipment sh ON sh.shipment_id = sdsi.shipment_id AND sh.status = 'active'
     INNER JOIN special_order_do_item sdi ON sdi.special_order_do_item_id = sdsi.special_order_do_item_id
     GROUP BY sdi.special_order_item_id";
// A Pickup order collected at the factory may have no shipment at all: once
// marked Selesai its full ordered qty counts.
$nonTokoQty = "CASE WHEN so.fulfillment_type = 'pickup' AND so.status = 'completed' THEN GREATEST(it.qty, COALESCE(x.shipped, 0)) ELSE COALESCE(x.shipped, 0) END";
$nonTokoFactoryStmt = $pdo->prepare(
    "SELECT COALESCE(f.name, 'Tanpa Pabrik') AS factory_name,
            SUM({$nonTokoQty}) AS qty_terjual, SUM({$nonTokoQty} * it.unit_price + it.charge + it.extra_packaging) AS total_omset
     FROM special_order so
     INNER JOIN special_order_item it ON it.special_order_id = so.special_order_id
     LEFT JOIN ({$nonTokoShippedSql}) x ON x.special_order_item_id = it.special_order_item_id
     LEFT JOIN division d ON d.division_id = it.division_id
     LEFT JOIN factory f ON f.factory_id = d.factory_id
     WHERE so.source_type = 'non_toko' AND so.status <> 'cancelled' AND {$nonTokoQty} > 0
       AND so.required_date BETWEEN ? AND ?
     GROUP BY COALESCE(f.name, 'Tanpa Pabrik')"
);
$nonTokoFactoryStmt->execute([$omzetDateFrom, $omzetDateTo]);
$nonTokoSourceStmt = $pdo->prepare(
    "SELECT so.non_store_source, COUNT(DISTINCT so.special_order_id) AS order_count,
            SUM({$nonTokoQty} * it.unit_price + it.charge + it.extra_packaging) AS total_omset
     FROM special_order so
     INNER JOIN special_order_item it ON it.special_order_id = so.special_order_id
     LEFT JOIN ({$nonTokoShippedSql}) x ON x.special_order_item_id = it.special_order_item_id
     WHERE so.source_type = 'non_toko' AND so.status <> 'cancelled' AND {$nonTokoQty} > 0
       AND so.required_date BETWEEN ? AND ?
     GROUP BY so.non_store_source
     ORDER BY total_omset DESC"
);
$nonTokoSourceStmt->execute([$omzetDateFrom, $omzetDateTo]);
$nonTokoSourceRows = $nonTokoSourceStmt->fetchAll();
$nonTokoSourceLabels = ['konsumen_langsung' => 'Konsumen Langsung', 'cs' => 'CS', 'sales_executive' => 'Sales Eksekutif', 'umum' => 'Lainnya'];

// One row per factory: store invoices + non-toko orders side by side.
$omzetByFactory = [];
foreach ($omzetFactoryRows as $r) {
    $k = (string) $r['factory_name'];
    $omzetByFactory[$k] = ['factory_name' => $k, 'qty' => (float) $r['qty_terjual'], 'toko' => (float) $r['total_omset'], 'nonToko' => 0.0];
}
foreach ($notaFactoryStmt->fetchAll() as $r) {
    $k = (string) $r['factory_name'];
    $omzetByFactory[$k] ??= ['factory_name' => $k, 'qty' => 0.0, 'toko' => 0.0, 'nonToko' => 0.0];
    $omzetByFactory[$k]['qty'] -= (float) $r['qty'];
    $omzetByFactory[$k]['toko'] -= (float) $r['total'];
}
foreach ($nonTokoFactoryStmt->fetchAll() as $r) {
    $k = (string) $r['factory_name'];
    $omzetByFactory[$k] ??= ['factory_name' => $k, 'qty' => 0.0, 'toko' => 0.0, 'nonToko' => 0.0];
    $omzetByFactory[$k]['qty'] += (float) $r['qty_terjual'];
    $omzetByFactory[$k]['nonToko'] += (float) $r['total_omset'];
}
uasort($omzetByFactory, static fn ($a, $b) => ($b['toko'] + $b['nonToko']) <=> ($a['toko'] + $a['nonToko']));

$omzetTokoTotal = 0.0;
$omzetInvoiceCount = 0;
$omzetNotaTotal = 0.0;
foreach ($omzetRows as $r) {
    $omzetTokoTotal += (float) $r['total_omset'] - (float) $r['nota_total'];
    $omzetNotaTotal += (float) $r['nota_total'];
    $omzetInvoiceCount += (int) $r['invoice_count'];
}
usort($omzetRows, static fn ($a, $b) => ((float) $b['total_omset'] - (float) $b['nota_total']) <=> ((float) $a['total_omset'] - (float) $a['nota_total']));
$omzetNonTokoTotal = 0.0;
$omzetNonTokoCount = 0;
foreach ($nonTokoSourceRows as $r) {
    $omzetNonTokoTotal += (float) $r['total_omset'];
    $omzetNonTokoCount += (int) $r['order_count'];
}
$omzetTotal = $omzetTokoTotal + $omzetNonTokoTotal;
?>
<div class="card section">
  <div class="card-head"><h2 class="card-title">Laporan Omset / Penjualan per Periode</h2></div>
  <p style="color:var(--text-faint);font-size:.85rem;margin-top:-8px;margin-bottom:var(--space-3);">Toko: Invoice yang sudah dibuat dikurangi Nota Potongan (reject di toko, mutasi keluar). Non-Toko: dari Pesanan Non-Toko berstatus Selesai (menurut tanggal kebutuhan).</p>
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;margin-bottom:var(--space-4);">
    <input type="hidden" name="page" value="laporan">
    <input type="hidden" name="tanggal" value="<?= ui_esc($uiTanggal) ?>">
    <input type="hidden" name="factoryId" value="<?= $uiFactoryId ?>">
    <div class="field"><label>Dari Tanggal</label><input type="date" name="omzetDateFrom" value="<?= ui_esc($omzetDateFrom) ?>"></div>
    <div class="field"><label>Sampai Tanggal</label><input type="date" name="omzetDateTo" value="<?= ui_esc($omzetDateTo) ?>"></div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>

  <div class="kpi-grid kpi-grid-4" style="margin-bottom:var(--space-4);">
    <?= ui_kpi_card(['label' => 'Total Omset', 'value' => ui_fmt_money($omzetTotal), 'icon' => 'box', 'color' => 'success']) ?>
    <?= ui_kpi_card(['label' => 'Omset Toko (invoice − nota)', 'value' => ui_fmt_money($omzetTokoTotal), 'icon' => 'cart', 'color' => 'primary']) ?>
    <?= ui_kpi_card(['label' => 'Omset Non-Toko', 'value' => ui_fmt_money($omzetNonTokoTotal), 'icon' => 'user', 'color' => 'primary']) ?>
    <?= ui_kpi_card(['label' => 'Invoice / Pesanan Non-Toko', 'value' => $omzetInvoiceCount . ' / ' . $omzetNonTokoCount, 'icon' => 'file', 'color' => 'neutral']) ?>
  </div>

  <?php if ($omzetRows === [] && $nonTokoSourceRows === []): ?>
    <?= ui_empty_state('Belum ada omset', 'Belum ada invoice atau pesanan non-toko selesai pada periode ini.') ?>
  <?php else: ?>
  <h3 style="font-size:var(--text-md);margin:0 0 var(--space-2);">Per Pabrik</h3>
  <div class="table-scroll" style="margin-bottom:var(--space-4);"><table class="data-table" id="omset-per-pabrik">
    <thead><tr><th>Pabrik</th><th class="num">Qty Terjual</th><th class="num">Omset Toko</th><th class="num">Omset Non-Toko</th><th class="num">Total Omset</th></tr></thead>
    <tbody>
    <?php foreach ($omzetByFactory as $r): ?>
    <tr>
      <td><?= ui_esc($r['factory_name']) ?></td>
      <td class="num"><?= ui_fmt_num($r['qty']) ?></td>
      <td class="num"><?= ui_fmt_money($r['toko']) ?></td>
      <td class="num"><?= ui_fmt_money($r['nonToko']) ?></td>
      <td class="num"><strong><?= ui_fmt_money($r['toko'] + $r['nonToko']) ?></strong></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($nonTokoSourceRows !== []): ?>
  <h3 style="font-size:var(--text-md);margin:0 0 var(--space-2);">Non-Toko per Sumber</h3>
  <div class="table-scroll" style="margin-bottom:var(--space-4);"><table class="data-table" id="omset-non-toko">
    <thead><tr><th>Sumber</th><th class="num">Jumlah Pesanan</th><th class="num">Total Omset</th></tr></thead>
    <tbody>
    <?php foreach ($nonTokoSourceRows as $r): ?>
    <tr>
      <td><?= ui_esc($nonTokoSourceLabels[(string) $r['non_store_source']] ?? 'Lainnya') ?></td>
      <td class="num"><?= (int) $r['order_count'] ?></td>
      <td class="num"><?= ui_fmt_money((float) $r['total_omset']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php if ($omzetRows !== []): ?>
  <h3 style="font-size:var(--text-md);margin:0 0 var(--space-2);">Per Toko (Invoice &minus; Nota Potongan)</h3>
  <div class="table-scroll"><table class="data-table" id="omset-per-toko">
    <thead><tr><th>Toko</th><th class="num">Jumlah Invoice</th><th class="num">Total Invoice</th><th class="num">Nota Potongan</th><th class="num">Tagihan Bersih</th></tr></thead>
    <tbody>
    <?php foreach ($omzetRows as $r): ?>
    <tr>
      <td><?= ui_esc($r['store_name']) ?></td>
      <td class="num"><?= (int) $r['invoice_count'] ?></td>
      <td class="num"><?= ui_fmt_money((float) $r['total_omset']) ?></td>
      <td class="num"><?= (float) $r['nota_total'] > 0 ? '&minus;' . ui_fmt_money((float) $r['nota_total']) . ' (' . (int) $r['nota_count'] . ')' : '-' ?></td>
      <td class="num"><b><?= ui_fmt_money((float) $r['total_omset'] - (float) $r['nota_total']) ?></b></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php
// Arus Barang per Produk — one row per product, following the goods from
// PO to Retur over a period, for one factory. Every column is read from
// the table that is the single source of truth for that step (nothing is
// re-derived), and only finished steps count: submitted production,
// active (non-void) shipments, invoiced qty, verified retur.
$flowDateFrom = isset($_GET['flowDateFrom']) && $_GET['flowDateFrom'] !== '' ? (string) $_GET['flowDateFrom'] : date('Y-m-01');
$flowDateTo = isset($_GET['flowDateTo']) && $_GET['flowDateTo'] !== '' ? (string) $_GET['flowDateTo'] : date('Y-m-d');
$flowFactoryId = isset($_GET['flowFactoryId']) && $_GET['flowFactoryId'] !== '' ? (int) $_GET['flowFactoryId'] : $uiFactoryId;
$flowStmt = $pdo->prepare(
    "SELECT p.product_id, p.name AS product_name, d.name AS division_name,
            COALESCE(po.qty, 0) AS po_qty, COALESCE(pr.aktual, 0) AS produksi, COALESCE(pr.reject, 0) AS reject_produksi,
            COALESCE(sh.qty, 0) AS dikirim, COALESCE(rc.reject, 0) AS reject_toko,
            COALESCE(iv.qty, 0) AS terjual, COALESCE(rt.qty, 0) AS retur, COALESCE(rj.qty, 0) AS reject_di_toko
     FROM product p
     INNER JOIN division d ON d.division_id = p.division_id
     LEFT JOIN (SELECT pi.product_id, SUM(pi.po_awal + pi.po_revisi) AS qty FROM po_item pi
                INNER JOIN po_batch b ON b.po_batch_id = pi.po_batch_id
                WHERE b.tanggal BETWEEN ? AND ? AND b.factory_id = ? GROUP BY pi.product_id) po ON po.product_id = p.product_id
     LEFT JOIN (SELECT it.product_id, SUM(it.aktual) AS aktual, SUM(it.reject) AS reject FROM production_item it
                INNER JOIN production_run r ON r.production_run_id = it.production_run_id
                WHERE r.tanggal BETWEEN ? AND ? AND r.status IN ('submitted','verified_fg') GROUP BY it.product_id) pr ON pr.product_id = p.product_id
     LEFT JOIN (SELECT si.product_id, SUM(si.qty) AS qty FROM shipment_item si
                INNER JOIN shipment s ON s.shipment_id = si.shipment_id
                WHERE s.tanggal BETWEEN ? AND ? AND s.status <> 'void' GROUP BY si.product_id) sh ON sh.product_id = p.product_id
     LEFT JOIN (SELECT ri.product_id, SUM(ri.reject_qty) AS reject FROM shipment_receipt_item ri
                INNER JOIN shipment_receipt r ON r.shipment_receipt_id = ri.shipment_receipt_id
                INNER JOIN shipment s ON s.shipment_id = r.shipment_id
                WHERE s.tanggal BETWEEN ? AND ? GROUP BY ri.product_id) rc ON rc.product_id = p.product_id
     LEFT JOIN (SELECT x.product_id, SUM(x.qty) AS qty FROM (
                    SELECT ii.product_id, ii.qty_invoice AS qty FROM invoice_item ii
                    INNER JOIN invoice i ON i.invoice_id = ii.invoice_id WHERE i.tanggal BETWEEN ? AND ?
                    UNION ALL
                    SELECT nit.product_id, -nit.qty FROM nota_potongan_item nit
                    INNER JOIN nota_potongan n ON n.nota_potongan_id = nit.nota_potongan_id
                    LEFT JOIN invoice ni ON ni.invoice_id = n.invoice_id
                    WHERE COALESCE(ni.tanggal, n.tanggal) BETWEEN ? AND ?
                ) x GROUP BY x.product_id) iv ON iv.product_id = p.product_id
     LEFT JOIN (SELECT product_id, SUM(qty) AS qty FROM retur_request
                WHERE jenis = 'retur' AND status = 'verified' AND retur_date BETWEEN ? AND ? GROUP BY product_id) rt ON rt.product_id = p.product_id
     LEFT JOIN (SELECT product_id, SUM(qty) AS qty FROM retur_request
                WHERE jenis = 'reject_toko' AND status = 'verified' AND retur_date BETWEEN ? AND ? GROUP BY product_id) rj ON rj.product_id = p.product_id
     WHERE d.factory_id = ?
       AND (po.qty IS NOT NULL OR pr.aktual IS NOT NULL OR sh.qty IS NOT NULL OR iv.qty IS NOT NULL OR rt.qty IS NOT NULL OR rj.qty IS NOT NULL)
     ORDER BY d.name, p.name"
);
$flowStmt->execute([
    $flowDateFrom, $flowDateTo, $flowFactoryId,
    $flowDateFrom, $flowDateTo,
    $flowDateFrom, $flowDateTo,
    $flowDateFrom, $flowDateTo,
    $flowDateFrom, $flowDateTo,
    $flowDateFrom, $flowDateTo,
    $flowDateFrom, $flowDateTo,
    $flowDateFrom, $flowDateTo,
    $flowFactoryId,
]);
$flowRows = $flowStmt->fetchAll();
$flowTotals = ['po_qty' => 0.0, 'produksi' => 0.0, 'reject_produksi' => 0.0, 'dikirim' => 0.0, 'reject_toko' => 0.0, 'reject_di_toko' => 0.0, 'terjual' => 0.0, 'retur' => 0.0];
foreach ($flowRows as $r) {
    foreach ($flowTotals as $k => $_) {
        $flowTotals[$k] += (float) $r[$k];
    }
}
?>
<div class="card section">
  <div class="card-head"><h2 class="card-title">Arus Barang per Produk</h2></div>
  <p style="color:var(--text-faint);font-size:.85rem;margin-top:-8px;margin-bottom:var(--space-3);">Dari PO masuk sampai produksi, reject, terjual (invoice), dan retur toko. <b>Reject</b> = tanggungan pabrik (sudah dipotong dari Terjual); <b>Retur</b> = tanggungan toko, hanya catatan.</p>
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;margin-bottom:var(--space-4);">
    <input type="hidden" name="page" value="laporan">
    <input type="hidden" name="tanggal" value="<?= ui_esc($uiTanggal) ?>">
    <input type="hidden" name="factoryId" value="<?= $uiFactoryId ?>">
    <div class="field"><label>Dari Tanggal</label><input type="date" name="flowDateFrom" value="<?= ui_esc($flowDateFrom) ?>"></div>
    <div class="field"><label>Sampai Tanggal</label><input type="date" name="flowDateTo" value="<?= ui_esc($flowDateTo) ?>"></div>
    <div class="field"><label>Pabrik</label>
      <select name="flowFactoryId">
        <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['factory_id'] ?>" <?= $flowFactoryId === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>
  <?php if ($flowRows === []): ?>
    <?= ui_empty_state('Belum ada arus barang', 'Belum ada PO, produksi, pengiriman, atau penjualan pada periode ini.') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table" id="arus-barang">
    <thead><tr><th>Produk</th><th>Divisi</th><th class="num">PO</th><th class="num">Produksi</th><th class="num">Reject Produksi</th><th class="num">Dikirim</th><th class="num">Reject saat Terima</th><th class="num">Reject di Toko</th><th class="num">Terjual</th><th class="num">Retur (catatan)</th></tr></thead>
    <tbody>
    <?php foreach ($flowRows as $r): ?>
    <tr>
      <td><?= ui_esc((string) $r['product_name']) ?></td>
      <td><?= ui_esc((string) $r['division_name']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['po_qty']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['produksi']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['reject_produksi']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['dikirim']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['reject_toko']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['reject_di_toko']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['terjual']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['retur']) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr style="font-weight:600;">
      <td colspan="2">Total</td>
      <?php foreach ($flowTotals as $v): ?><td class="num"><?= ui_fmt_num($v) ?></td><?php endforeach; ?>
    </tr>
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
     WHERE r.jenis = 'retur' AND r.retur_date BETWEEN ? AND ?
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

// Reject di Toko (migration 0020): spoiled before expiry, factory burden.
$rejectTokoStmt = $pdo->prepare(
    "SELECT s.store_id, s.canonical_name AS store_name,
            SUM(CASE WHEN r.status = 'verified' THEN r.qty ELSE 0 END) AS verified_qty,
            SUM(CASE WHEN r.status = 'verified' THEN r.qty * p.harga ELSE 0 END) AS verified_nilai,
            SUM(CASE WHEN r.status = 'verified' AND r.tindak_lanjut = 'potong' THEN r.qty ELSE 0 END) AS potong_qty,
            SUM(CASE WHEN r.status = 'verified' AND r.tindak_lanjut = 'ganti' THEN r.qty ELSE 0 END) AS ganti_qty,
            SUM(CASE WHEN r.status = 'waiting_admin_verification' THEN r.qty ELSE 0 END) AS waiting_qty,
            SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
     FROM retur_request r
     INNER JOIN store s ON s.store_id = r.store_id
     INNER JOIN product p ON p.product_id = r.product_id
     WHERE r.jenis = 'reject_toko' AND r.retur_date BETWEEN ? AND ?
     GROUP BY s.store_id, s.canonical_name
     ORDER BY verified_nilai DESC"
);
$rejectTokoStmt->execute([$returDateFrom, $returDateTo]);
$rejectTokoRows = $rejectTokoStmt->fetchAll();
$rejectTokoQty = 0.0;
$rejectTokoNilai = 0.0;
foreach ($rejectTokoRows as $r) {
    $rejectTokoQty += (float) $r['verified_qty'];
    $rejectTokoNilai += (float) $r['verified_nilai'];
}

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

  <p style="color:var(--text-faint);font-size:.85rem;margin:-8px 0 var(--space-3);"><b>Reject</b> (saat terima &amp; di toko) = tanggungan pabrik, mengurangi invoice. <b>Retur</b> = barang tidak laku, tanggungan toko, hanya dicatat.</p>
  <div class="kpi-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:var(--space-4);">
    <?= ui_kpi_card(['label' => 'Reject saat Terima (qty)', 'value' => ui_fmt_num($rejectTotalQty), 'icon' => 'alert', 'color' => 'warning']) ?>
    <?= ui_kpi_card(['label' => 'Reject di Toko (qty / nilai)', 'value' => ui_fmt_num($rejectTokoQty) . ' · ' . ui_fmt_money($rejectTokoNilai), 'icon' => 'alert', 'color' => 'warning']) ?>
    <?= ui_kpi_card(['label' => 'Retur — catatan (qty)', 'value' => ui_fmt_num($returTotalQty), 'icon' => 'box', 'color' => 'neutral']) ?>
  </div>

  <div style="display:grid;gap:var(--space-5);">
    <div style="min-width:0;">
      <h3 style="margin-bottom:var(--space-3);">⚠️ Reject di Toko per Toko <small style="font-weight:400;color:var(--text-faint);">— rusak/jamur sebelum expired, tanggungan pabrik</small></h3>
      <?php if ($rejectTokoRows === []): ?>
        <?= ui_empty_state('Belum ada Reject di Toko', 'Tidak ada laporan Reject di Toko pada periode ini.') ?>
      <?php else: ?>
      <div class="table-scroll"><table class="data-table" id="reject-di-toko">
        <thead><tr><th>Toko</th><th class="num">Disetujui (qty)</th><th class="num">Nilai</th><th class="num">Potong</th><th class="num">Ganti</th><th class="num">Menunggu</th><th class="num">Ditolak</th></tr></thead>
        <tbody>
        <?php foreach ($rejectTokoRows as $r): ?>
        <tr>
          <td><?= ui_esc($r['store_name']) ?></td>
          <td class="num"><?= ui_fmt_num((float) $r['verified_qty']) ?></td>
          <td class="num"><?= ui_fmt_money((float) $r['verified_nilai']) ?></td>
          <td class="num"><?= ui_fmt_num((float) $r['potong_qty']) ?></td>
          <td class="num"><?= ui_fmt_num((float) $r['ganti_qty']) ?></td>
          <td class="num"><?= ui_fmt_num((float) $r['waiting_qty']) ?></td>
          <td class="num"><?= (int) $r['rejected_count'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <div style="min-width:0;">
      <h3 style="margin-bottom:var(--space-3);">↩️ Retur per Toko <small style="font-weight:400;color:var(--text-faint);">— hanya catatan, tidak memotong invoice</small></h3>
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
    <div style="min-width:0;">
      <h3 style="margin-bottom:var(--space-3);">⚠️ Reject saat Terima per Toko</h3>
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
