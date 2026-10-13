<?php

declare(strict_types=1);

use Amor\Api\StorePortal\ReturService;

/**
 * Admin "Reject di Toko" (migration 0020) — goods that spoiled in the
 * store BEFORE their expiry date (mold, stale…), reported later from the
 * Portal's "Lapor Barang" menu. FACTORY burden, unlike a Retur:
 *   - Potong invoice: a Nota Potongan (qty x harga) is issued against the
 *     invoice of the DO that delivered the goods.
 *   - Ganti barang: the same nota, plus a Pesanan Khusus Toko replacement
 *     (billed normally once received).
 * Every mutation goes through the real JSON API via Amor.apiFetch.
 */

if (array_intersect(['ADMIN', 'PPIC'], $ui['roles']) === []) {
    echo ui_empty_state('Akses Ditolak', 'Anda tidak memiliki izin untuk mengakses halaman Reject di Toko.');
    return;
}

$service = new ReturService($pdo);
$statusFilter = (string) ($_GET['status'] ?? 'waiting_admin_verification');
if (!in_array($statusFilter, ['waiting_admin_verification', 'verified', 'rejected', ''], true)) {
    $statusFilter = 'waiting_admin_verification';
}
$rows = $service->adminList($statusFilter === '' ? null : $statusFilter, 'reject_toko');
$allRows = $service->adminList(null, 'reject_toko');
$waiting = 0;
$pendingDeduct = 0.0;
$deducted = 0.0;
foreach ($allRows as $r) {
    if ($r['status'] === 'waiting_admin_verification') {
        $waiting++;
    }
}
$detail = [];
foreach ($rows as $r) {
    $detail[$r['returId']] = $service->getForAdmin((int) $r['returId']);
}
foreach ($allRows as $r) {
    if ($r['status'] !== 'verified') {
        continue;
    }
    $d = $detail[$r['returId']] ?? $service->getForAdmin((int) $r['returId']);
    if ($d['notaId'] === null) {
        $pendingDeduct += (float) $d['qty'] * (float) ($d['harga'] ?? 0);
    } else {
        $deducted += (float) $d['notaTotal'];
    }
}
$tabs = ['waiting_admin_verification' => 'Menunggu Keputusan', 'verified' => 'Sudah Diproses', 'rejected' => 'Ditolak', '' => 'Semua'];
$tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
?>
<div class="alert alert-danger" style="display:flex;gap:var(--space-3);align-items:flex-start;">
  <span style="font-size:1.6rem;line-height:1;">⚠️</span>
  <div><strong>Reject di Toko = tanggungan pabrik.</strong> Barang rusak/berjamur <em>sebelum</em> tanggal expired. Setelah disetujui, otomatis terbit
  <strong>Nota Potongan</strong> atas invoice DO yang mengirim barang itu (invoice tidak diubah). Pilih <strong>Ganti barang</strong> bila toko perlu kiriman pengganti (pengganti ditagih normal).
  Barang tidak laku / lewat expired adalah <a href="/api/_ui-preview/?page=retur-review">Retur</a> (hanya dicatat, tidak memotong invoice).</div>
</div>

<div class="kpi-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));">
  <?= ui_kpi_card(['label' => 'Menunggu Keputusan', 'value' => (string) $waiting, 'icon' => 'bolt', 'color' => $waiting > 0 ? 'warning' : 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'Nota Potongan Terbit', 'value' => ui_fmt_money($deducted), 'icon' => 'file', 'color' => 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'Disetujui, Nota Belum Terbit', 'value' => ui_fmt_money($pendingDeduct), 'icon' => 'box', 'color' => $pendingDeduct > 0 ? 'warning' : 'neutral']) ?>
</div>

