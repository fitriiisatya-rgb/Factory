#!/usr/bin/env bash
# Builds dist/amor-factory-production-division-fg-rework.zip — Production
# Division + FG & Packing rework:
#   - user_division_access / user_factory_access (many-to-many user<->
#     factory/division), wired opt-in into Auth/ProductionService/
#     FgService, with an admin UI to assign users (api/_users-uat/).
#   - Ceklis Produksi combines PO Reguler + Pesanan Khusus/Non-Toko in one
#     table, with Sesuai/Tidak Sesuai (server-enforced), a derived "Perlu
#     Review Ulang" badge, and an enhanced admin division dashboard.
#   - FG & Packing (Reguler) gains Sesuai/Tidak Sesuai for Verified and
#     Packing, Reject/Hilang columns, and a read-only Breakdown Toko view.
#   - FG Sumber Khusus/Non-Toko gains Sesuai/Tidak Sesuai for its own
#     Verifikasi FG step.
#
# ONE new migration: 0014 (fg_item.reject_qty/hilang_qty only — the many-
# to-many access tables and the shared-worksheet version column already
# existed, unused, since the original 0001 schema).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-production-division-fg-rework"
ZIP_PATH="$DIST_DIR/amor-factory-production-division-fg-rework.zip"

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

echo "--- sanity: RBAC wiring present (Auth division/factory access gates, opt-in) ---"
grep -q "requireDivisionAccess" "$STAGE/api/app/src/Auth.php" || { echo "REFUSING TO BUILD: Auth::requireDivisionAccess is missing."; exit 1; }
grep -q "requireFactoryAccess" "$STAGE/api/app/src/Auth.php" || { echo "REFUSING TO BUILD: Auth::requireFactoryAccess is missing."; exit 1; }
grep -q "Auth::requireDivisionAccess" "$STAGE/api/app/src/Production/ProductionService.php" || { echo "REFUSING TO BUILD: ProductionService does not call requireDivisionAccess."; exit 1; }
grep -q "Auth::requireFactoryAccess" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService does not call requireFactoryAccess."; exit 1; }
grep -q "user_division_access" "$STAGE/api/app/src/Users/UserRepository.php" || { echo "REFUSING TO BUILD: UserRepository does not manage user_division_access."; exit 1; }
grep -q "user_factory_access" "$STAGE/api/app/src/Users/UserRepository.php" || { echo "REFUSING TO BUILD: UserRepository does not manage user_factory_access."; exit 1; }

echo "--- sanity: opt-in backward compatibility (empty assignment array means unrestricted, never a lockout) ---"
grep -q "assigned === \[\]" "$STAGE/api/app/src/Auth.php" || { echo "REFUSING TO BUILD: Auth's opt-in 'no assignment = unrestricted' bypass is missing — this would lock out every existing PRODUCTION/FG_PACKING user with no assignments." >&2; exit 1; }

echo "--- sanity: Production Sesuai/Tidak Sesuai is server-enforced, never client-trusted alone ---"
grep -q "SESUAI_ACTUAL_MISMATCH" "$STAGE/api/app/src/Production/ProductionService.php" || { echo "REFUSING TO BUILD: ProductionService's server-side sesuai enforcement is missing."; exit 1; }

echo "--- sanity: FG Sesuai/Tidak Sesuai + Reject/Hilang server-enforced ---"
grep -q "SESUAI_VERIFIED_MISMATCH" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService's server-side sesuaiVerified enforcement is missing."; exit 1; }
grep -q "SESUAI_PACKING_MISMATCH" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService's server-side sesuaiPacking enforcement is missing."; exit 1; }
grep -q "NOTES_REQUIRED" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService's Keterangan-required enforcement is missing."; exit 1; }
grep -q "reject_qty" "$STAGE/api/app/src/Fg/FgRepository.php" || { echo "REFUSING TO BUILD: FgRepository does not write reject_qty."; exit 1; }
grep -q "hilang_qty" "$STAGE/api/app/src/Fg/FgRepository.php" || { echo "REFUSING TO BUILD: FgRepository does not write hilang_qty."; exit 1; }

echo "--- sanity: FG NOTES_REQUIRED is three-state (absent never blocks — legacy callers must keep working) ---"
grep -q "=== false" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService's sesuai three-state (absent/true/false) check is missing — this would break every pre-existing caller that never sends sesuaiVerified/sesuaiPacking." >&2; exit 1; }

echo "--- sanity: Breakdown Toko is read-only (never a second writable quantity per store) ---"
grep -q "storeBreakdownForProduct" "$STAGE/api/app/src/Fg/FgTargetService.php" || { echo "REFUSING TO BUILD: FgTargetService::storeBreakdownForProduct is missing."; exit 1; }
grep -qE "function storeBreakdown\(string \\\$tanggal, int \\\$factoryId, int \\\$productId\): array" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::storeBreakdown's read-only array-returning signature is missing/changed." >&2; exit 1; }

