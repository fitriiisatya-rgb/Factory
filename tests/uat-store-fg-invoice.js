"use strict";
// Verifies the 3 browser-UAT issues reported after the nav/compact-fixes pass:
//   A/B/C. Full canonical store audit (STORE01-06) — every raw toko name used
//     across PO/FG-Packing/Kirim/Invoice/Retur/Reject/Rekap/Omset/filters is
//     classified mapped/unmapped/ambiguous in a new Master Toko audit table
//     (storeUsageAudit/renderStoreUsageAudit), and every user-facing display
//     surface resolves through canonicalStoreName() — raw aliases stay in the
//     underlying data (audit trail) but never render as a separate bakery.
//   D/E. FG/Packing 3-state status bug — a row nobody has touched yet must
//     default to "Belum Dicek" (neutral), NEVER auto-render as "Tidak Sesuai"
//     just because qty is still 0. "Tandai Siap Diambil Delivery" is soft-
//     gated (confirm()) if any row is still Belum Dicek; "Simpan Progres"
//     is never gated.
//   F/G. Compact Invoice row — one compact row per invoice (primary "Buka" +
//     secondary "..." overflow menu with Cetak Invoice/Buka DO/Edit/Bayar),
//     not 4 stacked vertical buttons — and the bakery name shown is always
//     canonical, old raw-alias invoice history is read (not rewritten).
//
// Drives the REAL app functions/DOM via jsdom + real xlsx — no business logic
// reimplemented here. Navigation/menus are exercised via direct function
// calls (api.fgSetStatus(btn,...), api.mtkTambahBakery(), ...) rather than
// synthetic .click() on inline onclick="...", per this harness's documented
// limitation (see uat-nav-compact-fixes.js header) — native <details> open
// state is still toggled directly where relevant.
const { loadApp } = require("./uat-harness.js");

const results = [];
function check(id, scenario, expected, actual){
  const pass = JSON.stringify(expected) === JSON.stringify(actual);
  results.push({id, scenario, expected, actual, pass});
  return pass;
}

function tambahBakery(api, storeId, canonicalName){
  api.$("mtk-storeIdBaru").value = storeId;
  api.$("mtk-canonBaru").value = canonicalName;
  api.mtkTambahBakery();
}
function tambahAlias(api, storeId, alias){
  api.renderMasterToko(); // pastikan input alias per-baris ("mtk-aliasBaru-<sid>") ada di DOM
  const el = api.$("mtk-aliasBaru-"+storeId);
  el.value = alias;
  api.mtkTambahAlias(storeId);
}

