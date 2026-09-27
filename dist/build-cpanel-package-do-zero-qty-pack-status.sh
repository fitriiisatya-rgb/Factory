#!/usr/bin/env bash
# Builds dist/amor-factory-do-zero-qty-pack-status.zip — LIVE UAT HOTFIX:
# hide zero-qty DO items + unambiguous Packing submit status.
#
# Migration 0014 is already APPLIED on the real cPanel host. This package
# adds NO new migration and touches NO schema — it is a code-only hotfix
# on top of the already-deployed FG Security XSS Hotfix package
# (amor-factory-fg-security-xss-hotfix.zip).
#
# WHAT CHANGED
#
# BUG 1 — zero-qty DO items (screen + print):
#   - Root cause: DoTargetService::storeDemandByProduct() (used by
#     createDraft()/refreshFromPo()) had no po_awal/po_revisi > 0 filter,
#     unlike its own sibling methods (storesWithPo()/
#     allStoreDemandForFactory()) — every product in a day's PO template
#     got a real, persisted delivery_order_item row, whether or not this
#     store actually ordered any of it. Fixed at the query layer so a NEW
#     DO never gets a zero-planned row inserted again.
#   - Display-layer safety net: DoService::buildDoDto() now hides any
#     item where plannedQty<=0 AND alreadyShippedQty<=0 from the returned
#     items array (screen AND print both read this one DTO, no separate
#     query) — so an ALREADY-CREATED live DO (like DO/KRM/006/IX/2026)
#     is fixed immediately, no backfill/migration needed. A historical
#     line with real shipped_qty is never hidden by this. Totals/
#     shipment logic are unaffected — shippedQtyByProduct() and the
#     shipment endpoints still read straight from the repository, never
#     from this filtered list.
#
# BUG 2 — unambiguous Packing submit status:
#   - fg-packing.php's Packing-per-Toko store status ("Selesai" once
#     packed_qty reaches a store's own target) was ambiguous about
#     whether it meant "the numbers look complete" or "this was actually
#     submitted". Audited: packed_qty only ever changes via a successful
#     "Submit Packing [Store]" PATCH, so reaching target already IS real,
#     persisted, submitted data — the true gap was an unsaved edit made
#     AFTER reaching that state still displaying the old "complete"
#     label. Fixed with a purely client-side "dirty" flag (no new
#     persisted field): the moment any packing input/button for the
#     currently open store is touched, its status can no longer claim
#     "Sudah Disubmit" until the next successful submit (or a fresh page
#     load) confirms it again. The store's own chip and the per-store
#     action button both switch to an unmistakable, disabled
#     "✓ Sudah Disubmit" state exactly when packed_qty already
#     covers the full target AND nothing is unsaved on top of it.
#   - No per-store "submitted by/at" metadata is shown or invented — only
#     the whole FG document's own batch-level submitted_by/submitted_at
#     exists in the schema (set by the separate final "Submit FG"
#     action), and this hotfix does not add a per-store equivalent.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-do-zero-qty-pack-status"
ZIP_PATH="$DIST_DIR/amor-factory-do-zero-qty-pack-status.zip"

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

echo "--- sanity: confirm NO new migration was added (0014 is already LIVE — this must be a code-only hotfix) ---"
[ -f "$STAGE/api/app/migrations/0014_production_fg_division_rework.php" ] || { echo "REFUSING TO BUILD: migration 0014 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0015_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0015+ was found — this hotfix must add NO new migration." >&2
  exit 1
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, current) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: BUG 1 fix (DO zero-qty hiding) is present at both the query and DTO/render layer ---"
grep -q "AND (si.po_awal > 0 OR si.po_revisi > 0)" "$STAGE/api/app/src/Delivery/DoTargetService.php" || { echo "REFUSING TO BUILD: DoTargetService::storeDemandByProduct()'s zero-demand query filter is missing."; exit 1; }
grep -q "if (\$planned <= 0.0001 && \$shippedQty <= 0.0001)" "$STAGE/api/app/src/Delivery/DoService.php" || { echo "REFUSING TO BUILD: DoService::buildDoDto()'s zero-qty visibility filter is missing."; exit 1; }
grep -q "'productCount' => \$visibleCount" "$STAGE/api/app/src/Delivery/DoService.php" || { echo "REFUSING TO BUILD: DoService::buildDoDto()'s visibleCount-based productCount/fullyFulfilled fix is missing."; exit 1; }

echo "--- sanity: BUG 2 fix (unambiguous Packing submit status) is present ---"
grep -q "currentStoreDirty" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's dirty-tracking guard is missing."; exit 1; }
grep -q "sudah_disubmit" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's sudah_disubmit status code is missing."; exit 1; }
grep -q "function packingActionsHtml" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's packingActionsHtml() action-area renderer is missing."; exit 1; }
grep -q "fg-store-chip-submitted" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: app.css's Sudah Disubmit chip styling is missing."; exit 1; }

echo "--- sanity: confirm the prior FG security escaping fix (escHtml) is still intact, unregressed ---"
grep -q "function escHtml" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's escHtml() helper is missing — the prior security hotfix must not regress."; exit 1; }

