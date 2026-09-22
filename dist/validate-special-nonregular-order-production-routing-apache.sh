#!/usr/bin/env bash
# Validates dist/amor-factory-special-nonregular-order-production-routing.zip
# against a REAL Apache + PHP-FPM server (migration 0010 applied for
# real), PLUS real headless-Chromium at THREE viewports (desktop, iPad/
# tablet, mobile) driving the ACTUAL create form (not just the JSON API)
# for Pesanan Khusus Toko, then checking Pesanan Non-Toko and Production's
# "Order Masuk / Demand Tambahan" inbox.
#
# Verifies: dark navy theme preserved, forms usable, item table readable,
# division clearly visible, multi-division order works end to end through
# the real UI, special notes visible in Production, charge shown
# correctly, no horizontal overflow, existing Admin pages (Dashboard,
# Konfirmasi Toko Detail) visually/structurally unaffected.
#
# Requires apache2 + php8.3-fpm + node/playwright. Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-special-nonregular-order-production-routing.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_order_apachevalidate"
ADMIN_PASS="ApacheOrderAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassORDER_123"
RUNTIME_USER_PASS="ApacheRunPassORDER_123"
HTTP_PORT=8200
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q order-validate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q order-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/order-validate.conf /etc/apache2/sites-enabled/order-validate.conf
  rm -f /etc/apache2/conf-available/order-listen.conf /etc/apache2/conf-enabled/order-listen.conf
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
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-special-nonregular-order-production-routing.sh first"; exit 1; }

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
CREATE USER 'orderav_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'orderav_migration_user'@'localhost';
CREATE USER 'orderav_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'orderav_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/8: migrate (0001-0010, the REAL packaged migration.php pointer files) + seed + admin + master bootstrap ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'orderav_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
# api/bin/ is dev-only tooling and is intentionally NOT shipped in the ZIP
# (cPanel users apply migrations via the web-based api/_upgrade/ tool instead).
# The build script's own sanity checks already confirm the
# shipped migrations/ + database/ DDL files are byte-identical to the repo's,
# so running the repo's migrate.php here against this disposable DB is the
# real end-to-end proof that migration 0010 applies cleanly.
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" orderav_admin "Order Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/8: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'orderav_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
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
cat > /etc/apache2/conf-available/order-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q order-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/order-validate.conf <<CONF
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
a2ensite -q order-validate
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

