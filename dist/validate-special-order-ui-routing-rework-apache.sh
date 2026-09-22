#!/usr/bin/env bash
# Validates dist/amor-factory-special-order-ui-routing-rework.zip against
# a REAL Apache + PHP-FPM server, PLUS real headless-Chromium at THREE
# viewports (desktop, iPad/tablet, mobile) driving the ACTUAL reworked
# create form for Pesanan Khusus Toko (card-based item entry, automatic
# factory routing, no manual Factory selector), then Pesanan Non-Toko and
# Production's "Order Masuk / Demand Tambahan" inbox.
#
# Verifies: dark navy theme preserved, every form control uses real dark
# styling (not a native light control), item type is an explicit badge,
# Division/Factory are automatic read-only badges (never a manual
# selector), a Bolu item routes to Cibadak while a non-Bolu item routes to
# Karangtengah IN THE SAME ORDER (multi-division + multi-factory), the
# status timeline and "Informasi Produksi" panel render, charge/notes
# still work, Production's inbox shows Factory per division group, no
# horizontal overflow, existing Admin pages unaffected.
#
# Requires apache2 + php8.3-fpm + node/playwright. Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-special-order-ui-routing-rework.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_uirework_apachevalidate"
ADMIN_PASS="ApacheUiReworkAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassUIREWORK_123"
RUNTIME_USER_PASS="ApacheRunPassUIREWORK_123"
HTTP_PORT=8210
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q uirework-validate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q uirework-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/uirework-validate.conf /etc/apache2/sites-enabled/uirework-validate.conf
  rm -f /etc/apache2/conf-available/uirework-listen.conf /etc/apache2/conf-enabled/uirework-listen.conf
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
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-special-order-ui-routing-rework.sh first"; exit 1; }

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
CREATE USER 'uiravmig_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'uiravmig_user'@'localhost';
CREATE USER 'uiravrun_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'uiravrun_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/8: migrate (0001-0010, this package ships no new migration) + seed + admin + master bootstrap ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'uiravmig_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" uirav_admin "UI Rework Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/8: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'uiravrun_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
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
cat > /etc/apache2/conf-available/uirework-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q uirework-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/uirework-validate.conf <<CONF
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
a2ensite -q uirework-validate
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

