#!/usr/bin/env bash
# Builds dist/amor-factory-replacement-reject.zip — REPLACEMENT REJECT
# END-TO-END: the approved-reject disposition (Reject Final / Kirim
# Ulang), a protected FG-first allocation, a separate Replacement
# Production Need, a separate no-price Replacement DO/shipment, and reuse
# of the existing store Receipt flow for the replacement's own delivery.
#
# THIS PASS INCLUDES A NEW MIGRATION (0016) — the ONE genuine schema gap
# the task's own "MANDATORY DATA MODEL AUDIT FIRST" found: no existing
# table distinguished an Admin-approved reject qty/disposition from the
# store's own reported reject_qty, and no table tracked a Replacement
# Demand/its FG allocation/its own separate DO at all. Migration 0016 is
# purely additive (ADD COLUMN IF NOT EXISTS / widen-only ENUMs / CREATE
# TABLE IF NOT EXISTS) on top of the already-deployed Lock Packing After
# Submit package (amor-factory-pack-edit-lock.zip / migration 0015, still
# the latest schema before this pass).
#
# WHAT CHANGED
#
#   - shipment_receipt_item gains approved_reject_qty/disposition/
#     disposition_reason/disposition_by/disposition_at (Admin's own
#     per-line "Reject Final" vs "Kirim Ulang" decision, distinct from
#     the store's own reported reject_qty) + replacement_do_shipment_
#     item_id (a Replacement shipment's own receipt line reuses this
#     SAME table, exactly like a Special/Non-Regular shipment already
#     does).
#   - New tables: replacement_demand (the ONE authoritative "Kirim Ulang"
#     demand per approved reject line — never created for Reject Final),
#     replacement_demand_fg_allocation (a structural sibling of
#     special_order_fg_allocation — an EARMARK, never a stock movement),
#     replacement_do + replacement_do_shipment_item (a SEPARATE, no-price
#     DO + its own real dispatch lines).
#   - shipment.source_type/stock_ledger.source_type widened (never
#     narrowed) to add 'replacement_do'/'replacement_demand_fg_
#     allocation'.
#   - New Amor\Api\Replacement\* classes (Repository/Service split,
#     mirroring SpecialOrder's own architecture): ReplacementRepository
#     (disposition + demand), ReplacementFgAllocationRepository/Service
#     (the "Check FG First" earmark, protected on BOTH sides — Special/
#     Regular can no longer steal a Replacement's own reservation either,
#     since SpecialOrderFgAllocationRepository::
#     sumActiveAllocatedForProductFactory() is widened to sum both
#     tables), ReplacementDoRepository/Service (the separate DO +
#     dispatch, mirroring SpecialOrderDoService's own CRITICAL DISPATCH
#     RULE: DO creation never reduces FG, only a real ship() does).
#   - New ReplacementController + routes (disposition, demand list/show,
#     production-actual, verify-fg, DO create, DO ship) — Admin/PPIC for
#     disposition, the SAME EDITOR_ROLES tiers as Production/FG/DO reuse
#     for everything downstream.
#   - Production\ProductionTaskService's own already-reserved
#     SOURCE_REPLACEMENT_REJECT branch (previously permanently empty) now
#     surfaces real Replacement Production Need rows, netting out
#     whatever the demand's own FG allocation already covers — never
#     merged into PO Reguler's own target.
#   - Dispatch\ReceiptService/ReceiptRepository/ShipmentLineResolver
#     widened (never rewritten) to also resolve/receipt a
#     'replacement_do' shipment through the EXACT SAME store Receipt
#     flow a Special/Non-Regular shipment already uses — confirming
#     receipt for a Replacement shipment updates that demand's own status
#     (received_partial/completed) and NEVER auto-creates another
#     Replacement Demand; a reject on a Replacement's own receipt simply
#     awaits another explicit Admin disposition, chained via parent_
#     replacement_demand_id back to the SAME root original reject.
#   - New Admin UI: replacement-reject.php (Tindak Lanjut Reject +
#     Replacement traceability list) and replacement-do-detail.php (the
#     separate, no-price DO's own ship action).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-replacement-reject"
ZIP_PATH="$DIST_DIR/amor-factory-replacement-reject.zip"

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

