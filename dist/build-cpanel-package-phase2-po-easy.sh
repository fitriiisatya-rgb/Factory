#!/usr/bin/env bash
# Builds dist/amor-factory-api-phase2-po-easy.zip — the Phase 2 fast-track
# PO module package, for an EXISTING real deployment where Phase 1 (EASY
# V2) is already installed and reviewed:
#   - api/ lives at public_html/factory/api/ (domain docroot is
#     public_html/factory/, not public_html/ itself),
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001+0002 are already applied,
#   - api/_import-master/ (Phase 1's own temporary wizard) has already been
#     deleted by the operator — this package does NOT resurrect it.
#
# INCREMENTAL, not a fresh install: extract this ZIP positioned INSIDE
# public_html/factory/ so it merges into the existing public_html/factory/api/
# — it overwrites code files but NEVER includes api/app/config/config.php,
# so the operator's existing DB credentials are untouched.
#
# New in this package:
#   - api/_import-po/ (new) — the Phase 2 PO upload/preview/import wizard.
#   - api/app/src/Import/Po*.php + XlsxReader.php (new) — parser, resolver,
#     merger, repository, orchestrator for the authoritative MySQL PO model
#     (po_batch/po_item/po_store_item, all already part of the original
#     0001 schema — no parallel PO truth table).
#   - api/app/src/Controllers/PoController.php + updated App.php routes.
#   - database/schema-v1-0003-po-phase2.sql + migrations/0003_po_phase2.php
#     (additive only — adds po_batch upload-provenance columns and a new
#     po_import immutable audit-history table; does not touch any existing
#     table's original CREATE TABLE statement).
#   - api/.htaccess — CRITICAL FIX carried in this package: adds the real
#     DIRECTORY bypass ("-d", not just "-f") so _admin-login/, _upgrade/,
#     and this package's new _import-po/ stay directly reachable on live
#     Apache (this previously worked only under PHP's built-in dev server,
#     which ignores .htaccess entirely — see the file's own header comment).
#
# This ZIP contains NO setup-reset tool, NO database TRUNCATE/DROP, and NO
# transaction-module import of any kind (Production/FG/DO/shipment/stock/
# invoice/payment/return/sale are all untouched — see
# Amor\Api\Import\Phase1Importer and PoImporter's own header comments).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-phase2-po"
ZIP_PATH="$DIST_DIR/amor-factory-api-phase2-po-easy.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points (index.php + THE FIXED .htaccess) ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- sanity: confirm the shipped .htaccess has the real-directory bypass (-d), not just -f ---"
if ! grep -qE '^\s*RewriteCond %\{REQUEST_FILENAME\} -d\s*$' "$STAGE/api/.htaccess"; then
  echo "REFUSING TO BUILD: api/.htaccess is missing the '-d' (real directory) RewriteCond —" >&2
  echo "this is the exact regression that broke _admin-login/_upgrade/_import-master on live Apache before. See api/.htaccess's own header comment." >&2
  exit 1
fi

echo "--- copying admin login wizard (unchanged, included for a clean overwrite) ---"
mkdir -p "$STAGE/api/_admin-login"
cp "$REPO_ROOT/api/_admin-login/index.php" "$STAGE/api/_admin-login/index.php"
cp "$REPO_ROOT/api/_admin-login/.htaccess" "$STAGE/api/_admin-login/.htaccess"

echo "--- copying standing upgrade runner (unchanged, included for a clean overwrite) ---"
mkdir -p "$STAGE/api/_upgrade"
cp "$REPO_ROOT/api/_upgrade/index.php" "$STAGE/api/_upgrade/index.php"
cp "$REPO_ROOT/api/_upgrade/.htaccess" "$STAGE/api/_upgrade/.htaccess"

echo "--- copying Phase 2 PO import wizard (new) ---"
mkdir -p "$STAGE/api/_import-po"
cp "$REPO_ROOT/api/_import-po/index.php" "$STAGE/api/_import-po/index.php"
cp "$REPO_ROOT/api/_import-po/.htaccess" "$STAGE/api/_import-po/.htaccess"

echo "--- copying application (source/config-example/migrations, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- copying canonical schema DDL (0001 + 0002 + new 0003) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"
cp "$REPO_ROOT/database/schema-v1-0003-po-phase2.sql" "$STAGE/api/app/database/schema-v1-0003-po-phase2.sql"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory — Phase 2 Fast-Track PO Package

INCREMENTAL update for an existing Phase 1 (EASY V2) deployment. This
domain's document root is public_html/factory/ — extract this ZIP
positioned INSIDE public_html/factory/ so it merges into your existing
public_html/factory/api/. It overwrites code files but NEVER includes
app/config/config.php, so your database credentials are untouched.

**Start here**: dist/README-FIRST-CPANEL-PHASE2-PO.md (delivered alongside
this ZIP) has the full step-by-step, non-technical guide. No SQL. No CLI.

Quick facts:
- Log in as ADMIN: api/_admin-login/ (same as Phase 1 — reinstalled here
  only for a clean overwrite if you kept it; delete after Phase 2 if you
  no longer need it for anything else)
- Apply migration 0003: api/_upgrade/ (adds po_batch upload-provenance
  columns + a new po_import audit-history table — additive only)
- Upload/preview/import PO files: api/_import-po/ (requires ADMIN login;
  temporary — delete this folder once Phase 2 is reviewed and accepted)
- This package does NOT reinstall api/_import-master/ — if you deleted it
  after Phase 1, it stays deleted.
- Still NOT implemented: Production actual, FG, Packing, DO, Shipment,
  Stock ledger business flow, Invoice, Payment, Return, Reject, or any
  cutover of the live Apps Script/Sheets frontend to MySQL.
EOF

find "$STAGE" -name '.DS_Store' -delete 2>/dev/null || true
find "$STAGE" -name 'Thumbs.db' -delete 2>/dev/null || true

echo "--- sanity: confirm no config.php (real credentials) made it in ---"
if find "$STAGE" -name 'config.php' | grep -q .; then
  echo "REFUSING TO BUILD: a config.php was found in the staging tree — this must never ship." >&2
  find "$STAGE" -name 'config.php' >&2
  exit 1
fi

echo "--- sanity: confirm api/_setup/ (Phase 0 bootstrap wizard) is not reintroduced ---"
if [ -d "$STAGE/api/_setup" ]; then
  echo "REFUSING TO BUILD: api/_setup/ was found in the staging tree — this ZIP must not reintroduce it." >&2
  exit 1
fi

echo "--- sanity: confirm api/_import-master/ (deleted Phase 1 wizard) is not reintroduced ---"
if [ -d "$STAGE/api/_import-master" ]; then
  echo "REFUSING TO BUILD: api/_import-master/ was found in the staging tree — Phase 2 must not resurrect it." >&2
  exit 1
fi

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
