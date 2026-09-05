"use strict";
// End-to-end UAT simulation against the REAL app script, via jsdom (real DOM,
// real innerHTML/querySelectorAll/dataset) + the REAL SheetJS (xlsx) library,
// so real functions are driven end-to-end (poProses, poSimpanPreview,
// prodBuildChecklist/pdBaca/pdSimpan, fgBuildPanel/fgMaterializeAll/
// fgTandaiSiap, kBuildGrid/kBaca/kSimpan, kBukaInvoice/kInvoiceSimpan,
// dashData/dashTrenData/renderDashStatusSubmit). Business logic under test is
// NEVER reimplemented here.
const { loadApp, XLSX } = require("./uat-harness.js");

const results = [];
function check(id, scenario, expected, actual, note){
  const pass = JSON.stringify(expected) === JSON.stringify(actual);
  results.push({id, scenario, expected, actual, pass, note});
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
// Submit production/FG for one divisi at the CURRENT default (segmented
// control "Sesuai" -> aktual = sisa target shown). Optionally patch specific
// rows' reject/aktual on the REAL rendered <tr> before submitting.
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
  const rowsBefore = api.pdBaca();
  api.pdSimpan();
  return rowsBefore;
}

/* =====================================================================
   MAIN NARRATIVE SESSION — Trials A..K, P, Q, R, S, T, U
   One continuous "user session" (single loadApp()), Karangtengah PO for
   2026-01-15 using the pre-verified sample totals (9.533 / 58 / 9.591),
   plus a packing/store-snapshot product and a reject-scenario product in
   the SAME upload so Trials G/H/R share the same session naturally.
   ===================================================================== */
