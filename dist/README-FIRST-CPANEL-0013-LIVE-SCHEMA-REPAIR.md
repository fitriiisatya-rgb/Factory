# Amor Factory System — Migration 0013: Perbaikan Skema Live — cPanel, Non-Teknis

**Baca ini SEBELUM melakukan apa pun. Ini adalah perbaikan darurat untuk
server LIVE yang sudah berjalan.**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang terjadi di server LIVE Anda

Halaman migrasi (`/_upgrade/`) di server Anda saat ini menunjukkan:

**0012_production_flow_completion.php = SUDAH DITERAPKAN**

TAPI pengecekan langsung ke database `u7566812_factory` menemukan: tabel
`special_order_fg_allocation` **TIDAK ADA**.

**Ini artinya**: migrasi 0012 di server Anda dulu diterapkan dari VERSI
LAMA file migrasi 0012 — sebelum fitur "Alokasikan dari FG" ditambahkan
ke dalamnya. Sistem migrasi mencatat status "sudah diterapkan" berdasarkan
NAMA FILE saja, bukan isinya — jadi walau isi file 0012 di kode sekarang
sudah lebih lengkap, sistem **tidak akan pernah menjalankan ulang**
migrasi 0012 di server Anda, karena sudah tercatat "selesai".

**Akibatnya di server Anda sekarang**: halaman **Produksi → Order Masuk /
Demand Tambahan** kosong/blank — karena halaman itu butuh tabel
`special_order_fg_allocation` yang ternyata belum pernah benar-benar
dibuat di database Anda.

---

## Solusinya: migrasi 0013 (BUKAN mengulang 0012)

Paket ini menambahkan **migrasi 0013**, sebuah "migrasi perbaikan" yang
KHUSUS dibuat untuk situasi ini.

**ATURAN PALING PENTING — WAJIB DIPAHAMI:**

- Migrasi 0012 yang SUDAH tercatat "diterapkan" di server Anda **TIDAK
  AKAN disentuh, dihapus, atau dijalankan ulang** oleh paket ini.
- Migrasi 0013 **HANYA menambah** apa yang ternyata belum ada dari
  migrasi 0012 (terutama tabel `special_order_fg_allocation`, dan
  beberapa objek lain yang sudah dicek satu per satu — lihat bagian
  "Yang diperbaiki" di bawah).
- Migrasi 0013 **AMAN dijalankan berkali-kali** — kalau sebagian objek
  ternyata sudah ada (bukan hanya `special_order_fg_allocation`), migrasi
  ini melewatinya dengan aman, tidak akan pernah error karena "sudah ada"
  atau "duplikat".
- **TIDAK ADA data yang dihapus.** Ini murni menambah struktur tabel
  (kolom baru, tabel baru, index baru) — bukan mengubah/menghapus data
  Regular PO, Produksi, FG, DO, Pengiriman, Pesanan Khusus, atau data
  apa pun yang sudah ada.
- **TIDAK ADA logika bisnis yang berubah.** Ini murni perbaikan struktur
  database supaya cocok dengan kode aplikasi yang sudah berjalan di
  server Anda — bukan mengubah cara kerja alokasi, produksi, DO,
  pengiriman, atau resi.

---

## Yang diperbaiki oleh migrasi 0013

Migrasi 0013 memastikan SEMUA objek berikut ini ada di database Anda —
menambahkan yang belum ada, dan melewati dengan aman yang sudah ada:

1. `special_order_item.extra_packaging` dan `fg_verified_qty` (kolom)
2. Tabel `special_order_do`, `special_order_do_item`,
   `special_order_do_shipment_item` (untuk DO Pesanan Khusus/Non-Toko)
3. Kolom pengiriman (`shipment.special_order_do_id`, `delivery_method`,
   `courier_provider`, `courier_name`, `external_order_reference`,
   `handover_note`) dan koneksinya (foreign key) ke `special_order_do`
4. Tabel `shipment_receipt_token` (untuk link konfirmasi resi Pesanan
   Khusus/Non-Toko)
5. Kolom tambahan di `shipment_receipt_item` untuk baris resi Pesanan
   Khusus/Non-Toko
6. **Tabel `special_order_fg_allocation`** — INI YANG DIKONFIRMASI HILANG
   di server Anda, dan penyebab langsung halaman Demand Tambahan blank
7. Daftar nilai (`ENUM`) `stock_ledger.source_type` diperluas untuk
   mencakup `special_order_fg_allocation`, **sambil tetap mempertahankan
   semua nilai yang sudah ada** (termasuk `fg_item`, yang penting untuk
   FG Reguler — TIDAK PERNAH dihapus/dipersempit)

Migrasi ini sudah diuji terhadap beberapa kemungkinan kondisi lama server
Anda (bukan cuma satu kemungkinan), dan hasilnya selalu sama: database
menjadi persis seperti yang dibutuhkan kode aplikasi saat ini, tanpa
pernah error "sudah ada" atau kehilangan data.

---

## Cara pasang (cPanel, tanpa command line)

1. **Backup dulu.** Di cPanel → phpMyAdmin, export (backup) database Anda
   saat ini. Ini WAJIB sebelum migrasi apa pun, walau migrasi ini hanya
   menambah struktur.
