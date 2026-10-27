<?php

declare(strict_types=1);

/**
 * Migration 0026: store reports — Retur / Reject / Mutasi submitted as one
 * transaction, Head Store (Atasan) approval, Admin physical validation,
 * master reject reasons.
 *
 * See database/schema-v1-0026-store-report.sql for the full rationale.
 * Additive only (new tables, ADD COLUMN IF NOT EXISTS, INSERT IGNORE).
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0026-store-report.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0026-store-report.sql';
return is_file($packaged) ? $packaged : $monorepo;
