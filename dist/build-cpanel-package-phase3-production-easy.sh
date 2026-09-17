#!/usr/bin/env bash
# Builds dist/amor-factory-api-phase3-production-easy.zip — the Phase 3
# fast-track Production/SPK actual package, for an EXISTING real deployment
# where Phase 2 (PO fast-track) is already installed and reviewed:
#   - api/ lives at public_html/factory/api/ (domain docroot is
#     public_html/factory/, not public_html/ itself),
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001+0002+0003 are already applied,
#   - api/_import-po/ (Phase 2's own temporary wizard) may still be present
#     — this package refreshes it in place (it now shows unique vs
#     occurrence store counts — see PACKAGE-INFO.md) but does not remove it;
#     delete it yourself once Phase 2 is fully retired.
#
# INCREMENTAL, not a fresh install: extract this ZIP positioned INSIDE
# public_html/factory/ so it merges into the existing public_html/factory/api/
# — it overwrites code files but NEVER includes api/app/config/config.php,
# so the operator's existing DB credentials are untouched.
#
# New in this package:
#   - api/_production-uat/ (new) — the Phase 3 Production/SPK actual
#     temporary ADMIN UAT wizard (date/factory/division -> load target from
#     PO -> draft -> input actual -> save/submit -> reopen/resubmit).
#   - api/app/src/Production/*.php (new) — ProductionTargetService (read-only
#     PO target aggregation), ProductionRepository, ProductionService (the
#     document lifecycle: draft/submit/reopen, snapshot-semantics actual
#     entry, optimistic concurrency).
#   - api/app/src/Controllers/ProductionController.php (new) + updated
#     App.php routes (GET/POST /api/production, .../target, .../history,
#     .../{id}, .../{id}/submit, .../{id}/reopen) + Router.php (adds PATCH
#     verb support).
#   - database/schema-v1-0004-production-phase3.sql + migrations/
#     0004_production_phase3.php (additive only — adds user-attribution and
#     PO-drift-detection columns to the ALREADY EXISTING production_run
#     table from the original 0001 schema; production_item needs no schema
#     change at all. No table dropped, no Phase 0/1/2 data touched).
#   - api/app/src/Import/PoImporter.php + api/_import-po/index.php (updated,
#     ADDITIVE ONLY) — the wizard now shows "Toko unik terpetakan" (distinct
#     stores) separately from "Kemunculan/baris toko terpetakan" (row
#     occurrences), fixing the misleading single "toko terpetakan" number a
#     real cPanel UAT run showed as 1986 for a file with far fewer actual
#     stores. Every existing Phase 2 field/semantic/total is unchanged.
#
# This ZIP contains NO setup-reset tool, NO database TRUNCATE/DROP, and NO
# FG/Packing/DO/Shipment/Invoice/Payment/Return authoritative business flow
# of any kind — Production actual here NEVER mutates PO target, and nothing
# downstream of Production (FG onward) is touched by this phase.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-phase3-production"
ZIP_PATH="$DIST_DIR/amor-factory-api-phase3-production-easy.zip"

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

echo "--- copying Phase 2 PO import wizard (refreshed — store-count UI patch, semantics unchanged) ---"
mkdir -p "$STAGE/api/_import-po"
cp "$REPO_ROOT/api/_import-po/index.php" "$STAGE/api/_import-po/index.php"
cp "$REPO_ROOT/api/_import-po/.htaccess" "$STAGE/api/_import-po/.htaccess"

echo "--- copying Phase 3 Production/SPK actual UAT wizard (new) ---"
mkdir -p "$STAGE/api/_production-uat"
cp "$REPO_ROOT/api/_production-uat/index.php" "$STAGE/api/_production-uat/index.php"
cp "$REPO_ROOT/api/_production-uat/.htaccess" "$STAGE/api/_production-uat/.htaccess"

echo "--- copying application (source/config-example/migrations, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- copying canonical schema DDL (0001 + 0002 + 0003 + new 0004) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"
cp "$REPO_ROOT/database/schema-v1-0003-po-phase2.sql" "$STAGE/api/app/database/schema-v1-0003-po-phase2.sql"
cp "$REPO_ROOT/database/schema-v1-0004-production-phase3.sql" "$STAGE/api/app/database/schema-v1-0004-production-phase3.sql"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory — Phase 3 Fast-Track Production/SPK Actual Package

INCREMENTAL update for an existing Phase 2 (PO fast-track) deployment. This
domain's document root is public_html/factory/ — extract this ZIP
positioned INSIDE public_html/factory/ so it merges into your existing
public_html/factory/api/. It overwrites code files but NEVER includes
app/config/config.php, so your database credentials are untouched.

**Start here**: dist/README-FIRST-CPANEL-PHASE3-PRODUCTION.md (delivered
alongside this ZIP) has the full step-by-step, non-technical guide. No SQL.
No CLI.

Quick facts:
- Log in as ADMIN: api/_admin-login/ (same as Phase 1/2)
- Apply migration 0004: api/_upgrade/ (adds created_by/submitted_by/
  reopened_by/source_po_batch_version columns to the ALREADY EXISTING
  production_run table — additive only, no table dropped, no Phase 0/1/2
  data touched)
- Use Production/SPK actual: api/_production-uat/ (requires ADMIN login;
  temporary — delete this folder once Phase 3 is reviewed and accepted)
- api/_import-po/ is refreshed in this package too — it now shows "Toko
  unik terpetakan" (distinct stores) separately from "Kemunculan/baris toko
  terpetakan" (row occurrences). Every existing Phase 2 number/semantic/
  total is unchanged — this is a display-clarity fix only.
- Production actual here NEVER changes PO target — target is always read
  live from the Phase 2 PO module.
- Still NOT implemented: FG verification, Packing, DO, Shipment, Stock
  ledger business flow, Invoice, Payment, Return, Reject, or any cutover of
  the live Apps Script/Sheets frontend to MySQL.
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
  echo "REFUSING TO BUILD: api/_import-master/ was found in the staging tree — this ZIP must not resurrect it." >&2
  exit 1
fi

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
