<?php

declare(strict_types=1);

/**
 * Migration 0015: real persisted Packing submission state per (FG batch,
 * store) — fg_store_packing_submission.
 *
 * See database/schema-v1-0015-fg-store-packing-submission.sql for the
 * full rationale: packed_qty was an invalid proxy for "has this store's
 * Packing actually been submitted" (a submit can legitimately leave
 * packed_qty below target, or even at 0), and no existing table already
 * carried this truth. This is the FIRST schema change since 0014 (which
 * is already LIVE and untouched here) — purely additive, one new table,
 * no existing table altered.
 *
 * Same dual-location pointer pattern as every prior migration — the
 * canonical DDL lives in exactly one place in this monorepo
 * (/database/schema-v1-0015-fg-store-packing-submission.sql), so this
 * file never carries a second copy.
 *
 * Two possible locations, tried in order:
 *   1. api/app/database/schema-v1-0015-fg-store-packing-submission.sql
 *      — placed here ONLY by the cPanel easy-install packaging script,
 *      because the shipped ZIP does not include the whole monorepo.
 *   2. The monorepo's top-level database/schema-v1-0015-fg-store-
 *      packing-submission.sql — used for local development and the
 *      disposable-MariaDB test suite.
 * Exactly one of these exists in any given deployment.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0015-fg-store-packing-submission.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0015-fg-store-packing-submission.sql';
return is_file($packaged) ? $packaged : $monorepo;
