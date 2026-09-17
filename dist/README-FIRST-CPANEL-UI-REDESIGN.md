# Amor Factory System — Panduan UI/UX Redesign Preview (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada langkah yang
mengharuskan Anda menjalankan perintah SQL manual atau membuka Terminal/SSH
— semua lewat klik di File Manager dan browser biasa. Tidak ada migrasi
database yang perlu diterapkan di paket ini.**

**Baca ini setelah Phase 5 (Draft DO / Pengiriman) selesai dan sudah
diuji di UAT real.** Panduan ini untuk memasang **tampilan admin baru**
(dark mode, dashboard operasional, halaman per modul) di path **terpisah**
dari sistem yang sudah berjalan — tidak menggantikan apa pun. Semua
halaman UAT lama (`_import-po`, `_production-uat`, `_fg-uat`, `_do-uat`)
tetap ada dan tetap berfungsi persis seperti sebelumnya, sebagai
cadangan/fallback selama masa transisi.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri) — sama seperti Phase 1-5.

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN (sama seperti Phase 1-5).
- File `amor-factory-ui-redesign-preview.zip` (dikirim bersama panduan
  ini).
- **Backup sudah ada** (dibuat setelah UAT Phase 5) — paket ini tidak
  mengubah database sama sekali, tapi tetap disarankan backup ulang
  sebelum memulai, sesuai kebiasaan setiap phase sebelumnya.

---

## Langkah-langkah

### 1. Backup (opsional tapi disarankan)

cPanel → Backup Wizard → backup database `u7566812_factory` dan folder
`public_html/factory/`. Paket ini TIDAK mengubah struktur database sama
sekali, jadi langkah ini murni jaga-jaga.

### 2. Upload `amor-factory-ui-redesign-preview.zip` ke `public_html/factory/`

Buka cPanel → File Manager → `public_html` → `factory`, lalu upload file
ZIP-nya di situ (pastikan Anda benar-benar di dalam folder `factory`).

### 3. Extract ZIP tersebut di tempat yang sama

Klik kanan file ZIP → **Extract**. Hasilnya bergabung ke folder
`public_html/factory/api/` yang sudah ada — file `config.php` Anda TIDAK
ikut ditimpa, dan folder-folder UAT lama (`_import-po/`,
`_production-uat/`, `_fg-uat/`, `_do-uat/`) tetap ada tanpa perubahan.

### 4. Periksa `config.php` Anda masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 5. TIDAK ADA migrasi database untuk paket ini

Paket ini murni tampilan (UI) di atas API yang sudah ada — tidak ada
tabel baru, tidak ada kolom baru. **Lewati langkah "Upgrade Database"**
untuk paket ini (boleh dicek di `api/_upgrade/` untuk memastikan, seharusnya
tertulis "Tidak ada yang perlu diterapkan").

### 6. Cek kesehatan sistem dulu

Buka `https://factory.amorgroup.id/api/health` — pastikan muncul
`"ok": true` dan `"db": "connected"` sebelum lanjut.

### 7. Login sebagai ADMIN

Buka `https://factory.amorgroup.id/api/_admin-login/` dan login dengan
akun ADMIN Anda seperti biasa.

### 8. Buka tampilan baru

Buka `https://factory.amorgroup.id/api/_ui-preview/` di tab/browser yang
sama (sesi login yang sama otomatis terpakai). Anda akan melihat
Dashboard dark-mode dengan sidebar menu di kiri.

### 9. Uji Dashboard

Pastikan kartu-kartu di bagian atas (PO Target, Produksi Aktual, FG
Tersedia, DO Dibuat, Sudah Terkirim) menampilkan angka yang masuk akal
untuk tanggal & pabrik hari ini (bukan angka contoh/mockup) — ganti
tanggal/pabrik lewat filter di atas untuk memastikan angkanya berubah
sesuai data sebenarnya.

### 10. Uji halaman Produksi

