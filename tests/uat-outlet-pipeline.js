"use strict";
// Regression + mechanism-reproduction suite for the "Tracing Posisi Barang"
// Outlet-vs-Non-Outlet dashboard fix (dashPipelineData). Drives the real app
// functions (poProses/poSimpanPreview/prodBuildChecklist/pdSimpan/psSimpan/
// dashPipelineData/renderDashPipeline) via jsdom + real xlsx — no business
// logic reimplemented.
const { loadApp, XLSX } = require("./uat-harness.js");
const fs = require("fs");

const results = [];
function check(id, scenario, expected, actual){
  const pass = JSON.stringify(expected) === JSON.stringify(actual);
  results.push({id, scenario, expected, actual, pass});
  return pass;
}

function makeXlsxFile(rows, sheetName){
  const ws = XLSX.utils.aoa_to_sheet(rows);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, sheetName || "01");
  const buf = XLSX.write(wb, {type:"buffer", bookType:"xlsx"});
  const ab = buf.buffer.slice(buf.byteOffset, buf.byteOffset+buf.byteLength);
  return { name:"po.xlsx", arrayBuffer: async () => ab };
}
async function uploadPO(api, rows, {factory, tgl, sheetName}){
  const fileInput = api.$("po-file");
  Object.defineProperty(fileInput, "files", { value:[makeXlsxFile(rows, sheetName)], configurable:true });
  api.$("po-factory").value = factory;
  api.$("po-tgl").value = tgl;
  await api.poProses();
}
function simpleSheet(kode, produk, poAwal, poRevisi){
  return [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","TOTAL","","TOTAL","PB","TOTAL"],
    ["","","","","TOKO_X","","TOKO_X","","",""],
    [1,"BASIC",kode,produk, poAwal, poAwal, poRevisi||0, poRevisi||0, 0, 0],
  ];
}
function submitCeklis(api, tgl, divisi, patchByKode){
  api.$("pd-tgl").value = tgl;
  api.$("pd-divisi").value = divisi;
  api.prodBuildChecklist();
  if(patchByKode){
    Object.entries(patchByKode).forEach(([kode, patch])=>{
      const tr = api.__document.querySelector(`#pdBody tr[data-kode="${kode}"]`);
      if(!tr) return;
      if(patch.aktual!=null) tr.querySelector('[data-c="aktual"]').value = String(patch.aktual);
      if(patch.reject!=null) tr.querySelector('[data-c="reject"]').value = String(patch.reject);
    });
  }
  api.pdSimpan();
}
// Real "Pesanan Non-Outlet" order fulfilled via production, sumber:"produksi",
// added directly to D.pesanan the same shape psSimpan() would produce (psSimpan
// itself is a DOM-form reader like pdBaca/kBaca — this seeds the same D.pesanan
// record structure it writes, so targetPesananDivisi/dashPipelineData under
// test read genuinely real state, not a parallel reimplementation of their logic).
function addPesananNonOutlet(api, {tglProduksi, produk, qty}){
  api.D.pesanan.push({
    id: api.uid(), no: "PS-"+api.uid(), tglPesan: tglProduksi, tglProduksi, tglAmbil: tglProduksi,
    tipe: "produksi", pemesan: "Test Non-Outlet", kontak:"", alamat:"", pctOmset:100, sumber:"produksi",
    items: [{produk, qty, harga:0}], catatan:"", status:"proses", createdAt: new Date().toISOString(),
  });
  api.saveD();
}

