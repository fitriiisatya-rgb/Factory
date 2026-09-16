# Amor Factory — MySQL Design V1: Open Decisions & Resolution Tracker

**Status: MYSQL DESIGN V1 READY FOR FINAL REVIEW**
This document is the single place to check what still needs a human sign-off before `database/schema-v1.sql` (draft) becomes a real migration, and what the review already resolved. Nothing in this document has been implemented or deployed.

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

---

## 3. Open decisions requiring explicit sign-off

### OD-1 — Historical Pesanan-derived invoices with no fulfillment evidence

**Context**: `docs/mysql-migration-map-v1.md` §3 case (b) — some legacy invoices created via the order-gated path have no `StokAdj`/shipment evidence of physical fulfillment at all. The migration plan synthesizes a dated `shipment` row at migration time and flags it.
**Decision needed**: is synthesizing a shipment record (flagged as such) acceptable for these historical rows, or should such invoices instead be migrated into a separate `is_legacy_unverified_fulfillment` marker without a synthetic shipment (breaking the "every invoice has a real shipment" invariant, but only for pre-cutover data, and only if the business considers a synthetic record worse than an exception flag)?
**Recommendation**: synthesize + flag (as currently written) — keeps exactly one query shape ("every invoice has ≥1 shipment") for reports across all history, with the flag providing the honesty a straight "no evidence" case deserves.

### OD-2 — Document number display format

**Context**: legacy formats are inconsistent (`DO/KRM/{seq:3}/{romawiBulan}/{yyyy}` vs `INV/KRM/{ddmmyy}/{seq:3}`, Audit §9).
**Decision needed**: (a) keep both legacy formats exactly, applied only to the newly-generated numeric sequence from `document_sequence`; (b) unify to one consistent format for both document types going forward, with historical numbers displayed as-migrated (never reformatted); (c) something else the business specifically wants (e.g. a factory-prefix, a location code).
**Recommendation**: (a) — least disruptive to anyone reading printed documents day-to-day, and `document_sequence`'s `(document_type, year, month, last_number)` shape supports either format equally; this is purely a PHP string-formatting choice once the decision is made.

### OD-3 — Server-side FG availability computation

**Context**: `docDocReady`'s `AvailableItemsJSON` is still client-computed today (Audit §12.1) and this review's point 3/schema work did not mandate changing it — `POST /api/delivery-orders/{id}/ready` in v1 still accepts `availableItems` from the caller (validated at `ship` time against the *stored* value, per the existing hardening — Audit §6.2).
**Decision needed**: is v1 acceptable with client-supplied-but-server-validated availability, or should `ready` instead compute `availableItems` itself from `fg_item` rows server-side (removing the caller's ability to supply it at all)?
**Recommendation**: ship v1 as specified (matches current, already-hardened behavior 1:1); revisit as a v1.1 hardening pass once the new backend is live and FG data is reliably flowing through `fg_item` — not a blocker for this migration's first cut.

### OD-4 — Confirm actual cPanel MySQL/MariaDB version

**Context**: `docs/mysql-schema-v1.md` §0/§11 — the DO-uniqueness generated-column approach needs MySQL 5.7.6+/MariaDB 10.2+.
**Decision needed**: run `SELECT VERSION();` against the real `factory.amorgroup.id` hosting database and confirm. This audit had no access to that environment.
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

### OD-9 — `store_id` on `shipment`/`customer_order` for walk-in customers — **recommendation pre-applied, needs confirmation**

**Context**: `docs/mysql-schema-v1.md` §5.6/§5.8 — some Pesanan (Customer Order) records have no real outlet/store (an individual walk-in customer).
**Applied**: the schema now defines `shipment.store_id`/`customer_order.store_id` as `NOT NULL`, resolving to a synthetic `store` row (`canonical_name = 'NON-OUTLET / PERORANGAN'`) rather than allowing `NULL` — avoids `NULL`-handling special cases across every report join in `docs/php-api-contract-v1.md` §11.
**Confirmation needed**: sign off that a synthetic store row is an acceptable modeling choice (vs. genuinely allowing `NULL` and handling it everywhere), and confirm the exact label text with PPIC/Finance before it appears on any printed report.

### OD-10 — Pre-existing failing UAT scripts (Audit §1.1/L1)

**Context**: `tests/uat-canonical-store.js` and `tests/uat-nav-compact-fixes.js` have known failures unrelated to this migration design (a signature drift from an earlier session's shipmentGroup feature).
**Decision needed**: fix these two test scripts (update them to the current 4-argument `kTarikDariFGBakery` signature) before or independently of the MySQL migration work, so the existing regression suite is fully green again as a trustworthy gate.
**Recommendation**: fix as a small, separate, low-risk housekeeping task — not blocking this design review, but should happen before the migration's Phase 0 (`docs/mysql-migration-audit.md` §18) begins, so the legacy system's own test suite can be trusted as a reference oracle during the ETL-validation process.

### OD-11 — Real user/role provisioning

**Context**: `users`/`roles`/`user_roles` (§15 of the schema) need actual people and role assignments — this design cannot invent them.
**Decision needed**: who are the initial `ADMIN` user(s), and how do the 7 seed roles map onto actual job titles/people at CV. Amor Group?
**Recommendation**: business input required — not something to guess or default.

### OD-12 — Overpayment tolerance on `payment`

**Context**: `docs/mysql-migration-audit.md` §9/§19 — the legacy app allows a payment exceeding an invoice's `sisa`, only warning, never blocking.
**Decision needed**: keep this tolerance in the new system (recommended — matches real business behavior where an advance/rounding overpayment isn't necessarily an error), or make it a hard block requiring explicit override?
**Recommendation**: keep the warning-only behavior (`docs/php-api-contract-v1.md` §8 already specifies this) unless Finance specifically wants stricter control.

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

- [ ] OD-1 through OD-12 above each have an explicit decision recorded (even if it's "accept the recommendation as-is")
- [ ] OD-13 (`po_closure`) and OD-14 (`shipment.status`) — already incorporated into `mysql-schema-v1.md`; confirm no objection
- [ ] OD-4 (DB version) confirmed against the real hosting environment
- [ ] Business sign-off on OD-11 (real users/roles) obtained separately from this technical review
- [ ] `database/schema-v1.sql` reviewed against any changes agreed above before it is used for anything beyond reading

Only after this checklist is complete should Phase 0 of `docs/mysql-migration-audit.md` §18 (data-quality pre-work) begin.

---

**Status: MYSQL DESIGN V1 READY FOR FINAL REVIEW.**
