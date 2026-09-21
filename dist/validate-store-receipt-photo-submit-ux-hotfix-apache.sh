#!/usr/bin/env bash
# Validates dist/amor-factory-store-receipt-photo-submit-ux-hotfix.zip
# against a REAL Apache + PHP-FPM server (not `php -S`, which silently
# ignores .htaccess — see SESSION-HANDOFF.md gotcha #1), PLUS a real
# headless-Chromium browser at a MOBILE viewport for the actual JS/CSS
# UX fixes (button enable/disable state and photo preview sizing cannot
# be verified by curl alone).
#
# Runs the REC-UX-01..10 checks named in the bug report:
#   REC-UX-01 clean receipt, valid totals, no photo -> button enabled
#   REC-UX-02 discrepancy without photo -> button disabled
#   REC-UX-03 discrepancy + valid photo -> button enabled
#   REC-UX-04 selecting a photo immediately refreshes button state
#   REC-UX-05 removing the last photo immediately re-disables the button
#             (the confirmed root cause of "submit button stuck disabled")
#   REC-UX-06 changing quantity inputs immediately refreshes button state
#   REC-UX-07 photo preview stays within bounded max dimensions (mobile)
#   REC-UX-08 multiple photos render in a grid with no layout overflow
#   REC-UX-09 server-side discrepancy-photo validation is unchanged
#             (a raw multipart POST bypassing the button entirely is still
#             rejected/accepted exactly as before)
#   REC-UX-10 a historical (already confirmed) receipt card is unaffected
#             — still read-only, never mutated by this hotfix
#
# Requires apache2 + php8.3-fpm + node/playwright (all already present in
# this environment's validation harness). Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-store-receipt-photo-submit-ux-hotfix.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_uxhotfix_apachevalidate"
ADMIN_PASS="ApacheUxHotfixAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassUXHOTFIX_123"
RUNTIME_USER_PASS="ApacheRunPassUXHOTFIX_123"
HTTP_PORT=8197
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q uxhotfix-validate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q uxhotfix-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/uxhotfix-validate.conf /etc/apache2/sites-enabled/uxhotfix-validate.conf
  rm -f /etc/apache2/conf-available/uxhotfix-listen.conf /etc/apache2/conf-enabled/uxhotfix-listen.conf
  if [ "$FPM_STARTED_BY_US" = "1" ] && [ -f /run/php/php8.3-fpm.pid ]; then
    kill "$(cat /run/php/php8.3-fpm.pid)" 2>/dev/null || true
  fi
  if [ -S "$SOCK" ]; then mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true; sleep 1; fi
  rm -f "$REPO_ROOT/api/app/config/config.php"
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

command -v apache2 >/dev/null 2>&1 || { echo "REFUSING: apache2 is not installed"; exit 1; }
command -v php-fpm8.3 >/dev/null 2>&1 || { echo "REFUSING: php8.3-fpm is not installed"; exit 1; }
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-store-receipt-photo-submit-ux-hotfix.sh first"; exit 1; }

