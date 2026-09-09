/**
 * ============================================================
 *  AMORCAKES — BACKEND GOOGLE APPS SCRIPT (v2, CONCURRENCY-SAFE)
 * ============================================================
 * Ini REVISI dari Code.gs yang sudah ada, dibuat khusus utk mengamankan
 * penyimpanan saat BEBERAPA DIVISI submit hampir bersamaan (Roti & Bollen,
 * Basic, Donat/Mochi/AKB, Pastry, Bolu, FG). Nama sheet/kolom LAMA tetap
 * dipertahankan — ditambah, bukan dihapus/di-rename — supaya data historis
 * tidak perlu dimigrasi manual.
 *
 * APA YANG BERUBAH DARI VERSI SEBELUMNYA (ringkas — detail lengkap ada di
 * laporan audit terpisah):
 *   1. SETIAP tulis (doPost) sekarang dibungkus LockService.getScriptLock()
 *      — sebelumnya TIDAK ADA locking sama sekali, jadi 2 divisi yang
 *      submit bersamaan bisa saling menimpa/mengosongkan sheet
 *      (deleteRowsWhere_ lama = clearContents() SELURUH sheet lalu tulis
 *      ulang, tanpa pengaman apa pun terhadap request lain yang jalan di
 *      waktu yang sama).
 *   2. Versioning optimis per record (tanggal+divisi utk Ceklis,
 *      tanggal+factory utk PO/FGPacking/FGReady, dst.) — disimpan di sheet
 *      baru "RecordVersion". Kalau app mengirim expectedVersion yang sudah
 *      basi (sudah ada yang menulis duluan), tulisan DITOLAK dgn
 *      VERSION_CONFLICT, BUKAN ditimpa diam-diam.
 *   3. Idempotency key (requestId) — sheet baru "RequestLog" + cache cepat
 *      (CacheService). Request yang sama (mis. retry jaringan HP) yang
 *      terkirim 2x TIDAK diproses dua kali.
 *   4. Audit trail append-only — sheet baru "AuditLog" mencatat SETIAP
 *      mutasi (siapa, kapan, versi sebelum/sesudah, berhasil/ditolak).
 *   5. Respons JSON terstruktur ({ok, code, version, ...}) — CATATAN
 *      PENTING: kalau app di sisi HTML masih memakai
 *      fetch(url,{mode:"no-cors"}), respons ini TETAP TIDAK PERNAH dibaca
 *      browser (itu keterbatasan mode "no-cors", bukan bug di sini). App
 *      HTML harus diubah utk TIDAK pakai no-cors supaya bisa membaca
 *      VERSION_CONFLICT/LOCK_TIMEOUT/ALREADY_APPLIED. Lihat berkas
 *      pendamping (perubahan kirimSheets/muatSemua) — dan WAJIB dites
 *      manual di browser asli, karena Apps Script Web App kadang butuh
 *      penyesuaian tambahan soal CORS yang tidak bisa dipastikan dari sini.
 *   6. BUG DIPERBAIKI: FGPacking dulu menyimpan key packing sbg
 *      "<kode>\x1f<nama produk>|<toko>" (format skuId dari app, dibuat utk
 *      mengatasi 1 kode dipakai >1 produk berbeda) tapi backend LAMA
 *      memperlakukan semuanya-sebelum-"|"-terakhir sbg "Kode" polos —
 *      hasilnya kolom Kode di sheet FGPacking kemasukan karakter kontrol +
 *      nama produk, bukan kode bersih. Kontrak fgPacking sekarang kirim
 *      array rows eksplisit {kode,produk,toko,qty,status,keterangan},
 *      bukan lagi map "key":qty — supaya tidak ada parsing string yang
 *      rapuh sama sekali.
 *   7. Handler jenis yang SEBELUMNYA TIDAK DIKENALI backend (dicek dari
 *      isi kirimSheets() di HTML — bukan tebakan): "pembayaran",
 *      "hapusPembayaran", "mutasi", "stokAdj", "hapusStokAdj", "reject",
 *      "hapusReject", "pesanan", "pesananStatus", "hapusPesanan". Semua
 *      itu jatuh ke `default:` di switch lama dan CUMA dicatat ke Log
 *      sbg "jenis tidak dikenal" — datanya TIDAK PERNAH benar-benar
 *      tersimpan ke Sheets. Sekarang ditambahkan handler-nya (append-only,
 *      sama polanya dgn Kirim/Retur/Jual yang sudah ada).
 *
 * CARA PASANG: SAMA seperti sebelumnya (lihat header versi lama) — tempel
 * seluruh isi file ini menggantikan Code.gs lama, jalankan "setup" sekali
 * dari editor (akan otomatis membuat sheet baru RecordVersion/RequestLog/
 * AuditLog + kolom baru yang dibutuhkan), lalu re-deploy sbg Web App versi
 * baru (Deploy > Manage deployments > edit > New version). URL /exec BISA
 * TETAP SAMA kalau di-deploy sbg versi baru dari deployment yang sama.
 *
 * YANG BELUM DIPECAHKAN OLEH FILE INI (baca laporan lengkap):
 *   - Tidak ada autentikasi nyata. Deployment "Execute as: Me, Anyone" tetap
 *     berarti SIAPA PUN yang punya URL bisa menulis apa saja termasuk
 *     "reset". userId/userName/role yang dikirim app HANYA dicatat sbg
 *     metadata audit, BUKAN diverifikasi/diotorisasi di sini.
 *   - Global script lock berarti SEMUA tulisan (lintas divisi/lintas jenis
 *     data) antre satu-satu, bukan paralel per-record. Ini tradeoff yang
 *     disengaja demi kesederhanaan & keamanan (lihat bagian "Lock
 *     strategy" di laporan) — pada skala 6 divisi menulis beberapa kali
 *     per hari ini seharusnya cukup cepat (order milidetik-detik), tapi
 *     BELUM diuji thd Google Apps Script sungguhan (cuma disimulasikan).
 * ============================================================
 */

// ---------- Nama-nama sheet (LAMA, tidak berubah) ----------
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

// ---------- Sheet BARU (concurrency safety) ----------
const SHEET_RECORDVERSION = "RecordVersion";
const SHEET_REQUESTLOG = "RequestLog";
const SHEET_AUDITLOG = "AuditLog";

// ---------- Sheet BARU (jenis yang sebelumnya tidak ada handler-nya) ----------
const SHEET_PEMBAYARAN = "Pembayaran";
const SHEET_MUTASI = "Mutasi";
const SHEET_STOKADJ = "StokAdj";
const SHEET_REJECT = "Reject";
const SHEET_PESANAN = "Pesanan";

const HEADERS = {
  [SHEET_PO]: ["Tanggal","Factory","Kategori","Kode","Produk","POAwal","PORevisi","PB","StoresJSON","UpdatedAt"],
  // Kode+Produk sekarang eksplisit terpisah (bukan lagi digabung/di-parse dari
  // satu string key skuId) — lihat handleFgPacking_ & catatan bug #6 di atas.
  [SHEET_CEKLIS]: ["Tanggal","Divisi","Kode","Produk","Kategori","Target","Status","Aktual","Reject","Keterangan","UpdatedAt"],
  // Status eksplisit (bukan diinfer dari qty/Closed — lihat konstanta
  // CEKLIS_STATUS_* dan touchCeklisMetaDraft_/markCeklisSubmitted_/
  // markCeklisReopened_/markCeklisVerifiedFg_ di bawah). Kolom baru,
  // backward-compatible: baris lama tanpa Status dibaca sbg "not_started"
  // (bukan ditebak dari Closed), akan terisi begitu record itu disentuh lagi.
  [SHEET_CEKLIS_META]: ["Tanggal","Divisi","Status","SubmittedAt","Closed","ClosedAt","ClosedBy","ReopenReason"],
  [SHEET_FGPACKING]: ["Tanggal","Factory","Kode","Produk","Toko","Qty","Status","Keterangan","UpdatedAt"],
  [SHEET_FGREADY]: ["Tanggal","Factory","ReadyAt","SourceVersionJSON"],
  [SHEET_KIRIM]: ["Id","Batch","Tanggal","Toko","Produk","Qty","NoSJ","Pengemudi","Kendaraan","CreatedAt"],
  [SHEET_RETUR]: ["Id","Batch","Tanggal","Toko","Produk","Qty","Alasan","CreatedAt"],
  [SHEET_JUAL]: ["Id","Batch","Tanggal","Toko","Produk","Qty","CreatedAt"],
  [SHEET_MASTER]: ["Jenis","Nama"],
  [SHEET_MASTERPRODUK]: ["Produk","Kategori","Divisi","HPP","Harga","Aktif","UpdatedAt"],
  [SHEET_TOKOTIPE]: ["Toko","Tipe","UpdatedAt"],
  [SHEET_SETTINGS]: ["Key","Value"],
  [SHEET_INVOICE]: ["InvoiceNo","Batch","Tanggal","Toko","NoSJ","ItemsJSON","Total","CreatedAt","UpdatedAt"],
  [SHEET_LOG]: ["Waktu","Jenis","Payload","Error"],
  [SHEET_RECORDVERSION]: ["RecordType","RecordKey","Version","UpdatedAt","UpdatedBy"],
  [SHEET_REQUESTLOG]: ["RequestId","RecordType","RecordKey","Status","ResponseJSON","CreatedAt"],
  [SHEET_AUDITLOG]: ["EventId","RequestId","Timestamp","UserId","UserName","Role","Action","Tanggal","Divisi","RecordKey","PreviousVersion","NewVersion","PayloadSummary","Status"],
  [SHEET_PEMBAYARAN]: ["Id","Batch","InvoiceNo","Toko","Tanggal","Jumlah","Cara","Keterangan","CreatedAt"],
  [SHEET_MUTASI]: ["Id","Tanggal","Produk","Asal","Tujuan","Qty","Keterangan","BatchAsal","BatchTujuan","CreatedAt"],
  [SHEET_STOKADJ]: ["Id","Tanggal","Produk","Tipe","Qty","Keterangan","CreatedAt"],
  [SHEET_REJECT]: ["Id","Batch","Tanggal","Toko","Produk","Qty","Alasan","Resolusi","Nilai","InvoiceBatch","CreatedAt"],
  [SHEET_PESANAN]: ["Id","No","Status","PayloadJSON","CreatedAt","UpdatedAt"]
};
// Kolom yang dipaksa jadi teks polos, supaya Sheets tidak otomatis mengubah
// jadi angka/tanggal (mis. "0079" jadi 79, "2026-08-11" jadi objek Date yang
// bikin perbandingan string meleset).
const TEXT_COLS = ["Tanggal","Factory","Kategori","Kode","Id","Batch","Toko","NoSJ","Divisi","Jenis","Nama","Status",
  "Produk","Tipe","Key","InvoiceNo","RecordType","RecordKey","RequestId","EventId","UserId","UserName","Role",
  "Action","No","BatchAsal","BatchTujuan","InvoiceBatch"];

const LOCK_TIMEOUT_MS = 10000; // 10 detik — cukup utk beban beberapa divisi, lihat catatan tradeoff di laporan.
const CACHE_TTL_SEC = 21600; // 6 jam — cukup panjang utk menutup retry jaringan HP yang telat, cache expiry BUKAN batas idempotency (RequestLog sheet yang permanen).

// ============================================================
//  FITUR SEMENTARA MASA TRIAL — JANGAN DIANGGAP FITUR NORMAL
// ============================================================
// "Hapus Batch Trial" (recordType "po" key tanggal|factory) menghapus
// CASCADE seluruh data operasional satu batch PO — PO, Ceklis/CeklisMeta,
// FGPacking, FGReady, Kirim/DO, Invoice, Pembayaran, Retur/Reject, Jual,
// Mutasi, StokAdj (lihat cascadeDeleteTrialBatch_) — supaya admin bisa
// membersihkan data percobaan selama UAT/trial tanpa mengarsipkan/reset
// seluruh spreadsheet. Ini BUKAN pengganti hapusPO biasa (yang cuma
// menghapus baris PO) dan BUKAN fitur produksi permanen.
//
// Setelah masa trial selesai, MATIKAN dengan mengubah baris di bawah ini
// jadi `false` lalu deploy ulang — begitu false, handleTrialBatchDelete_
// SELALU menolak dgn TRIAL_DELETE_DISABLED sebelum menyentuh lock/sheet
// apa pun (tidak ada jalan pintas), dan tombol di sisi HTML juga otomatis
// hilang/nonaktif kalau ENABLE_TRIAL_BATCH_DELETE di HTML ikut diset false.
const ENABLE_TRIAL_BATCH_DELETE = true;

