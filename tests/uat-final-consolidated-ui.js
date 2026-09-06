"use strict";
// Verifies the "final consolidated UI" pass driven by the attached mockup
// (amor-factory-final-ui-mockup.html) used ONLY as a layout/IA reference —
// no business logic, no dummy numbers copied in. Covers:
//   - canonical bakery display in Kirim Toko / Invoice / Omset / Rekap / filters
//   - Kirim Toko vs Invoice & Piutang separation (finance moved out)
//   - compact Kartu Stok (no long paragraph by default, 4 KPI, table visible)
//   - simplified Rekap (primary KPI + Per Factory/Per Bakery/Top Concern visible,
//     Rekonsiliasi + Simulasi Omset collapsed by default)
//   - navigation grouping (secondary menu items under "Lainnya", not deleted)
//
// Drives the REAL app functions via jsdom + real xlsx — no business logic
// reimplemented here.
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
  api.fgTandaiSiap();
}

async function main(){
  const api = loadApp();
  const TGL = "2026-09-20";

  /* ===================== Setup: SDRM -> BAKERY SUDIRMAN merge ===================== */
  const rows = [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","TOTAL","","TOTAL","","TOTAL"],
    ["","","","","SDRM","","SDRM","","",""],
    [1,"BASIC","SD01","ROTI SUDIRMAN TEST", 266, 266, 0,0, 0,0],
  ];
  await uploadPO(api, rows, {factory:"karangtengah", tgl:TGL, sheetName:"20"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();
  api.D.masterProduk["ROTI SUDIRMAN TEST"] = {kategori:"BASIC", divisi:"Basic", hpp:8000, harga:20000, aktif:true, updatedAt:""};

  const divisi = api.divisiProduk(api.D.po[TGL].find(r=>r.kode==="SD01"));
  submitCeklis(api, TGL, divisi);
  submitCeklis(api, TGL, api.SPECIAL_FG);
  markFgReady(api, TGL, api.SPECIAL_FG);

  // Merge SDRM -> BAKERY SUDIRMAN the way the Master Toko admin UI would leave
  // D.masterToko (mtkEditCanonical/mtkTambahAlias use window.prompt, which is
  // not meaningful in a headless test — so we set the same end-state directly).
  api.ensureMasterToko();
  const m = api.D.masterToko["SDRM"];
  m.canonicalName = "BAKERY SUDIRMAN";
  m.aliases = ["SDRM","SUDIRMAN","BAKERY SUDIRMAN"];
  api.saveD();
  check("SETUP.canonicalResolves", "Setup — canonicalStoreName(\"SDRM\") = \"BAKERY SUDIRMAN\" setelah merge",
    "BAKERY SUDIRMAN", api.canonicalStoreName("SDRM"));

  api.kTarikDariFGBakery(TGL, "BAKERY SUDIRMAN");
  api.kSimpan();
  const batchSdrm = api.D.kirim.find(r=>r.produk==="ROTI SUDIRMAN TEST").batch;

  /* ===================== TEST 18a: canonical display — Kirim Toko ===================== */
  check("T18.kirimDropdownCanonicalOnly", "Kirim Toko — dropdown k-toko berisi \"BAKERY SUDIRMAN\", TIDAK ada \"SDRM\" mentah",
    JSON.stringify({has:true, dup:false}),
    JSON.stringify((()=>{
      const opts = [...api.$("k-toko").options].map(o=>o.value).filter(Boolean);
      return {has:opts.includes("BAKERY SUDIRMAN"), dup:opts.includes("SDRM")};
    })()));
  const readyGroups = api.fgReadyListByBakery();
  check("T18.kirimNoDuplicateRow", "Kirim Toko — Siap Kirim tidak menghasilkan baris \"SDRM\" terpisah dari \"BAKERY SUDIRMAN\"",
    0, readyGroups.filter(g=>g.bakery==="SDRM").length);

  /* ===================== TEST 18b: canonical display — Invoice & Piutang ===================== */
  api.kBukaInvoice(batchSdrm);
  api.kInvoiceSimpan();
  api.renderKPortalStats();
  const invTokoOpts = [...api.$("inv-fToko").options].map(o=>o.value).filter(Boolean);
  check("T18.invoiceFilterCanonicalOnly", "Invoice & Piutang — filter toko berisi \"BAKERY SUDIRMAN\", TIDAK ada \"SDRM\" mentah",
    JSON.stringify({has:true, dup:false}), JSON.stringify({has:invTokoOpts.includes("BAKERY SUDIRMAN"), dup:invTokoOpts.includes("SDRM")}));
  const invRows = api.invBarisData().filter(x=>x.inv.batch===batchSdrm);
  check("T18.invoiceRowShowsCanonical", "Invoice & Piutang — baris invoice ini ditampilkan sbg \"BAKERY SUDIRMAN\"",
    "BAKERY SUDIRMAN", invRows[0] ? invRows[0].inv.toko : null);
  // Invoice lama (pra-migrasi) dgn alias mentah tetap terbaca & tetap tampil canonical, TIDAK jadi baris terpisah.
  api.D.invoice["OLD-SDRM-BATCH"] = {invoiceNo:"INV-OLD-SD", batch:"OLD-SDRM-BATCH", tgl:"2026-01-01", toko:"SDRM", noSJ:"", items:[{produk:"X",qty:1}], total:0, catatan:"", createdAt:"", updatedAt:""};
  api.saveD();
  check("T18.oldInvoiceRawUntouched", "Invoice lama tetap tertulis \"SDRM\" apa adanya (tidak ditulis ulang)",
    "SDRM", api.D.invoice["OLD-SDRM-BATCH"].toko);
  check("T18.oldInvoiceResolvesCanonical", "Invoice lama tsb tetap resolve ke \"BAKERY SUDIRMAN\" saat ditampilkan",
    "BAKERY SUDIRMAN", api.canonicalStoreName(api.D.invoice["OLD-SDRM-BATCH"].toko));

  /* ===================== TEST 18c: canonical display — Laporan Omset ===================== */
  const omsetRows = api.omsetPerBakeryData(TGL, TGL, {});
  check("T18.omsetOneRowPerBakery", "Laporan Omset — BAKERY SUDIRMAN satu baris (bukan SDRM terpisah)",
    1, omsetRows.filter(r=>r.bakery==="BAKERY SUDIRMAN").length);
  check("T18.omsetNoRawAliasRow", "Laporan Omset — tidak ada baris \"SDRM\" mentah berdiri sendiri",
    0, omsetRows.filter(r=>r.bakery==="SDRM").length);

  /* ===================== TEST 18d: canonical display — Rekap ===================== */
  api.$("rk-dari").value = TGL; api.$("rk-sampai").value = TGL;
  api.renderRekap();
  const rkPerBakeryHtml = api.$("rk-perBakery").innerHTML;
  check("T18.rekapPerBakeryShowsCanonical", "Rekap — blok Per Bakery menampilkan \"BAKERY SUDIRMAN\"",
    true, rkPerBakeryHtml.includes("BAKERY SUDIRMAN"));
  check("T18.rekapPerBakeryNoRawAlias", "Rekap — blok Per Bakery TIDAK menampilkan \"SDRM\" mentah sbg baris sendiri",
    false, rkPerBakeryHtml.includes(">SDRM<"));

  /* ===================== TEST 19: Kirim Toko vs Finance separation ===================== */
  const kirimPageHtml = api.$("p-kirim").innerHTML;
  check("T19.noPiutangKpiOnKirim", "Kirim Toko — halaman TIDAK berisi KPI Piutang",
    false, kirimPageHtml.includes("Sisa Piutang"));
  check("T19.noInvoiceTableOnKirim", "Kirim Toko — halaman TIDAK berisi tabel Daftar Invoice (id inv-list)",
    false, kirimPageHtml.includes('id="inv-list"'));
  check("T19.noPaymentControlsOnKirim", "Kirim Toko — halaman TIDAK berisi kontrol pembayaran (Simpan Pembayaran)",
    false, kirimPageHtml.includes("Simpan Pembayaran"));
  const invoicePageHtml = api.$("p-invoice").innerHTML;
  check("T19.invoicePageHasPiutangKpi", "Invoice & Piutang — halaman punya KPI Sisa Piutang",
    true, invoicePageHtml.includes("Sisa Piutang"));
  check("T19.invoicePageHasInvoiceTable", "Invoice & Piutang — halaman punya tabel Daftar Invoice (id inv-list)",
    true, invoicePageHtml.includes('id="inv-list"'));
  check("T19.invoicePageHasPaymentControls", "Invoice & Piutang — halaman punya kontrol pembayaran (Simpan Pembayaran)",
    true, invoicePageHtml.includes("Simpan Pembayaran"));

  /* ===================== TEST 20: compact Kartu Stok ===================== */
  api.goPage("p-stok");
  const stokPageHtml = api.$("p-stok").innerHTML;
  const helpDetails = api.__document.querySelector("#p-stok details.dd");
  check("T20.helpParagraphCollapsedByDefault", "Kartu Stok — paragraf bantuan panjang ada di dalam <details> tertutup (Cara baca), bukan selalu tampil",
    JSON.stringify({isDetails:true, closed:true}),
    JSON.stringify({isDetails: helpDetails ? helpDetails.tagName==="DETAILS" : false, closed: helpDetails ? !helpDetails.open : false}));
  check("T20.helpNotInDefaultView", "Kartu Stok — paragraf panjang lama TIDAK lagi selalu tampil sbg .note langsung di bawah judul",
    true, helpDetails && helpDetails.querySelector(".note")!=null);
  api.renderKartuStok();
  const ksStatsHtml = api.$("ks-stats").innerHTML;
  const ksLabelCount = (ksStatsHtml.match(/class="lbl"/g)||[]).length;
  check("T20.fourStockKpi", "Kartu Stok — 4 KPI (Saldo Awal/Masuk/Keluar/Saldo Akhir)", 4, ksLabelCount);
  check("T20.hasSaldoAwal", "Kartu Stok — KPI Saldo Awal ada", true, ksStatsHtml.includes("Saldo Awal"));
  check("T20.hasSaldoAkhir", "Kartu Stok — KPI Saldo Akhir ada", true, ksStatsHtml.includes("Saldo Akhir"));
  check("T20.tableImmediatelyVisible", "Kartu Stok — tabel mutasi (ks-body) langsung ada di DOM, tidak di dalam <details> tertutup",
    true, api.$("ks-body")!=null && !api.$("ks-body").closest("details"));
  check("T20.compactToolbarOneRow", "Kartu Stok — filter (tanggal/produk/search/checkbox/export) digabung dalam satu .tbar",
    true, api.$("ks-dari").closest(".tbar") === api.$("ks-cari").closest(".tbar") && api.$("ks-dari").closest(".tbar")!=null);
  // Detail Masuk/Keluar existing (ksPop/ksRincian) masih ada dipakai di baris tabel.
  check("T20.clickableMasukKeluarStillWired", "Kartu Stok — klik angka Masuk/Keluar (ksPop) tetap ada di kode render",
    true, typeof api.__window.ksPop === "function");

  /* ===================== TEST 21: simplified Rekap ===================== */
  check("T21.primaryKpiVisible", "Rekap — KPI operasional (Kirim ke Toko/Retur/Reject/Sell-through) langsung tampil",
    true, api.$("rk-stats")!=null && !api.$("rk-stats").closest("details"));
  check("T21.performanceKpiVisible", "Rekap — KPI performa (Omset Pabrik/Margin/Fulfillment/Bakery Belum Selesai) langsung tampil",
    true, api.$("rk-statsPerforma")!=null && !api.$("rk-statsPerforma").closest("details"));
  check("T21.perFactoryVisible", "Rekap — blok Per Factory langsung tampil (bukan di dalam details tertutup)",
    true, api.$("rk-perFactory")!=null && !api.$("rk-perFactory").closest("details"));
  check("T21.perBakeryVisible", "Rekap — blok Per Bakery langsung tampil", true, api.$("rk-perBakery")!=null && !api.$("rk-perBakery").closest("details"));
  check("T21.topConcernVisible", "Rekap — blok Top Concern langsung tampil", true, api.$("rk-topConcern")!=null && !api.$("rk-topConcern").closest("details"));
  const rekonDetails = api.$("rc-stats").closest("details");
  check("T21.rekonsiliasiHiddenByDefault", "Rekap — Rekonsiliasi Arus Barang di dalam <details> tertutup by default",
    JSON.stringify({isDetails:true, closed:true}), JSON.stringify({isDetails: rekonDetails?rekonDetails.tagName==="DETAILS":false, closed: rekonDetails? !rekonDetails.open : false}));
  const simulasiDetails = api.$("rk-persentase").closest("details");
  check("T21.simulasiHiddenByDefault", "Rekap — Simulasi Persentase Omset di dalam <details> tertutup by default",
    JSON.stringify({isDetails:true, closed:true}), JSON.stringify({isDetails: simulasiDetails?simulasiDetails.tagName==="DETAILS":false, closed: simulasiDetails? !simulasiDetails.open : false}));

  /* ===================== TEST: navigation grouping ===================== */
  const navBtns = [...api.__document.querySelectorAll(".nav > .nav-btn")].map(b=>b.dataset.p);
  check("NAV.topLevelHasCorePages", "Nav — top-level berisi Dashboard/Produksi/Kirim Toko/Laporan Omset/Invoice & Piutang/Kartu Stok/Rekap/Master",
    true, ["p-dash","p-prod","p-kirim","p-omset","p-invoice","p-stok","p-rekap","p-master"].every(p=>navBtns.includes(p)));
  const moreBtns = [...api.__document.querySelectorAll(".nav-more .nav-btn")].map(b=>b.dataset.p);
  check("NAV.secondaryStillAccessibleUnderMore", "Nav — Upload PO/Retur/Reject/Pesanan/Jual Konsumen tetap ada (di dropdown Lainnya)",
    true, ["p-po","p-retur","p-reject","p-pesanan","p-jual"].every(p=>moreBtns.includes(p)));
  api.goPage("p-retur");
  check("NAV.navigatingViaMoreStillWorks", "Nav — navigasi ke halaman di dalam dropdown Lainnya tetap berfungsi (go())",
    true, api.$("p-retur").classList.contains("active"));

  /* ===================== Regression guard: business logic untouched ===================== */
  check("GUARD.canonicalMergeNeverAutoAppliesToUnrelated", "Guard — merge SDRM tidak menyentuh bakery lain yang tidak terkait",
    "TOKO ASING LAIN", api.canonicalStoreName("Toko Asing Lain".toUpperCase()));
  check("GUARD.kirimQtyStillCorrect", "Guard — qty kirim BAKERY SUDIRMAN tetap 266 (logika kirim tidak berubah)",
    266, api.D.kirim.filter(r=>r.batch===batchSdrm).reduce((a,r)=>a+r.qty,0));

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== FINAL CONSOLIDATED UI TEST TABLE ===\n");
  console.log("ID".padEnd(40), "Scenario".padEnd(92), "Expected".padEnd(14), "Actual".padEnd(14), "Result");
  results.forEach(r=>{
    console.log(r.id.padEnd(40), r.scenario.slice(0,92).padEnd(92), JSON.stringify(r.expected).slice(0,14).padEnd(14), JSON.stringify(r.actual).slice(0,14).padEnd(14), r.pass?"PASS":"FAIL");
  });
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, r.scenario, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main().catch(e=>{ console.error("SUITE CRASHED", e); process.exit(2); });
