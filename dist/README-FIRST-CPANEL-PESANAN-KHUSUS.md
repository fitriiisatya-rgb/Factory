# Amor Factory System — Pesanan Khusus & Perbaikan Deep-Check — cPanel, Non-Teknis

**Paket ini MEMBUTUHKAN migrasi database 0023.** Setelah extract, wajib buka
`https://factory.amorgroup.id/api/_upgrade/`.

Isinya sudah termasuk semua paket sebelumnya (Integrasi finhub, Invoice per
DO + Nota Potongan, dan seterusnya).

**Path yang BENAR**: `public_html/factory/`.

---

## Aturan baru (sesuai keputusan pemilik, Okt 2026)

- **Pesanan Khusus Toko** ditagih ke toko dengan **harga di pesanan**
  (harga satuan × jumlah diterima, ditambah biaya & kemasan tambahan satu
  kali). Item katalog khusus (tanpa produk master) juga ikut tertagih.
- **Pesanan Non-Toko** tidak pernah ditagihkan ke bakery tempat titip.
  Omset Non-Toko di Laporan dihitung dari **jumlah yang benar-benar terkirim**.
- **Status Selesai otomatis** begitu semua item pesanan terkirim. Tombol
  Selesai manual hanya ada untuk pesanan **Pickup** (diambil di pabrik),
  dan hanya bisa setelah semua item siap di FG.
- **DO Khusus dibatasi** sebesar jumlah pesanan. Kelebihan produksi tetap
  menjadi Stok Sisa.
- Membatalkan pesanan akan ikut membatalkan DO khusus yang belum berangkat.
  Pesanan yang sudah ada kirimannya tidak bisa dibatalkan.

## Perbaikan bug

- Angka **1.000 ke atas** dan angka desimal di kolom isian (Produksi, FG,
  Pengiriman, Task per Divisi) dulu bisa terbaca salah (1.047 terbaca 1,047).
  Sekarang benar.
- Akun divisi hanya bisa mengisi baris divisinya sendiri. Akun FG hanya bisa
  memverifikasi pabriknya sendiri. Dua divisi bisa menyimpan pesanan yang
  sama tanpa bentrok.
- Akun FG bisa memverifikasi pesanan khusus.
- Baris **Pengganti (reject)** di Task per Divisi sekarang tersimpan.
- Tombol Buka Ulang (Reopen) dan Alokasikan hanya untuk Admin/PPIC.
- Halaman Produksi memberi tahu jika ada produk PO baru atau produk tanpa
  divisi.
- Driver: tanggal kembali ke hari ini setiap hari, dan tidak bisa berangkat
  dengan semua jumlah 0. Pesan "stok toko belum siap" lebih jelas.
- Membatalkan DO melepas klaim driver. Admin bisa **Lepas Klaim Driver** di
  DO khusus.
- Mutasi yang masih menunggu toko tujuan bisa dibatalkan Admin.

## Cara pasang
1. **Backup database** lewat phpMyAdmin → Export.
2. Upload `amor-factory-pesanan-khusus.zip` ke `public_html/factory/`, lalu
   **Extract** (timpa file lama).
3. Login Admin, buka `https://factory.amorgroup.id/api/_upgrade/`, lalu
   jalankan migrasi **0023_invoice_special_lines**.

## Yang TIDAK berubah
- Data lama tetap ada. Migrasi 0023 hanya mengubah tabel `invoice_item`
  (menambah kolom). Tidak ada data yang dihapus.
- Invoice PO reguler tetap dihitung seperti sebelumnya.
