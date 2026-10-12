# Amor Factory System — Reject di Toko vs Retur — cPanel, Non-Teknis

**Paket ini MEMBUTUHKAN migrasi database 0020.** Setelah extract, wajib buka
`https://factory.amorgroup.id/api/_upgrade/` (lihat "Cara pasang").
Isinya sudah termasuk semua paket sebelumnya (Stok Sisa, UI ringkas, Omset
Non-Toko, tampilan HP, Riwayat).

**Path yang BENAR**: `public_html/factory/`.

---

## Aturan baru (sesuai arahan pemilik)

| | ⚠️ **REJECT** | ↩️ **RETUR** |
|---|---|---|
| Artinya | Barang **rusak / jamur / basi** padahal **belum lewat expired** | Barang **masih bagus**, hanya **tidak laku** / lewat masa jual |
| Tanggungan | **Pabrik** | **Toko** |
| Tagihan | **Dipotong** dari invoice | **Tetap**, hanya dicatat |
| Warna | **Merah** | **Abu-abu, garis putus-putus** |

Reject sendiri ada dua jalur:
1. **Reject saat Terima**: barang datang sudah rusak. Dilaporkan di menu
   **Konfirmasi** saat penerimaan (tombol "⚠️ Ada masalah", sekarang merah).
   Cara kerjanya sama seperti sebelumnya.
2. **Reject di Toko** (BARU): barang rusak setelah beberapa hari di toko,
   sebelum expired. Contoh: roti exp 4 hari, hari ke-2 sudah berjamur.

## Portal Toko — menu "Lapor Barang" (dulu "Retur")
- Tekan **+ Lapor Barang Rusak / Tidak Laku**. Ada dua kartu besar:
  - **merah "RUSAK / JAMUR → REJECT · ditanggung pabrik"**;
  - **abu-abu "TIDAK LAKU → RETUR · hanya dicatat"**.
- **Form Reject (merah)** berisi produk, jumlah, kondisi (berjamur / basi /
  keras / rusak / lainnya), **tanggal expired di kemasan (wajib)**, dan **foto
  (wajib)**.
  - Kalau tanggal expired **sudah lewat**, sistem menolaknya dan menyarankan
    **Retur**. Barang yang rusak setelah expired bukan tanggungan pabrik.
  - Sebelum dikirim, ada layar **"Yakin ini REJECT?"** dengan tombol
    **"Bukan, ganti ke Retur"**.
- **Form Retur (abu-abu)** berisi pengingat: "Barang rusak/jamur? Pakai
  REJECT".
- Daftar laporan menampilkan **REJECT merah** dan **RETUR abu-abu**, beserta
  statusnya. Untuk Reject: Menunggu dicek pabrik / Disetujui → Tagihan
  dipotong / Disetujui → Diganti / Ditolak.

## Admin
- Menu baru **Reject di Toko**. Menu lama diberi nama yang lebih jelas:
  **Reject saat Terima** (dulu "Replacement Reject") dan **Retur (catatan)**
  (dulu "Retur").
- Dashboard menampilkan **peringatan merah** bila ada Reject di Toko yang
  menunggu keputusan.
- Pada tiap laporan: **Foto & Detail**, **Setujui Reject**, atau **Tolak**
  (wajib dengan alasan). Saat menyetujui, pilih salah satu:
  - **Potong invoice**: nilai (jumlah × harga produk) dikurangkan dari
    invoice toko berikutnya.
  - **Ganti barang**: tetap dipotong, **dan** sistem otomatis membuat
    **Pesanan Khusus Toko** pengganti (tanggal kirim bisa dipilih) yang
    langsung masuk ke **Produksi → Order Masuk**. Di sana pengganti bisa
    diproduksi divisi, atau dipenuhi dari stok bebas/Stok Sisa lewat tombol
    **Alokasikan dari FG Existing**. Setelah itu kirim seperti Pesanan Khusus
    biasa (DO Pesanan Khusus → driver → toko konfirmasi). Pengganti ditagih
    normal setelah diterima toko, jadi toko membayar sekali untuk barang yang
    bisa dijual.

## Invoice
- Reject di Toko yang sudah disetujui **otomatis dipotong pada invoice
  berikutnya** untuk toko itu. Pratinjau menampilkan kotak merah
  "Potongan Reject di Toko".
- **Tanpa batas waktu**: kalau invoice periode itu sudah terbit, potongan
  masuk ke invoice berikutnya dan tidak hilang. Setiap reject dipotong
  **tepat sekali**.
- Bila invoice di-**void**, potongannya kembali ke antrean untuk invoice
  berikutnya.
- Detail dan cetak invoice mencantumkan nomor dokumen Reject yang dipotong.
- Catatan: bila sebuah toko hanya punya potongan (tanpa kiriman baru) di
  periode itu, invoice-nya bernilai **minus** (kredit untuk toko).

## Laporan
- **Laporan Retur & Reject** kini terpisah menjadi tiga bagian:
  - Reject saat Terima;
  - Reject di Toko (qty, nilai, potong/ganti);
  - Retur (catatan).
- **Arus Barang** kini punya kolom: Reject saat Terima, Reject di Toko,
  Terjual, dan Retur (catatan).

---

## Cara pasang
1. **Backup database dulu** (phpMyAdmin → Export). Migrasi ini aman (hanya
   menambah kolom), tetapi backup tetap dianjurkan.
2. Upload `amor-factory-reject-di-toko.zip` ke `public_html/factory/`, lalu
   **Extract** (timpa file lama). Folder `api/app/config/` aman, tidak ikut
   tertimpa.
3. Login Admin, lalu buka `https://factory.amorgroup.id/api/_upgrade/` dan
   jalankan migrasi **0020_reject_di_toko**. Pastikan tertulis berhasil.
4. Coba dari HP: buka link Portal sebuah toko, lalu menu **Lapor Barang**.

Jika HP masih menampilkan tampilan lama, tutup lalu buka lagi browsernya.

## Yang TIDAK berubah
- Data Retur lama tetap ada dan otomatis dianggap **Retur** (tidak memotong
  invoice).
- Reject saat Terima, stok, nomor dokumen, hak akses, dan invoice yang sudah
  terbit tidak berubah.
- Barang Reject maupun Retur **tidak** menambah stok pabrik.

## Perlu tindakan Anda
- **24 produk masih berharga Rp0.** Potongan reject untuk produk tersebut
  juga Rp0. Isi harganya di Master Data.
