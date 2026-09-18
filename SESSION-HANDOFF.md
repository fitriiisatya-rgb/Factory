# SESSION HANDOFF — Amor Factory System

**Purpose of this file:** this is a continuity document for whichever Claude
Code session/account picks up this project next. Read this file FIRST, in
full, before doing anything else. It is written to let a fresh session with
zero prior context resume exactly where the last one stopped, without
re-deriving decisions that have already been made and validated. This is a
full rewrite (not an incremental patch) — it supersedes any earlier version
of this file you may find in git history.

Written: 2026-09-18. Last commit at time of writing: `0930baf` (see the
addendum immediately below for what changed since `a183518`, which is what
this file's body still describes in detail — that content is still
accurate, just missing the one module added after it was written).

---

## 0. ADDENDUM (2026-09-18, later same day) — ADMIN User / Driver Account Management added

A new module was added on top of everything below: `api/_users-uat/`, an
ADMIN-only page (standalone, same side-by-side UAT convention as
`_driver-uat/`/`_do-uat/`, NOT wired into `layout.php`'s sidebar) that lets
an admin create/edit/deactivate/reactivate/reset-password/change-roles for
any user — the missing piece that was blocking real Phase 5.5 UAT (it
needs at least 2 DRIVER accounts, and there was no way to create them
without phpMyAdmin/manual SQL).

**No migration was needed** — `users.active`, the `username` UNIQUE
constraint, `password_hash`, and the `roles`/`user_roles` M:N junction all
already existed since migration 0001 and already fully supported this.
New code: `api/app/src/Users/{UserRepository,UserService}.php`,
`api/app/src/Controllers/UserController.php` (+ `/api/users/*` routes in
`App.php`), `api/_users-uat/index.php`, `api/assets/js/users.js`. A
"never zero active ADMIN users" safeguard blocks deactivating or
de-roling the last active admin (409). `PhaseUserMgmtTest.php` (UM-01..16,
including real end-to-end login checks against the actual
`/_driver-uat/login.php` and `/_admin-login/` HTML pages, not just the
JSON API) + full Phase 0-5.5 regression (UM-17) all pass. Also validated
with real Apache (`dist/validate-user-management-apache.sh`), applying the
§7.1 lesson: the new `users.js` correctly lives under `api/assets/js/`,
never under the deny-all `api/app/`.

Committed as `0930baf`, pushed. Package:
`dist/amor-factory-api-user-management-easy.zip` (files-only, no
migration). If the user's next ask is "create Driver A / Driver B" or
"resume Phase 5.5 UAT," this module is how — walk them to
`api/_users-uat/` (or use `dist/README-FIRST-CPANEL-USER-MANAGEMENT.md`'s
own step-by-step).

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
  file's existence), and does real cPanel UAT personally — meaning bugs
  that only manifest on a real Apache server (not `php -S`) are a real,
  recurring risk class for this project (see §7 gotcha #1, the most
  important one).

## 2. Current exact state (verify before trusting anything below)

```
git log --oneline -8
a183518 Update session handoff with the real-Apache asset bug and fix
bb3a5a9 Fix Phase 5.5 real-cPanel deployment bug: public assets under deny-all api/app/
a1fce14 Add session handoff document for continuity across account switches
ac07251 Fix Phase 5.5: multi-factory departure claim->shipment mapping
cc9d875 Add Phase 5.5: Dispatch Pool / Driver Claim / Store Receipt Confirmation
90c33c1 Add Invoice print/preview template (UI only) and Amor logo to DO/Invoice print
c24ca2c Redesign Print DO / Surat Jalan per mockup (read-only, no logic change)
ddf6894 Add redesigned dark-mode admin UI (preview) on top of Phase 1-5 APIs
```

`git status` is clean (nothing uncommitted) as of this writing. Everything
through `a183518` is already **pushed** to
`origin/claude/amor-factory-pricing-ui-final-6vv3q9`.

**First thing to do in a new session**: run `git log --oneline -10` and
`git status` yourself to confirm this is still accurate — do not assume
nothing changed between sessions. If the log has commits beyond `a183518`,
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
| 4 | FG & Packing — verifies Production output into finished-goods stock, posts `stock_ledger` (`production_in`) | 0005 | `FgTargetService`, `FgService`, `api/_fg-uat/` |
| 5 | Delivery Order (Draft DO) + Shipment — one DO per (store, date), can legitimately span two factories (see §4.9); shipment commit deducts stock (`shipment_out`) via `ShipmentService::ship()`, the SINGLE place stock ever decreases for outbound goods | 0006 | `DoService`, `ShipmentService`, `api/_do-uat/` |
| — | Redesigned admin UI shell (dark mode, shared layout/CSS/JS) sitting on top of Phase 0-5 APIs, read/write via the same JSON API, no parallel business logic | — | `api/_ui-preview/`, `api/app/ui/*` |
| — | Print DO / Surat Jalan redesign (A4, watermark, signature grid) — presentation only | — | `api/app/ui/print-template.php` |
| — | Invoice print/preview template — **UI/print layout ONLY, no invoice generation business logic** | — | `api/app/ui/print-invoice-template.php`, mock fixture only |
| **5.5** | **Dispatch Pool / Driver Claim / Store Receipt Confirmation** — sits between Phase 5 (DO/Shipment) and the not-yet-built Phase 6 (Invoice). Drivers claim delivery tasks from an open pool, build a manual route, confirm departure (the ONLY point stock decreases here too — reuses `ShipmentService::ship()`, never duplicates stock logic), stores scan a QR to confirm receipt (good/reject/shortage qty). Admin verifies discrepancies. | **0007** | `DispatchService`, `DepartureService`, `ReceiptService`, `api/_driver-uat/`, `api/_receive/` |

**Phase 5.5 is the most recently completed phase**, including TWO follow-up
bug fixes after initial delivery (see §5). **Phase 6 (Invoice business
logic/generation) has NOT been started** — only its print/preview UI
exists, with mock data, no real transactional logic. Do not start Phase 6
business logic without explicit user instruction — the user has previously
and explicitly said "DO NOT start Phase 6 Invoice yet" in the context of
sequencing Phase 5.5 first; that blocker is presumably lifted now that 5.5
is stable, but confirm scope with the user before beginning Phase 6, since
no one has explicitly greenlit it yet.

## 4. Architecture & conventions — READ THIS before writing any code

These are load-bearing conventions established and validated across every
phase. Breaking them will look like it works locally and then fail a
regression test, the cPanel package validation, or — worse, as happened
once already (see §5.2) — pass every automated check and still be broken
on the real server.

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
   A single DO/shipment CAN legitimately span two factories (e.g.
   Karangtengah + Cibadak) if the store's demand does — `ship()` itself
   refuses to mix factories in one call (`MIXED_FACTORY_SHIPMENT`), so any
   caller spanning factories must group inputs by factory and call `ship()`
   once per group (see gotcha #10 below for the bug this shape caused once).

10. **Never use a "last value wins" pattern when an operation splits into
    multiple sub-calls that must each be attributed back to different
    inputs.** This is exactly what caused the Phase 5.5 multi-factory bug
    (§5.1) — group inputs by their real key (factory), call the
    sub-operation once per group, and map EACH group's result back only to
    the inputs that were actually in that group. Watch for this shape
    elsewhere if extending Phase 5.5 or building Phase 6 invoice generation
    (which will likely also need to group shipments/receipts by store or
    period).

11. **Public (browser-reachable) assets live under `api/assets/`, NEVER
    under `api/app/`.** `api/app/.htaccess` is intentionally `Require all
    denied` — everything under `api/app/` (source, config, migrations, PHP
    templates) must never be directly web-reachable. Any CSS/JS/image a
    browser needs to load directly belongs in `api/assets/` (a sibling of
    `api/app/`, with its own `.htaccess` containing only `Options
    -Indexes`). This was violated once (see §5.2) and is now the single
    most important thing to get right for any new UI work — see §7.1.

## 5. Most recent work — two Phase 5.5 bug fixes after initial delivery

### 5.1 Multi-factory claim→shipment mapping (commit `ac07251`)

The user reported a suspected bug: when a driver's Confirm Departure spans
two factories (an existing, intentional Phase 5 capability), `DepartureService`
correctly called `ShipmentService::ship()` once per factory (creating two
real, correctly-separated shipments), but then resolved **every** claim in
that departure using a single shared `$lastShipmentId` — the shipment from
whichever factory group's `ship()` call happened to run last. Claims from
the earlier factory group were being stamped with the WRONG shipment's ID
in `dispatch_claim.shipment_id`.

**Audit confirmed this was real.** Root cause:
`$lastShipmentId = end($shipments)['shipmentId']` computed once after the
per-factory loop, applied to all claims. **Fix**: track claim IDs alongside
their factory group when building each `ship()` batch, then after each
`ship()` call returns, attribute that call's shipment ID only to the claims
that were actually in that group (`$shipmentIdByClaimId`, keyed by
claimId). No schema change was needed — `dispatch_claim.shipment_id` was
already correctly modeled as a single nullable FK.

Transaction/rollback safety was already correct (verified, not assumed):
`Idempotency::handle` wraps the whole `confirmDeparture()` call in one
`Database::transaction()`, and `ShipmentService::ship()` never opens its
own nested transaction — so if a later factory group's `ship()` call fails,
PDO rolls back every earlier group's writes too, in the same request.

Added `P55-MF01` (correct mapping across two factories) and `P55-MF02`
(forced second-factory-group failure → zero new shipments/ledger rows,
claims left recoverable) to `Phase55DispatchReceiptTest.php`. 21/21 Phase
5.5 tests + full regression passed. **Files touched**:
`api/app/src/Dispatch/DepartureService.php`, `Phase55DispatchReceiptTest.php`,
`run-phase55-dispatch-receipt.sh`, rebuilt ZIP.

### 5.2 Public assets blocked by Apache deny-all (commit `bb3a5a9`) — THE IMPORTANT ONE

The user did **real cPanel UAT** (actual Apache, not this project's own
`php -S`-based validation) and found the Driver portal loading as plain
unstyled HTML — broken logo, no CSS, JS possibly blocked too.

**Root cause**: every browser-facing CSS/JS/image asset (`tokens.css`,
`app.css`, `driver.css`, `receipt.css`, `print.css`, `print-invoice.css`,
`app.js`, `driver.js`, `receipt.js`, `amor-logo.png`) lived under
`api/app/ui/assets/` — INSIDE `api/app/`, which `api/app/.htaccess`
correctly denies all HTTP access to (source code/config must never be
web-reachable). On real Apache, every single asset request was silently
403'd. **This was invisible to every validation this project had done up
to that point, because `php -S` (used by every `run-*.sh` orchestrator and
every earlier `dist/validate-*.sh`) ignores `.htaccess` entirely** — it
served those files fine locally regardless of what any `.htaccess` said.

**Fix**: moved all 10 static assets to a new public `api/assets/`
directory (sibling of `api/app/`, outside the deny-all boundary, with its
own `.htaccess` containing only `Options -Indexes`). Updated every PHP
template that emits an asset URL — `layout.php` (shared by every admin
page), `print-template.php`, `print-invoice-template.php`, the driver
portal's `bootstrap.php`/`login.php`, the public receive portal — to
reference `/api/assets/...` instead of `/api/app/ui/assets/...`.
`api/app/.htaccess` itself is byte-for-byte UNCHANGED — still denies
everything under `api/app/`. Also fixed the shared local test-harness
router (`_ui_router.php`) and every affected orchestrator/build/validate
script.

**Critical new capability added**: since `php -S` cannot catch
`.htaccess`-dependent bugs, this session **installed a real Apache 2.4 +
PHP-FPM 8.3** in the sandbox (`apt-get install apache2 php8.3-fpm
php8.3-mysql` — plain Ubuntu archive, NOT the sury/ondrej PPA, which this
sandbox's egress proxy blocks with a 403) and wrote
`dist/validate-phase55-apache-assets.sh`: it extracts the shipped ZIP,
stands up a real vhost with `AllowOverride All` (matching real cPanel
default) against it, and asserts (28 checks, all passing):
- `api/app/*` (config.php, any `.php` source, migrations) → HTTP 403
- every `api/assets/*.css|js|png` → HTTP 200 with correct content-type
- Driver login, Driver portal, public Store Receipt portal, admin
  Konfirmasi Toko, DO print, and Invoice preview all render and reference
  **only** `/api/assets/...` — the exact URLs printed in their HTML were
  independently re-fetched and confirmed 200.

Two new build-time sanity gates were also added to
`dist/build-cpanel-package-phase55-dispatch-receipt-easy.sh`: refuse to
build if `api/app/.htaccess` isn't deny-all, or if any shipped PHP file
still references `/api/app/` for a browser asset.

**Files-only fix for the already-migrated real cPanel instance** (no new
migration needed — migration 0007 stays as already applied): re-upload/
extract the rebuilt ZIP, confirm `api/assets/` now exists with `css/`,
`js/`, `img/` subfolders, hard-refresh the browser. Full details in commit
`bb3a5a9`'s message and `git show bb3a5a9`.

**Result**: 21/21 Phase 5.5 tests + full regression + 28/28 real-Apache
checks all passed. Reported to the user with closing wording: "PHASE 5.5
PUBLIC ASSET / APACHE DEPLOYMENT BUG FIXED / FILES-ONLY PATCH — NO DATABASE
MIGRATION REQUIRED / READY TO RESUME REAL CPANEL UAT / NOT production
ready."

## 6. Directory map

```
api/
  assets/                PUBLIC static browser assets (css/js/img) — the
                          ONLY place browser-facing CSS/JS/images may live.
                          Own .htaccess: "Options -Indexes" only, no deny.
  app/                    api/app/.htaccess = "Require all denied" — NOTHING
                          in here is ever directly web-reachable. NEVER put
                          a browser-facing asset anywhere under here again.
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
    ui/                 Server-side PHP templates only (no static assets
                        here anymore — see api/assets/ above)
      bootstrap.php     Shared session/CSRF bootstrap for the admin UI
      layout.php        ui_page_head/ui_page_foot, sidebar nav items,
                        references /api/assets/... for CSS/JS
      pages/            One file per admin UI page (produksi.php,
                        pengiriman.php, konfirmasi-toko.php, etc.)
      print-template.php, print-invoice-template.php
  _import-po/, _production-uat/, _fg-uat/, _do-uat/, _ui-preview/
                        Manual UAT wizards per phase — ADMIN-authenticated,
                        NEVER wired into a single production nav, kept
                        side by side for manual verification
  _driver-uat/          Phase 5.5 mobile driver portal — has ITS OWN
                        login.php (see §7.2), DRIVER-or-ADMIN role
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
                                         orchestrator for full regression.
                                         ALL use php -S (see gotcha #1 for
                                         what that can't catch).
    _ui_router.php, _phase2_bootstrap_master.php  <- shared test harness helpers
database/
  schema-v1.sql                      Canonical full schema (mirrors 0001)
  schema-v1-0003-... .sql through schema-v1-0007-...sql   Per-migration DDL mirrors
dist/
  build-cpanel-package-*.sh          One per phase's deliverable ZIP
  validate-*-package.sh              Extracts the REAL zip, smoke-tests it
                                      via php -S (fast, but see gotcha #1)
  validate-phase55-apache-assets.sh  NEW: extracts the REAL zip, smoke-tests
                                      it via a REAL Apache+PHP-FPM vhost with
                                      AllowOverride All — the only check that
                                      actually enforces .htaccess. Run this
                                      for ANY change touching browser-facing
                                      pages or assets, not just php -S checks.
  README-FIRST-CPANEL-*.md           Non-technical Indonesian deployment guide
  *.zip                              The actual deliverables sent to the user
```

## 7. Known gotchas — things that will bite you if you don't know them

1. **`php -S` (used by every `run-*.sh` and older `dist/validate-*.sh`)
   IGNORES `.htaccess` ENTIRELY.** This is the single most important
   lesson from this project so far (see §5.2) — it means `.htaccess`-
   dependent behavior (deny-all directories, the front-controller rewrite
   bypass, per-directory `Options`) can pass every existing automated check
   and still be completely broken on the real server. **For any change
   that touches a browser-facing page, a new asset, or anything
   `.htaccess`-adjacent, also run (or extend)
   `dist/validate-phase55-apache-assets.sh`** — a real Apache 2.4 +
   PHP-FPM 8.3 validation. To install the prerequisites in a fresh
   sandbox: `apt-get install apache2 php8.3-fpm php8.3-mysql` — use the
   **plain Ubuntu archive version**, not the sury/ondrej PPA (blocked by
   this sandbox's egress proxy with a 403; if `apt-get update` shows PPA
   sources, temporarily move them out of `/etc/apt/sources.list.d/` before
   installing, then restore them). No systemd in this container — start
   both manually: `mkdir -p /run/php && chown www-data:www-data /run/php
   && /usr/sbin/php-fpm8.3 -D --fpm-config /etc/php/8.3/fpm/php-fpm.conf`
   and `apache2ctl start` (after adding a `Listen <port>` conf and a
   `<VirtualHost>` with `AllowOverride All` pointing at the extracted
   package — see the existing script for the exact pattern). **Gotcha
   within the gotcha**: `mktemp -d` creates directories `0700 root:root` —
   Apache's `www-data` worker can't even traverse into that, causing every
   request to 403 for a totally unrelated reason (permission denied, not
   `.htaccess` denial) that looks identical from the outside. Always
   `chmod 755` the temp workdir before pointing a vhost at it.

2. **`api/_admin-login/` is deliberately ADMIN-only** — it explicitly
   rejects any non-ADMIN account. This is intentional, pre-existing
   behavior from Phase 1, not a bug. Any new role that needs its own login
   (like DRIVER in Phase 5.5) needs its OWN dedicated login page
   (`api/_driver-uat/login.php` is the precedent to copy from) — do not
   try to loosen `_admin-login/`'s role check.

3. **Local test harness docroot ambiguity.** `php -S -t api/` makes the
   `api/` folder itself the docroot (paths like `/_driver-uat/...` with NO
   `/api/` prefix). The REAL deployment's docroot is ONE LEVEL ABOVE `api/`
   (`public_html/factory/`), so every real URL needs the `/api/` prefix
   (`/api/_driver-uat/...`). Mixing these up is an easy mistake when
   writing a new curl-based validation script — always double check which
   docroot convention the script you're extending uses before copying a
   URL path from it.

4. **`.htaccess` real-directory bypass.** The root `api/.htaccess` uses a
   `-f [OR] -d` RewriteCond pattern so that real subdirectories (the UAT
   wizard folders) are served directly instead of being swallowed by the
   front-controller rewrite. Every new UAT-tool folder needs its own
   `.htaccess` with `Options -Indexes` at minimum. When rebuilding a cPanel
   package, there's always a sanity check confirming this bypass pattern
   survived — don't remove it.

5. **cPanel package validation methodology** (do this for every future
   package, it has caught real bugs before): never test the source tree
   directly. EXTRACT the actual built `.zip` into a disposable directory,
   apply migrations via the repo's own `migrate.php` (simulating "already
   installed, now upgrading"), write `config.php` directly into the
   EXTRACTED tree, and run a live end-to-end smoke test against THOSE
   files — both via `php -S` (fast iteration) AND via real Apache (see
   gotcha #1, this is the one that actually matters for anything
   `.htaccess`-related).

6. **Manual browser/Playwright QA still matters.** Automated API tests
   only exercise the JSON endpoints; they never render a full page in a
   real browser and never test an unauthenticated redirect flow. The
   entire Phase 5.5 driver-login gap (gotcha #2) was found only by manually
   screenshotting the driver portal with Playwright, not by any automated
   test. Chromium is pre-installed at `/opt/pw-browsers/chromium-*/chrome-
   linux/chrome` (check `/opt/pw-browsers/` for the exact version) — do
   not run `playwright install`. For any new user-facing portal, do a
   manual click-through / take screenshots before declaring it done.

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
   doc (§2) is still accurate — if not, read whatever commits came after
   `a183518` before doing anything else.
4. Re-run the latest regression to build confidence in the new environment
   before changing anything:
   ```
   bash api/tests/run-phase55-dispatch-receipt.sh
   ```
   This cascades through the ENTIRE regression chain (Phase 0 → 5.5) and
   should print all-green. If it doesn't, something changed — investigate
   before proceeding with new work.
5. If the user reports something broken specifically on the REAL server
   (not locally), suspect a `.htaccess`-dependent issue FIRST (gotcha #1)
   and reach for `dist/validate-phase55-apache-assets.sh` (or extend it)
   rather than only re-running the `php -S`-based suites, which already
   passed once and will keep passing even if the real-server bug is still
   there.
6. Wait for the user's next instruction. If they ask to continue "the next
   phase," it is very likely **Phase 6 (Invoice business logic)** given the
   sequencing so far, but confirm scope with them first rather than
   assuming — Phase 6 was explicitly deferred by the user earlier and no
   message has yet greenlit starting it.
7. Read the delivered README files under `dist/` if you need to understand
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
