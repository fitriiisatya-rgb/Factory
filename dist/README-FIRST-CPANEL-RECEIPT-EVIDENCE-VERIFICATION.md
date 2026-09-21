# Amor Factory System — Panduan Patch Bukti Foto Konfirmasi Toko + Detail Admin + Verifikasi Selisih (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. Paket ini menambah **satu
tabel baru** (migrasi database, lewat halaman web yang sudah ada — tidak
ada perintah SQL manual, tidak ada Terminal/SSH).

**Baca ini setelah Phase 5.5 (Dispatch/Klaim Driver/Konfirmasi Toko)
sudah berjalan.** Paket ini menutup temuan real-UAT: toko bisa
mengonfirmasi barang **reject/rusak atau kurang tanpa bukti foto sama
sekali**, dan Admin tidak punya halaman detail per-pengiriman untuk
memeriksa selisih sebelum memverifikasinya.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang diperbaiki

1. **Bukti foto wajib untuk barang reject/rusak atau kurang.** Toko yang
   mengisi Reject > 0 atau Kurang > 0 pada konfirmasi penerimaan sekarang
   **harus** melampirkan minimal 1 foto sebelum tombol "Simpan
   Konfirmasi" bisa dipakai. Ini diperiksa DUA kali — di layar toko (agar
   toko langsung tahu) DAN di server (agar tidak bisa dilewati dengan
   cara apa pun, termasuk memanggil API langsung). Jika dilewati tanpa
   foto, server menolak dengan pesan: *"Bukti foto wajib diunggah untuk
   barang reject/rusak atau kurang."*

   Toko bisa melampirkan hingga 3 foto (kamera atau galeri HP), dengan
   pratinjau + tombol hapus sebelum submit. Konfirmasi TANPA selisih
   (semua barang diterima baik) tetap TIDAK memerlukan foto sama sekali —
   tidak ada persetujuan tambahan yang dipaksakan untuk kasus normal.

2. **Halaman Detail Konfirmasi Toko untuk Admin (baru).** Dari layar
   ringkasan **Konfirmasi Toko**, setiap baris sekarang punya tautan
   **"Lihat Detail ›"** yang jelas (bukan hanya mengandalkan warna badge
   status). Halaman detail menampilkan: Nama Toko, No. DO, No. Shipment,
   Group Pengiriman, Driver, Waktu Berangkat, lalu status konfirmasi
   toko, Nama Penerima, Waktu Konfirmasi, Catatan, tabel per-produk
   (Qty Dikirim / Diterima Baik / Reject / Kurang), dan foto bukti
   (thumbnail, bisa diklik untuk memperbesar).

3. **Verifikasi Selisih (baru).** Untuk konfirmasi yang punya selisih
   (reject atau kurang), Admin membuka Detail lalu klik **"Verifikasi
   Selisih"**. Muncul konfirmasi: *"Pastikan data penerimaan, selisih,
   catatan, dan bukti foto sudah diperiksa."* Setelah Admin menekan **Ya,
   Verifikasi**, status berubah menjadi **"Diverifikasi Admin"**. Jumlah
   barang TIDAK pernah berubah oleh langkah verifikasi ini — verifikasi
   murni tanda "sudah diperiksa Admin", bukan koreksi data.

   **Verifikasi diblokir jika belum ada bukti foto**, dengan pesan:
   *"Selisih belum dapat diverifikasi karena bukti foto belum
   tersedia."* Ini juga berlaku untuk data LAMA (lihat bagian "Data lama
   sebelum patch ini" di bawah) — tidak ada pengecualian diam-diam.

4. **Perbaikan tampilan HP (mobile).** Sebelumnya kolom "Kurang" pada
   tabel konfirmasi toko bisa terpotong/hilang di layar HP sempit. Tabel
   sekarang otomatis berubah jadi bentuk kartu bertumpuk di layar sempit
   (kolom Kurang selalu terlihat penuh). Tampilan di tablet/desktop tetap
   memakai tabel seperti biasa.

---

## Data lama sebelum patch ini (mis. SHP-3 Bakery Abdul Gani)

Jika di server Anda sudah ada konfirmasi toko dengan Reject/Kurang yang
dibuat **sebelum** patch ini terpasang, konfirmasi tersebut TIDAK diubah
atau dihapus sama sekali. Di halaman Detail, bagian Bukti Foto akan
menampilkan **"Tidak tersedia"**, dan tombol Verifikasi akan nonaktif
(diblokir) sampai ada bukti foto.