echo "--- sanity: migration 0016 is present, 0001-0015 byte-for-byte unchanged from what is already live, and NOTHING beyond 0016 exists ---"
for f in 0001_schema_v1.php 0002_master_identity.php 0003_po_phase2.php 0004_production_phase3.php \
         0005_fg_packing_phase4.php 0006_do_shipment_phase5.php 0007_dispatch_receipt_phase55.php \
         0008_receipt_evidence.php 0009_shipment_email.php 0010_special_nonregular_orders.php \
         0011_production_task_per_division.php 0012_production_flow_completion.php \
         0013_repair_production_flow_completion.php 0014_production_fg_division_rework.php \
         0015_fg_store_packing_submission.php; do
  diff -q "$REPO_ROOT/api/app/migrations/$f" "$STAGE/api/app/migrations/$f" > /dev/null || { echo "REFUSING TO BUILD: migration $f differs from the repo — every prior migration must stay byte-for-byte identical to what is already live."; exit 1; }
done
[ -f "$STAGE/api/app/migrations/0016_replacement_reject.php" ] || { echo "REFUSING TO BUILD: migration 0016 (replacement_reject) is missing — this pass's own real schema change."; exit 1; }
if find "$STAGE/api/app/migrations" -name '0017_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0017+ was found — this pass introduces ONLY 0016." >&2
  exit 1
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, current) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: real persisted Replacement Reject architecture (migration 0016 + its full call chain) is present ---"
grep -q "CREATE TABLE IF NOT EXISTS replacement_demand " "$STAGE/api/app/database/schema-v1-0016-replacement-reject.sql" 2>/dev/null \
  || grep -q "CREATE TABLE IF NOT EXISTS replacement_demand " "$REPO_ROOT/database/schema-v1-0016-replacement-reject.sql" \
  || { echo "REFUSING TO BUILD: replacement_demand's own CREATE TABLE is missing from migration 0016's SQL."; exit 1; }
[ -d "$STAGE/api/app/src/Replacement" ] || { echo "REFUSING TO BUILD: the Amor\\Api\\Replacement namespace is missing."; exit 1; }
for class in ReplacementRepository ReplacementFgAllocationRepository ReplacementFgAllocationService ReplacementDoRepository ReplacementDoService ReplacementService; do
  [ -f "$STAGE/api/app/src/Replacement/$class.php" ] || { echo "REFUSING TO BUILD: Replacement\\$class.php is missing."; exit 1; }
done
[ -f "$STAGE/api/app/src/Controllers/ReplacementController.php" ] || { echo "REFUSING TO BUILD: Controllers\\ReplacementController.php is missing."; exit 1; }
grep -q "/api/replacement/receipt-items/{id}/disposition" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: the disposition route is missing from App.php."; exit 1; }
grep -q "/api/replacement-demands/{id}/do" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: the Replacement DO create route is missing from App.php."; exit 1; }
grep -q "/api/replacement-do/{id}/ship" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: the Replacement DO ship route is missing from App.php."; exit 1; }
grep -q "function allocateAtCreation" "$STAGE/api/app/src/Replacement/ReplacementFgAllocationService.php" || { echo "REFUSING TO BUILD: ReplacementFgAllocationService::allocateAtCreation() is missing."; exit 1; }
grep -q "function sumStoreCommittedRemainingAcrossAllStores" "$STAGE/api/app/src/Delivery/DoRepository.php" || { echo "REFUSING TO BUILD: DoRepository::sumStoreCommittedRemainingAcrossAllStores() (the Regular-store-FG protection) is missing."; exit 1; }
grep -q "ReplacementFgAllocationRepository" "$STAGE/api/app/src/SpecialOrder/SpecialOrderFgAllocationRepository.php" || { echo "REFUSING TO BUILD: the widened, mutually-protective sumActiveAllocatedForProductFactory() is missing."; exit 1; }
grep -q "SOURCE_REPLACEMENT_REJECT" "$STAGE/api/app/src/Production/ProductionTaskService.php" || { echo "REFUSING TO BUILD: ProductionTaskService's Replacement Reject branch is missing."; exit 1; }
grep -q "replacement_do" "$STAGE/api/app/src/Dispatch/ShipmentLineResolver.php" || { echo "REFUSING TO BUILD: ShipmentLineResolver's replacement_do branch is missing."; exit 1; }
grep -q "insertReceiptItemForReplacementLine" "$STAGE/api/app/src/Dispatch/ReceiptRepository.php" || { echo "REFUSING TO BUILD: ReceiptRepository::insertReceiptItemForReplacementLine() is missing."; exit 1; }
grep -q "recordReceiptOutcome" "$STAGE/api/app/src/Replacement/ReplacementService.php" || { echo "REFUSING TO BUILD: ReplacementService::recordReceiptOutcome() (the no-recursive-chain receipt hook) is missing."; exit 1; }
[ -f "$STAGE/api/app/ui/pages/replacement-reject.php" ] || { echo "REFUSING TO BUILD: the Admin Replacement Reject UI page is missing."; exit 1; }
[ -f "$STAGE/api/app/ui/pages/replacement-do-detail.php" ] || { echo "REFUSING TO BUILD: the Admin Replacement DO detail UI page is missing."; exit 1; }
grep -q "replacement-reject" "$STAGE/api/app/ui/layout.php" || { echo "REFUSING TO BUILD: the Replacement Reject nav item is missing from layout.php."; exit 1; }