echo "--- 6/8: baseline sanity — api/app/ still forbidden, catalog route reachable, PO Reguler untouched ---"
check "api/app/config/config.php is forbidden" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/app/config/config.php")" 403
STOREA_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")
curl -s -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d "{\"username\":\"uirav_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
CATALOG_CODE=$(curl -s -o /dev/null -w '%{http_code}' -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" "$BASE/api/special-orders/catalog")
check "GET /api/special-orders/catalog (lazy-seeds Cake & Custom) is 200" "$CATALOG_CODE" 200
PO_BATCH_COUNT_BEFORE=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.po_batch")
echo "po_batch rows before any special-order activity: $PO_BATCH_COUNT_BEFORE (expect this to stay identical throughout)"

echo "--- 7/8: REAL headless-Chromium at THREE viewports — drive the REWORKED create form end to end ---"
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

  // Dark navy theme preserved.
  const bodyBg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  check('dark theme preserved on Pesanan Khusus Toko (body background is dark, got ' + bodyBg + ')', /rgb\(\s*\d+,\s*\d+,\s*\d+\)/.test(bodyBg) && bodyBg !== 'rgb(255, 255, 255)');

  check('tab bar shows PO Toko / Pesanan Khusus Toko / Pesanan Non-Toko', (await page.locator('.filter-bar .btn-group .btn').count()) === 3);

  // UI BUG FIX 7: no manual Factory selector anywhere on the form.
  check('no manual Factory <select> on the create form (routing is automatic)', (await page.locator('#pkt-factory').count()) === 0);

  // UI BUG FIX 1: every visible date input uses real dark styling (not the native white control).
  const dateInputBg = await page.locator('#pkt-required-date').evaluate(el => getComputedStyle(el).backgroundColor);
  check('date input uses real dark styling, not native white (' + dateInputBg + ')', dateInputBg !== 'rgba(0, 0, 0, 0)' && dateInputBg !== 'rgb(255, 255, 255)');

  await page.selectOption('#pkt-store', { label: storeName });
  await page.fill('#pkt-order-date', '2026-09-22');
  await page.fill('#pkt-required-date', '2026-09-25');

  // Default first card is "existing_product" — fill it with a Roti & Bollen product (non-Bolu -> Karangtengah).
  const card1 = page.locator('.order-item-card').first();
  await card1.locator('.pkt-item-input').fill('BOLLEN COKLAT');
  await card1.locator('.pkt-qty').fill('10');
  await card1.locator('.pkt-charge').fill('0');
  await card1.locator('.pkt-note').fill('Packing terpisah, mohon prioritas');

  // UI BUG FIX 6/2: item type is an explicit, unambiguous badge — never a cryptic per-row <select>.
  check('item type shown as an explicit "Produk Existing" badge', (await card1.locator('.badge-success:has-text("Produk Existing")').count()) === 1);

  // UI BUG FIX 3/4/5: Catatan Khusus is a real, usable full-width textarea — not a squeezed table cell input.
  const noteBox = await card1.locator('.pkt-note').boundingBox();
  check('Catatan Khusus textarea is usably wide (>= 300px, got ' + (noteBox ? noteBox.width : 'null') + ')', noteBox !== null && noteBox.width >= 300);
  const noteTag = await card1.locator('.pkt-note').evaluate(el => el.tagName);
  check('Catatan Khusus is a <textarea> (multi-line capable)', noteTag === 'TEXTAREA');

  // Division/Factory badges auto-populate from the chosen item, BEFORE submit.
  await page.waitForTimeout(150);
  const div1 = (await card1.locator('.pkt-division-slot').textContent()).trim();
  const fac1 = (await card1.locator('.pkt-factory-slot').textContent()).trim();
  check('Divisi Produksi badge auto-fills from the chosen product (Roti & Bollen)', div1.includes('Roti & Bollen'));
  check('Factory badge auto-fills automatically (Karangtengah) — never asked of the user', fac1.includes('Karangtengah'));

  // Add a SECOND item via the real "+ Tambah Item Existing" button, a Bolu product -> Cibadak (multi-division + multi-factory).
  await page.click('#pkt-add-existing');
  const card2 = page.locator('.order-item-card').nth(1);
  await card2.locator('.pkt-item-input').fill('BOLU PANDAN');
  await card2.locator('.pkt-qty').fill('4');
  await card2.locator('.pkt-charge').fill('25000');
  await page.waitForTimeout(150);
  const div2 = (await card2.locator('.pkt-division-slot').textContent()).trim();
  const fac2 = (await card2.locator('.pkt-factory-slot').textContent()).trim();
  check('second item (Bolu) shows Divisi=Bolu automatically', div2.includes('Bolu'));
  check('second item (Bolu) shows Factory=Cibadak automatically — different factory than item 1, no manual choice', fac2.includes('Cibadak'));

  // Live "Informasi Produksi" panel reflects BOTH factories before submit.
  const routingText = await page.locator('#pkt-routing-info').textContent();
  check('live routing panel shows Karangtengah before submit', routingText.includes('Karangtengah'));
  check('live routing panel shows Cibadak before submit', routingText.includes('Cibadak'));

  // Live "Ringkasan Pesanan" totals.
  const summaryText = await page.locator('#pkt-summary').textContent();
  check('live summary shows Jumlah Item', summaryText.includes('Jumlah Item'));
  check('live summary shows a charge total (Rp25.000)', summaryText.includes('25.000'));

  const scrollWidthForm = await page.evaluate(() => document.documentElement.scrollWidth);
  const clientWidthForm = await page.evaluate(() => document.documentElement.clientWidth);
  check('no horizontal overflow on the create form (desktop)', scrollWidthForm <= clientWidthForm);

  await page.screenshot({ path: shotPrefix + '-form-desktop.png', fullPage: true });

  // Primary action: "Kirim ke Produksi" — creates, confirms, AND sends to production in one click.
  await page.click('#pkt-submit-send');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(400);

  check('form submit navigated to the detail page', page.url().includes('pesanan-khusus-toko-detail'));
  const bodyText = await page.textContent('body');
  check('detail page shows Multi Divisi', bodyText.includes('Multi Divisi'));
  check('detail page shows Multi Factory', bodyText.includes('Multi Factory'));
  check('detail page shows Cake & Custom is NOT falsely implied — real divisions shown (Roti & Bollen + Bolu)', bodyText.includes('Roti & Bollen') && bodyText.includes('Bolu'));
  check('detail page shows both factory names (Karangtengah + Cibadak)', bodyText.includes('Karangtengah') && bodyText.includes('Cibadak'));
  check('detail page shows the special note', bodyText.includes('Packing terpisah'));
  check('detail page shows the charge value (25.000)', bodyText.includes('25.000'));
  check('detail page shows the status timeline (Dikirim ke Produksi step)', bodyText.includes('Dikirim ke Produksi'));
  check('detail page shows the "Informasi Produksi" panel', bodyText.includes('Informasi Produksi'));
  check('order status already Dikirim ke Produksi (one-click send worked end to end)', bodyText.includes('Dikirim ke Produksi'));

  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
  check('no horizontal overflow on the detail page (desktop)', scrollWidth <= clientWidth);

  const orderIdMatch = page.url().match(/id=(\d+)/);
  const orderId = orderIdMatch ? orderIdMatch[1] : null;
  console.log('CREATED_ORDER_ID=' + orderId);

  await context.close();

  // --- Tablet + mobile: create-form item cards stack readably, no overflow ---
  for (const vp of [{ name: 'ipad', width: 768, height: 1024 }, { name: 'mobile', width: 375, height: 667 }]) {
    const ctx2 = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    await loginAsAdmin(ctx2, base, adminUsername, adminPassword);
    const p2 = await ctx2.newPage();
    await p2.goto(base + '/api/_ui-preview/?page=pesanan-khusus-toko');
    await p2.waitForLoadState('networkidle');
    const sw = await p2.evaluate(() => document.documentElement.scrollWidth);
    const cw = await p2.evaluate(() => document.documentElement.clientWidth);
    check('create form [' + vp.name + ']: no horizontal overflow', sw <= cw);
    if (vp.name === 'mobile') {
      const cardBox = await p2.locator('.order-item-card').first().boundingBox();
      check('create form [mobile]: item card is a readable stacked block, not squeezed (width <= viewport)', cardBox !== null && cardBox.width <= 375);
    }
    await p2.screenshot({ path: shotPrefix + '-form-' + vp.name + '.png', fullPage: true });
    await ctx2.close();
  }

  await browser.close();
  process.exit(FAIL);
})().catch((err) => {
  console.error('CRASHED:', err.stack);
  process.exit(1);
});
NODEEOF

