# Amor Factory — Panduan Phase 1 EASY V2 (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada langkah yang
mengharuskan Anda menjalankan perintah SQL manual atau membuka Terminal/SSH
— semua lewat klik di File Manager dan browser biasa.**

**Path yang BENAR di server Anda**: domain `factory.amorgroup.id` document
root-nya adalah `public_html/factory/` (BUKAN `public_html/` itu sendiri).
Jadi folder API akan berada di `public_html/factory/api/`. Alamat web tetap
`https://factory.amorgroup.id/api/...` seperti biasa — hanya lokasi
upload/extract di File Manager yang perlu `public_html/factory/`.

**Jangan pernah menyentuh `public_html/factory/index.php`** — itu file
tampilan Amor Factory yang sudah berjalan sekarang. Tidak ada langkah di
bawah ini yang menimpanya.

**Apa yang paket ini TIDAK lakukan**: tidak menghapus/mengosongkan tabel
apa pun, tidak mengimpor data transaksi (PO, produksi, FG, pengiriman,
stok, invoice, pembayaran, retur, penjualan), tidak mengganti password
database Anda yang sudah ada, dan tidak memasang ulang wizard setup Phase 0
(sudah Anda hapus — tetap begitu).

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN yang sudah Anda buat sewaktu Phase 0.5.
- File `amor-factory-api-phase1-easy-v2.zip` (dikirim bersama panduan ini).
- Password untuk database user `u7566812_adminfactory` (user khusus
  migrasi/struktur — biasanya diberikan oleh siapa pun yang membuat
  database Anda; ini BUKAN password aplikasi sehari-hari).

---

## Langkah-langkah

### 1. Buka cPanel → File Manager → `public_html` → `factory`

Masuk ke folder `public_html/factory/` — folder ini berisi tampilan Amor
Factory yang sudah ada (jangan buka folder lain).

### 2. Upload `amor-factory-api-phase1-easy-v2.zip` ke dalam folder `public_html/factory/`

Gunakan tombol **Upload** di File Manager, pastikan Anda masih berada
**di dalam** `public_html/factory/` (bukan di `public_html/` saja) saat
mengupload.

### 3. Extract ZIP tersebut di tempat yang sama

Klik kanan file ZIP → **Extract**. Hasilnya akan bergabung ke folder
`api/` yang sudah ada di `public_html/factory/api/` — file konfigurasi
Anda (`config.php`) TIDAK ikut ditimpa karena ZIP ini memang tidak berisi
file itu sama sekali.

### 4. Periksa `config.php` Anda masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.
Jika masih ada dan tidak kosong, lanjut ke langkah berikutnya.

### 5. Tambahkan 4 baris kredensial migrasi SEMENTARA ke `config.php`

Masih di file yang sama, tambahkan (atau isi jika sudah ada tapi kosong):

```php
'MIGRATION_DB_HOST' => 'localhost',
'MIGRATION_DB_NAME' => 'u7566812_factory',
'MIGRATION_DB_USER' => 'u7566812_adminfactory',
'MIGRATION_DB_PASS' => '<isi password user adminfactory di sini>',
```

Simpan file. Ini **aman** — kredensial ini terpisah total dari akun
aplikasi sehari-hari, dan akan Anda kosongkan lagi setelah langkah 7.

### 6. Buka halaman login admin dan masuk

Buka `https://factory.amorgroup.id/api/_admin-login/` di browser, login
dengan akun ADMIN Anda (yang sama dari Phase 0.5). Jika berhasil, Anda
akan melihat "Login berhasil sebagai ADMIN" dengan 2 tombol: **Upgrade
Database** dan **Import Master**.

### 7. Klik "Upgrade Database" dan terapkan pembaruan struktur

Halaman akan menunjukkan 1 migrasi yang menunggu (`0002_master_identity`).
Centang kotak konfirmasi, klik **Terapkan Migrasi**. Tunggu sampai muncul
pesan "Migrasi berhasil diterapkan".

### 8. Kosongkan lagi `MIGRATION_DB_PASS` di `config.php`

Kembali ke File Manager Editor, buka `config.php` yang sama, kosongkan
nilai `MIGRATION_DB_PASS` (jadi `''`) atau hapus baris itu. Simpan. Halaman
Upgrade Database itu sendiri sudah mengingatkan Anda melakukan ini setelah
migrasi berhasil.