echo "--- sanity: confirm the prior mobile-first FG rework's own markup/JS is still intact, unregressed ---"
grep -q "fgStep = " "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Verifikasi/Packing step switch is missing."; exit 1; }
grep -q "function groupByStore" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Packing-per-Toko store grouping is missing."; exit 1; }
grep -q "window.addEventListener('load', initPacking)" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php no longer defers its first Packing fetch to window 'load'."; exit 1; }
grep -qE "fg-packing-row\[data-exploded=\"1\"\]" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's exploded-only submit-payload guard is missing."; exit 1; }
grep -q "existingHasRealData" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::batchProductStores()'s existingHasRealData fix is missing."; exit 1; }
grep -q "HAVING SUM(pi.aktual) > 0.0001" "$STAGE/api/app/src/Fg/FgTargetService.php" || { echo "REFUSING TO BUILD: FgTargetService's target>0 HAVING filter is missing."; exit 1; }

echo "--- sanity: confirm NO business rule / stock / reservation / shipment logic changed except DoTargetService/DoService's own explicit visibility fixes above (byte-for-byte elsewhere) ---"
for f in api/app/src/Delivery/DoRepository.php \
         api/app/src/Fg/FgRepository.php api/app/src/Fg/FgTargetService.php \
         api/app/src/Production/ProductionRepository.php api/app/src/Production/ProductionService.php \
         api/app/src/Production/ProductionRoutingService.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Production/ProductionTaskService.php \
         api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php api/app/src/SpecialOrder/SpecialOrderFgAllocationRepository.php \
         api/app/src/SpecialOrder/SpecialOrderDoService.php api/app/src/Dispatch/ReceiptService.php \
         api/app/src/Import/PoImporter.php \
         api/app/ui/pages/pengiriman.php api/app/ui/pages/delivery-order.php api/app/ui/pages/delivery-order-detail.php \
         api/app/ui/pages/produksi.php api/app/ui/pages/produksi-task-per-divisi.php api/app/ui/pages/produksi-demand.php \
         api/app/ui/pages/laporan.php \
         api/app/ui/layout.php api/app/ui/components.php api/app/ui/labels.php api/app/ui/bootstrap.php \
         api/app/ui/print-template.php \
         api/assets/js/app.js \
         api/app/src/Fg/FgService.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this hotfix (business logic, stock/shipment/reservation code, and delivery-order-detail.php's own screen rendering must be byte-for-byte unchanged; only DoTargetService.php, DoService.php, fg-packing.php and app.css change in this pass)." >&2
    exit 1
  fi
done

echo "--- copying canonical schema DDL (0001-0014, byte-for-byte unchanged — 0014 is already LIVE) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql schema-v1-0013-repair-production-flow-completion.sql \
         schema-v1-0014-production-fg-division-rework.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done
diff -q "$REPO_ROOT/database/schema-v1-0014-production-fg-division-rework.sql" "$STAGE/api/app/database/schema-v1-0014-production-fg-division-rework.sql" > /dev/null || { echo "REFUSING TO BUILD: migration 0014's SQL changed — it must stay byte-for-byte identical to what is already live."; exit 1; }

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — DO Zero-Qty Cleanup + Packing Submit Status

Code-only live UAT hotfix on top of the already-deployed FG Security XSS
Hotfix package. Migration 0014 is already LIVE — this package adds NO
new migration and touches NO schema.

## Bug 1 — zero-qty DO items (screen + print)

Root cause: DoTargetService::storeDemandByProduct() (used by
createDraft()/refreshFromPo()) had no po_awal/po_revisi > 0 filter,
unlike its own sibling methods — every product in a day's PO template
got a real, persisted delivery_order_item row, whether or not a given
store actually ordered any of it that day. Fixed at the query layer so
a NEW DO never gets a zero-planned row inserted again, plus a display-
layer safety net in DoService::buildDoDto() (screen AND print share this
one DTO) that hides any item with plannedQty<=0 AND alreadyShippedQty<=0
— fixing an ALREADY-CREATED live DO immediately, no backfill needed. A
historical line with real shipped_qty is never hidden. Totals and
shipment logic are unaffected.

## Bug 2 — unambiguous Packing submit status

Packing-per-Toko's "Selesai" status was ambiguous between "the numbers
look complete" and "this was actually submitted". Audited: packed_qty
only ever changes via a successful "Submit Packing [Store]" PATCH, so
reaching a store's own target already IS real, submitted data — the
real gap was an unsaved edit made AFTER reaching that state still
showing the old "complete" label. Fixed with a purely client-side
"dirty" flag (no new persisted field, no new backend state machine): a
store now shows an unmistakable, disabled "✓ Sudah Disubmit" badge
and button exactly when packed_qty covers the full target AND nothing
is unsaved on top of it; touching any input reverts it to an active
"Submit Packing" button until the next successful submit (or fresh page
load) confirms it again. No per-store submitted-by/at metadata is shown
or invented — only the whole document's own batch-level submitted_by/
submitted_at exists in the schema.

## What did NOT change

Shipment allocation, stock ledger, store_fg_balance, the Regular
shipment guard, special reservation logic, DO business rules,
Production logic, and the pre-existing "Per Produk must be Breakdown
Toko'd before packing per store" rule are all byte-for-byte unchanged
(enforced by this build script's own diff sanity checks). No new API
endpoint.

## Testing

Full existing regression suite re-run, 100% green. New DO-UI-01..05
(a real DO reproducing DO/KRM/006/IX/2026's own shape — 1 real item + 6
legacy zero-planned rows — verified hidden on both the rendered screen
and the real print-do.php page, with a historical shipped-qty line
proven never hidden) and PACK-STATUS-01..08 (a real headless-Chromium
click through "Submit Packing", confirming the chip and action area
both switch to Sudah Disubmit, persist across a real page reload, never
affect another store, and a double-submit never doubles packed_qty or
posts stock) all pass.
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
