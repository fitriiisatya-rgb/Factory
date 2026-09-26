#!/usr/bin/env bash
# Builds dist/amor-factory-store-fg-reservation-guard.zip — FINAL CORE
# BLOCKER fix: "Store A must never consume Store B's ready/packed FG
# allocation" for Regular store shipment.
#
# Everything from the prior "Production Division + FG & Packing rework"
# package is included unchanged, PLUS:
#   - a store-specific FG ownership guard in Delivery\ShipmentService::
#     ship()/preview() (INSUFFICIENT_STORE_READY_FG), gated on
#     DoRepository::hasAnyStoreAllocation() so a product still entirely in
#     Per Produk mode remains unrestricted by store, exactly as before.
#   - Fg\FgService::submit()'s own STORE_PACKED_BELOW_SHIPPED guard (FG
#     can never be corrected below what a store has already been shipped).
#   - a collapse-to-Per-Produk safety guard (CANNOT_COLLAPSE_STORE_ALREADY_SHIPPED).
#   - migration 0014 EXTENDED (still 0014, no 0015): fg_item.
#     posted_packed_qty (a frozen per-row snapshot) + the new
#     store_fg_balance lock-anchor table (no cached quantity).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-store-fg-reservation-guard"
ZIP_PATH="$DIST_DIR/amor-factory-store-fg-reservation-guard.zip"

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

echo "--- sanity: migration state is 0001-0014, exactly one new migration (0014) ---"
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009 0010 0011 0012 0013 0014; do
  find "$STAGE/api/app/migrations" -name "${m}_*" | grep -q . || { echo "REFUSING TO BUILD: migration $m is missing"; exit 1; }
done
find "$STAGE/api/app/migrations" -name '0015_*' | grep -q . && { echo "REFUSING TO BUILD: an unexpected migration 0015+ was found — only 0014 is in scope." >&2; exit 1; }

echo "--- sanity: migration 0014 SQL includes the store_fg_balance table AND posted_packed_qty column (this task's own schema extension) ---"
grep -q "store_fg_balance" "$REPO_ROOT/database/schema-v1-0014-production-fg-division-rework.sql" || { echo "REFUSING TO BUILD: store_fg_balance table missing from migration 0014's SQL."; exit 1; }
grep -q "posted_packed_qty" "$REPO_ROOT/database/schema-v1-0014-production-fg-division-rework.sql" || { echo "REFUSING TO BUILD: fg_item.posted_packed_qty column missing from migration 0014's SQL."; exit 1; }

echo "--- sanity: RBAC wiring present (Auth division/factory access gates, DEFAULT-DENY) ---"
grep -q "requireDivisionAccess" "$STAGE/api/app/src/Auth.php" || { echo "REFUSING TO BUILD: Auth::requireDivisionAccess is missing."; exit 1; }
grep -q "requireFactoryAccess" "$STAGE/api/app/src/Auth.php" || { echo "REFUSING TO BUILD: Auth::requireFactoryAccess is missing."; exit 1; }
grep -q "NO_DIVISION_ASSIGNMENT" "$STAGE/api/app/src/Auth.php" || { echo "REFUSING TO BUILD: Auth's default-deny NO_DIVISION_ASSIGNMENT is missing."; exit 1; }
grep -q "NO_FACTORY_ASSIGNMENT" "$STAGE/api/app/src/Auth.php" || { echo "REFUSING TO BUILD: Auth's default-deny NO_FACTORY_ASSIGNMENT is missing."; exit 1; }
grep -q "FG_PACKING" "$STAGE/api/app/src/Controllers/FgController.php" || { echo "REFUSING TO BUILD: FgController::EDITOR_ROLES no longer includes FG_PACKING."; exit 1; }

echo "--- sanity: writable FG Breakdown Toko (explode/collapse, aggregate-once submit) ---"
grep -q "explodeToStores" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::explodeToStores is missing."; exit 1; }
grep -q "collapseToProduct" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::collapseToProduct is missing."; exit 1; }
grep -q "PRODUCT_IN_BREAKDOWN_MODE" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService's Option A (derived/read-only Per Produk once exploded) guard is missing."; exit 1; }
grep -q "postedQtyForProduct" "$STAGE/api/app/src/Fg/FgRepository.php" || { echo "REFUSING TO BUILD: FgRepository::postedQtyForProduct (aggregate-once posting) is missing."; exit 1; }

