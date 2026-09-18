<?php

declare(strict_types=1);

/**
 * Driver portal — Tersedia / Pengiriman Saya / Rute Saya / Riwayat.
 * Every tab is a thin PHP shell; all data comes from the real Phase 5.5
 * JSON API (/api/dispatch/*) via driver.js + Amor.apiFetch — no business
 * logic or numbers are hardcoded here (task's own "Jangan menyalin angka
 * dummy dari mockup; semua data harus berasal dari backend").
 */

require __DIR__ . '/bootstrap.php';

$tabs = ['tersedia', 'saya', 'rute', 'riwayat'];
$tab = (string) ($_GET['tab'] ?? 'tersedia');
if (!in_array($tab, $tabs, true)) {
    $tab = 'tersedia';
}
$titles = [
    'tersedia' => 'Pengiriman Tersedia',
    'saya' => 'Pengiriman Saya',
    'rute' => 'Rute Saya',
    'riwayat' => 'Riwayat Pengiriman',
];

driver_page_head($ui, $tab, $titles[$tab]);
?>
<div id="driver-app" data-tab="<?= ui_esc($tab) ?>"></div>
<?php
driver_page_foot($tab);
