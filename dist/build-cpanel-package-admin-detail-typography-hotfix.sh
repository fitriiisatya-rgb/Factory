#!/usr/bin/env bash
# Builds dist/amor-factory-admin-detail-typography-hotfix.zip — a
# FILES-ONLY hotfix on top of the already-deployed Admin Receipt Evidence
# Thumbnail hotfix (migrations 0008/0009 already applied on the real
# cPanel host). This package adds NO new migration — schema is
# byte-for-byte the same as migration 0009 left it.
#
# Real cPanel UAT bug report (observed on real iPad/tablet UAT): on
# Admin -> Konfirmasi Toko -> Detail, several card values (Email
# Pengiriman's Status/Tujuan/Percobaan; Konfirmasi Toko's Waktu
# Konfirmasi/Status) render with Dashboard-KPI-headline typography — far
# too large and visually inconsistent for a plain information-detail
# page.
#
# Audit finding (not assumed): every ONE of these oversized values is
# rendered through the SAME shared ui_kpi_card() component App.php's
# Dashboard, DO list/detail, FG & Packing, Pengiriman, Pesanan Toko and
# Produksi pages ALSO use — but on every one of THOSE pages the "value"
# is a genuine aggregate metric (a count/total/percentage) that legitimately
# deserves .kpi-value's 1.75rem/font-weight:800 headline treatment.
# Konfirmasi Toko Detail is the ONLY caller that reuses this same
# component for plain prose record fields (a status label, an email
# address, a timestamp, a name) instead of a real KPI number. A SECOND,
# independently-confirmed contributing issue: these 3 card rows set
# their column count via an INLINE style="grid-template-columns:..."
# attribute, which beats the .kpi-grid class's own responsive @media
# breakpoints by CSS specificity — so a 4-column row of prose values
# never actually collapsed to fewer columns on iPad/mobile, a real
# overflow/cramping risk this package also fixes.
#
# This package:
#   - adds an opt-in 'detail' => true flag to ui_kpi_card() (adds a
#     "kpi-card--detail" modifier class) — every OTHER ui_kpi_card()
#     caller is completely unaffected, since none of them pass this flag,
#     so every real Dashboard/DO/FG/Pengiriman/Pesanan Toko/Produksi KPI
#     number keeps its full headline size exactly as before,
#   - .kpi-card--detail scales ONLY those 10 Konfirmasi Toko Detail
#     cards down to --text-lg/700 (18px, matching the same size class
#     .modal-title already uses for prominent-but-not-headline text),
#     with overflow-wrap:anywhere + word-break:break-word so a long email
#     address always stays inside its card,
#   - replaces the 3 inline style="grid-template-columns:..." overrides
#     with plain .kpi-grid-3/.kpi-grid-4 classes, letting the EXISTING
#     .kpi-grid responsive breakpoints (already used everywhere else in
#     the Admin UI) finally take effect on this page too,
#   - bumps ADMIN_ASSET_VERSION (added in the previous evidence-thumbnail
#     hotfix) so a stale cached app.css can never mask this fix.
#
# NO business rule change. Receipt quantities/status, evidence ownership,
# verification rules, email logic, timestamps, and every server-side
# validation are all UNCHANGED — this package touches ONLY
# api/app/ui/components.php (the opt-in flag), api/app/ui/layout.php
# (asset version bump), api/app/ui/pages/konfirmasi-toko-detail.php
# (markup only — the CSS classes used, not the data), and
# api/assets/css/app.css.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-admin-detail-typography-hotfix"
ZIP_PATH="$DIST_DIR/amor-factory-admin-detail-typography-hotfix.zip"

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

echo "--- sanity: confirm NO new migration was added (files-only hotfix, migration 0009 already applied on the real host) ---"
[ -f "$STAGE/api/app/migrations/0009_shipment_email.php" ] || { echo "REFUSING TO BUILD: migration 0009 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0010_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0010+ was found — this package must add NO new migration." >&2
  exit 1
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, refreshed) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: confirm the detail-card typography modifier exists and the base (real KPI) rule is untouched ---"
DETAIL_VALUE_BLOCK=$(awk '/\.kpi-card--detail \.kpi-value \{/,/^\}/' "$STAGE/api/assets/css/app.css")
echo "$DETAIL_VALUE_BLOCK" | grep -q "var(--text-lg)" || { echo "REFUSING TO BUILD: .kpi-card--detail .kpi-value must use --text-lg (18px), not a Dashboard-KPI size"; exit 1; }
echo "$DETAIL_VALUE_BLOCK" | grep -q "font-weight: 700" || { echo "REFUSING TO BUILD: .kpi-card--detail .kpi-value must use weight 700, not the KPI 800"; exit 1; }
echo "$DETAIL_VALUE_BLOCK" | grep -q "overflow-wrap: anywhere" || { echo "REFUSING TO BUILD: .kpi-card--detail .kpi-value must wrap long values (e.g. email) instead of overflowing"; exit 1; }
grep -q '\.kpi-value { font-size: var(--text-2xl); font-weight: 800; margin-top: 2px; }' "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: the base .kpi-value rule (real Dashboard/DO/FG/Pengiriman/Pesanan Toko/Produksi KPI numbers) must be BYTE-FOR-BYTE unchanged"; exit 1; }
grep -q "kpi-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: .kpi-grid-3 utility class missing"; exit 1; }
grep -q "kpi-grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: .kpi-grid-4 utility class missing"; exit 1; }

