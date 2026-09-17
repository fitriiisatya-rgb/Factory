# Amor Factory — Panduan Phase 4 Fast-Track FG/Packing (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada langkah yang
mengharuskan Anda menjalankan perintah SQL manual atau membuka Terminal/SSH
— semua lewat klik di File Manager dan browser biasa.**

**Baca ini setelah Phase 3 (Production/SPK Actual) selesai dan sudah diuji
di UAT real.** Panduan ini untuk memasang modul **FG (Finished Goods
verification) / Packing** — **bukan** Draft DO, Pengiriman, Invoice, atau
Pembayaran (itu tahap-tahap berikutnya). Tampilan Amor Factory yang sudah
ada (Apps Script/Sheets) tetap berjalan seperti biasa, tidak disentuh sama
sekali oleh paket ini. Data PO dan Produksi yang sudah tersimpan di Phase
2/3 **TIDAK diubah** oleh paket ini — FG hanya MEMBACA Produksi yang sudah
SUBMITTED, tidak pernah menulis ke sana.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri) — sama seperti Phase 1/2/3.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN (sama seperti Phase 1/2/3).
- File `amor-factory-api-phase4-fg-packing-easy.zip` (dikirim bersama
  panduan ini).
- Password untuk database user `u7566812_adminfactory` (user migrasi —
  sama yang dipakai waktu Phase 1/2/3, BUKAN password aplikasi
  sehari-hari).
- Minimal satu Produksi yang sudah berstatus **SUBMITTED** untuk tanggal
  yang ingin diuji (lewat `api/_production-uat/`) — FG tidak bisa berjalan
  tanpa ini.

---

## Langkah-langkah

### 1. Backup database dulu, sebelum apa pun

cPanel → Backup Wizard → backup database `u7566812_factory` saja.

### 2. Upload `amor-factory-api-phase4-fg-packing-easy.zip` ke `public_html/factory/`

Buka cPanel → File Manager → `public_html` → `factory`, lalu upload file
ZIP-nya di situ (pastikan Anda benar-benar di dalam folder `factory`).

### 3. Extract ZIP tersebut di tempat yang sama

Klik kanan file ZIP → **Extract**. Hasilnya bergabung ke folder
`public_html/factory/api/` yang sudah ada — file `config.php` Anda TIDAK
ikut ditimpa. Folder `api/_import-po/` dan `api/_production-uat/` juga ada
di paket ini (tidak berubah, disertakan hanya supaya overwrite bersih).

### 4. Periksa `config.php` Anda masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 5. Tambahkan 4 baris kredensial migrasi SEMENTARA ke `config.php`

Masih di file yang sama, tambahkan (atau isi jika sudah ada tapi kosong) —
persis seperti waktu Phase 1/2/3:

```php
'MIGRATION_DB_HOST' => 'localhost',
'MIGRATION_DB_NAME' => 'u7566812_factory',
'MIGRATION_DB_USER' => 'u7566812_adminfactory',
'MIGRATION_DB_PASS' => '<isi password user adminfactory di sini>',
```

Simpan file. Aman — kredensial ini terpisah total dari akun aplikasi
sehari-hari, dan akan dikosongkan lagi setelah langkah 8.

### 6. Login sebagai ADMIN

Buka `https://factory.amorgroup.id/api/_admin-login/` dan login dengan
akun ADMIN Anda.

### 7. Buka "Upgrade Database"

Dari halaman login, klik **Upgrade Database** (atau buka
`https://factory.amorgroup.id/api/_upgrade/`). Anda akan melihat 1 migrasi
baru menunggu: `0005_fg_packing_phase4`.

### 8. Terapkan migrasi 0005, lalu kosongkan lagi `MIGRATION_DB_PASS`

Centang kotak konfirmasi, klik **Terapkan Migrasi**. Migrasi ini HANYA
menambah kolom baru di tabel `location`/`fg_batch`/`fg_item` yang sudah ada
sejak awal, plus satu nilai baru di daftar jenis pergerakan stok — tidak
ada tabel yang dihapus, tidak ada data PO/Produksi/FG lama yang tersentuh.
Setelah berhasil, kembali ke File Manager Editor, kosongkan
`MIGRATION_DB_PASS` (jadi `''`) di `config.php`, lalu simpan.

### 9. Buka wizard FG/Packing

