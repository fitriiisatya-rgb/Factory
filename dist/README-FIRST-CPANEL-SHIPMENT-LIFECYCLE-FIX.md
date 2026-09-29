# Amor Factory System — Perbaikan Konsistensi Alur Pengiriman/Driver/Penerimaan — cPanel, Non-Teknis

**Baca ini SEBELUM upload/extract. Paket ini TIDAK butuh migrasi database
baru — murni perbaikan kode.**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Kenapa paket ini ada

Ditemukan kasus nyata di toko Bakery Abdul Gani: pengiriman/DO masih
muncul di Driver Portal sebagai "Tersedia", Konfirmasi Toko menunjukkan
Status Email = "Belum Dikirim", TAPI Konfirmasi Toko-nya sudah selesai
(Status Penerimaan = Ada Selisih, sudah ada nama & waktu konfirmasi).

Setelah diaudit sampai ke kode sumbernya: **ini BUKAN toko menerima
barang sebelum benar-benar dikirim.** Setiap baris pengiriman (`shipment`)
di sistem ini SELALU dibuat bersamaan dengan bukti nyata "sudah berangkat"
(siapa yang kirim, jam berapa) — tidak ada satupun jalur kode yang bisa
membuat baris pengiriman "palsu"/belum jadi. Penyebab sebenarnya ada 2:

1. Tombol **"Kirim"** manual di halaman Pengiriman (dipakai Admin/PPIC,
   BUKAN lewat Driver Portal) lupa membuat catatan email — jadi Status
   Email tampil "Belum Dikirim" SELAMANYA meskipun barangnya sudah benar-
   benar berangkat. Ini **sudah diperbaiki**.
2. Kalau satu DO dikirim SEBAGIAN lewat tombol manual ini (misalnya baru
   1 dari 2 produk yang dikirim), maka DO itu memang WAJAR masih muncul
   di Driver "Tersedia" — tapi HANYA untuk produk yang BELUM dikirim,
   bukan untuk produk yang SUDAH dikirim & sudah diterima toko. Ini
   perilaku yang sudah benar sejak awal, bukan bug.

Selain itu, sistem sekarang punya kode error yang jelas dan konsisten
(`SHIPMENT_NOT_DISPATCHED`) kalau ada percobaan konfirmasi penerimaan
untuk pengiriman yang memang belum pernah berangkat sama sekali — baik
lewat halaman maupun lewat panggilan API langsung.

---

## Yang PENTING dipahami

- Paket ini **TIDAK ada migrasi database sama sekali** — hanya 4 file
  kode yang berubah (2 file PHP, 1 file JavaScript, 1 file test).
- **Tidak ada satu baris data pun** (PO, Produksi, FG, DO, Pengiriman,
  Konfirmasi Toko, User, dll) yang dihapus atau diubah oleh paket ini.
- **PO, Produksi, FG, Packing, jumlah DO, posting stok, kepemilikan FG
  toko, alokasi Pesanan Khusus, alokasi Replacement, dan Invoice — SAMA
  SEKALI TIDAK berubah.** Paket ini murni memperbaiki konsistensi status
  pengiriman/driver/penerimaan.
- Data historis (termasuk kasus Abdul Gani) **TIDAK disentuh/diubah
  otomatis oleh paket ini** — lihat bagian "Data Historis Abdul Gani" di
  bawah untuk rencana pengecekannya.

---

## Cara pasang (cPanel, tanpa command line)

1. **Backup dulu.** Di cPanel → phpMyAdmin, export (backup) database Anda
   saat ini. Ini WAJIB sebelum upload apa pun (kebiasaan baik meskipun
   paket ini tidak mengubah skema).
2. **Upload & extract.** Upload `amor-factory-shipment-lifecycle-fix.zip`
   ke `public_html/factory/`, lalu extract — ini akan MENIMPA file lama
   dengan versi baru (aman, tidak menghapus folder `api/app/config/`).
3. **TIDAK ADA langkah migrasi** — tidak perlu buka `/_upgrade/` sama
   sekali untuk paket ini.
