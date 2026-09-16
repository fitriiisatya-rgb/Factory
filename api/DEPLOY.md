# Amor Factory — PHP/MySQL Phase 0 Staging Skeleton — Deployment Guide

**This is infrastructure only.** No business modules (PO, production, FG, DO
lifecycle, shipment, invoice, payment, stock ledger, reports) are
implemented. This skeleton must never be pointed at the production
Apps Script/Sheets frontend or at any live database — see item 19 of the
Phase 0 brief ("DO NOT DO YET") and `src/Config.php`, which hard-refuses
`APP_ENV=production`.

## 0. Before you deploy this anywhere real

Run `SELECT VERSION();` against the actual `factory.amorgroup.id` cPanel
MySQL/MariaDB host and confirm it against `docs/mysql-schema-v1.md` §0's
version requirements (MySQL 5.7.6+ / MariaDB 10.2+, for the generated-column
DO-uniqueness pattern). **This has not been done as part of building this
skeleton** — the session that built it has no network path to that host.
See OD-4 in `docs/mysql-open-decisions-v1.md`. Do not apply `database/schema-v1.sql`
to the real staging database until this is confirmed.

## 1. Requirements

- **PHP 8.1+** (this skeleton uses constructor property promotion, readonly
  properties, `match`, and typed properties throughout — 8.1 is the practical
  floor; developed and tested against PHP 8.4). Confirm the cPanel account's
  "MultiPHP Manager" is set to a matching version for this domain/subdomain.
