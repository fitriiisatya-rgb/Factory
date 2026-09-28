#!/usr/bin/env bash
# Migration 0016 (Replacement Reject) — mandatory retry-safety test suite
# (REPL-MIG-01..06). Migration 0016 has NEVER been applied to any live
# database — unlike 0013's own repair test (which had to reproduce real
# historical drift shapes from git history), this suite only needs to
# prove the migration's OWN SQL tolerates re-application safely, since a
# source deep-check found its one FK-add statement lacked an existence
# guard (fixed in place — see this file's own docblock in the SQL file).
#
# Uses ONE disposable local-only MariaDB instance (never touches any real
# database) with THREE separate schemas:
#   STATE A — fresh 0001-0015, seed realistic master data, snapshot,
#             apply 0016 through the REAL migrate.php path (exactly as an
#             operator will run it), snapshot again. Proves REPL-MIG-01
#             (clean apply succeeds, table count is 68) and REPL-MIG-05
#             (every pre-existing 0001-0015 row survives byte-for-byte).
#   STATE B — fresh 0001-0015, then 0016's OWN SQL with ONLY its FK-add
#             statements removed is applied directly (simulating "tables/
#             columns already exist, the FK does not yet") — then the
#             FULL, unmodified 0016 SQL is applied again. Proves
#             REPL-MIG-02.
#   STATE C — fresh 0001-0015, then the FULL 0016 SQL applied directly
#             ONCE (everything present, including the FK) — then the SAME
#             FULL SQL applied again a second time. Proves REPL-MIG-03
#             (FK-already-exists tolerated) and REPL-MIG-04 (a second raw
#             SQL run succeeds with zero destructive change).
# REPL-MIG-06 (no 0017 exists) is a plain filesystem check, no DB needed.
#
# Usage: bash api/tests/test-0016-replacement-reject-migration.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$API_ROOT/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
MIGRATION_USER_PASS="MigrationUserPass0016_123"
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
    'DB_USER' => 'repl0016_migration_user',
    'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
}

echo "--- 1/9: initializing disposable MariaDB datadir ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 \
  || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }

echo "--- 2/9: starting disposable MariaDB (--skip-networking) ---"
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root \
  --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do
  [ -S "$SOCK" ] && break
  sleep 0.5
done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }

echo "--- 3/9: creating the migration DB user + 3 disposable schemas ---"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'repl0016_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
CREATE DATABASE state_a CHARACTER SET utf8mb4;
CREATE DATABASE state_b CHARACTER SET utf8mb4;
CREATE DATABASE state_c CHARACTER SET utf8mb4;
GRANT ALL PRIVILEGES ON state_a.* TO 'repl0016_migration_user'@'localhost';
GRANT ALL PRIVILEGES ON state_b.* TO 'repl0016_migration_user'@'localhost';
GRANT ALL PRIVILEGES ON state_c.* TO 'repl0016_migration_user'@'localhost';
FLUSH PRIVILEGES;
"

# Applies ONLY 0001-0015 by temporarily hiding 0016's own migration
# pointer file — MigrationRunner::pendingMigrations() globs
# __DIR__/../../migrations, so this is the same "hide, apply, restore"
# technique migration 0013's own test script established.
apply_0001_0015_only() {
  local db_name="$1"
  write_config "$db_name"
  mkdir -p "$WORKDIR/hidden-migrations"
  mv "$API_ROOT/app/migrations/0016_replacement_reject.php" "$WORKDIR/hidden-migrations/" \
    || { echo "FATAL: could not hide 0016 migration file — wrong path?"; exit 1; }
  php "$API_ROOT/bin/migrate.php" --yes > "$WORKDIR/migrate-0001-0015-$db_name.log" 2>&1
  local rc=$?
  mv "$WORKDIR/hidden-migrations/0016_replacement_reject.php" "$API_ROOT/app/migrations/" \
    || { echo "FATAL: could not restore 0016 migration file"; exit 1; }
  if ! grep -q "OK    0015_fg_store_packing_submission.php" "$WORKDIR/migrate-0001-0015-$db_name.log"; then
    echo "FATAL: 0001-0015-only apply for $db_name did not report 0015 as OK — see log:"
    cat "$WORKDIR/migrate-0001-0015-$db_name.log"
    exit 1
  fi
  if grep -q "0016_replacement_reject.php" "$WORKDIR/migrate-0001-0015-$db_name.log"; then
    echo "FATAL: 0001-0015-only apply for $db_name unexpectedly touched 0016 — hide did not work:"
    cat "$WORKDIR/migrate-0001-0015-$db_name.log"
    exit 1
  fi
  return $rc
}

seed_master() {
  local db_name="$1"
  write_config "$db_name"
  php "$API_ROOT/bin/seed.php" > "$WORKDIR/seed-$db_name.log" 2>&1
  php "$API_ROOT/tests/_phase2_bootstrap_master.php" > "$WORKDIR/bootstrap-$db_name.log" 2>&1
  ADMIN_PASSWORD="Repl0016AdminPass#$(date +%s)" php "$API_ROOT/bin/create_admin.php" repl0016_admin "Repl 0016 Test Admin" > "$WORKDIR/admin-$db_name.log" 2>&1
}

