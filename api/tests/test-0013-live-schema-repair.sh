#!/usr/bin/env bash
# Migration 0013 (LIVE SCHEMA REPAIR for 0012) — mandatory test suite.
#
# Live cPanel diagnostic confirmed schema_migrations already has a row for
# 0012_production_flow_completion.php, but special_order_fg_allocation does
# not exist — meaning 0012 was applied live from an EARLIER revision of its
# own SQL than the one now in this repository. This script proves 0013
# repairs every plausible such drift state safely, additively, and without
# ever touching the 0012 registry row or any existing business data.
#
# Uses ONE disposable local-only MariaDB instance (never touches any real
# database) with FOUR separate schemas, one per state:
#   STATE A — fresh 0001-0011, then current-final 0012, then 0013 in one
#             pass (the ordinary fresh-install path). 0013 must be a safe
#             no-op layered on an already-fully-current schema.
#   STATE B — fresh 0001-0011, then the REAL historical "7c30712" revision
#             of 0012's SQL applied directly (not through migrate.php) and
#             schema_migrations seeded to mark 0012 applied — this is the
#             commit message's own "Final pre-live rework", and the
#             revision most likely to be what is actually live on cPanel
#             (it differs from the current final 0012 by exactly the two
#             things the live diagnostic found missing: special_order_fg_
#             allocation and the stock_ledger enum value). Then 0013 is
#             applied through the REAL migrate.php path, exactly as the
#             operator will run it. THIS IS THE CRITICAL TEST MATCHING LIVE.
#   STATE C — same as STATE B but using the earlier "81133f0" revision,
#             which is additionally missing shipment_receipt_token and the
#             shipment_receipt_item widening — proves 0013 also repairs a
#             MORE incomplete live drift, not only the exact one the
#             diagnostic happened to find.
#   STATE D — same fresh 0001-0013 pass as STATE A, but afterward 0013's
#             own SQL statements are re-executed a SECOND time directly
#             (bypassing the schema_migrations registry entirely) to prove
#             genuine SQL-level idempotency, not merely "the registry
#             stops it from running twice".
#
# After STATE B repairs to current-final, its schema is compared
# (SHOW CREATE TABLE, per affected table) against a fifth, CLEAN reference
# schema built directly from 0001-0011 + current-final 0012 (no 0013
# involved at all) — they must be functionally equivalent.
#
# A DATA PRESERVATION check seeds one real, fully-linked row into every
# major business table family (Regular PO, Production, FG, DO, Shipment,
# Special Order — Users/Stores/Products already exist from the master
# bootstrap) on STATE B before 0013 runs, snapshots every table's full
# content, applies 0013, and re-snapshots — the two snapshots must be
# byte-for-byte identical.
#
# Usage: bash api/tests/test-0013-live-schema-repair.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$API_ROOT/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
MIGRATION_USER_PASS="MigrationUserPass0013_123"
FAILED=0

cleanup() {
  echo "--- tearing down (disposable, local-only — nothing persistent touched) ---"
  if [ -S "$SOCK" ]; then
    mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true
    sleep 1
  fi
  rm -f "$API_ROOT/app/config/config.php"
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

check() {
  local label="$1" ok="$2"
  if [ "$ok" = "1" ]; then
    echo "PASS: $label"
  else
    echo "FAIL: $label"
    FAILED=1
  fi
}

write_config() {
  local db_name="$1"
  cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$db_name',
    'EXPECTED_DB_NAME' => '$db_name',
    'DB_USER' => 'repair0013_migration_user',
    'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
}

echo "--- 1/10: initializing disposable MariaDB datadir ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 \
  || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }

echo "--- 2/10: starting disposable MariaDB (--skip-networking) ---"
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root \
  --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do
  [ -S "$SOCK" ] && break
  sleep 0.5
done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }

