-- ============================================================================
-- Migration 0013 — LIVE SCHEMA REPAIR for migration 0012
-- (production_flow_completion / special-order FG allocation bridge)
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- Live cPanel diagnostic (authenticated, direct) on database
-- u7566812_factory confirmed: schema_migrations already has a row for
-- 0012_production_flow_completion.php, BUT special_order_fg_allocation
-- does not exist. This is only possible if 0012 was applied to that
-- database from an EARLIER VERSION of database/schema-v1-0012-production-
-- flow-completion.sql than the one now in this repository.
--
-- This repository's own git history confirms 0012's SQL went through four
-- real revisions before this repair was written:
--   84615ec  original draft (documented in a later revision's own
--            comment as "never applied to any live database" AT THE TIME
--            that later revision was written — i.e. this draft's shape,
--            with a nullable special_order_do.store_id, a 'draft/ready/
--            shipped/cancelled' status enum, and an actual_ship_qty
--            column, is confirmed to predate any live deployment and is
--            NOT a state this repair needs to migrate FROM)
--   81133f0  "Rework migration 0012": real shipment writes, Driver
--            Internal + External Courier fulfillment, multi-DO partial
--            shipping, FG double-consumption safety — this is the first
--            revision confirmed possible to have been live-deployed
--   7c30712  "Final pre-live rework": adds shipment_receipt_token +
--            widens shipment_receipt_item for special-order receipt
--            lines — commit message and timing make this the most likely
--            revision actually shipped to and applied on live cPanel
--   4febade  adds special_order_fg_allocation + widens
--            stock_ledger.source_type — the CURRENT final 0012, and the
--            first revision live is CONFIRMED to be missing
--
-- The migration runner (MigrationRunner::applyPending) tracks applied
-- state purely by filename, never by content hash — so once
-- "0012_production_flow_completion.php" is recorded, the runner will
-- never re-execute the .sql file it points to again, no matter how that
-- file's contents change. This 0013 is therefore the repair path: it
-- does NOT touch the 0012 registry row (never deleted, never re-run,
-- never renamed), and instead brings whichever of the above states is
-- actually live up to the exact schema the current application code
-- requires — additively, safely, and without assuming which one of
-- 81133f0 / 7c30712 / already-current the live database happens to be.
--
-- IDEMPOTENCY STRATEGY
-- ---------------------
-- Every statement below is written to be safe to execute against ANY of
-- the three possible live starting states (81133f0-shaped, 7c30712-
-- shaped, or already fully current) without error and without altering
-- data:
--   - CREATE TABLE IF NOT EXISTS — MariaDB-native, safe no-op if the
--     table already exists in its final shape (and every 0012-introduced
--     table's shape has been STABLE across all revisions that could
--     plausibly be live — see the per-table notes below).
--   - ADD COLUMN IF NOT EXISTS — MariaDB-native, safe no-op if the column
--     is already present.
--   - MODIFY COLUMN (nullability changes, ENUM widening) — always safe
--     to reissue: MariaDB simply redeclares the column to the same
--     final definition if it already matches, and existing rows/values
--     are preserved either way. Never narrows an ENUM (see below).
--   - Foreign keys: MariaDB has no "ADD CONSTRAINT IF NOT EXISTS ...
--     FOREIGN KEY" (empirically confirmed: this is a hard SQL syntax
--     error on MariaDB 10.11, not merely unsupported-but-ignored).
--     MariaDB DOES support "DROP FOREIGN KEY IF EXISTS" (empirically
--     confirmed safe/idempotent whether or not the named constraint
--     exists). Both FK additions below therefore use the pattern
--     "DROP FOREIGN KEY IF EXISTS <name>; ADD CONSTRAINT <name> FOREIGN
--     KEY (...) REFERENCES ...;" — this always ends with the exact same
--     FK present, regardless of whether it already existed, and is safe
--     to run any number of times.
--
-- PER-TABLE DRIFT NOTES (why CREATE TABLE IF NOT EXISTS alone is safe
-- for each 0012-introduced table, never silently masking a structural
-- mismatch):
--   - special_order_do / special_order_do_item / special_order_do_
--     shipment_item: structurally IDENTICAL between 81133f0 and 7c30712
--     (confirmed by diff) and untouched again through 4febade — so
--     ANY live database that has these tables at all already has them
--     in their exact final shape. CREATE TABLE IF NOT EXISTS is
--     therefore either a real create (if genuinely missing) or a
--     guaranteed-safe no-op (if already correct) — never a silent
--     mismatch.
--   - shipment_receipt_token: did not exist before 7c30712. CREATE
--     TABLE IF NOT EXISTS creates it on an 81133f0-shaped live database,
--     no-ops on a 7c30712-or-later one.
--   - special_order_fg_allocation: did not exist before 4febade
--     (CONFIRMED missing on live by direct diagnostic). CREATE TABLE IF
--     NOT EXISTS creates it unconditionally on the current live state.
--
-- NO ENUM IS EVER NARROWED. shipment.source_type and stock_ledger.
-- source_type are both reissued here with their COMPLETE current value
-- lists (verified against every prior migration that has ever touched
-- either enum: 0001's original shipment.source_type baseline, 0005's
-- addition of stock_ledger 'fg_item', and 0012's own additions) — a
-- previous regression in this project's history already caught an enum
-- narrowing that accidentally dropped 'fg_item'; this migration is
-- written to make that class of mistake structurally impossible by
-- always listing the full historical value set.
--
-- NO BUSINESS LOGIC CHANGES. This migration only creates/widens schema
-- objects. It does not change allocation logic, production formulas, DO
-- rules, receipt rules, Driver flow, Regular PO, stock ledger semantics,
-- Invoice, or Replacement/Reject — those are unchanged application code
-- already deployed; this migration only makes the live schema able to
-- support them.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. special_order_item.extra_packaging / fg_verified_qty
-- ----------------------------------------------------------------------------
ALTER TABLE special_order_item
  ADD COLUMN IF NOT EXISTS extra_packaging DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER charge,
  ADD COLUMN IF NOT EXISTS fg_verified_qty DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER reject_produksi;

-- ----------------------------------------------------------------------------
-- 2. special_order_do / special_order_do_item / special_order_do_shipment_item
--    (see "PER-TABLE DRIFT NOTES" above — structurally stable since
--    81133f0, so CREATE TABLE IF NOT EXISTS is safe here without a
--    separate column-level repair)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS special_order_do (
  special_order_do_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no                    VARCHAR(50)     NOT NULL,
  tanggal                   DATE            NOT NULL,
  special_order_id          BIGINT UNSIGNED NOT NULL,
  source_type               ENUM('toko_khusus','non_toko') NOT NULL,
  factory_id                BIGINT UNSIGNED NOT NULL,
  drop_store_id              BIGINT UNSIGNED NOT NULL,
  delivery_method            ENUM('DRIVER_INTERNAL','EXTERNAL_COURIER') NOT NULL DEFAULT 'DRIVER_INTERNAL',
  courier_provider           ENUM('grab','gosend','lalamove','other') NULL,
  courier_name               VARCHAR(100)    NULL,
  external_order_reference   VARCHAR(100)    NULL,
  status                     ENUM('open','partial','shipped','cancelled') NOT NULL DEFAULT 'open',
  claimed_by_user_id         BIGINT UNSIGNED NULL,
  claimed_at                 DATETIME        NULL,
  customer_name              VARCHAR(255)    NULL,
  customer_contact           VARCHAR(100)    NULL,
  delivery_address           VARCHAR(500)    NULL,
  version                    INT UNSIGNED    NOT NULL DEFAULT 1,
  created_by                 BIGINT UNSIGNED NOT NULL,
  created_at                 DATETIME        NOT NULL,
  updated_at                 DATETIME        NULL,
  cancelled_at                DATETIME        NULL,
  cancelled_by                BIGINT UNSIGNED NULL,
  cancel_reason                VARCHAR(500)    NULL,
  UNIQUE KEY uq_special_order_do_no (doc_no),
  KEY ix_sodo_order (special_order_id),
  KEY ix_sodo_status (status),
  KEY ix_sodo_drop_store (drop_store_id),
  KEY ix_sodo_factory (factory_id),
  KEY ix_sodo_delivery_method (delivery_method),
  KEY ix_sodo_claimed_by (claimed_by_user_id),
  CONSTRAINT fk_sodo_order FOREIGN KEY (special_order_id) REFERENCES special_order(special_order_id),
  CONSTRAINT fk_sodo_drop_store FOREIGN KEY (drop_store_id) REFERENCES store(store_id),
  CONSTRAINT fk_sodo_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id),
  CONSTRAINT fk_sodo_claimed_by FOREIGN KEY (claimed_by_user_id) REFERENCES users(user_id),
  CONSTRAINT fk_sodo_created_by FOREIGN KEY (created_by) REFERENCES users(user_id),
  CONSTRAINT fk_sodo_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS special_order_do_item (
  special_order_do_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  special_order_do_id       BIGINT UNSIGNED NOT NULL,
  special_order_item_id     BIGINT UNSIGNED NOT NULL,
  planned_qty                DECIMAL(12,2)  NOT NULL,
  UNIQUE KEY uq_sodo_item (special_order_do_id, special_order_item_id),
  KEY ix_sodoi_item (special_order_item_id),
  CONSTRAINT fk_sodoi_do FOREIGN KEY (special_order_do_id) REFERENCES special_order_do(special_order_do_id) ON DELETE CASCADE,
  CONSTRAINT fk_sodoi_item FOREIGN KEY (special_order_item_id) REFERENCES special_order_item(special_order_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS special_order_do_shipment_item (
  special_order_do_shipment_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id                        BIGINT UNSIGNED NOT NULL,
  special_order_do_item_id           BIGINT UNSIGNED NOT NULL,
  qty                                 DECIMAL(12,2)  NOT NULL,
  created_at                          DATETIME       NOT NULL,
  KEY ix_sodsi_shipment (shipment_id),
  KEY ix_sodsi_do_item (special_order_do_item_id),
  CONSTRAINT fk_sodsi_shipment FOREIGN KEY (shipment_id) REFERENCES shipment(shipment_id) ON DELETE CASCADE,
  CONSTRAINT fk_sodsi_do_item FOREIGN KEY (special_order_do_item_id) REFERENCES special_order_do_item(special_order_do_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 3. shipment: widen source_type ENUM (never narrowed — the full value
--    set from the original 0001 baseline, 'delivery_order'/'manual_kirim'/
--    'customer_order_fulfillment', plus 0012's own 'special_order_do'),
--    and add every 0012 column. All ADD COLUMN IF NOT EXISTS / MODIFY
--    COLUMN here are safe against an 81133f0-shaped live database
--    (delivery_method/courier_*/external_order_reference/handover_note
--    genuinely missing), a 7c30712-shaped one (all already present), or
--    already-current.
-- ----------------------------------------------------------------------------
ALTER TABLE shipment
  MODIFY COLUMN source_type ENUM('delivery_order','manual_kirim','customer_order_fulfillment','special_order_do') NOT NULL,
  ADD COLUMN IF NOT EXISTS special_order_do_id BIGINT UNSIGNED NULL AFTER customer_order_id,
  ADD COLUMN IF NOT EXISTS delivery_method ENUM('DRIVER_INTERNAL','EXTERNAL_COURIER') NULL AFTER special_order_do_id,
  ADD COLUMN IF NOT EXISTS courier_provider ENUM('grab','gosend','lalamove','other') NULL AFTER delivery_method,
  ADD COLUMN IF NOT EXISTS courier_name VARCHAR(100) NULL AFTER courier_provider,
  ADD COLUMN IF NOT EXISTS external_order_reference VARCHAR(100) NULL AFTER courier_name,
  ADD COLUMN IF NOT EXISTS handover_note VARCHAR(500) NULL AFTER external_order_reference;

-- MariaDB has no "ADD CONSTRAINT IF NOT EXISTS ... FOREIGN KEY" (empirically
-- confirmed: hard syntax error on 10.11) — DROP FOREIGN KEY IF EXISTS is
-- supported and idempotent, so drop-then-add always converges on the same
-- FK regardless of starting state (present on a 7c30712-or-later live
-- database, absent on an 81133f0-shaped one).
ALTER TABLE shipment DROP FOREIGN KEY IF EXISTS fk_shipment_special_order_do;
ALTER TABLE shipment
  ADD CONSTRAINT fk_shipment_special_order_do FOREIGN KEY (special_order_do_id) REFERENCES special_order_do(special_order_do_id);

-- ----------------------------------------------------------------------------
-- 4. shipment_receipt_token — did not exist before 7c30712; missing on an
--    81133f0-shaped live database, present on a 7c30712-or-later one.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shipment_receipt_token (
  shipment_receipt_token_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id                BIGINT UNSIGNED NOT NULL,
  token                       CHAR(64)        NOT NULL,
  created_at                  DATETIME        NOT NULL,
  UNIQUE KEY uq_shipment_receipt_token_shipment (shipment_id),
  UNIQUE KEY uq_shipment_receipt_token_token (token),
  CONSTRAINT fk_srt_shipment FOREIGN KEY (shipment_id) REFERENCES shipment(shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 5. shipment_receipt_item — widen to accept a special-order line (never
--    narrows shipment_item_id/product_id; only makes them nullable).
--    Missing on an 81133f0-shaped live database, present on a
--    7c30712-or-later one.
-- ----------------------------------------------------------------------------
ALTER TABLE shipment_receipt_item
  MODIFY COLUMN shipment_item_id BIGINT UNSIGNED NULL,
  MODIFY COLUMN product_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS special_order_do_shipment_item_id BIGINT UNSIGNED NULL AFTER shipment_item_id,
  ADD COLUMN IF NOT EXISTS item_name_snapshot VARCHAR(255) NULL AFTER product_id;

ALTER TABLE shipment_receipt_item DROP FOREIGN KEY IF EXISTS fk_sri_special_line;
ALTER TABLE shipment_receipt_item
  ADD CONSTRAINT fk_sri_special_line FOREIGN KEY (special_order_do_shipment_item_id) REFERENCES special_order_do_shipment_item(special_order_do_shipment_item_id);

-- ----------------------------------------------------------------------------
-- 6. special_order_fg_allocation — CONFIRMED MISSING on live by direct
--    authenticated diagnostic. This is the table whose absence causes
--    Produksi -> Order Masuk / Demand Tambahan to render blank (the page
--    reaches SpecialOrderFgAllocationRepository, which queries this
--    table, and the query fails against a live database that never had
--    it created).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS special_order_fg_allocation (
  special_order_fg_allocation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  special_order_item_id           BIGINT UNSIGNED NOT NULL,
  special_order_id                 BIGINT UNSIGNED NOT NULL,
  source_type                       ENUM('toko_khusus','non_toko') NOT NULL,
  product_id                         BIGINT UNSIGNED NOT NULL,
  factory_id                         BIGINT UNSIGNED NOT NULL,
  allocated_qty                      DECIMAL(12,2)  NOT NULL,
  consumed_qty                        DECIMAL(12,2)  NOT NULL DEFAULT 0,
  released_qty                        DECIMAL(12,2)  NOT NULL DEFAULT 0,
  status                              ENUM('active','partially_consumed','consumed','released') NOT NULL DEFAULT 'active',
  created_by                          BIGINT UNSIGNED NOT NULL,
  created_at                          DATETIME       NOT NULL,
  updated_at                          DATETIME       NULL,
  KEY ix_sofa_item (special_order_item_id),
  KEY ix_sofa_order (special_order_id),
  KEY ix_sofa_product_factory (product_id, factory_id),
  KEY ix_sofa_status (status),
  CONSTRAINT fk_sofa_item FOREIGN KEY (special_order_item_id) REFERENCES special_order_item(special_order_item_id),
  CONSTRAINT fk_sofa_order FOREIGN KEY (special_order_id) REFERENCES special_order(special_order_id),
  CONSTRAINT fk_sofa_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_sofa_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id),
  CONSTRAINT fk_sofa_created_by FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 7. stock_ledger.source_type — widen, NEVER narrow. Full value set
--    verified against every migration that has ever touched this enum:
--    the original 0001 baseline (production_run/shipment_item/
--    stock_adjustment/stock_transfer/opening_balance_cutover/
--    historical_replay/reversal), 0005's addition of 'fg_item', and
--    0012's own addition of 'special_order_fg_allocation'. Omitting any
--    one of these would silently break every existing/future row of
--    that source_type — a regression this project has already been
--    burned by once (an earlier draft accidentally dropped 'fg_item');
--    this migration lists the complete historical set explicitly so
--    that mistake cannot repeat here.
-- ----------------------------------------------------------------------------
ALTER TABLE stock_ledger
  MODIFY COLUMN source_type ENUM('production_run','shipment_item','stock_adjustment','stock_transfer','opening_balance_cutover','historical_replay','reversal','fg_item','special_order_fg_allocation') NOT NULL;
