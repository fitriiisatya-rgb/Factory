#!/usr/bin/env bash
# Builds dist/amor-factory-stok-sisa.zip — shipping follows FG packing (may
# exceed the PO), production surplus kept as Stok Sisa (Admin page, susulan
# to a store / sale to non-toko), on top of the compact-checklist release,
# on top of the Omset Non-Toko release, which itself sits on the phone-friendly release: app-like phone layout (bottom tab bar, compact top
# bar, card rows) for divisi/FG accounts, a Riwayat page for divisi/FG,
# driver login restyled to match, clearer FG labels. Contains everything
# from the E2E hardening release. No new migration (0019 is still the latest).
#
# Migration 0019 is purely additive on top of 0001-0018 (all untouched):
# three NULLable VARCHAR(150) columns on the EXISTING production_run table
# (ADD COLUMN IF NOT EXISTS, positioned AFTER updated_at). No new table.
#
# The package is a full overwrite of api/ like every earlier package, so it
# also carries everything from 0017 (Bakery Portal) and 0018 (Invoice) —
# installing this ONE zip on a server that is still at 0016 brings it
# fully up to 0019 (the _upgrade wizard applies 0017, 0018, 0019 in order).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-stok-sisa"
ZIP_PATH="$DIST_DIR/amor-factory-stok-sisa.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points (index.php + .htaccess, unchanged) ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- copying every existing tool, unchanged/updated, for a clean overwrite (nothing dropped) ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview _driver-uat _receive _users-uat _store; do
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

