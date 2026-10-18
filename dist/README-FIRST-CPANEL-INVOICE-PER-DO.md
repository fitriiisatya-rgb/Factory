# Amor Factory System — Invoice per DO + Nota Potongan — cPanel, Non-Teknis

**Paket ini MEMBUTUHKAN migrasi database 0021.** Setelah extract, wajib buka
`https://factory.amorgroup.id/api/_upgrade/`.

Isinya sudah termasuk semua paket sebelumnya (Reject di Toko, Stok Sisa, UI
ringkas, Omset Non-Toko, tampilan HP, Riwayat), plus perbaikan tombol
**Submit FG (Semua Toko)**.

**Path yang BENAR**: `public_html/factory/`.

---

## Aturan baru (sesuai arahan pemilik)

### 1. Invoice dibuat per DO
- Menu **Invoice** menampilkan daftar DO yang **sudah dikonfirmasi toko** dan
  belum ditagih.
- Tombol **Terbitkan semua** membuat **satu invoice untuk setiap DO**. Isinya
  jumlah yang diterima baik × harga produk. Reject saat terima sudah
  otomatis tidak ditagih.
- DO baru siap ditagih setelah **semua kirimannya** dikonfirmasi toko, dan
  reject saat terima sudah diverifikasi Admin.
- DO Pesanan Khusus juga ditagih per DO.
- Invoice per periode (cara lama) masih ada di bagian lipat
  "Invoice per periode (manual, opsional)". Biasanya tidak perlu dipakai.

### 2. Nota Potongan
**Invoice yang sudah terbit tidak pernah diubah.** Potongan dibuat sebagai
dokumen terpisah, **Nota Potongan** (nomor `NP/KRM/001/X/2026`), yang merujuk
ke invoice DO asal barangnya.

| Kejadian | Hasil |
|---|---|
| Admin menyetujui **Reject di Toko** (Potong atau Ganti) | Nota Potongan otomatis terbit atas invoice DO yang mengirim barang itu |
| **Mutasi** antar toko selesai | Toko **asal** mendapat Nota Potongan, toko **tujuan** mendapat invoice (lewat tombol Terbitkan semua) |
| **Retur** | Tidak ada nota. Hanya catatan, tagihan tetap |

- Kalau DO-nya **belum ditagih**, nota tetap terbit dengan tulisan
  "Atas invoice: menyusul". Nota otomatis tersambung saat invoice DO itu
  terbit.
- Harga potongan sama dengan harga di invoice DO tersebut.
- Nota bisa **dicetak** (tombol Print) dan **dibatalkan** dengan alasan.
  Untuk Reject di Toko, nota yang dibatalkan bisa diterbitkan ulang dari
  menu Reject di Toko.

### 3. Laporan
- **Laporan Omset → Per Toko:** Total Invoice, Nota Potongan, dan
  **Tagihan Bersih** (invoice − nota) untuk tiap bakery.
- Nota dihitung di **periode invoice yang dipotongnya**, yaitu periode
  barangnya. Contoh: barang kiriman Oktober yang dilaporkan rusak pada
  2 November tetap mengurangi omset **Oktober**.
- Omset per pabrik dan kolom "Terjual" di Arus Barang juga sudah dikurangi
  nota.

---

## Cara pasang
1. **Backup database** lewat phpMyAdmin → Export.
2. Upload `amor-factory-invoice-per-do.zip` ke `public_html/factory/`, lalu
   **Extract** (timpa file lama).
3. Login Admin, buka `https://factory.amorgroup.id/api/_upgrade/`, lalu
   jalankan migrasi **0021_nota_potongan**.

## Perbaikan kecil (versi 17 Okt)
- Tombol **Keluar** (logout) di pojok kanan atas.
- Jendela **Tambah/Edit User** sekarang bisa di-scroll di layar laptop
  pendek, jadi pilihan Pabrik FG & Packing dan tombol Simpan bisa dijangkau.
  Kalau server sudah di 0021, cukup upload + extract (tanpa migrasi), lalu
  refresh halaman.
- Halaman **FG & Packing**: akun yang belum punya akses ke pabrik itu
  (misalnya akun divisi) sekarang melihat pesan jelas untuk login dengan
  akun FG, bukan halaman setengah jalan yang gagal saat disimpan.
- **FG & Packing jauh lebih cepat** (versi 18 Okt): daftar toko di Packing
  dan tombol "Lanjut ke Packing" sebelumnya menunggu 13 detik sampai 1-2
  menit di server live (satu permintaan per produk). Sekarang satu
  permintaan untuk semua produk.

## Yang TIDAK berubah
- Data lama tetap ada. Invoice lama dianggap jenis "Periode".
- Stok, nomor dokumen lain, dan hak akses tidak berubah.
- Pembayaran/piutang masih ditahan.
