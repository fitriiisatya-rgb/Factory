#!/usr/bin/env bash
# Builds dist/amor-factory-production-flow-completion.zip — migration
# 0012: Ceklis Produksi notes-field bugfix, existing-product autocomplete
# (no native datalist), Extra Packaging, the special-order Production->FG
# bridge, and source-specific DO (special_order_do) for Pesanan Khusus
# Toko / Pesanan Non-Toko.
#
# Approved business rule (verified below): DIFFERENT DEMAND SOURCES MUST
# HAVE SEPARATE DO — special_order_do is a SEPARATE, parallel table, never
# new rows in delivery_order/delivery_order_item (Regular PO's own DO
# stays byte-for-byte untouched, verified below).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-production-flow-completion"
ZIP_PATH="$DIST_DIR/amor-factory-production-flow-completion.zip"

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

echo "--- sanity: confirm migration 0012 is present (the ONE new migration this package ships) ---"
[ -f "$STAGE/api/app/migrations/0012_production_flow_completion.php" ] || { echo "REFUSING TO BUILD: migration 0012 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0013_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0013+ was found — this package must add exactly ONE new migration (0012)." >&2
  exit 1
fi
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009 0010 0011; do
  find "$STAGE/api/app/migrations" -name "${m}_*" | grep -q . || { echo "REFUSING TO BUILD: migration $m is missing — every prior migration must still be present"; exit 1; }
done

echo "--- sanity: confirm migration 0012 stays additive (no DROP TABLE / DROP COLUMN, no destructive statement) ---"
if grep -qEi "DROP TABLE|DROP COLUMN|TRUNCATE" "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql"; then
  echo "REFUSING TO BUILD: migration 0012 contains a destructive statement — this task requires no hard delete after operational confirmation." >&2
  exit 1
fi

echo "--- sanity: confirm special_order_do is a SEPARATE table (never new columns on delivery_order/delivery_order_item — Regular PO's own DO semantics stay untouched) ---"
if grep -qE "ALTER TABLE delivery_order\b" "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql"; then
  echo "REFUSING TO BUILD: migration 0012 alters delivery_order — different demand sources must never share Regular PO's own DO table/identity rules." >&2
  exit 1
fi
grep -q "CREATE TABLE IF NOT EXISTS special_order_do " "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql" || { echo "REFUSING TO BUILD: the dedicated special_order_do table is missing from the migration"; exit 1; }
grep -q "CREATE TABLE IF NOT EXISTS special_order_do_item " "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql" || { echo "REFUSING TO BUILD: the dedicated special_order_do_item table is missing from the migration"; exit 1; }

echo "--- sanity: confirm the new Production->FG bridge + source-specific DO backend classes exist and are wired in ---"
for f in api/app/src/SpecialOrder/SpecialOrderDoRepository.php api/app/src/SpecialOrder/SpecialOrderDoService.php api/app/src/Controllers/SpecialOrderDoController.php; do
  [ -f "$STAGE/$f" ] || { echo "REFUSING TO BUILD: $f is missing"; exit 1; }
done
grep -q "SpecialOrderDoController::class" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: special-order-do routes are not registered in App.php"; exit 1; }
for route in "/api/special-orders/fg-eligible" "/api/special-orders/items/{itemId}/verify-fg" "/api/special-order-do" "/api/special-order-do/{id}/ship" "/api/special-order-do/{id}/cancel"; do
  grep -qF "$route" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: route $route is missing from App.php"; exit 1; }
done
grep -q "function fgEligibleItems" "$STAGE/api/app/src/SpecialOrder/SpecialOrderService.php" || { echo "REFUSING TO BUILD: SpecialOrderService::fgEligibleItems() is missing"; exit 1; }
grep -q "function verifyItemFg" "$STAGE/api/app/src/SpecialOrder/SpecialOrderService.php" || { echo "REFUSING TO BUILD: SpecialOrderService::verifyItemFg() is missing"; exit 1; }

echo "--- sanity: confirm the FG bridge stays order-specific (never posts to stock_ledger/fg_batch — no cross-source FG mixing) ---"
if grep -n "INSERT INTO stock_ledger\|INSERT INTO fg_batch\|INSERT INTO fg_item" "$STAGE/api/app/src/SpecialOrder/SpecialOrderService.php" "$STAGE/api/app/src/SpecialOrder/SpecialOrderDoService.php" "$STAGE/api/app/src/SpecialOrder/SpecialOrderDoRepository.php" | grep -q .; then
  echo "REFUSING TO BUILD: special-order FG/DO code writes to the shared stock_ledger/fg_batch tables — this phase's architecture decision requires special-order FG to stay order-specific, never silently merged into general warehouse stock." >&2
  exit 1
fi
if grep -n "INSERT INTO shipment\b" "$STAGE/api/app/src/SpecialOrder/SpecialOrderDoService.php" "$STAGE/api/app/src/SpecialOrder/SpecialOrderDoRepository.php" | grep -q .; then
  echo "REFUSING TO BUILD: special_order_do writes to the shared shipment table — this phase keeps its own self-contained status column only (see migration 0012's own docblock item 4)." >&2
  exit 1
fi

echo "--- sanity: confirm Extra Packaging is added ONCE (never multiplied by qty) ---"
grep -qE '\$qty \* \$unitPrice \+ \$charge \+ \$extraPackaging' "$STAGE/api/app/src/SpecialOrder/SpecialOrderService.php" || { echo "REFUSING TO BUILD: the Extra Packaging subtotal formula (qty*unitPrice+charge+extraPackaging) is missing or was changed"; exit 1; }

echo "--- sanity: confirm the Ceklis Produksi bugfix uses the authoritative DTO keys (liveTarget/notes) ---"
grep -q "liveTarget" "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php no longer reads liveTarget"; exit 1; }
if grep -qE "\\\$it\['target'\]|\\\$it\['keterangan'\]" "$STAGE/api/app/ui/pages/produksi.php"; then
  echo "REFUSING TO BUILD: produksi.php still reads the old, non-existent \$it['target']/\$it['keterangan'] keys." >&2
  exit 1
