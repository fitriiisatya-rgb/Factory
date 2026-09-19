#!/usr/bin/env bash
# Builds dist/amor-factory-api-phase4-fg-source-refresh-patch.zip — the
# URGENT UAT PATCH for an EXISTING real deployment where Phase 5.5 +
# User/Driver Account Management are already installed:
#   - api/ lives at public_html/factory/api/,
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001-0007 are already applied.
#
# FILES-ONLY PATCH — NO NEW MIGRATION. Audited first: fg_batch_source.
# source_version and fg_item.production_actual_snapshot already existed
# since migrations 0001/0005 and already fully supported a safe refresh —
# nothing in the schema needed to change. The bug was a guard in
# FgService::refreshSource() that stopped refreshing an item's snapshot
# forever once any fgVerified value had been entered.
#
# New/changed in this pass:
#   - api/app/src/Fg/FgService.php (changed) — fixed the snapshot guard,
#     added the standalone refreshProductionSource() action, added a
#     submit()-time pre-flight check (FG_EXCEEDS_PRODUCTION, 409) so a
#     refreshed-down snapshot can never silently post stock beyond it.
#   - api/app/src/Controllers/FgController.php (changed) — new
#     refreshSource() action.
#   - api/app/src/App.php (changed) — new route
#     POST /api/fg/{id}/refresh-source.
#   - api/_fg-uat/index.php (changed) — dedicated "Refresh Produksi
#     Terbaru" button, blocking-discrepancy panel, Filter Divisi Sumber
#     now disabled/relabeled once a batch exists.
#   - api/app/ui/pages/fg-packing.php (changed) — same additions for the
#     modern fetch/JS admin UI (existing refreshSource checkbox mechanism
#     kept working too).
#
# This ZIP contains NO changes to PO, Production semantics, Driver Claim,
# Dispatch Pool, ShipmentService, Store Receipt, Invoice preview, or User
# Management — see the byte-for-byte sanity checks below.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-phase4-fg-source-refresh"
ZIP_PATH="$DIST_DIR/amor-factory-api-phase4-fg-source-refresh-patch.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points (index.php + .htaccess, unchanged) ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- copying every existing tool, unchanged/updated, for a clean overwrite (nothing dropped) ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview _driver-uat _receive _users-uat; do
  mkdir -p "$STAGE/api/$tool"
  cp -r "$REPO_ROOT/api/$tool/." "$STAGE/api/$tool/"
done
[ -f "$STAGE/api/_fg-uat/index.php" ] || { echo "REFUSING TO BUILD: api/_fg-uat/index.php missing"; exit 1; }
if ! grep -q 'refresh_source' "$STAGE/api/_fg-uat/index.php"; then
  echo "REFUSING TO BUILD: api/_fg-uat/index.php is missing the refresh_source action — patch not applied." >&2
  exit 1
fi

echo "--- copying application (source/config-example/migrations/ui, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
cp -r "$REPO_ROOT/api/app/ui" "$STAGE/api/app/ui"
if [ -d "$STAGE/api/app/ui/assets" ]; then
  echo "REFUSING TO BUILD: api/app/ui/assets/ exists — static browser assets must live under the PUBLIC api/assets/, never inside the deny-all api/app/ tree." >&2
  exit 1
fi
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- sanity: confirm NO new migration was introduced (this is a FILES-ONLY patch, migration 0007 stays the latest) ---"
if find "$STAGE/api/app/migrations" -name '0008_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0008+ was found — this package must be files-only." >&2
  exit 1
fi

echo "--- sanity: confirm the new refresh-source route and service method are present ---"
grep -q "refresh-source" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: refresh-source route missing from App.php"; exit 1; }
grep -q "function refreshProductionSource" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: refreshProductionSource() missing from FgService.php"; exit 1; }
grep -q "function refreshSource" "$STAGE/api/app/src/Controllers/FgController.php" || { echo "REFUSING TO BUILD: refreshSource() missing from FgController.php"; exit 1; }

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, unchanged) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- copying canonical schema DDL (0001-0007, UNCHANGED — no 0008 in this package) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"
cp "$REPO_ROOT/database/schema-v1-0003-po-phase2.sql" "$STAGE/api/app/database/schema-v1-0003-po-phase2.sql"
cp "$REPO_ROOT/database/schema-v1-0004-production-phase3.sql" "$STAGE/api/app/database/schema-v1-0004-production-phase3.sql"
cp "$REPO_ROOT/database/schema-v1-0005-fg-packing-phase4.sql" "$STAGE/api/app/database/schema-v1-0005-fg-packing-phase4.sql"
cp "$REPO_ROOT/database/schema-v1-0006-do-shipment-phase5.sql" "$STAGE/api/app/database/schema-v1-0006-do-shipment-phase5.sql"
cp "$REPO_ROOT/database/schema-v1-0007-dispatch-receipt-phase55.sql" "$STAGE/api/app/database/schema-v1-0007-dispatch-receipt-phase55.sql"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Phase 4 FG Production Source Refresh Patch

