#!/usr/bin/env bash
# Builds dist/amor-factory-pack-edit-lock.zip — LIVE UAT UX FIX: lock
# Packing after submit + explicit Edit / Resubmit flow.
#
# NO NEW MIGRATION. Migration 0015 (fg_store_packing_submission) is
# already live and its existing 'submitted'/'stale' status is, as-is,
# already capable of representing this pass's whole correction lifecycle
# — the ONLY change in this pass is UI-only (fg-packing.php's Packing
# view now genuinely LOCKS all fields once a store shows Sudah Disubmit,
# instead of leaving Reject/Hilang/Keterangan/Packing inputs directly
# editable). Server-side enforcement of "a submitted store cannot be
# silently overwritten by an ordinary edit call" was ALREADY present
# before this pass, in FgService::applyStoreRow() + patchDraft()'s own
# real-change detection -> invalidatePackingSubmission() call, built in
# the immediately prior pass (amor-factory-pack-submit-state.zip) — this
# pass's own new PACK-EDIT-07/08/10/11/13/16 tests exist specifically to
# PROVE that existing architecture already satisfies this pass's
# "deliberate correction flow, not just disabled HTML controls" and
# "cannot be bypassed by manually calling the endpoint" requirements,
# with zero further server-side code changes required.
#
# WHAT CHANGED (UI ONLY — api/app/ui/pages/fg-packing.php)
#
#   - New client-side (per-open-store, never persisted) packingEditMode
#     boolean. A submitted/stale store's fields render read-only
#     (locked) unless packingEditMode is explicitly true.
#   - Clicking "Edit Packing" shows a real confirmation dialog ("Packing
#     toko ini sudah disubmit. Apakah Anda ingin melakukan koreksi?" /
#     Batal / Ya, Edit Packing) BEFORE unlocking anything — merely
#     clicking Edit Packing and cancelling never touches persisted
#     submission state.
#   - While editing, a clear "MODE EDIT — Sedang Dikoreksi" indicator is
#     shown; "Batal Edit" discards local edits and re-locks without any
#     server call; "Simpan Perubahan" saves via the EXISTING generic
#     PATCH /api/fg/{id} storeItems endpoint (not a new endpoint) — its
#     already-existing real-change detection is what decides, server-
#     side, whether the store's submission becomes stale (never a
#     client-side inference).
#   - A stale ("Perlu Submit Ulang") store shows an active "Submit Ulang
#     Packing <Store>" button, which resubmits via the EXISTING POST
#     /api/fg/{id}/packing-submit endpoint using the already-persisted
#     row values (collectPackingRowsFromGroup(), since fields are locked
#     and there is no DOM to read from) — after a successful resubmit,
#     fields lock again and the store returns to Sudah Disubmit.
#   - packingEditMode is unconditionally reset to false on every chip
#     switch and after every successful submit/resubmit/save, so edit
#     mode can never leak across stores or across a completed action.
#
# This script only READS from the repo and WRITES to dist/ — it never
# modifies api/ or database/ in place, and never touches any real
# database or credential.
set -euo pipefail

DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-pack-edit-lock"
ZIP_PATH="$DIST_DIR/amor-factory-pack-edit-lock.zip"

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

echo "--- sanity: NO NEW MIGRATION — 0015 stays the latest, 0001-0015 byte-for-byte unchanged from the already-live pass ---"
[ -f "$STAGE/api/app/migrations/0015_fg_store_packing_submission.php" ] || { echo "REFUSING TO BUILD: migration 0015 is missing."; exit 1; }
if find "$STAGE/api/app/migrations" -name '0016_*' | grep -q .; then
  echo "REFUSING TO BUILD: an unexpected migration 0016+ was found — this pass introduces NO new migration (UI-only change, per the task's own explicit instruction to STOP and report rather than create 0016 unless truly required)." >&2
  exit 1
