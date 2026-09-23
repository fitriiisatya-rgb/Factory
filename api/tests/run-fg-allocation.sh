#!/usr/bin/env bash
# "Existing FG Allocation Bridge" (special_order_fg_allocation, migration
# 0012's own extension) — ALLOC-01..15, see FgAllocationTest.php's own
# docblock — followed by the FULL existing Phase 0-5.5 + Special Order +
# Production Flow Completion + Final Pre-Live Rework regression suite —
# this bridge must never regress any prior phase, and Regular PO must stay
# completely unchanged.
#
# Spins up a DISPOSABLE, local-only MariaDB instance with TWO distinctly-
# privileged real DB users, applies ALL migrations 0001-0012, bootstraps
# realistic master data (8 divisions + 472 katalog products + P2 TEST
# STORE A/B), then runs FgAllocationTest.php against a live `php -S`
# server, and finally re-runs run-final-prelive-rework.sh (which itself
# cascades through every prior phase's own orchestrator).
#
# Usage: bash api/tests/run-fg-allocation.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_alloc_test"
TEST_ADMIN_PASS="StagingAdmin#$(date +%s)ALLOC"
MIGRATION_USER_PASS="MigrationUserPassALLOC_123"
RUNTIME_USER_PASS="RuntimeUserPassALLOC_123"
PHP_PORT=8116
PHP_PID=""

cleanup() {
  echo "--- tearing down (disposable, local-only — nothing persistent touched) ---"
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then
    kill "$PHP_PID" 2>/dev/null || true
  fi
  if [ -S "$SOCK" ]; then
    mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true
    sleep 1
  fi
  rm -f "$API_ROOT/app/config/config.php"
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

echo "--- 1/8: initializing disposable MariaDB datadir ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1
if [ $? -ne 0 ]; then
  echo "mariadb-install-db FAILED — see $WORKDIR/install.log"; exit 1
fi

echo "--- 2/8: starting disposable MariaDB (--skip-networking) ---"
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root \
  --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do
  [ -S "$SOCK" ] && break
  sleep 0.5
done
if [ ! -S "$SOCK" ]; then
  echo "MariaDB did not come up — see $WORKDIR/mariadb.log"; cat "$WORKDIR/mariadb.log"; exit 1
fi
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"

echo "--- 3/8: creating the two real, distinctly-privileged DB users ---"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'alloc_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'alloc_migration_user'@'localhost';
CREATE USER 'alloc_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'alloc_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 4/8: bootstrap config.php using the MIGRATION user (needs DDL) ---"
cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME',
    'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'alloc_migration_user',
    'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/8: migrate (0001-0012, ALL phases incl. special_order_fg_allocation) + seed + create ADMIN ---"
php "$API_ROOT/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" alloc_staging_admin "Alloc Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

echo "--- 6/8: bootstrap realistic Phase 1 master data (8 divisions + 472 products + P2 TEST STORE A/B) ---"
php "$API_ROOT/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }

echo "--- 7/8: rewriting config.php to the REALISTIC post-deployment shape (DB_USER=runtime) ---"
cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME',
    'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'alloc_runtime_user',
    'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
    'APP_BASE_URL' => 'http://127.0.0.1:$PHP_PORT',
    'MAIL_ENABLED' => false,
];
PHPCONFIG

echo "--- 8/8: starting php -S dev server on :$PHP_PORT and running FgAllocationTest.php ---"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT" "$API_ROOT/tests/_ui_router.php" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="alloc_staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
TEST_DB_SOCKET="$SOCK" \
TEST_DB_NAME="$DB_NAME" \
TEST_RUNTIME_USER="alloc_runtime_user" \
TEST_RUNTIME_PASS="$RUNTIME_USER_PASS" \
php "$API_ROOT/tests/FgAllocationTest.php"
ALLOC_RESULT=$?

kill "$PHP_PID" 2>/dev/null || true
PHP_PID=""

echo "--- full existing Phase 0-5.5 + Special Order + Production Flow Completion + Final Pre-Live Rework regression ---"
# run-final-prelive-rework.sh's own final step already re-runs
# run-production-flow-completion.sh, which cascades through every prior
# phase's own orchestrator — so calling it alone covers the full
# regression chain.
REGRESSION_RESULT=0
echo "  -> run-final-prelive-rework.sh (covers FINAL-01..40 plus the full prior-phase regression)"
bash "$API_ROOT/tests/run-final-prelive-rework.sh" > "$WORKDIR/run-final-prelive-rework.sh.log" 2>&1
RC=$?
if [ $RC -ne 0 ]; then
  echo "     FAILED — last 40 lines of run-final-prelive-rework.sh's own output:"
  tail -40 "$WORKDIR/run-final-prelive-rework.sh.log"
  REGRESSION_RESULT=1
else
  tail -5 "$WORKDIR/run-final-prelive-rework.sh.log"
fi

echo ""
if [ $ALLOC_RESULT -eq 0 ]; then echo "ALLOC-01..15 (Existing FG Allocation Bridge): PASSED"; else echo "ALLOC-01..15: FAILED"; fi
if [ $REGRESSION_RESULT -eq 0 ]; then echo "Full Phase 0-5.5 + Special Order + Production Flow Completion + Final Pre-Live Rework regression: PASSED"; else echo "Full regression: FAILED"; fi

if [ $ALLOC_RESULT -eq 0 ] && [ $REGRESSION_RESULT -eq 0 ]; then
  exit 0
else
  exit 1
fi
