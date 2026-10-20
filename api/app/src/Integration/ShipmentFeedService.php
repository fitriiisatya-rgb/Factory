<?php

declare(strict_types=1);

namespace Amor\Api\Integration;

use Amor\Api\ApiException;
use Amor\Api\Dispatch\ReceiptService;
use PDO;

/**
 * Read-only feed of departed shipments and what each store confirmed, for
 * finhub (Runchise "stok masuk" + per-branch stock monitoring). Item
 * figures come from ReceiptService::getPortalShipmentDetail() — exactly
 * what the store portal shows — so the two can never disagree.
 */
final class ShipmentFeedService
{
    private const MAX_DAYS = 31;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{from:string,to:string,shipments:array<int,array>} */
    public function list(string $from, string $to, ?int $storeId): array
    {
        foreach (['from' => $from, 'to' => $to] as $k => $v) {
            $d = \DateTime::createFromFormat('Y-m-d', $v);
            if ($d === false || $d->format('Y-m-d') !== $v) {
                throw new ApiException(400, 'INVALID_DATE', "Parameter {$k} harus berformat YYYY-MM-DD.");
            }
        }
        if ($from > $to) {
            throw new ApiException(400, 'INVALID_RANGE', 'Parameter from tidak boleh setelah to.');
        }
        if ((new \DateTime($from))->diff(new \DateTime($to))->days >= self::MAX_DAYS) {
            throw new ApiException(400, 'RANGE_TOO_LONG', 'Rentang maksimal ' . self::MAX_DAYS . ' hari per permintaan.');
        }

        $sql = 'SELECT sh.shipment_id, sh.store_id, sh.no_sj, sh.source_type, sh.tanggal, sh.shipped_at,
                       s.canonical_name AS store_name, f.name AS factory_name,
                       r.status AS receipt_status, r.confirmed_at, r.verified_at,
                       sh.runchise_downloaded_at, s.runchise_location
                FROM shipment sh
                INNER JOIN store s ON s.store_id = sh.store_id
                LEFT JOIN factory f ON f.factory_id = sh.factory_id
                LEFT JOIN shipment_receipt r ON r.shipment_id = sh.shipment_id
                WHERE sh.tanggal BETWEEN ? AND ? AND sh.status = \'active\' AND sh.voided_at IS NULL AND sh.shipped_at IS NOT NULL';
        $params = [$from, $to];
        if ($storeId !== null) {
            $sql .= ' AND sh.store_id = ?';
            $params[] = $storeId;
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY sh.tanggal, s.canonical_name, sh.shipment_id');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $codes = $this->legacyCodes();
        $receipts = new ReceiptService($this->pdo);
        $out = [];
        foreach ($rows as $r) {
            $detail = $receipts->getPortalShipmentDetail((int) $r['store_id'], (int) $r['shipment_id']);
            $status = $r['receipt_status'] ?? 'pending';
            $confirmed = !in_array($status, ['pending', 'belum_dikonfirmasi'], true);
            $out[] = [
                'shipmentId' => (int) $r['shipment_id'],
                'tanggal' => $r['tanggal'],
                'departedAt' => $r['shipped_at'],
                'noSj' => $r['no_sj'],
                'docNo' => $detail['docNo'],
                'sourceType' => $r['source_type'],
                'factory' => $r['factory_name'],
                'store' => ['id' => (int) $r['store_id'], 'name' => $r['store_name']],
                'receipt' => [
                    'status' => $status,
                    'confirmed' => $confirmed,
                    'confirmedAt' => $r['confirmed_at'],
                    'receiverName' => $detail['receiverName'],
                    'verifiedByAdmin' => $r['verified_at'] !== null,
                    'verifiedAt' => $r['verified_at'],
                ],
                // Migration 0024: when the store/admin downloaded the Runchise
                // "Impor Stok Masuk" file in Factory (UTC), and the mapped location.
                'runchiseDownloadedAt' => $r['runchise_downloaded_at'] !== null ? str_replace(' ', 'T', (string) $r['runchise_downloaded_at']) . 'Z' : null,
                'runchiseLocation' => $r['runchise_location'],
                'items' => array_map(static fn ($i) => [
                    'productId' => $i['productId'],
                    'productCode' => $codes[(int) $i['productId']] ?? null,
                    'productName' => $i['productName'],
                    'shippedQty' => (float) $i['shippedQty'],
                    'receivedGoodQty' => $confirmed ? (float) ($i['receivedGoodQty'] ?? 0) : null,
                    'rejectQty' => $confirmed ? (float) ($i['rejectQty'] ?? 0) : null,
                    'shortageQty' => $confirmed ? (float) ($i['shortageQty'] ?? 0) : null,
                ], $detail['items']),
            ];
        }
        return ['from' => $from, 'to' => $to, 'shipments' => $out];
    }

    /** @return array<int,string> product_id => first legacy code (the factory's own product code) */
    private function legacyCodes(): array
    {
        $map = [];
        foreach ($this->pdo->query('SELECT product_id, MIN(legacy_code) AS code FROM product_legacy_code GROUP BY product_id')->fetchAll() as $r) {
            $map[(int) $r['product_id']] = $r['code'];
        }
        return $map;
    }
}
