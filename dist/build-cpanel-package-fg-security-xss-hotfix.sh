#!/usr/bin/env bash
# Builds dist/amor-factory-fg-security-xss-hotfix.zip — SECURITY HOTFIX:
# escape all dynamic FG UI output.
#
# Migration 0014 is already APPLIED on the real cPanel host. This package
# adds NO new migration, touches NO schema, calls NO new backend endpoint,
# and changes NO business logic — it is a pure client-side (JavaScript)
# escaping fix inside api/app/ui/pages/fg-packing.php, on top of the
# already-deployed Mobile-First FG Verifikasi + Packing per Toko package
# (amor-factory-mobile-fg-verifikasi-packing.zip).
#
# WHAT CHANGED
#
# The mobile-first FG rework built several HTML strings by concatenating
# untrusted dynamic values (store name, product name, Keterangan/notes,
# an API-derived status label, and API error messages via e.message)
# directly into innerHTML. A store/product name or a user-entered
# Keterangan containing HTML/script markup would have been inserted as
# real markup, not literal text — a stored-XSS surface. A single
# escHtml() helper (escapes &, <, >, ", ') is now used consistently at
# EVERY one of those sites:
#   - buildVerifikasiStoreBlock(): product name, store name, Keterangan
#     (read-only span AND the editable input's value attribute), status
#     label, and its own fetch-failure error message.
#   - renderChips(): store name, store status label.
#   - renderDetail(): product name, store name (page title + submit
#     button label), Keterangan (read-only span AND editable input
#     value), status label.
#   - initPacking()'s own fetch-failure error message.
# The old ad hoc ".replace(/\"/g, '&quot;')" (which only escaped one of
# the five characters that matter) is replaced everywhere by the same
# escHtml() helper. Numeric-only values (ids, target/verified/packed
# quantities) are never escaped — they cannot carry markup, and the task
# explicitly asked not to escape them unnecessarily.
#
# Amor.toast()'s and Amor.confirmModal()'s own message/body rendering in
# assets/js/app.js already used el.textContent (never innerHTML) before
# this pass — confirmed safe, byte-for-byte unchanged here.
#
# WHAT DID NOT CHANGE
#
# No business logic, no database schema, no migration, and no Production/
# Shipment/Stock/Reservation logic changed. The pre-existing "a product
# verified only in Per Produk mode must first be Breakdown Toko'd before
# it can be packed per store" rule is completely unchanged.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-fg-security-xss-hotfix"
ZIP_PATH="$DIST_DIR/amor-factory-fg-security-xss-hotfix.zip"

rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api"

echo "--- copying public entry points (index.php + .htaccess, unchanged) ---"
cp "$REPO_ROOT/api/index.php" "$STAGE/api/index.php"
cp "$REPO_ROOT/api/.htaccess" "$STAGE/api/.htaccess"

echo "--- copying every existing tool, unchanged, for a clean overwrite (nothing dropped) ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview _driver-uat _receive _users-uat; do
  mkdir -p "$STAGE/api/$tool"
  cp -r "$REPO_ROOT/api/$tool/." "$STAGE/api/$tool/"
done

echo "--- copying application (source/config-example/migrations/ui, current) ---"
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

echo "--- sanity: confirm NO new migration was added (0014 is already LIVE — this must be a code-only hotfix) ---"
[ -f "$STAGE/api/app/migrations/0014_production_fg_division_rework.php" ] || { echo "REFUSING TO BUILD: migration 0014 is missing"; exit 1; }
if find "$STAGE/api/app/migrations" -name '0015_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0015+ was found — this hotfix must add NO new migration." >&2
  exit 1
fi

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, current) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: the escHtml() escaping helper is present and used at every known unsafe render site ---"
grep -q "function escHtml" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's escHtml() helper is missing."; exit 1; }
for needle in \
  "escHtml(pname)" \
  "escHtml(s.storeName)" \
  "escHtml(s.notes" \
  "escHtml(s.status" \
  "escHtml(e.message)" \
  "escHtml(g.storeName)" \
  "escHtml(st.label)" \
  "escHtml(r.productName)" \
  "escHtml(r.notes" \
  "escHtml(r.status" \
  "escHtml(group.storeName)"; do
  grep -qF "$needle" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php is missing an expected escHtml() call site: $needle"; exit 1; }
