<?php

declare(strict_types=1);

namespace Amor\Api\SpecialOrder;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\Fg\FgRepository;
use PDO;

/**
 * "Existing FG Allocation Bridge" — lets a special/non-regular order's
 * existing-product line draw from the SAME general FG pool Regular PO
 * ships from (stock_balance/stock_ledger), instead of dead-ending on
 * "no production yet = can't proceed" (the real cPanel UAT bug this
 * feature exists to fix — see migration 0012's own docblock on
 * special_order_fg_allocation for the full rationale).
 *
 * CRITICAL STOCK PRINCIPLE (binding, never relax): an allocation is an
 * EARMARK, not a stock movement. allocate()/release() NEVER write
 * stock_ledger — only a real dispatch consuming an allocation does (see
 * SpecialOrderDoService::dispatch(), which calls consumeForDispatch()
 * below). stock_ledger/stock_balance remain the single source of truth
 * for physical stock; this table only tracks who has first claim on it.
 *
 * CUSTOM ITEM RULE: applies ONLY to item_type='existing_product' lines —
 * enforced in allocate() below. special_catalog (custom) items can never
 * reach a product_id-keyed FG pool at all.
 *
 * "TRUE FREE FG" (task's own "IMPORTANT — FREE FG"): every allocate()
 * call locks the product+factory's stock_balance row FIRST (same
 * lockBalance() Regular PO's own ShipmentService::ship() uses), then
 * computes physical qty_on_hand MINUS every OTHER active/
 * partially_consumed allocation for that same product+factory — never
 * raw qty_on_hand alone. Holding that lock across both the read and the
 * INSERT is what makes two concurrent "Order A wants 8, Order B wants 8,
 * physical=10" allocation attempts serialize correctly instead of both
 * succeeding.
 *
 * GLOBAL RESERVATION, NOT JUST BETWEEN SPECIAL ORDERS (cross-flow deep-
 * check fix): the SAME "true free FG" formula, and the SAME
 * sumActiveAllocatedForProductFactory() this class uses, is also
 * consulted by Regular PO's own Delivery\ShipmentService::preview()/
 * ship() — see that class's own docblock for the canonical lock order
 * both sides honor. A special order's active allocation is therefore
 * invisible to Regular PO shipment too, not only to other special orders.
 * consumeForDispatch() below is the one exception: it is consuming its
 * OWN already-reserved allocation, so it never re-subtracts reservations
 * — it only re-locks and re-verifies the real physical stock_balance
 * immediately before writing the ledger deduction (never trusting that
 * physical stock is still there just because the allocation exists).
 *
 * STOCK LEDGER TRACEABILITY (source_type='special_order_fg_allocation'):
 * source_id is deliberately the causing special_order_do_shipment_item_id
 * (the real physical dispatch line), not a per-allocation-row id. One
 * shipment line may FIFO-consume several allocation rows but always
 * writes exactly ONE ledger row — the ledger's job is "physical stock
 * changed because of THIS shipment", not "which allocation row paid for
 * it". That finer-grained split is already queryable (each allocation
 * row's own consumed_qty, plus the dispatch's own audit_log entry listing
 * fromGeneralFg/fromSpecialProduction per item) — a dedicated per-
 * consumption-event table was considered and rejected as unnecessary
 * schema for what audit_log + the shipment-line reference already trace
 * sufficiently.
 */
final class SpecialOrderFgAllocationService
{
    /** "Good Actual Special Production already completed" per the task's own formula — fg_verified_qty is the existing verified/good special-production pool SpecialOrderDoService::create() already treats as shippable. */
    private SpecialOrderRepository $orderRepo;
    private SpecialOrderFgAllocationRepository $allocRepo;
    private DoRepository $doRepo;
    private FgRepository $fgRepo;

    public function __construct(private PDO $pdo)
    {
        $this->orderRepo = new SpecialOrderRepository();
        $this->allocRepo = new SpecialOrderFgAllocationRepository();
        $this->doRepo = new DoRepository();
        $this->fgRepo = new FgRepository();
    }

