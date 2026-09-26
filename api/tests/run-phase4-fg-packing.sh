#!/usr/bin/env bash
# Phase 4 fast-track FG/Packing module integration test orchestrator
# (P4-01..P4-32).
#
# Spins up a DISPOSABLE, local-only MariaDB instance (never the real
# u7566812_factory host) with TWO distinctly-privileged real DB users,
# bootstraps a REALISTIC post-Phase-3 state (migrations 0001-0004 applied,
# seeded, ADMIN created, 8 divisions + all 472 katalog products + the
# BAKERY CIKOLE alias group imported via Phase1Importer — exactly what a
# real deployment looks like right after Phase 3 review, before this
# Phase 4 patch's migration 0005 has been touched), then runs
# Phase4FgPackingTest.php (P4-01..P4-32) against a live `php -S` server.
#
# Usage: bash api/tests/run-phase4-fg-packing.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_phase4fg_test"
TEST_ADMIN_PASS="StagingAdmin#$(date +%s)P4"
MIGRATION_USER_PASS="MigrationUserPassP4_123"
RUNTIME_USER_PASS="RuntimeUserPassP4_123"
PHP_PORT=8100
PHP_PID=""
MIGRATION_0005="$API_ROOT/app/migrations/0005_fg_packing_phase4.php"
MIGRATION_0005_PARKED="$WORKDIR/0005_fg_packing_phase4.php.parked"
# Migration 0014 (Production Division + FG rework) ALTERs fg_item with
# "AFTER packed_qty" — a real, direct dependency on migration 0005's own
# column. It must be parked/restored in lockstep with 0005 here, otherwise
# the MigrationRunner's plain filename glob+sort would apply 0014 in the
# SAME batch as 0001-0004 (while 0005 is still deliberately parked below),
# failing with "Unknown column 'packed_qty'". Harmless if migration 0014
# does not exist in this checkout (e.g. testing an older revision).
MIGRATION_0014="$API_ROOT/app/migrations/0014_production_fg_division_rework.php"
MIGRATION_0014_PARKED="$WORKDIR/0014_production_fg_division_rework.php.parked"

cleanup() {
  echo "--- tearing down (disposable, local-only — nothing persistent touched) ---"
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then
    kill "$PHP_PID" 2>/dev/null || true
  fi
  if [ -S "$SOCK" ]; then
    mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true
    sleep 1
  fi
  if [ -f "$MIGRATION_0005_PARKED" ] && [ ! -f "$MIGRATION_0005" ]; then
    mv "$MIGRATION_0005_PARKED" "$MIGRATION_0005"
  fi
  if [ -f "$MIGRATION_0014_PARKED" ] && [ ! -f "$MIGRATION_0014" ]; then
    mv "$MIGRATION_0014_PARKED" "$MIGRATION_0014"
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
CREATE USER 'p4_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'p4_migration_user'@'localhost';
CREATE USER 'p4_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'p4_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 4/10: parking migrations 0005 and 0014 so bootstrap only applies 0001-0004 (0005 must be genuinely PENDING; 0014 depends on 0005's own column so it must stay parked alongside it) ---"
mv "$MIGRATION_0005" "$MIGRATION_0005_PARKED"
[ -f "$MIGRATION_0014" ] && mv "$MIGRATION_0014" "$MIGRATION_0014_PARKED"

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
    'DB_USER' => 'p4_migration_user',
    'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 6/10: migrate (0001-0004) + seed + create ADMIN ---"
php "$API_ROOT/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" p4_staging_admin "P4 Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

echo "--- 7/10: bootstrap realistic Phase 1 master data (8 divisions + 472 products + BAKERY CIKOLE) ---"
php "$API_ROOT/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }

echo "--- 8/10: restoring migrations 0005 and 0014 (now genuinely pending, applied together via the _upgrade/ wizard in P4-00) ---"
mv "$MIGRATION_0005_PARKED" "$MIGRATION_0005"
[ -f "$MIGRATION_0014_PARKED" ] && mv "$MIGRATION_0014_PARKED" "$MIGRATION_0014"

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
    'DB_USER' => 'p4_runtime_user',
    'DB_PASS' => '$RUNTIME_USER_PASS',
    'MIGRATION_DB_HOST' => 'unused-socket-mode',
    'MIGRATION_DB_SOCKET' => '$SOCK',
    'MIGRATION_DB_NAME' => '$DB_NAME',
    'MIGRATION_DB_USER' => 'p4_migration_user',
    'MIGRATION_DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 10/10: starting php -S dev server on :$PHP_PORT and running Phase4FgPackingTest.php (P4-01..P4-32) ---"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="p4_staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
TEST_DB_SOCKET="$SOCK" \
TEST_DB_NAME="$DB_NAME" \
TEST_RUNTIME_USER="p4_runtime_user" \
TEST_RUNTIME_PASS="$RUNTIME_USER_PASS" \
php "$API_ROOT/tests/Phase4FgPackingTest.php"
RESULT=$?

exit $RESULT
