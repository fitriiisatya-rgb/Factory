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
 *
 * Migration 0019 (Shared Division Login): no longer ADMIN-only — PPIC,
 * PRODUCTION and FG_PACKING accounts log in here too (ALLOWED_ROLES). A PRODUCTION shared
 * division account is then asked "Siapa Anda?" (Auth::needsOperatorName())
 * before being sent back to ?return= (only /api/_ui-preview/... targets are
 * honoured — see safeReturnUrl()). Every ADMIN-only tool (_upgrade,
 * _import-po, _import-master, the *-uat tools, _users-uat) keeps its OWN
 * ADMIN role check, so
 * widening this login page does not widen access to any of them.
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

const ALLOWED_ROLES = ['ADMIN', 'PPIC', 'PRODUCTION', 'FG_PACKING', 'ATASAN'];

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

/**
 * Only ever redirects back into the Admin UI shell — anything else
 * (absolute URLs, protocol-relative "//host", other paths) falls back to
 * the shell's root, so ?return= can never become an open redirect.
 */
function safeReturnUrl(?string $raw): ?string
{
    $raw = (string) $raw;
    // "/" (and "/?page=…") is the same shell served from the domain root (index.php at the docroot).
    $isShell = str_starts_with($raw, '/api/_ui-preview/') || $raw === '/' || str_starts_with($raw, '/?');
    if ($raw === '' || !$isShell || str_contains($raw, "\\") || preg_match('/[\r\n]/', $raw)) {
        return null;
    }
    return $raw;
}

const LOGIN_ASSET_VERSION = '20261025';

