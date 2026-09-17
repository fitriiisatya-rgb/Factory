#!/usr/bin/env bash
# Phase 5 fast-track Draft DO / staged Shipment module integration test
# orchestrator (P5-01..P5-36).
#
# Spins up a DISPOSABLE, local-only MariaDB instance (never the real
# u7566812_factory host) with TWO distinctly-privileged real DB users,
# bootstraps a REALISTIC post-Phase-4 state (migrations 0001-0005 applied,
# seeded, ADMIN created, 8 divisions + all 472 katalog products + the
# BAKERY CIKOLE alias group + the P2 TEST STORE A/B fixtures imported via
# Phase1Importer/_phase2_bootstrap_master.php — exactly what a real
# deployment looks like right after Phase 4 review, before this Phase 5
# patch's migration 0006 has been touched), then runs
# Phase5DoShipmentTest.php (P5-01..P5-36) against a live `php -S` server.
#
# Usage: bash api/tests/run-phase5-do-shipment.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_phase5do_test"
TEST_ADMIN_PASS="StagingAdmin#$(date +%s)P5"
MIGRATION_USER_PASS="MigrationUserPassP5_123"
RUNTIME_USER_PASS="RuntimeUserPassP5_123"
PHP_PORT=8102
PHP_PID=""
MIGRATION_0006="$API_ROOT/app/migrations/0006_do_shipment_phase5.php"
MIGRATION_0006_PARKED="$WORKDIR/0006_do_shipment_phase5.php.parked"

cleanup() {
  echo "--- tearing down (disposable, local-only — nothing persistent touched) ---"
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then
    kill "$PHP_PID" 2>/dev/null || true
  fi
  if [ -S "$SOCK" ]; then
    mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true
    sleep 1
  fi
  if [ -f "$MIGRATION_0006_PARKED" ] && [ ! -f "$MIGRATION_0006" ]; then
    mv "$MIGRATION_0006_PARKED" "$MIGRATION_0006"
  fi
  rm -f "$API_ROOT/app/config/config.php"
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

echo "--- 1/10: initializing disposable MariaDB datadir ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1
if [ $? -ne 0 ]; then
  echo "mariadb-install-db FAILED — see $WORKDIR/install.log"; exit 1
fi

echo "--- 2/10: starting disposable MariaDB (--skip-networking) ---"
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
echo "Disposable local test DB version: $DB_VERSION (not the real u7566812_factory host)"

echo "--- 3/10: creating the two real, distinctly-privileged DB users ---"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'p5_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'p5_migration_user'@'localhost';
CREATE USER 'p5_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'p5_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 4/10: parking migration 0006 so bootstrap only applies 0001-0005 (0006 must be genuinely PENDING) ---"
mv "$MIGRATION_0006" "$MIGRATION_0006_PARKED"

echo "--- 5/10: bootstrap config.php using the MIGRATION user (needs DDL) ---"
cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME',
    'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'p5_migration_user',
    'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 6/10: migrate (0001-0005) + seed + create ADMIN ---"
php "$API_ROOT/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" p5_staging_admin "P5 Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

echo "--- 7/10: bootstrap realistic Phase 1 master data (8 divisions + 472 products + BAKERY CIKOLE + P2 TEST STORE A/B) ---"
php "$API_ROOT/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }

echo "--- 8/10: restoring migration 0006 (now genuinely pending) ---"
mv "$MIGRATION_0006_PARKED" "$MIGRATION_0006"

echo "--- 9/10: rewriting config.php to the REALISTIC post-deployment shape (DB_USER=runtime, MIGRATION_DB_*=migration) ---"
cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME',
    'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'p5_runtime_user',
    'DB_PASS' => '$RUNTIME_USER_PASS',
    'MIGRATION_DB_HOST' => 'unused-socket-mode',
    'MIGRATION_DB_SOCKET' => '$SOCK',
    'MIGRATION_DB_NAME' => '$DB_NAME',
    'MIGRATION_DB_USER' => 'p5_migration_user',
    'MIGRATION_DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 10/10: starting php -S dev server on :$PHP_PORT and running Phase5DoShipmentTest.php (P5-01..P5-36) ---"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="p5_staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
TEST_DB_SOCKET="$SOCK" \
TEST_DB_NAME="$DB_NAME" \
TEST_RUNTIME_USER="p5_runtime_user" \
TEST_RUNTIME_PASS="$RUNTIME_USER_PASS" \
php "$API_ROOT/tests/Phase5DoShipmentTest.php"
RESULT=$?

exit $RESULT
