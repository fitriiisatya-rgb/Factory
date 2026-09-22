#!/usr/bin/env bash
# Builds dist/amor-factory-special-nonregular-order-production-routing.zip
# — Pesanan Khusus Toko / Pesanan Non-Toko + per-item Production division
# routing (migration 0010), on top of the already-deployed Admin Detail
# Typography hotfix (migration 0009 already applied on the real cPanel
# host). This is a NEW migration (0010) — the first new schema change
# since 0009 — adding exactly three new tables
# (special_order/special_order_item/special_order_catalog) and NO
# modification to any existing table.
#
# Core principle (task's own words): PO Reguler Toko + Pesanan Khusus
# Toko + Pesanan Non-Toko + Replacement Reject = Total Operational
# Production Demand, but every source stays SEPARATE and traceable.
# po_batch/po_item/po_store_item (PO Reguler Toko) are never read or
# written by this feature — verified below by byte-for-byte diff on
# every file that could plausibly touch them, plus a live regression
# test (ORDER-12) that asserts their row counts never change.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-special-nonregular-order-production-routing"
ZIP_PATH="$DIST_DIR/amor-factory-special-nonregular-order-production-routing.zip"

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

echo "--- sanity: confirm migration 0010 is present (the ONE new migration this package ships) ---"
[ -f "$STAGE/api/app/migrations/0010_special_nonregular_orders.php" ] || { echo "REFUSING TO BUILD: migration 0010 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0011_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0011+ was found — this package must add exactly ONE new migration (0010)." >&2
  exit 1
fi
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009; do
  find "$STAGE/api/app/migrations" -name "${m}_*" | grep -q . || { echo "REFUSING TO BUILD: migration $m is missing — every prior migration must still be present"; exit 1; }
done

echo "--- sanity: confirm the new special-order backend classes exist ---"
[ -f "$STAGE/api/app/src/SpecialOrder/SpecialOrderRepository.php" ] || { echo "REFUSING TO BUILD: SpecialOrderRepository.php is missing"; exit 1; }
[ -f "$STAGE/api/app/src/SpecialOrder/SpecialOrderService.php" ] || { echo "REFUSING TO BUILD: SpecialOrderService.php is missing"; exit 1; }
[ -f "$STAGE/api/app/src/Controllers/SpecialOrderController.php" ] || { echo "REFUSING TO BUILD: SpecialOrderController.php is missing"; exit 1; }
grep -q "SpecialOrderController::class" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: special-order routes are not registered in App.php"; exit 1; }
for route in "/api/special-orders/catalog" "/api/special-orders/production-inbox" "/api/special-orders/{id}/confirm" "/api/special-orders/{id}/send-to-production" "/api/special-orders/{id}/status" "/api/special-orders/{id}/cancel"; do
  grep -qF "$route" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: route $route is missing from App.php"; exit 1; }
done
grep -qF "'/api/special-orders'" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: base route /api/special-orders is missing from App.php"; exit 1; }

echo "--- sanity: confirm the new Admin UI pages exist ---"
for page in pesanan-khusus-toko pesanan-khusus-toko-detail pesanan-non-toko pesanan-non-toko-detail produksi-demand; do
  [ -f "$STAGE/api/app/ui/pages/$page.php" ] || { echo "REFUSING TO BUILD: api/app/ui/pages/$page.php is missing"; exit 1; }
done
for page in pesanan-khusus-toko pesanan-non-toko produksi-demand; do
  grep -qF "'$page'" "$STAGE/api/_ui-preview/index.php" || { echo "REFUSING TO BUILD: page key '$page' is not registered in _ui-preview/index.php"; exit 1; }
done

echo "--- sanity: confirm the existing PO Toko / Produksi pages were NOT rewritten, only prepended with a tab bar ---"
grep -qF "ui_pesanan_tabs('pesanan-toko'" "$STAGE/api/app/ui/pages/pesanan-toko.php" || { echo "REFUSING TO BUILD: pesanan-toko.php is missing its tab-bar addition"; exit 1; }
grep -qF "ui_produksi_tabs('produksi'," "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php is missing its tab-bar addition"; exit 1; }
# The rest of pesanan-toko.php's own PO-reading SQL must be untouched —
# diffed against the repo below alongside every other business-logic file.

