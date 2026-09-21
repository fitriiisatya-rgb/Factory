# Amor Factory System — Panduan Hotfix Navigasi Rute / Riwayat / Logout Driver (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada perintah SQL
manual dan tidak ada Terminal/SSH untuk paket ini — dan tidak perlu
menerapkan migrasi apa pun** (paket ini hanya berisi file, tidak ada
perubahan database). **Jangan jalankan `/api/_upgrade/` untuk paket ini.**

**Baca ini setelah patch Driver Portal UX + Shipment Tracing sudah
berjalan di server Anda.** Paket ini memperbaiki laporan UAT nyata pada
DO `DO/KRM/004/IX/2026`, Bakery Abdul Gani, Driver A, tanggal 2026-09-05:
setelah "Rute Saya" berhasil menampilkan angka yang benar, toko yang
sudah berangkat masih membuka layar yang salah saat diklik, kartu Riwayat
tidak bisa diklik, dan tombol Logout tidak bereaksi.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang diperbaiki

1. **Toko yang sudah "Sudah Berangkat" di Rute Saya sekarang membuka
   halaman yang BENAR.** Sebelumnya, mengklik toko yang sudah berangkat
   membuka layar "Konfirmasi Berangkat" yang menampilkan pesan
   membingungkan "Tidak ada klaim aktif Anda untuk toko ini." — pesan itu
   sendiri sebenarnya sudah benar (klaim memang sudah selesai), tapi itu
   halaman yang SALAH untuk dibuka. Sekarang:
   - Jika toko itu hanya punya SATU pengiriman → langsung membuka
     **Detail Pengiriman** toko tersebut.
   - Jika toko itu punya LEBIH dari satu pengiriman (misalnya terpisah
     MAIN dan PASTRY untuk DO yang sama) → membuka daftar pilihan
     **"Pengiriman untuk <nama toko>"** berisi setiap pengiriman
     (No. Shipment + grup + waktu), dan Anda memilih salah satu — sistem
     TIDAK PERNAH menebak/memilih otomatis salah satu jika ada lebih dari
     satu.
2. **Jika Anda membuka link "Konfirmasi Berangkat" yang sudah usang**
   (misalnya dari bookmark lama) untuk toko yang ternyata sudah
   berangkat, pesannya sekarang **"Pengiriman ini sudah
   diberangkatkan."** disertai tombol **Lihat Detail Pengiriman** —
   bukan lagi jalan buntu.
3. **Kartu Riwayat Pengiriman dan tombol Logout sudah diaudit ulang dan
   kodenya terbukti benar** (diuji langsung dengan browser sungguhan,
   bukan hanya tinjauan kode) — kemungkinan besar penyebab di server
   nyata Anda adalah file CSS/JS lama yang belum benar-benar tertimpa
   saat ekstrak sebelumnya, atau ter-cache di browser HP meskipun sudah
   di-refresh. Paket ini menambahkan **kode versi otomatis** pada URL
   `driver.css`/`driver.js`/`app.js` supaya browser SELALU mengambil file
   terbaru, apa pun yang terjadi pada percobaan ekstrak sebelumnya.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- File `amor-factory-driver-navigation-logout-hotfix.zip` (dikirim
  bersama panduan ini).

**Tidak perlu** kredensial migrasi database — paket ini tidak mengubah
struktur database sama sekali.

---

## Langkah-langkah

### 1. Backup (tetap disarankan, walau paket ini hanya file)

cPanel → Backup Wizard → backup folder `public_html/factory/`.

### 2. Upload & Extract — PENTING: pastikan file LAMA benar-benar tertimpa

