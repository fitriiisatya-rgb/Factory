<?php

declare(strict_types=1);

use Amor\Api\ApiException;
use Amor\Api\SpecialOrder\SpecialOrderDoService;

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$service = new SpecialOrderDoService($pdo);
$do = null;
$viewError = null;
try {
    $do = $service->getDo($id);
} catch (ApiException $e) {
    $viewError = $e->getMessage();
}

$doStatusLabels = ['open' => 'Open', 'partial' => 'Partial', 'shipped' => 'Shipped', 'cancelled' => 'Dibatalkan'];
$doStatusColors = ['open' => 'neutral', 'partial' => 'warning', 'shipped' => 'success', 'cancelled' => 'danger'];
$courierLabels = ['grab' => 'Grab', 'gosend' => 'GoSend', 'lalamove' => 'Lalamove', 'other' => 'Lainnya'];
$currentUserId = (int) ($ui['userId'] ?? 0);
$isAdmin = in_array('ADMIN', $ui['roles'] ?? [], true);
?>
<?php if ($viewError !== null): ?>
<div class="alert alert-danger"><?= ui_esc($viewError) ?></div>
<a class="btn btn-secondary" href="?page=delivery-order-khusus-non-toko">&larr; Kembali ke DO Pesanan Khusus / Non-Toko</a>
<?php else: ?>
<a class="btn btn-secondary" style="margin-bottom:var(--space-3);" href="?page=delivery-order-khusus-non-toko">&larr; Kembali ke DO Pesanan Khusus / Non-Toko</a>

