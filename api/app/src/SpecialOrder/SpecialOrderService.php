<?php

declare(strict_types=1);

namespace Amor\Api\SpecialOrder;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Production\ProductionRoutingService;
use Amor\Api\Services\DocumentSequenceService;
use Amor\Api\Versioning;
use PDO;

/**
 * Business orchestration for Pesanan Khusus Toko / Pesanan Non-Toko +
 * their per-item Production division routing (migration 0010).
 *
 * Core principle enforced throughout (task's own words): special/non-store
 * orders are NEVER merged into PO Reguler Toko — po_batch/po_item/
 * po_store_item are never read or written here. Every item keeps its own
 * division_id, snapshotted at creation time, so Production's inbox can
 * group/filter per item without ever collapsing a multi-division order
 * into one bucket.
 *
 * FG behavior (task's own explicit permission to defer a full reservation
 * engine): computeFgShortage() is a READ-ONLY, informational calculation
 * against the EXISTING stock_balance table — it never writes to
 * stock_ledger/stock_balance, and it never "reserves" a quantity against
 * future demand (two special orders both wanting the same 7 pcs of FG
 * would both currently show "7 available" until one of them actually
 * consumes it through the existing production/FG flow). Building real
 * reservation would need locking semantics this phase's tables don't
 * have — see the OUTPUT REPORT's "known deferred enhancements".
 */
final class SpecialOrderService
{
    private const VALID_SOURCE_TYPES = ['toko_khusus', 'non_toko'];
    private const VALID_NON_STORE_SOURCES = ['konsumen_langsung', 'cs', 'sales_executive', 'umum'];
    private const VALID_FULFILLMENT_TYPES = ['pengiriman', 'pickup'];
    private const VALID_ITEM_TYPES = ['existing_product', 'special_catalog'];
    private const PRODUCTION_STATUSES = ['sent_to_production', 'in_production', 'ready', 'completed'];

