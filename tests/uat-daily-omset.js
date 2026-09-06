"use strict";
// Verifies the Daily Omset engine (Parts H-M of the "UI simplification +
// canonical bakery mapping + daily omset per store" request): a NEW report
// separate from the operational Dashboard, basis = barang benar-benar
// terkirim (D.kirim), NEVER D.invoice. Reuses the exact same formulas
// already used by the existing Dashboard "Arus Barang" panel
// (omsetKirimRow/omset100KirimRow/hargaDasar) so the numbers never diverge
// between pages.
//
// Drives the REAL app functions (poProses, pdSimpan, fgTandaiSiap, kSimpan,
// omsetPerBakeryData/omsetTrenHarian/omsetSkuDetailData) via jsdom + real
// xlsx — no business logic reimplemented here.
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
// Jalur pendek utk fixture omset: upload PO 1 toko 1 produk, produksi penuh,
// FG penuh, kirim penuh — supaya D.kirim (basis Omset Pabrik) terisi dgn
// qty yang PERSIS terkontrol, lewat jalur nyata (bukan D.kirim.push manual).
async function kirimSatuKarangtengah(api, {tgl, toko, produk, kode, qty, harga100, hpp}){
  const rows = [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","TOTAL","","TOTAL","","TOTAL"],
    ["","","","",toko,"",toko,"","",""],
    [1,"BASIC",kode,produk, qty, qty, 0,0, 0,0],
  ];
  await uploadPO(api, rows, {factory:"karangtengah", tgl, sheetName:tgl.slice(-2)});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();
  api.D.masterProduk[produk] = {kategori:"BASIC", divisi:"Basic", hpp, harga:harga100, aktif:true, updatedAt:""};
  const divisi = api.divisiProduk(api.D.po[tgl].find(r=>r.produk===produk));
  submitCeklis(api, tgl, divisi);
  submitCeklis(api, tgl, api.SPECIAL_FG);
  markFgReady(api, tgl, api.SPECIAL_FG);
  api.$("k-tgl").value = tgl;
  api.kTarikDariFGBakery(tgl, api.canonicalStoreName(toko));
  api.kSimpan();
}
async function kirimSatuCibadak(api, {tgl, toko, produk, qty, harga100, hpp}){
  const rows = [
    ["","Kategori","Nama Produk","Harga Satuan","","TOTAL PO","","TOTAL PO","TOTAL PO"],
    ["","","","",toko,"",toko,"",""],
    ["","BOLU",produk,harga100, qty, qty, 0,0, 0],
  ];
  await uploadPO(api, rows, {factory:"cibadak", tgl, sheetName:tgl.slice(-2)});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();
  api.D.masterProduk[produk] = {kategori:"BOLU", divisi:api.SPECIAL_BOLU, hpp, harga:harga100, aktif:true, updatedAt:""};
  submitCeklis(api, tgl, api.SPECIAL_BOLU);
  submitCeklis(api, tgl, api.SPECIAL_FG_CIBADAK);
  markFgReady(api, tgl, api.SPECIAL_FG_CIBADAK);
  api.$("k-tgl").value = tgl;
  api.kTarikDariFGBakery(tgl, api.canonicalStoreName(toko));
  api.kSimpan();
}

