<?php

declare(strict_types=1);

namespace Amor\Api\Replacement;

use Amor\Api\Services\DocumentSequenceService;
use PDO;

/**
 * Data access for replacement_do / replacement_do_shipment_item — the
 * SEPARATE, source-specific DO + real shipment write path for Replacement
 * Reject (migration 0016). Mirrors SpecialOrder\SpecialOrderDoRepository's
 * own shape; see that class's own docblock for why Regular PO's
 * delivery_order/delivery_order_item/shipment_item are never reused here
 * either. A replacement_do folds what would otherwise be a permanent 1:1
 * "item" row directly onto its own header (product_id/planned_qty) —
 * see migration 0016's own docblock for why.
 */
final class ReplacementDoRepository
{
    /** REPL-{Ymd}-{seq:03d} — a distinct, unmistakably-non-regular-DO format, same numbering primitive as every other DO type. */
    public function allocateDoNumber(PDO $pdo, string $tanggal): string
    {
        [$year, $month] = array_map('intval', explode('-', $tanggal));
        $seq = DocumentSequenceService::allocate($pdo, 'REPLACEMENT_DO', $year, $month);
        return sprintf('REPL-%s-%03d', str_replace('-', '', $tanggal), $seq);
    }

    /**
     * uq_replacement_do_demand (migration 0016) makes "one DO per demand"
     * a schema-level guarantee — a duplicate-key exception here means
     * another request already created this demand's DO; the caller
     * re-selects and returns the existing row rather than erroring,
     * exactly the same idempotent-create pattern as Delivery\
     * DoRepository::createDo()'s own uq_delivery_order_open_store guard.
     */
    public function createDo(PDO $pdo, string $docNo, string $tanggal, int $demandId, int $storeId, int $factoryId, int $productId, float $plannedQty, int $userId): array
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO replacement_do
                    (replacement_demand_id, doc_no, tanggal, store_id, factory_id, product_id, planned_qty, status, version, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'open', 1, ?, UTC_TIMESTAMP())"
            );
            $stmt->execute([$demandId, $docNo, $tanggal, $storeId, $factoryId, $productId, $plannedQty, $userId]);
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $existing = $this->findDoByDemandId($pdo, $demandId);
                if ($existing !== null) {
                    return $existing;
                }
            }
            throw $e;
        }
        return $this->findDoById($pdo, (int) $pdo->lastInsertId());
    }

    public function findDoByDemandId(PDO $pdo, int $demandId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM replacement_do WHERE replacement_demand_id = ?');
        $stmt->execute([$demandId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findDoById(PDO $pdo, int $doId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT rdo.*, s.canonical_name AS store_name, p.name AS product_name, f.name AS factory_name,
                    cb.full_name AS created_by_name
             FROM replacement_do rdo
             INNER JOIN store s ON s.store_id = rdo.store_id
             INNER JOIN product p ON p.product_id = rdo.product_id
             INNER JOIN factory f ON f.factory_id = rdo.factory_id
             LEFT JOIN users cb ON cb.user_id = rdo.created_by
             WHERE rdo.replacement_do_id = ?'
        );
        $stmt->execute([$doId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function lockDoById(PDO $pdo, int $doId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM replacement_do WHERE replacement_do_id = ? FOR UPDATE');
        $stmt->execute([$doId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function sumShippedForDo(PDO $pdo, int $doId): float
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM replacement_do_shipment_item WHERE replacement_do_id = ?');
        $stmt->execute([$doId]);
        return (float) $stmt->fetchColumn();
    }

    /** Recomputes and writes status purely from real shipped-vs-planned sums — never touches cancelled_at/cancel_reason. */
    public function refreshStatus(PDO $pdo, int $doId): string
    {
        $do = $this->findDoById($pdo, $doId);
        $shipped = $this->sumShippedForDo($pdo, $doId);
        $planned = (float) $do['planned_qty'];
        if ($shipped <= 0.0001) {
            $status = 'open';
        } elseif ($shipped + 0.0001 < $planned) {
            $status = 'partial';
        } else {
            $status = 'shipped';
        }
        $stmt = $pdo->prepare("UPDATE replacement_do SET status = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE replacement_do_id = ? AND status <> 'cancelled'");
        $stmt->execute([$status, $doId]);
        return $status;
    }

    /**
     * Creates the real shipment header row — the ONLY place a
     * 'replacement_do'-sourced shipment is ever created (same "CRITICAL
     * DISPATCH RULE" as SpecialOrderDoRepository::createShipment()'s own
     * docblock: DO creation never reduces FG, only a real dispatch does).
     * shipment_group is fixed 'OTHER', same as Special/Non-Regular — the
     * MAIN/PASTRY routing tabs are a Regular-PO-only concept.
     */
    public function createShipment(PDO $pdo, int $doId, int $factoryId, int $storeId, string $tanggal, string $docNo, int $userId): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO shipment
                (batch, tanggal, store_id, factory_id, shipment_group, source_type, replacement_do_id,
                 status, version, created_by, shipped_by, shipped_at, created_at)
             VALUES (?, ?, ?, ?, 'OTHER', 'replacement_do', ?, 'active', 1, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmt->execute([$docNo, $tanggal, $storeId, $factoryId, $doId, $userId, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public function insertShipmentLine(PDO $pdo, int $shipmentId, int $doId, float $qty): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO replacement_do_shipment_item (shipment_id, replacement_do_id, qty, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$shipmentId, $doId, $qty]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int,array> every real dispatch line for one shipment, product name joined (ShipmentLineResolver's own normalized shape source). */
    public function findShipmentLines(PDO $pdo, int $shipmentId): array
    {
        $stmt = $pdo->prepare(
            'SELECT rdsi.replacement_do_shipment_item_id, rdsi.replacement_do_id, rdsi.qty,
                    rdo.product_id, rdo.replacement_demand_id, p.name AS product_name,
                    d.name AS division_name, f.name AS factory_name
             FROM replacement_do_shipment_item rdsi
             INNER JOIN replacement_do rdo ON rdo.replacement_do_id = rdsi.replacement_do_id
             INNER JOIN product p ON p.product_id = rdo.product_id
             LEFT JOIN division d ON d.division_id = p.division_id
             INNER JOIN factory f ON f.factory_id = rdo.factory_id
             WHERE rdsi.shipment_id = ?
             ORDER BY rdsi.replacement_do_shipment_item_id'
        );
        $stmt->execute([$shipmentId]);
        return $stmt->fetchAll();
    }

    public function cancel(PDO $pdo, int $doId, int $userId, string $reason): void
    {
        $stmt = $pdo->prepare(
            "UPDATE replacement_do SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), cancelled_by = ?, cancel_reason = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE replacement_do_id = ?"
        );
        $stmt->execute([$userId, $reason, $doId]);
    }
}
