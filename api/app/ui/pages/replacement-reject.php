<?php

declare(strict_types=1);

use Amor\Api\Replacement\ReplacementService;

/**
 * Admin "Replacement Reject" — the disposition worklist (Reject Final /
 * Kirim Ulang) plus the full Replacement Demand traceability list
 * (migration 0016). Read-rendered server-side via the real
 * ReplacementService (no second copy of the query/business logic); every
 * mutating action goes through the same real JSON API via Amor.apiFetch,
 * same discipline as every other admin page in this app.
 *
 * Server-side authorization is enforced TWICE, deliberately: the router
 * (api/_ui-preview/index.php) already denies this page with a real HTTP
 * 403 before ui_page_head() ever runs, for ANY unauthorized role — this
 * second, redundant check exists purely as defense-in-depth in case this
 * file is ever reached through a different entry point in the future.
 * Either check alone is sufficient today; neither reject/demand data is
 * ever queried without one of them passing first.
 */

if (array_intersect(['ADMIN', 'PPIC'], $ui['roles']) === []) {
    echo ui_empty_state('Akses Ditolak', 'Anda tidak memiliki izin untuk mengakses halaman Replacement Reject.');
    return;
}

$service = new ReplacementService($pdo);
$pending = $service->pendingDisposition(null);
$demands = $service->listDemands([]);

$statusLabels = [
    'need_production' => 'Perlu Produksi',
    'ready' => 'Siap DO',
    'do_created' => 'DO Dibuat',
    'partially_shipped' => 'Sebagian Terkirim',
    'shipped' => 'Terkirim',
    'received_partial' => 'Diterima Sebagian',
    'received_good' => 'Diterima Baik',
    'completed' => 'Selesai',
];
?>
<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-3);">Tindak Lanjut Reject</h3>
  <p style="color:var(--text-faint);font-size:.85rem;margin-bottom:var(--space-3);">Reject yang sudah diverifikasi Admin tapi belum punya keputusan akhir. Setiap baris wajib diputuskan: <strong>Reject Final / Tidak Diganti</strong> atau <strong>Kirim Ulang / Ganti Produk</strong>.</p>
  <?php if ($pending === []): ?>
  <?= ui_empty_state('Tidak ada reject menunggu keputusan', 'Semua reject yang sudah diverifikasi sudah punya disposisi.') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Toko</th><th>Produk</th><th class="num">Dikirim</th><th class="num">Reject Dilaporkan</th><th>Catatan Toko</th><th style="min-width:320px;">Keputusan</th></tr></thead>
    <tbody>
    <?php foreach ($pending as $p): ?>
    <tr data-receipt-item="<?= (int) $p['receiptItemId'] ?>">
      <td><?= ui_esc((string) $p['storeName']) ?></td>
      <td><?= ui_esc((string) ($p['productName'] ?? '-')) ?></td>
      <td class="num"><?= ui_fmt_num($p['shippedQty']) ?></td>
      <td class="num"><strong style="color:var(--danger)"><?= ui_fmt_num($p['reportedRejectQty']) ?></strong></td>
      <td><?= ui_esc((string) ($p['reason'] ?? '-')) ?></td>
      <td>
        <div style="display:flex;gap:var(--space-2);align-items:center;flex-wrap:wrap;">
          <input type="number" class="input-approved-qty" style="width:90px;" step="0.01" min="0" max="<?= ui_esc((string) $p['reportedRejectQty']) ?>" value="<?= ui_esc((string) $p['reportedRejectQty']) ?>" title="Qty Reject Disetujui">
          <input type="text" class="input-reason" style="width:160px;" placeholder="Alasan (opsional)">
          <button type="button" class="btn btn-secondary btn-sm btn-reject-final">Reject Final</button>
          <button type="button" class="btn btn-primary btn-sm btn-kirim-ulang">Kirim Ulang</button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-3);">Replacement Reject — Traceability</h3>
  <?php if ($demands === []): ?>
  <?= ui_empty_state('Belum ada Replacement Demand', 'Belum ada reject yang diputuskan Kirim Ulang / Ganti Produk.') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr>
      <th>Toko</th><th>Produk</th><th class="num">Disetujui</th><th class="num">Dari FG</th><th class="num">Perlu Produksi</th>
      <th class="num">Aktual Produksi</th><th class="num">Reject Produksi</th><th class="num">FG Verified</th>
      <th>Status</th><th>DO Replacement</th><th style="min-width:280px;">Aksi</th>
    </tr></thead>
    <tbody>
    <?php foreach ($demands as $d): ?>
    <tr data-demand="<?= (int) $d['demandId'] ?>">
      <td><?= ui_esc((string) $d['storeName']) ?></td>
      <td><?= ui_esc((string) $d['productName']) ?></td>
      <td class="num"><?= ui_fmt_num($d['approvedQty']) ?></td>
      <td class="num"><?= ui_fmt_num($d['allocatedFromFg']) ?></td>
      <td class="num"><?= $d['productionNeed'] > 0.0001 ? '<strong style="color:var(--warning)">' . ui_fmt_num($d['productionNeed']) . '</strong>' : ui_fmt_num($d['productionNeed']) ?></td>
      <td class="num"><?= ui_fmt_num($d['productionAktual']) ?></td>
      <td class="num"><?= ui_fmt_num($d['productionReject']) ?></td>
      <td class="num"><?= ui_fmt_num($d['productionFgVerifiedQty']) ?></td>
      <td><?= ui_badge($statusLabels[$d['status']] ?? $d['status']) ?></td>
      <td><?= ui_esc((string) ($d['replacementDoDocNo'] ?? '-')) ?></td>
      <td>
        <div style="display:flex;gap:var(--space-2);align-items:center;flex-wrap:wrap;">
          <?php if ($d['status'] === 'need_production'): ?>
            <input type="number" class="input-aktual" style="width:75px;" step="0.01" min="0" placeholder="Aktual" title="Aktual Produksi">
            <input type="number" class="input-reject" style="width:75px;" step="0.01" min="0" placeholder="Reject" title="Reject Produksi">
            <button type="button" class="btn btn-secondary btn-sm btn-save-actual">Simpan Aktual</button>
            <input type="number" class="input-fg-verified" style="width:75px;" step="0.01" min="0" placeholder="FG Verif" title="FG Verified Qty">
            <button type="button" class="btn btn-secondary btn-sm btn-verify-fg">Verifikasi FG</button>
          <?php elseif ($d['status'] === 'ready'): ?>
            <button type="button" class="btn btn-primary btn-sm btn-create-do">Buat DO Replacement</button>
          <?php elseif ($d['replacementDoId'] !== null): ?>
            <a class="btn btn-secondary btn-sm" href="?page=replacement-do-detail&doId=<?= (int) $d['replacementDoId'] ?>">Lihat DO &rsaquo;</a>
          <?php else: ?>
            <span style="color:var(--text-faint);">-</span>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<script>
