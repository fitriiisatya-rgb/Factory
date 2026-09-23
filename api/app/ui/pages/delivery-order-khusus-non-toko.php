<?php

declare(strict_types=1);

/**
 * DO Pesanan Khusus Toko / Pesanan Non-Toko — the SEPARATE, source-
 * specific DO (task's own approved rule: "DIFFERENT DEMAND SOURCES MUST
 * HAVE SEPARATE DO... DO NOT MERGE DIFFERENT SOURCE TYPES INTO ONE DO").
 * Reads special_order_do (migration 0012, reworked) — a dedicated table,
 * never delivery_order/delivery_order_item (Regular PO's own DO,
 * completely untouched — see delivery-order.php).
 *
 * Reworked for Driver Internal + External Courier fulfillment: an order
 * may now have MULTIPLE DOs (never "returns the same DO" — see
 * SpecialOrderDoService::create()'s own docblock), and every DO is
 * factory-scoped (this page's own factory filter IS the DO's
 * pickup_factory_id). A non-toko order has NO natural default drop
 * point, so the "Buat DO" row for one requires an explicit Drop Bakery
 * selection before the button is enabled.
 */

use Amor\Api\SpecialOrder\SpecialOrderDoService;
use Amor\Api\SpecialOrder\SpecialOrderService;

$orderService = new SpecialOrderService($pdo);
$doService = new SpecialOrderDoService($pdo);

// Items with real remaining availability for a NEW DO — never fgVerifiedQty
// alone (which double-counts what an earlier DO already committed).
$fgItems = $orderService->fgEligibleItems(['factoryId' => $uiFactoryId]);
$ordersReady = [];
foreach ($fgItems as $it) {
    if ($it['availableForDo'] <= 0.0001) {
        continue;
    }
    if (!isset($ordersReady[$it['orderId']])) {
        $ordersReady[$it['orderId']] = [
            'orderId' => $it['orderId'],
            'orderNo' => $it['orderNo'],
            'sourceType' => $it['sourceType'],
            'sourceLabel' => $it['sourceLabel'],
            'storeOrCustomerName' => $it['storeOrCustomerName'],
            'itemCount' => 0,
        ];
    }
    $ordersReady[$it['orderId']]['itemCount']++;
}

$stores = $pdo->query("SELECT store_id, canonical_name FROM store WHERE active = 1 ORDER BY canonical_name")->fetchAll();

$statusFilter = (string) ($_GET['status'] ?? '');
$sourceTypeFilter = (string) ($_GET['sourceType'] ?? '');
$dos = $doService->listDos(array_filter([
    'status' => $statusFilter !== '' ? $statusFilter : null,
    'sourceType' => $sourceTypeFilter !== '' ? $sourceTypeFilter : null,
    'factoryId' => $uiFactoryId,
]));

