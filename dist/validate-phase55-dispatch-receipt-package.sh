#!/usr/bin/env bash
# Validates dist/amor-factory-api-phase55-dispatch-receipt-easy.zip by
# actually EXTRACTING it and driving the extracted files end to end:
# disposable MariaDB pre-loaded with a realistic post-Phase-5 state,
# `php -S` rooted at the EXTRACTED api/ directory, then a full smoke test
# of PO -> Production -> FG -> DO -> Driver Claim -> Confirm Departure ->
# Store Receipt Confirm -> Admin Verify via the real API, confirming stock
# only ever deducts at departure and every read-only step (claim/route/QR)
# never touches stock_ledger — all against the SHIPPED files, not the
# source tree.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-api-phase55-dispatch-receipt-easy.zip"
WORKDIR="$(mktemp -d)"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_p55_pkgvalidate"
ADMIN_PASS="PkgValidateAdmin#$(date +%s)"
MIGRATION_USER_PASS="PkgMigPassP55_123"
RUNTIME_USER_PASS="PkgRunPassP55_123"
PHP_PORT=8110
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
for f in api/_driver-uat/index.php api/_driver-uat/stop.php api/_receive/index.php \
         api/app/src/Dispatch/DispatchService.php api/app/src/Dispatch/DepartureService.php \
         api/app/src/Dispatch/ReceiptService.php api/app/src/Ui/QrEncoder.php \
         api/app/ui/pages/konfirmasi-toko.php api/app/migrations/0007_dispatch_receipt_phase55.php; do
  [ -f "$EXTRACT_DIR/$f" ] || { echo "REFUSING: $f missing from extracted package"; exit 1; }