<div class="card section">
  <div class="card-head">
    <div>
      <h2 class="card-title"><?= ui_esc($do['docNo']) ?></h2>
      <div class="page-subtitle" style="margin-top:4px;"><?= ui_normalized_source_badge($do['normalizedSourceType'], $do['sourceLabel']) ?> &middot; Pesanan <?= ui_esc($do['orderNo']) ?> &middot; Pabrik <?= ui_esc($do['factoryName']) ?></div>
    </div>
    <span class="badge badge-<?= $doStatusColors[$do['status']] ?? 'neutral' ?>"><?= ui_esc($doStatusLabels[$do['status']] ?? $do['status']) ?></span>
  </div>

  <div class="kpi-grid kpi-grid-4">
    <?= ui_kpi_card(['label' => 'Tanggal', 'value' => (string) $do['tanggal'], 'icon' => 'chart', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Drop Bakery (Tujuan Fisik)', 'value' => (string) $do['dropStoreName'], 'icon' => 'box', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Customer/Kontak', 'value' => (string) ($do['customerName'] ?? $do['dropStoreName']) . (($do['customerContact'] ?? '') !== '' ? ' · ' . $do['customerContact'] : ''), 'icon' => 'user', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Dibuat Oleh', 'value' => (string) ($do['createdByName'] ?? '-'), 'icon' => 'user', 'color' => 'neutral', 'detail' => true]) ?>
  </div>
  <?php if (($do['deliveryAddress'] ?? '') !== ''): ?>
  <div class="alert alert-info" style="margin-top:var(--space-3);"><b>Alamat/Tujuan:</b> <?= ui_esc((string) $do['deliveryAddress']) ?></div>
  <?php endif; ?>
  <?php if ($do['status'] === 'cancelled' && ($do['cancelReason'] ?? '') !== ''): ?>
  <div class="alert alert-danger" style="margin-top:var(--space-3);"><b>Dibatalkan:</b> <?= ui_esc((string) $do['cancelReason']) ?></div>
  <?php endif; ?>

  <div class="subpanel section" style="margin-top:var(--space-4);">
    <div class="subpanel-title">Metode Pengiriman</div>
    <div class="kpi-grid kpi-grid-3" style="margin-bottom:0;">
      <?= ui_kpi_card(['label' => 'Metode', 'value' => $do['deliveryMethod'] === 'DRIVER_INTERNAL' ? 'Driver Internal' : 'Kurir Eksternal', 'icon' => 'truck', 'color' => 'primary', 'detail' => true]) ?>
      <?php if ($do['deliveryMethod'] === 'EXTERNAL_COURIER'): ?>
      <?= ui_kpi_card(['label' => 'Provider', 'value' => $courierLabels[$do['courierProvider']] ?? (string) $do['courierProvider'], 'icon' => 'box', 'color' => 'neutral', 'detail' => true]) ?>
      <?= ui_kpi_card(['label' => 'No. Booking/Resi', 'value' => (string) ($do['externalOrderReference'] ?? '-'), 'icon' => 'file', 'color' => 'neutral', 'detail' => true]) ?>
      <?php else: ?>
      <?= ui_kpi_card(['label' => 'Driver', 'value' => (string) ($do['claimedByName'] ?? 'Belum diambil'), 'icon' => 'user', 'color' => $do['claimedByName'] ? 'success' : 'neutral', 'detail' => true]) ?>
      <?php endif; ?>
    </div>

    <?php if ($do['status'] === 'open' && $isAdmin): ?>
    <form id="delivery-method-form" style="margin-top:var(--space-3);display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
      <div class="field"><label>Ubah Metode</label>
        <select id="dm-method">
          <option value="DRIVER_INTERNAL" <?= $do['deliveryMethod'] === 'DRIVER_INTERNAL' ? 'selected' : '' ?>>Driver Internal</option>
          <option value="EXTERNAL_COURIER" <?= $do['deliveryMethod'] === 'EXTERNAL_COURIER' ? 'selected' : '' ?>>Kurir Eksternal</option>
        </select>
      </div>
      <div class="field" id="dm-provider-field" style="<?= $do['deliveryMethod'] === 'EXTERNAL_COURIER' ? '' : 'display:none;' ?>"><label>Provider</label>
        <select id="dm-provider">
          <option value="grab" <?= $do['courierProvider'] === 'grab' ? 'selected' : '' ?>>Grab</option>
          <option value="gosend" <?= $do['courierProvider'] === 'gosend' ? 'selected' : '' ?>>GoSend</option>
          <option value="lalamove" <?= $do['courierProvider'] === 'lalamove' ? 'selected' : '' ?>>Lalamove</option>
          <option value="other" <?= $do['courierProvider'] === 'other' ? 'selected' : '' ?>>Lainnya</option>
        </select>
      </div>
      <div class="field" id="dm-courier-name-field" style="<?= $do['deliveryMethod'] === 'EXTERNAL_COURIER' ? '' : 'display:none;' ?>"><label>Nama Kurir (opsional)</label><input type="text" id="dm-courier-name" value="<?= ui_esc((string) ($do['courierName'] ?? '')) ?>"></div>
      <div class="field" id="dm-ref-field" style="<?= $do['deliveryMethod'] === 'EXTERNAL_COURIER' ? '' : 'display:none;' ?>"><label>No. Booking/Resi</label><input type="text" id="dm-ref" value="<?= ui_esc((string) ($do['externalOrderReference'] ?? '')) ?>"></div>
      <button type="submit" class="btn btn-secondary btn-sm">Simpan Metode</button>
    </form>
    <?php endif; ?>
  </div>

  <h3 class="card-title" style="margin:var(--space-4) 0 var(--space-2);">Item DO</h3>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Item</th><th>Divisi</th><th class="num">Qty Direncanakan</th><th class="num">Sudah Dikirim</th><th class="num">Sisa</th></tr></thead>
    <tbody>
    <?php foreach ($do['items'] as $it): ?>
    <tr>
      <td><?= ui_esc($it['itemName']) ?></td>
      <td><span class="badge badge-primary"><?= ui_esc($it['divisionName']) ?></span></td>
      <td class="num"><?= ui_fmt_num($it['plannedQty']) ?></td>
      <td class="num"><?= ui_fmt_num($it['shippedQty']) ?></td>
      <td class="num"><?= ui_fmt_num($it['remainingQty']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <div style="margin-top:var(--space-4);display:flex;gap:var(--space-3);align-items:center;flex-wrap:wrap;">
    <?php if (in_array($do['status'], ['open', 'partial'], true)): ?>
      <?php if ($do['deliveryMethod'] === 'DRIVER_INTERNAL'): ?>
        <?php if ($do['claimedByUserId'] === null): ?>
        <button type="button" class="btn btn-primary" id="btn-claim-do" data-id="<?= (int) $do['doId'] ?>">Ambil (Claim)</button>
        <?php elseif ($do['claimedByUserId'] === $currentUserId): ?>
        <button type="button" class="btn btn-primary" id="btn-depart-do" data-id="<?= (int) $do['doId'] ?>">Konfirmasi Berangkat</button>
        <button type="button" class="btn btn-secondary" id="btn-release-do" data-id="<?= (int) $do['doId'] ?>">Lepas Klaim</button>
        <?php else: ?>
        <span style="color:var(--text-faint);">Sudah diambil oleh <?= ui_esc((string) $do['claimedByName']) ?>.</span>
        <?php endif; ?>
      <?php else: ?>
        <?php if ($isAdmin): ?>
        <button type="button" class="btn btn-primary" id="btn-courier-handover" data-id="<?= (int) $do['doId'] ?>">Barang Diserahkan ke Kurir</button>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($do['status'] === 'open' && $isAdmin): ?>
      <button type="button" class="btn btn-secondary" id="btn-cancel-do" data-id="<?= (int) $do['doId'] ?>" data-version="<?= (int) $do['version'] ?>">Batalkan</button>
      <?php endif; ?>
    <?php else: ?>
      <span style="color:var(--text-faint);">DO ini sudah <?= mb_strtolower($doStatusLabels[$do['status']] ?? $do['status']) ?> — tidak ada tindakan lagi.</span>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  async function withReload(fn) {
    try { await fn(); window.location.reload(); } catch (e) { Amor.toast(e.message, 'danger'); }
  }

  var dmForm = document.getElementById('delivery-method-form');
  if (dmForm) {
    var methodSel = document.getElementById('dm-method');
    function toggleCourierFields() {
      var show = methodSel.value === 'EXTERNAL_COURIER';
      ['dm-provider-field', 'dm-courier-name-field', 'dm-ref-field'].forEach(function (id) {
        document.getElementById(id).style.display = show ? '' : 'none';
      });
    }
    methodSel.addEventListener('change', toggleCourierFields);
    dmForm.addEventListener('submit', function (e) {
      e.preventDefault();
      withReload(function () {
        return Amor.apiFetch('/api/special-order-do/<?= (int) $do['doId'] ?>/delivery-method', {
          method: 'POST',
          body: {
            expectedVersion: <?= (int) $do['version'] ?>,
            deliveryMethod: methodSel.value,
            courierProvider: methodSel.value === 'EXTERNAL_COURIER' ? document.getElementById('dm-provider').value : null,
            courierName: methodSel.value === 'EXTERNAL_COURIER' ? document.getElementById('dm-courier-name').value || null : null,
            externalOrderReference: methodSel.value === 'EXTERNAL_COURIER' ? document.getElementById('dm-ref').value || null : null,
          },
        });
      });
    });
  }

  var claimBtn = document.getElementById('btn-claim-do');
  if (claimBtn) {
    claimBtn.addEventListener('click', function () {
      withReload(function () { return Amor.apiFetch('/api/special-order-do/' + claimBtn.dataset.id + '/claim', { method: 'POST', body: {} }); });
    });
  }
  var releaseBtn = document.getElementById('btn-release-do');
  if (releaseBtn) {
    releaseBtn.addEventListener('click', function () {
      withReload(function () { return Amor.apiFetch('/api/special-order-do/' + releaseBtn.dataset.id + '/release', { method: 'POST', body: {} }); });
    });
  }
  var departBtn = document.getElementById('btn-depart-do');
  if (departBtn) {
    departBtn.addEventListener('click', async function () {
      var ok = await Amor.confirmModal({ title: 'Konfirmasi Berangkat', body: 'Seluruh sisa qty pada DO ini akan dikirim sekarang dan mengurangi FG tersedia. Lanjutkan?' });
      if (!ok) return;
      withReload(function () { return Amor.apiFetch('/api/special-order-do/' + departBtn.dataset.id + '/depart', { method: 'POST', body: { items: null } }); });
    });
  }
  var courierBtn = document.getElementById('btn-courier-handover');
  if (courierBtn) {
    courierBtn.addEventListener('click', async function () {
      var ok = await Amor.confirmModal({ title: 'Barang Diserahkan ke Kurir', body: 'Seluruh sisa qty pada DO ini akan ditandai diserahkan ke kurir sekarang dan mengurangi FG tersedia. Lanjutkan?' });
      if (!ok) return;
      withReload(function () { return Amor.apiFetch('/api/special-order-do/' + courierBtn.dataset.id + '/courier-handover', { method: 'POST', body: { items: null } }); });
    });
  }
  var cancelBtn = document.getElementById('btn-cancel-do');
  if (cancelBtn) {
    cancelBtn.addEventListener('click', async function () {
      var ok = await Amor.confirmModal({ title: 'Batalkan DO', body: 'Tindakan ini tidak dapat dibatalkan. Masukkan alasan pembatalan pada dialog berikutnya.', confirmLabel: 'Ya, Batalkan', danger: true });
      if (!ok) return;
      var reason = prompt('Alasan pembatalan:');
      if (!reason) return;
      withReload(function () {
        return Amor.apiFetch('/api/special-order-do/' + cancelBtn.dataset.id + '/cancel', { method: 'POST', body: { expectedVersion: parseInt(cancelBtn.dataset.version, 10), reason: reason } });
      });
    });
  }
})();
</script>
<?php endif; ?>
