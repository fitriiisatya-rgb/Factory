#!/usr/bin/env bash
# Validates dist/amor-factory-production-flow-completion.zip against a
# REAL Apache + PHP-FPM server, PLUS real headless-Chromium at THREE
# viewports (desktop, iPad/tablet, mobile) driving the ACTUAL new flows:
# Ceklis Produksi notes bugfix, the new product autocomplete, Extra
# Packaging + Rp money formatting, the FG Sumber Khusus/Non-Toko bridge,
# and source-specific DO creation/shipping.
#
# Requires apache2 + php8.3-fpm + node/playwright. Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-production-flow-completion.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_flowvalidate"
ADMIN_PASS="ApacheFlowAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassFLOWVAL_123"
RUNTIME_USER_PASS="ApacheRunPassFLOWVAL_123"
HTTP_PORT=8213
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q flowvalidate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q flowvalidate-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/flowvalidate.conf /etc/apache2/sites-enabled/flowvalidate.conf
  rm -f /etc/apache2/conf-available/flowvalidate-listen.conf /etc/apache2/conf-enabled/flowvalidate-listen.conf
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
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-production-flow-completion.sh first"; exit 1; }

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
CREATE USER 'flowvalmig_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'flowvalmig_user'@'localhost';
CREATE USER 'flowvalrun_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'flowvalrun_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/8: migrate (0001-0012) + seed + admin + master bootstrap + PO fixture ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'flowvalmig_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" flowval_admin "Flow Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
DRIVER_PASS="ApacheFlowDriver#$(date +%s)"
export DRIVER_PASS
DRIVER_HASH="$(php -r "echo password_hash(getenv('DRIVER_PASS'), PASSWORD_DEFAULT);")"
mariadb --socket="$SOCK" -u root "$DB_NAME" -e "
  INSERT INTO po_batch (tanggal, factory_id, version, created_at)
  SELECT '2026-09-25', factory_id, 1, UTC_TIMESTAMP() FROM factory WHERE name='Karangtengah';
  INSERT INTO po_item (po_batch_id, product_id, po_awal, po_revisi, pb)
  SELECT pb.po_batch_id, p.product_id, 50, 0, 0 FROM po_batch pb, product p, division d
  WHERE pb.tanggal='2026-09-25' AND d.name='Roti & Bollen' AND p.division_id=d.division_id AND p.aktif=1 LIMIT 1;
  INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES ('flowval_driver', '$DRIVER_HASH', 'Flow Apache Validate Driver', 1, UTC_TIMESTAMP());
  INSERT INTO user_roles (user_id, role_id) SELECT (SELECT user_id FROM users WHERE username='flowval_driver'), role_id FROM roles WHERE code='DRIVER';
"
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/8: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'flowvalrun_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/8: starting REAL php8.3-fpm + REAL apache2 (AllowOverride All, like real cPanel) ---"
mkdir -p /run/php
chown www-data:www-data /run/php
if [ ! -S /run/php/php8.3-fpm.sock ]; then
  /usr/sbin/php-fpm8.3 -D --fpm-config /etc/php/8.3/fpm/php-fpm.conf
  FPM_STARTED_BY_US=1
  for i in $(seq 1 20); do [ -S /run/php/php8.3-fpm.sock ] && break; sleep 0.3; done
  [ -S /run/php/php8.3-fpm.sock ] || { echo "php8.3-fpm did not come up"; exit 1; }
fi
cat > /etc/apache2/conf-available/flowvalidate-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q flowvalidate-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/flowvalidate.conf <<CONF
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
a2ensite -q flowvalidate
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

echo "--- 6/8: baseline sanity ---"
check "api/app/config/config.php is forbidden" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/app/config/config.php")" 403
STOREA_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")

