<?php

declare(strict_types=1);

/**
 * Migration 0010: Pesanan Khusus Toko / Pesanan Non-Toko + Production Routing.
 *
 * Same dual-location pointer pattern as 0001-0009 — the canonical DDL
 * lives in exactly one place in this monorepo (/database/schema-v1-0010-
 * special-nonregular-orders.sql), so this file never carries a second copy.
 *
 * Two possible locations, tried in order:
 *   1. api/app/database/schema-v1-0010-special-nonregular-orders.sql —
 *      placed here ONLY by the cPanel easy-install packaging script,
 *      because the shipped ZIP does not include the whole monorepo.
 *   2. The monorepo's top-level database/schema-v1-0010-special-
 *      nonregular-orders.sql — used for local development and the
 *      disposable-MariaDB test suite.
 * Exactly one of these exists in any given deployment.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0010-special-nonregular-orders.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0010-special-nonregular-orders.sql';
return is_file($packaged) ? $packaged : $monorepo;
