#!/usr/bin/env bash
# Builds dist/amor-factory-reject-qty-lineage-fix.zip — live-UAT reject
# quantity lineage fix: the Replacement Reject "Tindak Lanjut Reject"
# worklist now shows exactly which shipment/DO each pending reject row
# came from, closing the traceability gap that made two separate
# shipments' rejects for the same store+product hard to tell apart.
#
# CODE-ONLY, NO MIGRATION. Root-cause audit (proven via a live
# two-partial-shipment reproduction test, REJECT-QTY-01..12) found the
# reject_qty read pipeline already carried the correct per-row value end
# to end — one row per shipment_receipt_item_id, unique-keyed by
# shipment_item_id, no COUNT(*)/boolean/hardcoded-default anywhere.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-reject-qty-lineage-fix"
ZIP_PATH="$DIST_DIR/amor-factory-reject-qty-lineage-fix.zip"

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

echo "--- sanity: the traceability fix is present ---"
grep -q "replacement_doc_no" "$STAGE/api/app/src/Replacement/ReplacementRepository.php" || { echo "REFUSING TO BUILD: findPendingDispositionItems() no longer resolves docNo across shipment sources."; exit 1; }
grep -q "'docNo' =>" "$STAGE/api/app/src/Replacement/ReplacementService.php" || { echo "REFUSING TO BUILD: pendingDisposition() no longer exposes docNo in its DTO."; exit 1; }
grep -q "No. DO" "$STAGE/api/app/ui/pages/replacement-reject.php" || { echo "REFUSING TO BUILD: the worklist table no longer shows the No. DO column."; exit 1; }
grep -q "Shipment #" "$STAGE/api/app/ui/pages/replacement-reject.php" || { echo "REFUSING TO BUILD: the worklist table no longer shows the Shipment # column."; exit 1; }

echo "--- sanity: no business rule OUTSIDE this fix's own scope was touched (git diff against the prior commit) ---"
CHANGED_FILES="$(cd "$REPO_ROOT" && git diff --name-only HEAD~1 HEAD)"
EXPECTED_FILES="api/app/src/Replacement/ReplacementRepository.php
api/app/src/Replacement/ReplacementService.php
api/app/ui/pages/replacement-reject.php
api/tests/ProductionDivisionFgReworkTest.php"
if [ "$CHANGED_FILES" != "$EXPECTED_FILES" ]; then
  echo "REFUSING TO BUILD: the last commit touched files outside this fix's own scope." >&2
  echo "Expected exactly:" >&2
  echo "$EXPECTED_FILES" >&2
  echo "Got:" >&2
  echo "$CHANGED_FILES" >&2
  exit 1
fi

echo "--- sanity: reject_qty ITSELF, stock, FG, Production, DO qty, invoice, and the allocation formula are untouched (explicit forbidden-file list) ---"
for f in api/app/src/Dispatch/ReceiptService.php api/app/src/Dispatch/ReceiptRepository.php \
         api/app/src/Delivery/ShipmentService.php api/app/src/Delivery/DoService.php \
         api/app/src/Delivery/DoRepository.php api/app/src/Production/ProductionTaskService.php \
         api/app/src/Fg/FgService.php api/app/src/SpecialOrder/SpecialOrderDoService.php \
         api/app/src/SpecialOrder/SpecialOrderFgAllocationRepository.php \
         api/app/src/Replacement/ReplacementFgAllocationService.php \
         api/app/src/Replacement/ReplacementFgAllocationRepository.php \
         api/app/src/Replacement/ReplacementDoService.php api/app/src/Replacement/ReplacementDoRepository.php \
         api/app/src/Controllers/DoController.php api/app/src/Controllers/ReceiptController.php \
         api/app/src/Controllers/ReplacementController.php; do
  if echo "$CHANGED_FILES" | grep -qx "$f"; then
    echo "REFUSING TO BUILD: $f was modified — this task is reject quantity lineage + Replacement Reject display/action accuracy ONLY (no stock/FG/Production/DO-qty/invoice/allocation-formula change allowed)." >&2
    exit 1
  fi
done

echo "--- sanity: the disposition decision quantity is still bounded by the FULL reported reject qty (no silent capping) ---"
grep -q "approvedQty > (float) \$item\['reject_qty'\]" "$STAGE/api/app/src/Replacement/ReplacementService.php" || { echo "REFUSING TO BUILD: disposeReject() no longer validates approvedQty against the full reported reject_qty."; exit 1; }

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
# Amor Factory System — Reject Quantity Lineage Fix (Replacement Reject Worklist)

Live-UAT blocker fix: CODE ONLY, no new migration.

## Root cause

Audited end to end (ReplacementRepository::findPendingDispositionItems()
-> ReplacementService::pendingDisposition() -> replacement-reject.php)
and proven with a live two-partial-shipment reproduction test
(REJECT-QTY-01..12): the reject quantity pipeline already carried the
real per-row value correctly — one row per shipment_receipt_item_id,
unique-keyed by shipment_item_id (two separate shipments can never
collide), no COUNT(*), no boolean cast, no hardcoded default anywhere.

The genuine gap: the "Tindak Lanjut Reject" worklist never showed WHICH
shipment/DO each pending row came from — so two rows for the same
store+product (e.g. a DO shipped in two separate partial batches) were
hard to tell apart at a glance.

## What changed

1. ReplacementRepository::findPendingDispositionItems() now also
   resolves each row's real doc_no across all three shipment sources
   (Regular DO, Replacement DO, Special Order DO) — display-only
   enrichment, the WHERE clause and one-row-per-receipt-item shape are
   unchanged.
2. ReplacementService::pendingDisposition()'s DTO now includes docNo
   alongside the existing shipmentId.
3. replacement-reject.php's worklist table now shows "No. DO" and
   "Shipment #" columns so Admin can immediately distinguish which
   shipment each reject qty belongs to.

## What did NOT change

- reject_qty itself, shipment/receipt confirmation, stock_ledger,
  FG, Production, DO planned qty, invoice logic, the Replacement
  allocation formula, and shipment allocation safety — none of these
  files were touched (enforced by this build script's own sanity
  guards).
- The decision-quantity input still defaults to and is capped at the
  FULL reported reject quantity — no partial-approval behavior was
  invented.

## Tests

REJECT-QTY-01..12 added to ProductionDivisionFgReworkTest.php,
including a live reproduction of the reported scenario (Shipment A:
sent 13/good 12/reject 1, Shipment B: sent 10/good 8/reject 2, both
against ONE DO) proving reject=2 reads, decides, and creates a
Replacement Demand as exactly 2 — never 1. Full regression green:
the PDFG/Special Order/Replacement Reject/FG-Allocation cascade
(135+35 tests) and Phase 5.5 (107/107 + full P55-24 stack), 0
failures.
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