FILES-ONLY INCREMENTAL patch for an existing Phase 5.5 + User Management
deployment. NO DATABASE MIGRATION in this package — fg_batch_source.
source_version and fg_item.production_actual_snapshot already existed
since migrations 0001/0005. This domain's document root is
public_html/factory/ — extract this ZIP positioned INSIDE
public_html/factory/ so it merges into your existing
public_html/factory/api/. It overwrites code files but NEVER includes
app/config/config.php, so your database credentials are untouched.

Quick facts:
- New button on an existing FG draft/reopened batch: "Refresh Produksi
  Terbaru" — safely re-pulls the latest SUBMITTED Production actuals into
  fg_item.production_actual_snapshot and fg_batch_source.source_version.
- NEVER changes FG Verified, Packed, or stock — refresh writes ZERO
  stock_ledger rows. Only Submit/Resubmit posts stock, exactly as before.
- If a refresh reveals FG Verified > the new Production Actual, the batch
  is blocked from submit (409 FG_EXCEEDS_PRODUCTION) until an admin lowers
  FG Verified — it is never auto-reduced.
- "Filter Divisi Sumber" is now disabled and relabeled once a batch
  already exists for that date/factory (it was always preview-only before
  a draft is created — this patch only makes that clearer in the UI).
- This package does NOT touch PO, Production semantics, Driver Claim,
  Dispatch Pool, ShipmentService, Store Receipt, Invoice preview, or User
  Management.
EOF

find "$STAGE" -name '.DS_Store' -delete 2>/dev/null || true
find "$STAGE" -name 'Thumbs.db' -delete 2>/dev/null || true

echo "--- sanity: confirm no config.php (real credentials) made it in ---"
if find "$STAGE" -name 'config.php' | grep -q .; then
  echo "REFUSING TO BUILD: a config.php was found in the staging tree — this must never ship." >&2
  find "$STAGE" -name 'config.php' >&2
  exit 1
fi

echo "--- sanity: confirm api/_setup/ and api/_import-master/ (retired wizards) are not reintroduced ---"
if [ -d "$STAGE/api/_setup" ] || [ -d "$STAGE/api/_import-master" ]; then
  echo "REFUSING TO BUILD: a retired wizard directory was found in the staging tree." >&2
  exit 1
fi

echo "--- sanity: confirm every prior UAT tool + UI preview is present ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview _driver-uat _receive _users-uat; do
  if [ ! -f "$STAGE/api/$tool/index.php" ]; then
    echo "REFUSING TO BUILD: api/$tool/index.php is missing — old tools must never be dropped by this package." >&2
    exit 1
  fi
done

echo "--- sanity: confirm PO/Production/Dispatch/Delivery/User business logic files are UNCHANGED byte-for-byte vs the repo ---"
for f in api/app/src/Po/PoImporter.php api/app/src/Po/PoResolver.php api/app/src/Po/PoMerger.php \
         api/app/src/Production/ProductionService.php api/app/src/Production/ProductionRepository.php \
         api/app/src/Dispatch/DispatchService.php api/app/src/Dispatch/DepartureService.php \
         api/app/src/Dispatch/ReceiptService.php api/app/src/Delivery/ShipmentService.php \
         api/app/src/Users/UserService.php api/app/src/Users/UserRepository.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then
    continue
  fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this package must not touch PO/Production/Dispatch/Driver Claim/ShipmentService/Store Receipt/User Management logic." >&2
    exit 1
  fi
done

echo "--- sanity: confirm api/app/.htaccess is STILL deny-all (the security boundary this patch must never weaken) ---"
if ! grep -q 'Require all denied' "$STAGE/api/app/.htaccess"; then
  echo "REFUSING TO BUILD: api/app/.htaccess no longer denies all HTTP access — this must never be weakened." >&2
  exit 1
fi

echo "--- sanity: confirm NO browser-facing PHP file references the deny-all api/app/ path ---"
if grep -rl 'href="/api/app/\|src="/api/app/' "$STAGE/api" --include='*.php' | grep -q .; then
  echo "REFUSING TO BUILD: a browser-facing href/src still points inside the deny-all api/app/ tree." >&2
  grep -rln 'href="/api/app/\|src="/api/app/' "$STAGE/api" --include='*.php' >&2
  exit 1
fi

echo "--- sanity: php -l every PHP file in the staging tree ---"
find "$STAGE" -name '*.php' -print0 | while IFS= read -r -d '' f; do
  php -l "$f" > /dev/null || { echo "REFUSING TO BUILD: syntax error in $f" >&2; exit 1; }
done

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
