<?php

declare(strict_types=1);

use Amor\Api\Delivery\DoService;

$doIdParam = isset($_GET['doId']) ? (int) $_GET['doId'] : null;

if ($doIdParam !== null) {
    // -------------------------------------------------------------
    // Create-shipment mode for one specific DO.
    // -------------------------------------------------------------
    $service = new DoService($pdo);
    $do = null;
    $viewError = null;
    try {
        $do = $service->getDo($doIdParam);
    } catch (\Throwable $e) {
        $viewError = $e->getMessage();
    }
    ?>
    <?php if ($viewError !== null): ?>
    <div class="alert alert-danger"><?= ui_esc($viewError) ?></div>
    <?php elseif (!in_array($do['status'], ['draft', 'preprinted'], true) || (float) $do['summary']['totalRemaining'] <= 0.0001): ?>
    <div class="alert alert-warning">DO <?= ui_esc($do['docNo']) ?> tidak memiliki sisa untuk dikirim, atau statusnya tidak lagi bisa dikirim.</div>
    <a class="btn btn-secondary" href="/api/_ui-preview/?page=delivery-order-detail&doId=<?= (int) $do['doId'] ?>">&larr; Kembali ke DO</a>
    <?php else: ?>
    <div class="card section" id="ship-form" data-do-id="<?= (int) $do['doId'] ?>" data-expected-version="<?= (int) $do['version'] ?>">
      <div class="card-head">
        <div>
          <h2 class="card-title">Kirim — <?= ui_esc($do['docNo']) ?></h2>
          <div class="page-subtitle" style="margin-top:4px;"><?= ui_esc((string) $do['storeName']) ?> &middot; Sisa: <?= ui_fmt_num($do['summary']['totalRemaining']) ?></div>
        </div>
        <a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=delivery-order-detail&doId=<?= (int) $do['doId'] ?>">&larr; Kembali ke DO</a>
      </div>

      <div class="field" style="max-width:220px;margin-bottom:var(--space-4);">
        <label>Grup Pengiriman</label>
        <select id="ship-group">
          <option value="MAIN">MAIN</option>
          <option value="PASTRY">PASTRY</option>
          <option value="OTHER">OTHER</option>
        </select>
      </div>

      <div class="table-scroll"><table class="data-table">
        <thead><tr><th>Produk</th><th class="num">Sisa DO</th><th class="num">FG Available</th><th class="num">Qty Kirim</th><th>Catatan</th></tr></thead>
        <tbody>
        <?php foreach ($do['items'] as $it): if ((float) $it['remainingToShip'] <= 0.0001) continue;
          $available = $it['fgAvailable'] !== null ? (float) $it['fgAvailable'] : 0.0;
          $max = min((float) $it['remainingToShip'], $available);
          $disabled = $available <= 0.0001;
        ?>
        <tr<?= $disabled ? ' style="opacity:.5;"' : '' ?>>
          <td><?= ui_esc($it['productName']) ?></td>
          <td class="num"><?= ui_fmt_num($it['remainingToShip']) ?></td>
          <td class="num"><?= ui_fmt_num($available) ?></td>
          <td class="num"><input type="number" step="0.01" min="0" max="<?= ui_fmt_num($max) ?>" style="width:6rem;text-align:right;"
              data-product-id="<?= (int) $it['productId'] ?>" data-max="<?= ui_fmt_num($max) ?>" data-field="qty" value="0" <?= $disabled ? 'disabled title="Stok FG belum tersedia"' : '' ?>></td>
          <td><input type="text" style="width:8rem;" data-product-id="<?= (int) $it['productId'] ?>" data-field="notes" <?= $disabled ? 'disabled' : '' ?>></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>

      <div id="ship-preview-box" style="display:none;margin-top:var(--space-4);"></div>

      <div class="btn-group" style="margin-top:var(--space-4);">
        <button type="button" class="btn btn-secondary" id="btn-preview-ship">Pratinjau Pengiriman</button>
        <button type="button" class="btn btn-primary" id="btn-confirm-ship">Konfirmasi KIRIM</button>
      </div>
    </div>

    <script>
    (function () {
      var form = document.getElementById('ship-form');
      var doId = form.getAttribute('data-do-id');
      var version = parseInt(form.getAttribute('data-expected-version'), 10);

      function collectItems() {
        var items = [];
        document.querySelectorAll('#ship-form [data-field="qty"]').forEach(function (input) {
          var qty = parseFloat(input.value || '0');
          if (qty <= 0.0001) return;
          var pid = input.getAttribute('data-product-id');
          var notesInput = document.querySelector('#ship-form [data-field="notes"][data-product-id="' + pid + '"]');
          items.push({ productId: parseInt(pid, 10), actualQty: qty, notes: notesInput ? notesInput.value : '' });
        });
        return items;
      }
      // Client-side convenience only — max(remaining, FG available); server revalidates for real.
      document.querySelectorAll('#ship-form [data-field="qty"]').forEach(function (input) {
        input.addEventListener('input', function () {
          var max = parseFloat(input.getAttribute('data-max') || '0');
          if (parseFloat(input.value || '0') > max) input.value = max;
        });
      });

      document.getElementById('btn-preview-ship').addEventListener('click', async function () {
        var items = collectItems();
        if (items.length === 0) { Amor.toast('Isi minimal satu Qty Kirim terlebih dahulu.', 'warning'); return; }
        this.disabled = true;
        try {
          var data = await Amor.apiFetch('/api/do/' + doId + '/shipment-preview', { method: 'POST', body: { items: items } });
          var box = document.getElementById('ship-preview-box');
          var html = '<table class="data-table"><thead><tr><th>Produk</th><th class="num">Diminta</th><th class="num">Maks</th><th>Status</th></tr></thead><tbody>';
          data.lines.forEach(function (l) {
            html += '<tr><td>' + l.productName + '</td><td class="num">' + l.requestedQty + '</td><td class="num">' + l.maxShippable + '</td><td>' +
              (l.errors.length === 0 ? '<span class="badge badge-success">Valid</span>' : '<span class="badge badge-danger">' + l.errors.join(', ') + '</span>') + '</td></tr>';
          });
          html += '</tbody></table>';
          box.innerHTML = html;
          box.style.display = 'block';
          Amor.toast(data.ok ? 'Semua baris valid.' : 'Ada baris bermasalah — periksa tabel pratinjau.', data.ok ? 'success' : 'warning');
        } catch (e) { Amor.toast(e.message, 'danger'); }
        this.disabled = false;
      });

      document.getElementById('btn-confirm-ship').addEventListener('click', async function () {
        var items = collectItems();
        if (items.length === 0) { Amor.toast('Isi minimal satu Qty Kirim terlebih dahulu.', 'warning'); return; }
        var totalQty = items.reduce(function (s, i) { return s + i.actualQty; }, 0);
        var group = document.getElementById('ship-group').value;
        var ok = await Amor.confirmModal({
          title: 'Konfirmasi KIRIM',
          body: 'Grup ' + group + ' &middot; ' + items.length + ' produk &middot; total qty ' + totalQty + '. Stok FG akan berkurang setelah pengiriman dikonfirmasi.',
          confirmLabel: 'Ya, KIRIM',
        });
        if (!ok) return;
        this.disabled = true;
        try {
          var data = await Amor.apiFetch('/api/do/' + doId + '/ship', { method: 'POST', body: { expectedVersion: version, shipmentGroup: group, items: items } });
          Amor.toast('Pengiriman #' + data.shipmentId + ' terkonfirmasi.' + (data.doFullyFulfilled ? ' DO terkirim penuh.' : ' Sisa: ' + data.doTotalRemaining + '.'), 'success');
          setTimeout(function () { location.href = '/api/_ui-preview/?page=delivery-order-detail&doId=' + doId; }, 900);
        } catch (e) { Amor.toast(e.message, 'danger'); this.disabled = false; }
      });
    })();
    </script>
    <?php endif; ?>
<?php
    return;
}

