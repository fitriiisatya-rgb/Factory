"use strict";
// Section 25 load test: 5 divisions, multiple saves each, at least 50
// production save operations total. Verifies: expected totals, no missing
// audit rows, no duplicated delta, monotonic version per record, no
// cross-division contamination.
const { loadNewBackend } = require("./gas-harness.js");

const results = [];
function check(id, scenario, expected, actual){
  const pass = JSON.stringify(expected) === JSON.stringify(actual);
  results.push({id, scenario, expected, actual, pass});
  return pass;
}

function main(){
  const api = loadNewBackend();
  const DIVISIONS = ["Roti & Bollen","Basic","Donat/Mochi/AKB","Pastry","Bolu"];
  const TGL = "2026-09-16";
  const SAVES_PER_DIVISION = 12; // 5 x 12 = 60 >= 50 required
  const DELTA_PER_SAVE = 5;

  const expectedTotal = {};
  const versionTrace = {}; // divisi -> [version after each save]
  let opCount = 0;

  DIVISIONS.forEach(divisi=>{
    expectedTotal[divisi] = 0;
    versionTrace[divisi] = [];
    let expectedVersion = 0;
    for(let i=0;i<SAVES_PER_DIVISION;i++){
      const requestId = "load-"+divisi+"-"+i;
      const resp = api.doPostJSON({jenis:"productionProgress", tanggal:TGL, divisi, requestId, expectedVersion,
        rows:[{kode:"LOAD1", produk:"PRODUK LOAD "+divisi, kategori:divisi, target:1000, status:"sesuai", actualDelta:DELTA_PER_SAVE, rejectDelta:0}]});
      opCount++;
      if(!resp.ok) throw new Error("Save gagal tak terduga: "+divisi+" #"+i+" -> "+JSON.stringify(resp));
      expectedVersion = resp.version;
      expectedTotal[divisi] += DELTA_PER_SAVE;
      versionTrace[divisi].push(resp.version);
    }
  });

  check("LOAD.atLeast50Ops", "Section 25 — minimal 50 operasi simpan produksi dijalankan", true, opCount >= 50);

  const finalRows = api.doGetJSON().ceklis.filter(r=>r.tanggal===TGL);
  const actualTotals = {};
  DIVISIONS.forEach(divisi=>{ actualTotals[divisi] = (finalRows.find(r=>r.divisi===divisi)||{}).aktual; });
  check("LOAD.expectedTotalsPerDivision", "Total kumulatif per divisi benar (12 x 5 = 60 masing-masing)",
    JSON.stringify(Object.fromEntries(DIVISIONS.map(d=>[d,60]))), JSON.stringify(actualTotals));

  check("LOAD.noCrossDivisionContamination", "Tidak ada kontaminasi lintas divisi — tepat 5 baris (1 per divisi) utk tanggal ini",
    5, finalRows.length);

  const monotonic = DIVISIONS.every(divisi => {
    const seq = versionTrace[divisi];
    return seq.every((v,i)=> i===0 ? v===1 : v===seq[i-1]+1);
  });
  check("LOAD.monotonicVersionPerRecord", "Versi tiap record naik monoton 1,2,3...12 per divisi (tidak ada lompat/mundur/duplikat)",
    true, monotonic);

  // Audit trail: harus ada TEPAT 1 baris audit "production_progress" per operasi sukses (60 total),
  // tidak kurang (hilang) dan tidak lebih (duplikat).
  const ss = api.__state.ss;
  const auditSheet = ss.getSheetByName("AuditLog");
  const auditRows = api.readAllAsObjects_(auditSheet).filter(r=>r.Action==="production_progress" && r.Tanggal===TGL);
  check("LOAD.noMissingAuditRows", "Setiap operasi sukses punya TEPAT 1 baris AuditLog (tidak ada yang hilang)",
    opCount, auditRows.length);
  check("LOAD.noDuplicatedAuditRows", "Tidak ada baris AuditLog terduplikasi (requestId unik semua)",
    new Set(auditRows.map(r=>r.RequestId)).size, auditRows.length);

  // Retry stress: ulangi 10 requestId ACAK yang sudah pernah dipakai -> tidak boleh menambah delta lagi.
  let retryCount = 0;
  for(let i=0;i<10;i++){
    const divisi = DIVISIONS[i % DIVISIONS.length];
    const requestId = "load-"+divisi+"-"+(i % SAVES_PER_DIVISION);
    const resp = api.doPostJSON({jenis:"productionProgress", tanggal:TGL, divisi, requestId, expectedVersion:0,
      rows:[{kode:"LOAD1", produk:"PRODUK LOAD "+divisi, kategori:divisi, target:1000, status:"sesuai", actualDelta:DELTA_PER_SAVE, rejectDelta:0}]});
    if(resp.ok) retryCount++;
  }
  const afterRetryRows = api.doGetJSON().ceklis.filter(r=>r.tanggal===TGL);
  const afterRetryTotals = {}; DIVISIONS.forEach(divisi=>{ afterRetryTotals[divisi] = (afterRetryRows.find(r=>r.divisi===divisi)||{}).aktual; });
  check("LOAD.retriedRequestIdsDoNotDuplicateDelta", "10 requestId lama diulang -> totalnya TIDAK berubah (masih 60 semua, delta tidak digandakan)",
    JSON.stringify(Object.fromEntries(DIVISIONS.map(d=>[d,60]))), JSON.stringify(afterRetryTotals));

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== LOAD TEST (section 25) ===\n");
  console.log(`Total operasi simpan dijalankan: ${opCount} (+10 retry) di ${DIVISIONS.length} divisi\n`);
  results.forEach(r=>console.log(` ${r.pass?"PASS":"FAIL"}  ${r.id}: ${r.scenario}`));
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main();
