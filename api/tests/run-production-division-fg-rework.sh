#!/usr/bin/env bash
# Production Division + FG & Packing rework integration test orchestrator
# (PDFG-01..PDFG-24), followed by the full existing regression suite.
#
# Spins up a DISPOSABLE, local-only MariaDB instance (never the real
# u7566812_factory host), applies ALL migrations 0001-0014 for real
# (nothing parked — this module's own migration 0014 has no ordering
# dependency issue here since 0005 is never held back), bootstraps
# realistic master data, then runs ProductionDivisionFgReworkTest.php
# against a live `php -S` server, and finally re-runs the fg-allocation
# cascade (which itself cascades through every earlier phase) for the
# full regression.
#
# Usage: bash api/tests/run-production-division-fg-rework.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_pdfg_test"
TEST_ADMIN_PASS="StagingAdmin#$(date +%s)PDFG"
MIGRATION_USER_PASS="MigrationUserPassPDFG_123"
RUNTIME_USER_PASS="RuntimeUserPassPDFG_123"
PHP_PORT=8130
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
  [ -L "$API_ROOT/api" ] && rm -f "$API_ROOT/api"
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
CREATE USER 'pdfg_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'pdfg_migration_user'@'localhost';
CREATE USER 'pdfg_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'pdfg_runtime_user'@'localhost';
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
    'DB_USER' => 'pdfg_migration_user',
    'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/9: migrate (0001-0014, ALL phases) + seed + create ADMIN ---"
php "$API_ROOT/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" pdfg_staging_admin "PDFG Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

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
    'DB_USER' => 'pdfg_runtime_user',
    'DB_PASS' => '$RUNTIME_USER_PASS',
    'MIGRATION_DB_HOST' => 'unused-socket-mode',
    'MIGRATION_DB_SOCKET' => '$SOCK',
    'MIGRATION_DB_NAME' => '$DB_NAME',
    'MIGRATION_DB_USER' => 'pdfg_migration_user',
    'MIGRATION_DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 8/9: starting php -S dev server on :$PHP_PORT and running ProductionDivisionFgReworkTest.php (PDFG-01..24) ---"
# The app's own templates link static assets/pages via an ABSOLUTE
# "/api/assets/..." path (correct against the REAL cPanel docroot, one
# level above api/, where .htaccess rewrites bare "/api/xxx" JSON calls to
# api/index.php while letting a literal static file under api/assets/
# serve directly). This disposable php -S test server's own docroot IS
# api/ itself (so every existing HttpPdfg JSON call's "/api/..." path
# keeps falling through to index.php's router exactly as it always has —
# never touched here), which makes a REQUEST for "/api/assets/..." look
# for a literal "api/api/assets/..." file that doesn't exist. A
# self-referential symlink (api/api -> .) makes that lookup resolve to the
# real assets/ directory, purely a test-harness fix — the .htaccess/docroot
# split this papers over is real cPanel behavior this script cannot
# replicate under php -S, never a change to any shipped file. Removed by
# cleanup() below; never left behind for a build script to recurse into.
ln -sfn . "$API_ROOT/api"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="pdfg_staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
TEST_DB_SOCKET="$SOCK" \
TEST_DB_NAME="$DB_NAME" \
TEST_RUNTIME_USER="pdfg_runtime_user" \
TEST_RUNTIME_PASS="$RUNTIME_USER_PASS" \
php "$API_ROOT/tests/ProductionDivisionFgReworkTest.php" 2>&1 | tee "$WORKDIR/pdfg-output.log"
PDFG_RESULT=${PIPESTATUS[0]}

if [ $PDFG_RESULT -ne 0 ]; then
  kill "$PHP_PID" 2>/dev/null || true
  PHP_PID=""
  echo "ProductionDivisionFgReworkTest.php FAILED — stopping before the full regression cascade"
  exit 1
fi

echo "--- 8.5/9: real headless-browser smoke check — HOTFIX LIVE UI (FG target=0 rows + Breakdown Toko rendering, FG-UI-03/04/07/08/09/10/11/12) ---"
UI_HOTFIX_FACTORY_ID=$(grep -oP 'FG_UI_HOTFIX_FACTORY_ID=\K[0-9]+' "$WORKDIR/pdfg-output.log" | tail -1)
UI_HOTFIX_TANGGAL=$(grep -oP 'FG_UI_HOTFIX_TANGGAL=\K[0-9-]+' "$WORKDIR/pdfg-output.log" | tail -1)
if [ -z "$UI_HOTFIX_FACTORY_ID" ] || [ -z "$UI_HOTFIX_TANGGAL" ]; then
  kill "$PHP_PID" 2>/dev/null || true
  PHP_PID=""
  echo "Could not find FG_UI_HOTFIX_FACTORY_ID/FG_UI_HOTFIX_TANGGAL markers in PDFG test output — cannot run the UI smoke check"
  exit 1
fi
BASE_URL="http://127.0.0.1:$PHP_PORT" ADMIN_USER="pdfg_staging_admin" ADMIN_PASS="$TEST_ADMIN_PASS" \
TANGGAL="$UI_HOTFIX_TANGGAL" FACTORY_ID="$UI_HOTFIX_FACTORY_ID" \
node "$API_ROOT/tests/_ui_smoke_fg_breakdown_toko.mjs"
UI_SMOKE_RESULT=$?

kill "$PHP_PID" 2>/dev/null || true
PHP_PID=""

if [ $UI_SMOKE_RESULT -ne 0 ]; then
  echo "_ui_smoke_fg_breakdown_toko.mjs FAILED — stopping before the full regression cascade"
  exit 1
fi

echo "--- 9/9: full existing Phase 0-5.5 + Special Order + FG Allocation Bridge + Final Pre-Live Rework regression ---"
bash "$API_ROOT/tests/run-fg-allocation.sh"
exit $?
