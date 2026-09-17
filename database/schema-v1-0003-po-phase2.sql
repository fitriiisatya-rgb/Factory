-- ============================================================================
-- Migration 0003: PO Phase 2 fast-track (authoritative MySQL PO module)
-- ============================================================================
-- Additive only — reuses the po_batch/po_item/po_store_item tables already
-- created by 0001 (they already match the shape this phase needs almost
-- exactly: po_batch keyed on (tanggal, factory_id) with optimistic version,
-- po_item keyed on (po_batch_id, product_id) with po_awal/po_revisi/pb,
-- po_store_item keyed on (po_item_id, store_id) with its own po_awal/
-- po_revisi breakdown). No Phase 0/1 table is recreated or dropped, and no
-- existing master identity (product_id/store_id/division_id) is touched.
--
-- 1. po_batch gains upload-provenance columns. po_batch is CURRENT STATE
--    (one row per date+factory, overwritten by every upload per the audited
--    legacy semantics — see poMergeDenganExisting() in the frontend source),
--    so these columns always describe the MOST RECENT upload that touched
--    this batch, not the full history. Full immutable history lives in the
--    new po_import table below — current-state and audit-history are kept
--    as two separate concerns on purpose (task instruction: do not sacrifice
--    audit history merely to simplify calculations).
--
-- 2. po_import (new): one immutable row per upload EVENT, whether it wrote
--    data, was rejected for unresolved products/stores, or was recognized
--    as a duplicate of an already-imported file (source_hash match). Never
--    updated or deleted after insert — corrections happen by uploading a
--    new revision file, never by editing history.
--
-- Same idempotency discipline as 0002: ADD COLUMN/ADD KEY use IF NOT EXISTS
-- (confirmed supported on MariaDB 10.11.19/10.11.14). ADD CONSTRAINT ...
-- FOREIGN KEY does not support IF NOT EXISTS on MariaDB, so those are listed
-- last and rely on the migration runner's own schema_migrations bookkeeping
-- to never re-apply this file a second time.

ALTER TABLE po_batch
  ADD COLUMN IF NOT EXISTS upload_type ENUM('initial','revision') NULL AFTER factory_id;

ALTER TABLE po_batch
  ADD COLUMN IF NOT EXISTS source_filename VARCHAR(255) NULL AFTER upload_type;

ALTER TABLE po_batch
  ADD COLUMN IF NOT EXISTS source_hash CHAR(64) NULL AFTER source_filename;

ALTER TABLE po_batch
  ADD COLUMN IF NOT EXISTS uploaded_by BIGINT UNSIGNED NULL AFTER source_hash;

ALTER TABLE po_batch
  ADD KEY IF NOT EXISTS ix_po_batch_uploaded_by (uploaded_by);

CREATE TABLE IF NOT EXISTS po_import (
  po_import_id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_batch_id        BIGINT UNSIGNED NOT NULL,
  tanggal            DATE            NOT NULL,
  factory_id         BIGINT UNSIGNED NOT NULL,
  upload_type        ENUM('initial','revision') NOT NULL,
  source_filename    VARCHAR(255)    NULL,
  source_hash        CHAR(64)        NOT NULL,
  rows_total         INT UNSIGNED    NOT NULL DEFAULT 0,
  rows_po_awal       INT UNSIGNED    NOT NULL DEFAULT 0,
  rows_po_revisi     INT UNSIGNED    NOT NULL DEFAULT 0,
  rows_pb_ignored    INT UNSIGNED    NOT NULL DEFAULT 0,
  products_mapped    INT UNSIGNED    NOT NULL DEFAULT 0,
  products_unresolved INT UNSIGNED   NOT NULL DEFAULT 0,
  stores_mapped      INT UNSIGNED    NOT NULL DEFAULT 0,
  stores_unresolved  INT UNSIGNED    NOT NULL DEFAULT 0,
  total_po_awal      DECIMAL(14,2)   NOT NULL DEFAULT 0,
  total_po_revisi    DECIMAL(14,2)   NOT NULL DEFAULT 0,
  warnings_json      TEXT            NULL,
  result             ENUM('imported','rejected_unresolved','duplicate_file') NOT NULL,
  uploaded_by        BIGINT UNSIGNED NULL,
  uploaded_at        DATETIME        NOT NULL,
  KEY ix_po_import_batch (po_batch_id),
  KEY ix_po_import_dup_lookup (tanggal, factory_id, source_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE po_batch
  ADD CONSTRAINT fk_po_batch_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(user_id);

ALTER TABLE po_import
  ADD CONSTRAINT fk_po_import_batch FOREIGN KEY (po_batch_id) REFERENCES po_batch(po_batch_id) ON DELETE CASCADE;

ALTER TABLE po_import
  ADD CONSTRAINT fk_po_import_factory FOREIGN KEY (factory_id) REFERENCES factory(factory_id);

ALTER TABLE po_import
  ADD CONSTRAINT fk_po_import_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(user_id);
