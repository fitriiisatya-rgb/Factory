# Draft/Preprint DO + Staged Shipment — Phase 5 Design Reservation

**Status: PLANNING DOCUMENT ONLY.** No schema, migration, or application
code was added or changed to produce this file. This document exists
because the user gave an explicit architecture correction ahead of
finalizing Phase 4 (FG/Packing), with an explicit instruction: *"Do NOT
suddenly implement full DO/Shipment inside Phase 4... document it"* and
*"Phase 5 design must be reserved now."* This file is that reservation.

**Correction applied (second pass):** an earlier version of this document
recommended "one DO per (date, store, shipment_group)" as the identity
Phase 5 should follow, reasoning that the existing `open_key` unique
constraint already implied it. **That recommendation was wrong and has
been reversed.** The FINAL Amor business rule, stated explicitly by the
user: **one store PO normally produces exactly ONE Delivery Order holding
the store's complete planned demand across every product**, and that same
DO is fulfilled by zero, one, or many `shipment` rows of possibly
different `shipment_group` values (MAIN, PASTRY, OTHER, ...), all sharing
the same `delivery_order_id`. §3 and §8.2 below now audit the exact
constraint responsible for the earlier wrong recommendation and design the
additive fix Phase 5 will need to apply.

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

This means: **no additive migration is required for the baseline
DO/Shipment design** described in the user's spec — with one exception,
identified and resolved in §8.2: the DO-level *open-document uniqueness*
constraint is scoped by `shipment_group` today, which does not match the
final "one DO per store, many shipment groups" business rule. §8.2 designs
the additive fix. Section 8 also lists two remaining open questions that
do **not** require a schema change.

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

**Correction (see §8.2 for the full audit):** the `open_key` generated
column, and the `shipment_group` column it's built from, encode a
"one open DO per **group**" identity that does **not** match the FINAL
business rule (one DO per store holding all groups' demand). This is not
a hard technical block — nothing stops multiple `shipment` rows of
different groups from referencing the same `delivery_order_id` — but the
DO header's own `shipment_group` column, and the uniqueness scoped by it,
are the wrong shape for "one DO, many groups." §8.2 designs the additive
fix: a second, group-agnostic uniqueness key scoped to `(tanggal,
store_id)` only, which Phase 5 will rely on instead of the existing one.

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

delivery_order  #1  tanggal=D  store_id=Pangleseran  status=draft
  -- ONE DO for the whole store's planned demand — shipment_group is NOT
  -- part of the DO's identity (see §8.2's corrected migration); the DO
  -- header's legacy shipment_group column, if still present, is ignored
  -- for identity purposes and never used to decide whether a second DO
  -- is needed.
