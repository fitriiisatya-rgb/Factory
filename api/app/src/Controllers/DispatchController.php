<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Dispatch\DepartureService;
use Amor\Api\Dispatch\DispatchService;
use Amor\Api\Idempotency;
use Amor\Api\Mail\ShipmentEmailService;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

/**
 * JSON API for the Phase 5.5 Driver portal (Dispatch Pool / Claim / Route /
 * Confirm Departure). ADMIN is included in every role check purely so an
 * office user can preview/test the driver flow — the intended day-to-day
 * user is DRIVER.
 */
final class DispatchController
{
    private const DRIVER_ROLES = ['DRIVER', 'ADMIN'];

    public static function available(Request $request): void
    {
        Auth::requireRole(...self::DRIVER_ROLES);
        $tanggal = self::requireDate($request->query('tanggal'));
        $factoryId = $request->query('factoryId') !== null ? (int) $request->query('factoryId') : null;
        $storeId = $request->query('storeId') !== null ? (int) $request->query('storeId') : null;
        $group = $request->query('group');
        $service = new DispatchService(Database::pdo());
        Response::json($service->listAvailable($tanggal, $factoryId, $storeId, $group));
    }

    public static function claim(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $lines = (array) $request->input('lines', []);

        Idempotency::handle($request, 'POST /api/dispatch/claim', function (PDO $pdo) use ($userId, $lines, $request) {
            $service = new DispatchService($pdo);
            $dto = $service->claim($lines, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'dispatch_claim_batch', 'recordKey' => (string) $userId];
        });
    }

    public static function release(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $isAdmin = in_array('ADMIN', Auth::currentRoles(), true);
        $claimId = (int) $request->routeParams['id'];

        Idempotency::handle($request, 'POST /api/dispatch/claims/{id}/release', function (PDO $pdo) use ($userId, $isAdmin, $claimId, $request) {
            $service = new DispatchService($pdo);
            $dto = $service->release($claimId, $userId, $isAdmin, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'dispatch_claim', 'recordKey' => (string) $claimId];
        });
    }

    public static function mine(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $tanggal = $request->query('tanggal');
        $service = new DispatchService(Database::pdo());
        Response::json($service->myClaims($userId, $tanggal));
    }

    public static function route(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $tanggal = self::requireDate($request->query('tanggal'));
        $service = new DispatchService(Database::pdo());
        Response::json($service->myRoute($userId, $tanggal));
    }

    public static function reorderRoute(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $tanggal = self::requireDate((string) $request->input('tanggal'));
        $storeIds = array_map('intval', (array) $request->input('storeIds', []));

        Idempotency::handle($request, 'POST /api/dispatch/route/reorder', function (PDO $pdo) use ($userId, $tanggal, $storeIds, $request) {
            $service = new DispatchService($pdo);
            $dto = $service->reorderRoute($userId, $tanggal, $storeIds, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'driver_route', 'recordKey' => "{$userId}|{$tanggal}"];
        });
    }

    public static function stopDetail(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $storeId = (int) $request->routeParams['storeId'];
        $tanggal = self::requireDate($request->query('tanggal'));
        $service = new DispatchService(Database::pdo());
        Response::json($service->stopDetail($userId, $tanggal, $storeId));
    }

    /**
     * GET /api/dispatch/route/stops/{storeId}/shipments — real-UAT
     * navigation fix: lists this driver's own already-departed shipment(s)
     * for one store/date, so a departed route-stop card can open the real
     * shipment (or a chooser when a stop split into more than one — e.g.
     * MAIN + PASTRY, see DPT-19) instead of the "Konfirmasi Berangkat"
     * screen, which correctly has nothing to show once every claim has
     * resolved. See DispatchService::stopShipments()'s own docblock.
     */
    public static function stopShipments(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $storeId = (int) $request->routeParams['storeId'];
        $tanggal = self::requireDate($request->query('tanggal'));
        $service = new DispatchService(Database::pdo());
        Response::json($service->stopShipments($userId, $tanggal, $storeId));
    }

