<?php

declare(strict_types=1);

namespace Amor\Api\Dispatch;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\Fg\FgRepository;
use Amor\Api\Users\UserRepository;
use PDO;

/**
 * Dispatch Pool + Driver Claim + Route (Phase 5.5, Parts A/B/C/E). Never
 * writes to stock_ledger, never creates a shipment, never changes a
 * delivery_order's planned_qty/status/version — those stay exactly Phase
 * 5's own job. Claiming/releasing/routing is pure reservation bookkeeping
 * on top of the read-only pool (see DispatchRepository's own docblock for
 * why no separate "dispatch_task" table exists).
 *
 * Concurrency: every claim/release locks the PARENT delivery_order row
 * FOR UPDATE first (DoRepository::lockDoById — the exact same row lock
 * ShipmentService::ship() already uses), then re-reads the live
 * shipped+active-claimed sums under that lock before accepting or
 * rejecting the request. Two drivers racing for the last unit of the same
 * DO serialize on that lock; the second sees the first's freshly-inserted
 * claim row and is rejected with a friendly, re-fetchable error — never a
 * silent double-reservation.
 */
final class DispatchService
{
    private DispatchRepository $repo;
    private DoRepository $doRepo;
    private FgRepository $fg;
    private ReceiptRepository $receiptRepo;
    private UserRepository $userRepo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new DispatchRepository();
        $this->doRepo = new DoRepository();
        $this->fg = new FgRepository();
        $this->receiptRepo = new ReceiptRepository();
        $this->userRepo = new UserRepository();
    }

    /**
     * GET /api/dispatch/available — the "Tersedia" pool. Read-only; never
     * reserves anything. suggestedGroup is a DISPLAY-ONLY heuristic (division
     * name containing "pastry" -> PASTRY, else MAIN) used purely to let the
     * driver filter the list the same way the existing Pengiriman screen's
     * MAIN/PASTRY/OTHER tabs already work — it is NOT persisted anywhere and
     * NOT the value that ends up on the real shipment; the driver explicitly
     * picks the actual shipment_group at Confirm Departure, exactly like the
     * existing manual Pengiriman flow already requires today.
     */
    public function listAvailable(string $tanggal, ?int $factoryId, ?int $storeId, ?string $groupFilter): array
    {
        $rows = $this->repo->listAvailable($this->pdo, $tanggal, $factoryId, $storeId);
        $out = [];
        foreach ($rows as $doItemId => $r) {
            $remaining = (float) $r['remaining_claimable_qty'];
            if ($remaining <= 0.0001) {
                continue;
            }
            $suggestedGroup = self::suggestGroup((string) ($r['division_name'] ?? ''));
            if ($groupFilter !== null && $groupFilter !== 'ALL' && $suggestedGroup !== $groupFilter) {
                continue;
            }
            $out[] = [
                'doItemId' => $doItemId,
                'doId' => (int) $r['delivery_order_id'],
                'docNo' => $r['doc_no'],
                'tanggal' => $r['tanggal'],
                'storeId' => (int) $r['store_id'],
                'storeName' => $r['store_name'],
                'productId' => (int) $r['product_id'],
                'productName' => $r['product_name'],
                'divisionName' => $r['division_name'],
                'suggestedGroup' => $suggestedGroup,
                'plannedQty' => (float) $r['planned_qty'],
                'shippedQty' => (float) $r['shipped_qty'],
                'activeClaimedQty' => (float) $r['active_claimed_qty'],
                'availableToClaim' => $remaining,
            ];
        }
        return [
            'tanggal' => $tanggal,
            'items' => $out,
            'summary' => ['count' => count($out), 'totalAvailableQty' => array_sum(array_column($out, 'availableToClaim'))],
        ];
    }

    /**
     * POST /api/dispatch/claim — reserves qty against one or more DO items
     * for $driverUserId. Auto-adds each store touched to the driver's route
     * for that date (task's own "claimed tasks enter Pengiriman Saya" /
     * route auto-population, per the reference mockup).
     *
     * @param array<int,array{doItemId:int,qty:float}> $lines
     */
    public function claim(array $lines, int $driverUserId, ?string $requestId): array
    {
        if ($lines === []) {
            throw new ApiException(400, 'EMPTY_CLAIM', 'Pilih minimal satu pengiriman untuk diambil');
        }

        $details = [];
        foreach ($lines as $line) {
            $doItemId = (int) ($line['doItemId'] ?? 0);
            if (isset($details[$doItemId])) {
                continue;
            }
            $detail = $this->repo->findDoItemDetail($this->pdo, $doItemId);
            if ($detail === null) {
                throw new ApiException(404, 'NOT_FOUND', "Item DO {$doItemId} tidak ditemukan");
            }
            $details[$doItemId] = $detail;
        }

        // Lock every distinct parent DO row, smallest id first — a fixed
        // lock order across every caller prevents a lock-order deadlock
        // when two requests each touch two of the same DOs in opposite order.
        $doIds = array_unique(array_map(static fn ($d) => (int) $d['delivery_order_id'], $details));
        sort($doIds);
        foreach ($doIds as $doId) {
            $do = $this->doRepo->lockDoById($this->pdo, $doId);
            if ($do === null || !in_array($do['status'], ['draft', 'preprinted', 'ready'], true)) {
                throw new ApiException(409, 'INVALID_STATUS', "DO {$doId} sudah tidak dapat diklaim (status saat ini: " . ($do['status'] ?? 'tidak ditemukan') . ')');
            }
        }

        $shippedCache = [];
        $created = [];
        foreach ($lines as $line) {
            $doItemId = (int) ($line['doItemId'] ?? 0);
            $qty = (float) ($line['qty'] ?? 0);
            $detail = $details[$doItemId];
            $doId = (int) $detail['delivery_order_id'];

            if ($qty <= 0.0001) {
                throw new ApiException(400, 'NEGATIVE_QTY', "Item DO {$doItemId}: jumlah klaim harus lebih dari 0");
            }
            if (!isset($shippedCache[$doId])) {
                $shippedCache[$doId] = $this->doRepo->shippedQtyByProduct($this->pdo, $doId);
            }
            $shipped = $shippedCache[$doId][(int) $detail['product_id']] ?? 0.0;
            $activeClaimed = $this->repo->activeClaimedQtyForItem($this->pdo, $doItemId);
            $planned = (float) $detail['planned_qty'];
            $remaining = max(0.0, $planned - $shipped - $activeClaimed);

            if ($qty > $remaining + 0.0001) {
                throw new ApiException(409, 'DISPATCH_ALREADY_CLAIMED',
                    'Jumlah pengiriman sudah diambil driver lain. Muat ulang daftar.',
                    ['doItemId' => $doItemId, 'remaining' => $remaining]
                );
            }

            $claimId = $this->repo->insertClaim($this->pdo, $doId, $doItemId, (int) $detail['product_id'], (int) $detail['store_id'], $driverUserId, $qty);
            $routeId = $this->repo->findOrCreateRoute($this->pdo, $driverUserId, (string) $detail['tanggal']);
            $this->repo->ensureStop($this->pdo, $routeId, (int) $detail['store_id']);

            Audit::write(
                $this->pdo, $requestId, $driverUserId, 'dispatch.claimed', 'dispatch_claim', (string) $claimId,
                'ok', null, null, ['doItemId' => $doItemId, 'productId' => (int) $detail['product_id'], 'qty' => $qty]
            );

            $created[] = [
                'claimId' => $claimId,
                'doItemId' => $doItemId,
                'doId' => $doId,
                'storeId' => (int) $detail['store_id'],
                'storeName' => $detail['store_name'],
                'productId' => (int) $detail['product_id'],
                'productName' => $detail['product_name'],
                'claimedQty' => $qty,
            ];
        }

        return ['claims' => $created];
    }

    /** POST /api/dispatch/claims/{id}/release — driver gives up an active claim without departing. */
    public function release(int $claimId, int $driverUserId, bool $isAdmin, ?string $requestId): array
    {
        $claim = $this->repo->findClaim($this->pdo, $claimId);
        if ($claim === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Klaim tidak ditemukan');
        }
        $this->doRepo->lockDoById($this->pdo, (int) $claim['delivery_order_id']);
        $claim = $this->repo->lockClaim($this->pdo, $claimId);
        if ($claim['status'] !== 'active') {
            throw new ApiException(409, 'INVALID_CLAIM_STATUS', 'Klaim ini sudah tidak aktif');
        }
        if (!$isAdmin && (int) $claim['driver_user_id'] !== $driverUserId) {
            throw new ApiException(403, 'FORBIDDEN', 'Anda hanya dapat melepas klaim milik Anda sendiri');
        }

        $this->repo->releaseClaim($this->pdo, $claimId);
        Audit::write($this->pdo, $requestId, $driverUserId, 'dispatch.released', 'dispatch_claim', (string) $claimId, 'ok', null, null, ['releasedQty' => (float) $claim['active_qty']]);

        return ['claimId' => $claimId, 'status' => 'released', 'releasedQty' => (float) $claim['active_qty']];
    }

    /** GET /api/dispatch/mine — "Pengiriman Saya", grouped by store. */
    public function myClaims(int $driverUserId, ?string $tanggal): array
    {
        $rows = $this->repo->findActiveClaimsForDriver($this->pdo, $driverUserId, $tanggal);
        $byStore = [];
        foreach ($rows as $r) {
            $storeId = (int) $r['store_id'];
            $byStore[$storeId] ??= [
                'storeId' => $storeId,
                'storeName' => $r['store_name'],
                'doId' => (int) $r['delivery_order_id'],
                'docNo' => $r['doc_no'],
                'tanggal' => $r['tanggal'],
                'doStatus' => $r['do_status'],
                'items' => [],
            ];
            $byStore[$storeId]['items'][] = [
                'claimId' => (int) $r['dispatch_claim_id'],
                'doItemId' => (int) $r['delivery_order_item_id'],
                'productId' => (int) $r['product_id'],
                'productName' => $r['product_name'],
                'divisionName' => $r['division_name'],
                'suggestedGroup' => self::suggestGroup((string) ($r['division_name'] ?? '')),
                'claimedQty' => (float) $r['active_qty'],
            ];
        }
        return ['tanggal' => $tanggal, 'stores' => array_values($byStore)];
    }

    /** GET /api/dispatch/route — "Rute Saya". */
    /**
     * GET /api/dispatch/route — real-UAT fix: a stop's productCount/totalQty
     * used to be computed ONLY from this driver's dispatch_claim rows. That
     * is correct BEFORE departure (an active claim IS the reservation), but
     * confirmDeparture() always resolves every touched claim to a TERMINAL
     * 'departed' status with active_qty reset to 0 (DispatchRepository::
     * resolveClaimAsDeparted — by design, so a resolved claim can never be
     * double-counted or re-released). Once every claim for a stop reaches
     * that terminal state, summing "active" claims for that stop reads
     * 0 products / 0 pcs — even though the real shipment(s) just created
     * hold the true quantities. Fix: a DEPARTED stop's summary is now
     * sourced from the actual shipment_item rows (findDepartedTotalsForDriver),
     * which reflects the REAL shipped qty (e.g. claimed 5, shipped 3 shows
     * 3 — the dispatch truth after departure, never the original claim).
     * A stop with no departure yet keeps using live active-claim totals,
     * unchanged from before.
     */
    public function myRoute(int $driverUserId, string $tanggal): array
    {
        $routeId = $this->repo->findRouteId($this->pdo, $driverUserId, $tanggal);
        if ($routeId === null) {
            return ['tanggal' => $tanggal, 'stops' => []];
        }
        $stops = $this->repo->findStops($this->pdo, $routeId);
        $claims = $this->repo->findActiveClaimsForDriver($this->pdo, $driverUserId, $tanggal);
        $activeByStore = [];
        foreach ($claims as $c) {
            $activeByStore[(int) $c['store_id']] = true;
        }
        $history = $this->repo->findShipmentHistoryForDriver($this->pdo, $driverUserId, 200);
        $departedStores = [];
        foreach ($history as $h) {
            if ((string) $h['tanggal'] === $tanggal) {
                $departedStores[(int) $h['store_id']] = true;
            }
        }
        $departedTotals = $this->repo->findDepartedTotalsForDriver($this->pdo, $driverUserId, $tanggal);

        $out = [];
        foreach ($stops as $s) {
            $storeId = (int) $s['store_id'];
            $hasActive = isset($activeByStore[$storeId]);
            $hasDeparted = isset($departedStores[$storeId]);
            if ($hasActive) {
                // Not (fully) departed yet — active-claim totals are still the live truth.
                $storeClaims = array_values(array_filter($claims, static fn ($c) => (int) $c['store_id'] === $storeId));
                $productCount = count($storeClaims);
                $totalQty = array_sum(array_map(static fn ($c) => (float) $c['active_qty'], $storeClaims));
            } elseif ($hasDeparted) {
                // Fully departed — real shipment_item totals, never the now-zeroed claims.
                $productCount = $departedTotals[$storeId]['productCount'] ?? 0;
                $totalQty = $departedTotals[$storeId]['totalQty'] ?? 0.0;
            } else {
                $productCount = 0;
                $totalQty = 0.0;
            }
            $out[] = [
                'stopId' => (int) $s['driver_route_stop_id'],
                'storeId' => $storeId,
                'storeName' => $s['store_name'],
                'sequence' => (int) $s['sequence'],
                'productCount' => $productCount,
                'totalQty' => $totalQty,
                'hasActiveClaims' => $hasActive,
                'departureStatus' => $hasActive ? 'belum_berangkat' : ($hasDeparted ? 'sudah_berangkat' : 'belum_berangkat'),
                // Real-UAT navigation fix: which real shipment(s) a departed
                // stop resolved into, so the route card can link straight to
                // Detail Pengiriman (or a chooser when there's more than
                // one) instead of "Konfirmasi Berangkat", which has nothing
                // left to show once every claim is resolved. Always empty
                // for a stop that hasn't (fully) departed.
                'shipmentIds' => $hasDeparted ? ($departedTotals[$storeId]['shipmentIds'] ?? []) : [],
            ];
        }
        return ['tanggal' => $tanggal, 'stops' => $out];
    }

    /** POST /api/dispatch/route/reorder */
    public function reorderRoute(int $driverUserId, string $tanggal, array $storeIdsInOrder, ?string $requestId): array
    {
        $routeId = $this->repo->findRouteId($this->pdo, $driverUserId, $tanggal);
        if ($routeId === null) {
            throw new ApiException(404, 'ROUTE_NOT_FOUND', 'Rute untuk tanggal ini belum ada');
        }
        $stops = $this->repo->findStops($this->pdo, $routeId);
        $existing = array_map(static fn ($s) => (int) $s['store_id'], $stops);
        sort($existing);
        $incoming = array_map('intval', $storeIdsInOrder);
        $sortedIncoming = $incoming;
        sort($sortedIncoming);
        if ($existing !== $sortedIncoming) {
            throw new ApiException(400, 'INVALID_STOP_SET', 'Daftar toko tidak cocok dengan rute saat ini — muat ulang halaman');
        }
        $this->repo->reorderStops($this->pdo, $routeId, $incoming);
        Audit::write($this->pdo, $requestId, $driverUserId, 'dispatch.route_reordered', 'driver_route', (string) $routeId, 'ok', null, null, ['order' => $incoming]);
        return $this->myRoute($driverUserId, $tanggal);
    }

    /**
     * GET /api/dispatch/route/stops/{storeId} — the "Konfirmasi Berangkat"
     * detail screen for one stop: this driver's active claims for that
     * store's current DO, each with live DO-remaining and FG-available so
     * the driver can adjust actual qty downward before confirming.
     */
    public function stopDetail(int $driverUserId, string $tanggal, int $storeId): array
    {
        $do = $this->doRepo->findAnyDoByDateStore($this->pdo, $tanggal, $storeId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'DO untuk toko/tanggal ini tidak ditemukan');
        }
        $store = $this->doRepo->findStore($this->pdo, $storeId);
        $doId = (int) $do['delivery_order_id'];
        $claims = $this->repo->findActiveClaimsForDriverAndDo($this->pdo, $driverUserId, $doId);
        $shippedByProduct = $this->doRepo->shippedQtyByProduct($this->pdo, $doId);
        $doItems = $this->doRepo->findDoItems($this->pdo, $doId);

        $items = [];
        foreach ($claims as $c) {
            $productId = (int) $c['product_id'];
            $doItem = $doItems[$productId] ?? null;
            $planned = $doItem !== null ? (float) $doItem['planned_qty'] : 0.0;
            $shipped = $shippedByProduct[$productId] ?? 0.0;
            $doRemaining = max(0.0, $planned - $shipped);
            $factoryId = $c['factory_id'] !== null ? (int) $c['factory_id'] : null;
            $fgAvailable = $factoryId !== null ? $this->liveFgAvailable($productId, $factoryId) : 0.0;
            $claimedQty = (float) $c['active_qty'];
            $items[] = [
                'claimId' => (int) $c['dispatch_claim_id'],
                'doItemId' => (int) $c['delivery_order_item_id'],
                'productId' => $productId,
                'productName' => $c['product_name'],
                'suggestedGroup' => self::suggestGroup((string) ($c['division_name'] ?? '')),
                'claimedQty' => $claimedQty,
                'doRemaining' => $doRemaining,
                'fgAvailable' => $fgAvailable,
                'defaultActualQty' => max(0.0, min($claimedQty, $doRemaining, $fgAvailable)),
            ];
        }

        // Real-UAT UX fix: if this driver has no active claims left for this
        // stop (the normal case ONCE the route card's own navigation fix —
        // see myRoute()'s docblock — sends them here at all is only via a
        // stale/bookmarked URL), tell the caller which real shipment(s)
        // already exist for this store/date so the UI can offer "Lihat
        // Detail Pengiriman" instead of a bare "no active claim" dead end.
        // Never computed FROM $items — this is a read of shipment history,
        // not a derivation of claim state.
        $shipmentRows = $items === []
            ? $this->repo->findShipmentsForDriverStoreDate($this->pdo, $driverUserId, $storeId, $tanggal)
            : [];

        return [
            'doId' => $doId,
            'docNo' => $do['doc_no'],
            'doVersion' => (int) $do['version'],
            'storeId' => $storeId,
            'storeName' => $store['canonical_name'] ?? null,
            'tanggal' => $tanggal,
            'doStatus' => $do['status'],
            'items' => $items,
            'summary' => ['productCount' => count($items), 'totalClaimedQty' => array_sum(array_column($items, 'claimedQty'))],
            'shipments' => array_map(static fn ($r) => ['shipmentId' => (int) $r['shipment_id']], $shipmentRows),
        ];
    }

    /**
     * GET /api/dispatch/route/stops/{storeId}/shipments — real-UAT
     * navigation fix: every shipment THIS driver made for one store/date,
     * so a departed route-stop card can open the real Detail Pengiriman
     * directly (exactly one shipment) or show a chooser (more than one —
     * e.g. MAIN + PASTRY under the same DO, see DPT-19) instead of the
     * "Konfirmasi Berangkat" screen, which correctly has nothing left once
     * every claim has resolved (see myRoute()'s own docblock for the full
     * background). Driver-scoped by construction — the repository query
     * filters on shipped_by, so this can never return another driver's
     * shipment.
     */
    public function stopShipments(int $driverUserId, string $tanggal, int $storeId): array
    {
        $store = $this->doRepo->findStore($this->pdo, $storeId);
        $rows = $this->repo->findShipmentsForDriverStoreDate($this->pdo, $driverUserId, $storeId, $tanggal);
        return [
            'storeId' => $storeId,
            'storeName' => $store['canonical_name'] ?? null,
            'tanggal' => $tanggal,
            'shipments' => array_map(static fn ($r) => [
                'shipmentId' => (int) $r['shipment_id'],
                'shipmentGroup' => $r['shipment_group'],
                'shippedAt' => $r['shipped_at'] ?? $r['created_at'],
                'productCount' => (int) $r['product_count'],
                'totalQty' => (float) $r['total_qty'],
            ], $rows),
        ];
    }

    /**
     * GET /api/dispatch/shipments/{id} — read-only Driver Shipment Detail /
     * shipment tracing (real-UAT ask: "Driver needs shipment tracing" from
     * a clickable Riwayat card). Pure read: never writes shipment,
     * shipment_item, dispatch_claim, or shipment_receipt.
     *
     * Authorization is mandatory and scoped: a non-admin caller may only
     * open a shipment THEY shipped (shipment.shipped_by === requestingUserId).
     * Anyone else — including a driver who simply guesses another
     * shipment_id — gets 403 FORBIDDEN, never partial data (task's own
     * "Do not leak store data / item quantities / receipt detail").
     * ADMIN keeps the broader access it already has through the existing
     * admin receipt-verification screens.
     */
    public function shipmentDetail(int $shipmentId, int $requestingUserId, bool $isAdmin): array
    {
        $shipment = $this->doRepo->findShipmentById($this->pdo, $shipmentId);
        if ($shipment === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Shipment tidak ditemukan');
        }
        $shippedBy = $shipment['shipped_by'] !== null ? (int) $shipment['shipped_by'] : null;
        if (!$isAdmin && $shippedBy !== $requestingUserId) {
            throw new ApiException(403, 'FORBIDDEN', 'Anda tidak memiliki akses ke pengiriman ini');
        }

        // Product table: shipment_item's OWN qty is the dispatch truth after
        // departure (task's own "Do NOT use original claim quantities if
        // different") — never dispatch_claim.claimed_qty/active_qty.
        $items = $this->doRepo->findShipmentItems($this->pdo, $shipmentId);
        $totalQty = array_sum(array_map(static fn ($i) => (float) $i['qty'], $items));

        $driver = $shippedBy !== null ? $this->userRepo->findById($this->pdo, $shippedBy) : null;

        // Timeline "Driver Claim" event — earliest claim actually resolved
        // into this shipment, if any is still traceable. Never invented
        // when absent (task's own "Use only data that actually exists").
        $claims = $this->repo->findClaimsForShipment($this->pdo, $shipmentId);
        $firstClaim = $claims[0] ?? null;

        $receipt = $this->receiptRepo->findReceiptForShipment($this->pdo, $shipmentId);
        $receiptDto = null;
        if ($receipt !== null) {
            $receiptItems = $this->receiptRepo->findReceiptItems($this->pdo, (int) $receipt['shipment_receipt_id']);
            $verifier = $receipt['verified_by'] !== null ? $this->userRepo->findById($this->pdo, (int) $receipt['verified_by']) : null;
            $receiptDto = [
                'status' => $receipt['status'],
                'receiverName' => $receipt['receiver_name'],
                'note' => $receipt['note'],
                'confirmedAt' => $receipt['confirmed_at'],
                'verifiedAt' => $receipt['verified_at'],
                'verifiedByName' => $verifier !== null ? self::displayName($verifier) : null,
                'items' => array_map(static fn ($ri) => [
                    'productId' => (int) $ri['product_id'],
                    'productName' => $ri['product_name'],
                    'shippedQty' => (float) $ri['shipped_qty'],
                    'receivedGoodQty' => (float) $ri['received_good_qty'],
                    'rejectQty' => (float) $ri['reject_qty'],
                    'shortageQty' => (float) $ri['shortage_qty'],
                    'reason' => $ri['reason'],
                ], $receiptItems),
            ];
        }

        return [
            'shipmentId' => (int) $shipment['shipment_id'],
            // Real-UAT Surat Jalan print ask: the print page needs the
            // owning DO's real id (to reuse the EXISTING DO-level receipt
            // QR token — "1 DO = 1 receipt token" stays unchanged, never a
            // new per-shipment token) — additive field, doesn't affect any
            // existing consumer of this DTO.
            'doId' => (int) $shipment['delivery_order_id'],
            'storeId' => (int) $shipment['store_id'],
            'storeName' => $shipment['store_name'],
            'docNo' => $shipment['doc_no'],
            'doTanggal' => $shipment['do_tanggal'],
            'tanggal' => $shipment['tanggal'],
            'shipmentGroup' => $shipment['shipment_group'],
            'factoryName' => $shipment['factory_name'],
            'status' => $shipment['status'],
            'shippedAt' => $shipment['shipped_at'] ?? $shipment['created_at'],
            'driverUserId' => $shippedBy,
            'driverName' => $driver !== null ? self::displayName($driver) : null,
            'items' => array_map(static fn ($i) => [
                'productId' => (int) $i['product_id'],
                'productName' => $i['product_name'],
                'divisionName' => $i['division_name'] ?? null,
                'qty' => (float) $i['qty'],
            ], $items),
            'summary' => ['productCount' => count($items), 'totalQty' => $totalQty],
            'claim' => $firstClaim !== null ? [
                'driverName' => $firstClaim['driver_full_name'] !== null && $firstClaim['driver_full_name'] !== ''
                    ? $firstClaim['driver_full_name'] : $firstClaim['driver_username'],
                'claimedAt' => $firstClaim['created_at'],
            ] : null,
            'receipt' => $receiptDto,
        ];
    }

    private static function displayName(array $user): string
    {
        $fullName = (string) ($user['full_name'] ?? '');
        return $fullName !== '' ? $fullName : (string) ($user['username'] ?? '');
    }

    /** Display-only heuristic — see listAvailable()'s own docblock. Never authoritative. */
    public static function suggestGroup(string $divisionName): string
    {
        return stripos($divisionName, 'pastry') !== false ? 'PASTRY' : 'MAIN';
    }

    private function liveFgAvailable(int $productId, int $factoryId): float
    {
        $factory = $this->doRepo->findFactory($this->pdo, $factoryId);
        if ($factory === null) {
            return 0.0;
        }
        $locationId = $this->fg->findOrCreateLocationForFactory($this->pdo, $factoryId, $factory['name']);
        $balance = $this->fg->findBalance($this->pdo, $productId, $locationId);
        return $balance !== null ? (float) $balance['qty_on_hand'] : $this->fg->sumLedger($this->pdo, $productId, $locationId);
    }
}
