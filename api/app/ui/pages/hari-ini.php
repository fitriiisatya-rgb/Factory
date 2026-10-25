<?php

declare(strict_types=1);

use Amor\Api\Dashboard\DashboardService;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\Delivery\DoService;
use Amor\Api\System\BackupService;
use Amor\Api\System\ErrorLog;

/*
 * "Hari Ini" — one checklist for Admin: what is still open today, each
 * line a link to the page where it gets done. Read-only, built from the
 * same tables the other pages use.
 */
$d = $uiTanggal; $fid = $uiFactoryId;
$besok = (new DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d');
$qs = static fn (string $page, string $tgl, array $extra = []) => '/api/_ui-preview/?' . http_build_query(['page' => $page, 'tanggal' => $tgl, 'factoryId' => $GLOBALS['uiFactoryId']] + $extra);
$one = static function (string $sql, array $p) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); };
$n = static fn ($v) => ui_fmt_num((float) $v);
$tglId = static fn (string $t) => (new DateTimeImmutable($t))->format('d M Y');

$poSql = 'SELECT COALESCE(SUM(i.po_awal + i.po_revisi), 0) FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND b.factory_id = ?';
$poToday = (float) $one($poSql, [$d, $fid]);
$poTomorrow = (float) $one($poSql, [$besok, $fid]);

$divStmt = $pdo->prepare(
    "SELECT d.division_id, d.name,
            (SELECT COALESCE(SUM(i.po_awal + i.po_revisi), 0) FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id
              INNER JOIN product p ON p.product_id = i.product_id WHERE b.tanggal = ? AND b.factory_id = d.factory_id AND p.division_id = d.division_id) AS target,
            r.production_run_id, r.status,
            (SELECT COALESCE(SUM(pi.aktual), 0) FROM production_item pi WHERE pi.production_run_id = r.production_run_id) AS made,
            (SELECT COALESCE(SUM(phi.qty), 0) FROM production_handover ph INNER JOIN production_handover_item phi ON phi.production_handover_id = ph.production_handover_id
              WHERE ph.production_run_id = r.production_run_id) AS handed
     FROM division d
     LEFT JOIN production_run r ON r.division_id = d.division_id AND r.tanggal = ?
     WHERE d.factory_id = ? AND d.is_verification = 0
     ORDER BY d.name"
);
$divStmt->execute([$d, $d, $fid]);
$divs = array_values(array_filter($divStmt->fetchAll(PDO::FETCH_ASSOC), static fn ($r) => (float) $r['target'] > 0 || $r['production_run_id'] !== null));
$divSubmitted = count(array_filter($divs, static fn ($r) => in_array($r['status'], ['submitted', 'verified_fg'], true)));

$fgStatus = $one('SELECT status FROM fg_batch WHERE tanggal = ? AND factory_id = ?', [$d, $fid]);
$k = (new DashboardService($pdo, new DoService($pdo), new DoRepository()))->summary($d, $fid)['kpi'];

