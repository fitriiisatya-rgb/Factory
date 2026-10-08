#!/usr/bin/env bash
# Builds dist/amor-factory-invoice.zip — real Invoice generation from
# actual DO/Shipment/Mutasi data (migration 0018), plus the Laporan
# Omset/Penjualan and Retur & Reject report sections.
#
# Migration 0018 is purely additive on top of 0001-0017 (all untouched):
# ONE new table (invoice_mutasi) — the pre-existing Phase 1 placeholder
# tables (invoice/invoice_item/invoice_shipment/payment) are wired up
# for real, as-is, with zero ALTER.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-invoice"
ZIP_PATH="$DIST_DIR/amor-factory-invoice.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points (index.php + .htaccess, unchanged) ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- copying every existing tool, unchanged/updated, for a clean overwrite (nothing dropped) ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview _driver-uat _receive _users-uat _store; do
  mkdir -p "$STAGE/api/$tool"
  cp -r "$REPO_ROOT/api/$tool/." "$STAGE/api/$tool/"
done

echo "--- copying application (source/config-example/migrations/ui, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
cp -r "$REPO_ROOT/api/app/ui" "$STAGE/api/app/ui"
if [ -d "$STAGE/api/app/ui/assets" ]; then
  echo "REFUSING TO BUILD: api/app/ui/assets/ exists — static browser assets must live under the PUBLIC api/assets/, never inside the deny-all api/app/ tree." >&2
  exit 1