echo "--- 3/10: creating the migration DB user + 4 disposable schemas ---"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'repair0013_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
CREATE DATABASE state_a CHARACTER SET utf8mb4;
CREATE DATABASE state_b CHARACTER SET utf8mb4;
CREATE DATABASE state_c CHARACTER SET utf8mb4;
CREATE DATABASE state_d CHARACTER SET utf8mb4;
CREATE DATABASE clean_ref CHARACTER SET utf8mb4;
GRANT ALL PRIVILEGES ON state_a.* TO 'repair0013_migration_user'@'localhost';
GRANT ALL PRIVILEGES ON state_b.* TO 'repair0013_migration_user'@'localhost';
GRANT ALL PRIVILEGES ON state_c.* TO 'repair0013_migration_user'@'localhost';
GRANT ALL PRIVILEGES ON state_d.* TO 'repair0013_migration_user'@'localhost';
GRANT ALL PRIVILEGES ON clean_ref.* TO 'repair0013_migration_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 4/10: extracting the two real historical 0012 revisions from git history ---"
git -C "$REPO_ROOT" show 7c30712:database/schema-v1-0012-production-flow-completion.sql > "$WORKDIR/0012-at-7c30712.sql" \
  || { echo "git show 7c30712 FAILED"; exit 1; }
git -C "$REPO_ROOT" show 81133f0:database/schema-v1-0012-production-flow-completion.sql > "$WORKDIR/0012-at-81133f0.sql" \
  || { echo "git show 81133f0 FAILED"; exit 1; }

# Applies ONLY 0001-0011 by running migrate.php against a temporary
# migrations directory that is a symlink farm of just those 11 files —
# MigrationRunner::pendingMigrations() globs __DIR__/../../migrations, so
# this is done by temporarily moving 0012/0013 out of the way.
apply_0001_0011_only() {
  local db_name="$1"
  write_config "$db_name"
  mkdir -p "$WORKDIR/hidden-migrations"
  mv "$API_ROOT/app/migrations/0012_production_flow_completion.php" "$WORKDIR/hidden-migrations/" \
    || { echo "FATAL: could not hide 0012 migration file — wrong path?"; exit 1; }
  mv "$API_ROOT/app/migrations/0013_repair_production_flow_completion.php" "$WORKDIR/hidden-migrations/" \
    || { echo "FATAL: could not hide 0013 migration file — wrong path?"; exit 1; }
  php "$API_ROOT/bin/migrate.php" --yes > "$WORKDIR/migrate-0001-0011-$db_name.log" 2>&1
  local rc=$?
  mv "$WORKDIR/hidden-migrations/0012_production_flow_completion.php" "$API_ROOT/app/migrations/" \
    || { echo "FATAL: could not restore 0012 migration file"; exit 1; }
  mv "$WORKDIR/hidden-migrations/0013_repair_production_flow_completion.php" "$API_ROOT/app/migrations/" \
    || { echo "FATAL: could not restore 0013 migration file"; exit 1; }
  if ! grep -q "OK    0011_production_task_per_division.php" "$WORKDIR/migrate-0001-0011-$db_name.log"; then
    echo "FATAL: 0001-0011-only apply for $db_name did not report 0011 as OK — see log:"
    cat "$WORKDIR/migrate-0001-0011-$db_name.log"
    exit 1
  fi
  if grep -q "0012_production_flow_completion.php\|0013_repair_production_flow_completion.php" "$WORKDIR/migrate-0001-0011-$db_name.log"; then
    echo "FATAL: 0001-0011-only apply for $db_name unexpectedly touched 0012/0013 — hide did not work:"
    cat "$WORKDIR/migrate-0001-0011-$db_name.log"
    exit 1
  fi
  return $rc
}

apply_all_pending() {
  local db_name="$1"
  write_config "$db_name"
  php "$API_ROOT/bin/migrate.php" --yes > "$WORKDIR/migrate-all-$db_name.log" 2>&1
}

seed_master() {
  local db_name="$1"
  write_config "$db_name"
  php "$API_ROOT/bin/seed.php" > "$WORKDIR/seed-$db_name.log" 2>&1
  php "$API_ROOT/tests/_phase2_bootstrap_master.php" > "$WORKDIR/bootstrap-$db_name.log" 2>&1
  ADMIN_PASSWORD="Repair0013AdminPass#$(date +%s)" php "$API_ROOT/bin/create_admin.php" repair0013_admin "Repair 0013 Test Admin" > "$WORKDIR/admin-$db_name.log" 2>&1
}

