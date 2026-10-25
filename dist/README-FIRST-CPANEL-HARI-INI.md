# Amor Factory System — Hari Ini, Backup Otomatis, Catatan Error, Input Lebih Banyak Ketuk

**Tidak perlu migrasi database.** Cukup upload dan extract.

Isinya sudah termasuk semua paket sebelumnya (serah terima produksi → FG, posisi stok FG per produk).

**Path yang BENAR**: `public_html/factory/`.

---

## Yang baru

### 1. Halaman "Hari Ini" (Admin/PPIC)
- Halaman pertama setelah Admin login. Juga ada di menu paling atas.
- Daftar tugas hari ini per pabrik, setiap baris bisa diketuk:
  - PO hari ini dan PO besok;
  - tiap divisi (belum mulai, sedang jalan, atau sudah submit), termasuk yang masih di produksi;
  - FG & Packing, DO dibuat, sudah dikirim;
  - kiriman yang belum dikonfirmasi toko, reject saat terima, reject di toko, retur, mutasi, Stok Sisa;
  - error sistem dan backup.
- Warna: merah = perlu dikerjakan, kuning = perlu dipantau, hijau = beres.

### 2. Backup otomatis (Pengaturan → Sistem)
- Sistem membuat **backup database otomatis sekali sehari** saat Admin membuka aplikasi, dan menyimpan **14 backup terakhir** di server.
- Tombol **Backup sekarang** dan tombol **Unduh** per backup.
- **Penting:** unduh satu backup ke laptop atau Google Drive minimal seminggu sekali. Backup yang hanya ada di server ikut hilang kalau servernya bermasalah.
- Cara mengembalikan: phpMyAdmin → database → **Import** → pilih file `.sql.gz`.
- **Lebih andal (disarankan):** jadwalkan lewat cPanel → **Cron Jobs** → *Once Per Day*. Perintahnya tertera di halaman Sistem, bentuknya: `php /home/USER/public_html/factory/api/app/cli/backup.php`.

### 3. Catatan error (Pengaturan → Sistem)
- Setiap error sistem (halaman gagal dibuka atau proses gagal) tercatat lengkap dengan waktu, halaman, dan user.
- Jumlah error 24 jam terakhir tampil di "Hari Ini".
- Opsional, notifikasi email paling banyak 1 kali per jam: tambahkan di `api/app/config/config.php`
  `'ERROR_ALERT_EMAIL' => 'email-admin@...',`
  (butuh pengaturan MAIL_* yang sudah aktif).

### 4. Lebih banyak ketuk, lebih sedikit ketik (divisi, FG, driver)
- **"Siapa Anda?":** nama yang pernah dipakai di akun itu tampil sebagai tombol, cukup diketuk.
- **Tombol − / +** di HP untuk Hasil jadi, Reject, FG Terverifikasi, Reject/Hilang, Jumlah Dipacking, jumlah Serah terima, dan jumlah barang di Portal Driver.
- **Alasan reject sekali ketuk** di kolom Catatan/Keterangan: Ukuran tidak sesuai · Rusak/penyok · Gosong · Bentuk tidak sesuai · Kemasan rusak · Hilang. Alasan jadi seragam, sehingga nanti bisa dihitung di laporan.
- **"Biasa dibuat":** di lembar produksi, produk yang paling sering dibuat divisi itu dalam 30 hari terakhir tampil sebagai tombol, sekali ketuk langsung masuk lembar.

## Cara pasang
1. **Backup database** lewat phpMyAdmin → Export (sekali ini masih manual).
2. Upload `amor-factory-hari-ini.zip` ke `public_html/factory/`, lalu **Extract** (timpa file lama).
3. Login Admin. Halaman **Hari Ini** langsung muncul.
4. Buka **Pengaturan → Sistem** → tekan **Backup sekarang**, lalu **Unduh** filenya sekali untuk memastikan berhasil.
5. (Disarankan) pasang Cron Job harian di cPanel dengan perintah yang tertera di halaman Sistem.

## Catatan
- Tidak ada perubahan database. Semua data lama tetap.
- Backup dan catatan error disimpan di `api/app/storage/`. Folder ini tertutup dari internet dan tidak ikut terhapus saat extract paket baru.
