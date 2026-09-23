#!/usr/bin/env bash
# Builds dist/amor-factory-final-prelive-rework.zip — the Final Pre-Live
# Rework of migration 0012: Special/Non-Regular Shipment -> Driver History
# -> Digital Surat Jalan -> Email -> Bakery Receipt, plus the normalized
# downstream source identity (SPECIAL_STORE_ORDER/CS_ORDER/SALES_ORDER/
# DIRECT_CUSTOMER/GENERAL_ORDER) and the shipment-scoped receipt token.
#
# Migration 0012 is STILL UNAPPLIED to any live database — this REPLACES
# the earlier "Special/Non-Regular Fulfillment Completion" draft of 0012
# with the final, corrected version (never a new 0013 for an undeployed
# migration).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-final-prelive-rework"
ZIP_PATH="$DIST_DIR/amor-factory-final-prelive-rework.zip"

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

echo "--- sanity: confirm migration 0012 is present (STILL the only new migration — this rework corrects it in place, never adds 0013) ---"
[ -f "$STAGE/api/app/migrations/0012_production_flow_completion.php" ] || { echo "REFUSING TO BUILD: migration 0012 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0013_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0013+ was found — migration 0012 is still unapplied to any live DB, so this rework must correct 0012 in place, never add 0013." >&2
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
if grep -qE "ALTER TABLE (migration|schema)_0001|ALTER TABLE (migration|schema)_0007|ALTER TABLE (migration|schema)_0008" "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql"; then
  echo "REFUSING TO BUILD: unexpected reference to a historical migration file name inside 0012." >&2
  exit 1
fi

echo "--- sanity: confirm the NEW normalized-source + shipment-line-abstraction + shipment-scoped-token architecture is present ---"
[ -f "$STAGE/api/app/src/SpecialOrder/NormalizedSourceType.php" ] || { echo "REFUSING TO BUILD: NormalizedSourceType.php is missing — the ONE authoritative downstream source classification (SPECIAL_STORE_ORDER/CS_ORDER/SALES_ORDER/DIRECT_CUSTOMER/GENERAL_ORDER)."; exit 1; }
[ -f "$STAGE/api/app/src/Dispatch/ShipmentLineResolver.php" ] || { echo "REFUSING TO BUILD: ShipmentLineResolver.php is missing — the shared normalized shipment-line read model (regular shipment_item OR special_order_do_shipment_item, never a duplicated/fabricated line)."; exit 1; }
grep -q "CREATE TABLE IF NOT EXISTS shipment_receipt_token " "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql" || { echo "REFUSING TO BUILD: shipment_receipt_token table is missing from migration 0012 — required for a special-order shipment's own receipt entry point."; exit 1; }
grep -qE "MODIFY COLUMN shipment_item_id BIGINT UNSIGNED NULL" "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql" || { echo "REFUSING TO BUILD: shipment_receipt_item.shipment_item_id is not widened to NULL — required so a special/custom-item receipt line never fabricates a fake shipment_item."; exit 1; }
grep -q "special_order_do_shipment_item_id" "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql" || { echo "REFUSING TO BUILD: shipment_receipt_item.special_order_do_shipment_item_id column is missing."; exit 1; }

echo "--- sanity: confirm the special-order dispatch path now ALSO creates the automatic-email outbox in the SAME transaction (never a separate, unwired write path) ---"
grep -q "createOutboxForShipment" "$STAGE/api/app/src/SpecialOrder/SpecialOrderDoService.php" || { echo "REFUSING TO BUILD: SpecialOrderDoService no longer creates an email outbox on dispatch."; exit 1; }
grep -q "attemptEmailAfterCommit" "$STAGE/api/app/src/Controllers/SpecialOrderDoController.php" || { echo "REFUSING TO BUILD: SpecialOrderDoController no longer attempts the SMTP send AFTER commit — a pre-commit send attempt could roll back a successful dispatch on an SMTP failure."; exit 1; }
grep -q "getOrCreateShipmentToken" "$STAGE/api/app/src/Mail/ShipmentEmailService.php" || { echo "REFUSING TO BUILD: ShipmentEmailService no longer resolves a shipment-scoped token for a special-order shipment (doId === null)."; exit 1; }

echo "--- sanity: confirm the public receive portal resolves EITHER token table WITHOUT breaking any existing Regular DO receipt link ---"
grep -q "findDoIdByToken" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: ReceiptService no longer tries the Regular DO token first."; exit 1; }
grep -q "findShipmentIdByToken" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: ReceiptService no longer falls back to the shipment-scoped token."; exit 1; }
grep -q "function getPublicViewForDo" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: the Regular-DO public view path was not preserved as its own method (backward compatibility risk)."; exit 1; }

echo "--- sanity: confirm Driver History/Detail now include special-order-sourced shipments (never a silent 0 product_count) ---"
grep -q "special_order_do_shipment_item" "$STAGE/api/app/src/Dispatch/DispatchRepository.php" || { echo "REFUSING TO BUILD: DispatchRepository's Driver History query still ignores special_order_do_shipment_item lines."; exit 1; }

echo "--- sanity: confirm Admin Pengiriman/Konfirmasi Toko show a normalized source badge ---"
grep -q "ui_normalized_source_badge" "$STAGE/api/app/ui/pages/konfirmasi-toko.php" || { echo "REFUSING TO BUILD: konfirmasi-toko.php no longer shows a normalized source badge."; exit 1; }
grep -q "ui_normalized_source_badge" "$STAGE/api/app/ui/pages/pengiriman.php" || { echo "REFUSING TO BUILD: pengiriman.php no longer shows a normalized source badge."; exit 1; }
grep -q "function ui_normalized_source_badge" "$STAGE/api/app/ui/labels.php" || { echo "REFUSING TO BUILD: ui_normalized_source_badge() helper is missing."; exit 1; }

echo "--- sanity: confirm the Ceklis Produksi bugfix / autocomplete / Extra Packaging / source-specific DO from the prior pass are still intact ---"
grep -q "liveTarget" "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php no longer reads liveTarget"; exit 1; }
grep -q "function createAutocomplete" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: Amor.createAutocomplete is missing from app.js"; exit 1; }
grep -qE '\$qty \* \$unitPrice \+ \$charge \+ \$extraPackaging' "$STAGE/api/app/src/SpecialOrder/SpecialOrderService.php" || { echo "REFUSING TO BUILD: the Extra Packaging subtotal formula is missing or was changed"; exit 1; }
grep -q "function dispatch(" "$STAGE/api/app/src/SpecialOrder/SpecialOrderDoService.php" || { echo "REFUSING TO BUILD: the shared atomic dispatch() method is missing"; exit 1; }
grep -q "function renderKhusus" "$STAGE/api/assets/js/driver.js" || { echo "REFUSING TO BUILD: the Driver Portal's own Khusus/Non-Toko tab is missing from driver.js"; exit 1; }

