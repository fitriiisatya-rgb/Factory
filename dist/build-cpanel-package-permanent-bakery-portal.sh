#!/usr/bin/env bash
# Builds dist/amor-factory-permanent-bakery-portal.zip — the Permanent
# Bakery Portal (migration 0017): ONE permanent, no-login, token-based
# link per bakery store covering Konfirmasi Penerimaan (Reject lives
# inside it), Pesanan Khusus, Retur, Mutasi Produk, and Riwayat, plus
# Admin-side controls (token management, Retur Review, Mutasi Review).
#
# Migration 0017 is purely additive on top of 0001-0016 (all untouched).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-permanent-bakery-portal"
ZIP_PATH="$DIST_DIR/amor-factory-permanent-bakery-portal.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points (index.php + .htaccess, unchanged) ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- copying every existing tool, unchanged/updated, for a clean overwrite (nothing dropped), plus the NEW _store/ Permanent Portal ---"
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

echo "--- sanity: confirm migration 0017 is present, and 0001-0016 are COMPLETELY untouched byte-for-byte ---"
[ -f "$STAGE/api/app/migrations/0017_permanent_bakery_portal.php" ] || { echo "REFUSING TO BUILD: migration 0017 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0018_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0018+ was found — this phase's own scope is 0017 only." >&2
  exit 1
fi
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009 0010 0011 0012 0013 0014 0015 0016; do
  f="$(find "$REPO_ROOT/api/app/migrations" -name "${m}_*" | head -1)"
  [ -n "$f" ] || { echo "REFUSING TO BUILD: migration $m is missing from the repo"; exit 1; }
  s="$STAGE/api/app/migrations/$(basename "$f")"
  [ -f "$s" ] || { echo "REFUSING TO BUILD: migration $m is missing from the staged package"; exit 1; }
  if ! diff -q "$f" "$s" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: migration $m differs from the repo — migrations 0001-0016 must stay byte-for-byte untouched." >&2
    exit 1
  fi
done

echo "--- sanity: confirm migration 0017 stays additive (no DROP TABLE / DROP COLUMN / ALTER on an existing table, no destructive statement) ---"
if grep -qEi "DROP TABLE|DROP COLUMN|TRUNCATE" "$REPO_ROOT/database/schema-v1-0017-permanent-bakery-portal.sql"; then
  echo "REFUSING TO BUILD: migration 0017 contains a destructive statement." >&2
  exit 1
fi
if grep -qEi "^ALTER TABLE" "$REPO_ROOT/database/schema-v1-0017-permanent-bakery-portal.sql"; then
  echo "REFUSING TO BUILD: migration 0017 alters an existing table — this phase is six brand-new tables only (store_portal_token/retur_request/retur_request_evidence/mutasi_request/mutasi_request_evidence/special_order_attachment), never a modification to anything from 0001-0016." >&2
  exit 1
fi
for t in store_portal_token retur_request retur_request_evidence mutasi_request mutasi_request_evidence special_order_attachment; do
  grep -q "CREATE TABLE IF NOT EXISTS $t " "$REPO_ROOT/database/schema-v1-0017-permanent-bakery-portal.sql" || { echo "REFUSING TO BUILD: table $t is missing from migration 0017."; exit 1; }
done

echo "--- sanity: confirm the core LOCKED business rules are present in source ---"
grep -q "class StorePortalService" "$STAGE/api/app/src/StorePortal/StorePortalService.php" || { echo "REFUSING TO BUILD: StorePortalService.php is missing — the SOLE access-control mechanism for the Portal."; exit 1; }
grep -q "function resolvePortalIdentity" "$STAGE/api/app/src/StorePortal/StorePortalService.php" || { echo "REFUSING TO BUILD: resolvePortalIdentity() is missing — every Portal controller must resolve storeId from the token, never trust a client-supplied storeId."; exit 1; }
grep -q "class ReturService" "$STAGE/api/app/src/StorePortal/ReturService.php" || { echo "REFUSING TO BUILD: ReturService.php is missing."; exit 1; }
if grep -qiE "INSERT INTO stock_ledger|UPDATE stock_ledger" "$STAGE/api/app/src/StorePortal/ReturService.php" "$STAGE/api/app/src/StorePortal/ReturRepository.php"; then
  echo "REFUSING TO BUILD: Retur must NEVER write stock_ledger (100% store financial burden, never auto-adjusts Factory stock) — found a stock_ledger write in the Retur module." >&2
  exit 1
fi
if grep -qiE "INSERT INTO stock_ledger|UPDATE stock_ledger" "$STAGE/api/app/src/StorePortal/MutasiService.php" "$STAGE/api/app/src/StorePortal/MutasiRepository.php"; then
  echo "REFUSING TO BUILD: Mutasi must NEVER write stock_ledger (never changes Factory stock) — found a stock_ledger write in the Mutasi module." >&2
  exit 1
fi
grep -q "EVIDENCE_REQUIRED" "$STAGE/api/app/src/StorePortal/ReturService.php" || { echo "REFUSING TO BUILD: Retur no longer enforces mandatory evidence."; exit 1; }
grep -q "EVIDENCE_REQUIRED" "$STAGE/api/app/src/StorePortal/MutasiService.php" || { echo "REFUSING TO BUILD: Mutasi no longer enforces mandatory evidence on submission/mismatch."; exit 1; }
grep -q "function receiptConfirm" "$STAGE/api/app/src/Controllers/StorePortalController.php" || { echo "REFUSING TO BUILD: Konfirmasi Penerimaan (with Reject inside it) is missing from StorePortalController."; exit 1; }
if grep -qE "function reject\(|/reject\b" "$STAGE/api/app/src/Controllers/StorePortalController.php"; then
  echo "REFUSING TO BUILD: a STANDALONE Reject endpoint was found on the Portal controller — Reject must live inside Konfirmasi Penerimaan only, never its own menu/route." >&2
  exit 1
fi

echo "--- sanity: confirm the existing /api/_receive/ portal (Regular DO receipt) stays completely untouched ---"
if ! diff -q "$REPO_ROOT/api/_receive/index.php" "$STAGE/api/_receive/index.php" > /dev/null 2>&1; then
  echo "REFUSING TO BUILD: api/_receive/index.php differs from the repo — the existing per-shipment receive portal must stay byte-for-byte untouched." >&2
  exit 1
fi

echo "--- sanity: confirm the automatic Bakery email's CTA points at the Permanent Portal, and the onboarding-token contract is intact ---"
grep -q "getOrIssueTokenForEmailOnboarding" "$STAGE/api/app/src/Mail/ShipmentEmailService.php" || { echo "REFUSING TO BUILD: ShipmentEmailService no longer uses the onboarding-token contract."; exit 1; }
grep -q "/api/_store/" "$STAGE/api/app/src/Mail/ShipmentEmailService.php" || { echo "REFUSING TO BUILD: ShipmentEmailService's email no longer links to the Permanent Portal."; exit 1; }

echo "--- sanity: confirm Admin-side controls are present (token mgmt + Retur/Mutasi review) ---"
[ -f "$STAGE/api/app/ui/pages/bakery-portal-tokens.php" ] || { echo "REFUSING TO BUILD: Admin Portal Bakery token-management page is missing."; exit 1; }
[ -f "$STAGE/api/app/ui/pages/retur-review.php" ] || { echo "REFUSING TO BUILD: Admin Retur Review page is missing."; exit 1; }
[ -f "$STAGE/api/app/ui/pages/mutasi-review.php" ] || { echo "REFUSING TO BUILD: Admin Mutasi Review page is missing."; exit 1; }
grep -q "bakery-portal-tokens" "$STAGE/api/app/ui/layout.php" || { echo "REFUSING TO BUILD: the Admin sidebar no longer links to Portal Bakery token management."; exit 1; }

echo "--- sanity: confirm NO business rule / server-side validation file OUTSIDE this phase's own scope changed byte-for-byte ---"
for f in api/app/src/Delivery/ShipmentService.php api/app/src/Delivery/DoService.php \
         api/app/src/Controllers/DoController.php api/app/src/Controllers/DispatchController.php \
         api/app/src/Dispatch/DispatchService.php api/app/src/Dispatch/DispatchRepository.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionService.php \
         api/app/src/Production/ProductionRepository.php api/app/src/Controllers/ProductionController.php \
         api/app/src/Fg/FgService.php api/app/src/Fg/FgRepository.php api/app/src/Users/UserService.php \
         api/app/src/Mail/ShipmentEmailRepository.php api/app/src/SpecialOrder/SpecialOrderDoService.php \
         api/app/src/SpecialOrder/NormalizedSourceType.php api/app/src/Dispatch/ShipmentLineResolver.php \
         api/assets/js/receipt.js api/assets/css/receipt.css api/assets/css/print.css \
         api/_driver-uat/login.php api/_driver-uat/shipment.php api/_driver-uat/stop.php \
         api/app/ui/pages/produksi.php api/app/ui/pages/pengiriman.php api/app/ui/pages/konfirmasi-toko.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this phase." >&2
    exit 1
  fi
done
# ReceiptService.php/ReceiptRepository.php/EvidenceUploader.php/App.php/
# ShipmentEmailService.php/layout.php/SpecialOrderService.php/
# SpecialOrderRepository.php/SpecialOrderController.php ARE expected to
# differ (this phase's own core deliverable, additive widening only) —
# checked for the specific required additions above instead of byte-diffed.

echo "--- copying canonical schema DDL (0001-0017) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql schema-v1-0013-repair-production-flow-completion.sql \
         schema-v1-0014-production-fg-division-rework.sql schema-v1-0015-fg-store-packing-submission.sql \
         schema-v1-0016-replacement-reject.sql schema-v1-0017-permanent-bakery-portal.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Permanent Bakery Portal (migration 0017)

Purely additive on top of 0001-0016 — six brand-new tables only:
store_portal_token, retur_request, retur_request_evidence,
mutasi_request, mutasi_request_evidence, special_order_attachment.

ONE permanent, no-login, token-based link per bakery store
(api/_store/?token=...), covering:
  - Konfirmasi Penerimaan (Reject lives inside it, never a standalone menu)
  - Pesanan Khusus (reuses the existing SpecialOrderService engine)
  - Retur (100% store financial burden — NEVER reduces the invoice,
    NEVER auto-adjusts Factory stock_ledger)
  - Mutasi Produk (NEVER changes Factory stock_ledger; dual
    confirmation; qty_received is the future-invoice-authoritative figure,
    immutable after completion)
  - Riwayat (read-only aggregate across all of the above, this store only)

Plus Admin-side controls: Portal Bakery token management
(Generate/Regenerate/Revoke, one-time-shown link), Retur Review
(Verify/Reject), Mutasi Review (discrepancy resolution).

The automatic Bakery email's CTA now points at this permanent link
instead of the old per-shipment /api/_receive/ link — which stays
completely untouched, so every historic email already sent keeps
working exactly as before. A store's first-ever notification doubles
as onboarding (embeds a one-click deep link); every later email is a
bare reminder, since the raw token can never be re-shown once issued.
EOF

find "$STAGE" -name '.DS_Store' -delete 2>/dev/null || true
find "$STAGE" -name 'Thumbs.db' -delete 2>/dev/null || true

echo "--- sanity: confirm no config.php (real credentials) made it in ---"
if find "$STAGE" -name 'config.php' | grep -q .; then
  echo "REFUSING TO BUILD: a config.php was found in the staging tree — this must never ship." >&2
  find "$STAGE" -name 'config.php' >&2
  exit 1
fi

echo "--- sanity: confirm retired wizard directories are not reintroduced ---"
if [ -d "$STAGE/api/_setup" ] || [ -d "$STAGE/api/_import-master" ]; then
  echo "REFUSING TO BUILD: a retired wizard directory was found in the staging tree." >&2
  exit 1
fi

echo "--- sanity: confirm every prior UAT tool + UI preview + the NEW Permanent Portal are present ---"
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

echo "--- sanity: confirm every NEW evidence/attachment upload directory ships deny-all and clean (no stray test photos) ---"
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