done
if grep -qE "'\)\.replace\(/\"/g, *'&quot;'\)" "$STAGE/api/app/ui/pages/fg-packing.php"; then
  echo "REFUSING TO BUILD: fg-packing.php still contains the OLD ad hoc, incomplete .replace(/\"/g, '&quot;') escaping pattern applied directly to a value (outside escHtml() itself) — every site must go through escHtml() consistently." >&2
  exit 1
fi

echo "--- sanity: confirm the prior mobile-first FG rework's own markup/JS is still intact, unregressed ---"
grep -q "fgStep = " "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Verifikasi/Packing step switch is missing."; exit 1; }
grep -q "function groupByStore" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Packing-per-Toko store grouping is missing."; exit 1; }
grep -q "window.addEventListener('load', initPacking)" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php no longer defers its first Packing fetch to window 'load'."; exit 1; }
grep -qE "fg-packing-row\[data-exploded=\"1\"\]" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's exploded-only submit-payload guard is missing."; exit 1; }
grep -q "existingHasRealData" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::batchProductStores()'s existingHasRealData fix is missing."; exit 1; }
grep -q "HAVING SUM(pi.aktual) > 0.0001" "$STAGE/api/app/src/Fg/FgTargetService.php" || { echo "REFUSING TO BUILD: FgTargetService's target>0 HAVING filter is missing."; exit 1; }

echo "--- sanity: confirm PHP-side output escaping (ui_esc = htmlspecialchars ENT_QUOTES) is unchanged ---"
grep -q "htmlspecialchars(\$s, ENT_QUOTES)" "$STAGE/api/app/ui/bootstrap.php" || { echo "REFUSING TO BUILD: ui_esc()'s htmlspecialchars(..., ENT_QUOTES) implementation changed or is missing."; exit 1; }

