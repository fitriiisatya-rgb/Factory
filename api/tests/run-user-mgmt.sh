#!/usr/bin/env bash
# User / Driver Account Management integration test orchestrator
# (UM-01..16), followed by the FULL existing Phase 0-5.5 regression suite
# (UM-17) — this module must never regress any prior phase's own tests.
#
# Spins up a DISPOSABLE, local-only MariaDB instance (never the real
# u7566812_factory host), applies ALL migrations 0001-0007 for real (this
# module adds NO new migration — users/roles/user_roles from migration
# 0001 already supported everything required), bootstraps realistic
# master data, then runs PhaseUserMgmtTest.php against a live `php -S`
# server, and finally re-runs the Phase 5.5 orchestrator (which itself
# cascades through every earlier phase) for the full regression.
#
# Usage: bash api/tests/run-user-mgmt.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_um_test"
TEST_ADMIN_PASS="StagingAdmin#$(date +%s)UM"
MIGRATION_USER_PASS="MigrationUserPassUM_123"
RUNTIME_USER_PASS="RuntimeUserPassUM_123"
PHP_PORT=8111
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

echo "--- 1/9: initializing disposable MariaDB datadir ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1
if [ $? -ne 0 ]; then
  echo "mariadb-install-db FAILED — see $WORKDIR/install.log"; exit 1
fi

echo "--- 2/9: starting disposable MariaDB (--skip-networking) ---"
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

echo "--- 3/9: creating the two real, distinctly-privileged DB users ---"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'um_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'um_migration_user'@'localhost';
CREATE USER 'um_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'um_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 4/9: bootstrap config.php using the MIGRATION user (needs DDL) ---"
cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME',
    'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'um_migration_user',
    'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/9: migrate (0001-0007, ALL phases — NO new migration in this module) + seed + create ADMIN ---"
php "$API_ROOT/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" um_staging_admin "UM Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

echo "--- 6/9: bootstrap realistic Phase 1 master data (8 divisions + 472 products + BAKERY CIKOLE + P2 TEST STORE A/B) ---"
php "$API_ROOT/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }

echo "--- 7/9: rewriting config.php to the REALISTIC post-deployment shape (DB_USER=runtime, MIGRATION_DB_*=migration) ---"
cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME',
    'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'um_runtime_user',
    'DB_PASS' => '$RUNTIME_USER_PASS',
    'MIGRATION_DB_HOST' => 'unused-socket-mode',
    'MIGRATION_DB_SOCKET' => '$SOCK',
    'MIGRATION_DB_NAME' => '$DB_NAME',
    'MIGRATION_DB_USER' => 'um_migration_user',
    'MIGRATION_DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 8/9: starting php -S dev server on :$PHP_PORT (with the shared _ui_router.php) and running PhaseUserMgmtTest.php (UM-01..16) ---"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT" "$API_ROOT/tests/_ui_router.php" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="um_staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
TEST_DB_SOCKET="$SOCK" \
TEST_DB_NAME="$DB_NAME" \
php "$API_ROOT/tests/PhaseUserMgmtTest.php"
UM_RESULT=$?

kill "$PHP_PID" 2>/dev/null || true
PHP_PID=""

echo "--- 9/9: UM-17 — full existing Phase 0-5.5 + UI + Print + Invoice regression ---"
REGRESSION_RESULT=0
echo "  -> run-phase55-dispatch-receipt.sh (covers P55-01..MF02 plus the full Phase 0-5 + UI + Print + Invoice regression)"
bash "$API_ROOT/tests/run-phase55-dispatch-receipt.sh" > "$WORKDIR/run-phase55-dispatch-receipt.sh.log" 2>&1
RC=$?
if [ $RC -ne 0 ]; then
  echo "     FAILED — last 60 lines of run-phase55-dispatch-receipt.sh's own output:"
  tail -60 "$WORKDIR/run-phase55-dispatch-receipt.sh.log"
  REGRESSION_RESULT=1
else
  tail -6 "$WORKDIR/run-phase55-dispatch-receipt.sh.log"
fi

echo ""
if [ $UM_RESULT -eq 0 ]; then echo "UM-01..UM-16: PASSED"; else echo "UM-01..UM-16: FAILED"; fi
if [ $REGRESSION_RESULT -eq 0 ]; then echo "UM-17 (full Phase 0-5.5 + UI + Print + Invoice regression): PASSED"; else echo "UM-17 (full Phase 0-5.5 + UI + Print + Invoice regression): FAILED"; fi

if [ $UM_RESULT -eq 0 ] && [ $REGRESSION_RESULT -eq 0 ]; then
  exit 0
else
  exit 1
fi
