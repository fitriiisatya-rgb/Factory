#!/usr/bin/env bash
# Builds dist/amor-factory-alat-hapus-uji-retur.zip — ONLY api/_hapus-uji-retur/
# (the one-time tool that removes the Retur, Reject & Mutasi forms entered
# while trying migration 0026 on live). Shipped separately on purpose: it
# deletes itself after a successful run. Reads the repo, writes dist/ only.
set -euo pipefail
DIST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIST_DIR/.." && pwd)"
STAGE="$DIST_DIR/.stage-alat-hapus-uji-retur"
ZIP_PATH="$DIST_DIR/amor-factory-alat-hapus-uji-retur.zip"
F="$STAGE/api/_hapus-uji-retur/index.php"
rm -rf "$STAGE" "$ZIP_PATH"
mkdir -p "$STAGE/api/_hapus-uji-retur"
cp "$REPO_ROOT/api/_hapus-uji-retur/index.php" "$F"
php -l "$F" > /dev/null
grep -q "requireRole('ADMIN')" "$F" || { echo "REFUSING TO BUILD: ADMIN check missing"; exit 1; }
grep -q "password_verify" "$F" || { echo "REFUSING TO BUILD: password re-check missing"; exit 1; }
grep -q "gzopen" "$F" || { echo "REFUSING TO BUILD: automatic backup missing"; exit 1; }
grep -q "invoice_mutasi WHERE mutasi_request_id" "$F" || { echo "REFUSING TO BUILD: invoiced-mutasi guard missing"; exit 1; }
grep -q "invoice_id IS NOT NULL" "$F" || { echo "REFUSING TO BUILD: invoiced-nota guard missing"; exit 1; }
for never in invoice invoice_item invoice_shipment shipment delivery_order production_run fg_batch po_batch product store; do
  if grep -qE "DELETE FROM \`?${never}\`? " "$F" || grep -q "'${never}' => " "$F"; then
    echo "REFUSING TO BUILD: ${never} must never be deleted by this tool"; exit 1
  fi
done
( cd "$STAGE" && zip -r -X -q "$ZIP_PATH" api )
rm -rf "$STAGE"
ls -la "$ZIP_PATH"
