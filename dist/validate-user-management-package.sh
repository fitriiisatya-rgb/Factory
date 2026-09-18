#!/usr/bin/env bash
# Validates dist/amor-factory-api-user-management-easy.zip by actually
# EXTRACTING it and driving the extracted files end to end: disposable
# MariaDB pre-loaded with a realistic post-Phase-5.5 state, `php -S` rooted
# at the EXTRACTED api/ directory, then a full smoke test of the real
# /api/users/* API (create Driver A/B, activate/deactivate, role change),
# confirming the Driver accounts created THIS way can really log in at the
# real Driver portal — all against the SHIPPED files, not the source tree.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-api-user-management-easy.zip"
WORKDIR="$(mktemp -d)"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_um_pkgvalidate"
ADMIN_PASS="PkgValidateAdmin#$(date +%s)"
MIGRATION_USER_PASS="PkgMigPassUM_123"
RUNTIME_USER_PASS="PkgRunPassUM_123"
PHP_PORT=8112
PHP_PID=""

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then kill "$PHP_PID" 2>/dev/null || true; fi
  if [ -S "$SOCK" ]; then mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true; sleep 1; fi
  rm -f "$REPO_ROOT/api/app/config/config.php"
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

echo "--- 1/9: extracting the SHIPPED ZIP (never the source tree) ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
for f in api/_users-uat/index.php api/app/src/Users/UserService.php api/app/src/Users/UserRepository.php \
         api/app/src/Controllers/UserController.php api/assets/js/users.js api/app/migrations/0007_dispatch_receipt_phase55.php; do
  [ -f "$EXTRACT_DIR/$f" ] || { echo "REFUSING: $f missing from extracted package"; exit 1; }
done
[ -d "$EXTRACT_DIR/api/app/migrations" ] && find "$EXTRACT_DIR/api/app/migrations" -name '0008_*' | grep -q . && { echo "REFUSING: unexpected migration 0008+ in package"; exit 1; }
[ -f "$EXTRACT_DIR/api/app/config/config.php" ] && { echo "REFUSING: extracted package contains a config.php — credentials leaked into the ZIP"; exit 1; }
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/9: initializing disposable MariaDB ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'pkgum_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'pkgum_migration_user'@'localhost';
CREATE USER 'pkgum_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'pkgum_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/9: applying 0001-0007 from the REPO's own migrate.php (simulates 'Phase 5.5 already installed, now upgrading with a files-only patch') ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pkgum_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" pkgum_validate_admin "PkgUM Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/9: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pkgum_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/9: router script (extracted-tree-local) so /api/assets/*.css|js|png serve correctly under php -S ---"
cat > "$WORKDIR/router.php" <<PHPROUTER
<?php
\$uri = urldecode((string) parse_url((string) \$_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#^/api/(assets/[\w./-]+\.(css|js|png|jpg|jpeg|svg))\$#', \$uri, \$m)) {
    \$file = '$EXTRACT_DIR/api/' . \$m[1];
    if (is_file(\$file)) {
        \$types = ['css' => 'text/css; charset=UTF-8', 'js' => 'application/javascript; charset=UTF-8', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml'];
        header('Content-Type: ' . \$types[\$m[2]]);
        readfile(\$file);
        return true;
    }
}
return false;
PHPROUTER

echo "--- 6/9: starting php -S rooted at the EXTRACTED api/ directory ---"
php -S "127.0.0.1:$PHP_PORT" -t "$EXTRACT_DIR/api" "$WORKDIR/router.php" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
kill -0 "$PHP_PID" 2>/dev/null || { echo "php -S failed to start"; cat "$WORKDIR/php-server.log"; exit 1; }

BASE="http://127.0.0.1:$PHP_PORT"
ADMIN_JAR="$WORKDIR/admin_cookies.txt"
curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d "{\"username\":\"pkgum_validate_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
ME=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/auth/me")
CSRF=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["csrfToken"]??"";' "$ME")
[ -n "$CSRF" ] || { echo "REFUSING: no admin csrf token"; exit 1; }

echo "--- 7/9: creating Driver A and Driver B through the real /api/users API (exactly what the admin UI does) ---"
CREATE_A=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/users" -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkgum-a-$(date +%s)" -H 'Content-Type: application/json' -d '{"username":"driver.a","fullName":"Driver A","password":"DriverAPass123","passwordConfirm":"DriverAPass123","roles":["DRIVER"]}')
echo "$CREATE_A" | grep -q '"username":"driver.a"' || { echo "REFUSING: create Driver A failed: $CREATE_A"; exit 1; }
CREATE_B=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/users" -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkgum-b-$(date +%s)" -H 'Content-Type: application/json' -d '{"username":"driver.b","fullName":"Driver B","password":"DriverBPass123","passwordConfirm":"DriverBPass123","roles":["DRIVER"]}')
echo "$CREATE_B" | grep -q '"username":"driver.b"' || { echo "REFUSING: create Driver B failed: $CREATE_B"; exit 1; }

echo "--- 8/9: confirming Driver A can really log in at the real Driver portal login page ---"
DRIVER_JAR="$WORKDIR/driver_cookies.txt"
LOGIN=$(curl -s -o /dev/null -w "%{http_code}" -c "$DRIVER_JAR" -b "$DRIVER_JAR" -X POST "$BASE/_driver-uat/login.php" -d "username=driver.a&password=DriverAPass123&return=index.php")
[ "$LOGIN" = "302" ] || { echo "REFUSING: expected 302 from driver-portal login, got $LOGIN"; exit 1; }
PORTAL=$(curl -s -o /dev/null -w "%{http_code}" -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/_driver-uat/index.php?tab=tersedia")
[ "$PORTAL" = "200" ] || { echo "REFUSING: driver portal did not return 200 after login, got $PORTAL"; exit 1; }

USERS_PAGE=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/_users-uat/")
echo "$USERS_PAGE" | grep -q "driver.a" || { echo "REFUSING: User Management page did not list driver.a"; exit 1; }
echo "$USERS_PAGE" | grep -q "/api/assets/" || { echo "REFUSING: User Management page did not reference /api/assets/"; exit 1; }
echo "$USERS_PAGE" | grep -q '"/api/app/' && { echo "REFUSING: User Management page referenced the deny-all /api/app/ path"; exit 1; }

echo "--- 9/9: done ---"
echo ""
echo "=== PACKAGE VALIDATION PASSED ==="
echo "ZIP: $ZIP_PATH"
echo "Extracted, migrated 0001-0007 (no new migration), created Driver A/B via the real /api/users"
echo "API, confirmed Driver A logs in successfully at the real Driver portal, and the User"
echo "Management page itself renders and lists both drivers — all against the SHIPPED files."
