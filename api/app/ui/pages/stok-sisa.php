<?php

declare(strict_types=1);

use Amor\Api\Fg\StokSisaService;

/*
 * Stok Sisa — production kept aside by FG (above the PO, or never split
 * per store). Admin sends it on with one tap: "Kirim susulan" creates a
 * Pesanan Khusus Toko for the chosen store, "Jual non-toko" a Pesanan
 * Non-Toko; both take the goods through the existing "ambil dari stok
 * FG" allocation, so the usual DO -> driver -> toko/pelanggan -> invoice
 * path follows unchanged.
 */
$sisaFactory = isset($_GET['sisaFactoryId']) && $_GET['sisaFactoryId'] !== '' ? (int) $_GET['sisaFactoryId'] : null;
$rows = (new StokSisaService($pdo))->list($sisaFactory);
$stores = $pdo->query("SELECT store_id, canonical_name FROM store WHERE active = 1 AND canonical_name <> 'NON-OUTLET / PERORANGAN' ORDER BY canonical_name")->fetchAll();
$totalPcs = array_sum(array_column($rows, 'sisa'));
$totalValue = array_sum(array_map(static fn ($r) => $r['sisa'] * $r['harga'], $rows));
$byDivision = [];
foreach ($rows as $r) {
    $byDivision[$r['divisionName'] ?? 'Tanpa Divisi'][] = $r;
}
?>
<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="stok-sisa">
    <div class="field"><label>Pabrik</label>
      <select name="sisaFactoryId">
        <option value="">Semua pabrik</option>
        <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['factory_id'] ?>" <?= $sisaFactory === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Tampilkan</button>
  </form>
</div>

<div class="kpi-grid kpi-grid-3">
  <?= ui_kpi_card(['label' => 'Produk dengan Stok Sisa', 'value' => (string) count($rows), 'icon' => 'box', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'Total Stok Sisa', 'value' => ui_fmt_num($totalPcs) . ' pcs', 'icon' => 'factory', 'color' => 'warning']) ?>
  <?= ui_kpi_card(['label' => 'Perkiraan Nilai', 'value' => ui_fmt_money($totalValue), 'icon' => 'chart', 'color' => 'success']) ?>
</div>

<p style="color:var(--text-muted);font-size:var(--text-sm);margin:0 0 var(--space-3);">
  Stok Sisa = barang jadi di gudang pabrik yang <b>bukan milik toko mana pun</b>: kelebihan produksi dari PO yang disisihkan FG.
  Kirim susulan ke toko (jadi Pesanan Khusus Toko) atau jual ke non-toko (jadi Pesanan Non-Toko). Keduanya lanjut lewat DO, driver, dan invoice seperti biasa.
</p>

<?php if ($rows === []): ?>
<div class="card"><?= ui_empty_state('Tidak ada stok sisa', 'Semua barang jadi sudah milik toko atau sudah terkirim.') ?></div>
<?php endif; ?>

<?php foreach ($byDivision as $divName => $list): ?>
<div class="table-card section">
  <div class="card-head" style="padding:var(--space-4) var(--space-4) 0;">
    <h2 class="card-title">Divisi <?= ui_esc((string) $divName) ?></h2>
    <span style="color:var(--text-muted);font-size:var(--text-sm);"><?= ui_fmt_num(array_sum(array_column($list, 'sisa'))) ?> pcs</span>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Produk</th><th>Pabrik</th><th class="num">Stok Sisa</th><th class="num">Harga</th><th>Terakhir Disisihkan</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($list as $r): ?>
    <tr data-product-id="<?= $r['productId'] ?>" data-factory-id="<?= $r['factoryId'] ?>" data-name="<?= ui_esc($r['productName']) ?>" data-sisa="<?= $r['sisa'] ?>" data-harga="<?= $r['harga'] ?>">
      <td><?= ui_esc($r['productName']) ?></td>
      <td><?= ui_esc($r['factoryName']) ?></td>
      <td class="num"><strong><?= ui_fmt_num($r['sisa']) ?></strong></td>
      <td class="num"><?= $r['harga'] > 0 ? ui_fmt_money($r['harga']) : '<span class="badge badge-warning">Belum ada harga</span>' ?></td>
      <td><?= $r['lastSurplusDate'] ? ui_esc(ui_fmt_date_id((string) $r['lastSurplusDate'])) : '-' ?></td>
      <td><div class="row-actions">
        <button type="button" class="btn btn-primary btn-sm" data-sisa-action="toko">Kirim susulan ke toko</button>
        <button type="button" class="btn btn-secondary btn-sm" data-sisa-action="non_toko">Jual non-toko</button>
      </div></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endforeach; ?>

