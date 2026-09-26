#!/usr/bin/env bash
# Validates dist/amor-factory-production-live-po-target.zip against a REAL
# Apache + PHP-FPM server, reproducing the ACTUAL real UAT incident:
#   Date: 2026-09-26, Factory: Karangtengah. A Regular PO import succeeds
#   (BOLLEN LILIT COKLAT=25, CHOCO CUBE 12=14, target total=39). BEFORE
#   any Production Draft exists, Produksi -> Ceklis Produksi must show
#   Target Produksi=39 and the Roti & Bollen division row must show real
#   numbers (never "-"). Clicking "Buat/Buka Draft" must then show the
#   IDENTICAL target on the new draft.
#
# Requires apache2 + php8.3-fpm + node/playwright. Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-production-live-po-target.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_livepotarget"
ADMIN_PASS="ApacheLivePoAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheLivePoMigPass_123"
RUNTIME_USER_PASS="ApacheLivePoRunPass_123"
HTTP_PORT=8518
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q livepotarget >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q livepotarget-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/livepotarget.conf /etc/apache2/sites-enabled/livepotarget.conf
  rm -f /etc/apache2/conf-available/livepotarget-listen.conf /etc/apache2/conf-enabled/livepotarget-listen.conf
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
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-production-live-po-target.sh first"; exit 1; }

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
CREATE USER 'livepomig_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'livepomig_user'@'localhost';
CREATE USER 'livepomrun_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'livepomrun_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/9: migrate (0001-0013) + seed + admin user + master bootstrap ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'livepomig_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" livepo_admin "Live PO Target Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/9: writing config.php DIRECTLY INTO THE EXTRACTED TREE ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'livepomrun_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
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
cat > /etc/apache2/conf-available/livepotarget-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q livepotarget-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/livepotarget.conf <<CONF
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
a2ensite -q livepotarget
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

echo "--- 6/9: seeding the REAL UAT PO fixture (BOLLEN LILIT COKLAT=25, CHOCO CUBE 12=14, no production draft) ---"
KARANGTENGAH_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT factory_id FROM $DB_NAME.factory WHERE name='Karangtengah'")
ROTI_DIV_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT division_id FROM $DB_NAME.division WHERE name='Roti & Bollen'")

SEED_JSON=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
Config::load();
$pdo = Database::pdo();
$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name='"'"'Karangtengah'"'"'")->fetchColumn();
$rotiDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name='"'"'Roti & Bollen'"'"'")->fetchColumn();
$stmt = $pdo->prepare("SELECT product_id, name FROM product WHERE division_id = ? AND aktif = 1 ORDER BY product_id LIMIT 2");
$stmt->execute([$rotiDivId]);
$products = $stmt->fetchAll();
$tanggal = "2026-09-26";

$pdo->prepare("INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())")->execute([$tanggal, $karangtengahId]);
$batchId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (?, ?, '"'"'ROTI'"'"', 25, 0, 0)")->execute([$batchId, $products[0]["product_id"]]);
$pdo->prepare("INSERT INTO po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (?, ?, '"'"'ROTI'"'"', 14, 0, 0)")->execute([$batchId, $products[1]["product_id"]]);

$noRun = (int) $pdo->prepare("SELECT COUNT(*) FROM production_run WHERE tanggal = ? AND division_id = ?")->execute([$tanggal, $rotiDivId]) ? 0 : 0;

echo json_encode(["batchId" => $batchId, "tanggal" => $tanggal, "productA" => $products[0]["name"], "productB" => $products[1]["name"]]);
')
echo "SEED_JSON=$SEED_JSON"
NO_RUN_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.production_run WHERE tanggal='2026-09-26' AND division_id=$ROTI_DIV_ID")
[ "$NO_RUN_COUNT" = "0" ] || { echo "REFUSING: test fixture is not representative — a production_run already exists before the UAT repro"; exit 1; }
echo "fixture confirmed representative: no production_run exists yet for Roti & Bollen / 2026-09-26"

