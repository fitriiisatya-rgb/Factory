<?php

declare(strict_types=1);

/**
 * Detail Pengiriman — read-only shipment tracing for the driver who
 * shipped it (or ADMIN). Renders a static shell; driver.js fetches the
 * live detail via GET /api/dispatch/shipments/{id}, which enforces
 * authorization server-side (403 for a shipment that belongs to a
 * different driver — see DispatchService::shipmentDetail()'s docblock).
 *
 * Real-UAT navigation fix: also accepts ?storeId=&tanggal= (no ?id=) —
 * used by a departed Rute Saya stop when it doesn't yet know a single
 * shipment id (a stop can legitimately have split into more than one
 * shipment, e.g. MAIN + PASTRY — see DPT-19/DR-NAV04). driver.js then
 * calls GET /api/dispatch/route/stops/{storeId}/shipments and either
 * opens the one shipment directly or shows a chooser — never guesses.
 */

require __DIR__ . '/bootstrap.php';

$shipmentId = (int) ($_GET['id'] ?? 0);
$storeId = (int) ($_GET['storeId'] ?? 0);
$tanggal = (string) ($_GET['tanggal'] ?? '');

driver_page_head($ui, 'riwayat', 'Detail Pengiriman');
?>
<div id="driver-shipment-app" data-shipment-id="<?= $shipmentId ?>" data-store-id="<?= $storeId ?>" data-tanggal="<?= ui_esc($tanggal) ?>"></div>
<?php
driver_page_foot('riwayat');
