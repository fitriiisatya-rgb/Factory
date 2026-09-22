# Amor Factory System — Perbaikan Tampilan Pesanan Khusus/Non-Toko + Routing Factory Otomatis — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding.

**Paket ini TIDAK membutuhkan migrasi database baru.** Ini adalah paket
FILE SAJA — hanya memperbaiki tampilan (UI/UX) dan menambah logika routing
factory otomatis, di atas fitur Pesanan Khusus Toko / Pesanan Non-Toko
yang sudah dipasang sebelumnya (migrasi 0010). **Syarat: paket
sebelumnya (`amor-factory-special-nonregular-order-production-routing.zip`,
dengan migrasi 0010) harus sudah dipasang lebih dulu.**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang diperbaiki

Paket sebelumnya sudah berfungsi, tapi tampilan form Pesanan Khusus
Toko/Non-Toko dilaporkan **tidak layak pakai** di UAT nyata:

1. **Kontrol putih native** — kotak tanggal, dropdown, dan beberapa input
   tampil putih terang, tidak mengikuti tema gelap aplikasi.
2. **Kontrol kecil terpotong** di sisi kiri baris item (dropdown jenis
   item yang terlalu sempit).
3. **Tabel item terlalu sempit/padat** — sulit dibaca.
4. **Kolom Catatan Khusus terlalu sempit** — tidak nyaman untuk menulis
   catatan panjang.
5. **Field Harga/Charge tidak konsisten** secara visual.
6. **Jenis item (Existing/Custom) tidak jelas** — hanya dropdown kecil.
7. **Factory harus dipilih manual** — padahal seharusnya otomatis
   mengikuti divisi produksi.

Semua ini sudah diperbaiki di paket ini, TANPA mengubah tema gelap navy
yang sudah ada.

---

## 1. Perbaikan tampilan (UI/UX)

- **Setiap item pesanan sekarang berupa KARTU** yang jelas dan mudah
  dibaca — bukan lagi tabel sempit dengan kotak-kotak kecil. Setiap
  field punya label sendiri: Item, Tipe Item, Divisi Produksi, Factory,
  Qty, Harga, Charge, Catatan Khusus.
- **Semua kontrol form (tanggal, dropdown, input teks) sekarang benar-benar
  gelap** — penyebab aslinya ditemukan dan diperbaiki: sebelumnya hanya
  kontrol yang dibungkus pola tertentu yang mendapat warna gelap; kontrol
  lain (termasuk semua kotak Catatan) jatuh ke tampilan putih bawaan
  browser. Sekarang SEMUA kontrol form otomatis gelap, di halaman manapun.
- **Jenis item ditampilkan sebagai label warna jelas**: hijau "Produk
  Existing" atau oranye "Item Khusus / Custom" — tidak lagi dropdown
  kecil yang membingungkan.
- **Catatan Khusus sekarang kotak teks lebar** (textarea), nyaman untuk
  menulis catatan panjang seperti "Tema Spiderman, tulisan HBD Raka".
- Ditambahkan **garis waktu status** (Draft → Dikonfirmasi → Dikirim ke
  Produksi → Sedang Diproduksi → Siap → Selesai) di halaman detail
  pesanan, dan panel **"Informasi Produksi"** yang menunjukkan pesanan
  akan dikirim ke divisi/factory mana saja.
- Tombol aksi utama sekarang jelas di bagian bawah form: **"Simpan
  sebagai Draft"** (sekunder) dan **"Kirim ke Produksi"** (utama, langsung
  membuat + konfirmasi + kirim dalam satu klik).

---

## 2. Routing Factory Otomatis (aturan bisnis baru yang disetujui)

**Factory tujuan produksi TIDAK LAGI dipilih manual.** Sistem menentukan
otomatis berdasarkan Divisi Produksi setiap item:

- **Bolu** → Factory **Cibadak**
- **Semua divisi lain** (Cake & Custom, Pastry, Roti & Bollen,
  Donat/Mochi/AKB, Basic, Cookies) → Factory **Karangtengah**

Aturan ini sudah ada sebagai data master resmi di sistem sejak awal
(kolom `division.factory_id`) — paket ini hanya menghubungkan form
Pesanan Khusus/Non-Toko ke aturan itu secara otomatis, tidak menciptakan
aturan baru dari nol.

**Routing per item, bukan per pesanan.** Satu pesanan boleh berisi item
dari beberapa divisi sekaligus (misalnya Bolu Pandan dan Bollen Coklat
dalam satu pesanan yang sama) — pesanan itu akan otomatis dikirim ke
**dua factory berbeda**, dan kartu pesanan akan menunjukkan tanda "Multi
Divisi" DAN "Multi Factory". Setiap item tetap menunjukkan factory
tujuannya sendiri secara jelas sebelum pesanan dikirim.

Jika suatu divisi produksi tidak punya factory tujuan yang valid di data
master, sistem akan **memblokir pengiriman ke Produksi** dengan pesan
error yang jelas — tidak pernah menebak-nebak secara diam-diam.

---

## Yang TIDAK berubah

- **PO Reguler Toko** — alur, tampilan, dan datanya **sama sekali tidak
  disentuh**.
