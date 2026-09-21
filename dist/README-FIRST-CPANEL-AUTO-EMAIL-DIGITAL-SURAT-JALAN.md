# Amor Factory System — Panduan Email Otomatis ke Bakery + Surat Jalan Digital + Kirim Ulang Admin (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. Paket ini menambah **satu
migrasi database** (lewat halaman web yang sudah ada — tidak ada perintah
SQL manual, tidak ada Terminal/SSH) **dan** memerlukan **pengaturan email
pengirim (SMTP)** yang diisi manual di `config.php`.

**Baca ini setelah patch "Bukti Foto Konfirmasi Toko" dan "Koreksi Peran
Bukti Foto" sudah berjalan.** Paket ini adalah penyelesaian alur malam
hari: Admin bekerja jam kantor, FG/keberangkatan sering selesai malam
hari, toko bisa saja sudah tutup saat barang tiba, dan mungkin tidak ada
orang di toko untuk menandatangani apa pun malam itu. Setiap toko sudah
punya email — jadi begitu Driver **Konfirmasi Berangkat** untuk satu
pengiriman nyata, sistem otomatis mengirim email ke toko tersebut berisi
tautan langsung ke Surat Jalan Digital pengiriman itu.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang diperbaiki / ditambahkan

1. **Email otomatis per pengiriman.** Begitu Driver mengonfirmasi
   keberangkatan dan Shipment nyata tercipta, toko tujuan (jika sudah
   punya email terdaftar) langsung menerima email berisi: nama toko, No.
   Shipment, No. DO, nama Driver, grup pengiriman, waktu berangkat, daftar
   barang **yang benar-benar dikirim** (bukan seluruh rencana DO), dan
   tombol **"LIHAT SURAT JALAN & KONFIRMASI PENERIMAAN"**.

   Jika DO yang sama dipecah menjadi beberapa pengiriman (misalnya SHP-2
   oleh Driver A dan SHP-3 oleh Driver B), **masing-masing pengiriman
   mendapat emailnya sendiri** — tidak pernah digabung jadi satu email
   untuk seluruh DO.

2. **Email toko belum diisi TIDAK PERNAH menghentikan keberangkatan.**
   Jika toko belum punya email, Shipment dan pengurangan stok tetap
   berhasil seperti biasa. Statusnya menjadi **"Email Toko Belum Diisi"**
   — Admin bisa melihatnya dan memperbaikinya kapan saja.

3. **Kegagalan kirim email TIDAK PERNAH membatalkan pengiriman/stok.**
   Jika server email gagal terhubung (SMTP down, App Password salah,
   dll), Shipment tetap sah dan stok tetap berkurang seperti biasa.
   Statusnya menjadi **"Gagal"** dengan pesan singkat yang ramah (tidak
   pernah menampilkan password atau detail teknis server).

4. **Admin melihat status Email secara terpisah dari status Penerimaan.**
   Pada halaman Detail Konfirmasi Toko yang sudah ada, sekarang ada
   bagian **"Email Pengiriman"**: Status (Belum Dikirim/Terkirim/Gagal/
   Email Toko Belum Diisi), Tujuan, Percobaan, dan tombol **"Kirim Ulang
   Email"**. Dua status ini (Email vs Penerimaan) tidak pernah
   digabungkan menjadi satu.

5. **Kirim Ulang Email (Admin).** Berguna saat toko bilang emailnya tidak
   pernah sampai, SMTP gagal, atau email toko baru saja diperbaiki. Kirim
   Ulang HANYA mengirim ulang notifikasi — **tidak pernah** membuat
   Shipment baru, tidak pernah menyentuh stok, tidak pernah membuat/
   mereset konfirmasi penerimaan toko, dan tidak pernah mengubah DO.

6. **Master Data → Toko** sekarang punya kolom **"Email Penerimaan"**
   yang bisa langsung diedit dan disimpan per toko.

7. **Ringkasan Konfirmasi Toko** (daftar di halaman Konfirmasi Toko)
   sekarang menampilkan kolom **Driver** dan **Status Email** di samping
   kolom yang sudah ada.

8. **Aturan bukti foto (Toko mengunggah, Admin hanya meninjau)** dari
   patch sebelumnya **tidak berubah sama sekali**.

---

## Tentang keamanan tautan email

Tautan di email **tetap memakai token QR DO yang sudah ada** (64 karakter
acak) — **1 DO = 1 token**, tidak ada token baru per pengiriman. Karena
satu email mewakili satu pengiriman, tautannya menambahkan **petunjuk**
nomor pengiriman (`&shipment=3`) supaya toko langsung diarahkan ke kartu
pengiriman yang benar — tapi **token tetap satu-satunya kunci akses
sebenarnya**. Jika petunjuk nomor pengiriman salah/tidak cocok dengan DO
tersebut, sistem mengabaikannya dengan aman — tidak pernah membocorkan
pengiriman toko lain.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN.
- File `amor-factory-auto-email-digital-surat-jalan.zip` (dikirim bersama
  panduan ini).
