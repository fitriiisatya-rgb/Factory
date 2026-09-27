#!/usr/bin/env bash
# Builds dist/amor-factory-pack-submit-state.zip — FINAL FIX: real
# persisted Packing submission state per (FG batch, store).
#
# UNLIKE every prior hotfix in this series, this package DOES include a
# new migration (0015) — a source deep-check correctly found that the
# prior pass's "packed_qty >= target" inference for "Sudah Disubmit" is
# logically wrong (a store can legitimately, completely submit Packing
# with packed BELOW target, or even packed = 0), and no existing table
# already carried the real "was this store's Packing actually submitted"
# truth. Migration 0015 is purely additive (ONE new table,
# fg_store_packing_submission — no existing table altered, no existing
# row touched) and is layered on top of the already-deployed DO
# Zero-Qty + Packing Submit Status package (amor-factory-do-zero-qty-
# pack-status.zip).
#
# WHAT CHANGED
#
#   - New table fg_store_packing_submission (fg_batch_id, store_id,
#     status ENUM('submitted','stale'), submitted_at, submitted_by,
#     invalidated_at, updated_at) — the ONE authoritative event/state
#     "Packing for Store X has been submitted", never a duplicate of any
#     product quantity (those stay fg_item's own, sole columns).
#   - New endpoint POST /api/fg/{id}/packing-submit (FgService::
#     submitStorePacking()) — the ONLY place a submission record is ever
#     created, atomically alongside that store's own row writes, in ONE
#     transaction, never marked submitted before every row validates.
#     submitted_by is ALWAYS Auth::requireRole()'s own return value (the
#     authenticated session), never taken from the request payload.
#   - patchDraft()'s existing generic storeItems path (Breakdown Toko's
#     own save in FG Verifikasi) now flips an existing 'submitted' record
#     to 'stale' the instant it actually changes that store's own
#     fg_item data (reject/hilang/keterangan/fgVerified) — never on a
#     no-op resave of identical values.
#   - fg-packing.php's Packing UI reads this real, persisted status
#     directly (via batchProductStores()'s new packingSubmittedStatus/
#     At/By fields) — the ENTIRE client-side "packed_qty >= target" /
#     "dirty flag" inference from the prior pass is REMOVED, replaced by
#     trusting the server's own truth. New "Perlu Submit Ulang" state
#     when a submission has been invalidated; "Sudah Disubmit" now
#     correctly persists through low/zero-packed submissions, survives a
#     real reload, and is visible to a second, independent session.
#   - DoTargetService's zero-demand filter (storeDemandByProduct(),
#     storesWithPo(), allStoreDemandForFactory()) is corrected from
#     "po_awal > 0 OR po_revisi > 0" to "(po_awal + po_revisi) > 0" — the
#     OR-of-components version wrongly kept a store whose PO Revisi was
#     negative enough to drive its TRUE final demand to exactly 0 (a
#     real, reachable state: PoMerger::mergeRevision() replaces po_revisi
#     wholesale, and PoFileParser::parseAngka() parses a genuinely
#     negative cell value unclamped).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-pack-submit-state"
ZIP_PATH="$DIST_DIR/amor-factory-pack-submit-state.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points (index.php + .htaccess, unchanged) ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- copying every existing tool, unchanged, for a clean overwrite (nothing dropped) ---"
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

echo "--- sanity: confirm migration 0015 is present, 0001-0014 stay untouched, and nothing beyond 0015 exists yet ---"
[ -f "$STAGE/api/app/migrations/0014_production_fg_division_rework.php" ] || { echo "REFUSING TO BUILD: migration 0014 is missing"; exit 1; }
[ -f "$STAGE/api/app/migrations/0015_fg_store_packing_submission.php" ] || { echo "REFUSING TO BUILD: migration 0015 (fg_store_packing_submission) is missing — this package's own real schema change."; exit 1; }
if find "$STAGE/api/app/migrations" -name '0016_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0016+ was found — this pass introduces ONLY 0015." >&2
  exit 1
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, current) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: real persisted Packing submission state (migration 0015 + its full call chain) is present ---"
grep -q "CREATE TABLE IF NOT EXISTS fg_store_packing_submission" "$STAGE/api/app/database/schema-v1-0015-fg-store-packing-submission.sql" 2>/dev/null \
  || grep -q "CREATE TABLE IF NOT EXISTS fg_store_packing_submission" "$REPO_ROOT/database/schema-v1-0015-fg-store-packing-submission.sql" \
  || { echo "REFUSING TO BUILD: fg_store_packing_submission's own CREATE TABLE is missing from migration 0015's SQL."; exit 1; }
