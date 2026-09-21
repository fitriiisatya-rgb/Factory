# Amor Factory System — Panduan Patch Pemisahan Draft DO vs Surat Jalan Pengiriman (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada perintah SQL
manual dan tidak ada Terminal/SSH untuk paket ini — dan tidak perlu
menerapkan migrasi apa pun** (paket ini hanya berisi file, tidak ada
perubahan database).

**Baca ini setelah patch Driver Portal UX + Navigasi/Logout sudah
berjalan di server Anda.** Paket ini memperbaiki temuan UAT nyata pada
DO `DO/KRM/004/IX/2026`, Bakery Abdul Gani: dokumen cetak DO menampilkan
SEMUA barang rencana (1.034 pcs) — benar untuk dokumen perencanaan,
tapi SALAH jika dipakai sebagai Surat Jalan fisik yang dibawa satu
Driver, karena satu DO sekarang bisa terbagi menjadi beberapa pengiriman
nyata (SHP-2 Driver A = 7 pcs, SHP-3 Driver B = 4 pcs).

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang diperbaiki

Sekarang ada **DUA dokumen cetak yang jelas berbeda**:

1. **Draft DO / Rencana Pengiriman** (dokumen lama, TETAP ADA, hanya
   judulnya diperjelas) — tetap menampilkan SEMUA barang rencana di DO
   ini, untuk keperluan perencanaan/penyiapan barang. Judulnya sekarang
   **"DRAFT DELIVERY ORDER / RENCANA PENGIRIMAN"** — tidak lagi memakai
   kata "Surat Jalan" sama sekali, supaya tidak disalahartikan sebagai
   bukti kirim.

2. **Surat Jalan (dokumen BARU)** — mencetak HANYA barang yang benar-benar
   ada di SATU pengiriman (shipment) tertentu, sesuai Driver yang
   membawanya. Contoh SHP-2 (Driver A): hanya menampilkan AVOCADO RING=5
   dan BOLLEN KOMBINASI=2 (Total 7 pcs) — TIDAK menampilkan 1.027 pcs
   sisanya yang direncanakan tapi tidak ikut di pengiriman ini.

   Dokumen Surat Jalan ini dibuka lewat tombol **"Cetak Surat Jalan"**
   di halaman **Detail Pengiriman** Driver, dan berisi:
   - No. Shipment, No. DO, Tanggal DO, Tanggal/Jam Berangkat
   - Nama Toko, Factory Asal, Driver, Group Pengiriman (MAIN/PASTRY/OTHER)
   - Tabel produk: No, Nama Produk, Divisi, Qty Kirim (qty NYATA yang
     dikirim, bukan qty rencana)
   - Total Produk dan Total Qty Kirim
   - QR Konfirmasi Penerimaan (SAMA dengan QR di DO — lihat di bawah)
   - Kolom tanda tangan: Disiapkan Oleh / Dikirim Oleh Driver / Diterima Oleh

   Seorang Driver **hanya bisa mencetak Surat Jalan pengirimannya
   sendiri** — mencoba mencetak pengiriman Driver lain akan ditolak.

---

## Tentang QR Konfirmasi Penerimaan

- QR di Surat Jalan **SAMA** dengan QR di dokumen Draft DO — keduanya
  tetap 1 DO = 1 kode QR, TIDAK ada kode QR baru per pengiriman.
- Jika satu DO punya 2 pengiriman (misalnya SHP-2 dan SHP-3), Surat
  Jalan untuk KEDUANYA akan mencetak QR yang PERSIS SAMA.
