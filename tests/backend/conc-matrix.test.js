"use strict";
// CONC01-10 test matrix (per spec section 24) against the NEW backend
// (backend/Code.gs) via the Node GAS simulation harness.
//
// Methodology note (read this before doubting why these are sequential
// Node calls rather than true parallel network requests): the whole POINT
// of LockService.getScriptLock() is to convert genuinely-parallel Apps
// Script executions into a SAFE SERIALIZED sequence — correctness under
// "for ANY serialization order, no data is lost/corrupted/duplicated" is
// exactly the property that needs proving, and that is exactly what
// sequential calls against the same backing store test. What this CANNOT
// prove is whether Google's real infrastructure actually blocks two truly
// simultaneous HTTP requests at the LockService boundary the way this
// mock does — that requires a real deployment test (see the audit report's
// "remaining blockers"). CONC04 additionally forces a lock-contention
// response directly (bypassing the need for true interleaving) to verify
// the timeout path itself is exercised and correct.
const { loadNewBackend } = require("./gas-harness.js");

const results = [];
function check(id, scenario, expected, actual){
  const pass = JSON.stringify(expected) === JSON.stringify(actual);
  results.push({id, scenario, expected, actual, pass});
  return pass;
}
function progress(api, tgl, divisi, requestId, expectedVersion, kode, produk, target, actualDelta, rejectDelta){
  return api.doPostJSON({jenis:"productionProgress", tanggal:tgl, divisi, requestId, expectedVersion,
    rows:[{kode, produk, kategori:divisi, target, status:"sesuai", actualDelta, rejectDelta:rejectDelta||0}]});
}

