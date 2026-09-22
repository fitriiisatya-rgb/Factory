# Amor Factory System — Pesanan Khusus Toko / Pesanan Non-Toko + Routing Produksi — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding.

**Paket ini MEMBUTUHKAN migrasi database baru (migrasi 0010).** Ini
BERBEDA dari beberapa paket perbaikan sebelumnya yang hanya berisi file —
paket ini menambah 3 tabel baru di database Anda. **Tidak ada tabel lama
yang diubah, diganti nama, atau dihapus.**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang baru: 4 sumber permintaan produksi

Sebelumnya, produksi hanya mengenal **PO Reguler Toko**. Sekarang sistem
mengenal **empat** sumber permintaan produksi, dan setiap sumber tetap
**terpisah dan bisa ditelusuri asalnya**:

1. **PO Reguler Toko** — alur yang sudah ada, **tidak berubah sama
   sekali**.
2. **Pesanan Khusus Toko** *(BARU)* — pesanan tambahan dari toko/bakery
   di luar PO reguler (misal: pesanan event, kue custom).
3. **Pesanan Non-Toko** *(BARU)* — pesanan dari Konsumen Langsung, CS,
   Sales Executive, atau Umum (bukan dari toko manapun).
4. **Replacement Reject** — di luar cakupan paket ini, belum dibangun.

**Prinsip utama: Pesanan Khusus Toko dan Pesanan Non-Toko TIDAK PERNAH
digabung ke PO Reguler.** Keduanya disimpan di tabel yang sama sekali
berbeda dari PO, dan Produksi selalu bisa melihat pesanan itu berasal
dari mana.

---

## Menu baru

Tidak ada perubahan pada menu samping (sidebar) — mengikuti gaya tampilan
yang sudah ada, menu baru muncul sebagai **tab** di halaman yang relevan:

- Buka **Pesanan Toko** → sekarang ada 3 tab di atas: **PO Toko**
  (seperti biasa) / **Pesanan Khusus Toko** *(baru)* / **Pesanan
  Non-Toko** *(baru)*.
- Buka **Produksi** → sekarang ada 2 tab di atas: **Ceklis Produksi**
  (seperti biasa) / **Order Masuk / Demand Tambahan** *(baru)*.

---

## Cara kerja Pesanan Khusus Toko / Pesanan Non-Toko

1. Admin/PPIC membuat pesanan baru lewat form di tab yang sesuai — isi
   toko (atau sumber non-toko), tanggal dibutuhkan, lalu tambahkan item
   satu per satu.
2. Setiap item bisa berupa:
   - **Produk Existing** — produk yang sudah ada di Master Data (divisi
     produksinya otomatis mengikuti produk tersebut).
   - **Item Khusus/Custom** — dari katalog terkontrol (contoh: DELUXE
     KARAKTER 12/16/18/20/22/24/30, ICING 12/16/18/20/22/24/30) yang
     otomatis masuk ke divisi **Cake & Custom** (divisi baru yang dibuat
     otomatis pertama kali dipakai).
3. Setiap item **wajib** punya divisi produksi yang jelas — terlihat
   langsung di form sebelum pesanan dikirim ke Produksi.
4. **Satu pesanan boleh berisi item dari beberapa divisi berbeda**
   (misalnya kue custom untuk divisi Cake & Custom DAN roti untuk divisi
   Roti & Bollen dalam satu pesanan yang sama) — kartu pesanan akan
   menunjukkan tanda "Multi Divisi".
5. Setiap item bisa punya **Charge** (biaya tambahan, misal biaya
   dekorasi custom) — ini terpisah dari harga produk biasa, dan **tidak
   mengubah aturan harga Toko yang sudah ada (diskon 50%/60%)**.
6. Setiap item bisa punya **Catatan Khusus** (misal "Tema Spiderman",
   "Tulisan HBD Raka") — catatan ini akan terlihat oleh Produksi.
7. Alur status: **Draft → Dikonfirmasi → Dikirim ke Produksi → Sedang
   Diproduksi → Siap → Selesai** (atau **Dibatalkan** di tahap mana pun
   sebelum Selesai).

---

## Halaman Produksi baru: "Order Masuk / Demand Tambahan"

Menampilkan semua item dari Pesanan Khusus Toko / Pesanan Non-Toko yang
sudah dikirim ke Produksi, **dikelompokkan per divisi produksi** — jadi
satu pesanan yang berisi beberapa divisi akan otomatis muncul di
masing-masing bagian divisinya. Ada filter Pabrik / Divisi / Tanggal
Dibutuhkan / Sumber / Status.

Untuk produk existing, halaman ini juga menunjukkan **stok FG yang
tersedia** dan **kebutuhan produksi tambahan** (jika stok tidak
mencukupi) — ini murni informasi, bukan alokasi/kunci stok otomatis
(lihat bagian "Yang belum dibangun" di bawah).

---

## Yang TIDAK berubah

- **PO Reguler Toko** — alur import, tampilan, dan datanya **sama sekali
  tidak disentuh**.
- Perhitungan stok, logika verifikasi Produksi/FG, pengiriman, konfirmasi
  penerimaan toko, email otomatis, harga Toko (diskon 50%/60%), dan
  Mutasi Antar Toko — **semua tidak berubah**.
