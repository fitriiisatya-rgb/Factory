# Amor Factory — MySQL Migration Audit

**Status: MYSQL MIGRATION DESIGN READY FOR REVIEW**
Not "implementation complete." Not "production ready." No migration code, PHP, or SQL has been written. No existing source file has been modified as part of this audit.

This document is a full architecture + data-model audit of the current Google Apps Script / Google Sheets system, performed as the first step of migrating to:

`Frontend HTML standalone + PHP REST API + MySQL/MariaDB`, deployed on the same cPanel host as `factory.amorgroup.id`.

---

## 1. Executive Summary

Amor Factory is a single-page HTML/JS application (all logic in one inline `<script>` block, state in a global `D` object mirrored to `localStorage`) backed by a Google Apps Script Web App (`Code.gs`) that treats a Google Sheet as its database. The system already has a surprisingly mature concurrency layer for a Sheets-backed app — optimistic per-record versioning, requestId idempotency, a global script lock, and an append-only audit log — covering 8 of roughly 15 mutable entity families (PO, Ceklis/production, FGPacking, FGReady, Invoice, MasterProduk, TokoTipe, and the new DO-lifecycle documents). The remaining 7 families (Retur, Reject, Jual Konsumen, Pesanan, Pembayaran, Mutasi, StokAdj) are append-only with requestId dedup only — no conflict detection.

The most important structural fact for migration purposes: **there is no canonical numeric identity for products or stores anywhere in the persisted data.** Every table (Sheets and the in-memory `D` mirrors) stores raw product/store name strings; canonicalization (`mpResolveKey`/`mpGet` for products, `canonicalStoreName`/`D.masterToko` for stores) happens lazily at *read* time in the frontend, is never rewritten back into historical rows, and — for stores — **is not synced to the backend at all**. `D.masterToko` (the canonical-bakery-alias table) lives only in each browser's `localStorage`. This is the single largest gap to close before a MySQL schema with real foreign keys can be built.

The second major structural fact: Google Sheets forces a "read the whole sheet, filter in memory, rewrite the whole sheet" pattern (`deleteRowsWhere_` → `rewriteAll_` → `clearContents()`+`appendRow()` of everything). Every mutation to PO/Ceklis/FGPacking/DO is a full-sheet rewrite guarded by a **global, whole-script lock** (`LockService.getScriptLock()`) — not per-record. This is the correctness-preserving but throughput-limiting tradeoff the current backend accepts. MySQL row-level locking and transactions replace this trivially and are a genuine architectural improvement, not just a lift-and-shift.

Third: several entities are **never round-tripped from the backend to the frontend at all** (`doGet()` does not return FGPacking detail rows, Pembayaran, Mutasi, StokAdj, Reject, or Pesanan — only their `RecordVersion` numbers for the ones that have them). These currently behave as "local-only" per-device caches, patched over for the trial-delete feature with a lightweight tombstone mechanism. In MySQL, all of these become normal queryable tables with no special-casing needed — another case where the target architecture is strictly better than the source, not just equivalent.

Fourth: pricing/rate logic is already centralized and simple (`DEFAULT_FACTORY_RATE_PCT = 50`, one manual `OVERRIDE_FACTORY_RATE_PCT = 60` per invoice with a required reason, rate metadata frozen per invoice line at creation time) — this part of the migration is low-risk. Two coexisting invoice-creation code paths (shipment-gated `kBukaInvoice` vs. order-gated `psSyncInvoice` for Pesanan) and non-persisted, recomputed-at-render document numbering (`doNomor`, `invoiceNomor`) are the two real risks in the financial domain and are called out in detail in sections 9 and 19.

Fifth: the current codebase already has a genuine, executable regression/UAT suite (`tests/`, 50 frontend regression checks + 101 backend concurrency-matrix checks, all passing against this exact baseline — see §1.1) plus several UAT scripts with a small number of **pre-existing, already-failing checks** unrelated to this audit (see §1.1). These are documented as-is; nothing was changed to make them pass, per this task's explicit "don't touch source" constraint.

This audit does not redesign business rules. Every rule documented below (PO Awal/Revisi semantics, Retur = beban toko, Reject = tanggungan pabrik, DO lifecycle, shipmentGroup MAIN/PASTRY, 50%/60% pricing) is transcribed from the current source, not reinterpreted.

### 1.1 Current baseline test status (frozen, not modified during this audit)

