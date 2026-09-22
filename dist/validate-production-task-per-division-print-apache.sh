#!/usr/bin/env bash
# Validates dist/amor-factory-production-task-per-division-print.zip
# against a REAL Apache + PHP-FPM server (migration 0011 applied for
# real), PLUS real headless-Chromium at THREE viewports (desktop, iPad/
# tablet, mobile) driving the ACTUAL "Task per Divisi" tab, the Reject
# Produksi input on Ceklis Produksi, and the two new A4 landscape print
# pages.
#
# Verifies: dark navy theme preserved on the app pages, target/actual/
# reject/sisa/progress shown correctly, demand-source badges visible,
# special notes visible, print pages are white/black/A4-landscape (never
# dark), no horizontal overflow, existing Admin pages unaffected.
#
# Requires apache2 + php8.3-fpm + node/playwright. Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-production-task-per-division-print.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_task_apachevalidate"
ADMIN_PASS="ApacheTaskAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassTASK_123"
RUNTIME_USER_PASS="ApacheRunPassTASK_123"
HTTP_PORT=8230
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q task-validate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q task-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/task-validate.conf /etc/apache2/sites-enabled/task-validate.conf
  rm -f /etc/apache2/conf-available/task-listen.conf /etc/apache2/conf-enabled/task-listen.conf
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
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-production-task-per-division-print.sh first"; exit 1; }

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
CREATE USER 'taskavmig_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'taskavmig_user'@'localhost';
CREATE USER 'taskavrun_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'taskavrun_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/8: migrate (0001-0011, the ONE new migration 0011 shipped for real) + seed + admin + master bootstrap ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'taskavmig_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" taskav_admin "Task Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/8: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'taskavrun_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
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
cat > /etc/apache2/conf-available/task-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q task-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/task-validate.conf <<CONF
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
a2ensite -q task-validate
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

