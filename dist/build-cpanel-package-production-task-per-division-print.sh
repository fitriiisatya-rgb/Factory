#!/usr/bin/env bash
# Builds dist/amor-factory-production-task-per-division-print.zip —
# "Task per Divisi" (Production's consolidated task list across PO
# Reguler + Pesanan Khusus Toko + Pesanan Non-Toko + Replacement Reject),
# Production Actual/Reject entry, and A4 landscape print (Print Divisi
# Ini / Print Semua Divisi).
#
# This is a NEW migration (0011) — additive only. production_item.reject
# already existed since the ORIGINAL 0001 schema (only its write path was
# missing); the ONE new schema change is special_order_item.
# aktual_produksi/reject_produksi (see the .sql file's own docblock).
#
# Core principle (task's own words): Target=required good output,
# Actual=GOOD output only, Reject=tracked separately, Sisa=max(0,
# Target-Actual) — NEVER Target-(Actual+Reject). Verified below by a live
# regression test (TASK-12/13) plus a byte-for-byte diff on every
# business-logic file this feature must not touch.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-production-task-per-division-print"
ZIP_PATH="$DIST_DIR/amor-factory-production-task-per-division-print.zip"

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

echo "--- sanity: confirm migration 0011 is present (the ONE new migration this package ships) ---"
[ -f "$STAGE/api/app/migrations/0011_production_task_per_division.php" ] || { echo "REFUSING TO BUILD: migration 0011 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0012_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0012+ was found — this package must add exactly ONE new migration (0011)." >&2
  exit 1
fi
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009 0010; do
  find "$STAGE/api/app/migrations" -name "${m}_*" | grep -q . || { echo "REFUSING TO BUILD: migration $m is missing — every prior migration must still be present"; exit 1; }
done

echo "--- sanity: confirm the migration ONLY adds special_order_item columns (production_item.reject already existed — no new table, no ALTER on any other table) ---"
grep -q "ALTER TABLE special_order_item" "$STAGE/api/app/database/schema-v1-0011-production-task-per-division.sql" 2>/dev/null || \
  grep -q "ALTER TABLE special_order_item" "$REPO_ROOT/database/schema-v1-0011-production-task-per-division.sql" || { echo "REFUSING TO BUILD: migration 0011 no longer alters special_order_item"; exit 1; }
if grep -qE "^\s*CREATE TABLE" "$REPO_ROOT/database/schema-v1-0011-production-task-per-division.sql"; then
  echo "REFUSING TO BUILD: migration 0011 creates a new table — this feature was designed to need none (production_item.reject already existed, special_order_item just gains 2 columns)." >&2
  exit 1
fi

echo "--- sanity: confirm the new Production Task backend classes exist and are wired in ---"
[ -f "$STAGE/api/app/src/Production/ProductionTaskService.php" ] || { echo "REFUSING TO BUILD: ProductionTaskService.php is missing"; exit 1; }
[ -f "$STAGE/api/app/src/Controllers/ProductionTaskController.php" ] || { echo "REFUSING TO BUILD: ProductionTaskController.php is missing"; exit 1; }
grep -q "ProductionTaskController::class" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: production-task routes are not registered in App.php"; exit 1; }
for route in "/api/production-tasks/factory" "/api/production-tasks" "/api/special-orders/{id}/actual"; do
  grep -qF "$route" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: route $route is missing from App.php"; exit 1; }
done

echo "--- sanity: confirm Task per Divisi is READ-ONLY (GET-only controller — no second Actual-entry source for PO Reguler) ---"
if grep -qE "Idempotency::handle|Auth::requireRole" "$STAGE/api/app/src/Controllers/ProductionTaskController.php"; then
  echo "REFUSING TO BUILD: ProductionTaskController has a mutating action — Task per Divisi must stay a pure read view; PO Reguler is edited only via the existing Ceklis Produksi endpoint." >&2
  exit 1
fi
grep -q "'editable' =>" "$STAGE/api/app/src/Production/ProductionTaskService.php" || { echo "REFUSING TO BUILD: the task DTO no longer distinguishes editable (special-order) rows from read-only (PO Reguler) rows"; exit 1; }
grep -q "self::SOURCE_PO_REGULER" "$STAGE/api/app/src/Production/ProductionTaskService.php" || { echo "REFUSING TO BUILD: PO Reguler source constant is missing"; exit 1; }

echo "--- sanity: confirm Sisa = Target - Actual (never Target - (Actual + Reject)) ---"
if grep -nE "max\(0(\.0)?,\s*\\\$target\s*-\s*\(\\\$aktual\s*\+\s*\\\$reject\)\)" "$STAGE/api/app/src/Production/ProductionTaskService.php" | grep -q .; then
  echo "REFUSING TO BUILD: Sisa formula appears to subtract Reject from Target — task's own explicit rule forbids this." >&2
  exit 1
fi
grep -q "max(0.0, \$target - \$aktual)" "$STAGE/api/app/src/Production/ProductionTaskService.php" || { echo "REFUSING TO BUILD: the correct Sisa = max(0, Target - Actual) formula is missing"; exit 1; }

echo "--- sanity: confirm the new Admin UI pages + print pages exist and are registered ---"
[ -f "$STAGE/api/app/ui/pages/produksi-task-per-divisi.php" ] || { echo "REFUSING TO BUILD: produksi-task-per-divisi.php is missing"; exit 1; }
grep -qF "'produksi-task-per-divisi'" "$STAGE/api/_ui-preview/index.php" || { echo "REFUSING TO BUILD: page key 'produksi-task-per-divisi' is not registered in _ui-preview/index.php"; exit 1; }
[ -f "$STAGE/api/_ui-preview/print-production-task.php" ] || { echo "REFUSING TO BUILD: print-production-task.php is missing"; exit 1; }
[ -f "$STAGE/api/_ui-preview/print-production-task-bulk.php" ] || { echo "REFUSING TO BUILD: print-production-task-bulk.php is missing"; exit 1; }
[ -f "$STAGE/api/app/ui/print-production-task-template.php" ] || { echo "REFUSING TO BUILD: print-production-task-template.php is missing"; exit 1; }

echo "--- sanity: confirm the print pages use A4 LANDSCAPE (readability for 9 columns) and stay printer-friendly ---"
grep -q "size: A4 landscape" "$STAGE/api/_ui-preview/print-production-task.php" || { echo "REFUSING TO BUILD: print-production-task.php is missing the A4 landscape override"; exit 1; }
grep -q "size: A4 landscape" "$STAGE/api/_ui-preview/print-production-task-bulk.php" || { echo "REFUSING TO BUILD: print-production-task-bulk.php is missing the A4 landscape override"; exit 1; }
if grep -qE "app-shell|class=\"app" "$STAGE/api/_ui-preview/print-production-task.php" "$STAGE/api/_ui-preview/print-production-task-bulk.php"; then
  echo "REFUSING TO BUILD: a print page references the dark Admin shell — print output must always be plain white/black, never dark." >&2
  exit 1
fi

echo "--- sanity: confirm the existing Ceklis Produksi / Order Masuk pages were only EXTENDED (tab bar + Reject column), not rewritten ---"
grep -qF "ui_produksi_tabs('produksi'," "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php is missing its own tab-bar (should have been there already)"; exit 1; }
grep -q "data-field=\"reject\"" "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php is missing the new Reject Produksi input column"; exit 1; }
grep -qF "'Task per Divisi'" "$STAGE/api/app/ui/components.php" || { echo "REFUSING TO BUILD: ui_produksi_tabs() is missing the new 'Task per Divisi' tab"; exit 1; }