echo "--- sanity: confirm migration 0019 is present, and 0001-0018 are COMPLETELY untouched byte-for-byte ---"
[ -f "$STAGE/api/app/migrations/0019_production_operator_name.php" ] || { echo "REFUSING TO BUILD: migration 0019 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0020_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0020+ was found — this phase's own scope is 0019 only." >&2
  exit 1
fi
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009 0010 0011 0012 0013 0014 0015 0016 0017 0018; do
  f="$(find "$REPO_ROOT/api/app/migrations" -name "${m}_*" | head -1)"
  [ -n "$f" ] || { echo "REFUSING TO BUILD: migration $m is missing from the repo"; exit 1; }
  s="$STAGE/api/app/migrations/$(basename "$f")"
  [ -f "$s" ] || { echo "REFUSING TO BUILD: migration $m is missing from the staged package"; exit 1; }
  if ! diff -q "$f" "$s" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: migration $m differs from the repo — migrations 0001-0018 must stay byte-for-byte untouched." >&2
    exit 1
  fi
done

SQL0019="$REPO_ROOT/database/schema-v1-0019-production-operator-name.sql"
SQL0019_CODE="$(grep -vE '^[[:space:]]*--' "$SQL0019")"
echo "--- sanity: confirm migration 0019 stays additive (only ADD COLUMN IF NOT EXISTS) ---"
if echo "$SQL0019_CODE" | grep -qEi "DROP |TRUNCATE|CREATE TABLE|MODIFY |CHANGE |RENAME "; then
  echo "REFUSING TO BUILD: migration 0019 contains a destructive or non-additive statement." >&2
  exit 1
fi
if echo "$SQL0019_CODE" | grep -E "^ALTER TABLE" | grep -qvE "^ALTER TABLE (production_run|special_order_item|replacement_demand)$"; then
  echo "REFUSING TO BUILD: migration 0019 alters a table other than production_run/special_order_item/replacement_demand." >&2
  exit 1
fi
[ "$(echo "$SQL0019_CODE" | grep -c 'ADD COLUMN IF NOT EXISTS')" = "5" ] || { echo "REFUSING TO BUILD: migration 0019 must add exactly five columns, retry-safe."; exit 1; }
if echo "$SQL0019_CODE" | grep -qE "AFTER (created_by|submitted_by)[[:space:];]"; then
  echo "REFUSING TO BUILD: migration 0019 positions a column relative to a 0004-era column — it must anchor on updated_at (present since 0001)." >&2
  exit 1
fi

echo "--- sanity: confirm the operator-name gate is in _ui-preview/index.php AFTER the 403 gates, and NOT in bootstrap.php ---"
if grep -q "needsOperatorName" "$STAGE/api/app/ui/bootstrap.php"; then
  echo "REFUSING TO BUILD: the operator-name gate must never live in app/ui/bootstrap.php (it runs before per-page role checks — the REPL-AUTH-03..06 regression)." >&2
  exit 1
fi
UIIDX="$STAGE/api/_ui-preview/index.php"
GATE_LINE="$(grep -n 'Auth::needsOperatorName()' "$UIIDX" | head -1 | cut -d: -f1)"
ROLES_LINE="$(grep -nF 'isset($pageRoles[$page])' "$UIIDX" | head -1 | cut -d: -f1)"
PROD_LINE="$(grep -nF '$scopedPages !== null && !in_array' "$UIIDX" | head -1 | cut -d: -f1)"
if [ -z "$GATE_LINE" ] || [ -z "$ROLES_LINE" ] || [ -z "$PROD_LINE" ] || [ "$GATE_LINE" -le "$ROLES_LINE" ] || [ "$GATE_LINE" -le "$PROD_LINE" ]; then
  echo "REFUSING TO BUILD: _ui-preview/index.php must run the \$pageRoles 403 gate and the role-scoped allowlist 403 BEFORE the operator-name gate." >&2
  exit 1
fi

echo "--- sanity: confirm the login page widening is exactly ADMIN/PPIC/PRODUCTION/FG_PACKING with a safe ?return= ---"
grep -qF "const ALLOWED_ROLES = ['ADMIN', 'PPIC', 'PRODUCTION', 'FG_PACKING'];" "$STAGE/api/_admin-login/index.php" || { echo "REFUSING TO BUILD: _admin-login ALLOWED_ROLES is not exactly ADMIN/PPIC/PRODUCTION/FG_PACKING."; exit 1; }
grep -qF "str_starts_with(\$raw, '/api/_ui-preview/')" "$STAGE/api/_admin-login/index.php" || { echo "REFUSING TO BUILD: _admin-login safeReturnUrl() no longer restricts ?return= to /api/_ui-preview/."; exit 1; }
grep -q "hash_equals" "$STAGE/api/_admin-login/index.php" || { echo "REFUSING TO BUILD: the Siapa Anda? form lost its CSRF check."; exit 1; }
grep -qF "unset(\$_SESSION['operator_name']);" "$STAGE/api/app/src/Auth.php" || { echo "REFUSING TO BUILD: Auth no longer clears the operator name."; exit 1; }

echo "--- sanity: confirm every ADMIN-only tool still carries its OWN ADMIN role check (login widening must not widen them) ---"
for tool in _upgrade _import-po _production-uat _fg-uat _do-uat; do
  grep -qF "requireRole('ADMIN')" "$STAGE/api/$tool/index.php" || { echo "REFUSING TO BUILD: api/$tool/index.php lost its ADMIN role check."; exit 1; }
done
grep -qF "in_array('ADMIN', \$ui['roles'], true)" "$STAGE/api/_users-uat/index.php" || { echo "REFUSING TO BUILD: api/_users-uat/index.php lost its ADMIN role check."; exit 1; }

echo "--- sanity: confirm this release's own additions are present ---"
grep -q "class ErrorMessages" "$STAGE/api/app/src/ErrorMessages.php" || { echo "REFUSING TO BUILD: ErrorMessages.php (Indonesian error messages) is missing."; exit 1; }
grep -q "ErrorMessages::localize" "$STAGE/api/app/src/ErrorHandler.php" || { echo "REFUSING TO BUILD: ErrorHandler no longer localizes error messages."; exit 1; }
grep -q "FG_PACKING" "$STAGE/api/_admin-login/index.php" || { echo "REFUSING TO BUILD: FG accounts cannot log in."; exit 1; }
grep -q "buildVerifikasiPayload" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: FG auto per-store split is missing."; exit 1; }
grep -q 'id="arus-barang"' "$STAGE/api/app/ui/pages/laporan.php" || { echo "REFUSING TO BUILD: Arus Barang report is missing."; exit 1; }
grep -q 'id="omset-per-pabrik"' "$STAGE/api/app/ui/pages/laporan.php" || { echo "REFUSING TO BUILD: Omset per Pabrik report is missing."; exit 1; }
if grep -rqE "GET /api/po/current|di-generate \(migration|Nonaktifkan Import PO Wizard" "$STAGE/api/_import-po/index.php" "$STAGE/api/app/ui/pages/laporan.php"; then
  echo "REFUSING TO BUILD: developer-facing text is back on an Admin screen." >&2
  exit 1
fi

echo "--- sanity: confirm Stok Sisa + packing-based DO ship ---"
[ -f "$STAGE/api/app/src/Fg/StokSisaService.php" ] || { echo "REFUSING TO BUILD: StokSisaService is missing."; exit 1; }
[ -f "$STAGE/api/app/ui/pages/stok-sisa.php" ] || { echo "REFUSING TO BUILD: the Stok Sisa page is missing."; exit 1; }
grep -q "function packedForStore" "$STAGE/api/app/src/Delivery/DoTargetService.php" || { echo "REFUSING TO BUILD: DO no longer follows FG packing."; exit 1; }
grep -q "ApiException(400, 'STORE_FG_EXCEEDS_TARGET'" "$STAGE/api/app/src/Fg/FgService.php" && { echo "REFUSING TO BUILD: FG still caps a store at its PO."; exit 1; }
grep -q "storeCommitted(\$productId, \$factoryId, true)" "$STAGE/api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php" || { echo "REFUSING TO BUILD: special orders could take store-packed FG."; exit 1; }

echo "--- sanity: confirm the compact checklists ship ---"
grep -q "function compactList" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: Amor.compactList is missing."; exit 1; }
grep -q 'id="btn-all-sesuai"' "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: Produksi bulk Sesuai is missing."; exit 1; }
grep -q "driver-group-cb" "$STAGE/api/assets/js/driver.js" || { echo "REFUSING TO BUILD: driver per-store cards are missing."; exit 1; }
grep -q "store-issue-btn" "$STAGE/api/assets/js/store.js" || { echo "REFUSING TO BUILD: store 'Ada masalah' rows are missing."; exit 1; }

