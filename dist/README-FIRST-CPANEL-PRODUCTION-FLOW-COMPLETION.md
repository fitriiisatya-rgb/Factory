# Amor Factory System — Production Flow Completion — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding.

**Paket ini MEMBUTUHKAN migrasi database baru (migrasi 0012).** Migrasi
ini HANYA menambah kolom baru di tabel yang sudah ada, DUA tabel baru
khusus untuk DO Pesanan Khusus/Non-Toko, dan satu nilai baru di daftar
pilihan `shipment.source_type` — **tidak ada tabel yang dihapus, tidak
ada data yang dihapus, tidak ada perubahan pada DO PO Reguler.**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang baru

### 1. Perbaikan bug Ceklis Produksi (Target & Catatan)

Sebelumnya, di layar Ceklis Produksi, angka **Target** dan isi **Catatan**
pada beberapa baris bisa tampil kosong/salah, dan **Catatan yang diketik
tidak benar-benar tersimpan** walau tombol Simpan Draft ditekan. Ini
adalah bug di tampilan (bukan di database) — sudah diperbaiki. Sekarang
Target selalu menampilkan angka PO terbaru, dan Catatan benar-benar
tersimpan saat disimpan.

### 2. Pencarian produk yang lebih baik di Pesanan Khusus Toko / Pesanan Non-Toko

Kolom "Item" untuk Produk Existing sekarang punya **kotak pencarian
sungguhan** — ketik sebagian nama produk, daftar hasil muncul di
bawahnya, klik untuk memilih. Ini menggantikan cara lama yang kadang
tidak bekerja baik di iPad/HP. Produk yang tersimpan SELALU produk asli
dari database (tidak bisa mengetik nama sembarangan) — dan jika Anda
mengetik ulang setelah memilih, pilihan sebelumnya otomatis dibatalkan
sampai Anda memilih lagi dari daftar.

### 3. Tampilan uang format "Rp46.000"

Semua angka uang (Harga, Charge, Extra Packaging, Subtotal) sekarang
ditampilkan dengan format **Rp** dan titik pemisah ribuan, contoh
**Rp46.000** — lebih mudah dibaca. Angka yang tersimpan di database tetap
angka biasa (tidak berubah).

### 4. Kolom baru "Extra Packaging" di Pesanan Khusus Toko / Pesanan Non-Toko

Setiap item pesanan sekarang punya kolom **Extra Packaging (Rp)** — biaya
kemasan tambahan yang diisi manual per item. Extra Packaging ditambahkan
**SATU KALI** ke Subtotal item (tidak dikalikan qty), rumusnya:

```
Subtotal Item = (Qty × Harga) + Charge + Extra Packaging
```

Angka ini ikut tersimpan di draft, tetap ada setelah dibuka ulang, dan
ikut terkirim ke Produksi.

### 5. Tab baru "FG Sumber Khusus / Non-Toko"

Buka **FG & Packing** — sekarang ada 2 tab: **FG & Packing (Reguler)**
(seperti biasa, tidak berubah) dan **FG Sumber Khusus / Non-Toko**
*(BARU)*.

Tab baru ini menampilkan item Pesanan Khusus Toko/Pesanan Non-Toko yang
sudah punya Aktual Produksi (diisi di Task per Divisi), dengan kolom:
Aktual Produksi, Reject, Sudah Terverifikasi, Tersedia. Isi angka **FG
Terverifikasi** lalu klik **Simpan** untuk mengonfirmasi jumlah yang siap
dikirim.

**Penting:** FG untuk Pesanan Khusus/Non-Toko ini **terpisah** dari stok
gudang umum (FG PO Reguler) — tidak pernah bercampur, dan tidak pernah
diam-diam menjadi stok umum yang bisa dipakai toko lain.

### 6. Tab baru "DO Pesanan Khusus / Non-Toko"

Buka **Delivery Order** — sekarang ada 2 tab: **DO Toko (Reguler)**
(seperti biasa, tidak berubah) dan **DO Pesanan Khusus / Non-Toko**
*(BARU)*.

Setelah item pesanan diverifikasi FG, pesanan tersebut muncul di daftar
"Pesanan Siap Dibuat DO" — klik **Buat DO** untuk membuat dokumen DO
khusus (nomor dokumen berformat `DOK-tanggal-nomor urut`, BERBEDA dari
format DO Reguler `DO/KRM/...` yang tidak berubah). Di halaman detail DO,
tersedia tombol **Kirim DO** dan **Batalkan**.

**Aturan penting yang diterapkan:** Pesanan Khusus Toko, Pesanan
Non-Toko, dan PO Reguler **TIDAK PERNAH digabung dalam satu DO** —
walaupun tujuan/toko/tanggalnya sama. Setiap sumber pesanan punya DO-nya
masing-masing, agar mudah ditelusuri asalnya.

---

## Yang TIDAK berubah

- **PO Reguler Toko, PO Revisi, routing Factory otomatis, Target/Actual
  Produksi Reguler, FG & Packing Reguler, DO Toko Reguler (nomor & aturan
  sama persis), Pengiriman, bukti foto Konfirmasi Toko, Email otomatis,
  Mutasi Antar Toko, perhitungan Invoice** — semua **tidak berubah sama
  sekali**.
- Belum ada perhitungan Invoice baru di paket ini — fitur ini hanya
  menyiapkan data (asal pesanan, DO-nya) agar Invoice per-sumber bisa
  dibangun dengan aman di tahap berikutnya.
