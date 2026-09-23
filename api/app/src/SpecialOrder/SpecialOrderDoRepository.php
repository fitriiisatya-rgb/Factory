<?php

declare(strict_types=1);

namespace Amor\Api\SpecialOrder;

use Amor\Api\Services\DocumentSequenceService;
use PDO;

/**
 * Data access for special_order_do / special_order_do_item /
 * special_order_do_shipment_item — the SEPARATE, source-specific DO +
 * real shipment write path for Pesanan Khusus Toko / Pesanan Non-Toko
 * (migration 0012, reworked). Mirrors Delivery\DoRepository's own shape
 * for the shipment-creation half (see its own docblock for why one
 * shipment never mixes factories), but against its own dedicated tables —
 * see the migration's own docblock for why Regular PO's delivery_order/
 * delivery_order_item/shipment_item are never reused here.
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

    public function createDo(
        PDO $pdo,
        string $docNo,
        string $tanggal,
        int $specialOrderId,
        string $sourceType,
        int $factoryId,
        int $dropStoreId,
        string $deliveryMethod,
        ?string $courierProvider,
        ?string $courierName,
        ?string $externalOrderReference,
        ?string $customerName,
        ?string $customerContact,
        ?string $deliveryAddress,
        int $userId
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO special_order_do
                (doc_no, tanggal, special_order_id, source_type, factory_id, drop_store_id, delivery_method,
                 courier_provider, courier_name, external_order_reference, status, customer_name,
                 customer_contact, delivery_address, version, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, 1, ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([
            $docNo, $tanggal, $specialOrderId, $sourceType, $factoryId, $dropStoreId, $deliveryMethod,
            $courierProvider, $courierName, $externalOrderReference, $customerName, $customerContact,
            $deliveryAddress, $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function insertDoItem(PDO $pdo, int $doId, int $specialOrderItemId, float $plannedQty): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO special_order_do_item (special_order_do_id, special_order_item_id, planned_qty) VALUES (?, ?, ?)'
        );
        $stmt->execute([$doId, $specialOrderItemId, $plannedQty]);
        return (int) $pdo->lastInsertId();
    }

    public function findDoById(PDO $pdo, int $doId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT sodo.*, s.canonical_name AS drop_store_name, so.order_no, so.non_store_source, f.name AS factory_name,
                    cb.full_name AS created_by_name, cl.full_name AS claimed_by_name
             FROM special_order_do sodo
             INNER JOIN special_order so ON so.special_order_id = sodo.special_order_id
             INNER JOIN store s ON s.store_id = sodo.drop_store_id
             INNER JOIN factory f ON f.factory_id = sodo.factory_id
             LEFT JOIN users cb ON cb.user_id = sodo.created_by
             LEFT JOIN users cl ON cl.user_id = sodo.claimed_by_user_id
             WHERE sodo.special_order_do_id = ?'
        );
        $stmt->execute([$doId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Row-locks the DO for the duration of the caller's transaction — every claim/depart/handover/cancel/delivery-method-change action holds this for its whole write. */
    public function lockDoById(PDO $pdo, int $doId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM special_order_do WHERE special_order_do_id = ? FOR UPDATE');
        $stmt->execute([$doId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array<int,array> each row's shippedQty is the real, live SUM
     *         of special_order_do_shipment_item.qty for that line — never
     *         a cached column (same convention as Regular PO's own
     *         DoRepository::shippedQtyByProduct()).
     */
    public function findDoItems(PDO $pdo, int $doId): array
    {
        $stmt = $pdo->prepare(
            "SELECT sodi.*, soi.item_type, soi.item_name_snapshot, soi.division_id, d.name AS division_name,
                    d.factory_id, f.name AS factory_name,
                    COALESCE((SELECT SUM(sodsi.qty) FROM special_order_do_shipment_item sodsi WHERE sodsi.special_order_do_item_id = sodi.special_order_do_item_id), 0) AS shipped_qty
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

    public function sumShippedForDoItem(PDO $pdo, int $doItemId): float
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM special_order_do_shipment_item WHERE special_order_do_item_id = ?');
        $stmt->execute([$doItemId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * @param array{sourceType?:string,status?:string,tanggal?:string,deliveryMethod?:string,factoryId?:int,claimedByUserId?:int|null,unclaimedOnly?:bool} $filters
     * @return array<int,array>
     */
    public function findDos(PDO $pdo, array $filters): array
    {
        $sql = 'SELECT sodo.*, s.canonical_name AS drop_store_name, so.order_no, so.non_store_source, f.name AS factory_name,
                       cl.full_name AS claimed_by_name
                FROM special_order_do sodo
                INNER JOIN special_order so ON so.special_order_id = sodo.special_order_id
                INNER JOIN store s ON s.store_id = sodo.drop_store_id
                INNER JOIN factory f ON f.factory_id = sodo.factory_id
                LEFT JOIN users cl ON cl.user_id = sodo.claimed_by_user_id
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
        if (isset($filters['deliveryMethod'])) {
            $sql .= ' AND sodo.delivery_method = ?';
            $params[] = $filters['deliveryMethod'];
        }
        if (isset($filters['factoryId'])) {
            $sql .= ' AND sodo.factory_id = ?';
            $params[] = $filters['factoryId'];
        }
        if (array_key_exists('claimedByUserId', $filters)) {
            if ($filters['claimedByUserId'] === null) {
                $sql .= ' AND sodo.claimed_by_user_id IS NULL';
            } else {
                $sql .= ' AND sodo.claimed_by_user_id = ?';
                $params[] = $filters['claimedByUserId'];
            }
        }
        $sql .= ' ORDER BY sodo.special_order_do_id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function claim(PDO $pdo, int $doId, int $userId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE special_order_do SET claimed_by_user_id = ?, claimed_at = UTC_TIMESTAMP(), version = version + 1, updated_at = UTC_TIMESTAMP() WHERE special_order_do_id = ?"
        );
        $stmt->execute([$userId, $doId]);
    }

    public function release(PDO $pdo, int $doId): void
    {
        $stmt = $pdo->prepare(
            'UPDATE special_order_do SET claimed_by_user_id = NULL, claimed_at = NULL, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE special_order_do_id = ?'
        );
        $stmt->execute([$doId]);
    }

    public function cancel(PDO $pdo, int $doId, int $userId, string $reason): void
    {
        $stmt = $pdo->prepare(
            "UPDATE special_order_do SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), cancelled_by = ?, cancel_reason = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE special_order_do_id = ?"
        );
        $stmt->execute([$userId, $reason, $doId]);
    }

    public function changeDeliveryMethod(PDO $pdo, int $doId, string $method, ?string $provider, ?string $courierName, ?string $externalRef): void
    {
        $stmt = $pdo->prepare(
            'UPDATE special_order_do
                SET delivery_method = ?, courier_provider = ?, courier_name = ?, external_order_reference = ?,
                    version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE special_order_do_id = ?'
        );
        $stmt->execute([$method, $provider, $courierName, $externalRef, $doId]);
    }

    /** Recomputes and writes status purely from real shipped-vs-planned sums (task's own "Do NOT mark DO shipped merely because a button was clicked"). Never touches claim/cancel fields. */
    public function refreshStatus(PDO $pdo, int $doId): string
    {
        $items = $this->findDoItems($pdo, $doId);
        $totalPlanned = array_sum(array_map(static fn ($i) => (float) $i['planned_qty'], $items));
        $totalShipped = array_sum(array_map(static fn ($i) => (float) $i['shipped_qty'], $items));
        if ($totalShipped <= 0.0001) {
            $status = 'open';
        } elseif ($totalShipped + 0.0001 < $totalPlanned) {
            $status = 'partial';
        } else {
            $status = 'shipped';
        }
        $stmt = $pdo->prepare("UPDATE special_order_do SET status = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE special_order_do_id = ? AND status <> 'cancelled'");
        $stmt->execute([$status, $doId]);
        return $status;
    }

    /**
     * Creates the real shipment header row for a source-specific dispatch
     * — the "CRITICAL DISPATCH RULE" write path (task's own explicit
     * requirement: "Both actions must create an actual Shipment... Do NOT
     * create a fake parallel 'shipped' status that bypasses shipment").
     * batch = the DO's own doc_no, same convention as Regular PO's own
     * createShipment(). shipment_group is fixed 'OTHER' — the MAIN/PASTRY
     * routing tabs are a Regular-PO-only concept that doesn't apply here.
     */
    public function createShipment(
        PDO $pdo,
        int $doId,
        int $factoryId,
        int $dropStoreId,
        string $tanggal,
        string $docNo,
        string $deliveryMethod,
        ?string $courierProvider,
        ?string $courierName,
        ?string $externalOrderReference,
        ?string $handoverNote,
        ?string $pengemudiName,
        int $userId
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO shipment
                (batch, tanggal, store_id, factory_id, pengemudi, shipment_group, source_type, special_order_do_id,
                 delivery_method, courier_provider, courier_name, external_order_reference, handover_note,
                 status, version, created_by, shipped_by, shipped_at, created_at)
             VALUES (?, ?, ?, ?, ?, 'OTHER', 'special_order_do', ?, ?, ?, ?, ?, ?, 'active', 1, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmt->execute([
            $docNo, $tanggal, $dropStoreId, $factoryId, $pengemudiName, $doId,
            $deliveryMethod, $courierProvider, $courierName, $externalOrderReference, $handoverNote,
            $userId, $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function insertShipmentDoLine(PDO $pdo, int $shipmentId, int $doItemId, float $qty): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO special_order_do_shipment_item (shipment_id, special_order_do_item_id, qty, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$shipmentId, $doItemId, $qty]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Every real per-dispatch line for one shipment — the special-order
     * counterpart of Delivery\DoRepository::findShipmentItems(), read
     * through Dispatch\ShipmentLineResolver so both sources render behind
     * one normalized shape (task's own Section B — never a fake product_id
     * for a custom/catalog item).
     * @return array<int,array>
     */
    public function findShipmentLines(PDO $pdo, int $shipmentId): array
    {
        $stmt = $pdo->prepare(
            "SELECT sodsi.special_order_do_shipment_item_id, sodsi.special_order_do_item_id, sodsi.qty,
                    soi.special_order_item_id, soi.item_type, soi.product_id, soi.special_catalog_id, soi.item_name_snapshot, soi.special_note,
                    d.name AS division_name, f.name AS factory_name
             FROM special_order_do_shipment_item sodsi
             INNER JOIN special_order_do_item sodi ON sodi.special_order_do_item_id = sodsi.special_order_do_item_id
             INNER JOIN special_order_item soi ON soi.special_order_item_id = sodi.special_order_item_id
             INNER JOIN division d ON d.division_id = soi.division_id
             INNER JOIN factory f ON f.factory_id = d.factory_id
             WHERE sodsi.shipment_id = ?
             ORDER BY sodsi.special_order_do_shipment_item_id"
        );
        $stmt->execute([$shipmentId]);
        return $stmt->fetchAll();
    }

    /**
     * The Driver Portal pool — DRIVER_INTERNAL only (task's own explicit
     * mutual-exclusion rule: "A DO configured as EXTERNAL_COURIER must NOT
     * be claimable by internal driver"), open/partial only (nothing left
     * to dispatch on a fully shipped or cancelled DO), and either
     * unclaimed (available to any driver) or claimed by the requesting
     * driver (their own active claim).
     */
    public function findDriverPool(PDO $pdo, int $driverUserId): array
    {
        $stmt = $pdo->prepare(
            "SELECT sodo.*, s.canonical_name AS drop_store_name, so.order_no, so.non_store_source, f.name AS factory_name
             FROM special_order_do sodo
             INNER JOIN special_order so ON so.special_order_id = sodo.special_order_id
             INNER JOIN store s ON s.store_id = sodo.drop_store_id
             INNER JOIN factory f ON f.factory_id = sodo.factory_id
             WHERE sodo.delivery_method = 'DRIVER_INTERNAL'
               AND sodo.status IN ('open','partial')
               AND (sodo.claimed_by_user_id IS NULL OR sodo.claimed_by_user_id = ?)
             ORDER BY sodo.tanggal, sodo.special_order_do_id"
        );
        $stmt->execute([$driverUserId]);
        return $stmt->fetchAll();
    }
}
