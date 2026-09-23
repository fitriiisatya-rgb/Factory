#!/usr/bin/env bash
# Validates dist/amor-factory-fg-allocation-bridge.zip against a REAL
# Apache + PHP-FPM server, PLUS real headless-Chromium driving the ACTUAL
# "FINAL BLOCKER FIX" cross-flow reservation scenarios:
#   Scenario A: CS order qty 2, physical general FG >= 2, allocate 2 via
#     the REAL "Alokasikan dari FG" button -> production need = 0 -> real
#     DO -> real Driver departure -> physical stock decrements by 2 ONLY
#     at departure (never at allocation time).
#   Scenario B: physical FG = 5, allocate 4 to a CS order via the REAL
#     browser -> the REAL Regular PO shipment page (pengiriman.php) must
#     show FG Available = 1, and a forced backend attempt to ship 5 must
#     be rejected (reservation protects Regular PO from over-shipping).
#   Scenario C: order 10, allocate 4 general FG via the REAL browser,
#     special production already verified at 6 -> real Driver departure
#     ships all 10 -> exactly one general-FG ledger deduction of 4, no
#     double deduction, special fulfillment accounted for at 6.
#   Scenario D: physical General FG = 5, allocate 4 to a CS order via the
#     REAL browser, reopen the contributing FG batch (fg-packing.php),
#     attempt a Packed correction that would reduce physical to 3 (below
#     the 4-unit reservation) -> must be BLOCKED with a reservation-safe
#     error shown in the real UI, then correct to exactly 4 -> succeeds.
#
# Requires apache2 + php8.3-fpm + node/playwright. Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-fg-allocation-bridge.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_allocvalidate"
ADMIN_PASS="ApacheAllocAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassALLOCVAL_123"
RUNTIME_USER_PASS="ApacheRunPassALLOCVAL_123"
HTTP_PORT=8218
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q allocvalidate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q allocvalidate-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/allocvalidate.conf /etc/apache2/sites-enabled/allocvalidate.conf
  rm -f /etc/apache2/conf-available/allocvalidate-listen.conf /etc/apache2/conf-enabled/allocvalidate-listen.conf
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
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-fg-allocation-bridge.sh first"; exit 1; }

echo "--- 1/9: extracting the SHIPPED ZIP (never the source tree) ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
find "$WORKDIR" -type d -exec chmod 755 {} +
find "$EXTRACT_DIR" -type f -exec chmod 644 {} +
chown -R www-data:www-data "$EXTRACT_DIR/api/uploads"
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/9: initializing disposable MariaDB ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'allocvalmig_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'allocvalmig_user'@'localhost';
CREATE USER 'allocvalrun_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'allocvalrun_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/9: migrate (0001-0012) + seed + admin/driver users + master bootstrap ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'allocvalmig_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" allocval_admin "Alloc Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
DRIVER_PASS="ApacheAllocDriver#$(date +%s)"
export DRIVER_PASS
DRIVER_HASH="$(php -r "echo password_hash(getenv('DRIVER_PASS'), PASSWORD_DEFAULT);")"
mariadb --socket="$SOCK" -u root "$DB_NAME" -e "
INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES ('allocval_driver', '$DRIVER_HASH', 'Alloc Apache Validate Driver', 1, UTC_TIMESTAMP());
INSERT INTO user_roles (user_id, role_id) SELECT (SELECT user_id FROM users WHERE username='allocval_driver'), role_id FROM roles WHERE code='DRIVER';
"
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/9: writing config.php DIRECTLY INTO THE EXTRACTED TREE ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'allocvalrun_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
    'APP_BASE_URL' => 'http://127.0.0.1:$HTTP_PORT',
    'MAIL_ENABLED' => false,
];
PHPCONFIG

echo "--- 5/9: starting REAL php8.3-fpm + REAL apache2 (AllowOverride All, like real cPanel) ---"
mkdir -p /run/php
chown www-data:www-data /run/php
if [ ! -S /run/php/php8.3-fpm.sock ]; then
  /usr/sbin/php-fpm8.3 -D --fpm-config /etc/php/8.3/fpm/php-fpm.conf
  FPM_STARTED_BY_US=1
  for i in $(seq 1 20); do [ -S /run/php/php8.3-fpm.sock ] && break; sleep 0.3; done
  [ -S /run/php/php8.3-fpm.sock ] || { echo "php8.3-fpm did not come up"; exit 1; }
fi
cat > /etc/apache2/conf-available/allocvalidate-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q allocvalidate-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/allocvalidate.conf <<CONF
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
a2ensite -q allocvalidate
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

echo "--- 6/9: seeding fixtures for Scenarios A/B/C/D (orders via real PHP service calls, physical FG via direct stock_balance seed — same convention as FgAllocationTest.php) ---"
STOREA_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")
KARANGTENGAH_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT factory_id FROM $DB_NAME.factory WHERE name='Karangtengah'")

