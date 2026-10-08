<?php

declare(strict_types=1);

/**
 * Real Invoice print page (migration 0018) — reuses InvoiceService::getDetail()
 * and the same print-invoice-template.php markup the mock preview
 * (invoice-preview.php, untouched) already uses; nothing here is
 * mock-specific. READ-ONLY: only a SELECT, never writes anything.
 */

require __DIR__ . '/../app/ui/bootstrap.php';
require_once __DIR__ . '/../app/ui/labels.php';
require_once __DIR__ . '/../app/ui/print-invoice-template.php';

use Amor\Api\Invoice\InvoiceService;

$invoiceId = isset($_GET['invoiceId']) ? (int) $_GET['invoiceId'] : 0;
$service = new InvoiceService($ui['pdo']);

try {
    $invoice = $service->getDetail($invoiceId);
} catch (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:2rem auto;">'
        . '<h1>Gagal memuat Invoice</h1><p>' . ui_esc($e->getMessage()) . '</p></body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Invoice <?= ui_esc((string) $invoice['invoiceNumber']) ?></title>
<link rel="stylesheet" href="/api/assets/css/print-invoice.css">
</head>
<body class="print-doc inv-doc">
<div class="print-toolbar">
  <a class="secondary" href="/api/_ui-preview/?page=invoice-detail&invoiceId=<?= (int) $invoice['invoiceId'] ?>">&larr; Kembali</a>
  <span class="print-toolbar-title"><?= ui_esc((string) $invoice['invoiceNumber']) ?></span>
  <button type="button" class="primary" onclick="window.print()">Cetak</button>
</div>

<?php ui_render_invoice_print_document($invoice, 1, 1); ?>
</body>
</html>
