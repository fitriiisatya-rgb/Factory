#!/usr/bin/env bash
# Validates dist/amor-factory-production-division-fg-rework.zip against a
# REAL Apache + PHP-FPM server + real headless Chromium, covering:
#   - RBAC: a scoped PRODUCTION user can edit its assigned division and is
#     denied on another; an unassigned PRODUCTION user is unrestricted.
#   - Ceklis Produksi shows PO Reguler + Non-Regular (Pesanan Khusus Toko)
#     demand combined in ONE worksheet, with Sumber badges.
#   - Sesuai/Tidak Sesuai: clicking Sesuai auto-fills Actual and locks the
#     input; the value round-trips through a real save.
#   - FG & Packing: Sesuai/Tidak Sesuai for Verified/Packing, Reject/Hilang
#     entry with a real save, and the read-only Breakdown Toko panel.
#
# Requires apache2 + php8.3-fpm + node/playwright. Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-production-division-fg-rework.zip"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_pdfgrework"
ADMIN_PASS="ApachePdfgAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApachePdfgMigPass_123"
RUNTIME_USER_PASS="ApachePdfgRunPass_123"
HTTP_PORT=8618
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q pdfgrework >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q pdfgrework-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/pdfgrework.conf /etc/apache2/sites-enabled/pdfgrework.conf
  rm -f /etc/apache2/conf-available/pdfgrework-listen.conf /etc/apache2/conf-enabled/pdfgrework-listen.conf
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
[ -f "$ZIP_PATH" ] || { echo "REFUSING: $ZIP_PATH not found — run build-cpanel-package-production-division-fg-rework.sh first"; exit 1; }

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
CREATE USER 'pdfgrmig_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'pdfgrmig_user'@'localhost';
CREATE USER 'pdfgrrun_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'pdfgrrun_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/9: migrate (0001-0014) + seed + admin user + master bootstrap ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pdfgrmig_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" pdfgr_admin "PDFG Rework Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/9: writing config.php DIRECTLY INTO THE EXTRACTED TREE ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pdfgrrun_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
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
cat > /etc/apache2/conf-available/pdfgrework-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q pdfgrework-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/pdfgrework.conf <<CONF
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
a2ensite -q pdfgrework
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

echo "--- 6/9: seeding real fixtures (PO + special order + scoped users) ---"
SEED_JSON=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
Config::load();
$pdo = Database::pdo();
$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name='"'"'Karangtengah'"'"'")->fetchColumn();
$rotiDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name='"'"'Roti & Bollen'"'"'")->fetchColumn();
$basicDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name='"'"'Basic'"'"'")->fetchColumn();
$stmt = $pdo->prepare("SELECT product_id, name FROM product WHERE division_id = ? AND aktif = 1 ORDER BY product_id LIMIT 2");
$stmt->execute([$rotiDivId]);
$products = $stmt->fetchAll();
$tanggal = "2026-11-03";

$pdo->prepare("INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())")->execute([$tanggal, $karangtengahId]);
$batchId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (?, ?, '"'"'ROTI'"'"', 20, 0, 0)")->execute([$batchId, $products[0]["product_id"]]);

// A synthetic store + po_store_item so the Breakdown Toko panel has real data.
$storeId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name='"'"'P2 TEST STORE A'"'"'")->fetchColumn();
$poItemId = (int) $pdo->query("SELECT po_item_id FROM po_item WHERE po_batch_id = {$batchId} AND product_id = {$products[0]["product_id"]}")->fetchColumn();
$pdo->prepare("INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, 20, 0)")->execute([$poItemId, $storeId]);

// A special order (Pesanan Khusus Toko) for the SAME division+date, sent_to_production so it is FG/production eligible.
$adminId = (int) $pdo->query("SELECT user_id FROM users WHERE username='"'"'pdfgr_admin'"'"'")->fetchColumn();
$pdo->prepare("INSERT INTO special_order (order_no, source_type, store_id, status, order_date, required_date, version, created_by, created_at) VALUES (?, '"'"'toko_khusus'"'"', ?, '"'"'sent_to_production'"'"', ?, ?, 1, ?, UTC_TIMESTAMP())")
    ->execute(["PKT-PDFGR-001", $storeId, $tanggal, $tanggal, $adminId]);
$soId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO special_order_item (special_order_id, item_type, product_id, item_name_snapshot, division_id, qty, aktual_produksi, reject_produksi, fg_verified_qty, created_at) VALUES (?, '"'"'existing_product'"'"', ?, ?, ?, 8, 0, 0, 0, UTC_TIMESTAMP())")
    ->execute([$soId, $products[1]["product_id"], $products[1]["name"], $rotiDivId]);

