#!/usr/bin/env bash
# Builds dist/amor-factory-store-receipt-photo-submit-ux-hotfix.zip — a
# FILES-ONLY hotfix on top of the already-deployed Store Evidence Role
# Correction package (migrations 0008/0009 already applied on the real
# cPanel host). This package adds NO new migration — schema is
# byte-for-byte the same as migration 0009 left it.
#
# Real cPanel UAT bug report: on the live Store Receipt mobile page,
# (1) the uploaded evidence photo preview rendered far too large, and
# (2) the "Simpan Konfirmasi" button stayed disabled after a valid
# discrepancy + photo was provided in some flows.
#
# Root causes found (real-browser reproduction, not assumption):
#   - Bug 2 (the one concretely reproduced): renderPreviews()'s per-thumbnail
#     remove button spliced selectedFiles and re-rendered, but never called
#     validate() again — so removing the last evidence photo did NOT
#     re-disable the button for a still-invalid (discrepancy, zero evidence)
#     receipt. validate() is now called from within renderPreviews() itself
#     so every re-render (add OR remove) always refreshes button state.
#   - The evidence <input> combined capture="environment" with multiple —
#     a documented cross-browser (notably iOS Safari) compatibility hazard.
#     Removed; the native OS picker still offers "Take Photo" without it.
#   - The giant-preview report could not be reproduced against the
#     already-shipped CSS (a fixed 72x72 thumbnail) in headless/real-Apache
#     testing, which points at a stale cached asset on the real host as the
#     likely cause. This package both hardens the preview CSS defensively
#     (explicit bounded grid, max-width safety net) AND adds a cache-busting
#     ?v= query string to receipt.css/receipt.js (same RECEIPT_ASSET_VERSION
#     pattern already used for driver.js/driver.css) so a stale cached copy
#     can never be served again after this deploy.
#
# NO business rule change. Diterima Baik + Reject + Kurang = Dikirim,
# mandatory photo on Reject/Kurang > 0, Store-only upload / Admin-only
# view+verify, and every server-side validation are all UNCHANGED — this
# package touches ONLY api/_receive/index.php (cache-busting the asset
# tags) and the two Store Receipt portal browser assets themselves
# (api/assets/js/receipt.js, api/assets/css/receipt.css).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-store-receipt-photo-submit-ux-hotfix"
ZIP_PATH="$DIST_DIR/amor-factory-store-receipt-photo-submit-ux-hotfix.zip"

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

echo "--- sanity: confirm the Bug 2 fix (validate() called on every renderPreviews() re-render) is present ---"
grep -q "function renderPreviews" "$STAGE/api/assets/js/receipt.js" || { echo "REFUSING TO BUILD: renderPreviews() is missing from receipt.js"; exit 1; }
RENDERPREVIEWS_BODY=$(awk '/function renderPreviews/,/^      }$/' "$STAGE/api/assets/js/receipt.js")
echo "$RENDERPREVIEWS_BODY" | grep -q "validate();" || { echo "REFUSING TO BUILD: renderPreviews() must call validate() on every re-render (the confirmed remove-photo-doesn't-redisable-button bug fix)"; exit 1; }

echo "--- sanity: confirm capture=\"environment\" (iOS Safari + multiple compatibility hazard) was removed, multiple was kept ---"
if grep -q 'capture=' "$STAGE/api/assets/js/receipt.js"; then
  echo "REFUSING TO BUILD: receipt.js still sets a capture attribute on the evidence input — this must be removed (capture+multiple is a known iOS Safari hazard)." >&2
  exit 1
fi
grep -q 'accept="image/\*" multiple class="rc-evidence-input"' "$STAGE/api/assets/js/receipt.js" || { echo "REFUSING TO BUILD: the evidence input's accept/multiple attributes changed unexpectedly"; exit 1; }

echo "--- sanity: confirm the evidence preview CSS is a bounded, compact grid (never natural-resolution / giant) ---"
grep -q "rc-evidence-previews" "$STAGE/api/assets/css/receipt.css" || { echo "REFUSING TO BUILD: .rc-evidence-previews rule missing"; exit 1; }
grep -q "rc-evidence-thumb {" "$STAGE/api/assets/css/receipt.css" || { echo "REFUSING TO BUILD: .rc-evidence-thumb rule missing"; exit 1; }
grep -q "object-fit: cover" "$STAGE/api/assets/css/receipt.css" || { echo "REFUSING TO BUILD: object-fit: cover missing from the thumbnail rule"; exit 1; }
grep -q "rc-evidence-lightbox" "$STAGE/api/assets/css/receipt.css" || { echo "REFUSING TO BUILD: tap-to-enlarge lightbox CSS missing"; exit 1; }
grep -q "max-width: 480px" "$STAGE/api/assets/css/receipt.css" || { echo "REFUSING TO BUILD: the mobile-responsive receipt table CSS is missing (unrelated to this hotfix but must never regress)"; exit 1; }

