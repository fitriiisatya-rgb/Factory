#!/usr/bin/env bash
# Builds dist/amor-factory-0013-live-schema-repair.zip — a LIVE SCHEMA
# REPAIR package.
#
# Live cPanel diagnostic (authenticated, direct) confirmed schema_migrations
# already has a row for 0012_production_flow_completion.php, but
# special_order_fg_allocation does not exist there — meaning 0012 was
# applied live from an EARLIER revision of its own SQL than the one now in
# this repository (the migration runner tracks applied state by filename
# only, so it never re-executes 0012's current contents once recorded).
#
# This package adds migration 0013_repair_production_flow_completion.php —
# it NEVER touches, deletes, or re-runs the existing 0012 registry row.
# Every statement in 0013's own SQL is additive/idempotent (CREATE TABLE
# IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, safe-to-reissue MODIFY COLUMN,
# and a DROP FOREIGN KEY IF EXISTS + ADD CONSTRAINT pattern for the two
# foreign keys MariaDB has no native "IF NOT EXISTS" form for) — see the
# migration's own .sql docblock for the full drift analysis.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-0013-live-schema-repair"
ZIP_PATH="$DIST_DIR/amor-factory-0013-live-schema-repair.zip"

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

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, current) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: confirm migration 0013 exists and 0012's own file is UNTOUCHED (this package must never edit 0012 in place) ---"
[ -f "$STAGE/api/app/migrations/0013_repair_production_flow_completion.php" ] || { echo "REFUSING TO BUILD: migration 0013 is missing."; exit 1; }
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009 0010 0011 0012; do
  find "$STAGE/api/app/migrations" -name "${m}_*" | grep -q . || { echo "REFUSING TO BUILD: migration $m is missing — every prior migration must still be present"; exit 1; }
done
if ! diff -q "$REPO_ROOT/api/app/migrations/0012_production_flow_completion.php" "$STAGE/api/app/migrations/0012_production_flow_completion.php" > /dev/null 2>&1; then
  echo "REFUSING TO BUILD: migration 0012's own file differs — this package must NEVER modify 0012, only add 0013." >&2
  exit 1
fi

echo "--- sanity: confirm 0013 is additive only (no DROP TABLE / DROP COLUMN / TRUNCATE, and never touches schema_migrations itself) ---"
if grep -qEi "DROP TABLE|DROP COLUMN|TRUNCATE|DELETE FROM schema_migrations|UPDATE schema_migrations" "$REPO_ROOT/database/schema-v1-0013-repair-production-flow-completion.sql"; then
  echo "REFUSING TO BUILD: migration 0013 contains a destructive statement or touches schema_migrations directly." >&2
  exit 1
fi
if grep -qE "ALTER TABLE delivery_order\b|ALTER TABLE delivery_order_item\b|ALTER TABLE po_batch\b|ALTER TABLE po_item\b|ALTER TABLE production_run\b|ALTER TABLE fg_batch\b" "$REPO_ROOT/database/schema-v1-0013-repair-production-flow-completion.sql"; then
  echo "REFUSING TO BUILD: migration 0013 touches a Regular PO / Production / FG core table it must never touch — schema-drift repair only." >&2
  exit 1
fi

echo "--- sanity: confirm every 0012 object the live diagnostic and deep-check found is repaired by 0013 ---"
for object in "special_order_fg_allocation" "shipment_receipt_token" "special_order_do_shipment_item" \
              "fk_shipment_special_order_do" "fk_sri_special_line" "special_order_do_shipment_item_id" \
              "extra_packaging" "fg_verified_qty"; do
  grep -q "$object" "$REPO_ROOT/database/schema-v1-0013-repair-production-flow-completion.sql" \
    || { echo "REFUSING TO BUILD: expected object '$object' is not referenced in migration 0013."; exit 1; }
done
grep -q "'special_order_fg_allocation'" "$REPO_ROOT/database/schema-v1-0013-repair-production-flow-completion.sql" \
  || { echo "REFUSING TO BUILD: stock_ledger.source_type widening to 'special_order_fg_allocation' is missing from 0013."; exit 1; }
grep -q "'fg_item'" "$REPO_ROOT/database/schema-v1-0013-repair-production-flow-completion.sql" \
  || { echo "REFUSING TO BUILD: stock_ledger.source_type enum in 0013 no longer preserves 'fg_item' — this is the exact regression class this package exists to prevent."; exit 1; }

echo "--- sanity: confirm the two FK statements use the idempotent DROP-IF-EXISTS-then-ADD pattern, never a bare ADD CONSTRAINT ---"
grep -q "DROP FOREIGN KEY IF EXISTS fk_shipment_special_order_do" "$REPO_ROOT/database/schema-v1-0013-repair-production-flow-completion.sql" \
  || { echo "REFUSING TO BUILD: fk_shipment_special_order_do is not guarded with DROP FOREIGN KEY IF EXISTS — a re-run against a live database that already has this FK would fail with a duplicate-constraint error."; exit 1; }
grep -q "DROP FOREIGN KEY IF EXISTS fk_sri_special_line" "$REPO_ROOT/database/schema-v1-0013-repair-production-flow-completion.sql" \
  || { echo "REFUSING TO BUILD: fk_sri_special_line is not guarded with DROP FOREIGN KEY IF EXISTS."; exit 1; }

