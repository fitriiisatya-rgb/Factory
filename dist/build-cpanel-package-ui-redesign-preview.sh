#!/usr/bin/env bash
# Builds dist/amor-factory-ui-redesign-preview.zip — the UI/UX redesign
# preview package, for an EXISTING real deployment where Phase 5 (Draft
# DO / staged Shipment) is already installed:
#   - api/ lives at public_html/factory/api/ (domain docroot is
#     public_html/factory/, not public_html/ itself),
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001-0006 are already applied — this package adds NO new
#     migration, because the entire redesign is a presentation layer on
#     top of the existing Phase 1-5 APIs; DashboardService is read-only
#     and needs no schema change,
#   - api/_import-po/, api/_production-uat/, api/_fg-uat/, api/_do-uat/
#     (every prior phase's own temporary wizard) are ALL preserved and
#     refreshed in place, unchanged — this package deletes nothing.
#
# INCREMENTAL, not a fresh install: extract this ZIP positioned INSIDE
# public_html/factory/ so it merges into the existing public_html/factory/api/
# — it overwrites code files but NEVER includes api/app/config/config.php,
# so the operator's existing DB credentials are untouched. The existing
# public_html/factory/api/index.php (the JSON API's own front controller)
# is refreshed as-is (its routing gained one new read-only GET
# /api/dashboard/summary endpoint) but is NOT repointed at the new UI —
# the new UI lives entirely at its own path, api/_ui-preview/, so the
# live JSON API and every existing tool keep working exactly as before.
#
# New in this package:
#   - api/_ui-preview/ (new) — the redesigned dark-mode admin UI:
#     Dashboard, Pesanan Toko, Produksi, FG & Packing, Delivery Order (+
#     detail), Pengiriman, Master Data, Laporan, Pengaturan, plus
#     redesigned A4 DO print/bulk-print pages. Every mutating action goes
#     through the SAME real JSON API the old UAT wizards already call —
#     no business logic is duplicated or reimplemented.
#   - api/app/ui/ (new) — shared design tokens/CSS, layout shell,
#     component helpers, label maps, and the vanilla-JS toolkit
#     (assets/js/app.js) the new pages use for fetch()-based actions,
#     toasts, and confirmation modals.
#   - api/app/src/Dashboard/DashboardService.php (new) + Controllers/
#     DashboardController.php (new) + one new App.php route (GET
#     /api/dashboard/summary) — READ-ONLY aggregation, never writes
#     transactional data, safe to remove without affecting any document.
#
# This ZIP changes NO business logic in Production/FG/DO/Shipment, adds
# NO new migration, and does not touch api/index.php's own routing
# destination — see docs/mysql-do-shipment-phase5-reservation-v1.md and
# every prior phase's own schema, both completely untouched by this pass.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-ui-redesign-preview"
ZIP_PATH="$DIST_DIR/amor-factory-ui-redesign-preview.zip"

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

echo "--- copying the new UI preview (api/_ui-preview/) ---"
mkdir -p "$STAGE/api/_ui-preview"
cp -r "$REPO_ROOT/api/_ui-preview/." "$STAGE/api/_ui-preview/"

echo "--- copying application (source/config-example/migrations, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
cp -r "$REPO_ROOT/api/app/ui" "$STAGE/api/app/ui"
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
# Amor Factory System — UI/UX Redesign Preview Package

INCREMENTAL update for an existing Phase 5 (Draft DO / Shipment)
deployment. This domain's document root is public_html/factory/ —
extract this ZIP positioned INSIDE public_html/factory/ so it merges into
your existing public_html/factory/api/. It overwrites code files but
NEVER includes app/config/config.php, so your database credentials are
untouched.

**Start here**: dist/README-FIRST-CPANEL-UI-REDESIGN.md (delivered
alongside this ZIP) has the full step-by-step, non-technical guide. No
SQL. No CLI. No migration to apply — this package changes no schema.

Quick facts:
- Log in as ADMIN: api/_admin-login/ (unchanged)
- New UI: api/_ui-preview/ (requires login; every old UAT wizard is
  UNCHANGED and still reachable as a fallback: api/_import-po/,
  api/_production-uat/, api/_fg-uat/, api/_do-uat/)
- The new UI calls the SAME JSON API the old UAT wizards already call —
  no PO/Production/FG/DO/Shipment business logic was changed, weakened,
  or duplicated by this package.
- No new migration — this package adds one read-only endpoint (GET
  /api/dashboard/summary) and changes no table.
- The live JSON API's own front controller (api/index.php) is refreshed
  but NOT repointed anywhere — every existing integration keeps working.
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
  echo "REFUSING TO BUILD: an unexpected new migration was found — the UI redesign must not change schema." >&2
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
