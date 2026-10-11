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
--   - Purely additive: three NULLable VARCHAR columns on the EXISTING
--     production_run table. No existing column/row is altered, dropped,
--     narrowed, or backfilled — every historical run simply keeps NULL
--     (UI falls back to the account's full_name).
--   - Retry-safe: ADD COLUMN IF NOT EXISTS (MariaDB).
--   - Positioned AFTER updated_at (present since migration 0001), NOT
--     after created_by/submitted_by (added by 0004) — so this file stays
--     valid regardless of any other migration's ordering.
-- ============================================================================

ALTER TABLE production_run
  ADD COLUMN IF NOT EXISTS created_by_operator_name VARCHAR(150) NULL AFTER updated_at;

ALTER TABLE production_run
  ADD COLUMN IF NOT EXISTS submitted_by_operator_name VARCHAR(150) NULL AFTER created_by_operator_name;

ALTER TABLE production_run
  ADD COLUMN IF NOT EXISTS last_updated_by_operator_name VARCHAR(150) NULL AFTER submitted_by_operator_name;
