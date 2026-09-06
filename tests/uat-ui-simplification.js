"use strict";
// Verifies the Dashboard/Produksi UI-simplification pass: fewer primary
// cards, unfinished-first sorting, drill-down behavior. Does NOT re-test
// canonical store / daily omset / Kirim Toko business logic — those already
// have their own suites (uat-canonical-store.js, uat-daily-omset.js) and are
// explicitly out of scope for this pass.
//
// Drives the REAL app functions (poProses, pdSimpan, prodBuildChecklist,
// renderDashboard, pdRingkasanHariIni/renderPdRingkasan/pdBukaDivisi) via
// jsdom + real xlsx — no business logic reimplemented here.
const { loadApp, XLSX } = require("./uat-harness.js");

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

async function main(){
  const api = loadApp();
  const TGL = "2026-09-15";

  // Divisi 1 ("Basic"): selesai penuh (target 100, aktual 100).
  const rowsBasic = [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","TOTAL","","TOTAL","","TOTAL"],
    ["","","","","TOKO SIMPLIFY","","TOKO SIMPLIFY","","",""],
    [1,"BASIC","SIMP01","PROD SIMPLE A", 100, 100, 0,0, 0,0],
  ];
  await uploadPO(api, rowsBasic, {factory:"karangtengah", tgl:TGL, sheetName:"15"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();

  // Divisi 2 ("Roti & Bollen"): baru sebagian (target 80, aktual 30, sisa 50).
  const rowsRoti = [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","TOTAL","","TOTAL","","TOTAL"],
    ["","","","","TOKO SIMPLIFY","","TOKO SIMPLIFY","","",""],
    [1,"ROTI TAWAR","SIMP02","PROD SIMPLE B", 80, 80, 0,0, 0,0],
  ];
  await uploadPO(api, rowsRoti, {factory:"karangtengah", tgl:TGL, sheetName:"15"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();

  // Divisi 3 ("Pastry"): belum disentuh sama sekali — ini yang membuat
  // dashPipelineData().produksi ("Sedang Diproduksi") > 0, dipindah ke
  // "Fokus Hari Ini" alih-alih jadi kartu primer di header Dashboard.
  const rowsPastry = [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","TOTAL","","TOTAL","","TOTAL"],
    ["","","","","TOKO SIMPLIFY","","TOKO SIMPLIFY","","",""],
    [1,"PASTRY","SIMP03","PROD SIMPLE C", 20, 20, 0,0, 0,0],
  ];
  await uploadPO(api, rowsPastry, {factory:"karangtengah", tgl:TGL, sheetName:"15"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();

  const divisiBasic = api.divisiProduk(api.D.po[TGL].find(r=>r.kode==="SIMP01"));
  const divisiRoti = api.divisiProduk(api.D.po[TGL].find(r=>r.kode==="SIMP02"));
  check("SETUP.divisiDifferent", "Setup — dua divisi berbeda terbentuk dari 2 kategori", true, divisiBasic!==divisiRoti);

  submitCeklis(api, TGL, divisiBasic); // default "sesuai" -> aktual penuh (100)
  submitCeklis(api, TGL, divisiRoti, {SIMP02:{aktual:30, reject:0}}); // sengaja parsial

  /* ===================== PRODUKSI: ringkasan lintas-divisi ===================== */
  const ringkasan = api.pdRingkasanHariIni(TGL);
  const rowBasic = ringkasan.find(r=>r.divisi===divisiBasic);
  const rowRoti = ringkasan.find(r=>r.divisi===divisiRoti);
  check("PROD.basicSelesai", "Produksi — divisi Basic (100/100) berstatus Selesai, sisa 0", "Selesai", rowBasic ? rowBasic.status : null);
  check("PROD.rotiBerjalan", "Produksi — divisi Roti & Bollen (30/80) masih Berjalan, sisa 50", JSON.stringify({status:"Berjalan", sisa:50}),
    rowRoti ? JSON.stringify({status:rowRoti.status, sisa:rowRoti.sisa}) : null);
  check("PROD.unfinishedFirst", "Produksi — divisi yang BELUM selesai (Roti & Bollen) tampil lebih dulu dari yang sudah Selesai (Basic)",
    true, ringkasan.findIndex(r=>r.divisi===divisiRoti) < ringkasan.findIndex(r=>r.divisi===divisiBasic));

  api.renderPdRingkasan();
  const wrapEl = api.$("pdRingkasanWrap");
  check("PROD.wrapVisible", "Produksi — kartu ringkasan tampil (display block) begitu ada data tanggal ini", "block", wrapEl.style.display);
  const statsHtml = api.$("pdRingkasanStats").innerHTML;
  check("PROD.totalTarget200", "Produksi — Total Target header = 100 + 80 + 20 (Pastry, belum disubmit) = 200", true, statsHtml.includes(">200<"));
  check("PROD.totalAktual130", "Produksi — Total Aktual header = 100 + 30 + 0 = 130", true, statsHtml.includes(">130<"));
  check("PROD.totalSisa70", "Produksi — Total Sisa header = 200 - 130 = 70", true, statsHtml.includes(">70<"));

  // Drill-down: klik baris divisi Roti & Bollen harus memindahkan pd-divisi
  // & membangun ulang ceklis SKU-nya (bukan menampilkan semua SKU sekaligus).
  api.$("pd-divisi").value = divisiBasic; // mulai dari divisi LAIN dulu
  api.pdBukaDivisi(divisiRoti);
  check("PROD.drillDownSwitchesDivisi", "Produksi — pdBukaDivisi() memindahkan pd-divisi ke divisi yang diklik",
    divisiRoti, api.$("pd-divisi").value);
  check("PROD.drillDownShowsOwnSku", "Produksi — setelah drill-down, ceklis menampilkan SKU divisi itu (PROD SIMPLE B)",
    true, api.$("pdBody").innerHTML.includes("PROD SIMPLE B"));

  /* ===================== DASHBOARD: header 4 kartu + Fokus Hari Ini ===================== */
  api.$("db-dari").value = TGL; api.$("db-sampai").value = TGL;
  api.renderDashboard();
  const pipelineHtml = api.$("db-pipelineStats").innerHTML;
  const labelCount = (pipelineHtml.match(/class="lbl"/g)||[]).length;
  check("DASH.onlyFourPrimaryCards", "Dashboard — header pipeline cuma 4 kartu utama (bukan 5)", 4, labelCount);
  check("DASH.hasPesananOutlet", "Dashboard — kartu 'Pesanan Outlet' ada di header", true, pipelineHtml.includes("Pesanan Outlet"));
  check("DASH.hasSelesaiProduksi", "Dashboard — kartu 'Selesai Produksi (FG)' ada di header", true, pipelineHtml.includes("Selesai Produksi (FG)"));
  check("DASH.hasSiapKirim", "Dashboard — kartu 'Siap Kirim' ada di header", true, pipelineHtml.includes("Siap Kirim"));
  check("DASH.hasSudahTerkirim", "Dashboard — kartu 'Sudah Terkirim' ada di header", true, pipelineHtml.includes("Sudah Terkirim"));
  check("DASH.sedangDiproduksiMovedOut", "Dashboard — 'Sedang Diproduksi' TIDAK lagi jadi kartu primer di header",
    false, pipelineHtml.includes("Sedang Diproduksi"));

  const fokusHtml = api.$("dbFokusBody").innerHTML;
  check("DASH.fokusMentionsProduksi", "Dashboard — Fokus Hari Ini menyebutkan qty yang masih diproduksi (dipindah dari header)",
    true, fokusHtml.includes("masih dalam produksi"));

  const d = api.dashPipelineData(TGL,TGL);
  check("DASH.pipelineDataUnchanged_pesanan", "Dashboard — dashPipelineData() tetap benar (Pesanan Outlet = 100+80+20 = 200, tidak berubah krn UI)",
    200, d.pesanan);
  check("DASH.pipelineDataUnchanged_selesai", "Dashboard — dashPipelineData() Selesai Produksi tetap benar (FG belum disubmit = 0)",
    0, d.selesai);
  check("DASH.pipelineDataUnchanged_produksi", "Dashboard — dashPipelineData() Sedang Diproduksi tetap benar (divisi Pastry belum disubmit = 20)",
    20, d.produksi);

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== UI SIMPLIFICATION (Dashboard/Produksi) TEST TABLE ===\n");
  console.log("ID".padEnd(34), "Scenario".padEnd(90), "Expected".padEnd(10), "Actual".padEnd(10), "Result");
  results.forEach(r=>{
    console.log(r.id.padEnd(34), r.scenario.slice(0,90).padEnd(90), JSON.stringify(r.expected).slice(0,10).padEnd(10), JSON.stringify(r.actual).slice(0,10).padEnd(10), r.pass?"PASS":"FAIL");
  });
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, r.scenario, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main().catch(e=>{ console.error("SUITE CRASHED", e); process.exit(2); });