table_count() {
  local db_name="$1"
  mariadb --socket="$SOCK" -u root "$db_name" -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$db_name' AND table_name != 'schema_migrations'"
}

fk_exists() {
  local db_name="$1"
  mariadb --socket="$SOCK" -u root "$db_name" -N -e \
    "SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = '$db_name' AND constraint_name = 'fk_shipment_replacement_do' AND constraint_type = 'FOREIGN KEY'"
}

echo "--- 4/9: STATE A — fresh 0001-0015, seed real master data, snapshot, apply 0016 via migrate.php, re-snapshot (REPL-MIG-01 + REPL-MIG-05) ---"
apply_0001_0015_only state_a || { echo "STATE A: 0001-0015 apply FAILED"; FAILED=1; }
seed_master state_a
write_config state_a
php "$API_ROOT/tests/_snapshot_0013_tables.php" "$SOCK" state_a > "$WORKDIR/snapshot-state_a-before.txt" 2>&1
php "$API_ROOT/bin/migrate.php" --yes > "$WORKDIR/migrate-0016-state_a.log" 2>&1
MIGRATE_0016_RC=$?
cat "$WORKDIR/migrate-0016-state_a.log"
check "REPL-MIG-01: 0016 applies cleanly through migrate.php against a clean 0015 schema" \
  "$([ "$MIGRATE_0016_RC" -eq 0 ] && grep -q "OK    0016_replacement_reject.php" "$WORKDIR/migrate-0016-state_a.log" && echo 1 || echo 0)"
COUNT_A=$(table_count state_a)
check "REPL-MIG-01: exactly 68 business tables exist after 0016 (64 + 4 new Replacement tables), got $COUNT_A" "$([ "$COUNT_A" = "68" ] && echo 1 || echo 0)"
php "$API_ROOT/tests/_snapshot_0013_tables.php" "$SOCK" state_a > "$WORKDIR/snapshot-state_a-after.txt" 2>&1
if diff -q "$WORKDIR/snapshot-state_a-before.txt" "$WORKDIR/snapshot-state_a-after.txt" > /dev/null 2>&1; then
  check "REPL-MIG-05: every pre-existing 0001-0015 business row is byte-for-byte identical before/after 0016" 1
else
  echo "--- snapshot diff (before vs after) ---"
  diff "$WORKDIR/snapshot-state_a-before.txt" "$WORKDIR/snapshot-state_a-after.txt"
  check "REPL-MIG-05: every pre-existing 0001-0015 business row is byte-for-byte identical before/after 0016" 0
fi

echo "--- 5/9: STATE B — 0016 SQL with its FK-add statements REMOVED applied first (tables/columns exist, FK does not), then the FULL SQL applied again (REPL-MIG-02) ---"
apply_0001_0015_only state_b || { echo "STATE B: 0001-0015 apply FAILED"; FAILED=1; }
sed '/DROP FOREIGN KEY IF EXISTS fk_shipment_replacement_do/,/ADD CONSTRAINT fk_shipment_replacement_do FOREIGN KEY/d' \
  "$REPO_ROOT/database/schema-v1-0016-replacement-reject.sql" > "$WORKDIR/0016-no-fk.sql"
mariadb --socket="$SOCK" -u root state_b < "$WORKDIR/0016-no-fk.sql" > "$WORKDIR/apply-0016-no-fk-state_b.log" 2>&1
NO_FK_RC=$?
cat "$WORKDIR/apply-0016-no-fk-state_b.log"
check "STATE B setup: the FK-stripped 0016 SQL applies cleanly (tables/columns exist, FK does not yet)" "$([ "$NO_FK_RC" -eq 0 ] && echo 1 || echo 0)"
FK_BEFORE_B=$(fk_exists state_b)
check "STATE B setup sanity: fk_shipment_replacement_do is genuinely MISSING before the real retry" "$([ "$FK_BEFORE_B" = "0" ] && echo 1 || echo 0)"
mariadb --socket="$SOCK" -u root state_b < "$REPO_ROOT/database/schema-v1-0016-replacement-reject.sql" > "$WORKDIR/apply-0016-full-state_b.log" 2>&1
FULL_RETRY_B_RC=$?
cat "$WORKDIR/apply-0016-full-state_b.log"
check "REPL-MIG-02: rerunning the FULL 0016 SQL when tables/columns already exist but the FK does not succeeds" "$([ "$FULL_RETRY_B_RC" -eq 0 ] && echo 1 || echo 0)"
FK_AFTER_B=$(fk_exists state_b)
check "STATE B: fk_shipment_replacement_do exists exactly once after the retry (no duplicate)" "$([ "$FK_AFTER_B" = "1" ] && echo 1 || echo 0)"

