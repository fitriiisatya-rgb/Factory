-- ============================================================================
-- Migration 0017 — Permanent Bakery Portal (token identity, Retur, Mutasi,
-- Pesanan Khusus attachments)
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- One permanent, bookmarkable, no-login link per bakery
-- (/api/_store/?token=...) covering five menus: Konfirmasi Penerimaan
-- (Reject lives inside it), Pesanan Khusus/Custom, Retur, Mutasi Produk,
-- Riwayat. Live database is through migration 0016 — this is the FIRST
-- schema change since 0016 (untouched here, as with every prior
-- migration in this series). Every statement below is additive/retry-
-- safe: CREATE TABLE IF NOT EXISTS throughout, no existing table is
-- altered, dropped, or narrowed, no existing row anywhere is touched.
--
-- DATA MODEL AUDIT (done before writing this file):
--   - delivery_receipt_token / shipment_receipt_token (migration 0007)
--     already prove out "stable, unguessable, non-sequential, PUBLIC
--     token -> resolve to ID, never ID -> client-supplied" for a
--     PER-SHIPMENT/PER-DO token. store_portal_token below is the same
--     discipline widened to a PERMANENT, PER-STORE identity — but it
--     stores a SHA-256 HASH of the token (token_hash CHAR(64)), never
--     the raw value, going one step further than the 0007 precedent
--     (which stores the raw token directly) because this token is
--     long-lived and bookmarked, so a DB read alone must never be enough
--     to recover a live credential. Regenerate = revoke the existing
--     active row (revoked_at) + insert a new one; rows are NEVER
--     deleted, so a store's full token history stays auditable.
--     "Exactly one ACTIVE token per store" is enforced as a schema-level
--     guarantee via a generated/stored column, the EXACT same idiom
--     delivery_order.open_key (schema-v1.sql) already established for
--     "at most one open X" — a revoked row's generated column is NULL
--     and drops out of the unique constraint entirely (MySQL/MariaDB
--     NULLs are never considered equal in a UNIQUE index), so history
--     accumulates freely while only ever one row per store can be
--     non-NULL at a time.
--   - shipment_receipt (migration 0007) already proves the house answer
--     to "how does an unauthenticated store actor get recorded without a
--     users row": NO NOT-NULL created_by/user FK at all for the store's
--     own confirm action — only a free-text receiver_name, with
--     verified_by (nullable FK to users) set ONLY once a real logged-in
--     Admin acts. retur_request and mutasi_request below follow this
--     exact precedent: the store's own submission carries no users FK at
--     all (identity is the store_id itself, which is impossible to spoof
--     because it is always derived server-side from the resolved
--     token — see StorePortalService — never from client input); only
--     the nullable *_via_token_id columns (FK to store_portal_token,
--     never deleted, only revoked) and the Admin-side verified_by/
--     admin_reviewed_by columns carry a real actor. This also means
--     Retur/Mutasi submission needs no "fake system user" anywhere.
--   - SpecialOrderService::createOrder(array $input, int $userId, ...)
--     is the ONE exception: that signature is untouched (task's own "do
--     not duplicate existing modules" — reuse this engine verbatim) and
--     special_order.created_by is NOT NULL today. Rather than loosen a
--     live NOT NULL column or widen a well-tested service signature just
--     for this one caller, the Portal's Pesanan Khusus wrapper passes the
--     user_id of one lazily-seeded, role-less, inactive system user
--     (found-or-created in application code, same "find-or-create guarded
--     by a UNIQUE key" pattern migration 0010's own
--     findOrCreateCakeCustomDivision() already established — so no seed
--     row is baked into this DDL-only migration either). This is not a
--     loss of audit fidelity: the REAL actor for a 'toko_khusus' order is
--     already, and always was, special_order.store_id — created_by on a
--     system-originated row is exactly as meaningful as it would be for
--     any other automated insert.
--   - special_order_item (migration 0010) attaches per LINE; the task's
--     "photo/reference attachments (optional, multiple)" for Pesanan
--     Khusus are reference images for the whole submitted order, not
--     per-product evidence, so special_order_attachment below FKs to the
--     special_order HEADER (ON DELETE CASCADE, same cascade discipline as
--     special_order_item itself), never to special_order_item.
--   - shipment_receipt_evidence (migration 0008) is the established,
--     non-polymorphic "one row per uploaded photo" shape (this codebase
--     deliberately avoids a shared entity_type/entity_id evidence table —
--     see that migration's own docblock). retur_request_evidence is a
--     direct structural copy of it. mutasi_request_evidence copies the
--     same shape but adds one discriminator column, `stage`
--     ('request'|'confirmation'), because Mutasi's evidence rule differs
--     by moment: mandatory (>=1) when the SOURCE store submits the
--     request, optional-but-required-if-discrepancy when the
--     DESTINATION store confirms — two distinct evidence sets against
--     the same mutasi_request row, not two different parent tables.
--     "One reusable evidence/attachment MECHANISM" (task's own wording)
--     is satisfied at the CODE level — EvidenceUploader gains an
--     additive, optional $context parameter so retur-evidence/mutasi-
--     evidence/special-order-attachment reuse the identical MIME-
--     sniffing/size/filename-randomization logic — not by inventing a
--     shared polymorphic DB table, consistent with the house convention.
--   - DocumentSequenceService::allocate() + document_sequence
--     (document_type VARCHAR(30)) already carries 'DO', 'NPR',
--     'REPLACEMENT_DO' with zero collision risk. ReplacementDoRepository
--     ::allocateDoNumber()'s own format, confirmed by reading that method
--     directly, is `sprintf('REPL-%s-%03d', Ymd, seq)` — RETUR/MUTASI doc
--     numbers below follow the exact same human-readable
--     "{PREFIX}-{Ymd}-{seq:03d}" house style ('RETUR'/'MUTASI' document
--     types, new keys, no collision with the three existing ones).
--   - MUTATION CORE RULE / RETUR STOCK RULE (task's own LOCKED rules):
--     neither feature ever writes stock_ledger or creates a shipment —
--     stock_ledger.source_type and shipment.source_type are therefore
--     NOT widened by this migration at all (unlike every feature-adding
--     migration before it, 0006/0012/0013/0016, which all needed to).
--     Admin cannot fabricate store evidence either (see
--     ReceiptController::adminEvidence()'s own docblock on why that
--     endpoint was REMOVED, not hidden, for the identical reason) — so
--     there is deliberately no "admin submits on behalf of store" path
--     anywhere in this schema.
--
-- RETRY-SAFETY: every CREATE TABLE is IF NOT EXISTS; there are no ALTERs
-- against any pre-existing table in this migration at all (the first
-- migration in this series for which that is true), so there is no ENUM-
-- widening or FK-retry idiom needed here.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. store_portal_token — one permanent, bookmarkable, no-login identity per
--    store. token_hash is SHA-256(raw token), never the raw value. Exactly
--    one ACTIVE (revoked_at IS NULL) row per store_id, enforced by the
--    generated/stored active_store_key column + UNIQUE KEY (same idiom as
--    delivery_order.open_key) — regenerate = revoke the old row + insert a
--    new one; rows are never deleted, so history is always auditable.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS store_portal_token (
  store_portal_token_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id               BIGINT UNSIGNED NOT NULL,
  token_hash             CHAR(64)        NOT NULL,
  created_by             BIGINT UNSIGNED NOT NULL,
  created_at             DATETIME        NOT NULL,
  last_used_at           DATETIME        NULL,
  revoked_at             DATETIME        NULL,
  revoked_by             BIGINT UNSIGNED NULL,
  active_store_key VARCHAR(20) GENERATED ALWAYS AS (
      CASE WHEN revoked_at IS NULL THEN CAST(store_id AS CHAR) ELSE NULL END
  ) STORED,
  UNIQUE KEY uq_store_portal_token_hash (token_hash),
  UNIQUE KEY uq_store_portal_token_active (active_store_key),
  KEY ix_store_portal_token_store (store_id),
  CONSTRAINT fk_spt_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_spt_created_by FOREIGN KEY (created_by) REFERENCES users(user_id),
  CONSTRAINT fk_spt_revoked_by FOREIGN KEY (revoked_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 2. retur_request — "barang sudah diterima BAIK, belakangan tidak terjual",
--    reported later by the store. NOT reject/shortage/production-reject
--    (those stay inside Konfirmasi Penerimaan / Replacement Reject,
--    untouched). RETUR FINANCIAL RULE (LOCKED): 100% store burden, never
--    reduces any invoice, no credit note — enforced by this table simply
--    having NO link whatsoever to invoice/billing. RETUR STOCK RULE
--    (LOCKED): operational record only — this table is never read by
--    FgService/ShipmentService/stock_ledger, so Factory FG stock cannot be
--    auto-increased by it even by omission.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS retur_request (
  retur_request_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no                 VARCHAR(64)     NOT NULL,
  store_id                BIGINT UNSIGNED NOT NULL,
  product_id              BIGINT UNSIGNED NOT NULL,
  qty                     DECIMAL(12,2)  NOT NULL,
  reason                  VARCHAR(500)   NOT NULL,
  notes                   VARCHAR(1000)  NULL,
  retur_date              DATE           NOT NULL,
  status ENUM('waiting_admin_verification','verified','rejected') NOT NULL DEFAULT 'waiting_admin_verification',
  reject_reason           VARCHAR(500)   NULL,
  verified_by             BIGINT UNSIGNED NULL,
  verified_at             DATETIME       NULL,
  submitted_via_token_id  BIGINT UNSIGNED NULL,
  version                 INT UNSIGNED   NOT NULL DEFAULT 1,
  created_at              DATETIME       NOT NULL,
  updated_at               DATETIME      NULL,
  UNIQUE KEY uq_retur_request_doc_no (doc_no),
  KEY ix_retur_request_store (store_id),
  KEY ix_retur_request_status (status),
  KEY ix_retur_request_product (product_id),
  CONSTRAINT fk_retur_store FOREIGN KEY (store_id) REFERENCES store(store_id),
  CONSTRAINT fk_retur_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_retur_verified_by FOREIGN KEY (verified_by) REFERENCES users(user_id),
  CONSTRAINT fk_retur_token FOREIGN KEY (submitted_via_token_id) REFERENCES store_portal_token(store_portal_token_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 3. retur_request_evidence — direct structural copy of
--    shipment_receipt_evidence (migration 0008): one row per uploaded
--    photo, server-generated file_path, never the uploader's original
--    filename used to build a path. Photo is MANDATORY (>=1) at
--    submission — enforced in the service layer (ReturService), same
--    "app-layer invariant over DB CHECK constraint" convention as every
--    other evidence-required rule in this project.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS retur_request_evidence (
  retur_request_evidence_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  retur_request_id           BIGINT UNSIGNED NOT NULL,
  file_path                   VARCHAR(255)   NOT NULL,
  mime_type                   VARCHAR(100)   NOT NULL,
  file_size                   INT UNSIGNED   NOT NULL,
  original_name               VARCHAR(255)   NULL,
  uploaded_at                  DATETIME       NOT NULL,
  KEY ix_retur_evidence_request (retur_request_id),
  CONSTRAINT fk_retur_evidence_request FOREIGN KEY (retur_request_id)
    REFERENCES retur_request(retur_request_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 4. mutasi_request — store-to-store responsibility transfer. MUTATION CORE
--    RULE (LOCKED): never changes Factory stock, only transfers which store
--    is responsible for the product (enforced by this table having no
--    stock_ledger write path anywhere in its lifecycle — ReturService/
--    MutasiService never call StockLedger, only ShipmentService::ship()
--    and FgService::submit() may). MUTATION INVOICE RULE (LOCKED): source's
--    responsibility only decreases, destination's only increases, once
--    COMPLETED (dual confirmation) — never before — and qty_received (not
--    qty_requested) is the authoritative figure a future Invoice phase
--    must read; no invoice engine is built here, this table only PERSISTS
--    the data for it. MUTATION IMMUTABILITY (LOCKED): once both sides have
--    acted, no hard edit — a correction is a brand-new row with
--    reversal_of_mutasi_request_id pointing back, enforced in the service
--    layer (no UPDATE path for status IN ('completed','discrepancy') other
--    than the Admin Review columns).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mutasi_request (
  mutasi_request_id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no                          VARCHAR(64)     NOT NULL,
  source_store_id                  BIGINT UNSIGNED NOT NULL,
  destination_store_id              BIGINT UNSIGNED NOT NULL,
  product_id                        BIGINT UNSIGNED NOT NULL,
  qty_requested                      DECIMAL(12,2)  NOT NULL,
  request_notes                      VARCHAR(1000)  NULL,
  qty_received                        DECIMAL(12,2) NULL,
  destination_notes                   VARCHAR(1000) NULL,
  status ENUM('waiting_destination_confirmation','completed','discrepancy','cancelled') NOT NULL DEFAULT 'waiting_destination_confirmation',
  reversal_of_mutasi_request_id        BIGINT UNSIGNED NULL,
  admin_review_notes                    VARCHAR(500) NULL,
  admin_reviewed_by                      BIGINT UNSIGNED NULL,
  admin_reviewed_at                      DATETIME NULL,
  requested_via_token_id                  BIGINT UNSIGNED NULL,
  confirmed_via_token_id                   BIGINT UNSIGNED NULL,
  confirmed_at                              DATETIME NULL,
  version                                   INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                                 DATETIME NOT NULL,
  updated_at                                  DATETIME NULL,
  UNIQUE KEY uq_mutasi_request_doc_no (doc_no),
  KEY ix_mutasi_request_source (source_store_id),
  KEY ix_mutasi_request_destination (destination_store_id),
  KEY ix_mutasi_request_status (status),
  KEY ix_mutasi_request_product (product_id),
  KEY ix_mutasi_request_reversal (reversal_of_mutasi_request_id),
  CONSTRAINT fk_mutasi_source_store FOREIGN KEY (source_store_id) REFERENCES store(store_id),
  CONSTRAINT fk_mutasi_destination_store FOREIGN KEY (destination_store_id) REFERENCES store(store_id),
  CONSTRAINT fk_mutasi_product FOREIGN KEY (product_id) REFERENCES product(product_id),
  CONSTRAINT fk_mutasi_reversal_of FOREIGN KEY (reversal_of_mutasi_request_id) REFERENCES mutasi_request(mutasi_request_id),
  CONSTRAINT fk_mutasi_admin_reviewed_by FOREIGN KEY (admin_reviewed_by) REFERENCES users(user_id),
  CONSTRAINT fk_mutasi_requested_via_token FOREIGN KEY (requested_via_token_id) REFERENCES store_portal_token(store_portal_token_id),
  CONSTRAINT fk_mutasi_confirmed_via_token FOREIGN KEY (confirmed_via_token_id) REFERENCES store_portal_token(store_portal_token_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 5. mutasi_request_evidence — same shape as retur_request_evidence, plus
--    one discriminator column (`stage`) because Mutasi's evidence rule
--    differs by moment against the SAME parent row: mandatory (>=1) when
--    the source store submits ('request'), required-only-if-discrepancy
--    when the destination store confirms ('confirmation') — enforced in
--    MutasiService, not here.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mutasi_request_evidence (
  mutasi_request_evidence_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mutasi_request_id           BIGINT UNSIGNED NOT NULL,
  stage                        ENUM('request','confirmation') NOT NULL,
  file_path                    VARCHAR(255)   NOT NULL,
  mime_type                    VARCHAR(100)   NOT NULL,
  file_size                    INT UNSIGNED   NOT NULL,
  original_name                VARCHAR(255)   NULL,
  uploaded_at                   DATETIME       NOT NULL,
  KEY ix_mutasi_evidence_request (mutasi_request_id),
  KEY ix_mutasi_evidence_stage (stage),
  CONSTRAINT fk_mutasi_evidence_request FOREIGN KEY (mutasi_request_id)
    REFERENCES mutasi_request(mutasi_request_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 6. special_order_attachment — optional, multiple reference photos for a
--    Pesanan Khusus/Custom submission, attached to the ORDER HEADER (not
--    per line item — these are reference images for the whole request),
--    same ON DELETE CASCADE discipline as special_order_item.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS special_order_attachment (
  special_order_attachment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  special_order_id              BIGINT UNSIGNED NOT NULL,
  file_path                      VARCHAR(255)   NOT NULL,
  mime_type                      VARCHAR(100)   NOT NULL,
  file_size                      INT UNSIGNED   NOT NULL,
  original_name                  VARCHAR(255)   NULL,
  uploaded_at                     DATETIME       NOT NULL,
  KEY ix_special_order_attachment_order (special_order_id),
  CONSTRAINT fk_soa_order FOREIGN KEY (special_order_id)
    REFERENCES special_order(special_order_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
