# Draft/Preprint DO + Staged Shipment — Phase 5 Design Reservation

**Status: PLANNING DOCUMENT ONLY.** No schema, migration, or application
code was added or changed to produce this file. This document exists
because the user gave an explicit architecture correction ahead of
finalizing Phase 4 (FG/Packing), with an explicit instruction: *"Do NOT
suddenly implement full DO/Shipment inside Phase 4... document it"* and
*"Phase 5 design must be reserved now."* This file is that reservation.

As of this document, **Phase 4 (FG/Packing) has not yet been started** in
this codebase — the most recent completed phase is Phase 3 (Production/SPK
actual, `api/app/src/Production/`). There is therefore no existing Phase 4
code to retroactively correct; this document is guidance for Phase 4's own
design (so it doesn't box in Phase 5) and the reserved design for Phase 5
itself.

---

## 1. The most important finding: this design already exists in the schema

The original 45-table draft schema (`database/schema-v1.sql`, applied in
full by migration `0001_schema_v1.php` — the very first migration every
deployment runs) **already contains the entire entity model this document
would otherwise need to design from scratch**: `delivery_order`,
`delivery_order_item`, `shipment`, `shipment_item`, `stock_ledger`,
`stock_balance`, and `document_sequence`. These tables exist, empty and
unused, in every environment right now — exactly the same situation
`production_run`/`production_item` were in before Phase 3 built logic on
top of them.

This means: **no additive migration is anticipated to be required for the
baseline DO/Shipment design** described in the user's spec. Phase 5's job
is almost entirely a service/API/UI layer on top of tables that already
exist, not a schema-design exercise. Section 8 below lists the few open
questions worth an explicit decision before that build starts — none of
them require a schema change to keep open.

Everything below cites exact table/column names from
`database/schema-v1.sql` (lines noted) and their rationale from
`docs/mysql-schema-v1.md` §5.5/§5.6/§6/§7, so Phase 5 has a single
authoritative cross-reference instead of re-deriving intent from the DDL
alone.

---

## 2. Business flow (as specified, confirmed against the schema)

```
PO Toko (Phase 2, authoritative: po_batch/po_item/po_store_item)
  -> Draft DO / Preprint DO per toko           (delivery_order + delivery_order_item)
  -> Production                                (Phase 3: production_run/production_item)
  -> FG/Packing                                (Phase 4: fg_batch/fg_batch_source/fg_item)
  -> Shipment (one or more per DO)             (shipment + shipment_item)
  -> stock_ledger 'shipment_out' rows          (one per shipment_item, exactly once)
  -> DO shipped/final                          (delivery_order.status via cumulative fulfillment)
```

`delivery_order_id` is a nullable FK on `shipment` (schema-v1.sql:439,
docs §5.6), so **one DO can have zero, one, or many `shipment` rows** —
the multi-shipment relationship already exists structurally. There is no
FK from `delivery_order`/`delivery_order_item` to `fg_batch`/`fg_item` —
**FG completeness is structurally not a prerequisite for creating a DO**,
matching rule A exactly.

---

## 3. Entity model (already in schema-v1.sql — no changes proposed)

### `delivery_order` (schema-v1.sql:339-370) — the DO header

```
delivery_order_id, doc_no (UNIQUE, server-assigned via document_sequence),
tanggal, store_id, shipment_group ENUM('MAIN','PASTRY','OTHER'),
status ENUM('draft','preprinted','ready','shipped','cancelled'),
batch, catatan, created_by, preprinted_at, ready_at, shipped_at, shipped_by,
version, created_at, updated_at,
open_key GENERATED ALWAYS AS (
  CASE WHEN status NOT IN ('shipped','cancelled')
       THEN CONCAT(tanggal,'|',store_id,'|',shipment_group) ELSE NULL END
) STORED, UNIQUE KEY uq_delivery_order_open (open_key)
```

This is the exact lifecycle the user asked for (rule B), already present.
The `open_key` generated column enforces "exactly one **open** DO per
(tanggal, store, shipment_group)" (docs §11) without blocking a *second*
DO for the same store/date once the first is `shipped` or `cancelled` —
i.e. it does **not** limit a store to one shipment/DO per day (rule M's
third non-assumption), it only prevents two simultaneously-open drafts for
the same (date, store, group) colliding.

### `delivery_order_item` (schema-v1.sql:372-382) — planned vs actual, per product

```
delivery_order_item_id, delivery_order_id, product_id,
planned_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
available_qty DECIMAL(12,2) NULL,
actual_ship_qty DECIMAL(12,2) NULL,
UNIQUE KEY uq_do_item (delivery_order_id, product_id)
```

- `planned_qty` = rule D's `planned_qty`, auto-populated from the store's
  current PO demand (Phase 2's `po_item`/`po_store_item`) at DO-creation
  time — never hand-typed as the default workflow (rule C).
- `available_qty` = an informational snapshot of FG on hand *at print
  time* (what the printed draft can honestly claim was available when
  printed) — display-only, never the gate used at ship time (see §5).
- `actual_ship_qty` = a **maintained running total**, kept in sync
  transactionally every time a `shipment_item` ships against this DO item
  (exactly the same "cache, not primary truth" pattern `stock_balance` uses
  over `stock_ledger` — docs §6.1). The authoritative value is always
  `SUM(shipment_item.qty)` joined through `shipment.delivery_order_id =
  delivery_order_id AND shipment.status='active' AND shipment_item.product_id
  = delivery_order_item.product_id`; `actual_ship_qty` must always be
  reconcilable to that sum, never a second independent source of truth.

`remaining_to_ship` (rule D/H) is therefore:

```
remaining_to_ship = max(0, planned_qty - SUM(active shipment_item.qty for this DO+product))
```

which is exactly rule H's formula, computed live, never stored as its own
column (avoids a second value that can drift from the ledger of shipments
that actually produced it).

### `shipment` (schema-v1.sql:429-453) — one header per physical delivery event

```
shipment_id, batch, tanggal, store_id, no_sj, pengemudi, kendaraan,
shipment_group ENUM('MAIN','PASTRY','OTHER'),
source_type ENUM('delivery_order','manual_kirim','customer_order_fulfillment'),
delivery_order_id NULL, customer_order_id NULL,
status ENUM('active','void'), voided_at, voided_by, void_reason,
version, created_at
```

**Important design property already decided in the original review**
(docs §5.6): a `shipment` row is created **only at the moment goods
actually leave stock** — there is no persisted "draft shipment" status.
Staging/preparation (rule I: "Draft shipment: NO stock movement") happens
entirely at the **DO** level (`draft`/`preprinted`/`ready`); the `shipment`
row and its `stock_ledger` rows are written together, in the same
transaction, only at ship-commit time. This is what makes rule H's "must
revalidate FG at SHIP commit time, not draft-shipment-creation time"
automatic — there is no earlier point at which a shipment row exists to
validate against. See §8.1 for the one thing worth confirming explicitly
before Phase 5 build (the terms "draft shipment" appearing in the task's
own future-test list DO-12).

`shipment_group` uses the same `ENUM('MAIN','PASTRY','OTHER')` as
`delivery_order` — deliberately never mixed within one shipment header
(docs §5.6: "a shipment never mixes two shipment_group values"), matching
rule F's staged MAIN/PASTRY example directly.

### `shipment_item` (schema-v1.sql:456-465) — one row per product per shipment

```
shipment_item_id, shipment_id, product_id, qty DECIMAL(12,2) NOT NULL,
UNIQUE KEY uq_shipment_item (shipment_id, product_id)
```

`qty` here is unambiguously **actual shipped qty** for that
shipment — there is no separate planned/actual split at this level because
a `shipment_item`, once it exists, already represents something that
happened (rule I). "Planned" only exists one level up, on
`delivery_order_item.planned_qty`.

### `stock_ledger` (schema-v1.sql:596-616) — the sole stock-deduction mechanism

```
event_type ENUM('production_in','shipment_out','adjustment','opening_balance','reversal')
source_type ENUM('production_run','shipment_item','stock_adjustment','stock_transfer',
                 'opening_balance_cutover','historical_replay','reversal')
```

Per docs §6's mapping table: creating a `shipment` with N `shipment_item`
rows writes **exactly N** `stock_ledger` rows (`event_type='shipment_out'`,
`source_type='shipment_item'`, one `source_id` per `shipment_item_id`) —
**in the same transaction** as the `shipment`/`shipment_item` insert.
Voiding later writes exactly N *new* reversal rows, never edits the
originals. This is rule I's "SHIPMENT_OUT, stock deduction occurs exactly
once" and rule H(DO-11)'s "retry SHIPPED does not double-deduct" —
idempotency here comes from **only ever creating the shipment once**
(normal Idempotency-Key handling at the API layer, same pattern already
used by Phase 2/3), not from any special ledger-side dedup logic.

### `document_sequence` (schema-v1.sql, docs §7) — DO numbering, already built

`DO` is already one of the two seeded `document_type` values (the other
being `INVOICE`), and `Amor\Api\Services\DocumentSequenceService` (built
in Phase 0, already covered by P0-17's concurrent-uniqueness test) already
implements the exact `SELECT ... FOR UPDATE` reservation algorithm docs §7
specifies. Phase 5 reuses this service as-is for `doc_no` generation — no
new numbering mechanism needed.

---

## 4. Worked example (rule K), mapped onto real rows

```
PO Bakery Pangleseran: Roti A = 20, Pastry B = 10   (po_item/po_store_item, Phase 2)

delivery_order  #1  tanggal=D  store_id=Pangleseran  shipment_group=MAIN  status=draft
delivery_order_item  (do#1, Roti A)  planned_qty=20
delivery_order_item  (do#1, Pastry B) planned_qty=10
  -- a SECOND delivery_order (#2, same store/date, shipment_group=PASTRY) may
  -- also exist if the business genuinely splits DOs by group — the schema
  -- supports either "one DO, two shipments" or "two DOs, one shipment each";
  -- rule F's "MAIN/PASTRY are shipment_group values" does not by itself force
  -- two DOs. See §8.2.

-- FG at 10:00: Roti A=20, Pastry B=0. Shipment 1 ships what's ready:
shipment  #1  delivery_order_id=do#1  shipment_group=MAIN  status=active
shipment_item (shpt#1, Roti A) qty=20
stock_ledger: 1 row, event_type=shipment_out, source=shipment_item#(shpt#1,RotiA), qty_delta=-20

-- remaining_to_ship for do#1/Pastry B = max(0, 10 - 0) = 10 (unchanged; do#1 not yet fully fulfilled)

-- Later, FG Pastry B=10 becomes available:
shipment  #2  delivery_order_id=do#1  shipment_group=PASTRY  status=active
shipment_item (shpt#2, Pastry B) qty=10
stock_ledger: 1 row, event_type=shipment_out, source=shipment_item#(shpt#2,PastryB), qty_delta=-10

-- remaining_to_ship for do#1/Pastry B = max(0, 10 - 10) = 0 -> do#1 fully fulfilled
-- (delivery_order.status transitions to 'shipped' once every item's
-- remaining_to_ship reaches 0 — a Phase 5 service-layer rule, no schema change)
```

No second, unrelated DO was created merely because Pastry shipped later —
matching rule K's explicit instruction. One `delivery_order_id` was reused
by two `shipment` headers.

---

## 5. Non-assumptions this reservation holds (rule M, restated as design invariants)

- **DO ≠ shipment.** A DO can exist, be printed, and sit for hours/days
  with zero shipments against it. Fulfillment is `SUM(shipment_item.qty)`
  over its shipments, never a 1:1 assumption.
- **FG completeness is not a precondition for DO existence.** No FK from
  `delivery_order`/`delivery_order_item` to any FG table enforces this,
  and rule A forbids adding one.
- **A store is not limited to one shipment per day.** Nothing in the
  schema caps `shipment` rows per `(store_id, tanggal)` — only `delivery_order`
  has an *open-DO* uniqueness constraint (§3), and even that is scoped to
  "not yet shipped/cancelled," not "per day."
- **Draft/preprinted DO and any pre-ship staging never write to
  `stock_ledger`.** The only code path that can insert a `stock_ledger`
  row with `event_type='shipment_out'` is the transaction that creates a
  real `shipment` + its `shipment_item` rows (§3/§4).
- **`shipment_group` is not a two-value boolean.** It is an `ENUM` today
  (`MAIN`/`PASTRY`/`OTHER`), not a hardcoded pair — see §8.3 for whether
  that remains sufficient.

---

## 6. Phase 4 guidance (what Phase 4 must NOT do, and what it should confirm)

Per rule L, Phase 4 (FG/Packing) must:

1. Implement FG/Packing correctly on `fg_batch`/`fg_batch_source`/`fg_item`
   (already in the 0001 schema, same "tables exist, logic doesn't yet"
   situation as Production was before Phase 3).
2. **Not** implement any part of Draft DO, Preprint, or Shipment. Phase 4's
   read surface for "what's available to ship" is exactly FG's own
   `fg_item`/derived-availability data — Phase 4 does not need to know
   `delivery_order`/`shipment` exist at all.
3. Confirm (this document already does, by inspection — no code needed)
   that `stock_ledger`'s `event_type='production_in'` /
   `source_type='production_run'` path Phase 4 will use for FG-verified
   production does not collide with, or need to change, the
   `event_type='shipment_out'` path Phase 5 will use later. They are
   already two independent branches of the same ENUM.
4. Add no FK, trigger, or application check that makes DO creation depend
   on FG state — confirmed nothing in the current schema does this (§3).

No additive migration is being proposed in Phase 4 for DO/Shipment
purposes — the tables already exist and already support this design.

---

## 7. Future test list (rule N, carried forward verbatim as Phase 5 acceptance criteria)

DO-01 Draft DO can be created while FG = 0
DO-02 Draft DO creation does not reduce stock
DO-03 Preprint does not reduce stock
DO-04 DO planned qty comes from store PO
DO-05 Shipment MAIN can ship part of DO
DO-06 Shipment PASTRY can ship later
DO-07 one DO supports multiple shipments
DO-08 shipped qty cannot exceed remaining DO qty
DO-09 shipped qty cannot exceed FG availability
DO-10 stock decreases only on shipment SHIPPED
DO-11 retry SHIPPED does not double-deduct
DO-12 cancelled/draft shipment does not affect stock
DO-13 DO becomes fulfilled only when cumulative shipped qty reaches planned qty
DO-14 staged shipment does not alter PO
DO-15 staged shipment does not alter production actual

All 15 are already satisfiable by the schema as designed (§3-§5); none
require a schema change to become true. They become executable once
Phase 5's `DeliveryOrderService`/`ShipmentService` exist.

---

## 8. Open questions for explicit sign-off before Phase 5 build

These are the only points this reservation could not resolve by reading
the existing design alone — flagged rather than decided unilaterally.

### 8.1 Does "draft shipment" (DO-12's wording) need its own persisted row?

The existing schema has no `shipment.status='draft'` — a `shipment` row
only ever exists as `active` or `void` (§3), created at ship-commit time.
DO-12 says "cancelled/draft shipment does not affect stock," which is
trivially true under the existing design (a draft never becomes a
`shipment` row at all — it's just DO-level staging + whatever the Phase 5
UI holds in an unsaved form). **Recommendation:** keep it this way — no
schema change — and treat "draft shipment" in Phase 5's UI as "a shipment
the operator is composing but has not yet committed," never a DB row.
Flag if the business actually needs multiple people to collaborate on the
*same* in-progress shipment across sessions before it ships (that would be
the one scenario that might justify a real `status='draft'` row later —
an additive `ALTER ... MODIFY status ENUM(...)` if so, still not a
redesign).

### 8.2 Does MAIN/PASTRY imply exactly one DO per group, or one DO covering both?

§4's worked example shows both are structurally possible: one DO with two
shipments (one MAIN, one PASTRY), or two separate DOs (one per group) each
fulfilled by its own shipment. The existing uniqueness constraint
(`open_key` on `(tanggal, store_id, shipment_group)`) actually reads as
designed for the **two-DO** interpretation (a DO is already scoped to one
`shipment_group`). **Recommendation:** Phase 5 follows the schema's own
implication — one DO per `(date, store, shipment_group)` — since that's
what the existing unique key already enforces; a print/UI layer can still
show "this store's DOs for today" grouped together for the operator's
convenience without it being one DB row.

### 8.3 Is the `shipment_group` ENUM sufficient, or does it need to become a lookup table?

Today it's a closed 3-value `ENUM` (`MAIN`/`PASTRY`/`OTHER`) on both
`delivery_order` and `shipment`. Rule F says "don't hardcode only two" —
the existing `OTHER` value already provides one escape hatch, and adding a
new named value later (`FOLLOW_UP`, `SPECIAL`, ...) is a normal additive
`ALTER TABLE ... MODIFY COLUMN` (no data loss, same pattern already used
for other enum-like columns in this codebase). **Recommendation:** keep
the `ENUM` — it matches the already-reviewed design in
`docs/mysql-schema-v1.md`, and a lookup-table migration is a bigger,
unforced change that can wait until a genuinely open-ended set of groups
is a real, not hypothetical, requirement.

---

## 9. Summary

No code or schema was changed by this document. The DO/Shipment/Stock
architecture the user specified is already present in
`database/schema-v1.sql` (applied by migration `0001_schema_v1.php`) and
already reasoned through in `docs/mysql-schema-v1.md` §5.5/§5.6/§6/§7.
Phase 4 should build FG/Packing without touching or depending on
`delivery_order`/`shipment`/`stock_ledger`'s `shipment_out` path. Phase 5
builds the DO/Shipment service+API+UI layer directly on the existing
tables, resolving §8's three open questions explicitly before or during
that build.
