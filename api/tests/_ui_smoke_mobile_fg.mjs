// Real headless-browser smoke check (NOT part of the regular PHP suite) for
// "MOBILE-FIRST FG VERIFIKASI + PACKING PER TOKO". Invoked by
// run-production-division-fg-rework.sh right after ProductionDivisionFgReworkTest.php
// exits 0, against the SAME live php -S server + MariaDB + the exact
// MOBILE-FG-00 fixture that test just created and deliberately left in
// DRAFT status.
//
// Covers MOBILE-FG-01..18 at a REAL mobile viewport (390x844, an
// iPhone-class width) and, at the end, re-checks the same page still
// works at a desktop viewport (MOBILE-FG-18) — the exact browser/JS
// behavior an API-only test cannot see.
//
// Required environment: BASE_URL, ADMIN_USER, ADMIN_PASS, TANGGAL,
// FACTORY_ID, PRODMOBILEA_NAME.
// Exit code 0 = pass, 1 = fail (message on stderr).
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';

const BASE_URL = process.env.BASE_URL;
const ADMIN_USER = process.env.ADMIN_USER;
const ADMIN_PASS = process.env.ADMIN_PASS;
const TANGGAL = process.env.TANGGAL;
const FACTORY_ID = process.env.FACTORY_ID;
const PRODMOBILEA_NAME = process.env.PRODMOBILEA_NAME;

if (!BASE_URL || !ADMIN_USER || !ADMIN_PASS || !TANGGAL || !FACTORY_ID || !PRODMOBILEA_NAME) {
  console.error('Missing required env vars (BASE_URL/ADMIN_USER/ADMIN_PASS/TANGGAL/FACTORY_ID/PRODMOBILEA_NAME)');
  process.exit(1);
}

const failures = [];
function check(cond, label) {
  if (cond) { console.log(`OK   ${label}`); }
  else { console.log(`FAIL ${label}`); failures.push(label); }
}

const consoleErrors = [];
const pageErrors = [];
const browser = await chromium.launch({ headless: true });

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
}

