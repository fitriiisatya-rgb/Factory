<?php

declare(strict_types=1);

/**
 * Migration 0020: Reject di Toko — additive columns on retur_request
 * (jenis, kondisi, exp_date, tindak_lanjut, pengganti_order_id,
 * invoice_id) so a store can report spoiled-before-expiry goods as a
 * factory-burden Reject, separate from a record-only Retur.
 *
 * See database/schema-v1-0020-reject-di-toko.sql for the full
 * rationale. Purely additive (ADD COLUMN IF NOT EXISTS), no existing
 * column or row is touched.
 *
 * Same dual-location pointer pattern as every prior migration:
 *   1. api/app/database/schema-v1-0020-reject-di-toko.sql —
 *      placed here ONLY by the cPanel packaging script.
 *   2. The monorepo's top-level database/schema-v1-0020-reject-di-toko.sql —
 *      used for local development and the disposable-MariaDB test suite.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0020-reject-di-toko.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0020-reject-di-toko.sql';
return is_file($packaged) ? $packaged : $monorepo;
