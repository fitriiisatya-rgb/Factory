#!/usr/bin/env bash
# Builds dist/amor-factory-receipt-evidence-verification-patch.zip — Store
# Receipt Photo Evidence + Admin Konfirmasi Toko Detail/Verifikasi Selisih,
# for an EXISTING deployment where Phase 5.5 + User Management + FG Source
# Refresh + Driver UX/Navigation + Surat Jalan Print patches are already
# installed:
#   - api/ lives at public_html/factory/api/,
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001-0007 are already applied.
#
# THIS PACKAGE ADDS MIGRATION 0008 — additive only (see database/schema-v1-
# 0008-receipt-evidence.sql): one new table, shipment_receipt_evidence, FK'd
# to the existing shipment_receipt. No existing table is altered/dropped.
#
# Real-UAT ask: a Store Receipt confirmation with Reject/Kurang > 0 now
# REQUIRES at least one photo before it can even be submitted (server-side
# — see ReceiptService::confirmReceipt()'s own EVIDENCE_REQUIRED check),
# and Admin cannot verify a discrepancy receipt (POST /api/admin/receipts/
# {id}/verify) until at least one evidence row exists (adminVerify()'s own
# EVIDENCE_REQUIRED_FOR_VERIFY check — protects OLD/legacy discrepancy
# receipts too, never silently grandfathered in). The Admin "Konfirmasi
# Toko" summary rows are now clickable (Lihat Detail) into a new detail
# page (api/app/ui/pages/konfirmasi-toko-detail.php) that reuses
# DispatchService::shipmentDetail() (isAdmin=true) for its data — no
# second query/DTO — shows items/receiver/note/evidence thumbnails and the
# Verifikasi Selisih action. A legacy discrepancy receipt (confirmed
# before this patch, no evidence) can have evidence attached by Admin via
# a new POST /api/admin/receipts/{id}/evidence route, unblocking its
# verify gate without ever touching its quantities.
#
# File upload is entirely new infrastructure (EvidenceUploader.php) — no
# prior upload mechanism existed anywhere in this codebase. Uploaded
# files are validated by REAL content (finfo + getimagesize(), never the
# client-supplied MIME/extension), capped at 5 MB / 3 files, stored under
# api/uploads/receipt-evidence/ with a random server-generated filename
# under the SAME deny-all .htaccess as api/app/ — never directly web-
# reachable, always served through the ADMIN-authenticated GET
# /api/admin/receipts/evidence/{id} controller.
#
# Untouched by this patch (verified below + by the full regression
# suite): ShipmentService's own stock-deduction logic, Dispatch Claim/
# Release concurrency, ReceiptRepository's own confirm-write plumbing,
# ReceiptService::confirmReceipt()'s math validation and
# ReceiptService::getReceiptToken() ("1 DO = 1 receipt token"), PO/
# Production/FG business logic, User Management, Invoice preview, Surat
# Jalan print.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-receipt-evidence"
ZIP_PATH="$DIST_DIR/amor-factory-receipt-evidence-verification-patch.zip"

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
[ -f "$STAGE/api/app/ui/pages/konfirmasi-toko-detail.php" ] || { echo "REFUSING TO BUILD: konfirmasi-toko-detail.php missing"; exit 1; }
[ -f "$STAGE/api/app/src/Dispatch/EvidenceUploader.php" ] || { echo "REFUSING TO BUILD: EvidenceUploader.php missing"; exit 1; }
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- sanity: confirm EXACTLY migration 0008 was added (additive), nothing beyond it ---"
[ -f "$STAGE/api/app/migrations/0008_receipt_evidence.php" ] || { echo "REFUSING TO BUILD: migration 0008 is missing — this package's whole point is the shipment_receipt_evidence table."; exit 1; }
if find "$STAGE/api/app/migrations" -name '0009_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0009+ was found — this package must add ONLY migration 0008." >&2
  exit 1
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, refreshed) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }
grep -q "rc-evidence-input" "$STAGE/api/assets/js/receipt.js" || { echo "REFUSING TO BUILD: the Bukti Foto upload control is missing from receipt.js"; exit 1; }
grep -q "max-width: 480px" "$STAGE/api/assets/css/receipt.css" || { echo "REFUSING TO BUILD: the mobile-responsive receipt table CSS is missing"; exit 1; }
grep -q "evidence-thumb-grid" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: the Admin evidence thumbnail CSS is missing from app.css"; exit 1; }

echo "--- creating the deny-all evidence upload directory (api/uploads/receipt-evidence/) ---"
mkdir -p "$STAGE/api/uploads/receipt-evidence"
cp "$REPO_ROOT/api/uploads/receipt-evidence/.htaccess" "$STAGE/api/uploads/receipt-evidence/.htaccess"
if ! grep -q 'Require all denied' "$STAGE/api/uploads/receipt-evidence/.htaccess"; then
  echo "REFUSING TO BUILD: api/uploads/receipt-evidence/.htaccess must deny all direct HTTP access — uploaded evidence must never be directly web-reachable." >&2
  exit 1
fi

