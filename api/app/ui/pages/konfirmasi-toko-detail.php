<?php

declare(strict_types=1);

use Amor\Api\Dispatch\DispatchService;

/**
 * Admin "Konfirmasi Toko" Detail — real-UAT ask: the summary list had no
 * way to see one shipment's full receipt (items/receiver/note/evidence)
 * or verify a discrepancy from a proper detail screen. Keyed by
 * shipmentId (never a bare DO id — one DO can hold several shipments,
 * each with its own separate receipt), reusing the SAME
 * DispatchService::shipmentDetail() the Driver's own Detail Pengiriman
 * page and the Surat Jalan print page already call (isAdmin=true here
 * bypasses its shipped_by-only check, exactly like those pages) — no
 * second query/DTO for the same data.
 *
 * Role correction (real cPanel UAT): photo evidence is STORE evidence —
 * only the Store Receipt portal ever uploads it. This page is
 * view/verify-only: it shows whatever the store uploaded (or "Tidak
 * tersedia" when a legacy pre-patch receipt has none) and never offers
 * an Admin-side upload control. See ReceiptController::adminEvidence()'s
 * own docblock for why the old Admin-upload endpoint was removed rather
 * than just hidden.
 */

$shipmentId = isset($_GET['shipmentId']) ? (int) $_GET['shipmentId'] : 0;
$service = new DispatchService($pdo);

$shipment = null;
$viewError = null;
try {
    $shipment = $service->shipmentDetail($shipmentId, (int) $ui['userId'], true);
} catch (\Throwable $e) {
    $viewError = $e->getMessage();
}
?>
<?php if ($viewError !== null): ?>
<div class="alert alert-danger"><?= ui_esc($viewError) ?></div>
<a class="btn btn-secondary" href="?page=konfirmasi-toko">&larr; Kembali ke Konfirmasi Toko</a>
<?php else: ?>
<?php
$receipt = $shipment['receipt'];
$status = $receipt !== null ? $receipt['status'] : 'belum_dikonfirmasi';
$hasDiscrepancy = false;
if ($receipt !== null) {
    foreach ($receipt['items'] as $it) {
        if ($it['rejectQty'] > 0.0001 || $it['shortageQty'] > 0.0001) {
            $hasDiscrepancy = true;
            break;
        }
    }
}
$evidenceCount = $receipt !== null ? count($receipt['evidence']) : 0;
$canVerify = $receipt !== null && in_array($status, ['confirmed_ok', 'confirmed_discrepancy'], true);
$verifyBlockedByEvidence = $canVerify && $hasDiscrepancy && $evidenceCount === 0;
?>
<a class="btn btn-secondary" style="margin-bottom:var(--space-3);" href="?page=konfirmasi-toko">&larr; Kembali ke Konfirmasi Toko</a>

<div class="card section">
  <div class="card-head">
    <div>
      <h2 class="card-title">SHP-<?= (int) $shipment['shipmentId'] ?> &middot; <?= ui_esc((string) $shipment['shipmentGroup']) ?></h2>
      <div class="page-subtitle" style="margin-top:4px;"><?= ui_esc((string) $shipment['storeName']) ?></div>
    </div>
    <?= ui_badge(ui_receipt_status_label($status)) ?>
  </div>

  <div class="kpi-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));">
    <?= ui_kpi_card(['label' => 'No. DO', 'value' => (string) ($shipment['docNo'] ?? '-'), 'icon' => 'file', 'color' => 'neutral']) ?>
    <?= ui_kpi_card(['label' => 'Driver', 'value' => (string) ($shipment['driverName'] ?? '-'), 'icon' => 'truck', 'color' => 'primary']) ?>
    <?= ui_kpi_card(['label' => 'Factory Asal', 'value' => (string) ($shipment['factoryName'] ?? '-'), 'icon' => 'box', 'color' => 'neutral']) ?>
    <?= ui_kpi_card(['label' => 'Waktu Berangkat', 'value' => ui_fmt_datetime_id($shipment['shippedAt'] ?? null), 'icon' => 'chart', 'color' => 'neutral']) ?>
  </div>

  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Produk</th><th>Divisi</th><th class="num">Dikirim</th>
      <?php if ($receipt !== null): ?><th class="num">Baik</th><th class="num">Reject</th><th class="num">Kurang</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php if ($receipt !== null): ?>
      <?php foreach ($receipt['items'] as $it): ?>
      <tr>
        <td><?= ui_esc((string) $it['productName']) ?></td>
        <td>-</td>
        <td class="num"><?= ui_fmt_num($it['shippedQty']) ?></td>
        <td class="num"><?= ui_fmt_num($it['receivedGoodQty']) ?></td>
        <td class="num"><?= $it['rejectQty'] > 0.0001 ? '<strong style="color:var(--danger)">' . ui_fmt_num($it['rejectQty']) . '</strong>' : ui_fmt_num($it['rejectQty']) ?></td>
        <td class="num"><?= $it['shortageQty'] > 0.0001 ? '<strong style="color:var(--danger)">' . ui_fmt_num($it['shortageQty']) . '</strong>' : ui_fmt_num($it['shortageQty']) ?></td>
      </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <?php foreach ($shipment['items'] as $it): ?>
      <tr>
        <td><?= ui_esc((string) $it['productName']) ?></td>
        <td><?= ui_esc((string) ($it['divisionName'] ?? '-')) ?></td>
        <td class="num"><?= ui_fmt_num($it['qty']) ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php if ($receipt === null): ?>
