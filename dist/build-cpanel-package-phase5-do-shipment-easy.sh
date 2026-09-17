#!/usr/bin/env bash
# Builds dist/amor-factory-api-phase5-do-shipment-easy.zip — the Phase 5
# fast-track Draft DO / staged Shipment package, for an EXISTING real
# deployment where Phase 4 (FG/Packing) is already installed and reviewed:
#   - api/ lives at public_html/factory/api/ (domain docroot is
#     public_html/factory/, not public_html/ itself),
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001-0005 are already applied,
#   - api/_import-po/, api/_production-uat/, api/_fg-uat/ (Phase 2/3/4's
#     own temporary wizards) may still be present — this package refreshes
#     them in place (unchanged since their own phase) and does not delete
#     any of them; delete each yourself once retired.
#
# INCREMENTAL, not a fresh install: extract this ZIP positioned INSIDE
# public_html/factory/ so it merges into the existing public_html/factory/api/
# — it overwrites code files but NEVER includes api/app/config/config.php,
# so the operator's existing DB credentials are untouched.
#
# New in this package:
#   - api/_do-uat/ (new) — the Phase 5 Draft DO / Shipment temporary ADMIN
#     UAT wizard (date/factory -> stores with PO -> generate/open Draft DO
#     -> preprint -> shipment preview -> confirm SHIP, staged/partial,
#     bulk-generate, print + bulk-print, search/filter, history).
#   - api/_do-uat/print.php + print-bulk.php (new) — printable DO document
#     pages with a DRAFT/PREPRINT watermark (never shown as already shipped
#     before a real SHIP commit).
#   - api/app/src/Delivery/*.php (new) — DoTargetService (read-only
#     aggregation of live store-level PO demand), DoRepository, DoService
#     (draft/preprint/refresh-from-PO/cancel/bulk-generate lifecycle),
#     ShipmentService (read-only preview + the one and only transactional
#     stock-writing SHIP commit).
#   - api/app/src/Controllers/DoController.php (new) + updated App.php
#     routes (GET/POST /api/do, .../preview, .../stores, .../history,
#     .../{id}, .../{id}/preprint, .../{id}/refresh-po, .../{id}/cancel,
#     .../{id}/shipments, .../{id}/shipment-preview, .../{id}/ship,
#     .../generate-bulk).
#   - database/schema-v1-0006-do-shipment-phase5.sql + migrations/
#     0006_do_shipment_phase5.php (additive only — adds a NEW
#     open_key_by_store generated column + unique key to the ALREADY
#     EXISTING delivery_order table alongside its original open_key/
#     uq_delivery_order_open, adds a source_po_version_json/preprinted_by/
#     cancelled_at/cancelled_by/cancel_reason set of columns to
#     delivery_order, and factory_id/created_by/shipped_by/shipped_at to
#     the ALREADY EXISTING shipment table plus delivery_order_item_id/notes
#     to shipment_item. No table dropped, no Phase 0-4 data touched,
#     stock_ledger/stock_balance's ENUM values were already sufficient —
#     event_type='shipment_out' needed no schema change at all).
#
# This ZIP contains NO Invoice, Payment, Receivable, Return, Reject, or
# store-receipt-confirmation functionality of any kind, and NO shipment
# void/cancel-after-shipped UI (see the phase's own report for that
# residual risk) — see docs/mysql-do-shipment-phase5-reservation-v1.md,
# whose DO-identity correction this migration finally implements.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-phase5-do-shipment"
ZIP_PATH="$DIST_DIR/amor-factory-api-phase5-do-shipment-easy.zip"

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

echo "--- copying Phase 2 PO import wizard (unchanged, included for a clean overwrite) ---"
mkdir -p "$STAGE/api/_import-po"
cp "$REPO_ROOT/api/_import-po/index.php" "$STAGE/api/_import-po/index.php"
cp "$REPO_ROOT/api/_import-po/.htaccess" "$STAGE/api/_import-po/.htaccess"

echo "--- copying Phase 3 Production/SPK actual UAT wizard (unchanged, included for a clean overwrite) ---"
mkdir -p "$STAGE/api/_production-uat"
cp "$REPO_ROOT/api/_production-uat/index.php" "$STAGE/api/_production-uat/index.php"
cp "$REPO_ROOT/api/_production-uat/.htaccess" "$STAGE/api/_production-uat/.htaccess"

echo "--- copying Phase 4 FG/Packing UAT wizard (unchanged, included for a clean overwrite) ---"
mkdir -p "$STAGE/api/_fg-uat"
cp "$REPO_ROOT/api/_fg-uat/index.php" "$STAGE/api/_fg-uat/index.php"
cp "$REPO_ROOT/api/_fg-uat/.htaccess" "$STAGE/api/_fg-uat/.htaccess"

