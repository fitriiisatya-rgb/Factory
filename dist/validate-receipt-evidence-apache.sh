#!/usr/bin/env bash
# Validates dist/amor-factory-receipt-evidence-verification-patch.zip
# against a REAL Apache + PHP-FPM server (not `php -S`, which silently
# ignores .htaccess entirely — see SESSION-HANDOFF.md gotcha #1). Adapted
# from dist/validate-shipment-surat-jalan-print-apache.sh — also confirms
# THE critical security property of this specific patch: api/uploads/
# receipt-evidence/ (where uploaded Store Receipt photos land) is
# deny-all under real Apache, exactly like api/app/, and every evidence
# photo is only ever reachable through the ADMIN-authenticated GET
# /api/admin/receipts/evidence/{id} controller — never a direct URL.
# This script:
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
# php8.3-fpm php8.3-mysql php8.3-mbstring — from the plain Ubuntu archive,
# NOT a PPA, to avoid pulling in an unrelated PHP version this project
# doesn't ship against). php8.3-mbstring is NOT new to this patch — the
# codebase already calls mb_strtoupper()/mb_strtolower() in FgRepository.php
# and PoImporter.php — but this is the first real-Apache validator to
# actually exercise a code path that needs it (EvidenceUploader.php's
# mb_substr()), which is exactly the kind of gap this real-PHP-FPM
# validation (as opposed to the CLI `php` binary used by php -S and the
# automated test suite, which may resolve to a DIFFERENT PHP
# version/build with mbstring already compiled in) exists to catch. Run
# as root (needed to manage apache2/php-fpm without
# systemd in a container).
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-receipt-evidence-verification-patch.zip"
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
DB_NAME="amor_factory_rcptev_apachevalidate"
ADMIN_PASS="ApacheRcptEvAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassRCPTEV_123"
RUNTIME_USER_PASS="ApacheRunPassRCPTEV_123"
HTTP_PORT=8195
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q rcptev-validate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q rcptev-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/rcptev-validate.conf /etc/apache2/sites-enabled/rcptev-validate.conf
  rm -f /etc/apache2/conf-available/rcptev-listen.conf /etc/apache2/conf-enabled/rcptev-listen.conf
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
php8.3 -m 2>/dev/null | grep -qi '^mbstring$' || { echo "REFUSING: the mbstring extension is not enabled for php8.3 — the codebase (FgRepository.php, PoImporter.php, EvidenceUploader.php) already requires it (apt-get install php8.3-mbstring)"; exit 1; }

