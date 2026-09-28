<?php

declare(strict_types=1);

/**
 * Migration 0016: Replacement Reject end-to-end — approved-reject
 * disposition (Reject Final / Kirim Ulang), replacement_demand +
 * replacement_demand_fg_allocation + replacement_do + replacement_do_
 * shipment_item, and the additive shipment_receipt_item / shipment /
 * stock_ledger widenings this feature needs.
 *
 * See database/schema-v1-0016-replacement-reject.sql for the full
 * rationale and the data-model audit performed before writing it. This is
 * the FIRST schema change since 0015 (already LIVE, untouched here) —
 * every ALTER is additive (ADD COLUMN IF NOT EXISTS / widen-only ENUM),
 * every CREATE TABLE is IF NOT EXISTS, no existing row anywhere is
 * touched.
 *
 * Same dual-location pointer pattern as every prior migration — the
 * canonical DDL lives in exactly one place in this monorepo
 * (/database/schema-v1-0016-replacement-reject.sql), so this file never
 * carries a second copy.
 *
 * Two possible locations, tried in order:
 *   1. api/app/database/schema-v1-0016-replacement-reject.sql — placed
 *      here ONLY by the cPanel easy-install packaging script, because the
 *      shipped ZIP does not include the whole monorepo.
 *   2. The monorepo's top-level database/schema-v1-0016-replacement-
 *      reject.sql — used for local development and the disposable-
 *      MariaDB test suite.
 * Exactly one of these exists in any given deployment.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0016-replacement-reject.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0016-replacement-reject.sql';
return is_file($packaged) ? $packaged : $monorepo;
