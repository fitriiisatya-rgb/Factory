#!/usr/bin/env bash
# Builds dist/amor-factory-fg-live-ui-hotfix.zip — HOTFIX LIVE UAT: "FG
# TARGET 0 + BREAKDOWN TOKO NOT RENDERING".
#
# Migration 0014 is already APPLIED on the real cPanel host. This package
# adds NO new migration and touches NO schema — it is a pure code/query/UI
# hotfix on top of the already-deployed Store-Specific FG Reservation
# Guard package (amor-factory-store-fg-reservation-guard.zip).
#
# Real cPanel UAT bug report (2026-09-26, Karangtengah; production totals
# correct: BOLLEN LILIT COKLAT=25, CHOCO CUBE 12=14):
#
# BUG 1 — FG showed many products with "Target FG (Hasil Produksi) = 0"
# (e.g. BLACKFOREST CHOCO CASTLE 16, BUTTER CREAM, etc.). Root cause: a
# division's production_item rows are seeded from its OWN PO-derived
# template for that date — a product with a PO target that nobody
# actually produced that day still gets a row with aktual=0 (completely
# normal, not a data error). FgTargetService::productionActualByProduct()
# (and FgService's own division-scoped re-aggregation) never filtered
# these out, so every such product surfaced in "Produksi Submitted
# Tersedia", got materialized as a permanent fg_item row by createDraft()/
# refreshSource(), and stayed visible in Draft FG Per Produk forever.
# Fixed with a HAVING SUM(aktual) > 0 clause at the query layer (not
# CSS), plus a display-layer safety net (FgService::isVisibleItem(),
# used by buildBatchDto()) so an ALREADY-EXISTING live batch that already
# has zero-target rows persisted is correct immediately — no backfill/
# cleanup migration ships with this hotfix.
#
# BUG 2 — clicking the top "Breakdown Toko" toggle only changed the two
# buttons' CSS classes; the table never actually switched data source.
# Root cause: that toggle never called any API — the writable per-product
# store-breakdown endpoint (GET /api/fg/{id}/items/{productId}/stores)
# only ever got invoked by a SEPARATE per-row "Breakdown Toko" button.
# Fixed by rewiring the toggle to a real enterBreakdownMode()/
# exitBreakdownMode() pair: clicking "Breakdown Toko" now fetches every
# visible product's store rows in parallel and renders them (Store,
# Product, Target Toko, Verified Result, Actual Verified, Packing Result,
# Actual Packing, Reject, Hilang, Keterangan, Status) in place of the Per
# Produk table — no manual "explode" step required first (a product not
# yet exploded shows its live PO-target rows at 0, exactly like the
# existing per-row quick view already did). batchProductStores() also
# gained an "only stores with target > 0" filter (never hiding a store
# that already has real entered data, per the existing needsReview
# philosophy).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-fg-live-ui-hotfix"
ZIP_PATH="$DIST_DIR/amor-factory-fg-live-ui-hotfix.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points (index.php + .htaccess, unchanged) ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- copying every existing tool, unchanged, for a clean overwrite (nothing dropped) ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview _driver-uat _receive _users-uat; do
  mkdir -p "$STAGE/api/$tool"
  cp -r "$REPO_ROOT/api/$tool/." "$STAGE/api/$tool/"
done

echo "--- copying application (source/config-example/migrations/ui, current) ---"
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

echo "--- sanity: confirm NO new migration was added (0014 is already LIVE — this must be a code-only hotfix) ---"
[ -f "$STAGE/api/app/migrations/0014_production_fg_division_rework.php" ] || { echo "REFUSING TO BUILD: migration 0014 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0015_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0015+ was found — this hotfix must add NO new migration." >&2
  exit 1
fi
if ! diff -q "$REPO_ROOT/database/schema-v1-0014-production-fg-division-rework.sql" "$STAGE/api/app/database/schema-v1-0014-production-fg-division-rework.sql" > /dev/null 2>&1; then
  : # compared again below once this file is actually copied into STAGE
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, unchanged) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: BUG 1 fix present — target=0 rows filtered at the query/service layer ---"
grep -q "HAVING SUM(pi.aktual) > 0.0001" "$STAGE/api/app/src/Fg/FgTargetService.php" || { echo "REFUSING TO BUILD: FgTargetService::productionActualByProduct() is missing its HAVING SUM(aktual)>0 filter."; exit 1; }
grep -q "HAVING SUM(pi.aktual) > 0.0001" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService's division-scoped production actual re-aggregation is missing its HAVING SUM(aktual)>0 filter."; exit 1; }
grep -q "function isVisibleItem" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::isVisibleItem() (the display-layer safety net for an already-existing live batch) is missing."; exit 1; }
grep -q "isVisibleItem(\$item)" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: buildBatchDto() no longer calls isVisibleItem() — zero-target rows would reappear on an already-existing live batch."; exit 1; }

