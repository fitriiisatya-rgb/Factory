<?php

declare(strict_types=1);

/**
 * DO Pesanan Khusus Toko / Pesanan Non-Toko — the SEPARATE, source-
 * specific DO (task's own approved rule: "DIFFERENT DEMAND SOURCES MUST
 * HAVE SEPARATE DO... DO NOT MERGE DIFFERENT SOURCE TYPES INTO ONE DO").
 * Reads special_order_do (migration 0012) — a dedicated table, never
 * delivery_order/delivery_order_item (Regular PO's own DO, completely
 * untouched — see delivery-order.php).
 */

use Amor\Api\SpecialOrder\SpecialOrderDoService;
use Amor\Api\SpecialOrder\SpecialOrderService;

$orderService = new SpecialOrderService($pdo);
$doService = new SpecialOrderDoService($pdo);

// Orders with at least one FG-verified item, grouped — "ready to generate a DO" list.
$fgItems = $orderService->fgEligibleItems(['factoryId' => $uiFactoryId]);
$ordersReady = [];
foreach ($fgItems as $it) {
    if ($it['fgVerifiedQty'] <= 0.0001) {
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

$statusFilter = (string) ($_GET['status'] ?? '');
$sourceTypeFilter = (string) ($_GET['sourceType'] ?? '');
$dos = $doService->listDos(array_filter([
    'status' => $statusFilter !== '' ? $statusFilter : null,
    'sourceType' => $sourceTypeFilter !== '' ? $sourceTypeFilter : null,
]));
?>
<?= ui_do_tabs('delivery-order-khusus-non-toko', $uiTanggal, $uiFactoryId) ?>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Pesanan Siap Dibuat DO</h2></div>
  <p style="color:var(--text-muted);font-size:var(--text-sm);margin:0 0 var(--space-3);">Pesanan dengan item yang sudah terverifikasi FG. Membuat DO untuk pesanan yang sudah memiliki DO terbuka akan mengarah ke DO yang sama (tidak membuat duplikat).</p>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No. Pesanan</th><th>Sumber</th><th>Toko/Customer</th><th class="num">Item Siap</th><th></th></tr></thead>
    <tbody>
    <?php if ($ordersReady === []): ?>
    <tr><td colspan="5"><?= ui_empty_state('Belum ada pesanan dengan item terverifikasi FG', 'Verifikasi FG dahulu di tab FG Sumber Khusus / Non-Toko.') ?></td></tr>
    <?php else: foreach ($ordersReady as $o): ?>
    <tr>
      <td><?= ui_esc($o['orderNo']) ?></td>
      <td><span class="badge badge-neutral"><?= ui_esc($o['sourceLabel']) ?></span></td>
      <td><?= ui_esc((string) $o['storeOrCustomerName']) ?></td>
      <td class="num"><?= (int) $o['itemCount'] ?></td>
      <td><button type="button" class="btn btn-primary btn-sm do-create-btn" data-order-id="<?= (int) $o['orderId'] ?>">Buat DO</button></td>
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
          <?php foreach (['draft', 'ready', 'shipped', 'cancelled'] as $st): ?>
          <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ui_esc(ucfirst($st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No. DO</th><th>Sumber</th><th>Toko/Customer</th><th>No. Pesanan</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if ($dos === []): ?>
    <tr><td colspan="6"><?= ui_empty_state('Belum ada DO Pesanan Khusus / Non-Toko', '') ?></td></tr>
    <?php else: foreach ($dos as $d): ?>
    <tr>
      <td><?= ui_esc($d['docNo']) ?></td>
      <td><span class="badge badge-neutral"><?= ui_esc($d['sourceLabel']) ?></span></td>
      <td><?= ui_esc((string) $d['storeOrCustomerName']) ?></td>
      <td><?= ui_esc($d['orderNo']) ?></td>
      <td><?= ui_badge(ucfirst($d['status'])) ?></td>
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
      btn.disabled = true;
      try {
        var data = await Amor.apiFetch('/api/special-order-do', { method: 'POST', body: { orderId: parseInt(btn.dataset.orderId, 10) } });
        window.location.href = '/api/_ui-preview/?page=delivery-order-khusus-non-toko-detail&id=' + data.doId;
      } catch (err) {
        Amor.toast(err.message, 'danger');
        btn.disabled = false;
      }
    });
  });
})();
</script>