    public static function departures(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $doId = self::requireInt($request->input('doId'), 'doId');
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');
        $shipmentGroup = (string) $request->input('shipmentGroup', 'MAIN');
        $items = (array) $request->input('items', []);

        // Captured ONLY if $work below actually runs (a fresh departure) —
        // on an exact Idempotency-Key replay, Idempotency::handle() short-
        // circuits BEFORE $work ever runs (see its own docblock), so
        // $createdShipments stays empty and no duplicate email attempt
        // ever fires for a replayed request.
        $createdShipments = [];
        Idempotency::handle($request, 'POST /api/dispatch/departures', function (PDO $pdo) use ($userId, $doId, $expectedVersion, $shipmentGroup, $items, $request, &$createdShipments) {
            $service = new DepartureService($pdo);
            $dto = $service->confirmDeparture($userId, $doId, $expectedVersion, $shipmentGroup, $items, $request->header('Idempotency-Key'));
            $createdShipments = $dto['shipments'] ?? [];
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'delivery_order', 'recordKey' => (string) $doId];
        });

        // Runs ONLY after Database::transaction() above has actually
        // committed (or thrown, in which case we never reach here at all)
        // — see ShipmentEmailService::attemptSend()'s own docblock for why
        // an SMTP failure here can NEVER roll back the departure that just
        // succeeded. Best-effort per shipment: one shipment's mail hiccup
        // never blocks another's, and never turns this response into an
        // error the Driver would see as "pengiriman gagal".
        $emailService = new ShipmentEmailService();
        foreach ($createdShipments as $dto) {
            if (!isset($dto['emailOutboxId'])) {
                continue;
            }
            try {
                $emailService->attemptSend((int) $dto['emailOutboxId'], $userId, $request->header('Idempotency-Key'));
            } catch (\Throwable $e) {
                // Never lets an unexpected mail-layer error surface as a
                // 500 for what is, from the Driver's point of view, an
                // already-successful departure. attemptSend() itself
                // already records every transport-level failure onto the
                // outbox row + audit_log — this only catches a genuine
                // bug in that recording path itself, so it still goes to
                // the server error log rather than vanishing silently.
                error_log('ShipmentEmailService::attemptSend failed for outboxId=' . $dto['emailOutboxId'] . ': ' . $e->getMessage());
            }
        }
    }

    public static function history(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $repo = new \Amor\Api\Dispatch\DispatchRepository();
        $rows = $repo->findShipmentHistoryForDriver(Database::pdo(), $userId);
        // Server-authoritative normalized source label (task's own "Final
        // Blocker Fix" — prefer a server DTO over a second, JS-side
        // mapping). driver.js's Riwayat card reads sourceLabel directly;
        // every other raw column stays untouched for backward compat.
        foreach ($rows as &$r) {
            $isSpecial = $r['special_source_type'] !== null;
            $normalizedSourceType = $isSpecial
                ? \Amor\Api\SpecialOrder\NormalizedSourceType::fromSpecialOrder((string) $r['special_source_type'], $r['special_non_store_source'] ?? null)
                : \Amor\Api\SpecialOrder\NormalizedSourceType::REGULAR_STORE_PO;
            $r['normalizedSourceType'] = $normalizedSourceType;
            $r['sourceLabel'] = $isSpecial ? \Amor\Api\SpecialOrder\NormalizedSourceType::label($normalizedSourceType) : null;
        }
        unset($r);
        Response::json($rows);
    }

    /**
     * GET /api/dispatch/shipments/{id} — read-only shipment detail/tracing
     * for the driver who shipped it (or ADMIN). See
     * DispatchService::shipmentDetail()'s own docblock for the
     * authorization rule (403 FORBIDDEN for anyone else).
     */
    public static function shipmentDetail(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $isAdmin = in_array('ADMIN', Auth::currentRoles(), true);
        $shipmentId = (int) $request->routeParams['id'];
        $service = new DispatchService(Database::pdo());
        Response::json($service->shipmentDetail($shipmentId, $userId, $isAdmin));
    }

    private static function requireDate(?string $s): string
    {
        $s = (string) $s;
        $d = \DateTime::createFromFormat('Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s) {
            throw new ApiException(400, 'INVALID_DATE', "tanggal must be a valid 'YYYY-MM-DD' date");
        }
        return $s;
    }

    private static function requireInt(mixed $v, string $field): int
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            throw new ApiException(400, 'MISSING_FIELD', "{$field} is required");
        }
        return (int) $v;
    }
}