apply_old_draft_as_0012() {
  local db_name="$1" sql_file="$2"
  mariadb --socket="$SOCK" -u root "$db_name" < "$sql_file" \
    || { echo "applying old-draft SQL to $db_name FAILED"; cat "$WORKDIR/migrate-old-$db_name.log" 2>/dev/null; return 1; }
  mariadb --socket="$SOCK" -u root "$db_name" -e \
    "INSERT INTO schema_migrations (migration, applied_at) VALUES ('0012_production_flow_completion.php', UTC_TIMESTAMP())"
}

diagnose() {
  local db_name="$1"
  php "$API_ROOT/tests/_diagnose_0012_schema.php" "$SOCK" "$db_name"
}

echo "--- 5/10: STATE A — fresh 0001-0011, then current-final 0012 + 0013 in one pass ---"
apply_0001_0011_only state_a || { echo "STATE A: 0001-0011 apply FAILED"; cat "$WORKDIR/migrate-0001-0011-state_a.log"; FAILED=1; }
apply_all_pending state_a || { echo "STATE A: 0012+0013 apply FAILED"; cat "$WORKDIR/migrate-all-state_a.log"; FAILED=1; }
diagnose state_a > "$WORKDIR/diag-state_a.log" 2>&1
DIAG_A_RC=$?
cat "$WORKDIR/diag-state_a.log"
check "STATE A: fresh 0001-0011 -> final 0012 -> 0013 completes safely and every object is EXISTS" "$([ "$DIAG_A_RC" -eq 0 ] && echo 1 || echo 0)"

echo "--- 6/10: STATE B — old '7c30712' 0012 applied live-style, then repaired by 0013 (CRITICAL — matches live) ---"
apply_0001_0011_only state_b || { echo "STATE B: 0001-0011 apply FAILED"; FAILED=1; }
apply_old_draft_as_0012 state_b "$WORKDIR/0012-at-7c30712.sql" || FAILED=1
diagnose state_b > "$WORKDIR/diag-state_b-before.log" 2>&1
DIAG_B_BEFORE_RC=$?
check "STATE B: after the OLD 7c30712-shaped 0012 is applied (before 0013), special_order_fg_allocation is genuinely MISSING (sanity check the test fixture itself is representative)" \
  "$(grep -q '^special_order_fg_allocation .*= MISSING$' "$WORKDIR/diag-state_b-before.log" && echo 1 || echo 0)"

echo "--- 6b/10: seeding realistic business data on STATE B BEFORE 0013 (data preservation test) ---"
seed_master state_b
write_config state_b
php "$API_ROOT/tests/_seed_0013_data_preservation.php" "$SOCK" state_b > "$WORKDIR/seed-preservation-state_b.log" 2>&1 \
  || { echo "STATE B: data-preservation seed FAILED"; cat "$WORKDIR/seed-preservation-state_b.log"; FAILED=1; }
cat "$WORKDIR/seed-preservation-state_b.log"
php "$API_ROOT/tests/_snapshot_0013_tables.php" "$SOCK" state_b > "$WORKDIR/snapshot-state_b-before.txt" 2>&1

echo "--- 6c/10: applying 0013 through the REAL migrate.php path (exactly as the operator will run it) ---"
write_config state_b
php "$API_ROOT/bin/migrate.php" --yes > "$WORKDIR/migrate-0013-state_b.log" 2>&1
MIGRATE_0013_RC=$?
cat "$WORKDIR/migrate-0013-state_b.log"
check "STATE B: 0013 applies cleanly through migrate.php (0012 registry row untouched, only 0013 runs)" "$([ "$MIGRATE_0013_RC" -eq 0 ] && grep -q "OK    0013_repair_production_flow_completion.php" "$WORKDIR/migrate-0013-state_b.log" && echo 1 || echo 0)"
check "STATE B: 0012's registry row is still exactly 0012_production_flow_completion.php (never deleted/renamed/re-run)" \
  "$(mariadb --socket="$SOCK" -u root state_b -N -e "SELECT COUNT(*) FROM schema_migrations WHERE migration = '0012_production_flow_completion.php'" | grep -q '^1$' && echo 1 || echo 0)"

diagnose state_b > "$WORKDIR/diag-state_b-after.log" 2>&1
DIAG_B_AFTER_RC=$?
cat "$WORKDIR/diag-state_b-after.log"
check "STATE B (the critical live-matching test): after 0013, EVERY final-0012 object is EXISTS" "$([ "$DIAG_B_AFTER_RC" -eq 0 ] && echo 1 || echo 0)"

