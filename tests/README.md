# Regression tests — Amor Factory (PO Revision flow)

Executable regression tests for `amorcakes-manufacturing-v5-slate(2).html`.

These do **not** reimplement the app's logic. `harness.js` extracts the app's
own inline `<script>` verbatim from the HTML file and runs it under Node with
stubbed DOM / `localStorage` / `fetch` / `confirm` / `XLSX`, then exposes the
real top-level functions (`hitungTarget`, `poMergeDenganExisting`,
`pdSisaTarget`, `fgMaterializeAll`, `parsePOAuto`, ...) and state (`D`) so
`run-tests.js` can call them directly and assert on real behaviour.

## Run

```
node tests/run-tests.js
```

Exits non-zero if any check fails; prints a `Test | Expected | Actual | Result`
table plus a `REGRESSION RESULT: N/N PASS` summary.

## Coverage

- Group A — PO revision snapshot logic (`poMergeDenganExisting`): initial vs
  revision detection, PO Awal locking, PO Revisi snapshot (not additive), new
  products/stores appearing mid-revision, PO Awal edited in a later file
  (locked + warning), products omitted from a later upload (not deleted).
- Group B — Production vs revision target interaction (`pdSisaTarget`):
  cumulative aktual, partial production, multi-revision, revision-turun with
  overproduction warning.
- Group C — Product matching (`cocokProduk`, case/space normalization, fuzzy
  suggest, unmapped), unresolved-guard, and Finishgood/Packing materialization
  (`fgMaterializeAll`): existing packed quantities preserved when target rises,
  new toko/produk rows from a revision default to unpacked (not silently
  counted as done).
- Group D — Parser regression: Karangtengah and Cibadak/Bolu sample totals,
  factory auto-detection.
- Group E — Data safety: existing Delivery Order / Invoice untouched by a PO
  revision upload, ceklis fields (aktual/reject/keterangan/submittedAt)
  preserved, stock unaffected by a bare PO revision, `localStorage` roundtrip.

No test double is used for the functions under test themselves — only the
browser environment around them is stubbed.

## End-to-end UAT (`uat.js`)

A second, higher-fidelity suite that drives the full business flow — Upload PO
→ Produksi → Upload PO Revisi → Produksi Tambahan → Finishgood → Packing per
Toko → Stok → Delivery Order → Invoice — through the app's own UI-facing
functions (`poProses`, `poSimpanPreview`, `prodBuildChecklist`/`pdBaca`/
`pdSimpan`, `fgBuildPanel`/`fgMaterializeAll`/`fgTandaiSiap`, `kBuildGrid`/
`kBaca`/`kSimpan`, `kBukaInvoice`/`kInvoiceSimpan`, `dashData`/
`renderDashStatusSubmit`), using a **real DOM** (`jsdom`) and the **real
SheetJS library** (`xlsx` npm package) to build genuine in-memory `.xlsx`
files and feed them through `poProses()` exactly as a browser file-upload
would. Nothing here is a hand-rolled DOM stub — `innerHTML`, `querySelectorAll`,
`dataset`, `classList`, and `localStorage` are all real.

### Setup & run

```
cd tests
npm install   # installs jsdom + xlsx (test-only — the app itself stays dependency-free)
npm run uat   # or: node uat.js
```

`jsdom` and `xlsx` are **devDependencies of this test folder only**. The
shipped app (`amorcakes-manufacturing-v5-slate(2).html`) remains a
standalone, build-free HTML file — these packages are never loaded by it,
only used to execute its script under Node for testing.

### Coverage

80 checks across the full narrative (Trials A–U from the UAT spec): initial
PO, first production, PO revision upload (locked PO Awal, snapshot PO Revisi,
target 9.591 = 9.533 + 58 matching the pre-verified sample), idempotent
reupload, additional production, Finishgood verification (separate from the
Packing-per-toko panel — see note below), the dashboard double-count check,
Packing-per-toko (existing packed preserved / new revision rows default to
unpacked), stock derivation, Delivery Order, Invoice, product-mapping guard,
Cibadak/Bolu parser + case-insensitive matching, duplicate-key checks across
PO/FG-packing/ceklis/kirim/invoice, and data-preservation (ceklis/fgPacking/
kirim/invoice untouched by a later revision upload). Also captures real
outgoing `kirimSheets` payloads via a stubbed `fetch` to verify the Google
Sheets contract never accumulates duplicate revisions.

**Workflow note found during this UAT**: "Finishgood" is two separate
mechanisms on the same page — the divisi's own checklist ("Cek Kesesuaian
Barang Diterima", which is what feeds `stokGudang`) and the "Packing Per
Toko" panel (allocation to stores, independent of the first). A real user
filling the page top-to-bottom does both naturally; a script driving only one
of them will see stock stay at 0. Not a bug — documented here so the next
person driving this suite doesn't trip on it.
