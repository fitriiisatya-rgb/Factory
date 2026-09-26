<?php

declare(strict_types=1);

namespace Amor\Api\Delivery;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Fg\FgRepository;
use Amor\Api\SpecialOrder\SpecialOrderFgAllocationRepository;
use PDO;

/**
 * Shipment preview + the one and only real stock-writing action in Phase
 * 5. No persisted "draft shipment" row exists anywhere in this class (task
 * section 14, and docs/mysql-do-shipment-phase5-reservation-v1.md §3,
 * both already locked this design in before this phase began) — preview()
 * is a pure read/compute with zero writes; ship() is the single
 * transaction that creates the shipment + shipment_item rows AND their
 * stock_ledger 'shipment_out' rows together, never separately.
 *
 * A single shipment is one physical dispatch from ONE factory's warehouse
 * — every line in one ship() call must belong to the same factory
 * (derived per product via product -> division -> factory_id); a DO whose
 * demand spans two factories therefore needs at least two shipments (one
 * per factory), which is exactly what "one DO, many shipments" already
 * supports.
 *
 * Both constraints (actual_qty <= remaining_to_ship, actual_qty <= FG
 * available) are revalidated INSIDE ship()'s own transaction against
 * freshly re-read, row-locked values — never trusting whatever preview()
 * computed moments earlier (task section 16/20).
 *
 * GLOBAL FG RESERVATION ("Existing FG Allocation Bridge" cross-flow fix):
 * "FG available" here is NEVER raw stock_balance.qty_on_hand alone — it is
 * physical qty_on_hand MINUS every active/partially_consumed
 * special_order_fg_allocation reservation for the same product+factory
 * (SpecialOrderFgAllocationRepository::sumActiveAllocatedForProductFactory,
 * the SAME authoritative sum the special-order allocation side itself
 * uses — never a second, divergent computation). Regular PO can never ship
 * stock a special/non-regular order has already reserved.
 *
 * STORE-SPECIFIC FG OWNERSHIP ("FINAL CORE BLOCKER" fix, migration 0014
 * extension): even after the two checks above pass, a Regular shipment is
 * ALSO capped by how much of that PRODUCT'S packed FG belongs to THIS
 * shipment's destination store specifically — physical + special-reserved
 * being fine is necessary but not sufficient; Store A must never ship
 * Product X that was packed for Store B just because the factory-wide
 * total happens to cover it. "Store ready" = SUM(fg_item.packed_qty for
 * this store+product, submitted batches only) MINUS SUM(shipment_item.qty
 * already shipped for this store+product via an ACTIVE Regular shipment)
 * — both always live-summed from their own canonical tables
 * (DoRepository::sumPackedForStore/sumShippedForStore), never a separate
 * cached total. This guard applies ONLY to source_type='delivery_order'
 * (Regular store) shipments — Special/CS/Sales/Direct/General orders
 * never reach this class at all (they ship through
 * SpecialOrder\SpecialOrderDoService instead), so nothing here can affect
 * them.
 *
 * CANONICAL LOCK ORDER (documented once, honored by every writer that
 * touches General FG for a given product+location — this class's own
 * ship(), SpecialOrder\SpecialOrderFgAllocationService::allocate(),
 * ::consumeForDispatch(), and Fg\FgService::submit()'s own store-ready
 * check): lock the stock_balance row (SELECT ... FOR UPDATE) FIRST, for
 * the ENTIRE remainder of the read-decide-write sequence, THEN lock the
 * store_fg_balance row (product+store+location) SECOND, THEN read/write
 * special_order_fg_allocation rows, THEN write the shipment/ledger rows.
 * Holding the stock_balance lock across the whole sequence is what makes
 * a concurrent Regular ship() and a concurrent special allocate()/
 * consumeForDispatch() for the SAME product+location serialize correctly
 * instead of both reading a stale "free" number — the second writer
 * always sees the first's already-committed reservation or already-
 * committed physical deduction. Holding store_fg_balance across the same
 * sequence is what makes two concurrent Regular shipments for the SAME
 * store+product serialize instead of both reading a stale "ready"
 * number, and what makes a concurrent FgService::submit() downward
 * correction for that same store+product wait for (or be waited on by)
 * an in-flight shipment rather than racing it. No writer may read the
 * active-allocation SUM, the physical balance, or the store-ready SUM
 * without first holding the lock(s) that precede it in this order.
 */
