<?php

declare(strict_types=1);

namespace Amor\Api\Delivery;

use PDO;

/**
 * Rekonsiliasi Kirim vs FG (masa adaptasi, see System\ShipBeforeFgSwitch):
 * what Admin pressed Kirim for on regular DOs of one date + factory, next to
 * what FG packed in its own record, and what the store / Admin confirmed as
 * received. Read-only — nothing here changes stock or invoices.
 */
final class ReconciliationService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{fgStatus:?string, products:array<int,array>, rows:array<int,array>}
     */
    public function forDay(string $tanggal, int $factoryId): array
    {
        $unallocated = (new \Amor\Api\Fg\FgRepository())->unallocatedStoreId($this->pdo);

        $kirim = $this->pdo->prepare(
            "SELECT sh.store_id, si.product_id, SUM(si.qty) AS qty,
                    SUM(sri.received_good_qty) AS good, SUM(sri.reject_qty) AS reject, SUM(sri.shortage_qty) AS shortage,
                    SUM(sri.shipment_receipt_item_id IS NULL) AS unconfirmed
             FROM shipment sh
             INNER JOIN shipment_item si ON si.shipment_id = sh.shipment_id
             LEFT JOIN shipment_receipt_item sri ON sri.shipment_item_id = si.shipment_item_id
             WHERE sh.status = 'active' AND sh.delivery_order_id IS NOT NULL AND sh.tanggal = ? AND sh.factory_id = ?
             GROUP BY sh.store_id, si.product_id"
        );
        $kirim->execute([$tanggal, $factoryId]);

        $fg = $this->pdo->prepare(
            'SELECT fi.store_id, fi.product_id, SUM(fi.packed_qty) AS packed
             FROM fg_item fi INNER JOIN fg_batch b ON b.fg_batch_id = fi.fg_batch_id
             WHERE b.tanggal = ? AND b.factory_id = ?
             GROUP BY fi.store_id, fi.product_id'
        );
        $fg->execute([$tanggal, $factoryId]);

        $status = $this->pdo->prepare('SELECT status FROM fg_batch WHERE tanggal = ? AND factory_id = ? ORDER BY fg_batch_id DESC LIMIT 1');
        $status->execute([$tanggal, $factoryId]);
        $fgStatus = $status->fetchColumn();

        $cells = [];
        $products = [];
        foreach ($kirim->fetchAll() as $r) {
            $key = $r['store_id'] . ':' . $r['product_id'];
            $cells[$key] = ['storeId' => (int) $r['store_id'], 'productId' => (int) $r['product_id'], 'kirim' => (float) $r['qty'], 'fg' => null,
                'confirmed' => (int) $r['unconfirmed'] === 0, 'good' => (float) $r['good'], 'reject' => (float) $r['reject'], 'shortage' => (float) $r['shortage']];
            $products[(int) $r['product_id']]['kirim'] = ($products[(int) $r['product_id']]['kirim'] ?? 0.0) + (float) $r['qty'];
        }
        foreach ($fg->fetchAll() as $r) {
            $pid = (int) $r['product_id'];
            $products[$pid]['fg'] = ($products[$pid]['fg'] ?? 0.0) + (float) $r['packed'];
            if ((int) $r['store_id'] === $unallocated) {
                $products[$pid]['perProduk'] = true; // packed as one total, not split per store
                continue;
            }
            $key = $r['store_id'] . ':' . $pid;
            $cells[$key] ??= ['storeId' => (int) $r['store_id'], 'productId' => $pid, 'kirim' => 0.0, 'fg' => null, 'confirmed' => false, 'good' => 0.0, 'reject' => 0.0, 'shortage' => 0.0];
            $cells[$key]['fg'] = ($cells[$key]['fg'] ?? 0.0) + (float) $r['packed'];
        }

        $names = $this->names(array_keys($products), array_unique(array_column($cells, 'storeId')));
        $rows = [];
        foreach ($cells as $c) {
            $perProduk = !empty($products[$c['productId']]['perProduk']) && $c['fg'] === null;
            $diff = $c['fg'] === null ? null : $c['kirim'] - $c['fg'];
            $rows[] = $c + [
                'storeName' => $names['store'][$c['storeId']] ?? ('Toko #' . $c['storeId']),
                'productName' => $names['product'][$c['productId']] ?? ('Produk #' . $c['productId']),
                'selisih' => $diff,
                'status' => $this->status($fgStatus, $perProduk, $diff),
            ];
        }
        usort($rows, static fn ($a, $b) => [$a['storeName'], $a['productName']] <=> [$b['storeName'], $b['productName']]);

        $summary = [];
        foreach ($products as $pid => $p) {
            $k = $p['kirim'] ?? 0.0;
            $f = $p['fg'] ?? 0.0;
            $summary[] = ['productId' => $pid, 'productName' => $names['product'][$pid] ?? ('Produk #' . $pid), 'kirim' => $k, 'fg' => $f, 'selisih' => $k - $f,
                'status' => $this->status($fgStatus, false, $k - $f)];
        }
        usort($summary, static fn ($a, $b) => $a['productName'] <=> $b['productName']);

        return ['fgStatus' => $fgStatus === false ? null : (string) $fgStatus, 'products' => $summary, 'rows' => $rows];
    }

    private function status(string|false $fgStatus, bool $perProduk, ?float $diff): string
    {
        if ($fgStatus === false) {
            return 'fg_belum_input';
        }
        if ($fgStatus !== 'submitted') {
            return 'fg_belum_selesai';
        }
        if ($perProduk) {
            return 'lihat_total';
        }
        if ($diff === null || abs($diff) < 0.0001) {
            return 'cocok';
        }
        return $diff > 0 ? 'kirim_lebih' : 'fg_lebih';
    }

    /** @return array{store:array<int,string>, product:array<int,string>} */
    private function names(array $productIds, array $storeIds): array
    {
        $out = ['store' => [], 'product' => []];
        if ($productIds !== []) {
            $st = $this->pdo->query('SELECT product_id, name FROM product WHERE product_id IN (' . implode(',', array_map('intval', $productIds)) . ')');
            foreach ($st as $r) {
                $out['product'][(int) $r['product_id']] = (string) $r['name'];
            }
        }
        if ($storeIds !== []) {
            $st = $this->pdo->query('SELECT store_id, canonical_name FROM store WHERE store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')');
            foreach ($st as $r) {
                $out['store'][(int) $r['store_id']] = (string) $r['canonical_name'];
            }
        }
        return $out;
    }
}
