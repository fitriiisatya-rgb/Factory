# Integrasi factory → finhub (hanya baca)

Toko mengonfirmasi penerimaan **satu kali**, di factory: lewat portal toko,
atau scan QR di surat jalan. finhub **membaca** hasilnya untuk membuat file
Stok Masuk Runchise dan memantau stok cabang. finhub tidak perlu lagi
upload file DO `.xlsx` atau konfirmasi QR sendiri untuk kiriman pabrik.

## Kunci API

Admin factory: **Pengaturan → Integrasi finhub → Buat Kunci Baru**. Kunci
(`amk_…`) hanya tampil sekali, jadi simpan di konfigurasi server finhub,
bukan di kode browser. Kunci bisa dicabut kapan saja dari halaman yang sama.

## Permintaan

```
GET https://factory.amorgroup.id/api/integration/v1/shipments?from=2026-10-01&to=2026-10-01
X-Api-Key: amk_xxxxxxxx
```

| Parameter | Wajib | Keterangan |
|---|---|---|
| `from` | ya | Tanggal kiriman (YYYY-MM-DD) |
| `to` | tidak | Default = `from`. Rentang maksimal 31 hari |
| `storeId` | tidak | Satu toko saja (ID toko factory) |

Header alternatif: `Authorization: Bearer amk_…`.
Hanya `GET`. Kunci tidak bisa dipakai untuk mengubah data apa pun.

## Jawaban

```json
{
  "ok": true,
  "data": {
    "from": "2026-10-01",
    "to": "2026-10-01",
    "shipments": [
      {
        "shipmentId": 109,
        "tanggal": "2026-10-01",
        "departedAt": "2026-10-18 10:00:51",
        "noSj": null,
        "docNo": "DO/KRM/001/X/2026",
        "sourceType": "delivery_order",
        "factory": "Karangtengah",
        "store": { "id": 3, "name": "Bakery Abdul Gani" },
        "receipt": {
          "status": "confirmed_ok",
          "confirmed": true,
          "confirmedAt": "2026-10-18 10:01:06",
          "receiverName": "Rina",
          "verifiedByAdmin": false,
          "verifiedAt": null
        },
        "items": [
          { "productId": 185, "productCode": null, "productName": "BOLLEN LILIT COKLAT",
            "shippedQty": 2, "receivedGoodQty": 2, "rejectQty": 0, "shortageQty": 0 }
        ]
      }
    ]
  }
}
```

- Waktu (`departedAt`, `confirmedAt`, `verifiedAt`) dalam **UTC**.
- `receipt.confirmed = false` berarti toko belum konfirmasi. Selama itu
  `receivedGoodQty`, `rejectQty`, dan `shortageQty` bernilai `null`.
- Status: `pending`/`belum_dikonfirmasi` (belum), `confirmed_ok` (semua baik),
  `confirmed_discrepancy` (ada reject/kurang), `verified` (selisih sudah
  diverifikasi Admin factory).
- **Untuk Stok Masuk Runchise pakai `receivedGoodQty`.** Reject dan kurang
  kirim tidak masuk stok cabang.
- `sourceType`: `delivery_order` (kiriman PO harian), `special_order_do`
  (pesanan khusus), `replacement_do` (kirim ulang pengganti reject).
- Satu toko bisa punya lebih dari satu surat jalan per hari (misalnya
  grup MAIN dan PASTRY). Kunci unik per baris: `shipmentId`.
- Nama toko adalah nama di master factory (misalnya "Bakery Sudirman").
  Pemetaan ke lokasi Runchise tetap di finhub.

## Kode error

| HTTP | `code` | Arti |
|---|---|---|
| 401 | `MISSING_API_KEY` | Header kunci tidak dikirim |
| 401 | `INVALID_API_KEY` | Kunci salah atau sudah dicabut |
| 400 | `INVALID_DATE` / `INVALID_RANGE` / `RANGE_TOO_LONG` | Tanggal tidak valid, atau rentang lebih dari 31 hari |

## Saran pemakaian di finhub

1. Ganti "Upload file DO pabrik" dengan tombol **Ambil dari factory** untuk tanggal terpilih.
2. Simpan per `shipmentId`. Ambil ulang aman (tidak dobel) dan memperbarui status konfirmasi.
3. Buat file impor Runchise hanya dari surat jalan dengan `receipt.confirmed = true`.
4. QR dan konfirmasi di finhub tidak dipakai lagi untuk kiriman pabrik, supaya toko tidak konfirmasi dua kali.