    /**
     * POST /api/special-orders/items/{itemId}/allocate-fg — the explicit
     * "Alokasikan dari FG" operator action (task's own "AUTO VS MANUAL":
     * never silently auto-reserve just because a page opened).
     */
    public function allocate(int $itemId, float $requestedQty, int $userId, ?string $requestId): array
    {
        if ($requestedQty <= 0.0001) {
            throw new ApiException(400, 'INVALID_QTY', 'Allocation quantity must be greater than zero');
        }

        $item = $this->orderRepo->lockItemById($this->pdo, $itemId);
        if ($item === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Special order item not found');
        }
        if ($item['item_type'] !== 'existing_product' || $item['product_id'] === null) {
            throw new ApiException(400, 'CUSTOM_ITEM_NOT_ALLOCATABLE', 'General FG allocation only applies to existing-product items, never custom/special_catalog items');
        }
        if (in_array($item['order_status'], ['cancelled', 'completed'], true)) {
            throw new ApiException(400, 'INVALID_ORDER_STATUS', 'Cannot allocate FG for a cancelled or completed order');
        }

        $productId = (int) $item['product_id'];
        $factoryId = (int) $item['item_factory_id'];
        $locationId = $this->fgRepo->findOrCreateLocationForFactory($this->pdo, $factoryId, (string) $item['item_factory_name']);

        // Lock stock_balance FIRST (same discipline as Delivery\ShipmentService::ship()) so the free-FG read below is atomic against a concurrent allocator.
        $balanceRow = $this->doRepo->lockBalance($this->pdo, $productId, $locationId);
        $physical = $balanceRow !== null ? (float) $balanceRow['qty_on_hand'] : 0.0;
        $reservedByAnyOrder = $this->allocRepo->sumActiveAllocatedForProductFactory($this->pdo, $productId, $factoryId);
        $trueFree = max(0.0, $physical - $reservedByAnyOrder);

        $committed = $this->allocRepo->sumCommittedForItem($this->pdo, $itemId);
        $goodActualSpecialProduction = (float) $item['fg_verified_qty'];
        $capForNewAllocation = max(0.0, (float) $item['qty'] - $committed - $goodActualSpecialProduction);

        if ($requestedQty > $capForNewAllocation + 0.0001) {
            throw new ApiException(409, 'EXCEEDS_ORDER_REMAINING', "Requested {$requestedQty} exceeds this item's remaining unfulfilled qty {$capForNewAllocation}");
        }
        if ($requestedQty > $trueFree + 0.0001) {
            throw new ApiException(409, 'EXCEEDS_FREE_FG', "Requested {$requestedQty} exceeds true free General FG {$trueFree}");
        }

        $allocationId = $this->allocRepo->insertAllocation(
            $this->pdo,
            $itemId,
            (int) $item['special_order_id'],
            (string) $item['source_type'],
            $productId,
            $factoryId,
            $requestedQty,
            $userId
        );

        Audit::write(
            $this->pdo, $requestId, $userId, 'special_order_fg_allocation.created', 'special_order_fg_allocation',
            (string) $allocationId, 'ok', null, null,
            ['itemId' => $itemId, 'productId' => $productId, 'factoryId' => $factoryId, 'qty' => $requestedQty]
        );

        return $this->itemAllocationView($itemId);
    }