async function testCase(name, {poOutlet, nonOutlet, aktual, reject}, expected){
  const api = loadApp();
  const TGL = "2026-10-01", FAC = "karangtengah", KODE = "KX1", PRODUK = "PRODUK PIPELINE";
  await uploadPO(api, simpleSheet(KODE, PRODUK, poOutlet, 0), {factory:FAC, tgl:TGL, sheetName:"01"});
  api.poResolusiJadiBaru(0);
  api.poSimpanPreview();
  // targetPesananDivisi() resolves a Non-Outlet item's divisi via Master Produk
  // (mpGet), separately from how a PO row's divisi falls back to kategori
  // mapping — a brand-new product only exists in D.products until someone
  // fills in Master Produk, so give it a real MP entry here (as any producible
  // product would have in practice) or the non-outlet order can't be divisi-matched.
  api.D.masterProduk[PRODUK] = {kategori:"BASIC", divisi:"Basic", hpp:0, harga:0, aktif:true, updatedAt:""};
  if(nonOutlet>0) addPesananNonOutlet(api, {tglProduksi:TGL, produk:PRODUK, qty:nonOutlet});
  const divisi = api.divisiProduk(api.D.po[TGL][0]);
  // production: submit exactly `aktual` (real DOM row, matches whatever
  // targetUntukDivisi computed as the combined outlet+non-outlet target).
  submitCeklis(api, TGL, divisi, {[KODE]: {aktual, reject: reject||0}});
  // Finishgood's OWN checklist verifies the same netto (real function call).
  submitCeklis(api, TGL, api.SPECIAL_FG, {[KODE]: {aktual: (aktual-(reject||0)), reject:0}});

  api.$("db-dari").value = TGL; api.$("db-sampai").value = TGL;
  const d = api.__window.eval(`dashPipelineData("${TGL}","${TGL}")`);
  api.__window.eval("renderDashboard()"); // exercise the real renderer too (no crash, populates real DOM)

  const fulfillmentPct = d.pesanan ? Math.round((d.selesai/d.pesanan)*1000)/10 : null;
  check(name+".pesananOutlet", `${name}: Pesanan Outlet`, expected.pesanan, d.pesanan);
  check(name+".fgOutlet", `${name}: Selesai Produksi (FG) Outlet`, expected.selesai, d.selesai);
  if(expected.fulfillmentPct!=null) check(name+".fulfillmentPct", `${name}: Ratio outlet %`, expected.fulfillmentPct, fulfillmentPct);
  if(expected.overproduksi!=null) check(name+".overproduksi", `${name}: Overproduksi`, expected.overproduksi, d.overproduksi);
  if(expected.selesaiNonOutlet!=null) check(name+".selesaiNonOutlet", `${name}: Selesai Non-Outlet`, expected.selesaiNonOutlet, d.selesaiNonOutlet);
  return { api, d };
}