final class ShipmentService
{
    private DoRepository $repo;
    private FgRepository $fg;
    private SpecialOrderFgAllocationRepository $allocRepo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new DoRepository();
        $this->fg = new FgRepository();
        $this->allocRepo = new SpecialOrderFgAllocationRepository();
    }

    /**
     * POST /api/do/{id}/shipment-preview — read-only. Shows, per
     * requested line, whether it would be accepted right now and why not
     * if not — never writes anything, never reserves stock.
     */
    public function preview(int $doId, array $items): array
    {
        $do = $this->repo->findDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Delivery Order not found');
        }
        $doItems = $this->repo->findDoItems($this->pdo, $doId);
        $shippedByProduct = $this->repo->shippedQtyByProduct($this->pdo, $doId);
        $storeId = (int) $do['store_id'];

        $lines = [];
        $allOk = true;
        foreach ($items as $line) {
            $productId = (int) ($line['productId'] ?? 0);
            $requested = (float) ($line['actualQty'] ?? 0);
            if (!isset($doItems[$productId])) {
                throw new ApiException(400, 'UNKNOWN_PRODUCT_FOR_DO', "Product {$productId} is not part of this DO");
            }
            $doItem = $doItems[$productId];
            $factoryId = $doItem['factory_id'] !== null ? (int) $doItem['factory_id'] : null;
            $planned = (float) $doItem['planned_qty'];
            $shipped = $shippedByProduct[$productId] ?? 0.0;
            $remaining = max(0.0, $planned - $shipped);
            $available = $factoryId !== null ? $this->liveAvailable($productId, $factoryId) : 0.0;
            // null = no store allocation established yet for this product
            // (still Per Produk) — the store guard does not apply, see
            // DoRepository::hasAnyStoreAllocation()'s own docblock.
            $storeReady = $factoryId !== null ? $this->liveStoreReady($storeId, $productId, $factoryId) : 0.0;

            $errors = [];
            if ($requested < 0) {
                $errors[] = 'NEGATIVE_QTY';
            }
            if ($requested > $remaining + 0.0001) {
                $errors[] = 'EXCEEDS_REMAINING';
            }
            if ($requested > $available + 0.0001) {
                $errors[] = 'EXCEEDS_AVAILABLE';
            }
            if ($storeReady !== null && $requested > $storeReady + 0.0001) {
                $errors[] = 'EXCEEDS_STORE_READY';
            }
            if ($errors !== []) {
                $allOk = false;
            }

            $lines[] = [
                'productId' => $productId,
                'productName' => $doItem['product_name'],
                'requestedQty' => $requested,
                'remainingToShip' => $remaining,
                'fgAvailable' => $available,
                'storeReady' => $storeReady,
                'maxShippable' => max(0.0, min($remaining, $available, $storeReady ?? PHP_FLOAT_MAX)),
                'errors' => $errors,
            ];
        }

        return ['doId' => $doId, 'lines' => $lines, 'ok' => $allOk];
    }

    /**
     * POST /api/do/{id}/ship — the real commit. Requires an
     * Idempotency-Key at the controller layer (same replay-safe mechanism
     * already proven in Phases 2-4); "same key, same payload" replays the
     * stored result without re-executing, so a retry can never deduct
     * stock twice (task section 19).
     */
    public function ship(int $doId, int $expectedVersion, string $shipmentGroup, array $items, int $userId, ?string $requestId): array
    {
        if (!in_array($shipmentGroup, ['MAIN', 'PASTRY', 'OTHER'], true)) {
            throw new ApiException(400, 'INVALID_SHIPMENT_GROUP', 'shipmentGroup must be MAIN, PASTRY, or OTHER');
        }

        $do = $this->repo->lockDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Delivery Order not found');
        }
        // Checked immediately, before any stock is touched — we hold the
        // FOR UPDATE lock on this row for the rest of the transaction, so
        // nothing can change its version out from under us between here
        // and the bumpVersion() call at the end.
        if ((int) $do['version'] !== $expectedVersion) {
            throw new ApiException(409, 'VERSION_CONFLICT', 'The document was modified by someone else', ['currentVersion' => (int) $do['version']]);
        }
        if (!in_array($do['status'], ['draft', 'preprinted'], true)) {
            throw new ApiException(409, 'INVALID_STATUS', "Only a draft or preprinted document can be shipped against (current status: {$do['status']})");
        }

        $doItems = $this->repo->findDoItems($this->pdo, $doId);
        $shippedByProduct = $this->repo->shippedQtyByProduct($this->pdo, $doId);

        $shipmentId = null;
        $shipmentFactoryId = null;
        $createdItems = [];
        foreach ($items as $line) {
            $productId = (int) ($line['productId'] ?? 0);
            $requested = (float) ($line['actualQty'] ?? 0);
            if ($requested < 0) {
                throw new ApiException(400, 'NEGATIVE_QTY', "Product {$productId}: actualQty cannot be negative");
            }
            if ($requested <= 0.0001) {
                continue; // zero-qty lines are simply not part of this shipment
            }
            if (!isset($doItems[$productId])) {
                throw new ApiException(400, 'UNKNOWN_PRODUCT_FOR_DO', "Product {$productId} is not part of this DO");
            }
            $doItem = $doItems[$productId];
            $factoryId = $doItem['factory_id'] !== null ? (int) $doItem['factory_id'] : null;
            if ($factoryId === null) {
                throw new ApiException(400, 'PRODUCT_FACTORY_UNKNOWN', "Product {$productId} has no division/factory mapping — cannot determine which warehouse to ship from");
            }
            if ($shipmentFactoryId === null) {
                $shipmentFactoryId = $factoryId;
            } elseif ($factoryId !== $shipmentFactoryId) {
                throw new ApiException(400, 'MIXED_FACTORY_SHIPMENT', 'A single shipment cannot mix products from different factories — create a separate shipment per factory');
            }

            // Re-validate against the LIVE, just-re-read remaining — never
            // whatever preview() computed moments earlier (task section 16).
            $planned = (float) $doItem['planned_qty'];
            $alreadyShipped = $shippedByProduct[$productId] ?? 0.0;
            $remaining = max(0.0, $planned - $alreadyShipped);
            if ($requested > $remaining + 0.0001) {
                throw new ApiException(400, 'EXCEEDS_REMAINING', "Product {$productId}: requested {$requested} exceeds remaining {$remaining}");
            }

            $factory = $this->repo->findFactory($this->pdo, $factoryId);
            $locationId = $this->fg->findOrCreateLocationForFactory($this->pdo, $factoryId, $factory['name']);

            // Row-locks stock_balance (or confirms no row = 0 available) —
            // the concurrency guard: two simultaneous shippers serialize
            // here, the second sees the first's already-decremented balance
            // (task section 20 — "no negative stock, no silent overship").
            // Held for the rest of this line's read-decide-write sequence
            // per this class's own canonical-lock-order docblock, so a
            // concurrent special-order allocate()/consumeForDispatch() for
            // the same product+location can never race this check.
            $balanceRow = $this->repo->lockBalance($this->pdo, $productId, $locationId);
            $physical = $balanceRow !== null ? (float) $balanceRow['qty_on_hand'] : 0.0;
            $reservedForSpecial = $this->allocRepo->sumActiveAllocatedForProductFactory($this->pdo, $productId, $factoryId);
            $trueFree = max(0.0, $physical - $reservedForSpecial);
            if ($requested > $trueFree + 0.0001) {
                throw new ApiException(409, 'INSUFFICIENT_FG_AVAILABLE', "Product {$productId}: requested {$requested} exceeds FG available {$trueFree} (physical {$physical}, reserved for special/non-regular orders {$reservedForSpecial})");
            }

            // STORE-SPECIFIC FG OWNERSHIP (FINAL CORE BLOCKER fix): locked
            // SECOND, after stock_balance, per this class's own canonical
            // lock order docblock. Passing the two checks above only proves
            // the FACTORY has enough free/unreserved stock — it says
            // nothing about whether THIS destination store is the one that
            // stock was packed for. A concurrent FgService::submit()
            // downward correction for this same store+product either
            // already committed (we see its result) or is waiting on this
            // same lock (it will see ours) — never a race. Gated on
            // hasAnyStoreAllocation(): a product still entirely in Per
            // Produk mode has no per-store data to restrict against, so
            // this is skipped entirely for it (see DoRepository::
            // hasAnyStoreAllocation()'s own docblock) — physical+special
            // above remain the only guards, exactly as before this fix.
            $unallocatedStoreId = $this->fg->unallocatedStoreId($this->pdo);
            if ($this->repo->hasAnyStoreAllocation($this->pdo, $productId, $factoryId, $unallocatedStoreId)) {
                $this->repo->lockStoreFgBalance($this->pdo, (int) $do['store_id'], $productId, $locationId);
                $storePacked = $this->repo->sumPackedForStore($this->pdo, (int) $do['store_id'], $productId, $factoryId, null);
                $storeShipped = $this->repo->sumShippedForStore($this->pdo, (int) $do['store_id'], $productId, $factoryId);
                $storeReady = max(0.0, $storePacked - $storeShipped);
                if ($requested > $storeReady + 0.0001) {
                    throw new ApiException(409, 'INSUFFICIENT_STORE_READY_FG', "Product {$productId}: requested {$requested} exceeds ready FG owned by this store {$storeReady} (packed for this store {$storePacked}, already shipped to this store {$storeShipped}) — another store's packed FG cannot be substituted");
                }
            }

            if ($shipmentId === null) {
                $shipmentId = $this->repo->createShipment(
                    $this->pdo, $doId, $factoryId, (int) $do['store_id'], (string) $do['tanggal'],
                    $shipmentGroup, (string) ($do['doc_no'] ?? ('DO-' . $doId)), $userId
                );
            }
            $shipmentItemId = $this->repo->insertShipmentItem(
                $this->pdo, $shipmentId, $productId, $requested, (int) $doItem['delivery_order_item_id'],
                isset($line['notes']) ? (string) $line['notes'] : null
            );
            $this->repo->postShipmentOutLedger($this->pdo, $productId, $locationId, $requested, (string) $do['tanggal'], $shipmentItemId, $userId);

            $shippedByProduct[$productId] = $alreadyShipped + $requested;
            $createdItems[] = [
                'productId' => $productId,
                'productName' => $doItem['product_name'],
                'actualQty' => $requested,
                'remainingAfter' => max(0.0, $remaining - $requested),
            ];
        }

        if ($shipmentId === null) {
            throw new ApiException(400, 'EMPTY_SHIPMENT', 'No positive quantity was provided for any product on this DO');
        }

        // Recompute DO fulfillment: shipped only if EVERY item's cumulative
        // shipped now reaches its planned qty (task section 22 — never mark
        // shipped after just the first partial dispatch).
        $fullyFulfilled = true;
        foreach ($doItems as $productId => $doItem) {
            $planned = (float) $doItem['planned_qty'];
            $shippedNow = $shippedByProduct[$productId] ?? 0.0;
            if ($shippedNow + 0.0001 < $planned) {
                $fullyFulfilled = false;
                break;
            }
        }
        $setClause = $fullyFulfilled ? "status = 'shipped', shipped_at = UTC_TIMESTAMP()" : 'status = status';
        $bumped = $this->repo->bumpVersion($this->pdo, $doId, $expectedVersion, $setClause, []);
        if (!$bumped) {
            // Cannot happen under the row lock held since the top of this
            // method (see the early version check above) — defense in depth only.
            throw new ApiException(409, 'VERSION_CONFLICT', 'The document was modified by someone else');
        }

        $totalShipped = array_sum($shippedByProduct);
        $totalPlanned = array_sum(array_map(static fn ($i) => (float) $i['planned_qty'], $doItems));

        Audit::write(
            $this->pdo, $requestId, $userId, 'do.ship', 'delivery_order', (string) $doId,
            'ok', $expectedVersion, $expectedVersion + 1,
            ['shipmentId' => $shipmentId, 'shipmentGroup' => $shipmentGroup, 'items' => $createdItems, 'fullyFulfilled' => $fullyFulfilled]
        );

        return [
            'shipmentId' => $shipmentId,
            'shipmentGroup' => $shipmentGroup,
            'doId' => $doId,
            'storeId' => (int) $do['store_id'],
            'items' => $createdItems,
            'doTotalPlanned' => $totalPlanned,
            'doTotalShipped' => $totalShipped,
            'doTotalRemaining' => max(0.0, $totalPlanned - $totalShipped),
            'doStatus' => $fullyFulfilled ? 'shipped' : $do['status'],
            'doFullyFulfilled' => $fullyFulfilled,
        ];
    }

    /** Read-only, never locks (preview never writes/reserves) — physical FG minus active special-order reservations, the SAME "true free" formula ship() re-validates under lock. */
    private function liveAvailable(int $productId, int $factoryId): float
    {
        $factory = $this->repo->findFactory($this->pdo, $factoryId);
        if ($factory === null) {
            return 0.0;
        }
        $locationId = $this->fg->findOrCreateLocationForFactory($this->pdo, $factoryId, $factory['name']);
        $balance = $this->fg->findBalance($this->pdo, $productId, $locationId);
        $physical = $balance !== null ? (float) $balance['qty_on_hand'] : $this->fg->sumLedger($this->pdo, $productId, $locationId);
        $reservedForSpecial = $this->allocRepo->sumActiveAllocatedForProductFactory($this->pdo, $productId, $factoryId);
        return max(0.0, $physical - $reservedForSpecial);
    }

    /**
     * Read-only, never locks — the SAME packed-minus-shipped formula
     * ship() re-validates under lock (store_fg_balance). Returns null
     * when this product+factory has no store allocation established yet
     * (still Per Produk) — meaning "not restricted by store", never
     * "zero ready" (see DoRepository::hasAnyStoreAllocation()'s own
     * docblock — those are very different things a caller must not
     * conflate).
     */
    private function liveStoreReady(int $storeId, int $productId, int $factoryId): ?float
    {
        $unallocatedStoreId = $this->fg->unallocatedStoreId($this->pdo);
        if (!$this->repo->hasAnyStoreAllocation($this->pdo, $productId, $factoryId, $unallocatedStoreId)) {
            return null;
        }
        $storePacked = $this->repo->sumPackedForStore($this->pdo, $storeId, $productId, $factoryId, null);
        $storeShipped = $this->repo->sumShippedForStore($this->pdo, $storeId, $productId, $factoryId);
        return max(0.0, $storePacked - $storeShipped);
    }
}
