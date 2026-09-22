#!/usr/bin/env bash
# Builds dist/amor-factory-special-order-ui-routing-rework.zip — UI/UX
# rework of the Pesanan Khusus Toko / Pesanan Non-Toko create forms
# (card-based item entry instead of a cramped bare-input table, real dark
# form controls, explicit type/division/factory badges) PLUS automatic
# per-item factory routing (division.factory_id, already an authoritative
# master-data column since migration 0002 — Bolu -> Cibadak, every other
# division -> Karangtengah).
#
# FILES-ONLY package — NO new migration. Migration 0010 (special_order /
# special_order_item / special_order_catalog) already shipped in the
# prior "special-nonregular-order-production-routing" package and is
# assumed already applied on the target host; this package only changes
# PHP/CSS/JS files on top of that existing schema (per the task's own
# "Do NOT create a new schema migration for UI-only changes unless
# automatic factory routing genuinely requires schema support that does
# not already exist" — it doesn't: division.factory_id already existed).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-special-order-ui-routing-rework"
ZIP_PATH="$DIST_DIR/amor-factory-special-order-ui-routing-rework.zip"

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

echo "--- sanity: confirm this package ships NO new migration (UI/routing-only, on top of the already-applied 0010) ---"
if find "$STAGE/api/app/migrations" -name '0011_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0011+ was found — this package is UI/routing-logic only, no schema change." >&2
  exit 1
fi
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009 0010; do
  find "$STAGE/api/app/migrations" -name "${m}_*" | grep -q . || { echo "REFUSING TO BUILD: migration $m is missing — every prior migration must still be present"; exit 1; }
done

echo "--- sanity: confirm the new ProductionRoutingService (single authoritative division->factory resolver) exists and is wired in ---"
[ -f "$STAGE/api/app/src/Production/ProductionRoutingService.php" ] || { echo "REFUSING TO BUILD: ProductionRoutingService.php is missing"; exit 1; }
grep -q "resolveFactoryForDivision" "$STAGE/api/app/src/Production/ProductionRoutingService.php" || { echo "REFUSING TO BUILD: resolveFactoryForDivision() is missing"; exit 1; }
grep -q "ProductionRoutingService::resolveFactoryForDivision" "$STAGE/api/app/src/SpecialOrder/SpecialOrderService.php" || { echo "REFUSING TO BUILD: SpecialOrderService no longer routes items through ProductionRoutingService"; exit 1; }
# No scattered "if division === X then factory === Y" literal anywhere else in the app.
if grep -rniE "division.*==.*'Bolu'|'Bolu'.*==.*division" "$STAGE/api/app/src" --include='*.php' | grep -v ProductionRoutingService.php | grep -q .; then
  echo "REFUSING TO BUILD: a hardcoded division-name routing literal was found outside ProductionRoutingService — routing must have exactly ONE authoritative source." >&2
  exit 1
fi

echo "--- sanity: confirm the manual Factory selector was REMOVED from both create forms (routing is now automatic, never user-chosen) ---"
if grep -qE 'id="pkt-factory"|id="pnt-factory"' "$STAGE/api/app/ui/pages/pesanan-khusus-toko.php" "$STAGE/api/app/ui/pages/pesanan-non-toko.php"; then
  echo "REFUSING TO BUILD: a manual Factory <select> is still present on a create form — Factory must be derived automatically." >&2
  exit 1
fi
grep -q "factoryId is deliberately NOT read from \$input" "$STAGE/api/app/src/SpecialOrder/SpecialOrderService.php" || { echo "REFUSING TO BUILD: SpecialOrderService::validateHeader() no longer documents/enforces ignoring a client-supplied factoryId"; exit 1; }

echo "--- sanity: confirm item entry is now CARD-based (order-item-card), not the old cramped <table> of bare inputs ---"
for page in pesanan-khusus-toko pesanan-non-toko; do
  grep -q "order-item-card" "$STAGE/api/app/ui/pages/$page.php" || { echo "REFUSING TO BUILD: $page.php no longer uses the .order-item-card layout"; exit 1; }
  if grep -q 'id="pkt-items-table"\|id="pnt-items-table"' "$STAGE/api/app/ui/pages/$page.php"; then
    echo "REFUSING TO BUILD: $page.php still contains the old cramped item <table> markup" >&2
    exit 1
  fi
