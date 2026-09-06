"use strict";
// Verifies the browser-UAT navigation + compact-UI fixes on iPad landscape:
//   - Upload PO restored to primary nav (was accidentally dropped into
//     "Lainnya" in the previous pass)
//   - final primary nav order, Master staying primary (not in overflow)
//   - "Lainnya" dropdown actually opens/closes (root cause: position:absolute
//     dropdown panel was being clipped by .nav's overflow-x:auto — fixed by
//     switching the panel to position:fixed with JS-computed placement)
//   - Produksi: SKU checklist no longer forced open before a divisi is chosen
//   - Kirim Toko: full DO form hidden until "Proses Kirim" (or manual "Buat
//     Delivery Order Manual") is clicked
//   - Kartu Stok: "Catat Penyesuaian Stok" collapsed by default
//   - Rekap: primary KPI/summary blocks visible, secondary sections collapsed
//
// Drives the REAL app functions/DOM via jsdom + real xlsx — no business
// logic reimplemented here. Navigation is exercised via api.go()/goPage()
// (the same technique every other suite in this repo uses) because this
// harness's inline onclick="..." attributes are not wired up by jsdom's
// runScripts:"outside-only" mode — a pre-existing, harness-wide limitation
// unrelated to this fix (real browsers execute onclick normally). Native
// <details>/<summary> toggle-on-click IS exercised via real .click() calls,
// since jsdom implements that natively.
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
  const d = api.__document;

  /* ===================== NAV: structure & order ===================== */
  const topLevel = [...d.querySelectorAll(".nav > .nav-btn")].map(b=>b.dataset.p);
  const navMore = d.getElementById("navMore");

  check("NAV01.uploadPoInPrimaryNav", "NAV01 — Upload PO ada di primary nav (bukan di dalam Lainnya)",
    true, topLevel.includes("p-po"));

  const expectedOrder = ["p-po","p-dash","p-prod","p-kirim","p-omset","p-invoice","p-stok","p-rekap","p-master"];
  check("NAV.finalOrder", "NAV — urutan primary nav sesuai final order (Upload PO..Master)",
    JSON.stringify(expectedOrder), JSON.stringify(topLevel));

  api.goPage("p-po");
  check("NAV02.uploadPoOpensExistingPage", "NAV02 — klik Upload PO membuka page existing (bukan dibuat ulang, elemen po-file ada)",
    JSON.stringify({active:true, hasForm:true}),
    JSON.stringify({active: d.getElementById("p-po").classList.contains("active"), hasForm: !!api.$("po-file")}));

  const idxRekap = topLevel.indexOf("p-rekap"), idxMaster = topLevel.indexOf("p-master");
  check("NAV03.masterAfterRekap", "NAV03 — Master posisinya setelah Rekap", true, idxMaster > idxRekap && idxMaster>=0 && idxRekap>=0);
  check("NAV04.masterIsPrimaryTopLevelChild", "NAV04 — Master adalah direct child dari .nav (primary), bukan di dalam .nav-more",
    true, !!d.querySelector(".nav > .nav-btn[data-p='p-master']"));

  check("NAV05.lainnyaVisible", "NAV05 — \"Lainnya\" ada sbg direct child .nav", true, !!navMore && navMore.parentElement.classList.contains("nav"));

  navMore.querySelector("summary").click();
  check("NAV06.clickOpensDropdown", "NAV06 — klik \"Lainnya\" membuka dropdown", true, navMore.open);

  const moreBtns = [...navMore.querySelectorAll(".nav-btn")].map(b=>b.dataset.p);
  check("NAV.secondaryItemsPresent", "NAV — Retur/Reject/Pesanan/Jual Konsumen ada di dalam Lainnya (tidak dihapus)",
    true, ["p-retur","p-reject","p-pesanan","p-jual"].every(p=>moreBtns.includes(p)));

  // NAV07-10: klik tiap item Lainnya membuka page terkait (dipanggil lewat
  // api.go() langsung — lihat catatan header file soal onclick attribute).
  const returBtn = [...navMore.querySelectorAll(".nav-btn")].find(b=>b.dataset.p==="p-retur");
  api.go(returBtn);
  check("NAV07.returOpens", "NAV07 — klik Retur membuka page Retur", true, d.getElementById("p-retur").classList.contains("active"));
  const rejectBtn = [...navMore.querySelectorAll(".nav-btn")].find(b=>b.dataset.p==="p-reject");
  navMore.open = true; // go() menutup dropdown tiap navigasi — buka lagi utk simulasikan klik berikutnya
  api.go(rejectBtn);
  check("NAV08.rejectOpens", "NAV08 — klik Reject membuka page Reject", true, d.getElementById("p-reject").classList.contains("active"));
  const pesananBtn = [...navMore.querySelectorAll(".nav-btn")].find(b=>b.dataset.p==="p-pesanan");
  navMore.open = true;
  api.go(pesananBtn);
  check("NAV09.pesananOpens", "NAV09 — klik Pesanan membuka page Pesanan", true, d.getElementById("p-pesanan").classList.contains("active"));
  const jualBtn = [...navMore.querySelectorAll(".nav-btn")].find(b=>b.dataset.p==="p-jual");
  navMore.open = true;
  api.go(jualBtn);
  check("NAV10.jualOpens", "NAV10 — klik Jual Konsumen membuka page Jual Konsumen", true, d.getElementById("p-jual").classList.contains("active"));

  check("NAV.dropdownClosesAfterNavigate", "NAV — dropdown otomatis tertutup setelah klik salah satu item (go())",
    false, navMore.open);

  navMore.querySelector("summary").click(); // buka lagi
  d.body.dispatchEvent(new api.__window.Event("click", {bubbles:true}));
  check("NAV11.clickOutsideCloses", "NAV11 — klik di luar dropdown menutupnya", false, navMore.open);

  navMore.querySelector("summary").click();
  const afterFirst = navMore.open;
  navMore.querySelector("summary").click();
  const afterSecond = navMore.open;
  check("NAV12.toggleTwice", "NAV12 — klik \"Lainnya\" dua kali toggle buka lalu tutup",
    JSON.stringify({first:true, second:false}), JSON.stringify({first:afterFirst, second:afterSecond}));

  navMore.querySelector("summary").click();
  const escEvent = new api.__window.KeyboardEvent("keydown", {key:"Escape", bubbles:true});
  d.dispatchEvent(escEvent);
  check("NAV.escapeCloses", "NAV — tombol Escape menutup dropdown", false, navMore.open);

  check("NAV13.navScrollFallbackPresent", "NAV13 — .nav punya overflow-x:auto sbg fallback terakhir kalau tetap tidak muat",
    true, api.__window.getComputedStyle(d.querySelector(".nav")).overflowX === "auto");
  check("NAV14.uploadPoAndMasterBothTopLevel", "NAV14 — Upload PO dan Master tetap direct child .nav (selalu visible, bukan di overflow)",
    true, !!d.querySelector(".nav > .nav-btn[data-p='p-po']") && !!d.querySelector(".nav > .nav-btn[data-p='p-master']"));

  api.go(returBtn);
  check("NAV15.lainnyaActiveOnSecondaryPage", "NAV15 — \"Lainnya\" ter-highlight (has-active) saat halaman sekunder aktif",
    true, navMore.classList.contains("has-active"));
  api.goPage("p-dash");
  check("NAV15.lainnyaNotActiveOnPrimaryPage", "NAV15 — \"Lainnya\" TIDAK ter-highlight saat di halaman primary",
    false, navMore.classList.contains("has-active"));

  /* ===================== PRODUKSI ===================== */
  const TGL = "2026-10-01";
  const rowsBasic = [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","TOTAL","","TOTAL","","TOTAL"],
    ["","","","","TOKO NAV","","TOKO NAV","","",""],
    [1,"BASIC","NAV01","PROD NAV A", 100, 100, 0,0, 0,0],
  ];
  await uploadPO(api, rowsBasic, {factory:"karangtengah", tgl:TGL, sheetName:"01"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();
  const rowsRoti = [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","TOTAL","","TOTAL","","TOTAL"],
    ["","","","","TOKO NAV","","TOKO NAV","","",""],
    [1,"ROTI TAWAR","NAV02","PROD NAV B", 80, 80, 0,0, 0,0],
  ];
  await uploadPO(api, rowsRoti, {factory:"karangtengah", tgl:TGL, sheetName:"01"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();

  const divisiBasic = api.divisiProduk(api.D.po[TGL].find(r=>r.kode==="NAV01"));
  const divisiRoti = api.divisiProduk(api.D.po[TGL].find(r=>r.kode==="NAV02"));

  api.goPage("p-prod");
  api.$("pd-tgl").value = TGL; api.$("pd-divisi").value = "";
  api.prodBuildChecklist();
  check("PROD04.checklistNotForcedOpenBeforeDivisi", "PROD04 — SKU checklist (pdCard) TIDAK tampil sebelum divisi dipilih",
    "none", api.$("pdCard").style.display);

  check("PROD01.fourKpiBeforeChecklist", "PROD01 — 4 KPI ringkasan (Total Target/Aktual/Reject/Sisa) tampil sebelum checklist dibuka",
    "block", api.$("pdRingkasanWrap").style.display);

  submitCeklis(api, TGL, divisiBasic);
  submitCeklis(api, TGL, divisiRoti, {NAV02:{aktual:30, reject:0}});
  api.renderPdRingkasan();
  const ringkasan = api.pdRingkasanHariIni(TGL);
  check("PROD02.statusPerDivisiVisible", "PROD02 — tabel status per divisi (Target/Aktual/Sisa/Status) terisi",
    true, ringkasan.length>0 && api.$("pdRingkasanBody").innerHTML.length>0);
  check("PROD03.unfinishedFirst", "PROD03 — divisi belum selesai (Roti & Bollen, sisa 50) tampil sebelum yang Selesai (Basic)",
    true, ringkasan.findIndex(r=>r.divisi===divisiRoti) < ringkasan.findIndex(r=>r.divisi===divisiBasic));

  api.pdBukaDivisi(divisiRoti);
  check("PROD05.klikLihatOpensChecklist", "PROD05 — klik \"Lihat\" (pdBukaDivisi) membuka checklist SKU divisi tsb",
    JSON.stringify({visible:"block", divisi:divisiRoti}),
    JSON.stringify({visible: api.$("pdCard").style.display, divisi: api.$("pd-divisi").value}));

  /* ===================== KIRIM TOKO ===================== */
  api.$("pd-divisi").value = divisiBasic; api.prodBuildChecklist();
  submitCeklis(api, TGL, api.SPECIAL_FG);
  markFgReady(api, TGL, api.SPECIAL_FG);

  api.goPage("p-kirim");
  api.renderKPortalStats();
  check("SHIP01.doFormHiddenByDefault", "SHIP01 — form DO (kCard) tersembunyi by default", "none", api.$("kCard").style.display);
  check("SHIP02.bakeryReadyListVisibleByDefault", "SHIP02 — daftar bakery siap dikirim (kFgReadyCard) tampil by default",
    "block", api.$("kFgReadyCard").style.display);

  const readyGroups = api.fgReadyListByBakery().filter(g=>g.tgl===TGL);
  check("SHIP04.canonicalOneRow", "SHIP04 — bakery canonical \"TOKO NAV\" tampil satu row (bukan duplikat)",
    1, readyGroups.filter(g=>g.bakery==="TOKO NAV").length);

  api.kTarikDariFGBakery(TGL, "TOKO NAV");
  check("SHIP03.prosesKirimOpensForm", "SHIP03 — klik \"Proses Kirim\" (kTarikDariFGBakery) membuka form DO",
    "block", api.$("kCard").style.display);

  const qtyBefore = api.stokGudang("PROD NAV A");
  api.kSimpan();
  check("SHIP05.saveDoUnchanged", "SHIP05 — Simpan DO tetap mengurangi stok gudang seperti biasa (logika tidak berubah)",
    true, api.stokGudang("PROD NAV A") < qtyBefore);
  check("SHIP06.cetakDoAndInvoiceAvailableAfterSave", "SHIP06 — Cetak DO & Buat Invoice tetap tersedia setelah DO tersimpan",
    JSON.stringify({doneVisible:"block", hasCetak:true, hasInvoice:true}),
    JSON.stringify({
      doneVisible: api.$("kDoneWrap").style.display,
      hasCetak: api.$("kDoneWrap").innerHTML.includes("Cetak DO"),
      hasInvoice: api.$("kDoneWrap").innerHTML.includes("Buat Invoice"),
    }));

  // Manual entry path masih ada (bukan cuma lewat Proses Kirim).
  api.kBukaFormBaru();
  check("SHIP.manualEntryStillWorks", "SHIP — tombol \"Buat Delivery Order Manual\" (kBukaFormBaru) tetap membuka form",
    "block", api.$("kCard").style.display);

  /* ===================== KARTU STOK ===================== */
  api.goPage("p-stok");
  const adjDetails = api.$("adj-tgl").closest("details");
  check("STOCK01.adjustmentClosedByDefault", "STOCK01 — \"Catat Penyesuaian Stok\" tertutup by default",
    JSON.stringify({isDetails:true, closed:true}),
    JSON.stringify({isDetails: adjDetails?adjDetails.tagName==="DETAILS":false, closed: adjDetails? !adjDetails.open : false}));
  adjDetails.querySelector("summary").click();
  check("STOCK02.expandShowsForm", "STOCK02 — expand menampilkan form penyesuaian existing (Tanggal/Jenis/Produk/Qty/Keterangan/Simpan)",
    true, adjDetails.open && !!api.$("adj-produk") && !!api.$("adj-qty") && adjDetails.innerHTML.includes("Simpan Penyesuaian"));
  api.renderKartuStok();
  check("STOCK03.kpiAndTableVisibleWithoutExpanding", "STOCK03 — 4 KPI stok & tabel mutasi tetap tampil tanpa harus expand adjustment",
    true, api.$("ks-stats")!=null && !api.$("ks-stats").closest("details") && api.$("ks-body")!=null && !api.$("ks-body").closest("details"));

  api.D.masterProduk["PROD NAV A"] = api.D.masterProduk["PROD NAV A"] || {kategori:"BASIC", divisi:"Basic", hpp:0, harga:0, aktif:true, updatedAt:""};
  api.ksFillProdukSelect();
  api.$("adj-tgl").value = TGL;
  api.$("adj-tipe").value = "opname";
  api.$("adj-produk").value = "PROD NAV A";
  api.$("adj-qty").value = "5";
  const stokBeforeAdj = api.stokGudang("PROD NAV A");
  api.adjSimpan();
  check("STOCK04.adjustmentSaveStillWorks", "STOCK04 — Simpan Penyesuaian existing tetap berfungsi (stok berubah +5)",
    stokBeforeAdj+5, api.stokGudang("PROD NAV A"));

  /* ===================== REKAP ===================== */
  api.goPage("p-rekap");
  api.$("rk-dari").value = TGL; api.$("rk-sampai").value = TGL;
  api.renderRekap();
  check("REKAP01.primaryKpiVisible", "REKAP01 — KPI primary (Kirim ke Toko/Retur/Reject/Sell-through) tampil",
    true, api.$("rk-stats")!=null && !api.$("rk-stats").closest("details"));
  check("REKAP.performanceKpiVisible", "REKAP — KPI performa (Omset Pabrik/Margin/Fulfillment/Bakery Belum Selesai) tampil",
    true, api.$("rk-statsPerforma")!=null && !api.$("rk-statsPerforma").closest("details"));
  check("REKAP02.perFactoryVisible", "REKAP02 — blok Per Factory tampil", true, api.$("rk-perFactory")!=null && !api.$("rk-perFactory").closest("details"));
  check("REKAP03.perBakeryVisible", "REKAP03 — blok Per Bakery tampil", true, api.$("rk-perBakery")!=null && !api.$("rk-perBakery").closest("details"));
  check("REKAP04.topConcernVisible", "REKAP04 — blok Top Concern tampil", true, api.$("rk-topConcern")!=null && !api.$("rk-topConcern").closest("details"));
  const rekonDetails = api.$("rc-stats").closest("details");
  check("REKAP05.rekonsiliasiCollapsed", "REKAP05 — Rekonsiliasi Arus Barang collapsed by default",
    JSON.stringify({isDetails:true, closed:true}), JSON.stringify({isDetails: rekonDetails?rekonDetails.tagName==="DETAILS":false, closed: rekonDetails? !rekonDetails.open : false}));
  const simDetails = api.$("rk-persentase").closest("details");
  check("REKAP06.simulasiCollapsed", "REKAP06 — Simulasi Persentase Omset collapsed by default",
    JSON.stringify({isDetails:true, closed:true}), JSON.stringify({isDetails: simDetails?simDetails.tagName==="DETAILS":false, closed: simDetails? !simDetails.open : false}));
  const detailTambahan = api.$("rekapChart").closest("details");
  check("REKAP07.detailTambahanCollapsed", "REKAP07 — Detail Tambahan (Tren/Per Kategori/Per Produk/Per Toko) collapsed by default",
    JSON.stringify({isDetails:true, closed:true}), JSON.stringify({isDetails: detailTambahan?detailTambahan.tagName==="DETAILS":false, closed: detailTambahan? !detailTambahan.open : false}));

  /* ===================== Guard: business logic untouched ===================== */
  check("GUARD.canonicalStoreStillWorks", "Guard — canonical store resolver tidak diubah (TOKO NAV tetap resolve ke dirinya sendiri)",
    "TOKO NAV", api.canonicalStoreName("TOKO NAV"));
  check("GUARD.dashPipelineStillCorrect", "Guard — dashPipelineData tetap benar (Pesanan Outlet = 100+80 = 180)",
    180, api.dashPipelineData(TGL,TGL).pesanan);

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== NAV + COMPACT FIXES TEST TABLE ===\n");
  console.log("ID".padEnd(42), "Scenario".padEnd(92), "Expected".padEnd(20), "Actual".padEnd(20), "Result");
  results.forEach(r=>{
    console.log(r.id.padEnd(42), r.scenario.slice(0,92).padEnd(92), JSON.stringify(r.expected).slice(0,20).padEnd(20), JSON.stringify(r.actual).slice(0,20).padEnd(20), r.pass?"PASS":"FAIL");
  });
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, r.scenario, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main().catch(e=>{ console.error("SUITE CRASHED", e); process.exit(2); });
