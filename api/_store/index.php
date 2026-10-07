<?php

declare(strict_types=1);

/**
 * Permanent Bakery Portal — Konfirmasi Penerimaan (Reject included) /
 * Pesanan Khusus / Retur / Mutasi Produk / Riwayat. Every tab is a thin
 * PHP shell; all transactional data comes from the real JSON API
 * (/api/store/{token}/*) via store.js — this file never hardcodes a
 * number or duplicates business logic, same discipline as
 * api/_driver-uat/index.php. The only server-side DB reads here are
 * plain lookup lists (products/stores for a form's dropdown) — read-only
 * convenience data, not state.
 */

require __DIR__ . '/bootstrap.php';

use Amor\Api\Database;
use Amor\Api\Repositories\ProductRepository;
use Amor\Api\Repositories\StoreRepository;

if ($storeErrorMessage !== null) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Portal Bakery Amor</title>
<link rel="stylesheet" href="/api/assets/css/tokens.css?v=<?= STORE_ASSET_VERSION ?>">
<link rel="stylesheet" href="/api/assets/css/driver.css?v=<?= STORE_ASSET_VERSION ?>">
<link rel="stylesheet" href="/api/assets/css/store.css?v=<?= STORE_ASSET_VERSION ?>">
</head>
<body class="driver-body store-body">
<div class="driver-shell"><main class="driver-main">
  <div class="driver-card store-error-card">
    <div class="driver-card-title">Tautan tidak dapat dibuka</div>
    <div class="driver-row"><?= store_esc($storeErrorMessage) ?></div>
  </div>
</main></div>
</body>
</html>
<?php
    exit;
}

$tabs = ['konfirmasi', 'khusus', 'retur', 'mutasi', 'riwayat'];
$tab = (string) ($_GET['tab'] ?? 'konfirmasi');
if (!in_array($tab, $tabs, true)) {
    $tab = 'konfirmasi';
}
$titles = [
    'konfirmasi' => 'Konfirmasi Penerimaan',
    'khusus' => 'Pesanan Khusus',
    'retur' => 'Retur',
    'mutasi' => 'Mutasi Produk',
    'riwayat' => 'Riwayat',
];

// Plain read-only lookup lists for a form's dropdown — never a second
// source of truth (same products/store rows the real API already
// serves to an authenticated session; this portal has none, so these
// are read directly here, same convenience-query convention as every
// other UAT page's own filter <select>, e.g. Pesanan Toko's $factories).
$lookupProducts = [];
$lookupStores = [];
if (in_array($tab, ['khusus', 'retur', 'mutasi'], true)) {
    $productRows = (new ProductRepository())->findAll(Database::pdo(), null, null, true);
    $lookupProducts = array_map(static fn ($p) => ['productId' => (int) $p['product_id'], 'name' => $p['name']], $productRows);
}
if ($tab === 'mutasi') {
    $storeRows = (new StoreRepository())->findAll(Database::pdo(), null, null, true);
    $lookupStores = array_values(array_filter(array_map(
        static fn ($s) => ['storeId' => (int) $s['store_id'], 'name' => $s['canonical_name']],
        $storeRows
    ), fn ($s) => $s['storeId'] !== $storeIdentity['storeId']));
}

$focusShipmentId = isset($_GET['shipment']) ? (int) $_GET['shipment'] : null;
$focusOrderId = isset($_GET['order']) ? (int) $_GET['order'] : null;
$focusReturId = isset($_GET['retur']) ? (int) $_GET['retur'] : null;
$focusMutasiId = isset($_GET['mutasi']) ? (int) $_GET['mutasi'] : null;

store_page_head($storeToken, $storeIdentity['storeName'], $tab, $titles[$tab]);
?>
<div id="store-app"></div>
<script>
  window.STORE_TOKEN = <?= json_encode($storeToken) ?>;
  window.STORE_TAB = <?= json_encode($tab) ?>;
  window.STORE_FOCUS_SHIPMENT_ID = <?= json_encode($focusShipmentId) ?>;
  window.STORE_FOCUS_ORDER_ID = <?= json_encode($focusOrderId) ?>;
  window.STORE_FOCUS_RETUR_ID = <?= json_encode($focusReturId) ?>;
  window.STORE_FOCUS_MUTASI_ID = <?= json_encode($focusMutasiId) ?>;
  window.STORE_LOOKUP_PRODUCTS = <?= json_encode($lookupProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  window.STORE_LOOKUP_STORES = <?= json_encode($lookupStores, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php
store_page_foot($storeToken, $tab);
