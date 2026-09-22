-- ============================================================================
-- Migration 0010 — Pesanan Khusus Toko / Pesanan Non-Toko + Production Routing
-- ============================================================================
--
-- Purely additive. No Phase 1-5.5.1/migration 0001-0009 table is altered,
-- renamed, or dropped — this migration adds exactly three new tables (DDL
-- only; see #1/#2 below for why the new division row and catalog seed
-- data are NOT here). PO Reguler Toko's own tables (po_batch/po_item/
-- po_store_item) are completely untouched: this is a SEPARATE demand
-- source, never merged into PO.
--
-- Demand-source model (task's own "Core Principle"): PO Reguler Toko +
-- Pesanan Khusus Toko + Pesanan Non-Toko + Replacement Reject = Total
-- Operational Production Demand, but every source stays traceable
-- separately. This migration only builds the "Pesanan Khusus Toko" and
-- "Pesanan Non-Toko" sources — Replacement Reject is explicitly out of
-- scope for this phase (task's own instruction).
--
-- 1. New division: real master data (database/legacy/phase1-source-v1.json,
--    loaded by Phase1Importer::importDivisions()) has NO "Cake & Custom"
--    division — the 8 real production divisions are Roti & Bollen, Basic,
--    Donat/Mochi/AKB, Pastry, Cookies, Bolu, and the two Finishgood &
--    Packing verification divisions. Custom-cake/character items (DELUXE
--    KARAKTER.../ICING...) genuinely don't belong to any existing
--    division, so ONE new row is added to the EXISTING division table
--    (never a parallel/duplicated division concept) — assigned to
--    Karangtengah (the main factory), is_verification=0 (a real
--    production division, not an FG-verification one). This is DATA, not
--    DDL, so it is NOT seeded here — a schema migration only ever creates
--    structure in this project (see every prior 0001-0009 migration).
--    SpecialOrderRepository::findOrCreateCakeCustomDivision() lazily
--    inserts it on first use (same find-or-create-with-UNIQUE-key-race-
--    guard pattern as FgRepository::findOrCreateLocationForFactory()) —
--    this also sidesteps a real ordering hazard: a live cPanel deployment
--    already has its `factory`/`division` rows seeded from Phase 1 long
--    ago, but this repo's own disposable-test-DB bootstrap order runs
--    migrate.php BEFORE seed.php/Phase1Importer, so a factory-dependent
--    INSERT placed directly in this .sql file would silently insert
--    nothing on a fresh test database.
--
-- 2. special_order_catalog — the controlled master for special/custom
--    order items (task's own "Do not mix this catalog blindly with the
--    normal 472-product master" + "Do not create a permanent normal
--    product master row every time a one-off custom order exists").
--    Same reasoning as #1: the DELUXE KARAKTER/ICING seed rows are DATA,
--    lazily inserted by SpecialOrderRepository::ensureCatalogSeeded() the
--    first time the catalog is listed, not baked into this DDL-only
--    migration. Admin can extend the catalog further afterward via the
--    normal special_order_catalog CRUD.
--
-- 3. special_order — ONE header table for BOTH Pesanan Khusus Toko
--    (source_type='toko_khusus') and Pesanan Non-Toko
--    (source_type='non_toko') — they share nearly every header field, and
--    a single table keeps "Total Operational Production Demand" traceable
--    and aggregatable by source without inventing a second near-identical
--    table. store_id / non-store fields are mutually exclusive by
--    source_type (enforced in the service layer, matching this project's
--    existing convention of app-layer invariants over DB CHECK
--    constraints — see e.g. ReceiptService's evidence-required rule).
--    order_no reuses the EXISTING document_sequence infrastructure
--    (DocumentSequenceService) — never a second numbering table.
--
-- 4. special_order_item — one row per line item. Routing is PER ITEM
--    (task's own explicit "Routing must happen PER ITEM" rule): every
--    item carries its own division_id, snapshotted at insert time from
--    either the existing product's product.division_id (existing_product)
--    or the special catalog's division_id (special_catalog) — Production's
--    inbox filters/groups by this column directly, never by re-deriving
--    it from the product/catalog master at read time (keeps a later
--    catalog/product division change from silently reclassifying
--    already-sent demand).
-- ============================================================================

CREATE TABLE IF NOT EXISTS special_order_catalog (
  special_order_catalog_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(50)     NOT NULL,
  name            VARCHAR(255)    NOT NULL,
  division_id     BIGINT UNSIGNED NOT NULL,
  default_price   DECIMAL(14,2)   NULL,
  default_charge  DECIMAL(14,2)   NULL,
  notes           VARCHAR(500)    NULL,
  active          TINYINT(1)      NOT NULL DEFAULT 1,
  created_at      DATETIME        NOT NULL,
  updated_at      DATETIME        NULL,
  UNIQUE KEY uq_special_order_catalog_code (code),
  KEY ix_special_order_catalog_division (division_id),
  CONSTRAINT fk_soc_division FOREIGN KEY (division_id) REFERENCES division(division_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS special_order (
  special_order_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no               VARCHAR(50)     NOT NULL,
  source_type            ENUM('toko_khusus','non_toko') NOT NULL,
  order_date             DATE            NOT NULL,
  store_id               BIGINT UNSIGNED NULL,
  non_store_source       ENUM('konsumen_langsung','cs','sales_executive','umum') NULL,
  customer_name          VARCHAR(255)    NULL,
  customer_contact       VARCHAR(100)    NULL,
  fulfillment_type       ENUM('pengiriman','pickup') NULL,
  delivery_address       VARCHAR(500)    NULL,
  factory_id             BIGINT UNSIGNED NULL,
  required_date          DATE            NOT NULL,
  required_time          TIME            NULL,
  pic_user_id            BIGINT UNSIGNED NULL,
  general_note           VARCHAR(1000)   NULL,
  status                 ENUM('draft','confirmed','sent_to_production','in_production','ready','completed','cancelled') NOT NULL DEFAULT 'draft',
  version                INT UNSIGNED    NOT NULL DEFAULT 1,
  sent_to_production_at  DATETIME        NULL,
  cancelled_at           DATETIME        NULL,
  cancelled_by           BIGINT UNSIGNED NULL,
  cancel_reason          VARCHAR(500)    NULL,
  created_by             BIGINT UNSIGNED NOT NULL,
  created_at             DATETIME        NOT NULL,
  updated_at             DATETIME        NULL,
  UNIQUE KEY uq_special_order_no (order_no),
  KEY ix_special_order_status (status),
  KEY ix_special_order_required_date (required_date),
  KEY ix_special_order_store (store_id),
  KEY ix_special_order_source (source_type),
  CONSTRAINT fk_so_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_so_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id),
  CONSTRAINT fk_so_pic_user FOREIGN KEY (pic_user_id) REFERENCES users(user_id),
  CONSTRAINT fk_so_created_by FOREIGN KEY (created_by) REFERENCES users(user_id),
  CONSTRAINT fk_so_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS special_order_item (
  special_order_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  special_order_id       BIGINT UNSIGNED NOT NULL,
  item_type               ENUM('existing_product','special_catalog') NOT NULL,
  product_id               BIGINT UNSIGNED NULL,
  special_catalog_id       BIGINT UNSIGNED NULL,
  division_id              BIGINT UNSIGNED NOT NULL,
  item_name_snapshot       VARCHAR(255)    NOT NULL,
  qty                      DECIMAL(12,2)   NOT NULL,
  unit_price               DECIMAL(14,2)   NOT NULL DEFAULT 0,
  charge                   DECIMAL(14,2)   NOT NULL DEFAULT 0,
  subtotal                 DECIMAL(14,2)   NOT NULL DEFAULT 0,
  special_note             VARCHAR(1000)   NULL,
  created_at                DATETIME       NOT NULL,
  KEY ix_soi_order (special_order_id),
  KEY ix_soi_division (division_id),
  KEY ix_soi_product (product_id),
  KEY ix_soi_catalog (special_catalog_id),
  CONSTRAINT fk_soi_order FOREIGN KEY (special_order_id) REFERENCES special_order(special_order_id) ON DELETE CASCADE,
  CONSTRAINT fk_soi_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_soi_catalog FOREIGN KEY (special_catalog_id) REFERENCES special_order_catalog(special_order_catalog_id),
  CONSTRAINT fk_soi_division FOREIGN KEY (division_id) REFERENCES division(division_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
