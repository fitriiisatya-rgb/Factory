# Amor Factory System — Panduan Patch Driver Portal UX + Shipment Tracing (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada perintah SQL
manual dan tidak ada Terminal/SSH untuk paket ini — dan tidak perlu
menerapkan migrasi apa pun** (paket ini hanya berisi file, tidak ada
perubahan database).

**Baca ini setelah Phase 5.5 + Manajemen User + patch FG Source Refresh
sudah berjalan di server Anda.** Paket ini memperbaiki beberapa masalah
nyata di Driver Portal yang ditemukan saat UAT tanggal 2026-09-05 (DO
DO/KRM/004/IX/2026, Bakery Abdul Gani, Driver A).

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang diperbaiki

1. **Dialog "Konfirmasi Berangkat" rusak** — sebelumnya muncul di
   pojok-kiri-bawah, tidak ada latar gelap (overlay), dan bisa muncul
   berkali-kali bertumpuk. Sekarang selalu tepat SATU dialog, di
   tengah layar, dengan latar gelap, judul **"Konfirmasi Keberangkatan"**,
   ringkasan toko/jumlah produk/pcs, dan tombol **Batal** /
   **Ya, Konfirmasi Berangkat**. Tombol otomatis nonaktif dan menampilkan
   "Memproses..." saat sedang diproses, supaya tidak bisa diklik dua kali.
2. **Layar berhasil setelah konfirmasi** sekarang menampilkan nomor
   Shipment asli (SHP-xxxx), jumlah produk/pcs, dan waktu berangkat, plus
   tombol **Lihat Detail Pengiriman**.
3. **"Rute Saya" menampilkan 0 produk / 0 pcs setelah berangkat** — ini
   BUG NYATA yang sudah diperbaiki. Sekarang setelah berangkat, kartu
   toko tetap menampilkan jumlah produk/pcs yang SEBENARNYA dikirim.
4. **Riwayat Pengiriman sekarang bisa diklik** — setiap kartu riwayat
   membuka halaman baru **Detail Pengiriman** berisi info lengkap
   (No. Shipment, No. DO, Driver, Grup, Factory asal, status), daftar
   produk yang dikirim, status penerimaan toko (jika sudah dikonfirmasi),
   dan riwayat proses (timeline).
5. **Driver hanya bisa membuka pengirimannya sendiri** — mencoba membuka
   pengiriman driver lain akan ditolak otomatis.
6. **Tanggal/jam sekarang ditampilkan format Indonesia** ("21 Sep 2026 ·
   10:20") memakai zona waktu Asia/Jakarta — bukan format database mentah.
7. **Tombol Logout ditambahkan** di pojok kanan atas Driver Portal.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- File `amor-factory-driver-ux-tracing-patch.zip` (dikirim bersama
  panduan ini).

**Tidak perlu** kredensial migrasi database — paket ini tidak mengubah
struktur database sama sekali.

---

## Langkah-langkah

### 1. Backup (tetap disarankan, walau paket ini hanya file)

cPanel → Backup Wizard → backup folder `public_html/factory/`.

### 2. Upload & Extract

Upload `amor-factory-driver-ux-tracing-patch.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Hasilnya bergabung
ke folder `public_html/factory/api/` yang sudah ada — `config.php` Anda
TIDAK ikut ditimpa.

### 3. Periksa `config.php` masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 4. Tidak ada migrasi

Paket ini TIDAK memerlukan langkah migrasi apa pun — langsung ke langkah
berikutnya.

### 5. Hard refresh Driver Portal

Buka `https://factory.amorgroup.id/api/_driver-uat/` di HP/browser driver,
lalu lakukan **hard refresh** (di HP: tutup tab lalu buka lagi, atau
refresh sambil menahan tombol reload) supaya file CSS/JS lama yang
ter-cache tidak dipakai lagi.

### 6. Uji dialog Konfirmasi Berangkat

Login sebagai Driver, ambil satu pengiriman dari tab **Tersedia**, buka
**Rute Saya** → pilih toko → klik **KONFIRMASI BERANGKAT**. Pastikan:
- Hanya SATU dialog muncul, di tengah layar, dengan latar gelap.
- Judul "Konfirmasi Keberangkatan" dan ringkasan toko/produk/pcs tampil.
- Klik **Batal** menutup dialog tanpa membuat pengiriman.
- Klik **Ya, Konfirmasi Berangkat** menampilkan "Memproses..." sebentar,
  lalu layar berhasil dengan No. Shipment asli.

### 7. Periksa Rute Saya

Setelah berangkat, buka tab **Rute Saya** lagi — pastikan toko yang baru
saja diberangkatkan menampilkan jumlah produk/pcs yang BENAR (bukan 0).

### 8. Periksa Riwayat

Buka tab **Riwayat** — pastikan setiap kartu bisa diklik dan menampilkan
jumlah produk/pcs, lalu klik salah satu untuk membuka **Detail
Pengiriman**.

### 9. Buka Detail Pengiriman

Di halaman Detail Pengiriman, periksa: nama toko, No. Shipment, No. DO,
waktu berangkat, driver, grup, factory asal, status, daftar produk, status
penerimaan toko (jika sudah ada), dan riwayat proses.

### 10. Verifikasi isolasi akses Driver

Jika Anda punya dua akun Driver, login sebagai Driver B lalu coba buka
link Detail Pengiriman milik Driver A secara langsung (copy-paste URL-nya)
— pastikan yang muncul adalah pesan akses ditolak, bukan data pengiriman
Driver A.

### 11. Cara mundur (rollback) jika ada masalah

Karena paket ini hanya mengubah file kode (tidak ada perubahan database),
cara mundur paling aman adalah mengembalikan file-file yang tertimpa dari
backup langkah 1. Tidak ada data yang perlu dibersihkan di database.

---

## Catatan penting

- **Tidak ada perubahan pada perhitungan stok** — dialog konfirmasi baru
  hanyalah tampilan; logika pengurangan stok FG saat berangkat persis
  sama seperti sebelumnya.
- **Tidak ada perubahan pada aturan klaim/pool/pelepasan klaim Driver.**
- **Detail Pengiriman bersifat read-only** — tidak ada tombol edit/hapus
  di halaman ini.
- **Fitur ini BELUM termasuk**: notifikasi push, riwayat penerimaan toko
  yang bisa di-export, atau peta rute — sesuai cakupan yang diminta.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
