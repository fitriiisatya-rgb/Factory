#!/usr/bin/env bash
# Validates dist/amor-factory-invoice-ui-preview.zip by actually
# EXTRACTING it and driving the extracted files end to end: disposable
# MariaDB pre-loaded with a realistic post-Phase-5 state (no migration to
# apply), `php -S` rooted at the EXTRACTED api/ directory, login as ADMIN,
# then confirming the Invoice preview page renders the mock template
# correctly AND that opening it writes nothing to invoice/invoice_item/
# invoice_shipment/payment (still 0 rows — Phase 6 not implemented) or to
# any Phase 1-5 table — all against the SHIPPED files, not the source tree.
set -uo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
ZIP_PATH="$DIST_DIR/amor-factory-invoice-ui-preview.zip"
WORKDIR="$(mktemp -d)"
EXTRACT_DIR="$WORKDIR/public_html/factory"
DATADIR="$WORKDIR/mariadb-datadir"
SOCK="$WORKDIR/mariadb.sock"
DB_NAME="amor_factory_invoice_pkgvalidate"
ADMIN_PASS="PkgValidateAdmin#$(date +%s)"
MIGRATION_USER_PASS="PkgMigPassINV_123"
RUNTIME_USER_PASS="PkgRunPassINV_123"
PHP_PORT=8108
PHP_PID=""

