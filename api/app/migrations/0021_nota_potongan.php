<?php

declare(strict_types=1);

/**
 * Migration 0021: Invoice per DO + Nota Potongan — invoice.source_kind/
 * source_ref_id, and the new nota_potongan / nota_potongan_item tables (credit notes against an issued invoice).
 *
 * See database/schema-v1-0021-nota-potongan.sql for the full
 * rationale. Purely additive (CREATE TABLE / ADD COLUMN IF NOT EXISTS), no
 * existing column or row is touched.
 *
 * Same dual-location pointer pattern as every prior migration:
 *   1. api/app/database/schema-v1-0021-nota-potongan.sql —
 *      placed here ONLY by the cPanel packaging script.
 *   2. The monorepo's top-level database/schema-v1-0021-nota-potongan.sql —
 *      used for local development and the disposable-MariaDB test suite.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0021-nota-potongan.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0021-nota-potongan.sql';
return is_file($packaged) ? $packaged : $monorepo;