delivery_order_item  (do#1, Roti A)  planned_qty=20
delivery_order_item  (do#1, Pastry B) planned_qty=10

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

## 5. Confirmed invariants (restated exactly as asked, with the schema evidence for each)

| Invariant | Confirmed? | Evidence |
|---|---|---|
| DO may exist while FG = 0 | **Yes** | No FK from `delivery_order`/`delivery_order_item` to any FG table (§2). |
| DO planned_qty comes from store PO | **Yes** | `delivery_order_item.planned_qty` is populated from Phase 2's `po_item`/`po_store_item` at DO-creation time (§3), never hand-typed as the default workflow. |
| DO draft/preprinted does not consume stock | **Yes** | Only a real `shipment` insert can write `stock_ledger` (§3 `stock_ledger` / §4). No code path writes a ledger row from `delivery_order.status` alone. |
| One DO may have many shipment rows | **Yes** | `shipment.delivery_order_id` is a nullable FK with no uniqueness constraint against it — any number of `shipment` rows may share one `delivery_order_id` (§2, §4). |
| Each shipment has its own shipment_group | **Yes** | `shipment.shipment_group` is a column on `shipment` itself (§3), independent of whatever the DO header's own (now-deprecated-for-identity) `shipment_group` says. |
| MAIN and PASTRY can reference the same DO | **Yes** | Same evidence as above — nothing matches `shipment.shipment_group` against `delivery_order.shipment_group`; they were never constrained to agree. §8.2 removes the one place (DO-level uniqueness) that implicitly assumed otherwise. |
| Partial shipment does not modify PO | **Yes** | No shipment/DO code path writes to `po_batch`/`po_item`/`po_store_item` — Phase 2's PO tables have no FK from or to `shipment`/`delivery_order` at all. |
| Cumulative shipment actual determines DO fulfillment | **Yes** | `remaining_to_ship = max(0, planned_qty - SUM(active shipment_item.qty))` per product (§3); a Phase 5 service rule transitions `delivery_order.status` to `shipped` once every item reaches 0 remaining. |
| Shipment stock deduction happens only on actual SHIPPED commit | **Yes** | A `shipment` row is only ever created (as `active`) at ship-commit time, in the same transaction as its `stock_ledger` rows (§3 `shipment` / §3 `stock_ledger`). |
| Later PASTRY shipment consumes only its own shipped quantities | **Yes** | Each `shipment_item.qty` writes exactly one `stock_ledger` row for that item alone (§3 `stock_ledger`); an earlier MAIN shipment's ledger rows are untouched by a later PASTRY shipment. |
| No assumption of one shipment per store per day | **Yes** | Nothing in the schema caps `shipment` rows per `(store_id, tanggal)` (§3 `delivery_order`'s uniqueness only constrains **open DOs**, and after §8.2's fix that constraint is scoped per store+day, not per shipment — it still says nothing about how many `shipment` rows that one DO can accumulate). |

All eleven hold today, by inspection of the existing schema, with **one
caveat**: "one DO may have many shipment rows of different groups" is true
at the FK level right now, but the DO-level *uniqueness* constraint still
encodes a group-scoped identity that contradicts the "one DO per store"
half of the final rule. §8.2 is the fix for that one remaining mismatch.

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

## 8. Design questions, sign-off, and corrections

§8.2 was flagged as open in the first pass of this document and is now
**resolved** below, following an explicit architecture correction from the
user. §8.1 and §8.3 remain open — flagged rather than decided
unilaterally.

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

### 8.2 RESOLVED — one DO per store (not per group). Full audit of the constraint that implied otherwise.

**This was previously the open question recommending "one DO per
(date, store, shipment_group)." That recommendation is withdrawn.** The
FINAL business rule is explicit: one store PO produces one DO holding the
store's complete planned demand; MAIN, PASTRY, and any other
`shipment_group` are properties of the **shipments** fulfilling that one
DO, never a reason to split the DO itself.

**Audit, answering each point exactly as asked:**

1. **Exact table:** `delivery_order` (`database/schema-v1.sql:339-370`).

2. **Exact columns in the unique key:** the unique key is
   `uq_delivery_order_open`, defined on one generated `STORED` column,
   `open_key`. `open_key` is *not* a plain column — it's computed as
   `CASE WHEN status NOT IN ('shipped','cancelled') THEN
   CONCAT(tanggal, '|', store_id, '|', shipment_group) ELSE NULL END`.
   So the **effective** unique-key columns, once you unpack the generated
   expression, are `(tanggal, store_id, shipment_group)` — three columns,
   materialized through one derived column because MariaDB's partial-
   unique-index technique (only "open" rows are constrained — docs §11)
   needs a single nullable column to hang a `UNIQUE KEY` on.

3. **Does it actually prevent one DO having MAIN + PASTRY shipments? NO.**
   Nothing in `uq_delivery_order_open`, or any other constraint on
   `delivery_order`, `shipment`, or `shipment_item`, stops two `shipment`
   rows with different `shipment_group` values from both carrying the same
   `delivery_order_id`. The FK (`fk_shipment_do`) only requires that
   `delivery_order_id`, if set, point at *some* existing `delivery_order`
   row — it never checks that row's `shipment_group` against the
   shipment's own. The worked example in §4 (one `delivery_order_id`,
   a MAIN `shipment` and a later PASTRY `shipment` both referencing it) is
   already valid under the schema exactly as it stands today, with **zero
   schema change** — this part of the final rule already works.

4. **Does it instead only prevent duplicate DO generation? YES, but
   scoped by the wrong key.** `uq_delivery_order_open` prevents two
   simultaneously-**open** `delivery_order` rows from sharing the same
   `(tanggal, store_id, shipment_group)` triple. Combined with
   `delivery_order` carrying its own single-valued `shipment_group`
   column, the constraint's real effect is "at most one open DO per
   **(date, store, group)**" — which is the "one DO per group" shape the
   earlier (withdrawn) recommendation described, and which **does not
   match** the final rule of "one DO per (date, store), full stop,
   regardless of group." A second, unrelated DO could currently be opened
   for the same store/date as long as its `shipment_group` differed
   (e.g. one `draft` DO with `shipment_group='MAIN'` and a second `draft`
   DO with `shipment_group='PASTRY'` for the same store/day — both legal
   today, and exactly the two-DO outcome the final rule forbids).

5. **Will any schema change be required in Phase 5? YES — the migration
   below.** Point 3 (multi-group shipments against one DO) needs nothing.
   Point 4 (DO-level uniqueness scoped by group) needs an additive fix so
   the enforced identity becomes "(date, store)" instead of "(date, store,
   group)".

