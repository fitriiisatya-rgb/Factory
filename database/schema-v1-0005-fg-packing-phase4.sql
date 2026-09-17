-- ============================================================================
-- Migration 0005: FG/Packing Phase 4 (fast-track)
-- ============================================================================
-- Additive only — reuses fg_batch/fg_batch_source/fg_item/location/
-- stock_ledger/stock_balance, all already created by 0001 (same "tables
-- exist, logic doesn't yet" situation Production was in before Phase 3,
-- and DO/Shipment still is — see docs/mysql-do-shipment-phase5-
-- reservation-v1.md, entirely untouched by this migration). No Phase 0/1/
-- 2/3 table is recreated, dropped, or has a column removed. No PO,
-- Production, or master-identity data is touched.
--
-- 1. location.factory_id (new, NULLABLE): the original v1 draft
--    deliberately seeded `location` with a single pooled 'GUDANG UTAMA'
--    row (docs/mysql-schema-v1.md §5.1 — "current app has one warehouse
--    concept only... kept as its own table so multi-location stock never
--    requires a schema change later"). Phase 4's real requirement is
--    factory-scoped FG availability (Karangtengah and Cibadak produce and
--    pack independently), which this column makes possible WITHOUT
--    changing stock_ledger/stock_balance's own shape at all — they already
--    key on location_id. NULLABLE (not NOT NULL) keeps the door open for a
--    future non-factory-scoped location, matching the original design's
--    own stated intent. No row is seeded by this migration — FgRepository
--    lazily creates one `location` row per factory on that factory's first
--    FG submission (same find-or-create pattern PoRepository already uses
--    for po_batch), so a factory that never does Phase 4 work never gets
--    an unused location row.
--
-- 2. fg_batch gains the same lifecycle/attribution columns migration 0004
--    added to production_run: status (draft/submitted/reopened — no
--    'resubmitted' value; resubmitting is calling submit() again from
--    'reopened', landing back at 'submitted', exactly like Production),
--    created_by, submitted_by, submitted_at, reopened_by, reopened_at,
--    reopen_reason. The pre-existing `ready_at` column is NOT reused or
--    repurposed for this — it predates this phase's design (see the
--    fg_batch_source comment in schema-v1.sql referencing the legacy
--    handleFgReady_ handler) and may still matter for a future Phase 5
--    "ready for DO" signal; Phase 4 adds its own submitted_at alongside it
--    rather than overloading an existing column's meaning.
--
-- 3. fg_item gains packed_qty (the distinct "Packed" number — the existing
--    `qty` column becomes, by application convention only, "FG Verified
--    qty"; never renamed, purely additive) and production_actual_snapshot
--    (the source Production actual this fg_item was created/refreshed
--    against, snapshot semantics matching Phase 3's production_item.target
--    exactly). fg_item.store_id (NOT NULL since 0001) is satisfied using
--    the same synthetic "NON-OUTLET / PERORANGAN" store row PoResolver's
--    unallocatedStoreId() already uses — Phase 4 does no per-store
--    allocation at all (that is Phase 5's DO concern), so every fg_item
--    row uses this one placeholder store, never a real one.
--
-- 4. stock_ledger.source_type gains one new ENUM value, 'fg_item' — a safe
--    additive MODIFY COLUMN (every existing value is preserved verbatim,
--    so no existing row's stored value is invalidated; stock_ledger has
--    zero rows in every deployment today regardless, since no code has
--    ever written to it before this phase). This refines, rather than
--    contradicts, the original design's own mapping table
--    (docs/mysql-schema-v1.md §6: "FG-verified production submitted
--    (MASUK) -> production_in / production_run / production_run_id",
--    written before Phase 3/4's detailed design existed) — the actual
--    source of a stock movement is now traceable to the specific fg_item
--    (one ledger row per fg_item per posting, mirroring the existing
--    'shipment_item' source_type precedent exactly), which is what makes
--    exact compensating-delta corrections auditable per product. Unlike
--    ADD COLUMN/ADD KEY, MariaDB has no `MODIFY COLUMN IF NOT EXISTS` —
--    this statement relies on the migration runner's schema_migrations
--    bookkeeping alone to never re-apply this file a second time, same as
--    every ADD CONSTRAINT statement in migrations 0002-0004.
--
-- Idempotency discipline: identical to 0002-0004 — every ADD COLUMN/ADD
-- KEY uses IF NOT EXISTS (confirmed supported on MariaDB 10.11.x); ADD
-- CONSTRAINT and MODIFY COLUMN do not support IF NOT EXISTS on MariaDB, so
-- those are listed last.

ALTER TABLE location
  ADD COLUMN IF NOT EXISTS factory_id BIGINT UNSIGNED NULL AFTER name;

ALTER TABLE location
  ADD KEY IF NOT EXISTS ix_location_factory (factory_id);

ALTER TABLE fg_batch
  ADD COLUMN IF NOT EXISTS status ENUM('draft','submitted','reopened') NOT NULL DEFAULT 'draft' AFTER factory_id;

ALTER TABLE fg_batch
  ADD COLUMN IF NOT EXISTS created_by BIGINT UNSIGNED NULL AFTER status;

ALTER TABLE fg_batch
  ADD COLUMN IF NOT EXISTS submitted_by BIGINT UNSIGNED NULL AFTER ready_at;

ALTER TABLE fg_batch
  ADD COLUMN IF NOT EXISTS submitted_at DATETIME NULL AFTER submitted_by;

ALTER TABLE fg_batch
  ADD COLUMN IF NOT EXISTS reopened_by BIGINT UNSIGNED NULL AFTER submitted_at;

ALTER TABLE fg_batch
  ADD COLUMN IF NOT EXISTS reopened_at DATETIME NULL AFTER reopened_by;

ALTER TABLE fg_batch
  ADD COLUMN IF NOT EXISTS reopen_reason TEXT NULL AFTER reopened_at;

ALTER TABLE fg_batch
  ADD KEY IF NOT EXISTS ix_fg_batch_status (status);

ALTER TABLE fg_batch
  ADD KEY IF NOT EXISTS ix_fg_batch_created_by (created_by);

ALTER TABLE fg_batch
  ADD KEY IF NOT EXISTS ix_fg_batch_submitted_by (submitted_by);

ALTER TABLE fg_batch
  ADD KEY IF NOT EXISTS ix_fg_batch_reopened_by (reopened_by);

ALTER TABLE fg_item
  ADD COLUMN IF NOT EXISTS packed_qty DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER qty;

ALTER TABLE fg_item
  ADD COLUMN IF NOT EXISTS production_actual_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER packed_qty;

ALTER TABLE location
  ADD CONSTRAINT fk_location_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id);

ALTER TABLE fg_batch
  ADD CONSTRAINT fk_fg_batch_created_by FOREIGN KEY (created_by) REFERENCES users(user_id);

ALTER TABLE fg_batch
  ADD CONSTRAINT fk_fg_batch_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(user_id);

ALTER TABLE fg_batch
  ADD CONSTRAINT fk_fg_batch_reopened_by FOREIGN KEY (reopened_by) REFERENCES users(user_id);

ALTER TABLE stock_ledger
  MODIFY COLUMN source_type ENUM('production_run','shipment_item','stock_adjustment','stock_transfer','opening_balance_cutover','historical_replay','reversal','fg_item') NOT NULL;