echo "--- sanity: confirm Omset Non-Toko is in Laporan ---"
grep -q 'id="omset-non-toko"' "$STAGE/api/app/ui/pages/laporan.php" || { echo "REFUSING TO BUILD: Omset Non-Toko is missing from Laporan."; exit 1; }
grep -q "so.status = 'completed'" "$STAGE/api/app/ui/pages/laporan.php" || { echo "REFUSING TO BUILD: Omset Non-Toko must count only finished (Selesai) orders."; exit 1; }

echo "--- sanity: confirm this release's phone layout + Riwayat ship ---"
[ -f "$STAGE/api/app/ui/pages/riwayat.php" ] || { echo "REFUSING TO BUILD: the Riwayat page is missing."; exit 1; }
grep -q "'riwayat'" "$STAGE/api/app/ui/layout.php" || { echo "REFUSING TO BUILD: Riwayat is not in the divisi/FG page list."; exit 1; }
grep -q "body.is-field" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: the phone card layout CSS is missing."; exit 1; }
grep -q "function labelTables" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: app.js labelTables() (phone card labels) is missing."; exit 1; }
grep -q "Auth::currentDivisionIds()" "$STAGE/api/app/ui/pages/riwayat.php" || { echo "REFUSING TO BUILD: Riwayat lost its division scoping."; exit 1; }
grep -q "Auth::currentFactoryIds()" "$STAGE/api/app/ui/pages/riwayat.php" || { echo "REFUSING TO BUILD: Riwayat lost its factory scoping."; exit 1; }

echo "--- sanity: confirm the redesigned login page's public assets ship (outside the deny-all api/app/) ---"
[ -f "$STAGE/api/assets/css/login.css" ] || { echo "REFUSING TO BUILD: api/assets/css/login.css is missing."; exit 1; }
[ -f "$STAGE/api/assets/img/fa-mascot.png" ] || { echo "REFUSING TO BUILD: api/assets/img/fa-mascot.png is missing."; exit 1; }

echo "--- sanity: confirm the existing mock Invoice preview stays byte-for-byte untouched ---"
for f in api/_ui-preview/invoice-preview.php api/app/ui/fixtures/invoice-mock.php; do
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo." >&2
    exit 1
  fi
done