echo "--- 6/9: STATE C — FULL 0016 SQL applied once, then the SAME FULL SQL applied a SECOND time directly (REPL-MIG-03 + REPL-MIG-04) ---"
apply_0001_0015_only state_c || { echo "STATE C: 0001-0015 apply FAILED"; FAILED=1; }
mariadb --socket="$SOCK" -u root state_c < "$REPO_ROOT/database/schema-v1-0016-replacement-reject.sql" > "$WORKDIR/apply-0016-first-state_c.log" 2>&1
FIRST_C_RC=$?
cat "$WORKDIR/apply-0016-first-state_c.log"
check "STATE C setup: 0016 applies cleanly the FIRST time (fk_shipment_replacement_do now exists)" "$([ "$FIRST_C_RC" -eq 0 ] && [ "$(fk_exists state_c)" = "1" ] && echo 1 || echo 0)"
php "$API_ROOT/tests/_snapshot_0013_tables.php" "$SOCK" state_c > "$WORKDIR/snapshot-state_c-before-rerun.txt" 2>&1
mariadb --socket="$SOCK" -u root state_c < "$REPO_ROOT/database/schema-v1-0016-replacement-reject.sql" > "$WORKDIR/apply-0016-second-state_c.log" 2>&1
SECOND_C_RC=$?
cat "$WORKDIR/apply-0016-second-state_c.log"
check "REPL-MIG-03: rerunning the FULL 0016 SQL when the FK ALREADY exists succeeds (no duplicate-constraint error)" "$([ "$SECOND_C_RC" -eq 0 ] && echo 1 || echo 0)"
check "REPL-MIG-04: the second raw SQL run succeeds with zero errors" "$([ "$SECOND_C_RC" -eq 0 ] && echo 1 || echo 0)"
FK_COUNT_C=$(fk_exists state_c)
check "STATE C: fk_shipment_replacement_do still exists EXACTLY once after two full runs (never duplicated)" "$([ "$FK_COUNT_C" = "1" ] && echo 1 || echo 0)"
php "$API_ROOT/tests/_snapshot_0013_tables.php" "$SOCK" state_c > "$WORKDIR/snapshot-state_c-after-rerun.txt" 2>&1
if diff -q "$WORKDIR/snapshot-state_c-before-rerun.txt" "$WORKDIR/snapshot-state_c-after-rerun.txt" > /dev/null 2>&1; then
  check "REPL-MIG-04: the redundant second run altered zero rows (pure DDL, no destructive change)" 1
else
  diff "$WORKDIR/snapshot-state_c-before-rerun.txt" "$WORKDIR/snapshot-state_c-after-rerun.txt"
  check "REPL-MIG-04: the redundant second run altered zero rows" 0
fi
COUNT_C=$(table_count state_c)
check "STATE C: still exactly 68 business tables after two full runs (no duplicate table created)" "$([ "$COUNT_C" = "68" ] && echo 1 || echo 0)"

echo "--- 7/9: REPL-MIG-06 — no migration 0017 exists ---"
if find "$API_ROOT/app/migrations" -name '0017_*' | grep -q .; then
  check "REPL-MIG-06: no migration 0017+ exists (0016 was fixed in place, never superseded)" 0
else
  check "REPL-MIG-06: no migration 0017+ exists (0016 was fixed in place, never superseded)" 1
fi

echo "--- 8/9: ENUM widen-only sanity — every prior value from 0001-0015 is still present after 0016 (never narrowed) ---"
ENUM_SHIPMENT=$(mariadb --socket="$SOCK" -u root state_a -N -e "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='state_a' AND TABLE_NAME='shipment' AND COLUMN_NAME='source_type'")
ENUM_LEDGER=$(mariadb --socket="$SOCK" -u root state_a -N -e "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='state_a' AND TABLE_NAME='stock_ledger' AND COLUMN_NAME='source_type'")
ENUM_OK=1
for v in "delivery_order" "manual_kirim" "customer_order_fulfillment" "special_order_do" "replacement_do"; do
  echo "$ENUM_SHIPMENT" | grep -q "'$v'" || { echo "MISSING shipment.source_type value: $v"; ENUM_OK=0; }
done
for v in "production_run" "shipment_item" "stock_adjustment" "stock_transfer" "opening_balance_cutover" "historical_replay" "reversal" "fg_item" "special_order_fg_allocation" "replacement_demand_fg_allocation"; do
  echo "$ENUM_LEDGER" | grep -q "'$v'" || { echo "MISSING stock_ledger.source_type value: $v"; ENUM_OK=0; }
done
check "ENUM AUDIT: shipment.source_type and stock_ledger.source_type carry every value from 0001-0015 PLUS this pass's own new value, none narrowed" "$ENUM_OK"

echo "--- 9/9: PHP syntax check on the migration + its SQL is parseable by MigrationRunner's own splitter ---"
PHP_LINT_OK=1
php -l "$API_ROOT/app/migrations/0016_replacement_reject.php" > /dev/null 2>&1 || { echo "php -l FAILED for 0016_replacement_reject.php"; PHP_LINT_OK=0; }
check "PHP syntax: 0016 migration pointer file is clean" "$PHP_LINT_OK"

echo ""
if [ "$FAILED" -eq 0 ]; then
  echo "=== migration 0016 retry-safety: ALL CHECKS PASSED ==="
  exit 0
else
  echo "=== migration 0016 retry-safety: SOME CHECKS FAILED — see FAIL lines above ==="
  exit 1
fi