(function () {
  function rowOf(btn) { return btn.closest('tr'); }

  document.querySelectorAll('.btn-reject-final, .btn-kirim-ulang').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var tr = rowOf(btn);
      var receiptItemId = tr.dataset.receiptItem;
      var disposition = btn.classList.contains('btn-reject-final') ? 'reject_final' : 'kirim_ulang';
      var approvedQty = parseFloat(tr.querySelector('.input-approved-qty').value || '0');
      var reason = tr.querySelector('.input-reason').value || null;
      var label = disposition === 'reject_final' ? 'Reject Final / Tidak Diganti' : 'Kirim Ulang / Ganti Produk';
      var ok = await Amor.confirmModal({
        title: 'Konfirmasi Disposisi Reject',
        body: 'Tindakan ini permanen: ' + label + ' untuk qty ' + approvedQty + '. Lanjutkan?',
        confirmLabel: 'Ya, ' + label,
      });
      if (!ok) return;
      try {
        await Amor.apiFetch('/api/replacement/receipt-items/' + receiptItemId + '/disposition', {
          method: 'POST',
          body: { disposition: disposition, approvedQty: approvedQty, reason: reason },
        });
        Amor.toast('Disposisi tersimpan', 'success');
        window.location.reload();
      } catch (e) {
        Amor.toast(e.message, 'error');
      }
    });
  });

  document.querySelectorAll('.btn-save-actual').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var tr = rowOf(btn);
      var demandId = tr.dataset.demand;
      var aktual = parseFloat(tr.querySelector('.input-aktual').value || '0');
      var reject = parseFloat(tr.querySelector('.input-reject').value || '0');
      try {
        await Amor.apiFetch('/api/replacement-demands/' + demandId + '/production-actual', {
          method: 'POST',
          body: { aktualProduksi: aktual, rejectProduksi: reject },
        });
        Amor.toast('Aktual Produksi tersimpan', 'success');
        window.location.reload();
      } catch (e) {
        Amor.toast(e.message, 'error');
      }
    });
  });

  document.querySelectorAll('.btn-verify-fg').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var tr = rowOf(btn);
      var demandId = tr.dataset.demand;
      var qty = parseFloat(tr.querySelector('.input-fg-verified').value || '0');
      try {
        await Amor.apiFetch('/api/replacement-demands/' + demandId + '/verify-fg', {
          method: 'POST',
          body: { fgVerifiedQty: qty },
        });
        Amor.toast('FG Verified tersimpan', 'success');
        window.location.reload();
      } catch (e) {
        Amor.toast(e.message, 'error');
      }
    });
  });

  document.querySelectorAll('.btn-create-do').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var tr = rowOf(btn);
      var demandId = tr.dataset.demand;
      var ok = await Amor.confirmModal({
        title: 'Buat DO Replacement',
        body: 'DO Replacement terpisah akan dibuat untuk demand ini, tanpa harga.',
        confirmLabel: 'Ya, Buat DO',
      });
      if (!ok) return;
      try {
        await Amor.apiFetch('/api/replacement-demands/' + demandId + '/do', { method: 'POST', body: {} });
        Amor.toast('DO Replacement dibuat', 'success');
        window.location.reload();
      } catch (e) {
        Amor.toast(e.message, 'error');
      }
    });
  });
})();
</script>