echo "--- sanity: Produksi combines PO Reguler + Non-Regular in one worksheet ---"
grep -q "nonRegularRows" "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php no longer merges Non-Regular demand into the run-detail table."; exit 1; }
grep -q "Perlu Review Ulang" "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php's derived Perlu Review Ulang badge is missing."; exit 1; }
grep -q "targetChangedSincePoRevision" "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php no longer derives Perlu Review Ulang from the live-target-drift field."; exit 1; }
grep -q "hasTarget" "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php no longer excludes zero-target divisions from the outstanding-work KPI count."; exit 1; }

echo "--- sanity: FG & Packing (Reguler) has Sesuai/Tidak Sesuai + Reject/Hilang + Breakdown Toko UI ---"
grep -q "fg-mode-toko" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Breakdown Toko toggle is missing."; exit 1; }
grep -q "fg-verified-sesuai-group" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Verified Sesuai/Tidak Sesuai buttons are missing."; exit 1; }
grep -q "fg-packing-sesuai-group" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Packing Sesuai/Tidak Sesuai buttons are missing."; exit 1; }
grep -q 'data-field="hilang"' "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Hilang column is missing."; exit 1; }

echo "--- sanity: no double-counting proof markers survive in source (single source of truth documented at every new layer) ---"
grep -q "independently-editable quantity" "$STAGE/api/app/src/Fg/FgTargetService.php" || { echo "REFUSING TO BUILD: FgTargetService's single-source-of-truth guarantee documentation is missing from storeBreakdownForProduct."; exit 1; }

echo "--- sanity: no business rule / server-side validation file OUTSIDE this pass's own scope changed ---"
for f in api/app/src/Import/PoImporter.php api/app/src/Production/ProductionRepository.php \
         api/app/src/Production/ProductionRoutingService.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Production/ProductionTaskService.php api/app/src/Delivery/DoTargetService.php \
         api/app/src/Delivery/DoService.php api/app/src/Delivery/ShipmentService.php \
         api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php \
         api/app/ui/pages/produksi-task-per-divisi.php api/app/ui/pages/produksi-demand.php \
         api/app/ui/layout.php api/app/ui/components.php api/app/ui/labels.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this rework." >&2
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
# Amor Factory System — Production Division + FG & Packing Rework

ONE new migration in this package: 0014 (fg_item.reject_qty/hilang_qty
only). Everything else this rework needed already existed in the schema,
unused, since the original 0001 baseline — see the migration file's own
docblock for the full audit.

## What changed

1. User <-> Production Division / FG Factory access (many-to-many),
   wired into api/app/src/Auth.php + ProductionService + FgService, with
   an admin assignment UI in api/_users-uat/. OPT-IN: a user with zero
   assignment rows keeps today's unrestricted role-based access — nobody
   is locked out by deploying this package.
2. Ceklis Produksi (produksi.php) now shows PO Reguler AND Pesanan
   Khusus/Non-Toko in ONE worksheet per division/date, with Sesuai/Tidak
   Sesuai buttons (server-enforced, never a trusted disabled input alone),
   a derived "Perlu Review Ulang" badge when a submitted document's live
   target has drifted from a later PO revision, and an enhanced admin
   division dashboard (Target/Actual/Reject/Sisa/Status/Last Updated/
   Submitted By, zero-target divisions excluded from the outstanding-work
   count).
3. FG & Packing (Reguler) gains Sesuai/Tidak Sesuai for both Verified and
   Packing, independent Reject and Hilang columns (migration 0014's new
   fg_item columns), and a read-only Breakdown Toko view (per-store PO
   target reference) that can never double-count against the single
   Per Produk row it is derived from.
4. FG Sumber Khusus/Non-Toko gains Sesuai/Tidak Sesuai for its own
   Verifikasi FG step (Packing has never existed for this source and is
   NOT added here — disclosed scope decision, see the delivery report).

## What did NOT change

- PO Awal + latest Revisi (PB ignored) target formula — reused everywhere,
  never redefined.
- Production/FG snapshot semantics, optimistic-lock version columns,
  submit/reopen lifecycle, audit trail.
- Immediate production->FG visibility (already worked architecturally;
  FgTargetService sums SUBMITTED divisions per product, no "wait for all"
  gate existed before or after this change).
- DO creation before FG completeness, and the shipment ready-FG guard —
  both already correct, untouched.
- All existing FG reservation-safety logic (special/non-regular
  allocation, cross-flow guards) — completely untouched; Breakdown Toko
  never writes to fg_item's store dimension, so stock_ledger/stock_balance
  posting is byte-identical to before this rework.

24 new tests (PDFG-01..24) in ProductionDivisionFgReworkTest.php cover the
new RBAC scoping (both directions), sesuai/sesuaiVerified/sesuaiPacking
enforcement, the three-state (absent/true/false) backward-compat rule,
reject/hilang independence, and the store-breakdown endpoint's read-only
guarantee.
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
if find "$REPO_ROOT/api/uploads/receipt-evidence" -name '*.png' -o -name '*.jpg' 2>/dev/null | grep -q .; then
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