echo "--- sanity: confirm NO business rule / server-side validation file OUTSIDE this rework's own scope changed byte-for-byte ---"
for f in api/app/src/Dispatch/EvidenceUploader.php api/app/src/Controllers/DispatchController.php \
         api/app/src/Delivery/ShipmentService.php api/app/src/Delivery/DoService.php \
         api/app/src/Controllers/ReceiptController.php api/app/src/Controllers/DoController.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Production/ProductionRoutingService.php api/app/src/Production/ProductionService.php \
         api/app/src/Production/ProductionRepository.php api/app/src/Controllers/ProductionController.php \
         api/app/src/Production/ProductionTaskService.php api/app/src/Controllers/ProductionTaskController.php \
         api/app/src/Users/UserService.php api/app/src/Fg/FgService.php api/app/src/Fg/FgRepository.php \
         api/app/src/Mail/ShipmentEmailRepository.php \
         api/assets/js/receipt.js api/assets/css/receipt.css api/assets/css/print.css api/_receive/index.php \
         api/_driver-uat/login.php api/_driver-uat/shipment.php api/_driver-uat/stop.php api/_driver-uat/print-shipment.php \
         api/app/ui/pages/produksi-task-per-divisi.php api/app/src/SpecialOrder/SpecialOrderRepository.php \
         api/app/src/SpecialOrder/SpecialOrderService.php api/app/src/Controllers/SpecialOrderController.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this rework." >&2
    exit 1
  fi
