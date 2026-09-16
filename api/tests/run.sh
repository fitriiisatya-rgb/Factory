#!/usr/bin/env bash
# Phase 0 integration test orchestrator.
#
# Spins up a DISPOSABLE, local-only MariaDB instance (never a live/persistent
# database), applies database/schema-v1.sql, seeds minimum master data,
# creates a throwaway staging admin, starts `php -S` against api/public,
# runs Phase0Test.php's P0-01..P0-17 suite against it, then tears down
# EVERYTHING (php server, mariadb, datadir) regardless of pass/fail.
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

echo "--- 1/6: initializing disposable MariaDB datadir ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1
if [ $? -ne 0 ]; then
  echo "mariadb-install-db FAILED — see $WORKDIR/install.log"; exit 1
fi

echo "--- 2/6: starting disposable MariaDB (--skip-networking, throwaway datadir) ---"
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
echo "Disposable test DB version: $DB_VERSION (NOT the real factory.amorgroup.id cPanel host — see OD-4)"

echo "--- 3/6: writing throwaway api/config/config.php for this test run ---"
cat > "$API_ROOT/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'SESSION_SECURE' => false, // plain-HTTP php -S dev server for this local test run only
];
PHPCONFIG

echo "--- 4/6: migrate + seed + create throwaway admin ---"
php "$API_ROOT/bin/migrate.php" || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" staging_admin "Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

echo "--- 5/6: starting php -S dev server on :$PHP_PORT ---"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT/public" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

echo "--- 6/6: running Phase0Test.php ---"
TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
php "$API_ROOT/tests/Phase0Test.php"
RESULT=$?

rm -f "$API_ROOT/config/config.php"

exit $RESULT