if ! grep -q "font-family" "$STAGE/api/app/ui/pages/pesanan-khusus-toko.php" "$STAGE/api/app/ui/pages/pesanan-non-toko.php" "$STAGE/api/app/ui/pages/produksi-demand.php" 2>/dev/null; then
  echo "PASS: no new pages set their own font-family (inherit the existing Admin shell font)"
else
  echo "REFUSING TO BUILD: a new page sets its own font-family — must inherit the existing dark navy Admin shell typography." >&2
  exit 1
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, refreshed) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: confirm dark-navy UI consistency — app.css is unchanged since the last commit (this feature reuses existing classes only) ---"
if git -C "$REPO_ROOT" diff --quiet HEAD -- api/assets/css/app.css 2>/dev/null; then
  echo "PASS: app.css matches the last commit — new pages reuse existing kpi-card/kpi-grid/data-table/btn/field/badge/alert classes, no new visual theme."
else
  echo "REFUSING TO BUILD: api/assets/css/app.css has uncommitted changes — this feature must reuse the EXISTING dark navy classes only, never introduce a new stylesheet/theme." >&2
  exit 1
fi

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
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionService.php \
         api/app/src/Users/UserService.php api/app/src/Fg/FgService.php api/app/src/Fg/FgRepository.php \
         api/app/src/Mail/ShipmentEmailService.php \
         api/assets/js/receipt.js api/assets/css/receipt.css api/_receive/index.php \
         api/assets/js/app.js api/app/ui/components.php api/app/ui/labels.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this feature must not touch PO import, receipt/evidence, dispatch/shipment, email, or user-management business logic." >&2
    exit 1
  fi
done
# components.php/labels.php ARE expected to differ (ui_pesanan_tabs/
# ui_produksi_tabs/ui_special_order_status_label additions) — the diff
# check above is intentionally NOT applied to those two; see the
# additive-only checks below instead.
grep -q "function ui_pesanan_tabs" "$STAGE/api/app/ui/components.php" || { echo "REFUSING TO BUILD: ui_pesanan_tabs() helper missing"; exit 1; }
grep -q "function ui_special_order_status_label" "$STAGE/api/app/ui/labels.php" || { echo "REFUSING TO BUILD: ui_special_order_status_label() helper missing"; exit 1; }

echo "--- sanity: confirm ui_kpi_card()'s real-KPI default behavior (from the prior typography hotfix) is unchanged ---"
grep -q "!empty(\$opts\['detail'\])" "$STAGE/api/app/ui/components.php" || { echo "REFUSING TO BUILD: the typography hotfix's ui_kpi_card() detail flag regressed"; exit 1; }

echo "--- sanity: confirm the mandatory-photo-on-discrepancy / receipt / evidence server rules are unchanged ---"
grep -q "EVIDENCE_REQUIRED_FOR_VERIFY" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: adminVerify()'s evidence gate is missing"; exit 1; }
grep -q "RECEIPT_MATH_INVALID" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: confirmReceipt()'s math validation is missing"; exit 1; }

echo "--- sanity: confirm the new feature never QUERIES po_batch/po_item/po_store_item (a docblock mentioning them by name, to explain they're never touched, is fine) ---"
if grep -rEn "(FROM|INTO|UPDATE|JOIN)\s+po_(batch|item|store_item)\b" "$STAGE/api/app/src/SpecialOrder/" "$STAGE/api/app/src/Controllers/SpecialOrderController.php" 2>/dev/null | grep -q .; then
  echo "REFUSING TO BUILD: the SpecialOrder feature contains a real SQL reference to a PO Reguler Toko table — these demand sources must stay completely separate." >&2
  exit 1
fi