$unconfirmed = (int) $one(
    "SELECT COUNT(*) FROM shipment s LEFT JOIN shipment_receipt r ON r.shipment_id = s.shipment_id
     WHERE s.status = 'active' AND r.shipment_id IS NULL AND s.factory_id = ? AND s.tanggal BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND ?", [$fid, $d, $d]);
// Same list the "Reject saat Terima" page shows as waiting for a decision.
$discrepancy = count((new \Amor\Api\Replacement\ReplacementService($pdo))->pendingDisposition(null));
$rejectToko = (int) $one("SELECT COUNT(*) FROM retur_request WHERE jenis = 'reject_toko' AND status = 'waiting_admin_verification'", []);
$retur = (int) $one("SELECT COUNT(*) FROM retur_request WHERE jenis = 'retur' AND status = 'waiting_admin_verification'", []);
$mutasi = (int) $one("SELECT COUNT(*) FROM mutasi_request WHERE status = 'discrepancy'", []);
$sisa = array_sum(array_column((new \Amor\Api\Fg\StokSisaService($pdo))->list(null), 'sisa'));

$isAdmin = in_array('ADMIN', $ui['roles'], true);
$errors24 = ErrorLog::countSince(time() - 86400);
$lastBackup = (new BackupService($pdo))->list()[0] ?? null;
$backupAgeH = $lastBackup !== null ? (time() - $lastBackup['mtime']) / 3600 : null;

// state: ok | todo | warn
$rows = [];
$add = static function (string $group, string $state, string $title, string $detail, ?string $href, string $btn = 'Buka') use (&$rows) {
    $rows[$group][] = compact('state', 'title', 'detail', 'href', 'btn');
};

$add('Pagi', $poToday > 0 ? 'ok' : 'todo', 'PO hari ini (' . $tglId($d) . ')', $poToday > 0 ? $n($poToday) . ' pcs sudah diupload' : 'Belum diupload. Divisi tetap bisa mulai produksi, target muncul setelah PO diupload.', '/api/_import-po/', $poToday > 0 ? 'Lihat' : 'Upload PO');
$add('Pagi', $poTomorrow > 0 ? 'ok' : 'warn', 'PO besok (' . $tglId($besok) . ')', $poTomorrow > 0 ? $n($poTomorrow) . ' pcs sudah diupload' : 'Belum diupload.', '/api/_import-po/', 'Upload PO');

if ($divs === []) {
    // Nothing to chase yet: without a PO the PO line above is the action.
    $add('Produksi', $poToday > 0 ? 'warn' : 'ok', 'Produksi divisi', 'Belum ada divisi yang mulai produksi untuk tanggal ini.', $qs('produksi', $d));
} else {
    $add('Produksi', $divSubmitted === count($divs) ? 'ok' : 'todo', 'Divisi sudah submit: ' . $divSubmitted . ' / ' . count($divs), 'Ketuk divisi di bawah untuk membuka lembarnya.', $qs('produksi', $d));
    foreach ($divs as $r) {
        $st = $r['status'];
        if (in_array($st, ['submitted', 'verified_fg'], true)) {
            $add('Produksi', 'ok', '· ' . $r['name'], 'Sudah submit · hasil ' . $n($r['made']) . ' dari target ' . $n($r['target']), $qs('produksi', $d, ['divisionId' => $r['division_id']]));
        } elseif ($st === null) {
            $add('Produksi', 'todo', '· ' . $r['name'], 'Belum mulai · target ' . $n($r['target']) . ' pcs', $qs('produksi', $d, ['divisionId' => $r['division_id']]));
        } else {
            $left = max(0, (float) $r['made'] - (float) $r['handed']);
            $add('Produksi', 'warn', '· ' . $r['name'], 'Sedang jalan · sudah jadi ' . $n($r['made']) . ' / target ' . $n($r['target']) . ' · diserahkan ke FG ' . $n($r['handed']) . ($left > 0 ? ' · masih di produksi ' . $n($left) : ''), $qs('produksi', $d, ['divisionId' => $r['division_id']]));
        }
    }
}

$fgLabel = ['draft' => 'Draft — sedang diverifikasi', 'reopened' => 'Dibuka kembali — ada barang baru dari produksi', 'submitted' => 'Sudah Submit FG'];
// FG only has work once a division has handed over or submitted something.
$readyForFg = array_filter($divs, static fn ($r) => (float) $r['handed'] > 0 || in_array($r['status'], ['submitted', 'verified_fg'], true)) !== [];
if ($fgStatus) {
    $fgState = $fgStatus === 'submitted' ? 'ok' : 'warn';
    $fgDetail = $fgLabel[$fgStatus] ?? (string) $fgStatus;
} else {
    $fgState = $readyForFg ? 'todo' : 'ok';
    $fgDetail = $readyForFg ? 'Sudah ada barang dari produksi — buat dokumen FG.' : 'Belum ada barang dari produksi.';
}
$add('FG & Kirim', $fgState, 'FG & Packing', $fgDetail, $qs('fg-packing', $d, ['step' => 'ringkasan']));
$add('FG & Kirim', $k['doTotal'] > 0 && $k['doCreated'] >= $k['doTotal'] ? 'ok' : ($k['doTotal'] > 0 ? 'todo' : 'ok'), 'Delivery Order dibuat: ' . $k['doCreated'] . ' / ' . $k['doTotal'] . ' toko', $k['doTotal'] > 0 ? 'Toko dengan PO hari ini.' : 'Tidak ada toko dengan PO hari ini.', $qs('delivery-order', $d));
$add('FG & Kirim', $k['doCreated'] > 0 && $k['shipped'] >= $k['doCreated'] ? 'ok' : ($k['doCreated'] > 0 ? 'warn' : 'ok'), 'Sudah dikirim: ' . $k['shipped'] . ' / ' . $k['doCreated'] . ' DO', 'Pengiriman driver.', $qs('pengiriman', $d));

$add('Toko', $unconfirmed === 0 ? 'ok' : 'warn', 'Belum dikonfirmasi toko: ' . $unconfirmed, 'Kiriman 7 hari terakhir yang belum dikonfirmasi penerimaannya.', $qs('konfirmasi-toko', $d));
$add('Toko', $discrepancy === 0 ? 'ok' : 'todo', 'Reject saat terima belum diputuskan: ' . $discrepancy, 'Perlu ditinjau: reject final atau kirim ulang.', $qs('replacement-reject', $d), 'Tinjau');
$add('Toko', $rejectToko === 0 ? 'ok' : 'todo', 'Reject di Toko menunggu keputusan: ' . $rejectToko, 'Potong invoice atau ganti barang.', $qs('reject-toko', $d), 'Putuskan');
$add('Toko', $retur === 0 ? 'ok' : 'warn', 'Retur menunggu dicek: ' . $retur, 'Barang tidak laku (catatan, tanggungan toko).', $qs('retur-review', $d), 'Cek');
$add('Toko', $mutasi === 0 ? 'ok' : 'todo', 'Mutasi dengan selisih: ' . $mutasi, 'Perlu diputuskan.', $qs('mutasi-review', $d), 'Putuskan');
if ($sisa > 0) {
    $add('Toko', 'warn', 'Stok Sisa: ' . $n($sisa) . ' pcs', 'Kirim susulan ke toko atau jual ke non-toko.', $qs('stok-sisa', $d), 'Tindak lanjuti');
}

if ($isAdmin) {
    $add('Sistem', $errors24 === 0 ? 'ok' : 'todo', 'Error sistem 24 jam terakhir: ' . $errors24, $errors24 === 0 ? 'Tidak ada error.' : 'Buka dan kirim screenshot ke developer.', '/api/_ui-preview/?page=sistem', 'Lihat');
    $add('Sistem', $backupAgeH !== null && $backupAgeH <= 30 ? 'ok' : 'todo', 'Backup database', $lastBackup === null ? 'Belum ada backup.' : 'Terakhir ' . date('d M Y H:i', $lastBackup['mtime']) . '. Unduh satu ke laptop minimal seminggu sekali.', '/api/_ui-preview/?page=sistem', 'Buka');
}

$todo = 0; $warn = 0;
foreach ($rows as $g) { foreach ($g as $r) { if ($r['state'] === 'todo') { $todo++; } elseif ($r['state'] === 'warn') { $warn++; } } }
?>
<style>
.hi-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.hi-sum{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.hi-sum span{padding:6px 12px;border-radius:999px;font-weight:700;font-size:13px}
.hi-g{margin-bottom:14px}.hi-g h3{font-size:14px;margin:0 0 6px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em}
.hi-row{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:12px;margin-bottom:6px;background:var(--surface);text-decoration:none;color:inherit}
.hi-row:hover{border-color:var(--primary)}
.hi-ic{flex:0 0 28px;height:28px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px}
.hi-ok .hi-ic{background:rgba(34,197,94,.15);color:#22c55e}.hi-todo .hi-ic{background:rgba(239,68,68,.15);color:#f87171}.hi-warn .hi-ic{background:rgba(245,158,11,.15);color:#fbbf24}
.hi-t{flex:1;min-width:0}.hi-t b{display:block;font-size:14px}.hi-t small{color:var(--text-muted);font-size:12.5px}
.hi-btn{flex:0 0 auto}
.hi-ok{opacity:.78}
</style>
<div class="hi-head">
  <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="hari-ini">
    <div class="field"><label>Tanggal</label><input type="date" name="tanggal" value="<?= ui_esc($d) ?>" onchange="this.form.submit()"></div>
    <div class="field"><label>Pabrik</label><select name="factoryId" onchange="this.form.submit()">
      <?php foreach ($factories as $f): ?><option value="<?= (int) $f['factory_id'] ?>" <?= $fid === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option><?php endforeach; ?>
    </select></div>
  </form>
</div>
<div class="hi-sum">
  <span style="background:rgba(239,68,68,.15);color:#f87171;"><?= $todo ?> perlu dikerjakan</span>
  <span style="background:rgba(245,158,11,.15);color:#fbbf24;"><?= $warn ?> perlu dipantau</span>
  <?php if ($todo === 0 && $warn === 0): ?><span style="background:rgba(34,197,94,.15);color:#22c55e;">Semua beres</span><?php endif; ?>
</div>
<?php foreach ($rows as $group => $items): ?>
<div class="hi-g">
  <h3><?= ui_esc($group) ?></h3>
  <?php foreach ($items as $r): $icon = $r['state'] === 'ok' ? '&#10003;' : ($r['state'] === 'todo' ? '!' : '&#8226;'); ?>
  <a class="hi-row hi-<?= $r['state'] ?>" href="<?= ui_esc((string) $r['href']) ?>">
    <span class="hi-ic"><?= $icon ?></span>
    <span class="hi-t"><b><?= ui_esc($r['title']) ?></b><small><?= ui_esc($r['detail']) ?></small></span>
    <?php if ($r['state'] !== 'ok'): ?><span class="btn btn-sm <?= $r['state'] === 'todo' ? 'btn-primary' : 'btn-secondary' ?> hi-btn"><?= ui_esc($r['btn']) ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