fi
grep -q "notes: notesInput ? notesInput.value" "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php's collectItems() no longer sends the wire field 'notes'"; exit 1; }

echo "--- sanity: confirm the autocomplete component exists and no page reintroduces a native <datalist> product picker ---"
grep -q "function createAutocomplete" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: Amor.createAutocomplete is missing from app.js"; exit 1; }
if grep -lE 'list="p[kn]t-product-list"' "$STAGE/api/app/ui/pages/"*.php 2>/dev/null | grep -q .; then
  echo "REFUSING TO BUILD: a page still uses a native <datalist> product picker — task's own rule forbids it (unreliable on iPad/mobile)." >&2
  exit 1
fi

echo "--- sanity: confirm the money display helper exists (Rp + dot thousands separator, storage stays numeric) ---"
grep -q "function ui_fmt_money" "$STAGE/api/app/ui/bootstrap.php" || { echo "REFUSING TO BUILD: ui_fmt_money() is missing from bootstrap.php"; exit 1; }
grep -q "function fmtRupiah" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: Amor.fmtRupiah() is missing from app.js"; exit 1; }

echo "--- sanity: confirm the new FG/DO UI pages exist and are registered, and Regular FG/DO pages are only EXTENDED with a tab bar (not rewritten) ---"
for f in fg-khusus-non-toko.php delivery-order-khusus-non-toko.php delivery-order-khusus-non-toko-detail.php; do
  [ -f "$STAGE/api/app/ui/pages/$f" ] || { echo "REFUSING TO BUILD: api/app/ui/pages/$f is missing"; exit 1; }
done
for key in fg-khusus-non-toko delivery-order-khusus-non-toko delivery-order-khusus-non-toko-detail; do
  grep -qF "'$key'" "$STAGE/api/_ui-preview/index.php" || { echo "REFUSING TO BUILD: page key '$key' is not registered in _ui-preview/index.php"; exit 1; }
done
grep -qF "ui_fg_tabs('fg-packing'," "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php is missing its own new tab bar"; exit 1; }
grep -qF "ui_do_tabs('delivery-order'," "$STAGE/api/app/ui/pages/delivery-order.php" || { echo "REFUSING TO BUILD: delivery-order.php is missing its own new tab bar"; exit 1; }
grep -q "storesWithPo" "$STAGE/api/app/ui/pages/delivery-order.php" || { echo "REFUSING TO BUILD: delivery-order.php's own Regular DO logic appears to have been removed"; exit 1; }

echo "--- sanity: confirm print.css is UNCHANGED (this feature never touches print output) ---"
if git -C "$REPO_ROOT" diff --quiet HEAD -- api/assets/css/print.css 2>/dev/null; then
  echo "PASS: print.css matches the last commit — this feature never touches print output."
else
  echo "REFUSING TO BUILD: api/assets/css/print.css has uncommitted changes — out of scope for this feature." >&2
  exit 1
fi