async function main(){
  const api = loadApp();

  /* TEST A — total omset satu tanggal, satu bakery sederhana. */
  await kirimSatuKarangtengah(api, {tgl:"2026-09-01", toko:"TOKO A", produk:"PRODUK OMSET A", kode:"OA01", qty:100, harga100:20000, hpp:8000});
  // Omset Pabrik = 100 * hargaDasar(20000) = 100 * 10000 (pctDasar 50%) = 1.000.000
  const rowsA = api.omsetHarianRows("2026-09-01","2026-09-01",{});
  check("TESTA.dateTotal", "TEST A — Total Omset Pabrik tanggal 2026-09-01 = 1.000.000",
    1000000, rowsA.reduce((a,r)=>a+r.omsetPabrik,0));
  check("TESTA.hargaPabrikSatuan", "TEST A — Harga Pabrik satuan = hargaDasar(20.000) = 10.000 (pctDasar 50%)",
    10000, api.hargaDasar(20000));

  /* TEST B — bakery yang sama dari 2 factory berbeda digabung totalnya. */
  await kirimSatuKarangtengah(api, {tgl:"2026-09-02", toko:"CKLE", produk:"PRODUK OMSET B-KRT", kode:"OB01", qty:50, harga100:40000, hpp:15000});
  await kirimSatuCibadak(api, {tgl:"2026-09-02", toko:"BAKERY CIKOLE", produk:"PRODUK OMSET B-CBD", qty:30, harga100:60000, hpp:20000});
  // Canonical merge (pasangan yang sudah dikonfirmasi) — dilakukan SETELAH
  // kedua kiriman tersimpan, membuktikan resolve-nya LAZY di saat baca
  // (D.kirim baris Karangtengah tetap tertulis toko="CKLE" apa adanya, baris
  // Cibadak tetap "BAKERY CIKOLE" apa adanya — cuma agregasi omset yang
  // menggabungkan keduanya lewat canonicalStoreName saat laporan dibaca).
  api.ensureMasterToko();
  api.bootstrapTokoCanonicalDikenal();
  check("TESTB.rawKirimRowsUntouched", "TEST B — D.kirim baris mentah TIDAK ditulis ulang oleh proses merge (tetap \"CKLE\"/\"BAKERY CIKOLE\" apa adanya)",
    JSON.stringify(["BAKERY CIKOLE","CKLE"]),
    JSON.stringify([...new Set(api.D.kirim.filter(r=>r.tgl==="2026-09-02").map(r=>r.toko))].sort()));
  // Karangtengah: 50 * hargaDasar(40000)=20000 -> 1.000.000
  // Cibadak:      30 * hargaDasar(60000)=30000 -> 900.000
  // Gabungan (1 bakery, "BAKERY CIKOLE"): 1.900.000
  const perBakeryB = api.omsetPerBakeryData("2026-09-02","2026-09-02",{});
  const cikoleB = perBakeryB.find(r=>r.bakery==="BAKERY CIKOLE");
  check("TESTB.oneRowMergedFromTwoFactories", "TEST B — Bakery gabungan dari 2 factory tampil SATU baris",
    1, perBakeryB.filter(r=>r.bakery==="BAKERY CIKOLE").length);
  check("TESTB.combinedTotal", "TEST B — Omset gabungan bakery (Karangtengah 1jt + Cibadak 900rb) = 1.900.000",
    1900000, cikoleB ? cikoleB.omsetPabrik : null);

  /* TEST C — retur TIDAK mengurangi Omset Pabrik. */
  const omsetBeforeRetur = api.omsetPerBakeryData("2026-09-01","2026-09-01",{}).reduce((a,r)=>a+r.omsetPabrik,0);
  api.D.retur.push({id:"RET-OMSET-1", tgl:"2026-09-01", toko:"TOKO A", produk:"PRODUK OMSET A", qty:20, alasan:"test"});
  const omsetAfterRetur = api.omsetPerBakeryData("2026-09-01","2026-09-01",{}).reduce((a,r)=>a+r.omsetPabrik,0);
  check("TESTC.returDoesNotReduceOmset", "TEST C — Retur (20 pcs) TIDAK mengurangi Omset Pabrik tanggal itu (tetap 1.000.000)",
    omsetBeforeRetur, omsetAfterRetur);

  /* TEST D — penyesuaian invoice TIDAK mengubah Omset Pabrik (basis = D.kirim, bukan D.invoice). */
  const batchA = api.D.kirim.find(r=>r.produk==="PRODUK OMSET A").batch;
  api.kBukaInvoice(batchA);
  // ubah qtyInvoice/harga manual di form invoice (kalau ada input), lalu simpan —
  // apa pun isi invoice, Omset Pabrik harus tetap dihitung dari D.kirim.
  api.kInvoiceSimpan();
  if(api.D.invoice[batchA]) api.D.invoice[batchA].total = 999999999; // adjustment ekstrem, harus tetap tidak berpengaruh
  const omsetAfterInvoiceAdjust = api.omsetPerBakeryData("2026-09-01","2026-09-01",{}).reduce((a,r)=>a+r.omsetPabrik,0);
  check("TESTD.invoiceAdjustmentDoesNotChangeOmset", "TEST D — Penyesuaian ekstrem di Invoice TIDAK mengubah Omset Pabrik (basis D.kirim)",
    omsetBeforeRetur, omsetAfterInvoiceAdjust);

  /* TEST E — isolasi tanggal: omset tanggal lain tidak ikut tercampur. */
  const rowsSep1Only = api.omsetHarianRows("2026-09-01","2026-09-01",{});
  const rowsSep2Only = api.omsetHarianRows("2026-09-02","2026-09-02",{});
  check("TESTE.dateIsolation_sep1NotContainSep2", "TEST E — Baris tanggal 2026-09-01 tidak berisi produk dari 2026-09-02",
    false, rowsSep1Only.some(r=>r.produk==="PRODUK OMSET B-KRT" || r.produk==="PRODUK OMSET B-CBD"));
  check("TESTE.dateIsolation_sep2NotContainSep1", "TEST E — Baris tanggal 2026-09-02 tidak berisi produk dari 2026-09-01",
    false, rowsSep2Only.some(r=>r.produk==="PRODUK OMSET A"));

  /* TEST F — isolasi filter bakery: filter satu bakery tidak bocor ke bakery lain. */
  const filteredTokoA = api.omsetHarianRows("2026-09-01","2026-09-01",{bakery:"TOKO A"});
  const filteredCikole = api.omsetHarianRows("2026-09-02","2026-09-02",{bakery:"BAKERY CIKOLE"});
  check("TESTF.bakeryFilterIsolation_noCrossLeak", "TEST F — Filter bakery=\"TOKO A\" tidak memuat baris bakery lain",
    true, filteredTokoA.every(r=>r.bakery==="TOKO A"));
  check("TESTF.bakeryFilterIsolation_cikoleOnlyOwnRows", "TEST F — Filter bakery=\"BAKERY CIKOLE\" hanya berisi baris bakery itu (dari 2 factory)",
    true, filteredCikole.every(r=>r.bakery==="BAKERY CIKOLE") && filteredCikole.length===2);

  /* TEST G — contoh margin dari spesifikasi: Omset 1.000.000, HPP 650.000 -> Margin 350.000 (35%). */
  await kirimSatuKarangtengah(api, {tgl:"2026-09-05", toko:"TOKO MARGIN", produk:"PRODUK MARGIN TEST", kode:"MG01", qty:100, harga100:20000, hpp:6500});
  // Omset Pabrik = 100 * hargaDasar(20000)=10000 -> 1.000.000 ; HPP = 100*6500 = 650.000
  const marginRow = api.omsetPerBakeryData("2026-09-05","2026-09-05",{}).find(r=>r.bakery==="TOKO MARGIN");
  check("TESTG.omset1jt", "TEST G — Omset Pabrik = 1.000.000", 1000000, marginRow ? marginRow.omsetPabrik : null);
  check("TESTG.hpp650rb", "TEST G — HPP = 650.000", 650000, marginRow ? marginRow.hpp : null);
  check("TESTG.margin350rb", "TEST G — Margin Kotor = Omset - HPP = 350.000", 350000, marginRow ? marginRow.margin : null);
  const marginPct = marginRow ? Math.round(marginRow.margin/marginRow.omsetPabrik*1000)/10 : null;
  check("TESTG.margin35pct", "TEST G — Margin % = 350.000/1.000.000 = 35%", 35, marginPct);

  /* Drill-down SKU (Daily Store Report) — factory tetap terlihat di level SKU. */
  const drillCikole = api.omsetSkuDetailData("2026-09-02", "BAKERY CIKOLE");
  check("DRILL.bothFactoryVisible", "Drill-down SKU BAKERY CIKOLE 2026-09-02 — factory Karangtengah & Cibadak keduanya terlihat",
    JSON.stringify(["cibadak","karangtengah"]),
    JSON.stringify(drillCikole.map(r=>r.factory).sort()));

  /* Tren harian — dipakai chart 7 hari, tidak boleh melebar ke luar rentang. */
  const tren = api.omsetTrenHarian("2026-09-01","2026-09-02",{});
  check("TREN.onlyRequestedDates", "Tren harian 09-01..09-02 — hanya 2 titik tanggal (tidak melebar)",
    JSON.stringify(["2026-09-01","2026-09-02"]), JSON.stringify(tren.map(t=>t.tgl)));

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== DAILY OMSET (per bakery) TEST TABLE ===\n");
  console.log("ID".padEnd(38), "Scenario".padEnd(78), "Expected".padEnd(16), "Actual".padEnd(16), "Result");
  results.forEach(r=>{
    console.log(r.id.padEnd(38), r.scenario.slice(0,78).padEnd(78), JSON.stringify(r.expected).slice(0,16).padEnd(16), JSON.stringify(r.actual).slice(0,16).padEnd(16), r.pass?"PASS":"FAIL");
  });
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, r.scenario, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main().catch(e=>{ console.error("SUITE CRASHED", e); process.exit(2); });
