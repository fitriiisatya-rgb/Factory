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
<div class="card section">
  <div class="table-scroll"><table class="data-table">
    <thead><tr>
      <th>Toko</th><th>No. DO</th><th>Driver</th><th>Grup</th><th class="num">Dikirim</th><th class="num">Baik</th>
      <th class="num">Reject</th><th class="num">Kurang</th><th>Status Email</th><th>Status Penerimaan</th><th>Dikonfirmasi</th><th>Aksi</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <?php $detailUrl = '?page=konfirmasi-toko-detail&shipmentId=' . (int) $r['shipmentId']; ?>
    <tr class="row-clickable" data-row-href="<?= ui_esc($detailUrl) ?>">
      <td><?= ui_esc((string) $r['storeName']) ?></td>
      <td><?= ui_esc((string) ($r['docNo'] ?? '-')) ?></td>
      <td><?= ui_esc((string) ($r['driverName'] ?? '-')) ?></td>
      <td><?= ui_esc((string) $r['shipmentGroup']) ?></td>
      <td class="num"><?= ui_fmt_num($r['totalShipped']) ?></td>
      <td class="num"><?= ui_fmt_num($r['totalGood']) ?></td>
      <td class="num"><?= $r['totalReject'] > 0.0001 ? '<strong style="color:var(--danger)">' . ui_fmt_num($r['totalReject']) . '</strong>' : ui_fmt_num($r['totalReject']) ?></td>
      <td class="num"><?= $r['totalShortage'] > 0.0001 ? '<strong style="color:var(--danger)">' . ui_fmt_num($r['totalShortage']) . '</strong>' : ui_fmt_num($r['totalShortage']) ?></td>
      <td><?= ui_badge(ui_email_status_label($r['emailStatus'])) ?></td>
      <td><?= ui_badge(ui_receipt_status_label((string) $r['status'])) ?></td>
      <td><?= ui_esc((string) ($r['confirmedAt'] ?? '-')) ?><?= $r['receiverName'] ? ' &middot; ' . ui_esc((string) $r['receiverName']) : '' ?></td>
      <td>
        <a class="btn btn-secondary btn-sm" href="<?= ui_esc($detailUrl) ?>">Lihat Detail &rsaquo;</a>
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
    if (e.target.closest('a, button')) return;
    window.location.href = tr.dataset.rowHref;
  });
});
</script>
