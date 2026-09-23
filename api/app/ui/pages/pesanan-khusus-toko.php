<?php

declare(strict_types=1);

/**
 * Pesanan Khusus Toko — a special/additional order FROM a store, outside
 * its regular PO (task's own examples: event order, custom cake request,
 * additional product outside normal PO). Deliberately NEVER touches
 * po_batch/po_item/po_store_item — this is a SEPARATE demand source (see
 * SpecialOrderService's own docblock on the "Core Principle").
 *
 * UI/UX rework: item entry is a list of full-width CARDS (see
 * .order-item-card in app.css), not a cramped <table> of bare inputs —
 * every control is wrapped in `.field` so it gets the app's real dark
 * styling (the missing-`.field`-wrapper was the actual root cause of the
 * "native white control" / "clipped control" UAT bugs). Division/Factory
 * are always read-only badges: routing is derived automatically from the
 * chosen item, never a manual selector (task's own approved routing rule
 * — see ProductionRoutingService).
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
    <div class="order-item-list" id="pkt-items"></div>
    <div class="btn-group" style="margin-top:var(--space-3);">
      <button type="button" class="btn btn-secondary btn-sm" id="pkt-add-existing">+ Tambah Item Existing</button>
      <button type="button" class="btn btn-secondary btn-sm" id="pkt-add-custom">+ Tambah Item Custom</button>
    </div>

    <div class="grid-2" style="margin-top:var(--space-4);">
      <div class="subpanel">
        <div class="subpanel-title">Ringkasan Pesanan</div>
        <div class="kpi-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:0;" id="pkt-summary"></div>
      </div>
      <div class="subpanel">
        <div class="subpanel-title">Informasi Produksi</div>
        <div id="pkt-routing-info"></div>
      </div>
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

  var list = document.getElementById('pkt-items');
  var cards = [];

  function fmtRp(n) { return Amor.fmtRupiah(n); }

  function catalogOptions() {
    return '<option value="">— Pilih Item Khusus —</option>' + CATALOG.map(function (c) {
      return '<option value="' + c.catalogId + '">' + c.name + '</option>';
    }).join('');
  }

  function addCard(itemType) {
    var card = document.createElement('div');
    card.className = 'order-item-card';
    var typeBadge = itemType === 'existing_product'
      ? '<span class="badge badge-success">Produk Existing</span>'
      : '<span class="badge badge-warning">Item Khusus / Custom</span>';
    card.innerHTML =
      '<div class="order-item-card-head">' +
        '<span class="order-item-index"></span>' +
        '<button type="button" class="btn btn-secondary btn-sm order-item-remove">Hapus</button>' +
      '</div>' +
      '<div class="order-item-grid">' +
        '<div class="field"><label>Item</label>' +
          (itemType === 'existing_product'
            ? '<input type="text" class="pkt-item-input" placeholder="Ketik nama produk...">'
            : '<select class="pkt-item-input">' + catalogOptions() + '</select>') +
        '</div>' +
        '<div class="field"><label>Tipe Item</label><div class="badge-slot">' + typeBadge + '</div></div>' +
        '<div class="field"><label>Divisi Produksi</label><div class="badge-slot pkt-division-slot"><span class="badge badge-neutral">—</span></div></div>' +
        '<div class="field"><label>Factory</label><div class="badge-slot pkt-factory-slot"><span class="badge badge-neutral">—</span></div></div>' +
        '<div class="field"><label>Qty</label><input type="number" class="pkt-qty" min="0.01" step="0.01" value="1"></div>' +
        '<div class="field"><label>Harga (Rp)</label><input type="number" class="pkt-price" min="0" step="1" value="0"><span class="field-hint pkt-price-preview"></span></div>' +
        '<div class="field"><label>Charge (Rp)</label><input type="number" class="pkt-charge" min="0" step="1" value="0"><span class="field-hint pkt-charge-preview"></span></div>' +
        '<div class="field"><label>Extra Packaging (Rp)</label><input type="number" class="pkt-extra-packaging" min="0" step="1" value="0"><span class="field-hint pkt-extra-packaging-preview"></span></div>' +
        '<div class="field"><label>Subtotal Item</label><div class="field-static pkt-item-subtotal">Rp0</div></div>' +
        '<div class="field order-item-note-field"><label>Catatan Khusus</label><textarea class="pkt-note" rows="2" placeholder="Contoh: Tema Spiderman, tulisan HBD Raka, dominan warna biru..."></textarea></div>' +
      '</div>';
    list.appendChild(card);

    var state = { itemType: itemType, card: card, divisionName: null, factoryName: null, selectedProduct: null };
    cards.push(state);

    var itemInput = card.querySelector('.pkt-item-input');
    var divisionSlot = card.querySelector('.pkt-division-slot');
    var factorySlot = card.querySelector('.pkt-factory-slot');
    var priceInput = card.querySelector('.pkt-price');
    var chargeInput = card.querySelector('.pkt-charge');
    var extraPackagingInput = card.querySelector('.pkt-extra-packaging');

    function applyRouting(divisionName, factoryName) {
      state.divisionName = divisionName;
      state.factoryName = factoryName;
      divisionSlot.innerHTML = divisionName ? '<span class="badge badge-primary">' + divisionName + '</span>' : '<span class="badge badge-neutral">—</span>';
      factorySlot.innerHTML = factoryName ? '<span class="badge badge-neutral">' + factoryName + '</span>' : '<span class="badge badge-neutral">—</span>';
      renumber();
      refreshSummary();
    }

    if (itemType === 'existing_product') {
      // Real searchable dropdown, product_id authoritative — never free
      // text (task's own B). Selection is invalidated the moment the
      // user types again (Amor.createAutocomplete's own contract).
      Amor.createAutocomplete(itemInput, {
        items: PRODUCTS,
        getLabel: function (p) { return p.name; },
        onSelect: function (p) {
          state.selectedProduct = p;
          if (p) {
            priceInput.value = p.harga;
            applyRouting(p.divisionName, p.factoryName);
          } else {
            applyRouting(null, null);
          }
        },
      });
    } else {
      itemInput.addEventListener('change', function () {
        var c = CATALOG.filter(function (x) { return String(x.catalogId) === itemInput.value; })[0];
        if (c) {
          priceInput.value = c.defaultPrice || 0;
          chargeInput.value = c.defaultCharge || 0;
          applyRouting(c.divisionName, c.factoryName);
        } else {
          applyRouting(null, null);
        }
      });
    }
    priceInput.addEventListener('input', refreshSummary);
    chargeInput.addEventListener('input', refreshSummary);
    extraPackagingInput.addEventListener('input', refreshSummary);
    card.querySelector('.pkt-qty').addEventListener('input', refreshSummary);

    card.querySelector('.order-item-remove').addEventListener('click', function () {
      cards = cards.filter(function (s) { return s !== state; });
      card.remove();
      renumber();
      refreshSummary();
    });

    renumber();
    refreshSummary();
  }

  function renumber() {
    cards.forEach(function (s, i) { s.card.querySelector('.order-item-index').textContent = String(i + 1); });
  }

  function readCards() {
    var items = [];
    var rowError = null;
    cards.forEach(function (s) {
      var qty = parseFloat(s.card.querySelector('.pkt-qty').value) || 0;
      var unitPrice = parseFloat(s.card.querySelector('.pkt-price').value) || 0;
      var charge = parseFloat(s.card.querySelector('.pkt-charge').value) || 0;
      var extraPackaging = parseFloat(s.card.querySelector('.pkt-extra-packaging').value) || 0;
      var note = s.card.querySelector('.pkt-note').value.trim() || null;
      var itemInput = s.card.querySelector('.pkt-item-input');
      // Extra Packaging: added ONCE per line (task's own D), never
      // multiplied by qty — unlike unitPrice, which IS multiplied by qty.
      var subtotal = qty * unitPrice + charge + extraPackaging;
      if (s.itemType === 'existing_product') {
        var p = s.selectedProduct;
        if (!p) { rowError = 'Setiap item "Produk Existing" harus memilih produk yang valid dari daftar pencarian.'; return; }
        items.push({ itemType: 'existing_product', productId: p.productId, qty: qty, unitPrice: unitPrice, charge: charge, extraPackaging: extraPackaging, subtotal: subtotal, specialNote: note, divisionName: p.divisionName, factoryName: p.factoryName });
      } else {
        var catalogId = itemInput.value;
        if (!catalogId) { rowError = 'Setiap item "Item Khusus / Custom" harus memilih item dari katalog.'; return; }
        var c = CATALOG.filter(function (x) { return String(x.catalogId) === catalogId; })[0];
        items.push({ itemType: 'special_catalog', specialCatalogId: parseInt(catalogId, 10), qty: qty, unitPrice: unitPrice, charge: charge, extraPackaging: extraPackaging, subtotal: subtotal, specialNote: note, divisionName: c ? c.divisionName : null, factoryName: c ? c.factoryName : null });
      }
    });
    return { items: items, rowError: rowError };
  }

  function refreshCardPreviews() {
    cards.forEach(function (s) {
      var qty = parseFloat(s.card.querySelector('.pkt-qty').value) || 0;
      var unitPrice = parseFloat(s.card.querySelector('.pkt-price').value) || 0;
      var charge = parseFloat(s.card.querySelector('.pkt-charge').value) || 0;
      var extraPackaging = parseFloat(s.card.querySelector('.pkt-extra-packaging').value) || 0;
      s.card.querySelector('.pkt-price-preview').textContent = fmtRp(unitPrice);
      s.card.querySelector('.pkt-charge-preview').textContent = fmtRp(charge);
      s.card.querySelector('.pkt-extra-packaging-preview').textContent = fmtRp(extraPackaging);
      s.card.querySelector('.pkt-item-subtotal').textContent = fmtRp(qty * unitPrice + charge + extraPackaging);
    });
  }

  function refreshSummary() {
    refreshCardPreviews();
    var r = readCards();
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
      kpiTile('Subtotal Produk', fmtRp(subtotalProduk)) +
      kpiTile('Total Charge', fmtRp(totalCharge)) +
      kpiTile('Total Extra Packaging', fmtRp(totalExtraPackaging)) +
      kpiTile('Estimasi Total', fmtRp(estimasiTotal));

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

  document.getElementById('pkt-add-existing').addEventListener('click', function () { addCard('existing_product'); });
  document.getElementById('pkt-add-custom').addEventListener('click', function () { addCard('special_catalog'); });
  // Deferred to DOMContentLoaded: app.js (window.Amor) loads via a <script>
  // tag placed AFTER this page's own inline script (see layout.php's
  // ui_page_foot()) — addCard() now calls Amor.createAutocomplete()
  // synchronously, so calling it here directly would run before Amor
  // exists. DOMContentLoaded fires only after every synchronous <script>
  // in the document (including that later app.js tag) has executed.
  document.addEventListener('DOMContentLoaded', function () { addCard('existing_product'); });

  async function submitOrder(sendToProduction) {
    var errEl = document.getElementById('pkt-error');
    errEl.textContent = '';
    var storeId = document.getElementById('pkt-store').value;
    if (!storeId) { errEl.textContent = 'Toko wajib dipilih.'; return; }

    var r = readCards();
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
