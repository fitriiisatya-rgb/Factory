-- ============================================================================
-- Migration 0022 — Integration keys (finhub reads shipments + receipts)
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- finhub (FAT Workspace) turns factory deliveries into Runchise "stok masuk"
-- files and watches stock per branch. Until now it needed a manual .xlsx DO
-- upload and its own receipt confirmation. The owner decided (Oct 2026):
-- stores confirm receipt ONCE, in the factory; finhub READS the result.
--
-- integration_api_key holds the server-to-server keys finhub uses for that
-- read-only API. An Admin creates a key on the "Integrasi Finhub" page; the
-- raw key is shown once and only SHA-256(raw key) is stored, same as
-- store_portal_token. Revoke = set revoked_at; rows are never deleted.
--
-- SAFETY
-- ------
--   - Additive only: one new table. No existing table or row changes.
--   - Retry-safe: CREATE TABLE IF NOT EXISTS.
-- ============================================================================

CREATE TABLE IF NOT EXISTS integration_api_key (
  integration_api_key_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                   VARCHAR(80)     NOT NULL,
  key_prefix             CHAR(8)         NOT NULL,
  key_hash               CHAR(64)        NOT NULL,
  created_by             BIGINT UNSIGNED NOT NULL,
  created_at             DATETIME        NOT NULL,
  last_used_at           DATETIME        NULL,
  revoked_at             DATETIME        NULL,
  revoked_by             BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_integration_api_key_hash (key_hash),
  CONSTRAINT fk_iak_created_by FOREIGN KEY (created_by) REFERENCES users(user_id),
  CONSTRAINT fk_iak_revoked_by FOREIGN KEY (revoked_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
