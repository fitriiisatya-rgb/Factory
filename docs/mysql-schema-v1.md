# Amor Factory — MySQL Schema Design V1

**Status: MYSQL DESIGN V1 FINAL-CANDIDATE READY FOR REVIEW**
Not implementation complete. Not production ready. No SQL has been deployed. No live database exists yet. This document finalizes the conceptual schema from `docs/mysql-migration-audit.md` §15 based on the first review pass, then applies a second, corrective round of locked decisions (shipment header/item normalization, the store_id NOT NULL contradiction, server-side FG availability, session-based auth, the historical-invoice-without-evidence exception, and more — see each numbered section for "review point N"). A companion draft-only DDL file is at `database/schema-v1.sql` (explicitly marked not for production, not deployed).

This document assumes the reader has `docs/mysql-migration-audit.md` open for cross-reference (cited as "Audit §N" throughout) and does not re-derive facts already established there.

---

## 0. Target database version (must be confirmed before `database/schema-v1.sql` is finalized)

This design has **not** been validated against the actual cPanel MySQL/MariaDB instance for `factory.amorgroup.id` — that instance's version was not accessible from this audit environment. Before the draft DDL is treated as anything more than a draft, run:

```sql
SELECT VERSION();
SHOW VARIABLES LIKE 'version%';
```

