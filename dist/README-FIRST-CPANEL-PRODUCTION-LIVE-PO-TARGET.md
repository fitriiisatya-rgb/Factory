# Production Live PO Target Visibility — cPanel Deployment

## Bug fixed

Real UAT repro: a Regular PO import for 2026-09-26 / Karangtengah succeeded
(target total 39: BOLLEN LILIT COKLAT=25, CHOCO CUBE 12=14), but
Produksi → Ceklis Produksi → 26 Sep 2026 → Karangtengah showed
Target Produksi = 0 and every division row (including Roti & Bollen) showed
"-" for Target/Actual/Sisa with status "Belum Dimulai" — the operator had to
create a Production Draft first just to see the demand.

**Root cause**: `api/app/ui/pages/produksi.php`'s overview page ran its own
raw SQL query joined from `production_run`/`production_item`, so it only had
numbers once a `production_run` row existed. The rest of the codebase
(`ProductionService::loadTarget/createDraft/buildRunDto/submit/refreshItemTargets`,
`ProductionTaskService`) already used the correct authoritative source,
`ProductionTargetService::targetsByProduct()` (live PO target =
`po_awal + po_revisi`, PB never read) — the overview page was the one place
that had NOT been wired to it.

**Fix**: `produksi.php` now calls the same
`ProductionTargetService::targetsByProduct($pdo, $tanggal, $factoryId, $divisionId)`
used everywhere else, aggregates it per division for the top KPI cards and
the division summary table, and only additionally joins `production_run`/
`production_item` to know Actual and the run's lifecycle status. Target is
now always the live PO target, independent of whether a draft/run exists;
Actual is 0 until a run exists; Sisa = max(0, Target − Actual); Status is
"Belum Dimulai" only when no run exists yet (unchanged from before).
`ProductionService::classifyDisplayStatus()` was changed from `private` to
`public static` so the overview page can reuse the exact same status
classification used by the rest of the module (no logic duplicated).

No database migration; live migration state stays at 0013.

## Files changed

- `api/app/ui/pages/produksi.php` — overview now sourced from
  `ProductionTargetService::targetsByProduct()`; table/KPI rendering
  rewritten to always show numeric Target/Actual/Sisa instead of "-".
- `api/app/src/Production/ProductionService.php` — `classifyDisplayStatus()`
  visibility changed to `public static` (no behavior change).
- `api/tests/Phase3ProductionTest.php` — 10 new tests (LIVE-01..LIVE-10)
  covering: no PO → target 0; PO exists, no draft → target visible; create
  draft → target unchanged; PO revision → live target updates; PB ignored;
  submitted actual/remaining correct; overproduction preserved; Cibadak and
  Karangtengah both correct; division filtering correct.

## What did NOT change

- `ProductionTargetService`'s target formula (`po_awal + po_revisi`, PB
  ignored) — untouched.
- Draft/run creation, submission, revision, and reopen logic in
  `ProductionService.php` — untouched; already used the correct source.
- No double counting: PO target (demand) and `production_item` (execution
  state) are never summed together as two independent demands.
- Product→division routing — untouched.

## Validation performed

- All 10 new `LIVE-01..LIVE-10` unit-level tests pass.
- Full existing regression suite (`run-fg-allocation.sh` cascade including
  `FLOW-01..42`, Phase2/SpecialOrder suites) green — zero regressions from
  the `ProductionService.php` visibility change or the `produksi.php`
  rewrite.
- Real Apache + PHP-FPM + MariaDB + headless Chromium validation against the
  **shipped ZIP**, reproducing the exact real UAT scenario (2 real Roti &
  Bollen products seeded with po_awal 25 and 14, 2026-09-26, Karangtengah,
  confirmed zero `production_run` exists beforehand):
  - Before any draft: top KPI Target Produksi = 39; Roti & Bollen row shows
    Target=39 / Actual=0 / Sisa=39 (never "-"), status "Belum Dimulai".
  - Clicking "Buat/Buka Draft" creates the run; the draft's item target for
    the first product matches the overview exactly (25).
  - After the draft exists: overview KPI is still 39, row status is now
    "Draft", row Target is still 39 (no mismatch, no double counting).
  - DB checks: exactly 1 `production_run` created; `production_item.target`
    sums to 39.00.

All 15 checks passed on the first validation run.

## cPanel deployment steps

1. Back up the current `api/` directory (files only; no DB backup needed —
   no schema migration in this package).
2. Upload and extract `amor-factory-production-live-po-target.zip` over the
   existing `api/` tree, preserving `api/app/config/config.php`.
3. No `php api/_upgrade/migrate.php` run is needed — migration state stays
   at `0013`.
4. Clear any opcode cache / restart PHP-FPM if your host caches bytecode.
5. Spot-check: open Produksi → Ceklis Produksi for a date/factory with an
   imported PO but no draft yet, and confirm Target Produksi and the
   division rows show the real numeric target instead of 0/"-".
