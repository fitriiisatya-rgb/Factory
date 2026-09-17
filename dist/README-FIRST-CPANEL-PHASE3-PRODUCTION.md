# Amor Factory — Panduan Phase 3 Fast-Track Production/SPK Actual (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada langkah yang
mengharuskan Anda menjalankan perintah SQL manual atau membuka Terminal/SSH
— semua lewat klik di File Manager dan browser biasa.**

**Baca ini setelah Phase 2 (PO Fast-Track) selesai dan sudah diuji di UAT
real** — PO Karangtengah & Cibadak sudah tersimpan dengan benar di MySQL.
Panduan ini untuk memasang modul **Produksi Aktual / SPK** — **bukan** FG
(verifikasi barang jadi), Packing, DO, Pengiriman, Stok, Invoice, atau
Pembayaran (itu tahap-tahap berikutnya). Tampilan Amor Factory yang sudah
ada (Apps Script/Sheets) tetap berjalan seperti biasa, tidak disentuh sama
sekali oleh paket ini. Data PO yang sudah tersimpan di Phase 2 **TIDAK
diubah** oleh paket ini — Produksi Aktual hanya MEMBACA target dari PO,
tidak pernah menulis ke sana.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri) — sama seperti Phase 1 & 2.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN (sama seperti Phase 1 & 2).
- File `amor-factory-api-phase3-production-easy.zip` (dikirim bersama
  panduan ini).
- Password untuk database user `u7566812_adminfactory` (user migrasi —
  sama yang dipakai waktu Phase 1/2, BUKAN password aplikasi sehari-hari).
- PO untuk tanggal `2026-09-05` (Karangtengah & Cibadak) sudah tersimpan di
  Phase 2 — dipakai untuk verifikasi di langkah 11-12 di bawah.

---

## Langkah-langkah

### 1. Backup database dulu, sebelum apa pun

cPanel → Backup Wizard → backup database `u7566812_factory` saja (cukup
database, tidak perlu seluruh akun) sebelum lanjut ke langkah berikutnya.

### 2. Upload `amor-factory-api-phase3-production-easy.zip` ke `public_html/factory/`

Buka cPanel → File Manager → `public_html` → `factory`, lalu upload file
ZIP-nya di situ (pastikan Anda benar-benar di dalam folder `factory`, bukan
`public_html` saja).

### 3. Extract ZIP tersebut di tempat yang sama

Klik kanan file ZIP → **Extract**. Hasilnya bergabung ke folder
`public_html/factory/api/` yang sudah ada — file `config.php` Anda TIDAK
ikut ditimpa karena ZIP ini memang tidak berisi file itu sama sekali. Folder
`api/_import-po/` akan diperbarui (tampilannya sedikit berubah — lihat
catatan di bagian bawah), tapi datanya tidak disentuh.

### 4. Periksa `config.php` Anda masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 5. Tambahkan 4 baris kredensial migrasi SEMENTARA ke `config.php`

Masih di file yang sama, tambahkan (atau isi jika sudah ada tapi kosong) —
persis seperti waktu Phase 1/2:

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
baru menunggu: `0004_production_phase3`.

### 8. Terapkan migrasi 0004, lalu kosongkan lagi `MIGRATION_DB_PASS`

Centang kotak konfirmasi, klik **Terapkan Migrasi**. Migrasi ini HANYA
menambah kolom baru di tabel `production_run` yang sudah ada sejak awal
(siapa yang membuat draft/submit/reopen, dan penanda versi PO terakhir yang
dilihat) — tidak ada tabel yang dihapus, tidak ada data PO/produksi lama
yang tersentuh. Setelah berhasil, kembali ke File Manager Editor, kosongkan
`MIGRATION_DB_PASS` (jadi `''`) di `config.php`, lalu simpan.

### 9. Buka wizard Produksi/SPK Actual

Buka `https://factory.amorgroup.id/api/_production-uat/` (login ulang lewat
`_admin-login/` dulu kalau sesi sudah habis).

### 10. Pilih tanggal & divisi

Isi tanggal `2026-09-05`, pilih salah satu divisi Karangtengah (misalnya
**Roti & Bollen**) dari dropdown, klik **Muat Target dari PO**. Divisi
**Finishgood & Packing** sengaja tidak muncul di daftar — itu bagian dari
modul terpisah di masa depan, bukan Produksi Aktual.

### 11. Verifikasi target Karangtengah masuk akal (total gabungan 12.930)

