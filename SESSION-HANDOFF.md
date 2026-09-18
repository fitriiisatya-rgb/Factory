# SESSION HANDOFF — Amor Factory System

**Purpose of this file:** this is a continuity document for whichever Claude
Code session picks up this project next (possibly on a different account,
after the previous session ran out of budget). Read this file FIRST, in
full, before doing anything else. It is written to let a fresh session with
zero prior context resume exactly where the last one stopped, without
re-deriving decisions that have already been made and validated.

Written: 2026-09-18. Last commit at time of writing: `ac07251`.

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
- **Deployment target**: cPanel shared hosting, docroot
  `public_html/factory/`, domain `factory.amorgroup.id`. Absolutely no
  Composer/vendor directory, no SSH/CLI access assumed for the client, no
  ability to run `composer install` on the server.
- **Language/tone for user-facing text**: Bahasa Indonesia, non-technical,
  for every UI label, error message, and README aimed at the client. Code
  comments and internal docs are in English.

## 2. Current exact state (verify before trusting anything below)

```
git log --oneline -5
ac07251 Fix Phase 5.5: multi-factory departure claim->shipment mapping
cc9d875 Add Phase 5.5: Dispatch Pool / Driver Claim / Store Receipt Confirmation
90c33c1 Add Invoice print/preview template (UI only) and Amor logo to DO/Invoice print
c24ca2c Redesign Print DO / Surat Jalan per mockup (read-only, no logic change)
ddf6894 Add redesigned dark-mode admin UI (preview) on top of Phase 1-5 APIs
```

`git status` is clean (nothing uncommitted) as of this writing. Both
`ac07251` and `cc9d875` are already **pushed** to
`origin/claude/amor-factory-pricing-ui-final-6vv3q9`.

**First thing to do in the new session**: run `git log --oneline -10` and
`git status` yourself to confirm this is still accurate — do not assume
nothing changed between sessions.

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
| 4 | FG & Packing — verifies Production output into finished-goods stock, posts `stock_ledger` (`production_in`) | 0005 | `FgTargetService`, `FgService`, `api/_fg-uat/` |
| 5 | Delivery Order (Draft DO) + Shipment — one DO per (store, date); shipment commit deducts stock (`shipment_out`) via `ShipmentService::ship()`, the SINGLE place stock ever decreases for outbound goods | 0006 | `DoService`, `ShipmentService`, `api/_do-uat/` |
| — | Redesigned admin UI shell (dark mode, shared layout/CSS/JS) sitting on top of Phase 0-5 APIs, read/write via the same JSON API, no parallel business logic | — | `api/_ui-preview/`, `api/app/ui/*` |
| — | Print DO / Surat Jalan redesign (A4, watermark, signature grid) — presentation only | — | `api/app/ui/print-template.php` |
| — | Invoice print/preview template — **UI/print layout ONLY, no invoice generation business logic** | — | `api/app/ui/print-invoice-template.php`, mock fixture only |
| **5.5** | **Dispatch Pool / Driver Claim / Store Receipt Confirmation** — sits between Phase 5 (DO/Shipment) and the not-yet-built Phase 6 (Invoice). Drivers claim delivery tasks from an open pool, build a manual route, confirm departure (the ONLY point stock decreases here too — reuses `ShipmentService::ship()`, never duplicates stock logic), stores scan a QR to confirm receipt (good/reject/shortage qty). Admin verifies discrepancies. | **0007** | `DispatchService`, `DepartureService`, `ReceiptService`, `api/_driver-uat/`, `api/_receive/` |

**Phase 5.5 is the most recently completed phase**, including a follow-up
bug fix (see §6). **Phase 6 (Invoice business logic/generation) has NOT
been started** — only its print/preview UI exists, with mock data, no real
transactional logic. Do not start Phase 6 business logic without explicit
user instruction — an earlier user message explicitly said "DO NOT start
Phase 6 Invoice yet" in the context of sequencing Phase 5.5 first; that
blocker is presumably lifted now that 5.5 is done, but confirm with the
user before beginning Phase 6, since no one has explicitly greenlit it yet.

