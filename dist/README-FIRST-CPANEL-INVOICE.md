# Amor Factory System — Invoice (migrasi 0018) — cPanel, Non-Teknis

**Baca ini sebelum melakukan apa pun.** Panduan ini untuk siapa saja, walau
tidak familiar dengan database atau coding.

**Paket ini MEMBUTUHKAN migrasi database baru (migrasi 0018).** Migrasi ini
hanya **menambah 1 tabel baru** (`invoice_mutasi`) — **tidak ada tabel yang
dihapus, tidak ada data yang dihapus, tidak ada perubahan pada
tabel/alur yang sudah ada (migrasi 0001–0017 sama sekali tidak
disentuh).**

**Path yang BENAR di server Anda**: `public_html/factory/` (bukan
`public_html/` itu sendiri).

---

## Apa yang baru: Invoice dihitung OTOMATIS, bukan diketik manual

Sebelumnya, menu Invoice hanya tampilan contoh (data palsu untuk
keperluan desain cetak). Sekarang Invoice benar-benar dihitung dari data
Pengiriman dan Mutasi Produk yang sudah terjadi — Admin tinggal pilih
**toko** dan **periode tanggal**, sistem menghitung sendiri apa yang bisa
ditagihkan.

### Bagaimana cara hitungnya

- **Jumlah tertagih per produk** = total yang **sudah dikonfirmasi
  diterima toko** (lewat Konfirmasi Penerimaan / Portal Bakery) dalam
  periode yang dipilih.
- **Reject otomatis mengurangi tagihan** — jumlah yang direject toko
  tidak pernah ikut tertagih, tanpa perlu Admin mengurangi manual.
  Catatan: jika ada Reject, pengiriman itu baru bisa ditagihkan setelah
  Admin memverifikasi selisihnya di menu "Konfirmasi Toko" (selisih yang
  belum diverifikasi belum bisa ditagihkan).
- **Mutasi Produk antar toko juga mempengaruhi tagihan** — jika Toko A
  memutasi barang ke Toko B dan sudah **Selesai**, jumlah itu otomatis
  **berpindah** dari tagihan Toko A ke tagihan Toko B (tidak pernah
  dobel tertagih, tidak pernah hilang).
- **Satu Invoice bisa menggabungkan banyak Pengiriman** untuk satu toko
  dalam satu periode — tidak harus satu Invoice per pengiriman.
- Sebuah Pengiriman atau Mutasi yang **sudah pernah masuk ke satu
  Invoice tidak akan pernah ikut tertagih lagi** di Invoice lain,
  kecuali Invoice-nya dibatalkan.

---

## Cara pakai menu "Invoice" (Admin)

1. Buka menu **Invoice** di sidebar Admin.
2. Di bagian "Buat Invoice Baru": pilih **Toko**, **Tanggal Awal**, dan
   **Tanggal Akhir**, lalu klik **Pratinjau** — sistem menampilkan
   daftar produk, jumlah, harga, dan total TANPA menyimpan apa pun.
3. Jika sudah sesuai, klik **Generate Invoice** — Invoice resmi dibuat
   dengan nomor otomatis (format sama seperti nomor DO, misalnya
   `INV/KRM/001/X/2026`), dan setiap Pengiriman/Mutasi yang tercakup
   langsung ditandai sudah ditagihkan.
4. Dari daftar Invoice atau halaman detail, klik **Print Invoice** untuk
   cetak/PDF — tampilannya memakai desain cetak yang sama persis dengan
   yang sudah pernah dibuat sebelumnya, sekarang berisi data asli.
5. Jika ada kesalahan (salah toko/periode), klik **Batalkan Invoice** di
   halaman detail — Invoice dihapus permanen dan setiap
   Pengiriman/Mutasi yang tadinya tercakup otomatis bisa ditagihkan lagi
   di Invoice berikutnya.

---

## Laporan: 2 bagian baru

Menu **Laporan** sekarang punya 2 bagian tambahan di bagian bawah,
masing-masing dengan filter tanggal sendiri (terpisah dari filter
tanggal/pabrik di bagian atas):

- **Laporan Omset / Penjualan per Toko & Periode** — total nilai dan
  jumlah Invoice per toko, dihitung langsung dari Invoice yang sudah
  dibuat (bukan perkiraan dari data pengiriman).
- **Laporan Retur & Reject per Periode** — ringkasan Retur yang sudah
  diverifikasi dan Reject yang dilaporkan toko per toko, termasuk status
  keputusan Reject (Reject Final / Kirim Ulang / Belum Diputuskan).

Laporan Piutang/Pembayaran **belum tersedia** — ini menunggu fase
berikutnya (tabel `payment` belum dipakai di fase ini).

---

## Cara pasang (cPanel, tanpa command line)

1. **Backup dulu.** Di cPanel → phpMyAdmin, export (backup) database Anda
   saat ini. Ini WAJIB sebelum migrasi apa pun.
2. **Upload & extract.** Upload `amor-factory-invoice.zip` ke
   `public_html/factory/`, lalu extract — ini akan MENIMPA file lama
   dengan versi baru (aman, tidak menghapus folder `api/app/config/`).
3. **Jalankan migrasi.** Buka `https://domainanda.com/factory/api/_upgrade/`
   di browser (login sebagai Admin dulu jika diminta), lalu jalankan
   migrasi hingga selesai (akan menunjukkan migrasi 0018 sebagai migrasi
   baru yang diterapkan — migrasi 0001–0017 akan ditandai "sudah
   diterapkan sebelumnya", TIDAK dijalankan ulang).
4. **Coba langsung.** Login sebagai Admin → menu "Invoice" → pilih toko
   yang sudah punya Pengiriman terkonfirmasi di suatu periode → klik
   Pratinjau → pastikan angkanya masuk akal → Generate Invoice → Print
   Invoice untuk cek tampilan cetaknya.
5. **Cek Laporan** → menu "Laporan" → gulir ke bawah ke bagian "Laporan
   Omset" dan "Laporan Retur & Reject" → pastikan angkanya sesuai dengan
   Invoice yang baru dibuat.

---

## Yang TIDAK berubah

- Semua alur PO Reguler, Produksi, FG & Packing, Delivery Order,
  Pengiriman, Konfirmasi Toko, Portal Bakery (Retur/Mutasi/Pesanan
  Khusus), Driver Portal, dan email otomatis — semuanya bekerja PERSIS
  seperti sebelumnya.
- Halaman pratinjau cetak Invoice yang lama (data contoh/mock, untuk
  keperluan desain) tetap ada dan tidak diubah — hanya digantikan
  perannya oleh menu Invoice yang baru dan nyata.
- Tidak ada perubahan pada tabel/kolom manapun dari migrasi 0001–0017.
- Menu Invoice (generate/print/batalkan) tetap **khusus Admin** —
  peran lain tidak akan melihatnya di sidebar maupun bisa mengaksesnya.

---

## Keamanan

Menu Invoice (list, pratinjau, generate, detail, print, batalkan) hanya
bisa diakses oleh akun dengan peran ADMIN — dicek di server pada setiap
permintaan, bukan hanya disembunyikan di tampilan. Setiap aksi yang
mengubah data (Generate/Batalkan) memakai Idempotency-Key yang sama
seperti alur lain di aplikasi ini (double-klik tidak pernah membuat
Invoice dobel), token CSRF, query database dengan parameter aman, dan
catatan audit — semuanya tetap berjalan seperti sebelumnya, diperluas ke
alur baru ini tanpa dilonggarkan sedikit pun.
