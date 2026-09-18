#!/usr/bin/env bash
# Builds dist/amor-factory-ui-print-do-preview.zip — the redesigned Print
# DO / Surat Jalan package. This is the SAME underlying UI preview package
# as dist/amor-factory-ui-redesign-preview.zip (the print pages live
# inside api/_ui-preview/ and depend on the shared api/app/ui/ layer, so
# they can't be split out on their own) — this script just packages it
# under the name this specific task asked for, with docs focused on what
# changed in THIS pass: the print/bulk-print redesign.
#
# For an EXISTING real deployment where Phase 5 (Draft DO / staged
# Shipment) is already installed:
#   - api/ lives at public_html/factory/api/ (domain docroot is
#     public_html/factory/, not public_html/ itself),
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001-0006 are already applied — this package adds NO new
#     migration and changes NO existing table (the one read-only DTO
#     addition — delivery_order.catatan now passed through
#     DoService::getDo() — is a code-only change, not a schema change),
#   - api/_import-po/, api/_production-uat/, api/_fg-uat/, api/_do-uat/
#     (every prior phase's own temporary wizard, INCLUDING the old
#     api/_do-uat/print.php fallback print route) are ALL preserved and
#     refreshed in place, unchanged — this package deletes nothing.
#
# INCREMENTAL, not a fresh install: extract this ZIP positioned INSIDE
# public_html/factory/ so it merges into the existing public_html/factory/api/
# — it overwrites code files but NEVER includes api/app/config/config.php,
# so the operator's existing DB credentials are untouched.
#
# What changed in THIS pass:
#   - api/app/ui/print-template.php (new) — the ONE shared render function
#     both print-do.php and print-do-bulk.php now call, so single and
#     bulk print can never drift apart.
#   - api/assets/css/print.css (redesigned) — dark on-screen
#     backdrop behind a centered, shadowed white A4 "sheet" (matches the
#     reference mockup); @media print strips the dark backdrop/shadow/
#     toolbar so the ACTUAL printed page is always plain white — dark
#     mode never produces a dark printed document.
#   - api/_ui-preview/print-do.php / print-do-bulk.php (redesigned) —
#     "Amorcakes & Bakery" header branding, No/Produk/Divisi/Qty Rencana/
#     Sudah Dikirim/Sisa table, simple DRAFT/PREPRINT/DIBATALKAN
#     watermarks (a fully-shipped DO prints with no watermark — a real,
#     final document — but its header status text reads TERKIRIM),
#     Catatan section, three-box signature area, footer with print
#     timestamp/printed-by/page-of-page. Still 100% read-only: opening or
#     printing a DO never bumps its version, never touches stock_ledger,
#     never creates a shipment.
#   - api/app/src/Delivery/DoService.php — one added, read-only DTO field
#     (catatan, already-existing delivery_order.catatan column, never
#     written by any API) so the print template can show it without a
#     raw query bypassing the service layer. No validation, write path,
#     or business rule changed.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-print-do-preview"
ZIP_PATH="$DIST_DIR/amor-factory-ui-print-do-preview.zip"

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

echo "--- copying every existing tool, unchanged, for a clean overwrite (nothing deleted, includes the old print.php fallback) ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat; do
  mkdir -p "$STAGE/api/$tool"
  cp -r "$REPO_ROOT/api/$tool/." "$STAGE/api/$tool/"
done

echo "--- copying the UI preview, including the redesigned print pages (api/_ui-preview/) ---"
mkdir -p "$STAGE/api/_ui-preview"
cp -r "$REPO_ROOT/api/_ui-preview/." "$STAGE/api/_ui-preview/"
[ -f "$STAGE/api/_ui-preview/print-do.php" ] || { echo "REFUSING TO BUILD: print-do.php missing"; exit 1; }
[ -f "$STAGE/api/_ui-preview/print-do-bulk.php" ] || { echo "REFUSING TO BUILD: print-do-bulk.php missing"; exit 1; }

echo "--- copying application (source/config-example/migrations, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
cp -r "$REPO_ROOT/api/app/ui" "$STAGE/api/app/ui"
[ -f "$STAGE/api/app/ui/print-template.php" ] || { echo "REFUSING TO BUILD: shared print-template.php missing"; exit 1; }
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
# Amor Factory System — Print DO / Surat Jalan Redesign Preview Package

INCREMENTAL update for an existing Phase 5 (Draft DO / Shipment)
deployment. This domain's document root is public_html/factory/ —
extract this ZIP positioned INSIDE public_html/factory/ so it merges into
your existing public_html/factory/api/. It overwrites code files but
NEVER includes app/config/config.php, so your database credentials are
untouched.

**Start here**: dist/README-FIRST-CPANEL-PRINT-DO-REDESIGN.md (delivered
alongside this ZIP) has the full step-by-step, non-technical guide. No
SQL. No CLI. No migration to apply — this package changes no schema.

Quick facts:
- Log in as ADMIN: api/_admin-login/ (unchanged)
- New/redesigned print pages: api/_ui-preview/print-do.php (single DO)
  and api/_ui-preview/print-do-bulk.php (all DOs for a date+factory).
- The OLD print route, api/_do-uat/print.php, is untouched and still
  works as a fallback.
- Opening or printing a DO is READ-ONLY — it never changes DO status,
  version, or stock. Only the real Preprint/Ship actions (unchanged from
  Phase 5) do that.
- No PO/Production/FG/DO/Shipment business logic was changed, weakened,
  or duplicated by this package.
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

echo "--- sanity: confirm the old print.php fallback route specifically is present ---"
if [ ! -f "$STAGE/api/_do-uat/print.php" ]; then
  echo "REFUSING TO BUILD: api/_do-uat/print.php (the old print fallback) is missing." >&2
  exit 1
fi

echo "--- sanity: confirm no migration 0007+ was accidentally introduced (this package changes no schema) ---"
if find "$STAGE/api/app/migrations" -name '0007_*' -o -name '0008_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected new migration was found — the print redesign must not change schema." >&2
  exit 1
fi

echo "--- sanity: confirm no Invoice/Payment/Return/Reject functionality was added (out of scope) ---"
if find "$STAGE" -iname '*invoice*' -o -iname '*payment*' -o -iname '*receivable*' -o -iname '*retur*' -o -iname '*reject*' | grep -q .; then
  echo "REFUSING TO BUILD: Invoice/Payment/Receivable/Retur/Reject-named files found — out of scope for this package." >&2
  exit 1
fi

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