echo "--- 7/9: REAL headless-Chromium — the exact 26 Sep 2026 UAT repro ---"
cat > "$WORKDIR/live-po-target.js" <<'NODEEOF'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const karangtengahId = process.argv[5];
const rotiDivId = process.argv[6];
const shotPrefix = process.argv[7];

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

  // BEFORE any draft exists.
  await page.goto(base + '/api/_ui-preview/?page=produksi&tanggal=2026-09-26&factoryId=' + karangtengahId, { waitUntil: 'networkidle' });
  const targetKpi = await page.locator('.kpi-card', { hasText: 'Target Produksi' }).locator('.kpi-value').textContent();
  check('REPRO (before draft): top KPI Target Produksi = 39', targetKpi.trim() === '39');

  const rotiRow = page.locator('table.data-table tbody tr', { hasText: 'Roti & Bollen' });
  check('REPRO (before draft): the Roti & Bollen row exists', (await rotiRow.count()) === 1);
  const cells = await rotiRow.locator('td').allTextContents();
  // [0]=division name, [1]=target, [2]=actual, [3]=sisa, [4]=status, [5]=aksi
  check('REPRO (before draft): Roti & Bollen Target = 39 (never "-")', cells[1].trim() === '39');
  check('REPRO (before draft): Roti & Bollen Actual = 0 (never "-")', cells[2].trim() === '0');
  check('REPRO (before draft): Roti & Bollen Sisa = 39 (never "-")', cells[3].trim() === '39');
  check('REPRO (before draft): status is still "Belum Dimulai"', cells[4].includes('Belum Dimulai'));
  await page.screenshot({ path: shotPrefix + '-before-draft.png', fullPage: true });

  // Click "Buat/Buka Draft" for Roti & Bollen.
  await rotiRow.locator('button[data-action="create-run"]').click();
  await page.waitForURL(/runId=\d+/, { timeout: 10000 });
  await page.waitForLoadState('networkidle');
  const draftTargetText = await page.locator('#run-detail .kpi-value, #run-detail td.num').first().textContent().catch(() => null);
  const runDetailBody = await page.content();
  check('AFTER DRAFT: the draft was created and its own detail loaded', (await page.locator('#run-detail').count()) === 1);
  check('AFTER DRAFT: the draft item shows liveTarget = 25 for the first product (matching the overview before it existed)', /class="num">25<\/td>/.test(runDetailBody));
  await page.screenshot({ path: shotPrefix + '-after-draft.png', fullPage: true });

  // Reload the plain overview (no runId) and confirm Target is IDENTICAL.
  await page.goto(base + '/api/_ui-preview/?page=produksi&tanggal=2026-09-26&factoryId=' + karangtengahId, { waitUntil: 'networkidle' });
  const targetKpiAfter = await page.locator('.kpi-card', { hasText: 'Target Produksi' }).locator('.kpi-value').textContent();
  check('AFTER DRAFT: top KPI Target Produksi is STILL 39 (identical, unaffected by draft creation)', targetKpiAfter.trim() === '39');
  const rotiRowAfter = page.locator('table.data-table tbody tr', { hasText: 'Roti & Bollen' });
  const cellsAfter = await rotiRowAfter.locator('td').allTextContents();
  check('AFTER DRAFT: Roti & Bollen row now shows status "Draft"', cellsAfter[4].includes('Draft'));
  check('AFTER DRAFT: Roti & Bollen Target is still 39', cellsAfter[1].trim() === '39');
  await page.screenshot({ path: shotPrefix + '-overview-after-draft.png', fullPage: true });

  await browser.close();
  process.exit(FAIL);
})().catch((err) => { console.error('CRASHED:', err.stack); process.exit(1); });
NODEEOF
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/live-po-target.js" "$BASE" "livepo_admin" "$ADMIN_PASS" "$KARANGTENGAH_ID" "$ROTI_DIV_ID" "$WORKDIR/shot"
PW_EXIT=$?
if [ "$PW_EXIT" != "0" ]; then FAIL=1; fi
for shot in before-draft after-draft overview-after-draft; do
  cp "$WORKDIR/shot-$shot.png" "$DIST_DIR/livepotarget-$shot-screenshot.png" 2>/dev/null || true
done

echo "--- 8/9: DB-level verification ---"
RUN_COUNT_AFTER=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.production_run WHERE tanggal='2026-09-26' AND division_id=$ROTI_DIV_ID")
check "exactly one production_run now exists for Roti & Bollen / 2026-09-26" "$RUN_COUNT_AFTER" "1"
ITEM_TARGET_SUM=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COALESCE(SUM(pi.target),0) FROM $DB_NAME.production_item pi INNER JOIN $DB_NAME.production_run r ON r.production_run_id=pi.production_run_id WHERE r.tanggal='2026-09-26' AND r.division_id=$ROTI_DIV_ID")
check "the new draft's own production_item.target snapshot sums to exactly 39 (25+14)" "$ITEM_TARGET_SUM" "39.00"

echo "--- 9/9: negative-stock / no-double-count sanity ---"
NEGATIVE_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_balance WHERE qty_on_hand < 0")
check "no stock_balance row is ever negative" "$NEGATIVE_COUNT" "0"

echo "--- debug: last 30 lines of apache error log ---"
tail -30 "$WORKDIR/apache-error.log" 2>/dev/null || true

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + PHP-FPM + BROWSER VALIDATION PASSED (Production Live PO Target) ==="
  echo "ZIP: $ZIP_PATH"
  echo "Screenshots saved to: $DIST_DIR/livepotarget-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
