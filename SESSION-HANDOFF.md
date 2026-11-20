# SESSION HANDOFF — Amor Factory System

**Read this file first, in full.** It lets a fresh Claude Code session (or a
human) resume this project without re-deriving decisions already made.
Rewritten 2026-11-14, updated 2026-11-20 (§A10: legacy GAS lineage + AMORA 1.9 APK). It supersedes the 2026-09-21 version (still in git
history). **Part A** is the current state and what happened since September;
**Part B** keeps the long-lived conventions and gotchas from the earlier
handoff (they remain valid). If this file disagrees with the repo, trust the
repo (`git log`, `git status`, the files).

---

# PART A — Current state

## A1. What this is, and how to work with the user

**Amor Factory System**: PHP 8.3 + MariaDB rebuild of the internal ERP of CV.
Amor Group / Amor Cakes & Bakery (replaces a Google Apps Script + Sheets
system). Flow: PO toko → Produksi (per divisi) → serah terima → FG verifikasi
& packing → Delivery Order → Pengiriman → Konfirmasi penerimaan toko →
Invoice (plus Retur/Reject/Mutasi toko, Pesanan Khusus/Non-Toko, Stok Sisa).

- **Repo**: `fitriiisatya-rgb/Factory`. **Working branch**:
  `claude/gracious-heisenberg-m16irt` (all work goes here; the older
  `claude/amor-factory-pricing-ui-final-6vv3q9` named in the Sep handoff is
  no longer the active one; it and `po-revision-ks60px` are ancestors of the
  active branch). There is **no `master`/`main` branch on the remote**. The user once wrote "di master" and it was never resolved — ask
  which branch they mean before merging or pushing anywhere else.
- **Deploy**: cPanel shared hosting, docroot `public_html/factory`, zip root
  is `api/`, domain `factory.amorgroup.id`. No Composer, no SSH for the
  client. The user extracts a zip over the live folder; migrations run from
  the web UI `api/_upgrade/`. Most patches since September are files-only
  (no migration).
- **User style**: writes Indonesian, short messages, often screenshots. All
  user-facing text (UI, errors, guides) in Bahasa Indonesia, non-technical;
  code and internal docs in English. The user asks for "zip", "kirim ulang
  zip", "beresin dulu semua" — they expect a built, tested, dated zip sent
  via SendUserFile, and they confirm with "sudah extract". They also expect
  you to push back on weak ideas (user preference: critical, no empty
  validation, label confidence on uncertain facts).
- **Real-server bugs are a recurring class**: `php -S` ignores `.htaccess`
  (Part B gotcha #1). Cache is another: twice the user said "sudah extract
  tapi tidak berubah" — once the old `print.css` was still live (diagnosed by
  having them paste the file). Bump asset `?v=` versions when CSS/JS change
  (`ADMIN_ASSET_VERSION` in `api/app/ui/layout.php`, `print.css?v=` in print
  pages).

## A2. Repo / git state at time of writing

Last feature commit before this handoff: `1fd877a Division logins: remove
Ringkasan FG; menus are Produksi, Riwayat, Pengaturan only`. Branch pushed to
origin. `git log --oneline -15` to confirm. 245+ commits total.
Commit trailer for NEW commits: use whatever the new session's own system
reminder specifies (Co-Authored-By + Claude-Session URL); never reuse an old
session URL. Never put a model identifier in commit messages or code.

## A3. Accounts and roles (current model)

- **Leader Admin** = role ADMIN with **no** `user_factory_access` rows. Sees
  both factories and every Leader-only page (Master Data, Kelola User,
  Pengaturan switches, Laporan, import PO). The header factory pill is a
  switcher for Leader.
- **Admin Pabrik** (e.g. Admin Karangtengah = `admin_ktg`, Admin Cibadak =
  `admin_cbd`) = role ADMIN **plus exactly one factory row**. Everything is
  pinned to that factory server-side (`Auth::adminFactoryScope()`,
  `Auth::isFactoryAdmin()`, `Auth::requireLeaderAdmin()`,
  `Auth::requireAdminFactory()`, `AdminScope` helpers). Filters live in the
  services, not only controllers, because UI pages call services directly.
  Suite: `api/tests/AdminFactoryScopeTest.php` (AFS-01..15).
