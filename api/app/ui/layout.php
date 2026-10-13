<?php

declare(strict_types=1);

require_once __DIR__ . '/components.php';
require_once __DIR__ . '/labels.php';

// Cache-busting for tokens.css/app.css/app.js, same pattern as
// RECEIPT_ASSET_VERSION (api/_receive/index.php) and DRIVER_ASSET_VERSION
// (api/_driver-uat/bootstrap.php) — real-UAT hotfix: the Admin layout had
// NO cache-busting anywhere, so a stale cached app.css on a real device
// could keep serving an old version indefinitely, e.g. masking the
// evidence-thumbnail sizing fix. Bump this string whenever any of the
// three assets change.
const ADMIN_ASSET_VERSION = '20261016-logout';

/**
 * Nav item list — the single source of truth for the sidebar. Every item
 * links to /api/_ui-preview/?page=<key>. Role visibility is left
 * permissive for this preview (any authenticated user sees every menu
 * item); the backend's own role checks on every mutating API call remain
 * the real gate — hiding a menu item is a convenience, never a security
 * boundary (task's own "role-ready UI" rule).
 *
 * FINAL PRE-DEPLOY PATCH exception: "Replacement Reject" is the FIRST
 * (and, deliberately, only) nav item this function ever hides, because
 * its own page now carries a REAL server-side 403 gate (api/_ui-preview/
 * index.php's own $pageRoles check) — showing the link to a role that
 * would just get denied is confusing UX, not a security decision (the
 * 403 gate is what actually protects the data either way). $roles
 * defaults to [] (= show everything) so every OTHER existing call site/
 * page keeps its prior, unchanged permissive behavior.
 * @param string[] $roles the current user's roles (Auth::currentRoles()) — [] keeps the old fully-permissive behavior
 * @return array<int,array{key:string,label:string,icon:string}>
 */
function ui_nav_items(array $roles = []): array
{
    $items = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['key' => 'pesanan-toko', 'label' => 'Pesanan Toko', 'icon' => 'cart'],
        ['key' => 'produksi', 'label' => 'Produksi', 'icon' => 'factory'],
        ['key' => 'fg-packing', 'label' => 'FG & Packing', 'icon' => 'box'],
        ['key' => 'stok-sisa', 'label' => 'Stok Sisa', 'icon' => 'database'],
        ['key' => 'delivery-order', 'label' => 'Delivery Order', 'icon' => 'file'],
        ['key' => 'pengiriman', 'label' => 'Pengiriman', 'icon' => 'truck'],
        ['key' => 'konfirmasi-toko', 'label' => 'Konfirmasi Toko', 'icon' => 'user'],
        ['key' => 'replacement-reject', 'label' => 'Reject saat Terima', 'icon' => 'file'],
        ['key' => 'reject-toko', 'label' => 'Reject di Toko', 'icon' => 'alert'],
        ['key' => 'retur-review', 'label' => 'Retur (catatan)', 'icon' => 'box'],
        ['key' => 'mutasi-review', 'label' => 'Mutasi Produk', 'icon' => 'bolt'],
        ['key' => 'bakery-portal-tokens', 'label' => 'Portal Bakery', 'icon' => 'mail'],
        ['key' => 'invoice', 'label' => 'Invoice', 'icon' => 'file'],
    ];
    // Same "hide a link a role would just get denied on" reasoning as the
    // existing replacement-reject hide below — widened to the three
    // Permanent Bakery Portal admin pages (migration 0017), each gated
    // identically to api/_ui-preview/index.php's own $pageRoles.
    if ($roles !== [] && array_intersect(['ADMIN', 'PPIC'], $roles) === []) {
        $hidden = ['replacement-reject', 'reject-toko', 'retur-review', 'mutasi-review', 'bakery-portal-tokens', 'invoice', 'stok-sisa'];
        $items = array_values(array_filter($items, static fn ($i) => !in_array($i['key'], $hidden, true)));
    } elseif ($roles !== [] && !in_array('ADMIN', $roles, true)) {
        // bakery-portal-tokens and invoice are ADMIN-only (token mgmt is
        // more sensitive than Retur/Mutasi review; Invoice generation is
        // ADMIN-only end to end via InvoiceController's own role gate) —
        // a PPIC user keeps the other two.
        $items = array_values(array_filter($items, static fn ($i) => !in_array($i['key'], ['bakery-portal-tokens', 'invoice'], true)));
    }
    // Role-scoped accounts (PRODUCTION shared division logins, FG_PACKING,
    // DRIVER, ...) only see what ui_role_scoped_pages() lets them open.
    $scoped = $roles !== [] ? ui_role_scoped_pages($roles) : null;
    if ($scoped !== null) {
        $items = array_values(array_filter($items, static fn ($i) => in_array($i['key'], $scoped, true)));
        if (in_array('riwayat', $scoped, true)) {
            $items[] = ['key' => 'riwayat', 'label' => 'Riwayat', 'icon' => 'clock'];
        }
    }
    return $items;
}

