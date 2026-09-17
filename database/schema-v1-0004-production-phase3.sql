-- ============================================================================
-- Migration 0004: Production/SPK actual Phase 3 (fast-track)
-- ============================================================================
-- Additive only — reuses the production_run/production_item tables already
-- created by 0001 (the original 45-table design already anticipated this
-- module: production_run keyed on (tanggal, division_id) with the full
-- not_started/draft/submitted/reopened/verified_fg lifecycle enum, version,
-- submitted_at, reopen_reason; production_item keyed on
-- (production_run_id, product_id) with target/aktual/status/keterangan).
-- No Phase 0/1/2 table is recreated, dropped, or has a column removed, and
-- no PO/master-identity data is touched by this file.
--
-- production_item needs NO schema change at all: its existing `target`
-- column already serves as the "SPK snapshot" the task asks for (the target
-- value the operator saw at draft-creation/last-refresh time), while the
-- LIVE current PO target is never stored — it is always recomputed on read
-- from po_item (po_awal+po_revisi, PB excluded) by
-- Amor\Api\Production\ProductionTargetService. Comparing the two at read
-- time is what drives the "Target berubah sejak draft dibuat" warning,
-- without any parallel truth table.
--
-- production_run gains only the columns needed to run this lifecycle with
-- real user attribution and PO-drift detection — the existing closed_at/
-- closed_by pair (a leftover VARCHAR "closed by" from the original 45-table
-- draft, never used by any shipped code — grep confirms production_run/
-- production_item have zero references outside table-count tests before
-- this migration) is left untouched and unused by Phase 3; only proper
-- BIGINT UNSIGNED FK columns to users are added for the actions this phase
-- actually performs (create/submit/reopen).
--
-- Same idempotency discipline as 0002/0003: ADD COLUMN/ADD KEY use
-- IF NOT EXISTS (confirmed supported on MariaDB 10.11.x). ADD CONSTRAINT
-- ... FOREIGN KEY does not support IF NOT EXISTS on MariaDB, so those are
-- listed last and rely on the migration runner's schema_migrations
-- bookkeeping to never re-apply this file a second time.

ALTER TABLE production_run
  ADD COLUMN IF NOT EXISTS created_by BIGINT UNSIGNED NULL AFTER division_id;

ALTER TABLE production_run
  ADD COLUMN IF NOT EXISTS submitted_by BIGINT UNSIGNED NULL AFTER submitted_at;

ALTER TABLE production_run
  ADD COLUMN IF NOT EXISTS reopened_by BIGINT UNSIGNED NULL AFTER reopen_reason;

ALTER TABLE production_run
  ADD COLUMN IF NOT EXISTS reopened_at DATETIME NULL AFTER reopened_by;

-- po_batch.version captured at draft-creation / last explicit target refresh
-- — the baseline this run's operator last saw, so the service can tell
-- "PO was revised since this draft was created/refreshed" without re-diffing
-- every product's numbers. NULL until the first draft item is written.
ALTER TABLE production_run
  ADD COLUMN IF NOT EXISTS source_po_batch_version INT UNSIGNED NULL AFTER version;

ALTER TABLE production_run
  ADD KEY IF NOT EXISTS ix_production_run_status (status);

ALTER TABLE production_run
  ADD KEY IF NOT EXISTS ix_production_run_created_by (created_by);

ALTER TABLE production_run
  ADD KEY IF NOT EXISTS ix_production_run_submitted_by (submitted_by);

ALTER TABLE production_run
  ADD KEY IF NOT EXISTS ix_production_run_reopened_by (reopened_by);

ALTER TABLE production_run
  ADD CONSTRAINT fk_production_run_created_by FOREIGN KEY (created_by) REFERENCES users(user_id);

ALTER TABLE production_run
  ADD CONSTRAINT fk_production_run_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(user_id);

ALTER TABLE production_run
  ADD CONSTRAINT fk_production_run_reopened_by FOREIGN KEY (reopened_by) REFERENCES users(user_id);