<div class="filter-bar">
  <?php foreach ($tabs as $key => $label): ?>
  <a class="btn btn-sm <?= $statusFilter === $key ? 'btn-primary' : 'btn-secondary' ?>" href="?page=reject-toko&status=<?= urlencode($key) ?>"><?= ui_esc($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="card section">
  <?php if ($rows === []): ?>
  <?= ui_empty_state('Tidak ada laporan Reject di Toko', 'Belum ada laporan pada status ini.') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>No.</th><th>Toko</th><th>Produk</th><th class="num">Qty</th><th>Kondisi</th><th>Exp</th><th>Dilaporkan</th><th class="num">Nilai</th><th>Status</th><th style="min-width:240px;">Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $d = $detail[$r['returId']]; $nilai = (float) $d['qty'] * (float) ($d['harga'] ?? 0); ?>
    <tr data-retur="<?= (int) $r['returId'] ?>" data-doc="<?= ui_esc($d['docNo']) ?>" data-label="<?= ui_esc($d['productName'] . ' · ' . ui_fmt_num($d['qty']) . ' pcs · ' . $d['storeName']) ?>" data-nilai="<?= ui_esc(ui_fmt_money($nilai)) ?>">
      <td><?= ui_esc($d['docNo']) ?></td>
      <td><?= ui_esc((string) $d['storeName']) ?></td>
      <td><?= ui_esc($d['productName']) ?></td>
      <td class="num"><strong style="color:var(--danger)"><?= ui_fmt_num($d['qty']) ?></strong></td>
      <td><?= ui_esc((string) ($d['kondisiLabel'] ?? '-')) ?></td>
      <td><?= ui_esc((string) ($d['expDate'] ?? '-')) ?></td>
      <td><?= ui_esc((string) $d['returDate']) ?></td>
      <td class="num"><?= ui_fmt_money($nilai) ?><?= ((float) ($d['harga'] ?? 0)) <= 0 ? ' <span title="Harga produk Rp0 di Master Data" style="color:var(--warning)">⚠</span>' : '' ?></td>
      <td>
        <?php if ($d['status'] === 'waiting_admin_verification'): ?>
          <?= ui_badge('Menunggu Keputusan') ?>
        <?php elseif ($d['status'] === 'rejected'): ?>
          <?= ui_badge('Ditolak') ?><div style="font-size:.8rem;color:var(--text-faint);"><?= ui_esc((string) $d['rejectReason']) ?></div>
        <?php else: ?>
          <?= ui_badge($d['tindakLanjut'] === 'ganti' ? 'Ganti barang' : 'Potong invoice') ?>
          <div style="font-size:.8rem;color:var(--text-faint);">
            <?php if ($d['notaId'] !== null): ?>
              Nota <a target="_blank" href="/api/_ui-preview/print-nota.php?notaId=<?= (int) $d['notaId'] ?>"><?= ui_esc((string) $d['notaNo']) ?></a> &minus;<?= ui_fmt_money((float) $d['notaTotal']) ?>
              <br>atas <?= $d['invoiceNo'] !== null ? ui_esc((string) $d['invoiceNo']) : 'invoice DO-nya (menyusul)' ?>
            <?php else: ?>
              Nota belum terbit <button type="button" class="btn btn-secondary btn-sm btn-issue-nota" style="margin-top:4px;">Terbitkan Nota</button>
            <?php endif; ?>
            <?php if ($d['penggantiOrderId'] !== null): ?><br>Pengganti: <a href="/api/_ui-preview/?page=pesanan-khusus-toko-detail&id=<?= (int) $d['penggantiOrderId'] ?>"><?= ui_esc((string) $d['penggantiOrderNo']) ?></a><?php endif; ?>
          </div>
        <?php endif; ?>
      </td>
      <td>
        <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;align-items:center;">
          <button type="button" class="btn btn-secondary btn-sm btn-detail">Foto &amp; Detail</button>
          <?php if ($d['status'] === 'waiting_admin_verification'): ?>
          <button type="button" class="btn btn-danger btn-sm btn-decide">Setujui Reject</button>
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
  var TOMORROW = <?= json_encode($tomorrow) ?>;
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
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
      '<div class="modal-title">⚠️ ' + esc(dto.docNo) + ' — ' + esc(dto.storeName) + '</div>' +
      '<div class="modal-summary">' +
      '<div>' + esc(dto.productName) + ' &middot; ' + esc(dto.qty) + ' pcs</div>' +
      '<div>Kondisi: ' + esc(dto.kondisiLabel || '-') + ' &middot; Exp: ' + esc(dto.expDate || '-') + ' &middot; Dilaporkan: ' + esc(dto.returDate) + '</div>' +
      (dto.notes ? ('<div>Catatan toko: ' + esc(dto.notes) + '</div>') : '') +
      (dto.rejectReason ? ('<div style="color:var(--danger);">Alasan ditolak: ' + esc(dto.rejectReason) + '</div>') : '') +
      '</div>' +
      '<div class="evidence-thumb-grid">' + evidenceHtml + '</div>' +
      '<div class="modal-actions"><button type="button" class="btn" id="retur-detail-close">Tutup</button></div>' +
      '</div>';
    root.appendChild(backdrop);
    backdrop.querySelector('#retur-detail-close').addEventListener('click', function () { backdrop.remove(); });
  }

  document.querySelectorAll('.btn-detail').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      try { showDetail(await Amor.apiFetch('/api/admin/retur/' + rowOf(btn).dataset.retur)); }
      catch (e) { Amor.toast(e.message, 'danger'); }
    });
  });

  document.querySelectorAll('.btn-decide').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var row = rowOf(btn);
      var html =
        '<p style="margin:0 0 var(--space-3);"><strong>' + esc(row.dataset.label) + '</strong><br>Nilai: ' + esc(row.dataset.nilai) + '</p>' +
        '<label class="rt-choice"><input type="radio" name="rt-tl" value="potong" checked> <span><strong>Potong invoice</strong><br><small>Terbit Nota Potongan atas invoice DO-nya. Tidak ada kiriman pengganti.</small></span></label>' +
        '<label class="rt-choice"><input type="radio" name="rt-tl" value="ganti"> <span><strong>Ganti barang</strong><br><small>Tetap terbit Nota Potongan, dan dibuat Pesanan Khusus Toko pengganti (ditagih normal setelah diterima). Bisa diproduksi atau diambil dari Stok Sisa.</small></span></label>' +
        '<div class="field" id="rt-date-wrap" style="display:none;margin-top:var(--space-3);"><label>Tanggal kirim pengganti</label><input type="date" id="rt-date" value="' + TOMORROW + '"></div>';
      var result = null;
      var ok = await Amor.confirmModal({
        title: 'Setujui Reject di Toko', bodyHtml: html, confirmLabel: 'Setujui', danger: true,
        onConfirm: async function () {
          var tl = (document.querySelector('input[name="rt-tl"]:checked') || {}).value || 'potong';
          var date = (document.getElementById('rt-date') || {}).value || TOMORROW;
          result = await Amor.apiFetch('/api/admin/retur/' + row.dataset.retur + '/verify', { method: 'POST', body: { tindakLanjut: tl, requiredDate: date } });
        },
      });
      if (!ok || !result) return;
      if (result.penggantiOrderId) {
        Amor.toast('Disetujui. Pesanan pengganti ' + result.penggantiOrderNo + ' dibuat.', 'success');
        setTimeout(function () { location.href = '/api/_ui-preview/?page=pesanan-khusus-toko-detail&id=' + result.penggantiOrderId; }, 800);
      } else {
        Amor.toast('Disetujui. Nota potongan diterbitkan.', 'success');
        setTimeout(function () { location.reload(); }, 600);
      }
    });
  });
  document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'rt-tl') {
      var w = document.getElementById('rt-date-wrap');
      if (w) w.style.display = e.target.value === 'ganti' ? '' : 'none';
    }
  });

  document.querySelectorAll('.btn-issue-nota').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      btn.disabled = true;
      try {
        await Amor.apiFetch('/api/admin/retur/' + rowOf(btn).dataset.retur + '/nota', { method: 'POST', body: {} });
        Amor.toast('Nota potongan diterbitkan.', 'success');
        location.reload();
      } catch (e) { Amor.toast(e.message, 'danger'); btn.disabled = false; }
    });
  });

  document.querySelectorAll('.btn-reject').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var reason = prompt('Alasan penolakan (wajib diisi), mis. "foto tidak jelas" atau "sudah lewat expired, ini Retur":');
      if (reason === null || reason.trim() === '') return;
      try {
        await Amor.apiFetch('/api/admin/retur/' + rowOf(btn).dataset.retur + '/reject', { method: 'POST', body: { reason: reason } });
        Amor.toast('Laporan ditolak', 'success');
        location.reload();
      } catch (e) { Amor.toast(e.message, 'danger'); }
    });
  });
})();
</script>
<style>
.rt-choice { display:flex; gap:var(--space-3); align-items:flex-start; padding:var(--space-3); border:1px solid var(--border); border-radius:10px; margin-bottom:var(--space-2); cursor:pointer; }
.rt-choice input { margin-top:4px; }
.rt-choice small { color:var(--text-faint); }
</style>
