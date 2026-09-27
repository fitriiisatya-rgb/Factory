// Real headless-browser smoke check (NOT part of the regular PHP suite) for
// the "HOTFIX LIVE UI — FG target 0 + Breakdown Toko not rendering" fix.
// Invoked by run-production-division-fg-rework.sh right after
// ProductionDivisionFgReworkTest.php exits 0, against the SAME live
// php -S server + MariaDB + the exact FG batch Part G (FG-UI-00..06) just
// created and deliberately left in DRAFT status.
//
// Covers FG-UI-03/04/05/09/11/12 — real browser/JS behavior that an
// API-only test cannot see (BUG 2 shipped despite every backend test
// passing, precisely because nothing exercised the actual click). Logs in
// through the REAL /api/_admin-login/ HTML form (never a curl-injected
// cookie), navigates to the REAL fg-packing.php page, clicks the actual
// "Breakdown Toko" button, and inspects the actual rendered DOM.
//
// UPDATED for the "Mobile-First FG Verifikasi + Packing per Toko" rework:
// markup is now stacked .fg-card divs (not a <table>), and FG Verifikasi's
// own Breakdown Toko view no longer has ANY packing controls at all —
// Actual Packing moved entirely to the separate FG Packing (per Toko)
// step, see _ui_smoke_mobile_fg.mjs's MOBILE-FG-10/11/12 for that. The
// former FG-UI-08 ("Packing Sesuai/Tidak Sesuai controls render per store
// row") check is retired here for that reason — it is not a dropped
// assertion, it moved to the correct place.
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
const pageErrors = [];

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
page.on('pageerror', (err) => { pageErrors.push(String(err)); });

