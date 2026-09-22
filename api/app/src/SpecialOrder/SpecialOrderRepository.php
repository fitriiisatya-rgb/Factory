<?php

declare(strict_types=1);

namespace Amor\Api\SpecialOrder;

use PDO;

/**
 * Migration 0010's raw data access for special_order / special_order_item
 * / special_order_catalog — mirrors DoRepository/FgRepository's shape
 * (plain prepared statements, no ORM). See SpecialOrderService for the
 * business rules (division routing, subtotal calc, status lifecycle).
 */
final class SpecialOrderRepository
{
    /**
     * Lazily finds-or-creates the "Cake & Custom" division under
     * Karangtengah — same find-or-create-with-UNIQUE-key-race-guard
     * pattern as FgRepository::findOrCreateLocationForFactory(). See the
     * migration's own docblock for why this isn't seeded by the DDL
     * migration itself.
     */
    public function findOrCreateCakeCustomDivision(PDO $pdo): int
    {
        $stmt = $pdo->prepare('SELECT division_id FROM division WHERE name = ?');
        $stmt->execute(['Cake & Custom']);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $stmt = $pdo->prepare('SELECT factory_id FROM factory WHERE name = ?');
        $stmt->execute(['Karangtengah']);
        $factoryId = $stmt->fetchColumn();
        if ($factoryId === false) {
            throw new \RuntimeException('Cannot create the "Cake & Custom" division — the Karangtengah factory row does not exist yet (master data not seeded).');
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO division (name, factory_id, is_verification) VALUES (?, ?, 0)');
            $stmt->execute(['Cake & Custom', (int) $factoryId]);
            return (int) $pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $stmt = $pdo->prepare('SELECT division_id FROM division WHERE name = ?');
                $stmt->execute(['Cake & Custom']);
                $id = $stmt->fetchColumn();
                if ($id !== false) {
                    return (int) $id;
                }
            }
            throw $e;
        }
    }

    /**
     * Lazily seeds the task's own DELUXE KARAKTER/ICING example catalog
     * (idempotent — INSERT ... ON DUPLICATE KEY UPDATE keyed by the
     * UNIQUE `code`, safe under concurrent callers). Called once at the
     * top of listCatalog(); a no-op on every call after the first.
     */
    public function ensureCatalogSeeded(PDO $pdo, int $cakeCustomDivisionId): void
    {
        $names = [
            'DELUXE-KARAKTER-12' => 'DELUXE KARAKTER 12',
            'DELUXE-KARAKTER-12-KOTAK' => 'DELUXE KARAKTER 12 KOTAK',
            'DELUXE-KARAKTER-16' => 'DELUXE KARAKTER 16',
            'DELUXE-KARAKTER-16-KOTAK' => 'DELUXE KARAKTER 16 KOTAK',
            'DELUXE-KARAKTER-18' => 'DELUXE KARAKTER 18',
            'DELUXE-KARAKTER-18-KOTAK' => 'DELUXE KARAKTER 18 KOTAK',
            'DELUXE-KARAKTER-20' => 'DELUXE KARAKTER 20',
            'DELUXE-KARAKTER-20-KOTAK' => 'DELUXE KARAKTER 20 KOTAK',
            'DELUXE-KARAKTER-22' => 'DELUXE KARAKTER 22',
            'DELUXE-KARAKTER-22-KOTAK' => 'DELUXE KARAKTER 22 KOTAK',
            'DELUXE-KARAKTER-24' => 'DELUXE KARAKTER 24',
            'DELUXE-KARAKTER-24-KOTAK' => 'DELUXE KARAKTER 24 KOTAK',
            'DELUXE-KARAKTER-30-KOTAK' => 'DELUXE KARAKTER 30 KOTAK',
            'ICING-12' => 'ICING 12',
            'ICING-16' => 'ICING 16',
            'ICING-18' => 'ICING 18',
            'ICING-20' => 'ICING 20',
            'ICING-22' => 'ICING 22',
            'ICING-24' => 'ICING 24',
            'ICING-30' => 'ICING 30',
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO special_order_catalog (code, name, division_id, default_charge, active, created_at)
             VALUES (?, ?, ?, 0, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE code = code'
        );
        foreach ($names as $code => $name) {
            $stmt->execute([$code, $name, $cakeCustomDivisionId]);
        }
    }

    /**
     * @return array<int,array> each row also carries factory_id/factory_name
     *         (auto-routing preview — the create form shows Factory the
     *         instant a catalog item is picked, no extra round trip).
     */
    public function findActiveCatalog(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT soc.special_order_catalog_id, soc.code, soc.name, soc.division_id, d.name AS division_name,
                    d.factory_id, f.name AS factory_name, soc.default_price, soc.default_charge, soc.notes
             FROM special_order_catalog soc
             INNER JOIN division d ON d.division_id = soc.division_id
             INNER JOIN factory f ON f.factory_id = d.factory_id
             WHERE soc.active = 1
             ORDER BY soc.name'
        )->fetchAll();
    }

    public function findCatalogItem(PDO $pdo, int $catalogId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT soc.*, d.name AS division_name, d.factory_id, f.name AS factory_name
             FROM special_order_catalog soc
             INNER JOIN division d ON d.division_id = soc.division_id
             INNER JOIN factory f ON f.factory_id = d.factory_id
             WHERE soc.special_order_catalog_id = ?'
        );
        $stmt->execute([$catalogId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array> also carries division.factory_id/factory name for the product-search datalist's own Factory preview. */
    public function findProductsWithDivision(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT p.product_id, p.name, p.harga, p.division_id, d.name AS division_name, d.factory_id, f.name AS factory_name
             FROM product p
             LEFT JOIN division d ON d.division_id = p.division_id
             LEFT JOIN factory f ON f.factory_id = d.factory_id
             WHERE p.aktif = 1
             ORDER BY p.name"
        )->fetchAll();
    }

    public function findProductWithDivision(PDO $pdo, int $productId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT p.product_id, p.name, p.harga, p.division_id, d.name AS division_name
             FROM product p LEFT JOIN division d ON d.division_id = p.division_id
             WHERE p.product_id = ? AND p.aktif = 1'
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function insertOrder(PDO $pdo, string $orderNo, array $header, int $userId): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO special_order
                (order_no, source_type, order_date, store_id, non_store_source, customer_name, customer_contact,
                 fulfillment_type, delivery_address, factory_id, required_date, required_time, pic_user_id,
                 general_note, status, version, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', 1, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $orderNo,
            $header['sourceType'],
            $header['orderDate'],
            $header['storeId'],
            $header['nonStoreSource'],
            $header['customerName'],
            $header['customerContact'],
            $header['fulfillmentType'],
            $header['deliveryAddress'],
            $header['factoryId'],
            $header['requiredDate'],
            $header['requiredTime'],
            $header['picUserId'],
            $header['generalNote'],
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function insertItem(PDO $pdo, int $orderId, array $item): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO special_order_item
                (special_order_id, item_type, product_id, special_catalog_id, division_id, item_name_snapshot,
                 qty, unit_price, charge, subtotal, special_note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $orderId,
            $item['itemType'],
            $item['productId'],
            $item['specialCatalogId'],
            $item['divisionId'],
            $item['itemNameSnapshot'],
            $item['qty'],
            $item['unitPrice'],
            $item['charge'],
            $item['subtotal'],
            $item['specialNote'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function findOrderById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT so.*, s.canonical_name AS store_name, f.name AS factory_name, u.full_name AS pic_name,
                    cb.full_name AS created_by_name
             FROM special_order so
             LEFT JOIN store s ON s.store_id = so.store_id
             LEFT JOIN factory f ON f.factory_id = so.factory_id
             LEFT JOIN users u ON u.user_id = so.pic_user_id
             LEFT JOIN users cb ON cb.user_id = so.created_by
             WHERE so.special_order_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function lockOrderById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM special_order WHERE special_order_id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array<int,array> each row also carries factory_id/factory_name
     *         — derived PER ITEM from division.factory_id, never from the
     *         order header (task's own "routing must happen PER ITEM" —
     *         a multi-division order can legitimately span factories).
     */
    public function findItemsForOrder(PDO $pdo, int $orderId): array
    {
        $stmt = $pdo->prepare(
            'SELECT soi.*, d.name AS division_name, d.factory_id AS item_factory_id, f.name AS item_factory_name
             FROM special_order_item soi
             INNER JOIN division d ON d.division_id = soi.division_id
             INNER JOIN factory f ON f.factory_id = d.factory_id
             WHERE soi.special_order_id = ?
             ORDER BY soi.special_order_item_id'
        );
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    /**
     * Snapshot semantics, same convention as ProductionRepository::
     * updateItemActual() — $aktual/$reject each REPLACE the stored value.
     * The WHERE clause scopes by BOTH special_order_item_id AND
     * special_order_id so a caller can never accidentally write another
     * order's item by guessing an id; rowCount()>0 is the caller's signal
     * that the item genuinely belongs to this order.
     */
    public function updateItemActualProduksi(PDO $pdo, int $orderId, int $itemId, float $aktual, float $reject): bool
    {
        $stmt = $pdo->prepare(
            'UPDATE special_order_item SET aktual_produksi = ?, reject_produksi = ?
             WHERE special_order_item_id = ? AND special_order_id = ?'
        );
        $stmt->execute([$aktual, $reject, $itemId, $orderId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array{sourceType?:string,status?:string,tanggal?:string,storeId?:int,divisionId?:int,factoryId?:int} $filters
     * @return array<int,array>
     */
    public function findOrders(PDO $pdo, array $filters): array
    {
        $sql = "SELECT so.*, s.canonical_name AS store_name, f.name AS factory_name,
                       (SELECT COUNT(DISTINCT soi.division_id) FROM special_order_item soi WHERE soi.special_order_id = so.special_order_id) AS division_count,
                       (SELECT COUNT(DISTINCT d2.factory_id) FROM special_order_item soi2
                          INNER JOIN division d2 ON d2.division_id = soi2.division_id
                        WHERE soi2.special_order_id = so.special_order_id) AS factory_count
                FROM special_order so
                LEFT JOIN store s ON s.store_id = so.store_id
                LEFT JOIN factory f ON f.factory_id = so.factory_id
                WHERE 1=1";
        $params = [];
        if (isset($filters['sourceType'])) {
            $sql .= ' AND so.source_type = ?';
            $params[] = $filters['sourceType'];
        }
        if (isset($filters['status'])) {
            $sql .= ' AND so.status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['tanggal'])) {
            $sql .= ' AND so.order_date = ?';
            $params[] = $filters['tanggal'];
        }
        if (isset($filters['storeId'])) {
            $sql .= ' AND so.store_id = ?';
            $params[] = $filters['storeId'];
        }
        $sql .= ' ORDER BY so.created_at DESC LIMIT 300';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Production demand inbox — every item from an order that has already
     * been sent to Production (status IN sent_to_production/in_production/
     * ready/completed), joined back to its own order header for
     * traceability (task's own "Maintain traceability back to the same
     * source order" / "Production demand source remains traceable").
     *
     * Factory is resolved PER ITEM from division.factory_id, never from
     * the order header's so.factory_id — a multi-division order can span
     * factories, so using the header value here would show (and, worse,
     * check FG stock against) the WRONG factory for some of its items.
     * @param array{factoryId?:int,divisionId?:int,tanggal?:string,status?:string,sourceType?:string} $filters
     * @return array<int,array>
     */
    public function findProductionDemandItems(PDO $pdo, array $filters): array
    {
        $sql = "SELECT soi.special_order_item_id, soi.item_type, soi.item_name_snapshot, soi.qty, soi.charge,
                       soi.aktual_produksi, soi.reject_produksi,
                       soi.special_note, soi.division_id, d.name AS division_name,
                       d.factory_id AS item_factory_id, f.name AS item_factory_name, soi.product_id,
                       so.special_order_id, so.order_no, so.source_type, so.status, so.order_date, so.version AS order_version,
                       so.required_date, so.required_time, so.store_id, s.canonical_name AS store_name,
                       so.customer_name, so.non_store_source
                FROM special_order_item soi
                INNER JOIN special_order so ON so.special_order_id = soi.special_order_id
                INNER JOIN division d ON d.division_id = soi.division_id
                INNER JOIN factory f ON f.factory_id = d.factory_id
                LEFT JOIN store s ON s.store_id = so.store_id
                WHERE so.status IN ('sent_to_production','in_production','ready','completed')";
        $params = [];
        if (isset($filters['divisionId'])) {
            $sql .= ' AND soi.division_id = ?';
            $params[] = $filters['divisionId'];
        }
        if (isset($filters['factoryId'])) {
            $sql .= ' AND d.factory_id = ?';
            $params[] = $filters['factoryId'];
        }
        if (isset($filters['tanggal'])) {
            $sql .= ' AND so.required_date = ?';
            $params[] = $filters['tanggal'];
        }
        if (isset($filters['status'])) {
            $sql .= ' AND so.status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['sourceType'])) {
            $sql .= ' AND so.source_type = ?';
            $params[] = $filters['sourceType'];
        }
        $sql .= ' ORDER BY so.required_date, so.required_time IS NULL, so.required_time';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Read-only FG stock-on-hand for an existing product at a factory's location (never written here — see SpecialOrderService's own docblock on why no reservation is built). */
    public function findStockOnHand(PDO $pdo, int $productId, int $factoryId): float
    {
        $stmt = $pdo->prepare(
            "SELECT sb.qty_on_hand FROM stock_balance sb
             INNER JOIN location l ON l.location_id = sb.location_id
             WHERE sb.product_id = ? AND l.factory_id = ?"
        );
        $stmt->execute([$productId, $factoryId]);
        $v = $stmt->fetchColumn();
        return $v === false ? 0.0 : (float) $v;
    }
}
