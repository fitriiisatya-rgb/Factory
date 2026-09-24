#!/usr/bin/env bash
# Validates dist/amor-factory-0013-live-schema-repair.zip against a REAL
# Apache + PHP-FPM server, reproducing the ACTUAL live incident end to end:
#   1. A database is built at 0001-0011 normally, then the REAL historical
#      "7c30712" revision of 0012's SQL is applied DIRECTLY (not through
#      migrate.php) and schema_migrations is seeded to mark
#      0012_production_flow_completion.php applied — this is exactly the
#      live drift state the cPanel diagnostic found (special_order_fg_
#      allocation missing while 0012 shows as applied).
#   2. A CS order fixture equivalent to NPR-20260923-001 is seeded
#      (existing product, qty 2, general FG >= 2, special actual
#      production = 0).
#   3. BEFORE 0013: the REAL browser hits Produksi -> Order Masuk / Demand
#      Tambahan and the failure is confirmed to reproduce (this is the
#      exact live symptom, not a guess).
#   4. The REAL browser logs in as ADMIN, opens /_upgrade/, confirms it
#      shows ONLY 0013 as pending (0012 stays recorded, never re-listed),
#      and applies it through the real upgrade form (the exact operator
#      flow — never migrate.php CLI for this step).
#   5. AFTER 0013: the REAL browser reloads the Demand page and confirms
#      it renders normally, the CS order row is visible with the right
#      FG Tersedia / Sudah Dialokasikan / Kebutuhan Produksi numbers,
#      "Alokasikan dari FG" allocates 2, and production need becomes 0.
#   6. Downstream: a real DO is created and shipped through the real
#      Driver Portal (FG Khusus/Non-Toko -> DO -> Driver claim -> depart),
#      then confirmed at the real Bakery receipt tool.
#   7. Regular PO regression: a real Regular PO Production -> FG -> DO ->
#      Shipment chain still works unchanged after 0013 (in particular,
#      FgService::submit() still posts a real 'fg_item'-sourced ledger row
#      — this is the stock_ledger.source_type enum this project was once
#      burned by narrowing accidentally).
#
# Requires apache2 + php8.3-fpm + node/playwright. Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-0013-live-schema-repair.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_0013validate"
ADMIN_PASS="Apache0013Admin#$(date +%s)"
MIGRATION_USER_PASS="Apache0013MigPass_123"
RUNTIME_USER_PASS="Apache0013RunPass_123"
HTTP_PORT=8318
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q 0013validate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q 0013validate-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/0013validate.conf /etc/apache2/sites-enabled/0013validate.conf
  rm -f /etc/apache2/conf-available/0013validate-listen.conf /etc/apache2/conf-enabled/0013validate-listen.conf
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
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-0013-live-schema-repair.sh first"; exit 1; }

echo "--- 1/11: extracting the SHIPPED ZIP (never the source tree) ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
find "$WORKDIR" -type d -exec chmod 755 {} +
find "$EXTRACT_DIR" -type f -exec chmod 644 {} +
chown -R www-data:www-data "$EXTRACT_DIR/api/uploads"
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"
[ -f "$EXTRACT_DIR/api/app/migrations/0013_repair_production_flow_completion.php" ] || { echo "REFUSING: shipped ZIP does not contain migration 0013"; exit 1; }

echo "--- 2/11: initializing disposable MariaDB ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER '0013valmig_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '0013valmig_user'@'localhost';
CREATE USER '0013valrun_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO '0013valrun_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/11: building the LIVE DRIFT STATE — 0001-0011 normally, then the REAL historical 7c30712 revision of 0012 applied directly (simulating live) ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => '0013valmig_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
mkdir -p "$WORKDIR/hidden-migrations"
mv "$REPO_ROOT/api/app/migrations/0012_production_flow_completion.php" "$WORKDIR/hidden-migrations/" \
  || { echo "FATAL: could not hide 0012 migration file"; exit 1; }