echo "--- 1/10: extracting the SHIPPED ZIP (never the source tree) ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
find "$WORKDIR" -type d -exec chmod 755 {} +
find "$EXTRACT_DIR" -type f -exec chmod 644 {} +
[ -d "$EXTRACT_DIR/api/uploads/receipt-evidence" ] || { echo "REFUSING: api/uploads/receipt-evidence/ missing from extracted package"; exit 1; }
# This script extracts as root, so the tree above would otherwise be
# root:root — but the REAL PHP process (php8.3-fpm's pool user, started
# below) writes uploaded evidence photos into this one directory. On a
# real cPanel account this is a non-issue (the account's own user owns
# BOTH the extracted files and the PHP-FPM/suPHP process that runs them),
# but this harness's root-extracts/www-data-runs-PHP split does not
# reproduce that automatically, so it is made to match explicitly here —
# this is exactly the class of bug real Apache validation exists to catch
# (a silent EVIDENCE_UPLOAD_FAILED that php -S + root would never surface).
chown -R www-data:www-data "$EXTRACT_DIR/api/uploads"
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
CREATE USER 'rcptevav_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'rcptevav_migration_user'@'localhost';
CREATE USER 'rcptevav_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'rcptevav_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/10: applying 0001-0007 from the REPO's own migrate.php + seeding fixtures ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'rcptevav_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" rcptevav_admin "RCPTEV Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
php -r '
require "'"$REPO_ROOT"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
Config::load();
$pdo = Database::pdo();
$hash = password_hash("ApacheDriverPass123", PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (username,password_hash,full_name,active,created_at) VALUES (\"rcptevav_driver\",?,\"RCPTEV Apache Driver\",1,UTC_TIMESTAMP())")->execute([$hash]);
$pdo->exec("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT (SELECT user_id FROM users WHERE username=\"rcptevav_driver\"), role_id FROM roles WHERE code=\"DRIVER\"");
'
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/10: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'rcptevav_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
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

echo "--- 5b/10: creating a REAL shipment (Production -> FG -> claim -> depart) so print-shipment.php has something real to render ---"
# Reuses the rcptevav_driver account already created in step 3/10 (real
# bcrypt hash for ApacheDriverPass123) — never a second, differently-hashed
# row for the same username.
SHIPMENT_ID=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
use Amor\Api\Production\ProductionService; use Amor\Api\Fg\FgService;
use Amor\Api\Dispatch\DispatchService; use Amor\Api\Dispatch\DepartureService;
Config::load();
$pdo = Database::pdo();
$driverId = (int) $pdo->query("SELECT user_id FROM users WHERE username=\"rcptevav_driver\"")->fetchColumn();

$prod = new ProductionService($pdo);
$run = $prod->createDraft("'"$TANGGAL"'", '"$DIVISION_ID"', $driverId);
$run = $prod->patchDraft((int) $run["productionRunId"], (int) $run["version"], [["productId" => '"$PRODUCT_ID"', "actualQty" => 5.0]], false, $driverId, "rcptevav-prod-patch");
$prod->submit((int) $run["productionRunId"], (int) $run["version"], $driverId, "rcptevav-prod-submit");

$fg = new FgService($pdo);
$batch = $fg->createDraft("'"$TANGGAL"'", '"$FACTORY_ID"', $driverId);
$batch = $fg->patchDraft((int) $batch["fgBatchId"], (int) $batch["version"], [["productId" => '"$PRODUCT_ID"', "fgVerified" => 5.0, "packed" => 5.0]], false, $driverId, "rcptevav-fg-patch");
$fg->submit((int) $batch["fgBatchId"], (int) $batch["version"], $driverId, "rcptevav-fg-submit");

$doItemId = (int) $pdo->query("SELECT delivery_order_item_id FROM delivery_order_item WHERE delivery_order_id = '"$DO_ID"' AND product_id = '"$PRODUCT_ID"'")->fetchColumn();
$dispatch = new DispatchService($pdo);
$claim = $dispatch->claim([["doItemId" => $doItemId, "qty" => 5.0]], $driverId, "rcptevav-claim");
$claimId = $claim["claims"][0]["claimId"];

$doVersion = (int) $pdo->query("SELECT version FROM delivery_order WHERE delivery_order_id = '"$DO_ID"'")->fetchColumn();
$departure = new DepartureService($pdo);
$result = $departure->confirmDeparture($driverId, '"$DO_ID"', $doVersion, "MAIN", [["claimId" => $claimId, "actualQty" => 5.0]], "rcptevav-depart");
echo $result["shipments"][0]["shipmentId"];
')
[ -n "$SHIPMENT_ID" ] || { echo "REFUSING: could not seed a real shipment for print-shipment.php"; exit 1; }

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
cat > /etc/apache2/conf-available/rcptev-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q rcptev-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/rcptev-validate.conf <<CONF
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
a2ensite -q rcptev-validate
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
for f in css/tokens.css css/app.css css/driver.css css/receipt.css css/print.css css/print-invoice.css css/print-shipment.css js/app.js js/driver.js js/receipt.js img/amor-logo.png; do
  check "api/assets/$f is 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/assets/$f")" 200
done
CSS_TYPE=$(curl -s -o /dev/null -w '%{content_type}' "$BASE/api/assets/css/driver.css")
case "$CSS_TYPE" in text/css*) echo "PASS: driver.css content-type is text/css ($CSS_TYPE)";; *) echo "FAIL: driver.css content-type is $CSS_TYPE, expected text/css"; FAIL=1;; esac
PNG_TYPE=$(curl -s -o /dev/null -w '%{content_type}' "$BASE/api/assets/img/amor-logo.png")
case "$PNG_TYPE" in image/png*) echo "PASS: amor-logo.png content-type is image/png ($PNG_TYPE)";; *) echo "FAIL: amor-logo.png content-type is $PNG_TYPE, expected image/png"; FAIL=1;; esac