echo "--- sanity: confirm the new evidence routes and admin verify evidence gate are present ---"
grep -q "admin/receipts/evidence/{id}" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: GET evidence route missing from App.php"; exit 1; }
grep -q "admin/receipts/{id}/evidence" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: POST admin evidence-upload route missing from App.php"; exit 1; }
grep -q "function adminEvidence" "$STAGE/api/app/src/Controllers/ReceiptController.php" || { echo "REFUSING TO BUILD: ReceiptController::adminEvidence missing"; exit 1; }
grep -q "function adminUploadEvidence" "$STAGE/api/app/src/Controllers/ReceiptController.php" || { echo "REFUSING TO BUILD: ReceiptController::adminUploadEvidence missing"; exit 1; }
grep -q "EVIDENCE_REQUIRED_FOR_VERIFY" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: adminVerify()'s evidence gate is missing"; exit 1; }
grep -q "EVIDENCE_REQUIRED'" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: confirmReceipt()'s evidence-required check is missing"; exit 1; }
grep -q "fileField" "$STAGE/api/app/src/Request.php" || { echo "REFUSING TO BUILD: Request::fileField() (multipart support) is missing"; exit 1; }

echo "--- copying canonical schema DDL (0001-0008) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"
cp "$REPO_ROOT/database/schema-v1-0003-po-phase2.sql" "$STAGE/api/app/database/schema-v1-0003-po-phase2.sql"
cp "$REPO_ROOT/database/schema-v1-0004-production-phase3.sql" "$STAGE/api/app/database/schema-v1-0004-production-phase3.sql"
cp "$REPO_ROOT/database/schema-v1-0005-fg-packing-phase4.sql" "$STAGE/api/app/database/schema-v1-0005-fg-packing-phase4.sql"
cp "$REPO_ROOT/database/schema-v1-0006-do-shipment-phase5.sql" "$STAGE/api/app/database/schema-v1-0006-do-shipment-phase5.sql"
cp "$REPO_ROOT/database/schema-v1-0007-dispatch-receipt-phase55.sql" "$STAGE/api/app/database/schema-v1-0007-dispatch-receipt-phase55.sql"
cp "$REPO_ROOT/database/schema-v1-0008-receipt-evidence.sql" "$STAGE/api/app/database/schema-v1-0008-receipt-evidence.sql"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Store Receipt Photo Evidence + Admin Verification Patch

Adds ONE additive migration (0008 — shipment_receipt_evidence). Everything
else in the schema is unchanged.

Quick facts:
- A discrepancy (Reject/Kurang > 0) confirmation from the store REQUIRES
  at least one photo before it can be submitted at all — server-side.
- Admin cannot verify a discrepancy receipt (old or new) until at least
  one evidence photo exists for it.
- The Admin Konfirmasi Toko list is now clickable into a full Detail page
  (items, receiver/note/time, evidence thumbnails, Verifikasi Selisih).
- A discrepancy receipt confirmed BEFORE this patch (no evidence) can
  have evidence attached by Admin, which then unblocks its own verify —
  its quantities are never touched.
- Uploaded photos are validated by real file content (never trusted
  client MIME/extension), capped at 5 MB / 3 files, stored with a random
  filename under a deny-all directory (api/uploads/receipt-evidence/),
  and only ever served through an ADMIN-authenticated route — never a
  direct URL.
- The Store Receipt page is now usable on a narrow phone screen (the
  Kurang column used to clip off-screen).
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

echo "--- sanity: confirm ShipmentService and PO/Production/FG/User Mgmt are UNCHANGED byte-for-byte (this patch never touches stock deduction) ---"
for f in api/app/src/Delivery/ShipmentService.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionService.php api/app/src/Users/UserService.php \
         api/app/src/Fg/FgService.php; do
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this package must not touch stock deduction / PO / Production / User Management / FG logic." >&2
    exit 1
  fi
done

echo "--- sanity: confirm ReceiptRepository's EXISTING confirm-write methods are still present verbatim (this patch only ADDS evidence methods, never modifies these) ---"
for sig in \
  "public function insertReceipt(PDO \$pdo, int \$shipmentId, string \$status, ?string \$receiverName, ?string \$note): int" \
  "public function insertReceiptItem(PDO \$pdo, int \$receiptId, int \$shipmentItemId, int \$productId, float \$shippedQty, float \$good, float \$reject, float \$shortage, ?string \$reason): void" \
  "public function markVerified(PDO \$pdo, int \$receiptId, int \$adminUserId): void" \
  ; do
  grep -qF "$sig" "$STAGE/api/app/src/Dispatch/ReceiptRepository.php" \
    || { echo "REFUSING TO BUILD: ReceiptRepository signature changed/missing: $sig"; exit 1; }
done

echo "--- sanity: confirm ReceiptService's confirmReceipt()/getReceiptToken() core write signatures are still present verbatim ---"
grep -q "public function getReceiptToken(int \$doId): string" "$STAGE/api/app/src/Dispatch/ReceiptService.php" \
  || { echo "REFUSING TO BUILD: ReceiptService::getReceiptToken() signature changed — 1 DO = 1 receipt token must be preserved verbatim."; exit 1; }
grep -q "RECEIPT_MATH_INVALID" "$STAGE/api/app/src/Dispatch/ReceiptService.php" \
  || { echo "REFUSING TO BUILD: confirmReceipt()'s math validation is missing"; exit 1; }

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
