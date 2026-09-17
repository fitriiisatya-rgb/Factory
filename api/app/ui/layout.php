<?php

declare(strict_types=1);

require_once __DIR__ . '/components.php';
require_once __DIR__ . '/labels.php';

/**
 * Nav item list — the single source of truth for the sidebar. Every item
 * links to /api/_ui-preview/?page=<key>. Role visibility is left
 * permissive for this preview (any authenticated user sees every menu
 * item); the backend's own role checks on every mutating API call remain
 * the real gate — hiding a menu item is a convenience, never a security
 * boundary (task's own "role-ready UI" rule).
 * @return array<int,array{key:string,label:string,icon:string}>
 */
function ui_nav_items(): array
{
    return [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['key' => 'pesanan-toko', 'label' => 'Pesanan Toko', 'icon' => 'cart'],
        ['key' => 'produksi', 'label' => 'Produksi', 'icon' => 'factory'],
        ['key' => 'fg-packing', 'label' => 'FG & Packing', 'icon' => 'box'],
        ['key' => 'delivery-order', 'label' => 'Delivery Order', 'icon' => 'file'],
        ['key' => 'pengiriman', 'label' => 'Pengiriman', 'icon' => 'truck'],
    ];
}

/** @return array<int,array{key:string,label:string,icon:string}> */
function ui_nav_items_secondary(): array
{
    return [
        ['key' => 'laporan', 'label' => 'Laporan', 'icon' => 'chart'],
        ['key' => 'master-data', 'label' => 'Master Data', 'icon' => 'database'],
        ['key' => 'pengaturan', 'label' => 'Pengaturan', 'icon' => 'settings'],
    ];
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
<link rel="stylesheet" href="/api/app/ui/assets/css/tokens.css">
<link rel="stylesheet" href="/api/app/ui/assets/css/app.css">
</head>
<body>
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
      <?php foreach (ui_nav_items() as $item): ?>
      <a class="nav-item<?= $active === $item['key'] ? ' active' : '' ?>" href="/api/_ui-preview/?page=<?= $item['key'] ?><?= $navQS ?>">
        <span class="nav-icon"><?= ui_icon($item['icon']) ?></span><span class="nav-label"><?= ui_esc($item['label']) ?></span>
      </a>
      <?php endforeach; ?>
      <div class="sidebar-section-label">Lainnya</div>
      <?php foreach (ui_nav_items_secondary() as $item): ?>
      <a class="nav-item<?= $active === $item['key'] ? ' active' : '' ?>" href="/api/_ui-preview/?page=<?= $item['key'] ?><?= $navQS ?>">
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
            <div class="user-role"><?= ui_esc($primaryRole) ?></div>
          </div>
        </div>
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
<script src="/api/app/ui/assets/js/app.js"></script>
</body>
</html>
<?php
}
