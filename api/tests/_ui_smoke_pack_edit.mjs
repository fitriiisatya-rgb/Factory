// Real headless-browser smoke check (NOT part of the regular PHP suite) for
// the "LIVE UAT UX FIX — lock Packing after submit + explicit Edit/
// Resubmit flow" pass. Invoked by run-production-division-fg-rework.sh
// right after ProductionDivisionFgReworkTest.php exits 0, against the
// SAME live php -S server + MariaDB + the exact FG batch Part N
// (PACK-EDIT-00) just created and left in DRAFT status.
//
// Covers PACK-EDIT-01..06/09/12/15 — driven through the REAL "Submit
// Packing" -> "Edit Packing" (with confirmation) -> "Batal Edit" ->
// "Edit Packing" again -> real change -> "Simpan Perubahan" -> "Submit
// Ulang Packing" flow at a REAL mobile viewport (390x844), exactly as an
// operator would use it on a phone. PACK-EDIT-07/08/10/11/13/14/16 are
// proven directly at the API/DB level, in the PHP suite itself, against
// Store C and a dedicated unauthorized DRIVER session this script never
// touches.
//
// Required environment: BASE_URL, ADMIN_USER, ADMIN_PASS, TANGGAL, FACTORY_ID.
// Exit code 0 = pass, 1 = fail (message on stderr).
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';

const BASE_URL = process.env.BASE_URL;
const ADMIN_USER = process.env.ADMIN_USER;
const ADMIN_PASS = process.env.ADMIN_PASS;
const TANGGAL = process.env.TANGGAL;
const FACTORY_ID = process.env.FACTORY_ID;

if (!BASE_URL || !ADMIN_USER || !ADMIN_PASS || !TANGGAL || !FACTORY_ID) {
  console.error('Missing required env vars (BASE_URL/ADMIN_USER/ADMIN_PASS/TANGGAL/FACTORY_ID)');
  process.exit(1);
}

const failures = [];
function check(cond, label) {
  if (cond) { console.log(`OK   ${label}`); }
  else { console.log(`FAIL ${label}`); failures.push(label); }
}

const packingUrl = `/_ui-preview/index.php?page=fg-packing&tanggal=${TANGGAL}&factoryId=${FACTORY_ID}&step=packing`;

async function noHorizontalScroll(page) {
  return page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
}

async function login(page) {
  await page.goto(`${BASE_URL}/_admin-login/index.php`, { waitUntil: 'load' });
  await page.fill('input[name="username"]', ADMIN_USER);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.click('button[type="submit"]'),
  ]);
  return (await page.content()).includes('Login berhasil');
}

async function chipByStoreName(page, name) {
  return page.locator('.fg-store-chip', { hasText: name }).first();
}

async function openStore(page, name) {
  const chip = await chipByStoreName(page, name);
  await chip.click();
  await page.waitForSelector('#fg-packing-detail .fg-packing-row[data-exploded="1"]', { timeout: 15000 });
  return chip;
}

async function waitForChipText(page, name, text) {
  await page.waitForFunction(
    ([n, t]) => Array.from(document.querySelectorAll('.fg-store-chip')).some((c) => c.innerText.includes(n) && c.innerText.includes(t)),
    [name, text],
    { timeout: 15000 }
  );
}

const consoleErrors = [];
let page = null;
const browser = await chromium.launch({ headless: true });