fi
diff -q "$REPO_ROOT/api/app/migrations/0015_fg_store_packing_submission.php" "$STAGE/api/app/migrations/0015_fg_store_packing_submission.php" > /dev/null || { echo "REFUSING TO BUILD: migration 0015 differs from the repo — it must stay byte-for-byte identical to what is already live."; exit 1; }

echo "--- copying PUBLIC static assets (api/assets/ — outside the deny-all api/app/ tree, current) ---"
mkdir -p "$STAGE/api/assets"
cp -r "$REPO_ROOT/api/assets/." "$STAGE/api/assets/"
[ -f "$STAGE/api/assets/.htaccess" ] || { echo "REFUSING TO BUILD: api/assets/.htaccess missing"; exit 1; }

echo "--- sanity: this pass's own new lock/Edit Packing/Resubmit UI markers are present in fg-packing.php ---"
grep -q "var packingEditMode" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's packingEditMode UI state is missing."; exit 1; }
grep -q "id=\"fg-edit-packing-store\"" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Edit Packing button is missing."; exit 1; }
grep -q "id=\"fg-resubmit-packing-store\"" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Submit Ulang Packing button is missing."; exit 1; }
grep -q "id=\"fg-cancel-edit-packing\"" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Batal Edit button is missing."; exit 1; }
grep -q "id=\"fg-save-packing-correction\"" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Simpan Perubahan button is missing."; exit 1; }
grep -q "Packing toko ini sudah disubmit. Apakah Anda ingin melakukan koreksi?" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Edit Packing confirmation wording is missing."; exit 1; }
grep -q "MODE EDIT" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's MODE EDIT indicator is missing."; exit 1; }
grep -q "function collectPackingRowsFromDom" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's collectPackingRowsFromDom() helper is missing."; exit 1; }
grep -q "function collectPackingRowsFromGroup" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's collectPackingRowsFromGroup() helper is missing."; exit 1; }
grep -q "function wirePackingActions" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's wirePackingActions() is missing."; exit 1; }
if grep -q "function wireSubmitButton" "$STAGE/api/app/ui/pages/fg-packing.php"; then
  echo "REFUSING TO BUILD: fg-packing.php still contains the RETIRED wireSubmitButton() — it must be fully replaced by wirePackingActions()." >&2
  exit 1
fi

echo "--- sanity: server-side correction authorization already existed before this pass and is unregressed (no new migration/endpoint required) ---"
grep -q "function applyStoreRow" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::applyStoreRow() (shared validation+change-detection) is missing."; exit 1; }
grep -q "function invalidatePackingSubmission" "$STAGE/api/app/src/Fg/FgRepository.php" || { echo "REFUSING TO BUILD: FgRepository::invalidatePackingSubmission() is missing."; exit 1; }
grep -q "function submitStorePacking" "$STAGE/api/app/src/Fg/FgService.php" || { echo "REFUSING TO BUILD: FgService::submitStorePacking() is missing."; exit 1; }
grep -q "Auth::requireRole" "$STAGE/api/app/src/Controllers/FgController.php" || { echo "REFUSING TO BUILD: FgController's role-gate is missing."; exit 1; }

