// Real headless-browser smoke check (NOT part of the regular PHP suite) for
// the "FINAL FIX — persist real Packing submission state per store" pass.
// Invoked by run-production-division-fg-rework.sh right after
// ProductionDivisionFgReworkTest.php exits 0, against the SAME live
// php -S server + MariaDB + the exact FG batch Part M (PACK-SUBMIT-00)
// just created and left in DRAFT status.
//
// Covers PACK-SUBMIT-01..08 (PACK-SUBMIT-09/10 are proven directly at
// the API/DB level, in the PHP suite itself, against a fifth store this
// script never touches). Logs in through the REAL /_admin-login/ HTML
// form, drives Store A/C/D's own "Submit Packing" button exactly as an
// operator would, edits Store A again via Breakdown Toko in FG
// Verifikasi, and confirms a SECOND, independently-authenticated browser
// session sees the same real, persisted submission state.
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

const packingUrl = `/_ui-preview/index.php?page=fg-packing&tanggal=${TANGGAL}&factoryId=${FACTORY_ID}&step=packing`;
const verifikasiUrl = `/_ui-preview/index.php?page=fg-packing&tanggal=${TANGGAL}&factoryId=${FACTORY_ID}&step=verifikasi`;

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

const consoleErrors = [];
let page = null;
const browser = await chromium.launch({ headless: true });

