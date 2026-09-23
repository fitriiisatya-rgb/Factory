-- ============================================================================
-- Migration 0012 — Production Flow Completion + Special/Non-Regular
-- Fulfillment Completion (Driver Internal + External Courier)
-- ============================================================================
--
-- REWORK NOTE: this file replaces an earlier draft of migration 0012 that
-- was never applied to any live database (confirmed: the live cPanel DB is
-- still at 0011). Per the task's own explicit instruction ("Do NOT create
-- 0013 just to fix an undeployed bad 0012"), this is a full, corrected
-- replacement — not a patch on top of the draft. Migrations 0001-0011
-- remain byte-for-byte untouched.
--
-- Purely additive against 0011's schema. po_batch/po_item/po_store_item,
-- production_run/production_item, fg_batch/fg_item, delivery_order/
-- delivery_order_item are all completely untouched (Regular PO's own DO/
-- shipment semantics keep their exact existing structure and behavior).
--
-- ----------------------------------------------------------------------------
-- 1. special_order_item.extra_packaging / fg_verified_qty — unchanged from
--    the original draft. extra_packaging: manual per-line Rupiah field,
--    added once to the subtotal. fg_verified_qty: the Production->FG
--    bridge (snapshot semantics) — aktual_produksi (migration 0011) stays
--    the one authoritative Actual Produksi value.
--
--    allocated_qty / shipped_qty are DELIBERATELY NOT stored columns here
--    — they are always computed from the real child tables below (SUM of
--    special_order_do_item.planned_qty / special_order_do_shipment_item.qty)
--    so there is no cached value that can ever drift out of sync with the
--    real DO/shipment rows. This mirrors this codebase's own existing
--    convention: delivery_order_item has no stored "shipped_qty" column
--    either — DoRepository::shippedQtyByProduct() always sums the real
--    shipment_item rows on read.
--
-- 2. special_order_do — reworked from the earlier draft. Key changes:
--    - factory_id NOT NULL: one DO = one pickup factory (a multi-factory
--      special order needs one DO per factory — the same "MIXED_FACTORY_
--      SHIPMENT" principle Regular PO's own ShipmentService already
--      enforces, just applied one level higher here, since External
--      Courier pickup is a single physical location).
--    - drop_store_id NOT NULL (renamed conceptually from the draft's
--      nullable store_id): the PHYSICAL destination (a Bakery/store),
--      REQUIRED for every source — even CS/Sales/Direct/General — because
--      "in this phase, all physical drops may use a Bakery/store as
--      destination" (task's own rule). This is never the billing owner —
--      source_type/special_order_id alone determine that.
--    - delivery_method + courier_provider/courier_name/
--      external_order_reference: the DO-level delivery method (task's own
--      approved rule — DRIVER_INTERNAL default, EXTERNAL_COURIER with
--      provider metadata).
--    - claimed_by_user_id/claimed_at: a single DO-level claim (see the
--      migration's own note on the Driver Portal integration choice below).
--    - status now ENUM('open','partial','shipped','cancelled') —
--      DERIVED from real shipped quantities on every write, never hand-set
--      by a button click (task's own "Do NOT mark DO shipped merely
--      because a button was clicked").
--    - NO unique/business-key constraint tying one order to one DO — an
--      order may have MULTIPLE special_order_do rows (task's own explicit
--      "Do NOT enforce one-and-only-one DO per special order").
--
--    Driver Portal integration choice (documented per the task's own
--    "Document the decision clearly"): the EXISTING Phase 5.5 driver flow
--    (dispatch_claim/driver_route/driver_route_stop) is a POOLED, PER-
--    PRODUCT-ITEM claim system keyed on delivery_order_item_id + product_id
--    — both assumptions break for special orders (a special_order_do can
--    contain a custom/catalog line with NO product_id at all, and its
--    natural unit of work is the whole document, not a per-product pool
--    shared across drivers). Reusing that exact table would require
--    weakening its product_id NOT NULL foreign keys or forking its entire
--    concurrency model — high risk to a proven, heavily-tested Regular PO
--    flow for no real benefit, since a special-order DO already has ONE
--    destination and ONE pickup factory by construction (it's not a pool
--    of many stores a driver picks from). special_order_do therefore gets
--    its OWN, simpler, DO-level claim (claimed_by_user_id/claimed_at) —
--    a driver claims the WHOLE document, not a per-item slice — and its
--    own Driver Portal tab (never touching dispatch_claim/driver_route at
--    all). This still satisfies the task's own flow: "FG Ready ->
--    Source-specific DO -> appears in Driver Portal -> Driver claims ->
--    Driver confirms departure -> Shipment created."
--
-- 3. special_order_do_item — the PLANNED line (mirrors delivery_order_item).
--    No actual_ship_qty column (removed from the earlier draft, which
--    never wired it to a real write path) — actual dispatched quantity is
--    now the real, auditable SUM of special_order_do_shipment_item rows
--    below, exactly mirroring how delivery_order_item's own "shipped" is
--    computed from shipment_item, never a cached scalar.
--
-- 4. special_order_do_shipment_item — NEW. The actual per-dispatch-event
--    line item — this is what shipment_item is for Regular PO, but
--    keyed on special_order_do_item_id (never product_id) so it works
--    identically for both existing-product AND custom/catalog special-
--    order items. One shipment can have many of these; one
--    special_order_do_item can be covered by MANY of these across
--    multiple partial shipments (task's own PARTIAL FULFILLMENT example:
--    DO-1 planned 6, ships 4 today, ships the remaining 2 later — two
--    real shipment events against the same DO).
--
-- 5. shipment gains: special_order_do_id (nullable FK, COMPLETES the
--    ENUM value the earlier draft left unwired — "do not leave an enum/
--    FK without a real write path" is now honored: SpecialOrderDoService
--    actually INSERTs into this column on every real dispatch), plus
--    delivery_method/courier_provider/courier_name/
--    external_order_reference/handover_note — a denormalized snapshot of
--    the DO's delivery method AT THE TIME of that specific shipment event
--    (task's own requirement: "For source-specific dispatch preserve:
--    ...delivery method, driver_id OR external courier metadata..." on
--    the SHIPMENT itself, not only inferred via a join). Every existing
--    shipment row gets NULL for all of these — zero behavior change for
--    Regular PO's own shipments.
--
--    shipment.store_id (NOT NULL) is populated with the DO's drop_store_id
--    for a special-order shipment — this is now always safely satisfiable
--    because drop_store_id itself is required on every special_order_do
--    (see point 2). This resolves the exact blocker documented in the
--    ORIGINAL draft of this migration (which is why that draft never
--    wired a real shipment write path at all).
--
-- 6. Receipt/confirmation reuse — REWORKED per the task's own "Special /
--    Non-Regular Shipment -> Driver History -> Digital Surat Jalan ->
--    Email -> Bakery Receipt" completion pass. shipment_receipt itself
--    already keys ONLY on shipment_id (not shipment_item), so its HEADER
--    confirmation (confirmed_ok/confirmed_discrepancy, receiver_name,
--    note) needed ZERO schema change — untouched.
--
--    shipment_receipt_item (the per-product line breakdown) previously
--    required a real shipment_item_id + product_id, which conflicts with
--    custom/catalog special items exactly the same way shipment_item
--    itself does. Solved here WITHOUT fabricating a fake shipment_item
--    row (task's own explicit "Do NOT fabricate shipment_item"):
--    shipment_item_id and product_id become NULLable, and a new nullable
--    special_order_do_shipment_item_id + item_name_snapshot are added —
--    a receipt line now references EITHER the regular shipment_item OR
--    the special special_order_do_shipment_item, mutually exclusive by
--    convention (enforced in ReceiptService, same app-layer-invariant
--    discipline as every other cross-source rule in this codebase, never
--    a DB CHECK constraint).
--
-- 7. shipment_receipt_token — NEW. The token-based confirmation ENTRY
--    POINT (ReceiptService::confirmReceipt/getPublicView) was keyed ONLY
--    on a Regular delivery_order id via delivery_receipt_token — a
--    special-order shipment has no delivery_order_id to key on. Rather
--    than weaken/overload delivery_receipt_token (which would risk the
--    "existing receipt links already sent by email must remain valid"
--    backward-compatibility rule), this is a SEPARATE, additive,
--    shipment-keyed token table. ReceiptService now resolves an incoming
--    token against EITHER table (DO-token first, shipment-token second)
--    — a Regular DO's existing links keep working byte-for-byte unchanged
--    (same table, same lookup, same token value), while a special-order
--    shipment gets its own direct shipment-scoped token minted the first
--    time its Surat Jalan is printed or its automatic email is sent
--    (same get-or-create-once semantics as delivery_receipt_token's own
--    ReceiptRepository::getOrCreateToken).
-- ============================================================================

ALTER TABLE special_order_item
  ADD COLUMN IF NOT EXISTS extra_packaging DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER charge,
  ADD COLUMN IF NOT EXISTS fg_verified_qty DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER reject_produksi;

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

ALTER TABLE shipment
  MODIFY COLUMN source_type ENUM('delivery_order','manual_kirim','customer_order_fulfillment','special_order_do') NOT NULL,
  ADD COLUMN IF NOT EXISTS special_order_do_id BIGINT UNSIGNED NULL AFTER customer_order_id,
  ADD COLUMN IF NOT EXISTS delivery_method ENUM('DRIVER_INTERNAL','EXTERNAL_COURIER') NULL AFTER special_order_do_id,
  ADD COLUMN IF NOT EXISTS courier_provider ENUM('grab','gosend','lalamove','other') NULL AFTER delivery_method,
  ADD COLUMN IF NOT EXISTS courier_name VARCHAR(100) NULL AFTER courier_provider,
  ADD COLUMN IF NOT EXISTS external_order_reference VARCHAR(100) NULL AFTER courier_name,
  ADD COLUMN IF NOT EXISTS handover_note VARCHAR(500) NULL AFTER external_order_reference;

-- MariaDB has no "ADD CONSTRAINT IF NOT EXISTS ... FOREIGN KEY" (confirmed
-- empirically in migration 0002) — relies on the migration runner's own
-- applied/not-applied tracking for idempotency, same as every prior
-- migration's own final FK statement. Listed last so a from-scratch
-- direct-apply failure on a rerun happens after the safe/idempotent parts.
ALTER TABLE shipment
  ADD CONSTRAINT fk_shipment_special_order_do FOREIGN KEY (special_order_do_id) REFERENCES special_order_do(special_order_do_id);

-- ----------------------------------------------------------------------------
-- shipment_receipt_token (see point 7 above) — mints one high-entropy token
-- PER SHIPMENT, used only by special-order (and any future non-DO-keyed)
-- shipments. Regular PO shipments keep using delivery_receipt_token
-- exclusively; this table is never consulted for them.
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
-- shipment_receipt_item (migration 0007) — widen to accept a special-order
-- line in place of a regular shipment_item/product line (point 6 above).
-- Existing rows are all regular and keep shipment_item_id/product_id
-- populated exactly as before — this is purely widening, never narrowing.
-- ----------------------------------------------------------------------------
ALTER TABLE shipment_receipt_item
  MODIFY COLUMN shipment_item_id BIGINT UNSIGNED NULL,
  MODIFY COLUMN product_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS special_order_do_shipment_item_id BIGINT UNSIGNED NULL AFTER shipment_item_id,
  ADD COLUMN IF NOT EXISTS item_name_snapshot VARCHAR(255) NULL AFTER product_id;

ALTER TABLE shipment_receipt_item
  ADD CONSTRAINT fk_sri_special_line FOREIGN KEY (special_order_do_shipment_item_id) REFERENCES special_order_do_shipment_item(special_order_do_shipment_item_id);

-- ============================================================================
-- Existing FG allocation bridge (task's own "Implementation — Existing FG
-- Allocation Bridge" pass) — a real cPanel UAT found that an existing-
-- product special/non-regular order item could NOT proceed to DO at all
-- unless special_order_item.aktual_produksi > 0, even when general FG
-- (stock_balance, the SAME pool Regular PO ships from) already had enough
-- stock to fully satisfy the order. SpecialOrderRepository::
-- findStockOnHand()'s own pre-existing docblock explicitly documented this
-- as a deliberate prior-phase deferral ("never written here... building
-- real reservation would need locking semantics this phase's tables don't
-- have") — this migration builds that reservation layer.
--
-- special_order_fg_allocation — one row per explicit "Alokasikan dari FG"
-- operator action (never silent/automatic — task's own "Do NOT silently
-- auto-reserve stock just because the page opens"). Represents "this qty
-- of EXISTING general FG is earmarked for this special/non-regular order
-- item" — an EARMARK, never a physical stock movement (task's own
-- "CRITICAL STOCK PRINCIPLE": allocation must NOT write stock_ledger;
-- physical stock only leaves the factory at real dispatch). Applies ONLY
-- to item_type='existing_product' lines (enforced in the service layer,
-- never at the DB level, same app-layer-invariant convention as every
-- other cross-source rule in this codebase) — a special_catalog/custom
-- item has no general-FG identity to allocate from.
--
-- source_type/special_order_id are explicit denormalized columns (never
-- ONLY derivable via a join chain through special_order_item) — same
-- "explicit source identity, never fragile inference" principle as
-- special_order_do's own source_type column.
--
-- allocated_qty/consumed_qty/released_qty are a running ledger on the row
-- itself (never a second event-log table — the existing audit_log table
-- already captures the full create/consume/release history for this row,
-- same convention as every other mutation in this codebase). remaining
-- reservation = allocated_qty - consumed_qty - released_qty, ALWAYS
-- computed fresh, never cached elsewhere. status is DERIVED and
-- rewritten on every mutation (never hand-set), mirroring special_order_
-- do.status's own "always recomputed from real sums" convention:
--   'active'             — remaining > 0 (still earmarked, nothing shipped/released yet, or partially so)
--   'partially_consumed' — remaining <= 0, but consumed_qty > 0 AND released_qty > 0 (part shipped, the rest released — e.g. order cancelled after a partial shipment)
--   'consumed'            — remaining <= 0, released_qty = 0 (fully shipped)
--   'released'            — remaining <= 0, consumed_qty = 0 (fully released, nothing ever shipped from it)
-- ============================================================================
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

-- stock_ledger.source_type widened (never narrowed — the full current
-- list, including 'fg_item' already added by migration 0005, must be
-- preserved verbatim here; a MODIFY COLUMN that omits an already-live
-- ENUM value would silently break every existing/future 'fg_item' row)
-- so a real dispatch that consumes an EXISTING general-FG allocation for
-- a special/non-regular order can post its own real 'shipment_out' row,
-- traceable back to the exact special_order_do_shipment_item that caused
-- it (source_id), without ever claiming source_type='shipment_item'
-- (that value stays reserved for Regular PO's own real shipment_item
-- rows — never conflated).
ALTER TABLE stock_ledger
  MODIFY COLUMN source_type ENUM('production_run','shipment_item','stock_adjustment','stock_transfer','opening_balance_cutover','historical_replay','reversal','fg_item','special_order_fg_allocation') NOT NULL;