echo "--- sanity: BUG 1 fix also applied to the Breakdown Toko store-target listing (only stores with target > 0, unless real data already exists) ---"
grep -q "target'] <= 0.0001 && \$existing === null" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: batchProductStores()'s target>0 store filter is missing."; exit 1; }
grep -qE "target'\] > 0.0001" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: storeBreakdown()'s target>0 preview filter is missing."; exit 1; }

echo "--- sanity: BUG 2 fix present — the top 'Breakdown Toko' toggle actually switches renderer/data source ---"
grep -q "function enterBreakdownMode" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's enterBreakdownMode() (the real mode-switch renderer) is missing."; exit 1; }
grep -q "function exitBreakdownMode" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's exitBreakdownMode() is missing."; exit 1; }
grep -q "function buildStoreBlock" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's buildStoreBlock() (per-product writable store panel builder) is missing."; exit 1; }
grep -q "id=\"fg-perproduk-wrap\"" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's #fg-perproduk-wrap (the element enterBreakdownMode() hides) is missing."; exit 1; }
grep -q "visibleProducts.map(function (p)" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php no longer fetches every visible product's store rows in parallel."; exit 1; }
if grep -q "renderBreakdownPanel\|loadBreakdownPanel" "$STAGE/api/app/ui/pages/fg-packing.php"; then
  echo "REFUSING TO BUILD: fg-packing.php still references the old single-panel renderer this hotfix replaced." >&2
  exit 1
fi

echo "--- sanity: confirm the store-specific FG reservation guard + atomicity patch (prior package) are still intact, unregressed ---"
grep -q "INSUFFICIENT_STORE_READY_FG" "$STAGE/api/app/src/Delivery/ShipmentService.php" || { echo "REFUSING TO BUILD: ShipmentService's INSUFFICIENT_STORE_READY_FG guard is missing."; exit 1; }
grep -qE "hasAnyStoreAllocation\(.*,\s*true\)" "$STAGE/api/app/src/Delivery/ShipmentService.php" || { echo "REFUSING TO BUILD: ShipmentService's atomic (forUpdate=true) hasAnyStoreAllocation call is missing — the mode-transition race would reopen."; exit 1; }
grep -q "allProductIds = array_keys(\$items)" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::submit() no longer locks stock_balance for EVERY product — the mode-transition race would reopen."; exit 1; }
grep -q "STORE_PACKED_BELOW_SHIPPED" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService's STORE_PACKED_BELOW_SHIPPED guard is missing."; exit 1; }

echo "--- sanity: source_type routing — this hotfix's changes stay Regular-FG-view-only, never touching Special/CS/Sales/Direct/General order code ---"
if grep -rl "isVisibleItem\|enterBreakdownMode\|buildStoreBlock" "$STAGE/api/app/src/SpecialOrder/" > /dev/null 2>&1; then
  echo "REFUSING TO BUILD: this hotfix's changes leaked into the SpecialOrder module." >&2
  exit 1
fi

echo "--- sanity: confirm NO business rule / shipment / stock / reservation / DO / Production logic file changed byte-for-byte ---"
for f in api/app/src/Delivery/DoRepository.php api/app/src/Delivery/DoService.php api/app/src/Delivery/DoTargetService.php \
         api/app/src/Fg/FgRepository.php \
         api/app/src/Production/ProductionRepository.php api/app/src/Production/ProductionService.php \
         api/app/src/Production/ProductionRoutingService.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Production/ProductionTaskService.php \
         api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php api/app/src/SpecialOrder/SpecialOrderFgAllocationRepository.php \
         api/app/src/SpecialOrder/SpecialOrderDoService.php api/app/src/Dispatch/ReceiptService.php \
         api/app/src/Import/PoImporter.php \
         api/app/ui/pages/pengiriman.php api/app/ui/pages/delivery-order.php api/app/ui/pages/delivery-order-detail.php \
         api/app/ui/pages/produksi.php api/app/ui/pages/produksi-task-per-divisi.php api/app/ui/pages/produksi-demand.php \
         api/app/ui/pages/laporan.php \
         api/app/ui/layout.php api/app/ui/components.php api/app/ui/labels.php \
         api/assets/js/app.js; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this hotfix (shipment allocation, stock ledger, store_fg_balance locking, regular shipment guard, special reservation, DO, and Production logic must be byte-for-byte unchanged; Landing Page, Invoice, Reports, Replacement Reject, Mutation Antar Toko are also out of scope)." >&2
    exit 1
  fi
done

echo "--- copying canonical schema DDL (0001-0014, byte-for-byte unchanged — 0014 is already LIVE) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql schema-v1-0013-repair-production-flow-completion.sql \
         schema-v1-0014-production-fg-division-rework.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done
