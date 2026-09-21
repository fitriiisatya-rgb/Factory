#!/usr/bin/env bash
# Builds dist/amor-factory-shipment-surat-jalan-print-patch.zip — the
# Draft DO vs Actual Shipment Surat Jalan document-separation patch, for
# an EXISTING deployment where Phase 5.5 + User Management + FG Source
# Refresh + the Driver UX/Shipment Tracing + Navigation/Logout hotfix
# patches are already installed:
#   - api/ lives at public_html/factory/api/,
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001-0007 are already applied.
#
# FILES-ONLY PATCH — NO NEW MIGRATION. Real-UAT report: the existing DO
# print (api/_ui-preview/print-do.php, api/_do-uat/print.php) showed
# every PLANNED item across the whole DO (Bakery Abdul Gani, DO/KRM/004/
# IX/2026 = 1,034 pcs), titled "Delivery Order / Surat Jalan" — correct
# as a picking/planning document, wrong as the actual proof-of-goods a
# Driver carries once a DO has split into several real shipments (SHP-2
# Driver A/MAIN = 7 pcs, SHP-3 Driver B/PASTRY = 4 pcs). Fixed:
#   - The Draft DO print title is now unambiguous ("DRAFT DELIVERY ORDER
#     / RENCANA PENGIRIMAN") and never says "Surat Jalan" — it keeps
#     printing every planned item exactly as before (task's own
#     "preserve the existing Draft DO print functionality").
#   - A NEW, separate document — api/app/ui/print-shipment-template.php,
#     reached via api/_driver-uat/print-shipment.php (Driver, "Cetak
#     Surat Jalan" button on Detail Pengiriman) and
#     api/_ui-preview/print-shipment.php (Admin, same shared template) —
#     prints ONLY ONE real shipment's shipment_item rows, never
#     delivery_order_item/dispatch_claim/PO planned qty. Reuses the
#     EXISTING per-DO receipt QR (ui_do_receipt_qr_svg(), unchanged — "1
#     DO = 1 receipt token" stays true; two shipments under the same DO
#     print the SAME QR by design) and DispatchService::shipmentDetail()'s
#     EXISTING authorization rule (403 for a shipment the caller didn't
#     ship; ADMIN unrestricted) — never a parallel auth check.
#   - api/assets/css/print-shipment.css (new) makes the Surat Jalan print
#     dot-matrix/3-ply safe (darker label text, no gradients) and exposes
#     a configurable QR physical size (sj-qr-35mm/40mm/50mm via ?qr=,
#     default 40mm) for a real print-size comparison — one stylesheet,
#     one document, never three.
#   - The public Store Receipt portal (api/_receive/, receipt.js) now
#     also shows which Driver shipped each shipment card, read-only
#     display enrichment on top of ReceiptService::getPublicView() —
#     confirmReceipt()'s own write logic is unchanged (checked below).
#
# Untouched by this patch (verified below + by the full regression
# suite): ShipmentService's own stock-deduction logic, Dispatch Claim/
# Release concurrency, Store Receipt confirm/verify math, QR token
# issuance ("1 DO = 1 token"), PO/Production/FG business logic, User
# Management, Invoice preview.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-shipment-surat-jalan-print"
ZIP_PATH="$DIST_DIR/amor-factory-shipment-surat-jalan-print-patch.zip"

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
[ -f "$STAGE/api/_driver-uat/print-shipment.php" ] || { echo "REFUSING TO BUILD: api/_driver-uat/print-shipment.php (Cetak Surat Jalan) missing"; exit 1; }
[ -f "$STAGE/api/_ui-preview/print-shipment.php" ] || { echo "REFUSING TO BUILD: api/_ui-preview/print-shipment.php (Admin reuse) missing"; exit 1; }

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
[ -f "$STAGE/api/app/ui/print-shipment-template.php" ] || { echo "REFUSING TO BUILD: api/app/ui/print-shipment-template.php missing"; exit 1; }
grep -q "function ui_render_shipment_print_document" "$STAGE/api/app/ui/print-shipment-template.php" || { echo "REFUSING TO BUILD: ui_render_shipment_print_document() missing"; exit 1; }
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
[ -f "$STAGE/api/assets/css/print-shipment.css" ] || { echo "REFUSING TO BUILD: api/assets/css/print-shipment.css missing"; exit 1; }
grep -q "sj-qr-40mm" "$STAGE/api/assets/css/print-shipment.css" || { echo "REFUSING TO BUILD: print-shipment.css missing the configurable QR size classes"; exit 1; }
grep -q "print-shipment.php" "$STAGE/api/assets/js/driver.js" || { echo "REFUSING TO BUILD: the Cetak Surat Jalan button link is missing from driver.js"; exit 1; }

