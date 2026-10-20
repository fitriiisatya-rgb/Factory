<?php

declare(strict_types=1);

/**
 * Migration 0024: Runchise "Impor Stok Masuk" export — store/product
 * mapping columns, per-shipment download record, runchise_catalog.
 *
 * See database/schema-v1-0024-runchise-export.sql for the rationale.
 * Additive only; the initial fill touches NULL columns only.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0024-runchise-export.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0024-runchise-export.sql';
return is_file($packaged) ? $packaged : $monorepo;
