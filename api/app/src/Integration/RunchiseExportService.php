<?php

declare(strict_types=1);

namespace Amor\Api\Integration;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Dispatch\ReceiptService;
use PDO;

/**
 * "Impor Stok Masuk" CSV for Runchise — one file per confirmed surat jalan
 * (migration 0024). Quantity = what the store confirmed as received good;
 * reject and shortage never go in. Products are mapped through the stored
 * product.runchise_* columns (never fuzzy-matched here); several factory
 * products mapped to one Runchise SKU are summed (donut ring → DONUT
 * PLAIN). Anything not in the file is listed with its reason, so nothing
 * disappears silently.
 *
 * The file format matches FinHub's own Runchise import file exactly —
 * FinHub detects "already in Runchise" from Alasan = "DO <docNo>", so that
 * text must never change.
 */
final class RunchiseExportService
{
    public const LOCATIONS = [
        'ABDUL GANI', 'BANGBARUNG', 'BANTARGEBANG', 'BAROS', 'BENDA', 'BENDHIL', 'BOJONGLOPANG', 'CIBADAK', 'CIBINONG',
        'CIBUBUR', 'CICURUG', 'CIKARET', 'CIKEMBANG', 'CIKOLE', 'CIPAYUNG', 'CISAAT', 'DRAMAGA', 'HARJAMUKTI', 'JATIMELATI',
        'KEJAYAAN', 'LEMBURSITU', 'MEKARSARI', 'NUSA INDAH', 'PAGELARAN', 'PAMURUYAN', 'PANGLESERAN', 'PARUNGKUDA',
        'PEKAPURAN', 'PERUMNAS', 'PLARA', 'PONDOK UNGU', 'RAWALUMBU', 'RTM DEPOK', 'SESEUPAN', 'SUDIRMAN', 'SUKALARANG',
        'Sukahati', 'VILLA BOGOR INDAH', 'AMOR CAKES BAKERY BANGBARUNG',
    ];
    private const HEADER = ['Nama Lokasi', 'Tanggal Stok', 'Alasan', 'Nama Produk', 'SKU Produk', 'Jumlah', 'Nama Unit Produk', 'Biaya per unit'];
    private const CONFIRMED = ['confirmed_ok', 'confirmed_discrepancy', 'verified'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Preview + file content for one shipment of $storeId. Never writes.
     * ready=false (with message) when the file cannot be made yet.
     */
    public function build(int $storeId, int $shipmentId): array
    {
        $detail = (new ReceiptService($this->pdo))->getPortalShipmentDetail($storeId, $shipmentId);
        $sh = $this->pdo->prepare(
            'SELECT sh.tanggal, sh.runchise_downloaded_at, sh.runchise_downloaded_by, sh.runchise_download_count,
                    s.runchise_location, s.canonical_name, r.confirmed_at, r.verified_at, r.updated_at AS receipt_updated_at
             FROM shipment sh INNER JOIN store s ON s.store_id = sh.store_id
             LEFT JOIN shipment_receipt r ON r.shipment_id = sh.shipment_id
             WHERE sh.shipment_id = ?'
        );
        $sh->execute([$shipmentId]);
        $row = $sh->fetch(PDO::FETCH_ASSOC);

        $docNo = trim((string) ($detail['docNo'] ?? ''));
        $alasan = $docNo !== '' ? 'DO ' . $docNo : 'DO SJ-' . $shipmentId;
        $location = $row['runchise_location'] !== null && $row['runchise_location'] !== '' ? (string) $row['runchise_location'] : null;
        $tanggal = (string) $row['tanggal'];
        $base = [
            'shipmentId' => $shipmentId, 'docNo' => $docNo, 'storeName' => (string) $row['canonical_name'],
            'location' => $location, 'tanggal' => $tanggal, 'alasan' => $alasan,
            'downloadedAt' => $row['runchise_downloaded_at'], 'downloadedBy' => $row['runchise_downloaded_by'],
            'downloadCount' => (int) $row['runchise_download_count'],
            'changedSinceDownload' => $row['runchise_downloaded_at'] !== null
                && max((string) $row['confirmed_at'], (string) $row['verified_at'], (string) $row['receipt_updated_at']) > (string) $row['runchise_downloaded_at'],
            'lines' => [], 'excluded' => [], 'totalQty' => 0.0, 'ready' => false, 'message' => null,
        ];
        if (!in_array($detail['receiptStatus'] ?? 'pending', self::CONFIRMED, true)) {
            return ['message' => 'Surat jalan ini belum dikonfirmasi toko.'] + $base;
        }
        if ($location === null) {
            return ['message' => 'Lokasi Runchise belum diatur untuk toko ini. Hubungi Admin pabrik.'] + $base;
        }

        $productIds = array_values(array_filter(array_map(static fn ($i) => $i['productId'] !== null ? (int) $i['productId'] : null, $detail['items'])));
        $map = [];
        if ($productIds !== []) {
            $in = implode(',', array_fill(0, count($productIds), '?'));
            $st = $this->pdo->prepare("SELECT product_id, runchise_sku, runchise_name, runchise_unit, runchise_mode FROM product WHERE product_id IN ({$in})");
            $st->execute($productIds);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $map[(int) $p['product_id']] = $p;
            }
        }

        $lines = [];
        $excluded = [];
        foreach ($detail['items'] as $it) {
            $qty = (float) ($it['receivedGoodQty'] ?? 0);
            if ($qty <= 0.0001) {
                continue;
            }
            $p = $it['productId'] !== null ? ($map[(int) $it['productId']] ?? null) : null;
            $mode = $p['runchise_mode'] ?? null;
            if ($mode === 'stok' && ($p['runchise_sku'] ?? '') !== '') {
                $sku = (string) $p['runchise_sku'];
                if (!isset($lines[$sku])) {
                    $lines[$sku] = ['name' => (string) ($p['runchise_name'] ?: $it['productName']), 'sku' => $sku, 'qty' => 0.0, 'unit' => (string) ($p['runchise_unit'] ?: 'PCS'), 'from' => []];
                }
                $lines[$sku]['qty'] += $qty;
                $lines[$sku]['from'][] = (string) $it['productName'];
                continue;
            }
            $reason = match ($mode) {
                'topping' => 'topping (bukan stok)',
                'abaikan' => 'bukan stok Runchise',
                default => 'belum dipetakan ke produk Runchise',
            };
            $excluded[] = ['name' => (string) $it['productName'], 'qty' => $qty, 'reason' => $reason];
        }
        $lines = array_values($lines);
        $total = array_sum(array_map(static fn ($l) => $l['qty'], $lines));
        if ($lines === []) {
            return ['excluded' => $excluded, 'message' => 'Tidak ada produk yang bisa masuk file: semua jumlah baik 0 atau produknya belum dipetakan ke Runchise.'] + $base;
        }
        return ['lines' => $lines, 'excluded' => $excluded, 'totalQty' => $total, 'ready' => true,
            'filename' => $this->filename($location, $tanggal, $docNo !== '' ? $docNo : 'SJ-' . $shipmentId)] + $base;
    }

