<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  MOCK / PREVIEW-ONLY FIXTURE — NOT REAL DATA — REMOVE WHEN PHASE 6 LANDS
 * ============================================================================
 *
 * This file exists ONLY so the Invoice print template can be previewed
 * before Phase 6 (Invoice/Piutang) backend/business logic is built. It is
 * loaded by a single page: api/_ui-preview/invoice-preview.php.
 *
 * It NEVER touches the database — no PDO, no query, nothing is read from
 * or written to any table. The numbers below are entirely made up for
 * layout/print testing and must never be treated as a real transaction.
 *
 * When Phase 6 is implemented, this file should be deleted and
 * invoice-preview.php should be pointed at a real
 * InvoiceService::getInvoice($invoiceId) call returning the same DTO shape
 * (see the docblock in print-invoice-template.php).
 */

function ui_invoice_mock_fixture(): array
{
    return [
        'invoiceNumber' => 'INV/MOCK/0001',
        'invoiceDate' => date('Y-m-d'),
        'customer' => [
            'storeName' => 'Toko Contoh — Bakery Cikole',
            'address' => 'Jl. Contoh Alamat No. 123, Cikole, Lembang',
            'npwp' => '01.234.567.8-901.000',
        ],
        'reference' => [
            'doNumber' => 'DO/MOCK/0001',
            'shipmentNumber' => 'SHP/MOCK/0001',
            'shipDate' => date('Y-m-d'),
        ],
        'items' => [
            ['productName' => 'Roti Bollen Coklat', 'qty' => 24, 'unitPrice' => 12000, 'subtotal' => 288000],
            ['productName' => 'Roti Bollen Keju', 'qty' => 18, 'unitPrice' => 13000, 'subtotal' => 234000],
            ['productName' => 'Donat Gula', 'qty' => 30, 'unitPrice' => 8000, 'subtotal' => 240000],
            ['productName' => 'Brownies Kukus', 'qty' => 12, 'unitPrice' => 25000, 'subtotal' => 300000],
            ['productName' => 'Cake Tart Mini', 'qty' => 10, 'unitPrice' => 35000, 'subtotal' => 350000],
        ],
        'summary' => [
            'subtotal' => 1412000,
            'discount' => 12000,
            'total' => 1400000,
        ],
        // 'notes' intentionally omitted — the Catatan section is absent by
        // default per the task spec; uncomment to preview it:
        // 'notes' => 'Contoh catatan invoice.',
        'metadata' => [
            'printedAt' => date('Y-m-d H:i'),
            'generatedBy' => 'Mock Preview',
        ],
    ];
}
