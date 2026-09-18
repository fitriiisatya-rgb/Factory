<?php

declare(strict_types=1);

/**
 * Konfirmasi Berangkat — the departure detail screen for one route stop
 * (one store). Renders a static shell; driver.js fetches the live detail
 * via GET /api/dispatch/route/stops/{storeId}?tanggal=... and posts the
 * confirmation via POST /api/dispatch/departures.
 */

require __DIR__ . '/bootstrap.php';

$storeId = (int) ($_GET['storeId'] ?? 0);
$tanggal = (string) ($_GET['tanggal'] ?? date('Y-m-d'));

driver_page_head($ui, 'rute', 'Konfirmasi Berangkat');
?>
<div id="driver-stop-app" data-store-id="<?= $storeId ?>" data-tanggal="<?= ui_esc($tanggal) ?>"></div>
<?php
driver_page_foot('rute');