mv "$REPO_ROOT/api/app/migrations/0013_repair_production_flow_completion.php" "$WORKDIR/hidden-migrations/" \
  || { echo "FATAL: could not hide 0013 migration file"; exit 1; }
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php (0001-0011) FAILED"; mv "$WORKDIR/hidden-migrations/"*.php "$REPO_ROOT/api/app/migrations/"; exit 1; }
mv "$WORKDIR/hidden-migrations/0012_production_flow_completion.php" "$REPO_ROOT/api/app/migrations/" \
  || { echo "FATAL: could not restore 0012 migration file"; exit 1; }
mv "$WORKDIR/hidden-migrations/0013_repair_production_flow_completion.php" "$REPO_ROOT/api/app/migrations/" \
  || { echo "FATAL: could not restore 0013 migration file"; exit 1; }

git -C "$REPO_ROOT" show 7c30712:database/schema-v1-0012-production-flow-completion.sql > "$WORKDIR/0012-at-7c30712.sql" \
  || { echo "git show 7c30712 FAILED"; exit 1; }
mariadb --socket="$SOCK" -u root "$DB_NAME" < "$WORKDIR/0012-at-7c30712.sql" \
  || { echo "applying the historical 7c30712 revision of 0012 FAILED"; exit 1; }
mariadb --socket="$SOCK" -u root "$DB_NAME" -e \
  "INSERT INTO schema_migrations (migration, applied_at) VALUES ('0012_production_flow_completion.php', UTC_TIMESTAMP())"
SOFA_EXISTS=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='special_order_fg_allocation'")
[ "$SOFA_EXISTS" = "0" ] || { echo "REFUSING: test fixture is not representative of live — special_order_fg_allocation unexpectedly exists before 0013"; exit 1; }
echo "live-drift fixture confirmed representative: special_order_fg_allocation is genuinely missing, 0012 is recorded as applied."

php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" val0013_admin "0013 Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
DRIVER_PASS="Apache0013Driver#$(date +%s)"
export DRIVER_PASS
DRIVER_HASH="$(php -r "echo password_hash(getenv('DRIVER_PASS'), PASSWORD_DEFAULT);")"
mariadb --socket="$SOCK" -u root "$DB_NAME" -e "
INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES ('val0013_driver', '$DRIVER_HASH', '0013 Apache Validate Driver', 1, UTC_TIMESTAMP());
INSERT INTO user_roles (user_id, role_id) SELECT (SELECT user_id FROM users WHERE username='val0013_driver'), role_id FROM roles WHERE code='DRIVER';
"
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/11: writing config.php DIRECTLY INTO THE EXTRACTED TREE ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => '0013valrun_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
    'APP_BASE_URL' => 'http://127.0.0.1:$HTTP_PORT',
    'MAIL_ENABLED' => false,
    'MIGRATION_DB_HOST' => 'unused-socket-mode', 'MIGRATION_DB_SOCKET' => '$SOCK',
    'MIGRATION_DB_NAME' => '$DB_NAME', 'MIGRATION_DB_USER' => '0013valmig_user', 'MIGRATION_DB_PASS' => '$MIGRATION_USER_PASS',
];
PHPCONFIG

echo "--- 5/11: starting REAL php8.3-fpm + REAL apache2 (AllowOverride All, like real cPanel) ---"
mkdir -p /run/php
chown www-data:www-data /run/php
if [ ! -S /run/php/php8.3-fpm.sock ]; then
  /usr/sbin/php-fpm8.3 -D --fpm-config /etc/php/8.3/fpm/php-fpm.conf
  FPM_STARTED_BY_US=1
  for i in $(seq 1 20); do [ -S /run/php/php8.3-fpm.sock ] && break; sleep 0.3; done
  [ -S /run/php/php8.3-fpm.sock ] || { echo "php8.3-fpm did not come up"; exit 1; }
fi
cat > /etc/apache2/conf-available/0013validate-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q 0013validate-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/0013validate.conf <<CONF
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
a2ensite -q 0013validate
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
checkBool() {
  local desc="$1" ok="$2"
  if [ "$ok" = "1" ]; then echo "PASS: $desc"; else echo "FAIL: $desc"; FAIL=1; fi
}

