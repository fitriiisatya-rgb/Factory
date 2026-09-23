<?php

declare(strict_types=1);

namespace Amor\Api\SpecialOrder;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Mail\ShipmentEmailService;
use Amor\Api\Users\UserRepository;
use PDO;

/**
 * Business orchestration for special_order_do — the SEPARATE,
 * source-specific DO + real shipment write path for Pesanan Khusus Toko /
 * Pesanan Non-Toko (migration 0012, reworked per the task's own "Special /
 * Non-Regular Fulfillment Completion" deep-check).
 *
 * CRITICAL DISPATCH RULE (task's own words, enforced throughout this
 * class): DO creation does NOT reduce FG. Courier booking does NOT reduce
 * FG. Driver claim does NOT reduce FG. FG is reduced ONLY when goods
 * physically leave the factory — confirmDeparture() for DRIVER_INTERNAL,
 * courierHandover() for EXTERNAL_COURIER. Both share the private
 * dispatch() method, which is the ONLY place in this class that creates a
 * real `shipment` row — there is no other code path that can mark a DO
 * "shipped" without one.
 *
 * FG double-consumption safety: every method that reads or writes
 * fg_verified_qty / allocated / shipped sums for a special_order_item
 * FIRST row-locks that item (SpecialOrderRepository::lockItemById), and
 * every method that touches a DO's own state FIRST row-locks the DO
 * (SpecialOrderDoRepository::lockDoById) — two concurrent callers racing
 * for the same DO or the same item's FG always serialize on one of these
 * locks, never silently double-allocate or double-ship (task's own
 * "Two users must not dispatch the same available FG simultaneously").
 */
