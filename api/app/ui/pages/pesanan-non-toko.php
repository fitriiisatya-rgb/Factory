<?php

declare(strict_types=1);

/**
 * Pesanan Non-Toko — demand from Konsumen Langsung / CS / Sales Executive
 * / Umum (never a store's own PO). Same shared special_order/
 * special_order_item tables as Pesanan Khusus Toko (source_type=
 * 'non_toko'), never merged into PO. See pesanan-khusus-toko.php's own
 * docblock for the shared create/list-on-one-page design rationale.
 */

use Amor\Api\SpecialOrder\SpecialOrderService;

$service = new SpecialOrderService($pdo);
$catalog = $service->listCatalog();
$products = $pdo->query("SELECT product_id, name, harga, division_id FROM product WHERE aktif = 1 ORDER BY name")->fetchAll();
$picUsers = $pdo->query("SELECT user_id, full_name FROM users WHERE active = 1 ORDER BY full_name")->fetchAll();

$statusFilter = (string) ($_GET['status'] ?? '');
$orders = $service->listOrders(array_filter(['sourceType' => 'non_toko', 'status' => $statusFilter !== '' ? $statusFilter : null]));

$sourceLabels = ['konsumen_langsung' => 'Konsumen Langsung', 'cs' => 'CS', 'sales_executive' => 'Sales Executive', 'umum' => 'Umum'];
?>
<?= ui_pesanan_tabs('pesanan-non-toko', $uiTanggal, $uiFactoryId) ?>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Buat Pesanan Non-Toko Baru</h2></div>
  <form id="pnt-form">
    <div class="kpi-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:var(--space-3);">
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
      <div class="field"><label>PIC Internal (opsional)</label>
        <select id="pnt-pic">
          <option value="">—</option>
          <?php foreach ($picUsers as $u): ?>
          <option value="<?= (int) $u['user_id'] ?>"><?= ui_esc($u['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Factory Tujuan Produksi (opsional)</label>
        <select id="pnt-factory">
          <option value="">—</option>
          <?php foreach ($factories as $f): ?>
          <option value="<?= (int) $f['factory_id'] ?>"><?= ui_esc($f['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field"><label>Alamat / Tujuan (opsional)</label><input type="text" id="pnt-address"></div>
    <div class="field"><label>Catatan Umum (opsional)</label><textarea id="pnt-note" rows="2"></textarea></div>

    <h3 class="card-title" style="margin:var(--space-4) 0 var(--space-2);">Item Pesanan</h3>
    <div class="table-scroll"><table class="data-table" id="pnt-items-table">
      <thead><tr><th>Jenis</th><th>Item</th><th>Divisi Produksi</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Charge</th><th>Catatan Khusus</th><th></th></tr></thead>
      <tbody></tbody>
    </table></div>
    <button type="button" class="btn btn-secondary btn-sm" id="pnt-add-item" style="margin-top:var(--space-2);">+ Tambah Item</button>

    <div style="margin-top:var(--space-4);">
      <button type="submit" class="btn btn-primary" id="pnt-submit">Simpan sebagai Draft</button>
      <span id="pnt-error" style="color:var(--danger);margin-left:var(--space-3);"></span>
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
    <thead><tr><th>No. Pesanan</th><th>Sumber</th><th>Customer</th><th>Tanggal Dibutuhkan</th><th>Status</th><th>Divisi</th><th></th></tr></thead>
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
      <td><?= $o['isMultiDivision'] ? '<span class="badge badge-primary">Multi Divisi</span>' : '-' ?></td>
      <td><a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=pesanan-non-toko-detail&id=<?= (int) $o['orderId'] ?>">Detail</a></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<script>
(function () {
  var PRODUCTS = <?= json_encode(array_map(fn ($p) => ['id' => (int) $p['product_id'], 'name' => $p['name'], 'harga' => (float) $p['harga']], $products), JSON_UNESCAPED_UNICODE) ?>;
  var CATALOG = <?= json_encode(array_map(fn ($c) => ['id' => $c['catalogId'], 'name' => $c['name'], 'divisionName' => $c['divisionName'], 'defaultPrice' => $c['defaultPrice'], 'defaultCharge' => $c['defaultCharge']], $catalog), JSON_UNESCAPED_UNICODE) ?>;
  var productByName = {};
  PRODUCTS.forEach(function (p) { productByName[p.name] = p; });

  var tbody = document.querySelector('#pnt-items-table tbody');
  var addBtn = document.getElementById('pnt-add-item');

  function addRow() {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><select class="pnt-item-type"><option value="existing_product">Produk Existing</option><option value="special_catalog">Item Khusus / Custom</option></select></td>' +
      '<td>' +
        '<input class="pnt-product-input" list="pnt-product-list" placeholder="Ketik nama produk...">' +
        '<select class="pnt-catalog-select" style="display:none;"><option value="">— Pilih Item Khusus —</option>' +
          CATALOG.map(function (c) { return '<option value="' + c.id + '">' + c.name + '</option>'; }).join('') +
        '</select>' +
      '</td>' +
      '<td class="pnt-division-preview" style="color:var(--text-muted);">-</td>' +
      '<td class="num"><input type="number" class="pnt-qty" min="0.01" step="0.01" value="1" style="width:70px;"></td>' +
      '<td class="num"><input type="number" class="pnt-price" min="0" step="1" value="0" style="width:90px;"></td>' +
      '<td class="num"><input type="number" class="pnt-charge" min="0" step="1" value="0" style="width:90px;"></td>' +
      '<td><input type="text" class="pnt-note" placeholder="Contoh: Dominan warna biru..." style="width:160px;"></td>' +
      '<td><button type="button" class="btn btn-secondary btn-sm pnt-remove-row">Hapus</button></td>';
    tbody.appendChild(tr);

    var typeSel = tr.querySelector('.pnt-item-type');
    var productInput = tr.querySelector('.pnt-product-input');
    var catalogSel = tr.querySelector('.pnt-catalog-select');
    var divisionPreview = tr.querySelector('.pnt-division-preview');
    var priceInput = tr.querySelector('.pnt-price');
    var chargeInput = tr.querySelector('.pnt-charge');

    function refreshVisibility() {
      var isExisting = typeSel.value === 'existing_product';
      productInput.style.display = isExisting ? '' : 'none';
      catalogSel.style.display = isExisting ? 'none' : '';
      divisionPreview.textContent = '-';
    }
    typeSel.addEventListener('change', refreshVisibility);
    refreshVisibility();

    productInput.addEventListener('input', function () {
      var p = productByName[productInput.value];
      if (p) {
        divisionPreview.textContent = '(otomatis dari produk)';
        priceInput.value = p.harga;
      }
    });
    catalogSel.addEventListener('change', function () {
      var c = CATALOG.filter(function (x) { return String(x.id) === catalogSel.value; })[0];
      if (c) {
        divisionPreview.textContent = c.divisionName;
        priceInput.value = c.defaultPrice || 0;
        chargeInput.value = c.defaultCharge || 0;
      } else {
        divisionPreview.textContent = '-';
      }
    });

    tr.querySelector('.pnt-remove-row').addEventListener('click', function () { tr.remove(); });
  }
  addBtn.addEventListener('click', addRow);
  addRow();

  var datalist = document.createElement('datalist');
  datalist.id = 'pnt-product-list';
  PRODUCTS.forEach(function (p) { var o = document.createElement('option'); o.value = p.name; datalist.appendChild(o); });
  document.body.appendChild(datalist);

  document.getElementById('pnt-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    var errEl = document.getElementById('pnt-error');
    errEl.textContent = '';
    var source = document.getElementById('pnt-source').value;
    var customerName = document.getElementById('pnt-customer-name').value.trim();
    if (!source) { errEl.textContent = 'Sumber Pesanan wajib dipilih.'; return; }
    if (!customerName) { errEl.textContent = 'Nama Customer wajib diisi.'; return; }

    var items = [];
    var rowError = null;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      var itemType = tr.querySelector('.pnt-item-type').value;
      var qty = parseFloat(tr.querySelector('.pnt-qty').value) || 0;
      var unitPrice = parseFloat(tr.querySelector('.pnt-price').value) || 0;
      var charge = parseFloat(tr.querySelector('.pnt-charge').value) || 0;
      var note = tr.querySelector('.pnt-note').value.trim() || null;
      if (itemType === 'existing_product') {
        var p = productByName[tr.querySelector('.pnt-product-input').value];
        if (!p) { rowError = 'Setiap baris "Produk Existing" harus memilih produk yang valid dari daftar.'; return; }
        items.push({ itemType: 'existing_product', productId: p.id, qty: qty, unitPrice: unitPrice, charge: charge, specialNote: note });
      } else {
        var catalogId = tr.querySelector('.pnt-catalog-select').value;
        if (!catalogId) { rowError = 'Setiap baris "Item Khusus / Custom" harus memilih item dari katalog.'; return; }
        items.push({ itemType: 'special_catalog', specialCatalogId: parseInt(catalogId, 10), qty: qty, unitPrice: unitPrice, charge: charge, specialNote: note });
      }
    });
    if (rowError) { errEl.textContent = rowError; return; }
    if (items.length === 0) { errEl.textContent = 'Minimal 1 item pesanan.'; return; }

    var submitBtn = document.getElementById('pnt-submit');
    submitBtn.disabled = true;
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
          factoryId: document.getElementById('pnt-factory').value ? parseInt(document.getElementById('pnt-factory').value, 10) : null,
          generalNote: document.getElementById('pnt-note').value || null,
          items: items,
        },
      });
      Amor.toast('Pesanan Non-Toko berhasil dibuat: ' + order.orderNo, 'success');
      window.location.href = '/api/_ui-preview/?page=pesanan-non-toko-detail&id=' + order.orderId;
    } catch (err) {
      errEl.textContent = err.message;
      submitBtn.disabled = false;
    }
  });
})();
</script>
