#!/usr/bin/env bash
# Validates dist/amor-factory-order-entry-import-po-ui-rework.zip against
# a REAL Apache + PHP-FPM server, PLUS real headless-Chromium driving:
#   Part A: Pesanan Khusus Toko / Pesanan Non-Toko compact order-item
#     table — autocomplete product_id safety (select, then retype to
#     invalidate), 50-row scalability (bounded scroll, no giant page),
#     row delete not corrupting neighbors, real save for both pages, and
#     an iPad (768x1024) layout check.
#   Part B: Import / Revisi PO Toko — confirms the dark Amor Factory
#     shell (sidebar/topbar) replaced the old white "Phase 2 Fast-Track"
#     page, then drives a REAL upload -> preview -> commit cycle with an
#     actual Karangtengah-layout CSV file (existing-product-via-legacy-
#     code resolution, single-store allocation — the same mechanism the
#     task's own BLN004/ABGN UAT sample exercises; that literal product/
#     store only exists in the real live catalog, not this disposable
#     test database, so a locally-seeded equivalent is used here).
#
# Requires apache2 + php8.3-fpm + node/playwright. Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-order-entry-import-po-ui-rework.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_uirework"
ADMIN_PASS="ApacheUiReworkAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheUiReworkMigPass_123"
RUNTIME_USER_PASS="ApacheUiReworkRunPass_123"
HTTP_PORT=8418
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q uirework >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q uirework-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/uirework.conf /etc/apache2/sites-enabled/uirework.conf
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
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-order-entry-import-po-ui-rework.sh first"; exit 1; }

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
CREATE USER 'uirwmig_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'uirwmig_user'@'localhost';
CREATE USER 'uirwrun_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'uirwrun_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/10: migrate (0001-0013) + seed + admin user + master bootstrap ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'uirwmig_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" uirw_admin "UI Rework Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/10: writing config.php DIRECTLY INTO THE EXTRACTED TREE ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'uirwrun_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
    'APP_BASE_URL' => 'http://127.0.0.1:$HTTP_PORT',
    'MAIL_ENABLED' => false,
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
cat > /etc/apache2/conf-available/uirework-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q uirework-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/uirework.conf <<CONF
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
a2ensite -q uirework
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

echo "--- 6/10: seeding real fixture data (existing product w/ legacy code, TSA store alias, orders for the pesanan tests) ---"
KARANGTENGAH_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT factory_id FROM $DB_NAME.factory WHERE name='Karangtengah'")

SEED_JSON=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
Config::load();
$pdo = Database::pdo();

$row = $pdo->query("SELECT p.product_id, p.name, plc.legacy_code FROM product p INNER JOIN product_legacy_code plc ON plc.product_id = p.product_id ORDER BY p.product_id LIMIT 1")->fetch();
$storeId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = " . $pdo->quote("P2 TEST STORE A"))->fetchColumn();

echo json_encode([
    "productId" => (int) $row["product_id"],
    "productName" => $row["name"],
    "legacyCode" => $row["legacy_code"],
    "storeId" => $storeId,
]);
')
echo "SEED_JSON=$SEED_JSON"
KTG_PRODUCT_ID=$(php -r '$d=json_decode($argv[1],true); echo $d["productId"];' "$SEED_JSON")
KTG_PRODUCT_NAME=$(php -r '$d=json_decode($argv[1],true); echo $d["productName"];' "$SEED_JSON")
KTG_LEGACY_CODE=$(php -r '$d=json_decode($argv[1],true); echo $d["legacyCode"];' "$SEED_JSON")
[ -n "$KTG_PRODUCT_ID" ] && [ -n "$KTG_LEGACY_CODE" ] || { echo "REFUSING: could not find a seeded product with a legacy code"; exit 1; }

