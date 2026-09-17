#!/usr/bin/env bash
# Validates dist/amor-factory-ui-print-do-preview.zip by actually
# EXTRACTING it and driving the extracted files end to end: disposable
# MariaDB pre-loaded with a realistic post-Phase-5 state (no migration to
# apply), `php -S` rooted at the EXTRACTED api/ directory, then a smoke
# test of PO -> Production -> FG -> DO -> partial Shipment via the real
# API, followed by checking the redesigned print-do.php/print-do-bulk.php
# AND the old api/_do-uat/print.php fallback, all against the SHIPPED
# files — and confirming print never mutates stock/version.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-ui-print-do-preview.zip"
WORKDIR="$(mktemp -d)"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_print_pkgvalidate"
ADMIN_PASS="PkgValidateAdmin#$(date +%s)"
MIGRATION_USER_PASS="PkgMigPassPR_123"
RUNTIME_USER_PASS="PkgRunPassPR_123"
PHP_PORT=8107
PHP_PID=""

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then kill "$PHP_PID" 2>/dev/null || true; fi
  if [ -S "$SOCK" ]; then mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true; sleep 1; fi
  rm -f "$REPO_ROOT/api/app/config/config.php"
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

echo "--- 1/9: extracting the SHIPPED ZIP (never the source tree) ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
[ -f "$EXTRACT_DIR/api/_ui-preview/print-do.php" ] || { echo "REFUSING: print-do.php missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/_ui-preview/print-do-bulk.php" ] || { echo "REFUSING: print-do-bulk.php missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/app/ui/print-template.php" ] || { echo "REFUSING: shared print-template.php missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/_do-uat/print.php" ] || { echo "REFUSING: old print.php fallback missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/app/config/config.php" ] && { echo "REFUSING: extracted package contains a config.php — credentials leaked into the ZIP"; exit 1; }
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/9: initializing disposable MariaDB pre-loaded with a realistic post-Phase-5 state ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'pkgpr_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'pkgpr_migration_user'@'localhost';
CREATE USER 'pkgpr_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'pkgpr_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/9: applying 0001-0006 from the REPO's own migrate.php (simulates 'Phase 5 already installed') ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pkgpr_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" pkgpr_validate_admin "PkgPrint Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/9: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pkgpr_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/9: router script (extracted-tree-local) so /api/app/ui/assets/*.css serve correctly under php -S ---"
cat > "$WORKDIR/router.php" <<PHPROUTER
<?php
\$uri = urldecode((string) parse_url((string) \$_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#^/api/(app/ui/assets/[\w./-]+\.(css|js|png|jpg|jpeg|svg))\$#', \$uri, \$m)) {
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

echo "--- 6/9: starting php -S rooted at the EXTRACTED api/ directory ---"
php -S "127.0.0.1:$PHP_PORT" -t "$EXTRACT_DIR/api" "$WORKDIR/router.php" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
kill -0 "$PHP_PID" 2>/dev/null || { echo "php -S failed to start"; cat "$WORKDIR/php-server.log"; exit 1; }

echo "--- 7/9: PO -> Production -> FG -> DO -> partial Shipment (real API) against the extracted package ---"
JAR="$WORKDIR/cookies.txt"
curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/auth/login" \
  -H 'Content-Type: application/json' -d "{\"username\":\"pkgpr_validate_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
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
INSERT INTO $DB_NAME.po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (@bid, $PRODUCT_ID, NULL, 8, 0, 0);
SET @iid = LAST_INSERT_ID();
INSERT INTO $DB_NAME.po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (@iid, $STORE_ID, 8, 0);
"
curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/production" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkgpr-create-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"tanggal\":\"$TANGGAL\",\"divisionId\":$DIVISION_ID}" > /tmp/pkgpr_prod.json
RUN_ID=$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["data"]["productionRunId"]??"";' /tmp/pkgpr_prod.json)
[ -n "$RUN_ID" ] || { echo "REFUSING: production create failed"; exit 1; }
curl -s -c "$JAR" -b "$JAR" -X PATCH "http://127.0.0.1:$PHP_PORT/api/production/$RUN_ID" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkgpr-patch-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"expectedVersion\":1,\"items\":[{\"productId\":$PRODUCT_ID,\"actualQty\":6}]}" > /dev/null
curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/production/$RUN_ID/submit" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkgpr-submit-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"expectedVersion\":2}" > /dev/null

curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/fg" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkgpr-fgcreate-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"tanggal\":\"$TANGGAL\",\"factoryId\":$FACTORY_ID}" > /tmp/pkgpr_fg.json
FG_BATCH_ID=$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["data"]["fgBatchId"]??"";' /tmp/pkgpr_fg.json)
curl -s -c "$JAR" -b "$JAR" -X PATCH "http://127.0.0.1:$PHP_PORT/api/fg/$FG_BATCH_ID" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkgpr-fgpatch-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"expectedVersion\":1,\"items\":[{\"productId\":$PRODUCT_ID,\"fgVerified\":6,\"packed\":6}]}" > /tmp/pkgpr_fgpatch.json
FG_V2=$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["data"]["version"]??"";' /tmp/pkgpr_fgpatch.json)
curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/fg/$FG_BATCH_ID/submit" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkgpr-fgsubmit-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"expectedVersion\":$FG_V2}" > /dev/null

curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/do" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkgpr-docreate-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"tanggal\":\"$TANGGAL\",\"storeId\":$STORE_ID}" > /tmp/pkgpr_do.json
DO_ID=$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["data"]["doId"]??"";' /tmp/pkgpr_do.json)
DOC_NO=$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["data"]["docNo"]??"";' /tmp/pkgpr_do.json)
[ -n "$DO_ID" ] || { echo "REFUSING: DO create failed"; exit 1; }
curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/do/$DO_ID/preprint" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkgpr-preprint-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"expectedVersion\":1}" > /dev/null
SHIP=$(curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/do/$DO_ID/ship" \
  -H "X-CSRF-Token: $CSRF" -H "Idempotency-Key: pkgpr-ship-$(date +%s)" -H 'Content-Type: application/json' \
  -d "{\"expectedVersion\":2,\"shipmentGroup\":\"MAIN\",\"items\":[{\"productId\":$PRODUCT_ID,\"actualQty\":3}]}")
echo "$SHIP" | grep -q '"doFullyFulfilled":false' || { echo "REFUSING: expected a PARTIAL shipment for the print smoke test — $SHIP"; exit 1; }

echo "--- 8/9: verifying the redesigned print pages render this real, partially-shipped DO ---"
DO_VERSION_BEFORE=$(mariadb --socket="$SOCK" -u root -N -e "SELECT version FROM $DB_NAME.delivery_order WHERE delivery_order_id=$DO_ID")
LEDGER_BEFORE=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_ledger WHERE event_type='shipment_out'")

PRINT=$(curl -s -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/_ui-preview/print-do.php?doId=$DO_ID")
echo "$PRINT" | grep -q "$DOC_NO" || { echo "REFUSING: print page did not show the real DO number $DOC_NO"; exit 1; }
echo "$PRINT" | grep -qi "PREPRINT" || { echo "REFUSING: expected PREPRINT status/watermark text (some qty already shipped, but DO not fully fulfilled)"; exit 1; }
echo "$PRINT" | grep -q "Amorcakes" || { echo "REFUSING: expected the Amorcakes & Bakery branding on the printed document"; exit 1; }
# planned=8, shipped=3, remaining=5
echo "$PRINT" | grep -Pzo 'class="num">8</td>\s*<td[^>]*class="num">3</td>\s*<td[^>]*class="num">5</td>' > /dev/null \
  || { echo "REFUSING: expected planned=8/shipped=3/remaining=5 on the print page"; exit 1; }

BULK=$(curl -s -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/_ui-preview/print-do-bulk.php?tanggal=$TANGGAL&factoryId=$FACTORY_ID")
echo "$BULK" | grep -q "$DOC_NO" || { echo "REFUSING: bulk print did not include the real DO"; exit 1; }

OLDPRINT=$(curl -s -o /dev/null -w "%{http_code}" -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/_do-uat/print.php?doId=$DO_ID")
[ "$OLDPRINT" = "200" ] || { echo "REFUSING: old api/_do-uat/print.php fallback did not return 200 — got $OLDPRINT"; exit 1; }

DO_VERSION_AFTER=$(mariadb --socket="$SOCK" -u root -N -e "SELECT version FROM $DB_NAME.delivery_order WHERE delivery_order_id=$DO_ID")
LEDGER_AFTER=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_ledger WHERE event_type='shipment_out'")
[ "$DO_VERSION_BEFORE" = "$DO_VERSION_AFTER" ] || { echo "REFUSING: DO version changed just from opening print pages ($DO_VERSION_BEFORE -> $DO_VERSION_AFTER) — print must be read-only"; exit 1; }
[ "$LEDGER_BEFORE" = "$LEDGER_AFTER" ] || { echo "REFUSING: stock_ledger row count changed just from opening print pages — print must be read-only"; exit 1; }

echo "--- 9/9: done ---"
echo ""
echo "=== PACKAGE VALIDATION PASSED ==="
echo "ZIP: $ZIP_PATH"
echo "Extracted, config.php written post-extraction, PO -> Production -> FG -> DO -> partial Shipment"
echo "smoke-tested via the real API, redesigned single/bulk print pages confirmed to render the real"
echo "DO with correct planned/shipped/remaining and PREPRINT status, the old print.php fallback"
echo "confirmed still working, and DO version + stock_ledger confirmed UNCHANGED by opening any print"
echo "page — all against the SHIPPED files, not the source tree."