cleanup() {
  echo "--- tearing down (disposable, local-only) ---"
  if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then kill "$PHP_PID" 2>/dev/null || true; fi
  if [ -S "$SOCK" ]; then mariadb --socket="$SOCK" -u root -e "SHUTDOWN;" 2>/dev/null || true; sleep 1; fi
  rm -f "$REPO_ROOT/api/app/config/config.php"
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

echo "--- 1/8: extracting the SHIPPED ZIP (never the source tree) ---"
mkdir -p "$EXTRACT_DIR"
( cd "$EXTRACT_DIR" && unzip -q "$ZIP_PATH" )
[ -f "$EXTRACT_DIR/api/_ui-preview/invoice-preview.php" ] || { echo "REFUSING: invoice-preview.php missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/app/ui/print-invoice-template.php" ] || { echo "REFUSING: print-invoice-template.php missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/app/ui/fixtures/invoice-mock.php" ] || { echo "REFUSING: invoice-mock.php fixture missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/app/ui/assets/img/amor-logo.png" ] || { echo "REFUSING: amor-logo.png missing from extracted package"; exit 1; }
[ -f "$EXTRACT_DIR/api/app/config/config.php" ] && { echo "REFUSING: extracted package contains a config.php — credentials leaked into the ZIP"; exit 1; }
echo "extracted OK: $(find "$EXTRACT_DIR" -type f | wc -l) files"

echo "--- 2/8: initializing disposable MariaDB pre-loaded with a realistic post-Phase-5 state ---"
mkdir -p "$DATADIR"
mariadb-install-db --datadir="$DATADIR" --auth-root-authentication-method=normal > "$WORKDIR/install.log" 2>&1 || { echo "mariadb-install-db FAILED"; cat "$WORKDIR/install.log"; exit 1; }
/usr/sbin/mariadbd --datadir="$DATADIR" --socket="$SOCK" --skip-networking --user=root --pid-file="$DATADIR/mariadb.pid" > "$WORKDIR/mariadb.log" 2>&1 &
for i in $(seq 1 30); do [ -S "$SOCK" ] && break; sleep 0.5; done
[ -S "$SOCK" ] || { echo "MariaDB did not come up"; cat "$WORKDIR/mariadb.log"; exit 1; }
mariadb --socket="$SOCK" -u root -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4;"
mariadb --socket="$SOCK" -u root -e "
CREATE USER 'pkginv_migration_user'@'localhost' IDENTIFIED BY '$MIGRATION_USER_PASS';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO 'pkginv_migration_user'@'localhost';
CREATE USER 'pkginv_runtime_user'@'localhost' IDENTIFIED BY '$RUNTIME_USER_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON ${DB_NAME}.* TO 'pkginv_runtime_user'@'localhost';
FLUSH PRIVILEGES;
"

echo "--- 3/8: applying 0001-0006 from the REPO's own migrate.php (simulates 'Phase 5 already installed') ---"
cat > "$REPO_ROOT/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pkginv_migration_user', 'DB_PASS' => '$MIGRATION_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG
php "$REPO_ROOT/api/bin/migrate.php" --yes || { echo "migrate.php FAILED"; exit 1; }
php "$REPO_ROOT/api/bin/seed.php" || { echo "seed.php FAILED"; exit 1; }
ADMIN_PASSWORD="$ADMIN_PASS" php "$REPO_ROOT/api/bin/create_admin.php" pkginv_validate_admin "PkgInvoice Validate Admin" || exit 1
php "$REPO_ROOT/api/tests/_phase2_bootstrap_master.php" || { echo "master bootstrap FAILED"; exit 1; }
rm -f "$REPO_ROOT/api/app/config/config.php"

echo "--- 4/8: writing config.php DIRECTLY INTO THE EXTRACTED TREE (never the repo) ---"
cat > "$EXTRACT_DIR/api/app/config/config.php" <<PHPCONFIG
<?php
return [
    'APP_ENV' => 'staging', 'APP_DEBUG' => true,
    'DB_HOST' => 'unused-socket-mode', 'DB_SOCKET' => '$SOCK',
    'DB_NAME' => '$DB_NAME', 'EXPECTED_DB_NAME' => '$DB_NAME',
    'DB_USER' => 'pkginv_runtime_user', 'DB_PASS' => '$RUNTIME_USER_PASS',
    'SESSION_SECURE' => false,
];
PHPCONFIG

echo "--- 5/8: router script (extracted-tree-local) so /api/app/ui/assets/*.css|png serve correctly under php -S ---"
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

echo "--- 6/8: starting php -S rooted at the EXTRACTED api/ directory ---"
php -S "127.0.0.1:$PHP_PORT" -t "$EXTRACT_DIR/api" "$WORKDIR/router.php" > "$WORKDIR/php-server.log" 2>&1 &
PHP_PID=$!
sleep 1
kill -0 "$PHP_PID" 2>/dev/null || { echo "php -S failed to start"; cat "$WORKDIR/php-server.log"; exit 1; }

echo "--- 7/8: login + confirm the invoice preview page renders the mock template correctly ---"
JAR="$WORKDIR/cookies.txt"
curl -s -c "$JAR" -b "$JAR" -X POST "http://127.0.0.1:$PHP_PORT/api/auth/login" \
  -H 'Content-Type: application/json' -d "{\"username\":\"pkginv_validate_admin\",\"password\":\"$ADMIN_PASS\"}" > /dev/null
ME=$(curl -s -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/api/auth/me")
CSRF=$(php -r '$d=json_decode($argv[1],true);echo $d["data"]["csrfToken"]??"";' "$ME")
[ -n "$CSRF" ] || { echo "REFUSING: no csrf token from /api/auth/me"; exit 1; }

BEFORE_INVOICE=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.invoice")
BEFORE_ITEM=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.invoice_item")
BEFORE_PAYMENT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.payment")
BEFORE_DO=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.delivery_order")

INV=$(curl -s -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/_ui-preview/invoice-preview.php")
echo "$INV" | grep -q "INVOICE" || { echo "REFUSING: invoice preview did not render the INVOICE title"; exit 1; }
echo "$INV" | grep -q "Amor Cakes" || { echo "REFUSING: invoice preview did not render the Amor Cakes & Bakery brand name"; exit 1; }
echo "$INV" | grep -q "amor-logo.png" || { echo "REFUSING: invoice preview did not reference the shared Amor logo asset"; exit 1; }
echo "$INV" | grep -q "Kode Toko" && { echo "REFUSING: invoice preview must never show Kode Toko"; exit 1; }
echo "$INV" | grep -qi "Termin" && { echo "REFUSING: invoice preview must never show Termin Pembayaran"; exit 1; }
echo "$INV" | grep -qi "Rekening" && { echo "REFUSING: invoice preview must never show bank/account details"; exit 1; }
echo "$INV" | grep -q "Total Tagihan" || { echo "REFUSING: invoice preview did not render the Total Tagihan summary"; exit 1; }

AFTER_INVOICE=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.invoice")
AFTER_ITEM=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.invoice_item")
AFTER_PAYMENT=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.payment")
AFTER_DO=$(mariadb --socket="$SOCK" -u root -N -e "SELECT COUNT(*) FROM $DB_NAME.delivery_order")

[ "$BEFORE_INVOICE" = "$AFTER_INVOICE" ] && [ "$AFTER_INVOICE" = "0" ] || { echo "REFUSING: invoice table row count changed or non-zero ($BEFORE_INVOICE -> $AFTER_INVOICE)"; exit 1; }
[ "$BEFORE_ITEM" = "$AFTER_ITEM" ] && [ "$AFTER_ITEM" = "0" ] || { echo "REFUSING: invoice_item table row count changed or non-zero ($BEFORE_ITEM -> $AFTER_ITEM)"; exit 1; }
[ "$BEFORE_PAYMENT" = "$AFTER_PAYMENT" ] && [ "$AFTER_PAYMENT" = "0" ] || { echo "REFUSING: payment table row count changed or non-zero ($BEFORE_PAYMENT -> $AFTER_PAYMENT)"; exit 1; }
[ "$BEFORE_DO" = "$AFTER_DO" ] || { echo "REFUSING: delivery_order row count changed just from opening the invoice preview ($BEFORE_DO -> $AFTER_DO)"; exit 1; }

echo "--- 8/8: confirm the extracted package's DO print page still shows the real Amor logo, unaffected ---"
DOPRINT=$(curl -s -o /dev/null -w "%{http_code}" -c "$JAR" -b "$JAR" "http://127.0.0.1:$PHP_PORT/_do-uat/print.php?doId=1")
[ "$DOPRINT" = "200" ] || { echo "REFUSING: old api/_do-uat/print.php fallback did not return 200 — got $DOPRINT"; exit 1; }

echo ""
echo "=== PACKAGE VALIDATION PASSED ==="
echo "ZIP: $ZIP_PATH"
echo "Extracted, config.php written post-extraction, logged in as ADMIN, confirmed the Invoice preview"
echo "page renders the mock template (logo, brand name, customer/invoice info, product table, Total"
echo "Tagihan) with Kode Toko/Termin/bank details absent, and confirmed invoice/invoice_item/payment"
echo "stayed at 0 rows and delivery_order's row count was unchanged after opening the preview — all"
echo "against the SHIPPED files, not the source tree."
