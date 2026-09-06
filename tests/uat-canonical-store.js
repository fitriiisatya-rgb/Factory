"use strict";
// Verifies the canonical bakery/store mapping (Part A-C, F, G, R of the
// "UI simplification + canonical bakery mapping" request): two different
// RAW store names from two different factories (Karangtengah "CKLE" and
// Cibadak "BAKERY CIKOLE") that are actually the SAME physical bakery must
// behave as ONE store everywhere it matters — Kirim Toko dropdown, DO/Kirim
// grouping, Retur/Invoice resolution — while the raw source data is NEVER
// rewritten (lazy, read-time resolution only, per explicit data-safety rule).
//
// Drives the REAL app functions end-to-end (poProses, pdSimpan, fgTandaiSiap,
// kTarikDariFGBakery/kSimpan, canonicalStoreName/bootstrapTokoCanonicalDikenal)
// via jsdom + real xlsx — no business logic reimplemented here.
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
function submitCeklis(api, tgl, divisi){
  api.$("pd-tgl").value = tgl;
  api.$("pd-divisi").value = divisi;
  api.prodBuildChecklist();
  api.pdSimpan();
}
function markFgReady(api, tgl, divisi){
  api.$("pd-tgl").value = tgl;
  api.$("pd-divisi").value = divisi;
  api.prodBuildChecklist();
  // Baris packing baru default "Belum Dicek" (qty 0) sampai operator eksplisit
  // konfirmasi — simulasikan itu lewat "Tandai Semua Sesuai" sblm menandai siap,
  // sama seperti alur asli di browser (bukan lagi auto-"sesuai" diam-diam).
  api.fgTandaiSemuaSesuai();
  api.fgTandaiSiap();
}

