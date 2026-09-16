<?php

declare(strict_types=1);

/**
 * Amor Factory — schema upgrade runner (applies pending migrations/NNNN_*.php
 * files, e.g. 0002_master_identity.php).
 *
 * Unlike api/_setup/ (bootstrap-only, meant to be deleted after first use)
 * and api/_import-master/ (Phase-1-specific, one-time legacy data import,
 * meant to be deleted after Phase 1), this tool is DESIGNED TO STAY — every
 * future phase that adds a migrations/NNNN_*.php file can be applied here
 * without re-uploading a setup wizard. It is safe to leave in place because:
 *   - it requires a real ADMIN login session (not a bootstrap token),
 *   - it can only ever apply migration files that are already part of the
 *     deployed codebase (an admin account cannot use this to run arbitrary
 *     SQL — there is no free-text SQL input anywhere on this page),
 *   - MigrationRunner's own safety gate (EXPECTED_DB_NAME match, known-state
 *     check) still applies, and it never issues DROP/TRUNCATE — see
 *     app/src/Setup/MigrationRunner.php.
 * Operators who still prefer to remove it can delete api/_upgrade/ at any
 * time — nothing else depends on this directory existing.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Setup\MigrationRunner;

try {
    Config::load();
} catch (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
        . '<h1>Konfigurasi belum lengkap</h1><p>' . htmlspecialchars($e->getMessage()) . '</p></body></html>';
    exit;
}

Auth::bootSession();

if (Auth::currentUserId() === null) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Login diperlukan</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:2rem auto;">'
        . '<h1>Login diperlukan</h1><p>Login sebagai ADMIN dulu lewat aplikasi utama, lalu buka ulang halaman ini '
        . 'di browser/tab yang sama.</p></body></html>';
    exit;
}

try {
    Auth::requireRole('ADMIN');
} catch (ApiException $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Akses ditolak</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:2rem auto;"><h1>Akses ditolak</h1>'
        . '<p>Halaman ini hanya untuk ADMIN.</p></body></html>';
    exit;
}

$csrfToken = (string) $_SESSION['csrf_token'];
$pdo = Database::pdo();
$dbName = (string) Config::get('DB_NAME');
$expectedDbName = (string) Config::get('EXPECTED_DB_NAME', '');

$actionResult = null;
$postedCsrf = (string) ($_POST['csrf'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    if (!hash_equals($csrfToken, $postedCsrf)) {
        $actionResult = ['ok' => false, 'title' => 'CSRF token tidak cocok', 'message' => 'Muat ulang halaman dan coba lagi.'];
    } elseif ($expectedDbName !== '' && $expectedDbName !== $dbName) {
        $actionResult = ['ok' => false, 'title' => 'Ditolak', 'message' => "DB_NAME ('$dbName') tidak cocok dengan EXPECTED_DB_NAME ('$expectedDbName')."];
    } elseif (($_POST['confirm'] ?? '') !== '1') {
        $actionResult = ['ok' => false, 'title' => 'Belum dikonfirmasi', 'message' => 'Centang kotak konfirmasi dulu.'];
    } else {
        try {
            $runner = new MigrationRunner($pdo, $dbName);
            $runner->ensureBookkeepingTable();
            $state = $runner->checkKnownState();
            if (!$state['ok']) {
                throw new \RuntimeException($state['reason']);
            }
            $result = $runner->applyPending();
            $actionResult = $result['applied'] === []
                ? ['ok' => true, 'title' => 'Tidak ada yang perlu diterapkan', 'message' => 'Semua migrasi sudah terpasang sebelumnya.']
                : ['ok' => true, 'title' => 'Migrasi berhasil diterapkan', 'message' => 'Diterapkan: ' . implode(', ', $result['applied'])];
        } catch (\Throwable $e) {
            $actionResult = ['ok' => false, 'title' => 'Migrasi gagal', 'message' => $e->getMessage()];
        }
    }
}

$runner = new MigrationRunner($pdo, $dbName);
$runner->ensureBookkeepingTable();
$applied = $runner->appliedMigrations();
$pending = array_map('basename', $runner->pendingMigrations());
$businessTableCount = $runner->businessTableCount();

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>Amor Factory — Upgrade Database</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:680px;margin:2rem auto;padding:0 1rem;color:#1a1a1a;line-height:1.5;}
h1{font-size:1.4rem;}
.box{border:1px solid #ddd;border-radius:8px;padding:1rem;margin:1rem 0;}
.warn{background:#fff3cd;border:1px solid #f0c36d;padding:.75rem;border-radius:6px;}
.result-ok{background:#e6ffe6;border:1px solid #8c8;padding:.75rem;border-radius:6px;}
.result-error{background:#ffe6e6;border:1px solid #e99;padding:.75rem;border-radius:6px;}
button{padding:.5rem 1.2rem;background:#0a5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:bold;}
ul{margin:.3rem 0;}
</style>
</head>
<body>
<h1>Amor Factory — Upgrade Database</h1>
<p>Login sebagai: <strong><?= esc((string) $_SESSION['username']) ?></strong>. Database: <code><?= esc($dbName) ?></code>.
Tabel bisnis saat ini: <?= $businessTableCount ?>.</p>

<?php if ($actionResult !== null): ?>
<div class="<?= $actionResult['ok'] ? 'result-ok' : 'result-error' ?>">
  <strong><?= esc($actionResult['title']) ?></strong>
  <p><?= esc($actionResult['message']) ?></p>
</div>
<?php endif; ?>

<div class="box">
  <p><strong>Sudah diterapkan (<?= count($applied) ?>):</strong></p>
  <ul><?php foreach ($applied as $m): ?><li><?= esc($m) ?></li><?php endforeach; ?></ul>

  <p><strong>Menunggu diterapkan (<?= count($pending) ?>):</strong></p>
  <?php if ($pending === []): ?>
    <p>Tidak ada. Database sudah versi terbaru.</p>
  <?php else: ?>
    <ul><?php foreach ($pending as $m): ?><li><?= esc($m) ?></li><?php endforeach; ?></ul>
    <div class="warn"><p>Migrasi hanya menambah struktur (kolom/index baru) — tidak pernah menghapus tabel atau data.</p></div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
      <input type="hidden" name="action" value="apply">
      <label><input type="checkbox" name="confirm" value="1" required> Saya ingin menerapkan migrasi di atas sekarang.</label>
      <button type="submit">Terapkan Migrasi</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
