#!/usr/bin/env bash
# Validates dist/amor-factory-api-phase55-dispatch-receipt-easy.zip against
# a REAL Apache + PHP-FPM server (not `php -S`, which silently ignores
# .htaccess entirely and is why the api/app/ui/assets/ deny-all bug shipped
# undetected in earlier passes). This script:
#   1. extracts the SHIPPED ZIP (never the source tree),
#   2. stands up a disposable MariaDB pre-loaded with a realistic
#      post-Phase-5 state (same fixture bootstrap as
#      validate-phase55-dispatch-receipt-package.sh),
#   3. starts a REAL apache2 + php8.3-fpm, with AllowOverride All on the
#      docroot — the same setting a real cPanel account has — so every
#      .htaccess in the tree (api/.htaccess's front-controller rewrite,
#      api/app/.htaccess's deny-all, api/assets/.htaccess's -Indexes,
#      every UAT tool's own .htaccess) is ACTUALLY enforced, exactly like
#      the real factory.amorgroup.id deployment,
#   4. asserts: api/app/* is HTTP-forbidden, api/assets/*.css|js|png are
#      HTTP 200 with sane content-types, and every rendered browser-facing
#      page (Driver portal, public Store Receipt portal, admin UI, DO
#      print, Invoice preview) references ONLY /api/assets/... asset URLs
#      — never /api/app/....
#
# Requires apache2 + php8.3-fpm installed (apt-get install apache2
# php8.3-fpm php8.3-mysql — from the plain Ubuntu archive, NOT a PPA, to
# avoid pulling in an unrelated PHP version this project doesn't ship
# against). Run as root (needed to manage apache2/php-fpm without
# systemd in a container).
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-api-phase55-dispatch-receipt-easy.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR" # mktemp -d defaults to 0700 root-only, which would make
                      # apache2's www-data worker unable to even traverse into
                      # this directory — every request would 403 with
                      # "permission denied" regardless of what any .htaccess
                      # says, silently masquerading as a correct-looking
                      # result. This directory holds no secrets (config.php's
                      # DB creds are for a disposable throwaway database), so
                      # world-traversable is fine for this local validation run.
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_p55_apachevalidate"
ADMIN_PASS="ApacheValidateAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassP55_123"
RUNTIME_USER_PASS="ApacheRunPassP55_123"
HTTP_PORT=8199
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q p55-validate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q p55-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/p55-validate.conf /etc/apache2/sites-enabled/p55-validate.conf
  rm -f /etc/apache2/conf-available/p55-listen.conf /etc/apache2/conf-enabled/p55-listen.conf
  if [ "$FPM_STARTED_BY_US" = "1" ] && [ -f /run/php/php8.3-fpm.pid ]; then
    kill "$(cat /run/php/php8.3-fpm.pid)" 2>/dev/null || true
  fi
  if [ -S "$SOCK" ]; then mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true; sleep 1; fi
  rm -f "$REPO_ROOT/api/app/config/config.php"
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

command -v apache2 >/dev/null 2>&1 || { echo "REFUSING: apache2 is not installed (apt-get install apache2 php8.3-fpm php8.3-mysql)"; exit 1; }
command -v php-fpm8.3 >/dev/null 2>&1 || { echo "REFUSING: php8.3-fpm is not installed (apt-get install apache2 php8.3-fpm php8.3-mysql)"; exit 1; }

