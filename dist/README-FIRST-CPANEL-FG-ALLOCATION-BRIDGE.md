# Amor Factory System — Jembatan Alokasi FG Existing (migrasi 0012, tambahan) — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding.

**Paket ini MEMBUTUHKAN migrasi database baru (masih migrasi 0012).**
Migrasi 0012 **BELUM PERNAH dipasang di server manapun** — paket ini
**menambahkan** ke migrasi 0012 yang sama (satu tabel baru + satu kolom
diperluas), **bukan** migrasi 0013 baru. Migrasi hanya **menambah** — tidak
ada tabel yang dihapus, tidak ada data yang dihapus, dan alur PO Reguler
sama sekali tidak berubah.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## ⚠ Catatan revisi PENTING — JANGAN pasang paket sebelumnya

Paket **sebelumnya** (Jembatan Alokasi FG Existing versi pertama) sudah
benar mencegah dua Pesanan Khusus/Non-Toko saling merebut FG yang sama —
**TAPI belum melindungi FG yang sudah dialokasikan itu dari PENGIRIMAN PO
REGULER.** Artinya: FG yang sudah "dikunci" untuk satu Pesanan Khusus
masih bisa ikut terkirim lewat PO Reguler seolah-olah belum dialokasikan
sama sekali — jaminan alokasinya jadi tidak berlaku secara menyeluruh.

**Paket ini (revisi) memperbaikinya total.** Jika Anda sudah mengunduh
paket sebelumnya, **JANGAN dipasang** — gunakan paket ini sebagai
penggantinya. Migrasi 0012 di paket ini adalah versi yang SAMA (masih
migrasi 0012, bukan migrasi baru) — aman menimpa, karena migrasi 0012
dipastikan belum pernah benar-benar dipasang di server manapun (lihat
bagian "PENTING — cek dulu" di langkah pasang di bawah).

---

## Masalah yang diperbaiki

Saat UAT di cPanel sungguhan, ditemukan: sebuah item produk-existing pada
Pesanan Khusus/Non-Toko **tidak bisa lanjut ke DO** kalau Produksi belum
input Aktual Produksi — **padahal stok FG umum (yang sama dengan yang
dipakai PO Reguler) sebenarnya SUDAH CUKUP** untuk memenuhi pesanan itu.
Pesanan jadi buntu, padahal barangnya sudah ada di gudang.

Paket ini memperbaikinya: pesanan Khusus/Non-Toko sekarang bisa memakai
stok FG umum yang SUDAH ADA lebih dulu, dan Produksi baru hanya diminta
untuk KEKURANGANNYA saja.

---

## Apa yang baru

### 1. Tombol "Alokasikan dari FG" di Order Masuk / Demand Tambahan

Halaman Produksi → Order Masuk sekarang menampilkan, untuk setiap item
produk-existing: **FG Tersedia** (stok umum yang benar-benar bebas, bukan
sedang dipesan order lain), **Sudah Dialokasikan**, dan **Kebutuhan
Produksi** yang sudah dikurangi otomatis. Jika ada FG bebas, muncul tombol
**"Alokasikan dari FG"** — sebuah popup konfirmasi muncul menampilkan Qty
Order, FG Bebas, dan jumlah maksimal yang bisa dialokasikan; PIC harus
menekan Konfirmasi secara sadar — sistem **tidak pernah** mengunci stok
secara otomatis/diam-diam hanya karena halaman dibuka.

**Contoh:** pesanan 40 pcs, FG umum tersedia 35 pcs → alokasikan 35 →
Kebutuhan Produksi otomatis jadi 5, bukan 40 lagi. Task per Divisi Produksi
juga hanya menampilkan target 5, bukan 40.

**Contoh lain:** pesanan 2 pcs, FG umum tersedia 35 pcs → alokasikan 2 →
Kebutuhan Produksi jadi 0, statusnya "Tidak Perlu Produksi", dan pesanan
bisa langsung lanjut ke DO **tanpa Produksi mengisi apa pun**.

### 2. Halaman FG Khusus/Non-Toko menampilkan DUA asal pemenuhan secara terpisah

