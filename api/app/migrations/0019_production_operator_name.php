<?php

declare(strict_types=1);

/**
 * Migration 0019: Shared Division Login + Operator Name — three NULLable
 * operator-name columns on the EXISTING production_run table.
 *
 * See database/schema-v1-0019-production-operator-name.sql for the full
 * rationale. Purely additive (ADD COLUMN IF NOT EXISTS), no existing
 * column or row is touched.
 *
 * Same dual-location pointer pattern as every prior migration:
 *   1. api/app/database/schema-v1-0019-production-operator-name.sql —
 *      placed here ONLY by the cPanel packaging script.
 *   2. The monorepo's top-level database/schema-v1-0019-production-operator-name.sql —
 *      used for local development and the disposable-MariaDB test suite.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0019-production-operator-name.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0019-production-operator-name.sql';
return is_file($packaged) ? $packaged : $monorepo;
