-- ============================================================================
-- Migration 0026 — Store reports: Retur / Reject / Mutasi in one transaction,
-- Head Store (Atasan) approval, and Admin physical validation
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- Owner decision (Oct 2026): the stores' Google Apps Script form
-- ("Input Retur, Reject & Mutasi") moves into Factory. That form lets a
-- store submit many products in ONE transaction (mixed Retur / Reject /
-- Mutasi), a Head Store (Atasan) approves it, then Admin checks the
-- goods physically (Sesuai / Tidak Sesuai + catatan). Reject reasons come
-- from a fixed list the office maintains.
--
-- CHANGES
--   - store_report: the transaction header (one store, one date, status
--     menunggu_atasan -> disetujui). Items stay what they already are:
--     retur_request rows (retur / reject_toko) and mutasi_request rows,
--     linked by the new store_report_id column, so every existing review,
--     invoice and report path keeps working on them unchanged.
--   - retur_request / mutasi_request: store_report_id + validasi_* columns.
--   - reject_reason: the master list of reject reasons (seeded with the 30
--     reasons the stores already use).
--   - user_store_access: which stores an Atasan account supervises.
--   - roles: new ATASAN role.
--
-- SAFETY
-- ------
--   - Additive only (CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS,
--     INSERT IGNORE). Existing rows get store_report_id = NULL, which every
--     query treats as "already approved", so old data reads as before.
-- ============================================================================

INSERT IGNORE INTO roles (code, name) VALUES ('ATASAN', 'Atasan (Head Store)');

CREATE TABLE IF NOT EXISTS user_store_access (
  user_id   BIGINT UNSIGNED NOT NULL,
  store_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, store_id),
  KEY ix_user_store_access_store (store_id),
  CONSTRAINT fk_usa_user FOREIGN KEY (user_id) REFERENCES users(user_id),
  CONSTRAINT fk_usa_store FOREIGN KEY (store_id) REFERENCES store(store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reject_reason (
  reject_reason_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(150)    NOT NULL,
  sort_order        INT             NOT NULL DEFAULT 0,
  active            TINYINT(1)      NOT NULL DEFAULT 1,
  created_at        DATETIME        NOT NULL,
  updated_at        DATETIME        NULL,
  UNIQUE KEY uq_reject_reason_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO reject_reason (name, sort_order, created_at) VALUES
  ('Exp H-1 Berjamur', 10, UTC_TIMESTAMP()), ('Exp H-2 Berjamur', 20, UTC_TIMESTAMP()),
  ('Bau', 30, UTC_TIMESTAMP()), ('Ukuran Tidak Sesuai Standar', 40, UTC_TIMESTAMP()),
  ('Somplak', 50, UTC_TIMESTAMP()), ('Basah', 60, UTC_TIMESTAMP()),
  ('Patah', 70, UTC_TIMESTAMP()), ('Ada Serpihan Benda Asing', 80, UTC_TIMESTAMP()),
  ('Gosong', 90, UTC_TIMESTAMP()), ('Adonan Nyampur', 100, UTC_TIMESTAMP()),
  ('Bentuk Tidak Sesuai Standar', 110, UTC_TIMESTAMP()), ('Tampilan Kurang Menarik', 120, UTC_TIMESTAMP()),
  ('Bantet', 130, UTC_TIMESTAMP()), ('Belah', 140, UTC_TIMESTAMP()),
  ('Ada Rambut', 150, UTC_TIMESTAMP()), ('Nempel Ke Dus', 160, UTC_TIMESTAMP()),
  ('Pahit', 170, UTC_TIMESTAMP()), ('Ada Plastik', 180, UTC_TIMESTAMP()),
  ('Ada Semut', 190, UTC_TIMESTAMP()), ('Retak', 200, UTC_TIMESTAMP()),
  ('Tanpa Topping', 210, UTC_TIMESTAMP()), ('Miring', 220, UTC_TIMESTAMP()),
  ('Kemasan Bolong', 230, UTC_TIMESTAMP()), ('Tipis', 240, UTC_TIMESTAMP()),
  ('Geser', 250, UTC_TIMESTAMP()), ('Topping Berantakan', 260, UTC_TIMESTAMP()),
  ('Ada Cicak', 270, UTC_TIMESTAMP()), ('Meleleh', 280, UTC_TIMESTAMP()),
  ('Adonan Tidak Rata', 290, UTC_TIMESTAMP()), ('Topping Sedikit', 300, UTC_TIMESTAMP());

CREATE TABLE IF NOT EXISTS store_report (
  store_report_id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no                  VARCHAR(64)     NOT NULL,
  store_id                BIGINT UNSIGNED NOT NULL,
  report_date             DATE            NOT NULL,
  status                  ENUM('menunggu_atasan','disetujui') NOT NULL DEFAULT 'menunggu_atasan',
  approval_mode           ENUM('atasan','otomatis') NULL,
  approved_by             BIGINT UNSIGNED NULL,
  approved_at             DATETIME        NULL,
  submitted_via_token_id  BIGINT UNSIGNED NULL,
  version                 INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at              DATETIME        NOT NULL,
  updated_at              DATETIME        NULL,
  UNIQUE KEY uq_store_report_doc_no (doc_no),
  KEY ix_store_report_store_date (store_id, report_date),
  KEY ix_store_report_status (status),
  CONSTRAINT fk_store_report_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_store_report_approved_by FOREIGN KEY (approved_by) REFERENCES users(user_id),
  CONSTRAINT fk_store_report_token FOREIGN KEY (submitted_via_token_id) REFERENCES store_portal_token(store_portal_token_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE retur_request
  ADD COLUMN IF NOT EXISTS store_report_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS validasi_status ENUM('menunggu','sesuai','tidak_sesuai') NULL,
  ADD COLUMN IF NOT EXISTS validasi_note VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS validasi_by BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS validasi_at DATETIME NULL;

ALTER TABLE retur_request
  ADD KEY IF NOT EXISTS ix_retur_request_report (store_report_id);

ALTER TABLE mutasi_request
  ADD COLUMN IF NOT EXISTS store_report_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS validasi_status ENUM('menunggu','sesuai','tidak_sesuai') NULL,
  ADD COLUMN IF NOT EXISTS validasi_note VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS validasi_by BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS validasi_at DATETIME NULL;

ALTER TABLE mutasi_request
  ADD KEY IF NOT EXISTS ix_mutasi_request_report (store_report_id);
