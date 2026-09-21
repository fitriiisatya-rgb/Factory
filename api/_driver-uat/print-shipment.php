<?php

declare(strict_types=1);

/**
 * Cetak Surat Jalan — real-UAT ask: the Draft DO print shows every
 * PLANNED item across the whole DO (correct for planning/picking, wrong
 * as the actual proof-of-goods a Driver carries once a DO has split into
 * several real shipments). This page prints ONLY the ONE shipment the
 * Driver is viewing, sourced entirely from shipment_item via
 * DispatchService::shipmentDetail() — the same authorization rule the
 * Detail Pengiriman JSON API already enforces (a non-admin caller may
 * only open a shipment THEY shipped, 403 FORBIDDEN otherwise) reused
 * unchanged, never a parallel check.
 *
 * READ-ONLY: shipmentDetail() only SELECTs; the only write anywhere in
 * this request is ui_do_receipt_qr_svg()'s lazy get-or-create of the
 * OWNING DO's receipt token (same as the Draft DO print page already
 * does) — never touches delivery_order.version/status, never creates a
 * shipment, never posts to stock_ledger.
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/ui/print-shipment-template.php';

use Amor\Api\ApiException;
use Amor\Api\Dispatch\DispatchService;

$shipmentId = (int) ($_GET['id'] ?? 0);
$qrSize = (string) ($_GET['qr'] ?? '40');
$qrSizeClass = in_array($qrSize, ['35', '40', '50'], true) ? "sj-qr-{$qrSize}mm" : 'sj-qr-40mm';
$isAdmin = in_array('ADMIN', $ui['roles'], true);

try {
    $service = new DispatchService($ui['pdo']);
    $shipment = $service->shipmentDetail($shipmentId, $ui['userId'], $isAdmin);
} catch (ApiException $e) {
    // Real status code (403/404) — this is a security-relevant document
    // access surface (a printed proof-of-goods), so a caller probing
    // shipment ids gets a real, testable denial, never partial data.
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
  <a class="secondary" href="shipment.php?id=<?= (int) $shipment['shipmentId'] ?>">&larr; Kembali</a>
  <span class="print-toolbar-title">SHP-<?= (int) $shipment['shipmentId'] ?></span>
  <button type="button" class="primary" onclick="window.print()">Cetak</button>
</div>

<?php ui_render_shipment_print_document($shipment, $printedByName, $ui['pdo'], $qrSizeClass); ?>
</body>
</html>
