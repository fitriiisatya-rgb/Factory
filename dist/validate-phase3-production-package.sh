#!/usr/bin/env bash
# Validates dist/amor-factory-api-phase3-production-easy.zip by actually
# EXTRACTING it (never just re-running tests against the source tree) and
# driving the extracted files end to end: disposable MariaDB pre-loaded
# with a realistic post-Phase-2 state, config.php written directly into the
# extracted tree (never the repo), `php -S` rooted at the EXTRACTED api/
# directory, migration 0004 applied via the real _upgrade/ wizard, then a
# smoke test of the Production API + UAT wizard against the shipped code.
#
# This is what proves the ZIP itself — not just the source — actually
# works: file layout, .htaccess correctness (grep sanity below; `php -S`
# ignores .htaccess entirely, so this is checked by direct inspection, not
# by the smoke test), and that nothing needed by Phase 3 was left out of
# the package (e.g. api/bin/* is correctly NOT expected here — a cPanel
# deployment never has shell/CLI access, migrations apply only via the web
# _upgrade/ wizard, exactly as exercised below).
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-api-phase3-production-easy.zip"
WORKDIR="$(mktemp -d)"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_phase3_pkgvalidate"
ADMIN_PASS="PkgValidateAdmin#$(date +%s)"
MIGRATION_USER_PASS="PkgMigPass_123"
RUNTIME_USER_PASS="PkgRunPass_123"
PHP_PORT=8099
PHP_PID=""
MIGRATION_0004="$REPO_ROOT/api/app/migrations/0004_production_phase3.php"
MIGRATION_0004_PARKED="$WORKDIR/0004_production_phase3.php.parked"

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then kill "$PHP_PID" 2>/dev/null || true; fi
  if [ -S "$SOCK" ]; then mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true; sleep 1; fi
  if [ -f "$MIGRATION_0004_PARKED" ] && [ ! -f "$MIGRATION_0004" ]; then mv "$MIGRATION_0004_PARKED" "$MIGRATION_0004"; fi
  rm -f "$REPO_ROOT/api/app/config/config.php"
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

echo "--- 1/9: extracting the SHIPPED ZIP (never the source tree) ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
[ -f "$EXTRACT_DIR/api/index.php" ] || { echo "REFUSING: api/index.php missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/_production-uat/index.php" ] || { echo "REFUSING: api/_production-uat/index.php missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/app/migrations/0004_production_phase3.php" ] || { echo "REFUSING: migration 0004 pointer missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/app/database/schema-v1-0004-production-phase3.sql" ] || { echo "REFUSING: schema-v1-0004 SQL missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/app/config/config.php" ] && { echo "REFUSING: extracted package contains a config.php — credentials leaked into the ZIP"; exit 1; }
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/9: sanity — extracted .htaccess has the real-directory bypass ---"
grep -qE '^\s*RewriteCond %\{REQUEST_FILENAME\} -d\s*$' "$EXTRACT_DIR/api/.htaccess" || { echo "REFUSING: extracted .htaccess missing -d bypass"; exit 1; }

echo "--- 3/9: initializing disposable MariaDB pre-loaded with a realistic post-Phase-2 state ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'pkg_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'pkg_migration_user'@'localhost';
CREATE USER 'pkg_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'pkg_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 4/9: applying 0001-0003 from the REPO's own migrate.php (simulates 'Phase 2 already installed') ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pkg_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
mv "$MIGRATION_0004" "$MIGRATION_0004_PARKED"
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; mv "$MIGRATION_0004_PARKED" "$MIGRATION_0004"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; mv "$MIGRATION_0004_PARKED" "$MIGRATION_0004"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" pkg_validate_admin "Pkg Validate Admin" || { mv "$MIGRATION_0004_PARKED" "$MIGRATION_0004"; exit 1; }
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; mv "$MIGRATION_0004_PARKED" "$MIGRATION_0004"; exit 1; }
mv "$MIGRATION_0004_PARKED" "$MIGRATION_0004"
rm -f "$REPO_ROOT/api/app/config/config.php"
echo "pre-loaded DB now simulates: Phase 0+1+2 fully installed, migration 0004 genuinely pending."

echo "--- 5/9: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pkg_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'MIGRATION_DB_HOST' => 'unused-socket-mode', 'MIGRATION_DB_SOCKET' => '$SOCK',
    'MIGRATION_DB_NAME' => '$DB_NAME',
    'MIGRATION_DB_USER' => 'pkg_migration_user', 'MIGRATION_DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 6/9: starting php -S rooted at the EXTRACTED api/ directory ---"