echo "--- sanity: confirm NO business rule / server-side validation file OUTSIDE this phase's own scope changed byte-for-byte ---"
for f in api/app/src/Delivery/ShipmentService.php api/app/src/Delivery/DoService.php \
         api/app/src/Delivery/DoRepository.php api/app/src/Controllers/DoController.php \
         api/app/src/Dispatch/DispatchService.php api/app/src/Dispatch/ReceiptService.php \
         api/app/src/Dispatch/ReceiptRepository.php api/app/src/Controllers/ReceiptController.php \
         api/app/src/StorePortal/StorePortalService.php api/app/src/StorePortal/ReturService.php \
         api/app/src/StorePortal/MutasiService.php api/app/src/StorePortal/MutasiRepository.php \
         api/app/src/Controllers/StorePortalController.php api/app/src/Controllers/StorePortalAdminController.php \
         api/app/src/Import/PoImporter.php api/app/src/Invoice/InvoiceService.php api/app/src/Invoice/InvoiceRepository.php \
         api/app/src/Fg/FgService.php api/app/src/Fg/FgRepository.php api/app/src/Users/UserService.php \
         api/app/src/Mail/ShipmentEmailService.php api/app/src/SpecialOrder/SpecialOrderDoService.php \
         api/app/src/Services/DocumentSequenceService.php api/app/src/Idempotency.php \
         api/app/ui/pages/pengiriman.php api/app/ui/pages/konfirmasi-toko.php \
         api/app/ui/pages/delivery-order.php api/app/ui/pages/delivery-order-detail.php \
         api/app/ui/print-invoice-template.php api/assets/css/print-invoice.css; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this phase." >&2
    exit 1
  fi
done
# Auth.php, Production{Service,Repository,Controller}, produksi.php,
# layout.php, _ui-preview/index.php and _admin-login/index.php ARE this
# phase's own deliverable — checked for their specific required behavior
# above (and by OperatorLoginTest.php) instead of byte-diffed.

echo "--- copying canonical schema DDL (0001-0019) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql schema-v1-0013-repair-production-flow-completion.sql \
         schema-v1-0014-production-fg-division-rework.sql schema-v1-0015-fg-store-packing-submission.sql \
         schema-v1-0016-replacement-reject.sql schema-v1-0017-permanent-bakery-portal.sql \
         schema-v1-0018-invoice.sql schema-v1-0019-production-operator-name.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Stok Sisa + UI ringkas + Omset Non-Toko + Mobile + Riwayat (includes migration 0017-0019)

Purely additive on top of 0001-0018 — three NULLable VARCHAR(150)
columns on production_run (created_by_operator_name,
submitted_by_operator_name, last_updated_by_operator_name).

- api/_admin-login/ now admits ADMIN, PPIC and PRODUCTION accounts and
  honours ?return= (only /api/_ui-preview/... targets).
- A PRODUCTION shared division account is asked "Siapa Anda?" after
  login; the name is stamped on every production create/edit/submit and
  shown on the Produksi page. ADMIN/PPIC are never asked.
- A PRODUCTION-only account can open only Produksi (+ Demand / Task per
  Divisi), FG & Packing, DO Replacement detail and Pengaturan.
- ADMIN sidebar gains "Kelola User" (the existing api/_users-uat/ tool).

This package also contains everything from 0017 (Bakery Portal) and 0018
(Invoice); a server still at 0016 is brought to 0019 by api/_upgrade/.
EOF

find "$STAGE" -name '.DS_Store' -delete 2>/dev/null || true
find "$STAGE" -name 'Thumbs.db' -delete 2>/dev/null || true

echo "--- sanity: confirm no config.php (real credentials) made it in ---"
if find "$STAGE" -name 'config.php' | grep -q .; then
  echo "REFUSING TO BUILD: a config.php was found in the staging tree — this must never ship." >&2
  find "$STAGE" -name 'config.php' >&2
  exit 1
fi

echo "--- sanity: confirm every prior UAT tool + UI preview are present ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview _driver-uat _receive _users-uat _store; do
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

echo "--- sanity: confirm every existing evidence/attachment upload directory still ships deny-all and clean (no stray test photos) ---"
for dir in receipt-evidence retur-evidence mutasi-evidence special-order-attachment; do
  mkdir -p "$STAGE/api/uploads/$dir"
  cp "$REPO_ROOT/api/uploads/$dir/.htaccess" "$STAGE/api/uploads/$dir/.htaccess"
  if ! grep -q 'Require all denied' "$STAGE/api/uploads/$dir/.htaccess"; then
    echo "REFUSING TO BUILD: api/uploads/$dir/.htaccess must deny all direct HTTP access." >&2
    exit 1
  fi
  if find "$REPO_ROOT/api/uploads/$dir" -name '*.png' -o -name '*.jpg' 2>/dev/null | grep -q .; then
    echo "REFUSING TO BUILD: stray uploaded test evidence found in the repo's own $dir uploads directory — clean it up before building." >&2
    exit 1
  fi
done

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
