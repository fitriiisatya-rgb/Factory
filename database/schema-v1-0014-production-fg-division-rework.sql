-- ============================================================================
-- Migration 0014 — Production Division access wiring + FG Reject/Hilang
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- Part of the "Production Division + FG & Packing" rework. Audit of the
-- live schema (migrations 0001-0013) found that almost everything this
-- rework needs already exists:
--
--   - user_factory_access / user_division_access (many-to-many user<->
--     factory/division) were already created by the ORIGINAL 0001 schema
--     as "future scope, not necessarily enforced by v1 API" and were never
--     wired into any repository/service/controller. This migration does
--     NOT recreate them — application code (Auth/UserRepository/
--     ProductionController/FgController) is wired to read them instead.
--   - production_run.version (optimistic lock) + its UNIQUE KEY
--     (tanggal, division_id) already give a shared-division-worksheet
--     concurrency guarantee — no schema change needed there.
--   - production_item.status ENUM('sesuai','tidak_sesuai') already exists
--     — only application code needs to start writing/enforcing it.
--
-- The ONE genuine schema gap found: fg_item has no reject/lost-quantity
-- tracking at all (reject only ever existed on the Production side, on
-- production_item.reject and special_order_item.reject_produksi). FG
-- needs its own independently-tracked Reject and Hilang (physically lost
-- during FG/packing handling) columns, per this rework's explicit
-- requirement that FG reject/hilang are separate from — never merged
-- into — Production's own reject or FG's Actual.
--
-- Both new columns are purely additive (DEFAULT 0, backward compatible —
-- every existing fg_item row reads as reject=0/hilang=0, identical to
-- today's implicit behavior) and idempotent (ADD COLUMN IF NOT EXISTS,
-- same convention as migration 0013).
-- ============================================================================

ALTER TABLE fg_item
  ADD COLUMN IF NOT EXISTS reject_qty DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER packed_qty,
  ADD COLUMN IF NOT EXISTS hilang_qty DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER reject_qty;
