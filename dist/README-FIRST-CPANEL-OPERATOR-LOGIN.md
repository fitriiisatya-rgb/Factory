# Amor Factory System — Login Divisi + Nama Operator (migrasi 0019) — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding.

**Paket ini MEMBUTUHKAN migrasi database baru (migrasi 0019).** Migrasi ini
hanya **menambah 5 kolom baru** (3 di tabel Produksi, 1 di item Pesanan
Khusus/Non-Toko, 1 di Replacement) untuk mencatat nama orang yang input — **tidak ada tabel yang dihapus, tidak ada
data yang dihapus, tidak ada kolom lama yang diubah (migrasi 0001–0018 sama
sekali tidak disentuh).**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## PENTING: paket ini juga berisi Portal Bakery (0017) dan Invoice (0018)

Seperti semua paket sebelumnya, ZIP ini berisi **seluruh** folder `api/`
versi terbaru. Artinya:

- Kalau server Anda **masih di migrasi 0016** (belum pernah memasang
  `amor-factory-permanent-bakery-portal.zip` dan `amor-factory-invoice.zip`),
  cukup pasang **ZIP ini saja**. Wizard Upgrade akan menjalankan migrasi
  **0017, 0018, lalu 0019** berurutan. Anda akan langsung mendapat Portal
  Bakery, Invoice, dan Login Divisi sekaligus.
- Kalau server Anda sudah di 0017 atau 0018, wizard hanya menjalankan
  migrasi yang belum ada.

Cek posisi server Anda: buka `/api/_upgrade/` sebelum extract. Halaman itu
menampilkan migrasi mana yang sudah diterapkan.

---

## Apa yang baru

### 1. Setiap divisi produksi bisa login sendiri

Sebelumnya, hanya Admin yang bisa login dan Admin yang menginput hasil
produksi untuk semua divisi. Sekarang:

- **Satu akun login per divisi** (misalnya `divisi_roti`), dipakai bersama
  oleh staf divisi itu.
- Halaman login yang sama (`/api/_admin-login/`) sekarang menerima akun
  **ADMIN**, **PPIC**, dan **akun divisi Produksi**. Akun lain (Driver,
  FG Packing, dll.) tetap ditolak. Driver tetap login lewat Portal Driver.

### 2. "Siapa Anda?" — nama orang yang input dicatat

Karena satu akun dipakai beramai-ramai, setelah login akun divisi akan
ditanya **"Siapa Anda?"**. Staf mengetik namanya sendiri (bebas, misalnya
"Budi"). Nama itu:

- Dicatat setiap kali **membuat**, **mengubah**, dan **submit** produksi,
  dan juga saat mengisi **Actual Pesanan Khusus/Non-Toko** ("Diisi oleh: …")
  dan **Actual Replacement**.
- Tampil di halaman Produksi: "Dibuat oleh: Budi · Terakhir diupdate oleh:
  Siti · Disubmit oleh: Siti", dan di kolom **Disubmit Oleh** di tabel
  ringkasan divisi.
- Tampil di pojok kanan atas: `PRODUCTION · Budi (ganti)`. Kalau ganti
  shift di komputer yang sama, klik **(ganti)** dan ketik nama baru.
  **Tidak perlu logout.**
- Ditanya ulang **setiap kali login**. Nama orang sebelumnya tidak pernah
  "menempel" ke login berikutnya.

Admin dan PPIC **tidak pernah** ditanya, karena mereka login dengan akun
pribadi. Data produksi lama (sebelum paket ini) tetap tampil seperti biasa,
memakai nama akun.

### 3. Akun divisi hanya melihat menu yang relevan

Akun divisi Produksi hanya bisa membuka **Produksi** (termasuk Order Masuk
dan Task per Divisi), **FG & Packing**, dan **Pengaturan**. Menu Laporan
(berisi Omset), Master Data, Pesanan, DO, Invoice, dan lain-lain
**tidak tampil dan ditolak oleh server** (403) walau URL-nya diketik
langsung.

### 4. Perbaikan keamanan: akun Driver tidak bisa lagi membuka menu Admin

Ditemukan saat pemeriksaan sistem: akun **Driver** yang login lewat Portal
Driver ternyata bisa membuka halaman Admin seperti **Laporan (termasuk
Omset per toko)**, Master Data, Dashboard, dan Pesanan dengan mengetik
URL-nya langsung. Sekarang ditolak (403). Portal Driver sendiri tetap
berjalan seperti biasa. Aturan yang sama berlaku untuk semua peran selain
Admin/PPIC: masing-masing hanya bisa membuka menu kerjanya sendiri.