echo "--- 1/10: extracting the SHIPPED ZIP (never the source tree) ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
find "$WORKDIR" -type d -exec chmod 755 {} +
find "$EXTRACT_DIR" -type f -exec chmod 644 {} +
[ -d "$EXTRACT_DIR/api/assets" ] || { echo "REFUSING: api/assets/ missing from extracted package"; exit 1; }
[ -d "$EXTRACT_DIR/api/app/ui/assets" ] && { echo "REFUSING: api/app/ui/assets/ still present in the extracted package — assets were not fully relocated"; exit 1; }
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/10: initializing disposable MariaDB ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'p55av_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'p55av_migration_user'@'localhost';
CREATE USER 'p55av_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'p55av_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/10: applying 0001-0007 from the REPO's own migrate.php + seeding fixtures ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'p55av_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" p55av_admin "P55 Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
php -r '
require "'"$REPO_ROOT"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
Config::load();
$pdo = Database::pdo();
$hash = password_hash("ApacheDriverPass123", PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (username,password_hash,full_name,active,created_at) VALUES (\"p55av_driver\",?,\"P55 Apache Driver\",1,UTC_TIMESTAMP())")->execute([$hash]);
$pdo->exec("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT (SELECT user_id FROM users WHERE username=\"p55av_driver\"), role_id FROM roles WHERE code=\"DRIVER\"");
'
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/10: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'p55av_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/10: seeding a real DO (PO -> Production -> FG -> DO) directly via PHP so print-do.php has something real to render ---"
DIVISION_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT division_id FROM $DB_NAME.division WHERE name='Roti & Bollen'")
FACTORY_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT factory_id FROM $DB_NAME.division WHERE division_id=$DIVISION_ID")
PRODUCT_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT product_id FROM $DB_NAME.product WHERE division_id=$DIVISION_ID ORDER BY product_id LIMIT 1")
STORE_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")
TANGGAL="2026-08-15"
mariadb --socket="$SOCK" -u root -e "
INSERT INTO $DB_NAME.po_batch (tanggal, factory_id, version, created_at) VALUES ('$TANGGAL', $FACTORY_ID, 1, UTC_TIMESTAMP());
SET @bid = LAST_INSERT_ID();
INSERT INTO $DB_NAME.po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (@bid, $PRODUCT_ID, NULL, 5, 0, 0);
SET @iid = LAST_INSERT_ID();
INSERT INTO $DB_NAME.po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (@iid, $STORE_ID, 5, 0);
"
DO_ID=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database; use Amor\Api\Delivery\DoService;
Config::load();
$pdo = Database::pdo();
$svc = new DoService($pdo);
$dto = $svc->createDraft("'"$TANGGAL"'", '"$STORE_ID"', 1);
echo $dto["doId"];
')
[ -n "$DO_ID" ] || { echo "REFUSING: could not seed a real DO for print-do.php"; exit 1; }
TOKEN=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database; use Amor\Api\Dispatch\ReceiptService;
Config::load();
$pdo = Database::pdo();
$svc = new ReceiptService($pdo);
echo $svc->getReceiptToken((int)'"$DO_ID"');
')
[ -n "$TOKEN" ] || { echo "REFUSING: could not obtain a receipt token for the receive portal"; exit 1; }

echo "--- 6/10: starting REAL php8.3-fpm ---"
mkdir -p /run/php
chown www-data:www-data /run/php
if [ ! -S /run/php/php8.3-fpm.sock ]; then
  /usr/sbin/php-fpm8.3 -D --fpm-config /etc/php/8.3/fpm/php-fpm.conf
  FPM_STARTED_BY_US=1
  for i in $(seq 1 20); do [ -S /run/php/php8.3-fpm.sock ] && break; sleep 0.3; done
  [ -S /run/php/php8.3-fpm.sock ] || { echo "php8.3-fpm did not come up"; exit 1; }
fi

echo "--- 7/10: starting REAL apache2 with AllowOverride All on the docroot (exactly like real cPanel shared hosting) ---"
cat > /etc/apache2/conf-available/p55-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q p55-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/p55-validate.conf <<CONF
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
a2ensite -q p55-validate
APACHE_SITE_ENABLED=1
apache2ctl configtest || { echo "REFUSING: apache2 config test failed"; exit 1; }
apache2ctl start
sleep 1
BASE="http://127.0.0.1:$HTTP_PORT"
curl -s -o /dev/null "$BASE/" || { echo "apache2 did not come up on port $HTTP_PORT"; cat "$WORKDIR/apache-error.log" 2>/dev/null; exit 1; }

FAIL=0
check() {
  local desc="$1" got="$2" want="$3"
  if [ "$got" = "$want" ]; then
    echo "PASS: $desc (got $got)"
  else
    echo "FAIL: $desc (want $want, got $got)"
    FAIL=1
  fi
}
check_contains() {
  local desc="$1" body="$2" needle="$3"
  if printf '%s' "$body" | grep -qF -- "$needle"; then
    echo "PASS: $desc"
  else
    echo "FAIL: $desc — did not find: $needle"
    FAIL=1
  fi
}
check_not_contains() {
  local desc="$1" body="$2" needle="$3"
  if printf '%s' "$body" | grep -qF -- "$needle"; then
    echo "FAIL: $desc — unexpectedly found: $needle"
    FAIL=1
  else
    echo "PASS: $desc"
  fi
}

echo "--- 8/10: api/app/ must be HTTP-forbidden (the actual bug: this used to also block the assets the UI needed) ---"
check "api/app/config/config.php is forbidden"        "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/app/config/config.php")"                         403
check "api/app/src/Dispatch/DispatchService.php is forbidden" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/app/src/Dispatch/DispatchService.php")" 403
check "api/app/migrations/0007_...php is forbidden"    "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/app/migrations/0007_dispatch_receipt_phase55.php")" 403
check "api/app/ui/layout.php (server template) is forbidden" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/app/ui/layout.php")"                      403

echo "--- 9/10: api/assets/* must be HTTP 200 (the actual fix) ---"
for f in css/tokens.css css/app.css css/driver.css css/receipt.css css/print.css css/print-invoice.css js/app.js js/driver.js js/receipt.js img/amor-logo.png; do
  check "api/assets/$f is 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/assets/$f")" 200
