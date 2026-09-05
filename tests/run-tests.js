"use strict";
const { loadApp } = require("./harness.js");
const results = [];
function check(name, expected, actual, note){
  const pass = JSON.stringify(expected) === JSON.stringify(actual);
  results.push({name, expected, actual, pass, note});
}


/* ===================== GROUP A — PO Revision snapshot logic ===================== */
(function(){
  const api = loadApp();
  const { D, hitungTarget, poMergeDenganExisting, saveD } = api;
  const TGL = "2026-01-10", FAC = "karangtengah";

  // ---- TEST 1: Initial PO ----
  const initialRow = {factory:FAC, kategori:"BASIC", kode:"P001", produk:"PRODUK A", produkAsli:"PRODUK A",
    divisi:"Basic", poAwal:100, poRevisi:0, pb:0, stores:[{toko:"TOKO A",poAwal:100,poRevisi:0}]};
  let gab = poMergeDenganExisting(TGL, FAC, [Object.assign({},initialRow)]);
  check("TEST1 Initial PO — mode", "INITIAL", gab.mode);
  check("TEST1 Initial PO — target", 100, hitungTarget(gab.rows[0]));
  D.po[TGL] = gab.rows; saveD();

  // ---- TEST 2: Revision 20 (existing PO Awal 100) ----
  const rev1 = {factory:FAC, kategori:"BASIC", kode:"P001", produk:"PRODUK A", produkAsli:"PRODUK A",
    divisi:"Basic", poAwal:100, poRevisi:20, pb:0, stores:[{toko:"TOKO A",poAwal:100,poRevisi:20}]};
  gab = poMergeDenganExisting(TGL, FAC, [Object.assign({},rev1)]);
  check("TEST2 Revision 20 — mode", "REVISION", gab.mode);
  check("TEST2 Revision 20 — poAwal existing tetap", 100, gab.rows[0].poAwal);
  check("TEST2 Revision 20 — poRevisi", 20, gab.rows[0].poRevisi);
  check("TEST2 Revision 20 — target", 120, hitungTarget(gab.rows[0]));
  D.po[TGL] = gab.rows; saveD();

  // ---- TEST 3: Same revision uploaded twice ----
  gab = poMergeDenganExisting(TGL, FAC, [Object.assign({},rev1)]);
  check("TEST3 Same revision x2 — target tetap 120 (bukan 140)", 120, hitungTarget(gab.rows[0]));
  D.po[TGL] = gab.rows; saveD();

  // ---- TEST 4: Revision 20 -> 35 ----
  const rev2 = {factory:FAC, kategori:"BASIC", kode:"P001", produk:"PRODUK A", produkAsli:"PRODUK A",
    divisi:"Basic", poAwal:100, poRevisi:35, pb:0, stores:[{toko:"TOKO A",poAwal:100,poRevisi:35}]};
  gab = poMergeDenganExisting(TGL, FAC, [Object.assign({},rev2)]);
  check("TEST4 Revision 20->35 — target 135", 135, hitungTarget(gab.rows[0]));
  D.po[TGL] = gab.rows; saveD();

  // ---- TEST 8 (uses same product): Revision turun setelah over-produced ----
  // (handled together with production in Group B, product P001 continues there)

  // ---- TEST 9: New product appears in revision ----
  const rowExistingB = null; // no prior row for product B
  gab = poMergeDenganExisting(TGL, FAC, [
    Object.assign({},rev2), // P001 unchanged again (idempotent)
    {factory:FAC, kategori:"BASIC", kode:"P002", produk:"PRODUK B", produkAsli:"PRODUK B",
     divisi:"Basic", poAwal:0, poRevisi:12, pb:0, stores:[{toko:"TOKO B",poAwal:0,poRevisi:12}]}
  ]);
  const rowB = gab.rows.find(r=>r.kode==="P002");
  check("TEST9 New product in revision — poAwal", 0, rowB.poAwal);
  check("TEST9 New product in revision — poRevisi", 12, rowB.poRevisi);
  check("TEST9 New product in revision — target", 12, hitungTarget(rowB));
  D.po[TGL] = gab.rows; saveD();

  // ---- TEST 10: Store-level revision (independent scenario, product C) ----
  let gabC = poMergeDenganExisting(TGL, FAC, [
    {factory:FAC, kategori:"BASIC", kode:"P003", produk:"PRODUK C", produkAsli:"PRODUK C",
     divisi:"Basic", poAwal:10, poRevisi:0, pb:0, stores:[{toko:"TOKO A",poAwal:10,poRevisi:0}]}
  ]);
  D.po["2026-01-11"] = gabC.rows; saveD();
  gabC = poMergeDenganExisting("2026-01-11", FAC, [
    {factory:FAC, kategori:"BASIC", kode:"P003", produk:"PRODUK C", produkAsli:"PRODUK C",
     divisi:"Basic", poAwal:10, poRevisi:5, pb:0, stores:[{toko:"TOKO A",poAwal:10,poRevisi:5}]}
  ]);
  D.po["2026-01-11"] = gabC.rows; saveD();
  gabC = poMergeDenganExisting("2026-01-11", FAC, [
    {factory:FAC, kategori:"BASIC", kode:"P003", produk:"PRODUK C", produkAsli:"PRODUK C",
     divisi:"Basic", poAwal:10, poRevisi:8, pb:0, stores:[{toko:"TOKO A",poAwal:10,poRevisi:8}]}
  ]);
  const storeC = gabC.rows[0].stores[0];
  check("TEST10 Store revision — target toko (18, bukan 23)", 18, hitungTarget(storeC));

  // ---- TEST 14: PO Awal edited in later file must be locked + warning ----
  let gabD = poMergeDenganExisting(TGL, FAC, [
    {factory:FAC, kategori:"BASIC", kode:"P004", produk:"PRODUK D", produkAsli:"PRODUK D",
     divisi:"Basic", poAwal:100, poRevisi:0, pb:0, stores:[]}
  ]);
  D.po[TGL] = D.po[TGL].concat(gabD.rows); saveD();
  gabD = poMergeDenganExisting(TGL, FAC, [
    {factory:FAC, kategori:"BASIC", kode:"P004", produk:"PRODUK D", produkAsli:"PRODUK D",
     divisi:"Basic", poAwal:95, poRevisi:0, pb:0, stores:[]} // spreadsheet edited by accident
  ]);
  const rowD = gabD.rows.find(r=>r.kode==="P004");
  check("TEST14 PO Awal edited in file — existing kept (100, not 95)", 100, rowD.poAwal);
  check("TEST14 PO Awal edited — warning catatan present", true, (rowD.catatan||[]).some(c=>c.tipe==="awalBerbeda"));

  // ---- TEST 16 covered in Group C (resolusi/unresolved guard) ----

  // ---- TEST 17: PB never affects target ----
  const rowPB = {factory:FAC, kategori:"BASIC", kode:"P005", produk:"PRODUK E", produkAsli:"PRODUK E",
    divisi:"Basic", poAwal:50, poRevisi:0, pb:999999, stores:[]};
  check("TEST17 PB ignored — target only from poAwal+poRevisi", 50, hitungTarget(rowPB));

  // ---- TEST 13/33 support: unrelated product not mentioned in later upload must survive ----
  gab = poMergeDenganExisting(TGL, FAC, [Object.assign({},rev2)]); // upload that omits P002..P005 entirely
  const stillHasB = gab.rows.some(r=>r.kode==="P002");
  const stillHasD = gab.rows.some(r=>r.kode==="P004");
  check("Data-safety: product omitted from later upload is preserved (P002)", true, stillHasB);
  check("Data-safety: product omitted from later upload is preserved (P004)", true, stillHasD);

  module.exports = module.exports || {};
  module.exports.groupA = results.slice();
})();

