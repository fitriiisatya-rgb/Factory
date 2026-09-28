-- ============================================================================
-- Migration 0016 — Replacement Reject end-to-end
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- Approved-UAT flow: when a store reports a shipment Reject and Admin
-- verifies it, Admin must make one explicit, permanent disposition —
-- REJECT FINAL / TIDAK DIGANTI (factory absorbs the loss, nothing else
-- happens) or KIRIM ULANG / GANTI PRODUK (a genuinely new, separately
-- tracked "make-good" demand, never a PO revision, never merged into the
-- original PO/DO/shipment/receipt history).
--
-- DATA MODEL AUDIT (done before writing this file, per the task's own
-- "MANDATORY DATA MODEL AUDIT FIRST"):
--   - shipment_receipt_item already carries the STORE-reported reject_qty
--     per line (migration 0007) but has no concept of an Admin-approved
--     quantity distinct from it, and no disposition state at all — Admin's
--     existing adminVerify() (Dispatch\ReceiptService) only flips the
--     WHOLE receipt's status to 'verified', never touches a per-line
--     decision. This migration adds that missing per-line state directly
--     onto shipment_receipt_item — never a new parallel "reject" table,
--     since the reject quantity itself already lives here.
--   - special_order_fg_allocation (migration 0012) already proves out the
--     exact "reservation without stock movement" pattern this feature
--     needs for Replacement's own FG earmark — replacement_demand_fg_
--     allocation below is a deliberate structural sibling of it (see
--     Fg/SpecialOrderFgAllocationRepository's own docblock for the
--     "TRUE FREE FG" formula this new table now also participates in —
--     SpecialOrder\SpecialOrderFgAllocationRepository::
--     sumActiveAllocatedForProductFactory() is widened, in application
--     code only, to also subtract this table's active rows, so Regular/
--     Special/Replacement can never oversubscribe the same physical FG;
--     no schema change was needed for that widening).
--   - A legacy, wholly UNUSED table from the original 0001 baseline,
--     reject_note (resolusi ENUM('potong','ganti')), anticipated a
--     "ganti" (replace) resolution years ago but was never wired to any
--     application code and carries no useful shape for this feature (no
--     link to shipment_receipt_item, no FG/production/DO lineage at all,
--     an invoice_id column for a module that doesn't exist yet). It is
--     deliberately left untouched and unused rather than resurrected —
--     reusing it would mean bolting this feature's real lineage
--     requirements onto a shape that was never designed to carry them.
--   - special_order_do / special_order_do_item / special_order_do_
--     shipment_item (migration 0012) prove out the "separate DO, its own
--     doc-no sequence, its own shipment lines, reusing the shared
--     shipment/shipment_receipt/shipment_receipt_item tables for the
--     physical dispatch + receipt" pattern this feature's own DO
--     (replacement_do/replacement_do_shipment_item) mirrors. Because a
--     replacement is always for exactly ONE product, replacement_do below
--     folds special_order_do_item's own columns directly onto the header
--     (product_id/planned_qty) rather than adding a needless child table
--     for a permanent 1:1 relationship.
--
-- NORMALIZED SOURCE TYPE: NormalizedSourceType::REPLACEMENT_REJECT and
-- Production\ProductionTaskService::SOURCE_REPLACEMENT_REJECT already
-- exist in application code (added ahead of time, always producing zero
-- rows) — this migration is what finally gives them real data to surface.
--
-- Purely additive/data-preserving throughout: every ALTER here is
-- ADD COLUMN IF NOT EXISTS with a DEFAULT, every CREATE TABLE is
-- CREATE TABLE IF NOT EXISTS, no existing table is dropped or narrowed,
-- no existing row anywhere is touched. MariaDB 10.11 compatible (same
-- idioms as every prior migration in this series).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. shipment_receipt_item: Admin's own approved reject qty + disposition,
--    distinct from the store's own reported reject_qty (never overwritten).
-- ----------------------------------------------------------------------------
ALTER TABLE shipment_receipt_item
  ADD COLUMN IF NOT EXISTS approved_reject_qty DECIMAL(12,2) NULL AFTER reject_qty,
  ADD COLUMN IF NOT EXISTS disposition ENUM('pending','reject_final','kirim_ulang') NOT NULL DEFAULT 'pending' AFTER approved_reject_qty,
  ADD COLUMN IF NOT EXISTS disposition_reason VARCHAR(500) NULL AFTER disposition,
  ADD COLUMN IF NOT EXISTS disposition_by BIGINT UNSIGNED NULL AFTER disposition_reason,
  ADD COLUMN IF NOT EXISTS disposition_at DATETIME NULL AFTER disposition_by,
  -- A replacement shipment's own receipt line reuses this same table (see
  -- replacement_do_shipment_item below) — one more nullable FK column,
  -- exactly the same shape as migration 0012's own
  -- special_order_do_shipment_item_id / item_name_snapshot widening.
  ADD COLUMN IF NOT EXISTS replacement_do_shipment_item_id BIGINT UNSIGNED NULL AFTER special_order_do_shipment_item_id;

