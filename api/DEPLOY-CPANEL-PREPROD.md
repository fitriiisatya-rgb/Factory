# Amor Factory — Phase 0.5: Real cPanel Preproduction Apply Procedure

**Scope reminder**: this procedure applies the 45-table infrastructure
schema, seeds minimum master data, and creates an admin account on the real
cPanel hosting database. It does **not** implement any business module, does
**not** import legacy PO/production/shipment/invoice data, does **not** cut
over the live frontend, and does **not** retire Apps Script. Do not go
beyond these steps until the smoke test checklist below is fully green.

## Known values for this deployment

| | |
|---|---|
| Database | `u7566812_factory` (confirmed **EMPTY / newly created** as of Phase 0.5) |
| Engine | MariaDB `10.11.19-MariaDB-cll-lve` (OD-4 CLOSED — see `docs/mysql-open-decisions-v1.md`) |
| Connects via | `localhost` (standard cPanel same-host MySQL) |
| Domain document root | `public_html/factory/` (confirmed against the live host — **not** `public_html/` itself; `factory.amorgroup.id`'s existing frontend `index.php` lives there and must never be touched) |
| API filesystem path | `public_html/factory/api/` (the ZIP is extracted **inside `public_html/factory/`**, not `public_html/`) — the URL is still `https://factory.amorgroup.id/api/...`, unchanged, since routing reads `REQUEST_URI` directly |
| Runtime DB user | `u7566812_factoryapp` (SELECT/INSERT/UPDATE/DELETE only — day-to-day `DB_USER`, confirmed live) |
| Migration/admin DB user | `u7566812_adminfactory` (schema/DDL setup only, `MIGRATION_DB_*` — see section 6) |
| Real passwords | **Never** written to this repo, docs, commits, test fixtures, or source — the operator enters them only into an untracked `config/config.php` on the server itself, or types them at an interactive prompt |

Everything below assumes these exact values. If any of them changes, update
`api/app/config/config.example.php`'s `EXPECTED_DB_NAME`/`DB_NAME` comments
accordingly before proceeding — the migration runner will refuse to run on
a mismatch by design (see section 3 of the Phase 0.5 request, implemented in
`api/bin/migrate.php` and `api/app/src/Setup/MigrationRunner.php`).

---

## Method A: CLI / SSH (preferred whenever available)

### Step 1 — Backup / confirm empty DB

The database is already confirmed empty. Still, before touching anything,
run one read-only check to be certain nothing has changed since that was
confirmed:

```sql
-- via phpMyAdmin or `mysql`/`mariadb` CLI, connected as the migration user
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'u7566812_factory';
```

**Expected output**: `0`.
**Failure condition**: any non-zero count — STOP. Do not proceed to step 6
until you understand what's already in there (it may be someone else's
work, or a previous partial attempt). `bin/migrate.php`'s own safety gate
(section 6 below) also checks this automatically and will refuse if the
database has tables but no migration record — but don't rely on that alone;
check by hand first.

### Step 2 — Upload code

**This is now the easy-install package flow** (see
`dist/README-FIRST-CPANEL-PHASE1-V2.md` for the non-technical version of
these same steps, and its correction notice if you're looking at an older
README): upload `dist/amor-factory-api-phase1-easy-v2.zip` to the cPanel
account (File Manager upload, or SFTP) and extract it while positioned
**inside `public_html/factory/`** — the real document root for
`factory.amorgroup.id` on this host — so it produces
`public_html/factory/api/`. Since a technical operator with SSH can also
just `git clone`/`rsync` the repo and run the packaging script directly on
the server (or upload the already-built ZIP the same way) — both land at
the same `public_html/factory/api/` result. **Do not extract into
`public_html/` directly** — that would land at `public_html/api/`, which is
not where this domain actually serves requests from, and would leave
`public_html/factory/index.php` (the existing frontend) untouched but
unreachable from the new `api/` folder's sibling path.

**No document root change of any kind, and the existing frontend is never
touched.** `factory.amorgroup.id`'s existing document root is
`public_html/factory/`, and its `index.php` there is untouched — `api/` is
simply a new subfolder alongside it. `api/app/` (source, config,
migrations) sits *inside* that same subfolder but is blocked from direct
HTTP access by `api/app/.htaccess` (`Require all denied`) — see
`api/DEPLOY.md` section 2 for the full reasoning, including the advanced/
optional variant that moves `app/` fully outside `public_html/factory/` for
an operator who wants stronger isolation and has the access to arrange it.