// ---- Status eksplisit lifecycle Ceklis per tanggal+divisi ----
// JANGAN infer status dari qty atau Closed di mana pun — field Status pada
// CeklisMeta inilah SATU-SATUNYA sumber kebenaran, ditulis eksplisit oleh
// fungsi touchCeklisMetaDraft_/markCeklisSubmitted_/markCeklisReopened_/
// markCeklisVerifiedFg_ di bawah, masing-masing dipanggil dari HANYA satu
// jenis aksi (progress, submit, reopen, verifikasi FG).
const CEKLIS_STATUS_NOT_STARTED = "not_started";
const CEKLIS_STATUS_DRAFT = "draft";
const CEKLIS_STATUS_SUBMITTED = "submitted";
const CEKLIS_STATUS_REOPENED = "reopened";
const CEKLIS_STATUS_VERIFIED_FG = "verified_fg";

function setup(){
  Object.keys(HEADERS).forEach(name => getOrCreateSheet(name));
  migrateSchema_();
  Logger.log("Setup selesai — semua sheet sudah siap (termasuk migrasi skema kalau ada yg perlu): " + Object.keys(HEADERS).join(", "));
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

// ============================================================
//  SCHEMA MIGRATION — sheet PRODUKSI yang SUDAH ADA sebelum kolom baru
//  ditambahkan (Status di CeklisMeta, Produk di FGPacking, SourceVersionJSON
//  di FGReady) TIDAK otomatis dapat kolom itu dari getOrCreateSheet() —
//  fungsi itu HANYA menulis header saat sheet BARU dibuat. Tanpa migrasi
//  eksplisit ini, kolom baru itu baru "ketiban" (self-heal, karena
//  deleteRowsWhere_/rewriteAll_ menulis ulang SELURUH sheet pakai HEADERS
//  terbaru, dipetakan per NAMA bukan posisi — jadi nilai lama TIDAK
//  bergeser ke kolom salah) begitu ada TULISAN PERTAMA ke sheet itu sesudah
//  deploy. Sebelum tulisan pertama itu terjadi, PEMBACAAN LANGSUNG (doGet /
//  readCeklis_, yang baca header AS-IS tanpa lewat deleteRowsWhere_ dulu)
//  akan melihat kolom baru itu kosong utk SEMUA baris historis — misalnya
//  metaStatus terbaca "not_started" utk record yang sebenarnya sudah lama
//  submitted. Ini bikin migrasi implisit itu TIDAK BOLEH DIANDALKAN; harus
//  eksplisit & deterministik, dipanggil dari setup() DAN (sbg jaring
//  pengaman tambahan) dari doGet/doPost supaya tidak bergantung sama sekali
//  pada orang mengingat menjalankan setup() ulang tiap kali deploy.
function migrateSchema_(){
  migrateCeklisMetaStatus_();
  migrateFgPackingProdukColumn_();
  migrateFgReadySourceVersionColumn_();
}

// Cek CEPAT & READ-ONLY (tanpa lock, tanpa getOrCreateSheet — kalau sheet
// belum ada sama sekali, itu bukan kasus migrasi; nanti dibuat FRESH dgn
// header terbaru oleh getOrCreateSheet begitu benar2 diakses) apakah MASIH
// ada sheet yang perlu dimigrasi. Dipakai sbg fast-path di
// ensureSchemaMigrated_ — HARUS murah krn dipanggil di SETIAP doGet/doPost.
function sheetMissingColumn_(sheetName, newColumn){
  const sh = getSS().getSheetByName(sheetName);
  if(!sh) return false;
  const header = readHeaderRow_(sh);
  if(!header.length) return false; // sheet ada tapi kosong total -> bukan kasus migrasi (getOrCreateSheet/migrate*_ menanganinya sbg no-op/isi header baru saat benar2 disentuh)
  return header.indexOf(newColumn) === -1;
}
function schemaNeedsMigration_(){
  return sheetMissingColumn_(SHEET_CEKLIS_META, "Status")
      || sheetMissingColumn_(SHEET_FGPACKING, "Produk")
      || sheetMissingColumn_(SHEET_FGREADY, "SourceVersionJSON");
}

/**
 * Dipakai KHUSUS dari doGet()/doPost() (bukan dari setup() — setup() boleh
 * memanggil migrateSchema_() langsung/manual, lihat requirement #1). Pola:
 *   1. cek read-only TANPA lock dulu -> kalau tidak perlu migrasi, return
 *      cepat (ini kasus NORMAL/steady-state, harus murah).
 *   2. kalau PERLU migrasi -> baru acquire LockService.getScriptLock().
 *   3. CEK ULANG setelah dapat lock -> request LAIN mungkin sudah
 *      menyelesaikan migrasi duluan selagi kita menunggu/baru mulai cek;
 *      kalau sudah, jangan migrasi lagi (idempoten, hindari rewriteAll_
 *      dobel yang sia-sia).
 *   4. migrateSchema_() di dalam critical section itu.
 *   5. release di finally — TIDAK PERNAH menahan lock lebih lama dari
 *      migrasi itu sendiri, apalagi menahannya selama SISA doGet/doPost.
 *      Ini PENTING: handler mutation (productionProgress/ceklisSubmit/dst)
 *      MEMAKAI withLock_() SENDIRI belakangan di request yang sama (utk
 *      doPost) — lock migrasi ini SUDAH DILEPAS jauh sebelum handler mana
 *      pun mulai, jadi TIDAK bersarang (nested) dengan lock milik handler.
 *   Kalau tryLock() gagal (request lain sedang migrasi) -> jangan
 *   memaksa; lewati saja utk request INI (bukan fatal — request ini tetap
 *   lanjut baca/tulis dgn apa adanya, request berikutnya yang berhasil
 *   dapat lock akan menuntaskannya). Ini SATU-SATUNYA kasus di mana kolom
 *   baru bisa sesaat masih kosong utk SATU request yg pas bersamaan dgn
 *   migrasi pertama kali — batasnya sempit (cuma sekali, saat deploy
 *   pertama) dan tidak merusak data apa pun.
 */
function ensureSchemaMigrated_(){
  if(!schemaNeedsMigration_()) return;
  const lock = LockService.getScriptLock();
  let gotLock = false;
  try{ gotLock = lock.tryLock(LOCK_TIMEOUT_MS); }catch(e){ gotLock = false; }
  if(!gotLock){
    logError_({jenis:"ensureSchemaMigrated_"}, "Lock timeout saat mencoba migrasi skema otomatis — dilewati utk request ini (request lain kemungkinan sedang migrasi), akan dituntaskan oleh request berikutnya.");
    return;
  }
  try{
    if(!schemaNeedsMigration_()) return; // sudah dituntaskan request lain selagi kita menunggu lock
    migrateSchema_();
  } finally {
    try{ lock.releaseLock(); }catch(e){}
  }
}

// Baca HANYA baris header (bukan getDataRange() yg menarik SELURUH sheet) —
// ini dipanggil di setiap doGet/doPost, jadi harus murah utk kasus normal
// (sheet sudah termigrasi, tidak ada apa2 yg perlu dikerjakan lagi).
function readHeaderRow_(sh){
  const lastCol = sh.getLastColumn();
  if(lastCol < 1) return [];
  return sh.getRange(1, 1, 1, lastCol).getValues()[0].map(h=>String(h).trim());
}

// ---- CeklisMeta: sisipkan kolom "Status" (setelah Divisi, sesuai posisi
// di HEADERS[SHEET_CEKLIS_META] terbaru) utk sheet lama yang belum punya.
//
// ATURAN MIGRASI utk baris historis (persis seperti diminta):
//   - SubmittedAt ADA dan Closed=="true"  -> Status = submitted
//     (sudah pernah disubmit & memang masih tertutup — paling sesuai)
//   - SubmittedAt ADA tapi Closed=="false" -> Status = reopened
//     (CATATAN JUJUR: data lama (5-7 kolom) TIDAK membedakan "pernah
//     disubmit lalu dibuka lagi" dari "pernah disimpan progress berkali-
//     kali tapi belum pernah benar2 di-submit" — versi backend SEBELUM
//     patch ini memang mengisi SubmittedAt di setiap progress save, bug
//     yang baru diperbaiki di patch sebelumnya. "reopened" dipilih sesuai
//     instruksi krn itu yang paling aman/masuk akal drpd mengklaim
//     "submitted" utk record yang justru sedang Closed=false, TAPI ini
//     tetap sebuah heuristik, bukan fakta yang bisa dipulihkan 100% akurat
//     dari data lama.)
//   - SubmittedAt TIDAK ADA sama sekali    -> Status = not_started
//     (walau Closed kebetulan true — tanpa bukti SubmittedAt, tidak
//     diklaim submitted)
// Kalau header sheet TIDAK cocok dgn bentuk lama yang dikenali (bukan
// bentuk baru, bukan juga salah satu bentuk lama yang dikenal) — migrasi
// DIBATALKAN dan dicatat ke Log, drpd menebak-nebak dan salah menata ulang
// data produksi orang.
function migrateCeklisMetaStatus_(){
  const sh = getOrCreateSheet(SHEET_CEKLIS_META);
  const currentHeader = readHeaderRow_(sh);
  if(!currentHeader.length){ sh.appendRow(HEADERS[SHEET_CEKLIS_META]); return; }
  if(currentHeader.indexOf("Status") !== -1) return; // sudah termigrasi ATAU sheet baru -> idempoten, tidak diapa-apakan lagi.
  const OLD_CORE = ["Tanggal","Divisi","SubmittedAt","Closed","ClosedAt"]; // sama di bentuk lama 5-kolom (legacy asli) MAUPUN 7-kolom (+ClosedBy/ReopenReason)
  const matchesKnownOldShape = OLD_CORE.every(col => currentHeader.indexOf(col) !== -1);
  if(!matchesKnownOldShape){
    logError_({jenis:"migrateCeklisMetaStatus_"}, "Header CeklisMeta tidak dikenali (bukan bentuk baru, bukan juga bentuk lama yang diketahui) — migrasi Status DIBATALKAN demi keamanan. Header aktual: "+JSON.stringify(currentHeader));
    return;
  }
  const oldRows = readAllAsObjects_(sh); // dibaca dgn header LAMA yang masih apa adanya di sheet saat ini
  const migrated = oldRows.map(r=>{
    const closed = str_(r.Closed) === "true";
    const submittedAt = str_(r.SubmittedAt);
    let status;
    if(submittedAt && closed) status = CEKLIS_STATUS_SUBMITTED;
    else if(submittedAt && !closed) status = CEKLIS_STATUS_REOPENED;
    else status = CEKLIS_STATUS_NOT_STARTED;
    return {
      Tanggal: r.Tanggal, Divisi: r.Divisi, Status: status, SubmittedAt: str_(r.SubmittedAt),
      Closed: str_(r.Closed)||"false", ClosedAt: str_(r.ClosedAt),
      ClosedBy: str_(r.ClosedBy), ReopenReason: str_(r.ReopenReason) // "" kalau kolom ini belum ada sama sekali (bentuk 5-kolom asli)
    };
  });
  rewriteAll_(sh, HEADERS[SHEET_CEKLIS_META], migrated);
  logPayload_({jenis:"migrateCeklisMetaStatus_ selesai", rowsMigrated: migrated.length});
}

// ---- FGPacking: sisipkan kolom "Produk" (setelah Kode). Data lama tidak
// pernah mencatat nama produk terpisah dari Kode (itulah akar bug korupsi
// Kode yang sudah diperbaiki di patch sebelumnya) — jadi TIDAK ADA cara
// aman memulihkan nama produk baris historis; default "" (kosong), bukan
// menebak dari isi Kode yang justru mungkin sudah tercampur skuId lama.
function migrateFgPackingProdukColumn_(){
  const sh = getOrCreateSheet(SHEET_FGPACKING);
  const currentHeader = readHeaderRow_(sh);
  if(!currentHeader.length){ sh.appendRow(HEADERS[SHEET_FGPACKING]); return; }
  if(currentHeader.indexOf("Produk") !== -1) return;
  const OLD_CORE = ["Tanggal","Factory","Kode","Toko","Qty","Status","Keterangan","UpdatedAt"];
  const matchesKnownOldShape = OLD_CORE.every(col => currentHeader.indexOf(col) !== -1);
  if(!matchesKnownOldShape){
    logError_({jenis:"migrateFgPackingProdukColumn_"}, "Header FGPacking tidak dikenali — migrasi Produk DIBATALKAN demi keamanan. Header aktual: "+JSON.stringify(currentHeader));
    return;
  }
  const oldRows = readAllAsObjects_(sh);
  const migrated = oldRows.map(r=>({
    Tanggal: r.Tanggal, Factory: r.Factory, Kode: r.Kode, Produk: "",
    Toko: r.Toko, Qty: r.Qty, Status: r.Status, Keterangan: r.Keterangan, UpdatedAt: r.UpdatedAt
  }));
  rewriteAll_(sh, HEADERS[SHEET_FGPACKING], migrated);
  logPayload_({jenis:"migrateFgPackingProdukColumn_ selesai", rowsMigrated: migrated.length});
}

// ---- FGReady: sisipkan kolom "SourceVersionJSON". Data lama tidak pernah
// mencatat versi produksi sumber (fitur staleness-warning FG baru ada di
// patch ini) — default "{}" (peta kosong, artinya "tidak ada info versi
// sumber yang tercatat", beda makna dari peta berisi versi asli).
function migrateFgReadySourceVersionColumn_(){
  const sh = getOrCreateSheet(SHEET_FGREADY);
  const currentHeader = readHeaderRow_(sh);
  if(!currentHeader.length){ sh.appendRow(HEADERS[SHEET_FGREADY]); return; }
  if(currentHeader.indexOf("SourceVersionJSON") !== -1) return;
  const OLD_CORE = ["Tanggal","Factory","ReadyAt"];
  const matchesKnownOldShape = OLD_CORE.every(col => currentHeader.indexOf(col) !== -1);
  if(!matchesKnownOldShape){
    logError_({jenis:"migrateFgReadySourceVersionColumn_"}, "Header FGReady tidak dikenali — migrasi SourceVersionJSON DIBATALKAN demi keamanan. Header aktual: "+JSON.stringify(currentHeader));
    return;
  }
  const oldRows = readAllAsObjects_(sh);
  const migrated = oldRows.map(r=>({
    Tanggal: r.Tanggal, Factory: r.Factory, ReadyAt: r.ReadyAt, SourceVersionJSON: "{}"
  }));
  rewriteAll_(sh, HEADERS[SHEET_FGREADY], migrated);
  logPayload_({jenis:"migrateFgReadySourceVersionColumn_ selesai", rowsMigrated: migrated.length});
}

// ---------- Util tanggal & angka ----------
function normDate_(v){
  if(v && typeof v==="object" && typeof v.getFullYear==="function"){
    return Utilities.formatDate(v, Session.getScriptTimeZone(), "yyyy-MM-dd");
  }
  if(typeof v === "string" && /^\d{4}-\d{2}-\d{2}/.test(v)) return v.slice(0,10);
  return String(v||"");
}
function num_(v){ const n = Number(v); return isNaN(n) ? 0 : n; }
function str_(v){ return v===undefined||v===null ? "" : String(v); }

// ---------- Baca / tulis sheet sebagai array of object ----------
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

// ---------- Logging mentah (debug, TIDAK dipakai sbg audit trail resmi) ----------
function logPayload_(payload){
  try{
    const sh = getOrCreateSheet(SHEET_LOG);
    sh.appendRow([new Date(), payload.jenis||"?", JSON.stringify(payload).slice(0,5000), ""]);
  }catch(e){ /* jangan sampai logging sendiri bikin doPost gagal */ }
}
function logError_(payload, err){
  try{
    const sh = getOrCreateSheet(SHEET_LOG);
    sh.appendRow([new Date(), (payload&&payload.jenis)||"?", JSON.stringify(payload||{}).slice(0,5000), String(err)]);
  }catch(e){}
}

// ============================================================
//  KONKURENSI: lock, versi, idempotency, audit
// ============================================================

// Bungkus SATU eksekusi kritis (baca versi terkini -> validasi -> tulis ->
// naikkan versi -> audit) dgn LockService.getScriptLock(). Global (bukan
// per-sheet/per-record) — SEMUA tulisan app antre lewat lock yang sama,
// lihat catatan tradeoff soal ini di header file.
function withLock_(fn){
  const lock = LockService.getScriptLock();
  let gotLock = false;
  try{ gotLock = lock.tryLock(LOCK_TIMEOUT_MS); }catch(e){ gotLock = false; }
  if(!gotLock){
    return {ok:false, code:"LOCK_TIMEOUT", message:"Sistem sedang sibuk (ada divisi lain yang sedang menyimpan), coba lagi beberapa detik lagi."};
  }
  try{
    return fn();
  } finally {
    try{ lock.releaseLock(); }catch(e){}
  }
}

function cacheKey_(requestId){ return "req_" + requestId; }

// Cek apakah requestId ini SUDAH PERNAH diproses. Cache dulu (cepat), baru
// kalau miss fallback ke sheet RequestLog (permanen, tahan restart/expiry
// cache). HARUS dipanggil dari dalam withLock_.
function checkIdempotent_(requestId){
  if(!requestId) return null;
  try{
    const cache = CacheService.getScriptCache();
    const hit = cache.get(cacheKey_(requestId));
    if(hit) return JSON.parse(hit);
  }catch(e){ /* cache gagal -> lanjut cek sheet, jangan sampai bikin request gagal total */ }
  const sh = getOrCreateSheet(SHEET_REQUESTLOG);
  const rows = readAllAsObjects_(sh);
  const found = rows.find(r => str_(r.RequestId) === requestId);
  if(!found) return null;
  try{ return JSON.parse(found.ResponseJSON || "{}"); }catch(e){ return {ok:true, code:"ALREADY_APPLIED"}; }
}
function recordIdempotent_(requestId, recordType, recordKey, response){
  if(!requestId) return;
  const marked = Object.assign({}, response, {code: response.code || (response.ok ? "ALREADY_APPLIED" : undefined)});
  try{
    const sh = getOrCreateSheet(SHEET_REQUESTLOG);
    appendObjects_(sh, HEADERS[SHEET_REQUESTLOG], [{
      RequestId: requestId, RecordType: recordType, RecordKey: recordKey,
      Status: response.ok ? "applied" : "rejected", ResponseJSON: JSON.stringify(response), CreatedAt: new Date()
    }]);
  }catch(e){}
  try{
    CacheService.getScriptCache().put(cacheKey_(requestId), JSON.stringify(response), CACHE_TTL_SEC);
  }catch(e){}
}

function getVersion_(recordType, recordKey){
  const sh = getOrCreateSheet(SHEET_RECORDVERSION);
  const rows = readAllAsObjects_(sh);
  const hit = rows.find(r => str_(r.RecordType)===recordType && str_(r.RecordKey)===recordKey);
  return hit
    ? {version:num_(hit.Version), updatedAt:str_(hit.UpdatedAt), updatedBy:str_(hit.UpdatedBy), rowExists:true}
    : {version:0, updatedAt:"", updatedBy:"", rowExists:false};
}
function setVersion_(recordType, recordKey, newVersion, updatedBy){
  const sh = getOrCreateSheet(SHEET_RECORDVERSION);
  deleteRowsWhere_(sh, r => str_(r.RecordType)===recordType && str_(r.RecordKey)===recordKey);
  appendObjects_(sh, HEADERS[SHEET_RECORDVERSION], [{
    RecordType:recordType, RecordKey:recordKey, Version:newVersion,
    UpdatedAt:new Date().toISOString(), UpdatedBy:updatedBy||""
  }]);
}
// Dipakai doGet utk mengembalikan versi semua record dari SATU RecordType
// sekaligus (mis. semua "ceklis") supaya app tahu expectedVersion berikutnya
// tanpa perlu 1 request terpisah per tanggal+divisi.
function getAllVersions_(recordType){
  const sh = getOrCreateSheet(SHEET_RECORDVERSION);
  const out = {};
  readAllAsObjects_(sh).forEach(r=>{
    if(str_(r.RecordType)===recordType) out[str_(r.RecordKey)] = num_(r.Version);
  });
  return out;
}

function appendAudit_(evt){
  try{
    const sh = getOrCreateSheet(SHEET_AUDITLOG);
    appendObjects_(sh, HEADERS[SHEET_AUDITLOG], [{
      EventId: Utilities.getUuid(), RequestId: str_(evt.requestId), Timestamp: new Date(),
      UserId: str_(evt.userId), UserName: str_(evt.userName), Role: str_(evt.role),
      Action: str_(evt.action), Tanggal: str_(evt.tanggal), Divisi: str_(evt.divisi),
      RecordKey: str_(evt.recordKey), PreviousVersion: num_(evt.previousVersion), NewVersion: num_(evt.newVersion),
      PayloadSummary: str_(evt.payloadSummary).slice(0,2000), Status: str_(evt.status)
    }]);
  }catch(e){ /* audit TIDAK BOLEH bikin mutasi utama gagal, tapi kita masih mau tahu kalau ini pernah gagal */
    try{ logError_({jenis:"appendAudit_ gagal", evt:evt}, e); }catch(e2){}
  }
}

/**
 * Inti pola section 3/4/6/7 dari spesifikasi: satu fungsi generik dipakai
 * SEMUA handler yang butuh "replace penuh 1 record key" dgn aman.
 *
 *   opts: {recordType, recordKey, requestId, expectedVersion, actor:{userId,userName,role}, action, tanggal, divisi}
 *   applyFn(current) -> {record, payloadSummary} — HANYA dipanggil kalau
 *     requestId belum pernah diproses DAN expectedVersion cocok (atau tidak
 *     dikirim sama sekali, utk backward-compat client lama yg belum kirim
 *     versi apa pun — DIPERBOLEHKAN tapi TIDAK aman, lihat catatan blocker).
 *
 * expectedVersion==null (tidak dikirim) -> version check DILEWATI (supaya
 * client lama yang belum di-upgrade tidak langsung semua gagal), TAPI ini
 * berarti client itu TIDAK terlindung dari lost update — harus dianggap
 * transisi sementara, bukan target akhir.
 */
function mutateVersioned_(opts, applyFn){
  const recordType = opts.recordType, recordKey = opts.recordKey, requestId = opts.requestId;
  const actor = opts.actor || {};
  return withLock_(function(){
    const cached = checkIdempotent_(requestId);
    if(cached) return cached;

    const current = getVersion_(recordType, recordKey);
    if(opts.expectedVersion != null && current.rowExists && num_(opts.expectedVersion) !== current.version){
      const resp = {ok:false, code:"VERSION_CONFLICT", currentVersion: current.version,
        message:"Data sudah berubah oleh user lain."};
      recordIdempotent_(requestId, recordType, recordKey, resp);
      appendAudit_({requestId, userId:actor.userId, userName:actor.userName, role:actor.role,
        action:"conflict_rejected", tanggal:opts.tanggal, divisi:opts.divisi, recordKey,
        previousVersion:current.version, newVersion:current.version,
        payloadSummary:"expectedVersion="+opts.expectedVersion+" currentVersion="+current.version+" action="+opts.action,
        status:"conflict"});
      return resp;
    }

    let result;
    try{
      result = applyFn(current) || {};
    }catch(err){
      const resp = {ok:false, code:"APPLY_ERROR", message:String(err)};
      appendAudit_({requestId, userId:actor.userId, userName:actor.userName, role:actor.role,
        action:opts.action, tanggal:opts.tanggal, divisi:opts.divisi, recordKey,
        previousVersion:current.version, newVersion:current.version,
        payloadSummary:String(err).slice(0,500), status:"error"});
      // SENGAJA tidak direkam ke RequestLog sbg sukses — request boleh diulang oleh client.
      return resp;
    }

    const newVersion = current.version + 1;
    setVersion_(recordType, recordKey, newVersion, actor.userName||actor.userId||"");
    const resp = {ok:true, version:newVersion, updatedAt:new Date().toISOString(), record: result.record};
    if(result.warning) resp.warning = result.warning;
    recordIdempotent_(requestId, recordType, recordKey, resp);
    appendAudit_({requestId, userId:actor.userId, userName:actor.userName, role:actor.role,
      action:opts.action, tanggal:opts.tanggal, divisi:opts.divisi, recordKey,
      previousVersion:current.version, newVersion:newVersion,
      payloadSummary:(result.payloadSummary||"").slice(0,2000), status:"ok"});
    return resp;
  });
}

/**
 * Versi lebih sederhana utk operasi APPEND-ONLY (Kirim/Retur/Jual/Reject/
 * Pembayaran/Mutasi/StokAdj) — tidak ada "versi record" yang bermakna utk
 * dicek (append tidak pernah menimpa baris lain), tapi TETAP butuh lock
 * (supaya getLastRow()+tulis tidak bentrok antar request bersamaan) dan
 * requestId dedup (supaya retry jaringan tidak menggandakan baris).
 */
function mutateAppend_(opts, applyFn){
  const actor = opts.actor || {};
  return withLock_(function(){
    const cached = checkIdempotent_(opts.requestId);
    if(cached) return cached;
    let result;
    try{
      result = applyFn() || {};
    }catch(err){
      const resp = {ok:false, code:"APPLY_ERROR", message:String(err)};
      appendAudit_({requestId:opts.requestId, userId:actor.userId, userName:actor.userName, role:actor.role,
        action:opts.action, tanggal:opts.tanggal, divisi:opts.divisi, recordKey:opts.recordKey,
        previousVersion:0, newVersion:0, payloadSummary:String(err).slice(0,500), status:"error"});
      return resp;
    }
    const resp = {ok:true, updatedAt:new Date().toISOString(), record: result.record};
    recordIdempotent_(opts.requestId, opts.recordType, opts.recordKey, resp);
    appendAudit_({requestId:opts.requestId, userId:actor.userId, userName:actor.userName, role:actor.role,
      action:opts.action, tanggal:opts.tanggal, divisi:opts.divisi, recordKey:opts.recordKey,
      previousVersion:0, newVersion:0, payloadSummary:(result.payloadSummary||"").slice(0,2000), status:"ok"});
    return resp;
  });
}

// Section 4 (STRICT versioning): endpoint multi-user "inti" (productionProgress/
// ceklisSubmit/ceklisReopen) WAJIB requestId+expectedVersion — jangan biarkan
// lolos tanpa itu, karena keduanya inilah yang membuat endpoint ini aman dari
// lost-update/duplikasi. Endpoint LAIN (poUpload, fgPacking, fgReady, master/
// lookup upserts, dst) TETAP backward-compatible sementara (expectedVersion
// null dilewati, requestId kosong berarti tidak idempoten) — lihat catatan di
// mutateVersioned_.
function requireStrictVersioning_(payload){
  if(!payload.requestId) return {ok:false, code:"MISSING_REQUEST_ID", message:"requestId wajib utk endpoint ini."};
  if(payload.expectedVersion==null) return {ok:false, code:"MISSING_EXPECTED_VERSION", message:"expectedVersion wajib utk endpoint ini."};
  return null;
}

function actorFromPayload_(payload){
  // CATATAN KERAS: ini BUKAN otentikasi. userId/userName/role di sini
  // sepenuhnya diklaim sendiri oleh client, dicatat apa adanya ke audit
  // trail utk KETERLACAKAN, bukan sbg dasar otorisasi. Selama app belum
  // punya login/verifikasi identitas asli, siapa pun yang punya URL /exec
  // bisa mengklaim nama/role apa pun. Lihat blocker "AUTH IDENTITY".
  return {
    userId: str_(payload.userId || payload.userName || "anon"),
    userName: str_(payload.userName || "anon"),
    role: str_(payload.role || "")
  };
}

// ============================================================
//  doGet — kirim seluruh data sbg JSON (dipakai muatSemua())
// ============================================================
function doGet(e){
  Object.keys(HEADERS).forEach(name => getOrCreateSheet(name));
  // Jaring pengaman: jangan bergantung pada orang mengingat menjalankan
  // setup() ulang tiap kali sheet lama ketemu kolom baru. ensureSchemaMigrated_
  // (BUKAN migrateSchema_ langsung) — cek read-only dulu, cuma acquire lock
  // kalau benar2 perlu menulis, lock dilepas SEBELUM baris ini selesai (jadi
  // tidak nested dgn lock milik handler mutation manapun). Lihat catatan di
  // ensureSchemaMigrated_.
  ensureSchemaMigrated_();
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
    invoice: readInvoice_(),
    // BARU: versi per record, supaya app bisa menyimpan expectedVersion
    // utk pengiriman berikutnya tanpa request terpisah. Data packing FG
    // per-toko SENGAJA TIDAK ditarik balik ke sini (tetap lokal saja,
    // volumenya besar — keputusan yang sudah ada sebelumnya), tapi
    // VERSInya tetap dikirim supaya device yang sama tahu expectedVersion
    // yang benar utk simpanan berikutnya.
    versions: {
      po: getAllVersions_("po"),
      ceklis: getAllVersions_("ceklis"),
      fgPacking: getAllVersions_("fgPacking"),
      fgReady: getAllVersions_("fgReady"),
      invoice: getAllVersions_("invoice"),
      masterProduk: getAllVersions_("masterProduk"),
      tokoTipe: getAllVersions_("tokoTipe"),
      settings: getAllVersions_("settings")
    }
  };
  return ContentService.createTextOutput(JSON.stringify(out)).setMimeType(ContentService.MimeType.JSON);
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
      tanggal: normDate_(r.Tanggal), factory: str_(r.Factory) || "karangtengah",
      kategori: str_(r.Kategori), kode: str_(r.Kode), produk: str_(r.Produk),
      poAwal: num_(r.POAwal), poRevisi: num_(r.PORevisi), pb: num_(r.PB),
      stores: Array.isArray(stores) ? stores : []
    };
  });
}
function readCeklis_(){
  const shC = getOrCreateSheet(SHEET_CEKLIS);
  const shM = getOrCreateSheet(SHEET_CEKLIS_META);
  const metaMap = {};
  readAllAsObjects_(shM).forEach(m=>{
    const key = normDate_(m.Tanggal) + "|" + str_(m.Divisi);
    // NOTE: field ini "metaStatus" (lifecycle: not_started/draft/submitted/
    // reopened/verified_fg dari CeklisMeta), BUKAN "status" per-baris di
    // sheet Ceklis (itu status sesuai/tidak_sesuai per SKU, field beda).
    // Baris lama tanpa kolom Status dibaca "not_started" — TIDAK ditebak
    // dari Closed/qty (lihat catatan section 2/3 di header file).
    metaMap[key] = { metaStatus: str_(m.Status) || CEKLIS_STATUS_NOT_STARTED, submittedAt: str_(m.SubmittedAt), closed: str_(m.Closed)==="true", closedAt: str_(m.ClosedAt), reopenReason: str_(m.ReopenReason) };
  });
  return readAllAsObjects_(shC).map(r=>{
    const tanggal = normDate_(r.Tanggal), divisi = str_(r.Divisi);
    const meta = metaMap[tanggal+"|"+divisi] || {};
    return {
      tanggal, divisi, kode: str_(r.Kode), produk: str_(r.Produk), kategori: str_(r.Kategori),
      target: num_(r.Target), status: str_(r.Status) || "sesuai", aktual: num_(r.Aktual), reject: num_(r.Reject),
      keterangan: str_(r.Keterangan), submittedAt: meta.submittedAt || "", closed: !!meta.closed, closedAt: meta.closedAt || "",
      metaStatus: meta.metaStatus || CEKLIS_STATUS_NOT_STARTED, reopenReason: meta.reopenReason || ""
    };
  });
}
function readSimple_(sheetName, cols, outKeys){
  const sh = getOrCreateSheet(sheetName);
  return readAllAsObjects_(sh).map(r=>{
    const obj = {};
    cols.forEach((c,i)=>{
      const key = outKeys[i], raw = r[c];
      if(key==="qty") obj[key] = num_(raw);
      else if(key==="tgl") obj[key] = normDate_(raw);
      else obj[key] = str_(raw);
    });
    return obj;
  });
}

