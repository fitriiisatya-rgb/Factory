# Amor Factory — Panduan Phase 5 Fast-Track Draft DO / Staged Shipment (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada langkah yang
mengharuskan Anda menjalankan perintah SQL manual atau membuka Terminal/SSH
— semua lewat klik di File Manager dan browser biasa.**

**Baca ini setelah Phase 4 (FG/Packing) selesai dan sudah diuji di UAT
real.** Panduan ini untuk memasang modul **Draft DO (Delivery Order) dan
Pengiriman Bertahap (Staged Shipment)** — **bukan** Invoice, Pembayaran,
Retur, atau Reject Outbound (itu tahap-tahap berikutnya). Tampilan Amor
Factory yang sudah ada (Apps Script/Sheets) tetap berjalan seperti biasa,
tidak disentuh sama sekali oleh paket ini. Data PO/Produksi/FG yang sudah
tersimpan di Phase 2/3/4 **TIDAK diubah** oleh paket ini — DO hanya MEMBACA
PO toko yang hidup, dan Pengiriman hanya MEMBACA stok FG yang sudah
tersedia.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri) — sama seperti Phase 1-4.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN (sama seperti Phase 1-4).
- File `amor-factory-api-phase5-do-shipment-easy.zip` (dikirim bersama
  panduan ini).
- Password untuk database user `u7566812_adminfactory` (user migrasi —
  sama yang dipakai waktu Phase 1-4, BUKAN password aplikasi sehari-hari).
- Minimal satu PO toko (lewat `api/_import-po/`) dan stok FG yang sudah
  tersedia (lewat `api/_fg-uat/`, sudah SUBMITTED) untuk tanggal yang ingin
  diuji — Draft DO tidak bisa dibuat tanpa PO toko, dan Pengiriman tidak
  bisa dikonfirmasi tanpa stok FG tersedia.

---

## Langkah-langkah

### 1. Backup database dulu, sebelum apa pun

cPanel → Backup Wizard → backup database `u7566812_factory` saja.

### 2. Upload `amor-factory-api-phase5-do-shipment-easy.zip` ke `public_html/factory/`

Buka cPanel → File Manager → `public_html` → `factory`, lalu upload file
ZIP-nya di situ (pastikan Anda benar-benar di dalam folder `factory`).

### 3. Extract ZIP tersebut di tempat yang sama

Klik kanan file ZIP → **Extract**. Hasilnya bergabung ke folder
`public_html/factory/api/` yang sudah ada — file `config.php` Anda TIDAK
ikut ditimpa. Folder `api/_import-po/`, `api/_production-uat/`, dan
`api/_fg-uat/` juga ada di paket ini (tidak berubah, disertakan hanya
supaya overwrite bersih).

### 4. Periksa `config.php` Anda masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 5. Tambahkan 4 baris kredensial migrasi SEMENTARA ke `config.php`

Masih di file yang sama, tambahkan (atau isi jika sudah ada tapi kosong) —
persis seperti waktu Phase 1-4:

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
baru menunggu: `0006_do_shipment_phase5`.

### 8. Terapkan migrasi 0006, lalu kosongkan lagi `MIGRATION_DB_PASS`

Centang kotak konfirmasi, klik **Terapkan Migrasi**. Migrasi ini HANYA
menambah kolom/kunci baru di tabel `delivery_order`/`shipment`/
`shipment_item` yang sudah ada sejak awal (termasuk satu kunci unik baru
di `delivery_order` yang berdampingan dengan kunci unik lama — TIDAK
menghapus atau menggantinya) — tidak ada tabel yang dihapus, tidak ada
data PO/Produksi/FG lama yang tersentuh. Setelah berhasil, kembali ke File
Manager Editor, kosongkan `MIGRATION_DB_PASS` (jadi `''`) di `config.php`,
lalu simpan.

### 9. Buka wizard Draft DO / Pengiriman

Buka `https://factory.amorgroup.id/api/_do-uat/` (login ulang lewat
`_admin-login/` dulu kalau sesi sudah habis).

### 10. Pilih tanggal & pabrik

