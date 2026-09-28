<?php

declare(strict_types=1);

namespace Amor\Api\Replacement;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Delivery\DoRepository;
use PDO;

/**
 * Business orchestration for the Reject disposition decision + the
 * replacement_demand lifecycle up to (but not including) its own DO/
 * shipment — see ReplacementDoService for that half. Migration 0016.
 *
 * CORE PRINCIPLE (task's own words): Reject Final and Kirim Ulang are
 * explicit Admin decisions, never inferred. A verified Reject with no
 * disposition yet simply sits in findPendingDispositionItems() — nothing
 * downstream (invoice lineage, stock, production) ever treats a
 * still-'pending' line as resolved either way.
 */
final class ReplacementService
{
    private const PRODUCTION_STATUS = 'need_production';

    private ReplacementRepository $repo;
    private ReplacementFgAllocationService $allocSvc;
    private DoRepository $doRepo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new ReplacementRepository();
        $this->allocSvc = new ReplacementFgAllocationService($pdo);
        $this->doRepo = new DoRepository();
    }

    /** GET /api/replacement/pending-disposition — Admin's "Tindak Lanjut Reject" worklist. */
    public function pendingDisposition(?string $tanggal): array
    {
        return array_map(function ($r) {
            return [
                'receiptItemId' => (int) $r['shipment_receipt_item_id'],
                'shipmentId' => (int) $r['shipment_id'],
                'storeId' => (int) $r['store_id'],
                'storeName' => $r['store_name'],
                'tanggal' => $r['tanggal'],
                'productId' => $r['product_id'] !== null ? (int) $r['product_id'] : null,
                'productName' => $r['product_name'],
                'shippedQty' => (float) $r['shipped_qty'],
                'reportedRejectQty' => (float) $r['reject_qty'],
                'reason' => $r['reason'],
                'verifiedAt' => $r['verified_at'],
            ];
        }, $this->repo->findPendingDispositionItems($this->pdo, $tanggal));
    }

    /**
     * POST /api/replacement/receipt-items/{id}/disposition — the one and
     * only place a verified Reject's fate is decided. $disposition is
     * 'reject_final' or 'kirim_ulang'; $approvedQty is the Admin's OWN
     * approved quantity (task's own worked example: store reports 3,
     * Admin verifies only 2 -> Replacement Demand = 2), independently of
     * whatever the store originally reported.
     */
    public function disposeReject(int $receiptItemId, string $disposition, float $approvedQty, ?string $reason, int $userId, ?string $requestId): array
    {
        if (!in_array($disposition, ['reject_final', 'kirim_ulang'], true)) {
            throw new ApiException(400, 'INVALID_DISPOSITION', 'disposition must be reject_final or kirim_ulang');
        }

        $item = $this->repo->lockReceiptItemWithContext($this->pdo, $receiptItemId);
        if ($item === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Receipt item not found');
        }
        if ($item['receipt_status'] !== 'verified') {
            throw new ApiException(400, 'RECEIPT_NOT_VERIFIED', 'Admin must verify this receipt before deciding a disposition');
        }
        if ((float) $item['reject_qty'] <= 0.0001) {
            throw new ApiException(400, 'NO_REJECT_TO_DISPOSE', 'This line has no reported reject quantity');
        }
        if ($item['disposition'] !== 'pending') {
            throw new ApiException(409, 'ALREADY_DISPOSED', 'This reject already has a disposition and cannot be decided twice');
        }
        if ($approvedQty < 0 || $approvedQty > (float) $item['reject_qty'] + 0.0001) {
            throw new ApiException(400, 'INVALID_APPROVED_QTY', "approvedQty must be between 0 and the reported reject quantity ({$item['reject_qty']})");
        }
        if ($disposition === 'kirim_ulang' && $item['product_id'] === null) {
            throw new ApiException(400, 'REPLACEMENT_REQUIRES_PRODUCT', 'A custom/catalog line with no product cannot be replaced through this flow');
        }

        // Guarded by disposition = 'pending' at the SQL level too (see
        // applyDisposition()'s own docblock) — a concurrent second
        // request for the SAME line can never both win.
        $applied = $this->repo->applyDisposition($this->pdo, $receiptItemId, $disposition, $approvedQty, $reason, $userId);
        if (!$applied) {
            throw new ApiException(409, 'ALREADY_DISPOSED', 'This reject was just disposed by another request');
        }

        if ($disposition === 'reject_final') {
            Audit::write(
                $this->pdo, $requestId, $userId, 'reject.finalized', 'shipment_receipt_item', (string) $receiptItemId,
                'ok', null, null, ['approvedQty' => $approvedQty, 'reason' => $reason]
            );
            return ['receiptItemId' => $receiptItemId, 'disposition' => 'reject_final', 'replacementDemandId' => null];
        }

        Audit::write(
            $this->pdo, $requestId, $userId, 'reject.finalized', 'shipment_receipt_item', (string) $receiptItemId,
            'ok', null, null, ['approvedQty' => $approvedQty, 'disposition' => 'kirim_ulang', 'reason' => $reason]
        );

        $demandId = $this->createDemand($receiptItemId, $item, $approvedQty, $userId, $requestId);

        return ['receiptItemId' => $receiptItemId, 'disposition' => 'kirim_ulang', 'replacementDemandId' => $demandId];
    }

    /**
     * Root/parent resolution (task's own "preserve root original reject
     * traceability" + "avoid infinite replacement chains" — never
     * auto-created, only ever reached via another explicit Admin
     * disposeReject() call on the REPLACEMENT's own receipt) + FG
     * allocation, all in the SAME transaction as the disposition write
     * above (Idempotency::handle() already wraps the whole request in one
     * Database::transaction() — see Controllers\ReplacementController).
     */
    private function createDemand(int $receiptItemId, array $item, float $approvedQty, int $userId, ?string $requestId): int
    {
        $parentDemand = $this->repo->findDemandForReplacementReceiptLine($this->pdo, $receiptItemId);
        $parentDemandId = $parentDemand !== null ? (int) $parentDemand['replacement_demand_id'] : null;
        $rootReceiptItemId = $parentDemand !== null ? (int) $parentDemand['root_shipment_receipt_item_id'] : $receiptItemId;

        $productId = (int) $item['product_id'];
        $product = $this->doRepo->findProduct($this->pdo, $productId);
        if ($product === null || $product['factory_id'] === null) {
            throw new ApiException(422, 'PRODUCT_FACTORY_UNRESOLVED', "Product {$productId} has no resolvable factory routing (division/factory_id missing) — cannot create a Replacement Demand");
        }
        $factoryId = (int) $product['factory_id'];
        $storeId = (int) $item['store_id'];

        $demandId = $this->repo->insertDemand(
            $this->pdo, $receiptItemId, $rootReceiptItemId, $parentDemandId, $storeId, $productId, $factoryId, $approvedQty, $userId
        );

        Audit::write(
            $this->pdo, $requestId, $userId, 'replacement.created', 'replacement_demand', (string) $demandId,
            'ok', null, null, ['receiptItemId' => $receiptItemId, 'storeId' => $storeId, 'productId' => $productId, 'approvedQty' => $approvedQty, 'parentDemandId' => $parentDemandId]
        );

        $allocation = $this->allocSvc->allocateAtCreation($demandId, $productId, $factoryId, $approvedQty, $userId, $requestId);
        $status = $this->repo->refreshReadiness($this->pdo, $demandId, $allocation['allocatedQty']);

        Audit::write(
            $this->pdo, $requestId, $userId, 'replacement.production_needed', 'replacement_demand', (string) $demandId,
            'ok', null, null, ['allocatedQty' => $allocation['allocatedQty'], 'productionNeed' => $allocation['productionNeed'], 'status' => $status]
        );

        return $demandId;
    }

    public function getDemand(int $demandId): array
    {
        $demand = $this->repo->findDemandById($this->pdo, $demandId);
        if ($demand === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Replacement demand not found');
        }
        return $this->buildDemandDto($demand);
    }

    /** @return array<int,array> Admin's own Replacement traceability list. */
    public function listDemands(array $filters): array
    {
        return array_map(fn ($r) => $this->buildDemandDto($r), $this->repo->findDemands($this->pdo, $filters));
    }

    /**
     * POST /api/replacement-demands/{id}/production-actual — records
     * Actual/Reject Produksi for this ONE demand's own production run,
     * mirroring SpecialOrderService::updateItemsActual()'s snapshot
     * semantics exactly (each call REPLACES the stored value).
     */
    public function updateProductionActual(int $demandId, float $aktual, float $reject, int $userId, ?string $requestId): array
    {
        if ($aktual < 0 || $reject < 0) {
            throw new ApiException(400, 'INVALID_QTY', 'aktual/reject cannot be negative');
        }
        $demand = $this->repo->lockDemandById($this->pdo, $demandId);
        if ($demand === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Replacement demand not found');
        }
        $this->requireProductionScope($demand);
        if ($demand['status'] !== self::PRODUCTION_STATUS) {
            throw new ApiException(400, 'INVALID_STATUS', "Actual/Reject Produksi can only be entered while the demand still needs production (current status: {$demand['status']})");
        }
        $this->repo->updateProductionActual($this->pdo, $demandId, $aktual, $reject);
        Audit::write($this->pdo, $requestId, $userId, 'replacement.production_actual_updated', 'replacement_demand', (string) $demandId, 'ok', null, null, ['aktual' => $aktual, 'reject' => $reject]);
        return $this->getDemand($demandId);
    }

    /**
     * POST /api/replacement-demands/{id}/verify-fg — confirms how much of
     * this demand's own production_aktual is GOOD and ready to ship,
     * mirroring SpecialOrderService::verifyItemFg()'s own floor rule: may
     * never drop below what THIS demand's production side has already
     * shipped (never the FG-allocation side's own shipped qty — see
     * ReplacementDoService's own shippedSplitForDemand()).
     */
    public function verifyFg(int $demandId, float $fgVerifiedQty, int $userId, ?string $requestId): array
    {
        $demand = $this->repo->lockDemandById($this->pdo, $demandId);
        if ($demand === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Replacement demand not found');
        }
        $this->requireFgScope($demand);
        $aktual = (float) $demand['production_aktual'];
        if ($fgVerifiedQty < 0 || $fgVerifiedQty > $aktual + 0.0001) {
            throw new ApiException(400, 'INVALID_FG_QTY', 'fgVerifiedQty must be between 0 and production_aktual');
        }
        $shippedFromProduction = (new ReplacementDoService($this->pdo))->shippedSplitForDemand($demandId)['shippedFromProduction'];
        if ($fgVerifiedQty < $shippedFromProduction - 0.0001) {
            throw new ApiException(400, 'FG_BELOW_SHIPPED', "fgVerifiedQty cannot drop below what has already shipped from this demand's own production ({$shippedFromProduction})");
        }
        $this->repo->updateFgVerifiedQty($this->pdo, $demandId, $fgVerifiedQty);
        $allocated = $this->allocSvc->remainingForDemand($demandId);
        $status = $this->repo->refreshReadiness($this->pdo, $demandId, $allocated);
        Audit::write($this->pdo, $requestId, $userId, 'replacement.fg_verified', 'replacement_demand', (string) $demandId, 'ok', null, null, ['fgVerifiedQty' => $fgVerifiedQty, 'status' => $status]);
        return $this->getDemand($demandId);
    }

    /**
     * Called by Dispatch\ReceiptService right after a store confirms
     * receipt of a Replacement shipment (same transaction). Never
     * recursively creates another replacement — a reject reported here
     * simply leaves its own shipment_receipt_item at disposition='pending'
     * until Admin explicitly calls disposeReject() on IT (task's own
     * "avoid infinite replacement chains" — see that method's own root/
     * parent resolution for how a SECOND-generation replacement is linked
     * back to this one when that later happens).
     */
    public function recordReceiptOutcome(int $shipmentId, ?int $userId, ?string $requestId): void
    {
        $demandId = $this->repo->findDemandIdForShipment($this->pdo, $shipmentId);
        if ($demandId === null) {
            return;
        }
        $demand = $this->repo->lockDemandById($this->pdo, $demandId);
        if ($demand === null || in_array($demand['status'], ['completed'], true)) {
            return;
        }
        $totalGood = $this->repo->sumReceivedGoodForDemand($this->pdo, $demandId);
        $approvedQty = (float) $demand['approved_qty'];
        $newStatus = $totalGood + 0.0001 >= $approvedQty ? 'completed' : 'received_partial';
        $this->repo->setStatus($this->pdo, $demandId, $newStatus);

        Audit::write($this->pdo, $requestId, $userId, 'replacement.received', 'replacement_demand', (string) $demandId, 'ok', null, null, ['shipmentId' => $shipmentId, 'totalReceivedGood' => $totalGood]);
        if ($newStatus === 'completed') {
            Audit::write($this->pdo, $requestId, $userId, 'replacement.completed', 'replacement_demand', (string) $demandId, 'ok', null, null, ['approvedQty' => $approvedQty, 'totalReceivedGood' => $totalGood]);
        }
    }

    /**
     * FINAL PRE-DEPLOY PATCH — scoped access. Mirrors Production\
     * ProductionService::requireProductionScopedDivision() exactly: a
     * PRODUCTION user must be explicitly assigned (user_division_access)
     * to the division that actually produces this demand's product —
     * resolved from the row itself (product.division_id), never from a
     * client-supplied value. ADMIN/PPIC bypass unconditionally inside
     * Auth::requireDivisionAccess() itself, same as every other division-
     * scoped endpoint in this app. Only division is checked here (never
     * factory) — this is deliberately the SAME scope Production's own
     * real endpoints check, never a stricter parallel rule invented for
     * Replacement alone.
     */
    private function requireProductionScope(array $demand): void
    {
        $divisionId = $this->repo->findProductDivisionId($this->pdo, (int) $demand['product_id']);
        if ($divisionId === null) {
            throw new ApiException(422, 'PRODUCT_DIVISION_MISSING', 'This product has no production division set in Master Data');
        }
        Auth::requireDivisionAccess($divisionId);
    }

    /**
     * Mirrors Fg\FgService::requireFactory() exactly: an FG_PACKING user
     * must be explicitly assigned (user_factory_access) to the factory
     * this demand's own factory_id column already carries (resolved from
     * the just-locked row, never from the request). ADMIN/PPIC bypass
     * unconditionally inside Auth::requireFactoryAccess() itself.
     */
    private function requireFgScope(array $demand): void
    {
        Auth::requireFactoryAccess((int) $demand['factory_id']);
    }

    private function buildDemandDto(array $r): array
    {
        $demandId = (int) $r['replacement_demand_id'];
        $allocated = $this->allocSvc->remainingForDemand($demandId);
        $fgVerified = (float) $r['production_fg_verified_qty'];
        $approvedQty = (float) $r['approved_qty'];
        $productionNeed = max(0.0, $approvedQty - $allocated - $fgVerified);
        return [
            'demandId' => $demandId,
            'receiptItemId' => (int) $r['shipment_receipt_item_id'],
            'rootReceiptItemId' => (int) $r['root_shipment_receipt_item_id'],
            'parentDemandId' => $r['parent_replacement_demand_id'] !== null ? (int) $r['parent_replacement_demand_id'] : null,
            'storeId' => (int) $r['store_id'],
            'storeName' => $r['store_name'] ?? null,
            'productId' => (int) $r['product_id'],
            'productName' => $r['product_name'] ?? null,
            'factoryId' => (int) $r['factory_id'],
            'factoryName' => $r['factory_name'] ?? null,
            'approvedQty' => $approvedQty,
            'allocatedFromFg' => $allocated,
            'productionAktual' => (float) $r['production_aktual'],
            'productionReject' => (float) $r['production_reject'],
            'productionFgVerifiedQty' => $fgVerified,
            'productionNeed' => $productionNeed,
            'status' => $r['status'],
            'version' => (int) $r['version'],
            'replacementDoId' => isset($r['replacement_do_id']) && $r['replacement_do_id'] !== null ? (int) $r['replacement_do_id'] : null,
            'replacementDoDocNo' => $r['replacement_do_doc_no'] ?? null,
            'createdAt' => $r['created_at'],
        ];
    }
}