done
CSS_TYPE=$(curl -s -o /dev/null -w '%{content_type}' "$BASE/api/assets/css/driver.css")
case "$CSS_TYPE" in text/css*) echo "PASS: driver.css content-type is text/css ($CSS_TYPE)";; *) echo "FAIL: driver.css content-type is $CSS_TYPE, expected text/css"; FAIL=1;; esac
PNG_TYPE=$(curl -s -o /dev/null -w '%{content_type}' "$BASE/api/assets/img/amor-logo.png")
case "$PNG_TYPE" in image/png*) echo "PASS: amor-logo.png content-type is image/png ($PNG_TYPE)";; *) echo "FAIL: amor-logo.png content-type is $PNG_TYPE, expected image/png"; FAIL=1;; esac

echo "--- 10/10: every real page renders and references ONLY /api/assets/, never /api/app/ ---"
ADMIN_JAR="$WORKDIR/admin_cookies.txt"
DRIVER_JAR="$WORKDIR/driver_cookies.txt"

# Fetch the login form BEFORE authenticating (an already-logged-in session
# 302-redirects login.php straight to index.php with an empty body, which
# would make this check meaningless).
DRIVER_LOGIN_PAGE=$(curl -s "$BASE/api/_driver-uat/login.php")
check_contains "Driver login page references /api/assets/" "$DRIVER_LOGIN_PAGE" "/api/assets/"
check_not_contains "Driver login page does NOT reference /api/app/" "$DRIVER_LOGIN_PAGE" "/api/app/"

curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d "{\"username\":\"p55av_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
curl -s -c "$DRIVER_JAR" -b "$DRIVER_JAR" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d '{"username":"p55av_driver","password":"ApacheDriverPass123"}' > /dev/null

DRIVER_PAGE=$(curl -s -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/api/_driver-uat/index.php?tab=tersedia")
check_contains "Driver portal page renders (Amor Factory)" "$DRIVER_PAGE" "Amor"
check_contains "Driver portal references /api/assets/" "$DRIVER_PAGE" "/api/assets/"
check_not_contains "Driver portal does NOT reference /api/app/" "$DRIVER_PAGE" "/api/app/"
# The actual asset URL printed in the HTML must itself resolve.
DRIVER_CSS_URL=$(printf '%s' "$DRIVER_PAGE" | grep -oE '/api/assets/css/driver\.css' | head -1)
[ -n "$DRIVER_CSS_URL" ] && check "the exact driver.css URL referenced in the HTML is 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE$DRIVER_CSS_URL")" 200

RECEIVE_PAGE=$(curl -s "$BASE/api/_receive/?token=$TOKEN")
check_contains "Public Store Receipt portal references /api/assets/" "$RECEIVE_PAGE" "/api/assets/"
check_not_contains "Public Store Receipt portal does NOT reference /api/app/" "$RECEIVE_PAGE" "/api/app/"

ADMIN_UI_PAGE=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/_ui-preview/?page=konfirmasi-toko")
check_contains "Admin Konfirmasi Toko page references /api/assets/" "$ADMIN_UI_PAGE" "/api/assets/"
check_not_contains "Admin Konfirmasi Toko page does NOT reference /api/app/" "$ADMIN_UI_PAGE" "/api/app/"

PRINT_PAGE=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/_ui-preview/print-do.php?doId=$DO_ID")
check_contains "DO print page renders (has the receipt QR block)" "$PRINT_PAGE" "print-receipt-qr"
check_contains "DO print page references /api/assets/ for its logo/CSS" "$PRINT_PAGE" "/api/assets/"
check_not_contains "DO print page does NOT reference /api/app/" "$PRINT_PAGE" "/api/app/"
PRINT_LOGO_URL=$(printf '%s' "$PRINT_PAGE" | grep -oE '/api/assets/img/amor-logo\.png' | head -1)
[ -n "$PRINT_LOGO_URL" ] && check "the exact logo URL referenced on the DO print page is 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE$PRINT_LOGO_URL")" 200

INVOICE_PAGE=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/_ui-preview/invoice-preview.php")
check_contains "Invoice preview page references /api/assets/" "$INVOICE_PAGE" "/api/assets/"
check_not_contains "Invoice preview page does NOT reference /api/app/" "$INVOICE_PAGE" "/api/app/"

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE VALIDATION PASSED ==="
  echo "ZIP: $ZIP_PATH"
  echo "api/app/ confirmed HTTP-forbidden under REAL Apache + .htaccess (AllowOverride All)."
  echo "api/assets/*.css|js|png confirmed HTTP 200 with correct content-types under REAL Apache."
  echo "Driver portal, public Store Receipt portal, admin UI, DO print, and Invoice preview all"
  echo "render and reference ONLY /api/assets/ — never the deny-all /api/app/ — all against the"
  echo "SHIPPED, extracted package files."
else
  echo "=== REAL APACHE VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