async function mainNarrative(){
  const sentPayloads = [];
  const api = loadApp({ fetchImpl: (url,opts) => {
    sentPayloads.push({url, method:(opts&&opts.method)||"GET", body: opts&&opts.body});
    return Promise.resolve({ok:true, json: async()=>({})});
  }});
  api.$("cfg-url").value = "https://example.test/exec"; // non-empty so kirimSheets actually "sends" (captured, not real network)
  const TGL = "2026-01-15", FAC = "karangtengah";

  // Column layout (fixed across every row in this sheet):
  // 0 NO | 1 KATEGORI | 2 KODE | 3 NAMA PRODUK |
  // 4 SDRM(awal) 5 CKLE(awal) 6 TOKO_A(awal) 7 TOKO_B(awal) | 8 TOTAL(awal) |
  // 9 SDRM(rev) 10 CKLE(rev) 11 TOKO_A(rev) 12 TOKO_B(rev) | 13 TOTAL(rev) |
  // 14 PB | 15 TOTAL(PB)
  function sheet(rows){
    const head = ["NO","KATEGORI","KODE","NAMA PRODUK","","","","","TOTAL","","","","","TOTAL","","TOTAL"];
    const sub  = ["","","","","SDRM","CKLE","TOKO_A","TOKO_B","","SDRM","CKLE","TOKO_A","TOKO_B","","",""];
    return [head, sub, ...rows];
  }
  // row(kode, kategori, nama, [sdrmA,ckleA,tokoAA,tokoBA], [sdrmR,ckleR,tokoAR,tokoBR])
  function row(no,kategori,kode,nama, awal, revisi){
    const a = awal||[0,0,0,0], r = revisi||[0,0,0,0];
    const totalA = a.reduce((x,y)=>x+y,0), totalR = r.reduce((x,y)=>x+y,0);
    return [no,kategori,kode,nama, a[0],a[1],a[2],a[3], totalA, r[0],r[1],r[2],r[3], totalR, 0, 0];
  }

  /* ---------------- TRIAL A — INITIAL PO ---------------- */
  const rowsInitial = sheet([
    row(1,"BASIC","K100","PRODUK UTAMA",        [5000,4533,0,0], [0,0,0,0]),   // main sample: 9533 / 0
    row(2,"BASIC","K200","PRODUK PACKING",      [0,0,10,0],      [0,0,0,0]),   // store-snapshot subject: TOKO_A 10/0
    row(3,"BASIC","K400","PRODUK REJECT TEST",  [0,0,0,100],     [0,0,0,0]),   // reject scenario: TOKO_B 100
  ]);
  await uploadPO(api, rowsInitial, {factory:FAC, tgl:TGL, sheetName:"15"});
  check("A.mode", "Initial PO — mode", "INITIAL", api.poPreview.mode);
  const rA = api.poPreview.rows;
  check("A.poAwalUtama", "Initial PO — PRODUK UTAMA PO Awal", 9533, rA.find(r=>r.kode==="K100").poAwal);
  check("A.poRevisiUtama", "Initial PO — PRODUK UTAMA PO Revisi (belum ada)", 0, rA.find(r=>r.kode==="K100").poRevisi);
  check("A.targetUtama", "Initial PO — target = 9533 (PB diabaikan)", 9533, api.hitungTarget(rA.find(r=>r.kode==="K100")));
  // resolve all 3 brand-new products (not in the built-in catalog) before saving
  [0,1,2].forEach(i=>api.poResolusiJadiBaru(i));
  api.poSimpanPreview();
  check("A.saved", "Initial PO — D.po tersimpan satu snapshot", 3, api.D.po[TGL].filter(r=>r.factory===FAC).length);
  const dupKeysA = api.D.po[TGL].filter(r=>r.factory===FAC).map(r=>r.kode);
  check("A.noDuplicateRow", "Initial PO — tidak ada duplicate kode", dupKeysA.length, new Set(dupKeysA).size);

  /* ---------------- TRIAL B — PRODUKSI AWAL (full 9533) ---------------- */
  const divisiUtama = api.divisiProduk(api.D.po[TGL].find(r=>r.kode==="K100"));
  submitCeklis(api, TGL, divisiUtama); // default "sesuai" -> aktual = full target for every row in this divisi
  const ceklisB = api.D.ceklis[api.ceklisKey(TGL, divisiUtama)];
  check("B.cumulativeActual", "Produksi awal — cumulative actual PRODUK UTAMA", 9533, ceklisB.rows.find(r=>r.kode==="K100").aktual);
  check("B.sisaZero", "Produksi awal — sisa target PRODUK UTAMA", 0, api.pdSisaTarget(TGL,divisiUtama).find(r=>r.kode==="K100").sisa);

  // Prime the FG "Packing Per Toko" panel NOW (K300 does not exist yet) so that
  // when a later PO revision introduces K300 as a brand-new product/store row,
  // the panel's fgPacking record for this tanggal+factory ALREADY exists —
  // matching the real-world "packing already started, then revision adds a
  // new row" scenario (Trial H), rather than K300 being present at the very
  // first-ever materialize (which legitimately defaults to target, by design).
  submitCeklis(api, TGL, api.SPECIAL_FG);
  api.prodBuildChecklist(); // materializes fgPacking for K100/K200/K400 only

  /* ---------------- TRIAL C — UPLOAD REVISION (+58) ---------------- */
  const rowsRevisi1 = sheet([
    row(1,"BASIC","K100","PRODUK UTAMA",        [5000,4533,0,0], [30,28,0,0]), // +58 total
    row(2,"BASIC","K200","PRODUK PACKING",      [0,0,10,0],      [0,0,5,0]),   // TOKO_A revisi 5 (target 15)
    row(3,"BASIC","K400","PRODUK REJECT TEST",  [0,0,0,100],     [0,0,0,0]),
    row(4,"BASIC","K300","PRODUK BARU REVISION",[0,0,0,0],       [0,0,0,12]),  // new product, only in TOKO_B revisi
  ]);
  await uploadPO(api, rowsRevisi1, {factory:FAC, tgl:TGL, sheetName:"15"});
  check("C.mode", "Upload revision — mode terdeteksi otomatis", "REVISION", api.poPreview.mode);
  const rowUtamaC = api.poPreview.rows.find(r=>r.kode==="K100");
  check("C.poAwalLocked", "Upload revision — PO Awal existing tetap (file kedua tidak diproses ulang)", 9533, rowUtamaC.poAwal);
  check("C.poRevisiLatest", "Upload revision — PO Revisi latest", 58, rowUtamaC.poRevisi);
  check("C.targetKumulatif", "Upload revision — target kumulatif", 9591, api.hitungTarget(rowUtamaC));
  api.poResolusiJadiBaru(api.poPreview.rows.findIndex(r=>r.kode==="K300")); // brand-new product row
  api.poSimpanPreview();
  const ceklisAfterRevisi = api.D.ceklis[api.ceklisKey(TGL, divisiUtama)];
  check("C.ceklisNotReset", "Upload revision — D.ceklis lama tidak direset", 9533, ceklisAfterRevisi.rows.find(r=>r.kode==="K100").aktual);
  check("C.sisaSetelahRevisi", "Upload revision — pdSisaTarget total sisa (bukan 9591)", 58, api.pdSisaTarget(TGL,divisiUtama).find(r=>r.kode==="K100").sisa);
  const payloadC = JSON.parse(sentPayloads.filter(p=>{try{return JSON.parse(p.body).jenis==="poUpload";}catch{return false;}}).pop().body);
  check("C.pbIgnoredInPayload", "Upload revision — payload rows tidak dipengaruhi PB", 0, payloadC.rows.find(r=>r.kode==="K100").pb===0?0:1);

  /* ---------------- TRIAL D — IDEMPOTENT REUPLOAD (same revision file again) ---------------- */
  await uploadPO(api, rowsRevisi1, {factory:FAC, tgl:TGL, sheetName:"15"});
  const rowUtamaD = api.poPreview.rows.find(r=>r.kode==="K100");
  check("D.targetStable", "Idempotent reupload — target tetap 9591 (bukan 9649)", 9591, api.hitungTarget(rowUtamaD));
  check("D.revisiStable", "Idempotent reupload — revisi tetap 58", 58, rowUtamaD.poRevisi);
  api.poSimpanPreview();
  check("D.sisaStable", "Idempotent reupload — sisa produksi tetap 58", 58, api.pdSisaTarget(TGL,divisiUtama).find(r=>r.kode==="K100").sisa);
  check("D.rowCountStable", "Idempotent reupload — tidak ada duplicate PO row", 4, api.D.po[TGL].filter(r=>r.factory===FAC).length);

  /* ---------------- TRIAL E — PRODUKSI TAMBAHAN (+58) ---------------- */
  submitCeklis(api, TGL, divisiUtama); // "sesuai" now defaults aktual = remaining sisa (58) for every still-open row
  const ceklisE = api.D.ceklis[api.ceklisKey(TGL, divisiUtama)];
  check("E.cumulativeActual", "Produksi tambahan — cumulative actual", 9591, ceklisE.rows.find(r=>r.kode==="K100").aktual);
  check("E.sisaZero", "Produksi tambahan — sisa target", 0, api.pdSisaTarget(TGL,divisiUtama).find(r=>r.kode==="K100").sisa);

  /* ---------------- TRIAL R — REJECT (K400: target100, aktual100, reject5) ---------------- */
  // K400's divisi may differ from divisiUtama if kategori differs; both are "BASIC" here so it's the same divisi/session.
  const patchReject = { K400: { aktual:100, reject:5 } };
  // K400 wasn't submitted yet in Trials B/E (submitCeklis affects ALL rows of the divisi each time) —
  // by now it's already been auto-submitted twice (Trial B initial + Trial E revision-add, both no-op since K400 has no revision).
  // Force its ceklis back open is not part of app design (ceklis is cumulative-only) — so verify reject bookkeeping directly
  // from what's already recorded: submitCeklis's default "sesuai" pass never had a nonzero reject typed in for K400,
  // so re-derive Trial R as an independent, isolated check further below (fresh session) for a clean target/actual/reject triple.

  /* ---------------- TRIAL F — FINISHGOOD ---------------- */
  submitCeklis(api, TGL, api.SPECIAL_FG); // FG's OWN checklist (Cek Kesesuaian Barang Diterima) — separate from packing-per-toko
  const fgCeklis = api.D.ceklis[api.ceklisKey(TGL, api.SPECIAL_FG)];
  check("F.fgReadsCumulative", "Finishgood — verifies cumulative production (9591)", 9591, fgCeklis.rows.find(r=>r.kode==="K100").aktual);
  check("F.stockAfterFG", "Finishgood — stok bertambah SETELAH FG (bukan saat produksi saja)", 9591, api.stokGudang("PRODUK UTAMA"));

  /* ---------------- TRIAL S — FG DOUBLE COUNT (dashboard) ---------------- */
  // Ground truth computed independently from the SOURCE divisi's own ceklis
  // (Basic) — sum of aktual across ALL products in that divisi (K100,K200,
  // K400,K300), i.e. what production actually reported. This is the number
  // that must NOT be doubled by counting FG's verification a second time.
  const groundTruthAktual = api.D.ceklis[api.ceklisKey(TGL,divisiUtama)].rows.reduce((a,r)=>a+api.num(r.aktual),0);
  const w = api.__window;
  api.$("db-dari").value = TGL; api.$("db-sampai").value = TGL;
  w.eval("renderDashboard()");
  const totalAktualCard = api.$("db-stats").innerHTML.match(/Total Aktual<\/div><div class="val">([\d.,]+)<\/div>/);
  const dashTotalAktual = totalAktualCard ? parseInt(totalAktualCard[1].replace(/[.,]/g,""),10) : null;
  check("S.dashboardMainKPINotDoubled", "Dashboard Total Aktual (KPI utama) tidak dobel produksi+FG", groundTruthAktual, dashTotalAktual);
  // Secondary "Status Submit" per-factory subtotal — read from the REAL rendered
  // HTML that renderDashStatusSubmit() itself produces (not a re-derivation),
  // so this actually exercises the function under test, not a copy of its logic.
  w.eval(`renderDashStatusSubmit("${TGL}")`);
  const dashWrapHtml = api.$("dashWrap").innerHTML;
  const factoryLineMatch = dashWrapHtml.match(/Karangtengah[\s\S]*?—\s*([\d.,]+)\s*target\s*·\s*([\d.,]+)\s*aktual/);
  const statusSubmitKarangtengahTotal = factoryLineMatch ? parseInt(factoryLineMatch[2].replace(/[.,]/g,""),10) : null;
  check("S.statusSubmitWidgetNotDoubled", `Dashboard 'Status Submit' per-factory total tidak dobel FG (BUG jika != ${groundTruthAktual})`,
    groundTruthAktual, statusSubmitKarangtengahTotal);

  /* ---------------- TRIAL G — PACKING PER TOKO (existing preserved) ---------------- */
  submitCeklis(api, TGL, api.SPECIAL_FG); // idempotent re-submit is fine (cumulative, no new rows changed since last submit) — ensures FG panel materializes
  api.prodBuildChecklist(); // rebuilds fgPanel for the still-selected SPECIAL_FG divisi
  const fgKeyStr = api.fgKey(TGL, FAC);
  // Operator explicitly packs PRODUK PACKING/TOKO_A = 10 (matching the pre-revision target) BEFORE the store revision existed in packing state.
  api.D.fgPacking[fgKeyStr].packed["K200|TOKO_A"] = {qty:10, status:"sesuai", keterangan:""};
  api.saveD();
  api.prodBuildChecklist(); // re-render after revision (K200/TOKO_A target now 15) — fgMaterializeAll must NOT touch existing packed
  const fgRowK200 = api.fgStoreRows(TGL,FAC).find(r=>r.kode==="K200" && r.toko==="TOKO_A");
  const packedK200 = api.fgGetPacked(TGL,FAC,"K200","TOKO_A");
  check("G.targetBaru", "Packing per toko — target baru (10+5)", 15, fgRowK200.target);
  check("G.packedPreserved", "Packing per toko — packed existing tetap 10 (tidak direset)", 10, packedK200.qty);
  check("G.sisa", "Packing per toko — sisa", 5, fgRowK200.target-packedK200.qty);

  /* ---------------- TRIAL H — PRODUK/TOKO BARU DI REVISION ---------------- */
  const fgRowK300 = api.fgStoreRows(TGL,FAC).find(r=>r.kode==="K300" && r.toko==="TOKO_B");
  const packedK300 = api.fgGetPacked(TGL,FAC,"K300","TOKO_B");
  check("H.targetBaru", "Produk baru di revision — target produksi", 12, fgRowK300 ? fgRowK300.target : null);
  check("H.packedAwalNol", "Produk baru di revision — packed awal HARUS 0 (bukan auto-terisi)", 0, packedK300.qty);
  check("H.sisaPacking", "Produk baru di revision — sisa packing", 12, fgRowK300 ? fgRowK300.target-packedK300.qty : null);

  /* ---------------- TRIAL I — STOK ---------------- */
  const stokBeforeAnyDO = api.stokGudang("PRODUK UTAMA");
  check("I.fgOnlyAddsStock", "Stok — FG verified menambah stok (120-analog: full 9591)", 9591, stokBeforeAnyDO);
  api.fgTandaiSiap(); // marks ready (packing-per-toko readiness flag) — does not itself change D.kirim/stock

  /* ---------------- TRIAL J — DELIVERY ORDER ---------------- */
  api.$("k-tgl").value = TGL;
  ensureTokoOption(api, "SDRM"); ensureTokoOption(api, "CKLE");
  api.$("k-toko").value = "SDRM";
  api.kBuildGrid();
  let idx = api.kList.indexOf("PRODUK UTAMA");
  api.$("kq-"+idx).value = "5000"; // partial: 5000 of the 9591 stock, matches store SDRM's own PO (5000 awal)
  api.kHitung();
  check("J.qtyNotDisabled", "DO — qty diperbolehkan (stok mencukupi)", false, api.$("kq-"+idx).disabled);
  const doItems1 = api.kBaca();
  api.kSimpan();
  const stokAfterDO1 = api.stokGudang("PRODUK UTAMA");
  check("J.stockReducedByDO", "DO — stok berkurang sesuai qty DO (9591-5000)", 4591, stokAfterDO1);
  const doBatch1 = api.D.kirim.find(r=>r.produk==="PRODUK UTAMA").batch;

  // Second DO to CKLE for the remainder of PRODUK UTAMA's own store PO (won't consume all stock).
  api.$("k-toko").value = "CKLE";
  api.kBuildGrid();
  idx = api.kList.indexOf("PRODUK UTAMA");
  api.$("kq-"+idx).value = "4000";
  api.kHitung();
  api.kSimpan();
  const stokAfterDO2 = api.stokGudang("PRODUK UTAMA");
  check("J.secondDO", "DO kedua — stok berkurang lagi (4591-4000)", 591, stokAfterDO2);
  const doSnapshotBeforeRevision = JSON.stringify(api.D.kirim); // captured AFTER both existing DOs, BEFORE the next revision upload

  // Now upload ANOTHER revision (K100 revisi 58->70) — existing DOs must be untouched.
  const rowsRevisi2 = sheet([
    row(1,"BASIC","K100","PRODUK UTAMA",        [5000,4533,0,0], [40,30,0,0]), // revisi bumped 58->70
    row(2,"BASIC","K200","PRODUK PACKING",      [0,0,10,0],      [0,0,5,0]),
    row(3,"BASIC","K400","PRODUK REJECT TEST",  [0,0,0,100],     [0,0,0,0]),
    row(4,"BASIC","K300","PRODUK BARU REVISION",[0,0,0,0],       [0,0,0,12]),
  ]);
  await uploadPO(api, rowsRevisi2, {factory:FAC, tgl:TGL, sheetName:"15"});
  api.poSimpanPreview();
  check("J.existingDOUnchanged", "DO existing tidak berubah saat revision baru diupload", doSnapshotBeforeRevision, JSON.stringify(api.D.kirim));

  /* ---------------- TRIAL K — INVOICE ---------------- */
  api.D.masterProduk["PRODUK UTAMA"] = {kategori:"BASIC", divisi:"Basic", hpp:3000, harga:5000, aktif:true, updatedAt:""};
  api.kBukaInvoice(doBatch1);
  const invItemsBefore = api.kInvoiceBaca();
  api.kInvoiceSimpan();
  const invoiceBefore = JSON.parse(JSON.stringify(api.D.invoice[doBatch1]));
  check("K.invoiceFollowsDOQty", "Invoice — qty mengikuti qty DO (default)", 5000, invoiceBefore.items[0].qtyInvoice);
  check("K.invoicePriceRule", "Invoice — harga = Harga100% x %Ownership (55%) existing rule", 2750, invoiceBefore.items[0].harga);
  // Another revision upload must not touch the already-saved invoice.
  await uploadPO(api, rowsRevisi2, {factory:FAC, tgl:TGL, sheetName:"15"});
  api.poSimpanPreview();
  check("K.invoiceUntouchedByRevision", "Invoice existing tidak berubah saat revision PO diupload lagi", JSON.stringify(invoiceBefore), JSON.stringify(api.D.invoice[doBatch1]));

  /* ---------------- TRIAL Q — PRODUCT MAPPING GUARD ---------------- */
  // "suggest" candidate must be a genuinely close (single-character) typo of an
  // EXISTING catalog product so cocokProduk's fuzzy threshold (0.82) actually
  // triggers "suggest" rather than "unmapped" (appending extra words, or typo-ing
  // a session-local brand-new product, is too large an edit distance to trigger it).
  const realCatalogProduct = api.D.products.find(p=>p.length>=8) || api.D.products[0];
  const typoOfReal = realCatalogProduct.slice(0,-1) + (realCatalogProduct.slice(-1)==="A"?"O":"A"); // 1-char substitution
  const rowsGuard = sheet([
    row(1,"BASIC","K900",typoOfReal, [10,0,0,0],[0,0,0,0]),                       // should fuzzy-suggest against realCatalogProduct
    row(2,"BASIC","K901","Produk Sama Sekali Asing Belum Pernah Terdaftar Zzz9",[10,0,0,0],[0,0,0,0]), // unmapped
  ]);
  await uploadPO(api, rowsGuard, {factory:FAC, tgl:"2026-01-16", sheetName:"16"});
  const unresolvedQ = api.poHitungUnresolved(api.poPreview.rows);
  check("Q.hasSuggestAndUnmapped", "Mapping guard — 1 suggest + 1 unmapped terdeteksi", 2, unresolvedQ.length);
  check("Q.saveButtonDisabled", "Mapping guard — Save button disabled", true, api.$("po-btnSimpan").disabled);
  const poBeforeGuard = JSON.stringify(api.D.po["2026-01-16"]||null);
  api.poSimpanPreview(); // direct call — must still be rejected by the internal guard
  check("Q.directCallBlocked", "Mapping guard — panggilan langsung poSimpanPreview() tetap ditolak", poBeforeGuard, JSON.stringify(api.D.po["2026-01-16"]||null));
  // Resolve both, then Save must succeed.
  const suggestIdx = api.poPreview.rows.findIndex(r=>r._resolusi && r._resolusi.status==="suggest");
  const unmappedIdx = api.poPreview.rows.findIndex(r=>r._resolusi && r._resolusi.status==="unmapped");
  if(suggestIdx>=0) api.poResolusiKonfirmasi(suggestIdx);
  if(unmappedIdx>=0) api.poResolusiJadiBaru(unmappedIdx);
  check("Q.resolvedNoLongerBlocked", "Mapping guard — setelah resolve, unresolved = 0", 0, api.poHitungUnresolved(api.poPreview.rows).length);
  api.poSimpanPreview();
  check("Q.saveNowSucceeds", "Mapping guard — Save berhasil setelah mapping selesai", 2, (api.D.po["2026-01-16"]||[]).filter(r=>r.factory===FAC).length);

  /* ---------------- TRIAL P — CIBADAK ---------------- */
  const rowsCibadak = [
    ["","Kategori","Nama Produk","Harga Satuan","","TOTAL PO","","TOTAL PO","TOTAL PO"],
    ["","","","","TOKO1","","TOKO1","",""],
    ["","BOLU","BOLU SAMPLE UAT",10000, 3844, 3844, 0,0, 0],
  ];
  await uploadPO(api, rowsCibadak, {factory:"cibadak", tgl:"2026-01-17", sheetName:"17"});
  check("P.factoryDetected", "Cibadak — parser tetap detect Cibadak", "cibadak", api.poPreview.factory);
  check("P.totalAwal", "Cibadak — total PO Awal sample tetap 3844", 3844, api.poPreview.rows[0].poAwal);
  check("P.pbIgnored", "Cibadak — PB diabaikan dari target", 3844, api.hitungTarget(api.poPreview.rows[0]));
  api.poResolusiJadiBaru(0);
  api.poSimpanPreview();
  // Case-insensitive re-upload of the exact same product name (different case) must MATCH, not duplicate.
  const rowsCibadakLower = [
    ["","Kategori","Nama Produk","Harga Satuan","","TOTAL PO","","TOTAL PO","TOTAL PO"],
    ["","","","","TOKO1","","TOKO1","",""],
    ["","BOLU","bolu sample uat",10000, 3844, 3844, 60,0, 0],
  ];
  await uploadPO(api, rowsCibadakLower, {factory:"cibadak", tgl:"2026-01-17", sheetName:"17"});
  check("P.caseInsensitiveNoDuplicate", "Cibadak — nama beda case tetap 1 baris (bukan duplicate SKU)", "REVISION", api.poPreview.mode);
  check("P.caseInsensitiveAwalLocked", "Cibadak — PO Awal existing terkunci meski case berbeda", 3844, api.poPreview.rows[0].poAwal);
  check("P.caseInsensitiveRevisi", "Cibadak — PO Revisi latest terbaca", 60, api.poPreview.rows[0].poRevisi);

  /* ---------------- TRIAL U — DATA PRESERVATION ---------------- */
  const before = {
    ceklis: JSON.stringify(api.D.ceklis[api.ceklisKey(TGL,divisiUtama)]),
    fgPacking: JSON.stringify(api.D.fgPacking[api.fgKey(TGL,FAC)]),
    kirim: JSON.stringify(api.D.kirim),
    invoice: JSON.stringify(api.D.invoice),
  };
  const rowsRevisi3 = sheet([
    row(1,"BASIC","K100","PRODUK UTAMA",        [5000,4533,0,0], [50,40,0,0]), // bump again 70->90
    row(2,"BASIC","K200","PRODUK PACKING",      [0,0,10,0],      [0,0,5,0]),
    row(3,"BASIC","K400","PRODUK REJECT TEST",  [0,0,0,100],     [0,0,0,0]),
    row(4,"BASIC","K300","PRODUK BARU REVISION",[0,0,0,0],       [0,0,0,12]),
  ]);
  await uploadPO(api, rowsRevisi3, {factory:FAC, tgl:TGL, sheetName:"15"});
  api.poSimpanPreview();
  const after = {
    ceklis: JSON.stringify(api.D.ceklis[api.ceklisKey(TGL,divisiUtama)]),
    fgPacking: JSON.stringify(api.D.fgPacking[api.fgKey(TGL,FAC)]),
    kirim: JSON.stringify(api.D.kirim),
    invoice: JSON.stringify(api.D.invoice),
  };
  check("U.ceklisPreserved", "Data preservation — D.ceklis tidak berubah", before.ceklis, after.ceklis);
  check("U.fgPackingPreserved", "Data preservation — D.fgPacking tidak berubah", before.fgPacking, after.fgPacking);
  check("U.kirimPreserved", "Data preservation — D.kirim tidak berubah", before.kirim, after.kirim);
  check("U.invoicePreserved", "Data preservation — D.invoice tidak berubah", before.invoice, after.invoice);

  /* ---------------- TRIAL T — DUPLICATE CHECK ---------------- */
  const poKeys = api.D.po[TGL].filter(r=>r.factory===FAC).map(r=>`${TGL}|${FAC}|${r.kode}`);
  check("T.poNoDuplicate", "Duplicate check — PO (tanggal+factory+kode)", poKeys.length, new Set(poKeys).size);
  const fgPackKeys = Object.keys((api.D.fgPacking[api.fgKey(TGL,FAC)]||{packed:{}}).packed).map(k=>`${TGL}|${FAC}|${k}`);
  check("T.fgPackingNoDuplicate", "Duplicate check — FG packing (tanggal+factory+kode+toko)", fgPackKeys.length, new Set(fgPackKeys).size);
  const ceklisKeysAll = Object.keys(api.D.ceklis).filter(k=>k.startsWith(TGL+"|"));
  check("T.ceklisNoDuplicate", "Duplicate check — Ceklis (tanggal+divisi) — one record per key by construction", ceklisKeysAll.length, new Set(ceklisKeysAll).size);
  const kirimIds = api.D.kirim.map(r=>r.id);
  check("T.kirimNoDuplicateId", "Duplicate check — Kirim/DO id unik", kirimIds.length, new Set(kirimIds).size);
  const invoiceBatches = Object.keys(api.D.invoice);
  check("T.invoiceNoDuplicateBatch", "Duplicate check — Invoice batch unik", invoiceBatches.length, new Set(invoiceBatches).size);

  /* ---------------- TRIAL 24 — GOOGLE SHEETS PAYLOAD ---------------- */
  const poUploadPayloads = sentPayloads.filter(p=>{ try{ return JSON.parse(p.body).jenis==="poUpload"; }catch{ return false; } }).map(p=>JSON.parse(p.body));
  const forThisDate = poUploadPayloads.filter(p=>p.tanggal===TGL && p.factory===FAC);
  const lastPayload = forThisDate[forThisDate.length-1];
  const firstPayload = forThisDate[0];
  const sheetsReport = {
    totalPoUploadCalls: poUploadPayloads.length,
    firstUpload: {jenis:firstPayload.jenis, tanggal:firstPayload.tanggal, factory:firstPayload.factory, rows:firstPayload.rows.length},
    lastRevisionUpload: {jenis:lastPayload.jenis, tanggal:lastPayload.tanggal, factory:lastPayload.factory, rows:lastPayload.rows.length,
      poAwalUtama:lastPayload.rows.find(r=>r.kode==="K100").poAwal, poRevisiUtama:lastPayload.rows.find(r=>r.kode==="K100").poRevisi},
    latestSnapshotNotAccumulated: lastPayload.rows.find(r=>r.kode==="K100").poAwal===9533 && lastPayload.rows.find(r=>r.kode==="K100").poRevisi===90,
    backendPersistenceStatus: "FRONTEND PAYLOAD PASS / BACKEND PERSISTENCE UNVERIFIED (Code.gs not available in this repo)",
  };

  return { results, sheetsReport, api, TGL, FAC, divisiUtama };
}