"1. Dari FG Existing" (dari stok umum yang dialokasikan) dan "2. Dari
Produksi Khusus" (dari produksi baru yang diverifikasi) — plus "Total
Siap untuk Order" yang menjumlahkan keduanya. Tidak pernah dicampur jadi
satu angka yang membingungkan.

### 3. Satu pengiriman bisa gabungan dua sumber sekaligus

Kalau order 40 pcs punya alokasi 35 pcs dari FG umum + 5 pcs dari produksi
khusus, DO-nya boleh dibuat untuk 40 pcs, dan saat Driver/Kurir benar-benar
membawa barang keluar gudang, sistem otomatis mengurangi stok FG umum
untuk bagian 35-nya (satu kali catatan stok, bukan dobel), dan bagian 5
dari produksi khusus dicatat seperti biasa. Kalau pengiriman dilakukan
bertahap (misal 20 dulu, sisanya menyusul), sisa alokasi tetap tercatat
benar untuk pengiriman berikutnya.

### 4. Stok tidak pernah berkurang hanya karena dialokasikan

Menekan "Alokasikan dari FG" **TIDAK** mengurangi stok fisik — itu hanya
"menandai" agar order lain tidak ikut memakai stok yang sama. Stok fisik
baru benar-benar berkurang saat barang sungguhan keluar dari gudang
(Driver Berangkat / Barang Diserahkan ke Kurir).

### 5. Pesanan dibatalkan → alokasi yang belum terpakai otomatis dilepas

Kalau pesanan dibatalkan sebelum ada pengiriman, alokasi FG yang belum
terpakai otomatis dilepas kembali ke stok bebas — supaya order lain bisa
memakainya. Kalau sebagian sudah terkirim lalu dibatalkan, hanya sisa yang
BELUM terkirim yang dilepas; yang sudah terkirim tidak pernah "ditarik
balik".

### 6. Aman dipakai bersamaan oleh banyak orang

Kalau dua pesanan berbeda mencoba mengalokasikan FG yang sama secara
bersamaan, sistem menjamin totalnya tidak pernah melebihi stok fisik yang
benar-benar ada — sudah diuji dengan skenario dua permintaan sungguhan
berjalan bersamaan.

### 7. (BARU di revisi ini) PO Reguler sekarang juga menghormati FG yang sudah dialokasikan

Ini perbaikan utama revisi ini. Sebelumnya, FG yang sudah "dikunci" untuk
Pesanan Khusus/Non-Toko masih bisa ikut terkirim lewat PO Reguler seperti
biasa — sekarang TIDAK LAGI:

- Halaman **Pratinjau Pengiriman** dan halaman **Kirim** PO Reguler
  (`Pengiriman`) sekarang menampilkan **FG Available** yang sudah
  dikurangi alokasi Pesanan Khusus/Non-Toko — bukan stok fisik mentah.
- Kalau PIC Reguler mencoba mengirim melebihi sisa yang benar-benar bebas,
  sistem **menolak** di server (bukan cuma dibatasi tampilan).
- Sebaliknya, dispatch Pesanan Khusus/Non-Toko sendiri **selalu
  mengecek ulang stok fisik sungguhan** tepat sebelum barang dianggap
  keluar gudang — supaya stok tidak pernah minus walau ada aktivitas lain
  di sistem pada saat bersamaan.
- Kalau tidak ada Pesanan Khusus/Non-Toko yang memakai alokasi sama sekali
  untuk suatu produk, PO Reguler bekerja **persis seperti sebelumnya** —
  perbaikan ini hanya mengurangi FG yang terlihat/bisa dikirim PO Reguler
  ketika memang ada alokasi aktif.

**Contoh:** stok fisik 10 pcs, Pesanan CS sudah mengalokasikan 8 pcs → PO
Reguler hanya melihat dan bisa mengirim maksimal 2 pcs — bukan 10 pcs.

---

## Cara pasang (cPanel, tanpa command line)

1. **Backup dulu.** Di cPanel → phpMyAdmin, export (backup) database Anda
   saat ini. Ini WAJIB sebelum migrasi apa pun.
