<?php

declare(strict_types=1);

/**
 * Migration 0002: master identity (Phase 1 fast-track).
 *
 * Adds division.factory_id (Bolu -> Cibadak, everything else -> Karangtengah
 * — see database/schema-v1-0002-master-identity.sql's own header comment for
 * why this is a genuinely new, justified column) and two indexes on
 * migration_product_map.status / migration_store_map.status for the Phase 1
 * admin review API. Does NOT recreate or touch the original 45 tables'
 * CREATE TABLE statements.
 *
 * Same dual-location pattern as 0001_schema_v1.php: the packaged copy
 * (shipped by the Phase 1 incremental ZIP) is tried first, falling back to
 * the monorepo's canonical top-level copy for local/CI use.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0002-master-identity.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0002-master-identity.sql';
return is_file($packaged) ? $packaged : $monorepo;
