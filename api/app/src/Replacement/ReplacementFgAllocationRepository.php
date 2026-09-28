<?php

declare(strict_types=1);

namespace Amor\Api\Replacement;

use PDO;

/**
 * Data access for replacement_demand_fg_allocation (migration 0016) —
 * structural sibling of SpecialOrder\SpecialOrderFgAllocationRepository;
 * see that class's own docblock for the full "TRUE FREE FG"/consumption
 * discipline this mirrors exactly.
 */
final class ReplacementFgAllocationRepository
{
    public function insertAllocation(PDO $pdo, int $demandId, int $productId, int $factoryId, float $qty, int $userId): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO replacement_demand_fg_allocation
                (replacement_demand_id, product_id, factory_id, allocated_qty, consumed_qty, released_qty, status, created_by, created_at)
             VALUES (?, ?, ?, ?, 0, 0, 'active', ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([$demandId, $productId, $factoryId, $qty, $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** FIFO consumption order at real dispatch — same "smallest id first" deadlock-avoidance discipline as SpecialOrderFgAllocationRepository::lockActiveForItem(). */
    public function lockActiveForDemand(PDO $pdo, int $demandId): array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM replacement_demand_fg_allocation
             WHERE replacement_demand_id = ? AND status IN ('active','partially_consumed')
             ORDER BY created_at ASC, replacement_demand_fg_allocation_id ASC
             FOR UPDATE"
        );
        $stmt->execute([$demandId]);
        return $stmt->fetchAll();
    }

    public function lockAllocation(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM replacement_demand_fg_allocation WHERE replacement_demand_fg_allocation_id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Remaining (unconsumed, unreleased) reservation for one demand — the still-active earmark ready to ship right now. */
    public function sumActiveRemainingForDemand(PDO $pdo, int $demandId): float
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(allocated_qty - consumed_qty - released_qty), 0)
             FROM replacement_demand_fg_allocation
             WHERE replacement_demand_id = ? AND status IN ('active','partially_consumed')"
        );
        $stmt->execute([$demandId]);
        return (float) $stmt->fetchColumn();
    }

    /** How much of this demand's total REAL shipped qty came from its own FG allocation (never released) — the split needed to avoid double-counting production headroom, mirroring SpecialOrderFgAllocationRepository::sumConsumedForItem(). */
    public function sumConsumedForDemand(PDO $pdo, int $demandId): float
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(consumed_qty), 0) FROM replacement_demand_fg_allocation WHERE replacement_demand_id = ?');
        $stmt->execute([$demandId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * TRUE free General FG contribution from Replacement's OWN side —
     * every OTHER demand's still-active reservation for one product+
     * factory, FOR UPDATE for the identical REPEATABLE READ correctness
     * reason documented on SpecialOrderFgAllocationRepository::
     * sumActiveAllocatedForProductFactory() (that class's own widened
     * version of this same query is the one every real allocation
     * decision — Regular PO, Special, and this class's own allocate() —
     * actually calls; this method exists so that widening has something
     * of THIS table's own to add).
     */
    public function sumActiveAllocatedForProductFactory(PDO $pdo, int $productId, int $factoryId): float
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(allocated_qty - consumed_qty - released_qty), 0)
             FROM replacement_demand_fg_allocation
             WHERE product_id = ? AND factory_id = ? AND status IN ('active','partially_consumed')
             FOR UPDATE"
        );
        $stmt->execute([$productId, $factoryId]);
        return (float) $stmt->fetchColumn();
    }

    public function consume(PDO $pdo, array $lockedRow, float $qty): void
    {
        $consumed = (float) $lockedRow['consumed_qty'] + $qty;
        $this->writeConsumedReleased($pdo, (int) $lockedRow['replacement_demand_fg_allocation_id'], $consumed, (float) $lockedRow['released_qty'], (float) $lockedRow['allocated_qty']);
    }

    public function releaseRemaining(PDO $pdo, array $lockedRow): float
    {
        $allocated = (float) $lockedRow['allocated_qty'];
        $consumed = (float) $lockedRow['consumed_qty'];
        $releasedBefore = (float) $lockedRow['released_qty'];
        $remaining = max(0.0, $allocated - $consumed - $releasedBefore);
        if ($remaining <= 0.0001) {
            return 0.0;
        }
        $releasedAfter = $releasedBefore + $remaining;
        $this->writeConsumedReleased($pdo, (int) $lockedRow['replacement_demand_fg_allocation_id'], $consumed, $releasedAfter, $allocated);
        return $remaining;
    }

    private function writeConsumedReleased(PDO $pdo, int $id, float $consumedQty, float $releasedQty, float $allocatedQty): void
    {
        $remaining = $allocatedQty - $consumedQty - $releasedQty;
        if ($remaining > 0.0001) {
            $status = 'active';
        } elseif ($consumedQty > 0.0001 && $releasedQty > 0.0001) {
            $status = 'partially_consumed';
        } elseif ($consumedQty > 0.0001) {
            $status = 'consumed';
        } else {
            $status = 'released';
        }
        $stmt = $pdo->prepare(
            'UPDATE replacement_demand_fg_allocation SET consumed_qty = ?, released_qty = ?, status = ?, updated_at = UTC_TIMESTAMP() WHERE replacement_demand_fg_allocation_id = ?'
        );
        $stmt->execute([$consumedQty, $releasedQty, $status, $id]);
    }
}