    /** POST /api/special-order-fg-allocations/{id}/release — manual release, and the primitive releaseAllForOrder()/releaseAllForItem() build on. */
    public function release(int $allocationId, int $userId, ?string $requestId): array
    {
        $row = $this->allocRepo->lockAllocation($this->pdo, $allocationId);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'FG allocation not found');
        }
        $released = $this->allocRepo->releaseRemaining($this->pdo, $row);
        if ($released > 0.0001) {
            Audit::write(
                $this->pdo, $requestId, $userId, 'special_order_fg_allocation.released', 'special_order_fg_allocation',
                (string) $allocationId, 'ok', null, null, ['releasedQty' => $released]
            );
        }
        return $this->itemAllocationView((int) $row['special_order_item_id']);
    }

    /**
     * Bulk-release every still-active allocation belonging to one order —
     * called from SpecialOrderService::cancelOrder() ("if order cancelled
     * BEFORE shipment, release unused active General FG allocation").
     * Caller already holds the order's own lock/transaction; this method
     * takes $pdo explicitly so it participates in that SAME transaction
     * rather than opening its own.
     */
    public function releaseAllForOrder(PDO $pdo, int $orderId, int $userId, ?string $requestId): void
    {
        $rows = $this->allocRepo->lockActiveForOrder($pdo, $orderId);
        foreach ($rows as $row) {
            $released = $this->allocRepo->releaseRemaining($pdo, $row);
            if ($released > 0.0001) {
                Audit::write(
                    $pdo, $requestId, $userId, 'special_order_fg_allocation.released', 'special_order_fg_allocation',
                    (string) $row['special_order_fg_allocation_id'], 'ok', null, null,
                    ['releasedQty' => $released, 'reason' => 'order_cancelled']
                );
            }
        }
    }

    /**
     * Real dispatch consumption — called from SpecialOrderDoService's
     * private dispatch() method for the General-FG portion of a shipment
     * line, AFTER dispatch has already validated total headroom against
     * the allocation's own bookkeeping (task's own "DISPATCH — Existing FG
     * Allocation", steps 1 done by the caller). This method itself
     * performs steps 2-6: (2) locks stock_balance and re-verifies REAL
     * physical qty_on_hand >= qty (the cross-flow deep-check's own "Special
     * dispatch does not revalidate physical stock" fix — an allocation
     * existing is never proof physical stock is still there), (3) FIFO-
     * consumes allocation rows, then (4-6) posts exactly ONE real
     * stock_ledger deduction for the combined qty + upserts stock_balance,
     * mirroring Delivery\DoRepository::postShipmentOutLedger() but with
     * source_type='special_order_fg_allocation' so it's traceable back to
     * the causing special_order_do_shipment_item without ever claiming
     * Regular PO's own 'shipment_item' source_type.
     *
     * CONSUMPTION ORDER (documented strategy, per the task's own request):
     * General FG is consumed FIRST, oldest allocation row first — the
     * caller computes fromGeneral=min(requested, generalHeadroom) and
     * calls this ONLY with that already-capped qty; any remainder is left
     * to special-production fulfillment (unchanged existing behavior),
     * so this method never itself decides the split.
     */
    public function consumeForDispatch(int $specialOrderItemId, int $productId, int $factoryId, float $qty, int $shipmentItemId, string $eventDate, ?int $userId): void
    {
        if ($qty <= 0.0001) {
            return;
        }

        // CRITICAL BUG FIX (cross-flow reservation deep-check): this
        // dispatch is consuming its OWN already-reserved allocation, so it
        // does NOT re-subtract other orders' reservations (that rule only
        // applies to a NEW allocate() or a Regular PO ship() — see this
        // repository's own class docblock) — but it MUST still re-lock and
        // re-verify the REAL physical stock_balance under lock immediately
        // before writing the ledger deduction, per the canonical lock
        // order (stock_balance row FIRST, held for the whole sequence).
        // Without this, a concurrent flow that already depleted physical
        // stock since this allocation was created could drive stock_balance
        // negative.
        $locationId = $this->fgRepo->findOrCreateLocationForFactory($this->pdo, $factoryId, '');
        $balanceRow = $this->doRepo->lockBalance($this->pdo, $productId, $locationId);
        $physical = $balanceRow !== null ? (float) $balanceRow['qty_on_hand'] : 0.0;
        if ($qty > $physical + 0.0001) {
            throw new ApiException(409, 'INSUFFICIENT_PHYSICAL_FG', "Product {$productId}: dispatching {$qty} from General FG allocation exceeds physical stock on hand {$physical}");
        }

        $rows = $this->allocRepo->lockActiveForItem($this->pdo, $specialOrderItemId);
        $remainingToConsume = $qty;
        foreach ($rows as $row) {
            if ($remainingToConsume <= 0.0001) {
                break;
            }
            $rowRemaining = (float) $row['allocated_qty'] - (float) $row['consumed_qty'] - (float) $row['released_qty'];
            if ($rowRemaining <= 0.0001) {
                continue;
            }
            $take = min($rowRemaining, $remainingToConsume);
            $this->allocRepo->consume($this->pdo, $row, $take);
            $remainingToConsume -= $take;
        }

        if ($remainingToConsume > 0.0001) {
            throw new ApiException(409, 'INSUFFICIENT_ALLOCATION', "Item {$specialOrderItemId}: requested {$qty} exceeds active General FG allocation");
        }

        $this->postAllocationConsumptionLedger($productId, $locationId, $qty, $eventDate, $shipmentItemId, $userId);
    }

    private function postAllocationConsumptionLedger(int $productId, int $locationId, float $qty, string $eventDate, int $shipmentItemId, ?int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO stock_ledger
                (product_id, location_id, event_type, qty_delta, source_type, source_id, event_date, created_at, created_by, notes)
             VALUES (?, ?, 'shipment_out', ?, 'special_order_fg_allocation', ?, ?, UTC_TIMESTAMP(), ?, NULL)"
        );
        $stmt->execute([$productId, $locationId, -$qty, $shipmentItemId, $eventDate, $userId]);
        $ledgerId = (int) $this->pdo->lastInsertId();

        $upsert = $this->pdo->prepare(
            'INSERT INTO stock_balance (product_id, location_id, qty_on_hand, last_ledger_id, updated_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand), last_ledger_id = VALUES(last_ledger_id), updated_at = UTC_TIMESTAMP()'
        );
        $upsert->execute([$productId, $locationId, -$qty, $ledgerId]);
    }

    /**
     * How much of one item's TOTAL real headroom for a NEW dispatch comes
     * from General FG right now — used by SpecialOrderDoService to cap a
     * shipment line without double-deducting against special-production
     * headroom. Non-locking (dispatch's own private method re-locks via
     * consumeForDispatch()/lockActiveForItem() at the moment it actually
     * commits).
     */
    public function generalFgHeadroomForItem(int $specialOrderItemId): float
    {
        return $this->allocRepo->sumActiveRemainingForItem($this->pdo, $specialOrderItemId);
    }

    /** shippedFromGeneral / shippedFromSpecial split for one item, derived (never a stored column) — "FG Khusus/Non-Toko" view's "1. Dari FG Existing" / "2. Dari Produksi Khusus". */
    public function shippedSplitForItem(int $specialOrderItemId): array
    {
        $shippedFromGeneral = $this->allocRepo->sumConsumedForItem($this->pdo, $specialOrderItemId);
        $totalShipped = $this->orderRepo->sumShippedForItem($this->pdo, $specialOrderItemId);
        return [
            'shippedFromGeneral' => $shippedFromGeneral,
            'shippedFromSpecial' => max(0.0, $totalShipped - $shippedFromGeneral),
        ];
    }

    /**
     * Cheap per-item allocation summary reused by both productionInbox()
     * and fgEligibleItems() in SpecialOrderService — remaining is the
     * still-active/unconsumed reservation ("1. Dari FG Existing", ready
     * to ship right now); committed is remaining+consumed (never
     * released), the true total General FG has ever claimed for this
     * item, used to cap a NEW allocation request and to decide whether
     * the item belongs in the FG-eligible inbox at all even with zero
     * special production (the exact dead-end this feature fixes).
     */
    public function allocationSummaryForItem(int $itemId): array
    {
        return [
            'remaining' => $this->allocRepo->sumActiveRemainingForItem($this->pdo, $itemId),
            'committed' => $this->allocRepo->sumCommittedForItem($this->pdo, $itemId),
            'consumed' => $this->allocRepo->sumConsumedForItem($this->pdo, $itemId),
        ];
    }

    /**
     * Full allocation view for one existing-product item — the fields
     * "Order Masuk / Demand Tambahan" (produksi-demand.php) and the
     * allocate-fg response both need. Read-only best-effort (no lock):
     * true-free-FG here is advisory display, re-validated for real inside
     * allocate()'s own locked section.
     */
    public function itemAllocationView(int $itemId): array
    {
        $item = $this->orderRepo->findItemById($this->pdo, $itemId);
        if ($item === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Special order item not found');
        }

        if ($item['item_type'] !== 'existing_product' || $item['product_id'] === null) {
            return [
                'itemId' => $itemId,
                'allocatable' => false,
                'fgAvailable' => null,
                'allocatedFromGeneralFg' => 0.0,
                'productionNeed' => null,
                'maxAllocatable' => 0.0,
                'status' => null,
            ];
        }

        $productId = (int) $item['product_id'];
        $factoryId = (int) $item['item_factory_id'];
        $physical = $this->orderRepo->findStockOnHand($this->pdo, $productId, $factoryId);
        $reservedByAnyOrder = $this->allocRepo->sumActiveAllocatedForProductFactory($this->pdo, $productId, $factoryId);
        $trueFree = max(0.0, $physical - $reservedByAnyOrder);

        $committed = $this->allocRepo->sumCommittedForItem($this->pdo, $itemId);
        $goodActualSpecialProduction = (float) $item['fg_verified_qty'];
        $qty = (float) $item['qty'];
        $remaining = max(0.0, $qty - $committed - $goodActualSpecialProduction);
        $productionNeed = $remaining;
        $maxAllocatable = min($remaining, $trueFree);

        return [
            'itemId' => $itemId,
            'allocatable' => true,
            'qty' => $qty,
            'fgAvailable' => $trueFree,
            'allocatedFromGeneralFg' => $committed,
            'goodActualSpecialProduction' => $goodActualSpecialProduction,
            'productionNeed' => $productionNeed,
            'maxAllocatable' => max(0.0, $maxAllocatable),
            'status' => $this->statusLabel($remaining, $committed, $trueFree),
        ];
    }

    private function statusLabel(float $remaining, float $committedGeneralFg, float $trueFree): string
    {
        if ($remaining <= 0.0001) {
            return $committedGeneralFg > 0.0001 ? 'Tidak Perlu Produksi' : 'Siap ke DO';
        }
        if ($committedGeneralFg > 0.0001) {
            return 'Sudah Dialokasikan';
        }
        if ($trueFree >= $remaining - 0.0001) {
            return 'Bisa Dipenuhi dari FG';
        }
        if ($trueFree > 0.0001) {
            return 'Sebagian FG / Perlu Produksi';
        }
        return 'Perlu Produksi';
    }
}