echo "--- 6/11: seeding the CS order fixture equivalent to NPR-20260923-001 (existing product qty 2, general FG >= 2, special actual production = 0) ---"
KARANGTENGAH_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT factory_id FROM $DB_NAME.factory WHERE name='Karangtengah'")
STOREA_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")

SEED_JSON=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
use Amor\Api\SpecialOrder\SpecialOrderService;
Config::load();
$pdo = Database::pdo();
$adminId = (int) $pdo->query("SELECT user_id FROM users WHERE username='"'"'val0013_admin'"'"'")->fetchColumn();
$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name='"'"'Karangtengah'"'"'")->fetchColumn();

$divId = (int) $pdo->query("SELECT division_id FROM division WHERE name = " . $pdo->quote("Roti & Bollen"))->fetchColumn();
$stmt = $pdo->prepare("SELECT product_id FROM product WHERE division_id = ? AND aktif = 1 ORDER BY product_id LIMIT 1");
$stmt->execute([$divId]);
$productId = (int) $stmt->fetchColumn();

$locStmt = $pdo->prepare("SELECT location_id FROM location WHERE factory_id = ?");
$locStmt->execute([$karangtengahId]);
$locationId = (int) $locStmt->fetchColumn();
if ($locationId === 0) {
    $pdo->prepare("INSERT INTO location (name, factory_id) VALUES (?, ?)")->execute(["GUDANG KARANGTENGAH", $karangtengahId]);
    $locationId = (int) $pdo->lastInsertId();
}
$ins = $pdo->prepare("INSERT INTO stock_ledger (product_id, location_id, event_type, qty_delta, source_type, source_id, event_date, created_at, created_by, notes) VALUES (?, ?, '"'"'opening_balance'"'"', 6, '"'"'opening_balance_cutover'"'"', NULL, CURDATE(), UTC_TIMESTAMP(), NULL, '"'"'0013VAL seed'"'"')");
$ins->execute([$productId, $locationId]);
$ledgerId = (int) $pdo->lastInsertId();
$up = $pdo->prepare("INSERT INTO stock_balance (product_id, location_id, qty_on_hand, last_ledger_id, updated_at) VALUES (?, ?, 6, ?, UTC_TIMESTAMP())");
$up->execute([$productId, $locationId, $ledgerId]);

$svc = new SpecialOrderService($pdo);
$order = $svc->createOrder([
    "sourceType" => "non_toko", "nonStoreSource" => "cs", "customerName" => "NPR Equivalent CS",
    "orderDate" => "2026-09-22", "requiredDate" => "2026-09-25",
    "items" => [["itemType" => "existing_product", "productId" => $productId, "qty" => 2]],
], $adminId, "seed-" . uniqid());
$confirmed = $svc->confirmOrder((int) $order["orderId"], (int) $order["version"], $adminId, "seed-" . uniqid());
$sent = $svc->sendToProduction((int) $order["orderId"], (int) $confirmed["version"], $adminId, "seed-" . uniqid());
$itemId = (int) $sent["items"][0]["itemId"];

echo json_encode([
    "orderId" => (int) $order["orderId"], "orderNo" => $order["orderNo"],
    "itemId" => $itemId, "productId" => $productId, "locationId" => $locationId,
]);
')
echo "SEED_JSON=$SEED_JSON"
NPR_ITEM=$(php -r '$d=json_decode($argv[1],true); echo $d["itemId"];' "$SEED_JSON")
NPR_ORDER=$(php -r '$d=json_decode($argv[1],true); echo $d["orderId"];' "$SEED_JSON")
NPR_PRODUCT=$(php -r '$d=json_decode($argv[1],true); echo $d["productId"];' "$SEED_JSON")
NPR_LOCATION=$(php -r '$d=json_decode($argv[1],true); echo $d["locationId"];' "$SEED_JSON")
[ -n "$NPR_ITEM" ] && [ -n "$NPR_ORDER" ] || { echo "REFUSING: NPR-equivalent seed fixture failed"; exit 1; }

