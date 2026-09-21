-- ============================================================================
-- Migration 0008 — Store Receipt Photo Evidence
-- ============================================================================
--
-- Purely additive. No Phase 1-5.5 table is altered, renamed, or dropped.
--
-- Real-UAT ask: when a store confirms a shipment with Reject > 0 or
-- Kurang (shortage) > 0, at least one photo of the discrepancy must exist
-- before Admin can mark the receipt "Diverifikasi Admin". shipment_receipt
-- (migration 0007) already has everything needed for the confirmation
-- itself (status/receiver_name/note/verified_by/verified_at) — it has NO
-- column for evidence, and cramming a comma-separated file list into one
-- column was explicitly rejected in favor of one row per photo.
--
-- shipment_receipt_evidence — one row per uploaded photo, FK'd to the
-- shipment_receipt it belongs to (never to shipment directly — a shipment
-- with no receipt yet can have no evidence yet either, same "no draft/
-- placeholder row" discipline already used by shipment_receipt itself).
-- Deleting the parent receipt (never done by any code path, but as a
-- schema-level guarantee) cascades to its evidence rows.
--
-- file_path is a path RELATIVE to the receipt-evidence upload root
-- (api/uploads/receipt-evidence/), never an absolute filesystem path and
-- never web-reachable directly — that directory is deny-all just like
-- api/app/; every read goes through an authenticated admin controller
-- that streams the bytes, so the real filesystem path is never exposed
-- to a client. file_path holds a SERVER-GENERATED random filename, never
-- the uploader-supplied original filename (original_name is kept
-- separately, display-only, never used to build a path).
-- ============================================================================

CREATE TABLE shipment_receipt_evidence (
  shipment_receipt_evidence_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_receipt_id          BIGINT UNSIGNED NOT NULL,
  file_path                    VARCHAR(255)    NOT NULL,
  mime_type                    VARCHAR(100)    NOT NULL,
  file_size                    INT UNSIGNED    NOT NULL,
  original_name                VARCHAR(255)    NULL,
  uploaded_at                  DATETIME        NOT NULL,
  KEY ix_receipt_evidence_receipt (shipment_receipt_id),
  CONSTRAINT fk_receipt_evidence_receipt FOREIGN KEY (shipment_receipt_id)
    REFERENCES shipment_receipt(shipment_receipt_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
