<?php

declare(strict_types=1);

/**
 * Admin-facing Cetak Surat Jalan — same shared template/service as
 * api/_driver-uat/print-shipment.php (task's own "make the same
 * shipment-print endpoint reusable from Admin later"), just reached via
 * the admin UI session instead of the Driver Portal one. ADMIN keeps the
 * broader access DispatchService::shipmentDetail() already grants it
 * (isAdmin=true bypasses the shipped_by-only check).
 *
 * READ-ONLY — see print-shipment.php's own docblock for the exact write
 * guarantee (the OWNING DO's receipt token may be lazily created, same
 * as every other print page; nothing else is ever written).
 */

require __DIR__ . '/../app/ui/bootstrap.php';
require_once __DIR__ . '/../app/ui/print-shipment-template.php';

use Amor\Api\ApiException;
use Amor\Api\Dispatch\DispatchService;

$shipmentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$qrSize = (string) ($_GET['qr'] ?? '40');
$qrSizeClass = in_array($qrSize, ['35', '40', '50'], true) ? "sj-qr-{$qrSize}mm" : 'sj-qr-40mm';
$isAdmin = in_array('ADMIN', $ui['roles'], true);

try {
    $service = new DispatchService($ui['pdo']);
    $shipment = $service->shipmentDetail($shipmentId, $ui['userId'], $isAdmin);
} catch (ApiException $e) {
    http_response_code($e->status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:480px;margin:2rem auto;">'
        . '<h1>Tidak bisa mencetak Surat Jalan</h1><p>' . ui_esc($e->getMessage()) . '</p></body></html>';
    exit;
} catch (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:2rem auto;">'
        . '<h1>Gagal memuat Surat Jalan</h1><p>' . ui_esc($e->getMessage()) . '</p></body></html>';
    exit;
}

$printedByName = $ui['fullName'] !== '' ? $ui['fullName'] : $ui['username'];

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Surat Jalan SHP-<?= (int) $shipment['shipmentId'] ?></title>
<link rel="stylesheet" href="/api/assets/css/print.css">
<link rel="stylesheet" href="/api/assets/css/print-shipment.css">
</head>
<body class="print-doc">
<div class="print-toolbar">
  <a class="secondary" href="/api/_ui-preview/?page=delivery-order-detail">&larr; Kembali</a>
  <span class="print-toolbar-title">SHP-<?= (int) $shipment['shipmentId'] ?></span>
  <button type="button" class="primary" onclick="window.print()">Cetak</button>
</div>

<?php ui_render_shipment_print_document($shipment, $printedByName, $ui['pdo'], $qrSizeClass); ?>
</body>
</html>