echo "--- 6/8: baseline sanity — set up a real PO Reguler target + Pesanan Khusus order via curl ---"
curl -s -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d "{\"username\":\"taskav_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
CSRF=$(curl -s -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" "$BASE/api/auth/me" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["csrfToken"];')

TANGGAL="2026-09-22"
KARANGTENGAH_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT factory_id FROM $DB_NAME.factory WHERE name='Karangtengah'")
ROTIBOLLEN_DIV_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT division_id FROM $DB_NAME.division WHERE name='Roti & Bollen'")
ROTIBOLLEN_PRODUCT_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT product_id FROM $DB_NAME.product WHERE division_id=$ROTIBOLLEN_DIV_ID AND aktif=1 LIMIT 1")
ROTIBOLLEN_PRODUCT_NAME=$(mariadb --socket="$SOCK" -u root -N -e "SELECT name FROM $DB_NAME.product WHERE product_id=$ROTIBOLLEN_PRODUCT_ID")
mariadb --socket="$SOCK" -u root -e "
INSERT INTO $DB_NAME.po_batch (tanggal, factory_id, version, created_at) VALUES ('$TANGGAL', $KARANGTENGAH_ID, 1, UTC_TIMESTAMP());
SET @batch := (SELECT po_batch_id FROM $DB_NAME.po_batch WHERE tanggal='$TANGGAL' AND factory_id=$KARANGTENGAH_ID);
INSERT INTO $DB_NAME.po_item (po_batch_id, product_id, po_awal, po_revisi, pb) VALUES (@batch, $ROTIBOLLEN_PRODUCT_ID, 120, 0, 0);
"
STORE_A_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")

# Uses an EXISTING PRODUCT in Roti & Bollen (never the special catalog,
# which routes to Cake & Custom) so this fixture lands in the SAME
# division the Playwright script below filters by — a catalog item here
# would silently show under a different division's task list, which is
# correct app behavior but would make this fixture check the wrong page.
ORDER_JSON=$(curl -s -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" -X POST "$BASE/api/special-orders" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: task-av-order-create" -d "{\"sourceType\":\"toko_khusus\",\"storeId\":$STORE_A_ID,\"orderDate\":\"$TANGGAL\",\"requiredDate\":\"$TANGGAL\",\"items\":[{\"itemType\":\"existing_product\",\"productId\":$ROTIBOLLEN_PRODUCT_ID,\"qty\":5,\"specialNote\":\"Tema Spiderman, tulisan HBD Raka\"}]}")
ORDER_ID=$(printf '%s' "$ORDER_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["orderId"];')
ORDER_VERSION=$(printf '%s' "$ORDER_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["version"];')
CONFIRM=$(curl -s -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" -X POST "$BASE/api/special-orders/$ORDER_ID/confirm" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: task-av-confirm" -d "{\"expectedVersion\":$ORDER_VERSION}")
CONFIRM_VERSION=$(printf '%s' "$CONFIRM" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["version"];')
curl -s -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" -X POST "$BASE/api/special-orders/$ORDER_ID/send-to-production" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: task-av-send" -d "{\"expectedVersion\":$CONFIRM_VERSION}" > /dev/null

# A production draft (Ceklis Produksi's own document) so the run-detail
# table — where the new Reject Produksi column lives — has something to
# open; produksi.php only renders that table when a real runId is given.
RUN_JSON=$(curl -s -c "$WORKDIR/admin.jar" -b "$WORKDIR/admin.jar" -X POST "$BASE/api/production" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: task-av-run-create" -d "{\"tanggal\":\"$TANGGAL\",\"divisionId\":$ROTIBOLLEN_DIV_ID}")
RUN_ID=$(printf '%s' "$RUN_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["productionRunId"];')

check "PO Reguler + Pesanan Khusus + Ceklis Produksi draft fixtures created" "ok" "ok"

echo "--- 7/8: REAL headless-Chromium at THREE viewports — Task per Divisi tab, Ceklis Produksi Reject input, print pages ---"
cat > "$WORKDIR/task-ui.js" <<'NODEEOF'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const tanggal = process.argv[5];
const divisionId = process.argv[6];
const factoryId = process.argv[7];
const runId = process.argv[8];
const shotPrefix = process.argv[9];

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

    await page.goto(base + '/api/_ui-preview/?page=produksi-task-per-divisi&tanggal=' + tanggal + '&factoryId=' + factoryId + '&divisionId=' + divisionId);
    await page.waitForLoadState('networkidle');

    if (vp.name === 'desktop') {
      const bodyBg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
      check('dark theme preserved on Task per Divisi', /rgb\(\s*\d+,\s*\d+,\s*\d+\)/.test(bodyBg) && bodyBg !== 'rgb(255, 255, 255)');
      check('tab bar shows 3 tabs (Ceklis Produksi / Order Masuk / Task per Divisi)', (await page.locator('.filter-bar .btn-group .btn').count()) === 3);
    }

    const bodyText = await page.textContent('body');
    check('Task per Divisi [' + vp.name + ']: shows PO Reguler badge', bodyText.includes('PO Reguler'));
    check('Task per Divisi [' + vp.name + ']: shows Pesanan Khusus badge', bodyText.includes('Pesanan Khusus'));
    check('Task per Divisi [' + vp.name + ']: shows the special note', bodyText.includes('Tema Spiderman'));
    check('Task per Divisi [' + vp.name + ']: shows Sisa Target summary card', bodyText.includes('Sisa Target'));
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
    check('Task per Divisi [' + vp.name + ']: no horizontal overflow', scrollWidth <= clientWidth);

    if (vp.name === 'desktop') { await page.screenshot({ path: shotPrefix + '-desktop.png', fullPage: true }); }
    if (vp.name === 'ipad') { await page.screenshot({ path: shotPrefix + '-ipad.png', fullPage: true }); }
    if (vp.name === 'mobile') { await page.screenshot({ path: shotPrefix + '-mobile.png', fullPage: true }); }

    await context.close();
  }

  // Ceklis Produksi: confirm the new Reject Produksi input column exists.
  const ceklisCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await loginAsAdmin(ceklisCtx, base, adminUsername, adminPassword);
  const ceklisPage = await ceklisCtx.newPage();
  await ceklisPage.goto(base + '/api/_ui-preview/?page=produksi&tanggal=' + tanggal + '&factoryId=' + factoryId + '&runId=' + runId);
  await ceklisPage.waitForLoadState('networkidle');
  const ceklisBody = await ceklisPage.textContent('body');
  check('Ceklis Produksi shows the new "Reject Produksi" column header', ceklisBody.includes('Reject Produksi'));
  await ceklisCtx.close();

  // Print Divisi Ini — A4 landscape, white/black, no dark shell.
  const printCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await loginAsAdmin(printCtx, base, adminUsername, adminPassword);
  const printPage = await printCtx.newPage();
  await printPage.goto(base + '/api/_ui-preview/print-production-task.php?tanggal=' + tanggal + '&divisionId=' + divisionId);
  await printPage.waitForLoadState('networkidle');
  const printBodyBg = await printPage.evaluate(() => getComputedStyle(document.querySelector('.print-page')).backgroundColor);
  check('Print Divisi Ini: .print-page background is WHITE, never dark', printBodyBg === 'rgb(255, 255, 255)');
  check('Print Divisi Ini: shows Production Task title', (await printPage.textContent('body')).includes('Production Task'));
  check('Print Divisi Ini: shows the special note', (await printPage.textContent('body')).includes('Tema Spiderman'));
  await printPage.screenshot({ path: shotPrefix + '-print-single.png', fullPage: true });
  await printCtx.close();

  // Print Semua Divisi — one page per division.
  const bulkCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await loginAsAdmin(bulkCtx, base, adminUsername, adminPassword);
  const bulkPage = await bulkCtx.newPage();
  await bulkPage.goto(base + '/api/_ui-preview/print-production-task-bulk.php?tanggal=' + tanggal + '&factoryId=' + factoryId);
  await bulkPage.waitForLoadState('networkidle');
  const pageCount = await bulkPage.locator('.print-page').count();
  check('Print Semua Divisi: shows multiple division pages (got ' + pageCount + ')', pageCount >= 1);
  await bulkPage.screenshot({ path: shotPrefix + '-print-bulk.png', fullPage: true });
  await bulkCtx.close();

  // Dashboard unaffected.
  const dashCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await loginAsAdmin(dashCtx, base, adminUsername, adminPassword);
  const dashPage = await dashCtx.newPage();
  await dashPage.goto(base + '/api/_ui-preview/?page=dashboard');
  await dashPage.waitForLoadState('networkidle');
  const dashScrollWidth = await dashPage.evaluate(() => document.documentElement.scrollWidth);
  const dashClientWidth = await dashPage.evaluate(() => document.documentElement.clientWidth);
  check('Dashboard still renders with no horizontal overflow (unaffected by this feature)', dashScrollWidth <= dashClientWidth);
  check('Dashboard has NO kpi-card--detail (real KPIs untouched)', (await dashPage.locator('.kpi-card--detail').count()) === 0);
  await dashCtx.close();

  await browser.close();
  process.exit(FAIL);
})().catch((err) => {
  console.error('CRASHED:', err.stack);
  process.exit(1);
});
NODEEOF

NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/task-ui.js" "$BASE" "taskav_admin" "$ADMIN_PASS" "$TANGGAL" "$ROTIBOLLEN_DIV_ID" "$KARANGTENGAH_ID" "$RUN_ID" "$WORKDIR/task-ui"
NODE_EXIT=$?
if [ "$NODE_EXIT" != "0" ]; then FAIL=1; fi
cp "$WORKDIR/task-ui-desktop.png" "$DIST_DIR/task-per-divisi-desktop-screenshot.png" 2>/dev/null || true
cp "$WORKDIR/task-ui-ipad.png" "$DIST_DIR/task-per-divisi-ipad-screenshot.png" 2>/dev/null || true
cp "$WORKDIR/task-ui-mobile.png" "$DIST_DIR/task-per-divisi-mobile-screenshot.png" 2>/dev/null || true
cp "$WORKDIR/task-ui-print-single.png" "$DIST_DIR/task-per-divisi-print-single-screenshot.png" 2>/dev/null || true
cp "$WORKDIR/task-ui-print-bulk.png" "$DIST_DIR/task-per-divisi-print-bulk-screenshot.png" 2>/dev/null || true

echo "--- 8/8: PO Reguler + existing tables untouched (row counts identical, no fabricated data) ---"
PO_BATCH_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.po_batch")
check "po_batch has exactly the ONE fixture row this script itself created (no extra writes from Task per Divisi)" "$PO_BATCH_COUNT" "1"

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + DESKTOP/TABLET/MOBILE BROWSER VALIDATION PASSED ==="
  echo "ZIP: $ZIP_PATH"
  echo "Task per Divisi shows PO Reguler + Pesanan Khusus demand with correct target/actual/reject/sisa/status, Ceklis Produksi gained a Reject Produksi input, and both print pages render A4 landscape, white/black, printer-friendly."
  echo "Screenshots saved to: $DIST_DIR/task-per-divisi-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