- Kredensial migrasi database sementara (MIGRATION_DB_USER/PASS) — sama
  seperti saat meng-upgrade ke patch-patch sebelumnya.
- **Akun email pengirim** — salah satu dari:
  - Gmail dengan **App Password** (BUKAN password Gmail biasa Anda —
    lihat langkah 6 di bawah), atau
  - SMTP domain sendiri (misalnya `factory@amorgroup.id` dari cPanel
    Email Accounts Anda sendiri).

---

## Langkah-langkah

### 1. Backup (sangat disarankan)

cPanel → Backup Wizard → backup database `u7566812_factory` dan folder
`public_html/factory/`. Migrasi kali ini menambah 1 tabel baru
(`shipment_email_delivery`) dan 1 kolom baru (`store.email`) — aman,
tidak menyentuh tabel/kolom lama.

### 2. Upload & Extract (dengan Timpa/Overwrite)

Upload `amor-factory-auto-email-digital-surat-jalan.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Jika ditanya
"timpa atau lewati", selalu pilih **Timpa/Overwrite**. `config.php` Anda
TIDAK ikut ditimpa (tidak ada di dalam paket ini).

### 3. Periksa `config.php` masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 4. Tambahkan kredensial migrasi sementara

```php
'MIGRATION_DB_USER' => 'user_migrasi_anda',
'MIGRATION_DB_PASS' => 'password_migrasi_anda',
```

### 5. Login sebagai ADMIN, lalu terapkan migrasi 0009

Buka `https://factory.amorgroup.id/api/_admin-login/` dan login seperti
biasa. Lalu buka `https://factory.amorgroup.id/api/_upgrade/` — Anda
akan melihat migrasi `0009_shipment_email.php` tertulis sebagai
"pending". Klik tombol terapkan. Migrasi ini HANYA menambah kolom
`store.email` (boleh kosong) dan tabel baru `shipment_email_delivery` —
tidak ada tabel/kolom lama yang diubah atau dihapus.

### 6. Hapus kembali kredensial migrasi

Setelah migrasi berhasil, **hapus** dua baris `MIGRATION_DB_*` yang Anda
tambahkan di langkah 4, demi keamanan.

### 7. Tambahkan pengaturan SMTP (email pengirim) di `config.php`

Masih di `public_html/factory/api/app/config/config.php`, tambahkan
baris berikut (isi sesuai akun email pengirim Anda):

```php
'APP_BASE_URL' => 'https://factory.amorgroup.id',
'MAIL_ENABLED' => true,
'MAIL_HOST' => 'smtp.gmail.com',
'MAIL_PORT' => 587,
'MAIL_USERNAME' => 'alamat-pengirim@gmail.com',
'MAIL_PASSWORD' => 'ISI_DENGAN_APP_PASSWORD_16_KARAKTER',
'MAIL_ENCRYPTION' => 'tls',
'MAIL_FROM_ADDRESS' => 'alamat-pengirim@gmail.com',
'MAIL_FROM_NAME' => 'Amor Factory System',
```

**Jika memakai Gmail** — cara membuat App Password (bukan password Gmail
biasa Anda, dan TIDAK BISA memakai password biasa untuk ini):
1. Buka akun Google yang akan dipakai mengirim → **Security**.
2. Aktifkan **2-Step Verification** jika belum aktif (wajib untuk App
   Password).
