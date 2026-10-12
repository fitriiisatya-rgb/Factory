<?php

declare(strict_types=1);

namespace Amor\Api\Fg;

use Amor\Api\Delivery\DoRepository;
use Amor\Api\Replacement\ReplacementFgAllocationRepository;
use Amor\Api\SpecialOrder\SpecialOrderFgAllocationRepository;
use PDO;

/**
 * Stok Sisa — finished goods in the factory warehouse that belong to no
 * store: production kept aside by FG (more made than the PO, e.g. a whole
 * cake for 4 ordered slices), or FG never split per store. Read-only and
 * computed live from the same sources the allocators use:
 *
 *   sisa = stock on hand
 *          - FG packed for stores and not yet shipped to them
 *          - active Pesanan Khusus/Non-Toko allocations
 *          - active Replacement allocations
 *
 * Admin sends it on as a Pesanan Khusus Toko (susulan to a store) or a
 * Pesanan Non-Toko, both through the existing "ambil dari stok FG"
 * allocation, so nothing here writes.
 */
final class StokSisaService
{
    private DoRepository $doRepo;
    private FgRepository $fgRepo;
    private SpecialOrderFgAllocationRepository $specialAlloc;
    private ReplacementFgAllocationRepository $replAlloc;

    public function __construct(private PDO $pdo)
    {
        $this->doRepo = new DoRepository();
        $this->fgRepo = new FgRepository();
        $this->specialAlloc = new SpecialOrderFgAllocationRepository();
        $this->replAlloc = new ReplacementFgAllocationRepository();
    }

    /**
     * @return array<int,array{productId:int,productName:string,kategori:?string,harga:float,divisionName:?string,factoryId:int,factoryName:string,onHand:float,storeCommitted:float,reserved:float,sisa:float,lastSurplusDate:?string}>
     */
    public function list(?int $factoryId = null): array
    {
        $sql = "SELECT sb.product_id, sb.qty_on_hand, l.factory_id, f.name AS factory_name,
                       p.name AS product_name, p.kategori, p.harga, d.name AS division_name
                FROM stock_balance sb
                INNER JOIN location l ON l.location_id = sb.location_id
                INNER JOIN factory f ON f.factory_id = l.factory_id
                INNER JOIN product p ON p.product_id = sb.product_id
                LEFT JOIN division d ON d.division_id = p.division_id
                WHERE sb.qty_on_hand > 0.0001" . ($factoryId !== null ? ' AND l.factory_id = ?' : '') . "
                ORDER BY d.name, p.name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($factoryId !== null ? [$factoryId] : []);
        $unallocated = $this->fgRepo->unallocatedStoreId($this->pdo);
        $lastSurplus = $this->pdo->prepare(
            'SELECT MAX(b.tanggal) FROM fg_item fi INNER JOIN fg_batch b ON b.fg_batch_id = fi.fg_batch_id
             WHERE fi.product_id = ? AND b.factory_id = ? AND fi.store_id = ? AND fi.posted_packed_qty > 0 AND b.status = \'submitted\''
        );
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $productId = (int) $r['product_id'];
            $fid = (int) $r['factory_id'];
            $onHand = (float) $r['qty_on_hand'];
            $committed = $this->doRepo->hasAnyStoreAllocation($this->pdo, $productId, $fid, $unallocated)
                ? $this->doRepo->sumStoreCommittedRemainingAcrossAllStores($this->pdo, $productId, $fid, $unallocated)
                : 0.0;
            $reserved = $this->specialAlloc->sumActiveAllocatedForProductFactory($this->pdo, $productId, $fid)
                + $this->replAlloc->sumActiveAllocatedForProductFactory($this->pdo, $productId, $fid);
            $sisa = round($onHand - $committed - $reserved, 2);
            if ($sisa <= 0.0001) {
                continue;
            }
            $lastSurplus->execute([$productId, $fid, $unallocated]);
            $out[] = [
                'productId' => $productId,
                'productName' => (string) $r['product_name'],
                'kategori' => $r['kategori'],
                'harga' => (float) $r['harga'],
                'divisionName' => $r['division_name'],
                'factoryId' => $fid,
                'factoryName' => (string) $r['factory_name'],
                'onHand' => $onHand,
                'storeCommitted' => $committed,
                'reserved' => $reserved,
                'sisa' => $sisa,
                'lastSurplusDate' => $lastSurplus->fetchColumn() ?: null,
            ];
        }
        return $out;
    }
}
