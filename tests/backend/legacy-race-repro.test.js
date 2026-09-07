"use strict";
// Reproduces the concurrency bug found in backend/Code.legacy.gs (the exact
// verbatim source the user provided) DETERMINISTICALLY — not a guess, not a
// flaky true-concurrency test. It exploits the precise vulnerable window in
// deleteRowsWhere_():
//
//   const rows = readAllAsObjects_(sh);          // STEP 1: read whole sheet
//   const kept = rows.filter(r => !predicate(r)); // STEP 2: filter in memory
//   rewriteAll_(sh, headers, kept);                // STEP 3: clearContents() + rewrite
//
// A ceklisProduksi request computes `kept` (step 1-2) BEFORE it clears+
// rewrites (step 3). If a second division's request runs its ENTIRE
// read->filter->clear->rewrite->append cycle in that gap, the first
// request's step 3 then clears the sheet AGAIN and rewrites it using its
// now-STALE `kept` snapshot — silently erasing the second division's just-
// written rows, even though that second division's own save reported
// success. This is proven here by installing a hook that fires exactly
// when the first request calls clearContents() (i.e. right after it
// computed `kept`), and running the second request's full doPost from
// inside that hook.
const { loadLegacyBackend } = require("./gas-harness.js");

const results = [];
function check(id, scenario, expected, actual){
  const pass = JSON.stringify(expected) === JSON.stringify(actual);
  results.push({id, scenario, expected, actual, pass});
  return pass;
}

function main(){
  const api = loadLegacyBackend();
  const TGL = "2026-09-06";

  // Seed: Bolu already has a submitted row (present before the race starts).
  api.doPostJSON({jenis:"ceklisProduksi", tanggal:TGL, divisi:"Bolu",
    rows:[{kode:"B1", produk:"BOLU PANDAN", kategori:"Bolu", target:100, status:"sesuai", aktual:100, reject:0, keterangan:""}]});

  // Install the interleave hook on the FIRST clearContents() call the
  // Roti request makes (deleteRowsWhere_'s rewriteAll_ step) — at that
  // exact point, Roti has already read+filtered (kept = [Bolu's row]) but
  // has not yet written it back. We run the ENTIRE Pastry request here.
  let pastryResultAtInterleave = null;
  api.__state.installInterleaveHook("Ceklis", "clearContents", 1, () => {
    pastryResultAtInterleave = api.doPostJSON({jenis:"ceklisProduksi", tanggal:TGL, divisi:"Pastry",
      rows:[{kode:"P1", produk:"PASTRY COKLAT", kategori:"Pastry", target:50, status:"sesuai", aktual:50, reject:0, keterangan:""}]});
  });

  // Now fire Roti's request — this triggers the hook mid-flight per above.
  const rotiResult = api.doPostJSON({jenis:"ceklisProduksi", tanggal:TGL, divisi:"Roti & Bollen",
    rows:[{kode:"R1", produk:"ROTI COKLAT", kategori:"Roti", target:80, status:"sesuai", aktual:80, reject:0, keterangan:""}]});

  const finalState = api.doGetJSON();
  const ceklisRows = finalState.ceklis;
  const divisiPresent = [...new Set(ceklisRows.map(r=>r.divisi))].sort();

  check("REPRO.pastryRequestReportedSuccess", "Pastry sendiri melaporkan sukses ({ok:true}) saat requestnya sendiri dieksekusi",
    true, !!(pastryResultAtInterleave && pastryResultAtInterleave.ok));
  check("REPRO.rotiRequestReportedSuccess", "Roti juga melaporkan sukses ({ok:true}) — TIDAK ADA sinyal error apa pun ke kedua sisi",
    true, !!(rotiResult && rotiResult.ok));
  check("REPRO.BUG_pastryDataLost", "BUG: walau Pastry melapor sukses, baris Pastry HILANG dari Ceklis stlh Roti selesai (lost update, no-cors juga bikin ini tak pernah kelihatan dari browser)",
    false, divisiPresent.includes("Pastry"));
  check("REPRO.bolusPreservedByAccident", "Bolu (ditulis SEBELUM race, tidak ikut race) tetap ada",
    true, divisiPresent.includes("Bolu"));
  check("REPRO.rotiPresent", "Roti (pemenang race di skenario ini) ada",
    true, divisiPresent.includes("Roti & Bollen"));

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== LEGACY BACKEND RACE REPRODUCTION (proves the bug, not a guess) ===\n");
  results.forEach(r=>console.log(` ${r.pass?"PASS":"FAIL"}  ${r.id}: ${r.scenario}`));
  console.log(`\n=== RESULT: ${passCount}/${total} PASS (all should PASS — a PASS here means the LEGACY BUG WAS SUCCESSFULLY REPRODUCED) ===`);
  console.log("\nCeklis rows tersisa setelah race:", JSON.stringify(ceklisRows.map(r=>({divisi:r.divisi, kode:r.kode, aktual:r.aktual})), null, 2));
  process.exitCode = passCount===total ? 0 : 1;
}
main();
