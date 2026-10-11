# Amor Factory System — Penyempurnaan Alur Hulu ke Hilir — cPanel, Non-Teknis

**Baca ini sebelum memasang.** Paket ini adalah perbaikan menyeluruh hasil uji
coba seluruh alur, dari upload PO sampai laporan omset, memakai akun Admin,
Divisi, FG, Driver, dan Toko.

**Paket ini TIDAK membutuhkan migrasi database baru.** Server yang sudah di
migrasi 0019 cukup upload, lalu extract. Kalau server masih di bawah 0019,
buka `/api/_upgrade/` setelah extract (migrasi yang belum ada akan dijalankan).

**Path yang BENAR**: `public_html/factory/`.

---

## Yang diperbaiki dan ditambahkan

### Alur kerja
- **Akun FG bisa login.** Sebelumnya akun FG ditolak di halaman login. Sekarang
  akun FG langsung masuk ke menu FG & Packing (hanya menu itu).
- **FG: Sesuai → langsung Packing.** Setelah FG menekan "Sesuai", sistem
  otomatis membagi hasil ke tiap toko sesuai PO, lalu tombol
  **"Lanjut ke Packing"** membawa FG ke tahap packing per toko. Sebelumnya FG
  bisa tersangkut: produk terkunci di Packing dan stok masuk 0.
- **Submit FG ditahan** sampai semua toko selesai dipacking, supaya stok siap
  kirim tidak pernah kurang dari yang sudah diverifikasi.
- **Kekurangan produksi dicatat otomatis.** Jika hasil produksi kurang dari PO
  toko, keterangan terisi sendiri (contoh: "Sesuai hasil produksi (5); target
  PO toko 6").
- **Status DO "Sebagian Dikirim"** muncul jika sebagian barang sudah berangkat
  (sebelumnya tetap tertulis "Draft").

### Portal Bakery
- Pilihan produk di **Retur** dan **Mutasi** hanya berisi produk yang pernah
  diterima toko itu (sebelumnya seluruh katalog ±470 produk).
- Tujuan Mutasi tidak lagi menampilkan "NON-OUTLET / PERORANGAN".
- Jam berangkat tampil dalam WIB.

### Admin
- **Alasan Retur** tampil di daftar Retur (sebelumnya kosong).
- **Mutasi** langsung terlihat begitu toko mengajukan (sebelumnya tersembunyi
  di filter "Selisih").
- **Catatan Toko** tampil di Tindak Lanjut Reject; kolom **Divisi** terisi di
  detail Konfirmasi Toko.
- **Pesan error dalam Bahasa Indonesia** yang bisa dipahami (contoh: "Stok siap
  kirim untuk toko ini tidak cukup: diminta 6, siap 5."), bukan pesan teknis
  berbahasa Inggris.
- **Aktivitas Terbaru** di Dashboard tertulis jelas ("Invoice dibuat · Admin ·
  12 Okt 2026 · 04:05"), bukan kode sistem.
- Semua jam tampil dalam WIB.
- Halaman Import PO dirapikan: jumlah produk benar, tanpa teks teknis.
- Sumber pesanan Non-Toko: **CS, Sales Eksekutif, Lainnya**.

### Laporan baru
- **Omset per Pabrik** (dari Invoice; reject dan mutasi sudah diperhitungkan,
  retur tidak mengurangi omset karena menjadi tanggungan bakery).
- **Arus Barang per Produk**: PO → Produksi → Reject Produksi → Dikirim →
  Reject Toko → Terjual → Retur, dengan filter periode dan pabrik.

### Keamanan
- Akun selain Admin/PPIC (termasuk Driver) hanya bisa membuka menu kerjanya
  sendiri. Laporan omset, Master Data, dan Pesanan tertutup untuk mereka.

---

## Cara pasang

1. **Backup database** (phpMyAdmin → database → Export). Dianjurkan.
2. Upload `amor-factory-e2e-hardening.zip` ke `public_html/factory/`, lalu
   **Extract** (timpa file lama). Folder `api/app/config/` aman, tidak ikut
   tertimpa.
3. Buka `https://factory.amorgroup.id/api/_upgrade/` dan pastikan tertulis
   tidak ada migrasi yang menunggu.

## Yang TIDAK berubah
- Aturan stok, perhitungan invoice, nomor dokumen, dan semua data lama.
- Tidak ada tabel atau kolom database yang diubah atau dihapus.

## Belum ada di paket ini
- Piutang / Pembayaran: menunggu aturan bisnis (cicilan, jatuh tempo, siapa
  yang mencatat).