// ---------------------------------------------------------------------
// Shipment list/history mode.
// ---------------------------------------------------------------------
$groupParam = isset($_GET['group']) && $_GET['group'] !== '' ? (string) $_GET['group'] : null;
$statusParam = isset($_GET['status']) && $_GET['status'] !== '' ? (string) $_GET['status'] : null;
$searchTerm = trim((string) ($_GET['q'] ?? ''));

$sql = "SELECT sh.*, s.canonical_name AS store_name, o.doc_no, u.username AS shipped_by_name
        FROM shipment sh
        INNER JOIN store s ON s.store_id = sh.store_id
        LEFT JOIN delivery_order o ON o.delivery_order_id = sh.delivery_order_id
        LEFT JOIN users u ON u.user_id = sh.shipped_by
        WHERE sh.tanggal = ? AND sh.factory_id = ?";
$params = [$uiTanggal, $uiFactoryId];
if ($groupParam !== null) {
    $sql .= ' AND sh.shipment_group = ?';
    $params[] = $groupParam;
}
if ($statusParam !== null) {
    $sql .= ' AND sh.status = ?';
    $params[] = $statusParam;
}
if ($searchTerm !== '') {
    $sql .= ' AND (s.canonical_name LIKE ? OR o.doc_no LIKE ?)';
    $params[] = '%' . $searchTerm . '%';
    $params[] = '%' . $searchTerm . '%';
}
$sql .= ' ORDER BY sh.shipment_id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shipmentRows = $stmt->fetchAll();

