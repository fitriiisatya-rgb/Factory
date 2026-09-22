# Amor Factory System — Task per Divisi + Reject Produksi + Print — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding.

**Paket ini MEMBUTUHKAN migrasi database baru (migrasi 0011).** Migrasi
ini HANYA menambah 2 kolom baru di tabel yang sudah ada (`special_order_item`)
— **tidak ada tabel baru, tidak ada tabel yang diubah struktur besarnya,
tidak ada data yang dihapus.**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang baru

### 1. Tab baru "Task per Divisi" di menu Produksi

Buka **Produksi** — sekarang ada 3 tab: **Ceklis Produksi** (seperti
biasa) / **Order Masuk / Demand Tambahan** (seperti biasa) / **Task per
Divisi** *(BARU)*.

Task per Divisi menggabungkan SEMUA kebutuhan produksi satu divisi dalam
satu daftar:
- **PO Reguler** — dari PO Toko seperti biasa.
- **Pesanan Khusus** — dari Pesanan Khusus Toko yang sudah dikirim ke
  Produksi.
- **Pesanan Non-Toko** — dari Pesanan Non-Toko yang sudah dikirim ke
  Produksi.
- **Replacement Reject** — kolom sumber sudah disiapkan, tapi BELUM ada
  datanya karena modul Replacement Reject belum dibangun (bukan bug —
  memang belum ada fiturnya).

Setiap baris tetap menunjukkan asalnya dengan jelas (badge warna + nomor
pesanan) — **tidak pernah digabung jadi satu angka buram**.

### 2. Aturan Target / Aktual / Reject / Sisa (PENTING)

- **Target** = jumlah baik yang harus diproduksi.
- **Aktual** = jumlah baik yang SUDAH diproduksi (tidak termasuk reject).
- **Reject Produksi** = jumlah gagal/rusak SAAT PRODUKSI (dicatat
  terpisah, di tabel yang sama, kolom baru).
- **Sisa Target = Target − Aktual** (Reject TIDAK ikut mengurangi Sisa).

Contoh: Target 120, Aktual Baik 118, Reject 2 → **Sisa = 2** (pabrik masih
harus menghasilkan 2 pcs baik lagi untuk mencapai target 120).

**Reject Produksi (pabrik) BERBEDA dari Reject Toko (barang ditolak saat
diterima toko).** Reject Produksi adalah kegagalan SEBELUM barang jadi
(FG); Reject Toko adalah urusan Konfirmasi Toko/verifikasi Admin — kedua
hal ini **tidak digabung** di paket ini.

### 3. Kolom "Reject Produksi" baru di Ceklis Produksi

Buka **Produksi** → tab **Ceklis Produksi** → buka draft harian seperti
biasa. Di tabel item, sekarang ada kolom **Reject Produksi** di sebelah
kolom Actual — isi jumlah gagal/rusak, lalu **Simpan Draft** seperti
biasa. Kolom `reject` ini sebenarnya sudah ada di database sejak awal,
hanya belum pernah bisa diisi — sekarang sudah bisa.

### 4. Input Aktual & Reject untuk Pesanan Khusus/Non-Toko

Pesanan Khusus Toko dan Pesanan Non-Toko TIDAK melalui Ceklis Produksi —
jadi input Aktual & Reject untuk pesanan-pesanan ini dilakukan **langsung
di halaman Task per Divisi**: isi kolom Aktual dan Reject Produksi pada
baris yang bisa diedit, lalu klik **"Simpan Aktual & Reject"**.

Baris **PO Reguler tetap hanya bisa dilihat (read-only)** di Task per
Divisi — untuk mengubahnya, tetap gunakan Ceklis Produksi seperti biasa
(tidak ada dua tempat input untuk data yang sama).

### 5. Print Divisi Ini / Print Semua Divisi

Di halaman Task per Divisi, ada 2 tombol print:
- **Print Divisi Ini** — cetak worksheet A4 (landscape) untuk divisi yang
  sedang dibuka.
- **Print Semua Divisi** — cetak worksheet untuk SEMUA divisi di pabrik
  yang dipilih, **satu divisi per halaman**.

Hasil cetak: kertas A4 **landscape**, latar **putih**, tulisan **hitam**
(tidak pernah gelap/dark), ada kolom **Paraf** untuk tanda tangan manual
di area produksi.

**PENTING: hasil cetak ini HANYA cadangan fisik/worksheet.** Angka yang
ditulis tangan di kertas TIDAK otomatis masuk ke sistem — sistem (layar)
tetap menjadi sumber data resmi.

---

## Yang TIDAK berubah