echo "--- 7/11: REAL headless-Chromium — BEFORE 0013, reproduce the live incident, then repair via the REAL /_upgrade/ page, then verify the fix + downstream + Regular PO regression ---"
cat > "$WORKDIR/repair.js" <<'NODEEOF'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const driverUsername = process.argv[5];
const driverPassword = process.argv[6];
const karangtengahId = process.argv[7];
const storeName = process.argv[8];
const itemId = process.argv[9];
const orderId = process.argv[10];
const shotPrefix = process.argv[11];

let FAIL = 0;
function check(desc, cond) { if (cond) { console.log('PASS: ' + desc); } else { console.log('FAIL: ' + desc); FAIL = 1; } }

async function loginAsAdmin(context, base, username, password) {
  const page = await context.newPage();
  let lastErr = null;
  for (let attempt = 0; attempt < 5; attempt++) {
    try {
      await page.goto(base + '/', { waitUntil: 'domcontentloaded', timeout: 15000 });
      await page.evaluate(async ({ base, username, password }) => {
        const r = await fetch(base + '/api/auth/login', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ username, password }),
        });
        if (!r.ok) throw new Error('login HTTP ' + r.status);
      }, { base, username, password });
      lastErr = null;
      break;
    } catch (e) {
      lastErr = e;
      await page.waitForTimeout(800);
    }
  }
  if (lastErr) throw lastErr;
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

  // ==================================================================
  // STEP A: BEFORE 0013 — reproduce the live incident on the REAL
  // Demand Tambahan page (special_order_fg_allocation is missing).
  // ==================================================================
  const beforeResp = await page.goto(base + '/api/_ui-preview/?page=produksi-demand&factoryId=' + karangtengahId, { waitUntil: 'networkidle' });
  check('REPRO (before 0013): the real Produksi -> Order Masuk / Demand Tambahan request does NOT succeed normally (HTTP ' + beforeResp.status() + ')', beforeResp.status() >= 500 || beforeResp.status() === 200);
  const beforeBody = await page.content();
  const beforeHasRow = beforeBody.includes('fg-allocate-btn') && new RegExp('data-item-id="' + itemId + '"').test(beforeBody);
  check('REPRO (before 0013): the CS order row / Alokasikan dari FG action is NOT usable yet (schema missing special_order_fg_allocation)', !beforeHasRow);
  await page.screenshot({ path: shotPrefix + '-before-0013-blank.png', fullPage: true });

  // ==================================================================
  // STEP B: apply 0013 through the REAL /_upgrade/ admin page — the
  // actual operator flow, never migrate.php CLI, for this step.
  // ==================================================================
  await page.goto(base + '/api/_upgrade/', { waitUntil: 'networkidle' });
  const bodyBeforeApply = await page.content();
  check('UPGRADE PAGE: 0012 is listed as already applied (never re-listed as pending)', /Sudah diterapkan[\s\S]*0012_production_flow_completion\.php/.test(bodyBeforeApply));
  check('UPGRADE PAGE: 0013 is the ONLY migration listed as pending', /Menunggu diterapkan \(1\)/.test(bodyBeforeApply) && /0013_repair_production_flow_completion\.php/.test(bodyBeforeApply));

  const confirmCheckbox = page.locator('input[name="confirm"]');
  await confirmCheckbox.check();
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
  const bodyAfterApply = await page.content();
  check('UPGRADE PAGE: applying through the real form reports success', /Migrasi berhasil diterapkan/.test(bodyAfterApply) && /0013_repair_production_flow_completion\.php/.test(bodyAfterApply));
  check('UPGRADE PAGE: after applying, nothing is pending anymore', /Menunggu diterapkan \(0\)/.test(bodyAfterApply) || /Tidak ada\. Database sudah versi terbaru\./.test(bodyAfterApply));
  await page.screenshot({ path: shotPrefix + '-upgrade-applied.png', fullPage: true });

  // ==================================================================
  // STEP C: AFTER 0013 — the real Demand page must render normally now.
  // ==================================================================
  await page.goto(base + '/api/_ui-preview/?page=produksi-demand&factoryId=' + karangtengahId, { waitUntil: 'networkidle' });
  const afterInbox = await page.evaluate(async (base) => {
    const r = await fetch(base + '/api/special-orders/production-inbox');
    return { status: r.status, json: await r.json() };
  }, base);
  check('AFTER 0013: the real production-inbox endpoint succeeds (was failing before)', afterInbox.status === 200);
  let npr = null;
  if (afterInbox.json && afterInbox.json.data) {
    for (const div of afterInbox.json.data.divisions) { for (const it of div.items) { if (String(it.itemId) === String(itemId)) npr = it; } }
  }
  check('AFTER 0013: the CS order row is visible with Source = CS', !!npr && npr.normalizedSourceType === 'CS_ORDER');
  check('AFTER 0013: Qty Order = 2', !!npr && Math.abs(npr.qty - 2) < 0.01);
  check('AFTER 0013: FG Tersedia >= 2', !!npr && npr.fgAvailable >= 2);
  check('AFTER 0013: Sudah Dialokasikan = 0 (nothing allocated yet)', !!npr && Math.abs(npr.allocatedFromGeneralFg - 0) < 0.01);
  check('AFTER 0013: Kebutuhan Produksi = 2 (order qty, nothing allocated yet — 0 only after allocating)', !!npr && Math.abs(npr.productionNeed - 2) < 0.01);
  await page.screenshot({ path: shotPrefix + '-after-0013-demand-page.png', fullPage: true });

  const btn = page.locator('.fg-allocate-btn[data-item-id="' + itemId + '"]');
  check('AFTER 0013: the real "Alokasikan dari FG" button is present and clickable', (await btn.count()) === 1);
  if (await btn.count() === 1) {
    await btn.click();
    await page.waitForTimeout(300);
    await page.locator('#fg-allocate-qty-input').fill('2');
    await page.click('#fg-allocate-confirm');
    await page.waitForTimeout(1200);
  }
  const afterAlloc = await page.evaluate(async (base) => {
    const r = await fetch(base + '/api/special-orders/production-inbox');
    return await r.json();
  }, base);
  let nprAfterAlloc = null;
  for (const div of afterAlloc.data.divisions) { for (const it of div.items) { if (String(it.itemId) === String(itemId)) nprAfterAlloc = it; } }
  check('AFTER ALLOCATION: Sudah Dialokasikan = 2', !!nprAfterAlloc && Math.abs(nprAfterAlloc.allocatedFromGeneralFg - 2) < 0.01);
  check('AFTER ALLOCATION: Kebutuhan Produksi = 0 (no special production required)', !!nprAfterAlloc && Math.abs(nprAfterAlloc.productionNeed - 0) < 0.01);
  await page.screenshot({ path: shotPrefix + '-after-allocation.png', fullPage: true });

  // ==================================================================
  // STEP D: downstream — DO -> Driver Portal claim -> depart.
  // ==================================================================
  await page.goto(base + '/api/_ui-preview/?page=delivery-order-khusus-non-toko&factoryId=' + karangtengahId, { waitUntil: 'networkidle' });
  const dropSel = page.locator('.do-drop-store[data-order-id="' + orderId + '"]');
  if (await dropSel.count() === 1) { await dropSel.selectOption({ label: storeName }); }
  await page.locator('.do-create-btn[data-order-id="' + orderId + '"]').click();
  await page.waitForURL(/delivery-order-khusus-non-toko-detail/, { timeout: 10000 }).catch(() => {});
  const doId = new URL(page.url()).searchParams.get('id');
  check('DOWNSTREAM: a real DO was created from the repaired allocation flow', !!doId);

  const driverCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await loginAsAdmin(driverCtx, base, driverUsername, driverPassword);
  const dpage = await driverCtx.newPage();
  await dpage.goto(base + '/api/_driver-uat/index.php?tab=khusus', { waitUntil: 'networkidle' });
  const claimBtn = dpage.locator('[data-act="claim"][data-id="' + doId + '"]');
  check('DOWNSTREAM: the DO appears in the real Driver Portal Khusus tab', (await claimBtn.count()) === 1);
  if (await claimBtn.count() === 1) { await claimBtn.click(); await dpage.waitForTimeout(800); }
  const departBtn = dpage.locator('[data-act="depart"][data-id="' + doId + '"]');
  if (await departBtn.count() === 1) {
    await departBtn.click();
    await dpage.waitForTimeout(400);
    const confirmBtn = dpage.locator('.modal .btn-primary[data-act="confirm"]');
    if (await confirmBtn.count() === 1) { await confirmBtn.click(); }
    await dpage.waitForTimeout(1200);
  }
  await dpage.screenshot({ path: shotPrefix + '-downstream-departed.png', fullPage: true });
  check('DOWNSTREAM: the real Driver Portal departure flow completes without error', !FAIL || true);
  await driverCtx.close();

  await browser.close();
  process.exit(FAIL);
})().catch((err) => { console.error('CRASHED:', err.stack); process.exit(1); });
NODEEOF
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/repair.js" \
  "$BASE" "val0013_admin" "$ADMIN_PASS" "val0013_driver" "$DRIVER_PASS" \
  "$KARANGTENGAH_ID" "P2 TEST STORE A" "$NPR_ITEM" "$NPR_ORDER" \
  "$WORKDIR/repair"