## 4. Architecture & conventions — READ THIS before writing any code

These are load-bearing conventions established and validated across all six
phases. Breaking them will look like it works locally and then fail a
regression test or the real cPanel package validation.

1. **No Composer, no `vendor/`, ever.** Anything that looks like it needs a
   third-party library must be hand-written. Precedents: `XlsxReader.php`
   (reads real `.xlsx` files byte-by-byte), `Ui/QrEncoder.php` (a full
   ISO/IEC 18004 QR encoder written from scratch, GF(256) Reed-Solomon
   included, validated against independent Python tools — see §7). This is
   because the target server has no shell/Composer access.

2. **Every mutating endpoint requires an `Idempotency-Key` header.**
   `Idempotency::handle($request, $endpoint, $work)` wraps the whole
   `$work` callback in ONE `Database::transaction()`. Same key + same
   request fingerprint → replay the stored response verbatim (never
   re-executes). Same key + different fingerprint → 409
   `IDEMPOTENCY_KEY_REUSE_MISMATCH`. If `$work` throws, the transaction
   rolls back and nothing is recorded (a retry is free to re-evaluate).

3. **Optimistic concurrency via `expectedVersion`.** Every mutable
   aggregate (`production_run`, `fg_batch`, `delivery_order`, dispatch
   claims) has a `version` column. Every mutation call takes
   `expectedVersion`, checks it under a row lock, bumps it by exactly 1 on
   success, throws 409 `VERSION_CONFLICT` otherwise. When one logical
   operation calls multiple version-bumping sub-operations in sequence
   inside the same transaction (e.g. `DepartureService` calling `ship()`
   once per factory group), the next `expectedVersion` is computed locally
   as `previous + 1` — never re-queried — because both calls share the same
   open transaction and each successful call is guaranteed to bump by
   exactly 1.

4. **Row locking discipline.** Lock the PARENT aggregate row (`SELECT ...
   FOR UPDATE`) before reading/mutating anything under it. When one
   operation must lock multiple parent rows (e.g. claiming across several
   DOs at once), always lock them in a **fixed, ascending ID order** across
   every caller, to prevent lock-order deadlocks.

5. **CSRF via `X-CSRF-Token` header** on session-authenticated
   state-changing requests. Public unauthenticated endpoints (like the
   store receipt confirmation) are exempted in `App.php` — exact-path
   exemptions live in a `CSRF_EXEMPT` array, but a route with dynamic path
   segments (like `/api/receive/{token}/...`) needs a **prefix check**
   instead (see `$isPublicReceiptConfirm` in `App.php`), since the exact
   array can't match dynamic segments.

6. **Audit logging** on every meaningful mutation via
   `Audit::write($pdo, $requestId, $userId, $eventType, $entityType,
   $entityId, 'ok'/'error', $fromVersion, $toVersion, $details)`.

7. **Migrations are additive-only.** Never alter or drop an existing
   column without a very strong reason. Migration 0001 already
   pre-declared tables for invoice/payment/return_note/reject_note/
   retail_sale etc. for future phases, even though they're unused today.
   Before adding a new migration, **audit the existing schema first** and
   document in the migration file's own docblock exactly why each new
   table/column is needed — do not add a table "just in case." Example:
   Phase 5.5 concluded `delivery_order_item` already IS the claimable pool
   line, so no separate `dispatch_task` table was created.

8. **Business-identity keys, not surrogate assumptions.** DO identity =
   `(tanggal, storeId)` — one DO per store per date, idempotent create.
   `production_run` identity = `(tanggal, divisionId)`. `fg_batch` identity
   = `(tanggal, factoryId)`. A receipt token identity = one per DO
   (`delivery_receipt_token`, unique per `delivery_order_id`). Always
   check for an existing row on that identity before inserting a new one —
   every phase's create-flow is idempotent for this reason.

