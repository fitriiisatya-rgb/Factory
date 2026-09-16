# Amor Factory — MySQL Design V1: Open Decisions & Resolution Tracker

**Status: MYSQL DESIGN V1 FINAL-CANDIDATE READY FOR REVIEW**
This document is the single place to check what still needs a human sign-off before `database/schema-v1.sql` (draft) becomes a real migration, and what the review already resolved. Nothing in this document has been implemented or deployed. **This revision (the "FINAL DESIGN CORRECTION" pass) locks OD-1's synthesize-vs-flag question (now a three-case structure, never inventing evidence-free shipments), locks OD-3 (FG availability is now fully server-side), closes OD-9's contradiction (store_id NOT NULL is now consistent across every doc), resolves OD-10 (test fix committed), and reframes OD-4/OD-12 per explicit instruction — see each entry below for exactly what changed.**

---

## 1. How to use this document

- **§2** lists what the review request already resolved, with a pointer to where each is implemented in `docs/mysql-schema-v1.md` / `docs/php-api-contract-v1.md` / `docs/mysql-migration-map-v1.md`. No further decision needed on these unless someone disagrees with the resolution.
- **§3** lists genuinely open decisions (`OD-1` .. `OD-14`) — each has context, the options considered, and (where one is obviously better) a recommendation, but is left for explicit sign-off rather than silently decided, per this task's instruction to produce a design "ready for final review," not a fully executed decision set.
- **§4** carries forward risk items from `docs/mysql-migration-audit.md` §19 that this review's 16 points did not fully close, so nothing from the original audit is silently dropped.
- **§5** is the sign-off checklist for ending this phase.

---

## 2. Resolved by this review (no further decision needed)

