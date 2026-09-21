<?php

declare(strict_types=1);

namespace Amor\Api\Dispatch;

use PDO;

/**
 * Persistence for Phase 5.5 (dispatch_claim/driver_route/driver_route_stop
 * — the delivery_order/delivery_order_item/shipment tables themselves are
 * read through the EXISTING Delivery\DoRepository, never re-queried here
 * with a second, possibly-drifting SQL shape).
 *
 * No "dispatch_task" table exists (see the migration 0007 SQL file's own
 * docblock for the schema-audit reasoning) — delivery_order_item already
 * IS the claimable pool line; this class only adds the reservation
 * (dispatch_claim) on top of it.
 */
final class DispatchRepository
{
    /**
     * Every open (not shipped/cancelled) DO item with claimable qty > 0,
     * for the driver's "Tersedia" pool. One query with GROUP BY for
     * shipped-so-far and active-claimed-so-far each — never one query per
     * row (same "no obvious N+1" discipline as DoRepository's list screen).
     *
     * @return array<int,array> keyed by delivery_order_item_id
     */
    public function listAvailable(PDO $pdo, string $tanggal, ?int $factoryId, ?int $storeId): array
    {
        $sql = "SELECT oi.delivery_order_item_id, oi.delivery_order_id, oi.product_id, oi.planned_qty,
                       o.doc_no, o.tanggal, o.store_id, o.status AS do_status,
                       s.canonical_name AS store_name,
                       p.name AS product_name, d.division_id, d.name AS division_name, d.factory_id
                FROM delivery_order_item oi
                INNER JOIN delivery_order o ON o.delivery_order_id = oi.delivery_order_id
                INNER JOIN store s ON s.store_id = o.store_id
                INNER JOIN product p ON p.product_id = oi.product_id
                LEFT JOIN division d ON d.division_id = p.division_id
                WHERE o.tanggal = ? AND o.status NOT IN ('shipped', 'cancelled')";
        $params = [$tanggal];
        if ($factoryId !== null) {
            $sql .= ' AND d.factory_id = ?';
            $params[] = $factoryId;
        }
        if ($storeId !== null) {
            $sql .= ' AND o.store_id = ?';
            $params[] = $storeId;
        }
        $sql .= ' ORDER BY s.canonical_name, p.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return [];
        }
        $doItemIds = array_map(static fn ($r) => (int) $r['delivery_order_item_id'], $rows);
        $shippedByDoId = [];
        $doIds = array_unique(array_map(static fn ($r) => (int) $r['delivery_order_id'], $rows));
        foreach ($doIds as $doId) {
            $shippedByDoId[$doId] = $this->shippedQtyByProductForDo($pdo, $doId);
        }
        $activeClaimed = $this->activeClaimedQtyByItem($pdo, $doItemIds);

