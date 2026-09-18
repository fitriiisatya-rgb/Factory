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
    $errorBlock = $error !== null ? '<p class="rc-error-math" style="display:block;">' . driver_login_esc($error) . '</p>' : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Login Driver — Amor Factory</title>
<link rel="stylesheet" href="/api/assets/css/receipt.css">
</head><body class="rc-body">
<div class="rc-shell">
  <div class="rc-header">
    <img src="/api/assets/img/amor-logo.png" alt="Amor" width="36" height="36">
    <div><div class="rc-header-title">Login Driver</div><div class="rc-header-sub">Amor Factory System</div></div>
  </div>
  <div class="rc-card">
    {$errorBlock}
    <form method="post">
      <input type="hidden" name="return" value="{$return}">
      <div class="rc-field"><label>Username</label><input type="text" name="username" required autocomplete="username"></div>
      <div class="rc-field"><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div>
      <button type="submit" class="rc-btn primary">Masuk</button>
    </form>
  </div>
</div>
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
