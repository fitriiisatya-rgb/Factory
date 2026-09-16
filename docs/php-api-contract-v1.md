# Amor Factory — PHP REST API Contract V1

**Status: MYSQL DESIGN V1 READY FOR FINAL REVIEW**
No PHP code has been written or deployed. This is a conceptual contract only, revising `docs/mysql-migration-audit.md` §16 to reflect the review decisions finalized in `docs/mysql-schema-v1.md`.

---

## 1. Cross-cutting rules (apply to every endpoint below unless stated otherwise)

1. **Numeric IDs everywhere.** No path or payload field ever accepts a raw product/store name as an identifier. Every reference to a product/store/division/factory/user is its surrogate `*_id`. Name-based lookup (e.g., "find product by name for an autocomplete field") is a dedicated `GET .../search?q=` endpoint that **returns** ids — it is never accepted as an update/create key.
2. **Auth required on every endpoint** except `POST /api/auth/login`. Bearer token (or session cookie, decided at implementation time — not a schema-level concern) resolves to a `user_id` + set of `roles` (schema: `docs/mysql-schema-v1.md` §15). Every endpoint below states its minimum required role(s).
3. **Idempotency-Key required on every `POST`/`PUT`/`PATCH`/`DELETE`.** Sent as a request header, `Idempotency-Key: <client-generated uuid>`. Server behavior per `docs/mysql-schema-v1.md` §13: same key + same payload fingerprint → replay stored response; same key + different payload fingerprint → `409 IDEMPOTENCY_KEY_REUSE_MISMATCH`. Missing header on a mutating endpoint → `400 MISSING_IDEMPOTENCY_KEY`.
4. **`version` required on every update to a versioned entity** (the entity list in `docs/mysql-schema-v1.md` §12). Sent in the request body as `"version": <int>`. Server behavior: `UPDATE ... WHERE id=? AND version=?`; 0 rows affected → `409 VERSION_CONFLICT` with `{"currentVersion": <int>}` in the body so the client can re-fetch and retry. **There is no "version omitted" compatibility mode** — a mutating request to a versioned entity without a `version` field is rejected with `400 MISSING_VERSION`, full stop.
5. **Every write is one DB transaction.** Multi-table writes (e.g., DO ship → shipment rows + stock_ledger rows + delivery_order status update) either all succeed or all roll back. No endpoint performs a partial multi-statement write outside a transaction.
6. **Every write produces an `audit_log` row** in the same transaction where practical, else immediately after with a monitored (not silently swallowed) failure path — closing Audit §14 L17.
7. **All request/response dates**: business dates (`tanggal`) as `YYYY-MM-DD` strings interpreted as Asia/Jakarta calendar dates (`docs/mysql-schema-v1.md` §14); all timestamps as ISO-8601 UTC (`...Z` suffix).
8. **Error shape** (consistent across all endpoints): `{"ok": false, "code": "<MACHINE_CODE>", "message": "<human string>", ...extra fields per code}`. Success shape: `{"ok": true, "data": {...}}` or `{"ok": true, "data": [...], "meta": {...pagination...}}` for lists.

---

## 2. Auth

| Method | Path | Request | Response | Role |
|---|---|---|---|---|
| POST | `/api/auth/login` | `{username, password}` | `{token, user:{id, fullName, roles[]}}` | none |
| POST | `/api/auth/logout` | — | `204` | any |
| GET | `/api/auth/me` | — | current user + roles + (future) factory/division access grants | any |

---

## 3. Master data