try {
  // NOTE: api/_admin-login/'s own POST handler never actually reads/uses
  // its "?return=" param (it always renders a static "Login berhasil"
  // page regardless) — a real, pre-existing gap noted here but out of
  // scope for this hotfix (see task's explicit "do not expand scope"
  // instruction). So this drives the REAL login form for a REAL session
  // cookie, then navigates to fg-packing.php as its own separate step.
  const fgUrl = `/_ui-preview/index.php?page=fg-packing&tanggal=${TANGGAL}&factoryId=${FACTORY_ID}`;
  await page.goto(`${BASE_URL}/_admin-login/index.php`, { waitUntil: 'load' });
  await page.fill('input[name="username"]', ADMIN_USER);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.click('button[type="submit"]'),
  ]);
  check((await page.content()).includes('Login berhasil'), 'real login form authenticated as ADMIN');

  await page.goto(`${BASE_URL}${fgUrl}`, { waitUntil: 'load' });
  check(page.url().includes('page=fg-packing'), 'navigated to the real fg-packing.php page with the live session cookie');

  // FG-UI-01 (re-verified at the actual rendered page, not just the API)
  const perProdukRows = await page.locator('#fg-perproduk-wrap .fg-card').count();
  check(perProdukRows === 2, `Per Produk shows exactly 2 cards (got ${perProdukRows})`);

  const versionBefore = await page.getAttribute('#fg-form', 'data-expected-version');

  const modeToko = page.locator('#fg-mode-toko');
  check(await modeToko.count() === 1, '"Breakdown Toko" toggle button exists');
  await modeToko.click();

  // FG-UI-03: real renderer/data-source switch, not only a CSS class flip —
  // wait for the ACTUAL fetched product blocks to land in the DOM.
  await page.waitForSelector('#fg-breakdown-panel .fg-card[data-product-id]', { timeout: 15000 });
  const blockCount = await page.locator('#fg-breakdown-panel .fg-card[data-product-id]').count();
  check(blockCount === 2, `Breakdown Toko renders one card per visible product (got ${blockCount}, expected 2)`);

  const perProdukDisplay = await page.locator('#fg-perproduk-wrap').evaluate((el) => getComputedStyle(el).display);
  check(perProdukDisplay === 'none', 'Per Produk cards are actually hidden while in Breakdown Toko mode (FG-UI-03)');

  const panelText = await page.locator('#fg-breakdown-panel').innerText();
  check(panelText.includes('P2 TEST STORE A'), 'Breakdown Toko shows store name "P2 TEST STORE A" (FG-UI-04)');
  check(panelText.includes('P2 TEST STORE B'), 'Breakdown Toko shows store name "P2 TEST STORE B" (FG-UI-04)');
  check(!panelText.includes('P2 TEST STORE C'), 'Store C (target 0) is never rendered (FG-UI-05)');
  check(panelText.includes('BOLLEN LILIT COKLAT'), 'Breakdown Toko shows product name BOLLEN LILIT COKLAT');
  check(panelText.includes('CHOCO CUBE 12'), 'Breakdown Toko shows product name CHOCO CUBE 12');

  const storeRowCount = await page.locator('#fg-breakdown-panel [data-store-id]').filter({ has: page.locator('.bt-verified-sesuai') }).count();
  check(storeRowCount === 4, `exactly 4 store rows total across both products (2 stores x 2 products), got ${storeRowCount}`);

  const verifiedSesuaiGroups = await page.locator('#fg-breakdown-panel .bt-verified-sesuai').count();
  check(verifiedSesuaiGroups === 4, `Verified Sesuai/Tidak Sesuai controls render per store row, got ${verifiedSesuaiGroups}`);
  check(await page.locator('#fg-breakdown-panel .bt-packing-sesuai').count() === 0, 'Breakdown Toko in FG Verifikasi has NO packing controls at all — Packing moved entirely to the separate FG Packing step');

  // FG-UI-09: actual input enables only when Tidak Sesuai. The Sesuai
  // group and the Actual Verified input live in SEPARATE sibling row divs
  // that share the same [data-store-id] (never nested one inside the
  // other), so the input must be looked up by that shared id, not as a
  // descendant of the row the Sesuai group itself is in.
  const firstProductBlock = page.locator('#fg-breakdown-panel .fg-card[data-product-id]').first();
  const firstSesuaiRow = firstProductBlock.locator('[data-store-id]').filter({ has: page.locator('.bt-verified-sesuai') }).first();
  const firstStoreId = await firstSesuaiRow.getAttribute('data-store-id');
  const fgVerifiedInput = firstProductBlock.locator('[data-store-id="' + firstStoreId + '"] [data-bt-field="fgVerified"]').first();
  await firstSesuaiRow.locator('.bt-verified-sesuai button[data-value="sesuai"]').click();
  check(await fgVerifiedInput.isDisabled(), 'Actual Verified input disables when "Sesuai" is clicked (FG-UI-09)');
  await firstSesuaiRow.locator('.bt-verified-sesuai button[data-value="tidak_sesuai"]').click();
  check(!(await fgVerifiedInput.isDisabled()), 'Actual Verified input re-enables when "Tidak Sesuai" is clicked (FG-UI-09)');

  const rejectCount = await page.locator('#fg-breakdown-panel [data-bt-field="reject"]').count();
  const hilangCount = await page.locator('#fg-breakdown-panel [data-bt-field="hilang"]').count();
  const notesCount = await page.locator('#fg-breakdown-panel [data-bt-field="notes"]').count();
  check(rejectCount === 4 && hilangCount === 4 && notesCount === 4, `Reject/Hilang/Keterangan render per store row (FG-UI-10): reject=${rejectCount} hilang=${hilangCount} notes=${notesCount}`);

  // FG-UI-11: switching back to Per Produk must not duplicate/alter data —
  // nothing was saved, so the batch's own version must be untouched.
  await page.locator('#fg-mode-produk').click();
  await page.waitForSelector('#fg-perproduk-wrap', { state: 'visible', timeout: 5000 });
  const backRows = await page.locator('#fg-perproduk-wrap .fg-card').count();
  check(backRows === 2, `switching back to Per Produk still shows exactly 2 cards, no duplication (got ${backRows})`);
  const versionAfter = await page.getAttribute('#fg-form', 'data-expected-version');
  check(versionBefore === versionAfter, `viewing Breakdown Toko and switching back never wrote anything (version unchanged: ${versionBefore} -> ${versionAfter})`);

  check(consoleErrors.length === 0, `no browser console errors (FG-UI-12): ${JSON.stringify(consoleErrors)}`);
  check(pageErrors.length === 0, `no uncaught page/JS errors (FG-UI-12): ${JSON.stringify(pageErrors)}`);
} catch (e) {
  console.error('EXCEPTION during smoke check: ' + (e && e.stack ? e.stack : e));
  console.error('console errors so far: ' + JSON.stringify(consoleErrors));
  console.error('page errors so far: ' + JSON.stringify(pageErrors));
  try {
    console.error('breakdown panel HTML at failure: ' + (await page.locator('#fg-breakdown-panel').innerHTML()));
  } catch (e2) {
    console.error('(could not read #fg-breakdown-panel innerHTML: ' + e2 + ')');
  }
  failures.push('unhandled exception: ' + e);
} finally {
  await browser.close();
}

if (failures.length > 0) {
  console.error(`\n--- UI SMOKE CHECK FAILED (${failures.length} failing) ---`);
  process.exit(1);
}
console.log('\n--- UI SMOKE CHECK PASSED ---');
process.exit(0);
