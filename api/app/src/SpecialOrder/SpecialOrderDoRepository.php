<?php

declare(strict_types=1);

namespace Amor\Api\SpecialOrder;

use Amor\Api\Services\DocumentSequenceService;
use PDO;

/**
 * Data access for special_order_do / special_order_do_item — the
 * SEPARATE, source-specific DO for Pesanan Khusus Toko / Pesanan
 * Non-Toko (migration 0012). Mirrors Delivery\DoRepository's own shape,
 * but against its own dedicated tables — see the migration's own
 * docblock for why Regular PO's delivery_order/delivery_order_item are
 * never reused here.
 */
final class SpecialOrderDoRepository
{
    /** DOK-{Ymd}-{seq:03d} — a distinct, unmistakably-non-regular-DO format (task's own numbering guidance). */
    public function allocateDoNumber(PDO $pdo, string $tanggal): string
    {
        [$year, $month] = array_map('intval', explode('-', $tanggal));
        $seq = DocumentSequenceService::allocate($pdo, 'SPECIAL_ORDER_DO', $year, $month);
        return sprintf('DOK-%s-%03d', str_replace('-', '', $tanggal), $seq);
    }

    /** Any non-cancelled DO already open for this order (task's own "ONE DO per demand event" analog to Regular PO's (tanggal,storeId) identity). */
    public function findOpenDoForOrder(PDO $pdo, int $specialOrderId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM special_order_do WHERE special_order_id = ? AND status <> 'cancelled' ORDER BY special_order_do_id DESC LIMIT 1"
        );
        $stmt->execute([$specialOrderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createDo(
        PDO $pdo,
        string $docNo,
        string $tanggal,
        int $specialOrderId,
        string $sourceType,
        ?int $storeId,
        ?string $customerName,
        ?string $customerContact,
        ?string $deliveryAddress,
        int $userId
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO special_order_do
                (doc_no, tanggal, special_order_id, source_type, status, store_id, customer_name,
                 customer_contact, delivery_address, version, created_by, created_at)
             VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, 1, ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([$docNo, $tanggal, $specialOrderId, $sourceType, $storeId, $customerName, $customerContact, $deliveryAddress, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public function insertDoItem(PDO $pdo, int $doId, int $specialOrderItemId, float $plannedQty): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO special_order_do_item (special_order_do_id, special_order_item_id, planned_qty) VALUES (?, ?, ?)'
        );
        $stmt->execute([$doId, $specialOrderItemId, $plannedQty]);
    }

    public function findDoById(PDO $pdo, int $doId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT sodo.*, s.canonical_name AS store_name, so.order_no, cb.full_name AS created_by_name
             FROM special_order_do sodo
             INNER JOIN special_order so ON so.special_order_id = sodo.special_order_id
             LEFT JOIN store s ON s.store_id = sodo.store_id
             LEFT JOIN users cb ON cb.user_id = sodo.created_by
             WHERE sodo.special_order_do_id = ?'
        );
        $stmt->execute([$doId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array> */
    public function findDoItems(PDO $pdo, int $doId): array
    {
        $stmt = $pdo->prepare(
            "SELECT sodi.*, soi.item_type, soi.item_name_snapshot, soi.division_id, d.name AS division_name,
                    d.factory_id, f.name AS factory_name
             FROM special_order_do_item sodi
             INNER JOIN special_order_item soi ON soi.special_order_item_id = sodi.special_order_item_id
             INNER JOIN division d ON d.division_id = soi.division_id
             INNER JOIN factory f ON f.factory_id = d.factory_id
             WHERE sodi.special_order_do_id = ?
             ORDER BY sodi.special_order_do_item_id"
        );
        $stmt->execute([$doId]);
        return $stmt->fetchAll();
    }

    /**
     * @param array{sourceType?:string,status?:string,tanggal?:string} $filters
     * @return array<int,array>
     */
    public function findDos(PDO $pdo, array $filters): array
    {
        $sql = 'SELECT sodo.*, s.canonical_name AS store_name, so.order_no
                FROM special_order_do sodo
                INNER JOIN special_order so ON so.special_order_id = sodo.special_order_id
                LEFT JOIN store s ON s.store_id = sodo.store_id
                WHERE 1=1';
        $params = [];
        if (isset($filters['sourceType'])) {
            $sql .= ' AND sodo.source_type = ?';
            $params[] = $filters['sourceType'];
        }
        if (isset($filters['status'])) {
            $sql .= ' AND sodo.status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['tanggal'])) {
            $sql .= ' AND sodo.tanggal = ?';
            $params[] = $filters['tanggal'];
        }
        $sql .= ' ORDER BY sodo.special_order_do_id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function markStatus(PDO $pdo, int $doId, string $status, int $userId, ?string $reason = null): void
    {
        if ($status === 'shipped') {
            $stmt = $pdo->prepare(
                "UPDATE special_order_do SET status = 'shipped', shipped_at = UTC_TIMESTAMP(), shipped_by = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE special_order_do_id = ?"
            );
            $stmt->execute([$userId, $doId]);
        } elseif ($status === 'cancelled') {
            $stmt = $pdo->prepare(
                "UPDATE special_order_do SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), cancelled_by = ?, cancel_reason = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE special_order_do_id = ?"
            );
            $stmt->execute([$userId, $reason, $doId]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE special_order_do SET status = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE special_order_do_id = ?'
            );
            $stmt->execute([$status, $doId]);
        }
    }
}
