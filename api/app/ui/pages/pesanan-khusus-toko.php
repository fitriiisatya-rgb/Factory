<?php

declare(strict_types=1);

/**
 * Pesanan Khusus Toko — a special/additional order FROM a store, outside
 * its regular PO (task's own examples: event order, custom cake request,
 * additional product outside normal PO). Deliberately NEVER touches
 * po_batch/po_item/po_store_item — this is a SEPARATE demand source (see
 * SpecialOrderService's own docblock on the "Core Principle").
 *
 * UI/UX rework: item entry is a compact, scrollable spreadsheet-like
 * table (Amor.createOrderItemGrid(), see app.js) so a real order with
 * 50+ items stays usable — replaces the old one-card-per-item layout.
 * Division/Factory are always read-only badges: routing is derived
 * automatically from the chosen item, never a manual selector (task's
 * own approved routing rule — see ProductionRoutingService).
 *
 * The create form + list live on the SAME page (task's own "MVP RULE" /
 * "keep it simple" — a full line-item is entered once at creation time;
 * see the OUTPUT REPORT's "known deferred enhancements" for why a
 * separate post-creation item-edit screen was deliberately not built).
 * All mutations go through the real JSON API (Amor.apiFetch), never a
 * duplicated write path here.
 */

use Amor\Api\SpecialOrder\SpecialOrderService;

$service = new SpecialOrderService($pdo);
$catalog = $service->listCatalog();
$productsForEntry = $service->listProductsForOrderEntry();
$stores = $pdo->query("SELECT store_id, canonical_name FROM store WHERE active = 1 ORDER BY canonical_name")->fetchAll();
$picUsers = $pdo->query("SELECT user_id, full_name FROM users WHERE active = 1 ORDER BY full_name")->fetchAll();