fi
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, refreshed) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: confirm migration 0018 is present, and 0001-0017 are COMPLETELY untouched byte-for-byte ---"
[ -f "$STAGE/api/app/migrations/0018_invoice.php" ] || { echo "REFUSING TO BUILD: migration 0018 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0019_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0019+ was found — this phase's own scope is 0018 only." >&2
  exit 1
fi
for m in 0001 0002 0003 0004 0005 0006 0007 0008 0009 0010 0011 0012 0013 0014 0015 0016 0017; do
  f="$(find "$REPO_ROOT/api/app/migrations" -name "${m}_*" | head -1)"
  [ -n "$f" ] || { echo "REFUSING TO BUILD: migration $m is missing from the repo"; exit 1; }
  s="$STAGE/api/app/migrations/$(basename "$f")"
  [ -f "$s" ] || { echo "REFUSING TO BUILD: migration $m is missing from the staged package"; exit 1; }
  if ! diff -q "$f" "$s" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: migration $m differs from the repo — migrations 0001-0017 must stay byte-for-byte untouched." >&2
    exit 1
  fi
done

echo "--- sanity: confirm migration 0018 stays additive (no DROP TABLE / DROP COLUMN / TRUNCATE / ALTER TABLE, one new table only) ---"
if grep -qEi "DROP TABLE|DROP COLUMN|TRUNCATE" "$REPO_ROOT/database/schema-v1-0018-invoice.sql"; then
  echo "REFUSING TO BUILD: migration 0018 contains a destructive statement." >&2
  exit 1
fi
if grep -qEi "^ALTER TABLE" "$REPO_ROOT/database/schema-v1-0018-invoice.sql"; then
  echo "REFUSING TO BUILD: migration 0018 alters an existing table — this phase is exactly one brand-new table (invoice_mutasi), never a modification to the pre-existing invoice/invoice_item/invoice_shipment/payment tables from migration 0001." >&2
  exit 1
fi
grep -q "CREATE TABLE IF NOT EXISTS invoice_mutasi " "$REPO_ROOT/database/schema-v1-0018-invoice.sql" || { echo "REFUSING TO BUILD: table invoice_mutasi is missing from migration 0018."; exit 1; }

echo "--- sanity: confirm the Phase 1 placeholder tables (invoice/invoice_item/invoice_shipment/payment) are NOT touched by migration 0018's own DDL ---"
if grep -qE "^CREATE TABLE (IF NOT EXISTS )?(invoice|invoice_item|invoice_shipment|payment)\b" "$REPO_ROOT/database/schema-v1-0018-invoice.sql"; then
  echo "REFUSING TO BUILD: migration 0018 re-declares a Phase 1 table it must only read/write via PHP, never re-CREATE." >&2
  exit 1
fi

echo "--- sanity: confirm the core Invoice business logic is present in source ---"
grep -q "class InvoiceService" "$STAGE/api/app/src/Invoice/InvoiceService.php" || { echo "REFUSING TO BUILD: InvoiceService.php is missing."; exit 1; }
grep -q "class InvoiceRepository" "$STAGE/api/app/src/Invoice/InvoiceRepository.php" || { echo "REFUSING TO BUILD: InvoiceRepository.php is missing."; exit 1; }
grep -q "class InvoiceController" "$STAGE/api/app/src/Controllers/InvoiceController.php" || { echo "REFUSING TO BUILD: InvoiceController.php is missing."; exit 1; }
grep -q "EMPTY_INVOICE" "$STAGE/api/app/src/Invoice/InvoiceService.php" || { echo "REFUSING TO BUILD: InvoiceService no longer guards against generating an invoice with nothing eligible."; exit 1; }
grep -q "received_good_qty" "$STAGE/api/app/src/Invoice/InvoiceRepository.php" || { echo "REFUSING TO BUILD: InvoiceRepository no longer bases billable qty on received_good_qty (Reject must already be excluded by construction)."; exit 1; }
grep -qE "confirmed_ok.*verified|'confirmed_ok', 'verified'" "$STAGE/api/app/src/Invoice/InvoiceRepository.php" || { echo "REFUSING TO BUILD: InvoiceRepository no longer restricts eligibility to confirmed_ok/verified receipts."; exit 1; }
grep -q "invoice_mutasi" "$STAGE/api/app/src/Invoice/InvoiceRepository.php" || { echo "REFUSING TO BUILD: InvoiceRepository no longer consumes/links invoice_mutasi."; exit 1; }
if grep -qE "Database::transaction" "$STAGE/api/app/src/Invoice/InvoiceService.php"; then
  echo "REFUSING TO BUILD: InvoiceService must never self-wrap in Database::transaction() — Idempotency::handle() in the controller already provides the transaction; a nested one is a real bug this phase fixed." >&2
  exit 1
fi
grep -q "function (PDO \$pdo) use" "$STAGE/api/app/src/Controllers/InvoiceController.php" || { echo "REFUSING TO BUILD: InvoiceController's Idempotency::handle() closures must take PDO \$pdo (the transactional connection), not construct their own."; exit 1; }
grep -q "'envelope' =>" "$STAGE/api/app/src/Controllers/InvoiceController.php" || { echo "REFUSING TO BUILD: InvoiceController's Idempotency::handle() closures must return an 'envelope' key, not 'body'."; exit 1; }

echo "--- sanity: confirm the real /api/invoices routes are registered (ADMIN-gated) ---"
grep -q "'/api/invoices'" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: /api/invoices route is missing from App.php."; exit 1; }
grep -q "'/api/invoices/preview'" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: /api/invoices/preview route is missing from App.php."; exit 1; }
grep -q "'/api/invoices/{id}/void'" "$STAGE/api/app/src/App.php" || { echo "REFUSING TO BUILD: /api/invoices/{id}/void route is missing from App.php."; exit 1; }

echo "--- sanity: confirm the Admin Invoice UI is present and reachable from the sidebar ---"
[ -f "$STAGE/api/app/ui/pages/invoice.php" ] || { echo "REFUSING TO BUILD: Admin Invoice list/generate page is missing."; exit 1; }
[ -f "$STAGE/api/app/ui/pages/invoice-detail.php" ] || { echo "REFUSING TO BUILD: Admin Invoice detail page is missing."; exit 1; }
[ -f "$STAGE/api/_ui-preview/print-invoice.php" ] || { echo "REFUSING TO BUILD: the REAL (non-mock) Invoice print page is missing."; exit 1; }
grep -q "'key' => 'invoice'" "$STAGE/api/app/ui/layout.php" || { echo "REFUSING TO BUILD: the Admin sidebar no longer links to Invoice."; exit 1; }

echo "--- sanity: confirm the EXISTING mock Invoice preview (Phase pre-0018) stays completely untouched — it is kept deliberately, not retired, to protect its own regression suite ---"
if ! diff -q "$REPO_ROOT/api/_ui-preview/invoice-preview.php" "$STAGE/api/_ui-preview/invoice-preview.php" > /dev/null 2>&1; then
  echo "REFUSING TO BUILD: api/_ui-preview/invoice-preview.php (the mock fixture preview) differs from the repo — it must stay byte-for-byte untouched." >&2
  exit 1
fi
if ! diff -q "$REPO_ROOT/api/app/ui/fixtures/invoice-mock.php" "$STAGE/api/app/ui/fixtures/invoice-mock.php" > /dev/null 2>&1; then
  echo "REFUSING TO BUILD: api/app/ui/fixtures/invoice-mock.php differs from the repo — it must stay byte-for-byte untouched." >&2
  exit 1
fi

echo "--- sanity: confirm Laporan's new Omset and Retur & Reject sections are present and still clearly read-only ---"
grep -q "Laporan Omset" "$STAGE/api/app/ui/pages/laporan.php" || { echo "REFUSING TO BUILD: Laporan Omset/Penjualan section is missing."; exit 1; }
grep -q "Laporan Retur" "$STAGE/api/app/ui/pages/laporan.php" || { echo "REFUSING TO BUILD: Laporan Retur & Reject section is missing."; exit 1; }
if grep -qiE "INSERT INTO|UPDATE |DELETE FROM" "$STAGE/api/app/ui/pages/laporan.php"; then
  echo "REFUSING TO BUILD: laporan.php must stay a pure read-only report page — found a write statement." >&2
  exit 1
fi

echo "--- sanity: confirm NO business rule / server-side validation file OUTSIDE this phase's own scope changed byte-for-byte ---"
for f in api/app/src/Delivery/ShipmentService.php api/app/src/Delivery/DoService.php \
         api/app/src/Delivery/DoRepository.php api/app/src/Controllers/DoController.php \
         api/app/src/Dispatch/DispatchService.php api/app/src/Dispatch/ReceiptService.php \
         api/app/src/Dispatch/ReceiptRepository.php api/app/src/Controllers/ReceiptController.php \
         api/app/src/StorePortal/StorePortalService.php api/app/src/StorePortal/ReturService.php \
         api/app/src/StorePortal/MutasiService.php api/app/src/StorePortal/MutasiRepository.php \
         api/app/src/Controllers/StorePortalController.php api/app/src/Controllers/StorePortalAdminController.php \
         api/app/src/Import/PoImporter.php api/app/src/Production/ProductionService.php \
         api/app/src/Fg/FgService.php api/app/src/Fg/FgRepository.php api/app/src/Users/UserService.php \
         api/app/src/Mail/ShipmentEmailService.php api/app/src/SpecialOrder/SpecialOrderDoService.php \
         api/app/src/Services/DocumentSequenceService.php api/app/src/Idempotency.php \
         api/app/ui/pages/produksi.php api/app/ui/pages/pengiriman.php api/app/ui/pages/konfirmasi-toko.php \
         api/app/ui/pages/delivery-order.php api/app/ui/pages/delivery-order-detail.php \
         api/app/ui/print-invoice-template.php api/assets/css/print-invoice.css; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this phase." >&2
    exit 1
  fi
done
# App.php/layout.php/laporan.php ARE expected to differ (this phase's
# own core deliverable: new /api/invoices/* routes, the Invoice nav
# item, and the two new Laporan sections) — checked for the specific
# required additions above instead of byte-diffed.

echo "--- copying canonical schema DDL (0001-0018) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql schema-v1-0013-repair-production-flow-completion.sql \
         schema-v1-0014-production-fg-division-rework.sql schema-v1-0015-fg-store-packing-submission.sql \
         schema-v1-0016-replacement-reject.sql schema-v1-0017-permanent-bakery-portal.sql \
         schema-v1-0018-invoice.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Invoice (migration 0018)

Purely additive on top of 0001-0017 — ONE brand-new table
(invoice_mutasi). The pre-existing Phase 1 placeholder tables
(invoice, invoice_item, invoice_shipment, payment — schema-v1.sql,
migration 0001, previously unused by any live code) are now wired up
for real, as-is, with zero ALTER.

Invoice is auto-calculated (never manually typed) from real
Shipment/Mutasi data:
  - Billable qty per product = confirmed-received qty across every
    eligible Shipment in the chosen store+date-range window
    (received_good_qty already excludes a verified Reject by
    construction — no separate Reject-subtraction logic needed), net
    of completed Mutasi movement (a completed Mutasi moves its
    qty_received fully from the SOURCE store's billable pool to the
    DESTINATION store's, never double-billed on either side).
  - Only a CONFIRMED receipt (confirmed_ok or Admin-verified) and only
    a COMPLETED Mutasi are ever eligible — a still-open discrepancy is
    excluded until Admin resolves it.
  - ONE Invoice can bundle MANY Shipments for one store across a
    chosen date range.
  - Void is a hard delete (cascades to invoice_item/invoice_shipment/
    invoice_mutasi), releasing every shipment/mutasi it had consumed
    back into the eligible pool — safe because the `payment` table
    remains completely out of this phase's scope.
  - rate_pct/rate_source/override_reason exist on the underlying Phase 1
    tables but are explicitly NOT used by this phase (confirmed with
    the user) — every row carries rate_pct=100.00 (full price, no
    adjustment), a placeholder for a future consignment/discount
    feature.

Admin UI: Invoice (sidebar) — generate/preview per store+period, list,
detail, print (reuses the existing print-invoice-template.php design
unchanged — the pre-existing mock preview at
api/_ui-preview/invoice-preview.php is kept, untouched, for its own
regression suite), void.

Laporan gained two new period-based sections: Omset/Penjualan per
Toko & Periode (from real Invoice totals) and Retur & Reject per
Periode (from retur_request and shipment_receipt_item) — each with its
own date-range filter, independent of the page's existing single-day
operational-funnel filter. Laporan stays 100% read-only.
EOF

find "$STAGE" -name '.DS_Store' -delete 2>/dev/null || true
find "$STAGE" -name 'Thumbs.db' -delete 2>/dev/null || true

echo "--- sanity: confirm no config.php (real credentials) made it in ---"
if find "$STAGE" -name 'config.php' | grep -q .; then
  echo "REFUSING TO BUILD: a config.php was found in the staging tree — this must never ship." >&2
  find "$STAGE" -name 'config.php' >&2
  exit 1
fi

echo "--- sanity: confirm every prior UAT tool + UI preview are present ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview _driver-uat _receive _users-uat _store; do
  if [ ! -f "$STAGE/api/$tool/index.php" ]; then
    echo "REFUSING TO BUILD: api/$tool/index.php is missing — old tools must never be dropped by this package." >&2
    exit 1
  fi
done

echo "--- sanity: confirm api/app/.htaccess is STILL deny-all (the security boundary this patch must never weaken) ---"
if ! grep -q 'Require all denied' "$STAGE/api/app/.htaccess"; then
  echo "REFUSING TO BUILD: api/app/.htaccess no longer denies all HTTP access — this must never be weakened." >&2
  exit 1
fi

echo "--- sanity: confirm NO browser-facing PHP file references the deny-all api/app/ or api/uploads/ paths ---"
if grep -rl 'href="/api/app/\|src="/api/app/\|href="/api/uploads/\|src="/api/uploads/' "$STAGE/api" --include='*.php' | grep -q .; then
  echo "REFUSING TO BUILD: a browser-facing href/src points directly inside a deny-all tree." >&2
  grep -rln 'href="/api/app/\|src="/api/app/\|href="/api/uploads/\|src="/api/uploads/' "$STAGE/api" --include='*.php' >&2
  exit 1
fi

echo "--- sanity: confirm every existing evidence/attachment upload directory still ships deny-all and clean (no stray test photos) ---"
for dir in receipt-evidence retur-evidence mutasi-evidence special-order-attachment; do
  mkdir -p "$STAGE/api/uploads/$dir"
  cp "$REPO_ROOT/api/uploads/$dir/.htaccess" "$STAGE/api/uploads/$dir/.htaccess"
  if ! grep -q 'Require all denied' "$STAGE/api/uploads/$dir/.htaccess"; then
    echo "REFUSING TO BUILD: api/uploads/$dir/.htaccess must deny all direct HTTP access." >&2
    exit 1
  fi
  if find "$REPO_ROOT/api/uploads/$dir" -name '*.png' -o -name '*.jpg' 2>/dev/null | grep -q .; then
    echo "REFUSING TO BUILD: stray uploaded test evidence found in the repo's own $dir uploads directory — clean it up before building." >&2
    exit 1
  fi
done

echo "--- sanity: php -l every PHP file in the staging tree ---"
find "$STAGE" -name '*.php' -print0 | while IFS= read -r -d '' f; do
  php -l "$f" > /dev/null || { echo "REFUSING TO BUILD: syntax error in $f" >&2; exit 1; }
done

echo "--- sanity: node -c every JS file in the staging tree ---"
find "$STAGE" -name '*.js' -print0 | while IFS= read -r -d '' f; do
  node -c "$f" || { echo "REFUSING TO BUILD: syntax error in $f" >&2; exit 1; }
done

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