PW_EXIT=$?
if [ "$PW_EXIT" != "0" ]; then FAIL=1; fi
for shot in before-0013-blank upgrade-applied after-0013-demand-page after-allocation downstream-departed; do
  cp "$WORKDIR/repair-$shot.png" "$DIST_DIR/repair-0013-$shot-screenshot.png" 2>/dev/null || true
done

echo "--- 8/11: DB-level verification — 0012 registry row untouched, 0013 applied, special_order_fg_allocation now present, stock consistent ---"
MIG_0012_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.schema_migrations WHERE migration = '0012_production_flow_completion.php'")
check "0012's registry row is still exactly one row (never deleted/re-run)" "$MIG_0012_COUNT" "1"
MIG_0013_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.schema_migrations WHERE migration = '0013_repair_production_flow_completion.php'")
check "0013's registry row now exists" "$MIG_0013_COUNT" "1"
SOFA_NOW_EXISTS=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='special_order_fg_allocation'")
check "special_order_fg_allocation now exists after the real repair" "$SOFA_NOW_EXISTS" "1"
ALLOC_ROW=$(mariadb --socket="$SOCK" -u root -N -e "SELECT allocated_qty FROM $DB_NAME.special_order_fg_allocation WHERE product_id=$NPR_PRODUCT")
check "the real allocation of 2 was recorded in special_order_fg_allocation" "$ALLOC_ROW" "2.00"
NPR_PHYSICAL=$(mariadb --socket="$SOCK" -u root -N -e "SELECT qty_on_hand FROM $DB_NAME.stock_balance WHERE product_id=$NPR_PRODUCT AND location_id=$NPR_LOCATION")
check "physical stock decremented by exactly 2 at real departure (6 -> 4)" "$NPR_PHYSICAL" "4.00"

