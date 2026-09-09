"use strict";
// Verifies the 5-point hardening patch requested after the first Code.gs
// delivery:
//   1. Remote "reset" is fully disabled — doPost never calls the wipe logic,
//      always returns {ok:false, code:"RESET_DISABLED"}.
//   2/3. CeklisMeta now carries an EXPLICIT Status column (not_started/
//      draft/submitted/reopened/verified_fg), written by 4 separate
//      functions (touchCeklisMetaDraft_/markCeklisSubmitted_/
//      markCeklisReopened_/markCeklisVerifiedFg_) each called from exactly
//      one action — productionProgress and the legacy ceklisProduksi
//      replace path must NEVER fill SubmittedAt (that's a draft save, not
//      a submit).
//   4. productionProgress/ceklisSubmit/ceklisReopen are now STRICT: missing
//      requestId or expectedVersion is rejected outright, not silently
//      allowed through unversioned.
const fs = require("fs");
const path = require("path");
const { loadNewBackend } = require("./gas-harness.js");

const results = [];
function check(id, scenario, expected, actual){
  const pass = JSON.stringify(expected) === JSON.stringify(actual);
  results.push({id, scenario, expected, actual, pass});
  return pass;
}
function readMeta(api, tanggal, divisi){
  const sh = api.__state.ss.getSheetByName("CeklisMeta");
  return api.readAllAsObjects_(sh).find(r => r.Tanggal===tanggal && r.Divisi===divisi) || null;
}

