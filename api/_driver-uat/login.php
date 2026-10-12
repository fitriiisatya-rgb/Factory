<?php

declare(strict_types=1);

/**
 * Driver portal login — mirrors api/_admin-login/index.php's own pattern
 * (same Amor\Api\Auth service, same password_hash/session/CSRF machinery,
 * no parallel auth system), but gates on the DRIVER (or ADMIN) role
 * instead of ADMIN-only, since api/_admin-login/ deliberately rejects any
 * non-ADMIN account and is documented as staying that way.
 *
 * All links here are RELATIVE (login.php, index.php — never an absolute
 * /api/... path), so this page works unmodified whether api/ is the site
 * docroot itself (local dev/test harness) or a real subdirectory of it
 * (production cPanel deployment) — see this project's own docroot-
 * ambiguity note in api/tests/_ui_router.php for the background.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Config;

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

function driver_login_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES);
}

function driver_login_render_form(?string $error = null): void
{
    $return = driver_login_esc((string) ($_GET['return'] ?? $_POST['return'] ?? 'index.php'));
    $errorBlock = $error !== null ? '<div class="lg-alert lg-alert-error">' . driver_login_esc($error) . '</div>' : '';
    // Same look as api/_admin-login/ (login.css), compact for a phone.
    echo <<<HTML
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0a1846">
<title>Login Driver — Amor Factory</title>
<link rel="stylesheet" href="/api/assets/css/login.css?v=20261013-mobile">
</head><body class="lg">
<header class="lg-top">
  <span class="lg-icon">&#128230;</span>
  <div><div class="lg-top-title">Amor Factory System</div><div class="lg-top-sub">Portal Driver</div></div>
</header>
<main class="lg-stage">
  <div class="lg-card">
    <span class="lg-icon">&#128666;</span>
    <h1 class="lg-title">Login <span>Driver</span></h1>
    <p class="lg-sub">Ambil tugas kirim, cek muatan, lalu konfirmasi berangkat.</p>
    {$errorBlock}
    <form method="post">
      <input type="hidden" name="return" value="{$return}">
      <div class="lg-field"><label for="drv-username">Username</label>
        <div class="lg-input"><input id="drv-username" type="text" name="username" required autocomplete="username" style="padding-left:14px;"></div></div>
      <div class="lg-field"><label for="drv-password">Password</label>
        <div class="lg-input"><input id="drv-password" type="password" name="password" required autocomplete="current-password" style="padding-left:14px;"></div></div>
      <button type="submit" class="lg-btn">Masuk</button>
    </form>
  </div>
</main>
</body></html>
HTML;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $return = (string) ($_POST['return'] ?? 'index.php');
    // Only ever redirect within this same directory — never an
    // attacker-supplied absolute/external URL (open-redirect guard).
    if (!preg_match('#^[a-zA-Z0-9_\-.]+\.php(\?[^\s]*)?$#', $return)) {
        $return = 'index.php';
    }

    header('Content-Type: text/html; charset=utf-8');
    try {
        Auth::attemptLogin($username, $password);
    } catch (ApiException $e) {
        driver_login_render_form('Username atau password salah.');
        exit;
    }

    if (array_intersect(['DRIVER', 'ADMIN'], Auth::currentRoles()) === []) {
        Auth::logout();
        driver_login_render_form('Akun ini bukan Driver. Halaman ini hanya untuk akun dengan peran Driver.');
        exit;
    }

    header('Location: ' . $return);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
if (Auth::currentUserId() !== null && array_intersect(['DRIVER', 'ADMIN'], Auth::currentRoles()) !== []) {
    header('Location: index.php');
    exit;
}
driver_login_render_form();
