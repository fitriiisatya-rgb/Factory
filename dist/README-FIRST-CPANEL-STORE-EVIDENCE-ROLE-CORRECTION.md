# Amor Factory System — Koreksi Peran Bukti Foto (Toko Mengunggah, Admin Hanya Meninjau) — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Paket ini HANYA berisi
file — tidak ada migrasi database sama sekali** (migrasi 0008 sudah
terpasang dari patch sebelumnya dan TIDAK berubah).

**Baca ini setelah patch "Bukti Foto Konfirmasi Toko + Detail Admin +
Verifikasi Selisih" sudah berjalan di server Anda.** Paket ini
memperbaiki temuan real-UAT: halaman Detail Admin sebelumnya punya
tombol **"Unggah Bukti Foto"** milik Admin sendiri — ini SALAH secara
bisnis dan sudah dihapus total.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Aturan bisnis yang dikoreksi

**BUKTI FOTO ADALAH BUKTI DARI TOKO.**

Hanya Toko (lewat portal QR Store Receipt publik) yang boleh mengunggah
bukti foto. Peran Admin sekarang murni:

- menerima/melihat data konfirmasi penerimaan,
- melihat bukti foto yang diunggah Toko,
- memeriksa selisih,
- memverifikasi selisih.

**Admin TIDAK BOLEH mengunggah bukti foto atas nama Toko.**

---

## Apa yang dihapus dari tampilan Admin

- Tombol **"Unggah Bukti Foto"** dan kotak pilih file di halaman Detail
  Admin — **DIHAPUS TOTAL**, bukan hanya disembunyikan.
- Jalur teknis `POST /api/admin/receipts/{id}/evidence` yang dulu
  dipakai tombol tersebut — **DIHAPUS TOTAL** dari server. Jika ada yang
  mencoba memanggilnya langsung, server akan menjawab "tidak ditemukan"
  (404), persis seperti alamat yang memang tidak pernah ada.

Halaman Detail Admin sekarang hanya menampilkan:

- **Ada bukti foto dari Toko** → judul "Bukti Foto dari Toko" + thumbnail
  yang bisa diklik untuk diperbesar. Admin hanya bisa MELIHAT.