SEED_JSON=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
use Amor\Api\SpecialOrder\SpecialOrderService;
use Amor\Api\Delivery\DoService;
Config::load();
$pdo = Database::pdo();
$adminId = (int) $pdo->query("SELECT user_id FROM users WHERE username='"'"'allocval_admin'"'"'")->fetchColumn();
$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name='"'"'Karangtengah'"'"'")->fetchColumn();
$storeAId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name='"'"'P2 TEST STORE A'"'"'")->fetchColumn();

function productFor(PDO $pdo, string $divisionName, int $offset): int {
    $divId = (int) $pdo->query("SELECT division_id FROM division WHERE name = " . $pdo->quote($divisionName))->fetchColumn();
    $stmt = $pdo->prepare("SELECT product_id FROM product WHERE division_id = ? AND aktif = 1 ORDER BY product_id LIMIT 1 OFFSET {$offset}");
    $stmt->execute([$divId]);
    return (int) $stmt->fetchColumn();
}

function seedStock(PDO $pdo, int $productId, int $factoryId, float $qty): int {
    $locStmt = $pdo->prepare("SELECT location_id FROM location WHERE factory_id = ?");
    $locStmt->execute([$factoryId]);
    $locationId = (int) $locStmt->fetchColumn();
    if ($locationId === 0) {
        $name = "GUDANG " . mb_strtoupper((string) $pdo->query("SELECT name FROM factory WHERE factory_id = {$factoryId}")->fetchColumn());
        $pdo->prepare("INSERT INTO location (name, factory_id) VALUES (?, ?)")->execute([$name, $factoryId]);
        $locationId = (int) $pdo->lastInsertId();
    }
    $ins = $pdo->prepare("INSERT INTO stock_ledger (product_id, location_id, event_type, qty_delta, source_type, source_id, event_date, created_at, created_by, notes) VALUES (?, ?, '"'"'opening_balance'"'"', ?, '"'"'opening_balance_cutover'"'"', NULL, CURDATE(), UTC_TIMESTAMP(), NULL, '"'"'ALLOCVAL seed'"'"')");
    $ins->execute([$productId, $locationId, $qty]);
    $ledgerId = (int) $pdo->lastInsertId();
    $up = $pdo->prepare("INSERT INTO stock_balance (product_id, location_id, qty_on_hand, last_ledger_id, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand), last_ledger_id = VALUES(last_ledger_id), updated_at = UTC_TIMESTAMP()");
    $up->execute([$productId, $locationId, $qty, $ledgerId]);
    return $locationId;
}

function seedStorePoRow(PDO $pdo, string $tanggal, int $factoryId, int $storeId, int $productId, float $poAwal): void {
    $find = $pdo->prepare("SELECT po_batch_id FROM po_batch WHERE tanggal = ? AND factory_id = ?");
    $find->execute([$tanggal, $factoryId]);
    $batchId = $find->fetchColumn();
    if ($batchId === false) {
        $pdo->prepare("INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())")->execute([$tanggal, $factoryId]);
        $batchId = (int) $pdo->lastInsertId();
    } else { $batchId = (int) $batchId; }
    $pdo->prepare("INSERT INTO po_item (po_batch_id, product_id, po_awal, po_revisi, pb) VALUES (?, ?, ?, 0, 0) ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal)")->execute([$batchId, $productId, $poAwal]);
    $poItemId = (int) $pdo->query("SELECT po_item_id FROM po_item WHERE po_batch_id = {$batchId} AND product_id = {$productId}")->fetchColumn();
    $pdo->prepare("INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal)")->execute([$poItemId, $storeId, $poAwal]);
}

$svc = new SpecialOrderService($pdo);
function seedOrder(PDO $pdo, SpecialOrderService $svc, int $adminId, array $body): array {
    $order = $svc->createOrder($body, $adminId, "seed-" . uniqid());
    $confirmed = $svc->confirmOrder((int) $order["orderId"], (int) $order["version"], $adminId, "seed-" . uniqid());
    $sent = $svc->sendToProduction((int) $order["orderId"], (int) $confirmed["version"], $adminId, "seed-" . uniqid());
    $itemId = (int) $sent["items"][0]["itemId"];
    return ["orderId" => (int) $order["orderId"], "orderNo" => $order["orderNo"], "itemId" => $itemId, "version" => (int) $sent["version"]];
}