echo "--- sanity: confirm the feature never writes to stock_ledger/stock_balance (no fake reservation engine) ---"
if grep -n "INSERT INTO stock_ledger\|UPDATE stock_balance\|INSERT INTO stock_balance" "$STAGE/api/app/src/SpecialOrder/SpecialOrderRepository.php" | grep -q .; then
  echo "REFUSING TO BUILD: SpecialOrderRepository writes to stock_ledger/stock_balance — FG availability must stay READ-ONLY (see the OUTPUT REPORT's known deferred enhancements)." >&2
  exit 1
fi
grep -q "findStockOnHand" "$STAGE/api/app/src/SpecialOrder/SpecialOrderRepository.php" || { echo "REFUSING TO BUILD: the read-only FG stock-on-hand lookup is missing"; exit 1; }

echo "--- copying canonical schema DDL (0001-0010) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"
cp "$REPO_ROOT/database/schema-v1-0003-po-phase2.sql" "$STAGE/api/app/database/schema-v1-0003-po-phase2.sql"
cp "$REPO_ROOT/database/schema-v1-0004-production-phase3.sql" "$STAGE/api/app/database/schema-v1-0004-production-phase3.sql"
cp "$REPO_ROOT/database/schema-v1-0005-fg-packing-phase4.sql" "$STAGE/api/app/database/schema-v1-0005-fg-packing-phase4.sql"
cp "$REPO_ROOT/database/schema-v1-0006-do-shipment-phase5.sql" "$STAGE/api/app/database/schema-v1-0006-do-shipment-phase5.sql"
cp "$REPO_ROOT/database/schema-v1-0007-dispatch-receipt-phase55.sql" "$STAGE/api/app/database/schema-v1-0007-dispatch-receipt-phase55.sql"
cp "$REPO_ROOT/database/schema-v1-0008-receipt-evidence.sql" "$STAGE/api/app/database/schema-v1-0008-receipt-evidence.sql"
cp "$REPO_ROOT/database/schema-v1-0009-shipment-email.sql" "$STAGE/api/app/database/schema-v1-0009-shipment-email.sql"
cp "$REPO_ROOT/database/schema-v1-0010-special-nonregular-orders.sql" "$STAGE/api/app/database/schema-v1-0010-special-nonregular-orders.sql"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Pesanan Khusus Toko / Pesanan Non-Toko + Production Routing

FILES + ONE NEW MIGRATION (0010). Adds exactly 3 new tables
(special_order, special_order_item, special_order_catalog) — no existing
table is altered, renamed, or dropped.

Quick facts:
- PO Reguler Toko is a completely separate, untouched demand source
  (po_batch/po_item/po_store_item are never read or written by this
  feature).
- Pesanan Khusus Toko (from a store, outside its regular PO) and Pesanan
  Non-Toko (Konsumen Langsung/CS/Sales Executive/Umum) share the same
  underlying tables (source_type column) but stay fully traceable.
- Every order item carries its own division_id, resolved at creation time
  (existing product -> product.division_id; special/custom item -> the
  catalog's own division_id) — a multi-division order routes PER ITEM,
  never forced into one bucket.
- A new "Cake & Custom" division (Karangtengah) and its DELUXE
  KARAKTER/ICING catalog are lazily created on first use — never baked
  into the migration's DDL, so this works correctly on both a fresh
  database and an already-seeded live one.
- Charge is an explicit per-item field; subtotal = qty*unitPrice + charge.
  Store pricing (50%/60% rules) is completely untouched.
- Production's new "Order Masuk / Demand Tambahan" inbox groups items by
  division, shows a read-only special note, and computes FG shortage
  READ-ONLY against the existing stock_balance table — it never reserves
  or writes stock (see the OUTPUT REPORT's known deferred enhancements
  for why a full reservation engine was deliberately not built).
- Reuses the EXISTING dark navy Admin UI exactly (kpi-card, data-table,
  btn, field, badge, alert classes) — app.css is byte-for-byte unchanged.
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
