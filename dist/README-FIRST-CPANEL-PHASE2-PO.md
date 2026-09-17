# Amor Factory — Panduan Phase 2 Fast-Track PO (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada langkah yang
mengharuskan Anda menjalankan perintah SQL manual atau membuka Terminal/SSH
— semua lewat klik di File Manager dan browser biasa.**

**Baca ini setelah Phase 1 (EASY V2) selesai** — skema database, data
master (divisi, produk, toko), dan login admin sudah berjalan. Panduan ini
untuk memasang modul **PO (Purchase Order / permintaan produksi)** di
MySQL — **bukan** Produksi Aktual, FG, Packing, DO, Pengiriman, Stok,
Invoice, atau Pembayaran (itu tahap-tahap berikutnya). Tampilan Amor
Factory yang sudah ada (Apps Script/Sheets) tetap berjalan seperti biasa,
tidak disentuh sama sekali oleh paket ini.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri) — sama seperti Phase 1.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN (sama seperti Phase 1).
- File `amor-factory-api-phase2-po-easy.zip` (dikirim bersama panduan ini).
- Password untuk database user `u7566812_adminfactory` (user migrasi —
  sama yang dipakai waktu Phase 1, BUKAN password aplikasi sehari-hari).
- Satu file PO asli (Excel `.xlsx` atau `.csv`) untuk dicoba, format yang
  sudah biasa dipakai (Karangtengah atau Cibadak/Bolu — dikenali otomatis).

---

## Langkah-langkah

### 1. Upload `amor-factory-api-phase2-po-easy.zip` ke `public_html/factory/`

Buka cPanel → File Manager → `public_html` → `factory`, lalu upload file
ZIP-nya di situ (pastikan Anda benar-benar di dalam folder `factory`, bukan
`public_html` saja).

### 2. Extract ZIP tersebut di tempat yang sama

Klik kanan file ZIP → **Extract**. Hasilnya bergabung ke folder
`public_html/factory/api/` yang sudah ada — file `config.php` Anda TIDAK
ikut ditimpa karena ZIP ini memang tidak berisi file itu sama sekali.

### 3. Periksa `config.php` Anda masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 4. Tambahkan 4 baris kredensial migrasi SEMENTARA ke `config.php`

Masih di file yang sama, tambahkan (atau isi jika sudah ada tapi kosong) —
persis seperti waktu Phase 1:

```php
'MIGRATION_DB_HOST' => 'localhost',
'MIGRATION_DB_NAME' => 'u7566812_factory',
'MIGRATION_DB_USER' => 'u7566812_adminfactory',
'MIGRATION_DB_PASS' => '<isi password user adminfactory di sini>',
```

Simpan file. Aman — kredensial ini terpisah total dari akun aplikasi
sehari-hari, dan akan dikosongkan lagi setelah langkah 7.

### 5. Login sebagai ADMIN

Buka `https://factory.amorgroup.id/api/_admin-login/` dan login dengan
akun ADMIN Anda.

### 6. Buka "Upgrade Database"

Dari halaman login, klik **Upgrade Database** (atau buka
`https://factory.amorgroup.id/api/_upgrade/`). Anda akan melihat 1 migrasi
baru menunggu: `0003_po_phase2`.

### 7. Terapkan migrasi 0003

Centang kotak konfirmasi, klik **Terapkan Migrasi**. Migrasi ini HANYA
menambah struktur baru (tidak menghapus tabel/data apa pun) — menambah
beberapa kolom pencatatan di tabel PO yang sudah ada, dan satu tabel baru
untuk riwayat upload.

### 8. Kosongkan lagi `MIGRATION_DB_PASS`

Kembali ke File Manager Editor, kosongkan `MIGRATION_DB_PASS` (jadi `''`)
di `config.php`. Simpan.

### 9. Buka wizard Import PO

Buka `https://factory.amorgroup.id/api/_import-po/` (atau login ulang lewat
`_admin-login/` dulu kalau sesi sudah habis). Isi **Tanggal Berlaku PO**,
pilih **PO Awal** atau **PO Tambahan/Revisi**, pilih file PO Anda
(`.xlsx` atau `.csv`), klik **Proses & Preview**.

### 10. Periksa hasil Preview sebelum lanjut

Halaman preview akan menampilkan:
- Jumlah baris produk terbaca, berapa yang PO Awal, berapa yang PO
  Tambahan/Revisi, dan berapa baris **PB** (Penerimaan Barang — ini SELALU
  diabaikan, tidak pernah memengaruhi target).
- Berapa produk & toko yang **sudah terpetakan**, dan berapa yang
  **belum** (kalau ada yang belum, ada formulir kecil untuk memetakan ke
  nama produk/toko yang sudah ada — tidak perlu tahu ID angka apa pun).
- **Target hasil** jika diimpor sekarang.

Jika ada produk/toko yang belum terpetakan, tombol **Konfirmasi & Impor
PO** tidak akan muncul sampai semuanya selesai dipetakan.

### 11. Verifikasi angka totalnya masuk akal

Bandingkan jumlah baris & target di layar preview dengan file PO asli
Anda. Kalau ada catatan "selisih TOTAL vs breakdown toko" atau "tidak ada
alokasi ke toko", itu bukan error — hanya informasi, boleh tetap lanjut,
tapi sebaiknya dicek ke sumbernya.

### 12. Klik "Konfirmasi & Impor PO"

Setelah yakin, klik tombol tersebut. Halaman akan menampilkan hasil:
jumlah baris ditulis dan target total akhir.

### 13. Verifikasi ringkasan PO di database

Bagian bawah halaman `_import-po/` menampilkan tabel ringkasan PO
tersimpan (tanggal, pabrik, tipe upload terakhir, versi). Untuk rincian
per toko/produk, gunakan alamat berikut di browser (hasil JSON, untuk
verifikasi teknis lebih lanjut jika diperlukan):
`https://factory.amorgroup.id/api/po/current?date=<tanggal>`

### 14. Cek kesehatan sistem

Buka `https://factory.amorgroup.id/api/health` — pastikan muncul
`"ok": true` dan `"db": "connected"`.

### 15. Backup

**Buat backup penuh** lewat cPanel → Backup Wizard (backup database
`u7566812_factory` dan file `public_html/factory/`) sebagai langkah
penutup setelah Phase 2 berhasil diuji.

---

## Catatan penting

- **api/_import-po/ bersifat sementara** — hapus foldernya lewat File
  Manager setelah Phase 2 selesai direview, sama seperti `_import-master/`
  di Phase 1. `api/_upgrade/` boleh tetap ada untuk pembaruan struktur di
  masa depan.
- **Tidak ada tombol reset/hapus semua data PO.** Kalau ada kesalahan
  upload, upload ulang file yang benar sebagai revisi — PO Awal yang sudah
  benar tidak akan ikut hilang (lihat aturan "PO Awal terkunci, PO Revisi
  selalu snapshot terbaru" di halaman preview).
- Modul ini **HANYA** menangani PO (permintaan). Belum ada Produksi
  Aktual, FG, Packing, DO, Pengiriman, Stok, Invoice, atau Pembayaran di
  MySQL — semua itu masih berjalan seperti biasa di sistem lama untuk saat
  ini.

**Selesai.** Jika ada langkah yang gagal atau pesan error yang tidak Anda
mengerti, hentikan di situ dan simpan tangkapan layarnya — jangan
melanjutkan ke langkah berikutnya sebelum masalahnya jelas.
