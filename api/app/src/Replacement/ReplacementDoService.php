<?php

declare(strict_types=1);

namespace Amor\Api\Replacement;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use PDO;

/**
 * Business orchestration for replacement_do / replacement_do_shipment_item
 * — the SEPARATE DO + real shipment write path for an already-READY
 * Replacement Demand (migration 0016). Mirrors SpecialOrder\
 * SpecialOrderDoService's own CRITICAL DISPATCH RULE exactly: DO creation
 * never reduces FG; FG/physical stock is reduced ONLY at real dispatch
 * (ship()), and only for the portion actually drawn from the demand's own
 * FG allocation — the production-verified portion was never posted to the
 * shared stock_balance pool in the first place (same reasoning as Special
 * Order's own fg_verified_qty; see ReplacementFgAllocationService's own
 * docblock).
 */
final class ReplacementDoService
{
    private ReplacementRepository $demandRepo;
    private ReplacementDoRepository $doRepo;
    private ReplacementFgAllocationService $allocSvc;
    private ReplacementFgAllocationRepository $allocRepo;

    public function __construct(private PDO $pdo)
    {
        $this->demandRepo = new ReplacementRepository();
        $this->doRepo = new ReplacementDoRepository();
        $this->allocSvc = new ReplacementFgAllocationService($pdo);
        $this->allocRepo = new ReplacementFgAllocationRepository();
    }

    /**
     * POST /api/replacement-demands/{id}/do — creates the Replacement DO.
     * Only once the demand is genuinely READY (task's own "Check FG
     * First" — never a DO for a demand still short by any real amount).
     * uq_replacement_do_demand makes a second concurrent create for the
     * same demand resolve to the SAME row rather than a duplicate (see
     * ReplacementDoRepository::createDo()'s own docblock).
     */
    public function create(int $demandId, int $userId, ?string $requestId): array
    {
        $demand = $this->demandRepo->lockDemandById($this->pdo, $demandId);
        if ($demand === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Replacement demand not found');
        }
        if ($demand['status'] === 'need_production') {
            throw new ApiException(400, 'STILL_NEEDS_PRODUCTION', 'This demand is not yet fully covered by FG allocation + verified production');
        }
        if ($demand['status'] !== 'ready') {
            $existing = $this->doRepo->findDoByDemandId($this->pdo, $demandId);
            if ($existing !== null) {
                return $this->buildDoDto((int) $existing['replacement_do_id']);
            }
            throw new ApiException(400, 'INVALID_STATUS', "Cannot create a Replacement DO from status {$demand['status']}");
        }

        $tanggal = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $docNo = $this->doRepo->allocateDoNumber($this->pdo, $tanggal);
        $do = $this->doRepo->createDo(
            $this->pdo, $docNo, $tanggal, $demandId, (int) $demand['store_id'], (int) $demand['factory_id'],
            (int) $demand['product_id'], (float) $demand['approved_qty'], $userId
        );
        $doId = (int) $do['replacement_do_id'];

        $this->demandRepo->setStatus($this->pdo, $demandId, 'do_created');

        Audit::write($this->pdo, $requestId, $userId, 'replacement.do_created', 'replacement_do', (string) $doId, 'ok', null, null, ['demandId' => $demandId, 'docNo' => $docNo, 'plannedQty' => $demand['approved_qty']]);

        return $this->buildDoDto($doId);
    }

    public function getDo(int $doId): array
    {
        return $this->buildDoDto($doId);
    }

    /**
     * POST /api/replacement-do/{id}/ship — the ONE place that creates a
     * real `shipment` row for a Replacement dispatch. $qty null means
     * "ship the full remaining" (the simple one-tap default); a positive
     * $qty less than the full remaining supports partial shipment (task's
     * own worked example: approved 5, allocation 2 + production 3 ->
     * Shipment 1 = 2, Shipment 2 = 3).
     */
    public function ship(int $doId, ?float $qty, int $userId, ?string $requestId): array
    {
        $do = $this->doRepo->lockDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Replacement DO not found');
        }
        if (!in_array($do['status'], ['open', 'partial'], true)) {
            throw new ApiException(400, 'INVALID_STATUS', 'This Replacement DO has already fully shipped or was cancelled');
        }

