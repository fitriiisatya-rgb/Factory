# Amor Factory System — Perbaikan Foto Bukti di Detail Admin (Konfirmasi Toko) — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Paket ini HANYA berisi
file — tidak ada migrasi database sama sekali** (migrasi 0009 sudah
terpasang dari patch sebelumnya dan TIDAK berubah).

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Masalah yang dilaporkan dari real-UAT cPanel

Di halaman **Admin → Konfirmasi Toko → Detail**, pada bagian **"Bukti
Foto dari Toko"**, foto yang diunggah Toko tampil **sangat besar / hampir
memenuhi satu halaman penuh**.

Ini murni masalah tampilan di sisi Admin — bukan masalah data atau alur
kerja. (Perbaikan foto di sisi Toko sudah dilakukan sebelumnya di patch
terpisah dan TIDAK diubah lagi di sini.)

---

## Penyebab sebenarnya yang ditemukan (bukan dugaan — sudah diaudit langsung)

Kode CSS yang membatasi ukuran thumbnail (96x96px) **sebenarnya sudah
ada** sejak patch bukti foto pertama kali dibuat, dan sudah benar. Yang
ditemukan justru: **halaman Admin (termasuk halaman Detail ini) sama
sekali tidak memiliki penanda versi pada file CSS/JS-nya** — beda dengan
halaman Toko dan halaman Driver yang sudah lebih dulu diperbaiki. Artinya
jika HP/tablet Admin masih menyimpan file CSS lama di cache (dari versi
sebelum aturan pembatas ukuran ini ada, atau dari sebelum perbaikan
apa pun di masa depan), file lama itu bisa saja terus terpakai tanpa
batas waktu.

Ditemukan juga: mengetuk foto bukti sebelumnya langsung membuka file foto
asli di tab baru (tanpa batasan ukuran sama sekali) — ini sekarang
diganti dengan jendela pratinjau (lightbox) yang ukurannya selalu
dibatasi.

Paket ini memperbaiki:
1. Menambahkan penanda versi (cache-busting) pada file CSS/JS halaman
   Admin, supaya file lama di cache tidak lagi terpakai.
2. Memperkuat batasan ukuran thumbnail (tambahan batas maksimal, selain
   ukuran tetap yang sudah ada).
3. Mengganti "buka foto asli di tab baru" menjadi jendela pratinjau
   (lightbox) yang selalu dibatasi ukurannya, dengan tombol tutup, bisa
   ditutup dengan tombol Escape, atau dengan mengetuk di luar jendela.

---

## Yang TIDAK berubah (tetap sama seperti sebelumnya)

- **Bukti foto tetap hanya diunggah oleh Toko** — Admin tetap hanya bisa
  melihat dan memverifikasi, tidak pernah mengunggah atau mengganti.
- Data kuantitas (Diterima Baik/Reject/Kurang), status konfirmasi, dan
  hasil verifikasi selisih **tidak berubah sama sekali**.
- Aturan keamanan: hanya Admin yang bisa melihat foto bukti lewat jalur
  resmi (`/api/admin/receipts/evidence/{id}`) — Driver atau pihak lain
  tetap ditolak (403), sama seperti sebelumnya.
- Tidak ada perubahan pada perhitungan stok, klaim Driver, token QR,
  logika email pengiriman, atau aturan bisnis DO/FG/Production/PO.
- Tidak ada migrasi database baru — migrasi 0009 yang sudah terpasang
  tetap seperti semula.
- Halaman Konfirmasi Penerimaan Barang di sisi Toko (yang sudah
  diperbaiki di patch sebelumnya) **tidak disentuh lagi** di paket ini.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- File `amor-factory-admin-receipt-evidence-thumbnail-hotfix.zip`
  (dikirim bersama panduan ini).

**Tidak perlu** kredensial migrasi database — paket ini tidak mengubah
struktur database sama sekali.

---

## Langkah-langkah

### 1. Backup (tetap disarankan, walau paket ini hanya file)

cPanel → Backup Wizard → backup folder `public_html/factory/`.

### 2. Upload & Extract (dengan Timpa/Overwrite)

Upload `amor-factory-admin-receipt-evidence-thumbnail-hotfix.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Jika ditanya
"timpa atau lewati", selalu pilih **Timpa/Overwrite**. `config.php` Anda
TIDAK ikut ditimpa (tidak ada di dalam paket ini).

### 3. Tidak ada migrasi

Paket ini TIDAK memerlukan langkah migrasi apa pun — langsung ke langkah
berikutnya.

### 4. Hard refresh di browser Admin

Login sebagai Admin, lalu lakukan **hard refresh** (tutup tab lalu buka
lagi, atau bersihkan cache browser) supaya file CSS/JS lama yang
ter-cache tidak dipakai. Paket ini menambahkan penanda versi baru, jadi
seharusnya otomatis mengambil file terbaru — tapi hard refresh tetap
disarankan untuk memastikan.

### 5. Uji halaman Detail Konfirmasi Toko

Buka **Konfirmasi Toko** → pilih salah satu konfirmasi yang punya bukti
foto (Reject/Kurang > 0 dengan foto terlampir) → buka **Detail**.
Pastikan:

- Bagian **"Bukti Foto dari Toko"** menampilkan **thumbnail kecil**
  (bukan foto besar memenuhi layar), tersusun rapi berjajar.
- Mengetuk/klik salah satu thumbnail membuka **jendela pratinjau**
  (bukan tab baru dengan file asli) — ukurannya tetap dibatasi dan tidak
  memenuhi seluruh layar.
- Jendela pratinjau bisa ditutup dengan tombol "×", tombol Escape, atau
  mengetuk area gelap di luar foto.
- Coba juga di HP/tablet — thumbnail tetap kecil, tidak melebar ke luar
  layar.

### 6. Pastikan data tidak berubah

Periksa bahwa kuantitas (Diterima Baik/Reject/Kurang), status
("Ada Selisih"/"Diterima Sesuai"/"Diverifikasi Admin"), dan tombol
**Verifikasi Selisih** masih berfungsi seperti biasa — paket ini murni
perbaikan tampilan, bukan perubahan data.

### 7. Cara mundur (rollback) jika ada masalah

Karena paket ini hanya mengubah file kode (tidak ada perubahan database),
cara mundur paling aman adalah mengembalikan file-file yang tertimpa dari
backup langkah 1. Tidak ada data yang perlu dibersihkan di database.

---

## Risiko & Catatan penting

- **Tidak ada perubahan pada perhitungan stok, klaim Driver, token QR,
  logika email, atau aturan bisnis DO/FG/Production/PO** — paket ini
  murni memperbaiki cara bukti foto ditampilkan di halaman Detail Admin.
- **Foto bukti tetap hanya bisa dilihat oleh Admin lewat jalur resmi
  yang sudah ada** — tidak ada perubahan pada siapa yang boleh
  mengakses foto.
- **Migrasi database 0009 tidak berubah** — tidak perlu membuka halaman
  `_upgrade/` sama sekali untuk paket ini.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
