<?php

declare(strict_types=1);

/**
 * Pesanan Non-Toko — demand from Konsumen Langsung / CS / Sales Executive
 * / Umum (never a store's own PO). Same shared special_order/
 * special_order_item tables as Pesanan Khusus Toko (source_type=
 * 'non_toko'), never merged into PO. See pesanan-khusus-toko.php's own
 * docblock for the shared create/list-on-one-page design rationale and
 * the compact item-entry table (Amor.createOrderItemGrid()) rationale.
 */

use Amor\Api\SpecialOrder\SpecialOrderService;

$service = new SpecialOrderService($pdo);
$catalog = $service->listCatalog();
$productsForEntry = $service->listProductsForOrderEntry();
$picUsers = $pdo->query("SELECT user_id, full_name FROM users WHERE active = 1 ORDER BY full_name")->fetchAll();

$statusFilter = (string) ($_GET['status'] ?? '');
$orders = $service->listOrders(array_filter(['sourceType' => 'non_toko', 'status' => $statusFilter !== '' ? $statusFilter : null]));

$sourceLabels = ['konsumen_langsung' => 'Konsumen Langsung', 'cs' => 'CS', 'sales_executive' => 'Sales Executive', 'umum' => 'Umum'];
?>
<?= ui_pesanan_tabs('pesanan-non-toko', $uiTanggal, $uiFactoryId) ?>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Buat Pesanan Non-Toko Baru</h2></div>
  <form id="pnt-form">
    <h3 class="card-title" style="margin-bottom:2px;">Informasi Pesanan</h3>
    <p style="color:var(--text-muted);font-size:var(--text-sm);margin:0 0 var(--space-3);">Lengkapi informasi pesanan dengan benar.</p>
    <div class="kpi-grid kpi-grid-3" style="margin-bottom:var(--space-3);">
      <div class="field"><label>Sumber Pesanan</label>
        <select id="pnt-source" required>
          <option value="">— Pilih Sumber —</option>
          <?php foreach ($sourceLabels as $val => $label): ?>
          <option value="<?= $val ?>"><?= ui_esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Nama Customer</label><input type="text" id="pnt-customer-name" required></div>
      <div class="field"><label>Kontak</label><input type="text" id="pnt-contact" placeholder="No. HP / email"></div>
      <div class="field"><label>Tanggal Pesanan</label><input type="date" id="pnt-order-date" value="<?= ui_esc(date('Y-m-d')) ?>" required></div>
      <div class="field"><label>Tanggal Dibutuhkan</label><input type="date" id="pnt-required-date" required></div>
      <div class="field"><label>Waktu Dibutuhkan (opsional)</label><input type="time" id="pnt-required-time"></div>
      <div class="field"><label>Pengiriman / Pickup</label>
        <select id="pnt-fulfillment">
          <option value="">—</option>
          <option value="pengiriman">Pengiriman</option>
          <option value="pickup">Pickup</option>
        </select>
      </div>
      <div class="field"><label>Alamat / Tujuan (opsional)</label><input type="text" id="pnt-address"></div>
      <div class="field"><label>PIC Internal (opsional)</label>
        <select id="pnt-pic">
          <option value="">—</option>
          <?php foreach ($picUsers as $u): ?>
          <option value="<?= (int) $u['user_id'] ?>"><?= ui_esc($u['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field"><label>Catatan Umum (opsional)</label><textarea id="pnt-note" rows="2" placeholder="Contoh: Mohon dikerjakan sebelum jam 10."></textarea></div>

    <h3 class="card-title" style="margin:var(--space-5) 0 2px;">Item Pesanan</h3>
    <p style="color:var(--text-muted);font-size:var(--text-sm);margin:0 0 var(--space-3);">Tambah item yang dipesan beserta divisi produksinya. Divisi &amp; Factory ditentukan otomatis dari item yang dipilih.</p>
    <div id="pnt-items"></div>
    <div class="btn-group" style="margin-top:var(--space-3);">
      <button type="button" class="btn btn-secondary btn-sm" id="pnt-add-existing">+ Tambah Item Existing</button>
      <button type="button" class="btn btn-secondary btn-sm" id="pnt-add-custom">+ Tambah Item Custom</button>
    </div>

    <div class="subpanel" style="margin-top:var(--space-4);">
      <div class="subpanel-title">Ringkasan Pesanan</div>
      <div class="kpi-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:0;" id="pnt-summary"></div>
    </div>
    <div class="subpanel" style="margin-top:var(--space-4);">
      <div class="subpanel-title">Informasi Produksi</div>
      <div id="pnt-routing-info"></div>
    </div>

    <div style="margin-top:var(--space-4);display:flex;align-items:center;gap:var(--space-3);flex-wrap:wrap;">
      <button type="submit" class="btn btn-secondary" id="pnt-submit-draft" data-mode="draft">Simpan sebagai Draft</button>
      <button type="submit" class="btn btn-primary" id="pnt-submit-send" data-mode="send">Kirim ke Produksi</button>
      <span id="pnt-error" style="color:var(--danger);"></span>
    </div>
  </form>
</div>

<div class="table-card section">
  <div class="card-head" style="padding:var(--space-4) var(--space-4) 0;">
    <h2 class="card-title">Daftar Pesanan Non-Toko</h2>
    <form method="get" style="display:flex;gap:var(--space-2);align-items:flex-end;">
      <input type="hidden" name="page" value="pesanan-non-toko">
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
    <thead><tr><th>No. Pesanan</th><th>Sumber</th><th>Customer</th><th>Tanggal Dibutuhkan</th><th>Status</th><th>Routing</th><th></th></tr></thead>
    <tbody>
    <?php if ($orders === []): ?>
    <tr><td colspan="7"><?= ui_empty_state('Belum ada Pesanan Non-Toko', '') ?></td></tr>
    <?php else: foreach ($orders as $o): ?>
    <tr>
      <td><?= ui_esc($o['orderNo']) ?></td>
      <td><?= ui_esc($sourceLabels[$o['nonStoreSource']] ?? (string) $o['nonStoreSource']) ?></td>
      <td><?= ui_esc((string) ($o['customerName'] ?? '-')) ?></td>
      <td><?= ui_esc($o['requiredDate']) ?><?= $o['requiredTime'] ? ' · ' . ui_esc(substr($o['requiredTime'], 0, 5)) : '' ?></td>
      <td><?= ui_badge(ui_special_order_status_label($o['status'])) ?></td>
      <td>
        <?= $o['isMultiDivision'] ? '<span class="badge badge-primary">Multi Divisi</span> ' : '' ?>
        <?= $o['isMultiFactory'] ? '<span class="badge badge-neutral">Multi Factory</span>' : '' ?>
        <?= !$o['isMultiDivision'] && !$o['isMultiFactory'] ? '-' : '' ?>
      </td>
      <td><a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=pesanan-non-toko-detail&id=<?= (int) $o['orderId'] ?>">Detail</a></td>
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

    document.getElementById('pnt-summary').innerHTML =
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
    var routingEl = document.getElementById('pnt-routing-info');
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

  // Deferred to DOMContentLoaded — see pesanan-khusus-toko.php's own
  // comment on this exact same fix (Amor.createOrderItemGrid() itself
  // must never run before app.js/window.Amor has loaded).
  document.addEventListener('DOMContentLoaded', function () {
    grid = Amor.createOrderItemGrid(document.getElementById('pnt-items'), {
      products: PRODUCTS,
      catalog: CATALOG,
      onChange: refreshSummary,
    });
    document.getElementById('pnt-add-existing').addEventListener('click', function () { grid.addRow('existing_product'); });
    document.getElementById('pnt-add-custom').addEventListener('click', function () { grid.addRow('special_catalog'); });
    grid.addRow('existing_product');
  });

  async function submitOrder(sendToProduction) {
    var errEl = document.getElementById('pnt-error');
    errEl.textContent = '';
    var source = document.getElementById('pnt-source').value;
    var customerName = document.getElementById('pnt-customer-name').value.trim();
    if (!source) { errEl.textContent = 'Sumber Pesanan wajib dipilih.'; return; }
    if (!customerName) { errEl.textContent = 'Nama Customer wajib diisi.'; return; }

    var r = grid.getItems();
    if (r.rowError) { errEl.textContent = r.rowError; return; }
    if (r.items.length === 0) { errEl.textContent = 'Minimal 1 item pesanan.'; return; }

    var items = r.items.map(function (it) {
      return { itemType: it.itemType, productId: it.productId, specialCatalogId: it.specialCatalogId, qty: it.qty, unitPrice: it.unitPrice, charge: it.charge, extraPackaging: it.extraPackaging, specialNote: it.specialNote };
    });

    var draftBtn = document.getElementById('pnt-submit-draft');
    var sendBtn = document.getElementById('pnt-submit-send');
    draftBtn.disabled = true;
    sendBtn.disabled = true;
    try {
      var order = await Amor.apiFetch('/api/special-orders', {
        method: 'POST',
        body: {
          sourceType: 'non_toko',
          nonStoreSource: source,
          customerName: customerName,
          customerContact: document.getElementById('pnt-contact').value || null,
          fulfillmentType: document.getElementById('pnt-fulfillment').value || null,
          deliveryAddress: document.getElementById('pnt-address').value || null,
          orderDate: document.getElementById('pnt-order-date').value,
          requiredDate: document.getElementById('pnt-required-date').value,
          requiredTime: document.getElementById('pnt-required-time').value || null,
          picUserId: document.getElementById('pnt-pic').value ? parseInt(document.getElementById('pnt-pic').value, 10) : null,
          generalNote: document.getElementById('pnt-note').value || null,
          items: items,
        },
      });
      if (sendToProduction) {
        var confirmed = await Amor.apiFetch('/api/special-orders/' + order.orderId + '/confirm', { method: 'POST', body: { expectedVersion: order.version } });
        await Amor.apiFetch('/api/special-orders/' + order.orderId + '/send-to-production', { method: 'POST', body: { expectedVersion: confirmed.version } });
        Amor.toast('Pesanan Non-Toko berhasil dikirim ke Produksi: ' + order.orderNo, 'success');
      } else {
        Amor.toast('Pesanan Non-Toko disimpan sebagai Draft: ' + order.orderNo, 'success');
      }
      window.location.href = '/api/_ui-preview/?page=pesanan-non-toko-detail&id=' + order.orderId;
    } catch (err) {
      errEl.textContent = err.message;
      draftBtn.disabled = false;
      sendBtn.disabled = false;
    }
  }

  document.getElementById('pnt-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var mode = (e.submitter && e.submitter.dataset && e.submitter.dataset.mode) || 'draft';
    submitOrder(mode === 'send');
  });
})();
</script>