$qtyStmt = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM shipment_item WHERE shipment_id = ?');
$kpi = ['total' => 0, 'main' => 0, 'pastry' => 0, 'other' => 0, 'qty' => 0.0];
foreach ($shipmentRows as &$sh) {
    $qtyStmt->execute([$sh['shipment_id']]);
    $sh['qty_total'] = (float) $qtyStmt->fetchColumn();
    $kpi['total']++;
    $kpi['qty'] += $sh['qty_total'];
    $key = strtolower($sh['shipment_group']);
    if (isset($kpi[$key])) {
        $kpi[$key]++;
    }
}
unset($sh);

$partialDo = $pdo->prepare(
    "SELECT COUNT(*) FROM delivery_order o
     INNER JOIN delivery_order_item oi ON oi.delivery_order_id = o.delivery_order_id
     INNER JOIN product p ON p.product_id = oi.product_id
     INNER JOIN division d ON d.division_id = p.division_id
     WHERE o.tanggal = ? AND d.factory_id = ? AND o.status IN ('draft','preprinted')
       AND EXISTS (SELECT 1 FROM shipment sh2 WHERE sh2.delivery_order_id = o.delivery_order_id AND sh2.status = 'active')"
);
$partialDo->execute([$uiTanggal, $uiFactoryId]);
$kpi['doParsial'] = (int) $partialDo->fetchColumn();
?>
<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="pengiriman">
    <div class="field"><label>Tanggal</label><input type="date" name="tanggal" value="<?= ui_esc($uiTanggal) ?>"></div>
    <div class="field"><label>Pabrik</label>
      <select name="factoryId">
        <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['factory_id'] ?>" <?= $uiFactoryId === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Grup</label>
      <select name="group">
        <option value="">Semua Grup</option>
        <?php foreach (['MAIN', 'PASTRY', 'OTHER'] as $g): ?>
        <option value="<?= $g ?>" <?= $groupParam === $g ? 'selected' : '' ?>><?= $g ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Status</label>
      <select name="status">
        <option value="">Semua Status</option>
        <option value="active" <?= $statusParam === 'active' ? 'selected' : '' ?>>Aktif</option>
        <option value="void" <?= $statusParam === 'void' ? 'selected' : '' ?>>Dibatalkan</option>
      </select>
    </div>
    <div class="field"><label>Cari Shipment/DO</label><input type="text" name="q" value="<?= ui_esc($searchTerm) ?>"></div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>
</div>

<div class="kpi-grid" style="grid-template-columns:repeat(6,minmax(0,1fr));">
  <?= ui_kpi_card(['label' => 'Shipment Hari Ini', 'value' => (string) $kpi['total'], 'icon' => 'truck', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'MAIN', 'value' => (string) $kpi['main'], 'icon' => 'truck', 'color' => 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'PASTRY', 'value' => (string) $kpi['pastry'], 'icon' => 'truck', 'color' => 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'OTHER', 'value' => (string) $kpi['other'], 'icon' => 'truck', 'color' => 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'Qty Terkirim', 'value' => ui_fmt_num($kpi['qty']), 'icon' => 'box', 'color' => 'success']) ?>
  <?= ui_kpi_card(['label' => 'DO Parsial', 'value' => (string) $kpi['doParsial'], 'icon' => 'file', 'color' => 'warning']) ?>
</div>

<div class="table-card section">
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Shipment No</th><th>DO No</th><th>Toko</th><th>Grup</th><th class="num">Qty Kirim</th><th>Status</th><th>Waktu Kirim</th><th>Pengirim</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if ($shipmentRows === []): ?>
    <tr><td colspan="9"><?= ui_empty_state('Belum ada pengiriman', 'Buat pengiriman dari halaman Delivery Order.') ?></td></tr>
    <?php else: foreach ($shipmentRows as $sh): ?>
    <tr>
      <td>#<?= (int) $sh['shipment_id'] ?></td>
      <td><?= $sh['delivery_order_id'] !== null ? '<a href="/api/_ui-preview/?page=delivery-order-detail&doId=' . (int) $sh['delivery_order_id'] . '">' . ui_esc((string) $sh['doc_no']) . '</a>' : '-' ?></td>
      <td><?= ui_esc($sh['store_name']) ?></td>
      <td><?= ui_esc($sh['shipment_group']) ?></td>
      <td class="num"><?= ui_fmt_num($sh['qty_total']) ?></td>
      <td><?= ui_badge(ui_shipment_status_label($sh['status'])) ?></td>
      <td><?= ui_esc((string) $sh['created_at']) ?></td>
      <td><?= ui_esc((string) ($sh['shipped_by_name'] ?? '-')) ?></td>
      <td><?= $sh['delivery_order_id'] !== null ? '<a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=delivery-order-detail&doId=' . (int) $sh['delivery_order_id'] . '">Lihat DO</a>' : '' ?></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