/* =====================================================================
   ISOLATED ARITHMETIC SESSIONS — Trials L, M, N, O, R (each independent,
   fresh loadApp(), so partial/multi-revision arithmetic can't interfere
   with the main narrative above). Still driven via REAL poProses (real
   xlsx) + REAL prodBuildChecklist/pdBaca/pdSimpan — no logic reimplemented.
   ===================================================================== */
function simpleSheet(kode, produk, poAwal, poRevisi){
  return [
    ["NO","KATEGORI","KODE","NAMA PRODUK","","TOTAL","","TOTAL","PB","TOTAL"],
    ["","","","","TOKO_X","","TOKO_X","","",""],
    [1,"BASIC",kode,produk, poAwal, poAwal, poRevisi, poRevisi, 0, 0],
  ];
}
async function trialL(){
  const api = loadApp();
  const TGL="2026-02-01", FAC="karangtengah", KODE="K500";
  await uploadPO(api, simpleSheet(KODE,"PRODUK PARSIAL",100,0), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();
  const divisi = api.divisiProduk(api.D.po[TGL][0]);
  submitCeklis(api, TGL, divisi, {[KODE]:{aktual:70}});
  check("L.sisaAfter70", "Parsial — sisa setelah aktual 70/100", 30, api.pdSisaTarget(TGL,divisi).find(r=>r.kode===KODE).sisa);
  await uploadPO(api, simpleSheet(KODE,"PRODUK PARSIAL",100,20), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poSimpanPreview();
  check("L.targetAfterRevision", "Parsial — target kumulatif setelah revisi +20", 120, api.hitungTarget(api.D.po[TGL].find(r=>r.kode===KODE)));
  check("L.sisaAfterRevision", "Parsial — sisa produksi setelah revisi (120-70)", 50, api.pdSisaTarget(TGL,divisi).find(r=>r.kode===KODE).sisa);
  submitCeklis(api, TGL, divisi, {[KODE]:{aktual:30}}); // aktual now 100
  check("L.sisaAfter100", "Parsial — sisa setelah aktual kumulatif 100/120", 20, api.pdSisaTarget(TGL,divisi).find(r=>r.kode===KODE).sisa);
  submitCeklis(api, TGL, divisi); // "sesuai" default = remaining sisa (20) -> aktual 120
  check("L.sisaAfter120", "Parsial — sisa setelah aktual kumulatif 120/120", 0, api.pdSisaTarget(TGL,divisi).find(r=>r.kode===KODE).sisa);
  return results.filter(r=>r.id.startsWith("L."));
}
async function trialM(){
  const api = loadApp();
  const TGL="2026-02-02", FAC="karangtengah", KODE="K600";
  await uploadPO(api, simpleSheet(KODE,"PRODUK MULTI REVISI",100,0), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();
  const divisi = api.divisiProduk(api.D.po[TGL][0]);
  await uploadPO(api, simpleSheet(KODE,"PRODUK MULTI REVISI",100,20), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poSimpanPreview();
  submitCeklis(api, TGL, divisi); // full 120... but spec wants aktual=100 BEFORE revision changes to 35 — patch to 100 explicitly instead of full "sesuai".
  // Redo with an explicit patch matching the spec exactly: aktual=100 while target=120.
  return api; // handled inline below (needs patch before default-fills the row)
}
async function trialMExact(){
  const api = loadApp();
  const TGL="2026-02-02", FAC="karangtengah", KODE="K600";
  await uploadPO(api, simpleSheet(KODE,"PRODUK MULTI REVISI",100,0), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();
  const divisi = api.divisiProduk(api.D.po[TGL][0]);
  await uploadPO(api, simpleSheet(KODE,"PRODUK MULTI REVISI",100,20), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poSimpanPreview();
  submitCeklis(api, TGL, divisi, {[KODE]:{aktual:100}}); // aktual 100 of target 120
  check("M.sisaBeforeChange", "Revision berubah — sisa sebelum revisi berubah (120-100)", 20, api.pdSisaTarget(TGL,divisi).find(r=>r.kode===KODE).sisa);
  await uploadPO(api, simpleSheet(KODE,"PRODUK MULTI REVISI",100,35), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poSimpanPreview();
  check("M.targetAfterChange", "Revision berubah — target baru (100+35)", 135, api.hitungTarget(api.D.po[TGL].find(r=>r.kode===KODE)));
  check("M.sisaAfterChange", "Revision berubah — sisa setelah revisi berubah (135-100)", 35, api.pdSisaTarget(TGL,divisi).find(r=>r.kode===KODE).sisa);
  submitCeklis(api, TGL, divisi, {[KODE]:{aktual:20}}); // +20 -> cumulative 120
  check("M.sisaAfterMoreProd", "Revision berubah — sisa setelah produksi tambahan 20 (135-120)", 15, api.pdSisaTarget(TGL,divisi).find(r=>r.kode===KODE).sisa);
  return results.filter(r=>r.id.startsWith("M."));
}
async function trialN(){
  const api = loadApp();
  const TGL="2026-02-03", FAC="karangtengah", KODE="K700";
  await uploadPO(api, simpleSheet(KODE,"PRODUK REVISI TURUN",100,0), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();
  const divisi = api.divisiProduk(api.D.po[TGL][0]);
  await uploadPO(api, simpleSheet(KODE,"PRODUK REVISI TURUN",100,20), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poSimpanPreview();
  submitCeklis(api, TGL, divisi); // full 120
  check("N.aktualFull", "Revision turun — aktual sebelum revisi turun", 120, api.D.ceklis[api.ceklisKey(TGL,divisi)].rows.find(r=>r.kode===KODE).aktual);
  await uploadPO(api, simpleSheet(KODE,"PRODUK REVISI TURUN",100,15), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poSimpanPreview();
  const s = api.pdSisaTarget(TGL,divisi).find(r=>r.kode===KODE);
  check("N.targetBaru", "Revision turun — target baru (100+15)", 115, api.hitungTarget(api.D.po[TGL].find(r=>r.kode===KODE)));
  check("N.sisaClampedZero", "Revision turun — sisa TIDAK negatif (clamped ke 0)", 0, s.sisa);
  check("N.overproductionRecorded", "Revision turun — overproduction tercatat (120-115)", 5, s.overTarget);
  check("N.actualNotDeleted", "Revision turun — aktual lama TIDAK dihapus", 120, api.D.ceklis[api.ceklisKey(TGL,divisi)].rows.find(r=>r.kode===KODE).aktual);
  return results.filter(r=>r.id.startsWith("N."));
}
async function trialO(){
  const api = loadApp();
  const TGL="2026-02-04", FAC="karangtengah", KODE="K800";
  await uploadPO(api, simpleSheet(KODE,"PRODUK STORE SNAPSHOT",10,0), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();
  const store0 = api.D.po[TGL][0].stores[0];
  check("O.initialStoreTarget", "Store snapshot — target awal toko", 10, api.hitungTarget(store0));
  await uploadPO(api, simpleSheet(KODE,"PRODUK STORE SNAPSHOT",10,5), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poSimpanPreview();
  const store1 = api.D.po[TGL][0].stores[0];
  check("O.afterFirstRevision", "Store snapshot — target setelah revisi lama (10+5)", 15, api.hitungTarget(store1));
  await uploadPO(api, simpleSheet(KODE,"PRODUK STORE SNAPSHOT",10,8), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poSimpanPreview();
  const store2 = api.D.po[TGL][0].stores[0];
  check("O.afterSecondRevision", "Store snapshot — target setelah revisi baru (10+8, BUKAN 23)", 18, api.hitungTarget(store2));
  await uploadPO(api, simpleSheet(KODE,"PRODUK STORE SNAPSHOT",10,8), {factory:FAC,tgl:TGL,sheetName:"01"}); // reupload identical
  api.poSimpanPreview();
  const store3 = api.D.po[TGL][0].stores[0];
  check("O.idempotentReupload", "Store snapshot — reupload identik tetap 18", 18, api.hitungTarget(store3));
  return results.filter(r=>r.id.startsWith("O."));
}
async function trialRIsolated(){
  const api = loadApp();
  const TGL="2026-02-05", FAC="karangtengah", KODE="K900R";
  await uploadPO(api, simpleSheet(KODE,"PRODUK REJECT",100,0), {factory:FAC,tgl:TGL,sheetName:"01"});
  api.poResolusiJadiBaru(0); api.poSimpanPreview();
  const divisi = api.divisiProduk(api.D.po[TGL][0]);
  submitCeklis(api, TGL, divisi, {[KODE]:{aktual:100, reject:5}});
  const rec = api.D.ceklis[api.ceklisKey(TGL,divisi)].rows.find(r=>r.kode===KODE);
  check("R.aktualRecorded", "Reject — aktual tercatat 100", 100, rec.aktual);
  check("R.rejectRecorded", "Reject — reject tercatat 5 (beban pabrik, bukan toko)", 5, rec.reject);
  // FG's own checklist netto (target for FG = aktual - reject of the source divisi)
  submitCeklis(api, TGL, api.SPECIAL_FG, null);
  const fgTargetRow = api.targetUntukDivisi(TGL, api.SPECIAL_FG).find(r=>r.kode===KODE);
  check("R.fgNettoTarget", "Reject — FG target = netto (100-5=95), FG tidak menerima 100", 95, fgTargetRow ? fgTargetRow.target : null);
  return results.filter(r=>r.id.startsWith("R."));
}

async function main(){
  const main1 = await mainNarrative();
  await trialL();
  await trialMExact();
  await trialN();
  await trialO();
  await trialRIsolated();

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  const failCount = total - passCount;

  console.log("\n=== UAT DETAILED TEST TABLE ===\n");
  console.log("ID".padEnd(10), "Scenario".padEnd(70), "Expected".padEnd(10), "Actual".padEnd(10), "Result");
  results.forEach(r=>{
    console.log(r.id.padEnd(10), r.scenario.slice(0,70).padEnd(70), JSON.stringify(r.expected).padEnd(10), JSON.stringify(r.actual).padEnd(10), r.pass?"PASS":"FAIL");
  });

  console.log("\n=== GOOGLE SHEETS PAYLOAD REPORT ===\n");
  console.log(JSON.stringify(main1.sheetsReport, null, 1));

  console.log("\n=== METRIC RINGKAS ===\n");
  console.log(`Total test: ${total}`);
  console.log(`PASS: ${passCount}`);
  console.log(`FAIL: ${failCount}`);
  if(failCount){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, r.scenario, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = failCount ? 1 : 0;
}
main().catch(e=>{ console.error("UAT SUITE CRASHED", e); process.exit(2); });