if ! grep -q "font-family" "$STAGE/api/app/ui/pages/produksi-task-per-divisi.php" 2>/dev/null; then
  echo "PASS: the new page sets no font-family of its own (inherits the existing Admin shell font)"
else
  echo "REFUSING TO BUILD: produksi-task-per-divisi.php sets its own font-family — must inherit the existing dark navy Admin shell typography." >&2
  exit 1
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, refreshed) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: confirm dark-navy UI consistency — app.css/print.css are unchanged since the last commit (this feature reuses existing classes/print styles only) ---"
if git -C "$REPO_ROOT" diff --quiet HEAD -- api/assets/css/app.css api/assets/css/print.css 2>/dev/null; then
  echo "PASS: app.css/print.css match the last commit — new pages reuse existing kpi-card/data-table/btn/field/badge classes and the existing print.css document system, no new visual theme."
else
  echo "REFUSING TO BUILD: api/assets/css/app.css or print.css has uncommitted changes — this feature must reuse the EXISTING dark navy classes and the EXISTING print document system only." >&2
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
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Production/ProductionRoutingService.php \
         api/app/src/Users/UserService.php api/app/src/Fg/FgService.php api/app/src/Fg/FgRepository.php \
         api/app/src/Mail/ShipmentEmailService.php \
         api/assets/js/receipt.js api/assets/css/receipt.css api/_receive/index.php api/assets/js/app.js \
         api/app/ui/print-template.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this feature must not touch PO import/target, PO routing, receipt/evidence, dispatch/shipment, email, user-management, or the existing DO print template." >&2
    exit 1
  fi
