-- ============================================================================
-- Migration 0007 — Phase 5.5: Dispatch Pool + Driver Claim + Store Receipt
-- ============================================================================
--
-- Purely additive. No Phase 1-5 table is altered, renamed, or dropped.
--
-- Schema audit performed before writing this file (see the Phase 5.5 final
-- report for the full writeup) concluded that a separate "dispatch_task"
-- table is NOT needed: delivery_order_item already IS the one-row-per-
-- (DO,product) "pool line" the task spec describes — a dispatch_claim
-- simply reserves a quantity against an existing delivery_order_item.
-- Concurrency for claims reuses the SAME row-lock pattern ShipmentService
-- already uses (FOR UPDATE on the parent delivery_order row) rather than
-- adding a new lockable row.
--
-- New tables, each with a one-line reason:
--   dispatch_claim         — a driver's reservation against one DO item;
--                            does not exist anywhere in Phase 1-5.
--   driver_route           — one ordering scratchpad per driver per day;
--                            no existing table represents "my delivery
--                            order for today".
--   driver_route_stop      — the ordered stores within a driver_route.
--   delivery_receipt_token — a stable, unguessable, PUBLIC-facing token
--                            per DO so a QR can be printed before any
--                            shipment/driver split is known. delivery_order
--                            has no such column and must never expose its
--                            own sequential ID publicly.
--   shipment_receipt        — store's confirmation of ONE real shipment
--                            (good/reject/shortage), gated on Phase 6
--                            invoice math later. shipment has no such
--                            columns; adding them there would conflate the
--                            immutable shipment record with a mutable,
--                            store-editable confirmation.
--   shipment_receipt_item   — per-product breakdown of the above, one row
--                            per shipment_item.
--
-- Role: DRIVER already existed in Seeder.php's illustrative role list
-- comment but was NEVER actually inserted anywhere — this migration inserts
-- it for real (idempotent upsert), so an already-deployed database gets it
-- via the normal api/_upgrade/ wizard, no manual SQL needed.
-- ============================================================================

INSERT INTO roles (code, name) VALUES ('DRIVER', 'Driver')
  ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ----------------------------------------------------------------------------
