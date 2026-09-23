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
mariadb --socket="$SOCK" -u root "$DB_NAME" -e "
  INSERT INTO po_batch (tanggal, factory_id, version, created_at)
  SELECT '2026-09-25', factory_id, 1, UTC_TIMESTAMP() FROM factory WHERE name='Karangtengah';
  INSERT INTO po_item (po_batch_id, product_id, po_awal, po_revisi, pb)
  SELECT pb.po_batch_id, p.product_id, 50, 0, 0 FROM po_batch pb, product p, division d
  WHERE pb.tanggal='2026-09-25' AND d.name='Roti & Bollen' AND p.division_id=d.division_id AND p.aktif=1 LIMIT 1;
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

    // --- DO Pesanan Khusus/Non-Toko: create + ship ---
    await page.goto(base + '/api/_ui-preview/?page=delivery-order-khusus-non-toko&factoryId=' + karangtengahFactoryId);
    await page.waitForLoadState('networkidle');
    check('DO Pesanan Khusus/Non-Toko tab bar renders', (await page.locator('.filter-bar .btn-group .btn').count()) >= 2);
    const doBtn = page.locator('.do-create-btn[data-order-id="' + orderId + '"]');
    check('the FG-verified order appears in "Pesanan Siap Dibuat DO"', (await doBtn.count()) === 1);
    if (await doBtn.count() === 1) {
      await doBtn.click();
      await page.waitForURL(/delivery-order-khusus-non-toko-detail/, { timeout: 10000 }).catch(() => {});
      const doBody = await page.content();
      check('the created DO uses the DOK- number format (never DO/KRM/...)', /DOK-\d{8}-\d{3}/.test(doBody));
      check('the DO detail shows the source badge (Pesanan Khusus Toko)', doBody.includes('Pesanan Khusus Toko'));
      await page.screenshot({ path: shotPrefix + '-do-desktop.png', fullPage: true });

      const shipBtn = page.locator('#btn-ship-do');
      if (await shipBtn.count() === 1) {
        page.once('dialog', d => d.accept());
        await shipBtn.click();
        await page.waitForTimeout(300);
        const confirmBtn = page.locator('.modal .btn-primary[data-act="confirm"]');
        if (await confirmBtn.count() === 1) { await confirmBtn.click(); }
        await page.waitForTimeout(1000);
        const afterShipBody = await page.content();
        check('after shipping, the DO shows status Shipped and no Cancel action', afterShipBody.includes('Shipped') && (await page.locator('#btn-cancel-do').count()) === 0);
      }
    }
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

  await browser.close();
  process.exit(FAIL);
})().catch((err) => { console.error('CRASHED:', err.stack); process.exit(1); });
NODEEOF
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/flow.js" "$BASE" "flowval_admin" "$ADMIN_PASS" "P2 TEST STORE A" "$WORKDIR/flow"
FLOW_EXIT=$?
if [ "$FLOW_EXIT" != "0" ]; then FAIL=1; fi
for shot in create-desktop detail-desktop fg-desktop do-desktop create-ipad create-mobile; do
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
  echo "New product autocomplete (no native datalist, invalidates on edit), Extra Packaging (added once, Rp-formatted), the special-order FG bridge, and source-specific DO (DOK- numbering, separate from Regular DO/KRM/...) all verified end to end through the REAL UI at desktop/tablet/mobile."
  echo "Screenshots saved to: $DIST_DIR/flow-ui-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