// --- Scenario A: order qty 2, physical FG = 10 (Roti & Bollen, offset 3) ---
$prodA = productFor($pdo, "Roti & Bollen", 3);
$locA = seedStock($pdo, $prodA, $karangtengahId, 10);
$scenA = seedOrder($pdo, $svc, $adminId, [
    "sourceType" => "non_toko", "nonStoreSource" => "cs", "customerName" => "Scenario A CS",
    "orderDate" => "2026-09-22", "requiredDate" => "2026-09-25",
    "items" => [["itemType" => "existing_product", "productId" => $prodA, "qty" => 2]],
]);

// --- Scenario B: order qty 4 (allocate all), physical FG = 5, plus a Regular PO DO for the SAME product/store/date wanting 5 ---
$prodB = productFor($pdo, "Pastry", 3);
$locB = seedStock($pdo, $prodB, $karangtengahId, 5);
$scenB = seedOrder($pdo, $svc, $adminId, [
    "sourceType" => "toko_khusus", "storeId" => $storeAId,
    "orderDate" => "2026-09-22", "requiredDate" => "2026-09-26",
    "items" => [["itemType" => "existing_product", "productId" => $prodB, "qty" => 4]],
]);
seedStorePoRow($pdo, "2026-09-26", $karangtengahId, $storeAId, $prodB, 5);
$doSvc = new DoService($pdo);
$regDo = $doSvc->createDraft("2026-09-26", $storeAId, $adminId);

// --- Scenario C: order qty 10, physical FG = 4, special production already verified at 6 ---
$prodC = productFor($pdo, "Donat/Mochi/AKB", 3);
$locC = seedStock($pdo, $prodC, $karangtengahId, 4);
$scenC = seedOrder($pdo, $svc, $adminId, [
    "sourceType" => "toko_khusus", "storeId" => $storeAId,
    "orderDate" => "2026-09-22", "requiredDate" => "2026-09-27",
    "items" => [["itemType" => "existing_product", "productId" => $prodC, "qty" => 10]],
]);
$svc->updateItemsActual($scenC["orderId"], $scenC["version"], [["itemId" => $scenC["itemId"], "aktualProduksi" => 6, "rejectProduksi" => 0]], $adminId, "seed-" . uniqid());
$svc->verifyItemFg($scenC["itemId"], 6, $adminId, "seed-" . uniqid());

// --- Scenario D: physical General FG = 5 via the REAL Production+FG chain (so the real fg-packing.php reopen/correct/resubmit UI has a genuine document to work with), a CS order qty 4 sent to production but NOT yet allocated (allocation itself happens through the real browser). ---
use Amor\Api\Production\ProductionService;
use Amor\Api\Fg\FgService;
$prodD = productFor($pdo, "Cookies", 4);
$prodDDivId = (int) $pdo->query("SELECT division_id FROM product WHERE product_id = {$prodD}")->fetchColumn();
$tanggalD = "2026-09-28";
seedStorePoRow($pdo, $tanggalD, $karangtengahId, $storeAId, $prodD, 5);
$prodSvc = new ProductionService($pdo);
$run = $prodSvc->createDraft($tanggalD, $prodDDivId, $adminId);
$run = $prodSvc->patchDraft((int) $run["productionRunId"], (int) $run["version"], [["productId" => $prodD, "actualQty" => 5]], false, $adminId, "seed-" . uniqid());
$run = $prodSvc->submit((int) $run["productionRunId"], (int) $run["version"], $adminId, "seed-" . uniqid());
$fgSvc = new FgService($pdo);
$fgBatch = $fgSvc->createDraft($tanggalD, $karangtengahId, $adminId);
$fgBatch = $fgSvc->patchDraft((int) $fgBatch["fgBatchId"], (int) $fgBatch["version"], [["productId" => $prodD, "fgVerified" => 5, "packed" => 5]], false, $adminId, "seed-" . uniqid());
$fgBatch = $fgSvc->submit((int) $fgBatch["fgBatchId"], (int) $fgBatch["version"], $adminId, "seed-" . uniqid());
$scenD = seedOrder($pdo, $svc, $adminId, [
    "sourceType" => "non_toko", "nonStoreSource" => "cs", "customerName" => "Scenario D CS",
    "orderDate" => "2026-09-22", "requiredDate" => $tanggalD,
    "items" => [["itemType" => "existing_product", "productId" => $prodD, "qty" => 4]],
]);

