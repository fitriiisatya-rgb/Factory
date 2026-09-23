<?php

declare(strict_types=1);

namespace Amor\Api\SpecialOrder;

use PDO;

/**
 * Data access for special_order_fg_allocation (migration 0012, "Existing
 * FG Allocation Bridge") — see the migration's own docblock for the full
 * rationale and the status-derivation rules this class enforces on every
 * write. Mirrors SpecialOrderRepository/SpecialOrderDoRepository's own
 * shape (plain prepared statements, no ORM).
 */
final class SpecialOrderFgAllocationRepository
{
    public function insertAllocation(
        PDO $pdo,
        int $specialOrderItemId,
        int $specialOrderId,
        string $sourceType,
        int $productId,
        int $factoryId,
        float $qty,
        int $userId
    ): int {
        $stmt = $pdo->prepare(
            "INSERT INTO special_order_fg_allocation
                (special_order_item_id, special_order_id, source_type, product_id, factory_id,
                 allocated_qty, consumed_qty, released_qty, status, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, 0, 'active', ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([$specialOrderItemId, $specialOrderId, $sourceType, $productId, $factoryId, $qty, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public function findAllocation(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM special_order_fg_allocation WHERE special_order_fg_allocation_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Row-locks one allocation for the duration of the caller's transaction (release()/consume() single-row path). */
    public function lockAllocation(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM special_order_fg_allocation WHERE special_order_fg_allocation_id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * FIFO consumption order at real dispatch (task's own recommended
     * strategy, documented in SpecialOrderDoService::dispatch()'s own
     * docblock) — oldest allocation first, fixed id order as the
     * deadlock-avoidance tiebreaker (same "smallest id first" discipline
     * as every other multi-row lock in this codebase). Only 'active'/
     * 'partially_consumed' rows carry remaining reservation.
     * @return array<int,array>
     */
    public function lockActiveForItem(PDO $pdo, int $specialOrderItemId): array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM special_order_fg_allocation
             WHERE special_order_item_id = ? AND status IN ('active','partially_consumed')
             ORDER BY created_at ASC, special_order_fg_allocation_id ASC
             FOR UPDATE"
        );
        $stmt->execute([$specialOrderItemId]);
        return $stmt->fetchAll();
    }

    /** Every allocation for one order, locked — the cancel-order bulk-release path. */
    public function lockActiveForOrder(PDO $pdo, int $specialOrderId): array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM special_order_fg_allocation
             WHERE special_order_id = ? AND status IN ('active','partially_consumed')
             ORDER BY created_at ASC, special_order_fg_allocation_id ASC
             FOR UPDATE"
        );
        $stmt->execute([$specialOrderId]);
        return $stmt->fetchAll();
    }

    /** @return array<int,array> plain (non-locking) read — for display (UI list of an item's own allocation history). */
    public function findForItem(PDO $pdo, int $specialOrderItemId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM special_order_fg_allocation WHERE special_order_item_id = ? ORDER BY created_at ASC, special_order_fg_allocation_id ASC'
        );
        $stmt->execute([$specialOrderItemId]);
        return $stmt->fetchAll();
    }

    /**
     * Remaining (unconsumed, unreleased) reservation for one item — the
     * "still earmarked, ready to ship from General FG" headroom used at
     * DO-creation and dispatch time.
     */
    public function sumActiveRemainingForItem(PDO $pdo, int $specialOrderItemId): float
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(allocated_qty - consumed_qty - released_qty), 0)
             FROM special_order_fg_allocation
             WHERE special_order_item_id = ? AND status IN ('active','partially_consumed')"
        );
        $stmt->execute([$specialOrderItemId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Total non-released commitment for one item (allocated_qty -
     * released_qty, across EVERY status) — whether still reserved or
     * already physically shipped, this is "how much of this order's
     * demand General FG has already claimed", the cap a NEW allocation
     * request must respect alongside Good Actual Special Production
     * (task's own allocation formula).
     */
    public function sumCommittedForItem(PDO $pdo, int $specialOrderItemId): float
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(allocated_qty - released_qty), 0) FROM special_order_fg_allocation WHERE special_order_item_id = ?'
        );
        $stmt->execute([$specialOrderItemId]);
        return (float) $stmt->fetchColumn();
    }

    /** How much of this item's total REAL shipped qty (special_order_do_shipment_item) came from General FG — the split needed to avoid double-counting special-production headroom. */
    public function sumConsumedForItem(PDO $pdo, int $specialOrderItemId): float
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(consumed_qty), 0) FROM special_order_fg_allocation WHERE special_order_item_id = ?');
        $stmt->execute([$specialOrderItemId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * TRUE free General FG for one product+factory — physical stock minus
     * every OTHER order's still-active reservation (task's own "must be
     * physical FG available minus active unconsumed allocations" —
     * prevents two special orders from reserving the same stock). Caller
     * must already hold the stock_balance row lock (Delivery\DoRepository
     * ::lockBalance()) before calling this, so the two reads are atomic
     * against a concurrent allocator (same discipline as
     * Delivery\ShipmentService::ship()'s own lockBalance()-then-check).
     */
    /**
     * FOR UPDATE is REQUIRED here, not optional (cross-flow deep-check
     * fix): under InnoDB REPEATABLE READ, an ordinary SELECT reads the
     * consistent snapshot established by the transaction's FIRST
     * consistent (non-locking) read — which, in a caller like
     * Delivery\ShipmentService::ship() that runs other plain SELECTs
     * (findDoItems/shippedQtyByProduct) BEFORE reaching this call, would
     * already be stale by the time execution gets here, even though the
     * stock_balance row was freshly re-locked moments earlier. A locking
     * read always returns the latest COMMITTED data regardless of when
     * the transaction's snapshot was established — this is what makes a
     * concurrent allocate() that just committed actually visible to a
     * Regular PO ship() checking trueFree right after. Confirmed via a
     * real two-process race test (ALLOC-GLOBAL-05) that intermittently
     * failed before this fix.
     */
    public function sumActiveAllocatedForProductFactory(PDO $pdo, int $productId, int $factoryId): float
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(allocated_qty - consumed_qty - released_qty), 0)
             FROM special_order_fg_allocation
             WHERE product_id = ? AND factory_id = ? AND status IN ('active','partially_consumed')
             FOR UPDATE"
        );
        $stmt->execute([$productId, $factoryId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Applies a consumption of $qty to one already-locked allocation row
     * (caller has already verified $qty <= remaining) — increments
     * consumed_qty and rewrites status from the real resulting sums,
     * never hand-set.
     */
    public function consume(PDO $pdo, array $lockedRow, float $qty): void
    {
        $consumed = (float) $lockedRow['consumed_qty'] + $qty;
        $this->writeConsumedReleased($pdo, (int) $lockedRow['special_order_fg_allocation_id'], $consumed, (float) $lockedRow['released_qty'], (float) $lockedRow['allocated_qty']);
    }

    /** Releases the entire remaining (unconsumed) reservation of one already-locked allocation row — idempotent (a zero-remaining row is simply left untouched). */
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
        $this->writeConsumedReleased($pdo, (int) $lockedRow['special_order_fg_allocation_id'], $consumed, $releasedAfter, $allocated);
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
            'UPDATE special_order_fg_allocation SET consumed_qty = ?, released_qty = ?, status = ?, updated_at = UTC_TIMESTAMP() WHERE special_order_fg_allocation_id = ?'
        );
        $stmt->execute([$consumedQty, $releasedQty, $status, $id]);
    }
}