Isi tanggal yang PO-nya sudah masuk (misalnya `2026-09-05`), pilih pabrik
(Karangtengah/Cibadak), klik **Muat Toko dengan PO**.

### 11. Verifikasi daftar Toko dengan PO masuk akal

Halaman akan menampilkan daftar toko yang punya PO untuk tanggal/pabrik
ini. Kalau kosong, berarti belum ada PO untuk tanggal/pabrik ini — buka
`api/_import-po/` dulu dan import PO-nya.

### 12. (Opsional) Generate Draft DO untuk semua toko sekaligus

Kalau ingin membuat Draft DO untuk SEMUA toko pada tanggal ini sekaligus,
klik **Generate Draft DO untuk Semua Toko**. Toko yang sudah punya DO
terbuka akan otomatis dilewati (aman, tidak membuat duplikat) — hasilnya
ditampilkan sebagai jumlah dibuat/sudah ada/gagal.

### 13. Atau, buat Draft DO untuk satu toko

Pada baris toko yang ingin diuji, klik **Generate/Buka Draft DO**. Anda
akan masuk ke halaman detail DO toko tersebut.

### 14. Verifikasi item DO sesuai PO toko

Tabel item DO menampilkan Produk, Divisi, Planned (jumlah dari PO
Awal+Revisi toko ini), Sudah Dikirim (masih 0), Sisa, FG Available, dan
Status. Satu toko HANYA punya SATU DO per tanggal — kalau toko ini juga
punya PO dari pabrik lain (Karangtengah DAN Cibadak) pada tanggal yang
sama, item dari kedua pabrik akan muncul di DO yang SAMA.

### 15. Lihat Print Preview

Klik **Print Preview** — halaman baru terbuka menampilkan dokumen Surat
Jalan dengan watermark **"DRAFT — BELUM DICETAK RESMI"** (karena status
masih draft). Ini BUKAN dokumen resmi sampai benar-benar dikirim.

### 16. Tandai Preprinted

Kembali ke halaman DO, klik **Tandai Preprinted**. Buka lagi Print
Preview — watermark berubah menjadi **"PREPRINT — BELUM DIKIRIM"**. Status
DO masih BUKAN "terkirim" — stok FG belum berkurang sama sekali di tahap
ini.

### 17. Isi Qty Kirim untuk pengiriman pertama (boleh sebagian)

Pada bagian **Buat Pengiriman**, pilih Grup Pengiriman (MAIN/PASTRY/
OTHER), isi **Qty Kirim** untuk satu atau beberapa produk — boleh KURANG
dari Sisa (pengiriman bertahap/parsial itu wajar dan didukung).

### 18. Klik Pratinjau Pengiriman dulu

Klik **Pratinjau Pengiriman** untuk melihat apakah qty yang diisi valid
(tidak melebihi Sisa DO, tidak melebihi FG Available) TANPA mengubah stok
apa pun. Kalau ada baris bermasalah, akan ditandai merah dengan alasannya.

### 19. Konfirmasi KIRIM

Kalau pratinjau sudah OK, klik **Konfirmasi KIRIM** (akan ada konfirmasi
sekali lagi). Ini **satu-satunya aksi yang benar-benar mengurangi stok
FG** di seluruh modul ini — stok berkurang tepat sejumlah yang dikirim,
di detik itu juga.

### 20. Verifikasi Sisa DO berkurang, status BELUM "shipped" kalau masih parsial

Setelah konfirmasi, tabel item menunjukkan Sudah Dikirim bertambah dan
Sisa berkurang. Kalau MASIH ada Sisa (pengiriman parsial), status DO tetap
draft/preprinted, BUKAN "Terkirim Penuh" — ini disengaja, DO baru berubah
status setelah SEMUA item habis terkirim.

### 21. Buat pengiriman kedua (tahap/grup berbeda) untuk menghabiskan Sisa

Ulangi langkah 17-19 dengan Grup Pengiriman berbeda (misalnya PASTRY kalau
tadi MAIN) untuk mengirim sisa qty yang belum terkirim. Setelah SEMUA item
habis terkirim, status DO otomatis menjadi **"Terkirim Penuh" (shipped)**.

### 22. Cek Riwayat Pengiriman

