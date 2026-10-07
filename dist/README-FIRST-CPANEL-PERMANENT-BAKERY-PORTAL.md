# Amor Factory System — Portal Bakery Permanen (migrasi 0017) — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding.

**Paket ini MEMBUTUHKAN migrasi database baru (migrasi 0017).** Migrasi ini
hanya **menambah** 6 tabel baru — **tidak ada tabel yang dihapus, tidak ada
data yang dihapus, tidak ada perubahan pada tabel/alur yang sudah ada
(migrasi 0001–0016 sama sekali tidak disentuh).**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang baru: SATU link permanen per toko/Bakery, tanpa login

Setiap toko/Bakery sekarang punya **satu link tetap** (tidak pernah
berubah kecuali Admin sengaja membuatnya ulang) yang bisa dibuka kapan
saja, tanpa perlu username/password, berisi 5 menu:

### 1. Konfirmasi Penerimaan (Reject ada DI DALAM menu ini, bukan menu terpisah)

Toko melihat daftar pengiriman miliknya sendiri saja, membuka salah satu,
lalu mengisi jumlah Diterima Baik / Reject / Kurang per produk. Jika ada
Reject atau Kurang, **foto bukti wajib diunggah** sebelum bisa disimpan —
aturan ini berlaku di aplikasi maupun di server (tidak bisa dilewati).

### 2. Pesanan Khusus

Toko bisa mengajukan pesanan khusus sendiri (produk existing + jumlah +
tanggal dibutuhkan + foto referensi opsional). Pesanan ini **TIDAK
langsung masuk ke Produksi** — berstatus "Menunggu Verifikasi Admin"
sampai Admin meninjau dan memverifikasinya lewat alur yang sudah ada.

### 3. Retur

Untuk barang yang sudah diterima BAIK tapi belakangan tidak terjual
(bukan barang reject/rusak saat kirim — itu di menu Konfirmasi
Penerimaan). **Foto bukti wajib diunggah.** Retur ini **100% tanggung
jawab finansial toko** — TIDAK PERNAH mengurangi invoice/tagihan toko,
dan TIDAK PERNAH otomatis menambah stok Factory. Admin meninjau dan
memverifikasi di halaman Admin baru "Retur".

### 4. Mutasi Produk

Untuk perpindahan barang ANTAR TOKO (toko A mengirim ke toko B langsung,
tanpa lewat Factory). Toko pengirim mengajukan (dengan foto bukti wajib),
toko tujuan mengonfirmasi jumlah yang BENAR-BENAR diterima. Jika jumlah
diterima SAMA dengan yang dikirim, otomatis selesai. Jika BERBEDA, foto
bukti wajib diunggah dan statusnya "Selisih — Menunggu Admin" sampai
Admin memutuskan (Terima Apa Adanya / Batalkan). **Mutasi TIDAK PERNAH
mengubah stok Factory** — ini murni catatan perpindahan antar toko.

### 5. Riwayat

Ringkasan read-only semua aktivitas toko tersebut di 5 kategori di atas —
Pengiriman & Konfirmasi, Pesanan Khusus, Retur, Mutasi Keluar, Mutasi
Masuk.

---

## Yang baru di sisi Admin

- **Menu "Portal Bakery"** (sidebar Admin) — kelola link permanen tiap
  toko: Generate (pertama kali) / Regenerate (link lama langsung tidak
  berlaku) / Cabut. Link hanya ditampilkan SEKALI saat dibuat — server
  tidak pernah menyimpan nilai aslinya, jadi salin segera.
- **Menu "Retur"** — tinjau & verifikasi pengajuan Retur dari semua toko.
- **Menu "Mutasi Produk"** — tinjau Mutasi, dengan fokus pada kasus
  selisih yang perlu keputusan Admin.

## Email otomatis sekarang mengarah ke Portal permanen