async function main(){
  // TEST A — no non-outlet at all
  await testCase("TESTA", {poOutlet:100, nonOutlet:0, aktual:100}, {pesanan:100, selesai:100, fulfillmentPct:100});

  // TEST B — non-outlet present, FG=120 (outlet+non-outlet both fully produced) -> FG Outlet must stay 100, not 120
  await testCase("TESTB", {poOutlet:100, nonOutlet:20, aktual:120}, {pesanan:100, selesai:100, fulfillmentPct:100, selesaiNonOutlet:20, overproduksi:0});

  // TEST C — production short: FG=80 (less than outlet target alone)
  await testCase("TESTC", {poOutlet:100, nonOutlet:20, aktual:80}, {pesanan:100, selesai:80, fulfillmentPct:80, selesaiNonOutlet:0, overproduksi:0});

  // TEST D — production exceeds TOTAL target (100+20=120): FG=130 -> outlet capped 100, non-outlet capped 20, excess 10 = overproduksi
  await testCase("TESTD", {poOutlet:100, nonOutlet:20, aktual:130}, {pesanan:100, selesai:100, fulfillmentPct:100, selesaiNonOutlet:20, overproduksi:10});

  // TEST J (reject) — PO100, aktual100, reject5 -> FG netto 95, outlet pipeline 95%
  await testCase("TESTJ", {poOutlet:100, nonOutlet:0, aktual:100, reject:5}, {pesanan:100, selesai:95, fulfillmentPct:95});

  // TEST F — non-outlet still counted in actual PRODUCTION target (not dashboard) —
  // verify via the real ceklis record itself (not the dashboard), proving section 5.3 wasn't violated.
  {
    const { api } = await testCase("TESTF_setup", {poOutlet:100, nonOutlet:20, aktual:120}, {pesanan:100, selesai:100});
    const divisi = api.divisiProduk(api.D.po["2026-10-01"][0]);
    const combinedTarget = api.targetUntukDivisi("2026-10-01", divisi).find(r=>r.kode==="KX1").target;
    check("TESTF.nonOutletStillInProductionTarget", "TESTF: target produksi tetap gabungan (120), Non-Outlet tidak dihapus", 120, combinedTarget);
  }

  // TEST G — FG still not double-counted as new production (main dashboard KPI, from previous session's fix)
  {
    const { api } = await testCase("TESTG_setup", {poOutlet:100, nonOutlet:0, aktual:100}, {pesanan:100, selesai:100});
    const w = api.__window;
    w.eval('renderDashboard()');
    const card = api.$("db-stats").innerHTML.match(/Total Aktual<\/div><div class="val">([\d.,]+)<\/div>/);
    check("TESTG.dashboardTotalAktualNotDoubled", "TESTG: Total Aktual KPI utama tidak dobel (100, bukan 200)", 100, card?parseInt(card[1].replace(/[.,]/g,""),10):null);
  }

  // TEST H — stock stays based on REAL FG actual, not the dashboard's outlet-capped number
  {
    const { api } = await testCase("TESTH_setup", {poOutlet:100, nonOutlet:20, aktual:120}, {pesanan:100, selesai:100});
    check("TESTH.stockUsesRealFGNotCapped", "TESTH: stokGudang pakai FG actual penuh (120), BUKAN angka outlet-capped dashboard (100)", 120, api.stokGudang("PRODUK PIPELINE"));
  }

  // TEST I — DO still based on available/packing, untouched by this patch
  {
    const { api } = await testCase("TESTI_setup", {poOutlet:100, nonOutlet:0, aktual:100}, {pesanan:100, selesai:100});
    api.$("k-tgl").value = "2026-10-01";
    const sel = api.$("k-toko");
    const opt = api.__document.createElement("option"); opt.value="TOKO_X"; opt.textContent="TOKO_X";
    sel.appendChild(opt); sel.value = "TOKO_X";
    api.kBuildGrid();
    const idx = api.kList.indexOf("PRODUK PIPELINE");
    check("TESTI.doGridUsesFullStock", "TESTI: grid DO tidak dibatasi oleh angka outlet-capped dashboard", false, idx<0 || api.$("kq-"+idx).disabled);
  }

  /* =====================================================================
     REAL DATA — Karangtengah & Cibadak September 2026 CSVs uploaded exactly
     as PPIC provided them, parsed through the REAL parsePOAuto(). This is
     the actual "Pesanan Outlet" figure the live dashboard would compute:
     confirmed to equal 13,435 (9,591 Karangtengah + 3,844 Cibadak) — the
     exact number reported from the live site. FG/production actuals from
     the live browser's localStorage are NOT available in this repo, so the
     581 pcs gap itself cannot be replayed pcs-for-pcs here — what's proven
     below is the MECHANISM (Test B/D above) plus a duplicate-kode audit on
     the real source files.
     ===================================================================== */
  {
    const api = loadApp();
    function parseCsvToRows(path){
      const csv = fs.readFileSync(path, "utf8");
      const wb = XLSX.read(csv, {type:"string"});
      const ws = wb.Sheets[wb.SheetNames[0]];
      return XLSX.utils.sheet_to_json(ws, {header:1, raw:true, defval:""});
    }
    const rowsKT = parseCsvToRows(process.env.KT_CSV || "/tmp/karangtengah.csv");
    const rowsBolu = parseCsvToRows(process.env.BOLU_CSV || "/tmp/bolu.csv");
    const parsedKT = api.parsePOAuto(rowsKT);
    const parsedBolu = api.parsePOAuto(rowsBolu);
    const totalKT = parsedKT.reduce((a,r)=>a+r.poAwal+r.poRevisi,0);
    const totalBolu = parsedBolu.reduce((a,r)=>a+r.poAwal+r.poRevisi,0);
    check("REALDATA.karangtengahTarget", "Real CSV — Karangtengah target (Awal 9533 + Revisi 58)", 9591, totalKT);
    check("REALDATA.cibadakTarget", "Real CSV — Cibadak target", 3844, totalBolu);
    check("REALDATA.grandTotalMatchesLiveDashboard", "Real CSV — total Outlet cocok dgn 'Pesanan' live dashboard (13.435)", 13435, totalKT+totalBolu);

    const kodeCount = {};
    parsedKT.forEach(r=>{ kodeCount[r.kode] = (kodeCount[r.kode]||0)+1; });
    const dupKode = Object.entries(kodeCount).filter(([k,c])=>c>1);
    check("REALDATA.duplicateKodeInSourceFile", "Real CSV — kode yg dipakai >1 produk BERBEDA di file sumber (temuan terpisah, lihat laporan)",
      true, dupKode.length>0);
    results.push({id:"REALDATA.duplicateKodeDetail", scenario:"Real CSV — daftar kode duplikat (bukan pass/fail, informasi)",
      expected:"-", actual: JSON.stringify(dupKode), pass:true});
  }

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== OUTLET PIPELINE TEST TABLE ===\n");
  console.log("ID".padEnd(40), "Scenario".padEnd(70), "Expected".padEnd(10), "Actual".padEnd(10), "Result");
  results.forEach(r=>{
    console.log(r.id.padEnd(40), r.scenario.slice(0,70).padEnd(70), JSON.stringify(r.expected).padEnd(10), JSON.stringify(r.actual).padEnd(10), r.pass?"PASS":"FAIL");
  });
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, r.scenario, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main().catch(e=>{ console.error("SUITE CRASHED", e); process.exit(2); });