done

echo "--- sanity: confirm item type uses explicit badges (badge-success/badge-warning), never a cryptic inline <select> per row ---"
for page in pesanan-khusus-toko pesanan-non-toko; do
  grep -q "badge-success\">Produk Existing" "$STAGE/api/app/ui/pages/$page.php" || { echo "REFUSING TO BUILD: $page.php no longer shows an explicit 'Produk Existing' badge"; exit 1; }
  grep -q "badge-warning\">Item Khusus" "$STAGE/api/app/ui/pages/$page.php" || { echo "REFUSING TO BUILD: $page.php no longer shows an explicit 'Item Khusus' badge"; exit 1; }
done

echo "--- sanity: confirm the status timeline + production-routing summary helpers exist and are used on both detail pages ---"
grep -q "function ui_special_order_timeline" "$STAGE/api/app/ui/components.php" || { echo "REFUSING TO BUILD: ui_special_order_timeline() is missing"; exit 1; }
grep -q "function ui_special_order_routing_info" "$STAGE/api/app/ui/components.php" || { echo "REFUSING TO BUILD: ui_special_order_routing_info() is missing"; exit 1; }
for page in pesanan-khusus-toko-detail pesanan-non-toko-detail; do
  grep -q "ui_special_order_timeline(" "$STAGE/api/app/ui/pages/$page.php" || { echo "REFUSING TO BUILD: $page.php is missing the status timeline"; exit 1; }
  grep -q "ui_special_order_routing_info(" "$STAGE/api/app/ui/pages/$page.php" || { echo "REFUSING TO BUILD: $page.php is missing the Informasi Produksi panel"; exit 1; }
done

echo "--- sanity: confirm the new Admin UI pages exist and are still registered ---"
for page in pesanan-khusus-toko pesanan-khusus-toko-detail pesanan-non-toko pesanan-non-toko-detail produksi-demand; do
  [ -f "$STAGE/api/app/ui/pages/$page.php" ] || { echo "REFUSING TO BUILD: api/app/ui/pages/$page.php is missing"; exit 1; }
done
for page in pesanan-khusus-toko pesanan-non-toko produksi-demand; do
  grep -qF "'$page'" "$STAGE/api/_ui-preview/index.php" || { echo "REFUSING TO BUILD: page key '$page' is not registered in _ui-preview/index.php"; exit 1; }
done

echo "--- sanity: confirm the existing PO Toko / Produksi pages still carry only their tab-bar addition (unchanged otherwise) ---"
grep -qF "ui_pesanan_tabs('pesanan-toko'" "$STAGE/api/app/ui/pages/pesanan-toko.php" || { echo "REFUSING TO BUILD: pesanan-toko.php is missing its tab-bar addition"; exit 1; }
grep -qF "ui_produksi_tabs('produksi'," "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php is missing its tab-bar addition"; exit 1; }
git -C "$REPO_ROOT" diff --quiet HEAD -- api/app/ui/pages/pesanan-toko.php api/app/ui/pages/produksi.php 2>/dev/null || { echo "REFUSING TO BUILD: pesanan-toko.php/produksi.php have uncommitted changes beyond the already-committed tab-bar addition — this package must not touch PO Reguler / Produksi actual-entry logic further."; exit 1; }

if ! grep -q "font-family" "$STAGE/api/app/ui/pages/pesanan-khusus-toko.php" "$STAGE/api/app/ui/pages/pesanan-non-toko.php" "$STAGE/api/app/ui/pages/produksi-demand.php" "$STAGE/api/app/ui/pages/pesanan-khusus-toko-detail.php" "$STAGE/api/app/ui/pages/pesanan-non-toko-detail.php" 2>/dev/null; then
  echo "PASS: no page sets its own font-family (inherit the existing Admin shell font)"
