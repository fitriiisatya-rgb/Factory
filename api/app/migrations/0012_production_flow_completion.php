<?php

declare(strict_types=1);

/**
 * Migration 0012: Production Flow Completion + Special/Non-Regular
 * Fulfillment Completion — Extra Packaging, the special-order FG bridge
 * (fg_verified_qty), source-specific DO with Driver Internal / External
 * Courier delivery methods (special_order_do/special_order_do_item/
 * special_order_do_shipment_item), and the real shipment write path for
 * source-specific dispatch. See the .sql file's own docblock for full
 * rationale — this REPLACES an earlier draft of 0012 that was never
 * applied to any live database (per the task's own "Do NOT create 0013
 * just to fix an undeployed bad 0012").
 *
 * Same dual-location pointer pattern as 0001-0011 — the canonical DDL
 * lives in exactly one place in this monorepo (/database/schema-v1-0012-
 * production-flow-completion.sql), so this file never carries a second copy.
 *
 * Two possible locations, tried in order:
 *   1. api/app/database/schema-v1-0012-production-flow-completion.sql —
 *      placed here ONLY by the cPanel easy-install packaging script,
 *      because the shipped ZIP does not include the whole monorepo.
 *   2. The monorepo's top-level database/schema-v1-0012-production-
 *      flow-completion.sql — used for local development and the
 *      disposable-MariaDB test suite.
 * Exactly one of these exists in any given deployment.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0012-production-flow-completion.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0012-production-flow-completion.sql';
return is_file($packaged) ? $packaged : $monorepo;
