"use strict";
// Reproduces and verifies the fix for the "kode collision" bug: the real PO
// files audited earlier (PO_SEPTEMBER_2026_KARANGTENGAH) have 7 kode values
// each shared by 2-3 DIFFERENT products (source spreadsheet data-entry error,
// e.g. kode "BSC008" used for both "MUFFIN VANILLA" and "MUFFIN COKLAT").
// Every function in this app that keyed ceklis/FG/packing/dashboard state by
// bare `kode` therefore silently merged, mis-attributed, or dropped one of
// the two products' numbers — and since stokGudang() reads straight off
// those ceklis rows, warehouse stock itself was affected, not just a report.
//
// This suite drives the REAL app functions end-to-end (poProses, pdSimpan,
// fgTandaiSiap, kBuildGrid/kSimpan, kBukaInvoice/kInvoiceSimpan, dashboard)
// via jsdom + real xlsx — no business logic reimplemented.
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
function ensureTokoOption(api, toko){
  const sel = api.$("k-toko");
  if(![...sel.options].some(o=>o.value===toko)){
    const opt = api.__document.createElement("option"); opt.value = toko; opt.textContent = toko;
    sel.appendChild(opt);
  }
}

// Two DIFFERENT products, SAME kode, TWO different stores each — mirrors the
// real file (kode shared across products) plus store overlap risk for packing.
const KODE = "DUP1", TGL = "2026-09-10", FAC = "karangtengah";
const rowsPO = [
  ["NO","KATEGORI","KODE","NAMA PRODUK","","","TOTAL","","","TOTAL","","","TOTAL"],
  ["","","","","STORE_A","STORE_B","","STORE_A","STORE_B","","","",""],
  [1,"BASIC",KODE,"PRODUK A", 40,0, 40, 0,0, 0, 0,0,0],
  [2,"BASIC",KODE,"PRODUK B", 0,60, 60, 0,0, 0, 0,0,0],
];

async function setup(){
  const api = loadApp();
  await uploadPO(api, rowsPO, {factory:FAC, tgl:TGL, sheetName:"10"});
  [0,1].forEach(i=>api.poResolusiJadiBaru(i));
  api.poSimpanPreview();
  api.D.masterProduk["PRODUK A"] = {kategori:"BASIC", divisi:"Basic", hpp:0, harga:1000, aktif:true, updatedAt:""};
  api.D.masterProduk["PRODUK B"] = {kategori:"BASIC", divisi:"Basic", hpp:0, harga:2000, aktif:true, updatedAt:""};
  return api;
}

