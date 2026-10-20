-- ============================================================================
-- Migration 0023 — Invoice lines for Pesanan Khusus Toko at the order price
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- Owner decision (Oct 2026): a Pesanan Khusus Toko delivery is invoiced at
-- the price agreed on the order (unit price + charge + extra packaging),
-- including custom catalog items that have no master product. Until now
-- invoice_item required a product and allowed one row per product per
-- invoice, so custom items could not be billed at all.
--
-- CHANGES (invoice_item only)
--   - product_id becomes NULL-able (a custom catalog item has no product).
--   - item_name: the line name when there is no product.
--   - special_order_item_id: the order line this invoice line bills.
--   - division_id: producing division (factory attribution in Laporan for
--     lines without a product).
--   - extra_amount: charge + extra packaging billed on this line (once per
--     order line, on its first invoice).
--   - uniqueness moves from (invoice, product) to (invoice, product, order
--     line): one order can hold the same product on two lines.
--
-- SAFETY
-- ------
--   - No existing row changes meaning: new columns are NULL / 0, and every
--     existing invoice line keeps its product.
--   - Retry-safe: ADD COLUMN / ADD KEY IF NOT EXISTS, DROP INDEX IF EXISTS.
-- ============================================================================

ALTER TABLE invoice_item MODIFY COLUMN product_id BIGINT UNSIGNED NULL;
ALTER TABLE invoice_item ADD COLUMN IF NOT EXISTS item_name VARCHAR(255) NULL AFTER product_id;
ALTER TABLE invoice_item ADD COLUMN IF NOT EXISTS special_order_item_id BIGINT UNSIGNED NULL AFTER item_name;
ALTER TABLE invoice_item ADD COLUMN IF NOT EXISTS division_id BIGINT UNSIGNED NULL AFTER special_order_item_id;
ALTER TABLE invoice_item ADD COLUMN IF NOT EXISTS extra_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER subtotal;
ALTER TABLE invoice_item ADD UNIQUE KEY IF NOT EXISTS uq_invoice_item_line (invoice_id, product_id, special_order_item_id);
ALTER TABLE invoice_item DROP INDEX IF EXISTS uq_invoice_item;
ALTER TABLE invoice_item ADD KEY IF NOT EXISTS ix_invoice_item_special (special_order_item_id);