9. **Stock ledger has ONE authority.** `stock_ledger` only ever gets a
   `shipment_out` row from inside `Delivery\ShipmentService::ship()` — no
   other code path is allowed to write a stock deduction. When a new
   feature needs to trigger a shipment (like Phase 5.5's driver departure),
   it must call the EXISTING `ship()`, never reimplement stock deduction.
   This has been the single most repeated instruction across every phase
   from Phase 5 onward, and the multi-factory bug just fixed (§6) was a
   bug in the *attribution* of an already-correct `ship()` call, not in
   stock math itself.

10. **Never use a "last value wins" pattern when an operation splits into
    multiple sub-calls that must each be attributed back to different
    inputs.** This is exactly what caused the Phase 5.5 multi-factory bug
    (§6) — group inputs by their real key (factory), call the sub-operation
    once per group, and map EACH group's result back only to the inputs
    that were actually in that group. Watch for this shape elsewhere if
    extending Phase 5.5 or building Phase 6 invoice generation (which will
    likely also need to group shipments/receipts by store or period).

## 5. Directory map

```
api/
  app/
    src/
      Import/          Phase 1 katalog/division import (Phase1Importer)
      PoImport/... (namespaced under Amor\Api\Po or similar) Phase 2 PO
      Production/       Phase 3
      Fg/               Phase 4
      Delivery/         Phase 5 — DoService, DoRepository, ShipmentService
      Dispatch/         Phase 5.5 — DispatchService/Repository,
                        DepartureService, ReceiptService/Repository
      Controllers/      One controller per phase's API surface
      Ui/               QrEncoder.php (dependency-free QR)
      Setup/            Seeder.php (roles/factories/synthetic store)
      App.php           Router + CSRF exemption list
      Auth.php, Audit.php, Idempotency.php, Database.php, Config.php
    migrations/         0001_..php through 0007_..php (dual-location
                        pointer pattern — see any existing one)
    ui/
      bootstrap.php     Shared session/CSRF bootstrap for the admin UI
      layout.php        ui_page_head/ui_page_foot, sidebar nav items
      pages/            One file per admin UI page (produksi.php,
                        pengiriman.php, konfirmasi-toko.php, etc.)
      print-template.php, print-invoice-template.php
      assets/css/*, assets/js/app.js + per-portal JS (driver.js, receipt.js)
  _import-po/, _production-uat/, _fg-uat/, _do-uat/, _ui-preview/
                        Manual UAT wizards per phase — ADMIN-authenticated,
                        NEVER wired into a single production nav, kept
                        side by side for manual verification
  _driver-uat/          Phase 5.5 mobile driver portal — has ITS OWN
                        login.php (see §7, gotcha #2), DRIVER-or-ADMIN role
  _receive/             Phase 5.5 PUBLIC store receipt portal — no
                        session/login, resolves identity only via the
                        secure per-DO token in the URL
  _admin-login/         ADMIN-ONLY login (deliberately rejects any
                        non-ADMIN account — this is intentional, do not
                        "fix" it; drivers get their own login page instead)
  _upgrade/             Web-based migration runner (no SSH/CLI needed)
  tests/
    Phase0Test.php, Phase2POTest.php, Phase3Test.php, Phase4FgTest.php,
    Phase5DoShipmentTest.php, PhasePrintTest.php, PhaseInvoiceUiTest.php,
    Phase55DispatchReceiptTest.php   <- most recent, P55-01..23 + P55-MF01/02
    run-phaseX.sh / run-*.sh          <- one orchestrator per suite, each
                                         cascades into the previous phase's
                                         orchestrator for full regression
    _ui_router.php, _phase2_bootstrap_master.php  <- shared test harness helpers
database/
  schema-v1.sql                      Canonical full schema (mirrors 0001)
  schema-v1-0003-... .sql through schema-v1-0007-...sql   Per-migration DDL mirrors
dist/
  build-cpanel-package-*.sh          One per phase's deliverable ZIP
  validate-*-package.sh              Extracts the REAL zip and smoke-tests it
  README-FIRST-CPANEL-*.md           Non-technical Indonesian deployment guide
  *.zip                              The actual deliverables sent to the user
```

## 6. Most recent work — Phase 5.5 multi-factory bug fix

The user reported a suspected bug after Phase 5.5 was delivered: when a
driver's Confirm Departure spans two factories (an existing, intentional
Phase 5 capability — one store's DO can legitimately contain items from two
factories, e.g. Karangtengah + Cibadak), `DepartureService` correctly
called `ShipmentService::ship()` once per factory (creating two real,
correctly-separated shipments), but then resolved **every** claim in that
departure using a single shared `$lastShipmentId` — the shipment from
whichever factory group's `ship()` call happened to run last. Claims from
the earlier factory group were being stamped with the WRONG shipment's ID
in `dispatch_claim.shipment_id`.