$statusFilter = (string) ($_GET['status'] ?? '');
$orders = $service->listOrders(array_filter(['sourceType' => 'toko_khusus', 'status' => $statusFilter !== '' ? $statusFilter : null]));
?>
<?= ui_pesanan_tabs('pesanan-khusus-toko', $uiTanggal, $uiFactoryId) ?>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Buat Pesanan Khusus Toko Baru</h2></div>
  <form id="pkt-form">
    <h3 class="card-title" style="margin-bottom:2px;">Informasi Pesanan</h3>
    <p style="color:var(--text-muted);font-size:var(--text-sm);margin:0 0 var(--space-3);">Lengkapi informasi pesanan dengan benar.</p>
    <div class="kpi-grid kpi-grid-3" style="margin-bottom:var(--space-3);">
      <div class="field"><label>Toko</label>
        <select id="pkt-store" required>
          <option value="">— Pilih Toko —</option>
          <?php foreach ($stores as $s): ?>
          <option value="<?= (int) $s['store_id'] ?>"><?= ui_esc($s['canonical_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Tanggal Pesanan</label><input type="date" id="pkt-order-date" value="<?= ui_esc(date('Y-m-d')) ?>" required></div>
      <div class="field"><label>Tanggal Dibutuhkan</label><input type="date" id="pkt-required-date" required></div>
      <div class="field"><label>Waktu Dibutuhkan (opsional)</label><input type="time" id="pkt-required-time"></div>
      <div class="field"><label>PIC Internal (opsional)</label>
        <select id="pkt-pic">
          <option value="">—</option>
          <?php foreach ($picUsers as $u): ?>
          <option value="<?= (int) $u['user_id'] ?>"><?= ui_esc($u['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field"><label>Catatan Umum (opsional)</label><textarea id="pkt-note" rows="2" placeholder="Contoh: Mohon dikerjakan sebelum jam 10."></textarea></div>

    <h3 class="card-title" style="margin:var(--space-5) 0 2px;">Item Pesanan</h3>
    <p style="color:var(--text-muted);font-size:var(--text-sm);margin:0 0 var(--space-3);">Tambah item yang dipesan beserta divisi produksinya. Divisi &amp; Factory ditentukan otomatis dari item yang dipilih.</p>
    <div id="pkt-items"></div>
    <div class="btn-group" style="margin-top:var(--space-3);">
      <button type="button" class="btn btn-secondary btn-sm" id="pkt-add-existing">+ Tambah Item Existing</button>
      <button type="button" class="btn btn-secondary btn-sm" id="pkt-add-custom">+ Tambah Item Custom</button>
    </div>

    <div class="subpanel" style="margin-top:var(--space-4);">
      <div class="subpanel-title">Ringkasan Pesanan</div>
      <div class="kpi-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:0;" id="pkt-summary"></div>
    </div>
    <div class="subpanel" style="margin-top:var(--space-4);">
      <div class="subpanel-title">Informasi Produksi</div>
      <div id="pkt-routing-info"></div>
    </div>

    <div style="margin-top:var(--space-4);display:flex;align-items:center;gap:var(--space-3);flex-wrap:wrap;">
      <button type="submit" class="btn btn-secondary" id="pkt-submit-draft" data-mode="draft">Simpan sebagai Draft</button>
      <button type="submit" class="btn btn-primary" id="pkt-submit-send" data-mode="send">Kirim ke Produksi</button>
      <span id="pkt-error" style="color:var(--danger);"></span>
    </div>
  </form>
</div>

<div class="table-card section">
  <div class="card-head" style="padding:var(--space-4) var(--space-4) 0;">
    <h2 class="card-title">Daftar Pesanan Khusus Toko</h2>
    <form method="get" style="display:flex;gap:var(--space-2);align-items:flex-end;">
      <input type="hidden" name="page" value="pesanan-khusus-toko">
      <div class="field"><label>Status</label>
        <select name="status" onchange="this.form.submit()">
          <option value="">Semua</option>
          <?php foreach (['draft', 'confirmed', 'sent_to_production', 'in_production', 'ready', 'completed', 'cancelled'] as $st): ?>
          <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ui_esc(ui_special_order_status_label($st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No. Pesanan</th><th>Toko</th><th>Tanggal Dibutuhkan</th><th>Status</th><th>Routing</th><th></th></tr></thead>
    <tbody>
    <?php if ($orders === []): ?>
    <tr><td colspan="6"><?= ui_empty_state('Belum ada Pesanan Khusus Toko', '') ?></td></tr>
    <?php else: foreach ($orders as $o): ?>
    <tr>
      <td><?= ui_esc($o['orderNo']) ?></td>
      <td><?= ui_esc((string) ($o['storeName'] ?? '-')) ?></td>
      <td><?= ui_esc($o['requiredDate']) ?><?= $o['requiredTime'] ? ' · ' . ui_esc(substr($o['requiredTime'], 0, 5)) : '' ?></td>
      <td><?= ui_badge(ui_special_order_status_label($o['status'])) ?></td>
      <td>
        <?= $o['isMultiDivision'] ? '<span class="badge badge-primary">Multi Divisi</span> ' : '' ?>
        <?= $o['isMultiFactory'] ? '<span class="badge badge-neutral">Multi Factory</span>' : '' ?>
        <?= !$o['isMultiDivision'] && !$o['isMultiFactory'] ? '-' : '' ?>
      </td>
      <td><a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=pesanan-khusus-toko-detail&id=<?= (int) $o['orderId'] ?>">Detail</a></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<script>
(function () {
  var PRODUCTS = <?= json_encode($productsForEntry, JSON_UNESCAPED_UNICODE) ?>;
  var CATALOG = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE) ?>;

  function fmtRp(n) { return Amor.fmtRupiah(n); }

  // grid is created inside DOMContentLoaded below (see that block's own
  // comment for why) — declared here so refreshSummary()/submitOrder()
  // can close over it before it is actually assigned.
  var grid = null;

  function refreshSummary() {
    var r = grid.getItems();
    var items = r.items;
    var jumlahItem = items.length;
    var totalQty = items.reduce(function (s, it) { return s + it.qty; }, 0);
    var subtotalProduk = items.reduce(function (s, it) { return s + it.qty * it.unitPrice; }, 0);
    var totalCharge = items.reduce(function (s, it) { return s + it.charge; }, 0);
    var totalExtraPackaging = items.reduce(function (s, it) { return s + it.extraPackaging; }, 0);
    var estimasiTotal = subtotalProduk + totalCharge + totalExtraPackaging;

    document.getElementById('pkt-summary').innerHTML =
      kpiTile('Jumlah Item', String(jumlahItem)) +
      kpiTile('Total Qty', String(totalQty)) +
      kpiTile('Total Nilai Pesanan', fmtRp(estimasiTotal)) +
      kpiTile('Subtotal Produk', fmtRp(subtotalProduk)) +
      kpiTile('Total Charge', fmtRp(totalCharge)) +
      kpiTile('Total Extra Packaging', fmtRp(totalExtraPackaging));

    var byFactory = {};
    var order = [];
    items.forEach(function (it) {
      if (!it.factoryName) return;
      if (!byFactory[it.factoryName]) { byFactory[it.factoryName] = []; order.push(it.factoryName); }
      if (byFactory[it.factoryName].indexOf(it.divisionName) === -1) byFactory[it.factoryName].push(it.divisionName);
    });
    var divisionCount = new Set(items.map(function (it) { return it.divisionName; }).filter(Boolean)).size;
    var routingEl = document.getElementById('pkt-routing-info');
    if (order.length === 0) {
      routingEl.innerHTML = '<p style="color:var(--text-muted);font-size:var(--text-sm);">Tambahkan item untuk melihat routing produksi.</p>';
    } else {
      var html = '<p class="routing-info-intro">Pesanan ini akan dikirim ke ' + divisionCount + ' divisi produksi' + (order.length > 1 ? ' di ' + order.length + ' factory berbeda' : '') + ':</p>';
      order.forEach(function (factoryName) {
        html += '<div class="routing-factory-group"><div class="routing-factory-name">' + factoryName + '</div>' +
          '<div class="routing-division-badges">' + byFactory[factoryName].map(function (d) { return '<span class="badge badge-primary">' + d + '</span>'; }).join('') + '</div></div>';
      });
      routingEl.innerHTML = html;
    }
  }

  function kpiTile(label, value) {
    return '<div class="kpi-card kpi-card--detail"><div class="kpi-label">' + label + '</div><div class="kpi-value">' + value + '</div></div>';
  }

  // Deferred to DOMContentLoaded: app.js (window.Amor) loads via a <script>
  // tag placed AFTER this page's own inline script (see layout.php's own
  // ui_page_foot()) — Amor.createOrderItemGrid() itself (and the
  // Amor.createAutocomplete() call it makes for the first row) must run
  // AFTER that later script has executed, never synchronously here, or
  // "Amor is not defined" aborts this whole script (the actual bug a real
  // Apache/Playwright run caught: the table never rendered at all).
  // DOMContentLoaded fires only once every synchronous <script> in the
  // document, including that later app.js tag, has already run.
  document.addEventListener('DOMContentLoaded', function () {
    grid = Amor.createOrderItemGrid(document.getElementById('pkt-items'), {
      products: PRODUCTS,
      catalog: CATALOG,
      onChange: refreshSummary,
    });
    document.getElementById('pkt-add-existing').addEventListener('click', function () { grid.addRow('existing_product'); });
    document.getElementById('pkt-add-custom').addEventListener('click', function () { grid.addRow('special_catalog'); });
    grid.addRow('existing_product');
  });

  async function submitOrder(sendToProduction) {
    var errEl = document.getElementById('pkt-error');
    errEl.textContent = '';
    var storeId = document.getElementById('pkt-store').value;
    if (!storeId) { errEl.textContent = 'Toko wajib dipilih.'; return; }

    var r = grid.getItems();
    if (r.rowError) { errEl.textContent = r.rowError; return; }
    if (r.items.length === 0) { errEl.textContent = 'Minimal 1 item pesanan.'; return; }

    var items = r.items.map(function (it) {
      return { itemType: it.itemType, productId: it.productId, specialCatalogId: it.specialCatalogId, qty: it.qty, unitPrice: it.unitPrice, charge: it.charge, extraPackaging: it.extraPackaging, specialNote: it.specialNote };
    });

    var draftBtn = document.getElementById('pkt-submit-draft');
    var sendBtn = document.getElementById('pkt-submit-send');
    draftBtn.disabled = true;
    sendBtn.disabled = true;
    try {
      var order = await Amor.apiFetch('/api/special-orders', {
        method: 'POST',
        body: {
          sourceType: 'toko_khusus',
          storeId: parseInt(storeId, 10),
          orderDate: document.getElementById('pkt-order-date').value,
          requiredDate: document.getElementById('pkt-required-date').value,
          requiredTime: document.getElementById('pkt-required-time').value || null,
          picUserId: document.getElementById('pkt-pic').value ? parseInt(document.getElementById('pkt-pic').value, 10) : null,
          generalNote: document.getElementById('pkt-note').value || null,
          items: items,
        },
      });
      if (sendToProduction) {
        var confirmed = await Amor.apiFetch('/api/special-orders/' + order.orderId + '/confirm', { method: 'POST', body: { expectedVersion: order.version } });
        await Amor.apiFetch('/api/special-orders/' + order.orderId + '/send-to-production', { method: 'POST', body: { expectedVersion: confirmed.version } });
        Amor.toast('Pesanan Khusus Toko berhasil dikirim ke Produksi: ' + order.orderNo, 'success');
      } else {
        Amor.toast('Pesanan Khusus Toko disimpan sebagai Draft: ' + order.orderNo, 'success');
      }
      window.location.href = '/api/_ui-preview/?page=pesanan-khusus-toko-detail&id=' + order.orderId;
    } catch (err) {
      errEl.textContent = err.message;
      draftBtn.disabled = false;
      sendBtn.disabled = false;
    }
  }

  document.getElementById('pkt-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var mode = (e.submitter && e.submitter.dataset && e.submitter.dataset.mode) || 'draft';
    submitOrder(mode === 'send');
  });
})();
</script>
