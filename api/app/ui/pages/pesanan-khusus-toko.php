<?php

declare(strict_types=1);

/**
 * Pesanan Khusus Toko — a special/additional order FROM a store, outside
 * its regular PO (task's own examples: event order, custom cake request,
 * additional product outside normal PO). Deliberately NEVER touches
 * po_batch/po_item/po_store_item — this is a SEPARATE demand source (see
 * SpecialOrderService's own docblock on the "Core Principle").
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
$stores = $pdo->query("SELECT store_id, canonical_name FROM store WHERE active = 1 ORDER BY canonical_name")->fetchAll();
$products = $pdo->query("SELECT product_id, name, harga, division_id FROM product WHERE aktif = 1 ORDER BY name")->fetchAll();
$picUsers = $pdo->query("SELECT user_id, full_name FROM users WHERE active = 1 ORDER BY full_name")->fetchAll();

$statusFilter = (string) ($_GET['status'] ?? '');
$orders = $service->listOrders(array_filter(['sourceType' => 'toko_khusus', 'status' => $statusFilter !== '' ? $statusFilter : null]));
?>
<?= ui_pesanan_tabs('pesanan-khusus-toko', $uiTanggal, $uiFactoryId) ?>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Buat Pesanan Khusus Toko Baru</h2></div>
  <form id="pkt-form">
    <div class="kpi-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:var(--space-3);">
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
      <div class="field"><label>Factory Asal / Tujuan Produksi (opsional)</label>
        <select id="pkt-factory">
          <option value="">—</option>
          <?php foreach ($factories as $f): ?>
          <option value="<?= (int) $f['factory_id'] ?>"><?= ui_esc($f['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field"><label>Catatan Umum (opsional)</label><textarea id="pkt-note" rows="2"></textarea></div>

    <h3 class="card-title" style="margin:var(--space-4) 0 var(--space-2);">Item Pesanan</h3>
    <div class="table-scroll"><table class="data-table" id="pkt-items-table">
      <thead><tr><th>Jenis</th><th>Item</th><th>Divisi Produksi</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Charge</th><th>Catatan Khusus</th><th></th></tr></thead>
      <tbody></tbody>
    </table></div>
    <button type="button" class="btn btn-secondary btn-sm" id="pkt-add-item" style="margin-top:var(--space-2);">+ Tambah Item</button>

    <div style="margin-top:var(--space-4);">
      <button type="submit" class="btn btn-primary" id="pkt-submit">Simpan sebagai Draft</button>
      <span id="pkt-error" style="color:var(--danger);margin-left:var(--space-3);"></span>
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
    <thead><tr><th>No. Pesanan</th><th>Toko</th><th>Tanggal Dibutuhkan</th><th>Status</th><th>Divisi</th><th></th></tr></thead>
    <tbody>
    <?php if ($orders === []): ?>
    <tr><td colspan="6"><?= ui_empty_state('Belum ada Pesanan Khusus Toko', '') ?></td></tr>
    <?php else: foreach ($orders as $o): ?>
    <tr>
      <td><?= ui_esc($o['orderNo']) ?></td>
      <td><?= ui_esc((string) ($o['storeName'] ?? '-')) ?></td>
      <td><?= ui_esc($o['requiredDate']) ?><?= $o['requiredTime'] ? ' · ' . ui_esc(substr($o['requiredTime'], 0, 5)) : '' ?></td>
      <td><?= ui_badge(ui_special_order_status_label($o['status'])) ?></td>
      <td><?= $o['isMultiDivision'] ? '<span class="badge badge-primary">Multi Divisi</span>' : '-' ?></td>
      <td><a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=pesanan-khusus-toko-detail&id=<?= (int) $o['orderId'] ?>">Detail</a></td>
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

  var tbody = document.querySelector('#pkt-items-table tbody');
  var addBtn = document.getElementById('pkt-add-item');

  function addRow() {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><select class="pkt-item-type"><option value="existing_product">Produk Existing</option><option value="special_catalog">Item Khusus / Custom</option></select></td>' +
      '<td>' +
        '<input class="pkt-product-input" list="pkt-product-list" placeholder="Ketik nama produk...">' +
        '<select class="pkt-catalog-select" style="display:none;"><option value="">— Pilih Item Khusus —</option>' +
          CATALOG.map(function (c) { return '<option value="' + c.id + '">' + c.name + '</option>'; }).join('') +
        '</select>' +
      '</td>' +
      '<td class="pkt-division-preview" style="color:var(--text-muted);">-</td>' +
      '<td class="num"><input type="number" class="pkt-qty" min="0.01" step="0.01" value="1" style="width:70px;"></td>' +
      '<td class="num"><input type="number" class="pkt-price" min="0" step="1" value="0" style="width:90px;"></td>' +
      '<td class="num"><input type="number" class="pkt-charge" min="0" step="1" value="0" style="width:90px;"></td>' +
      '<td><input type="text" class="pkt-note" placeholder="Contoh: Tema Spiderman..." style="width:160px;"></td>' +
      '<td><button type="button" class="btn btn-secondary btn-sm pkt-remove-row">Hapus</button></td>';
    tbody.appendChild(tr);

    var typeSel = tr.querySelector('.pkt-item-type');
    var productInput = tr.querySelector('.pkt-product-input');
    var catalogSel = tr.querySelector('.pkt-catalog-select');
    var divisionPreview = tr.querySelector('.pkt-division-preview');
    var priceInput = tr.querySelector('.pkt-price');
    var chargeInput = tr.querySelector('.pkt-charge');

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

    tr.querySelector('.pkt-remove-row').addEventListener('click', function () { tr.remove(); });
  }
  addBtn.addEventListener('click', addRow);
  addRow();

  var datalist = document.createElement('datalist');
  datalist.id = 'pkt-product-list';
  PRODUCTS.forEach(function (p) { var o = document.createElement('option'); o.value = p.name; datalist.appendChild(o); });
  document.body.appendChild(datalist);

  document.getElementById('pkt-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    var errEl = document.getElementById('pkt-error');
    errEl.textContent = '';
    var storeId = document.getElementById('pkt-store').value;
    if (!storeId) { errEl.textContent = 'Toko wajib dipilih.'; return; }

    var items = [];
    var rowError = null;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      var itemType = tr.querySelector('.pkt-item-type').value;
      var qty = parseFloat(tr.querySelector('.pkt-qty').value) || 0;
      var unitPrice = parseFloat(tr.querySelector('.pkt-price').value) || 0;
      var charge = parseFloat(tr.querySelector('.pkt-charge').value) || 0;
      var note = tr.querySelector('.pkt-note').value.trim() || null;
      if (itemType === 'existing_product') {
        var p = productByName[tr.querySelector('.pkt-product-input').value];
        if (!p) { rowError = 'Setiap baris "Produk Existing" harus memilih produk yang valid dari daftar.'; return; }
        items.push({ itemType: 'existing_product', productId: p.id, qty: qty, unitPrice: unitPrice, charge: charge, specialNote: note });
      } else {
        var catalogId = tr.querySelector('.pkt-catalog-select').value;
        if (!catalogId) { rowError = 'Setiap baris "Item Khusus / Custom" harus memilih item dari katalog.'; return; }
        items.push({ itemType: 'special_catalog', specialCatalogId: parseInt(catalogId, 10), qty: qty, unitPrice: unitPrice, charge: charge, specialNote: note });
      }
    });
    if (rowError) { errEl.textContent = rowError; return; }
    if (items.length === 0) { errEl.textContent = 'Minimal 1 item pesanan.'; return; }

    var submitBtn = document.getElementById('pkt-submit');
    submitBtn.disabled = true;
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
          factoryId: document.getElementById('pkt-factory').value ? parseInt(document.getElementById('pkt-factory').value, 10) : null,
          generalNote: document.getElementById('pkt-note').value || null,
          items: items,
        },
      });
      Amor.toast('Pesanan Khusus Toko berhasil dibuat: ' + order.orderNo, 'success');
      window.location.href = '/api/_ui-preview/?page=pesanan-khusus-toko-detail&id=' + order.orderId;
    } catch (err) {
      errEl.textContent = err.message;
      submitBtn.disabled = false;
    }
  });
})();
</script>
