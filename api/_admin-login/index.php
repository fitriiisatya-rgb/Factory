<?php

declare(strict_types=1);

/**
 * Amor Factory — temporary Phase 1 admin login page.
 *
 * The EXISTING Amor Factory frontend (Apps Script/Google Sheets) has not
 * been cut over to this PHP/MySQL backend's session auth at all — it has
 * no code path that ever calls POST /api/auth/login. So an operator opening
 * api/_upgrade/ or api/_import-master/ for the first time has no session to
 * reuse. This page is that missing first step: a plain HTML form in front
 * of the SAME Amor\Api\Auth service every other part of this codebase uses
 * (password_hash/password_verify, session_regenerate_id, Secure/HttpOnly/
 * SameSite cookie) — it does not implement any auth logic of its own.
 *
 * TEMPORARY, like api/_import-master/. Delete this directory once Phase 1
 * is done. api/_upgrade/ may stay, but without this page nothing can log
 * in to use it until the real frontend gets its own PHP-auth login screen
 * in a later phase.
 *
 * Rate limiting note: there is no real IP-based throttling here (building
 * one would mean adding new infrastructure this task explicitly says not
 * to — "Do NOT create another auth system"). What's below is a session-
 * scoped speed bump (a growing delay after repeated failures within the
 * SAME browser session) — a deterrent, not a security control.
 * Amor\Api\Auth itself has no built-in throttling to hook into.
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

if ((bool) Config::get('SESSION_SECURE') && ($_SERVER['HTTPS'] ?? '') === '' && (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https')) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
        . '<h1>HTTPS diperlukan</h1><p>Halaman login ini hanya boleh dibuka lewat HTTPS '
        . '(SESSION_SECURE=true mengharuskan koneksi aman untuk cookie sesi).</p></body></html>';
    exit;
}

Auth::bootSession();

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

function renderLoginForm(?string $error = null): void
{
    $errorBlock = $error !== null ? '<p style="color:#b00;font-weight:bold;">' . htmlspecialchars($error) . '</p>' : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<title>Amor Factory — Login Admin (Phase 1)</title>
<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:420px;margin:3rem auto;padding:0 1rem;}
label{display:block;margin:.6rem 0;} input{width:100%;padding:.5rem;box-sizing:border-box;}
button{padding:.6rem 1.4rem;background:#0a5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:bold;margin-top:1rem;}</style>
</head><body>
<h1>Login Admin — Phase 1 Tools</h1>
<p style="color:#666;">Halaman sementara. Gunakan akun ADMIN yang sudah dibuat sewaktu Phase 0.5.</p>
{$errorBlock}
<form method="post">
  <input type="hidden" name="action" value="login">
  <label>Username <input type="text" name="username" required autocomplete="username"></label>
  <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
  <button type="submit">Login</button>
</form>
</body></html>
HTML;
}

function renderLoggedIn(string $username): void
{
    echo <<<HTML
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<title>Amor Factory — Login Admin (Phase 1)</title>
<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:420px;margin:3rem auto;padding:0 1rem;}
a.btn{display:block;padding:.7rem 1rem;margin:.6rem 0;background:#0a5;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;text-align:center;}
form.logout button{padding:.5rem 1rem;background:#b00;color:#fff;border:none;border-radius:6px;cursor:pointer;margin-top:1.5rem;}</style>
</head><body>
<h1 style="color:#080;">Login berhasil sebagai ADMIN</h1>
<p>Masuk sebagai: <strong>{$username}</strong></p>
<a class="btn" href="../_upgrade/">Upgrade Database</a>
<a class="btn" href="../_import-master/">Import Master</a>
<form class="logout" method="post"><input type="hidden" name="action" value="logout"><button type="submit">Logout</button></form>
</body></html>
HTML;
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';

if ($action === 'logout') {
    Auth::logout();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:sans-serif;max-width:420px;margin:3rem auto;">'
        . '<h1>Sudah logout</h1><p><a href="./">Login lagi</a></p></body></html>';
    exit;
}

if ($action === 'login') {
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    $lastAttempt = (int) ($_SESSION['login_last_attempt'] ?? 0);
    $requiredWait = $attempts >= 5 ? min(30, ($attempts - 4) * 5) : 0;

    header('Content-Type: text/html; charset=utf-8');

    if ($requiredWait > 0 && (time() - $lastAttempt) < $requiredWait) {
        $remaining = $requiredWait - (time() - $lastAttempt);
        renderLoginForm("Terlalu banyak percobaan gagal. Coba lagi dalam {$remaining} detik.");
        exit;
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $_SESSION['login_last_attempt'] = time();

    try {
        Auth::attemptLogin($username, $password);
    } catch (ApiException $e) {
        $_SESSION['login_attempts'] = $attempts + 1;
        renderLoginForm('Username atau password salah.');
        exit;
    }

    unset($_SESSION['login_attempts'], $_SESSION['login_last_attempt']);

    if (!in_array('ADMIN', Auth::currentRoles(), true)) {
        $notAdminUsername = (string) $_SESSION['username'];
        Auth::logout();
        renderLoginForm("Akun '{$notAdminUsername}' login berhasil, tapi bukan ADMIN. Halaman Phase 1 hanya untuk ADMIN.");
        exit;
    }

    renderLoggedIn(esc((string) $_SESSION['username']));
    exit;
}

// GET (or anything else): show current state.
header('Content-Type: text/html; charset=utf-8');
if (Auth::currentUserId() !== null && in_array('ADMIN', Auth::currentRoles(), true)) {
    renderLoggedIn(esc((string) $_SESSION['username']));
} else {
    renderLoginForm();
}
