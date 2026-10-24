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
- **Koreksi** selama produksi belum disubmit; isi 0 untuk menarik kembali.
  Tidak bisa dikurangi di bawah jumlah yang sudah diverifikasi FG.
- "Hasil jadi" tidak bisa diturunkan di bawah jumlah yang sudah diserahkan.

### FG: barang langsung masuk, cek & reject di FG Verifikasi
- Yang diserahkan **langsung** jadi Hasil Produksi di FG Verifikasi — tidak
  ada langkah cek terpisah. Cek, reject, dan selisih tetap dicatat di FG
  Verifikasi seperti biasa.
- Produksi yang sudah **Submit** tetap terhitung penuh seperti sebelumnya.
  Kalau divisi Submit saat masih ada sisa yang belum diserahkan, sisanya
  **langsung** masuk ke FG (tidak perlu tombol Refresh).
- Kalau dokumen FG sudah disubmit lalu jumlah dari produksi berubah (serah
  terima baru / koreksi / divisi Submit dengan sisa), dokumen FG **otomatis
  terbuka lagi** (alasan: "Otomatis: ada serah terima baru dari
  produksi").
- Tab **Ringkasan** menampilkan **Serah terima masuk dari produksi** (jam,
  divisi, produk, jumlah).

### Ringkasan FG: posisi barang hari ini
- Bar 4 warna: **Masih di produksi · Di FG, belum diverifikasi ·
  Terverifikasi, belum dipacking · Dipacking**.
- Reject / hilang yang dicatat di FG Verifikasi tampil terpisah.
- Per divisi: sudah jadi, masih di produksi, diserahkan.

### Perbaikan lain (ketemu saat uji)
- Packing: "Isi semua yang belum diisi = Sesuai" sekarang juga mengisi
  produk yang punya reject dari FG Verifikasi (dulu terlewat, sehingga Submit
  FG ditolak "Packing belum selesai").
- Upload PO dengan file yang tidak terbaca (0 produk) sekarang ditolak dengan
  pesan jelas, tidak lagi tersimpan sebagai "PO 0 pcs".

## Cara pasang
1. **Backup database** lewat phpMyAdmin → Export.
2. Upload `amor-factory-handover.zip` ke `public_html/factory/`, lalu
   **Extract** (timpa file lama).
3. Login Admin, buka `https://factory.amorgroup.id/api/_upgrade/`, lalu
   jalankan migrasi **0025_production_handover**.
4. Uji: login akun divisi → Produksi → isi hasil → **Serahkan ke FG**; lalu
   login FG → FG & Packing → FG Verifikasi: hasil produksinya sudah muncul.

## Catatan
- Migrasi 0025 hanya menambah 2 tabel baru. Data lama tidak diubah.
- Alur lama (divisi Submit tanpa serah terima) tetap jalan seperti biasa.
