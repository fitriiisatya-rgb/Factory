# Amor Factory System — Serah Terima Produksi → FG — cPanel, Non-Teknis

**Paket ini MEMBUTUHKAN migrasi database 0025.** Setelah extract, wajib buka
`https://factory.amorgroup.id/api/_upgrade/`.

Isinya sudah termasuk semua paket sebelumnya (Runchise 0024, Print DO per
Divisi, Ringkasan FG, notifikasi di tengah).

**Path yang BENAR**: `public_html/factory/`.

---

## Yang baru

### Divisi: produksi sebelum PO ada
- **Mulai Isi Produksi** selalu ada, walau PO tanggal itu belum diupload
  (status "Belum ada PO").
- Kotak **Mau produksi apa?**: ketik nama produk (min. 2 huruf), pilih, lalu
  produk masuk ke lembar. Hanya produk divisi sendiri.
- Produk tanpa PO tampil "belum ada PO" dan kolom **Hasil jadi** diisi
  langsung. Hasilnya tetap dihitung.
- Begitu PO diupload, produk PO otomatis masuk ke lembar yang masih terbuka
  dan target muncul.

### Divisi: serah terima ke FG (sebagian, berkali-kali)
- Kotak **Serah terima ke FG**: sudah jadi / diserahkan / belum diserahkan.
- **Serahkan ke FG** → isi jumlah yang dibawa sekarang (otomatis = sisa yang
  belum diserahkan). Tidak bisa melebihi yang sudah jadi.
- **Koreksi** selama FG belum mengecek; isi 0 untuk menarik kembali. Setelah
  dicek FG, koreksi hanya lewat Admin.
- "Hasil jadi" tidak bisa diturunkan di bawah jumlah yang sudah diserahkan.

### FG: cek serah terima
- Tab **Ringkasan** menampilkan **Serah terima dari produksi**: daftar yang
  menunggu cek dan yang sudah dicek.
- **Cek** → isi jumlah diterima baik. Selisihnya = **reject**, wajib pilih
  alasan: Ukuran tidak sesuai / Rusak-penyok / Bentuk / Lainnya (tulis).
- Yang bisa diverifikasi FG = yang sudah diserahkan dikurangi reject.
  Produksi yang sudah **Submit** tetap terhitung penuh seperti sebelumnya.
- Kalau dokumen FG sudah disubmit lalu ada serah terima baru, dokumen FG
  **otomatis terbuka lagi** (alasan: "Otomatis: ada serah terima baru dari
  produksi").

### Ringkasan FG: posisi barang hari ini
- Bar 4 warna: **Masih di produksi · Diserahkan, belum dicek · Diterima FG ·
  Dipacking**, plus **Reject saat serah terima**.
- Per divisi: sudah jadi, masih di produksi, diserahkan, menunggu cek.

## Cara pasang
1. **Backup database** lewat phpMyAdmin → Export.
2. Upload `amor-factory-handover.zip` ke `public_html/factory/`, lalu
   **Extract** (timpa file lama).
3. Login Admin, buka `https://factory.amorgroup.id/api/_upgrade/`, lalu
   jalankan migrasi **0025_production_handover**.
4. Uji: login akun divisi → Produksi → isi hasil → **Serahkan ke FG**; lalu
   login FG → FG & Packing → Ringkasan → **Cek**.

## Catatan
- Migrasi 0025 hanya menambah 2 tabel baru. Data lama tidak diubah.
- Alur lama (divisi Submit tanpa serah terima) tetap jalan seperti biasa.
