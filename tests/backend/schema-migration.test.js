"use strict";
// Verifies the explicit schema migration requested after the backward-
// compatibility gap was found: getOrCreateSheet() only writes headers for
// BRAND-NEW sheets — an existing production CeklisMeta/FGPacking/FGReady
// sheet (old header, real historical rows) is left untouched by it. Before
// this patch, that sheet only got its new column once something ELSE
// happened to trigger a deleteRowsWhere_/rewriteAll_ pass over it; until
// then, direct reads (doGet/readCeklis_) would misreport every historical
// record's status. migrateSchema_() (called from setup(), and defensively
// from doGet/doPost) closes that gap deterministically.
//
// These tests manually seed a MockSheet with an OLD header + real
// historical rows BEFORE calling setup() — i.e. exactly what a real
// pre-existing production sheet looks like — using loadNewBackendNoSetup()
// so nothing auto-creates it with the new shape first.
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
  /* ===================== CeklisMeta: 7-column old header (my own first patch's shape) ===================== */
  const api = loadNewBackendNoSetup();
  const ss = api.__state.ss;
  seedSheet(ss, "CeklisMeta",
    ["Tanggal","Divisi","SubmittedAt","Closed","ClosedAt","ClosedBy","ReopenReason"],
    [
      ["2026-01-01","Pastry","01/01/2026 10:00","true","01/01/2026 10:00","Budi",""],   // submitted historis
      ["2026-01-02","Bolu","02/01/2026 09:00","false","","","koreksi manual"],           // reopened historis (SubmittedAt ada, Closed false)
      ["2026-01-03","Basic","","false","","",""],                                        // belum pernah disubmit sama sekali
    ]
  );
  const beforeHeader = api.readHeaderRow_(ss.getSheetByName("CeklisMeta"));
  check("MIG01.oldHeaderIs7Cols", "MIG01 — header lama (7 kolom, tanpa Status) tersimulasikan dgn benar sblm migrasi",
    7, beforeHeader.length);

  api.setup(); // <-- migrasi harus terjadi di sini

  const afterHeader = api.readHeaderRow_(ss.getSheetByName("CeklisMeta"));
  check("MIG02.newHeaderIs8ColsWithStatusAfterDivisi", "MIG02 — setelah setup(), header jadi 8 kolom, Status persis setelah Divisi",
    JSON.stringify(["Tanggal","Divisi","Status","SubmittedAt","Closed","ClosedAt","ClosedBy","ReopenReason"]), JSON.stringify(afterHeader));

  const migratedRows = readSheetRows(api, "CeklisMeta");
  check("MIG03.rowCountPreserved", "MIG03 — jumlah baris historis TIDAK berubah (3 baris tetap 3)",
    3, migratedRows.length);

  const pastryRow = migratedRows.find(r=>r.Divisi==="Pastry");
  check("MIG04.submittedRuleApplied", "MIG04 — SubmittedAt ada + Closed=true -> Status=submitted",
    "submitted", pastryRow.Status);
  check("MIG05.pastryValuesNotShifted", "MIG05 — nilai Pastry lain TIDAK bergeser kolom (SubmittedAt/Closed/ClosedAt/ClosedBy tetap benar)",
    JSON.stringify({SubmittedAt:"01/01/2026 10:00", Closed:"true", ClosedAt:"01/01/2026 10:00", ClosedBy:"Budi", ReopenReason:""}),
    JSON.stringify({SubmittedAt:pastryRow.SubmittedAt, Closed:pastryRow.Closed, ClosedAt:pastryRow.ClosedAt, ClosedBy:pastryRow.ClosedBy, ReopenReason:pastryRow.ReopenReason}));

  const boluRow = migratedRows.find(r=>r.Divisi==="Bolu");
  check("MIG06.reopenedRuleApplied", "MIG06 — SubmittedAt ada + Closed=false -> Status=reopened",
    "reopened", boluRow.Status);
  check("MIG07.boluValuesNotShifted", "MIG07 — nilai Bolu (termasuk ReopenReason lama) TIDAK bergeser",
    JSON.stringify({SubmittedAt:"02/01/2026 09:00", Closed:"false", ReopenReason:"koreksi manual"}),
    JSON.stringify({SubmittedAt:boluRow.SubmittedAt, Closed:boluRow.Closed, ReopenReason:boluRow.ReopenReason}));

  const basicRow = migratedRows.find(r=>r.Divisi==="Basic");
  check("MIG08.notStartedRuleApplied", "MIG08 — tidak ada SubmittedAt sama sekali -> Status=not_started",
    "not_started", basicRow.Status);

  // Idempotent: run setup() again, nothing should change (no double column, no re-migration).
  const snapshotBeforeSecondSetup = JSON.stringify(readSheetRows(api, "CeklisMeta"));
  api.setup();
  const headerAfterSecondSetup = api.readHeaderRow_(ss.getSheetByName("CeklisMeta"));
  check("MIG09.setupTwiceNoDoubleStatusColumn", "MIG09 — setup() dijalankan 2x TIDAK menambah kolom Status lagi (masih 8 kolom, bukan 9)",
    JSON.stringify(afterHeader), JSON.stringify(headerAfterSecondSetup));
  const snapshotAfterSecondSetup = JSON.stringify(readSheetRows(api, "CeklisMeta"));
  check("MIG10.setupTwiceDataUnchanged", "MIG10 — setup() ke-2 TIDAK mengubah data yg sudah termigrasi",
    snapshotBeforeSecondSetup, snapshotAfterSecondSetup);

  // readCeklis_ (via doGet) reads Status/SubmittedAt/Closed correctly post-migration.
  // Need at least one Ceklis row per divisi+tanggal for readCeklis_ to surface the meta.
  ["2026-01-01|Pastry","2026-01-02|Bolu","2026-01-03|Basic"].forEach((key,i)=>{
    const [tgl, divisi] = key.split("|");
    const shC = api.getOrCreateSheet(api.SHEET_CEKLIS);
    shC.appendRow([tgl, divisi, "K"+i, "PRODUK "+i, "KAT", 10, "sesuai", 10, 0, "", new Date()]);
  });
  const got = api.doGetJSON();
  const gPastry = got.ceklis.find(r=>r.tanggal==="2026-01-01" && r.divisi==="Pastry");
  const gBolu = got.ceklis.find(r=>r.tanggal==="2026-01-02" && r.divisi==="Bolu");
  const gBasic = got.ceklis.find(r=>r.tanggal==="2026-01-03" && r.divisi==="Basic");
  check("MIG11.readCeklisSurfacesCorrectMetaStatus", "MIG11 — readCeklis_ (lewat doGet) membaca metaStatus benar utk ketiga baris historis",
    JSON.stringify({pastry:"submitted", bolu:"reopened", basic:"not_started"}),
    JSON.stringify({pastry:gPastry.metaStatus, bolu:gBolu.metaStatus, basic:gBasic.metaStatus}));
  check("MIG12.readCeklisSurfacesCorrectSubmittedAt", "MIG12 — readCeklis_ membaca SubmittedAt/Closed benar (bukan hanya Status)",
    JSON.stringify({pastrySubmittedAt:"01/01/2026 10:00", pastryClosed:true, basicSubmittedAt:"", basicClosed:false}),
    JSON.stringify({pastrySubmittedAt:gPastry.submittedAt, pastryClosed:gPastry.closed, basicSubmittedAt:gBasic.submittedAt, basicClosed:gBasic.closed}));

  /* ===================== CeklisMeta: TRUE original 5-column legacy header ===================== */
  const api2 = loadNewBackendNoSetup();
  const ss2 = api2.__state.ss;
  seedSheet(ss2, "CeklisMeta",
    ["Tanggal","Divisi","SubmittedAt","Closed","ClosedAt"], // bentuk ASLI dari Code.legacy.gs — tanpa ClosedBy/ReopenReason SAMA SEKALI
    [["2026-02-01","Roti & Bollen","01/02/2026 08:00","true","01/02/2026 08:00"]]
  );
  api2.setup();
  const migratedLegacy5 = readSheetRows(api2, "CeklisMeta")[0];
  check("MIG13.trueOriginal5ColLegacyHeaderAlsoMigrates", "MIG13 — header ASLI legacy (5 kolom, tanpa ClosedBy/ReopenReason sama sekali) tetap termigrasi dgn benar",
    JSON.stringify({Status:"submitted", ClosedBy:"", ReopenReason:""}),
    JSON.stringify({Status:migratedLegacy5.Status, ClosedBy:migratedLegacy5.ClosedBy, ReopenReason:migratedLegacy5.ReopenReason}));

  /* ===================== Unrecognized header -> migration safely skipped ===================== */
  const api3 = loadNewBackendNoSetup();
  const ss3 = api3.__state.ss;
  seedSheet(ss3, "CeklisMeta", ["KolomAsing1","KolomAsing2"], [["x","y"]]);
  api3.setup();
  const unrecognizedRows = readSheetRows(api3, "CeklisMeta");
  check("MIG14.unrecognizedHeaderNotGuessed", "MIG14 — header yg tidak dikenali TIDAK ditebak-tebak/dirusak, data mentah tetap apa adanya",
    JSON.stringify([{KolomAsing1:"x", KolomAsing2:"y"}]), JSON.stringify(unrecognizedRows));

  /* ===================== FGPacking migration ===================== */
  const api4 = loadNewBackendNoSetup();
  const ss4 = api4.__state.ss;
  seedSheet(ss4, "FGPacking",
    ["Tanggal","Factory","Kode","Toko","Qty","Status","Keterangan","UpdatedAt"],
    [["2026-03-01","karangtengah","K1","TOKO A",50,"sesuai","",new Date()]]
  );
  api4.setup();
  const fgHeader = api4.readHeaderRow_(ss4.getSheetByName("FGPacking"));
  check("MIG15.fgPackingGetsProdukColumn", "MIG15 — FGPacking lama (8 kolom) -> 9 kolom dgn Produk setelah Kode",
    JSON.stringify(["Tanggal","Factory","Kode","Produk","Toko","Qty","Status","Keterangan","UpdatedAt"]), JSON.stringify(fgHeader));
  const fgRow = readSheetRows(api4, "FGPacking")[0];
  check("MIG16.fgPackingOldValuesPreserved", "MIG16 — nilai FGPacking lama (Kode/Toko/Qty/Status) tidak bergeser, Produk default kosong",
    JSON.stringify({Kode:"K1", Produk:"", Toko:"TOKO A", Qty:50, Status:"sesuai"}),
    JSON.stringify({Kode:fgRow.Kode, Produk:fgRow.Produk, Toko:fgRow.Toko, Qty:fgRow.Qty, Status:fgRow.Status}));

  /* ===================== FGReady migration ===================== */
  const api5 = loadNewBackendNoSetup();
  const ss5 = api5.__state.ss;
  seedSheet(ss5, "FGReady", ["Tanggal","Factory","ReadyAt"], [["2026-03-02","cibadak","02/03/2026 14:00"]]);
  api5.setup();
  const fgrHeader = api5.readHeaderRow_(ss5.getSheetByName("FGReady"));
  check("MIG17.fgReadyGetsSourceVersionColumn", "MIG17 — FGReady lama (3 kolom) -> 4 kolom dgn SourceVersionJSON",
    JSON.stringify(["Tanggal","Factory","ReadyAt","SourceVersionJSON"]), JSON.stringify(fgrHeader));
  const fgrRow = readSheetRows(api5, "FGReady")[0];
  check("MIG18.fgReadyDefaultsEmptyMap", "MIG18 — SourceVersionJSON default \"{}\" utk baris historis",
    "{}", fgrRow.SourceVersionJSON);

  /* ===================== Safety net via doGet/doPost, not just setup() ===================== */
  const api6 = loadNewBackendNoSetup();
  const ss6 = api6.__state.ss;
  seedSheet(ss6, "CeklisMeta",
    ["Tanggal","Divisi","SubmittedAt","Closed","ClosedAt","ClosedBy","ReopenReason"],
    [["2026-04-01","Bolu","01/04/2026 10:00","true","01/04/2026 10:00","",""]]
  );
  // TIDAK memanggil setup() sama sekali — langsung doGet(), migrasi harus tetap jalan sbg jaring pengaman.
  api6.doGetJSON();
  const headerViaDoGet = api6.readHeaderRow_(ss6.getSheetByName("CeklisMeta"));
  check("MIG19.doGetTriggersMigrationEvenWithoutSetup", "MIG19 — doGet() SENDIRI (tanpa setup() pernah dipanggil) tetap memicu migrasi",
    8, headerViaDoGet.length);

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== SCHEMA MIGRATION (CeklisMeta/FGPacking/FGReady) ===\n");
  results.forEach(r=>console.log(` ${r.pass?"PASS":"FAIL"}  ${r.id}: ${r.scenario}`));
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main();