function main(){
  const api = loadNewBackend();
  const TGL = "2026-09-06";

  /* ===================== CONC01 — 4 divisi berbeda, hampir bersamaan ===================== */
  const r1 = progress(api, TGL, "Pastry", "c1-pastry", 0, "PS1", "PASTRY A", 40, 40);
  const r2 = progress(api, TGL, "Bolu", "c1-bolu", 0, "BL1", "BOLU A", 60, 60);
  const r3 = progress(api, TGL, "Roti & Bollen", "c1-roti", 0, "RT1", "ROTI A", 30, 30);
  const r4 = progress(api, TGL, "Basic", "c1-basic", 0, "BS1", "BASIC A", 90, 90);
  check("CONC01.allFourSucceed", "CONC01 — 4 divisi berbeda submit hampir bersamaan: semua sukses",
    JSON.stringify([true,true,true,true]), JSON.stringify([r1,r2,r3,r4].map(r=>r.ok)));
  const afterConc01 = api.doGetJSON().ceklis.filter(r=>r.tanggal===TGL);
  const totalsByDivisi = {}; afterConc01.forEach(r=>totalsByDivisi[r.divisi]=r.aktual);
  check("CONC01.noLostUpdate", "CONC01 — semua 4 nilai tersimpan benar, tidak ada yang hilang/tertimpa",
    JSON.stringify({Pastry:40,Bolu:60,"Roti & Bollen":30,Basic:90}),
    JSON.stringify({Pastry:totalsByDivisi.Pastry, Bolu:totalsByDivisi.Bolu, "Roti & Bollen":totalsByDivisi["Roti & Bollen"], Basic:totalsByDivisi.Basic}));

  /* ===================== CONC02 — divisi sama, versi basi ===================== */
  const TGL2 = "2026-09-07";
  const vBefore = api.getVersion_("ceklis", TGL2+"|Pastry").version;
  check("CONC02.startsAtVersion0", "CONC02 — Pastry belum pernah disimpan tgl ini, versi awal 0", 0, vBefore);
  const deviceA = progress(api, TGL2, "Pastry", "c2-deviceA", 0, "PS2", "PASTRY B", 100, 20);
  check("CONC02.deviceASucceeds", "CONC02 — Device A simpan +20 dgn expectedVersion 0 -> sukses, versi jadi 1",
    JSON.stringify({ok:true, version:1}), JSON.stringify({ok:deviceA.ok, version:deviceA.version}));
  const deviceB = progress(api, TGL2, "Pastry", "c2-deviceB", 0, "PS2", "PASTRY B", 100, 15);
  check("CONC02.deviceBRejected", "CONC02 — Device B masih pakai expectedVersion 0 (basi) -> VERSION_CONFLICT, TIDAK menimpa",
    JSON.stringify({ok:false, code:"VERSION_CONFLICT", currentVersion:1}), JSON.stringify({ok:deviceB.ok, code:deviceB.code, currentVersion:deviceB.currentVersion}));
  const afterConc02 = api.doGetJSON().ceklis.find(r=>r.tanggal===TGL2 && r.divisi==="Pastry");
  check("CONC02.valueNotOverwrittenByLoser", "CONC02 — nilai tetap hasil Device A (20), TIDAK ikut tertimpa 15 dari Device B",
    20, afterConc02.aktual);

  /* ===================== CONC03 — requestId sama dikirim 2x ===================== */
  const TGL3 = "2026-09-08";
  const first = progress(api, TGL3, "Bolu", "c3-dup", 0, "BL2", "BOLU B", 50, 25);
  const second = progress(api, TGL3, "Bolu", "c3-dup", 0, "BL2", "BOLU B", 50, 25); // requestId SAMA, dikirim ulang
  check("CONC03.firstApplied", "CONC03 — pengiriman pertama diterapkan, versi jadi 1",
    JSON.stringify({ok:true, version:1}), JSON.stringify({ok:first.ok, version:first.version}));
  check("CONC03.duplicateReturnsSameResponseNotReapplied", "CONC03 — requestId sama yg ke-2 mengembalikan respons TERSIMPAN yang sama (versi tetap 1), bukan diterapkan lagi",
    JSON.stringify({ok:true, version:1}), JSON.stringify({ok:second.ok, version:second.version}));
  const afterConc03 = api.doGetJSON().ceklis.find(r=>r.tanggal===TGL3 && r.divisi==="Bolu");
  check("CONC03.deltaAppliedOnce", "CONC03 — delta +25 diterapkan SEKALI (25), bukan 50",
    25, afterConc03.aktual);

  /* ===================== CONC04 — lock contention ===================== */
  api.__state.forceLockTimeoutOnce();
  const lockBusy = progress(api, "2026-09-09", "Basic", "c4-1", 0, "BS2", "BASIC B", 10, 10);
  check("CONC04.explicitLockTimeout", "CONC04 — saat lock sedang dipegang request lain, respons LOCK_TIMEOUT eksplisit (bukan diam2 gagal/korup)",
    JSON.stringify({ok:false, code:"LOCK_TIMEOUT"}), JSON.stringify({ok:lockBusy.ok, code:lockBusy.code}));
  const afterLockBusy = api.doGetJSON().ceklis.find(r=>r.tanggal==="2026-09-09" && r.divisi==="Basic");
  check("CONC04.noPartialWriteOnTimeout", "CONC04 — tidak ada tulisan parsial saat LOCK_TIMEOUT (record belum ada sama sekali)",
    undefined, afterLockBusy);
  const lockRetry = progress(api, "2026-09-09", "Basic", "c4-1-retry", 0, "BS2", "BASIC B", 10, 10);
  check("CONC04.retrySucceedsAfterLockFree", "CONC04 — retry setelah lock bebas berhasil",
    true, lockRetry.ok);

  /* ===================== CONC05 — save + submit race, divisi sama ===================== */
  const TGL5 = "2026-09-10";
  const saveA = progress(api, TGL5, "Roti & Bollen", "c5-save", 0, "RT2", "ROTI B", 60, 60);
  check("CONC05.saveSucceedsFirst", "CONC05 — Save Progress (device A) duluan, sukses -> versi 1",
    JSON.stringify({ok:true, version:1}), JSON.stringify({ok:saveA.ok, version:saveA.version}));
  const submitStale = api.doPostJSON({jenis:"ceklisSubmit", tanggal:TGL5, divisi:"Roti & Bollen", requestId:"c5-submit-stale", expectedVersion:0});
  check("CONC05.submitWithStaleVersionRejected", "CONC05 — Submit (device B) pakai versi 0 (basi, blm tau ada save barusan) -> VERSION_CONFLICT",
    JSON.stringify({ok:false, code:"VERSION_CONFLICT", currentVersion:1}), JSON.stringify({ok:submitStale.ok, code:submitStale.code, currentVersion:submitStale.currentVersion}));
  const submitCorrect = api.doPostJSON({jenis:"ceklisSubmit", tanggal:TGL5, divisi:"Roti & Bollen", requestId:"c5-submit-ok", expectedVersion:1});
  check("CONC05.submitWithCorrectVersionSucceeds", "CONC05 — Submit dgn versi terkini (1) berhasil -> versi 2, closed",
    JSON.stringify({ok:true, version:2, closed:true}), JSON.stringify({ok:submitCorrect.ok, version:submitCorrect.version, closed:submitCorrect.record&&submitCorrect.record.closed}));

  /* ===================== CONC06 — reopen vs submit race ===================== */
  const TGL6 = "2026-09-11";
  progress(api, TGL6, "Pastry", "c6-save", 0, "PS3", "PASTRY C", 30, 30);
  const submit6 = api.doPostJSON({jenis:"ceklisSubmit", tanggal:TGL6, divisi:"Pastry", requestId:"c6-submit", expectedVersion:1});
  check("CONC06.submitSucceeds", "CONC06 — submit awal sukses -> versi 2, closed",
    JSON.stringify({ok:true, version:2}), JSON.stringify({ok:submit6.ok, version:submit6.version}));
  const reopenNoReason = api.doPostJSON({jenis:"ceklisReopen", tanggal:TGL6, divisi:"Pastry", requestId:"c6-reopen-noreason", expectedVersion:2});
  check("CONC06.reopenRequiresReason", "CONC06 — reopen TANPA alasan ditolak (REASON_REQUIRED), tidak mengubah state",
    JSON.stringify({ok:false, code:"REASON_REQUIRED"}), JSON.stringify({ok:reopenNoReason.ok, code:reopenNoReason.code}));
  const reopenStale = api.doPostJSON({jenis:"ceklisReopen", tanggal:TGL6, divisi:"Pastry", requestId:"c6-reopen-stale", expectedVersion:1, reason:"koreksi qty"});
  check("CONC06.reopenStaleVersionRejected", "CONC06 — reopen dgn versi basi (1, padahal sudah 2 stlh submit) -> VERSION_CONFLICT",
    JSON.stringify({ok:false, code:"VERSION_CONFLICT", currentVersion:2}), JSON.stringify({ok:reopenStale.ok, code:reopenStale.code, currentVersion:reopenStale.currentVersion}));
  const reopenOk = api.doPostJSON({jenis:"ceklisReopen", tanggal:TGL6, divisi:"Pastry", requestId:"c6-reopen-ok", expectedVersion:2, reason:"koreksi qty"});
  check("CONC06.reopenWithCorrectVersionAndReasonSucceeds", "CONC06 — reopen dgn versi benar + alasan -> berhasil, versi 3, closed=false, alasan tercatat",
    JSON.stringify({ok:true, version:3, closed:false, reason:"koreksi qty"}),
    JSON.stringify({ok:reopenOk.ok, version:reopenOk.version, closed:reopenOk.record&&reopenOk.record.closed, reason:reopenOk.record&&reopenOk.record.reason}));
  check("CONC06.noCorruptionOrderedState", "CONC06 — urutan versi konsisten monoton (submit=2, konflik reopen lihat versi terkini=2, reopen sukses=3), tidak ada state korup",
    JSON.stringify([2,2,3]), JSON.stringify([submit6.version, reopenStale.currentVersion, reopenOk.version]));

  /* ===================== CONC07 — FG verifikasi versi produksi basi ===================== */
  const TGL7 = "2026-09-12";
  const progA = progress(api, TGL7, "Basic", "c7-prog", 0, "BS3", "BASIC C", 100, 100);
  const fgReady1 = api.doPostJSON({jenis:"fgReady", tanggal:TGL7, factory:"karangtengah", requestId:"c7-fgready-1", expectedVersion:0,
    readyAt:"06/09/2026 10:00", sourceVersions:{Basic: progA.version}});
  check("CONC07.fgReadyNoWarningWhenSourceFresh", "CONC07 — FG ready saat sourceVersions masih sinkron -> tidak ada warning",
    undefined, fgReady1.warning);
  // Produksi berubah lagi SETELAH FG mulai verifikasi (mis. koreksi Basic).
  progress(api, TGL7, "Basic", "c7-prog2", progA.version, "BS3", "BASIC C", 100, 5);
  const fgReady2 = api.doPostJSON({jenis:"fgReady", tanggal:TGL7, factory:"karangtengah", requestId:"c7-fgready-2", expectedVersion:fgReady1.version,
    readyAt:"06/09/2026 11:00", sourceVersions:{Basic: progA.version}}); // FG masih pakai versi produksi LAMA
  check("CONC07.fgWarnsOnStaleSource", "CONC07 — FG verifikasi thd versi produksi yang SUDAH basi -> warning STALE_PRODUCTION_SOURCE (bukan hard block, konsisten dgn soft-gate fgTandaiSiap yang sudah ada)",
    "STALE_PRODUCTION_SOURCE", fgReady2.warning && fgReady2.warning.code);

  /* ===================== CONC08 — retry jaringan (mobile) ===================== */
  const TGL8 = "2026-09-13";
  const tap1 = progress(api, TGL8, "Donat/Mochi/AKB", "c8-tap", 0, "DM1", "DONAT A", 20, 20);
  const tap2 = progress(api, TGL8, "Donat/Mochi/AKB", "c8-tap", 0, "DM1", "DONAT A", 20, 20); // retry, requestId SAMA
  check("CONC08.doubleTapAppliesOnce", "CONC08 — 'double tap'/retry jaringan dgn requestId sama, delta +20 hanya diterapkan SEKALI (bukan +40)",
    20, api.doGetJSON().ceklis.find(r=>r.tanggal===TGL8 && r.divisi==="Donat/Mochi/AKB").aktual);
  check("CONC08.bothResponsesConsistent", "CONC08 — kedua respons (asli & retry) sama persis (idempoten)",
    JSON.stringify(tap1), JSON.stringify(tap2));

  /* ===================== CONC09 — 2 device berbeda, backend menang lewat versi ===================== */
  const TGL9 = "2026-09-14";
  const devA = progress(api, TGL9, "FG", "c9-devA", 0, "FG1", "PRODUK FG A", 200, 150);
  const devB = progress(api, TGL9, "FG", "c9-devB", 0, "FG1", "PRODUK FG A", 200, 999); // device B basi, coba dgn nilai ekstrem
  check("CONC09.backendRejectsStaleNotLastWriteWins", "CONC09 — backend TOLAK device B (versi basi) via version check — BUKAN last-write-wins yang diam-diam menang",
    JSON.stringify({ok:false, code:"VERSION_CONFLICT"}), JSON.stringify({ok:devB.ok, code:devB.code}));
  check("CONC09.valueIsDeviceAOnly", "CONC09 — nilai akhir cuma dari Device A (150), angka ekstrem Device B (999) tidak pernah masuk",
    150, api.doGetJSON().ceklis.find(r=>r.tanggal===TGL9 && r.divisi==="FG").aktual);

  /* ===================== CONC10 — kode sama, produk beda, tetap terpisah ===================== */
  const TGL10 = "2026-09-15";
  const dup1 = api.doPostJSON({jenis:"productionProgress", tanggal:TGL10, divisi:"Basic", requestId:"c10-a", expectedVersion:0,
    rows:[{kode:"DUP1", produk:"PRODUK A", kategori:"Basic", target:40, status:"sesuai", actualDelta:40, rejectDelta:0}]});
  const dup2 = api.doPostJSON({jenis:"productionProgress", tanggal:TGL10, divisi:"Basic", requestId:"c10-b", expectedVersion:dup1.version,
    rows:[{kode:"DUP1", produk:"PRODUK B", kategori:"Basic", target:60, status:"sesuai", actualDelta:60, rejectDelta:0}]});
  const rowsTGL10 = api.doGetJSON().ceklis.filter(r=>r.tanggal===TGL10);
  check("CONC10.sameKodeDifferentProductsStaySeparate", "CONC10 — kode sama (DUP1) dipakai 2 produk berbeda tetap 2 baris terpisah, tidak tergabung",
    JSON.stringify({A:40, B:60}),
    JSON.stringify({A:(rowsTGL10.find(r=>r.produk==="PRODUK A")||{}).aktual, B:(rowsTGL10.find(r=>r.produk==="PRODUK B")||{}).aktual}));

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== CONC01-10 TEST MATRIX ===\n");
  console.log("ID".padEnd(46), "Scenario".padEnd(100), "Result");
  results.forEach(r=>console.log(r.id.padEnd(46), r.scenario.slice(0,100).padEnd(100), r.pass?"PASS":"FAIL"));
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main();