2. **Upload & extract.** Upload `amor-factory-0013-live-schema-repair.zip`
   ke `public_html/factory/`, lalu extract — ini akan MENIMPA file lama
   dengan versi baru (aman, tidak menghapus folder `api/app/config/`).
3. **Buka halaman Upgrade Database.** Buka
   `https://domainanda.com/factory/api/_upgrade/` di browser (login
   sebagai Admin dulu jika diminta).
4. **VERIFIKASI PENTING sebelum menekan apa pun**: pastikan halaman
   tersebut menunjukkan:
   - "Sudah diterapkan" masih mencantumkan
     `0012_production_flow_completion.php` (TIDAK hilang, TIDAK berubah).
   - "Menunggu diterapkan" **HANYA** berisi SATU baris:
     `0013_repair_production_flow_completion.php`.

   Kalau yang muncul BUKAN seperti ini (misalnya 0012 ikut muncul lagi di
   "Menunggu diterapkan", atau ada migrasi lain selain 0013), **STOP** —
   jangan tekan apa pun, laporkan kembali screenshot halaman tersebut.
5. **Terapkan.** Centang kotak konfirmasi, lalu tekan tombol "Terapkan
   Migrasi". Halaman akan menampilkan "Migrasi berhasil diterapkan:
   0013_repair_production_flow_completion.php".
6. **Verifikasi migrasi 0013 sudah tercatat.** Muat ulang halaman
   `/_upgrade/` — sekarang "Sudah diterapkan" harus mencantumkan BAIK
   0012 MAUPUN 0013, dan "Menunggu diterapkan" harus kosong ("Tidak ada.
   Database sudah versi terbaru.").
7. **Health check cepat.** Buka beberapa halaman lain yang sudah biasa
   dipakai (Ceklis Produksi, FG & Packing, Pengiriman) — pastikan
   semuanya masih tampil normal seperti sebelumnya (paket ini tidak
   mengubah halaman-halaman itu, ini hanya sanity check).
8. **UAT nyata dengan pesanan yang sebelumnya buntu.** Buka **Produksi →
   Order Masuk / Demand Tambahan**. Halaman ini SEHARUSNYA sekarang
   tampil normal (tidak blank lagi), dan pesanan seperti
   **NPR-20260923-001** (Source: CS, Produk: BOLLEN LILIT COKLAT, Qty: 2,
   Factory: Karangtengah) sekarang harus muncul dengan baris lengkap:
   FG Tersedia, Sudah Dialokasikan (0 kalau belum pernah dialokasikan),
   dan Kebutuhan Produksi. Tekan **"Alokasikan dari FG"**, masukkan 2,
   konfirmasi — cek Sudah Dialokasikan jadi 2 dan Kebutuhan Produksi jadi
   0 (tidak perlu produksi khusus tambahan). Lanjutkan alur seperti
   biasa: buat DO, klaim di Driver Portal, konfirmasi berangkat.

---

## Yang TIDAK berubah

- Migrasi 0012 yang sudah tercatat di server Anda — **tidak disentuh sama
  sekali**, baik isinya maupun statusnya di `schema_migrations`.
- Alur PO Reguler (target, Ceklis Produksi, FG & Packing, DO, Driver
  Portal, email, konfirmasi toko) — bekerja PERSIS seperti sebelumnya.
  FG Reguler tetap bisa disubmit dan tetap mencatat pergerakan stok
  dengan `source_type = 'fg_item'` seperti biasa — nilai ini SENGAJA
  tetap dipertahankan di daftar ENUM (bukan sesuatu yang bisa hilang
  secara tidak sengaja lagi, karena migrasi ini menulis ulang daftar
  ENUM lengkap secara eksplisit, bukan mempersempitnya).
- Logika alokasi FG, aturan DO, aturan resi, alur Driver, semantik stock
  ledger, Invoice, Replacement/Reject — semuanya TIDAK berubah. Ini murni
  perbaikan struktur database, bukan perubahan cara kerja aplikasi.
- Data yang sudah ada (Regular PO, Produksi, FG, DO, Pengiriman, Pesanan
  Khusus, User, Toko, Produk) — tidak ada satu baris pun yang dihapus
  atau diubah oleh migrasi ini (migrasi ini murni `CREATE TABLE`/
  `ALTER TABLE ADD COLUMN`/perluasan `ENUM` — tidak ada `INSERT`,
  `UPDATE`, atau `DELETE` terhadap data bisnis apa pun).

---

## Kalau ada masalah

Kalau setelah menjalankan langkah di atas halaman `/_upgrade/` menunjukkan
sesuatu yang TIDAK sesuai dengan langkah 4 (misalnya migrasi lain selain
0013 muncul di "Menunggu diterapkan"), **JANGAN tekan "Terapkan Migrasi"**
— ambil screenshot dan laporkan kembali sebelum melanjutkan. Migrasi ini
dirancang untuk aman di berbagai kemungkinan kondisi server, tapi kondisi
server Anda yang SEBENARNYA harus tetap diverifikasi dulu dengan mata
sebelum menekan tombol apa pun yang mengubah struktur database live.
