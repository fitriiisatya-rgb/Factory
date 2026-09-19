# Amor Factory System — Panduan Patch "Refresh Produksi Terbaru" FG/Packing (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada perintah SQL
manual dan tidak ada Terminal/SSH untuk paket ini — dan tidak perlu
menerapkan migrasi apa pun** (paket ini hanya berisi file, tidak ada
perubahan database — kolom yang dibutuhkan sudah ada sejak awal).

**Baca ini setelah Phase 5.5 + Manajemen User sudah berjalan di server
Anda.** Paket ini memperbaiki masalah nyata yang ditemukan saat UAT: FG
Batch #1 tanggal 2026-09-05 (Karangtengah, Roti & Bollen) tidak bisa
menyegarkan angka BOLLEN KOMBINASI setelah Produksinya diedit dan
disubmit ulang (actual 0 → 2, versi 8 → 11).

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang diperbaiki

Sebelum patch ini: halaman FG sudah BENAR mendeteksi dan menampilkan
peringatan "Ketidaksesuaian Sumber Produksi" (versi tercatat 8, versi
sekarang 11), tapi tombol **"4. Muat Produksi Submitted"** hanya membuka
kembali tampilan batch yang sudah ada — TIDAK benar-benar menyegarkan
angkanya. Mengganti "Filter Divisi Sumber" juga tidak berpengaruh ke
batch yang sudah ada (filter ini memang hanya untuk pratinjau SEBELUM
draft dibuat).

Setelah patch ini: ada tombol baru **"Refresh Produksi Terbaru"** di FG
Batch yang sudah ada (status draft/reopened). Tombol ini HANYA
menyegarkan angka Produksi (Hasil Produksi/snapshot dan versi sumbernya)
— **tidak pernah** mengubah FG Terverifikasi, Packed, atau stok yang
sudah tercatat.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN (atau PPIC/PRODUCTION) yang sudah ada.
- File `amor-factory-api-phase4-fg-source-refresh-patch.zip` (dikirim
  bersama panduan ini).

**Tidak perlu** kredensial migrasi database — paket ini tidak mengubah
struktur database sama sekali.

---

## Langkah-langkah

### 1. Backup (tetap disarankan, walau paket ini hanya file)

cPanel → Backup Wizard → backup folder `public_html/factory/`. Paket ini
tidak menyentuh database sama sekali, tapi backup file tetap kebiasaan
yang baik sebelum menimpa file apa pun di server.

### 2. Upload & Extract

Upload `amor-factory-api-phase4-fg-source-refresh-patch.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Hasilnya bergabung
ke folder `public_html/factory/api/` yang sudah ada — `config.php` Anda
TIDAK ikut ditimpa.

### 3. Periksa `config.php` masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 4. Login dan buka FG Batch #1 (2026-09-05, Karangtengah)

Buka `https://factory.amorgroup.id/api/_fg-uat/`, login, pilih tanggal
**2026-09-05** dan pabrik **Karangtengah**, lalu klik **"4. Muat Produksi
Submitted"**. Anda akan melihat kotak peringatan kuning "Ketidaksesuaian
Sumber Produksi" seperti sebelumnya — sekarang dengan tombol **"Refresh
Produksi Terbaru"** di dalamnya.

### 5. Klik "Refresh Produksi Terbaru"

Klik tombol tersebut. Setelah selesai:
- BOLLEN KOMBINASI akan menampilkan Hasil Produksi **2** (sebelumnya 0).
- Versi sumber berubah menjadi 11 (kotak peringatan hilang jika semua
  sumber sudah sinkron).
- Baris BIG BANANA CHOCOCHEESE (dan produk lain yang sudah diisi
  FG Terverifikasi/Packed sebelumnya) **TIDAK berubah sama sekali**.
- **Tidak ada** pergerakan stok — kolom Available tetap sama seperti
  sebelum refresh.

### 6. Isi FG Terverifikasi untuk BOLLEN KOMBINASI, lalu submit seperti biasa

Sekarang Hasil Produksi BOLLEN KOMBINASI sudah benar (2), isi FG
Terverifikasi/Packed-nya seperti biasa dan lanjutkan submit FG seperti
alur normal.

### 7. Kalau FG Terverifikasi ternyata lebih besar dari Produksi terbaru

Jika refresh menemukan produk yang FG Terverifikasi-nya SUDAH lebih besar
dari Produksi Actual terbaru (misalnya Produksi turun dari 10 ke 6,
padahal FG Terverifikasi sudah diisi 8), sistem **tidak akan pernah**
menurunkan angka FG Terverifikasi itu sendiri secara otomatis. Sistem
akan menampilkan kotak merah **"Diblokir untuk Submit"** dan menolak
tombol Submit sampai Admin/PPIC/Produksi menurunkan angka FG Terverifikasi
itu secara manual terlebih dahulu.

### 8. Cara mundur (rollback) jika ada masalah

Karena paket ini hanya mengubah file kode (tidak ada perubahan database),
cara mundur paling aman adalah mengembalikan file-file yang tertimpa dari
backup langkah 1. Tidak ada data yang perlu dibersihkan di database —
tombol baru ini hanya membaca dan menulis kolom yang sudah ada sejak
migrasi 0001/0005.

---

## Catatan penting

- **Refresh Produksi Terbaru TIDAK PERNAH menulis ke stock_ledger** —
  hanya Submit/Resubmit FG yang tetap memposting stok, persis seperti
  sebelumnya.
- **FG Terverifikasi dan Packed yang sudah diisi TIDAK PERNAH berubah**
  oleh tombol refresh ini — hanya angka Hasil Produksi (snapshot) dan
  versi sumber yang disegarkan.
- **"Filter Divisi Sumber" sekarang dinonaktifkan** begitu FG Batch untuk
  tanggal/pabrik itu sudah ada — filter ini tetap hanya untuk pratinjau
  SEBELUM draft dibuat, sekarang lebih jelas di layar.
- **Tombol ini tersedia untuk role ADMIN, PPIC, dan PRODUCTION** — sama
  seperti hak akses menyimpan/submit FG lainnya.
- **FG yang sudah berstatus submitted harus di-"Buka Kembali (Reopen)"
  dulu** sebelum bisa memakai tombol Refresh Produksi Terbaru — persis
  seperti aturan edit FG lainnya.
- Halaman modern (`api/app/ui/pages/fg-packing.php`, jika Anda memakai
  tampilan admin baru) mendapat tombol dan peringatan yang sama.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