-- disposition is per-line and only ever meaningful once (task's own "Do
-- NOT leave verified Reject without an explicit disposition" + "cannot
-- create two Replacement Demands from the same approved reject") — a
-- unique, sparse guard belongs on replacement_demand itself (below), not
-- here: a 'pending' vs 'reject_final' line never has a replacement_demand
-- row at all, so a uniqueness rule on THIS table would have nothing to
-- key against for the two states that need no row.

-- ----------------------------------------------------------------------------
-- 2. replacement_demand — the ONE authoritative "Kirim Ulang / Ganti
--    Produk" demand row per approved reject line. Never created for a
--    REJECT FINAL disposition. root_shipment_receipt_item_id always
--    traces to the very FIRST reject in a chain (self for a first-
--    generation demand; copied from the parent's own root for a
--    replacement-of-a-replacement) — task's own "preserve root original
--    reject traceability" + "avoid infinite replacement chains" (never
--    auto-created; parent_replacement_demand_id only ever set by another
--    explicit Admin disposition on the replacement's OWN receipt).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS replacement_demand (
  replacement_demand_id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_receipt_item_id       BIGINT UNSIGNED NOT NULL,
  root_shipment_receipt_item_id  BIGINT UNSIGNED NOT NULL,
  parent_replacement_demand_id   BIGINT UNSIGNED NULL,
  store_id                       BIGINT UNSIGNED NOT NULL,
  product_id                     BIGINT UNSIGNED NOT NULL,
  factory_id                     BIGINT UNSIGNED NOT NULL,
  approved_qty                   DECIMAL(12,2)   NOT NULL,
  -- Production tracking mirrors special_order_item's own aktual_produksi/
  -- reject_produksi/fg_verified_qty snapshot columns exactly (migration
  -- 0011/0012's own convention) — deliberately NOT routed through
  -- fg_batch/fg_item (that table is Regular-PO/date/batch-scoped and has
  -- no natural home for a single ad-hoc replacement qty).
  production_aktual              DECIMAL(12,2)   NOT NULL DEFAULT 0,
  production_reject              DECIMAL(12,2)   NOT NULL DEFAULT 0,
  production_fg_verified_qty     DECIMAL(12,2)   NOT NULL DEFAULT 0,
  status ENUM('need_production','ready','do_created','partially_shipped','shipped','received_partial','received_good','completed')
                                  NOT NULL DEFAULT 'need_production',
  version                        INT UNSIGNED    NOT NULL DEFAULT 1,
  created_by                     BIGINT UNSIGNED NOT NULL,
  created_at                     DATETIME        NOT NULL,
  updated_at                     DATETIME        NULL,
  -- Task's own explicit concurrency requirement: "Two Admin requests must
  -- not create two Replacement Demands from the same approved reject" —
  -- enforced here, not just in application code, so even a direct API
  -- race is physically impossible to double-insert for the same line.
  UNIQUE KEY uq_replacement_demand_receipt_item (shipment_receipt_item_id),
  KEY ix_replacement_demand_store_product (store_id, product_id),
  KEY ix_replacement_demand_factory (factory_id),
  KEY ix_replacement_demand_status (status),
  KEY ix_replacement_demand_root (root_shipment_receipt_item_id),
  KEY ix_replacement_demand_parent (parent_replacement_demand_id),
  CONSTRAINT fk_repldem_receipt_item FOREIGN KEY (shipment_receipt_item_id) REFERENCES shipment_receipt_item(shipment_receipt_item_id),
  CONSTRAINT fk_repldem_root_receipt_item FOREIGN KEY (root_shipment_receipt_item_id) REFERENCES shipment_receipt_item(shipment_receipt_item_id),
  CONSTRAINT fk_repldem_parent FOREIGN KEY (parent_replacement_demand_id) REFERENCES replacement_demand(replacement_demand_id),
  CONSTRAINT fk_repldem_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_repldem_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_repldem_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id),
  CONSTRAINT fk_repldem_created_by FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 3. replacement_demand_fg_allocation — structural sibling of
--    special_order_fg_allocation (migration 0012): an EARMARK, never a
--    stock movement (allocate/release never write stock_ledger — only a
--    real Replacement DO dispatch does, see below). remaining is always
--    computed fresh (allocated_qty - consumed_qty - released_qty), never
--    cached; status is always derived from those three, never hand-set —
--    identical discipline to special_order_fg_allocation's own
--    writeConsumedReleased().
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS replacement_demand_fg_allocation (
  replacement_demand_fg_allocation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  replacement_demand_id  BIGINT UNSIGNED NOT NULL,
  product_id              BIGINT UNSIGNED NOT NULL,
  factory_id              BIGINT UNSIGNED NOT NULL,
  allocated_qty           DECIMAL(12,2)  NOT NULL,
  consumed_qty            DECIMAL(12,2)  NOT NULL DEFAULT 0,
  released_qty            DECIMAL(12,2)  NOT NULL DEFAULT 0,
  status                  ENUM('active','partially_consumed','consumed','released') NOT NULL DEFAULT 'active',
  created_by              BIGINT UNSIGNED NOT NULL,
  created_at              DATETIME NOT NULL,
  updated_at              DATETIME NULL,
  KEY ix_rdfa_demand (replacement_demand_id),
  KEY ix_rdfa_product_factory (product_id, factory_id),
  KEY ix_rdfa_status (status),
  CONSTRAINT fk_rdfa_demand FOREIGN KEY (replacement_demand_id) REFERENCES replacement_demand(replacement_demand_id),
  CONSTRAINT fk_rdfa_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_rdfa_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id),
  CONSTRAINT fk_rdfa_created_by FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 4. replacement_do — a SEPARATE DO, own doc-no sequence (via the
--    existing, shared DocumentSequenceService/document_sequence — never a
--    new fragile counter), own status lifecycle, no price column at all
--    (same shape as delivery_order_item's own complete absence of any
--    price field — a Replacement DO is never billed). Folds what would
--    otherwise be a permanent 1:1 "item" row directly onto the header
--    (product_id/planned_qty) since one replacement_demand always yields
--    at most one replacement_do (uq_replacement_do_demand below) — this
--    keeps "one DO per demand" a schema-level guarantee, not just an
--    application convention, and doubles as the concurrency guard against
--    two concurrent "Buat DO" clicks for the same demand.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS replacement_do (
  replacement_do_id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  replacement_demand_id   BIGINT UNSIGNED NOT NULL,
  doc_no                   VARCHAR(64)     NOT NULL,
  tanggal                  DATE            NOT NULL,
  store_id                 BIGINT UNSIGNED NOT NULL,
  factory_id               BIGINT UNSIGNED NOT NULL,
  product_id                BIGINT UNSIGNED NOT NULL,
  planned_qty               DECIMAL(12,2)  NOT NULL,
  status ENUM('open','partial','shipped','cancelled') NOT NULL DEFAULT 'open',
  version                   INT UNSIGNED    NOT NULL DEFAULT 1,
  created_by                BIGINT UNSIGNED NOT NULL,
  created_at                DATETIME NOT NULL,
  updated_at                DATETIME NULL,
  cancelled_at              DATETIME NULL,
  cancelled_by              BIGINT UNSIGNED NULL,
  cancel_reason             VARCHAR(500) NULL,
  UNIQUE KEY uq_replacement_do_docno (doc_no),
  UNIQUE KEY uq_replacement_do_demand (replacement_demand_id),
  KEY ix_replacement_do_store (store_id),
  KEY ix_replacement_do_factory_tanggal (factory_id, tanggal),
  CONSTRAINT fk_repldo_demand FOREIGN KEY (replacement_demand_id) REFERENCES replacement_demand(replacement_demand_id),
  CONSTRAINT fk_repldo_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_repldo_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id),
  CONSTRAINT fk_repldo_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_repldo_created_by FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 5. replacement_do_shipment_item — the real per-dispatch line, mirroring
--    special_order_do_shipment_item exactly (multiple rows against the
--    same replacement_do_id support partial shipment: task's own worked
--    example "Shipment 1 = 2, Shipment 2 = 3").
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS replacement_do_shipment_item (
  replacement_do_shipment_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id          BIGINT UNSIGNED NOT NULL,
  replacement_do_id    BIGINT UNSIGNED NOT NULL,
  qty                   DECIMAL(12,2)  NOT NULL,
  created_at            DATETIME NOT NULL,
  KEY ix_rdsi_shipment (shipment_id),
  KEY ix_rdsi_do (replacement_do_id),
  CONSTRAINT fk_rdsi_shipment FOREIGN KEY (shipment_id) REFERENCES shipment(shipment_id),
  CONSTRAINT fk_rdsi_do FOREIGN KEY (replacement_do_id) REFERENCES replacement_do(replacement_do_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 6. shipment / stock_ledger: widen (never narrow) source_type to add the
--    new 'replacement_do' shipment origin and 'replacement_demand_fg_
--    allocation' ledger source — same additive-ENUM-only discipline as
--    every prior migration (0005/0012/0013's own docblocks).
-- ----------------------------------------------------------------------------
ALTER TABLE shipment
  MODIFY COLUMN source_type ENUM('delivery_order','manual_kirim','customer_order_fulfillment','special_order_do','replacement_do') NOT NULL,
  ADD COLUMN IF NOT EXISTS replacement_do_id BIGINT UNSIGNED NULL AFTER special_order_do_id;

-- Listed after the additive/idempotent parts above, same "a from-scratch
-- direct-apply failure happens after the safe parts" ordering as
-- migration 0012's own final FK statement.
ALTER TABLE shipment
  ADD CONSTRAINT fk_shipment_replacement_do FOREIGN KEY (replacement_do_id) REFERENCES replacement_do(replacement_do_id);

ALTER TABLE stock_ledger
  MODIFY COLUMN source_type ENUM('production_run','shipment_item','stock_adjustment','stock_transfer','opening_balance_cutover','historical_replay','reversal','fg_item','special_order_fg_allocation','replacement_demand_fg_allocation') NOT NULL;