-- dispatch_claim — a driver's reservation of qty against one delivery_order_item.
-- ----------------------------------------------------------------------------
-- Lifecycle (deliberately simple — see DispatchService's own docblock):
--   'active'   — reserved, not yet departed or released. The ONLY state
--                that counts toward "already claimed" when computing another
--                driver's remaining-claimable qty.
--   'departed' — confirm-departure was executed for this claim. Whatever
--                portion did not go into the resulting shipment
--                (claimed_qty - departed_qty) was AUTOMATICALLY released
--                back to the pool in the same transaction (task's own
--                "automatically release unused claimed qty" preference) —
--                this is a TERMINAL state; a claim is never revisited after
--                departure (the driver would claim the freed leftover again
--                as a brand new row if still needed).
--   'released' — the driver manually released the claim WITHOUT departing
--                (e.g. plans changed) — also terminal.
--   'cancelled'— the underlying DO was cancelled while this claim was still
--                active — also terminal, system-set.
-- claimed_qty is immutable once inserted (the original reservation, for
-- audit); active_qty/departed_qty/released_qty always satisfy
-- departed_qty + released_qty + active_qty = claimed_qty.
CREATE TABLE dispatch_claim (
  dispatch_claim_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_order_id      BIGINT UNSIGNED NOT NULL,
  delivery_order_item_id BIGINT UNSIGNED NOT NULL,
  product_id             BIGINT UNSIGNED NOT NULL,
  store_id               BIGINT UNSIGNED NOT NULL,
  driver_user_id         BIGINT UNSIGNED NOT NULL,
  claimed_qty            DECIMAL(12,2)   NOT NULL,
  active_qty             DECIMAL(12,2)   NOT NULL,
  departed_qty           DECIMAL(12,2)   NOT NULL DEFAULT 0,
  released_qty           DECIMAL(12,2)   NOT NULL DEFAULT 0,
  status                 ENUM('active','departed','released','cancelled') NOT NULL DEFAULT 'active',
  shipment_id            BIGINT UNSIGNED NULL,
  version                INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at             DATETIME        NOT NULL,
  updated_at             DATETIME        NULL,
  KEY ix_dispatch_claim_item_status (delivery_order_item_id, status),
  KEY ix_dispatch_claim_driver_status (driver_user_id, status),
  KEY ix_dispatch_claim_do (delivery_order_id),
  KEY ix_dispatch_claim_store (store_id),
  CONSTRAINT fk_dispatch_claim_do FOREIGN KEY (delivery_order_id) REFERENCES delivery_order(delivery_order_id),
  CONSTRAINT fk_dispatch_claim_item FOREIGN KEY (delivery_order_item_id) REFERENCES delivery_order_item(delivery_order_item_id),
  CONSTRAINT fk_dispatch_claim_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_dispatch_claim_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_dispatch_claim_driver FOREIGN KEY (driver_user_id) REFERENCES users(user_id),
  CONSTRAINT fk_dispatch_claim_shipment FOREIGN KEY (shipment_id) REFERENCES shipment(shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- driver_route / driver_route_stop — pure ordering, never a source of truth
-- for what is claimed or shipped (dispatch_claim/shipment already are).
-- ----------------------------------------------------------------------------
CREATE TABLE driver_route (
  driver_route_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_user_id  BIGINT UNSIGNED NOT NULL,
  tanggal         DATE            NOT NULL,
  created_at      DATETIME        NOT NULL,
  updated_at      DATETIME        NULL,
  UNIQUE KEY uq_driver_route (driver_user_id, tanggal),
  CONSTRAINT fk_driver_route_driver FOREIGN KEY (driver_user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE driver_route_stop (
  driver_route_stop_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  driver_route_id      BIGINT UNSIGNED NOT NULL,
  store_id             BIGINT UNSIGNED NOT NULL,
  sequence             INT UNSIGNED    NOT NULL,
  note                 VARCHAR(300)    NULL,
  created_at           DATETIME        NOT NULL,
  updated_at           DATETIME        NULL,
  UNIQUE KEY uq_driver_route_stop (driver_route_id, store_id),
  CONSTRAINT fk_route_stop_route FOREIGN KEY (driver_route_id) REFERENCES driver_route(driver_route_id) ON DELETE CASCADE,
  CONSTRAINT fk_route_stop_store FOREIGN KEY (store_id) REFERENCES store(store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- delivery_receipt_token — one stable, high-entropy, non-sequential public
-- token per DO. Printed as a QR before any shipment/driver split exists.
-- Never a sequential ID; lookups always go token -> DO, never DO -> guess
-- token, and the public API only ever accepts this token, never a raw
-- delivery_order_id.
-- ----------------------------------------------------------------------------
CREATE TABLE delivery_receipt_token (
  delivery_order_id BIGINT UNSIGNED PRIMARY KEY,
  token             CHAR(64)        NOT NULL,
  created_at        DATETIME        NOT NULL,
  UNIQUE KEY uq_receipt_token (token),
  CONSTRAINT fk_receipt_token_do FOREIGN KEY (delivery_order_id) REFERENCES delivery_order(delivery_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- shipment_receipt / shipment_receipt_item — the store's confirmation of ONE
-- real (already-departed) shipment. Never created for a shipment that
-- doesn't exist yet; "pending" state before confirmation is represented by
-- the ABSENCE of a row, not a placeholder row (mirrors the "no draft
-- shipment row" design already locked in for Phase 5's own shipment table).
-- ----------------------------------------------------------------------------
CREATE TABLE shipment_receipt (
  shipment_receipt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id         BIGINT UNSIGNED NOT NULL,
  status              ENUM('confirmed_ok','confirmed_discrepancy','verified') NOT NULL,
  receiver_name       VARCHAR(150)    NULL,
  note                VARCHAR(500)    NULL,
  confirmed_at        DATETIME        NOT NULL,
  verified_by         BIGINT UNSIGNED NULL,
  verified_at         DATETIME        NULL,
  version             INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at          DATETIME        NOT NULL,
  updated_at          DATETIME        NULL,
  UNIQUE KEY uq_shipment_receipt (shipment_id),
  CONSTRAINT fk_shipment_receipt_shipment FOREIGN KEY (shipment_id) REFERENCES shipment(shipment_id),
  CONSTRAINT fk_shipment_receipt_verified_by FOREIGN KEY (verified_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE shipment_receipt_item (
  shipment_receipt_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_receipt_id      BIGINT UNSIGNED NOT NULL,
  shipment_item_id         BIGINT UNSIGNED NOT NULL,
  product_id               BIGINT UNSIGNED NOT NULL,
  shipped_qty              DECIMAL(12,2)   NOT NULL,
  received_good_qty        DECIMAL(12,2)   NOT NULL DEFAULT 0,
  reject_qty               DECIMAL(12,2)   NOT NULL DEFAULT 0,
  shortage_qty             DECIMAL(12,2)   NOT NULL DEFAULT 0,
  reason                   VARCHAR(300)    NULL,
  UNIQUE KEY uq_receipt_item (shipment_item_id),
  KEY ix_receipt_item_receipt (shipment_receipt_id),
  CONSTRAINT fk_receipt_item_receipt FOREIGN KEY (shipment_receipt_id) REFERENCES shipment_receipt(shipment_receipt_id) ON DELETE CASCADE,
  CONSTRAINT fk_receipt_item_shipment_item FOREIGN KEY (shipment_item_id) REFERENCES shipment_item(shipment_item_id),
  CONSTRAINT fk_receipt_item_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT chk_receipt_item_math CHECK (received_good_qty + reject_qty + shortage_qty = shipped_qty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