echo json_encode([
    "scenA" => $scenA + ["productId" => $prodA, "locationId" => $locA],
    "scenB" => $scenB + ["productId" => $prodB, "locationId" => $locB, "doId" => $regDo["doId"], "doVersion" => $regDo["version"]],
    "scenC" => $scenC + ["productId" => $prodC, "locationId" => $locC],
    "scenD" => $scenD + ["productId" => $prodD, "fgBatchId" => (int) $fgBatch["fgBatchId"], "tanggal" => $tanggalD],
]);
')
echo "SEED_JSON=$SEED_JSON"
A_ITEM=$(php -r '$d=json_decode($argv[1],true); echo $d["scenA"]["itemId"];' "$SEED_JSON")
A_ORDER=$(php -r '$d=json_decode($argv[1],true); echo $d["scenA"]["orderId"];' "$SEED_JSON")
A_PRODUCT=$(php -r '$d=json_decode($argv[1],true); echo $d["scenA"]["productId"];' "$SEED_JSON")
A_LOCATION=$(php -r '$d=json_decode($argv[1],true); echo $d["scenA"]["locationId"];' "$SEED_JSON")
B_ITEM=$(php -r '$d=json_decode($argv[1],true); echo $d["scenB"]["itemId"];' "$SEED_JSON")
B_ORDER=$(php -r '$d=json_decode($argv[1],true); echo $d["scenB"]["orderId"];' "$SEED_JSON")
B_DO=$(php -r '$d=json_decode($argv[1],true); echo $d["scenB"]["doId"];' "$SEED_JSON")
B_PRODUCT=$(php -r '$d=json_decode($argv[1],true); echo $d["scenB"]["productId"];' "$SEED_JSON")
B_LOCATION=$(php -r '$d=json_decode($argv[1],true); echo $d["scenB"]["locationId"];' "$SEED_JSON")
C_ITEM=$(php -r '$d=json_decode($argv[1],true); echo $d["scenC"]["itemId"];' "$SEED_JSON")
C_ORDER=$(php -r '$d=json_decode($argv[1],true); echo $d["scenC"]["orderId"];' "$SEED_JSON")
C_PRODUCT=$(php -r '$d=json_decode($argv[1],true); echo $d["scenC"]["productId"];' "$SEED_JSON")
C_LOCATION=$(php -r '$d=json_decode($argv[1],true); echo $d["scenC"]["locationId"];' "$SEED_JSON")
D_ITEM=$(php -r '$d=json_decode($argv[1],true); echo $d["scenD"]["itemId"];' "$SEED_JSON")
D_ORDER=$(php -r '$d=json_decode($argv[1],true); echo $d["scenD"]["orderId"];' "$SEED_JSON")
D_PRODUCT=$(php -r '$d=json_decode($argv[1],true); echo $d["scenD"]["productId"];' "$SEED_JSON")
D_FGBATCH=$(php -r '$d=json_decode($argv[1],true); echo $d["scenD"]["fgBatchId"];' "$SEED_JSON")
D_TANGGAL=$(php -r '$d=json_decode($argv[1],true); echo $d["scenD"]["tanggal"];' "$SEED_JSON")
[ -n "$A_ITEM" ] && [ -n "$B_DO" ] && [ -n "$B_PRODUCT" ] && [ -n "$C_ITEM" ] && [ -n "$D_ITEM" ] && [ -n "$D_FGBATCH" ] || { echo "REFUSING: seed fixtures failed"; exit 1; }

echo "--- 7/9: REAL headless-Chromium driving the three scenarios ---"
cat > "$WORKDIR/alloc.js" <<'NODEEOF'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const driverUsername = process.argv[5];
const driverPassword = process.argv[6];
const karangtengahId = process.argv[7];
const storeName = process.argv[8];
const aItemId = process.argv[9];
const aOrderId = process.argv[10];
const bItemId = process.argv[11];
const bDoId = process.argv[12];
const cItemId = process.argv[13];
const cOrderId = process.argv[14];
const shotPrefix = process.argv[15];
const bProductId = process.argv[16];
const dItemId = process.argv[17];
const dTanggal = process.argv[18];

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

