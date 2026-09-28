<?php

declare(strict_types=1);

namespace Amor\Api\Replacement;

use Amor\Api\Audit;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\Fg\FgRepository;
use Amor\Api\SpecialOrder\SpecialOrderFgAllocationRepository;
use PDO;

/**
 * "Check FG First" — the ONE place Replacement Reject decides how much of
 * an approved reject's demand is covered by already-free FG right now,
 * versus how much becomes a real Production Need. Mirrors
 * SpecialOrder\SpecialOrderFgAllocationService's own CRITICAL STOCK
 * PRINCIPLE exactly: an allocation is an EARMARK, never a stock movement
 * — only consumeForDispatch() (a real Replacement DO shipment) ever
 * writes stock_ledger.
 *
 * "DO NOT STEAL STORE FG" (task's own explicit, critical requirement),
 * enforced by TWO subtractions from physical stock, both taken under the
 * SAME stock_balance lock so the whole read is atomic against a
 * concurrent allocator/shipper:
 *   1. every OTHER order's active reservation — special_order_fg_
 *      allocation AND replacement_demand_fg_allocation, via
 *      SpecialOrderFgAllocationRepository::sumActiveAllocatedForProductFactory()
 *      (widened by this same pass to sum BOTH tables — see that method's
 *      own docblock), so Special and Replacement mutually protect each
 *      other's earmarks and Regular PO/Special allocation both already
 *      see this reservation too, with zero further changes to either.
 *   2. every store's own remaining (not-yet-shipped) posted_packed_qty
 *      commitment (Delivery\DoRepository::
 *      sumStoreCommittedRemainingAcrossAllStores(), guarded by
 *      hasAnyStoreAllocation() — a product still entirely in Per Produk
 *      mode has nothing to subtract here, matching Regular shipment's own
 *      exemption for that mode).
 *
 * Allocation happens exactly ONCE, synchronously, inside the SAME
 * transaction that creates the replacement_demand row
 * (ReplacementService::disposeReject()) — unlike Special Order's own
 * repeatable manual "Alokasikan dari FG" action, there is no separate
 * user-facing allocate step: "Check FG First" is not optional, so it is
 * never left for an operator to remember to click.
 */
final class ReplacementFgAllocationService
{
    private ReplacementFgAllocationRepository $allocRepo;
    private SpecialOrderFgAllocationRepository $specialAllocRepo;
    private DoRepository $doRepo;
    private FgRepository $fgRepo;

    public function __construct(private PDO $pdo)
    {
        $this->allocRepo = new ReplacementFgAllocationRepository();
        $this->specialAllocRepo = new SpecialOrderFgAllocationRepository();
        $this->doRepo = new DoRepository();
        $this->fgRepo = new FgRepository();
    }

    /**
     * @return array{allocatedQty:float,productionNeed:float} allocatedQty
     *         is how much of $approvedQty was immediately reserved from
     *         free FG; productionNeed is the remainder (0 when free FG
     *         fully covers it).
     */
    public function allocateAtCreation(int $demandId, int $productId, int $factoryId, float $approvedQty, int $userId, ?string $requestId): array
    {
        if ($approvedQty <= 0.0001) {
            return ['allocatedQty' => 0.0, 'productionNeed' => 0.0];
        }

        $locationId = $this->fgRepo->findOrCreateLocationForFactory($this->pdo, $factoryId, '');

        // Canonical lock order (Delivery\ShipmentService's own docblock,
        // extended by migration 0014): stock_balance FIRST.
        $balanceRow = $this->doRepo->lockBalance($this->pdo, $productId, $locationId);
        $physical = $balanceRow !== null ? (float) $balanceRow['qty_on_hand'] : 0.0;

        $reservedByAnyOrder = $this->specialAllocRepo->sumActiveAllocatedForProductFactory($this->pdo, $productId, $factoryId);

        // hasAnyStoreAllocation()'s own 3rd argument MUST be the real
        // synthetic "NON-OUTLET / PERORANGAN" placeholder store id — every
        // fg_item row (even an un-split, Per Produk one) carries a real,
        // non-NULL store_id (inherited NOT NULL FK from the original 0001
        // schema; see Fg\FgRepository::unallocatedStoreId()'s own
        // docblock), so passing anything else here would incorrectly
        // treat EVERY product as "already store-split".
        $unallocatedStoreId = $this->fgRepo->unallocatedStoreId($this->pdo);
        $hasStoreSplit = $this->doRepo->hasAnyStoreAllocation($this->pdo, $productId, $factoryId, $unallocatedStoreId, true);
        $storeCommitted = $hasStoreSplit
            ? $this->doRepo->sumStoreCommittedRemainingAcrossAllStores($this->pdo, $productId, $factoryId, $unallocatedStoreId, true)
            : 0.0;

        $trueFree = max(0.0, $physical - $reservedByAnyOrder - $storeCommitted);
        $toAllocate = min($approvedQty, $trueFree);

        if ($toAllocate > 0.0001) {
            $allocationId = $this->allocRepo->insertAllocation($this->pdo, $demandId, $productId, $factoryId, $toAllocate, $userId);
            Audit::write(
                $this->pdo, $requestId, $userId, 'replacement.fg_allocated', 'replacement_demand_fg_allocation',
                (string) $allocationId, 'ok', null, null,
                ['demandId' => $demandId, 'productId' => $productId, 'factoryId' => $factoryId, 'qty' => $toAllocate]
            );
        }

        return [
            'allocatedQty' => $toAllocate,
            'productionNeed' => max(0.0, $approvedQty - $toAllocate),
        ];
    }

