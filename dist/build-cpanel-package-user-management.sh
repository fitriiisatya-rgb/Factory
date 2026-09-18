#!/usr/bin/env bash
# Builds dist/amor-factory-api-user-management-easy.zip — ADMIN User /
# Driver Account Management, for an EXISTING real deployment where Phase
# 5.5 (Dispatch/Driver/Receipt) is already installed:
#   - api/ lives at public_html/factory/api/,
#   - api/app/config/config.php already has working DB_USER/DB_PASS,
#   - migrations 0001-0007 are already applied.
#
# FILES-ONLY PATCH — NO NEW MIGRATION. Audited first: users.active
# (default 1), users.username (UNIQUE), users.password_hash, and the
# roles/user_roles M:N junction all already existed since migration 0001
# and already fully support create/deactivate/role-assignment — nothing
# in the schema needed to change. See UserService.php's own docblock for
# why no `version` column was added either.
#
# New in this pass:
#   - api/app/src/Users/{UserRepository,UserService}.php (new) — CRUD +
#     the "never zero active ADMIN" safeguard, on the EXISTING
#     users/roles/user_roles tables only.
#   - api/app/src/Controllers/UserController.php (new) + updated App.php
#     routes (/api/users/*, ADMIN-only, same CSRF/Idempotency-Key guards
#     as every other mutating route).
#   - api/_users-uat/ (new) — ADMIN-only User Management page. A
#     standalone side-by-side admin tool (same convention as
#     api/_driver-uat/, api/_do-uat/, etc.) — NOT wired into
#     api/app/ui/layout.php's sidebar (task's own "do not redesign root
#     app" instruction).
#   - api/assets/js/users.js (new) — client code for the page above.
#     Lives under the PUBLIC api/assets/, never under the deny-all
#     api/app/ — see the sanity checks below and the lesson from the
#     prior Phase 5.5 Apache asset-path patch.
#   - api/app/ui/labels.php (updated) — added a "Nonaktif" status-badge
#     color mapping (additive array entry only).
#
# This ZIP contains NO Invoice/Payment/Receivable financial logic, NO
# Phase 7 Retur/Reject lifecycle changes, and does NOT alter Dispatch/
# Driver Claim/ShipmentService/Store Receipt/QR business logic in any way.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real database
# or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-user-management"
ZIP_PATH="$DIST_DIR/amor-factory-api-user-management-easy.zip"

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
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview _driver-uat _receive; do
  mkdir -p "$STAGE/api/$tool"
  cp -r "$REPO_ROOT/api/$tool/." "$STAGE/api/$tool/"
done

echo "--- copying the NEW User Management admin tool ---"
mkdir -p "$STAGE/api/_users-uat"
cp -r "$REPO_ROOT/api/_users-uat/." "$STAGE/api/_users-uat/"
[ -f "$STAGE/api/_users-uat/index.php" ] || { echo "REFUSING TO BUILD: User Management index.php missing"; exit 1; }
[ -f "$STAGE/api/_users-uat/.htaccess" ] || { echo "REFUSING TO BUILD: User Management .htaccess missing"; exit 1; }

echo "--- copying application (source/config-example/migrations/ui, refreshed) ---"
mkdir -p "$STAGE/api/app"
cp "$REPO_ROOT/api/app/autoload.php" "$STAGE/api/app/autoload.php"
cp "$REPO_ROOT/api/app/.htaccess" "$STAGE/api/app/.htaccess"
cp -r "$REPO_ROOT/api/app/src" "$STAGE/api/app/src"
cp -r "$REPO_ROOT/api/app/ui" "$STAGE/api/app/ui"
[ -d "$STAGE/api/app/src/Users" ] || { echo "REFUSING TO BUILD: api/app/src/Users/ missing"; exit 1; }
[ -f "$STAGE/api/app/src/Controllers/UserController.php" ] || { echo "REFUSING TO BUILD: UserController.php missing"; exit 1; }
if [ -d "$STAGE/api/app/ui/assets" ]; then
  echo "REFUSING TO BUILD: api/app/ui/assets/ exists — static browser assets must live under the PUBLIC api/assets/, never inside the deny-all api/app/ tree." >&2
  exit 1
fi
mkdir -p "$STAGE/api/app/config"
cp "$REPO_ROOT/api/app/config/config.example.php" "$STAGE/api/app/config/config.example.php"
cp -r "$REPO_ROOT/api/app/migrations" "$STAGE/api/app/migrations"

