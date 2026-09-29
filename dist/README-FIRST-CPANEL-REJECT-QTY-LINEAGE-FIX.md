# Amor Factory System — Perbaikan Kejelasan Qty Reject di Replacement Reject — cPanel, Non-Teknis

**Baca ini SEBELUM upload/extract. Paket ini TIDAK butuh migrasi database
baru — murni perbaikan kode.**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Kenapa paket ini ada

Dilaporkan: untuk DO/KRM/007/IX/2026 (Bakery Abdul Gani, produk BOLLEN
LILIT COKLAT), ada DUA pengiriman terpisah untuk DO yang sama:

- Pengiriman A: Dikirim 13, Baik 12, **Reject 1**
- Pengiriman B: Dikirim 10, Baik 8, **Reject 2**

Tapi di halaman **Replacement Reject → Tindak Lanjut Reject**, KEDUA
baris sama-sama menunjukkan "Reject Dilaporkan = 1" — baris Pengiriman B
seharusnya menunjukkan 2.

**Setelah diaudit sampai ke kode sumbernya dan dibuktikan dengan skenario
persis seperti ini (dua pengiriman sebagian untuk satu DO yang sama)**:
angka reject yang tersimpan dan dibaca sistem **SUDAH BENAR** di setiap
tahap — tidak ada pembulatan, tidak ada default 1, tidak ada
penggabungan baris. Yang sebenarnya hilang adalah: **halaman Tindak
Lanjut Reject tidak menunjukkan pengiriman/DO mana asal setiap baris**,
sehingga dua baris untuk toko+produk yang sama jadi sulit dibedakan
sekilas mata — inilah yang menyebabkan kebingungan tersebut.

---

## Yang PENTING dipahami

- Paket ini **TIDAK ada migrasi database sama sekali** — hanya 3 file
  kode (2 PHP, 1 halaman UI) dan 1 file test yang berubah.
- **Tidak ada satu baris data pun** yang dihapus atau diubah.
- **Qty reject itu sendiri, konfirmasi Toko, stok, FG, Produksi, jumlah
  DO, Invoice, dan rumus alokasi Replacement — SAMA SEKALI TIDAK
  berubah.** Paket ini murni menambah kejelasan tampilan (Toko + asal
  pengiriman) di worklist Tindak Lanjut Reject.
- Kolom qty keputusan (Kirim Ulang / Reject Final) **tetap** default dan
  dibatasi ke jumlah reject yang DILAPORKAN penuh — tidak ada fitur
  approval-qty sebagian yang ditambahkan di paket ini.

---

## Apa yang berubah di halaman Tindak Lanjut Reject

Tabel "Tindak Lanjut Reject" sekarang punya 2 kolom baru:

| Toko | **No. DO** | **Shipment #** | Produk | Dikirim | Reject Dilaporkan | Catatan Toko | Keputusan |
|---|---|---|---|---|---|---|---|

Sehingga untuk kasus di atas, sekarang akan terlihat jelas:

| Toko | No. DO | Shipment # | Produk | Dikirim | Reject Dilaporkan |
|---|---|---|---|---|---|
| Bakery Abdul Gani | DO/KRM/007/IX/2026 | SHP-6 | BOLLEN LILIT COKLAT | 13 | **1** |
| Bakery Abdul Gani | DO/KRM/007/IX/2026 | SHP-9 | BOLLEN LILIT COKLAT | 10 | **2** |

Dua baris, dua Shipment # yang berbeda, dua angka reject yang benar dan
berbeda — tidak pernah digabung atau tertukar.

---

## Cara pasang (cPanel, tanpa command line)

1. **Backup dulu.** Di cPanel → phpMyAdmin, export (backup) database Anda
   saat ini (kebiasaan baik meskipun paket ini tidak mengubah skema).
2. **Upload & extract.** Upload `amor-factory-reject-qty-lineage-fix.zip`
   ke `public_html/factory/`, lalu extract — ini akan MENIMPA file lama
   dengan versi baru (aman, tidak menghapus folder `api/app/config/`).
3. **TIDAK ADA langkah migrasi** — tidak perlu buka `/_upgrade/` sama
   sekali untuk paket ini.
4. **Health check cepat.** Buka halaman **Replacement Reject → Tindak
   Lanjut Reject** — pastikan kolom **"No. DO"** dan **"Shipment #"**
   sudah muncul di tabel, dan setiap baris menunjukkan angka Reject
   Dilaporkan yang SESUAI dengan Konfirmasi Toko-nya masing-masing.

---

## UAT lanjutan yang disarankan

Kalau memungkinkan, silakan cek ulang DO/KRM/007/IX/2026 yang dilaporkan:

- Buka **Replacement Reject → Tindak Lanjut Reject**, cari kedua baris
  untuk toko Abdul Gani / BOLLEN LILIT COKLAT.
- Pastikan sekarang terlihat **DUA baris berbeda** dengan **Shipment #**
  berbeda, dan Reject Dilaporkan **1** untuk pengiriman pertama, **2**
  untuk pengiriman kedua.
- Kalau salah satu baris SUDAH TERLANJUR diputuskan (Reject Final /
  Kirim Ulang) dengan qty yang salah SEBELUM paket ini dipasang, paket
  ini **TIDAK mengubah keputusan yang sudah final itu secara otomatis**
  — silakan laporkan detailnya (ID baris/DO/Shipment) untuk pengecekan
  lebih lanjut, jangan diubah manual di database.

---

## Yang TIDAK berubah

- Migrasi 0001–0016 yang sudah tercatat di server Anda — **tidak
  disentuh sama sekali**, dan paket ini tidak menambah migrasi baru.
- Alur PO Reguler, Produksi, FG Verifikasi, Breakdown Toko, Packing per
  Toko, jumlah DO, posting stok, Pengiriman/Driver/Konfirmasi Toko,
  Pesanan Khusus/Non-Toko, Invoice — bekerja PERSIS seperti sebelumnya.
- Rumus alokasi FG Replacement (cek FG bebas dulu, baru Perlu Produksi)
  — tidak disentuh.
- Data yang sudah ada — tidak ada satu baris pun yang dihapus atau
  diubah oleh paket ini.

---

## Kalau ada masalah

Kalau setelah extract halaman Tindak Lanjut Reject menunjukkan sesuatu
yang tidak sesuai penjelasan di atas, jangan lakukan perubahan manual ke
database — ambil screenshot dan laporkan kembali sebelum melanjutkan.
