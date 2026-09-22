# Amor Factory System — Perbaikan Ukuran Tulisan di Kartu Detail Admin — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Paket ini HANYA berisi
file — tidak ada migrasi database sama sekali** (migrasi 0009 sudah
terpasang dari patch sebelumnya dan TIDAK berubah).

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Masalah yang dilaporkan dari real-UAT cPanel (terlihat di iPad/tablet)

Di halaman **Admin → Konfirmasi Toko → Detail**, beberapa nilai di dalam
kartu informasi tampil dengan ukuran tulisan yang **terlalu besar dan
tidak konsisten** dengan tampilan Admin lainnya. Contoh:

- **Email Pengiriman**: Status ("Terkirim"), Tujuan (alamat email),
  Percobaan ("1")
- **Konfirmasi Toko**: Waktu Konfirmasi ("22 Sep 2026 · 07:21"), Status
  ("Ada Selisih")

Nilai-nilai ini tampil seperti angka besar di Dashboard, padahal ini
adalah halaman detail informasi biasa — membuat tampilan terasa tidak
seimbang.

---

## Penyebab sebenarnya yang ditemukan (bukan dugaan — sudah diaudit langsung)

Semua nilai di atas ternyata memakai **komponen kartu yang sama** dengan
kartu KPI (indikator kinerja) di halaman Dashboard, Delivery Order,
FG & Packing, Pengiriman, Pesanan Toko, dan Produksi. Di halaman-halaman
itu, ukuran tulisan besar memang TEPAT karena nilainya adalah angka
ringkasan (misalnya total, jumlah). Tapi khusus di halaman **Detail
Konfirmasi Toko**, komponen yang sama ini dipakai untuk menampilkan
teks biasa (nama, status, tanggal, email) — bukan angka ringkasan —
sehingga terlihat berlebihan.

Ditemukan juga: baris kartu ini sebelumnya diatur langsung lewat kode di
halaman (bukan lewat kelas CSS biasa), yang membuatnya **tidak pernah
menyesuaikan diri ke lebih sedikit kolom di HP/tablet** — padahal seluruh
halaman Admin lain sudah otomatis menyesuaikan.

Paket ini memperbaiki:
1. Kartu-kartu di halaman Detail Konfirmasi Toko sekarang memakai ukuran
   tulisan yang lebih pas untuk halaman informasi (bukan ukuran KPI
   Dashboard) — **kartu KPI asli di halaman lain sama sekali tidak
   berubah**.
2. Alamat email yang panjang sekarang selalu tetap berada di dalam
   kartunya sendiri, tidak pernah meluber keluar.
3. Baris kartu ini sekarang ikut menyesuaikan jumlah kolom di HP/tablet,
   sama seperti bagian Admin lainnya.

---

## Yang TIDAK berubah (tetap sama seperti sebelumnya)

- **Kartu KPI asli** (Dashboard, Delivery Order, FG & Packing,
  Pengiriman, Pesanan Toko, Produksi) — ukuran tulisannya **sama sekali
  tidak berubah**.
- Data kuantitas (Diterima Baik/Reject/Kurang), status konfirmasi, hasil
  verifikasi selisih, dan status email **tidak berubah sama sekali** —
  ini murni perbaikan tampilan.
- Perbaikan foto bukti (thumbnail kecil + jendela pratinjau) dari patch
  sebelumnya **tetap berfungsi seperti biasa**.
- Tidak ada perubahan pada perhitungan stok, klaim Driver, token QR,
  logika email pengiriman, atau aturan bisnis DO/FG/Production/PO.
- Tidak ada migrasi database baru — migrasi 0009 yang sudah terpasang
  tetap seperti semula.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- File `amor-factory-admin-detail-typography-hotfix.zip` (dikirim
  bersama panduan ini).

**Tidak perlu** kredensial migrasi database — paket ini tidak mengubah
struktur database sama sekali.

---

## Langkah-langkah

### 1. Backup (tetap disarankan, walau paket ini hanya file)

cPanel → Backup Wizard → backup folder `public_html/factory/`.

### 2. Upload & Extract (dengan Timpa/Overwrite)

Upload `amor-factory-admin-detail-typography-hotfix.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Jika ditanya
"timpa atau lewati", selalu pilih **Timpa/Overwrite**. `config.php` Anda
TIDAK ikut ditimpa (tidak ada di dalam paket ini).

### 3. Tidak ada migrasi

Paket ini TIDAK memerlukan langkah migrasi apa pun — langsung ke langkah
berikutnya.

### 4. Hard refresh di browser Admin

Login sebagai Admin, lalu lakukan **hard refresh** (tutup tab lalu buka
lagi, atau bersihkan cache browser) supaya file CSS lama yang ter-cache
tidak dipakai. Paket ini menaikkan penanda versi asset yang sudah ada
dari patch sebelumnya, jadi seharusnya otomatis mengambil file terbaru —
tapi hard refresh tetap disarankan untuk memastikan.

### 5. Uji halaman Detail Konfirmasi Toko

Buka **Konfirmasi Toko** → buka **Detail** salah satu pengiriman.
Pastikan:

- Nilai di kartu "No. DO", "Driver", "Factory Asal", "Waktu Berangkat"
  tampil dengan ukuran yang wajar (bukan seperti angka besar Dashboard).
- Bagian **Email Pengiriman** (Status/Tujuan/Percobaan) dan
  **Konfirmasi Toko** (Nama Penerima/Waktu Konfirmasi/Status) juga
  tampil dengan ukuran yang wajar dan konsisten.
- Jika email tujuan panjang, pastikan teksnya tetap berada di dalam
  kartu (boleh turun ke baris berikutnya), tidak meluber ke luar atau
  menabrak kartu di sebelahnya.
- Coba juga di HP/tablet — kartu-kartu ini menyesuaikan ke lebih sedikit
  kolom, tidak ada yang terpotong atau melebar ke luar layar.

### 6. Pastikan bagian lain tidak berubah

Buka **Dashboard** dan halaman lain yang punya kartu ringkasan (Delivery
Order, FG & Packing, Pengiriman, Pesanan Toko, Produksi) — pastikan
angka-angka di sana **masih tampil besar seperti biasa** (tidak ikut
mengecil). Periksa juga bukti foto dan tombol Verifikasi Selisih di
halaman Detail Konfirmasi Toko masih berfungsi normal.

### 7. Cara mundur (rollback) jika ada masalah

Karena paket ini hanya mengubah file kode (tidak ada perubahan database),
cara mundur paling aman adalah mengembalikan file-file yang tertimpa dari
backup langkah 1. Tidak ada data yang perlu dibersihkan di database.

---

## Risiko & Catatan penting

- **Tidak ada perubahan pada perhitungan stok, klaim Driver, token QR,
  logika email, atau aturan bisnis DO/FG/Production/PO** — paket ini
  murni memperbaiki ukuran tulisan di kartu Detail Konfirmasi Toko.
- **Kartu KPI asli di halaman lain sama sekali tidak tersentuh.**
- **Migrasi database 0009 tidak berubah** — tidak perlu membuka halaman
  `_upgrade/` sama sekali untuk paket ini.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