// ============================================================
//  doPost — terima satu aksi (payload.jenis) dari app
//
//  PENTING (lihat catatan #5 di header file): app SEBELUM diubah mengirim
//  dgn mode:"no-cors", jadi respons ini TIDAK PERNAH dibaca browser dari
//  versi app yang belum diperbarui. Kalau app sudah diperbarui utk TIDAK
//  pakai no-cors, respons terstruktur di sini baru benar-benar berguna.
// ============================================================
function doPost(e){
  let payload;
  try{
    payload = JSON.parse(e.postData.contents);
  }catch(err){
    return jsonResp_({ok:false, code:"BAD_PAYLOAD", message:"payload bukan JSON valid"});
  }
  // Jaring pengaman yg sama dgn doGet — lihat catatan di ensureSchemaMigrated_.
  // Lock migrasi (kalau memang perlu) SUDAH DILEPAS pada titik ini sebelum
  // baris ini selesai, jauh sebelum switch(payload.jenis) di bawah mulai
  // memanggil handler mana pun yang pakai withLock_() sendiri — jadi TIDAK
  // bersarang dgn lock handler.
  ensureSchemaMigrated_();
  logPayload_(payload);
  const actor = actorFromPayload_(payload);
  let resp;
  try{
    switch(payload.jenis){
      case "poUpload": resp = handlePoUpload_(payload, actor); break;
      case "hapusPO": resp = handleHapusPO_(payload, actor); break;
      // FITUR SEMENTARA MASA TRIAL — lihat ENABLE_TRIAL_BATCH_DELETE di atas.
      case "trialBatchDelete": resp = handleTrialBatchDelete_(payload, actor); break;
      // "ceklisProduksi" dipertahankan (replace penuh, cocok dgn client lama
      // yg belum diupgrade). Client yg sudah diupgrade pakai "productionProgress"
      // (delta-safe, section 6) dan "ceklisSubmit"/"ceklisReopen" (section 8/9).
      case "ceklisProduksi": resp = handleCeklisProduksiReplace_(payload, actor); break;
      case "productionProgress": resp = handleProductionProgressDelta_(payload, actor); break;
      case "ceklisTutup": case "ceklisSubmit": resp = handleCeklisSubmit_(payload, actor); break;
      case "ceklisReopen": resp = handleCeklisReopen_(payload, actor); break;
      case "fgPacking": resp = handleFgPacking_(payload, actor); break;
      case "fgReady": resp = handleFgReady_(payload, actor); break;
      case "kirim": resp = handleAppendTransaksi_(SHEET_KIRIM, "kirim", payload, actor, ["Id","Batch","Tanggal","Toko","Produk","Qty","NoSJ","Pengemudi","Kendaraan"]); break;
      case "hapusKirim": resp = handleHapusById_(SHEET_KIRIM, "hapusKirim", payload, actor); break;
      case "retur": resp = handleAppendTransaksi_(SHEET_RETUR, "retur", payload, actor, ["Id","Batch","Tanggal","Toko","Produk","Qty","Alasan"]); break;
      case "hapusRetur": resp = handleHapusById_(SHEET_RETUR, "hapusRetur", payload, actor); break;
      case "jual": resp = handleAppendTransaksi_(SHEET_JUAL, "jual", payload, actor, ["Id","Batch","Tanggal","Toko","Produk","Qty"]); break;
      case "hapusJual": resp = handleHapusById_(SHEET_JUAL, "hapusJual", payload, actor); break;
      // ---- Sebelumnya TIDAK ADA case-nya sama sekali (jatuh ke default,
      // datanya hilang) — lihat catatan #7 di header file. ----
      case "reject": resp = handleReject_(payload, actor); break;
      case "hapusReject": resp = handleHapusByBatch_(SHEET_REJECT, "hapusReject", payload, actor); break;
      case "pembayaran": resp = handleAppendTransaksi_(SHEET_PEMBAYARAN, "pembayaran", payload, actor, ["Id","Batch","InvoiceNo","Toko","Tanggal","Jumlah","Cara","Keterangan"], true); break;
      case "hapusPembayaran": resp = handleHapusById_(SHEET_PEMBAYARAN, "hapusPembayaran", payload, actor); break;
      case "mutasi": resp = handleMutasi_(payload, actor); break;
      case "stokAdj": resp = handleStokAdj_(payload, actor); break;
      case "hapusStokAdj": resp = handleHapusById_(SHEET_STOKADJ, "hapusStokAdj", payload, actor); break;
      case "pesanan": resp = handlePesanan_(payload, actor); break;
      case "pesananStatus": resp = handlePesananStatus_(payload, actor); break;
      case "hapusPesanan": resp = handleHapusById_(SHEET_PESANAN, "hapusPesanan", payload, actor); break;
      // ---- selebihnya (tidak berubah dari versi lama) ----
      case "divisiUpsert": resp = handleMasterUpsert_("divisi", payload, actor); break;
      case "produkUpsert": resp = handleMasterUpsert_("produk", payload, actor); break;
      case "tokoUpsert": resp = handleMasterUpsert_("toko", payload, actor); break;
      case "masterProdukUpsert": resp = handleMasterProdukUpsert_(payload, actor); break;
      case "tokoTipeUpsert": resp = handleTokoTipeUpsert_(payload, actor); break;
      case "settingsUpsert": resp = handleSettingsUpsert_(payload, actor); break;
      case "invoice": resp = handleInvoiceUpsert_(payload, actor); break;
      // Reset jarak jauh DINONAKTIFKAN TOTAL — tidak pernah mengeksekusi apa
      // pun, tidak pernah menyentuh sheet, siapa pun yang mengirim payload
      // ini (termasuk yang benar-benar berniat) selalu dapat respons ini.
      // arsipkanData()/arsipkanData_() tetap ada di file ini HANYA utk
      // dijalankan manual dari dalam Apps Script Editor (Run > arsipkanData)
      // oleh orang yang punya akses editor — bukan lewat doPost publik.
      case "reset":
        logError_(payload, "reset ditolak — remote reset dinonaktifkan (RESET_DISABLED)");
        resp = {ok:false, code:"RESET_DISABLED", message:"Reset jarak jauh dinonaktifkan. Jalankan arsipkanData() manual dari Apps Script Editor kalau memang perlu mengarsipkan/mengosongkan data."};
        break;
      default:
        logError_(payload, "jenis tidak dikenal: " + payload.jenis);
        resp = {ok:false, code:"UNKNOWN_JENIS", message:"jenis tidak dikenal: "+payload.jenis};
    }
  }catch(err){
    logError_(payload, err);
    resp = {ok:false, code:"UNCAUGHT_ERROR", message:String(err)};
  }
  return jsonResp_(resp);
}