echo "--- creating the deny-all evidence upload directory (api/uploads/receipt-evidence/) ---"
mkdir -p "$STAGE/api/uploads/receipt-evidence"
cp "$REPO_ROOT/api/uploads/receipt-evidence/.htaccess" "$STAGE/api/uploads/receipt-evidence/.htaccess"
if ! grep -q 'Require all denied' "$STAGE/api/uploads/receipt-evidence/.htaccess"; then
  echo "REFUSING TO BUILD: api/uploads/receipt-evidence/.htaccess must deny all direct HTTP access." >&2
  exit 1
fi
if find "$STAGE/api/uploads/receipt-evidence" -name '*.png' -o -name '*.jpg' | grep -q .; then
  echo "REFUSING TO BUILD: stray uploaded test evidence found in the staging tree." >&2
  exit 1
fi

echo "--- sanity: confirm NO business rule / server-side validation file (unrelated to this feature) changed byte-for-byte ---"
for f in api/app/src/Dispatch/ReceiptService.php api/app/src/Dispatch/ReceiptRepository.php \
         api/app/src/Dispatch/EvidenceUploader.php api/app/src/Dispatch/DispatchService.php \
         api/app/src/Delivery/ShipmentService.php api/app/src/Delivery/DoService.php api/app/src/Delivery/DoRepository.php \
         api/app/src/Controllers/ReceiptController.php api/app/src/Controllers/DoController.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Production/ProductionRoutingService.php api/app/src/Production/ProductionService.php \
         api/app/src/Production/ProductionRepository.php api/app/src/Controllers/ProductionController.php \
         api/app/src/Production/ProductionTaskService.php api/app/src/Controllers/ProductionTaskController.php \
         api/app/src/Users/UserService.php api/app/src/Fg/FgService.php api/app/src/Fg/FgRepository.php \
         api/app/src/Mail/ShipmentEmailService.php \
         api/assets/js/receipt.js api/assets/css/receipt.css api/_receive/index.php \
         api/app/ui/print-template.php api/app/ui/pages/produksi-task-per-divisi.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this feature must not touch PO import/target, PO routing, Regular Production, Regular Task per Divisi, receipt/evidence, dispatch/shipment, Regular DO, email, or user-management." >&2
    exit 1
  fi
done
# produksi.php/produksi-demand.php/app.js/app.css/bootstrap.php/components.php/
# fg-packing.php/delivery-order.php/SpecialOrder*.php ARE expected to differ
# (bugfix, autocomplete, money formatting, new tabs) — checked additively
# above instead of byte-diffed.

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
# Amor Factory System — Production Flow Completion (migration 0012)

ONE NEW MIGRATION (0012) — additive only:
- special_order_item gains extra_packaging + fg_verified_qty (2 columns).
- special_order_do / special_order_do_item — a NEW, SEPARATE pair of
  tables for Pesanan Khusus Toko / Pesanan Non-Toko's own DO. Regular
  PO's delivery_order/delivery_order_item are completely untouched.
- shipment.source_type gains one new ENUM value + a nullable FK column
  (schema-ready extension point, not yet wired to a write path this
  phase — special_order_do's own status column is self-contained).

Quick facts:
- Ceklis Produksi bugfix: the item Target/Catatan columns and the notes
  save path now use the authoritative DTO/wire keys (liveTarget/notes),
  fixing both a display bug and a previously-silent notes-save bug.
- Existing-product search on Pesanan Khusus Toko / Pesanan Non-Toko is
  now a real searchable dropdown (Amor.createAutocomplete) — no native
  <datalist>, product_id is always authoritative, a selection is
  invalidated the moment the text is edited again.
- Money fields display "Rp46.000" style; storage stays plain numeric.
- Extra Packaging: a new per-item manual Rupiah field, added ONCE to the
  item subtotal (never multiplied by qty).
- Special/non-regular Production -> FG: special_order_item.aktual_produksi
  (migration 0011) stays the one authoritative Actual Produksi value;
  fg_verified_qty tracks how much of it has been confirmed FG-ready.
  Snapshot semantics (same convention as Actual/Reject) — never additive.
- Special-order FG stays ORDER-SPECIFIC in this phase — it does not post
  to the shared stock_ledger/fg_batch tables, so it can never silently
  become general warehouse stock or mix with Regular PO's FG.
- DO is separated by demand source: Pesanan Khusus Toko / Pesanan
  Non-Toko get their own DOK-{date}-{seq} documents from
  special_order_do — Regular PO's own DO/KRM/{seq}/{month}/{year}
  numbering and (tanggal,store_id) identity are entirely unaffected.
- Reuses the EXISTING dark navy Admin UI; new pages are added as tabs
  next to the existing Produksi/FG/DO pages, never a redesign.
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
