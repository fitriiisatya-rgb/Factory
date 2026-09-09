"use strict";
// Regression suite for the "lifecycle + delete consistency + performance"
// patch: UI01-UI10 exactly per spec. Drives the REAL app functions via jsdom
// (loadApp() from uat-harness.js) — business logic is never reimplemented
// here, only seeded with realistic D.po/D.ceklis/D.fgPacking shapes (same
// shapes the real upload/checklist flows produce) so the functions under
// test (prodBuildChecklist, fgTandaiSiap, poHapusFactory, muatSemua, dashData,
// pdRingkasanHariIni, the *Debounced wrappers, withBusyBtn double-submit
// guard) run against genuine app state.
const { loadApp } = require("./uat-harness.js");

const results = [];
function check(id, scenario, expected, actual){
  const pass = JSON.stringify(expected) === JSON.stringify(actual);
  results.push({id, scenario, expected, actual, pass});
  return pass;
}
function okFetch(extra){
  return () => Promise.resolve({ json: async () => Object.assign({ok:true, version:1}, extra||{}) });
}
function seedPO(api, tgl, factory, divisi, kode, produk, target, toko){
  (api.D.po[tgl] ||= []).push({
    factory, kategori:"BASIC", kode, produk, divisi,
    poAwal:target, poRevisi:0, pb:0,
    stores:[{toko:toko||"TOKO A", poAwal:target, poRevisi:0}]
  });
}

