# Amor Factory System — Panduan Phase 5.5: Dispatch Pool / Klaim Driver / Konfirmasi Penerimaan (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada perintah SQL
manual dan tidak ada Terminal/SSH untuk paket ini** — hanya satu langkah
"Upgrade Database" lewat halaman web yang sudah ada.

**Baca ini setelah Phase 5 (Draft DO / Pengiriman) sudah berjalan.**
Paket ini menambahkan alur baru: Driver bisa login sendiri, mengambil
("klaim") tugas pengiriman dari daftar yang tersedia, mengatur urutan
rute, lalu mengonfirmasi keberangkatan (baru di titik inilah stok
berkurang) — dan toko bisa memindai kode QR pada Surat Jalan untuk
mengonfirmasi barang yang diterima. Semua fitur Phase 1–5 yang sudah ada
tetap berjalan seperti biasa, tidak ada yang dihapus.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN.
- File `amor-factory-api-phase55-dispatch-receipt-easy.zip` (dikirim
  bersama panduan ini).
- Kredensial migrasi database sementara (MIGRATION_DB_USER/PASS) — sama
  seperti saat Anda meng-upgrade ke Phase 2–5 sebelumnya. Jika belum
  pernah diatur, minta admin database untuk membuatkan satu user dengan
  hak ALTER/CREATE TABLE pada database `u7566812_factory` (hanya
  dipakai sementara, boleh dicabut setelah migrasi selesai).

---

## Langkah-langkah

### 1. Backup (sangat disarankan)

cPanel → Backup Wizard → backup database `u7566812_factory` dan folder
`public_html/factory/`. Migrasi kali ini menambah 6 tabel baru — aman dan
tidak menyentuh tabel lama, tapi backup tetap kebiasaan yang baik.

### 2. Upload & Extract

Upload `amor-factory-api-phase55-dispatch-receipt-easy.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Hasilnya bergabung
ke folder `public_html/factory/api/` yang sudah ada — `config.php` Anda
TIDAK ikut ditimpa.

### 3. Periksa `config.php` masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 4. Tambahkan kredensial migrasi sementara (jika belum ada)

Masih di file yang sama, tambahkan baris berikut (isi sesuai user migrasi
Anda) jika belum ada, lalu simpan:

```php
'MIGRATION_DB_USER' => 'user_migrasi_anda',
'MIGRATION_DB_PASS' => 'password_migrasi_anda',
```

### 5. Login sebagai ADMIN

Buka `https://factory.amorgroup.id/api/_admin-login/` dan login seperti
biasa.

### 6. Terapkan migrasi 0007 (murni tambahan, 6 tabel baru + 1 peran baru)

Buka `https://factory.amorgroup.id/api/_upgrade/`, Anda akan melihat
migrasi `0007_dispatch_receipt_phase55.php` tertulis sebagai "pending".
Klik tombol terapkan. Migrasi ini HANYA menambah tabel baru
(`dispatch_claim`, `driver_route`, `driver_route_stop`,
`delivery_receipt_token`, `shipment_receipt`, `shipment_receipt_item`) dan
menambahkan peran baru "DRIVER" — tidak ada tabel lama yang diubah atau
dihapus.

### 7. Hapus kembali kredensial migrasi (opsional tapi disarankan)

Setelah migrasi berhasil, hapus dua baris `MIGRATION_DB_*` yang Anda
tambahkan di langkah 4, demi keamanan.

### 8. Buat akun Driver

Saat ini pembuatan user masih lewat halaman Master Data/Setup yang sudah
ada (atau minta developer membuatkan satu akun user biasa), lalu berikan
peran **DRIVER** padanya — sama seperti cara memberi peran ADMIN/PPIC
sebelumnya.

### 9. Uji login Driver & lihat "Pengiriman Tersedia"

Logout dari ADMIN, lalu buka
`https://factory.amorgroup.id/api/_driver-uat/login.php` — **ini halaman
login KHUSUS Driver, berbeda dari halaman login ADMIN di langkah 5.**
Halaman `_admin-login/` yang sudah ada sengaja HANYA menerima akun ADMIN
(sudah begitu sejak Phase 1), jadi akun Driver murni tidak akan bisa masuk
lewat sana — karena itu paket ini menyediakan pintu masuk terpisah khusus
Driver. Login dengan akun Driver yang Anda buat di langkah 8. Anda akan
otomatis masuk ke tab **Tersedia** berisi daftar tugas pengiriman dari DO
yang sudah ada (toko, produk, jumlah tersedia).

### 10. Uji ambil tugas ("Klaim")

Pilih satu atau lebih baris, atur jumlah jika perlu, lalu klik **Ambil
Pengiriman**. Tugas akan pindah ke tab **Pengiriman Saya**, dan toko
tujuannya otomatis masuk ke tab **Rute Saya**.