$doStatusLabels = ['open' => 'Open', 'partial' => 'Partial', 'shipped' => 'Shipped', 'cancelled' => 'Dibatalkan'];
$doStatusColors = ['open' => 'neutral', 'partial' => 'warning', 'shipped' => 'success', 'cancelled' => 'danger'];
?>
<?= ui_do_tabs('delivery-order-khusus-non-toko', $uiTanggal, $uiFactoryId) ?>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Pesanan Siap Dibuat DO — Pabrik <?= ui_esc($uiFactoryName) ?></h2></div>
  <p style="color:var(--text-muted);font-size:var(--text-sm);margin:0 0 var(--space-3);">Satu DO hanya untuk SATU pabrik (pengambilan kurir eksternal selalu dari satu lokasi fisik). Pesanan yang sama bisa membuat DO baru lagi selama masih ada FG yang belum dialokasikan (pengiriman bertahap/parsial).</p>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No. Pesanan</th><th>Sumber</th><th>Toko/Customer</th><th class="num">Item Siap</th><th>Drop Bakery</th><th></th></tr></thead>
    <tbody>
    <?php if ($ordersReady === []): ?>
    <tr><td colspan="6"><?= ui_empty_state('Belum ada pesanan dengan item tersedia untuk DO baru', 'Verifikasi FG dahulu di tab FG Sumber Khusus / Non-Toko.') ?></td></tr>
    <?php else: foreach ($ordersReady as $o): ?>
    <tr>
      <td><?= ui_esc($o['orderNo']) ?></td>
      <td><span class="badge badge-neutral"><?= ui_esc($o['sourceLabel']) ?></span></td>
      <td><?= ui_esc((string) $o['storeOrCustomerName']) ?></td>
      <td class="num"><?= (int) $o['itemCount'] ?></td>
      <td>
        <?php if ($o['sourceType'] === 'toko_khusus'): ?>
        <span style="color:var(--text-muted);font-size:var(--text-sm);">Toko pesanan (otomatis)</span>
        <?php else: ?>
        <select class="do-drop-store" data-order-id="<?= (int) $o['orderId'] ?>" style="min-width:160px;">
          <option value="">— Pilih Bakery —</option>
          <?php foreach ($stores as $s): ?>
          <option value="<?= (int) $s['store_id'] ?>"><?= ui_esc($s['canonical_name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </td>
      <td><button type="button" class="btn btn-primary btn-sm do-create-btn" data-order-id="<?= (int) $o['orderId'] ?>" data-source-type="<?= ui_esc($o['sourceType']) ?>">Buat DO</button></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<div class="table-card section">
  <div class="card-head" style="padding:var(--space-4) var(--space-4) 0;">
    <h2 class="card-title">Daftar DO Pesanan Khusus / Non-Toko</h2>
    <form method="get" style="display:flex;gap:var(--space-2);align-items:flex-end;">
      <input type="hidden" name="page" value="delivery-order-khusus-non-toko">
      <div class="field"><label>Sumber</label>
        <select name="sourceType" onchange="this.form.submit()">
          <option value="">Semua</option>
          <option value="toko_khusus" <?= $sourceTypeFilter === 'toko_khusus' ? 'selected' : '' ?>>Pesanan Khusus Toko</option>
          <option value="non_toko" <?= $sourceTypeFilter === 'non_toko' ? 'selected' : '' ?>>Pesanan Non-Toko</option>
        </select>
      </div>
      <div class="field"><label>Status</label>
        <select name="status" onchange="this.form.submit()">
          <option value="">Semua</option>
          <?php foreach ($doStatusLabels as $st => $label): ?>
          <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ui_esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No. DO</th><th>Sumber</th><th>Toko/Customer</th><th>No. Pesanan</th><th>Metode</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if ($dos === []): ?>
    <tr><td colspan="7"><?= ui_empty_state('Belum ada DO Pesanan Khusus / Non-Toko', '') ?></td></tr>
    <?php else: foreach ($dos as $d): ?>
    <tr>
      <td><?= ui_esc($d['docNo']) ?></td>
      <td><span class="badge badge-neutral"><?= ui_esc($d['sourceLabel']) ?></span></td>
      <td><?= ui_esc((string) $d['dropStoreName']) ?></td>
      <td><?= ui_esc($d['orderNo']) ?></td>
      <td><?= $d['deliveryMethod'] === 'DRIVER_INTERNAL' ? '<span class="badge badge-primary">Driver Internal</span>' : '<span class="badge badge-neutral">Kurir: ' . ui_esc((string) $d['courierProvider']) . '</span>' ?></td>
      <td><span class="badge badge-<?= $doStatusColors[$d['status']] ?? 'neutral' ?>"><?= ui_esc($doStatusLabels[$d['status']] ?? $d['status']) ?></span></td>
      <td><a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=delivery-order-khusus-non-toko-detail&id=<?= (int) $d['doId'] ?>">Detail</a></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<script>
(function () {
  document.querySelectorAll('.do-create-btn').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var orderId = parseInt(btn.dataset.orderId, 10);
      var body = { orderId: orderId, factoryId: <?= (int) $uiFactoryId ?> };
      if (btn.dataset.sourceType !== 'toko_khusus') {
        var sel = document.querySelector('.do-drop-store[data-order-id="' + orderId + '"]');
        var dropStoreId = sel ? parseInt(sel.value, 10) : NaN;
        if (!dropStoreId) { Amor.toast('Pilih Drop Bakery terlebih dahulu.', 'danger'); return; }
        body.dropStoreId = dropStoreId;
      }
      btn.disabled = true;
      try {
        var data = await Amor.apiFetch('/api/special-order-do', { method: 'POST', body: body });
        window.location.href = '/api/_ui-preview/?page=delivery-order-khusus-non-toko-detail&id=' + data.doId;
      } catch (err) {
        Amor.toast(err.message, 'danger');
        btn.disabled = false;
      }
    });
  });
})();
</script>
