# SESSION HANDOFF — Amor Factory System

**Purpose of this file:** this is a continuity document for whichever Claude
Code session/account picks up this project next. Read this file FIRST, in
full, before doing anything else. It is written to let a fresh session with
zero prior context resume exactly where the last one stopped, without
re-deriving decisions that have already been made and validated. This is a
full rewrite (not an incremental patch) — it supersedes any earlier version
of this file you may find in git history.

Written: 2026-09-21. Last commit at time of writing: `dc09a61`.

---

## 1. What this project is

**Amor Factory System** — a from-scratch PHP/MySQL rebuild of the internal
ERP used by **CV. Amor Group / Amor Cakes & Bakery**, replacing a legacy
Google Apps Script (Code.gs) + Google Sheets system. Built incrementally in
strictly ordered **phases**, each phase fast-tracked, tested, packaged for
cPanel shared hosting, and delivered before the next phase begins.

- **Repo**: `fitriiisatya-rgb/Factory` (GitHub)
- **Branch**: `claude/amor-factory-pricing-ui-final-6vv3q9` — ALL work goes
  here. Do not create a different branch unless explicitly instructed.
- **Working directory in the container**: `/home/user/Factory`
- **Deployment target**: cPanel shared hosting. Domain docroot is
  `public_html/factory/` (NOT `public_html/` itself), domain
  `factory.amorgroup.id`, `api/` is a real subdirectory of that docroot.
  Absolutely no Composer/vendor directory, no SSH/CLI access assumed for
  the client, no ability to run `composer install` on the server — every
  migration/upgrade is done through a web UI (`api/_upgrade/`).
- **Language/tone for user-facing text**: Bahasa Indonesia, non-technical,
  for every UI label, error message, and README aimed at the client. Code
  comments and internal docs (including this file) are in English.
