#!/usr/bin/env bash
# Validates dist/amor-factory-admin-detail-typography-hotfix.zip against
# a REAL Apache + PHP-FPM server, PLUS real headless-Chromium at THREE
# viewports (desktop, iPad/tablet, mobile) — computed font-size, email
# wrapping and no-overflow cannot be verified by curl alone.
#
# Runs the ADM-TYPE-01..10 checks named in the bug report:
#   ADM-TYPE-01 Email status value does not exceed intended detail font size
#   ADM-TYPE-02 long email address stays inside its card
#   ADM-TYPE-03 attempt count uses detail typography, not KPI typography
#   ADM-TYPE-04 confirmation date/time does not render as oversized headline
#   ADM-TYPE-05 receipt status uses normalized detail typography
#   ADM-TYPE-06/07/08 no horizontal overflow at 1280 desktop / 768x1024
#                      iPad / 375x667 mobile
#   ADM-TYPE-09 evidence thumbnail/lightbox from the previous hotfix
#               remains correct
#   ADM-TYPE-10 full regression green (run separately via
#               api/tests/run-phase55-dispatch-receipt.sh — see the
#               OUTPUT REPORT, not re-run by this script)
#
# Requires apache2 + php8.3-fpm + node/playwright. Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-admin-detail-typography-hotfix.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_admtype_apachevalidate"
ADMIN_PASS="ApacheAdmTypeAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassADMTYPE_123"
RUNTIME_USER_PASS="ApacheRunPassADMTYPE_123"
HTTP_PORT=8199
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q admtype-validate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q admtype-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/admtype-validate.conf /etc/apache2/sites-enabled/admtype-validate.conf
  rm -f /etc/apache2/conf-available/admtype-listen.conf /etc/apache2/conf-enabled/admtype-listen.conf
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
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-admin-detail-typography-hotfix.sh first"; exit 1; }

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
CREATE USER 'admtypeav_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'admtypeav_migration_user'@'localhost';
CREATE USER 'admtypeav_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'admtypeav_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/8: migrate + seed + admin + master bootstrap ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'admtypeav_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" admtypeav_admin "AdmType Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
php -r '
require "'"$REPO_ROOT"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
Config::load();
$pdo = Database::pdo();
$hash = password_hash("ApacheDriverPassADMTYPE123", PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (username,password_hash,full_name,active,created_at) VALUES (\"admtypeav_driver\",?,\"AdmType Apache Driver\",1,UTC_TIMESTAMP())")->execute([$hash]);
$pdo->exec("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT (SELECT user_id FROM users WHERE username=\"admtypeav_driver\"), role_id FROM roles WHERE code=\"DRIVER\"");
'
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/8: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'admtypeav_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/8: seeding a real shipment with a long email + evidence photo + discrepancy status ---"
DIVISION_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT division_id FROM $DB_NAME.division WHERE name='Roti & Bollen'")
FACTORY_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT factory_id FROM $DB_NAME.division WHERE division_id=$DIVISION_ID")
PRODUCT_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT product_id FROM $DB_NAME.product WHERE division_id=$DIVISION_ID ORDER BY product_id LIMIT 1")
STORE_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")
TANGGAL="2026-08-23"
LONG_EMAIL="konfirmasi.penerimaan.toko.karangtengah.cabang.utama@amorcakesandbakeryindonesia.example.test"
mariadb --socket="$SOCK" -u root -e "
UPDATE $DB_NAME.store SET email = '$LONG_EMAIL' WHERE store_id = $STORE_ID;
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

$driverId = (int) $pdo->query("SELECT user_id FROM users WHERE username=\"admtypeav_driver\"")->fetchColumn();
$prod = new ProductionService($pdo);
$run = $prod->createDraft("'"$TANGGAL"'", '"$DIVISION_ID"', $driverId);
$run = $prod->patchDraft((int) $run["productionRunId"], (int) $run["version"], [["productId" => '"$PRODUCT_ID"', "actualQty" => 5.0]], false, $driverId, "admtypeav-prod-patch");
$prod->submit((int) $run["productionRunId"], (int) $run["version"], $driverId, "admtypeav-prod-submit");

$fg = new FgService($pdo);
$batch = $fg->createDraft("'"$TANGGAL"'", '"$FACTORY_ID"', $driverId);
$batch = $fg->patchDraft((int) $batch["fgBatchId"], (int) $batch["version"], [["productId" => '"$PRODUCT_ID"', "fgVerified" => 5.0, "packed" => 5.0]], false, $driverId, "admtypeav-fg-patch");
$fg->submit((int) $batch["fgBatchId"], (int) $batch["version"], $driverId, "admtypeav-fg-submit");

$doItemId = (int) $pdo->query("SELECT delivery_order_item_id FROM delivery_order_item WHERE delivery_order_id = $doId AND product_id = '"$PRODUCT_ID"'")->fetchColumn();
$dispatch = new DispatchService($pdo);
$claim = $dispatch->claim([["doItemId" => $doItemId, "qty" => 5.0]], $driverId, "admtypeav-claim");
$claimId = $claim["claims"][0]["claimId"];

$doVersion = (int) $pdo->query("SELECT version FROM delivery_order WHERE delivery_order_id = $doId")->fetchColumn();
$departure = new DepartureService($pdo);
$result = $departure->confirmDeparture($driverId, $doId, $doVersion, "MAIN", [["claimId" => $claimId, "actualQty" => 5.0]], "admtypeav-depart");
$shipmentId = (int) $result["shipments"][0]["shipmentId"];

$receiptSvc = new ReceiptService($pdo);
$token = $receiptSvc->getReceiptToken($doId);
echo "$shipmentId|$token";
')
SHIPMENT_ID=$(echo "$RESULT" | cut -d"|" -f1)
TOKEN=$(echo "$RESULT" | cut -d"|" -f2)
[ -n "$SHIPMENT_ID" ] && [ -n "$TOKEN" ] || { echo "REFUSING: could not seed the fixture shipment: $RESULT"; exit 1; }
echo "SHIPMENT_ID=$SHIPMENT_ID TOKEN=$TOKEN LONG_EMAIL=$LONG_EMAIL"

echo "--- 6/8: starting REAL php8.3-fpm + REAL apache2 (AllowOverride All, like real cPanel) ---"
mkdir -p /run/php
chown www-data:www-data /run/php
if [ ! -S /run/php/php8.3-fpm.sock ]; then
  /usr/sbin/php-fpm8.3 -D --fpm-config /etc/php/8.3/fpm/php-fpm.conf
  FPM_STARTED_BY_US=1
  for i in $(seq 1 20); do [ -S /run/php/php8.3-fpm.sock ] && break; sleep 0.3; done
  [ -S /run/php/php8.3-fpm.sock ] || { echo "php8.3-fpm did not come up"; exit 1; }
fi
cat > /etc/apache2/conf-available/admtype-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q admtype-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/admtype-validate.conf <<CONF
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
a2ensite -q admtype-validate
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

echo "--- 7/8: submit a discrepancy receipt with a photo + long email already on file, via the real multipart route ---"
EVIDENCE_PNG="$WORKDIR/evidence-test.png"
base64 -d > "$EVIDENCE_PNG" <<'PNGB64'
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==
PNGB64
SII=$(curl -s "$BASE/api/receive/$TOKEN" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["shipments"][0]["items"][0]["shipmentItemId"];')
[ -n "$SII" ] || { echo "REFUSING: could not resolve shipmentItemId"; FAIL=1; }
CONFIRM_JSON=$(curl -s -X POST "$BASE/api/receive/$TOKEN/shipments/$SHIPMENT_ID/confirm" \
  -H "Idempotency-Key: admtype-confirm-$(date +%s)" \
  -F "receiverName=Toko Apache AdmType Test" \
  -F "items=[{\"shipmentItemId\":$SII,\"receivedGood\":4,\"reject\":1,\"shortage\":0}]" \
  -F "evidence[0]=@$EVIDENCE_PNG;type=image/png")
RECEIPT_ID=$(printf '%s' "$CONFIRM_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["receiptId"] ?? "";')
if [ -z "$RECEIPT_ID" ]; then
  echo "FAIL: the discrepancy confirm did not succeed: $CONFIRM_JSON"
  FAIL=1
else
  echo "PASS: seeded a real discrepancy receipt with evidence (receiptId=$RECEIPT_ID)"
fi

echo "--- 8/8: REAL headless-Chromium at THREE viewports (desktop / iPad-tablet / mobile) — ADM-TYPE-01..09 ---"
cat > "$WORKDIR/adm-type.js" <<'NODEEOF'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const detailUrl = process.argv[5];
const dashboardUrl = process.argv[6];
const longEmail = process.argv[7];

let FAIL = 0;
function check(desc, cond) { if (cond) { console.log('PASS: ' + desc); } else { console.log('FAIL: ' + desc); FAIL = 1; } }

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
  { name: 'desktop', width: 1280, height: 900 },
  { name: 'ipad', width: 768, height: 1024 },
  { name: 'mobile', width: 375, height: 667 },
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

    await page.goto(detailUrl);
    await page.waitForLoadState('networkidle');

    // ADM-TYPE-01/03/04/05: every .kpi-card--detail .kpi-value computes
    // to the normalized 18px/700 size, never the 28px/800 KPI headline.
    const detailValues = await page.locator('.kpi-card--detail .kpi-value').all();
    check('ADM-TYPE-01/03/04/05 [' + vp.name + ']: exactly 10 detail-typography values render', detailValues.length === 10);
    let allNormalized = true;
    for (const el of detailValues) {
      const style = await el.evaluate(e => { const s = getComputedStyle(e); return { fontSize: parseFloat(s.fontSize), fontWeight: s.fontWeight }; });
      if (style.fontSize > 23 || Number(style.fontWeight) >= 800) allNormalized = false;
    }
    check('ADM-TYPE-01/03/04/05 [' + vp.name + ']: all detail values are <=23px and NOT weight 800 (never Dashboard-KPI sized)', allNormalized);

    // ADM-TYPE-02: the long email value never overflows its own card.
    const emailCard = page.locator('.kpi-card--detail').filter({ hasText: longEmail.split('@')[0] }).first();
    const emailCardBox = await emailCard.boundingBox();
    const emailValueBox = await emailCard.locator('.kpi-value').boundingBox();
    check('ADM-TYPE-02 [' + vp.name + ']: long email value stays within its card width (value=' + JSON.stringify(emailValueBox) + ' card=' + JSON.stringify(emailCardBox) + ')', emailValueBox && emailCardBox && emailValueBox.width <= emailCardBox.width + 1);

    // ADM-TYPE-06/07/08: no horizontal overflow at this viewport.
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    check('ADM-TYPE-06/07/08 [' + vp.name + ']: no horizontal overflow (scrollWidth=' + scrollWidth + ' clientWidth=' + clientWidth + ')', scrollWidth <= clientWidth);

    // ADM-TYPE-09: the evidence-thumbnail hotfix still works.
    const thumbCount = await page.locator('.evidence-thumb').count();
    check('ADM-TYPE-09 [' + vp.name + ']: evidence thumbnail still renders bounded', thumbCount >= 1);
    if (thumbCount >= 1) {
      const thumbBox = await page.locator('.evidence-thumb').first().boundingBox();
      check('ADM-TYPE-09 [' + vp.name + ']: evidence thumbnail still bounded (<=130px), got ' + JSON.stringify(thumbBox), thumbBox && thumbBox.width <= 130 && thumbBox.height <= 130);
    }

    // Verifikasi Selisih button still present/enabled (business logic untouched).
    const verifyBtnCount = await page.locator('#btn-verify-receipt').count();
    check('ADM-TYPE [' + vp.name + ']: Verifikasi Selisih button still present', verifyBtnCount === 1);

    if (vp.name === 'desktop') {
      await page.screenshot({ path: process.argv[8] + '-desktop.png', fullPage: true });
    }
    if (vp.name === 'ipad') {
      await page.screenshot({ path: process.argv[8] + '-ipad.png', fullPage: true });
    }
    if (vp.name === 'mobile') {
      await page.screenshot({ path: process.argv[8] + '-mobile.png', fullPage: true });
    }

    await context.close();
  }

  // Regression guard: the Dashboard's REAL KPI numbers must stay at the
  // full 28px/800 headline size — this hotfix must never touch them.
  const dashContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await loginAsAdmin(dashContext, base, adminUsername, adminPassword);
  const dashPage = await dashContext.newPage();
  await dashPage.goto(dashboardUrl);
  await dashPage.waitForLoadState('networkidle');
  const dashDetailCount = await dashPage.locator('.kpi-card--detail').count();
  check('regression guard: Dashboard has ZERO .kpi-card--detail (its KPIs keep the full headline size)', dashDetailCount === 0);
  const dashKpiValue = await dashPage.locator('.kpi-card .kpi-value').first().evaluate(e => parseFloat(getComputedStyle(e).fontSize));
  check('regression guard: Dashboard\'s real KPI value is still ~28px (got ' + dashKpiValue + 'px)', dashKpiValue >= 26);
  await dashContext.close();

  await browser.close();
  process.exit(FAIL);
})().catch((err) => {
  console.error('CRASHED:', err.stack);
  process.exit(1);
});
NODEEOF