Upload `amor-factory-driver-navigation-logout-hotfix.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**.

Beberapa File Manager cPanel akan bertanya "file sudah ada, timpa atau
lewati?" — **selalu pilih "Timpa/Overwrite", jangan pernah "Lewati/Skip"**
untuk file di dalam paket ini. Ini penting khususnya untuk:
`api/assets/js/driver.js`, `api/assets/css/driver.css`, dan
`api/assets/js/app.js` — jika salah satu di antaranya ter-skip, perbaikan
ini tidak akan aktif walaupun file PHP lainnya sudah baru.

`config.php` Anda **TIDAK ikut ditimpa** (tidak ada di dalam paket ini
sama sekali).

### 3. Periksa `config.php` masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 4. Tidak ada migrasi — JANGAN jalankan `/api/_upgrade/`

Paket ini TIDAK memerlukan langkah migrasi apa pun. Melewati/skip langkah
ini adalah perilaku yang BENAR untuk paket ini.

### 5. Periksa `/api/health`

Buka `https://factory.amorgroup.id/api/health` di browser — harus
menampilkan respons OK (bukan halaman error). Ini memastikan aplikasi
masih berjalan normal sebelum Anda lanjut menguji Driver Portal.

### 6. Hard refresh Driver Portal

Buka `https://factory.amorgroup.id/api/_driver-uat/` di HP/browser
driver, lalu lakukan **hard refresh** (tutup tab lalu buka lagi, atau
refresh sambil menahan tombol reload). Paket ini sekarang menambahkan
kode versi otomatis pada file CSS/JS sehingga langkah ini seharusnya
tidak lagi diperlukan untuk update berikutnya — tapi tetap lakukan sekali
untuk memastikan browser mengambil halaman HTML terbaru.

### 7. Login sebagai Driver A

Login menggunakan akun Driver yang sebelumnya dipakai UAT.

### 8. Buka Rute Saya

Buka tab **Rute Saya**. Cari toko yang sudah berstatus **"Sudah
Berangkat"** (misalnya Bakery Abdul Gani).

### 9. Klik toko "Sudah Berangkat" — verifikasi Detail Pengiriman terbuka

Klik kartu toko tersebut. Pastikan:
- Layar yang terbuka adalah **Detail Pengiriman** (menampilkan No.
  Shipment asli, produk yang dikirim, status, dsb) — **BUKAN** layar
  "Konfirmasi Berangkat" dan **BUKAN** pesan "Tidak ada klaim aktif".
- Jika toko itu punya lebih dari satu pengiriman, yang terbuka adalah
  daftar pilihan **"Pengiriman untuk <toko>"** — pilih salah satu untuk
  melihat detailnya.

### 10. Buka Riwayat

Buka tab **Riwayat** — pastikan setiap kartu bisa diklik, dan mengklik
salah satu benar-benar membuka **Detail Pengiriman** yang sesuai (nomor
shipment, produk, jumlah yang cocok dengan kartu yang diklik).

### 11. Uji Logout

Tap ikon Logout di pojok kanan atas. Pastikan:
- Muncul dialog konfirmasi "Logout?" di tengah layar.
- Menekan **Ya, Logout** membawa Anda ke halaman **Login Driver**.

### 12. Konfirmasi portal butuh login lagi

Setelah logout, coba buka lagi
`https://factory.amorgroup.id/api/_driver-uat/` — pastikan Anda diminta
login kembali, bukan langsung masuk ke portal.

### 13. Cara mundur (rollback) jika ada masalah

Karena paket ini hanya mengubah file kode (tidak ada perubahan database),
cara mundur paling aman adalah mengembalikan file-file yang tertimpa dari
backup langkah 1. Tidak ada data yang perlu dibersihkan di database.

---

## Catatan penting

- **Tidak ada perubahan pada perhitungan stok** — navigasi baru ini
  murni tentang halaman mana yang dibuka; logika pengurangan stok FG saat
  berangkat, aturan klaim/pool/pelepasan klaim Driver, aturan Konfirmasi
  Toko, dan logika QR semuanya persis sama seperti sebelumnya.
- **Halaman "Pengiriman untuk <toko>" dan Detail Pengiriman bersifat
  read-only** — tidak ada tombol edit/hapus di halaman-halaman ini.
- Jika setelah langkah 2 (extract dengan overwrite) masalah Riwayat/
  Logout masih terjadi, kemungkinan besar ada CDN/proxy caching di depan
  domain Anda (di luar cPanel) yang masih menyimpan salinan lama — hubungi
  penyedia hosting/CDN untuk melakukan purge cache, karena kode versi
  otomatis pada file ini seharusnya sudah memaksa browser mengambil versi
  baru.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
