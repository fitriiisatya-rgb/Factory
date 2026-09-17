#!/usr/bin/env bash
# Builds dist/amor-factory-api-phase4-fg-packing-easy.zip — the Phase 4
# fast-track FG/Packing package, for an EXISTING real deployment where
# Phase 3 (Production/SPK actual) is already installed and reviewed:
#   - api/ lives at public_html/factory/api/ (domain docroot is
#     public_html/factory/, not public_html/ itself),
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001-0004 are already applied,
#   - api/_import-po/ and api/_production-uat/ (Phase 2/3's own temporary
#     wizards) may still be present — this package refreshes _import-po/
#     in place (unchanged since Phase 3) and does not touch
#     _production-uat/ at all; delete either yourself once retired.
#
# INCREMENTAL, not a fresh install: extract this ZIP positioned INSIDE
# public_html/factory/ so it merges into the existing public_html/factory/api/
# — it overwrites code files but NEVER includes api/app/config/config.php,
# so the operator's existing DB credentials are untouched.
#
# New in this package:
#   - api/_fg-uat/ (new) — the Phase 4 FG/Packing temporary ADMIN UAT
#     wizard (date/factory/division filter -> load eligible SUBMITTED
#     Production -> draft -> FG Verified/Packed entry -> save/submit ->
#     reopen/resubmit -> availability/history).
#   - api/app/src/Fg/*.php (new) — FgTargetService (read-only aggregation
#     of SUBMITTED Production actual), FgRepository, FgService (the
#     document lifecycle: draft/submit/reopen, snapshot-semantics FG
#     Verified/Packed entry, compensating-delta stock_ledger posting,
#     optimistic concurrency).
#   - api/app/src/Controllers/FgController.php (new) + updated App.php
#     routes (GET/POST /api/fg, .../target, .../availability, .../history,
#     .../{id}, .../{id}/submit, .../{id}/reopen).
#   - database/schema-v1-0005-fg-packing-phase4.sql + migrations/
#     0005_fg_packing_phase4.php (additive only — adds factory_id to the
#     ALREADY EXISTING location table, lifecycle/attribution columns to
#     the ALREADY EXISTING fg_batch table, packed_qty/
#     production_actual_snapshot to the ALREADY EXISTING fg_item table,
#     and one new ENUM value ('fg_item') to stock_ledger.source_type. No
#     table dropped, no Phase 0/1/2/3 data touched, no DO/Shipment table
#     touched at all).
#
# This ZIP contains NO Draft/Preprint DO, NO Shipment, NO delivery,
# invoice, payment, return, or reject-outbound functionality of any kind —
# Phase 4 stays entirely independent of delivery_order/delivery_order_item/
# shipment/shipment_item (see docs/mysql-do-shipment-phase5-reservation-v1.md,
# untouched by this phase; that design's own DO-uniqueness correction stays
# deferred to Phase 5).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-phase4-fg-packing"
ZIP_PATH="$DIST_DIR/amor-factory-api-phase4-fg-packing-easy.zip"

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

echo "--- copying Phase 2 PO import wizard (unchanged since Phase 3, included for a clean overwrite) ---"
mkdir -p "$STAGE/api/_import-po"
cp "$REPO_ROOT/api/_import-po/index.php" "$STAGE/api/_import-po/index.php"
cp "$REPO_ROOT/api/_import-po/.htaccess" "$STAGE/api/_import-po/.htaccess"

echo "--- copying Phase 3 Production/SPK actual UAT wizard (unchanged, included for a clean overwrite) ---"
mkdir -p "$STAGE/api/_production-uat"
cp "$REPO_ROOT/api/_production-uat/index.php" "$STAGE/api/_production-uat/index.php"
cp "$REPO_ROOT/api/_production-uat/.htaccess" "$STAGE/api/_production-uat/.htaccess"

echo "--- copying Phase 4 FG/Packing UAT wizard (new) ---"
mkdir -p "$STAGE/api/_fg-uat"
cp "$REPO_ROOT/api/_fg-uat/index.php" "$STAGE/api/_fg-uat/index.php"
cp "$REPO_ROOT/api/_fg-uat/.htaccess" "$STAGE/api/_fg-uat/.htaccess"

echo "--- copying application (source/config-example/migrations, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- copying canonical schema DDL (0001 + 0002 + 0003 + 0004 + new 0005) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"
cp "$REPO_ROOT/database/schema-v1-0003-po-phase2.sql" "$STAGE/api/app/database/schema-v1-0003-po-phase2.sql"
cp "$REPO_ROOT/database/schema-v1-0004-production-phase3.sql" "$STAGE/api/app/database/schema-v1-0004-production-phase3.sql"
cp "$REPO_ROOT/database/schema-v1-0005-fg-packing-phase4.sql" "$STAGE/api/app/database/schema-v1-0005-fg-packing-phase4.sql"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory — Phase 4 Fast-Track FG/Packing Package

INCREMENTAL update for an existing Phase 3 (Production/SPK actual)
deployment. This domain's document root is public_html/factory/ — extract
this ZIP positioned INSIDE public_html/factory/ so it merges into your
existing public_html/factory/api/. It overwrites code files but NEVER
includes app/config/config.php, so your database credentials are
untouched.

**Start here**: dist/README-FIRST-CPANEL-PHASE4-FG-PACKING.md (delivered
alongside this ZIP) has the full step-by-step, non-technical guide. No SQL.
No CLI.

Quick facts:
- Log in as ADMIN: api/_admin-login/ (same as Phase 1/2/3)
- Apply migration 0005: api/_upgrade/ (adds factory_id to the ALREADY
  EXISTING location table, lifecycle columns to the ALREADY EXISTING
  fg_batch table, packed_qty/production_actual_snapshot to the ALREADY
  EXISTING fg_item table, and one new stock_ledger.source_type value —
  additive only, no table dropped, no Phase 0/1/2/3 data touched)
- Use FG/Packing: api/_fg-uat/ (requires ADMIN login; temporary — delete
  this folder once Phase 4 is reviewed and accepted)
- Only Production with status SUBMITTED is eligible as an FG source —
  draft/reopened Production contributes nothing until resubmitted.
- FG/Packing NEVER changes PO or Production data — both are always read
  live/snapshotted, never written to by this phase.
- Stock only decreases/increases via stock_ledger, written exactly once
  per FG submission (or once per correction's delta on a resubmit) —
  draft/reopened FG never touches stock.
- Still NOT implemented: Draft/Preprint DO, Shipment, delivery, Invoice,
  Payment, Return, Reject, or any cutover of the live Apps Script/Sheets
  frontend to MySQL. See docs/mysql-do-shipment-phase5-reservation-v1.md
  for the reserved Phase 5 design — nothing in it was touched by Phase 4.
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

echo "--- sanity: confirm no Draft DO / Shipment functionality was added (Phase 4 must stay independent) ---"
if [ -d "$STAGE/api/_do-uat" ] || [ -d "$STAGE/api/_shipment-uat" ] || [ -d "$STAGE/api/app/src/Delivery" ] || [ -d "$STAGE/api/app/src/Shipment" ]; then
  echo "REFUSING TO BUILD: DO/Shipment code found in the staging tree — Phase 4 must remain independent of delivery_order/shipment (deferred to Phase 5)." >&2
  exit 1
fi
if grep -RIl "delivery_order\|shipment_item\|CREATE TABLE shipment" "$STAGE/api/app/src/Fg" 2>/dev/null | grep -q .; then
  echo "REFUSING TO BUILD: Phase 4's own Fg/ source references delivery_order/shipment — must stay independent." >&2
  exit 1
fi

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