<div class="card section">
  <?= ui_empty_state('Belum Dikonfirmasi', 'Toko belum mengonfirmasi penerimaan pengiriman ini.') ?>
</div>
<?php else: ?>
<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-3);">Konfirmasi Toko</h3>
  <div class="kpi-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));">
    <?= ui_kpi_card(['label' => 'Nama Penerima', 'value' => (string) ($receipt['receiverName'] ?? '-'), 'icon' => 'user', 'color' => 'neutral']) ?>
    <?= ui_kpi_card(['label' => 'Waktu Konfirmasi', 'value' => ui_fmt_datetime_id($receipt['confirmedAt'] ?? null), 'icon' => 'chart', 'color' => 'neutral']) ?>
    <?= ui_kpi_card(['label' => 'Status', 'value' => ui_receipt_status_label($status), 'icon' => 'file', 'color' => $hasDiscrepancy ? 'warning' : 'success']) ?>
  </div>
  <?php if (($receipt['note'] ?? '') !== ''): ?>
  <div class="alert alert-info" style="margin-top:var(--space-3);"><b>Catatan:</b> <?= nl2br(ui_esc((string) $receipt['note'])) ?></div>
  <?php endif; ?>
  <?php if ($status === 'verified'): ?>
  <div class="alert alert-success" style="margin-top:var(--space-3);">Diverifikasi oleh <?= ui_esc((string) ($receipt['verifiedByName'] ?? '-')) ?> &middot; <?= ui_esc(ui_fmt_datetime_id($receipt['verifiedAt'] ?? null)) ?></div>
  <?php endif; ?>

  <?php if ($evidenceCount > 0): ?>
  <h3 class="card-title" style="margin:var(--space-4) 0 var(--space-2);">Bukti Foto dari Toko</h3>
  <div class="evidence-thumb-grid">
    <?php foreach ($receipt['evidence'] as $ev): ?>
    <a href="/api/admin/receipts/evidence/<?= (int) $ev['evidenceId'] ?>" target="_blank" rel="noopener" class="evidence-thumb">
      <img src="/api/admin/receipts/evidence/<?= (int) $ev['evidenceId'] ?>" alt="Bukti foto" loading="lazy">
    </a>
    <?php endforeach; ?>
  </div>
  <p style="color:var(--text-faint);font-size:.8rem;margin-top:6px;">Bukti foto diunggah oleh toko saat konfirmasi penerimaan. Admin hanya dapat melihat, tidak dapat mengunggah atau mengganti bukti foto ini.</p>
  <?php else: ?>
  <h3 class="card-title" style="margin:var(--space-4) 0 var(--space-2);">Bukti Foto:</h3>
  <p style="color:var(--text-faint);">
    Tidak tersedia<?= $hasDiscrepancy ? ' — konfirmasi dibuat sebelum aturan bukti foto diberlakukan.' : '.' ?>
  </p>
  <?php endif; ?>

  <div style="margin-top:var(--space-4);display:flex;gap:var(--space-3);align-items:center;flex-wrap:wrap;">
    <?php if ($status === 'verified'): ?>
      <span style="color:var(--text-faint);">Konfirmasi ini sudah diverifikasi — tidak perlu tindakan lagi.</span>
    <?php elseif ($hasDiscrepancy): ?>
      <button type="button" class="btn btn-primary" id="btn-verify-receipt" data-receipt="<?= (int) $receipt['receiptId'] ?>" <?= $verifyBlockedByEvidence ? 'disabled' : '' ?>>Verifikasi Selisih</button>
      <?php if ($verifyBlockedByEvidence): ?>
      <span style="color:var(--danger);font-size:.85rem;">Selisih belum dapat diverifikasi karena bukti foto dari toko belum tersedia.</span>
      <?php endif; ?>
    <?php else: ?>
      <span style="color:var(--text-faint);">Penerimaan sesuai — verifikasi tidak wajib, tapi Admin boleh menandainya diverifikasi.</span>
      <button type="button" class="btn btn-secondary" id="btn-verify-receipt" data-receipt="<?= (int) $receipt['receiptId'] ?>">Verifikasi</button>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var verifyBtn = document.getElementById('btn-verify-receipt');
  if (verifyBtn) {
    verifyBtn.addEventListener('click', async function () {
      const ok = await Amor.confirmModal({
        title: 'Verifikasi Konfirmasi Toko',
        body: 'Pastikan data penerimaan, selisih, catatan, dan bukti foto sudah diperiksa.',
        confirmLabel: 'Ya, Verifikasi',
      });
      if (!ok) return;
      try {
        await Amor.apiFetch('/api/admin/receipts/' + verifyBtn.dataset.receipt + '/verify', { method: 'POST', body: {} });
        Amor.toast('Konfirmasi diverifikasi', 'success');
        window.location.reload();
      } catch (e) {
        Amor.toast(e.message, 'error');
      }
    });
  }
})();
</script>
<?php endif; ?>
