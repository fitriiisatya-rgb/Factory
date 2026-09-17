# Amor Factory System — Panduan Invoice UI / Print Template Preview (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada perintah SQL
manual, tidak ada Terminal/SSH, dan tidak ada migrasi database untuk
paket ini.**

**Baca ini setelah Phase 5 (Draft DO / Pengiriman) sudah berjalan.**
Paket ini HANYA menambahkan satu halaman baru untuk melihat pratinjau
desain cetak **Invoice** — belum ada fitur Invoice sungguhan (belum bisa
membuat Invoice asli, belum ada pencatatan piutang/pembayaran). Semua
halaman dan fitur lama tetap berjalan seperti biasa.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN.
- File `amor-factory-invoice-ui-preview.zip` (dikirim bersama panduan
  ini).

---

## Langkah-langkah

### 1. Backup (opsional tapi disarankan)

cPanel → Backup Wizard → backup database `u7566812_factory` dan folder
`public_html/factory/`. Paket ini tidak mengubah struktur database sama
sekali.

### 2. Upload & Extract

Upload `amor-factory-invoice-ui-preview.zip` ke `public_html/factory/`,
lalu klik kanan → **Extract**. Hasilnya bergabung ke folder
`public_html/factory/api/` yang sudah ada — `config.php` Anda TIDAK ikut
ditimpa.

### 3. Periksa `config.php` masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 4. TIDAK ADA migrasi database untuk paket ini

Lewati langkah "Upgrade Database" — paket ini murni tampilan/template
cetak, tidak ada tabel/kolom baru yang dipakai (tabel `invoice` dkk.
memang sudah ada di database sejak awal, tapi paket ini tidak
membacanya maupun menulisnya).

### 5. Login sebagai ADMIN

Buka `https://factory.amorgroup.id/api/_admin-login/` dan login seperti
biasa.

### 6. Buka halaman pratinjau Invoice

Buka:
`https://factory.amorgroup.id/api/_ui-preview/invoice-preview.php`

Halaman ini akan langsung menampilkan **contoh** dokumen Invoice —
lengkap dengan logo Amor Group, informasi pelanggan contoh, tabel produk
contoh, dan ringkasan total. Ada kotak peringatan kuning di bagian atas
layar (tidak ikut tercetak) yang mengingatkan bahwa data di halaman ini
adalah **data contoh (mock)**, bukan transaksi sungguhan.

### 7. Periksa isi yang HARUS ADA

- Logo Amor Group di kiri atas, nama **"Amor Cakes & Bakery"**.
- Judul besar **"INVOICE"** di kanan atas beserta No. Invoice & Tanggal.
- Bagian **"Informasi Pelanggan"** (Toko, Alamat, NPWP).
- Bagian **"Informasi Invoice"** (No. Invoice, Tanggal, dan referensi DO/
  Pengiriman jika ada).
- Tabel produk (No, Nama Produk, Qty, Harga Satuan, Subtotal).
- Kotak ringkasan di kanan bawah: Subtotal, Diskon, **Total Tagihan**
  (dengan warna coklat yang menonjol).
- Bagian "Terima Kasih" dan tiga kolom tanda tangan (Dibuat/Diperiksa/
  Disetujui Oleh) tanpa nama yang sudah terisi.

### 8. Periksa isi yang TIDAK BOLEH ADA

- Kode Toko.
- Termin Pembayaran / Jatuh Tempo.
- Kode Produk / Divisi pada tabel.
- Detail rekening bank / nomor rekening.
- Catatan otomatis yang mengada-ada (bagian Catatan memang sengaja
  disembunyikan pada versi ini).

### 9. Uji tombol Cetak & tampilan kertas

Klik tombol **Cetak** di pojok kanan atas — pastikan pratinjau cetak
browser menampilkan halaman berwarna putih/krem bersih berukuran A4,
TANPA sidebar/menu aplikasi, tanpa kotak peringatan kuning, dan tombol
Cetak/Kembali tidak ikut tercetak. Coba juga "Save as PDF" dari dialog
cetak browser jika perlu menyimpan file.

### 10. Uji di iPad (jika tersedia)

Buka halaman yang sama di Safari iPad, posisi landscape dan portrait —
pastikan dokumen A4 tetap terbaca dan proporsional, tidak terpotong,
dan halaman tidak bisa digeser ke samping (tidak ada scroll horizontal).

### 11. Periksa logo baru juga muncul di Print DO

Buka salah satu Print Preview Delivery Order yang sudah ada
(`https://factory.amorgroup.id/api/_ui-preview/?page=delivery-order`,
lalu klik **Print**). Pastikan logo Amor Group (bukan lagi kotak huruf
"A") sekarang muncul juga di kop surat Surat Jalan.

### 12. Cara mundur (rollback) jika ada masalah

Karena paket ini hanya menambah/mengganti file di `api/_ui-preview/` dan
`api/app/ui/` (tidak menyentuh database), cara mundur paling aman adalah
menghapus file `api/_ui-preview/invoice-preview.php` dan folder
`api/app/ui/fixtures/` lewat File Manager — sistem Phase 1-5 dan seluruh
halaman cetak DO tetap berjalan normal tanpa terpengaruh.

---

## Catatan penting

- **Membuka halaman ini TIDAK PERNAH menyimpan apa pun** — tidak membuat
  Invoice, tidak membuat catatan piutang/pembayaran, tidak mengubah DO,
  Pengiriman, FG, atau PO manapun. Halaman ini murni pratinjau desain.
- **Data yang tampil adalah data contoh (mock)** — nama toko, alamat,
  NPWP, daftar produk, dan angka-angkanya semuanya contoh, bukan data
  toko atau transaksi sungguhan mana pun.
- **Fitur Invoice sungguhan (Phase 6) belum dibangun** — belum ada tombol
  untuk membuat Invoice dari DO/Pengiriman asli, belum ada pencatatan
  piutang atau pembayaran. Paket ini hanya untuk meninjau DESAIN dokumen
  cetaknya lebih dulu.
- **Tidak ada logika bisnis Phase 1-5 yang berubah** — satu-satunya
  perubahan pada halaman yang sudah ada adalah logo Amor Group kini
  tampil di kop surat Print DO, menggantikan kotak huruf "A" sebelumnya.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
