<?php

declare(strict_types=1);

use Amor\Api\Replacement\ReplacementDoService;

/**
 * Admin "DO Replacement" detail — a separate, no-price DO for one
 * Replacement Demand (migration 0016). Shipping here creates a real
 * shipment row (source_type='replacement_do') and consumes only this
 * demand's own protected FG allocation/production headroom — see
 * ReplacementDoService::ship()'s own docblock. Store receipt confirmation
 * reuses the existing shipment_receipt/shipment_receipt_item flow
 * unchanged (Konfirmasi Toko already lists any shipment, regardless of
 * source, once it has departed).
 *
 * Role matrix: identical to ReplacementController's own DO_ROLES (ADMIN/
 * PPIC/PRODUCTION) — enforced server-side, twice: the router (api/
 * _ui-preview/index.php) already denies this page with a real HTTP 403
 * before ui_page_head() ever runs; this second check is defense-in-depth
 * only, in case this file is ever reached through a different entry
 * point in the future.
 */

if (array_intersect(['ADMIN', 'PPIC', 'PRODUCTION'], $ui['roles']) === []) {
    echo ui_empty_state('Akses Ditolak', 'Anda tidak memiliki izin untuk mengakses halaman DO Replacement.');
    return;
}

$doId = isset($_GET['doId']) ? (int) $_GET['doId'] : 0;
$service = new ReplacementDoService($pdo);

$do = null;
$viewError = null;
try {
    $do = $service->getDo($doId);
} catch (\Throwable $e) {
    $viewError = $e->getMessage();
}
?>
<?php if ($viewError !== null): ?>
<div class="alert alert-danger"><?= ui_esc($viewError) ?></div>
<a class="btn btn-secondary" href="?page=replacement-reject">&larr; Kembali ke Replacement Reject</a>
<?php else: ?>
<a class="btn btn-secondary" style="margin-bottom:var(--space-3);" href="?page=replacement-reject">&larr; Kembali ke Replacement Reject</a>

<div class="card section">
  <div class="card-head">
    <div>
      <h2 class="card-title"><?= ui_esc((string) $do['docNo']) ?></h2>
      <div class="page-subtitle" style="margin-top:4px;"><?= ui_esc((string) $do['storeName']) ?> &middot; <?= ui_esc((string) $do['productName']) ?></div>
    </div>
    <?= ui_badge($do['status']) ?>
  </div>

  <div class="kpi-grid kpi-grid-3">
    <?= ui_kpi_card(['label' => 'Direncanakan', 'value' => ui_fmt_num($do['plannedQty']), 'icon' => 'box', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Terkirim', 'value' => ui_fmt_num($do['shippedQty']), 'icon' => 'truck', 'color' => 'success', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Sisa', 'value' => ui_fmt_num($do['remainingQty']), 'icon' => 'chart', 'color' => $do['remainingQty'] > 0.0001 ? 'warning' : 'neutral', 'detail' => true]) ?>
  </div>

  <p style="color:var(--text-faint);font-size:.85rem;margin-top:var(--space-3);">DO Replacement tidak memiliki harga — dokumen ini murni make-good, tidak ditagihkan ke pelanggan.</p>

  <?php if (in_array($do['status'], ['open', 'partial'], true)): ?>
  <div style="margin-top:var(--space-4);display:flex;gap:var(--space-2);align-items:center;flex-wrap:wrap;">
    <input type="number" id="ship-qty" style="width:100px;" step="0.01" min="0" max="<?= ui_esc((string) $do['remainingQty']) ?>" placeholder="Semua sisa">
    <button type="button" class="btn btn-primary" id="btn-ship">Kirim Sekarang</button>
    <span style="color:var(--text-faint);font-size:.85rem;">Kosongkan qty untuk mengirim seluruh sisa (<?= ui_fmt_num($do['remainingQty']) ?>).</span>
  </div>
  <?php else: ?>
  <div class="alert alert-success" style="margin-top:var(--space-4);">DO ini sudah terkirim penuh.</div>
  <?php endif; ?>
</div>

<script>
(function () {
  var shipBtn = document.getElementById('btn-ship');
  if (shipBtn) {
    shipBtn.addEventListener('click', async function () {
      var qtyInput = document.getElementById('ship-qty');
      var qty = qtyInput.value !== '' ? parseFloat(qtyInput.value) : null;
      var ok = await Amor.confirmModal({
        title: 'Kirim DO Replacement',
        body: 'Barang akan dianggap berangkat dari factory sekarang. Lanjutkan?',
        confirmLabel: 'Ya, Kirim',
      });
      if (!ok) return;
      try {
        await Amor.apiFetch('/api/replacement-do/<?= (int) $doId ?>/ship', {
          method: 'POST',
          body: qty !== null ? { qty: qty } : {},
        });
        Amor.toast('Pengiriman Replacement dibuat', 'success');
        window.location.reload();
      } catch (e) {
        Amor.toast(e.message, 'error');
      }
    });
  }
})();
</script>
<?php endif; ?>