function jsonResp_(obj){
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}

// ============================================================
//  Handler — PO (recordType "po", key = tanggal|factory)
// ============================================================
function handlePoUpload_(payload, actor){
  const tanggal = normDate_(payload.tanggal), factory = str_(payload.factory);
  const recordKey = tanggal + "|" + factory;
  return mutateVersioned_({
    recordType:"po", recordKey, requestId:payload.requestId, expectedVersion:payload.expectedVersion,
    actor, action:"po_upload", tanggal, divisi:""
  }, function(){
    const sh = getOrCreateSheet(SHEET_PO);
    deleteRowsWhere_(sh, r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory);
    const now = new Date();
    const rows = (payload.rows||[]).map(r=>({
      Tanggal: tanggal, Factory: factory, Kategori: str_(r.kategori), Kode: str_(r.kode), Produk: str_(r.produk),
      POAwal: num_(r.poAwal), PORevisi: num_(r.poRevisi), PB: num_(r.pb),
      StoresJSON: JSON.stringify(r.stores||[]), UpdatedAt: now
    }));
    appendObjects_(sh, HEADERS[SHEET_PO], rows);
    return {record:{tanggal, factory, rowCount:rows.length}, payloadSummary: rows.length+" baris PO utk "+recordKey};
  });
}
function handleHapusPO_(payload, actor){
  const tanggal = normDate_(payload.tanggal), factory = str_(payload.factory);
  const recordKey = tanggal + "|" + factory;
  return mutateVersioned_({
    recordType:"po", recordKey, requestId:payload.requestId, expectedVersion:payload.expectedVersion,
    actor, action:"po_delete", tanggal, divisi:""
  }, function(){
    const sh = getOrCreateSheet(SHEET_PO);
    deleteRowsWhere_(sh, r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory);
    return {record:{tanggal, factory, rowCount:0}, payloadSummary:"PO "+recordKey+" dihapus"};
  });
}

