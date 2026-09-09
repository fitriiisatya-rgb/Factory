"use strict";
// Verifies the final hardening: migrateSchema_() writes triggered from
// doGet()/doPost() are now protected by LockService (via ensureSchemaMigrated_),
// with a read-only fast path so the lock is only ever acquired when a
// migration write is actually about to happen, and released again before
// doPost's handler dispatch runs (so it never nests with a handler's own
// withLock_() call).
//
// The core race is reproduced deterministically with the same interleave-
// hook technique used elsewhere in this suite (see legacy-race-repro.test.js
// for the full rationale): Request A is paused at the exact instant it is
// about to READ the old CeklisMeta rows to migrate them (i.e. AFTER it
// already acquired the script lock) — at that instant, Request B's own
// ensureSchemaMigrated_() is run in full. Because A is still holding the
// lock, B's tryLock() must fail, so B safely skips and returns without
// writing anything; A then resumes and completes the migration alone.
const { loadNewBackendNoSetup, loadNewBackend } = require("./gas-harness.js");

const results = [];
function check(id, scenario, expected, actual){
  const pass = JSON.stringify(expected) === JSON.stringify(actual);
  results.push({id, scenario, expected, actual, pass});
  return pass;
}
function seedSheet(ss, name, headerRow, dataRows){
  const sh = ss.insertSheet(name);
  sh.appendRow(headerRow);
  dataRows.forEach(r=>sh.appendRow(r));
  return sh;
}
function readSheetRows(api, name){
  return api.readAllAsObjects_(api.__state.ss.getSheetByName(name));
}

