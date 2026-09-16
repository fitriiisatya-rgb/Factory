# Amor Factory — Migration Map V1 (Sheets → MySQL)

**Status: MYSQL DESIGN V1 FINAL-CANDIDATE READY FOR REVIEW**
No ETL has been run. No live data has been touched. This document finalizes `docs/mysql-migration-audit.md` §17/§18 against the schema in `docs/mysql-schema-v1.md`. This revision replaces §3's old single "synthesize a shipment representation" rule with the locked three-case (A/verified, B/reconstructed, C/unverified) structure from schema §5.6.1 (review point 5) and reflects the `shipment`/`shipment_item` header+item split (review point 1).

---

## 1. Identity resolution process (review points 1 & 2 — the prerequisite for everything else)

This must run **before** any transactional row is inserted into `product`, `store`, or any table that references them, because every other table's FK depends on having a trustworthy `product_id`/`store_id` to point at.

### 1.1 Harvest

For every historical row in `PO`, `Ceklis`, `FGPacking`, `Kirim`, `Retur`, `Reject`, `Jual`, `Invoice.ItemsJSON`, `Pesanan.PayloadJSON`, `MasterProduk`, `Mutasi`, `StokAdj`, extract the raw `Produk` string (+ `Kode` where present) and the raw `Toko` string, and insert one row per **distinct** `(raw_name, raw_code)` pair into `migration_product_map` / `migration_store_map` (schema §4), with `occurrence_count` = how many historical rows used that exact pair, `source_table` = the first sheet it was found in (informational), `status='unresolved'`.

This step is a straightforward, mechanical distinct-and-count query per legacy sheet — no judgment calls, purely harvesting.

### 1.2 Pre-fill suggestions (assist only, never auto-commit)

Run the *existing, already-built* frontend audit tools — `storeUsageAudit`/`auditStoreAliases` for stores, an equivalent normalized-name grouping pass (using `normNama`/`mpResolveKey`'s exact logic, Audit §10) for products — against the harvested rows. Where these tools already suggest a grouping (e.g., two raw names differ only by case/whitespace, or match `bootstrapTokoCanonicalDikenal`'s one hardcoded known pair), write that suggestion into `migration_*_map.notes` as free text (e.g. `"suggested: matches existing alias group for 'BAKERY CIKOLE'"`). **`status` stays `'unresolved'` after this step — a suggestion is not a resolution.**

### 1.3 Human resolution

A human (using `GET/POST /api/admin/migration/products` and `/stores`, `docs/php-api-contract-v1.md` §12) reviews every `unresolved` row, ordered by `occurrence_count DESC` (highest-impact first), and either:
- Confirms it maps to an existing or newly-created `product`/`store` → `status='mapped'`, `target_id` set, `resolved_by`/`resolved_at` stamped.
- Flags it as ambiguous (could be more than one real entity) → `status='conflict'`, with `notes` explaining the ambiguity, held for a business decision (e.g., "these two 'CKLE' occurrences are actually different physical outlets that happen to share a code — confirm with PPIC before merging").

**No row may reach `status='mapped'` without a human-set `resolved_by`.** This is enforced by process (application code path), not by a DB trigger — the schema does not prevent a bulk `UPDATE` from setting `status='mapped'` programmatically, but no endpoint in `docs/php-api-contract-v1.md` exposes a bulk-auto-resolve action, and this document records that no such tool is to be built.

### 1.4 Backfill gate

The ETL (§2 below) that populates transactional tables **does not run** until every row in both staging tables is either `mapped` or explicitly accepted as a permanent `conflict` bucket with a documented fallback (§1.5). A `conflict` row is not required to become `mapped` before cutover — it is required to have an explicit, signed-off decision about what happens to the historical rows that used that raw value.

### 1.5 Fallback bucket for unresolved/conflict rows at cutover time