DETAIL_URL="$BASE/api/_ui-preview/?page=konfirmasi-toko-detail&shipmentId=$SHIPMENT_ID"
DASHBOARD_URL="$BASE/api/_ui-preview/?page=dashboard"
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/adm-type.js" "$BASE" "admtypeav_admin" "$ADMIN_PASS" "$DETAIL_URL" "$DASHBOARD_URL" "$LONG_EMAIL" "$WORKDIR/adm-type"
NODE_EXIT=$?
if [ "$NODE_EXIT" != "0" ]; then FAIL=1; fi
cp "$WORKDIR/adm-type-desktop.png" "$DIST_DIR/adm-type-desktop-screenshot.png" 2>/dev/null || true
cp "$WORKDIR/adm-type-ipad.png" "$DIST_DIR/adm-type-ipad-screenshot.png" 2>/dev/null || true
cp "$WORKDIR/adm-type-mobile.png" "$DIST_DIR/adm-type-mobile-screenshot.png" 2>/dev/null || true

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + DESKTOP/TABLET/MOBILE BROWSER VALIDATION PASSED ==="
  echo "ZIP: $ZIP_PATH"
  echo "ADM-TYPE-01..09 all confirmed against the SHIPPED, extracted package under real Apache + real headless Chromium at 3 viewports."
  echo "Screenshots saved to: $DIST_DIR/adm-type-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