2. **Upload & extract.** Upload `amor-factory-fg-allocation-bridge.zip` ke
   `public_html/factory/`, lalu extract — ini akan MENIMPA file lama
   dengan versi baru (aman, tidak menghapus folder `api/app/config/`).
3. **PENTING — cek dulu sebelum menjalankan migrasi.** Paket ini AMAN
   menimpa file migrasi 0012 HANYA KARENA sudah dipastikan migrasi 0012
   BELUM PERNAH dipasang di server manapun. Sistem migrasi mencatat status
   "sudah diterapkan" per NAMA FILE, bukan per isi — jadi kalau server
   Anda TERNYATA sudah pernah menjalankan migrasi 0012 versi sebelumnya
   (misalnya dari paket rework yang lama), migrasi akan **melewati
   seluruh file 0012 begitu saja** dan tabel/kolom baru di paket ini
   **TIDAK AKAN PERNAH terpasang** — walau tidak ada pesan error yang
   jelas. Sebelum melanjutkan: buka phpMyAdmin → tabel `schema_migrations`
   → pastikan tidak ada baris untuk `0012_production_flow_completion.php`.
   Kalau ternyata SUDAH ada baris untuk migrasi 0012 di server Anda,
   **STOP** — jangan pasang paket ini, laporkan kembali agar dibuatkan
   migrasi 0013 yang benar, bukan menimpa 0012 yang sudah berjalan.
   Kalau memang belum ada (kondisi normal saat ini), buka
   `https://domainanda.com/factory/api/_upgrade/` di browser (login
   sebagai Admin dulu jika diminta), lalu jalankan migrasi hingga selesai
   — migrasi 0001–0012 semuanya akan diterapkan dalam satu proses.
4. **Coba alur berikut** untuk memastikan semuanya bekerja:
   - Buat Pesanan Khusus Toko dengan item produk-existing yang stok
     umumnya cukup → buka Produksi → Order Masuk → tekan "Alokasikan dari
     FG" → konfirmasi → cek Kebutuhan Produksi jadi 0 → buat DO → kirim →
     cek stok FG berkurang sesuai yang dikirim.
   - Buat pesanan dengan qty lebih besar dari stok FG umum → alokasikan
     sebagian → isi Aktual Produksi untuk sisanya di Task per Divisi →
     verifikasi FG → cek halaman FG Khusus/Non-Toko menampilkan "Dari FG
     Existing" dan "Dari Produksi Khusus" terpisah, totalnya benar → buat
     DO gabungan → kirim.

---

## Yang TIDAK berubah

- Alur PO Reguler Toko (target, Ceklis Produksi, FG & Packing, DO, Driver
  Portal, email, konfirmasi toko) — semuanya bekerja PERSIS seperti
  sebelumnya, TERMASUK Pengiriman/Kirim — **selama tidak ada Pesanan
  Khusus/Non-Toko yang mengalokasikan FG produk yang sama.** Begitu ada
  alokasi aktif untuk suatu produk, Pengiriman PO Reguler otomatis hanya
  melihat sisa yang benar-benar bebas (lihat poin 7 di atas) — ini
  satu-satunya perubahan pada alur PO Reguler, dan sifatnya melindungi,
  bukan mengubah cara kerja normalnya.
- Model data PO Reguler sendiri (po_batch/po_item/po_store_item/delivery_
  order/shipment/shipment_item) tidak disentuh sama sekali.
- Item custom/katalog (bukan produk-existing) TIDAK PERNAH bisa memakai
  FG umum — tetap harus melalui produksi khusus seperti biasa.
- Riwayat Aktual/Reject Produksi yang sudah ada tidak pernah disembunyikan
  atau dihapus hanya karena FG umum belakangan tersedia.

---

## Keamanan

Aksi "Alokasikan dari FG" hanya bisa dilakukan oleh Admin/PPIC (peran yang
sama dengan yang mengelola pesanan lainnya), memakai sesi login + token
CSRF + validasi server penuh (qty tidak pernah dipercaya dari input
browser begitu saja), query database dengan parameter aman, dan tercatat
di log audit (siapa, kapan, pesanan mana, produk mana, jumlah berapa).
Menekan tombol dua kali tidak pernah menghasilkan alokasi ganda.