echo "--- 9b/10: DRIVER-UX-TRACING-PATCH-SPECIFIC: the served (not just source) assets carry the actual fixes ---"
DRIVER_CSS_BODY=$(curl -s "$BASE/api/assets/css/driver.css")
check_contains "served driver.css contains the ported .modal-backdrop rule" "$DRIVER_CSS_BODY" "modal-backdrop"
APP_JS_BODY=$(curl -s "$BASE/api/assets/js/app.js")
check_contains "served app.js contains the confirmModal singleton guard" "$APP_JS_BODY" "moduleActiveBackdrop"

echo "--- 9c/10: NAVIGATION-LOGOUT-HOTFIX-SPECIFIC: the served driver.js carries the new navigation/chooser code ---"
DRIVER_JS_BODY=$(curl -s "$BASE/api/assets/js/driver.js")
check_contains "served driver.js contains renderShipmentChooser (multi-shipment chooser)" "$DRIVER_JS_BODY" "renderShipmentChooser"
check_contains "served driver.js contains the friendly departed-stop fallback message" "$DRIVER_JS_BODY" "Pengiriman ini sudah diberangkatkan"

echo "--- 10/10: every real page renders and references ONLY /api/assets/, never /api/app/ ---"
ADMIN_JAR="$WORKDIR/admin_cookies.txt"
DRIVER_JAR="$WORKDIR/driver_cookies.txt"

# Fetch the login form BEFORE authenticating (an already-logged-in session
# 302-redirects login.php straight to index.php with an empty body, which
# would make this check meaningless).
DRIVER_LOGIN_PAGE=$(curl -s "$BASE/api/_driver-uat/login.php")
check_contains "Driver login page references /api/assets/" "$DRIVER_LOGIN_PAGE" "/api/assets/"
check_not_contains "Driver login page does NOT reference /api/app/" "$DRIVER_LOGIN_PAGE" "/api/app/"

curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d "{\"username\":\"rcptevav_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
curl -s -c "$DRIVER_JAR" -b "$DRIVER_JAR" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d '{"username":"rcptevav_driver","password":"ApacheDriverPass123"}' > /dev/null

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

echo "--- DRIVER-UX-TRACING-PATCH-SPECIFIC: the new Detail Pengiriman page + its API route are reachable under real Apache/.htaccess ---"
check "api/_driver-uat/shipment.php (new page, no id) is reachable, not .htaccess-blocked" \
  "$(curl -s -o /dev/null -w '%{http_code}' -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/api/_driver-uat/shipment.php")" 200
DISPATCH_API_CODE=$(curl -s -o /dev/null -w '%{http_code}' -c "$DRIVER_JAR" -b "$DRIVER_JAR" -H 'X-CSRF-Token: probe' "$BASE/api/dispatch/shipments/999999")
if [ "$DISPATCH_API_CODE" = "403" ] || [ "$DISPATCH_API_CODE" = "404" ]; then
  echo "PASS: GET /api/dispatch/shipments/{id} is routed through the front controller (real app response $DISPATCH_API_CODE for a nonexistent id, not an Apache-level block)"
else
  echo "FAIL: GET /api/dispatch/shipments/{id} returned unexpected $DISPATCH_API_CODE"
  FAIL=1
fi

echo "--- NAVIGATION-LOGOUT-HOTFIX-SPECIFIC: the new chooser route + cache-busted asset URLs work under real Apache/.htaccess ---"
STOPSHIP_CODE=$(curl -s -o /dev/null -w '%{http_code}' -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/api/dispatch/route/stops/1/shipments?tanggal=2026-01-01")
if [ "$STOPSHIP_CODE" = "200" ]; then
  echo "PASS: GET /api/dispatch/route/stops/{storeId}/shipments is routed through the front controller (real app response 200, not an Apache-level block)"
else
  echo "FAIL: GET /api/dispatch/route/stops/{storeId}/shipments returned unexpected $STOPSHIP_CODE"
  FAIL=1
fi
# The driver portal HTML must reference its CSS/JS with the cache-busting
# ?v= query string, and that EXACT versioned URL must itself resolve —
# proving the hardening actually forces a fresh fetch under real Apache,
# not just that the plain (unversioned) path happens to work.
check_contains "Driver portal HTML references driver.js with a cache-busting ?v= query string" "$DRIVER_PAGE" "driver.js?v="
VERSIONED_JS_URL=$(printf '%s' "$DRIVER_PAGE" | grep -oE '/api/assets/js/driver\.js\?v=[A-Za-z0-9._-]+' | head -1)
[ -n "$VERSIONED_JS_URL" ] && check "the exact versioned driver.js URL referenced in the HTML is 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE$VERSIONED_JS_URL")" 200