// ============================================================
//  Handler — TRIAL BATCH DELETE (FITUR SEMENTARA MASA TRIAL)
//  Lihat ENABLE_TRIAL_BATCH_DELETE di atas SEBELUM membaca fungsi ini.
// ============================================================
// Beda dgn handleHapusPO_ (cuma menghapus baris PO): ini CASCADE ke semua
// sheet operasional yg berelasi dgn batch PO (tanggal+factory) tsb, supaya
// admin bisa membersihkan SATU batch percobaan sampai bersih selama UAT.
//
// requestId WAJIB (idempotency — retry jaringan tidak boleh menghapus dua
// kali/menghitung ganda) dan seluruh operasi dibungkus withLock_ yang sama
// dgn mutasi lain, supaya tidak bentrok dgn poUpload/hapusPO/dst yang
// jalan bersamaan. expectedVersion (opsional, versi record "po" di key
// ini) dicek SAMA seperti mutateVersioned_ — kalau ada yang re-upload PO
// utk tanggal+factory yang sama tepat sebelum tombol ini ditekan, hapusnya
// ditolak VERSION_CONFLICT alih-alih diam-diam menghapus data yang baru.
function handleTrialBatchDelete_(payload, actor){
  if(!ENABLE_TRIAL_BATCH_DELETE){
    logError_(payload, "trialBatchDelete ditolak — ENABLE_TRIAL_BATCH_DELETE=false");
    return {ok:false, code:"TRIAL_DELETE_DISABLED", message:"Fitur hapus batch trial sudah dinonaktifkan (ENABLE_TRIAL_BATCH_DELETE=false di Code.gs)."};
  }
  const tanggal = normDate_(payload.tanggal), factory = str_(payload.factory);
  if(!tanggal || !factory) return {ok:false, code:"BAD_PAYLOAD", message:"tanggal dan factory wajib diisi."};
  const recordKey = tanggal + "|" + factory;
  const requestId = payload.requestId;
  if(!requestId) return {ok:false, code:"MISSING_REQUEST_ID", message:"requestId wajib utk endpoint ini."};

  return withLock_(function(){
    const cached = checkIdempotent_(requestId);
    if(cached) return cached;

    const current = getVersion_("po", recordKey);
    if(payload.expectedVersion != null && current.rowExists && num_(payload.expectedVersion) !== current.version){
      const resp = {ok:false, code:"VERSION_CONFLICT", currentVersion:current.version,
        message:"Data PO batch ini sudah berubah oleh orang lain — muat ulang dulu sebelum menghapus."};
      recordIdempotent_(requestId, "trialBatchDelete", recordKey, resp);
      appendAudit_({requestId, userId:actor.userId, userName:actor.userName, role:actor.role,
        action:"trial_batch_delete_conflict", tanggal, divisi:"", recordKey,
        previousVersion:current.version, newVersion:current.version,
        payloadSummary:"expectedVersion="+payload.expectedVersion+" currentVersion="+current.version, status:"conflict"});
      return resp;
    }

    let result;
    try{
      result = cascadeDeleteTrialBatch_(tanggal, factory);
    }catch(err){
      const resp = {ok:false, code:"APPLY_ERROR", message:String(err)};
      appendAudit_({requestId, userId:actor.userId, userName:actor.userName, role:actor.role,
        action:"trial_batch_delete", tanggal, divisi:"", recordKey,
        previousVersion:current.version, newVersion:current.version,
        payloadSummary:String(err).slice(0,500), status:"error"});
      // SENGAJA tidak direkam sbg ALREADY_APPLIED ke RequestLog — boleh diulang.
      return resp;
    }

    const newVersion = current.version + 1;
    setVersion_("po", recordKey, newVersion, actor.userName||actor.userId||"");
    const resp = {ok:true, version:newVersion, updatedAt:new Date().toISOString(), record: result};
    recordIdempotent_(requestId, "trialBatchDelete", recordKey, resp);
    // Tombstone — AuditLog TETAP menyimpan bukti "batch trial ini pernah ada
    // dan sengaja dihapus", walau seluruh baris operasionalnya sendiri sudah
    // hilang dari sheet masing-masing (sesuai spesifikasi: AuditLog boleh
    // menyimpan tombstone).
    appendAudit_({requestId, userId:actor.userId, userName:actor.userName, role:actor.role,
      action:"trial_batch_delete", tanggal, divisi:"", recordKey,
      previousVersion:current.version, newVersion:newVersion,
      payloadSummary:"TOMBSTONE trial batch dihapus "+recordKey+" — "+JSON.stringify(result), status:"ok"});
    return resp;
  });
}

// Cascade delete SATU batch trial (tanggal+factory) dari semua sheet
// operasional yang diketahui berelasi. Join key dipilih per-sheet dari yang
// PALING presisi yang tersedia:
//   - FGReady py kolom Factory+Tanggal sendiri -> match langsung.
//   - FGPacking py kolom Factory sendiri -> match Tanggal+Factory+Produk.
//   - Kirim -> match Tanggal+Produk (join by produk krn tidak py kolom
//     Factory), lalu Invoice/Pembayaran/sebagian Mutasi di-cascade lewat
//     Batch Kirim/Invoice yg SUDAH pasti dihapus (presisi, bukan tebakan).
//   - Ceklis -> match Tanggal+Produk (bukan Divisi, krn backend tidak py
//     peta divisi->factory; katalog produk berbeda per factory jadi ini
//     tetap presisi). CeklisMeta HANYA dihapus kalau divisi itu benar2
//     tidak py sisa baris Ceklis lain di tanggal yg sama, supaya tidak
//     menghapus meta milik PO lain yang kebetulan sama tanggal+divisi.
//   - Retur/Reject/Jual -> tidak py kolom factory, match Tanggal+Produk
//     (Reject juga dicek InvoiceBatch kalau sudah tertaut ke invoice yg
//     dihapus, lebih presisi).
//   - StokAdj -> TIDAK py join presisi sama sekali (tidak py kolom batch
//     apa pun) — best-effort Tanggal+Produk SAJA. Ini persis kasus "bila
//     dapat diidentifikasi dengan aman" di spesifikasi: risiko residual
//     (atau overdelete) ada, tapi dibatasi Tanggal+Produk supaya tidak
//     menyentuh penyesuaian stok produk lain/tanggal lain.
function cascadeDeleteTrialBatch_(tanggal, factory){
  const counts = {};

  // 1. PO milik batch ini -> produkSet jadi join key utk sheet lain yang
  //    tidak py kolom Factory sendiri.
  const shPO = getOrCreateSheet(SHEET_PO);
  const poRows = readAllAsObjects_(shPO).filter(r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory);
  const produkSet = new Set(poRows.map(r=>str_(r.Produk)).filter(Boolean));
  counts.po = poRows.length;
  deleteRowsWhere_(shPO, r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory);

  if(!produkSet.size){
    return Object.assign(counts, {tanggal, factory, note:"Tidak ada baris PO ditemukan utk batch ini — cuma baris PO (kalau ada) yang dihapus, sheet lain tidak disentuh."});
  }
  const inSet = produk => produkSet.has(str_(produk));

  // 2. Ceklis (Tanggal+Produk).
  const shCeklis = getOrCreateSheet(SHEET_CEKLIS);
  const ceklisRows = readAllAsObjects_(shCeklis);
  const ceklisHapus = ceklisRows.filter(r => normDate_(r.Tanggal)===tanggal && inSet(r.Produk));
  const divisiTerdampak = new Set(ceklisHapus.map(r=>str_(r.Divisi)));
  counts.ceklis = ceklisHapus.length;
  deleteRowsWhere_(shCeklis, r => normDate_(r.Tanggal)===tanggal && inSet(r.Produk));

  // 3. CeklisMeta — hanya kalau divisi itu tidak py sisa Ceklis lain di
  //    tanggal yg sama (baca ulang SETELAH baris Ceklis di atas dihapus).
  const shMeta = getOrCreateSheet(SHEET_CEKLIS_META);
  const sisaCeklis = readAllAsObjects_(shCeklis);
  let metaHapus = 0;
  divisiTerdampak.forEach(div=>{
    const masihAda = sisaCeklis.some(r => normDate_(r.Tanggal)===tanggal && str_(r.Divisi)===div);
    if(!masihAda){
      deleteRowsWhere_(shMeta, r => normDate_(r.Tanggal)===tanggal && str_(r.Divisi)===div);
      metaHapus++;
    }
  });
  counts.ceklisMeta = metaHapus;

  // 4. FGPacking (Tanggal+Factory+Produk — py kolom Factory sendiri).
  const shFgp = getOrCreateSheet(SHEET_FGPACKING);
  const fgpRows = readAllAsObjects_(shFgp);
  counts.fgPacking = fgpRows.filter(r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory && inSet(r.Produk)).length;
  deleteRowsWhere_(shFgp, r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory && inSet(r.Produk));

  // 5. FGReady (Tanggal+Factory langsung, sesuai kontrak schema aslinya).
  const shFgr = getOrCreateSheet(SHEET_FGREADY);
  const fgrRows = readAllAsObjects_(shFgr);
  counts.fgReady = fgrRows.filter(r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory).length;
  deleteRowsWhere_(shFgr, r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory);

  // 6. Kirim/DO (Tanggal+Produk) — kumpulkan Batch yg terpengaruh utk
  //    cascade presisi ke Invoice/Pembayaran/Mutasi di bawah.
  const shKirim = getOrCreateSheet(SHEET_KIRIM);
  const kirimRows = readAllAsObjects_(shKirim);
  const kirimHapus = kirimRows.filter(r => normDate_(r.Tanggal)===tanggal && inSet(r.Produk));
  const batchKirimSet = new Set(kirimHapus.map(r=>str_(r.Batch)).filter(Boolean));
  counts.kirim = kirimHapus.length;
  deleteRowsWhere_(shKirim, r => normDate_(r.Tanggal)===tanggal && inSet(r.Produk));

  // 7. Invoice — join PRESISI lewat Batch = Batch Kirim yg baru dihapus
  //    (satu batch kirim = satu invoice di app ini), bukan tebakan tanggal/produk.
  const shInv = getOrCreateSheet(SHEET_INVOICE);
  const invRows = readAllAsObjects_(shInv);
  const invHapus = invRows.filter(r => batchKirimSet.has(str_(r.Batch)));
  const batchInvoiceSet = new Set(invHapus.map(r=>str_(r.Batch)));
  counts.invoice = invHapus.length;
  deleteRowsWhere_(shInv, r => batchKirimSet.has(str_(r.Batch)));

  // 8. Pembayaran — join presisi lewat Batch = Batch Invoice yg dihapus.
  const shBayar = getOrCreateSheet(SHEET_PEMBAYARAN);
  const bayarRows = readAllAsObjects_(shBayar);
  counts.pembayaran = bayarRows.filter(r => batchInvoiceSet.has(str_(r.Batch))).length;
  deleteRowsWhere_(shBayar, r => batchInvoiceSet.has(str_(r.Batch)));

  // 9. Retur (Tanggal+Produk — tidak py join batch invoice).
  const shRetur = getOrCreateSheet(SHEET_RETUR);
  const returRows = readAllAsObjects_(shRetur);
  counts.retur = returRows.filter(r => normDate_(r.Tanggal)===tanggal && inSet(r.Produk)).length;
  deleteRowsWhere_(shRetur, r => normDate_(r.Tanggal)===tanggal && inSet(r.Produk));

  // 10. Reject — union: Tanggal+Produk ATAU InvoiceBatch yg sudah dihapus.
  const shRej = getOrCreateSheet(SHEET_REJECT);
  const rejRows = readAllAsObjects_(shRej);
  const rejMatch = r => (normDate_(r.Tanggal)===tanggal && inSet(r.Produk)) || batchInvoiceSet.has(str_(r.InvoiceBatch));
  counts.reject = rejRows.filter(rejMatch).length;
  deleteRowsWhere_(shRej, rejMatch);

  // 11. Jual/penjualan outlet (Tanggal+Produk).
  const shJual = getOrCreateSheet(SHEET_JUAL);
  const jualRows = readAllAsObjects_(shJual);
  counts.jual = jualRows.filter(r => normDate_(r.Tanggal)===tanggal && inSet(r.Produk)).length;
  deleteRowsWhere_(shJual, r => normDate_(r.Tanggal)===tanggal && inSet(r.Produk));

  // 12. Mutasi — union: Tanggal+Produk ATAU BatchAsal/BatchTujuan yg dihapus
  //     (mutasi mengacu ke batch Kirim ASAL & batch Invoice TUJUAN yg baru dibuat).
  const shMut = getOrCreateSheet(SHEET_MUTASI);
  const mutRows = readAllAsObjects_(shMut);
  const mutMatch = r => (normDate_(r.Tanggal)===tanggal && inSet(r.Produk))
    || batchKirimSet.has(str_(r.BatchAsal)) || batchKirimSet.has(str_(r.BatchTujuan))
    || batchInvoiceSet.has(str_(r.BatchAsal)) || batchInvoiceSet.has(str_(r.BatchTujuan));
  counts.mutasi = mutRows.filter(mutMatch).length;
  deleteRowsWhere_(shMut, mutMatch);

  // 13. StokAdj — best-effort Tanggal+Produk SAJA (lihat catatan besar di
  //     atas fungsi ini soal kenapa ini satu-satunya sheet tanpa join presisi).
  const shStok = getOrCreateSheet(SHEET_STOKADJ);
  const stokRows = readAllAsObjects_(shStok);
  counts.stokAdj = stokRows.filter(r => normDate_(r.Tanggal)===tanggal && inSet(r.Produk)).length;
  deleteRowsWhere_(shStok, r => normDate_(r.Tanggal)===tanggal && inSet(r.Produk));

  return Object.assign(counts, {tanggal, factory, produkCount:produkSet.size});
}

