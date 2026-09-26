#!/usr/bin/env bash
# Builds dist/amor-factory-production-live-po-target.zip — BUGFIX: Regular
# PO target must be visible on Produksi -> Ceklis Produksi BEFORE any
# Production Draft (production_run) exists.
#
# Real UAT root cause: produksi.php's own top KPI cards and division
# summary table derived Target/Actual ONLY from a raw SQL join across
# production_run + production_item — a division with no draft yet
# contributed NOTHING, so Target Produksi showed 0 and every division row
# showed "-" even when a real PO had already been imported with a real
# target. The correct live-target formula (po_awal + po_revisi, PB
# excluded) already existed and was already used correctly elsewhere
# (ProductionService::loadTarget()/buildRunDto(), ProductionTaskService) —
# this fix makes produksi.php's own overview use that SAME authoritative
# ProductionTargetService instead of its own separate, draft-dependent
# query. No new target formula, no schema change.
#
# NO DATABASE MIGRATION — migration state stays at 0013.
#
# This package necessarily also contains the already-in-progress "Order
# Entry + Import PO UI/UX Rework" (compact order-item table, dark-shell
# Import PO page) — see build-cpanel-package-order-entry-import-po-ui-
# rework.sh's own PACKAGE-INFO.md for that pass's own detail — since both
# changesets currently live in the same working tree and this script
# always ships the CURRENT full source, not a partial diff.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-production-live-po-target"
ZIP_PATH="$DIST_DIR/amor-factory-production-live-po-target.zip"

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

echo "--- sanity: NO DATABASE MIGRATION — confirm migration state stays at 0013 ---"
find "$STAGE/api/app/migrations" -name '0014_*' | grep -q . && { echo "REFUSING TO BUILD: an unexpected migration 0014+ was found — this is repository/service/UI logic only, no schema change." >&2; exit 1; }
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009 0010 0011 0012 0013; do
  find "$STAGE/api/app/migrations" -name "${m}_*" | grep -q . || { echo "REFUSING TO BUILD: migration $m is missing — every prior migration must still be present"; exit 1; }
done

echo "--- sanity: confirm produksi.php now derives Target from the authoritative live-PO ProductionTargetService, never production_item alone ---"
grep -q "ProductionTargetService" "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php no longer uses ProductionTargetService — the actual fix for this bug."; exit 1; }
grep -q "targetsByProduct" "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php no longer calls targetsByProduct() for its live target."; exit 1; }
if grep -qE "INNER JOIN production_run r ON r\.production_run_id = pi\.production_run_id\s*$" "$STAGE/api/app/ui/pages/produksi.php"; then
  echo "REFUSING TO BUILD: produksi.php still contains the old production_item-only KPI query — the regression this fix must remove." >&2
  exit 1
fi
grep -q "divisionSummaryRows" "$STAGE/api/app/ui/pages/produksi.php" || { echo "REFUSING TO BUILD: produksi.php's division-summary rows are no longer built from the live-target-driven array."; exit 1; }

echo "--- sanity: confirm the division summary never shows a bare dash for Target/Actual/Sisa (task's own UX rule) ---"
if grep -qE '<td class="num">-</td><td class="num">-</td><td class="num">-</td>' "$STAGE/api/app/ui/pages/produksi.php"; then
  echo "REFUSING TO BUILD: produksi.php still has a hardcoded '-'/'-'/'-' row for a division without a draft." >&2
  exit 1
fi

echo "--- sanity: confirm ProductionService::classifyDisplayStatus is reusable (public) rather than duplicated logic ---"
grep -q "public static function classifyDisplayStatus" "$STAGE/api/app/src/Production/ProductionService.php" || { echo "REFUSING TO BUILD: classifyDisplayStatus is no longer public — produksi.php can no longer reuse it without duplicating the classification rules."; exit 1; }