**Audit confirmed this was real** (not a false alarm). Root cause:
`$lastShipmentId = end($shipments)['shipmentId']` computed once after the
per-factory loop, applied to all claims. **Fix**: track claim IDs alongside
their factory group when building each `ship()` batch, then after each
`ship()` call returns, attribute that call's shipment ID only to the claims
that were actually in that group (`$shipmentIdByClaimId`, keyed by
claimId). A claim that shipped nothing (fully released) now correctly gets
`shipment_id = null` instead of an incorrect value.

**No schema change was needed** — `dispatch_claim.shipment_id` was already
correctly modeled as a single nullable FK, since a claim's product always
maps to exactly one factory and therefore exactly one shipment per
departure.

**Transaction/rollback safety was already correct**, verified not just
assumed: `Idempotency::handle` wraps the whole `confirmDeparture()` call in
one `Database::transaction()`, and `ShipmentService::ship()` never opens
its own nested transaction — so if the second factory group's `ship()`
call fails, PDO rolls back the first group's already-written
shipment/shipment_item/stock_ledger rows too, in the same request. Added
`P55-MF02` to lock this in as a regression test (forces the second factory
group to fail on insufficient FG, asserts zero new shipments/ledger rows
and both claims left `active`/unresolved/recoverable).

**Files touched**: `api/app/src/Dispatch/DepartureService.php` (the fix),
`api/tests/Phase55DispatchReceiptTest.php` (added `P55-MF01`/`P55-MF02` +
Cibadak/Bolu cross-factory test fixtures), `api/tests/run-phase55-dispatch-receipt.sh`
(label update), `dist/amor-factory-api-phase55-dispatch-receipt-easy.zip`
(rebuilt and re-validated).

**Result**: 21/21 Phase 5.5 tests pass, full Phase 0-5 + UI + Print +
Invoice regression pass, package re-validated end-to-end against the
shipped ZIP. Committed as `ac07251`, pushed. This was reported to the user
with closing wording: "PHASE 5.5 MULTI-FACTORY CLAIM → SHIPMENT MAPPING
VERIFIED / FIXED / READY FOR REAL CPANEL UAT / NOT production ready."

## 7. Known gotchas — things that will bite you if you don't know them

1. **Local test harness docroot ambiguity.** `php -S -t api/` makes the
   `api/` folder itself the docroot. An ABSOLUTE path like
   `/api/_admin-login/` resolves to a nonexistent nested
   `api/api/_admin-login/` and 404s — this is CORRECT/harmless in the REAL
   cPanel deployment (docroot=`public_html/factory/`, `api/` is a real
   subdirectory) but breaks locally. **Mitigation for any NEW same-portal
   navigation**: always use RELATIVE paths (`index.php?tab=...`,
   `stop.php?...`) instead of absolute `/api/...` paths — this works
   correctly in BOTH environments unconditionally.

2. **`api/_admin-login/` is deliberately ADMIN-only** — it explicitly
   rejects any non-ADMIN account. This is intentional, pre-existing
   behavior from Phase 1, not a bug. Any new role that needs its own login
   (like DRIVER in Phase 5.5) needs its OWN dedicated login page
   (`api/_driver-uat/login.php` is the precedent to copy from) — do not
   try to loosen `_admin-login/`'s role check.