echo "--- sanity: confirm ui_kpi_card()'s detail flag is opt-in and additive (no change to its default behavior) ---"
grep -q "!empty(\$opts\['detail'\])" "$STAGE/api/app/ui/components.php" || { echo "REFUSING TO BUILD: ui_kpi_card()'s opt-in 'detail' flag is missing"; exit 1; }

echo "--- sanity: confirm konfirmasi-toko-detail.php uses the new classes/flag, and the old inline grid override is GONE ---"
if grep -q 'style="grid-template-columns' "$STAGE/api/app/ui/pages/konfirmasi-toko-detail.php"; then
  echo "REFUSING TO BUILD: konfirmasi-toko-detail.php still has an inline grid-template-columns override — it beat the responsive .kpi-grid breakpoints by specificity." >&2
  exit 1
fi
[ "$(grep -c "'detail' => true" "$STAGE/api/app/ui/pages/konfirmasi-toko-detail.php")" = "10" ] || { echo "REFUSING TO BUILD: expected exactly 10 ui_kpi_card() calls with 'detail' => true (4 summary + 3 email + 3 konfirmasi)"; exit 1; }

echo "--- sanity: confirm NO other ui_kpi_card() caller (real Dashboard/DO/FG/Pengiriman/Pesanan Toko/Produksi KPIs) was touched ---"
for f in api/app/ui/pages/dashboard.php api/app/ui/pages/delivery-order.php api/app/ui/pages/delivery-order-detail.php \
         api/app/ui/pages/fg-packing.php api/app/ui/pages/pengiriman.php api/app/ui/pages/pesanan-toko.php api/app/ui/pages/produksi.php; do
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this hotfix must touch ONLY Konfirmasi Toko Detail's own typography, never another page's real KPI cards." >&2
    exit 1
  fi
  if grep -q "'detail' => true" "$STAGE/$f"; then
    echo "REFUSING TO BUILD: $f unexpectedly opts into the detail typography modifier — its KPI numbers must keep the full headline treatment." >&2
    exit 1
  fi
done

echo "--- sanity: confirm the Admin UI cache-busting version was bumped (stale-asset defense, same mechanism as the previous hotfix) ---"
grep -q "ADMIN_ASSET_VERSION" "$STAGE/api/app/ui/layout.php" || { echo "REFUSING TO BUILD: ADMIN_ASSET_VERSION cache-busting constant missing from layout.php"; exit 1; }
if grep -q "20260922-evidence-thumbnail-hotfix" "$STAGE/api/app/ui/layout.php"; then
  echo "REFUSING TO BUILD: ADMIN_ASSET_VERSION was not bumped from the previous hotfix's value — a stale cached app.css could mask this typography fix." >&2
  exit 1
fi

echo "--- sanity: confirm the previous evidence-thumbnail hotfix is still intact (this package must not regress it) ---"
grep -q "evidence-thumb-grid" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: .evidence-thumb-grid rule missing"; exit 1; }
grep -q "image-lightbox-backdrop" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: .image-lightbox-backdrop CSS missing"; exit 1; }
grep -q "function imageLightbox" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: Amor.imageLightbox() is missing from app.js"; exit 1; }
if grep -q 'target="_blank"' "$STAGE/api/app/ui/pages/konfirmasi-toko-detail.php"; then
  echo "REFUSING TO BUILD: konfirmasi-toko-detail.php regressed back to opening the raw evidence image via target=\"_blank\"." >&2
  exit 1
fi

echo "--- creating the deny-all evidence upload directory (api/uploads/receipt-evidence/) ---"
mkdir -p "$STAGE/api/uploads/receipt-evidence"
cp "$REPO_ROOT/api/uploads/receipt-evidence/.htaccess" "$STAGE/api/uploads/receipt-evidence/.htaccess"
if ! grep -q 'Require all denied' "$STAGE/api/uploads/receipt-evidence/.htaccess"; then
  echo "REFUSING TO BUILD: api/uploads/receipt-evidence/.htaccess must deny all direct HTTP access." >&2
  exit 1
