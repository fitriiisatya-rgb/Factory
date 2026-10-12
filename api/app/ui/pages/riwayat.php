<?php

declare(strict_types=1);

use Amor\Api\Auth;

/*
 * Riwayat — what this account has worked on, newest first. A divisi
 * account sees its own divisions' Produksi documents, an FG account its
 * own factories' FG documents; ADMIN/PPIC see both, every division and
 * factory. Same default-deny scoping as the worksheets themselves
 * (Auth::requireDivisionAccess / requireFactoryAccess): a scoped account
 * with no assignment sees nothing, never everything. Read-only; "Buka"
 * opens the normal worksheet for that date.
 */

$roles = Auth::currentRoles();
$isUnscoped = array_intersect(['ADMIN', 'PPIC'], $roles) !== [];
$showProduksi = $isUnscoped || in_array('PRODUCTION', $roles, true);
$showFg = $isUnscoped || in_array('FG_PACKING', $roles, true);

$today = date('Y-m-d');
$dateRe = '/^\d{4}-\d{2}-\d{2}$/';
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
if (preg_match($dateRe, $from) !== 1) {
    $from = date('Y-m-d', strtotime($today . ' -30 days'));
}
if (preg_match($dateRe, $to) !== 1) {
    $to = $today;
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

/** @param int[] $ids */
$inList = static function (array $ids): string {
    return implode(',', array_map('intval', $ids));
};

$prodRows = [];
if ($showProduksi) {
    $divIds = $isUnscoped ? null : Auth::currentDivisionIds();
    if ($divIds === null || $divIds !== []) {
        $sql = 'SELECT r.production_run_id, r.tanggal, r.status, r.submitted_at, r.updated_at, r.created_at,
                       d.name AS division_name, d.factory_id,
                       COALESCE(r.submitted_by_operator_name, su.full_name) AS submitted_by_name,
                       COALESCE(r.last_updated_by_operator_name, r.created_by_operator_name, cu.full_name) AS updated_by_name,
                       COUNT(i.production_item_id) AS item_count,
                       COALESCE(SUM(i.aktual), 0) AS total_actual,
                       COALESCE(SUM(i.reject), 0) AS total_reject
                FROM production_run r
                JOIN division d ON d.division_id = r.division_id
                LEFT JOIN users su ON su.user_id = r.submitted_by
                LEFT JOIN users cu ON cu.user_id = r.created_by
                LEFT JOIN production_item i ON i.production_run_id = r.production_run_id
                WHERE r.tanggal BETWEEN ? AND ?'
            . ($divIds === null ? '' : ' AND r.division_id IN (' . $inList($divIds) . ')')
            . ' GROUP BY r.production_run_id
                ORDER BY r.tanggal DESC, d.name
                LIMIT 300';
        $st = $pdo->prepare($sql);
        $st->execute([$from, $to]);
        $prodRows = $st->fetchAll();
    }
}

$fgRows = [];
if ($showFg) {
    $facIds = $isUnscoped ? null : Auth::currentFactoryIds();
    if ($facIds === null || $facIds !== []) {
        $sql = 'SELECT b.fg_batch_id, b.tanggal, b.status, b.submitted_at, b.factory_id, f.name AS factory_name,
                       su.full_name AS submitted_by_name,
                       (SELECT COUNT(DISTINCT fi.product_id) FROM fg_item fi WHERE fi.fg_batch_id = b.fg_batch_id) AS product_count,
                       (SELECT COALESCE(SUM(fi.qty), 0) FROM fg_item fi WHERE fi.fg_batch_id = b.fg_batch_id) AS total_qty,
                       (SELECT COUNT(DISTINCT fi.store_id) FROM fg_item fi WHERE fi.fg_batch_id = b.fg_batch_id AND fi.qty > 0) AS store_count,
                       (SELECT COUNT(*) FROM fg_store_packing_submission ps WHERE ps.fg_batch_id = b.fg_batch_id AND ps.status = \'submitted\') AS packed_count
                FROM fg_batch b
                JOIN factory f ON f.factory_id = b.factory_id
                LEFT JOIN users su ON su.user_id = b.submitted_by
                WHERE b.tanggal BETWEEN ? AND ?'
            . ($facIds === null ? '' : ' AND b.factory_id IN (' . $inList($facIds) . ')')
            . ' ORDER BY b.tanggal DESC, f.name
                LIMIT 300';
        $st = $pdo->prepare($sql);
        $st->execute([$from, $to]);
        $fgRows = $st->fetchAll();
    }
}

$fgStatusLabel = static fn (string $s): string => ['draft' => 'Draft', 'submitted' => 'Sudah Disubmit', 'reopened' => 'Dibuka Kembali'][$s] ?? $s;
?>
<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="riwayat">
    <div class="field"><label>Dari</label><input type="date" name="from" value="<?= ui_esc($from) ?>"></div>
    <div class="field"><label>Sampai</label><input type="date" name="to" value="<?= ui_esc($to) ?>"></div>
    <button type="submit" class="btn btn-primary">Tampilkan</button>
  </form>
</div>

<?php if ($showProduksi): ?>
<div class="table-card section" id="riwayat-produksi">
  <div class="card-head" style="padding:var(--space-4) var(--space-4) 0;">
    <h2 class="card-title">Riwayat Produksi</h2>
    <span style="color:var(--text-muted);font-size:var(--text-sm);"><?= count($prodRows) ?> dokumen</span>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Tanggal</th><th>Divisi</th><th>Status</th><th class="num">Jumlah Produk</th><th class="num">Hasil</th><th class="num">Reject</th><th>Diisi oleh</th><th>Disubmit</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if ($prodRows === []): ?>
    <tr><td colspan="9"><?= ui_empty_state('Belum ada riwayat produksi', 'Belum ada dokumen produksi pada periode ini.') ?></td></tr>
    <?php endif; ?>
    <?php foreach ($prodRows as $r): ?>
    <tr>
      <td><?= ui_esc(ui_fmt_date_id((string) $r['tanggal'])) ?></td>
      <td><?= ui_esc((string) $r['division_name']) ?></td>
      <td><?= ui_badge(ui_doc_status_label((string) $r['status'])) ?></td>
      <td class="num"><?= (int) $r['item_count'] ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['total_actual']) ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['total_reject']) ?></td>
      <td><?= ui_esc((string) ($r['submitted_by_name'] ?? $r['updated_by_name'] ?? '-')) ?></td>
      <td style="font-size:var(--text-sm);color:var(--text-muted);"><?= $r['submitted_at'] !== null ? ui_fmt_datetime_id((string) $r['submitted_at']) : 'Belum' ?></td>
      <td><a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=produksi&tanggal=<?= urlencode((string) $r['tanggal']) ?>&factoryId=<?= (int) $r['factory_id'] ?>&runId=<?= (int) $r['production_run_id'] ?>">Buka</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($showFg): ?>