Buka `https://factory.amorgroup.id/api/_fg-uat/` (login ulang lewat
`_admin-login/` dulu kalau sesi sudah habis).

### 10. Pilih tanggal & pabrik

Isi tanggal yang Produksinya sudah SUBMITTED (misalnya `2026-09-05`),
pilih pabrik (Karangtengah/Cibadak), klik **Muat Produksi Submitted**.
Filter divisi bersifat opsional, hanya untuk pratinjau.

### 11. Verifikasi daftar Produksi Submitted masuk akal

Halaman akan menampilkan daftar divisi yang sudah SUBMITTED beserta
jumlah produk & Production Actual per produk. Kalau kosong, berarti belum
ada Produksi SUBMITTED untuk tanggal/pabrik ini — buka
`api/_production-uat/` dulu dan submit produksinya.

### 12. Buat draft FG

Klik **Buat/Buka Draft FG**. Halaman akan menampilkan tabel per produk:
Production Actual, FG Verified, Variance, Packed, Available, FG Status,
Packing Status, Catatan.

### 13. Isi FG Verified & Packed, simpan draft

Isi angka **FG Verified** (harus ≤ Production Actual) dan **Packed**
(harus ≤ FG Verified) untuk produk yang ingin diuji, klik **Simpan
Draft**. Cek: belum ada perubahan stok pada tahap ini (Available masih
0 untuk produk ini kalau belum pernah disubmit sebelumnya).

### 14. Submit FG, verifikasi Available bertambah

Klik **Submit FG**. Cek angka **Available** — harus sama dengan angka
Packed yang baru saja disubmit.

### 15. Uji koreksi: Buka Kembali, ubah angka, submit ulang

Klik **Buka Kembali (Reopen)**, isi alasan (wajib). Ubah **FG Verified**
dan **Packed** (misalnya dari 3 menjadi 4), **Simpan Draft**, lalu
**Submit FG** lagi. Cek **Available** — harus menjadi 4 (BUKAN 3+4=7).
Ini bukti sistem memposting **selisih koreksi saja** ke stok, bukan angka
penuh berulang.

### 16. Uji peringatan ketidaksesuaian sumber (opsional)

Kalau setelah FG disubmit, Produksi sumbernya dibuka kembali (reopen) lewat
`api/_production-uat/`, halaman FG akan menampilkan kotak peringatan
**"Ketidaksesuaian Sumber Produksi"** — data FG yang sudah ada TIDAK
dihapus atau diubah otomatis, hanya diberi tahu supaya diperiksa ulang.

### 17. Cek kesehatan sistem

Buka `https://factory.amorgroup.id/api/health` — pastikan muncul
`"ok": true` dan `"db": "connected"`.

### 18. Backup lagi setelah semua diuji

**Buat backup penuh** lewat cPanel → Backup Wizard (backup database
`u7566812_factory` dan file `public_html/factory/`) sebagai langkah
penutup setelah Phase 4 berhasil diuji.

---

## Catatan penting

- **api/_fg-uat/ bersifat sementara** — hapus foldernya lewat File Manager
  setelah Phase 4 selesai direview, sama seperti `_production-uat/` di
  Phase 3.
- **Hanya Produksi berstatus SUBMITTED yang bisa jadi sumber FG.** Produksi
  yang masih draft atau sedang dibuka kembali (reopened) tidak akan
  muncul di daftar sampai disubmit/resubmit.
- **FG TIDAK PERNAH mengubah PO atau Produksi** — keduanya hanya dibaca.
- **Stok hanya berubah saat FG benar-benar disubmit** (bukan saat draft
  disimpan) — dan setiap submit/resubmit hanya memposting **selisihnya
  saja**, tidak pernah menjumlahkan ulang angka penuh.
- **Tidak ada tombol reset/hapus semua data FG.** Koreksi normal cukup
  lewat Buka Kembali (Reopen), bukan hapus data.
- Modul ini **HANYA** menangani verifikasi FG dan Packing. Belum ada Draft
  DO, Pengiriman, Invoice, Pembayaran, Retur, atau Reject Outbound di
  MySQL — semua itu masih berjalan seperti biasa di sistem lama untuk saat
  ini.

**Selesai.** Jika ada langkah yang gagal atau pesan error yang tidak Anda
mengerti, hentikan di situ dan simpan tangkapan layarnya — jangan
melanjutkan ke langkah berikutnya sebelum masalahnya jelas.