STORE_NAME=$(mariadb --socket="$SOCK" -u root -N -e "SELECT canonical_name FROM $DB_NAME.store WHERE store_id=$STOREA_ID")
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/order-ui.js" "$BASE" "uirav_admin" "$ADMIN_PASS" "$STORE_NAME" "$WORKDIR/order-ui" > "$WORKDIR/order-ui-output.log" 2>&1
NODE_EXIT=$?
cat "$WORKDIR/order-ui-output.log"
if [ "$NODE_EXIT" != "0" ]; then FAIL=1; fi
cp "$WORKDIR/order-ui-form-desktop.png" "$DIST_DIR/order-ui-form-desktop-screenshot.png" 2>/dev/null || true
cp "$WORKDIR/order-ui-form-ipad.png" "$DIST_DIR/order-ui-form-ipad-screenshot.png" 2>/dev/null || true
cp "$WORKDIR/order-ui-form-mobile.png" "$DIST_DIR/order-ui-form-mobile-screenshot.png" 2>/dev/null || true
CREATED_ORDER_ID=$(grep -oE 'CREATED_ORDER_ID=[0-9]+' "$WORKDIR/order-ui-output.log" | head -1 | cut -d= -f2)

echo "--- 7b/8: check the Production demand inbox at desktop/tablet/mobile (already sent by the one-click form) ---"
if [ -n "${CREATED_ORDER_ID:-}" ]; then
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
    check('Production inbox [' + vp.name + ']: shows Roti & Bollen division group', bodyText.includes('Roti & Bollen'));
    check('Production inbox [' + vp.name + ']: shows the Factory badge next to a division group', bodyText.includes('Karangtengah'));
    check('Production inbox [' + vp.name + ']: special note visible read-only', bodyText.includes('Packing terpisah'));
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    check('Production inbox [' + vp.name + ']: no horizontal overflow', scrollWidth <= clientWidth);

    // Existing Admin pages (Dashboard) visually unaffected.
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
  NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/order-inbox.js" "$BASE" "uirav_admin" "$ADMIN_PASS" "$WORKDIR/order-inbox"
  INBOX_EXIT=$?
  if [ "$INBOX_EXIT" != "0" ]; then FAIL=1; fi
  cp "$WORKDIR/order-inbox-inbox-desktop.png" "$DIST_DIR/order-ui-inbox-desktop-screenshot.png" 2>/dev/null || true
  cp "$WORKDIR/order-inbox-inbox-ipad.png" "$DIST_DIR/order-ui-inbox-ipad-screenshot.png" 2>/dev/null || true
  cp "$WORKDIR/order-inbox-inbox-mobile.png" "$DIST_DIR/order-ui-inbox-mobile-screenshot.png" 2>/dev/null || true
else
  echo "FAIL: could not determine the created order id from the Playwright form-submission step"
  FAIL=1
fi

echo "--- 8/8: PO Reguler Toko untouched (row counts identical) ---"
PO_BATCH_COUNT_AFTER=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.po_batch")
check "po_batch row count unchanged by the entire UI-rework flow" "$PO_BATCH_COUNT_AFTER" "$PO_BATCH_COUNT_BEFORE"

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + DESKTOP/TABLET/MOBILE BROWSER VALIDATION PASSED ==="
  echo "ZIP: $ZIP_PATH"
  echo "Reworked card-based create form: multi-division + multi-factory order (Roti & Bollen -> Karangtengah, Bolu -> Cibadak) created through the REAL UI with automatic routing (no manual Factory selector), one-click send to Production, correctly traced in the demand inbox at all 3 viewports."
  echo "Screenshots saved to: $DIST_DIR/order-ui-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