**Expected output**: `https://factory.amorgroup.id/api/` is reachable (the
URL itself never includes `/factory/` — only the upload/extract filesystem
destination does). It will show a friendly "config not filled in yet"
message until step 4 below, not a raw 500.
**Failure condition**: a 404 for anything under `/api/` most likely means
the ZIP was extracted into `public_html/` instead of `public_html/factory/`
(check the domain's actual document root in cPanel's "Domains" panel if
unsure), or `.htaccess` isn't being honored (rare on cPanel — confirm
`AllowOverride`/mod_rewrite are enabled, which is the standard cPanel/
CloudLinux default).

### Step 3 — Create the private config

Copy `api/app/config/config.example.php` to `api/app/config/config.php` on
the server (File Manager's "Copy" + rename, or SFTP — never commit this
file to any repository). `api/app/` is not reachable over HTTP (step 2), so
this file cannot be requested directly by a browser regardless of its name
or location within `app/`.

### Step 4 — Set DB credentials

Edit the server's `api/app/config/config.php`:

```php
<?php
return [
    'APP_ENV' => 'preproduction',
    'APP_DEBUG' => false,
    'APP_TIMEZONE' => 'Asia/Jakarta',

    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'u7566812_factory',
    'EXPECTED_DB_NAME' => 'u7566812_factory',

    'DB_USER' => 'u7566812_adminfactory', // migration only — see section 6
    'DB_PASS' => '<the real password — never write this anywhere else>',

    'SESSION_SECURE' => true,
    'SESSION_SAMESITE' => 'Lax',
    'SESSION_LIFETIME_SECONDS' => 28800,
];
```

**Failure condition**: if you don't know the `u7566812_adminfactory`
password, get it from cPanel → MySQL Databases → (reset if needed) before
continuing. Do not paste it into a chat, an issue, a commit message, or a
support ticket.

### Step 5 — Run a database connection test

```bash
php api/bin/migrate.php
```

At this point it will print connection info and then either refuse (if the
DB isn't empty as expected — see step 1) or show "Planned action: apply 1
migration(s)" and stop at the confirmation prompt. This alone proves the
connection works — you don't need a separate diagnostic script (see section
14 below on why a separate credential-bearing test file is explicitly the
wrong pattern).

**Expected output**:
```
=== Amor Factory migration runner ===
APP_ENV=preproduction  DB_HOST=localhost  DB_NAME=u7566812_factory  DB_USER=u7566812_adminfactory
(password never printed)
Server: 10.11.19-MariaDB-cll-lve
Database state: 0 business table(s), 0 migration(s) recorded as applied.
Planned action: apply 1 migration(s) to 'u7566812_factory':
  - 0001_schema_v1.php

Type the database name ('u7566812_factory') exactly to confirm, or anything else to abort:
```