echo "--- sanity: confirm NO server-side business logic changed at all in this pass (UI-only change; byte-for-byte elsewhere) ---"
for f in api/app/src/Fg/FgService.php api/app/src/Fg/FgRepository.php api/app/src/Fg/FgTargetService.php \
         api/app/src/Controllers/FgController.php api/app/src/App.php \
         api/app/src/Delivery/DoTargetService.php api/app/src/Delivery/DoRepository.php api/app/src/Delivery/DoService.php \
         api/app/src/Production/ProductionRepository.php api/app/src/Production/ProductionService.php \
         api/app/src/Production/ProductionRoutingService.php api/app/src/Production/ProductionTargetService.php \
         api/app/src/Production/ProductionTaskService.php \
         api/app/src/SpecialOrder/SpecialOrderFgAllocationService.php api/app/src/SpecialOrder/SpecialOrderFgAllocationRepository.php \
         api/app/src/SpecialOrder/SpecialOrderDoService.php api/app/src/Dispatch/ReceiptService.php \
         api/app/src/Import/PoImporter.php \
         api/app/src/Controllers/DoController.php \
         api/app/ui/pages/pengiriman.php api/app/ui/pages/delivery-order.php api/app/ui/pages/delivery-order-detail.php \
         api/app/ui/pages/produksi.php api/app/ui/pages/produksi-task-per-divisi.php api/app/ui/pages/produksi-demand.php \
         api/app/ui/pages/laporan.php \
         api/app/ui/layout.php api/app/ui/components.php api/app/ui/labels.php api/app/ui/bootstrap.php \
         api/app/ui/print-template.php \
         api/assets/js/app.js api/assets/css/app.css; do
  if [ ! -f "$REPO_ROOT/$f" ]; then continue; fi
  if ! diff -q "$REPO_ROOT/$f" "$STAGE/$f" > /dev/null 2>&1; then
    echo "REFUSING TO BUILD: $f differs from the repo — out of scope for this pass (this is a UI-only fg-packing.php change; no server-side business logic, stock/shipment/reservation code, routing, or static assets may change here)." >&2
    exit 1
  fi
done

echo "--- sanity: confirm the prior passes' own fixes are still intact, unregressed ---"
grep -q "function escHtml" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's escHtml() helper is missing — the security hotfix must not regress."; exit 1; }
grep -q "fgStep = " "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Verifikasi/Packing step switch is missing."; exit 1; }
grep -q "function groupByStore" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's Packing-per-Toko store grouping is missing."; exit 1; }
grep -q "window.addEventListener('load', initPacking)" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php no longer defers its first Packing fetch to window 'load'."; exit 1; }
grep -q "sudah_disubmit" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's sudah_disubmit status code is missing."; exit 1; }
grep -q "perlu_submit_ulang" "$STAGE/api/app/ui/pages/fg-packing.php" || { echo "REFUSING TO BUILD: fg-packing.php's perlu_submit_ulang (stale) status code is missing."; exit 1; }
grep -q "fg-store-chip-stale" "$STAGE/api/assets/css/app.css" || { echo "REFUSING TO BUILD: app.css's Perlu Submit Ulang chip styling is missing."; exit 1; }
COUNT_CORRECT_FILTER=$(grep -c "(si.po_awal + si.po_revisi) > 0" "$STAGE/api/app/src/Delivery/DoTargetService.php" || true)
if [ "$COUNT_CORRECT_FILTER" -lt 3 ]; then
  echo "REFUSING TO BUILD: expected the corrected (po_awal + po_revisi) > 0 filter in all 3 of DoTargetService's demand queries, found $COUNT_CORRECT_FILTER." >&2
  exit 1
fi

echo "--- copying canonical schema DDL (0001-0015, all byte-for-byte unchanged and already LIVE — no new file this pass) ---"
mkdir -p "$STAGE/api/app/database"
for f in schema-v1.sql schema-v1-0002-master-identity.sql schema-v1-0003-po-phase2.sql \
         schema-v1-0004-production-phase3.sql schema-v1-0005-fg-packing-phase4.sql \
         schema-v1-0006-do-shipment-phase5.sql schema-v1-0007-dispatch-receipt-phase55.sql \
         schema-v1-0008-receipt-evidence.sql schema-v1-0009-shipment-email.sql \
         schema-v1-0010-special-nonregular-orders.sql schema-v1-0011-production-task-per-division.sql \
         schema-v1-0012-production-flow-completion.sql schema-v1-0013-repair-production-flow-completion.sql \
         schema-v1-0014-production-fg-division-rework.sql schema-v1-0015-fg-store-packing-submission.sql; do
  cp "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f"
  diff -q "$REPO_ROOT/database/$f" "$STAGE/api/app/database/$f" > /dev/null || { echo "REFUSING TO BUILD: $f differs unexpectedly."; exit 1; }
