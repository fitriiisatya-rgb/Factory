#!/usr/bin/env bash
# Builds dist/amor-factory-mobile-fg-verifikasi-packing.zip — MOBILE-FIRST
# FG VERIFIKASI + PACKING PER TOKO.
#
# Migration 0014 is already APPLIED on the real cPanel host. This package
# adds NO new migration and touches NO schema, and calls NO new backend
# endpoint — it is a pure UI/UX rework of api/app/ui/pages/fg-packing.php
# (plus one small, genuine backend bug fix it uncovered along the way — see
# below) on top of the already-deployed FG Live UI Hotfix package
# (amor-factory-fg-live-ui-hotfix.zip).
#
# WHAT CHANGED
#
# FG is split into two separate steps (?step=verifikasi, the default, and
# ?step=packing), both still served by the SAME fg-packing.php page/route:
#
#   - FG Verifikasi: the wide table is replaced by mobile-first stacked
#     .fg-card divs, for BOTH Per Produk (fgVerified/reject/hilang/notes/
#     Sesuai only — packed moved entirely to the new Packing step) and
#     Breakdown Toko (same fields, per store, also as cards). Zero-target
#     products/stores stay hidden exactly as the prior hotfix left them.
#
#   - FG Packing: NEW, defaults to per-Toko. A store chip selector (product
#     count / target pcs / status per store) plus a store detail view
#     (Target Toko, Ready Verified, Sesuai/Tidak Sesuai, Actual Packing,
#     Reject, Hilang, Keterangan per product), submitted ONE STORE AT A
#     TIME via the EXISTING storeItems PATCH endpoint. Packing Sesuai
#     auto-fills Actual Packing to Ready Verified (this store's own
#     fgVerified), never the raw PO target — the existing, unchanged
#     PACKED_EXCEEDS_VERIFIED server rule is what actually enforces this;
#     the auto-fill only mirrors it.
#
# A product still being verified in default Per Produk mode still has a
# real Regular PO store-level target (Phase 2 PO import is always store-
# split, independently of whether THIS product has ever been exploded for
# FG purposes) — its row is shown in the Packing view too, but read-only,
# with an explanatory note, and is NEVER included in that store's
# "Submit Packing" payload or its own progress totals (there is nothing
# it could legitimately pack yet, and including it would try to
# explodeToStores() a row that still has a real nonzero Per Produk
# aggregate, which the backend correctly refuses to guess how to split).
#
# GENUINE BACKEND BUG FOUND AND FIXED (FgService::batchProductStores())
#
# The prior hotfix's "only stores with target > 0" filter had an
# exception for a store that "already has real data" (existing !== null),
# meant to protect a PO-revision-orphaned store's real numbers from being
# hidden. But explodeToStores() creates a real fg_item row for EVERY
# store storeBreakdownForProduct() returns, INCLUDING target=0 ones — so
# the moment ANY product was exploded, its own target=0 stores' brand-new
# all-zero rows satisfied "existing !== null" and reappeared, reopening
# exactly the bug the prior hotfix closed. Fixed: the exception now
# requires the existing row to carry real (nonzero) qty/packed/reject/
# hilang data.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-mobile-fg-verifikasi-packing"
ZIP_PATH="$DIST_DIR/amor-factory-mobile-fg-verifikasi-packing.zip"

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

echo "--- sanity: mobile-first FG Verifikasi + Packing per Toko markup/JS present ---"
grep -q "fgStep = " "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Verifikasi/Packing step switch is missing."; exit 1; }
grep -q "class=\"fg-card-list" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's mobile-first card list markup is missing."; exit 1; }
grep -q "function groupByStore" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Packing-per-Toko store grouping is missing."; exit 1; }
grep -q "function storeStatus" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's store progress-status logic is missing."; exit 1; }
grep -q "window.addEventListener('load', initPacking)" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php no longer defers its first Packing fetch to window 'load' — Amor would be undefined at that point (app.js loads AFTER this page's own inline script)."; exit 1; }
grep -q "Submit Packing " "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's per-store 'Submit Packing' action is missing."; exit 1; }
grep -qE "fg-packing-row\[data-exploded=\"1\"\]" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's exploded-only submit-payload guard is missing — a never-exploded product would poison its store's whole PATCH."; exit 1; }
grep -q "fg-card-list" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: app.css's mobile-first FG card styles are missing."; exit 1; }
grep -q "fg-store-chip" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: app.css's store-chip-selector styles are missing."; exit 1; }

echo "--- sanity: the explodeToStores() zero-row fix (batchProductStores' real-data exception) is present ---"
grep -q "existingHasRealData" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::batchProductStores()'s existingHasRealData fix is missing — an exploded product's own target=0 stores would reappear again."; exit 1; }

echo "--- sanity: confirm the PRIOR FG live UI hotfix's own fixes are still intact, unregressed ---"
grep -q "HAVING SUM(pi.aktual) > 0.0001" "$STAGE/api/app/src/Fg/FgTargetService.php" || { echo "REFUSING TO BUILD: FgTargetService's target>0 HAVING filter is missing."; exit 1; }
grep -q "function isVisibleItem" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::isVisibleItem() is missing."; exit 1; }

