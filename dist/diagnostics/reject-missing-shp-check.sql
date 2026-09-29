-- ============================================================================
-- READ-ONLY diagnostic — why a specific shipment's verified reject is (or
-- isn't) showing in Replacement Reject -> Tindak Lanjut Reject.
--
-- Every statement below is a plain SELECT. Nothing here writes, updates,
-- deletes, or locks any row. Safe to run any number of times against the
-- real production database via phpMyAdmin's SQL tab.
--
-- HOW TO USE: replace the shipment_id value (6 in the examples below,
-- for the "SHP-6" case reported live) with the real shipment_id shown on
-- the Admin Konfirmasi Toko / Driver Riwayat page (the number after
-- "SHP-"). Run each numbered block in order and read the "diagnosis"
-- column of the LAST block — it tells you in plain language exactly
-- which of the three discovery conditions this shipment's reject
-- currently fails, if any.
-- ============================================================================

SET @shipment_id = 6;  -- <-- change this to the real shipment_id (the "SHP-" number)

-- 1) The shipment itself — confirms it is real, active, and which DO/store/product it belongs to.
SELECT sh.shipment_id, sh.status AS shipment_status, sh.shipped_by, sh.shipped_at,
       sh.source_type, sh.delivery_order_id, o.doc_no,
       s.canonical_name AS store_name
FROM shipment sh
LEFT JOIN delivery_order o ON o.delivery_order_id = sh.delivery_order_id
LEFT JOIN store s ON s.store_id = sh.store_id
WHERE sh.shipment_id = @shipment_id;

-- 2) The store's own receipt confirmation for this shipment — THIS is the
--    most likely place a "missing" reject actually lives: status must be
--    exactly 'verified' (not just 'confirmed_discrepancy') for it to ever
--    reach the Replacement Reject worklist. verified_at/verified_by tell
--    you whether Admin has ever clicked "Verifikasi" for THIS shipment's
--    OWN receipt (every shipment has its own separate receipt row/action
--    — verifying one shipment's receipt does NOT verify another).
SELECT r.shipment_receipt_id, r.shipment_id, r.status AS receipt_status,
       r.receiver_name, r.confirmed_at, r.verified_by, r.verified_at
FROM shipment_receipt r
WHERE r.shipment_id = @shipment_id;

-- 3) The receipt's own line item(s) — the real reject_qty, and its
--    current disposition state ('pending' = not yet decided,
--    'reject_final'/'kirim_ulang' = already decided).
SELECT ri.shipment_receipt_item_id, ri.shipment_item_id, ri.product_id,
       ri.shipped_qty, ri.received_good_qty, ri.reject_qty, ri.shortage_qty,
       ri.approved_reject_qty, ri.disposition, ri.disposition_reason,
       ri.disposition_by, ri.disposition_at
FROM shipment_receipt_item ri
INNER JOIN shipment_receipt r ON r.shipment_receipt_id = ri.shipment_receipt_id
WHERE r.shipment_id = @shipment_id;

-- 4) How many store-uploaded evidence photos this receipt has. If this
--    shipment has reject_qty > 0 and this count is 0, Admin's
--    "Verifikasi" action is BEING REFUSED server-side with
--    EVIDENCE_REQUIRED_FOR_VERIFY (an existing, intentional rule — never
--    silently bypassed) — this is a very common, entirely non-buggy
--    reason a real-UAT reject "seems stuck": the store must re-submit at
--    least one photo (or Admin must ask them to) before it can ever be
--    verified.
SELECT COUNT(*) AS evidence_photo_count
FROM shipment_receipt_evidence e
INNER JOIN shipment_receipt r ON r.shipment_receipt_id = e.shipment_receipt_id
WHERE r.shipment_id = @shipment_id;

-- 5) Whether a Replacement Demand already exists for this receipt line
--    (would explain a "missing from pending, but should show in
--    Traceability instead" case — if this returns a row, check the
--    Replacement Reject page's SECOND table, "Traceability", not the
--    first "Tindak Lanjut Reject" table).
SELECT rd.replacement_demand_id, rd.status, rd.approved_qty, rd.created_at
FROM replacement_demand rd
INNER JOIN shipment_receipt_item ri ON ri.shipment_receipt_item_id = rd.shipment_receipt_item_id
INNER JOIN shipment_receipt r ON r.shipment_receipt_id = ri.shipment_receipt_id
WHERE r.shipment_id = @shipment_id;

-- 6) THE DIAGNOSIS — plain-language answer, combining everything above.
SELECT
  sh.shipment_id,
  ri.reject_qty,
  r.status AS receipt_status,
  ri.disposition,
  (SELECT COUNT(*) FROM shipment_receipt_evidence e WHERE e.shipment_receipt_id = r.shipment_receipt_id) AS evidence_count,
  (SELECT COUNT(*) FROM replacement_demand rd WHERE rd.shipment_receipt_item_id = ri.shipment_receipt_item_id) AS has_replacement_demand,
  CASE
    WHEN ri.reject_qty IS NULL OR ri.reject_qty <= 0.0001
      THEN 'No reject reported on this line — nothing should appear in the worklist for it (this is correct).'
    WHEN r.status <> 'verified' AND (SELECT COUNT(*) FROM shipment_receipt_evidence e WHERE e.shipment_receipt_id = r.shipment_receipt_id) = 0
      THEN 'BLOCKED: this receipt has a reject but ZERO evidence photos, so Admin cannot verify it yet (EVIDENCE_REQUIRED_FOR_VERIFY). Ask the store to re-open the receipt link and upload a photo, or confirm with them what happened — this is the existing, intentional rule, not a bug.'
    WHEN r.status <> 'verified'
      THEN 'NOT YET VERIFIED: this receipt has evidence but Admin has not clicked "Verifikasi" for THIS specific shipment''s own receipt yet (see the Konfirmasi Toko admin page). Once verified, it will appear in Tindak Lanjut Reject.'
    WHEN ri.disposition NOT IN ('pending') AND ri.disposition IS NOT NULL
      THEN CONCAT('ALREADY DECIDED: disposition=', ri.disposition, ' — check the Replacement Reject "Traceability" table instead of "Tindak Lanjut Reject".')
    ELSE 'SHOULD BE PENDING: every condition is satisfied (verified, reject>0, disposition=pending) — if it is still not showing in the UI after this, report this exact diagnostic output for further investigation.'
  END AS diagnosis
FROM shipment sh
INNER JOIN shipment_receipt r ON r.shipment_id = sh.shipment_id
INNER JOIN shipment_receipt_item ri ON ri.shipment_receipt_id = r.shipment_receipt_id
WHERE sh.shipment_id = @shipment_id;