/* ===================== GROUP B — Production cumulative vs revision target ===================== */
(function(){
  const before = results.length;
  const api = loadApp();
  const { D, hitungTarget, poMergeDenganExisting, pdSisaTarget, ceklisKey, saveD, num } = api;
  const TGL = "2026-02-01", FAC = "karangtengah", DIVISI = "Basic", KODE = "P900";

  function setPO(poAwal, poRevisi){
    const parsed = [{factory:FAC, kategori:"BASIC", kode:KODE, produk:"PRODUK X", produkAsli:"PRODUK X",
      divisi:DIVISI, poAwal, poRevisi, pb:0, stores:[]}];
    const gab = poMergeDenganExisting(TGL, FAC, parsed);
    D.po[TGL] = gab.rows; saveD();
    return gab;
  }
  function submitAktual(aktual, reject){
    const key = ceklisKey(TGL, DIVISI);
    if(!D.ceklis[key]) D.ceklis[key] = {submitted:false, submittedAt:null, rows:[], closed:false};
    const rec = D.ceklis[key];
    const map = {}; (rec.rows||[]).forEach(r=>map[r.kode]=r);
    const prev = map[KODE];
    if(prev){ prev.aktual = num(prev.aktual)+aktual; prev.reject = num(prev.reject)+(reject||0); }
    else map[KODE] = {kode:KODE, produk:"PRODUK X", kategori:"BASIC", target:0, status:"sesuai", aktual, reject:reject||0, keterangan:""};
    rec.rows = Object.values(map);
    rec.submitted = true; rec.submittedAt = "now";
    saveD();
  }

  // TEST 5: Production already complete (100), then revision +20 -> sisa 20
  setPO(100,0);
  submitAktual(100,0);
  setPO(100,20);
  let sisa = pdSisaTarget(TGL,DIVISI).find(t=>t.kode===KODE);
  check("TEST5 Production complete then +20 revision — sisa", 20, sisa.sisa);

  // TEST 6: Partial production (70 of 100), then revision +20 -> target120, sisa 50
  // reset scenario in a fresh date so TEST5 cumulative doesn't interfere
  (function(){
    const TGL2 = "2026-02-02";
    function setPO2(poAwal, poRevisi){
      const gab = poMergeDenganExisting(TGL2, FAC, [{factory:FAC, kategori:"BASIC", kode:KODE, produk:"PRODUK X",
        produkAsli:"PRODUK X", divisi:DIVISI, poAwal, poRevisi, pb:0, stores:[]}]);
      D.po[TGL2] = gab.rows; saveD();
    }
    function submit2(aktual){
      const key = ceklisKey(TGL2, DIVISI);
      if(!D.ceklis[key]) D.ceklis[key] = {submitted:false, submittedAt:null, rows:[], closed:false};
      const rec = D.ceklis[key];
      const map = {}; (rec.rows||[]).forEach(r=>map[r.kode]=r);
      const prev = map[KODE];
      if(prev) prev.aktual = num(prev.aktual)+aktual;
      else map[KODE] = {kode:KODE, produk:"PRODUK X", kategori:"BASIC", target:0, status:"sesuai", aktual, reject:0, keterangan:""};
      rec.rows = Object.values(map); rec.submitted = true; rec.submittedAt="now"; saveD();
    }
    setPO2(100,0);
    submit2(70);
    setPO2(100,20);
    const s = pdSisaTarget(TGL2,DIVISI).find(t=>t.kode===KODE);
    check("TEST6 Partial production (70/100) + revision +20 — sisa 50", 50, s.sisa);
  })();

  // TEST 7: Production after revision, then revision changes again (multi-revision)
  (function(){
    const TGL3 = "2026-02-03";
    function setPO3(poAwal, poRevisi){
      const gab = poMergeDenganExisting(TGL3, FAC, [{factory:FAC, kategori:"BASIC", kode:KODE, produk:"PRODUK X",
        produkAsli:"PRODUK X", divisi:DIVISI, poAwal, poRevisi, pb:0, stores:[]}]);
      D.po[TGL3] = gab.rows; saveD();
    }
    function submit3(aktual){
      const key = ceklisKey(TGL3, DIVISI);
      if(!D.ceklis[key]) D.ceklis[key] = {submitted:false, submittedAt:null, rows:[], closed:false};
      const rec = D.ceklis[key];
      const map = {}; (rec.rows||[]).forEach(r=>map[r.kode]=r);
      const prev = map[KODE];
      if(prev) prev.aktual = num(prev.aktual)+aktual;
      else map[KODE] = {kode:KODE, produk:"PRODUK X", kategori:"BASIC", target:0, status:"sesuai", aktual, reject:0, keterangan:""};
      rec.rows = Object.values(map); rec.submitted = true; rec.submittedAt="now"; saveD();
    }
    setPO3(100,0);      // target 100
    submit3(100);       // aktual 100
    setPO3(100,20);     // target 120
    submit3(20);        // aktual cumulative 120
    setPO3(100,35);     // target 135
    const target = D.po[TGL3].find(r=>r.kode===KODE);
    check("TEST7 Multi-revision — target 135", 135, api.hitungTarget(target));
    const s = pdSisaTarget(TGL3,DIVISI).find(t=>t.kode===KODE);
    check("TEST7 Multi-revision — sisa 15", 15, s.sisa);

    // TEST 8: revision turun (135 -> 115) with aktual already 120
    setPO3(100,15); // target 100+15=115
    const s2 = pdSisaTarget(TGL3,DIVISI).find(t=>t.kode===KODE);
    check("TEST8 Revision turun — sisa clamped to 0 (not negative)", 0, s2.sisa);
    check("TEST8 Revision turun — overTarget warning = 5", 5, s2.overTarget);
  })();

  module.exports.groupB = results.slice(before);
})();

