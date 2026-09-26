<?php

declare(strict_types=1);

/**
 * Migration 0014: Production Division access wiring + FG Reject/Hilang.
 *
 * See database/schema-v1-0014-production-fg-division-rework.sql for the
 * full rationale. In short: the many-to-many user<->factory/division
 * access tables and the shared-worksheet optimistic-lock columns this
 * rework needs already existed (added by the original 0001 schema as
 * unused "future scope") — only fg_item.reject_qty/hilang_qty are new.
 *
 * Same dual-location pointer pattern as every prior migration — the
 * canonical DDL lives in exactly one place in this monorepo
 * (/database/schema-v1-0014-production-fg-division-rework.sql), so this
 * file never carries a second copy.
 *
 * Two possible locations, tried in order:
 *   1. api/app/database/schema-v1-0014-production-fg-division-rework.sql
 *      — placed here ONLY by the cPanel easy-install packaging script,
 *      because the shipped ZIP does not include the whole monorepo.
 *   2. The monorepo's top-level database/schema-v1-0014-production-fg-
 *      division-rework.sql — used for local development and the
 *      disposable-MariaDB test suite.
 * Exactly one of these exists in any given deployment.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0014-production-fg-division-rework.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0014-production-fg-division-rework.sql';
return is_file($packaged) ? $packaged : $monorepo;