- Tampilan Admin **tetap memakai tema gelap (dark navy)** yang sama
  persis — halaman baru dan kolom baru semuanya memakai gaya yang sudah
  ada.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- File `amor-factory-production-flow-completion.zip` (dikirim bersama
  panduan ini).
- **Kredensial migrasi database** (`MIGRATION_DB_USER`/`MIGRATION_DB_PASS`)
  — sudah pernah dipakai sebelumnya, isi lagi sementara di `config.php`.

---

## Langkah-langkah

### 1. Backup (WAJIB — ada migrasi database)

cPanel → Backup Wizard → backup folder `public_html/factory/` DAN
database MySQL Anda. Migrasi ini hanya MENAMBAH kolom/tabel baru (tidak
menghapus apa pun), tapi backup tetap wajib sebelum migrasi apa pun.

### 2. Upload & Extract (dengan Timpa/Overwrite)

Upload `amor-factory-production-flow-completion.zip` ke
`public_html/factory/`, klik kanan → **Extract**, pilih **Timpa/Overwrite**.
`config.php` Anda TIDAK ikut ditimpa.

### 3. Terapkan migrasi 0012

Buka `public_html/factory/api/_upgrade/` di browser, login sebagai ADMIN.
Isi sementara kredensial migrasi di `config.php` bila diminta. Halaman
akan menunjukkan **migrasi 0012 menunggu diterapkan** — centang kotak
konfirmasi, klik **Terapkan Migrasi**. Setelah berhasil, hapus lagi baris
`MIGRATION_DB_PASS` dari `config.php`.

### 4. Hard refresh di browser Admin

Login sebagai Admin, lalu hard refresh (tutup tab lalu buka lagi).

### 5. Uji perbaikan Ceklis Produksi

Buka **Produksi** → **Ceklis Produksi**, buka draft harian, pastikan
kolom **Target** menampilkan angka yang benar. Ketik sesuatu di kolom
**Catatan**, klik **Simpan Draft**, lalu buka ulang halamannya — pastikan
Catatan yang diketik tadi masih ada (ini bug yang sudah diperbaiki).

### 6. Uji pencarian produk baru

Buka **Pesanan Khusus Toko** (atau **Pesanan Non-Toko**) → **Buat Pesanan
Baru** → **Tambah Item Existing**. Ketik sebagian nama produk di kolom
Item, pastikan daftar hasil muncul dan bisa dipilih dengan tap/klik. Coba
di HP/iPad juga jika memungkinkan.

### 7. Uji Extra Packaging & format uang

Pada item yang sama, isi Harga, Charge, dan **Extra Packaging**. Pastikan
Subtotal Item = (Qty×Harga)+Charge+Extra Packaging (Extra Packaging TIDAK
dikalikan qty). Simpan pesanan, buka halaman Detail-nya, pastikan angka
uang tampil format **Rp46.000**.

### 8. Uji alur FG & DO sumber khusus

1. Kirim pesanan ke Produksi (Konfirmasi → Kirim ke Produksi).
2. Buka **Produksi** → **Task per Divisi**, isi Aktual & Reject Produksi
   untuk item pesanan tadi, klik Simpan.
3. Buka **FG & Packing** → tab **FG Sumber Khusus / Non-Toko**, cari item
   tadi, isi **FG Terverifikasi**, klik Simpan.
4. Buka **Delivery Order** → tab **DO Pesanan Khusus / Non-Toko**, cari
   pesanan tadi di "Pesanan Siap Dibuat DO", klik **Buat DO**. Pastikan
   nomor dokumen berformat `DOK-...` (bukan `DO/KRM/...`).
5. Buat pesanan Pesanan Khusus Toko DAN Pesanan Non-Toko untuk toko/
   tanggal yang sama, ulangi langkah 1-4 untuk keduanya — pastikan
   **masing-masing dapat nomor DO sendiri** (tidak digabung).
6. Di halaman detail DO, klik **Kirim DO** — pastikan status berubah jadi
   "Shipped" dan tombol Batalkan hilang (DO yang sudah dikirim tidak bisa
   dibatalkan).

### 9. Pastikan fitur lain tidak berubah

Buka PO Toko, DO Toko Reguler (nomor formatnya harus tetap `DO/KRM/...`),
FG & Packing (Reguler), Pengiriman, Konfirmasi Toko — pastikan semuanya
sama seperti sebelum paket ini dipasang.

### 10. Cara mundur (rollback) jika ada masalah

Kembalikan file-file dari backup langkah 1. Migrasi 0012 hanya menambah
kolom/tabel baru — jika perlu benar-benar mengembalikan struktur
database, gunakan backup database dari langkah 1.

---

## Risiko & Catatan penting

- **PO Reguler, DO Reguler, FG Reguler, Pengiriman, Konfirmasi Toko,
  Email, Mutasi Toko, perhitungan Invoice — semua tidak tersentuh.**
- **DO Pesanan Khusus Toko dan Pesanan Non-Toko SELALU terpisah dari DO
  PO Reguler dan dari satu sama lain** — tidak pernah digabung dalam satu
  dokumen, walau tujuan/tanggalnya sama.
- **FG Pesanan Khusus/Non-Toko tidak bercampur dengan stok gudang umum.**
- **Belum ada perhitungan Invoice final di paket ini** — hanya data asal
  (sumber pesanan + DO) yang disiapkan agar aman dipakai tahap
  berikutnya.
- **Tema gelap (dark navy) Admin dipertahankan persis sama.**

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