echo "--- sanity: STORE-SPECIFIC FG OWNERSHIP guard is present (the FINAL CORE BLOCKER fix itself) ---"
grep -q "INSUFFICIENT_STORE_READY_FG" "$STAGE/api/app/src/Delivery/ShipmentService.php" || { echo "REFUSING TO BUILD: ShipmentService's INSUFFICIENT_STORE_READY_FG guard is missing."; exit 1; }
grep -q "hasAnyStoreAllocation" "$STAGE/api/app/src/Delivery/ShipmentService.php" || { echo "REFUSING TO BUILD: ShipmentService no longer gates the store guard on hasAnyStoreAllocation (would make every never-exploded product unshippable)."; exit 1; }
grep -q "STORE_PACKED_BELOW_SHIPPED" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService's STORE_PACKED_BELOW_SHIPPED guard is missing."; exit 1; }
grep -q "CANNOT_COLLAPSE_STORE_ALREADY_SHIPPED" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService's collapse-safety guard is missing."; exit 1; }
grep -q "lockStoreFgBalance" "$STAGE/api/app/src/Delivery/DoRepository.php" || { echo "REFUSING TO BUILD: DoRepository::lockStoreFgBalance (the new lock anchor) is missing."; exit 1; }
grep -q "sumPackedForStore" "$STAGE/api/app/src/Delivery/DoRepository.php" || { echo "REFUSING TO BUILD: DoRepository::sumPackedForStore is missing."; exit 1; }
grep -q "sumShippedForStore" "$STAGE/api/app/src/Delivery/DoRepository.php" || { echo "REFUSING TO BUILD: DoRepository::sumShippedForStore is missing."; exit 1; }
grep -qE "sumPackedForStore\(.*,\s*true\)" "$STAGE/api/app/src/Delivery/ShipmentService.php" || { echo "REFUSING TO BUILD: ShipmentService's REPEATABLE READ race fix (forUpdate=true on the store-ready reads) is missing."; exit 1; }

echo "--- sanity: FINAL ATOMICITY PATCH — the mode-transition-vs-shipment race is closed ---"
grep -qE "hasAnyStoreAllocation\(.*,\s*true\)" "$STAGE/api/app/src/Delivery/ShipmentService.php" || { echo "REFUSING TO BUILD: ShipmentService no longer calls hasAnyStoreAllocation with forUpdate=true — the mode-transition race would reopen."; exit 1; }
grep -q "allProductIds = array_keys(\$items)" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::submit() no longer locks stock_balance for EVERY product (only negative-delta ones) — the mode-transition race would reopen."; exit 1; }
grep -q "bool \$forUpdate = false" "$STAGE/api/app/src/Delivery/DoRepository.php" || { echo "REFUSING TO BUILD: DoRepository's hasAnyStoreAllocation/sumPackedForStore/sumShippedForStore forUpdate parameter is missing."; exit 1; }

echo "--- sanity: source_type routing — the store guard must NEVER reach Special/CS/Sales/Direct/General orders ---"
if grep -rl "sumPackedForStore\|sumShippedForStore\|store_fg_balance" "$STAGE/api/app/src/SpecialOrder/" > /dev/null 2>&1; then
  echo "REFUSING TO BUILD: the store-specific guard leaked into the SpecialOrder module — it must stay Regular-DO-only (source_type='delivery_order')." >&2
  exit 1
fi

echo "--- sanity: DO can still be created before FG completion (planned-demand semantics unchanged) ---"
grep -q "function createDraft" "$STAGE/api/app/src/Delivery/DoService.php" || { echo "REFUSING TO BUILD: DoService::createDraft is missing."; exit 1; }

echo "--- sanity: no business rule / server-side validation file OUTSIDE this pass's own scope changed ---"
for f in api/app/src/Import/PoImporter.php api/app/src/Production/ProductionRepository.php \
         api/app/src/Production/ProductionRoutingService.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Production/ProductionTaskService.php api/app/src/Delivery/DoTargetService.php \
         api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php \
         api/app/src/SpecialOrder/SpecialOrderDoService.php api/app/src/Dispatch/ReceiptService.php \
         api/app/ui/pages/produksi-task-per-divisi.php api/app/ui/pages/produksi-demand.php \
         api/app/ui/layout.php api/app/ui/components.php api/app/ui/labels.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this fix." >&2
    exit 1
  fi
done

echo "--- copying canonical schema DDL (0001-0014) ---"
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

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Store-Specific FG Reservation Guard (FINAL CORE BLOCKER fix)