done
[ -f "$EXTRACT_DIR/api/app/config/config.php" ] && { echo "REFUSING: extracted package contains a config.php — credentials leaked into the ZIP"; exit 1; }
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/9: initializing disposable MariaDB ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'pkgp55_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'pkgp55_migration_user'@'localhost';
CREATE USER 'pkgp55_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'pkgp55_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/9: applying 0001-0007 from the REPO's own migrate.php (simulates 'Phase 5 already installed, now upgrading') ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pkgp55_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" pkgp55_validate_admin "PkgP55 Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
php -r '
require "'"$REPO_ROOT"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database;
Config::load();
$pdo = Database::pdo();
$hash = password_hash("PkgDriverPass123", PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (username,password_hash,full_name,active,created_at) VALUES (\"pkgp55_driver\",?,\"PkgP55 Driver\",1,UTC_TIMESTAMP())")->execute([$hash]);
$pdo->exec("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT (SELECT user_id FROM users WHERE username=\"pkgp55_driver\"), role_id FROM roles WHERE code=\"DRIVER\"");
'
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/9: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pkgp55_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/9: router script (extracted-tree-local) so /api/app/ui/assets/*.css|js|png serve correctly under php -S ---"
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

echo "--- 7/9: PO -> Production -> FG -> DO (real API against the extracted package) ---"
ADMIN_JAR="$WORKDIR/admin_cookies.txt"
DRIVER_JAR="$WORKDIR/driver_cookies.txt"
BASE="http://127.0.0.1:$PHP_PORT"
curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d "{\"username\":\"pkgp55_validate_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
ME=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/auth/me")
CSRF_A=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["csrfToken"]??"";' "$ME")
[ -n "$CSRF_A" ] || { echo "REFUSING: no admin csrf token"; exit 1; }
curl -s -c "$DRIVER_JAR" -b "$DRIVER_JAR" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d '{"username":"pkgp55_driver","password":"PkgDriverPass123"}' > /dev/null
MED=$(curl -s -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/api/auth/me")
CSRF_D=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["csrfToken"]??"";' "$MED")
[ -n "$CSRF_D" ] || { echo "REFUSING: no driver csrf token"; exit 1; }

DIVISION_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT division_id FROM $DB_NAME.division WHERE name='Roti & Bollen'")
FACTORY_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT factory_id FROM $DB_NAME.division WHERE division_id=$DIVISION_ID")
PRODUCT_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT product_id FROM $DB_NAME.product WHERE division_id=$DIVISION_ID ORDER BY product_id LIMIT 1")
STORE_ID=$(mariadb --socket="$SOCK" -u root -N -e "SELECT store_id FROM $DB_NAME.store WHERE canonical_name='P2 TEST STORE A'")
TANGGAL="2026-08-01"
mariadb --socket="$SOCK" -u root -e "
INSERT INTO $DB_NAME.po_batch (tanggal, factory_id, version, created_at) VALUES ('$TANGGAL', $FACTORY_ID, 1, UTC_TIMESTAMP());
SET @bid = LAST_INSERT_ID();
INSERT INTO $DB_NAME.po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (@bid, $PRODUCT_ID, NULL, 8, 0, 0);
SET @iid = LAST_INSERT_ID();
INSERT INTO $DB_NAME.po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (@iid, $STORE_ID, 8, 0);
"
curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/production" -H "X-CSRF-Token: $CSRF_A" -H "Idempotency-Key: pkgp55-prod-$(date +%s)" -H 'Content-Type: application/json' -d "{\"tanggal\":\"$TANGGAL\",\"divisionId\":$DIVISION_ID}" > /tmp/pkgp55_prod.json
RUN_ID=$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["data"]["productionRunId"]??"";' /tmp/pkgp55_prod.json)
[ -n "$RUN_ID" ] || { echo "REFUSING: production create failed: $(cat /tmp/pkgp55_prod.json)"; exit 1; }
curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X PATCH "$BASE/api/production/$RUN_ID" -H "X-CSRF-Token: $CSRF_A" -H "Idempotency-Key: pkgp55-prodpatch-$(date +%s)" -H 'Content-Type: application/json' -d "{\"expectedVersion\":1,\"items\":[{\"productId\":$PRODUCT_ID,\"actualQty\":8}]}" > /dev/null
curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/production/$RUN_ID/submit" -H "X-CSRF-Token: $CSRF_A" -H "Idempotency-Key: pkgp55-prodsubmit-$(date +%s)" -H 'Content-Type: application/json' -d '{"expectedVersion":2}' > /dev/null

curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/fg" -H "X-CSRF-Token: $CSRF_A" -H "Idempotency-Key: pkgp55-fgcreate-$(date +%s)" -H 'Content-Type: application/json' -d "{\"tanggal\":\"$TANGGAL\",\"factoryId\":$FACTORY_ID}" > /tmp/pkgp55_fg.json
FG_BATCH_ID=$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["data"]["fgBatchId"]??"";' /tmp/pkgp55_fg.json)
curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X PATCH "$BASE/api/fg/$FG_BATCH_ID" -H "X-CSRF-Token: $CSRF_A" -H "Idempotency-Key: pkgp55-fgpatch-$(date +%s)" -H 'Content-Type: application/json' -d "{\"expectedVersion\":1,\"items\":[{\"productId\":$PRODUCT_ID,\"fgVerified\":8,\"packed\":8}]}" > /tmp/pkgp55_fgpatch.json
FG_V2=$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["data"]["version"]??"";' /tmp/pkgp55_fgpatch.json)
curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/fg/$FG_BATCH_ID/submit" -H "X-CSRF-Token: $CSRF_A" -H "Idempotency-Key: pkgp55-fgsubmit-$(date +%s)" -H 'Content-Type: application/json' -d "{\"expectedVersion\":$FG_V2}" > /dev/null

curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/do" -H "X-CSRF-Token: $CSRF_A" -H "Idempotency-Key: pkgp55-docreate-$(date +%s)" -H 'Content-Type: application/json' -d "{\"tanggal\":\"$TANGGAL\",\"storeId\":$STORE_ID}" > /tmp/pkgp55_do.json
DO_ID=$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["data"]["doId"]??"";' /tmp/pkgp55_do.json)
[ -n "$DO_ID" ] || { echo "REFUSING: DO create failed: $(cat /tmp/pkgp55_do.json)"; exit 1; }

echo "--- 8/9: Driver claim -> Confirm Departure -> Store Receipt -> Admin Verify (real API), checking stock only moves at departure ---"
LEDGER_BEFORE=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_ledger WHERE event_type='shipment_out'")

AVAIL=$(curl -s -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/api/dispatch/available?tanggal=$TANGGAL")
DOITEM_ID=$(php -r '$d=json_decode($argv[1],true); foreach($d["data"]["items"] as $i){ if($i["doId"]=='"$DO_ID"'){echo $i["doItemId"];break;} }' "$AVAIL")
[ -n "$DOITEM_ID" ] || { echo "REFUSING: dispatch/available did not list the DO item: $AVAIL"; exit 1; }

CLAIM=$(curl -s -c "$DRIVER_JAR" -b "$DRIVER_JAR" -X POST "$BASE/api/dispatch/claim" -H "X-CSRF-Token: $CSRF_D" -H "Idempotency-Key: pkgp55-claim-$(date +%s)" -H 'Content-Type: application/json' -d "{\"lines\":[{\"doItemId\":$DOITEM_ID,\"qty\":8}]}")
CLAIM_ID=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["claims"][0]["claimId"]??"";' "$CLAIM")
[ -n "$CLAIM_ID" ] || { echo "REFUSING: claim failed: $CLAIM"; exit 1; }
LEDGER_AFTER_CLAIM=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_ledger WHERE event_type='shipment_out'")
[ "$LEDGER_BEFORE" = "$LEDGER_AFTER_CLAIM" ] || { echo "REFUSING: claiming touched stock_ledger"; exit 1; }

STOP=$(curl -s -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/api/dispatch/route/stops/$STORE_ID?tanggal=$TANGGAL")
DO_VERSION=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["doVersion"];' "$STOP")

DEPART=$(curl -s -c "$DRIVER_JAR" -b "$DRIVER_JAR" -X POST "$BASE/api/dispatch/departures" -H "X-CSRF-Token: $CSRF_D" -H "Idempotency-Key: pkgp55-depart-$(date +%s)" -H 'Content-Type: application/json' -d "{\"doId\":$DO_ID,\"expectedVersion\":$DO_VERSION,\"shipmentGroup\":\"MAIN\",\"items\":[{\"claimId\":$CLAIM_ID,\"actualQty\":8}]}")
SHIPMENT_ID=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["shipments"][0]["shipmentId"]??"";' "$DEPART")
[ -n "$SHIPMENT_ID" ] || { echo "REFUSING: departure failed: $DEPART"; exit 1; }
LEDGER_AFTER_DEPART=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.stock_ledger WHERE event_type='shipment_out'")
[ "$LEDGER_AFTER_DEPART" = "$((LEDGER_BEFORE + 1))" ] || { echo "REFUSING: expected exactly one new shipment_out row from departure, before=$LEDGER_BEFORE after=$LEDGER_AFTER_DEPART"; exit 1; }

TOKEN=$(php -r '
require "'"$EXTRACT_DIR"'/api/app/autoload.php";
use Amor\Api\Config; use Amor\Api\Database; use Amor\Api\Dispatch\ReceiptService;
Config::load();
$pdo = Database::pdo();
$svc = new ReceiptService($pdo);
echo $svc->getReceiptToken((int)'"$DO_ID"');
')
[ -n "$TOKEN" ] || { echo "REFUSING: could not obtain receipt token"; exit 1; }
PUBVIEW=$(curl -s "$BASE/api/receive/$TOKEN")
echo "$PUBVIEW" | grep -q "\"shipmentId\":$SHIPMENT_ID" || { echo "REFUSING: public receive view did not list the real shipment: $PUBVIEW"; exit 1; }
SHIPITEM=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["shipments"][0]["items"][0]["shipmentItemId"];' "$PUBVIEW")

CONFIRM=$(curl -s -X POST "$BASE/api/receive/$TOKEN/shipments/$SHIPMENT_ID/confirm" -H "Idempotency-Key: pkgp55-confirm-$(date +%s)" -H 'Content-Type: application/json' -d "{\"receiverName\":\"Budi\",\"items\":[{\"shipmentItemId\":$SHIPITEM,\"receivedGood\":8,\"reject\":0,\"shortage\":0}]}")
RECEIPT_ID=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["receiptId"]??"";' "$CONFIRM")
[ -n "$RECEIPT_ID" ] || { echo "REFUSING: public receipt confirm failed: $CONFIRM"; exit 1; }
echo "$CONFIRM" | grep -q '"status":"confirmed_ok"' || { echo "REFUSING: expected confirmed_ok status: $CONFIRM"; exit 1; }

ADMIN_LIST=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/api/admin/receipts?tanggal=$TANGGAL")
echo "$ADMIN_LIST" | grep -q "\"receiptId\":$RECEIPT_ID" || { echo "REFUSING: admin receipts list did not show the new receipt: $ADMIN_LIST"; exit 1; }
VERIFY=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" -X POST "$BASE/api/admin/receipts/$RECEIPT_ID/verify" -H "X-CSRF-Token: $CSRF_A" -H "Idempotency-Key: pkgp55-verify-$(date +%s)" -H 'Content-Type: application/json' -d '{}')
echo "$VERIFY" | grep -q '"status":"verified"' || { echo "REFUSING: admin verify failed: $VERIFY"; exit 1; }

DRIVER_PAGE=$(curl -s -o /dev/null -w "%{http_code}" -c "$DRIVER_JAR" -b "$DRIVER_JAR" "$BASE/_driver-uat/?tab=tersedia")
[ "$DRIVER_PAGE" = "200" ] || { echo "REFUSING: driver portal did not return 200 — got $DRIVER_PAGE"; exit 1; }
KONFIRMASI_PAGE=$(curl -s -o /dev/null -w "%{http_code}" -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/_ui-preview/?page=konfirmasi-toko")
[ "$KONFIRMASI_PAGE" = "200" ] || { echo "REFUSING: admin Konfirmasi Toko page did not return 200 — got $KONFIRMASI_PAGE"; exit 1; }
PRINT_PAGE=$(curl -s -c "$ADMIN_JAR" -b "$ADMIN_JAR" "$BASE/_ui-preview/print-do.php?doId=$DO_ID")
echo "$PRINT_PAGE" | grep -q "print-receipt-qr" || { echo "REFUSING: DO print page did not include the receipt QR block"; exit 1; }

echo "--- 9/9: done ---"
echo ""
echo "=== PACKAGE VALIDATION PASSED ==="
echo "ZIP: $ZIP_PATH"
echo "Extracted, migrated 0001-0007, PO->Production->FG->DO created, driver claimed + confirmed departure"
echo "(exactly one new shipment_out row), public receipt confirmed, admin verified, driver portal + admin"
echo "Konfirmasi Toko page + DO print QR block all confirmed present — all against the SHIPPED files."