4. **Health check cepat.**
   - Buka halaman **Pengiriman**, kirim SEBAGIAN item dari satu DO lewat
     tombol "Kirim" manual (bukan lewat Driver Portal). Cek halaman
     **Konfirmasi Toko** — Status Email seharusnya TIDAK lagi diam di
     "Belum Dikirim" (akan berubah sesuai kondisi email toko: Terkirim /
     Gagal / Email Toko Belum Diisi).
   - Buka **Driver Portal → Tersedia** — item yang SUDAH dikirim & sudah
     dikonfirmasi toko TIDAK boleh muncul lagi di sana; item yang BELUM
     dikirim dari DO yang sama BOLEH tetap muncul (ini benar).
   - Coba buka link Konfirmasi Toko untuk sebuah DO yang BELUM PERNAH
     dikirim sama sekali — halaman harus menampilkan "Belum Dikirim —
     konfirmasi penerimaan belum tersedia", TANPA tombol konfirmasi.

---

## Data Historis Abdul Gani — rencana pengecekan (BUKAN perbaikan otomatis)

Paket ini **tidak mengubah data Abdul Gani atau data historis lain**.
Berdasarkan audit kode, kemungkinan besar penjelasannya adalah: DO
tersebut dikirim SEBAGIAN lewat tombol manual "Kirim" (bukan lewat Driver
Portal), sehingga:
- baris produk yang sudah dikirim → sudah benar-benar berangkat & sudah
  benar diterima toko (data ini SAH, jangan dihapus/diubah).
- baris produk lain yang belum dikirim → itu sebabnya DO masih tampak di
  Driver "Tersedia".
- karena dikirim lewat tombol manual (sebelum paket ini), catatan
  email-nya memang tidak pernah dibuat — itulah sebabnya Status Email
  diam di "Belum Dikirim".

**Untuk memastikan**, jalankan query berikut (read-only, tidak mengubah
apa pun) di phpMyAdmin terhadap shipment Abdul Gani yang dimaksud:

```sql
SELECT sh.shipment_id, sh.status, sh.shipped_by, sh.shipped_at,
       o.doc_no, o.status AS do_status,
       e.status AS email_status
FROM shipment sh
LEFT JOIN delivery_order o ON o.delivery_order_id = sh.delivery_order_id
LEFT JOIN shipment_email_delivery e ON e.shipment_id = sh.shipment_id
WHERE sh.store_id = (SELECT store_id FROM store WHERE canonical_name LIKE '%Abdul Gani%')
ORDER BY sh.shipment_id DESC;
```

- Kalau `sh.status = 'active'` dan `sh.shipped_by`/`sh.shipped_at` terisi
  → pengiriman itu SAH, sudah benar-benar berangkat (Konfirmasi Toko-nya
  valid, jangan diubah).
- Kalau `o.do_status` BUKAN `'shipped'` → DO tersebut memang dikirim
  SEBAGIAN (ada produk lain di DO yang sama yang belum dikirim) — itu
  sebabnya masih tampak di Driver "Tersedia", dan ini WAJAR.
- Kalau `e.status` kosong/NULL untuk shipment lama (sebelum paket ini
  di-deploy) → itu memang gap lama yang sekarang sudah diperbaiki untuk
  pengiriman BARU; shipment LAMA tidak akan otomatis dapat email
  retroaktif (silakan pakai tombol "Kirim Ulang Email" di halaman Admin
  Konfirmasi Toko kalau ingin toko tetap menerima link Surat Jalan-nya).

Kalau hasil query di atas menunjukkan sesuatu yang TIDAK sesuai
penjelasan ini (misalnya `sh.shipped_by`/`sh.shipped_at` kosong padahal
statusnya `active`), **STOP — jangan ubah apa pun**, laporkan kembali
hasil query tersebut untuk investigasi lebih lanjut.

---

## Yang TIDAK berubah

- Migrasi 0001–0016 yang sudah tercatat di server Anda — **tidak
  disentuh sama sekali**, dan paket ini tidak menambah migrasi baru.
- Alur PO Reguler, Produksi, FG Verifikasi, Breakdown Toko, Packing per
  Toko, jumlah DO, posting stok, Pesanan Khusus/Non-Toko, Replacement
  Reject, Invoice — bekerja PERSIS seperti sebelumnya.
- Data yang sudah ada — tidak ada satu baris pun yang dihapus atau
  diubah oleh paket ini.

---

## Kalau ada masalah

Kalau setelah extract halaman Pengiriman/Driver Portal/Konfirmasi Toko
menunjukkan sesuatu yang tidak sesuai penjelasan di atas, jangan lakukan
perubahan manual ke database — ambil screenshot dan laporkan kembali
sebelum melanjutkan.