grep -q "function submitStorePacking" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::submitStorePacking() is missing."; exit 1; }
grep -q "function upsertPackingSubmission" "$STAGE/api/app/src/Fg/FgRepository.php" || { echo "REFUSING TO BUILD: FgRepository::upsertPackingSubmission() is missing."; exit 1; }
grep -q "function invalidatePackingSubmission" "$STAGE/api/app/src/Fg/FgRepository.php" || { echo "REFUSING TO BUILD: FgRepository::invalidatePackingSubmission() is missing."; exit 1; }
grep -q "function packingSubmitStore" "$STAGE/api/app/src/Controllers/FgController.php" || { echo "REFUSING TO BUILD: FgController::packingSubmitStore() is missing."; exit 1; }
grep -q "/api/fg/{id}/packing-submit" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: the POST /api/fg/{id}/packing-submit route is missing from App.php."; exit 1; }
grep -q "packingSubmittedStatus" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::batchProductStores()'s packingSubmittedStatus field is missing."; exit 1; }
grep -q "sudah_disubmit" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's sudah_disubmit status code is missing."; exit 1; }
grep -q "perlu_submit_ulang" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's perlu_submit_ulang (stale) status code is missing."; exit 1; }
grep -q "/packing-submit'" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Submit Packing button no longer calls the dedicated packing-submit endpoint."; exit 1; }
if grep -q "currentStoreDirty" "$STAGE/api/app/ui/pages/fg-packing.php"; then
  echo "REFUSING TO BUILD: fg-packing.php still contains the RETIRED client-side dirty-tracking inference — Sudah Disubmit must come from the server's own persisted state only." >&2
  exit 1
fi
grep -q "fg-store-chip-stale" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: app.css's Perlu Submit Ulang chip styling is missing."; exit 1; }

echo "--- sanity: the corrected DO zero-final-demand filter, (po_awal + po_revisi) > 0, is present everywhere it must be ---"
COUNT_CORRECT_FILTER=$(grep -c "(si.po_awal + si.po_revisi) > 0" "$STAGE/api/app/src/Delivery/DoTargetService.php" || true)
if [ "$COUNT_CORRECT_FILTER" -lt 3 ]; then
  echo "REFUSING TO BUILD: expected the corrected (po_awal + po_revisi) > 0 filter in all 3 of DoTargetService's demand queries (storeDemandByProduct/storesWithPo/allStoreDemandForFactory), found $COUNT_CORRECT_FILTER." >&2
  exit 1
fi
if grep -qE "si\.po_awal > 0 OR si\.po_revisi > 0" "$STAGE/api/app/src/Delivery/DoTargetService.php"; then
  echo "REFUSING TO BUILD: the OLD, incorrect OR-of-components zero-demand filter is still present somewhere in DoTargetService.php." >&2
  exit 1
fi
grep -q "if (\$planned <= 0.0001 && \$shippedQty <= 0.0001)" "$STAGE/api/app/src/Delivery/DoService.php" || { echo "REFUSING TO BUILD: DoService::buildDoDto()'s zero-qty visibility filter is missing."; exit 1; }

echo "--- sanity: confirm the prior passes' own fixes are still intact, unregressed ---"
grep -q "function escHtml" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's escHtml() helper is missing — the security hotfix must not regress."; exit 1; }
grep -q "fgStep = " "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Verifikasi/Packing step switch is missing."; exit 1; }
grep -q "function groupByStore" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Packing-per-Toko store grouping is missing."; exit 1; }
grep -q "window.addEventListener('load', initPacking)" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php no longer defers its first Packing fetch to window 'load'."; exit 1; }
grep -qE "fg-packing-row\[data-exploded=\"1\"\]" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's exploded-only submit-payload guard is missing."; exit 1; }
grep -q "existingHasRealData" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::batchProductStores()'s existingHasRealData fix is missing."; exit 1; }
grep -q "HAVING SUM(pi.aktual) > 0.0001" "$STAGE/api/app/src/Fg/FgTargetService.php" || { echo "REFUSING TO BUILD: FgTargetService's target>0 HAVING filter is missing."; exit 1; }

