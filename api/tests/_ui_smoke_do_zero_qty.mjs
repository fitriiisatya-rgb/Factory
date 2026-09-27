// Real headless-browser smoke check (NOT part of the regular PHP suite) for
// the "LIVE UAT HOTFIX — hide zero-qty DO items" fix's print view.
// Invoked by run-production-division-fg-rework.sh right after
// ProductionDivisionFgReworkTest.php exits 0, against the SAME live
// php -S server + MariaDB + the exact DO Part K (DO-UI-00) just created
// (1 real item + 6 legacy zero-planned rows on the same document).
//
// Covers DO-UI-02 — the actual rendered print-do.php page, not just the
// JSON DoService::getDo() response (already asserted at the API level by
// DO-UI-01/03 in the PHP suite). Logs in through the REAL
// /_admin-login/ HTML form, then navigates to the REAL print-do.php page.
//
// Required environment: BASE_URL, ADMIN_USER, ADMIN_PASS, DO_ID.
// Exit code 0 = pass, 1 = fail (message on stderr).
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';

const BASE_URL = process.env.BASE_URL;
const ADMIN_USER = process.env.ADMIN_USER;
const ADMIN_PASS = process.env.ADMIN_PASS;
const DO_ID = process.env.DO_ID;

if (!BASE_URL || !ADMIN_USER || !ADMIN_PASS || !DO_ID) {
  console.error('Missing required env vars (BASE_URL/ADMIN_USER/ADMIN_PASS/DO_ID)');
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

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

try {
  await page.goto(`${BASE_URL}/_admin-login/index.php`, { waitUntil: 'load' });
  await page.fill('input[name="username"]', ADMIN_USER);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.click('button[type="submit"]'),
  ]);
  check((await page.content()).includes('Login berhasil'), 'real login form authenticated as ADMIN');

  // Screen view (delivery-order-detail.php) — same DoService::getDo()
  // source the print view below reuses, re-verified here at the actual
  // rendered DOM rather than only the raw JSON.
  await page.goto(`${BASE_URL}/_ui-preview/index.php?page=delivery-order-detail&doId=${DO_ID}`, { waitUntil: 'load' });
  const screenRows = await page.locator('.data-table tbody tr').count();
  check(screenRows === 1, `DO-UI-01 (rendered screen): expected exactly 1 product row, got ${screenRows}`);
  const screenText = await page.locator('.data-table').innerText();
  check(screenText.includes('CHOCO CUBE 12'), 'DO-UI-01 (rendered screen): shows the one real product, CHOCO CUBE 12');
  check(!screenText.includes('BLACKFOREST CHOCO CASTLE 16'), 'DO-UI-01 (rendered screen): a zero-qty padding product is never rendered');

  // Print view — print-do.php, real headless render.
  await page.goto(`${BASE_URL}/_ui-preview/print-do.php?doId=${DO_ID}`, { waitUntil: 'load' });
  const printRows = await page.locator('table tbody tr, .print-doc tbody tr').count();
  check(printRows === 1, `DO-UI-02: print view expected exactly 1 product row, got ${printRows}`);
  const printText = await page.locator('body').innerText();
  check(printText.includes('CHOCO CUBE 12'), 'DO-UI-02: print view shows the one real product, CHOCO CUBE 12');
  check(!printText.includes('BLACKFOREST CHOCO CASTLE 16'), 'DO-UI-02: print view never shows a zero-qty padding product');
  check(!printText.includes('BOLLEN COKLAT'), 'DO-UI-02: print view never shows a second zero-qty padding product');
} catch (e) {
  console.error('EXCEPTION during smoke check: ' + (e && e.stack ? e.stack : e));
  try {
    console.error('page HTML at failure: ' + (await page.content()));
  } catch (e2) { /* ignore */ }
  failures.push('unhandled exception: ' + e);
} finally {
  await browser.close();
}

if (failures.length > 0) {
  console.error(`\n--- DO ZERO-QTY SMOKE CHECK FAILED (${failures.length} failing) ---`);
  process.exit(1);
}
console.log('\n--- DO ZERO-QTY SMOKE CHECK PASSED ---');
process.exit(0);
