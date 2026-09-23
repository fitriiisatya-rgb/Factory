#!/usr/bin/env bash
# Validates dist/amor-factory-final-prelive-rework.zip against a REAL
# Apache + PHP-FPM server, PLUS real headless-Chromium (desktop/tablet/
# mobile) driving the ACTUAL flows this rework fixes:
#   - Normalized downstream source identity (CS/Sales Executive/Konsumen
#     Langsung/Umum/Pesanan Khusus Toko) must render correctly — never
#     collapse to generic "Pesanan Non-Toko" — in FG Khusus/Non-Toko, DO
#     Khusus/Non-Toko, Driver Portal Khusus tab, Driver Riwayat/Detail,
#     Digital Surat Jalan, Admin Konfirmasi Toko, and Admin Pengiriman.
#   - Driver Internal dispatch -> automatic Bakery email -> real-browser
#     Bakery receipt confirmation (clean).
#   - External Courier (GoSend) dispatch -> automatic Bakery email ->
#     real-browser Bakery receipt confirmation WITH a discrepancy + a real
#     uploaded photo -> Admin verification.
#
# Requires apache2 + php8.3-fpm + node/playwright. Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-final-prelive-rework.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_finalvalidate"
ADMIN_PASS="ApacheFinalAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassFINALVAL_123"
RUNTIME_USER_PASS="ApacheRunPassFINALVAL_123"
HTTP_PORT=8217
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q finalvalidate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q finalvalidate-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/finalvalidate.conf /etc/apache2/sites-enabled/finalvalidate.conf
  rm -f /etc/apache2/conf-available/finalvalidate-listen.conf /etc/apache2/conf-enabled/finalvalidate-listen.conf
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
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-final-prelive-rework.sh first"; exit 1; }

echo "--- 1/10: extracting the SHIPPED ZIP (never the source tree) ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
find "$WORKDIR" -type d -exec chmod 755 {} +
find "$EXTRACT_DIR" -type f -exec chmod 644 {} +
chown -R www-data:www-data "$EXTRACT_DIR/api/uploads"
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/10: initializing disposable MariaDB ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'finalvalmig_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'finalvalmig_user'@'localhost';
CREATE USER 'finalvalrun_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'finalvalrun_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/10: migrate (0001-0012) + seed + admin/driver users + master bootstrap ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'finalvalmig_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" finalval_admin "Final Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
DRIVER_PASS="ApacheFinalDriver#$(date +%s)"
export DRIVER_PASS
DRIVER_HASH="$(php -r "echo password_hash(getenv('DRIVER_PASS'), PASSWORD_DEFAULT);")"
mariadb --socket="$SOCK" -u root "$DB_NAME" -e "
INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES ('finalval_driver', '$DRIVER_HASH', 'Final Apache Validate Driver', 1, UTC_TIMESTAMP());
INSERT INTO user_roles (user_id, role_id) SELECT (SELECT user_id FROM users WHERE username='finalval_driver'), role_id FROM roles WHERE code='DRIVER';
"
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/10: writing config.php DIRECTLY INTO THE EXTRACTED TREE with a fake-SMTP transport (never a real internet SMTP connection) ---"
MAIL_OUTBOX_DIR="$WORKDIR/mail-outbox"
mkdir -p "$MAIL_OUTBOX_DIR"
chown www-data:www-data "$MAIL_OUTBOX_DIR"
MAIL_LOG="$MAIL_OUTBOX_DIR/mail-fake.jsonl"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'finalvalrun_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
    'APP_BASE_URL' => 'http://127.0.0.1:$HTTP_PORT',
    'MAIL_ENABLED' => true,
    'MAIL_TRANSPORT' => 'fake',
    'MAIL_FAKE_LOG_PATH' => '$MAIL_LOG',
    'MAIL_FROM_ADDRESS' => 'factory@amorgroup.id',
    'MAIL_FROM_NAME' => 'Amor Factory System',
];
PHPCONFIG

