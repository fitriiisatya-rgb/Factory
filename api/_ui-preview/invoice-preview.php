<?php

declare(strict_types=1);

/**
 * Invoice print/preview page — Phase 6 (Invoice/Piutang) backend does not
 * exist yet, so this page is a PURE READ-ONLY TEMPLATE PREVIEW: it loads a
 * clearly-marked mock fixture (api/app/ui/fixtures/invoice-mock.php), never
 * touches the database, never writes anything, and never invents any real
 * transaction. It exists so the invoice document design can be reviewed
 * now and wired to a real InvoiceService::getInvoice() DTO later without
 * changing this file's rendering (see print-invoice-template.php).
 *
 * Still requires the same authenticated session as every other
 * /api/_ui-preview/ page (bootstrap.php) — this is not a public route.
 */

require __DIR__ . '/../app/ui/bootstrap.php';
require_once __DIR__ . '/../app/ui/labels.php';
require_once __DIR__ . '/../app/ui/print-invoice-template.php';
require_once __DIR__ . '/../app/ui/fixtures/invoice-mock.php';

$invoice = ui_invoice_mock_fixture();

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Invoice <?= ui_esc((string) $invoice['invoiceNumber']) ?> (Preview)</title>
<link rel="stylesheet" href="/api/assets/css/print-invoice.css">
</head>
<body class="print-doc inv-doc">
<div class="print-toolbar">
  <a class="secondary" href="/api/_ui-preview/?page=dashboard">&larr; Kembali</a>
  <span class="print-toolbar-title"><?= ui_esc((string) $invoice['invoiceNumber']) ?></span>
  <button type="button" class="primary" onclick="window.print()">Cetak</button>
</div>

<div class="inv-preview-notice">
  Pratinjau template Invoice — data di bawah ini adalah <strong>data contoh (mock)</strong> untuk keperluan desain cetak.
  Logika transaksi Invoice (Phase 6) belum dibangun; halaman ini tidak menyimpan apa pun ke database.
</div>

<?php ui_render_invoice_print_document($invoice, 1, 1); ?>
</body>
</html>