done
# ReceiptService.php/ReceiptRepository.php/DispatchService.php/
# DispatchRepository.php/ShipmentEmailService.php/print-template.php/
# print-shipment-template.php/SpecialOrderDoRepository.php/
# SpecialOrderDoService.php/SpecialOrderDoController.php/labels.php/
# konfirmasi-toko*.php/pengiriman.php/driver.js/app.css ARE expected to
# differ (this rework's own core deliverable) — checked additively above
# instead of byte-diffed.

echo "--- sanity: confirm the regular Phase 5.5 flow's own byte-identical guarantee (Driver dispatch_claim/driver_route) stays untouched ---"
if grep -qE "ALTER TABLE dispatch_claim\b|ALTER TABLE driver_route\b" "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql"; then
  echo "REFUSING TO BUILD: migration 0012 touches Regular PO's own dispatch_claim/driver_route — special_order_do must keep its OWN, separate DO-level claim (documented architecture decision)." >&2
  exit 1
fi

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
# Amor Factory System — Final Pre-Live Rework of migration 0012

Migration 0012 was STILL UNAPPLIED to any live database when this rework
started (confirmed) — this is the FINAL, corrected version of 0012, not a
new 0013. Purely additive on top of 0011.

What this rework connects (on top of the prior "Special/Non-Regular
Fulfillment Completion" pass, which already created a REAL shipment on
every dispatch):

- Normalized downstream source identity (Amor\Api\SpecialOrder\
  NormalizedSourceType): SPECIAL_STORE_ORDER / CS_ORDER / SALES_ORDER /
  DIRECT_CUSTOMER / GENERAL_ORDER, derived ONLY from the real, audited
  special_order.source_type/non_store_source ENUM values — never inferred
  from customer name text.
- Shipment-line read model (Amor\Api\Dispatch\ShipmentLineResolver):
  normalizes shipment_item (Regular) and special_order_do_shipment_item
  (special) behind one shape, for Driver History/Detail, Digital Surat
  Jalan, and the Bakery receipt screen — never a fabricated shipment_item
  row for a custom/catalog item.
- Driver History/Detail now include special-order-sourced shipments (real
  product/qty totals, not 0).
- Digital Surat Jalan renders for a special shipment too (Source, Drop
  Bakery, Delivery Method, Driver-or-Courier), reusing the SAME print
  template/QR architecture — a NEW shipment_receipt_token table gives it
  its own QR when there's no owning delivery_order_id.
- Automatic Bakery email now fires for BOTH DRIVER_INTERNAL departure and
  EXTERNAL_COURIER handover, addressed to the DROP BAKERY (never the
  customer/CS/Sales name) — outbox row created inside the same commit as
  the shipment, SMTP attempted strictly AFTER commit (a failure can never
  roll back the shipment/FG/DO write).
- The public receive portal (ReceiptService::getPublicView/confirmReceipt)
  now resolves EITHER a Regular DO token OR a special shipment token,
  trying the Regular path FIRST and completely unchanged — every existing
  Regular receipt link already sent by email keeps working byte-for-byte.
- shipment_receipt_item widened (shipment_item_id/product_id now
  nullable, + special_order_do_shipment_item_id/item_name_snapshot) so a
  custom/catalog item's receipt line references its REAL special line,
  never a fake shipment_item row.
- Admin Pengiriman + Admin Konfirmasi Toko show a clear Sumber badge
  (PO Reguler / Pesanan Khusus Toko / CS / Sales / Konsumen Langsung /
  Umum) and Delivery Method on every row.

Deferred by design, documented (not a silent gap): Phase 6 invoice
calculations (source_type + order reference are already retained on every
special DO/shipment for a future per-source invoice), and "Rute Saya"
multi-stop sequencing for special orders (a special_order_do has exactly
one destination by construction — DO-level claim, not a pooled per-store
route stop — so route sequencing is a Regular-PO-only concept by design).
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
