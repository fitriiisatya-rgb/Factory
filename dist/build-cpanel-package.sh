#!/usr/bin/env bash
# Builds dist/amor-factory-api-preprod.zip — the cPanel easy-install package
# for Amor Factory's Phase 0.5 PHP/MySQL preproduction skeleton.
#
# Run from anywhere; paths are resolved relative to this script's location.
# Output: dist/amor-factory-api-preprod.zip, ready to upload to cPanel and
# extract directly into public_html/ (see dist/README-FIRST-CPANEL.md).
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage"
ZIP_PATH="$DIST_DIR/amor-factory-api-preprod.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- copying setup wizard ---"
mkdir -p "$STAGE/api/_setup"
cp "$REPO_ROOT/api/_setup/index.php" "$STAGE/api/_setup/index.php"
cp "$REPO_ROOT/api/_setup/.htaccess" "$STAGE/api/_setup/.htaccess"
cp "$REPO_ROOT/api/_setup/README.md" "$STAGE/api/_setup/README.md"

echo "--- copying application (source/config/migrations) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- copying canonical schema DDL (co-located copy for this package only) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory — PHP/MySQL Preproduction Package

This is the Phase 0.5 easy-install package: infrastructure only (no
business modules, no legacy data import, no frontend cutover).

**Start here**: `dist/README-FIRST-CPANEL.md` (delivered alongside the ZIP
you extracted this from) has the full step-by-step, non-technical guide.

Quick facts:
- Setup wizard: `_setup/index.php` (delete this whole folder once done)
- Private config: `app/config/config.php` (you create this — see the guide)
- This file, `_setup/`, and `app/` are NOT the live application by
  themselves — they only install and verify the database. No frontend
  code lives here and none is touched by anything in this package.
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
echo "Files in package:"
unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | awk '{print $4}' | sort
echo "Total files: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
