# Amor Factory System — Panduan Redesign Print DO / Surat Jalan (cPanel, Non-Teknis)

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding. **Tidak ada perintah SQL
manual, tidak ada Terminal/SSH, dan tidak ada migrasi database untuk
paket ini.**

**Baca ini setelah Phase 5 (Draft DO / Pengiriman) sudah berjalan.**
Panduan ini HANYA untuk tampilan cetak (print) Delivery Order / Surat
Jalan — tidak mengubah cara Draft DO, Preprint, atau Pengiriman bekerja.
Halaman cetak lama (`api/_do-uat/print.php`) tetap ada dan tetap
berfungsi sebagai cadangan.

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Yang Anda butuhkan sebelum mulai

- Akses cPanel File Manager untuk domain `factory.amorgroup.id`.
- Username & password akun ADMIN.
- File `amor-factory-ui-print-do-preview.zip` (dikirim bersama panduan
  ini).

---

## Langkah-langkah

### 1. Backup (opsional tapi disarankan)

cPanel → Backup Wizard → backup database `u7566812_factory` dan folder
`public_html/factory/`. Paket ini tidak mengubah struktur database sama
sekali.

### 2. Upload & Extract

Upload `amor-factory-ui-print-do-preview.zip` ke `public_html/factory/`,
lalu klik kanan → **Extract**. Hasilnya bergabung ke folder
`public_html/factory/api/` yang sudah ada — `config.php` Anda TIDAK ikut
ditimpa.

### 3. Periksa `config.php` masih ada dan tidak berubah

Buka `public_html/factory/api/app/config/config.php` di File Manager
Editor — pastikan `DB_USER`/`DB_PASS` masih terisi seperti sebelumnya.

### 4. TIDAK ADA migrasi database untuk paket ini

Lewati langkah "Upgrade Database" — paket ini murni tampilan cetak, tidak
ada tabel/kolom baru.

### 5. Login sebagai ADMIN

Buka `https://factory.amorgroup.id/api/_admin-login/` dan login seperti
biasa.

### 6. Buka salah satu Delivery Order yang sudah ada

Buka `https://factory.amorgroup.id/api/_ui-preview/?page=delivery-order`,
pilih tanggal & pabrik yang sudah ada DO-nya, lalu klik **Print** pada
salah satu baris (atau buka **Delivery Order** → pilih satu DO → **Print
Preview**).

### 7. Periksa tampilan Draft

Jika DO tersebut masih berstatus Draft, pastikan muncul watermark besar
**"DRAFT"** di tengah dokumen, dan kolom **Sudah Dikirim** menunjukkan 0,
**Sisa** sama dengan **Qty Rencana**.

### 8. Periksa tampilan Preprint

Kembali ke halaman DO, klik **Tandai Preprint**, lalu buka Print Preview
lagi — watermark berubah menjadi **"PREPRINT"**.

### 9. Periksa tampilan setelah Pengiriman sebagian

Buat satu pengiriman sebagian dari halaman Pengiriman (Qty Kirim kurang
dari Sisa DO), lalu buka Print Preview lagi. Pastikan:
- **Qty Rencana** tetap sama (jumlah total pesanan),
- **Sudah Dikirim** menunjukkan jumlah yang sudah benar-benar dikirim,
- **Sisa** = Qty Rencana dikurangi Sudah Dikirim.

### 10. Periksa tampilan setelah Terkirim Penuh

Setelah SEMUA item pada DO habis terkirim, buka Print Preview — status di
kanan atas menunjukkan **"TERKIRIM"** tanpa watermark besar (karena ini
sudah menjadi dokumen final, bukan draft).

### 11. Periksa Print Semua (Bulk)

Dari halaman daftar Delivery Order, klik **Print Semua DO** — pastikan
setiap DO tampil di halaman kertas terpisah (ganti halaman), masing-masing
dengan kop surat, tabel, dan kolom tanda tangannya sendiri.

### 12. Uji tombol Cetak & tampilan kertas

Klik tombol **Cetak** di pojok kanan atas — pastikan pratinjau cetak
browser menampilkan halaman putih bersih berukuran A4, TANPA sidebar/menu
aplikasi, tanpa latar belakang gelap, dan tombol Cetak/Kembali tidak ikut
tercetak. Coba juga "Save as PDF" dari dialog cetak browser jika perlu
menyimpan file.

### 13. Uji di iPad (jika tersedia)

Buka halaman Print Preview yang sama di Safari iPad, posisi landscape dan
portrait — pastikan dokumen A4 tetap terbaca dan proporsional, tidak
terpotong.

### 14. Konfirmasi halaman cetak lama masih berfungsi

Buka `api/_do-uat/print.php?doId=<salah satu ID DO>` — pastikan halaman
cetak versi lama masih bisa diakses dan menampilkan data yang sama,
sebagai cadangan.

### 15. Cara mundur (rollback) jika ada masalah

Karena paket ini hanya menambah/mengganti file di `api/_ui-preview/` dan
`api/app/ui/` (tidak menyentuh database), cara mundur paling aman adalah
menghapus kedua folder tersebut lewat File Manager — sistem Phase 1-5 dan
halaman cetak lama (`api/_do-uat/print.php`) tetap berjalan normal tanpa
terpengaruh.

---

## Catatan penting

- **Membuka/mencetak DO TIDAK PERNAH mengubah data** — status, versi
  dokumen, dan stok FG hanya berubah lewat aksi Preprint/Kirim yang
  sesungguhnya (tidak berubah sama sekali di paket ini).
- **Tidak ada logika bisnis yang berubah** — nomor DO, aturan
  planned/shipped/remaining, dan aturan stok semuanya identik dengan
  Phase 5 sebelumnya.
- Kolom **Catatan** pada dokumen cetak saat ini masih kosong secara
  default — belum ada halaman untuk mengisi catatan per-DO (kolom
  database-nya sudah ada, tapi belum ada tombol edit di versi ini).

**Selesai.** Jika ada tampilan yang tidak sesuai, hentikan di situ dan
simpan tangkapan layarnya — jangan melanjutkan sebelum masalahnya jelas.