final class SpecialOrderDoService
{
    private SpecialOrderDoRepository $repo;
    private SpecialOrderRepository $orderRepo;
    private UserRepository $userRepo;
    private ShipmentEmailService $emailService;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new SpecialOrderDoRepository();
        $this->orderRepo = new SpecialOrderRepository();
        $this->userRepo = new UserRepository();
        $this->emailService = new ShipmentEmailService();
    }

    /**
     * POST /api/special-order-do — creates a NEW DO scoped to one factory
     * (task's own "pickup_factory_id must always be the factory associated
     * with the DO" — a multi-factory order needs one DO per factory,
     * mirroring Regular PO's own MIXED_FACTORY_SHIPMENT principle one
     * level higher). An order may have MULTIPLE DOs over time (task's own
     * explicit "Do NOT enforce one-and-only-one DO per special order") —
     * this is a plain create, idempotent only via the controller's
     * Idempotency-Key header, never by reusing an existing open DO.
     *
     * $itemsOverride: optional [{itemId, qty}] — when omitted, every
     * order item in $factoryId with availableForDo > 0 is included at its
     * FULL available quantity (the simple one-click "Buat DO" default).
     */
    public function create(
        int $orderId,
        int $factoryId,
        ?array $itemsOverride,
        string $deliveryMethod,
        ?string $courierProvider,
        ?string $courierName,
        ?string $externalOrderReference,
        ?int $dropStoreIdOverride,
        int $userId,
        ?string $requestId
    ): array {
        if (!in_array($deliveryMethod, ['DRIVER_INTERNAL', 'EXTERNAL_COURIER'], true)) {
            throw new ApiException(400, 'INVALID_DELIVERY_METHOD', 'deliveryMethod must be DRIVER_INTERNAL or EXTERNAL_COURIER');
        }
        if ($deliveryMethod === 'EXTERNAL_COURIER' && !in_array($courierProvider, ['grab', 'gosend', 'lalamove', 'other'], true)) {
            throw new ApiException(400, 'INVALID_COURIER_PROVIDER', 'provider must be one of grab/gosend/lalamove/other');
        }

        $order = $this->orderRepo->findOrderById($this->pdo, $orderId);
        if ($order === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Pesanan tidak ditemukan');
        }

        $dropStoreId = $order['source_type'] === 'toko_khusus'
            ? (int) $order['store_id']
            : $dropStoreIdOverride;
        if ($dropStoreId === null) {
            throw new ApiException(400, 'DROP_STORE_REQUIRED', 'Toko/Bakery tujuan pengiriman fisik wajib dipilih untuk pesanan non-toko');
        }

        $demand = $this->orderRepo->findProductionDemandItems($this->pdo, ['orderId' => $orderId, 'factoryId' => $factoryId]);
        $demandByItem = [];
        foreach ($demand as $d) {
            $demandByItem[(int) $d['special_order_item_id']] = $d;
        }

        $lines = [];
        if ($itemsOverride !== null) {
            foreach ($itemsOverride as $line) {
                $itemId = (int) ($line['itemId'] ?? 0);
                $qty = (float) ($line['qty'] ?? 0);
                if (!isset($demandByItem[$itemId])) {
                    throw new ApiException(400, 'MIXED_FACTORY_DO', "Item {$itemId} bukan bagian dari pesanan ini di factory yang dipilih — satu DO tidak boleh mencampur factory berbeda");
                }
                if ($qty > 0.0001) {
                    $lines[(int) $itemId] = $qty;
                }
            }
        } else {
            foreach ($demandByItem as $itemId => $d) {
                $verified = (float) $d['fg_verified_qty'];
                if ($verified <= 0.0001) {
                    continue;
                }
                $lines[$itemId] = null; // resolved to full availableForDo under lock below
            }
        }
        if ($lines === []) {
            throw new ApiException(400, 'NO_FG_VERIFIED_DEMAND', 'Belum ada item yang terverifikasi FG untuk pesanan/factory ini — tidak ada yang bisa dibuat DO.');
        }

        // Fixed lock order (smallest item id first) across every caller —
        // prevents a lock-order deadlock when two requests touch two of
        // the same items in opposite order (same discipline as
        // DispatchService::claim()'s own docblock).
        $itemIds = array_keys($lines);
        sort($itemIds);

        $resolved = [];
        foreach ($itemIds as $itemId) {
            $item = $this->orderRepo->lockItemById($this->pdo, $itemId);
            $allocated = $this->orderRepo->sumAllocatedForItem($this->pdo, $itemId);
            $available = max(0.0, (float) $item['fg_verified_qty'] - $allocated);
            $requested = $lines[$itemId] ?? $available;
            if ($requested <= 0.0001) {
                continue;
            }
            if ($requested > $available + 0.0001) {
                throw new ApiException(400, 'EXCEEDS_AVAILABLE_FOR_DO', "Item {$itemId}: qty {$requested} melebihi yang tersedia untuk DO baru ({$available}) — planned qty tidak boleh melebihi sisa kebutuhan yang belum terpenuhi");
            }
            $resolved[$itemId] = $requested;
        }
        if ($resolved === []) {
            throw new ApiException(400, 'NO_FG_VERIFIED_DEMAND', 'Belum ada item yang terverifikasi FG untuk pesanan/factory ini — tidak ada yang bisa dibuat DO.');
        }

        $tanggal = (string) $order['required_date'];
        $docNo = $this->repo->allocateDoNumber($this->pdo, $tanggal);
        $doId = $this->repo->createDo(
            $this->pdo, $docNo, $tanggal, $orderId, (string) $order['source_type'], $factoryId, $dropStoreId,
            $deliveryMethod, $courierProvider, $courierName, $externalOrderReference,
            $order['customer_name'], $order['customer_contact'], $order['delivery_address'], $userId
        );
        foreach ($resolved as $itemId => $qty) {
            $this->repo->insertDoItem($this->pdo, $doId, $itemId, $qty);
        }

        Audit::write($this->pdo, $requestId, $userId, 'special_order_do.create', 'special_order_do', (string) $doId, 'ok', null, null, ['orderId' => $orderId, 'docNo' => $docNo, 'factoryId' => $factoryId, 'deliveryMethod' => $deliveryMethod, 'items' => $resolved]);

        return $this->buildDoDto($doId);
    }

    public function getDo(int $doId): array
    {
        return $this->buildDoDto($doId);
    }

    /** @return array<int,array> */
    public function listDos(array $filters): array
    {
        $rows = $this->repo->findDos($this->pdo, $filters);
        return array_map(fn ($r) => $this->summaryDto($r), $rows);
    }

    /** GET /api/special-order-do/driver-pool — Driver Portal's own eligible-dispatch list (DRIVER_INTERNAL only; EXTERNAL_COURIER never appears here — task's own mutual-exclusion rule). */
    public function driverPool(int $driverUserId): array
    {
        $rows = $this->repo->findDriverPool($this->pdo, $driverUserId);
        return array_map(function ($r) use ($driverUserId) {
            $dto = $this->summaryDto($r);
            $dto['items'] = array_map(function ($it) {
                return [
                    'doItemId' => (int) $it['special_order_do_item_id'],
                    'itemName' => $it['item_name_snapshot'],
                    'plannedQty' => (float) $it['planned_qty'],
                    'shippedQty' => (float) $it['shipped_qty'],
                    'remainingQty' => max(0.0, (float) $it['planned_qty'] - (float) $it['shipped_qty']),
                ];
            }, $this->repo->findDoItems($this->pdo, (int) $r['special_order_do_id']));
            $dto['isMine'] = (int) ($r['claimed_by_user_id'] ?? 0) === $driverUserId;
            return $dto;
        }, $rows);
    }

    /** POST /api/special-order-do/{id}/claim — a driver claims the WHOLE document (see the migration's own docblock for why this is DO-level, not per-item pooled). */
    public function claim(int $doId, int $driverUserId, ?string $requestId): array
    {
        $do = $this->requireLockedDo($doId);
        if ($do['delivery_method'] !== 'DRIVER_INTERNAL') {
            throw new ApiException(400, 'WRONG_DELIVERY_METHOD', 'DO ini bukan untuk Driver Internal');
        }
        if (!in_array($do['status'], ['open', 'partial'], true)) {
            throw new ApiException(400, 'INVALID_STATUS', 'DO ini sudah selesai dikirim atau dibatalkan');
        }
        if ($do['claimed_by_user_id'] !== null && (int) $do['claimed_by_user_id'] !== $driverUserId) {
            throw new ApiException(409, 'ALREADY_CLAIMED', 'DO ini sudah diambil driver lain');
        }
        if ((int) ($do['claimed_by_user_id'] ?? 0) !== $driverUserId) {
            $this->repo->claim($this->pdo, $doId, $driverUserId);
            Audit::write($this->pdo, $requestId, $driverUserId, 'special_order_do.claim', 'special_order_do', (string) $doId, 'ok', null, null, null);
        }
        return $this->buildDoDto($doId);
    }

    /** POST /api/special-order-do/{id}/release */
    public function release(int $doId, int $userId, bool $isAdmin, ?string $requestId): array
    {
        $do = $this->requireLockedDo($doId);
        if ($do['claimed_by_user_id'] === null) {
            return $this->buildDoDto($doId);
        }
        if (!$isAdmin && (int) $do['claimed_by_user_id'] !== $userId) {
            throw new ApiException(403, 'FORBIDDEN', 'Anda hanya dapat melepas klaim milik Anda sendiri');
        }
        $this->repo->release($this->pdo, $doId);
        Audit::write($this->pdo, $requestId, $userId, 'special_order_do.release', 'special_order_do', (string) $doId, 'ok', null, null, null);
        return $this->buildDoDto($doId);
    }

    public function cancel(int $doId, int $expectedVersion, string $reason, int $userId, ?string $requestId): array
    {
        $do = $this->requireLockedDo($doId);
        $this->checkVersion($do, $expectedVersion);
        if ($do['status'] === 'cancelled') {
            throw new ApiException(400, 'INVALID_STATUS', 'DO ini sudah dibatalkan.');
        }
        if ($do['status'] !== 'open') {
            // Any real shipment (partial or fully shipped) already moved
            // physical FG — cancelling would leave a dispatch orphaned
            // with no document. Same principle as CANNOT_CANCEL_SHIPPED.
            throw new ApiException(400, 'CANNOT_CANCEL_SHIPPED', 'DO ini sudah memiliki pengiriman aktual — tidak bisa dibatalkan.');
        }
        if (trim($reason) === '') {
            throw new ApiException(400, 'REASON_REQUIRED', 'Alasan wajib diisi.');
        }
        $this->repo->cancel($this->pdo, $doId, $userId, $reason);
        Audit::write($this->pdo, $requestId, $userId, 'special_order_do.cancel', 'special_order_do', (string) $doId, 'ok', null, null, ['reason' => $reason]);
        return $this->buildDoDto($doId);
    }

    /** POST /api/special-order-do/{id}/delivery-method — locked once any real shipment exists (task's own "After actual shipment/handover: delivery method becomes locked"). */
    public function changeDeliveryMethod(int $doId, int $expectedVersion, string $method, ?string $provider, ?string $courierName, ?string $externalRef, int $userId, ?string $requestId): array
    {
        if (!in_array($method, ['DRIVER_INTERNAL', 'EXTERNAL_COURIER'], true)) {
            throw new ApiException(400, 'INVALID_DELIVERY_METHOD', 'method must be DRIVER_INTERNAL or EXTERNAL_COURIER');
        }
        if ($method === 'EXTERNAL_COURIER' && !in_array($provider, ['grab', 'gosend', 'lalamove', 'other'], true)) {
            throw new ApiException(400, 'INVALID_COURIER_PROVIDER', 'provider must be one of grab/gosend/lalamove/other');
        }
        $do = $this->requireLockedDo($doId);
        $this->checkVersion($do, $expectedVersion);
        if ($do['status'] !== 'open') {
            throw new ApiException(400, 'DELIVERY_METHOD_LOCKED', 'Metode pengiriman terkunci setelah pengiriman aktual pertama dilakukan.');
        }
        $this->repo->changeDeliveryMethod($this->pdo, $doId, $method, $method === 'EXTERNAL_COURIER' ? $provider : null, $method === 'EXTERNAL_COURIER' ? $courierName : null, $method === 'EXTERNAL_COURIER' ? $externalRef : null);
        Audit::write($this->pdo, $requestId, $userId, 'special_order_do.delivery_method_changed', 'special_order_do', (string) $doId, 'ok', null, null, ['method' => $method, 'provider' => $provider]);
        return $this->buildDoDto($doId);
    }

    /**
     * POST /api/special-order-do/{id}/depart — DRIVER_INTERNAL's "Confirm
     * Departure / Berangkat". Only the claimant may depart.
     * @param array<int,array{doItemId:int,qty:float}>|null $items null = full remaining on every line (the simple one-tap default).
     */
    public function confirmDeparture(int $doId, ?array $items, int $driverUserId, ?string $requestId): array
    {
        $driver = $this->userRepo->findById($this->pdo, $driverUserId);
        $driverName = $driver !== null ? ((string) ($driver['full_name'] ?? '') !== '' ? (string) $driver['full_name'] : (string) $driver['username']) : null;
        return $this->dispatch($doId, 'DRIVER_INTERNAL', $items, $driverUserId, $driverName, null, $requestId, function (array $do) use ($driverUserId) {
            if ((int) ($do['claimed_by_user_id'] ?? 0) !== $driverUserId) {
                throw new ApiException(403, 'NOT_CLAIMANT', 'Anda belum mengambil (claim) DO ini');
            }
        });
    }

    /**
     * POST /api/special-order-do/{id}/courier-handover — EXTERNAL_COURIER's
     * "Barang Diserahkan ke Kurir". Admin/PPIC action, no claim required
     * (a courier is never claimed by an internal driver).
     * @param array<int,array{doItemId:int,qty:float}>|null $items null = full remaining on every line.
     */
    public function courierHandover(int $doId, ?array $items, ?string $handoverNote, int $userId, ?string $requestId): array
    {
        return $this->dispatch($doId, 'EXTERNAL_COURIER', $items, $userId, null, $handoverNote, $requestId, function () {});
    }

    /**
     * The one and only place that creates a real `shipment` row for a
     * source-specific dispatch (see this class's own docblock for the
     * CRITICAL DISPATCH RULE). Both confirmDeparture()/courierHandover()
     * fully share this — the only difference between them is which
     * delivery_method is required and who may act.
     * @param array<int,array{doItemId:int,qty:float}>|null $items
     */
    private function dispatch(int $doId, string $expectedMethod, ?array $items, int $userId, ?string $pengemudiName, ?string $handoverNote, ?string $requestId, callable $extraGuard): array
    {
        $do = $this->requireLockedDo($doId);
        if ($do['delivery_method'] !== $expectedMethod) {
            throw new ApiException(400, 'WRONG_DELIVERY_METHOD', "DO ini bukan untuk metode {$expectedMethod}");
        }
        if (!in_array($do['status'], ['open', 'partial'], true)) {
            throw new ApiException(400, 'INVALID_STATUS', 'DO ini sudah selesai dikirim atau dibatalkan');
        }
        $extraGuard($do);

        $doItems = $this->repo->findDoItems($this->pdo, $doId);
        $doItemsById = [];
        foreach ($doItems as $it) {
            $doItemsById[(int) $it['special_order_do_item_id']] = $it;
        }

        $requested = [];
        if ($items !== null) {
            foreach ($items as $line) {
                $doItemId = (int) ($line['doItemId'] ?? 0);
                $qty = (float) ($line['qty'] ?? 0);
                if (!isset($doItemsById[$doItemId])) {
                    throw new ApiException(400, 'UNKNOWN_DO_ITEM', "Item DO {$doItemId} bukan bagian dari DO ini");
                }
                if ($qty > 0.0001) {
                    $requested[$doItemId] = $qty;
                }
            }
        } else {
            foreach ($doItemsById as $doItemId => $it) {
                $remaining = max(0.0, (float) $it['planned_qty'] - (float) $it['shipped_qty']);
                if ($remaining > 0.0001) {
                    $requested[$doItemId] = $remaining;
                }
            }
        }
        if ($requested === []) {
            throw new ApiException(400, 'EMPTY_SHIPMENT', 'Tidak ada qty positif untuk dikirim pada DO ini');
        }

        // Fixed lock order across the underlying special_order_item rows —
        // same deadlock-avoidance discipline as create().
        $itemIdByDoItemId = [];
        foreach ($doItemsById as $doItemId => $it) {
            $itemIdByDoItemId[$doItemId] = (int) $it['special_order_item_id'];
        }
        $orderedDoItemIds = array_keys($requested);
        usort($orderedDoItemIds, fn ($a, $b) => $itemIdByDoItemId[$a] <=> $itemIdByDoItemId[$b]);

        $shipmentId = null;
        $createdLines = [];
        foreach ($orderedDoItemIds as $doItemId) {
            $qty = $requested[$doItemId];
            $doItem = $doItemsById[$doItemId];
            $specialOrderItemId = (int) $doItem['special_order_item_id'];

            // Re-validate under a fresh row lock on the underlying item —
            // never trust the pre-lock findDoItems() read for the actual
            // safety check (same discipline as Regular PO's own
            // ShipmentService::ship() re-reading remaining/available
            // inside the transaction).
            $item = $this->orderRepo->lockItemById($this->pdo, $specialOrderItemId);
            $doItemRemaining = max(0.0, (float) $doItem['planned_qty'] - $this->repo->sumShippedForDoItem($this->pdo, $doItemId));
            $fgHeadroom = max(0.0, (float) $item['fg_verified_qty'] - $this->orderRepo->sumShippedForItem($this->pdo, $specialOrderItemId));
            $maxShippable = min($doItemRemaining, $fgHeadroom);

            if ($qty > $maxShippable + 0.0001) {
                throw new ApiException(409, 'EXCEEDS_AVAILABLE',
                    "Item {$doItem['item_name_snapshot']}: qty {$qty} melebihi yang bisa dikirim sekarang ({$maxShippable} — sisa DO {$doItemRemaining}, FG tersedia {$fgHeadroom})");
            }

            if ($shipmentId === null) {
                $do = $this->repo->findDoById($this->pdo, $doId); // refresh for doc_no/store/factory display fields
                $shipmentId = $this->repo->createShipment(
                    $this->pdo, $doId, (int) $do['factory_id'], (int) $do['drop_store_id'], (string) $do['tanggal'],
                    (string) $do['doc_no'], $expectedMethod,
                    $expectedMethod === 'EXTERNAL_COURIER' ? $do['courier_provider'] : null,
                    $expectedMethod === 'EXTERNAL_COURIER' ? $do['courier_name'] : null,
                    $expectedMethod === 'EXTERNAL_COURIER' ? $do['external_order_reference'] : null,
                    $handoverNote,
                    $pengemudiName ?? ($do['courier_name'] ?? null),
                    $userId
                );
            }
            $this->repo->insertShipmentDoLine($this->pdo, $shipmentId, $doItemId, $qty);
            $createdLines[] = ['doItemId' => $doItemId, 'itemName' => $doItem['item_name_snapshot'], 'qty' => $qty];
        }

        if ($shipmentId === null) {
            throw new ApiException(400, 'EMPTY_SHIPMENT', 'Tidak ada qty positif untuk dikirim pada DO ini');
        }

        $newStatus = $this->repo->refreshStatus($this->pdo, $doId);

        // Automatic Bakery email / Digital Surat Jalan (task's own "Final
        // Pre-Live Rework" Section E) — the outbox ROW is created here,
        // INSIDE this same transaction, mirroring Dispatch\DepartureService
        // ::confirmDeparture()'s own exact pattern: a row insert can never
        // fail the way an SMTP handshake can, so it's safe here; the real
        // send attempt happens strictly AFTER commit, in
        // Controllers\SpecialOrderDoController::depart()/courierHandover().
        // storeId = the DO's own drop_store_id — always a real store row
        // (migration 0012 made drop_store_id NOT NULL on every
        // special_order_do), so ShipmentEmailService::createOutboxForShipment()
        // needs NO changes to work here: it already only ever looks up
        // store.email by storeId, nothing Regular-DO-specific.
        $outbox = $this->emailService->createOutboxForShipment($this->pdo, $shipmentId, (int) $do['drop_store_id']);

        Audit::write(
            $this->pdo, $requestId, $userId,
            $expectedMethod === 'DRIVER_INTERNAL' ? 'special_order_do.depart' : 'special_order_do.courier_handover',
            'special_order_do', (string) $doId, 'ok', null, null,
            ['shipmentId' => $shipmentId, 'items' => $createdLines, 'newStatus' => $newStatus]
        );

        $dto = $this->buildDoDto($doId);
        $dto['shipmentId'] = $shipmentId;
        $dto['emailOutboxId'] = $outbox['outboxId'];
        return $dto;
    }

    private function requireLockedDo(int $doId): array
    {
        $do = $this->repo->lockDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'DO tidak ditemukan');
        }
        return $do;
    }

    private function checkVersion(array $do, int $expectedVersion): void
    {
        if ((int) $do['version'] !== $expectedVersion) {
            throw new ApiException(409, 'VERSION_CONFLICT', 'Dokumen sudah diubah oleh pengguna lain', ['currentVersion' => (int) $do['version']]);
        }
    }

    private function summaryDto(array $r): array
    {
        // Normalized downstream source identity (task's own "Final Blocker
        // Fix" — CS/Sales Executive/Konsumen Langsung/Umum must never
        // collapse into the generic "Pesanan Non-Toko" the way the
        // earlier pass's naive `source_type === 'toko_khusus' ? ... :
        // ...` ternary did). sourceType/sourceLabel stay RAW here
        // (backward-compat: every existing caller — driver.js's
        // sourceBadgeClass(), delivery-order-khusus-non-toko.php's
        // "toko_khusus ? auto-store : pick-a-Bakery" branch — keys off
        // the raw 'toko_khusus'/'non_toko' value, never the normalized
        // one) — normalizedSourceType/nonStoreSource are ADDITIVE fields;
        // every UI consumer this task asks to fix now reads sourceLabel
        // (fixed below to the real, granular label) or normalizedSourceType
        // directly, never re-deriving it from sourceType+text.
        $normalizedSourceType = NormalizedSourceType::fromSpecialOrder((string) $r['source_type'], $r['non_store_source'] ?? null);
        return [
            'doId' => (int) $r['special_order_do_id'],
            'docNo' => $r['doc_no'],
            'tanggal' => $r['tanggal'],
            'orderId' => (int) $r['special_order_id'],
            'orderNo' => $r['order_no'],
            'sourceType' => $r['source_type'],
            'nonStoreSource' => $r['non_store_source'] ?? null,
            'normalizedSourceType' => $normalizedSourceType,
            'sourceLabel' => NormalizedSourceType::label($normalizedSourceType),
            'factoryId' => (int) $r['factory_id'],
            'factoryName' => $r['factory_name'],
            'dropStoreId' => (int) $r['drop_store_id'],
            'dropStoreName' => $r['drop_store_name'],
            'deliveryMethod' => $r['delivery_method'],
            'courierProvider' => $r['courier_provider'],
            'courierName' => $r['courier_name'],
            'status' => $r['status'],
            'claimedByUserId' => $r['claimed_by_user_id'] !== null ? (int) $r['claimed_by_user_id'] : null,
            'claimedByName' => $r['claimed_by_name'] ?? null,
        ];
    }

    private function buildDoDto(int $doId): array
    {
        $do = $this->repo->findDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'DO tidak ditemukan');
        }
        $items = $this->repo->findDoItems($this->pdo, $doId);
        $dto = $this->summaryDto($do);
        $dto += [
            'externalOrderReference' => $do['external_order_reference'],
            'customerName' => $do['customer_name'],
            'customerContact' => $do['customer_contact'],
            'deliveryAddress' => $do['delivery_address'],
            'version' => (int) $do['version'],
            'createdByName' => $do['created_by_name'],
            'createdAt' => $do['created_at'],
            'cancelledAt' => $do['cancelled_at'],
            'cancelReason' => $do['cancel_reason'],
            'items' => array_map(function ($it) {
                $planned = (float) $it['planned_qty'];
                $shipped = (float) $it['shipped_qty'];
                return [
                    'doItemId' => (int) $it['special_order_do_item_id'],
                    'itemId' => (int) $it['special_order_item_id'],
                    'itemType' => $it['item_type'],
                    'itemName' => $it['item_name_snapshot'],
                    'divisionName' => $it['division_name'],
                    'factoryName' => $it['factory_name'],
                    'plannedQty' => $planned,
                    'shippedQty' => $shipped,
                    'remainingQty' => max(0.0, $planned - $shipped),
                ];
            }, $items),
        ];
        return $dto;
    }
}