Di bagian bawah halaman DO, **Riwayat Pengiriman** menampilkan setiap
shipment yang pernah dibuat untuk DO ini (grup, waktu, produk & qty
masing-masing) — bukti pengiriman bertahap tersimpan lengkap, bukan
ditimpa.

### 23. (Opsional) Uji Segarkan dari PO

Kalau PO toko direvisi setelah DO dibuat (lewat `api/_import-po/`), buka
lagi halaman DO — kotak peringatan **"PO Sumber Berubah"** akan muncul.
Klik **Segarkan dari PO** untuk menyamakan Planned dengan PO terbaru;
qty yang SUDAH terkirim tidak akan pernah diturunkan di bawah angka yang
sudah dikirim.

### 24. (Opsional) Uji Batalkan DO

Pada DO draft/preprinted yang BELUM ada qty terkirim sama sekali, klik
**Batalkan DO** (isi alasan). Kalau DO sudah punya qty terkirim (walau
sebagian), tombol ini akan gagal dengan pesan yang jelas — DO yang sudah
mulai dikirim tidak bisa dibatalkan dari sini, hanya bisa dilanjutkan.

### 25. Cek Print Semua (Bulk)

Kembali ke halaman tanggal & pabrik, klik **Print Semua (Bulk)** — semua
DO pada tanggal/pabrik itu ditampilkan sebagai dokumen cetak terpisah
(page break per DO), masing-masing dengan watermark sesuai statusnya.

### 26. Coba Cari / Filter DO

Gunakan bagian **Cari / Filter DO** di halaman utama wizard untuk mencari
DO berdasarkan tanggal, toko, dan/atau status — berguna untuk memeriksa DO
dari hari-hari sebelumnya tanpa harus tahu ID-nya.

### 27. Cek kesehatan sistem

Buka `https://factory.amorgroup.id/api/health` — pastikan muncul
`"ok": true` dan `"db": "connected"`.

### 28. Backup lagi setelah semua diuji

**Buat backup penuh** lewat cPanel → Backup Wizard (backup database
`u7566812_factory` dan file `public_html/factory/`) sebagai langkah
penutup setelah Phase 5 berhasil diuji.

---

## Catatan penting

- **api/_do-uat/ bersifat sementara** — hapus foldernya lewat File Manager
  setelah Phase 5 selesai direview, sama seperti `_fg-uat/` di Phase 4.
- **Satu toko = SATU Delivery Order per tanggal**, tidak peduli berapa
  pabrik atau berapa kali dikirim — jangan membuat DO terpisah per grup
  pengiriman.
- **Stok FG HANYA berkurang saat Konfirmasi KIRIM ditekan** — membuat
  draft, melihat print preview, menandai preprinted, atau menekan
  Pratinjau Pengiriman TIDAK PERNAH menyentuh stok.
- **Pengiriman bertahap/parsial itu normal dan didukung** — DO baru
  berstatus "Terkirim Penuh" setelah SEMUA item habis terkirim, bukan
  setelah pengiriman pertama.
- **BELUM ADA tombol batalkan pengiriman yang sudah terkonfirmasi** (void/
  cancel shipment) — ini keputusan sengaja untuk menghindari risiko data
  yang tidak aman di tahap fast-track ini. Kalau ada kesalahan kirim,
  catat secara manual dan tangani lewat proses di luar sistem untuk saat
  ini; jangan mencoba menghapus data lewat database secara langsung.
- **Tidak ada tombol reset/hapus semua data DO/Pengiriman.** Koreksi PO
  cukup lewat Segarkan dari PO; pembatalan DO hanya berlaku sebelum ada
  qty yang terkirim.
- Modul ini **HANYA** menangani Draft DO, Print, dan Pengiriman Bertahap.
  Belum ada Invoice, Pembayaran, Piutang, Retur, Reject Outbound, atau
  konfirmasi terima barang dari toko di MySQL — semua itu masih berjalan
  seperti biasa di sistem lama untuk saat ini.

**Selesai.** Jika ada langkah yang gagal atau pesan error yang tidak Anda
mengerti, hentikan di situ dan simpan tangkapan layarnya — jangan
melanjutkan ke langkah berikutnya sebelum masalahnya jelas.
