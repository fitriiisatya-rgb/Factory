-- ============================================================================
-- Migration 0014 — Production Division access wiring + FG Reject/Hilang
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- Part of the "Production Division + FG & Packing" rework. Audit of the
-- live schema (migrations 0001-0013) found that almost everything this
-- rework needs already exists:
--
--   - user_factory_access / user_division_access (many-to-many user<->
--     factory/division) were already created by the ORIGINAL 0001 schema
--     as "future scope, not necessarily enforced by v1 API" and were never
--     wired into any repository/service/controller. This migration does
--     NOT recreate them — application code (Auth/UserRepository/
--     ProductionController/FgController) is wired to read them instead.
--   - production_run.version (optimistic lock) + its UNIQUE KEY
--     (tanggal, division_id) already give a shared-division-worksheet
--     concurrency guarantee — no schema change needed there.
--   - production_item.status ENUM('sesuai','tidak_sesuai') already exists
--     — only application code needs to start writing/enforcing it.
--
-- The ONE genuine schema gap found: fg_item has no reject/lost-quantity
-- tracking at all (reject only ever existed on the Production side, on
-- production_item.reject and special_order_item.reject_produksi). FG
-- needs its own independently-tracked Reject and Hilang (physically lost
-- during FG/packing handling) columns, per this rework's explicit
-- requirement that FG reject/hilang are separate from — never merged
-- into — Production's own reject or FG's Actual.
--
-- Both new columns are purely additive (DEFAULT 0, backward compatible —
-- every existing fg_item row reads as reject=0/hilang=0, identical to
-- today's implicit behavior) and idempotent (ADD COLUMN IF NOT EXISTS,
-- same convention as migration 0013).
-- ============================================================================

ALTER TABLE fg_item
  ADD COLUMN IF NOT EXISTS reject_qty DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER packed_qty,
  ADD COLUMN IF NOT EXISTS hilang_qty DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER reject_qty;

-- ============================================================================
-- Migration 0014 (extended) — Store-specific FG allocation guard for
-- Regular shipment (FINAL CORE BLOCKER: "Store A must never consume
-- Store B's ready/packed FG allocation").
-- ============================================================================
--
-- WHY THIS TABLE, AND WHY NOTHING ELSE
-- --------------------------------------
-- Deep-audit finding: the writable Breakdown Toko rework already made
-- fg_item's (fg_batch_id, product_id, store_id) rows the CANONICAL record
-- of "how much FG is packed for which store" — no new quantity table is
-- needed to represent that (it already exists, see FgRepository::
-- findItems()'s own docblock). Likewise "how much of a store's packed FG
-- has already left the building" is already the CANONICAL
-- shipment_item/shipment record Phase 5 already writes on every real
-- ship() — no new ledger is needed to represent that either.
--
-- What's genuinely missing is a LOCK ANCHOR: neither fg_item (spread
-- across however many fg_batch rows a store+product has accumulated over
-- time) nor shipment_item is a single, always-present row that two
-- concurrent writers (a Regular shipment vs an FG downward correction)
-- can both SELECT ... FOR UPDATE to serialize against each other for one
-- specific (store, product, location) triple — the same role
-- stock_balance already plays for the product+location-only dimension.
--
-- store_fg_balance exists PURELY to be that lock row. It carries NO
-- quantity column on purpose — "packed" is always re-summed live from
-- fg_item (submitted batches only, or the batch currently being
-- submitted), "shipped" is always re-summed live from shipment_item
-- (active, source_type='delivery_order' only, i.e. Regular shipments
-- only — Special/CS/Sales/Direct/General orders use a completely
-- different allocation path, special_order_fg_allocation, untouched by
-- this table). This is the same "cache vs primary truth" discipline
-- documented for stock_balance itself in docs/mysql-schema-v1.md §5 —
-- except here there is not even a cache, only the lock.
--
-- Canonical lock order (see Delivery\ShipmentService's own docblock,
-- extended by this change): stock_balance FIRST, THEN store_fg_balance,
-- THEN a live read of special_order_fg_allocation's active sum, THEN
-- shipment/ledger writes. Every writer that touches both stock_balance
-- and store_fg_balance for the same product acquires them in exactly
-- this order (Delivery\ShipmentService::ship(), Fg\FgService::submit()),
-- so no deadlock cycle is possible between them.
CREATE TABLE IF NOT EXISTS store_fg_balance (
  store_id     BIGINT UNSIGNED NOT NULL,
  product_id   BIGINT UNSIGNED NOT NULL,
  location_id  BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (store_id, product_id, location_id),
  CONSTRAINT fk_sfb_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_sfb_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_sfb_location FOREIGN KEY (location_id) REFERENCES location(location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- fg_item.posted_packed_qty: a per-row FROZEN SNAPSHOT of packed_qty as of
-- this row's own LAST submit() — never touched by a draft edit, never
-- touched by reopen. This is what DoRepository::sumPackedForStore() reads
-- (never the live, possibly-mid-edit packed_qty) so that a store's ready
-- allocation always reflects what is ACTUALLY sitting in the warehouse
-- (i.e. was really posted to stock_ledger) rather than a number an
-- operator has started editing in a reopened-but-not-yet-resubmitted
-- batch. Without this column, a reopened batch's real, already-shipped-
-- against stock would incorrectly vanish from every store-ready
-- calculation the instant it was reopened, even though reopen itself
-- never touches stock_ledger (see Fg\FgService::reopen()'s own
-- docblock) — the physical stock never actually moved.
ALTER TABLE fg_item
  ADD COLUMN IF NOT EXISTS posted_packed_qty DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER hilang_qty;