Halaman akan menampilkan target per produk, dibaca langsung dari PO yang
sudah tersimpan. Untuk melihat total gabungan SEMUA produk Karangtengah
tanggal ini (bukan per-divisi), buka
`https://factory.amorgroup.id/api/po/current?date=2026-09-05&factoryId=1`
di tab lain dan jumlahkan — total PO Awal + PO Tambahan seharusnya sesuai
dengan angka yang sudah dikonfirmasi di Phase 2 UAT (12.824 + 106 = 12.930).
Modul Produksi TIDAK menghitung ulang angka ini — hanya membacanya apa
adanya dari PO.

### 12. Verifikasi target Cibadak (total 6.726)

Ulangi langkah 10-11 untuk tanggal yang sama, pilih divisi **Bolu**
(Cibadak). Total PO Cibadak tanggal ini seharusnya 6.726 (PO Awal 6.726,
tanpa revisi) — sesuai konfirmasi Phase 2 UAT.

### 13. Buat draft kecil untuk uji coba

Kembali ke divisi Karangtengah tanggal `2026-09-05`, klik **Buat/Buka Draft
Produksi**. Halaman akan menampilkan tabel per produk (Target/Actual/Sisa/
Status) — isi angka **Actual** untuk 1-2 produk saja sebagai uji coba
(jangan isi field kosong 0, wajar), klik **Simpan Draft**.

### 14. Edit draft, pastikan angka TIDAK bertambah

Ubah salah satu angka Actual yang tadi diisi (misalnya dari 10 jadi 15),
klik **Simpan Draft** lagi. Cek angka Actual di layar — harus menjadi 15
persis, BUKAN 25 (10+15). Ini bukti sistem "mengganti" angka, bukan
"menjumlahkan" — aturan yang sama seperti PO Revisi di Phase 2.

### 15. Submit, lalu verifikasi Sisa Produksi

Klik **Submit Produksi**. Cek angka "Sisa Produksi" di ringkasan atas
halaman — harus sama dengan Target dikurangi Actual (kalau Actual lebih
besar dari Target, akan muncul peringatan **Overproduction**, bukan angka
minus).

### 16. Uji Buka Kembali (Reopen) & Submit Ulang

Klik **Buka Kembali (Reopen)**, isi alasan (wajib), submit form. Status
berubah jadi REOPENED — ubah lagi salah satu angka Actual, **Simpan
Draft**, lalu **Submit Produksi** lagi. Status kembali SUBMITTED, dan waktu
submit pertama (SubmittedAt) tetap tercatat di riwayat — cek bagian
**10. Riwayat** di bawah halaman untuk melihat jejak lengkapnya.

### 17. Cek kesehatan sistem

Buka `https://factory.amorgroup.id/api/health` — pastikan muncul
`"ok": true` dan `"db": "connected"`.

### 18. Backup lagi setelah semua diuji

**Buat backup penuh** lewat cPanel → Backup Wizard (backup database
`u7566812_factory` dan file `public_html/factory/`) sebagai langkah
penutup setelah Phase 3 berhasil diuji.

---

## Catatan penting

- **api/_production-uat/ bersifat sementara** — hapus foldernya lewat File
  Manager setelah Phase 3 selesai direview, sama seperti `_import-po/` di
  Phase 2. `api/_upgrade/` boleh tetap ada untuk pembaruan struktur di masa
  depan.
- **Tidak ada tombol reset/hapus semua data produksi.** Data uji coba di
  atas akan dibersihkan lewat alat pembersihan terpisah dan terkontrol
  sebelum go-live nanti — koreksi normal cukup lewat Buka Kembali (Reopen),
  bukan hapus data.
- **Produksi Aktual TIDAK PERNAH mengubah target PO** — target selalu
  dibaca langsung dan hidup dari modul PO Phase 2. Kalau PO direvisi
  setelah draft produksi dibuat, halaman akan menampilkan peringatan
  "Target berubah sejak draft dibuat", tapi angka Actual yang sudah diisi
  operator TIDAK PERNAH hilang atau berubah otomatis.
- Wizard `api/_import-po/` (Phase 2) sekarang menampilkan **"Toko unik
  terpetakan"** terpisah dari **"Kemunculan/baris toko terpetakan"** —
  perbaikan tampilan saja (angka PO yang sebenarnya tersimpan tidak
  berubah sama sekali).
- Modul ini **HANYA** menangani Produksi Aktual/SPK. Belum ada verifikasi
  FG, Packing, DO, Pengiriman, Stok, Invoice, atau Pembayaran di MySQL —
  semua itu masih berjalan seperti biasa di sistem lama untuk saat ini.

**Selesai.** Jika ada langkah yang gagal atau pesan error yang tidak Anda
mengerti, hentikan di situ dan simpan tangkapan layarnya — jangan
melanjutkan ke langkah berikutnya sebelum masalahnya jelas.