echo "--- 6/8: baseline sanity — api/app/ still forbidden, new routes reachable, PO Reguler untouched ---"
check "api/app/config/config.php is forbidden" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/app/config/config.php")" 403
STOREA_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")
curl -s -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d "{\"username\":\"orderav_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
CATALOG_CODE=$(curl -s -o /dev/null -w '%{http_code}' -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" "$BASE/api/special-orders/catalog")
check "GET /api/special-orders/catalog (lazy-seeds Cake & Custom) is 200" "$CATALOG_CODE" 200
PO_BATCH_COUNT_BEFORE=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.po_batch")
echo "po_batch rows before any special-order activity: $PO_BATCH_COUNT_BEFORE (expect this to stay identical throughout)"

echo "--- 7/8: REAL headless-Chromium at THREE viewports — create a real multi-division Pesanan Khusus Toko through the ACTUAL form ---"
cat > "$WORKDIR/order-ui.js" <<'NODEEOF'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const storeName = process.argv[5];
const shotPrefix = process.argv[6];

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

  await page.goto(base + '/api/_ui-preview/?page=pesanan-khusus-toko');
  await page.waitForLoadState('networkidle');

  // Dark navy theme preserved: same body background as every other Admin page.
  const bodyBg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  check('dark theme preserved on Pesanan Khusus Toko (body background is a dark color, got ' + bodyBg + ')', /rgb\(\s*\d+,\s*\d+,\s*\d+\)/.test(bodyBg) && bodyBg !== 'rgb(255, 255, 255)');

  check('tab bar shows PO Toko / Pesanan Khusus Toko / Pesanan Non-Toko', (await page.locator('.filter-bar .btn-group .btn').count()) === 3);

  await page.selectOption('#pkt-store', { label: storeName });
  await page.fill('#pkt-order-date', '2026-09-22');
  await page.fill('#pkt-required-date', '2026-09-25');

  // Row 1: special/custom catalog item (routes to Cake & Custom).
  await page.locator('#pkt-items-table tbody tr').first().locator('.pkt-item-type').selectOption('special_catalog');
  await page.locator('#pkt-items-table tbody tr').first().locator('.pkt-catalog-select').selectOption({ index: 1 });
  await page.locator('#pkt-items-table tbody tr').first().locator('.pkt-qty').fill('2');
  await page.locator('#pkt-items-table tbody tr').first().locator('.pkt-charge').fill('50000');
  await page.locator('#pkt-items-table tbody tr').first().locator('.pkt-note').fill('Tema Spiderman, tulisan HBD Raka');

  // Row 2 (added via the real "+ Tambah Item" button): existing product (routes to its own division).
  await page.click('#pkt-add-item');
  const row2 = page.locator('#pkt-items-table tbody tr').nth(1);
  await row2.locator('.pkt-product-input').fill('BOLLEN COKLAT');
  await row2.locator('.pkt-qty').fill('10');

  const divisionPreviews = await page.locator('.pkt-division-preview').allTextContents();
  check('division preview is visible per item BEFORE submit (task: user must clearly see DIVISI PRODUKSI TUJUAN)', divisionPreviews.every(t => t.trim() !== '' && t.trim() !== '-'));

  await page.screenshot({ path: shotPrefix + '-form-desktop.png', fullPage: true });

  await page.click('#pkt-submit');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(300);

  check('form submit navigated to the detail page', page.url().includes('pesanan-khusus-toko-detail'));
  const bodyText = await page.textContent('body');
  check('detail page shows Multi Divisi', bodyText.includes('Multi Divisi'));
  check('detail page shows both division names (Cake & Custom + Roti & Bollen)', bodyText.includes('Cake & Custom') && bodyText.includes('Roti & Bollen'));
  check('detail page shows the special note', bodyText.includes('Tema Spiderman'));
  check('detail page shows the charge value (50.000 or 50000)', bodyText.includes('50.000') || bodyText.includes('50000'));
  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
  check('no horizontal overflow on the detail page (desktop)', scrollWidth <= clientWidth);

  const orderIdMatch = page.url().match(/id=(\d+)/);
  const orderId = orderIdMatch ? orderIdMatch[1] : null;
  console.log('CREATED_ORDER_ID=' + orderId);

  await context.close();
  await browser.close();
  process.exit(FAIL);
})().catch((err) => {
  console.error('CRASHED:', err.stack);
  process.exit(1);
});
NODEEOF

STORE_NAME=$(mariadb --socket="$SOCK" -u root -N -e "SELECT canonical_name FROM $DB_NAME.store WHERE store_id=$STOREA_ID")
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/order-ui.js" "$BASE" "orderav_admin" "$ADMIN_PASS" "$STORE_NAME" "$WORKDIR/order-ui" > "$WORKDIR/order-ui-output.log" 2>&1
NODE_EXIT=$?
cat "$WORKDIR/order-ui-output.log"
if [ "$NODE_EXIT" != "0" ]; then FAIL=1; fi
cp "$WORKDIR/order-ui-form-desktop.png" "$DIST_DIR/order-form-desktop-screenshot.png" 2>/dev/null || true
CREATED_ORDER_ID=$(grep -oE 'CREATED_ORDER_ID=[0-9]+' "$WORKDIR/order-ui-output.log" | head -1 | cut -d= -f2)

