# Amor Factory System — Kirim Sesuai Packing + Stok Sisa — cPanel, Non-Teknis

**Paket ini TIDAK membutuhkan migrasi database baru.** Cukup upload lalu
extract. Isinya sudah termasuk semua paket sebelumnya (UI ringkas, Omset
Non-Toko, tampilan HP, Riwayat).

**Path yang BENAR**: `public_html/factory/`.

---

## Aturan baru (sesuai arahan pemilik)

### 1. Acuan kirim = hasil packing FG
- Divisi boleh submit hasil produksi **di atas target**. Contoh: PO 4 slice,
  dibuat 1 hole cake = 8.
- FG boleh mempacking **lebih dari PO** untuk sebuah toko. Batasnya hanya
  total hasil produksi.
- **DO mengikuti hasil packing FG**, bukan lagi PO: lebih dari PO kalau FG
  mempacking lebih, kurang dari PO kalau produksinya kurang. Sebelum FG
  disubmit, DO masih memakai PO. Menekan "Generate Draft DO Semua Toko" lagi
  setelah FG disubmit akan **memperbarui DO yang masih draft**.

### 2. Stok Sisa produksi
- Kalau FG menekan **Sesuai**, tiap toko mendapat sesuai PO-nya dan
  **kelebihannya otomatis menjadi Stok Sisa**. Contoh: dibuat 8, PO 3, maka
  Stok Sisa 5.
- Kalau FG menaikkan jatah satu toko di atas PO, jumlahnya **diambil dari
  Stok Sisa**.
- Stok Sisa terlihat di:
  - layar **FG Packing**: "Stok Sisa (lebih dari PO)";
  - **Dashboard Admin**: peringatan kuning + tombol "Lihat & tindak lanjuti";
  - menu baru **Stok Sisa** (Admin/PPIC): per divisi, produk, pabrik, jumlah,
    harga, perkiraan nilai, dan tanggal terakhir disisihkan.
- Dari menu Stok Sisa, satu tombol:
  - **Kirim susulan ke toko**: pilih toko, jumlah, dan harga. Otomatis menjadi
    Pesanan Khusus Toko yang diambil dari Stok Sisa. Setelah itu buat DO di
    "DO Pesanan Khusus / Non-Toko", driver mengirim, toko konfirmasi, lalu
    masuk invoice.
  - **Jual non-toko**: pilih sumber (CS / Sales Eksekutif / Lainnya), nama
    pelanggan, jumlah, dan harga. Otomatis menjadi Pesanan Non-Toko. Setelah
    ditandai Selesai, masuk Omset Non-Toko.

### 3. Perbaikan keamanan stok
- Pesanan Khusus/Non-Toko **tidak bisa lagi mengambil barang yang sudah
  dipacking untuk toko lain**. Sebelumnya bisa, sehingga kiriman ke toko
  aslinya gagal karena stok habis.

---

## Cara pasang
1. Upload `amor-factory-stok-sisa.zip` ke `public_html/factory/`, lalu
   **Extract** (timpa file lama).
2. Login Admin. Sidebar sekarang punya menu **Stok Sisa**.

## Yang TIDAK berubah
- Invoice tetap dihitung dari jumlah yang diterima toko.
- Nomor dokumen, hak akses, dan data lama tidak berubah.
- Tidak ada tabel atau kolom database yang diubah.
