#!/usr/bin/env bash
# Builds dist/amor-factory-api-phase55-dispatch-receipt-easy.zip — the
# Phase 5.5 Dispatch Pool / Driver Claim / Store Receipt package, for an
# EXISTING real deployment where Phase 5 (Draft DO / staged Shipment) and
# the UI redesign/print/invoice preview passes are already installed:
#   - api/ lives at public_html/factory/api/ (domain docroot is
#     public_html/factory/, not public_html/ itself),
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001-0006 are already applied — this package adds ONE
#     new migration (0007) that is PURELY ADDITIVE: 6 brand-new tables
#     (dispatch_claim, driver_route, driver_route_stop,
#     delivery_receipt_token, shipment_receipt, shipment_receipt_item) plus
#     one new role (DRIVER). No Phase 1-5 table is altered, renamed, or
#     dropped, and no Phase 1-5 business rule (stock ledger authority,
#     DO/shipment lifecycle, version/idempotency/CSRF/audit) is changed.
#   - every prior phase's own temporary wizard (api/_import-po/,
#     api/_production-uat/, api/_fg-uat/, api/_do-uat/) and the UI/print/
#     invoice preview layer (api/_ui-preview/, api/app/ui/) are ALL
#     preserved and refreshed in place — this package deletes nothing.
#
# INCREMENTAL, not a fresh install: extract this ZIP positioned INSIDE
# public_html/factory/ so it merges into the existing public_html/factory/api/
# — it overwrites code files but NEVER includes api/app/config/config.php,
# so the operator's existing DB credentials are untouched.
#
# New in this pass:
#   - api/app/migrations/0007_dispatch_receipt_phase55.php + database/
#     schema-v1-0007-dispatch-receipt-phase55.sql (additive schema + DRIVER
#     role insert — see that SQL file's own docblock for the full
#     table-by-table justification).
#   - api/app/src/Dispatch/{DispatchRepository,DispatchService,
#     DepartureService,ReceiptRepository,ReceiptService}.php (new) — claim/
#     release/route logic, and departure/receipt orchestration that calls
#     the EXISTING Delivery\ShipmentService::ship() for every real stock
#     write (never a parallel stock-deduction implementation).
#   - api/app/src/Controllers/{DispatchController,ReceiptController}.php
#     (new) + updated App.php routes (/api/dispatch/*, /api/receive/*,
#     /api/admin/receipts/*) and CSRF exemption for the PUBLIC receipt
#     confirm endpoint only (same "no session yet" reasoning as the
#     existing login exemption).
#   - api/app/src/Ui/QrEncoder.php (new) — a from-scratch, dependency-free
#     QR code encoder (this stack ships no vendor/ directory), used only
#     to render the DO receipt QR on the print template.
#   - api/_driver-uat/ (new) — mobile-first Driver portal (Tersedia/
#     Pengiriman Saya/Rute Saya/Riwayat + Konfirmasi Berangkat), requiring
#     the DRIVER (or ADMIN) role.
#   - api/_receive/ (new) — the PUBLIC Store Receipt confirmation portal,
#     reachable only via the DO's own high-entropy receipt token (never a
#     raw delivery_order_id).
#   - api/app/ui/pages/konfirmasi-toko.php (new) + layout.php nav entry —
#     Admin discrepancy verification page in the existing UI shell.
#   - api/app/ui/print-template.php / assets/css/print.css (updated) — a
#     read-only receipt QR now prints on every non-cancelled DO, labeled
#     "Scan untuk Konfirmasi Penerimaan Barang".
#   - api/app/ui/assets/{css,js}/{driver,receipt}.css/js (new) — client
#     code for the two new portals above.
#
# This ZIP contains NO Invoice/Payment/Receivable financial logic, NO
# Phase 7 Retur/Reject physical-return lifecycle, NO GPS/route
# optimization, and NO driver payroll — see the phase's own final report.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-phase55-dispatch-receipt"
ZIP_PATH="$DIST_DIR/amor-factory-api-phase55-dispatch-receipt-easy.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points (index.php + THE FIXED .htaccess) ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- sanity: confirm the shipped .htaccess has the real-directory bypass (-d), not just -f ---"
if ! grep -qE '^\s*RewriteCond %\{REQUEST_FILENAME\} -d\s*$' "$STAGE/api/.htaccess"; then
  echo "REFUSING TO BUILD: api/.htaccess is missing the '-d' (real directory) RewriteCond." >&2
  exit 1
fi
if ! grep -qE '^\s*RewriteCond %\{REQUEST_FILENAME\} -f' "$STAGE/api/.htaccess"; then
  echo "REFUSING TO BUILD: api/.htaccess is missing the '-f' (real file) RewriteCond." >&2
  exit 1
fi

echo "--- copying every existing tool, unchanged, for a clean overwrite (nothing deleted) ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview; do
  mkdir -p "$STAGE/api/$tool"
  cp -r "$REPO_ROOT/api/$tool/." "$STAGE/api/$tool/"
done

echo "--- copying the Phase 5.5 Driver portal (new) ---"
mkdir -p "$STAGE/api/_driver-uat"
cp -r "$REPO_ROOT/api/_driver-uat/." "$STAGE/api/_driver-uat/"
[ -f "$STAGE/api/_driver-uat/index.php" ] || { echo "REFUSING TO BUILD: driver portal index.php missing"; exit 1; }
[ -f "$STAGE/api/_driver-uat/stop.php" ] || { echo "REFUSING TO BUILD: driver portal stop.php missing"; exit 1; }

