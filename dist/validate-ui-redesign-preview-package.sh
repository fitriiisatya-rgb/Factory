#!/usr/bin/env bash
# Validates dist/amor-factory-ui-redesign-preview.zip by actually
# EXTRACTING it (never just re-running tests against the source tree) and
# driving the extracted files end to end: disposable MariaDB pre-loaded
# with a realistic post-Phase-5 state (NO new migration to apply — this
# package changes no schema), `php -S` rooted at the EXTRACTED api/
# directory, then a smoke test of the full PO -> Production -> FG -> DO ->
# Shipment flow AND the new /_ui-preview/ pages AND every old UAT tool,
# all against the SHIPPED files.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-ui-redesign-preview.zip"
WORKDIR="$(mktemp -d)"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_ui_pkgvalidate"
ADMIN_PASS="PkgValidateAdmin#$(date +%s)"
MIGRATION_USER_PASS="PkgMigPassUI_123"
RUNTIME_USER_PASS="PkgRunPassUI_123"
PHP_PORT=8105
PHP_PID=""

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then kill "$PHP_PID" 2>/dev/null || true; fi
  if [ -S "$SOCK" ]; then mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true; sleep 1; fi
  rm -f "$REPO_ROOT/api/app/config/config.php"
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

echo "--- 1/10: extracting the SHIPPED ZIP (never the source tree) ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
[ -f "$EXTRACT_DIR/api/index.php" ] || { echo "REFUSING: api/index.php missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/_ui-preview/index.php" ] || { echo "REFUSING: api/_ui-preview/index.php missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/assets/css/app.css" ] || { echo "REFUSING: api/assets/css/app.css missing from extracted package"; exit 1; }
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat; do
  [ -f "$EXTRACT_DIR/api/$tool/index.php" ] || { echo "REFUSING: api/$tool/index.php missing — an old tool was dropped"; exit 1; }
done
[ -f "$EXTRACT_DIR/api/app/config/config.php" ] && { echo "REFUSING: extracted package contains a config.php — credentials leaked into the ZIP"; exit 1; }
if find "$EXTRACT_DIR" -iname '*invoice*' -o -iname '*payment*' -o -iname '*receivable*' -o -iname '*retur*' -o -iname '*reject*' | grep -q .; then
  echo "REFUSING: extracted package contains out-of-scope Invoice/Payment/Receivable/Retur/Reject files"; exit 1
fi
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/10: sanity — extracted .htaccess has both the -f and -d bypasses ---"
grep -qE '^\s*RewriteCond %\{REQUEST_FILENAME\} -d\s*$' "$EXTRACT_DIR/api/.htaccess" || { echo "REFUSING: extracted .htaccess missing -d bypass"; exit 1; }
grep -qE '^\s*RewriteCond %\{REQUEST_FILENAME\} -f' "$EXTRACT_DIR/api/.htaccess" || { echo "REFUSING: extracted .htaccess missing -f bypass"; exit 1; }

echo "--- 3/10: initializing disposable MariaDB pre-loaded with a realistic post-Phase-5 state ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'pkgui_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'pkgui_migration_user'@'localhost';
CREATE USER 'pkgui_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'pkgui_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 4/10: applying 0001-0006 from the REPO's own migrate.php (simulates 'Phase 5 already installed') ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pkgui_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" pkgui_validate_admin "PkgUI Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
rm -f "$REPO_ROOT/api/app/config/config.php"
echo "pre-loaded DB now simulates: Phase 0-5 fully installed. No migration needed by this package."