try {
  page = await browser.newPage();
  page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });

  check(await login(page), 'real login form authenticated as ADMIN');

  await page.goto(`${BASE_URL}${packingUrl}`, { waitUntil: 'load' });
  await page.waitForSelector('#fg-store-chip-row .fg-store-chip', { timeout: 15000 });

  // -------------------------------------------------------------------
  // PACK-SUBMIT-01: Store A, packed = target (10), submit -> Sudah Disubmit.
  // -------------------------------------------------------------------
  await openStore(page, 'P2 TEST STORE A');
  await page.locator('#fg-packing-detail .pk-sesuai button[data-value="sesuai"]').first().click();
  let submitBtn = page.locator('#fg-submit-packing-store');
  check(await submitBtn.count() === 1, 'Store A: active Submit Packing button present before first submit');
  await submitBtn.click();
  await page.waitForFunction(
    (name) => Array.from(document.querySelectorAll('.fg-store-chip')).some((c) => c.innerText.includes(name) && c.innerText.includes('Sudah Disubmit')),
    'P2 TEST STORE A',
    { timeout: 15000 }
  );
  let chipA = await chipByStoreName(page, 'P2 TEST STORE A');
  check((await chipA.innerText()).includes('Sudah Disubmit'), 'PACK-SUBMIT-01: Store A (packed = target = 10) shows Sudah Disubmit after submit');

  // -------------------------------------------------------------------
  // PACK-SUBMIT-02: Store C, packed = 8 (BELOW target 10), Tidak Sesuai +
  // note, submit -> STILL Sudah Disubmit (submission is about the ACTION
  // completing, never about matching the target).
  // -------------------------------------------------------------------
  await openStore(page, 'P2 TEST STORE C');
  await page.locator('#fg-packing-detail .pk-sesuai button[data-value="tidak_sesuai"]').first().click();
  await page.locator('#fg-packing-detail [data-pk-field="packed"]').first().fill('8');
  await page.locator('#fg-packing-detail [data-pk-field="notes"]').first().fill('Baru 8 pcs siap, sisanya menyusul besok');
  await page.locator('#fg-submit-packing-store').click();
  await page.waitForFunction(
    (name) => Array.from(document.querySelectorAll('.fg-store-chip')).some((c) => c.innerText.includes(name) && c.innerText.includes('Sudah Disubmit')),
    'P2 TEST STORE C',
    { timeout: 15000 }
  );
  let chipC = await chipByStoreName(page, 'P2 TEST STORE C');
  check((await chipC.innerText()).includes('Sudah Disubmit'), 'PACK-SUBMIT-02: Store C (packed 8 < target 10) STILL shows Sudah Disubmit');

  // -------------------------------------------------------------------
  // PACK-SUBMIT-03: Store D, packed = 0, Tidak Sesuai + valid discrepancy
  // note, submit -> STILL Sudah Disubmit.
  // -------------------------------------------------------------------
  await openStore(page, 'P2 TEST STORE D');
  await page.locator('#fg-packing-detail .pk-sesuai button[data-value="tidak_sesuai"]').first().click();
  // packed input already defaults to 0 — left untouched.
  await page.locator('#fg-packing-detail [data-pk-field="notes"]').first().fill('Belum ada yang siap dipacking hari ini');
  await page.locator('#fg-submit-packing-store').click();
  await page.waitForFunction(
    (name) => Array.from(document.querySelectorAll('.fg-store-chip')).some((c) => c.innerText.includes(name) && c.innerText.includes('Sudah Disubmit')),
    'P2 TEST STORE D',
    { timeout: 15000 }
  );
  let chipD = await chipByStoreName(page, 'P2 TEST STORE D');
  check((await chipD.innerText()).includes('Sudah Disubmit'), 'PACK-SUBMIT-03: Store D (packed 0, valid Tidak Sesuai note) STILL shows Sudah Disubmit');

  // -------------------------------------------------------------------
  // PACK-SUBMIT-04: reload page -> Store C (from -02) still Sudah Disubmit.
  // -------------------------------------------------------------------
  await page.reload({ waitUntil: 'load' });
  await page.waitForSelector('#fg-store-chip-row .fg-store-chip', { timeout: 15000 });
  chipC = await chipByStoreName(page, 'P2 TEST STORE C');
  check((await chipC.innerText()).includes('Sudah Disubmit'), 'PACK-SUBMIT-04: after a real page reload, Store C STILL shows Sudah Disubmit');

  // -------------------------------------------------------------------
  // PACK-SUBMIT-05: a SECOND, independently-authenticated browser
  // session opens the same page and sees the same real, persisted state.
  // -------------------------------------------------------------------
  const context2 = await browser.newContext();
  const page2 = await context2.newPage();
  check(await login(page2), 'second session: real login form authenticated as ADMIN');
  await page2.goto(`${BASE_URL}${packingUrl}`, { waitUntil: 'load' });
  await page2.waitForSelector('#fg-store-chip-row .fg-store-chip', { timeout: 15000 });
  const chipASession2 = await chipByStoreName(page2, 'P2 TEST STORE A');
  check((await chipASession2.innerText()).includes('Sudah Disubmit'), 'PACK-SUBMIT-05: a second, independent session also sees Store A as Sudah Disubmit');
  await context2.close();

  // -------------------------------------------------------------------
  // PACK-SUBMIT-06: Store B was never touched anywhere -> stays clearly
  // different (Belum Mulai) even while Store A/C/D are Sudah Disubmit.
  // -------------------------------------------------------------------
  const chipB = await chipByStoreName(page, 'P2 TEST STORE B');
  const chipBText = await chipB.innerText();
  check(!chipBText.includes('Sudah Disubmit'), `PACK-SUBMIT-06: Store B (never touched) is NOT Sudah Disubmit (got: ${JSON.stringify(chipBText)})`);
  check(chipBText.includes('Belum Mulai'), `PACK-SUBMIT-06: Store B still shows Belum Mulai (got: ${JSON.stringify(chipBText)})`);

  // -------------------------------------------------------------------
  // PACK-SUBMIT-07: edit Store A again via Breakdown Toko's OWN save (FG
  // Verifikasi) after it was already submitted in Packing -> status
  // becomes Perlu Submit Ulang.
  // -------------------------------------------------------------------
  await page.goto(`${BASE_URL}${verifikasiUrl}`, { waitUntil: 'load' });
  await page.locator('#fg-mode-toko').click();
  await page.waitForSelector('#fg-breakdown-panel .fg-card[data-product-id]', { timeout: 15000 });
  const cardA = page.locator('#fg-breakdown-panel .fg-card[data-product-id]', { hasText: 'P2 TEST STORE A' });
  check(await cardA.count() === 1, 'Breakdown Toko: Store A\'s own product card is present');
  await cardA.locator('[data-bt-field="reject"]').first().fill('1');
  await cardA.locator('[data-bt-field="notes"]').first().fill('Ada 1 pcs reject ditemukan setelah packing disubmit');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load', timeout: 20000 }).catch(() => null),
    cardA.locator('.bt-save').click(),
  ]);
  // bt-save's own handler reloads the SAME (Verifikasi) page ~600ms after
  // a successful save — wait past that before navigating away.
  await page.waitForTimeout(1200);

  await page.goto(`${BASE_URL}${packingUrl}`, { waitUntil: 'load' });
  await page.waitForSelector('#fg-store-chip-row .fg-store-chip', { timeout: 15000 });
  chipA = await chipByStoreName(page, 'P2 TEST STORE A');
  const chipATextAfterEdit = await chipA.innerText();
  check(chipATextAfterEdit.includes('Perlu Submit Ulang'), `PACK-SUBMIT-07: Store A shows Perlu Submit Ulang after its Reject/Keterangan were edited via Breakdown Toko (got: ${JSON.stringify(chipATextAfterEdit)})`);
  check(!chipATextAfterEdit.includes('Sudah Disubmit'), 'PACK-SUBMIT-07: Store A no longer claims Sudah Disubmit once edited');

  // -------------------------------------------------------------------
  // PACK-SUBMIT-08: resubmitting Store A returns it to Sudah Disubmit,
  // with a fresh submission event.
  // -------------------------------------------------------------------
  await openStore(page, 'P2 TEST STORE A');
  const resubmitBtn = page.locator('#fg-submit-packing-store');
  check(await resubmitBtn.count() === 1, 'PACK-SUBMIT-08: an active resubmit button ("Submit Ulang Packing ...") is present for a Perlu Submit Ulang store');
  check((await resubmitBtn.innerText()).includes('Submit Ulang'), 'PACK-SUBMIT-08: the resubmit button is explicitly labeled as a re-submit, not a fresh one');
  await resubmitBtn.click();
  await page.waitForFunction(
    (name) => Array.from(document.querySelectorAll('.fg-store-chip')).some((c) => c.innerText.includes(name) && c.innerText.includes('Sudah Disubmit')),
    'P2 TEST STORE A',
    { timeout: 15000 }
  );
  chipA = await chipByStoreName(page, 'P2 TEST STORE A');
  check((await chipA.innerText()).includes('Sudah Disubmit'), 'PACK-SUBMIT-08: Store A is Sudah Disubmit again after resubmitting');

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
  console.error(`\n--- PACK-SUBMIT SMOKE CHECK FAILED (${failures.length} failing) ---`);
  process.exit(1);
}
console.log('\n--- PACK-SUBMIT SMOKE CHECK PASSED ---');
process.exit(0);