function main(){
  const api = loadNewBackend();
  const TGL = "2026-09-16";

  /* ===================== 1. Remote reset disabled ===================== */
  // Seed some real data first so we can prove reset truly did nothing.
  api.doPostJSON({jenis:"productionProgress", tanggal:TGL, divisi:"Basic", requestId:"seed-1", expectedVersion:0,
    rows:[{kode:"K1", produk:"P1", kategori:"Basic", target:10, status:"sesuai", actualDelta:10, rejectDelta:0}]});
  const beforeReset = api.doGetJSON();
  const resetResp = api.doPostJSON({jenis:"reset", requestId:"reset-attempt-1"});
  check("RESET01.disabled", "RESET01 — payload jenis:reset ditolak dgn RESET_DISABLED",
    JSON.stringify({ok:false, code:"RESET_DISABLED"}), JSON.stringify({ok:resetResp.ok, code:resetResp.code}));
  const afterReset = api.doGetJSON();
  check("RESET02.dataUntouched", "RESET02 — data ceklis yg sudah ada TIDAK terhapus/berubah walau reset dikirim",
    JSON.stringify(beforeReset.ceklis), JSON.stringify(afterReset.ceklis));
  const codeSrc = fs.readFileSync(path.join(__dirname, "..", "..", "backend", "Code.gs"), "utf8");
  check("RESET03.noHandleResetFunctionInSource", "RESET03 — fungsi handleReset_ (destructive, dulu men-wipe sheet) sudah dihapus total dari source, bukan cuma tidak dipanggil",
    false, /function\s+handleReset_/.test(codeSrc));
  check("RESET04.doPostSwitchNeverCallsAWipeHandler", "RESET04 — case \"reset\" di doPost tidak memanggil fungsi apa pun yang mem-wipe sheet",
    true, /case\s+"reset":\s*\n\s*logError_/.test(codeSrc));

  /* ===================== 2/3. Explicit CeklisMeta status lifecycle ===================== */
  const TGL2 = "2026-09-17";

  // Fresh record: never touched -> not_started (absence of any CeklisMeta row).
  const metaFresh = readMeta(api, TGL2, "Pastry");
  check("STATUS01.freshRecordHasNoMetaRow", "STATUS01 — sebelum disentuh sama sekali, belum ada baris CeklisMeta (not_started tersirat dari ketidakhadiran)",
    null, metaFresh);

  // productionProgress (delta, new endpoint) -> draft, SubmittedAt kosong.
  const prog1 = api.doPostJSON({jenis:"productionProgress", tanggal:TGL2, divisi:"Pastry", requestId:"lc-prog-1", expectedVersion:0,
    rows:[{kode:"PS1", produk:"PASTRY A", kategori:"Pastry", target:40, status:"sesuai", actualDelta:25, rejectDelta:0}]});
  check("STATUS02.progressSucceeds", "STATUS02 — productionProgress sukses", true, prog1.ok);
  const metaAfterProgress = readMeta(api, TGL2, "Pastry");
  check("STATUS03.progressIsDraft", "STATUS03 — Save Progress (productionProgress) -> Status = draft",
    "draft", metaAfterProgress ? metaAfterProgress.Status : null);
  check("STATUS04.progressLeavesSubmittedAtEmpty", "STATUS04 — Save Progress -> SubmittedAt TETAP KOSONG (bug lama: dulu ikut keisi)",
    "", metaAfterProgress ? metaAfterProgress.SubmittedAt : null);
  check("STATUS05.progressLeavesClosedFalse", "STATUS05 — Save Progress -> Closed tetap false",
    "false", metaAfterProgress ? metaAfterProgress.Closed : null);

  // Legacy ceklisProduksi (full replace) -> ALSO draft, not submitted.
  const TGL2B = "2026-09-18";
  const legacySave = api.doPostJSON({jenis:"ceklisProduksi", tanggal:TGL2B, divisi:"Bolu", requestId:"lc-legacy-1", expectedVersion:0,
    rows:[{kode:"BL1", produk:"BOLU A", kategori:"Bolu", target:50, status:"sesuai", aktual:50, reject:0, keterangan:""}]});
  check("STATUS06.legacySaveSucceeds", "STATUS06 — ceklisProduksi (legacy replace) sukses", true, legacySave.ok);
  const metaLegacy = readMeta(api, TGL2B, "Bolu");
  check("STATUS07.legacySaveIsDraftNotSubmitted", "STATUS07 — endpoint legacy ceklisProduksi jg -> Status draft (BUKAN submitted)",
    "draft", metaLegacy ? metaLegacy.Status : null);
  check("STATUS08.legacySaveSubmittedAtEmpty", "STATUS08 — endpoint legacy ceklisProduksi -> SubmittedAt TETAP KOSONG",
    "", metaLegacy ? metaLegacy.SubmittedAt : null);

  // ceklisSubmit -> submitted, SubmittedAt terisi.
  const submitResp = api.doPostJSON({jenis:"ceklisSubmit", tanggal:TGL2, divisi:"Pastry", requestId:"lc-submit-1", expectedVersion:prog1.version});
  check("STATUS09.submitSucceeds", "STATUS09 — ceklisSubmit sukses", true, submitResp.ok);
  const metaAfterSubmit = readMeta(api, TGL2, "Pastry");
  check("STATUS10.submitIsSubmitted", "STATUS10 — Submit -> Status = submitted",
    "submitted", metaAfterSubmit ? metaAfterSubmit.Status : null);
  check("STATUS11.submitFillsSubmittedAt", "STATUS11 — Submit -> SubmittedAt TERISI (tidak kosong)",
    true, !!(metaAfterSubmit && metaAfterSubmit.SubmittedAt));
  check("STATUS12.submitClosesRecord", "STATUS12 — Submit -> Closed = true",
    "true", metaAfterSubmit ? metaAfterSubmit.Closed : null);

  // ceklisReopen -> reopened, reason recorded, SubmittedAt (history) preserved.
  const submittedAtBeforeReopen = metaAfterSubmit.SubmittedAt;
  const reopenResp = api.doPostJSON({jenis:"ceklisReopen", tanggal:TGL2, divisi:"Pastry", requestId:"lc-reopen-1", expectedVersion:submitResp.version, reason:"koreksi qty aktual"});
  check("STATUS13.reopenSucceeds", "STATUS13 — ceklisReopen sukses", true, reopenResp.ok);
  const metaAfterReopen = readMeta(api, TGL2, "Pastry");
  check("STATUS14.reopenIsReopened", "STATUS14 — Reopen -> Status = reopened (BUKAN draft/submitted)",
    "reopened", metaAfterReopen ? metaAfterReopen.Status : null);
  check("STATUS15.reopenReasonRecorded", "STATUS15 — Reopen -> alasan tercatat di ReopenReason",
    "koreksi qty aktual", metaAfterReopen ? metaAfterReopen.ReopenReason : null);
  check("STATUS16.reopenPreservesSubmittedAtHistory", "STATUS16 — Reopen TIDAK menghapus riwayat SubmittedAt sebelumnya (jejak audit)",
    submittedAtBeforeReopen, metaAfterReopen ? metaAfterReopen.SubmittedAt : null);
  check("STATUS17.reopenReopensClosed", "STATUS17 — Reopen -> Closed = false lagi",
    "false", metaAfterReopen ? metaAfterReopen.Closed : null);

  // fgReady (FG verification) -> verified_fg for each source divisi.
  const TGL2C = "2026-09-19";
  const progFgSource = api.doPostJSON({jenis:"productionProgress", tanggal:TGL2C, divisi:"Basic", requestId:"lc-fgsrc-1", expectedVersion:0,
    rows:[{kode:"BS1", produk:"BASIC A", kategori:"Basic", target:100, status:"sesuai", actualDelta:100, rejectDelta:0}]});
  const fgReadyResp = api.doPostJSON({jenis:"fgReady", tanggal:TGL2C, factory:"karangtengah", requestId:"lc-fgready-1", expectedVersion:0,
    readyAt:"19/09/2026 10:00", sourceVersions:{Basic: progFgSource.version}});
  check("STATUS18.fgReadySucceeds", "STATUS18 — fgReady sukses", true, fgReadyResp.ok);
  const metaAfterFg = readMeta(api, TGL2C, "Basic");
  check("STATUS19.fgVerifyMarksVerifiedFg", "STATUS19 — FG verification (fgReady) -> Status divisi sumber = verified_fg",
    "verified_fg", metaAfterFg ? metaAfterFg.Status : null);

  // doGet surface: metaStatus field exposed, backward-compatible naming
  // (does not clobber the pre-existing per-row FG "status" field).
  const gotCeklis = api.doGetJSON().ceklis;
  const rowBasic = gotCeklis.find(r=>r.tanggal===TGL2C && r.divisi==="Basic");
  check("STATUS20.doGetExposesMetaStatusSeparateFromRowStatus", "STATUS20 — doGet mengekspos metaStatus (lifecycle) TERPISAH dari status per-baris (sesuai/tidak_sesuai)",
    JSON.stringify({metaStatus:"verified_fg", rowStatus:"sesuai"}),
    JSON.stringify({metaStatus: rowBasic?rowBasic.metaStatus:null, rowStatus: rowBasic?rowBasic.status:null}));

  /* ===================== 4. Strict requestId/expectedVersion ===================== */
  const TGL4 = "2026-09-20";
  const missingReqId = api.doPostJSON({jenis:"productionProgress", tanggal:TGL4, divisi:"Roti & Bollen", expectedVersion:0,
    rows:[{kode:"RT1", produk:"ROTI A", kategori:"Roti", target:20, status:"sesuai", actualDelta:20, rejectDelta:0}]});
  check("STRICT01.missingRequestIdRejected", "STRICT01 — productionProgress TANPA requestId -> MISSING_REQUEST_ID",
    JSON.stringify({ok:false, code:"MISSING_REQUEST_ID"}), JSON.stringify({ok:missingReqId.ok, code:missingReqId.code}));

  const missingVersion = api.doPostJSON({jenis:"productionProgress", tanggal:TGL4, divisi:"Roti & Bollen", requestId:"strict-1",
    rows:[{kode:"RT1", produk:"ROTI A", kategori:"Roti", target:20, status:"sesuai", actualDelta:20, rejectDelta:0}]});
  check("STRICT02.missingExpectedVersionRejected", "STRICT02 — productionProgress TANPA expectedVersion -> MISSING_EXPECTED_VERSION",
    JSON.stringify({ok:false, code:"MISSING_EXPECTED_VERSION"}), JSON.stringify({ok:missingVersion.ok, code:missingVersion.code}));

  const noRowsWritten = api.doGetJSON().ceklis.filter(r=>r.tanggal===TGL4);
  check("STRICT03.rejectedRequestsWriteNothing", "STRICT03 — kedua request yg ditolak TIDAK menulis apa pun ke Ceklis",
    0, noRowsWritten.length);

  const missingReqIdSubmit = api.doPostJSON({jenis:"ceklisSubmit", tanggal:TGL4, divisi:"Roti & Bollen", expectedVersion:0});
  check("STRICT04.ceklisSubmitStrict", "STRICT04 — ceklisSubmit TANPA requestId juga ditolak MISSING_REQUEST_ID",
    JSON.stringify({ok:false, code:"MISSING_REQUEST_ID"}), JSON.stringify({ok:missingReqIdSubmit.ok, code:missingReqIdSubmit.code}));

  const missingVersionReopen = api.doPostJSON({jenis:"ceklisReopen", tanggal:TGL4, divisi:"Roti & Bollen", requestId:"strict-reopen-1", reason:"test"});
  check("STRICT05.ceklisReopenStrict", "STRICT05 — ceklisReopen TANPA expectedVersion ditolak MISSING_EXPECTED_VERSION",
    JSON.stringify({ok:false, code:"MISSING_EXPECTED_VERSION"}), JSON.stringify({ok:missingVersionReopen.ok, code:missingVersionReopen.code}));

  // Valid strict call still works normally (both fields present).
  const validStrict = api.doPostJSON({jenis:"productionProgress", tanggal:TGL4, divisi:"Roti & Bollen", requestId:"strict-valid-1", expectedVersion:0,
    rows:[{kode:"RT1", produk:"ROTI A", kategori:"Roti", target:20, status:"sesuai", actualDelta:20, rejectDelta:0}]});
  check("STRICT06.validCallStillWorks", "STRICT06 — request lengkap (requestId+expectedVersion) tetap berhasil normal",
    true, validStrict.ok);

  /* ===================== Idempotent retry still holds (regression guard) ===================== */
  const retrySame = api.doPostJSON({jenis:"productionProgress", tanggal:TGL4, divisi:"Roti & Bollen", requestId:"strict-valid-1", expectedVersion:0,
    rows:[{kode:"RT1", produk:"ROTI A", kategori:"Roti", target:20, status:"sesuai", actualDelta:20, rejectDelta:0}]});
  check("IDEMP01.retrySameRequestIdStillIdempotent", "IDEMP01 — requestId yg sama diulang tetap idempoten (versi tidak naik lagi)",
    JSON.stringify({ok:true, version:validStrict.version}), JSON.stringify({ok:retrySame.ok, version:retrySame.version}));
  const rotiRow = api.doGetJSON().ceklis.find(r=>r.tanggal===TGL4 && r.divisi==="Roti & Bollen");
  check("IDEMP02.deltaNotDoubled", "IDEMP02 — delta +20 tetap cuma 20 (bukan 40) setelah diulang",
    20, rotiRow ? rotiRow.aktual : null);

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== STATUS LIFECYCLE + RESET DISABLE + STRICT VERSIONING ===\n");
  results.forEach(r=>console.log(` ${r.pass?"PASS":"FAIL"}  ${r.id}: ${r.scenario}`));
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main();