php -S "127.0.0.1:$PHP_PORT" -t "$EXTRACT_DIR/api" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
kill -0 "$PHP_PID" 2>/dev/null || { echo "php -S failed to start"; cat "$WORKDIR/php-server.log"; exit 1; }

echo "--- 7/9: health check against the extracted package ---"
HEALTH=$(curl -s "http://127.0.0.1:$PHP_PORT/api/health")
echo "$HEALTH" | grep -q '"ok":true' || { echo "REFUSING: /api/health did not report ok — $HEALTH"; exit 1; }

echo "--- 8/9: applying migration 0004 via the extracted _upgrade/ wizard over real HTTP ---"
JAR="$WORKDIR/cookies.txt"
curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/auth/login" \
  -H 'Content-Type: application/json' -d "{\"username\":\"pkg_validate_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
PAGE=$(curl -s -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/_upgrade/")
CSRF=$(echo "$PAGE" | grep -oE 'name="csrf" value="[a-f0-9]+"' | head -1 | sed -E 's/.*value="([a-f0-9]+)".*/\1/')
[ -n "$CSRF" ] || { echo "REFUSING: could not find csrf token on extracted _upgrade/ page"; exit 1; }
echo "$PAGE" | grep -q '0004_production_phase3.php' || { echo "REFUSING: extracted _upgrade/ does not list migration 0004 as pending"; exit 1; }
APPLY=$(curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/_upgrade/" --data-urlencode "csrf=$CSRF" --data-urlencode "action=apply" --data-urlencode "confirm=1")
echo "$APPLY" | grep -qE 'Migrasi berhasil diterapkan|Tidak ada yang perlu diterapkan' || { echo "REFUSING: migration 0004 did not apply cleanly via extracted _upgrade/"; exit 1; }
echo "migration 0004 applied against the EXTRACTED package."

echo "--- 9/9: smoke-testing the Production API + UAT wizard against the extracted package ---"
ME=$(curl -s -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/api/auth/me")
CSRF2=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["csrfToken"]??"";' "$ME")
[ -n "$CSRF2" ] || { echo "REFUSING: no csrf token from /api/auth/me"; exit 1; }

DIVISION_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT division_id FROM $DB_NAME.division WHERE name='Roti & Bollen'")
PRODUCT_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT product_id FROM $DB_NAME.product WHERE division_id=$DIVISION_ID ORDER BY product_id LIMIT 1")
TANGGAL="2026-03-01"
mariadb --socket="$SOCK" -u root -e "
INSERT INTO $DB_NAME.po_batch (tanggal, factory_id, version, created_at) VALUES ('$TANGGAL', 1, 1, UTC_TIMESTAMP());
SET @bid = LAST_INSERT_ID();
INSERT INTO $DB_NAME.po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (@bid, $PRODUCT_ID, NULL, 25, 0, 0);
"

TARGET=$(curl -s -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/api/production/target?date=$TANGGAL&divisionId=$DIVISION_ID" -H "X-CSRF-Token: $CSRF2")
echo "$TARGET" | grep -q '"target":25' || { echo "REFUSING: extracted package's /api/production/target did not return target 25 — $TARGET"; exit 1; }

CREATE=$(curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/production" \
  -H "X-CSRF-Token: $CSRF2" -H "Idempotency-Key: pkgvalidate-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"tanggal\":\"$TANGGAL\",\"divisionId\":$DIVISION_ID}")
echo "$CREATE" | grep -q '"status":"draft"' || { echo "REFUSING: extracted package's POST /api/production did not create a draft — $CREATE"; exit 1; }

UAT_PAGE=$(curl -s -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/_production-uat/?tanggal=$TANGGAL&divisionId=$DIVISION_ID")
echo "$UAT_PAGE" | grep -q 'Draft Produksi' || { echo "REFUSING: extracted _production-uat/ wizard did not render the draft screen"; exit 1; }

echo ""
echo "=== PACKAGE VALIDATION PASSED ==="
echo "ZIP: $ZIP_PATH"
echo "Extracted, config.php written post-extraction, migration 0004 applied over real HTTP,"
echo "Production API + UAT wizard smoke-tested — all against the SHIPPED files, not the source tree."
