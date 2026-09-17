<?php

declare(strict_types=1);

namespace Amor\Api\Delivery;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Fg\FgRepository;
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
 */
final class ShipmentService
{
    private DoRepository $repo;
    private FgRepository $fg;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new DoRepository();
        $this->fg = new FgRepository();
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
            if ($errors !== []) {
                $allOk = false;
            }

            $lines[] = [
                'productId' => $productId,
                'productName' => $doItem['product_name'],
                'requestedQty' => $requested,
                'remainingToShip' => $remaining,
                'fgAvailable' => $available,
                'maxShippable' => max(0.0, min($remaining, $available)),
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
            $balanceRow = $this->repo->lockBalance($this->pdo, $productId, $locationId);
            $available = $balanceRow !== null ? (float) $balanceRow['qty_on_hand'] : 0.0;
            if ($requested > $available + 0.0001) {
                throw new ApiException(409, 'INSUFFICIENT_FG_AVAILABLE', "Product {$productId}: requested {$requested} exceeds FG available {$available}");
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

    private function liveAvailable(int $productId, int $factoryId): float
    {
        $factory = $this->repo->findFactory($this->pdo, $factoryId);
        if ($factory === null) {
            return 0.0;
        }
        $locationId = $this->fg->findOrCreateLocationForFactory($this->pdo, $factoryId, $factory['name']);
        $balance = $this->fg->findBalance($this->pdo, $productId, $locationId);
        return $balance !== null ? (float) $balance['qty_on_hand'] : $this->fg->sumLedger($this->pdo, $productId, $locationId);
    }
}
