# Order Entry Table + Import/Revisi PO UI Rework — cPanel Deployment

## What this package changes

1. **Pesanan Khusus Toko** and **Pesanan Non-Toko** item entry: the old
   one-card-per-item vertical layout is replaced with a single compact,
   scrollable table/grid (`Amor.createOrderItemGrid`), supporting 50+ items
   without becoming a giant page.
2. **`/api/_import-po/`** ("Import PO" wizard) is now rendered inside the
   same dark-navy Amor Factory shell (sidebar/topbar/cards/buttons) instead
   of as a standalone white page, and is retitled "Import / Revisi PO Toko".
   The URL/route and entry point (Pesanan Toko → PO Toko → Import/Revisi PO)
   are unchanged.

No database migration is included or required — this is a UI/presentation
rework only. The live migration state remains at `0013`.

## Files changed

- `api/assets/js/app.js` — new `Amor.createOrderItemGrid()`; `Amor.createAutocomplete()`
  menu is now portaled/positioned so it works correctly inside a scrollable
  table container.
- `api/assets/css/app.css` — new `.order-item-table*` styles (sticky header,
  sticky first two columns, bounded max-height); added missing `.alert-info`.
- `api/app/ui/pages/pesanan-khusus-toko.php` — rewritten to use the table grid.
- `api/app/ui/pages/pesanan-non-toko.php` — rewritten to use the table grid.
- `api/_import-po/index.php` — now renders through `api/app/ui/layout.php`
  (`ui_page_head`/`ui_page_foot`), restyled Step 1/Step 2/commit/success/
  history sections; all original business logic (session staging, CSRF,
  ADMIN gate, PoImporter/PoResolver handlers) is unchanged.
- `api/app/src/Import/PoImporter.php` — additive-only: `import()`'s return
  array gained `committedPoAwal`/`committedPoRevisi`/`storesMapped` (display
  only, already computed by `buildPlan()`, not consumed by any business rule).

## What did NOT change

- Karangtengah/Cibadak parser column layouts and product/store resolution.
- PO Awal / Revision / PB business rules (PO Awal is baseline-only, Revision
  is a full snapshot, PB is display/reference only, Target = PO Awal + latest
  Revisi).
- Autocomplete safety contract: selecting a product sets `product_id`;
  retyping the item text immediately invalidates the prior selection
  (`onSelect(null)`) — never silently keeps a stale `product_id`.
- Session/CSRF/ADMIN authorization and the runtime DML-only DB connection.

## Validation performed

Full real-stack validation (disposable MariaDB + real Apache + PHP-FPM +
headless Chromium against the **shipped ZIP**, not the source tree):

- Pesanan Khusus Toko: compact table present, old card layout gone, table
  height-bounded/scrollable, autocomplete suggestions work, selecting a
  product populates Divisi, retyping clears the stale selection, 50 rows
  added successfully, table scrolls internally (page stays compact, body
  height < 4000px even at 50 rows), row delete + renumbering correct, order
  saves successfully.
- Pesanan Non-Toko: same table checks, saves successfully.
- iPad viewport (768×1024): compact table still used (never reverts to
  stacked cards), page itself never scrolls horizontally.
- Import PO: dark shell (sidebar/topbar) present, title "Import / Revisi PO
  Toko" shown, "Phase 2 Fast-Track" text gone, Step 2 preview inside the same
  shell, Karangtengah factory auto-detected, a real seeded product (via
  legacy code) resolved to the EXISTING product (0 unresolved / no duplicate
  created), store count correct, "PO berhasil disimpan" success card shown,
  history section lists the imported batch, iPad Import PO page does not
  scroll horizontally as a whole.
- DB-level: seeded product row count unchanged (=1, no duplicate), imported
  PO line links to the same existing `product_id` with the correct `po_awal`.
- Full existing regression suite (Phase2POTest, Phase2NewProductTest,
  Phase2TotalsTest, SpecialOrderTest, FG allocation cascade) all green.

All checks passed on the final validation run.

## cPanel deployment steps

1. Take the current live site offline for writes if your process requires it
   (recommended but not strictly required — this package includes no schema
   migration).
2. Back up the current `api/` directory (files only; no DB backup needed
   since there is no migration).
3. Upload and extract `amor-factory-order-entry-import-po-ui-rework.zip`
   over the existing `api/` tree (or wherever your document root points),
   preserving `api/app/config/config.php` (never overwrite the live config).
4. No `php api/_upgrade/migrate.php` run is needed — migration state stays
   at `0013`.
5. Clear any opcode cache (`opcache_reset()` or restart PHP-FPM/LSAPI) if
   your host caches PHP bytecode.
6. Spot-check: open Pesanan Khusus Toko and Pesanan Non-Toko, confirm the
   compact table renders; open Pesanan Toko → PO Toko → Import/Revisi PO
   and confirm it now shows the dark navy shell with the title
   "Import / Revisi PO Toko".