| Method | Path | Request | Response | Role | Notes |
|---|---|---|---|---|---|
| GET | `/api/factories` | — | `factory[]` | any | Static 2-row list |
| GET | `/api/divisions` | — | `division[]` | any | |
| GET | `/api/products` | `?q=&divisionId=&aktif=` | `product[]` (with `product_id`) | any | `q` searches `product.name` and `product_alias.raw_name` |
| POST | `/api/products` | `{name, kategori, divisionId, hpp, harga, aktif}` | created `product` | ADMIN, PPIC | Rejects on `uq_product_name` violation with `409 DUPLICATE_PRODUCT_NAME`, pointing the caller at `/api/products/{id}/aliases` instead if this is meant to be an alias of an existing product |
| PUT | `/api/products/{id}` | fields + `version` | updated `product` | ADMIN, PPIC | |
| DELETE | `/api/products/{id}` | `version` | `204` | ADMIN | **New — closes Audit §14 L7.** Rejects with `409 PRODUCT_IN_USE` if any transactional table still references this `product_id` (no silent orphaning; deletion of a truly-unused product is fine, retiring an in-use one should use `aktif=false` instead) |
| POST | `/api/products/{id}/aliases` | `{rawName}` | created `product_alias` | ADMIN, PPIC | `409 ALIAS_ALREADY_MAPPED` if `rawName` already resolves (via `uq_product_alias_raw`) to a *different* product — never silently reassigns |
| DELETE | `/api/products/{id}/aliases/{aliasId}` | — | `204` | ADMIN | |
| GET | `/api/stores` | `?q=&channel=&active=` | `store[]` | any | |
| POST | `/api/stores` | `{canonicalName, channel}` | created `store` | ADMIN | |
| PUT | `/api/stores/{id}` | fields + `version` | updated `store` | ADMIN | |
| POST | `/api/stores/{id}/aliases` | `{rawName, factoryHint?}` | created `store_alias` | ADMIN | Same duplicate-alias guard as products |
| DELETE | `/api/stores/{id}/aliases/{aliasId}` | — | `204` | ADMIN | |
| POST | `/api/stores/{id}/merge` | `{intoStoreId}` | merged `store` | ADMIN | Moves all `store_alias` rows from `{id}` to `{intoStoreId}`, deactivates `{id}` (does not hard-delete — historical FK references to `{id}` as a `store_id` are never rewritten by a merge; only new aliasing changes) |

---

## 4. PO (demand)