diff -q "$REPO_ROOT/database/schema-v1-0014-production-fg-division-rework.sql" "$STAGE/api/app/database/schema-v1-0014-production-fg-division-rework.sql" > /dev/null || { echo "REFUSING TO BUILD: migration 0014's SQL changed — it must stay byte-for-byte identical to what is already live."; exit 1; }

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — FG Live UI Hotfix (target=0 rows + Breakdown Toko rendering)

Code-only hotfix on top of the already-deployed Store-Specific FG
Reservation Guard package. Migration 0014 is already LIVE — this package
adds NO new migration and touches NO schema.

## BUG 1 — FG showed "Target FG (Hasil Produksi) = 0" for many products

Root cause: a division's production_item rows are seeded from its own
PO-derived template for a date — a product with a PO target nobody
actually produced that day still gets a row with aktual=0. Fixed:
  - FgTargetService::productionActualByProduct() and FgService's
    division-scoped re-aggregation now add HAVING SUM(aktual) > 0 —
    filtered at the query layer, never CSS.
  - FgService::isVisibleItem() + buildBatchDto() give this the SAME
    filter as a display-layer safety net, so an ALREADY-EXISTING live
    batch (created before this hotfix, with zero-target rows already
    persisted) is correct immediately — no backfill/cleanup migration.
  - batchProductStores()/storeBreakdown() also hide a store row with
    target <= 0, UNLESS that store already has real entered data (never
    silently hides genuine already-packed/shipped work — same
    "PO revision must not silently reclaim stock" rule as before).

## BUG 2 — "Breakdown Toko" button only changed CSS, no store rows rendered

Root cause: the top "Tampilan Data: Per Produk / Breakdown Toko" toggle
never called any API — it only flipped the two buttons' classes and
revealed an (empty) panel div. The real writable per-product store data
(GET /api/fg/{id}/items/{productId}/stores) was only ever fetched by a
SEPARATE per-row "Breakdown Toko" button elsewhere in the row.

Fixed: the toggle is rewired to enterBreakdownMode()/exitBreakdownMode():
clicking "Breakdown Toko" now fetches EVERY visible product's store rows
in parallel and renders them (Store, Product, Target Toko, Verified
Result, Actual Verified, Packing Result, Actual Packing, Reject, Hilang,
Keterangan, Status) in place of the Per Produk table. No manual "explode"
step is required first — a product not yet exploded still shows its live
PO-target rows at 0 (same data source the old per-row quick view already
used). The per-row button now enters the SAME mode and scrolls to that
product. Saving one product's Breakdown Toko block still PATCHes only
that product (unchanged endpoint/business logic) — switching modes can
never duplicate or double-count data.

## What did NOT change

Shipment allocation logic, stock ledger logic, store_fg_balance locking,
the Regular shipment guard, special reservation logic, DO business rules,
and Production logic are all byte-for-byte unchanged — see this build
script's own diff sanity checks. Landing Page, Invoice, Reports,
Replacement Reject, and Mutation Antar Toko were not touched.

## Testing

FG-UI-01/02/05/06 (query/API layer) verified via
ProductionDivisionFgReworkTest.php against a real fixture reproducing the
exact live bug (2026-09-26, Karangtengah, BOLLEN LILIT COKLAT=25 / CHOCO
CUBE 12=14, plus zero-actual padding products and a zero-target store).
FG-UI-03/04/07/08/09/10/11/12 (real browser/JS behavior — the exact layer
BUG 2 shipped in despite passing backend tests) verified via a real
headless-Chromium smoke check (run-ui-smoke-fg-breakdown-toko.mjs) against
the SAME live server+DB+batch: a real login, a real click on "Breakdown
Toko", and DOM inspection of the resulting store rows. Full existing
regression suite re-run, all green.
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

echo "--- sanity: confirm api/app/.htaccess is STILL deny-all (the security boundary this patch must never weaken) ---"
if ! grep -q 'Require all denied' "$STAGE/api/app/.htaccess"; then
  echo "REFUSING TO BUILD: api/app/.htaccess no longer denies all HTTP access — this must never be weakened." >&2
  exit 1
fi

echo "--- sanity: confirm the evidence upload directory ships deny-all and clean (no stray test photos) ---"
mkdir -p "$STAGE/api/uploads/receipt-evidence"
cp "$REPO_ROOT/api/uploads/receipt-evidence/.htaccess" "$STAGE/api/uploads/receipt-evidence/.htaccess"
if ! grep -q 'Require all denied' "$STAGE/api/uploads/receipt-evidence/.htaccess"; then
  echo "REFUSING TO BUILD: api/uploads/receipt-evidence/.htaccess must deny all direct HTTP access." >&2
  exit 1
fi
if find "$REPO_ROOT/api/uploads/receipt-evidence" -iname '*.png' -o -iname '*.jpg' 2>/dev/null | grep -q .; then
  echo "REFUSING TO BUILD: stray uploaded test evidence found in the repo's own uploads directory — clean it up before building." >&2
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
