<?php

declare(strict_types=1);

/**
 * Nota Potongan print page (migration 0021) — same print-invoice-template.php
 * markup as an invoice, titled NOTA POTONGAN and pointing at the invoice it
 * credits. READ-ONLY.
 */

require __DIR__ . '/../app/ui/bootstrap.php';
require_once __DIR__ . '/../app/ui/labels.php';
require_once __DIR__ . '/../app/ui/print-invoice-template.php';

if (!in_array('ADMIN', $ui['roles'], true)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:2rem auto;"><h1>Akses Ditolak</h1></body></html>';
    exit;
}

use Amor\Api\Invoice\NotaPotonganService;

$notaId = isset($_GET['notaId']) ? (int) $_GET['notaId'] : 0;
try {
    $n = (new NotaPotonganService($ui['pdo']))->get($notaId);
} catch (\Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:2rem auto;">'
        . '<h1>Gagal memuat Nota Potongan</h1><p>' . ui_esc($e->getMessage()) . '</p></body></html>';
    exit;
}

$doc = [
    'docTitle' => 'NOTA POTONGAN',
    'docLabel' => 'Nota',
    'totalLabel' => 'Total Potongan',
    'invoiceNumber' => $n['notaNo'],
    'invoiceDate' => $n['tanggal'],
    'customer' => ['storeName' => $n['storeName']],
    'reference' => ['invoiceNo' => $n['invoiceNo'] ?? 'menyusul (pengiriman belum ditagih)'],
    'items' => array_map(static fn ($it) => [
        'productName' => $it['productName'], 'qty' => $it['qty'], 'unitPrice' => $it['harga'], 'subtotal' => $it['subtotal'],
    ], $n['items']),
    'summary' => ['subtotal' => $n['total'], 'discount' => 0.0, 'total' => $n['total']],
    'notes' => $n['alasanLabel'] . ': ' . (string) $n['keterangan']
        . ($n['alasan'] === 'reject_toko' && $n['expDate'] ? "\nTanggal expired di kemasan: " . $n['expDate'] : ''),
    'metadata' => ['printedAt' => date('Y-m-d H:i'), 'generatedBy' => 'Amor Factory System', 'createdAt' => $n['createdAt']],
];

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Nota Potongan <?= ui_esc($n['notaNo']) ?></title>
<link rel="stylesheet" href="/api/assets/css/print-invoice.css">
</head>
<body class="print-doc inv-doc">
<div class="print-toolbar">
  <a class="secondary" href="/api/_ui-preview/?page=invoice#nota-potongan">&larr; Kembali</a>
  <span class="print-toolbar-title"><?= ui_esc($n['notaNo']) ?></span>
  <button type="button" class="primary" onclick="window.print()">Cetak</button>
</div>

<?php ui_render_invoice_print_document($doc, 1, 1); ?>
</body>
</html>