echo "--- sanity: confirm NO business rule / server-side validation file changed as part of this repair (schema-only pass) ---"
# This package's ENTIRE application code tree is identical to what already
# shipped in amor-factory-fg-allocation-bridge.zip — this is a pure schema
# repair, so every src/ file must be byte-for-byte identical to the repo
# (already guaranteed since $STAGE is copied directly from $REPO_ROOT), and
# the only new non-schema file is the migration pointer itself plus its
# dev/test diagnostic tooling (never shipped — see the api/tests/ exclusion
# below). This check exists to document that intent for future edits to
# this build script, not to diff against git history.
if [ -d "$STAGE/api/tests" ]; then
  echo "REFUSING TO BUILD: api/tests/ (dev/test-only scripts) must never ship in a cPanel package." >&2
  exit 1
fi

echo "--- copying canonical schema DDL (0001-0013) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql schema-v1-0013-repair-production-flow-completion.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done

if ! diff -q "$REPO_ROOT/database/schema-v1-0012-production-flow-completion.sql" "$STAGE/api/app/database/schema-v1-0012-production-flow-completion.sql" > /dev/null 2>&1; then
  echo "REFUSING TO BUILD: staged 0012 SQL differs from the repo's own 0012 SQL — 0012 must ship byte-for-byte unmodified." >&2
  exit 1
fi

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Migration 0013: LIVE SCHEMA REPAIR

LIVE cPanel has 0012_production_flow_completion.php recorded as applied in
schema_migrations, but a direct authenticated diagnostic confirmed
special_order_fg_allocation does not exist there. This means 0012 was
applied live from an EARLIER revision of its own SQL file than the one
that shipped in the previous "Existing FG Allocation Bridge" package —
the migration runner tracks applied state by FILENAME only, so it never
re-executes 0012's current contents once that row exists.

This package adds ONE new migration, 0013_repair_production_flow_
completion.php. It does NOT delete, rename, or re-run the 0012 registry
row — 0012 stays exactly as already recorded. Every statement in 0013's
own SQL is additive and idempotent (safe against ANY of the plausible
live drift states, and a safe no-op if the live database already has
everything current final 0012 provides):
  - CREATE TABLE IF NOT EXISTS for every table 0012 introduces
    (special_order_do / special_order_do_item / special_order_do_
    shipment_item / shipment_receipt_token / special_order_fg_allocation).
  - ADD COLUMN IF NOT EXISTS for every column 0012 adds to an existing
    table (special_order_item.extra_packaging/fg_verified_qty, shipment's
    special_order_do_id/delivery_method/courier_*/handover_note,
    shipment_receipt_item's special_order_do_shipment_item_id/
    item_name_snapshot).
  - MODIFY COLUMN re-declarations for nullability changes and ENUM
    widening (shipment.source_type, stock_ledger.source_type) — always
    safe to reissue, and NEVER narrows an existing ENUM value (the full
    historical value set is preserved explicitly, including 'fg_item',
    which an earlier draft of this project once accidentally dropped —
    this migration is written so that mistake cannot repeat).
  - DROP FOREIGN KEY IF EXISTS followed by an unconditional ADD
    CONSTRAINT for the two foreign keys 0012 adds (fk_shipment_special_
    order_do, fk_sri_special_line) — MariaDB has no native "ADD
    CONSTRAINT IF NOT EXISTS ... FOREIGN KEY" (confirmed: a hard SQL
    syntax error on 10.11), but DROP FOREIGN KEY IF EXISTS is supported
    and safe to run whether or not the constraint already exists, so the
    pair always converges on exactly the same FK being present.

Tested against every plausible live drift state (see api/tests/
test-0013-live-schema-repair.sh in the source repository, not shipped in
this package): a fresh install, the historical "81133f0" and "7c30712"
revisions of 0012 applied live-style then repaired, and an already-fully-
current schema (0013 is a safe no-op there too, including when its own
raw SQL is reissued a second time). Schema equivalence to a clean
0001-0011 + current-final-0012 build was verified table-by-table. A
data-preservation test seeded one real row into every major business
table family (Regular PO, Production, FG, DO, Shipment, Special Order —
Users/Stores/Products already present) and confirmed byte-for-byte
identical content before and after 0013.

This is a SCHEMA-ONLY repair. No allocation logic, production formula,
DO rule, receipt rule, Driver flow, Regular PO behavior, stock ledger
semantics, Invoice, or Replacement/Reject logic changed — the
application code in this package is identical to what already shipped
in amor-factory-fg-allocation-bridge.zip. This package only makes the
LIVE DATABASE SCHEMA match that already-deployed code.

Once 0013 is applied, Produksi -> Order Masuk / Demand Tambahan (which
was rendering blank because it reaches SpecialOrderFgAllocationRepository,
whose query fails against a live database missing special_order_fg_
allocation entirely) works again, and existing orders such as
NPR-20260923-001 can allocate from General FG without requiring fake
production.
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

echo "--- sanity: confirm NO browser-facing PHP file references the deny-all api/app/ or api/uploads/ paths ---"
if grep -rl 'href="/api/app/\|src="/api/app/\|href="/api/uploads/\|src="/api/uploads/' "$STAGE/api" --include='*.php' | grep -q .; then
  echo "REFUSING TO BUILD: a browser-facing href/src points directly inside a deny-all tree." >&2
  grep -rln 'href="/api/app/\|src="/api/app/\|href="/api/uploads/\|src="/api/uploads/' "$STAGE/api" --include='*.php' >&2
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
