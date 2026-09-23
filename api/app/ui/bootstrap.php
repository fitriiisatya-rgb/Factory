<?php

declare(strict_types=1);

/**
 * Shared bootstrap for every /api/_ui-preview/ page. Reuses the EXISTING
 * session/CSRF/role model exactly (Amor\Api\Auth, Amor\Api\Csrf) — no
 * parallel auth mechanism, no bearer token, nothing in localStorage that
 * isn't a pure UI preference (see assets/js/app.js's own docblock).
 *
 * Sets $ui = ['pdo'=>PDO,'userId'=>int,'username'=>string,'fullName'=>string,
 * 'roles'=>string[],'csrfToken'=>string] for the calling page.
 */

require_once __DIR__ . '/../autoload.php';

use Amor\Api\Auth;
use Amor\Api\Config;
use Amor\Api\Database;

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

if (Auth::currentUserId() === null) {
    $return = urlencode($_SERVER['REQUEST_URI'] ?? '/api/_ui-preview/');
    header('Location: /api/_admin-login/?return=' . $return);
    exit;
}

$ui = [
    'pdo' => Database::pdo(),
    'userId' => (int) Auth::currentUserId(),
    'username' => (string) ($_SESSION['username'] ?? ''),
    'fullName' => (string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''),
    'roles' => Auth::currentRoles(),
    'csrfToken' => (string) ($_SESSION['csrf_token'] ?? ''),
];

function ui_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES);
}

function ui_fmt_num(float $n): string
{
    return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
}

/**
 * Money display formatter ("Rp46.000" style, task's own approved format).
 * Display-only — storage stays a plain numeric column, never this string.
 */
function ui_fmt_money(float $n): string
{
    return 'Rp' . number_format($n, 0, ',', '.');
}

function ui_fmt_pct(float $numerator, float $denominator): int
{
    if ($denominator <= 0.0001) {
        return 0;
    }
    return (int) round(min(100, max(0, ($numerator / $denominator) * 100)));
}

/**
 * Display-only formatter: a DATETIME column (always stored as UTC per
 * this app's UTC_TIMESTAMP() convention) rendered Indonesian-friendly in
 * Asia/Jakarta — "21 Sep 2026 · 10:20" instead of the raw
 * "2026-09-21 03:20:00". Never changes what is stored; every caller keeps
 * writing/reading UTC exactly as before. Returns '-' for null/empty.
 */
function ui_fmt_datetime_id(?string $utcDatetime): string
{
    if ($utcDatetime === null || $utcDatetime === '') {
        return '-';
    }
    try {
        $dt = new \DateTime($utcDatetime, new \DateTimeZone('UTC'));
        $dt->setTimezone(new \DateTimeZone('Asia/Jakarta'));
    } catch (\Throwable $e) {
        return $utcDatetime;
    }
    static $bulan = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
    return $dt->format('j') . ' ' . $bulan[(int) $dt->format('n')] . ' ' . $dt->format('Y') . ' · ' . $dt->format('H:i');
}