- Perhitungan stok, verifikasi Produksi/FG, pengiriman, konfirmasi
  penerimaan, email otomatis, harga Toko (diskon 50%/60%), dan Mutasi
  Antar Toko — **semua tidak berubah**.
- **Tidak ada tabel database baru** — paket ini murni perbaikan file.
- Tampilan Admin **tetap memakai tema gelap (dark navy)** yang sama
  persis — warna baru yang dipakai semuanya mengambil dari variabel warna
  yang sudah ada, tidak ada tema baru atau font baru.

Ada satu perbaikan kecil namun penting di balik layar: sebelumnya, cek
ketersediaan stok FG untuk pesanan multi-factory memakai factory pada
level pesanan (yang bisa salah/kosong untuk pesanan multi-factory) —
sekarang cek stok memakai factory milik masing-masing item, sesuai
divisinya sendiri.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- File `amor-factory-special-order-ui-routing-rework.zip` (dikirim
  bersama panduan ini).
- **Paket migrasi 0010 (Pesanan Khusus Toko/Non-Toko) sudah terpasang.**
  Jika belum, pasang paket itu terlebih dahulu sebelum melanjutkan.

---

## Langkah-langkah

### 1. Backup (tetap disarankan, walau tidak ada migrasi database)

cPanel → Backup Wizard → backup folder `public_html/factory/`. Paket ini
TIDAK menyentuh database sama sekali, tapi backup file tetap kebiasaan
baik sebelum overwrite apapun.

### 2. Upload & Extract (dengan Timpa/Overwrite)

Upload `amor-factory-special-order-ui-routing-rework.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Selalu pilih
**Timpa/Overwrite**. `config.php` Anda TIDAK ikut ditimpa (tidak ada di
dalam paket ini).

### 3. Hard refresh di browser Admin

Login sebagai Admin, lalu **hard refresh** (tutup tab lalu buka lagi,
atau Ctrl+Shift+R) supaya file CSS/JS lama yang ter-cache tidak dipakai.
Versi asset sudah dinaikkan otomatis di paket ini untuk memastikan ini.

### 4. Uji tampilan baru di Pesanan Khusus Toko

Buka **Pesanan Toko** → tab **Pesanan Khusus Toko**. Perhatikan:
- Semua kotak tanggal/dropdown sudah berwarna gelap (bukan putih).
- Setiap item pesanan tampil sebagai kartu dengan label jelas.
- Tidak ada lagi pilihan "Factory" manual di form.
- Tambahkan 1 item **Produk Existing** (misal produk dari divisi Roti &
  Bollen) — perhatikan badge Divisi & Factory otomatis muncul
  (Karangtengah).
- Tambahkan 1 item lagi dari divisi **Bolu** — perhatikan badge Factory
  otomatis menunjukkan **Cibadak** (berbeda dari item pertama).
- Perhatikan panel "Informasi Produksi" di bawah menunjukkan kedua
  factory tersebut.

### 5. Uji "Kirim ke Produksi" satu klik

Klik tombol **"Kirim ke Produksi"** (bukan "Simpan sebagai Draft"). Pastikan
Anda langsung diarahkan ke halaman detail dengan status **"Dikirim ke
Produksi"**, tanda **"Multi Divisi"** dan **"Multi Factory"** muncul, dan
garis waktu status menunjukkan langkah yang benar.

### 6. Uji halaman Produksi "Order Masuk / Demand Tambahan"

Buka **Produksi** → tab **Order Masuk / Demand Tambahan**. Pastikan item
dari langkah 5 muncul di bawah kelompok divisinya masing-masing, dengan
badge Factory yang benar di sebelah nama divisi.

### 7. Uji Pesanan Non-Toko (pola yang sama)

Buka tab **Pesanan Non-Toko**, ulangi pengujian tampilan kartu item dan
routing otomatis yang sama.

### 8. Pastikan PO Reguler Toko tidak berubah

Buka tab **PO Toko** — pastikan tampilan dan datanya sama seperti
sebelumnya.

### 9. Cara mundur (rollback) jika ada masalah

Paket ini TIDAK mengubah database sama sekali — rollback cukup dengan
mengembalikan file-file dari backup langkah 1 (atau minta paket
sebelumnya dikirim ulang dan overwrite lagi).

---

## Risiko & Catatan penting

- **Tidak ada perubahan database sama sekali** — paket ini murni file.
- **PO Reguler Toko sama sekali tidak tersentuh.**
- **Tidak ada perubahan pada perhitungan stok, verifikasi Produksi/FG,
  pengiriman, konfirmasi penerimaan, email otomatis, atau harga Toko** —
  kecuali satu perbaikan kecil: cek stok FG kini memakai factory yang
  benar milik masing-masing item pada pesanan multi-factory (sebelumnya
  bisa salah tampil untuk kasus ini, sekarang benar).
- **Tema gelap (dark navy) Admin dipertahankan persis sama** — semua
  warna baru memakai variabel warna yang sudah ada, tidak ada tema baru.
- **Factory sekarang otomatis, tidak bisa dipilih manual** — ini
  perubahan aturan bisnis yang disengaja dan disetujui.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