3. Buka **App Passwords**, buat satu baru (nama bebas, misalnya "Amor
   Factory SMTP"), Google akan menampilkan **16 karakter** — itulah yang
   diisi ke `MAIL_PASSWORD` di atas (tanpa spasi).

**Jika memakai SMTP domain sendiri** (misalnya `factory@amorgroup.id`
dari cPanel Email Accounts) — isi `MAIL_HOST`/`MAIL_PORT`/
`MAIL_ENCRYPTION` sesuai pengaturan yang cPanel Email Accounts tampilkan
untuk akun tersebut (biasanya terlihat di menu "Connect Devices" pada
akun email itu), dan `MAIL_USERNAME`/`MAIL_PASSWORD` adalah alamat email
+ password akun email tersebut.

**PENTING — jangan pernah menempelkan password SMTP ke mana pun selain
`config.php` di server Anda sendiri**: jangan ke repository/Git, jangan
ke chat, jangan ke dokumen yang dibagikan. `config.php` sudah otomatis
diabaikan (gitignored) dan tidak pernah ikut dalam ZIP paket ini.

### 8. Isi email untuk minimal satu toko (untuk uji coba)

Masih sebagai Admin, buka **Master Data → Toko**. Cari satu toko (mis.
Bakery Abdul Gani / toko uji coba Anda), isi kolom **Email Penerimaan**
dengan alamat email nyata yang bisa Anda buka, lalu klik **Simpan**.

### 9. Uji coba: Driver Konfirmasi Berangkat

Login sebagai Driver (atau gunakan akun Driver uji coba Anda), ambil
("klaim") satu tugas pengiriman kecil untuk toko yang emailnya sudah
diisi di langkah 8, lalu **Konfirmasi Berangkat** seperti biasa.

### 10. Periksa email benar-benar sampai

Buka kotak masuk email toko tersebut (boleh beberapa saat, tergantung
kecepatan server email) — pastikan ada email baru dengan subjek
menyebutkan nomor Shipment yang baru saja dibuat.

### 11. Buka Surat Jalan Digital dari email

Klik tombol **"LIHAT SURAT JALAN & KONFIRMASI PENERIMAAN"** di email
tersebut — pastikan halaman yang terbuka menampilkan pengiriman yang
BENAR (bukan seluruh rencana DO), lengkap dengan nama Driver yang benar.

### 12. Uji Konfirmasi Penerimaan Toko

Isi Diterima Baik/Reject/Kurang seperti biasa dari halaman tersebut, dan
pastikan alur bukti foto (wajib jika ada Reject/Kurang) masih berjalan
seperti sebelumnya.

### 13. Uji simulasi kegagalan email (opsional tapi disarankan)

Untuk memastikan kegagalan email TIDAK membatalkan pengiriman: matikan
sementara akses internet server ke SMTP (atau sengaja salah isi
`MAIL_PASSWORD` sementara), lakukan satu Konfirmasi Berangkat lagi, lalu
pastikan: Shipment tetap berhasil dibuat, stok tetap berkurang, dan
status Email pada Detail Konfirmasi Toko menunjukkan **"Gagal"** (bukan
error yang menghentikan apa pun). Setelah selesai uji coba, kembalikan
`MAIL_PASSWORD`/koneksi ke keadaan benar.

### 14. Uji Admin Kirim Ulang Email

Buka halaman **Konfirmasi Toko** → pilih pengiriman dari langkah 9/13 →
**Lihat Detail** → di bagian "Email Pengiriman", klik **Kirim Ulang
Email**. Pastikan email sampai lagi dan status berubah menjadi
"Terkirim".

### 15. Pastikan tidak ada duplikasi Shipment/stok

Setelah langkah 14, periksa halaman Detail Pengiriman / riwayat stok —
pastikan HANYA ADA SATU Shipment dan satu pengurangan stok untuk
pengiriman tersebut (Kirim Ulang Email tidak pernah membuat Shipment
baru atau mengurangi stok lagi).

### 16. Cara mundur (rollback) jika ada masalah

Kembalikan file-file yang tertimpa dari backup langkah 1. Jika ingin
menonaktifkan pengiriman email otomatis tanpa mundur seluruh patch,
cukup ubah `MAIL_ENABLED` menjadi `false` di `config.php` — setiap
Shipment akan tetap berhasil seperti biasa, hanya status emailnya
menjadi "Gagal" dengan keterangan "belum dikonfigurasi".

---

## Risiko & Catatan penting

- **Tidak ada perubahan pada perhitungan stok, logika klaim Driver,
  token QR, aturan bisnis DO/FG/Production/PO, atau aturan bukti foto
  Toko** — paket ini murni menambah notifikasi email di atas alur
  Confirm Departure yang sudah ada.
- **Kegagalan email TIDAK PERNAH membatalkan Shipment/stok** — ini
  jaminan arsitektur inti patch ini, sudah diuji end-to-end (termasuk di
  bawah Apache/PHP-FPM asli, bukan hanya server pengujian).
- **Pengiriman email berjalan langsung (synchronous) di dalam proses
  yang sama saat Driver menekan Konfirmasi Berangkat** — bukan lewat
  antrean/worker terpisah (server cPanel shared hosting ini tidak
  menyediakan itu). Ini berarti respons ke Driver bisa sedikit lebih
  lambat (biasanya di bawah beberapa detik) saat server email lambat
  merespons — tetap jauh lebih baik daripada tidak ada notifikasi sama
  sekali, dan Driver tidak pernah melihat pesan seolah pengiriman gagal.
- **Password SMTP tidak pernah disimpan di database, tidak pernah masuk
  log, tidak pernah dikirim ke browser** — hanya ada di `config.php` di
  server Anda sendiri.
- **Belum ada percobaan ulang otomatis (cron retry)** untuk email yang
  gagal — Admin perlu mengklik Kirim Ulang Email secara manual saat
  diperlukan. Ini sudah cukup untuk kebutuhan saat ini; retry otomatis
  bisa ditambahkan nanti sebagai penyempurnaan terpisah.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