echo "--- sanity: confirm the store-specific FG reservation guard + atomicity patch (earlier packages) are still intact, unregressed ---"
grep -q "INSUFFICIENT_STORE_READY_FG" "$STAGE/api/app/src/Delivery/ShipmentService.php" || { echo "REFUSING TO BUILD: ShipmentService's INSUFFICIENT_STORE_READY_FG guard is missing."; exit 1; }
grep -qE "hasAnyStoreAllocation\(.*,\s*true\)" "$STAGE/api/app/src/Delivery/ShipmentService.php" || { echo "REFUSING TO BUILD: ShipmentService's atomic (forUpdate=true) hasAnyStoreAllocation call is missing."; exit 1; }
grep -q "STORE_PACKED_BELOW_SHIPPED" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService's STORE_PACKED_BELOW_SHIPPED guard is missing."; exit 1; }
grep -q "PACKED_EXCEEDS_VERIFIED" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService's PACKED_EXCEEDS_VERIFIED rule (what Packing's own Sesuai auto-fill mirrors) is missing."; exit 1; }

echo "--- sanity: source_type routing — this rework's changes stay Regular-FG-view-only, never touching Special/CS/Sales/Direct/General order code ---"
if grep -rl "groupByStore\|storeStatus\|initPacking\|fg-store-chip" "$STAGE/api/app/src/SpecialOrder/" > /dev/null 2>&1; then
  echo "REFUSING TO BUILD: this rework's changes leaked into the SpecialOrder module." >&2
  exit 1
fi

echo "--- sanity: confirm NO business rule / shipment / stock / reservation / DO / Production logic file changed byte-for-byte ---"
for f in api/app/src/Delivery/DoRepository.php api/app/src/Delivery/DoService.php api/app/src/Delivery/DoTargetService.php \
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
         api/app/ui/layout.php api/app/ui/components.php api/app/ui/labels.php \
         api/assets/js/app.js; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this rework (shipment allocation, stock ledger, store_fg_balance locking, regular shipment guard, special reservation, DO, and Production logic must be byte-for-byte unchanged)." >&2
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
# Amor Factory System — Mobile-First FG Verifikasi + Packing per Toko

Code-only rework on top of the already-deployed FG Live UI Hotfix
package. Migration 0014 is already LIVE — this package adds NO new
migration, touches NO schema, and calls NO new backend endpoint.

## What changed

FG is split into two steps on the same page (?step=verifikasi default,
?step=packing):

- **FG Verifikasi** — mobile-first stacked cards (no wide table, no
  horizontal scroll) for both Per Produk (fgVerified/reject/hilang/
  notes/Sesuai — Packing removed entirely, moved to its own step) and
  Breakdown Toko (same fields, per store, also as cards).
- **FG Packing** — NEW, defaults to per-Toko: a store chip selector
  (product count / target pcs / status) plus a store detail view
  submitted ONE STORE AT A TIME. Packing Sesuai auto-fills Actual
  Packing to Ready Verified (this store's own fgVerified), never the
  raw PO target — mirroring the existing, unchanged PACKED_EXCEEDS_
  VERIFIED server rule, never a new formula.

A product still verified in default Per Produk mode (never exploded via
Breakdown Toko) still shows up in Packing — it has a real Regular PO
store-level target from Phase 2 PO import, independent of FG's own
explode step — but read-only, with an explanatory note, excluded from
its store's progress totals and from any "Submit Packing" payload (it
cannot be packed yet, and trying would attempt to split an existing
nonzero Per Produk number across stores, which the backend correctly
refuses to guess at).

## Genuine backend bug found and fixed

FgService::batchProductStores()'s "only stores with target > 0" filter
had an exception for a store that "already has real data" — but
explodeToStores() creates a real (all-zero) fg_item row for EVERY store,
including target=0 ones, so exploding ANY product reopened the exact bug
the prior hotfix closed (a target=0 store reappearing). Fixed: the
exception now requires the existing row to carry a real nonzero qty/
packed/reject/hilang value.

## What did NOT change

Shipment allocation logic, stock ledger logic, store_fg_balance locking,
the Regular shipment guard, special reservation logic, DO business
rules, and Production logic are all byte-for-byte unchanged (enforced by
this build script's own diff sanity checks). No new API endpoint.

## Testing

Full existing regression suite (PDFG/FG-STORE/REGSTORE/ALLOC-GLOBAL/
FINAL/ALLOC and every earlier Phase 0-5.5 suite) re-run, 100% green. New
RECON-01..08 (product-vs-store PO target reconciliation) and MOBILE-FG-
01..18 (a real headless-Chromium mobile-viewport (390x844) smoke test,
plus a desktop-viewport re-check) all pass: no horizontal scroll on
either step, Sesuai/Tidak Sesuai behavior on both Verifikasi and
Packing, store-selector scoping, the packing-cannot-exceed-ready-
verified rule (both the client auto-fill and the server-authoritative
rejection), submit-one-store-never-another, correct progress totals, and
no double counting between the two steps.
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