php "$API_ROOT/tests/_snapshot_0013_tables.php" "$SOCK" state_b > "$WORKDIR/snapshot-state_b-after.txt" 2>&1
if diff -q "$WORKDIR/snapshot-state_b-before.txt" "$WORKDIR/snapshot-state_b-after.txt" > /dev/null 2>&1; then
  check "DATA PRESERVATION: every seeded business table is byte-for-byte identical before/after 0013 (Regular PO, Production, FG, DO, Shipment, Special Order, Users, Stores, Products)" 1
else
  echo "--- snapshot diff (before vs after) ---"
  diff "$WORKDIR/snapshot-state_b-before.txt" "$WORKDIR/snapshot-state_b-after.txt"
  check "DATA PRESERVATION: every seeded business table is byte-for-byte identical before/after 0013" 0
fi

echo "--- 7/10: STATE C — older '81133f0' 0012 (more incomplete) applied live-style, then repaired by 0013 ---"
apply_0001_0011_only state_c || { echo "STATE C: 0001-0011 apply FAILED"; FAILED=1; }
apply_old_draft_as_0012 state_c "$WORKDIR/0012-at-81133f0.sql" || FAILED=1
diagnose state_c > "$WORKDIR/diag-state_c-before.log" 2>&1
check "STATE C: after the OLDER 81133f0-shaped 0012 (before 0013), shipment_receipt_token is genuinely MISSING (sanity check)" \
  "$(grep -q '^shipment_receipt_token .*= MISSING$' "$WORKDIR/diag-state_c-before.log" && echo 1 || echo 0)"
write_config state_c
php "$API_ROOT/bin/migrate.php" --yes > "$WORKDIR/migrate-0013-state_c.log" 2>&1
MIGRATE_0013_C_RC=$?
cat "$WORKDIR/migrate-0013-state_c.log"
diagnose state_c > "$WORKDIR/diag-state_c-after.log" 2>&1
DIAG_C_AFTER_RC=$?
cat "$WORKDIR/diag-state_c-after.log"
check "STATE C: 0013 also fully repairs the MORE incomplete 81133f0-shaped drift, no duplicate column/index/FK errors, every object EXISTS afterward" \
  "$([ "$MIGRATE_0013_C_RC" -eq 0 ] && [ "$DIAG_C_AFTER_RC" -eq 0 ] && echo 1 || echo 0)"

echo "--- 8/10: STATE D — 0013's own SQL re-executed a SECOND time directly (true SQL-level idempotency, not just registry-once) ---"
apply_0001_0011_only state_d || { echo "STATE D: 0001-0011 apply FAILED"; FAILED=1; }
apply_all_pending state_d || { echo "STATE D: 0012+0013 apply FAILED"; cat "$WORKDIR/migrate-all-state_d.log"; FAILED=1; }
php "$API_ROOT/tests/_snapshot_0013_tables.php" "$SOCK" state_d > "$WORKDIR/snapshot-state_d-before-rerun.txt" 2>&1
mariadb --socket="$SOCK" -u root state_d < "$REPO_ROOT/database/schema-v1-0013-repair-production-flow-completion.sql" > "$WORKDIR/rerun-0013-state_d.log" 2>&1
RERUN_RC=$?
cat "$WORKDIR/rerun-0013-state_d.log"
check "STATE D: reissuing 0013's raw SQL a second time against an already-fully-current schema causes ZERO errors" "$([ "$RERUN_RC" -eq 0 ] && echo 1 || echo 0)"
diagnose state_d > "$WORKDIR/diag-state_d.log" 2>&1
DIAG_D_RC=$?
check "STATE D: schema is still fully correct after the redundant re-run" "$([ "$DIAG_D_RC" -eq 0 ] && echo 1 || echo 0)"
php "$API_ROOT/tests/_snapshot_0013_tables.php" "$SOCK" state_d > "$WORKDIR/snapshot-state_d-after-rerun.txt" 2>&1
if diff -q "$WORKDIR/snapshot-state_d-before-rerun.txt" "$WORKDIR/snapshot-state_d-after-rerun.txt" > /dev/null 2>&1; then
  check "STATE D: the redundant re-run altered zero rows (pure DDL, no data side effects)" 1
