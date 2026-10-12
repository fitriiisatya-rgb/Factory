-- ============================================================================
-- Migration 0020 — Reject di Toko (produk rusak/jamur sebelum expired)
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- A store can now report, AFTER receipt, a product that spoiled before its
-- expiry date (e.g. mold on day 2 of a 4-day shelf life). Unlike a Retur
-- (unsold goods — store's burden, record only), this is a REJECT: the
-- factory's burden. Both are submitted from the same Portal menu ("Lapor
-- Barang") and stored in the same retur_request table, told apart by
-- `jenis`:
--   - 'retur'       — unchanged behaviour: record only, never touches
--                     invoice or stock.
--   - 'reject_toko' — once Admin verifies it, qty x product.harga is
--                     deducted from the store's NEXT generated invoice
--                     (invoice_id records which one consumed it, so it is
--                     deducted exactly once). Admin also picks a follow-up:
--                     'potong' (deduct only) or 'ganti' (deduct AND send a
--                     replacement as a Pesanan Khusus Toko, billed normally
--                     when received — pengganti_order_id links it).
--
-- SAFETY
-- ------
--   - Purely additive: new NULLable / defaulted columns on retur_request.
--     Every existing row becomes jenis='retur' (its true meaning), so no
--     historical behaviour changes.
--   - invoice_id FK is ON DELETE SET NULL: voiding an invoice releases its
--     reject deductions back to the pool for the next invoice, exactly like
--     invoice_shipment / invoice_mutasi rows are released by the cascade.
--   - Retry-safe: ADD COLUMN / ADD KEY IF NOT EXISTS (MariaDB). The FK is
--     added only when missing (information_schema check).
-- ============================================================================

ALTER TABLE retur_request
  ADD COLUMN IF NOT EXISTS jenis ENUM('retur','reject_toko') NOT NULL DEFAULT 'retur';

ALTER TABLE retur_request
  ADD COLUMN IF NOT EXISTS kondisi VARCHAR(50) NULL;

ALTER TABLE retur_request
  ADD COLUMN IF NOT EXISTS exp_date DATE NULL;

ALTER TABLE retur_request
  ADD COLUMN IF NOT EXISTS tindak_lanjut ENUM('potong','ganti') NULL;

ALTER TABLE retur_request
  ADD COLUMN IF NOT EXISTS pengganti_order_id BIGINT UNSIGNED NULL;

ALTER TABLE retur_request
  ADD COLUMN IF NOT EXISTS invoice_id BIGINT UNSIGNED NULL;

ALTER TABLE retur_request
  ADD KEY IF NOT EXISTS ix_retur_request_jenis (jenis, status);

ALTER TABLE retur_request
  ADD KEY IF NOT EXISTS ix_retur_request_invoice (invoice_id);

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'retur_request'
    AND CONSTRAINT_NAME = 'fk_retur_invoice' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @fk_sql := IF(@fk_exists = 0,
  'ALTER TABLE retur_request ADD CONSTRAINT fk_retur_invoice FOREIGN KEY (invoice_id) REFERENCES invoice(invoice_id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE fk_stmt FROM @fk_sql;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;