| Audit §19 risk / prior open item | Resolution | Where |
|---|---|---|
| R1 — no canonical store identity persisted server-side | `store`/`store_alias` built fresh, human-reviewed via `migration_store_map`, never fuzzy-auto-merged | `mysql-schema-v1.md` §3–4, `mysql-migration-map-v1.md` §1 |
| R2 — product identity is name-string-based, proven kode-collision history | `product_id` surrogate PK, `kode` demoted to informational `product_legacy_code`, `migration_product_map` for resolution | `mysql-schema-v1.md` §2, `mysql-migration-map-v1.md` §1 |
| R6/R7 — DO/Invoice numbering not persisted, recomputed at render time | `document_sequence` table, server-side generation inside the same transaction as document creation | `mysql-schema-v1.md` §7 |
| R18 — no real authentication/authorization exists | `users`/`roles`/`user_roles` (+ future `user_factory_access`/`user_division_access`), 7 seed roles, per-endpoint role table | `mysql-schema-v1.md` §15, `php-api-contract-v1.md` §14 |
| R8 — concurrency: `expectedVersion` omission was allowed | Omission is now a hard `400 MISSING_VERSION` on every versioned entity, no exceptions | `mysql-schema-v1.md` §12, `php-api-contract-v1.md` §1.4 |
| R9 — idempotency key retention/behavior on mismatch was unspecified | `idempotency_log` with `request_fingerprint`; mismatch is a hard reject (`409 IDEMPOTENCY_KEY_REUSE_MISMATCH`), never silently accepted | `mysql-schema-v1.md` §13 |
| R10 — timezone handling unspecified | UTC for all `DATETIME`, Asia/Jakarta business `DATE` convention documented | `mysql-schema-v1.md` §14 |
| R14 — Trial destructive functions' production exposure | Not routed in production builds at all, `ADMIN`-only + `APP_ENV` gate, never a frontend-flag-only guard | `php-api-contract-v1.md` §13 |
| R21 — should `stock_adjustment` cascade-delete ever happen | Confirmed: never, in any environment; corrections are compensating rows | `mysql-schema-v1.md` §9 |
| R22 — Mutasi has no delete/reversal path | `stock_transfer` gains an explicit reversal mechanism, no hard delete | `mysql-schema-v1.md` §8 |
| L5/R20 — two invoice-creation triggers need reconciling | Shipment-gated is the only target-architecture default; order-gated path is migration-compatibility-only | `mysql-schema-v1.md` §5.6, `php-api-contract-v1.md` §8, `mysql-migration-map-v1.md` §3 |
| L12 — `stokGudang`/`mutasiStok` recompute over full history every call | `stock_ledger` (append-only) + `stock_balance` (rebuildable cache), indexed reads | `mysql-schema-v1.md` §6 |
| L13 — whole-sheet-rewrite pattern | Superseded entirely by row-level SQL operations | N/A (structural — MySQL doesn't have this problem) |
| L15 — `expectedVersion` omission unsafe allowance | Same as R8 above | |
| L7 — Master Produk/Toko delete has no backend endpoint | `DELETE /api/products/{id}` and store-merge endpoints now exist | `php-api-contract-v1.md` §3 |
| R18 (auth mechanism, FINAL DESIGN CORRECTION pass) — "Bearer token or session cookie, decided later" was ambiguous | **LOCKED: server-side PHP session**, not a bearer token. `password_hash()`/`password_verify()`, `session_regenerate_id(true)` on login, `Secure`/`HttpOnly`/`SameSite` cookie, CSRF token required on every mutating request (independent of Idempotency-Key), no session-support DB table needed (native PHP file-based session storage suffices on the single-server cPanel deployment) | `mysql-schema-v1.md` §15.1, `php-api-contract-v1.md` §1/§2 |
| Shipment modeling (FINAL DESIGN CORRECTION pass) — one `shipment` row per product conflated a physical delivery event with its line items | **LOCKED: `shipment` (header) + `shipment_item` (one row per product)**, 44→45 tables. MAIN/PASTRY remain separate shipments. Void is header-level only, with one compensating `stock_ledger` reversal row per original `shipment_item`, never a per-product void | `mysql-schema-v1.md` §5.6, `database/schema-v1.sql`, `php-api-contract-v1.md` §7/§8 |

---

## 3. Open decisions requiring explicit sign-off

### OD-1 — Historical Pesanan-derived invoices with no fulfillment evidence — **resolved, LOCKED three-case structure (FINAL DESIGN CORRECTION pass, review point 5)**

**Context**: `docs/mysql-migration-map-v1.md` §3 — some legacy invoices created via the order-gated path have no `StokAdj`/shipment evidence of physical fulfillment at all. The prior revision of this document synthesized a dated `shipment` row uniformly whenever evidence was "thin," which conflated two very different situations: reconstruction from real (if indirect) evidence, and pure invention with no evidence at all.
**Applied (no longer open)**: three cases, all documented in `mysql-schema-v1.md` §5.6.1 and `mysql-migration-map-v1.md` §3:
- **Case A** (real `Kirim`/shipment evidence) → normal migrated `shipment`, `legacy_fulfillment_status='verified'`.
- **Case B** (Pesanan-derived, `StokAdj`/other reliable stock-out evidence exists) → synthesize a `shipment` from that evidence, `legacy_fulfillment_status='reconstructed'`.
- **Case C** (no shipment/`StokAdj`/reliable evidence at all) → **do NOT invent a shipment.** Invoice + financial history migrate as-is with `legacy_fulfillment_status='unverified'`, no `shipment`/`stock_ledger` row is created, excluded from fulfillment KPIs by default, clearly flagged in migration reporting. New PHP API code can never create an invoice in this state.
**Confirmation needed**: none technical — this is a correction of an internal inconsistency, not a business trade-off. Business should be informed that Case C invoices exist in the historical data (a real, surfaced data-quality finding) before cutover, but no decision is pending on the mechanism itself.

### OD-2 — Document number display format

**Context**: legacy formats are inconsistent (`DO/KRM/{seq:3}/{romawiBulan}/{yyyy}` vs `INV/KRM/{ddmmyy}/{seq:3}`, Audit §9).
**Decision needed**: (a) keep both legacy formats exactly, applied only to the newly-generated numeric sequence from `document_sequence`; (b) unify to one consistent format for both document types going forward, with historical numbers displayed as-migrated (never reformatted); (c) something else the business specifically wants (e.g. a factory-prefix, a location code).
**Recommendation**: (a) — least disruptive to anyone reading printed documents day-to-day, and `document_sequence`'s `(document_type, year, month, last_number)` shape supports either format equally; this is purely a PHP string-formatting choice once the decision is made.

### OD-3 — Server-side FG availability computation — **resolved, LOCKED (FINAL DESIGN CORRECTION pass, review point 3)**

**Context**: `docDocReady`'s `AvailableItemsJSON` was client-computed in the legacy app (Audit §12.1), and the prior revision of this contract still accepted `availableItems` from the caller on `POST /api/delivery-orders/{id}/ready`, validated only at `ship` time.
**Applied (no longer open)**: `POST /api/delivery-orders/{id}/ready` request body is now `{version}` only — **no client-supplied `availableItems` anywhere in the API.** The server loads the DO + items, queries the authoritative `fg_batch`/`fg_item` rows (matched by date/store/`shipment_group`/product and FG-ready-state), computes each item's available quantity itself, validates against `planned_qty`, persists the result onto `delivery_order_item.available_qty`, and transitions the DO atomically (incrementing `version`, writing `audit_log`). Ship-time validation (`actualShipQty <= plannedQty` AND `<= server-computed availableQty`) is unchanged.
**Confirmation needed**: none technical — this closes the client-trust gap outright rather than deferring it to a v1.1 pass.

### OD-4 — Confirm actual cPanel MySQL/MariaDB version — **CLOSED / VERIFIED ON REAL CPANEL HOSTING (Phase 0.5)**

**Context**: `docs/mysql-schema-v1.md` §0/§11 — the DO-uniqueness generated-column approach needs MySQL 5.7.6+/MariaDB 10.2+.
**Resolved**: the human operator ran `SELECT VERSION();` against the real `factory.amorgroup.id` cPanel hosting database (database `u7566812_factory`, host `localhost` from the app's perspective, i.e. the standard cPanel same-host MySQL socket/TCP setup) and reported the result back:

```
10.11.19-MariaDB-cll-lve
```

(`cll-lve` is CloudLinux's package/LVE tag — a packaging label, not a distinct SQL dialect; the SQL feature set is standard MariaDB 10.11.)

**This is the evidence that closes OD-4** — not the disposable local MariaDB instances used during design/Phase 0 validation (those never claimed to be evidence about the real host, and are not being reinterpreted as such now). The real host is MariaDB **10.11.19**, well above every version floor this schema depends on:

| Feature this schema uses | Floor required | Real host (10.11.19) |
|---|---|---|
| InnoDB (transactions, row locking, `SELECT ... FOR UPDATE`) | any supported version | ✅ default engine |
| Generated (`GENERATED ALWAYS AS ... STORED`) columns | MariaDB 10.2.0 | ✅ (10.11.19 ≫ 10.2.0) |
| `UNIQUE` index on a `STORED` generated column, multi-NULL-safe | MariaDB 10.2.0 | ✅ |
| `JSON` column (`idempotency_log.response_body`) | MariaDB 10.2.7 (aliased to `LONGTEXT` + `JSON_VALID` `CHECK`) | ✅ — read/written whole in this design, never queried with JSON path functions, so the alias behavior is irrelevant either way |
| Foreign keys, `ON DELETE CASCADE`/`RESTRICT` | InnoDB, any supported version | ✅ |
| `SELECT ... FOR UPDATE` (§7 document-numbering fallback path) | InnoDB, any supported version | ✅ |

**Full compatibility pass result**: see `docs/mysql-schema-v1.md` §0 and §18.1 (Phase 0.5 addendum) for the line-by-line review of `database/schema-v1.sql` against 10.11.19 specifically (ENUM syntax, generated-column expression, FK constraint name lengths, utf8mb4 index-prefix limits, reserved words, `CHECK` constraints, `AUTO_INCREMENT BIGINT UNSIGNED`, `DECIMAL`/`DATETIME` usage, `document_sequence`, `idempotency_log.response_body`). **Conclusion: no MySQL-only or version-sensitive constructs found; zero patches required.** `database/schema-v1.sql` is no longer gated by OD-4.
**What remains true**: this closes the *version-compatibility* question only. The schema has still never been applied to the real `u7566812_factory` database as of this entry — that is Phase 0.5's actual apply step, tracked separately (see `api/DEPLOY-CPANEL-PREPROD.md`), and is not implied by this OD's closure.
**Recommendation**: do this before writing any real (non-draft) DDL — it is a 30-second check that gates §11's design choice and is the only version-sensitive part of the entire schema.

### OD-5 — Quantity column type (`DECIMAL(12,2)` vs `INT`)

**Context**: `docs/mysql-schema-v1.md` §1 chose `DECIMAL(12,2)` for all quantities because the legacy app never enforced integer-only qty at the type level.
**Decision needed**: confirm with the business whether every quantity in this system (production units, shipment pcs, stock adjustments) is always a whole number in practice. If yes, `INT` is simpler and slightly cheaper; if any workflow ever uses fractional units (e.g. weight-based product lines), `DECIMAL` is required.
**Recommendation**: keep `DECIMAL(12,2)` unless the business explicitly confirms whole-units-only for 100% of products — cheap insurance, `DECIMAL` behaves identically to `INT` for whole-number data.

### OD-6 — Retention/archival policy for `audit_log` and `idempotency_log`

**Context**: Audit §14 L14/R9 — unbounded growth was a known risk in the legacy system.
**Decision needed**: `audit_log` — keep forever (recommended, it's evidentiary) with a partitioning-by-month strategy for query performance once it's large, or actively archive-and-purge after N years? `idempotency_log` — how long is a safe retention window (unlike `audit_log`, this one is safe to prune once its replay-protection purpose is served, likely 30–90 days is generous given the legacy `CACHE_TTL_SEC` was only 6 hours)?
**Recommendation**: `audit_log` — keep forever, add date-range partitioning once row count justifies it (not needed at launch). `idempotency_log` — a scheduled job prunes rows older than 90 days.

### OD-7 — Staging/production build separation mechanism for trial endpoints

**Context**: `docs/php-api-contract-v1.md` §13 says trial endpoints are "not routed" in production, not merely permission-denied.
**Decision needed**: is this achieved via (a) a genuinely separate deployment artifact/branch for staging vs. production, (b) one codebase with route registration conditioned on a server-side config value read at boot (not per-request), or (c) something else the hosting setup (single cPanel account for `factory.amorgroup.id`) makes easier?
**Recommendation**: (b) is simplest for a single-cPanel-host deployment — router registration checks `APP_ENV` once at application bootstrap, not per-request — as long as the config value lives in a file/env var outside the web root and is not client-settable by any means. Needs sign-off from whoever manages the actual cPanel deployment process.

### OD-8 — Unused `KATALOG_BAWAAN` catalog entries

**Context**: `docs/mysql-migration-map-v1.md` §4 — 472 built-in products, unknown how many are actually ever used.
**Decision needed**: migrate all 472 as `aktif=0` reference rows, or migrate only the subset that appears in real transactional history and let the rest be re-added on demand from the same source list if ever needed?
**Recommendation**: migrate only the used subset for the live `product` table (keeps the catalog meaningfully "real"); keep the full 472-entry list available as a separate importable reference/seed file (not a live table) for anyone who wants to bulk-add a dormant product later.

### OD-9 — `store_id` on `shipment`/`customer_order` for walk-in customers — **LOCKED (FINAL DESIGN CORRECTION pass, review point 2)**

**Context**: `docs/mysql-schema-v1.md` §5.6/§5.8.1 — some Pesanan (Customer Order) records have no real outlet/store (an individual walk-in customer). **The prior revision of this design had an internal contradiction**: `mysql-schema-v1.md`/this document described `store_id` as `NOT NULL` resolving to a synthetic store, while `php-api-contract-v1.md` still described the fulfillment endpoint's resulting `shipment.storeId` as nullable. That contradiction is what this pass fixes — it is not a new decision, it is closing a docs-disagreement bug.
**Applied (no longer open)**: `shipment.store_id` and `customer_order.store_id` are `NOT NULL` in the schema and the DDL, full stop — there is no code path, migrated or new, that inserts NULL into either column. A synthetic `store` row (`canonical_name = 'NON-OUTLET / PERORANGAN'`, `channel = NULL`, `active = 1`) is seeded before any other store row. The API may accept `storeId` as optional in request bodies for walk-in customer orders/shipments (`POST /api/customer-orders`, `POST /api/shipments`), but the **server** always resolves the omitted value to the synthetic store's `store_id` before the INSERT — this is now consistent across `mysql-schema-v1.md`, `database/schema-v1.sql`, `php-api-contract-v1.md`, and `mysql-migration-map-v1.md`.
**Confirmation needed**: confirm the exact label text (`'NON-OUTLET / PERORANGAN'`) with PPIC/Finance before it appears on any printed report — a cosmetic/business-wording sign-off, not a technical one.

### OD-10 — Pre-existing failing UAT scripts (Audit §1.1/L1) — **RESOLVED**

**Context**: `tests/uat-canonical-store.js` and `tests/uat-nav-compact-fixes.js` had known failures unrelated to this migration design.
**Resolved**: fixed in commit `b04cd53`, as its own commit separate from the schema-correction work (it touches test/application-adjacent source, not the design docs). Both scripts are now fully green: `uat-canonical-store.js` 31/31 PASS, `uat-nav-compact-fixes.js` 46/46 PASS. Root cause was actually **two** distinct, previously-conflated issues: (1) `kTarikDariFGBakery` gained a required 3rd `shipmentGroup` argument in an earlier session, which changed the `dikirimKe` marking format the tests asserted on; (2) an unrelated stray over-stock qty left on a different product line in the nav-compact-fixes test setup, which `kSimpan()`'s pre-existing stock validation correctly blocked (surfaced only after fixing (1), and only in that one test). Only the test scripts were changed — no application/business behavior was altered to make tests pass, per instruction.
**Note for the record**: while re-running the full suite, `tests/uat.js`'s `K.invoicePriceRule` check (a stale 55%-Ownership-era pricing assertion, expects 2750 got 2500) was found still failing. This is a pre-existing condition, untouched by this session, and out of scope for OD-10 (which named only the two scripts above) — flagged here for visibility, not fixed.

### OD-11 — Real user/role provisioning

**Context**: `users`/`roles`/`user_roles` (§15 of the schema) need actual people and role assignments — this design cannot invent them.
**Decision needed**: who are the initial `ADMIN` user(s), and how do the 7 seed roles map onto actual job titles/people at CV. Amor Group?
**Recommendation**: business input required — not something to guess or default.

### OD-12 — Overpayment tolerance on `payment` — **NOT redesigned this pass; BUSINESS CONFIRMATION REQUIRED BEFORE PRODUCTION**

**Context**: `docs/mysql-migration-audit.md` §9/§19 — the legacy app allows a payment exceeding an invoice's `sisa`, only warning, never blocking.
**Applied this pass**: explicitly kept as-is (warning-only, `docs/php-api-contract-v1.md` §8) — not redesigned, per instruction.
**Status, explicitly**: this is **BUSINESS CONFIRMATION REQUIRED BEFORE PRODUCTION**, not a blocker for the schema/PHP staging build. The design is allowed to proceed into staging with the current warning-only behavior; it must not go to production without an explicit Finance sign-off on whether this tolerance is still wanted or should become a hard block.
**Decision needed (before production only)**: keep the warning-only tolerance (matches real business behavior where an advance/rounding overpayment isn't necessarily an error), or make it a hard block requiring explicit override?
**Recommendation**: keep the warning-only behavior unless Finance specifically wants stricter control.

### OD-13 — `D.kirimClosed` ("pesanan ditutup" per store+date) — **resolved, added to schema**

**Context**: `docs/mysql-migration-audit.md` §5/§17 flagged `D.kirimClosed` (`{"tgl|toko": true}`) as needing a migration decision.
**Applied**: `docs/mysql-schema-v1.md` §5.2.2 now includes `po_closure(tanggal, store_id, closed_at, closed_by, reopened_at, reopened_by)`, mirroring both `kTutupToko` (close) and `kBukaTokoKembali` (reopen) as one toggleable row per `(tanggal, store_id)` rather than deleting/recreating.
**Confirmation needed**: none technical — sign off that this is still a wanted business action in the target system (if PPIC confirms "pesanan ditutup" is no longer needed post-migration, this table can simply go unused, at negligible cost).

### OD-14 — Hard delete vs. soft-cancel for `shipment` — **resolved, soft-cancel applied**

**Context**: the legacy `hapusKirim` operation physically removes a `Kirim` row.
**Applied**: `docs/mysql-schema-v1.md` §5.6 now defines `shipment.status ENUM('active','void')`. "Deleting" a shipment sets `status='void'` and writes a compensating `stock_ledger` reversal in the same transaction; the row itself is never physically removed, so `invoice_shipment`/`stock_transfer.source_shipment_id` references never dangle. `docs/php-api-contract-v1.md` §8's `DELETE /api/shipments/{id}` implements this internally as the soft-cancel, not a row removal.
**Confirmation needed**: none technical — this is a strict improvement over the legacy hard-delete behavior (nothing is lost that was previously kept), so sign-off here is a formality unless there's a specific reason void'd shipments must not remain queryable at all (e.g. a regulatory requirement to purge — not currently known to exist).

---

## 4. Carried-forward risks from the original audit not fully closed by this review

| Audit §19 item | Status |
|---|---|
| R3 — ETL must be idempotent/re-runnable without duplicating rows | Not yet specified as a concrete mechanism — the ETL script design (not just the target schema) needs its own idempotency discipline (e.g., keyed upserts against `legacy_ref`/natural keys, not blind `INSERT`s), to be detailed when the actual ETL script is written (out of scope for this schema-design pass) |
| R4 — historical Kirim rows without `ShipmentGroup` | Resolved in the mapping (`mysql-migration-map-v1.md` §2, defaults to `MAIN`) — no further decision needed, listed here only for completeness since it's an ETL detail rather than a schema one |
| R12 — numeric precision / rounding rule verification | `docs/mysql-schema-v1.md` §1 sets `DECIMAL(14,2)` for money — the exact rounding function (`hargaPabrik`'s `round()`) must be verified against MySQL's own rounding behavior (`ROUND()`) or done exclusively in PHP before insert, to avoid a double-rounding discrepancy; not yet decided which layer owns rounding |
| R13 — FK fallback bucket for ambiguous historical rows | Addressed structurally by `mysql-migration-map-v1.md` §1.5 (placeholder record), but the exact placeholder-record policy (one shared placeholder vs. one per ambiguous cluster) is not yet decided |
| R19 — pre-existing failing UAT checks | See OD-10 above |

---

## 5. Sign-off checklist to close this phase

- [x] OD-1 (historical invoice evidence, three-case structure) — LOCKED this pass, no further decision needed
- [x] OD-3 (FG availability) — LOCKED this pass, server-side computation, no further decision needed
- [x] OD-9 (`store_id` NOT NULL) — LOCKED this pass, contradiction closed, only the cosmetic label text needs PPIC/Finance confirmation
- [x] OD-10 (stale UAT scripts) — RESOLVED, commit `b04cd53`
- [x] **OD-4 (DB version) — CLOSED / VERIFIED ON REAL CPANEL HOSTING (Phase 0.5): `10.11.19-MariaDB-cll-lve`, full compatibility pass found zero patches required**
- [ ] OD-2, OD-5, OD-6, OD-7, OD-8 each have an explicit decision recorded (even if it's "accept the recommendation as-is")
- [ ] OD-13 (`po_closure`) and OD-14 (`shipment.status`) — already incorporated into `mysql-schema-v1.md`; confirm no objection
- [ ] Business sign-off on OD-11 (real users/roles) obtained separately from this technical review
- [ ] **OD-12 (overpayment tolerance) — BUSINESS CONFIRMATION REQUIRED BEFORE PRODUCTION** (not a blocker for schema/PHP staging build; may remain unchecked through staging)
- [ ] `database/schema-v1.sql` reviewed against any changes agreed above before it is used for anything beyond reading

OD-4 no longer gates applying `database/schema-v1.sql` to the real `u7566812_factory` database — but that apply is a separate, not-yet-performed step (see `api/DEPLOY-CPANEL-PREPROD.md`), gated instead by the Phase 0.5 database-safety-gate and the human operator following that procedure. Note: OD-11 gates real (non-draft) DDL going further than infrastructure; OD-12 gates production only, not staging/preproduction.

---

## 6. Phase roadmap (updated Phase 0.5)

- **Phase 0 — DONE.** PHP + MySQL staging skeleton (infrastructure only): cross-cutting auth/CSRF/idempotency/versioning/audit, health + master-read + minimal product/store CRUD endpoints, 17/17 local integration tests green. Commit `1f307da`.
- **Phase 0.5 — DONE.** Real cPanel database + API deployment preparation: OD-4 closed against the real host (`10.11.19-MariaDB-cll-lve`, database `u7566812_factory`), MariaDB-10.11.19-specific compatibility pass on `database/schema-v1.sql` (zero patches required), a database safety gate added to the migration runner, migration-user/runtime-user privilege split, a cPanel easy-install package (`dist/amor-factory-api-preprod.zip`) with a guided setup wizard. Reported by the human operator as applied to the real host: 45/45 schema, seed 2/7/1, admin created, runtime DB user active, `/api/health` OK, setup wizard disabled/deleted, baseline backup (`u7566812_factory.sql.gz`) downloaded.
- **Phase 1 fast-track — MASTER + IDENTITY + LEGACY MAPPING — THIS PASS.** Explicitly, all delivered this pass:
  - Division + factory master: added `division.factory_id` (migration `0002_master_identity`) and seeded all 8 real divisions (`Roti & Bollen`, `Basic`, `Donat/Mochi/AKB`, `Pastry`, `Cookies`, `Bolu`, `Finishgood & Packing`, `Finishgood & Packing (Cibadak)`) with factory/`is_verification` derived directly from `divisiDari()`/`fgFactoryForDivisi()`/`divisiVerifikasi()` in the frontend source — not invented, not assumed from the request text alone. **`Cookies` was found in source but not named in the request's own "expected" list — included anyway per the explicit "audit source, don't invent from the prompt" instruction.**
  - Product identity: extracted the real `KATALOG_BAWAAN` (472 rows, zero blanks, zero duplicate codes, zero duplicate names) via `dist/tools/extract-legacy-source.php` into `database/legacy/phase1-source-v1.json`, imported through `Amor\Api\Import\Phase1Importer` with SAFE/REVIEW/CONFLICT classification (no fuzzy auto-merge — a name or code collision is always staged into `migration_product_map` for human resolution, never resolved automatically).
  - Store identity: seeded the one store alias group actually hardcoded in the frontend source (`bootstrapTokoCanonicalDikenal()`: `CKLE`/`CIKOLE` → `BAKERY CIKOLE`). **Did NOT fabricate `SDRM → BAKERY SUDIRMAN`** — that example from the request text has no corroborating occurrence anywhere in the current frontend/test source, and this repo has no access to the live Sheets-backed store list to verify it independently; it is left for a human to add via `POST /api/stores` once confirmed, not silently assumed.
  - Admin review API: `GET/POST /api/admin/migration/{products,stores}` (`resolve`, `flag-conflict`), ADMIN-only, CSRF, Idempotency-Key, audited, transactional, never auto-resolving.
  - Master API completion: `GET /api/products/{id}`, `GET /api/stores/{id}` added.
  - Fast-track web tooling: `api/_import-master/` (ADMIN-session-gated, one-time, meant to be deleted after this phase) and `api/_upgrade/` (ADMIN-session-gated, designed to remain permanently for future incremental migrations — see its own header comment for why that's safe).
  - Incremental cPanel package: `dist/amor-factory-api-phase1-incremental.zip` + `dist/README-FIRST-CPANEL-PHASE1.md`, verified in this pass to overwrite application code while leaving an existing `app/config/config.php` byte-for-byte untouched.
  - **DO lifecycle, shipment, stock, PO, production, FG, and invoice remain explicitly NOT done.** No transaction table received a single row from this pass (verified by automated test P1-16). Frontend cutover and Apps Script retirement remain untouched.
- Phase 1's actual real-database apply (running the incremental package's `_upgrade/` + `_import-master/` against `u7566812_factory`) is the human operator's next action, following `dist/README-FIRST-CPANEL-PHASE1.md` — not yet performed as part of producing this pass.

---

**Status: MYSQL DESIGN V1 FINAL-CANDIDATE READY FOR REVIEW.** Not implementation complete. Not production ready. OD-4 is now CLOSED (see above) — the real cPanel database version is confirmed and schema-compatible; the schema itself has not yet been applied there.
