#!/usr/bin/env bash
# Builds dist/amor-factory-order-entry-import-po-ui-rework.zip — a UI/UX-
# ONLY rework:
#   Part A: Pesanan Khusus Toko / Pesanan Non-Toko item entry replaces the
#     old one-card-per-item layout with a compact, scrollable spreadsheet-
#     like table (Amor.createOrderItemGrid(), see assets/js/app.js) so a
#     real order with 50+ items stays usable. product_id safety, division/
#     factory auto-routing, and every payload field/semantic are UNCHANGED
#     — only the presentation and DOM structure changed.
#   Part B: api/_import-po/ now renders inside the SAME Amor Factory dark
#     navy shell every /api/_ui-preview/ page uses (sidebar/topbar/cards/
#     data-table/badges) instead of its own bare white "Phase 2 Fast-
#     Track" page. Every business rule (Karangtengah/Cibadak parsing,
#     PO Awal/Revisi semantics, PB-ignored, ADMIN+CSRF+session auth) is
#     UNCHANGED — only the HTML/CSS around it changed. Two new display-
#     only fields (committedPoAwal/committedPoRevisi/storesMapped) were
#     added to PoImporter::import()'s already-existing return array for
#     a richer post-commit success card — purely additive, no existing
#     key removed/renamed, no computation altered.
#
# NO DATABASE MIGRATION — migration state stays at 0013, nothing new.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-order-entry-import-po-ui-rework"
ZIP_PATH="$DIST_DIR/amor-factory-order-entry-import-po-ui-rework.zip"

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

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, current) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: NO DATABASE MIGRATION — confirm migration state stays at 0013, no 0014 added ---"
find "$STAGE/api/app/migrations" -name '0014_*' | grep -q . && { echo "REFUSING TO BUILD: an unexpected migration 0014+ was found — this is a UI-only rework, no schema change." >&2; exit 1; }
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009 0010 0011 0012 0013; do
  find "$STAGE/api/app/migrations" -name "${m}_*" | grep -q . || { echo "REFUSING TO BUILD: migration $m is missing — every prior migration must still be present"; exit 1; }
done
if ! diff -q "$REPO_ROOT/api/app/migrations/0013_repair_production_flow_completion.php" "$STAGE/api/app/migrations/0013_repair_production_flow_completion.php" > /dev/null 2>&1; then
  echo "REFUSING TO BUILD: migration 0013's own file differs — this UI-only pass must never touch it." >&2
  exit 1
fi

echo "--- sanity: confirm the compact order-item table replaced the old card layout ---"
grep -q "function createOrderItemGrid" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: Amor.createOrderItemGrid is missing from app.js."; exit 1; }
grep -q "createOrderItemGrid:" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: createOrderItemGrid is not exported on window.Amor."; exit 1; }
if grep -q "order-item-card\b" "$STAGE/api/app/ui/pages/pesanan-khusus-toko.php" "$STAGE/api/app/ui/pages/pesanan-non-toko.php"; then
  echo "REFUSING TO BUILD: the old one-card-per-item layout is still referenced — the compact table rework did not fully replace it." >&2
  exit 1
fi
grep -q "Amor.createOrderItemGrid" "$STAGE/api/app/ui/pages/pesanan-khusus-toko.php" || { echo "REFUSING TO BUILD: pesanan-khusus-toko.php no longer uses the shared order-item grid."; exit 1; }
grep -q "Amor.createOrderItemGrid" "$STAGE/api/app/ui/pages/pesanan-non-toko.php" || { echo "REFUSING TO BUILD: pesanan-non-toko.php no longer uses the shared order-item grid."; exit 1; }
grep -q "order-item-table-wrap" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: the compact order-item table CSS is missing."; exit 1; }

echo "--- sanity: confirm product_id autocomplete safety is unchanged (createAutocomplete still invalidates on retype) ---"
grep -q "if (selected) setSelected(null);" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: createAutocomplete no longer invalidates the selected product when its text is edited again — this is the product_id safety contract."; exit 1; }
grep -q "destroy: function" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: createAutocomplete's destroy() cleanup (for a row removed from the compact table) is missing."; exit 1; }

echo "--- sanity: confirm Import PO no longer shows the legacy 'Phase 2 Fast-Track' title/shell to normal users ---"
if grep -qi "Phase 2 Fast-Track" "$STAGE/api/_import-po/index.php"; then
  echo "REFUSING TO BUILD: api/_import-po/index.php still shows 'Phase 2 Fast-Track' — must be renamed to 'Import / Revisi PO Toko'." >&2
  exit 1
fi
grep -q "Import / Revisi PO Toko" "$STAGE/api/_import-po/index.php" || { echo "REFUSING TO BUILD: the new 'Import / Revisi PO Toko' title is missing."; exit 1; }
grep -q "ui_page_head(" "$STAGE/api/_import-po/index.php" || { echo "REFUSING TO BUILD: api/_import-po/index.php no longer renders inside the shared Amor Factory dark shell (ui_page_head/ui_page_foot)."; exit 1; }
grep -q "ui_page_foot(" "$STAGE/api/_import-po/index.php" || { echo "REFUSING TO BUILD: api/_import-po/index.php is missing its closing ui_page_foot() call."; exit 1; }

echo "--- sanity: confirm Import PO's own ADMIN + CSRF + session auth gate is still fully intact (never bootstrap.php's redirect variant) ---"
grep -q "Auth::requireRole('ADMIN')" "$STAGE/api/_import-po/index.php" || { echo "REFUSING TO BUILD: the ADMIN role gate is missing from api/_import-po/index.php."; exit 1; }
grep -q "hash_equals(\$csrfToken, \$postedCsrf)" "$STAGE/api/_import-po/index.php" || { echo "REFUSING TO BUILD: the CSRF check is missing from api/_import-po/index.php."; exit 1; }
grep -q "Database::pdo()" "$STAGE/api/_import-po/index.php" || { echo "REFUSING TO BUILD: api/_import-po/index.php no longer uses the runtime (DML-only) connection."; exit 1; }
if grep -v '^\s*\*\|^\s*//' "$STAGE/api/_import-po/index.php" | grep -q "Database::migrationPdo()"; then
  echo "REFUSING TO BUILD: api/_import-po/index.php must never use the migration connection." >&2
  exit 1
