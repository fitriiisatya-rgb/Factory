<?php

declare(strict_types=1);

use Amor\Api\StorePortal\MutasiService;

/**
 * Admin "Mutasi Produk" discrepancy review (migration 0017) — the ONLY
 * next step for a Mutasi whose destination-reported qty differs from
 * what the source sent (task's own "Mismatch/damage -> DISCREPANCY ->
 * Admin Review"). Defaults to the 'discrepancy' filter since that is the
 * only status this page can act on; other statuses are read-only here
 * (Riwayat Masuk/Keluar in the Bakery Portal itself is where a store
 * reviews its own completed/cancelled history).
 */

if (array_intersect(['ADMIN', 'PPIC'], $ui['roles']) === []) {
    echo ui_empty_state('Akses Ditolak', 'Anda tidak memiliki izin untuk mengakses halaman Mutasi Produk.');
    return;
}

$service = new MutasiService($pdo);
$statusFilter = (string) ($_GET['status'] ?? 'discrepancy');
if (!in_array($statusFilter, ['waiting_destination_confirmation', 'discrepancy', 'completed', 'cancelled', ''], true)) {
    $statusFilter = 'discrepancy';
}
$rows = $service->adminList($statusFilter === '' ? null : $statusFilter);
$statusLabels = [
    'waiting_destination_confirmation' => 'Menunggu Konfirmasi Tujuan',
    'discrepancy' => 'Selisih — Menunggu Admin',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan',
];
$tabs = ['discrepancy' => 'Selisih (Perlu Keputusan)', 'waiting_destination_confirmation' => 'Menunggu Tujuan', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan', '' => 'Semua'];
?>
<div class="filter-bar">
  <?php foreach ($tabs as $key => $label): ?>
  <a class="btn btn-sm <?= $statusFilter === $key ? 'btn-primary' : 'btn-secondary' ?>" href="?page=mutasi-review&status=<?= urlencode($key) ?>"><?= ui_esc($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="card section">
  <?php if ($rows === []): ?>
  <?= ui_empty_state('Tidak ada data', 'Belum ada Mutasi pada status ini.') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No. Mutasi</th><th>Dari</th><th>Ke</th><th>Produk</th><th class="num">Dikirim</th><th class="num">Diterima</th><th>Status</th><th style="min-width:260px;">Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr data-mutasi="<?= (int) $r['mutasiId'] ?>">
      <td><?= ui_esc((string) $r['docNo']) ?></td>
      <td><?= ui_esc((string) $r['sourceStoreName']) ?></td>
      <td><?= ui_esc((string) $r['destinationStoreName']) ?></td>
      <td><?= ui_esc((string) $r['productName']) ?></td>
      <td class="num"><?= ui_fmt_num($r['qtyRequested']) ?></td>
      <td class="num"><?= $r['qtyReceived'] !== null ? ui_fmt_num($r['qtyReceived']) : '-' ?></td>
      <td><?= ui_badge($statusLabels[$r['status']] ?? $r['status']) ?></td>
      <td>
        <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;align-items:center;">
          <button type="button" class="btn btn-secondary btn-sm btn-detail">Lihat Detail</button>
          <?php if ($r['status'] === 'discrepancy'): ?>
          <button type="button" class="btn btn-primary btn-sm btn-accept">Terima Apa Adanya</button>
          <button type="button" class="btn btn-secondary btn-sm btn-cancel">Batalkan Mutasi</button>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div id="mutasi-detail-modal-root"></div>

<script>
(function () {
  function rowOf(btn) { return btn.closest('tr'); }

  function showDetail(dto) {
    var root = document.getElementById('mutasi-detail-modal-root');
    var evidenceHtml = dto.evidence.map(function (ev) {
      return '<a href="/api/admin/mutasi/evidence/' + ev.evidenceId + '" rel="noopener" class="evidence-thumb" data-lightbox="image" title="' + ev.stage + '">' +
        '<img src="/api/admin/mutasi/evidence/' + ev.evidenceId + '" alt="Bukti foto (' + ev.stage + ')" loading="lazy"></a>';
    }).join('');
    var backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop open';
    backdrop.innerHTML =
      '<div class="modal">' +
      '<div class="modal-title">' + dto.docNo + '</div>' +
      '<div class="modal-summary">' +
      '<div>' + dto.sourceStoreName + ' &rarr; ' + dto.destinationStoreName + ' &middot; ' + dto.productName + '</div>' +
      '<div>Dikirim: ' + dto.qtyRequested + ' pcs &middot; Diterima: ' + (dto.qtyReceived === null ? '-' : dto.qtyReceived) + ' pcs</div>' +
      (dto.requestNotes ? ('<div>Catatan Pengirim: ' + dto.requestNotes + '</div>') : '') +
      (dto.destinationNotes ? ('<div>Catatan Penerima: ' + dto.destinationNotes + '</div>') : '') +
      (dto.adminReviewNotes ? ('<div>Keputusan Admin: ' + dto.adminReviewNotes + '</div>') : '') +
      '</div>' +
      '<div class="evidence-thumb-grid">' + evidenceHtml + '</div>' +
      '<div class="modal-actions"><button type="button" class="btn" id="mutasi-detail-close">Tutup</button></div>' +
      '</div>';
    root.appendChild(backdrop);
    backdrop.querySelector('#mutasi-detail-close').addEventListener('click', function () { backdrop.remove(); });
  }

  document.querySelectorAll('.btn-detail').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var mutasiId = rowOf(btn).dataset.mutasi;
      try {
        var dto = await Amor.apiFetch('/api/admin/mutasi/' + mutasiId);
        showDetail(dto);
      } catch (e) { Amor.toast(e.message, 'danger'); }
    });
  });

  function review(btn, resolution, title, body) {
    btn.addEventListener('click', async function () {
      var mutasiId = rowOf(btn).dataset.mutasi;
      var notes = prompt(body + '\n\nCatatan keputusan (wajib diisi):');
      if (notes === null || notes.trim() === '') return;
      try {
        await Amor.apiFetch('/api/admin/mutasi/' + mutasiId + '/review', { method: 'POST', body: { resolution: resolution, notes: notes } });
        Amor.toast('Keputusan Mutasi tersimpan', 'success');
        window.location.reload();
      } catch (e) { Amor.toast(e.message, 'danger'); }
    });
  }
  document.querySelectorAll('.btn-accept').forEach(function (btn) {
    review(btn, 'completed', 'Terima Apa Adanya', 'Mutasi akan diselesaikan dengan jumlah yang dilaporkan toko tujuan (Qty Diterima di atas menjadi final).');
  });
  document.querySelectorAll('.btn-cancel').forEach(function (btn) {
    review(btn, 'cancelled', 'Batalkan Mutasi', 'Mutasi ini akan dibatalkan sepenuhnya — tanggung jawab produk TIDAK berpindah ke toko tujuan.');
  });
})();
</script>