    private SpecialOrderRepository $repo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new SpecialOrderRepository();
    }

    /** GET /api/special-orders/catalog — lazily seeds the catalog on first call. */
    public function listCatalog(): array
    {
        $divisionId = $this->repo->findOrCreateCakeCustomDivision($this->pdo);
        $this->repo->ensureCatalogSeeded($this->pdo, $divisionId);
        return array_map(fn ($r) => [
            'catalogId' => (int) $r['special_order_catalog_id'],
            'code' => $r['code'],
            'name' => $r['name'],
            'divisionId' => (int) $r['division_id'],
            'divisionName' => $r['division_name'],
            'factoryId' => (int) $r['factory_id'],
            'factoryName' => $r['factory_name'],
            'defaultPrice' => $r['default_price'] !== null ? (float) $r['default_price'] : null,
            'defaultCharge' => $r['default_charge'] !== null ? (float) $r['default_charge'] : null,
        ], $this->repo->findActiveCatalog($this->pdo));
    }

    /**
     * GET-adjacent helper for the create form's "existing product" search
     * — same product list the form already fetched inline before this
     * routing rework, now behind the service so every caller (this
     * service, its own tests) shares one query instead of ad-hoc SQL
     * duplicated across pages.
     */
    public function listProductsForOrderEntry(): array
    {
        return array_map(fn ($p) => [
            'productId' => (int) $p['product_id'],
            'name' => $p['name'],
            'harga' => (float) $p['harga'],
            'divisionId' => $p['division_id'] !== null ? (int) $p['division_id'] : null,
            'divisionName' => $p['division_name'],
            'factoryId' => $p['factory_id'] !== null ? (int) $p['factory_id'] : null,
            'factoryName' => $p['factory_name'],
        ], $this->repo->findProductsWithDivision($this->pdo));
    }

    /**
     * POST /api/special-orders — creates the order header AND every item
     * in one transaction (this phase's create flow: the whole order,
     * items included, is built client-side before the single submit —
     * see the OUTPUT REPORT's "known deferred enhancements" for why a
     * separate post-creation item-edit flow was deliberately not built).
     */
    public function createOrder(array $input, int $userId, ?string $requestId): array
    {
        $header = $this->validateHeader($input);
        $itemsInput = $input['items'] ?? null;
        if (!is_array($itemsInput) || $itemsInput === []) {
            throw new ApiException(400, 'ITEMS_REQUIRED', 'At least one order item is required');
        }

        // A special_catalog item can only resolve once the catalog exists —
        // normally already true because the create form always calls
        // listCatalog() first to populate its dropdown, but a direct API
        // caller (or the very first order ever created on a fresh install)
        // must not depend on that. Cheap and idempotent, so unconditional.
        $cakeCustomDivisionId = $this->repo->findOrCreateCakeCustomDivision($this->pdo);
        $this->repo->ensureCatalogSeeded($this->pdo, $cakeCustomDivisionId);

        // Every item is resolved (and its factory routing validated —
        // resolveItem() throws FACTORY_ROUTING_UNRESOLVED via
        // ProductionRoutingService if it can't be) BEFORE the order header
        // is written, so the header's own factory_id — a display-only
        // convenience snapshot, never user-supplied (task: "Do not require
        // a manual factory selector" / "User cannot manually override
        // factory inconsistently") — can be set correctly in one insert:
        // the single resolved factory when every item routes to the same
        // one, or NULL for a genuine multi-factory order (per-item factory
        // stays the source of truth either way — see findItemsForOrder()).
        $resolvedItems = [];
        $divisionIds = [];
        $factoryIds = [];
        foreach ($itemsInput as $i => $rawItem) {
            if (!is_array($rawItem)) {
                throw new ApiException(400, 'INVALID_ITEM', "Item #{$i} is not a valid object");
            }
            $item = $this->resolveItem($rawItem, $i);
            $resolvedItems[] = $item;
            $divisionIds[$item['divisionId']] = true;
            $factoryIds[$item['factoryId']] = true;
        }
        $header['factoryId'] = count($factoryIds) === 1 ? array_key_first($factoryIds) : null;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $orderNo = sprintf('NPR-%s-%03d', $now->format('Ymd'), DocumentSequenceService::allocate($this->pdo, 'NPR', (int) $now->format('Y'), (int) $now->format('n')));

        $orderId = $this->repo->insertOrder($this->pdo, $orderNo, $header, $userId);

        foreach ($resolvedItems as $item) {
            $this->repo->insertItem($this->pdo, $orderId, $item);
        }

        Audit::write(
            $this->pdo,
            $requestId,
            $userId,
            'special_order.created',
            'special_order',
            (string) $orderId,
            'ok',
            null,
            1,
            [
                'orderNo' => $orderNo,
                'sourceType' => $header['sourceType'],
                'itemCount' => count($itemsInput),
                'divisionCount' => count($divisionIds),
                'factoryCount' => count($factoryIds),
                'factoryIds' => array_keys($factoryIds),
            ]
        );

        return $this->getOrder($orderId);
    }

    public function getOrder(int $orderId): array
    {
        $order = $this->repo->findOrderById($this->pdo, $orderId);
        if ($order === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Special order not found');
        }
        $items = $this->repo->findItemsForOrder($this->pdo, $orderId);
        return $this->buildOrderDto($order, $items);
    }

    /** @return array<int,array> */
    public function listOrders(array $filters): array
    {
        $rows = $this->repo->findOrders($this->pdo, $filters);
        return array_map(fn ($r) => $this->buildOrderSummaryDto($r), $rows);
    }

    public function confirmOrder(int $orderId, int $expectedVersion, int $userId, ?string $requestId): array
    {
        $order = $this->repo->lockOrderById($this->pdo, $orderId);
        if ($order === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Special order not found');
        }
        if ($order['status'] !== 'draft') {
            throw new ApiException(400, 'INVALID_STATUS', 'Only a DRAFT order can be confirmed');
        }
        Versioning::update($this->pdo, 'special_order', 'special_order_id', $orderId, $expectedVersion, "status = 'confirmed'", []);
        Audit::write($this->pdo, $requestId, $userId, 'special_order.confirmed', 'special_order', (string) $orderId, 'ok', $expectedVersion, $expectedVersion + 1);
        return $this->getOrder($orderId);
    }

    /**
     * POST /api/special-orders/{id}/send-to-production — routes every
     * item into Production's demand inbox by flipping the ORDER's status
     * (items already carry their own division_id from creation, so no
     * per-item write happens here — this is what makes a repeated/
     * idempotent send safe: Idempotency::handle's replay covers an exact
     * retry, and optimistic version-locking covers a stale double-submit,
     * so the same items are never inserted twice — see ORDER-17).
     */
    public function sendToProduction(int $orderId, int $expectedVersion, int $userId, ?string $requestId): array
    {
        $order = $this->repo->lockOrderById($this->pdo, $orderId);
        if ($order === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Special order not found');
        }
        if ($order['status'] !== 'confirmed') {
            throw new ApiException(400, 'INVALID_STATUS', 'Only a CONFIRMED order can be sent to Production');
        }
        Versioning::update(
            $this->pdo, 'special_order', 'special_order_id', $orderId, $expectedVersion,
            "status = 'sent_to_production', sent_to_production_at = UTC_TIMESTAMP()", []
        );
        Audit::write($this->pdo, $requestId, $userId, 'special_order.sent_to_production', 'special_order', (string) $orderId, 'ok', $expectedVersion, $expectedVersion + 1);
        return $this->getOrder($orderId);
    }

    /** PATCH-style forward-only status update once in Production (task's own "Do NOT over-engineer state transitions"). */
    public function updateStatus(int $orderId, int $expectedVersion, string $newStatus, int $userId, ?string $requestId): array
    {
        if (!in_array($newStatus, ['in_production', 'ready', 'completed'], true)) {
            throw new ApiException(400, 'INVALID_STATUS', 'newStatus must be one of in_production, ready, completed');
        }
        $order = $this->repo->lockOrderById($this->pdo, $orderId);
        if ($order === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Special order not found');
        }
        if (!in_array($order['status'], self::PRODUCTION_STATUSES, true) || $order['status'] === 'completed') {
            throw new ApiException(400, 'INVALID_STATUS', 'Order must already be sent to Production (and not yet completed) to change its Production status');
        }
        Versioning::update($this->pdo, 'special_order', 'special_order_id', $orderId, $expectedVersion, 'status = ?', [$newStatus]);
        Audit::write($this->pdo, $requestId, $userId, 'special_order.status_changed', 'special_order', (string) $orderId, 'ok', $expectedVersion, $expectedVersion + 1, ['newStatus' => $newStatus]);
        return $this->getOrder($orderId);
    }

    /**
     * POST /api/special-orders/{id}/actual — records Actual Produksi
     * (good output) + Reject Produksi per item, for Task per Divisi.
     * Only makes sense once the order has actually reached Production
     * (task's own item field list separates Aktual/Reject from the
     * order's own draft/confirm/send lifecycle). Snapshot semantics —
     * every write REPLACES the item's stored value, matching
     * ProductionRepository::updateItemActual()'s own convention exactly,
     * so the two production-actual write paths in this app never disagree
     * on what "saving actual" means.
     *
     * @param array<int,array{itemId:int,aktualProduksi:float,rejectProduksi:float}> $items
     */
    public function updateItemsActual(int $orderId, int $expectedVersion, array $items, int $userId, ?string $requestId): array
    {
        $order = $this->repo->lockOrderById($this->pdo, $orderId);
        if ($order === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Special order not found');
        }
        if (!in_array($order['status'], self::PRODUCTION_STATUSES, true)) {
            throw new ApiException(400, 'INVALID_STATUS', 'Actual/Reject Produksi hanya bisa diisi setelah pesanan dikirim ke Produksi');
        }
        if ($items === []) {
            throw new ApiException(400, 'ITEMS_REQUIRED', 'At least one item is required');
        }

        $touched = 0;
        foreach ($items as $i => $line) {
            $itemId = (int) ($line['itemId'] ?? 0);
            if ($itemId <= 0) {
                throw new ApiException(400, 'INVALID_ITEM_ID', "Item #{$i}: itemId is required");
            }
            $aktual = (float) ($line['aktualProduksi'] ?? 0);
            $reject = (float) ($line['rejectProduksi'] ?? 0);
            if ($aktual < 0 || $reject < 0) {
                throw new ApiException(400, 'INVALID_QTY', "Item #{$i}: aktualProduksi/rejectProduksi cannot be negative");
            }
            $ok = $this->repo->updateItemActualProduksi($this->pdo, $orderId, $itemId, $aktual, $reject);
            if (!$ok) {
                throw new ApiException(400, 'UNKNOWN_ITEM_FOR_ORDER', "Item #{$i}: itemId {$itemId} does not belong to this order");
            }
            $touched++;
        }

        Versioning::update($this->pdo, 'special_order', 'special_order_id', $orderId, $expectedVersion, 'status = status', []);
        Audit::write($this->pdo, $requestId, $userId, 'special_order.actual_updated', 'special_order', (string) $orderId, 'ok', $expectedVersion, $expectedVersion + 1, ['itemsTouched' => $touched]);

        return $this->getOrder($orderId);
    }

    public function cancelOrder(int $orderId, int $expectedVersion, string $reason, int $userId, ?string $requestId): array
    {
        if (trim($reason) === '') {
            throw new ApiException(400, 'REASON_REQUIRED', 'A cancellation reason is required');
        }
        $order = $this->repo->lockOrderById($this->pdo, $orderId);
        if ($order === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Special order not found');
        }
        if (in_array($order['status'], ['completed', 'cancelled'], true)) {
            throw new ApiException(400, 'INVALID_STATUS', 'A completed or already-cancelled order cannot be cancelled');
        }
        Versioning::update(
            $this->pdo, 'special_order', 'special_order_id', $orderId, $expectedVersion,
            "status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), cancelled_by = ?, cancel_reason = ?",
            [$userId, $reason]
        );
        Audit::write($this->pdo, $requestId, $userId, 'special_order.cancelled', 'special_order', (string) $orderId, 'ok', $expectedVersion, $expectedVersion + 1, ['reason' => $reason]);

        // Existing FG Allocation Bridge: release any still-active General FG
        // earmark for this order now that it can never ship (task's own
        // "if order cancelled BEFORE shipment, release unused active
        // allocation" — already-consumed qty is untouched, matching
        // "never reverse already-dispatched stock without a formal
        // reversal flow").
        (new SpecialOrderFgAllocationService($this->pdo))->releaseAllForOrder($this->pdo, $orderId, $userId, $requestId);

        return $this->getOrder($orderId);
    }

    /**
     * GET /api/special-orders/production-inbox — Production's "Order
     * Masuk / Demand Tambahan" view, grouped by division (task's own
     * "Production inbox must group/filter items by their own division").
     * @param array{factoryId?:int,divisionId?:int,tanggal?:string,status?:string,sourceType?:string} $filters
     */
    public function productionInbox(array $filters): array
    {
        $rows = $this->repo->findProductionDemandItems($this->pdo, $filters);
        $allocSvc = new SpecialOrderFgAllocationService($this->pdo);

        $byDivision = [];
        $totalsBySource = ['toko_khusus' => 0.0, 'non_toko' => 0.0];
        foreach ($rows as $r) {
            $divId = (int) $r['division_id'];
            if (!isset($byDivision[$divId])) {
                $byDivision[$divId] = ['divisionId' => $divId, 'divisionName' => $r['division_name'], 'items' => []];
            }
            if (!isset($byDivision[$divId]['factoryName'])) {
                $byDivision[$divId]['factoryId'] = (int) $r['item_factory_id'];
                $byDivision[$divId]['factoryName'] = $r['item_factory_name'];
            }
            $fgAvailable = null;
            $allocatedFromGeneralFg = 0.0;
            $productionNeed = null;
            $maxAllocatable = 0.0;
            $allocationStatus = null;
            // Stock is checked at the ITEM's own routed factory
            // (item_factory_id, derived from division.factory_id) —
            // never the order header's factory_id, which is null (or
            // simply irrelevant) for a multi-factory order. "Existing FG
            // Allocation Bridge": fgAvailable is now the TRUE free FG
            // (physical minus every other order's active reservation,
            // never raw stock_balance alone), and productionNeed already
            // nets out whatever this item has explicitly allocated —
            // never silently, only via a prior "Alokasikan dari FG" call.
            if ($r['item_type'] === 'existing_product' && $r['product_id'] !== null) {
                $view = $allocSvc->itemAllocationView((int) $r['special_order_item_id']);
                $fgAvailable = $view['fgAvailable'];
                $allocatedFromGeneralFg = $view['allocatedFromGeneralFg'];
                $productionNeed = $view['productionNeed'];
                $maxAllocatable = $view['maxAllocatable'];
                $allocationStatus = $view['status'];
            }
            $byDivision[$divId]['items'][] = [
                'orderNo' => $r['order_no'],
                'sourceType' => $r['source_type'],
                // sourceLabel here is "Sumber — Siapa" ($this->sourceLabel())
                // — a different, richer field than the plain badge text a
                // caller wants for a "Sumber" column; normalizedSourceType
                // gives that badge its real, granular value (CS/Sales
                // Executive/Konsumen Langsung/Umum — never generic "Pesanan
                // Non-Toko", task's own "Final Blocker Fix" Section G).
                'normalizedSourceType' => NormalizedSourceType::fromSpecialOrder((string) $r['source_type'], $r['non_store_source'] ?? null),
                'sourceLabel' => $this->sourceLabel($r),
                'storeOrCustomerName' => $r['source_type'] === 'toko_khusus' ? ($r['store_name'] ?? '-') : ($r['customer_name'] ?? '-'),
                'itemType' => $r['item_type'],
                'itemName' => $r['item_name_snapshot'],
                'qty' => (float) $r['qty'],
                'charge' => (float) $r['charge'],
                'factoryId' => (int) $r['item_factory_id'],
                'factoryName' => $r['item_factory_name'],
                'requiredDate' => $r['required_date'],
                'requiredTime' => $r['required_time'],
                'specialNote' => $r['special_note'],
                'status' => $r['status'],
                'itemId' => (int) $r['special_order_item_id'],
                'fgAvailable' => $fgAvailable,
                'allocatedFromGeneralFg' => $allocatedFromGeneralFg,
                'productionNeed' => $productionNeed,
                'maxAllocatable' => $maxAllocatable,
                'allocationStatus' => $allocationStatus,
                'canAllocateFg' => $maxAllocatable > 0.0001,
            ];
            $totalsBySource[$r['source_type']] = ($totalsBySource[$r['source_type']] ?? 0.0) + (float) $r['qty'];
        }

        return [
            'divisions' => array_values($byDivision),
            // Breakdown is deliberately partial (task's own "do NOT merge
            // them into one opaque number without source traceability"):
            // PO Reguler and Replacement Reject totals live on their own
            // existing pages (Produksi target / a future Replacement
            // Reject phase) and are never duplicated or estimated here.
            'demandBreakdown' => [
                'pesananKhususToko' => $totalsBySource['toko_khusus'],
                'pesananNonToko' => $totalsBySource['non_toko'],
                'poReguler' => null,
                'replacementReject' => null,
                'note' => 'PO Reguler dan Replacement Reject dilihat di halaman Produksi/PO masing-masing — tidak digabung di sini agar sumber datanya tetap jelas.',
            ],
        ];
    }

    /**
     * FG-eligible items for special/non-regular orders — the read side of
     * the Production → FG bridge (task's own scope E/F). Reuses
     * findProductionDemandItems() (already source-aware) rather than a new
     * query, and only surfaces items that actually have Actual Produksi >
     * 0 (nothing to verify otherwise). FG SOURCE MODEL fields (task's own
     * spec): sourceType/sourceLabel/orderId (source_reference_id),
     * division/factory, aktualProduksi (production_actual_good),
     * fgVerifiedQty (already_verified_qty), availableToVerify.
     */
    public function fgEligibleItems(array $filters): array
    {
        $rows = $this->repo->findProductionDemandItems($this->pdo, $filters);
        $allocSvc = new SpecialOrderFgAllocationService($this->pdo);
        $out = [];
        foreach ($rows as $r) {
            $itemId = (int) $r['special_order_item_id'];
            $aktual = (float) $r['aktual_produksi'];
            $allocSummary = $r['item_type'] === 'existing_product' && $r['product_id'] !== null
                ? $allocSvc->allocationSummaryForItem($itemId)
                : ['remaining' => 0.0, 'committed' => 0.0, 'consumed' => 0.0];
            // "Existing FG Allocation Bridge" — an item with zero special
            // production but an explicit General FG allocation must NOT
            // dead-end here (the real cPanel UAT bug this feature fixes);
            // it is eligible the moment EITHER origin has something ready.
            if ($aktual <= 0.0001 && $allocSummary['committed'] <= 0.0001) {
                continue;
            }
            $allocated = $this->repo->sumAllocatedForItem($this->pdo, $itemId);
            $shipped = $this->repo->sumShippedForItem($this->pdo, $itemId);
            $dariFgExisting = $allocSummary['remaining'];
            $dariProduksiKhusus = (float) $r['fg_verified_qty'];
            $totalSiapUntukOrder = $dariFgExisting + $dariProduksiKhusus;
            // Normalized downstream source (task's own "Final Blocker Fix"
            // — CS/Sales Executive/Konsumen Langsung/Umum must never
            // collapse into generic "Pesanan Non-Toko"). sourceType stays
            // RAW for backward compat (fg-khusus-non-toko.php's filter
            // dropdown keys off it); sourceLabel is now the real, granular
            // label.
            $normalizedSourceType = NormalizedSourceType::fromSpecialOrder((string) $r['source_type'], $r['non_store_source'] ?? null);
            $out[] = [
                'itemId' => (int) $r['special_order_item_id'],
                'orderId' => (int) $r['special_order_id'],
                'orderNo' => $r['order_no'],
                'sourceType' => $r['source_type'],
                'nonStoreSource' => $r['non_store_source'] ?? null,
                'normalizedSourceType' => $normalizedSourceType,
                'sourceLabel' => NormalizedSourceType::label($normalizedSourceType),
                'storeOrCustomerName' => $r['source_type'] === 'toko_khusus' ? ($r['store_name'] ?? '-') : ($r['customer_name'] ?? '-'),
                'itemType' => $r['item_type'],
                'itemName' => $r['item_name_snapshot'],
                'divisionId' => (int) $r['division_id'],
                'divisionName' => $r['division_name'],
                'factoryId' => isset($r['item_factory_id']) ? (int) $r['item_factory_id'] : null,
                'factoryName' => $r['item_factory_name'] ?? null,
                'productId' => $r['product_id'] !== null ? (int) $r['product_id'] : null,
                'aktualProduksi' => $aktual,
                'rejectProduksi' => (float) $r['reject_produksi'],
                'fgVerifiedQty' => (float) $r['fg_verified_qty'],
                'availableToVerify' => max(0.0, $aktual - (float) $r['fg_verified_qty']),
                // FG allocation model (task's own rework): allocatedQty is
                // the SUM of planned_qty already committed to non-cancelled
                // DOs; shippedQty is the real, physically-dispatched sum;
                // availableForDo is what a NEW DO may still draw from —
                // never double-countable (see SpecialOrderRepository's own
                // sumAllocatedForItem/sumShippedForItem docblocks).
                // Existing FG Allocation Bridge: availableForDo now combines
                // BOTH fulfillment origins — dariFgExisting (still-active,
                // unconsumed General FG earmark) + dariProduksiKhusus
                // (verified special production) — before subtracting what's
                // already committed to a DO, so an order can reach DO purely
                // from allocated General FG with zero special production.
                'allocatedQty' => $allocated,
                'shippedQty' => $shipped,
                'dariFgExisting' => $dariFgExisting,
                'dariProduksiKhusus' => $dariProduksiKhusus,
                'totalSiapUntukOrder' => $totalSiapUntukOrder,
                'availableForDo' => max(0.0, $totalSiapUntukOrder - $allocated),
                'requiredDate' => $r['required_date'] ?? null,
                'specialNote' => $r['special_note'] ?? null,
                'status' => $r['status'],
            ];
        }
        return $out;
    }

    /**
     * Verify (confirm) FG-ready quantity for one special/non-regular order
     * item. Snapshot semantics (same convention as production_item.aktual
     * and special_order_item.aktual_produksi/reject_produksi): each call
     * REPLACES the stored value, it never adds to it — repeating the same
     * call is naturally idempotent and never inflates availability.
     *
     * Row-locks the item first (concurrency guard) and refuses to drop
     * fgVerifiedQty below what has ALREADY been physically shipped (task's
     * own explicit rule: "FG Verified may not be reduced below shipped/
     * consumed quantity" — e.g. FG Verified 10, Shipped 6 -> cannot change
     * to 2).
     */
    public function verifyItemFg(int $itemId, float $fgVerifiedQty, int $userId, ?string $requestId): array
    {
        $item = $this->repo->lockItemById($this->pdo, $itemId);
        if ($item === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Item pesanan tidak ditemukan');
        }
        if (!in_array($item['order_status'], self::PRODUCTION_STATUSES, true)) {
            throw new ApiException(400, 'INVALID_STATUS', 'Verifikasi FG hanya bisa dilakukan setelah pesanan dikirim ke Produksi');
        }
        $aktual = (float) $item['aktual_produksi'];
        if ($fgVerifiedQty < 0 || $fgVerifiedQty > $aktual + 0.0001) {
            throw new ApiException(400, 'INVALID_FG_QTY', 'Qty FG terverifikasi harus antara 0 dan Aktual Produksi');
        }
        $shipped = $this->repo->sumShippedForItem($this->pdo, $itemId);
        if ($fgVerifiedQty < $shipped - 0.0001) {
            throw new ApiException(400, 'FG_BELOW_SHIPPED', "FG Terverifikasi tidak boleh dikurangi di bawah jumlah yang sudah dikirim ({$shipped})");
        }
        $this->repo->updateFgVerifiedQty($this->pdo, $itemId, $fgVerifiedQty);
        Audit::write(
            $this->pdo,
            $requestId,
            $userId,
            'special_order.fg_verified',
            'special_order_item',
            (string) $itemId,
            'ok',
            null,
            null,
            ['fgVerifiedQty' => $fgVerifiedQty, 'orderId' => (int) $item['special_order_id']]
        );
        return $this->getOrder((int) $item['special_order_id']);
    }

    // -----------------------------------------------------------------
    // Validation / resolution helpers
    // -----------------------------------------------------------------

    private function validateHeader(array $input): array
    {
        $sourceType = (string) ($input['sourceType'] ?? '');
        if (!in_array($sourceType, self::VALID_SOURCE_TYPES, true)) {
            throw new ApiException(400, 'INVALID_SOURCE_TYPE', 'sourceType must be toko_khusus or non_toko');
        }
        $orderDate = $this->requireDate($input['orderDate'] ?? null, 'orderDate');
        $requiredDate = $this->requireDate($input['requiredDate'] ?? null, 'requiredDate');
        $requiredTime = $input['requiredTime'] ?? null;
        if ($requiredTime !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $requiredTime)) {
            throw new ApiException(400, 'INVALID_REQUIRED_TIME', 'requiredTime must be HH:MM');
        }

        $storeId = null;
        $nonStoreSource = null;
        $customerName = null;
        $customerContact = null;
        $fulfillmentType = null;
        $deliveryAddress = null;

        if ($sourceType === 'toko_khusus') {
            $storeId = (int) ($input['storeId'] ?? 0);
            if ($storeId <= 0) {
                throw new ApiException(400, 'STORE_REQUIRED', 'storeId is required for Pesanan Khusus Toko');
            }
            $stmt = $this->pdo->prepare('SELECT store_id FROM store WHERE store_id = ? AND active = 1');
            $stmt->execute([$storeId]);
            if ($stmt->fetchColumn() === false) {
                throw new ApiException(404, 'STORE_NOT_FOUND', 'Store not found or inactive');
            }
        } else {
            $nonStoreSource = (string) ($input['nonStoreSource'] ?? '');
            if (!in_array($nonStoreSource, self::VALID_NON_STORE_SOURCES, true)) {
                throw new ApiException(400, 'INVALID_NON_STORE_SOURCE', 'nonStoreSource must be one of: ' . implode(', ', self::VALID_NON_STORE_SOURCES));
            }
            $customerName = trim((string) ($input['customerName'] ?? ''));
            if ($customerName === '') {
                throw new ApiException(400, 'CUSTOMER_NAME_REQUIRED', 'customerName is required for Pesanan Non-Toko');
            }
            $customerContact = $this->nullableString($input['customerContact'] ?? null);
            $fulfillmentType = $input['fulfillmentType'] ?? null;
            if ($fulfillmentType !== null && !in_array($fulfillmentType, self::VALID_FULFILLMENT_TYPES, true)) {
                throw new ApiException(400, 'INVALID_FULFILLMENT_TYPE', 'fulfillmentType must be pengiriman or pickup');
            }
            $deliveryAddress = $this->nullableString($input['deliveryAddress'] ?? null);
        }

        $picUserId = isset($input['picUserId']) && $input['picUserId'] !== '' && $input['picUserId'] !== null ? (int) $input['picUserId'] : null;

        // factoryId is deliberately NOT read from $input here (task: "Do
        // not require a manual factory selector" / ROUTE-10 "user cannot
        // manually override factory inconsistently") — any factoryId a
        // client sends is silently ignored; createOrder() computes the
        // real value itself from each item's own resolved routing once
        // every item has been validated.
        return [
            'sourceType' => $sourceType,
            'orderDate' => $orderDate,
            'storeId' => $storeId,
            'nonStoreSource' => $nonStoreSource,
            'customerName' => $customerName,
            'customerContact' => $customerContact,
            'fulfillmentType' => $fulfillmentType,
            'deliveryAddress' => $deliveryAddress,
            'requiredDate' => $requiredDate,
            'requiredTime' => $requiredTime,
            'picUserId' => $picUserId,
            'generalNote' => $this->nullableString($input['generalNote'] ?? null),
        ];
    }

    private function resolveItem(array $raw, int $index): array
    {
        $itemType = (string) ($raw['itemType'] ?? '');
        if (!in_array($itemType, self::VALID_ITEM_TYPES, true)) {
            throw new ApiException(400, 'INVALID_ITEM_TYPE', "Item #{$index}: itemType must be existing_product or special_catalog");
        }
        $qty = (float) ($raw['qty'] ?? 0);
        if ($qty <= 0) {
            throw new ApiException(400, 'INVALID_QTY', "Item #{$index}: qty must be greater than 0");
        }
        $charge = (float) ($raw['charge'] ?? 0);
        if ($charge < 0) {
            throw new ApiException(400, 'INVALID_CHARGE', "Item #{$index}: charge cannot be negative");
        }
        // Extra Packaging (task's own approved rule): a manually-entered,
        // per-LINE nominal — added ONCE to the subtotal, never multiplied
        // by qty (unlike unitPrice, which IS multiplied by qty).
        $extraPackaging = (float) ($raw['extraPackaging'] ?? 0);
        if ($extraPackaging < 0) {
            throw new ApiException(400, 'INVALID_EXTRA_PACKAGING', "Item #{$index}: extraPackaging cannot be negative");
        }
        $specialNote = $this->nullableString($raw['specialNote'] ?? null);

        if ($itemType === 'existing_product') {
            $productId = (int) ($raw['productId'] ?? 0);
            if ($productId <= 0) {
                throw new ApiException(400, 'PRODUCT_REQUIRED', "Item #{$index}: productId is required for an existing-product item");
            }
            $product = $this->repo->findProductWithDivision($this->pdo, $productId);
            if ($product === null) {
                throw new ApiException(404, 'PRODUCT_NOT_FOUND', "Item #{$index}: product not found or inactive");
            }
            if ($product['division_id'] === null) {
                throw new ApiException(422, 'PRODUCT_DIVISION_MISSING', "Item #{$index}: product '{$product['name']}' has no production division set in Master Data — division is mandatory for every order item");
            }
            $divisionId = (int) $product['division_id'];
            $routing = ProductionRoutingService::resolveFactoryForDivision($this->pdo, $divisionId);
            $unitPrice = isset($raw['unitPrice']) && $raw['unitPrice'] !== '' ? (float) $raw['unitPrice'] : (float) $product['harga'];
            $subtotal = round($qty * $unitPrice + $charge + $extraPackaging, 2);
            return [
                'itemType' => 'existing_product',
                'productId' => $productId,
                'specialCatalogId' => null,
                'divisionId' => $divisionId,
                'divisionName' => $routing['divisionName'],
                'factoryId' => $routing['factoryId'],
                'factoryName' => $routing['factoryName'],
                'itemNameSnapshot' => $product['name'],
                'qty' => $qty,
                'unitPrice' => $unitPrice,
                'charge' => $charge,
                'extraPackaging' => $extraPackaging,
                'subtotal' => $subtotal,
                'specialNote' => $specialNote,
            ];
        }

        $catalogId = (int) ($raw['specialCatalogId'] ?? 0);
        if ($catalogId <= 0) {
            throw new ApiException(400, 'CATALOG_ITEM_REQUIRED', "Item #{$index}: specialCatalogId is required for a special/custom item");
        }
        $catalog = $this->repo->findCatalogItem($this->pdo, $catalogId);
        if ($catalog === null || (int) $catalog['active'] !== 1) {
            throw new ApiException(404, 'CATALOG_ITEM_NOT_FOUND', "Item #{$index}: special catalog item not found or inactive");
        }
        $divisionId = (int) $catalog['division_id'];
        $routing = ProductionRoutingService::resolveFactoryForDivision($this->pdo, $divisionId);
        $unitPrice = isset($raw['unitPrice']) && $raw['unitPrice'] !== ''
            ? (float) $raw['unitPrice']
            : ($catalog['default_price'] !== null ? (float) $catalog['default_price'] : 0.0);
        if (!isset($raw['charge']) || $raw['charge'] === '' || $raw['charge'] === null) {
            $charge = $catalog['default_charge'] !== null ? (float) $catalog['default_charge'] : 0.0;
        }
        $subtotal = round($qty * $unitPrice + $charge + $extraPackaging, 2);
        return [
            'itemType' => 'special_catalog',
            'productId' => null,
            'specialCatalogId' => $catalogId,
            'divisionId' => $divisionId,
            'divisionName' => $routing['divisionName'],
            'factoryId' => $routing['factoryId'],
            'factoryName' => $routing['factoryName'],
            'itemNameSnapshot' => $catalog['name'],
            'qty' => $qty,
            'unitPrice' => $unitPrice,
            'charge' => $charge,
            'extraPackaging' => $extraPackaging,
            'subtotal' => $subtotal,
            'specialNote' => $specialNote,
        ];
    }

    private function sourceLabel(array $r): string
    {
        if ($r['source_type'] === 'toko_khusus') {
            return (string) ($r['store_name'] ?? '-');
        }
        $labels = ['konsumen_langsung' => 'Konsumen Langsung', 'cs' => 'CS', 'sales_executive' => 'Sales Executive', 'umum' => 'Umum'];
        $sourceLabel = $labels[$r['non_store_source']] ?? (string) $r['non_store_source'];
        return $sourceLabel . ' — ' . ($r['customer_name'] ?? '-');
    }

    private function buildOrderDto(array $order, array $items): array
    {
        $itemDtos = array_map(fn ($it) => [
            'itemId' => (int) $it['special_order_item_id'],
            'itemType' => $it['item_type'],
            'productId' => $it['product_id'] !== null ? (int) $it['product_id'] : null,
            'specialCatalogId' => $it['special_catalog_id'] !== null ? (int) $it['special_catalog_id'] : null,
            'divisionId' => (int) $it['division_id'],
            'divisionName' => $it['division_name'],
            'factoryId' => (int) $it['item_factory_id'],
            'factoryName' => $it['item_factory_name'],
            'itemName' => $it['item_name_snapshot'],
            'qty' => (float) $it['qty'],
            'unitPrice' => (float) $it['unit_price'],
            'charge' => (float) $it['charge'],
            'extraPackaging' => (float) $it['extra_packaging'],
            'subtotal' => (float) $it['subtotal'],
            'specialNote' => $it['special_note'],
            'aktualProduksi' => (float) $it['aktual_produksi'],
            'rejectProduksi' => (float) $it['reject_produksi'],
            'fgVerifiedQty' => (float) $it['fg_verified_qty'],
            'availableToVerify' => max(0.0, (float) $it['aktual_produksi'] - (float) $it['fg_verified_qty']),
        ], $items);

        $divisionNames = array_values(array_unique(array_column($itemDtos, 'divisionName')));
        $factoryNames = array_values(array_unique(array_column($itemDtos, 'factoryName')));

        // "Informasi Produksi" — divisions grouped under the factory they
        // route to, for the detail page's routing summary panel. Built
        // from the items themselves (never re-derived from the header),
        // so it is correct even for a multi-factory order.
        $routingByFactory = [];
        foreach ($itemDtos as $it) {
            $fName = $it['factoryName'];
            if (!isset($routingByFactory[$fName])) {
                $routingByFactory[$fName] = ['factoryId' => $it['factoryId'], 'factoryName' => $fName, 'divisionNames' => []];
            }
            if (!in_array($it['divisionName'], $routingByFactory[$fName]['divisionNames'], true)) {
                $routingByFactory[$fName]['divisionNames'][] = $it['divisionName'];
            }
        }

        return [
            'orderId' => (int) $order['special_order_id'],
            'orderNo' => $order['order_no'],
            'sourceType' => $order['source_type'],
            'orderDate' => $order['order_date'],
            'storeId' => $order['store_id'] !== null ? (int) $order['store_id'] : null,
            'storeName' => $order['store_name'],
            'nonStoreSource' => $order['non_store_source'],
            'customerName' => $order['customer_name'],
            'customerContact' => $order['customer_contact'],
            'fulfillmentType' => $order['fulfillment_type'],
            'deliveryAddress' => $order['delivery_address'],
            'factoryId' => $order['factory_id'] !== null ? (int) $order['factory_id'] : null,
            'factoryName' => $order['factory_name'],
            'requiredDate' => $order['required_date'],
            'requiredTime' => $order['required_time'],
            'picUserId' => $order['pic_user_id'] !== null ? (int) $order['pic_user_id'] : null,
            'picName' => $order['pic_name'],
            'generalNote' => $order['general_note'],
            'status' => $order['status'],
            'version' => (int) $order['version'],
            'isMultiDivision' => count($divisionNames) > 1,
            'divisionNames' => $divisionNames,
            'isMultiFactory' => count($factoryNames) > 1,
            'factoryNames' => $factoryNames,
            'productionRouting' => array_values($routingByFactory),
            'createdByName' => $order['created_by_name'],
            'createdAt' => $order['created_at'],
            'cancelReason' => $order['cancel_reason'],
            'items' => $itemDtos,
        ];
    }

    private function buildOrderSummaryDto(array $r): array
    {
        return [
            'orderId' => (int) $r['special_order_id'],
            'orderNo' => $r['order_no'],
            'sourceType' => $r['source_type'],
            'orderDate' => $r['order_date'],
            'storeName' => $r['store_name'],
            'customerName' => $r['customer_name'],
            'nonStoreSource' => $r['non_store_source'],
            'requiredDate' => $r['required_date'],
            'requiredTime' => $r['required_time'],
            'status' => $r['status'],
            'version' => (int) $r['version'],
            'isMultiDivision' => (int) $r['division_count'] > 1,
            'isMultiFactory' => (int) $r['factory_count'] > 1,
        ];
    }

    private function requireDate(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new ApiException(400, 'INVALID_DATE', "{$field} must be YYYY-MM-DD");
        }
        return $value;
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
