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
 * Photo evidence normally comes from the store (Portal). Tahap 1 (owner
 * decision Oct 2026, toko belum memakai Portal): while a shipment has no
 * receipt yet, Admin may fill it in from the signed paper surat jalan; a
 * Reject/Kurang then needs a photo of that signed surat jalan. An existing
 * receipt's evidence is never replaced from here.
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

  <?php $source = $shipment['source'] ?? ['type' => 'REGULAR_STORE_PO', 'label' => 'PO Reguler']; $isSpecial = $source['type'] !== 'REGULAR_STORE_PO'; ?>
  <div class="kpi-grid kpi-grid-5">
    <?= ui_kpi_card(['label' => 'No. DO', 'value' => (string) ($shipment['docNo'] ?? '-'), 'icon' => 'file', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Sumber', 'value' => $source['label'] . ($source['orderNo'] ?? null ? ' · ' . $source['orderNo'] : ''), 'icon' => 'file', 'color' => $isSpecial ? 'warning' : 'primary', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => $isSpecial && $source['deliveryMethod'] === 'EXTERNAL_COURIER' ? 'Kurir' : 'Driver', 'value' => (string) ($shipment['driverName'] ?? '-'), 'icon' => 'truck', 'color' => 'primary', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Factory Asal', 'value' => (string) ($shipment['factoryName'] ?? '-'), 'icon' => 'box', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Waktu Berangkat', 'value' => ui_fmt_datetime_id($shipment['shippedAt'] ?? null), 'icon' => 'chart', 'color' => 'neutral', 'detail' => true]) ?>
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

<?php
// Real-UAT finalization ask (Part K/L) — Email status is a SEPARATE
// concern from receipt/penerimaan status and is shown independently,
// never merged into one status field. Always rendered regardless of
// whether a receipt exists yet (a shipment can have a Terkirim email
// long before the store confirms anything).
$email = $shipment['email'];
$emailStatus = $email['status'] ?? null;
?>
<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-3);">Email Pengiriman</h3>
  <div class="kpi-grid kpi-grid-3">
    <?= ui_kpi_card(['label' => 'Status', 'value' => ui_email_status_label($emailStatus), 'icon' => 'mail', 'color' => $emailStatus === 'sent' ? 'success' : ($emailStatus === 'failed' ? 'danger' : 'warning'), 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Tujuan', 'value' => (string) ($email['recipientEmail'] ?? '-'), 'icon' => 'user', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Percobaan', 'value' => (string) ($email['attemptCount'] ?? 0), 'icon' => 'chart', 'color' => 'neutral', 'detail' => true]) ?>
  </div>
  <?php if ($emailStatus === 'sent'): ?>
  <p style="color:var(--text-faint);font-size:.85rem;margin-top:var(--space-3);">Pertama dikirim: <?= ui_esc(ui_fmt_datetime_id($email['sentAt'] ?? null)) ?></p>
  <?php elseif ($emailStatus === 'failed' && !empty($email['lastError'])): ?>
  <div class="alert alert-danger" style="margin-top:var(--space-3);"><b>Error:</b> <?= ui_esc((string) $email['lastError']) ?></div>
  <?php elseif ($emailStatus === 'no_email'): ?>
  <div class="alert alert-info" style="margin-top:var(--space-3);">Toko ini belum memiliki email penerimaan. Isi emailnya di Master Data &rarr; Toko, lalu klik Kirim Ulang Email.</div>
  <?php endif; ?>
  <div style="margin-top:var(--space-3);">
    <button type="button" class="btn btn-secondary btn-sm" id="btn-resend-email" data-shipment="<?= (int) $shipment['shipmentId'] ?>">Kirim Ulang Email</button>
  </div>
</div>

<?php if ($receipt === null): ?>
<?php
// Tahap 1 (toko belum memakai Portal): Admin mengisi penerimaan dari surat
// jalan kertas yang sudah ditandatangani toko, supaya invoice bisa terbit.
$sjStore = $pdo->prepare('SELECT store_id FROM shipment WHERE shipment_id = ?');
$sjStore->execute([$shipmentId]);
$sjLines = (new \Amor\Api\Dispatch\ReceiptService($pdo))->getPortalShipmentDetail((int) $sjStore->fetchColumn(), $shipmentId)['items'];
?>
<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-2);">Isi Penerimaan dari Surat Jalan</h3>
  <p style="color:var(--text-faint);font-size:.9rem;margin-bottom:var(--space-3);">Toko belum mengonfirmasi lewat Portal. Kalau surat jalan kertas sudah kembali dengan tanda tangan toko, isi di sini. Semua barang diisi "Baik" sesuai jumlah kirim — ubah hanya kalau di surat jalan tertulis reject atau kurang.</p>
  <form id="sj-confirm-form" data-shipment="<?= (int) $shipmentId ?>">
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Produk</th><th class="num">Dikirim</th><th class="num">Baik</th><th class="num">Reject</th><th class="num">Kurang</th></tr></thead>
      <tbody>
      <?php foreach ($sjLines as $l): ?>
      <tr data-line="<?= (int) $l['shipmentItemId'] ?>" data-shipped="<?= ui_esc((string) $l['shippedQty']) ?>">
        <td><?= ui_esc((string) $l['productName']) ?></td>
        <td class="num"><?= ui_fmt_num($l['shippedQty']) ?></td>
        <td class="num sj-good"><?= ui_fmt_num($l['shippedQty']) ?></td>
        <td class="num"><input type="number" class="input sj-reject" min="0" step="1" value="0" style="width:80px;"></td>
        <td class="num"><input type="number" class="input sj-short" min="0" step="1" value="0" style="width:80px;"></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="form-grid" style="margin-top:var(--space-3);display:grid;gap:var(--space-3);grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
      <label>Nama penerima di toko (yang tanda tangan)<input type="text" class="input" id="sj-receiver" required maxlength="100"></label>
      <label>Catatan (opsional)<input type="text" class="input" id="sj-note" maxlength="500"></label>
      <label>Foto surat jalan bertanda tangan <span id="sj-photo-hint" style="color:var(--text-faint);">(wajib kalau ada reject/kurang)</span><input type="file" class="input" id="sj-photo" accept="image/*" multiple></label>
    </div>
    <div style="margin-top:var(--space-3);"><button type="submit" class="btn btn-primary" id="sj-submit">Simpan Penerimaan</button></div>
  </form>