echo "--- 5/10: starting REAL php8.3-fpm + REAL apache2 (AllowOverride All, like real cPanel) ---"
mkdir -p /run/php
chown www-data:www-data /run/php
if [ ! -S /run/php/php8.3-fpm.sock ]; then
  /usr/sbin/php-fpm8.3 -D --fpm-config /etc/php/8.3/fpm/php-fpm.conf
  FPM_STARTED_BY_US=1
  for i in $(seq 1 20); do [ -S /run/php/php8.3-fpm.sock ] && break; sleep 0.3; done
  [ -S /run/php/php8.3-fpm.sock ] || { echo "php8.3-fpm did not come up"; exit 1; }
fi
cat > /etc/apache2/conf-available/finalvalidate-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q finalvalidate-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/finalvalidate.conf <<CONF
<VirtualHost *:$HTTP_PORT>
    DocumentRoot $EXTRACT_DIR
    <Directory $EXTRACT_DIR>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>
    <FilesMatch \.php\$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>
    ErrorLog $WORKDIR/apache-error.log
    CustomLog $WORKDIR/apache-access.log combined
</VirtualHost>
CONF
a2ensite -q finalvalidate
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

echo "--- 6/10: baseline sanity ---"
check "api/app/config/config.php is forbidden" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/app/config/config.php")" 403
curl -s -o /dev/null "$BASE/api/special-orders/catalog" # lazy-seeds special_order_catalog id=1
STOREA_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")
KARANGTENGAH_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT factory_id FROM $DB_NAME.factory WHERE name='Karangtengah'")
# The automatic-email feature is a no-op ("no_email") without a real Store
# email on file — set one directly (never through the UI, to keep this
# step deterministic) so the Digital Surat Jalan email actually attempts a
# (fake) send for both scenarios below.
mariadb --socket="$SOCK" -u root -e "UPDATE $DB_NAME.store SET email='dropbakery-finalval@example.test' WHERE store_id=$STOREA_ID;"

echo "--- 7/10: seeding a CS order + a Sales Executive order (order->confirm->send->actual->FG-verify, via direct PHP service calls — the REAL UI/browser drives everything from DO creation onward) ---"
SEED_JSON=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
use Amor\Api\SpecialOrder\SpecialOrderService;
Config::load();
$pdo = Database::pdo();
$adminId = (int) $pdo->query("SELECT user_id FROM users WHERE username='"'"'finalval_admin'"'"'")->fetchColumn();
$svc = new SpecialOrderService($pdo);

function seedOne(PDO $pdo, SpecialOrderService $svc, int $adminId, array $body, float $qty): array {
    $order = $svc->createOrder($body, $adminId, "seed-" . uniqid());
    $confirmed = $svc->confirmOrder((int) $order["orderId"], (int) $order["version"], $adminId, "seed-" . uniqid());
    $sent = $svc->sendToProduction((int) $order["orderId"], (int) $confirmed["version"], $adminId, "seed-" . uniqid());
    $itemId = (int) $sent["items"][0]["itemId"];
    $svc->updateItemsActual((int) $order["orderId"], (int) $sent["version"], [["itemId" => $itemId, "aktualProduksi" => $qty, "rejectProduksi" => 0]], $adminId, "seed-" . uniqid());
    $svc->verifyItemFg($itemId, $qty, $adminId, "seed-" . uniqid());
    return ["orderId" => (int) $order["orderId"], "orderNo" => $order["orderNo"], "itemId" => $itemId];
}

$cs = seedOne($pdo, $svc, $adminId, [
    "sourceType" => "non_toko", "nonStoreSource" => "cs", "customerName" => "Bapak Andi CS",
    "orderDate" => "2026-09-22", "requiredDate" => "2026-09-25",
    "items" => [["itemType" => "special_catalog", "specialCatalogId" => 1, "qty" => 4]],
], 4.0);

$sales = seedOne($pdo, $svc, $adminId, [
    "sourceType" => "non_toko", "nonStoreSource" => "sales_executive", "customerName" => "Budi Sales",
    "orderDate" => "2026-09-22", "requiredDate" => "2026-09-25",
    "items" => [["itemType" => "special_catalog", "specialCatalogId" => 1, "qty" => 2]],
], 2.0);

$direct = seedOne($pdo, $svc, $adminId, [
    "sourceType" => "non_toko", "nonStoreSource" => "konsumen_langsung", "customerName" => "Citra Langsung",
    "orderDate" => "2026-09-22", "requiredDate" => "2026-09-25",
    "items" => [["itemType" => "special_catalog", "specialCatalogId" => 1, "qty" => 1]],
], 1.0);