| Method | Path | Request | Response | Role | Notes |
|---|---|---|---|---|---|
| GET | `/api/po?tanggal=&factoryId=` | — | `po_batch` + nested `po_item[]` + `po_store_item[]`, with computed `target` per item (schema §5.2's `hitungTarget` equivalent, applied server-side) | any | |
| POST | `/api/po/preview` | `{tanggal, factoryId, rows[]}` | merge preview: awal(existing, frozen)/revisiLama/revisiBaru/targetBaru per row, same shape as the legacy `poMergeDenganExisting` UI preview (Audit §7) | PPIC, ADMIN | Read-only — does not write |
| POST | `/api/po` | `{tanggal, factoryId, rows[], version?}` | created/updated `po_batch` | PPIC, ADMIN | `version` required only when `po_batch` already exists for this key (omit on first-ever upload, matching `INITIAL` mode); applies the awal-frozen/revisi-replaced merge rule from `docs/mysql-schema-v1.md` §5.2.1 server-side, not trusting the client to have pre-merged |
| DELETE | `/api/po/{tanggal}/{factoryId}` | `version` | `204` | ADMIN | |

---

## 5. Production (Ceklis)

| Method | Path | Request | Response | Role | Notes |
|---|---|---|---|---|---|
| GET | `/api/production?tanggal=&divisionId=` | — | `production_run` + items, with computed `sisa` (server-side `pdSisaTarget` equivalent) and the PO-Awal/PO-Tambahan breakdown fields (presentation-only, matches the existing Produksi-page filter — Audit §7) | any | |
| POST | `/api/production/progress` | `{tanggal, divisionId, rows[], version}` | updated `production_run` | PRODUCTION, PPIC, ADMIN | `version` **always required**, no legacy "strict-versioning-optional" carve-out (Audit §12 `productionProgress` was already strict; this generalizes that to every mutating endpoint per review point 7) |
| POST | `/api/production/{id}/submit` | `{version}` | `submitted` | PRODUCTION, PPIC, ADMIN | |
| POST | `/api/production/{id}/reopen` | `{version, reason}` | `reopened` | ADMIN | `reason` required, `400 REASON_REQUIRED` otherwise |

---

## 6. FG / Packing

| Method | Path | Request | Response | Role | Notes |
|---|---|---|---|---|---|
| GET | `/api/fg?tanggal=&factoryId=` | — | `fg_batch` + `fg_item[]` | any | |
| POST | `/api/fg/packing` | `{tanggal, factoryId, items[], version}` | updated `fg_batch`/`fg_item` | FG_PACKING, ADMIN | |
| POST | `/api/fg/{id}/ready` | `{version, sourceProductionRunIds[]}` | `ready_at` set, linked `production_run`s marked `verified_fg` | FG_PACKING, ADMIN | |

---

## 7. Delivery Orders (draft → preprint → ready → shipped)

| Method | Path | Request | Response | Role | Notes |
|---|---|---|---|---|---|
| GET | `/api/delivery-orders?tanggal=&storeId=&status=` | — | `delivery_order[]` + items | any | |
| POST | `/api/delivery-orders` | `{tanggal, storeId, shipmentGroup, plannedItems:[{productId, qty}], version?}` | created/updated `draft` | DELIVERY, PPIC, ADMIN | `version` omitted only on first create for this `(tanggal,storeId,shipmentGroup)`; if an open one already exists, the server reuses/updates it (mirrors `doDocIdFor`, Audit §6.2) and requires `version` |
| POST | `/api/delivery-orders/{id}/preprint` | `{version}` | `preprinted` | DELIVERY, ADMIN | |
| POST | `/api/delivery-orders/{id}/ready` | `{version, availableItems:[{productId, qty}]}` | `ready` | FG_PACKING, DELIVERY, ADMIN | `availableItems` is still client/caller-supplied in v1, per Audit §12.1's carried-over open item — see `docs/mysql-open-decisions-v1.md` OD-3 for the fuller server-side-computation option |
| POST | `/api/delivery-orders/{id}/ship` | `{version, actualItems:[{productId, qty}]}` | `shipped` + created `shipment` row(s) + `stock_ledger` row(s) | DELIVERY, ADMIN | **Single transaction**, exact validation ported from the hardened `handleDoDocShip_` (Audit §6.2/§12): status must be `ready` (else `409 DO_NOT_READY`/`DO_ALREADY_SHIPPED`/`DO_CANCELLED`); every `actualItems` entry must match a `delivery_order_item.product_id` (`422 INVALID_DO_ITEM` otherwise); qty ≤ **stored** `available_qty` (`422 ACTUAL_EXCEEDS_FG_AVAILABLE`) and ≤ **stored** `planned_qty` (`422 ACTUAL_EXCEEDS_PLANNED`); `actualItems` must be non-empty and explicit (`400 MISSING_ACTUAL_ITEMS` — no fallback to planned). On success: assigns `doc_no` via `document_sequence` (schema §7) if not already assigned, inserts one `shipment` row per item (`source_type='delivery_order'`), inserts one `stock_ledger` row per item (`event_type='shipment_out'`), updates `delivery_order.status='shipped'` — all in one transaction |
| POST | `/api/delivery-orders/{id}/cancel` | `{version}` | `cancelled` | DELIVERY, ADMIN | Blocked if already `shipped` (`409 DO_ALREADY_SHIPPED`) |
| POST | `/api/delivery-orders/{pastryId}/merge` | `{mainId?, mainVersion?, pastryVersion}` | merged `delivery_order` | DELIVERY, ADMIN | `409 ALREADY_SHIPPED` if either side has shipped; auto-search for an open MAIN doc explicitly excludes `shipped` docs (Audit §6.2's fixed bug, carried forward as a permanent rule here) |

---

## 8. Shipments (manual, non-DO path) and Invoices — shipment-gated by design (review point 3)

| Method | Path | Request | Response | Role | Notes |
|---|---|---|---|---|---|
| GET | `/api/shipments?tanggal=&storeId=&batch=` | — | `shipment[]` | any | |
| POST | `/api/shipments` | `{tanggal, storeId, items:[{productId, qty}], noSj?, shipmentGroup}` | created `shipment` row(s) (`source_type='manual_kirim'`) + `stock_ledger` rows | DELIVERY, ADMIN | Direct manual-Kirim path (bypasses the DO lifecycle), preserved per Audit §3 — but now writes `stock_ledger` atomically in the same transaction, closing the "no version conflict on Kirim" gap noted in Audit §12 by wrapping the whole call in a transaction even though `shipment` itself has no `version` column (it's append-only; the transaction boundary is what matters here, not optimistic concurrency on an immutable row) |
| DELETE | `/api/shipments/{id}` | — | `204` | ADMIN | Inserts a compensating `stock_ledger` reversal row (`event_type='reversal'`, `reversal_of_id` set) in the same transaction — never removes the original ledger effect silently |
| GET | `/api/invoices?storeId=&status=&outstandingOnly=` | — | `invoice[]` + computed `sisa`/`status`/`telat` (Piutang view, Audit §9) | any | |
| POST | `/api/invoices` | `{shipmentIds:[...], ratePct?, overrideReason?}` | created `invoice` + `invoice_item[]` + `invoice_shipment[]` | FINANCE, DELIVERY, ADMIN | **`shipmentIds` is required and must be non-empty — `400 MISSING_SHIPMENTS`.** This is the structural enforcement of "Invoice is generated from SHIPPED fulfillment" (review point 3): there is no request shape that creates an invoice from a `customer_order_id` or any other demand-side reference directly. `ratePct` defaults to `DEFAULT_FACTORY_RATE_PCT`; supplying a different value requires `overrideReason` (non-empty) or `400 OVERRIDE_REASON_REQUIRED`, and both are frozen onto each `invoice_item` at creation (Audit §9) |
| PUT | `/api/invoices/{id}/items/{itemId}` | `{qtyInvoice, version}` | updated item + recomputed `total` | FINANCE, ADMIN | Adjustment path for retur/reject/mutasi-driven qty reductions |
| POST | `/api/invoices/{id}/payments` | `{jumlah, cara, keterangan}` | created `payment` | FINANCE, ADMIN | Overpayment allowed, returns a `warning` field in the response rather than blocking (Audit §9) |

**No endpoint exists for "create an invoice directly from a customer_order."** A `sumber:'stok'` customer order must first go through `POST /api/shipments` (with `source_type` effectively `customer_order_fulfillment` — see the note below) before `POST /api/invoices` can reference it. This is the concrete API-level enforcement of review point 3's instruction not to preserve the legacy order-gated path as the target default.

| Method | Path | Request | Response | Role | Notes |
|---|---|---|---|---|---|
| POST | `/api/customer-orders/{id}/fulfill` | `{items:[{productId, qty}]}` | created `shipment` row(s) (`source_type='customer_order_fulfillment'`) + `stock_ledger` rows | PPIC, DELIVERY, ADMIN | The one sanctioned way a `customer_order` (Pesanan) turns into stock leaving the warehouse — for BOTH `sumber:'produksi'` and `sumber:'stok'` orders alike, unifying what were two different legacy code paths (Audit §9) into the single shipment-gated rule. `storeId` on the resulting `shipment` is nullable per schema §5.6/OD-9 if this order has no associated store (walk-in customer) |

---

## 9. Customer Orders (Pesanan) — pure demand

| Method | Path | Request | Response | Role | Notes |
|---|---|---|---|---|---|
| GET | `/api/customer-orders?tglProduksi=&status=` | — | `customer_order[]` + items | any | |
| POST | `/api/customer-orders` | `{storeId?, tglPesan, tglProduksi, tglAmbil, tipe, pemesan, kontak, alamat, pctOmset, sumber, items[], catatan}` | created `customer_order` | PPIC, ADMIN | **Does not create an invoice.** `sumber:'produksi'` items fold into the relevant `production_run` target the same way Pesanan does today (Audit §3) |
| PUT | `/api/customer-orders/{id}` | fields + `version` | updated | PPIC, ADMIN | |
| POST | `/api/customer-orders/{id}/status` | `{status, version}` | updated status | PPIC, ADMIN | No longer auto-creates/removes `stock_adjustment` rows on `selesai` (Audit §3's old behavior) — fulfillment/stock-out now happens exclusively via `POST /api/customer-orders/{id}/fulfill` (§8), so `status` becomes a pure workflow label decoupled from the stock effect |

---

## 10. Retur / Reject / Retail Sale / Stock Transfer / Stock Adjustment

| Method | Path | Request | Response | Role | Notes |
|---|---|---|---|---|---|
| GET/POST | `/api/returns` | `{tanggal, storeId, productId, qty, alasan}` | `return_note` CRUD | PPIC/ADMIN (write), any (read) | Now versioned-transaction-wrapped (idempotency + audit) even though `return_note` itself carries no `version` column (append-only; see schema §5.7) |
| GET/POST | `/api/rejects` | `{..., resolusi, nilai, invoiceId?}` | `reject_note` CRUD, `potong` path reduces the linked invoice item, `ganti` path creates a `stock_adjustment` (schema §9) | PPIC/FINANCE (write), any (read) | |
| GET/POST | `/api/retail-sales` | `{tanggal, storeId, productId, qty}` | `retail_sale` CRUD | PPIC/ADMIN (write), any (read) | |
| GET/POST | `/api/stock-transfers` | `{tanggal, productId, fromStoreId, toStoreId, qty, keterangan}` | `stock_transfer` create | DELIVERY/FINANCE (write), any (read) | **No `DELETE` endpoint** — see below |
| POST | `/api/stock-transfers/{id}/reverse` | `{keterangan?}` | new compensating `stock_transfer` row | ADMIN | Implements review point 11 — the only way to undo a transfer |
| GET/POST | `/api/stock-adjustments` | `{tanggal, productId, tipe, qty, keterangan}` | `stock_adjustment` create | PPIC/ADMIN (write), any (read) | **No `DELETE` endpoint** — see below |
| POST | `/api/stock-adjustments/{id}/reverse` | `{keterangan?}` | new compensating `stock_adjustment` row (`reverses_adjustment_id` set) | ADMIN | Implements review point 12 |

---

## 11. Reports (read-only, no mutation, no version/idempotency requirements)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/reports/dashboard?dari=&sampai=` | Pipeline, arus barang, PO-per-bakery, status-submit — same semantics as Audit §13, queried against `stock_ledger`/indexed tables instead of full in-memory recompute |
| GET | `/api/reports/omset?dari=&sampai=&storeId=&channel=` | Laporan Omset — reuses the same rate/formula logic as `/api/reports/dashboard`, per the existing app's own design principle (Audit §9/§13) carried forward, not reimplemented twice |
| GET | `/api/reports/stock-card?productId=&dari=&sampai=` | Kartu Stok — reads `stock_ledger` directly (indexed on `(product_id, location_id, event_date)`), replacing the current full-history recompute (Audit §14 L12) |
| GET | `/api/reports/rekap?dari=&sampai=` | Rekap cross-module recap |
| GET | `/api/reports/fulfillment?storeId=` | "Pesanan Toko belum terpenuhi" — Audit §13 explicitly notes this is lifetime-cumulative, not date-ranged, and does not split by `shipment_group`; both properties are preserved as-is here since the review did not ask to change this business rule |

---

## 12. Admin / migration-map endpoints (new — supports the ongoing resolution workflow in `docs/mysql-schema-v1.md` §4)

| Method | Path | Request | Response | Role | Notes |
|---|---|---|---|---|---|
| GET | `/api/admin/migration/products?status=unresolved\|conflict\|mapped` | — | `migration_product_map[]`, sorted by `occurrence_count DESC` by default (review highest-impact ambiguities first) | ADMIN | |
| POST | `/api/admin/migration/products/{id}/resolve` | `{targetProductId}` or `{createNewProduct:{...}}` | `mapped`, `resolved_by`=current user, `resolved_at`=now | ADMIN | Human-only action — no automated caller is permitted to hit this endpoint with a fuzzy-match result; `resolved_by` is always the authenticated `user_id`, never a service account |
| POST | `/api/admin/migration/products/{id}/flag-conflict` | `{notes}` | `conflict` | ADMIN | |
| GET/POST | `/api/admin/migration/stores...` | (mirrors the product endpoints exactly) | | ADMIN | |
| GET | `/api/admin/audit-log?recordType=&recordKey=&dari=&sampai=` | — | `audit_log[]` | ADMIN | |

---

## 13. Trial / destructive utility endpoints (review point 10) — staging/UAT only, never routed in production

**These endpoints do not exist in the production router at all.** They are compiled/deployed only in a staging/UAT build, gated by:
1. An environment check (`APP_ENV !== 'production'`) evaluated **server-side**, at router-registration time, not as a runtime `if` inside a handler that could be bypassed by a crafted request.
2. `ADMIN` role required, in addition to the environment gate.
3. No request parameter, header, or frontend-sent flag can substitute for either of the above — per the review's explicit instruction, "never rely only on frontend flag."

| Method | Path | Request | Response | Role + Env |
|---|---|---|---|---|
| POST | `/api/staging/trial/batch-delete` | `{tanggal, factoryId}` | cascade-delete summary | ADMIN, staging/UAT only |
| POST | `/api/staging/trial/full-reset` | `{}` | wipe summary | ADMIN, staging/UAT only |

If a production deployment is ever asked to expose these (it should not be), that is itself an incident, not a configuration toggle — hence "not routed," not merely "permission-denied."

---

## 14. Role → endpoint-group summary

| Role | Can access |
|---|---|
| `ADMIN` | Everything, including master-data delete, migration-map resolution, user/role management (not detailed above — standard CRUD on `users`/`roles`/`user_roles`), staging/UAT trial endpoints (staging env only) |
| `PPIC` | PO upload, customer orders, production read, master-data create (not delete) |
| `PRODUCTION` | Production progress/submit/reopen |
| `FG_PACKING` | FG packing/ready |
| `DELIVERY` | Delivery orders (full lifecycle), manual shipments, stock transfers (create), customer-order fulfillment |
| `FINANCE` | Invoices, payments, reject `potong` resolution, stock-adjustment reversal proposals (execution still `ADMIN`, per schema §9's "corrections are deliberate, reviewed events" intent) |
| `MANAGEMENT_VIEWER` | All `GET`/report endpoints only, no mutation rights anywhere |

Exact per-endpoint role lists in the tables above are the authoritative source; this table is a summary for quick review, not a separate rule set.

---

**Status: MYSQL DESIGN V1 READY FOR FINAL REVIEW.** No endpoint listed here has been implemented.
