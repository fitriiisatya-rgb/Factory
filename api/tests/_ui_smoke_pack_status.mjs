// Real headless-browser smoke check (NOT part of the regular PHP suite) for
// the "LIVE UAT HOTFIX — clear packing submit status" fix.
// Invoked by run-production-division-fg-rework.sh right after
// ProductionDivisionFgReworkTest.php exits 0, against the SAME live
// php -S server + MariaDB + the exact FG batch Part L (PACK-STATUS-00)
// just created and left in DRAFT status.
//
// Covers PACK-STATUS-01..07 (PACK-STATUS-08 is proven directly at the
// API/DB level, in the PHP suite itself, against a third store this
// script never touches). Logs in through the REAL /_admin-login/ HTML
// form, navigates to the REAL fg-packing.php Packing step, and drives
// Store A's own "Submit Packing" button exactly as an operator would.
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
  if (cond) {
    console.log(`OK   ${label}`);
  } else {
    console.log(`FAIL ${label}`);
    failures.push(label);
  }
}

const consoleErrors = [];
let page = null;
const browser = await chromium.launch({ headless: true });

async function chipByStoreName(name) {
  return page.locator('.fg-store-chip', { hasText: name }).first();
}

try {
  page = await browser.newPage();
  page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });

  await page.goto(`${BASE_URL}/_admin-login/index.php`, { waitUntil: 'load' });
  await page.fill('input[name="username"]', ADMIN_USER);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.click('button[type="submit"]'),
  ]);
  check((await page.content()).includes('Login berhasil'), 'real login form authenticated as ADMIN');

  const packingUrl = `/_ui-preview/index.php?page=fg-packing&tanggal=${TANGGAL}&factoryId=${FACTORY_ID}&step=packing`;
  await page.goto(`${BASE_URL}${packingUrl}`, { waitUntil: 'load' });
  await page.waitForSelector('#fg-store-chip-row .fg-store-chip', { timeout: 15000 });

  // PACK-STATUS-01: before any submit, Store A is not labeled Sudah Disubmit.
  let chipA = await chipByStoreName('P2 TEST STORE A');
  check(await chipA.count() === 1, 'Store A chip is present');
  let chipAText = await chipA.innerText();
  check(!chipAText.includes('Sudah Disubmit'), `PACK-STATUS-01: before submit, Store A is NOT labeled Sudah Disubmit (got: ${JSON.stringify(chipAText)})`);
  check(chipAText.includes('Belum Mulai'), `PACK-STATUS-01: before submit, Store A shows Belum Mulai (got: ${JSON.stringify(chipAText)})`);

  await chipA.click();
  await page.waitForSelector('#fg-packing-detail .fg-packing-row[data-exploded="1"]', { timeout: 15000 });

  // Fill packing to match the store's own target via the real "Sesuai" control.
  const sesuaiBtn = page.locator('#fg-packing-detail .pk-sesuai button[data-value="sesuai"]').first();
  await sesuaiBtn.click();
  const packedInput = page.locator('#fg-packing-detail [data-pk-field="packed"]').first();
  check(await packedInput.isDisabled(), 'Sesuai auto-fills and locks Actual Packing (existing behavior, unchanged by this fix)');

  // PACK-STATUS-02: submit store packing successfully.
  const submitBtn = page.locator('#fg-submit-packing-store');
  check(await submitBtn.count() === 1, 'PACK-STATUS-02: active "Submit Packing" button is present before first submit');
  await submitBtn.click();
  await page.waitForFunction(
    () => {
      const chip = Array.from(document.querySelectorAll('.fg-store-chip')).find((c) => c.innerText.includes('P2 TEST STORE A'));
      return chip && chip.innerText.includes('Sudah Disubmit');
    },
    { timeout: 15000 }
  );

  // PACK-STATUS-03: store chip immediately shows Sudah Disubmit.
  chipA = await chipByStoreName('P2 TEST STORE A');
  chipAText = await chipA.innerText();
  check(chipAText.includes('Sudah Disubmit'), `PACK-STATUS-03: Store A chip immediately shows Sudah Disubmit after successful submit (got: ${JSON.stringify(chipAText)})`);

  // PACK-STATUS-04: submit button becomes disabled / final-state display.
  const disabledBtn = page.locator('#fg-packing-detail .fg-card-actions button', { hasText: 'Sudah Disubmit' });
  check(await disabledBtn.count() === 1, 'PACK-STATUS-04: action area now shows a "Sudah Disubmit" button');
  check(await disabledBtn.isDisabled(), 'PACK-STATUS-04: the "Sudah Disubmit" button is disabled/non-actionable');
  check(await page.locator('#fg-submit-packing-store').count() === 0, 'PACK-STATUS-04: the old active "Submit Packing" button no longer exists');

  // PACK-STATUS-05: reload persists (real browser reload, not JS memory).
  await page.reload({ waitUntil: 'load' });
  await page.waitForSelector('#fg-store-chip-row .fg-store-chip', { timeout: 15000 });
  chipA = await chipByStoreName('P2 TEST STORE A');
  chipAText = await chipA.innerText();
  check(chipAText.includes('Sudah Disubmit'), `PACK-STATUS-05: after a real page reload, Store A STILL shows Sudah Disubmit (got: ${JSON.stringify(chipAText)})`);
  await chipA.click();
  await page.waitForSelector('#fg-packing-detail .fg-packing-row[data-exploded="1"]', { timeout: 15000 });
  check(await page.locator('#fg-packing-detail .fg-card-actions button', { hasText: 'Sudah Disubmit' }).count() === 1, 'PACK-STATUS-05: after reload, the detail view\'s own action area also still shows Sudah Disubmit');

  // PACK-STATUS-06 / 07: Store B remains clearly different — submitting
  // Store A never touched it.
  const chipB = await chipByStoreName('P2 TEST STORE B');
  check(await chipB.count() === 1, 'Store B chip is present');
  const chipBText = await chipB.innerText();
  check(!chipBText.includes('Sudah Disubmit'), `PACK-STATUS-06/07: Store B remains clearly different (NOT Sudah Disubmit) after Store A's own submit (got: ${JSON.stringify(chipBText)})`);
  check(chipBText.includes('Belum Mulai'), `PACK-STATUS-06/07: Store B still shows Belum Mulai, completely untouched (got: ${JSON.stringify(chipBText)})`);

  // Extra (beyond the mandatory list): editing a completed store again
  // reverts it to an actionable Submit button — proves "Sudah Disubmit"
  // never lies about unsaved edits sitting on top of it.
  await chipA.click();
  await page.waitForSelector('#fg-packing-detail .fg-packing-row[data-exploded="1"]', { timeout: 15000 });
  const rejectInput = page.locator('#fg-packing-detail [data-pk-field="reject"]').first();
  await rejectInput.fill('1');
  await rejectInput.dispatchEvent('input');
  check(await page.locator('#fg-submit-packing-store').count() === 1, 'EXTRA: editing a "Sudah Disubmit" store\'s own inputs again reverts the action area to an active "Submit Packing" button (never silently stays claiming Sudah Disubmit over an unsaved edit)');
  check(await page.locator('#fg-packing-detail .fg-card-actions button', { hasText: 'Sudah Disubmit' }).count() === 0, 'EXTRA: the stale "Sudah Disubmit" button is gone the instant an edit is made');

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
  console.error(`\n--- PACK-STATUS SMOKE CHECK FAILED (${failures.length} failing) ---`);
  process.exit(1);
}
console.log('\n--- PACK-STATUS SMOKE CHECK PASSED ---');
process.exit(0);