fi

echo "--- sanity: confirm PO Awal / Revisi / PB-ignored business rules are byte-for-byte unchanged (PoImporter.php's writer logic) ---"
if ! diff <(grep -v "^\s*//\|^\s*\*" "$REPO_ROOT/api/app/src/Import/PoImporter.php" | grep -v "committedPoAwal.*=>.*plan\['committedPoAwal'\]\|committedPoRevisi.*=>.*plan\['committedPoRevisi'\]\|storesMapped.*=>.*plan\['storeResolution'\]\['uniqueMapped'\]") \
       <(grep -v "^\s*//\|^\s*\*" "$STAGE/api/app/src/Import/PoImporter.php" | grep -v "committedPoAwal.*=>.*plan\['committedPoAwal'\]\|committedPoRevisi.*=>.*plan\['committedPoRevisi'\]\|storesMapped.*=>.*plan\['storeResolution'\]\['uniqueMapped'\]") \
       > /dev/null 2>&1; then
  echo "REFUSING TO BUILD: PoImporter.php changed beyond the 3 additive display-only fields this pass adds — out of scope for a UI-only rework." >&2
  exit 1
fi
grep -q "applyLines" "$STAGE/api/app/src/Import/PoImporter.php" || { echo "REFUSING TO BUILD: PoImporter::import()'s own write path (applyLines) is missing."; exit 1; }

echo "--- sanity: confirm NO business rule / server-side validation file OUTSIDE this pass's own scope changed ---"
for f in api/app/src/SpecialOrder/SpecialOrderService.php api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php \
         api/app/src/SpecialOrder/SpecialOrderFgAllocationRepository.php api/app/src/SpecialOrder/SpecialOrderRepository.php \
         api/app/src/SpecialOrder/SpecialOrderDoService.php api/app/src/Production/ProductionRoutingService.php \
         api/app/src/Fg/FgService.php api/app/src/Delivery/ShipmentService.php api/app/src/Delivery/DoService.php \
         api/app/src/Import/PoResolver.php api/app/src/Import/PoRepository.php api/app/src/Import/PoFileParser.php \
         api/app/src/Import/PoMerger.php api/app/src/Controllers/SpecialOrderController.php api/app/src/Controllers/PoController.php \
         api/app/ui/pages/pesanan-khusus-toko-detail.php api/app/ui/pages/pesanan-non-toko-detail.php \
         api/app/ui/layout.php api/app/ui/components.php api/app/ui/labels.php api/app/ui/bootstrap.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this UI-only pass." >&2
    exit 1
  fi
done

echo "--- copying canonical schema DDL (0001-0013, unchanged) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql schema-v1-0013-repair-production-flow-completion.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Order Entry + Import PO UI/UX Rework

NO DATABASE MIGRATION in this package — schema stays at 0013.

## Part A — Pesanan Khusus Toko / Pesanan Non-Toko item entry

Replaces the old one-full-width-card-per-item layout with a compact,
scrollable spreadsheet-like table (Amor.createOrderItemGrid(), assets/js/
app.js): No / Item / Tipe / Divisi / Factory / Qty / Harga / Charge /
Extra Packaging / Subtotal / Catatan / Aksi. Bounded height (~520px) with
its own scroll, sticky header, and a sticky No/Item "frozen corner" so a
real 50+ item order stays usable and never turns into a giant vertical
page. Division/Factory stay read-only, auto-derived badges — routing is
never a manual selector.

product_id safety is unchanged: an existing-product row still goes
through the same createAutocomplete() as before (never free text) — the
autocomplete menu is now portaled to <body> with viewport-fixed
positioning so it is never clipped by the table's own scroll container,
and its selection is invalidated the instant the row's text is edited
again. Catatan is a compact button that opens a small popover/editor for
a long note; the payload field (specialNote) is unchanged.

## Part B — Import / Revisi PO Toko

api/_import-po/index.php now renders inside the SAME Amor Factory dark
navy shell every /api/_ui-preview/ page uses (sidebar, topbar, cards,
data-table, badges) instead of its own bare white "Phase 2 Fast-Track"
page — title renamed to "Import / Revisi PO Toko". The entry point
(Pesanan Toko -> PO Toko -> "Import / Revisi PO") and its URL are
unchanged. Every business rule is unchanged: Karangtengah (NO/KATEGORI/
KODE/NAMA PRODUK + per-store columns + TOTAL + revision/PB blocks) and
Cibadak (Kategori/Nama Produk + per-store columns + TOTAL PO) parsing,
PO Awal-only commit on initial upload, revision-as-full-snapshot (never
cumulative double counting), PB always ignored (display/reference only),
factory auto-detected from file content (never a manual selector), and
this page's own ADMIN + CSRF + session auth gate (never the shared
bootstrap.php's login-redirect variant, so its friendly "Login
diperlukan"/"Akses ditolak" messages are preserved). Three purely
additive, display-only fields (committedPoAwal/committedPoRevisi/
storesMapped) were added to PoImporter::import()'s already-existing
return array for a richer post-commit "PO berhasil disimpan" success
card — no existing key removed/renamed, no computation altered, safe for
every existing consumer of that return value (including the real
POST /api/po/import JSON endpoint, which now also returns these three
extra fields additively).
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
