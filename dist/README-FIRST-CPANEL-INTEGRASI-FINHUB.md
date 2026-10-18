# Amor Factory System — Integrasi finhub — cPanel, Non-Teknis

**Paket ini MEMBUTUHKAN migrasi database 0022.** Setelah extract, wajib buka
`https://factory.amorgroup.id/api/_upgrade/`.

Isinya sudah termasuk semua paket sebelumnya: Invoice per DO + Nota
Potongan, tombol Keluar, jendela user yang bisa di-scroll, dan FG & Packing
yang lebih cepat.

**Path yang BENAR**: `public_html/factory/`.

---

## Yang baru

### Konfirmasi terima cukup sekali, di factory
- Toko mengonfirmasi penerimaan di factory, lewat link portal toko atau
  **scan QR yang sudah tercetak di surat jalan**.
- finhub **membaca** hasilnya (diterima baik, reject, kurang per produk)
  untuk membuat file Stok Masuk Runchise. Upload file DO `.xlsx` dan
  konfirmasi QR di finhub tidak diperlukan lagi untuk kiriman pabrik.

### Halaman Pengaturan → Integrasi finhub (khusus Admin)
- **Buat Kunci Baru**: kunci rahasia untuk finhub. Kunci hanya tampil
  **sekali**, jadi salin dan berikan ke pembuat finhub.
- Kunci hanya bisa **membaca** data, tidak bisa mengubah apa pun.
- **Cabut** kalau kunci bocor atau tidak dipakai lagi.
- Petunjuk teknis untuk pembuat finhub ada di file `INTEGRASI-FINHUB.md`.

---

## Cara pasang
1. **Backup database** lewat phpMyAdmin → Export.
2. Upload `amor-factory-integrasi-finhub.zip` ke `public_html/factory/`, lalu
   **Extract** (timpa file lama).
3. Login Admin, buka `https://factory.amorgroup.id/api/_upgrade/`, lalu
   jalankan migrasi **0022_integration_key**.

## Yang TIDAK berubah
- Data lama tetap ada. Migrasi 0022 hanya menambah satu tabel baru.
- Alur PO, produksi, FG, DO, driver, toko, dan invoice tidak berubah.