/* ===================== GROUP C — Product matching / unresolved guard / packing / FG ===================== */
(function(){
  const before = results.length;
  const api = loadApp();
  const { D, poHitungUnresolved, resolusiProdukPO, cocokProduk, normalizeProductKey,
          poMergeDenganExisting, hitungTarget, fgMaterializeAll, fgStoreRows, fgGetPacked,
          ceklisKey, saveD } = api;

  // Case-insensitive / whitespace-normalized exact match
  D.products.push("BOLU PISANG ORIGINAL BESAR");
  const m1 = cocokProduk("Bolu Pisang Original Besar");
  check("Product match case-insensitive", "match", m1.status);
  const m2 = cocokProduk("bolu   pisang  original besar");
  check("Product match multi-space normalized", "match", m2.status);

  // Fuzzy suggestion (typo) must NOT auto-map
  const m3 = cocokProduk("Bolu Pisang Orignal Besar");
  check("Product typo -> suggest (not auto-mapped)", "suggest", m3.status);

  // Completely unknown -> unmapped
  const m4 = cocokProduk("Produk Yang Sama Sekali Belum Ada XYZ123");
  check("Unknown product -> unmapped", "unmapped", m4.status);

  // TEST 16: unresolved guard blocks save
  const rowsWithUnmapped = [{produkAsli:"Produk Baru Belum Dikenal", produk:"Produk Baru Belum Dikenal",
    kode:"PX1", kategori:"BASIC", divisi:"Basic", poAwal:10, poRevisi:0, stores:[]}];
  resolusiProdukPO(rowsWithUnmapped);
  const unresolved = poHitungUnresolved(rowsWithUnmapped);
  check("TEST16 Unresolved guard — blocks (suggest/unmapped present)", true, unresolved.length>0);

  // ---- TEST 11: Existing packing preserved when target rises ----
  const TGL = "2026-03-01", FAC = "karangtengah", DIVISI = "Basic", KODE="P800";
  function setPO(poAwal, poRevisi, storeAwal, storeRevisi){
    const gab = poMergeDenganExisting(TGL, FAC, [{factory:FAC, kategori:"BASIC", kode:KODE, produk:"PRODUK Y",
      produkAsli:"PRODUK Y", divisi:DIVISI, poAwal, poRevisi, pb:0,
      stores:[{toko:"TOKO A", poAwal:storeAwal, poRevisi:storeRevisi}]}]);
    D.po[TGL] = gab.rows; saveD();
  }
  setPO(10,0,10,0);
  D.ceklis[ceklisKey(TGL,DIVISI)] = {submitted:true, submittedAt:"now", rows:[{kode:KODE,produk:"PRODUK Y",kategori:"BASIC",target:10,status:"sesuai",aktual:10,reject:0,keterangan:""}]};
  saveD();
  fgMaterializeAll(TGL, FAC); // first materialize -> default packed = target (existing daily flow)
  // operator explicitly packs all 10 (simulate real packing action, not just default)
  const k = KODE+"|TOKO A";
  D.fgPacking[api.fgKey(TGL,FAC)].packed[k] = {qty:10, status:"sesuai", keterangan:""};
  saveD();
  setPO(10,5,10,5); // revision: target toko now 15
  fgMaterializeAll(TGL, FAC); // must NOT touch existing packed
  const packedAfter = fgGetPacked(TGL,FAC,KODE,"TOKO A");
  const rowsFg = fgStoreRows(TGL,FAC).find(r=>r.kode===KODE);
  check("TEST11 Existing packing — target baru 15", 15, rowsFg.target);
  check("TEST11 Existing packing — packed tetap 10 (bukan direset)", 10, packedAfter.qty);
  check("TEST11 Existing packing — sisa 5", 5, rowsFg.target-packedAfter.qty);

  // ---- TEST 12: New product/store row appearing in revision -> packed 0 before operator confirms ----
  const gabNew = poMergeDenganExisting(TGL, FAC, [
    {factory:FAC, kategori:"BASIC", kode:KODE, produk:"PRODUK Y", produkAsli:"PRODUK Y", divisi:DIVISI,
     poAwal:10, poRevisi:5, pb:0, stores:[{toko:"TOKO A",poAwal:10,poRevisi:5}]},
    {factory:FAC, kategori:"BASIC", kode:"P801", produk:"PRODUK Z", produkAsli:"PRODUK Z", divisi:DIVISI,
     poAwal:0, poRevisi:12, pb:0, stores:[{toko:"TOKO B",poAwal:0,poRevisi:12}]}
  ]);
  D.po[TGL] = gabNew.rows; saveD();
  D.ceklis[ceklisKey(TGL,DIVISI)].rows.push({kode:"P801",produk:"PRODUK Z",kategori:"BASIC",target:12,status:"sesuai",aktual:12,reject:0,keterangan:""});
  saveD();
  fgMaterializeAll(TGL, FAC); // rec already existed (from TEST11 above) -> new key must default to 0/unconfirmed
  const packedNew = fgGetPacked(TGL,FAC,"P801","TOKO B");
  const rowFgNew = fgStoreRows(TGL,FAC).find(r=>r.kode==="P801");
  check("TEST12 New packing row — target 12", 12, rowFgNew.target);
  check("TEST12 New packing row — packed = 0 before operator confirms", 0, packedNew.qty);
  check("TEST12 New packing row — sisa 12", 12, rowFgNew.target-packedNew.qty);

  // TEST 19: Cibadak parser still works standalone (sanity — real file tested in Group D)
  check("Sanity: DIVISI_BAWAAN / SPECIAL_BOLU constants present", true, !!api.SPECIAL_BOLU);

  module.exports.groupC = results.slice(before);
})();

