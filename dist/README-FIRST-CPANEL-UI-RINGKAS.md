# Amor Factory System — UI Ringkas untuk SKU Banyak — cPanel, Non-Teknis

**Paket ini TIDAK membutuhkan migrasi database baru.** Cukup upload lalu
extract. Isinya sudah termasuk paket sebelumnya (Omset Non-Toko, tampilan HP,
Riwayat).

**Path yang BENAR**: `public_html/factory/`.

Diuji dengan PO asli 29 September 2026 (±400 SKU × 37 toko) di salinan lokal
yang memakai master data live, dari upload PO sampai invoice.

---

## Yang baru

### Divisi Produksi (HP)
- Halaman langsung menampilkan **kartu hari**: pilih tanggal, lalu Target,
  Hasil, Reject, dan Status. Tombol **Mulai Isi Produksi**.
- Dokumen hari itu **terbuka otomatis** kalau sudah ada.
- **Satu baris per produk**: nama, target, dan tombol Sesuai / Tidak Sesuai.
  Kolom angka hanya muncul untuk produk yang kurang atau ada reject.
- **"Isi semua yang belum diisi = Sesuai target"**, jadi yang diisi manual
  hanya pengecualian.
- Progres "x / y produk terisi", cari produk, dan filter Belum diisi /
  Kurang-Reject / Sesuai.
- **Perbaikan:** akun divisi **Bolu (Cibadak)** sebelumnya membuka halaman
  kosong karena selalu diarahkan ke Karangtengah. Sekarang tiap akun divisi
  dan FG langsung dibuka di pabriknya sendiri.
- **Perbaikan:** setelah memilih "Tidak Sesuai" lalu berubah pikiran, tombol
  "Sesuai" kini kembali berwarna dengan benar.

### FG Verifikasi & Packing (HP)
- Pola yang sama: satu baris per produk, progres, cari, filter, dan
  **"Isi semua = Sesuai"** di Verifikasi maupun di Packing tiap toko.

### Driver (HP)
- Tab **Tersedia**: **satu kartu per toko** (jumlah produk & pcs), tidak lagi
  satu baris per produk. Ada **"Pilih semua toko yang tampil"**. Rincian per
  produk bisa dibuka kalau perlu mengubah jumlah.

### Portal Toko (HP)
- Konfirmasi Penerimaan: **satu baris per produk**. Semua dianggap diterima
  baik. Tekan **"Ada masalah"** hanya pada produk yang rusak atau kurang;
  Diterima Baik dihitung otomatis.

---

## Cara pasang
1. Upload `amor-factory-ui-ringkas.zip` ke `public_html/factory/`, lalu
   **Extract** (timpa file lama).
2. Coba dari HP dengan akun `divisi_bolu`. Halaman Produksi harus langsung
   dibuka di **Cibadak**.

## Perlu tindakan Anda
- **24 produk harganya masih Rp0** di Master Data. Pada uji PO 29 Sep,
  1.108 pcs tertagih Rp0 karena ini. Isi harganya sebelum invoice dipakai
  sungguhan.

## Yang TIDAK berubah
- Aturan stok, perhitungan invoice, nomor dokumen, hak akses, dan data lama.
- Tidak ada tabel atau kolom database yang diubah.
