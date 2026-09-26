# Production Division + FG & Packing Rework — cPanel Deployment

## What this package changes

1. **User ↔ Production Division / FG Factory access** (many-to-many),
   wired opt-in into `Auth`, `ProductionService`, and `FgService`, with an
   admin assignment UI in `api/_users-uat/`. A user with **zero**
   assignment rows keeps today's unrestricted role-based access — nobody
   is locked out by deploying this package. Only once an admin explicitly
   assigns a `PRODUCTION` user to specific divisions (or a `FG_PACKING`
   user to specific factories) does access become scoped.
2. **Ceklis Produksi** (Produksi page) now shows PO Reguler **and**
   Pesanan Khusus/Non-Toko demand combined in one worksheet per
   division/date, with Sesuai/Tidak Sesuai buttons (server-enforced,
   never a trusted disabled input alone), a derived "Perlu Review Ulang"
   badge when a submitted document's live target has drifted from a
   later PO revision, and an enhanced admin division dashboard
   (Target/Actual/Reject/Sisa/Status/Terakhir Diperbarui/Disubmit Oleh,
   with zero-target divisions excluded from the outstanding-work count).
3. **FG & Packing (Reguler)** gains Sesuai/Tidak Sesuai for both Verified
   and Packing, independent Reject and Hilang columns, and a read-only
   Breakdown Toko view (per-store PO target reference).
4. **FG Sumber Khusus/Non-Toko** gains Sesuai/Tidak Sesuai for its own
   Verifikasi FG step.

**ONE new migration: 0014** (`fg_item.reject_qty`/`hilang_qty` only).
Everything else this rework needed — the many-to-many access tables, the
shared-worksheet optimistic-lock version column — already existed in the
schema, unused, since the original migration 0001 baseline.

## Migration decision (reported before implementation, per the task's own instruction)

An architectural audit found:
- `user_division_access` / `user_factory_access` already existed
  (migration 0001, marked "future scope") but were never read by any
  code — wired in now, no new table.
- `production_run.version` + its unique `(tanggal, division_id)`
  constraint already gave shared-worksheet concurrency protection — no
  new column.
- `production_item.status` ('sesuai'/'tidak_sesuai') already existed but
  nothing wrote to it meaningfully — Sesuai/Tidak Sesuai was a wiring
  gap, not a schema gap.
- Regular vs. non-regular target separation, non-regular source identity,
  and immediate production→FG visibility all already existed correctly
  in `ProductionTargetService`/`ProductionTaskService`/
  `NormalizedSourceType`/`FgTargetService` — the UI simply hadn't been
  wired to them yet.
- The one genuine gap: FG had no reject/hilang tracking at all. Migration
  0014 adds exactly those two columns to `fg_item`.

## Files changed

- `api/app/src/Auth.php` — `requireDivisionAccess()`/`requireFactoryAccess()`
  (opt-in scoping), session now carries `division_ids`/`factory_ids`.
- `api/app/src/Production/ProductionService.php` — `patchDraft()` gains
  server-side `sesuai` enforcement (re-derives the live target itself,
  never trusts a disabled UI input); `requireProductionScopedDivision()`
  now calls `Auth::requireDivisionAccess()`.
- `api/app/src/Fg/FgService.php` / `FgRepository.php` / `FgTargetService.php`
  — `reject_qty`/`hilang_qty` wiring, `sesuaiVerified`/`sesuaiPacking`
  enforcement (three-state: absent/true/false — absent never blocks, so
  every pre-existing caller keeps working unchanged), Keterangan-required
  validation, and the new read-only `storeBreakdown()` (Breakdown Toko).
- `api/app/src/Users/UserRepository.php` / `UserService.php` /
  `Controllers/UserController.php` — division/factory assignment CRUD,
  mirroring the existing role-assignment pattern.
- `api/app/src/Controllers/FgController.php` / `App.php` — new
  `GET /api/fg/store-breakdown` route.
- `api/app/ui/pages/produksi.php` — combined worksheet, Sesuai/Tidak
  Sesuai UI, Perlu Review Ulang badge, admin dashboard columns.
- `api/app/ui/pages/fg-packing.php` — Sesuai/Tidak Sesuai UI, Reject/Hilang
  columns, Per Produk/Breakdown Toko toggle.
- `api/app/ui/pages/fg-khusus-non-toko.php` — Sesuai/Tidak Sesuai for its
  Verifikasi FG step.
- `api/_users-uat/index.php` / `api/assets/js/users.js` — division/factory
  assignment checkboxes in the existing user-management admin tool.
- `database/schema-v1-0014-production-fg-division-rework.sql` +
  `api/app/migrations/0014_production_fg_division_rework.php`.

## What did NOT change

- PO Awal + latest Revisi (PB ignored) target formula — reused everywhere
  it already existed, never redefined or duplicated.
