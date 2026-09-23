-- ============================================================================
-- Migration 0012 — Production Flow Completion: Extra Packaging, FG bridge,
-- source-specific DO for Pesanan Khusus Toko / Pesanan Non-Toko
-- ============================================================================
--
-- Purely additive. No existing table's existing columns/rows change
-- meaning; po_batch/po_item/po_store_item, production_run/production_item,
-- fg_batch/fg_item, delivery_order/delivery_order_item are all completely
-- untouched by this migration (regular flows keep their exact existing
-- schema and semantics).
--
-- 1. special_order_item.extra_packaging — approved new per-item monetary
--    field (task's own "Extra Packaging"): manually entered, per line,
--    added once to the item subtotal, NEVER multiplied by qty. Same
--    DECIMAL(14,2) convention as unit_price/charge/subtotal on this same
--    table — no second money datatype introduced.
--
-- 2. special_order_item.fg_verified_qty — the smallest safe FG-bridge
--    linkage for special/non-regular production (task's own explicit
--    permission: "If existing schema cannot safely model this: introduce
--    the smallest dedicated source-aware FG linkage"). aktual_produksi
--    (migration 0011) REMAINS the authoritative Actual Produksi value for
--    special orders — this column never copies or duplicates it, it only
--    tracks how much of that already-authoritative quantity has been
--    confirmed FG-ready (available_to_verify = aktual_produksi -
--    fg_verified_qty, computed in the service layer, never stored).
--
--    Architecture decision (documented per the task's own "Document the
--    decision clearly" instruction): special-order FG — for BOTH existing
--    products and custom/special-catalog items — stays entirely
--    order-specific in this phase. It does NOT post to the shared
--    stock_ledger/stock_balance tables that Regular PO's FG flow uses.
--    stock_ledger is this project's "sole stock-truth write" (see
--    FgService's own docblock) with a strict, narrow source_type ENUM
--    that does not yet include a special-order source — widening that
--    ENUM and deciding how special-order-sourced stock should coexist
--    with regular per-store FG stock is a real design question deserving
--    its own dedicated review, not a rushed addition here. Until that
--    review happens, special-order FG-verified quantity is tracked here,
--    consumed directly by special_order_do below, and never silently
--    mixed into the general 472-product warehouse pool.
--
-- 3. special_order_do / special_order_do_item — a SEPARATE, parallel DO
--    concept for Pesanan Khusus Toko / Pesanan Non-Toko (task's own
--    approved rule: "DIFFERENT DEMAND SOURCES MUST HAVE SEPARATE DO...
--    DO NOT MERGE DIFFERENT SOURCE TYPES INTO ONE DO"). Deliberately NOT
--    modeled as new rows in the EXISTING delivery_order/delivery_order_item
--    tables — those tables' partial-unique-index (tanggal, store_id,
--    shipment_group) and NOT NULL store_id are load-bearing for Regular
--    PO's own DO semantics ("keep existing DO semantics exactly... do not
--    change existing regular DO number format unless necessary"); reusing
--    them for a non-store destination would require weakening that
--    NOT NULL constraint and inventing a parallel uniqueness rule anyway.
--    A dedicated pair of tables is the actually-smallest-risk design: zero
--    changes to Regular DO's proven structure, and store_id here is
--    naturally nullable (a non-store destination — CS/Sales/Direct/
--    General — has no store at all).
--
-- 4. shipment.source_type gains ONE new ENUM value ('special_order_do')
--    and shipment.special_order_do_id (nullable FK) — additive only; the
--    three existing values and every existing shipment row are
--    unaffected. This is the exact extension point the ORIGINAL schema
--    already designed shipment.source_type for (it was never a single
--    fixed source). NOT wired into a real write path in this phase —
--    same "schema ready, write path deferred" convention as
--    DocumentSequenceService's own docblock. Reason: shipment.store_id is
--    NOT NULL, which is fine for Pesanan Khusus Toko (always has a real
--    store_id) but cannot represent Pesanan Non-Toko's non-store
--    destinations (CS/Sales/Direct/Umum) without either violating that
--    constraint or forcing a fake store row into the store master — the
--    task's own explicit rule ("without forcing non-store customers into
--    store master"). It also risks pulling special-order dispatches into
--    Konfirmasi Toko's existing store-receipt-confirmation queries, which
--    are scoped to shipment/delivery_order today and must not regress.
--    special_order_do's own status column (draft/ready/shipped/cancelled)
--    plus shipped_at/shipped_by is therefore the authoritative, self-
--    contained dispatch record for BOTH source types in this phase.
-- ============================================================================

ALTER TABLE special_order_item
  ADD COLUMN IF NOT EXISTS extra_packaging DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER charge,
  ADD COLUMN IF NOT EXISTS fg_verified_qty DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER reject_produksi;

CREATE TABLE IF NOT EXISTS special_order_do (
  special_order_do_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no               VARCHAR(50)     NOT NULL,
  tanggal              DATE            NOT NULL,
  special_order_id     BIGINT UNSIGNED NOT NULL,
  source_type          ENUM('toko_khusus','non_toko') NOT NULL,
  status               ENUM('draft','ready','shipped','cancelled') NOT NULL DEFAULT 'draft',
  store_id             BIGINT UNSIGNED NULL,
  customer_name        VARCHAR(255)    NULL,
  customer_contact     VARCHAR(100)    NULL,
  delivery_address     VARCHAR(500)    NULL,
  version              INT UNSIGNED    NOT NULL DEFAULT 1,
  created_by           BIGINT UNSIGNED NOT NULL,
  created_at           DATETIME        NOT NULL,
  updated_at           DATETIME        NULL,
  shipped_at           DATETIME        NULL,
  shipped_by           BIGINT UNSIGNED NULL,
  cancelled_at         DATETIME        NULL,
  cancelled_by         BIGINT UNSIGNED NULL,
  cancel_reason        VARCHAR(500)    NULL,
  UNIQUE KEY uq_special_order_do_no (doc_no),
  KEY ix_sodo_order (special_order_id),
  KEY ix_sodo_status (status),
  KEY ix_sodo_store (store_id),
  CONSTRAINT fk_sodo_order FOREIGN KEY (special_order_id) REFERENCES special_order(special_order_id),
  CONSTRAINT fk_sodo_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_sodo_created_by FOREIGN KEY (created_by) REFERENCES users(user_id),
  CONSTRAINT fk_sodo_shipped_by FOREIGN KEY (shipped_by) REFERENCES users(user_id),
  CONSTRAINT fk_sodo_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS special_order_do_item (
  special_order_do_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  special_order_do_id       BIGINT UNSIGNED NOT NULL,
  special_order_item_id     BIGINT UNSIGNED NOT NULL,
  planned_qty                DECIMAL(12,2)  NOT NULL,
  actual_ship_qty             DECIMAL(12,2) NULL,
  UNIQUE KEY uq_sodo_item (special_order_do_id, special_order_item_id),
  CONSTRAINT fk_sodoi_do FOREIGN KEY (special_order_do_id) REFERENCES special_order_do(special_order_do_id) ON DELETE CASCADE,
  CONSTRAINT fk_sodoi_item FOREIGN KEY (special_order_item_id) REFERENCES special_order_item(special_order_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE shipment
  MODIFY COLUMN source_type ENUM('delivery_order','manual_kirim','customer_order_fulfillment','special_order_do') NOT NULL,
  ADD COLUMN IF NOT EXISTS special_order_do_id BIGINT UNSIGNED NULL AFTER customer_order_id;

-- MariaDB has no "ADD CONSTRAINT IF NOT EXISTS ... FOREIGN KEY" (confirmed
-- empirically in migration 0002) — relies on the migration runner's own
-- applied/not-applied tracking for idempotency, same as every prior
-- migration's own final FK statement. Listed last so a from-scratch
-- direct-apply failure on a rerun happens after the safe/idempotent parts.
ALTER TABLE shipment
  ADD CONSTRAINT fk_shipment_special_order_do FOREIGN KEY (special_order_do_id) REFERENCES special_order_do(special_order_do_id);