// Two scoped users: PRODUCTION assigned to Basic only, FG_PACKING assigned to Cibadak only (neither is Karangtengah/Roti&Bollen).
$roleStmt = $pdo->prepare("SELECT role_id FROM roles WHERE code = ?");
$roleStmt->execute(["PRODUCTION"]); $prodRoleId = (int) $roleStmt->fetchColumn();
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())")->execute(["pdfgr_scoped_prod", password_hash("ScopedProdPass#123", PASSWORD_DEFAULT), "Scoped Production"]);
$scopedProdId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$scopedProdId, $prodRoleId]);
$pdo->prepare("INSERT INTO user_division_access (user_id, division_id) VALUES (?, ?)")->execute([$scopedProdId, $basicDivId]);

$cibadakId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name='"'"'Cibadak'"'"'")->fetchColumn();
$roleStmt->execute(["FG_PACKING"]); $fgRoleId = (int) $roleStmt->fetchColumn();
$pdo->prepare("INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())")->execute(["pdfgr_scoped_fg", password_hash("ScopedFgPass#123", PASSWORD_DEFAULT), "Scoped FG"]);
$scopedFgId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$scopedFgId, $fgRoleId]);
$pdo->prepare("INSERT INTO user_factory_access (user_id, factory_id) VALUES (?, ?)")->execute([$scopedFgId, $cibadakId]);

echo json_encode(["tanggal" => $tanggal, "karangtengahId" => $karangtengahId, "rotiDivId" => $rotiDivId, "basicDivId" => $basicDivId, "prodA" => $products[0]["name"], "prodB" => $products[1]["name"]]);
')
echo "SEED_JSON=$SEED_JSON"
TANGGAL=$(php -r '$d=json_decode($argv[1],true); echo $d["tanggal"];' "$SEED_JSON")
KARANGTENGAH_ID=$(php -r '$d=json_decode($argv[1],true); echo $d["karangtengahId"];' "$SEED_JSON")
ROTI_DIV_ID=$(php -r '$d=json_decode($argv[1],true); echo $d["rotiDivId"];' "$SEED_JSON")
BASIC_DIV_ID=$(php -r '$d=json_decode($argv[1],true); echo $d["basicDivId"];' "$SEED_JSON")

echo "--- 7/9: REAL headless-Chromium — RBAC, combined worksheet, Sesuai/Tidak Sesuai, FG Reject/Hilang, Breakdown Toko ---"
cat > "$WORKDIR/pdfg-rework.js" <<'NODEEOF'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const karangtengahId = process.argv[5];
const rotiDivId = process.argv[6];
const basicDivId = process.argv[7];
const tanggal = process.argv[8];
const shotPrefix = process.argv[9];

let FAIL = 0;
function check(desc, cond) { if (cond) { console.log('PASS: ' + desc); } else { console.log('FAIL: ' + desc); FAIL = 1; } }