</div>
<?php else: ?>
<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-3);">Konfirmasi Toko</h3>
  <div class="kpi-grid kpi-grid-3">
    <?= ui_kpi_card(['label' => 'Nama Penerima', 'value' => (string) ($receipt['receiverName'] ?? '-'), 'icon' => 'user', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Waktu Konfirmasi', 'value' => ui_fmt_datetime_id($receipt['confirmedAt'] ?? null), 'icon' => 'chart', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Status', 'value' => ui_receipt_status_label($status), 'icon' => 'file', 'color' => $hasDiscrepancy ? 'warning' : 'success', 'detail' => true]) ?>
  </div>
  <?php if (($receipt['note'] ?? '') !== ''): ?>
  <div class="alert alert-info" style="margin-top:var(--space-3);"><b>Catatan:</b> <?= nl2br(ui_esc((string) $receipt['note'])) ?></div>
  <?php endif; ?>
  <?php if ($status === 'verified'): ?>
  <div class="alert alert-success" style="margin-top:var(--space-3);">Diverifikasi oleh <?= ui_esc((string) ($receipt['verifiedByName'] ?? '-')) ?> &middot; <?= ui_esc(ui_fmt_datetime_id($receipt['verifiedAt'] ?? null)) ?></div>
  <?php endif; ?>

  <?php if ($evidenceCount > 0): ?>
  <?php $byAdmin = str_starts_with((string) ($receipt['note'] ?? ''), 'Diisi Admin dari surat jalan'); ?>
  <h3 class="card-title" style="margin:var(--space-4) 0 var(--space-2);"><?= $byAdmin ? 'Foto Surat Jalan (diunggah Admin)' : 'Bukti Foto dari Toko' ?></h3>
  <div class="evidence-thumb-grid">
    <?php foreach ($receipt['evidence'] as $ev): ?>
    <a href="/api/admin/receipts/evidence/<?= (int) $ev['evidenceId'] ?>" rel="noopener" class="evidence-thumb" data-lightbox="image">
      <img src="/api/admin/receipts/evidence/<?= (int) $ev['evidenceId'] ?>" alt="Bukti foto" loading="lazy">
    </a>
    <?php endforeach; ?>
  </div>
  <p style="color:var(--text-faint);font-size:.8rem;margin-top:6px;"><?= $byAdmin ? 'Foto surat jalan bertanda tangan toko, diunggah Admin saat mengisi penerimaan. Tidak bisa diganti setelah disimpan.' : 'Bukti foto diunggah oleh toko saat konfirmasi penerimaan. Admin hanya dapat melihat, tidak dapat mengunggah atau mengganti bukti foto ini.' ?></p>
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

  var sjForm = document.getElementById('sj-confirm-form');
  if (sjForm) {
    var rows = Array.prototype.slice.call(sjForm.querySelectorAll('tr[data-line]'));
    function num(el) { var v = parseFloat(el.value); return isNaN(v) || v < 0 ? 0 : v; }
    function recalc() {
      var bad = false;
      rows.forEach(function (tr) {
        var shipped = parseFloat(tr.dataset.shipped), r = num(tr.querySelector('.sj-reject')), k = num(tr.querySelector('.sj-short'));
        var good = shipped - r - k;
        tr.querySelector('.sj-good').textContent = good < 0 ? 'lebih dari kirim!' : good.toLocaleString('id-ID');
        tr.querySelector('.sj-good').style.color = good < 0 ? 'var(--danger)' : '';
        if (good < 0) bad = true;
      });
      return bad;
    }
    sjForm.addEventListener('input', recalc);
    sjForm.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      if (recalc()) { Amor.toast('Reject + Kurang tidak boleh lebih dari jumlah dikirim.', 'error'); return; }
      var receiver = document.getElementById('sj-receiver').value.trim();
      if (receiver === '') { Amor.toast('Isi nama penerima di toko.', 'error'); return; }
      var items = [], selisih = 0;
      rows.forEach(function (tr) {
        var shipped = parseFloat(tr.dataset.shipped), r = num(tr.querySelector('.sj-reject')), k = num(tr.querySelector('.sj-short'));
        selisih += r + k;
        items.push({ shipmentItemId: parseInt(tr.dataset.line, 10), receivedGood: shipped - r - k, reject: r, shortage: k });
      });
      var photos = document.getElementById('sj-photo').files;
      if (selisih > 0 && photos.length === 0) { Amor.toast('Ada reject/kurang — upload foto surat jalan bertanda tangan.', 'error'); return; }
      var ok = await Amor.confirmModal({
        title: 'Simpan penerimaan?',
        body: selisih > 0 ? 'Ada selisih ' + selisih.toLocaleString('id-ID') + ' pcs. Setelah disimpan, klik Verifikasi Selisih agar invoice bisa terbit.' : 'Semua barang diterima baik. Invoice pengiriman ini bisa langsung diterbitkan.',
        confirmLabel: 'Ya, Simpan',
      });
      if (!ok) return;
      var fd = new FormData();
      fd.append('receiverName', receiver);
      fd.append('note', document.getElementById('sj-note').value);
      fd.append('items', JSON.stringify(items));
      Array.prototype.forEach.call(photos, function (f) { fd.append('evidence[]', f); });
      var btn = document.getElementById('sj-submit');
      btn.disabled = true; btn.textContent = 'Menyimpan...';
      try {
        var res = await fetch('/api/admin/shipments/' + sjForm.dataset.shipment + '/confirm', {
          method: 'POST', credentials: 'same-origin', body: fd,
          headers: { 'X-CSRF-Token': AMOR.csrfToken || '', 'Idempotency-Key': 'sj-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8) },
        });
        var json = null; try { json = await res.json(); } catch (e) {}
        if (!res.ok || !json || json.ok === false) throw new Error((json && json.message) || ('Gagal menyimpan (HTTP ' + res.status + ')'));
        Amor.toast('Penerimaan disimpan', 'success');
        window.location.reload();
      } catch (e) {
        Amor.toast(e.message, 'error');
        btn.disabled = false; btn.textContent = 'Simpan Penerimaan';
      }
    });
  }

  var resendBtn = document.getElementById('btn-resend-email');
  if (resendBtn) {
    resendBtn.addEventListener('click', async function () {
      resendBtn.disabled = true;
      resendBtn.textContent = 'Mengirim...';
      try {
        var result = await Amor.apiFetch('/api/admin/shipments/' + resendBtn.dataset.shipment + '/email/resend', { method: 'POST', body: {} });
        if (result.status === 'sent') {
          Amor.toast('Email berhasil dikirim ulang', 'success');
        } else if (result.status === 'no_email') {
          Amor.toast('Toko belum memiliki email penerimaan', 'error');
        } else {
          Amor.toast('Gagal mengirim email: ' + (result.error || '-'), 'error');
        }
        window.location.reload();
      } catch (e) {
        Amor.toast(e.message, 'error');
        resendBtn.disabled = false;
        resendBtn.textContent = 'Kirim Ulang Email';
      }
    });
  }
})();
</script>
<?php endif; ?>
