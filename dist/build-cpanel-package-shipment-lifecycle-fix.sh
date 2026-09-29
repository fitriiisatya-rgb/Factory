#!/usr/bin/env bash
# Builds dist/amor-factory-shipment-lifecycle-fix.zip — live-UAT lifecycle-
# consistency fix (Abdul Gani blocker): a receipt must never be confirmable
# before a shipment has genuinely departed, and a departed/received
# shipment must never remain shown as available to a Driver.
#
# CODE-ONLY, NO MIGRATION. Root cause: a `shipment` row is always created
# atomically with real shipped_by/shipped_at by Delivery\ShipmentService::
# ship() (no draft/staged shipment concept exists in this schema) — so a
# receipt could never actually precede real dispatch. The two real gaps
# were:
#   - Controllers\DoController::ship() (the manual "Kirim" admin action)
#     never created a shipment_email_delivery outbox row, unlike Dispatch\
#     DepartureService's driver-claim flow — leaving a genuinely departed
#     shipment reading "Belum Dikirim" forever.
#   - Dispatch\ReceiptService::confirmReceiptForDo() returned a bare
#     NOT_FOUND instead of a stable, distinct SHIPMENT_NOT_DISPATCHED code
#     when a DO has never had a real shipment depart.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-shipment-lifecycle-fix"
ZIP_PATH="$DIST_DIR/amor-factory-shipment-lifecycle-fix.zip"

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

echo "--- copying application (source/config-example/migrations/ui, current) ---"
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

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, current) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: NO new migration was introduced (0001-0016 only, exactly as before this patch) ---"
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009 0010 0011 0012 0013 0014 0015 0016; do
  find "$STAGE/api/app/migrations" -name "${m}_*" | grep -q . || { echo "REFUSING TO BUILD: migration $m is missing"; exit 1; }
done
find "$STAGE/api/app/migrations" -name '0017_*' | grep -q . && { echo "REFUSING TO BUILD: an unexpected migration 0017 was found — this task is code-only, no schema change." >&2; exit 1; }

echo "--- sanity: the two real fixes are present ---"
grep -q "createOutboxForShipment" "$STAGE/api/app/src/Controllers/DoController.php" || { echo "REFUSING TO BUILD: DoController::ship() no longer wires the email outbox creation."; exit 1; }
grep -q "ShipmentEmailService" "$STAGE/api/app/src/Controllers/DoController.php" || { echo "REFUSING TO BUILD: DoController.php is missing its ShipmentEmailService import."; exit 1; }
grep -q "SHIPMENT_NOT_DISPATCHED" "$STAGE/api/app/src/Dispatch/ReceiptService.php" || { echo "REFUSING TO BUILD: ReceiptService no longer returns the stable SHIPMENT_NOT_DISPATCHED code."; exit 1; }
grep -q "Belum Dikirim — konfirmasi penerimaan belum tersedia" "$STAGE/api/assets/js/receipt.js" || { echo "REFUSING TO BUILD: receipt.js no longer shows the pre-dispatch empty-state message."; exit 1; }

echo "--- sanity: no business rule OUTSIDE this fix's own scope was touched (git diff against the prior commit) ---"
CHANGED_FILES="$(cd "$REPO_ROOT" && git diff --name-only HEAD~1 HEAD)"
EXPECTED_FILES="api/app/src/Controllers/DoController.php
api/app/src/Dispatch/ReceiptService.php
api/assets/js/receipt.js
api/tests/Phase55DispatchReceiptTest.php"
if [ "$CHANGED_FILES" != "$EXPECTED_FILES" ]; then
  echo "REFUSING TO BUILD: the last commit touched files outside this fix's own scope." >&2
  echo "Expected exactly:" >&2
  echo "$EXPECTED_FILES" >&2
  echo "Got:" >&2
  echo "$CHANGED_FILES" >&2
  exit 1
fi