// ============================================================
//  Handler — Ceklis Produksi (recordType "ceklis", key = tanggal|divisi)
// ============================================================

// LAMA: replace penuh (client sudah menghitung total kumulatif sendiri lalu
// kirim ULANG semua baris). Dipertahankan utk backward-compat, TAPI ini
// artinya kalau 2 device menyimpan hampir bersamaan utk DIVISI YANG SAMA,
// yang menang adalah yang expectedVersion-nya cocok — yang kalah harus
// reload (VERSION_CONFLICT), TIDAK ditimpa diam-diam sejak versioning ini
// dipasang (sebelumnya: race biasa, siapa nulis belakangan menang telak).
function handleCeklisProduksiReplace_(payload, actor){
  const tanggal = normDate_(payload.tanggal), divisi = str_(payload.divisi);
  const recordKey = tanggal + "|" + divisi;
  return mutateVersioned_({
    recordType:"ceklis", recordKey, requestId:payload.requestId, expectedVersion:payload.expectedVersion,
    actor, action:"production_progress", tanggal, divisi
  }, function(){
    writeCeklisRows_(tanggal, divisi, payload.rows||[]);
    // DRAFT, bukan submitted — endpoint legacy ini dipakai utk Save Progress,
    // bukan submit final. Lihat catatan bug #3 di header file.
    touchCeklisMetaDraft_(tanggal, divisi, actor);
    return {record:{tanggal, divisi, rowCount:(payload.rows||[]).length}, payloadSummary:(payload.rows||[]).length+" baris ceklis (replace) utk "+recordKey};
  });
}

// BARU (section 6): delta-safe. rows:[{kode,produk,kategori,target,status,
// actualDelta,rejectDelta,keterangan}] — server yang menjumlahkan ke total
// kumulatif TERKINI (dibaca ulang di dalam lock, bukan dipercaya dari
// client), jadi 2 submit "+25" dari device berbeda utk SKU yang sama tidak
// bisa saling menimpa jadi cuma "+25" sekali (asalkan expectedVersion
// dikirim benar; kalau device B sudah kadaluwarsa versinya, dia akan
// ditolak VERSION_CONFLICT dan harus reload dulu sebelum submit lagi —
// TIDAK otomatis di-retry dgn versi baru oleh backend, itu keputusan sadar
// supaya operator tahu ada perubahan lain sebelum datanya ikut bercampur).
function handleProductionProgressDelta_(payload, actor){
  const strictErr = requireStrictVersioning_(payload);
  if(strictErr) return strictErr;
  const tanggal = normDate_(payload.tanggal), divisi = str_(payload.divisi);
  const recordKey = tanggal + "|" + divisi;
  return mutateVersioned_({
    recordType:"ceklis", recordKey, requestId:payload.requestId, expectedVersion:payload.expectedVersion,
    actor, action:"production_progress", tanggal, divisi
  }, function(){
    const sh = getOrCreateSheet(SHEET_CEKLIS);
    const existing = readAllAsObjects_(sh).filter(r => normDate_(r.Tanggal)===tanggal && str_(r.Divisi)===divisi);
    const map = {};
    existing.forEach(r=>{ map[str_(r.Kode)+"\x1f"+str_(r.Produk)] = {
      Tanggal:tanggal, Divisi:divisi, Kode:str_(r.Kode), Produk:str_(r.Produk), Kategori:str_(r.Kategori),
      Target:num_(r.Target), Status:str_(r.Status), Aktual:num_(r.Aktual), Reject:num_(r.Reject), Keterangan:str_(r.Keterangan)
    }; });
    (payload.rows||[]).forEach(fr=>{
      const key = str_(fr.kode)+"\x1f"+str_(fr.produk);
      const prev = map[key];
      if(prev){
        prev.Aktual = num_(prev.Aktual) + num_(fr.actualDelta);
        prev.Reject = num_(prev.Reject) + num_(fr.rejectDelta);
        if(fr.status) prev.Status = str_(fr.status);
        if(fr.target!=null) prev.Target = num_(fr.target);
        if(fr.keterangan) prev.Keterangan = prev.Keterangan ? (prev.Keterangan+"; "+fr.keterangan) : str_(fr.keterangan);
      } else {
        map[key] = {Tanggal:tanggal, Divisi:divisi, Kode:str_(fr.kode), Produk:str_(fr.produk), Kategori:str_(fr.kategori),
          Target:num_(fr.target), Status:str_(fr.status)||"sesuai", Aktual:num_(fr.actualDelta), Reject:num_(fr.rejectDelta), Keterangan:str_(fr.keterangan)};
      }
    });
    const rows = Object.values(map).map(r=>Object.assign({}, r, {UpdatedAt:new Date()}));
    deleteRowsWhere_(sh, r => normDate_(r.Tanggal)===tanggal && str_(r.Divisi)===divisi);
    appendObjects_(sh, HEADERS[SHEET_CEKLIS], rows);
    // DRAFT — ini Save Progress, BUKAN submit final. SubmittedAt tidak diisi
    // di sini sama sekali (bug #2 di header file).
    touchCeklisMetaDraft_(tanggal, divisi, actor);
    return {record:{tanggal, divisi, rowCount:rows.length}, payloadSummary:(payload.rows||[]).length+" delta diterapkan ke "+recordKey};
  });
}
function writeCeklisRows_(tanggal, divisi, rows){
  const sh = getOrCreateSheet(SHEET_CEKLIS);
  deleteRowsWhere_(sh, r => normDate_(r.Tanggal)===tanggal && str_(r.Divisi)===divisi);
  const now = new Date();
  appendObjects_(sh, HEADERS[SHEET_CEKLIS], rows.map(r=>({
    Tanggal: tanggal, Divisi: divisi, Kode: str_(r.kode), Produk: str_(r.produk), Kategori: str_(r.kategori),
    Target: num_(r.target), Status: str_(r.status), Aktual: num_(r.aktual), Reject: num_(r.reject),
    Keterangan: str_(r.keterangan), UpdatedAt: now
  })));
}
// ---- Metadata Ceklis: 4 fungsi TERPISAH, masing-masing HANYA dipanggil dari
// SATU jenis aksi. Jangan gabung lagi jadi satu fungsi serba-bisa seperti
// touchCeklisMetaSubmitted_ yang lama — itu sebabnya Save Progress (delta
// atau legacy replace) dulu ikut mengisi SubmittedAt padahal belum di-submit.