echo "--- 1/9: extracting the SHIPPED ZIP (never the source tree) ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
find "$WORKDIR" -type d -exec chmod 755 {} +
find "$EXTRACT_DIR" -type f -exec chmod 644 {} +
chown -R www-data:www-data "$EXTRACT_DIR/api/uploads"
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/9: initializing disposable MariaDB ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'uxhfav_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'uxhfav_migration_user'@'localhost';
CREATE USER 'uxhfav_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'uxhfav_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/9: migrate + seed + admin + master bootstrap (via the REPO's own bin/, config aimed at the extracted tree at runtime) ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'uxhfav_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" uxhfav_admin "UxHotfix Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
php -r '
require "'"$REPO_ROOT"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
Config::load();
$pdo = Database::pdo();
$hash = password_hash("ApacheDriverPassUX123", PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (username,password_hash,full_name,active,created_at) VALUES (\"uxhfav_driver\",?,\"UxHotfix Apache Driver\",1,UTC_TIMESTAMP())")->execute([$hash]);
$pdo->exec("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT (SELECT user_id FROM users WHERE username=\"uxhfav_driver\"), role_id FROM roles WHERE code=\"DRIVER\"");
'
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/9: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'uxhfav_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/9: seeding TWO shipments on ONE DO (one confirmed=historical, one left pending=under test) ---"
DIVISION_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT division_id FROM $DB_NAME.division WHERE name='Roti & Bollen'")
FACTORY_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT factory_id FROM $DB_NAME.division WHERE division_id=$DIVISION_ID")
PRODUCT_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT product_id FROM $DB_NAME.product WHERE division_id=$DIVISION_ID ORDER BY product_id LIMIT 1")
PRODUCT_ID_2=$(mariadb --socket="$SOCK" -u root -N -e "SELECT product_id FROM $DB_NAME.product WHERE division_id=$DIVISION_ID ORDER BY product_id LIMIT 1 OFFSET 1")
STORE_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")
TANGGAL="2026-08-20"
mariadb --socket="$SOCK" -u root -e "
INSERT INTO $DB_NAME.po_batch (tanggal, factory_id, version, created_at) VALUES ('$TANGGAL', $FACTORY_ID, 1, UTC_TIMESTAMP());
SET @bid = LAST_INSERT_ID();
INSERT INTO $DB_NAME.po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (@bid, $PRODUCT_ID, NULL, 5, 0, 0);
INSERT INTO $DB_NAME.po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (@bid, $PRODUCT_ID_2, NULL, 5, 0, 0);
SET @iid1 = (SELECT po_item_id FROM $DB_NAME.po_item WHERE po_batch_id=@bid AND product_id=$PRODUCT_ID);
SET @iid2 = (SELECT po_item_id FROM $DB_NAME.po_item WHERE po_batch_id=@bid AND product_id=$PRODUCT_ID_2);
INSERT INTO $DB_NAME.po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (@iid1, $STORE_ID, 5, 0);
INSERT INTO $DB_NAME.po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (@iid2, $STORE_ID, 5, 0);
"
RESULT=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database; use Amor\Api\Delivery\DoService;
use Amor\Api\Production\ProductionService; use Amor\Api\Fg\FgService;
use Amor\Api\Dispatch\DispatchService; use Amor\Api\Dispatch\DepartureService;
use Amor\Api\Dispatch\ReceiptService;
Config::load();
$pdo = Database::pdo();
$doSvc = new DoService($pdo);
$dto = $doSvc->createDraft("'"$TANGGAL"'", '"$STORE_ID"', 1);
$doId = (int) $dto["doId"];

$driverId = (int) $pdo->query("SELECT user_id FROM users WHERE username=\"uxhfav_driver\"")->fetchColumn();
$prod = new ProductionService($pdo);
$run = $prod->createDraft("'"$TANGGAL"'", '"$DIVISION_ID"', $driverId);
$run = $prod->patchDraft((int) $run["productionRunId"], (int) $run["version"], [["productId" => '"$PRODUCT_ID"', "actualQty" => 5.0], ["productId" => '"$PRODUCT_ID_2"', "actualQty" => 5.0]], false, $driverId, "uxhfav-prod-patch");
$prod->submit((int) $run["productionRunId"], (int) $run["version"], $driverId, "uxhfav-prod-submit");

$fg = new FgService($pdo);
$batch = $fg->createDraft("'"$TANGGAL"'", '"$FACTORY_ID"', $driverId);
$batch = $fg->patchDraft((int) $batch["fgBatchId"], (int) $batch["version"], [["productId" => '"$PRODUCT_ID"', "fgVerified" => 5.0, "packed" => 5.0], ["productId" => '"$PRODUCT_ID_2"', "fgVerified" => 5.0, "packed" => 5.0]], false, $driverId, "uxhfav-fg-patch");
$fg->submit((int) $batch["fgBatchId"], (int) $batch["version"], $driverId, "uxhfav-fg-submit");

$doItemId1 = (int) $pdo->query("SELECT delivery_order_item_id FROM delivery_order_item WHERE delivery_order_id = $doId AND product_id = '"$PRODUCT_ID"'")->fetchColumn();
$doItemId2 = (int) $pdo->query("SELECT delivery_order_item_id FROM delivery_order_item WHERE delivery_order_id = $doId AND product_id = '"$PRODUCT_ID_2"'")->fetchColumn();
$dispatch = new DispatchService($pdo);
$claim1 = $dispatch->claim([["doItemId" => $doItemId1, "qty" => 5.0]], $driverId, "uxhfav-claim1");
$claimId1 = $claim1["claims"][0]["claimId"];

$doVersion = (int) $pdo->query("SELECT version FROM delivery_order WHERE delivery_order_id = $doId")->fetchColumn();
$departure = new DepartureService($pdo);
$result1 = $departure->confirmDeparture($driverId, $doId, $doVersion, "MAIN", [["claimId" => $claimId1, "actualQty" => 5.0]], "uxhfav-depart1");
$shipmentId1 = (int) $result1["shipments"][0]["shipmentId"];

$claim2 = $dispatch->claim([["doItemId" => $doItemId2, "qty" => 5.0]], $driverId, "uxhfav-claim2");
$claimId2 = $claim2["claims"][0]["claimId"];
$doVersion2 = (int) $pdo->query("SELECT version FROM delivery_order WHERE delivery_order_id = $doId")->fetchColumn();
$result2 = $departure->confirmDeparture($driverId, $doId, $doVersion2, "PASTRY", [["claimId" => $claimId2, "actualQty" => 5.0]], "uxhfav-depart2");
$shipmentId2 = (int) $result2["shipments"][0]["shipmentId"];

$receiptSvc = new ReceiptService($pdo);
$token = $receiptSvc->getReceiptToken($doId);

// Make shipment 1 genuinely HISTORICAL (already confirmed, clean, no
// discrepancy) BEFORE Apache/the browser ever sees this token — this is
// what REC-UX-10 actually needs: a card that is already read-only.
$sii1 = (int) $pdo->query("SELECT shipment_item_id FROM shipment_item WHERE shipment_id = $shipmentId1 LIMIT 1")->fetchColumn();
$receiptSvc->confirmReceipt($token, $shipmentId1, "Toko Historis Apache", null, [["shipmentItemId" => $sii1, "receivedGood" => 5.0, "reject" => 0.0, "shortage" => 0.0]], [], "uxhfav-historical-confirm");

echo "$doId|$shipmentId1|$shipmentId2|$token";
')
DO_ID=$(echo "$RESULT" | cut -d"|" -f1)
SHIPMENT_ID_HISTORICAL=$(echo "$RESULT" | cut -d"|" -f2)
SHIPMENT_ID_PENDING=$(echo "$RESULT" | cut -d"|" -f3)
TOKEN=$(echo "$RESULT" | cut -d"|" -f4)
[ -n "$DO_ID" ] && [ -n "$TOKEN" ] || { echo "REFUSING: could not seed the two-shipment fixture: $RESULT"; exit 1; }
echo "DO_ID=$DO_ID SHIPMENT_ID_HISTORICAL=$SHIPMENT_ID_HISTORICAL SHIPMENT_ID_PENDING=$SHIPMENT_ID_PENDING TOKEN=$TOKEN"

EVIDENCE_PNG="$WORKDIR/evidence-test.png"
base64 -d > "$EVIDENCE_PNG" <<'PNGB64'
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==
PNGB64

echo "--- 6/9: starting REAL php8.3-fpm + REAL apache2 (AllowOverride All, like real cPanel) ---"
mkdir -p /run/php
chown www-data:www-data /run/php
if [ ! -S /run/php/php8.3-fpm.sock ]; then
  /usr/sbin/php-fpm8.3 -D --fpm-config /etc/php/8.3/fpm/php-fpm.conf
  FPM_STARTED_BY_US=1
  for i in $(seq 1 20); do [ -S /run/php/php8.3-fpm.sock ] && break; sleep 0.3; done
  [ -S /run/php/php8.3-fpm.sock ] || { echo "php8.3-fpm did not come up"; exit 1; }
fi
cat > /etc/apache2/conf-available/uxhotfix-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q uxhotfix-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/uxhotfix-validate.conf <<CONF
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
a2ensite -q uxhotfix-validate
APACHE_SITE_ENABLED=1
apache2ctl configtest || { echo "REFUSING: apache2 config test failed"; exit 1; }
apache2ctl start
sleep 1
BASE="http://127.0.0.1:$HTTP_PORT"
curl -s -o /dev/null "$BASE/" || { echo "apache2 did not come up on port $HTTP_PORT"; cat "$WORKDIR/apache-error.log" 2>/dev/null; exit 1; }

FAIL=0
check() {
  local desc="$1" got="$2" want="$3"
  if [ "$got" = "$want" ]; then echo "PASS: $desc (got $got)"; else echo "FAIL: $desc (want $want, got $got)"; FAIL=1; fi
}
check_contains() {
  local desc="$1" body="$2" needle="$3"
  if printf '%s' "$body" | grep -qF -- "$needle"; then echo "PASS: $desc"; else echo "FAIL: $desc — did not find: $needle"; FAIL=1; fi
}

echo "--- 7/9: baseline sanity — api/app/ still forbidden, assets still 200, page still references only /api/assets/ ---"
check "api/app/config/config.php is forbidden" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/app/config/config.php")" 403
check "api/assets/js/receipt.js is 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/assets/js/receipt.js")" 200
check "api/assets/css/receipt.css is 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/assets/css/receipt.css")" 200
RECEIVE_PAGE=$(curl -s "$BASE/api/_receive/?token=$TOKEN")
check_contains "receipt page references the cache-busted receipt.css" "$RECEIVE_PAGE" "receipt.css?v="
check_contains "receipt page references the cache-busted receipt.js" "$RECEIVE_PAGE" "receipt.js?v="
VERSIONED_JS_URL=$(printf '%s' "$RECEIVE_PAGE" | grep -oE '/api/assets/js/receipt\.js\?v=[A-Za-z0-9._-]+' | head -1)
[ -n "$VERSIONED_JS_URL" ] && check "the exact versioned receipt.js URL is 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE$VERSIONED_JS_URL")" 200

