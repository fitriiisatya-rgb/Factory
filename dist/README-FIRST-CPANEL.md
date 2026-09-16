# Amor Factory — Panduan Instalasi Database (cPanel)

**Baca ini dulu sebelum melakukan apa pun.** Panduan ini untuk siapa saja,
walau tidak familiar dengan database atau coding. Tidak ada langkah yang
mengharuskan Anda menjalankan perintah SQL manual atau membuka Terminal.

**Apa yang TIDAK dilakukan oleh paket ini** (penting untuk diketahui di
awal): tidak menyentuh tampilan/frontend Amor Factory yang sudah ada, tidak
mematikan Apps Script yang sedang berjalan, tidak mengimpor data lama (PO,
produksi, pengiriman, invoice), dan tidak membuat fitur bisnis baru. Paket
ini HANYA memasang struktur database kosong + data minimum + 1 akun admin,
sebagai persiapan untuk tahap berikutnya.

Setelah selesai, status akhirnya adalah **"siap untuk direview"**, BUKAN
**"siap produksi."**

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel untuk `factory.amorgroup.id`
- Nama database: `u7566812_factory` (saat ini kosong)
- Username database migrasi: `u7566812_adminfactory` (mintakan/reset
  passwordnya di cPanel → MySQL Databases kalau belum tahu)
- File `amor-factory-api-preprod.zip` (satu file ini saja yang perlu diupload)

---

## STEP 1 — Upload ZIP

1. Masuk ke cPanel → **File Manager**.
2. Buka folder **`public_html`** (folder ini berisi tampilan Amor Factory
   yang sudah ada — jangan hapus atau ubah apa pun yang sudah ada di sana).
3. Klik **Upload**, pilih file `amor-factory-api-preprod.zip`, tunggu sampai selesai.

**Hasil yang diharapkan**: file `amor-factory-api-preprod.zip` muncul di
dalam folder `public_html`.

---

## STEP 2 — Extract

1. Masih di dalam `public_html`, klik kanan file `amor-factory-api-preprod.zip`.
2. Pilih **Extract**.
3. Tunggu sampai proses selesai, lalu (opsional) hapus file ZIP-nya —
   isinya sudah tersalin ke folder baru.

**Hasil yang diharapkan**: muncul folder baru **`public_html/api/`** berisi
beberapa file dan folder (`index.php`, `_setup/`, `app/`, dll). Tampilan
Amor Factory yang lama TIDAK berubah sama sekali — folder `api/` ini
berdiri sendiri di sampingnya.

---

## STEP 3 — Isi konfigurasi privat (tempat password database disimpan)

1. Masuk ke folder `public_html/api/app/config/`.
2. Klik kanan file **`config.example.php`** → **Copy**.
3. Beri nama salinannya **`config.php`** (di folder yang sama).
4. Klik kanan `config.php` → **Edit** (atau **Code Editor**).
5. Cari baris `'DB_PASS' => 'CHANGE_ME',` dan ganti `CHANGE_ME` dengan
   password asli untuk user `u7566812_adminfactory` (dari cPanel → MySQL
   Databases).
6. Cari baris `'SETUP_TOKEN' => '',` dan isi dengan kode acak yang panjang
   — bisa ketik sembarang huruf/angka panjang sendiri (minimal 32 karakter),
   atau minta developer menjalankan `php dist/generate-setup-token.php` dan
   salin hasilnya. **Catat kode ini** — Anda akan membutuhkannya di STEP 4.
7. Simpan file.

**Hasil yang diharapkan**: file `public_html/api/app/config/config.php`
sudah ada dan sudah terisi. File ini **tidak bisa diakses lewat browser**
(sudah diblokir otomatis) — aman menyimpan password di sini.

**PENTING**: jangan pernah kirim isi file ini ke siapa pun lewat chat,
email, atau tiket support. Kalau perlu bantuan developer, tunjukkan
strukturnya tanpa menyalin nilai `DB_PASS`/`SETUP_TOKEN` yang sebenarnya.

