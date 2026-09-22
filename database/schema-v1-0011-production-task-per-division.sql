-- ============================================================================
-- Migration 0011 — Task per Divisi + Production Actual/Reject for special orders
-- ============================================================================
--
-- Purely additive. No table is dropped or renamed.
--
-- 1. production_item.reject — this column has existed since the ORIGINAL
--    0001 schema (see schema-v1.sql's own "Production (Ceklis)" section),
--    but no code path has ever written to it: ProductionRepository::
--    updateItemActual() only ever set aktual/keterangan/status, leaving
--    reject permanently at its DEFAULT 0. This migration adds NO column
--    for PO-Reguler reject — the column already exists; only the service/
--    repository write path (and the Ceklis Produksi UI) change, in the
--    application code, to finally use it. Confirmed via audit before
--    writing this migration (task's own explicit "audit first" requirement).
--
-- 2. special_order_item.aktual_produksi / reject_produksi (NEW columns) —
--    Pesanan Khusus Toko / Pesanan Non-Toko items have NO home for
--    production actual/reject at all today (production_item is keyed
--    UNIQUE per (production_run_id, product_id), so it cannot hold a
--    second, independently-tracked actual/reject for the same product
--    coming from a special order on the same day/division — task's own
--    "do NOT aggregate if... order reference differs" rule requires each
--    special_order_item to keep its OWN actual/reject, never merged into
--    a per-product total). Adding these two columns directly to the
--    EXISTING special_order_item table — never a new parallel "production
--    module" table — is the smallest safe extension: this is still the
--    one authoritative row for that order line, just carrying two more
--    read/write fields. NOT NULL DEFAULT 0, mirroring production_item's
--    own aktual/reject convention exactly, so "not yet produced" reads as
--    0 in both places consistently.
-- ============================================================================

ALTER TABLE special_order_item
  ADD COLUMN IF NOT EXISTS aktual_produksi DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER special_note,
  ADD COLUMN IF NOT EXISTS reject_produksi DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER aktual_produksi;