else
  diff "$WORKDIR/snapshot-state_d-before-rerun.txt" "$WORKDIR/snapshot-state_d-after-rerun.txt"
  check "STATE D: the redundant re-run altered zero rows" 0
fi

echo "--- 9/10: SCHEMA EQUIVALENCE — STATE B (repaired via 0013) vs a CLEAN 0001-0011+final-0012 reference (0013 never applied) ---"
write_config clean_ref
mkdir -p "$WORKDIR/hidden-migrations-clean"
mv "$API_ROOT/app/migrations/0013_repair_production_flow_completion.php" "$WORKDIR/hidden-migrations-clean/" \
  || { echo "FATAL: could not hide 0013 migration file for clean_ref"; exit 1; }
php "$API_ROOT/bin/migrate.php" --yes > "$WORKDIR/migrate-0001-0011-clean_ref.log" 2>&1
CLEAN_REF_RC=$?
mv "$WORKDIR/hidden-migrations-clean/0013_repair_production_flow_completion.php" "$API_ROOT/app/migrations/" \
  || { echo "FATAL: could not restore 0013 migration file"; exit 1; }
if grep -q "0013_repair_production_flow_completion.php" "$WORKDIR/migrate-0001-0011-clean_ref.log"; then
  echo "FATAL: clean_ref unexpectedly applied 0013 — hide did not work:"; cat "$WORKDIR/migrate-0001-0011-clean_ref.log"; exit 1
fi
check "clean_ref: 0001-0011 + current-final 0012 (0013 never applied) builds cleanly, as the equivalence baseline" "$([ "$CLEAN_REF_RC" -eq 0 ] && echo 1 || echo 0)"

EQUIV_TABLES="special_order_item special_order_do special_order_do_item special_order_do_shipment_item shipment shipment_receipt_token shipment_receipt_item special_order_fg_allocation stock_ledger"
EQUIV_OK=1
for t in $EQUIV_TABLES; do
  # AUTO_INCREMENT values legitimately differ (different insert history) and
  # MariaDB sometimes renders a double space in table options after a table
  # has been through ALTER TABLE vs a fresh CREATE (a harmless rendering
  # artifact, not a structural difference) — both are normalized away here
  # so only genuine column/index/FK/engine differences can fail this check.
  CREATE_B=$(mariadb --socket="$SOCK" -u root state_b -e "SHOW CREATE TABLE \`$t\`\G" 2>/dev/null | grep -v "^\*\|^Table:" | sed 's/AUTO_INCREMENT=[0-9]*//' | tr -s ' ')
  CREATE_REF=$(mariadb --socket="$SOCK" -u root clean_ref -e "SHOW CREATE TABLE \`$t\`\G" 2>/dev/null | grep -v "^\*\|^Table:" | sed 's/AUTO_INCREMENT=[0-9]*//' | tr -s ' ')
  if [ "$CREATE_B" != "$CREATE_REF" ]; then
    echo "SCHEMA DIFF on $t:"
    diff <(echo "$CREATE_B") <(echo "$CREATE_REF")
    EQUIV_OK=0
  fi
done
check "SCHEMA EQUIVALENCE: every 0012/0013-affected table is structurally IDENTICAL between STATE B (repaired) and the clean reference" "$EQUIV_OK"

echo "--- 10/10: PHP syntax check on all 0013-related files ---"
PHP_LINT_OK=1
for f in "$API_ROOT/app/migrations/0013_repair_production_flow_completion.php" "$API_ROOT/tests/_diagnose_0012_schema.php" "$API_ROOT/tests/_seed_0013_data_preservation.php" "$API_ROOT/tests/_snapshot_0013_tables.php"; do
  php -l "$f" > /dev/null 2>&1 || { echo "php -l FAILED for $f"; PHP_LINT_OK=0; }
done
check "PHP syntax: 0013 migration + all diagnostic/seed/snapshot scripts are clean" "$PHP_LINT_OK"

echo ""
if [ "$FAILED" -eq 0 ]; then
  echo "=== migration 0013 live schema repair: ALL CHECKS PASSED ==="
  exit 0
else
  echo "=== migration 0013 live schema repair: SOME CHECKS FAILED — see FAIL lines above ==="
  exit 1
fi
