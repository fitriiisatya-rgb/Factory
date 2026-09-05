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
