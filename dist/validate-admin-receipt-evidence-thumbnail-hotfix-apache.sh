#!/usr/bin/env bash
# Validates dist/amor-factory-admin-receipt-evidence-thumbnail-hotfix.zip
# against a REAL Apache + PHP-FPM server (not `php -S`, which silently
# ignores .htaccess), PLUS real headless-Chromium at THREE viewports
# (desktop, iPad/tablet, mobile) — thumbnail bounding, grid layout, and
# lightbox open/close cannot be verified by curl alone.
#
# Runs the ADM-PHOTO-01..10 checks named in the bug report:
#   ADM-PHOTO-01 single evidence photo renders as bounded thumbnail
#   ADM-PHOTO-02 thumbnail dimensions remain within expected bounds
#   ADM-PHOTO-03 multiple evidence photos render in responsive grid
#   ADM-PHOTO-04 click thumbnail opens bounded lightbox
#   ADM-PHOTO-05 lightbox closes correctly (close button, Escape, outside click)
#   ADM-PHOTO-06 no horizontal overflow on iPad/mobile viewport
#   ADM-PHOTO-07 admin receipt quantities/status unchanged
#   ADM-PHOTO-08 evidence viewer authorization unchanged
#   ADM-PHOTO-09 no DB writes caused by opening image/lightbox
#   ADM-PHOTO-10 full Phase 0-5.5 regression green (run separately via
#                api/tests/run-phase55-dispatch-receipt.sh, which this
#                script does not re-run — see the OUTPUT REPORT)
#
# Requires apache2 + php8.3-fpm + node/playwright (already present in
# this environment's validation harness). Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-admin-receipt-evidence-thumbnail-hotfix.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_admphoto_apachevalidate"
ADMIN_PASS="ApacheAdmPhotoAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassADMPHOTO_123"
RUNTIME_USER_PASS="ApacheRunPassADMPHOTO_123"
HTTP_PORT=8198
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q admphoto-validate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q admphoto-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/admphoto-validate.conf /etc/apache2/sites-enabled/admphoto-validate.conf
  rm -f /etc/apache2/conf-available/admphoto-listen.conf /etc/apache2/conf-enabled/admphoto-listen.conf
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
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-admin-receipt-evidence-thumbnail-hotfix.sh first"; exit 1; }