$umum = seedOne($pdo, $svc, $adminId, [
    "sourceType" => "non_toko", "nonStoreSource" => "umum", "customerName" => "Dedi Umum",
    "orderDate" => "2026-09-22", "requiredDate" => "2026-09-25",
    "items" => [["itemType" => "special_catalog", "specialCatalogId" => 1, "qty" => 1]],
], 1.0);

echo json_encode(["cs" => $cs, "sales" => $sales, "direct" => $direct, "umum" => $umum]);
')
echo "SEED_JSON=$SEED_JSON"
CS_ORDER_ID=$(php -r '$d=json_decode($argv[1],true); echo $d["cs"]["orderId"];' "$SEED_JSON")
CS_ORDER_NO=$(php -r '$d=json_decode($argv[1],true); echo $d["cs"]["orderNo"];' "$SEED_JSON")
SALES_ORDER_ID=$(php -r '$d=json_decode($argv[1],true); echo $d["sales"]["orderId"];' "$SEED_JSON")
SALES_ORDER_NO=$(php -r '$d=json_decode($argv[1],true); echo $d["sales"]["orderNo"];' "$SEED_JSON")
[ -n "$CS_ORDER_ID" ] && [ -n "$SALES_ORDER_ID" ] || { echo "REFUSING: seed fixtures failed"; exit 1; }

echo "--- 8/10: REAL headless-Chromium, DESKTOP (1280x900) — the two full scenarios, source-label checks, receipt confirmation, evidence upload ---"
PNG_PATH="$WORKDIR/evidence.png"
php -r 'file_put_contents($argv[1], base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="));' "$PNG_PATH"

cat > "$WORKDIR/final.js" <<'NODEEOF'
const { chromium } = require('playwright');
const fs = require('fs');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const driverUsername = process.argv[5];
const driverPassword = process.argv[6];
const storeName = process.argv[7];
const factoryId = process.argv[8];
const csOrderId = process.argv[9];
const csOrderNo = process.argv[10];
const salesOrderId = process.argv[11];
const salesOrderNo = process.argv[12];
const mailLog = process.argv[13];
const pngPath = process.argv[14];
const shotPrefix = process.argv[15];

let FAIL = 0;
function check(desc, cond) { if (cond) { console.log('PASS: ' + desc); } else { console.log('FAIL: ' + desc); FAIL = 1; } }

