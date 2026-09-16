#!/usr/bin/env bash
# Phase 1 EASY V2 patch integration test orchestrator (V2-01..V2-18).
#
# Spins up a DISPOSABLE, local-only MariaDB instance (never the real
# u7566812_factory host) and reproduces a REALISTIC post-deployment state:
# migration 0001 installed, baseline seed installed, an ADMIN account
# already created — exactly what a real cPanel deployment looks like right
# after Phase 0.5, before this V2 patch's migration 0002 / import wizard
# have been touched.
#
# Two distinctly-privileged real MySQL users are created and BOTH are used
# for real, by the actual running app (not just probed with a side PDO):
#   - a RUNTIME user (SELECT/INSERT/UPDATE/DELETE only) — this becomes
#     DB_USER/DB_PASS, i.e. what Database::pdo() uses for EVERYTHING except
#     the upgrade wizard, proving the whole app (including _import-master/)
#     works on a DML-only identity.
#   - a MIGRATION user (ALL PRIVILEGES / DDL-capable) — this becomes
#     MIGRATION_DB_*, used ONLY by api/_upgrade/ via Database::migrationPdo().
#
# Migration 0002 is deliberately withheld during bootstrap (its file is
# moved aside) so it shows up as genuinely PENDING once the php -S server
# starts — the V2 suite then applies it for real through the web wizard,
# not via bin/migrate.php.
#
# Usage: bash api/tests/run-phase1-v2.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_phase1v2_test"
TEST_ADMIN_PASS="StagingAdmin#$(date +%s)V2"
MIGRATION_USER_PASS="MigrationUserPassV2_123"
RUNTIME_USER_PASS="RuntimeUserPassV2_123"
PHP_PORT=8096
PHP_PID=""
MIGRATION_0002="$API_ROOT/app/migrations/0002_master_identity.php"
MIGRATION_0002_PARKED="$WORKDIR/0002_master_identity.php.parked"

cleanup() {
  echo "--- tearing down (disposable, local-only — nothing persistent touched) ---"
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then
    kill "$PHP_PID" 2>/dev/null || true
  fi
  if [ -S "$SOCK" ]; then
    mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true
    sleep 1
  fi
  # Restore 0002 if this script died mid-way while it was parked.
  if [ -f "$MIGRATION_0002_PARKED" ] && [ ! -f "$MIGRATION_0002" ]; then
    mv "$MIGRATION_0002_PARKED" "$MIGRATION_0002"
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

echo "--- 2/9: starting disposable MariaDB (--skip-networking, throwaway datadir) ---"
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

echo "--- 3/9: creating the two real, distinctly-privileged DB users (mirrors u7566812_factoryapp / u7566812_adminfactory) ---"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'v2_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'v2_migration_user'@'localhost';
CREATE USER 'v2_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'v2_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 4/9: parking migration 0002 so bootstrap only applies 0001 (0002 must be genuinely PENDING for V2-09) ---"
mv "$MIGRATION_0002" "$MIGRATION_0002_PARKED"

echo "--- 5/9: bootstrap config.php using the MIGRATION user (needs DDL for 0001's CREATE TABLEs) ---"
cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME',
    'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'v2_migration_user',
    'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 6/9: migrate (0001 only) + seed + create ADMIN + PPIC test users ---"
php "$API_ROOT/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" v2_staging_admin "V2 Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

echo "--- 7/9: restoring migration 0002 (now genuinely pending — schema_migrations has no record of it) ---"
mv "$MIGRATION_0002_PARKED" "$MIGRATION_0002"

echo "--- 8/9: rewriting config.php to the REALISTIC post-deployment shape (DB_USER=runtime, MIGRATION_DB_*=migration) ---"
cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME',
    'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'v2_runtime_user',
    'DB_PASS' => '$RUNTIME_USER_PASS',
    'MIGRATION_DB_HOST' => 'unused-socket-mode',
    'MIGRATION_DB_SOCKET' => '$SOCK',
    'MIGRATION_DB_NAME' => '$DB_NAME',
    'MIGRATION_DB_USER' => 'v2_migration_user',
    'MIGRATION_DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 9/9: starting php -S dev server on :$PHP_PORT and running Phase1TestV2.php (V2-01..V2-18) ---"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="v2_staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
TEST_DB_SOCKET="$SOCK" \
TEST_DB_NAME="$DB_NAME" \
TEST_RUNTIME_USER="v2_runtime_user" \
TEST_RUNTIME_PASS="$RUNTIME_USER_PASS" \
TEST_MIGRATION_USER="v2_migration_user" \
TEST_MIGRATION_PASS="$MIGRATION_USER_PASS" \
php "$API_ROOT/tests/Phase1TestV2.php"
RESULT=$?

exit $RESULT
