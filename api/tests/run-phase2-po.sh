#!/usr/bin/env bash
# Phase 2 fast-track PO module integration test orchestrator (P2-01..P2-20).
#
# Spins up a DISPOSABLE, local-only MariaDB instance (never the real
# u7566812_factory host) with TWO distinctly-privileged real DB users (a
# DML-only "runtime" user — what the live app's DB_USER actually is — and a
# DDL-capable "migration" user for MIGRATION_DB_*), bootstraps a REALISTIC
# post-Phase-1 state (migrations 0001+0002 applied, seeded, ADMIN created,
# 8 divisions + all 472 katalog products + the BAKERY CIKOLE alias group
# imported via Phase1Importer — exactly what a real deployment looks like
# right after Phase 1 review, before this Phase 2 patch's migration 0003
# has been touched), then runs Phase2POTest.php (P2-01..P2-20) against a
# live `php -S` server.
#
# Usage: bash api/tests/run-phase2-po.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_phase2po_test"
TEST_ADMIN_PASS="StagingAdmin#$(date +%s)P2"
MIGRATION_USER_PASS="MigrationUserPassP2_123"
RUNTIME_USER_PASS="RuntimeUserPassP2_123"
PHP_PORT=8097
PHP_PID=""
MIGRATION_0003="$API_ROOT/app/migrations/0003_po_phase2.php"
MIGRATION_0003_PARKED="$WORKDIR/0003_po_phase2.php.parked"

cleanup() {
  echo "--- tearing down (disposable, local-only — nothing persistent touched) ---"
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then
    kill "$PHP_PID" 2>/dev/null || true
  fi
  if [ -S "$SOCK" ]; then
    mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true
    sleep 1
  fi
  if [ -f "$MIGRATION_0003_PARKED" ] && [ ! -f "$MIGRATION_0003" ]; then
    mv "$MIGRATION_0003_PARKED" "$MIGRATION_0003"
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
CREATE USER 'p2_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'p2_migration_user'@'localhost';
CREATE USER 'p2_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'p2_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 4/10: parking migration 0003 so bootstrap only applies 0001+0002 (0003 must be genuinely PENDING) ---"
mv "$MIGRATION_0003" "$MIGRATION_0003_PARKED"

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
    'DB_USER' => 'p2_migration_user',
    'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 6/10: migrate (0001+0002) + seed + create ADMIN ---"
php "$API_ROOT/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" p2_staging_admin "P2 Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

echo "--- 7/10: bootstrap realistic Phase 1 master data (8 divisions + 472 products + BAKERY CIKOLE) + test store fixtures ---"
php "$API_ROOT/tests/_phase2_bootstrap_master.php" || { echo "Phase 1 master bootstrap FAILED"; exit 1; }

echo "--- 8/10: restoring migration 0003 (now genuinely pending) ---"
mv "$MIGRATION_0003_PARKED" "$MIGRATION_0003"

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
    'DB_USER' => 'p2_runtime_user',
    'DB_PASS' => '$RUNTIME_USER_PASS',
    'MIGRATION_DB_HOST' => 'unused-socket-mode',
    'MIGRATION_DB_SOCKET' => '$SOCK',
    'MIGRATION_DB_NAME' => '$DB_NAME',
    'MIGRATION_DB_USER' => 'p2_migration_user',
    'MIGRATION_DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 10/10: starting php -S dev server on :$PHP_PORT and running Phase2POTest.php (P2-01..P2-20) ---"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="p2_staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
TEST_DB_SOCKET="$SOCK" \
TEST_DB_NAME="$DB_NAME" \
TEST_RUNTIME_USER="p2_runtime_user" \
TEST_RUNTIME_PASS="$RUNTIME_USER_PASS" \
php "$API_ROOT/tests/Phase2POTest.php"
RESULT=$?

exit $RESULT
