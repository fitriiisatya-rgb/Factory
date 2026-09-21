<?php

declare(strict_types=1);

/**
 * Detail Pengiriman — read-only shipment tracing for the driver who
 * shipped it (or ADMIN). Renders a static shell; driver.js fetches the
 * live detail via GET /api/dispatch/shipments/{id}, which enforces
 * authorization server-side (403 for a shipment that belongs to a
 * different driver — see DispatchService::shipmentDetail()'s docblock).
 */

require __DIR__ . '/bootstrap.php';

$shipmentId = (int) ($_GET['id'] ?? 0);

driver_page_head($ui, 'riwayat', 'Detail Pengiriman');
?>
<div id="driver-shipment-app" data-shipment-id="<?= $shipmentId ?>"></div>
<?php
driver_page_foot('riwayat');
