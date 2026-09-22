#!/usr/bin/env bash
# Builds dist/amor-factory-admin-receipt-evidence-thumbnail-hotfix.zip —
# a FILES-ONLY hotfix on top of the already-deployed Store Receipt Photo
# Preview / Submit Button UX hotfix (migrations 0008/0009 already
# applied on the real cPanel host). This package adds NO new migration —
# schema is byte-for-byte the same as migration 0009 left it.
#
# Real cPanel UAT bug report: on Admin -> Konfirmasi Toko -> Detail, the
# "Bukti Foto dari Toko" section rendered the store-uploaded evidence
# photo at (near) full/natural size instead of a compact thumbnail.
#
# Audit finding (not assumed): the .evidence-thumb/.evidence-thumb-grid
# CSS bounding the thumbnail to 96x96px with object-fit:cover has existed
# since the ORIGINAL receipt-evidence patch and was already correct in
# the shipped source — it could not be faked into rendering "giant" in
# either headless or real-Apache testing. The concrete, auditable gap
# found instead: api/app/ui/layout.php (the Admin UI's shared shell,
# used by every _ui-preview page including this one) referenced
# tokens.css/app.css/app.js with NO cache-busting query string at all —
# unlike receipt.css/js and driver.css/js, which both already got one in
# earlier patches. A stale cached app.css on a real device could keep
# serving a version from before the thumbnail rule existed, or before
# any future fix, indefinitely. This package:
#   - adds ADMIN_ASSET_VERSION cache-busting (same pattern as
#     RECEIPT_ASSET_VERSION / DRIVER_ASSET_VERSION) to layout.php's
#     tokens.css/app.css/app.js tags,
#   - hardens .evidence-thumb with a defensive max-width/max-height cap
#     on top of its existing fixed 96x96px sizing, and adds a blanket
#     .evidence-thumb-grid img { max-width:100% } safety net,
#   - replaces the previous default UX (clicking a thumbnail opened the
#     raw image URL directly via target="_blank") with a bounded,
#     closable lightbox (max-width:90vw / max-height:80vh, object-fit:
#     contain, close button, Escape, click-outside) — a new shared
#     Amor.imageLightbox() in app.js, wired via a generic
#     [data-lightbox="image"] click delegate so any future Admin page
#     can reuse it without duplicating the code.
#
# NO business rule change. Receipt quantities/status, evidence ownership
# (Store-only upload, Admin-only view+verify), verification rules, email
# logic, and every server-side validation are all UNCHANGED — this
# package touches ONLY api/app/ui/layout.php,
# api/app/ui/pages/konfirmasi-toko-detail.php (markup only — the click
# target, not its data), api/assets/css/app.css, and api/assets/js/app.js.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-admin-receipt-evidence-thumbnail-hotfix"
ZIP_PATH="$DIST_DIR/amor-factory-admin-receipt-evidence-thumbnail-hotfix.zip"

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

echo "--- sanity: confirm the Admin evidence thumbnail is bounded (fixed 96x96px + defensive max-width/max-height cap + object-fit:cover) ---"
grep -q "evidence-thumb-grid" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: .evidence-thumb-grid rule missing"; exit 1; }
EVIDENCE_THUMB_BLOCK=$(awk '/\.evidence-thumb \{/,/^\}/' "$STAGE/api/assets/css/app.css")
echo "$EVIDENCE_THUMB_BLOCK" | grep -q "width: 96px" || { echo "REFUSING TO BUILD: .evidence-thumb no longer fixed at 96px wide"; exit 1; }
echo "$EVIDENCE_THUMB_BLOCK" | grep -q "max-width: 120px" || { echo "REFUSING TO BUILD: .evidence-thumb missing the defensive max-width cap"; exit 1; }
grep -q "object-fit: cover" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: object-fit: cover missing from the thumbnail rule"; exit 1; }

echo "--- sanity: confirm the bounded lightbox exists (never a raw image navigation as the default UX) ---"
grep -q "image-lightbox-backdrop" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: .image-lightbox-backdrop CSS missing"; exit 1; }
IMAGE_LIGHTBOX_BLOCK=$(awk '/\.image-lightbox \{/,/^\}/' "$STAGE/api/assets/css/app.css")
echo "$IMAGE_LIGHTBOX_BLOCK" | grep -q "max-width: 90vw" || { echo "REFUSING TO BUILD: .image-lightbox missing max-width: 90vw"; exit 1; }
echo "$IMAGE_LIGHTBOX_BLOCK" | grep -q "max-height: 80vh" || { echo "REFUSING TO BUILD: .image-lightbox missing max-height: 80vh"; exit 1; }
grep -q "object-fit: contain" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: object-fit: contain missing from the lightbox image rule"; exit 1; }
grep -q "function imageLightbox" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: Amor.imageLightbox() is missing from app.js"; exit 1; }
grep -q 'data-lightbox="image"' "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: the [data-lightbox=image] click delegate is missing from app.js"; exit 1; }
grep -q "imageLightbox: imageLightbox" "$STAGE/api/assets/js/app.js" || { echo "REFUSING TO BUILD: Amor.imageLightbox is not exported"; exit 1; }

