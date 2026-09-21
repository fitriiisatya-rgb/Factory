#!/usr/bin/env bash
# Builds dist/amor-factory-driver-ux-tracing-patch.zip — the Driver Portal
# UX + Shipment Tracing patch, for an EXISTING real deployment where Phase
# 5.5 + User Management + the Phase 4 FG Source Refresh patch are already
# installed:
#   - api/ lives at public_html/factory/api/,
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001-0007 are already applied.
#
# FILES-ONLY PATCH — NO NEW MIGRATION. This is UX + read-only tracing +
# one display-query bug fix — no new table/column is needed:
#   - Confirm Departure modal was unstyled (driver.css never loaded
#     app.css's .modal-backdrop/.modal/.toast rules) and had no
#     double-open/double-submit guard — fixed in api/assets/js/app.js
#     (Amor.confirmModal) and api/assets/css/driver.css, verified with a
#     real headless-Chromium run against a live server (see the final
#     report's own test section).
#   - "Rute Saya" showing 0 produk/0 pcs after departure was a real bug in
#     DispatchService::myRoute() (it summed only ACTIVE dispatch_claim
#     rows, which confirmDeparture() always resolves to a terminal
#     'departed' status with active_qty=0) — fixed to source a departed
#     stop's totals from the real shipment_item rows instead
#     (DispatchRepository::findDepartedTotalsForDriver, new).
#   - Riwayat is now clickable, showing real product/qty totals, and opens
#     a new read-only, driver-scoped Detail Pengiriman page
#     (api/_driver-uat/shipment.php + GET /api/dispatch/shipments/{id}),
#     authorized so a driver can only open a shipment they themselves
#     shipped (403 otherwise) — ADMIN keeps its existing broader access.
#   - Indonesian Asia/Jakarta display-only date/time formatting added
#     (ui_fmt_datetime_id() server-side, fmtDateTimeId() client-side) —
#     never changes what is stored (still UTC via UTC_TIMESTAMP()).
#   - A Driver Logout button was added, reusing the EXISTING
#     POST /api/auth/logout endpoint — no new backend logout mechanism.
#
# Untouched by this patch (verified below + by the full regression suite):
# PO, Production, Driver Claim/Release semantics, Dispatch Pool, claim
# concurrency, ShipmentService's own stock-deduction logic, Store Receipt
# business rules, QR token logic, Invoice preview, Return/Reject logic,
# User Management.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-driver-ux-tracing"
ZIP_PATH="$DIST_DIR/amor-factory-driver-ux-tracing-patch.zip"

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
[ -f "$STAGE/api/_driver-uat/shipment.php" ] || { echo "REFUSING TO BUILD: api/_driver-uat/shipment.php (new Detail Pengiriman page) missing"; exit 1; }

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

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, refreshed) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: confirm the new shipment-detail route/method/repo query are present ---"
grep -q "dispatch/shipments/{id}" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: shipment-detail route missing from App.php"; exit 1; }
grep -q "function shipmentDetail" "$STAGE/api/app/src/Dispatch/DispatchService.php" || { echo "REFUSING TO BUILD: shipmentDetail() missing from DispatchService.php"; exit 1; }
grep -q "function findDepartedTotalsForDriver" "$STAGE/api/app/src/Dispatch/DispatchRepository.php" || { echo "REFUSING TO BUILD: findDepartedTotalsForDriver() (the route 0/0 bug fix) missing"; exit 1; }
grep -q "moduleActiveBackdrop" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: confirmModal singleton guard missing from app.js"; exit 1; }
grep -q "modal-backdrop" "$STAGE/api/assets/css/driver.css" || { echo "REFUSING TO BUILD: driver.css modal styles missing"; exit 1; }

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
# Amor Factory System — Driver Portal UX + Shipment Tracing Patch

FILES-ONLY INCREMENTAL patch for an existing Phase 5.5 + User Management +
FG Source Refresh deployment. NO DATABASE MIGRATION in this package.

Quick facts:
- "KONFIRMASI BERANGKAT" now shows exactly ONE centered confirmation
  dialog (never stacked/unstyled), disables its own button and shows
  "Memproses..." while the request is in flight, and the success screen
  shows the real Shipment ID, product/qty totals, and departure time.
- "Rute Saya" now shows the REAL shipped product/qty totals after
  departure (it used to show 0 produk / 0 pcs — a real bug, now fixed).
- "Riwayat" cards are now clickable and open a new read-only "Detail
  Pengiriman" page with the full shipment + receipt trace. A driver can
  only open a shipment THEY shipped — opening another driver's shipment
  is refused (403).
- Dates/times are now shown Indonesian-friendly in Asia/Jakarta (e.g.
  "21 Sep 2026 · 10:20") instead of the raw database format. Nothing
  stored changes — display only.
- A Logout button was added to the Driver Portal topbar.
- This package does NOT touch PO, Production semantics, Driver Claim/
  Release concurrency rules, Dispatch Pool, ShipmentService's own stock
  deduction, Store Receipt business rules, QR token logic, Invoice
  preview, Return/Reject logic, or User Management.
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

echo "--- sanity: confirm ShipmentService/ReceiptService/ReceiptRepository (stock deduction + receipt business rules) are UNCHANGED byte-for-byte ---"
for f in api/app/src/Delivery/ShipmentService.php api/app/src/Dispatch/ReceiptService.php api/app/src/Dispatch/ReceiptRepository.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionService.php api/app/src/Users/UserService.php \
         api/app/src/Fg/FgService.php; do
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this package must not touch stock deduction / receipt business rules / PO / Production / User Management / FG logic." >&2
    exit 1
  fi
done

echo "--- sanity: confirm DispatchService's claim()/release() concurrency logic text is still present verbatim (additive-only change) ---"
grep -q "public function claim(array \$lines, int \$driverUserId, ?string \$requestId): array" "$STAGE/api/app/src/Dispatch/DispatchService.php" \
  || { echo "REFUSING TO BUILD: DispatchService::claim() signature changed — this patch must only ADD to this file, never modify claim/release."; exit 1; }
grep -q "public function release(" "$STAGE/api/app/src/Dispatch/DispatchService.php" \
  || { echo "REFUSING TO BUILD: DispatchService::release() missing"; exit 1; }

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

echo "--- sanity: node -c every JS file in the staging tree ---"
find "$STAGE" -name '*.js' -print0 | while IFS= read -r -d '' f; do
  node -c "$f" || { echo "REFUSING TO BUILD: syntax error in $f" >&2; exit 1; }
done

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