async function main(){
  const api = await setup();

  check("REPRO.twoProductsSameKode", "2 produk berbeda memakai kode yang sama (fixture nyata)",
    JSON.stringify(["PRODUK A","PRODUK B"]),
    JSON.stringify(api.D.po[TGL].filter(r=>r.kode===KODE).map(r=>r.produk)));

  const divisi = api.divisiProduk(api.D.po[TGL][0]);
  // Real production submit — default "sesuai" = aktual matches each row's own target (40, 60).
  api.$("pd-tgl").value = TGL; api.$("pd-divisi").value = divisi;
  api.prodBuildChecklist();
  api.pdSimpan();
  const sourceCeklis = api.D.ceklis[api.ceklisKey(TGL,divisi)].rows;
  check("PDSIMPAN.noMerge", "pdSimpan() TIDAK menggabungkan 2 produk beda jadi 1 row",
    2, sourceCeklis.length);
  check("PDSIMPAN.produkACorrect", "pdSimpan() — aktual PRODUK A benar (40, bukan tercampur)",
    40, (sourceCeklis.find(r=>r.produk==="PRODUK A")||{}).aktual);
  check("PDSIMPAN.produkBCorrect", "pdSimpan() — aktual PRODUK B benar (60, bukan hilang)",
    60, (sourceCeklis.find(r=>r.produk==="PRODUK B")||{}).aktual);

  // Real FG checklist — targetUntukDivisi()'s FG branch must pick the RIGHT
  // source row per product, not the first kode match for both.
  api.$("pd-divisi").value = api.SPECIAL_FG;
  api.prodBuildChecklist();
  const fgTarget = api.targetUntukDivisi(TGL, api.SPECIAL_FG);
  check("TARGETDIVISI.fgRowCount", "targetUntukDivisi(FG) menghasilkan 2 baris terpisah (bukan 2x baris sama)",
    2, fgTarget.length);
  check("TARGETDIVISI.fgTargetACorrect", "targetUntukDivisi(FG) — target PRODUK A benar (40, bukan netto gabungan)",
    40, (fgTarget.find(r=>r.produk==="PRODUK A")||{}).target);
  check("TARGETDIVISI.fgTargetBCorrect", "targetUntukDivisi(FG) — target PRODUK B benar (60, bukan netto gabungan)",
    60, (fgTarget.find(r=>r.produk==="PRODUK B")||{}).target);

  api.pdSimpan(); // real FG verification submit
  const fgCeklis = api.D.ceklis[api.ceklisKey(TGL,api.SPECIAL_FG)].rows;
  check("FGSIMPAN.noMerge", "FG pdSimpan() TIDAK menggabungkan 2 produk beda jadi 1 row",
    2, fgCeklis.length);

  check("STOK.produkA", "stokGudang(PRODUK A) benar (40, bukan 100 gabungan)",
    40, api.stokGudang("PRODUK A"));
  check("STOK.produkB", "stokGudang(PRODUK B) benar (60, bukan 0/hilang)",
    60, api.stokGudang("PRODUK B"));

  // Packing per toko: PRODUK A -> STORE_A, PRODUK B -> STORE_B (real fgTandaiSiap/fgMaterializeAll).
  api.prodBuildChecklist(); // rebuilds fgPanel for SPECIAL_FG
  const fgRowA = api.fgStoreRows(TGL,FAC).find(r=>r.produk==="PRODUK A");
  const fgRowB = api.fgStoreRows(TGL,FAC).find(r=>r.produk==="PRODUK B");
  check("PACKING.targetACorrect", "fgStoreRows — target packing PRODUK A/STORE_A benar (40)", 40, fgRowA?fgRowA.target:null);
  check("PACKING.targetBCorrect", "fgStoreRows — target packing PRODUK B/STORE_B benar (60)", 60, fgRowB?fgRowB.target:null);
  const packedA = api.fgGetPacked(TGL,FAC, api.skuId(fgRowA), "STORE_A");
  const packedB = api.fgGetPacked(TGL,FAC, api.skuId(fgRowB), "STORE_B");
  check("PACKING.packedSeparateA", "packing PRODUK A/STORE_A tidak tertukar dgn B (qty=target=40)", 40, packedA.qty);
  check("PACKING.packedSeparateB", "packing PRODUK B/STORE_B tidak tertukar dgn A (qty=target=60)", 60, packedB.qty);
  api.fgTandaiSiap();

  // Delivery Order: pull from FG packing for each store, must not swap products.
  api.$("k-tgl").value = TGL;
  ensureTokoOption(api, "STORE_A"); ensureTokoOption(api, "STORE_B");
  api.kTarikDariFG(TGL, "STORE_A", FAC);
  let idxA = api.kList.indexOf("PRODUK A"), idxB = api.kList.indexOf("PRODUK B");
  const qtyAatStoreA = idxA>=0 ? api.num(api.$("kq-"+idxA).value) : 0;
  const qtyBatStoreA = idxB>=0 ? api.num(api.$("kq-"+idxB).value) : 0;
  check("DO.pullStoreA_getsProdukA", "kTarikDariFG(STORE_A) mengisi qty PRODUK A (40), bukan B", 40, qtyAatStoreA);
  check("DO.pullStoreA_notProdukB", "kTarikDariFG(STORE_A) TIDAK mengisi PRODUK B", 0, qtyBatStoreA);
  api.kSimpan();
  const doBatchA = api.D.kirim.find(r=>r.toko==="STORE_A").batch;

  api.kBaru();
  api.$("k-tgl").value = TGL;
  api.kTarikDariFG(TGL, "STORE_B", FAC);
  idxA = api.kList.indexOf("PRODUK A"); idxB = api.kList.indexOf("PRODUK B");
  const qtyAatStoreB = idxA>=0 ? api.num(api.$("kq-"+idxA).value) : 0;
  const qtyBatStoreB = idxB>=0 ? api.num(api.$("kq-"+idxB).value) : 0;
  check("DO.pullStoreB_getsProdukB", "kTarikDariFG(STORE_B) mengisi qty PRODUK B (60), bukan A", 60, qtyBatStoreB);
  check("DO.pullStoreB_notProdukA", "kTarikDariFG(STORE_B) TIDAK mengisi PRODUK A", 0, qtyAatStoreB);
  api.kSimpan();
  const doBatchB = api.D.kirim.find(r=>r.toko==="STORE_B").batch;

  check("DO.stockAfterBothDO_A", "stokGudang(PRODUK A) setelah DO STORE_A (40-40=0)", 0, api.stokGudang("PRODUK A"));
  check("DO.stockAfterBothDO_B", "stokGudang(PRODUK B) setelah DO STORE_B (60-60=0)", 0, api.stokGudang("PRODUK B"));

  // Invoice: must follow the correct DO/produk, not swapped.
  api.kBukaInvoice(doBatchA);
  api.kInvoiceSimpan();
  api.kBukaInvoice(doBatchB);
  api.kInvoiceSimpan();
  check("INVOICE.batchA_isProdukA", "Invoice batch STORE_A berisi PRODUK A (bukan B)",
    "PRODUK A", (api.D.invoice[doBatchA].items[0]||{}).produk);
  check("INVOICE.batchB_isProdukB", "Invoice batch STORE_B berisi PRODUK B (bukan A)",
    "PRODUK B", (api.D.invoice[doBatchB].items[0]||{}).produk);

  // Dashboard "Per Produk" must show 2 separate rows, not merged into 1.
  const w = api.__window;
  const perProduk = w.eval(`dashProdukData("${TGL}","${TGL}")`);
  const rowsForKode = perProduk.filter(r=>r.kode===KODE);
  check("DASHBOARD.perProdukNotMerged", "Dashboard Per Produk — 2 baris terpisah utk kode yg sama (bukan 1 gabungan)",
    2, rowsForKode.length);
  check("DASHBOARD.perProdukACorrect", "Dashboard Per Produk — target PRODUK A benar (40)",
    40, (rowsForKode.find(r=>r.produk==="PRODUK A")||{}).target);
  check("DASHBOARD.perProdukBCorrect", "Dashboard Per Produk — target PRODUK B benar (60)",
    60, (rowsForKode.find(r=>r.produk==="PRODUK B")||{}).target);

  // Revision PO tetap benar meski kode collision — poIdentitasBaris (kode+nama)
  // harus mencocokkan tiap produk ke barisnya SENDIRI, bukan tertukar krn kode sama.
  const rowsRevisi = [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","","TOTAL","","","TOTAL","","","TOTAL"],
    ["","","","","STORE_A","STORE_B","","STORE_A","STORE_B","","","",""],
    [1,"BASIC",KODE,"PRODUK A", 40,0, 40, 5,0, 5, 999999,0,999999], // PB besar — harus diabaikan
    [2,"BASIC",KODE,"PRODUK B", 0,60, 60, 0,10, 10, 0,999999,999999], // PB besar — harus diabaikan
  ];
  await uploadPO(api, rowsRevisi, {factory:FAC, tgl:TGL, sheetName:"10"});
  check("REVISION.modeDetected", "Revision — mode terdeteksi otomatis meski kode collision", "REVISION", api.poPreview.mode);
  api.poSimpanPreview();
  const rowARev = api.D.po[TGL].find(r=>r.produk==="PRODUK A");
  const rowBRev = api.D.po[TGL].find(r=>r.produk==="PRODUK B");
  check("REVISION.produkA_awalLocked", "Revision — PO Awal PRODUK A tetap terkunci (40), tidak tertukar dgn B", 40, rowARev.poAwal);
  check("REVISION.produkA_revisiCorrect", "Revision — PO Revisi PRODUK A benar (5), bukan milik B", 5, rowARev.poRevisi);
  check("REVISION.produkB_awalLocked", "Revision — PO Awal PRODUK B tetap terkunci (60), tidak tertukar dgn A", 60, rowBRev.poAwal);
  check("REVISION.produkB_revisiCorrect", "Revision — PO Revisi PRODUK B benar (10), bukan milik A", 10, rowBRev.poRevisi);
  check("REVISION.pbIgnored_A", "PB (999999) tidak memengaruhi target PRODUK A (tetap 45)", 45, api.hitungTarget(rowARev));
  check("REVISION.pbIgnored_B", "PB (999999) tidak memengaruhi target PRODUK B (tetap 70)", 70, api.hitungTarget(rowBRev));

  // Unresolved guard tetap jalan — produk baru dgn kode yg SAMA jg dipakai
  // produk lain, tapi namanya belum dikenal sama sekali, tetap harus diblokir.
  const rowsGuard = [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","","TOTAL","","","TOTAL","","","TOTAL"],
    ["","","","","STORE_A","","","STORE_A","","","","",""],
    [1,"BASIC",KODE,"Produk Sama Sekali Asing Belum Pernah Terdaftar Zzz9", 10,0, 10, 0,0,0, 0,0,0],
  ];
  await uploadPO(api, rowsGuard, {factory:FAC, tgl:"2026-09-11", sheetName:"11"});
  const unresolvedGuard = api.poHitungUnresolved(api.poPreview.rows);
  check("GUARD.unresolvedDetected", "Unresolved guard — produk asing terdeteksi meski kode dipakai produk lain juga", true, unresolvedGuard.length>0);
  const poBeforeGuard = JSON.stringify(api.D.po["2026-09-11"]||null);
  api.poSimpanPreview();
  check("GUARD.saveBlocked", "Unresolved guard — Save tetap ditolak (D.po tidak berubah)", poBeforeGuard, JSON.stringify(api.D.po["2026-09-11"]||null));

  // Real file check: dashProdukData() pada file PPIC sungguhan (7 kode
  // collision) tidak lagi menggabungkan produk2 yg berbeda nama.
  {
    const fs = require("fs");
    function parseCsvToRows(path){
      const csv = fs.readFileSync(path, "utf8");
      const wb = XLSX.read(csv, {type:"string"});
      const ws = wb.Sheets[wb.SheetNames[0]];
      return XLSX.utils.sheet_to_json(ws, {header:1, raw:true, defval:""});
    }
    const rowsKT = parseCsvToRows(process.env.KT_CSV || "/tmp/karangtengah.csv");
    const parsedKT = api.parsePOAuto(rowsKT).map(r=>{ r.produk = r.produkAsli; return r; });
    api.D.po["2026-09-03"] = parsedKT;
    api.saveD();
    const per = api.dashProdukData("2026-09-03","2026-09-03");
    const bsc008Rows = per.filter(r=>r.kode==="BSC008");
    check("REALDATA.bsc008NotMerged", "Real CSV — kode BSC008 (2 produk berbeda) TIDAK digabung di dashProdukData", 2, bsc008Rows.length);
    const mc0025Rows = per.filter(r=>r.kode==="MC0025");
    check("REALDATA.mc0025NotMerged", "Real CSV — kode MC0025 (2 produk berbeda, salah satunya 534pcs) TIDAK digabung", 2, mc0025Rows.length);
    const dubaiRow = mc0025Rows.find(r=>r.produk==="DUBAI CHEWY COOKIES");
    check("REALDATA.mc0025DubaiCorrect", "Real CSV — target DUBAI CHEWY COOKIES tetap 534 (bukan digabung ke produk lain)", 534, dubaiRow?dubaiRow.target:null);
  }

  // Data safety: packing progres yg sudah tersimpan SEBELUM patch ini (key
  // lama "kode|toko" polos, tanpa skuId) harus tetap terbaca — TIDAK boleh
  // kelihatan reset/hilang hanya krn skema key berubah.
  {
    const api2 = await setup();
    const divisi2 = api2.divisiProduk(api2.D.po[TGL][0]);
    api2.$("pd-tgl").value = TGL; api2.$("pd-divisi").value = divisi2;
    api2.prodBuildChecklist(); api2.pdSimpan();
    api2.$("pd-divisi").value = api2.SPECIAL_FG;
    api2.prodBuildChecklist(); api2.pdSimpan();
    // Simulasikan data LAMA: tulis manual pakai key lama (kode polos+"|"+toko),
    // seperti yg tersimpan di localStorage sebelum patch skuId ada.
    const fgKeyStr = api2.fgKey(TGL, FAC);
    delete api2.D.fgPacking[fgKeyStr]; // hapus hasil auto-materialize dulu
    api2.D.fgPacking[fgKeyStr] = {readyAt:null, packed:{
      [KODE+"|STORE_A"]: {qty:40, status:"sesuai", keterangan:"data lama sebelum patch"},
      [KODE+"|STORE_B"]: {qty:60, status:"sesuai", keterangan:"data lama sebelum patch"},
    }};
    api2.saveD();
    api2.prodBuildChecklist(); // triggers fgMaterializeAll again — must NOT overwrite old-format entries
    const rowA2 = api2.fgStoreRows(TGL,FAC).find(r=>r.produk==="PRODUK A");
    const rowB2 = api2.fgStoreRows(TGL,FAC).find(r=>r.produk==="PRODUK B");
    const packedA2 = api2.fgGetPacked(TGL, FAC, api2.skuId(rowA2), "STORE_A");
    const packedB2 = api2.fgGetPacked(TGL, FAC, api2.skuId(rowB2), "STORE_B");
    check("BACKCOMPAT.oldKeyStillReadableA", "Data lama (key 'kode|toko' polos) tetap terbaca utk PRODUK A, tidak reset ke 0", 40, packedA2.qty);
    check("BACKCOMPAT.oldKeyStillReadableB", "Data lama (key 'kode|toko' polos) tetap terbaca utk PRODUK B, tidak reset ke 0", 60, packedB2.qty);
    check("BACKCOMPAT.oldEntriesNotDuplicated", "materializeAll tidak menduplikasi entry lama jadi entry baru terpisah",
      2, Object.keys(api2.D.fgPacking[fgKeyStr].packed).length);
    // Update lewat fgSetPackedField harus menulis BALIK ke key lama yg sama (bukan bikin key baru di sebelahnya).
    api2.fgSetPackedField(TGL, FAC, api2.skuId(rowA2), "STORE_A", {qty:35, status:"tidak_sesuai", keterangan:"dikoreksi"});
    check("BACKCOMPAT.updateStaysOnOldKey", "Update pakai skuId tetap menulis ke key lama yg sama (tidak duplikat)",
      2, Object.keys(api2.D.fgPacking[fgKeyStr].packed).length);
    check("BACKCOMPAT.updateValueCorrect", "Nilai ter-update benar (35) setelah ditulis balik ke key lama",
      35, api2.D.fgPacking[fgKeyStr].packed[KODE+"|STORE_A"].qty);
  }

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== SKU-IDENTITY (kode collision) TEST TABLE ===\n");
  console.log("ID".padEnd(32), "Scenario".padEnd(72), "Expected".padEnd(14), "Actual".padEnd(14), "Result");
  results.forEach(r=>{
    console.log(r.id.padEnd(32), r.scenario.slice(0,72).padEnd(72), JSON.stringify(r.expected).padEnd(14), JSON.stringify(r.actual).padEnd(14), r.pass?"PASS":"FAIL");
  });
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, r.scenario, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main().catch(e=>{ console.error("SUITE CRASHED", e); process.exit(2); });