done
# components.php/labels.php/ProductionRepository.php/ProductionService.php/
# SpecialOrderRepository.php/SpecialOrderService.php/SpecialOrderController.php
# ARE expected to differ (new tab/badge helpers, reject write path, actual/
# reject entry endpoint) — checked additively above instead of byte-diffed.

echo "--- sanity: confirm the new feature never QUERIES po_batch/po_item/po_store_item for WRITES (read-only target aggregation reuses the existing ProductionTargetService, never writes PO) ---"
if grep -rEn "(INSERT INTO|UPDATE|DELETE FROM)\s+po_(batch|item|store_item)\b" "$STAGE/api/app/src/Production/ProductionTaskService.php" "$STAGE/api/app/src/Controllers/ProductionTaskController.php" 2>/dev/null | grep -q .; then
  echo "REFUSING TO BUILD: Task per Divisi writes to a PO Reguler Toko table — production actual must NEVER mutate PO target." >&2
  exit 1
fi

echo "--- sanity: confirm the feature never writes to stock_ledger/stock_balance (no new FG posting logic) ---"
if grep -n "INSERT INTO stock_ledger\|UPDATE stock_balance\|INSERT INTO stock_balance" "$STAGE/api/app/src/Production/ProductionTaskService.php" "$STAGE/api/app/src/Controllers/ProductionTaskController.php" | grep -q .; then
  echo "REFUSING TO BUILD: Task per Divisi writes to stock_ledger/stock_balance — this feature must stay production-actual/reject only." >&2
  exit 1
fi

echo "--- copying canonical schema DDL (0001-0011) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Task per Divisi + Production Actual/Reject + Print

ONE NEW MIGRATION (0011) — additive only. Adds exactly 2 new columns
(special_order_item.aktual_produksi/reject_produksi) — no table is
created, altered elsewhere, renamed, or dropped.
production_item.reject already existed since the very first schema; this
package only wires up its write path (Ceklis Produksi's own PATCH
endpoint), it does not add a column for it.

Quick facts:
- New "Task per Divisi" tab on Produksi consolidates PO Reguler + Pesanan
  Khusus Toko + Pesanan Non-Toko demand into one read-only task list per
  division — every source stays traceable (badge + reference), never
  merged into an opaque total. Replacement Reject is listed as a source
  but shows zero rows until that module exists (never fabricated).
- Target=required good output, Actual=GOOD output only, Reject=tracked
  separately, Sisa=max(0, Target-Actual) — Reject is NEVER subtracted
  from Sisa.
- PO Reguler rows are READ-ONLY in Task per Divisi (edited only via the
  existing Ceklis Produksi, which now also has a Reject Produksi input
  column next to Actual). Pesanan Khusus/Non-Toko rows ARE editable here
  (their only home for actual/reject entry) via a new
  POST /api/special-orders/{id}/actual endpoint.
- Print Divisi Ini / Print Semua Divisi: A4 LANDSCAPE, printer-friendly
  (white background, black text, no dark theme), one division per page
  for "Semua Divisi". Physical backup only — never a second source of
  truth; the system stays authoritative.
- Reuses the EXISTING dark navy Admin UI and the EXISTING print.css
  document system exactly.
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

echo "--- sanity: no ADMIN_ASSET_VERSION bump needed this time (tokens.css/app.css/app.js are all confirmed unchanged above) ---"

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