echo "--- copying Phase 5 Draft DO / Shipment UAT wizard (new) ---"
mkdir -p "$STAGE/api/_do-uat"
cp "$REPO_ROOT/api/_do-uat/index.php" "$STAGE/api/_do-uat/index.php"
cp "$REPO_ROOT/api/_do-uat/print.php" "$STAGE/api/_do-uat/print.php"
cp "$REPO_ROOT/api/_do-uat/print-bulk.php" "$STAGE/api/_do-uat/print-bulk.php"
cp "$REPO_ROOT/api/_do-uat/.htaccess" "$STAGE/api/_do-uat/.htaccess"

echo "--- copying application (source/config-example/migrations, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- copying canonical schema DDL (0001 + 0002 + 0003 + 0004 + 0005 + new 0006) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"
cp "$REPO_ROOT/database/schema-v1-0003-po-phase2.sql" "$STAGE/api/app/database/schema-v1-0003-po-phase2.sql"
cp "$REPO_ROOT/database/schema-v1-0004-production-phase3.sql" "$STAGE/api/app/database/schema-v1-0004-production-phase3.sql"
cp "$REPO_ROOT/database/schema-v1-0005-fg-packing-phase4.sql" "$STAGE/api/app/database/schema-v1-0005-fg-packing-phase4.sql"
cp "$REPO_ROOT/database/schema-v1-0006-do-shipment-phase5.sql" "$STAGE/api/app/database/schema-v1-0006-do-shipment-phase5.sql"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory — Phase 5 Fast-Track Draft DO / Staged Shipment Package

INCREMENTAL update for an existing Phase 4 (FG/Packing) deployment. This
domain's document root is public_html/factory/ — extract this ZIP
positioned INSIDE public_html/factory/ so it merges into your existing
public_html/factory/api/. It overwrites code files but NEVER includes
app/config/config.php, so your database credentials are untouched.

**Start here**: dist/README-FIRST-CPANEL-PHASE5-DO-SHIPMENT.md (delivered
alongside this ZIP) has the full step-by-step, non-technical guide. No SQL.
No CLI.

Quick facts:
- Log in as ADMIN: api/_admin-login/ (same as Phase 1-4)
- Apply migration 0006: api/_upgrade/ (adds a new store-scoped unique key
  to the ALREADY EXISTING delivery_order table ALONGSIDE its original one
  — never replacing it — plus lifecycle/attribution columns to
  delivery_order and shipment/shipment_item. Additive only, no table
  dropped, no Phase 0-4 data touched)
- Use Draft DO / Shipment: api/_do-uat/ (requires ADMIN login; temporary —
  delete this folder once Phase 5 is reviewed and accepted)
- ONE store produces ONE Delivery Order per day (identity = tanggal+store
  only), which can be shipped in MULTIPLE staged shipments (MAIN/PASTRY/
  OTHER or any other group label) — never a separate DO per shipment group.
- FG stock ONLY decreases on a real, confirmed SHIP action — creating,
  previewing, or preprinting a DO never touches stock_ledger.
- DO NOT YET IMPLEMENTED: shipment void/cancel-after-shipped (see the
  phase's own report for this residual risk — prefer safe omission over
  an unsafe delete), Invoice, Payment, Receivable aging, Return, Reject,
  store receipt confirmation, or any cutover of the live Apps
  Script/Sheets frontend to MySQL.
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

echo "--- sanity: confirm no Invoice/Payment/Return/Reject functionality was added (Phase 5 must stay scoped to DO/Shipment) ---"
if find "$STAGE" -iname '*invoice*' -o -iname '*payment*' -o -iname '*receivable*' -o -iname '*return*' -o -iname '*reject*' | grep -q .; then
  echo "REFUSING TO BUILD: Invoice/Payment/Receivable/Return/Reject-named files found in the staging tree — out of scope for Phase 5." >&2
  exit 1
fi

echo "--- sanity: confirm no shipment void/cancel-after-shipped endpoint was added (deliberately omitted this phase) ---"
if grep -RIliE "function (voidShipment|cancelShipment|shipmentVoid|shipmentCancel)\(" "$STAGE/api/app/src/Controllers/DoController.php" "$STAGE/api/app/src/Delivery" 2>/dev/null | grep -q .; then
  echo "REFUSING TO BUILD: a shipment void/cancel function was found — this phase deliberately omits it (safe-omission decision, see report)." >&2
  exit 1
fi

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