echo "--- 5/10: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pkgui_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 6/10: writing a router script (extracted-tree-local, mirrors _ui_router.php) so /api/assets/*.css serve correctly under php -S ---"
cat > "$WORKDIR/router.php" <<PHPROUTER
<?php
\$uri = urldecode((string) parse_url((string) \$_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#^/api/(assets/[\w./-]+\.(css|js|png|jpg|jpeg|svg))\$#', \$uri, \$m)) {
    \$file = '$EXTRACT_DIR/api/' . \$m[1];
    if (is_file(\$file)) {
        \$types = ['css' => 'text/css; charset=UTF-8', 'js' => 'application/javascript; charset=UTF-8', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml'];
        header('Content-Type: ' . \$types[\$m[2]]);
        readfile(\$file);
        return true;
    }
}
return false;
PHPROUTER

echo "--- 7/10: starting php -S rooted at the EXTRACTED api/ directory ---"
php -S "127.0.0.1:$PHP_PORT" -t "$EXTRACT_DIR/api" "$WORKDIR/router.php" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
kill -0 "$PHP_PID" 2>/dev/null || { echo "php -S failed to start"; cat "$WORKDIR/php-server.log"; exit 1; }

echo "--- 8/10: health check + static asset + old-tool reachability against the extracted package ---"
HEALTH=$(curl -s "http://127.0.0.1:$PHP_PORT/api/health")
echo "$HEALTH" | grep -q '"ok":true' || { echo "REFUSING: /api/health did not report ok — $HEALTH"; exit 1; }
CSS_TYPE=$(curl -s -o /dev/null -w "%{content_type}" "http://127.0.0.1:$PHP_PORT/api/assets/css/app.css")
echo "$CSS_TYPE" | grep -q "text/css" || { echo "REFUSING: app.css did not serve as text/css — got $CSS_TYPE"; exit 1; }
for tool in _admin-login _import-po _production-uat _fg-uat _do-uat; do
  CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$PHP_PORT/$tool/")
  [ "$CODE" = "200" ] || { echo "REFUSING: extracted api/$tool/ did not return 200 (got $CODE) — an old tool regressed"; exit 1; }
done

echo "--- 9/10: smoke-testing PO -> Production -> FG -> DO -> Shipment (real API) then the new UI pages, against the extracted package ---"
JAR="$WORKDIR/cookies.txt"
curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/auth/login" \
  -H 'Content-Type: application/json' -d "{\"username\":\"pkgui_validate_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
ME=$(curl -s -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/api/auth/me")
CSRF=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["csrfToken"]??"";' "$ME")
[ -n "$CSRF" ] || { echo "REFUSING: no csrf token from /api/auth/me"; exit 1; }

DIVISION_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT division_id FROM $DB_NAME.division WHERE name='Roti & Bollen'")
FACTORY_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT factory_id FROM $DB_NAME.division WHERE division_id=$DIVISION_ID")
PRODUCT_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT product_id FROM $DB_NAME.product WHERE division_id=$DIVISION_ID ORDER BY product_id LIMIT 1")
STORE_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")
TANGGAL="2026-04-01"
mariadb --socket="$SOCK" -u root -e "
INSERT INTO $DB_NAME.po_batch (tanggal, factory_id, version, created_at) VALUES ('$TANGGAL', $FACTORY_ID, 1, UTC_TIMESTAMP());
SET @bid = LAST_INSERT_ID();
INSERT INTO $DB_NAME.po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (@bid, $PRODUCT_ID, NULL, 6, 0, 0);
SET @iid = LAST_INSERT_ID();
INSERT INTO $DB_NAME.po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (@iid, $STORE_ID, 6, 0);
"

PROD_CREATE=$(curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/production" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkguivalidate-create-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"tanggal\":\"$TANGGAL\",\"divisionId\":$DIVISION_ID}")
RUN_ID=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["productionRunId"]??"";' "$PROD_CREATE")
[ -n "$RUN_ID" ] || { echo "REFUSING: extracted package's POST /api/production did not create a draft — $PROD_CREATE"; exit 1; }
curl -s -c "$JAR" -b "$JAR" -X PATCH "http://127.0.0.1:$PHP_PORT/api/production/$RUN_ID" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkguivalidate-patch-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"expectedVersion\":1,\"items\":[{\"productId\":$PRODUCT_ID,\"actualQty\":6}]}" > /dev/null
curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/production/$RUN_ID/submit" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkguivalidate-submit-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"expectedVersion\":2}" > /dev/null

FG_CREATE=$(curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/fg" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkguivalidate-fgcreate-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"tanggal\":\"$TANGGAL\",\"factoryId\":$FACTORY_ID}")
FG_BATCH_ID=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["fgBatchId"]??"";' "$FG_CREATE")
[ -n "$FG_BATCH_ID" ] || { echo "REFUSING: extracted package's POST /api/fg did not create a draft — $FG_CREATE"; exit 1; }
FG_PATCH=$(curl -s -c "$JAR" -b "$JAR" -X PATCH "http://127.0.0.1:$PHP_PORT/api/fg/$FG_BATCH_ID" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkguivalidate-fgpatch-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"expectedVersion\":1,\"items\":[{\"productId\":$PRODUCT_ID,\"fgVerified\":6,\"packed\":6}]}")
FG_V2=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["version"]??"";' "$FG_PATCH")
curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/fg/$FG_BATCH_ID/submit" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkguivalidate-fgsubmit-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"expectedVersion\":$FG_V2}" > /dev/null

DO_CREATE=$(curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/do" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkguivalidate-docreate-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"tanggal\":\"$TANGGAL\",\"storeId\":$STORE_ID}")
DO_ID=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["doId"]??"";' "$DO_CREATE")
[ -n "$DO_ID" ] || { echo "REFUSING: extracted package's POST /api/do did not create a draft — $DO_CREATE"; exit 1; }
SHIP=$(curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/do/$DO_ID/ship" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkguivalidate-ship-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"expectedVersion\":1,\"shipmentGroup\":\"MAIN\",\"items\":[{\"productId\":$PRODUCT_ID,\"actualQty\":6}]}")
echo "$SHIP" | grep -q '"doFullyFulfilled":true' || { echo "REFUSING: extracted package's ship did not fully fulfill the DO — $SHIP"; exit 1; }

echo "--- 10/10: verifying the new /_ui-preview/ pages render this real data ---"
DASH=$(curl -s -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/_ui-preview/?page=dashboard&tanggal=$TANGGAL&factoryId=$FACTORY_ID")
echo "$DASH" | grep -q 'kpi-value">6' || { echo "REFUSING: dashboard did not render the real PO/Production figure of 6 — $(echo "$DASH" | grep -o 'kpi-value\">[^<]*')"; exit 1; }
DOLIST=$(curl -s -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/_ui-preview/?page=delivery-order-detail&doId=$DO_ID")
echo "$DOLIST" | grep -qi 'terkirim penuh' || { echo "REFUSING: DO detail page did not show Terkirim Penuh status"; exit 1; }
PRINT=$(curl -s -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/_ui-preview/print-do.php?doId=$DO_ID")
echo "$PRINT" | grep -qi 'surat jalan' || { echo "REFUSING: redesigned DO print page did not render"; exit 1; }

echo ""
echo "=== PACKAGE VALIDATION PASSED ==="
echo "ZIP: $ZIP_PATH"
echo "Extracted, config.php written post-extraction, NO migration needed, PO -> Production -> FG -> DO ->"
echo "Shipment flow smoke-tested via the real API, the new /_ui-preview/ Dashboard/DO pages confirmed to"
echo "render that real data, the redesigned print page confirmed to render, and every old UAT tool"
echo "confirmed still reachable — all against the SHIPPED files, not the source tree."
