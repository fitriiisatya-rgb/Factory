#!/usr/bin/env bash
# Builds dist/amor-factory-api-phase1-easy-v2.zip — the Phase 1 EASY V2
# patch package, for an EXISTING real deployment where:
#   - the domain's document root is public_html/factory/ (NOT public_html/
#     itself), so api/ already lives at public_html/factory/api/,
#   - api/app/config/config.php already has working DB_USER/DB_PASS
#     (the runtime user, e.g. u7566812_factoryapp),
#   - the Phase 0 api/_setup/ wizard has already been deleted.
#
# INCREMENTAL, not a fresh install: extract this ZIP positioned INSIDE
# public_html/factory/ so it merges into the existing public_html/factory/api/
# — it overwrites code files but NEVER includes api/app/config/config.php
# (not present in this ZIP at all), so the operator's existing DB
# credentials are untouched and preserved exactly as-is. It never touches
# public_html/factory/index.php (the existing frontend) because nothing in
# this ZIP has that path at all.
#
# New in V2 vs. the superseded amor-factory-api-phase1-incremental.zip:
#   - api/_admin-login/ (new) — the only way to get an ADMIN PHP session at
#     all, since the real frontend has no PHP-session login of its own.
#   - api/_upgrade/ now uses a SEPARATE MIGRATION_DB_* connection
#     (Database::migrationPdo()) instead of the day-to-day runtime one.
#   - api/_import-master/ adds the operator-provided store CANDIDATE review
#     workflow (CONFIRM/SKIP/EDIT NAME) alongside the source-confirmed
#     alias-group import, a manual add-store form, and the Phase 1
#     completion gate banner.
#   - api/app/config/config.example.php documents the new MIGRATION_DB_*
#     keys (used only by _upgrade/, never by the day-to-day app).
#   - database/legacy/phase1-store-candidates-v1.json (new, operator-
#     provided review candidates — see its own "provenance" field).
#
# This ZIP contains NO setup-reset tool, NO database TRUNCATE/DROP, and NO
# transaction-module import of any kind (PO/production/FG/DO/shipment/
# stock/invoice/payment/return/sale are all untouched — see
# Amor\Api\Import\Phase1Importer's own header comment).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-phase1-v2"
ZIP_PATH="$DIST_DIR/amor-factory-api-phase1-easy-v2.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points (unchanged, included for a clean overwrite) ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- copying admin login wizard (new — the only way to get an ADMIN session on this deployment) ---"
mkdir -p "$STAGE/api/_admin-login"
cp "$REPO_ROOT/api/_admin-login/index.php" "$STAGE/api/_admin-login/index.php"
cp "$REPO_ROOT/api/_admin-login/.htaccess" "$STAGE/api/_admin-login/.htaccess"

echo "--- copying Phase 1 import wizard (updated: store candidate review + manual add-store + completion gate) ---"
mkdir -p "$STAGE/api/_import-master"
cp "$REPO_ROOT/api/_import-master/index.php" "$STAGE/api/_import-master/index.php"
cp "$REPO_ROOT/api/_import-master/.htaccess" "$STAGE/api/_import-master/.htaccess"

echo "--- copying standing upgrade runner (updated: separate MIGRATION_DB_* connection) ---"
mkdir -p "$STAGE/api/_upgrade"
cp "$REPO_ROOT/api/_upgrade/index.php" "$STAGE/api/_upgrade/index.php"
cp "$REPO_ROOT/api/_upgrade/.htaccess" "$STAGE/api/_upgrade/.htaccess"

echo "--- copying application (source/config-example/migrations, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- copying canonical schema DDL (0001 + 0002) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"

echo "--- copying bundled legacy source extraction + operator-provided store candidates (new) ---"
mkdir -p "$STAGE/api/app/database/legacy"
cp "$REPO_ROOT/database/legacy/phase1-source-v1.json" "$STAGE/api/app/database/legacy/phase1-source-v1.json"
cp "$REPO_ROOT/database/legacy/phase1-store-candidates-v1.json" "$STAGE/api/app/database/legacy/phase1-store-candidates-v1.json"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory — Phase 1 EASY V2 Package

INCREMENTAL update for an existing real deployment. This domain's document
root is public_html/factory/ — extract this ZIP positioned INSIDE
public_html/factory/ so it merges into your existing
public_html/factory/api/. It overwrites code files but NEVER includes
app/config/config.php, so your database credentials are untouched.

**Start here**: dist/README-FIRST-CPANEL-PHASE1-V2.md (delivered alongside
this ZIP) has the full step-by-step, non-technical guide. No SQL. No CLI.

Quick facts:
- Log in as ADMIN: api/_admin-login/ (temporary — delete after Phase 1)
- Apply the new migration: api/_upgrade/ (requires ADMIN login; uses a
  SEPARATE migration DB connection — MIGRATION_DB_* config keys, never the
  day-to-day DB_USER/DB_PASS; safe to leave in place permanently)
- Import master/identity data + review store candidates: api/_import-master/
  (requires ADMIN login; delete this folder once Phase 1 is reviewed)
- Still NOT imported: PO, production, FG, DO, shipment, stock, invoice,
  payment, returns, sales. Still NOT connected to the live frontend.
- Store master is NOT guaranteed complete just because this ZIP was
  installed — see the "Toko — Kandidat Review" section of
  api/_import-master/ for the 24 operator-provided candidate names that
  still need an explicit CONFIRM/SKIP per row.
EOF

find "$STAGE" -name '.DS_Store' -delete 2>/dev/null || true
find "$STAGE" -name 'Thumbs.db' -delete 2>/dev/null || true

echo "--- sanity: confirm no config.php (real credentials) made it in ---"
if find "$STAGE" -name 'config.php' | grep -q .; then
  echo "REFUSING TO BUILD: a config.php was found in the staging tree — this must never ship." >&2
  find "$STAGE" -name 'config.php' >&2
  exit 1
fi

echo "--- sanity: confirm no api/_setup/ (Phase 0 bootstrap wizard) made it in ---"
if [ -d "$STAGE/api/_setup" ]; then
  echo "REFUSING TO BUILD: api/_setup/ was found in the staging tree — this ZIP must not reintroduce it." >&2
  exit 1
fi

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
