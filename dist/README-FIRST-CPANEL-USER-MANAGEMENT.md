# Amor Factory System — Panduan Manajemen User & Akun Driver (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada perintah SQL
manual dan tidak ada Terminal/SSH untuk paket ini — dan tidak perlu
menerapkan migrasi apa pun** (paket ini hanya berisi file, tidak ada
perubahan database).

**Baca ini setelah Phase 5.5 (Dispatch/Driver/Konfirmasi Toko) sudah
berjalan.** Paket ini menambahkan satu halaman baru: **Manajemen User**,
tempat Admin bisa membuat, mengedit, dan mengatur akun login — termasuk
akun Driver — tanpa perlu phpMyAdmin atau perintah SQL sama sekali.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN yang sudah ada.
- File `amor-factory-api-user-management-easy.zip` (dikirim bersama
  panduan ini).

**Tidak perlu** kredensial migrasi database — paket ini tidak mengubah
struktur database sama sekali.

---

## Langkah-langkah

### 1. Backup (tetap disarankan, walau paket ini hanya file)

cPanel → Backup Wizard → backup folder `public_html/factory/`. Paket ini
tidak menyentuh database sama sekali, tapi backup file tetap kebiasaan
yang baik sebelum menimpa file apa pun di server.

### 2. Upload & Extract

Upload `amor-factory-api-user-management-easy.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Hasilnya bergabung
ke folder `public_html/factory/api/` yang sudah ada — `config.php` Anda
TIDAK ikut ditimpa.

### 3. Periksa `config.php` masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 4. Login sebagai ADMIN

Buka `https://factory.amorgroup.id/api/_admin-login/` dan login seperti
biasa. Halaman login ini tidak berubah sama sekali.

### 5. Buka halaman Manajemen User

Buka `https://factory.amorgroup.id/api/_users-uat/`. Anda akan melihat
daftar semua user yang sudah ada (termasuk akun Admin Anda sendiri),
lengkap dengan peran (role) dan status aktif/nonaktif masing-masing.

### 6. Buat akun Driver A

Klik **+ Tambah User**. Isi:
- **Username**: `driver.a`
- **Nama Lengkap**: nama asli driver, misalnya "Budi Santoso"
- **Password** & **Konfirmasi Password**: buat password sementara
  (minimal 10 karakter), beri tahu driver secara langsung/lisan — jangan
  dikirim lewat chat/email yang tidak aman
- **Peran**: centang **Driver**

Klik **Simpan**. Akun langsung aktif dan bisa dipakai login.

### 7. Buat akun Driver B

Ulangi langkah 6 dengan username `driver.b` dan nama driver kedua.

### 8. Uji login Driver

Logout dari Admin, lalu buka
`https://factory.amorgroup.id/api/_driver-uat/login.php` dan login
dengan akun `driver.a` yang baru dibuat. Driver akan langsung masuk ke
tab **Tersedia** — persis seperti akun driver yang dibuat sebelumnya
secara manual.

### 9. Lanjutkan UAT Phase 5.5

Dengan Driver A dan Driver B sudah bisa login, lanjutkan pengujian alur
Phase 5.5 (Ambil Pengiriman → Rute → Konfirmasi Berangkat → Konfirmasi
Penerimaan Toko) seperti biasa menggunakan kedua akun ini.

### 10. Cara mundur (rollback) jika ada masalah

Karena paket ini hanya menambah file baru (`api/_users-uat/`, beberapa
file di `api/app/src/Users/`, `api/app/src/Controllers/UserController.php`,
`api/assets/js/users.js`) dan TIDAK ADA perubahan database, cara mundur
paling aman adalah:
- Menghapus folder `api/_users-uat/` lewat File Manager (menonaktifkan
  halaman ini tanpa memengaruhi Phase 1-5.5 sama sekali), ATAU
- Mengembalikan file-file yang tertimpa dari backup langkah 1.
- Akun user yang sudah dibuat lewat halaman ini TETAP ADA di database
  (karena disimpan di tabel `users`/`roles`/`user_roles` yang sudah ada
  sejak awal) — menghapus foldernya hanya menyembunyikan halamannya,
  bukan menghapus akun.

---

## Catatan penting

- **Ini bukan sistem HR** — hanya untuk mengatur akun login dan akses
  (username, password, peran, status aktif/nonaktif). Tidak ada data
  gaji, jadwal kerja, atau profil karyawan lengkap.
- **Sistem tidak akan pernah membiarkan Anda menonaktifkan atau
  menghapus peran Admin dari SATU-SATUNYA akun Admin aktif yang
  tersisa** — ini pengaman otomatis supaya Anda tidak pernah terkunci
  dari sistem sendiri. Jika Anda mencoba, sistem akan menolak dan
  meminta Anda membuat/mengaktifkan Admin lain terlebih dahulu.
- **Akun yang dinonaktifkan tidak dihapus** — datanya tetap ada, hanya
  tidak bisa dipakai login sampai diaktifkan kembali oleh Admin.
- **Reset Password** langsung mengganti password akun tersebut — tidak
  ada email otomatis, jadi sampaikan password baru ke pemilik akun
  secara langsung.
- **Halaman ini khusus Admin** — akun Driver atau peran lain yang
  mencoba membuka `api/_users-uat/` akan ditolak otomatis.
- **Fitur ini BELUM termasuk**: undangan lewat email, lupa password
  mandiri (self-service forgot-password), Single Sign-On (SSO), atau
  profil karyawan lengkap ala HR — sesuai cakupan yang diminta.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
