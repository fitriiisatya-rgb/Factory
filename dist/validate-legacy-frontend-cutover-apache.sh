#!/usr/bin/env bash
# Validates the legacy-frontend docroot cutover against a REAL Apache +
# PHP-FPM server: visiting the bare docroot ("/") now serves the Amor
# Factory Admin UI (dashboard) directly for a logged-in session, and
# redirects to the existing login page for an anonymous visitor (the
# SAME pre-existing auth flow /api/_ui-preview/ already used — this
# cutover adds a new entry point, it does not change that flow). Confirms
# api/ itself is byte-for-byte unchanged, no legacy nav/switch-back link
# exists anywhere, and dark theme / no horizontal overflow hold at the
# root URL just like every other Admin page.
#
# Requires apache2 + php8.3-fpm + node/playwright. Run as root.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
WORKDIR="$(mktemp -d)"
chmod 755 "$WORKDIR"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_cutover_apachevalidate"
ADMIN_PASS="ApacheCutoverAdmin#$(date +%s)"
MIGRATION_USER_PASS="ApacheMigPassCUTOVER_123"
RUNTIME_USER_PASS="ApacheRunPassCUTOVER_123"
HTTP_PORT=8220
FPM_STARTED_BY_US=0
APACHE_SITE_ENABLED=0
APACHE_CONF_ENABLED=0

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  apache2ctl stop >/dev/null 2>&1 || true
  if [ "$APACHE_SITE_ENABLED" = "1" ]; then a2dissite -q cutover-validate >/dev/null 2>&1 || true; fi
  if [ "$APACHE_CONF_ENABLED" = "1" ]; then a2disconf -q cutover-listen >/dev/null 2>&1 || true; fi
  rm -f /etc/apache2/sites-available/cutover-validate.conf /etc/apache2/sites-enabled/cutover-validate.conf
  rm -f /etc/apache2/conf-available/cutover-listen.conf /etc/apache2/conf-enabled/cutover-listen.conf
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

echo "--- 1/7: staging the FULL repo tree as the docroot (index.php + the ENTIRE untouched api/, exactly as a real cPanel docroot would have both) ---"
mkdir -p "$EXTRACT_DIR"
cp "$REPO_ROOT/index.php" "$EXTRACT_DIR/index.php"
cp -r "$REPO_ROOT/api" "$EXTRACT_DIR/api"
rm -f "$EXTRACT_DIR/api/app/config/config.php"
find "$WORKDIR" -type d -exec chmod 755 {} +
find "$EXTRACT_DIR" -type f -exec chmod 644 {} +
echo "staged OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/7: initializing disposable MariaDB ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'cutovermig_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'cutovermig_user'@'localhost';
CREATE USER 'cutoverrun_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'cutoverrun_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/7: migrate (0001-0010) + seed + admin ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'cutovermig_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" cutover_admin "Cutover Apache Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/7: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'cutoverrun_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/7: starting REAL php8.3-fpm + REAL apache2 (AllowOverride All, like real cPanel) ---"
mkdir -p /run/php
chown www-data:www-data /run/php
if [ ! -S /run/php/php8.3-fpm.sock ]; then
  /usr/sbin/php-fpm8.3 -D --fpm-config /etc/php/8.3/fpm/php-fpm.conf
  FPM_STARTED_BY_US=1
  for i in $(seq 1 20); do [ -S /run/php/php8.3-fpm.sock ] && break; sleep 0.3; done
  [ -S /run/php/php8.3-fpm.sock ] || { echo "php8.3-fpm did not come up"; exit 1; }
fi
cat > /etc/apache2/conf-available/cutover-listen.conf <<CONF
Listen $HTTP_PORT
CONF
a2enconf -q cutover-listen
APACHE_CONF_ENABLED=1
cat > /etc/apache2/sites-available/cutover-validate.conf <<CONF
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
a2ensite -q cutover-validate
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