### 9. Buka "Import Master" dan pasang divisi

Dari halaman login admin, klik **Import Master** (atau buka
`https://factory.amorgroup.id/api/_import-master/`). Di bagian "1. Divisi +
Pabrik", periksa daftarnya (termasuk **Cookies** yang ditandai "BARU DARI
SOURCE" — ini divisi yang baru ditemukan langsung dari source code, bukan
dari daftar awal), centang konfirmasi, klik **Import Divisi**.

### 10. Import produk katalog bawaan

Di bagian "2. Produk", Anda akan melihat **472 produk** yang akan dibuat,
dengan peringatan jelas bahwa **HPP belum dimigrasikan** (semua produk baru
ini punya HPP = 0, bukan biaya produksi sungguhan). Centang konfirmasi,
klik **Import Produk (SAFE)**.

### 11. Import toko yang sudah pasti (dari source code)

Di bagian "3. Toko — Sumber Terkonfirmasi", ada peringatan **"Store master
source incomplete"** — hanya SATU toko (BAKERY CIKOLE, dengan alias
CKLE/CIKOLE) yang benar-benar tertulis di source code. Centang konfirmasi,
klik **Import Toko Sumber**.

### 12. Review satu per satu 24 kandidat nama toko

Di bagian "4. Toko — Kandidat Review", ada 24 nama toko yang berasal dari
informasi operasional Anda sendiri (BUKAN dari source code) — setiap baris
butuh keputusan Anda:
- **CONFIRM** — jika nama sudah benar (boleh diedit dulu di kotak teks
  sebelum klik CONFIRM jika ejaannya perlu diperbaiki), toko ini akan
  benar-benar dibuat.
- **SKIP** — jika Anda belum yakin/belum ingin memasang toko ini sekarang;
  bisa direview lagi kapan saja nanti (halaman ini tidak akan menghapus
  keputusan Anda, dan status SKIP bisa direview ulang dengan membuka
  kembali halaman ini).

Catatan khusus: **"Bakery Cikole"** akan otomatis dikenali sebagai toko
yang sama dengan BAKERY CIKOLE di langkah 11 — tidak akan dobel. Alias
**SDRM** hanya akan aktif pada saat Anda meng-CONFIRM **"Bakery Sudirman"**
— sebelum itu, alias tersebut tidak ada sama sekali.

Jika ada toko yang tidak ada di daftar mana pun, gunakan formulir
**"5. Tambah Toko Manual"** di bagian bawah halaman yang sama.

### 13. Pastikan status akhir sebelum lanjut

Di bagian atas halaman Import Master akan muncul salah satu status:
- **PHASE 1 COMPLETE** — semua kandidat toko sudah direview (CONFIRM atau
  SKIP), produk & divisi lengkap.
- **PRODUCT MASTER COMPLETE — STORE MASTER INCOMPLETE** — produk & divisi
  sudah lengkap, tapi masih ada kandidat toko yang belum direview. Ini
  BUKAN error — Anda boleh berhenti di sini dan lanjutkan review toko kapan
  saja, halaman ini tetap bisa dibuka kembali kapan pun.

### 14. Bersihkan wizard sementara, cek kesehatan sistem, lalu backup

Setelah semua yang Anda perlukan selesai:
1. Buka `https://factory.amorgroup.id/api/health` — pastikan muncul
   `"ok": true` dan `"db": "connected"`.
2. Hapus folder `public_html/factory/api/_admin-login/` dan
   `public_html/factory/api/_import-master/` lewat File Manager (folder
   `api/_upgrade/` boleh dibiarkan — aman untuk pembaruan struktur di masa
   depan).
3. **Buat backup penuh** lewat cPanel → Backup Wizard (backup database
   `u7566812_factory` dan file `public_html/factory/`) sebagai langkah
   penutup Phase 1.

---

**Selesai.** Jika ada langkah yang gagal atau pesan error yang tidak Anda
mengerti, hentikan di situ dan simpan tangkapan layarnya — jangan
melanjutkan ke langkah berikutnya sebelum masalahnya jelas.