### 5. Halaman login baru

Halaman login (`/api/_admin-login/`) didesain ulang: tema biru gelap,
ikon 📦, maskot FA, kartu "Secure Login / Login Divisi", dan sudah
menyesuaikan layar HP (maskot disembunyikan di layar kecil). Layar
"Siapa Anda?" dan layar setelah login/logout memakai tampilan yang sama.
Cara login dan isinya tidak berubah. Berkas baru:
`api/assets/css/login.css` dan `api/assets/img/fa-mascot.png`.

### 6. Menu "Kelola User" di sidebar Admin

Alat Kelola User yang sudah ada (`/api/_users-uat/`), yang sebelumnya hanya
bisa dibuka lewat URL langsung, sekarang muncul di sidebar Admin (bagian
"Lainnya"). Tetap **khusus Admin**.

---

## Cara pasang (cPanel, tanpa command line)

1. **Backup dulu.** Di cPanel → phpMyAdmin, export (backup) database Anda
   saat ini. Ini WAJIB sebelum migrasi apa pun.
2. **Upload & extract.** Upload `amor-factory-operator-login.zip` ke
   `public_html/factory/`, lalu extract. Ini akan MENIMPA file lama dengan
   versi baru (aman, tidak menghapus folder `api/app/config/`).
3. **Jalankan migrasi.** Buka `https://factory.amorgroup.id/api/_upgrade/`
   di browser (login sebagai Admin dulu jika diminta), lalu jalankan
   migrasi hingga selesai. Migrasi lama akan ditandai "sudah diterapkan
   sebelumnya" dan TIDAK dijalankan ulang.
4. **Buat akun divisi.** Login Admin → sidebar **Kelola User** → buat akun
   baru per divisi, misalnya:
   - Username `divisi_roti`, Nama "Divisi Roti & Bollen", Peran
     **PRODUCTION**, centang akses divisi **Roti & Bollen**.
   - Ulangi untuk setiap divisi (Basic, Donat/Mochi/AKB, Pastry, Bolu,
     dst.).

   **Wajib centang akses divisi.** Akun Produksi tanpa centang divisi
   tidak bisa membuka worksheet divisi mana pun (aturan keamanan yang
   sudah berlaku sejak sebelumnya).
5. **Coba langsung.** Logout, lalu login dengan akun divisi tadi → muncul
   "Siapa Anda?" → ketik nama → masuk ke halaman Produksi → buka divisi →
   isi Actual → Simpan → Submit. Pastikan "Dibuat oleh / Disubmit oleh"
   tampil dengan nama yang diketik.
6. **Cek pembatasan.** Masih login sebagai akun divisi, coba buka
   `.../api/_ui-preview/?page=laporan`. Harus muncul **403 — Akses
   Ditolak**.

---

## Yang TIDAK berubah

- Semua alur PO, Produksi (aturan target/actual/submit/reopen), FG &
  Packing, DO, Pengiriman, Konfirmasi Toko, Portal Bakery, Invoice, Driver
  Portal, dan email otomatis bekerja PERSIS seperti sebelumnya.
- **Reopen** (Buka Kembali) produksi tetap hanya untuk Admin/PPIC.
- Semua alat Admin (`_upgrade`, `_import-po`, `_production-uat`,
  `_fg-uat`, `_do-uat`, `_users-uat`) tetap **khusus ADMIN**. Walaupun
  halaman login kini menerima akun divisi, setiap alat itu tetap memeriksa
  sendiri bahwa yang membuka adalah ADMIN.
- Tidak ada perubahan pada tabel/kolom manapun dari migrasi 0001–0018.

---

## Keamanan

- Nama "Siapa Anda?" adalah **catatan**, bukan password. Siapa pun yang
  memegang akun divisi bisa mengetik nama apa saja. Ini cukup untuk
  menelusuri "siapa yang input", tapi **bukan** bukti identitas. Kalau
  butuh jejak per orang yang tidak bisa dipalsukan, buat akun pribadi per
  orang (sistem sudah mendukung).
- Form "Siapa Anda?" dilindungi token CSRF. Setelah login, sistem hanya
  mau mengarahkan kembali ke halaman di dalam aplikasi (tidak bisa
  dibelokkan ke situs luar).
- Password akun divisi dipakai bersama. **Ganti password lewat Kelola
  User** setiap kali ada staf yang keluar dari divisi tersebut.