echo "--- sanity: confirm ProductionTargetService's own PO Awal+Revisi (PB-excluded) formula is UNCHANGED (never duplicated/redefined) ---"
grep -q "'target' => \$poAwal + \$poRevisi" "$STAGE/api/app/src/Production/ProductionTargetService.php" || { echo "REFUSING TO BUILD: ProductionTargetService's target formula changed — this fix must only REUSE it, never redefine it."; exit 1; }

echo "--- sanity: confirm NO business rule / server-side validation file OUTSIDE this pass's own scope changed ---"
for f in api/app/src/Import/PoImporter.php api/app/src/Production/ProductionRepository.php \
         api/app/src/Production/ProductionRoutingService.php api/app/src/Production/ProductionTaskService.php \
         api/app/src/Controllers/ProductionController.php api/app/src/Delivery/DoTargetService.php \
         api/app/src/Fg/FgTargetService.php api/app/ui/pages/produksi-task-per-divisi.php \
         api/app/ui/pages/produksi-demand.php api/app/ui/layout.php api/app/ui/components.php api/app/ui/labels.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this bugfix." >&2
    exit 1
  fi
done

echo "--- copying canonical schema DDL (0001-0013, unchanged) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql schema-v1-0013-repair-production-flow-completion.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Production Live PO Target Visibility (Bugfix)

NO DATABASE MIGRATION in this package — schema stays at 0013.

## Root cause

Real UAT (2026-09-26, Karangtengah): a Regular PO import succeeded
(Batch #3, target total = 39 — BOLLEN LILIT COKLAT 25 + CHOCO CUBE 12
14) but Produksi -> Ceklis Produksi -> 26 Sep 2026 -> Karangtengah showed
Target Produksi = 0 and every division row showed Target/Actual/Sisa = "-"
and status "Belum Dimulai" — including Roti & Bollen, which genuinely had
25 units of live PO demand.

produksi.php's own top KPI cards and division summary table derived
Target/Actual ONLY from a raw SQL join across production_run +
production_item — a division with no Production Draft yet contributed
NOTHING to either. Production Draft is the execution/realisasi document;
PO is the demand source. The correct live-target formula (po_awal +
po_revisi, PB excluded) already existed in
Production\ProductionTargetService and was already used correctly
elsewhere in this codebase (ProductionService::loadTarget()/
buildRunDto(), ProductionTaskService) — produksi.php's own overview was
the one place still bypassing it with its own separate, draft-dependent
query.

## Fix

produksi.php now calls the SAME ProductionTargetService::targetsByProduct()
every other authoritative target read in this codebase already uses,
grouped by division, for BOTH the top KPI cards and the division summary
table — this is always shown, whether or not a production_run exists for
that division/date. Actual/Sisa/Status still come from production_run/
production_item exactly as before (execution state only — never a second
target source; PO target and production_item target are never summed
together). ProductionService::classifyDisplayStatus() was made public so
produksi.php reuses the exact same not_produced/below_target/on_target/
overproduction classification the run-detail view already uses, instead
of duplicating it.

Division rows and the top KPI cards now always show a real number for
Target/Actual/Sisa — never a bare "-" — even when no draft exists yet.
Creating a draft (Buat/Buka Draft) initializes it from this exact same
live target (already true before this fix, via ProductionService::
createDraft()), so there is never a mismatch between what the overview
showed and what the new draft shows. A PO revision updates the overview's
live target immediately, with no page-reopen or draft-refresh action
needed — the same "never freeze target just because the page was opened"
principle production_item's own live-vs-snapshot model already enforced
for an existing draft.

10 new tests (LIVE-01..10) in Phase3ProductionTest.php cover: no PO ->
target 0, PO with no draft -> target visible, draft creation matches the
overview target exactly, PO revision reflected live, PB still ignored,
submitted production's actual/remaining still correct, Cibadak/Bolu same
behavior as Karangtengah, division filter shows only that division's
target, and no double counting across divisions when creating one
division's draft.
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
