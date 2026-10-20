<?php

declare(strict_types=1);

/**
 * Migration 0023: invoice_item lines for Pesanan Khusus Toko at the order
 * price (custom items without a product, charge/extra packaging).
 *
 * See database/schema-v1-0023-invoice-special-lines.sql for the rationale.
 * Additive: nullable product_id, new nullable/defaulted columns, and the
 * unique key moved to (invoice, product, order line).
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0023-invoice-special-lines.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0023-invoice-special-lines.sql';
return is_file($packaged) ? $packaged : $monorepo;