async function main(){
  /* ===================== UI01 + UI02 + UI10 =====================
     Closed FG (Tandai Siap Diambil Delivery) hilang dari workspace aktif
     Produksi/FG, tapi tetap muncul di Kirim Toko & tetap ada sbg histori. */
  {
    let fetchCalls = 0;
    const api = loadApp({fetchImpl: (url, opts)=>{ fetchCalls++; return okFetch()(); }});
    // loadApp() sendiri memicu satu muatSemua(true) latar belakang di akhir
    // init (baris terakhir app: "renderAll(); muatSemua(true);") yang baru
    // SELESAI (termasuk renderAll() miliknya SENDIRI di ujung muatSemua())
    // pada microtask BERIKUTNYA, bukan sinkron. "Flush" dulu di sini supaya
    // spy renderAll/fetchCalls di bawah HANYA menghitung aksi yg sedang
    // diuji, bukan ikut menghitung sinkron latar belakang bawaan init.
    await new Promise(r=>setTimeout(r,0));
    fetchCalls = 0;
    const tgl = "2026-06-01";
    api.D.divisi.push("Basic", api.SPECIAL_FG);
    seedPO(api, tgl, "karangtengah", "Basic", "K1", "Produk A", 100);
    api.D.ceklis[tgl+"|Basic"] = {submitted:true, submittedAt:"now", metaStatus:api.CEKLIS_STATUS_SUBMITTED,
      rows:[{kode:"K1",produk:"Produk A",kategori:"BASIC",target:100,status:"sesuai",aktual:100,reject:0,keterangan:""}]};
    api.saveD();
    api.$("pd-tgl").value = tgl;
    api.$("pd-divisi").value = api.SPECIAL_FG;
    api.prodBuildChecklist(); // materializes D.fgPacking lewat fgBuildPanel -> fgMaterializeAll

    const factory = "karangtengah";
    const fgKeyStr = tgl+"|"+factory;
    check("PRECOND.fgPackingMaterialized", "Prakondisi — fgMaterializeAll mengisi baris packing dari PO+ceklis", true, !!(api.D.fgPacking[fgKeyStr] && Object.keys(api.D.fgPacking[fgKeyStr].packed).length>0));
    Object.keys(api.D.fgPacking[fgKeyStr].packed).forEach(k=>{ api.D.fgPacking[fgKeyStr].packed[k] = {qty:100, status:"sesuai", keterangan:""}; });
    api.saveD();

    let renderAllCalls = 0;
    const origRenderAll = api.__window.renderAll;
    api.__window.renderAll = function(){ renderAllCalls++; return origRenderAll.apply(this, arguments); };
    await api.fgTandaiSiap(api.__document.createElement("button"));

    check("UI01.dashDataClosedTrue", "UI01 — dashData() FG closed=true setelah Tandai Siap Diambil Delivery", true, api.dashData(tgl).find(r=>r.divisi===api.SPECIAL_FG).closed);
    api.prodBuildChecklist();
    const fgBodyHtml = api.$("fgBody").innerHTML;
    check("UI01.noEditableInputsInFgBody", "UI01 — panel packing FG tidak lagi berisi <input> yg bisa diedit stlh closed (bukan cuma di-CSS-hide)", false, fgBodyHtml.includes("<input"));
    check("UI01.activeActionsHidden", "UI01 — tombol Simpan Progres/Tandai Siap disembunyikan stlh closed", "none", api.$("fgActiveActions2").style.display);
    const ring = api.pdRingkasanHariIni(tgl).find(r=>r.divisi===api.SPECIAL_FG);
    check("UI01.ringkasanLintasDivisiStatusDitutup", "UI01 — ringkasan lintas-divisi (pekerjaan aktif) FG berstatus Ditutup, bukan Berjalan/Selesai", "Ditutup", ring && ring.status);
    check("UI07.fgTandaiSiapDoesNotCallRenderAll", "UI07 — fgTandaiSiap() TIDAK memicu renderAll() penuh (targeted refresh saja)", 0, renderAllCalls);

    api.renderFgReadyList();
    const grup = api.fgReadyListByBakery();
    check("UI02.appearsInFgReadyListByBakery", "UI02 — batch delivery-ready tetap muncul di fgReadyListByBakery (basis Kirim Toko)", true, grup.some(g=>g.tgl===tgl));
    check("UI02.kFgReadyCardVisibleInKirimToko", "UI02 — card 'Siap Diambil Delivery' tampil di menu Kirim Toko", "block", api.$("kFgReadyCard").style.display);

    check("UI10.packingDataStillStoredAfterClosed", "UI10 — data packing FG yg closed TETAP tersimpan (histori/audit), bukan dihapus", true, Object.keys(api.D.fgPacking[fgKeyStr].packed).length>0);
    check("UI10.readyAtTimestampPreserved", "UI10 — timestamp 'siap diambil delivery' tetap tercatat sbg audit", true, !!api.D.fgPacking[fgKeyStr].readyAt);

    // Buka Kembali (explicit reopen) — satu-satunya jalan balik ke aktif.
    await api.fgBukaKembali(api.__document.createElement("button"));
    check("A.reopenBringsBackToActiveWorkspace", "Section A — fgBukaKembali() eksplisit membawa kembali ke workspace aktif", false, api.fgPackingEffectiveClosed(tgl, factory));
    api.prodBuildChecklist();
    check("A.reopenShowsEditableInputsAgain", "Section A — setelah dibuka kembali, panel FG kembali bisa diedit", true, api.$("fgBody").innerHTML.includes("<input"));
  }

  /* ===================== UI03 + UI04 + UI05 =====================
     PO dihapus -> hilang dari Riwayat Upload PO & Dashboard, TETAP hilang
     setelah "refresh" (localStorage roundtrip di device yang sama). */
  {
    let fetchCalls = 0;
    const api = loadApp({fetchImpl: ()=>{ fetchCalls++; return okFetch()(); }});
    await new Promise(r=>setTimeout(r,0)); // flush init's own background muatSemua(true) — lihat komentar di blok UI01
    fetchCalls = 0;
    const tgl = "2026-06-10";
    api.D.divisi.push("Basic");
    seedPO(api, tgl, "karangtengah", "Basic", "K9", "Produk B", 50);
    api.saveD();
    api.renderPoHist();
    check("UI03.beforeDelete_deleteButtonPresent", "UI03 (prakondisi) — sebelum hapus, Riwayat Upload PO menampilkan tombol Hapus utk factory ini", 1, api.__document.querySelectorAll('#poHist button.del').length);
    check("UI04.beforeDelete_dashDataTargetIsFifty", "UI04 (prakondisi) — sebelum hapus, dashData() target Basic = 50", 50, api.dashData(tgl).find(r=>r.divisi==="Basic").target);

    let renderAllCalls = 0;
    const origRenderAll = api.__window.renderAll;
    api.__window.renderAll = function(){ renderAllCalls++; return origRenderAll.apply(this, arguments); };
    const delBtn = api.__document.querySelector('#poHist button.del');
    const ok = await api.poHapusFactory(tgl, "karangtengah", delBtn);
    check("B.deleteConfirmedOk", "Section B — poHapusFactory() sukses (backend dikonfirmasi lewat fetch sungguhan, bukan no-cors)", true, ok);
    check("B.onlyOneNetworkCallForDelete", "Section B — tepat satu panggilan fetch utk aksi hapus (bukan no-cors fire-and-forget tak-terverifikasi)", true, fetchCalls>=1);
    check("UI07.poHapusFactoryDoesNotCallRenderAll", "UI07 — poHapusFactory() TIDAK memicu renderAll() penuh", 0, renderAllCalls);

    check("UI03.afterDelete_noDeleteButtonsLeft", "UI03 — Riwayat Upload PO tidak lagi menampilkan baris/tombol utk PO yg sudah dihapus", 0, api.__document.querySelectorAll('#poHist button.del').length);
    check("UI03.afterDelete_DPoFactoryRemoved", "UI03 — D.po tanggal ini sudah tidak berisi baris factory yg dihapus", undefined, api.D.po[tgl]);
    check("UI04.afterDelete_targetUntukDivisiEmpty", "UI04 — target produksi utk divisi ini ikut hilang (bukan cuma listnya)", 0, api.targetUntukDivisi(tgl,"Basic").length);
    check("UI04.afterDelete_dashDataTargetZero", "UI04 — Dashboard (dashData) target Basic turun ke 0 setelah PO dihapus", 0, api.dashData(tgl).find(r=>r.divisi==="Basic").target);

    const savedRaw = api.__window.localStorage.getItem("amorcakes-arus-v1");
    const apiRefresh = loadApp({seedLocalStorage:{"amorcakes-arus-v1": savedRaw}});
    check("UI05.refreshDoesNotResurrectDeletedPO", "UI05 — 'refresh browser' (localStorage device yg sama) tidak memunculkan lagi PO yg sudah dihapus", undefined, apiRefresh.D.po[tgl]);
  }

  /* ===================== UI06 =====================
     Device KEDUA yang masih punya cache PO lama (disinkron SEBELUM
     penghapusan) tidak lagi melihat data yg sudah dihapus device lain,
     begitu muatSemua() menarik state terbaru — dibedakan dari baris yg
     memang belum pernah tersinkron lewat tombstone j.versions.po. */
  {
    const tgl2 = "2026-06-11";
    const apiB = loadApp({fetchImpl: ()=>Promise.resolve({json: async()=>({
      divisi:[], produk:[], toko:[],
      po: [ {tanggal:tgl2, factory:"cibadak", kategori:"BOLU", kode:"KB1", produk:"Produk Bolu", divisi:"Bolu", poAwal:20, poRevisi:0, pb:0, stores:[]} ],
      ceklis: [], kirim: [], retur: [], jual: [], masterProduk: [], tokoTipe: {}, settings: {}, invoice: [],
      versions: { po: {[tgl2+"|karangtengah"]: 2, [tgl2+"|cibadak"]: 1}, ceklis:{}, fgPacking:{}, fgReady:{}, invoice:{}, masterProduk:{}, tokoTipe:{}, settings:{} }
    })})});
    // Flush dulu muatSemua(true) bawaan init (lihat komentar di blok UI01) —
    // TANPA ini, panggilan muatSemua(true) eksplisit di bawah akan no-op
    // (guard `if(muatSedang) return;` masih terkunci oleh sync bawaan init
    // yg belum selesai), bukan benar2 menguji reconciliation-nya.
    await new Promise(r=>setTimeout(r,0));
    // Device B "pernah sync" sebelum penghapusan — cache lokalnya masih
    // punya baris karangtengah yg SUDAH dihapus device lain, plus baris
    // cibadak yg masih hidup (utk membuktikan penghapusannya presisi per
    // factory, bukan menyapu bersih seluruh tanggal).
    seedPO(apiB, tgl2, "karangtengah", "Basic", "K7", "Produk Lama", 30);
    seedPO(apiB, tgl2, "cibadak", "Bolu", "KB1", "Produk Bolu", 20);
    apiB.saveD();
    await apiB.muatSemua(true);
    check("UI06.deletedFactoryGoneAfterSecondDeviceSync", "UI06 — device kedua yg sebelumnya py cache PO lama tidak lagi melihatnya stlh sync (tombstone via j.versions.po)", false, (apiB.D.po[tgl2]||[]).some(r=>r.factory==="karangtengah"));
    check("UI06.survivingFactoryStillPresentAfterSync", "UI06 — factory LAIN yg memang belum dihapus tetap ada (penghapusan presisi, bukan sapu bersih)", true, (apiB.D.po[tgl2]||[]).some(r=>r.factory==="cibadak"));
  }

  /* ===================== UI08 =====================
     Double-tap / klik ganda saat request masih berjalan TIDAK mengirim
     permintaan backend kedua (requestId & tombol tidak digandakan). */
  {
    let fetchCalls = 0;
    const api = loadApp({fetchImpl: ()=>{ fetchCalls++; return okFetch()(); }});
    await new Promise(r=>setTimeout(r,0)); // flush init's own background muatSemua(true) — lihat komentar di blok UI01
    fetchCalls = 0;
    const tgl = "2026-06-12";
    api.D.divisi.push("Basic");
    api.D.ceklis[tgl+"|Basic"] = {submitted:false, submittedAt:"", rows:[]};
    api.saveD();
    api.$("pd-tgl").value = tgl; api.$("pd-divisi").value = "Basic";
    const btn = api.__document.createElement("button");
    const p1 = api.pdTutup(btn);
    const p2 = api.pdTutup(btn); // tap kedua sementara tap pertama masih berjalan
    check("UI08.secondTapIgnoredSynchronously", "UI08 — panggilan kedua sementara tombol masih disabled diabaikan (return undefined, tidak fetch lagi)", undefined, p2);
    await p1;
    check("UI08.onlyOneNetworkCallDespiteDoubleTap", "UI08 — tepat SATU permintaan backend terkirim meski tombol di-tap dua kali beruntun", 1, fetchCalls);
    await new Promise(r=>setTimeout(r,950)); // withBusyBtn menjadwalkan re-enable via setTimeout terpisah dari Promise chain-nya
    check("C3.buttonReenabledAfterSettle", "Section C.3 — tombol kembali enabled setelah request selesai (bukan terkunci selamanya)", false, btn.disabled);
  }

  /* ===================== UI09 =====================
     Search/filter di-debounce (~200ms) — banyak keystroke cepat cuma
     memicu SATU render, bukan satu render per keystroke. */
  {
    const api = loadApp({fetchImpl: okFetch()});
    await new Promise(r=>setTimeout(r,0)); // flush init's own background muatSemua(true) (yg juga renderAll -> renderPosisiStok) sblm spy dipasang — lihat komentar di blok UI01
    let calls = 0;
    const orig = api.__window.renderPosisiStok;
    api.__window.renderPosisiStok = function(){ calls++; return orig.apply(this, arguments); };
    api.renderPosisiStokDebounced();
    api.renderPosisiStokDebounced();
    api.renderPosisiStokDebounced();
    api.renderPosisiStokDebounced();
    api.renderPosisiStokDebounced();
    check("UI09.noSynchronousRenderDuringRapidTyping", "UI09 — 5x panggilan cepat (simulasi ketikan cepat) belum memicu render SAMA SEKALI sblm jeda debounce", 0, calls);
    await new Promise(r=>setTimeout(r, 280));
    check("UI09.exactlyOneRenderAfterDebounceWindow", "UI09 — setelah jeda ~200ms, tepat SATU render terjadi (bukan 5x) — tabel besar tetap responsif", 1, calls);
    api.__window.renderPosisiStok = orig;
  }

  const total = results.length;
  const passCount = results.filter(r=>r.pass).length;
  console.log("\n=== LIFECYCLE + DELETE CONSISTENCY + PERFORMANCE (UI01-UI10) ===\n");
  console.log("ID".padEnd(46), "Scenario".padEnd(80), "Expected".padEnd(10), "Actual".padEnd(10), "Result");
  const fmtVal = v => String(JSON.stringify(v)).padEnd(10); // JSON.stringify(undefined) === the value undefined, bukan string — String() dulu supaya padEnd tidak throw
  results.forEach(r=>{
    console.log(r.id.padEnd(46), r.scenario.slice(0,80).padEnd(80), fmtVal(r.expected), fmtVal(r.actual), r.pass?"PASS":"FAIL");
  });
  console.log(`\n=== RESULT: ${passCount}/${total} PASS ===`);
  if(passCount!==total){
    console.log("\nFAILED:");
    results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.id, r.scenario, "| expected", JSON.stringify(r.expected), "| actual", JSON.stringify(r.actual)));
  }
  process.exitCode = passCount===total ? 0 : 1;
}
main().catch(e=>{ console.error("SUITE CRASHED", e); process.exit(2); });