echo "--- 7/10: building a real Karangtengah-layout CSV fixture (single store TSA, existing-product-via-legacy-code) ---"
KARANGTENGAH_CSV="$WORKDIR/karangtengah-uat.csv"
python3 - "$KARANGTENGAH_CSV" "$KTG_LEGACY_CODE" "$KTG_PRODUCT_NAME" <<'PYEOF'
import csv, sys
path, code, name = sys.argv[1], sys.argv[2], sys.argv[3]
with open(path, 'w', newline='') as f:
    w = csv.writer(f)
    w.writerow(['NO', 'KATEGORI', 'KODE', 'NAMA PRODUK', 'TSA', 'TOTAL', 'TSA', 'TOTAL', 'TSA', 'TOTAL'])
    w.writerow(['', '', '', '', 'TSA', '', 'TSA', '', 'TSA', ''])
    w.writerow([1, 'UAT', code, name, 5, 5, 0, 0, 0, 0])
PYEOF
[ -f "$KARANGTENGAH_CSV" ] || { echo "REFUSING: could not build the Karangtengah UAT CSV fixture"; exit 1; }
cat "$KARANGTENGAH_CSV"

echo "--- 8/10: REAL headless-Chromium — Part A (compact order-item table) ---"
cat > "$WORKDIR/order-table.js" <<'NODEEOF'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const shotPrefix = process.argv[5];

let FAIL = 0;
function check(desc, cond) { if (cond) { console.log('PASS: ' + desc); } else { console.log('FAIL: ' + desc); FAIL = 1; } }