- **Divisi (7 shared logins, e.g. `divisi_bolu`)** = role PRODUCTION +
  division access. **They only submit production data** (and press "Serahkan
  ke FG"). Menus: **Produksi, Riwayat, Pengaturan**. They can NOT write FG:
  FG create/save/submit, special-order FG verify and replacement FG verify
  require ADMIN/PPIC/FG_PACKING (FgController, SpecialOrderController,
  ReplacementController). Layout allow-list: `ui_role_scoped_pages()` in
  `api/app/ui/layout.php`; page gate: `$pageRoles` in
  `api/_ui-preview/index.php`. Test: PDFG-01b. Consequence: a division can't
  see whether FG received its handover (only FG/Admin can) — the user chose
  this ("jangan sampai campur"); revisit only if they complain.
- **FG accounts** `fg_ktg` / `fg_cbd` = FG_PACKING with one factory: FG
  verifikasi + packing, FG Sumber Khusus.
- Driver (own portal `_driver-uat`), Toko (public QR portal `_receive`, plus
  store portal), Atasan (approval of Retur/Reject/Mutasi).
- Live users known: two Leader accounts (display names include "Admin1" and
  "Adminofc"). **The Admin1 password should be changed** (it was shared in
  chat earlier). Never print passwords/tokens.

## A4. What was built after the Sep-21 handoff (migrations 0008–0026)

Source: migration names + commit log (names are authoritative; the one-line
descriptions for older items are from commit messages — medium confidence on
fine detail). Open the migration or its dist README for specifics.

| Mig | Topic |
|---|---|
| 0008 | Store receipt photo evidence |
| 0009 | Shipment email to stores (outbox) |
| 0010 | Special (Khusus) / Non-Toko orders and their production routing |
| 0011–0015 | Production task per division, production flow completion (+0013 repair), division/FG rework, per-store packing submission |
| 0016 | Replacement Reject (reject on receipt → replacement DO) |
| 0017 | Permanent Bakery Portal (admin tokens for stores) |
| 0018 | **Real Invoice generation** (Phase 6 done): one invoice per DO per factory |
| 0019 | Shared division login + operator name |
| 0020 | Reject di Toko (Lapor Barang red/grey, Admin potong/ganti, invoice deduction) |
| 0021 | Nota Potongan |
| 0022 | finhub integration (admin-managed API keys, read-only shipment/receipt feed; see `api/INTEGRASI-FINHUB.md`) |
| 0023 | Invoice special lines |
| 0024 | Runchise "Impor Stok Masuk" CSV per surat jalan |
| 0025 | Production before PO, partial handover to FG (serah terima), FG stock position |
| 0026 | Retur/Reject/Mutasi toko per transaction with Atasan approval |

Other shipped things: Hari Ini checklist, daily DB backup + error log,
phone-friendly layouts for divisi/FG/driver/toko, Riwayat page, FG Ringkasan
dashboard (Admin/FG only now), Print DO per divisi, Stok Sisa (sell surplus
non-toko / kirim susulan), omset per pabrik and Non-Toko omset, tutorial PDFs
per role (+ videos, in `dist/tutorial/`), an Android WebView APK (`android/`,
signing key was rotated on 2026-10-29 after the first key was briefly
downloadable — **never commit or upload key files; `*.p12` is gitignored**).

## A5. Latest work (2026-10-29 → 2026-11-14) — "masa adaptasi" and cleanups

**Context**: the user wants a staged rollout. Weeks 1–2: the system runs from
production through invoice using **Admin only** (no drivers, no stores). From
about week 3 (date to be announced by the user) drivers and stores join.

1. **Admin per pabrik** (see A3), with tests and a regression.
2. **Admin fills receipts from the signed surat jalan** (Konfirmasi Toko):
   `ReceiptService::confirmReceiptByAdmin` / `confirmAllGoodByAdmin`, audit
   `via=admin_surat_jalan`, note prefixed "Diisi Admin dari surat jalan."
   Needs a receiver name. Pages: `konfirmasi-toko.php` (checkboxes + "Diterima
   semua sesuai SJ"), `konfirmasi-toko-detail.php` ("Isi dari SJ").
3. **Switches** (Pengaturan, Leader only; stored in `master_setting`):
   - `store_email_enabled` (missing = on). **Currently frozen on live**:
     emails to stores stay `pending` ("dibekukan"). Turn on at go-live.
   - `ship_before_fg` (missing = off). **Currently ON on live**: Admin may
     press Kirim without waiting for FG; FG still enters its own result;
     reconcile afterwards on **Rekonsiliasi Kirim vs FG**
     (`ReconciliationService::forDay`, page `rekonsiliasi-kirim.php`).
     Turn OFF at go-live so FG availability limits Kirim again.
4. **Pengiriman**: Qty Kirim is now **prefilled with Sisa DO** (capped by
   FG/store-ready when the switch is off); Admin edits only exceptions, 0 for
   products not loaded. Risk: Admin can confirm without checking; the
   safety nets are Rekonsiliasi and the Kurang column on receipt. The
   Konfirmasi KIRIM dialog text had a literal `&middot;` (modal body is
   plain text) — fixed with a real "·".
5. **QR per DO** stays active; it only works after Konfirmasi KIRIM creates a
   shipment ("Belum Dikirim" before that).
6. **Cetak**: surat jalan per shipment (blank driver line when Admin pressed
   Kirim, write-in columns Diterima Baik/Reject/Kurang + catatan toko) and a
   real "DELIVERY ORDER" print (`ui_render_do_print_document`) with Dikirim +
   write-in columns + signatures; "Sudah dikirim sebelumnya" note.
7. **Invoice**: one per DO per factory via Invoice → "Terbitkan semua"
   (`POST /api/invoices/generate-ready`). Ready only when every shipment of
   the DO+factory has a receipt `confirmed_ok` or `verified`
   (`confirmed_discrepancy` waits for Verifikasi Selisih). The Invoice page
   now shows how many DOs were sent but are waiting for receipt confirmation,
   linking to Konfirmasi Toko.
8. **Master Data** (Leader only): add/edit/delete on every tab (products incl.
   Harga column, stores, divisions, factories, store aliases), delete blocked
   with `MASTER_IN_USE` when referenced (hint: set Nonaktif), protected
   records (NON-OUTLET store, Cake & Custom and verification divisions), filters
   Kategori/Divisi on products. Explicit DELETE routes (a generic
   `/api/{entity}/{id}` would shadow others).
9. **Import PO** (`api/_import-po`): unresolved store rows block import
   (`UNRESOLVED_ROWS`); the wizard now has a store dropdown for "Petakan" and
   "Tambah sebagai toko baru". Store resolution order: `store_alias.raw_name`
   → UPPER(`canonical_name`) → `migration_store_map`.
10. UI: overlapping "Aksi" buttons fixed (`td.row-actions` in `app.css`);
    header factory switcher for Leader (note: global `URL` is shadowed in
    pages — build query strings manually, `new URL` fails).
11. Divisi menu cleanup (A3): FG & Packing and Ringkasan FG removed from
    division logins; server-side write block on FG endpoints.

**Guides**: per-role tutorial PDFs live in `dist/tutorial/`. Masa-adaptasi
guides for Admin Karangtengah/Cibadak were produced in the session scratchpad
(not committed; rebuilt by scripts that no longer exist) — **regenerate if
needed; they predate several UI changes** (prefilled Qty Kirim, divisi menus).
A "Panduan Leader Admin" PDF was offered but never requested.

## A6. Live state and what to verify

- Last zip sent: `amor-factory-2026-10-30-divisi-produksi-saja.zip` (= current
  `dist/amor-factory-retur-toko.zip` content at that commit). The user said
  "sudah extract". **Not yet verified on live**: divisi_bolu shows only
  Produksi/Riwayat/Pengaturan; Pengiriman Qty prefilled; Konfirmasi KIRIM
  dialog shows "·"; Invoice page shows the waiting-DO hint. Cache ⇒ hard
  refresh. Also confirm `print.css` contains `do-write` and `app.css` has
  `td.row-actions` (earlier extraction once did not take effect).
- Advise deleting zips from the server after extracting. Never upload key
  files to `public_html`.
- Write to live only when the user explicitly asks (live DB, live files).
  Don't keep downloaded live backups.

## A7. Go-live checklist for week 3 (drivers + stores)

1. Pengaturan → turn **Kirim tanpa menunggu FG OFF**.
2. Pengaturan → turn **email ke toko ON** (check each store has an email in
   Master Data → Toko).
3. Drivers claim from the pool; stop Admin pressing Kirim for them. Admin
   stops filling receipts from SJ; stores confirm via QR; Admin verifies
   selisih.
4. Re-issue/refresh the tutorials (driver, toko, FG) and train drivers/stores.
5. Keep Rekonsiliasi as a daily check while FG/Kirim figures settle.

## A8. Running tests and building (recipes)

- Full regression: `bash api/tests/run-reject-toko.sh` (~40 min; cascades
  through the chain). `RJT_ONLY=1 bash api/tests/run-reject-toko.sh` = RJT +
  AFS only (~few min). Targeted: `run-operator-login.sh`,
  `run-production-division-fg-rework.sh`, `run-phase4-fg-packing.sh`,
  `run-special-order.sh`, `run-ui-preview.sh`, `run-phase5-do-shipment.sh`,
  `run-print-preview.sh`. Each `run-*.sh` boots its own disposable MariaDB.
  **Do not build a zip while a regression is running.**
- Build the deliverable: `find api/uploads -type f ! -name .htaccess -delete
  && bash dist/build-cpanel-package-retur.sh` → `dist/amor-factory-retur-toko.zip`;
  copy to a dated name before sending. The build script sanity-checks (php -l,
  no `config.php`, migration guards); it accepts `requireRole('ADMIN')` or
  `Auth::requireLeaderAdmin()` in gated controllers.
- For ad-hoc browser checks, run `php -S 127.0.0.1:PORT -t <api root>
  <api root>/tests/_ui_router.php` against a disposable DB and drive it with
  Playwright (preinstalled; see Part B gotcha #10). Workers sometimes restart
  and kill the DB/server — restart them.

## A9. Open items and risks (most important first)

1. **Branch target unresolved** ("di master" — no such branch). Ask.
2. **Security hygiene still pending on the user's side**: change the Admin1
   password; revoke the finhub API key that was exposed in chat (Pengaturan/finhub
   admin page → revoke, issue a new one); the Android keystore password
   lives only in a past session's scratchpad (treat as possibly lost).
3. **Prefilled Qty Kirim** raises the chance of "sent on paper but not
   loaded". Watch Rekonsiliasi; consider requiring a confirm checkbox if it
   becomes a real problem (not built).
4. Division accounts can't see handover/FG status (A3). Possible follow-up:
   a read-only "diterima FG?" badge on the Produksi page only.
5. Masa-adaptasi PDFs are stale (A5). Email freeze and ship-before-FG are
   **temporary switches** — remember to flip them at go-live (A7).
6. Phase 7+/receivables (Piutang/Payment), GPS/route optimization, payroll,
   SSO/email invites remain explicitly out of scope unless the user says so.

## A10. Two external inputs folded in (2026-11-20)

**(a) A second handoff, written in another session, about a "GAS + single-file
HTML" app** (branch name given: `claude/quirky-clarke-pnc27r`, last commit
`822cf08`). Checked against this repo (high confidence — git facts):

- That is **not a separate project**. `822cf08` (2026-09-09, "Fix closed-item
  lifecycle, PO delete consistency…") is the tip of
  `origin/claude/amor-factory-po-revision-ks60px` and is an **ancestor** of
  the current branch (HEAD is 230 commits ahead). It is the original
  Google-Apps-Script + Google-Sheets system that the PHP/MariaDB rebuild
  (this project) replaces. The other handoff's claim "the PHP project is a
  different project on a different branch" is wrong; the PHP rebuild simply
  came after, in the same repo. `claude/quirky-clarke-pnc27r` does not exist
  on origin (likely a local-only branch name there).
- Legacy pieces that still live in this repo: `backend/Code.gs` (GAS backend,
  ~1,468 lines) and `backend/Code.legacy.gs` (kept verbatim for the
  race-condition repro test — do not delete), the node test suites under
  `tests/` (`run-tests.js`, `uat-*.js`, `backend/*.test.js`, see
  `tests/README.md`), and the single-file frontend, which has been **moved to
  `legacy-frontend-backup/amorcakes-manufacturing-v5-slate(2).html`** (rollback
  copy, see that folder's README). Consequence (verified today): running
  `cd tests && node run-tests.js` now fails with ENOENT because it still looks
  for the HTML at the repo root. Fix only if the user wants the legacy suites
  alive (copy/symlink the HTML back or point the harness at
  `legacy-frontend-backup/`); the PHP regression (§A8) is the active safety net.
- The legacy handoff's conventions (LockService + try/finally on every GAS
  write, `requestId` idempotency + `expectedVersion` optimistic lock, never
  re-add a remote reset endpoint, don't call `arsipkanData()` unless asked)
  mirror what the PHP rebuild does (Part B2 rules 2–3) — useful only if the
  GAS system is ever touched again. Nothing in it is active work; its own §4
  says no task was left open. Its test claims (404 frontend / 108 backend)
  are as recorded in its commit message, not re-run here.
- Is the GAS system still in use? Unknown (the legacy handoff itself says it
  has no evidence of a live deployment). The PHP rebuild is what runs on
  `factory.amorgroup.id`.

**(b) APK `amora.apk` supplied by the user — "AMORA" 1.9** (uploaded file,
inspected statically only; not installed or run). It is a **newer, different
app from `android/` in this repo**:

| | repo `android/` | uploaded AMORA APK |
|---|---|---|
| package | `id.amorgroup.factory`, v1.0 (versionCode 1) | `id.amorgroup.amora`, version 1.9 |
| shell | WebView → `factory.amorgroup.id`; role picker | same WebView lineage (same `MainActivity`/`Bridge` design, Factory login/driver/toko-link endpoints) **plus** an in-app **Updater** that downloads `https://factory.amorgroup.id/app/amora.apk` (permission `REQUEST_INSTALL_PACKAGES`) |
| extras | `launcher.html`, `offline.html`, `amor-logo.png` | launcher titled "AMORA" with "Masuk dengan username & password", "Masuk dengan link toko", **"Buka Kas saja"**; asset `kas-theme.js` (recolours **AmorCash** green → denim blue without touching that site, "same mapping as amorcash-tema-denim"); `amor-cakes-logo.png`; app shortcuts |
| deep links | `/api/_store/` on factory.amorgroup.id | same (`autoVerify`, `pathPrefix=/api/_store/`) |

So AMORA = Factory app + AmorCash ("Kas") in one shell. Its **source is not in
this repo** (repo still holds only the 1.0 Factory-only source) and
`/app/amora.apk` is not produced by any `dist/` script — the update channel is
a manually uploaded file on the server. If the user wants AMORA maintained
from here, get the source (and the **same keystore** — see `android/README.md`:
a different key forces uninstall and breaks `assetlinks.json`) before changing
anything; do not rebuild from `android/` and ship it over AMORA (different
package id = a second app, not an update). The APK is signed with an APK v2
block (a signature block is present); I could not extract the certificate
fingerprint here (no `apksigner`), so compare it with `assetlinks.json` before
trusting a rebuild. Not committed to git (binary, and keys/APKs stay out of
the repo).

**(c) What "this computer" had:** the repo working tree was clean at HEAD
`05d77e8`/pushed; remote branches are only `gracious-heisenberg-m16irt` (active),
`pricing-ui-final-6vv3q9` (tip 2026-10-08, ancestor of HEAD, 120 behind) and
`po-revision-ks60px` (tip 2026-09-09, ancestor, 230 behind). Both older
branches are fully contained in the active one; nothing to merge from them.
That also answers the earlier "di master" question partly: there is no master,
and all history lives in the active branch.

---

# PART B — Long-lived conventions and gotchas (from the Sep-21 handoff; still valid)

## B1. Phase history 0–5.5 (original build order; still accurate for those phases)

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

**Everything after Phase 5.5 is summarised in Part A (§A4).** Phase 6 (Invoice) HAS since been built (migration 0018) — ignore any older statement here that it has not.

## B2. Architecture & conventions — READ THIS before writing any code

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
   correctly by the application code (see (Sep-2026 patch notes, see git history)). **Files-only patches with
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
   safety invariant of the Phase 4 FG Source Refresh patch ((Sep-2026 patch notes, see git history)) and is
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
    Portal** (see (Sep-2026 patch notes, see git history)). When adding a new shared JS behavior, check
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
    real-UAT report — see (Sep-2026 patch notes, see git history)).

## B3. Directory map (as of Sep 2026 — new pages/services since then are listed in §A4/§A5; `ls` is ground truth)

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

## B4. Known gotchas — things that will bite you if you don't know them

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


## B5. Resuming in a new session

1. Attach the repo if needed (`add_repo`), `git fetch origin
   claude/gracious-heisenberg-m16irt && git checkout` it (or use the existing
   `/home/user/Factory` copy). `git log --oneline -10`, `git status`.
2. Skim `git log` since the date at the top of this file; read commit
   messages before touching anything.
3. Run a targeted regression for the area you will change (A8) before and
   after. If the user reports something broken only on live, suspect
   `.htaccess`/cache first (A1, B gotcha #1).
4. Wait for the user's instruction. Their usual next message is a screenshot
   or a short Indonesian bug/UX report; treat it as the spec, reproduce it,
   fix, test, build a dated zip, send it, and ask them to confirm
   ("sudah extract") and verify on live.
5. If asked to refresh this handoff, do a full rewrite folding changes into
   the sections (not a stacked addendum), commit, push, and also send it via
   SendUserFile.

---

**If anything here conflicts with the actual repo state, trust the repo.**
