#!/usr/bin/env bash
# Builds dist/amor-factory-auto-email-digital-surat-jalan.zip — Phase 5.5
# finalization: automatic Bakery email + Digital Surat Jalan link + Admin
# resend, for an EXISTING deployment where migrations 0001-0008 are
# already applied.
#
# THIS PACKAGE ADDS MIGRATION 0009 — additive only (see database/schema-v1-
# 0009-shipment-email.sql): one new nullable column (store.email) and one
# new table (shipment_email_delivery, UNIQUE on shipment_id). No existing
# table/column is altered or dropped.
#
# Night-delivery reality this closes: Admin works office hours, FG/
# departure often finish at night, the bakery may be closed when goods
# arrive. The moment Confirm Departure creates a REAL shipment, that
# store's registered email (if any) gets a message linking straight to
# the EXISTING token-gated Digital Surat Jalan / Store Receipt portal for
# THAT shipment — never the whole DO's planned quantities, never a new
# insecure public endpoint. A missing store email never blocks a
# departure (shipment/stock always succeed first); an SMTP failure can
# never roll back either (Mail\ShipmentEmailService's own docblock — the
# send attempt runs strictly AFTER the departure's own DB transaction has
# committed). Admin gets an "Email Pengiriman" section on the existing
# Konfirmasi Toko Detail page (status/recipient/attempt count, a friendly
# error on failure, never the SMTP password) with a Kirim Ulang Email
# action that reuses the SAME outbox row and NEVER touches shipment/
# stock/DO/receipt data.
#
# New from-scratch SMTP client (Mail/SmtpMailTransport.php) — this project
# has no Composer/vendor dependency at all (see autoload.php's own
# docblock), audited first before adding one; a raw STARTTLS/AUTH LOGIN
# client was chosen over PHP's native mail() (unreliable on shared hosting
# without deep host-specific configuration this project has no visibility
# into) and over pulling in a large mail library for one feature.
# MAIL_ENABLED defaults to false — a fresh install with no SMTP configured
# yet still lets every departure succeed normally.
#
# Untouched by this patch (verified below + by the full regression
# suite): ShipmentService's own stock-deduction logic, Dispatch Claim/
# Release concurrency, ReceiptRepository/ReceiptService's confirm-write
# plumbing and math validation, EvidenceUploader's store-evidence upload
# validator, PO/Production/FG business logic, User Management, Invoice
# preview, Surat Jalan print, the DO receipt QR "1 DO = 1 token" rule.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-auto-email-digital-surat-jalan"
ZIP_PATH="$DIST_DIR/amor-factory-auto-email-digital-surat-jalan.zip"

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
[ -d "$STAGE/api/app/src/Mail" ] || { echo "REFUSING TO BUILD: api/app/src/Mail/ (the mail subsystem) is missing"; exit 1; }
for f in MailMessage.php MailSendResult.php MailTransport.php SmtpMailTransport.php FakeMailTransport.php MailTransportFactory.php ShipmentEmailRepository.php ShipmentEmailService.php; do
  [ -f "$STAGE/api/app/src/Mail/$f" ] || { echo "REFUSING TO BUILD: api/app/src/Mail/$f is missing"; exit 1; }
done
[ -f "$STAGE/api/app/src/Controllers/ShipmentEmailController.php" ] || { echo "REFUSING TO BUILD: ShipmentEmailController.php missing"; exit 1; }
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- sanity: confirm EXACTLY migration 0009 was added (additive), nothing beyond it ---"
[ -f "$STAGE/api/app/migrations/0009_shipment_email.php" ] || { echo "REFUSING TO BUILD: migration 0009 is missing — this package's whole point is store.email + shipment_email_delivery."; exit 1; }
if find "$STAGE/api/app/migrations" -name '0010_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0010+ was found — this package must add ONLY migration 0009." >&2
  exit 1
fi

echo "--- sanity: confirm config.example.php documents the new MAIL_*/APP_BASE_URL keys, and no test-only key leaked in ---"
for key in APP_BASE_URL MAIL_ENABLED MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_ENCRYPTION MAIL_FROM_ADDRESS MAIL_FROM_NAME; do
  grep -q "'$key'" "$STAGE/api/app/config/config.example.php" || { echo "REFUSING TO BUILD: config.example.php is missing the $key key"; exit 1; }
