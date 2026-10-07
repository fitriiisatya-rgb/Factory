#!/usr/bin/env bash
# Permanent Bakery Portal integration test orchestrator (PORTAL-01..06,
# RECEIPT-PORTAL-01..05, SPECIAL-PORTAL-01..05, RETUR-01..05, MUTASI-01..08,
# PHOTO-01..04, IDEMPOTENCY-01..04), followed by the full existing
# regression suite.
#
# Spins up a DISPOSABLE, local-only MariaDB instance (never the real
# u7566812_factory host) with TWO distinctly-privileged real DB users,
# applies ALL migrations 0001-0017 for real (0001-0016 untouched, 0017 is
# this phase's own), bootstraps realistic master data, then runs
# BakeryPortalTest.php against a live `php -S` server, and finally
# re-runs the production-division-fg-rework cascade (which itself
# cascades through every earlier phase) for the full regression.
#
# Usage: bash api/tests/run-bakery-portal.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_bp_test"
TEST_ADMIN_PASS="StagingAdmin#$(date +%s)BP"
MIGRATION_USER_PASS="MigrationUserPassBP_123"
RUNTIME_USER_PASS="RuntimeUserPassBP_123"
PHP_PORT=8133
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
CREATE USER 'bp_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'bp_migration_user'@'localhost';
CREATE USER 'bp_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'bp_runtime_user'@'localhost';
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
    'DB_USER' => 'bp_migration_user',
    'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/9: migrate (0001-0017, ALL phases) + seed + create ADMIN ---"
php "$API_ROOT/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" bp_staging_admin "BP Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

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
    'DB_USER' => 'bp_runtime_user',
    'DB_PASS' => '$RUNTIME_USER_PASS',
    'MIGRATION_DB_HOST' => 'unused-socket-mode',
    'MIGRATION_DB_SOCKET' => '$SOCK',
    'MIGRATION_DB_NAME' => '$DB_NAME',
    'MIGRATION_DB_USER' => 'bp_migration_user',
    'MIGRATION_DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
    // Automatic Bakery Email's CTA now points at the Permanent Portal
    // (Mail\ShipmentEmailService) — MAIL_TRANSPORT=fake is a TEST-ONLY key
    // (never present in config.example.php/real cPanel config): it swaps
    // the real SmtpMailTransport for FakeMailTransport, which appends one
    // JSON line per "sent" message to this file instead of opening a real
    // network connection.
    'APP_BASE_URL' => 'http://127.0.0.1:$PHP_PORT',
    'MAIL_ENABLED' => true,
    'MAIL_TRANSPORT' => 'fake',
    'MAIL_FAKE_LOG_PATH' => '$WORKDIR/mail-fake.jsonl',
    'MAIL_FROM_ADDRESS' => 'factory@amorgroup.id',
    'MAIL_FROM_NAME' => 'Amor Factory System',
];
PHPCONFIG

echo "--- 8/9: starting php -S dev server on :$PHP_PORT (with the shared _ui_router.php) and running BakeryPortalTest.php ---"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT" "$API_ROOT/tests/_ui_router.php" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="bp_staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
TEST_DB_SOCKET="$SOCK" \
TEST_DB_NAME="$DB_NAME" \
TEST_RUNTIME_USER="bp_runtime_user" \
TEST_RUNTIME_PASS="$RUNTIME_USER_PASS" \
php "$API_ROOT/tests/BakeryPortalTest.php"
BP_RESULT=$?

kill "$PHP_PID" 2>/dev/null || true
PHP_PID=""

echo "--- 9/9: full existing Phase 0-5.5 + UI + Print + Invoice + Production/FG rework regression ---"
# run-production-division-fg-rework.sh is the current top of the chain:
# it cascades through run-fg-allocation.sh -> run-final-prelive-rework.sh /
# run-production-flow-completion.sh -> run-production-task.sh ->
# run-special-order.sh -> run-invoice-ui-preview.sh -> run-print-preview.sh
# -> run-ui-preview.sh -> every Phase 1-5 orchestrator, so calling it alone
# covers the full regression without running each script twice.
REGRESSION_RESULT=0
echo "  -> run-production-division-fg-rework.sh (covers PDFG-01..24 plus the full prior regression cascade)"
bash "$API_ROOT/tests/run-production-division-fg-rework.sh" > "$WORKDIR/run-production-division-fg-rework.sh.log" 2>&1
RC=$?
if [ $RC -ne 0 ]; then
  echo "     FAILED — last 40 lines of run-production-division-fg-rework.sh's own output:"
  tail -40 "$WORKDIR/run-production-division-fg-rework.sh.log"
  REGRESSION_RESULT=1
else
  tail -5 "$WORKDIR/run-production-division-fg-rework.sh.log"
fi

echo ""
if [ $BP_RESULT -eq 0 ]; then echo "Bakery Portal suite (PORTAL/RECEIPT-PORTAL/SPECIAL-PORTAL/RETUR/MUTASI/PHOTO/IDEMPOTENCY): PASSED"; else echo "Bakery Portal suite (PORTAL/RECEIPT-PORTAL/SPECIAL-PORTAL/RETUR/MUTASI/PHOTO/IDEMPOTENCY): FAILED"; fi
if [ $REGRESSION_RESULT -eq 0 ]; then echo "Full prior regression (Phase 0-5.5 + UI + Print + Invoice + Production/FG rework): PASSED"; else echo "Full prior regression (Phase 0-5.5 + UI + Print + Invoice + Production/FG rework): FAILED"; fi

if [ $BP_RESULT -eq 0 ] && [ $REGRESSION_RESULT -eq 0 ]; then
  exit 0
else
  exit 1
fi
