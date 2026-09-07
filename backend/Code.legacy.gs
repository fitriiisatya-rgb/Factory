/**
 * ============================================================
 *  ARSIP — VERSI LAMA (SEBELUM concurrency fix), JANGAN DI-DEPLOY.
 * ============================================================
 * Disimpan APA ADANYA (verbatim, tidak diubah satu baris pun) sbg bukti
 * audit — dipakai oleh tests/backend/legacy-race-repro.test.js utk
 * MEMBUKTIKAN secara reproducible (bukan dugaan) bahwa handler tulis di
 * sini kehilangan data ("lost update") saat 2 divisi menyimpan ke sheet
 * Ceklis yang sama pada jendela waktu yang tumpang tindih, karena TIDAK
 * ADA locking sama sekali dan pola deleteRowsWhere_ + rewriteAll_ adalah
 * baca-seluruh-sheet -> saring di memori -> clearContents() SELURUH sheet
 * -> tulis ulang, tanpa proteksi thd tulisan lain yang berjalan bersamaan.
 *
 * Lihat backend/Code.gs utk versi yang sudah diperbaiki (LockService +
 * versioning + idempotency + audit trail), dan laporan audit lengkap
 * (dikirim terpisah di percakapan) utk penjelasan detail tiap temuan.
 * ============================================================
 */

// ---------- Nama-nama sheet ----------
const SHEET_PO = "PO";
const SHEET_CEKLIS = "Ceklis";
const SHEET_CEKLIS_META = "CeklisMeta";
const SHEET_FGPACKING = "FGPacking";
const SHEET_FGREADY = "FGReady";
const SHEET_KIRIM = "Kirim";
const SHEET_RETUR = "Retur";
const SHEET_JUAL = "Jual";
const SHEET_MASTER = "Master";
const SHEET_MASTERPRODUK = "MasterProduk";
const SHEET_TOKOTIPE = "TokoTipe";
const SHEET_SETTINGS = "Settings";
const SHEET_INVOICE = "Invoice";
const SHEET_LOG = "Log";

const HEADERS = {
  [SHEET_PO]: ["Tanggal","Factory","Kategori","Kode","Produk","POAwal","PORevisi","PB","StoresJSON","UpdatedAt"],
  [SHEET_CEKLIS]: ["Tanggal","Divisi","Kode","Produk","Kategori","Target","Status","Aktual","Reject","Keterangan","UpdatedAt"],
  [SHEET_CEKLIS_META]: ["Tanggal","Divisi","SubmittedAt","Closed","ClosedAt"],
  [SHEET_FGPACKING]: ["Tanggal","Factory","Kode","Toko","Qty","Status","Keterangan","UpdatedAt"],
  [SHEET_FGREADY]: ["Tanggal","Factory","ReadyAt"],
  [SHEET_KIRIM]: ["Id","Batch","Tanggal","Toko","Produk","Qty","NoSJ","Pengemudi","Kendaraan","CreatedAt"],
  [SHEET_RETUR]: ["Id","Batch","Tanggal","Toko","Produk","Qty","Alasan","CreatedAt"],
  [SHEET_JUAL]: ["Id","Batch","Tanggal","Toko","Produk","Qty","CreatedAt"],
  [SHEET_MASTER]: ["Jenis","Nama"],
  [SHEET_MASTERPRODUK]: ["Produk","Kategori","Divisi","HPP","Harga","Aktif","UpdatedAt"],
  [SHEET_TOKOTIPE]: ["Toko","Tipe","UpdatedAt"],
  [SHEET_SETTINGS]: ["Key","Value"],
  [SHEET_INVOICE]: ["InvoiceNo","Batch","Tanggal","Toko","NoSJ","ItemsJSON","Total","CreatedAt","UpdatedAt"],
  [SHEET_LOG]: ["Waktu","Jenis","Payload","Error"]
};
const TEXT_COLS = ["Tanggal","Factory","Kategori","Kode","Id","Batch","Toko","NoSJ","Divisi","Jenis","Nama","Status","Produk","Tipe","Key","InvoiceNo"];