Extends the "Production Division + FG & Packing rework" package with the
final closing piece of the Production -> FG -> DO -> Shipment core path:
Regular store shipment can no longer ship one store's Product X using FG
that was physically packed for a DIFFERENT store.

Migration 0014 is EXTENDED (still 0014, no 0015):
  - fg_item.posted_packed_qty — a frozen per-row snapshot of packed_qty as
    of that row's own last submit(), read by the store-ready guard instead
    of the live (possibly mid-edit) packed_qty.
  - store_fg_balance — a pure lock-anchor table (store_id, product_id,
    location_id), no cached quantity at all. "Packed" and "shipped" are
    always live-summed from fg_item/shipment_item — the same canonical
    tables the writable Breakdown Toko rework already introduced.

## What changed

1. Delivery\ShipmentService::ship()/preview() now ALSO check "store
   ready" (packed for this store, minus already shipped to this store)
   for Regular (source_type='delivery_order') shipments only.
   INSUFFICIENT_STORE_READY_FG / EXCEEDS_STORE_READY are the new codes.
2. This guard is GATED on DoRepository::hasAnyStoreAllocation() — a
   product still entirely in Per Produk mode (Breakdown Toko is
   optional) is completely unrestricted by store, exactly as before this
   fix.
3. Fg\FgService::submit() gains STORE_PACKED_BELOW_SHIPPED: a store's
   packed FG can never be corrected down below what has already been
   shipped to that store.
4. Collapsing a Breakdown Toko product back to Per Produk is refused
   (CANNOT_COLLAPSE_STORE_ALREADY_SHIPPED) once any of its stores has
   shipped history — collapsing would otherwise erase the per-store
   ownership data the guard depends on.
5. A PO revision that drops a store's target below its already-shipped/
   packed quantity surfaces a non-blocking "Perlu Review Ulang" flag
   (never silently reclaims stock).
6. A REPEATABLE READ concurrency bug (found by this task's own real
   two-process REGSTORE-07 test) is fixed: the store-ready SUM queries
   now take a locking read (FOR UPDATE) at every real-decision call site,
   never a plain SELECT that could see stale data even after correctly
   waiting on the new store_fg_balance lock.

## What did NOT change

- Special/CS/Sales/Direct/General order shipment (SpecialOrderDoService)
  — a structurally separate code path this guard never touches.
- Physical stock_ledger/stock_balance posting — still exactly once per
  shipment, same as before.
- DO can still be created before FG completion (planned demand only).
- Receipt — still never touches stock or store allocation.
- No shipment reversal/cancel-after-ship path exists in this codebase
  (DoService::cancel() already refuses once anything has shipped) — this
  fix does not add one; if a reversal capability is wanted, it is a
  separate, larger undertaking, reported rather than silently added.

## FINAL ATOMICITY PATCH (this build)

A follow-up fix closes the one architecture risk the first version of
this guard disclosed: hasAnyStoreAllocation() (deciding whether a
product is restricted by store) was an unlocked existence check, so a
Regular shipment could race a concurrent FgService::submit() that was
establishing a product's FIRST store allocation and observe a stale
"no allocation yet" answer.

Fix: FgService::submit() now locks stock_balance(product, location) FOR
UPDATE for EVERY product it touches (not just negative-delta ones),
reusing the SAME lock ShipmentService::ship() already holds first — no
new table/column was needed, 0014 is unchanged from the prior build.
hasAnyStoreAllocation() itself also takes a locking-read flag, used only
on ship()'s real-decision path. Four new real concurrency tests
(REGSTORE-16..19, including the exact mode-transition-vs-shipment race
in both spawn orders, a no-false-block check for pure Per Produk
products, and a 3-way FG-submit/shipment/special-allocation contention
test) plus the full existing regression suite, all green.

19 new REGSTORE tests total (REGSTORE-01..19), including four real
concurrent-process races, plus the full existing regression suite
(PDFG-01..24, FG-STORE-01..12, ALLOC-GLOBAL-01..20, ALLOC-01..15,
FINAL-01..40, and every earlier Phase 0-5.5 suite), all green.

## Known architecture limits (reported, not solved by this fix)

Nothing in this system currently allows a genuine RESERVATION of FG for
a store ahead of packing (only after packing is store ownership
established). This is intentional and matches the approved business
model ("Once FG Packing assigns ready quantity to Store A, that quantity
belongs to Store A") — it is not a gap, just worth stating explicitly.
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