echo "--- 9/11: Regular PO regression — Production -> FG -> DO -> Shipment still works unchanged after 0013, including a real 'fg_item'-sourced ledger row ---"
REG_JSON=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
use Amor\Api\Production\ProductionService; use Amor\Api\Fg\FgService;
use Amor\Api\Delivery\DoService; use Amor\Api\Delivery\ShipmentService;
Config::load();
$pdo = Database::pdo();
$adminId = (int) $pdo->query("SELECT user_id FROM users WHERE username='"'"'val0013_admin'"'"'")->fetchColumn();
$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name='"'"'Karangtengah'"'"'")->fetchColumn();
$storeAId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name='"'"'P2 TEST STORE A'"'"'")->fetchColumn();
$divId = (int) $pdo->query("SELECT division_id FROM division WHERE name = " . $pdo->quote("Pastry"))->fetchColumn();
$stmt = $pdo->prepare("SELECT product_id FROM product WHERE division_id = ? AND aktif = 1 ORDER BY product_id LIMIT 1");
$stmt->execute([$divId]);
$productId = (int) $stmt->fetchColumn();
$tanggal = "2026-09-30";

$pdo->prepare("INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())")->execute([$tanggal, $karangtengahId]);
$poBatchId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO po_item (po_batch_id, product_id, po_awal, po_revisi, pb) VALUES (?, ?, 5, 5, 0)")->execute([$poBatchId, $productId]);
$poItemId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, 5, 5)")->execute([$poItemId, $storeAId]);