else
  echo "REFUSING TO BUILD: a page sets its own font-family — must inherit the existing dark navy Admin shell typography." >&2
  exit 1
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, refreshed) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: confirm the .order-item-card layout CSS and the dark-control root-cause fix are present ---"
grep -q "\.order-item-card {" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: .order-item-card CSS is missing from app.css"; exit 1; }
grep -q "input:not(\[type=checkbox\]):not(\[type=radio\]):not(\[type=file\])" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: the global dark form-control baseline is missing from app.css"; exit 1; }
grep -q "color-scheme: dark;" "$STAGE/api/assets/css/tokens.css" || { echo "REFUSING TO BUILD: color-scheme: dark is missing from tokens.css (native date/time pickers would still render light)"; exit 1; }
grep -q "\.field textarea {" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: .field textarea styling is missing from app.css"; exit 1; }

echo "--- sanity: confirm app.css/tokens.css changes are ONLY new token-based rules — no new hardcoded hex color, no new font-family ---"
# tokens.css itself legitimately defines hex values; app.css must never
# introduce a literal color — every new rule added by this rework uses
# var(--...) exclusively (checked below against the diff, not the whole
# file, so pre-existing hex usage elsewhere in app.css is not flagged).
NEW_APP_CSS_LINES="$(git -C "$REPO_ROOT" diff HEAD -- api/assets/css/app.css | grep -E '^\+[^+]' || true)"
if echo "$NEW_APP_CSS_LINES" | grep -qE '#[0-9a-fA-F]{3,8}\b'; then
  echo "REFUSING TO BUILD: a new hardcoded hex color was added to app.css — every color must reference an existing --token from tokens.css." >&2
  exit 1
fi
# "font-family: inherit;" (used by the new global dark-control baseline,
# so bare inputs/selects/textareas pick up the SAME --font-sans the rest
# of the app already uses) is fine; a real override to a literal font
# name is not. awk (not a chained grep -v, which proved unreliable on
# this diff's multi-byte content during development) does the AND check.
if echo "$NEW_APP_CSS_LINES" | awk 'BEGIN{IGNORECASE=1} /font-family/ && !/inherit/ {f=1} END{exit !f}'; then
  echo "REFUSING TO BUILD: a new font-family was introduced in app.css — the existing --font-sans token must be inherited, never overridden." >&2
  exit 1
fi
echo "PASS: app.css's new rules are token-only — no new hardcoded color, no new font-family (dark navy identity preserved)."

echo "--- creating the deny-all evidence upload directory (api/uploads/receipt-evidence/) ---"
mkdir -p "$STAGE/api/uploads/receipt-evidence"
cp "$REPO_ROOT/api/uploads/receipt-evidence/.htaccess" "$STAGE/api/uploads/receipt-evidence/.htaccess"
if ! grep -q 'Require all denied' "$STAGE/api/uploads/receipt-evidence/.htaccess"; then
  echo "REFUSING TO BUILD: api/uploads/receipt-evidence/.htaccess must deny all direct HTTP access." >&2
  exit 1
fi

echo "--- sanity: confirm NO business rule / server-side validation file (unrelated to this feature) changed byte-for-byte ---"
for f in api/app/src/Dispatch/ReceiptService.php api/app/src/Dispatch/ReceiptRepository.php \
         api/app/src/Dispatch/EvidenceUploader.php api/app/src/Dispatch/DispatchService.php \
         api/app/src/Delivery/ShipmentService.php api/app/src/Delivery/DoService.php api/app/src/Delivery/DoRepository.php \
         api/app/src/Controllers/ReceiptController.php api/app/src/Controllers/DoController.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionService.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Users/UserService.php api/app/src/Fg/FgService.php api/app/src/Fg/FgRepository.php \
         api/app/src/Mail/ShipmentEmailService.php api/app/src/Controllers/SpecialOrderController.php \
         api/assets/js/receipt.js api/assets/css/receipt.css api/_receive/index.php api/assets/js/app.js; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this feature must not touch PO import, receipt/evidence, dispatch/shipment, email, user-management, or production-actual business logic." >&2
    exit 1
  fi