echo "--- sanity: confirm NO new migration was introduced (this is a FILES-ONLY patch, migration 0007 stays the latest) ---"
if find "$STAGE/api/app/migrations" -name '0008_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0008+ was found — this package must be files-only." >&2
  exit 1
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/js/users.js" ] || { echo "REFUSING TO BUILD: api/assets/js/users.js missing"; exit 1; }
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- copying canonical schema DDL (0001-0007, UNCHANGED — no 0008 in this package) ---"
mkdir -p "$STAGE/api/app/database"
cp "$REPO_ROOT/database/schema-v1.sql" "$STAGE/api/app/database/schema-v1.sql"
cp "$REPO_ROOT/database/schema-v1-0002-master-identity.sql" "$STAGE/api/app/database/schema-v1-0002-master-identity.sql"
cp "$REPO_ROOT/database/schema-v1-0003-po-phase2.sql" "$STAGE/api/app/database/schema-v1-0003-po-phase2.sql"
cp "$REPO_ROOT/database/schema-v1-0004-production-phase3.sql" "$STAGE/api/app/database/schema-v1-0004-production-phase3.sql"
cp "$REPO_ROOT/database/schema-v1-0005-fg-packing-phase4.sql" "$STAGE/api/app/database/schema-v1-0005-fg-packing-phase4.sql"
cp "$REPO_ROOT/database/schema-v1-0006-do-shipment-phase5.sql" "$STAGE/api/app/database/schema-v1-0006-do-shipment-phase5.sql"
cp "$REPO_ROOT/database/schema-v1-0007-dispatch-receipt-phase55.sql" "$STAGE/api/app/database/schema-v1-0007-dispatch-receipt-phase55.sql"

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — User / Driver Account Management Package

FILES-ONLY INCREMENTAL update for an existing Phase 5.5 deployment. NO
DATABASE MIGRATION in this package — the existing users/roles/user_roles
tables already supported everything required. This domain's document root
is public_html/factory/ — extract this ZIP positioned INSIDE
public_html/factory/ so it merges into your existing
public_html/factory/api/. It overwrites code files but NEVER includes
app/config/config.php, so your database credentials are untouched.

**Start here**: dist/README-FIRST-CPANEL-USER-MANAGEMENT.md (delivered
alongside this ZIP) has the full step-by-step, non-technical guide.

Quick facts:
- Log in as ADMIN: api/_admin-login/ (unchanged)
- New: User Management page (ADMIN-only): api/_users-uat/
- Create Driver A / Driver B here, assign the DRIVER role, then test their
  login at api/_driver-uat/login.php to resume Phase 5.5 UAT.
- The system will never let you end up with zero active ADMIN accounts —
  deactivating or de-roling the last active admin is blocked automatically.
- This package does NOT touch Dispatch Pool, Driver Claim, ShipmentService,
  Store Receipt, QR logic, Invoice, or any Phase 1-5.5 business rule.
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

echo "--- sanity: confirm every prior UAT tool + UI preview + the new User Management tool is present ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview _driver-uat _receive _users-uat; do
  if [ ! -f "$STAGE/api/$tool/index.php" ]; then
    echo "REFUSING TO BUILD: api/$tool/index.php is missing — old tools must never be dropped by this package." >&2
    exit 1
  fi
done

echo "--- sanity: confirm NO Invoice/Payment/Receivable business logic was added (out of scope for this package) ---"
if find "$STAGE/api/app/src" -iname '*invoice*' -o -iname '*payment*' -o -iname '*receivable*' | grep -q .; then
  echo "REFUSING TO BUILD: an Invoice/Payment/Receivable file was found under api/app/src — out of scope." >&2
  exit 1
fi

echo "--- sanity: confirm NO Phase 7 physical Retur/Reject return-lifecycle logic was added ---"
if find "$STAGE/api/app/src" -iname '*retur*' -o -iname '*reject_note*' -o -iname '*returnlifecycle*' | grep -q .; then
  echo "REFUSING TO BUILD: a Retur/Reject lifecycle file was found — out of scope." >&2
  exit 1
fi

echo "--- sanity: confirm Dispatch/Delivery business logic files are UNCHANGED byte-for-byte vs the repo ---"
for f in api/app/src/Dispatch/DispatchService.php api/app/src/Dispatch/DepartureService.php \
         api/app/src/Dispatch/ReceiptService.php api/app/src/Delivery/ShipmentService.php; do
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — this package must not touch Dispatch/Driver Claim/ShipmentService/Store Receipt logic." >&2
    exit 1
  fi
done

echo "--- sanity: confirm api/app/.htaccess is STILL deny-all (the security boundary this patch must never weaken) ---"
if ! grep -q 'Require all denied' "$STAGE/api/app/.htaccess"; then
  echo "REFUSING TO BUILD: api/app/.htaccess no longer denies all HTTP access — this must never be weakened." >&2
  exit 1
fi

echo "--- sanity: confirm NO browser-facing PHP file references the deny-all api/app/ path ---"
if grep -rl 'href="/api/app/\|src="/api/app/' "$STAGE/api" --include='*.php' | grep -q .; then
  echo "REFUSING TO BUILD: a browser-facing href/src still points inside the deny-all api/app/ tree." >&2
  grep -rln 'href="/api/app/\|src="/api/app/' "$STAGE/api" --include='*.php' >&2
  exit 1
fi

echo "--- sanity: confirm no plaintext-looking password constant was committed ---"
if grep -rlE "password\s*=\s*['\"][^'\"]{4,}['\"]" "$STAGE/api/app/src/Users" 2>/dev/null | grep -q .; then
  echo "REFUSING TO BUILD: a hardcoded-looking password string was found under api/app/src/Users." >&2
  exit 1
fi

echo "--- zipping ---"
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )

echo "--- done ---"
ls -la "$ZIP_PATH"
echo "Files in package: $(unzip -l "$ZIP_PATH" | tail -n +4 | head -n -2 | wc -l)"

rm -rf "$STAGE"
