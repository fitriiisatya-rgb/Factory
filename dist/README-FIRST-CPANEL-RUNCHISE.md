# Amor Factory System — File Stok Masuk Runchise — cPanel, Non-Teknis

**Paket ini MEMBUTUHKAN migrasi database 0024.** Setelah extract, wajib buka
`https://factory.amorgroup.id/api/_upgrade/`.

Isinya sudah termasuk semua paket sebelumnya (Pesanan Khusus 0023 dan
perbaikan 3 celah).

**Path yang BENAR**: `public_html/factory/`.

---

## Yang baru

### Toko: unduh file stok masuk Runchise
- Setelah toko mengonfirmasi penerimaan (portal toko atau scan QR surat
  jalan), muncul kotak **File stok masuk Runchise**: lokasi, tanggal, jumlah
  produk, total pcs, dan daftar produk yang **tidak ikut file** beserta
  alasannya.
- Tombol **Unduh file stok masuk Runchise** + petunjuk impor 3 langkah.
- Isi file = jumlah **diterima baik** saja (reject dan kurang tidak masuk).
  Satu surat jalan = satu file.
- Kalau file sudah pernah diunduh, muncul peringatan agar **tidak diimpor
  dua kali**.

### Admin: Pengaturan → Mapping Runchise
1. **Upload katalog Runchise** (`impor_template_stock_ins.csv` dari Runchise).
   Produk factory yang kodenya sama dengan SKU Runchise langsung terpetakan.
2. **Produk**: atur yang belum terpetakan (Stok + pilih SKU / Bukan stok /
   Topping).
3. **Toko → Lokasi Runchise**: sudah terisi otomatis untuk 37 toko; "NON-OUTLET
   / PERORANGAN" sengaja kosong.
4. Daftar surat jalan 14 hari terakhir yang sudah dikonfirmasi, dengan
   pratinjau & unduh untuk admin.

### finhub
- API finhub mendapat 2 data baru per surat jalan: `runchiseDownloadedAt`
  dan `runchiseLocation`. Detail di `INTEGRASI-FINHUB.md`.

### Perbaikan
- Halaman **Integrasi finhub**: daftar kunci dulu tidak tampil ("Amor is not
  defined"). Sekarang tampil.

### Perbaikan FG Verifikasi (versi 20 Okt sore)
- Cari produk sekarang juga bekerja di **Breakdown Toko**.
- Kalau hasil produksi kurang dari PO di beberapa toko, Breakdown Toko
  menampilkan "Hasil Produksi · Total PO · kurang N", total yang berubah
  langsung, dan keterangan otomatis untuk toko yang dikurangi.
- Produk yang belum diisi tidak lagi menghalangi **Simpan Draft**.
- Produk yang sudah diisi di Per Produk bisa dibagi di Breakdown Toko.
- Pesan error menyebut nama produk dan angkanya.

### Notifikasi di tengah layar
- Error dan peringatan muncul di tengah dengan tombol **OK, Mengerti** (tidak
  hilang sendiri). Notifikasi sukses muncul singkat di tengah lalu hilang.
  Berlaku di admin, divisi, FG, portal toko, dan HP driver.

### Dashboard Ringkasan FG
- Menu **FG & Packing** sekarang membuka tab **Ringkasan** dulu: progres
  verifikasi, total produk (ketuk untuk daftar tiap barang: terverifikasi dan
  dipacking), per divisi, per produk, produksi kurang dari PO, dan Stok Sisa.
- Akun divisi mendapat menu **Ringkasan FG** (hanya divisinya sendiri).

### Print DO per Divisi
- Delivery Order → **Print per Divisi**: pilih divisi, lalu semua toko atau toko
  tertentu. Satu halaman per toko, hanya produk divisi itu, berlabel
  "BAGIAN: …", QR tetap ada. Tombol Print tiap toko juga bisa per divisi.
- Akun FG bisa mencetak dari tab Ringkasan.
- Kalau server sudah di migrasi 0024, cukup upload + extract (tanpa migrasi).

## Cara pasang
1. **Backup database** lewat phpMyAdmin → Export.
2. Upload `amor-factory-runchise.zip` ke `public_html/factory/`, lalu
   **Extract** (timpa file lama).
3. Login Admin, buka `https://factory.amorgroup.id/api/_upgrade/`, lalu
   jalankan migrasi **0024_runchise_export**.
4. Buka **Pengaturan → Mapping Runchise**, upload katalog Runchise, lalu
   cek daftar produk yang belum diatur.
5. Uji impor dulu ke lokasi uji **AMOR CAKES BAKERY BANGBARUNG** sebelum
   dipakai toko asli.

## Yang TIDAK berubah
- Alur konfirmasi toko tidak berubah. Data lama tetap ada; migrasi 0024 hanya
  menambah kolom dan satu tabel.
