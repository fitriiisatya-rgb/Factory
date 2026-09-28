# Amor Factory System — Migration 0016: Replacement Reject End-to-End — cPanel, Non-Teknis

**Baca ini SEBELUM upload/extract. Paket ini BUTUH menjalankan satu
migrasi database baru (0016).**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

**Update (source deep-check sebelum UAT)**: paket ini SEKARANG juga
mencakup perbaikan kontrol akses (role PRODUCTION/FG_PACKING kini benar-
benar dibatasi sesuai divisi/pabrik yang ditugaskan, dan halaman Admin
Replacement Reject kini benar-benar ditolak — bukan cuma disembunyikan
dari menu — untuk role yang tidak berwenang) dan perbaikan keamanan
migrasi (migrasi 0016 sekarang aman dijalankan ulang kapan pun, walau
sebagian sudah pernah diterapkan sebelumnya). **Migrasi 0016 masih
migrasi yang SAMA — belum pernah diterapkan ke server manapun — jadi
tidak ada langkah tambahan di luar yang sudah dijelaskan di bawah ini.**

---

## Kenapa paket ini ada

Selama ini, kalau toko melaporkan Reject saat menerima kiriman, Admin
bisa memverifikasi laporan tersebut, tapi sistem TIDAK punya cara resmi
untuk mencatat: apakah reject ini **Reject Final (barang tidak diganti,
kerugian ditanggung factory)** atau **Kirim Ulang / Ganti Produk (barang
akan dikirim ulang)**. Paket ini menambahkan alur keputusan itu, beserta
seluruh proses lanjutannya: cek FG bebas dulu, baru masuk kebutuhan
Produksi kalau memang kurang, lalu DO Replacement terpisah (tanpa harga),
sampai toko menerima kembali barang penggantinya.

---

## Yang PENTING dipahami

- Migrasi 0016 **HANYA menambah 4 tabel baru** (`replacement_demand`,
  `replacement_demand_fg_allocation`, `replacement_do`,
  `replacement_do_shipment_item`) dan menambah beberapa **kolom baru**
  (bukan mengubah/menghapus kolom lama) pada tabel `shipment_receipt_item`,
  `shipment`, dan `stock_ledger`. **Tidak ada satu baris data pun** (PO,
  Produksi, FG, DO, Pengiriman, Konfirmasi Toko, User, dll) yang dihapus
  atau diubah oleh migrasi ini.
- Migrasi 0016 **aman dijalankan** — memakai `CREATE TABLE IF NOT EXISTS`
  dan `ADD COLUMN IF NOT EXISTS`, jadi kalau entah bagaimana sebagian
  sudah ada, migrasi ini tidak akan error.
- **Reject Final dan Kirim Ulang adalah keputusan PERMANEN, sekali jalan**
  — begitu diputuskan, tidak bisa diputuskan ulang untuk baris reject yang
  sama.
- **Reject Final TIDAK PERNAH** mengembalikan stok FG, TIDAK mengubah
  target PO asli, TIDAK membuat DO baru, dan TIDAK membuat Replacement
  Demand sama sekali.
- **Kirim Ulang** membuat satu **Replacement Demand** terpisah — ini
  BUKAN revisi PO, dan tidak pernah menambah target PO Reguler asli.
- Fitur ini mengecek FG bebas **lebih dulu** (tanpa mencuri FG yang sudah
  jadi milik toko lain atau Pesanan Khusus aktif) — sisa kekurangannya
  baru masuk sebagai **Kebutuhan Produksi Replacement Reject**, terpisah
  dari PO Reguler di halaman Task per Divisi.
- DO Replacement adalah dokumen **terpisah, tanpa harga** — bukan
  ditambahkan ke DO asli.
- Penerimaan barang Replacement memakai alur **Konfirmasi Toko yang
  sudah ada** — tidak ada halaman baru untuk toko.

---

## Cara pasang (cPanel, tanpa command line)

1. **Backup dulu.** Di cPanel → phpMyAdmin, export (backup) database Anda
   saat ini. Ini WAJIB sebelum migrasi apa pun.
2. **Upload & extract.** Upload `amor-factory-replacement-reject.zip` ke
   `public_html/factory/`, lalu extract — ini akan MENIMPA file lama
   dengan versi baru (aman, tidak menghapus folder `api/app/config/`).
3. **Buka halaman Upgrade Database.** Buka
   `https://domainanda.com/factory/api/_upgrade/` di browser (login
   sebagai Admin dulu jika diminta).
4. **VERIFIKASI PENTING sebelum menekan apa pun**: pastikan halaman
   tersebut menunjukkan:
   - "Sudah diterapkan" masih mencantumkan
     `0015_fg_store_packing_submission.php` (TIDAK hilang, TIDAK
     berubah).
   - "Menunggu diterapkan" **HANYA** berisi SATU baris:
     `0016_replacement_reject.php`.

   Kalau yang muncul BUKAN seperti ini, **STOP** — jangan tekan apa pun,
   laporkan kembali screenshot halaman tersebut.
5. **Terapkan.** Centang kotak konfirmasi, lalu tekan tombol "Terapkan
   Migrasi". Halaman akan menampilkan "Migrasi berhasil diterapkan:
   0016_replacement_reject.php".
6. **Verifikasi migrasi 0016 sudah tercatat.** Muat ulang halaman
   `/_upgrade/` — sekarang "Sudah diterapkan" harus mencantumkan 0015
   MAUPUN 0016, dan "Menunggu diterapkan" harus kosong.