- Tampilan Admin **tetap memakai tema gelap (dark navy)** yang sama
  persis — tidak ada tema baru, tidak ada font baru. Halaman baru terasa
  seperti bagian dari aplikasi yang sudah ada.

---

## Yang belum dibangun (disengaja, bukan bug)

- **Belum ada penguncian/reservasi stok FG otomatis.** Jika 2 pesanan
  sama-sama membutuhkan produk yang sama, sistem menunjukkan stok yang
  tersedia untuk keduanya secara terpisah — belum ada mekanisme "kunci"
  supaya stok itu tidak dipakai dobel. Ini keputusan yang disengaja untuk
  fase ini (bukan lupa) — koordinasi manual masih diperlukan untuk kasus
  ini.
- **Belum bisa mengedit pesanan setelah dibuat** (item tidak bisa
  ditambah/dihapus setelah disimpan). Untuk memperbaiki kesalahan,
  batalkan pesanan dan buat yang baru.
- **Replacement Reject** (permintaan pengganti akibat barang reject)
  belum dibangun di fase ini.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- File `amor-factory-special-nonregular-order-production-routing.zip`
  (dikirim bersama panduan ini).
- **Kredensial migrasi database** (`MIGRATION_DB_USER`/`MIGRATION_DB_PASS`)
  — sudah pernah dipakai sebelumnya untuk migrasi 0008/0009, isi lagi
  sementara di `config.php` khusus untuk migrasi ini.

---

## Langkah-langkah

### 1. Backup (WAJIB kali ini — ada migrasi database)

cPanel → Backup Wizard → backup **folder** `public_html/factory/` DAN
**database** MySQL Anda. Migrasi ini hanya MENAMBAH tabel baru (tidak
menghapus apa pun), tapi backup tetap wajib sebelum migrasi apa pun.

### 2. Upload & Extract (dengan Timpa/Overwrite)

Upload `amor-factory-special-nonregular-order-production-routing.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Selalu pilih
**Timpa/Overwrite**. `config.php` Anda TIDAK ikut ditimpa (tidak ada di
dalam paket ini).

### 3. Terapkan migrasi 0010

Buka `public_html/factory/api/_upgrade/` di browser, login sebagai
ADMIN. Jika diminta kredensial migrasi, isi sementara di `config.php`
(lihat `config.example.php` untuk contoh `MIGRATION_DB_USER`/
`MIGRATION_DB_PASS`). Halaman akan menunjukkan **migrasi 0010 menunggu
diterapkan** — centang kotak konfirmasi, klik **Terapkan Migrasi**.
Setelah berhasil, Anda boleh menghapus baris `MIGRATION_DB_PASS` dari
`config.php` lagi.

### 4. Hard refresh di browser Admin

Login sebagai Admin, lalu **hard refresh** (tutup tab lalu buka lagi)
supaya file lama yang ter-cache tidak dipakai.

### 5. Uji Pesanan Khusus Toko

Buka **Pesanan Toko** → tab **Pesanan Khusus Toko**. Buat pesanan baru:
pilih toko, isi tanggal dibutuhkan, tambahkan 1 item Produk Existing dan
1 item Item Khusus/Custom (misal DELUXE KARAKTER 16) — pastikan **divisi
produksi tampil otomatis** untuk masing-masing item. Simpan, lalu buka
Detail — pastikan tampil **"Multi Divisi"** karena dua item ini berasal
dari divisi berbeda. Klik **Konfirmasi Pesanan**, lalu **Kirim ke
Produksi**.

### 6. Uji Pesanan Non-Toko

Buka tab **Pesanan Non-Toko**. Buat pesanan baru dengan Sumber = CS,
isi Nama Customer, tambahkan 1 item dengan Catatan Khusus (misal "Tema
Spiderman"). Simpan, Konfirmasi, Kirim ke Produksi.

### 7. Uji halaman Produksi "Order Masuk / Demand Tambahan"

Buka **Produksi** → tab **Order Masuk / Demand Tambahan**. Pastikan
kedua pesanan dari langkah 5 & 6 muncul, **dikelompokkan per divisi**,
dan **catatan khusus terlihat**. Coba filter per Divisi/Sumber/Status.

### 8. Pastikan PO Reguler Toko tidak berubah

Buka tab **PO Toko** — pastikan tampilan dan datanya sama seperti
sebelum paket ini dipasang.

### 9. Cara mundur (rollback) jika ada masalah

Karena ada migrasi database (menambah 3 tabel baru), rollback yang aman:
- Kembalikan file-file dari backup langkah 1.
- Migrasi 0010 hanya MENAMBAH tabel baru — jika perlu benar-benar
  mengembalikan struktur database ke sebelum migrasi ini, gunakan backup
  database dari langkah 1 (bukan menghapus tabel secara manual).

---

## Risiko & Catatan penting

- **PO Reguler Toko sama sekali tidak tersentuh** — tabel, alur import,
  dan tampilannya identik dengan sebelumnya.
- **Tidak ada perubahan pada perhitungan stok, verifikasi Produksi/FG,
  pengiriman, konfirmasi penerimaan, email otomatis, atau harga Toko.**
- **Stok FG yang ditampilkan di halaman Order Masuk bersifat informasi,
  bukan alokasi otomatis** — lihat bagian "Yang belum dibangun" di atas.
- **Tema gelap (dark navy) Admin dipertahankan persis sama** — tidak ada
  tampilan baru yang terasa asing.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
