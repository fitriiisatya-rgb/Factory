#!/usr/bin/env bash
# Builds dist/amor-factory-invoice-ui-preview.zip — the Invoice print
# template / preview package. This is the SAME underlying UI preview
# package as dist/amor-factory-ui-redesign-preview.zip and
# dist/amor-factory-ui-print-do-preview.zip (the invoice preview page
# lives inside api/_ui-preview/ and depends on the shared api/app/ui/
# layer, so it can't be split out on its own) — this script just packages
# it under the name this specific task asked for, with docs focused on
# what changed in THIS pass: the Invoice print template/preview.
#
# For an EXISTING real deployment where Phase 5 (Draft DO / staged
# Shipment) is already installed:
#   - api/ lives at public_html/factory/api/ (domain docroot is
#     public_html/factory/, not public_html/ itself),
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001-0006 are already applied — this package adds NO new
#     migration and changes NO existing table. The invoice/invoice_item/
#     invoice_shipment/payment tables already exist (reserved, unused,
#     since the original 0001 migration) — this package does not write to
#     them, read from them, or add a service/controller against them,
#   - api/_import-po/, api/_production-uat/, api/_fg-uat/, api/_do-uat/
#     (every prior phase's own temporary wizard) are ALL preserved and
#     refreshed in place, unchanged — this package deletes nothing.
#
# INCREMENTAL, not a fresh install: extract this ZIP positioned INSIDE
# public_html/factory/ so it merges into the existing public_html/factory/api/
# — it overwrites code files but NEVER includes api/app/config/config.php,
# so the operator's existing DB credentials are untouched.
#
# What is new in THIS pass (all UI/print-template only — Phase 6 Invoice
# transaction logic is NOT implemented):
#   - api/app/ui/assets/img/amor-logo.png (new) — the real Amor Group logo
#     asset, now used on BOTH the DO print header and the Invoice header.
#   - api/app/ui/print-invoice-template.php (new) — the ONE shared render
#     function the invoice preview page calls; takes a plain PHP array DTO
#     (invoiceNumber/invoiceDate/customer/reference/items/summary/notes/
#     metadata) and renders it — no DB access, no business logic.
#   - api/app/ui/fixtures/invoice-mock.php (new) — a clearly-marked MOCK
#     fixture (see its own docblock) used ONLY by the preview page below;
#     never touches any database table.
#   - api/app/ui/assets/css/print-invoice.css (new) — cream/white/brown
#     Amor Cakes & Bakery print theme, same dark-screen/white-print split
#     as the DO's print.css (dark mode never produces a dark printed
#     invoice).
#   - api/_ui-preview/invoice-preview.php (new) — the READ-ONLY preview
#     page. Loads only the mock fixture above; writes nothing to the
#     database under any circumstance.
#   - api/app/ui/print-template.php / assets/css/print.css (DO print,
#     updated) — the letter-mark placeholder is now the real Amor logo
#     image, matching the Invoice header.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-invoice-ui-preview"
ZIP_PATH="$DIST_DIR/amor-factory-invoice-ui-preview.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points (index.php + THE FIXED .htaccess) ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- sanity: confirm the shipped .htaccess has the real-directory bypass (-d), not just -f ---"
if ! grep -qE '^\s*RewriteCond %\{REQUEST_FILENAME\} -d\s*$' "$STAGE/api/.htaccess"; then
  echo "REFUSING TO BUILD: api/.htaccess is missing the '-d' (real directory) RewriteCond." >&2
  exit 1
fi
if ! grep -qE '^\s*RewriteCond %\{REQUEST_FILENAME\} -f' "$STAGE/api/.htaccess"; then
  echo "REFUSING TO BUILD: api/.htaccess is missing the '-f' (real file) RewriteCond." >&2
  exit 1
fi

echo "--- copying every existing tool, unchanged, for a clean overwrite (nothing deleted) ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat; do
  mkdir -p "$STAGE/api/$tool"
  cp -r "$REPO_ROOT/api/$tool/." "$STAGE/api/$tool/"
done

echo "--- copying the UI preview, including the invoice preview page (api/_ui-preview/) ---"
mkdir -p "$STAGE/api/_ui-preview"
cp -r "$REPO_ROOT/api/_ui-preview/." "$STAGE/api/_ui-preview/"
[ -f "$STAGE/api/_ui-preview/invoice-preview.php" ] || { echo "REFUSING TO BUILD: invoice-preview.php missing"; exit 1; }
[ -f "$STAGE/api/_ui-preview/print-do.php" ] || { echo "REFUSING TO BUILD: print-do.php missing"; exit 1; }

