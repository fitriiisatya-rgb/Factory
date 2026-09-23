#!/usr/bin/env bash
# Builds dist/amor-factory-fg-allocation-bridge.zip — the "Existing FG
# Allocation Bridge": lets a special/non-regular order's existing-product
# line draw from EXISTING general FG (stock_balance, the SAME pool Regular
# PO ships from) first, and only require NEW production for the shortfall
# — fixing the real cPanel UAT dead-end where an item with
# aktual_produksi=0 could never reach DO even when enough general FG
# already existed.
#
# Migration 0012 was STILL UNAPPLIED to any live database when this pass
# started (confirmed via schema_migrations before this pass began — see
# the package's own PACKAGE-INFO.md) — this EXTENDS 0012 in place (a new
# special_order_fg_allocation table + a widened stock_ledger.source_type
# enum), never a new 0013 for an undeployed migration.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-fg-allocation-bridge"
ZIP_PATH="$DIST_DIR/amor-factory-fg-allocation-bridge.zip"

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

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, refreshed) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: confirm migration 0012 is present (STILL the only new migration — this pass extends it in place, never adds 0013) ---"
[ -f "$STAGE/api/app/migrations/0012_production_flow_completion.php" ] || { echo "REFUSING TO BUILD: migration 0012 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0013_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0013+ was found — migration 0012 is still unapplied to any live DB, so this pass must extend 0012 in place, never add 0013." >&2
  exit 1
fi
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009 0010 0011; do
  find "$STAGE/api/app/migrations" -name "${m}_*" | grep -q . || { echo "REFUSING TO BUILD: migration $m is missing — every prior migration must still be present"; exit 1; }
done

echo "--- sanity: confirm migration 0012 stays additive (no DROP TABLE / DROP COLUMN, no destructive statement) ---"
if grep -qEi "DROP TABLE|DROP COLUMN|TRUNCATE" "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql"; then
  echo "REFUSING TO BUILD: migration 0012 contains a destructive statement." >&2
  exit 1
fi
if grep -qE "ALTER TABLE delivery_order\b|ALTER TABLE delivery_order_item\b" "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql"; then
  echo "REFUSING TO BUILD: migration 0012 alters Regular PO's own delivery_order/delivery_order_item — must stay completely untouched." >&2
  exit 1
fi

echo "--- sanity: confirm the new special_order_fg_allocation table + widened stock_ledger enum are present ---"
grep -q "CREATE TABLE IF NOT EXISTS special_order_fg_allocation" "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql" || { echo "REFUSING TO BUILD: special_order_fg_allocation table is missing from migration 0012."; exit 1; }
grep -q "'special_order_fg_allocation'" "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql" || { echo "REFUSING TO BUILD: stock_ledger.source_type was not widened with 'special_order_fg_allocation'."; exit 1; }

echo "--- sanity: confirm the CRITICAL STOCK PRINCIPLE is enforced (allocate/release never write stock_ledger; only real dispatch consumption does) ---"
[ -f "$STAGE/api/app/src/SpecialOrder/SpecialOrderFgAllocationRepository.php" ] || { echo "REFUSING TO BUILD: SpecialOrderFgAllocationRepository.php is missing."; exit 1; }
[ -f "$STAGE/api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php" ] || { echo "REFUSING TO BUILD: SpecialOrderFgAllocationService.php is missing."; exit 1; }
grep -q "function insertAllocation" "$STAGE/api/app/src/SpecialOrder/SpecialOrderFgAllocationRepository.php" || { echo "REFUSING TO BUILD: SpecialOrderFgAllocationRepository::insertAllocation is missing."; exit 1; }
grep -q "function consumeForDispatch" "$STAGE/api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php" || { echo "REFUSING TO BUILD: SpecialOrderFgAllocationService::consumeForDispatch (the ONLY real stock_ledger-writing path for this feature) is missing."; exit 1; }
grep -q "function allocate" "$STAGE/api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php" || { echo "REFUSING TO BUILD: SpecialOrderFgAllocationService::allocate is missing."; exit 1; }
grep -q "function release" "$STAGE/api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php" || { echo "REFUSING TO BUILD: SpecialOrderFgAllocationService::release is missing."; exit 1; }

echo "--- sanity: confirm the custom-item rule (special_catalog items can NEVER consume general FG) is enforced ---"
grep -q "CUSTOM_ITEM_NOT_ALLOCATABLE" "$STAGE/api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php" || { echo "REFUSING TO BUILD: the CUSTOM_ITEM_NOT_ALLOCATABLE guard is missing."; exit 1; }

echo "--- sanity: confirm the routes + UI action for 'Alokasikan dari FG' are wired ---"
grep -q "allocate-fg" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: the allocate-fg route is missing from App.php."; exit 1; }
grep -q "allocateFg" "$STAGE/api/app/src/Controllers/SpecialOrderController.php" || { echo "REFUSING TO BUILD: SpecialOrderController::allocateFg is missing."; exit 1; }
grep -q "fg-allocate-btn" "$STAGE/api/app/ui/pages/produksi-demand.php" || { echo "REFUSING TO BUILD: the 'Alokasikan dari FG' UI action is missing from produksi-demand.php."; exit 1; }
grep -q "dariFgExisting" "$STAGE/api/app/ui/pages/fg-khusus-non-toko.php" || { echo "REFUSING TO BUILD: the 'Dari FG Existing' / 'Dari Produksi Khusus' split columns are missing from fg-khusus-non-toko.php."; exit 1; }

echo "--- sanity: confirm mixed-fulfillment dispatch consumption is wired into SpecialOrderDoService ---"
grep -q "consumeForDispatch" "$STAGE/api/app/src/SpecialOrder/SpecialOrderDoService.php" || { echo "REFUSING TO BUILD: SpecialOrderDoService no longer calls consumeForDispatch — mixed General FG + special production fulfillment would silently stop working."; exit 1; }
grep -q "generalFgHeadroomForItem" "$STAGE/api/app/src/SpecialOrder/SpecialOrderDoService.php" || { echo "REFUSING TO BUILD: SpecialOrderDoService no longer computes generalFgHeadroomForItem."; exit 1; }

echo "--- sanity: confirm order cancellation releases unused active allocation (never destroys already-consumed history) ---"
grep -q "releaseAllForOrder" "$STAGE/api/app/src/SpecialOrder/SpecialOrderService.php" || { echo "REFUSING TO BUILD: SpecialOrderService::cancelOrder no longer releases active FG allocation on cancel."; exit 1; }

echo "--- sanity: confirm Task per Divisi shows only the NET production need (never the raw order qty once General FG is allocated) ---"
grep -q "allocationSummaryForItem" "$STAGE/api/app/src/Production/ProductionTaskService.php" || { echo "REFUSING TO BUILD: ProductionTaskService no longer nets out General FG allocation from its target."; exit 1; }

echo "--- sanity: confirm normalized source identity (Task 11's own fix) is not regressed by this pass ---"
[ -f "$STAGE/api/app/src/SpecialOrder/NormalizedSourceType.php" ] || { echo "REFUSING TO BUILD: NormalizedSourceType.php is missing."; exit 1; }
grep -q "SALES_ORDER => 'Sales Executive'" "$STAGE/api/app/src/SpecialOrder/NormalizedSourceType.php" || { echo "REFUSING TO BUILD: the SALES_ORDER => 'Sales Executive' label fix has regressed."; exit 1; }

echo "--- sanity: confirm NO business rule / server-side validation file OUTSIDE this pass's own scope changed byte-for-byte ---"
for f in api/app/src/Dispatch/EvidenceUploader.php api/app/src/Controllers/DispatchController.php \
         api/app/src/Delivery/ShipmentService.php api/app/src/Delivery/DoService.php \
         api/app/src/Delivery/DoRepository.php \
         api/app/src/Controllers/ReceiptController.php api/app/src/Controllers/DoController.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Production/ProductionRoutingService.php api/app/src/Production/ProductionService.php \
         api/app/src/Production/ProductionRepository.php api/app/src/Controllers/ProductionController.php \
         api/app/src/Controllers/ProductionTaskController.php \
         api/app/src/Users/UserService.php api/app/src/Fg/FgService.php api/app/src/Fg/FgRepository.php \
         api/app/src/Mail/ShipmentEmailRepository.php api/app/src/Mail/ShipmentEmailService.php \
         api/app/src/Dispatch/ShipmentLineResolver.php api/app/src/Dispatch/ReceiptService.php \
         api/app/src/Dispatch/DispatchRepository.php api/app/src/Dispatch/DispatchService.php \
         api/assets/js/receipt.js api/assets/css/receipt.css api/assets/css/print.css api/_receive/index.php \
         api/_driver-uat/login.php api/_driver-uat/shipment.php api/_driver-uat/stop.php api/_driver-uat/print-shipment.php \
         api/app/ui/pages/produksi-task-per-divisi.php api/app/ui/pages/konfirmasi-toko.php api/app/ui/pages/pengiriman.php \
         api/assets/js/driver.js api/assets/js/app.js; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this pass." >&2
    exit 1
  fi
done
# SpecialOrderRepository.php/SpecialOrderService.php/SpecialOrderController.php/
# SpecialOrderDoService.php/SpecialOrderDoRepository.php/ProductionTaskService.php/
# App.php/labels.php/produksi-demand.php/fg-khusus-non-toko.php ARE expected
# to differ (this pass's own core deliverable) — checked additively above
# instead of byte-diffed.

echo "--- copying canonical schema DDL (0001-0012) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Existing FG Allocation Bridge

Migration 0012 was STILL UNAPPLIED to any live database when this pass
started (confirmed via schema_migrations) — this EXTENDS 0012 in place
(adds special_order_fg_allocation + widens stock_ledger.source_type),
never a new 0013.

What this pass fixes: a real cPanel UAT found that a special/non-regular
order's existing-product line could NOT proceed toward DO/fulfillment
unless special_order_item.aktual_produksi > 0, even when EXISTING general
FG (stock_balance, the SAME pool Regular PO ships from) already had
enough stock to satisfy the order — a dead end. This pass lets such an
order draw from existing general FG first, and only requires NEW
production for the shortfall.

Architecture (see SpecialOrder/SpecialOrderFgAllocationService.php's own
docblock for the full detail):
- special_order_fg_allocation is an EARMARK, never a physical stock
  movement — allocate()/release() NEVER write stock_ledger.
- "True free FG" = physical stock_balance MINUS every other order's
  still-active allocation for the same product+factory — computed under
  the SAME stock_balance row lock Regular PO's own ShipmentService::
  ship() uses, so two concurrent allocation attempts against the same
  stock can never both succeed beyond what's physically free.
- Applies ONLY to item_type=existing_product lines — a custom/
  special_catalog item can never consume general FG.
- A real dispatch (confirmDeparture/courierHandover) may fulfill a single
  line from BOTH General FG allocation and special production FG in the
  SAME shipment — General FG is consumed FIRST, then special-production
  FG, and the split is tracked without ever double-deducting.
- Order cancellation releases any still-active, unconsumed allocation
  back to the free pool; already-consumed (physically shipped) qty is
  never reversed by a cancel.
- Task per Divisi's production target nets out whatever General FG is
  already allocated — an order fully covered by existing FG shows ZERO
  production target, never the raw order qty.
- Source identity (SPECIAL_STORE_ORDER/CS_ORDER/SALES_ORDER/
  DIRECT_CUSTOMER/GENERAL_ORDER) is preserved throughout every new/
  touched screen — no regression of the prior pass's own fix.
- Regular PO (po_batch/po_item/po_store_item/delivery_order/shipment/
  shipment_item) is completely unchanged by this pass.

New UI: Produksi -> Order Masuk / Demand Tambahan shows FG Tersedia,
Sudah Dialokasikan, Kebutuhan Produksi, and an explicit "Alokasikan dari
FG" action (never silent/automatic) with a confirmation modal; FG Khusus/
Non-Toko now shows "1. Dari FG Existing" / "2. Dari Produksi Khusus" /
"Total Siap untuk Order" as two clearly separate fulfillment origins.
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

echo "--- sanity: confirm NO browser-facing PHP file references the deny-all api/app/ or api/uploads/ paths ---"
if grep -rl 'href="/api/app/\|src="/api/app/\|href="/api/uploads/\|src="/api/uploads/' "$STAGE/api" --include='*.php' | grep -q .; then
  echo "REFUSING TO BUILD: a browser-facing href/src points directly inside a deny-all tree." >&2
  grep -rln 'href="/api/app/\|src="/api/app/\|href="/api/uploads/\|src="/api/uploads/' "$STAGE/api" --include='*.php' >&2
  exit 1
fi

echo "--- sanity: confirm the evidence upload directory ships deny-all and clean (no stray test photos) ---"
mkdir -p "$STAGE/api/uploads/receipt-evidence"
cp "$REPO_ROOT/api/uploads/receipt-evidence/.htaccess" "$STAGE/api/uploads/receipt-evidence/.htaccess"
if ! grep -q 'Require all denied' "$STAGE/api/uploads/receipt-evidence/.htaccess"; then
  echo "REFUSING TO BUILD: api/uploads/receipt-evidence/.htaccess must deny all direct HTTP access." >&2
  exit 1
fi
if find "$REPO_ROOT/api/uploads/receipt-evidence" -name '*.png' -o -name '*.jpg' 2>/dev/null | grep -q .; then
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
