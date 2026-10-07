<?php

declare(strict_types=1);

/**
 * Migration 0017: Permanent Bakery Portal — store_portal_token (one
 * permanent, bookmarkable, no-login identity per store), retur_request +
 * retur_request_evidence, mutasi_request + mutasi_request_evidence, and
 * special_order_attachment.
 *
 * See database/schema-v1-0017-permanent-bakery-portal.sql for the full
 * rationale and the data-model audit performed before writing it. This is
 * the FIRST schema change since 0016 (already LIVE, untouched here) —
 * every CREATE TABLE is IF NOT EXISTS, no existing table anywhere is
 * altered, dropped, or touched.
 *
 * Same dual-location pointer pattern as every prior migration — the
 * canonical DDL lives in exactly one place in this monorepo
 * (/database/schema-v1-0017-permanent-bakery-portal.sql), so this file
 * never carries a second copy.
 *
 * Two possible locations, tried in order:
 *   1. api/app/database/schema-v1-0017-permanent-bakery-portal.sql —
 *      placed here ONLY by the cPanel easy-install packaging script,
 *      because the shipped ZIP does not include the whole monorepo.
 *   2. The monorepo's top-level database/schema-v1-0017-permanent-
 *      bakery-portal.sql — used for local development and the
 *      disposable-MariaDB test suite.
 * Exactly one of these exists in any given deployment.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0017-permanent-bakery-portal.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0017-permanent-bakery-portal.sql';
return is_file($packaged) ? $packaged : $monorepo;