Klik menu **Produksi**. Buka salah satu divisi, isi/ubah angka Actual,
klik **Simpan Draft**, lalu **Submit Produksi**. Cek: perilaku sama
persis seperti di `_production-uat/` (data yang sama, tervalidasi sama),
hanya tampilannya yang baru.

### 11. Uji halaman FG & Packing

Klik menu **FG & Packing**. Buat/buka draft FG untuk tanggal yang
Produksinya sudah SUBMITTED, isi FG Terverifikasi & Packed, **Simpan
Draft**, lalu **Submit FG**. Cek: stok FG bertambah sesuai angka Packed
yang disubmit, persis seperti sistem lama.

### 12. Uji halaman Delivery Order

Klik menu **Delivery Order**. Coba **Generate Draft DO Semua Toko**, buka
salah satu DO, klik **Tandai Preprint**, lalu buka **Print Preview** —
pastikan dokumen tampil rapi dengan watermark DRAFT/PREPRINT yang sesuai.

### 13. Uji halaman Pengiriman

Dari halaman detail DO, klik **Buat Pengiriman**. Isi Qty Kirim untuk
satu produk (tidak boleh melebihi Sisa DO atau FG Available — kolom yang
stoknya kosong otomatis tidak bisa diisi), klik **Pratinjau Pengiriman**
untuk memastikan valid, lalu **Konfirmasi KIRIM**. Cek: stok FG berkurang
tepat setelah dikonfirmasi, DO menampilkan Sisa yang benar.

### 14. Uji cetak (print)

Dari halaman Delivery Order, klik **Print** pada salah satu DO. Pastikan
tampilan cetak bersih (latar putih, bukan gelap), rapi untuk kertas A4,
dan tombol/menu sidebar tidak ikut tercetak.

### 15. Konfirmasi semua halaman UAT lama masih berfungsi

Buka `api/_production-uat/`, `api/_fg-uat/`, `api/_do-uat/`,
`api/_import-po/` satu per satu — pastikan semuanya masih bisa diakses
dan berfungsi seperti sebelum paket ini dipasang. Tampilan baru ini
adalah TAMBAHAN, bukan pengganti.

### 16. Jika ada masalah tampilan: cara mundur (rollback)

Karena paket ini hanya menambah file baru (folder `api/_ui-preview/` dan
`api/app/ui/`) dan tidak mengubah database maupun `config.php`, cara
mundur paling aman adalah: hapus folder `public_html/factory/api/_ui-preview/`
dan `public_html/factory/api/app/ui/` lewat File Manager. Semua sistem
lama (Phase 1-5, halaman UAT) akan tetap berjalan normal tanpa
terpengaruh sama sekali — tidak ada langkah database yang perlu
dibatalkan.

---

## Catatan penting

- **api/_ui-preview/ bersifat preview** — belum menggantikan alamat utama
  (`factory.amorgroup.id/`) dan tidak akan menggantikannya tanpa
  persetujuan eksplisit Anda lebih dulu.
- **Tidak ada logika bisnis yang berubah** — PO, Produksi, FG/Packing, DO,
  dan Pengiriman semuanya tetap memakai API yang SAMA PERSIS dengan
  sebelumnya. Tampilan baru ini hanya memanggil API tersebut dengan cara
  yang lebih nyaman dipakai.
- **Tidak ada data yang dihapus atau direset** oleh paket ini.
- Modul ini **HANYA** tampilan untuk Phase 1-5. Belum ada Invoice,
  Pembayaran, Piutang, Retur, atau Reject Outbound di tampilan baru ini —
  sama seperti sebelumnya.

**Selesai.** Jika ada langkah yang gagal atau tampilan yang tidak sesuai,
hentikan di situ dan simpan tangkapan layarnya — jangan melanjutkan ke
langkah berikutnya sebelum masalahnya jelas. Karena tidak ada perubahan
database, Anda selalu bisa kembali ke kondisi semula hanya dengan
menghapus dua folder baru di atas.