Email pengiriman otomatis yang sudah ada **tetap terkirim seperti
biasa**, tapi tombolnya sekarang mengarah ke link Portal permanen toko
(bukan link sekali-pakai per pengiriman seperti dulu). Pengiriman
pertama ke sebuah toko otomatis menyertakan link permanennya (sekali
pakai, langsung aktif) — pengiriman berikutnya hanya pengingat biasa
(toko sudah punya link tersimpan). **Tautan email lama yang sudah
terkirim sebelumnya tetap berfungsi persis seperti sebelumnya** — rute
`/api/_receive/` lama sama sekali tidak diubah.

---

## Cara pasang (cPanel, tanpa command line)

1. **Backup dulu.** Di cPanel → phpMyAdmin, export (backup) database Anda
   saat ini. Ini WAJIB sebelum migrasi apa pun.
2. **Upload & extract.** Upload `amor-factory-permanent-bakery-portal.zip`
   ke `public_html/factory/`, lalu extract — ini akan MENIMPA file lama
   dengan versi baru (aman, tidak menghapus folder `api/app/config/`).
3. **Jalankan migrasi.** Buka `https://domainanda.com/factory/api/_upgrade/`
   di browser (login sebagai Admin dulu jika diminta), lalu jalankan
   migrasi hingga selesai (akan menunjukkan migrasi 0017 sebagai migrasi
   baru yang diterapkan — migrasi 0001–0016 akan ditandai "sudah
   diterapkan sebelumnya", TIDAK dijalankan ulang).
4. **Buat link pertama.** Login sebagai Admin → menu "Portal Bakery" →
   klik "Generate / Regenerate" untuk satu toko → salin link yang
   muncul → coba buka di HP/browser lain untuk memastikan 5 menu di
   atas terbuka dan terasa mudah dipakai di layar kecil.
5. **Coba salah satu alur berikut** untuk memastikan semuanya bekerja:
   - Buka link toko → menu Konfirmasi Penerimaan → konfirmasi satu
     pengiriman dengan Reject > 0 → unggah foto → simpan → cek muncul di
     Admin Konfirmasi Toko seperti biasa.
   - Buka link toko → menu Retur → ajukan Retur dengan foto → cek muncul
     di Admin "Retur" → Admin verifikasi.
   - Buka link DUA toko berbeda → toko A ajukan Mutasi ke toko B → toko B
     konfirmasi → cek status jadi "Selesai" (jika jumlah sama) atau
     "Selisih — Menunggu Admin" (jika beda) → Admin selesaikan dari menu
     "Mutasi Produk".

---

## Yang TIDAK berubah

- Semua alur PO Reguler, Driver Portal, Produksi, FG & Packing, Delivery
  Order, email otomatis (isi & pemicu pengirimannya), Admin Konfirmasi
  Toko, dan link `/api/_receive/` yang sudah ada — semuanya bekerja
  PERSIS seperti sebelumnya.
- Retur dan Mutasi **tidak pernah** menyentuh stok Factory
  (`stock_ledger`) — ini sengaja dan sudah diverifikasi lewat pengujian
  otomatis maupun manual di browser.
- Tidak ada perubahan pada tabel/kolom manapun dari migrasi 0001–0016.

---

## Keamanan

Akses ke Portal Bakery murni dikontrol oleh token acak 256-bit di dalam
link itu sendiri (tidak bisa ditebak) — bukan oleh apa pun yang dikirim
dari browser toko. Server tidak pernah menyimpan nilai token asli, hanya
hash-nya. Setiap folder unggahan foto bukti baru (Retur/Mutasi/Pesanan
Khusus) diproteksi `Require all denied` seperti folder bukti foto yang
sudah ada, dan setiap file yang diunggah divalidasi isinya (bukan hanya
nama filenya) sebelum disimpan. Sesi login Admin, token CSRF, query
database dengan parameter aman, otorisasi per-peran, dan catatan audit —
semuanya tetap berjalan seperti sebelumnya, diperluas ke alur baru ini
tanpa dilonggarkan sedikit pun.