- **The user** communicates in Indonesian, works with multiple Claude Code
  accounts/sessions on this same project over time (hence this handoff
  file's existence, and their habit of asking for it to be refreshed and
  resent after a burst of work), and does real cPanel UAT personally —
  meaning bugs that only manifest on a real Apache server (not `php -S`)
  are a real, recurring risk class for this project (see §7 gotcha #1).
  **The user reports real UAT bugs in a highly detailed, structured
  format** (exact scenario, real product/quantity numbers, exact required
  fix behavior, exact required test names, exact required deployment
  procedure, exact required closing wording) — read their request in full
  before starting; it is usually a complete spec, not a vague bug report.

## 2. Current exact state (verify before trusting anything below)

```
git log --oneline -10
dc09a61 Add Driver Portal UX + shipment tracing patch
280d754 Add Phase 4 FG production source refresh patch
b21cf99 Rewrite session handoff into one coherent document (again)
9b4a827 Update session handoff with the new User Management module
0930baf Add ADMIN User / Driver Account Management module
52d476f Rewrite session handoff as one coherent document
a183518 Update session handoff with the real-Apache asset bug and fix
bb3a5a9 Fix Phase 5.5 real-cPanel deployment bug: public assets under deny-all api/app/
a1fce14 Add session handoff document for continuity across account switches
ac07251 Fix Phase 5.5: multi-factory departure claim->shipment mapping
```

`git status` is clean (nothing uncommitted) as of this writing. Everything
through `dc09a61` is already **pushed** to
`origin/claude/amor-factory-pricing-ui-final-6vv3q9`.

**First thing to do in a new session**: run `git log --oneline -10` and
`git status` yourself to confirm this is still accurate — do not assume
nothing changed between sessions. If the log has commits beyond `dc09a61`,
someone (another session, or you in a prior turn you don't remember) has
already continued past this document — read those commit messages before
doing anything else.

## 3. Phase history (what exists, in order)

Each phase = one self-contained MySQL/PHP module + API + a manual UAT wizard
under `api/_xxx-uat/` + an automated test suite + a cPanel ZIP package + a
non-technical Indonesian README. Every later phase's test orchestrator
re-runs ALL earlier phases' test suites as a cascading regression — this is
the project's core safety net.

| Phase | What it is | Migration | Key services |
|---|---|---|---|
| 0 | Core schema (users/roles/auth/audit/idempotency), pre-declares tables for ALL future phases (invoice/payment/return_note/reject_note/retail_sale etc. already exist as empty tables since 0001) | 0001 | `Auth`, `Audit`, `Idempotency`, `Database` |
| 1 | Master data import: divisions, ~472 katalog products, store alias resolution (no fuzzy matching — ambiguous names are always REVIEW/CONFLICT, never silently merged) | (part of 0001/0002) | `Phase1Importer`, `LegacyCatalogSource` |
| 2 | PO (Pesanan Toko) import from real Excel files — dependency-free `XlsxReader.php`, `PoFileParser`, `PoResolver`, `PoMerger`, `PoRepository`, `PoImporter` | 0003 | `api/_import-po/` wizard |
| 3 | Production (Produksi) actual entry against PO targets, submit lifecycle | 0004 | `ProductionTargetService`, `ProductionService`, `api/_production-uat/` |
| 4 | FG & Packing — verifies Production output into finished-goods stock, posts `stock_ledger` (`production_in`). **Patched 2026-09-19** with a standalone "Refresh Produksi Terbaru" action so an EXISTING FG batch can safely resync its source Production snapshot (see §5.1). | 0005 | `FgTargetService`, `FgService`, `api/_fg-uat/`, `api/app/ui/pages/fg-packing.php` |
| 5 | Delivery Order (Draft DO) + Shipment — one DO per (store, date), can legitimately span two factories (see §4.9); shipment commit deducts stock (`shipment_out`) via `ShipmentService::ship()`, the SINGLE place stock ever decreases for outbound goods | 0006 | `DoService`, `ShipmentService`, `api/_do-uat/` |
| — | Redesigned admin UI shell (dark mode, shared layout/CSS/JS) sitting on top of Phase 0-5 APIs, read/write via the same JSON API, no parallel business logic | — | `api/_ui-preview/`, `api/app/ui/*` |
| — | Print DO / Surat Jalan redesign (A4, watermark, signature grid) — presentation only | — | `api/app/ui/print-template.php` |
| — | Invoice print/preview template — **UI/print layout ONLY, no invoice generation business logic** | — | `api/app/ui/print-invoice-template.php`, mock fixture only |
| 5.5 | Dispatch Pool / Driver Claim / Store Receipt Confirmation — sits between Phase 5 (DO/Shipment) and the not-yet-built Phase 6 (Invoice). Drivers claim delivery tasks from an open pool, build a manual route, confirm departure (the ONLY point stock decreases here too — reuses `ShipmentService::ship()`, never duplicates stock logic), stores scan a QR to confirm receipt (good/reject/shortage qty). Admin verifies discrepancies. Two post-delivery bug fixes (multi-factory mapping, real-Apache asset path — see prior handoff versions in git history for full writeups), then two further real-UAT patches: a driver-account gap (§ User Mgmt row below) and, most recently, a Driver Portal UX + tracing patch (see §5.2). | 0007 | `DispatchService`, `DepartureService`, `ReceiptService`, `api/_driver-uat/`, `api/_receive/` |
| User Mgmt | ADMIN User / Driver Account Management — the missing piece for real Phase 5.5 UAT (needed a way to create DRIVER accounts without phpMyAdmin/manual SQL). Create/edit/deactivate/reactivate/reset-password/change-roles for any user, with a "never zero active ADMIN" safeguard. **Files-only, no migration** — `users.active`/`username` UNIQUE/`password_hash`/`roles`+`user_roles` M:N all already existed since 0001. | — (none) | `Users\UserService`, `Users\UserRepository`, `api/_users-uat/` |

**Most recent work (2026-09-21, see §5 for full writeups): the Driver
Portal UX + Shipment Tracing patch** (commit `dc09a61`) is the newest
thing done. Just before it, the **Phase 4 FG Production Source Refresh
patch** (commit `280d754`) fixed a real UAT bug where an existing FG batch
had no safe way to resync after its source Production was resubmitted.
**Phase 6 (Invoice business logic/generation) has NOT been started** —
only its print/preview UI exists, with mock data, no real transactional
logic. Do not start Phase 6 business logic without explicit user
instruction.

## 4. Architecture & conventions — READ THIS before writing any code

These are load-bearing conventions established and validated across every
phase. Breaking them will look like it works locally and then fail a
regression test, the cPanel package validation, or — worse, as happened
more than once — pass every automated check and still be broken on the
real server or in the real browser.

1. **No Composer, no `vendor/`, ever.** Anything that looks like it needs a
   third-party library must be hand-written. Precedents: `XlsxReader.php`
   (reads real `.xlsx` files byte-by-byte), `Ui/QrEncoder.php` (a full
   ISO/IEC 18004 QR encoder written from scratch, GF(256) Reed-Solomon
   included, validated against independent Python tools — see §7.7). This
   is because the target server has no shell/Composer access.

2. **Every mutating endpoint requires an `Idempotency-Key` header.**
   `Idempotency::handle($request, $endpoint, $work)` wraps the whole
   `$work` callback in ONE `Database::transaction()`. Same key + same
   request fingerprint → replay the stored response verbatim (never
   re-executes). Same key + different fingerprint → 409
   `IDEMPOTENCY_KEY_REUSE_MISMATCH`. If `$work` throws, the transaction
   rolls back and nothing is recorded (a retry is free to re-evaluate).
   **Frontend double-click/double-tap guards (see rule 13) are a UX
   convenience layered ON TOP of this — never a replacement for it.**

3. **Optimistic concurrency via `expectedVersion` — but only where it
   earns its keep.** Every shop-floor transactional aggregate
   (`production_run`, `fg_batch`, `delivery_order`, dispatch claims,
   `product`/`store` master data) has a `version` column: every mutation
   takes `expectedVersion`, checks it under a row lock, bumps it by
   exactly 1 on success, throws 409 `VERSION_CONFLICT` otherwise. When one
   logical operation calls multiple version-bumping sub-operations in
   sequence inside the same transaction (e.g. `DepartureService` calling
   `ship()` once per factory group), the next `expectedVersion` is
   computed locally as `previous + 1` — never re-queried — because both
   calls share the same open transaction. **Exception, deliberately**:
   `users` has NO `version` column — the User Management module judged a
   plain `SELECT ... FOR UPDATE` row lock per mutation sufficient for that
   low-frequency, single-actor-at-a-time admin screen. Don't assume every
   table has `version` — check first.

4. **Row locking discipline.** Lock the PARENT aggregate row (`SELECT ...
   FOR UPDATE`) before reading/mutating anything under it. When one
   operation must lock multiple parent rows, always lock them in a
   **fixed, ascending ID order** (or lock the whole relevant set) across
   every caller, to prevent lock-order deadlocks.

5. **CSRF via `X-CSRF-Token` header** on session-authenticated
   state-changing requests. Public unauthenticated endpoints (like the
   store receipt confirmation) are exempted in `App.php` — exact-path
   exemptions live in a `CSRF_EXEMPT` array, but a route with dynamic path
   segments (like `/api/receive/{token}/...`) needs a **prefix check**
   instead (see `$isPublicReceiptConfirm` in `App.php`).

6. **Audit logging** on every meaningful mutation via
   `Audit::write($pdo, $requestId, $userId, $eventType, $entityType,
   $entityId, 'ok'/'error', $fromVersion, $toVersion, $details)`. Never
   put a password (plaintext or hash) in the `$details` payload.

7. **Migrations are additive-only, and often unnecessary.** Never alter or
   drop an existing column without a very strong reason. Migration 0001
   already pre-declared tables for invoice/payment/return_note/
   reject_note/retail_sale etc. for future phases. Before adding a new
   migration, **audit the existing schema first** and document exactly why
   each new table/column is needed. Three precedents for "the schema
   already had it, zero migration needed": User Management, and — most
   recently — the FG Production Source Refresh patch, where
   `fg_batch_source.source_version` and `fg_item.production_actual_snapshot`
   had existed since migrations 0001/0005 and just weren't being refreshed
   correctly by the application code (see §5.1). **Files-only patches with
   zero schema change are now the norm for bug-fix/UX work in this
   project** — always audit before assuming a new migration is needed.

8. **Business-identity keys, not surrogate assumptions.** DO identity =
   `(tanggal, storeId)`. `production_run` identity = `(tanggal,
   divisionId)` — **this means two products in the SAME division on the
   SAME date share ONE production_run and must go through ONE
   create→patch→submit call sequence, never two separate ones** (a real
   trap hit while writing this session's own new tests — see §7.9).
   `fg_batch` identity = `(tanggal, factoryId)`. A receipt token identity =
   one per DO. Always check for an existing row on that identity before
   inserting a new one.

9. **Stock ledger has ONE authority.** `stock_ledger` only ever gets a
   `shipment_out` row from inside `Delivery\ShipmentService::ship()`, and
   a `production_in` row from inside `Fg\FgService::submit()` — no other
   code path is allowed to write a stock movement. A **source-refresh /
   resync action must write ZERO stock_ledger rows** — this was the core
   safety invariant of the Phase 4 FG Source Refresh patch (§5.1) and is
   the same discipline any future "refresh"/"resync" feature must follow:
   refreshing a *snapshot* of upstream data is always safe; only an
   explicit submit/ship action may ever move stock.

10. **Never use a "last value wins" pattern when an operation splits into
    multiple sub-calls that must each be attributed back to different
    inputs.** This caused the Phase 5.5 multi-factory bug (fixed, see git
    history for commit `ac07251`) — group inputs by their real key, call
    the sub-operation once per group, map EACH group's result back only to
    the inputs that were actually in that group.

11. **Public (browser-reachable) assets live under `api/assets/`, NEVER
    under `api/app/`.** `api/app/.htaccess` is intentionally `Require all
    denied` — everything under `api/app/` (source, config, migrations, PHP
    templates) must never be directly web-reachable. Any CSS/JS/image a
    browser needs to load directly belongs in `api/assets/`. **A shared,
    cross-page JS component (like `Amor.confirmModal()` in `app.js`) needs
    ITS CSS available on every page that calls it — `driver.css` did NOT
    load `app.css`'s `.modal-backdrop`/`.modal`/`.toast` rules, so the
    confirm dialog rendered completely unstyled on the whole Driver
    Portal** (see §5.2). When adding a new shared JS behavior, check
    whether every CSS bundle that will use it actually defines its
    classes — don't assume a shared JS file implies shared CSS coverage.

12. **A standalone admin tool needing its own gate follows the
    `_driver-uat/`/`_users-uat/` shape**: `require app/ui/bootstrap.php`
    for session/CSRF/PDO, add an explicit role check for anything narrower
    (DRIVER-or-ADMIN needed its own separate login page since
    `_admin-login/` rejects non-ADMIN — see §7.2), then a self-contained
    HTML shell referencing `/api/assets/...` directly rather than pulling
    in `layout.php`'s full sidebar.

13. **A shared confirm/modal component must guard against being opened
    twice, and must support an async "do the real work while the dialog
    stays open" mode when the confirmed action is itself a server call.**
    `Amor.confirmModal()` (in `api/assets/js/app.js`) now has (a) a
    module-level singleton guard — a second call while one is already open
    is treated as a no-op `resolve(false)`, so a real UAT bug (3 rapid taps
    on a button opening 3 stacked dialogs) can never recur no matter how a
    future caller behaves, and (b) an optional `opts.onConfirm` async
    callback: when passed, clicking the confirm button disables both
    buttons, shows a pending label ("Memproses..." by default), blocks
    repeat clicks/backdrop-click/Escape while pending, and on failure
    re-enables the buttons and shows the error INSIDE the dialog rather
    than just a toast. Every OLD call site (plain yes/no confirms with no
    `onConfirm`) keeps working exactly as before — this was an additive,
    backward-compatible change. **Always pass `confirmModal()` an options
    OBJECT (`{title, body, ...}`), never a raw string** — a raw string
    silently falls back to the generic "Konfirmasi"/"Lanjutkan?" text with
    no error (this was a second, independent bug found in the same
    real-UAT report — see §5.2).

## 5. Most recent work

### 5.1 Phase 4 FG Production Source Refresh patch (commit `280d754`)

**Real UAT report**: Production Run for 2026-09-05 (Karangtengah, Roti &
Bollen) was edited and resubmitted (BOLLEN KOMBINASI actual 0→2, version
8→11) AFTER an FG batch already existed for that date. The FG page
correctly detected and displayed the "Ketidaksesuaian Sumber Produksi"
mismatch warning, but had **no way to actually resync** — the prominent
"4. Muat Produksi Submitted" button only re-displayed the existing batch,
never refreshed it, and the "Filter Divisi Sumber" was (correctly, but
confusingly) preview-only and had no effect once a batch existed.

**Root cause, audited not guessed**: two compounding issues. (a) A pure
UX/wiring gap — the button did nothing useful on an existing batch. (b) A
real logic bug in `FgService::refreshSource()` (the underlying,
buried, checkbox-triggered refresh mechanism): a guard meant to protect
operator-entered `fgVerified` data (`if ($existingItems[$productId]['qty']
<= 0.0001)`) was ALSO mistakenly gating the unrelated, always-safe
`production_actual_snapshot` update — so the snapshot froze forever the
moment any FG Verified value was entered, even though `source_version`
kept updating, meaning the mismatch WARNING could later disappear while
the underlying NUMBER stayed stale.

**Fix**: `refreshSource()` now always refreshes the snapshot (safe,
derived data) while never touching `fgVerified`/`packed_qty`. Added a
standalone `refreshProductionSource()` method + `POST
/api/fg/{id}/refresh-source` route + controller action — a dedicated,
one-click "Refresh Produksi Terbaru" action that needs only the batch id
+ `expectedVersion`, writes **zero `stock_ledger` rows**, and reports back
which items changed (`refreshChangedSnapshots`) and which are now blocking
(`verifiedExceedsProductionBlocking`, when a refreshed-down snapshot is
now below an already-entered `fgVerified`). Added a `submit()`-time
pre-flight check (`FG_EXCEEDS_PRODUCTION`, 409) so a lowered snapshot can
NEVER silently let stock post beyond it — `fgVerified` itself is NEVER
auto-reduced; an admin must lower it manually before submit will proceed.

UI: both `api/_fg-uat/index.php` (the OLDER plain-HTML wizard — confirmed
via exact label matching to be what real cPanel UAT is actually using)
and the modern `api/app/ui/pages/fg-packing.php` got a "Refresh Produksi
Terbaru" button (inside the mismatch warning, plus a standalone version
when no mismatch exists yet), a red "Diblokir untuk Submit" panel, and the
"Filter Divisi Sumber" is now disabled/relabeled "TIDAK BERLAKU" once a
batch already exists for that date/factory.

**No schema change** — `fg_batch_source.source_version` and
`fg_item.production_actual_snapshot` already existed since migrations
0001/0005. Tests: `FG-R01..R11` added to `Phase4FgPackingTest.php`
(45/45 total incl. pre-existing P4-01..32/P4-VAR01..04, all green).
Package: `dist/amor-factory-api-phase4-fg-source-refresh-patch.zip`.
Closing wording used: "PHASE 4 FG PRODUCTION SOURCE REFRESH PATCH READY
FOR REAL CPANEL UAT / NO STOCK REPOST ON SOURCE REFRESH / PHASE 5 / 5.5
BUSINESS LOGIC UNCHANGED / NOT production ready."

### 5.2 Driver Portal UX + Shipment Tracing patch (commit `dc09a61`) — most recent

**Real UAT report**: DO `DO/KRM/004/IX/2026`, Bakery Abdul Gani, Driver A
claimed AVOCADO RING=5 + BOLLEN KOMBINASI=2, confirmed departure
successfully (core shipment creation worked), but several UX/display
problems surfaced:

1. **Confirm-departure dialog broken** — rendered at the bottom-left,
   unstyled, and appeared stacked ~3 times.
2. **Success screen** was generic, no real Shipment ID/time shown.
3. **"Rute Saya" showed 0 produk / 0 pcs after departure**, even though
   the shipment just created had 2 products / 7 pcs.
4. **Riwayat cards were not clickable** — no shipment tracing possible.
5. Raw DB timestamp format shown instead of Indonesian/Asia-Jakarta.
6. No visible Driver Logout.

**Root causes, all audited (see §4 rules 11/13 for the general lessons)**:
(1) `driver.css` never loaded `app.css`'s `.modal-backdrop`/`.modal`/
`.toast` CSS at all (driver pages deliberately never load the full admin
`app.css`), so the shared `Amor.confirmModal()` dialog rendered as plain
unstyled HTML flowing in normal document position — never a real overlay.
The stop-detail page's own click handler also had zero guard against
repeat clicks, and — a third, independently-found bug — it called
`confirmModal('some string')` instead of `confirmModal({title:...,
body:...})`, so the real confirmation text was silently replaced by the
generic fallback the whole time. (3) `DispatchService::myRoute()` computed
a route stop's `productCount`/`totalQty` by summing only **ACTIVE**
`dispatch_claim` rows. `confirmDeparture()` always resolves every touched
claim to a terminal `'departed'` status with `active_qty` reset to 0 (by
design — a resolved claim must never be double-counted or re-released),
so once every claim for a stop had departed, summing "active" claims for
that stop correctly read 0 — even though the real shipment held the true
quantities.

**Fixes**:
- `Amor.confirmModal()` (`api/assets/js/app.js`) gained a singleton
  open-guard and an optional `onConfirm` async mode (see §4 rule 13).
  `driver.css` got the missing CSS ported in (self-contained copy, driver
  pages still never load the full `app.css`). The stop-detail page's
  click handler now passes a proper options object with the exact
  required title/message/summary, and uses `onConfirm` so the actual
  `POST /api/dispatch/departures` call happens while the dialog shows
  "Memproses..." and blocks repeat clicks.
- `DispatchService::myRoute()`: a DEPARTED stop's totals now come from a
  new `DispatchRepository::findDepartedTotalsForDriver()` query
  (aggregates real `shipment_item` rows per store/driver/date) instead of
  the now-zeroed active claims. A NOT-yet-departed stop keeps using live
  active-claim totals, unchanged. Verified: claim 5/ship 3 correctly shows
  3 on the route (never the original claim qty); two shipments under one
  DO aggregate correctly (2 produk/9 pcs, not double-counted or lost).
- New read-only, driver-scoped **Shipment Detail** page:
  `GET /api/dispatch/shipments/{id}` (`DispatchService::shipmentDetail()`)
  + `api/_driver-uat/shipment.php`. Authorization: non-admin caller may
  only open a shipment where `shipment.shipped_by === requestingUserId`
  — 403 `FORBIDDEN` otherwise, with NO data payload leaked. Shows header
  (store/shipment id "SHP-{id}"/DO/driver/group/factory/status), product
  table from real `shipment_item` qty (never the original claim qty —
  "shipment is the dispatch truth after departure"), a receipt trace
  section (from `shipment_receipt`/`shipment_receipt_item`, showing
  "Belum Dikonfirmasi" when none exists yet), and a simple timeline
  (Driver Claim → Berangkat → Konfirmasi Toko → Diverifikasi Admin) built
  ONLY from data that actually exists — nothing invented.
- Riwayat cards (`driver.js` `renderRiwayat`) are now clickable, showing
  real product-count/qty totals sourced from an extended
  `findShipmentHistoryForDriver()` query (batched correlated subquery, no
  N+1), and link to the new Detail Pengiriman page.
- New `ui_fmt_datetime_id()` (PHP, `api/app/ui/bootstrap.php`) and
  `fmtDateTimeId()` (JS, `driver.js`) convert a stored UTC timestamp to
  Asia/Jakarta display format ("21 Sep 2026 · 10:20") — **display only**,
  nothing stored changes.
- Driver Logout button added to the topbar, reusing the EXISTING
  `POST /api/auth/logout` endpoint (no new backend mechanism).

**No schema change.** Tests: `DPT-05, DPT-07..19` added to
`Phase55DispatchReceiptTest.php` (31/31 total incl. pre-existing
P55-01..23/MF01-02, all green) — PLUS a genuine headless-Chromium
Playwright run (not just code review) against a live disposable server,
verifying `DPT-01..04/06` (exactly one centered `.modal-backdrop` after 3
rapid clicks, correct title/message, `position:fixed`, Batal closes
cleanly with no shipment created, confirm button disables with
"Memproses..." immediately, success screen shows real `SHP-{id}` +
totals). See §7.10 for the exact reusable recipe (chromium binary path,
the docroot-router gotcha it also hit).

Package: `dist/amor-factory-driver-ux-tracing-patch.zip`. Closing wording
used: "DRIVER PORTAL CONFIRMATION / ROUTE SUMMARY / SHIPMENT TRACING PATCH
READY FOR REAL CPANEL UAT / PHASE 5.5 BUSINESS LOGIC UNCHANGED / NO STOCK
LOGIC CHANGE / NOT production ready."

## 6. Directory map

```
api/
  assets/                PUBLIC static browser assets (css/js/img) — the
                          ONLY place browser-facing CSS/JS/images may live.
                          Own .htaccess: "Options -Indexes" only, no deny.
                          js/app.js, driver.js, receipt.js, users.js
                          css/tokens.css, app.css, driver.css, receipt.css
  app/                    api/app/.htaccess = "Require all denied" — NOTHING
                          in here is ever directly web-reachable. NEVER put
                          a browser-facing asset anywhere under here again.
    src/
      Import/          Phase 1 katalog/division import (Phase1Importer,
                        note: PoImporter.php also lives here, NOT under a
                        "Po/" namespace — check before assuming a path)
      Production/       Phase 3
      Fg/               Phase 4 — FgService.php has the Source Refresh
                        patch (refreshSource/refreshProductionSource)
      Delivery/         Phase 5 — DoService, DoRepository (now also
                        findShipmentById), ShipmentService
      Dispatch/         Phase 5.5 — DispatchService/Repository (now also
                        myRoute's departed-totals fix + shipmentDetail()),
                        DepartureService, ReceiptService/Repository
      Users/            User Management — UserRepository, UserService
      Controllers/      One controller per phase's API surface, incl.
                        UserController.php, DispatchController.php (now
                        also shipmentDetail())
      Ui/               QrEncoder.php (dependency-free QR)
      Setup/            Seeder.php (roles/factories/synthetic store),
                        AdminCreator.php (CLI-only first-admin bootstrap —
                        NOT the general user-management path, see §7.8)
      App.php           Router + CSRF exemption list — now also
                        /api/fg/{id}/refresh-source and
                        /api/dispatch/shipments/{id}
    migrations/         0001_..php through 0007_..php (dual-location
                        pointer pattern — see any existing one). Still
                        only 7 — every patch since has been files-only.
    ui/                 Server-side PHP templates only (no static assets
                        here anymore — see api/assets/ above)
      bootstrap.php     Shared session/CSRF bootstrap for the admin UI —
                        also now has ui_fmt_datetime_id() (Asia/Jakarta
                        display formatter, shared by driver pages too)
      layout.php        ui_page_head/ui_page_foot, sidebar nav items
      pages/            One file per admin UI page, incl. fg-packing.php
                        (now has the Refresh Produksi Terbaru button)
      print-template.php, print-invoice-template.php
      labels.php        ui_badge()/ui_status_color()
  _import-po/, _production-uat/, _fg-uat/, _do-uat/, _ui-preview/
                        Manual UAT wizards per phase. _fg-uat/index.php is
                        confirmed (via exact label matching) to be what
                        real cPanel UAT actually uses for FG/Packing.
  _driver-uat/          Phase 5.5 mobile driver portal — has ITS OWN
                        login.php (see §7.2). Now also shipment.php (new
                        read-only Detail Pengiriman page).
  _receive/             Phase 5.5 PUBLIC store receipt portal — no
                        session/login, resolves identity only via the
                        secure per-DO token in the URL
  _users-uat/           User Management — ADMIN-only, standalone (rule 12)
  _admin-login/         ADMIN-ONLY login (deliberately rejects any
                        non-ADMIN account — this is intentional)
  _upgrade/             Web-based migration runner (no SSH/CLI needed)
  _setup/                RETIRED, one-shot initial-install wizard only —
                        never reintroduced into any shipped package
  tests/
    Phase0Test.php, Phase2POTest.php, Phase3Test.php,
    Phase4FgPackingTest.php  <- now also FG-R01..R11 (Source Refresh),
    Phase5DoShipmentTest.php, PhasePrintTest.php, PhaseInvoiceUiTest.php,
    Phase55DispatchReceiptTest.php  <- now also DPT-05/07..19 (Driver UX),
    PhaseUserMgmtTest.php  (UM-01..16)
    run-phaseX.sh / run-*.sh          <- one orchestrator per suite, each
                                         cascades into the previous phase's
                                         orchestrator for full regression
                                         (run-user-mgmt.sh -> run-phase55-
                                         dispatch-receipt.sh -> ... -> Phase
                                         0). ALL use php -S (see gotcha #1
                                         for what that can't catch).
    _ui_router.php, _phase2_bootstrap_master.php  <- shared test harness
                        helpers. _ui_router.php is ALSO required for any
                        ad-hoc/Playwright browser check against a php -S
                        server — see §7.10, this was re-discovered the
                        hard way this session.
database/
  schema-v1.sql                      Canonical full schema (mirrors 0001)
  schema-v1-0003-... .sql through schema-v1-0007-...sql   Per-migration DDL mirrors
dist/
  build-cpanel-package-*.sh          One per module's deliverable ZIP —
                                      now incl. build-cpanel-package-
                                      phase4-fg-source-refresh.sh and
                                      build-cpanel-package-driver-ux-
                                      tracing.sh
  validate-*-package.sh              Extracts the REAL zip, smoke-tests it
                                      via php -S (fast, but see gotcha #1)
  validate-phase55-apache-assets.sh,
  validate-user-management-apache.sh  Real Apache+PHP-FPM vhost with
                                      AllowOverride All — the only checks
                                      that actually enforce .htaccess.
  README-FIRST-CPANEL-*.md           Non-technical Indonesian deployment
                                      guide — now incl. ...-PHASE4-FG-
                                      SOURCE-REFRESH.md and ...-DRIVER-UX-
                                      TRACING.md
  *.zip                              The actual deliverables sent to the user
```

## 7. Known gotchas — things that will bite you if you don't know them

1. **`php -S` (used by every `run-*.sh` and older `dist/validate-*.sh`)
   IGNORES `.htaccess` ENTIRELY.** `.htaccess`-dependent behavior (deny-all
   directories, the front-controller rewrite bypass, per-directory
   `Options`) can pass every existing automated check and still be
   completely broken on the real server. For any change touching a
   browser-facing page/asset/`.htaccess`, also run (or extend/copy)
   `dist/validate-phase55-apache-assets.sh` or
   `dist/validate-user-management-apache.sh` — a real Apache 2.4 +
   PHP-FPM 8.3 validation. Install: `apt-get install apache2 php8.3-fpm
   php8.3-mysql` (plain Ubuntu archive, not sury/ondrej PPA — blocked by
   this sandbox's egress proxy). No systemd — start both manually
   (`php-fpm8.3 -D`, `apache2ctl start`, see either existing script for
   the exact vhost pattern). Remember to `chmod 755` any `mktemp -d`
   workdir before pointing a vhost at it (default `0700` 403s Apache's
   `www-data` worker for an unrelated reason).

2. **`api/_admin-login/` is deliberately ADMIN-only** — rejects any
   non-ADMIN account. Intentional. Any new role needing its own login
   (like DRIVER) needs its OWN dedicated login page
   (`api/_driver-uat/login.php` is the precedent).

3. **Local test harness docroot ambiguity.** `php -S -t api/` makes the
   `api/` folder itself the docroot (paths like `/_driver-uat/...` with NO
   `/api/` prefix). The REAL deployment's docroot is ONE LEVEL ABOVE `api/`
   , so every real URL needs the `/api/` prefix. Always double check which
   convention a script uses before copying a URL path from it.

4. **`.htaccess` real-directory bypass.** The root `api/.htaccess` uses a
   `-f [OR] -d` RewriteCond pattern so real subdirectories (UAT wizard
   folders) are served directly instead of being swallowed by the
   front-controller rewrite. Every new UAT-tool folder needs its own
   `.htaccess` with `Options -Indexes` at minimum.

5. **cPanel package validation methodology**: never test the source tree
   directly. EXTRACT the actual built `.zip` into a disposable directory,
   write `config.php` directly into the EXTRACTED tree, and run a live
   end-to-end smoke test against THOSE files — both via `php -S` AND real
   Apache where relevant (gotcha #1). Every `build-cpanel-package-*.sh`
   script also runs its own in-process sanity checks (`php -l` every file,
   confirm no `config.php`/no unexpected migration/deny-all still intact,
   confirm specific unrelated business-logic files are byte-identical to
   the repo) — REFUSING to zip if any check fails. Always add file-path
   sanity checks by actually looking the file up first (`find`/`grep`) —
   two of THIS session's own new build scripts initially referenced a
   wrong path (`api/app/src/Po/PoImporter.php` — the real path is
   `api/app/src/Import/PoImporter.php`) and failed loudly at build time,
   which is the system working as intended, but slower than getting it
   right first.

6. **Manual browser/Playwright QA still matters, and is now easier to
   automate end-to-end** — see §7.10 for the exact recipe used this
   session to get a REAL browser (not just code review) verifying a UX
   fix. Chromium binaries are pre-installed under `/opt/pw-browsers/` —
   check that directory for the exact versioned subdirectory names before
   hardcoding a path (they change).

7. **Dependency-free QR encoder** (`api/app/src/Ui/QrEncoder.php`) was
   validated by installing Python's `qrcode`, `pyzbar`+`libzbar0t64`, and
   `reedsolo` in the sandbox and cross-checking byte-for-byte. Re-validate
   the same way if this file is ever modified.

8. **`Setup/AdminCreator.php` and `api/_setup/` are NOT the general
   user-management path.** Narrow, one-shot "create/reset the very first
   ADMIN" tools only. Use `Users\UserService` for ongoing account work.

9. **`production_run` identity is `(tanggal, divisionId)` — a real trap
   when writing test fixtures (or any future feature) that puts TWO
   products from the SAME division into one scenario.** Calling the
   production create→patch→submit sequence twice for the same
   (date, division) doesn't create two runs — the second `create` call
   returns the ALREADY-SUBMITTED run, and a subsequent `PATCH` on it fails
   with 409 `INVALID_STATUS`. This was hit twice while writing this
   session's own new `Phase55DispatchReceiptTest.php` tests (DPT-08/09,
   DPT-19) — the fix each time was using the test file's existing
   `$secondDivId`/`nextProductFromSecondDivision()` fixture (a second,
   different division at the same factory) for the second product,
   exactly the same pattern the pre-existing P55-16/17 tests already used.
   **Two products, same date, need either the same division in ONE
   create/patch/submit call, or two genuinely different divisions.**

10. **Recipe for a real (not just code-reviewed) browser check against a
    disposable local server + a running Playwright/Chromium instance**,
    used this session to verify the Driver Portal confirm-modal fix
    end-to-end:
    - Bootstrap a disposable MariaDB + apply migrations + seed + bootstrap
      master data exactly like any `run-*.sh` orchestrator (copy the
      pattern from `run-phase55-dispatch-receipt.sh` steps 1-7).
    - **Start `php -S` WITH `api/tests/_ui_router.php`** as the router
      script argument (`php -S 127.0.0.1:PORT -t "$API_ROOT"
      "$API_ROOT/tests/_ui_router.php"`) — without it, every
      `/api/assets/...` request 404s (see gotcha #3's docroot mismatch;
      the router rewrites that one specific path pattern to the real file
      on disk). Forgetting this router makes the whole page load with the
      JS/CSS silently missing, which looks like "the button never
      appears" and is easy to misdiagnose as a data/fixture problem
      instead of a static-asset routing problem.
    - Seed whatever fixture data is needed via plain curl-style PHP (mirror
      the test file's own `Http55`-style client + `idem()` helper — a
      small standalone PHP script using `curl_init`/cookie jars is enough,
      no framework needed) rather than trying to drive the UI for setup.
    - Playwright's `chromium.launch()` needs an explicit
      `executablePath` — use the `chromium_headless_shell-*` binary under
      `/opt/pw-browsers/` (`.../chrome-linux/headless_shell`), NOT the
      plain `chromium-*/chrome-linux/chrome` binary run with
      `headless: true` (that one hits "Old Headless mode has been removed"
      and the launch silently fails downstream). `args: ['--no-sandbox']`
      is also needed in this container.
    - To simulate a genuinely rapid multi-tap (testing a stacking bug),
      use `page.$eval('#selector', el => { el.click(); el.click();
      el.click(); })` — Playwright's own `.click()` auto-wait/
      actionability checks can prevent a true "3 clicks before any visual
      feedback" scenario from landing all 3.
    - Playwright's own npm package may not be in the project's
      `node_modules` — check `npm root -g` and set `NODE_PATH` to that
      global path when running the script if so (`playwright` was
      pre-installed globally in this container, at
      `/opt/node22/lib/node_modules/playwright`).

## 8. What is explicitly NOT built yet (respect these boundaries)

- **Phase 6 — Invoice business logic/generation.** Only the print/preview
  UI exists (`api/app/ui/print-invoice-template.php` + a mock fixture) —
  there is NO real invoice creation, no invoiceable-quantity computation,
  no linkage from confirmed shipments/receipts to an actual invoice
  record. Phase 5.5's receipt confirmation records reject/shortage qty
  specifically so a future Phase 6 can compute `invoiceable_qty`, but that
  computation does not exist yet.
- **Piutang (receivables) / Payment.** Schema tables were pre-declared in
  migration 0001 but no business logic exists.
- **Phase 7 — physical Retur/Reject full lifecycle.** Not implemented.
- **GPS tracking, route optimization, driver payroll, Google Maps
  integration.** Explicitly out of scope per direct user instruction —
  do not build these even if it seems like a natural extension of the
  driver route feature.
- **Email invitations, self-service forgot-password, SSO, full HR
  employee profiles.** Explicitly excluded from the User Management
  module's scope per direct user instruction.
- **Push notifications, exportable receipt history, route maps** on the
  Driver Portal — explicitly named as out of scope for the Driver UX
  patch (§5.2).

## 9. How to resume work in a new session

1. Confirm repo access to `fitriiisatya-rgb/Factory` is available (use
   `add_repo` tool if not already attached).
2. `git fetch origin claude/amor-factory-pricing-ui-final-6vv3q9 && git
   checkout claude/amor-factory-pricing-ui-final-6vv3q9` (or clone fresh —
   check whether the container already has a working copy at
   `/home/user/Factory`).
3. Run `git log --oneline -10` and `git status` to confirm this handoff
   doc (§2) is still accurate — if not, read whatever commits came after
   `dc09a61` before doing anything else.
4. Re-run the latest regression to build confidence in the new environment
   before changing anything:
   ```
   bash api/tests/run-user-mgmt.sh
   ```
   This cascades through the ENTIRE regression chain (User Mgmt → Phase
   5.5 [now incl. DPT-05/07..19] → Phase 0-5 → UI/Print/Invoice) and
   should print all-green. Separately also run
   `bash api/tests/run-phase4-fg-packing.sh` (now incl. FG-R01..11) since
   it isn't cascaded into the User Mgmt chain. If either doesn't come back
   green, something changed — investigate before proceeding with new work.
5. If the user reports something broken specifically on the REAL server
   (not locally), suspect a `.htaccess`-dependent issue FIRST (gotcha #1).
   If they report a broken-looking DIALOG/MODAL or other clearly visual
   JS/CSS issue, suspect a missing-CSS-for-shared-JS-component issue
   SECOND (§4 rule 11/13, §5.2) — check whether the page's own CSS bundle
   actually defines the classes the shared JS component needs, and
   consider a real Playwright browser check (§7.10), not just re-running
   the existing `php -S`-based API test suites (which test data/logic
   correctness, not rendered appearance).
6. If the user wants a fresh, comprehensive version of this handoff file
   resent (they've asked for this more than once, in Indonesian — "update
   dan kirim lagi handoff" or similar), do a **full rewrite** (not another
   stacked addendum section) that folds every change since the last
   version directly into the relevant sections (phase table, architecture
   rules, directory map, gotchas), the same way this version folded in the
   FG Source Refresh and Driver UX patches. Then commit, push, and send
   the file directly via SendUserFile as well, so it's available even
   before the new session/account re-clones the repo.
7. Wait for the user's next instruction. If they ask to continue "the next
   phase," it is very likely **Phase 6 (Invoice business logic)** given the
   sequencing so far, but confirm scope with them first rather than
   assuming — Phase 6 was explicitly deferred by the user earlier and no
   message has yet greenlit starting it. The user's pattern so far has
   been to send further **real-UAT bug/UX reports** on already-shipped
   phases before greenlighting a new phase — treat a new detailed report
   in the same highly-structured format (§1) as the most likely next
   message, not necessarily "start Phase 6."
8. Read the delivered README files under `dist/` if you need to understand
   exactly what was told to the client for any given phase — they are the
   ground truth for what the client believes is deployed/working.

## 10. Git commit attribution note

Commits in this project end with a `Co-Authored-By: Claude ... <noreply@
anthropic.com>` and a `Claude-Session: https://claude.ai/code/session_...`
line. **A new session has its OWN session URL**, supplied via its own
system reminder at the start of that session — use whatever that new
session's reminder specifies for any NEW commits, never reuse a URL you see
in past commit history (those refer to whichever session made that
specific commit, not to you).

---

**If anything in this document conflicts with what you observe in the
actual repo state, trust the repo — this document describes intent and
history, but `git log`/`git status`/the actual files are ground truth.**