**Additive/non-destructive migration strategy for Phase 5** (not applied
now; not required by Phase 4 — see §6):

```sql
-- New, group-agnostic partial-unique-index column, added ALONGSIDE the
-- existing open_key/uq_delivery_order_open (never dropped, never
-- modified — same "ADD only" discipline as migrations 0002-0004).
ALTER TABLE delivery_order
  ADD COLUMN IF NOT EXISTS open_key_by_store VARCHAR(80) GENERATED ALWAYS AS (
    CASE WHEN status NOT IN ('shipped','cancelled')
         THEN CONCAT(tanggal, '|', store_id)
         ELSE NULL END
  ) STORED AFTER open_key;

ALTER TABLE delivery_order
  ADD UNIQUE KEY IF NOT EXISTS uq_delivery_order_open_store (open_key_by_store);
```

Effects, and why this is safe:

- **Additive only** — one `ADD COLUMN` + one `ADD KEY`, same `IF NOT
  EXISTS` idempotency discipline already used in migrations 0002-0004.
  No table dropped, no column dropped, no existing row's data touched.
- **The new key becomes the one Phase 5's `DeliveryOrderService` actually
  relies on** for "reject creating a second open DO for a store that
  already has one open today" — i.e. the group-agnostic rule the final
  business requirement asks for.
- **The old `open_key`/`uq_delivery_order_open` is left in place,
  harmlessly dormant.** Since Phase 5 will stop varying
  `delivery_order.shipment_group` per-DO (it's no longer meaningful at
  the DO level — see below), every DO row ends up with the same constant
  value in that column (e.g. the column's existing `DEFAULT 'MAIN'`), so
  the old constraint is trivially satisfied by construction and never
  rejects a legitimate insert. It is safe to leave un-dropped indefinitely,
  and a later, separate, optional cleanup migration could drop
  `open_key`/`uq_delivery_order_open`/the now-unused `shipment_group`
  column from `delivery_order` entirely once Phase 5 is stable and nothing
  is confirmed to depend on them — that cleanup is explicitly **not**
  proposed or scheduled now.
- **`delivery_order.shipment_group` itself is not removed by this
  migration** (removing a column is a separate, non-additive decision,
  deliberately deferred per the instruction to design something additive/
  non-destructive). Phase 5's application code simply stops reading it for
  any business decision — the authoritative group for a given unit of
  work now always lives on `shipment.shipment_group` (per-shipment, as
  §3/§5 already establish), never on the DO header.
- **`delivery_order_item` needs no change at all** — it was never
  group-scoped (§3), which is exactly right and already matches the
  corrected rule.

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

No code or schema was changed by this document (still true after this
correction — §8.2's migration is designed, not applied). The DO/Shipment/
Stock architecture the user specified is already present in
`database/schema-v1.sql` (applied by migration `0001_schema_v1.php`) and
already reasoned through in `docs/mysql-schema-v1.md` §5.5/§5.6/§6/§7,
**with one identified gap**: `delivery_order`'s open-document uniqueness
is scoped by `shipment_group`, which contradicts the final "one DO per
store, many shipment groups" rule. §8.2 audits the exact constraint
(`uq_delivery_order_open` on the generated `open_key` column, effectively
`(tanggal, store_id, shipment_group)`) and designs its additive fix (a new
`open_key_by_store`/`uq_delivery_order_open_store` pair scoped to
`(tanggal, store_id)` only, added alongside — never replacing — the
existing one).

**Can Phase 4 (FG/Packing) proceed without any schema change?** **Yes.**
The §8.2 fix is scoped entirely to `delivery_order`'s own uniqueness
identity — it has no interaction with `fg_batch`/`fg_batch_source`/
`fg_item`, and Phase 4 does not read or write `delivery_order`/`shipment`
at all (§6). The §8.2 migration is Phase 5's to apply, when Phase 5
begins, not Phase 4's.