        $out = [];
        foreach ($rows as $r) {
            $doItemId = (int) $r['delivery_order_item_id'];
            $doId = (int) $r['delivery_order_id'];
            $productId = (int) $r['product_id'];
            $planned = (float) $r['planned_qty'];
            $shipped = $shippedByDoId[$doId][$productId] ?? 0.0;
            $claimed = $activeClaimed[$doItemId] ?? 0.0;
            $remaining = max(0.0, $planned - $shipped - $claimed);
            $r['shipped_qty'] = $shipped;
            $r['active_claimed_qty'] = $claimed;
            $r['remaining_claimable_qty'] = $remaining;
            $out[$doItemId] = $r;
        }
        return $out;
    }

    /** @return array<int,float> product_id => qty, active shipments only (mirrors DoRepository::shippedQtyByProduct) */
    public function shippedQtyByProductForDo(PDO $pdo, int $doId): array
    {
        $stmt = $pdo->prepare(
            "SELECT si.product_id, SUM(si.qty) AS qty
             FROM shipment_item si INNER JOIN shipment sh ON sh.shipment_id = si.shipment_id
             WHERE sh.delivery_order_id = ? AND sh.status = 'active' GROUP BY si.product_id"
        );
        $stmt->execute([$doId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['product_id']] = (float) $r['qty'];
        }
        return $out;
    }

    /** @param int[] $doItemIds @return array<int,float> delivery_order_item_id => sum of active_qty */
    public function activeClaimedQtyByItem(PDO $pdo, array $doItemIds): array
    {
        if ($doItemIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($doItemIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT delivery_order_item_id, SUM(active_qty) AS qty FROM dispatch_claim
             WHERE delivery_order_item_id IN ({$placeholders}) AND status = 'active'
             GROUP BY delivery_order_item_id"
        );
        $stmt->execute($doItemIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['delivery_order_item_id']] = (float) $r['qty'];
        }
        return $out;
    }

    public function activeClaimedQtyForItem(PDO $pdo, int $doItemId): float
    {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(active_qty), 0) FROM dispatch_claim WHERE delivery_order_item_id = ? AND status = 'active'");
        $stmt->execute([$doItemId]);
        return (float) $stmt->fetchColumn();
    }

    /** One delivery_order_item joined with its parent DO/product/division/store — the shape claim/departure logic needs. */
    public function findDoItemDetail(PDO $pdo, int $doItemId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT oi.delivery_order_item_id, oi.delivery_order_id, oi.product_id, oi.planned_qty,
                    o.doc_no, o.tanggal, o.store_id, o.status AS do_status, o.version AS do_version,
                    s.canonical_name AS store_name,
                    p.name AS product_name, d.division_id, d.name AS division_name, d.factory_id
             FROM delivery_order_item oi
             INNER JOIN delivery_order o ON o.delivery_order_id = oi.delivery_order_id
             INNER JOIN store s ON s.store_id = o.store_id
             INNER JOIN product p ON p.product_id = oi.product_id
             LEFT JOIN division d ON d.division_id = p.division_id
             WHERE oi.delivery_order_item_id = ?"
        );
        $stmt->execute([$doItemId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function insertClaim(PDO $pdo, int $doId, int $doItemId, int $productId, int $storeId, int $driverUserId, float $qty): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO dispatch_claim
                (delivery_order_id, delivery_order_item_id, product_id, store_id, driver_user_id,
                 claimed_qty, active_qty, status, version, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 1, UTC_TIMESTAMP())"
        );
        $stmt->execute([$doId, $doItemId, $productId, $storeId, $driverUserId, $qty, $qty]);
        return (int) $pdo->lastInsertId();
    }

    public function findClaim(PDO $pdo, int $claimId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM dispatch_claim WHERE dispatch_claim_id = ?');
        $stmt->execute([$claimId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Row-locked (FOR UPDATE) — caller must already hold the parent DO's lock too. */
    public function lockClaim(PDO $pdo, int $claimId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM dispatch_claim WHERE dispatch_claim_id = ? FOR UPDATE');
        $stmt->execute([$claimId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array> every 'active' claim for this driver, richest-first joined display fields */
    public function findActiveClaimsForDriver(PDO $pdo, int $driverUserId, ?string $tanggal = null): array
    {
        $sql = "SELECT c.*, o.doc_no, o.tanggal, o.status AS do_status, o.version AS do_version,
                       s.canonical_name AS store_name, p.name AS product_name,
                       d.name AS division_name, d.factory_id
                FROM dispatch_claim c
                INNER JOIN delivery_order o ON o.delivery_order_id = c.delivery_order_id
                INNER JOIN store s ON s.store_id = c.store_id
                INNER JOIN product p ON p.product_id = c.product_id
                LEFT JOIN division d ON d.division_id = p.division_id
                WHERE c.driver_user_id = ? AND c.status = 'active'";
        $params = [$driverUserId];
        if ($tanggal !== null) {
            $sql .= ' AND o.tanggal = ?';
            $params[] = $tanggal;
        }
        $sql .= ' ORDER BY s.canonical_name, p.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int,array> this driver's active claims for one specific DO */
    public function findActiveClaimsForDriverAndDo(PDO $pdo, int $driverUserId, int $doId): array
    {
        $stmt = $pdo->prepare(
            "SELECT c.*, p.name AS product_name, d.factory_id, d.name AS division_name
             FROM dispatch_claim c
             INNER JOIN product p ON p.product_id = c.product_id
             LEFT JOIN division d ON d.division_id = p.division_id
             WHERE c.driver_user_id = ? AND c.delivery_order_id = ? AND c.status = 'active'
             ORDER BY p.name"
        );
        $stmt->execute([$driverUserId, $doId]);
        return $stmt->fetchAll();
    }

    /**
     * Every dispatch_claim resolved INTO this shipment (a shipment can be
     * fed by more than one claim — e.g. two products claimed separately).
     * Used only for the read-only driver/admin shipment-detail timeline's
     * "Driver Claim" event (earliest created_at among these); never a
     * mutation path.
     * @return array<int,array>
     */
    public function findClaimsForShipment(PDO $pdo, int $shipmentId): array
    {
        $stmt = $pdo->prepare(
            'SELECT dc.*, u.full_name AS driver_full_name, u.username AS driver_username
             FROM dispatch_claim dc
             INNER JOIN users u ON u.user_id = dc.driver_user_id
             WHERE dc.shipment_id = ? ORDER BY dc.created_at ASC'
        );
        $stmt->execute([$shipmentId]);
        return $stmt->fetchAll();
    }

    /** Resolves a claim at departure time: some qty departed, the rest auto-released — always terminal ('departed'). */
    public function resolveClaimAsDeparted(PDO $pdo, int $claimId, float $departedQty, float $releasedQty, ?int $shipmentId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE dispatch_claim
             SET active_qty = 0, departed_qty = departed_qty + ?, released_qty = released_qty + ?,
                 status = 'departed', shipment_id = ?, version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE dispatch_claim_id = ?"
        );
        $stmt->execute([$departedQty, $releasedQty, $shipmentId, $claimId]);
    }

    public function releaseClaim(PDO $pdo, int $claimId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE dispatch_claim
             SET released_qty = released_qty + active_qty, active_qty = 0,
                 status = 'released', version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE dispatch_claim_id = ?"
        );
        $stmt->execute([$claimId]);
    }

    public function cancelActiveClaimsForDo(PDO $pdo, int $doId): int
    {
        $stmt = $pdo->prepare(
            "UPDATE dispatch_claim
             SET released_qty = released_qty + active_qty, active_qty = 0,
                 status = 'cancelled', version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE delivery_order_id = ? AND status = 'active'"
        );
        $stmt->execute([$doId]);
        return $stmt->rowCount();
    }

    // ------------------------------------------------------------------
    // driver_route / driver_route_stop — ordering only.
    // ------------------------------------------------------------------

    public function findOrCreateRoute(PDO $pdo, int $driverUserId, string $tanggal): int
    {
        $stmt = $pdo->prepare('SELECT driver_route_id FROM driver_route WHERE driver_user_id = ? AND tanggal = ?');
        $stmt->execute([$driverUserId, $tanggal]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        try {
            $ins = $pdo->prepare('INSERT INTO driver_route (driver_user_id, tanggal, created_at) VALUES (?, ?, UTC_TIMESTAMP())');
            $ins->execute([$driverUserId, $tanggal]);
            return (int) $pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $stmt->execute([$driverUserId, $tanggal]);
                return (int) $stmt->fetchColumn();
            }
            throw $e;
        }
    }

    public function findRouteId(PDO $pdo, int $driverUserId, string $tanggal): ?int
    {
        $stmt = $pdo->prepare('SELECT driver_route_id FROM driver_route WHERE driver_user_id = ? AND tanggal = ?');
        $stmt->execute([$driverUserId, $tanggal]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** Adds $storeId to the route at the end of the sequence if not already present. Idempotent. */
    public function ensureStop(PDO $pdo, int $routeId, int $storeId): void
    {
        $stmt = $pdo->prepare('SELECT driver_route_stop_id FROM driver_route_stop WHERE driver_route_id = ? AND store_id = ?');
        $stmt->execute([$routeId, $storeId]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }
        $next = $pdo->prepare('SELECT COALESCE(MAX(sequence), 0) + 1 FROM driver_route_stop WHERE driver_route_id = ?');
        $next->execute([$routeId]);
        $seq = (int) $next->fetchColumn();
        try {
            $ins = $pdo->prepare('INSERT INTO driver_route_stop (driver_route_id, store_id, sequence, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())');
            $ins->execute([$routeId, $storeId, $seq]);
        } catch (\PDOException $e) {
            if ((int) $e->getCode() !== 23000) {
                throw $e;
            } // else: another concurrent claim already added this exact stop — fine, idempotent
        }
    }

    /** @return array<int,array> route stops in sequence order */
    public function findStops(PDO $pdo, int $routeId): array
    {
        $stmt = $pdo->prepare(
            'SELECT rs.*, s.canonical_name AS store_name FROM driver_route_stop rs
             INNER JOIN store s ON s.store_id = rs.store_id
             WHERE rs.driver_route_id = ? ORDER BY rs.sequence'
        );
        $stmt->execute([$routeId]);
        return $stmt->fetchAll();
    }

    /** @param int[] $storeIdsInOrder */
    public function reorderStops(PDO $pdo, int $routeId, array $storeIdsInOrder): void
    {
        $stmt = $pdo->prepare('UPDATE driver_route_stop SET sequence = ?, updated_at = UTC_TIMESTAMP() WHERE driver_route_id = ? AND store_id = ?');
        foreach ($storeIdsInOrder as $i => $storeId) {
            $stmt->execute([$i + 1, $routeId, $storeId]);
        }
    }

    // ------------------------------------------------------------------
    // Driver shipment history — reuses the existing shipment/shipment_item
    // tables, filtered by shipped_by, never a separate log.
    // ------------------------------------------------------------------

    /**
     * @return array<int,array> each row is a shipment header PLUS
     * product_count/total_qty aggregated from its OWN shipment_item rows
     * (one extra correlated-subquery pair per row, not a per-row extra
     * round-trip — see the real-UAT "Riwayat card must show 2 produk · 7
     * pcs, not just the store/DO/date" requirement).
     */
    public function findShipmentHistoryForDriver(PDO $pdo, int $driverUserId, int $limit = 50): array
    {
        $stmt = $pdo->prepare(
            "SELECT sh.*, s.canonical_name AS store_name, o.doc_no,
                    (SELECT COUNT(*) FROM shipment_item si WHERE si.shipment_id = sh.shipment_id) AS product_count,
                    (SELECT COALESCE(SUM(si.qty), 0) FROM shipment_item si WHERE si.shipment_id = sh.shipment_id) AS total_qty
             FROM shipment sh
             INNER JOIN store s ON s.store_id = sh.store_id
             LEFT JOIN delivery_order o ON o.delivery_order_id = sh.delivery_order_id
             WHERE sh.shipped_by = ? ORDER BY sh.shipment_id DESC LIMIT " . max(1, min(200, $limit))
        );
        $stmt->execute([$driverUserId]);
        return $stmt->fetchAll();
    }

    /**
     * Real (actual-shipped) product/qty totals per store, for THIS driver,
     * on THIS date — aggregated across every shipment(s) that store may
     * have (a route stop can legitimately have more than one, e.g. a
     * MAIN + PASTRY split). This is the source of truth for a DEPARTED
     * route stop's summary — never the driver's now-resolved dispatch_claim
     * rows, which is the real-UAT bug this method fixes (see
     * DispatchService::myRoute()'s own docblock for the full root-cause
     * writeup: active_qty on a 'departed' claim is reset to 0, so summing
     * claims after departure always reads 0/0 regardless of what actually
     * shipped).
     * @return array<int,array{productCount:int,totalQty:float}> keyed by store_id
     */
    public function findDepartedTotalsForDriver(PDO $pdo, int $driverUserId, string $tanggal): array
    {
        $stmt = $pdo->prepare(
            "SELECT sh.store_id, COUNT(DISTINCT si.product_id) AS product_count, COALESCE(SUM(si.qty), 0) AS total_qty
             FROM shipment sh
             INNER JOIN shipment_item si ON si.shipment_id = sh.shipment_id
             WHERE sh.shipped_by = ? AND sh.tanggal = ? AND sh.status = 'active'
             GROUP BY sh.store_id"
        );
        $stmt->execute([$driverUserId, $tanggal]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['store_id']] = ['productCount' => (int) $r['product_count'], 'totalQty' => (float) $r['total_qty']];
        }
        return $out;
    }
}
