-- ============================================================================
-- Migration 0002: master identity (Phase 1 fast-track)
-- ============================================================================
-- Two justified, minimal changes — no tables recreated, no business/
-- transaction tables touched:
--
-- 1. division.factory_id: the original 45-table design left `division`
--    factory-independent (production_run keys on (tanggal, division_id) only).
--    Phase 1 needs a persisted division -> factory mapping (Bolu -> Cibadak,
--    every other real production division -> Karangtengah, confirmed against
--    divisiDari()/fgFactoryForDivisi() in the frontend source — see
--    dist/tools/extract-legacy-source.php). NOT NULL is safe here because
--    `division` has never been seeded before this migration (Phase 0 seeds
--    factories/roles/the synthetic store only) — there are zero existing
--    rows that would need backfilling.
--
-- 2. Indexes on migration_product_map.status / migration_store_map.status:
--    the Phase 1 admin review API (GET .../products?status=unresolved etc.)
--    filters on this column; it had no index before.
--
-- Safe to re-run at TWO levels: (1) the migration runner tracks this whole
-- file as applied/not-applied in schema_migrations, exactly like 0001 — a
-- normal rerun never gets here a second time; (2) as defense in depth for
-- someone applying this .sql file directly (bypassing the runner), every
-- ADD COLUMN / ADD KEY below uses IF NOT EXISTS (confirmed supported on
-- MariaDB 10.11.19 — verified empirically against a disposable instance,
-- see the Phase 1 test suite). The one exception is the FOREIGN KEY
-- constraint: MariaDB does not support `ADD CONSTRAINT IF NOT EXISTS ...
-- FOREIGN KEY` (confirmed empirically — syntax error), so that one
-- statement relies on level (1) alone; it is listed last so a from-scratch
-- direct-apply failure on a rerun happens after the safe/idempotent parts,
-- not before them.

ALTER TABLE division
  ADD COLUMN IF NOT EXISTS factory_id BIGINT UNSIGNED NOT NULL AFTER name;

ALTER TABLE division
  ADD KEY IF NOT EXISTS ix_division_factory (factory_id);

ALTER TABLE migration_product_map
  ADD KEY IF NOT EXISTS ix_migration_product_status (status);

ALTER TABLE migration_store_map
  ADD KEY IF NOT EXISTS ix_migration_store_status (status);

ALTER TABLE division
  ADD CONSTRAINT fk_division_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id);