    /** Still-active, unconsumed reservation for one demand — the "Dialokasikan dari FG" figure the Admin UI shows. */
    public function remainingForDemand(int $demandId): float
    {
        return $this->allocRepo->sumActiveRemainingForDemand($this->pdo, $demandId);
    }

    /**
     * Real dispatch consumption — called from ReplacementDoService's own
     * private dispatch() method. Re-verifies REAL physical stock under
     * lock (never trusts that physical stock is still there just because
     * an allocation row exists — same discipline as SpecialOrderFgAllocationService
     * ::consumeForDispatch()'s own docblock), FIFO-consumes allocation
     * rows, then posts exactly ONE stock_ledger deduction.
     */
    public function consumeForDispatch(int $demandId, int $productId, int $factoryId, float $qty, int $shipmentLineId, string $eventDate, ?int $userId): void
    {
        if ($qty <= 0.0001) {
            return;
        }

        $locationId = $this->fgRepo->findOrCreateLocationForFactory($this->pdo, $factoryId, '');
        $balanceRow = $this->doRepo->lockBalance($this->pdo, $productId, $locationId);
        $physical = $balanceRow !== null ? (float) $balanceRow['qty_on_hand'] : 0.0;
        if ($qty > $physical + 0.0001) {
            throw new \Amor\Api\ApiException(409, 'INSUFFICIENT_PHYSICAL_FG', "Product {$productId}: shipping {$qty} exceeds physical stock on hand {$physical}");
        }

        $rows = $this->allocRepo->lockActiveForDemand($this->pdo, $demandId);
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
            throw new \Amor\Api\ApiException(409, 'INSUFFICIENT_ALLOCATION', "Demand {$demandId}: shipping {$qty} exceeds its own active FG allocation");
        }

        $this->postConsumptionLedger($productId, $locationId, $qty, $eventDate, $shipmentLineId, $userId);
    }

    private function postConsumptionLedger(int $productId, int $locationId, float $qty, string $eventDate, int $shipmentLineId, ?int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO stock_ledger
                (product_id, location_id, event_type, qty_delta, source_type, source_id, event_date, created_at, created_by, notes)
             VALUES (?, ?, 'shipment_out', ?, 'replacement_demand_fg_allocation', ?, ?, UTC_TIMESTAMP(), ?, NULL)"
        );
        $stmt->execute([$productId, $locationId, -$qty, $shipmentLineId, $eventDate, $userId]);
        $ledgerId = (int) $this->pdo->lastInsertId();

        $upsert = $this->pdo->prepare(
            'INSERT INTO stock_balance (product_id, location_id, qty_on_hand, last_ledger_id, updated_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand), last_ledger_id = VALUES(last_ledger_id), updated_at = UTC_TIMESTAMP()'
        );
        $upsert->execute([$productId, $locationId, -$qty, $ledgerId]);
    }
}
