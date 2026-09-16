#!/usr/bin/env bash
# Phase 1 fast-track integration test orchestrator.
#
# Spins up a DISPOSABLE, local-only MariaDB instance (never the real
# u7566812_factory host), applies migrations 0001+0002, seeds, creates an
# admin and a second limited-privilege runtime DB user (SELECT/INSERT/
# UPDATE/DELETE only, no DDL), starts `php -S`, runs Phase1Test.php's
# P1-01..P1-20 suite, then tears down EVERYTHING regardless of pass/fail.
#
# Usage: bash api/tests/run-phase1.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_phase1_test"
TEST_ADMIN_PASS="StagingAdmin#$(date +%s)Test"
RUNTIME_USER_PASS="RuntimeUserPass123"
PHP_PORT=8095
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

echo "--- 1/7: initializing disposable MariaDB datadir ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1
if [ $? -ne 0 ]; then
  echo "mariadb-install-db FAILED — see $WORKDIR/install.log"; exit 1
fi

echo "--- 2/7: starting disposable MariaDB (--skip-networking, throwaway datadir) ---"
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
DB_VERSION=$(mariadb --socket="$SOCK" -u root -N -e "SELECT VERSION();")
echo "Disposable local test DB version: $DB_VERSION (not the real u7566812_factory host — see OD-4)"

echo "--- 3/7: writing throwaway api/app/config/config.php for this test run ---"
cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
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
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 4/7: migrate (0001+0002) + seed + create admin ---"
php "$API_ROOT/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" staging_admin "Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

echo "--- 5/7: creating limited-privilege runtime DB user (for P1-19/P1-20) ---"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'p1_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'p1_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 6/7: starting php -S dev server on :$PHP_PORT ---"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

echo "--- 7/7: running Phase1Test.php (P1-01..P1-20) ---"
TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
TEST_DB_SOCKET="$SOCK" \
TEST_DB_NAME="$DB_NAME" \
php "$API_ROOT/tests/Phase1Test.php"
RESULT=$?

exit $RESULT
