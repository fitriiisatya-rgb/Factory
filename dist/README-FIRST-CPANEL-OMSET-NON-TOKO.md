# Amor Factory System — Omset Pesanan Non-Toko — cPanel, Non-Teknis

**Paket ini TIDAK membutuhkan migrasi database baru.** Cukup upload lalu
extract. Isinya sudah termasuk paket sebelumnya (`amor-factory-mobile.zip`:
tampilan HP + Riwayat).

**Path yang BENAR**: `public_html/factory/`.

---

## Yang baru: Pesanan Non-Toko masuk Omset

Menu **Laporan → Laporan Omset / Penjualan per Periode** sekarang berisi:

- **Total Omset** = Omset Toko + Omset Non-Toko.
- **Omset Toko**: dari Invoice toko, sama seperti sebelumnya (reject dan
  mutasi sudah dipotong, retur tidak memotong).
- **Omset Non-Toko**: dari Pesanan Non-Toko (CS, Sales Eksekutif, Lainnya).
- **Per Pabrik**: kolom Omset Toko, Omset Non-Toko, dan Total.
- **Non-Toko per Sumber**: jumlah pesanan dan omset untuk CS, Sales
  Eksekutif, dan Lainnya.

### Aturan hitung Omset Non-Toko
- Hanya pesanan berstatus **Selesai** yang dihitung. Pesanan yang masih
  berjalan atau Dibatalkan **tidak** dihitung.
- Nilainya = jumlah × harga + biaya tambahan + kemasan tambahan, sesuai yang
  diisi Admin di pesanan.
- Masuk periode sesuai **Tanggal Kebutuhan** pesanan.
- Pesanan tanpa harga (Rp0) ikut terhitung Rp0. **Isi harga saat membuat
  pesanan.**
- Setelah barang diserahkan ke pelanggan, Admin wajib menekan **Selesai** di
  detail pesanan. Tanpa itu, pesanan belum masuk omset.

### Belum termasuk
- Piutang / Pembayaran (ditahan sesuai arahan).
- Tabel **Arus Barang per Produk** tetap untuk alur PO toko saja.

---

## Cara pasang
1. Upload `amor-factory-omset-non-toko.zip` ke `public_html/factory/`, lalu
   **Extract** (timpa file lama).
2. Buka **Laporan** sebagai Admin. Bagian Omset menampilkan kartu "Omset
   Non-Toko".

## Yang TIDAK berubah
- Perhitungan invoice toko, stok, dan semua data lama.
- Tidak ada tabel atau kolom database yang diubah.