**Failure condition**: a `RuntimeException`/`PDOException` here means
connection failure — check `DB_HOST`/`DB_USER`/`DB_PASS`/`DB_NAME`, and that
the migration user has been granted access to this database in cPanel's
MySQL Databases panel (creating a user does not automatically grant it
access to a specific database on cPanel — that's a separate "Add User to
Database" step).

### Step 6 — Run the migration

At the prompt from step 5, type `u7566812_factory` exactly and press enter.

**Expected output**: `OK    0001_schema_v1.php` followed by `Migrations
complete. 45 business table(s) now present (schema_migrations itself is
infrastructure metadata, not counted here).`

**Failure condition**: any SQL error here is unexpected — the DDL was
compatibility-reviewed against this exact MariaDB version (10.11.19) with
zero patches required (`docs/mysql-schema-v1.md` §18.1) and validated
against a disposable local instance on the same 10.11 branch. If this step
fails anyway, STOP, capture the exact error, and treat it as a real,
unresolved compatibility finding — do not work around it by hand-editing
the live database.

### Step 7 — Verify table count

```sql
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'u7566812_factory' AND table_name != 'schema_migrations';
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'u7566812_factory';
```

**Expected output**: `45` and `46` respectively (the 46th being
`schema_migrations` itself — infrastructure metadata that tracks which
migrations have run, not a business table; see section 4 of the Phase 0.5
request).

### Step 8 — Run the seed

```bash
php api/bin/seed.php
```

**Expected output**: `factories: 2 seeded`, `roles: 7 seeded`, `synthetic
store: NON-OUTLET / PERORANGAN seeded`.
**Failure condition**: none expected — this only runs after step 6 succeeds,
and every insert is upsert-on-conflict (safe to re-run, see
`api/app/src/Setup/Seeder.php`).

### Step 9 — Create the preproduction admin

```bash
php api/bin/create_admin.php preprod_admin "Preproduction Admin"
```

You'll be prompted for a password (input hidden, typed twice to confirm).
Never pass `ADMIN_PASSWORD=...` on a shared/logged shell if you can avoid
it — the interactive prompt is preferred on a real host for exactly that
reason (shell history, `ps` visibility of other users on the box, etc.).

**Expected output**: `OK: preprod_admin is now an ADMIN in u7566812_factory`

### Step 10 — Create the runtime DB user

Still using the migration user's access (cPanel → MySQL Databases), create
a **separate** database user for the running application — see section 6
below for the exact privilege set and the reasoning. Do this now, before
declaring Phase 0.5 done, even though the app config in step 4 still points
at the migration user for now.

### Step 11 — Switch API config from migration user to runtime user

Edit `api/app/config/config.php` again:

```php
'DB_USER' => '<the new runtime user from step 10>',
'DB_PASS' => '<its real password>',
```

Leave `DB_NAME`/`EXPECTED_DB_NAME` unchanged. This is the point where the
running application stops having any `CREATE`/`ALTER`/`DROP`/`INDEX`
capability at all — see section 6's privilege table.

### Step 12 — Smoke test

Run through the full CP-01..CP-20 checklist in the section below. Every
item must pass before this phase is considered done.

### Step 13 — Remove temporary migration/test scripts from the web-accessible directory

If Method B (section below) was used at any point, **delete the entire
`api/_setup/` directory now** — it must never remain reachable after
setup, regardless of how well-guarded its `SETUP_TOKEN` check is. If only
Method A (CLI) was used, `_setup/` was never touched and this step is a
no-op, but confirm it's not present anyway:

```bash
ls api/_setup 2>/dev/null && echo "STILL THERE — DELETE IT" || echo "not present, OK"
```

Also see section 14 below regarding any pre-existing `db-test.php`-style
diagnostic file that may already be sitting in the web root from earlier,
unrelated manual poking around on this hosting account.

---

## Method B: no SSH — the guided setup wizard

Use this if cPanel's Terminal/SSH is genuinely unavailable, or simply
because a single guided page is easier to get right than five CLI commands.
It performs the exact same steps as Method A (5, 6, 8, 9) but via one
browser page instead of a shell, with an extra layer of access control
since this page sits inside the public document root. **This is also the
path the non-technical `dist/README-FIRST-CPANEL.md` guide uses — read that
document for the plain-language walkthrough of the same wizard described
technically here.**

### B.0 — Extra config before using this method

Add one more key to `api/app/config/config.php` (in addition to everything in
step 4 above):

```php
'SETUP_TOKEN' => '<a long random string, e.g. from `openssl rand -hex 32`>',
```

Without `SETUP_TOKEN` set, `api/_setup/index.php` refuses outright (see
`api/app/src/SetupGuard.php`) — this is not optional. Generate the token
with a real random source, not something guessable. The packaged ZIP
includes `dist/generate-setup-token.php` (`php dist/generate-setup-token.php`)
as a convenience if no other way to generate a random hex string is at hand.

### B.1 — Open the wizard

Visit (replace `<token>` with your real `SETUP_TOKEN` value):

```
https://factory.amorgroup.id/api/_setup/?token=<token>
```

This single page shows a live 7-step checklist (Koneksi, Kompatibilitas
Database, Instalasi Struktur Database, Data Awal, Buat Admin, Verifikasi,
Selesai), each marked `BELUM` (not yet reached), `READY` (can run now),
`BERHASIL` (done), or `ERROR`. Steps 1–2 (connection, compatibility) run
automatically and read-only on every page load — nothing destructive ever
runs without an explicit button click plus a confirmation checkbox.

### B.2 — Run steps 3–5 in order, on the same page

The page enforces the order itself (a step's button only appears once the
previous one is `BERHASIL`) — there is no separate migrate/seed/create-admin
URL to visit in the wrong order. **Expected/failure conditions for steps
3–5 are identical to CLI steps 5–9** (schema install, seed, admin
creation), just presented as one page instead of terminal output.

### B.3 — Step 6 (Verifikasi) and step 7 (Selesai)

Step 6 shows the same summary table as CP-01..CP-19 below, computed live.
Step 7 shows the "SETUP COMPLETE" banner, the same "next required actions"
list as section 9 of this document, and a **"Selesai & Nonaktifkan Setup"**
button.

### B.4 — Disable or delete `api/_setup/` immediately after

Click "Selesai & Nonaktifkan Setup" — this writes a marker file that makes
every request to `api/_setup/` return `404 Not Found` from then on. This is
not optional and not deferred to "later cleanup" — do it in the same
session, right after B.3 succeeds. Continue with CLI steps 10–13 (creating
the runtime user, switching config, smoke test) exactly as in Method A —
those don't need SSH either, they're config-file edits and the smoke test
is just HTTP requests (`curl` from your own machine, or a browser, works
fine). The single most reliable disable mechanism, stronger than the
marker-file self-disable, is still to delete the entire `api/_setup/`
folder via File Manager once you're done with it — do that when convenient,
even after clicking the button.

---

## 6. Migration user vs runtime user — privilege model

Two separate database identities, on purpose, so the running application
can never issue `CREATE`/`ALTER`/`DROP`, even if a bug in the PHP code (SQL
injection, a logic error, anything) tried to:

### A. Migration user — `u7566812_adminfactory`

Used **only** for:
- `CREATE`, `ALTER`, `INDEX` — schema/DDL setup (`bin/migrate.php`)
- `REFERENCES` — foreign key creation
- Whatever else cPanel's "Add User to Database" UI grants by default (cPanel
  typically offers "ALL PRIVILEGES" as the simple option for a database
  user — that's acceptable for this identity specifically, since it is
  never used by the running application after step 11)