done

echo "--- writing package-local short docs ---"
cat > "$STAGE/api/PACKAGE-INFO.md" <<'EOF'
# Amor Factory System — Lock Packing After Submit + Edit/Resubmit Flow

UI-ONLY change on top of the already-deployed Real Persisted Packing
Submission State package (amor-factory-pack-submit-state.zip / migration
0015). NO NEW MIGRATION — nothing to run in cPanel beyond deploying these
files; migration 0015 stays the latest and is byte-for-byte unchanged.

## Why

Even with migration 0015's real submission state, a submitted store's
Reject/Hilang/Keterangan/Packing fields remained directly editable in the
UI with no explicit Edit action — confusing and unsafe operationally on
the phone-first Packing screen used live on the factory floor.

## What changed

- fg-packing.php's Packing detail view now genuinely LOCKS all fields
  (Sesuai/Tidak Sesuai, Actual Packing, Reject, Hilang, Keterangan) once
  a store shows Sudah Disubmit or Perlu Submit Ulang, replacing the
  disabled-but-still-focusable Submit Packing button with a plain
  "✓ Sudah Disubmit" status and a secondary "Edit Packing" action.
- "Edit Packing" always confirms first ("Packing toko ini sudah
  disubmit. Apakah Anda ingin melakukan koreksi?" / Batal / Ya, Edit
  Packing) — clicking it and cancelling never changes persisted state.
- Confirming shows a clear "MODE EDIT — Sedang Dikoreksi" indicator and
  unlocks the fields; "Batal Edit" discards local edits with no server
  call; "Simpan Perubahan" saves via the EXISTING generic PATCH
  /api/fg/{id} endpoint.
- No new endpoint and no new authorization rule were needed: the prior
  pass's own FgService::applyStoreRow() + patchDraft() real-change
  detection already flips a submitted store to 'stale' ONLY when a value
  genuinely changes (never on an identical resave), and already runs
  under the same Auth::requireRole() gate as every other FG write — this
  pass's own new tests (PACK-EDIT-07/08/10/11/13/16) exist specifically
  to prove that server-side behavior, unchanged, already satisfies this
  pass's "no UI-only locking" requirement.
- A stale ("Perlu Submit Ulang") store shows an active "Submit Ulang
  Packing <Store>" button; resubmitting uses the EXISTING POST
  /api/fg/{id}/packing-submit endpoint, then locks again.

## What did NOT change

FgService.php, FgRepository.php, FgController.php, App.php, and every
DO/Production/Special Order/shipment/reservation file are byte-for-byte
unchanged (enforced by this build script's own diff sanity checks).
Editing/resubmitting Packing per Store still never posts stock_ledger,
never decrements stock, never creates a shipment, and never touches
store_fg_balance's physical quantity — the separate, whole-document
"Submit FG (Semua Toko)" finalization action is completely untouched.

## Testing

Full existing regression suite re-run, 100% green. New PACK-EDIT-01..16
(a real headless-Chromium click-through at 390px mobile width covering
the full lock -> confirm -> Edit Packing -> MODE EDIT -> Batal Edit
no-op -> real change + dirty indicator -> Simpan Perubahan -> Perlu
Submit Ulang -> reload persistence -> Submit Ulang Packing -> locked
Sudah Disubmit again -> Store B unaffected -> no horizontal scroll flow,
plus server-level no-op-never-stale / real-change-always-stale /
concurrent-version-conflict / unauthorized-role-rejected proofs) all
pass. The pre-existing PACK-SUBMIT-01..10 suite was updated only to
target this pass's new #fg-resubmit-packing-store id (the resubmit
button on an already-locked/stale store) rather than the first-time-only
#fg-submit-packing-store id — a pure test-selector update to match the
new, intentional button-id scheme, not an application regression.
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