echo "--- sanity: confirm NO business rule / stock / reservation / shipment logic changed except this pass's own explicit files above (byte-for-byte elsewhere) ---"
for f in api/app/src/Delivery/DoRepository.php \
         api/app/src/Fg/FgTargetService.php \
         api/app/src/Production/ProductionRepository.php api/app/src/Production/ProductionService.php \
         api/app/src/Production/ProductionRoutingService.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Production/ProductionTaskService.php \
         api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php api/app/src/SpecialOrder/SpecialOrderFgAllocationRepository.php \
         api/app/src/SpecialOrder/SpecialOrderDoService.php api/app/src/Dispatch/ReceiptService.php \
         api/app/src/Import/PoImporter.php \
         api/app/src/Controllers/DoController.php \
         api/app/ui/pages/pengiriman.php api/app/ui/pages/delivery-order.php api/app/ui/pages/delivery-order-detail.php \
         api/app/ui/pages/produksi.php api/app/ui/pages/produksi-task-per-divisi.php api/app/ui/pages/produksi-demand.php \
         api/app/ui/pages/laporan.php \
         api/app/ui/layout.php api/app/ui/components.php api/app/ui/labels.php api/app/ui/bootstrap.php \
         api/app/ui/print-template.php \
         api/assets/js/app.js; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this pass (business logic, stock/shipment/reservation code must be byte-for-byte unchanged; only DoTargetService.php, FgService.php, FgRepository.php, FgController.php, App.php, fg-packing.php and app.css change here)." >&2
    exit 1
  fi
done

echo "--- copying canonical schema DDL (0001-0015; 0001-0014 byte-for-byte unchanged and already LIVE, 0015 is this pass's own new table) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql schema-v1-0013-repair-production-flow-completion.sql \
         schema-v1-0014-production-fg-division-rework.sql schema-v1-0015-fg-store-packing-submission.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done
diff -q "$REPO_ROOT/database/schema-v1-0014-production-fg-division-rework.sql" "$STAGE/api/app/database/schema-v1-0014-production-fg-division-rework.sql" > /dev/null || { echo "REFUSING TO BUILD: migration 0014's SQL changed — it must stay byte-for-byte identical to what is already live."; exit 1; }

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Real Persisted Packing Submission State

Adds migration 0015 (ONE new, purely additive table:
fg_store_packing_submission) on top of the already-deployed DO Zero-Qty
+ Packing Submit Status package. See README-FIRST-CPANEL-0015-PACK-
SUBMIT-STATE.md for the cPanel deployment steps — THIS PASS REQUIRES
RUNNING A MIGRATION, unlike every prior hotfix in this series.

## Why

The prior pass's "Sudah Disubmit" inference (packed_qty >= target) was
logically wrong: a store's Packing can be legitimately, completely
submitted with packed BELOW target (Tidak Sesuai + a valid discrepancy
note) or even packed = 0. Quantities alone can never distinguish "never
submitted" from "submitted with a low/zero number".

## What changed

- New table fg_store_packing_submission (fg_batch_id, store_id, status
  submitted/stale, submitted_at, submitted_by, invalidated_at,
  updated_at) — the ONE authoritative "has this store's Packing been
  submitted" event, never a duplicate of any product quantity.
- New endpoint POST /api/fg/{id}/packing-submit (FgService::
  submitStorePacking()) — writes a store's rows AND records its
  submission atomically, in one transaction, never marked submitted
  before every row validates. submitted_by always comes from the
  authenticated session, never the request payload.
- Editing an already-submitted store's data via ANY path (most commonly
  Breakdown Toko's own save in FG Verifikasi) flips its record to
  'stale' — the UI then shows "Perlu Submit Ulang" until resubmitted.
- fg-packing.php's Packing UI now trusts this real, persisted state
  directly; the prior pass's entire client-side "packed_qty >= target" /
  "dirty flag" inference is removed.
- DoTargetService's zero-demand filter is corrected from
  "po_awal > 0 OR po_revisi > 0" to "(po_awal + po_revisi) > 0" — the
  OR-of-components version could wrongly keep a store whose PO Revisi
  was negative enough to drive its TRUE final demand to exactly 0 (a
  real, reachable state given how PO revisions and PoFileParser work).

## What did NOT change

Shipment allocation, stock ledger, store_fg_balance, the Regular
shipment guard, special reservation logic, DO business rules, Production
logic, and the pre-existing "Per Produk must be Breakdown Toko'd before
packing per store" rule are all byte-for-byte unchanged (enforced by
this build script's own diff sanity checks). The separate, whole-
document "Submit FG (Semua Toko)" action and its stock-posting logic are
completely untouched — per-store Packing submission never posts stock.

## Testing

Full existing regression suite re-run, 100% green (including a fix to
Phase0Test.php's own hardcoded total-table-count assertion: 63 -> 64,
to account for this pass's one new table). New PACK-SUBMIT-01..10 (a
real headless-Chromium click through Submit Packing with packed = target,
packed < target, and packed = 0 — all correctly showing Sudah Disubmit;
edited-then-resubmitted showing Perlu Submit Ulang then Sudah Disubmit
again; a second independent session seeing the same state; a failed
validation creating no submission row; a retried submit never doubling
packed_qty or posting stock) and DO-UI-06 (a po_awal=8/po_revisi=-8 line,
final demand exactly 0, correctly never surfaced or even persisted) all
pass.
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
