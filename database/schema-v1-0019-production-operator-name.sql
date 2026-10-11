-- ============================================================================
-- Migration 0019 — Shared Division Login + Operator Name
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- Each production division now logs in with ONE shared account (used by
-- every staff member of that division), so the existing
-- production_run.created_by / submitted_by user_id columns (migration
-- 0004) can no longer tell WHO actually entered or submitted the numbers.
-- After login the system asks "Siapa Anda?" and records that free-text
-- name on every create/edit/submit, in the three columns below.
--
-- SAFETY
-- ------
--   - Purely additive: NULLable VARCHAR columns on EXISTING tables (three on
--     production_run, one on special_order_item, one on
--     replacement_demand). No existing column/row is altered, dropped,
--     narrowed, or backfilled — every historical row simply keeps NULL
--     (UI falls back to the account's full_name).
--   - Retry-safe: ADD COLUMN IF NOT EXISTS (MariaDB).
--   - production_run's columns are positioned AFTER updated_at (present
--     since migration 0001), NOT after created_by/submitted_by (added by
--     0004); the other two have no AFTER clause at all — so this file
--     stays valid regardless of any other migration's ordering.
-- ============================================================================

ALTER TABLE production_run
  ADD COLUMN IF NOT EXISTS created_by_operator_name VARCHAR(150) NULL AFTER updated_at;

ALTER TABLE production_run
  ADD COLUMN IF NOT EXISTS submitted_by_operator_name VARCHAR(150) NULL AFTER created_by_operator_name;

ALTER TABLE production_run
  ADD COLUMN IF NOT EXISTS last_updated_by_operator_name VARCHAR(150) NULL AFTER submitted_by_operator_name;

-- ----------------------------------------------------------------------------
-- The same shared division accounts also enter Actual/Reject Produksi for
-- Pesanan Khusus / Non-Toko items (POST /api/special-orders/{id}/actual)
-- and for Replacement demands (POST /api/replacement-demands/{id}/
-- production-actual). One NULLable column each records the last
-- "Siapa Anda?" name that wrote those numbers. No AFTER clause: appended
-- at the end, so it never depends on another migration's column order.
-- ----------------------------------------------------------------------------

ALTER TABLE special_order_item
  ADD COLUMN IF NOT EXISTS actual_updated_by_operator_name VARCHAR(150) NULL;

ALTER TABLE replacement_demand
  ADD COLUMN IF NOT EXISTS production_actual_updated_by_operator_name VARCHAR(150) NULL;
