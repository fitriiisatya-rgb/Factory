<?php

declare(strict_types=1);

/**
 * Migration 0018: Invoice generation from real DO/Shipment data —
 * invoice_mutasi, the Mutasi-side junction (invoice_shipment already
 * exists, unmodified, from migration 0001).
 *
 * See database/schema-v1-0018-invoice.sql for the full rationale and the
 * data-model audit performed before writing it. This is the FIRST schema
 * change since 0017 (already LIVE, untouched here) — the only new table
 * is CREATE TABLE IF NOT EXISTS, no existing table anywhere is altered,
 * dropped, or touched.
 *
 * Same dual-location pointer pattern as every prior migration — the
 * canonical DDL lives in exactly one place in this monorepo
 * (/database/schema-v1-0018-invoice.sql), so this file never carries a
 * second copy.
 *
 * Two possible locations, tried in order:
 *   1. api/app/database/schema-v1-0018-invoice.sql — placed here ONLY by
 *      the cPanel easy-install packaging script, because the shipped ZIP
 *      does not include the whole monorepo.
 *   2. The monorepo's top-level database/schema-v1-0018-invoice.sql —
 *      used for local development and the disposable-MariaDB test suite.
 * Exactly one of these exists in any given deployment.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0018-invoice.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0018-invoice.sql';
return is_file($packaged) ? $packaged : $monorepo;