echo "--- SHIPMENT-SURAT-JALAN-PRINT-PATCH-SPECIFIC: Draft DO vs actual shipment print documents, under real Apache/.htaccess ---"
DO_PRINT_PAGE=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/_ui-preview/print-do.php?doId=$DO_ID")
check_contains "Draft DO print shows the unambiguous planning-level title" "$DO_PRINT_PAGE" "RENCANA PENGIRIMAN"
check_not_contains "Draft DO print tab title never claims to be Surat Jalan any more" "$DO_PRINT_PAGE" "<title>Surat Jalan"

DRIVER_SJ_PAGE=$(curl -s -o /dev/null -w '%{http_code}' -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/api/_driver-uat/print-shipment.php?id=$SHIPMENT_ID")
check "api/_driver-uat/print-shipment.php (Driver, own shipment) is 200, not .htaccess-blocked" "$DRIVER_SJ_PAGE" 200
DRIVER_SJ_BODY=$(curl -s -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/api/_driver-uat/print-shipment.php?id=$SHIPMENT_ID")
check_contains "Surat Jalan print shows the SURAT JALAN title" "$DRIVER_SJ_BODY" "SURAT JALAN"
check_contains "Surat Jalan print references print-shipment.css" "$DRIVER_SJ_BODY" "print-shipment.css"
check_contains "Surat Jalan print embeds the DO receipt QR" "$DRIVER_SJ_BODY" "print-receipt-qr-code"
check_not_contains "Surat Jalan print does NOT reference /api/app/" "$DRIVER_SJ_BODY" "/api/app/"

ADMIN_SJ_CODE=$(curl -s -o /dev/null -w '%{http_code}' -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/_ui-preview/print-shipment.php?id=$SHIPMENT_ID")
check "api/_ui-preview/print-shipment.php (Admin, reusable route) is 200, not .htaccess-blocked" "$ADMIN_SJ_CODE" 200

FOREIGN_SHIPMENT_CODE=$(curl -s -o /dev/null -w '%{http_code}' -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/api/_driver-uat/print-shipment.php?id=999999")
if [ "$FOREIGN_SHIPMENT_CODE" = "403" ] || [ "$FOREIGN_SHIPMENT_CODE" = "404" ]; then
  echo "PASS: printing a shipment id this driver doesn't own is refused with a real status ($FOREIGN_SHIPMENT_CODE, not an Apache-level artifact)"
else
  echo "FAIL: printing a foreign/nonexistent shipment id returned unexpected $FOREIGN_SHIPMENT_CODE"
  FAIL=1
fi

PRINT_SHIPMENT_CSS_BODY=$(curl -s "$BASE/api/assets/css/print-shipment.css")
check_contains "served print-shipment.css defines the 40mm default QR size class" "$PRINT_SHIPMENT_CSS_BODY" "sj-qr-40mm"

echo "--- RECEIPT-EVIDENCE-PATCH-SPECIFIC: photo upload, admin evidence viewer, and the deny-all upload directory, all under real Apache/.htaccess ---"

echo "PASS-or-FAIL below depend on a REAL multipart upload through the REAL front-controller rewrite — not a PHP-direct call:"
EVIDENCE_PNG="$WORKDIR/evidence-test.png"
# A real, valid 1x1 PNG (same bytes the automated PHP/Playwright suites use).
base64 -d > "$EVIDENCE_PNG" <<'PNGB64'
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==
PNGB64

SII=$(curl -s "$BASE/api/receive/$TOKEN" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["shipments"][0]["items"][0]["shipmentItemId"];')
[ -n "$SII" ] || { echo "REFUSING: could not resolve a shipmentItemId from the real public receive view"; FAIL=1; }

BLOCKED_CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/receive/$TOKEN/shipments/$SHIPMENT_ID/confirm" \
  -H "Idempotency-Key: rcptev-block-$(date +%s)" \
  -F "receiverName=Toko Apache Test" \
  -F "items=[{\"shipmentItemId\":$SII,\"receivedGood\":4,\"reject\":1,\"shortage\":0}]")
check "a discrepancy confirm WITHOUT any photo is blocked (400) through the real multipart route" "$BLOCKED_CODE" 400

CONFIRM_JSON=$(curl -s -X POST "$BASE/api/receive/$TOKEN/shipments/$SHIPMENT_ID/confirm" \
  -H "Idempotency-Key: rcptev-confirm-$(date +%s)" \
  -F "receiverName=Toko Apache Test" \
  -F "items=[{\"shipmentItemId\":$SII,\"receivedGood\":4,\"reject\":1,\"shortage\":0}]" \
  -F "evidence[]=@$EVIDENCE_PNG;type=image/png")
RECEIPT_ID=$(printf '%s' "$CONFIRM_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["receiptId"] ?? "";')
if [ -z "$RECEIPT_ID" ]; then
  echo "FAIL: real multipart confirm WITH a photo did not succeed: $CONFIRM_JSON"
  echo "--- diagnostic: tail of apache error log ---"
  tail -n 30 "$WORKDIR/apache-error.log" 2>&1 || echo "(no apache-error.log)"
  echo "--- diagnostic: tail of php-fpm error log ---"
  tail -n 30 /var/log/php8.3-fpm.log 2>&1 || echo "(no php8.3-fpm.log)"
  echo "--- diagnostic: uploads dir ownership/perms ---"
  ls -ld "$EXTRACT_DIR/api/uploads" "$EXTRACT_DIR/api/uploads/receipt-evidence" 2>&1
  FAIL=1
else
  echo "PASS: a discrepancy confirm WITH a real photo succeeds through the real multipart route (receiptId=$RECEIPT_ID)"
fi

# The uploaded file itself must be COMPLETELY unreachable by direct URL —
# this is the core security property of this whole patch.
EVIDENCE_FILE=$(mariadb --socket="$SOCK" -u root -N -e "SELECT file_path FROM $DB_NAME.shipment_receipt_evidence WHERE shipment_receipt_id = $RECEIPT_ID LIMIT 1" 2>/dev/null || true)
if [ -n "$EVIDENCE_FILE" ]; then
  DIRECT_CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/uploads/receipt-evidence/$EVIDENCE_FILE")
  check "the uploaded evidence file is NOT directly web-reachable (403, deny-all .htaccess)" "$DIRECT_CODE" 403
fi

ADMIN_EVIDENCE_CODE=$(curl -s -o /dev/null -w '%{http_code}' -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/admin/receipts/evidence/1")
if [ "$ADMIN_EVIDENCE_CODE" = "200" ] || [ "$ADMIN_EVIDENCE_CODE" = "404" ]; then
  echo "PASS: GET /api/admin/receipts/evidence/{id} is routed through the front controller as ADMIN (real app response $ADMIN_EVIDENCE_CODE, not an Apache-level block)"
else
  echo "FAIL: GET /api/admin/receipts/evidence/{id} returned unexpected $ADMIN_EVIDENCE_CODE"
  FAIL=1
fi
DRIVER_EVIDENCE_CODE=$(curl -s -o /dev/null -w '%{http_code}' -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/api/admin/receipts/evidence/1")
check "GET /api/admin/receipts/evidence/{id} refuses a non-admin (Driver) caller" "$DRIVER_EVIDENCE_CODE" 403

DETAIL_PAGE=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/_ui-preview/?page=konfirmasi-toko-detail&shipmentId=$SHIPMENT_ID")
check_contains "Admin Konfirmasi Toko Detail page renders the real shipment" "$DETAIL_PAGE" "SHP-$SHIPMENT_ID"
check_contains "Admin Konfirmasi Toko Detail page references /api/assets/" "$DETAIL_PAGE" "/api/assets/"
check_not_contains "Admin Konfirmasi Toko Detail page does NOT reference /api/app/" "$DETAIL_PAGE" "/api/app/"
check_contains "Admin Konfirmasi Toko Detail page shows the evidence thumbnail via the authenticated route" "$DETAIL_PAGE" "/api/admin/receipts/evidence/"

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
