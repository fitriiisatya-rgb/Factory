<?php

declare(strict_types=1);

namespace Amor\Api\Dispatch;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\Fg\FgRepository;
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

    public function __construct(private PDO $pdo)
    {
        $this->repo = new DispatchRepository();
        $this->doRepo = new DoRepository();
        $this->fg = new FgRepository();
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

        $out = [];
        foreach ($stops as $s) {
            $storeId = (int) $s['store_id'];
            $storeClaims = array_values(array_filter($claims, static fn ($c) => (int) $c['store_id'] === $storeId));
            $out[] = [
                'stopId' => (int) $s['driver_route_stop_id'],
                'storeId' => $storeId,
                'storeName' => $s['store_name'],
                'sequence' => (int) $s['sequence'],
                'productCount' => count($storeClaims),
                'totalQty' => array_sum(array_map(static fn ($c) => (float) $c['active_qty'], $storeClaims)),
                'hasActiveClaims' => isset($activeByStore[$storeId]),
                'departureStatus' => isset($activeByStore[$storeId]) ? 'belum_berangkat' : (isset($departedStores[$storeId]) ? 'sudah_berangkat' : 'belum_berangkat'),
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
        ];
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