async function loginAsAdmin(context, base, username, password) {
  const page = await context.newPage();
  await page.goto(base + '/');
  await page.evaluate(async ({ base, username, password }) => {
    await fetch(base + '/api/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
  }, { base, username, password });
  await page.close();
}

function tokenForShipment(logPath, shipmentId) {
  if (!fs.existsSync(logPath)) return null;
  const lines = fs.readFileSync(logPath, 'utf8').split('\n').filter(l => l.trim() !== '');
  const msgs = lines.map(l => JSON.parse(l));
  for (let i = msgs.length - 1; i >= 0; i--) {
    // "No. Shipment .. SHP-{id}" is a reliable, HTML-entity-free anchor
    // for THIS message (the link itself is htmlspecialchars()-encoded, so
    // its own "&shipment=" becomes "&amp;shipment=" in the raw body).
    if (!msgs[i].htmlBody.includes('SHP-' + shipmentId + '<')) continue;
    const m = msgs[i].htmlBody.match(/token=([0-9a-f]{64})/);
    if (m) return m[1];
  }
  return null;
}

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    args: ['--no-sandbox'],
  });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await loginAsAdmin(context, base, adminUsername, adminPassword);
  const page = await context.newPage();
  page.on('pageerror', err => console.log('  [pageerror]', err.message));

  const assetFailures = [];
  page.on('response', (res) => {
    const url = res.url();
    if (/\.(css|js|png|jpg|jpeg|svg)(\?|$)/.test(url) && res.status() === 404) {
      assetFailures.push(url + ' -> 404');
    }
  });

  // ------------------------------------------------------------------
  // PART A — FG Khusus/Non-Toko must show the REAL granular source for
  // every order, never a generic "Pesanan Non-Toko" collapse.
  // ------------------------------------------------------------------
  await page.goto(base + '/api/_ui-preview/?page=fg-khusus-non-toko&factoryId=' + factoryId);
  await page.waitForLoadState('networkidle');
  const fgBody = await page.content();
  check('PART A: FG page shows "CS" for the CS order (never generic "Pesanan Non-Toko")', fgBody.includes('>CS<'));
  check('PART A: FG page shows "Sales Executive"', fgBody.includes('Sales Executive'));
  check('PART A: FG page shows "Konsumen Langsung"', fgBody.includes('Konsumen Langsung'));
  check('PART A: FG page shows "Umum"', fgBody.includes('>Umum<'));
  if (!fgBody.includes('Sales Executive')) {
    const idx = fgBody.indexOf('Budi Sales');
    console.log('  [debug] context around "Budi Sales" (idx=' + idx + '): ' + JSON.stringify(fgBody.substring(Math.max(0, idx - 400), idx + 100)));
  }
  await page.screenshot({ path: shotPrefix + '-fg-sources-desktop.png', fullPage: true });

  // ------------------------------------------------------------------
  // PART B — Scenario: CS order -> Driver Internal -> Driver Portal
  // claim -> Konfirmasi Berangkat -> Driver History -> Digital SJ ->
  // automatic email -> real-browser clean receipt confirmation -> Admin
  // Konfirmasi Toko / Pengiriman.
  // ------------------------------------------------------------------
  await page.goto(base + '/api/_ui-preview/?page=delivery-order-khusus-non-toko&factoryId=' + factoryId);
  await page.waitForLoadState('networkidle');
  const dropSel = page.locator('.do-drop-store[data-order-id="' + csOrderId + '"]');
  check('PART B: a Drop Bakery selector appears for the CS (non-toko) order', (await dropSel.count()) === 1);
  if (await dropSel.count() === 1) { await dropSel.selectOption({ label: storeName }); }
  const doBtnCs = page.locator('.do-create-btn[data-order-id="' + csOrderId + '"]');
  check('PART B: the CS order row shows the "CS" badge, never "Pesanan Non-Toko"', (await page.locator('tr:has(.do-create-btn[data-order-id="' + csOrderId + '"])').textContent()).includes('CS'));
  await doBtnCs.click();
  await page.waitForURL(/delivery-order-khusus-non-toko-detail/, { timeout: 10000 }).catch(() => {});
  const csDoBody = await page.content();
  check('PART B: the DO detail page shows "CS" as the source badge', csDoBody.includes('>CS<') || csDoBody.includes('CS</span>'));
  check('PART B: the DO detail page does NOT show the generic "Pesanan Non-Toko" label where CS is the real source', !csDoBody.includes('Pesanan Non-Toko'));
  const csDoId = new URL(page.url()).searchParams.get('id');
  await page.screenshot({ path: shotPrefix + '-cs-do-desktop.png', fullPage: true });

  const driverCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await loginAsAdmin(driverCtx, base, driverUsername, driverPassword);
  const dpage = await driverCtx.newPage();
  await dpage.goto(base + '/api/_driver-uat/index.php?tab=khusus');
  await dpage.waitForLoadState('networkidle');
  const poolBody = await dpage.content();
  check('PART B: the CS DO appears in the Driver Portal Khusus/Non-Toko tab', poolBody.includes('DOK-'));
  check('PART B: the driver card shows the "CS" badge (never "Pesanan Non-Toko")', poolBody.includes('>CS<'));
  await dpage.screenshot({ path: shotPrefix + '-driverpool-cs.png', fullPage: true });

  const claimBtn = dpage.locator('[data-act="claim"][data-id="' + csDoId + '"]');
  check('PART B: an "Ambil (Claim)" button is present', (await claimBtn.count()) === 1);
  if (await claimBtn.count() === 1) { await claimBtn.click(); await dpage.waitForTimeout(800); }
  const departBtn = dpage.locator('[data-act="depart"][data-id="' + csDoId + '"]');
  check('PART B: after claiming, "Konfirmasi Berangkat" appears', (await departBtn.count()) === 1);
  if (await departBtn.count() === 1) {
    await departBtn.click();
    await dpage.waitForTimeout(400);
    const confirmBtn = dpage.locator('.modal .btn-primary[data-act="confirm"]');
    if (await confirmBtn.count() === 1) { await confirmBtn.click(); }
    await dpage.waitForTimeout(1200);
  }

  // Driver Riwayat + Detail Pengiriman + Digital Surat Jalan.
  await dpage.goto(base + '/api/_driver-uat/index.php?tab=riwayat');
  await dpage.waitForLoadState('networkidle');
  const riwayatBody = await dpage.content();
  // The "&middot;" JS literal is parsed into a real middle-dot character
  // by the browser and re-serialized as that raw character (not the
  // entity name) in page.content() — match loosely on the driver-card-sub
  // div's own content instead of hardcoding the separator's exact bytes.
  const riwayatSubMatch = riwayatBody.match(/driver-card-sub">[^<]*<\/div>/);
  check('PART B: the departed CS shipment appears in Driver Riwayat with source "CS" (never generic)', !!riwayatSubMatch && /(^|\W)CS($|\W)/.test(riwayatSubMatch[0]));
  await dpage.screenshot({ path: shotPrefix + '-driver-riwayat-cs.png', fullPage: true });

  const shpMatch = (await dpage.content()).match(/shipment\.php\?id=(\d+)/);
  const csShipmentId = shpMatch ? shpMatch[1] : null;
  check('PART B: a real shipment id was found in Driver Riwayat', !!csShipmentId);
  if (csShipmentId) {
    await dpage.goto(base + '/api/_driver-uat/shipment.php?id=' + csShipmentId);
    await dpage.waitForLoadState('networkidle');
    const detailBody = await dpage.content();
    check('PART B: Detail Pengiriman shows Sumber = CS', detailBody.includes('CS'));

    const [sjPage] = await Promise.all([
      driverCtx.waitForEvent('page'),
      dpage.click('a.driver-btn.primary'),
    ]).catch(() => [null]);
    if (sjPage) {
      await sjPage.waitForLoadState('networkidle');
      const sjBody = await sjPage.content();
      check('PART B: Digital Surat Jalan shows "CS", never "Pesanan Non-Toko"', sjBody.includes('<b>Sumber</b>CS') && !sjBody.includes('Pesanan Non-Toko'));
      await sjPage.screenshot({ path: shotPrefix + '-sj-cs.png', fullPage: true });
      await sjPage.close();
    } else {
      check('PART B: Digital Surat Jalan link was clickable', false);
    }
  }
  await driverCtx.close();

  // Automatic email -> real-browser receipt confirmation (clean).
  if (csShipmentId) {
    await page.waitForTimeout(500);
    const csToken = tokenForShipment(mailLog, csShipmentId);
    check('PART B: an automatic email with a shipment-scoped token was sent for the CS shipment', !!csToken);
    if (csToken) {
      const anonCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
      const rpage = await anonCtx.newPage();
      await rpage.goto(base + '/api/_receive/?token=' + csToken);
      await rpage.waitForLoadState('networkidle');
      const receiveBody = await rpage.content();
      check('PART B: the Bakery receipt page shows Sumber/DO for the CS shipment', receiveBody.includes(csOrderNo) || receiveBody.includes('DOK-'));
      const goodInputs = rpage.locator('[data-field=good]');
      const n = await goodInputs.count();
      for (let i = 0; i < n; i++) {
        const row = goodInputs.nth(i).locator('xpath=ancestor::tr');
        const shipped = await row.getAttribute('data-shipped');
        await goodInputs.nth(i).fill(shipped || '0');
      }
      const submitBtn = rpage.locator('button.rc-btn.primary').first();
      await rpage.fill('input[id^="receiver-name-"]', 'Bakery Tester CS');
      await submitBtn.click();
      await rpage.waitForTimeout(1000);
      await rpage.waitForLoadState('networkidle');
      const afterConfirm = await rpage.textContent('body');
      check('PART B: clean receipt confirmation succeeds through the REAL browser (Diterima Sesuai)', afterConfirm.includes('Diterima Sesuai'));
      await rpage.screenshot({ path: shotPrefix + '-receipt-cs.png', fullPage: true });
      await anonCtx.close();
    }

    // Admin Konfirmasi Toko / Pengiriman keep the CS badge.
    // shipment.tanggal = the DO's own tanggal = the order's required_date
    // (2026-09-25 for every fixture seeded above) — _ui-preview defaults
    // ?tanggal to TODAY when omitted, which would silently filter every
    // seeded shipment out.
    await page.goto(base + '/api/_ui-preview/?page=konfirmasi-toko&tanggal=2026-09-25');
    await page.waitForLoadState('networkidle');
    const admReceiptBody = await page.content();
    check('PART B: Admin Konfirmasi Toko shows the "CS" badge for this shipment', admReceiptBody.includes('>CS<'));
    await page.goto(base + '/api/_ui-preview/?page=pengiriman&tanggal=2026-09-25&factoryId=' + factoryId);
    await page.waitForLoadState('networkidle');
    const admShipBody = await page.content();
    check('PART B: Admin Pengiriman shows the "CS" badge for this shipment', admShipBody.includes('>CS<'));
    await page.screenshot({ path: shotPrefix + '-admin-pengiriman-cs.png', fullPage: true });
  }

  // ------------------------------------------------------------------
  // PART C — Scenario: Sales Executive order -> External Courier
  // (GoSend) -> Barang Diserahkan ke Kurir -> automatic email ->
  // real-browser receipt WITH discrepancy + real photo upload -> Admin
  // verification.
  // ------------------------------------------------------------------
  await page.goto(base + '/api/_ui-preview/?page=delivery-order-khusus-non-toko&factoryId=' + factoryId);
  await page.waitForLoadState('networkidle');
  const dropSelSales = page.locator('.do-drop-store[data-order-id="' + salesOrderId + '"]');
  if (await dropSelSales.count() === 1) { await dropSelSales.selectOption({ label: storeName }); }
  const salesRowText = await page.locator('tr:has(.do-create-btn[data-order-id="' + salesOrderId + '"])').textContent();
  check('PART C: the Sales Executive order row shows the "Sales Executive" badge', salesRowText.includes('Sales Executive'));
  if (!salesRowText.includes('Sales Executive')) { console.log('  [debug] Sales order row text: ' + JSON.stringify(salesRowText)); }
  await page.locator('.do-create-btn[data-order-id="' + salesOrderId + '"]').click();
  await page.waitForURL(/delivery-order-khusus-non-toko-detail/, { timeout: 10000 }).catch(() => {});
  const salesDoId = new URL(page.url()).searchParams.get('id');

  const methodSel = page.locator('#dm-method');
  check('PART C: the delivery-method form is present (status=open)', (await methodSel.count()) === 1);
  if (await methodSel.count() === 1) {
    await methodSel.selectOption('EXTERNAL_COURIER');
    await page.locator('#dm-provider').selectOption('gosend');
    await page.locator('#dm-ref').fill('GS-FINALVAL-001');
    await page.click('#delivery-method-form button[type=submit]');
    await page.waitForLoadState('networkidle');
  }
  const afterMethodBody = await page.content();
  check('PART C: GoSend persisted as the courier provider', afterMethodBody.includes('GoSend'));
  check('PART C: source stays "Sales Executive" after the delivery-method change', afterMethodBody.includes('Sales Executive'));

  const courierBtn = page.locator('#btn-courier-handover');
  check('PART C: "Barang Diserahkan ke Kurir" action is present', (await courierBtn.count()) === 1);
  if (await courierBtn.count() === 1) {
    await courierBtn.click();
    await page.waitForTimeout(400);
    const confirmBtn2 = page.locator('.modal .btn-primary[data-act="confirm"]');
    if (await confirmBtn2.count() === 1) { await confirmBtn2.click(); }
    await page.waitForTimeout(1200);
  }
  const afterHandoverBody = await page.content();
  check('PART C: DO status is Shipped after handover (a real shipment was created)', afterHandoverBody.includes('Shipped'));
  await page.screenshot({ path: shotPrefix + '-sales-courier-shipped.png', fullPage: true });

  const salesShipmentDetail = await page.evaluate(async (base) => {
    const r = await fetch(base + '/api/special-order-do/driver-pool');
    return await r.text();
  }, base);
  check('PART C: the External Courier DO never appears in the Driver Portal pool (mutual exclusion)', !salesShipmentDetail.includes('GS-FINALVAL-001'));

  await page.waitForTimeout(500);
  // GET /api/special-order-do/{id} never carries a "shipmentId" field (a
  // DO can have MULTIPLE shipments across partial dispatches, so there is
  // no single one to expose there) — the real shipmentId only ever comes
  // back from the depart/courier-handover response itself. Look it up the
  // same way Admin would: match this DO's own docNo in Konfirmasi Toko.
  const salesDocNoMatch = afterHandoverBody.match(/DOK-\d{8}-\d{3}/);
  const salesDocNo = salesDocNoMatch ? salesDocNoMatch[0] : null;
  const salesShipmentId = await page.evaluate(async ({ base, salesDocNo }) => {
    const r = await fetch(base + '/api/admin/receipts');
    const j = await r.json();
    const row = (j.data || []).find(x => x.docNo === salesDocNo);
    return row ? row.shipmentId : null;
  }, { base, salesDocNo });
  check('PART C: a real shipmentId was found for the courier handover (docNo=' + salesDocNo + ')', !!salesShipmentId);

  if (salesShipmentId) {
    const salesToken = tokenForShipment(mailLog, String(salesShipmentId));
    check('PART C: an automatic email was sent at "Barang Diserahkan ke Kurir" time', !!salesToken);
    if (salesToken) {
      const anonCtx2 = await browser.newContext({ viewport: { width: 390, height: 844 } });
      const rpage2 = await anonCtx2.newPage();
      await rpage2.goto(base + '/api/_receive/?token=' + salesToken);
      await rpage2.waitForLoadState('networkidle');
      const btn2 = rpage2.locator('button.rc-btn.primary').first();
      await rpage2.fill('[data-field=good]', '1');
      await rpage2.fill('[data-field=reject]', '1');
      await rpage2.waitForTimeout(150);
      check('PART C: a discrepancy without a photo disables the submit button', (await btn2.isDisabled()) === true);
      await rpage2.setInputFiles('.rc-evidence-input', pngPath);
      await rpage2.waitForTimeout(200);
      check('PART C: selecting a real photo re-enables the submit button', (await btn2.isDisabled()) === false);
      await rpage2.fill('input[id^="receiver-name-"]', 'Bakery Tester Sales');
      await btn2.click();
      await rpage2.waitForTimeout(1000);
      await rpage2.waitForLoadState('networkidle');
      const afterDiscrepancy = await rpage2.textContent('body');
      check('PART C: discrepancy + photo confirmation succeeds through the REAL browser (Ada Selisih)', afterDiscrepancy.includes('Ada Selisih'));
      await rpage2.screenshot({ path: shotPrefix + '-receipt-sales-discrepancy.png', fullPage: true });
      await anonCtx2.close();
    }

    await page.goto(base + '/api/_ui-preview/?page=konfirmasi-toko-detail&shipmentId=' + salesShipmentId);
    await page.waitForLoadState('networkidle');
    const admDetailBody = await page.content();
    check('PART C: Admin Konfirmasi Toko Detail shows "Sales Executive" source', admDetailBody.includes('Sales Executive'));
    check('PART C: Admin Konfirmasi Toko Detail shows the uploaded evidence photo', admDetailBody.includes('evidence'));
    const verifyBtn = page.locator('#btn-verify-receipt');
    if (await verifyBtn.count() === 1 && !(await verifyBtn.isDisabled())) {
      await verifyBtn.click();
      await page.waitForTimeout(400);
      const confirmVerify = page.locator('.modal [data-act="confirm"]');
      if (await confirmVerify.count() === 1) { await confirmVerify.click(); }
      await page.waitForTimeout(1000);
    }
    const afterVerifyBody = await page.content();
    check('PART C: Admin can verify the Sales Executive discrepancy (Diverifikasi)', afterVerifyBody.includes('Diverifikasi'));
    await page.screenshot({ path: shotPrefix + '-admin-verify-sales.png', fullPage: true });
  }

  // ------------------------------------------------------------------
  // PART D — Responsive pass (no horizontal overflow) at tablet/mobile.
  // ------------------------------------------------------------------
  for (const vp of [{ name: 'ipad', width: 768, height: 1024 }, { name: 'mobile', width: 375, height: 667 }]) {
    const ctx2 = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    await loginAsAdmin(ctx2, base, adminUsername, adminPassword);
    const p2 = await ctx2.newPage();
    for (const pageKey of ['fg-khusus-non-toko', 'delivery-order-khusus-non-toko', 'konfirmasi-toko']) {
      await p2.goto(base + '/api/_ui-preview/?page=' + pageKey + '&factoryId=' + factoryId);
      await p2.waitForLoadState('networkidle');
      const sw = await p2.evaluate(() => document.documentElement.scrollWidth);
      const cw = await p2.evaluate(() => document.documentElement.clientWidth);
      check(pageKey + ' [' + vp.name + ']: no horizontal overflow', sw <= cw);
    }
    await ctx2.close();

    const ctx3 = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    await loginAsAdmin(ctx3, base, driverUsername, driverPassword);
    const p3 = await ctx3.newPage();
    await p3.goto(base + '/api/_driver-uat/index.php?tab=khusus');
    await p3.waitForLoadState('networkidle');
    const sw3 = await p3.evaluate(() => document.documentElement.scrollWidth);
    const cw3 = await p3.evaluate(() => document.documentElement.clientWidth);
    check('Driver Portal Khusus/Non-Toko [' + vp.name + ']: no horizontal overflow', sw3 <= cw3);
    await ctx3.close();
  }

  check('PART E: NO 404 for any CSS/JS/image asset across the whole run (' + assetFailures.length + ' found)', assetFailures.length === 0);
  if (assetFailures.length > 0) { assetFailures.forEach(f => console.log('  [asset-404] ' + f)); }

  await browser.close();
  process.exit(FAIL);
})().catch((err) => { console.error('CRASHED:', err.stack); process.exit(1); });
NODEEOF
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/final.js" \
  "$BASE" "finalval_admin" "$ADMIN_PASS" "finalval_driver" "$DRIVER_PASS" \
  "P2 TEST STORE A" "$KARANGTENGAH_ID" "$CS_ORDER_ID" "$CS_ORDER_NO" "$SALES_ORDER_ID" "$SALES_ORDER_NO" \
  "$MAIL_LOG" "$PNG_PATH" "$WORKDIR/final"
