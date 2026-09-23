# Amor Factory System — Final Pre-Live Rework (migrasi 0012, revisi terakhir) — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding.

**Paket ini MEMBUTUHKAN migrasi database baru (migrasi 0012).** Migrasi
0012 ini **BELUM PERNAH dipasang di server manapun** — ini adalah versi
FINAL, bukan revisi di atas versi yang sudah jalan. Migrasi hanya
**menambah** tabel/kolom baru — **tidak ada tabel yang dihapus, tidak ada
data yang dihapus, tidak ada perubahan pada DO PO Reguler, Driver Portal
Reguler, Email Reguler, atau Konfirmasi Toko Reguler.**

**Catatan revisi:** paket sebelumnya (Special/Non-Regular Fulfillment
Completion) sudah bisa membuat Shipment sungguhan untuk Pesanan Khusus
Toko/Non-Toko, tapi Shipment itu "buntu" — tidak muncul di Riwayat Driver,
tidak ada Surat Jalan Digital, tidak ada email otomatis ke Bakery, dan
toko tidak bisa konfirmasi terima barang. Paket ini **menyambungkan**
semua itu, tanpa mengubah alur PO Reguler sama sekali.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang baru

### 1. Sumber Pesanan yang jelas di mana-mana

Setiap pengiriman (Shipment) sekarang punya label Sumber yang tegas:
**PO Reguler**, **Pesanan Khusus Toko**, **CS**, **Sales**, **Konsumen
Langsung**, atau **Umum** — diambil langsung dari data pesanan (bukan
menebak dari nama pelanggan). Label ini muncul di Riwayat Driver, Detail
Pengiriman, Surat Jalan, Admin Pengiriman, dan Admin Konfirmasi Toko.

### 2. Riwayat & Detail Pengiriman Driver kini mencakup Pesanan Khusus/Non-Toko

Setelah Driver menekan "Berangkat" untuk DO Khusus/Non-Toko, pengiriman
itu sekarang **muncul di tab Riwayat** Driver Portal seperti pengiriman
PO Reguler — lengkap dengan jumlah produk, qty, dan status penerimaan
toko. Detail Pengiriman menampilkan item custom/katalog dengan nama asli
(tanpa produk palsu).

### 3. Surat Jalan Digital untuk Pesanan Khusus/Non-Toko

Tombol "Cetak Surat Jalan" sekarang juga bekerja untuk pengiriman
Khusus/Non-Toko — menampilkan Sumber, No. DO, Drop Bakery, Metode
Pengiriman (Driver Internal / Kurir Eksternal), dan nama Driver atau
Kurir. QR kode konfirmasi penerimaan khusus untuk pengiriman ini sendiri
(bukan berbagi dengan DO lain).

### 4. Email otomatis ke Bakery untuk Pesanan Khusus/Non-Toko

Begitu barang benar-benar berangkat (Driver Internal) atau diserahkan ke
kurir (External Courier), sistem otomatis mengirim email berisi Surat
Jalan Digital + tautan konfirmasi penerimaan ke **email Bakery tempat
barang diturunkan** — bukan ke pelanggan/CS/Sales yang memesan (nama
pemesan tetap tercatat terpisah untuk keperluan tagihan nanti). Kegagalan
kirim email TIDAK PERNAH membatalkan pengiriman/pengurangan stok FG.

### 5. Toko/Bakery bisa konfirmasi terima barang untuk Pesanan Khusus/Non-Toko

Toko yang menerima email bisa membuka tautan dan mengonfirmasi barang
diterima — termasuk item custom/katalog (misal "DELUXE KARAKTER 16").
Aturan sama seperti PO Reguler: Diterima Baik + Reject + Kurang harus
sama dengan jumlah dikirim, dan **bukti foto wajib diunggah** jika ada
Reject/Kurang. Setiap Shipment punya konfirmasi terpisah — jika satu DO
dikirim 2× (parsial), toko mengonfirmasi 2× juga, masing-masing dengan
email sendiri.

### 6. Admin Konfirmasi Toko & Admin Pengiriman menampilkan Sumber

Kedua halaman Admin sekarang menampilkan label Sumber + Metode Pengiriman
untuk setiap baris, sehingga Admin bisa langsung melihat mana pengiriman
PO Reguler dan mana yang Khusus/CS/Sales/Konsumen Langsung/Umum. Admin
tetap bisa memverifikasi selisih (Reject/Kurang) untuk pengiriman
Khusus/Non-Toko dengan cara yang sama seperti PO Reguler — dan tetap
TIDAK BISA mengunggah bukti foto atas nama toko.

### 7. Tautan konfirmasi PO Reguler yang SUDAH ADA tetap berfungsi

Tautan email lama yang sudah terkirim ke toko PO Reguler **tetap
berfungsi persis seperti sebelumnya** — tidak ada perubahan pada cara
kerja token/tautan untuk PO Reguler.

---

## Cara pasang (cPanel, tanpa command line)

1. **Backup dulu.** Di cPanel → phpMyAdmin, export (backup) database Anda
   saat ini. Ini WAJIB sebelum migrasi apa pun.
2. **Upload & extract.** Upload `amor-factory-final-prelive-rework.zip` ke
   `public_html/factory/`, lalu extract — ini akan MENIMPA file lama
   dengan versi baru (aman, tidak menghapus folder `api/app/config/`).
3. **Jalankan migrasi.** Buka `https://domainanda.com/factory/api/_upgrade/`
   di browser (login sebagai Admin dulu jika diminta), lalu jalankan
   migrasi hingga selesai (akan menunjukkan migrasi 0012 sebagai migrasi
   baru yang diterapkan — migrasi 0001–0011 akan ditandai "sudah
   diterapkan sebelumnya", TIDAK dijalankan ulang).
4. **Coba salah satu alur berikut** untuk memastikan semuanya bekerja:
   - Buat Pesanan Khusus Toko → verifikasi FG → buat DO → Driver klaim &
     Berangkat → cek tab Riwayat Driver → cek email masuk ke Bakery → buka
     tautan di email → konfirmasi terima barang.
   - Buat Pesanan Non-Toko (CS) → verifikasi FG → buat DO dengan Metode
     Kurir Eksternal → "Barang Diserahkan ke Kurir" → cek email masuk ke
     Bakery → buka tautan → konfirmasi dengan Reject > 0 → unggah bukti
     foto → cek muncul di Admin Konfirmasi Toko dengan badge Sumber "CS".

---

## Yang TIDAK berubah

- Alur PO Reguler Toko, Driver Portal Reguler, Surat Jalan PO Reguler,
  Email PO Reguler, Konfirmasi Toko PO Reguler, dan Evidence/Bukti Foto —
  semuanya bekerja PERSIS seperti sebelumnya.
- Perhitungan Invoice/tagihan (Phase 6) belum dibangun di paket ini — di
  luar cakupan tugas ini. Setiap Shipment/DO Khusus/Non-Toko tetap
  menyimpan Sumber + referensi pesanan aslinya untuk keperluan tagihan
  nanti.
- "Rute Saya" (urutan kunjungan multi-toko) tetap khusus PO Reguler — DO
  Khusus/Non-Toko punya satu tujuan pasti per DO, jadi tidak memerlukan
  fitur urutan rute.

---

## Keamanan

Sesi login, token CSRF, tautan konfirmasi bertoken acak (tidak bisa
ditebak), query database dengan parameter aman, unggah bukti foto yang
divalidasi, otorisasi per-peran, dan catatan audit — semuanya tetap
berjalan seperti sebelumnya, diperluas ke alur baru ini tanpa
dilonggarkan sedikit pun.
