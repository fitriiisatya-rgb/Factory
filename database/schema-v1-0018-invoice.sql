-- ============================================================================
-- Migration 0018 — Invoice generation from real DO/Shipment data
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- Live database is through migration 0017 — this is the FIRST schema
-- change since 0017 (untouched here, as with every prior migration in
-- this series). The ONLY new table is invoice_mutasi below; every
-- statement is additive/retry-safe (CREATE TABLE IF NOT EXISTS), no
-- existing table anywhere is altered, dropped, or narrowed, no existing
-- row anywhere is touched.
--
-- DATA MODEL AUDIT (done before writing this file):
--   - invoice / invoice_shipment / invoice_item / payment already exist
--     (schema-v1.sql, migration 0001) — a forward-looking Phase 1
--     placeholder, confirmed by grep to be referenced by ZERO service/
--     repository/controller anywhere in api/app/src/ before this
--     migration. This phase wires them up for real, AS-IS, with no
--     ALTER: invoice.rate_pct/invoice_item.rate_pct are NOT NULL with no
--     DEFAULT and were explicitly confirmed with the user as "not used
--     yet" — every write from this phase's own InvoiceService inserts
--     100.00 (= full price, zero adjustment) into both, documented
--     in-code as a placeholder for a future consignment/discount-rate
--     feature, never computed or surfaced anywhere in this phase's own
--     UI. invoice.batch (UNIQUE) is populated with the same string as
--     invoice_no (both already globally unique via the DocumentSequence-
--     backed numbering below, so this trivially satisfies the existing
--     constraint without inventing a second meaning for "batch").
--     invoice_shipment already models exactly what this phase needs
--     ("one row per linked physical delivery event" — confirmed by its
--     own existing comment) — one invoice legitimately spans MANY
--     shipments (one Invoice can bundle every Shipment a store received
--     in a chosen date range, confirmed with the user), and a shipment
--     already linked into one invoice_shipment row is excluded from
--     every later invoice's own eligibility query (a plain NOT IN
--     sub-select — no new column needed on shipment/shipment_receipt).
--   - invoice_item.qty_do vs qty_invoice already anticipates exactly
--     this phase's own two figures: qty_do = the gross confirmed-
--     received qty for that product in the chosen window (receivedGood,
--     which already excludes a verified Reject by construction — see
--     shipment_receipt_item.received_good_qty + reject_qty + shortage_qty
--     = shipped_qty, migration 0007), qty_invoice = that same figure net
--     of completed Mutasi movement (confirmed with the user: a completed
--     Mutasi moves its qty_received fully from the SOURCE store's
--     billable pool to the DESTINATION store's billable pool, never
--     double-billed on either side).
--   - invoice_mutasi (the one new table below) is the Mutasi-side
--     equivalent of the existing invoice_shipment junction — but a
--     single mutasi_request can legitimately appear in TWO different
--     stores' invoices (once as the SOURCE store's own deduction, once
--     as the DESTINATION store's own addition), which invoice_shipment's
--     simple (invoice_id, shipment_id) shape cannot express. The
--     direction column plus UNIQUE KEY (mutasi_request_id, direction)
--     is the schema-level guarantee that a given mutasi_request can be
--     consumed AT MOST ONCE on the 'out' side and AT MOST ONCE on the
--     'in' side, across every invoice ever generated — the same
--     "exactly-once consumption" discipline invoice_shipment already
--     gives a shipment, widened to a two-sided movement.
--   - Only a CONFIRMED receipt (shipment_receipt.status IN
--     ('confirmed_ok','verified')) and only a COMPLETED Mutasi
--     (mutasi_request.status = 'completed') are ever eligible —
--     enforced entirely in InvoiceService's own query, no new column
--     needed. A still-open discrepancy (confirmed_discrepancy / Mutasi
--     'discrepancy') is never invoiced until Admin resolves it, by
--     construction of that WHERE clause alone.
--   - Voiding a generated Invoice is a hard DELETE (cascading to
--     invoice_item/invoice_shipment/invoice_mutasi via their own existing
--     ON DELETE CASCADE / this migration's own FK), releasing its
--     shipments/mutasi back into the eligible pool for a future Invoice.
--     Safe because nothing references a generated Invoice yet (the
--     existing `payment` table is still completely unused — out of this
--     phase's own confirmed scope) — documented here so a future Payment
--     phase knows to replace this hard-delete with a real void/reversal
--     flow once a real payment could reference an invoice_id.
-- ============================================================================

-- Mutasi-side equivalent of invoice_shipment (which already exists,
-- unmodified, from migration 0001) — see the audit note above for why a
-- single mutasi_request needs its own two-sided junction instead of
-- reusing invoice_shipment's one-sided shape.
CREATE TABLE IF NOT EXISTS invoice_mutasi (
  invoice_id         BIGINT UNSIGNED NOT NULL,
  mutasi_request_id  BIGINT UNSIGNED NOT NULL,
  direction          ENUM('out','in') NOT NULL,
  qty                DECIMAL(12,2)   NOT NULL,
  PRIMARY KEY (invoice_id, mutasi_request_id, direction),
  UNIQUE KEY uq_invoice_mutasi_direction (mutasi_request_id, direction),
  CONSTRAINT fk_invoice_mutasi_invoice FOREIGN KEY (invoice_id) REFERENCES invoice(invoice_id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_mutasi_request FOREIGN KEY (mutasi_request_id) REFERENCES mutasi_request(mutasi_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