<div class="table-card section" id="riwayat-fg">
  <div class="card-head" style="padding:var(--space-4) var(--space-4) 0;">
    <h2 class="card-title">Riwayat FG &amp; Packing</h2>
    <span style="color:var(--text-muted);font-size:var(--text-sm);"><?= count($fgRows) ?> dokumen</span>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Tanggal</th><th>Pabrik</th><th>Status</th><th class="num">Jumlah Produk</th><th class="num">FG Terverifikasi</th><th class="num">Toko Dipacking</th><th>Disubmit oleh</th><th>Disubmit</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if ($fgRows === []): ?>
    <tr><td colspan="9"><?= ui_empty_state('Belum ada riwayat FG', 'Belum ada dokumen FG pada periode ini.') ?></td></tr>
    <?php endif; ?>
    <?php foreach ($fgRows as $r): ?>
    <tr>
      <td><?= ui_esc(ui_fmt_date_id((string) $r['tanggal'])) ?></td>
      <td><?= ui_esc((string) $r['factory_name']) ?></td>
      <td><?= ui_badge($fgStatusLabel((string) $r['status'])) ?></td>
      <td class="num"><?= (int) $r['product_count'] ?></td>
      <td class="num"><?= ui_fmt_num((float) $r['total_qty']) ?></td>
      <td class="num"><?= (int) $r['packed_count'] ?> / <?= (int) $r['store_count'] ?></td>
      <td><?= ui_esc((string) ($r['submitted_by_name'] ?? '-')) ?></td>
      <td style="font-size:var(--text-sm);color:var(--text-muted);"><?= $r['submitted_at'] !== null ? ui_fmt_datetime_id((string) $r['submitted_at']) : 'Belum' ?></td>
      <td><a class="btn btn-secondary btn-sm" href="/api/_ui-preview/?page=fg-packing&tanggal=<?= urlencode((string) $r['tanggal']) ?>&factoryId=<?= (int) $r['factory_id'] ?>">Buka</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
