#!/usr/bin/env bash
# Real Apache 2.4 + PHP-FPM validation for the User Management package —
# same methodology as dist/validate-phase55-apache-assets.sh (php -S
# ignores .htaccess entirely, so this is the only check that actually
# enforces api/app/.htaccess's deny-all and api/assets/'s public
# reachability the way real cPanel Apache does).
#
# Requires apache2 + php8.3-fpm installed (apt-get install apache2
# php8.3-fpm php8.3-mysql — plain Ubuntu archive, not a PPA).
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-api-user-management-easy.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR" # see validate-phase55-apache-assets.sh for why this matters
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_um_apachevalidate"
ADMIN_PASS="ApacheValidateAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassUM_123"
RUNTIME_USER_PASS="ApacheRunPassUM_123"
HTTP_PORT=8198
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q um-validate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q um-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/um-validate.conf /etc/apache2/sites-enabled/um-validate.conf
  rm -f /etc/apache2/conf-available/um-listen.conf /etc/apache2/conf-enabled/um-listen.conf
  if [ "$FPM_STARTED_BY_US" = "1" ] && [ -f /run/php/php8.3-fpm.pid ]; then
    kill "$(cat /run/php/php8.3-fpm.pid)" 2>/dev/null || true
  fi
  if [ -S "$SOCK" ]; then mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true; sleep 1; fi
  rm -f "$REPO_ROOT/api/app/config/config.php"
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

command -v apache2 >/dev/null 2>&1 || { echo "REFUSING: apache2 is not installed (apt-get install apache2 php8.3-fpm php8.3-mysql)"; exit 1; }
command -v php-fpm8.3 >/dev/null 2>&1 || { echo "REFUSING: php8.3-fpm is not installed"; exit 1; }

echo "--- 1/8: extracting the SHIPPED ZIP ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
find "$WORKDIR" -type d -exec chmod 755 {} +
find "$EXTRACT_DIR" -type f -exec chmod 644 {} +
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/8: initializing disposable MariaDB + migrating + seeding ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'umav_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'umav_migration_user'@'localhost';
CREATE USER 'umav_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'umav_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'umav_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" umav_admin "UM Apache Validate Admin" || exit 1
rm -f "$REPO_ROOT/api/app/config/config.php"

cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'umav_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 3/8: starting REAL php8.3-fpm ---"
mkdir -p /run/php
chown www-data:www-data /run/php
if [ ! -S /run/php/php8.3-fpm.sock ]; then
  /usr/sbin/php-fpm8.3 -D --fpm-config /etc/php/8.3/fpm/php-fpm.conf
  FPM_STARTED_BY_US=1
  for i in $(seq 1 20); do [ -S /run/php/php8.3-fpm.sock ] && break; sleep 0.3; done
  [ -S /run/php/php8.3-fpm.sock ] || { echo "php8.3-fpm did not come up"; exit 1; }
fi

echo "--- 4/8: starting REAL apache2 with AllowOverride All on the docroot ---"
cat > /etc/apache2/conf-available/um-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q um-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/um-validate.conf <<CONF
<VirtualHost *:$HTTP_PORT>
    DocumentRoot $EXTRACT_DIR
    <Directory $EXTRACT_DIR>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>
    ErrorLog $WORKDIR/apache-error.log
    CustomLog $WORKDIR/apache-access.log combined
</VirtualHost>
CONF
a2ensite -q um-validate
APACHE_SITE_ENABLED=1
apache2ctl configtest || { echo "REFUSING: apache2 config test failed"; exit 1; }
apache2ctl start
sleep 1
BASE="http://127.0.0.1:$HTTP_PORT"
curl -s -o /dev/null "$BASE/" || { echo "apache2 did not come up"; cat "$WORKDIR/apache-error.log" 2>/dev/null; exit 1; }

FAIL=0
check() {
  local desc="$1" got="$2" want="$3"
  if [ "$got" = "$want" ]; then echo "PASS: $desc (got $got)"; else echo "FAIL: $desc (want $want, got $got)"; FAIL=1; fi
}
check_contains() {
  local desc="$1" body="$2" needle="$3"
  if printf '%s' "$body" | grep -qF -- "$needle"; then echo "PASS: $desc"; else echo "FAIL: $desc — did not find: $needle"; FAIL=1; fi
}
check_not_contains() {
  local desc="$1" body="$2" needle="$3"
  if printf '%s' "$body" | grep -qF -- "$needle"; then echo "FAIL: $desc — unexpectedly found: $needle"; FAIL=1; else echo "PASS: $desc"; fi
}

echo "--- 5/8: api/app/ must be HTTP-forbidden ---"
check "api/app/src/Users/UserService.php is forbidden" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/app/src/Users/UserService.php")" 403
check "api/app/config/config.php is forbidden" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/app/config/config.php")" 403

echo "--- 6/8: api/assets/js/users.js must be HTTP 200 ---"
check "api/assets/js/users.js is 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/assets/js/users.js")" 200
JS_TYPE=$(curl -s -o /dev/null -w '%{content_type}' "$BASE/api/assets/js/users.js")
case "$JS_TYPE" in application/javascript*|text/javascript*) echo "PASS: users.js content-type is a valid JS type ($JS_TYPE)";; *) echo "FAIL: users.js content-type is $JS_TYPE"; FAIL=1;; esac

echo "--- 7/8: the real User Management page renders under Apache and only references /api/assets/ ---"
ADMIN_JAR="$WORKDIR/admin_cookies.txt"
curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d "{\"username\":\"umav_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
USERS_PAGE=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/_users-uat/")
check_contains "User Management page renders (title present)" "$USERS_PAGE" "Manajemen User"
check_contains "User Management page references /api/assets/" "$USERS_PAGE" "/api/assets/"
check_not_contains "User Management page does NOT reference /api/app/" "$USERS_PAGE" "/api/app/"

echo "--- 8/8: create a driver via the real API through Apache, confirm real Driver-portal login through Apache ---"
CSRF=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/auth/me" | php -r '$d=json_decode(file_get_contents("php://stdin"),true);echo $d["data"]["csrfToken"]??"";')
CREATE=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/users" -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: umav-$(date +%s)" -H 'Content-Type: application/json' -d '{"username":"apache.driver","fullName":"Apache Driver","password":"ApacheDriverPass123","passwordConfirm":"ApacheDriverPass123","roles":["DRIVER"]}')
echo "$CREATE" | grep -q '"username":"apache.driver"' && echo "PASS: driver created via real API through Apache" || { echo "FAIL: driver creation through Apache failed: $CREATE"; FAIL=1; }

DRIVER_JAR="$WORKDIR/driver_cookies.txt"
LOGIN_STATUS=$(curl -s -o /dev/null -w '%{http_code}' -c "$DRIVER_JAR" -b "$DRIVER_JAR" -X POST "$BASE/api/_driver-uat/login.php" -d "username=apache.driver&password=ApacheDriverPass123&return=index.php")
check "driver login through Apache returns 302" "$LOGIN_STATUS" 302

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE VALIDATION PASSED ==="
  echo "ZIP: $ZIP_PATH"
else
  echo "=== REAL APACHE VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
