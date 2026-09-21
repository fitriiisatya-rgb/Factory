<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Dispatch\DepartureService;
use Amor\Api\Dispatch\DispatchService;
use Amor\Api\Idempotency;
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

    public static function departures(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $doId = self::requireInt($request->input('doId'), 'doId');
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');
        $shipmentGroup = (string) $request->input('shipmentGroup', 'MAIN');
        $items = (array) $request->input('items', []);

        Idempotency::handle($request, 'POST /api/dispatch/departures', function (PDO $pdo) use ($userId, $doId, $expectedVersion, $shipmentGroup, $items, $request) {
            $service = new DepartureService($pdo);
            $dto = $service->confirmDeparture($userId, $doId, $expectedVersion, $shipmentGroup, $items, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'delivery_order', 'recordKey' => (string) $doId];
        });
    }

    public static function history(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $repo = new \Amor\Api\Dispatch\DispatchRepository();
        Response::json($repo->findShipmentHistoryForDriver(Database::pdo(), $userId));
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