**Keputusan yang diambil**: Admin sekarang punya cara aman untuk
menambahkan bukti foto ke konfirmasi lama tersebut LANGSUNG dari halaman
Detail (form upload khusus Admin muncul otomatis saat bukti foto belum
ada). Upload ini HANYA menambah foto — tidak pernah mengubah Qty
Dikirim/Diterima/Reject/Kurang yang sudah tercatat. Setelah Admin
mengunggah minimal 1 foto (foto asli dari toko yang diambil belakangan,
atau foto pendukung lain yang relevan), tombol Verifikasi akan aktif
seperti biasa.

Alternatif lain (dibuat ulang penerimaannya dari nol lewat toko) TIDAK
diperlukan dengan adanya jalur upload Admin ini, tapi tetap bisa dipakai
jika Anda lebih memilih data yang benar-benar baru dari toko.

---

## Bukti foto: bagaimana disimpan (untuk keamanan, ringkas)

- Foto disimpan sebagai FILE biasa di server (folder
  `api/uploads/receipt-evidence/`), bukan di database.
- Folder ini **tertutup total dari akses langsung lewat browser** (sama
  seperti folder `api/app/` yang sudah ada) — foto HANYA bisa dilihat
  Admin yang sudah login, lewat halaman Detail.
- Nama file yang tersimpan di server **diacak sepenuhnya** — nama asli
  dari HP toko tidak pernah dipakai sebagai nama file.
- Hanya JPEG/PNG/WEBP yang diterima, diperiksa dari ISI file (bukan
  hanya ekstensinya) — file yang menyamar (mis. `.php` diganti nama jadi
  `.jpg`) akan ditolak. Maksimal 5 MB per foto, maksimal 3 foto per
  konfirmasi.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN.
- File `amor-factory-receipt-evidence-verification-patch.zip` (dikirim
  bersama panduan ini).
- Kredensial migrasi database sementara (MIGRATION_DB_USER/PASS) — sama
  seperti saat meng-upgrade ke Phase 2–5.5 sebelumnya.
- **Penting — periksa dengan tim hosting/PHP Anda:** server harus
  mengaktifkan ekstensi PHP **mbstring** (ini BUKAN sesuatu yang baru
  dipakai patch ini — fitur lama seperti nama gudang di FG Batch sudah
  memakainya — tapi validasi kali ini baru pertama kali benar-benar
  menguji lewat Apache/PHP-FPM asli dan menemukan ekstensi ini wajib
  tersedia). Di cPanel, biasanya lewat **MultiPHP INI Editor** atau
  **Select PHP Version** → centang `mbstring`. Jika ekstensi ini TIDAK
  aktif, unggah bukti foto akan gagal dengan error server (500), bukan
  pesan yang ramah.

---

## Langkah-langkah

### 1. Backup (sangat disarankan)

cPanel → Backup Wizard → backup database `u7566812_factory` dan folder
`public_html/factory/`. Migrasi kali ini menambah 1 tabel baru
(`shipment_receipt_evidence`) — aman, tidak menyentuh tabel lama.

### 2. Upload & Extract (dengan Timpa/Overwrite)

Upload `amor-factory-receipt-evidence-verification-patch.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Jika ditanya
"timpa atau lewati", selalu pilih **Timpa/Overwrite**. `config.php` Anda
TIDAK ikut ditimpa (tidak ada di dalam paket ini).

### 3. Periksa ekstensi PHP mbstring aktif

Lihat bagian "Yang Anda butuhkan sebelum mulai" di atas. Lakukan ini
SEBELUM lanjut ke langkah upload foto di bawah — jika terlewat, uji coba
foto di langkah 10 akan gagal dan Anda harus kembali ke langkah ini.

### 4. Periksa `config.php` masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 5. Tambahkan kredensial migrasi sementara (jika belum ada)

```php
'MIGRATION_DB_USER' => 'user_migrasi_anda',
'MIGRATION_DB_PASS' => 'password_migrasi_anda',
```

### 6. Login sebagai ADMIN

Buka `https://factory.amorgroup.id/api/_admin-login/` dan login seperti
biasa.

### 7. Terapkan migrasi 0008 (murni tambahan, 1 tabel baru)

Buka `https://factory.amorgroup.id/api/_upgrade/`, Anda akan melihat
migrasi `0008_receipt_evidence.php` tertulis sebagai "pending". Klik
tombol terapkan. Migrasi ini HANYA menambah tabel baru
(`shipment_receipt_evidence`) — tidak ada tabel lama yang diubah atau
dihapus.

### 8. Hapus kembali kredensial migrasi (opsional tapi disarankan)

Setelah migrasi berhasil, hapus dua baris `MIGRATION_DB_*` yang Anda
tambahkan di langkah 5, demi keamanan.

### 9. Hard refresh