7. **Health check cepat.** Buka halaman **Konfirmasi Toko** dan **FG &
   Packing** untuk tanggal dan pabrik yang biasa dipakai — pastikan
   tampilannya normal seperti sebelumnya. Menu baru **"Replacement
   Reject"** juga harus muncul di sidebar.

8. **UAT nyata — alur Replacement Reject lengkap.**
   - Ambil satu pengiriman Reguler yang sudah pernah dikirim ke toko.
     Di halaman Konfirmasi Toko (link penerimaan toko), konfirmasi
     penerimaan dengan sebagian **Baik** dan sebagian **Reject** (isi
     bukti foto — wajib untuk reject/selisih).
   - Di halaman **Konfirmasi Toko**, buka detail pengiriman tersebut dan
     tekan **Verifikasi**.
   - Buka menu **Replacement Reject** di sidebar — baris reject yang
     baru diverifikasi harus muncul di bagian **"Tindak Lanjut Reject"**.
   - **Kasus A — Reject Final**: pilih baris tersebut, isi Qty Reject
     Disetujui, tekan **"Reject Final"**. Pastikan: TIDAK ada Replacement
     Demand baru muncul di tabel bawahnya, dan stok FG tidak berubah.
   - **Kasus B — Kirim Ulang**: untuk baris reject LAIN, isi Qty Reject
     Disetujui, tekan **"Kirim Ulang"**. Pastikan muncul baris baru di
     tabel **"Replacement Reject — Traceability"** menunjukkan:
     - **Dari FG** (kalau ada stok bebas yang cukup) dan/atau
     - **Perlu Produksi** (kalau stok bebas tidak cukup).
   - Kalau ada **Perlu Produksi**: cek halaman **Produksi → Task per
     Divisi**, pilih filter Sumber **"Replacement Reject"** — baris
     kebutuhan produksi untuk produk tersebut harus muncul di sana,
     TERPISAH dari PO Reguler.
   - Kembali ke halaman **Replacement Reject**, isi **Aktual Produksi**
     dan **Verifikasi FG** untuk baris tersebut sampai Perlu Produksi
     menjadi 0 — status berubah menjadi **"Siap DO"**.
   - Tekan **"Buat DO Replacement"** — pastikan dokumen baru muncul
     dengan nomor **REPL-...** (bukan format DO Reguler biasa), dan TIDAK
     ada kolom harga sama sekali.
   - Buka DO Replacement tersebut, tekan **"Kirim Sekarang"** (boleh
     kirim sebagian dulu untuk uji coba pengiriman bertahap).
   - Buka link penerimaan toko untuk pengiriman Replacement ini (sama
     seperti pengiriman biasa) dan konfirmasi **Baik** — status
     Replacement Demand tersebut harus menjadi **"Selesai/Completed"**.
   - **Verifikasi tidak ada efek samping**: cek halaman **Pesanan Toko**
     — target PO asli tidak berubah. Cek **Delivery Order** asli — qty
     rencana tidak bertambah.

9. **UAT nyata — kontrol akses (bagian dari perbaikan sebelum UAT).**
   - Login sebagai user dengan role **DRIVER** atau **PRODUCTION biasa**
     (bukan ADMIN/PPIC), lalu coba buka
     `https://domainanda.com/factory/api/_ui-preview/?page=replacement-reject`
     langsung — halaman harus menampilkan **403 Akses Ditolak**, bukan
     data reject/toko apa pun. Menu "Replacement Reject" di sidebar juga
     seharusnya TIDAK muncul untuk user ini.
   - Login sebagai user **PRODUCTION** yang HANYA ditugaskan ke satu
     divisi tertentu (lihat Master Data → User → Akses Divisi). Coba isi
     Aktual Produksi untuk sebuah Replacement Demand dari divisi LAIN —
     harus ditolak. Coba lagi untuk Replacement Demand dari divisi yang
     memang ditugaskan ke user tersebut — harus berhasil.
   - Login sebagai user **FG_PACKING** yang HANYA ditugaskan ke satu
     pabrik tertentu (lihat Master Data → User → Akses Pabrik). Coba
     Verifikasi FG untuk Replacement Demand dari pabrik LAIN — harus
     ditolak. Coba lagi untuk pabrik yang memang ditugaskan — harus
     berhasil.

---

## Yang TIDAK berubah

- Migrasi 0001–0015 yang sudah tercatat di server Anda — **tidak
  disentuh sama sekali**.
- Alur PO Reguler, Produksi, FG Verifikasi, Breakdown Toko, Packing per
  Toko, DO/Pengiriman Reguler, Pesanan Khusus/Non-Toko, Driver Portal —
  bekerja PERSIS seperti sebelumnya.
- Tombol terpisah **"Submit FG (Semua Toko)"** (yang benar-benar
  memposting stok) — TIDAK berubah sama sekali.
- Data yang sudah ada — tidak ada satu baris pun yang dihapus atau
  diubah oleh migrasi ini (murni `CREATE TABLE`/`ADD COLUMN` baru, tanpa
  `INSERT`/`UPDATE`/`DELETE` terhadap data bisnis apa pun).
- Modul Invoice belum diimplementasikan pada paket ini (memang di luar
  cakupan tugas ini) — tidak ada penagihan ganda yang dibuat oleh
  Replacement Reject.

---

## Kalau ada masalah

Kalau setelah menjalankan langkah di atas halaman `/_upgrade/`
menunjukkan sesuatu yang TIDAK sesuai dengan langkah 4 (misalnya migrasi
lain selain 0016 muncul di "Menunggu diterapkan"), **JANGAN tekan
"Terapkan Migrasi"** — ambil screenshot dan laporkan kembali sebelum
melanjutkan.