    /** The CSV text: UTF-8 without BOM, comma, CRLF; location/date/alasan on the first data row only. */
    public function csv(array $built): string
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $built['tanggal']);
        $rows = [self::HEADER];
        foreach ($built['lines'] as $i => $l) {
            $rows[] = [
                $i === 0 ? $built['location'] : '', $i === 0 ? $d->format('d/m/Y') : '', $i === 0 ? $built['alasan'] : '',
                $l['name'], $l['sku'], self::num($l['qty']), $l['unit'], '',
            ];
        }
        return implode('', array_map(static fn ($r) => implode(',', array_map([self::class, 'cell'], $r)) . "\r\n", $rows));
    }

    /** Records the download (who/when/how many times) and returns [filename, csv]. */
    public function download(int $storeId, int $shipmentId, string $by, ?int $userId, ?string $requestId): array
    {
        $built = $this->build($storeId, $shipmentId);
        if (!$built['ready']) {
            throw new ApiException(400, 'RUNCHISE_NOT_READY', (string) $built['message']);
        }
        $this->pdo->prepare('UPDATE shipment SET runchise_downloaded_at = UTC_TIMESTAMP(), runchise_downloaded_by = ?, runchise_download_count = runchise_download_count + 1 WHERE shipment_id = ?')
            ->execute([mb_substr($by, 0, 150), $shipmentId]);
        Audit::write($this->pdo, $requestId, $userId, 'runchise.downloaded', 'shipment', (string) $shipmentId, 'ok', null, null,
            ['by' => $by, 'location' => $built['location'], 'lines' => count($built['lines']), 'totalQty' => $built['totalQty'], 'previousDownloads' => $built['downloadCount']]);
        return [$built['filename'], $this->csv($built)];
    }

    private function filename(string $location, string $tanggal, string $docNo): string
    {
        $name = 'stok_masuk_' . str_replace(' ', '', $location) . '_' . $tanggal . '_' . str_replace('/', '-', $docNo) . '.csv';
        return preg_replace('/[^A-Za-z0-9._-]/', '', $name) ?? $name;
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    private static function cell(string $v): string
    {
        return preg_match('/[",\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
    }
}