---

## STEP 4 — Buka halaman Setup

Buka browser, kunjungi (ganti `KODE_ANDA` dengan token dari STEP 3):

```
https://factory.amorgroup.id/api/_setup/?token=KODE_ANDA
```

**Hasil yang diharapkan**: halaman **"Amor Factory — Setup Database
(Preproduksi)"** muncul, dengan tabel 7 langkah. Langkah 1 dan 2 sudah
otomatis terisi (lihat STEP 5).

**Kalau muncul "403 Forbidden"**: token di URL tidak cocok dengan yang ada
di `config.php` — cek lagi STEP 3.
**Kalau muncul halaman "Konfigurasi belum lengkap"**: ada isian di
`config.php` yang masih kosong/salah — ikuti instruksi yang tertulis di
halaman itu.

---

## STEP 5 — Cek Koneksi (otomatis, tidak perlu klik apa pun)

Halaman langsung menampilkan status untuk:
- **1. Koneksi** — harus **BERHASIL** (PHP versi cukup baru, ekstensi
  database aktif, berhasil connect).
- **2. Kompatibilitas Database** — harus **BERHASIL** (nama database
  cocok, versi MariaDB terbaca).

**Kalau salah satu ERROR**: pesan di halaman itu sendiri menjelaskan apa
yang salah (biasanya password di `config.php` salah, atau nama database
tidak cocok). Perbaiki `config.php`, lalu muat ulang halaman.

---

## STEP 6 — Install Struktur Database

Begitu langkah 1–2 hijau, bagian **"3. Instalasi Struktur Database"**
menampilkan tombol.

1. Baca penjelasan singkat di kotak kuning (akan membuat 45 tabel + 1 tabel
   pencatatan, tidak menghapus apa pun karena database masih kosong).
2. Centang kotak konfirmasi.
3. Klik **"Install Database Schema"**.

**Hasil yang diharapkan**: pesan hijau "Instalasi struktur database
berhasil", dan baris ke-3 di tabel berubah jadi **BERHASIL**.

**Kalau ERROR**: catat pesan errornya persis, dan hentikan — jangan
mengulangi berkali-kali. Ini kemungkinan besar bukan kesalahan Anda;
teruskan pesan errornya ke developer.

---

## STEP 7 — Install Data Awal

Begitu langkah 3 hijau, bagian **"4. Data Awal"** menampilkan tombol.

1. Centang kotak konfirmasi.
2. Klik **"Install Data Awal"**.

Ini memasang: 2 pabrik (Karangtengah, Cibadak), 7 peran pengguna (ADMIN,
PPIC, PRODUCTION, FG_PACKING, DELIVERY, FINANCE, MANAGEMENT_VIEWER), dan 1
toko khusus (NON-OUTLET / PERORANGAN untuk pelanggan tanpa toko). **Tidak
ada data produk atau transaksi yang dipasang di sini.**

**Hasil yang diharapkan**: pesan hijau "Data awal berhasil dipasang", baris
ke-4 jadi **BERHASIL**.

---

## STEP 8 — Buat Admin Pertama

Begitu langkah 4 hijau, bagian **"5. Buat Admin"** menampilkan form.

1. Isi **Username** (contoh: `admin`).
2. Isi **Nama Lengkap**.
3. Isi **Password** — minimal 10 karakter, kombinasikan huruf/angka.
4. Ulangi password yang sama di **Konfirmasi Password**.
5. Klik **"Buat Admin"**.

**Simpan username & password ini di tempat aman** (password manager, atau
dicatat dan disimpan fisik) — halaman ini tidak akan menampilkannya lagi
setelah ini, dan tidak dicatat di log mana pun.

**Hasil yang diharapkan**: pesan hijau "Admin berhasil dibuat", baris ke-5
dan ke-6 (Verifikasi) jadi **BERHASIL**, dan tabel ringkasan di bagian
"6. Verifikasi" menunjukkan semuanya OK (45 tabel, 2 pabrik, 7 peran, 1
toko, admin CREATED).

