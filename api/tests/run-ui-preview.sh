#!/usr/bin/env bash
# UI/UX redesign integration test orchestrator (UI-01..UI-19), followed by
# the FULL existing Phase 0-5 regression suite (UI-20) — this redesign
# must never regress any prior phase's own test suite.
#
# Spins up a DISPOSABLE, local-only MariaDB instance (never the real
# u7566812_factory host) with TWO distinctly-privileged real DB users,
# applies ALL migrations 0001-0006 for real (the redesigned UI spans every
# phase), bootstraps realistic master data (8 divisions + 472 katalog
# products + BAKERY CIKOLE + P2 TEST STORE A/B), then runs
# PhaseUiTest.php against a live `php -S` server, and finally re-runs
# every prior phase's own orchestrator script in sequence.
#
# Usage: bash api/tests/run-ui-preview.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_ui_test"
TEST_ADMIN_PASS="StagingAdmin#$(date +%s)UI"
MIGRATION_USER_PASS="MigrationUserPassUI_123"
RUNTIME_USER_PASS="RuntimeUserPassUI_123"
PHP_PORT=8104
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
CREATE USER 'ui_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'ui_migration_user'@'localhost';
CREATE USER 'ui_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'ui_runtime_user'@'localhost';
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
    'DB_USER' => 'ui_migration_user',
    'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/9: migrate (0001-0006, ALL phases — the UI spans every one) + seed + create ADMIN ---"
php "$API_ROOT/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" ui_staging_admin "UI Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

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
    'DB_USER' => 'ui_runtime_user',
    'DB_PASS' => '$RUNTIME_USER_PASS',
    'MIGRATION_DB_HOST' => 'unused-socket-mode',
    'MIGRATION_DB_SOCKET' => '$SOCK',
    'MIGRATION_DB_NAME' => '$DB_NAME',
    'MIGRATION_DB_USER' => 'ui_migration_user',
    'MIGRATION_DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 8/9: starting php -S dev server on :$PHP_PORT (same docroot convention as every other orchestrator, plus a tiny router script that only serves this phase's /api/app/ui/assets/* static CSS/JS — see _ui_router.php) and running PhaseUiTest.php (UI-01..UI-19) ---"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT" "$API_ROOT/tests/_ui_router.php" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="ui_staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
TEST_DB_SOCKET="$SOCK" \
TEST_DB_NAME="$DB_NAME" \
TEST_RUNTIME_USER="ui_runtime_user" \
TEST_RUNTIME_PASS="$RUNTIME_USER_PASS" \
php "$API_ROOT/tests/PhaseUiTest.php"
UI_RESULT=$?

kill "$PHP_PID" 2>/dev/null || true
PHP_PID=""

echo "--- 9/9: UI-20 — full existing Phase 0-5 regression (each phase's own disposable DB, untouched by the above) ---"
REGRESSION_RESULT=0
for script in run.sh run-phase1.sh run-phase1-v2.sh run-phase2-po.sh run-phase3-production.sh run-phase4-fg-packing.sh run-phase5-do-shipment.sh; do
  echo "  -> $script"
  bash "$API_ROOT/tests/$script" > "$WORKDIR/${script}.log" 2>&1
  RC=$?
  if [ $RC -ne 0 ]; then
    echo "     FAILED — last 30 lines of $script's own output:"
    tail -30 "$WORKDIR/${script}.log"
    REGRESSION_RESULT=1
  else
    tail -3 "$WORKDIR/${script}.log"
  fi
done

echo ""
if [ $UI_RESULT -eq 0 ]; then echo "UI-01..UI-19: PASSED"; else echo "UI-01..UI-19: FAILED"; fi
if [ $REGRESSION_RESULT -eq 0 ]; then echo "UI-20 (full Phase 0-5 regression): PASSED"; else echo "UI-20 (full Phase 0-5 regression): FAILED"; fi

if [ $UI_RESULT -eq 0 ] && [ $REGRESSION_RESULT -eq 0 ]; then
  exit 0
else
  exit 1
fi
