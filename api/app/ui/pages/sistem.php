<?php

declare(strict_types=1);

use Amor\Api\System\BackupService;
use Amor\Api\System\ErrorLog;

$backup = new BackupService($pdo);
$backups = $backup->list();
$errors = ErrorLog::recent(100);
$fmtSize = static fn (int $b) => $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format($b / 1024, 0, ',', '.') . ' KB';
$fmtTime = static fn (int $ts) => date('d M Y · H:i', $ts);
$latest = $backups[0] ?? null;
$ageH = $latest !== null ? (time() - $latest['mtime']) / 3600 : null;
?>
<div class="card section">
  <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
    <h2 class="card-title">Backup database</h2>
    <button type="button" class="btn btn-primary" id="btn-backup-now">Backup sekarang</button>
  </div>
  <?php if ($latest === null): ?>
  <div class="alert alert-danger">Belum ada backup. Tekan <b>Backup sekarang</b>.</div>
  <?php elseif ($ageH > 30): ?>
  <div class="alert alert-danger">Backup terakhir <b><?= ui_esc($fmtTime($latest['mtime'])) ?></b> — lebih dari sehari lalu.</div>
  <?php else: ?>
  <div class="alert alert-success">Backup terakhir <b><?= ui_esc($fmtTime($latest['mtime'])) ?></b> (<?= ui_esc($fmtSize($latest['bytes'])) ?>).</div>
  <?php endif; ?>
  <p style="font-size:var(--text-sm);color:var(--text-muted);margin:6px 0 10px;">
    Sistem membuat backup otomatis sekali sehari (saat Admin membuka aplikasi) dan menyimpan <?= BackupService::KEEP ?> backup terakhir di server.
    <b>Unduh satu backup ke laptop/Google Drive minimal seminggu sekali</b> — backup yang hanya ada di server ikut hilang kalau servernya bermasalah.
    Cara mengembalikan: phpMyAdmin → database → Import → pilih file .sql.gz.
  </p>
  <?php if ($backups !== []): ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Waktu</th><th class="num">Ukuran</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($backups as $b): ?>
      <tr><td><?= ui_esc($fmtTime($b['mtime'])) ?></td><td class="num"><?= ui_esc($fmtSize($b['bytes'])) ?></td>
        <td><a class="btn btn-secondary btn-sm" href="/api/admin/system/backup-download?name=<?= ui_esc(rawurlencode($b['name'])) ?>">Unduh</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <details style="margin-top:10px;font-size:var(--text-sm);color:var(--text-muted);"><summary style="cursor:pointer;">Lebih aman: jadwal otomatis lewat cPanel (Cron Jobs)</summary>
    <p style="margin:6px 0;">cPanel → <b>Cron Jobs</b> → Common Settings: <i>Once Per Day</i> → Command:</p>
    <code style="display:block;padding:8px;background:var(--surface-2);border-radius:6px;word-break:break-all;">php <?= ui_esc(realpath(__DIR__ . '/../../cli/backup.php') ?: '/home/USER/public_html/factory/api/app/cli/backup.php') ?></code>
  </details>
</div>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Error sistem <small style="font-weight:400;color:var(--text-muted);">(100 terakhir)</small></h2></div>
  <?php if ($errors === []): ?>
  <div class="alert alert-success">Tidak ada error tercatat.</div>
  <?php else: ?>
  <p style="font-size:var(--text-sm);color:var(--text-muted);">Kalau ada error baru, kirim screenshot halaman ini ke developer.</p>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Waktu</th><th>Halaman</th><th>User</th><th>Pesan</th></tr></thead>
    <tbody>
    <?php foreach ($errors as $e): ?>
      <tr><td style="white-space:nowrap;"><?= ui_esc(date('d M H:i', strtotime((string) $e['t']))) ?></td><td><?= ui_esc((string) $e['path']) ?></td><td><?= ui_esc((string) $e['user']) ?></td>
        <td style="font-size:var(--text-xs);"><?= ui_esc((string) $e['msg']) ?><?= $e['where'] !== '' ? ' <span style="color:var(--text-faint);">(' . ui_esc((string) $e['where']) . ')</span>' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var b = document.getElementById('btn-backup-now');
  if (b) b.addEventListener('click', async function () {
    b.disabled = true; b.textContent = 'Membuat backup...';
    try { var r = await Amor.apiFetch('/api/admin/system/backups', { method: 'POST', body: {} }); await Amor.toast('Backup selesai: ' + r.tables + ' tabel, ' + (r.bytes / 1048576).toFixed(1) + ' MB.', 'success'); location.reload(); }
    catch (e) { Amor.toast(e.message, 'danger'); b.disabled = false; b.textContent = 'Backup sekarang'; }
  });
});
</script>