**Never used by `api/app/config/config.php` once step 11 is complete.**

### B. Runtime user — created fresh in step 10, name TBD by the operator (e.g. `u7566812_factoryapp`)

Grant **exactly**:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON u7566812_factory.* TO 'u7566812_factoryapp'@'localhost';
FLUSH PRIVILEGES;
```

In cPanel's MySQL Databases → "Add User to Database" UI, this is the
"Privileges" checkbox screen — check only `SELECT`, `INSERT`, `UPDATE`,
`DELETE`. Leave every other checkbox (including `INDEX`, `ALTER`, `CREATE
TEMPORARY TABLES`, `LOCK TABLES`, `CREATE`, `DROP`, `REFERENCES`, `EXECUTE`,
`CREATE VIEW`, `SHOW VIEW`, `CREATE ROUTINE`, `ALTER ROUTINE`, `EVENT`,
`TRIGGER`) **unchecked**. This codebase's queries never need `REFERENCES`
(it never creates a table at runtime) or `EXECUTE` (no stored
procedures/functions exist in this schema — see `docs/mysql-schema-v1.md`
§12 on why `CHECK` constraints are advisory-only rather than real DDL, and
note there are no stored procedures anywhere in this design either).

This is exactly what CP-20 in the smoke test checklist verifies —
attempting a `CREATE TABLE` as the runtime user must fail.

---

## Smoke test checklist

Run in order. Every item must show the expected result; stop and fix before
continuing on any FAIL. `curl` commands assume a browser-reachable base URL
for the deployed API; substitute your actual preprod host.

| ID | Check | How | Expected |
|---|---|---|---|
| CP-01 | API PHP loads | `curl -i https://<host>/api/health` | Any HTTP response at all (not a connection refused/timeout) |
| CP-02 | PDO MySQL extension active | Same request | No `500` with a message about `pdo_mysql`/`PDO` missing |
| CP-03 | DB connection succeeds | Same request, inspect JSON | `data.db == "connected"` |
| CP-04 | MariaDB version confirmed | Same request | `data.dbVersion` starts with `10.11.19` |
| CP-05 | Migration applied | `SELECT COUNT(*) FROM schema_migrations;` via phpMyAdmin/CLI | `1` |
| CP-06 | Schema count correct | `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='u7566812_factory' AND table_name != 'schema_migrations';` | `45` |
| CP-07 | Factories seeded = 2 | `SELECT COUNT(*) FROM factory;` | `2` |
| CP-08 | Roles seeded = 7 | `SELECT COUNT(*) FROM roles;` | `7` |
| CP-09 | Synthetic store exists = 1 | `SELECT COUNT(*) FROM store WHERE canonical_name='NON-OUTLET / PERORANGAN';` | `1` |
| CP-10 | Create staging admin succeeds | `bin/create_admin.php` (or the web fallback), then `SELECT COUNT(*) FROM users WHERE username='...';` | `1` |
| CP-11 | Login succeeds | `POST /api/auth/login` with the admin's real credentials | `200`, `data.user.roles` includes `"ADMIN"` |
| CP-12 | `auth/me` succeeds | `GET /api/auth/me` reusing the session cookie from CP-11 | `200`, `data.username` matches |
| CP-13 | Logout succeeds | `POST /api/auth/logout` | `204`, and a subsequent `GET /api/auth/me` on the same cookie jar now returns `401` |
| CP-14 | Unauthenticated protected endpoint → 401 | `GET /api/products` with no session cookie | `401 UNAUTHENTICATED` |
| CP-15 | CSRF-less mutation → rejected | Logged-in session, `POST /api/products` with no `X-CSRF-Token` header | `403 CSRF_TOKEN_INVALID` |
| CP-16 | Product create (optional) | `POST /api/products` with a valid CSRF token + `Idempotency-Key` | `201`, `data.version == 1` — **optional; delete the test product row afterward if run, since Phase 0.5 seeds no products** |
| CP-17 | Version conflict works | `PUT /api/products/{id}` twice with the same stale `version` | Second call: `409 VERSION_CONFLICT` with `currentVersion` in the body |
| CP-18 | Idempotency replay works | Repeat an identical `POST` (same `Idempotency-Key` + same body) | Same response replayed, `SELECT COUNT(*)` on the affected table shows no duplicate row |
| CP-19 | Audit log written | `SELECT COUNT(*) FROM audit_log WHERE record_type='product' AND record_key='<id>';` after CP-16/17 | `>= 1` |
| CP-20 | Runtime DB user cannot CREATE/DROP/ALTER | Connect as the **runtime** user (not migration user) via CLI/phpMyAdmin and run `CREATE TABLE cp20_probe (x INT);` | Access-denied error (`ERROR 1142` or similar) — if this succeeds, the runtime user has too many privileges; fix the grant from section 6 and re-test |

