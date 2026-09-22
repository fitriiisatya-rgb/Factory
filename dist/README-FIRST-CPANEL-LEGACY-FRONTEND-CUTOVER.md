# Amor Factory System — Cutover Halaman Utama (factory.amorgroup.id/) ke Amor Factory System Baru — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding.

**Paket ini TIDAK membutuhkan migrasi database.** Ini adalah paket paling
kecil yang pernah dibuat untuk proyek ini — **hanya SATU file**:
`index.php`. Paket ini **TIDAK menyentuh folder `api/` sama sekali** —
semua fitur yang sudah berjalan (Pesanan Toko, Produksi, Pengiriman, dll)
tetap seperti sebelumnya, tidak berubah.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri). File `index.php` dari paket ini harus
diletakkan **LANGSUNG** di `public_html/factory/index.php` — BUKAN di
dalam folder `api/`.

---

## Apa yang berubah

Sebelumnya, ketika seseorang membuka **factory.amorgroup.id/** (alamat
paling utama, tanpa embel-embel apapun di belakangnya), yang muncul
adalah **tampilan lama** (frontend lama, dari zaman sebelum sistem baru
ini dibangun).

**Setelah paket ini dipasang**, membuka **factory.amorgroup.id/** akan
langsung menampilkan **Amor Factory System yang baru** (Dashboard, dengan
tema gelap navy yang sama seperti yang sudah Anda gunakan selama ini di
Pesanan Toko, Produksi, dll) — tidak perlu lagi mengetik alamat khusus.

- Jika belum login, Anda akan diarahkan ke halaman login yang sudah ada
  (perilaku ini **tidak berubah** — sama seperti sebelumnya).
- Setelah login, **factory.amorgroup.id/** langsung menampilkan Dashboard
  Operasional yang baru.
- **Tidak ada tombol atau menu "kembali ke tampilan lama"** — ini adalah
  keputusan yang disengaja dan sudah disetujui. Tampilan lama tidak lagi
  aktif sebagai sistem yang dipakai sehari-hari.

---

## Yang TIDAK berubah

- **Folder `api/` sama sekali tidak disentuh** — semua fitur (Pesanan
  Toko, Pesanan Khusus/Non-Toko, Produksi, FG & Packing, Delivery Order,
  Pengiriman, Konfirmasi Toko, halaman terima barang untuk toko, link
  email otomatis, dll) tetap berjalan **persis seperti sebelumnya**, tidak
  ada satupun logic yang diubah.
- **Tidak ada migrasi database** — tidak ada tabel yang ditambah, diubah,
  atau dihapus.
- Tampilan Admin **tetap memakai tema gelap (dark navy)** yang sama persis
  — halaman utama yang baru ini sebenarnya HANYA "jendela depan" yang
  langsung menampilkan Dashboard yang sudah ada, bukan tampilan baru yang
  dibuat dari nol.

---

## PENTING — Backup wajib sebelum memasang

Karena file `index.php` yang lama akan **ditimpa (overwrite)**, dan file
itu TIDAK pernah dikelola oleh paket manapun dari proyek ini sebelumnya
(artinya tim developer tidak punya salinan pasti dari versi yang sedang
aktif di server Anda sekarang), **langkah backup di bawah ini WAJIB**,
bukan opsional:

### Langkah 1 (WAJIB): Backup `index.php` yang lama secara manual

1. Buka **cPanel → File Manager**.
2. Masuk ke folder `public_html/factory/`.
3. Cari file `index.php` yang ada di situ SEKARANG (bukan yang di dalam
   folder `api/`).
4. Klik kanan → **Rename** (atau **Copy**) file itu menjadi:
   `index.php.LEGACY-BACKUP-SEBELUM-CUTOVER`
5. Pastikan file backup ini tetap ada di folder yang sama — JANGAN
   dihapus. Ini adalah satu-satunya salinan resmi dari tampilan lama yang
   benar-benar aktif di server Anda.

*(Catatan teknis: repository proyek ini juga menyimpan satu salinan
riwayat frontend lama di folder `legacy-frontend-backup/` sebagai
cadangan tambahan, tetapi salinan itu mungkin sudah tidak 100% sama
dengan yang aktif di server Anda sekarang — jadi backup manual di atas
tetap yang paling penting dan wajib dilakukan.)*

---

## Langkah-langkah pemasangan

### 1. Backup (lihat bagian PENTING di atas — WAJIB)

### 2. Upload & Extract

Upload `amor-factory-legacy-frontend-cutover.zip` ke `public_html/factory/`
(folder utama, BUKAN ke dalam `api/`), lalu klik kanan → **Extract**.
Pastikan file `index.php` baru mendarat **langsung** di
`public_html/factory/index.php` (sejajar dengan folder `api/`, bukan di
dalamnya). Jika diminta Timpa/Overwrite, pilih **Ya**.

### 3. Uji di browser

Buka `https://factory.amorgroup.id/` di tab baru (atau hard refresh jika
sudah pernah dibuka sebelumnya):
- Jika belum login: Anda akan diarahkan ke halaman login seperti biasa.
- Login dengan akun ADMIN Anda.
- Setelah login, buka lagi `https://factory.amorgroup.id/` — pastikan
  langsung menampilkan **Dashboard Operasional** dengan tema gelap navy,
  BUKAN tampilan lama.

### 4. Pastikan semua fitur lain masih berjalan normal

Buka menu Pesanan Toko, Produksi, Pengiriman, dll seperti biasa — pastikan
semuanya bekerja persis seperti sebelum paket ini dipasang (karena
folder `api/` memang tidak disentuh sama sekali).

### 5. Cara mundur (rollback) jika ada masalah

1. Hapus `index.php` yang baru (hasil paket ini).
2. Rename kembali `index.php.LEGACY-BACKUP-SEBELUM-CUTOVER` (dari Langkah
   1) menjadi `index.php`.
3. `factory.amorgroup.id/` akan kembali menampilkan tampilan lama seperti
   sebelum paket ini dipasang. Tidak ada dampak ke database atau ke
   folder `api/` sama sekali, jadi rollback ini 100% aman kapan saja.

---

## Risiko & Catatan penting

- **Ini adalah keputusan final yang sudah disetujui** — tampilan lama
  tidak lagi menjadi sistem yang aktif dipakai, dan sengaja tidak
  disediakan tombol "kembali ke tampilan lama" di aplikasi baru.
- **Folder `api/` sama sekali tidak berubah** — nol risiko terhadap fitur
  yang sudah berjalan.
- **Tidak ada perubahan database.**
- Rollback (jika suatu saat dibutuhkan) hanya soal menukar satu file
  `index.php` — sangat aman dan cepat, selama backup Langkah 1 di atas
  benar-benar dilakukan.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ, jangan
hapus file backup, dan simpan tangkapan layarnya — jangan melanjutkan
sebelum masalahnya jelas.
