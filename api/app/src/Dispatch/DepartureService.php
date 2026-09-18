<?php

declare(strict_types=1);

namespace Amor\Api\Dispatch;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\Delivery\ShipmentService;
use PDO;

/**
 * Confirm Departure (Phase 5.5, Part D) — the ONE place a driver's claimed
 * tasks turn into a REAL Phase 5 shipment. Deliberately thin: this class
 * owns none of the stock-deduction logic itself — every actual write to
 * shipment/shipment_item/stock_ledger/stock_balance happens inside the
 * EXISTING Delivery\ShipmentService::ship(), called here exactly as any
 * other caller would call it (task's own "reuse existing ShipmentService
 * ... do not duplicate stock deduction logic").
 *
 * Claiming a task never creates stock movement (Part D, first line) — the
 * dispatch_claim rows this method resolves are pure reservation
 * bookkeeping; the stock write happens only inside ship() below, exactly
 * once per (DO, factory) pair in this departure.
 *
 * A single departure MAY span two factories if the driver's claimed items
 * happen to do so (the store's DO can legitimately span two factories per
 * the existing cross-factory-store design) — ship() itself refuses to mix
 * factories in one call (MIXED_FACTORY_SHIPMENT), so this method groups by
 * factory and calls ship() once per group, chaining the DO's own optimistic
 * version forward between calls (each successful ship() call is guaranteed
 * to bump delivery_order.version by exactly 1 — see ShipmentService::ship()
 * — so the second group's expectedVersion is simply the first's + 1,
 * computed locally rather than re-queried, since both calls execute inside
 * the same locked transaction).
 */
final class DepartureService
{
    private DispatchRepository $dispatchRepo;
    private DoRepository $doRepo;

    public function __construct(private PDO $pdo)
    {
        $this->dispatchRepo = new DispatchRepository();
        $this->doRepo = new DoRepository();
    }

    /**
     * @param array<int,array{claimId:int,actualQty:float}> $items driver's
     *        chosen actual-ship quantity per claim (may be less than the
     *        claim's full active_qty — the undelivered remainder is
     *        automatically released back to the pool, task's own
     *        preference, never left dangling in a half-active state)
     */
    public function confirmDeparture(int $driverUserId, int $doId, int $expectedVersion, string $shipmentGroup, array $items, ?string $requestId): array
    {
        if (!in_array($shipmentGroup, ['MAIN', 'PASTRY', 'OTHER'], true)) {
            throw new ApiException(400, 'INVALID_SHIPMENT_GROUP', 'shipmentGroup must be MAIN, PASTRY, or OTHER');
        }
        if ($items === []) {
            throw new ApiException(400, 'EMPTY_DEPARTURE', 'Pilih minimal satu produk untuk konfirmasi keberangkatan');
        }

        // Lock the DO up front — every claim ownership/status check below,
        // and ship()'s own re-lock of the same row further down, all happen
        // inside this one hold (re-locking the same row in the same
        // transaction is a safe no-op).
        $do = $this->doRepo->lockDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Delivery Order not found');
        }

        $claims = [];
        $requestedByClaimId = [];
        foreach ($items as $line) {
            $claimId = (int) ($line['claimId'] ?? 0);
            $actual = (float) ($line['actualQty'] ?? 0);
            $claim = $this->dispatchRepo->lockClaim($this->pdo, $claimId);
            if ($claim === null) {
                throw new ApiException(404, 'NOT_FOUND', "Klaim {$claimId} tidak ditemukan");
            }
            if ((int) $claim['delivery_order_id'] !== $doId) {
                throw new ApiException(400, 'CLAIM_DO_MISMATCH', "Klaim {$claimId} bukan milik DO ini");
            }
            if ((int) $claim['driver_user_id'] !== $driverUserId) {
                throw new ApiException(403, 'FORBIDDEN', 'Klaim ini bukan milik Anda');
            }
            if ($claim['status'] !== 'active') {
                throw new ApiException(409, 'INVALID_CLAIM_STATUS', "Klaim {$claimId} sudah tidak aktif — muat ulang halaman");
            }
            if ($actual < 0) {
                throw new ApiException(400, 'NEGATIVE_QTY', "Klaim {$claimId}: jumlah aktual tidak boleh negatif");
            }
            if ($actual > (float) $claim['active_qty'] + 0.0001) {
                throw new ApiException(409, 'EXCEEDS_CLAIMED', "Klaim {$claimId}: jumlah aktual ({$actual}) melebihi jumlah yang diklaim (" . $claim['active_qty'] . ')');
            }
            $claims[$claimId] = $claim;
            $requestedByClaimId[$claimId] = $actual;
        }

        // Group the positive-qty lines by factory (per product, via the
        // DO's own items — never a second source of truth for factory
        // mapping) so each ship() call stays single-factory.
        $doItems = $this->doRepo->findDoItems($this->pdo, $doId);
        $byFactory = [];
        foreach ($claims as $claimId => $claim) {
            $requested = $requestedByClaimId[$claimId];
            if ($requested <= 0.0001) {
                continue;
            }
            $productId = (int) $claim['product_id'];
            $factoryId = $doItems[$productId]['factory_id'] ?? null;
            $factoryKey = $factoryId !== null ? (int) $factoryId : 0;
            $byFactory[$factoryKey][] = ['productId' => $productId, 'actualQty' => $requested];
        }

        $shipmentService = new ShipmentService($this->pdo);
        $shipments = [];
        $currentVersion = $expectedVersion;
        foreach ($byFactory as $groupItems) {
            $dto = $shipmentService->ship($doId, $currentVersion, $shipmentGroup, $groupItems, $driverUserId, $requestId);
            $shipments[] = $dto;
            $currentVersion++; // ship() always bumps version by exactly 1 on success — see its own docblock
        }

        // Resolve every claim touched by this departure — departed qty (if
        // any was shipped) plus the automatic release of whatever active_qty
        // remained unshipped. This is TERMINAL for the claim (see
        // DispatchRepository::resolveClaimAsDeparted's own docblock) —
        // exactly the task's "automatically release unused claimed qty
        // after successful departure" preference.
        $lastShipmentId = $shipments !== [] ? (int) end($shipments)['shipmentId'] : null;
        foreach ($claims as $claimId => $claim) {
            $requested = $requestedByClaimId[$claimId];
            $leftover = max(0.0, (float) $claim['active_qty'] - $requested);
            $this->dispatchRepo->resolveClaimAsDeparted($this->pdo, $claimId, $requested, $leftover, $lastShipmentId);
            Audit::write(
                $this->pdo, $requestId, $driverUserId, 'dispatch.claim_resolved', 'dispatch_claim', (string) $claimId,
                'ok', null, null, ['departedQty' => $requested, 'releasedQty' => $leftover]
            );
        }

        foreach ($shipments as $dto) {
            Audit::write(
                $this->pdo, $requestId, $driverUserId, 'dispatch.departed', 'shipment', (string) $dto['shipmentId'],
                'ok', null, null, ['doId' => $doId, 'shipmentGroup' => $shipmentGroup]
            );
        }

        return [
            'doId' => $doId,
            'shipmentsCreated' => count($shipments),
            'shipments' => $shipments,
            'claimsResolved' => count($claims),
        ];
    }
}