/* ===================== GROUP D — Parser regression (Karangtengah & Cibadak samples) ===================== */
(function(){
  const before = results.length;
  const api = loadApp();
  const { parsePOAuto, deteksiFactory } = api;

  // Build a minimal but structurally faithful Karangtengah sheet:
  // NO | KATEGORI | KODE | NAMA PRODUK | <stores...AWAL> | TOTAL | <stores...REVISI> | TOTAL | <stores...PB> | TOTAL
  const rowsKT = [];
  rowsKT[0] = ["NO","KATEGORI","KODE","NAMA PRODUK","","","TOTAL","","","TOTAL","","","TOTAL"];
  rowsKT[1] = ["","","","","SDRM","CKLE","","SDRM","CKLE","","SDRM","CKLE",""];
  // product rows: 2 stores, AWAL total 9533, REVISI total 58 (matches spec sample totals)
  rowsKT[2] = [1,"BASIC","K100","PRODUK SAMPLE A", 5000,4533, 9533, 30,28, 58, 0,0,0];
  const gabKT = parsePOAuto(rowsKT);
  const totalAwalKT = gabKT.reduce((a,r)=>a+r.poAwal,0);
  const totalRevisiKT = gabKT.reduce((a,r)=>a+r.poRevisi,0);
  check("TEST18 Karangtengah sample — PO Awal 9533", 9533, totalAwalKT);
  check("TEST18 Karangtengah sample — PO Revisi 58", 58, totalRevisiKT);
  check("TEST18 Karangtengah sample — Target 9591", 9591, totalAwalKT+totalRevisiKT);
  check("Factory auto-detect — Karangtengah", "karangtengah", deteksiFactory(rowsKT));

  // Cibadak/Bolu sheet: _ | Kategori | Nama Produk | Harga Satuan | <stores AWAL> | TOTAL PO | <stores REVISI> | TOTAL PO | PB...
  const rowsCB = [];
  rowsCB[0] = ["","Kategori","Nama Produk","Harga Satuan","","TOTAL PO","","TOTAL PO","TOTAL PO"];
  rowsCB[1] = ["","","","","TOKO1","","TOKO1","",""];
  rowsCB[2] = ["","BOLU","BOLU SAMPLE B",10000, 3844, 3844, 0,0, 0];
  const gabCB = parsePOAuto(rowsCB);
  const totalCB = gabCB.reduce((a,r)=>a+r.poAwal+r.poRevisi,0);
  check("TEST19 Cibadak sample — PO total 3844", 3844, totalCB);
  check("Factory auto-detect — Cibadak", "cibadak", deteksiFactory(rowsCB));

  module.exports.groupD = results.slice(before);
})();