### 11. Uji urutan rute

Buka tab **Rute Saya**, gunakan tombol panah atas/bawah untuk mengubah
urutan toko — perubahan tersimpan otomatis.

### 12. Uji Konfirmasi Berangkat (di sinilah stok baru berkurang)

Dari **Rute Saya**, ketuk salah satu toko untuk membuka layar **Konfirmasi
Berangkat**. Periksa jumlah "Sisa DO" dan "FG Ready" per produk, sesuaikan
"Qty Kirim" jika perlu, lalu klik **KONFIRMASI BERANGKAT**. Konfirmasi
sekali lagi pada kotak dialog. Setelah ini, stok FG akan berkurang dan
sebuah Pengiriman/Shipment resmi (sama seperti Phase 5) akan tercatat.

### 13. Periksa QR pada Surat Jalan

Buka `Delivery Order` → pilih DO yang sama → **Print Preview**. Pastikan
muncul kode QR kecil dengan label **"Scan untuk Konfirmasi Penerimaan
Barang"** di atas kolom tanda tangan. QR ini SUDAH muncul bahkan sebelum
ada pengiriman sama sekali (Draft/Preprint) — ini yang membedakan dengan
sistem lama.

### 14. Uji pemindaian QR sebelum ada pengiriman

Pindai (atau salin tautannya secara manual dari kode QR) pada DO yang
BELUM dikonfirmasi berangkat oleh driver mana pun. Halaman akan
menampilkan **"Pengiriman belum dikonfirmasi berangkat."** — ini normal
dan diharapkan.

### 15. Uji konfirmasi penerimaan oleh toko

Setelah driver mengonfirmasi keberangkatan (langkah 12), pindai ulang QR
yang sama. Sekarang akan muncul detail pengiriman (daftar produk beserta
jumlah dikirim). Isi jumlah **Diterima Baik**, **Reject**, dan **Kurang**
untuk setiap produk (totalnya harus sama dengan jumlah dikirim), isi nama
penerima, lalu klik **Simpan Konfirmasi**.

### 16. Uji halaman Admin "Konfirmasi Toko"

Login kembali sebagai ADMIN, buka menu **Konfirmasi Toko** di sidebar.
Anda akan melihat baris pengiriman yang baru saja dikonfirmasi toko tadi.
Jika ada Reject/Kurang, klik **Verifikasi** untuk menandainya sudah
ditinjau Admin.

### 17. Cara mundur (rollback) jika ada masalah

Karena paket ini hanya menambah file baru (`api/_driver-uat/`,
`api/_receive/`, beberapa file di `api/app/src/Dispatch/` dan
`api/app/ui/`) dan satu migrasi TAMBAHAN (tidak mengubah tabel lama), cara
mundur paling aman adalah:
- Menghapus folder `api/_driver-uat/` dan `api/_receive/` lewat File
  Manager (menonaktifkan fitur baru tanpa memengaruhi Phase 1-5), ATAU
- Jika perlu mundur total, biarkan ke-6 tabel baru tetap ada (tidak
  mengganggu apa pun karena tidak dipakai fitur lain) dan cukup hapus
  kedua folder di atas.
- Sistem Phase 1-5 dan seluruh halaman lama tetap berjalan normal tanpa
  terpengaruh sama sekali.

---

## Catatan penting

- **Mengambil tugas (klaim), melihat rute, atau membuka/memindai QR TIDAK
  PERNAH mengubah stok** — stok FG hanya berkurang pada saat
  "KONFIRMASI BERANGKAT" yang sesungguhnya, persis seperti aksi "Kirim"
  manual di Phase 5 (menggunakan mesin pencatat stok yang sama persis,
  tidak ada logika pengurangan stok kedua yang terpisah).
- **Satu DO bisa dikirim oleh lebih dari satu driver** (misalnya produk
  Roti oleh Driver A, produk Pastry oleh Driver B) — setiap driver
  membuat Pengiriman/Shipment-nya sendiri, dan kode QR yang sama akan
  menampilkan SEMUA pengiriman tersebut untuk dikonfirmasi toko satu per
  satu.
- **Tautan QR bersifat rahasia dan acak** — tidak bisa ditebak, dan
  tautan satu DO tidak bisa dipakai untuk melihat/mengubah DO lain.
- **Fitur Invoice, Piutang, dan proses fisik Retur/Reject BELUM
  diimplementasikan** — halaman Konfirmasi Toko hanya mencatat jumlah
  diterima baik/reject/kurang untuk keperluan pelaporan; belum ada
  perhitungan uang atau proses pengembalian barang fisik.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
