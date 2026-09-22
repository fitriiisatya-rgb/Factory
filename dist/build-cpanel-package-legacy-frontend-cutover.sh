#!/usr/bin/env bash
# Builds dist/amor-factory-legacy-frontend-cutover.zip — the smallest
# package this project has ever shipped: exactly ONE file, index.php,
# meant to be extracted DIRECTLY INTO public_html/factory/ itself (NOT
# into public_html/factory/api/ like every other package) so it lands as
# public_html/factory/index.php, replacing the old static legacy
# frontend that used to live there.
#
# Per the approved cutover decision: factory.amorgroup.id/ (the bare
# docroot) must serve the current Amor Factory Admin UI directly. This
# new index.php is a pure passthrough to the ALREADY-SHIPPED
# api/_ui-preview/index.php — no new routing, no new business logic, no
# legacy navigation/switch-back link, NO change to anything under api/
# (verified below byte-for-byte), no database migration.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-legacy-frontend-cutover"
ZIP_PATH="$DIST_DIR/amor-factory-legacy-frontend-cutover.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE"

echo "--- copying the ONE file this package ships: index.php ---"
cp "$REPO_ROOT/index.php" "$STAGE/index.php"

echo "--- sanity: confirm index.php is a pure passthrough (no new routing/business logic) ---"
grep -q "require __DIR__ . '/api/_ui-preview/index.php';" "$STAGE/index.php" || { echo "REFUSING TO BUILD: index.php no longer passes through to api/_ui-preview/index.php"; exit 1; }
LINE_COUNT_NO_COMMENTS=$(grep -vE '^\s*(\*|/\*|//|\*/)?\s*$' "$STAGE/index.php" | grep -vE '^\s*\*' | grep -c '.')
if [ "$LINE_COUNT_NO_COMMENTS" -gt 6 ]; then
  echo "REFUSING TO BUILD: index.php has grown beyond a plain passthrough ($LINE_COUNT_NO_COMMENTS non-comment lines) — this package must never gain routing/business logic of its own." >&2
  exit 1
fi

echo "--- sanity: confirm no legacy navigation / switch-back reference in index.php's REAL CODE (a docblock explaining its absence is fine) ---"
CODE_ONLY=$(php -w "$STAGE/index.php" 2>/dev/null || true)
if echo "$CODE_ONLY" | grep -qiE 'legacy|switch.?back|kembali ke'; then
  echo "REFUSING TO BUILD: index.php's actual code (not just a comment) references legacy navigation or a switch-back link — this must be a clean cutover, no parallel old UI." >&2
  exit 1
fi

echo "--- sanity: confirm this package contains NOTHING under api/ (that tree must never be touched by this cutover) ---"
if [ -d "$STAGE/api" ]; then
  echo "REFUSING TO BUILD: an api/ directory was staged — this package must ship index.php ONLY." >&2
  exit 1
fi

echo "--- sanity: confirm api/ in the REPO itself has no uncommitted changes (this cutover must not silently carry unrelated api/ edits) ---"
git -C "$REPO_ROOT" diff --quiet HEAD -- api/ 2>/dev/null || { echo "REFUSING TO BUILD: api/ has uncommitted changes in the repo — this cutover package must ship independently of any api/ change."; exit 1; }

echo "--- sanity: php -l index.php ---"
php -l "$STAGE/index.php" > /dev/null || { echo "REFUSING TO BUILD: syntax error in index.php"; exit 1; }

echo "--- zipping (index.php at the ZIP ROOT — extract directly into public_html/factory/, NOT into its api/ subfolder) ---"
( cd "$STAGE" && zip -X -q "$ZIP_PATH" index.php )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
