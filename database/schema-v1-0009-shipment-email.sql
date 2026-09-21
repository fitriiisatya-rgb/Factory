-- ============================================================================
-- Migration 0009 — Automatic Bakery Email / Digital Surat Jalan / Admin Resend
-- ============================================================================
--
-- Purely additive. No Phase 1-5.5.1/migration 0001-0008 table is altered
-- (beyond the one additive column below), renamed, or dropped.
--
-- Night-delivery reality: Admin works office hours, FG/departure often
-- finish at night, the bakery may be closed when goods arrive, and nobody
-- may be there to sign. Every bakery already has an email account, so the
-- final leg of Phase 5.5 is: the moment a REAL shipment departs, the
-- owning store's registered email gets a message linking straight to the
-- existing token-gated Digital Surat Jalan / Store Receipt page for THAT
-- shipment (never the whole DO's planned quantities).
--
-- 1. store.email — the one field this whole feature needs on `store`
--    that didn't already exist. Nullable (existing stores have none until
--    Admin fills it in from Master Data) and never blocks anything: a
--    missing email means "no automatic send possible for this store yet",
--    never "cannot depart" (see shipment_email_delivery.status='no_email'
--    below) and never "cannot receive goods" (the store can still be
--    handed a physical Surat Jalan, or Admin can share the link some
--    other way, or fill the email in and click Kirim Ulang Email later).
--
-- 2. shipment_email_delivery — ONE row per real shipment (UNIQUE on
--    shipment_id: "each actual shipment gets its own notification", and a
--    resend REUSES this same row, never creates a second one for the same
--    shipment). Represents CURRENT delivery status; attempt_count/
--    last_attempt_at/last_error are overwritten on every attempt
--    (including a later Admin resend), while the full history of who
--    triggered which attempt with which recipient is kept in the EXISTING
--    audit_log table (Audit::write(), action='shipment.email.sent' /
--    'shipment.email.failed') rather than a second bespoke history table
--    — this project's own established convention (see
--    ReceiptRepository::findVerifierName()'s docblock for the same
--    "plain join / existing audit_log, never a duplicated trail" choice).
--
--    recipient_email is snapshotted PER ATTEMPT into audit_log's own
--    payload_summary, but this row's own recipient_email always reflects
--    the email actually used on the MOST RECENT attempt (so if Admin
--    fixes store.email and clicks Kirim Ulang Email, this row updates to
--    the corrected address while the earlier failed attempt's original
--    recipient stays visible in audit_log history).
--
--    status:
--      pending   — outbox row created, no send attempt has completed yet
--                  (the normal in-flight state for the split second
--                  between commit and the post-commit send attempt; see
--                  Mail\ShipmentEmailService's own docblock)
--      sent      — last attempt succeeded
--      failed    — last attempt was tried and failed (SMTP error, or the
--                  mail system itself is not configured — MAIL_ENABLED=false)
--      no_email  — store.email was empty at the time of this row's most
--                  recent (re)check; no network attempt was made at all
--
--    Never stores the SMTP password or the rendered email body — only
--    what's needed to show Admin a friendly status and let them resend.
-- ============================================================================

ALTER TABLE store
  ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL AFTER canonical_name;

CREATE TABLE IF NOT EXISTS shipment_email_delivery (
  shipment_email_delivery_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id       BIGINT UNSIGNED NOT NULL,
  store_id          BIGINT UNSIGNED NOT NULL,
  recipient_email   VARCHAR(255)    NULL,
  subject           VARCHAR(255)    NOT NULL,
  status            ENUM('pending','sent','failed','no_email') NOT NULL DEFAULT 'pending',
  attempt_count     INT UNSIGNED    NOT NULL DEFAULT 0,
  first_attempt_at  DATETIME        NULL,
  last_attempt_at   DATETIME        NULL,
  sent_at           DATETIME        NULL,
  last_error        VARCHAR(500)    NULL,
  triggered_by      BIGINT UNSIGNED NULL,
  created_at        DATETIME        NOT NULL,
  updated_at        DATETIME        NULL,
  UNIQUE KEY uq_email_delivery_shipment (shipment_id),
  KEY ix_email_delivery_status (status),
  CONSTRAINT fk_email_delivery_shipment FOREIGN KEY (shipment_id) REFERENCES shipment(shipment_id),
  CONSTRAINT fk_email_delivery_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_email_delivery_triggered_by FOREIGN KEY (triggered_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
