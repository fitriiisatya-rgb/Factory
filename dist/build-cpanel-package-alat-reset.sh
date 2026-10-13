#!/usr/bin/env bash
# Builds dist/amor-factory-alat-reset.zip — ONLY api/_reset-transaksi/ (the
# one-time "Reset Data Transaksi" tool). Shipped separately on purpose: it
# must never sit on the server longer than needed (it deletes itself after a
# successful run). Reads the repo, writes dist/ only.
set -euo pipefail
DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-alat-reset"
ZIP_PATH="$DIST_DIR/amor-factory-alat-reset.zip"
F="$STAGE/api/_reset-transaksi/index.php"
rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api/_reset-transaksi"
cp "$REPO_ROOT/api/_reset-transaksi/index.php" "$F"
php -l "$F" > /dev/null
grep -q "requireRole('ADMIN')" "$F" || { echo "REFUSING TO BUILD: ADMIN check missing"; exit 1; }
grep -q "password_verify" "$F" || { echo "REFUSING TO BUILD: password re-check missing"; exit 1; }
grep -q "gzopen" "$F" || { echo "REFUSING TO BUILD: automatic backup missing"; exit 1; }
for keep in product store users store_portal_token division factory user_roles; do
  if sed -n '/const TRANSACTION_TABLES/,/];/p' "$F" | grep -q "'${keep}'"; then
    echo "REFUSING TO BUILD: master table ${keep} is in the delete list"; exit 1
  fi
done
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )
rm -rf "$STAGE"
ls -la "$ZIP_PATH"
