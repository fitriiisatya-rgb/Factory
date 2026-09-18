<?php

declare(strict_types=1);

/**
 * Driver portal bootstrap — reuses the EXISTING session/CSRF/role model
 * exactly like every other page (api/app/ui/bootstrap.php), then narrows
 * access to the DRIVER role (ADMIN included so an office user can preview
 * the flow). This is a MOBILE-FIRST preview, deliberately NOT wired into
 * the main sidebar app shell (task's own "Do NOT expose it as production
 * main UI yet").
 */

require_once __DIR__ . '/../app/autoload.php';

use Amor\Api\Auth;
use Amor\Api\Config;

try {
    Config::load();
} catch (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:sans-serif;max-width:640px;margin:2rem auto;">'
        . '<h1>Konfigurasi belum lengkap</h1><p>' . htmlspecialchars($e->getMessage()) . '</p></body></html>';
    exit;
}

Auth::bootSession();

// Redirect to THIS portal's own login.php (relative path) — never
// api/_admin-login/, which deliberately rejects any non-ADMIN account
// (a pure DRIVER user could never log in there at all).
if (Auth::currentUserId() === null) {
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $return = $script . ($qs !== '' ? '?' . $qs : '');
    header('Location: login.php?return=' . urlencode($return));
    exit;
}

// Now that we KNOW a session exists, the shared app/ui/bootstrap.php's own
// "redirect if not logged in" check is a guaranteed no-op — safe to reuse
// it purely for $ui/ui_esc()/ui_fmt_num() without it ever mis-redirecting
// a driver toward the ADMIN-only login page.
require __DIR__ . '/../app/ui/bootstrap.php';
require_once __DIR__ . '/../app/ui/components.php';

$driverRoles = ['DRIVER', 'ADMIN'];
if (array_intersect($driverRoles, $ui['roles']) === []) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Akses ditolak</title></head><body style="font-family:sans-serif;max-width:420px;margin:3rem auto;padding:0 1rem;">'
        . '<h1>Akses ditolak</h1><p>Halaman ini hanya untuk akun dengan peran Driver.</p></body></html>';
    exit;
}

/**
 * Opens the mobile driver-portal HTML shell: header + <main> + bottom tab
 * bar. $active is one of tersedia/saya/rute/riwayat. Every link in this
 * file and driver.js is a RELATIVE path within api/_driver-uat/ (never an
 * absolute /api/... path) — this page is reached from both index.php and
 * stop.php, and an absolute path would additionally break under this
 * project's local test harness, where the api/ folder itself is the
 * docroot (see api/tests/_ui_router.php's own docroot-ambiguity note).
 */
function driver_page_head(array $ui, string $active, string $title): void
{
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= ui_esc($title) ?> — Amor Factory Driver</title>
<link rel="stylesheet" href="/api/assets/css/tokens.css">
<link rel="stylesheet" href="/api/assets/css/driver.css">
</head>
<body class="driver-body">
<div class="driver-shell">
  <header class="driver-topbar">
    <img class="driver-topbar-logo" src="/api/assets/img/amor-logo.png" alt="Amor" width="28" height="28">
    <div class="driver-topbar-title"><?= ui_esc($title) ?></div>
    <div class="driver-topbar-user"><?= ui_esc($ui['fullName'] !== '' ? $ui['fullName'] : $ui['username']) ?></div>
  </header>
  <main class="driver-main">
<?php
}

function driver_page_foot(string $active): void
{
    $tabs = [
        ['key' => 'tersedia', 'label' => 'Tersedia', 'icon' => 'box'],
        ['key' => 'saya', 'label' => 'Pengiriman Saya', 'icon' => 'truck'],
        ['key' => 'rute', 'label' => 'Rute Saya', 'icon' => 'file'],
        ['key' => 'riwayat', 'label' => 'Riwayat', 'icon' => 'chart'],
    ];
    ?>
  </main>
  <nav class="driver-tabbar">
    <?php foreach ($tabs as $t): ?>
    <a class="driver-tab<?= $active === $t['key'] ? ' active' : '' ?>" href="index.php?tab=<?= $t['key'] ?>">
      <span class="driver-tab-icon"><?= ui_icon($t['icon']) ?></span>
      <span class="driver-tab-label"><?= ui_esc($t['label']) ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
</div>
<script>window.AMOR = <?= json_encode(['csrfToken' => $GLOBALS['ui']['csrfToken'] ?? '', 'userId' => $GLOBALS['ui']['userId'] ?? null, 'username' => $GLOBALS['ui']['username'] ?? ''], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/api/assets/js/app.js"></script>
<script src="/api/assets/js/driver.js"></script>
</body>
</html>
<?php
}
