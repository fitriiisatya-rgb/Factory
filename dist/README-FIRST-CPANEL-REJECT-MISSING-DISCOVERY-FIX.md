# Amor Factory System — Perbaikan "Reject Terverifikasi Hilang" di Replacement Reject — cPanel, Non-Teknis

**Baca ini SEBELUM upload/extract. Paket ini TIDAK butuh migrasi database
baru — murni perbaikan kode + satu file diagnostik.**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Kenapa paket ini ada

Dilaporkan: untuk toko Bakery Abdul Gani, produk BOLLEN LILIT COKLAT —

- **SHP-9** (DO/KRM/007/IX/2026, Dikirim 13, Baik 12, Reject 1) → muncul
  benar di Tindak Lanjut Reject.
- **SHP-4** (DO/KRM/004/IX/2026, Dikirim 2, Reject 1) → muncul benar di
  Tindak Lanjut Reject.
- **SHP-6** (DO/KRM/007/IX/2026, Dikirim 10, Baik 8, Reject 2) → **TIDAK
  MUNCUL** di Tindak Lanjut Reject maupun di Replacement Traceability.

**Setelah diaudit sampai ke kode sumbernya, dibuktikan dengan simulasi
baris data yang benar-benar melewati SQL mentah (bukan lewat aplikasi
sama sekali)**: query yang menemukan baris "menunggu keputusan" TERBUKTI
sudah benar untuk baris apa pun, kapan pun dibuat — kolom status
keputusan (`disposition`) di database TIDAK PERNAH bisa kosong/NULL
(dibuktikan langsung: database MENOLAK percobaan mengosongkannya).

**Kemungkinan penyebab paling besar SHP-6 belum muncul** (dua-duanya
BUKAN bug, ini adalah aturan yang memang sudah ada sebelumnya):

1. Admin **belum menekan tombol "Verifikasi"** untuk Konfirmasi Toko
   pengiriman SHP-6 secara spesifik — setiap pengiriman (SHP-6, SHP-9,
   dst) punya Konfirmasi Toko dan tombol Verifikasi-nya SENDIRI-SENDIRI,
   memverifikasi satu pengiriman TIDAK memverifikasi pengiriman lain.
2. ATAU Konfirmasi Toko SHP-6 memang ada Reject tapi **tidak ada foto
   bukti** sama sekali — sistem MEMANG SENGAJA menolak tombol Verifikasi
   untuk kasus seperti ini (aturan lama: "reject/selisih wajib ada foto
   bukti sebelum bisa diverifikasi").

Paket ini menambahkan **satu file diagnostik** (dijelaskan di bawah)
supaya Anda bisa memastikan sendiri mana dari dua kemungkinan di atas
yang sebenarnya terjadi untuk SHP-6 — tanpa menebak.

---

## Yang PENTING dipahami

- Paket ini **TIDAK ada migrasi database sama sekali**.
- **Tidak ada satu baris data pun** yang dihapus atau diubah oleh paket
  ini sendiri.
- **Qty reject, verifikasi Konfirmasi Toko, aturan wajib-foto-bukti,
  stok, FG, Produksi, jumlah DO, Invoice, dan rumus alokasi Replacement —
  SAMA SEKALI TIDAK berubah.**
- Perubahan kode di paket ini murni **jaring pengaman tambahan** (query
  sekarang juga menoleransi nilai kosong pada kolom keputusan, meskipun
  terbukti kolom itu tidak pernah bisa kosong) — tidak ada perilaku baru
  yang terlihat oleh user biasa.

---

## File diagnostik BARU (bukan bagian dari aplikasi — jalankan manual di phpMyAdmin)

`dist/diagnostics/reject-missing-shp-check.sql` — **hanya berisi SELECT,
TIDAK PERNAH menulis/mengubah apa pun**, aman dijalankan berkali-kali.

**Cara pakai:**

1. Buka **cPanel → phpMyAdmin**, pilih database Amor Factory Anda, buka
   tab **SQL**.
2. Buka file `reject-missing-shp-check.sql` (disertakan terpisah, bukan
   di dalam ZIP aplikasi), salin isinya, tempel di tab SQL phpMyAdmin.
3. Di baris paling atas, ganti angka `6` pada `SET @shipment_id = 6;`
   dengan nomor shipment yang benar (angka setelah "SHP-" di halaman
   Admin, misalnya untuk SHP-6 isi `6`).
4. Jalankan (Execute). Lihat hasil query **terakhir** (kolom bernama
   `diagnosis`) — akan menjelaskan dalam bahasa biasa persis kenapa
   pengiriman tersebut muncul atau tidak muncul di Tindak Lanjut Reject.

---

## Cara pasang (cPanel, tanpa command line)

1. **Backup dulu.** Di cPanel → phpMyAdmin, export (backup) database Anda
   saat ini (kebiasaan baik meskipun paket ini tidak mengubah skema).
2. **Upload & extract.** Upload
   `amor-factory-reject-missing-discovery-fix.zip` ke
   `public_html/factory/`, lalu extract — ini akan MENIMPA file lama
   dengan versi baru (aman, tidak menghapus folder `api/app/config/`).
3. **TIDAK ADA langkah migrasi** — tidak perlu buka `/_upgrade/` sama
   sekali untuk paket ini.
4. **Jalankan file diagnostik** (lihat bagian di atas) untuk SHP-6 dan
   catat hasilnya.

---

## Live UAT lanjutan yang disarankan

- Jalankan file diagnostik untuk SHP-6 dan baca kolom `diagnosis`.
- Kalau hasilnya **"BLOCKED: ... ZERO evidence photos"** → minta toko
  membuka kembali link Konfirmasi Toko untuk SHP-6 dan unggah foto bukti
  (atau tanyakan ke toko apa yang terjadi saat konfirmasi pertama kali).
- Kalau hasilnya **"NOT YET VERIFIED"** → buka halaman **Konfirmasi
  Toko** di Admin, cari SHP-6, tekan **Verifikasi** — setelah itu baris
  akan langsung muncul di Tindak Lanjut Reject.
- Kalau hasilnya **"SHOULD BE PENDING"** (semua syarat terpenuhi tapi
  tetap tidak muncul di UI) → ini kasus yang belum pernah terjadi di
  semua pengujian kami; laporkan kembali hasil diagnostik LENGKAP
  (semua 6 blok query, bukan cuma yang terakhir) untuk investigasi lebih
  lanjut.

---

## Yang TIDAK berubah

- Migrasi 0001–0016 yang sudah tercatat di server Anda — **tidak
  disentuh sama sekali**, dan paket ini tidak menambah migrasi baru.
- Alur PO Reguler, Produksi, FG Verifikasi, Breakdown Toko, Packing per
  Toko, jumlah DO, posting stok, Pengiriman/Driver/Konfirmasi Toko
  (termasuk aturan wajib foto bukti), Pesanan Khusus/Non-Toko, Invoice —
  bekerja PERSIS seperti sebelumnya.
- Rumus alokasi FG Replacement — tidak disentuh.
- Data yang sudah ada — tidak ada satu baris pun yang dihapus atau
  diubah oleh paket ini.

---

## Kalau ada masalah

Kalau setelah menjalankan diagnostik dan mengikuti langkah di atas
SHP-6 tetap tidak muncul, jangan lakukan perubahan manual ke database —
salin hasil LENGKAP dari file diagnostik dan laporkan kembali sebelum
melanjutkan.