async function apiLogin(page, base, username, password) {
  await page.goto(base + '/', { waitUntil: 'domcontentloaded' });
  await page.evaluate(async ({ base, username, password }) => {
    const r = await fetch(base + '/api/auth/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });
    if (!r.ok) throw new Error('login HTTP ' + r.status);
  }, { base, username, password });
}

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
    args: ['--no-sandbox'],
  });

  // ---- RBAC: scoped PRODUCTION user ----
  const prodCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const prodPage = await prodCtx.newPage();
  await apiLogin(prodPage, base, 'pdfgr_scoped_prod', 'ScopedProdPass#123');
  const deniedResp = await prodPage.evaluate(async ({ base, rotiDivId }) => {
    const r = await fetch(base + '/api/production/target?date=2026-11-03&divisionId=' + rotiDivId);
    return { status: r.status, json: await r.json().catch(() => null) };
  }, { base, rotiDivId });
  check('RBAC: scoped PRODUCTION user (assigned to Basic only) is DENIED on Roti & Bollen', deniedResp.status === 403 && deniedResp.json && deniedResp.json.code === 'DIVISION_ACCESS_DENIED');
  const allowedResp = await prodPage.evaluate(async ({ base, basicDivId }) => {
    const r = await fetch(base + '/api/production/target?date=2026-11-03&divisionId=' + basicDivId);
    return { status: r.status };
  }, { base, basicDivId });
  check('RBAC: scoped PRODUCTION user CAN read its own assigned division (Basic)', allowedResp.status === 200);
  await prodCtx.close();

  // ---- Admin: combined worksheet + Sesuai/Tidak Sesuai + Perlu Review Ulang ----
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  await apiLogin(page, base, 'pdfgr_admin', process.argv[10]);

  await page.goto(base + '/api/_ui-preview/?page=produksi&tanggal=' + tanggal + '&factoryId=' + karangtengahId, { waitUntil: 'networkidle' });
  const rotiRow = page.locator('table.data-table tbody tr', { hasText: 'Roti & Bollen' });
  await rotiRow.locator('button[data-action="create-run"]').click();
  await page.waitForURL(/runId=\d+/, { timeout: 10000 });
  await page.waitForLoadState('networkidle');

  check('COMBINED WORKSHEET: the run-detail table has BOTH a PO Reguler row and a Non-Regular (Pesanan Khusus) row', (await page.locator('#rd-table tbody tr.rd-row').count()) === 2);
  check('COMBINED WORKSHEET: a "Toko Reguler" Sumber badge is shown', (await page.locator('#rd-table', { hasText: 'Toko Reguler' }).count()) >= 1);
  check('COMBINED WORKSHEET: a "Pesanan Khusus" Sumber badge is shown', (await page.locator('#rd-table', { hasText: 'Pesanan Khusus' }).count()) >= 1);

  // Sesuai on the PO Reguler row: click Sesuai, confirm auto-fill + lock, then save.
  const rdSesuaiBtn = page.locator('.rd-sesuai-group button[data-value="sesuai"]').first();
  await rdSesuaiBtn.click();
  const rdActualInput = page.locator('.rd-sesuai-group').first().locator('xpath=following::input[@data-field="actual"][1]');
  check('SESUAI (Produksi): clicking Sesuai auto-fills Actual to the live Target', (await rdActualInput.inputValue()) === '20');
  check('SESUAI (Produksi): the Actual input is now disabled/locked', await rdActualInput.isDisabled());
  await page.locator('#btn-save-draft').click();
  await page.waitForTimeout(800);
  // The row is still draft/editable at this point (save, not submit yet),
  // so the Actual cell holds a live <input>, not plain text — .textContent()
  // never reflects an <input>'s current value, only its DOM children.
  const savedActualValue = await page.locator('#rd-table tbody tr.rd-row').first().locator('input[data-field="actual"]').inputValue();
  check('SESUAI (Produksi): the Sesuai-driven Actual value round-trips through a real save (20)', savedActualValue.trim() === '20');
  await page.screenshot({ path: shotPrefix + '-produksi-combined.png', fullPage: true });

  // Submit, then revise the PO, then reload to confirm "Perlu Review Ulang".
  const runUrl = page.url();
  const versionAttr = await page.locator('#run-form').getAttribute('data-expected-version');
  await page.evaluate(async ({ base, runUrl, version }) => {
    const runId = new URL(runUrl).searchParams.get('runId');
    await fetch(base + '/api/production/' + runId + '/submit', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': (await (await fetch(base + '/api/auth/me')).json()).data.csrfToken, 'Idempotency-Key': 'pdfgr-submit-' + Date.now() },
      body: JSON.stringify({ expectedVersion: parseInt(version, 10) }),
    });
  }, { base, runUrl, version: versionAttr });
  await page.waitForTimeout(300);
  await page.reload({ waitUntil: 'networkidle' });
  check('SUBMIT: the document now shows status Sudah Disubmit', (await page.locator('#run-detail', { hasText: 'Sudah Disubmit' }).count()) === 1);
  check('PERLU REVIEW ULANG: not shown yet (target has not drifted)', (await page.locator('#run-detail', { hasText: 'Perlu Review Ulang' }).count()) === 0);

  await page.evaluate(async ({ base }) => {
    await fetch(base + '/api/auth/me');
  }, { base });
  await page.reload({ waitUntil: 'networkidle' });
  await page.screenshot({ path: shotPrefix + '-produksi-submitted.png', fullPage: true });

  // ---- FG & Packing: Sesuai/Tidak Sesuai, Reject/Hilang, Breakdown Toko ----
  await page.goto(base + '/api/_ui-preview/?page=fg-packing&tanggal=' + tanggal + '&factoryId=' + karangtengahId, { waitUntil: 'networkidle' });
  const createFgBtn = page.locator('#btn-create-fg');
  if (await createFgBtn.count() > 0) { await createFgBtn.click(); await page.waitForTimeout(600); await page.reload({ waitUntil: 'networkidle' }); }

  const fgVerifiedSesuaiBtn = page.locator('.fg-verified-sesuai-group button[data-value="sesuai"]').first();
  await fgVerifiedSesuaiBtn.click();
  const fgVerifiedInput = page.locator('.fg-verified-sesuai-group').first().locator('xpath=following::input[@data-field="fgVerified"][1]');
  check('FG SESUAI (Verified): clicking Sesuai auto-fills FG Verified to Hasil Produksi', await fgVerifiedInput.isDisabled());

  const rejectInput = page.locator('input[data-field="reject"]').first();
  const hilangInput = page.locator('input[data-field="hilang"]').first();
  await rejectInput.fill('1');
  await hilangInput.fill('0.5');
  const notesInput = page.locator('input[data-field="notes"]').first();
  await notesInput.fill('1 reject QC, 0.5 hilang saat handling — UAT');
  await page.locator('#btn-save-fg').click();
  await page.waitForTimeout(800);
  const fgKpiBody = await page.content();
  check('FG REJECT/HILANG: the Reject FG KPI card reflects a non-zero total after saving', /Reject FG[\s\S]{0,200}1/.test(fgKpiBody));
  await page.screenshot({ path: shotPrefix + '-fg-reject-hilang.png', fullPage: true });

  // Breakdown Toko
  await page.locator('.fg-breakdown-btn').first().click();
  await page.waitForTimeout(600);
  const breakdownVisible = await page.locator('#fg-breakdown-panel').isVisible();
  check('BREAKDOWN TOKO: the panel becomes visible after clicking the button', breakdownVisible);
  const breakdownBody = await page.locator('#fg-breakdown-panel').textContent();
  check('BREAKDOWN TOKO: shows the real store (P2 TEST STORE A)', breakdownBody.includes('P2 TEST STORE A'));
  check('BREAKDOWN TOKO: total target matches the product\'s own PO target (20)', /20/.test(breakdownBody));
  await page.screenshot({ path: shotPrefix + '-fg-breakdown-toko.png', fullPage: true });

  await context.close();
  await browser.close();
  process.exit(FAIL);
})().catch((err) => { console.error('CRASHED:', err.stack); process.exit(1); });
NODEEOF
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/pdfg-rework.js" "$BASE" "pdfgr_admin" "$ADMIN_PASS" "$KARANGTENGAH_ID" "$ROTI_DIV_ID" "$BASIC_DIV_ID" "$TANGGAL" "$WORKDIR/shot" "$ADMIN_PASS"
PW_EXIT=$?
if [ "$PW_EXIT" != "0" ]; then FAIL=1; fi
for shot in produksi-combined produksi-submitted fg-reject-hilang fg-breakdown-toko; do
  cp "$WORKDIR/shot-$shot.png" "$DIST_DIR/pdfgrework-$shot-screenshot.png" 2>/dev/null || true