        $demandId = (int) $do['replacement_demand_id'];
        $demand = $this->demandRepo->lockDemandById($this->pdo, $demandId);
        $planned = (float) $do['planned_qty'];
        $alreadyShipped = $this->doRepo->sumShippedForDo($this->pdo, $doId);
        $remaining = max(0.0, $planned - $alreadyShipped);
        $requested = $qty ?? $remaining;
        if ($requested <= 0.0001) {
            throw new ApiException(400, 'EMPTY_SHIPMENT', 'No positive quantity left to ship on this Replacement DO');
        }
        if ($requested > $remaining + 0.0001) {
            throw new ApiException(400, 'EXCEEDS_PLANNED', "Requested {$requested} exceeds remaining planned qty {$remaining}");
        }

        $split = $this->shippedSplitForDemand($demandId);
        $allocationHeadroom = $this->allocRepo->sumActiveRemainingForDemand($this->pdo, $demandId);
        $productionHeadroom = max(0.0, (float) $demand['production_fg_verified_qty'] - $split['shippedFromProduction']);
        $maxShippable = min($remaining, $allocationHeadroom + $productionHeadroom);
        if ($requested > $maxShippable + 0.0001) {
            throw new ApiException(409, 'EXCEEDS_AVAILABLE', "Requested {$requested} exceeds what is actually ready to ship now ({$maxShippable} — FG allocation {$allocationHeadroom}, verified production {$productionHeadroom})");
        }

        $shipmentId = $this->doRepo->createShipment($this->pdo, $doId, (int) $do['factory_id'], (int) $do['store_id'], (string) $do['tanggal'], (string) $do['doc_no'], $userId);
        $lineId = $this->doRepo->insertShipmentLine($this->pdo, $shipmentId, $doId, $requested);

        $fromAllocation = min($requested, $allocationHeadroom);
        if ($fromAllocation > 0.0001) {
            $this->allocSvc->consumeForDispatch($demandId, (int) $do['product_id'], (int) $do['factory_id'], $fromAllocation, $lineId, (string) $do['tanggal'], $userId);
        }
        $fromProduction = $requested - $fromAllocation;

        $newDoStatus = $this->doRepo->refreshStatus($this->pdo, $doId);
        $newDemandStatus = $newDoStatus === 'shipped' ? 'shipped' : 'partially_shipped';
        $this->demandRepo->setStatus($this->pdo, $demandId, $newDemandStatus);

        Audit::write(
            $this->pdo, $requestId, $userId, 'replacement.shipped', 'replacement_do', (string) $doId, 'ok', null, null,
            ['demandId' => $demandId, 'shipmentId' => $shipmentId, 'qty' => $requested, 'fromAllocation' => $fromAllocation, 'fromProduction' => $fromProduction, 'doStatus' => $newDoStatus]
        );

        $dto = $this->buildDoDto($doId);
        $dto['shipmentId'] = $shipmentId;
        return $dto;
    }

    /** @return array{shippedFromAllocation:float,shippedFromProduction:float} */
    public function shippedSplitForDemand(int $demandId): array
    {
        $do = $this->doRepo->findDoByDemandId($this->pdo, $demandId);
        if ($do === null) {
            return ['shippedFromAllocation' => 0.0, 'shippedFromProduction' => 0.0];
        }
        $totalShipped = $this->doRepo->sumShippedForDo($this->pdo, (int) $do['replacement_do_id']);
        $shippedFromAllocation = $this->allocRepo->sumConsumedForDemand($this->pdo, $demandId);
        return [
            'shippedFromAllocation' => $shippedFromAllocation,
            'shippedFromProduction' => max(0.0, $totalShipped - $shippedFromAllocation),
        ];
    }

    private function buildDoDto(int $doId): array
    {
        $do = $this->doRepo->findDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Replacement DO not found');
        }
        $shipped = $this->doRepo->sumShippedForDo($this->pdo, $doId);
        return [
            'doId' => (int) $do['replacement_do_id'],
            'demandId' => (int) $do['replacement_demand_id'],
            'docNo' => $do['doc_no'],
            'tanggal' => $do['tanggal'],
            'storeId' => (int) $do['store_id'],
            'storeName' => $do['store_name'],
            'factoryId' => (int) $do['factory_id'],
            'factoryName' => $do['factory_name'],
            'productId' => (int) $do['product_id'],
            'productName' => $do['product_name'],
            'plannedQty' => (float) $do['planned_qty'],
            'shippedQty' => $shipped,
            'remainingQty' => max(0.0, (float) $do['planned_qty'] - $shipped),
            'status' => $do['status'],
            'version' => (int) $do['version'],
            'createdByName' => $do['created_by_name'],
            'createdAt' => $do['created_at'],
            'cancelledAt' => $do['cancelled_at'],
            'cancelReason' => $do['cancel_reason'],
        ];
    }
}