- **Tidak ada bukti foto** → "Bukti Foto: Tidak tersedia" (dan jika ini
  konfirmasi lama sebelum aturan berlaku, ditambah keterangan "konfirmasi
  dibuat sebelum aturan bukti foto diberlakukan").

Jika selisih (Reject/Kurang) belum punya bukti foto dari Toko, tombol
**Verifikasi Selisih** tetap nonaktif dengan pesan: *"Selisih belum dapat
diverifikasi karena bukti foto dari toko belum tersedia."*

---

## Tentang data lama (mis. SHP-3 real UAT)

Konfirmasi lama yang sudah ada SEBELUM aturan bukti foto diberlakukan
(reject/kurang tanpa foto) **TIDAK diubah, TIDAK dihapus, dan TIDAK
diperbaiki**. Statusnya tetap "Ada Selisih", bukti fotonya tetap "Tidak
tersedia", dan verifikasinya **tetap terkunci permanen** — tidak ada lagi
cara dari sisi Admin untuk membuka kuncinya (jalur upload Admin yang dulu
ada sudah dihapus).

Baris data lama ini sengaja **dibiarkan sebagai bukti sejarah** bahwa
aturan bukti foto belum berlaku saat itu. **Tidak perlu memperbaiki SHP-3
atau konfirmasi lama sejenisnya.** Untuk menguji alur bukti foto yang
baru/benar, gunakan pengiriman BARU yang dikonfirmasi Toko lewat portal QR
seperti biasa.

---

## Yang TIDAK berubah (tetap sama seperti sebelumnya)

- Alur Toko: scan QR → isi Diterima Baik/Reject/Kurang → jika ada selisih,
  foto WAJIB diunggah Toko sebelum submit → toko yang mengunggah, bukan
  siapa pun selain toko.
- Validasi foto (format gambar asli, maksimal 5 MB, maksimal 3 foto,
  nama file diacak, penyimpanan aman) — semua tetap sama.
- Admin tetap bisa MELIHAT bukti foto Toko dan memverifikasi selisih
  (untuk konfirmasi yang memang sudah punya bukti foto dari Toko).
- Tidak ada perubahan pada perhitungan stok, klaim Driver, token QR, atau
  aturan bisnis DO/FG/Production/PO.
- Tidak ada migrasi database baru — migrasi 0008 yang sudah terpasang
  tetap seperti semula.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- File `amor-factory-store-evidence-role-correction.zip` (dikirim
  bersama panduan ini).

**Tidak perlu** kredensial migrasi database — paket ini tidak mengubah
struktur database sama sekali.

---

## Langkah-langkah

### 1. Backup (tetap disarankan, walau paket ini hanya file)

cPanel → Backup Wizard → backup folder `public_html/factory/`.

### 2. Upload & Extract (dengan Timpa/Overwrite)

Upload `amor-factory-store-evidence-role-correction.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Jika ditanya
"timpa atau lewati", selalu pilih **Timpa/Overwrite**. `config.php` Anda
TIDAK ikut ditimpa (tidak ada di dalam paket ini).

### 3. Tidak ada migrasi

Paket ini TIDAK memerlukan langkah migrasi apa pun — langsung ke langkah
berikutnya.

### 4. Hard refresh

Buka halaman Admin di browser, lalu lakukan **hard refresh** (tutup tab
lalu buka lagi) supaya file lama yang ter-cache tidak dipakai.

### 5. Verifikasi tombol Unggah Bukti Foto sudah hilang dari Admin

Login sebagai Admin → **Konfirmasi Toko** → buka Detail salah satu
konfirmasi lama yang punya selisih tapi belum ada foto (mis. SHP-3).
Pastikan halaman HANYA menampilkan "Bukti Foto: Tidak tersedia" — **TIDAK
ADA** tombol atau kotak pilih file untuk mengunggah apa pun. Tombol
Verifikasi Selisih harus tetap nonaktif dengan pesan yang menyebut "bukti
foto dari toko belum tersedia".

### 6. Uji alur BARU dari sisi Toko (bukan memperbaiki SHP-3)

Buat pengiriman baru (atau gunakan yang sudah ada tapi belum
dikonfirmasi), scan QR-nya, isi Reject atau Kurang > 0, lampirkan foto
dari HP, submit. Pastikan berhasil seperti biasa.

### 7. Uji Admin melihat & memverifikasi bukti foto BARU tersebut

Buka Detail konfirmasi yang baru saja dibuat di langkah 6 — pastikan
judul **"Bukti Foto dari Toko"** muncul beserta thumbnail-nya, lalu klik
**Verifikasi Selisih** dan pastikan berhasil menjadi "Diverifikasi
Admin".

### 8. Cara mundur (rollback) jika ada masalah

Karena paket ini hanya mengubah file kode (tidak ada perubahan database),
cara mundur paling aman adalah mengembalikan file-file yang tertimpa dari
backup langkah 1. Tidak ada data yang perlu dibersihkan di database.

---

## Risiko & Catatan penting

- **Tidak ada perubahan pada perhitungan stok, logika klaim Driver,
  token QR, atau aturan bisnis DO/FG/Production/PO** — paket ini murni
  mengoreksi siapa yang boleh mengunggah bukti foto.
- **Konfirmasi lama seperti SHP-3 sengaja dibiarkan tidak terverifikasi
  selamanya** — ini keputusan yang disengaja, bukan bug. Jika toko benar-
  benar perlu memberikan bukti foto untuk kasus lama tersebut, satu-
  satunya jalur yang benar adalah Toko mengonfirmasi ulang lewat
  pengiriman/DO yang baru — bukan menambal data lama.
- **Migrasi database 0008 tidak berubah** — tidak perlu membuka halaman
  `_upgrade/` sama sekali untuk paket ini.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