/**
 * "Kelola User" (migration 0019) links straight to the EXISTING standalone
 * api/_users-uat/ tool via its own 'href' (not the ?page= pattern) and is
 * ADMIN-only — that tool's own server-side ADMIN gate is the real boundary.
 * $roles defaults to [] so any older call site keeps its prior output.
 * @param string[] $roles
 * @return array<int,array{key:string,label:string,icon:string,href?:string}>
 */
function ui_nav_items_secondary(array $roles = []): array
{
    $items = [];
    if ($roles === [] || ui_role_scoped_pages($roles) === null) {
        $items[] = ['key' => 'laporan', 'label' => 'Laporan', 'icon' => 'chart'];
        $items[] = ['key' => 'master-data', 'label' => 'Master Data', 'icon' => 'database'];
    }
    if (in_array('ADMIN', $roles, true)) {
        $items[] = ['key' => 'kelola-user', 'label' => 'Kelola User', 'icon' => 'user', 'href' => '/api/_users-uat/'];
    }
    $items[] = ['key' => 'pengaturan', 'label' => 'Pengaturan', 'icon' => 'settings'];
    return $items;
}

/**
 * Pages a non-ADMIN/PPIC account may open in this shell, or null for
 * ADMIN/PPIC (= every page, each still subject to $pageRoles). Before
 * migration 0019 only ADMIN could log in through api/_admin-login/, but a
 * DRIVER session from api/_driver-uat/login.php already reached every
 * ungated page here (Laporan with omset, Master Data, Pesanan, ...). This
 * list closes that: each role gets only the worksheets it works in.
 * @param string[] $roles
 * @return string[]|null
 */
function ui_role_scoped_pages(array $roles): ?array
{
    if (array_intersect(['ADMIN', 'PPIC'], $roles) !== []) {
        return null;
    }
    $byRole = [
        'PRODUCTION' => ['produksi', 'produksi-demand', 'produksi-task-per-divisi', 'fg-packing', 'fg-khusus-non-toko', 'replacement-do-detail', 'riwayat'],
        'FG_PACKING' => ['fg-packing', 'fg-khusus-non-toko', 'riwayat'],
    ];
    $pages = [];
    foreach ($roles as $role) {
        foreach ($byRole[$role] ?? [] as $page) {
            $pages[] = $page;
        }
    }
    $pages[] = 'pengaturan';
    return array_values(array_unique($pages));
}

/**
 * Opens the HTML document, renders the sidebar + topbar, and opens
 * <main class="page">. Every page must call ui_page_foot() to close it.
 *
 * @param array $ui from bootstrap.php
 * @param array{tanggal?:string,factoryId?:int,factories?:array} $topbarCtx optional date/factory selector state
 */
