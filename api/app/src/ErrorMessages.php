<?php

declare(strict_types=1);

namespace Amor\Api;

/**
 * Turns the English developer-facing ApiException messages into plain
 * Indonesian for the people who actually see them (Admin, divisi, FG,
 * driver, toko) — applied once, in ErrorHandler, so the error CODE every
 * caller and test relies on never changes. Messages that are already in
 * Indonesian are passed through untouched.
 *
 * Templates use {1}, {2}, ... for the numbers found in the original
 * message, in order, after any leading "Product 12:" / "Item #3:" prefix
 * is dropped (an internal id means nothing to the reader).
 */
final class ErrorMessages
{
    private const GENERIC_BY_STATUS = [
        400 => 'Data yang dikirim belum lengkap atau tidak valid. Periksa kembali isian Anda.',
        401 => 'Sesi Anda sudah berakhir. Silakan login kembali.',
        403 => 'Anda tidak memiliki akses untuk tindakan ini.',
        404 => 'Data tidak ditemukan. Muat ulang halaman lalu coba lagi.',
        405 => 'Tindakan ini tidak tersedia.',
        409 => 'Data sudah berubah. Muat ulang halaman lalu coba lagi.',
        422 => 'Data master belum lengkap untuk tindakan ini. Hubungi Admin.',
        500 => 'Terjadi kesalahan pada sistem. Coba lagi beberapa saat lagi.',
    ];