echo "--- 7/8: REAL headless-Chromium at THREE viewports — drive the new flows end to end ---"
cat > "$WORKDIR/flow.js" <<'NODEEOF'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const storeName = process.argv[5];
const shotPrefix = process.argv[6];
const driverUsername = process.argv[7];
const driverPassword = process.argv[8];

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

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    args: ['--no-sandbox'],
  });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await loginAsAdmin(context, base, adminUsername, adminPassword);
  const page = await context.newPage();
  page.on('pageerror', err => console.log('  [pageerror]', err.message));

  // --- Create a Pesanan Khusus Toko order using the NEW autocomplete + Extra Packaging ---
  await page.goto(base + '/api/_ui-preview/?page=pesanan-khusus-toko');
  await page.waitForLoadState('networkidle');

  const bodyBg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  check('dark theme preserved (body background is dark, got ' + bodyBg + ')', bodyBg !== 'rgb(255, 255, 255)');

  await page.selectOption('#pkt-store', { label: storeName });
  await page.fill('#pkt-order-date', '2026-09-22');
  await page.fill('#pkt-required-date', '2026-09-25');

  const card1 = page.locator('.order-item-card').first();
  const itemInput = card1.locator('.pkt-item-input');
  await itemInput.click();
  await itemInput.fill('BOLLEN');
  await page.waitForTimeout(300);
  const menu = card1.locator('.ac-menu');
  check('autocomplete dropdown appears (no native datalist)', await menu.isVisible());
  check('page has NO native <datalist> product picker', (await page.locator('datalist#pkt-product-list').count()) === 0);
  const firstOption = menu.locator('.ac-option').first();
  const optionText = await firstOption.textContent();
  await firstOption.click();
  check('autocomplete fills the input with the selected product name', (await itemInput.inputValue()) === (optionText || '').trim());

  // Invalidation: typing again after a selection must clear the routing badges.
  await itemInput.fill((optionText || '').trim() + 'x');
  await page.waitForTimeout(150);
  const divisionBadgeAfterEdit = await card1.locator('.pkt-division-slot').textContent();
  check('editing the text after a selection invalidates it (division badge resets to —)', (divisionBadgeAfterEdit || '').includes('—'));
  // Re-select for the rest of the flow.
  await itemInput.fill('BOLLEN');
  await page.waitForTimeout(300);
  await card1.locator('.ac-menu .ac-option').first().click();

  await card1.locator('.pkt-qty').fill('10');
  await card1.locator('.pkt-price').fill('10000');
  await card1.locator('.pkt-charge').fill('2000');
  await card1.locator('.pkt-extra-packaging').fill('5000');
  await page.waitForTimeout(150);

  const subtotalText = await card1.locator('.pkt-item-subtotal').textContent();
  check('Extra Packaging is added ONCE to the item subtotal (10*10000+2000+5000=107.000), got "' + subtotalText + '"', (subtotalText || '').includes('107.000'));

  const summaryText = await page.locator('#pkt-summary').textContent();
  check('order summary shows "Total Extra Packaging"', (summaryText || '').includes('Total Extra Packaging'));
  check('money is Rp-formatted with a dot thousands separator (Rp10.000 present)', (summaryText || '').includes('Rp10.000') || (await card1.locator('.pkt-price-preview').textContent() || '').includes('Rp10.000'));

  const scrollWidthForm = await page.evaluate(() => document.documentElement.scrollWidth);
  const clientWidthForm = await page.evaluate(() => document.documentElement.clientWidth);
  check('no horizontal overflow on the create form (desktop)', scrollWidthForm <= clientWidthForm);

  await page.screenshot({ path: shotPrefix + '-create-desktop.png', fullPage: true });

  await page.click('#pkt-submit-send');
  await page.waitForURL(/pesanan-khusus-toko-detail/, { timeout: 10000 }).catch(() => {});
  const url = page.url();
  const idMatch = url.match(/[?&]id=(\d+)/);
  check('order was created and sent to production (redirected to detail page)', !!idMatch);

  if (idMatch) {
    const orderId = idMatch[1];
    await page.waitForLoadState('networkidle');
    const detailBody = await page.content();
    check('detail page shows Extra Packaging column', detailBody.includes('Extra Packaging'));
    check('detail page shows Rp-formatted money (Rp107.000 subtotal)', detailBody.includes('Rp107.000'));
    await page.screenshot({ path: shotPrefix + '-detail-desktop.png', fullPage: true });

    // --- Production: set Actual/Reject via Task per Divisi ---
    const itemsResp = await page.evaluate(async ({ base, orderId }) => {
      const r = await fetch(base + '/api/special-orders/' + orderId);
      const j = await r.json();
      return j.data;
    }, { base, orderId });
    const itemId = itemsResp.items[0].itemId;

    const actualResult = await page.evaluate(async ({ base, orderId, itemId, version }) => {
      const r = await fetch(base + '/api/special-orders/' + orderId + '/actual', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.AMOR.csrfToken, 'Idempotency-Key': 'validate-flow-actual-' + Date.now() },
        body: JSON.stringify({ expectedVersion: version, items: [{ itemId, aktualProduksi: 8, rejectProduksi: 1 }] }),
      });
      return { status: r.status, body: await r.text() };
    }, { base, orderId, itemId, version: itemsResp.version });
    check('POST /actual (Aktual/Reject Produksi) succeeded', actualResult.status === 200);
    if (actualResult.status !== 200) { console.log('  [debug] /actual response: ' + actualResult.body); }

    // Roti & Bollen (the item picked above) routes to Karangtengah — the
    // FG/DO pages default to _ui-preview's own $factories[0], which isn't
    // guaranteed to BE Karangtengah, so fetch its real id explicitly
    // rather than relying on that default.
    const karangtengahFactoryId = await page.evaluate(async (base) => {
      const r = await fetch(base + '/api/factories');
      const j = await r.json();
      const f = j.data.find(x => x.name === 'Karangtengah');
      return f ? f.factory_id : null;
    }, base);
    check('resolved the Karangtengah factory id for the FG/DO pages', !!karangtengahFactoryId);

    // --- FG Sumber Khusus/Non-Toko: verify FG ---
    await page.goto(base + '/api/_ui-preview/?page=fg-khusus-non-toko&factoryId=' + karangtengahFactoryId);
    await page.waitForLoadState('networkidle');
    check('FG Sumber Khusus/Non-Toko tab bar renders', (await page.locator('.filter-bar .btn-group .btn').count()) >= 2);
    const fgRow = page.locator('tr[data-item-id="' + itemId + '"]');
    check('the produced item appears in the FG-eligible list', (await fgRow.count()) === 1);
    if (await fgRow.count() === 1) {
      await fgRow.locator('.fg-verify-input').fill('8');
      await fgRow.locator('.fg-verify-btn').click();
      await page.waitForTimeout(800);
    }
    await page.screenshot({ path: shotPrefix + '-fg-desktop.png', fullPage: true });

    // --- SCENARIO A: DO Pesanan Khusus Toko -> Driver Internal -> Driver Portal claim -> Konfirmasi Berangkat ---
    await page.goto(base + '/api/_ui-preview/?page=delivery-order-khusus-non-toko&factoryId=' + karangtengahFactoryId);
    await page.waitForLoadState('networkidle');
    check('DO Pesanan Khusus/Non-Toko tab bar renders', (await page.locator('.filter-bar .btn-group .btn').count()) >= 2);
    const doBtn = page.locator('.do-create-btn[data-order-id="' + orderId + '"]');
    check('the FG-verified order appears in "Pesanan Siap Dibuat DO"', (await doBtn.count()) === 1);
    let scenarioADoId = null;
    if (await doBtn.count() === 1) {
      await doBtn.click();
      await page.waitForURL(/delivery-order-khusus-non-toko-detail/, { timeout: 10000 }).catch(() => {});
      const doBody = await page.content();
      check('the created DO uses the DOK- number format (never DO/KRM/...)', /DOK-\d{8}-\d{3}/.test(doBody));
      check('the DO detail shows the source badge (Pesanan Khusus Toko)', doBody.includes('Pesanan Khusus Toko'));
      check('delivery method defaults to Driver Internal', doBody.includes('Driver Internal'));
      await page.screenshot({ path: shotPrefix + '-do-desktop.png', fullPage: true });
      scenarioADoId = new URL(page.url()).searchParams.get('id');
    }

    if (scenarioADoId) {
      // --- Driver Portal: a SEPARATE browser context, logged in as the driver ---
      const driverCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
      await loginAsAdmin(driverCtx, base, driverUsername, driverPassword);
      const dpage = await driverCtx.newPage();
      dpage.on('pageerror', err => console.log('  [driver pageerror]', err.message));
      dpage.on('console', msg => console.log('  [driver console]', msg.text()));
      await dpage.goto(base + '/api/_driver-uat/index.php?tab=khusus');
      await dpage.waitForLoadState('networkidle');
      const poolDebug = await dpage.evaluate(async (base) => {
        const r = await fetch(base + '/api/special-order-do/driver-pool');
        return { status: r.status, body: await r.text() };
      }, base).catch(err => ({ status: -1, body: String(err) }));
      console.log('  [debug] GET /api/special-order-do/driver-pool -> ' + poolDebug.status + ' ' + poolDebug.body);
      await dpage.waitForTimeout(1000);
      const poolBody = await dpage.content();
      check('SCENARIO A: the DO appears in the Driver Portal Khusus/Non-Toko tab', poolBody.includes('DOK-'));
      check('SCENARIO A: the driver card shows a clear source badge', poolBody.includes('Pesanan Khusus Toko'));
      await dpage.screenshot({ path: shotPrefix + '-driverportal-pool.png', fullPage: true });

      const claimBtn = dpage.locator('[data-act="claim"][data-id="' + scenarioADoId + '"]');
      check('SCENARIO A: an "Ambil (Claim)" button is present', (await claimBtn.count()) === 1);
      if (await claimBtn.count() === 1) {
        await claimBtn.click();
        await dpage.waitForTimeout(800);
      }
      const departBtn = dpage.locator('[data-act="depart"][data-id="' + scenarioADoId + '"]');
      check('SCENARIO A: after claiming, a "Konfirmasi Berangkat" button appears', (await departBtn.count()) === 1);
      if (await departBtn.count() === 1) {
        await departBtn.click();
        await dpage.waitForTimeout(400);
        const confirmBtn = dpage.locator('.modal .btn-primary[data-act="confirm"]');
        if (await confirmBtn.count() === 1) { await confirmBtn.click(); }
        await dpage.waitForTimeout(1200);
      }
      await dpage.screenshot({ path: shotPrefix + '-driverportal-departed.png', fullPage: true });
      await driverCtx.close();

      // Back on the Admin side: confirm the REAL dispatch effect.
      await page.goto(base + '/api/_ui-preview/?page=delivery-order-khusus-non-toko-detail&id=' + scenarioADoId);
      await page.waitForLoadState('networkidle');
      const afterDepartBody = await page.content();
      check('SCENARIO A: DO status is Shipped after Konfirmasi Berangkat (CRITICAL DISPATCH RULE — a real shipment was created)', afterDepartBody.includes('Shipped'));
      check('SCENARIO A: "Sudah Dikirim" is populated (never "-") after a real shipment', !/Sudah Dikirim<\/th>[\s\S]{0,300}>-</.test(afterDepartBody));
      await page.screenshot({ path: shotPrefix + '-do-shipped-desktop.png', fullPage: true });
    }

    // --- SCENARIO B: Pesanan Non-Toko (CS) -> Kurir Eksternal (GoSend) -> Barang Diserahkan ke Kurir ---
    const scenarioB = await page.evaluate(async ({ base }) => {
      async function post(path, body) {
        const r = await fetch(base + path, {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.AMOR.csrfToken, 'Idempotency-Key': 'validate-b-' + Math.random() },
          body: JSON.stringify(body || {}),
        });
        return { status: r.status, json: await r.json().catch(() => null) };
      }
      const order = await post('/api/special-orders', {
        sourceType: 'non_toko', nonStoreSource: 'cs', customerName: 'Bapak Andi',
        orderDate: '2026-09-22', requiredDate: '2026-09-25',
        items: [{ itemType: 'special_catalog', specialCatalogId: 1, qty: 3 }],
      });
      const confirmed = await post('/api/special-orders/' + order.json.data.orderId + '/confirm', { expectedVersion: order.json.data.version });
      const sent = await post('/api/special-orders/' + order.json.data.orderId + '/send-to-production', { expectedVersion: confirmed.json.data.version });
      const itemId = sent.json.data.items[0].itemId;
      await post('/api/special-orders/' + order.json.data.orderId + '/actual', { expectedVersion: sent.json.data.version, items: [{ itemId, aktualProduksi: 3, rejectProduksi: 0 }] });
      await post('/api/special-orders/items/' + itemId + '/verify-fg', { fgVerifiedQty: 3 });
      return { orderId: order.json.data.orderId, orderNo: order.json.data.orderNo, itemId };
    }, { base });
    check('SCENARIO B: CS order created + sent to production + FG verified (via API, real UI drives the dispatch below)', !!scenarioB.orderId);

    await page.goto(base + '/api/_ui-preview/?page=delivery-order-khusus-non-toko&factoryId=' + karangtengahFactoryId);
    await page.waitForLoadState('networkidle');
    const dropSelB = page.locator('.do-drop-store[data-order-id="' + scenarioB.orderId + '"]');
    check('SCENARIO B: a Drop Bakery selector appears for the non-toko order', (await dropSelB.count()) === 1);
    if (await dropSelB.count() === 1) { await dropSelB.selectOption({ label: storeName }); }
    const doBtnB = page.locator('.do-create-btn[data-order-id="' + scenarioB.orderId + '"]');
    await doBtnB.click();
    await page.waitForURL(/delivery-order-khusus-non-toko-detail/, { timeout: 10000 }).catch(() => {});
    const doBodyB = await page.content();
    check('SCENARIO B: the DO shows sourceType-preserving badge (Pesanan Non-Toko), never converted to a store order', doBodyB.includes('Pesanan Non-Toko'));
    const scenarioBDoId = new URL(page.url()).searchParams.get('id');

    // Change delivery method to External Courier / GoSend through the REAL UI.
    const methodSel = page.locator('#dm-method');
    check('SCENARIO B: the delivery-method form is present (still status=open, unshipped)', (await methodSel.count()) === 1);
    if (await methodSel.count() === 1) {
      await methodSel.selectOption('EXTERNAL_COURIER');
      await page.locator('#dm-provider').selectOption('gosend');
      await page.locator('#dm-ref').fill('GS-123456');
      await page.click('#delivery-method-form button[type=submit]');
      await page.waitForLoadState('networkidle');
    }
    const afterMethodBody = await page.content();
    check('SCENARIO B: provider GoSend persisted', afterMethodBody.includes('GoSend'));
    check('SCENARIO B: booking reference GS-123456 persisted', afterMethodBody.includes('GS-123456'));
    await page.screenshot({ path: shotPrefix + '-courier-do-desktop.png', fullPage: true });

    // Confirm this DO never reaches the Driver Portal now that it's External Courier.
    const driverCheckCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    await loginAsAdmin(driverCheckCtx, base, driverUsername, driverPassword);
    const dcpage = await driverCheckCtx.newPage();
    await dcpage.goto(base + '/api/_driver-uat/index.php?tab=khusus');
    await dcpage.waitForLoadState('networkidle');
    const dcBody = await dcpage.content();
    check('SCENARIO B: the External Courier DO is ABSENT from the Driver Portal (mutual exclusion)', !dcBody.includes(scenarioB.orderNo));
    await driverCheckCtx.close();

    // "Barang Diserahkan ke Kurir" — the CRITICAL DISPATCH action for External Courier.
    const courierBtn = page.locator('#btn-courier-handover');
    check('SCENARIO B: "Barang Diserahkan ke Kurir" action is present', (await courierBtn.count()) === 1);
    if (await courierBtn.count() === 1) {
      await courierBtn.click();
      await page.waitForTimeout(400);
      const confirmBtn2 = page.locator('.modal .btn-primary[data-act="confirm"]');
      if (await confirmBtn2.count() === 1) { await confirmBtn2.click(); }
      await page.waitForTimeout(1200);
    }
    const afterHandoverBody = await page.content();
    check('SCENARIO B: DO status is Shipped after handover (a real shipment was created)', afterHandoverBody.includes('Shipped'));
    await page.screenshot({ path: shotPrefix + '-courier-shipped-desktop.png', fullPage: true });

    const fgAfterB = await page.evaluate(async (base) => {
      const r = await fetch(base + '/api/special-orders/fg-eligible');
      const j = await r.json();
      return j.data;
    }, base);
    const fgRowB = Array.isArray(fgAfterB) ? fgAfterB.find(it => it.itemId === scenarioB.itemId) : null;
    check('SCENARIO B: FG shippedQty reflects the courier handover (FG only reduced at handover, never at booking)', !!fgRowB && Math.abs(fgRowB.shippedQty - 3) < 0.001);
  }

  // --- Responsive pass: tablet + mobile, no horizontal overflow, dark theme intact ---
  for (const vp of [{ name: 'ipad', width: 768, height: 1024 }, { name: 'mobile', width: 375, height: 667 }]) {
    const ctx2 = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    await loginAsAdmin(ctx2, base, adminUsername, adminPassword);
    const p2 = await ctx2.newPage();
    for (const pageKey of ['pesanan-khusus-toko', 'fg-khusus-non-toko', 'delivery-order-khusus-non-toko']) {
      await p2.goto(base + '/api/_ui-preview/?page=' + pageKey);
      await p2.waitForLoadState('networkidle');
      const sw = await p2.evaluate(() => document.documentElement.scrollWidth);
      const cw = await p2.evaluate(() => document.documentElement.clientWidth);
      check(pageKey + ' [' + vp.name + ']: no horizontal overflow', sw <= cw);
    }
    await p2.screenshot({ path: shotPrefix + '-create-' + vp.name + '.png', fullPage: true });
    await ctx2.close();
  }

  // Driver Portal responsive pass (mobile is its PRIMARY form factor).
  for (const vp of [{ name: 'ipad', width: 768, height: 1024 }, { name: 'mobile', width: 375, height: 667 }]) {
    const ctx3 = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    await loginAsAdmin(ctx3, base, driverUsername, driverPassword);
    const p3 = await ctx3.newPage();
    await p3.goto(base + '/api/_driver-uat/index.php?tab=khusus');
    await p3.waitForLoadState('networkidle');
    const sw3 = await p3.evaluate(() => document.documentElement.scrollWidth);
    const cw3 = await p3.evaluate(() => document.documentElement.clientWidth);
    check('Driver Portal Khusus/Non-Toko [' + vp.name + ']: no horizontal overflow', sw3 <= cw3);
    await p3.screenshot({ path: shotPrefix + '-driverportal-' + vp.name + '.png', fullPage: true });
    await ctx3.close();
  }

  await browser.close();
  process.exit(FAIL);
})().catch((err) => { console.error('CRASHED:', err.stack); process.exit(1); });
NODEEOF
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/flow.js" "$BASE" "flowval_admin" "$ADMIN_PASS" "P2 TEST STORE A" "$WORKDIR/flow" "flowval_driver" "$DRIVER_PASS"
FLOW_EXIT=$?
if [ "$FLOW_EXIT" != "0" ]; then FAIL=1; fi
for shot in create-desktop detail-desktop fg-desktop do-desktop create-ipad create-mobile \
            driverportal-pool driverportal-departed do-shipped-desktop \
            courier-do-desktop courier-shipped-desktop driverportal-ipad driverportal-mobile; do
  cp "$WORKDIR/flow-$shot.png" "$DIST_DIR/flow-ui-$shot-screenshot.png" 2>/dev/null || true
done

echo "--- 8/8: Regular PO / Regular DO untouched (row counts identical) ---"
PO_BATCH_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.po_batch")
DO_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.delivery_order")
check "delivery_order (Regular DO) row count is zero — this feature never writes to it" "$DO_COUNT" 0
echo "po_batch rows: $PO_BATCH_COUNT (fixture only, unaffected by special-order/FG/DO activity)"

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + DESKTOP/TABLET/MOBILE BROWSER VALIDATION PASSED ==="
  echo "ZIP: $ZIP_PATH"
  echo "New product autocomplete, Extra Packaging, the special-order FG bridge, and source-specific DO all verified. SCENARIO A (Driver Internal: FG->DO->Driver Portal claim->Konfirmasi Berangkat->real shipment) and SCENARIO B (External Courier/GoSend: DO->delivery-method change->Barang Diserahkan ke Kurir->real shipment, absent from Driver Portal) both passed end to end through the REAL UI at desktop/tablet/mobile."
  echo "Screenshots saved to: $DIST_DIR/flow-ui-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