Buka portal Toko dan halaman Admin di HP/browser, lalu lakukan **hard
refresh** supaya file CSS/JS lama yang ter-cache tidak dipakai.

### 10. Uji dari sisi Toko: konfirmasi selisih TANPA foto (harus ditolak)

Scan QR salah satu DO yang sudah berangkat, isi Reject atau Kurang > 0
pada salah satu produk, JANGAN lampirkan foto, lalu coba klik **Simpan
Konfirmasi**. Pastikan tombol nonaktif/tertolak dan muncul pesan yang
mengarahkan untuk melampirkan foto.

### 11. Uji dari sisi Toko: lampirkan 1 foto, lalu submit (harus berhasil)

Lampirkan 1 foto (kamera atau galeri), pastikan muncul pratinjau, lalu
klik **Simpan Konfirmasi**. Pastikan berhasil dan status berubah menjadi
"Ada Selisih".

### 12. Uji dari sisi Admin: buka Detail dari ringkasan Konfirmasi Toko

Login sebagai Admin → menu **Konfirmasi Toko** → cari baris toko/DO yang
baru saja dikonfirmasi di langkah 11 → klik **Lihat Detail**. Pastikan
halaman menampilkan Nama Toko, No. DO, No. Shipment, Driver, Waktu
Berangkat, Nama Penerima, Waktu Konfirmasi, tabel per-produk dengan
kolom Reject/Kurang yang sesuai, dan thumbnail foto bukti yang bisa
diklik untuk diperbesar.

### 13. Uji dari sisi Admin: Verifikasi Selisih

Klik **Verifikasi Selisih**, pastikan muncul dialog konfirmasi dengan
judul "Verifikasi Konfirmasi Toko", klik **Ya, Verifikasi**, lalu
pastikan status berubah menjadi **"Diverifikasi Admin"** dan jumlah
barang di tabel TIDAK berubah.

### 14. Uji tampilan HP untuk tabel dengan kolom Kurang

Buka layar konfirmasi penerimaan toko di HP (bukan tablet/desktop),
pastikan kolom "Kurang" selalu terlihat penuh (tidak terpotong) — tabel
akan tampil sebagai kartu bertumpuk, bukan tabel lebar yang harus
digeser.

### 15. (Jika berlaku) Tambahkan bukti foto ke konfirmasi LAMA

Buka Detail untuk konfirmasi lama yang punya selisih tapi belum ada
foto (mis. SHP-3 sebelum patch ini). Pastikan muncul pesan "Bukti Foto:
Tidak tersedia" dan tombol Verifikasi nonaktif dengan pesan yang
menjelaskan alasannya. Gunakan form upload Admin yang muncul di halaman
yang sama untuk melampirkan foto, lalu pastikan tombol Verifikasi
menjadi aktif dan bisa dipakai seperti langkah 13.

### 16. Cara mundur (rollback) jika ada masalah

Kembalikan file-file yang tertimpa dari backup langkah 1. Untuk
database, tabel baru `shipment_receipt_evidence` aman dibiarkan kosong/
tidak dipakai jika Anda memutuskan mundur — tidak ada tabel lama yang
diubah sehingga tidak perlu proses mundur database khusus.

---

## Risiko & Catatan penting

- **Tidak ada perubahan pada perhitungan stok, logika klaim Driver,
  arsitektur token QR, atau aturan bisnis DO/FG/Production/PO** — patch
  ini murni menambah bukti foto + halaman detail + langkah verifikasi di
  atas alur Phase 5.5 yang sudah ada.
- **Ekstensi PHP mbstring wajib aktif** (lihat bagian "Yang Anda
  butuhkan sebelum mulai") — ini bukan kebutuhan baru dari patch ini,
  tapi validasi real-Apache kali ini yang pertama kali benar-benar
  mengujinya dan menemukan sebagian jalur kode (termasuk yang sudah lama
  ada, di luar patch ini) memang membutuhkannya.
- **Folder `api/uploads/receipt-evidence/` harus bisa ditulis oleh PHP**
  (bukan hanya bisa dibaca). Pada cPanel standar, akun Anda yang meng-
  upload file dan proses PHP yang menjalankannya adalah user yang SAMA,
  jadi ini biasanya otomatis benar. Jika setelah extract Anda melihat
  error saat upload foto, periksa permission folder tersebut lewat File
  Manager (klik kanan → Permissions) — pastikan minimal 755 dan dimiliki
  oleh akun cPanel Anda sendiri (bukan user lain).
- **Verifikasi tidak wajib untuk konfirmasi bersih (tanpa selisih).**
  Admin tetap bisa membuka Detail-nya secara read-only kapan saja, tapi
  sistem tidak memaksa persetujuan tambahan untuk kasus yang sudah
  sesuai.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