---

## STEP 9 — Buat User Database Khusus untuk Aplikasi

Sampai titik ini, aplikasi masih memakai user `u7566812_adminfactory` yang
punya akses penuh (buat/hapus tabel). Untuk keamanan, buat user BARU yang
hanya boleh baca/tulis data, tidak boleh mengubah struktur:

1. cPanel → **MySQL Databases**.
2. Bagian **"MySQL Users"** → **Create New User**. Contoh nama:
   `u7566812_factoryapp`. Isi password baru yang kuat (beda dari yang
   migrasi). Catat password ini di tempat aman.
3. Scroll ke **"Add User To Database"** → pilih user baru ini + database
   `u7566812_factory` → **Add**.
4. Di halaman privilege yang muncul, centang **HANYA** empat ini:
   **SELECT**, **INSERT**, **UPDATE**, **DELETE**. Jangan centang yang
   lain (terutama jangan `CREATE`/`ALTER`/`DROP`/`INDEX`).
5. **Make Changes** / Simpan.
6. Buka lagi `public_html/api/app/config/config.php`, ganti:
   ```
   'DB_USER' => 'u7566812_factoryapp',
   'DB_PASS' => '<password baru dari langkah 2>',
   ```
   Simpan.

**Hasil yang diharapkan**: setelah disimpan, buka
`https://factory.amorgroup.id/api/health` — harus tetap menunjukkan
`"db":"connected"` (lihat STEP 11).

---

## STEP 10 — Nonaktifkan / Hapus Halaman Setup

**Wajib dilakukan, jangan dilewati.**

1. Kembali ke halaman `_setup` (STEP 4), scroll ke bagian **"7. Selesai"**.
2. Klik **"Selesai & Nonaktifkan Setup"**, konfirmasi.
3. Halaman ini sekarang otomatis menolak semua akses (404).
4. **Langkah paling aman** (lakukan juga, kapan pun sempat): di File
   Manager, hapus seluruh folder `public_html/api/_setup/`.

**Hasil yang diharapkan**: membuka ulang URL dari STEP 4 menampilkan
halaman "tidak ditemukan", bukan halaman setup.

---

## STEP 11 — Test API

Buka di browser:

```
https://factory.amorgroup.id/api/health
```

**Hasil yang diharapkan**:
```
{"ok":true,"data":{"ok":true,"env":"preproduction","db":"connected","dbVersion":"10.11.19-MariaDB-cll-lve","schemaVersion":"v1"}}
```

Kalau `"db":"disconnected"` — kemungkinan STEP 9 salah isi user/password
baru. Perbaiki `config.php` lagi.

Untuk pengecekan lebih lengkap (login, buat produk percobaan, dll), lihat
daftar CP-01 sampai CP-20 di `api/DEPLOY-CPANEL-PREPROD.md` — ini boleh
diserahkan ke developer untuk dijalankan, tidak wajib dilakukan sendiri.

---

## STEP 12 — Backup

Setelah STEP 1–11 semuanya berhasil, minta developer (atau gunakan fitur
backup cPanel → **Backup Wizard** → pilih database `u7566812_factory`) untuk
membuat satu backup database. Ini titik aman untuk kembali kalau ada
masalah di tahap berikutnya (migrasi data lama). Backup ini disimpan di
komputer/penyimpanan Anda sendiri — **jangan diunggah ke tempat publik**.

---

## Kalau ada yang salah di tengah jalan

- Halaman `_setup` selalu boleh dibuka ulang — tidak ada langkah yang
  hilang atau rusak kalau Anda menutup browser di tengah proses.
- Mengulangi STEP 6/7 tidak akan membuat data dobel (sudah dirancang aman
  untuk diulang).
- Tidak ada tombol "Hapus Database" atau "Reset" di halaman mana pun dalam
  paket ini, dan memang sengaja tidak dibuat.
- Kalau benar-benar buntu, kirim pesan error yang muncul ke developer —
  jangan sertakan isi `config.php` di pesan itu.