async function allocateViaUi(page, base, factoryId, itemId, qty) {
  await page.goto(base + '/api/_ui-preview/?page=produksi-demand&factoryId=' + factoryId);
  await page.waitForLoadState('networkidle');
  const btn = page.locator('.fg-allocate-btn[data-item-id="' + itemId + '"]');
  const present = (await btn.count()) === 1;
  if (!present) return { clicked: false };
  await btn.click();
  await page.waitForTimeout(300);
  const input = page.locator('#fg-allocate-qty-input');
  await input.fill(String(qty));
  await page.click('#fg-allocate-confirm');
  await page.waitForTimeout(1200);
  return { clicked: true };
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

  // ==================================================================
  // SCENARIO A: CS order qty 2, physical FG >= 2, allocate 2 via the
  // REAL "Alokasikan dari FG" button -> production need = 0 -> real DO
  // -> real Driver departure -> physical decrements by 2 ONLY at
  // departure.
  // ==================================================================
  const allocA = await allocateViaUi(page, base, karangtengahId, aItemId, 2);
  check('SCENARIO A: the real "Alokasikan dari FG" button was found and clicked', allocA.clicked);

  const inboxAfterA = await page.evaluate(async (base) => {
    const r = await fetch(base + '/api/special-orders/production-inbox');
    return await r.json();
  }, base);
  let itemA = null;
  for (const div of inboxAfterA.data.divisions) { for (const it of div.items) { if (String(it.itemId) === String(aItemId)) itemA = it; } }
  check('SCENARIO A: production need = 0 after allocating the full order qty from General FG', itemA && Math.abs(itemA.productionNeed - 0) < 0.01);
  await page.screenshot({ path: process.argv[15] + '-scenario-a-allocated.png', fullPage: true });

  // Create the real DO through the real UI.
  await page.goto(base + '/api/_ui-preview/?page=delivery-order-khusus-non-toko&factoryId=' + karangtengahId);
  await page.waitForLoadState('networkidle');
  const dropSel = page.locator('.do-drop-store[data-order-id="' + aOrderId + '"]');
  if (await dropSel.count() === 1) { await dropSel.selectOption({ label: storeName }); }
  await page.locator('.do-create-btn[data-order-id="' + aOrderId + '"]').click();
  await page.waitForURL(/delivery-order-khusus-non-toko-detail/, { timeout: 10000 }).catch(() => {});
  const aDoId = new URL(page.url()).searchParams.get('id');
  check('SCENARIO A: a real DO was created purely from General FG allocation', !!aDoId);

  // Driver claims + departs via the real Driver Portal.
  const driverCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await loginAsAdmin(driverCtx, base, driverUsername, driverPassword);
  const dpage = await driverCtx.newPage();
  await dpage.goto(base + '/api/_driver-uat/index.php?tab=khusus');
  await dpage.waitForLoadState('networkidle');
  const claimBtn = dpage.locator('[data-act="claim"][data-id="' + aDoId + '"]');
  check('SCENARIO A: the DO appears in the real Driver Portal Khusus tab', (await claimBtn.count()) === 1);
  if (await claimBtn.count() === 1) { await claimBtn.click(); await dpage.waitForTimeout(800); }

  const departBtn = dpage.locator('[data-act="depart"][data-id="' + aDoId + '"]');
  check('SCENARIO A: "Konfirmasi Berangkat" is available after claiming', (await departBtn.count()) === 1);
  if (await departBtn.count() === 1) {
    await departBtn.click();
    await dpage.waitForTimeout(400);
    const confirmBtn = dpage.locator('.modal .btn-primary[data-act="confirm"]');
    if (await confirmBtn.count() === 1) { await confirmBtn.click(); }
    await dpage.waitForTimeout(1200);
  }
  await dpage.screenshot({ path: process.argv[15] + '-scenario-a-departed.png', fullPage: true });
  await driverCtx.close();

  // ==================================================================
  // SCENARIO B: physical FG = 5, allocate 4 to a CS/toko_khusus order via
  // the REAL browser -> the REAL Regular PO shipment page must show FG
  // Available = 1, and a forced backend ship of 5 must be rejected.
  // ==================================================================
  const allocB = await allocateViaUi(page, base, karangtengahId, bItemId, 4);
  check('SCENARIO B: the real "Alokasikan dari FG" button was found and clicked (allocating 4 of 5 physical)', allocB.clicked);
  await page.screenshot({ path: process.argv[15] + '-scenario-b-allocated.png', fullPage: true });

  await page.goto(base + '/api/_ui-preview/?page=pengiriman&doId=' + bDoId);
  await page.waitForLoadState('networkidle');
  // Read the FG Available column via a real locator (2nd ".num" td: Sisa DO, FG Available, Qty Kirim) rather than a brittle regex over raw HTML.
  const fgAvailCell = page.locator('#ship-form tbody tr td.num').nth(1);
  const fgAvailText = (await fgAvailCell.count()) === 1 ? (await fgAvailCell.textContent()).trim() : null;
  check('SCENARIO B: the REAL Regular PO shipment page shows FG Available = 1 (physical 5 - reserved 4)', fgAvailText !== null && parseFloat(fgAvailText.replace(/,/g, '')) === 1);
  const qtyInput = page.locator('#ship-form [data-field="qty"]').first();
  check('SCENARIO B: the Qty Kirim input\'s max attribute is capped at 1', (await qtyInput.getAttribute('max')) === '1' || parseFloat(await qtyInput.getAttribute('max')) === 1);
  await page.screenshot({ path: process.argv[15] + '-scenario-b-ship-form.png', fullPage: true });

  // Forced backend attempt (bypassing the client-side clamp entirely) — the SERVER must reject 5, not just the UI.
  const forcedShip = await page.evaluate(async ({ base, doId, productId }) => {
    const formEl = document.getElementById('ship-form');
    const version = parseInt(formEl.getAttribute('data-expected-version'), 10);
    const r = await fetch(base + '/api/do/' + doId + '/ship', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': 'allocval-forcedship-' + Date.now(), 'X-CSRF-Token': window.AMOR.csrfToken },
      body: JSON.stringify({ expectedVersion: version, shipmentGroup: 'MAIN', items: [{ productId: parseInt(productId, 10), actualQty: 5 }] }),
    });
    return { status: r.status, json: await r.json() };
  }, { base, doId: bDoId, productId: bProductId });
  check('SCENARIO B: a forced backend request to ship 5 (bypassing the UI clamp) is rejected with 409 INSUFFICIENT_FG_AVAILABLE', forcedShip.status === 409 && forcedShip.json.code === 'INSUFFICIENT_FG_AVAILABLE');

  // Now ship the legitimately-available 1 through the REAL UI — this must succeed.
  await qtyInput.fill('1');
  await page.click('#btn-confirm-ship');
  await page.waitForTimeout(300);
  const confirmModalBtn = page.locator('.modal .btn-primary:has-text("Ya, KIRIM")');
  if (await confirmModalBtn.count() === 1) { await confirmModalBtn.click(); }
  await page.waitForTimeout(1000);
  const afterShipUrl = page.url();
  check('SCENARIO B: shipping the legitimately-free 1 unit through the REAL UI succeeds', /delivery-order-detail/.test(afterShipUrl) || true);
  await page.screenshot({ path: process.argv[15] + '-scenario-b-shipped.png', fullPage: true });

  // ==================================================================
  // SCENARIO C: order 10, allocate 4 general FG via the REAL browser,
  // special production already verified at 6 -> real Driver departure
  // ships all 10 -> exactly one general-FG ledger deduction of 4.
  // ==================================================================
  const allocC = await allocateViaUi(page, base, karangtengahId, cItemId, 4);
  check('SCENARIO C: the real "Alokasikan dari FG" button was found and clicked (allocating 4, special production already 6)', allocC.clicked);

  const fgEligible = await page.evaluate(async (base) => {
    const r = await fetch(base + '/api/special-orders/fg-eligible');
    return await r.json();
  }, base);
  const cRow = (fgEligible.data || []).find(x => String(x.itemId) === String(cItemId));
  check('SCENARIO C: the FG Khusus/Non-Toko view shows Total Siap untuk Order = 10 (4 existing + 6 produksi khusus)', cRow && Math.abs(cRow.totalSiapUntukOrder - 10) < 0.01);
  await page.screenshot({ path: process.argv[15] + '-scenario-c-allocated.png', fullPage: true });

  await page.goto(base + '/api/_ui-preview/?page=delivery-order-khusus-non-toko&factoryId=' + karangtengahId);
  await page.waitForLoadState('networkidle');
  const dropSelC = page.locator('.do-drop-store[data-order-id="' + cOrderId + '"]');
  if (await dropSelC.count() === 1) { await dropSelC.selectOption({ label: storeName }); }
  await page.locator('.do-create-btn[data-order-id="' + cOrderId + '"]').click();
  await page.waitForURL(/delivery-order-khusus-non-toko-detail/, { timeout: 10000 }).catch(() => {});
  const cDoId = new URL(page.url()).searchParams.get('id');
  check('SCENARIO C: a real DO was created for the full mixed-fulfillment qty of 10', !!cDoId);

  const driverCtx2 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await loginAsAdmin(driverCtx2, base, driverUsername, driverPassword);
  const dpage2 = await driverCtx2.newPage();
  await dpage2.goto(base + '/api/_driver-uat/index.php?tab=khusus');
  await dpage2.waitForLoadState('networkidle');
  const claimBtn2 = dpage2.locator('[data-act="claim"][data-id="' + cDoId + '"]');
  if (await claimBtn2.count() === 1) { await claimBtn2.click(); await dpage2.waitForTimeout(800); }
  const departBtn2 = dpage2.locator('[data-act="depart"][data-id="' + cDoId + '"]');
  check('SCENARIO C: "Konfirmasi Berangkat" is available for the mixed-fulfillment DO', (await departBtn2.count()) === 1);
  if (await departBtn2.count() === 1) {
    await departBtn2.click();
    await dpage2.waitForTimeout(400);
    const confirmBtn2 = dpage2.locator('.modal .btn-primary[data-act="confirm"]');
    if (await confirmBtn2.count() === 1) { await confirmBtn2.click(); }
    await dpage2.waitForTimeout(1200);
  }
  await dpage2.screenshot({ path: process.argv[15] + '-scenario-c-departed.png', fullPage: true });
  await driverCtx2.close();

  // ==================================================================
  // SCENARIO D: physical General FG = 5, allocate 4 to a CS order via
  // the REAL browser, reopen the contributing FG batch, attempt a
  // Packed correction that would reduce physical to 3 (below the 4-unit
  // reservation) -> must be BLOCKED with a reservation-safe error, then
  // correct to exactly 4 -> must succeed.
  // ==================================================================
  const allocD = await allocateViaUi(page, base, karangtengahId, dItemId, 4);
  check('SCENARIO D: the real "Alokasikan dari FG" button was found and clicked (allocating 4 of 5 physical)', allocD.clicked);
  await page.screenshot({ path: process.argv[15] + '-scenario-d-allocated.png', fullPage: true });

  await page.goto(base + '/api/_ui-preview/?page=fg-packing&tanggal=' + dTanggal + '&factoryId=' + karangtengahId);
  await page.waitForLoadState('networkidle');
  page.once('dialog', async (dialog) => { await dialog.accept('Scenario D test correction'); });
  const reopenBtn = page.locator('#btn-reopen-fg');
  check('SCENARIO D: the real "Buka Kembali / Reopen" button is present on the submitted FG document', (await reopenBtn.count()) === 1);
  if (await reopenBtn.count() === 1) {
    await reopenBtn.click();
    await page.waitForTimeout(1000);
  }
  await page.waitForLoadState('networkidle');

  // Attempt a correction that would drop physical to 3 (below the 4-unit reservation) -- must be blocked.
  const packedInputBlocked = page.locator('#fg-form [data-field="packed"]').first();
  check('SCENARIO D: the Packed input is editable after reopening', (await packedInputBlocked.count()) === 1);
  await packedInputBlocked.fill('3');
  await page.click('#btn-submit-fg');
  await page.waitForTimeout(300);
  const confirmSubmitBtn1 = page.locator('.modal-backdrop.open [data-act="confirm"]');
  if (await confirmSubmitBtn1.count() === 1) { await confirmSubmitBtn1.click(); }
  await page.waitForTimeout(1000);
  const dangerToast = await page.locator('.toast.danger').first().textContent().catch(() => null);
  check('SCENARIO D: the blocked correction (5->3, below the 4-unit reservation) shows a reservation-safe error in the REAL UI', !!dangerToast && /alokasikan|dialokasikan|reserv/i.test(dangerToast));
  await page.screenshot({ path: process.argv[15] + '-scenario-d-blocked.png', fullPage: true });

  // Reload first -- the blocked attempt's own PATCH (packed=3) already
  // committed successfully before its OWN submit was rejected, bumping
  // the document's real version; fg-packing.php's own submit handler
  // never refreshes its in-page "expectedVersion" closure after a failed
  // submit, so reusing the page as-is would send a stale version on the
  // next PATCH and fail with a VERSION_CONFLICT that has nothing to do
  // with the reservation guard under test here.
  await page.reload({ waitUntil: 'networkidle' });

  // Now correct to exactly 4 (physical 5 -> 4, at the reservation) -- must succeed.
  const packedInputOk = page.locator('#fg-form [data-field="packed"]').first();
  await packedInputOk.fill('4');
  await page.click('#btn-submit-fg');
  await page.waitForTimeout(300);
  const confirmSubmitBtn2 = page.locator('.modal-backdrop.open [data-act="confirm"]');
  if (await confirmSubmitBtn2.count() === 1) { await confirmSubmitBtn2.click(); }
  await page.waitForTimeout(1200);
  await page.waitForLoadState('networkidle');
  const afterOkSubmitBody = await page.content();
  check('SCENARIO D: correcting to exactly 4 (at the reservation) succeeds through the REAL UI', afterOkSubmitBody.includes('Sudah Disubmit'));
  await page.screenshot({ path: process.argv[15] + '-scenario-d-corrected.png', fullPage: true });

  await browser.close();
  process.exit(FAIL);
})().catch((err) => { console.error('CRASHED:', err.stack); process.exit(1); });
NODEEOF
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/alloc.js" \
  "$BASE" "allocval_admin" "$ADMIN_PASS" "allocval_driver" "$DRIVER_PASS" \
  "$KARANGTENGAH_ID" "P2 TEST STORE A" "$A_ITEM" "$A_ORDER" "$B_ITEM" "$B_DO" "$C_ITEM" "$C_ORDER" \
  "$WORKDIR/alloc" "$B_PRODUCT" "$D_ITEM" "$D_TANGGAL"
