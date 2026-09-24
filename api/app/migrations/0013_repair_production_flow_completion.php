<?php

declare(strict_types=1);

/**
 * Migration 0013: LIVE SCHEMA REPAIR for migration 0012.
 *
 * Live cPanel has 0012_production_flow_completion.php recorded as applied,
 * but a direct authenticated diagnostic confirmed special_order_fg_
 * allocation does not exist there — meaning 0012 was applied live from an
 * earlier revision of its own SQL file than the one now in this
 * repository. The migration runner tracks applied state by FILENAME only,
 * so it will never re-execute 0012's current contents on that database.
 *
 * This migration does NOT touch the 0012 registry row (never deleted,
 * never re-run, never renamed). Every statement in its own .sql file is
 * additive and idempotent — safe to run whether live is missing only
 * special_order_fg_allocation, or is missing a wider set of 0012 objects
 * from an even earlier revision. See the .sql file's own docblock for the
 * full drift analysis and per-object idempotency reasoning.
 *
 * Same dual-location pointer pattern as every prior migration — the
 * canonical DDL lives in exactly one place in this monorepo
 * (/database/schema-v1-0013-repair-production-flow-completion.sql), so
 * this file never carries a second copy.
 *
 * Two possible locations, tried in order:
 *   1. api/app/database/schema-v1-0013-repair-production-flow-completion.sql
 *      — placed here ONLY by the cPanel easy-install packaging script,
 *      because the shipped ZIP does not include the whole monorepo.
 *   2. The monorepo's top-level database/schema-v1-0013-repair-
 *      production-flow-completion.sql — used for local development and
 *      the disposable-MariaDB test suite.
 * Exactly one of these exists in any given deployment.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0013-repair-production-flow-completion.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0013-repair-production-flow-completion.sql';
return is_file($packaged) ? $packaged : $monorepo;
