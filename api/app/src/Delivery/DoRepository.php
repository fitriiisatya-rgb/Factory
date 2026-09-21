<?php

declare(strict_types=1);

namespace Amor\Api\Delivery;

use Amor\Api\Services\DocumentSequenceService;
use PDO;

/**
 * Persistence for the Phase 5 Draft DO / staged Shipment documents
 * (delivery_order/delivery_order_item/shipment/shipment_item, all from
 * the original 0001 schema, extended additively by migration 0006) plus
 * the stock_ledger/stock_balance writes a real SHIP commit produces.
 * Mirrors the Production\ProductionRepository / Fg\FgRepository split
 * established in Phases 3/4.
 */
final class DoRepository
{
    private const ROMAN_MONTHS = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    public function findStore(PDO $pdo, int $storeId): ?array
    {
        $stmt = $pdo->prepare('SELECT store_id, canonical_name FROM store WHERE store_id = ?');
        $stmt->execute([$storeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findFactory(PDO $pdo, int $factoryId): ?array
    {
        $stmt = $pdo->prepare('SELECT factory_id, code, name FROM factory WHERE factory_id = ?');
        $stmt->execute([$factoryId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findProduct(PDO $pdo, int $productId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT p.product_id, p.name, p.division_id, d.factory_id
             FROM product p LEFT JOIN division d ON d.division_id = p.division_id
             WHERE p.product_id = ?'
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array|null row-locked (FOR UPDATE) */
    public function lockExistingDo(PDO $pdo, string $tanggal, int $storeId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM delivery_order WHERE tanggal = ? AND store_id = ? AND status NOT IN (\'shipped\',\'cancelled\') FOR UPDATE');
        $stmt->execute([$tanggal, $storeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Includes shipped/cancelled — used for display/history, never for the "find the open DO" identity check above. */
    public function findAnyDoByDateStore(PDO $pdo, string $tanggal, int $storeId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM delivery_order WHERE tanggal = ? AND store_id = ? ORDER BY delivery_order_id DESC LIMIT 1');
        $stmt->execute([$tanggal, $storeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array|null row-locked (FOR UPDATE) */
    public function lockDoById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM delivery_order WHERE delivery_order_id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findDoById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT o.*, s.canonical_name AS store_name FROM delivery_order o
             INNER JOIN store s ON s.store_id = o.store_id
             WHERE o.delivery_order_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array> */
    public function findDos(PDO $pdo, ?string $tanggal, ?int $storeId, ?string $status): array
    {
        $sql = 'SELECT o.*, s.canonical_name AS store_name FROM delivery_order o
                INNER JOIN store s ON s.store_id = o.store_id WHERE 1=1';
        $params = [];
        if ($tanggal !== null) {
            $sql .= ' AND o.tanggal = ?';
            $params[] = $tanggal;
        }
        if ($storeId !== null) {
            $sql .= ' AND o.store_id = ?';
            $params[] = $storeId;
        }
        if ($status !== null) {
            $sql .= ' AND o.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY o.tanggal DESC, s.canonical_name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * DOs for a factory+date in one query (avoids N+1 on the list screen —
     * task section 37) — a DO is included if ANY of its items belongs to
     * this factory (via product->division->factory_id).
     * @return array<int,array>
     */
    public function findDosForFactory(PDO $pdo, string $tanggal, int $factoryId): array
    {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT o.*, s.canonical_name AS store_name FROM delivery_order o
             INNER JOIN store s ON s.store_id = o.store_id
             INNER JOIN delivery_order_item oi ON oi.delivery_order_id = o.delivery_order_id
             INNER JOIN product p ON p.product_id = oi.product_id
             INNER JOIN division d ON d.division_id = p.division_id
             WHERE o.tanggal = ? AND d.factory_id = ?
             ORDER BY s.canonical_name'
        );
        $stmt->execute([$tanggal, $factoryId]);
        return $stmt->fetchAll();
    }

    /**
     * Server-generated, transactional DO number — DO/KRM/{seq:3}/{roman
     * month}/{yyyy}, the exact legacy format (audited from
     * amorcakes-manufacturing-v5-slate(2).html's doNomor(), confirmed
     * against docs/mysql-schema-v1.md §7's OD-2 note) — now backed by the
     * already-built DocumentSequenceService (Phase 0, unused until this
     * phase) instead of the legacy client-side recompute-from-existing-rows
     * approach. Must be called inside the same transaction as the
     * delivery_order INSERT.
     */
    public function allocateDoNumber(PDO $pdo, string $tanggal): string
    {
        [$year, $month] = array_map('intval', explode('-', $tanggal));
        $seq = DocumentSequenceService::allocate($pdo, 'DO', $year, $month);
        return sprintf('DO/KRM/%03d/%s/%d', $seq, self::ROMAN_MONTHS[$month - 1], $year);
    }

    public function createDo(PDO $pdo, string $tanggal, int $storeId, string $docNo, ?string $sourcePoVersionJson, int $userId): array
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO delivery_order
                    (doc_no, tanggal, store_id, status, source_po_version_json, created_by, version, created_at)
                 VALUES (?, ?, ?, 'draft', ?, ?, 1, UTC_TIMESTAMP())"
            );
            $stmt->execute([$docNo, $tanggal, $storeId, $sourcePoVersionJson, $userId]);
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $row = $this->lockExistingDo($pdo, $tanggal, $storeId);
                if ($row !== null) {
                    return $row;
                }
            }
            throw $e;
        }
        return $this->lockExistingDo($pdo, $tanggal, $storeId);
    }

    /** @return array<int,array> keyed by product_id */
    public function findDoItems(PDO $pdo, int $doId): array
    {
        $stmt = $pdo->prepare(
            'SELECT oi.*, p.name AS product_name, p.division_id, d.factory_id, d.name AS division_name
             FROM delivery_order_item oi
             INNER JOIN product p ON p.product_id = oi.product_id
             LEFT JOIN division d ON d.division_id = p.division_id
             WHERE oi.delivery_order_id = ?
             ORDER BY p.name'
        );
        $stmt->execute([$doId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['product_id']] = $r;
        }
        return $out;
    }

    public function insertDoItem(PDO $pdo, int $doId, int $productId, float $plannedQty): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO delivery_order_item (delivery_order_id, product_id, planned_qty) VALUES (?, ?, ?)'
        );
        $stmt->execute([$doId, $productId, $plannedQty]);
    }

    public function updateDoItemPlanned(PDO $pdo, int $doItemId, float $plannedQty): void
    {
        $stmt = $pdo->prepare('UPDATE delivery_order_item SET planned_qty = ? WHERE delivery_order_item_id = ?');
        $stmt->execute([$plannedQty, $doItemId]);
    }

    /** @return bool true if a row was actually updated (expectedVersion matched), false on a version conflict */
    public function bumpVersion(PDO $pdo, int $doId, int $expectedVersion, string $setClause, array $setParams): bool
    {
        $sql = "UPDATE delivery_order SET {$setClause}, version = version + 1, updated_at = UTC_TIMESTAMP() "
             . 'WHERE delivery_order_id = ? AND version = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([...$setParams, $doId, $expectedVersion]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Authoritative already-shipped qty per product for this DO — always
     * derived live from shipment_item joined through ACTIVE shipments only
     * (never a stored cache; delivery_order_item.actual_ship_qty is left
     * unused by this phase for exactly the same "never a second truth"
     * reason Phase 4 never trusts a cache over stock_ledger).
     * @return array<int,float>
     */
    public function shippedQtyByProduct(PDO $pdo, int $doId): array
    {
        $stmt = $pdo->prepare(
            "SELECT si.product_id, SUM(si.qty) AS qty
             FROM shipment_item si
             INNER JOIN shipment sh ON sh.shipment_id = si.shipment_id
             WHERE sh.delivery_order_id = ? AND sh.status = 'active'
             GROUP BY si.product_id"
        );
        $stmt->execute([$doId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['product_id']] = (float) $r['qty'];
        }
        return $out;
    }

    /** @return array<int,array> every active/void shipment for this DO, newest first */
    public function findShipmentsForDo(PDO $pdo, int $doId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM shipment WHERE delivery_order_id = ? ORDER BY shipment_id DESC');
        $stmt->execute([$doId]);
        return $stmt->fetchAll();
    }

    /** One shipment header, joined with store/DO/factory for the driver/admin detail screens. */
    public function findShipmentById(PDO $pdo, int $shipmentId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT sh.*, s.canonical_name AS store_name, o.doc_no, o.tanggal AS do_tanggal,
                    f.name AS factory_name
             FROM shipment sh
             INNER JOIN store s ON s.store_id = sh.store_id
             LEFT JOIN delivery_order o ON o.delivery_order_id = sh.delivery_order_id
             LEFT JOIN factory f ON f.factory_id = sh.factory_id
             WHERE sh.shipment_id = ?'
        );
        $stmt->execute([$shipmentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array<int,array> every item on one shipment, product_name
     * plus division_name (real-UAT Surat Jalan print ask: the shipment
     * item table needs a "Divisi" column — additive column, every
     * existing caller reads specific keys off each row so this never
     * breaks them).
     */
    public function findShipmentItems(PDO $pdo, int $shipmentId): array
    {
        $stmt = $pdo->prepare(
            'SELECT si.*, p.name AS product_name, d.name AS division_name FROM shipment_item si
             INNER JOIN product p ON p.product_id = si.product_id
             LEFT JOIN division d ON d.division_id = p.division_id
             WHERE si.shipment_id = ? ORDER BY p.name'
        );
        $stmt->execute([$shipmentId]);
        return $stmt->fetchAll();
    }

    public function createShipment(
        PDO $pdo,
        int $doId,
        int $factoryId,
        int $storeId,
        string $tanggal,
        string $shipmentGroup,
        string $batchLabel,
        int $userId
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO shipment
                (batch, tanggal, store_id, factory_id, shipment_group, source_type, delivery_order_id,
                 status, version, created_by, shipped_by, shipped_at, created_at)
             VALUES (?, ?, ?, ?, ?, 'delivery_order', ?, 'active', 1, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmt->execute([$batchLabel, $tanggal, $storeId, $factoryId, $shipmentGroup, $doId, $userId, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public function insertShipmentItem(PDO $pdo, int $shipmentId, int $productId, float $qty, ?int $doItemId, ?string $notes): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO shipment_item (shipment_id, product_id, delivery_order_item_id, qty, notes) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$shipmentId, $productId, $doItemId, $qty, $notes]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Batched planned/shipped/last-shipment totals for a set of DO ids —
     * ONE query each, never one query per DO (task section 37's explicit
     * "no obvious N+1" ask for the list screen).
     * @param int[] $doIds
     * @return array<int,float>
     */
    public function sumPlannedByDo(PDO $pdo, array $doIds): array
    {
        if ($doIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($doIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT delivery_order_id, SUM(planned_qty) AS total FROM delivery_order_item
             WHERE delivery_order_id IN ({$placeholders}) GROUP BY delivery_order_id"
        );
        $stmt->execute($doIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['delivery_order_id']] = (float) $r['total'];
        }
        return $out;
    }

    /** @param int[] $doIds @return array<int,float> */
    public function sumShippedByDo(PDO $pdo, array $doIds): array
    {
        if ($doIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($doIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT sh.delivery_order_id, SUM(si.qty) AS total
             FROM shipment_item si INNER JOIN shipment sh ON sh.shipment_id = si.shipment_id
             WHERE sh.delivery_order_id IN ({$placeholders}) AND sh.status = 'active'
             GROUP BY sh.delivery_order_id"
        );
        $stmt->execute($doIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['delivery_order_id']] = (float) $r['total'];
        }
        return $out;
    }

    /** @param int[] $doIds @return array<int,array> latest active shipment row per DO */
    public function lastShipmentByDo(PDO $pdo, array $doIds): array
    {
        if ($doIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($doIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT sh1.* FROM shipment sh1
             INNER JOIN (
                 SELECT delivery_order_id, MAX(shipment_id) AS max_id FROM shipment
                 WHERE delivery_order_id IN ({$placeholders}) AND status = 'active'
                 GROUP BY delivery_order_id
             ) latest ON latest.max_id = sh1.shipment_id"
        );
        $stmt->execute($doIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['delivery_order_id']] = $r;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // stock_ledger / stock_balance — reusing exactly the tables/columns
    // Phase 4 already made authoritative; this class only adds the
    // 'shipment_out' write path, never a parallel stock number (task
    // section 18).
    // ------------------------------------------------------------------

    /**
     * Row-locks (FOR UPDATE) the stock_balance row for (product,location)
     * if it exists — the concurrency guard for "no negative stock" (task
     * section 20). Returns null if no row exists yet (nothing to lock;
     * available is then 0 by definition, so no race is possible on a
     * product that has never received any FG stock).
     */
    public function lockBalance(PDO $pdo, int $productId, int $locationId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM stock_balance WHERE product_id = ? AND location_id = ? FOR UPDATE');
        $stmt->execute([$productId, $locationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Posts one stock_ledger row (event_type='shipment_out',
     * qty_delta = -$qty, source_type='shipment_item') and updates
     * stock_balance in the same transaction. Caller must already hold the
     * lock from lockBalance() (or know the row doesn't exist yet) so the
     * available-qty check and this write are atomic against concurrent
     * shippers.
     */
    public function postShipmentOutLedger(
        PDO $pdo,
        int $productId,
        int $locationId,
        float $qty,
        string $eventDate,
        int $shipmentItemId,
        ?int $userId
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO stock_ledger
                (product_id, location_id, event_type, qty_delta, source_type, source_id, event_date, created_at, created_by, notes)
             VALUES (?, ?, 'shipment_out', ?, 'shipment_item', ?, ?, UTC_TIMESTAMP(), ?, NULL)"
        );
        $stmt->execute([$productId, $locationId, -$qty, $shipmentItemId, $eventDate, $userId]);
        $ledgerId = (int) $pdo->lastInsertId();

        $upsert = $pdo->prepare(
            'INSERT INTO stock_balance (product_id, location_id, qty_on_hand, last_ledger_id, updated_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand), last_ledger_id = VALUES(last_ledger_id), updated_at = UTC_TIMESTAMP()'
        );
        $upsert->execute([$productId, $locationId, -$qty, $ledgerId]);

        return $ledgerId;
    }
}
