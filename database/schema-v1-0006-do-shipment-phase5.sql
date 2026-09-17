-- ============================================================================
-- Migration 0006: Draft DO + staged Shipment Phase 5 (fast-track)
-- ============================================================================
-- Additive only — reuses delivery_order/delivery_order_item/shipment/
-- shipment_item/stock_ledger/stock_balance/document_sequence, ALL already
-- created by 0001 (same "tables exist, logic doesn't yet" situation
-- Production/FG were in before Phases 3/4 built logic on top of them). No
-- Phase 0/1/2/3/4 table is recreated, dropped, or has a column removed. No
-- PO, Production, or FG data is touched by this file. `stock_ledger` needs
-- NO change at all: `event_type='shipment_out'` and
-- `source_type='shipment_item'` were both already part of the original
-- 0001 ENUM definitions, unlike Phase 4's `fg_item` addition.
--
-- 1. delivery_order.open_key_by_store (new, additive-only, does NOT
--    replace the existing open_key/uq_delivery_order_open): the original
--    v1 draft scoped "one open DO" by (tanggal, store_id, shipment_group),
--    which does not match the FINAL business rule — one store PO produces
--    ONE Delivery Order per (tanggal, store_id), regardless of
--    shipment_group, fulfilled by zero/one/many `shipment` rows of
--    possibly different groups. This exact correction, and the exact
--    migration shape below, was already designed and locked in
--    docs/mysql-do-shipment-phase5-reservation-v1.md §8.2 before this
--    phase began — see that document for the full audit of why the old
--    key is wrong and why the new one is added alongside it rather than
--    replacing it (the old key becomes harmlessly dormant once
--    `delivery_order.shipment_group` stops being varied per-DO by
--    application code — never dropped in this phase per the explicit
--    "additive/non-destructive, do not drop the old key unless absolutely
--    necessary" instruction).
--
-- 2. delivery_order.source_po_version_json (new): a DO's planned demand
--    can legitimately span MORE THAN ONE po_batch — a store can appear in
--    both Karangtengah's and Cibadak's PO for the same date (Karangtengah
--    makes non-Bolu, Cibadak makes Bolu; a single retail store can order
--    both in one day) — so a single INT "source version" column (the
--    pattern migrations 0004/0005 used for Production/FG, each scoped to
--    exactly one upstream batch) does not fit here. Stores
--    {factoryId: poBatchVersion} as JSON, mirroring the already-precedented
--    legacy `FGReady.SourceVersionJSON` pattern audited in Phase 3
--    (`handleFgReady_`, backend/Code.gs) — snapshotting multiple upstream
--    versions on one parent row is not a new technique in this codebase.
--
-- 3. delivery_order.preprinted_by / cancelled_at / cancelled_by /
--    cancel_reason (new): audit attribution for two DO actions the
--    original 0001 schema anticipated in its status ENUM
--    ('preprinted'/'cancelled') but never gave attribution columns for
--    (preprinted_at existed with no preprinted_by; cancelled had no
--    timestamp/actor/reason columns at all).
--
-- 4. shipment.factory_id (new): a shipment is a physical dispatch from ONE
--    factory's warehouse — needed to scope which factory's FG stock
--    (Phase 4's `location`, itself scoped by `location.factory_id` since
--    migration 0005) a given shipment consumes from, and to enforce
--    Karangtengah/Cibadak stock never mixes. Nullable (matching the same
--    nullable-with-app-always-populating convention already used for
--    every other attribution FK column in this codebase — e.g.
--    production_run.created_by, fg_batch.created_by).
--
-- 5. shipment.created_by / shipment.shipped_by / shipment.shipped_at
--    (new): the original 0001 shipment header had `voided_by` for the one
--    lifecycle transition it anticipated (active->void) but no attribution
--    at all for the create/ship event itself. In this phase's design (see
--    docs/mysql-do-shipment-phase5-reservation-v1.md §3 "shipment" and
--    task section 14 — no persisted draft-shipment row; a `shipment` row
--    is only ever created AT actual-ship-commit time), created_by and
--    shipped_by are always written with the same user/moment — both
--    columns are kept, matching the task's explicit field list, for
--    schema completeness and forward compatibility should a future phase
--    ever separate draft-shipment staging from commit.
--
-- 6. shipment_item.delivery_order_item_id / shipment_item.notes (new): a
--    precise back-reference from a shipment line to the DO item it
--    fulfills (nullable — a `manual_kirim`/`customer_order_fulfillment`
--    shipment per the original source_type ENUM has no DO item at all),
--    and a notes field for the picking/dispatch document. The existing
--    `qty` column is kept as-is (never renamed) and is, by application
--    convention, the actual shipped quantity for that line — there is no
--    separate planned/actual split at the shipment_item level because a
--    shipment_item, once it exists, already represents something that
--    physically happened.
--
-- Same idempotency discipline as 0002-0005: every ADD COLUMN/ADD KEY uses
-- IF NOT EXISTS (confirmed supported on MariaDB 10.11.x). ADD CONSTRAINT
-- does not support IF NOT EXISTS on MariaDB, so those are listed last and
-- rely on the migration runner's schema_migrations bookkeeping alone to
-- never re-apply this file a second time.