function setup(){
  Object.keys(HEADERS).forEach(name => getOrCreateSheet(name));
  Logger.log("Setup selesai — semua sheet sudah siap: " + Object.keys(HEADERS).join(", "));
}
function getSS(){ return SpreadsheetApp.getActiveSpreadsheet(); }
function getOrCreateSheet(name){
  const ss = getSS();
  let sh = ss.getSheetByName(name);
  const headers = HEADERS[name];
  if(!sh){
    sh = ss.insertSheet(name);
    sh.appendRow(headers);
    sh.setFrozenRows(1);
    headers.forEach((h,i)=>{
      if(TEXT_COLS.indexOf(h) !== -1){
        sh.getRange(1, i+1, Math.max(sh.getMaxRows(),1000), 1).setNumberFormat("@");
      }
    });
  }
  return sh;
}
function normDate_(v){
  if(v && typeof v==="object" && typeof v.getFullYear==="function"){
    return Utilities.formatDate(v, Session.getScriptTimeZone(), "yyyy-MM-dd");
  }
  if(typeof v === "string" && /^\d{4}-\d{2}-\d{2}/.test(v)) return v.slice(0,10);
  return String(v||"");
}
function num_(v){ const n = Number(v); return isNaN(n) ? 0 : n; }
function str_(v){ return v===undefined||v===null ? "" : String(v); }
function readAllAsObjects_(sh){
  const values = sh.getDataRange().getValues();
  if(values.length < 2) return [];
  const headers = values[0];
  return values.slice(1)
    .filter(row => row.some(c => c!=="" && c!==null))
    .map(row=>{
      const obj = {};
      headers.forEach((h,i)=>{ obj[h] = row[i]; });
      return obj;
    });
}
function appendObjects_(sh, headers, objects){
  if(!objects.length) return;
  const data = objects.map(o => headers.map(h => o[h]!==undefined ? o[h] : ""));
  sh.getRange(sh.getLastRow()+1, 1, data.length, headers.length).setValues(data);
}
function rewriteAll_(sh, headers, objects){
  sh.clearContents();
  sh.appendRow(headers);
  headers.forEach((h,i)=>{
    if(TEXT_COLS.indexOf(h) !== -1) sh.getRange(1, i+1, Math.max(objects.length+1,1000), 1).setNumberFormat("@");
  });
  if(objects.length) appendObjects_(sh, headers, objects);
}
function deleteRowsWhere_(sh, predicate){
  const headers = HEADERS[sh.getName()];
  const rows = readAllAsObjects_(sh);
  const kept = rows.filter(r => !predicate(r));
  rewriteAll_(sh, headers, kept);
}
function logPayload_(payload){
  try{
    const sh = getOrCreateSheet(SHEET_LOG);
    sh.appendRow([new Date(), payload.jenis||"?", JSON.stringify(payload).slice(0,5000), ""]);
  }catch(e){}
}
function logError_(payload, err){
  try{
    const sh = getOrCreateSheet(SHEET_LOG);
    sh.appendRow([new Date(), (payload&&payload.jenis)||"?", JSON.stringify(payload||{}).slice(0,5000), String(err)]);
  }catch(e){}
}
function doGet(e) {
  Object.keys(HEADERS).forEach(name => getOrCreateSheet(name));
  const out = {
    divisi: readMasterList_("divisi"),
    produk: readMasterList_("produk"),
    toko: readMasterList_("toko"),
    po: readPO_(),
    ceklis: readCeklis_(),
    kirim: readSimple_(SHEET_KIRIM, ["Id","Batch","Tanggal","Toko","Produk","Qty","NoSJ"], ["id","batch","tgl","toko","produk","qty","noSJ"]),
    retur: amorBacaRetur_(),
    jual: readSimple_(SHEET_JUAL, ["Id","Batch","Tanggal","Toko","Produk","Qty"], ["id","batch","tgl","toko","produk","qty"]),
    masterProduk: readMasterProduk_(),
    tokoTipe: readTokoTipe_(),
    settings: readSettings_(),
    invoice: readInvoice_()
  };
  return ContentService.createTextOutput(JSON.stringify(out))
    .setMimeType(ContentService.MimeType.JSON);
}
function readMasterProduk_(){
  const sh = getOrCreateSheet(SHEET_MASTERPRODUK);
  return readAllAsObjects_(sh).map(r=>({
    produk: str_(r.Produk), kategori: str_(r.Kategori), divisi: str_(r.Divisi),
    hpp: num_(r.HPP), harga: num_(r.Harga), aktif: str_(r.Aktif) !== "false"
  }));
}
function readTokoTipe_(){
  const sh = getOrCreateSheet(SHEET_TOKOTIPE);
  const out = {};
  readAllAsObjects_(sh).forEach(r=>{ if(str_(r.Toko)) out[str_(r.Toko)] = str_(r.Tipe); });
  return out;
}
function readSettings_(){
  const sh = getOrCreateSheet(SHEET_SETTINGS);
  const out = {};
  readAllAsObjects_(sh).forEach(r=>{ if(str_(r.Key)) out[str_(r.Key)] = str_(r.Value); });
  return out;
}
function readInvoice_(){
  const sh = getOrCreateSheet(SHEET_INVOICE);
  return readAllAsObjects_(sh).map(r=>{
    let items = [];
    try{ items = JSON.parse(r.ItemsJSON || "[]"); }catch(e){ items = []; }
    return {
      invoiceNo: str_(r.InvoiceNo), batch: str_(r.Batch), tgl: normDate_(r.Tanggal),
      toko: str_(r.Toko), noSJ: str_(r.NoSJ), items: Array.isArray(items) ? items : [], total: num_(r.Total)
    };
  });
}
function readMasterList_(jenis){
  const sh = getOrCreateSheet(SHEET_MASTER);
  return readAllAsObjects_(sh)
    .filter(r => str_(r.Jenis).toLowerCase() === jenis)
    .map(r => str_(r.Nama))
    .filter(Boolean);
}
function readPO_(){
  const sh = getOrCreateSheet(SHEET_PO);
  return readAllAsObjects_(sh).map(r=>{
    let stores = [];
    try{ stores = JSON.parse(r.StoresJSON || "[]"); }catch(e){ stores = []; }
    return {
      tanggal: normDate_(r.Tanggal),
      factory: str_(r.Factory) || "karangtengah",
      kategori: str_(r.Kategori),
      kode: str_(r.Kode),
      produk: str_(r.Produk),
      poAwal: num_(r.POAwal),
      poRevisi: num_(r.PORevisi),
      pb: num_(r.PB),
      stores: Array.isArray(stores) ? stores : []
    };
  });
}
function readCeklis_(){
  const shC = getOrCreateSheet(SHEET_CEKLIS);
  const shM = getOrCreateSheet(SHEET_CEKLIS_META);
  const metaRows = readAllAsObjects_(shM);
  const metaMap = {};
  metaRows.forEach(m=>{
    const key = normDate_(m.Tanggal) + "|" + str_(m.Divisi);
    metaMap[key] = { submittedAt: str_(m.SubmittedAt), closed: str_(m.Closed)==="true", closedAt: str_(m.ClosedAt) };
  });
  return readAllAsObjects_(shC).map(r=>{
    const tanggal = normDate_(r.Tanggal);
    const divisi = str_(r.Divisi);
    const meta = metaMap[tanggal+"|"+divisi] || {};
    return {
      tanggal: tanggal,
      divisi: divisi,
      kode: str_(r.Kode),
      produk: str_(r.Produk),
      kategori: str_(r.Kategori),
      target: num_(r.Target),
      status: str_(r.Status) || "sesuai",
      aktual: num_(r.Aktual),
      reject: num_(r.Reject),
      keterangan: str_(r.Keterangan),
      submittedAt: meta.submittedAt || "",
      closed: !!meta.closed,
      closedAt: meta.closedAt || ""
    };
  });
}
function readSimple_(sheetName, cols, outKeys){
  const sh = getOrCreateSheet(sheetName);
  return readAllAsObjects_(sh).map(r=>{
    const obj = {};
    cols.forEach((c,i)=>{
      const key = outKeys[i];
      const raw = r[c];
      if(key==="qty") obj[key] = num_(raw);
      else if(key==="tgl") obj[key] = normDate_(raw);
      else obj[key] = str_(raw);
    });
    return obj;
  });
}
function doPost(e){
  let payload;
  try{
    payload = JSON.parse(e.postData.contents);
  }catch(err){
    return jsonResp_({ok:false, error:"payload bukan JSON valid"});
  }
  logPayload_(payload);
  try{
    switch(payload.jenis){
      case "poUpload": handlePoUpload_(payload); break;
      case "hapusPO": handleHapusPO_(payload); break;
      case "ceklisProduksi": handleCeklisProduksi_(payload); break;
      case "ceklisTutup": handleCeklisTutup_(payload); break;
      case "fgPacking": handleFgPacking_(payload); break;
      case "fgReady": handleFgReady_(payload); break;
      case "kirim": handleAppendTransaksi_(SHEET_KIRIM, payload.rows, ["Id","Batch","Tanggal","Toko","Produk","Qty","NoSJ","Pengemudi","Kendaraan"]); break;
      case "hapusKirim": handleHapusById_(SHEET_KIRIM, payload.id); break;
      case "retur": handleAppendTransaksi_(SHEET_RETUR, payload.rows, ["Id","Batch","Tanggal","Toko","Produk","Qty","Alasan"]); break;
      case "hapusRetur": handleHapusById_(SHEET_RETUR, payload.id); break;
      case "jual": handleAppendTransaksi_(SHEET_JUAL, payload.rows, ["Id","Batch","Tanggal","Toko","Produk","Qty"]); break;
      case "hapusJual": handleHapusById_(SHEET_JUAL, payload.id); break;
      case "divisiUpsert": handleMasterUpsert_("divisi", payload.nama); break;
      case "produkUpsert": handleMasterUpsert_("produk", payload.nama); break;
      case "tokoUpsert": handleMasterUpsert_("toko", payload.nama); break;
      case "masterProdukUpsert": handleMasterProdukUpsert_(payload); break;
      case "tokoTipeUpsert": handleTokoTipeUpsert_(payload); break;
      case "settingsUpsert": handleSettingsUpsert_(payload); break;
      case "invoice": handleInvoiceUpsert_(payload); break;
      case "reset": handleReset_(); break;
      default:
        logError_(payload, "jenis tidak dikenal: " + payload.jenis);
    }
  }catch(err){
    logError_(payload, err);
    return jsonResp_({ok:false, error:String(err)});
  }
  return jsonResp_({ok:true});
}
function jsonResp_(obj){
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
function handlePoUpload_(payload){
  const sh = getOrCreateSheet(SHEET_PO);
  const tanggal = normDate_(payload.tanggal);
  const factory = str_(payload.factory);
  deleteRowsWhere_(sh, r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory);
  const now = new Date();
  const rows = (payload.rows||[]).map(r=>({
    Tanggal: tanggal, Factory: factory, Kategori: str_(r.kategori), Kode: str_(r.kode), Produk: str_(r.produk),
    POAwal: num_(r.poAwal), PORevisi: num_(r.poRevisi), PB: num_(r.pb),
    StoresJSON: JSON.stringify(r.stores||[]), UpdatedAt: now
  }));
  appendObjects_(sh, HEADERS[SHEET_PO], rows);
}
function handleHapusPO_(payload){
  const sh = getOrCreateSheet(SHEET_PO);
  const tanggal = normDate_(payload.tanggal);
  const factory = str_(payload.factory);
  deleteRowsWhere_(sh, r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory);
}
function handleCeklisProduksi_(payload){
  const sh = getOrCreateSheet(SHEET_CEKLIS);
  const tanggal = normDate_(payload.tanggal);
  const divisi = str_(payload.divisi);
  deleteRowsWhere_(sh, r => normDate_(r.Tanggal)===tanggal && str_(r.Divisi)===divisi);
  const now = new Date();
  const rows = (payload.rows||[]).map(r=>({
    Tanggal: tanggal, Divisi: divisi, Kode: str_(r.kode), Produk: str_(r.produk), Kategori: str_(r.kategori),
    Target: num_(r.target), Status: str_(r.status), Aktual: num_(r.aktual), Reject: num_(r.reject),
    Keterangan: str_(r.keterangan), UpdatedAt: now
  }));
  appendObjects_(sh, HEADERS[SHEET_CEKLIS], rows);
  const shM = getOrCreateSheet(SHEET_CEKLIS_META);
  deleteRowsWhere_(shM, m => normDate_(m.Tanggal)===tanggal && str_(m.Divisi)===divisi);
  appendObjects_(shM, HEADERS[SHEET_CEKLIS_META], [{
    Tanggal: tanggal, Divisi: divisi, SubmittedAt: now.toLocaleString("id-ID"), Closed: "false", ClosedAt: ""
  }]);
}
function handleCeklisTutup_(payload){
  const shM = getOrCreateSheet(SHEET_CEKLIS_META);
  const tanggal = normDate_(payload.tanggal);
  const divisi = str_(payload.divisi);
  const rows = readAllAsObjects_(shM);
  const now = new Date();
  let found = false;
  const updated = rows.map(m=>{
    if(normDate_(m.Tanggal)===tanggal && str_(m.Divisi)===divisi){
      found = true;
      return Object.assign({}, m, {Closed:"true", ClosedAt: now.toLocaleString("id-ID")});
    }
    return m;
  });
  if(!found) updated.push({Tanggal:tanggal, Divisi:divisi, SubmittedAt:"", Closed:"true", ClosedAt: now.toLocaleString("id-ID")});
  rewriteAll_(shM, HEADERS[SHEET_CEKLIS_META], updated);
}
function handleFgPacking_(payload){
  const sh = getOrCreateSheet(SHEET_FGPACKING);
  const tanggal = normDate_(payload.tanggal);
  const factory = str_(payload.factory);
  deleteRowsWhere_(sh, r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory);
  const now = new Date();
  const packed = payload.packed || {};
  const rows = Object.keys(packed).map(key=>{
    const idx = key.lastIndexOf("|");
    const kode = key.slice(0, idx);
    const toko = key.slice(idx+1);
    const val = packed[key];
    const isObj = val && typeof val === "object";
    return {
      Tanggal: tanggal, Factory: factory, Kode: kode, Toko: toko,
      Qty: num_(isObj ? val.qty : val),
      Status: isObj ? str_(val.status) : "sesuai",
      Keterangan: isObj ? str_(val.keterangan) : "",
      UpdatedAt: now
    };
  });
  appendObjects_(sh, HEADERS[SHEET_FGPACKING], rows);
}
function handleFgReady_(payload){
  const sh = getOrCreateSheet(SHEET_FGREADY);
  const tanggal = normDate_(payload.tanggal);
  const factory = str_(payload.factory);
  deleteRowsWhere_(sh, r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory);
  appendObjects_(sh, HEADERS[SHEET_FGREADY], [{Tanggal:tanggal, Factory:factory, ReadyAt: str_(payload.readyAt)}]);
}
function handleAppendTransaksi_(sheetName, rows, headerFields){
  const sh = getOrCreateSheet(sheetName);
  const now = new Date();
  const objects = (rows||[]).map(r=>{
    const o = {
      Id: str_(r.id), Batch: str_(r.batch), Tanggal: normDate_(r.tgl), Toko: str_(r.toko),
      Produk: str_(r.produk), Qty: num_(r.qty)
    };
    if(headerFields.indexOf("NoSJ")!==-1) o.NoSJ = str_(r.noSJ);
    if(headerFields.indexOf("Pengemudi")!==-1) o.Pengemudi = str_(r.pengemudi);
    if(headerFields.indexOf("Kendaraan")!==-1) o.Kendaraan = str_(r.kendaraan);
    if(headerFields.indexOf("Alasan")!==-1) o.Alasan = str_(r.alasan);
    o.CreatedAt = now;
    return o;
  });
  appendObjects_(sh, HEADERS[sheetName], objects);
}
function handleHapusById_(sheetName, id){
  const sh = getOrCreateSheet(sheetName);
  deleteRowsWhere_(sh, r => str_(r.Id) === str_(id));
}
function handleMasterUpsert_(jenis, nama){
  nama = str_(nama).trim();
  if(!nama) return;
  const sh = getOrCreateSheet(SHEET_MASTER);
  const rows = readAllAsObjects_(sh);
  const exists = rows.some(r => str_(r.Jenis).toLowerCase()===jenis && str_(r.Nama)===nama);
  if(!exists) appendObjects_(sh, HEADERS[SHEET_MASTER], [{Jenis:jenis, Nama:nama}]);
}
function handleMasterProdukUpsert_(payload){
  const produk = str_(payload.produk).trim();
  if(!produk) return;
  const sh = getOrCreateSheet(SHEET_MASTERPRODUK);
  deleteRowsWhere_(sh, r => str_(r.Produk)===produk);
  appendObjects_(sh, HEADERS[SHEET_MASTERPRODUK], [{
    Produk: produk, Kategori: str_(payload.kategori), Divisi: str_(payload.divisi),
    HPP: num_(payload.hpp), Harga: num_(payload.harga),
    Aktif: payload.aktif===false ? "false" : "true",
    UpdatedAt: new Date()
  }]);
}
function handleTokoTipeUpsert_(payload){
  const toko = str_(payload.toko).trim();
  if(!toko) return;
  const sh = getOrCreateSheet(SHEET_TOKOTIPE);
  deleteRowsWhere_(sh, r => str_(r.Toko)===toko);
  appendObjects_(sh, HEADERS[SHEET_TOKOTIPE], [{Toko: toko, Tipe: str_(payload.tipe), UpdatedAt: new Date()}]);
}
function handleSettingsUpsert_(payload){
  const sh = getOrCreateSheet(SHEET_SETTINGS);
  Object.keys(payload).forEach(key=>{
    if(key==="jenis") return;
    if(payload[key]===undefined || payload[key]===null) return;
    deleteRowsWhere_(sh, r => str_(r.Key)===key);
    appendObjects_(sh, HEADERS[SHEET_SETTINGS], [{Key:key, Value:String(payload[key])}]);
  });
}
function handleInvoiceUpsert_(payload){
  const batch = str_(payload.batch).trim();
  if(!batch) return;
  const sh = getOrCreateSheet(SHEET_INVOICE);
  const existingRows = readAllAsObjects_(sh);
  const existing = existingRows.find(r => str_(r.Batch)===batch);
  deleteRowsWhere_(sh, r => str_(r.Batch)===batch);
  appendObjects_(sh, HEADERS[SHEET_INVOICE], [{
    InvoiceNo: str_(payload.invoiceNo), Batch: batch, Tanggal: normDate_(payload.tanggal),
    Toko: str_(payload.toko), NoSJ: str_(payload.noSJ),
    ItemsJSON: JSON.stringify(payload.items||[]), Total: num_(payload.total),
    CreatedAt: existing ? existing.CreatedAt : new Date(), UpdatedAt: new Date()
  }]);
}
function handleReset_(){
  arsipkanData_("sebelum-reset");
  [SHEET_PO,SHEET_CEKLIS,SHEET_CEKLIS_META,SHEET_FGPACKING,SHEET_FGREADY,SHEET_KIRIM,SHEET_RETUR,SHEET_JUAL,SHEET_INVOICE].forEach(name=>{
    const sh = getOrCreateSheet(name);
    rewriteAll_(sh, HEADERS[name], []);
  });
}
function arsipkanData(){ arsipkanData_("manual"); }
function arsipkanData_(label){
  try{
    const ss = getSS();
    const stamp = Utilities.formatDate(new Date(), Session.getScriptTimeZone(), "yyyyMMdd-HHmmss");
    const copy = ss.copy("Arsip Amorcakes " + label + " " + stamp);
    Logger.log("Arsip dibuat: " + copy.getUrl());
    return copy.getUrl();
  }catch(e){
    Logger.log("Gagal membuat arsip: " + e);
    return null;
  }
}
function amorBacaRetur_() {
  const sh = SpreadsheetApp.getActive().getSheetByName('Retur');
  if (!sh) return [];
  const nilai = sh.getDataRange().getValues();
  if (nilai.length < 2) return [];
  const head = nilai[0].map(h => String(h).trim().toLowerCase());
  const kol = (...nama) => {
    for (const n of nama) {
      const i = head.indexOf(String(n).toLowerCase());
      if (i >= 0) return i;
    }
    return -1;
  };
  const cId     = kol('id transaksi','idtransaksi','id');
  const cTgl    = kol('tanggal','tgl','timestamp');
  const cToko   = kol('nama toko','toko');
  const cProduk = kol('nama produk','produk');
  const cQty    = kol('qty','jumlah');
  const cJenis  = kol('jenis');
  const cAlasan = kol('alasan','keterangan');
  const cHarga  = kol('harga satuan','harga');
  if (cProduk < 0 || cQty < 0) return [];
  const hasil = [];
  for (let i = 1; i < nilai.length; i++) {
    const b = nilai[i];
    if (String(b.join('')).trim() === '') continue;
    const qty = amorAngka_(b[cQty]);
    const produk = String(b[cProduk] || '').trim();
    if (!qty || !produk) continue;
    hasil.push({
      idTransaksi: cId     >= 0 ? String(b[cId]).trim() : '',
      tanggal:     amorTanggal_(cTgl >= 0 ? b[cTgl] : ''),
      toko:        cToko   >= 0 ? String(b[cToko]).trim()   : '',
      produk:      produk,
      qty:         qty,
      jenis:       cJenis  >= 0 ? String(b[cJenis]).trim()  : 'Retur',
      alasan:      cAlasan >= 0 ? String(b[cAlasan]).trim() : '',
      harga:       cHarga  >= 0 ? amorAngka_(b[cHarga])     : 0
    });
  }
  return hasil;
}
function amorTanggal_(v) {
  if (v instanceof Date && !isNaN(v))
    return Utilities.formatDate(v, Session.getScriptTimeZone(), 'yyyy-MM-dd');
  const t = String(v || '').trim();
  if (!t) return '';
  const m = t.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
  if (m) return m[1] + '-' + ('0'+m[2]).slice(-2) + '-' + ('0'+m[3]).slice(-2);
  const d = new Date(t);
  if (!isNaN(d)) return Utilities.formatDate(d, Session.getScriptTimeZone(), 'yyyy-MM-dd');
  return '';
}
function amorAngka_(v) {
  if (typeof v === 'number') return v;
  let t = String(v || '').trim();
  if (!t) return 0;
  if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(t)) t = t.replace(/,/g,'');
  else if (/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(t)) t = t.replace(/\./g,'').replace(',','.');
  const n = parseFloat(t.replace(/[^\d.-]/g,''));
  return isNaN(n) ? 0 : n;
}
function amorTesRetur() {
  const d = amorBacaRetur_();
  Logger.log('Terbaca ' + d.length + ' baris');
  Logger.log(JSON.stringify(d.slice(0,5), null, 2));
}
