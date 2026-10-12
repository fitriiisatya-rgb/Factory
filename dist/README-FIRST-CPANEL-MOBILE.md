# Amor Factory System — Tampilan HP + Riwayat — cPanel, Non-Teknis

**Baca ini sebelum memasang.** Paket ini membuat layar akun **Divisi**, **FG**,
**Driver**, dan **Toko** nyaman dipakai di HP, dan menambah menu
**Riwayat** di akun Divisi dan FG.

**Paket ini TIDAK membutuhkan migrasi database baru.** Server yang sudah di
migrasi 0019 cukup upload lalu extract. Paket ini juga berisi semua perbaikan
paket sebelumnya (`amor-factory-e2e-hardening.zip`).

**Path yang BENAR**: `public_html/factory/`.

---

## Yang baru

### Tampilan HP (akun Divisi & FG)
- Menu pindah ke **bawah layar** seperti aplikasi: Produksi · FG & Packing ·
  Riwayat · Pengaturan (FG: FG & Packing · Riwayat · Pengaturan).
- Bagian atas ringkas: logo, nama akun, dan nama operator + **(ganti)**.
- Tabel berubah menjadi **kartu per produk**. Setiap baris berisi label
  yang jelas (Target, Hasil, Reject, Catatan) dan kolom isian besar.
- Tombol **Simpan Draft / Submit Produksi** menempel di bawah layar, jadi
  tidak perlu menggulir ke bawah dulu.
- Angka ringkasan di HP dibatasi 4 yang paling penting.
- Kolom isian memakai huruf 16px sehingga iPhone tidak memperbesar layar
  sendiri saat diketik.
- Di laptop, tampilan **tidak berubah**.

### Menu Riwayat
- **Divisi**: semua dokumen produksi divisinya, berisi tanggal, status,
  jumlah produk, hasil, reject, siapa yang mengisi, dan kapan disubmit.
  Tombol **Buka** menampilkan dokumen tersebut.
- **FG**: semua dokumen FG pabriknya, berisi tanggal, status, FG
  terverifikasi, toko yang sudah dipacking, dan kapan disubmit.
- Filter tanggal **Dari – Sampai**, defaultnya 30 hari terakhir.
- Akun divisi **hanya** melihat divisinya sendiri. Akun FG hanya melihat
  pabriknya sendiri.
- **Driver** dan **Toko** sudah punya tab Riwayat di portal masing-masing
  (Riwayat Pengiriman; Riwayat penerimaan/retur/mutasi).

### Rapikan
- Halaman **Login Driver** kini satu gaya dengan login utama (tema biru
  gelap, ikon 📦).
- Label FG sekarang berbahasa Indonesia: "Hasil Produksi", "Hasil
  Packing", "Jumlah Dipacking", "Hasil Produksi Siap Diverifikasi".
- Tombol **pilih foto** di Portal Toko dibuat lebih besar.

---

## Cara pasang

1. (Dianjurkan) Backup database lewat phpMyAdmin → Export.
2. Upload `amor-factory-mobile.zip` ke `public_html/factory/`, lalu
   **Extract** (timpa file lama). Folder `api/app/config/` aman, tidak ikut
   tertimpa.
3. Buka `https://factory.amorgroup.id/api/_upgrade/` dan pastikan tertulis
   tidak ada migrasi yang menunggu.
4. Coba dari HP: login `divisi_roti` → isi nama → lihat menu bawah →
   buka **Riwayat**.

Jika HP masih menampilkan tampilan lama, tutup lalu buka lagi browsernya.
Versi file tampilan sudah dinaikkan, jadi biasanya ini tidak perlu.

## Yang TIDAK berubah
- Aturan stok, perhitungan invoice, nomor dokumen, hak akses, dan semua
  data lama.
- Tidak ada tabel atau kolom database yang diubah.