- Production/FG snapshot semantics, optimistic-lock version columns,
  submit/reopen lifecycle, audit trail — all untouched.
- Immediate production→FG visibility — already worked architecturally
  (`FgTargetService` sums SUBMITTED divisions per product; no "wait for
  all divisions" gate existed before or after this change).
- DO creation before FG completeness, and the shipment ready-FG guard —
  both already correct, untouched, still enforced exactly as before.
- Every existing FG reservation-safety mechanism (special/non-regular
  allocation, cross-flow guards, the 35-test `ALLOC-*`/`ALLOC-GLOBAL-*`
  suite) — completely untouched. Breakdown Toko never writes to
  `fg_item`'s store dimension, so `stock_ledger`/`stock_balance` posting
  is byte-identical to before this rework.

## Deliberately deferred (disclosed, not silently skipped)

- **FG store-level editable breakdown**: Breakdown Toko is a read-only
  per-store *target* reference, not an independently-editable per-store
  Verified/Packing entry surface. The single Per Produk `fg_item` row
  remains the sole stored quantity — this guarantees "no double counting
  between product/store view" by construction (there is nothing to
  double: only one row exists per product, ever) rather than by careful
  bookkeeping across two writable surfaces. Rearchitecting `fg_item` to
  hold genuine per-store writable rows would touch the reservation-safety-
  critical `submit()`/`stock_ledger` posting path this session's own
  history shows was hard-won; this package does not touch that path.
- **FG Packing for Non-Regular (Sumber Khusus/Non-Toko) orders**: this
  source has never had a "Packing" step (only "Verified" →
  `special_order_do`) — no Packing gate is added here, since that would
  be new shipment-blocking behavior, not a UI rework of something that
  already existed.
- **UI-level division-dropdown filtering for scoped FG users**: the
  factory picker (`$factories`) is a variable shared globally by every
  page in the shell, not page-local — filtering it would risk affecting
  unrelated pages (Dashboard, DO, Shipment). Server-side enforcement
  (`Auth::requireFactoryAccess()`) fully applies regardless; only the
  convenience of hiding disallowed options from the dropdown is deferred.

## Validation performed

- **24 new unit/integration tests** (`ProductionDivisionFgReworkTest.php`,
  PDFG-01..24) covering RBAC opt-in scoping in both directions, Sesuai
  server-side enforcement for both Production and FG, the three-state
  (absent/true/false) backward-compatibility rule, Reject/Hilang
  independence, and the store-breakdown endpoint's read-only guarantee.
  All 24 pass.
- **Full existing regression suite** (Phase0-5.5, UI/Print/Invoice
  preview, User Management, Special Order, FG Allocation Bridge,
  Final Pre-Live Rework — the deepest nested cascade in this repo,
  re-running essentially the entire historical test history) — **zero
  failures**. Two pre-existing test-infrastructure issues were found and
  fixed as part of this validation (both disclosed, neither a product
  bug): a migration-parking race in `run-phase4-fg-packing.sh` that
  didn't account for migration 0014's dependency on migration 0005's own
  column, and a stale hardcoded table-count assertion in `Phase0Test.php`
  (57, never updated after migrations 0011/0012 added 5 tables — now 62).
- **Real Apache + PHP-FPM + MariaDB + headless Chromium UAT** against the
  shipped ZIP: scoped RBAC denial/allow, the combined PO Reguler +
  Non-Regular worksheet with Sumber badges, Sesuai auto-fill/lock/
  round-trip through a real save, submit + Perlu-Review-Ulang check, FG
  Sesuai/Reject/Hilang entry and persistence, and the Breakdown Toko
  panel showing real per-store PO data. **22/22 checks passed.**

## cPanel deployment steps

1. Back up the current `api/` directory (files only) and take a database
   backup before applying migration 0014 (standard practice for any
   schema-changing deploy, even though this one is purely additive).
2. Upload and extract `amor-factory-production-division-fg-rework.zip`
   over the existing `api/` tree, preserving `api/app/config/config.php`.
3. Run the migration via `api/_upgrade/` (or `php api/bin/migrate.php`)
   to apply migration 0014 — adds `fg_item.reject_qty`/`hilang_qty`.
4. Clear any opcode cache / restart PHP-FPM if your host caches bytecode.
5. Spot-check: open **Manajemen User** (`api/_users-uat/`) and confirm the
   Divisi Produksi / Pabrik FG & Packing checkboxes appear in the
   Tambah/Edit User form; open **Produksi** and confirm the combined
   worksheet and Sesuai/Tidak Sesuai buttons render; open **FG & Packing**
   and confirm the Breakdown Toko toggle and Reject/Hilang columns
   render.
6. No existing user is affected until an admin explicitly assigns
   division/factory access to them — this is safe to deploy without any
   coordinated rollout.