/** Inline line icons for the login shell (no external icon font). */
function loginSvg(string $name): string
{
    $paths = [
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><path d="M12 15v2"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
        'server' => '<rect x="4" y="4" width="16" height="7" rx="1.5"/><rect x="4" y="13" width="16" height="7" rx="1.5"/><path d="M8 7.5h3M8 16.5h3"/>',
        'chart' => '<path d="M5 20V12M10 20V6M15 20v-9M20 20V4"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

/**
 * Shared landing shell for every screen of this page (login, "Siapa
 * Anda?", logged-in, logged-out): top bar, blueprint background, the
 * card ($cardHtml), the FA mascot (desktop only) and footer. Static
 * assets live under the public api/assets/, never the deny-all api/app/.
 */
function renderShell(string $title, string $cardHtml, string $bubbleSmall = 'Siap produksi hari ini?'): void
{
    $v = LOGIN_ASSET_VERSION;
    $titleEsc = esc($title);
    $bubbleEsc = esc($bubbleSmall);
    $year = date('Y');
    $chart = loginSvg('chart');
    echo <<<HTML
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>{$titleEsc}</title>
<link rel="stylesheet" href="/api/assets/css/login.css?v={$v}">
</head><body class="lg">
<header class="lg-top">
  <span class="lg-icon">📦</span>
  <div><div class="lg-top-title">Amor Factory System</div><div class="lg-top-sub">By FA — Amor Cakes &amp; Bakery</div></div>
  <div class="lg-top-spacer"></div>
  <span class="lg-badge">FACTORY</span>
  <span class="lg-top-note">Sistem Produksi &amp; Distribusi</span>
</header>
<main class="lg-stage">
  <div class="lg-tagline">PRODUKSI TERKENDALI<br>DISTRIBUSI LEBIH CEPAT</div>
  <div class="lg-wrap">
    <div class="lg-card">
{$cardHtml}
    </div>
    <div class="lg-mascot">
      <img src="/api/assets/img/fa-mascot.png?v={$v}" alt="Maskot FA" width="640" height="640">
      <div class="lg-bubble">Halo!<small>{$bubbleEsc}</small></div>
    </div>
  </div>
</main>
<footer class="lg-foot">
  <div><b>Amor Factory System &copy; {$year}</b><br>Satu Hati, Efisiensi</div>
  <div style="display:flex;align-items:center;gap:10px;"><span style="width:22px;height:22px;display:inline-flex;">{$chart}</span>Akurat &bull; Terstruktur &bull; Bertumbuh</div>
</footer>
</body></html>
HTML;
}

function cardHead(string $subtitle): string
{
    return '<span class="lg-icon">📦</span>'
        . '<h1 class="lg-title">Amor <span>Factory</span></h1>'
        . '<p class="lg-sub">' . $subtitle . '</p>';
}

function alertBlock(?string $message, string $kind = 'error'): string
{
    return $message !== null ? '<div class="lg-alert lg-alert-' . $kind . '">' . htmlspecialchars($message) . '</div>' : '';
}

function renderLoginForm(?string $error = null, ?string $return = null): void
{
    $returnField = $return !== null ? '<input type="hidden" name="return" value="' . esc($return) . '">' : '';
    $user = loginSvg('user');
    $lock = loginSvg('lock');
    $shield = loginSvg('shield');
    $server = loginSvg('server');
    $card = cardHead('Kelola produksi, FG, dan pengiriman dengan lebih mudah, cepat, dan efisien.')
        . '<p class="lg-kicker">Login Admin / Divisi</p>'
        . alertBlock($error)
        . <<<HTML
<form method="post">
  <input type="hidden" name="action" value="login">
  {$returnField}
  <div class="lg-field"><label for="lg-username">Username</label>
    <div class="lg-input">{$user}<input id="lg-username" type="text" name="username" required autocomplete="username" autofocus></div></div>
  <div class="lg-field"><label for="lg-password">Password</label>
    <div class="lg-input">{$lock}<input id="lg-password" type="password" name="password" required autocomplete="current-password"></div></div>
  <button type="submit" class="lg-btn">Masuk</button>
</form>
<div class="lg-or">untuk</div>
<div class="lg-trust">
  <div>{$shield}<span><b>Secure Login</b><small>Data Anda Terlindungi</small></span></div>
  <div>{$server}<span><b>Login Divisi</b><small>Admin, Divisi &amp; FG</small></span></div>
</div>
HTML;
    renderShell('Amor Factory — Login', $card);
}

/**
 * Names this shared account used in the last 60 days (newest first), so
 * the person taps their name instead of typing it every login.
 * @return string[]
 */
function recentOperatorNames(): array
{
    try {
        $st = \Amor\Api\Database::pdo()->prepare(
            "SELECT payload_summary FROM audit_log WHERE user_id = ? AND event_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)
               AND payload_summary LIKE '%operatorName%' ORDER BY audit_log_id DESC LIMIT 400"
        );
        $st->execute([(int) ($_SESSION['user_id'] ?? 0)]);
        $names = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $n = trim((string) (json_decode((string) $json, true)['operatorName'] ?? ''));
            if (mb_strlen($n) >= 2 && !in_array(mb_strtolower($n), array_map('mb_strtolower', $names), true)) {
                $names[] = $n;
            }
            if (count($names) >= 10) {
                break;
            }
        }
        return $names;
    } catch (\Throwable) {
        return [];
    }
}

/** "Siapa Anda?" — shared division accounts name the actual person (migration 0019). */
function renderOperatorForm(?string $return, ?string $error = null, string $prefill = ''): void
{
    $returnField = $return !== null ? '<input type="hidden" name="return" value="' . esc($return) . '">' : '';
    $csrf = esc((string) ($_SESSION['csrf_token'] ?? ''));
    $account = esc((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
    $prefillEsc = esc($prefill);
    $user = loginSvg('user');
    $chips = '';
    foreach (recentOperatorNames() as $nm) {
        $chips .= '<button type="submit" name="operatorName" value="' . esc($nm) . '" class="lg-chip">' . esc($nm) . '</button>';
    }
    $chipBlock = $chips !== ''
        ? '<form method="post" class="lg-field"><input type="hidden" name="action" value="set_operator_name"><input type="hidden" name="csrf_token" value="' . $csrf . '">' . $returnField
          . '<label>Ketuk nama Anda</label><div class="lg-chips">' . $chips . '</div></form><div class="lg-or">atau ketik nama baru</div>'
        : '';
    $autofocus = $chips === '' ? ' autofocus' : '';
    $card = '<span class="lg-icon">📦</span>'
        . '<h1 class="lg-title">Siapa Anda?</h1>'
        . "<p class=\"lg-sub\">Akun <strong>{$account}</strong> dipakai bersama oleh satu divisi. Pilih atau ketik nama Anda — nama ini dicatat di setiap input dan submit selama sesi ini.</p>"
        . alertBlock($error)
        . $chipBlock
        . <<<HTML
<form method="post">
  <input type="hidden" name="action" value="set_operator_name">
  <input type="hidden" name="csrf_token" value="{$csrf}">
  {$returnField}
  <div class="lg-field"><label for="lg-operator">Nama Anda</label>
    <div class="lg-input">{$user}<input id="lg-operator" type="text" name="operatorName" value="{$prefillEsc}" required maxlength="150" autocomplete="name"{$autofocus}></div></div>
  <button type="submit" class="lg-btn">Lanjut</button>
</form>
<div class="lg-or">bukan akun Anda?</div>
<form class="logout" method="post"><input type="hidden" name="action" value="logout"><button type="submit" class="lg-btn lg-btn-danger">Logout</button></form>
HTML;
    renderShell('Amor Factory — Siapa Anda?', $card, 'Siapa yang bertugas hari ini?');
}

function renderLoggedIn(string $username): void
{
    $card = cardHead('Login berhasil sebagai ADMIN')
        . "<p class=\"lg-kicker\">Masuk sebagai {$username}</p>"
        . <<<HTML
<div class="lg-links">
  <a class="lg-btn" href="../_ui-preview/">Buka Amor Factory</a>
  <a class="lg-btn lg-btn-ghost" href="../_upgrade/">Upgrade Database</a>
  <a class="lg-btn lg-btn-ghost" href="../_import-master/">Import Master</a>
  <a class="lg-btn lg-btn-ghost" href="../_users-uat/">Kelola User</a>
</div>
<div class="lg-or">selesai?</div>
<form class="logout" method="post"><input type="hidden" name="action" value="logout"><button type="submit" class="lg-btn lg-btn-danger">Logout</button></form>
HTML;
    renderShell('Amor Factory — Login Admin', $card);
}

function renderLoggedOut(): void
{
    $card = cardHead('Sesi Anda sudah berakhir.')
        . alertBlock('Sudah logout', 'ok')
        . '<a class="lg-btn" href="./">Login lagi</a>';
    renderShell('Amor Factory — Logout', $card, 'Sampai jumpa lagi!');
}

/** Where a logged-in, fully-identified user goes next. */
function finishLogin(?string $return): void
{
    if ($return !== null) {
        header('Location: ' . $return, true, 302);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    if (in_array('ADMIN', Auth::currentRoles(), true) && !Auth::isFactoryAdmin()) {
        renderLoggedIn(esc((string) $_SESSION['username']));
        exit;
    }
    header('Location: /api/_ui-preview/', true, 302);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $method === 'POST' ? (string) ($_POST['action'] ?? '') : (string) ($_GET['action'] ?? '');
$return = safeReturnUrl($method === 'POST' ? ($_POST['return'] ?? null) : ($_GET['return'] ?? null));
$loggedIn = Auth::currentUserId() !== null && array_intersect(ALLOWED_ROLES, Auth::currentRoles()) !== [];

if ($method === 'POST' && $action === 'logout') {
    Auth::logout();
    header('Content-Type: text/html; charset=utf-8');
    renderLoggedOut();
    exit;
}

if ($method === 'POST' && $action === 'set_operator_name') {
    header('Content-Type: text/html; charset=utf-8');
    if (!$loggedIn) {
        renderLoginForm(null, $return);
        exit;
    }
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        renderOperatorForm($return, 'Sesi tidak valid. Silakan coba lagi.');
        exit;
    }
    $name = trim((string) ($_POST['operatorName'] ?? ''));
    if (mb_strlen($name) < 2) {
        renderOperatorForm($return, 'Nama wajib diisi (minimal 2 huruf).', $name);
        exit;
    }
    Auth::setOperatorName($name);
    finishLogin($return);
}

if ($method === 'POST' && $action === 'login') {
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    $lastAttempt = (int) ($_SESSION['login_last_attempt'] ?? 0);
    $requiredWait = $attempts >= 5 ? min(30, ($attempts - 4) * 5) : 0;

    header('Content-Type: text/html; charset=utf-8');

    if ($requiredWait > 0 && (time() - $lastAttempt) < $requiredWait) {
        $remaining = $requiredWait - (time() - $lastAttempt);
        renderLoginForm("Terlalu banyak percobaan gagal. Coba lagi dalam {$remaining} detik.", $return);
        exit;
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $_SESSION['login_last_attempt'] = time();

    try {
        Auth::attemptLogin($username, $password);
    } catch (ApiException $e) {
        $_SESSION['login_attempts'] = $attempts + 1;
        renderLoginForm('Username atau password salah.', $return);
        exit;
    }

    unset($_SESSION['login_attempts'], $_SESSION['login_last_attempt']);

    if (array_intersect(ALLOWED_ROLES, Auth::currentRoles()) === []) {
        $rejectedUsername = (string) $_SESSION['username'];
        Auth::logout();
        renderLoginForm("Akun '{$rejectedUsername}' login berhasil, tapi bukan ADMIN, PPIC, akun divisi Produksi, atau akun FG. Driver login lewat Portal Driver.");
        exit;
    }

    if (Auth::needsOperatorName()) {
        renderOperatorForm($return);
        exit;
    }
    finishLogin($return);
}

// GET (or anything else): show current state.
header('Content-Type: text/html; charset=utf-8');
if (!$loggedIn) {
    renderLoginForm(null, $return);
    exit;
}
if ($action === 'change_operator' || Auth::needsOperatorName()) {
    // "(ganti)" from the topbar: show the form again (prefilled). Nothing
    // changes until the form is POSTed, so a GET can never alter state.
    $isProductionShared = in_array('PRODUCTION', Auth::currentRoles(), true) && array_intersect(['ADMIN', 'PPIC'], Auth::currentRoles()) === [];
    if ($isProductionShared) {
        renderOperatorForm($return, null, (string) (Auth::currentOperatorName() ?? ''));
        exit;
    }
}
if (in_array('ADMIN', Auth::currentRoles(), true) && !Auth::isFactoryAdmin() && $return === null) {
    renderLoggedIn(esc((string) $_SESSION['username']));
    exit;
}
finishLogin($return);
