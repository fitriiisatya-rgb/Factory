#!/usr/bin/env bash
# SUPERSEDED — see dist/build-cpanel-package-phase1-v2.sh instead. This
# script's ZIP assumed the wrong upload path (public_html/api/); the real
# host's document root for factory.amorgroup.id is public_html/factory/,
# so the real path is public_html/factory/api/. Kept only as a historical
# record of the amor-factory-api-phase1-incremental.zip that was already
# built from it; do not build or ship from this script anymore.
#
# Builds dist/amor-factory-api-phase1-incremental.zip — the Phase 1
# fast-track incremental update package for an EXISTING Phase 0.5
# deployment (api/ already live at public_html/api/ with a working
# app/config/config.php).
#
# INCREMENTAL, not a fresh install: extracting this ZIP over the existing
# public_html/api/ overwrites api/index.php, api/.htaccess, api/app/src/,
# api/app/migrations/, api/app/database/, and adds api/_import-master/ +
# api/_upgrade/ — but NEVER includes api/app/config/config.php (not
# present in this ZIP at all, so extracting cannot overwrite it; the
# operator's existing DB credentials/SETUP_TOKEN keep working unchanged).
# Does not require re-running Phase 0's setup wizard.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-phase1"
ZIP_PATH="$DIST_DIR/amor-factory-api-phase1-incremental.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points (unchanged since Phase 0.5, included for a clean overwrite) ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- copying setup wizard (unchanged; only relevant if the operator kept it) ---"
mkdir -p "$STAGE/api/_setup"
cp "$REPO_ROOT/api/_setup/index.php" "$STAGE/api/_setup/index.php"
cp "$REPO_ROOT/api/_setup/.htaccess" "$STAGE/api/_setup/.htaccess"
cp "$REPO_ROOT/api/_setup/README.md" "$STAGE/api/_setup/README.md"

echo "--- copying Phase 1 import wizard (new) ---"
mkdir -p "$STAGE/api/_import-master"
cp "$REPO_ROOT/api/_import-master/index.php" "$STAGE/api/_import-master/index.php"
cp "$REPO_ROOT/api/_import-master/.htaccess" "$STAGE/api/_import-master/.htaccess"

echo "--- copying standing upgrade runner (new) ---"
mkdir -p "$STAGE/api/_upgrade"
cp "$REPO_ROOT/api/_upgrade/index.php" "$STAGE/api/_upgrade/index.php"
cp "$REPO_ROOT/api/_upgrade/.htaccess" "$STAGE/api/_upgrade/.htaccess"

echo "--- copying application (source/config-example/migrations, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- copying canonical schema DDL (0001 + new 0002) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"

echo "--- copying bundled legacy source extraction (new) ---"
mkdir -p "$STAGE/api/app/database/legacy"
cp "$REPO_ROOT/database/legacy/phase1-source-v1.json" "$STAGE/api/app/database/legacy/phase1-source-v1.json"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory — Phase 1 Fast-Track Incremental Package

INCREMENTAL update for an existing Phase 0.5 deployment. Extract this ZIP
over your existing public_html/api/ — it overwrites code files but never
includes app/config/config.php, so your database credentials and
SETUP_TOKEN are untouched.

**Start here**: dist/README-FIRST-CPANEL-PHASE1.md (delivered alongside
this ZIP) has the full step-by-step, non-technical guide.

Quick facts:
- Apply the new migration: api/_upgrade/ (requires ADMIN login; safe to
  leave in place permanently — see its own header comment)
- Import master/identity data: api/_import-master/ (requires ADMIN login;
  delete this folder once Phase 1 is reviewed and accepted)
- Still NOT imported: PO, production, FG, DO, shipment, stock, invoice,
  payment, returns, sales. Still NOT connected to the live frontend.
EOF

find "$STAGE" -name '.DS_Store' -delete 2>/dev/null || true
find "$STAGE" -name 'Thumbs.db' -delete 2>/dev/null || true

echo "--- sanity: confirm no config.php (real credentials) made it in ---"
if find "$STAGE" -name 'config.php' | grep -q .; then
  echo "REFUSING TO BUILD: a config.php was found in the staging tree — this must never ship." >&2
  find "$STAGE" -name 'config.php' >&2
  exit 1
fi

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