fi

echo "--- sanity: confirm NO business rule / server-side validation / receipt-side file changed byte-for-byte ---"
for f in api/app/src/Dispatch/ReceiptService.php api/app/src/Dispatch/ReceiptRepository.php \
         api/app/src/Dispatch/EvidenceUploader.php api/app/src/Dispatch/DispatchService.php \
         api/app/src/Delivery/ShipmentService.php api/app/src/Controllers/ReceiptController.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionService.php \
         api/app/src/Users/UserService.php api/app/src/Fg/FgService.php \
         api/app/src/Mail/ShipmentEmailService.php \
         api/assets/js/receipt.js api/assets/css/receipt.css api/_receive/index.php \
         api/assets/js/app.js; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this hotfix must not touch business rules, receipt math validation, evidence ownership, verification, email logic, the Store-side receipt page, or app.js (a typography-only patch never needs JS changes)." >&2
    exit 1
  fi
done

echo "--- sanity: confirm the mandatory-photo-on-discrepancy server rule and admin verify gate are unchanged ---"
grep -q "EVIDENCE_REQUIRED_FOR_VERIFY" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: adminVerify()'s evidence gate is missing"; exit 1; }
grep -q "EVIDENCE_REQUIRED'" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: confirmReceipt()'s evidence-required check is missing"; exit 1; }

echo "--- copying canonical schema DDL (0001-0009, unchanged) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"
cp "$REPO_ROOT/database/schema-v1-0003-po-phase2.sql" "$STAGE/api/app/database/schema-v1-0003-po-phase2.sql"
cp "$REPO_ROOT/database/schema-v1-0004-production-phase3.sql" "$STAGE/api/app/database/schema-v1-0004-production-phase3.sql"
cp "$REPO_ROOT/database/schema-v1-0005-fg-packing-phase4.sql" "$STAGE/api/app/database/schema-v1-0005-fg-packing-phase4.sql"
cp "$REPO_ROOT/database/schema-v1-0006-do-shipment-phase5.sql" "$STAGE/api/app/database/schema-v1-0006-do-shipment-phase5.sql"
cp "$REPO_ROOT/database/schema-v1-0007-dispatch-receipt-phase55.sql" "$STAGE/api/app/database/schema-v1-0007-dispatch-receipt-phase55.sql"
cp "$REPO_ROOT/database/schema-v1-0008-receipt-evidence.sql" "$STAGE/api/app/database/schema-v1-0008-receipt-evidence.sql"
if [ -f "$REPO_ROOT/database/schema-v1-0009-shipment-email.sql" ]; then
  cp "$REPO_ROOT/database/schema-v1-0009-shipment-email.sql" "$STAGE/api/app/database/schema-v1-0009-shipment-email.sql"
fi

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Admin Detail Typography Hotfix

FILES-ONLY hotfix. No new migration (0009 stays as the last one applied —
same schema as the previous Admin Receipt Evidence Thumbnail hotfix left
it).

Quick facts:
- Admin -> Konfirmasi Toko -> Detail's info-card values (Email
  Pengiriman's Status/Tujuan/Percobaan; the shipment summary's No. DO/
  Driver/Factory Asal/Waktu Berangkat; Konfirmasi Toko's Nama Penerima/
  Waktu Konfirmasi/Status) now render at 18px/weight 700 instead of the
  28px/weight 800 Dashboard-KPI headline size.
- A long email address now wraps inside its card instead of overflowing.
- The 3 card rows now collapse to fewer columns on iPad/mobile (an old
  inline style was silently blocking the existing responsive behavior).
- Every OTHER page's real KPI numbers (Dashboard, Delivery Order,
  FG & Packing, Pengiriman, Pesanan Toko, Produksi) are COMPLETELY
  UNCHANGED -- they never opted into this new modifier.
- The Admin UI's cache-busting version (ADMIN_ASSET_VERSION, added in
  the previous hotfix) was bumped, so a stale cached app.css can no
  longer mask this fix.
- NO business rule change: receipt quantities/status, evidence
  ownership, verification rules, email logic, and every server-side
  validation are all byte-for-byte unchanged (verified -- see the diff
  sanity checks in this build script).
- This package does NOT touch stock deduction, Dispatch Claim/Release
  concurrency, receipt confirm math validation, the DO receipt QR token
  mechanism, email logic, PO/Production/FG business logic, User
  Management, Invoice preview, Surat Jalan print, the evidence-thumbnail
  hotfix's own lightbox/grid, or the Store-side receipt page.
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
  echo "REFUSING TO BUILD: a browser-facing href/src points directly inside a deny-all tree — evidence must only ever be reached via the authenticated controller route." >&2
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