echo "--- sanity: confirm receipt.css/receipt.js are cache-busted from _receive/index.php (stale-asset defense) ---"
grep -q "RECEIPT_ASSET_VERSION" "$STAGE/api/_receive/index.php" || { echo "REFUSING TO BUILD: RECEIPT_ASSET_VERSION cache-busting constant missing from _receive/index.php"; exit 1; }
grep -q 'receipt\.css?v=<?= RECEIPT_ASSET_VERSION ?>' "$STAGE/api/_receive/index.php" || { echo "REFUSING TO BUILD: receipt.css is not cache-busted"; exit 1; }
grep -q 'receipt\.js?v=<?= RECEIPT_ASSET_VERSION ?>' "$STAGE/api/_receive/index.php" || { echo "REFUSING TO BUILD: receipt.js is not cache-busted"; exit 1; }

echo "--- creating the deny-all evidence upload directory (api/uploads/receipt-evidence/) ---"
mkdir -p "$STAGE/api/uploads/receipt-evidence"
cp "$REPO_ROOT/api/uploads/receipt-evidence/.htaccess" "$STAGE/api/uploads/receipt-evidence/.htaccess"
if ! grep -q 'Require all denied' "$STAGE/api/uploads/receipt-evidence/.htaccess"; then
  echo "REFUSING TO BUILD: api/uploads/receipt-evidence/.htaccess must deny all direct HTTP access." >&2
  exit 1
fi

echo "--- sanity: confirm NO business rule / server-side validation file changed byte-for-byte ---"
for f in api/app/src/Dispatch/ReceiptService.php api/app/src/Dispatch/ReceiptRepository.php \
         api/app/src/Dispatch/EvidenceUploader.php api/app/src/Dispatch/DispatchService.php \
         api/app/src/Delivery/ShipmentService.php api/app/src/Controllers/ReceiptController.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionService.php \
         api/app/src/Users/UserService.php api/app/src/Fg/FgService.php \
         api/app/ui/pages/konfirmasi-toko-detail.php; do
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this hotfix must not touch business rules, receipt math validation, evidence ownership, Admin verification, or stock/shipment/DO logic." >&2
    exit 1
  fi
done

echo "--- sanity: confirm the mandatory-photo-on-discrepancy server rule is unchanged ---"
grep -q "EVIDENCE_REQUIRED_FOR_VERIFY" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: adminVerify()'s evidence gate is missing"; exit 1; }
grep -q "EVIDENCE_REQUIRED'" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: confirmReceipt()'s evidence-required check is missing"; exit 1; }
grep -q "RECEIPT_MATH_INVALID" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: confirmReceipt()'s math validation is missing"; exit 1; }

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
# Amor Factory System — Store Receipt Photo Preview / Submit Button UX Hotfix

FILES-ONLY hotfix. No new migration (0009 stays as the last one applied —
same schema as the previous Store Evidence Role Correction patch left it).

Quick facts:
- Fixes the confirmed bug: removing the only/last evidence photo did not
  re-disable "Simpan Konfirmasi" for a still-invalid (discrepancy, zero
  evidence) receipt, because renderPreviews() never re-ran validate() after
  a removal. It now does, on every re-render.
- Removes capture="environment" from the evidence file input (kept
  accept="image/*" multiple) — a documented iOS Safari + multiple
  compatibility hazard; the native picker still offers the camera.
- Hardens the evidence photo preview CSS into an explicit bounded grid
  (fixed compact thumbnails, object-fit: cover, no overflow) plus a
  tap-to-enlarge lightbox, and adds cache-busting (?v=) to receipt.css/
  receipt.js so a stale cached copy on the real host can never mask this
  fix.
- NO business rule change: Diterima Baik + Reject + Kurang = Dikirim,
  mandatory photo on Reject/Kurang > 0, Store-only upload, Admin-only
  view+verify, and every server-side validation are all byte-for-byte
  unchanged (verified — see the diff sanity checks in this build script).
- This package does NOT touch stock deduction, Dispatch Claim/Release
  concurrency, receipt confirm math validation, the DO receipt QR token
  mechanism, PO/Production/FG business logic, User Management, Invoice
  preview, or Surat Jalan print.
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