// productionProgress & ceklisProduksi (legacy replace) -> DRAFT. SubmittedAt
// SENGAJA TIDAK diisi di sini (dipertahankan apa adanya kalau sudah pernah
// ada dari submit sebelumnya — draft baru TIDAK menghapus riwayat submit
// terakhir, tapi juga TIDAK menciptakan SubmittedAt baru).
function touchCeklisMetaDraft_(tanggal, divisi, actor){
  const shM = getOrCreateSheet(SHEET_CEKLIS_META);
  const existing = readAllAsObjects_(shM).find(m=>normDate_(m.Tanggal)===tanggal && str_(m.Divisi)===divisi);
  deleteRowsWhere_(shM, m => normDate_(m.Tanggal)===tanggal && str_(m.Divisi)===divisi);
  appendObjects_(shM, HEADERS[SHEET_CEKLIS_META], [{
    Tanggal: tanggal, Divisi: divisi, Status: CEKLIS_STATUS_DRAFT,
    SubmittedAt: existing ? str_(existing.SubmittedAt) : "",
    Closed: "false", ClosedAt: "", ClosedBy: "",
    ReopenReason: existing ? str_(existing.ReopenReason) : ""
  }]);
}
// ceklisSubmit -> SUBMITTED. Satu-satunya tempat SubmittedAt diisi.
function markCeklisSubmitted_(tanggal, divisi, actor){
  const shM = getOrCreateSheet(SHEET_CEKLIS_META);
  deleteRowsWhere_(shM, m => normDate_(m.Tanggal)===tanggal && str_(m.Divisi)===divisi);
  const now = new Date();
  appendObjects_(shM, HEADERS[SHEET_CEKLIS_META], [{
    Tanggal: tanggal, Divisi: divisi, Status: CEKLIS_STATUS_SUBMITTED,
    SubmittedAt: now.toLocaleString("id-ID"), Closed: "true", ClosedAt: now.toLocaleString("id-ID"),
    ClosedBy: actor.userName||"", ReopenReason: ""
  }]);
}
// ceklisReopen -> REOPENED. SubmittedAt (riwayat submit terakhir) dipertahankan
// apa adanya utk jejak audit, TAPI Status eksplisit "reopened" itulah yang
// harus dibaca konsumen manapun — bukan menyimpulkan dari ada/tidaknya SubmittedAt.
function markCeklisReopened_(tanggal, divisi, actor, reason){
  const shM = getOrCreateSheet(SHEET_CEKLIS_META);
  const existing = readAllAsObjects_(shM).find(m=>normDate_(m.Tanggal)===tanggal && str_(m.Divisi)===divisi) || {};
  deleteRowsWhere_(shM, m => normDate_(m.Tanggal)===tanggal && str_(m.Divisi)===divisi);
  appendObjects_(shM, HEADERS[SHEET_CEKLIS_META], [{
    Tanggal: tanggal, Divisi: divisi, Status: CEKLIS_STATUS_REOPENED,
    SubmittedAt: str_(existing.SubmittedAt)||"", Closed: "false", ClosedAt: "",
    ClosedBy: "", ReopenReason: reason
  }]);
}
// fgReady (verifikasi FG) -> VERIFIED_FG, utk SETIAP divisi sumber yang
// disebut di payload.sourceVersions. Kalau divisi itu belum pernah punya
// CeklisMeta sama sekali (FG mengklaim sumber yg tidak pernah ada ceklis-nya),
// TIDAK membuat baris baru — tidak ada apa pun utk ditandai terverifikasi.
function markCeklisVerifiedFg_(tanggal, divisi, actor){
  const shM = getOrCreateSheet(SHEET_CEKLIS_META);
  const existing = readAllAsObjects_(shM).find(m=>normDate_(m.Tanggal)===tanggal && str_(m.Divisi)===divisi);
  if(!existing) return;
  deleteRowsWhere_(shM, m => normDate_(m.Tanggal)===tanggal && str_(m.Divisi)===divisi);
  appendObjects_(shM, HEADERS[SHEET_CEKLIS_META], [Object.assign({}, existing, {
    Status: CEKLIS_STATUS_VERIFIED_FG
  })]);
}

// Section 8: SUBMIT harus atomik — verifikasi versi, tandai submitted/closed,
// naikkan versi, audit, semua di dalam SATU critical section (bukan langkah
// terpisah yang bisa berhenti di tengah).
function handleCeklisSubmit_(payload, actor){
  const strictErr = requireStrictVersioning_(payload);
  if(strictErr) return strictErr;
  const tanggal = normDate_(payload.tanggal), divisi = str_(payload.divisi);
  const recordKey = tanggal + "|" + divisi;
  return mutateVersioned_({
    recordType:"ceklis", recordKey, requestId:payload.requestId, expectedVersion:payload.expectedVersion,
    actor, action:"production_submit", tanggal, divisi
  }, function(){
    // Kalau ada baris final (delta terakhir) ikut dikirim bersamaan dgn submit, terapkan dulu.
    if(Array.isArray(payload.rows) && payload.rows.length){
      const sh = getOrCreateSheet(SHEET_CEKLIS);
      const existing = readAllAsObjects_(sh).filter(r => normDate_(r.Tanggal)===tanggal && str_(r.Divisi)===divisi);
      const map = {};
      existing.forEach(r=>{ map[str_(r.Kode)+"\x1f"+str_(r.Produk)] = r; });
      payload.rows.forEach(fr=>{
        const key = str_(fr.kode)+"\x1f"+str_(fr.produk);
        const prev = map[key];
        if(prev){ prev.Aktual = num_(prev.Aktual)+num_(fr.actualDelta||0); prev.Reject = num_(prev.Reject)+num_(fr.rejectDelta||0); }
        else map[key] = {Tanggal:tanggal, Divisi:divisi, Kode:str_(fr.kode), Produk:str_(fr.produk), Kategori:str_(fr.kategori),
          Target:num_(fr.target), Status:str_(fr.status)||"sesuai", Aktual:num_(fr.actualDelta||0), Reject:num_(fr.rejectDelta||0), Keterangan:str_(fr.keterangan)};
      });
      deleteRowsWhere_(sh, r => normDate_(r.Tanggal)===tanggal && str_(r.Divisi)===divisi);
      appendObjects_(sh, HEADERS[SHEET_CEKLIS], Object.values(map).map(r=>Object.assign({}, r, {UpdatedAt:new Date()})));
    }
    // SUBMITTED — satu-satunya tempat SubmittedAt benar-benar diisi.
    markCeklisSubmitted_(tanggal, divisi, actor);
    return {record:{tanggal, divisi, closed:true, status:CEKLIS_STATUS_SUBMITTED}, payloadSummary:"Submit final "+recordKey};
  });
}

// Section 9: REOPEN wajib alasan + audit + lock + versi. Pengecekan "operator
// tidak boleh reopen submission sendiri kecuali permission mengizinkan" TIDAK
// bisa ditegakkan di sini krn TIDAK ADA sistem permission/auth nyata — role
// yang dikirim client cuma diklaim sendiri (lihat actorFromPayload_ & blocker
// AUTH IDENTITY). Yang BISA dipastikan di sini: alasan wajib diisi & tercatat.
function handleCeklisReopen_(payload, actor){
  const strictErr = requireStrictVersioning_(payload);
  if(strictErr) return strictErr;
  const tanggal = normDate_(payload.tanggal), divisi = str_(payload.divisi);
  const recordKey = tanggal + "|" + divisi;
  const reason = str_(payload.reason).trim();
  if(!reason){
    return {ok:false, code:"REASON_REQUIRED", message:"Alasan reopen wajib diisi."};
  }
  return mutateVersioned_({
    recordType:"ceklis", recordKey, requestId:payload.requestId, expectedVersion:payload.expectedVersion,
    actor, action:"production_reopen", tanggal, divisi
  }, function(){
    markCeklisReopened_(tanggal, divisi, actor, reason);
    return {record:{tanggal, divisi, closed:false, status:CEKLIS_STATUS_REOPENED, reason}, payloadSummary:"Reopen "+recordKey+": "+reason};
  });
}

// ============================================================
//  Handler — FG/Packing (recordType "fgPacking", key = tanggal|factory)
// ============================================================

// BUG DIPERBAIKI (catatan #6 di header): payload sekarang WAJIB kirim array
// `rows` eksplisit {kode,produk,toko,qty,status,keterangan} — BUKAN LAGI map
// "<kode>\x1f<produk>|<toko>":qty. Kalau client lama (belum diupgrade) masih
// kirim `packed` map, tetap dicoba diuraikan sbg fallback SUPAYA TIDAK
// mendadak putus total — tapi hasilnya tetap berpotensi kolom Kode kurang
// bersih utk kasus lama itu (persis bug lama). App HARUS diupgrade ke `rows`.
function handleFgPacking_(payload, actor){
  const tanggal = normDate_(payload.tanggal), factory = str_(payload.factory);
  const recordKey = tanggal + "|" + factory;
  return mutateVersioned_({
    recordType:"fgPacking", recordKey, requestId:payload.requestId, expectedVersion:payload.expectedVersion,
    actor, action:"fg_verify", tanggal, divisi:factory
  }, function(){
    const sh = getOrCreateSheet(SHEET_FGPACKING);
    deleteRowsWhere_(sh, r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory);
    const now = new Date();
    let rowsIn = [];
    if(Array.isArray(payload.rows)){
      rowsIn = payload.rows.map(r=>({kode:str_(r.kode), produk:str_(r.produk), toko:str_(r.toko), qty:num_(r.qty), status:str_(r.status)||"belum", keterangan:str_(r.keterangan)}));
    } else if(payload.packed && typeof payload.packed==="object"){
      // Fallback kompatibilitas client lama — lihat catatan di atas.
      rowsIn = Object.keys(payload.packed).map(key=>{
        const idx = key.lastIndexOf("|");
        const skuPart = key.slice(0, idx), toko = key.slice(idx+1);
        const sep = skuPart.indexOf("\x1f");
        const kode = sep>=0 ? skuPart.slice(0,sep) : skuPart;
        const produk = sep>=0 ? skuPart.slice(sep+1) : "";
        const val = payload.packed[key];
        const isObj = val && typeof val==="object";
        return {kode, produk, toko, qty:num_(isObj?val.qty:val), status:isObj?str_(val.status):"sesuai", keterangan:isObj?str_(val.keterangan):""};
      });
    }
    appendObjects_(sh, HEADERS[SHEET_FGPACKING], rowsIn.map(r=>({
      Tanggal: tanggal, Factory: factory, Kode: r.kode, Produk: r.produk, Toko: r.toko,
      Qty: r.qty, Status: r.status, Keterangan: r.keterangan, UpdatedAt: now
    })));
    return {record:{tanggal, factory, rowCount:rowsIn.length}, payloadSummary:rowsIn.length+" baris packing utk "+recordKey};
  });
}

// Section 17: FG hanya boleh memverifikasi versi PRODUKSI (ceklis) yang
// SUDAH diverifikasi/dipakai sblm packing ready ditandai. sourceVersions
// (kalau dikirim client, {divisi:version}) dibandingkan ke versi ceklis
// TERKINI utk tanggal ini — kalau beda, dikasih WARNING (bukan hard block,
// konsisten dgn perilaku lunak fgTandaiSiap yang sudah ada), supaya operator
// FG tahu produksi sumbernya berubah lagi setelah dia mulai verifikasi.
function handleFgReady_(payload, actor){
  const tanggal = normDate_(payload.tanggal), factory = str_(payload.factory);
  const recordKey = tanggal + "|" + factory;
  return mutateVersioned_({
    recordType:"fgReady", recordKey, requestId:payload.requestId, expectedVersion:payload.expectedVersion,
    actor, action:"fg_verify", tanggal, divisi:factory
  }, function(){
    const sh = getOrCreateSheet(SHEET_FGREADY);
    deleteRowsWhere_(sh, r => normDate_(r.Tanggal)===tanggal && str_(r.Factory)===factory);
    appendObjects_(sh, HEADERS[SHEET_FGREADY], [{
      Tanggal:tanggal, Factory:factory, ReadyAt: str_(payload.readyAt), SourceVersionJSON: JSON.stringify(payload.sourceVersions||{})
    }]);
    let warning = null;
    if(payload.sourceVersions && typeof payload.sourceVersions==="object"){
      const stale = Object.keys(payload.sourceVersions).filter(divisi=>{
        const cur = getVersion_("ceklis", tanggal+"|"+divisi).version;
        return cur !== num_(payload.sourceVersions[divisi]);
      });
      if(stale.length) warning = {code:"STALE_PRODUCTION_SOURCE", divisi: stale, message:"Produksi sumber ("+stale.join(", ")+") berubah lagi setelah FG mulai verifikasi — cek ulang sebelum kirim."};
      // VERIFIED_FG utk tiap divisi sumber — dicatat terlepas dari warning di
      // atas (FG memang sudah memakai/memverifikasi versi itu; warning cuma
      // memberitahu kalau sumbernya berubah LAGI sesudahnya).
      Object.keys(payload.sourceVersions).forEach(divisi=>{ markCeklisVerifiedFg_(tanggal, divisi, actor); });
    }
    return {record:{tanggal, factory, readyAt:str_(payload.readyAt)}, warning, payloadSummary:"FG ready "+recordKey};
  });
}