- **Extensions required**: `pdo_mysql`, `session`, `json` (all are effectively
  always present on cPanel/CloudLinux PHP builds, but confirm in MultiPHP
  Manager's extension list — `mysqli` alone is not sufficient, PDO is required).
- **MySQL 5.7.6+ or MariaDB 10.2+** — see §0 above. Not yet confirmed for
  the real host.
- No Composer dependency is required to run this skeleton (see `autoload.php`
  — a hand-rolled PSR-4-ish loader). Composer may be introduced later if a
  specific need arises, but item 4 of the Phase 0 brief asks to minimize
  dependencies for shared hosting, so none were added.
- **Session storage**: native PHP file-based sessions (`session.save_path`,
  whatever cPanel's default is) are sufficient — no session-support DB table
  exists or is needed (locked decision, `docs/mysql-schema-v1.md` §15.1).

## 2. Directory layout and document root

```
api/
  public/       <- THIS must be the site's document root (or subdomain root)
    index.php
    .htaccess
  src/          <- must NOT be reachable over HTTP
  config/       <- must NOT be reachable over HTTP (holds config.php with real DB creds)
  migrations/
  bin/          <- CLI-only, run via SSH/cPanel Terminal, never over HTTP
  tests/
```

On cPanel, when creating the staging subdomain (e.g. `staging-factory.amorgroup.id`)
or subfolder (e.g. `factory.amorgroup.id/staging-api/`), set its **document
root to `api/public`**, not to `api/`. This means `src/`, `config/`,
`migrations/`, and `bin/` sit outside the web-servable tree entirely — they
are unreachable over HTTP regardless of `.htaccess`, which is the actual
protection (the `.htaccess` in `public/` only handles front-controller
routing, it is not what keeps `config/config.php`'s DB password safe).

If the cPanel account's subdomain tooling cannot point a document root
outside a single shared tree, the fallback is a `factory.amorgroup.id/staging-api/`
subfolder with `public/` as that subfolder's contents and `src/`/`config/`/
`migrations/`/`bin/` placed one level above the account's public web root
(e.g. in the account's home directory, not under `public_html/`) — whichever
of the two `.htaccess`/subdomain options cPanel access actually allows is a
deployment-time decision, not a design one.

**Never deploy this into or under the existing `public_html/` path that
serves the live Apps Script/Sheets-backed frontend.** Use a separate
subdomain or a clearly-separate subfolder, per item 18 of the Phase 0 brief.

## 3. Config

1. Copy `api/config/config.example.php` to `api/config/config.php` on the
   server (via SFTP/File Manager — never via a git deploy that would commit
   it; it's gitignored for exactly this reason).
2. Fill in real `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` for the staging
   database created in §4 below.
3. Alternatively (or in addition — env vars always win, see `src/Config.php`),
   set the same keys as environment variables via cPanel's "Environment
   Variables" panel if the hosting plan exposes one.
4. `APP_ENV` must be `staging`. Setting it to `production` is a hard
   `RuntimeException` at boot in this codebase — that gate is deliberate and
   should stay in place until a real production-readiness review happens.

## 4. Staging database

Create a **separate** database, never the production one:

```sql
CREATE DATABASE amor_factory_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'amor_staging_app'@'localhost' IDENTIFIED BY '<strong random password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON amor_factory_staging.* TO 'amor_staging_app'@'localhost';
FLUSH PRIVILEGES;
```

That grant is deliberately minimal — `SELECT, INSERT, UPDATE, DELETE` only.
The app user does not need `CREATE`/`ALTER`/`DROP`/`GRANT` at runtime; schema
changes are applied by a human running `bin/migrate.php` with a
separately-configured, more-privileged account (or via cPanel's phpMyAdmin),
not by the running application.

On most cPanel accounts, database and username are prefixed
(`cpaneluser_amor_factory_staging`) — adjust `DB_NAME`/`DB_USER` accordingly.

## 5. Apply the schema and seed

Via SSH or cPanel Terminal, with `config/config.php` pointing at the
staging DB from §4:

```bash
php api/bin/migrate.php     # applies database/schema-v1.sql (single canonical source)
php api/bin/seed.php        # factories, 7 roles, synthetic NON-OUTLET store — nothing transactional
```

`bin/migrate.php` tracks what it has applied in a `schema_migrations` table
so it is safe to re-run. `bin/seed.php` is idempotent (every insert is
upsert-on-conflict).

## 6. Creating / resetting the staging admin

Never a hardcoded password in source. Two ways, both via CLI (never HTTP):

```bash
# Interactive (password typed, not echoed):
php api/bin/create_admin.php staging_admin "Staging Admin"

# Non-interactive (e.g. a deploy script) — set the env var in your shell,
# never commit it:
ADMIN_PASSWORD='...' php api/bin/create_admin.php staging_admin "Staging Admin"
```

**To reset a forgotten/rotated password**, re-run the same command with the
same username — it upserts the password hash and reactivates the account.
There is no separate "reset" command; re-creation IS the reset procedure.

## 7. Smoke test

```bash
curl https://staging-factory.amorgroup.id/api/health
```

Expected: `{"ok":true,"data":{"ok":true,"env":"staging","db":"connected","dbVersion":"...","schemaVersion":"v1"}}`

If `db` comes back `"disconnected"`, check `config/config.php` credentials
and that the DB user/host/port are correct — the health endpoint deliberately
never echoes the underlying PDO error message (see `HealthController.php`).

## 8. Running the automated test suite

`api/tests/run.sh` is self-contained: it installs and starts a **disposable,
local-only MariaDB instance** (never touching the real staging DB), applies
the schema, seeds, creates a throwaway admin, starts `php -S`, runs the
P0-01..P0-17 suite, and tears everything down. Requires `mariadb-server-core`
and `mariadb-client-core` (or full `mariadb-server`) available on the machine
running the tests — this is a CI/dev-machine tool, not something to run on
the shared cPanel host itself.

```bash
bash api/tests/run.sh
```

## 9. What is deliberately NOT here yet

Per item 19 of the Phase 0 brief: PO, production, FG, DO full lifecycle,
shipment, invoice, payment, stock ledger business flow, reports, frontend
cutover, Apps Script removal, legacy ETL. This skeleton only proves the
infrastructure (DB access, session auth, CSRF, idempotency, optimistic
concurrency, audit logging, and read-only + minimal master-data CRUD) works
end-to-end and is testable.