async function main(){
  const api = loadApp();
  const d = api.__document;

  /* ===================== Setup: master toko mapping ===================== */
  tambahBakery(api, "ABGN", "BAKERY BANTAR GEBANG");
  tambahAlias(api, "ABGN", "ABGN");
  tambahBakery(api, "SDRM", "BAKERY SUDIRMAN");
  tambahAlias(api, "SDRM", "SDRM");

  check("SETUP.abgnResolves", "Setup — canonicalStoreName(\"ABGN\") = \"BAKERY BANTAR GEBANG\"",
    "BAKERY BANTAR GEBANG", api.canonicalStoreName("ABGN"));
  check("SETUP.sdrmResolves", "Setup — canonicalStoreName(\"SDRM\") = \"BAKERY SUDIRMAN\"",
    "BAKERY SUDIRMAN", api.canonicalStoreName("SDRM"));

  /* ===================== Seed raw usage across sources ===================== */
  const TGL = "2026-05-01";
  api.D.po[TGL] = [{factory:"karangtengah", kategori:"BASIC", kode:"X001", produk:"PRODUK AUDIT", produkAsli:"PRODUK AUDIT",
    divisi:"Basic", poAwal:3, poRevisi:0, pb:0, stores:[{toko:"XYZ", poAwal:3, poRevisi:0}]}]; // XYZ tetap unmapped
  api.D.kirim.push({id:api.uid(), batch:"AUDIT-KIRIM-1", tgl:TGL, toko:"ABGN", produk:"PRODUK AUDIT", qty:10, noSJ:"", catatan:""});
  api.D.invoice["AUDIT-INV-1"] = {invoiceNo:"INV-AUDIT-1", batch:"AUDIT-KIRIM-1", tgl:TGL, toko:"ABGN", noSJ:"",
    items:[{produk:"PRODUK AUDIT", qty:10}], total:100000, createdAt:"", updatedAt:""};
  api.D.invoice["AUDIT-INV-2"] = {invoiceNo:"INV-AUDIT-2", batch:"AUDIT-KIRIM-2", tgl:TGL, toko:"SDRM", noSJ:"",
    items:[{produk:"PRODUK AUDIT", qty:5}], total:50000, createdAt:"", updatedAt:""};
  api.saveD();

  /* ===================== STORE01-03: full audit table ===================== */
  const auditRows = api.storeUsageAudit();
  const byRaw = {}; auditRows.forEach(r=>byRaw[r.raw]=r);

  check("STORE01.allDistinctRawNamesAudited", "STORE01 — semua nama mentah dari PO/Kirim/Invoice muncul di storeUsageAudit()",
    true, ["XYZ","ABGN","SDRM"].every(n=>!!byRaw[n]));

  check("STORE02.knownAliasesResolveMapped", "STORE02 — ABGN & SDRM berstatus mapped dgn nama standar yg benar",
    JSON.stringify({abgn:"mapped/BAKERY BANTAR GEBANG", sdrm:"mapped/BAKERY SUDIRMAN"}),
    JSON.stringify({abgn:`${byRaw.ABGN.status}/${byRaw.ABGN.canonical}`, sdrm:`${byRaw.SDRM.status}/${byRaw.SDRM.canonical}`}));

  const masterKeysBefore = Object.keys(api.D.masterToko).length;
  check("STORE03.unmappedReportedNotMerged", "STORE03 — XYZ dilaporkan unmapped (bukan di-auto-merge ke bakery manapun)",
    JSON.stringify({status:"unmapped", canonical:"-", stillUnresolved:true}),
    JSON.stringify({status:byRaw.XYZ.status, canonical:byRaw.XYZ.canonical, stillUnresolved: api.resolveStore("XYZ")===null}));
  check("STORE03.auditIsReadOnly", "STORE03 — memanggil storeUsageAudit() tidak mengubah D.masterToko (cuma laporan)",
    masterKeysBefore, Object.keys(api.D.masterToko).length);

  const rankOrder = auditRows.map(r=>r.status);
  const firstUnmappedIdx = rankOrder.indexOf("unmapped");
  const lastMappedIdx = rankOrder.lastIndexOf("mapped");
  check("STORE.unmappedSortedFirst", "C — baris unmapped diurutkan di paling atas (sebelum mapped)",
    true, firstUnmappedIdx===-1 || lastMappedIdx===-1 || firstUnmappedIdx<lastMappedIdx);

  api.renderMasterToko();
  const auditBodyHtml = api.$("mtk-usageAuditBody").innerHTML;
  check("STORE.auditTableRendersToDom", "C — tabel audit Master Toko ter-render ke #mtk-usageAuditBody",
    true, auditBodyHtml.includes("XYZ") && auditBodyHtml.includes("ABGN") && auditBodyHtml.includes("Unmapped"));

  /* ===================== STORE04: Invoice display canonical ===================== */
  api.fillInvoiceTokoSelect();
  api.renderInvoiceList();
  const invRowAbgn = [...d.querySelectorAll("#inv-list tr")].find(tr=>tr.textContent.includes("INV-AUDIT-1"));
  check("STORE04.invoiceDisplaysCanonical", "STORE04 — baris Invoice utk toko mentah ABGN tampil sbg \"BAKERY BANTAR GEBANG\"",
    JSON.stringify({canonical:true, rawLeaked:false}),
    JSON.stringify({canonical: invRowAbgn ? invRowAbgn.textContent.includes("BAKERY BANTAR GEBANG") : false,
                     rawLeaked: invRowAbgn ? invRowAbgn.cells[2].textContent.trim()==="ABGN" : true}));

  /* ===================== STORE05: Kirim (DO belum invoice) display canonical ===================== */
  api.D.kirim.push({id:api.uid(), batch:"AUDIT-KIRIM-3", tgl:TGL, toko:"SDRM", produk:"PRODUK AUDIT", qty:7, noSJ:"", catatan:""});
  api.saveD();
  api.renderKHist();
  const doRowSdrm = [...d.querySelectorAll("#kHist tr[data-batch]")].find(tr=>tr.dataset.batch==="AUDIT-KIRIM-3");
  check("STORE05.doListDisplaysCanonical", "STORE05 — daftar DO belum-invoice utk toko mentah SDRM tampil sbg \"BAKERY SUDIRMAN\"",
    "BAKERY SUDIRMAN", doRowSdrm ? doRowSdrm.cells[2].textContent.trim() : null);

  /* ===================== STORE06: Rekap Per Toko display canonical ===================== */
  api.$("rk-dari").value = TGL; api.$("rk-sampai").value = TGL;
  api.renderRekapToko();
  const rekapTokoHtml = api.$("rk-toko").innerHTML;
  check("STORE06.rekapTokoDisplaysCanonical", "STORE06 — Rekap Per Toko menampilkan bakery kanonik, bukan alias mentah",
    JSON.stringify({hasCanonical:true, rawLeaked:false}),
    JSON.stringify({hasCanonical: rekapTokoHtml.includes("BAKERY BANTAR GEBANG")||rekapTokoHtml.includes("BAKERY SUDIRMAN"),
                     rawLeaked: /(^|>)ABGN(<|$)/.test(rekapTokoHtml) || /(^|>)SDRM(<|$)/.test(rekapTokoHtml)}));

  /* ===================== FG01-06: FG/Packing 3-state status ===================== */
  const FTGL = "2026-06-01", FAC = "karangtengah", DIVISI = "Basic", KODE = "F900", TOKO_FG = "TOKO FG A";
  function setPOFg(poAwal, poRevisi){
    const gab = api.poMergeDenganExisting(FTGL, FAC, [{factory:FAC, kategori:"BASIC", kode:KODE, produk:"PRODUK FG",
      produkAsli:"PRODUK FG", divisi:DIVISI, poAwal, poRevisi, pb:0, stores:[{toko:TOKO_FG, poAwal, poRevisi}]}]);
    api.D.po[FTGL] = gab.rows; api.saveD();
  }
  setPOFg(20, 0);
  api.D.ceklis[api.ceklisKey(FTGL, DIVISI)] = {submitted:true, submittedAt:"now", closed:false,
    rows:[{kode:KODE, produk:"PRODUK FG", kategori:"BASIC", target:20, status:"sesuai", aktual:20, reject:0, keterangan:""}]};
  api.saveD();
  api.fgMaterializeAll(FTGL, FAC);
  const SKU = api.skuId({kode:KODE, produk:"PRODUK FG"});

  const fresh = api.fgGetPacked(FTGL, FAC, SKU, TOKO_FG);
  check("FG01.newRowDefaultsBelumDicek", "FG01 — baris baru default status \"belum\" (Belum Dicek), bukan otomatis Sesuai/Tidak Sesuai",
    "belum", fresh.status);
  check("FG02.zeroQtyNotAutoTidakSesuai", "FG02 — qty 0 pada baris Belum Dicek TIDAK otomatis dianggap Tidak Sesuai",
    JSON.stringify({qty:0, status:"belum"}), JSON.stringify({qty:fresh.qty, status:fresh.status}));

  // fgRenderTable() perlu divisi FG di DOM (fgFactoryForDivisi hanya mengenali
  // SPECIAL_FG/SPECIAL_FG_CIBADAK) supaya bisa menentukan factory-nya.
  api.$("pd-tgl").value = FTGL; api.$("pd-divisi").value = api.SPECIAL_FG;
  api.fgRenderTable();
  const trFg = d.querySelector(`#fgBody tr[data-kode="${KODE}"][data-toko="${TOKO_FG}"]`);
  check("FG.rowRendersNeutralNotError", "UI — segmen \"Belum Dicek\" tampil neutral (class neutral), bukan warna error",
    true, !!trFg && trFg.querySelector(".segb.neutral")!=null);

  const [btnBelum, btnSesuai, btnTidak] = trFg.querySelectorAll(".segb");
  check("FG.belumButtonInitiallyOn", "UI — tombol \"Belum Dicek\" aktif (on) di awal, bukan \"Tidak Sesuai\"",
    JSON.stringify({belum:true, tidak:false}), JSON.stringify({belum:btnBelum.classList.contains("on"), tidak:btnTidak.classList.contains("on")}));

  api.fgSetStatus(btnSesuai, "sesuai");
  const afterSesuai = api.fgGetPacked(FTGL, FAC, SKU, TOKO_FG);
  check("FG03.clickSesuaiPersists", "FG03 — klik Sesuai tersimpan (status sesuai, qty = target)",
    JSON.stringify({status:"sesuai", qty:20}), JSON.stringify({status:afterSesuai.status, qty:afterSesuai.qty}));

  api.fgSetStatus(btnTidak, "tidak_sesuai");
  const afterTidak = api.fgGetPacked(FTGL, FAC, SKU, TOKO_FG);
  check("FG04.clickTidakSesuaiPersists", "FG04 — klik Tidak Sesuai tersimpan",
    "tidak_sesuai", afterTidak.status);

  // kembalikan ke "belum" utk lanjut ke skenario FG05/FG06 (submit blocking)
  api.fgSetStatus(btnBelum, "belum");
  let simpanThrew = false;
  try { api.fgSimpanProgres(); } catch(e){ simpanThrew = true; }
  check("FG05.simpanProgresAllowedWithBelumDicek", "FG05 — \"Simpan Progres\" tetap boleh walau masih ada baris Belum Dicek",
    JSON.stringify({threw:false, stillBelum:true}),
    JSON.stringify({threw:simpanThrew, stillBelum: api.fgGetPacked(FTGL,FAC,SKU,TOKO_FG).status==="belum"}));

  // FG06a: confirm() ditolak (operator memilih TIDAK lanjut) -> blocked, readyAt tetap null
  const apiBlock = loadApp({confirmAnswer:false});
  let confirmMsg = "";
  apiBlock.__window.confirm = (msg)=>{ confirmMsg = msg||""; return false; };
  setupFgForReadyTest(apiBlock, FTGL, FAC, DIVISI, KODE, TOKO_FG, apiBlock.SPECIAL_FG);
  apiBlock.$("pd-tgl").value = FTGL; apiBlock.$("pd-divisi").value = apiBlock.SPECIAL_FG;
  apiBlock.fgTandaiSiap();
  const recBlocked = apiBlock.D.fgPacking[apiBlock.fgKey(FTGL,FAC)];
  check("FG06.blockedWhenBelumDicekAndOperatorDeclines", "FG06 — \"Tandai Siap Diambil Delivery\" TIDAK menandai ready kalau msh Belum Dicek & operator pilih Batal",
    null, recBlocked ? recBlocked.readyAt : null);
  check("FG06.confirmMessageMentionsBelumDicek", "FG06 — pesan konfirmasi menyebutkan jumlah baris \"Belum Dicek\"",
    true, confirmMsg.toLowerCase().includes("belum dicek"));

  // FG06b: confirm() diterima -> tetap boleh lanjut (soft-gate, bukan hard block)
  const apiAllow = loadApp({confirmAnswer:true});
  setupFgForReadyTest(apiAllow, FTGL, FAC, DIVISI, KODE, TOKO_FG, apiAllow.SPECIAL_FG);
  apiAllow.$("pd-tgl").value = FTGL; apiAllow.$("pd-divisi").value = apiAllow.SPECIAL_FG;
  apiAllow.fgTandaiSiap();
  const recAllowed = apiAllow.D.fgPacking[apiAllow.fgKey(FTGL,FAC)];
  check("FG06.softGateAllowsWhenOperatorConfirms", "FG06 — kalau operator KLIK OK di konfirmasi, tetap bisa ditandai siap (soft-gate, bukan hard block permanen)",
    true, !!(recAllowed && recAllowed.readyAt));

  function setupFgForReadyTest(a, tgl, fac, divisi, kode, toko, fgDivisi){
    const gab = a.poMergeDenganExisting(tgl, fac, [{factory:fac, kategori:"BASIC", kode, produk:"PRODUK FG",
      produkAsli:"PRODUK FG", divisi, poAwal:20, poRevisi:0, pb:0, stores:[{toko, poAwal:20, poRevisi:0}]}]);
    a.D.po[tgl] = gab.rows; a.saveD();
    a.D.ceklis[a.ceklisKey(tgl, divisi)] = {submitted:true, submittedAt:"now", closed:false,
      rows:[{kode, produk:"PRODUK FG", kategori:"BASIC", target:20, status:"sesuai", aktual:20, reject:0, keterangan:""}]};
    a.saveD();
    a.fgMaterializeAll(tgl, fac); // baris tetap "belum" (belum pernah disentuh operator)
  }

  /* ===================== INV01-04: compact invoice row ===================== */
  api.$("inv-dari").value = ""; api.$("inv-sampai").value = ""; api.$("inv-fToko").value = "";
  api.renderInvoiceList();
  const invRow = [...d.querySelectorAll("#inv-list tr")].find(tr=>tr.textContent.includes("INV-AUDIT-1"));
  const actionCell = invRow ? invRow.querySelector(".inv-row-actions") : null;
  const primaryBtns = actionCell ? [...actionCell.children].filter(c=>c.tagName==="BUTTON") : [];
  const menu = actionCell ? actionCell.querySelector("details.row-menu") : null;

  check("INV01.rowIsCompactSingleLineStructure", "INV01 — 1 baris invoice = struktur ringkas (1 tombol utama + 1 menu, bukan 4 tombol vertikal)",
    JSON.stringify({primaryCount:1, hasMenu:true}),
    JSON.stringify({primaryCount:primaryBtns.length, hasMenu:!!menu}));

  check("INV02.onlyOnePrimaryActionVisible", "INV02 — hanya 1 aksi utama (\"Buka\") yg terlihat langsung, sisanya di menu tertutup",
    JSON.stringify({label:"Buka", menuClosed:true}),
    JSON.stringify({label: primaryBtns[0] ? primaryBtns[0].textContent.trim() : null, menuClosed: menu ? !menu.open : false}));

  const menuLabels = menu ? [...menu.querySelectorAll(".row-menu-panel button")].map(b=>b.textContent.trim()) : [];
  check("INV03.overflowMenuHasAllFourActions", "INV03 — menu \"...\" berisi Cetak Invoice/Buka DO/Edit/Bayar",
    true, ["Cetak Invoice","Buka DO","Edit","Bayar"].every(lbl=>menuLabels.includes(lbl)));

  check("INV04.canonicalBakeryStillCorrectInCompactRow", "INV04 — bakery kanonik tetap benar di baris ringkas baru",
    "BAKERY BANTAR GEBANG", invRow ? invRow.cells[2].textContent.trim() : null);

  // Filter by canonical name harus tetap mencocokkan invoice lama yg .toko-nya alias mentah
  api.$("inv-fToko").value = "BAKERY BANTAR GEBANG";
  const filtered = api.invBarisData();
  check("INV.filterByCanonicalMatchesRawAliasData", "G — filter dropdown \"BAKERY BANTAR GEBANG\" tetap mencocokkan invoice yg .toko-nya masih \"ABGN\" mentah",
    true, filtered.some(x=>x.inv.batch==="AUDIT-KIRIM-1"));
  api.$("inv-fToko").value = "";

  /* ===================== Guard: business logic & data safety untouched ===================== */
  check("GUARD.hitungTargetUnaffected", "Guard — hitungTarget tetap murni PO Awal+Revisi (target FG row tetap 20)",
    20, api.hitungTarget({poAwal:20, poRevisi:0}));
  check("GUARD.oldPlainNumberPackingStillReadsAsSesuai", "Guard — data packing LAMA (angka polos, pra-status) tetap terbaca \"sesuai\" (backward compatible)",
    JSON.stringify({qty:15, status:"sesuai"}),
    JSON.stringify((()=>{
      api.D.fgPacking[api.fgKey(FTGL,FAC)].packed["OLD-KODE|TOKO LAMA"] = 15; // format lama: angka polos
      const r = api.fgGetPacked(FTGL, FAC, "OLD-KODE", "TOKO LAMA");
      return {qty:r.qty, status:r.status};
    })()));
  check("GUARD.canonicalStoreResolverUnchangedForKnownName", "Guard — canonicalStoreName utk nama yg tidak dikenal apapun dikembalikan apa adanya (tidak mengarang nama)",
    "TOKO BENAR-BENAR BARU", api.canonicalStoreName("TOKO BENAR-BENAR BARU"));

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== STORE AUDIT + FG STATUS + COMPACT INVOICE TEST TABLE ===\n");
  console.log("ID".padEnd(46), "Scenario".padEnd(92), "Expected".padEnd(24), "Actual".padEnd(24), "Result");
  results.forEach(r=>{
    console.log(r.id.padEnd(46), r.scenario.slice(0,92).padEnd(92), JSON.stringify(r.expected).slice(0,24).padEnd(24), JSON.stringify(r.actual).slice(0,24).padEnd(24), r.pass?"PASS":"FAIL");
  });
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, r.scenario, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main().catch(e=>{ console.error("SUITE CRASHED", e); process.exit(2); });
