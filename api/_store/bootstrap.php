<?php

declare(strict_types=1);

/**
 * Permanent Bakery Portal bootstrap (migration 0017) — PUBLIC, no
 * session/login. Access control is entirely the permanent, high-entropy
 * ?token= query parameter, resolved via StorePortalService::
 * resolvePortalIdentity() — the ONLY place this page ever learns which
 * store it is serving. Every subsequent page link/form in this portal
 * carries the SAME token forward as its own ?token= — this bootstrap
 * never stores it in a session/cookie (there is no session at all here),
 * matching api/_receive/'s own "token IS the access control" discipline,
 * just widened from a single DO/shipment token to a permanent per-store
 * one.
 *
 * Mobile-first, dark navy shell: reuses the EXISTING design tokens
 * (api/assets/css/tokens.css, dark theme is the default) and the EXISTING
 * Driver Portal's own card/button/tab-bar component vocabulary
 * (api/assets/css/driver.css) as its visual foundation — task's own
 * explicit "reuse existing cards/buttons/components... no new theme".
 * store.css (loaded by store_page_head() below) adds ONLY the handful of
 * rules driver.css has no equivalent for (store identity header, incoming/
 * outgoing split, evidence thumbnail grid — itself a dark-navy port of
 * receipt.css's own proven evidence-upload markup).
 */

require_once __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\StorePortal\StorePortalService;

try {
    Config::load();
} catch (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:sans-serif;max-width:480px;margin:2rem auto;">'
        . '<h1>Konfigurasi belum lengkap</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p></body></html>';
    exit;
}

function store_esc(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

$storeToken = (string) ($_GET['token'] ?? '');
$storeIdentity = null;
$storeErrorMessage = null;

if ($storeToken === '') {
    $storeErrorMessage = 'Tautan tidak valid — parameter token tidak ditemukan.';
} else {
    try {
        $storeIdentity = (new StorePortalService(Database::pdo()))->resolvePortalIdentity($storeToken);
    } catch (ApiException $e) {
        $storeErrorMessage = $e->getMessage();
    }
}

require_once __DIR__ . '/../app/ui/components.php'; // ui_icon() only — pure function, no session/$ui dependency

/**
 * Cache-busting query string for store.css/store.js — same pattern as
 * DRIVER_ASSET_VERSION/RECEIPT_ASSET_VERSION. Bump on every patch that
 * touches either file.
 */
const STORE_ASSET_VERSION = '20261007-initial';

/**
 * Opens the mobile Portal HTML shell: header (bakery name, prominent per
 * task's own "Home shows bakery name prominently") + <main> + bottom tab
 * bar. $active is one of konfirmasi/khusus/retur/mutasi/riwayat. Every
 * tab link carries the token forward (?token=...&tab=...) — there is no
 * other way this portal remembers who it's serving between page loads.
 */
function store_page_head(string $token, string $storeName, string $active, string $title): void
{
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= store_esc($title) ?> — Portal Bakery Amor</title>
<link rel="stylesheet" href="/api/assets/css/tokens.css?v=<?= STORE_ASSET_VERSION ?>">
<link rel="stylesheet" href="/api/assets/css/driver.css?v=<?= STORE_ASSET_VERSION ?>">
<link rel="stylesheet" href="/api/assets/css/store.css?v=<?= STORE_ASSET_VERSION ?>">
</head>
<body class="driver-body store-body">
<div class="driver-shell">
  <header class="driver-topbar store-topbar">
    <img class="driver-topbar-logo" src="/api/assets/img/amor-logo.png" alt="Amor" width="28" height="28">
    <div class="store-topbar-text">
      <div class="store-topbar-store"><?= store_esc($storeName) ?></div>
      <div class="driver-topbar-title store-topbar-title"><?= store_esc($title) ?></div>
    </div>
  </header>
  <main class="driver-main">
<?php
}

function store_page_foot(string $token, string $active): void
{
    $tabs = [
        ['key' => 'konfirmasi', 'label' => 'Konfirmasi', 'icon' => 'truck'],
        ['key' => 'khusus', 'label' => 'Pesanan Khusus', 'icon' => 'cart'],
        ['key' => 'retur', 'label' => 'Retur', 'icon' => 'box'],
        ['key' => 'mutasi', 'label' => 'Mutasi', 'icon' => 'bolt'],
        ['key' => 'riwayat', 'label' => 'Riwayat', 'icon' => 'chart'],
    ];
    ?>
  </main>
  <nav class="driver-tabbar">
    <?php foreach ($tabs as $t): ?>
    <a class="driver-tab<?= $active === $t['key'] ? ' active' : '' ?>" href="index.php?token=<?= urlencode($token) ?>&tab=<?= $t['key'] ?>">
      <span class="driver-tab-icon"><?= ui_icon($t['icon']) ?></span>
      <span class="driver-tab-label"><?= store_esc($t['label']) ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
</div>
<script src="/api/assets/js/app.js?v=<?= STORE_ASSET_VERSION ?>"></script>
<script src="/api/assets/js/store.js?v=<?= STORE_ASSET_VERSION ?>"></script>
</body>
</html>
<?php
}