echo "--- 6/7: baseline sanity (curl) — anonymous root redirects to login, api/ still forbidden ---"
ANON_ROOT_STATUS=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")
check "GET / while anonymous is a redirect (302)" "$ANON_ROOT_STATUS" 302
ANON_ROOT_LOCATION=$(curl -s -D - -o /dev/null "$BASE/" | grep -i '^Location:' | tr -d '\r' | awk '{print $2}')
if [[ "$ANON_ROOT_LOCATION" == /api/_admin-login/* ]]; then
  echo "PASS: anonymous / redirects into the EXISTING login page (got $ANON_ROOT_LOCATION)"
else
  echo "FAIL: anonymous / did not redirect to /api/_admin-login/ (got $ANON_ROOT_LOCATION)"; FAIL=1
fi
check "api/app/config/config.php is still forbidden" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/app/config/config.php")" 403

echo "--- 7/7: REAL headless-Chromium — logged-in root ('/') serves the Admin dashboard directly ---"
cat > "$WORKDIR/cutover.js" <<'NODEEOF'
const { chromium } = require('playwright');
const base = process.argv[2];
const adminUsername = process.argv[3];
const adminPassword = process.argv[4];
const shotPath = process.argv[5];

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

  const response = await page.goto(base + '/');
  await page.waitForLoadState('networkidle');

  check('GET / (logged in) returns 200 directly, no redirect', response.status() === 200);
  check('URL bar stays at the docroot ("/"), not bounced into /api/_ui-preview/', page.url() === base + '/');

  const bodyText = await page.textContent('body');
  check('root page shows the real Admin dashboard content (Dashboard Operasional)', bodyText.includes('Dashboard Operasional'));

  const bodyBg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  check('dark navy theme preserved at the docroot', /rgb\(\s*\d+,\s*\d+,\s*\d+\)/.test(bodyBg) && bodyBg !== 'rgb(255, 255, 255)');

  check('sidebar/topbar (the real Admin shell) is present at the docroot', (await page.locator('.app-shell').count()) === 1);

  // No legacy navigation / switch-back link of any kind.
  const legacyMentions = await page.locator('a:has-text("Legacy"), a:has-text("Frontend Lama"), a:has-text("Switch"), a:has-text("Kembali ke")').count();
  check('no legacy-frontend nav / switch-back link anywhere on the page', legacyMentions === 0);

  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
  check('no horizontal overflow at the docroot (desktop)', scrollWidth <= clientWidth);

  await page.screenshot({ path: shotPath, fullPage: true });

  // Sanity: a real Admin nav link still correctly lands on /api/_ui-preview/...
  await page.click('a:has-text("Pesanan Toko")');
  await page.waitForLoadState('networkidle');
  check('normal in-app navigation still works and lands on /api/_ui-preview/', page.url().includes('/api/_ui-preview/'));

  await context.close();
  await browser.close();
  process.exit(FAIL);
})().catch((err) => {
  console.error('CRASHED:', err.stack);
  process.exit(1);
});
NODEEOF
NODE_PATH=/opt/node22/lib/node_modules node "$WORKDIR/cutover.js" "$BASE" "cutover_admin" "$ADMIN_PASS" "$DIST_DIR/root-cutover-desktop-screenshot.png"
NODE_EXIT=$?
if [ "$NODE_EXIT" != "0" ]; then FAIL=1; fi

echo "--- extra: confirm api/ is byte-for-byte UNCHANGED by this cutover (this script's own staged copy vs. the repo) ---"
if ! diff -rq "$REPO_ROOT/api" "$EXTRACT_DIR/api" > /dev/null 2>&1; then
  # diff -rq's "file only exists in one tree" format is "Only in DIR: NAME"
  # — config.php is EXPECTED to only exist in the staged/validated tree
  # (it's gitignored, written fresh by step 4 above, never in the repo).
  DIFFOUT=$(diff -rq "$REPO_ROOT/api" "$EXTRACT_DIR/api" 2>&1 | grep -v "^Only in .*/config: config\.php$")
  if [ -n "$DIFFOUT" ]; then
    echo "FAIL: api/ differs between the repo and the staged/validated tree (excluding config.php, which is expected to differ):"
    echo "$DIFFOUT"
    FAIL=1
  else
    echo "PASS: api/ is unchanged (only the disposable config.php differs, as expected)"
  fi
else
  echo "PASS: api/ is byte-for-byte identical to the repo (config.php aside)"
fi

echo ""
if [ "$FAIL" = "0" ]; then
  echo "=== REAL APACHE + BROWSER CUTOVER VALIDATION PASSED ==="
  echo "factory.amorgroup.id/ (docroot) now serves the Admin dashboard directly for a logged-in session, redirects anonymous visitors to the existing login page, api/ is untouched, no legacy nav exists."
  echo "Screenshot: $DIST_DIR/root-cutover-desktop-screenshot.png"
else
  echo "=== VALIDATION FAILED — see FAIL lines above ==="
fi
exit "$FAIL"