echo "--- sanity: confirm the prior passes' own fixes are still intact, unregressed ---"
grep -q "var packingEditMode" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's packingEditMode UI state (the prior Lock Packing pass) is missing."; exit 1; }
grep -q "function escHtml" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's escHtml() helper is missing — the security hotfix must not regress."; exit 1; }
grep -q "function submitStorePacking" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::submitStorePacking() is missing."; exit 1; }
COUNT_CORRECT_FILTER=$(grep -c "(si.po_awal + si.po_revisi) > 0" "$STAGE/api/app/src/Delivery/DoTargetService.php" || true)
if [ "$COUNT_CORRECT_FILTER" -lt 3 ]; then
  echo "REFUSING TO BUILD: expected the corrected (po_awal + po_revisi) > 0 filter in all 3 of DoTargetService's demand queries, found $COUNT_CORRECT_FILTER." >&2
  exit 1
fi

echo "--- sanity: confirm NO business logic outside this pass's own explicit files changed (byte-for-byte elsewhere) ---"
for f in api/app/src/Fg/FgService.php api/app/src/Fg/FgRepository.php api/app/src/Fg/FgTargetService.php \
         api/app/src/Controllers/FgController.php \
         api/app/src/Delivery/DoTargetService.php api/app/src/Delivery/ShipmentService.php api/app/src/Delivery/DoService.php \
         api/app/src/Production/ProductionRepository.php api/app/src/Production/ProductionService.php \
         api/app/src/Production/ProductionRoutingService.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Production/ProductionTaskController.php \
         api/app/src/Controllers/ProductionController.php api/app/src/Controllers/DoController.php \
         api/app/src/SpecialOrder/SpecialOrderService.php api/app/src/SpecialOrder/SpecialOrderRepository.php \
         api/app/src/SpecialOrder/SpecialOrderDoService.php api/app/src/SpecialOrder/SpecialOrderDoRepository.php \
         api/app/src/SpecialOrder/NormalizedSourceType.php \
         api/app/src/Controllers/SpecialOrderController.php api/app/src/Controllers/SpecialOrderDoController.php \
         api/app/src/Controllers/ReceiptController.php api/app/src/Dispatch/EvidenceUploader.php \
         api/app/ui/pages/fg-packing.php api/app/ui/pages/produksi.php api/app/ui/pages/produksi-task-per-divisi.php \
         api/app/ui/pages/pengiriman.php api/app/ui/pages/delivery-order.php api/app/ui/pages/delivery-order-detail.php \
         api/app/ui/pages/konfirmasi-toko.php api/app/ui/pages/konfirmasi-toko-detail.php \
         api/app/ui/components.php api/app/ui/labels.php api/app/ui/bootstrap.php api/app/ui/print-template.php \
         api/assets/js/app.js api/assets/css/app.css; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this pass (only the files this pass's own docs list above may change)." >&2
    exit 1
  fi
done

echo "--- copying canonical schema DDL (0001-0016; 0001-0015 byte-for-byte unchanged and already LIVE, 0016 is this pass's own new schema) ---"
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
for f in schema-v1-0014-production-fg-division-rework.sql schema-v1-0015-fg-store-packing-submission.sql; do
  diff -q "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f" > /dev/null || { echo "REFUSING TO BUILD: $f changed — it must stay byte-for-byte identical to what is already live."; exit 1; }
done

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Replacement Reject End-to-End

Adds migration 0016 (additive: 4 new tables — replacement_demand,
replacement_demand_fg_allocation, replacement_do, replacement_do_
shipment_item — plus ADD COLUMN/widen-only ALTERs on shipment_receipt_
item, shipment, and stock_ledger) on top of the already-deployed Lock
Packing After Submit package. See README-FIRST-CPANEL-REPLACEMENT-
REJECT.md for the cPanel deployment steps — THIS PASS REQUIRES RUNNING A
MIGRATION.

## Why

When a store reports a shipment Reject, Admin previously had no way to
record an approved (possibly adjusted) reject quantity, no explicit final
disposition, and no tracked "make-good" demand if goods needed to be sent
again. Reject Final and Kirim Ulang / Ganti Produk are now explicit,
permanent, one-time Admin decisions.

## What changed

- Admin verifies a Reject, then must choose ONE disposition:
  - Reject Final / Tidak Diganti: factory absorbs the loss. No FG is
    restored, no PO/DO is touched, no Replacement Demand is ever created.
  - Kirim Ulang / Ganti Produk: creates a Replacement Demand for the
    Admin-approved qty (which may be less than what the store reported).
- "Check FG First": the new demand's own protected FG allocation
  (replacement_demand_fg_allocation) reserves whatever is TRULY free
  right now — physical stock minus every other active reservation
  (Special Order's own allocation, AND every Regular store's own
  still-unshipped Breakdown Toko commitment) — capped at the approved
  qty. Any shortfall becomes a real Production Need, entering Production
  under its own distinct Replacement Reject source, never merged into PO
  Reguler's own target.
- Once fully covered (FG allocation + verified production), a SEPARATE,
  no-price Replacement DO can be created and shipped (partial shipment
  supported), consuming ONLY that demand's own reservation/production
  headroom — never another store's or another order's FG.
- The Replacement's own delivery reuses the EXISTING store Receipt flow
  end to end (public token, Good/Reject/Shortage confirmation, Admin
  verify). A full Good receipt completes the demand; a Reject on the
  Replacement's OWN receipt never auto-creates another Replacement — it
  simply awaits another explicit Admin disposition, correctly chained
  back to the very first original reject for full traceability.
- The existing "true free FG" formula (already shared by Regular PO and
  Special Order) is widened so all three sources — Regular, Special, and
  Replacement — mutually protect each other's reservations; no existing
  call site needed any further change for this.

## What did NOT change

Original PO targets, original DO planned quantities, original shipment/
receipt history, and Final FG Submit's own stock-posting/finalization
logic are all byte-for-byte/behaviorally unchanged (enforced by this
build script's own diff sanity checks and by REPL-22/23/24's own tests).
Editing/resubmitting Replacement Packing/DO/shipment never posts stock
outside of the ONE real dispatch deduction per shipment, exactly like
every other source already in this system.

## Testing

Full existing regression suite re-run, 100% green. New REPL-01..25 (a
real end-to-end lifecycle: Reject Final creates nothing and restores
nothing; Check FG First covers a demand fully from free FG, partially,
or not at all, matching the task's own worked examples exactly;
Replacement allocation cannot steal an active Special Order allocation
nor another Regular store's own committed FG; two demands competing for
the same limited free FG pool never combine to oversubscribe it;
Production Need appears correctly under its own Replacement Reject
source; a separate no-price DO supports partial shipment consuming only
its own headroom, with physical stock decremented exactly once per real
dispatch; a full-Good receipt completes the demand; a Reject on the
replacement's own receipt never auto-chains; a second explicit Admin
disposition correctly chains to the original root; original PO/DO/
invoice data stays completely untouched throughout) all pass, alongside
an unauthorized-role check confirming server-side authorization is
authoritative regardless of what the Admin UI shows.
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