    private const TEMPLATES = [
        'ACCOUNT_INACTIVE' => 'Akun ini sudah dinonaktifkan. Hubungi Admin.',
        'ALIAS_ALREADY_MAPPED' => 'Nama ini sudah dipetakan ke produk lain.',
        'ALREADY_DISPOSED' => 'Reject ini sudah diputuskan sebelumnya.',
        'ALREADY_MAPPED' => 'Pemetaan ini sudah diselesaikan sebelumnya.',
        'CANNOT_CANCEL_SHIPPED' => 'DO ini sudah ada pengirimannya, jadi tidak bisa dibatalkan.',
        'CATALOG_ITEM_NOT_FOUND' => 'Item katalog khusus tidak ditemukan atau sudah tidak aktif.',
        'CATALOG_ITEM_REQUIRED' => 'Pilih item katalog untuk pesanan khusus.',
        'CSRF_TOKEN_INVALID' => 'Sesi halaman sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.',
        'CUSTOMER_NAME_REQUIRED' => 'Nama pelanggan wajib diisi untuk Pesanan Non-Toko.',
        'CUSTOM_ITEM_NOT_ALLOCATABLE' => 'Item khusus/custom tidak bisa diambil dari stok FG umum.',
        'DIVISION_ACCESS_DENIED' => 'Akun Anda tidak memiliki akses ke divisi ini.',
        'DIVISION_NOT_FOUND' => 'Divisi tidak ditemukan.',
        'DIVISION_OUT_OF_SCOPE' => 'Divisi FG & Packing tidak memakai lembar kerja Produksi.',
        'DUPLICATE_PRODUCT_NAME' => 'Produk dengan nama ini sudah ada.',
        'DUPLICATE_STORE_NAME' => 'Toko dengan nama ini sudah ada.',
        'EMPTY_FG_BATCH' => 'Dokumen FG ini belum berisi produk, jadi belum ada yang bisa disubmit.',
        'EMPTY_PRODUCTION' => 'Dokumen produksi ini belum berisi produk, jadi belum ada yang bisa disubmit.',
        'EMPTY_SHIPMENT' => 'Tidak ada jumlah yang tersisa untuk dikirim.',
        'EXCEEDS_AVAILABLE' => 'Jumlah yang diminta ({1}) melebihi yang siap dikirim saat ini ({2}).',
        'EXCEEDS_FREE_FG' => 'Jumlah yang diminta ({1}) melebihi stok FG umum yang bebas ({2}).',
        'EXCEEDS_ORDER_REMAINING' => 'Jumlah yang diminta ({1}) melebihi sisa pesanan ({2}).',
        'EXCEEDS_PLANNED' => 'Jumlah yang diminta ({1}) melebihi sisa rencana kirim ({2}).',
        'EXCEEDS_REMAINING' => 'Jumlah yang diminta ({1}) melebihi sisa ({2}).',
        'FACTORY_ACCESS_DENIED' => 'Akun Anda tidak memiliki akses ke pabrik ini.',
        'FACTORY_NOT_FOUND' => 'Pabrik tidak ditemukan.',
        'FG_BELOW_SHIPPED' => 'Jumlah FG terverifikasi tidak boleh lebih kecil dari yang sudah dikirim ({1}).',
        'FG_EXCEEDS_PRODUCTION' => 'FG terverifikasi ({1}) tidak boleh melebihi hasil produksi ({2}).',
        'FORBIDDEN' => 'Anda tidak memiliki akses untuk tindakan ini.',
        'INSUFFICIENT_ALLOCATION' => 'Jumlah yang diminta ({1}) melebihi alokasi stok FG yang tersedia.',
        'INSUFFICIENT_FG_AVAILABLE' => 'Stok FG tidak cukup: diminta {1}, tersedia {2}.',
        'INSUFFICIENT_PHYSICAL_FG' => 'Stok fisik FG tidak cukup: dikirim {1}, stok fisik {2}.',
        'INSUFFICIENT_STORE_READY_FG' => 'Stok siap kirim untuk toko ini tidak cukup: diminta {1}, siap {2}.',
        'INVALID_ACTUAL_QTY' => 'Jumlah hasil produksi tidak boleh negatif.',
        'INVALID_APPROVED_QTY' => 'Jumlah yang disetujui harus antara 0 dan jumlah reject yang dilaporkan ({1}).',
        'INVALID_CHARGE' => 'Biaya tambahan tidak boleh negatif.',
        'INVALID_CREDENTIALS' => 'Username atau password salah.',
        'INVALID_DATE' => 'Tanggal tidak valid.',
        'INVALID_EXTRA_PACKAGING' => 'Jumlah kemasan tambahan tidak boleh negatif.',
        'INVALID_FG_QTY' => 'Jumlah FG terverifikasi harus antara 0 dan hasil produksi.',
        'INVALID_ORDER_STATUS' => 'Pesanan yang sudah selesai atau dibatalkan tidak bisa diambilkan stok FG.',
        'INVALID_QTY' => 'Jumlah tidak boleh negatif.',
        'INVALID_REJECT_QTY' => 'Jumlah reject tidak boleh negatif.',
        'INVALID_REQUIRED_TIME' => 'Format jam harus JJ:MM.',
        'INVALID_STATUS' => 'Dokumen ini sudah tidak bisa diubah pada status saat ini. Muat ulang halaman.',
        'ITEMS_REQUIRED' => 'Minimal satu item wajib diisi.',
        'MISSING_CANONICAL_NAME' => 'Nama toko wajib diisi.',
        'MISSING_CREDENTIALS' => 'Username dan password wajib diisi.',
        'MISSING_NAME' => 'Nama wajib diisi.',
        'MISSING_NOTES' => 'Catatan wajib diisi.',
        'MIXED_FACTORY_SHIPMENT' => 'Satu pengiriman tidak boleh berisi produk dari pabrik berbeda. Buat pengiriman terpisah per pabrik.',
        'NEGATIVE_QTY' => 'Jumlah tidak boleh negatif.',
        'NO_ELIGIBLE_PRODUCTION' => 'Belum ada hasil produksi yang disubmit untuk pabrik dan tanggal ini.',
        'NO_PO_DEMAND' => 'Belum ada PO untuk toko dan tanggal ini, jadi DO belum bisa dibuat.',
        'NO_REJECT_TO_DISPOSE' => 'Baris ini tidak memiliki reject.',
        'PACKED_EXCEEDS_VERIFIED' => 'Jumlah packing ({1}) tidak boleh melebihi FG terverifikasi ({2}).',
        'PRODUCT_DIVISION_MISMATCH' => 'Produk ini bukan milik divisi pada dokumen ini.',
        'PRODUCT_DIVISION_MISSING' => 'Produk ini belum memiliki divisi produksi di Master Data.',
        'PRODUCT_FACTORY_UNKNOWN' => 'Produk ini belum memiliki divisi/pabrik di Master Data.',
        'PRODUCT_FACTORY_UNRESOLVED' => 'Produk ini belum memiliki divisi/pabrik di Master Data.',
        'PRODUCT_NOT_FOUND' => 'Produk tidak ditemukan atau sudah tidak aktif.',
        'PRODUCT_REQUIRED' => 'Pilih produk.',
        'REASON_REQUIRED' => 'Alasan wajib diisi.',
        'RECEIPT_NOT_VERIFIED' => 'Selisih penerimaan ini harus diverifikasi Admin dulu sebelum diputuskan.',
        'REPLACEMENT_REQUIRES_PRODUCT' => 'Item khusus tanpa produk tidak bisa dikirim ulang lewat alur ini.',
        'SESUAI_ACTUAL_MISMATCH' => 'Status Sesuai berarti hasil ({1}) harus sama dengan target ({2}).',
        'STILL_NEEDS_PRODUCTION' => 'Kebutuhan ini belum tercukupi oleh stok FG dan hasil produksi.',
        'STORE_FG_EXCEEDS_TARGET' => 'FG terverifikasi ({1}) tidak boleh melebihi target toko ({2}).',
        'STORE_NOT_FOUND' => 'Toko tidak ditemukan.',
        'STORE_REQUIRED' => 'Pilih toko untuk Pesanan Khusus Toko.',
        'UNAUTHENTICATED' => 'Sesi Anda sudah berakhir. Silakan login kembali.',
        'UNKNOWN_ITEM_FOR_ORDER' => 'Item ini bukan bagian dari pesanan tersebut.',
        'UNKNOWN_PRODUCT_FOR_BATCH' => 'Produk ini belum ada di dokumen FG. Klik "Refresh Produksi Terbaru" dulu.',
        'UNKNOWN_PRODUCT_FOR_DO' => 'Produk ini bukan bagian dari DO tersebut.',
        'UNKNOWN_PRODUCT_FOR_RUN' => 'Produk ini belum ada di dokumen produksi. Segarkan target dari PO dulu.',
        'VERSION_CONFLICT' => 'Data ini baru saja diubah oleh pengguna lain. Muat ulang halaman lalu coba lagi.',
    ];