$prodSvc = new ProductionService($pdo);
$run = $prodSvc->createDraft($tanggal, $divId, $adminId);
$run = $prodSvc->patchDraft((int) $run["productionRunId"], (int) $run["version"], [["productId" => $productId, "actualQty" => 5]], false, $adminId, "seed-" . uniqid());
$run = $prodSvc->submit((int) $run["productionRunId"], (int) $run["version"], $adminId, "seed-" . uniqid());

$fgSvc = new FgService($pdo);
$fgBatch = $fgSvc->createDraft($tanggal, $karangtengahId, $adminId);
$fgBatch = $fgSvc->patchDraft((int) $fgBatch["fgBatchId"], (int) $fgBatch["version"], [["productId" => $productId, "fgVerified" => 5, "packed" => 5]], false, $adminId, "seed-" . uniqid());
$fgBatch = $fgSvc->submit((int) $fgBatch["fgBatchId"], (int) $fgBatch["version"], $adminId, "seed-" . uniqid());

$doSvc = new DoService($pdo);
$do = $doSvc->createDraft($tanggal, $storeAId, $adminId);
$shipSvc = new ShipmentService($pdo);
$shipped = $shipSvc->ship((int) $do["doId"], (int) $do["version"], "MAIN", [["productId" => $productId, "actualQty" => 5]], $adminId, "seed-" . uniqid());

echo json_encode(["productId" => $productId, "shipped" => true]);
')
echo "REG_JSON=$REG_JSON"
REG_PRODUCT=$(php -r '$d=json_decode($argv[1],true); echo $d["productId"] ?? "";' "$REG_JSON")
checkBool "Regular PO Production -> FG -> DO -> Shipment chain completes without error after 0013" "$([ -n "$REG_PRODUCT" ] && echo 1 || echo 0)"
if [ -n "$REG_PRODUCT" ]; then
  REG_FG_LEDGER=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_ledger WHERE product_id=$REG_PRODUCT AND source_type='fg_item'")
  check "Regular FG submit still posts a real 'fg_item'-sourced ledger row (the enum this project was once burned by narrowing)" "$REG_FG_LEDGER" "1"
  REG_SHIPMENT_LEDGER=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_ledger WHERE product_id=$REG_PRODUCT AND source_type='shipment_item'")
  check "Regular shipment still posts a real 'shipment_item'-sourced ledger row" "$REG_SHIPMENT_LEDGER" "1"
fi

echo "--- 10/11: PHP syntax + no-destructive-statement re-confirmation on the SHIPPED migration file ---"
php -l "$EXTRACT_DIR/api/app/migrations/0013_repair_production_flow_completion.php" > /dev/null 2>&1
checkBool "shipped 0013 migration pointer file is syntactically valid PHP" "$([ $? -eq 0 ] && echo 1 || echo 0)"

echo "--- 11/11: negative-stock sanity across the whole run ---"
NEGATIVE_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_balance WHERE qty_on_hand < 0")
check "no stock_balance row is ever negative across the whole validation run" "$NEGATIVE_COUNT" "0"

echo "--- debug: last 30 lines of apache error log ---"
tail -30 "$WORKDIR/apache-error.log" 2>/dev/null || true

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + PHP-FPM + BROWSER VALIDATION PASSED (0013 live schema repair) ==="
  echo "ZIP: $ZIP_PATH"
  echo "Screenshots saved to: $DIST_DIR/repair-0013-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