async function main(){
  const api = loadApp();
  const TGL = "2026-09-10";

  /* ===================== TEST 1 & 2 setup =====================
     Karangtengah "CKLE" 345 pcs (ROTI COKELAT CIKOLE) + Cibadak
     "BAKERY CIKOLE" 120 pcs (BOLU KEJU CIKOLE), tanggal sama — persis
     skenario nyata yang diberikan (2 factory, 1 bakery fisik).           */
  const rowsKrt = [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","TOTAL","","TOTAL","","TOTAL"],
    ["","","","","CKLE","","CKLE","","",""],
    [1,"BASIC","CKT01","ROTI COKELAT CIKOLE", 345, 345, 0,0, 0,0],
  ];
  await uploadPO(api, rowsKrt, {factory:"karangtengah", tgl:TGL, sheetName:"10"});
  api.poResolusiJadiBaru(0);
  api.poSimpanPreview();

  const rowsCbd = [
    ["","Kategori","Nama Produk","Harga Satuan","","TOTAL PO","","TOTAL PO","TOTAL PO"],
    ["","","","","BAKERY CIKOLE","","BAKERY CIKOLE","",""],
    ["","BOLU","BOLU KEJU CIKOLE",10000, 120, 120, 0,0, 0],
  ];
  await uploadPO(api, rowsCbd, {factory:"cibadak", tgl:TGL, sheetName:"10"});
  api.poResolusiJadiBaru(0);
  api.poSimpanPreview();

  check("SETUP.krtSaved", "Setup — PO Karangtengah (CKLE, 345) tersimpan", 345,
    api.hitungTarget(api.D.po[TGL].find(r=>r.factory==="karangtengah")));
  check("SETUP.cbdSaved", "Setup — PO Cibadak (BAKERY CIKOLE, 120) tersimpan", 120,
    api.hitungTarget(api.D.po[TGL].find(r=>r.factory==="cibadak")));

  // Produksi -> Finishgood -> siap kirim, MASING-MASING factory (jalur nyata).
  const divisiKrt = api.divisiProduk(api.D.po[TGL].find(r=>r.factory==="karangtengah"));
  submitCeklis(api, TGL, divisiKrt);
  submitCeklis(api, TGL, api.SPECIAL_FG);
  markFgReady(api, TGL, api.SPECIAL_FG);

  submitCeklis(api, TGL, api.SPECIAL_BOLU);
  submitCeklis(api, TGL, api.SPECIAL_FG_CIBADAK);
  markFgReady(api, TGL, api.SPECIAL_FG_CIBADAK);

  check("SETUP.stokKrt", "Setup — stok gudang ROTI COKELAT CIKOLE (345) setelah FG", 345, api.stokGudang("ROTI COKELAT CIKOLE"));
  check("SETUP.stokCbd", "Setup — stok gudang BOLU KEJU CIKOLE (120) setelah FG", 120, api.stokGudang("BOLU KEJU CIKOLE"));

  /* ===================== Canonical merge (Part A/B — pasangan yang SUDAH
     DIKONFIRMASI, contoh persis dari user: CKLE = BAKERY CIKOLE) ========== */
  api.ensureMasterToko();
  check("MERGE.beforeSeparate", "Sebelum merge — CKLE & BAKERY CIKOLE masih 2 canonical terpisah",
    false, api.canonicalStoreName("CKLE")===api.canonicalStoreName("BAKERY CIKOLE"));
  api.bootstrapTokoCanonicalDikenal();
  check("MERGE.afterSame", "Setelah merge — CKLE & BAKERY CIKOLE jadi SATU canonical (\"BAKERY CIKOLE\")",
    "BAKERY CIKOLE", api.canonicalStoreName("CKLE"));
  check("MERGE.neverAutoMergeUnrelated", "Merge TIDAK menyentuh toko lain yang tidak terkait (mis. toko baru asing tetap apa adanya)",
    "TOKO ASING BARU", api.canonicalStoreName("Toko Asing Baru".toUpperCase()));

  /* ===================== TEST 1: satu row gabungan, total 465 ===================== */
  const readyBefore = api.fgReadyListByBakery().filter(g=>g.tgl===TGL && g.bakery==="BAKERY CIKOLE");
  check("TEST1.oneRowInReadyList", "TEST 1 — Siap Kirim: BAKERY CIKOLE tampil SATU row gabungan (bukan 2 baris terpisah)",
    1, readyBefore.length);
  check("TEST1.combinedTotal465", "TEST 1 — Total gabungan Siap Kirim = 345 + 120 = 465",
    465, readyBefore[0] ? readyBefore[0].total : null);
  check("TEST1.breakdownHasBothFactories", "TEST 1 — Breakdown per-factory tetap ada (Karangtengah 345 + Cibadak 120)",
    JSON.stringify([{factory:"cibadak",total:120},{factory:"karangtengah",total:345}]),
    JSON.stringify(readyBefore[0].breakdown.map(b=>({factory:b.factory,total:b.total})).sort((a,b)=>a.factory.localeCompare(b.factory))));

  // Proses Kirim SATU KALI klik utk bakery gabungan -> tarik KEDUA batch factory sekaligus.
  api.kTarikDariFGBakery(TGL, "BAKERY CIKOLE");
  check("TEST1.tokoDropdownCanonical", "TEST 1 — Setelah 'Proses Kirim', dropdown toko terisi nama standar (BAKERY CIKOLE)",
    "BAKERY CIKOLE", api.$("k-toko").value);
  const idxKrt = api.kList.indexOf("ROTI COKELAT CIKOLE");
  const idxCbd = api.kList.indexOf("BOLU KEJU CIKOLE");
  check("TEST1.pulledQtyKrt", "TEST 1 — Qty ROTI COKELAT CIKOLE (Karangtengah) tertarik penuh (345)",
    345, idxKrt>=0 ? api.num(api.$("kq-"+idxKrt).value) : null);
  check("TEST1.pulledQtyCbd", "TEST 1 — Qty BOLU KEJU CIKOLE (Cibadak) tertarik penuh (120)",
    120, idxCbd>=0 ? api.num(api.$("kq-"+idxCbd).value) : null);

  api.kSimpan();
  const kirimRows = api.D.kirim.filter(r=>r.tgl===TGL && r.toko==="BAKERY CIKOLE");
  const batchIds = [...new Set(kirimRows.map(r=>r.batch))];
  check("TEST1.oneBatchOnly", "TEST 1 — Simpan Pengiriman menghasilkan SATU batch/DO (bukan 2 DO terpisah)",
    1, batchIds.length);
  const totalQtyGabungan = kirimRows.reduce((a,r)=>a+r.qty,0);
  check("TEST1.totalQty465", "TEST 1 — Total qty dalam SATU DO gabungan = 465 pcs",
    465, totalQtyGabungan);

  /* ===================== TEST 2: DO tetap menyimpan SEMUA SKU dari 2 factory ===================== */
  check("TEST2.bothSkuPresent", "TEST 2 — DO BAKERY CIKOLE berisi SKU dari KEDUA factory (bukan cuma salah satu)",
    JSON.stringify(["BOLU KEJU CIKOLE","ROTI COKELAT CIKOLE"]),
    JSON.stringify(kirimRows.map(r=>r.produk).sort()));
  check("TEST2.krtQtyInDO", "TEST 2 — Qty ROTI COKELAT CIKOLE di DO gabungan tetap 345 (tidak berkurang/hilang)",
    345, (kirimRows.find(r=>r.produk==="ROTI COKELAT CIKOLE")||{}).qty);
  check("TEST2.cbdQtyInDO", "TEST 2 — Qty BOLU KEJU CIKOLE di DO gabungan tetap 120 (tidak berkurang/hilang)",
    120, (kirimRows.find(r=>r.produk==="BOLU KEJU CIKOLE")||{}).qty);

  // Kedua batch FG (Karangtengah & Cibadak) harus SAMA-SAMA ditandai sudah
  // dikirim — bukan cuma yang terakhir ditarik (lihat kFgSource akumulasi).
  const recKrt = api.D.fgPacking[api.fgKey(TGL,"karangtengah")];
  const recCbd = api.D.fgPacking[api.fgKey(TGL,"cibadak")];
  check("TEST2.fgKrtMarkedDelivered", "TEST 2 — Batch FG Karangtengah (CKLE) ditandai sudah dikirim",
    true, !!(recKrt && recKrt.dikirimKe && recKrt.dikirimKe.includes("CKLE")));
  check("TEST2.fgCbdMarkedDelivered", "TEST 2 — Batch FG Cibadak (BAKERY CIKOLE) ditandai sudah dikirim",
    true, !!(recCbd && recCbd.dikirimKe && recCbd.dikirimKe.includes("BAKERY CIKOLE")));
  const readyAfter = api.fgReadyListByBakery().filter(g=>g.tgl===TGL && g.bakery==="BAKERY CIKOLE");
  check("TEST2.notInReadyListAnymore", "TEST 2 — Setelah dikirim, BAKERY CIKOLE hilang dari daftar Siap Kirim (bukan nyangkut sebagian)",
    0, readyAfter.length);

  /* ===================== TEST 3: Omset gabungan per bakery (4jt + 1.8jt = 5.8jt) ===================== */
  const TGL2 = "2026-09-12";
  const rowsKrt2 = [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","TOTAL","","TOTAL","","TOTAL"],
    ["","","","","CKLE","","CKLE","","",""],
    [1,"BASIC","CKT02","OMSET TEST KRT", 200, 200, 0,0, 0,0],
  ];
  await uploadPO(api, rowsKrt2, {factory:"karangtengah", tgl:TGL2, sheetName:"12"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();
  const rowsCbd2 = [
    ["","Kategori","Nama Produk","Harga Satuan","","TOTAL PO","","TOTAL PO","TOTAL PO"],
    ["","","","","BAKERY CIKOLE","","BAKERY CIKOLE","",""],
    ["","BOLU","OMSET TEST CBD",10000, 120, 120, 0,0, 0],
  ];
  await uploadPO(api, rowsCbd2, {factory:"cibadak", tgl:TGL2, sheetName:"12"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();

  api.D.masterProduk["OMSET TEST KRT"] = {kategori:"BASIC", divisi:"Basic", hpp:12000, harga:40000, aktif:true, updatedAt:""};
  api.D.masterProduk["OMSET TEST CBD"] = {kategori:"BOLU", divisi:api.SPECIAL_BOLU, hpp:9000, harga:30000, aktif:true, updatedAt:""};
  check("SETUP.pctDasar50", "Setup — pctDasar default 50% (dipakai Omset Pabrik)", 50, api.D.settings.pctDasar);

  const divisiKrt2 = api.divisiProduk(api.D.po[TGL2].find(r=>r.factory==="karangtengah"));
  submitCeklis(api, TGL2, divisiKrt2);
  submitCeklis(api, TGL2, api.SPECIAL_FG);
  markFgReady(api, TGL2, api.SPECIAL_FG);
  submitCeklis(api, TGL2, api.SPECIAL_BOLU);
  submitCeklis(api, TGL2, api.SPECIAL_FG_CIBADAK);
  markFgReady(api, TGL2, api.SPECIAL_FG_CIBADAK);

  api.kTarikDariFGBakery(TGL2, "BAKERY CIKOLE");
  api.kSimpan();

  const omsetRows = api.omsetPerBakeryData(TGL2, TGL2, {});
  const omsetCikole = omsetRows.find(r=>r.bakery==="BAKERY CIKOLE");
  check("TEST3.oneRowPerBakery", "TEST 3 — Omset per bakery: BAKERY CIKOLE satu baris gabungan (bukan 2 baris per factory)",
    1, omsetRows.filter(r=>r.bakery==="BAKERY CIKOLE").length);
  check("TEST3.omsetKrt4jt", "TEST 3 — Omset Pabrik Karangtengah = 200 x 20.000 = 4.000.000",
    4000000, api.omsetHarianRows(TGL2,TGL2,{factory:"karangtengah"}).reduce((a,r)=>a+r.omsetPabrik,0));
  check("TEST3.omsetCbd1_8jt", "TEST 3 — Omset Pabrik Cibadak = 120 x 15.000 = 1.800.000",
    1800000, api.omsetHarianRows(TGL2,TGL2,{factory:"cibadak"}).reduce((a,r)=>a+r.omsetPabrik,0));
  check("TEST3.combined5_8jt", "TEST 3 — Omset Pabrik gabungan BAKERY CIKOLE = 4.000.000 + 1.800.000 = 5.800.000",
    5800000, omsetCikole ? omsetCikole.omsetPabrik : null);

  /* ===================== TEST 4: Retur alias CKLE resolve ke BAKERY CIKOLE ===================== */
  api.D.retur.push({id:"RET-TEST-1", tgl:TGL, toko:"CKLE", produk:"ROTI COKELAT CIKOLE", qty:5, alasan:"test"});
  const returRaw = api.D.retur.find(r=>r.id==="RET-TEST-1");
  check("TEST4.rawNeverRewritten", "TEST 4 — Data retur mentah TIDAK diubah (toko tetap tertulis \"CKLE\" apa adanya)",
    "CKLE", returRaw.toko);
  check("TEST4.resolvesToCanonical", "TEST 4 — canonicalStoreName(retur.toko) resolve ke \"BAKERY CIKOLE\"",
    "BAKERY CIKOLE", api.canonicalStoreName(returRaw.toko));

  /* ===================== TEST 5: invoice lama (alias mentah) tetap terbaca ===================== */
  api.D.invoice["OLD-BATCH-PRA-MIGRASI"] = {
    invoiceNo:"INV-OLD-1", batch:"OLD-BATCH-PRA-MIGRASI", tgl:"2026-01-01", toko:"CKLE", noSJ:"",
    items:[{produk:"ROTI COKELAT CIKOLE", qty:10, hargaPabrik:0, subtotal:0}], total:0,
    catatan:"", createdAt:"", updatedAt:"",
  };
  api.saveD();
  const oldInv = api.D.invoice["OLD-BATCH-PRA-MIGRASI"];
  check("TEST5.oldInvoiceStillReadable", "TEST 5 — Invoice lama (toko mentah \"CKLE\") tetap terbaca, tidak hilang/reset",
    "CKLE", oldInv ? oldInv.toko : null);
  check("TEST5.oldInvoiceResolvesLive", "TEST 5 — Invoice lama tsb tetap bisa ditampilkan sbg \"BAKERY CIKOLE\" (resolve saat dibaca, bukan ditulis ulang)",
    "BAKERY CIKOLE", api.canonicalStoreName(oldInv.toko));
  check("TEST5.itemsIntact", "TEST 5 — Isi item invoice lama utuh (tidak berubah krn migrasi canonical)",
    10, oldInv.items[0].qty);

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== CANONICAL STORE (bakery mapping) TEST TABLE ===\n");
  console.log("ID".padEnd(32), "Scenario".padEnd(78), "Expected".padEnd(16), "Actual".padEnd(16), "Result");
  results.forEach(r=>{
    console.log(r.id.padEnd(32), r.scenario.slice(0,78).padEnd(78), JSON.stringify(r.expected).slice(0,16).padEnd(16), JSON.stringify(r.actual).slice(0,16).padEnd(16), r.pass?"PASS":"FAIL");
  });
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, r.scenario, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main().catch(e=>{ console.error("SUITE CRASHED", e); process.exit(2); });