echo "--- copying application (source/config-example/migrations, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
cp -r "$REPO_ROOT/api/app/ui" "$STAGE/api/app/ui"
[ -f "$STAGE/api/app/ui/print-invoice-template.php" ] || { echo "REFUSING TO BUILD: shared print-invoice-template.php missing"; exit 1; }
[ -f "$STAGE/api/app/ui/fixtures/invoice-mock.php" ] || { echo "REFUSING TO BUILD: invoice-mock.php fixture missing"; exit 1; }
[ -f "$STAGE/api/app/ui/assets/img/amor-logo.png" ] || { echo "REFUSING TO BUILD: amor-logo.png asset missing"; exit 1; }
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- copying canonical schema DDL (0001-0006 — UNCHANGED, no new migration in this package) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"
cp "$REPO_ROOT/database/schema-v1-0003-po-phase2.sql" "$STAGE/api/app/database/schema-v1-0003-po-phase2.sql"
cp "$REPO_ROOT/database/schema-v1-0004-production-phase3.sql" "$STAGE/api/app/database/schema-v1-0004-production-phase3.sql"
cp "$REPO_ROOT/database/schema-v1-0005-fg-packing-phase4.sql" "$STAGE/api/app/database/schema-v1-0005-fg-packing-phase4.sql"
cp "$REPO_ROOT/database/schema-v1-0006-do-shipment-phase5.sql" "$STAGE/api/app/database/schema-v1-0006-do-shipment-phase5.sql"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Invoice UI / Print Template Preview Package

INCREMENTAL update for an existing Phase 5 (Draft DO / Shipment)
deployment. This domain's document root is public_html/factory/ —
extract this ZIP positioned INSIDE public_html/factory/ so it merges into
your existing public_html/factory/api/. It overwrites code files but
NEVER includes app/config/config.php, so your database credentials are
untouched.

**Start here**: dist/README-FIRST-CPANEL-INVOICE-UI.md (delivered
alongside this ZIP) has the full step-by-step, non-technical guide. No
SQL. No CLI. No migration to apply — this package changes no schema.

Quick facts:
- Log in as ADMIN: api/_admin-login/ (unchanged)
- New page: api/_ui-preview/invoice-preview.php — a READ-ONLY preview of
  the redesigned Invoice print template, using built-in MOCK/sample data
  (clearly labeled on screen). It does not read or write any real
  transaction, and it does not depend on any real toko/PO/DO/Shipment
  existing yet.
- Phase 6 (real Invoice creation, payment, receivable tracking) is NOT
  part of this package and has NOT been built. This page exists purely so
  the printed Invoice DESIGN can be reviewed ahead of that work.
- No PO/Production/FG/DO/Shipment business logic was changed, weakened,
  or duplicated by this package. The DO print header now shows the real
  Amor Group logo image (previously a plain letter mark) — everything
  else about DO printing is unchanged from the prior print redesign pass.
EOF

find "$STAGE" -name '.DS_Store' -delete 2>/dev/null || true
find "$STAGE" -name 'Thumbs.db' -delete 2>/dev/null || true

echo "--- sanity: confirm no config.php (real credentials) made it in ---"
if find "$STAGE" -name 'config.php' | grep -q .; then
  echo "REFUSING TO BUILD: a config.php was found in the staging tree — this must never ship." >&2
  find "$STAGE" -name 'config.php' >&2
  exit 1
fi

echo "--- sanity: confirm api/_setup/ and api/_import-master/ (retired wizards) are not reintroduced ---"
if [ -d "$STAGE/api/_setup" ] || [ -d "$STAGE/api/_import-master" ]; then
  echo "REFUSING TO BUILD: a retired wizard directory was found in the staging tree." >&2
  exit 1
fi

echo "--- sanity: confirm every prior UAT tool is present (nothing was accidentally dropped) ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat; do
  if [ ! -f "$STAGE/api/$tool/index.php" ]; then
    echo "REFUSING TO BUILD: api/$tool/index.php is missing — old tools must never be dropped by this package." >&2
    exit 1
  fi
done

echo "--- sanity: confirm no migration 0007+ was accidentally introduced (this package changes no schema) ---"
if find "$STAGE/api/app/migrations" -name '0007_*' -o -name '0008_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected new migration was found — the Invoice UI preview must not change schema." >&2
  exit 1
fi

echo "--- sanity: confirm NO Invoice/Payment SERVICE or CONTROLLER (business logic) was added — UI/template only ---"
if find "$STAGE/api/app/src" -iname '*invoice*' -o -iname '*payment*' -o -iname '*receivable*' | grep -q .; then
  echo "REFUSING TO BUILD: an Invoice/Payment/Receivable file was found under api/app/src (business logic) — this package must remain UI/template only." >&2
  find "$STAGE/api/app/src" -iname '*invoice*' -o -iname '*payment*' -o -iname '*receivable*' >&2
  exit 1
fi

echo "--- sanity: confirm no Retur/Reject functionality was added (out of scope) ---"
if find "$STAGE" -iname '*retur*' -o -iname '*reject*' | grep -q .; then
  echo "REFUSING TO BUILD: Retur/Reject-named files found — out of scope for this package." >&2
  exit 1
fi

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