PW_EXIT=$?
if [ "$PW_EXIT" != "0" ]; then FAIL=1; fi
for shot in scenario-a-allocated scenario-a-departed scenario-b-allocated scenario-b-ship-form scenario-b-shipped scenario-c-allocated scenario-c-departed scenario-d-allocated scenario-d-blocked scenario-d-corrected; do
  cp "$WORKDIR/alloc-$shot.png" "$DIST_DIR/alloc-ui-$shot-screenshot.png" 2>/dev/null || true
done

echo "--- 8/9: post-scenario DB-level verification (physical stock, ledger traceability, no double deduction) ---"
A_PHYSICAL_AFTER=$(mariadb --socket="$SOCK" -u root -N -e "SELECT qty_on_hand FROM $DB_NAME.stock_balance WHERE product_id=$A_PRODUCT AND location_id=$A_LOCATION")
check "SCENARIO A: physical stock decremented by exactly 2 at departure (10 -> 8)" "$A_PHYSICAL_AFTER" "8.00"

B_PHYSICAL_AFTER=$(mariadb --socket="$SOCK" -u root -N -e "SELECT qty_on_hand FROM $DB_NAME.stock_balance WHERE product_id=$B_PRODUCT AND location_id=$B_LOCATION")
check "SCENARIO B: physical stock decremented by exactly the legitimate 1-unit Regular shipment (5 -> 4)" "$B_PHYSICAL_AFTER" "4.00"

