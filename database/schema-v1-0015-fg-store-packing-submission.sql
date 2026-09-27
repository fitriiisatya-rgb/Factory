-- ============================================================================
-- Migration 0015 — Real persisted Packing submission state per (FG batch,
-- store)
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- The prior "LIVE UAT HOTFIX — clear packing submit status" pass inferred
-- "Sudah Disubmit" from packed_qty >= a store's own PO target — a source
-- deep-check correctly found this logically wrong: a store's own Packing
-- can be legitimately, completely submitted with packed_qty BELOW target
-- (operator selects Tidak Sesuai with a valid discrepancy note) or even
-- packed_qty = 0 (submitted with a note explaining nothing was ready yet).
-- Quantities alone can never distinguish "never submitted" from
-- "submitted with a low/zero number" — there is no way to derive that
-- distinction from fg_item's own columns, however they are combined.
--
-- Deep-audit of the existing schema (fg_batch, fg_item, store_fg_balance)
-- found NO existing table or column already carrying this truth:
--   - fg_batch.submitted_at/submitted_by is the WHOLE DOCUMENT's own
--     final-submit event (Fg\FgService::submit(), which also posts
--     stock) — a completely different, coarser-grained event than "this
--     ONE store's Packing was submitted" (Packing per Toko submits one
--     store at a time, well before the whole document's own final
--     submit).
--   - fg_item itself has no per-row "was this submitted" flag, only
--     quantity/status columns — reusing status (VARCHAR, currently
--     'belum_dicek'/'dicek') for this would conflate two unrelated
--     concepts (FG Verifikasi's own check-state vs Packing's own
--     submission event) and would need one flag PER PRODUCT ROW even
--     though submission is genuinely scoped to the STORE as a whole
--     (task's own explicit "authoritative event/state: Packing for
--     Store X has been submitted", never duplicated per product).
--
-- This migration adds exactly ONE new, purely additive table —
-- fg_store_packing_submission — carrying ONLY that event/state, never a
-- duplicate of any product quantity (those remain fg_item's own, sole,
-- canonical columns, completely untouched by this migration).
--
-- status is 'submitted' immediately after a successful "Submit Packing
-- [Store]" call; a LATER edit that actually changes that store's own
-- fg_item data (via any write path — Breakdown Toko's own save
-- included) flips it to 'stale' rather than deleting the row, so
-- submitted_at/submitted_by remain visible as "last known submission"
-- history even while the operator is asked to resubmit
-- (Fg\FgService::submitStorePacking()/invalidatePackingSubmission()).
--
-- Idempotent (CREATE TABLE IF NOT EXISTS, same convention as every prior
-- migration) and fully additive/data-preserving — no existing table is
-- altered, no existing row anywhere is touched.
-- ============================================================================

CREATE TABLE IF NOT EXISTS fg_store_packing_submission (
  fg_store_packing_submission_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fg_batch_id     BIGINT UNSIGNED NOT NULL,
  store_id        BIGINT UNSIGNED NOT NULL,
  status          ENUM('submitted','stale') NOT NULL DEFAULT 'submitted',
  submitted_at    DATETIME NOT NULL,
  submitted_by    BIGINT UNSIGNED NOT NULL,
  invalidated_at  DATETIME NULL,
  updated_at      DATETIME NOT NULL,
  UNIQUE KEY uq_fg_store_packing_submission (fg_batch_id, store_id),
  KEY ix_fg_store_packing_submission_store (store_id),
  CONSTRAINT fk_fspks_batch FOREIGN KEY (fg_batch_id) REFERENCES fg_batch(fg_batch_id) ON DELETE CASCADE,
  CONSTRAINT fk_fspks_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_fspks_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