echo "--- 1/8: extracting the SHIPPED ZIP (never the source tree) ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
find "$WORKDIR" -type d -exec chmod 755 {} +
find "$EXTRACT_DIR" -type f -exec chmod 644 {} +
chown -R www-data:www-data "$EXTRACT_DIR/api/uploads"
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/8: initializing disposable MariaDB ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'admphotoav_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'admphotoav_migration_user'@'localhost';
CREATE USER 'admphotoav_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'admphotoav_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/8: migrate + seed + admin + master bootstrap ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'admphotoav_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" admphotoav_admin "AdmPhoto Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
php -r '
require "'"$REPO_ROOT"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
Config::load();
$pdo = Database::pdo();
$hash = password_hash("ApacheDriverPassADMPHOTO123", PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (username,password_hash,full_name,active,created_at) VALUES (\"admphotoav_driver\",?,\"AdmPhoto Apache Driver\",1,UTC_TIMESTAMP())")->execute([$hash]);
$pdo->exec("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT (SELECT user_id FROM users WHERE username=\"admphotoav_driver\"), role_id FROM roles WHERE code=\"DRIVER\"");
'
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/8: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'admphotoav_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/8: seeding a real shipment with 3 evidence photos (real DO->Production->FG->claim->depart->confirm flow) ---"
DIVISION_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT division_id FROM $DB_NAME.division WHERE name='Roti & Bollen'")
FACTORY_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT factory_id FROM $DB_NAME.division WHERE division_id=$DIVISION_ID")
PRODUCT_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT product_id FROM $DB_NAME.product WHERE division_id=$DIVISION_ID ORDER BY product_id LIMIT 1")
STORE_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")
TANGGAL="2026-08-22"
mariadb --socket="$SOCK" -u root -e "
INSERT INTO $DB_NAME.po_batch (tanggal, factory_id, version, created_at) VALUES ('$TANGGAL', $FACTORY_ID, 1, UTC_TIMESTAMP());
SET @bid = LAST_INSERT_ID();
INSERT INTO $DB_NAME.po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (@bid, $PRODUCT_ID, NULL, 5, 0, 0);
SET @iid = LAST_INSERT_ID();
INSERT INTO $DB_NAME.po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (@iid, $STORE_ID, 5, 0);
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

$driverId = (int) $pdo->query("SELECT user_id FROM users WHERE username=\"admphotoav_driver\"")->fetchColumn();
$prod = new ProductionService($pdo);
$run = $prod->createDraft("'"$TANGGAL"'", '"$DIVISION_ID"', $driverId);
$run = $prod->patchDraft((int) $run["productionRunId"], (int) $run["version"], [["productId" => '"$PRODUCT_ID"', "actualQty" => 5.0]], false, $driverId, "admphotoav-prod-patch");
$prod->submit((int) $run["productionRunId"], (int) $run["version"], $driverId, "admphotoav-prod-submit");

$fg = new FgService($pdo);
$batch = $fg->createDraft("'"$TANGGAL"'", '"$FACTORY_ID"', $driverId);
$batch = $fg->patchDraft((int) $batch["fgBatchId"], (int) $batch["version"], [["productId" => '"$PRODUCT_ID"', "fgVerified" => 5.0, "packed" => 5.0]], false, $driverId, "admphotoav-fg-patch");
$fg->submit((int) $batch["fgBatchId"], (int) $batch["version"], $driverId, "admphotoav-fg-submit");

$doItemId = (int) $pdo->query("SELECT delivery_order_item_id FROM delivery_order_item WHERE delivery_order_id = $doId AND product_id = '"$PRODUCT_ID"'")->fetchColumn();
$dispatch = new DispatchService($pdo);
$claim = $dispatch->claim([["doItemId" => $doItemId, "qty" => 5.0]], $driverId, "admphotoav-claim");
$claimId = $claim["claims"][0]["claimId"];

$doVersion = (int) $pdo->query("SELECT version FROM delivery_order WHERE delivery_order_id = $doId")->fetchColumn();
$departure = new DepartureService($pdo);
$result = $departure->confirmDeparture($driverId, $doId, $doVersion, "MAIN", [["claimId" => $claimId, "actualQty" => 5.0]], "admphotoav-depart");
$shipmentId = (int) $result["shipments"][0]["shipmentId"];

$receiptSvc = new ReceiptService($pdo);
$token = $receiptSvc->getReceiptToken($doId);
echo "$shipmentId|$token";
')
SHIPMENT_ID=$(echo "$RESULT" | cut -d"|" -f1)
TOKEN=$(echo "$RESULT" | cut -d"|" -f2)
[ -n "$SHIPMENT_ID" ] && [ -n "$TOKEN" ] || { echo "REFUSING: could not seed the fixture shipment: $RESULT"; exit 1; }
echo "SHIPMENT_ID=$SHIPMENT_ID TOKEN=$TOKEN"

echo "--- 6/8: starting REAL php8.3-fpm + REAL apache2 (AllowOverride All, like real cPanel) ---"
mkdir -p /run/php
chown www-data:www-data /run/php
if [ ! -S /run/php/php8.3-fpm.sock ]; then
  /usr/sbin/php-fpm8.3 -D --fpm-config /etc/php/8.3/fpm/php-fpm.conf
  FPM_STARTED_BY_US=1
  for i in $(seq 1 20); do [ -S /run/php/php8.3-fpm.sock ] && break; sleep 0.3; done
  [ -S /run/php/php8.3-fpm.sock ] || { echo "php8.3-fpm did not come up"; exit 1; }
fi
cat > /etc/apache2/conf-available/admphoto-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q admphoto-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/admphoto-validate.conf <<CONF
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
a2ensite -q admphoto-validate
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
check_not_contains() {
  local desc="$1" body="$2" needle="$3"
  if printf '%s' "$body" | grep -qF -- "$needle"; then echo "FAIL: $desc — unexpectedly found: $needle"; FAIL=1; else echo "PASS: $desc"; fi
}

echo "--- 7/8: multipart-upload 3 real evidence photos, then log in as Admin (real cookie session) ---"
EVIDENCE_PNG="$WORKDIR/evidence-test.png"
base64 -d > "$EVIDENCE_PNG" <<'PNGB64'
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==
PNGB64
SII=$(curl -s "$BASE/api/receive/$TOKEN" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["shipments"][0]["items"][0]["shipmentItemId"];')
[ -n "$SII" ] || { echo "REFUSING: could not resolve shipmentItemId"; FAIL=1; }
CONFIRM_JSON=$(curl -s -X POST "$BASE/api/receive/$TOKEN/shipments/$SHIPMENT_ID/confirm" \
  -H "Idempotency-Key: admphoto-confirm-$(date +%s)" \
  -F "receiverName=Toko Apache AdmPhoto Test" \
  -F "items=[{\"shipmentItemId\":$SII,\"receivedGood\":4,\"reject\":1,\"shortage\":0}]" \
  -F "evidence[0]=@$EVIDENCE_PNG;type=image/png" \
  -F "evidence[1]=@$EVIDENCE_PNG;type=image/png" \
  -F "evidence[2]=@$EVIDENCE_PNG;type=image/png")
RECEIPT_ID=$(printf '%s' "$CONFIRM_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["receiptId"] ?? "";')
if [ -z "$RECEIPT_ID" ]; then
  echo "FAIL: the 3-photo discrepancy confirm did not succeed: $CONFIRM_JSON"
  FAIL=1
else
  echo "PASS: seeded a real receipt with 3 evidence photos (receiptId=$RECEIPT_ID)"
fi

ADMIN_JAR="$WORKDIR/admin_cookies.txt"
DRIVER_JAR="$WORKDIR/driver_cookies.txt"
curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d "{\"username\":\"admphotoav_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
curl -s -c "$DRIVER_JAR" -b "$DRIVER_JAR" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d '{"username":"admphotoav_driver","password":"ApacheDriverPassADMPHOTO123"}' > /dev/null

DETAIL_PAGE=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/_ui-preview/?page=konfirmasi-toko-detail&shipmentId=$SHIPMENT_ID")
check_contains "Admin detail page references the cache-busted app.css" "$DETAIL_PAGE" "app.css?v="
check_contains "Admin detail page references the cache-busted app.js" "$DETAIL_PAGE" "app.js?v="
VERSIONED_JS_URL=$(printf '%s' "$DETAIL_PAGE" | grep -oE '/api/assets/js/app\.js\?v=[A-Za-z0-9._-]+' | head -1)
[ -n "$VERSIONED_JS_URL" ] && check "the exact versioned app.js URL is 200" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE$VERSIONED_JS_URL")" 200
check_contains "Admin detail page shows Bukti Foto dari Toko" "$DETAIL_PAGE" "Bukti Foto dari Toko"
check_not_contains "Admin detail page NEVER opens evidence via target=_blank (raw-image nav)" "$DETAIL_PAGE" 'target="_blank"'
DRIVER_EVIDENCE_CODE=$(curl -s -o /dev/null -w '%{http_code}' -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/api/admin/receipts/evidence/1")
check "GET /api/admin/receipts/evidence/{id} refuses a non-admin (Driver) caller" "$DRIVER_EVIDENCE_CODE" 403
ADMIN_EVIDENCE_CODE=$(curl -s -o /dev/null -w '%{http_code}' -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/admin/receipts/evidence/1")
check "GET /api/admin/receipts/evidence/{id} still works for Admin" "$ADMIN_EVIDENCE_CODE" 200

echo "--- 8/8: REAL headless-Chromium at THREE viewports (desktop / iPad-tablet / mobile) — ADM-PHOTO-01..06,09 ---"
cat > "$WORKDIR/adm-photo.js" <<'NODEEOF'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const url = process.argv[5]; // detail page URL
const shotPrefix = process.argv[6];

let FAIL = 0;
function check(desc, cond) { if (cond) { console.log('PASS: ' + desc); } else { console.log('FAIL: ' + desc); FAIL = 1; } }

// Logs in FOR REAL inside the browser context itself (a fetch() POST to
// the real /api/auth/login, from a page in this context) instead of
// importing a curl-generated Netscape cookie jar — the browser then sets
// its own session cookie exactly as it would for a real Admin, with none
// of the cross-tool cookie-format edge cases (HttpOnly-prefixed domain
// fields, leading dots, etc.) that a hand-parsed jar file runs into.
async function loginAsAdmin(context, base, username, password) {
  const page = await context.newPage();
  await page.goto(base + '/');
  await page.evaluate(async ({ base, username, password }) => {
    await fetch(base + '/api/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
  }, { base, username, password });
  await page.close();
}

const VIEWPORTS = [
  { name: 'desktop', width: 1280, height: 900, maxThumb: 130 },
  { name: 'ipad', width: 768, height: 1024, maxThumb: 130 },
  { name: 'mobile', width: 375, height: 667, maxThumb: 130 },
];

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    args: ['--no-sandbox'],
  });

  for (const vp of VIEWPORTS) {
    const context = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    await loginAsAdmin(context, base, adminUsername, adminPassword);
    const page = await context.newPage();
    page.on('pageerror', err => console.log('  [pageerror:' + vp.name + ']', err.message));

    await page.goto(url);
    await page.waitForLoadState('networkidle');

    const thumbCount = await page.locator('.evidence-thumb').count();
    check('ADM-PHOTO-01/03 [' + vp.name + ']: 3 evidence thumbnails render', thumbCount === 3);

    const thumbBox = await page.locator('.evidence-thumb').first().boundingBox();
    check('ADM-PHOTO-02 [' + vp.name + ']: thumbnail bounded (<=' + vp.maxThumb + 'px both dimensions, got ' + JSON.stringify(thumbBox) + ')', thumbBox && thumbBox.width <= vp.maxThumb && thumbBox.height <= vp.maxThumb);

    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    check('ADM-PHOTO-06 [' + vp.name + ']: no horizontal overflow (scrollWidth=' + scrollWidth + ' clientWidth=' + clientWidth + ')', scrollWidth <= clientWidth);

    // ADM-PHOTO-04: click opens a bounded lightbox, never raw navigation.
    const urlBefore = page.url();
    await page.locator('.evidence-thumb').first().click();
    await page.waitForTimeout(200);
    const lightboxVisible = await page.locator('.image-lightbox-backdrop.open').count();
    check('ADM-PHOTO-04 [' + vp.name + ']: clicking a thumbnail opens the lightbox (no navigation away)', lightboxVisible === 1 && page.url() === urlBefore);
    if (lightboxVisible === 1) {
      const lbBox = await page.locator('.image-lightbox').boundingBox();
      const vw = vp.width, vh = vp.height;
      check('ADM-PHOTO-04 [' + vp.name + ']: lightbox bounded to <=90vw/<=80vh (got ' + JSON.stringify(lbBox) + ', vw=' + vw + ' vh=' + vh + ')', lbBox && lbBox.width <= vw * 0.9 + 2 && lbBox.height <= vh * 0.8 + 2);
    }
    if (vp.name === 'desktop') {
      await page.screenshot({ path: shotPrefix + '-desktop-lightbox.png', fullPage: true });
    }

    // ADM-PHOTO-05a: close button.
    if (lightboxVisible === 1) {
      await page.locator('.image-lightbox-close').click();
      await page.waitForTimeout(150);
      check('ADM-PHOTO-05a [' + vp.name + ']: close button closes the lightbox', (await page.locator('.image-lightbox-backdrop.open').count()) === 0);
    }

    // ADM-PHOTO-05b: Escape key.
    await page.locator('.evidence-thumb').first().click();
    await page.waitForTimeout(150);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(150);
    check('ADM-PHOTO-05b [' + vp.name + ']: Escape closes the lightbox', (await page.locator('.image-lightbox-backdrop.open').count()) === 0);

    // ADM-PHOTO-05c: click-outside (backdrop) close.
    await page.locator('.evidence-thumb').first().click();
    await page.waitForTimeout(150);
    await page.mouse.click(5, 5);
    await page.waitForTimeout(150);
    check('ADM-PHOTO-05c [' + vp.name + ']: click-outside closes the lightbox', (await page.locator('.image-lightbox-backdrop.open').count()) === 0);

    if (vp.name === 'mobile') {
      await page.screenshot({ path: shotPrefix + '-mobile.png', fullPage: true });
    }
    if (vp.name === 'ipad') {
      await page.screenshot({ path: shotPrefix + '-ipad.png', fullPage: true });
    }

    await context.close();
  }

  await browser.close();
  process.exit(FAIL);
})().catch((err) => {
  console.error('CRASHED:', err.stack);
  process.exit(1);
});
NODEEOF

DETAIL_URL="$BASE/api/_ui-preview/?page=konfirmasi-toko-detail&shipmentId=$SHIPMENT_ID"
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/adm-photo.js" "$BASE" "admphotoav_admin" "$ADMIN_PASS" "$DETAIL_URL" "$WORKDIR/adm-photo"
NODE_EXIT=$?
if [ "$NODE_EXIT" != "0" ]; then FAIL=1; fi
cp "$WORKDIR/adm-photo-desktop-lightbox.png" "$DIST_DIR/adm-photo-desktop-lightbox-screenshot.png" 2>/dev/null || true
cp "$WORKDIR/adm-photo-ipad.png" "$DIST_DIR/adm-photo-ipad-screenshot.png" 2>/dev/null || true
cp "$WORKDIR/adm-photo-mobile.png" "$DIST_DIR/adm-photo-mobile-screenshot.png" 2>/dev/null || true

echo ""
echo "--- ADM-PHOTO-09: no DB writes from opening the detail page / evidence image (re-check after the whole browser run above) ---"
EVID_COUNT_AFTER=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.shipment_receipt_evidence WHERE shipment_receipt_id=$RECEIPT_ID")
check "evidence row count is still exactly 3 after all viewing/lightbox interactions" "$EVID_COUNT_AFTER" 3
ITEM_ROW=$(mariadb --socket="$SOCK" -u root -N -e "SELECT CONCAT(received_good_qty,':',reject_qty,':',shortage_qty) FROM $DB_NAME.shipment_receipt_item WHERE shipment_receipt_id=$RECEIPT_ID")
check "receipt item quantities unchanged after all viewing/lightbox interactions (4:1:0)" "$ITEM_ROW" "4.00:1.00:0.00"

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + DESKTOP/TABLET/MOBILE BROWSER VALIDATION PASSED ==="
  echo "ZIP: $ZIP_PATH"
  echo "ADM-PHOTO-01..09 all confirmed against the SHIPPED, extracted package under real Apache + real headless Chromium at 3 viewports."
  echo "Screenshots saved to: $DIST_DIR/adm-photo-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