try {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  page = await context.newPage();
  page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });

  check(await login(page), 'real login form authenticated as ADMIN');

  await page.goto(`${BASE_URL}${packingUrl}`, { waitUntil: 'load' });
  await page.waitForSelector('#fg-store-chip-row .fg-store-chip', { timeout: 15000 });
  check(await noHorizontalScroll(page), 'PACK-EDIT-15: Packing chip list at mobile width (390px) has no horizontal page scroll');

  // -------------------------------------------------------------------
  // Submit Store A for real (target 10, Sesuai) so PACK-EDIT-01.. can
  // exercise the LOCKED view against a genuinely submitted store.
  // -------------------------------------------------------------------
  await openStore(page, 'P2 TEST STORE A');
  check(await page.locator('#fg-submit-packing-store').count() === 1, 'before any submit, an active "Submit Packing" button is present and fields are editable');
  check(await page.locator('#fg-packing-detail [data-pk-field="packed"]').count() === 1, 'before submit, Actual Packing is a real editable input');
  await page.locator('#fg-packing-detail .pk-sesuai button[data-value="sesuai"]').first().click();
  await page.locator('#fg-submit-packing-store').click();
  await waitForChipText(page, 'P2 TEST STORE A', 'Sudah Disubmit');

  // -------------------------------------------------------------------
  // PACK-EDIT-01/02/03/04: after submit, ALL packing fields are locked
  // (no editable inputs at all), badge shows Sudah Disubmit, and the
  // only action is "Edit Packing" (no lingering disabled Submit button
  // as the main action).
  // -------------------------------------------------------------------
  await openStore(page, 'P2 TEST STORE A');
  const detailText1 = await page.locator('#fg-packing-detail').innerText();
  check(detailText1.includes('Sudah Disubmit'), 'PACK-EDIT-01: detail view shows the Sudah Disubmit badge after submit');
  check(await page.locator('#fg-packing-detail [data-pk-field="packed"]').count() === 0, 'PACK-EDIT-01: Actual Packing is NOT an editable input after submit');
  check(await page.locator('#fg-packing-detail [data-pk-field="reject"]').count() === 0, 'PACK-EDIT-02: Reject field cannot be edited directly after submit');
  check(await page.locator('#fg-packing-detail [data-pk-field="hilang"]').count() === 0, 'PACK-EDIT-03: Hilang field cannot be edited directly after submit');
  check(await page.locator('#fg-packing-detail input[data-pk-field="notes"]').count() === 0, 'PACK-EDIT-04: Keterangan cannot be edited directly after submit');
  check(await page.locator('#fg-packing-detail .pk-sesuai').count() === 0, 'PACK-EDIT-01: Sesuai/Tidak Sesuai controls are not present while locked');
  check(await page.locator('#fg-submit-packing-store').count() === 0, 'PACK-EDIT-01: no plain "Submit Packing" button is shown as the main action once locked');
  check(await page.locator('#fg-edit-packing-store').count() === 1, 'PACK-EDIT-01: an "Edit Packing" button is present instead');

  // -------------------------------------------------------------------
  // PACK-EDIT-05 (declined path): clicking Edit Packing shows a
  // confirmation FIRST; declining leaves everything locked, unchanged.
  // -------------------------------------------------------------------
  await page.locator('#fg-edit-packing-store').click();
  await page.waitForSelector('.modal-backdrop.open .modal-body', { timeout: 5000 });
  const modalText = await page.locator('.modal-backdrop.open .modal-body').innerText();
  check(modalText.includes('Packing toko ini sudah disubmit') && modalText.includes('koreksi'), `PACK-EDIT-05: Edit Packing confirmation shows the expected wording (got: ${JSON.stringify(modalText)})`);
  await page.locator('.modal-backdrop.open [data-act="cancel"]').click();
  await page.waitForSelector('.modal-backdrop.open', { state: 'detached', timeout: 5000 });
  check(await page.locator('#fg-packing-detail [data-pk-field="packed"]').count() === 0, 'declining the Edit Packing confirmation leaves fields locked');
  check((await page.locator('#fg-packing-detail').innerText()).includes('Sudah Disubmit'), 'declining the Edit Packing confirmation leaves the badge as Sudah Disubmit');

  // -------------------------------------------------------------------
  // PACK-EDIT-05 (confirmed path): confirming unlocks the fields and
  // shows the MODE EDIT indicator.
  // -------------------------------------------------------------------
  await page.locator('#fg-edit-packing-store').click();
  await page.waitForSelector('.modal-backdrop.open .modal-body', { timeout: 5000 });
  await page.locator('.modal-backdrop.open [data-act="confirm"]').click();
  await page.waitForSelector('.modal-backdrop.open', { state: 'detached', timeout: 5000 });
  check(await page.locator('#fg-packing-detail [data-pk-field="packed"]').count() === 1, 'PACK-EDIT-05: confirming Edit Packing unlocks Actual Packing as a real editable input');
  check((await page.locator('#fg-packing-detail').innerText()).includes('MODE EDIT'), 'PACK-EDIT-05: a clear MODE EDIT indicator is shown while editing');
  check(await page.locator('#fg-cancel-edit-packing').count() === 1, 'Edit mode shows a "Batal Edit" button');
  check(await page.locator('#fg-save-packing-correction').count() === 1, 'Edit mode shows a "Simpan Perubahan" button');
  check(await noHorizontalScroll(page), 'PACK-EDIT-15: MODE EDIT view at mobile width (390px) has no horizontal page scroll');

  // -------------------------------------------------------------------
  // PACK-EDIT-06: enter Edit mode (already done above), make NO changes,
  // click Batal Edit -> still Sudah Disubmit, still locked.
  // -------------------------------------------------------------------
  await page.locator('#fg-cancel-edit-packing').click();
  check(await page.locator('#fg-packing-detail [data-pk-field="packed"]').count() === 0, 'PACK-EDIT-06: after Batal Edit with no changes, fields are locked again');
  check((await page.locator('#fg-packing-detail').innerText()).includes('Sudah Disubmit'), 'PACK-EDIT-06: after Batal Edit with no changes, status is STILL Sudah Disubmit');
  check(await page.locator('#fg-edit-packing-store').count() === 1, 'PACK-EDIT-06: Edit Packing is available again after Batal Edit');

  // -------------------------------------------------------------------
  // Enter Edit mode again and make a REAL change (Reject 0 -> 1 + a
  // note), then Simpan Perubahan -> Perlu Submit Ulang, locked again.
  // (Server-side proof that a no-op save never fabricates this state,
  // and that a real change always does, lives in PHP's own PACK-EDIT-07/
  // 08 — this is the same flow driven through the real UI end to end.)
  // -------------------------------------------------------------------
  await page.locator('#fg-edit-packing-store').click();
  await page.waitForSelector('.modal-backdrop.open .modal-body', { timeout: 5000 });
  await page.locator('.modal-backdrop.open [data-act="confirm"]').click();
  await page.waitForSelector('.modal-backdrop.open', { state: 'detached', timeout: 5000 });
  const dirtyIndicator = page.locator('#fg-packing-dirty-indicator');
  check(!(await dirtyIndicator.isVisible()), 'the "Perubahan belum disimpan" hint is not shown before anything is touched');
  await page.locator('#fg-packing-detail [data-pk-field="reject"]').first().fill('1');
  await page.locator('#fg-packing-detail [data-pk-field="reject"]').first().dispatchEvent('input');
  await page.locator('#fg-packing-detail [data-pk-field="notes"]').first().fill('Ada 1 pcs reject ditemukan setelah submit');
  check(await dirtyIndicator.isVisible(), 'the "Perubahan belum disimpan" hint appears once a field is actually touched in Edit mode');
  await page.locator('#fg-save-packing-correction').click();
  await waitForChipText(page, 'P2 TEST STORE A', 'Perlu Submit Ulang');

  await openStore(page, 'P2 TEST STORE A');
  const detailText2 = await page.locator('#fg-packing-detail').innerText();
  check(detailText2.includes('Perlu Submit Ulang'), 'PACK-EDIT-08: after saving a REAL correction, the badge shows Perlu Submit Ulang');
  check(await page.locator('#fg-packing-detail [data-pk-field="reject"]').count() === 0, 'PACK-EDIT-08: fields re-lock after the correction is saved');
  check(await page.locator('#fg-resubmit-packing-store').count() === 1, 'PACK-EDIT-08: an active "Submit Ulang Packing" button is now shown');
  check(await page.locator('#fg-edit-packing-store').count() === 1, 'Edit Packing remains available from the Perlu Submit Ulang view too');

  // -------------------------------------------------------------------
  // PACK-EDIT-09: reload -> still Perlu Submit Ulang (real, persisted).
  // -------------------------------------------------------------------
  await page.reload({ waitUntil: 'load' });
  await page.waitForSelector('#fg-store-chip-row .fg-store-chip', { timeout: 15000 });
  const chipATextReload = await (await chipByStoreName(page, 'P2 TEST STORE A')).innerText();
  check(chipATextReload.includes('Perlu Submit Ulang'), `PACK-EDIT-09: after a real page reload, Store A STILL shows Perlu Submit Ulang (got: ${JSON.stringify(chipATextReload)})`);

  // -------------------------------------------------------------------
  // PACK-EDIT-10: Submit Ulang Packing -> Sudah Disubmit again, locked.
  // -------------------------------------------------------------------
  await openStore(page, 'P2 TEST STORE A');
  await page.locator('#fg-resubmit-packing-store').click();
  await waitForChipText(page, 'P2 TEST STORE A', 'Sudah Disubmit');
  await openStore(page, 'P2 TEST STORE A');
  const detailText3 = await page.locator('#fg-packing-detail').innerText();
  check(detailText3.includes('Sudah Disubmit'), 'PACK-EDIT-10: after Submit Ulang Packing, Store A is Sudah Disubmit again');
  check(await page.locator('#fg-packing-detail [data-pk-field="reject"]').count() === 0, 'PACK-EDIT-10: fields are locked again after resubmit');
  check(await page.locator('#fg-resubmit-packing-store').count() === 0, 'PACK-EDIT-10: the "Submit Ulang Packing" button is gone once resubmitted');
  check(await page.locator('#fg-edit-packing-store').count() === 1, 'PACK-EDIT-10: "Edit Packing" is available again from the fresh Sudah Disubmit view');

  // -------------------------------------------------------------------
  // PACK-EDIT-12: Store B was never touched by any of this -> stays
  // clearly different.
  // -------------------------------------------------------------------
  const chipBText = await (await chipByStoreName(page, 'P2 TEST STORE B')).innerText();
  check(!chipBText.includes('Sudah Disubmit') && !chipBText.includes('Perlu Submit Ulang'), `PACK-EDIT-12: Store B (never touched) has no submission-derived status at all (got: ${JSON.stringify(chipBText)})`);
  check(chipBText.includes('Belum Mulai'), `PACK-EDIT-12: Store B still shows Belum Mulai (got: ${JSON.stringify(chipBText)})`);

  check(await noHorizontalScroll(page), 'PACK-EDIT-15: final Packing view at mobile width (390px) still has no horizontal page scroll');
  check(consoleErrors.length === 0, `no browser console errors: ${JSON.stringify(consoleErrors)}`);
} catch (e) {
  console.error('EXCEPTION during smoke check: ' + (e && e.stack ? e.stack : e));
  console.error('console errors so far: ' + JSON.stringify(consoleErrors));
  if (page) {
    try {
      console.error('#fg-store-chip-row HTML at failure: ' + (await page.locator('#fg-store-chip-row').innerHTML()));
    } catch (e2) { /* ignore */ }
    try {
      console.error('#fg-packing-detail HTML at failure: ' + (await page.locator('#fg-packing-detail').innerHTML()));
    } catch (e2) { /* ignore */ }
  }
  failures.push('unhandled exception: ' + e);
} finally {
  await browser.close();
}

if (failures.length > 0) {
  console.error(`\n--- PACK-EDIT SMOKE CHECK FAILED (${failures.length} failing) ---`);
  process.exit(1);
}
console.log('\n--- PACK-EDIT SMOKE CHECK PASSED ---');
process.exit(0);