<template id="sisa-store-options">
  <?php foreach ($stores as $s): ?><option value="<?= (int) $s['store_id'] ?>"><?= ui_esc($s['canonical_name']) ?></option><?php endforeach; ?>
</template>

<script>
(function () {
  var today = <?= json_encode(date('Y-m-d')) ?>;
  function esc(s) { var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }
  function form(kind, row) {
    var sisa = parseFloat(row.dataset.sisa);
    var html = '<div class="field"><label>Produk</label><div class="field-static">' + esc(row.dataset.name) + ' &middot; sisa ' + sisa.toLocaleString('id-ID') + ' pcs</div></div>';
    if (kind === 'toko') {
      html += '<div class="field"><label>Toko tujuan</label><select id="sisa-store">' + document.getElementById('sisa-store-options').innerHTML + '</select></div>';
    } else {
      html += '<div class="field"><label>Sumber</label><select id="sisa-source"><option value="cs">CS</option><option value="sales_executive">Sales Eksekutif</option><option value="umum">Lainnya</option></select></div>'
        + '<div class="field"><label>Nama pelanggan</label><input type="text" id="sisa-customer" placeholder="Nama pembeli"></div>';
    }
    html += '<div class="field"><label>Jumlah (pcs)</label><input type="number" id="sisa-qty" min="1" max="' + sisa + '" step="1" value="' + sisa + '"></div>'
      + '<div class="field"><label>Harga satuan</label><input type="number" id="sisa-price" min="0" step="100" value="' + (parseFloat(row.dataset.harga) || 0) + '"></div>'
      + '<div class="field"><label>Tanggal kirim</label><input type="date" id="sisa-date" value="' + today + '"></div>';
    return html;
  }
  async function run(kind, row) {
    var ok = await Amor.confirmModal({
      title: kind === 'toko' ? 'Kirim susulan ke toko' : 'Jual ke non-toko',
      body: '', bodyHtml: form(kind, row), confirmLabel: 'Buat pesanan',
    });
    if (!ok) return;
    var qty = parseFloat(document.getElementById('sisa-qty') ? document.getElementById('sisa-qty').value : '0');
    // Values are read before the modal closes via onConfirm-less flow — fall back to stored ones.
    var v = window.__sisaValues || {};
    qty = v.qty || qty;
    if (!(qty > 0) || qty > parseFloat(row.dataset.sisa) + 0.0001) { Amor.toast('Jumlah harus 1 sampai ' + row.dataset.sisa + '.', 'danger'); return; }
    var body = {
      sourceType: kind === 'toko' ? 'toko_khusus' : 'non_toko',
      orderDate: v.date, requiredDate: v.date,
      generalNote: 'Dari Stok Sisa produksi',
      items: [{ itemType: 'existing_product', productId: parseInt(row.dataset.productId, 10), qty: qty, unitPrice: v.price }],
    };
    if (kind === 'toko') { body.storeId = v.storeId; }
    else { body.nonStoreSource = v.source; body.customerName = v.customer || 'Pembeli Stok Sisa'; body.fulfillmentType = 'pickup'; }
    try {
      var order = await Amor.apiFetch('/api/special-orders', { method: 'POST', body: body });
      var c = await Amor.apiFetch('/api/special-orders/' + order.orderId + '/confirm', { method: 'POST', body: { expectedVersion: order.version } });
      await Amor.apiFetch('/api/special-orders/' + order.orderId + '/send-to-production', { method: 'POST', body: { expectedVersion: c.version } });
      await Amor.apiFetch('/api/special-orders/items/' + order.items[0].itemId + '/allocate-fg', { method: 'POST', body: { qty: qty } });
      Amor.toast('Pesanan ' + order.orderNo + ' dibuat dan diambil dari Stok Sisa.', 'success');
      setTimeout(function () {
        location.href = '/api/_ui-preview/?page=' + (kind === 'toko' ? 'pesanan-khusus-toko-detail' : 'pesanan-non-toko-detail') + '&orderId=' + order.orderId;
      }, 700);
    } catch (e) { Amor.toast(e.message, 'danger'); }
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-sisa-action]');
    if (!b) return;
    run(b.getAttribute('data-sisa-action'), b.closest('tr'));
  });
  // Capture the modal's field values the moment "Buat pesanan" is pressed
  // (the modal removes its DOM on close).
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.modal [data-act="confirm"]')) return;
    var g = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
    window.__sisaValues = { qty: parseFloat(g('sisa-qty')), price: parseFloat(g('sisa-price')) || 0, date: g('sisa-date') || today,
      storeId: parseInt(g('sisa-store'), 10) || null, source: g('sisa-source'), customer: g('sisa-customer') };
  }, true);
})();
</script>