async function loginAsAdmin(context, base, username, password) {
  const page = await context.newPage();
  await page.goto(base + '/', { waitUntil: 'domcontentloaded', timeout: 15000 });
  await page.evaluate(async ({ base, username, password }) => {
    const r = await fetch(base + '/api/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
    if (!r.ok) throw new Error('login HTTP ' + r.status);
  }, { base, username, password });
  await page.close();
}

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    args: ['--no-sandbox'],
  });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await loginAsAdmin(context, base, adminUsername, adminPassword);
  const page = await context.newPage();
  page.on('pageerror', err => console.log('  [pageerror]', err.message));

  // ==================================================================
  // Pesanan Khusus Toko: compact table replaces the old cards.
  // ==================================================================
  await page.goto(base + '/api/_ui-preview/?page=pesanan-khusus-toko', { waitUntil: 'networkidle' });
  check('PKT: the compact order-item TABLE is present', (await page.locator('#pkt-items .order-item-table').count()) === 1);
  check('PKT: the old one-card-per-item layout is GONE', (await page.locator('#pkt-items .order-item-card').count()) === 0);
  check('PKT: the table wrapper is height-bounded (scrollable, not a giant page)', await page.locator('#pkt-items .order-item-table-wrap').evaluate(el => getComputedStyle(el).overflowY === 'auto' && parseInt(getComputedStyle(el).maxHeight, 10) > 0));
  check('PKT: exactly 1 row exists on load (the default first row)', (await page.locator('#pkt-items tbody tr').count()) === 1);

  // --- product_id autocomplete safety: select, then retype to invalidate ---
  const firstItemInput = page.locator('#pkt-items tbody tr').first().locator('.oit-item-input');
  await firstItemInput.fill('a');
  await page.waitForTimeout(200);
  const firstOption = page.locator('.ac-menu .ac-option').first();
  const hasOption = (await firstOption.count()) > 0;
  check('PKT: the autocomplete menu shows real suggestions', hasOption);
  let pickedName = null;
  if (hasOption) {
    pickedName = await firstOption.textContent();
    await firstOption.click();
  }
  await page.waitForTimeout(200);
  const divisionAfterPick = await page.locator('#pkt-items tbody tr').first().locator('td').nth(3).textContent();
  check('PKT: selecting a product populates a real Divisi badge (not the placeholder dash)', !divisionAfterPick.includes('—'));

  await firstItemInput.fill((pickedName || 'x') + ' extra text');
  await page.waitForTimeout(200);
  const divisionAfterRetype = await page.locator('#pkt-items tbody tr').first().locator('td').nth(3).textContent();
  check('PKT: retyping the Item text clears the stale product_id (Divisi badge resets to the placeholder)', divisionAfterRetype.includes('—'));
  // Re-select so this row is valid again for the later delete/save checks.
  await firstItemInput.fill('');
  await firstItemInput.fill((pickedName || 'a').slice(0, 3));
  await page.waitForTimeout(200);
  const anyOption = page.locator('.ac-menu .ac-option').first();
  if (await anyOption.count() > 0) { await anyOption.click(); }
  await page.waitForTimeout(200);

  // --- 50-row scalability ---
  for (let i = 0; i < 49; i++) {
    await page.click('#pkt-add-existing');
  }
  const rowCount = await page.locator('#pkt-items tbody tr').count();
  check('PKT: 50 rows were added successfully', rowCount === 50);
  const scrollHeight = await page.locator('#pkt-items .order-item-table-wrap').evaluate(el => el.scrollHeight);
  const clientHeight = await page.locator('#pkt-items .order-item-table-wrap').evaluate(el => el.clientHeight);
  check('PKT: with 50 rows, the table wrapper itself scrolls internally (scrollHeight > clientHeight, page stays compact)', scrollHeight > clientHeight);
  const bodyHeight = await page.evaluate(() => document.body.scrollHeight);
  check('PKT: the overall page is NOT a giant vertical page even with 50 items (body height stays under 4000px)', bodyHeight < 4000);
  await page.screenshot({ path: shotPrefix + '-pkt-50-rows.png', fullPage: true });

  // --- delete a middle row, confirm neighbors intact ---
  const lastRowNoBefore = await page.locator('#pkt-items tbody tr').last().locator('.oit-col-no').textContent();
  await page.locator('#pkt-items tbody tr').nth(25).locator('td').last().locator('button').click();
  const rowCountAfterDelete = await page.locator('#pkt-items tbody tr').count();
  check('PKT: deleting one row leaves exactly 49 rows', rowCountAfterDelete === 49);
  const lastRowNoAfter = await page.locator('#pkt-items tbody tr').last().locator('.oit-col-no').textContent();
  check('PKT: row numbers renumber correctly after a delete (neighbors not corrupted)', lastRowNoAfter === '49' && lastRowNoBefore === '50');

  // Remove all the extra rows, leaving just the first (valid) one, then save.
  while (await page.locator('#pkt-items tbody tr').count() > 1) {
    await page.locator('#pkt-items tbody tr').last().locator('td').last().locator('button').click();
  }
  await page.selectOption('#pkt-store', { index: 1 });
  await page.fill('#pkt-required-date', '2026-10-15');
  await page.click('#pkt-submit-draft');
  await page.waitForTimeout(1500);
  check('PKT: Pesanan Khusus Toko saves successfully (navigated to its detail page)', /pesanan-khusus-toko-detail/.test(page.url()));
  await page.screenshot({ path: shotPrefix + '-pkt-saved.png', fullPage: true });

  // ==================================================================
  // Pesanan Non-Toko: same compact table.
  // ==================================================================
  await page.goto(base + '/api/_ui-preview/?page=pesanan-non-toko', { waitUntil: 'networkidle' });
  check('PNT: the compact order-item TABLE is present', (await page.locator('#pnt-items .order-item-table').count()) === 1);
  check('PNT: the old one-card-per-item layout is GONE', (await page.locator('#pnt-items .order-item-card').count()) === 0);
  const pntItemInput = page.locator('#pnt-items tbody tr').first().locator('.oit-item-input');
  await pntItemInput.fill('a');
  await page.waitForTimeout(200);
  const pntOption = page.locator('.ac-menu .ac-option').first();
  if (await pntOption.count() > 0) { await pntOption.click(); }
  await page.waitForTimeout(200);
  await page.selectOption('#pnt-source', 'cs');
  await page.fill('#pnt-customer-name', 'UAT Rework Customer');
  await page.fill('#pnt-required-date', '2026-10-16');
  await page.click('#pnt-submit-draft');
  await page.waitForTimeout(1500);
  check('PNT: Pesanan Non-Toko saves successfully (navigated to its detail page)', /pesanan-non-toko-detail/.test(page.url()));
  await page.screenshot({ path: shotPrefix + '-pnt-saved.png', fullPage: true });

  // ==================================================================
  // iPad viewport (768x1024) for the Pesanan Khusus Toko form.
  // ==================================================================
  const ipadCtx = await browser.newContext({ viewport: { width: 768, height: 1024 } });
  await loginAsAdmin(ipadCtx, base, adminUsername, adminPassword);
  const ipage = await ipadCtx.newPage();
  await ipage.goto(base + '/api/_ui-preview/?page=pesanan-khusus-toko', { waitUntil: 'networkidle' });
  check('IPAD: the compact table is still used (not reverted to stacked cards)', (await ipage.locator('#pkt-items .order-item-table').count()) === 1);
  const ipadBodyScrollX = await ipage.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  check('IPAD: the PAGE itself never scrolls horizontally (only the table container does)', !ipadBodyScrollX);
  await ipage.screenshot({ path: shotPrefix + '-ipad-pkt.png', fullPage: true });
  await ipadCtx.close();

  await browser.close();
  process.exit(FAIL);
})().catch((err) => { console.error('CRASHED:', err.stack); process.exit(1); });
NODEEOF
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/order-table.js" "$BASE" "uirw_admin" "$ADMIN_PASS" "$WORKDIR/shot"
PW_EXIT_A=$?
if [ "$PW_EXIT_A" != "0" ]; then FAIL=1; fi
for shot in pkt-50-rows pkt-saved pnt-saved ipad-pkt; do
  cp "$WORKDIR/shot-$shot.png" "$DIST_DIR/uirework-$shot-screenshot.png" 2>/dev/null || true
