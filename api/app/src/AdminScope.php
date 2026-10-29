<?php

declare(strict_types=1);

namespace Amor\Api;

use PDO;

/**
 * Admin per pabrik — record-level checks. Auth::adminFactoryScope() says
 * which factories an Admin Pabrik is limited to (null = Leader Admin or not
 * an admin: nothing to check). A factory is derived the same way everywhere:
 * product -> division -> factory; a shipment carries its own factory_id.
 *
 * DO stays ONE document per store per day (it can hold both factories'
 * goods): an Admin Pabrik opens it when it holds any of their goods, and
 * sees / prints / ships only their own lines. Invoices are issued per
 * factory, so an invoice belongs to the factory of its shipments.
 * Mutasi between stores belongs to no factory and stays visible to all.
 */
final class AdminScope
{
    /** @return int[] factories whose goods are on this DO */
    public static function doFactoryIds(PDO $pdo, int $doId): array
    {
        $st = $pdo->prepare(
            'SELECT DISTINCT d.factory_id FROM delivery_order_item oi
             INNER JOIN product p ON p.product_id = oi.product_id
             INNER JOIN division d ON d.division_id = p.division_id
             WHERE oi.delivery_order_id = ?'
        );
        $st->execute([$doId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function shipmentFactoryId(PDO $pdo, int $shipmentId): ?int
    {
        $st = $pdo->prepare('SELECT factory_id FROM shipment WHERE shipment_id = ?');
        $st->execute([$shipmentId]);
        $f = $st->fetchColumn();
        return $f === false || $f === null ? null : (int) $f;
    }

    public static function receiptFactoryId(PDO $pdo, int $receiptId): ?int
    {
        $st = $pdo->prepare('SELECT sh.factory_id FROM shipment_receipt sr INNER JOIN shipment sh ON sh.shipment_id = sr.shipment_id WHERE sr.shipment_receipt_id = ?');
        $st->execute([$receiptId]);
        $f = $st->fetchColumn();
        return $f === false || $f === null ? null : (int) $f;
    }

    /** @return int[] factories whose divisions make this Pesanan Khusus's items (plus its own factory, if set) */
    public static function specialOrderFactoryIds(PDO $pdo, int $orderId): array
    {
        $st = $pdo->prepare(
            'SELECT d.factory_id FROM special_order_item i INNER JOIN division d ON d.division_id = i.division_id WHERE i.special_order_id = ?
             UNION SELECT factory_id FROM special_order WHERE special_order_id = ? AND factory_id IS NOT NULL'
        );
        $st->execute([$orderId, $orderId]);
        return array_values(array_unique(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
    }

    public static function specialOrderItemFactoryId(PDO $pdo, int $itemId): ?int
    {
        $st = $pdo->prepare('SELECT d.factory_id FROM special_order_item i INNER JOIN division d ON d.division_id = i.division_id WHERE i.special_order_item_id = ?');
        $st->execute([$itemId]);
        $f = $st->fetchColumn();
        return $f === false || $f === null ? null : (int) $f;
    }

    public static function specialOrderDoFactoryId(PDO $pdo, int $doId): ?int
    {
        $st = $pdo->prepare('SELECT factory_id FROM special_order_do WHERE special_order_do_id = ?');
        $st->execute([$doId]);
        $f = $st->fetchColumn();
        return $f === false || $f === null ? null : (int) $f;
    }

    public static function productFactoryId(PDO $pdo, int $productId): ?int
    {
        $st = $pdo->prepare('SELECT d.factory_id FROM product p INNER JOIN division d ON d.division_id = p.division_id WHERE p.product_id = ?');
        $st->execute([$productId]);
        $f = $st->fetchColumn();
        return $f === false || $f === null ? null : (int) $f;
    }

    /** @return int[] factories of the shipments this invoice bills (empty = a mutasi invoice, no factory) */
    public static function invoiceFactoryIds(PDO $pdo, int $invoiceId): array
    {
        $st = $pdo->prepare(
            'SELECT DISTINCT sh.factory_id FROM invoice_shipment ish
             INNER JOIN shipment sh ON sh.shipment_id = ish.shipment_id
             WHERE ish.invoice_id = ? AND sh.factory_id IS NOT NULL'
        );
        $st->execute([$invoiceId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Allowed when the record touches one of the admin's factories.
     * $allowNoFactory: records that belong to no factory (mutasi) stay open to every admin.
     *
     * @param int[] $factoryIds
     */
    public static function requireAny(array $factoryIds, bool $allowNoFactory = false): void
    {
        $scope = Auth::adminFactoryScope();
        if ($scope === null) {
            return;
        }
        if ($factoryIds === [] ? $allowNoFactory : array_intersect($factoryIds, $scope) !== []) {
            return;
        }
        throw new ApiException(403, 'FACTORY_ACCESS_DENIED', 'Data ini milik pabrik lain — hanya bisa dibuka Admin pabrik tersebut atau Leader Admin.');
    }

    /**
     * Allowed only when every factory on the record is the admin's own
     * (actions that change the whole shared document, e.g. cancelling a DO).
     *
     * @param int[] $factoryIds
     */
    public static function requireAll(array $factoryIds, string $message): void
    {
        $scope = Auth::adminFactoryScope();
        if ($scope === null) {
            return;
        }
        if ($factoryIds !== [] && array_diff($factoryIds, $scope) === []) {
            return;
        }
        throw new ApiException(403, 'LEADER_ONLY', $message);
    }

    /** True when the row's factory is visible to the current user (always true outside an Admin Pabrik). */
    public static function visible(?int $factoryId, bool $allowNoFactory = false): bool
    {
        $scope = Auth::adminFactoryScope();
        if ($scope === null) {
            return true;
        }
        return $factoryId === null ? $allowNoFactory : in_array($factoryId, $scope, true);
    }
}
