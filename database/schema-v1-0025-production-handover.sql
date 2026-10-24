-- ============================================================================
-- Migration 0025 — Partial handover (serah terima) from production to FG
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- Owner decision (Oct 2026): divisions start producing before the PO is
-- uploaded and hand goods to FG in several batches through the day, so FG
-- starts working on what has already arrived. Each handover is recorded
-- with its time and quantities; FG checks it (received qty + reject with a
-- reason such as "Ukuran tidak sesuai"). FG's source quantity becomes:
--   submitted run  -> its full actual (unchanged rule), and
--   draft/reopened -> what has been handed over so far,
-- minus what FG rejected when checking a handover.
--
-- CHANGES
--   - production_handover: one handover of one division's run (seq per run).
--   - production_handover_item: per product qty handed over; FG check fills
--     received_qty / reject_qty / reject_reason.
--
-- SAFETY
-- ------
--   - Two new tables only; no existing row or column changes, so every
--     past run, FG batch and report reads exactly as before.
--   - Retry-safe: CREATE TABLE IF NOT EXISTS.
-- ============================================================================

CREATE TABLE IF NOT EXISTS production_handover (
  production_handover_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  production_run_id       BIGINT UNSIGNED NOT NULL,
  seq                     INT UNSIGNED    NOT NULL,
  status                  ENUM('diserahkan','dicek') NOT NULL DEFAULT 'diserahkan',
  note                    VARCHAR(255)    NULL,
  created_by              BIGINT UNSIGNED NOT NULL,
  created_operator_name   VARCHAR(150)    NULL,
  created_at              DATETIME        NOT NULL,
  updated_at              DATETIME        NULL,
  checked_by              BIGINT UNSIGNED NULL,
  checked_operator_name   VARCHAR(150)    NULL,
  checked_at              DATETIME        NULL,
  UNIQUE KEY uq_handover_seq (production_run_id, seq),
  KEY ix_handover_status (status),
  CONSTRAINT fk_handover_run FOREIGN KEY (production_run_id) REFERENCES production_run(production_run_id),
  CONSTRAINT fk_handover_created_by FOREIGN KEY (created_by) REFERENCES users(user_id),
  CONSTRAINT fk_handover_checked_by FOREIGN KEY (checked_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS production_handover_item (
  production_handover_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  production_handover_id      BIGINT UNSIGNED NOT NULL,
  product_id                  BIGINT UNSIGNED NOT NULL,
  qty                         DECIMAL(12,2)   NOT NULL,
  received_qty                DECIMAL(12,2)   NULL,
  reject_qty                  DECIMAL(12,2)   NOT NULL DEFAULT 0,
  reject_reason               VARCHAR(255)    NULL,
  UNIQUE KEY uq_handover_item (production_handover_id, product_id),
  KEY ix_handover_item_product (product_id),
  CONSTRAINT fk_handover_item_handover FOREIGN KEY (production_handover_id) REFERENCES production_handover(production_handover_id) ON DELETE CASCADE,
  CONSTRAINT fk_handover_item_product FOREIGN KEY (product_id) REFERENCES product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