echo "--- sanity: confirm the Draft DO print title is unambiguous and never says Surat Jalan any more ---"
grep -q "DRAFT DELIVERY ORDER / RENCANA PENGIRIMAN" "$STAGE/api/app/ui/print-template.php" || { echo "REFUSING TO BUILD: the Draft DO print title naming fix is missing"; exit 1; }
# Checked by exact FILE, not a blanket tree grep — the two NEW Surat Jalan
# print pages legitimately title themselves "Surat Jalan SHP-{id}" (that
# IS the real document this patch adds); only the Draft DO print pages
# must never say it.
for f in "$STAGE/api/_ui-preview/print-do.php" "$STAGE/api/_do-uat/print.php"; do
  if grep -q '<title>Surat Jalan' "$f"; then
    echo "REFUSING TO BUILD: $f still titles itself Surat Jalan — the exact ambiguity this patch fixes." >&2
    exit 1
  fi
done

echo "--- sanity: confirm shipment_item is the print source (both entry points call DispatchService::shipmentDetail(), never a parallel query) ---"
grep -q "shipmentDetail" "$STAGE/api/_driver-uat/print-shipment.php" || { echo "REFUSING TO BUILD: print-shipment.php must source its data via DispatchService::shipmentDetail()"; exit 1; }
grep -q "shipmentDetail" "$STAGE/api/_ui-preview/print-shipment.php" || { echo "REFUSING TO BUILD: the admin print-shipment.php must source its data via DispatchService::shipmentDetail()"; exit 1; }

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
# Amor Factory System — Draft DO vs Actual Shipment Surat Jalan Separation Patch

FILES-ONLY INCREMENTAL patch for an existing Phase 5.5 + User Management +
FG Source Refresh + Driver UX/Navigation deployment. NO DATABASE MIGRATION
in this package.

Quick facts:
- Draft DO print (planning/picking, shows ALL planned DO items) is now
  titled unambiguously and never claims to be "Surat Jalan" any more.
- A brand-new SEPARATE document — Surat Jalan — prints ONLY ONE real
  shipment's actual items (shipment_item), reached via "Cetak Surat
  Jalan" on the Driver's Detail Pengiriman page, or the same route from
  Admin. A Driver can only print a shipment they themselves shipped
  (403 otherwise) — the exact rule the Detail Pengiriman page already
  enforced, reused unchanged.
- Surat Jalan reuses the SAME DO-level receipt QR every other shipment
  under that DO already shares — never a new token per shipment.
- Surat Jalan print styling is dot-matrix/3-ply friendly (no gradients,
  darker label text, configurable QR physical size for a print test:
  ?qr=35, ?qr=40 [default], or ?qr=50).
- This package does NOT touch stock deduction, Dispatch Claim/Release
  concurrency, Store Receipt confirm/verify math, QR token issuance,
  PO/Production/FG business logic, User Management, or Invoice preview.
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

echo "--- sanity: confirm ShipmentService/ReceiptRepository (stock deduction + receipt write logic) and PO/Production/FG/User Mgmt are UNCHANGED byte-for-byte ---"
for f in api/app/src/Delivery/ShipmentService.php api/app/src/Dispatch/ReceiptRepository.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionService.php api/app/src/Users/UserService.php \
         api/app/src/Fg/FgService.php; do
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this package must not touch stock deduction / receipt write logic / PO / Production / User Management / FG logic." >&2
    exit 1
  fi
done

echo "--- sanity: confirm ReceiptService's confirmReceipt()/getReceiptToken() write logic is still present verbatim (this patch only ADDS a read-only driverName field to getPublicView()) ---"
grep -q "public function confirmReceipt(string \$token, int \$shipmentId, ?string \$receiverName, ?string \$note, array \$items, ?string \$requestId): array" "$STAGE/api/app/src/Dispatch/ReceiptService.php" \
  || { echo "REFUSING TO BUILD: ReceiptService::confirmReceipt() signature changed — this patch must never touch receipt confirm/verify logic."; exit 1; }
grep -q "public function getReceiptToken(int \$doId): string" "$STAGE/api/app/src/Dispatch/ReceiptService.php" \
  || { echo "REFUSING TO BUILD: ReceiptService::getReceiptToken() signature changed — 1 DO = 1 receipt token must be preserved verbatim."; exit 1; }

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