echo "--- copying the Phase 5.5 PUBLIC Store Receipt portal (new) ---"
mkdir -p "$STAGE/api/_receive"
cp -r "$REPO_ROOT/api/_receive/." "$STAGE/api/_receive/"
[ -f "$STAGE/api/_receive/index.php" ] || { echo "REFUSING TO BUILD: public receipt portal index.php missing"; exit 1; }

echo "--- copying application (source/config-example/migrations/ui, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
cp -r "$REPO_ROOT/api/app/ui" "$STAGE/api/app/ui"
[ -d "$STAGE/api/app/src/Dispatch" ] || { echo "REFUSING TO BUILD: api/app/src/Dispatch/ missing"; exit 1; }
[ -f "$STAGE/api/app/src/Ui/QrEncoder.php" ] || { echo "REFUSING TO BUILD: QrEncoder.php missing"; exit 1; }
[ -f "$STAGE/api/app/ui/pages/konfirmasi-toko.php" ] || { echo "REFUSING TO BUILD: konfirmasi-toko.php admin page missing"; exit 1; }
[ -f "$STAGE/api/app/ui/assets/js/driver.js" ] || { echo "REFUSING TO BUILD: driver.js missing"; exit 1; }
[ -f "$STAGE/api/app/ui/assets/js/receipt.js" ] || { echo "REFUSING TO BUILD: receipt.js missing"; exit 1; }
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"
[ -f "$STAGE/api/app/migrations/0007_dispatch_receipt_phase55.php" ] || { echo "REFUSING TO BUILD: migration 0007 missing"; exit 1; }

echo "--- copying canonical schema DDL (0001-0007) ---"
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
# Amor Factory System — Phase 5.5 Dispatch Pool / Driver Claim / Store Receipt Package

INCREMENTAL update for an existing Phase 5 (Draft DO / Shipment) + UI/print/
invoice preview deployment. This domain's document root is
public_html/factory/ — extract this ZIP positioned INSIDE
public_html/factory/ so it merges into your existing
public_html/factory/api/. It overwrites code files but NEVER includes
app/config/config.php, so your database credentials are untouched.

**Start here**: dist/README-FIRST-CPANEL-PHASE55-DISPATCH-RECEIPT.md
(delivered alongside this ZIP) has the full step-by-step, non-technical
guide.

Quick facts:
- Log in as ADMIN: api/_admin-login/ (unchanged)
- Apply migration 0007 (ADDITIVE ONLY — 6 new tables + 1 new role, no
  existing table touched): api/_upgrade/
- New Driver portal (requires a user with the DRIVER role — create one the
  same way you create any user today, then assign the DRIVER role):
  api/_driver-uat/
- New PUBLIC Store Receipt portal (no login — reachable only via the QR
  now printed on each Draft/Preprint/Preprinted/Shipped DO):
  api/_receive/?token=...
- New Admin page "Konfirmasi Toko" inside the existing UI
  (api/_ui-preview/?page=konfirmasi-toko) — review store confirmations and
  verify discrepancies.
- Claiming a delivery task, viewing a route, or opening/scanning the
  receipt QR NEVER writes to stock or changes any DO/shipment — only a
  real "Konfirmasi Berangkat" (Confirm Departure) action creates a real
  Phase 5 shipment and deducts stock, exactly once, via the SAME
  ShipmentService Phase 5 already used for manual shipping.
- This package does NOT implement Invoice, Payment, Receivable aging, or
  the Phase 7 physical Retur/Reject return lifecycle.
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

echo "--- sanity: confirm every prior UAT tool + UI preview is present (nothing was accidentally dropped) ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview _driver-uat _receive; do
  if [ ! -f "$STAGE/api/$tool/index.php" ]; then
    echo "REFUSING TO BUILD: api/$tool/index.php is missing — old tools must never be dropped by this package." >&2
    exit 1
  fi
done

echo "--- sanity: confirm no migration 0008+ was accidentally introduced (this package adds ONLY 0007) ---"
if find "$STAGE/api/app/migrations" -name '0008_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration beyond 0007 was found." >&2
  exit 1
fi

echo "--- sanity: confirm NO Invoice/Payment/Receivable business logic was added (out of scope for this package) ---"
if find "$STAGE/api/app/src" -iname '*invoice*' -o -iname '*payment*' -o -iname '*receivable*' | grep -q .; then
  echo "REFUSING TO BUILD: an Invoice/Payment/Receivable file was found under api/app/src — out of scope for Phase 5.5." >&2
  exit 1
fi

echo "--- sanity: confirm NO Phase 7 physical Retur/Reject return-lifecycle logic was added ---"
if find "$STAGE/api/app/src" -iname '*retur*' -o -iname '*reject_note*' -o -iname '*returnlifecycle*' | grep -q .; then
  echo "REFUSING TO BUILD: a Retur/Reject lifecycle file was found — out of scope for Phase 5.5 (only received_good/reject/shortage QTY fields are stored)." >&2
  exit 1
fi

echo "--- sanity: confirm the public receive portal has no session/role check that would break its public-token design ---"
if grep -q "Auth::requireAuth\|Auth::requireRole" "$STAGE/api/_receive/index.php" 2>/dev/null; then
  echo "REFUSING TO BUILD: api/_receive/index.php must stay public (token-only access) — an auth check was found." >&2
  exit 1
fi

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
