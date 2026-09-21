#!/usr/bin/env bash
# Builds dist/amor-factory-driver-navigation-logout-hotfix.zip — the Driver
# Route Navigation / History / Logout real-cPanel-UAT hotfix, for an
# EXISTING deployment where Phase 5.5 + User Management + FG Source
# Refresh + the Driver UX/Shipment Tracing patch are already installed:
#   - api/ lives at public_html/factory/api/,
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001-0007 are already applied.
#
# FILES-ONLY PATCH — NO NEW MIGRATION. Real-UAT report (DO
# DO/KRM/004/IX/2026, Bakery Abdul Gani, Driver A, 2026-09-05):
#   - A departed "Rute Saya" stop opened "Konfirmasi Berangkat" (which
#     correctly says "Tidak ada klaim aktif Anda untuk toko ini." once
#     every claim has resolved — not a bug in that screen, just the wrong
#     destination). Fixed: myRoute() now reports each departed stop's real
#     shipmentIds (DispatchRepository::findDepartedTotalsForDriver,
#     extended) so the route card links straight to Detail Pengiriman
#     (exactly one shipment) or a new chooser page (more than one — e.g.
#     MAIN + PASTRY under the same DO) via the new
#     GET /api/dispatch/route/stops/{storeId}/shipments endpoint
#     (DispatchService::stopShipments(), new). stop.php's own "no active
#     claim" fallback (reached only via a stale/bookmarked URL now) also
#     gained a contextual "Pengiriman ini sudah diberangkatkan." message
#     + a real link, instead of a dead end.
#   - Riwayat cards and the Logout button were re-audited: their driver.js
#     click-handling code was ALREADY correct in this repo (verified with
#     a real headless-Chromium run, see the final report's own test
#     section) — the most likely real-cPanel explanation is a stale
#     deployed/cached copy of driver.js/driver.css from before the prior
#     patch. Hardening: api/_driver-uat/bootstrap.php now appends a
#     DRIVER_ASSET_VERSION cache-busting query string to every CSS/JS URL
#     it serves, forcing a fresh fetch regardless of a skipped-file
#     extract or a stubborn mobile browser cache.
#
# Untouched by this patch (verified below + by the full regression
# suite): ShipmentService's own stock-deduction logic, Dispatch Claim
# concurrency (claim()/release()), Store Receipt business rules, QR token
# logic, PO/Production/FG business logic, User Management, Invoice
# preview.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-driver-nav-logout-hotfix"
ZIP_PATH="$DIST_DIR/amor-factory-driver-navigation-logout-hotfix.zip"

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
[ -f "$STAGE/api/_driver-uat/shipment.php" ] || { echo "REFUSING TO BUILD: api/_driver-uat/shipment.php missing"; exit 1; }
grep -q "data-store-id" "$STAGE/api/_driver-uat/shipment.php" || { echo "REFUSING TO BUILD: shipment.php missing the new storeId/tanggal chooser mode"; exit 1; }
grep -q "DRIVER_ASSET_VERSION" "$STAGE/api/_driver-uat/bootstrap.php" || { echo "REFUSING TO BUILD: bootstrap.php missing the cache-busting version constant"; exit 1; }

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

echo "--- sanity: confirm the new navigation/chooser route, service method, and repo query are present ---"
grep -q "route/stops/{storeId}/shipments" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: stopShipments route missing from App.php"; exit 1; }
grep -q "function stopShipments" "$STAGE/api/app/src/Controllers/DispatchController.php" || { echo "REFUSING TO BUILD: DispatchController::stopShipments missing"; exit 1; }
grep -q "function stopShipments" "$STAGE/api/app/src/Dispatch/DispatchService.php" || { echo "REFUSING TO BUILD: DispatchService::stopShipments missing"; exit 1; }
grep -q "function findShipmentsForDriverStoreDate" "$STAGE/api/app/src/Dispatch/DispatchRepository.php" || { echo "REFUSING TO BUILD: findShipmentsForDriverStoreDate missing"; exit 1; }
grep -q "shipmentIds" "$STAGE/api/app/src/Dispatch/DispatchService.php" || { echo "REFUSING TO BUILD: myRoute() shipmentIds field missing"; exit 1; }
grep -q "renderShipmentChooser" "$STAGE/api/assets/js/driver.js" || { echo "REFUSING TO BUILD: renderShipmentChooser missing from driver.js"; exit 1; }
grep -q "Pengiriman ini sudah diberangkatkan" "$STAGE/api/assets/js/driver.js" || { echo "REFUSING TO BUILD: friendly departed-stop fallback message missing from driver.js"; exit 1; }

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
# Amor Factory System — Driver Route Navigation / History / Logout Hotfix

FILES-ONLY INCREMENTAL patch for an existing Phase 5.5 + User Management +
FG Source Refresh + Driver UX/Shipment Tracing deployment. NO DATABASE
MIGRATION in this package.

Quick facts:
- A departed "Rute Saya" stop now opens Detail Pengiriman directly (one
  shipment) or a "Pengiriman untuk <toko>" chooser (more than one — e.g.
  MAIN + PASTRY) — it never again opens "Konfirmasi Berangkat" for a
  stop with nothing left to confirm.
- Riwayat cards and the Logout button were re-verified to already work
  correctly in this code (real headless-browser click test) — this
  package additionally cache-busts driver.css/driver.js/app.js so a
  browser (or a partial extract) can never keep serving stale copies.
- If a driver opens an old departure link after the shipment already
  went out, the screen now says "Pengiriman ini sudah diberangkatkan."
  with a real "Lihat Detail Pengiriman" button instead of a dead-end
  "Tidak ada klaim aktif" message.
- This package does NOT touch stock deduction, Dispatch Claim/Release
  concurrency, Store Receipt business rules, QR token logic, PO/
  Production/FG business logic, User Management, or Invoice preview.
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

echo "--- sanity: confirm ShipmentService/ReceiptService/ReceiptRepository (stock deduction + receipt business rules) and PO/Production/FG/User Mgmt are UNCHANGED byte-for-byte ---"
for f in api/app/src/Delivery/ShipmentService.php api/app/src/Dispatch/ReceiptService.php api/app/src/Dispatch/ReceiptRepository.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionService.php api/app/src/Users/UserService.php \
         api/app/src/Fg/FgService.php; do
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this package must not touch stock deduction / receipt business rules / PO / Production / User Management / FG logic." >&2
    exit 1
  fi
done

echo "--- sanity: confirm DispatchService's claim()/release() concurrency logic signatures are still present verbatim (additive-only change) ---"
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