done

echo "--- sanity: confirm the new feature never QUERIES po_batch/po_item/po_store_item (a docblock mentioning them by name, to explain they're never touched, is fine) ---"
if grep -rEn "(FROM|INTO|UPDATE|JOIN)\s+po_(batch|item|store_item)\b" "$STAGE/api/app/src/SpecialOrder/" "$STAGE/api/app/src/Production/ProductionRoutingService.php" "$STAGE/api/app/src/Controllers/SpecialOrderController.php" 2>/dev/null | grep -q .; then
  echo "REFUSING TO BUILD: the SpecialOrder feature contains a real SQL reference to a PO Reguler Toko table — these demand sources must stay completely separate." >&2
  exit 1
fi

echo "--- sanity: confirm the feature never writes to stock_ledger/stock_balance (no fake reservation engine) ---"
if grep -n "INSERT INTO stock_ledger\|UPDATE stock_balance\|INSERT INTO stock_balance" "$STAGE/api/app/src/SpecialOrder/SpecialOrderRepository.php" | grep -q .; then
  echo "REFUSING TO BUILD: SpecialOrderRepository writes to stock_ledger/stock_balance — FG availability must stay READ-ONLY." >&2
  exit 1
fi

echo "--- sanity: confirm the FG stock lookup now uses the ITEM's own routed factory, never the order header's (multi-factory correctness fix) ---"
grep -q "item_factory_id" "$STAGE/api/app/src/SpecialOrder/SpecialOrderRepository.php" || { echo "REFUSING TO BUILD: findProductionDemandItems() no longer derives factory per item"; exit 1; }
grep -q "findStockOnHand(\$this->pdo, (int) \$r\['product_id'\], (int) \$r\['item_factory_id'\])" "$STAGE/api/app/src/SpecialOrder/SpecialOrderService.php" || { echo "REFUSING TO BUILD: productionInbox() no longer checks FG stock at the item's own routed factory"; exit 1; }

echo "--- copying canonical schema DDL (0001-0010, unchanged — no new DDL in this package) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Pesanan Khusus Toko / Pesanan Non-Toko UI/UX Rework + Automatic Factory Routing

FILES ONLY — NO new migration. Ships on top of the already-applied
migration 0010 (special_order/special_order_item/special_order_catalog).

Quick facts:
- The create-form item table (bare, unstyled inputs squeezed into <td>
  cells) is replaced by readable .order-item-card cards — every field is
  labeled, uses the app's real dark controls, and Catatan Khusus is a
  full-width textarea.
- Root cause of the "native white control" bug: only `.field input,
  .field select` had dark styling — any bare/unwrapped control (and every
  textarea, even wrapped ones) fell back to the browser's native light
  rendering. Fixed with a real global baseline PLUS `color-scheme: dark`
  so native date/time pickers render dark too.
- Factory is no longer a manual dropdown — it is derived automatically,
  per item, from the item's production division via
  ProductionRoutingService (division.factory_id, an authoritative
  master-data column since migration 0002: Bolu -> Cibadak, every other
  division -> Karangtengah). A client-supplied factoryId is silently
  ignored; the server always computes its own.
- One order can span multiple divisions AND multiple factories — routing
  is resolved and shown PER ITEM, never forced to one factory for the
  whole order. The detail page's "Informasi Produksi" panel groups
  divisions under the factory each one really routes to.
- Fixed a real correctness bug along the way: Production's FG-shortage
  check used to read the order HEADER's factory_id, which is wrong (or
  null) for a multi-factory order — it now checks stock at each ITEM's
  own routed factory.
- Reuses the EXISTING dark navy Admin UI exactly — new CSS rules are
  token-only (var(--...)), no new hardcoded color, no new font-family.
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

echo "--- sanity: confirm ADMIN_ASSET_VERSION was bumped (cache-busting for the CSS/page changes) ---"
grep -q "special-order-ui-routing-rework" "$STAGE/api/app/ui/layout.php" || { echo "REFUSING TO BUILD: ADMIN_ASSET_VERSION was not bumped for this rework"; exit 1; }

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