// ============================================================
//  Handler — append-only (Kirim/Retur/Jual/Reject/Pembayaran/Mutasi/StokAdj/Pesanan)
//  Lock + idempotent, TANPA version-conflict check (append tidak menimpa
//  baris orang lain — resiko konkurensinya beda: race getLastRow() [dicegah
//  lock] dan duplikasi krn retry [dicegah requestId]).
// ============================================================
function handleAppendTransaksi_(sheetName, actionName, payload, actor, headerFields, singleObjectMode){
  const rows = singleObjectMode ? [payload] : (payload.rows||[]);
  const tanggal = normDate_(payload.tanggal || (rows[0]&&rows[0].tgl) || "");
  const recordKey = sheetName+":"+(singleObjectMode ? str_(payload.id) : rows.map(r=>r.id).join(","));
  return mutateAppend_({
    recordType:sheetName, recordKey, requestId:payload.requestId, actor, action:actionName, tanggal, divisi:""
  }, function(){
    const sh = getOrCreateSheet(sheetName);
    const now = new Date();
    const objects = rows.map(r=>{
      const o = { Id: str_(r.id), Batch: str_(r.batch), Tanggal: normDate_(r.tgl||r.tanggal), Toko: str_(r.toko),
        Produk: str_(r.produk), Qty: num_(r.qty) };
      if(headerFields.indexOf("NoSJ")!==-1) o.NoSJ = str_(r.noSJ);
      if(headerFields.indexOf("Pengemudi")!==-1) o.Pengemudi = str_(r.pengemudi);
      if(headerFields.indexOf("Kendaraan")!==-1) o.Kendaraan = str_(r.kendaraan);
      if(headerFields.indexOf("Alasan")!==-1) o.Alasan = str_(r.alasan);
      if(headerFields.indexOf("InvoiceNo")!==-1) o.InvoiceNo = str_(r.invoiceNo);
      if(headerFields.indexOf("Jumlah")!==-1) o.Jumlah = num_(r.jumlah);
      if(headerFields.indexOf("Cara")!==-1) o.Cara = str_(r.cara);
      if(headerFields.indexOf("Keterangan")!==-1) o.Keterangan = str_(r.keterangan);
      o.CreatedAt = now;
      return o;
    });
    appendObjects_(sh, HEADERS[sheetName], objects);
    return {record:{rowCount:objects.length}, payloadSummary:objects.length+" baris ditambahkan ke "+sheetName};
  });
}
function handleHapusById_(sheetName, actionName, payload, actor){
  const id = str_(payload.id);
  return mutateAppend_({
    recordType:sheetName, recordKey:sheetName+":"+id, requestId:payload.requestId, actor, action:actionName, tanggal:"", divisi:""
  }, function(){
    const sh = getOrCreateSheet(sheetName);
    deleteRowsWhere_(sh, r => str_(r.Id) === id);
    return {record:{id}, payloadSummary:"Hapus id="+id+" dari "+sheetName};
  });
}
function handleHapusByBatch_(sheetName, actionName, payload, actor){
  const batch = str_(payload.batch);
  return mutateAppend_({
    recordType:sheetName, recordKey:sheetName+":"+batch, requestId:payload.requestId, actor, action:actionName, tanggal:"", divisi:""
  }, function(){
    const sh = getOrCreateSheet(sheetName);
    deleteRowsWhere_(sh, r => str_(r.Batch) === batch);
    return {record:{batch}, payloadSummary:"Hapus batch="+batch+" dari "+sheetName};
  });
}

// Reject: sebelumnya TIDAK ADA case-nya di doPost — payload frontend {jenis:"reject", rows:[...]}
function handleReject_(payload, actor){
  const rows = payload.rows||[];
  return mutateAppend_({
    recordType:SHEET_REJECT, recordKey:SHEET_REJECT+":"+rows.map(r=>r.id).join(","),
    requestId:payload.requestId, actor, action:"reject_append", tanggal:normDate_((rows[0]||{}).tgl), divisi:""
  }, function(){
    const sh = getOrCreateSheet(SHEET_REJECT);
    const now = new Date();
    appendObjects_(sh, HEADERS[SHEET_REJECT], rows.map(r=>({
      Id:str_(r.id), Batch:str_(r.batch), Tanggal:normDate_(r.tgl), Toko:str_(r.toko), Produk:str_(r.produk),
      Qty:num_(r.qty), Alasan:str_(r.alasan), Resolusi:str_(r.resolusi), Nilai:num_(r.nilai),
      InvoiceBatch:str_(r.invoiceBatch), CreatedAt: now
    })));
    return {record:{rowCount:rows.length}, payloadSummary:rows.length+" baris reject"};
  });
}
function handleMutasi_(payload, actor){
  return mutateAppend_({
    recordType:SHEET_MUTASI, recordKey:SHEET_MUTASI+":"+str_(payload.id),
    requestId:payload.requestId, actor, action:"mutasi_append", tanggal:normDate_(payload.tgl), divisi:""
  }, function(){
    const sh = getOrCreateSheet(SHEET_MUTASI);
    appendObjects_(sh, HEADERS[SHEET_MUTASI], [{
      Id:str_(payload.id), Tanggal:normDate_(payload.tgl), Produk:str_(payload.produk), Asal:str_(payload.asal),
      Tujuan:str_(payload.tujuan), Qty:num_(payload.qty), Keterangan:str_(payload.keterangan),
      BatchAsal:str_(payload.batchAsal), BatchTujuan:str_(payload.batchTujuan), CreatedAt:new Date()
    }]);
    return {record:{id:payload.id}, payloadSummary:"Mutasi "+payload.produk};
  });
}
function handleStokAdj_(payload, actor){
  return mutateAppend_({
    recordType:SHEET_STOKADJ, recordKey:SHEET_STOKADJ+":"+str_(payload.id),
    requestId:payload.requestId, actor, action:"stok_adjustment", tanggal:normDate_(payload.tgl), divisi:""
  }, function(){
    const sh = getOrCreateSheet(SHEET_STOKADJ);
    appendObjects_(sh, HEADERS[SHEET_STOKADJ], [{
      Id:str_(payload.id), Tanggal:normDate_(payload.tgl), Produk:str_(payload.produk), Tipe:str_(payload.tipe),
      Qty:num_(payload.qty), Keterangan:str_(payload.keterangan), CreatedAt:new Date()
    }]);
    return {record:{id:payload.id}, payloadSummary:"StokAdj "+payload.produk};
  });
}
function handlePesanan_(payload, actor){
  return mutateAppend_({
    recordType:SHEET_PESANAN, recordKey:SHEET_PESANAN+":"+str_(payload.id),
    requestId:payload.requestId, actor, action:"pesanan_append", tanggal:"", divisi:""
  }, function(){
    const sh = getOrCreateSheet(SHEET_PESANAN);
    appendObjects_(sh, HEADERS[SHEET_PESANAN], [{
      Id:str_(payload.id), No:str_(payload.no), Status:str_(payload.status||"baru"),
      PayloadJSON: JSON.stringify(payload), CreatedAt:new Date(), UpdatedAt:new Date()
    }]);
    return {record:{id:payload.id}, payloadSummary:"Pesanan "+payload.no};
  });
}
function handlePesananStatus_(payload, actor){
  return mutateAppend_({
    recordType:SHEET_PESANAN, recordKey:SHEET_PESANAN+":"+str_(payload.id)+":status",
    requestId:payload.requestId, actor, action:"pesanan_status", tanggal:"", divisi:""
  }, function(){
    const sh = getOrCreateSheet(SHEET_PESANAN);
    const rows = readAllAsObjects_(sh);
    const found = rows.find(r=>str_(r.Id)===str_(payload.id));
    deleteRowsWhere_(sh, r=>str_(r.Id)===str_(payload.id));
    appendObjects_(sh, HEADERS[SHEET_PESANAN], [{
      Id:str_(payload.id), No: found?str_(found.No):str_(payload.no), Status:str_(payload.status),
      PayloadJSON: found?found.PayloadJSON:"{}", CreatedAt: found?found.CreatedAt:new Date(), UpdatedAt:new Date()
    }]);
    return {record:{id:payload.id, status:payload.status}, payloadSummary:"Pesanan "+payload.id+" -> "+payload.status};
  });
}

// ============================================================
//  Handler — master/lookup data (Divisi/Produk/Toko/MasterProduk/TokoTipe/Settings/Invoice)
// ============================================================
function handleMasterUpsert_(jenis, payload, actor){
  const nama = str_(payload.nama).trim();
  if(!nama) return {ok:false, code:"BAD_PAYLOAD", message:"nama kosong"};
  return mutateAppend_({
    recordType:SHEET_MASTER, recordKey:jenis+":"+nama, requestId:payload.requestId, actor, action:"master_upsert", tanggal:"", divisi:""
  }, function(){
    const sh = getOrCreateSheet(SHEET_MASTER);
    const rows = readAllAsObjects_(sh);
    const exists = rows.some(r => str_(r.Jenis).toLowerCase()===jenis && str_(r.Nama)===nama);
    if(!exists) appendObjects_(sh, HEADERS[SHEET_MASTER], [{Jenis:jenis, Nama:nama}]);
    return {record:{jenis, nama}, payloadSummary:"Master "+jenis+" += "+nama};
  });
}
function handleMasterProdukUpsert_(payload, actor){
  const produk = str_(payload.produk).trim();
  if(!produk) return {ok:false, code:"BAD_PAYLOAD", message:"produk kosong"};
  return mutateVersioned_({
    recordType:"masterProduk", recordKey:produk, requestId:payload.requestId, expectedVersion:payload.expectedVersion,
    actor, action:"master_produk_upsert", tanggal:"", divisi:""
  }, function(){
    const sh = getOrCreateSheet(SHEET_MASTERPRODUK);
    deleteRowsWhere_(sh, r => str_(r.Produk)===produk);
    appendObjects_(sh, HEADERS[SHEET_MASTERPRODUK], [{
      Produk: produk, Kategori: str_(payload.kategori), Divisi: str_(payload.divisi),
      HPP: num_(payload.hpp), Harga: num_(payload.harga), Aktif: payload.aktif===false ? "false" : "true", UpdatedAt: new Date()
    }]);
    return {record:{produk}, payloadSummary:"MasterProduk "+produk};
  });
}
function handleTokoTipeUpsert_(payload, actor){
  const toko = str_(payload.toko).trim();
  if(!toko) return {ok:false, code:"BAD_PAYLOAD", message:"toko kosong"};
  return mutateVersioned_({
    recordType:"tokoTipe", recordKey:toko, requestId:payload.requestId, expectedVersion:payload.expectedVersion,
    actor, action:"toko_tipe_upsert", tanggal:"", divisi:""
  }, function(){
    const sh = getOrCreateSheet(SHEET_TOKOTIPE);
    deleteRowsWhere_(sh, r => str_(r.Toko)===toko);
    appendObjects_(sh, HEADERS[SHEET_TOKOTIPE], [{Toko: toko, Tipe: str_(payload.tipe), UpdatedAt: new Date()}]);
    return {record:{toko}, payloadSummary:"TokoTipe "+toko+"="+payload.tipe};
  });
}
function handleSettingsUpsert_(payload, actor){
  return mutateVersioned_({
    recordType:"settings", recordKey:"__settings__", requestId:payload.requestId, expectedVersion:payload.expectedVersion,
    actor, action:"settings_upsert", tanggal:"", divisi:""
  }, function(){
    const sh = getOrCreateSheet(SHEET_SETTINGS);
    Object.keys(payload).forEach(key=>{
      if(["jenis","requestId","expectedVersion","userId","userName","role"].indexOf(key)!==-1) return;
      if(payload[key]===undefined || payload[key]===null) return;
      deleteRowsWhere_(sh, r => str_(r.Key)===key);
      appendObjects_(sh, HEADERS[SHEET_SETTINGS], [{Key:key, Value:String(payload[key])}]);
    });
    return {record:{}, payloadSummary:"Settings diperbarui"};
  });
}
function handleInvoiceUpsert_(payload, actor){
  const batch = str_(payload.batch).trim();
  if(!batch) return {ok:false, code:"BAD_PAYLOAD", message:"batch kosong"};
  return mutateVersioned_({
    recordType:"invoice", recordKey:batch, requestId:payload.requestId, expectedVersion:payload.expectedVersion,
    actor, action:"invoice_upsert", tanggal:normDate_(payload.tanggal), divisi:""
  }, function(){
    const sh = getOrCreateSheet(SHEET_INVOICE);
    const existing = readAllAsObjects_(sh).find(r => str_(r.Batch)===batch);
    deleteRowsWhere_(sh, r => str_(r.Batch)===batch);
    appendObjects_(sh, HEADERS[SHEET_INVOICE], [{
      InvoiceNo: str_(payload.invoiceNo), Batch: batch, Tanggal: normDate_(payload.tanggal),
      Toko: str_(payload.toko), NoSJ: str_(payload.noSJ), ItemsJSON: JSON.stringify(payload.items||[]),
      Total: num_(payload.total), CreatedAt: existing ? existing.CreatedAt : new Date(), UpdatedAt: new Date()
    }]);
    return {record:{batch}, payloadSummary:"Invoice "+batch};
  });
}

/**
 * Salin seluruh spreadsheet jadi file arsip baru (Google Drive). Tidak
 * berubah dari versi sebelumnya.
 */
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

/* ===== Retur (sudah ada sebelumnya — TIDAK diubah) ===== */
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