3. **`.htaccess` real-directory bypass.** The root `api/.htaccess` uses a
   `-f [OR] -d` RewriteCond pattern so that real subdirectories (the UAT
   wizard folders) are served directly instead of being swallowed by the
   front-controller rewrite. Every new UAT-tool folder needs its own
   `.htaccess` with `Options -Indexes` at minimum. When rebuilding a cPanel
   package, there's always a sanity check confirming this bypass pattern
   survived — don't remove it.

4. **cPanel package validation methodology** (do this for every future
   package, it has caught real bugs before): never test the source tree
   directly. EXTRACT the actual built `.zip` into a disposable directory,
   apply migrations via the repo's own `migrate.php` (simulating "already
   installed, now upgrading"), write `config.php` directly into the
   EXTRACTED tree, start `php -S` rooted at the extracted `api/` directory,
   and run a live end-to-end smoke test against THOSE files. This caught a
   missing `require_once` for `ui_icon()` in an earlier phase that every
   automated JSON-API-only test had missed.

5. **Manual browser/Playwright QA still matters.** Automated API tests
   only exercise the JSON endpoints; they never render a full page in a
   real browser and never test an unauthenticated redirect flow. The
   entire Phase 5.5 driver-login gap (gotcha #2) was found only by manually
   screenshotting the driver portal with Playwright, not by any automated
   test. For any new user-facing portal, do a manual click-through / take
   screenshots before declaring it done.

6. **Chromium is pre-installed** at
   `/opt/pw-browsers/chromium-1194/chrome-linux/chrome` (or similar
   versioned path — check `/opt/pw-browsers/`) for Playwright screenshots;
   do not run `playwright install`.

7. **Dependency-free QR encoder** (`api/app/src/Ui/QrEncoder.php`) was
   validated by installing Python's `qrcode`, `pyzbar`+`libzbar0t64`, and
   `reedsolo` in the sandbox and cross-checking byte-for-byte. If this file
   ever needs modification, re-validate the same way — a subtly wrong QR
   encoder produces something that LOOKS like a QR code but doesn't scan,
   and that's very hard to catch by visual inspection alone.

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

## 9. How to resume work in a new session

1. Confirm repo access to `fitriiisatya-rgb/Factory` is available (use
   `add_repo` tool if not already attached).
2. `git fetch origin claude/amor-factory-pricing-ui-final-6vv3q9 && git
   checkout claude/amor-factory-pricing-ui-final-6vv3q9` (or clone fresh —
   check whether the container already has a working copy at
   `/home/user/Factory`).
3. Run `git log --oneline -10` and `git status` to confirm this handoff
   doc is still accurate.
4. Re-run the latest regression to build confidence in the new environment
   before changing anything:
   ```
   bash api/tests/run-phase55-dispatch-receipt.sh
   ```
   This cascades through the ENTIRE regression chain (Phase 0 → 5.5) and
   should print all-green. If it doesn't, something changed — investigate
   before proceeding with new work.
5. Wait for the user's next instruction. If they ask to continue "the next
   phase," it is very likely **Phase 6 (Invoice business logic)** given the
   sequencing so far, but confirm scope with them first rather than
   assuming — Phase 6 was explicitly deferred by the user earlier and no
   message has yet greenlit starting it.
6. Read the delivered README files under `dist/` if you need to understand
   exactly what was told to the client for any given phase — they are the
   ground truth for what the client believes is deployed/working.

## 10. Git commit attribution note

This session's commits ended with:
```
Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FpTwbDfjF6rYrnbhu5212B
```
A new session will have its OWN session URL supplied via its own system
reminder — use whatever that new session's reminder specifies, do not
reuse the URL above (it refers to the session that is ending, not the new
one).

---

**If anything in this document conflicts with what you observe in the
actual repo state, trust the repo — this document describes intent and
history, but `git log`/`git status`/the actual files are ground truth.**