and confirm against the feature list below. Everything in this design works on **MySQL 5.7.8+ or MariaDB 10.2+** (the two lowest versions that support generated/virtual columns, which §11's DO-uniqueness design relies on as the primary approach) with one explicitly-documented fallback for anything older (§11). If the host is confirmed to run MySQL 8.0+/MariaDB 10.5+, nothing below needs to change — those also support everything used here, including `JSON` columns (used sparingly, see §5.6) and `CHECK` constraints (MySQL 8.0.16+/MariaDB 10.2+; treated as advisory/documentation-only below since older-but-supported versions silently ignore `CHECK` — see §12).

**Features this design relies on and their minimum version:**

| Feature | Used for | Min. MySQL | Min. MariaDB |
|---|---|---|---|
| Generated (virtual/stored) columns | §11 partial-unique DO index | 5.7.6 | 10.2.0 (persistent), 5.2.0 (virtual, but virtual columns can't be indexed before 10.2 — see §11) |
| `UNIQUE` index treating multiple `NULL`s as non-conflicting | §11 partial-unique pattern, `product_alias`/`store_alias` optionality | All supported versions | All supported versions |
| `JSON` column type | §5.6 (only two columns in the whole schema) | 5.7.8 | 10.2.7 (as a real type; earlier MariaDB aliases `JSON` to `LONGTEXT` with `JSON_VALID` check — also acceptable here since these columns are read/written whole, never queried with JSON path functions) |
| `InnoDB` row-level locking + transactions | §7, §11 (`SELECT ... FOR UPDATE`) | All supported versions (InnoDB is default since 5.5) | All supported versions |
| `CHECK` constraints | Advisory only, see §12 | 8.0.16 (enforced) | 10.2.1 (enforced) |

**If the confirmed version is older than 5.7/10.2** (unlikely on a current cPanel host, but not verified): the one required change is §11's DO-uniqueness index, which falls back to pure application-level enforcement (documented inline). Nothing else in this design depends on a version floor above baseline InnoDB.

**Validation performed (this pass, re-run after the shipment/store/auth corrections below)**: `database/schema-v1.sql` was applied against a disposable, local-only MariaDB 10.11.14 instance (spun up solely for this syntax/behavior check, then fully torn down immediately after — no live or persistent database was created or touched) to confirm the DDL is actually valid, not just plausible-looking. Result: all 45 tables created with zero errors; see §18 for the full list of behavioral checks re-run against this instance (shipment header+items, header-level void, synthetic non-outlet store, duplicate-open-DO blocking, and more).

**This validates the design's internal correctness on MariaDB 10.11 — it proves nothing whatsoever about the actual `factory.amorgroup.id` cPanel database.** OD-4 (confirm `SELECT VERSION();` on the real host) is explicitly, deliberately kept **open** per review point 15 — the convenience of a disposable local instance must never be mistaken for, or silently substituted for, confirmation of the real target environment. `database/schema-v1.sql` remains a **draft** until that confirmation happens, full stop, regardless of how many times it validates cleanly elsewhere.

---

## 1. Conventions used throughout this schema

- All table and column names: `snake_case`.
- Every table has a surrogate `BIGINT UNSIGNED AUTO_INCREMENT` primary key named `<table>_id`, **except** where a natural composite key is explicitly more correct (documented per-table below — e.g. `document_sequence`, `user_roles`).
- Every table that supports `UPDATE` (i.e., has a lifecycle, not pure append) has a `version INT UNSIGNED NOT NULL DEFAULT 1` column — see §7. Pure append-only tables (`stock_ledger`, `audit_log`, `idempotency_log`, and line-item child tables that are only ever inserted once with their parent and never independently edited) do **not** get a `version` column.
- `created_at DATETIME NOT NULL` / `updated_at DATETIME NULL` are UTC on every table that has them — see §9. Business-operational dates (`tanggal`/`event_date`/etc.) are `DATE` columns representing the Asia/Jakarta calendar date — see §9.
- Money columns: `DECIMAL(14,2)`. Quantity columns: `DECIMAL(12,2)` (kept decimal, not `INT`, because the legacy app never enforced integer-only quantities at the type level and this audit found no rule requiring it — revisit only if the business confirms all quantities are always whole units).
- Every `ENUM` used below is a closed, small, stable set transcribed from the current source (Audit §6/§7/§8/§9) — not invented.
- Foreign keys are `RESTRICT` on delete by default (never silently cascade-delete a transactional row because a master record was removed) unless explicitly noted otherwise (child line-items of a parent document `CASCADE` with their parent, since they have no independent lifecycle).

---

## 2. Product identity (review point 1)

| Decision | Applied |
|---|---|
| Numeric/surrogate `product_id` is the authoritative PK | ✅ |
| `kode` is **not** authoritative identity | ✅ — stored only as informational/legacy reference (§2.1) |
| Product name is **not** the PK | ✅ — `name` carries a `UNIQUE` constraint as a *target-state* invariant once migrated data is clean, but is never the join key from any other table |
| Aliases live in `product_alias` | ✅ |
| Historical ambiguity is never fuzzy-auto-merged | ✅ — all ambiguous historical raw names go through `migration_product_map` (§4) for human resolution; nothing in this schema or its ETL performs a similarity-threshold auto-merge |

```
product
  product_id       BIGINT UNSIGNED  PK AUTO_INCREMENT
  name             VARCHAR(255)     NOT NULL         -- canonical display name
  kategori         VARCHAR(100)     NULL
  division_id      BIGINT UNSIGNED  NULL  FK -> division.division_id
  hpp              DECIMAL(14,2)    NOT NULL DEFAULT 0
  harga            DECIMAL(14,2)    NOT NULL DEFAULT 0   -- harga100 (consumer price), Audit §9
  aktif            TINYINT(1)       NOT NULL DEFAULT 1
  version          INT UNSIGNED     NOT NULL DEFAULT 1
  created_at       DATETIME         NOT NULL
  updated_at       DATETIME         NULL
  UNIQUE KEY uq_product_name (name)
```

### 2.1 `product_legacy_code` — informational only, never a lookup key

Real historical PO files reuse one `kode` for two different products (Audit §10, `tests/uat-sku-identity.js`). Modeling `kode` as a 1:1 column on `product` would either lose that history or force a false uniqueness. Instead:

```
product_legacy_code
  id               BIGINT UNSIGNED  PK AUTO_INCREMENT
  product_id       BIGINT UNSIGNED  NOT NULL  FK -> product.product_id
  legacy_code      VARCHAR(64)      NOT NULL
  first_seen_at    DATE             NULL
  created_at       DATETIME         NOT NULL
  KEY ix_legacy_code (legacy_code)          -- indexed for lookup/reporting, NOT unique (a code can legitimately point at >1 product historically)
```

This table exists purely so a legacy `kode` can be looked up for *display/reference* ("what codes has this product ever been called") — application code must never use it as a join key to resolve a product.

### 2.2 `product_alias`

```
product_alias
  product_alias_id BIGINT UNSIGNED  PK AUTO_INCREMENT
  product_id       BIGINT UNSIGNED  NOT NULL  FK -> product.product_id
  raw_name         VARCHAR(255)     NOT NULL         -- stored normalized (uppercase/trim/collapse-space, matching normNama — Audit §10)
  source           VARCHAR(50)      NULL             -- e.g. 'po_import_confirmed', 'migration'
  created_at       DATETIME         NOT NULL
  UNIQUE KEY uq_product_alias_raw (raw_name)   -- one raw name resolves to exactly one product, enforced by the DB, not just app discipline
```

A raw name that would map to two different products is exactly the "conflict" case §4's staging table exists to catch *before* it ever reaches this table.

### 2.3 Product delete rule (review point 11, LOCKED)

`DELETE /api/products/{id}` (`docs/php-api-contract-v1.md` §3) is only permitted for a `product` that has **never** been referenced by any transactional row — the API checks every FK-referencing table (`po_item`, `production_item`, `fg_item`, `delivery_order_item`, `shipment_item`, `invoice_item`, `return_note`, `reject_note`, `retail_sale`, `stock_transfer`, `stock_adjustment`, `customer_order_item`, `stock_ledger`) before allowing the delete, and rejects with `409 PRODUCT_IN_USE` if any reference exists at all — even a single historical row. **A referenced product is retired via `aktif=0`, never deleted.** No FK on any table above ever cascades a `product` deletion into transactional rows — every FK from a transactional table to `product` is `RESTRICT` (or omitted `ON DELETE` entirely, which defaults to `RESTRICT` in InnoDB), never `CASCADE`, never `SET NULL`.

---

## 3. Store identity (review point 2)

Mirrors §2 exactly, with `store` replacing `product`.

```
store
  store_id         BIGINT UNSIGNED  PK AUTO_INCREMENT
  canonical_name   VARCHAR(255)     NOT NULL
  channel          ENUM('ownership','franchise') NULL   -- Audit §9: reporting/grouping metadata ONLY, never feeds pricing
  active           TINYINT(1)       NOT NULL DEFAULT 1
  version          INT UNSIGNED     NOT NULL DEFAULT 1
  created_at       DATETIME         NOT NULL
  updated_at       DATETIME         NULL
  UNIQUE KEY uq_store_canonical_name (canonical_name)
```

```
store_alias
  store_alias_id   BIGINT UNSIGNED  PK AUTO_INCREMENT
  store_id         BIGINT UNSIGNED  NOT NULL  FK -> store.store_id
  raw_name         VARCHAR(255)     NOT NULL         -- normalized (matching normTokoKunci — Audit §10)
  factory_hint     ENUM('karangtengah','cibadak') NULL  -- optional provenance: which factory's PO files used this spelling (informational, not a constraint)
  created_at       DATETIME         NOT NULL
  UNIQUE KEY uq_store_alias_raw (raw_name)
```

**Canonical store is built fresh** (per review point 2 and Audit §10's finding that `D.masterToko` has zero backend persistence today) — there is no legacy `store`/`store_alias` table to migrate row-for-row; §4 and `docs/mysql-migration-map-v1.md` describe the one-time reconciliation process that populates these two tables from historical raw `toko` strings.

### 3.1 Store delete rule (review point 11, LOCKED)

Same principle as §2.3, applied to `store`: **a historically-referenced store is never hard-deleted.** `docs/php-api-contract-v1.md` §3 exposes only `active=0` (retire) and `POST /api/stores/{id}/merge` (alias consolidation into another canonical store) — there is no `DELETE /api/stores/{id}` endpoint at all in this design, because unlike `product` (which can legitimately have zero historical references if created and abandoned same-day), a `store` row reachable by the API has, in practice, always already been referenced by at least a `store_alias` harvested from real data by the time a human could act on it. Every FK from a transactional table to `store` is `RESTRICT`, never `CASCADE`/`SET NULL` — no master-data deletion can cascade into `shipment`, `customer_order`, `po_store_item`, `return_note`, `reject_note`, `retail_sale`, `stock_transfer`, or `po_closure`.

---

## 4. Migration staging tables (review point 2's explicit ask)

These two tables exist **only** for the one-time (or repeatable, if run in dry-run mode multiple times before final cutover) identity-resolution pass described in `docs/mysql-migration-map-v1.md` Phase 0. They are not part of the live application's runtime data model, but they are **not dropped** after migration either — they remain as the permanent audit trail of how every historical raw name was resolved (or explicitly left unresolved/conflicted), in case a historical report is ever questioned.

```
migration_product_map
  id                  BIGINT UNSIGNED  PK AUTO_INCREMENT
  raw_name            VARCHAR(255)     NOT NULL
  raw_code            VARCHAR(64)      NULL
  source_table        VARCHAR(50)      NULL   -- e.g. 'PO','Kirim','MasterProduk','Ceklis' — which legacy sheet this raw value was harvested from (traceability aid, not required by the review but low-cost and directly useful during resolution)
  occurrence_count    INT UNSIGNED     NOT NULL DEFAULT 1   -- how many historical rows use this raw_name+raw_code combination (helps prioritize review — high-occurrence unresolved rows first)
  target_id           BIGINT UNSIGNED  NULL     FK -> product.product_id
  status              ENUM('mapped','unresolved','conflict') NOT NULL DEFAULT 'unresolved'
  resolved_by         VARCHAR(100)     NULL     -- human identifier (name/username), never auto-filled by a fuzzy-match process
  resolved_at         DATETIME         NULL
  notes               TEXT             NULL
  created_at          DATETIME         NOT NULL
  UNIQUE KEY uq_migration_product_raw (raw_name, raw_code)
```

```
migration_store_map
  id                  BIGINT UNSIGNED  PK AUTO_INCREMENT
  raw_name            VARCHAR(255)     NOT NULL
  raw_code            VARCHAR(64)      NULL      -- rarely populated (stores don't have a "kode" in the current app), kept for symmetry/future external-system imports
  source_table        VARCHAR(50)      NULL      -- e.g. 'PO','Kirim','Retur','D.masterToko(local export)'
  occurrence_count    INT UNSIGNED     NOT NULL DEFAULT 1
  target_id           BIGINT UNSIGNED  NULL      FK -> store.store_id
  status              ENUM('mapped','unresolved','conflict') NOT NULL DEFAULT 'unresolved'
  resolved_by         VARCHAR(100)     NULL
  resolved_at         DATETIME         NULL
  notes               TEXT             NULL
  created_at          DATETIME         NOT NULL
  UNIQUE KEY uq_migration_store_raw (raw_name, raw_code)
```

**`status` semantics** (both tables):
- `unresolved` — the raw value has been harvested from legacy data but nobody has decided which `product`/`store` (if any) it maps to yet. This is the default and the only status a row can have without a human `resolved_by`.
- `mapped` — a human has confirmed `target_id`. Only a human-set `resolved_by`/`resolved_at` may move a row into this status; no automated process (fuzzy match, exact-normalized match, or otherwise) is permitted to set `status='mapped'` on its own. Automated tooling may **suggest** a `target_id` (e.g., pre-fill it) but must leave `status='unresolved'` until a human confirms.
- `conflict` — the raw value could plausibly map to more than one existing `product`/`store` (e.g. two canonical products differ only by a fuzzy-similar spelling, or a `store_alias.raw_name` uniqueness check would be violated), and a human must resolve which one is correct (or that they are genuinely two different entities that happen to look similar) before this row can move to `mapped`.

**No fuzzy auto-merge, anywhere**: no code path in the ETL (Phase 0/1 of the migration, §17/§18 of the audit) is permitted to write `target_id`+`status='mapped'` based on a similarity score. A similarity score may only ever *populate `notes`* (e.g., "suggested match: PRODUK X, 92% similar") to speed up human review.

---

## 5. Core transactional tables

Unless a field's meaning differs from the audit, only new/changed details vs. Audit §15 are called out; the full table-by-table restates the design for completeness.

### 5.1 Reference/master data

```
factory
  factory_id   BIGINT UNSIGNED PK AUTO_INCREMENT
  code         VARCHAR(30)     NOT NULL UNIQUE   -- 'karangtengah' | 'cibadak'
  name         VARCHAR(100)    NOT NULL

division
  division_id      BIGINT UNSIGNED PK AUTO_INCREMENT
  name             VARCHAR(100)    NOT NULL UNIQUE
  is_verification  TINYINT(1)      NOT NULL DEFAULT 0   -- replaces divisiVerifikasi() (Audit §6)

location
  location_id  BIGINT UNSIGNED PK AUTO_INCREMENT
  name         VARCHAR(100)    NOT NULL UNIQUE
  -- seeded with exactly one row, 'GUDANG UTAMA', for v1 (current app has one warehouse concept
  -- only — Audit §8). Kept as its own table, not a hardcoded constant, so multi-location stock
  -- never requires a schema change later — see stock_ledger (§6).
```

### 5.2 PO (demand, factory side)

```
po_batch
  po_batch_id  BIGINT UNSIGNED PK AUTO_INCREMENT
  tanggal      DATE            NOT NULL
  factory_id   BIGINT UNSIGNED NOT NULL FK -> factory.factory_id
  version      INT UNSIGNED    NOT NULL DEFAULT 1
  created_at   DATETIME        NOT NULL
  updated_at   DATETIME        NULL
  UNIQUE KEY uq_po_batch (tanggal, factory_id)

po_item
  po_item_id     BIGINT UNSIGNED PK AUTO_INCREMENT
  po_batch_id    BIGINT UNSIGNED NOT NULL FK -> po_batch.po_batch_id ON DELETE CASCADE
  product_id     BIGINT UNSIGNED NOT NULL FK -> product.product_id
  kategori       VARCHAR(100)    NULL          -- informational, as uploaded (Audit §7 — kategori is per-row, not derived solely from product master)
  po_awal        DECIMAL(12,2)   NOT NULL DEFAULT 0   -- FROZEN after first insert — see §5.2.1 application rule
  po_revisi      DECIMAL(12,2)   NOT NULL DEFAULT 0   -- latest-snapshot REPLACE semantics — see §5.2.1
  pb             DECIMAL(12,2)   NOT NULL DEFAULT 0   -- always excluded from target (Audit §7) — stored for reference/reporting only
  UNIQUE KEY uq_po_item (po_batch_id, product_id)

po_store_item
  po_store_item_id BIGINT UNSIGNED PK AUTO_INCREMENT
  po_item_id       BIGINT UNSIGNED NOT NULL FK -> po_item.po_item_id ON DELETE CASCADE
  store_id         BIGINT UNSIGNED NOT NULL FK -> store.store_id
  po_awal          DECIMAL(12,2)   NOT NULL DEFAULT 0
  po_revisi        DECIMAL(12,2)   NOT NULL DEFAULT 0
  UNIQUE KEY uq_po_store_item (po_item_id, store_id)
```

**5.2.1 Application-layer rule (not enforceable by a column constraint, must live in the API — Audit §7):** on a **new** `po_batch` upload for a `(tanggal, factory_id)` that already exists, the API must (a) never touch `po_item.po_awal`/`po_store_item.po_awal` for a `product_id`/`store_id` combination already present, (b) fully replace `po_revisi` at both levels with the new file's value (not add to it), (c) preserve rows for products/stores present in the old data but absent from the new file, (d) insert new rows as-is for products/stores newly appearing. This is the exact `poMergeDenganExisting` behavior (Audit §7) — a business rule, not a schema constraint, and is called out again in `docs/php-api-contract-v1.md` on the PO-upload endpoint.

### 5.2.2 `po_closure` — "pesanan ditutup" per store+date

Carries forward `D.kirimClosed` (Audit §5/§17's flagged gap, closed here per `mysql-open-decisions-v1.md` OD-13 rather than left pending): a store's remaining unfulfilled PO for a given date, explicitly written off (the legacy `kTutupToko` action) so the system stops treating that shortfall as outstanding.

```
po_closure
  po_closure_id  BIGINT UNSIGNED PK AUTO_INCREMENT
  tanggal        DATE            NOT NULL
  store_id       BIGINT UNSIGNED NOT NULL FK -> store.store_id
  closed_at      DATETIME        NOT NULL
  closed_by      BIGINT UNSIGNED NULL FK -> users.user_id
  reopened_at    DATETIME        NULL
  reopened_by    BIGINT UNSIGNED NULL FK -> users.user_id
  UNIQUE KEY uq_po_closure (tanggal, store_id)
```

A single row per `(tanggal, store_id)` toggles between closed (`reopened_at IS NULL`) and reopened (`reopened_at` set) — mirroring the legacy `kBukaTokoKembali` reopen action — rather than deleting and recreating rows, so the closure history for a given store+date is never lost.

### 5.3 Production (Ceklis)

```
production_run
  production_run_id BIGINT UNSIGNED PK AUTO_INCREMENT
  tanggal            DATE            NOT NULL
  division_id        BIGINT UNSIGNED NOT NULL FK -> division.division_id
  status             ENUM('not_started','draft','submitted','reopened','verified_fg') NOT NULL DEFAULT 'not_started'
  submitted_at       DATETIME        NULL
  closed_at          DATETIME        NULL
  closed_by          VARCHAR(100)    NULL
  reopen_reason      TEXT            NULL
  version            INT UNSIGNED    NOT NULL DEFAULT 1
  created_at         DATETIME        NOT NULL
  updated_at         DATETIME        NULL
  UNIQUE KEY uq_production_run (tanggal, division_id)

production_item
  production_item_id BIGINT UNSIGNED PK AUTO_INCREMENT
  production_run_id  BIGINT UNSIGNED NOT NULL FK -> production_run.production_run_id ON DELETE CASCADE
  product_id         BIGINT UNSIGNED NOT NULL FK -> product.product_id
  target             DECIMAL(12,2)   NOT NULL DEFAULT 0
  status             ENUM('sesuai','tidak_sesuai') NOT NULL DEFAULT 'sesuai'
  aktual             DECIMAL(12,2)   NOT NULL DEFAULT 0
  reject             DECIMAL(12,2)   NOT NULL DEFAULT 0
  keterangan         VARCHAR(500)    NULL
  UNIQUE KEY uq_production_item (production_run_id, product_id)
```

State machine exactly per Audit §6.1 — enforced in the API layer (a `submitted`→`draft` transition without going through `reopened` is invalid, etc.), not by a DB trigger, consistent with how the rest of this design keeps business-rule enforcement in the application layer and structural invariants (uniqueness, FK, version) in the schema.

### 5.4 FG / Packing

```
fg_batch
  fg_batch_id   BIGINT UNSIGNED PK AUTO_INCREMENT
  tanggal       DATE            NOT NULL
  factory_id    BIGINT UNSIGNED NOT NULL FK -> factory.factory_id
  ready_at      DATETIME        NULL
  version       INT UNSIGNED    NOT NULL DEFAULT 1
  created_at    DATETIME        NOT NULL
  updated_at    DATETIME        NULL
  UNIQUE KEY uq_fg_batch (tanggal, factory_id)

fg_batch_source   -- replaces FGReady.SourceVersionJSON with real rows (Audit §15 originally left this as "or normalize into a child table" — resolved here)
  fg_batch_source_id BIGINT UNSIGNED PK AUTO_INCREMENT
  fg_batch_id        BIGINT UNSIGNED NOT NULL FK -> fg_batch.fg_batch_id ON DELETE CASCADE
  production_run_id  BIGINT UNSIGNED NOT NULL FK -> production_run.production_run_id
  source_version     INT UNSIGNED    NOT NULL   -- the production_run.version this FG confirmation was verified against
  UNIQUE KEY uq_fg_batch_source (fg_batch_id, production_run_id)

fg_item
  fg_item_id    BIGINT UNSIGNED PK AUTO_INCREMENT
  fg_batch_id   BIGINT UNSIGNED NOT NULL FK -> fg_batch.fg_batch_id ON DELETE CASCADE
  product_id    BIGINT UNSIGNED NOT NULL FK -> product.product_id
  store_id      BIGINT UNSIGNED NOT NULL FK -> store.store_id
  qty           DECIMAL(12,2)   NOT NULL DEFAULT 0
  status        VARCHAR(30)     NOT NULL DEFAULT 'belum_dicek'   -- 3-state per Audit §12 research notes: belum_dicek/sesuai/tidak_sesuai
  keterangan    VARCHAR(500)    NULL
  UNIQUE KEY uq_fg_item (fg_batch_id, product_id, store_id)
```

### 5.5 Delivery Order lifecycle (Audit §6.2)

```
delivery_order
  delivery_order_id BIGINT UNSIGNED PK AUTO_INCREMENT
  doc_no            VARCHAR(50)     NULL UNIQUE      -- server-assigned, see document_sequence (§7 of this doc)
  tanggal           DATE            NOT NULL
  store_id          BIGINT UNSIGNED NOT NULL FK -> store.store_id
  shipment_group    ENUM('MAIN','PASTRY','OTHER') NOT NULL DEFAULT 'MAIN'
  status            ENUM('draft','preprinted','ready','shipped','cancelled') NOT NULL DEFAULT 'draft'
  batch             VARCHAR(64)     NULL             -- set at ship time, groups the resulting shipment rows (Audit §6.2)
  catatan           VARCHAR(500)    NULL
  created_by        BIGINT UNSIGNED NULL FK -> users.user_id
  preprinted_at     DATETIME        NULL
  ready_at          DATETIME        NULL
  shipped_at        DATETIME        NULL
  shipped_by        BIGINT UNSIGNED NULL FK -> users.user_id
  version           INT UNSIGNED    NOT NULL DEFAULT 1
  created_at        DATETIME        NOT NULL
  updated_at        DATETIME        NULL
  -- "one open DO per (tanggal, store, shipment_group)" — see §11 of this document for the
  -- generated-column unique-index design (primary) and the transactional fallback (secondary).
  open_key VARCHAR(80) GENERATED ALWAYS AS (
      CASE WHEN status NOT IN ('shipped','cancelled')
           THEN CONCAT(tanggal, '|', store_id, '|', shipment_group)
           ELSE NULL END
  ) STORED,
  UNIQUE KEY uq_delivery_order_open (open_key)

delivery_order_item
  delivery_order_item_id BIGINT UNSIGNED PK AUTO_INCREMENT
  delivery_order_id      BIGINT UNSIGNED NOT NULL FK -> delivery_order.delivery_order_id ON DELETE CASCADE
  product_id             BIGINT UNSIGNED NOT NULL FK -> product.product_id
  planned_qty            DECIMAL(12,2)   NOT NULL DEFAULT 0
  available_qty          DECIMAL(12,2)   NULL
  actual_ship_qty        DECIMAL(12,2)   NULL
  UNIQUE KEY uq_do_item (delivery_order_id, product_id)
```

### 5.6 Shipment header + item (review point 1 — CORRECTED this pass) and the shipment-gated invoice rule (review point 3)

**Correction applied**: the previous draft made `shipment` one row per product (carrying `product_id`/`qty` directly on the header), which is wrong — a shipment is **one physical delivery event**, carrying **one or more products**. This is now normalized into a header (`shipment`) and a line-item child (`shipment_item`), exactly mirroring `delivery_order`/`delivery_order_item`.

**Target-architecture rule, stated explicitly per the review's instruction — this supersedes the audit's "two invoice-creation triggers" finding as a going-forward default, not merely a documentation of the status quo:**

> PO / Pesanan (Customer Order) = **demand**. A `shipment` = **one physical fulfillment event** (goods actually leaving stock to a store or a customer, possibly multiple products at once). `invoice` is **only ever generated from one or more existing `shipment` headers.** There is no code path in the target architecture that creates an `invoice` directly from a `customer_order` without at least one `shipment` existing first.

To make this hold **uniformly** — including for `sumber:'stok'` Pesanan orders, which today skip straight to an invoice+StokAdj without ever touching `Kirim` (Audit §3/§9) — `shipment` is broadened from "a physical delivery-order dispatch" to "any confirmed transfer of goods out of stock to a store or a customer," discriminated by `source_type`. **MAIN and PASTRY remain separate `shipment` headers** — a shipment never mixes two `shipment_group` values, matching the existing DO-level split.

```
shipment
  shipment_id       BIGINT UNSIGNED PK AUTO_INCREMENT
  batch             VARCHAR(64)     NOT NULL
  tanggal           DATE            NOT NULL
  store_id          BIGINT UNSIGNED NOT NULL FK -> store.store_id    -- ALWAYS a real store row, NEVER NULL — walk-in/non-outlet customers resolve server-side to the synthetic "NON-OUTLET / PERORANGAN" store (mysql-open-decisions-v1.md OD-9, LOCKED — see §5.8.1)
  no_sj             VARCHAR(50)     NULL
  pengemudi         VARCHAR(100)    NULL
  kendaraan         VARCHAR(50)     NULL
  shipment_group    ENUM('MAIN','PASTRY','OTHER') NOT NULL DEFAULT 'MAIN'
  source_type       ENUM('delivery_order','manual_kirim','customer_order_fulfillment') NOT NULL
  delivery_order_id BIGINT UNSIGNED NULL FK -> delivery_order.delivery_order_id     -- set iff source_type='delivery_order'
  customer_order_id BIGINT UNSIGNED NULL FK -> customer_order.customer_order_id     -- set iff source_type='customer_order_fulfillment'
  status            ENUM('active','void') NOT NULL DEFAULT 'active'   -- soft-cancel only (OD-14) — a shipment header is never hard-deleted, since invoice_shipment/stock_transfer.source_shipment_id may reference it
  voided_at         DATETIME        NULL
  voided_by         BIGINT UNSIGNED NULL FK -> users.user_id
  void_reason       VARCHAR(500)    NULL
  version           INT UNSIGNED    NOT NULL DEFAULT 1   -- required per §12 (review point 7): the void transition IS an UPDATE (active->void), so this header — unlike the append-only shipment_item children — gets a version column like every other mutable entity, no exception
  created_at        DATETIME        NOT NULL
  KEY ix_shipment_tanggal_store (tanggal, store_id)
  KEY ix_shipment_batch (batch)

shipment_item
  shipment_item_id  BIGINT UNSIGNED PK AUTO_INCREMENT
  shipment_id       BIGINT UNSIGNED NOT NULL FK -> shipment.shipment_id ON DELETE CASCADE
  product_id        BIGINT UNSIGNED NOT NULL FK -> product.product_id
  qty               DECIMAL(12,2)   NOT NULL
  UNIQUE KEY uq_shipment_item (shipment_id, product_id)
```

**Void is header-level, applied once, never per-product**: "deleting" a shipment (`hapusKirim` today) sets `shipment.status='void'` + `voided_at`/`voided_by`/`void_reason` exactly once on the header, and — in the **same transaction** — writes one compensating `stock_ledger` reversal row **per `shipment_item`** belonging to that header (so every product's stock effect is individually reversed, but the void *action* is one atomic header-level operation, not N separate per-product voids that could partially fail). There is no way to void a single `shipment_item` while leaving its siblings active — the whole shipment is one physical event; if only some products were wrong, the correction is a **new** shipment (or, for a full mis-ship, void the whole thing and re-create it correctly). `shipment_item` rows themselves are never deleted or edited once inserted — see §6.

`invoice_shipment`, `stock_transfer.source_shipment_id`, and every report/query reference the **shipment header** (`shipment_id`), never a `shipment_item_id` — an invoice's or a stock-transfer's originating shipment is "this whole delivery event"; invoice line items are read from the header's `shipment_item` rows at invoice-creation time, not stored as a second copy.

```
invoice
  invoice_id                BIGINT UNSIGNED PK AUTO_INCREMENT
  invoice_no                VARCHAR(50)     NOT NULL UNIQUE   -- server-assigned, see document_sequence (§7)
  batch                     VARCHAR(64)     NOT NULL UNIQUE
  tanggal                   DATE            NOT NULL
  store_id                  BIGINT UNSIGNED NOT NULL FK -> store.store_id   -- NOT NULL, same synthetic-store rule as shipment (extended here for consistency — every invoice bills a store, even a synthetic non-outlet one)
  no_sj                     VARCHAR(50)     NULL
  total                     DECIMAL(14,2)   NOT NULL DEFAULT 0
  rate_pct                  DECIMAL(5,2)    NOT NULL
  rate_source               ENUM('default','override') NOT NULL DEFAULT 'default'
  override_reason           VARCHAR(500)    NULL
  sumber                    ENUM('kirim','pesanan','mutasi') NOT NULL DEFAULT 'kirim'  -- kept for historical/reporting labeling — see note below
  legacy_fulfillment_status ENUM('verified','reconstructed','unverified') NOT NULL DEFAULT 'verified'   -- review point 5, see §5.6.1. Every invoice created by the NEW PHP API is always 'verified' (structurally guaranteed — creation requires >=1 real shipment). 'reconstructed' and 'unverified' can ONLY be written by the one-time migration ETL, never by any live API endpoint.
  version                   INT UNSIGNED    NOT NULL DEFAULT 1
  created_at                DATETIME        NOT NULL
  updated_at                DATETIME        NULL

invoice_shipment   -- join table enforcing "invoice requires >=1 shipment" structurally, for 'verified'/'reconstructed' invoices
  invoice_id     BIGINT UNSIGNED NOT NULL FK -> invoice.invoice_id ON DELETE CASCADE
  shipment_id    BIGINT UNSIGNED NOT NULL FK -> shipment.shipment_id
  PRIMARY KEY (invoice_id, shipment_id)

invoice_item
  invoice_item_id  BIGINT UNSIGNED PK AUTO_INCREMENT
  invoice_id       BIGINT UNSIGNED NOT NULL FK -> invoice.invoice_id ON DELETE CASCADE
  product_id       BIGINT UNSIGNED NOT NULL FK -> product.product_id
  qty_do           DECIMAL(12,2)   NOT NULL
  qty_invoice      DECIMAL(12,2)   NOT NULL
  harga            DECIMAL(14,2)   NOT NULL
  subtotal         DECIMAL(14,2)   NOT NULL
  rate_pct         DECIMAL(5,2)    NOT NULL    -- frozen per-line at creation, Audit §9 — never recomputed against a later global default
  rate_source      ENUM('default','override') NOT NULL
  override_reason  VARCHAR(500)    NULL
  UNIQUE KEY uq_invoice_item (invoice_id, product_id)
```

**The application layer must enforce** (structurally guaranteed by `invoice_shipment` having `ON DELETE CASCADE` from `invoice` but `RESTRICT`-by-omission from `shipment`'s side) that **every `invoice` with `legacy_fulfillment_status='verified'` has at least one `invoice_shipment` row inserted in the same transaction it is created — and the PHP API has no code path capable of creating an invoice any other way.** `invoice_item` rows for a normal (`verified`) invoice are populated by reading `shipment_item` for every linked `shipment_id`, not supplied freestanding by the caller — see `docs/php-api-contract-v1.md` §8.

#### 5.6.1 Historical invoices without fulfillment evidence (review point 5 — migration-only exception, LOCKED)

Three cases, exactly as specified in the review — **no fake shipment is ever invented merely for schema compliance**:

| Case | Legacy evidence | `legacy_fulfillment_status` | `invoice_shipment` | `stock_ledger` |
|---|---|---|---|---|
| **A** | Real legacy `Kirim` rows for the batch | `verified` | Linked to the migrated `shipment`(s) — mechanical | Normal `shipment_out` rows already exist for those shipments |
| **B** | No `Kirim`, but a reliable `StokAdj`/other stock-out signal exists (e.g. Pesanan `selesai` + its `koreksi` StokAdj) | `reconstructed` | Linked to a `shipment`+`shipment_item` **synthesized** from that evidence, dated to the evidence's own date, `source_type='customer_order_fulfillment'` | The synthesized shipment gets a normal `shipment_out` ledger row — it's not fictitious, it's a faithful re-expression of real (if indirect) evidence |
| **C** | **No** shipment, StokAdj, or any other reliable physical fulfillment evidence at all | `unverified` | **None — no `invoice_shipment` row, no synthetic shipment of any kind** | **No `shipment_out` ledger row is created.** Financial history (the invoice, its items, its payments) is fully preserved; the physical-fulfillment claim simply isn't asserted |

For Case C specifically: the invoice and its `invoice_item`/`payment` rows migrate normally (financial history is never discarded), but `invoice_shipment` is empty for that invoice, and **no** `stock_ledger` row is written on its behalf. Reports (`docs/php-api-contract-v1.md` §11) must **exclude** `legacy_fulfillment_status<>'verified'` invoices from physical shipment/fulfillment KPIs by default (an explicit filter param can opt them back in for finance-side reconciliation views that need to see all money regardless of fulfillment evidence). This status is visibly stamped on the row forever — never silently reclassified to `'verified'` after the fact.

**On the legacy order-gated Pesanan invoice path (`psSyncInvoice`, Audit §9):** this is **not** carried into the target architecture as a normal path — see §5.6.1 above for exactly how its historical output migrates (Case B in the ordinary case, Case C when even that evidence is missing). It is documented in `docs/mysql-migration-map-v1.md` §3 as a **migration-compatibility concern only**, not a rule the new PHP API implements going forward. Going forward, a `sumber:'stok'` Pesanan is fulfilled by creating a `shipment` (`source_type='customer_order_fulfillment'`), which also produces the correct `stock_ledger` KELUAR event, before its `invoice` can be created.

**On the Mutasi-derived invoice (Audit §9's second nuance):** a `stock_transfer` (§8) does not, by itself, move goods out of the warehouse — the goods already left via an earlier `shipment`. The destination store's new invoice created by a Mutasi is therefore linked via `invoice_shipment` to that **original** `shipment_id` (traceable through `stock_transfer.source_shipment_id`, §8), not to a new shipment of its own.

### 5.7 Payments, returns, rejects, retail sales

```
payment
  payment_id    BIGINT UNSIGNED PK AUTO_INCREMENT
  invoice_id    BIGINT UNSIGNED NOT NULL FK -> invoice.invoice_id
  tanggal       DATE            NOT NULL
  jumlah        DECIMAL(14,2)   NOT NULL
  cara          VARCHAR(50)     NULL
  keterangan    VARCHAR(500)    NULL
  created_at    DATETIME        NOT NULL
  KEY ix_payment_invoice (invoice_id)

return_note
  return_note_id BIGINT UNSIGNED PK AUTO_INCREMENT
  batch          VARCHAR(64)     NOT NULL
  tanggal        DATE            NOT NULL
  store_id       BIGINT UNSIGNED NOT NULL FK -> store.store_id
  product_id     BIGINT UNSIGNED NOT NULL FK -> product.product_id
  qty            DECIMAL(12,2)   NOT NULL
  alasan         VARCHAR(500)    NULL
  sumber         VARCHAR(30)     NULL       -- provenance label (e.g. 'sheet_import','manual') — Audit §14 L2/L9 dual-path note
  created_at     DATETIME        NOT NULL

reject_note
  reject_note_id BIGINT UNSIGNED PK AUTO_INCREMENT
  batch          VARCHAR(64)     NOT NULL
  tanggal        DATE            NOT NULL
  store_id       BIGINT UNSIGNED NOT NULL FK -> store.store_id
  product_id     BIGINT UNSIGNED NOT NULL FK -> product.product_id
  qty            DECIMAL(12,2)   NOT NULL
  alasan         VARCHAR(500)    NULL
  resolusi       ENUM('potong','ganti') NOT NULL
  nilai          DECIMAL(14,2)   NOT NULL DEFAULT 0
  invoice_id     BIGINT UNSIGNED NULL FK -> invoice.invoice_id      -- set iff resolusi='potong' and linked
  created_at     DATETIME        NOT NULL

retail_sale
  retail_sale_id BIGINT UNSIGNED PK AUTO_INCREMENT
  batch          VARCHAR(64)     NOT NULL
  tanggal        DATE            NOT NULL
  store_id       BIGINT UNSIGNED NOT NULL FK -> store.store_id
  product_id     BIGINT UNSIGNED NOT NULL FK -> product.product_id
  qty            DECIMAL(12,2)   NOT NULL
  sumber         VARCHAR(30)     NOT NULL DEFAULT 'pos'
  created_at     DATETIME        NOT NULL
```

### 5.8 Customer Order (Pesanan) — pure demand, per review point 3

```
customer_order
  customer_order_id BIGINT UNSIGNED PK AUTO_INCREMENT
  order_no          VARCHAR(50)     NOT NULL UNIQUE
  store_id          BIGINT UNSIGNED NOT NULL FK -> store.store_id   -- ALWAYS a real store row, NEVER NULL — walk-in/individual customers resolve server-side to the synthetic "NON-OUTLET / PERORANGAN" store — see §5.8.1
  tgl_pesan         DATE            NOT NULL
  tgl_produksi      DATE            NULL
  tgl_ambil         DATE            NULL
  tipe              VARCHAR(30)     NULL
  pemesan           VARCHAR(150)    NULL
  kontak            VARCHAR(100)    NULL
  alamat            VARCHAR(300)    NULL
  pct_omset         DECIMAL(5,2)    NOT NULL DEFAULT 100
  sumber            ENUM('produksi','stok') NOT NULL
  status            VARCHAR(30)     NOT NULL DEFAULT 'baru'
  catatan           VARCHAR(500)    NULL
  version           INT UNSIGNED    NOT NULL DEFAULT 1
  created_at        DATETIME        NOT NULL
  updated_at        DATETIME        NULL

customer_order_item
  customer_order_item_id BIGINT UNSIGNED PK AUTO_INCREMENT
  customer_order_id      BIGINT UNSIGNED NOT NULL FK -> customer_order.customer_order_id ON DELETE CASCADE
  product_id             BIGINT UNSIGNED NOT NULL FK -> product.product_id
  qty                    DECIMAL(12,2)   NOT NULL
  harga                  DECIMAL(14,2)   NOT NULL
  UNIQUE KEY uq_customer_order_item (customer_order_id, product_id)
```

`customer_order` never has a direct FK from `invoice` — its only route to an invoice is through a `shipment` with `source_type='customer_order_fulfillment'` per §5.6.

#### 5.8.1 `store_id` NOT NULL — LOCKED decision (review point 2), synthetic non-outlet store

**This corrects a real contradiction in the previous draft** (§15/§5.6/§5.8 said `store_id NOT NULL` + a synthetic store; `docs/php-api-contract-v1.md` still described the resulting customer-order fulfillment shipment's `storeId` as nullable). **Final, locked rule: `shipment.store_id` and `customer_order.store_id` are `NOT NULL` in the schema — full stop. No code path, migration or live API, may ever insert `NULL` into either column.**

- A seed row is inserted into `store` at migration/bootstrap time:
  ```
  canonical_name = 'NON-OUTLET / PERORANGAN'
  channel        = NULL   -- channel is genuinely not applicable to this row; NULL is allowed HERE specifically because `store.channel` itself is a nullable column for every store (Audit §9 — channel is reporting metadata, not a required field for any store), not because store identity itself is ever optional
  active          = 1
  ```
- The **API**, not the database, resolves optionality: `POST /api/customer-orders` may accept `storeId` as an **optional** request field (a genuine walk-in customer has no real outlet to pick). When omitted, the server resolves it to the synthetic store's `store_id` **before** the `INSERT` — the column itself never sees a `NULL`. See `docs/php-api-contract-v1.md` §9 for the corrected endpoint description (this was the ambiguous part before).
- Reports/joins never need a `LEFT JOIN`/`COALESCE` special case for a missing store dimension — every `shipment`/`customer_order` row joins cleanly to exactly one `store` row, synthetic or real.

---

## 6. Stock ledger — append-only event log (review point 4)

**`stock_ledger` is the single source of truth for all stock movement. It is append-only. `running_balance` is deliberately NOT a column on this table** — a per-row running balance would need to be recomputed/shifted on every backdated insert or correction, defeating the append-only property. Current balance is derived (§6.1).

```
stock_ledger
  stock_ledger_id BIGINT UNSIGNED PK AUTO_INCREMENT
  product_id      BIGINT UNSIGNED NOT NULL FK -> product.product_id
  location_id     BIGINT UNSIGNED NOT NULL FK -> location.location_id   -- defaults to the single 'GUDANG UTAMA' row for v1
  event_type      ENUM('production_in','shipment_out','adjustment','opening_balance','reversal') NOT NULL
  qty_delta       DECIMAL(12,2)   NOT NULL     -- signed: + increases stock, - decreases
  source_type     ENUM('production_run','shipment_item','stock_adjustment','stock_transfer','opening_balance_cutover','historical_replay','reversal') NOT NULL
  source_id       BIGINT UNSIGNED NULL         -- id in the source_type's table (for source_type='shipment_item' this is shipment_item_id, NOT shipment_id — one ledger row per line item; the header is still reachable via shipment_item.shipment_id for traceability); NULL for opening_balance/historical_replay rows that reference a legacy identifier instead (see reversal_of / legacy_ref below)
  reversal_of_id  BIGINT UNSIGNED NULL FK -> stock_ledger.stock_ledger_id   -- set only on event_type='reversal' rows, pointing at the ledger row being compensated
  legacy_ref      VARCHAR(100)    NULL         -- free-text pointer back to the legacy Sheets row/id for historical_replay/opening_balance rows, for audit traceability
  event_date      DATE            NOT NULL     -- business date (Asia/Jakarta), Audit §9
  created_at      DATETIME        NOT NULL     -- UTC insert time
  created_by      BIGINT UNSIGNED NULL FK -> users.user_id
  notes           VARCHAR(500)    NULL
  KEY ix_stock_ledger_product_date (product_id, location_id, event_date)
  KEY ix_stock_ledger_source (source_type, source_id)
```

**Mapping from Audit §8's event table to `event_type`/`source_type`:**

| Audit §8 source event | `event_type` | `source_type` | `source_id` |
|---|---|---|---|
| FG-verified production submitted (MASUK) | `production_in` | `production_run` | `production_run_id` |
| Shipment created (review point 1/7 — **one ledger row per `shipment_item`**, not one per `shipment` header) | `shipment_out` | `shipment_item` | `shipment_item_id` |
| Manual Stock Adjustment | `adjustment` | `stock_adjustment` | `stock_adjustment_id` |
| Reject `ganti` (indirect, via its StokAdj) | `adjustment` | `stock_adjustment` (the `reject_note` row is referenced from the `stock_adjustment` row, not directly from the ledger — see §9) | `stock_adjustment_id` |
| Customer order `selesai` (indirect, via its StokAdj) — **superseded**: per §5.6, this now goes through a `shipment`/`shipment_item` (`shipment_out`) instead of a raw `stock_adjustment`, unifying it with the invoice-gating rule | `shipment_out` | `shipment_item` | `shipment_item_id` |
| Shipment voided (review point 1 — header-level action, one reversal per original item) | `reversal` | `reversal` | the **original** `stock_ledger_id` being reversed (via `reversal_of_id`); one new row per `shipment_item` that belonged to the voided header |
| Opening balance at cutover | `opening_balance` | `opening_balance_cutover` | `NULL` (see `legacy_ref`) |
| Full historical replay | (whichever `event_type` the original event was) | `historical_replay` | `NULL` (see `legacy_ref`) |

**Void walkthrough (review point 7 — "one stock_ledger shipment_out row per item; void writes one reversal row per item"):** creating a `shipment` with 3 `shipment_item` rows writes exactly 3 `stock_ledger` rows (`event_type='shipment_out'`, one `source_id` per `shipment_item_id`). Voiding that shipment later writes exactly 3 **new** `stock_ledger` rows (`event_type='reversal'`, each `reversal_of_id` pointing at one of the original 3), each with the negated `qty_delta` — never edits or deletes the original 3. The void is one atomic transaction producing all 3 reversal rows together with the `shipment.status='void'` update; it cannot leave some items reversed and others not.

Retur, Jual Konsumen, and Mutasi remain **not** represented in `stock_ledger` at all — confirmed by Audit §8 as having no warehouse-stock effect, and nothing in this review changes that rule.

### 6.1 `stock_balance` — materialized/rebuildable cache (review point 4's explicit ask)

```
stock_balance
  product_id       BIGINT UNSIGNED NOT NULL
  location_id      BIGINT UNSIGNED NOT NULL
  qty_on_hand      DECIMAL(12,2)   NOT NULL DEFAULT 0
  last_ledger_id   BIGINT UNSIGNED NULL FK -> stock_ledger.stock_ledger_id   -- the highest stock_ledger_id already folded into qty_on_hand, for incremental rebuild
  updated_at       DATETIME        NOT NULL
  PRIMARY KEY (product_id, location_id)
```

**This is explicitly a cache, not a primary truth.** `qty_on_hand` for any `(product_id, location_id)` must always be reproducible by `SELECT COALESCE(SUM(qty_delta),0) FROM stock_ledger WHERE product_id=? AND location_id=?` (matching `stokGudang()`'s current recompute-from-scratch semantics, Audit §8/§14 L12) — `stock_balance` exists purely so the application doesn't have to run that full aggregation on every page load once ledger volume grows. It is safe to `TRUNCATE stock_balance` and rebuild it entirely from `stock_ledger` at any time with no data loss; the reverse is never true.

---

## 7. Document numbering (review point 5)

```
document_sequence
  document_type  VARCHAR(30)  NOT NULL     -- 'DO' | 'INVOICE' (extensible)
  year           SMALLINT     NOT NULL
  month          TINYINT      NOT NULL
  last_number    INT UNSIGNED NOT NULL DEFAULT 0
  PRIMARY KEY (document_type, year, month)
```

**Generation algorithm (must run inside the same DB transaction as creating the `delivery_order`/`invoice` row):**

```sql
-- inside one transaction:
INSERT INTO document_sequence (document_type, year, month, last_number)
  VALUES (?, ?, ?, 1)
  ON DUPLICATE KEY UPDATE last_number = last_number + 1;
SELECT last_number FROM document_sequence
  WHERE document_type=? AND year=? AND month=?
  FOR UPDATE;
-- format the number into doc_no / invoice_no per the chosen format (OD-2), then INSERT the document row using it, then COMMIT.
```

This guarantees: (a) numbers are never recomputed from existing rows at render time (Audit §9/§19 R6/R7 — the core problem being fixed), (b) two concurrent requests for the same `(document_type, year, month)` cannot receive the same number (`FOR UPDATE` serializes them), (c) numbers are permanent once assigned — a document deletion never reuses its number for a different document. Gaps are acceptable (a failed transaction after reserving a number but before committing the document row just skips that number); duplicates are not, and are impossible under this design.

**Exact display format** (`DO/KRM/{seq:3}/{romawiBulan}/{yyyy}` for DO, `INV/KRM/{ddmmyy}/{seq:3}` for Invoice, per Audit §9) is an **application-layer formatting decision**, not a schema concern — but the two legacy formats are inconsistent with each other (Roman-numeral month vs. numeric date), and whether to preserve both exactly, unify them, or preserve them only for display of pre-cutover documents is an open decision — see `docs/mysql-open-decisions-v1.md` OD-2.

---

## 8. Mutasi — append-only transfer + explicit reversal (review point 11)

```
stock_transfer
  stock_transfer_id      BIGINT UNSIGNED PK AUTO_INCREMENT
  tanggal                DATE            NOT NULL
  product_id             BIGINT UNSIGNED NOT NULL FK -> product.product_id
  from_store_id          BIGINT UNSIGNED NOT NULL FK -> store.store_id
  to_store_id            BIGINT UNSIGNED NOT NULL FK -> store.store_id
  qty                    DECIMAL(12,2)   NOT NULL
  keterangan             VARCHAR(500)    NULL
  source_shipment_id     BIGINT UNSIGNED NULL FK -> shipment.shipment_id     -- the original shipment this transfer reallocates (Audit §9 nuance, §5.6 above)
  from_invoice_id        BIGINT UNSIGNED NULL FK -> invoice.invoice_id       -- invoice whose qty was reduced
  to_invoice_id          BIGINT UNSIGNED NULL FK -> invoice.invoice_id       -- newly created destination invoice
  reverses_transfer_id   BIGINT UNSIGNED NULL FK -> stock_transfer.stock_transfer_id   -- set only when this row is a reversal of an earlier transfer
  created_at             DATETIME        NOT NULL
  KEY ix_stock_transfer_date (tanggal)
```

**No hard delete.** A wrong/unwanted transfer is corrected by inserting a **new** `stock_transfer` row with `from_store_id`/`to_store_id` swapped relative to the original, `qty` equal, and `reverses_transfer_id` pointing at the original — an explicit compensating transaction, never an `UPDATE` or `DELETE` of the original row. This closes Audit §14 L10 ("Mutasi has no delete/reversal path") with a real, auditable mechanism rather than either leaving the gap or adding a destructive delete.

---

## 9. Stock Adjustment — no cascade-delete, compensating entries only (review point 12)

```
stock_adjustment
  stock_adjustment_id   BIGINT UNSIGNED PK AUTO_INCREMENT
  tanggal               DATE            NOT NULL
  product_id            BIGINT UNSIGNED NOT NULL FK -> product.product_id
  location_id           BIGINT UNSIGNED NOT NULL FK -> location.location_id
  tipe                  ENUM('masuk','waste','rusak','opname','koreksi') NOT NULL
  qty                   DECIMAL(12,2)   NOT NULL       -- signed, exactly as today (Audit §8)
  keterangan            VARCHAR(500)    NULL
  sumber                VARCHAR(30)     NULL           -- e.g. 'manual','reject_replacement'
  reject_note_id        BIGINT UNSIGNED NULL FK -> reject_note.reject_note_id   -- set iff this adjustment was auto-created by a 'ganti' reject
  reverses_adjustment_id BIGINT UNSIGNED NULL FK -> stock_adjustment.stock_adjustment_id
  created_at            DATETIME        NOT NULL
```

**Never cascade-deleted** by any batch/trial-delete process, in any environment (Audit §14 L21/R21 — this review's point 12 confirms the current permanent-exemption rule is carried forward, not treated as a Sheets-era artifact to drop). **A correction is a new row** with `reverses_adjustment_id` set (and typically the negated `qty` and `tipe` needed to offset the original), never an edit or delete of the original — this is also how `stock_ledger` stays a true append-only log: every `stock_adjustment` insert or reversal produces exactly one corresponding `stock_ledger` row.

---

## 10. Opening stock (review point 13) — both paths designed, decision deferred

Both are fully supported by the schema above with no further changes needed:

- **Path A — full historical replay**: every historical FG-verified-production / shipment / stock-adjustment / reject-`ganti` / (legacy) Pesanan-`selesai` event is re-derived from the legacy Sheets export and inserted into `stock_ledger` with its **original** `event_date`, `source_type='historical_replay'`, and `legacy_ref` pointing at the originating Sheets row. `stock_balance` is then built by aggregation as normal.
- **Path B — cutover opening-balance event**: exactly one `stock_ledger` row per `(product_id, location_id)` with `event_type='opening_balance'`, `source_type='opening_balance_cutover'`, `qty_delta` = the reconciled starting balance, `event_date` = the cutover date. This row must be traceable to a **signed reconciliation report** (a retained document — CSV/PDF export, or at minimum a `notes`/`legacy_ref` pointer to one — comparing the legacy system's computed `stokGudang()` per product as of the cutover date against an independent physical count).

**Which path is used is a migration-execution decision** (per the review: "Final migration strategy will choose A if legacy history passes reconciliation; otherwise B"), made in `docs/mysql-migration-map-v1.md` Phase 1, not fixed by this schema. Nothing here forces one over the other.

---

## 11. Delivery Order uniqueness — implementation alternatives (review point 14)

**Business rule (unchanged from Audit §6.2/§15): exactly one open DO (`status NOT IN ('shipped','cancelled')`) per `(tanggal, store_id, shipment_group)` at any time.**

### 11.1 Primary approach — generated column + unique index (MySQL 5.7.6+ / MariaDB 10.2+, confirmed supported per §0)

Already shown in §5.5:

```sql
open_key VARCHAR(80) GENERATED ALWAYS AS (
    CASE WHEN status NOT IN ('shipped','cancelled')
         THEN CONCAT(tanggal, '|', store_id, '|', shipment_group)
         ELSE NULL END
) STORED,
UNIQUE KEY uq_delivery_order_open (open_key)
```

Because both MySQL and MariaDB treat multiple `NULL`s in a unique index as non-conflicting, this enforces uniqueness **only** among rows where `open_key` is non-null (i.e., only "open" DOs) — exactly the partial-unique-index behavior needed, with no dependency on MySQL 8.0's newer functional-index syntax. `STORED` (not `VIRTUAL`) is used because MariaDB cannot index a `VIRTUAL` generated column before 10.2, and `STORED` works identically on both engines for indexing purposes from the versions in §0's floor.

### 11.2 Fallback approach — transactional application-level enforcement (any InnoDB version)

If, after confirming the actual cPanel database version, generated/indexable columns are unavailable for any reason:

```sql
START TRANSACTION;
SELECT delivery_order_id, version FROM delivery_order
  WHERE tanggal = ? AND store_id = ? AND shipment_group = ?
    AND status NOT IN ('shipped','cancelled')
  FOR UPDATE;
-- application code: if a row is found, this is an update-in-place of that draft (mirrors
-- doDocIdFor's reuse-until-final behavior, Audit §6); if none found, INSERT a new row.
COMMIT;
```

This relies entirely on `FOR UPDATE` row locking within one transaction to prevent a race between two concurrent "create DO" requests for the same key, with no DB-level constraint backing it up — strictly weaker than §11.1 (a bug in application code could still insert a duplicate), which is why §11.1 is the recommended default and this is documented only as a fallback.

**Decision point**: confirm actual database version (§0) before `database/schema-v1.sql` is finalized for real use; the draft file ships with §11.1.

---

## 12. Concurrency — version columns (review point 7)

**Every table listed in this document that supports `UPDATE` has a `version INT UNSIGNED NOT NULL DEFAULT 1` column, with no exceptions and no "version omitted" compatibility mode** (Audit §14 L15 is explicitly closed by this review point): `product`, `store`, `po_batch`, `production_run`, `fg_batch`, `delivery_order`, `shipment` (the header only — its one legitimate in-place transition is `active`→`void`, §5.6), `invoice`, `customer_order`. (Line-item/child tables, and pure-append tables like `shipment_item`/`return_note`/`reject_note`/`retail_sale`/`payment`/`stock_adjustment`/`stock_transfer`/`stock_ledger`/`audit_log`/`idempotency_log`, do not get one — they are never updated in place; a "correction" to any of them is a new compensating row, per §8/§9 above.)

**Every update statement must take the form:**

```sql
UPDATE <table> SET <fields...>, version = version + 1, updated_at = ?
  WHERE <table>_id = ? AND version = ?;
-- application checks affected-row count:
--   1 row  -> success, return the new version
--   0 rows -> re-SELECT current version, return 409 VERSION_CONFLICT {currentVersion}
```

This is a direct, faithful port of the current `mutateVersioned_`/`getVersion_`/`setVersion_` semantics (Audit §11) onto a real column instead of a separate full-table-scan version sheet — strictly better (O(1) indexed lookup instead of O(n) sheet scan) with identical conflict semantics. `expectedVersion` is a **required** field on every mutating API call for these entities — see `docs/php-api-contract-v1.md`.

(`CHECK (version >= 1)` may be added on MySQL 8.0.16+/MariaDB 10.2.1+ as a documentation-as-code assertion; on older-but-still-supported versions within §0's floor it is silently parsed and ignored rather than enforced, so it must never be relied on as the actual safety mechanism — the `WHERE ... AND version = ?` pattern above is what actually provides safety, not the `CHECK`.)

---

## 13. Idempotency (review point 8)

```
idempotency_log
  request_id           VARCHAR(64)   PRIMARY KEY
  endpoint             VARCHAR(150)  NOT NULL
  request_fingerprint  CHAR(64)      NOT NULL      -- SHA-256 hex of the normalized request payload
  response_status      ENUM('ok','error','conflict') NOT NULL
  response_body        JSON          NOT NULL      -- the exact response replayed on a safe retry
  record_type          VARCHAR(50)   NULL
  record_key           VARCHAR(100)  NULL
  created_at           DATETIME      NOT NULL
```

**Behavior (must be implemented exactly this way, per review point 8's explicit instruction):**
1. On receiving `request_id`, look it up in `idempotency_log`.
2. Not found → proceed normally; on completion, insert the row (fingerprint of the request that was just processed + the response produced) before returning.
3. Found, and `request_fingerprint` matches the incoming request's fingerprint → **replay** `response_body` verbatim, do not re-execute the mutation.
4. Found, and `request_fingerprint` does **not** match → **reject** with a distinct error (e.g. `IDEMPOTENCY_KEY_REUSE_MISMATCH`), explaining that this `request_id` was already used for a materially different request. **Never** silently accept the new payload under the old key, and never silently overwrite the stored response.

This is a strengthening of the current Apps Script behavior (Audit §11's `checkIdempotent_`, which replays on `request_id` match alone with no payload-fingerprint check at all) — closes a theoretical gap where two unrelated requests could collide on a client-generated `requestId` and silently short-circuit.

**Retention (review point 9, LOCKED): 90 days.** Unlike `audit_log` (§16, retained indefinitely), `idempotency_log` only needs to outlive the longest plausible client retry window — 90 days is generous against the legacy system's 6-hour cache TTL. A scheduled cleanup job (documented here, not implemented in this pass — this is a design decision, not a cron script) deletes `idempotency_log` rows where `created_at < NOW() - INTERVAL 90 DAY`. This job is a future implementation task; nothing in Phase 0/PHP-implementation is blocked by its absence, since an un-pruned table is a storage-growth concern, not a correctness one.

---

## 14. Time handling (review point 9)

- Every `DATETIME` column in this schema (`created_at`, `updated_at`, `*_at` timestamps like `shipped_at`/`ready_at`/`preprinted_at`) stores **UTC**. The application/PHP layer is responsible for converting to/from `Asia/Jakarta` only at the presentation layer, never in the database. The DB connection should explicitly `SET time_zone = '+00:00'` at connect time to avoid relying on the server's default session timezone.
- Every business-operational date (`tanggal`, `event_date`) is a plain `DATE` column with **no time-of-day or timezone component**, representing the `Asia/Jakarta` calendar date the business event belongs to (matching the legacy app's `yyyy-MM-dd` string convention, Audit §19 R11) — the application layer must always resolve "what is today's business date" using `Asia/Jakarta`, not the server's or the browser's local timezone, before writing to any `DATE` column.

---

## 15. Authentication / Authorization (review point 6)

```
users
  user_id        BIGINT UNSIGNED PK AUTO_INCREMENT
  username       VARCHAR(100)    NOT NULL UNIQUE
  password_hash  VARCHAR(255)    NOT NULL
  full_name      VARCHAR(150)    NOT NULL
  active         TINYINT(1)      NOT NULL DEFAULT 1
  created_at     DATETIME        NOT NULL
  updated_at     DATETIME        NULL

roles
  role_id  BIGINT UNSIGNED PK AUTO_INCREMENT
  code     VARCHAR(30)     NOT NULL UNIQUE   -- ADMIN | PPIC | PRODUCTION | FG_PACKING | DELIVERY | FINANCE | MANAGEMENT_VIEWER
  name     VARCHAR(100)    NOT NULL

user_roles
  user_id  BIGINT UNSIGNED NOT NULL FK -> users.user_id
  role_id  BIGINT UNSIGNED NOT NULL FK -> roles.role_id
  PRIMARY KEY (user_id, role_id)     -- many-to-many, per review ("bukan single role_id pada user")

-- Future scope (schema present now so no later migration is needed to add it; not
-- necessarily enforced by the v1 API — see docs/php-api-contract-v1.md):
user_factory_access
  user_id     BIGINT UNSIGNED NOT NULL FK -> users.user_id
  factory_id  BIGINT UNSIGNED NOT NULL FK -> factory.factory_id
  PRIMARY KEY (user_id, factory_id)

user_division_access
  user_id      BIGINT UNSIGNED NOT NULL FK -> users.user_id
  division_id  BIGINT UNSIGNED NOT NULL FK -> division.division_id
  PRIMARY KEY (user_id, division_id)
```

**Seed data for `roles`** (the 7 minimum roles from the review): `ADMIN`, `PPIC`, `PRODUCTION`, `FG_PACKING`, `DELIVERY`, `FINANCE`, `MANAGEMENT_VIEWER`. A role-to-endpoint permission matrix is defined in `docs/php-api-contract-v1.md`, not in the schema — role checks are an application-layer concern; the schema only needs to represent "which roles does this user have" correctly (many-to-many, as required).

This directly replaces the current system's complete absence of real authentication (Audit §6 "User/Role" entity, §19 R18 — the current Apps Script backend trusts client-supplied `{userId,userName,role}` purely as audit-label metadata, with zero verification, and is explicitly self-documented in `Code.gs`'s own header comment as an unsolved gap). Real auth is a **requirement** for the PHP/MySQL system before any public deployment, per the audit's own risk R18 and this review's point 6.

### 15.1 Auth mechanism (review point 4, LOCKED — replaces the earlier "bearer or cookie, decided later" ambiguity)

**Final choice: PHP server-side session, cookie-based.** Frontend and API are hosted on the same domain/site (`factory.amorgroup.id`), so there is no cross-origin reason to reach for a bearer token, and a bearer token would force `localStorage` storage — explicitly disallowed by the review (XSS-exposed, no `HttpOnly` protection). Concretely:

- **Login** (`POST /api/auth/login`): verify credentials with `password_hash()`/`password_verify()` against `users.password_hash`; on success, call `session_regenerate_id(true)` (never reuse a pre-login session id — mitigates session fixation), then store `user_id` (and resolved roles) in `$_SESSION`.
- **Session cookie**: `Secure` (HTTPS only — cPanel host must terminate TLS), `HttpOnly` (never readable from JS, so an XSS bug cannot exfiltrate it — this is *why* it beats a `localStorage` token, not just a style preference), `SameSite=Lax` as the default (upgrade to `Strict` if product confirms no cross-site-initiated-navigation login flows are needed — e.g. no "open a specific report link from an external chat message while already logged in" use case; `Lax` is the safer-by-default starting point for a same-domain app).
- **Session lifetime**: configurable inactivity timeout (`session.gc_maxlifetime`, e.g. 30–60 minutes idle) plus an absolute maximum session age enforced in application code (`$_SESSION['login_at']` checked on every request) — both values are app config, not schema.
- **Logout** (`POST /api/auth/logout`): `session_unset()` + `session_destroy()` server-side, plus clears the cookie — a logged-out session cannot be replayed even if the cookie value leaks afterward.
- **`GET /api/auth/me`**: returns the current session's user + roles, or `401` if unauthenticated — this is how the frontend detects "am I logged in" on page load, replacing any client-stored token check.
- **CSRF protection**: a per-session CSRF token (generated at login, stored in `$_SESSION`, **never in a table**) is required as a header (e.g. `X-CSRF-Token`) on every `POST`/`PUT`/`PATCH`/`DELETE`; the server rejects a mismatch with `403 CSRF_TOKEN_INVALID` before any business logic runs. **This is independent of and in addition to `Idempotency-Key`** (review point 4's explicit instruction) — CSRF protects against a forged request from a *different, unauthenticated* origin; idempotency protects against a *legitimate* client's own retry. A request can fail one check and pass the other; both are checked, neither substitutes for the other.

**Does this need a `sessions` database table?** **No — documented decision, not an oversight.** PHP's native file-based session storage (the default `session.save_handler=files`) is sufficient for a single-server cPanel deployment (one PHP process pool, one filesystem, no load-balanced multi-server fan-out) — there is exactly one place sessions live, and the OS filesystem already handles it correctly and atomically for this deployment shape. **If** the hosting setup ever changes to multiple app servers behind a load balancer (not the case for this single cPanel account today), file-based sessions would stop being reliable (a session created on server A wouldn't be readable from server B) and a DB-backed (or Redis-backed) session store would become necessary at that point — this is called out here as a forward-looking note, not a requirement for this design.

---

## 16. Audit & config (carried over unchanged in spirit from Audit §15, with FK targets now numeric)

```
audit_log
  audit_log_id       BIGINT UNSIGNED PK AUTO_INCREMENT
  request_id         VARCHAR(64)     NULL
  event_at           DATETIME        NOT NULL      -- UTC
  user_id            BIGINT UNSIGNED NULL FK -> users.user_id
  action             VARCHAR(100)    NOT NULL
  record_type        VARCHAR(50)     NOT NULL
  record_key         VARCHAR(100)    NOT NULL      -- loose reference (string), intentionally not a hard FK — must survive the referenced row's own eventual deletion
  previous_version    INT UNSIGNED   NULL
  new_version         INT UNSIGNED   NULL
  payload_summary    VARCHAR(2000)   NULL
  status             ENUM('ok','conflict','error') NOT NULL
  KEY ix_audit_record (record_type, record_key)
  KEY ix_audit_event_at (event_at)

master_setting
  setting_key    VARCHAR(100) PRIMARY KEY
  setting_value  VARCHAR(500) NOT NULL
```

`master_setting` carries forward `targetMode` and `pctDasar` as live config, and `pctOwnership`/`pctFranchise` as **historical-only** rows (Audit §14 L4) — the application must not read the latter two for any new-transaction pricing decision; they exist solely so a report on very old, already-priced historical data can still explain what rate scheme was in effect at the time.

**Retention (review point 9, LOCKED): `audit_log` is retained indefinitely — no partitioning is required at launch.** Partitioning-by-date remains a reasonable *future* performance option once row count actually justifies it, but it is not part of this design and not a launch blocker; nothing here schedules or requires it. This is a deliberate contrast with `idempotency_log`'s 90-day pruning (§13) — audit history is evidentiary and never expires by policy, idempotency history is purely a replay-protection window.

---

## 17. Full table list (reference index)

`factory`, `division`, `location`, `product`, `product_legacy_code`, `product_alias`, `migration_product_map`, `store`, `store_alias`, `migration_store_map`, `po_batch`, `po_item`, `po_store_item`, `po_closure`, `production_run`, `production_item`, `fg_batch`, `fg_batch_source`, `fg_item`, `delivery_order`, `delivery_order_item`, `shipment`, `shipment_item`, `invoice`, `invoice_shipment`, `invoice_item`, `payment`, `return_note`, `reject_note`, `retail_sale`, `customer_order`, `customer_order_item`, `stock_ledger`, `stock_balance`, `stock_transfer`, `stock_adjustment`, `document_sequence`, `users`, `roles`, `user_roles`, `user_factory_access`, `user_division_access`, `audit_log`, `idempotency_log`, `master_setting`.

**45 tables total** (44 → 45: `shipment` split into `shipment` header + new `shipment_item`, review point 1). See `docs/mysql-migration-map-v1.md` for the Sheet→table mapping and `docs/mysql-open-decisions-v1.md` for every point in this design still awaiting explicit sign-off.

---

## 18. Validation results (this pass, FINAL DESIGN CORRECTION)

`database/schema-v1.sql` was applied against a **disposable, local-only MariaDB 10.11.14 instance**, installed via `mariadb-install-db`/`mariadbd --skip-networking --user=root` into a throwaway datadir, exercised, then fully torn down (`SHUTDOWN;` + `rm -rf` the datadir and socket) — no live or persistent database of any kind was created or touched. This is a syntax/behavior check on the draft DDL, not a deployment. See the caveat in §0: **this proves the design is internally consistent, not that it matches `factory.amorgroup.id`'s real MySQL/MariaDB version — OD-4 stays open.**

**DDL build**: all 45 `CREATE TABLE` statements applied with zero errors (scenario 10). `SHOW TABLES` / `information_schema.tables` both confirm 45 tables in the resulting schema.

**Functional scenarios exercised** (seed data: 2 products, 1 real store, the synthetic `NON-OUTLET / PERORANGAN` store, 1 user):

| # | Scenario | Result |
|---|---|---|
| 1 | `shipment` header with multiple items | PASS — one header row, two `shipment_item` rows under it |
| 2 | Void shipment reverses every item once (conceptually) | PASS — header-level `UPDATE ... status='void', version=version+1` (once, not per item), followed by exactly one `stock_ledger` reversal row per original `shipment_item` row (2 in, 2 reversals out) |
| 3 | Invoice links shipment header | PASS — `invoice_shipment` row created against the `shipment` header id, not a per-product id |
| 4 | Synthetic `NON-OUTLET` store satisfies `store_id NOT NULL` | PASS (positive) — `customer_order` inserted against the synthetic store's id. PASS (negative) — a direct `INSERT ... store_id=NULL` on both `shipment` and `customer_order` was tried and correctly rejected: `ERROR 1048: Column 'store_id' cannot be null` |
| 5 | Duplicate open DO still blocked | PASS — a second `delivery_order` insert with the same `(tanggal, store_id, shipment_group)` while both rows are in an "open" status (`draft`) was rejected: `ERROR 1062: Duplicate entry '2026-09-04\|1\|MAIN' for key 'uq_delivery_order_open'` (the generated-`STORED`-column partial-unique-index pattern from §11, confirmed still working after the shipment changes) |
| 6 | Shipment/invoice document numbers unique | PASS — `document_sequence` seed row inserted cleanly; a duplicate `invoice_no` insert was rejected: `ERROR 1062: Duplicate entry 'INV-0001' for key 'uq_invoice_no'`. A duplicate `(shipment_id, product_id)` on `shipment_item` was also rejected (`uq_shipment_item`), confirming a shipment cannot silently gain two rows for the same product |
| 7 | No FK cascade deletes historical transactions from a master delete | PASS — `DELETE FROM product WHERE product_id=1` (referenced by `shipment_item`) rejected: `ERROR 1451`. `DELETE FROM store WHERE store_id=1` (referenced by `delivery_order`) rejected: `ERROR 1451`. Both are plain `RESTRICT` (InnoDB default, no `ON DELETE CASCADE` on either FK) — no code path in the schema can cascade a master-data delete into transactional history |
| 8 | Historical unverified invoice migrates without a physical shipment | PASS — `invoice` row inserted with `legacy_fulfillment_status='unverified'` and **zero** `invoice_shipment` rows; both the flag and the absent link were confirmed by direct query |
| 9 | New normal invoice cannot exist without a shipment | **Confirmed as an application/API-layer invariant, not a raw SQL constraint** — nothing in `invoice`'s DDL by itself prevents an `invoice` row with zero `invoice_shipment` rows (there is no SQL-native way to express "must have ≥1 child row" as a table constraint). The enforcement point is `docs/php-api-contract-v1.md` §8's `POST /api/invoices`, which hard-requires a non-empty `shipmentIds` (`400 MISSING_SHIPMENTS` otherwise) and always sets `legacy_fulfillment_status='verified'` — there is no request shape that can produce a shipment-less "verified" invoice. This is stated explicitly here rather than glossed over: the schema alone does not and cannot enforce this invariant; the API contract does |
| 10 | All 45 tables build cleanly | PASS — see "DDL build" above |

**Net result: 9 of 10 scenarios are enforced at the database-constraint level and were positively confirmed; scenario 9 is (and can only be) an application-layer invariant, confirmed by inspection of the API contract rather than a SQL error.** No scenario failed or required a design change during this validation pass.

---

**Status: MYSQL DESIGN V1 FINAL-CANDIDATE READY FOR REVIEW.** Not implementation complete. Not production ready. No SQL has been executed against any live or persistent database — see §0 for what disposable-instance validation was performed and, critically, what it does **not** confirm (the actual `factory.amorgroup.id` database version, OD-4, remains open). A draft-only, explicitly-marked-not-for-production DDL rendering of this document is at `database/schema-v1.sql`.
