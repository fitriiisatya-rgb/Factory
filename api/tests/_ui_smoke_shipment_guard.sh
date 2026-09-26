#!/usr/bin/env bash
# One-off smoke check (NOT part of the regular suite): boots a disposable
# MariaDB + php -S env, explodes one product into per-store FG rows,
# submits, creates a Regular DO for Store A, then curls the actual
# pengiriman.php (Kirim) UI page through api/_ui-preview/index.php with a
# real admin session cookie and greps for PHP fatal errors plus the new
# "Ready Toko Ini" store-ready column and its correct value. Deleted
# after use.
set -uo pipefail

API_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="$(mktemp -d)"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_uismoke_ship_test"
ADMIN_PASS="UiSmokeShipAdmin#$(date +%s)"
MIGRATION_USER_PASS="UiSmokeShipMigPass_123"
RUNTIME_USER_PASS="UiSmokeShipRunPass_123"
PHP_PORT=8132
PHP_PID=""

cleanup() {
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then kill "$PHP_PID" 2>/dev/null || true; fi
  if [ -S "$SOCK" ]; then mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true; sleep 1; fi
  rm -f "$API_ROOT/app/config/config.php"
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not start"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'uismokeship_migration'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'uismokeship_migration'@'localhost';
CREATE USER 'uismokeship_runtime'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'uismokeship_runtime'@'localhost';
FLUSH PRIVILEGES;"

cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true, 'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK', 'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'uismokeship_migration', 'DB_PASS' => '$MIGRATION_USER_PASS', 'SESSION_SECURE' => false,
];
PHPCONFIG

php "$API_ROOT/bin/migrate.php" --yes || exit 1
php "$API_ROOT/bin/seed.php" || exit 1
ADMIN_PASSWORD="$ADMIN_PASS" php "$API_ROOT/bin/create_admin.php" uismokeship_admin "UI Smoke Ship Admin" || exit 1
php "$API_ROOT/tests/_phase2_bootstrap_master.php" || exit 1

cat > "$API_ROOT/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true, 'DB_HOST' => 'unused-socket-mode',
    'DB_SOCKET' => '$SOCK', 'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'uismokeship_runtime', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'MIGRATION_DB_HOST' => 'unused-socket-mode', 'MIGRATION_DB_SOCKET' => '$SOCK', 'MIGRATION_DB_NAME' => '$DB_NAME',
    'MIGRATION_DB_USER' => 'uismokeship_migration', 'MIGRATION_DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

php -S "127.0.0.1:$PHP_PORT" -t "$API_ROOT" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
kill -0 "$PHP_PID" 2>/dev/null || { echo "php -S failed"; cat "$WORKDIR/php-server.log"; exit 1; }

COOKIES="$WORKDIR/cookies.txt"
BASE="http://127.0.0.1:$PHP_PORT"

CSRF=$(UISMOKE_BASE_URL="$BASE" UISMOKE_ADMIN_USER="uismokeship_admin" UISMOKE_ADMIN_PASS="$ADMIN_PASS" \
  UISMOKE_DB_SOCKET="$SOCK" UISMOKE_DB_NAME="$DB_NAME" UISMOKE_DB_USER="uismokeship_runtime" UISMOKE_DB_PASS="$RUNTIME_USER_PASS" \
  UISMOKE_COOKIE_JAR="$COOKIES" php "$API_ROOT/tests/_ui_smoke_shipment_guard_fixture.php")
if [ -z "$CSRF" ]; then echo "fixture setup FAILED"; cat "$WORKDIR/php-server.log"; exit 1; fi
CSRF_TOKEN=$(echo "$CSRF" | cut -d'|' -f1)
DO_ID=$(echo "$CSRF" | cut -d'|' -f2)

echo "--- fetching pengiriman.php (Kirim) UI page for DO #$DO_ID (Store A ready should show 9) ---"
HTML=$(curl -s -b "$COOKIES" -c "$COOKIES" "$BASE/_ui-preview/index.php?page=pengiriman&doId=$DO_ID")

FAIL=0
check() {
  if echo "$HTML" | grep -qF "$1"; then echo "OK   found: $1"; else echo "MISS not found: $1"; FAIL=1; fi
}
checkAbsent() {
  if echo "$HTML" | grep -qi "$1"; then echo "FAIL PHP error marker present: $1"; FAIL=1; else echo "OK   no '$1' in output"; fi
}

checkAbsent "Fatal error"
checkAbsent "<b>Warning</b>"
checkAbsent "<b>Notice</b>"
checkAbsent "<b>Deprecated</b>"
check "Ready Toko Ini"
check 'class="num">9<'

echo "$HTML" > "$WORKDIR/pengiriman-output.html"
cp "$WORKDIR/pengiriman-output.html" /tmp/ui-smoke-shipment-guard-output.html

if [ $FAIL -ne 0 ]; then
  echo "--- UI SMOKE CHECK FAILED — saved HTML to /tmp/ui-smoke-shipment-guard-output.html ---"
  exit 1
fi
echo "--- UI SMOKE CHECK PASSED ---"