    /** Words that mark a message as already Indonesian — those pass through unchanged. */
    private const INDONESIAN_MARKERS = '/\b(tidak|wajib|sudah|belum|harus|hanya|untuk|pada|tersedia|ditemukan|silakan|melebihi|gagal|berhasil|dulu|bisa|minimal|maksimal|pilih|isi|kosong|menunggu|bukan|tambahkan|rentang|ukuran)\b/i';

    public static function localize(int $status, string $code, string $message): string
    {
        if ($message !== '' && preg_match(self::INDONESIAN_MARKERS, $message) === 1) {
            return $message;
        }
        $template = self::TEMPLATES[$code] ?? null;
        if ($template === null) {
            return self::GENERIC_BY_STATUS[$status] ?? self::GENERIC_BY_STATUS[400];
        }
        // Keep a human "Produk X / Toko Y:" prefix; drop an internal-id one.
        $prefix = '';
        if (preg_match('/^(Produk [^:]+?(?: \/ Toko [^:]+?)?):\s*/u', $message, $pm) === 1) {
            $prefix = $pm[1] . ': ';
            $message = substr($message, strlen($pm[0]));
        }
        $body = preg_replace('/^(Product|Item)\s*#?\d+\s*:?\s*/i', '', $message) ?? $message;
        preg_match_all('/-?\d+(?:[.,]\d+)?/', $body, $m);
        $numbers = $m[0];
        $out = preg_replace_callback('/\{(\d)\}/', static function (array $x) use ($numbers): string {
            $i = (int) $x[1] - 1;
            return $numbers[$i] ?? '-';
        }, $template) ?? $template;
        return $prefix . $out;
    }
}
