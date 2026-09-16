#!/usr/bin/env bash
# Phase 0 / Phase 0.5 integration test orchestrator.
#
# Spins up a DISPOSABLE, local-only MariaDB instance (never a live/persistent
# database, and NEVER the real u7566812_factory host — see OD-4 in
# docs/mysql-open-decisions-v1.md for what actually closed that), applies
# database/schema-v1.sql via the safety-gated migration runner, seeds minimum
# master data, creates a throwaway admin, starts `php -S`, runs
# Phase0Test.php's P0-01..P0-17 suite, additionally exercises the migration
# runner's safety gate / idempotent-rerun / duplicate-seed behavior, then
# tears down EVERYTHING (php server, mariadb, datadir) regardless of
# pass/fail.
#
# Usage: bash api/tests/run.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$API_ROOT/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_phase0_test"
TEST_ADMIN_PASS="StagingAdmin#$(date +%s)Test"
PHP_PORT=8089
MARIADB_PID=""
PHP_PID=""
FAILURES=0

cleanup() {
  echo "--- tearing down (disposable, local-only — nothing persistent touched) ---"
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then
    kill "$PHP_PID" 2>/dev/null || true
  fi
  if [ -S "$SOCK" ]; then
    mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true
    sleep 1
  fi
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

echo "--- 1/8: initializing disposable MariaDB datadir ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1
if [ $? -ne 0 ]; then
  echo "mariadb-install-db FAILED — see $WORKDIR/install.log"; exit 1
fi

echo "--- 2/8: starting disposable MariaDB (--skip-networking, throwaway datadir) ---"
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root \
  --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
MARIADB_PID=$!

for i in $(seq 1 30); do
  [ -S "$SOCK" ] && break
  sleep 0.5
done
if [ ! -S "$SOCK" ]; then
  echo "MariaDB did not come up — see $WORKDIR/mariadb.log"; cat "$WORKDIR/mariadb.log"; exit 1
fi

mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
DB_VERSION=$(mariadb --socket="$SOCK" -u root -N -e "SELECT VERSION();")
echo "Disposable local test DB version: $DB_VERSION"
echo "(This is a local/CI validation instance, distinct from the real u7566812_factory"
echo " host confirmed as 10.11.19-MariaDB-cll-lve — see OD-4, CLOSED, in"
echo " docs/mysql-open-decisions-v1.md. This script never touches that real host.)"

echo "--- 3/8: writing throwaway api/config/config.php for this test run ---"
cat > "$API_ROOT/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME',
    'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'SESSION_SECURE' => false, // plain-HTTP php -S dev server for this local test run only
];
PHPCONFIG

echo "--- 4/8: safety-gate proof: migrate.php must REFUSE on an EXPECTED_DB_NAME mismatch ---"
cp "$API_ROOT/config/config.php" "$WORKDIR/config-correct.php"
cat > "$API_ROOT/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME',
    'EXPECTED_DB_NAME' => 'wrong_db_name_on_purpose',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'SESSION_SECURE' => false,
];
PHPCONFIG
if php "$API_ROOT/bin/migrate.php" --yes > "$WORKDIR/gate-test.log" 2>&1; then
  echo "SAFETY GATE FAILURE: migrate.php should have REFUSED on EXPECTED_DB_NAME mismatch but exited 0"
  cat "$WORKDIR/gate-test.log"
  FAILURES=$((FAILURES+1))
else
  if grep -q "REFUSED" "$WORKDIR/gate-test.log"; then
    echo "PASS  safety gate correctly refused EXPECTED_DB_NAME mismatch"
  else
    echo "SAFETY GATE FAILURE: migrate.php exited non-zero but not via the expected REFUSED path"
    cat "$WORKDIR/gate-test.log"
    FAILURES=$((FAILURES+1))
  fi
fi
cp "$WORKDIR/config-correct.php" "$API_ROOT/config/config.php"

echo "--- 5/8: migrate (first real apply) + seed + create throwaway admin ---"
php "$API_ROOT/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" staging_admin "Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

echo "--- 6/8: idempotency proof: rerun migrate.php and seed.php, must not duplicate anything ---"
BEFORE_TABLES=$(mariadb --socket="$SOCK" -u root "$DB_NAME" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'")
BEFORE_FACTORIES=$(mariadb --socket="$SOCK" -u root "$DB_NAME" -N -e "SELECT COUNT(*) FROM factory")
BEFORE_ROLES=$(mariadb --socket="$SOCK" -u root "$DB_NAME" -N -e "SELECT COUNT(*) FROM roles")
BEFORE_STORES=$(mariadb --socket="$SOCK" -u root "$DB_NAME" -N -e "SELECT COUNT(*) FROM store WHERE canonical_name='NON-OUTLET / PERORANGAN'")

RERUN_OUTPUT=$(php "$API_ROOT/bin/migrate.php" --yes 2>&1)
echo "$RERUN_OUTPUT"
if echo "$RERUN_OUTPUT" | grep -q "Nothing to do"; then
  echo "PASS  migrate.php rerun reported 'Nothing to do' and stopped cleanly"
else
  echo "FAIL  migrate.php rerun did not report the expected idempotent-skip message"
  FAILURES=$((FAILURES+1))
fi
php "$API_ROOT/bin/seed.php" > /dev/null || { echo "seed.php rerun FAILED"; FAILURES=$((FAILURES+1)); }

AFTER_TABLES=$(mariadb --socket="$SOCK" -u root "$DB_NAME" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'")
AFTER_FACTORIES=$(mariadb --socket="$SOCK" -u root "$DB_NAME" -N -e "SELECT COUNT(*) FROM factory")
AFTER_ROLES=$(mariadb --socket="$SOCK" -u root "$DB_NAME" -N -e "SELECT COUNT(*) FROM roles")
AFTER_STORES=$(mariadb --socket="$SOCK" -u root "$DB_NAME" -N -e "SELECT COUNT(*) FROM store WHERE canonical_name='NON-OUTLET / PERORANGAN'")

echo "tables: $BEFORE_TABLES -> $AFTER_TABLES | factories: $BEFORE_FACTORIES -> $AFTER_FACTORIES | roles: $BEFORE_ROLES -> $AFTER_ROLES | synthetic store rows: $BEFORE_STORES -> $AFTER_STORES"
if [ "$BEFORE_TABLES" = "$AFTER_TABLES" ] && [ "$BEFORE_FACTORIES" = "$AFTER_FACTORIES" ] && [ "$AFTER_FACTORIES" = "2" ] \
   && [ "$BEFORE_ROLES" = "$AFTER_ROLES" ] && [ "$AFTER_ROLES" = "7" ] \
   && [ "$BEFORE_STORES" = "$AFTER_STORES" ] && [ "$AFTER_STORES" = "1" ]; then
  echo "PASS  rerunning migrate.php + seed.php did not duplicate anything"
else
  echo "FAIL  rerun changed row/table counts unexpectedly"
  FAILURES=$((FAILURES+1))
fi

echo "--- 7/8: starting php -S dev server on :$PHP_PORT ---"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT/public" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

echo "--- 8/8: running Phase0Test.php (P0-01..P0-17) ---"
TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
php "$API_ROOT/tests/Phase0Test.php"
RESULT=$?

if [ "$FAILURES" -ne 0 ]; then
  echo "$FAILURES Phase 0.5 migration-runner check(s) FAILED (see above)"
  RESULT=1
fi

rm -f "$API_ROOT/config/config.php"

exit $RESULT