echo "--- sanity: PO/Production/FG/Packing/DO-quantity/stock_ledger/allocation/invoice logic is untouched (explicit forbidden-file list) ---"
for f in api/app/src/Delivery/ShipmentService.php api/app/src/Delivery/DoService.php \
         api/app/src/Delivery/DoRepository.php api/app/src/Production/ProductionTaskService.php \
         api/app/src/Fg/FgService.php api/app/src/SpecialOrder/SpecialOrderDoService.php \
         api/app/src/SpecialOrder/SpecialOrderFgAllocationRepository.php \
         api/app/src/Replacement/ReplacementService.php api/app/src/Replacement/ReplacementDoRepository.php \
         api/app/src/Dispatch/DispatchService.php api/app/src/Dispatch/DispatchRepository.php \
         api/app/src/Dispatch/DepartureService.php; do
  if echo "$CHANGED_FILES" | grep -qx "$f"; then
    echo "REFUSING TO BUILD: $f was modified — this task is shipment lifecycle consistency ONLY (no PO/Production/FG/Packing/DO-quantity/stock_ledger/allocation change allowed)." >&2
    exit 1
  fi
done

echo "--- copying canonical schema DDL (0001-0016, unchanged) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql schema-v1-0013-repair-production-flow-completion.sql \
         schema-v1-0014-production-fg-division-rework.sql schema-v1-0015-fg-store-packing-submission.sql \
         schema-v1-0016-replacement-reject.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Shipment / Driver / Receipt Lifecycle Consistency Fix

Live-UAT blocker fix (Abdul Gani case): CODE ONLY, no new migration.

## Root cause

A `shipment` row is ALWAYS created atomically with real shipped_by/
shipped_at by Delivery\ShipmentService::ship() — there is no draft/staged
shipment concept in this schema, so a receipt could never actually be
confirmed before a real shipment row existed. The confusing symptoms had
two real, separate causes:

1. Controllers\DoController::ship() (the manual "Kirim" admin button, a
   second entry point into ShipmentService::ship() alongside the Driver
   claim/departure flow) never created a shipment_email_delivery outbox
   row — so a genuinely departed shipment read "Belum Dikirim" forever.
2. Dispatch\ReceiptService::confirmReceiptForDo() returned a bare
   NOT_FOUND instead of a stable, distinct SHIPMENT_NOT_DISPATCHED code
   when a DO had never had a real shipment depart.

A DO that still shows *other, still-unshipped* line items in Driver
"Tersedia" after a *partial* manual ship is correct, by-design behavior
(remaining qty is computed per product) — not a bug.

## What changed

1. Controllers\DoController::ship() now creates the email outbox row
   inside the same transaction as ship() and attempts the send after
   commit, mirroring Controllers\DispatchController::departures()'s own
   pattern exactly.
2. Dispatch\ReceiptService::confirmReceiptForDo() now throws the stable
   SHIPMENT_NOT_DISPATCHED code (never a bare 404) when the targeted DO
   has no active shipment at all yet — a direct token/API call cannot
   bypass this by guessing a shipmentId, since no such row exists to find.
3. receipt.js's pre-dispatch empty state now reads "Belum Dikirim —
   konfirmasi penerimaan belum tersedia."

## What did NOT change

- PO, Production, FG, Packing, DO quantity logic, stock_ledger posting,
  Regular store FG ownership, Special allocation, Replacement allocation,
  Invoice — none of these files were touched (enforced by this build
  script's own sanity guards).
- Driver "Tersedia" query logic — already correctly excludes any product
  already shipped; no change was needed there.
- Email-independent dispatch gate — email failure never blocked dispatch
  or receipt before this fix, and still does not.

## Tests

SHIP-STATE-01..12 added to Phase55DispatchReceiptTest.php. Full
regression green: Phase 5.5 (107/107 tests, including the new
SHIP-STATE-01..12, plus the full P55-24 Phase 0-5 + UI + Print + Invoice
stack), and the PDFG/Special Order/Replacement Reject/FG-Allocation
cascade (123+35+35 tests, 0 failures).
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

echo "--- sanity: confirm the evidence upload directory ships deny-all and clean (no stray test photos) ---"
mkdir -p "$STAGE/api/uploads/receipt-evidence"
cp "$REPO_ROOT/api/uploads/receipt-evidence/.htaccess" "$STAGE/api/uploads/receipt-evidence/.htaccess"
if ! grep -q 'Require all denied' "$STAGE/api/uploads/receipt-evidence/.htaccess"; then
  echo "REFUSING TO BUILD: api/uploads/receipt-evidence/.htaccess must deny all direct HTTP access." >&2
  exit 1
fi
if find "$REPO_ROOT/api/uploads/receipt-evidence" -iname '*.png' -o -iname '*.jpg' 2>/dev/null | grep -q .; then
  echo "REFUSING TO BUILD: stray uploaded test evidence found in the repo's own uploads directory — clean it up before building." >&2
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
