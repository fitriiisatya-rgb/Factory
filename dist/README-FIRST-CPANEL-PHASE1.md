# Amor Factory — Panduan Phase 1 (Master + Identity)

> **⚠️ SUDAH DIGANTIKAN — jangan pakai panduan ini lagi.** Panduan ini
> ditulis untuk `amor-factory-api-phase1-incremental.zip` dengan asumsi
> path yang SALAH (`public_html/api/`). Setelah deployment nyata, path
> yang benar adalah **`public_html/factory/api/`** (domain
> `factory.amorgroup.id` document root-nya adalah `public_html/factory/`,
> bukan `public_html/` itu sendiri). Gunakan
> `dist/README-FIRST-CPANEL-PHASE1-V2.md` dan
> `dist/amor-factory-api-phase1-easy-v2.zip` sebagai gantinya — panduan
> itu juga menambahkan halaman login admin (`_admin-login/`) yang tidak
> ada di paket ini, dan wizard review toko yang lebih lengkap. Sisa isi
> file ini dibiarkan apa adanya sebagai catatan sejarah paket lama.

**Baca ini setelah Phase 0.5 selesai** (skema database + data awal + admin
sudah terpasang, `/api/health` sudah OK). Panduan ini untuk memasang divisi,
katalog produk lama (472 item), dan data toko yang benar-benar ada di
source code aplikasi Amor Factory — **bukan** memasang PO, produksi, FG,
pengiriman, stok, atau invoice (itu tahap berikutnya).

**Ini INCREMENTAL** — tidak perlu mengulang setup Phase 0.5. File
konfigurasi (`config.php`) yang sudah Anda isi TIDAK akan tertimpa/hilang.

---

## Yang Anda butuhkan

- File `amor-factory-api-phase1-incremental.zip`
- Akses cPanel File Manager ke `public_html/api/` (folder Phase 0.5 yang
  sudah ada)
- Username & password ADMIN yang sudah dibuat di Phase 0.5

---

## STEP 1 — Upload & Extract (menimpa folder lama, aman)

1. cPanel → File Manager → masuk ke `public_html`.
2. Upload `amor-factory-api-phase1-incremental.zip`.
3. Klik kanan → **Extract**. Saat ditanya "folder api sudah ada, timpa?",
   pilih **Yes/Overwrite**.

**Hasil yang diharapkan**: folder `public_html/api/` sekarang punya 2 folder
baru: `_import-master/` dan `_upgrade/`, ditambah isi `api/app/src/` yang
terbarui. **File `public_html/api/app/config/config.php` Anda TIDAK
berubah** — boleh dicek isinya tetap sama seperti sebelumnya.

---

## STEP 2 — Terapkan Pembaruan Struktur Database

1. Login dulu ke aplikasi (kalau belum): buka
   `https://factory.amorgroup.id/api/` di browser Anda biasanya login lewat
   halaman utama aplikasi — atau gunakan akun ADMIN yang sama dari Phase 0.5.
2. Buka:
   ```
   https://factory.amorgroup.id/api/_upgrade/
   ```
3. Kalau diminta login, gunakan akun ADMIN yang sama dari Phase 0.5.
4. Halaman menunjukkan **"Menunggu diterapkan (1): 0002_master_identity.php"**.
5. Centang kotak konfirmasi, klik **"Terapkan Migrasi"**.

**Hasil yang diharapkan**: pesan hijau "Migrasi berhasil diterapkan". Ini
HANYA menambah 1 kolom baru + 2 index — tidak menghapus tabel atau data
apa pun.

**Catatan**: halaman `_upgrade/` ini AMAN ditinggalkan permanen (tidak
wajib dihapus) — selalu butuh login ADMIN asli, dan hanya bisa menjalankan
pembaruan yang memang sudah ada di dalam paket aplikasi, bukan perintah
bebas.

---

## STEP 3 — Impor Divisi, Produk, dan Toko

1. Buka:
   ```
   https://factory.amorgroup.id/api/_import-master/
   ```