ALTER TABLE delivery_order
  ADD COLUMN IF NOT EXISTS open_key_by_store VARCHAR(80) GENERATED ALWAYS AS (
    CASE WHEN status NOT IN ('shipped','cancelled')
         THEN CONCAT(tanggal, '|', store_id)
         ELSE NULL END
  ) STORED AFTER open_key;

ALTER TABLE delivery_order
  ADD UNIQUE KEY IF NOT EXISTS uq_delivery_order_open_store (open_key_by_store);

ALTER TABLE delivery_order
  ADD COLUMN IF NOT EXISTS source_po_version_json TEXT NULL AFTER catatan;

ALTER TABLE delivery_order
  ADD COLUMN IF NOT EXISTS preprinted_by BIGINT UNSIGNED NULL AFTER preprinted_at;

ALTER TABLE delivery_order
  ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER shipped_by;

ALTER TABLE delivery_order
  ADD COLUMN IF NOT EXISTS cancelled_by BIGINT UNSIGNED NULL AFTER cancelled_at;

ALTER TABLE delivery_order
  ADD COLUMN IF NOT EXISTS cancel_reason VARCHAR(500) NULL AFTER cancelled_by;

ALTER TABLE delivery_order
  ADD KEY IF NOT EXISTS ix_delivery_order_preprinted_by (preprinted_by);

ALTER TABLE delivery_order
  ADD KEY IF NOT EXISTS ix_delivery_order_cancelled_by (cancelled_by);

ALTER TABLE shipment
  ADD COLUMN IF NOT EXISTS factory_id BIGINT UNSIGNED NULL AFTER store_id;

ALTER TABLE shipment
  ADD COLUMN IF NOT EXISTS created_by BIGINT UNSIGNED NULL AFTER version;

ALTER TABLE shipment
  ADD COLUMN IF NOT EXISTS shipped_by BIGINT UNSIGNED NULL AFTER created_by;

ALTER TABLE shipment
  ADD COLUMN IF NOT EXISTS shipped_at DATETIME NULL AFTER shipped_by;

ALTER TABLE shipment
  ADD KEY IF NOT EXISTS ix_shipment_factory (factory_id);

ALTER TABLE shipment
  ADD KEY IF NOT EXISTS ix_shipment_created_by (created_by);

ALTER TABLE shipment
  ADD KEY IF NOT EXISTS ix_shipment_shipped_by (shipped_by);

ALTER TABLE shipment_item
  ADD COLUMN IF NOT EXISTS delivery_order_item_id BIGINT UNSIGNED NULL AFTER product_id;

ALTER TABLE shipment_item
  ADD COLUMN IF NOT EXISTS notes VARCHAR(500) NULL AFTER qty;

ALTER TABLE shipment_item
  ADD KEY IF NOT EXISTS ix_shipment_item_do_item (delivery_order_item_id);

ALTER TABLE delivery_order
  ADD CONSTRAINT fk_do_preprinted_by FOREIGN KEY (preprinted_by) REFERENCES users(user_id);

ALTER TABLE delivery_order
  ADD CONSTRAINT fk_do_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(user_id);

ALTER TABLE shipment
  ADD CONSTRAINT fk_shipment_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id);

ALTER TABLE shipment
  ADD CONSTRAINT fk_shipment_created_by FOREIGN KEY (created_by) REFERENCES users(user_id);

ALTER TABLE shipment
  ADD CONSTRAINT fk_shipment_shipped_by FOREIGN KEY (shipped_by) REFERENCES users(user_id);

ALTER TABLE shipment_item
  ADD CONSTRAINT fk_shipment_item_do_item FOREIGN KEY (delivery_order_item_id) REFERENCES delivery_order_item(delivery_order_item_id);