done

echo "--- 9/10: REAL headless-Chromium — Part B (Import / Revisi PO Toko, real upload/preview/commit) ---"
cat > "$WORKDIR/import-po.js" <<'NODEEOF'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const csvPath = process.argv[5];
const shotPrefix = process.argv[6];

let FAIL = 0;
function check(desc, cond) { if (cond) { console.log('PASS: ' + desc); } else { console.log('FAIL: ' + desc); FAIL = 1; } }

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    args: ['--no-sandbox'],
  });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  await page.goto(base + '/', { waitUntil: 'domcontentloaded' });
  await page.evaluate(async ({ base, username, password }) => {
    const r = await fetch(base + '/api/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
    if (!r.ok) throw new Error('login HTTP ' + r.status);
  }, { base, username: adminUsername, password: adminPassword });

  await page.goto(base + '/api/_import-po/', { waitUntil: 'networkidle' });
  const bodyText = await page.content();
  check('IMPORT-PO: the dark Amor Factory sidebar shell is present', (await page.locator('.sidebar').count()) === 1);
  check('IMPORT-PO: the topbar is present', (await page.locator('.topbar').count()) === 1);
  check('IMPORT-PO: the new title "Import / Revisi PO Toko" is shown', /Import \/ Revisi PO Toko/.test(bodyText));
  check('IMPORT-PO: the legacy "Phase 2 Fast-Track" text is GONE', !/Phase 2 Fast-Track/i.test(bodyText));
  await page.screenshot({ path: shotPrefix + '-import-po-step1.png', fullPage: true });

  // --- Step 1: real upload of a real Karangtengah-layout CSV file ---
  await page.fill('input[name="tanggal"]', '2026-10-20');
  await page.selectOption('select[name="uploadType"]', 'initial');
  await page.setInputFiles('input[name="file"]', csvPath);
  await page.click('button:has-text("Proses & Preview")');
  await page.waitForLoadState('networkidle');
  const previewBody = await page.content();
  check('IMPORT-PO: Step 2 preview rendered inside the SAME dark shell', (await page.locator('.sidebar').count()) === 1);
  check('IMPORT-PO: preview shows Karangtengah as the detected factory', /Karangtengah/.test(previewBody));
  check('IMPORT-PO: preview shows 0 unresolved products (the seeded legacy-code product resolved to an EXISTING product, not a new one)', /Produk BELUM terpetakan[\s\S]{0,200}badge-success/.test(previewBody) || (await page.locator('text=Produk Baru Terdeteksi').count()) === 0);
  check('IMPORT-PO: preview shows Jumlah Toko = 1 (single-store allocation, TSA)', /Jumlah Toko[\s\S]{0,150}>1</.test(previewBody));
  await page.screenshot({ path: shotPrefix + '-import-po-step2.png', fullPage: true });

  // --- Commit ---
  page.once('dialog', d => d.accept());
  await page.click('button:has-text("Konfirmasi & Simpan PO Awal")');
  await page.waitForLoadState('networkidle');
  const successBody = await page.content();
  check('IMPORT-PO: the post-commit "PO berhasil disimpan" success card is shown', /PO berhasil disimpan/.test(successBody));
  check('IMPORT-PO: the success card is inside the SAME dark shell (never a legacy page)', (await page.locator('.sidebar').count()) === 1);
  await page.screenshot({ path: shotPrefix + '-import-po-success.png', fullPage: true });

  await page.goto(base + '/api/_import-po/', { waitUntil: 'networkidle' });
  const historyBody = await page.content();
  check('IMPORT-PO: "PO Tersimpan (Ringkasan)" now lists the just-imported batch', /2026-10-20/.test(historyBody) && /Karangtengah/.test(historyBody));

  // --- iPad viewport ---
  const ipadCtx = await browser.newContext({ viewport: { width: 768, height: 1024 } });
  const ipage = await ipadCtx.newPage();
  await ipage.goto(base + '/', { waitUntil: 'domcontentloaded' });
  await ipage.evaluate(async ({ base, username, password }) => {
    const r = await fetch(base + '/api/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
    if (!r.ok) throw new Error('login HTTP ' + r.status);
  }, { base, username: adminUsername, password: adminPassword });
  await ipage.goto(base + '/api/_import-po/', { waitUntil: 'networkidle' });
  check('IPAD IMPORT-PO: the page does not scroll horizontally as a whole', !(await ipage.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)));
  await ipage.screenshot({ path: shotPrefix + '-ipad-import-po.png', fullPage: true });
  await ipadCtx.close();

  await browser.close();
  process.exit(FAIL);
})().catch((err) => { console.error('CRASHED:', err.stack); process.exit(1); });
NODEEOF
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/import-po.js" "$BASE" "uirw_admin" "$ADMIN_PASS" "$KARANGTENGAH_CSV" "$WORKDIR/shot"
PW_EXIT_B=$?
if [ "$PW_EXIT_B" != "0" ]; then FAIL=1; fi
for shot in import-po-step1 import-po-step2 import-po-success ipad-import-po; do
  cp "$WORKDIR/shot-$shot.png" "$DIST_DIR/uirework-$shot-screenshot.png" 2>/dev/null || true
done

echo "--- 10/10: DB-level verification (the CSV import actually resolved to the EXISTING seeded product, not a duplicate) ---"
PRODUCT_COUNT_FOR_NAME=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.product WHERE product_id = $KTG_PRODUCT_ID")
check "the seeded product row is still exactly one (no duplicate product was created by the import)" "$PRODUCT_COUNT_FOR_NAME" "1"
PO_ITEM_LINKS_TO_SAME_PRODUCT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.po_item WHERE product_id = $KTG_PRODUCT_ID AND po_awal = 5")
check "the imported PO line links to the SAME existing product_id with po_awal=5" "$PO_ITEM_LINKS_TO_SAME_PRODUCT" "1"

echo "--- debug: last 30 lines of apache error log ---"
tail -30 "$WORKDIR/apache-error.log" 2>/dev/null || true

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + PHP-FPM + BROWSER VALIDATION PASSED (Order Entry + Import PO UI Rework) ==="
  echo "ZIP: $ZIP_PATH"
  echo "Screenshots saved to: $DIST_DIR/uirework-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
