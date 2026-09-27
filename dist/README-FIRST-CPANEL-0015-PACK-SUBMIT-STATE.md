# Amor Factory System — Migration 0015: Status Submit Packing Nyata — cPanel, Non-Teknis

**Baca ini SEBELUM upload/extract. Paket ini BERBEDA dari hotfix-hotfix
sebelumnya — paket ini BUTUH menjalankan satu migrasi database baru.**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Kenapa paket ini ada

Pada hotfix sebelumnya, status "✓ Sudah Disubmit" di halaman **FG
Packing** ditebak dari angka: kalau qty yang sudah dipacking sudah sama
dengan target toko, dianggap "sudah disubmit". Setelah dicek lebih
dalam, cara ini **salah secara logika**: seorang operator BISA benar-benar
menekan "Submit Packing" walau qty yang dipacking masih di bawah target
(karena memilih "Tidak Sesuai" + catatan alasan), atau bahkan qty = 0
(misalnya belum ada barang yang siap, tapi submit dengan catatan). Dalam
kedua kasus itu, submit-nya **sungguhan terjadi**, tapi sistem lama tetap
menampilkan status seolah "belum selesai" karena hanya melihat angka.

Paket ini menambahkan **satu tabel baru** di database yang benar-benar
mencatat: "Packing untuk Toko X sudah pernah disubmit, oleh siapa, dan
kapan" — terpisah dari angka qty yang mana pun.

---

## Yang PENTING dipahami

- Migrasi 0015 **HANYA menambah SATU tabel baru**
  (`fg_store_packing_submission`). Tidak ada tabel lama yang diubah, dan
  **tidak ada satu baris data pun** (PO, Produksi, FG, DO, Pengiriman,
  User, dll) yang dihapus atau diubah oleh migrasi ini.
- Migrasi 0015 **aman dijalankan** — memakai `CREATE TABLE IF NOT
  EXISTS`, jadi kalau tabel ini entah bagaimana sudah ada, migrasi ini
  tidak akan error.
- **Tidak ada logika bisnis lain yang berubah**: aturan stok, pengiriman,
  reservasi Pesanan Khusus, aturan Produksi — semuanya persis seperti
  sebelumnya.
- Satu perbaikan kecil tambahan disertakan: pada halaman **Delivery
  Order**, ada satu kasus langka (PO Revisi yang menurunkan target
  sampai tepat 0, walau PO Awal-nya dulu positif) yang sebelumnya bisa
  membuat baris produk dengan qty 0 masih muncul — sekarang ditangani
  dengan benar juga. Ini murni perbaikan tampilan, bukan perubahan rumus
  target.

---

## Cara pasang (cPanel, tanpa command line)

1. **Backup dulu.** Di cPanel → phpMyAdmin, export (backup) database Anda
   saat ini. Ini WAJIB sebelum migrasi apa pun.
2. **Upload & extract.** Upload `amor-factory-pack-submit-state.zip` ke
   `public_html/factory/`, lalu extract — ini akan MENIMPA file lama
   dengan versi baru (aman, tidak menghapus folder `api/app/config/`).
3. **Buka halaman Upgrade Database.** Buka
   `https://domainanda.com/factory/api/_upgrade/` di browser (login
   sebagai Admin dulu jika diminta).
4. **VERIFIKASI PENTING sebelum menekan apa pun**: pastikan halaman
   tersebut menunjukkan:
   - "Sudah diterapkan" masih mencantumkan
     `0014_production_fg_division_rework.php` (TIDAK hilang, TIDAK
     berubah).
   - "Menunggu diterapkan" **HANYA** berisi SATU baris:
     `0015_fg_store_packing_submission.php`.

   Kalau yang muncul BUKAN seperti ini, **STOP** — jangan tekan apa pun,
   laporkan kembali screenshot halaman tersebut.
5. **Terapkan.** Centang kotak konfirmasi, lalu tekan tombol "Terapkan
   Migrasi". Halaman akan menampilkan "Migrasi berhasil diterapkan:
   0015_fg_store_packing_submission.php".
6. **Verifikasi migrasi 0015 sudah tercatat.** Muat ulang halaman
   `/_upgrade/` — sekarang "Sudah diterapkan" harus mencantumkan 0014
   MAUPUN 0015, dan "Menunggu diterapkan" harus kosong.
7. **Health check cepat.** Buka halaman **FG & Packing** untuk tanggal
   dan pabrik yang biasa dipakai — pastikan tampilannya normal seperti
   sebelumnya.
8. **UAT nyata — status submit Packing.**
   - Buka **FG Packing**, pilih satu toko yang belum pernah disubmit.
     Pastikan chip toko menunjukkan **"Belum Mulai"** (bukan "Sudah
     Disubmit").
   - Isi Actual Packing (boleh sengaja DI BAWAH target toko, pilih
     "Tidak Sesuai", isi catatan alasan), lalu tekan **"Submit Packing
     [Nama Toko]"**.
   - Setelah berhasil, chip toko tersebut HARUS langsung berubah jadi
     **"✓ Sudah Disubmit"** (warna hijau) — walau qty yang dipacking
     lebih kecil dari target.
   - **Muat ulang halaman (refresh browser)** — status "✓ Sudah
     Disubmit" harus TETAP tampil (bukti tersimpan di database, bukan
     hanya di layar).
   - Kalau memungkinkan, buka halaman yang sama dari HP/browser lain
     yang login sebagai user berbeda — toko yang sama harus tetap
     terlihat "✓ Sudah Disubmit" di sana juga.
   - Kembali ke **FG Verifikasi → Breakdown Toko**, ubah salah satu
     angka Reject/Hilang/Keterangan untuk toko yang tadi sudah disubmit,
     lalu Simpan. Kembali ke **FG Packing** — toko tersebut sekarang
     harus menunjukkan **"Perlu Submit Ulang"** (bukan lagi "Sudah
     Disubmit"). Tekan tombol submit lagi untuk mengembalikannya ke
     "✓ Sudah Disubmit".

---

## Yang TIDAK berubah

- Migrasi 0001–0014 yang sudah tercatat di server Anda — **tidak
  disentuh sama sekali**.
- Alur PO Reguler, Produksi, FG Verifikasi, Breakdown Toko, Reject/
  Hilang, alur DO, Pengiriman, Driver Portal, Pesanan Khusus/Non-Toko —
  bekerja PERSIS seperti sebelumnya.
- Tombol terpisah **"Submit FG (Semua Toko)"** (yang benar-benar
  memposting stok) — TIDAK berubah sama sekali, dan tidak pernah
  terpengaruh oleh submit Packing per-toko yang baru ini. Submit Packing
  per-toko TIDAK PERNAH memposting stok — itu tetap hanya tugas tombol
  "Submit FG (Semua Toko)".
- Data yang sudah ada — tidak ada satu baris pun yang dihapus atau
  diubah oleh migrasi ini (murni `CREATE TABLE` baru, tanpa `INSERT`/
  `UPDATE`/`DELETE` terhadap data bisnis apa pun).

---

## Kalau ada masalah

Kalau setelah menjalankan langkah di atas halaman `/_upgrade/`
menunjukkan sesuatu yang TIDAK sesuai dengan langkah 4 (misalnya migrasi
lain selain 0015 muncul di "Menunggu diterapkan"), **JANGAN tekan
"Terapkan Migrasi"** — ambil screenshot dan laporkan kembali sebelum
melanjutkan.
