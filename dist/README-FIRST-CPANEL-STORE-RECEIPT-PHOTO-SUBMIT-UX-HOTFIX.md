# Amor Factory System — Perbaikan Foto Bukti Kebesaran + Tombol Simpan Macet (Halaman Konfirmasi Toko) — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Paket ini HANYA berisi
file — tidak ada migrasi database sama sekali** (migrasi 0009 sudah
terpasang dari patch sebelumnya dan TIDAK berubah).

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Masalah yang dilaporkan dari real-UAT cPanel

1. Setelah Toko mengunggah foto bukti di halaman Konfirmasi Penerimaan
   Barang (HP), pratinjau (preview) foto tersebut tampil **sangat besar**
   dan memenuhi sebagian besar layar.
2. Tombol **"Simpan Konfirmasi"** kadang tetap **nonaktif/tidak bisa
   diklik** walau data sudah benar.

---

## Penyebab sebenarnya yang ditemukan (bukan dugaan — sudah diuji langsung)

- **Tombol macet**: saat Toko menghapus foto bukti yang baru saja
  dipilih (menekan tombol "×" pada thumbnail), sistem lupa mengecek ulang
  apakah data masih valid. Akibatnya, jika itu adalah foto TERAKHIR yang
  dihapus pada pengiriman yang punya Reject/Kurang, tombol seharusnya
  kembali nonaktif (karena foto wajib untuk selisih) — tapi tombol malah
  tetap dalam kondisi terakhir sebelum foto dihapus. Ini sudah diperbaiki:
  setiap kali daftar foto berubah (ditambah ATAU dihapus), sistem sekarang
  SELALU mengecek ulang kondisi tombol.
- **Kemungkinan penyebab foto tampil besar**: pratinjau foto di kode yang
  berjalan di server sebenarnya sudah dibatasi ukurannya, dan tidak bisa
  direproduksi ulang di pengujian. Kemungkinan besar ini adalah file lama
  (CSS/JS) yang masih tersimpan di cache browser HP. Untuk berjaga-jaga,
  paket ini **memperkuat batasan ukuran pratinjau foto** (grid rapi,
  ukuran tetap kecil, bisa diketuk untuk diperbesar) **dan** menambahkan
  penanda versi pada file CSS/JS supaya HP selalu mengambil file TERBARU,
  bukan file lama dari cache.
- Ditemukan juga satu potensi gangguan teknis di beberapa HP (terutama
  iPhone/Safari): kombinasi pengaturan yang memaksa kamera langsung
  terbuka bersamaan dengan mode pilih-banyak-foto bisa membuat kotak
  pilih file berperilaku aneh. Pengaturan ini sudah dilonggarkan — HP
  tetap menawarkan opsi "Ambil Foto" lewat menu pilihan bawaan, hanya
  tidak lagi dipaksa membuka kamera secara langsung.

---

## Yang TIDAK berubah (tetap sama seperti sebelumnya)

- **Aturan bisnis Toko**: Diterima Baik + Reject + Kurang harus sama
  dengan jumlah Dikirim.
- **Foto bukti tetap WAJIB** jika ada Reject > 0 atau Kurang > 0, minimal
  1 foto yang valid.
- **Foto bukti tetap hanya boleh diunggah oleh Toko** — Admin tetap hanya
  bisa melihat dan memverifikasi, tidak pernah mengunggah.
- Semua pemeriksaan di server (bukan hanya di HP) tetap sama persis:
  server tetap menolak data yang salah hitung, menolak selisih tanpa
  foto, menolak file yang bukan gambar, menolak file yang kelewat besar.
  Tombol di HP hanyalah kenyamanan tampilan — server tetap menjadi
  penjaga akhir yang sebenarnya.
- Tidak ada perubahan pada perhitungan stok, klaim Driver, token QR,
  verifikasi Admin, atau aturan bisnis DO/FG/Production/PO.
- Tidak ada migrasi database baru — migrasi 0009 yang sudah terpasang
  tetap seperti semula.
- Kartu riwayat pengiriman lama (yang sudah dikonfirmasi sebelumnya,
  mis. contoh SHP-2/SHP-3 di real-UAT) **tidak disentuh sama sekali** —
  tetap tampil apa adanya, tidak bisa diedit ulang.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- File `amor-factory-store-receipt-photo-submit-ux-hotfix.zip` (dikirim
  bersama panduan ini).

**Tidak perlu** kredensial migrasi database — paket ini tidak mengubah
struktur database sama sekali.

---

## Langkah-langkah

### 1. Backup (tetap disarankan, walau paket ini hanya file)

cPanel → Backup Wizard → backup folder `public_html/factory/`.

### 2. Upload & Extract (dengan Timpa/Overwrite)

Upload `amor-factory-store-receipt-photo-submit-ux-hotfix.zip` ke
`public_html/factory/`, lalu klik kanan → **Extract**. Jika ditanya
"timpa atau lewati", selalu pilih **Timpa/Overwrite**. `config.php` Anda
TIDAK ikut ditimpa (tidak ada di dalam paket ini).

### 3. Tidak ada migrasi

Paket ini TIDAK memerlukan langkah migrasi apa pun — langsung ke langkah
berikutnya.

### 4. Hard refresh / bersihkan cache di HP

Buka halaman Konfirmasi Penerimaan Barang (link QR) di HP, lalu **tutup
tab sepenuhnya dan buka lagi** (atau bersihkan cache browser HP jika
memungkinkan). Paket ini menambahkan penanda versi baru pada file
CSS/JS-nya, jadi seharusnya file terbaru otomatis terambil — tapi hard
refresh tetap disarankan untuk memastikan.

### 5. Uji pengiriman yang PUNYA selisih (Reject atau Kurang > 0)

Scan QR pengiriman yang masih "Menunggu Konfirmasi", isi Reject atau
Kurang lebih dari 0. Pastikan:

- Pratinjau foto setelah diunggah berukuran **kecil dan rapi**, tidak
  memenuhi layar.
- Tombol **Simpan Konfirmasi** otomatis **nonaktif** sebelum ada foto,
  lalu otomatis **aktif** begitu 1 foto valid dipilih.
- Jika foto tersebut dihapus lagi (tekan "×"), tombol **langsung kembali
  nonaktif**.
- Pilih foto lagi, submit, pastikan berhasil tersimpan seperti biasa.

### 6. Uji pengiriman yang BERSIH (tanpa selisih)

Scan QR pengiriman lain yang totalnya pas (Diterima Baik = Dikirim,
Reject = 0, Kurang = 0). Pastikan tombol **Simpan Konfirmasi** langsung
aktif tanpa perlu foto sama sekali, dan submit berhasil.

### 7. Cara mundur (rollback) jika ada masalah

Karena paket ini hanya mengubah file kode (tidak ada perubahan database),
cara mundur paling aman adalah mengembalikan file-file yang tertimpa dari
backup langkah 1. Tidak ada data yang perlu dibersihkan di database.

---

## Risiko & Catatan penting

- **Tidak ada perubahan pada perhitungan stok, klaim Driver, token QR,
  atau aturan bisnis DO/FG/Production/PO** — paket ini murni memperbaiki
  tampilan pratinjau foto dan kondisi tombol Simpan Konfirmasi.
- **Server tetap menjadi penjaga akhir** — walau tombol di HP sekarang
  lebih akurat, server akan selalu tetap menolak data yang sebenarnya
  tidak valid, apa pun yang ditampilkan di layar HP.
- **Migrasi database 0009 tidak berubah** — tidak perlu membuka halaman
  `_upgrade/` sama sekali untuk paket ini.

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
