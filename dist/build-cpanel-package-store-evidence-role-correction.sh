#!/usr/bin/env bash
# Builds dist/amor-factory-store-evidence-role-correction.zip — a FILES-ONLY
# correction on top of the already-deployed Store Receipt Photo Evidence +
# Admin Konfirmasi Toko Detail/Verifikasi Selisih patch (migration 0008,
# already applied on the real cPanel host). This package adds NO new
# migration — schema is byte-for-byte the same as migration 0008 left it.
#
# Real cPanel UAT correction: the previous patch's Admin Detail page had an
# "Unggah Bukti Foto" (Admin) upload control for attaching evidence to
# legacy pre-patch receipts. This broke the business rule: photo evidence
# is STORE evidence, uploaded ONLY by the Store through the public QR
# Receipt portal. Admin's role is view + verify ONLY. This package:
#   - REMOVES the Admin-side evidence upload control from the UI
#     (api/app/ui/pages/konfirmasi-toko-detail.php),
#   - REMOVES the POST /api/admin/receipts/{id}/evidence route and its
#     controller method/service method entirely (not just hidden — a
#     request to it now 404s through the front controller like any other
#     unknown route),
#   - updates the verify-blocked message to "Selisih belum dapat
#     diverifikasi karena bukti foto dari toko belum tersedia.",
#   - leaves a legacy discrepancy receipt with no store evidence (e.g. real
#     UAT's SHP-3) PERMANENTLY un-verifiable — never repaired, never
#     fabricated by Admin. A NEW shipment/receipt is used to test the
#     corrected Store-side evidence workflow instead.
#
# The Store-side flow (public confirm() route, EvidenceUploader validation,
# mandatory photo on Reject/Kurang > 0, mobile upload UX, idempotency) is
# UNCHANGED — this package touches ONLY the Admin-side surface described
# above. GET /api/admin/receipts/evidence/{id} (the admin-authenticated
# viewer) is UNCHANGED — Admin can still VIEW store-uploaded evidence.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-store-evidence-role-correction"
ZIP_PATH="$DIST_DIR/amor-factory-store-evidence-role-correction.zip"

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
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- sanity: confirm NO new migration was added (files-only correction, migration 0008 already applied on the real host) ---"
[ -f "$STAGE/api/app/migrations/0008_receipt_evidence.php" ] || { echo "REFUSING TO BUILD: migration 0008 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0009_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0009+ was found — this package must add NO new migration." >&2
  exit 1
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, refreshed) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }
grep -q "rc-evidence-input" "$STAGE/api/assets/js/receipt.js" || { echo "REFUSING TO BUILD: the Store-side Bukti Foto upload control is missing from receipt.js — this correction must NOT touch the store upload flow"; exit 1; }
grep -q "max-width: 480px" "$STAGE/api/assets/css/receipt.css" || { echo "REFUSING TO BUILD: the mobile-responsive receipt table CSS is missing"; exit 1; }

echo "--- creating the deny-all evidence upload directory (api/uploads/receipt-evidence/) ---"
mkdir -p "$STAGE/api/uploads/receipt-evidence"
cp "$REPO_ROOT/api/uploads/receipt-evidence/.htaccess" "$STAGE/api/uploads/receipt-evidence/.htaccess"
if ! grep -q 'Require all denied' "$STAGE/api/uploads/receipt-evidence/.htaccess"; then
  echo "REFUSING TO BUILD: api/uploads/receipt-evidence/.htaccess must deny all direct HTTP access." >&2
  exit 1
fi

echo "--- sanity: confirm the Admin-side evidence UPLOAD surface is GONE (this correction's whole point) ---"
if grep -q "admin/receipts/{id}/evidence" "$STAGE/api/app/src/App.php"; then
  echo "REFUSING TO BUILD: the POST admin evidence-upload route is still registered in App.php — it must be removed entirely." >&2
  exit 1
fi
if grep -q "function adminUploadEvidence" "$STAGE/api/app/src/Controllers/ReceiptController.php"; then
  echo "REFUSING TO BUILD: ReceiptController::adminUploadEvidence still exists — it must be removed entirely." >&2
  exit 1
fi
if grep -q "function adminAddEvidence" "$STAGE/api/app/src/Dispatch/ReceiptService.php"; then
  echo "REFUSING TO BUILD: ReceiptService::adminAddEvidence still exists — it must be removed entirely." >&2
  exit 1
fi
if grep -qE "Unggah Bukti Foto|admin-evidence-submit|admin-evidence-input" "$STAGE/api/app/ui/pages/konfirmasi-toko-detail.php"; then
  echo "REFUSING TO BUILD: the Admin detail page still offers an evidence upload control — Admin must be view/verify-only." >&2
  exit 1
fi
grep -q "bukti foto dari toko belum tersedia" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: the corrected verify-blocked message text is missing"; exit 1; }

echo "--- sanity: confirm the Admin-side evidence VIEWER (GET) is still present — Admin may still VIEW store evidence ---"
grep -q "admin/receipts/evidence/{id}" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: GET evidence-viewer route missing from App.php"; exit 1; }
grep -q "function adminEvidence" "$STAGE/api/app/src/Controllers/ReceiptController.php" || { echo "REFUSING TO BUILD: ReceiptController::adminEvidence missing"; exit 1; }
grep -q "Bukti Foto dari Toko" "$STAGE/api/app/ui/pages/konfirmasi-toko-detail.php" || { echo "REFUSING TO BUILD: the store-evidence display heading is missing from the Admin detail page"; exit 1; }

echo "--- sanity: confirm the Store-side evidence-required rule is unchanged ---"
grep -q "EVIDENCE_REQUIRED_FOR_VERIFY" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: adminVerify()'s evidence gate is missing"; exit 1; }
grep -q "EVIDENCE_REQUIRED'" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: confirmReceipt()'s evidence-required check is missing"; exit 1; }
grep -q "fileField" "$STAGE/api/app/src/Request.php" || { echo "REFUSING TO BUILD: Request::fileField() (multipart support) is missing"; exit 1; }

echo "--- copying canonical schema DDL (0001-0008, unchanged) ---"
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
# Amor Factory System — Store Photo Evidence Role Correction

FILES-ONLY correction. No new migration (0008 stays as the last one
applied — same schema as the previous receipt-evidence-verification
patch left it).

Quick facts:
- Photo evidence is STORE evidence. Only the public Store Receipt
  portal (api/_receive/) ever uploads it.
- Admin's role is now strictly VIEW + VERIFY: the Admin Konfirmasi Toko
  Detail page shows whatever the store uploaded (or "Tidak tersedia" for
  a legacy pre-patch receipt) and never offers an upload control.
- The old POST /api/admin/receipts/{id}/evidence route is REMOVED
  entirely (404s like any unknown route), not just hidden from the UI.
- A legacy discrepancy receipt with no store evidence (e.g. a real-UAT
  row confirmed before this rule existed) stays PERMANENTLY blocked from
  verification — it is never repaired and Admin can never fabricate
  evidence on the store's behalf.
- The Store-side flow itself (mandatory photo on Reject/Kurang > 0,
  image validation, mobile camera/gallery upload, idempotency) is
  UNCHANGED by this package.
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
         api/app/src/Fg/FgService.php api/app/src/Dispatch/EvidenceUploader.php; do
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this package must not touch stock deduction / PO / Production / User Management / FG logic / the store-side upload validator." >&2
    exit 1
  fi
done

echo "--- sanity: confirm ReceiptRepository's EXISTING confirm-write + evidence methods are still present verbatim ---"
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