| Suite | Location | Result |
|---|---|---|
| Frontend regression (PO revision, production, FG materialization, parser, data-safety) | `tests/run-tests.js` | **50/50 PASS** |
| Backend concurrency matrix (CONC01–10) | `tests/backend/conc-matrix.test.js` | **27/27 PASS** |
| Legacy race reproduction (proves the *old* pre-lock backend loses data) | `tests/backend/legacy-race-repro.test.js` | **5/5 PASS** (a PASS here means the legacy bug is successfully reproduced — expected) |
| Schema auto-migration | `tests/backend/schema-migration.test.js` | **19/19 PASS** |
| Schema-migration lock interaction | `tests/backend/schema-migration-lock.test.js` | **12/12 PASS** |
| Status lifecycle + strict-versioning hardening | `tests/backend/status-lifecycle-and-hardening.test.js` | **32/32 PASS** |
| New-backend lock blocks race | `tests/backend/new-backend-lock-blocks-race.test.js` | **6/6 PASS** |
| UAT: canonical store / combined shipment | `tests/uat-canonical-store.js` | **6 known FAILs** (TEST1/TEST2 — combined-DO-across-two-factories scenario; calls `kTarikDariFGBakery` with its **pre-shipmentGroup 3-argument signature**, which this session's DO/shipmentGroup feature changed to 4 arguments — see §14, §19) |
| UAT: daily omset engine | `tests/uat-daily-omset.js` | PASS (no explicit summary line printed by this script, no FAIL lines) |
| UAT: SKU identity (kode collision) | `tests/uat-sku-identity.js` | PASS (no FAIL lines) |
| UAT: store/FG/invoice (canonical audit, FG 3-state, compact invoice) | `tests/uat-store-fg-invoice.js` | **29/29 PASS** |
| UAT: lifecycle + delete consistency + performance | `tests/uat-lifecycle-delete-perf.js` | **28/29 PASS** — 1 known FAIL (`UI03`, "Riwayat Upload PO menampilkan tombol Hapus" expects 1 delete button, finds 2 — likely a pre-existing UI-count drift, not investigated further per this task's scope) |
| UAT: outlet pipeline | `tests/uat-outlet-pipeline.js` | PASS (no FAIL lines) |
| UAT: nav/compact fixes | `tests/uat-nav-compact-fixes.js` | **43/46 PASS** — 3 known FAILs (`SHIP03/05/06`, all about `kTarikDariFGBakery`/"Simpan DO" — same root cause as the canonical-store failures: this UAT script pre-dates the shipmentGroup signature change) |
| UAT: UI simplification | `tests/uat-ui-simplification.js` | **32/32 PASS** |
| UAT: final consolidated UI | `tests/uat-final-consolidated-ui.js` | PASS (no FAIL lines) |
| UAT: full flow (`uat.js`) | `tests/uat.js` | 80 checks run, no FAIL lines observed |

**Interpretation, not a fix**: the ~10 failing checks across `uat-canonical-store.js` and `uat-nav-compact-fixes.js` all stem from one root cause — those two UAT scripts call `kTarikDariFGBakery(tgl, bakery)` with the pre-shipmentGroup 2/3-argument signature, while the current source (this branch) requires a 4th `shipmentGroup` argument (see §14 item L1). This is a **known, pre-existing gap between an older UAT script and the current baseline**, not something introduced or fixed by this audit. It is recorded here for completeness and flagged in §19 as an open item; per this task's explicit instructions, no source file was touched to address it.

---

## 2. Current Architecture

```
Browser (any device)
  └─ amorcakes-manufacturing-v5-slate(2).html   (single file, 10,390 lines)
       ├─ inline <script>: all business logic, ~9,600 lines of JS
       ├─ global `D` object  ──persisted──▶  localStorage (key "amorcakes-arus-v1")
       └─ fetch() to Apps Script Web App /exec URL
              ├─ GET  → doGet()   (full-state snapshot pull, "Segarkan"/muatSemua())
              └─ POST → doPost()  (one mutation per call, payload.jenis selects handler)
                    │
                    ▼
         backend/Code.gs (Google Apps Script, 2,335 lines)
              ├─ LockService.getScriptLock()      — global mutation lock
              ├─ CacheService + "RequestLog" sheet — requestId idempotency
              ├─ "RecordVersion" sheet             — optimistic per-record versioning
              ├─ "AuditLog" sheet                  — append-only audit trail
              └─ ~24 named Sheets = the database (see §4)
```

- **Frontend filename**: `amorcakes-manufacturing-v5-slate(2).html` (10,390 lines; the file also embeds a 472-row `KATALOG_BAWAAN` product catalog literal — see §14).
- **Backend filename**: `backend/Code.gs` (2,335 lines).
- **Branch**: `claude/amor-factory-pricing-ui-final-6vv3q9`.
- **Baseline commit**: `2a4307ed422f316c969aaa8da0a39621151cfc66` (2026-09-15 09:20:43 +0000).
- **Working tree**: clean at time of audit (no uncommitted changes; verified via `git status --short`).
- **Known feature flags** (both live in the frontend AND the backend, must match):
  - `ENABLE_TRIAL_BATCH_DELETE = true` — gates the "Hapus Batch Trial" cascade-delete-one-PO-batch feature.
  - `ENABLE_TRIAL_FULL_RESET = true` — gates the "Reset Semua Data Trial" full-wipe feature.
- **Known trial-only features** (both explicitly labeled "FITUR SEMENTARA MASA TRIAL" in source, both gated by the flags above, both require a two-step confirm dialog + typed keyword in the UI):
  1. **Hapus Batch Trial** — cascade-deletes every record tied to one `tanggal|factory` PO batch across 14 sheets (see §6/§14 for the exact cascade order).
  2. **Reset Semua Data Trial** — wipes all 12 operational sheets (`PO, Ceklis, CeklisMeta, FGPacking, FGReady, Kirim, Invoice, Pembayaran, Retur, Reject, Jual, Pesanan, Mutasi, StokAdj, TokoTipe, Master, DODoc`) at once, preserving `MasterProduk` and `Settings`.
- **Companion test infrastructure** (pre-existing, not part of this audit's deliverable but directly relevant to "freeze the baseline"): `tests/` — a Node harness (`tests/harness.js`, `tests/uat-harness.js`) that extracts the frontend's own inline `<script>` and runs it under `jsdom` with stubbed `localStorage`/`fetch`/`XLSX`, so tests call the *real* app functions, not reimplementations; `tests/backend/gas-harness.js` similarly runs the real `Code.gs` under a mocked `SpreadsheetApp`/`LockService`/`CacheService`. See §1.1 for results.

---

## 3. Complete Module Inventory

Legend for **Authoritative Source**: `Sheet (versioned)` = round-tripped via `doGet`, protected by `mutateVersioned_`/expectedVersion. `Sheet (append-only)` = round-tripped or write-only via `mutateAppend_`, requestId-dedup only, no version conflict. `Local-only` = never returned by `doGet` at all today; each device's `D`/localStorage is that device's only copy.

| Module | Purpose | Key Frontend Functions | Backend Handler(s) | Sheet(s) | `D.*` field | Authoritative Source | Derived Data | Depends On |
|---|---|---|---|---|---|---|---|---|
| Upload PO | Upload/parse PO Awal & PO Revisi per tanggal+factory | `poProses`, `poMergeDenganExisting`, `poIdentitasBaris`, `poSimpanPreview`, `poHapusFactory` | `handlePoUpload_`, `handleHapusPO_` | `PO` | `D.po[tanggal][]` | Sheet (versioned, key `tanggal\|factory`) | `hitungTarget`, `targetUntukDivisi` | Master Produk (name resolution), Master Toko (store resolution) |
| Dashboard | Cross-cutting KPIs: pipeline, arus barang, PO-per-bakery, status-submit, trend | `dashData`, `dashTrenData`, `dashKategoriData`, `dashPipelineData`, `dashArusBarangData`, `dashByFactoryData` | *(none — read-only)* | — | *(pure derived, no D field of its own)* | Derived | Rolls up PO/Ceklis/FGPacking/Kirim/Retur/Reject | PO, Ceklis, FGPacking, Kirim, Retur, Reject, Pesanan |
| Produksi (Ceklis) | Daily production checklist per divisi, with PO Awal/Tambahan filter | `prodBuildChecklist`, `pdSisaTarget`, `pdSimpan`, `pdTutup`/`pdBukaKembali`, `renderPdRingkasan` | `handleCeklisProduksiReplace_`, `handleProductionProgressDelta_`, `handleCeklisSubmit_`/`handleCeklisReopen_` | `Ceklis`, `CeklisMeta` | `D.ceklis["tgl\|divisi"]` | Sheet (versioned, key `tanggal\|divisi`) | `targetUntukDivisi`, `pdSisaTarget` | PO (target source), Master Produk |
| FG / Packing | Finishgood verification + per-toko packing allocation | `fgBuildPanel`, `fgMaterializeAll`, `fgTandaiSiap`, `fgPackingEffectiveClosed` | `handleFgPacking_`, `handleFgReady_` | `FGPacking`, `FGReady` | `D.fgPacking["tgl\|factory"]` | Sheet (versioned, key `tanggal\|factory`) — **but packing detail rows are NOT returned by `doGet`** (local-only in practice; only the version number round-trips) | `stokGudang()` MASUK term | Ceklis (must be `submitted` + divisi is a "verifikasi" divisi) |
| Kirim Toko (manual DO) | Direct/manual delivery entry (bypasses the draft/preprint lifecycle) | `kBuildGrid`, `kSimpan`, `kTarikDariFG`/`kTarikDariFGBakery` | `handleAppendTransaksi_(SHEET_KIRIM,...)` | `Kirim` | `D.kirim[]` | Sheet (append-only) | `stokGudang()` KELUAR term, fulfillment | FG/Packing (source qty), Master Toko |
| DO Draft / Preprint / Ready / Shipped | Early-print DO lifecycle, decoupled from FG completion | `doDraftBuat`, `doDocCetak`, `doDocMatchFgReady_`, `doDocKonfirmasiKirimSimpan`, `doDocBatalkan` | `handleDoDocSave_`, `handleDoDocPreprint_`, `handleDoDocReady_`, `handleDoDocShip_`, `handleDoDocCancel_`, `handleDoDocMerge_` | `DODoc` (writes `Kirim` only at `shipped`) | `D.doDocs{}` (keyed by id) | Sheet (versioned, key = doc id) | — | FG/Packing (availableItems), PO (plannedQty at draft time) |
| Shipment Group MAIN/PASTRY | Split a bakery's shipment into independent MAIN/PASTRY documents | `shipmentGroupForProduk`, `fgReadyListByBakery`, `doDocGabungKeMain` | (folded into DO handlers above; `ShipmentGroup` column on `Kirim` and `DODoc`) | `Kirim.ShipmentGroup`, `DODoc.ShipmentGroup` | — | Sheet | `kFulfillmentFlatData` does **not** split by group (see §8) | DO Draft, FG/Packing |
| Laporan Omset | Daily Omset Pabrik per canonical bakery | `omsetPerBakeryData`, `omsetHarianRows`, `omsetSkuDetailData` | *(none — read-only)* | — | *(derived)* | Derived, deliberately reuses `omsetKirimRow`/`hargaDasar` from Dashboard | Kirim, Reject, Master Produk, Master Toko |
| Invoice & Piutang | Invoice creation/editing, payments, receivables aging | `kBukaInvoice`, `kInvoiceSimpan`, `kInvTerapkanOverride`, `bayarSimpan`, `invStatus` | `handleInvoiceUpsert_`; Pembayaran via `handleAppendTransaksi_` | `Invoice`, `Pembayaran` | `D.invoice{batch}`, `D.pembayaran[]` | Invoice: Sheet (versioned, key=batch). Pembayaran: Sheet (append-only) | `invTerbayar`, `invStatus` (lunas/sebagian/belum, telat) | Kirim (normal path) **or** Pesanan (order-gated path — two invoice-creation triggers exist, see §9) |
| Kartu Stok | Warehouse stock ledger / on-hand qty | `stokGudang`, `mutasiStok`, `rekapStokHarian` | *(none — read-only; fed by Ceklis/Kirim/StokAdj handlers)* | — | *(derived from D.ceklis+D.kirim+D.stokAdj)* | Derived, recomputed from full history every call (no memoized ledger) | Ceklis (FG-verified only), Kirim, StokAdj |
| Rekap | Cross-module recap (produksi/reject/kirim/retur/jual per product/kategori/toko) | `rekapData`, `renderRekapRingkasan`, `renderRekapKategori`, `renderRekapToko` | *(none — read-only)* | — | *(derived)* | Mostly independent aggregators; KPI panel reuses Dashboard/Omset functions | Ceklis, Kirim, Retur, Jual, PO |
| Master Produk | Product catalog CRUD (kategori/divisi/HPP/harga/aktif) | `mpSimpanManual`, `mpSetField`, `mpUploadFile`, `mpHapus` | `handleMasterProdukUpsert_` | `MasterProduk` | `D.masterProduk{produk}` | Sheet (versioned, key=produk name) — **but delete (`mpHapus`) is local-only, never synced** | `mpGet`/`mpResolveKey` used everywhere for pricing/kategori/divisi | KATALOG_BAWAAN (initial seed, frontend-only) |
| Master Toko | Canonical bakery/store alias mapping + channel | `mtkTambahAlias`, `mtkGabungKe`, `mtkSetChannel`, `ensureMasterToko` | `handleTokoTipeUpsert_` (channel only) | `TokoTipe` (channel only) | `D.masterToko{storeId}`, `D.tokoAlias{}` | **Local-only** — only the `channel` field syncs (via `tokoTipeUpsert`); the entire alias/canonical-name/merge layer has no backend counterpart at all today | `canonicalStoreName`, `resolveStore`, `storeIdentity` | — |
| Retur | Store-side returns (store's burden, no invoice/stock effect) | `serapReturSheet`, `returTerapkan`, `rHapusBatch` | `handleAppendTransaksi_(SHEET_RETUR,...)`, `handleHapusById_` | `Retur` (dual legacy reader `amorBacaRetur_` also reads this sheet by header-name, mixing in Reject rows via a `jenis` column) | `D.retur[]` | Sheet (append-only) — **but the app has no manual create UI**; all creation is via two import pipelines, both currently local-only (never call `kirimSheets`) | Dashboard/Rekap qty-only reconciliation | — |
| Reject | Factory-side liability (potong invoice / ganti barang) | `rjSimpan`, `serapReturSheet` (reject branch) | `handleReject_`, `handleHapusByBatch_` | `Reject` | `D.reject[]` | Sheet (append-only) | Triggers StokAdj (if `ganti`) or Invoice reduction (if `potong`) | Invoice (potong path), StokAdj (ganti path) |
| Pesanan (non-outlet orders) | Sales-exec / CS / walk-in custom orders | `psSimpan`, `psSetStatus`, `psSyncInvoice` | `handlePesanan_`, `handlePesananStatus_` | `Pesanan` (whole record as `PayloadJSON`) | `D.pesanan[]` | Sheet (append-only) | Auto-creates an Invoice at save time (order-gated, not shipment-gated); `selesai` status triggers a StokAdj `koreksi` | Ceklis target (if `sumber:"produksi"`), StokAdj |
| Jual Konsumen | Store-level POS sales for reconciliation | `posJualTerapkan`, `jHapusBatch` | `handleAppendTransaksi_(SHEET_JUAL,...)` | `Jual` | `D.jual[]` | Sheet (append-only) — **no manual create UI**; sole creation path is POS CSV upload, and that path is local-only (never calls `kirimSheets`) | `stokToko`, `renderRekonToko` | — |
| Pembayaran | Invoice payment ledger | `bayarSimpan`, `bayarHapus` | `handleAppendTransaksi_(SHEET_PEMBAYARAN,...)` | `Pembayaran` | `D.pembayaran[]` | Sheet (append-only) — **never returned by `doGet`** (local-only in practice) | `invTerbayar`, `invStatus` | Invoice |
| Mutasi | Inter-store stock transfer | `mutSimpan`, `renderMutHist` | `handleMutasi_` | `Mutasi` | `D.mutasi[]` | Sheet (append-only) — **never returned by `doGet`**; **no delete function exists in the frontend at all** | Creates a new destination-store Invoice, reduces source-store Invoice qty | Invoice |
| Stock Adjustment | Manual/derived warehouse corrections (waste/rusak/opname/koreksi/masuk) | `adjSimpan`, `adjHapus` | `handleStokAdj_`, `handleHapusById_` | `StokAdj` | `D.stokAdj[]` | Sheet (append-only) — **never returned by `doGet`**; also **never cascade-deleted** by Trial Batch Delete (by explicit design, see §14) | Direct term in `stokGudang()` | Also auto-created by Reject(`ganti`), Pesanan(`selesai`) |
| Trial Batch Delete | Cascade-delete one PO batch's data (trial/UAT convenience) | `trialHapusBatch`, `trialCascadeClearLocal_` | `handleTrialBatchDelete_` → `cascadeDeleteTrialBatch_` | 14 sheets (see §6) | — | Sheet-driven cascade + local mirror cascade | — | Everything keyed by `tanggal\|factory` |
| Trial Full Reset | Wipe all operational data at once (trial/UAT convenience) | `trialResetAllData`, `trialResetAllLocal_` | `handleTrialResetAll_` → `resetAllTrialData_` | 12 sheets (see §2) | — | Sheet-driven wipe + local mirror wipe + epoch propagation | — | Everything |
| AuditLog | Append-only mutation audit trail | *(read via server-only tools/manual sheet review; no dedicated frontend page)* | `appendAudit_` (called by every `mutateVersioned_`/most `mutateAppend_` paths) | `AuditLog` | — | Sheet (append-only, never deleted) | `readDeletedTrialBatches_` derives the trial tombstone feed from this | — |
| RequestLog | Idempotency ledger (requestId dedup, permanent fallback behind CacheService) | — | `checkIdempotent_`/`recordIdempotent_` | `RequestLog` | — | Sheet (append-only) | — | — |
| RecordVersion | Optimistic per-record version counters | — | `getVersion_`/`setVersion_`/`getAllVersions_` | `RecordVersion` | `D.recordVersions{type}{key}` | Sheet, mirrored client-side per record | expectedVersion checks in `mutateVersioned_` | 8 versioned record types only (see §11) |

---

## 4. Complete Sheet Inventory

All 24 sheets are declared in `HEADERS` (Code.gs lines 107–151) and created on demand by `getOrCreateSheet`. None are created outside this map — confirmed by grepping every `getSheetByName`/`insertSheet`/`appendRow`/`getRange(...).setValues`/`clearContents`/`deleteRows`-equivalent call in `Code.gs`; the only sheet accessed by a name *not* in `HEADERS` is the legacy `amorBacaRetur_()` reader, which reads the **same** `"Retur"` sheet by header name instead of by position (see §14, item L2) — it is not a separate hidden sheet.

| Sheet | Logical Key | Columns (exact, in order) | Created by | Written by | Read by | Lifecycle | Versioning |
|---|---|---|---|---|---|---|---|
| `PO` | `Tanggal\|Factory` (per-SKU rows within) | Tanggal, Factory, Kategori, Kode, Produk, POAwal, PORevisi, PB, StoresJSON, UpdatedAt | `handlePoUpload_` (first upload) | `handlePoUpload_` (full replace of the key), `handleHapusPO_` | `readPO_` (doGet), Trial cascade/reset | Transactional, historical | `mutateVersioned_`, recordType `po`, key `tanggal\|factory` |
| `Ceklis` | `Tanggal\|Divisi` (per-SKU rows within) | Tanggal, Divisi, Kode, Produk, Kategori, Target, Status, Aktual, Reject, Keterangan, UpdatedAt | `handleCeklisProduksiReplace_`/`handleProductionProgressDelta_` | Same + `handleCeklisSubmit_`/`handleCeklisReopen_` (rows unaffected, only meta) | `readCeklis_`, Trial cascade | Transactional, historical | `mutateVersioned_`, recordType `ceklis`, key `tanggal\|divisi` |
| `CeklisMeta` | `Tanggal\|Divisi` | Tanggal, Divisi, Status, SubmittedAt, Closed, ClosedAt, ClosedBy, ReopenReason | `touchCeklisMetaDraft_` | `touchCeklisMetaDraft_`, `markCeklisSubmitted_`, `markCeklisReopened_`, `markCeklisVerifiedFg_` | `readCeklis_` (joined into ceklis output) | Lifecycle state (see §6) | Same version as `Ceklis` (shared recordType `ceklis`) |
| `FGPacking` | `Tanggal\|Factory` (per-SKU-per-toko rows) | Tanggal, Factory, Kode, Produk, Toko, Qty, Status, Keterangan, UpdatedAt | `handleFgPacking_` | `handleFgPacking_` | *(NOT returned by `doGet` — local-only)* | Transactional, historical | `mutateVersioned_`, recordType `fgPacking`, key `tanggal\|factory` |
| `FGReady` | `Tanggal\|Factory` | Tanggal, Factory, ReadyAt, SourceVersionJSON | `handleFgReady_` | `handleFgReady_` | *(NOT returned by `doGet`, version only)* | Transactional | `mutateVersioned_`, recordType `fgReady` |
| `Kirim` | `Id` (grouped by `Batch`) | Id, Batch, Tanggal, Toko, Produk, Qty, NoSJ, Pengemudi, Kendaraan, CreatedAt, **ShipmentGroup** | `handleAppendTransaksi_`/`handleDoDocShip_` | Same + `handleHapusById_` | `readSimple_` (doGet) | Transactional, historical, append-only | `mutateAppend_` (requestId dedup only, no version conflict) |
| `Retur` | `Id` (grouped by `Batch`) | Id, Batch, Tanggal, Toko, Produk, Qty, Alasan, CreatedAt | `handleAppendTransaksi_` (rarely invoked — see §3) | Same + `handleHapusById_` | `amorBacaRetur_` (header-name based, ALSO returns Reject rows mixed in via a `Jenis` column — legacy dual-purpose reader, see §14) | Transactional, historical | `mutateAppend_` |
| `Jual` | `Id` (grouped by `Batch`) | Id, Batch, Tanggal, Toko, Produk, Qty, CreatedAt | `handleAppendTransaksi_` | Same + `handleHapusById_` | `readSimple_` (doGet) | Transactional, historical | `mutateAppend_` |
| `Master` | `Jenis:Nama` | Jenis, Nama | `handleMasterUpsert_` | `handleMasterUpsert_` (upsert only — **no delete handler**, matching the frontend's local-only `mHapus`) | `readMasterList_` (doGet, one call per jenis: divisi/produk/toko) | Simple list, append-if-missing | `mutateAppend_` |
| `MasterProduk` | `Produk` (full replace per key) | Produk, Kategori, Divisi, HPP, Harga, Aktif, UpdatedAt | `handleMasterProdukUpsert_` | Same (**delete is local-only**, `mpHapus` never syncs) | `readMasterProduk_` (doGet) | Master data, historical values overwritten in place | `mutateVersioned_`, recordType `masterProduk`, key=produk name |
| `TokoTipe` | `Toko` | Toko, Tipe, UpdatedAt | `handleTokoTipeUpsert_` | Same | `readTokoTipe_` (doGet) | Master data | `mutateVersioned_`, recordType `tokoTipe` |
| `Settings` | `Key` | Key, Value | `handleSettingsUpsert_` | Same | `readSettings_` (doGet) | Config, not transactional | `mutateVersioned_`, recordType `settings`, key `__settings__` |
| `Invoice` | `Batch` | InvoiceNo, Batch, Tanggal, Toko, NoSJ, ItemsJSON, Total, CreatedAt, UpdatedAt | `handleInvoiceUpsert_` | Same (full replace per batch) | `readInvoice_` (doGet) | Transactional, historical, editable | `mutateVersioned_`, recordType `invoice`, key=batch |
| `Log` | — (append-only debug log, not audit) | Waktu, Jenis, Payload, Error | `logPayload_`/`logError_` | Same | *(no read path — debug only)* | Debug/diagnostic, unbounded growth risk | None |
| `RecordVersion` | `RecordType:RecordKey` | RecordType, RecordKey, Version, UpdatedAt, UpdatedBy | `setVersion_` | `setVersion_` (delete+reinsert per bump) | `getVersion_`/`getAllVersions_` | Concurrency infrastructure | Is the version store itself |
| `RequestLog` | `RequestId` | RequestId, RecordType, RecordKey, Status, ResponseJSON, CreatedAt | `recordIdempotent_` | Same | `checkIdempotent_` | Idempotency infrastructure, append-only, unbounded growth risk | None |
| `AuditLog` | `EventId` (append-only) | EventId, RequestId, Timestamp, UserId, UserName, Role, Action, Tanggal, Divisi, RecordKey, PreviousVersion, NewVersion, PayloadSummary, Status | `appendAudit_` | Same, called from almost every handler | `readDeletedTrialBatches_` (derives trial tombstones) | Audit infrastructure, append-only, never deleted, unbounded growth risk | None |
| `Pembayaran` | `Id` (grouped by `Batch`/`InvoiceNo`) | Id, Batch, InvoiceNo, Toko, Tanggal, Jumlah, Cara, Keterangan, CreatedAt | `handleAppendTransaksi_(...,true)` (single-object mode) | Same + `handleHapusById_` | *(NOT returned by `doGet` — local-only)* | Transactional, historical | `mutateAppend_` |
| `Mutasi` | `Id` | Id, Tanggal, Produk, Asal, Tujuan, Qty, Keterangan, BatchAsal, BatchTujuan, CreatedAt | `handleMutasi_` | Same (**no delete handler at all**, matching frontend) | *(NOT returned by `doGet`)* | Transactional, historical, append-only forever | `mutateAppend_` |
| `StokAdj` | `Id` | Id, Tanggal, Produk, Tipe, Qty, Keterangan, CreatedAt | `handleStokAdj_` | Same + `handleHapusById_` | *(NOT returned by `doGet`)* | Transactional, historical; **never cascade-deleted** by Trial Batch Delete | `mutateAppend_` |
| `Reject` | `Batch` | Id, Batch, Tanggal, Toko, Produk, Qty, Alasan, Resolusi, Nilai, InvoiceBatch, CreatedAt | `handleReject_` | Same + `handleHapusByBatch_` | *(NOT returned by `doGet`)* | Transactional, historical | `mutateAppend_` |
| `Pesanan` | `Id` | Id, No, Status, PayloadJSON, CreatedAt, UpdatedAt | `handlePesanan_` | Same + `handlePesananStatus_` (rewrite) + `handleHapusById_` | *(NOT returned by `doGet`)* | Transactional, historical; whole record opaque JSON blob | `mutateAppend_` |
| `DODoc` | `Id` | Id, NoSJ, Tanggal, Toko, CanonicalStore, ShipmentGroup, Status, PlannedItemsJSON, AvailableItemsJSON, ActualItemsJSON, Catatan, Batch, CreatedAt, CreatedBy, PreprintedAt, ReadyAt, ShippedAt, ShippedBy, UpdatedAt | `handleDoDocSave_` | All 6 `handleDoDoc*_` handlers | `readDoDocs_` (doGet, **full table**, unlike FGPacking/Pembayaran/etc.) | Lifecycle state machine (see §6); historical documents from before this feature never appear here (they're plain `Kirim` rows) | `mutateVersioned_`, recordType `doDoc`, key=doc id |

**Can be deleted?** Only `PO`/`Ceklis`/`FGPacking`/`Kirim`/`Invoice`/`Pembayaran`/`Retur`/`Reject`/`Jual`/`Mutasi`/`TokoTipe`/`Master`/`DODoc` rows are ever deleted by application code (Trial cascade or the individual `hapus*` endpoints). `RecordVersion` rows are only deleted-and-reinserted (never left as a gap) during a version bump. `AuditLog` and `RequestLog` are **never** deleted by any code path (by design — audit/idempotency history). `StokAdj` rows can be deleted individually (`hapusStokAdj`) but are explicitly **never** cascade-deleted by Trial Batch Delete.

---

## 5. Frontend State Inventory

Classification legend: **A** = authoritative transaction (should become a MySQL table of record), **B** = backend mirror/cache (currently exists because Sheets round-trips are partial; in MySQL this classification disappears — the table itself is the source), **C** = derived runtime cache (must NOT be persisted in MySQL; recompute or memoize server-side/client-side as a performance detail only), **D** = UI preference (fine to keep as `localStorage`/cookie in the new architecture), **E** = obsolete/legacy (candidate to drop or migrate as read-only history only).

| `D.*` field | Shape | Classification | Notes |
|---|---|---|---|
| `D.po` | `{tanggal: [{factory,kategori,kode,produk,poAwal,poRevisi,pb,stores[],catatan}]}` | **A** | Full snapshot merge semantics, see §7 |
| `D.ceklis` | `{"tgl\|divisi": {submitted,submittedAt,closed,closedAt,reopenReason,rows:[{kode,produk,kategori,target,status,aktual,reject,keterangan}]}}` | **A** | `metaStatus` field mirrors `CeklisMeta.Status` (state machine, §6) |
| `D.fgPacking` | `{"tgl\|factory": {readyAt, packed:{...}, dikirimKe:[]}}` | **A** (currently behaves as B in practice — never round-tripped, see §4) | Packing detail + `dikirimKe` per-shipment-group marker |
| `D.kirim` | `[{id,batch,tgl,toko,produk,qty,noSJ,catatan,shipmentGroup}]` | **A** | KELUAR term of stock, invoice basis, omset basis |
| `D.kirimClosed` | `{"tgl\|toko": true}` | **A** (small, but a real business decision — "pesanan ditutup") | Not synced under its own `jenis` — needs a migration decision (see §17) |
| `D.doDocs` | `{id: {...DO lifecycle record}}` | **A** — but explicitly documented in-source as "localStorage di sini murni cache/mirror" (backend `DODoc` is authoritative) | Fully round-tripped via `doGet().doDocs` — closest thing to a true B-in-practice-but-A-by-design field |
| `D.invoice` | `{batch: {invoiceNo,batch,tgl,toko,noSJ,items[],total,ratePct,rateSource,overrideReason,catatan,sumber,createdAt,updatedAt}}` | **A** | |
| `D.pembayaran` | `[{id,batch,tgl,jumlah,cara,keterangan,createdAt}]` | **A** (currently B-in-practice — local-only) | |
| `D.retur` | `[{id,batch,tgl,toko,produk,qty,alasan,resolusi,invoiceBatch,sumber}]` | **A** | `sumber:"sheet"` vs implicit manual — see §14 |
| `D.reject` | `[{id,batch,tgl,toko,produk,qty,alasan,resolusi,nilai,invoiceBatch,sumber}]` | **A** | |
| `D.jual` | `[{id,batch,tgl,toko,produk,qty,sumber:"pos"}]` | **A** (currently B-in-practice — local-only) | |
| `D.pesanan` | `[{id,no,tglPesan,tglProduksi,tglAmbil,tipe,pemesan,kontak,alamat,pctOmset,sumber,items[],catatan,status,createdAt}]` | **A** (currently B-in-practice — local-only) | Whole record stored as opaque JSON on the backend (`PayloadJSON`) — a genuine schema gap for MySQL (see §15) |
| `D.mutasi` | `[{id,tgl,produk,asal,tujuan,qty,keterangan,batchAsal,batchTujuan,createdAt}]` | **A** (currently B-in-practice — local-only, and no delete path exists at all) | |
| `D.stokAdj` | `[{id,tgl,produk,tipe,qty,keterangan,createdAt,sumber}]` | **A** (currently B-in-practice — local-only) | Only entity explicitly exempted from Trial Batch Delete cascade |
| `D.masterProduk` | `{produk: {kategori,divisi,hpp,harga,aktif,updatedAt}}` | **A** | Keyed by raw/normalized name string, not an ID — see §10 |
| `D.masterToko` | `{storeId: {storeId,canonicalName,aliases[],channel,active,createdAt,updatedAt}}` | **A in intent, but currently pure D/localStorage-only in practice** | Never synced to backend at all (see §10) — the single biggest migration gap |
| `D.tokoAlias` | `{rawName: officialName}` | **E** (legacy, pre-dates `D.masterToko`) | Still read by `cocokToko` for Retur/POS import matching |
| `D.produkAlias` | `{produkAsli: produkTujuan}` | **A** (small but real user-confirmed mapping) | Feeds `cocokProduk` PO-import fuzzy matcher |
| `D.tokoTipe` | `{toko: "ownership"\|"franchise"}` | **A** | Reporting/grouping label only — does **not** affect pricing (§9) |
| `D.settings` | `{targetMode, pctDasar, pctOwnership, pctFranchise}` | **A** (config, small) | `pctOwnership`/`pctFranchise` are **E** (dead — see §9, §14) |
| `D.divisi`, `D.products`, `D.stores` | flat string arrays | **A** (thin lists) / **E** for the auto-seeded portion of `D.products` from `KATALOG_BAWAAN` until actually used | |
| `D.recordVersions` | `{type: {key: version}}` | **B** (pure sync-protocol cache, mirrors `RecordVersion` sheet) | In MySQL this becomes a `version` column on each authoritative row — no separate cache table needed |
| `D.trialTombstonesProcessed`, `D.lastProcessedTrialResetEpoch` | small counters/maps | **B** (sync-protocol bookkeeping, trial-feature-specific) | Not needed post-migration if trial features are retired (§14) |
| `D.returImported` | `{sheetRowId: true}` | **B** (dedup marker for the external Retur/Reject sheet-pull feature) | |
| `D.returTertahan` | `[]` | **C** (held-for-manual-resolution import rows) | |
| `kUnfulfilledCache`, `kUnfulfilledFlatCache`, `dashProdukCache`, `returPreviewCache`, `posJualCache`, `omsetDrillState`, `poPreview` (module-level JS vars, not on `D`) | various | **C** | Pure render caches, explicitly cleared by Trial Reset to avoid stale-number bugs (a bug of this exact shape was fixed in an earlier session on this branch) |
| `cfg-url`, `cfg-returUrl` (separate `localStorage` keys, not on `D`) | strings | **D** | Connection config — becomes an API base URL / env config in the new architecture, not app data |
| `D.masterTokoBootstrapV`, `D.katalogBawaanV`, `D.fixFactoryBoluV` | version-flag integers | **E** | One-time migration markers for past frontend-only data fixes; not meaningful post-MySQL-migration |

**Target architecture invariant** (already stated by the user, restated here as the design constraint that shapes §15–18): MySQL becomes the single authoritative transaction source for every **A**-classified field above. `localStorage` in the new frontend is restricted to **D**-classified items (UI prefs, session/API token) plus narrowly-scoped **C** (derived, disposable) caches and, if genuinely needed for offline-tolerant UX, an explicitly-labeled "unsynced draft" buffer — never a second copy of authoritative state.

---

## 6. Entity Model

For each candidate entity: current representation, current identity, fields, relationships, lifecycle, who can create/update, and historical-compatibility concerns.

| Entity | Current representation | Current ID/key | Key fields | Relationships | Lifecycle | Create/Update | Historical compat concern |
|---|---|---|---|---|---|---|---|
| **Factory** | Hardcoded string enum (`"karangtengah"`, `"cibadak"`) throughout both files | the string itself | — | Product→factory inferred via `D.po`/`DIVISI_BAWAAN` | Static, 2 values only | Not user-editable (hardcoded) | Trivial to migrate to a 2-row lookup table |
| **Division (Divisi)** | `D.divisi` flat array + `DEFAULT_DIVISI` (8 entries, incl. 3 "verifikasi"/FG pseudo-divisions) | division name string | `divisiVerifikasi(d)` flags FG pseudo-divisions | Products map to a division via `DIVISI_BAWAAN`/`mpGet(produk).divisi` | Static-ish, admin-addable via Master page | `mHapus`/`mTambah` (list add/remove is local-only for delete, synced for add via `divisiUpsert`) | Name-string identity; migrate to `division_id` + keep name unique |
| **Product** | `D.masterProduk{name}` + `D.products[]` flat list; 472-row `KATALOG_BAWAAN` seed | **raw/normalized name string** (`mpResolveKey`) — **no numeric ID exists anywhere** | kategori, divisi, hpp, harga, aktif | Every transactional table stores the raw `produk` string | Seeded (katalog bawaan) → created/edited via Master Produk UI → referenced by every transaction | Master Produk CRUD; delete is local-only (bug, see §14) | **Highest-risk entity for the migration** — see §10 |
| **Product Alias** | `D.produkAlias{raw:resolved}` (user-confirmed PO-import mappings) | raw name string | — | Feeds `cocokProduk` fuzzy PO-import matching | Grows organically, never pruned | Confirmed inline during PO upload (`poResolusiPetakan`) | Needs a real `product_alias` table with FK to `product_id` |
| **Store (Bakery/Toko)** | `D.stores[]` (raw names, one per factory-side spelling) + `D.masterToko{storeId}` (canonical layer, **local-only**, see §5/§10) | raw name string; `storeId` in the canonical layer is usually just the raw name itself (one hardcoded exception, `"CKLE"`) | canonicalName, aliases[], channel, active | Every transactional table stores the raw `toko` string | Grows from PO uploads; canonical merges are manual (`mtkGabungKe`) or the one hardcoded bootstrap pair | Master Toko UI — **not synced to backend at all** | **Second-highest-risk entity** — no server-side source to migrate FROM; must be freshly built (§10, §19) |
| **Store Alias** | `D.tokoAlias{}` (legacy) + `D.masterToko[x].aliases[]` (current) | raw string | — | — | Two parallel/coexisting layers (§10) | UI-only, unsynced | Reconcile both layers into one `store_alias` table |
| **PO Batch / PO Item** | One `PO` sheet row = one SKU for one `tanggal+factory`, with a `StoresJSON` breakdown | `tanggal|factory|kode(or name)` (`poIdentitasBaris`) | poAwal (frozen after first upload), poRevisi (latest-snapshot, replaces not adds), pb (always ignored for target), stores[] | Belongs to a factory+date; feeds Ceklis targets, Dashboard, Kartu Stok reconciliation | See §7 for full upload/revision lifecycle | `poProses`/`poSimpanPreview` (upload), `poHapusFactory` (delete) | Row identity by kode+name composite (`poIdentitasBaris`), not a surrogate key — migrate carefully (§10) |
| **PO Store Item** | `stores[]` array inside a PO row (`{toko, poAwal, poRevisi}`) | store name string within the row | — | One-to-many under PO Item | Same lifecycle as parent PO Item | Same as parent | Becomes its own child table with FK to `po_item_id` + `store_id` |
| **Production Run (Ceklis)** | `D.ceklis["tgl|divisi"]` (header/meta) | `tanggal|divisi` | status machine (§6.1 below), submittedAt, closedAt, reopenReason | One per divisi per date; source-verified into FG for "verifikasi" divisions | See §6.1 | `pdSimpan` (delta/replace), `pdTutup`/`pdBukaKembali` (submit/reopen) | Composite key, no surrogate ID — fine as a natural key in MySQL too (`UNIQUE(tanggal,divisi_id)`) |
| **Production Item** | Row inside Ceklis (`{kode,produk,kategori,target,status,aktual,reject,keterangan}`) | `skuId(kode,produk)` within the parent | — | Child of Production Run | Same as parent | Same as parent | `skuId` (kode+normalized-name) should become the join key into `product_id`, not `kode` alone (kode alone is a proven collision risk, per `tests/uat-sku-identity.js`) |
| **FG Batch** | `D.fgPacking["tgl|factory"]` header (`readyAt`, `dikirimKe[]`) | `tanggal|factory` | — | Verifies a Production Run's netto output; feeds packing | Draft → readyAt set ("Siap Diambil Delivery") | `fgTandaiSiap` | Never round-tripped from backend today (§4) — becomes a real table post-migration |
| **FG Item** | `packed{skuId|toko}` entries inside FG Batch | `skuId + toko` | qty, status, keterangan | Child of FG Batch, one row per SKU per destination store | Same as parent | `fgMaterializeAll`/packing form | Needs `fg_item_id` with FK to `fg_batch_id`, `product_id`, `store_id` |
| **Delivery Order (DO Draft/Preprint/Ready/Shipped)** | `DODoc` sheet row (`D.doDocs{id}`) | client-generated deterministic `id` (`doDocIdFor(tgl,canonicalStore,shipmentGroup)`) | status machine (§6.2), plannedItems/availableItems/actualItems JSON blobs, shipmentGroup | One per (tanggal, canonical store, shipmentGroup) at a time (barring the deliberate multi-DO-after-final-status escape hatch) | See §6.2 | 6 `handleDoDoc*_` handlers | JSON-blob items need to become real child rows (`do_item`) for MySQL |
| **Delivery Order Item** | Entry inside `PlannedItemsJSON`/`AvailableItemsJSON`/`ActualItemsJSON` | `kode + normalized produk` (`doDocItemKey_`, hardened this session) | plannedQty / availableQty / actualShipQty | Child of DO | planned → (available) → actual, only actual persists to Kirim | Written wholesale by the parent DO's handlers | Straightforward JSON→rows migration |
| **Shipment (Kirim row)** | `Kirim` sheet row | `Id` (backend-assigned pattern `docId+"-"+kode` when from a DO; frontend `uid()` otherwise) | tgl, toko, produk, qty, noSJ, shipmentGroup, batch | Grouped by `batch` into one logical delivery; is the sole basis for stock-out, fulfillment, Omset, and (normally) Invoice | Append-only; a `hapusKirim` delete exists | `handleAppendTransaksi_`/`handleDoDocShip_` (the only two writers) | Historical rows without `ShipmentGroup` must read as `MAIN` (already implemented both sides) |
| **Invoice** | `Invoice` sheet row | `Batch` (usually = the Kirim batch id; `"mut-"+uid()` for mutasi-derived invoices; `"PSN|"+id` for Pesanan-derived invoices) | items[] (qtyDO, qtyInvoice, harga, subtotal, ratePct, rateSource, overrideReason), total | One invoice per batch; batch may or may not correspond to a real Kirim batch (see §9 for the Pesanan exception) | Created at `kBukaInvoice` (shipment-gated) OR at `psSimpan`/`psSyncInvoice` (order-gated, no shipment precondition) — **two different creation triggers, see §9** | `kInvoiceSimpan`, `kInvTerapkanOverride`, `simpanInvoicePerubahan` | Non-persisted sequence numbering (`invoiceNomor`) — real risk, §19 |
| **Invoice Item** | Entry inside `Invoice.items[]` | `produk` (+ `kode` free-text, not authoritative) | qtyDO, qtyInvoice, harga, subtotal, ratePct/rateSource/overrideReason (frozen at creation) | Child of Invoice | Adjustable (retur/reject/mutasi can reduce `qtyInvoice`) | `kInvoiceSimpan` and the qty-reduction helpers | Rate metadata must be frozen per row at migration time — do not recompute historical invoices against a new default rate |
| **Payment (Pembayaran)** | `Pembayaran` sheet row | `Id`, FK by exact `batch` string match to Invoice | jumlah, cara, keterangan | Many-to-one into Invoice | Append-only, individually deletable | `bayarSimpan`/`bayarHapus` | Overpayment is allowed (warned, not blocked) — decide whether MySQL should keep that tolerance |
| **Return (Retur)** | `Retur` sheet row / `D.retur[]` | `Id` | produk, toko, qty, alasan, resolusi (usually blank), sumber | Store's burden; no stock/invoice effect by default | Import-only creation (see §3) | `serapReturSheet`/`returTerapkan` (both local-only today), `rHapusBatch` | The dual-purpose legacy `amorBacaRetur_` reader (§14) needs a clean split before migration |
| **Reject** | `Reject` sheet row / `D.reject[]` | `Id`/`Batch` | produk, toko, qty, resolusi (`potong`\|`ganti`), nilai, invoiceBatch | Factory's liability; `potong`→reduces invoice, `ganti`→creates a StokAdj waste entry | Manual (`rjSimpan`) or import (`serapReturSheet` reject branch) | `rjSimpan`, `rjHapusBatch` | Two creation code paths (manual UI vs. sheet import) both need to land in the same table |
| **Stock Movement (Kartu Stok ledger)** | Not a stored entity — **computed** by `mutasiStok()` by reading Ceklis(FG-verified)+Kirim+StokAdj on every call | — | masuk/keluar/doc/jenis per row | Synthesizes 3 other tables | Always-current, recomputed | N/A (derived) | Migration candidate: either keep as a computed view/query, or introduce a materialized `stock_ledger` table for performance (recommend the latter — see §15) |
| **Stock Adjustment** | `StokAdj` sheet row / `D.stokAdj[]` | `Id` | tipe (masuk/waste/rusak/opname/koreksi), qty (signed), sumber | Direct term in stock formula; also auto-created by Reject(ganti)/Pesanan(selesai) | Manual (`adjSimpan`) or auto-created | `adjSimpan`/`adjHapus` + auto-create call sites | **Never cascade-deleted** by Trial Batch Delete — an explicit, permanent design decision to preserve during migration if Trial features are ported at all |
| **Customer Order (Pesanan)** | `Pesanan` sheet row, whole record as opaque `PayloadJSON` | `Id` | tipe, pemesan, pctOmset, sumber (produksi\|stok), items[], status | Auto-creates an Invoice; `sumber:"produksi"` folds into Ceklis targets; `status:"selesai"` triggers a StokAdj deduction | Draft → ... → selesai (a simple status field, not a full state machine) | `psSimpan`, `psSetStatus`, `psHapus` | The `PayloadJSON` blob needs to be unpacked into real `order`/`order_item` tables |
| **Retail Sale (Jual Konsumen)** | `Jual` sheet row / `D.jual[]` | `Id`/`Batch` | produk, toko, qty | Store-level POS reconciliation only, no stock-gudang effect | Import-only (POS CSV, local-only creation today) | `posJualTerapkan` (local-only), `jHapusBatch` (synced) | — |
| **Stock Transfer (Mutasi)** | `Mutasi` sheet row / `D.mutasi[]` | `Id` | produk, asal, tujuan, qty, batchAsal, batchTujuan | Adjusts two stores' invoices; no gudang stock effect | **Create-only — no delete path anywhere** | `mutSimpan` | Decide during migration whether to finally add a delete/reversal path |
| **User / Role** | Not modeled at all — `actor = {userId, userName, role}` is free-text metadata sent by the client and trusted as-is for audit labeling ONLY | — | — | — | — | Anyone with the Web App URL can call any endpoint including reset (explicitly documented as an unsolved gap in Code.gs's own header comment) | **Must be designed from scratch** for the PHP/MySQL system — see §15/§19 |
| **Audit Log entry** | `AuditLog` row | `EventId` (UUID) | action, recordKey, previousVersion/newVersion, payloadSummary, status | One per mutation attempt (success, conflict, or error) | Append-only forever | `appendAudit_`, called from nearly every handler | Straightforward append-only table; consider partitioning/archival policy for growth |
| **Idempotency Request** | `RequestLog` row + `CacheService` (6h TTL) fast path | `RequestId` | recordType, recordKey, status, responseJSON | One per requestId ever seen | Append-only forever | `recordIdempotent_` | MySQL: `UNIQUE(request_id)` + a short-TTL cache layer (Redis/APCu) mirroring the current two-tier design is a direct, faithful port |

### 6.1 Production (Ceklis) lifecycle — confirmed from `Code.gs` lines 1852–1908 and frontend `CEKLIS_STATUS_*`

| State | Meaning | Set by |
|---|---|---|
| `not_started` | No `CeklisMeta` row exists yet for this `tanggal+divisi` | Default (absence of a row) |
| `draft` | Progress saved but not submitted | `productionProgress`/`ceklisProduksi` (legacy replace) |
| `submitted` | Final submit; `Closed=true` | `ceklisSubmit`/`ceklisTutup` — **the only place `SubmittedAt` is written** |
| `reopened` | Re-opened after submit, `Closed=false`, `SubmittedAt` preserved for history | `ceklisReopen` (requires a non-empty `reason`, audited) |
| `verified_fg` | The Finishgood/Packing divisi has confirmed this production run's output | `markCeklisVerifiedFg_`, called from `handleFgReady_` for every divisi named in `payload.sourceVersions` |

### 6.2 DO Draft/Preprint/Ready/Shipped lifecycle — confirmed from `Code.gs` lines 1454–1691 (hardened this session)

| From | Action (`payload.jenis`) | To | Backend mutation | Validation | Side effects |
|---|---|---|---|---|---|
| *(none)* | `doDocSave` | `draft` | Insert `DODoc` row | id/tanggal/toko required; blocked if the id already exists and is `shipped`/`cancelled` | None — plannedItems only, **no** stock/Kirim/Omset effect |
| `draft` | `doDocSave` (called again) | `draft` (unchanged) | Full replace of the same row | Same as above | None |
| `draft` | `doDocPreprint` | `preprinted` | Set `PreprintedAt` (once), bump status | Blocked if `shipped`/`cancelled` | None — still planning only; "print = shipped" is explicitly never true in this system |
| `preprinted` | `doDocPreprint` (re-print) | `preprinted` (unchanged) | No-op status-wise, `PreprintedAt` untouched if already set | — | None |
| `draft`/`preprinted` | `doDocReady` | `ready` | Store `AvailableItemsJSON` (client-computed FG-availability), set `ReadyAt` | Blocked if `shipped`/`cancelled` | None yet — availability is recorded, not consumed |
| `ready` | `doDocShip` | `shipped` | **Atomically**, in one locked/versioned mutation: validate (see below), append rows to `Kirim`, set `ActualItemsJSON`/`ShippedAt`/`ShippedBy` | **Hardened this session**: status must be exactly `ready` (else `DO_NOT_READY`/`DO_ALREADY_SHIPPED`/`DO_CANCELLED`); every actual item must match a planned item by kode+normalized-name (`INVALID_DO_ITEM` otherwise); `actualShipQty` capped by the **stored** `AvailableItemsJSON` (`ACTUAL_EXCEEDS_FG_AVAILABLE`) and the **stored** `PlannedItemsJSON` (`ACTUAL_EXCEEDS_PLANNED`); `actualItems` must be sent explicitly (`MISSING_ACTUAL_ITEMS` — the old draft-as-actual fallback was removed) | **This is the only mutation in the whole system that writes to `Kirim` from the DO flow** → triggers stock KELUAR, fulfillment, Dashboard, Invoice basis, Omset Pabrik basis, AuditLog (`do_ship`) |
| `draft`/`preprinted`/`ready` | `doDocCancel` | `cancelled` | Set status only | Blocked only if already `shipped` | None |
| Two docs, `PASTRY`+`MAIN`, same tanggal+canonicalStore, neither `shipped` | `doDocMerge` | Pastry→`cancelled`, Main updated (or newly created `"MRG-"+pastryId`) with merged `plannedItems` | Custom `withLock_` (touches 2 records, not `mutateVersioned_`) | `ALREADY_SHIPPED` if either side has shipped (checked both for an explicit `mainId` and for the auto-search path — a real bug in the auto-search was found and fixed this session) | Two audit entries (`do_merge` or `do_merge_blocked`) |

---

## 7. PO Semantics (PO Awal / PO Tambahan / PB)

Transcribed verbatim from the source comment block at `amorcakes-manufacturing-v5-slate(2).html` lines 3079–3172 (also independently confirmed by `tests/run-tests.js` Group A/B and `tests/uat-*` PO-revision scenarios, all passing — see §1.1):

- **First upload** for a given `tanggal+factory` → becomes **PO Awal**. Stored as `poAwal` per SKU (and per store-breakdown line).
- **Every subsequent upload** for the same `tanggal+factory`:
  - The **existing `poAwal` is frozen** — never overwritten by a later file, even if the new file's PO-Awal-looking column differs (a warning note is attached to the row, but the stored value wins).
  - The uploaded file's revision quantity becomes the new **`poRevisi`**, as a **full snapshot replacement**, not additive. Re-uploading the identical file never doubles the target; a corrected/lower revision is correctly reflected without manual reset.
  - Row identity for merging is `kode + normalized(produk name)` if a code is present, else `normalized(produk name)` alone (`poIdentitasBaris`) — chosen because real PO files have been observed to reuse one `kode` for two different products (`tests/uat-sku-identity.js` reproduces this exact real-world case).
  - Products/stores present in the old data but **absent** from the new file are preserved as-is (not deleted).
  - Products/stores newly appearing in the revision file are added as-is (nothing to "lock" for them).
- **`PB`** (a third numeric column present on every PO row) is **always excluded** from the production target. `hitungTarget(row)` is either `poAwal + poRevisi` (default `targetMode`) or `poRevisi>0 ? poRevisi : poAwal` (`targetMode:"revisiTimpa"`, an alternate global setting) — PB never enters either formula, anywhere in the codebase.
- **Target stays cumulative, never split**: this audit's own earlier work this session (the PO Awal/PO Tambahan *filter* on the Produksi page) is presentation-only by explicit design and by verified test — selecting "PO Tambahan" hides rows/divisions with `poRevisi<=0` but the underlying `target`/`sisa`/`aktual` used for actual production submission is untouched. There is exactly one cumulative production job per `tanggal+divisi+SKU`, never two.
- Upload itself (`poUpload`/`hapusPO`) is on the **versioned** sync path (`kirimSheetsVersioned`, `expectedVersion` checked), unlike most other transactional modules.

---

## 8. Stock Flow

**Formula, confirmed directly from `stokGudang(produk)` (frontend lines 5323–5334):**

```
stokGudang(produk) =
    Σ over D.ceklis[key].rows, ONLY where key ends in "|Finishgood & Packing" or "|Finishgood & Packing (Cibadak)"
      AND that Ceklis record is submitted:      (aktual − reject)              // MASUK
  − Σ over ALL D.kirim rows for this produk:     qty                            // KELUAR
  + Σ over ALL D.stokAdj rows for this produk:   qty  (signed: + in, − out)     // ADJ
```

| Source Event | Stock Effect | Record Created | Notes |
|---|---|---|---|
| Raw production (Ceklis, non-FG divisi) submitted | **None** | `Ceklis` row (submitted) | Only a *raw production* number; does not touch stock until FG-verified |
| Finishgood & Packing checklist submitted (`divisiVerifikasi` divisi) | **+ (aktual − reject)** | `Ceklis` row (submitted, FG divisi) | This is the sole MASUK path |
| FG Packing (`fgPacking`) — per-store allocation | **None directly** | `FGPacking` row | Allocates already-counted stock to a planned destination; does not itself change `stokGudang()` |
| FG Ready ("Siap Diambil Delivery") | **None** | `FGReady` row + `CeklisMeta.Status→verified_fg` | Marks readiness for dispatch; no stock change |
| DO Draft created | **None** | `DODoc` row (`draft`) | Explicit design goal: planning must be possible even at zero stock |
| DO Preprint (early print) | **None** | `DODoc.Status→preprinted` | "print ≠ shipped" |
| DO marked Ready (FG matched) | **None** | `DODoc.Status→ready`, `AvailableItemsJSON` stored | Availability recorded, not yet consumed |
| DO Shipped (`doDocShip`) / manual Kirim (`kSimpan`) | **− actualShipQty / − qty** | `Kirim` row(s) | The **only** two writers of `Kirim`; this is the sole KELUAR path |
| Retur (store return) | **None** | `Retur` row | Explicit design: "does not automatically add back to gudang stock; if it really re-enters the warehouse, record it via a StokAdj 'masuk'" |
| Reject, `resolusi:"potong"` | **None** | `Reject` row | Invoice-only effect |
| Reject, `resolusi:"ganti"` | **− qty** (indirectly) | `Reject` row **+ a `StokAdj` "rusak" row** | The stock reduction is StokAdj's doing, triggered by Reject's logic — Reject itself is not a term in the formula |
| Jual Konsumen (POS sale) | **None** | `Jual` row | Store-level reconciliation only, never touches warehouse stock |
| Mutasi (inter-store transfer) | **None** | `Mutasi` row (+ 2 Invoice adjustments) | Goods already left the warehouse via the original Kirim; Mutasi only reallocates the paperwork between two stores |
| Pesanan marked `selesai` | **− qty** (indirectly) | `Pesanan.status→selesai` **+ a `StokAdj` "koreksi" row per item** | Applies regardless of `sumber` (`produksi` or `stok`); reverted (StokAdj rows removed) if status moves away from `selesai` or the order is deleted |
| Manual Stock Adjustment | **± qty (as entered)** | `StokAdj` row | `masuk`→+abs, `waste`/`rusak`→−abs, `opname`/`koreksi`→raw signed value |

**Double-count risk assessment for MySQL migration**: the current design is **not** double-count-prone as long as the "only two writers of Kirim" invariant is preserved (§6.2 confirms `doDocShip` writes Kirim atomically with its own status transition, and idempotency via requestId prevents a retried ship from writing twice — verified in this session's DO-BE08 test and the pre-existing DO05 test, both passing). The one thing to explicitly re-verify once ported to MySQL transactions: `handleDoDocShip_`'s "append to Kirim + flip DO status" must remain a single atomic DB transaction (a straightforward `BEGIN…COMMIT` in MySQL, trivially stronger than the current Sheets-based approach of doing both writes inside one `LockService` critical section).

---

## 9. Financial Flow

- **Default rate**: `DEFAULT_FACTORY_RATE_PCT = 50` — applied to every new DO/Invoice/Retur/Reject/Pesanan/Mutasi-derived document. This superseded an older Ownership=55%/Franchise=60% scheme; `tokoTipe` (ownership/franchise) is now **reporting metadata only**, confirmed not to feed into any pricing function.
- **Override**: `OVERRIDE_FACTORY_RATE_PCT = 60`, applied **only** manually per open invoice via `kInvTerapkanOverride`, requires a typed reason, applies to all rows of that invoice only, and is **not** persisted as a new default for future documents.
- **Rate metadata is frozen per invoice/item at creation time** (`{ratePct, rateSource, overrideReason}`), so a later change to the global default never silently reprices historical invoices — this is a real, deliberate, already-tested design (`tests/uat.js` explicitly asserts invoice data preservation across unrelated operations).
- **`hargaPabrik(harga100, toko, ratePctOverride)`** = `round(harga100 × pct/100)`, `pct` = override or default. The `toko` parameter is vestigial (kept for old call-site compatibility, unused in the calculation).
- **`hargaDasar(harga100)`** = `round(harga100 × D.settings.pctDasar/100)` — a **separate** setting (currently numerically identical to the default, 50) used **only** for the Omset Pabrik accounting metric, editable independently of document pricing.
- **DO has no pricing** — confirmed: `DODoc`'s planned/available/actual item JSON carries only `{kode, produk, qty}` fields, never price. Pricing only exists at the Invoice layer.
- **Omset Pabrik** (`omsetKirimRow`) = `qty × hargaDasar(harga)`, computed **from `D.kirim`**, always at full shipped qty regardless of any later invoice qty adjustment (a damage found at handover is booked as a Reject/loss, not a revenue reduction) — this is explicitly, deliberately, and consistently the basis across Dashboard, Laporan Omset, and Rekap (the latter two reuse the exact same formula functions rather than re-implementing them, by explicit code comment).
- **Omset 100%** = `qty × harga100` (pre-discount consumer price) — a comparison baseline, not a revenue figure.
- **Retur** — never reduces Omset or the invoice/tagihan ("beban toko"), confirmed; contributes qty only to reconciliation views. A dead `omsetReturRow` function is retained in source purely as a comment/reference of a rule that used to exist (flagged in §14).
- **Reject**: always counted as `rugiReject` (factory loss, at `hargaPabrik` basis) regardless of `resolusi`; **additionally** reduces Omset/Omset100 **only** when `resolusi==="potong"` (to keep the percentage basis consistent with how it was added) — `resolusi==="ganti"` does not touch Omset (goods replaced free; the stock effect is captured separately via StokAdj, §8).
- **HPP/Margin**: `hpp = qty × mpGet(produk).hpp`; `margin = omset − hpp`. Computed independently at multiple call sites (Dashboard's `dashArusBarangData`, Laporan Omset's `omsetKirimDetailRow`, Rekap's summary) — all ultimately reading the same `D.masterProduk[...].hpp` source field, so the numbers agree, but the aggregation code itself is duplicated 2–3× (flagged in §13/§14 as a moderate duplication risk, not a correctness risk).
- **Invoice creation has two independent triggers today — a genuine business-rule reconciliation item for the new backend, not something to silently pick one side of**:
  1. **Shipment-gated** (`kBukaInvoice`): strictly requires existing `D.kirim` rows for the batch; this is the "normal"/documented rule ("Invoice hanya berdasarkan barang yang benar-benar shipped").
  2. **Order-gated** (`psSyncInvoice`, called synchronously from `psSimpan`): fires the moment a Pesanan (custom order) is saved, **before** any shipment/fulfillment confirmation, using the order's chosen `pctOmset` rather than the default rate.
- **Piutang (receivables)**: `sisa = max(0, total − Σ pembayaran)`; status `lunas`/`sebagian`/`belum`; `telat` = not lunas AND `umur(hari) > TEMPO_HARI(14)`. Overpayment is allowed and only warned about, never blocked.
- **Document numbering is not persisted** — both `doNomor` (DO number) and `invoiceNomor` (Invoice number) are **recomputed at render time** by counting/sorting the current in-memory `D.kirim`/`D.invoice` arrays. This is a genuine numbering-stability and concurrency risk (two clients saving near-simultaneously can compute the same "next" number) that MySQL must fix with a real server-side sequence (§15, §19). The DO-lifecycle flow's `doc.noSJ`, assigned once at `doDocSave` time, is a partial, newer exception to this pattern and a better model to generalize from.

---

## 10. Identity Strategy

**Products**: identity is a **normalized name string** (`normNama` = uppercase+trim+collapse-whitespace), resolved lazily via `mpResolveKey`/`mpGet` at *read* time. There is **no numeric product ID anywhere in the persisted data**, frontend or backend. `skuId(kode,produk)` = `kode + "\x1f" + normalizedName` is used as an internal composite key specifically because a bare `kode` has been proven (by real historical PO files, reproduced in `tests/uat-sku-identity.js`) to collide across two different products.

**Stores**: identity has **two parallel layers**. The legacy layer (`D.tokoAlias` + `cocokToko`) resolves an external raw name to an existing entry in the flat `D.stores` array. The newer layer (`D.masterToko`, keyed by `storeId`, holding `canonicalName`+`aliases[]`+`channel`) exists specifically because the *same physical bakery* can appear under different raw names from different factories (the canonical worked example throughout the source: Karangtengah's PO says `"CKLE"`, Cibadak's says `"BAKERY CIKOLE"` — both are legitimately separate entries in `D.stores`, so alias-matching within that flat array alone cannot merge them). `canonicalStoreName(name)` is the primary resolver, and **deliberately never invents a canonical name for an unmapped store** — it falls back to the raw name, by explicit design ("JANGAN diam2 mengarang nama baru").

**Critical finding — no backend persistence for canonical store identity at all.** Every function that edits `D.masterToko` (`mtkTambahAlias`, `mtkHapusAlias`, `mtkToggleActive`, `mtkGabungKe`, and canonical-name rename) is local-only — none of them call `kirimSheets`. Only the `channel` field (ownership/franchise) syncs, via `tokoTipeUpsert`. This means the entire canonical-bakery-alias mapping exists **only in whichever browser's `localStorage` last edited it**; there is no server-side source of truth to migrate *from* for this entity — it must be freshly designed and (re-)populated for MySQL, most practically by replaying `canonicalStoreName`/the built-in audit tools (`storeUsageAudit`, `auditStoreAliases`) against every historical raw `toko` string once, offline, before cutover.

**SAFE identity today** (already collision-resistant, safe to promote to a real FK with minimal rework): `skuId` (kode+normalized-name) for products within Ceklis/FGPacking; `poIdentitasBaris` (kode+normalized-name) for PO row merging; `doDocItemKey_` (kode+normalized-name, added this session) for DO item matching.

**RISKY raw-string identity** (used as the actual stored join/lookup key, with only lazy, non-persistent, read-time canonicalization) — the definitive list, confirmed by both source inspection and the dedicated research pass:
- `D.kirim[].produk`/`.toko`, `D.retur[].produk`/`.toko`, `D.reject[].produk`/`.toko`, `D.jual[].produk`/`.toko`, `D.stokAdj[].produk` — raw strings, never rewritten.
- `D.po[tanggal][].produk`, `.stores[].toko` — raw strings, post-resolution but still just strings.
- `D.invoice[batch].toko` and `.items[].produk`/`.kode` — raw strings; `kode` here is free-text, not authoritative.
- `D.masterProduk` — keyed by the raw/normalized name **itself** (no numeric id).
- `D.tokoTipe` — keyed by raw toko name string; `tokoTipe()` falls back from canonical to raw key, meaning both spellings can independently exist for the same physical store.
- `D.masterToko[storeId]` — `storeId` itself is usually just the raw name (except the one hardcoded bootstrap pair).
- Backend Sheets mirror this exactly — `Kirim`/`Retur`/`Jual`/`Reject`/`Pesanan` all store Toko/Produk as plain text columns.

**Migration implication**: introducing `product_id`/`store_id` surrogate keys (recommended, §15) requires an explicit backfill pass over **every historical row** using `mpResolveKey`/`canonicalStoreName`-equivalent logic, and an explicit, human-reviewed resolution of every case the existing audit tools (`storeUsageAudit`) already flag as `unmapped`/`conflictingAlias`/`suggestions` (fuzzy near-matches). These are pre-existing data-quality gaps, not something this migration can silently paper over with a `NOT NULL` foreign key.

---

## 11. Concurrency & Idempotency Matrix

| Mechanism | Backend implementation | Scope |
|---|---|---|
| `ScriptLock` (`withLock_`) | `LockService.getScriptLock().tryLock(10000ms)` | **Global** — every mutation across the entire app serializes through ONE lock, not per-record/per-sheet. Explicit, documented tradeoff for simplicity/safety over throughput. |
| `expectedVersion` / `RecordVersion` | `getVersion_`/`setVersion_`, full-sheet scan+rewrite per check/bump | Per `(recordType, recordKey)` — **only for the 8 versioned record types** (see below) |
| `requestId` / idempotency | `checkIdempotent_` (CacheService 6h TTL, falls back to permanent `RequestLog` sheet), `recordIdempotent_` | **Universal** — every handler that takes a `requestId` gets replay protection, versioned or not |
| `AuditLog` | `appendAudit_`, wrapped in its own try/catch so an audit-write failure never fails the main mutation | Called from nearly every handler; failures are silently swallowed (a risk — audit gaps are possible and currently invisible) |
| `VERSION_CONFLICT` | Returned by `mutateVersioned_` when `expectedVersion` is provided and doesn't match current | Only for versioned types; **if a client omits `expectedVersion` entirely, the check is skipped outright** — an explicitly self-documented, intentional-but-unsafe backward-compat allowance ("TIDAK aman... transisi sementara, bukan target akhir") |

**Per-endpoint matrix** (does it use lock? requestId? expectedVersion? audit? atomic?):

| `jenis` (endpoint) | Handler | Lock | requestId | expectedVersion | Audit | Atomic |
|---|---|---|---|---|---|---|
| `poUpload`, `hapusPO` | `handlePoUpload_`, `handleHapusPO_` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `ceklisProduksi` (legacy replace) | `handleCeklisProduksiReplace_` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `productionProgress` | `handleProductionProgressDelta_` | ✅ | ✅ | ✅ (strict — `requireStrictVersioning_` rejects a missing version) | ✅ | ✅ |
| `ceklisSubmit`/`ceklisTutup`, `ceklisReopen` | `handleCeklisSubmit_`, `handleCeklisReopen_` | ✅ | ✅ | ✅ (strict) | ✅ | ✅ |
| `fgPacking`, `fgReady` | `handleFgPacking_`, `handleFgReady_` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `doDocSave`/`Preprint`/`Ready`/`Ship`/`Cancel` | 5× `handleDoDoc*_` | ✅ | ✅ (`Ship` explicitly requires it, refuses without) | ✅ | ✅ | ✅ (`Ship` additionally writes `Kirim` inside the same critical section) |
| `doDocMerge` | `handleDoDocMerge_` | ✅ (custom `withLock_`, not `mutateVersioned_` — touches 2 records) | ✅ (required) | N/A (bumps both records' versions manually via `setVersion_`) | ✅ | ✅ |
| `kirim` (manual DO) | `handleAppendTransaksi_` | ✅ | ✅ | ❌ (append-only) | ✅ | ✅ (single append) |
| `hapusKirim`, `hapusRetur`, `hapusJual`, `hapusPembayaran`, `hapusStokAdj`, `hapusPesanan` | `handleHapusById_` | ✅ | ✅ | ❌ | ✅ | ✅ |
| `retur`, `jual`, `pembayaran` | `handleAppendTransaksi_` | ✅ | ✅ | ❌ | ✅ | ✅ |
| `reject`, `hapusReject` | `handleReject_`, `handleHapusByBatch_` | ✅ | ✅ | ❌ | ✅ | ✅ |
| `mutasi` | `handleMutasi_` | ✅ | ✅ | ❌ | ✅ | ✅ (no delete endpoint exists at all) |
| `stokAdj` | `handleStokAdj_` | ✅ | ✅ | ❌ | ✅ | ✅ |
| `pesanan`, `pesananStatus`, `hapusPesanan` | `handlePesanan_`, `handlePesananStatus_`, `handleHapusById_` | ✅ | ✅ | ❌ | ✅ | ✅ |
| `divisiUpsert`, `produkUpsert`, `tokoUpsert` | `handleMasterUpsert_` | ✅ | ✅ | ❌ (append-if-missing; **no delete handler exists**) | ✅ | ✅ |
| `masterProdukUpsert` | `handleMasterProdukUpsert_` | ✅ | ✅ | ✅ | ✅ | ✅ (**delete is frontend-local-only — no `jenis` exists for it at all**) |
| `tokoTipeUpsert` | `handleTokoTipeUpsert_` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `settingsUpsert` | `handleSettingsUpsert_` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `invoice` | `handleInvoiceUpsert_` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `trialBatchDelete` | `handleTrialBatchDelete_` → `cascadeDeleteTrialBatch_` | ✅ | ✅ (required) | N/A | ✅ | ✅ (one lock section covers the whole 14-sheet cascade) |
| `trialResetAll` | `handleTrialResetAll_` → `resetAllTrialData_` | ✅ | ✅ (required) | N/A | ✅ | ✅ |
| `reset` (legacy remote wipe) | — | N/A | N/A | N/A | ✅ (logs the rejection) | N/A — **permanently disabled**, always returns `RESET_DISABLED` |

**Translation notes for MySQL**: `withLock_`'s single global script lock → MySQL row-level locking / `SELECT ... FOR UPDATE` on the specific record, or an application-level transaction — strictly finer-grained and higher-throughput than today, as long as the per-record version-check + write remains inside one DB transaction. `expectedVersion` → a `version INT` column checked in the `WHERE` clause of the `UPDATE` (`UPDATE ... SET version=version+1 WHERE id=? AND version=?`, 0 rows affected = conflict), exactly mirroring current semantics. `requestId` idempotency → `UNIQUE(request_id)` on an `idempotency_log` table, insert-first-then-process or a stored, replayable response, mirroring `RequestLog`'s two-tier (cache+permanent) design, with a Redis/APCu layer as the optional fast path replacing `CacheService`.

---

## 12. Backend Endpoint (Handler) Matrix

All 39 distinct `payload.jenis` values, one row each (concurrency columns duplicated from §11 for a single reference table; reads/writes/side-effects added):

| `jenis` | Frontend caller(s) | Backend function | Reads | Writes | Side effects | Versioned? | Idempotent? | Audited? | Risk |
|---|---|---|---|---|---|---|---|---|---|
| `poUpload` | `poSimpanPreview` | `handlePoUpload_` | `PO` (existing rows for key) | `PO` (full replace for key) | Feeds all downstream targets | ✅ | ✅ | ✅ | Low |
| `hapusPO` | `poHapusFactory` | `handleHapusPO_` | — | `PO` (delete for key) | None else (Kirim/Invoice already created against old PO are untouched) | ✅ | ✅ | ✅ | Low |
| `ceklisProduksi` | (legacy client compat) | `handleCeklisProduksiReplace_` | — | `Ceklis` (full replace), `CeklisMeta` (→draft) | None | ✅ | ✅ | ✅ | Low |
| `productionProgress` | `pdSimpan` (delta) | `handleProductionProgressDelta_` | `Ceklis` (existing rows) | `Ceklis` (merged), `CeklisMeta` (→draft) | None | ✅ strict | ✅ | ✅ | Low |
| `ceklisTutup`/`ceklisSubmit` | `pdTutup` | `handleCeklisSubmit_` | `Ceklis`/`CeklisMeta` | `CeklisMeta` (→submitted/closed) + optional final rows | Unlocks FG verification path | ✅ strict | ✅ | ✅ | Low |
| `ceklisReopen` | `pdBukaKembali` | `handleCeklisReopen_` | `CeklisMeta` | `CeklisMeta` (→reopened) | Requires reason | ✅ strict | ✅ | ✅ | Low |
| `fgPacking` | `fgMaterializeAll`/packing form | `handleFgPacking_` | — | `FGPacking` (full replace for key) | Not round-tripped by doGet (§4) | ✅ | ✅ | ✅ | Medium (local-only round-trip gap) |
| `fgReady` | `fgTandaiSiap` | `handleFgReady_` | — | `FGReady` (replace), `CeklisMeta` (→verified_fg for each source divisi) | Unlocks DO-ready matching | ✅ | ✅ | ✅ | Low |
| `doDocSave` | `doDraftBuat` | `handleDoDocSave_` | `DODoc` (existing) | `DODoc` (upsert) | None | ✅ | ✅ | ✅ | Low |
| `doDocPreprint` | `doDocCetak` | `handleDoDocPreprint_` | `DODoc` | `DODoc` (status) | None | ✅ | ✅ | ✅ | Low |
| `doDocReady` | `doDocMatchFgReady_` | `handleDoDocReady_` | `DODoc` | `DODoc` (status + AvailableItemsJSON) | None yet | ✅ | ✅ | ✅ | Medium (availability is client-computed, §12.1) |
| `doDocShip` | `doDocKonfirmasiKirimSimpan` | `handleDoDocShip_` | `DODoc` | `DODoc` (status), **`Kirim` (append)** | Stock KELUAR, fulfillment, Omset, Invoice basis | ✅ | ✅ (required) | ✅ | Was High, **hardened this session** to Low/Medium (see §6.2) |
| `doDocCancel` | `doDocBatalkan` | `handleDoDocCancel_` | `DODoc` | `DODoc` (status) | None | ✅ | ✅ | ✅ | Low |
| `doDocMerge` | `doDocGabungKeMain` | `handleDoDocMerge_` | `DODoc` (both) | `DODoc` (both) | Blocks if either shipped | Custom lock | ✅ (required) | ✅ | Low (bug found+fixed this session) |
| `kirim` | `kSimpan` | `handleAppendTransaksi_` | — | `Kirim` (append) | Stock KELUAR, fulfillment, Omset, Invoice basis | ❌ | ✅ | ✅ | Medium (no version conflict — see §17 recommendation) |
| `hapusKirim` | (delete UI) | `handleHapusById_` | — | `Kirim` (delete by id) | Reverses the above | ❌ | ✅ | ✅ | Medium |
| `retur` | *(handler exists, no live frontend caller — see §3, §14)* | `handleAppendTransaksi_` | — | `Retur` (append) | None | ❌ | ✅ | ✅ | Low (effectively dead code today) |
| `hapusRetur` | `rHapusBatch` | `handleHapusById_` | — | `Retur` (delete) | — | ❌ | ✅ | ✅ | Low |
| `jual` | *(handler exists, no live frontend caller today — §3, §14)* | `handleAppendTransaksi_` | — | `Jual` (append) | None | ❌ | ✅ | ✅ | Low (effectively dead code today) |
| `hapusJual` | `jHapusBatch` | `handleHapusById_` | — | `Jual` (delete) | — | ❌ | ✅ | ✅ | Low |
| `reject` | `rjSimpan` | `handleReject_` | — | `Reject` (append) | May also fire `invoice`/`stokAdj` calls from the frontend in the same user action | ❌ | ✅ | ✅ | Medium |
| `hapusReject` | `rjHapusBatch` | `handleHapusByBatch_` | — | `Reject` (delete by batch) | — | ❌ | ✅ | ✅ | Low |
| `pembayaran` | `bayarSimpan` | `handleAppendTransaksi_(...,true)` | — | `Pembayaran` (append, single-object mode) | Affects Piutang status | ❌ | ✅ | ✅ | Medium (never round-tripped, §4) |
| `hapusPembayaran` | `bayarHapus` | `handleHapusById_` | — | `Pembayaran` (delete) | — | ❌ | ✅ | ✅ | Medium |
| `mutasi` | `mutSimpan` | `handleMutasi_` | — | `Mutasi` (append) | Frontend also separately calls `invoice` twice (reduce source, create destination) | ❌ | ✅ | ✅ | Medium (3-call sequence isn't atomic across all 3 as a business transaction) |
| `stokAdj` | `adjSimpan` + auto-create sites | `handleStokAdj_` | — | `StokAdj` (append) | Direct stock effect | ❌ | ✅ | ✅ | Medium (never round-tripped, never cascade-deleted) |
| `hapusStokAdj` | `adjHapus` | `handleHapusById_` | — | `StokAdj` (delete) | — | ❌ | ✅ | ✅ | Medium |
| `pesanan` | `psSimpan` | `handlePesanan_` | — | `Pesanan` (append, opaque JSON) | Frontend separately creates an Invoice client-side | ❌ | ✅ | ✅ | Medium (order-gated invoice trigger, §9) |
| `pesananStatus` | `psSetStatus` | `handlePesananStatus_` | `Pesanan` (find by id) | `Pesanan` (rewrite) | May create/remove StokAdj rows client-side | ❌ | ✅ | ✅ | Medium |
| `hapusPesanan` | `psHapus` | `handleHapusById_` | — | `Pesanan` (delete) | — | ❌ | ✅ | ✅ | Low |
| `divisiUpsert`/`produkUpsert`/`tokoUpsert` | `mTambah` | `handleMasterUpsert_` | `Master` | `Master` (append if missing) | — | ❌ | ✅ | ✅ | Low (**no delete endpoint** — `mHapus` is local-only) |
| `masterProdukUpsert` | `mpSetField`/`mpSimpanManual`/`mpUploadFile` | `handleMasterProdukUpsert_` | — | `MasterProduk` (full replace by key) | — | ✅ | ✅ | ✅ | Medium (**delete, `mpHapus`, has no endpoint at all — local-only, real bug**) |
| `tokoTipeUpsert` | `mtkSetChannel` | `handleTokoTipeUpsert_` | — | `TokoTipe` (replace) | — | ✅ | ✅ | ✅ | Low |
| `settingsUpsert` | `setPctHarga` | `handleSettingsUpsert_` | — | `Settings` (per-key replace) | — | ✅ | ✅ | ✅ | Low |
| `invoice` | `kInvoiceSimpan`, `buatInvoiceMutasi`, `psSyncInvoice` (indirectly via `pesanan`, see above), Mutasi flow | `handleInvoiceUpsert_` | — | `Invoice` (full replace by batch) | — | ✅ | ✅ | ✅ | Medium (multiple call sites, non-persisted numbering, §9) |
| `trialBatchDelete` | `trialHapusBatch` (feature-flagged) | `handleTrialBatchDelete_` | 14 sheets | 14 sheets (cascade delete) | Cross-device tombstone via AuditLog | Custom lock | ✅ (required) | ✅ | Low (trial-only, flag-gated) |
| `trialResetAll` | `trialResetAllData` (feature-flagged) | `handleTrialResetAll_` | 12 sheets (counts) | 12 sheets (wipe) | Epoch propagation via `doGet` | Custom lock (`trialReset` global counter) | ✅ (required) | ✅ | Low (trial-only, flag-gated) |
| `reset` | *(legacy — permanently disabled)* | *(none — rejected before any sheet access)* | — | — | Logs the rejection only | N/A | N/A | ✅ (rejection logged) | None (dead-safe) |

### 12.1 A note carried over from this session's own hardening work (§6.2)

`doDocReady`'s `AvailableItemsJSON` is still computed client-side (`doDocMatchFgReady_`/`doDocAvailableItemsFor_` in the HTML) and merely stored+versioned server-side — it is authoritative in the sense that `doDocShip` validates against the *stored* value rather than trusting a fresh value resent by the client, but the backend does not itself recompute FG availability from source. This is flagged in the current source's own comments as a candidate for further hardening (moving FG-availability computation fully server-side) and is repeated here as an explicit open item for the MySQL-era backend design (§16, §19).

---

## 13. Derived Report Matrix

| Report/KPI | Source tables | Formula/logic | Filters | Date semantics | Store semantics | Shipment-group semantics |
|---|---|---|---|---|---|---|
| Dashboard — Status Submit | Ceklis, PO, FGPacking | `dashData(tgl)`, one row per divisi | Single date | Single date, not ranged | Raw factory grouping (karangtengah/cibadak), not canonical bakery | N/A |
| Dashboard — Pipeline | PO, Ceklis, FGPacking, Kirim, Pesanan | `dashPipelineData(dari,sampai)` — Pesanan Outlet → Sedang Diproduksi → Selesai Produksi/FG → Siap Kirim → Sudah Terkirim, split Outlet vs Non-Outlet | Date range | Ranged, inclusive string-date compare | Not store-grouped (system-wide totals) | N/A |
| Dashboard — Arus Barang & Omset | PO, Kirim, Retur, Reject, MasterProduk | `dashArusBarangData` — byBakery/byKategori/byChannel; Retur qty-only, Reject reduces omset only if `potong` | Date range + tab (bakery/kategori/toko/channel) | Ranged | Canonical bakery (via `canonicalStoreName`) for the Bakery tab | N/A |
| Dashboard — PO per Bakery | PO, Ceklis, Kirim | `dashByFactoryData` — Pesanan vs Produksi vs Terkirim per factory | Date range | Ranged | Per-factory (karangtengah/cibadak), not canonical bakery | N/A |
| Laporan Omset | Kirim, Reject, MasterProduk, MasterToko | `omsetPerBakeryData`/`omsetTrenHarian`/`omsetSkuDetailData` — **deliberately reuses** `omsetKirimRow`/`hargaDasar` from Dashboard so the number never diverges | Date range + bakery/channel/factory filter | Ranged (trend chart always widens to ≥7 days) | Canonical bakery, channel (ownership/franchise) | Not used in this report's grouping (exists in the underlying Kirim rows but not surfaced here) |
| Kartu Stok | Ceklis (FG only), Kirim, StokAdj | `stokGudang`/`mutasiStok` — recomputed from full history every call | Product search + date range (display only, saldo always computed from full history) | Full-history running balance regardless of displayed window | N/A (warehouse-level, not per-store) | N/A |
| Rekap — main table | Ceklis (all divisi), Kirim, Retur, Jual | `rekapData` — per product: produksi/reject/kirim/retur/jual/sell-through/sisaGudang/sisaToko | Date range + search/active toggle | Ranged | Per-store `stokToko` sums | N/A |
| Rekap — Ringkasan (KPI panel) | *(reuses)* `dashArusBarangData`, `dashByFactoryData`, `omsetPerBakeryData`, `kPesananBelumTerpenuhiPerToko` | Explicit "synthesis of existing functions, not new business logic" | Date range | Ranged | Reuses whatever the underlying functions use | N/A |
| Rekap — Persentase (what-if) | *(reuses)* `dashArusBarangData.byChannel.omset100` | Shows Omset-100% at 100%/60%/50%/pctDasar simultaneously by channel | Date range | Ranged | Channel (ownership/franchise) | N/A |
| Rekap — Tren, Kategori, Toko | Kirim, Retur, Jual (independently aggregated, not reused) | Three separately-written reducers over overlapping rows | Date range | Ranged | Canonical bakery for the Toko tab | N/A |
| "Pesanan Toko belum terpenuhi" (fulfillment) | PO, Kirim | `kFulfillmentFlatData`/`kPesananBelumTerpenuhiPerToko` | None (search only) | **Lifetime cumulative — no date range at all**, unlike every other report above | Canonical bakery | **Explicitly does not split by shipmentGroup** — a partial shipment to either MAIN or PASTRY counts fully toward `terkirim` (this is a real, current gap: the spec's PASTRY-partial-fulfillment display requirement from an earlier session is a *UI-level* indicator layered on top, not a change to this underlying ratio) |

**Migration principle already satisfied by the current design and worth preserving**: reports should be computed from transactional tables at query time (or via a materialized/indexed view for performance), not stored as a second duplicate "truth." The current app already does this almost everywhere; the one place genuinely worth introducing a materialized structure in MySQL is the stock ledger (§8, §15), purely for query performance at scale — not because the current logic is wrong.

---

## 14. Legacy / Technical Debt

| Item | Description | Classification |
|---|---|---|
| **L1. `kTarikDariFGBakery` signature drift vs. older UAT scripts** | This session's shipmentGroup feature added a 4th parameter; `tests/uat-canonical-store.js` and `tests/uat-nav-compact-fixes.js` still call the 2/3-arg form and now fail 6+3 checks respectively (§1.1) | **NEEDS DECISION** — update those two UAT scripts to the current signature (straightforward) as a housekeeping task; explicitly NOT done in this audit per the "don't touch source" instruction |
| **L2. Dual-purpose legacy `Retur` sheet reader (`amorBacaRetur_`)** | Reads the same `"Retur"` sheet by flexible header-name lookup (tolerant of column order/naming), returning BOTH Retur and Reject rows distinguished by a `Jenis` column — separate from and inconsistent with the strict-positional `HEADERS[SHEET_RETUR]` writer used by `handleAppendTransaksi_` | **NEEDS DECISION** — likely reflects an external/manual import sheet structure that predates the in-app Reject module; must be reconciled into one clean source before a MySQL `returns`/`rejects` table split |
| **L3. `omsetReturRow` dead code** | Explicitly commented as unused, kept "for reference" — evidence Retur's Omset rule changed at some point in the past | **DROP AFTER MIGRATION** |
| **L4. `pctOwnership`/`pctFranchise` legacy settings** | Still initialized (55/60) and stored in `Settings`, explicitly documented as no longer used for rate computation, kept only to recognize historically-priced records during reconciliation | **KEEP FOR MIGRATION** (as historical-only reference data), **DROP** from any new-transaction pricing logic |
| **L5. Two invoice-creation triggers** | Shipment-gated (`kBukaInvoice`) vs. order-gated (`psSyncInvoice`, fires at order-save time) — see §9 | **NEEDS DECISION** — the new backend needs one reconciled invoice-creation workflow with an explicit `sumber` discriminator and matching preconditions per source |
| **L6. Non-persisted document numbering** | `doNomor`/`invoiceNomor` recompute their sequence from in-memory arrays at render time — no server-assigned sequence exists | **NEEDS DECISION**, high priority — replace with a real MySQL sequence/auto-increment before go-live (§19) |
| **L7. Inconsistent delete-sync coverage** | `mpHapus` (Master Produk delete), `mHapus` (divisi/produk/toko list delete), and all of `D.masterToko`'s alias/merge/active-toggle functions mutate local state only, with **no backend endpoint at all** for several of them | **NEEDS DECISION** — the new PHP API must decide, per entity, whether delete becomes a real endpoint (recommended) or stays admin/manual |
| **L8. `D.masterToko` (canonical store alias layer) has zero backend persistence** | See §10 — the single most consequential legacy gap for the migration; there is nothing to migrate *from* on the server side for this entity | **NEEDS DECISION**, highest priority — must be freshly designed, not "migrated" |
| **L9. Retur and Jual Konsumen have no manual create UI** | Both are exclusively populated by import pipelines (external Sheet pull for Retur, POS CSV for Jual), and **both import paths are themselves local-only** (never call `kirimSheets`) — meaning the backend's own `retur`/`jual` `jenis` handlers are effectively dead code, never invoked by the current frontend build | **NEEDS DECISION** — decide whether the new system re-adds manual entry, keeps import-only, and in either case makes creation actually reach the server |
| **L10. Mutasi has no delete/reversal path** | Neither frontend nor backend expose one | **NEEDS DECISION** |
| **L11. Bulk import fires one request per row** | `mpUploadFile` (Master Produk bulk upload) calls `kirimSheets` once per row rather than batching | **NEEDS DECISION** — a batch upsert endpoint is straightforward to add in the PHP API and recommended (§16) |
| **L12. `stokGudang`/`mutasiStok`/`rekapStokHarian` recompute over full history on every call** | No memoization or incremental ledger; fine at current volume, a real risk at MySQL-migrated scale if ported as literal full-table scans | **NEEDS DECISION** — recommend a materialized `stock_ledger` table or indexed running-balance view (§15) |
| **L13. Whole-sheet-rewrite pattern (`deleteRowsWhere_`)** | Every "delete some rows" operation actually reads the entire sheet and rewrites it in full — a Sheets-specific limitation | **DROP AFTER MIGRATION** — trivially replaced by `DELETE ... WHERE` in MySQL |
| **L14. `Log`, `RequestLog`, `AuditLog` unbounded growth** | None of these three sheets are ever pruned or archived | **NEEDS DECISION** — MySQL should add a retention/archival policy (partitioning by date is a natural fit), especially for `AuditLog`/`RequestLog` which must NOT lose data needed for the tombstone/idempotency mechanisms within their active window |
| **L15. `expectedVersion` omission bypasses version-conflict protection entirely** | Explicitly self-documented in `mutateVersioned_`'s own comment as an unsafe, "transitional" allowance for not-yet-upgraded clients | **DROP AFTER MIGRATION** — the new frontend/API pair should make `expectedVersion` (or its MySQL equivalent) mandatory, not optional, from day one |
| **L16. `KATALOG_BAWAAN`, 472-row built-in product catalog embedded in frontend JS** | Never synced to the backend; a fresh browser silently treats all 472 as "real" local master data until individually touched via the Master Produk UI | **KEEP FOR MIGRATION** as a seed/fixture dataset, but cross-reference against actual PO/Kirim usage first to separate "live" from "dormant reference-only" entries (§10) |
| **L17. Audit-write failures are silently swallowed** | `appendAudit_` wraps itself in try/catch specifically so a logging failure never blocks the main mutation — correct prioritization, but means audit gaps are currently invisible | **NEEDS DECISION** — MySQL migration should keep the "never block the main mutation" priority but consider surfacing audit-write failures to a monitoring/alerting channel instead of swallowing silently |

---

## 15. Proposed MySQL Schema — Conceptual Only

**No SQL is written here.** This is a table-level proposal for review; DDL comes only after this document is approved.

| Table | Purpose | PK | FK | Important columns | Unique constraint | Indexes | Lifecycle/status | Source legacy mapping |
|---|---|---|---|---|---|---|---|---|
| `factory` | Static 2-row lookup | `id` | — | `code` (karangtengah/cibadak), `name` | `code` | — | Static | Hardcoded string enum |
| `division` | Production divisions incl. FG pseudo-divisions | `id` | — | `name`, `is_verification` (replaces `divisiVerifikasi`) | `name` | — | Static-ish | `D.divisi`/`DEFAULT_DIVISI` |
| `product` | Master product catalog | `id` | — | `name` (canonical display), `kategori`, `division_id`, `hpp`, `harga`, `aktif` | none on `name` alone — see `product_alias` below | `name` (non-unique, indexed for lookup) | Master data | `MasterProduk` + `KATALOG_BAWAAN` seed |
| `product_alias` | Raw/typo/legacy spellings → `product_id` | `id` | `product_id` | `raw_name` (normalized on write) | `raw_name` | `raw_name` | Grows organically | `D.produkAlias` |
| `store` | Canonical bakery/store | `id` | — | `canonical_name`, `channel` (ownership/franchise), `active` | `canonical_name` | — | Master data | `D.masterToko` (**freshly built, no server source today** — see §10, §19) |
| `store_alias` | Raw factory-side spellings → `store_id` | `id` | `store_id` | `raw_name` (normalized on write) | `raw_name` | `raw_name` | Grows organically | `D.stores` + `D.tokoAlias` + `D.masterToko[].aliases` (reconciled) |
| `po_batch` | One row per `tanggal+factory` upload event | `id` | `factory_id` | `tanggal`, `uploaded_at`, `version` | `(tanggal, factory_id)` | `tanggal` | Transactional, historical | `PO` (grouped) |
| `po_item` | One row per SKU within a PO batch | `id` | `po_batch_id`, `product_id` | `kode` (free text, informational), `po_awal` (frozen), `po_revisi` (latest snapshot), `pb` (always ignored for target) | `(po_batch_id, product_id)` | `product_id` | Awal frozen at first insert; Revisi replaced wholesale on re-upload (application-layer rule, §7) | `PO` rows |
| `po_store_item` | Per-store breakdown of a PO item | `id` | `po_item_id`, `store_id` | `po_awal`, `po_revisi` | `(po_item_id, store_id)` | — | Same as parent | `stores[]` inside `PO.StoresJSON` |
| `production_run` | One per `tanggal+divisi` | `id` | `division_id` | `tanggal`, `status` (enum: not_started/draft/submitted/reopened/verified_fg), `submitted_at`, `closed_at`, `reopen_reason`, `version` | `(tanggal, division_id)` | `tanggal` | State machine per §6.1 | `Ceklis`+`CeklisMeta` |
| `production_item` | One per SKU within a run | `id` | `production_run_id`, `product_id` | `kode` (informational), `target`, `status` (sesuai/tidak_sesuai), `aktual`, `reject`, `keterangan` | `(production_run_id, product_id)` | `product_id` | Same as parent | `Ceklis` rows |
| `fg_batch` | One per `tanggal+factory` | `id` | `factory_id` | `ready_at`, `source_version_json` (or normalize into a child table), `version` | `(tanggal, factory_id)` | `tanggal` | draft → ready | `FGPacking`+`FGReady` header |
| `fg_item` | One per SKU per destination store | `id` | `fg_batch_id`, `product_id`, `store_id` | `qty`, `status`, `keterangan` | `(fg_batch_id, product_id, store_id)` | `product_id`, `store_id` | Same as parent | `FGPacking` rows |
| `delivery_order` | DO document (draft/preprint/ready/shipped/cancelled) | `id` (keep the human-readable string id, e.g. `DO-2026-09-20-TOKO1-MAIN`, as PK or as a unique business key alongside a surrogate bigint) | `store_id`, `factory_id` (nullable, derivable) | `tanggal`, `shipment_group` (MAIN/PASTRY/OTHER), `status`, `no_sj`, `batch`, `catatan`, `created_by`, `preprinted_at`, `ready_at`, `shipped_at`, `shipped_by`, `version` | `(tanggal, store_id, shipment_group)` **partial** unique (only enforce while status NOT IN (shipped,cancelled) — needs an application-level or filtered-index equivalent, see note below) | `tanggal`, `store_id`, `status` | Full state machine per §6.2 | `DODoc` |
| `delivery_order_item` | One per SKU within a DO | `id` | `delivery_order_id`, `product_id` | `kode` (informational), `planned_qty`, `available_qty`, `actual_ship_qty` | `(delivery_order_id, product_id)` | `product_id` | planned → (available) → actual | `PlannedItemsJSON`/`AvailableItemsJSON`/`ActualItemsJSON` |
| `shipment` (aka Kirim) | One row per SKU actually shipped | `id` | `store_id`, `product_id`, `delivery_order_id` (nullable — manual Kirim has none) | `tanggal`, `batch`, `qty`, `no_sj`, `pengemudi`, `kendaraan`, `shipment_group` | — | `(tanggal, store_id)`, `batch`, `product_id` | Append-only + soft/hard delete | `Kirim` |
| `invoice` | One per batch | `id` | `store_id` | `invoice_no` (server-sequenced, see §19), `batch`, `tanggal`, `no_sj`, `total`, `rate_pct`, `rate_source`, `override_reason`, `sumber` (`kirim`\|`pesanan`\|`mutasi`), `version` | `batch`, `invoice_no` | `store_id`, `tanggal` | Created, then editable (qty adjustments) | `Invoice` |
| `invoice_item` | Line items | `id` | `invoice_id`, `product_id` | `kode` (informational), `qty_do`, `qty_invoice`, `harga`, `subtotal`, `rate_pct`, `rate_source`, `override_reason` (frozen at creation) | `(invoice_id, product_id)` | `product_id` | Adjustable via retur/reject/mutasi flows | `Invoice.ItemsJSON` |
| `payment` | Pembayaran | `id` | `invoice_id` | `tanggal`, `jumlah`, `cara`, `keterangan` | — | `invoice_id`, `tanggal` | Append-only, individually deletable | `Pembayaran` |
| `return_note` (Retur) | Store-side return | `id` | `store_id`, `product_id` | `tanggal`, `batch`, `qty`, `alasan`, `sumber` | — | `(tanggal, store_id)` | Import-created today | `Retur` |
| `reject_note` (Reject) | Factory-side liability | `id` | `store_id`, `product_id`, `invoice_id` (nullable) | `tanggal`, `batch`, `qty`, `alasan`, `resolusi` (potong/ganti), `nilai` | — | `(tanggal, store_id)`, `invoice_id` | Manual or import-created | `Reject` |
| `retail_sale` (Jual) | POS sale | `id` | `store_id`, `product_id` | `tanggal`, `batch`, `qty`, `sumber` | — | `(tanggal, store_id)` | Import-created (POS CSV) | `Jual` |
| `stock_transfer` (Mutasi) | Inter-store transfer | `id` | `product_id`, `from_store_id`, `to_store_id`, `batch_asal_invoice_id`, `batch_tujuan_invoice_id` | `tanggal`, `qty`, `keterangan` | — | `tanggal` | Create-only (decide whether to finally add delete, §14 L10) | `Mutasi` |
| `stock_adjustment` | Manual/derived correction | `id` | `product_id` | `tanggal`, `tipe` (masuk/waste/rusak/opname/koreksi), `qty` (signed), `keterangan`, `sumber` | — | `(tanggal, product_id)` | Deletable individually; **never cascade-deleted** by any trial/batch-delete feature if ported (§14) | `StokAdj` |
| `stock_ledger` *(new, recommended — not a 1:1 legacy mapping)* | Materialized running balance per product | `id` | `product_id` | `tanggal`, `event_type` (masuk/keluar/adj), `qty_delta`, `running_balance`, `source_table`, `source_id` | — | `(product_id, tanggal)` | Append-only, rebuildable from source tables | Replaces the recompute-from-scratch pattern in `stokGudang`/`mutasiStok` (§8, §14 L12) |
| `customer_order` (Pesanan) | Non-outlet order | `id` | `store_id` (nullable — some orders may not map to a store) | `no`, `tgl_pesan`, `tgl_produksi`, `tgl_ambil`, `tipe`, `pemesan`, `kontak`, `alamat`, `pct_omset`, `sumber` (produksi/stok), `status`, `catatan` | `no` | `tgl_produksi` | Draft → ... → selesai | `Pesanan` (unpacked from `PayloadJSON`) |
| `customer_order_item` | Line items | `id` | `customer_order_id`, `product_id` | `qty`, `harga` | — | `product_id` | Same as parent | `Pesanan.items[]` |
| `master_setting` | Config key/value (targetMode, pctDasar, and — as historical-only — pctOwnership/pctFranchise) | `key` | — | `value` | `key` | — | Config | `Settings` |
| `audit_log` | Append-only mutation trail | `id` | (loose reference — `record_type`+`record_key`, not a hard FK, to survive the referenced row's own deletion) | `request_id`, `timestamp`, `user_id`, `action`, `record_type`, `record_key`, `previous_version`, `new_version`, `payload_summary`, `status` | — | `record_type,record_key`, `timestamp` | Append-only forever; consider partitioning by month | `AuditLog` |
| `idempotency_log` | Request replay protection | `request_id` | — | `record_type`, `record_key`, `status`, `response_json`, `created_at` | `request_id` | — | Append-only; consider a retention window (unlike AuditLog, this can likely be pruned safely after some months) | `RequestLog` |
| `user` / `role` *(new — does not exist today, see §6/§19)* | Real authentication/authorization | `id` | — | credentials, `role_id` | — | — | New | Currently only free-text `actor` metadata, not verified |

**Notes on constraints that need careful MySQL/MariaDB version-specific handling, not resolved here:**
- The "one open DO per (tanggal, store, shipment_group)" rule (§6.2, `doDocIdFor`'s anti-collision logic) is naturally a **partial unique index** (`WHERE status NOT IN ('shipped','cancelled')`) — supported in MariaDB 10.2+/MySQL 8.0.13+ via functional/filtered unique constraints, or otherwise enforced at the application layer inside the same transaction as the insert. This needs an explicit decision once the target MySQL/MariaDB version on the cPanel host is confirmed.
- `product`/`store` uniqueness is deliberately **not** placed on `name` directly, because the legacy data has proven near-duplicates (aliases, casing/spacing variants) that must be resolved via the alias tables and a human-reviewed backfill (§10, §19) before a hard unique constraint on canonical name is safe to add.

---

## 16. Proposed PHP API — Conceptual Only

**No PHP is written here.** Endpoint shapes only, for review.

| Method | Path | Request | Response | Transaction? | Role | Idempotency |
|---|---|---|---|---|---|---|
| POST | `/api/auth/login` | credentials | token/session | — | — | — |
| GET | `/api/products` | filters (aktif, divisi, search) | `product[]` | read-only | any | — |
| POST | `/api/products` | product fields | created `product` | single-row txn | admin | `Idempotency-Key` header (mirrors `requestId`) |
| PUT | `/api/products/{id}` | fields + `version` | updated `product` or `409 VERSION_CONFLICT` | single-row txn, optimistic version check | admin | required |
| DELETE | `/api/products/{id}` | `version` | `204` or `409` | single-row txn | admin | required — **closes legacy gap L7 (`mpHapus` currently unsynced)** |
| GET/POST/PUT | `/api/stores`, `/api/stores/{id}/aliases`, `/api/stores/{id}/merge` | — | canonical store CRUD + alias/merge tooling | txn | admin | required for writes — **closes legacy gap L8 (no backend persistence today)** |
| GET | `/api/po?tanggal=&factory=` | — | `po_batch` + nested `po_item[]`+`po_store_item[]` | read-only | any | — |
| POST | `/api/po/upload` | `{tanggal, factory, rows[]}` | merged result (awal/revisi/target per §7's exact semantics, preview-then-confirm as today) | single-batch txn, optimistic version on `po_batch` | admin/produksi | required |
| DELETE | `/api/po/{tanggal}/{factory}` | `version` | `204`/`409` | txn | admin | required |
| GET | `/api/production?tanggal=&divisi=` | — | `production_run` + items, computed `sisa` per §7 | read-only | any | — |
| POST | `/api/production/progress` | `{tanggal, divisi, rows[], expectedVersion}` | updated run or `409` | txn, strict version required (no omission allowed — closes L15) | produksi | required |
| POST | `/api/production/submit` | `{tanggal, divisi, expectedVersion}` | `submitted` | txn | produksi | required |
| POST | `/api/production/reopen` | `{tanggal, divisi, expectedVersion, reason}` | `reopened` | txn | admin | required |
| GET/POST | `/api/fg/packing`, `/api/fg/ready` | — | FG batch/items | txn | produksi | required for writes |
| GET | `/api/delivery-orders?tanggal=&store=&status=` | — | `delivery_order[]` + items | read-only | any | — |
| POST | `/api/delivery-orders` | `{tanggal, store, shipmentGroup, plannedItems[], ...}` | created/updated draft | txn, version-checked | admin/delivery | required |
| POST | `/api/delivery-orders/{id}/preprint` | `{expectedVersion}` | `preprinted` | txn | delivery | required |
| POST | `/api/delivery-orders/{id}/ready` | `{expectedVersion, availableItems[]}` | `ready` | txn | fg/delivery — **recommend eventually server-computing `availableItems` from `fg_item` directly, per §12.1's open item, rather than trusting the client value even at this step** | required |
| POST | `/api/delivery-orders/{id}/ship` | `{expectedVersion, actualItems[]}` | `shipped` + created `shipment[]` rows | **single DB transaction**: validate (status=ready, item identity, ≤available, ≤planned, actualItems non-empty — the exact 5 checks hardened this session) → insert `shipment` rows → update `delivery_order` status, all-or-nothing | delivery | required (mirrors the current hardened `handleDoDocShip_` 1:1) |
| POST | `/api/delivery-orders/{id}/cancel` | `{expectedVersion}` | `cancelled` | txn | admin/delivery | required |
| POST | `/api/delivery-orders/{pastryId}/merge` | `{mainId?, expectedVersion pair}` | merged `delivery_order` | txn spanning 2 rows | admin | required |
| GET/POST/DELETE | `/api/shipments` | — | manual Kirim CRUD | txn | delivery | required — **recommend adding `expectedVersion` here, currently append-only with no conflict detection (§12 risk note)** |
| GET/POST/PUT | `/api/invoices` | — | Invoice CRUD, rate override with required reason | txn, version-checked | admin | required |
| POST | `/api/invoices/{id}/payments` | `{jumlah, cara, keterangan}` | created `payment` | txn | admin | required |
| GET/POST/DELETE | `/api/returns`, `/api/rejects`, `/api/retail-sales`, `/api/stock-transfers`, `/api/stock-adjustments`, `/api/customer-orders` | — | CRUD per §15 tables | txn | role per entity | required — **all 7 of these gain real conflict detection they don't have today (§11)**, an explicit improvement, not a regression |
| GET | `/api/reports/dashboard`, `/api/reports/omset`, `/api/reports/kartu-stok`, `/api/reports/rekap` | date range + filters (per §13's exact semantics for each) | computed report payloads | read-only, ideally against `stock_ledger`/indexed views for performance | any | — |
| GET | `/api/audit-log`, `/api/sync/state` (a `doGet`-equivalent full/incremental snapshot pull if the new frontend still wants an offline-tolerant local cache) | — | — | read-only | admin (audit) / any (sync) | — |

**Cross-cutting requirements for every write endpoint**: (1) `Idempotency-Key` request header, checked server-side before any write, mirroring `requestId`; (2) a `version` field required (not optional) on every entity that already has version semantics today, plus newly added to the 7 currently-unversioned modules per the recommendation in §11/§19; (3) every write wrapped in a real DB transaction; (4) an `audit_log` insert on every successful or rejected mutation, in the same transaction where possible (an improvement over today's "audit write is best-effort and can silently fail," §14 L17) or immediately after with a monitored dead-letter path if not.

---

## 17. Migration Mapping

**Google Sheet → MySQL table**: see the "Source legacy mapping" column of §15's table — every one of the 24 sheets in §4 maps to at least one MySQL table; `PO`, `Ceklis`, `FGPacking`, `Invoice`, `DODoc` each split into a header+items pair; `RecordVersion`/`RequestLog`/`AuditLog` map close to 1:1 as infrastructure tables.

**LocalStorage → MySQL / cache / removed**: per §5's classification column —
- **A**-classified fields → become the MySQL tables of record (their local mirror in the new frontend becomes read-through cache at most, never authoritative).
- **B**-classified fields (`D.recordVersions`, trial-epoch bookkeeping) → **removed**; version numbers live as a column on the authoritative row itself, no separate mirror needed.
- **C**-classified fields (all the render-only caches) → **removed** as persisted state; may still exist as transient in-memory JS variables in the new frontend, never in `localStorage`.
- **D**-classified fields (`cfg-url` equivalent) → becomes API base URL config / auth token storage.
- **E**-classified fields (`D.tokoAlias` legacy layer, version-flag markers) → **not migrated**; superseded by the reconciled `store_alias` table (§15) and simply not needed post-cutover.

**Apps Script handler → PHP endpoint**: 1:1 per §12 → §16 (every `handle*_` function has a named counterpart proposed in §16's table); the biggest *behavioral* change (not just a rename) is giving the 7 currently-unversioned modules (§11) real optimistic-version columns and giving Master Produk/Store delete and Store-alias editing real endpoints for the first time (§14 L7, L8).

**Frontend function → new API endpoint** (representative examples, not exhaustive — the full mapping is implied by §3's "Key Frontend Functions" and §16's endpoint table read together):

| Frontend function | New API call |
|---|---|
| `poSimpanPreview` | `POST /api/po/upload` |
| `pdSimpan` | `POST /api/production/progress` |
| `fgTandaiSiap` | `POST /api/fg/ready` |
| `doDraftBuat` | `POST /api/delivery-orders` |
| `doDocKonfirmasiKirimSimpan` | `POST /api/delivery-orders/{id}/ship` |
| `kSimpan` | `POST /api/shipments` |
| `kBukaInvoice`/`kInvoiceSimpan` | `GET`/`PUT /api/invoices/{batch}` |
| `bayarSimpan` | `POST /api/invoices/{id}/payments` |
| `mpSimpanManual`/`mpHapus` | `PUT`/`DELETE /api/products/{id}` |
| `mtkGabungKe`/`mtkTambahAlias` | `POST /api/stores/{id}/merge` / `/api/stores/{id}/aliases` |
| `muatSemua` (full-state pull) | `GET /api/sync/state` (if kept) or a set of per-page `GET` calls (recommended default — see §18) |

---

## 18. Migration Strategy (Phased)

No permanent dual-write is recommended. If a temporary dual-write period is used at all, it should be as short as one cutover window (hours to a few days, not weeks), with MySQL as the write-of-record from day one of that window and Sheets receiving a best-effort mirrored copy purely as a rollback safety net — reconciled and then switched off, never left running indefinitely.

1. **Phase 0 — Data-quality pre-work (before any schema exists)**: run `storeUsageAudit`/`auditStoreAliases` (already built into the current frontend) against full production history; resolve every `unmapped`/`conflictingAlias` case with a human decision. Do the equivalent name-normalization pass for products. This directly de-risks §10's biggest finding and must happen before `store_id`/`product_id` foreign keys can be trusted.
2. **Phase 1 — Schema + one-time backfill**: stand up MySQL schema per §15 (or its reviewed revision), write a one-time ETL from every Sheet into the new tables using the resolved identity mapping from Phase 0. Freeze new writes to Sheets during the backfill window only.
3. **Phase 2 — PHP API, read-only first**: build and deploy the `/api/reports/*` and `GET` endpoints first, validate their numbers against the existing Dashboard/Omset/Rekap pages (which stay live, reading Sheets) side-by-side for at least one real business cycle (e.g., one full week of production+shipment+invoice activity) before trusting them.
4. **Phase 3 — PHP API, writes, module by module, lowest-risk first**: recommended order based on this audit's risk ratings — Master Produk/Master Toko CRUD (closes L7/L8) → PO upload → Production (Ceklis) → FG/Packing → DO lifecycle (§6.2, already hardened business rules to port faithfully) → manual Kirim → Invoice/Payment → Retur/Reject/Jual/Mutasi/StokAdj/Pesanan (the 7 currently-unversioned modules — add real version columns as part of this phase, not deferred). Each module's cutover point is: new frontend page talks to MySQL/PHP only; the old Sheets-backed page for that module is retired the same day.
5. **Phase 4 — Frontend cutover**: replace `kirimSheets`/`kirimSheetsVersioned`/`muatSemua` calls with the new API client, module by module, following the same order as Phase 3. `localStorage` usage is pared down to the §5 target invariant (UI prefs + disposable caches only) as each module cuts over.
6. **Phase 5 — Retire Apps Script**: once every module is confirmed cut over and a full audit-log/reconciliation pass shows no drift, the Apps Script Web App deployment is disabled. Google Sheets, if kept at all, becomes an **archive/optional reporting export only** — never a live write target again.

**Reconciliation approach if a dual-write window is used in Phase 3**: for the duration of that window only, treat MySQL as authoritative and Sheets as a shadow copy; after the window, run a diff (row counts + checksums per entity) between MySQL and the shadow Sheets copy, resolve any discrepancy in favor of MySQL (since it was the write-of-record), then stop writing to Sheets for that module.

---

## 19. Risks / Open Decisions

| # | Risk | Notes |
|---|---|---|
| R1 | **No canonical store identity persisted server-side today** (§10, L8) | Must be freshly built and back-filled with human review before store FKs are trustworthy; this is the single largest open item |
| R2 | **Product identity is name-string-based, no numeric ID, proven kode-collision history** (§10) | Backfill via `mpResolveKey`-equivalent logic; expect manual review of ambiguous cases |
| R3 | **Duplicate records from re-running the backfill** | The ETL (Phase 1) must be idempotent/re-runnable without creating duplicate rows on retry — design it with the same idempotency-key discipline as the live API |
| R4 | **Historical compatibility of old Kirim rows without `ShipmentGroup`** | Already solved in the current source (defaults to MAIN both sides) — carry the same default into the MySQL column (`NOT NULL DEFAULT 'MAIN'`) |
| R5 | **Stock opening balance** | The current formula (§8) has no explicit "opening balance" concept — it's a lifetime sum over all history. The MySQL migration must decide whether to (a) replay full history into the new `stock_ledger`, or (b) snapshot a computed opening balance at cutover date and start the ledger fresh from there. (a) is safer for auditability; (b) is cheaper. Needs a decision. |
| R6 | **Invoice numbering not persisted, recomputed at render time** (§9, L6) | Must become a real server-side sequence before go-live; decide the exact format to preserve continuity with historical numbers (`INV/KRM/{ddmmyy}/{seq:3}`) or accept a clean break at cutover — needs a decision |
| R7 | **DO numbering (`doNomor`) same issue** | Same recommendation as R6; the newer `doDocSave`-assigned `noSJ` is a better model already partially in place |
| R8 | **Concurrency**: current global-lock model vs. MySQL row-level | Strictly improvable, but the *exact* version-check semantics (including the "omitted expectedVersion skips the check" legacy allowance, L15) must be a deliberate choice in the new API, not silently inherited |
| R9 | **Idempotency key lifetime** | Current design: 6h fast cache + permanent sheet fallback. Decide the MySQL-era retention policy for `idempotency_log` (§15) — likely safe to prune after some months, unlike `audit_log` |
| R10 | **Timezone handling** | Current backend uses `Session.getScriptTimeZone()` (Apps Script project timezone) for all date formatting; the new PHP/MySQL stack must pick one explicit timezone (recommend storing all datetimes in UTC and formatting for display, or matching the Apps Script project's configured timezone exactly, to avoid off-by-one-day drift in date-keyed records like `tanggal|divisi`) |
| R11 | **Date conversion** — `tanggal` is stored/compared as a `yyyy-MM-dd` **string** throughout the current app (`normDate_`), not a native date type | MySQL should use a real `DATE` column, but every string-date comparison (`inRange`, key construction like `tanggal+"|"+divisi`) in the ported business logic must be re-verified against the new type — a subtle but real correctness risk during the port, not just a schema detail |
| R12 | **Numeric precision** | All monetary/qty values are plain JS numbers (float) today; MySQL should use `DECIMAL` for money (`harga`, `total`, `subtotal`, `nilai`) and `INT` for qty, with explicit rounding rules matching `hargaPabrik`'s `round()` behavior — needs verification that no existing historical value depends on float imprecision that a `DECIMAL` re-store would "correct" and thereby change a historical total |
| R13 | **Foreign key mapping for historical rows with ambiguous/unmapped store or product names** | Per R1/R2 — some historical rows may need a placeholder "unmapped" store/product record rather than being silently dropped or force-merged; decide the exact fallback bucket rule before the ETL runs |
| R14 | **Deleted trial data** | Trial Batch Delete / Trial Full Reset (§2, §14) are dev/UAT-only features; decide whether they're ported at all to the production PHP/MySQL system (recommend: NOT ported to production, or ported behind an even stricter environment guard than today's boolean flag) |
| R15 | **Canonical store aliases** | See R1 — restated because it specifically also affects the ETL's row-attribution logic (which historical Kirim/Invoice/Retur rows belong to which canonical store) |
| R16 | **Product aliases** | Same class of risk as R15, for `D.produkAlias` |
| R17 | **Existing historical documents (DO/Invoice) predate the current lifecycle/shipmentGroup features** | Already handled by explicit backward-compat rules in the current source (old DO-less Kirim rows are just plain shipments; ShipmentGroup defaults to MAIN) — the ETL must replicate these exact fallback rules, not invent new ones |
| R18 | **No real authentication/authorization exists today** (§6, "User/Role" entity) | Explicitly self-documented as an unsolved gap in the current Apps Script backend's own header comment ("Execute as: Me, Anyone" — anyone with the URL can call anything, including reset). The new PHP/MySQL system is the right place to finally add real auth — this is a **requirement**, not optional, before this system can be trusted with a public-facing `factory.amorgroup.id` deployment |
| R19 | **Pre-existing failing UAT checks** (§1.1, L1) | Not a migration risk per se, but must be resolved (update the two affected UAT scripts to the current 4-arg `kTarikDariFGBakery` signature) before those scripts can be trusted as a regression gate during the migration itself |
| R20 | **Two invoice-creation triggers must be reconciled** (§9, §14 L5) | A design decision, not just a technical one — needs sign-off from whoever owns the Pesanan/order-booking business process |
| R21 | **`stokAdj` never cascade-deleted** — is this a permanent business rule or a Sheets-era safety-valve that MySQL's better tooling makes unnecessary? | Needs a decision either way, since MySQL makes a real cascade-with-rollback trivial and safe, unlike the current one-way Sheets rewrite |
| R22 | **`Mutasi` has no delete/reversal path anywhere** | Decide whether to finally add one in the new system, or confirm this is intentional (append-only ledger of physical movements is a defensible design — but should be a decision, not an accident) |

---

## 20. Recommended Next Step

This audit is complete for review. Recommended next step, in order:

1. **Human review of this document** — in particular §10 (Identity Strategy), §15 (schema draft), §16 (API draft), and the R1/R2/R6/R7/R18/R20 decisions in §19, since these shape everything downstream and are the hardest to change once implementation starts.
2. Once reviewed and any open decisions in §19 are resolved (or explicitly deferred with a stated owner/date), proceed to **Phase 0** of §18 (data-quality pre-work: run the existing `storeUsageAudit`/product-name audit against real production history) — this can start immediately and independently of schema/API work, and materially de-risks everything after it.
3. Only after Phase 0 and schema/API sign-off should DDL (`database.sql`) or any PHP code be written — explicitly out of scope for this task.

**Status: MYSQL MIGRATION DESIGN READY FOR REVIEW.**