CP-16/CP-17/CP-18/CP-19 together exercise the same behavior the local
`api/tests/Phase0Test.php` suite already proves (P0-08 through P0-14) —
running them here is confirmation that the real host behaves identically,
not a first-time test of that logic.

---

## Database backup baseline

**After** schema + seed + admin creation succeed (CP-01 through CP-20 all
green), and **before** any Phase 1 legacy-identity ETL work begins, take a
clean baseline backup:

```bash
mysqldump -h localhost -u u7566812_adminfactory -p u7566812_factory > amor_factory_baseline_pre_etl_YYYYMMDD.sql
```

(replace `YYYYMMDD` with the actual date). **Do not commit this dump to
git** — it's a real database export, however minimal its contents are at
this point (infrastructure + seed data + one admin user's password hash).
Store it wherever the operator normally keeps hosting backups (local
machine, a private backup service, cPanel's own backup tooling) — anywhere
except this repository. This is the rollback point Phase 1's identity
migration can restore to if something goes wrong there.

---

## Cleanup: the old `db-test.php`

An earlier, ad-hoc diagnostic file (`db-test.php`, or similarly named) may
already exist in the live web root from manual troubleshooting before this
procedure existed. **It must be deleted** — this kind of file typically
hardcodes or echoes DB credentials directly, which is exactly what this
whole procedure (config files outside the document root, `SETUP_TOKEN`
gating, `HealthController` never exposing DB username/password) exists to
avoid. This repository does not recreate any such file — `GET /api/health`
(section on smoke testing above) is the one sanctioned "is the DB reachable"
diagnostic, and it deliberately reports only `ok`/`env`/`db`/`dbVersion`/
`schemaVersion`, never credentials, DB name, or filesystem paths. If a
future diagnostic need comes up, it must read from `config/config.php` /
environment variables like everything else in `api/app/src/Config.php` — never
a new hardcoded-credential file.