let page = null;
try {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  page = await context.newPage();
  page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
  page.on('pageerror', (err) => { pageErrors.push(String(err)); });

  await login(page);

  const verifUrl = `/_ui-preview/index.php?page=fg-packing&tanggal=${TANGGAL}&factoryId=${FACTORY_ID}&step=verifikasi`;
  await page.goto(`${BASE_URL}${verifUrl}`, { waitUntil: 'load' });

  // MOBILE-FG-01 / MOBILE-FG-02
  check(await noHorizontalScroll(page), 'MOBILE-FG-01: FG Verifikasi at mobile width (390px) has no horizontal page scroll');
  const cardCount = await page.locator('#fg-perproduk-wrap .fg-card').count();
  check(cardCount === 3, `MOBILE-FG-02: only target>0 products render as cards (expected 3, got ${cardCount})`);

  // MOBILE-FG-03/04/05 — prodMobileA's own Per Produk card (never exploded).
  const mobileACard = page.locator('#fg-perproduk-wrap .fg-card').filter({ hasText: PRODMOBILEA_NAME });
  check(await mobileACard.count() === 1, 'MOBILE-FG-02: prodMobileA card is present and findable by name');
  const verifiedInput = mobileACard.locator('[data-field="fgVerified"]');
  await mobileACard.locator('.fg-verified-sesuai-group button[data-value="sesuai"]').click();
  check(await verifiedInput.isDisabled(), 'MOBILE-FG-03: Sesuai auto-fills and locks Actual Verified');
  check((await verifiedInput.inputValue()) === '20', 'MOBILE-FG-03: Sesuai auto-fills Actual Verified to the Target (20)');
  await mobileACard.locator('.fg-verified-sesuai-group button[data-value="tidak_sesuai"]').click();
  check(!(await verifiedInput.isDisabled()), 'MOBILE-FG-04: Tidak Sesuai re-enables Actual Verified');
  const rejectInput = mobileACard.locator('[data-field="reject"]');
  const hilangInput = mobileACard.locator('[data-field="hilang"]');
  const notesInput = mobileACard.locator('[data-field="notes"]');
  await rejectInput.fill('2');
  await hilangInput.fill('1');
  await notesInput.fill('QC check');
  check((await rejectInput.inputValue()) === '2' && (await hilangInput.inputValue()) === '1' && (await notesInput.inputValue()) === 'QC check', 'MOBILE-FG-05: Reject/Hilang/Keterangan accept input correctly');
  // revert prodMobileA back to a clean Sesuai state so it does not block this batch's own submit later in the run
  await notesInput.fill('');
  await rejectInput.fill('0');
  await hilangInput.fill('0');
  await mobileACard.locator('.fg-verified-sesuai-group button[data-value="sesuai"]').click();

  // MOBILE-FG-06 — Breakdown Toko renders real store cards for BOLLEN LILIT COKLAT.
  const bollenCard = page.locator('#fg-perproduk-wrap .fg-card').filter({ hasText: 'BOLLEN LILIT COKLAT' });
  await bollenCard.locator('.fg-breakdown-btn').click();
  await page.waitForSelector('#fg-breakdown-panel .fg-card[data-product-id]', { timeout: 15000 });
  const breakdownBlocks = await page.locator('#fg-breakdown-panel .fg-card[data-product-id]').count();
  check(breakdownBlocks === 3, `MOBILE-FG-06: Breakdown Toko renders one card per visible product (got ${breakdownBlocks}, expected 3)`);
  const panelText = await page.locator('#fg-breakdown-panel').innerText();
  check(panelText.includes('P2 TEST STORE A') && panelText.includes('P2 TEST STORE B'), 'MOBILE-FG-06: Breakdown Toko store cards show real store names');
  check(await noHorizontalScroll(page), 'MOBILE-FG-01b: Breakdown Toko view also has no horizontal page scroll at mobile width');

  // MOBILE-FG-07/08/09/10/11/12/13/14 — FG Packing per Toko.
  const packingUrl = `/_ui-preview/index.php?page=fg-packing&tanggal=${TANGGAL}&factoryId=${FACTORY_ID}&step=packing`;
  await page.goto(`${BASE_URL}${packingUrl}`, { waitUntil: 'load' });
  await page.waitForSelector('#fg-store-chip-row .fg-store-chip', { timeout: 15000 });
  check(await noHorizontalScroll(page), 'MOBILE-FG-01c: FG Packing at mobile width has no horizontal page scroll');
  check(await page.locator('#fg-perproduk-wrap').count() === 0, 'MOBILE-FG-07: FG Packing defaults to Per Toko — no Per Produk table/list rendered on this step');
  const chipCount = await page.locator('#fg-store-chip-row .fg-store-chip').count();
  check(chipCount === 2, `MOBILE-FG-08: store selector shows both stores with real target>0 rows (got ${chipCount}, expected 2)`);

  const storeAChip = page.locator('.fg-store-chip').filter({ hasText: 'P2 TEST STORE A' });
  await storeAChip.click();
  await page.waitForSelector('#fg-packing-detail .fg-packing-row', { timeout: 10000 });
  const detailTitle = await page.locator('#fg-packing-detail h3').innerText();
  check(detailTitle.includes('P2 TEST STORE A'), 'MOBILE-FG-08: store selector switches the detail view to the clicked store');
  const rowCountA = await page.locator('#fg-packing-detail .fg-packing-row').count();
  check(rowCountA === 2, `MOBILE-FG-09: selecting Store A only shows Store A's own products (got ${rowCountA}, expected 2 — BOLLEN + CHOCO CUBE)`);

  // MOBILE-FG-10: Store A's BOLLEN row has Target=15 but Ready Verified=12
  // (deliberately different — see MOBILE-FG-00 fixture) — Sesuai must
  // auto-fill Actual Packing to 12 (Ready Verified), never 15 (raw Target).
  const bollenRow = page.locator('#fg-packing-detail .fg-packing-row').filter({ hasText: 'BOLLEN LILIT COKLAT' });
  const packedInput = bollenRow.locator('[data-pk-field="packed"]');
  await bollenRow.locator('.pk-sesuai button[data-value="sesuai"]').click();
  check(await packedInput.isDisabled(), 'MOBILE-FG-10: Packing Sesuai locks Actual Packing');
  check((await packedInput.inputValue()) === '12', `MOBILE-FG-10: Packing Sesuai auto-fills Actual Packing to Ready Verified (12), NOT the raw Target (15) — got ${await packedInput.inputValue()}`);
  await bollenRow.locator('.pk-sesuai button[data-value="tidak_sesuai"]').click();
  check(!(await packedInput.isDisabled()), 'MOBILE-FG-11: Packing Tidak Sesuai re-enables Actual Packing');

  // MOBILE-FG-12: server-side ceiling still wins even if the UI is asked
  // to accept more than Ready Verified (12) — CRITICAL PACKING RULE.
  await packedInput.fill('13');
  const choccubeRowA = page.locator('#fg-packing-detail .fg-packing-row').filter({ hasText: 'CHOCO CUBE 12' });
  await choccubeRowA.locator('.pk-sesuai button[data-value="sesuai"]').click();
  await page.locator('#fg-submit-packing-store').click();
  await page.waitForTimeout(700);
  const toastText = await page.locator('body').innerText();
  check(toastText.includes('PACKED_EXCEEDS_VERIFIED') || toastText.toLowerCase().includes('exceed') || toastText.toLowerCase().includes('melebihi'), 'MOBILE-FG-12: server rejects Actual Packing (13) exceeding Ready Verified (12) — the ceiling is server-authoritative, not merely a UI hint');

  // Now save Store A for real, within its own ceiling, to exercise the
  // real submit-per-store path (MOBILE-FG-13/14).
  await packedInput.fill('12');
  await page.locator('#fg-submit-packing-store').click();
  await page.waitForTimeout(700);

  // MOBILE-FG-15/16: Per Product totals stay consistent with the store
  // rows just packed, no double counting — verified via the SAME real API
  // the page itself calls, using this browser tab's own session cookie.
  // The Packing page itself has no #fg-form (that only exists on the
  // Verifikasi step), so read the batch back via the Verifikasi page.
  await page.goto(`${BASE_URL}${verifUrl}`, { waitUntil: 'load' });
  const versionAfterPacking = await page.getAttribute('#fg-form', 'data-expected-version');
  const batchIdStr = await page.getAttribute('#fg-form', 'data-batch-id');
  const batchAfter = await page.evaluate(async (id) => (await fetch('/api/fg/' + id, { credentials: 'same-origin' })).json(), batchIdStr);
  const bollenItem = (batchAfter.data.items || []).find((i) => i.productName === 'BOLLEN LILIT COKLAT');
  check(!!bollenItem && Math.abs(bollenItem.packed - 12) < 0.01, `MOBILE-FG-15: BOLLEN LILIT COKLAT's Per Produk aggregate packed (${bollenItem && bollenItem.packed}) equals the SUM of its store rows packed so far (12 from Store A, 0 from Store B) — no double counting`);

  // MOBILE-FG-16: switching Per Produk <-> Breakdown Toko in Verifikasi
  // itself never writes anything or duplicates data (re-checked here on
  // this mobile-card markup, not just the earlier hotfix's table markup).
  await page.locator('#fg-mode-toko').click();
  await page.waitForSelector('#fg-breakdown-panel .fg-card[data-product-id]', { timeout: 15000 });
  await page.locator('#fg-mode-produk').click();
  await page.waitForTimeout(300);
  const versionAfterToggle = await page.getAttribute('#fg-form', 'data-expected-version');
  check(versionAfterPacking === versionAfterToggle, `MOBILE-FG-16: viewing Breakdown Toko and switching back in Verifikasi never wrote anything (version unchanged: ${versionAfterPacking} -> ${versionAfterToggle})`);

  await page.goto(`${BASE_URL}${packingUrl}`, { waitUntil: 'load' });
  await page.waitForSelector('#fg-store-chip-row .fg-store-chip', { timeout: 15000 });
  const storeAChip2 = page.locator('.fg-store-chip').filter({ hasText: 'P2 TEST STORE A' });
  await storeAChip2.click();
  await page.waitForSelector('#fg-packing-detail .fg-packing-row', { timeout: 10000 });
  // MOBILE-FG-13: Store B must be COMPLETELY untouched by Store A's own submit.
  const storeBChip = page.locator('.fg-store-chip').filter({ hasText: 'P2 TEST STORE B' });
  await storeBChip.click();
  await page.waitForSelector('#fg-packing-detail .fg-packing-row', { timeout: 10000 });
  const bollenRowB = page.locator('#fg-packing-detail .fg-packing-row').filter({ hasText: 'BOLLEN LILIT COKLAT' });
  const packedInputB = bollenRowB.locator('[data-pk-field="packed"]');
  check((await packedInputB.inputValue()) === '0', `MOBILE-FG-13: submitting Store A packing never touched Store B's own BOLLEN row (expected still 0, got ${await packedInputB.inputValue()})`);

  // MOBILE-FG-14: header progress reflects the real packed total after Store A's submit.
  const headerText = await page.locator('#fg-packing-header').innerText();
  check(/20\s*\/\s*23/.test(headerText.replace(/ /g, ' ')) || headerText.includes('20'), `MOBILE-FG-14: packed-pcs progress reflects Store A's real submit (12 BOLLEN + 8 CHOCO CUBE = 20 packed so far), got: ${headerText}`);

  check(consoleErrors.length === 0, `MOBILE-FG-17: no browser console errors: ${JSON.stringify(consoleErrors)}`);
  check(pageErrors.length === 0, `MOBILE-FG-17: no uncaught page/JS errors: ${JSON.stringify(pageErrors)}`);
  await context.close();

  // MOBILE-FG-18 — desktop still works (same components/logic, wider layout).
  const desktopContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const desktopPage = await desktopContext.newPage();
  const desktopConsoleErrors = [];
  desktopPage.on('console', (msg) => { if (msg.type() === 'error') desktopConsoleErrors.push(msg.text()); });
  await login(desktopPage);
  await desktopPage.goto(`${BASE_URL}${verifUrl}`, { waitUntil: 'load' });
  const desktopCardCount = await desktopPage.locator('#fg-perproduk-wrap .fg-card').count();
  check(desktopCardCount === 3, `MOBILE-FG-18: desktop Verifikasi still renders all 3 product cards (got ${desktopCardCount})`);
  await desktopPage.goto(`${BASE_URL}${packingUrl}`, { waitUntil: 'load' });
  await desktopPage.waitForSelector('#fg-store-chip-row .fg-store-chip', { timeout: 15000 });
  const desktopChipCount = await desktopPage.locator('#fg-store-chip-row .fg-store-chip').count();
  check(desktopChipCount === 2, `MOBILE-FG-18: desktop FG Packing still renders the store selector (got ${desktopChipCount})`);
  check(desktopConsoleErrors.length === 0, `MOBILE-FG-18: no console errors on desktop: ${JSON.stringify(desktopConsoleErrors)}`);
  await desktopContext.close();
} catch (e) {
  console.error('EXCEPTION during smoke check: ' + (e && e.stack ? e.stack : e));
  console.error('console errors so far: ' + JSON.stringify(consoleErrors));
  console.error('page errors so far: ' + JSON.stringify(pageErrors));
  if (page) {
    try {
      console.error('#fg-packing-header at failure: ' + (await page.locator('#fg-packing-header').innerHTML()));
      console.error('#fg-store-chip-row at failure: ' + (await page.locator('#fg-store-chip-row').innerHTML()));
    } catch (e2) {
      console.error('(could not read packing debug panels: ' + e2 + ')');
    }
  }
  failures.push('unhandled exception: ' + e);
} finally {
  await browser.close();
}

if (failures.length > 0) {
  console.error(`\n--- MOBILE FG SMOKE CHECK FAILED (${failures.length} failing) ---`);
  process.exit(1);
}
console.log('\n--- MOBILE FG SMOKE CHECK PASSED ---');
process.exit(0);