- **PO Reguler Toko, Pesanan Khusus/Non-Toko, routing Factory otomatis,
  FG & Packing, Pengiriman, Konfirmasi Toko, Email otomatis, Invoice,
  Mutasi Antar Toko** — semua **tidak berubah sama sekali**.
- Tampilan Admin **tetap memakai tema gelap (dark navy)** yang sama
  persis — halaman baru dan kolom baru semuanya memakai gaya yang sudah
  ada.
- **Actual Produksi PO Reguler tetap satu-satunya sumber** — Task per
  Divisi hanya MENAMPILKAN angka yang sama, tidak pernah membuat salinan
  kedua yang bisa berbeda.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- File `amor-factory-production-task-per-division-print.zip` (dikirim
  bersama panduan ini).
- **Kredensial migrasi database** (`MIGRATION_DB_USER`/`MIGRATION_DB_PASS`)
  — sudah pernah dipakai sebelumnya, isi lagi sementara di `config.php`.

---

## Langkah-langkah

### 1. Backup (WAJIB — ada migrasi database)

cPanel → Backup Wizard → backup folder `public_html/factory/` DAN
database MySQL Anda. Migrasi ini hanya MENAMBAH 2 kolom baru (tidak
menghapus apa pun), tapi backup tetap wajib sebelum migrasi apa pun.

### 2. Upload & Extract (dengan Timpa/Overwrite)

Upload `amor-factory-production-task-per-division-print.zip` ke
`public_html/factory/`, klik kanan → **Extract**, pilih **Timpa/Overwrite**.
`config.php` Anda TIDAK ikut ditimpa.

### 3. Terapkan migrasi 0011

Buka `public_html/factory/api/_upgrade/` di browser, login sebagai ADMIN.
Isi sementara kredensial migrasi di `config.php` bila diminta. Halaman
akan menunjukkan **migrasi 0011 menunggu diterapkan** — centang kotak
konfirmasi, klik **Terapkan Migrasi**. Setelah berhasil, hapus lagi baris
`MIGRATION_DB_PASS` dari `config.php`.

### 4. Hard refresh di browser Admin

Login sebagai Admin, lalu hard refresh (tutup tab lalu buka lagi).

### 5. Uji tab "Task per Divisi"

Buka **Produksi** → tab **Task per Divisi**. Pilih Tanggal/Pabrik/Divisi
yang punya PO Reguler dan/atau Pesanan Khusus/Non-Toko aktif, klik
**Terapkan**. Pastikan:
- Ringkasan (Total Target/Aktual/Reject/Sisa/Progress) muncul di atas.
- Baris PO Reguler dan Pesanan Khusus/Non-Toko tampil dengan badge warna
  berbeda.
- Catatan Khusus terlihat pada baris yang punya catatan.

### 6. Uji input Reject Produksi di Ceklis Produksi

Buka **Ceklis Produksi**, buka draft harian, isi kolom **Reject
Produksi** di samping Actual, klik **Simpan Draft**. Buka lagi tab Task
per Divisi — pastikan angka Reject yang sama muncul di sana (baca saja,
tidak diedit ulang).

### 7. Uji input Aktual/Reject Pesanan Khusus di Task per Divisi

Pada baris Pesanan Khusus/Non-Toko, isi Aktual dan Reject Produksi, klik
**Simpan Aktual & Reject**. Pastikan halaman refresh dan angka tersimpan.

### 8. Uji Print Divisi Ini / Print Semua Divisi

Klik **Print Divisi Ini** — pastikan tampil kertas A4 landscape putih
dengan kolom Paraf. Klik **Print Semua Divisi** — pastikan setiap divisi
tampil di halaman terpisah.

### 9. Pastikan fitur lain tidak berubah

Buka PO Toko, Pesanan Khusus/Non-Toko, Order Masuk/Demand Tambahan —
pastikan semuanya sama seperti sebelum paket ini dipasang.

### 10. Cara mundur (rollback) jika ada masalah

Kembalikan file-file dari backup langkah 1. Migrasi 0011 hanya menambah 2
kolom baru — jika perlu benar-benar mengembalikan struktur database,
gunakan backup database dari langkah 1.

---

## Risiko & Catatan penting

- **PO Reguler Toko, Pesanan Khusus/Non-Toko, FG, Pengiriman, Konfirmasi
  Toko, Email, Invoice, Mutasi Toko — semua tidak tersentuh.**
- **Actual Produksi PO Reguler tetap satu sumber data** — Task per Divisi
  hanya menampilkan, tidak menduplikasi.
- **Hasil print adalah cadangan fisik, bukan sumber data** — sistem tetap
  yang utama.
- **Tema gelap (dark navy) Admin dipertahankan persis sama.**

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