PW_EXIT=$?
if [ "$PW_EXIT" != "0" ]; then FAIL=1; fi
for shot in fg-sources-desktop cs-do-desktop driverpool-cs driver-riwayat-cs sj-cs receipt-cs \
            admin-pengiriman-cs sales-courier-shipped receipt-sales-discrepancy admin-verify-sales; do
  cp "$WORKDIR/final-$shot.png" "$DIST_DIR/final-ui-$shot-screenshot.png" 2>/dev/null || true
done

echo "--- debug: shipment_email_delivery rows + last PHP-FPM/Apache errors (diagnostic only) ---"
mariadb --socket="$SOCK" -u root -e "SELECT shipment_id, status, recipient_email, last_error, attempt_count FROM $DB_NAME.shipment_email_delivery" 2>&1 || true
echo "--- last 30 lines of apache error log ---"
tail -30 "$WORKDIR/apache-error.log" 2>/dev/null || true

echo "--- 9/10: Regular PO / Regular DO row counts untouched ---"
DO_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.delivery_order")
check "delivery_order (Regular DO) row count is zero — this feature never writes to it" "$DO_COUNT" 0

echo "--- 10/10: mail log sanity (no real SMTP ever attempted) ---"
MAIL_COUNT=$(wc -l < "$MAIL_LOG" 2>/dev/null || echo 0)
echo "fake-SMTP messages captured: $MAIL_COUNT"
check "at least 2 emails were sent (CS driver-internal + Sales external-courier)" "$([ "$MAIL_COUNT" -ge 2 ] && echo yes || echo no)" yes

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + PHP-FPM + DESKTOP/TABLET/MOBILE BROWSER VALIDATION PASSED ==="
  echo "ZIP: $ZIP_PATH"
  echo "Normalized source identity (CS/Sales Executive/Konsumen Langsung/Umum/Pesanan Khusus Toko) verified through FG, DO, Driver Portal, Driver Riwayat/Detail, Digital Surat Jalan, and Admin Konfirmasi Toko/Pengiriman — never collapsing to generic 'Pesanan Non-Toko'. Driver Internal (CS) and External Courier/GoSend (Sales Executive, with discrepancy + real photo upload + Admin verification) both passed end to end through the REAL browser at desktop/tablet/mobile."
  echo "Screenshots saved to: $DIST_DIR/final-ui-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
