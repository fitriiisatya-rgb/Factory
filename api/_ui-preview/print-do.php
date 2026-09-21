<?php

declare(strict_types=1);

/**
 * Redesigned single-DO print page — professional A4 "Delivery Order /
 * Surat Jalan" document, matching the reference mockup. Read-only;
 * reuses DoService::getDo() exactly like the old api/_do-uat/print.php
 * did (that page is untouched and stays as a fallback route — see its
 * own file). DRAFT/PREPRINT/DIBATALKAN watermark rule unchanged; a
 * SHIPPED DO prints with no watermark (a real, final document) but its
 * status is still shown as "TERKIRIM" in the header.
 *
 * READ-ONLY: this file only calls DoService::getDo() (a SELECT) — it
 * never bumps delivery_order.version, never touches stock_ledger, never
 * creates a shipment. Opening/printing is a pure read.
 */

require __DIR__ . '/../app/ui/bootstrap.php';
require_once __DIR__ . '/../app/ui/labels.php';
require_once __DIR__ . '/../app/ui/print-template.php';

use Amor\Api\Delivery\DoService;

$doId = isset($_GET['doId']) ? (int) $_GET['doId'] : 0;
$service = new DoService($ui['pdo']);

try {
    $do = $service->getDo($doId);
} catch (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:2rem auto;">'
        . '<h1>Gagal memuat DO</h1><p>' . ui_esc($e->getMessage()) . '</p></body></html>';
    exit;
}

$factoryNamesById = [];
foreach ($ui['pdo']->query('SELECT factory_id, name FROM factory')->fetchAll() as $f) {
    $factoryNamesById[(int) $f['factory_id']] = $f['name'];
}
$factoryLabel = ui_print_factory_label($do, $factoryNamesById);
$printedByName = $ui['fullName'] !== '' ? $ui['fullName'] : $ui['username'];

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Draft DO <?= ui_esc($do['docNo']) ?></title>
<link rel="stylesheet" href="/api/assets/css/print.css">
</head>
<body class="print-doc">
<div class="print-toolbar">
  <a class="secondary" href="/api/_ui-preview/?page=delivery-order-detail&doId=<?= (int) $do['doId'] ?>">&larr; Kembali</a>
  <span class="print-toolbar-title"><?= ui_esc($do['docNo']) ?></span>
  <button type="button" class="primary" onclick="window.print()">Cetak</button>
</div>

<?php ui_render_do_print_document($do, $factoryLabel, $printedByName, 1, 1, $ui['pdo']); ?>
</body>
</html>