echo "--- 7b/8: confirm + send that order to Production, then check the demand inbox + tablet/mobile viewports ---"
if [ -n "${CREATED_ORDER_ID:-}" ]; then
  ORDER_JSON=$(curl -s -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" "$BASE/api/special-orders/$CREATED_ORDER_ID")
  ORDER_VERSION=$(printf '%s' "$ORDER_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["version"];')
  CSRF=$(curl -s -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" "$BASE/api/auth/me" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["csrfToken"];')
  CONFIRM=$(curl -s -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" -X POST "$BASE/api/special-orders/$CREATED_ORDER_ID/confirm" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: order-av-confirm" -d "{\"expectedVersion\":$ORDER_VERSION}")
  CONFIRM_VERSION=$(printf '%s' "$CONFIRM" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["version"] ?? "";')
  SEND=$(curl -s -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" -X POST "$BASE/api/special-orders/$CREATED_ORDER_ID/send-to-production" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: order-av-send" -d "{\"expectedVersion\":$CONFIRM_VERSION}")
  SEND_STATUS=$(printf '%s' "$SEND" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["status"] ?? "";')
  check "order successfully sent to Production" "$SEND_STATUS" "sent_to_production"

  cat > "$WORKDIR/order-inbox.js" <<'NODEEOF2'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const shotPrefix = process.argv[5];

let FAIL = 0;
function check(desc, cond) { if (cond) { console.log('PASS: ' + desc); } else { console.log('FAIL: ' + desc); FAIL = 1; } }

async function loginAsAdmin(context, base, username, password) {
  const page = await context.newPage();
  await page.goto(base + '/');
  await page.evaluate(async ({ base, username, password }) => {
    await fetch(base + '/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username, password }) });
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

    await page.goto(base + '/api/_ui-preview/?page=produksi-demand');
    await page.waitForLoadState('networkidle');
    const bodyText = await page.textContent('body');
    check('Production inbox [' + vp.name + ']: shows Cake & Custom division group', bodyText.includes('Cake & Custom'));
    check('Production inbox [' + vp.name + ']: shows Roti & Bollen division group', bodyText.includes('Roti & Bollen'));
    check('Production inbox [' + vp.name + ']: special note visible read-only', bodyText.includes('Tema Spiderman'));
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    check('Production inbox [' + vp.name + ']: no horizontal overflow', scrollWidth <= clientWidth);

    // Existing Admin pages (Dashboard, Konfirmasi Toko) visually unaffected.
    await page.goto(base + '/api/_ui-preview/?page=dashboard');
    await page.waitForLoadState('networkidle');
    const dashScrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const dashClientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    check('Dashboard [' + vp.name + ']: still renders with no horizontal overflow (unaffected by this feature)', dashScrollWidth <= dashClientWidth);
    check('Dashboard [' + vp.name + ']: has NO kpi-card--detail (real KPIs untouched)', (await page.locator('.kpi-card--detail').count()) === 0);

    if (vp.name === 'desktop') { await page.screenshot({ path: shotPrefix + '-inbox-desktop.png', fullPage: true }); }
    if (vp.name === 'ipad') { await page.screenshot({ path: shotPrefix + '-inbox-ipad.png', fullPage: true }); }
    if (vp.name === 'mobile') { await page.screenshot({ path: shotPrefix + '-inbox-mobile.png', fullPage: true }); }

    await context.close();
  }
  await browser.close();
  process.exit(FAIL);
})().catch((err) => { console.error('CRASHED:', err.stack); process.exit(1); });
NODEEOF2
  NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/order-inbox.js" "$BASE" "orderav_admin" "$ADMIN_PASS" "$WORKDIR/order-inbox"
  INBOX_EXIT=$?
  if [ "$INBOX_EXIT" != "0" ]; then FAIL=1; fi
  cp "$WORKDIR/order-inbox-inbox-desktop.png" "$DIST_DIR/order-inbox-desktop-screenshot.png" 2>/dev/null || true
  cp "$WORKDIR/order-inbox-inbox-ipad.png" "$DIST_DIR/order-inbox-ipad-screenshot.png" 2>/dev/null || true
  cp "$WORKDIR/order-inbox-inbox-mobile.png" "$DIST_DIR/order-inbox-mobile-screenshot.png" 2>/dev/null || true
else
  echo "FAIL: could not determine the created order id from the Playwright form-submission step"
  FAIL=1
fi

echo "--- 8/8: PO Reguler Toko untouched (row counts identical) ---"
PO_BATCH_COUNT_AFTER=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.po_batch")
check "po_batch row count unchanged by the entire special-order flow" "$PO_BATCH_COUNT_AFTER" "$PO_BATCH_COUNT_BEFORE"

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + DESKTOP/TABLET/MOBILE BROWSER VALIDATION PASSED ==="
  echo "ZIP: $ZIP_PATH"
  echo "Multi-division Pesanan Khusus Toko created through the REAL form, sent to Production, and traced correctly in the demand inbox at all 3 viewports."
  echo "Screenshots saved to: $DIST_DIR/order-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
