// Real headless-browser smoke check (NOT part of the regular PHP suite) for
// the "SECURITY HOTFIX — escape all dynamic FG UI output" pass.
// Invoked by run-production-division-fg-rework.sh right after
// ProductionDivisionFgReworkTest.php exits 0, against the SAME live
// php -S server + MariaDB + the exact FG batch Part J (FG-XSS-00) just
// created and deliberately left in DRAFT status.
//
// Covers FG-XSS-01..05 — proves the real rendered DOM, not just the raw
// JSON payload, treats a store name, a product name, and a Keterangan
// value that are each a raw <script>/<img onerror> string as INERT TEXT,
// in both FG Verifikasi's Breakdown Toko and FG Packing's per-Toko view.
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

const XSS_STORE_NAME = '<script>alert(1)</script>';
const XSS_PRODUCT_NAME = '<img src=x onerror=alert(1)>';
const XSS_NOTES = '"><img src=x onerror=alert(1)>';
const NORMAL_NOTES = 'Sudah pas & lengkap, tidak ada masalah';

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
const dialogsFired = [];

let page = null;
const browser = await chromium.launch({ headless: true });
try {
  page = await browser.newPage();
  page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
  page.on('pageerror', (err) => { pageErrors.push(String(err)); });
  // A real alert()/confirm()/prompt() firing means a payload actually
  // executed as script — the single strongest signal an escaping fix
  // failed. Auto-dismiss so it can never hang the run, but record it.
  page.on('dialog', async (d) => { dialogsFired.push(d.message()); await d.dismiss(); });

  await page.goto(`${BASE_URL}/_admin-login/index.php`, { waitUntil: 'load' });
  await page.fill('input[name="username"]', ADMIN_USER);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.click('button[type="submit"]'),
  ]);
  check((await page.content()).includes('Login berhasil'), 'real login form authenticated as ADMIN');

  // -----------------------------------------------------------------
  // FG-XSS-01 / FG-XSS-02 / FG-XSS-03 (FG Verifikasi — Breakdown Toko)
  // -----------------------------------------------------------------
  const verifikasiUrl = `/_ui-preview/index.php?page=fg-packing&tanggal=${TANGGAL}&factoryId=${FACTORY_ID}&step=verifikasi`;
  await page.goto(`${BASE_URL}${verifikasiUrl}`, { waitUntil: 'load' });
  check(page.url().includes('page=fg-packing'), 'navigated to the real fg-packing.php Verifikasi step with the live session cookie');

  // Even the collapsed Per Produk card (read-only aggregate row, product
  // already exploded) must render the product name as literal text.
  const perProdukText = await page.locator('#fg-perproduk-wrap').innerText();
  check(perProdukText.includes(XSS_PRODUCT_NAME), `FG-XSS-02: Per Produk card shows the raw product name payload as literal text (got: ${JSON.stringify(perProdukText.slice(0, 200))})`);

  await page.locator('#fg-mode-toko').click();
  await page.waitForSelector('#fg-breakdown-panel .fg-card[data-product-id]', { timeout: 15000 });

  const panelText = await page.locator('#fg-breakdown-panel').innerText();
  check(panelText.includes(XSS_STORE_NAME), 'FG-XSS-01: Breakdown Toko shows the raw store name payload as literal text, no execution');
  check(panelText.includes(XSS_PRODUCT_NAME), 'FG-XSS-02: Breakdown Toko shows the raw product name payload as literal text, no execution');

  // The payload's own <script>/<img onerror> tags must never become real
  // DOM elements INSIDE the innerHTML-rendered panel — only literal text
  // nodes / escaped attribute values. Scoped to the panel itself (not
  // the whole document): the page's OWN pre-existing inline <script>
  // legitimately embeds productName verbatim as JSON string DATA (via
  // json_encode(visibleProducts)) for its unrelated, safe, non-innerHTML
  // use elsewhere — that legitimate JSON text also contains the
  // substring "alert(1)" and would otherwise be a false positive here.
  const liveInjection = await page.evaluate(() => ({
    scripts: document.querySelectorAll('#fg-breakdown-panel script').length,
    onerrorImgs: document.querySelectorAll('#fg-breakdown-panel img[onerror]').length,
  }));
  check(liveInjection.scripts === 0, 'FG-XSS-01: no live <script> element was ever created inside the Breakdown Toko panel');
  check(liveInjection.onerrorImgs === 0, 'FG-XSS-02/03: no live <img onerror=...> element was ever created inside the Breakdown Toko panel');

  const notesInputs = page.locator('#fg-breakdown-panel [data-bt-field="notes"]');
  const notesValues = await notesInputs.evaluateAll((els) => els.map((e) => e.value));
  check(notesValues.includes(XSS_NOTES), `FG-XSS-03: Keterangan input holds the exact raw payload as its .value, unmangled (got: ${JSON.stringify(notesValues)})`);
  check(notesValues.includes(NORMAL_NOTES), `FG-XSS-05: a normal Indonesian Keterangan ("${NORMAL_NOTES}") rides along unchanged (got: ${JSON.stringify(notesValues)})`);

  // -----------------------------------------------------------------
  // FG-XSS-03 (continued) / FG-XSS-01 / FG-XSS-02 — same fixture as seen
  // from FG Packing's per-Toko view, after a real reload.
  // -----------------------------------------------------------------
  const packingUrl = `/_ui-preview/index.php?page=fg-packing&tanggal=${TANGGAL}&factoryId=${FACTORY_ID}&step=packing`;
  await page.goto(`${BASE_URL}${packingUrl}`, { waitUntil: 'load' });
  await page.waitForSelector('#fg-store-chip-row .fg-store-chip', { timeout: 15000 });

  const chipText = await page.locator('#fg-store-chip-row').innerText();
  check(chipText.includes(XSS_STORE_NAME), 'FG-XSS-01: Packing store chip shows the raw store name payload as literal text');

  await page.locator('#fg-store-chip-row .fg-store-chip').first().click();
  await page.waitForSelector('#fg-packing-detail .fg-packing-row', { timeout: 15000 });

  const detailText = await page.locator('#fg-packing-detail').innerText();
  check(detailText.includes(XSS_STORE_NAME), 'FG-XSS-01: Packing detail header shows the raw store name payload as literal text');
  check(detailText.includes(XSS_PRODUCT_NAME), 'FG-XSS-02: Packing detail card shows the raw product name payload as literal text');

  const packingLiveInjection = await page.evaluate(() => ({
    scripts: document.querySelectorAll('#fg-packing-detail script').length,
    onerrorImgs: document.querySelectorAll('#fg-packing-detail img[onerror]').length,
  }));
  check(packingLiveInjection.scripts === 0, 'FG-XSS-01 (Packing): no live <script> element was ever created inside the Packing detail panel');
  check(packingLiveInjection.onerrorImgs === 0, 'FG-XSS-02/03 (Packing): no live <img onerror=...> element was ever created inside the Packing detail panel');

  const packingNotesValues = await page.locator('#fg-packing-detail [data-pk-field="notes"]').evaluateAll((els) => els.map((e) => e.value));
  check(packingNotesValues.includes(XSS_NOTES), `FG-XSS-03: Packing Keterangan input holds the exact raw payload as its .value (got: ${JSON.stringify(packingNotesValues)})`);
  check(packingNotesValues.includes(NORMAL_NOTES), `FG-XSS-05: Packing view shows the normal Keterangan unchanged too (got: ${JSON.stringify(packingNotesValues)})`);

  // -----------------------------------------------------------------
  // FG-XSS-04 — an API error message containing markup-like text must
  // render as plain text, never be inserted as HTML. initPacking()'s own
  // fetch is intercepted here to force exactly that response shape.
  // -----------------------------------------------------------------
  const XSS_ERROR_MESSAGE = '<img src=x onerror=window.__xssFired=true>';
  await page.route('**/api/fg/*/items/*/stores', (route) => route.fulfill({
    status: 400,
    contentType: 'application/json',
    body: JSON.stringify({ ok: false, code: 'TEST_XSS_ERROR', message: XSS_ERROR_MESSAGE }),
  }));
  await page.goto(`${BASE_URL}${packingUrl}`, { waitUntil: 'load' });
  await page.waitForSelector('#fg-packing-header .alert-danger', { timeout: 15000 });
  const headerText = await page.locator('#fg-packing-header').innerText();
  check(headerText.includes(XSS_ERROR_MESSAGE), `FG-XSS-04: API error message renders as literal text in the error banner (got: ${JSON.stringify(headerText)})`);
  const xssFired = await page.evaluate(() => window.__xssFired === true);
  check(!xssFired, 'FG-XSS-04: the error message payload never executed (window.__xssFired was never set)');
  await page.unroute('**/api/fg/*/items/*/stores');
  // The deliberate 400 above legitimately logs a "Failed to load
  // resource" console message for each intercepted request — expected
  // noise from forcing this error path, not a real regression (same
  // convention _ui_smoke_mobile_fg.mjs uses for its own deliberate
  // over-limit 400 check).
  consoleErrors.length = 0;

  check(dialogsFired.length === 0, `no alert()/confirm()/prompt() dialog ever fired across any XSS check: ${JSON.stringify(dialogsFired)}`);
  check(consoleErrors.length === 0, `no browser console errors: ${JSON.stringify(consoleErrors)}`);
  check(pageErrors.length === 0, `no uncaught page/JS errors: ${JSON.stringify(pageErrors)}`);
} catch (e) {
  console.error('EXCEPTION during smoke check: ' + (e && e.stack ? e.stack : e));
  console.error('console errors so far: ' + JSON.stringify(consoleErrors));
  console.error('page errors so far: ' + JSON.stringify(pageErrors));
  console.error('dialogs fired so far: ' + JSON.stringify(dialogsFired));
  if (page) {
    try {
      console.error('#fg-breakdown-panel HTML at failure: ' + (await page.locator('#fg-breakdown-panel').innerHTML()));
    } catch (e2) { /* not on that page at failure time */ }
    try {
      console.error('#fg-packing-detail HTML at failure: ' + (await page.locator('#fg-packing-detail').innerHTML()));
    } catch (e2) { /* not on that page at failure time */ }
  }
  failures.push('unhandled exception: ' + e);
} finally {
  await browser.close();
}

if (failures.length > 0) {
  console.error(`\n--- FG-XSS SMOKE CHECK FAILED (${failures.length} failing) ---`);
  process.exit(1);
}
console.log('\n--- FG-XSS SMOKE CHECK PASSED ---');
process.exit(0);
