# Amor Factory System — Kunci Packing Setelah Submit + Edit/Submit Ulang — cPanel, Non-Teknis

**Baca ini sebelum upload/extract. Paket ini TIDAK butuh migrasi
database — cukup upload & extract file, tidak ada langkah di halaman
Upgrade Database.**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Kenapa paket ini ada

Setelah paket sebelumnya (status submit Packing yang benar-benar
tersimpan di database), masih ada satu masalah tampilan: walau status
sudah menunjukkan **"✓ Sudah Disubmit"** dan tombol Submit Packing sudah
nonaktif, kolom Reject/Hilang/Keterangan dan input Packing **masih bisa
diketik langsung** tanpa ada tombol Edit yang jelas. Ini membingungkan
dan berisiko secara operasional — orang bisa tidak sadar sedang mengubah
data yang sudah disubmit.

Paket ini mengunci semua kolom Packing setelah disubmit, dan
mewajibkan koreksi lewat tombol **"Edit Packing"** yang eksplisit,
dengan konfirmasi terlebih dahulu.

---

## Yang PENTING dipahami

- **TIDAK ADA migrasi database baru.** Tabel `fg_store_packing_submission`
  dari paket sebelumnya (migrasi 0015) sudah cukup dan tidak diubah sama
  sekali. Tidak ada langkah di halaman `/_upgrade/` untuk paket ini.
- Perubahan di paket ini **murni tampilan (UI)** pada halaman **FG
  Packing** — tidak ada logika bisnis, aturan stok, pengiriman, atau
  reservasi Pesanan Khusus yang berubah.
- Mengedit/submit-ulang Packing per Toko **tetap tidak pernah**
  memposting stok, membuat pengiriman, atau mengubah stok fisik toko —
  itu tetap hanya tugas tombol terpisah "Submit FG (Semua Toko)".

---

## Cara pasang (cPanel, tanpa command line)

1. **Backup dulu** (langkah standar, walau paket ini tidak mengubah
   struktur database). Di cPanel → phpMyAdmin, export (backup) database
   Anda saat ini.
2. **Upload & extract.** Upload `amor-factory-pack-edit-lock.zip` ke
   `public_html/factory/`, lalu extract — ini akan MENIMPA file lama
   dengan versi baru (aman, tidak menghapus folder `api/app/config/`).
3. **TIDAK PERLU membuka halaman Upgrade Database** — tidak ada migrasi
   baru untuk paket ini.
4. **Health check cepat.** Buka halaman **FG & Packing** untuk tanggal
   dan pabrik yang biasa dipakai — pastikan tampilannya normal seperti
   sebelumnya.
5. **UAT nyata — kunci Packing setelah submit.**
   - Buka **FG Packing**, pilih satu toko yang belum pernah disubmit.
     Isi Actual Packing, lalu tekan **"Submit Packing [Nama Toko]"**.
   - Setelah berhasil, tampilan toko tersebut sekarang HARUS:
     - Menunjukkan badge **"✓ Sudah Disubmit"**.
     - SEMUA kolom (Sesuai/Tidak Sesuai, Actual Packing, Reject, Hilang,
       Keterangan) menjadi **tidak bisa diketik/dipilih langsung**.
     - Menampilkan tombol **"Edit Packing"** — bukan lagi tombol Submit
       yang nonaktif.
   - Tekan **"Edit Packing"** — harus muncul konfirmasi: *"Packing toko
     ini sudah disubmit. Apakah Anda ingin melakukan koreksi?"* dengan
     pilihan **Batal** / **Ya, Edit Packing**.
   - Tekan **Batal** — pastikan tampilan KEMBALI ke "✓ Sudah Disubmit"
     terkunci seperti semula (tidak ada perubahan apa pun).
   - Tekan **Edit Packing** lagi, kali ini pilih **Ya, Edit Packing** —
     kolom harus terbuka untuk diedit, dan muncul indikator jelas **"MODE
     EDIT"** di layar.
   - Tanpa mengubah apa pun, tekan **"Batal Edit"** — pastikan status
     KEMBALI ke "✓ Sudah Disubmit" (bukan berubah jadi "Perlu Submit
     Ulang").
   - Masuk Edit Packing lagi, kali ini benar-benar ubah salah satu angka
     (misalnya Reject) atau Keterangan, lalu tekan **"Simpan Perubahan"**.
   - Setelah tersimpan, tampilan HARUS berubah jadi status **"Perlu
     Submit Ulang"**, kolom terkunci lagi, dan muncul tombol aktif
     **"Submit Ulang Packing [Nama Toko]"**.
   - **Muat ulang halaman (refresh browser)** — status "Perlu Submit
     Ulang" harus TETAP tampil (bukti tersimpan di database).
   - Tekan **"Submit Ulang Packing [Nama Toko]"** — status harus kembali
     ke **"✓ Sudah Disubmit"**, kolom terkunci lagi.
   - Cek toko LAIN yang tidak pernah disentuh — statusnya harus tetap
     "Belum Mulai" seperti biasa, tidak terpengaruh sama sekali oleh
     proses edit/submit-ulang toko sebelumnya.
   - Buka halaman ini juga dari HP (atau browser dengan lebar layar
     sempit) — pastikan tidak ada scroll ke samping (horizontal) di
     bagian mana pun dari alur ini.

---

## Yang TIDAK berubah

- Tidak ada migrasi database baru — struktur tabel persis seperti paket
  sebelumnya (migrasi 0001–0015, tidak disentuh sama sekali).
- Alur PO Reguler, Produksi, FG Verifikasi, Breakdown Toko, alur DO,
  Pengiriman, Driver Portal, Pesanan Khusus/Non-Toko — bekerja PERSIS
  seperti sebelumnya.
- Tombol terpisah **"Submit FG (Semua Toko)"** (yang benar-benar
  memposting stok) — TIDAK berubah sama sekali.
- Data yang sudah ada — tidak ada satu baris pun yang dihapus atau
  diubah oleh paket ini (paket ini tidak menyertakan satu pun perubahan
  skema database).
- Keamanan: hanya role yang sudah berwenang mengedit FG (sama seperti
  sebelumnya) yang bisa melihat/menggunakan tombol Edit Packing dan
  Submit Ulang — ini tetap ditegakkan di sisi server, bukan hanya
  disembunyikan di tampilan.

---

## Kalau ada masalah

Kalau setelah upload & extract, halaman FG Packing tidak menunjukkan
perilaku seperti langkah UAT di atas (misalnya kolom masih bisa diedit
langsung setelah submit, atau tombol Edit Packing tidak muncul),
**jangan lanjutkan penggunaan produksi** — ambil screenshot dan laporkan
kembali sebelum melanjutkan.