2. Halaman ini menampilkan 3 bagian dengan pratinjau jumlah data:
   - **1. Divisi + Pabrik** — 8 divisi (termasuk pemetaan otomatis ke
     pabrik Karangtengah/Cibadak).
   - **2. Produk (Katalog Bawaan)** — 472 produk dari katalog bawaan
     aplikasi.
   - **3. Toko & Alias** — 1 toko resmi (BAKERY CIKOLE) beserta 2 nama
     alternatifnya (CKLE, CIKOLE).
3. Untuk setiap bagian: centang kotak konfirmasi, klik tombol import-nya.
   Urutan disarankan: Divisi dulu, baru Produk, baru Toko (halaman sudah
   mengunci urutan ini secara otomatis).

**Hasil yang diharapkan** setelah ketiganya selesai: pesan hijau di setiap
bagian, dan bagian Produk menunjukkan **"REVIEW: 0"** dan **"CONFLICT: 0"**
(katalog aslinya memang bersih, tidak ada duplikat).

**PENTING — jujur soal keterbatasan data toko**: hanya SATU toko yang
benar-benar ditemukan di source code aplikasi (BAKERY CIKOLE). Kalau ada
toko lain yang perlu dimasukkan (misalnya toko yang disebutkan sebelumnya
seperti "BAKERY SUDIRMAN"), itu **tidak** otomatis dipasang di sini karena
tidak ada bukti dari source code — harus ditambahkan manual lewat
`POST /api/stores` setelah Anda konfirmasi nama & datanya benar.

---

## STEP 4 — Selesaikan Baris yang Butuh Review (jika ada)

Kalau bagian Produk di STEP 3 menunjukkan angka REVIEW atau CONFLICT lebih
dari 0 (untuk data Anda saat ini seharusnya 0, tapi bisa muncul di masa
depan kalau katalog berubah), setiap baris itu butuh keputusan manual —
tidak bisa otomatis. Serahkan ke developer untuk menyelesaikannya lewat:

```
GET /api/admin/migration/products?status=unresolved
POST /api/admin/migration/products/{id}/resolve
```

Ini sengaja tidak dibuatkan tombol otomatis di halaman wizard — setiap
baris REVIEW/CONFLICT butuh orang yang benar-benar tahu apakah dua nama
produk yang mirip itu sungguh sama atau berbeda.

---

## STEP 5 — Nonaktifkan/Hapus Import Wizard

Setelah STEP 3–4 selesai dan hasilnya sudah dicek:

1. Di halaman `_import-master/`, scroll ke bawah, klik
   **"Nonaktifkan Import Wizard"**.
2. Untuk keamanan penuh, hapus juga folder `public_html/api/_import-master/`
   lewat File Manager kapan pun sempat.

**`_upgrade/` boleh TETAP ada** — beda dari `_import-master/`, ini
dirancang untuk dipakai lagi di pembaruan berikutnya (Phase 2, dst.).

---

## STEP 6 — Verifikasi

```
https://factory.amorgroup.id/api/health
```

Harus tetap `"db":"connected"` seperti sebelumnya — Phase 1 tidak mengubah
cara aplikasi konek ke database.

Cek tambahan (boleh diserahkan ke developer):
```
GET /api/divisions   -> harus menunjukkan 8 divisi
GET /api/products    -> harus menunjukkan 472 produk
GET /api/stores      -> harus menunjukkan 2 toko (BAKERY CIKOLE + NON-OUTLET/PERORANGAN)
```

---

## Yang BELUM dilakukan (sengaja)

Tidak ada data PO, produksi, FG, DO (surat jalan), pengiriman, stok,
invoice, pembayaran, retur, atau penjualan yang disentuh oleh Phase 1.
Frontend/tampilan Amor Factory yang sekarang **masih memakai Apps
Script/Google Sheets seperti biasa** — belum ada yang beralih ke database
baru ini. Tahap berikutnya (memindahkan modul transaksi) adalah keputusan
terpisah yang akan direncanakan setelah Phase 1 ini direview.
