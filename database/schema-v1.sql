-- ============================================================================
-- ⚠️  DRAFT ONLY — NOT FOR PRODUCTION  ⚠️
-- ============================================================================
-- This file is a literal SQL rendering of docs/mysql-schema-v1.md for review
-- convenience. It has NEVER been executed against any database, live or
-- otherwise, and MUST NOT be run against factory.amorgroup.id or any other
-- environment until:
--
--   1. docs/mysql-open-decisions-v1.md's sign-off checklist (§5) is complete,
--   2. OD-4 (actual MySQL/MariaDB version on the target cPanel host) is
--      confirmed and matches the feature requirements in
--      docs/mysql-schema-v1.md §0,
--   3. A human has reviewed this file line-by-line against the design docs
--      it was generated from.
--
-- No deployment, migration run, or database modification of any kind was
-- performed by producing this file. Seed data below (roles, one location
-- row) is illustrative only.
--
-- Target: MySQL 5.7.8+ / MariaDB 10.2.0+ (see docs/mysql-schema-v1.md §0 for
-- the exact feature-to-version mapping this file relies on — principally the
-- generated STORED column + UNIQUE index pattern used on delivery_order).
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ============================================================================
-- 1. Reference / master data
-- ============================================================================

CREATE TABLE factory (
  factory_id   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30)  NOT NULL,
  name         VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_factory_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE division (
  division_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(100) NOT NULL,
  is_verification  TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_division_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE location (
  location_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_location_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 2. Users / roles / auth (review point 6)
-- ============================================================================

CREATE TABLE users (
  user_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(100) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  full_name      VARCHAR(150) NOT NULL,
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL,
  updated_at     DATETIME     NULL,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE roles (
  role_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code     VARCHAR(30)  NOT NULL,
  name     VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_roles (
  user_id  BIGINT UNSIGNED NOT NULL,
  role_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(user_id),
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Future scope (present now, not necessarily enforced by v1 API):
CREATE TABLE user_factory_access (
  user_id     BIGINT UNSIGNED NOT NULL,
  factory_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, factory_id),
  CONSTRAINT fk_ufa_user FOREIGN KEY (user_id) REFERENCES users(user_id),
  CONSTRAINT fk_ufa_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_division_access (
  user_id      BIGINT UNSIGNED NOT NULL,
  division_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, division_id),
  CONSTRAINT fk_uda_user FOREIGN KEY (user_id) REFERENCES users(user_id),
  CONSTRAINT fk_uda_division FOREIGN KEY (division_id) REFERENCES division(division_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed roles (illustrative only — not deployed):
-- INSERT INTO roles (code, name) VALUES
--   ('ADMIN','Administrator'), ('PPIC','PPIC'), ('PRODUCTION','Produksi'),
--   ('FG_PACKING','Finishgood & Packing'), ('DELIVERY','Delivery'),
--   ('FINANCE','Finance'), ('MANAGEMENT_VIEWER','Management Viewer');

-- ============================================================================
-- 3. Product identity (review point 1)
-- ============================================================================

CREATE TABLE product (
  product_id   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(255)    NOT NULL,
  kategori     VARCHAR(100)    NULL,
  division_id  BIGINT UNSIGNED NULL,
  hpp          DECIMAL(14,2)   NOT NULL DEFAULT 0,
  harga        DECIMAL(14,2)   NOT NULL DEFAULT 0,
  aktif        TINYINT(1)      NOT NULL DEFAULT 1,
  version      INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at   DATETIME        NOT NULL,
  updated_at   DATETIME        NULL,
  UNIQUE KEY uq_product_name (name),
  CONSTRAINT fk_product_division FOREIGN KEY (division_id) REFERENCES division(division_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_legacy_code (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id     BIGINT UNSIGNED NOT NULL,
  legacy_code    VARCHAR(64)     NOT NULL,
  first_seen_at  DATE            NULL,
  created_at     DATETIME        NOT NULL,
  KEY ix_legacy_code (legacy_code),
  CONSTRAINT fk_plc_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_alias (
  product_alias_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id       BIGINT UNSIGNED NOT NULL,
  raw_name         VARCHAR(255)    NOT NULL,
  source           VARCHAR(50)     NULL,
  created_at       DATETIME        NOT NULL,
  UNIQUE KEY uq_product_alias_raw (raw_name),
  CONSTRAINT fk_product_alias_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 4. Store identity (review point 2)
-- ============================================================================

CREATE TABLE store (
  store_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  canonical_name  VARCHAR(255) NOT NULL,
  channel         ENUM('ownership','franchise') NULL,
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  version         INT UNSIGNED NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NULL,
  UNIQUE KEY uq_store_canonical_name (canonical_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE store_alias (
  store_alias_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id       BIGINT UNSIGNED NOT NULL,
  raw_name       VARCHAR(255)    NOT NULL,
  factory_hint   ENUM('karangtengah','cibadak') NULL,
  created_at     DATETIME        NOT NULL,
  UNIQUE KEY uq_store_alias_raw (raw_name),
  CONSTRAINT fk_store_alias_store FOREIGN KEY (store_id) REFERENCES store(store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Illustrative seed for OD-9 (synthetic non-outlet store) — NOT executed:
-- INSERT INTO store (canonical_name, active, created_at) VALUES ('NON-OUTLET / PERORANGAN', 1, UTC_TIMESTAMP());

-- ============================================================================
-- 5. Migration staging tables (review point 2's explicit ask)
-- ============================================================================

CREATE TABLE migration_product_map (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  raw_name          VARCHAR(255)    NOT NULL,
  raw_code          VARCHAR(64)     NULL,
  source_table      VARCHAR(50)     NULL,
  occurrence_count  INT UNSIGNED    NOT NULL DEFAULT 1,
  target_id         BIGINT UNSIGNED NULL,
  status            ENUM('mapped','unresolved','conflict') NOT NULL DEFAULT 'unresolved',
  resolved_by       VARCHAR(100)    NULL,
  resolved_at       DATETIME        NULL,
  notes             TEXT            NULL,
  created_at        DATETIME        NOT NULL,
  UNIQUE KEY uq_migration_product_raw (raw_name, raw_code),
  CONSTRAINT fk_mpm_target FOREIGN KEY (target_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE migration_store_map (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  raw_name          VARCHAR(255)    NOT NULL,
  raw_code          VARCHAR(64)     NULL,
  source_table      VARCHAR(50)     NULL,
  occurrence_count  INT UNSIGNED    NOT NULL DEFAULT 1,
  target_id         BIGINT UNSIGNED NULL,
  status            ENUM('mapped','unresolved','conflict') NOT NULL DEFAULT 'unresolved',
  resolved_by       VARCHAR(100)    NULL,
  resolved_at       DATETIME        NULL,
  notes             TEXT            NULL,
  created_at        DATETIME        NOT NULL,
  UNIQUE KEY uq_migration_store_raw (raw_name, raw_code),
  CONSTRAINT fk_msm_target FOREIGN KEY (target_id) REFERENCES store(store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 6. PO (demand, factory side)
-- ============================================================================

CREATE TABLE po_batch (
  po_batch_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal      DATE            NOT NULL,
  factory_id   BIGINT UNSIGNED NOT NULL,
  version      INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at   DATETIME        NOT NULL,
  updated_at   DATETIME        NULL,
  UNIQUE KEY uq_po_batch (tanggal, factory_id),
  CONSTRAINT fk_po_batch_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE po_item (
  po_item_id   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_batch_id  BIGINT UNSIGNED NOT NULL,
  product_id   BIGINT UNSIGNED NOT NULL,
  kategori     VARCHAR(100)    NULL,
  po_awal      DECIMAL(12,2)   NOT NULL DEFAULT 0,
  po_revisi    DECIMAL(12,2)   NOT NULL DEFAULT 0,
  pb           DECIMAL(12,2)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_po_item (po_batch_id, product_id),
  CONSTRAINT fk_po_item_batch FOREIGN KEY (po_batch_id) REFERENCES po_batch(po_batch_id) ON DELETE CASCADE,
  CONSTRAINT fk_po_item_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE po_store_item (
  po_store_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_item_id       BIGINT UNSIGNED NOT NULL,
  store_id         BIGINT UNSIGNED NOT NULL,
  po_awal          DECIMAL(12,2)   NOT NULL DEFAULT 0,
  po_revisi        DECIMAL(12,2)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_po_store_item (po_item_id, store_id),
  CONSTRAINT fk_po_store_item_item FOREIGN KEY (po_item_id) REFERENCES po_item(po_item_id) ON DELETE CASCADE,
  CONSTRAINT fk_po_store_item_store FOREIGN KEY (store_id) REFERENCES store(store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE po_closure (
  po_closure_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal       DATE            NOT NULL,
  store_id      BIGINT UNSIGNED NOT NULL,
  closed_at     DATETIME        NOT NULL,
  closed_by     BIGINT UNSIGNED NULL,
  reopened_at   DATETIME        NULL,
  reopened_by   BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_po_closure (tanggal, store_id),
  CONSTRAINT fk_po_closure_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_po_closure_closed_by FOREIGN KEY (closed_by) REFERENCES users(user_id),
  CONSTRAINT fk_po_closure_reopened_by FOREIGN KEY (reopened_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 7. Production (Ceklis)
-- ============================================================================

CREATE TABLE production_run (
  production_run_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal            DATE            NOT NULL,
  division_id        BIGINT UNSIGNED NOT NULL,
  status             ENUM('not_started','draft','submitted','reopened','verified_fg') NOT NULL DEFAULT 'not_started',
  submitted_at       DATETIME        NULL,
  closed_at          DATETIME        NULL,
  closed_by          VARCHAR(100)    NULL,
  reopen_reason      TEXT            NULL,
  version            INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at         DATETIME        NOT NULL,
  updated_at         DATETIME        NULL,
  UNIQUE KEY uq_production_run (tanggal, division_id),
  CONSTRAINT fk_production_run_division FOREIGN KEY (division_id) REFERENCES division(division_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE production_item (
  production_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  production_run_id  BIGINT UNSIGNED NOT NULL,
  product_id         BIGINT UNSIGNED NOT NULL,
  target             DECIMAL(12,2)   NOT NULL DEFAULT 0,
  status             ENUM('sesuai','tidak_sesuai') NOT NULL DEFAULT 'sesuai',
  aktual             DECIMAL(12,2)   NOT NULL DEFAULT 0,
  reject             DECIMAL(12,2)   NOT NULL DEFAULT 0,
  keterangan         VARCHAR(500)    NULL,
  UNIQUE KEY uq_production_item (production_run_id, product_id),
  CONSTRAINT fk_production_item_run FOREIGN KEY (production_run_id) REFERENCES production_run(production_run_id) ON DELETE CASCADE,
  CONSTRAINT fk_production_item_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 8. FG / Packing
-- ============================================================================

CREATE TABLE fg_batch (
  fg_batch_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal      DATE            NOT NULL,
  factory_id   BIGINT UNSIGNED NOT NULL,
  ready_at     DATETIME        NULL,
  version      INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at   DATETIME        NOT NULL,
  updated_at   DATETIME        NULL,
  UNIQUE KEY uq_fg_batch (tanggal, factory_id),
  CONSTRAINT fk_fg_batch_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fg_batch_source (
  fg_batch_source_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fg_batch_id        BIGINT UNSIGNED NOT NULL,
  production_run_id  BIGINT UNSIGNED NOT NULL,
  source_version     INT UNSIGNED    NOT NULL,
  UNIQUE KEY uq_fg_batch_source (fg_batch_id, production_run_id),
  CONSTRAINT fk_fbs_batch FOREIGN KEY (fg_batch_id) REFERENCES fg_batch(fg_batch_id) ON DELETE CASCADE,
  CONSTRAINT fk_fbs_run FOREIGN KEY (production_run_id) REFERENCES production_run(production_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE fg_item (
  fg_item_id   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fg_batch_id  BIGINT UNSIGNED NOT NULL,
  product_id   BIGINT UNSIGNED NOT NULL,
  store_id     BIGINT UNSIGNED NOT NULL,
  qty          DECIMAL(12,2)   NOT NULL DEFAULT 0,
  status       VARCHAR(30)     NOT NULL DEFAULT 'belum_dicek',
  keterangan   VARCHAR(500)    NULL,
  UNIQUE KEY uq_fg_item (fg_batch_id, product_id, store_id),
  CONSTRAINT fk_fg_item_batch FOREIGN KEY (fg_batch_id) REFERENCES fg_batch(fg_batch_id) ON DELETE CASCADE,
  CONSTRAINT fk_fg_item_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_fg_item_store FOREIGN KEY (store_id) REFERENCES store(store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 9. Delivery Order lifecycle (review point 14 — DO uniqueness)
-- ============================================================================

CREATE TABLE delivery_order (
  delivery_order_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no            VARCHAR(50)     NULL,
  tanggal           DATE            NOT NULL,
  store_id          BIGINT UNSIGNED NOT NULL,
  shipment_group    ENUM('MAIN','PASTRY','OTHER') NOT NULL DEFAULT 'MAIN',
  status            ENUM('draft','preprinted','ready','shipped','cancelled') NOT NULL DEFAULT 'draft',
  batch             VARCHAR(64)     NULL,
  catatan           VARCHAR(500)    NULL,
  created_by        BIGINT UNSIGNED NULL,
  preprinted_at     DATETIME        NULL,
  ready_at          DATETIME        NULL,
  shipped_at        DATETIME        NULL,
  shipped_by        BIGINT UNSIGNED NULL,
  version           INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at        DATETIME        NOT NULL,
  updated_at        DATETIME        NULL,
  -- Partial-unique-index pattern: only "open" (not shipped/cancelled) DOs are
  -- constrained to one per (tanggal, store_id, shipment_group). See
  -- docs/mysql-schema-v1.md §11 for the version requirement (MySQL 5.7.6+ /
  -- MariaDB 10.2+) and the transactional fallback if unavailable (OD-4).
  open_key VARCHAR(80) GENERATED ALWAYS AS (
      CASE WHEN status NOT IN ('shipped','cancelled')
           THEN CONCAT(tanggal, '|', store_id, '|', shipment_group)
           ELSE NULL END
  ) STORED,
  UNIQUE KEY uq_delivery_order_open (open_key),
  UNIQUE KEY uq_delivery_order_doc_no (doc_no),
  CONSTRAINT fk_do_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_do_created_by FOREIGN KEY (created_by) REFERENCES users(user_id),
  CONSTRAINT fk_do_shipped_by FOREIGN KEY (shipped_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delivery_order_item (
  delivery_order_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delivery_order_id      BIGINT UNSIGNED NOT NULL,
  product_id             BIGINT UNSIGNED NOT NULL,
  planned_qty            DECIMAL(12,2)   NOT NULL DEFAULT 0,
  available_qty          DECIMAL(12,2)   NULL,
  actual_ship_qty        DECIMAL(12,2)   NULL,
  UNIQUE KEY uq_do_item (delivery_order_id, product_id),
  CONSTRAINT fk_do_item_order FOREIGN KEY (delivery_order_id) REFERENCES delivery_order(delivery_order_id) ON DELETE CASCADE,
  CONSTRAINT fk_do_item_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 10. Customer Order (Pesanan) — pure demand (review point 3)
-- ============================================================================

CREATE TABLE customer_order (
  customer_order_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no          VARCHAR(50)     NOT NULL,
  store_id          BIGINT UNSIGNED NOT NULL,
  tgl_pesan         DATE            NOT NULL,
  tgl_produksi      DATE            NULL,
  tgl_ambil         DATE            NULL,
  tipe              VARCHAR(30)     NULL,
  pemesan           VARCHAR(150)    NULL,
  kontak            VARCHAR(100)    NULL,
  alamat            VARCHAR(300)    NULL,
  pct_omset         DECIMAL(5,2)    NOT NULL DEFAULT 100,
  sumber            ENUM('produksi','stok') NOT NULL,
  status            VARCHAR(30)     NOT NULL DEFAULT 'baru',
  catatan           VARCHAR(500)    NULL,
  version           INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at        DATETIME        NOT NULL,
  updated_at        DATETIME        NULL,
  UNIQUE KEY uq_customer_order_no (order_no),
  CONSTRAINT fk_customer_order_store FOREIGN KEY (store_id) REFERENCES store(store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customer_order_item (
  customer_order_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_order_id      BIGINT UNSIGNED NOT NULL,
  product_id             BIGINT UNSIGNED NOT NULL,
  qty                    DECIMAL(12,2)   NOT NULL,
  harga                  DECIMAL(14,2)   NOT NULL,
  UNIQUE KEY uq_customer_order_item (customer_order_id, product_id),
  CONSTRAINT fk_coi_order FOREIGN KEY (customer_order_id) REFERENCES customer_order(customer_order_id) ON DELETE CASCADE,
  CONSTRAINT fk_coi_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 11. Shipment — the sole KELUAR event (review point 3/4, OD-9, OD-14)
-- ============================================================================

-- shipment = header for ONE physical delivery event (review point 1, CORRECTED this pass).
-- store_id NEVER NULL: walk-in/non-outlet customers resolve server-side to the synthetic
-- "NON-OUTLET / PERORANGAN" store row (review point 2, LOCKED — see docs/mysql-schema-v1.md §5.8.1).
-- Void is header-level only: see shipment_item and stock_ledger void walkthrough below.
CREATE TABLE shipment (
  shipment_id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch             VARCHAR(64)     NOT NULL,
  tanggal           DATE            NOT NULL,
  store_id          BIGINT UNSIGNED NOT NULL,
  no_sj             VARCHAR(50)     NULL,
  pengemudi         VARCHAR(100)    NULL,
  kendaraan         VARCHAR(50)     NULL,
  shipment_group    ENUM('MAIN','PASTRY','OTHER') NOT NULL DEFAULT 'MAIN',
  source_type       ENUM('delivery_order','manual_kirim','customer_order_fulfillment') NOT NULL,
  delivery_order_id BIGINT UNSIGNED NULL,
  customer_order_id BIGINT UNSIGNED NULL,
  status            ENUM('active','void') NOT NULL DEFAULT 'active',
  voided_at         DATETIME        NULL,
  voided_by         BIGINT UNSIGNED NULL,
  void_reason       VARCHAR(500)    NULL,
  version           INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at        DATETIME        NOT NULL,
  KEY ix_shipment_tanggal_store (tanggal, store_id),
  KEY ix_shipment_batch (batch),
  CONSTRAINT fk_shipment_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_shipment_do FOREIGN KEY (delivery_order_id) REFERENCES delivery_order(delivery_order_id),
  CONSTRAINT fk_shipment_co FOREIGN KEY (customer_order_id) REFERENCES customer_order(customer_order_id),
  CONSTRAINT fk_shipment_voided_by FOREIGN KEY (voided_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- shipment_item = one row per product on the shipment (review point 1, NEW this pass).
CREATE TABLE shipment_item (
  shipment_item_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id       BIGINT UNSIGNED NOT NULL,
  product_id        BIGINT UNSIGNED NOT NULL,
  qty               DECIMAL(12,2)   NOT NULL,
  UNIQUE KEY uq_shipment_item (shipment_id, product_id),
  KEY ix_shipment_item_product (product_id),
  CONSTRAINT fk_shipment_item_shipment FOREIGN KEY (shipment_id) REFERENCES shipment(shipment_id) ON DELETE CASCADE,
  CONSTRAINT fk_shipment_item_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 12. Invoice, payments (review point 3 — shipment-gated)
-- ============================================================================

-- legacy_fulfillment_status (review point 5, LOCKED): migration-only escape hatch.
-- 'verified'     = normal case, backed by a real shipment (post-cutover invariant).
-- 'reconstructed'= migrated Pesanan-derived invoice, stock-out evidence exists but no
--                  original shipment record (StokAdj or equivalent) -> synthesized shipment.
-- 'unverified'   = migrated invoice with NO shipment/StokAdj/reliable evidence at all;
--                  financial history preserved, but NO shipment_out ledger row is created
--                  and it is excluded from physical shipment/fulfillment KPIs.
-- New PHP API code must NEVER be able to INSERT an invoice with legacy_fulfillment_status
-- other than 'verified' -- 'reconstructed'/'unverified' are migration-time-only values.
CREATE TABLE invoice (
  invoice_id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no               VARCHAR(50)     NOT NULL,
  batch                    VARCHAR(64)     NOT NULL,
  tanggal                  DATE            NOT NULL,
  store_id                 BIGINT UNSIGNED NOT NULL,
  no_sj                    VARCHAR(50)     NULL,
  total                    DECIMAL(14,2)   NOT NULL DEFAULT 0,
  rate_pct                 DECIMAL(5,2)    NOT NULL,
  rate_source              ENUM('default','override') NOT NULL DEFAULT 'default',
  override_reason          VARCHAR(500)    NULL,
  sumber                   ENUM('kirim','pesanan','mutasi') NOT NULL DEFAULT 'kirim',
  legacy_fulfillment_status ENUM('verified','reconstructed','unverified') NOT NULL DEFAULT 'verified',
  version                  INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at               DATETIME        NOT NULL,
  updated_at               DATETIME        NULL,
  UNIQUE KEY uq_invoice_no (invoice_no),
  UNIQUE KEY uq_invoice_batch (batch),
  CONSTRAINT fk_invoice_store FOREIGN KEY (store_id) REFERENCES store(store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- invoice_shipment references the shipment HEADER (one row per linked physical delivery
-- event, not per product). Normal post-cutover invariant: a NEW invoice (legacy_fulfillment_status
-- = 'verified') must reference >= 1 active shipment row. Migration-only 'unverified' invoices
-- have zero rows here by design (review point 5).
CREATE TABLE invoice_shipment (
  invoice_id  BIGINT UNSIGNED NOT NULL,
  shipment_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (invoice_id, shipment_id),
  CONSTRAINT fk_is_invoice FOREIGN KEY (invoice_id) REFERENCES invoice(invoice_id) ON DELETE CASCADE,
  CONSTRAINT fk_is_shipment FOREIGN KEY (shipment_id) REFERENCES shipment(shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE invoice_item (
  invoice_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id      BIGINT UNSIGNED NOT NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  qty_do          DECIMAL(12,2)   NOT NULL,
  qty_invoice     DECIMAL(12,2)   NOT NULL,
  harga           DECIMAL(14,2)   NOT NULL,
  subtotal        DECIMAL(14,2)   NOT NULL,
  rate_pct        DECIMAL(5,2)    NOT NULL,
  rate_source     ENUM('default','override') NOT NULL,
  override_reason VARCHAR(500)    NULL,
  UNIQUE KEY uq_invoice_item (invoice_id, product_id),
  CONSTRAINT fk_invoice_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoice(invoice_id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_item_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment (
  payment_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id  BIGINT UNSIGNED NOT NULL,
  tanggal     DATE            NOT NULL,
  jumlah      DECIMAL(14,2)   NOT NULL,
  cara        VARCHAR(50)     NULL,
  keterangan  VARCHAR(500)    NULL,
  created_at  DATETIME        NOT NULL,
  KEY ix_payment_invoice (invoice_id),
  CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoice(invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 13. Returns, rejects, retail sales
-- ============================================================================

CREATE TABLE return_note (
  return_note_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch          VARCHAR(64)     NOT NULL,
  tanggal        DATE            NOT NULL,
  store_id       BIGINT UNSIGNED NOT NULL,
  product_id     BIGINT UNSIGNED NOT NULL,
  qty            DECIMAL(12,2)   NOT NULL,
  alasan         VARCHAR(500)    NULL,
  sumber         VARCHAR(30)     NULL,
  created_at     DATETIME        NOT NULL,
  KEY ix_return_note_date_store (tanggal, store_id),
  CONSTRAINT fk_return_note_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_return_note_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE reject_note (
  reject_note_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch          VARCHAR(64)     NOT NULL,
  tanggal        DATE            NOT NULL,
  store_id       BIGINT UNSIGNED NOT NULL,
  product_id     BIGINT UNSIGNED NOT NULL,
  qty            DECIMAL(12,2)   NOT NULL,
  alasan         VARCHAR(500)    NULL,
  resolusi       ENUM('potong','ganti') NOT NULL,
  nilai          DECIMAL(14,2)   NOT NULL DEFAULT 0,
  invoice_id     BIGINT UNSIGNED NULL,
  created_at     DATETIME        NOT NULL,
  KEY ix_reject_note_date_store (tanggal, store_id),
  CONSTRAINT fk_reject_note_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_reject_note_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_reject_note_invoice FOREIGN KEY (invoice_id) REFERENCES invoice(invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE retail_sale (
  retail_sale_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch          VARCHAR(64)     NOT NULL,
  tanggal        DATE            NOT NULL,
  store_id       BIGINT UNSIGNED NOT NULL,
  product_id     BIGINT UNSIGNED NOT NULL,
  qty            DECIMAL(12,2)   NOT NULL,
  sumber         VARCHAR(30)     NOT NULL DEFAULT 'pos',
  created_at     DATETIME        NOT NULL,
  KEY ix_retail_sale_date_store (tanggal, store_id),
  CONSTRAINT fk_retail_sale_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_retail_sale_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 14. Stock ledger — append-only (review point 4) + materialized balance
-- ============================================================================

CREATE TABLE stock_ledger (
  stock_ledger_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id      BIGINT UNSIGNED NOT NULL,
  location_id     BIGINT UNSIGNED NOT NULL,
  event_type      ENUM('production_in','shipment_out','adjustment','opening_balance','reversal') NOT NULL,
  qty_delta       DECIMAL(12,2)   NOT NULL,
  source_type     ENUM('production_run','shipment_item','stock_adjustment','stock_transfer','opening_balance_cutover','historical_replay','reversal') NOT NULL,
  source_id       BIGINT UNSIGNED NULL,
  reversal_of_id  BIGINT UNSIGNED NULL,
  legacy_ref      VARCHAR(100)    NULL,
  event_date      DATE            NOT NULL,
  created_at      DATETIME        NOT NULL,
  created_by      BIGINT UNSIGNED NULL,
  notes           VARCHAR(500)    NULL,
  KEY ix_stock_ledger_product_date (product_id, location_id, event_date),
  KEY ix_stock_ledger_source (source_type, source_id),
  CONSTRAINT fk_stock_ledger_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_stock_ledger_location FOREIGN KEY (location_id) REFERENCES location(location_id),
  CONSTRAINT fk_stock_ledger_reversal FOREIGN KEY (reversal_of_id) REFERENCES stock_ledger(stock_ledger_id),
  CONSTRAINT fk_stock_ledger_user FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_balance (
  product_id      BIGINT UNSIGNED NOT NULL,
  location_id     BIGINT UNSIGNED NOT NULL,
  qty_on_hand     DECIMAL(12,2)   NOT NULL DEFAULT 0,
  last_ledger_id  BIGINT UNSIGNED NULL,
  updated_at      DATETIME        NOT NULL,
  PRIMARY KEY (product_id, location_id),
  CONSTRAINT fk_stock_balance_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_stock_balance_location FOREIGN KEY (location_id) REFERENCES location(location_id),
  CONSTRAINT fk_stock_balance_ledger FOREIGN KEY (last_ledger_id) REFERENCES stock_ledger(stock_ledger_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 15. Mutasi — append-only transfer + explicit reversal (review point 11)
-- ============================================================================

CREATE TABLE stock_transfer (
  stock_transfer_id    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal              DATE            NOT NULL,
  product_id           BIGINT UNSIGNED NOT NULL,
  from_store_id        BIGINT UNSIGNED NOT NULL,
  to_store_id          BIGINT UNSIGNED NOT NULL,
  qty                  DECIMAL(12,2)   NOT NULL,
  keterangan           VARCHAR(500)    NULL,
  source_shipment_id   BIGINT UNSIGNED NULL,
  from_invoice_id      BIGINT UNSIGNED NULL,
  to_invoice_id        BIGINT UNSIGNED NULL,
  reverses_transfer_id BIGINT UNSIGNED NULL,
  created_at           DATETIME        NOT NULL,
  KEY ix_stock_transfer_date (tanggal),
  CONSTRAINT fk_stock_transfer_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_stock_transfer_from_store FOREIGN KEY (from_store_id) REFERENCES store(store_id),
  CONSTRAINT fk_stock_transfer_to_store FOREIGN KEY (to_store_id) REFERENCES store(store_id),
  CONSTRAINT fk_stock_transfer_shipment FOREIGN KEY (source_shipment_id) REFERENCES shipment(shipment_id),
  CONSTRAINT fk_stock_transfer_from_invoice FOREIGN KEY (from_invoice_id) REFERENCES invoice(invoice_id),
  CONSTRAINT fk_stock_transfer_to_invoice FOREIGN KEY (to_invoice_id) REFERENCES invoice(invoice_id),
  CONSTRAINT fk_stock_transfer_reverses FOREIGN KEY (reverses_transfer_id) REFERENCES stock_transfer(stock_transfer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 16. Stock Adjustment — no cascade-delete, compensating entries (review point 12)
-- ============================================================================

CREATE TABLE stock_adjustment (
  stock_adjustment_id    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tanggal                DATE            NOT NULL,
  product_id             BIGINT UNSIGNED NOT NULL,
  location_id            BIGINT UNSIGNED NOT NULL,
  tipe                   ENUM('masuk','waste','rusak','opname','koreksi') NOT NULL,
  qty                    DECIMAL(12,2)   NOT NULL,
  keterangan             VARCHAR(500)    NULL,
  sumber                 VARCHAR(30)     NULL,
  reject_note_id         BIGINT UNSIGNED NULL,
  reverses_adjustment_id BIGINT UNSIGNED NULL,
  created_at             DATETIME        NOT NULL,
  CONSTRAINT fk_stock_adj_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_stock_adj_location FOREIGN KEY (location_id) REFERENCES location(location_id),
  CONSTRAINT fk_stock_adj_reject FOREIGN KEY (reject_note_id) REFERENCES reject_note(reject_note_id),
  CONSTRAINT fk_stock_adj_reverses FOREIGN KEY (reverses_adjustment_id) REFERENCES stock_adjustment(stock_adjustment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 17. Document numbering (review point 5)
-- ============================================================================

CREATE TABLE document_sequence (
  document_type VARCHAR(30)  NOT NULL,
  year          SMALLINT     NOT NULL,
  month         TINYINT      NOT NULL,
  last_number   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (document_type, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 18. Idempotency (review point 8)
-- ============================================================================

-- Retention (review point 9, LOCKED): 90 days. Rows older than 90 days are purged by a
-- scheduled cleanup job (mechanism documented in docs/mysql-open-decisions-v1.md; not
-- built in this pass). Same request_id + same request_fingerprint -> replay stored
-- response_body. Same request_id + different request_fingerprint -> 409
-- IDEMPOTENCY_KEY_REUSE_MISMATCH.
CREATE TABLE idempotency_log (
  request_id           VARCHAR(64)   PRIMARY KEY,
  endpoint             VARCHAR(150)  NOT NULL,
  request_fingerprint  CHAR(64)      NOT NULL,
  response_status      ENUM('ok','error','conflict') NOT NULL,
  response_body        JSON          NOT NULL,
  record_type          VARCHAR(50)   NULL,
  record_key           VARCHAR(100)  NULL,
  created_at           DATETIME      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 19. Audit & config
-- ============================================================================

-- Retention (review point 9, LOCKED): audit_log is retained indefinitely. No partitioning
-- is required at launch.
CREATE TABLE audit_log (
  audit_log_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id        VARCHAR(64)     NULL,
  event_at          DATETIME        NOT NULL,
  user_id           BIGINT UNSIGNED NULL,
  action            VARCHAR(100)    NOT NULL,
  record_type       VARCHAR(50)     NOT NULL,
  record_key        VARCHAR(100)    NOT NULL,
  previous_version  INT UNSIGNED    NULL,
  new_version       INT UNSIGNED    NULL,
  payload_summary   VARCHAR(2000)   NULL,
  status            ENUM('ok','conflict','error') NOT NULL,
  KEY ix_audit_record (record_type, record_key),
  KEY ix_audit_event_at (event_at),
  CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE master_setting (
  setting_key   VARCHAR(100) PRIMARY KEY,
  setting_value VARCHAR(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- End of draft. 45 tables (44 -> 45: shipment split into shipment header + new
-- shipment_item, review point 1). See docs/mysql-schema-v1.md for full rationale,
-- docs/mysql-open-decisions-v1.md for what still needs sign-off, and
-- docs/mysql-migration-map-v1.md for how legacy data populates these tables.
-- ⚠️  DO NOT RUN THIS FILE AGAINST ANY DATABASE UNTIL THE SIGN-OFF CHECKLIST
--     IN docs/mysql-open-decisions-v1.md §5 IS COMPLETE.  ⚠️
-- ============================================================================