If a business decision is made to proceed with cutover while some `migration_*_map` rows remain `conflict` (not `unresolved` — every row must at least reach `conflict` or `mapped`, never be silently skipped), those historical rows are migrated against a designated placeholder record (`product`/`store` row named e.g. `"[UNRESOLVED LEGACY]"`, `active=0`), never dropped and never force-merged into a real entity without sign-off. This placeholder's usage count becomes a visible, permanent line item in post-migration reports — not hidden.

---

## 2. Sheet → MySQL table mapping

| Legacy Sheet | MySQL table(s) | Notes |
|---|---|---|
| `PO` | `po_batch`, `po_item`, `po_store_item` | Header (`tanggal+factory`) + item + per-store breakdown split, per `docs/mysql-schema-v1.md` §5.2. `poAwal`/`poRevisi` freeze/replace semantics (Audit §7) preserved as an **application-layer rule**, not re-derivable from the schema alone — the ETL must replay the *final* state per key correctly (frozen awal = the value at first-ever upload for that key; revisi = the value from the most recent upload), not just copy the last row seen |
| `Ceklis` + `CeklisMeta` | `production_run`, `production_item` | State machine (Audit §6.1) ported 1:1 into `production_run.status` |
| `FGPacking` + `FGReady` | `fg_batch`, `fg_batch_source`, `fg_item` | `FGReady.SourceVersionJSON` unpacked into real `fg_batch_source` rows |
| `Kirim` | `shipment` (header), `shipment_item` (one row per product) | **Header+item split (review point 1, NEW this pass).** Legacy `Kirim` rows are grouped into one `shipment` header per distinct physical delivery event (same `tanggal`+`store`(raw)+`batch`+`ShipmentGroup`), with each product line becoming a `shipment_item` row under that header — never one `shipment` row per product as the pre-correction design had it. `source_type='delivery_order'` for rows whose `Id` matches the `docId+"-"+kode` pattern from a `DODoc`-driven ship (Audit §6.2), else `source_type='manual_kirim'`. `ShipmentGroup` column maps directly to `shipment.shipment_group`; historical rows with no `ShipmentGroup` value → `'MAIN'` (Audit §17, unchanged). MAIN and PASTRY rows for the same date/store are always grouped into **separate** headers, never merged. `shipment.store_id` is resolved via `migration_store_map`; a `Kirim` row with no resolvable store (rare, walk-in) resolves to the synthetic `NON-OUTLET / PERORANGAN` store — see the store-seed note under §2 below, never NULL |
| `DODoc` | `delivery_order`, `delivery_order_item` | JSON item blobs unpacked into `delivery_order_item` rows. Historical Kirim rows that predate this feature (Audit §6.2's "historical documents from before this feature never appear here") get **no** synthetic `delivery_order` row invented for them — they migrate as bare `shipment` header(s) (with their `shipment_item` rows) only, exactly matching current behavior |
| `Invoice` | `invoice`, `invoice_item`, `invoice_shipment` | **The one non-mechanical step in this mapping** — see §3 below (invoice-to-shipment linking) |
| `Pembayaran` | `payment` | |
| `Retur` (+ the legacy `amorBacaRetur_` dual-purpose reader, Audit §14 L2) | `return_note` (Retur rows) and `reject_note` (Reject rows mixed in via the legacy `Jenis` column) | The two are split at ETL time using the exact same `jenis.startsWith("reject")` rule the frontend's `serapReturSheet` already uses (Audit §3/§14) — not reinterpreted, just relocated from client-side JS into the one-time ETL script |
| `Reject` | `reject_note` | Merged with the above (a `reject_note` may originate from either the manual UI path or the legacy sheet-import path — both land in the same table, `sumber` column preserves provenance) |
| `Jual` | `retail_sale` | |
| `Mutasi` | `stock_transfer` | `source_shipment_id`/`from_invoice_id`/`to_invoice_id` backfilled by matching the legacy `BatchAsal`/`BatchTujuan` fields against migrated `shipment`/`invoice` rows |
| `StokAdj` | `stock_adjustment` | Never cascade-deleted historically (Audit §8/§14) — migrates in full, including any legacy rows whose originating PO batch was later cascade-deleted via Trial Batch Delete (they were exempted from that cascade specifically so they'd survive to be seen here) |
| `Pesanan` (`PayloadJSON`) | `customer_order`, `customer_order_item` | JSON blob unpacked into real columns/rows. **Historical Pesanan-derived invoices** (created via the legacy order-gated `psSyncInvoice` path, Audit §9) are migrated as `invoice` rows same as any other — but see §3 for how they get a compliant `invoice_shipment` link despite not having had a real shipment at creation time. This is the migration-compatibility handling explicitly requested by review point 3, not a new business rule |
| `Master` (divisi/produk/toko flat lists) | `division` (divisi rows only — produk/toko rows are superseded by `product`/`store` via §1, not migrated as a separate flat list) | |
| `MasterProduk` | `product` | Merged with `KATALOG_BAWAAN` (Audit §14 L16) — see §4 below |
| `TokoTipe` | `store.channel` | |
| `Settings` | `master_setting` | `pctOwnership`/`pctFranchise` migrate as `is_active=false`-equivalent historical-only rows (Audit §14 L4) — modeled here simply as rows the application is instructed never to read for new pricing decisions, per schema §16 |
| `RecordVersion` | *(not migrated — superseded)* | Becomes the `version` column on each authoritative row (schema §12); the sheet's historical version numbers are not meaningful to carry forward (a fresh `version=1` on migrated rows is correct — Audit §11's version-per-record concept is preserved, the specific numbers are not) |
| `RequestLog` | *(not migrated)* | Pure idempotency-protocol history, superseded by the new `idempotency_log` starting fresh at cutover |
| `AuditLog` | `audit_log` | Migrated in full — this is the one sheet with genuine historical evidentiary value worth preserving verbatim (Audit §14 L14 flags growth/retention, not disposability) |
| `Log` | *(not migrated)* | Debug-only, never had a read path (Audit §4) |
| *(no legacy source)* | `store`, `store_alias` | Built fresh per §1, per review point 2 — there is nothing to map FROM for canonical store identity. **One additional row is seeded before any other store row (review point 2, LOCKED):** `canonical_name='NON-OUTLET / PERORANGAN'`, `channel=NULL`, `active=1`. Every historical row that has no resolvable real store (walk-in/non-outlet legacy transactions) resolves its `store_id` FK to this row at ETL time — `store_id` is `NOT NULL` on `shipment`/`customer_order` in the target schema, so nothing may migrate with a NULL store reference |
| *(no legacy source)* | `product_alias` | Built fresh per §1 (informed by `D.produkAlias` where it exists, but not limited to it — `D.produkAlias` was never synced to the backend either, so this is also effectively a fresh-build, informed by whatever local export can be recovered from active devices before cutover) |
| *(no legacy source)* | `migration_product_map`, `migration_store_map` | The staging tables themselves — see §1 |
| *(no legacy source)* | `users`, `roles`, `user_roles`, `user_factory_access`, `user_division_access` | Real auth did not exist before (Audit §6/§19 R18) — user accounts are created fresh, with an initial `ADMIN` account and role assignments decided by the business, not derived from the free-text `actor.userName` values scattered through legacy `AuditLog`/`Kirim`/etc. (those remain as historical labels only, migrated verbatim into `audit_log`/wherever they already existed as text, never auto-promoted into real `users` rows) |
| *(no legacy source)* | `document_sequence` | Seeded at cutover with `last_number` set high enough to be greater than the highest number appearing in any migrated historical `doc_no`/`invoice_no` for that `(document_type, year, month)`, so the first newly-generated number never collides with a migrated historical one — see `docs/mysql-open-decisions-v1.md` OD-2 for the exact numbering-format decision this depends on |
| *(no legacy source)* | `stock_ledger`, `stock_balance` | See §5 (opening stock) |

---

## 3. Invoice-to-shipment linking during migration (the one genuinely non-mechanical mapping step) — LOCKED three-case structure (review point 5)

**Correction from the prior revision of this document (review point 5):** the earlier language here described a single "synthesize a shipment representation when evidence is thin" rule applied uniformly. That was wrong — it risked inventing fictitious physical deliveries for invoices that have **no** stock-out evidence at all. This section replaces that with the LOCKED three-case structure from `docs/mysql-schema-v1.md` §5.6.1. Every migrated `invoice` row gets exactly one of these three dispositions; there is no fourth path and no case is skipped.

### Case A — real shipment evidence exists (the normal path, the large majority)

Legacy invoices created via `kBukaInvoice` (the normal path) and Mutasi-derived invoices both have real, identifiable fulfillment evidence:
- **Shipment-gated legacy invoices**: link directly to the migrated `shipment` **header**(s) sharing the same `batch` (review point 1 — the link is to the header, never to an individual product line). Purely mechanical.
- **Mutasi-derived legacy invoices**: link to the **original** `shipment_id` reachable via the migrated `stock_transfer.source_shipment_id` (Audit §9's nuance, carried into schema §5.6/§8). Mechanical once `stock_transfer` rows are migrated.

Both migrate with `invoice.legacy_fulfillment_status='verified'`.

### Case B — no original shipment record, but reliable stock-out evidence exists (Pesanan-derived, StokAdj-backed)

Pesanan-derived legacy invoices (the order-gated `psSyncInvoice` path, Audit §9) whose Pesanan reached `status='selesai'` **and** have a corresponding `StokAdj` "koreksi" row (the legacy stock-out signal for this path, Audit §8): **synthesize** one `shipment` header (with one `shipment_item` row per invoice item) at ETL time, dated to the `StokAdj` row's `tanggal`, with `source_type='customer_order_fulfillment'`, and link the invoice to it. This is only done because reliable, independent stock-movement evidence (the `StokAdj` row) exists — it is a reconstruction from real evidence, not an invention. Migrates with `invoice.legacy_fulfillment_status='reconstructed'`.

### Case C — invoice exists but NO shipment/StokAdj/reliable stock-out evidence at all (LOCKED, review point 5 — the actual correction)

If a Pesanan-derived invoice's order never reached `selesai`, or reached it without any corresponding `StokAdj`/other reliable stock-movement evidence: **do NOT invent a shipment.** This is a real historical data-quality gap this review is explicitly surfacing, not something the migration is allowed to paper over with a fictitious delivery record. Instead:
- The `invoice` row (and its `invoice_item`/`payment` rows — the full financial history) is migrated as-is, preserving the money trail.
- `invoice.legacy_fulfillment_status='unverified'` is set.
- **No `shipment`, `shipment_item`, or `stock_ledger` row is created for it at all.** There is nothing to link — `invoice_shipment` legitimately has zero rows for this invoice, which is the one documented exception to the "every invoice has >=1 shipment link" invariant (migration-only).
- These invoices are excluded from physical shipment/fulfillment KPIs and reports by default (they are filtered on `legacy_fulfillment_status != 'unverified'` unless a report explicitly asks to include them), and are clearly marked in the migration run's own summary report as a distinct, counted bucket — not silently blended into "normal" invoices.

### Why this is safe going forward

**No code in the new PHP API can produce Case B or Case C.** `docs/php-api-contract-v1.md` §8's `POST /api/invoices` always sets `legacy_fulfillment_status='verified'` and always requires a non-empty `shipmentIds` referencing real, pre-existing `shipment` headers — there is no request shape that creates an invoice without a real shipment, and no field that lets a caller set `legacy_fulfillment_status` to anything else. Cases B and C can only ever occur for pre-cutover historical data produced by this one-time ETL.

---

## 4. Product catalog seed (`KATALOG_BAWAAN`, Audit §14 L16)

The 472-row built-in catalog is cross-referenced against actual usage in `PO`/`Kirim`/`Ceklis` (Audit §14's explicit recommendation) before migration:
- Entries that appear in at least one transactional row → migrated as normal `product` rows via §1's process (they'll be harvested naturally since they appear in transactional data).
- Entries that **never** appear in any transactional row → migrated as `product` rows with `aktif=0` and a `notes`-equivalent marker (or simply excluded from the initial migration and re-added on demand — a decision point, see `docs/mysql-open-decisions-v1.md` OD-8), rather than silently inflating the live catalog with 472 rows of unknown provenance from day one.

---

## 5. Opening stock (review point 13 — process detail; schema support is already in `docs/mysql-schema-v1.md` §10)

1. **Attempt Path A first**: replay full historical stock-affecting events (FG-verified production, all shipments, all stock adjustments, all `ganti`-reject-triggered adjustments) into `stock_ledger` with their original dates, then compute `stock_balance` from the ledger and compare it, per product, against the legacy system's own `stokGudang()` value **as it would have reported on the same historical date** (recomputable from the legacy Sheets export, since `stokGudang()`'s formula is fully known — Audit §8).
2. **Reconciliation check**: for every product, the two must match exactly (zero tolerance — a stock formula is either faithfully replayed or it isn't). Discrepancies are investigated (usually attributable to a legacy data-entry inconsistency already flagged elsewhere in the audit, e.g. a `kode` collision that was silently mis-attributing production quantities before `skuId`/`doDocItemKey_` were introduced).
3. **If reconciliation passes for all products** → Path A's replayed ledger is the migrated data; no separate opening-balance event is needed.
4. **If reconciliation fails for some products and cannot be resolved by fixing a specific identified data issue** → fall back to **Path B** for just those products: discard the replay for that product, insert a single `opening_balance` ledger row per the schema, and attach a **signed reconciliation report** (§10 of the schema doc) explaining the discrepancy and stating the agreed starting balance (from an independent physical stock count, per the review's own wording). Path A and Path B may be mixed **per product** — this is not an all-or-nothing switch across the whole catalog.
5. Either way, the decision (and, for Path B products, the signed report) is recorded permanently — referenced from `stock_ledger.notes`/`legacy_ref` on the relevant rows — as part of the migration's own audit trail, not just a one-time email or spreadsheet that gets lost.

---

## 6. LocalStorage → MySQL / cache / removed

Unchanged from `docs/mysql-migration-audit.md` §17's classification-driven mapping (Audit §5's A/B/C/D/E legend) — restated briefly since this document supersedes that section's specifics for identity-bearing fields only:
- **A-classified fields** (`D.po`, `D.ceklis`, `D.kirim`, `D.invoice`, `D.pembayaran`, `D.retur`, `D.reject`, `D.jual`, `D.pesanan`, `D.mutasi`, `D.stokAdj`, `D.masterProduk`, `D.tokoTipe`, `D.settings`) → their respective MySQL tables per §2 above, now with numeric `product_id`/`store_id` FKs instead of raw strings.
- **`D.masterToko`/`D.tokoAlias`/`D.produkAlias`** → superseded entirely by `store`/`store_alias`/`product_alias`, built fresh per §1 (not a field-by-field carry-over, since — per Audit §10 — these were never reliably synced in the first place).
- **B/C/D/E-classified fields** → unchanged from the audit's original disposition (removed, become UI-only prefs, or dropped as legacy). Not repeated here.

---

## 7. Apps Script handler / frontend function → new API endpoint

Unchanged in spirit from `docs/mysql-migration-audit.md` §17's mapping table, now pointing at the finalized endpoint paths in `docs/php-api-contract-v1.md` instead of the earlier conceptual sketch. The **one structural change** worth calling out explicitly here: `psSimpan`/`psSyncInvoice` (which used to be one frontend action that both saved the order and created its invoice) splits into two independent new-frontend actions — `POST /api/customer-orders` (demand only) and, separately and later in the real business flow, `POST /api/customer-orders/{id}/fulfill` followed by `POST /api/invoices` — reflecting review point 3's rule, not a renaming exercise.

---

**Status: MYSQL DESIGN V1 FINAL-CANDIDATE READY FOR REVIEW.** No ETL has been executed; no legacy or live data has been read, copied, or modified as part of producing this document. Not implementation complete. Not production ready.