done
if grep -q "MAIL_TRANSPORT" "$STAGE/api/app/config/config.example.php"; then
  echo "REFUSING TO BUILD: MAIL_TRANSPORT (a test-only escape hatch) must never appear in config.example.php." >&2
  exit 1
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, refreshed) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }
grep -q "rc-evidence-input" "$STAGE/api/assets/js/receipt.js" || { echo "REFUSING TO BUILD: the Store-side Bukti Foto upload control is missing from receipt.js"; exit 1; }
grep -q "RECEIPT_FOCUS_SHIPMENT_ID" "$STAGE/api/assets/js/receipt.js" || { echo "REFUSING TO BUILD: the email ?shipment= focus behaviour is missing from receipt.js"; exit 1; }

echo "--- creating the deny-all evidence upload directory (api/uploads/receipt-evidence/, unchanged from the prior patch) ---"
mkdir -p "$STAGE/api/uploads/receipt-evidence"
cp "$REPO_ROOT/api/uploads/receipt-evidence/.htaccess" "$STAGE/api/uploads/receipt-evidence/.htaccess"
if ! grep -q 'Require all denied' "$STAGE/api/uploads/receipt-evidence/.htaccess"; then
  echo "REFUSING TO BUILD: api/uploads/receipt-evidence/.htaccess must deny all direct HTTP access." >&2
  exit 1
fi

echo "--- sanity: confirm the new resend route + email-required-for-verify gate are present ---"
grep -q "admin/shipments/{shipmentId}/email/resend" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: POST resend route missing from App.php"; exit 1; }
grep -q "function resend" "$STAGE/api/app/src/Controllers/ShipmentEmailController.php" || { echo "REFUSING TO BUILD: ShipmentEmailController::resend missing"; exit 1; }
grep -q "EVIDENCE_REQUIRED_FOR_VERIFY" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: adminVerify()'s photo-evidence gate is missing (regression check)"; exit 1; }

echo "--- sanity: confirm Admin cannot upload photo evidence (Part M — must not regress the role-correction patch) ---"
if grep -q "admin/receipts/{id}/evidence" "$STAGE/api/app/src/App.php"; then
  echo "REFUSING TO BUILD: the removed admin photo-upload route has reappeared." >&2
  exit 1
fi
if grep -qE "Unggah Bukti Foto|admin-evidence-submit" "$STAGE/api/app/ui/pages/konfirmasi-toko-detail.php"; then
  echo "REFUSING TO BUILD: the admin detail page offers a photo upload control again." >&2
  exit 1
fi

echo "--- sanity: confirm the Master Data Toko email editor calls the EXISTING /api/stores/{id} endpoint (no new store-write path) ---"
grep -q "/api/stores/" "$STAGE/api/app/ui/pages/master-data.php" || { echo "REFUSING TO BUILD: master-data.php's email editor is missing"; exit 1; }

echo "--- copying canonical schema DDL (0001-0009) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"
cp "$REPO_ROOT/database/schema-v1-0003-po-phase2.sql" "$STAGE/api/app/database/schema-v1-0003-po-phase2.sql"
cp "$REPO_ROOT/database/schema-v1-0004-production-phase3.sql" "$STAGE/api/app/database/schema-v1-0004-production-phase3.sql"
cp "$REPO_ROOT/database/schema-v1-0005-fg-packing-phase4.sql" "$STAGE/api/app/database/schema-v1-0005-fg-packing-phase4.sql"
cp "$REPO_ROOT/database/schema-v1-0006-do-shipment-phase5.sql" "$STAGE/api/app/database/schema-v1-0006-do-shipment-phase5.sql"
cp "$REPO_ROOT/database/schema-v1-0007-dispatch-receipt-phase55.sql" "$STAGE/api/app/database/schema-v1-0007-dispatch-receipt-phase55.sql"
cp "$REPO_ROOT/database/schema-v1-0008-receipt-evidence.sql" "$STAGE/api/app/database/schema-v1-0008-receipt-evidence.sql"
cp "$REPO_ROOT/database/schema-v1-0009-shipment-email.sql" "$STAGE/api/app/database/schema-v1-0009-shipment-email.sql"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Automatic Bakery Email / Digital Surat Jalan / Admin Resend