/* ===================== GROUP E — Data safety: DO/Invoice untouched, ceklis fields preserved, localStorage roundtrip ===================== */
(function(){
  const before = results.length;
  const api = loadApp();
  const { D, poMergeDenganExisting, saveD, ceklisKey, uid } = api;
  const TGL = "2026-04-01", FAC = "karangtengah", DIVISI="Basic", KODE="P700";

  // Seed an existing DO (D.kirim) and Invoice, unrelated to the revision flow.
  const doRow = {id:uid(), batch:"BATCH1", tgl:TGL, toko:"TOKO A", produk:"PRODUK LAMA", qty:42, noSJ:"SJ001", catatan:""};
  D.kirim.push(doRow);
  D.invoice["BATCH1"] = {invoiceNo:"INV001", batch:"BATCH1", tgl:TGL, toko:"TOKO A", noSJ:"SJ001", items:[{produk:"PRODUK LAMA",qty:42}], total:100000, createdAt:"now", updatedAt:"now"};
  const doSnapshotBefore = JSON.stringify(D.kirim);
  const invSnapshotBefore = JSON.stringify(D.invoice);

  // Seed ceklis with rich fields that must survive a revision upload untouched.
  D.po[TGL] = [{factory:FAC, kategori:"BASIC", kode:KODE, produk:"PRODUK LAMA", produkAsli:"PRODUK LAMA",
    divisi:DIVISI, poAwal:100, poRevisi:0, pb:0, stores:[]}];
  D.ceklis[ceklisKey(TGL,DIVISI)] = {submitted:true, submittedAt:"2026-04-01 08:00", closed:false,
    rows:[{kode:KODE, produk:"PRODUK LAMA", kategori:"BASIC", target:100, status:"tidak_sesuai", aktual:90, reject:5, keterangan:"5 pcs gosong"}]};
  saveD();

  // Now upload a revision for the SAME date+factory.
  const gab = poMergeDenganExisting(TGL, FAC, [{factory:FAC, kategori:"BASIC", kode:KODE, produk:"PRODUK LAMA",
    produkAsli:"PRODUK LAMA", divisi:DIVISI, poAwal:100, poRevisi:20, pb:0, stores:[]}]);
  D.po[TGL] = gab.rows; saveD();

  check("TEST13 Existing DO untouched by PO revision upload", doSnapshotBefore, JSON.stringify(D.kirim));
  check("TEST14-inv Existing Invoice untouched by PO revision upload", invSnapshotBefore, JSON.stringify(D.invoice));
  const c = D.ceklis[ceklisKey(TGL,DIVISI)];
  check("TEST21 Ceklis aktual preserved after revision (not reset)", 90, c.rows[0].aktual);
  check("TEST21 Ceklis reject preserved after revision (not reset)", 5, c.rows[0].reject);
  check("TEST21 Ceklis keterangan preserved after revision (not reset)", "5 pcs gosong", c.rows[0].keterangan);
  check("TEST21 Ceklis submittedAt preserved after revision (not reset)", "2026-04-01 08:00", c.submittedAt);

  // TEST15: Stock is derived only from ceklis(FG divisi submitted)+kirim+stokAdj —
  // a bare PO revision upload (no FG submission) must not move stok.
  const stokBefore = api.stokGudang("PRODUK LAMA");
  D.po[TGL] = poMergeDenganExisting(TGL, FAC, [{factory:FAC, kategori:"BASIC", kode:KODE, produk:"PRODUK LAMA",
    produkAsli:"PRODUK LAMA", divisi:DIVISI, poAwal:100, poRevisi:35, pb:0, stores:[]}]).rows;
  saveD();
  const stokAfter = api.stokGudang("PRODUK LAMA");
  check("TEST15 Stock unaffected by PO revision upload alone", stokBefore, stokAfter);

  // localStorage roundtrip: after saveD(), re-parsing localStorage must reproduce D.po/D.ceklis/D.kirim.
  const raw = api.__ctx.localStorage.getItem("amorcakes-arus-v1");
  const parsed = JSON.parse(raw);
  check("localStorage roundtrip — D.po persisted", JSON.stringify(D.po[TGL]), JSON.stringify(parsed.po[TGL]));
  check("localStorage roundtrip — D.kirim persisted", JSON.stringify(D.kirim), JSON.stringify(parsed.kirim));

  module.exports.groupE = results.slice(before);
})();

/* ===================== Report ===================== */
const total = results.length;
const passCount = results.filter(r=>r.pass).length;
console.log("\n=== TEST REPORT ===\n");
console.log("Test".padEnd(66), "Expected".padEnd(12), "Actual".padEnd(12), "Result");
results.forEach(r=>{
  console.log(
    r.name.padEnd(66),
    JSON.stringify(r.expected).padEnd(12),
    JSON.stringify(r.actual).padEnd(12),
    r.pass ? "PASS" : "FAIL"
  );
});
console.log(`\n=== REGRESSION RESULT: ${passCount}/${total} PASS ===`);
if(passCount!==total){
  console.log("\nFAILED CASES:");
  results.filter(r=>!r.pass).forEach(r=>console.log(" -", r.name, "expected", JSON.stringify(r.expected), "got", JSON.stringify(r.actual)));
  process.exitCode = 1;
}