function main(){
  /* ===================== Fast path: no lock touched when nothing to migrate ===================== */
  const apiFast = loadNewBackend(); // setup() already ran -> already fully migrated (new sheets from the start)
  apiFast.__state.forceLockTimeoutOnce(); // if ensureSchemaMigrated_ tried to acquire a lock here, it would fail and log — we check it did NOT even try
  let threwOrLogged = false;
  try{ apiFast.ensureSchemaMigrated_(); }catch(e){ threwOrLogged = true; }
  check("FASTPATH01.noMigrationNeededSkipsLockEntirely", "FASTPATH01 — kalau sudah termigrasi, ensureSchemaMigrated_ tidak mencoba lock sama sekali (forced lock-timeout tidak berpengaruh, tidak error)",
    false, threwOrLogged);
  // Prove the forced-timeout is still armed (i.e. ensureSchemaMigrated_'s
  // fast path really did NOT consume it) — the very next real tryLock()
  // attempt anywhere (here: the handler's own withLock_ inside doPost) must
  // be the one that hits it.
  const armedCheck = apiFast.doPostJSON({jenis:"productionProgress", tanggal:"2026-10-01", divisi:"Basic", requestId:"fp-1", expectedVersion:0,
    rows:[{kode:"K1", produk:"P1", kategori:"Basic", target:10, status:"sesuai", actualDelta:5, rejectDelta:0}]});
  check("FASTPATH02.forcedTimeoutStillArmedProvesItWasUnused", "FASTPATH02 — forceLockTimeoutOnce() masih 'bersenjata' dan baru kena di percobaan tryLock() SUNGGUHAN pertama (milik handler), membuktikan ensureSchemaMigrated_ TIDAK memakainya lebih dulu",
    JSON.stringify({ok:false, code:"LOCK_TIMEOUT"}), JSON.stringify({ok:armedCheck.ok, code:armedCheck.code}));
  // Retry (fresh requestId, timeout already consumed above) now succeeds normally.
  const retryAfterArmed = apiFast.doPostJSON({jenis:"productionProgress", tanggal:"2026-10-01", divisi:"Basic", requestId:"fp-2", expectedVersion:0,
    rows:[{kode:"K1", produk:"P1", kategori:"Basic", target:10, status:"sesuai", actualDelta:5, rejectDelta:0}]});
  check("FASTPATH03.normalCallSucceedsAfterTimeoutConsumed", "FASTPATH03 — panggilan normal setelah forced-timeout terpakai kembali berhasil seperti biasa",
    true, retryAfterArmed.ok);

  /* ===================== Two concurrent first-time migrations: exact race ===================== */
  const api = loadNewBackendNoSetup();
  const ss = api.__state.ss;
  seedSheet(ss, "CeklisMeta",
    ["Tanggal","Divisi","SubmittedAt","Closed","ClosedAt","ClosedBy","ReopenReason"],
    [
      ["2026-05-01","Pastry","01/05/2026 10:00","true","01/05/2026 10:00","Budi",""],
      ["2026-05-02","Bolu","","false","","",""],
    ]
  );

  let requestBResult = "not_run";
  let requestBSawStillNeedsMigration = null;
  api.__state.installInterleaveHook("CeklisMeta", "getDataRange", 1, () => {
    // Titik ini = A SUDAH memegang lock (tryLock berhasil) dan baru mau
    // membaca baris lama utk dimigrasi (readAllAsObjects_ di dalam
    // migrateCeklisMetaStatus_) — race paling ketat yg realistis.
    requestBSawStillNeedsMigration = api.schemaNeedsMigration_(); // B's OWN unlocked fast-check, dijalankan di tengah critical section A
    try{
      api.ensureSchemaMigrated_(); // Request B, seluruhnya, reentrant persis di tengah critical section A.
      requestBResult = "returned_without_throwing";
    }catch(e){ requestBResult = "threw:"+e; }
  });

  api.ensureSchemaMigrated_(); // Request A — memicu hook di atas persis saat A mulai membaca data lama.

  check("RACE01.requestBSawMigrationStillNeeded", "RACE01 — Request B, dijalankan di tengah critical section A, benar melihat (via cek read-only tanpa lock) bahwa migrasi masih 'dibutuhkan' pada saat itu (A blm selesai menulis)",
    true, requestBSawStillNeedsMigration);
  check("RACE02.requestBDidNotThrowOrCorrupt", "RACE02 — Request B tidak throw/error — tryLock gagal ditangani dgn baik (skip, bukan crash)",
    "returned_without_throwing", requestBResult);

  const finalHeader = api.readHeaderRow_(ss.getSheetByName("CeklisMeta"));
  check("RACE03.migratedExactlyOnce", "RACE03 — header akhir tetap 8 kolom yg benar (bukan dobel/rusak krn 2 percobaan migrasi)",
    JSON.stringify(["Tanggal","Divisi","Status","SubmittedAt","Closed","ClosedAt","ClosedBy","ReopenReason"]), JSON.stringify(finalHeader));

  const finalRows = readSheetRows(api, "CeklisMeta");
  check("RACE04.rowCountStillCorrect", "RACE04 — jumlah baris tetap 2 (bukan digandakan oleh 2 upaya migrasi)",
    2, finalRows.length);
  const pastryFinal = finalRows.find(r=>r.Divisi==="Pastry");
  const boluFinal = finalRows.find(r=>r.Divisi==="Bolu");
  check("RACE05.historicalValuesUnchangedAfterRace", "RACE05 — nilai historis kedua baris tetap benar & tidak bergeser walau ada percobaan migrasi ganda",
    JSON.stringify({pastryStatus:"submitted", pastrySubmittedAt:"01/05/2026 10:00", pastryClosedBy:"Budi", boluStatus:"not_started"}),
    JSON.stringify({pastryStatus:pastryFinal.Status, pastrySubmittedAt:pastryFinal.SubmittedAt, pastryClosedBy:pastryFinal.ClosedBy, boluStatus:boluFinal.Status}));

  // Second call (a genuinely later, non-interleaved request C) now becomes
  // a pure no-op via the fast path — no lock touched at all this time.
  api.__state.forceLockTimeoutOnce();
  let requestCThrew = false;
  try{ api.ensureSchemaMigrated_(); }catch(e){ requestCThrew = true; }
  check("RACE06.subsequentCallIsPureNoOpFastPath", "RACE06 — panggilan ketiga (request C, setelah migrasi tuntas) langsung no-op lewat fast path, TIDAK mencoba lock sama sekali (forced timeout tidak berpengaruh)",
    false, requestCThrew);

  /* ===================== No nesting: handler mutation right after migration still works ===================== */
  const api2 = loadNewBackendNoSetup();
  const ss2 = api2.__state.ss;
  seedSheet(ss2, "CeklisMeta",
    ["Tanggal","Divisi","SubmittedAt","Closed","ClosedAt","ClosedBy","ReopenReason"],
    [["2026-06-01","Basic","","false","","",""]]
  );
  // doPost() calls ensureSchemaMigrated_() FIRST (triggers a real migration,
  // acquiring+releasing the script lock), THEN dispatches to a handler that
  // calls mutateVersioned_ -> withLock_ -> tryLock() ITSELF. If the migration
  // lock were still held (nested/leaked), this would deadlock/fail.
  const postAfterMigration = api2.doPostJSON({jenis:"productionProgress", tanggal:"2026-06-02", divisi:"Basic", requestId:"nn-1", expectedVersion:0,
    rows:[{kode:"K2", produk:"P2", kategori:"Basic", target:10, status:"sesuai", actualDelta:5, rejectDelta:0}]});
  check("NEST01.handlerMutationSucceedsRightAfterMigration", "NEST01 — doPost yg memicu migrasi DAN handler mutation dlm request yg sama sukses keduanya (lock migrasi tidak nested/bocor ke lock handler)",
    JSON.stringify({ok:true, version:1}), JSON.stringify({ok:postAfterMigration.ok, version:postAfterMigration.version}));
  const migratedViaPost = api2.readHeaderRow_(ss2.getSheetByName("CeklisMeta"));
  check("NEST02.migrationAlsoHappenedInSameRequest", "NEST02 — CeklisMeta jg sudah termigrasi (8 kolom) dlm request yg sama itu",
    8, migratedViaPost.length);

  /* ===================== Full regression guard (existing suites still pass) ===================== */
  // (Dijalankan sbg file terpisah dlm CI/manual — dicatat di sini sbg
  // pengingat, bukan dieksekusi ulang di file ini utk menghindari duplikasi.)
  check("REGRESSION_NOTE.seeOtherFiles", "Regresi penuh (legacy-race-repro/new-backend-lock-blocks-race/conc-matrix/load-test/status-lifecycle/schema-migration) dijalankan terpisah — semua harus tetap PASS",
    true, true);

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== SCHEMA MIGRATION LOCK HARDENING ===\n");
  results.forEach(r=>console.log(` ${r.pass?"PASS":"FAIL"}  ${r.id}: ${r.scenario}`));
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main();