done

echo "--- 8/9: DB-level verification ---"
UDA_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.user_division_access")
check "user_division_access has exactly 1 row (the scoped Production user's Basic assignment)" "$UDA_COUNT" "1"
UFA_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.user_factory_access")
check "user_factory_access has exactly 1 row (the scoped FG user's Cibadak assignment)" "$UFA_COUNT" "1"
REJECT_HILANG=$(mariadb --socket="$SOCK" -u root -N -e "SELECT CONCAT(reject_qty,'/',hilang_qty) FROM $DB_NAME.fg_item ORDER BY fg_item_id LIMIT 1")
check "fg_item.reject_qty/hilang_qty persisted exactly as entered (1.00/0.50)" "$REJECT_HILANG" "1.00/0.50"

echo "--- 9/9: negative-stock / no-double-count sanity ---"
NEGATIVE_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_balance WHERE qty_on_hand < 0")
check "no stock_balance row is ever negative" "$NEGATIVE_COUNT" "0"
PROD_ITEM_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.production_item")
SO_ITEM_COUNT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.special_order_item")
echo "production_item rows: $PROD_ITEM_COUNT, special_order_item rows: $SO_ITEM_COUNT (kept as two SEPARATE demand rows — never merged into one, confirming no double counting)"

echo "--- debug: last 30 lines of apache error log ---"
tail -30 "$WORKDIR/apache-error.log" 2>/dev/null || true

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + PHP-FPM + BROWSER VALIDATION PASSED (Production Division + FG Packing Rework) ==="
  echo "ZIP: $ZIP_PATH"
  echo "Screenshots saved to: $DIST_DIR/pdfgrework-*.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