echo "--- 8/9: REC-UX-09 — server-side discrepancy-photo validation UNCHANGED (raw multipart, bypassing the JS button entirely) ---"
SII=$(curl -s "$BASE/api/receive/$TOKEN" | php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach($j["data"]["shipments"] as $s){ if($s["shipmentId"]=='"$SHIPMENT_ID_PENDING"'){ echo $s["items"][0]["shipmentItemId"]; break; } }')
[ -n "$SII" ] || { echo "REFUSING: could not resolve shipmentItemId for the pending shipment"; FAIL=1; }
BLOCKED_CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/api/receive/$TOKEN/shipments/$SHIPMENT_ID_PENDING/confirm" \
  -H "Idempotency-Key: uxhotfix-block-$(date +%s)" \
  -F "receiverName=Toko Apache UX Test" \
  -F "items=[{\"shipmentItemId\":$SII,\"receivedGood\":4,\"reject\":1,\"shortage\":0}]")
check "REC-UX-09a: a discrepancy confirm WITHOUT any photo is still blocked (400) server-side" "$BLOCKED_CODE" 400
CONFIRM_JSON=$(curl -s -X POST "$BASE/api/receive/$TOKEN/shipments/$SHIPMENT_ID_PENDING/confirm" \
  -H "Idempotency-Key: uxhotfix-confirm-$(date +%s)" \
  -F "receiverName=Toko Apache UX Test" \
  -F "items=[{\"shipmentItemId\":$SII,\"receivedGood\":4,\"reject\":1,\"shortage\":0}]" \
  -F "evidence[]=@$EVIDENCE_PNG;type=image/png")
RECEIPT_ID=$(printf '%s' "$CONFIRM_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["receiptId"] ?? "";')
if [ -n "$RECEIPT_ID" ]; then
  echo "PASS: REC-UX-09b: a discrepancy confirm WITH a real photo still succeeds server-side (receiptId=$RECEIPT_ID)"
else
  echo "FAIL: REC-UX-09b: discrepancy+photo confirm did not succeed: $CONFIRM_JSON"
  FAIL=1
fi
# This SHIPMENT_ID_PENDING receipt is now consumed for REC-UX-09's own
# purpose (proving the server rule); the browser checks below use it in
# its now-pending-again... no: it is now confirmed, so the browser suite
# re-seeds its OWN fresh pending shipment via ux-hotfix-seed-extra.php
# below rather than reusing this one, keeping REC-UX-01..08 independent
# of REC-UX-09's side effects.

echo "--- 8b/9: seeding a THIRD, still-pending shipment dedicated to the browser (REC-UX-01..08) checks ---"
RESULT3=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
use Amor\Api\Production\ProductionService; use Amor\Api\Fg\FgService;
use Amor\Api\Dispatch\DispatchService; use Amor\Api\Dispatch\DepartureService;
Config::load();
$pdo = Database::pdo();
$doId = '"$DO_ID"';
$driverId = (int) $pdo->query("SELECT user_id FROM users WHERE username=\"uxhfav_driver\"")->fetchColumn();
$factoryId = '"$FACTORY_ID"';
$divisionId = '"$DIVISION_ID"';
$productId = '"$PRODUCT_ID"';
$tanggal = "2026-08-21";

$pdo->exec("INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES (\"$tanggal\", $factoryId, 1, UTC_TIMESTAMP())");
$bid = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES ($bid, $productId, NULL, 5, 0, 0)");
$iid = (int) $pdo->lastInsertId();
$storeId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name=\"P2 TEST STORE A\"")->fetchColumn();
$pdo->exec("INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES ($iid, $storeId, 5, 0)");

$doSvc = new \Amor\Api\Delivery\DoService($pdo);
$dto = $doSvc->createDraft($tanggal, $storeId, 1);
$doId3 = (int) $dto["doId"];

$prod = new ProductionService($pdo);
$run = $prod->createDraft($tanggal, $divisionId, $driverId);
$run = $prod->patchDraft((int) $run["productionRunId"], (int) $run["version"], [["productId" => $productId, "actualQty" => 5.0]], false, $driverId, "uxhfav-prod3-patch");
$prod->submit((int) $run["productionRunId"], (int) $run["version"], $driverId, "uxhfav-prod3-submit");

$fg = new FgService($pdo);
$batch = $fg->createDraft($tanggal, $factoryId, $driverId);
$batch = $fg->patchDraft((int) $batch["fgBatchId"], (int) $batch["version"], [["productId" => $productId, "fgVerified" => 5.0, "packed" => 5.0]], false, $driverId, "uxhfav-fg3-patch");
$fg->submit((int) $batch["fgBatchId"], (int) $batch["version"], $driverId, "uxhfav-fg3-submit");

$doItemId = (int) $pdo->query("SELECT delivery_order_item_id FROM delivery_order_item WHERE delivery_order_id = $doId3 AND product_id = $productId")->fetchColumn();
$dispatch = new DispatchService($pdo);
$claim = $dispatch->claim([["doItemId" => $doItemId, "qty" => 5.0]], $driverId, "uxhfav-claim3");
$claimId = $claim["claims"][0]["claimId"];
$doVersion = (int) $pdo->query("SELECT version FROM delivery_order WHERE delivery_order_id = $doId3")->fetchColumn();
$departure = new DepartureService($pdo);
$result = $departure->confirmDeparture($driverId, $doId3, $doVersion, "MAIN", [["claimId" => $claimId, "actualQty" => 5.0]], "uxhfav-depart3");
$shipmentId3 = (int) $result["shipments"][0]["shipmentId"];

$receiptSvc = new \Amor\Api\Dispatch\ReceiptService($pdo);
$token3 = $receiptSvc->getReceiptToken($doId3);
echo "$shipmentId3|$token3";
')
SHIPMENT_ID_BROWSER=$(echo "$RESULT3" | cut -d"|" -f1)
TOKEN_BROWSER=$(echo "$RESULT3" | cut -d"|" -f2)
[ -n "$SHIPMENT_ID_BROWSER" ] && [ -n "$TOKEN_BROWSER" ] || { echo "REFUSING: could not seed the browser-test shipment: $RESULT3"; exit 1; }
echo "SHIPMENT_ID_BROWSER=$SHIPMENT_ID_BROWSER TOKEN_BROWSER=$TOKEN_BROWSER"

echo "--- 9/9: REAL headless-Chromium, MOBILE viewport (375x667) — REC-UX-01..08 and REC-UX-10 ---"
cat > "$WORKDIR/rec-ux.js" <<'NODEEOF'
const { chromium } = require('playwright');
const fs = require('fs');
const base = process.argv[2];
const tokenBrowser = process.argv[3];
const tokenHistorical = process.argv[4];
const shipmentHistorical = process.argv[5];

const pngPath = process.argv[6];
let FAIL = 0;
function check(desc, cond) {
  if (cond) { console.log('PASS: ' + desc); } else { console.log('FAIL: ' + desc); FAIL = 1; }
}

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    args: ['--no-sandbox'],
  });
  const page = await browser.newPage({ viewport: { width: 375, height: 667 } });
  page.on('pageerror', err => console.log('  [pageerror]', err.message));

  await page.goto(base + '/api/_receive/?token=' + tokenBrowser);
  await page.waitForLoadState('networkidle');
  const btn = page.locator('button.rc-btn.primary');
  await btn.waitFor();

  check('REC-UX-01: clean receipt, valid totals, no photo -> button enabled', (await btn.isDisabled()) === false);

  await page.fill('[data-field=good]', '4');
  await page.fill('[data-field=reject]', '1');
  await page.waitForTimeout(150);
  check('REC-UX-02/06: discrepancy without photo immediately disables the button', (await btn.isDisabled()) === true);

  await page.setInputFiles('.rc-evidence-input', pngPath);
  await page.waitForTimeout(200);
  check('REC-UX-03/04: selecting a valid photo immediately enables the button', (await btn.isDisabled()) === false);

  const thumbBox = await page.locator('.rc-evidence-thumb').first().boundingBox();
  check('REC-UX-07: photo preview thumbnail is bounded (width<=150, height<=240) on mobile', thumbBox.width <= 150 && thumbBox.height <= 240);

  await page.setInputFiles('.rc-evidence-input', [pngPath, pngPath]);
  await page.waitForTimeout(200);
  const thumbCount = await page.locator('.rc-evidence-thumb').count();
  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
  check('REC-UX-08: multiple photos render (thumbCount=' + thumbCount + ') with NO horizontal overflow', thumbCount >= 2 && scrollWidth <= clientWidth);

  // Remove EVERY selected photo (there may be several, accumulated across
  // the REC-UX-08 multi-select step above) — REC-UX-05 is specifically
  // about the state once the LAST one is gone, so keep clicking remove
  // until none are left rather than assuming a fixed count.
  for (let guard = 0; guard < 10; guard++) {
    const remaining = await page.locator('.rc-evidence-remove').count();
    if (remaining === 0) break;
    await page.locator('.rc-evidence-remove').first().click();
    await page.waitForTimeout(100);
  }
  const thumbsLeft = await page.locator('.rc-evidence-thumb').count();
  check('REC-UX-05: removing the last photo IMMEDIATELY re-disables the button (thumbsLeft=' + thumbsLeft + ')', thumbsLeft === 0 && (await btn.isDisabled()) === true);

  await page.setInputFiles('.rc-evidence-input', pngPath);
  await page.fill('input[id^="receiver-name-"]', 'Tester REC-UX Apache');
  await page.waitForTimeout(150);
  check('REC-UX-04 (re-confirm): re-adding a photo re-enables the button', (await btn.isDisabled()) === false);
  await btn.click();
  await page.waitForTimeout(800);
  await page.waitForLoadState('networkidle');
  const bodyText = await page.textContent('body');
  check('submit succeeds end-to-end (page reflects Ada Selisih)', bodyText.includes('Ada Selisih'));

  await page.screenshot({ path: process.argv[7], fullPage: true });

  // REC-UX-10: the OTHER shipment on this DO's token — already confirmed
  // BEFORE this hotfix even ran — must render read-only/unchanged.
  await page.goto(base + '/api/_receive/?token=' + tokenHistorical);
  await page.waitForLoadState('networkidle');
  const historicalCardText = await page.textContent('body');
  check('REC-UX-10: historical shipment SHP-' + shipmentHistorical + ' card is present', historicalCardText.includes('SHP-' + shipmentHistorical));
  const historicalEditableInputs = await page.locator('.rc-card [data-field=good]').count();
  check('REC-UX-10: NO editable quantity inputs render for the fully-confirmed DO token page (all cards read-only)', historicalEditableInputs === 0);

  await browser.close();
  process.exit(FAIL);
})().catch((err) => {
  console.error('CRASHED:', err.stack);
  process.exit(1);
});
NODEEOF

EVIDENCE_PNG2="$WORKDIR/evidence-test.png"
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/rec-ux.js" "$BASE" "$TOKEN_BROWSER" "$TOKEN" "$SHIPMENT_ID_HISTORICAL" "$EVIDENCE_PNG2" "$WORKDIR/rec-ux-screenshot.png"
NODE_EXIT=$?
if [ "$NODE_EXIT" != "0" ]; then FAIL=1; fi
cp "$WORKDIR/rec-ux-screenshot.png" "$DIST_DIR/rec-ux-mobile-screenshot.png" 2>/dev/null || true

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + MOBILE BROWSER VALIDATION PASSED ==="
  echo "ZIP: $ZIP_PATH"
  echo "REC-UX-01..10 all confirmed against the SHIPPED, extracted package under real Apache + real headless Chromium."
  echo "Mobile screenshot saved to: $DIST_DIR/rec-ux-mobile-screenshot.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
