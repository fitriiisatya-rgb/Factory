<?php

declare(strict_types=1);

/**
 * Migration 0022: integration_api_key — server-to-server keys for the
 * read-only finhub API (shipments + store receipts).
 *
 * See database/schema-v1-0022-integration-key.sql for the full rationale.
 * Purely additive (CREATE TABLE IF NOT EXISTS).
 *
 * Same dual-location pointer pattern as every prior migration:
 *   1. api/app/database/schema-v1-0022-integration-key.sql —
 *      placed here ONLY by the cPanel packaging script.
 *   2. The monorepo's top-level database/schema-v1-0022-integration-key.sql —
 *      used for local development and the disposable-MariaDB test suite.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0022-integration-key.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0022-integration-key.sql';
return is_file($packaged) ? $packaged : $monorepo;