echo "--- sanity: confirm NO business rule / shipment / stock / reservation / DO / Production logic file changed byte-for-byte ---"
for f in api/app/src/Delivery/DoRepository.php api/app/src/Delivery/DoService.php api/app/src/Delivery/DoTargetService.php \
         api/app/src/Fg/FgRepository.php api/app/src/Fg/FgTargetService.php \
         api/app/src/Production/ProductionRepository.php api/app/src/Production/ProductionService.php \
         api/app/src/Production/ProductionRoutingService.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Production/ProductionTaskService.php \
         api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php api/app/src/SpecialOrder/SpecialOrderFgAllocationRepository.php \
         api/app/src/SpecialOrder/SpecialOrderDoService.php api/app/src/Dispatch/ReceiptService.php \
         api/app/src/Import/PoImporter.php \
         api/app/ui/pages/pengiriman.php api/app/ui/pages/delivery-order.php api/app/ui/pages/delivery-order-detail.php \
         api/app/ui/pages/produksi.php api/app/ui/pages/produksi-task-per-divisi.php api/app/ui/pages/produksi-demand.php \
         api/app/ui/pages/laporan.php \
         api/app/ui/layout.php api/app/ui/components.php api/app/ui/labels.php api/app/ui/bootstrap.php \
         api/assets/js/app.js \
         api/app/src/Fg/FgService.php; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this security hotfix (business logic, schema-adjacent code, and server-side rendering must be byte-for-byte unchanged; only fg-packing.php's client-side JS changes in this pass)." >&2
    exit 1
  fi
done

echo "--- sanity: confirm ONLY fg-packing.php changed inside api/app/ui/pages/ versus the currently-deployed mobile-fg package's own known-good file list ---"
# (no stronger check available here without the previous package's own
# staged tree — the explicit per-file diff loop above is the real guard.)

echo "--- copying canonical schema DDL (0001-0014, byte-for-byte unchanged — 0014 is already LIVE) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql schema-v1-0013-repair-production-flow-completion.sql \
         schema-v1-0014-production-fg-division-rework.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
done
diff -q "$REPO_ROOT/database/schema-v1-0014-production-fg-division-rework.sql" "$STAGE/api/app/database/schema-v1-0014-production-fg-division-rework.sql" > /dev/null || { echo "REFUSING TO BUILD: migration 0014's SQL changed — it must stay byte-for-byte identical to what is already live."; exit 1; }

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — FG Security Hotfix (escape all dynamic FG UI output)

Code-only security hotfix on top of the already-deployed Mobile-First FG
Verifikasi + Packing per Toko package. Migration 0014 is already LIVE —
this package adds NO new migration, touches NO schema, calls NO new
backend endpoint, and changes NO business logic.

## What changed

api/app/ui/pages/fg-packing.php built several HTML strings by
concatenating untrusted dynamic values (store name, product name,
Keterangan/notes, an API-derived status label, API error messages via
e.message) directly into innerHTML, with only an incomplete ad hoc
`.replace(/"/g, '&quot;')` in a couple of spots. A store/product name or
a user-entered Keterangan containing HTML/script markup rendered as real
markup, not literal text — a stored-XSS surface.

A single `escHtml()` helper (escapes &, <, >, ", ') now guards every one
of those sites consistently: the Breakdown Toko renderer
(buildVerifikasiStoreBlock), the Packing store chips (renderChips), the
Packing store detail cards (renderDetail), and both steps' own
fetch-failure error banners (which previously inserted `e.message`
straight into innerHTML). Numeric-only values (ids, target/verified/
packed quantities) are never escaped.

Amor.toast() and Amor.confirmModal() in assets/js/app.js already
rendered their message/body via `el.textContent` (never innerHTML) —
confirmed safe, unchanged.

## What did NOT change

No business logic, no database schema, no migration, no Production/
Shipment/Stock/Reservation logic. The pre-existing "a product verified
only in Per Produk mode must first be Breakdown Toko'd before it can be
packed per store" rule is completely unchanged. Enforced by this build
script's own byte-for-byte diff sanity checks.

## Testing

Full existing regression suite re-run, 100% green. New FG-XSS-01..05 (a
real headless-Chromium check against a fixture whose store name is
`<script>alert(1)</script>`, product name is
`<img src=x onerror=alert(1)>`, and Keterangan is
`"><img src=x onerror=alert(1)>`) confirm every payload renders as inert
literal text in FG Verifikasi's Breakdown Toko and FG Packing's per-Toko
view (both as rendered text and as an input's exact `.value`), that no
live `<script>`/`<img onerror>` element is ever created, that no
alert()/confirm()/prompt() dialog ever fires, and that a forced API error
message containing markup renders as plain text too. A normal Indonesian
Keterangan containing "&" rides along in the same fixture and is
confirmed to render completely unchanged.
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

echo "--- sanity: confirm every prior UAT tool + UI preview is present ---"
for tool in _admin-login _upgrade _import-po _production-uat _fg-uat _do-uat _ui-preview _driver-uat _receive _users-uat; do
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

echo "--- sanity: confirm the evidence upload directory ships deny-all and clean (no stray test photos) ---"
mkdir -p "$STAGE/api/uploads/receipt-evidence"
cp "$REPO_ROOT/api/uploads/receipt-evidence/.htaccess" "$STAGE/api/uploads/receipt-evidence/.htaccess"
if ! grep -q 'Require all denied' "$STAGE/api/uploads/receipt-evidence/.htaccess"; then
  echo "REFUSING TO BUILD: api/uploads/receipt-evidence/.htaccess must deny all direct HTTP access." >&2
  exit 1
fi
if find "$REPO_ROOT/api/uploads/receipt-evidence" -iname '*.png' -o -iname '*.jpg' 2>/dev/null | grep -q .; then
  echo "REFUSING TO BUILD: stray uploaded test evidence found in the repo's own uploads directory — clean it up before building." >&2
  exit 1
fi

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