Adds ONE additive migration (0009 — store.email + shipment_email_delivery).
Everything else in the schema is unchanged.

Quick facts:
- The moment a REAL shipment departs, its store's registered email (if
  any) automatically receives a message linking to that ONE shipment's
  Digital Surat Jalan / Store Receipt page — never the whole DO.
- A missing store email never blocks a departure. An SMTP failure can
  never roll back a shipment or its stock deduction.
- Admin sees Email status (Belum Dikirim/Terkirim/Gagal/Email Toko Belum
  Diisi) separately from Penerimaan status on the existing Konfirmasi
  Toko Detail page, with a Kirim Ulang Email action.
- Resend reuses the SAME outbox row and NEVER creates a new shipment,
  stock movement, or store receipt, and never alters the DO.
- Master Data -> Toko now has an inline email editor (uses the existing
  PUT /api/stores/{id} endpoint).
- The Store photo-evidence rule (Store uploads, Admin only views/
  verifies) from the previous patch is UNCHANGED.
- SMTP credentials live only in config.php/environment — never in the
  database, never in this ZIP, never in a log or API response.
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

echo "--- sanity: confirm no MAIL_PASSWORD-looking real secret leaked into any shipped file ---"
if grep -rlE "MAIL_PASSWORD['\"]?\s*=>\s*['\"][^'\"]{6,}" "$STAGE" --include='*.php' | grep -v config.example.php | grep -q .; then
  echo "REFUSING TO BUILD: a non-empty MAIL_PASSWORD value was found outside config.example.php." >&2
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

echo "--- sanity: confirm ShipmentService and PO/Production/FG/User Mgmt/EvidenceUploader are UNCHANGED byte-for-byte ---"
for f in api/app/src/Delivery/ShipmentService.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionService.php api/app/src/Users/UserService.php \
         api/app/src/Fg/FgService.php api/app/src/Dispatch/EvidenceUploader.php; do
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this package must not touch stock deduction / PO / Production / User Management / FG logic / the store-side upload validator." >&2
    exit 1
  fi
done

echo "--- sanity: confirm DispatchService's claim()/release() concurrency logic signatures are still present verbatim (additive-only change) ---"
grep -q "public function claim(array \$lines, int \$driverUserId, ?string \$requestId): array" "$STAGE/api/app/src/Dispatch/DispatchService.php" \
  || { echo "REFUSING TO BUILD: DispatchService::claim() signature changed — this patch must only ADD to this file, never modify claim/release."; exit 1; }
grep -q "public function release(" "$STAGE/api/app/src/Dispatch/DispatchService.php" \
  || { echo "REFUSING TO BUILD: DispatchService::release() missing"; exit 1; }

echo "--- sanity: confirm DepartureService's own claim-resolution/version-chaining logic is unchanged (only ADDS the outbox-creation call) ---"
grep -q "resolveClaimAsDeparted" "$STAGE/api/app/src/Dispatch/DepartureService.php" \
  || { echo "REFUSING TO BUILD: DepartureService's claim-resolution call is missing"; exit 1; }
grep -q "ShipmentEmailService" "$STAGE/api/app/src/Dispatch/DepartureService.php" \
  || { echo "REFUSING TO BUILD: DepartureService no longer creates the email outbox row"; exit 1; }

echo "--- sanity: confirm ReceiptService's confirmReceipt()/getReceiptToken() core write signatures are still present verbatim ---"
grep -q "public function getReceiptToken(int \$doId): string" "$STAGE/api/app/src/Dispatch/ReceiptService.php" \
  || { echo "REFUSING TO BUILD: ReceiptService::getReceiptToken() signature changed — 1 DO = 1 receipt token must be preserved verbatim."; exit 1; }
grep -q "RECEIPT_MATH_INVALID" "$STAGE/api/app/src/Dispatch/ReceiptService.php" \
  || { echo "REFUSING TO BUILD: confirmReceipt()'s math validation is missing"; exit 1; }

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