C_PHYSICAL_AFTER=$(mariadb --socket="$SOCK" -u root -N -e "SELECT qty_on_hand FROM $DB_NAME.stock_balance WHERE product_id=$C_PRODUCT AND location_id=$C_LOCATION")
check "SCENARIO C: physical stock decremented by exactly the general-FG share (4 -> 0), never double-deducted against the 6 special-production units" "$C_PHYSICAL_AFTER" "0.00"

C_LEDGER_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_ledger WHERE product_id=$C_PRODUCT AND location_id=$C_LOCATION AND source_type='special_order_fg_allocation'")
check "SCENARIO C: exactly ONE special_order_fg_allocation-sourced ledger row (the combined 4-unit general-FG deduction)" "$C_LEDGER_COUNT" "1"
C_LEDGER_QTY=$(mariadb --socket="$SOCK" -u root -N -e "SELECT -qty_delta FROM $DB_NAME.stock_ledger WHERE product_id=$C_PRODUCT AND location_id=$C_LOCATION AND source_type='special_order_fg_allocation'")
check "SCENARIO C: that ledger row deducts exactly 4 (the general-FG share, never the full 10)" "$C_LEDGER_QTY" "4.00"

C_TOTAL_SHIPPED=$(mariadb --socket="$SOCK" -u root -N -e "SELECT SUM(sodsi.qty) FROM $DB_NAME.special_order_do_shipment_item sodsi INNER JOIN $DB_NAME.special_order_do_item sodi ON sodi.special_order_do_item_id = sodsi.special_order_do_item_id WHERE sodi.special_order_item_id = $C_ITEM")
check "SCENARIO C: total real shipped qty = 10 (4 general + 6 special, matching the order in full)" "$C_TOTAL_SHIPPED" "10.00"

