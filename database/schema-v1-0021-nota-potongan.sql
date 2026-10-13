-- ============================================================================
-- Migration 0021 — Invoice per DO + Nota Potongan
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- The owner invoices stores per DO ("sesuai DO"), right after the store
-- confirms receipt. Anything that later reduces what a store owes for goods
-- it ALREADY received on an issued invoice is issued as a separate
-- NOTA POTONGAN (credit note) that references that invoice, never by editing
-- the invoice:
--   - 'reject_toko'   — an approved Reject di Toko (migration 0020): goods
--                       that spoiled in the store before expiry.
--   - 'mutasi_keluar' — a completed Mutasi: the SOURCE store hands goods it
--                       was billed for to another store.
-- The DESTINATION store of a Mutasi gets its own invoice ('mutasi').
-- Per bakery: tagihan bersih = Σ invoice − Σ nota potongan.
--
-- nota_potongan.shipment_id is the delivery the credited goods came from;
-- invoice_id is that delivery's invoice — NULL while the delivery is not
-- invoiced yet, filled in automatically when it is.
--
-- SAFETY
-- ------
--   - Additive only: two new tables and two defaulted/nullable columns on
--     invoice. No existing row changes meaning (every existing
--     invoice becomes source_kind='periode').
--   - Retry-safe: CREATE TABLE / ADD COLUMN / ADD KEY IF NOT EXISTS.
-- ============================================================================

ALTER TABLE invoice
  ADD COLUMN IF NOT EXISTS source_kind ENUM('periode','do','do_khusus','mutasi') NOT NULL DEFAULT 'periode';

ALTER TABLE invoice
  ADD COLUMN IF NOT EXISTS source_ref_id BIGINT UNSIGNED NULL;

ALTER TABLE invoice
  ADD KEY IF NOT EXISTS ix_invoice_source (source_kind, source_ref_id);

CREATE TABLE IF NOT EXISTS nota_potongan (
  nota_potongan_id   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nota_no            VARCHAR(50)     NOT NULL,
  tanggal            DATE            NOT NULL,
  store_id           BIGINT UNSIGNED NOT NULL,
  invoice_id         BIGINT UNSIGNED NULL,
  shipment_id        BIGINT UNSIGNED NULL,
  alasan             ENUM('reject_toko','mutasi_keluar') NOT NULL,
  retur_request_id   BIGINT UNSIGNED NULL,
  mutasi_request_id  BIGINT UNSIGNED NULL,
  total              DECIMAL(14,2)   NOT NULL DEFAULT 0,
  keterangan         VARCHAR(500)    NULL,
  created_by         BIGINT UNSIGNED NULL,
  created_at         DATETIME        NOT NULL,
  UNIQUE KEY uq_nota_no (nota_no),
  UNIQUE KEY uq_nota_retur (retur_request_id),
  UNIQUE KEY uq_nota_mutasi (mutasi_request_id),
  KEY ix_nota_store_tanggal (store_id, tanggal),
  KEY ix_nota_invoice (invoice_id),
  CONSTRAINT fk_nota_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_nota_invoice FOREIGN KEY (invoice_id) REFERENCES invoice(invoice_id),
  CONSTRAINT fk_nota_retur FOREIGN KEY (retur_request_id) REFERENCES retur_request(retur_request_id),
  CONSTRAINT fk_nota_mutasi FOREIGN KEY (mutasi_request_id) REFERENCES mutasi_request(mutasi_request_id),
  CONSTRAINT fk_nota_created_by FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS nota_potongan_item (
  nota_potongan_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nota_potongan_id      BIGINT UNSIGNED NOT NULL,
  product_id            BIGINT UNSIGNED NOT NULL,
  qty                   DECIMAL(12,2)   NOT NULL,
  harga                 DECIMAL(14,2)   NOT NULL,
  subtotal              DECIMAL(14,2)   NOT NULL,
  KEY ix_npi_nota (nota_potongan_id),
  CONSTRAINT fk_npi_nota FOREIGN KEY (nota_potongan_id) REFERENCES nota_potongan(nota_potongan_id) ON DELETE CASCADE,
  CONSTRAINT fk_npi_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
