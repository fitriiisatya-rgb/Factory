<?php

declare(strict_types=1);

use Amor\Api\StorePortal\ReturService;

/**
 * Admin "Retur" review (migration 0017) — verify or reject a bakery's
 * Retur submission. Read-rendered server-side via the real ReturService
 * (no second query); every mutating action goes through the same real
 * JSON API via Amor.apiFetch. RETUR FINANCIAL RULE reminder for whoever
 * reads this page next: verifying here NEVER reduces any invoice — this
 * screen is a record-keeping decision only (see ReturService's own
 * docblock for why no invoice/stock code path exists at all).
 */

if (array_intersect(['ADMIN', 'PPIC'], $ui['roles']) === []) {
    echo ui_empty_state('Akses Ditolak', 'Anda tidak memiliki izin untuk mengakses halaman Retur.');
    return;
}

$service = new ReturService($pdo);
$statusFilter = (string) ($_GET['status'] ?? 'waiting_admin_verification');
if (!in_array($statusFilter, ['waiting_admin_verification', 'verified', 'rejected', ''], true)) {
    $statusFilter = 'waiting_admin_verification';
}
$rows = $service->adminList($statusFilter === '' ? null : $statusFilter);
$statusLabels = [
    'waiting_admin_verification' => 'Menunggu Verifikasi',
    'verified' => 'Terverifikasi',
    'rejected' => 'Ditolak',
];
$tabs = ['waiting_admin_verification' => 'Menunggu Verifikasi', 'verified' => 'Terverifikasi', 'rejected' => 'Ditolak', '' => 'Semua'];
?>
<div class="filter-bar">
  <?php foreach ($tabs as $key => $label): ?>
  <a class="btn btn-sm <?= $statusFilter === $key ? 'btn-primary' : 'btn-secondary' ?>" href="?page=retur-review&status=<?= urlencode($key) ?>"><?= ui_esc($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="card section">
  <?php if ($rows === []): ?>
  <?= ui_empty_state('Tidak ada pengajuan Retur', 'Belum ada pengajuan Retur pada status ini.') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No. Retur</th><th>Toko</th><th>Produk</th><th class="num">Qty</th><th>Alasan</th><th>Tanggal</th><th>Status</th><th style="min-width:260px;">Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr data-retur="<?= (int) $r['returId'] ?>">
      <td><?= ui_esc((string) $r['docNo']) ?></td>
      <td><?= ui_esc((string) $r['storeName']) ?></td>
      <td><?= ui_esc((string) $r['productName']) ?></td>
      <td class="num"><?= ui_fmt_num($r['qty']) ?></td>
      <td><?= ui_esc((string) $r['reason'] ?? '-') ?></td>
      <td><?= ui_esc((string) $r['returDate']) ?></td>
      <td><?= ui_badge($statusLabels[$r['status']] ?? $r['status']) ?></td>
      <td>
        <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;align-items:center;">
          <button type="button" class="btn btn-secondary btn-sm btn-detail">Lihat Detail</button>
          <?php if ($r['status'] === 'waiting_admin_verification'): ?>
          <button type="button" class="btn btn-primary btn-sm btn-verify">Verifikasi</button>
          <button type="button" class="btn btn-secondary btn-sm btn-reject">Tolak</button>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div id="retur-detail-modal-root"></div>

<script>
(function () {
  function rowOf(btn) { return btn.closest('tr'); }

  function showDetail(dto) {
    var root = document.getElementById('retur-detail-modal-root');
    var evidenceHtml = dto.evidence.map(function (ev) {
      return '<a href="/api/admin/retur/evidence/' + ev.evidenceId + '" rel="noopener" class="evidence-thumb" data-lightbox="image">' +
        '<img src="/api/admin/retur/evidence/' + ev.evidenceId + '" alt="Bukti foto" loading="lazy"></a>';
    }).join('');
    var backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop open';
    backdrop.innerHTML =
      '<div class="modal">' +
      '<div class="modal-title">' + dto.docNo + ' — ' + dto.storeName + '</div>' +
      '<div class="modal-summary">' +
      '<div>' + dto.productName + ' &middot; ' + dto.qty + ' pcs</div>' +
      '<div>Alasan: ' + (dto.reason || '-') + '</div>' +
      (dto.notes ? ('<div>Catatan: ' + dto.notes + '</div>') : '') +
      (dto.rejectReason ? ('<div style="color:var(--danger);">Alasan Ditolak: ' + dto.rejectReason + '</div>') : '') +
      '</div>' +
      '<div class="evidence-thumb-grid">' + evidenceHtml + '</div>' +
      '<div class="modal-actions"><button type="button" class="btn" id="retur-detail-close">Tutup</button></div>' +
      '</div>';
    root.appendChild(backdrop);
    backdrop.querySelector('#retur-detail-close').addEventListener('click', function () { backdrop.remove(); });
  }

  document.querySelectorAll('.btn-detail').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var returId = rowOf(btn).dataset.retur;
      try {
        var dto = await Amor.apiFetch('/api/admin/retur/' + returId);
        showDetail(dto);
      } catch (e) { Amor.toast(e.message, 'danger'); }
    });
  });

  document.querySelectorAll('.btn-verify').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var returId = rowOf(btn).dataset.retur;
      var ok = await Amor.confirmModal({ title: 'Verifikasi Retur', body: 'Pengajuan Retur ini akan ditandai terverifikasi. Tindakan ini TIDAK mengurangi invoice toko (beban 100% ditanggung toko).', confirmLabel: 'Ya, Verifikasi' });
      if (!ok) return;
      try {
        await Amor.apiFetch('/api/admin/retur/' + returId + '/verify', { method: 'POST', body: {} });
        Amor.toast('Retur terverifikasi', 'success');
        window.location.reload();
      } catch (e) { Amor.toast(e.message, 'danger'); }
    });
  });

  document.querySelectorAll('.btn-reject').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var returId = rowOf(btn).dataset.retur;
      var reason = prompt('Alasan penolakan (wajib diisi):');
      if (reason === null || reason.trim() === '') return;
      try {
        await Amor.apiFetch('/api/admin/retur/' + returId + '/reject', { method: 'POST', body: { reason: reason } });
        Amor.toast('Retur ditolak', 'success');
        window.location.reload();
      } catch (e) { Amor.toast(e.message, 'danger'); }
    });
  });
})();
</script>