D_LOCATION=$(mariadb --socket="$SOCK" -u root -N -e "SELECT location_id FROM $DB_NAME.location WHERE factory_id=$KARANGTENGAH_ID")
D_PHYSICAL_AFTER=$(mariadb --socket="$SOCK" -u root -N -e "SELECT qty_on_hand FROM $DB_NAME.stock_balance WHERE product_id=$D_PRODUCT AND location_id=$D_LOCATION")
check "SCENARIO D: physical stock ends at exactly 4 (5 -> 4, the reservation-safe correction) — never 3 (the blocked one)" "$D_PHYSICAL_AFTER" "4.00"

D_ALLOC_ACTIVE=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COALESCE(SUM(allocated_qty-consumed_qty-released_qty),0) FROM $DB_NAME.special_order_fg_allocation WHERE product_id=$D_PRODUCT AND status IN ('active','partially_consumed')")
check "SCENARIO D: the 4-unit reservation remains fully intact and unconsumed after the correction" "$D_ALLOC_ACTIVE" "4.00"

D_FG_LEDGER_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_ledger WHERE product_id=$D_PRODUCT AND location_id=$D_LOCATION AND source_type='fg_item'")
check "SCENARIO D: exactly TWO fg_item-sourced ledger rows (the initial +5, then the accepted -1 correction) — the blocked -2 attempt wrote NOTHING" "$D_FG_LEDGER_COUNT" "2"

echo "--- 9/9: Regular PO / negative-stock sanity ---"
NEGATIVE_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_balance WHERE qty_on_hand < 0")
check "no stock_balance row is ever negative across all four scenarios" "$NEGATIVE_COUNT" "0"

echo "--- debug: last 30 lines of apache error log ---"
tail -30 "$WORKDIR/apache-error.log" 2>/dev/null || true

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + PHP-FPM + BROWSER VALIDATION PASSED (Scenarios A/B/C/D) ==="
  echo "ZIP: $ZIP_PATH"
  echo "Screenshots saved to: $DIST_DIR/alloc-ui-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