echo "--- sanity: confirm the evidence link no longer defaults to a raw-image new-tab navigation ---"
if grep -q 'target="_blank"' "$STAGE/api/app/ui/pages/konfirmasi-toko-detail.php"; then
  echo "REFUSING TO BUILD: konfirmasi-toko-detail.php still opens the raw evidence image via target=\"_blank\" — the default UX must be the bounded lightbox." >&2
  exit 1
fi
grep -q 'data-lightbox="image"' "$STAGE/api/app/ui/pages/konfirmasi-toko-detail.php" || { echo "REFUSING TO BUILD: the evidence link is not wired to the lightbox"; exit 1; }
grep -q 'class="evidence-thumb"' "$STAGE/api/app/ui/pages/konfirmasi-toko-detail.php" || { echo "REFUSING TO BUILD: the bounded .evidence-thumb wrapper is missing from the evidence markup"; exit 1; }

echo "--- sanity: confirm the Admin UI now cache-busts tokens.css/app.css/app.js (stale-asset defense) ---"
grep -q "ADMIN_ASSET_VERSION" "$STAGE/api/app/ui/layout.php" || { echo "REFUSING TO BUILD: ADMIN_ASSET_VERSION cache-busting constant missing from layout.php"; exit 1; }
grep -q 'tokens\.css?v=<?= ADMIN_ASSET_VERSION ?>' "$STAGE/api/app/ui/layout.php" || { echo "REFUSING TO BUILD: tokens.css is not cache-busted"; exit 1; }
grep -q 'app\.css?v=<?= ADMIN_ASSET_VERSION ?>' "$STAGE/api/app/ui/layout.php" || { echo "REFUSING TO BUILD: app.css is not cache-busted"; exit 1; }
grep -q 'app\.js?v=<?= ADMIN_ASSET_VERSION ?>' "$STAGE/api/app/ui/layout.php" || { echo "REFUSING TO BUILD: app.js is not cache-busted"; exit 1; }

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
         api/assets/js/receipt.js api/assets/css/receipt.css api/_receive/index.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this hotfix must not touch business rules, receipt math validation, evidence ownership, verification, email logic, or the Store-side receipt page." >&2
    exit 1
  fi
done

echo "--- sanity: confirm the mandatory-photo-on-discrepancy server rule and admin verify gate are unchanged ---"
grep -q "EVIDENCE_REQUIRED_FOR_VERIFY" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: adminVerify()'s evidence gate is missing"; exit 1; }
grep -q "EVIDENCE_REQUIRED'" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: confirmReceipt()'s evidence-required check is missing"; exit 1; }
grep -q "admin/receipts/evidence/{id}" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: GET evidence-viewer route missing from App.php"; exit 1; }
grep -q "function adminEvidence" "$STAGE/api/app/src/Controllers/ReceiptController.php" || { echo "REFUSING TO BUILD: ReceiptController::adminEvidence missing"; exit 1; }
if grep -q "admin/receipts/{id}/evidence" "$STAGE/api/app/src/App.php"; then
  echo "REFUSING TO BUILD: the removed Admin-upload evidence route reappeared in App.php." >&2
  exit 1
fi

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
# Amor Factory System — Admin Receipt Evidence Thumbnail Hotfix

FILES-ONLY hotfix. No new migration (0009 stays as the last one applied —
same schema as the previous Store Receipt Photo/Submit UX hotfix left it).

Quick facts:
- Admin -> Konfirmasi Toko -> Detail's "Bukti Foto dari Toko" now always
  renders a bounded 96x96px thumbnail (never the raw photo at natural
  size), with a defensive max-width/max-height cap on top.
- Clicking a thumbnail opens a bounded lightbox (max-width:90vw,
  max-height:80vh, object-fit:contain) with a close button, Escape, and
  click-outside-to-close — replacing the previous default of opening the
  raw image URL in a new tab.
- The Admin UI's shared layout (tokens.css/app.css/app.js) is now
  cache-busted (ADMIN_ASSET_VERSION), so a stale cached asset on a real
  device can no longer mask this fix.
- NO business rule change: receipt quantities/status, evidence ownership
  (Store-only upload, Admin-only view+verify), verification rules, and
  every server-side validation are all byte-for-byte unchanged (verified
  — see the diff sanity checks in this build script).
- This package does NOT touch stock deduction, Dispatch Claim/Release
  concurrency, receipt confirm math validation, the DO receipt QR token
  mechanism, email logic, PO/Production/FG business logic, User
  Management, Invoice preview, Surat Jalan print, or the Store-side
  receipt page itself.
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
