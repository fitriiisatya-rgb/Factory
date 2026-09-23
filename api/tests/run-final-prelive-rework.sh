#!/usr/bin/env bash
# Final Pre-Live Rework of migration 0012 — Special/Non-Regular Shipment ->
# Driver History -> Digital Surat Jalan -> Email -> Bakery Receipt
# (FINAL-01..40, see FinalPreliveReworkTest.php's own docblock), followed by
# the FULL existing Phase 0-5.5 + Special Order + Production Flow Completion
# regression suite — this rework must never regress any prior phase.
#
# Spins up a DISPOSABLE, local-only MariaDB instance with TWO distinctly-
# privileged real DB users PLUS a fake-SMTP transport (same
# FakeMailTransport convention as run-phase55-dispatch-receipt.sh's own
# MAIL-* tests — appends one JSON line per "sent" email instead of opening
# a real network connection), applies ALL migrations 0001-0012 for real,
# bootstraps realistic master data (8 divisions + 472 katalog products +
# P2 TEST STORE A/B), then runs FinalPreliveReworkTest.php against a live
# `php -S` server, and finally re-runs run-production-task.sh (which itself
# cascades through every prior phase's own orchestrator, including the
# Phase 5.5 MAIL-*/receipt regression).
#
# Usage: bash api/tests/run-final-prelive-rework.sh
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_final_test"
TEST_ADMIN_PASS="StagingAdmin#$(date +%s)FINAL"
MIGRATION_USER_PASS="MigrationUserPassFINAL_123"
RUNTIME_USER_PASS="RuntimeUserPassFINAL_123"
PHP_PORT=8115
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
CREATE USER 'final_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'final_migration_user'@'localhost';
CREATE USER 'final_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'final_runtime_user'@'localhost';
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
    'DB_USER' => 'final_migration_user',
    'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/9: migrate (0001-0012, ALL phases) + seed + create ADMIN ---"
php "$API_ROOT/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$API_ROOT/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$TEST_ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" final_staging_admin "Final Staging Admin" || { echo "create_admin.php FAILED"; exit 1; }

echo "--- 6/9: bootstrap realistic Phase 1 master data (8 divisions + 472 products + P2 TEST STORE A/B) ---"
php "$API_ROOT/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }

echo "--- 7/9: rewriting config.php to the REALISTIC post-deployment shape (DB_USER=runtime) + fake-SMTP transport ---"
cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging',
    'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME',
    'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'final_runtime_user',
    'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
    // TEST-ONLY: swaps SmtpMailTransport for FakeMailTransport (appends one
    // JSON line per "sent" message to this file instead of opening a real
    // network connection) — same convention as run-phase55-dispatch-
    // receipt.sh's own MAIL-* tests.
    'APP_BASE_URL' => 'http://127.0.0.1:$PHP_PORT',
    'MAIL_ENABLED' => true,
    'MAIL_TRANSPORT' => 'fake',
    'MAIL_FAKE_LOG_PATH' => '$WORKDIR/mail-fake.jsonl',
    'MAIL_FROM_ADDRESS' => 'factory@amorgroup.id',
    'MAIL_FROM_NAME' => 'Amor Factory System',
];
PHPCONFIG

echo "--- 8/9: starting php -S dev server on :$PHP_PORT (with the shared _ui_router.php) and running FinalPreliveReworkTest.php ---"
php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT" "$API_ROOT/tests/_ui_router.php" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
if ! kill -0 "$PHP_PID" 2>/dev/null; then
  echo "php -S failed to start — see $WORKDIR/php-server.log"; cat "$WORKDIR/php-server.log"; exit 1
fi

TEST_BASE_URL="http://127.0.0.1:$PHP_PORT" \
TEST_ADMIN_USER="final_staging_admin" \
TEST_ADMIN_PASS="$TEST_ADMIN_PASS" \
TEST_DB_SOCKET="$SOCK" \
TEST_DB_NAME="$DB_NAME" \
TEST_RUNTIME_USER="final_runtime_user" \
TEST_RUNTIME_PASS="$RUNTIME_USER_PASS" \
TEST_MAIL_FAKE_LOG_PATH="$WORKDIR/mail-fake.jsonl" \
php "$API_ROOT/tests/FinalPreliveReworkTest.php"
FINAL_RESULT=$?

kill "$PHP_PID" 2>/dev/null || true
PHP_PID=""

echo "--- 9/9: full existing Phase 0-5.5 + Special Order + Production Flow Completion regression ---"
# run-production-task.sh's own final step already re-runs run-special-
# order.sh, which re-runs run-phase55-dispatch-receipt.sh, which cascades
# through every prior phase's orchestrator — so calling it alone covers the
# full regression chain (including run-production-flow-completion.sh's own
# FLOW-01..47).
REGRESSION_RESULT=0
echo "  -> run-production-flow-completion.sh (covers FLOW-01..47 plus the full prior-phase regression)"
bash "$API_ROOT/tests/run-production-flow-completion.sh" > "$WORKDIR/run-production-flow-completion.sh.log" 2>&1
RC=$?
if [ $RC -ne 0 ]; then
  echo "     FAILED — last 40 lines of run-production-flow-completion.sh's own output:"
  tail -40 "$WORKDIR/run-production-flow-completion.sh.log"
  REGRESSION_RESULT=1
else
  tail -5 "$WORKDIR/run-production-flow-completion.sh.log"
fi

echo ""
if [ $FINAL_RESULT -eq 0 ]; then echo "FINAL-01..40 (Special/Non-Regular Shipment -> Driver History -> Digital SJ -> Email -> Bakery Receipt): PASSED"; else echo "FINAL-01..40: FAILED"; fi
if [ $REGRESSION_RESULT -eq 0 ]; then echo "Full Phase 0-5.5 + Special Order + Production Flow Completion regression: PASSED"; else echo "Full Phase 0-5.5 + Special Order + Production Flow Completion regression: FAILED"; fi

if [ $FINAL_RESULT -eq 0 ] && [ $REGRESSION_RESULT -eq 0 ]; then
  exit 0
else
  exit 1
fi
