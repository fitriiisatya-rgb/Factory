"use strict";
// Fires the EXACT SAME interleave attack as legacy-race-repro.test.js
// (Pastry's whole request runs reentrantly at the moment Roti calls
// clearContents() on the Ceklis sheet, mid-critical-section) against the
// NEW backend (backend/Code.gs). Proves the fix: the reentrant Pastry call
// gets a clean LOCK_TIMEOUT (because Roti is still holding the script lock
// at that exact instant) instead of silently corrupting/losing data. Pastry
// then retries (as a real client would on LOCK_TIMEOUT) and succeeds once
// Roti's critical section has actually finished.
const { loadNewBackend } = require("./gas-harness.js");

const results = [];
function check(id, scenario, expected, actual){
  const pass = JSON.stringify(expected) === JSON.stringify(actual);
  results.push({id, scenario, expected, actual, pass});
  return pass;
}

function main(){
  const api = loadNewBackend();
  const TGL = "2026-09-06";

  api.doPostJSON({jenis:"productionProgress", tanggal:TGL, divisi:"Bolu", requestId:"seed-bolu", expectedVersion:0,
    rows:[{kode:"B1", produk:"BOLU PANDAN", kategori:"Bolu", target:100, status:"sesuai", actualDelta:100, rejectDelta:0}]});

  let pastryResultAtInterleave = null;
  api.__state.installInterleaveHook("Ceklis", "clearContents", 1, () => {
    pastryResultAtInterleave = api.doPostJSON({jenis:"productionProgress", tanggal:TGL, divisi:"Pastry", requestId:"pastry-1", expectedVersion:0,
      rows:[{kode:"P1", produk:"PASTRY COKLAT", kategori:"Pastry", target:50, status:"sesuai", actualDelta:50, rejectDelta:0}]});
  });

  const rotiResult = api.doPostJSON({jenis:"productionProgress", tanggal:TGL, divisi:"Roti & Bollen", requestId:"roti-1", expectedVersion:0,
    rows:[{kode:"R1", produk:"ROTI COKLAT", kategori:"Roti", target:80, status:"sesuai", actualDelta:80, rejectDelta:0}]});

  check("LOCKFIX.rotiSucceeds", "Roti (memegang lock duluan) tetap sukses",
    true, !!(rotiResult && rotiResult.ok));
  check("LOCKFIX.pastryGetsLockTimeoutNotSilentCorruption", "Pastry yang reentrant di tengah critical section Roti mendapat LOCK_TIMEOUT eksplisit — BUKAN diam-diam jalan lalu korup data",
    "LOCK_TIMEOUT", pastryResultAtInterleave && pastryResultAtInterleave.code);
  check("LOCKFIX.pastryNotFalselyMarkedApplied", "Respons LOCK_TIMEOUT ok:false — Pastry TAHU harus retry, tidak mengira sukses",
    false, pastryResultAtInterleave && pastryResultAtInterleave.ok);

  // Pastry retries (real client behavior on LOCK_TIMEOUT) after Roti's
  // critical section has fully released the lock.
  const pastryRetry = api.doPostJSON({jenis:"productionProgress", tanggal:TGL, divisi:"Pastry", requestId:"pastry-1-retry", expectedVersion:0,
    rows:[{kode:"P1", produk:"PASTRY COKLAT", kategori:"Pastry", target:50, status:"sesuai", actualDelta:50, rejectDelta:0}]});
  check("LOCKFIX.pastryRetrySucceeds", "Retry Pastry setelah lock bebas berhasil",
    true, !!(pastryRetry && pastryRetry.ok));

  const finalState = api.doGetJSON();
  const divisiPresent = [...new Set(finalState.ceklis.map(r=>r.divisi))].sort();
  check("LOCKFIX.allThreeDivisionsPersist", "Ketiga divisi (Bolu, Roti, Pastry-setelah-retry) SEMUA ada — tidak ada yang hilang",
    JSON.stringify(["Bolu","Pastry","Roti & Bollen"]), JSON.stringify(divisiPresent));
  const bolu = finalState.ceklis.find(r=>r.divisi==="Bolu");
  const roti = finalState.ceklis.find(r=>r.divisi==="Roti & Bollen");
  const pastry = finalState.ceklis.find(r=>r.divisi==="Pastry");
  check("LOCKFIX.valuesCorrect", "Nilai aktual masing-masing divisi benar (tidak tertukar/terpotong)",
    JSON.stringify({bolu:100, roti:80, pastry:50}), JSON.stringify({bolu:bolu?bolu.aktual:null, roti:roti?roti.aktual:null, pastry:pastry?pastry.aktual:null}));

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== NEW BACKEND: LOCK BLOCKS THE SAME ATTACK THAT BROKE LEGACY ===\n");
  results.forEach(r=>console.log(` ${r.pass?"PASS":"FAIL"}  ${r.id}: ${r.scenario}`));
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  process.exitCode = passCount===total ? 0 : 1;
}
main();