function ui_page_head(array $ui, string $active, string $title, string $subtitle, array $topbarCtx = []): void
{
    $initials = strtoupper(substr($ui['fullName'] !== '' ? $ui['fullName'] : $ui['username'], 0, 2));
    $primaryRole = $ui['roles'][0] ?? '-';
    $operatorName = \Amor\Api\Auth::currentOperatorName();
    // Carries the current date/factory selection across nav clicks, so
    // switching pages doesn't silently reset the operator's context.
    $navQS = '';
    if (isset($topbarCtx['tanggal'])) {
        $navQS .= '&tanggal=' . urlencode((string) $topbarCtx['tanggal']);
    }
    if (isset($topbarCtx['factoryId'])) {
        $navQS .= '&factoryId=' . (int) $topbarCtx['factoryId'];
    }
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= ui_esc($title) ?> — Amor Factory System</title>
<link rel="stylesheet" href="/api/assets/css/tokens.css?v=<?= ADMIN_ASSET_VERSION ?>">
<link rel="stylesheet" href="/api/assets/css/app.css?v=<?= ADMIN_ASSET_VERSION ?>">
</head>
<body class="<?= ui_role_scoped_pages($ui['roles']) !== null ? 'is-field' : 'is-office' ?>">
<div class="app-shell">
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="sidebar-brand-mark">A</div>
      <div class="sidebar-brand-text">
        <div class="sidebar-brand-title">Amor Factory System</div>
        <div class="sidebar-brand-sub">By FA</div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <?php foreach (ui_nav_items($ui['roles']) as $item): ?>
      <a class="nav-item<?= $active === $item['key'] ? ' active' : '' ?>" href="/api/_ui-preview/?page=<?= $item['key'] ?><?= $navQS ?>">
        <span class="nav-icon"><?= ui_icon($item['icon']) ?></span><span class="nav-label"><?= ui_esc($item['label']) ?></span>
      </a>
      <?php endforeach; ?>
      <div class="sidebar-section-label">Lainnya</div>
      <?php foreach (ui_nav_items_secondary($ui['roles']) as $item): ?>
      <a class="nav-item<?= $active === $item['key'] ? ' active' : '' ?>" href="<?= isset($item['href']) ? ui_esc($item['href']) : '/api/_ui-preview/?page=' . $item['key'] . $navQS ?>">
        <span class="nav-icon"><?= ui_icon($item['icon']) ?></span><span class="nav-label"><?= ui_esc($item['label']) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <span><?= ui_icon('heart') ?></span>
      <strong>Satu Hati</strong>
      <span><?= ui_icon('bolt') ?></span>
      <strong>Efisiensi</strong>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button type="button" class="topbar-toggle" data-action="toggle-sidebar" aria-label="Buka/tutup menu"><?= ui_icon('dashboard') ?></button>
      <span class="topbar-brand" aria-hidden="true"><span class="topbar-brand-mark">&#128230;</span> Amor Factory</span>
      <div class="topbar-search">
        <span class="topbar-search-icon"><?= ui_icon('search') ?></span>
        <input type="search" placeholder="Cari produk, toko, atau nomor dokumen..." title="Pencarian global — segera hadir sepenuhnya; saat ini gunakan filter pada masing-masing halaman.">
      </div>
      <div class="topbar-spacer"></div>
      <div class="topbar-right">
        <?php if (isset($topbarCtx['tanggal'])): ?>
        <span class="select-pill"><?= ui_icon('calendar') ?> <?= ui_esc($topbarCtx['tanggal']) ?></span>
        <?php endif; ?>
        <?php if (isset($topbarCtx['factoryName'])): ?>
        <span class="select-pill"><?= ui_icon('building') ?> <?= ui_esc($topbarCtx['factoryName']) ?></span>
        <?php endif; ?>
        <button type="button" class="icon-btn" data-action="toggle-theme" title="Ganti tema (segera: mode terang)"><?= ui_icon('moon') ?></button>
        <button type="button" class="icon-btn" title="Notifikasi"><?= ui_icon('bell') ?></button>
        <div class="user-chip" title="<?= ui_esc(implode(', ', $ui['roles'])) ?>">
          <div class="user-avatar"><?= ui_esc($initials) ?></div>
          <div class="user-meta">
            <div class="user-name"><?= ui_esc($ui['fullName'] !== '' ? $ui['fullName'] : $ui['username']) ?></div>
            <div class="user-role"><?= ui_esc($primaryRole) ?><?php if ($operatorName !== null): ?> &middot; <?= ui_esc($operatorName) ?> <a href="/api/_admin-login/?action=change_operator&amp;return=<?= urlencode((string) ($_SERVER['REQUEST_URI'] ?? '/api/_ui-preview/')) ?>" style="color:inherit;text-decoration:underline;">(ganti)</a><?php endif; ?></div>
          </div>
        </div>
        <button type="button" class="icon-btn logout-btn" data-action="logout" title="Keluar / ganti akun" aria-label="Keluar"><?= ui_icon('logout') ?><span class="logout-label">Keluar</span></button>
      </div>
    </header>
    <main class="page">
      <div class="page-head">
        <div class="page-head-row">
          <div>
            <h1 class="page-title"><?= ui_esc($title) ?></h1>
            <p class="page-subtitle"><?= ui_esc($subtitle) ?></p>
          </div>
        </div>
      </div>
<?php
}

function ui_page_foot(): void
{
    ?>
    </main>
  </div>
</div>
<script>window.AMOR = <?= json_encode(['csrfToken' => $GLOBALS['ui']['csrfToken'] ?? '', 'userId' => $GLOBALS['ui']['userId'] ?? null, 'username' => $GLOBALS['ui']['username'] ?? ''], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/api/assets/js/app.js?v=<?= ADMIN_ASSET_VERSION ?>"></script>
</body>
</html>
<?php
}
