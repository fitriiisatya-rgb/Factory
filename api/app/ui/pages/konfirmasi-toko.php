<?php

declare(strict_types=1);

use Amor\Api\Dispatch\ReceiptService;

/**
 * Admin "Konfirmasi Toko" — Phase 5.5 Part I. Read-rendered server-side via
 * the real ReceiptService (same discipline as every other page: no second
 * copy of the query/business logic); the one mutating action (Verifikasi)
 * goes through the real JSON API via Amor.apiFetch, same as every other
 * admin action in this app.
 */

$service = new ReceiptService($pdo);
$statusFilter = (string) ($_GET['status'] ?? '');
$rows = $service->adminList($uiTanggal, $statusFilter !== '' ? $statusFilter : null);

$statusOptions = [
    '' => 'Semua Status',
    'belum_dikonfirmasi' => 'Belum Dikonfirmasi',
    'confirmed_ok' => 'Diterima Sesuai',
    'confirmed_discrepancy' => 'Ada Selisih',
    'verified' => 'Diverifikasi Admin',
];
?>
<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="konfirmasi-toko">
    <div class="field"><label>Tanggal</label><input type="date" name="tanggal" value="<?= ui_esc($uiTanggal) ?>"></div>
    <div class="field"><label>Status</label>
      <select name="status">
        <?php foreach ($statusOptions as $key => $label): ?>
        <option value="<?= ui_esc($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= ui_esc($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>
</div>

<?php if ($rows === []): ?>
<?= ui_empty_state('Belum ada pengiriman', 'Tidak ada pengiriman yang cocok dengan filter tanggal/status ini.') ?>
<?php else: ?>
<?php $pendingCount = count(array_filter($rows, static fn ($r) => $r['status'] === 'belum_dikonfirmasi')); ?>
<div class="card section">
  <?php if ($pendingCount > 0): ?>
  <div class="alert alert-info" style="display:flex;gap:var(--space-3);align-items:center;flex-wrap:wrap;">
    <span style="flex:1 1 280px;">Toko belum pakai Portal? Centang pengiriman yang surat jalannya sudah kembali bertanda tangan <b>tanpa catatan reject/kurang</b>, lalu klik tombol ini. Kalau ada reject/kurang, buka detailnya dan isi satu per satu.</span>
    <button type="button" class="btn btn-primary btn-sm" id="btn-confirm-all-good" disabled>Diterima semua sesuai SJ (<span id="sel-count">0</span>)</button>
  </div>
  <?php endif; ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr>
      <th><?php if ($pendingCount > 0): ?><input type="checkbox" id="sel-all" title="Pilih semua yang belum dikonfirmasi"><?php endif; ?></th>
      <th>Toko/Drop</th><th>No. DO</th><th>Sumber</th><th>Metode</th><th>Driver/Kurir</th><th>Grup</th><th class="num">Dikirim</th><th class="num">Baik</th>
      <th class="num">Reject</th><th class="num">Kurang</th><th>Status Email</th><th>Status Penerimaan</th><th>Dikonfirmasi</th><th>Aksi</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <?php $detailUrl = '?page=konfirmasi-toko-detail&shipmentId=' . (int) $r['shipmentId']; ?>
    <tr class="row-clickable" data-row-href="<?= ui_esc($detailUrl) ?>">
      <td><?php if ($r['status'] === 'belum_dikonfirmasi'): ?><input type="checkbox" class="sel-ship" value="<?= (int) $r['shipmentId'] ?>"><?php endif; ?></td>
      <td><?= ui_esc((string) $r['storeName']) ?></td>
      <td><?= ui_esc((string) ($r['docNo'] ?? '-')) ?></td>
      <td><?= ui_normalized_source_badge($r['source']['type'], $r['source']['label']) ?></td>
      <td><?= $r['deliveryMethod'] === 'EXTERNAL_COURIER' ? 'Kurir Eksternal' : 'Driver Internal' ?></td>
      <td><?= ui_esc((string) ($r['driverName'] ?? '-')) ?></td>
      <td><?= ui_esc((string) $r['shipmentGroup']) ?></td>
      <td class="num"><?= ui_fmt_num($r['totalShipped']) ?></td>
      <td class="num"><?= ui_fmt_num($r['totalGood']) ?></td>
      <td class="num"><?= $r['totalReject'] > 0.0001 ? '<strong style="color:var(--danger)">' . ui_fmt_num($r['totalReject']) . '</strong>' : ui_fmt_num($r['totalReject']) ?></td>
      <td class="num"><?= $r['totalShortage'] > 0.0001 ? '<strong style="color:var(--danger)">' . ui_fmt_num($r['totalShortage']) . '</strong>' : ui_fmt_num($r['totalShortage']) ?></td>
      <td><?= ui_badge(ui_email_status_label($r['emailStatus'])) ?></td>
      <td><?= ui_badge(ui_receipt_status_label((string) $r['status'])) ?></td>
      <td><?= ui_esc(ui_fmt_datetime_id($r['confirmedAt'] ?? null)) ?><?= $r['receiverName'] ? ' &middot; ' . ui_esc((string) $r['receiverName']) : '' ?></td>
      <td>
        <a class="btn btn-secondary btn-sm" href="<?= ui_esc($detailUrl) ?>"><?= $r['status'] === 'belum_dikonfirmasi' ? 'Isi dari SJ' : 'Lihat Detail' ?> &rsaquo;</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<script>
// Real-UAT ask: the whole row is clickable (not just the "Lihat Detail"
// link/status badge) — a click anywhere on the row that isn't itself a
// link/button navigates to the same Detail page.
document.querySelectorAll('tr.row-clickable').forEach(function (tr) {
  tr.style.cursor = 'pointer';
  tr.addEventListener('click', function (e) {
    if (e.target.closest('a, button, input')) return;
    window.location.href = tr.dataset.rowHref;
  });
});

(function () {
  var btn = document.getElementById('btn-confirm-all-good');
  if (!btn) return;
  var boxes = Array.prototype.slice.call(document.querySelectorAll('.sel-ship'));
  var all = document.getElementById('sel-all');
  function picked() { return boxes.filter(function (b) { return b.checked; }).map(function (b) { return parseInt(b.value, 10); }); }
  function refresh() { var n = picked().length; document.getElementById('sel-count').textContent = n; btn.disabled = n === 0; }
  boxes.forEach(function (b) { b.addEventListener('change', refresh); });
  if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); refresh(); });
  btn.addEventListener('click', async function () {
    var ids = picked();
    var res = null;
    var ok = await Amor.confirmModal({
      title: 'Diterima semua sesuai surat jalan?',
      body: ids.length + ' pengiriman akan dicatat diterima BAIK semua (tanpa reject/kurang). Pastikan surat jalannya sudah kembali bertanda tangan. Setelah ini invoicenya bisa diterbitkan.',
      confirmLabel: 'Ya, Simpan',
      pendingLabel: 'Menyimpan...',
      onConfirm: function () {
        return Amor.apiFetch('/api/admin/shipments/confirm-all-good', { method: 'POST', body: { shipmentIds: ids, receiverName: 'Sesuai TTD surat jalan' } })
          .then(function (d) { res = d; });
      },
    });
    if (!ok) return;
    Amor.toast(res.confirmed.length + ' pengiriman dicatat diterima' + (res.skipped.length ? ', ' + res.skipped.length + ' dilewati (sudah dikonfirmasi)' : ''), 'success');
    window.location.reload();
  });
})();
</script>