- Saat toko men-scan QR ini, halaman yang muncul akan menampilkan SEMUA
  pengiriman nyata di bawah DO tersebut secara terpisah (misalnya
  "SHP-2 · MAIN · Driver: [Nama Driver A]" dan "SHP-3 · PASTRY · Driver:
  [Nama Driver B]"), masing-masing dengan tombol konfirmasi sendiri —
  konfirmasi penerimaan tetap PER PENGIRIMAN, tidak digabung.
- **Belum ada kode cadangan pendek** (selain QR) di bawah QR — ini
  sengaja belum dibuat karena memerlukan keputusan keamanan baru
  (lihat bagian "Risiko & Catatan" di bawah).

---

## Untuk pencetak dot-matrix / kertas 3-ply

Tampilan cetak Surat Jalan sudah disesuaikan untuk printer dot-matrix:
hitam-putih polos, tanpa gradasi, tanpa latar gelap, teks label lebih
gelap/kontras supaya tetap terbaca di printer impact.

Ukuran fisik QR bisa diatur lewat URL untuk uji coba cetak fisik:
- `...print-shipment.php?id=123` → default 40mm
- `...print-shipment.php?id=123&qr=35` → 35mm
- `...print-shipment.php?id=123&qr=50` → 50mm

Silakan cetak dengan printer dot-matrix asli dan bandingkan ketiga
ukuran untuk menentukan ukuran QR yang paling mudah di-scan di kertas
3-ply Anda.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- File `amor-factory-shipment-surat-jalan-print-patch.zip` (dikirim
  bersama panduan ini).

**Tidak perlu** kredensial migrasi database — paket ini tidak mengubah
struktur database sama sekali.

---

## Langkah-langkah

### 1. Backup (tetap disarankan, walau paket ini hanya file)

cPanel → Backup Wizard → backup folder `public_html/factory/`.

### 2. Upload & Extract (dengan Timpa/Overwrite)

Upload `amor-factory-shipment-surat-jalan-print-patch.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Jika ditanya
"timpa atau lewati", selalu pilih **Timpa/Overwrite**. `config.php` Anda
TIDAK ikut ditimpa (tidak ada di dalam paket ini).

### 3. Tidak ada migrasi

Paket ini TIDAK memerlukan langkah migrasi apa pun — langsung ke langkah
berikutnya.

### 4. Hard refresh

Buka Driver Portal di HP/browser, lalu lakukan **hard refresh** (tutup
tab lalu buka lagi) supaya file CSS/JS lama yang ter-cache tidak dipakai.

### 5. Buka Driver A → SHP-2

Login sebagai Driver A → tab **Riwayat** → klik pengiriman SHP-2 →
**Detail Pengiriman**.

### 6. Klik "Cetak Surat Jalan"

### 7. Verifikasi hanya 2 produk / 7 pcs

Pastikan yang tercetak HANYA AVOCADO RING=5 dan BOLLEN KOMBINASI=2,
Total 2 Produk / 7 Pcs — TIDAK ada produk lain dari rencana DO.

### 8. Buka Driver B → SHP-3 → Cetak Surat Jalan

### 9. Verifikasi hanya 2 produk / 4 pcs

Pastikan yang tercetak HANYA BLACKFOREST CHOCO CASTLE 16=3 dan
BLACKFOREST CHOCO ROCK 16=1, Total 2 Produk / 4 Pcs.

### 10. Scan QR di salah satu Surat Jalan

### 11. Verifikasi portal Toko menampilkan KEDUA pengiriman

Pastikan halaman yang terbuka menampilkan Bakery Abdul Gani, DO/KRM/004/
IX/2026, dengan DUA kartu terpisah: SHP-2 (MAIN, Driver A) dan SHP-3
(PASTRY, Driver B), masing-masing dengan tombol Konfirmasi Penerimaan
sendiri.

### 12. Uji cetak fisik dot-matrix (dilakukan setelah UAT digital di atas selesai)

Cetak Surat Jalan ke printer dot-matrix asli dengan kertas 3-ply,
bandingkan hasil dengan `?qr=35`, `?qr=40`, dan `?qr=50` untuk menentukan
ukuran QR terbaik yang bisa di-scan dari hasil cetakan fisik.

### 13. Cara mundur (rollback) jika ada masalah

Karena paket ini hanya mengubah file kode (tidak ada perubahan database),
cara mundur paling aman adalah mengembalikan file-file yang tertimpa dari
backup langkah 1. Tidak ada data yang perlu dibersihkan di database.

---

## Risiko & Catatan penting

- **Tidak ada perubahan pada perhitungan stok, klaim Driver, atau
  logika Shipment/Receipt** — dokumen cetak hanya membaca data yang
  sudah ada, tidak pernah menulis/mengubah data transaksi.
- **Status DO** di layar admin masih bisa menampilkan "Draft" walau
  sebagian pengirimannya sudah berangkat — ini SUDAH ADA sebelumnya dan
  SENGAJA TIDAK diubah di paket ini (di luar cakupan tugas pemisahan
  dokumen). Jika ini membingungkan, laporkan terpisah untuk audit
  Phase 5 lifecycle-nya.
- **Kode cadangan pendek di bawah QR belum tersedia.** Toko sepenuhnya
  bergantung pada scan QR untuk saat ini. Menambahkan kode pendek yang
  aman (tidak mudah ditebak) memerlukan keputusan arsitektur keamanan
  baru — sengaja DITUNDA, bukan dilewatkan tanpa alasan. Beri tahu kami
  jika toko benar-benar membutuhkan ini secepatnya.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
