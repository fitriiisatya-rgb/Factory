<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Idempotency;
use Amor\Api\Mail\ShipmentEmailService;
use Amor\Api\Request;
use Amor\Api\Response;
use Amor\Api\SpecialOrder\SpecialOrderDoService;
use PDO;

/**
 * JSON API for special_order_do — the source-specific DO + real shipment
 * write path for Pesanan Khusus Toko / Pesanan Non-Toko (migration 0012,
 * reworked). Mirrors DoController/DispatchController's own shape.
 */
final class SpecialOrderDoController
{
    private const EDITOR_ROLES = ['ADMIN', 'PPIC'];
    private const DRIVER_ROLES = ['DRIVER', 'ADMIN'];

    public static function index(Request $request): void
    {
        Auth::requireAuth();
        $service = new SpecialOrderDoService(Database::pdo());
        $filters = array_filter([
            'sourceType' => $request->query('sourceType'),
            'status' => $request->query('status'),
            'tanggal' => $request->query('tanggal'),
            'deliveryMethod' => $request->query('deliveryMethod'),
            'factoryId' => $request->query('factoryId') !== null ? (int) $request->query('factoryId') : null,
        ], fn ($v) => $v !== null);
        Response::json($service->listDos($filters));
    }

    /** GET /api/special-order-do/driver-pool — Driver Portal's own eligible-dispatch list. */
    public static function driverPool(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $service = new SpecialOrderDoService(Database::pdo());
        Response::json($service->driverPool($userId));
    }

    public static function show(Request $request): void
    {
        Auth::requireAuth();
        $id = (int) $request->routeParams['id'];
        $service = new SpecialOrderDoService(Database::pdo());
        Response::json($service->getDo($id));
    }

    public static function create(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $orderId = self::requireInt($request->input('orderId'), 'orderId');
        $factoryId = self::requireInt($request->input('factoryId'), 'factoryId');
        $items = $request->input('items');
        $deliveryMethod = (string) ($request->input('deliveryMethod') ?? 'DRIVER_INTERNAL');
        $courierProvider = $request->input('courierProvider');
        $courierName = $request->input('courierName');
        $externalOrderReference = $request->input('externalOrderReference');
        $dropStoreId = $request->input('dropStoreId') !== null ? (int) $request->input('dropStoreId') : null;

        Idempotency::handle($request, 'POST /api/special-order-do', function (PDO $pdo) use ($userId, $orderId, $factoryId, $items, $deliveryMethod, $courierProvider, $courierName, $externalOrderReference, $dropStoreId, $request) {
            $service = new SpecialOrderDoService($pdo);
            $dto = $service->create($orderId, $factoryId, is_array($items) ? $items : null, $deliveryMethod, $courierProvider, $courierName, $externalOrderReference, $dropStoreId, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order_do', 'recordKey' => (string) $dto['doId']];
        });
    }

    public static function claim(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $id = (int) $request->routeParams['id'];
        Idempotency::handle($request, 'POST /api/special-order-do/{id}/claim', function (PDO $pdo) use ($request, $userId, $id) {
            $service = new SpecialOrderDoService($pdo);
            $dto = $service->claim($id, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order_do', 'recordKey' => (string) $id];
        });
    }

    public static function release(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $isAdmin = in_array('ADMIN', Auth::currentRoles(), true);
        $id = (int) $request->routeParams['id'];
        Idempotency::handle($request, 'POST /api/special-order-do/{id}/release', function (PDO $pdo) use ($request, $userId, $isAdmin, $id) {
            $service = new SpecialOrderDoService($pdo);
            $dto = $service->release($id, $userId, $isAdmin, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order_do', 'recordKey' => (string) $id];
        });
    }

    public static function cancel(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');
        $reason = (string) ($request->input('reason') ?? '');

        Idempotency::handle($request, 'POST /api/special-order-do/{id}/cancel', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion, $reason) {
            $service = new SpecialOrderDoService($pdo);
            $dto = $service->cancel($id, $expectedVersion, $reason, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order_do', 'recordKey' => (string) $id];
        });
    }

    public static function changeDeliveryMethod(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');
        $method = (string) ($request->input('deliveryMethod') ?? '');
        $provider = $request->input('courierProvider');
        $courierName = $request->input('courierName');
        $externalRef = $request->input('externalOrderReference');

        Idempotency::handle($request, 'POST /api/special-order-do/{id}/delivery-method', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion, $method, $provider, $courierName, $externalRef) {
            $service = new SpecialOrderDoService($pdo);
            $dto = $service->changeDeliveryMethod($id, $expectedVersion, $method, $provider, $courierName, $externalRef, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order_do', 'recordKey' => (string) $id];
        });
    }

    /** POST /api/special-order-do/{id}/depart — DRIVER_INTERNAL's "Confirm Departure / Berangkat". */
    public static function depart(Request $request): void
    {
        $userId = Auth::requireRole(...self::DRIVER_ROLES);
        $id = (int) $request->routeParams['id'];
        $items = $request->input('items');

        // Captured only if a fresh dispatch actually runs — an exact
        // Idempotency-Key replay short-circuits before $work runs at all,
        // so $emailOutboxId stays null and no duplicate email attempt ever
        // fires for a replayed request (same discipline as
        // Controllers\DispatchController::departures()'s own $createdShipments).
        $emailOutboxId = null;
        Idempotency::handle($request, 'POST /api/special-order-do/{id}/depart', function (PDO $pdo) use ($request, $userId, $id, $items, &$emailOutboxId) {
            $service = new SpecialOrderDoService($pdo);
            $dto = $service->confirmDeparture($id, is_array($items) ? $items : null, $userId, $request->header('Idempotency-Key'));
            $emailOutboxId = $dto['emailOutboxId'] ?? null;
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order_do', 'recordKey' => (string) $id];
        });

        self::attemptEmailAfterCommit($emailOutboxId, $userId, $request);
    }

    /** POST /api/special-order-do/{id}/courier-handover — EXTERNAL_COURIER's "Barang Diserahkan ke Kurir". */
    public static function courierHandover(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $items = $request->input('items');
        $note = $request->input('handoverNote');

        $emailOutboxId = null;
        Idempotency::handle($request, 'POST /api/special-order-do/{id}/courier-handover', function (PDO $pdo) use ($request, $userId, $id, $items, $note, &$emailOutboxId) {
            $service = new SpecialOrderDoService($pdo);
            $dto = $service->courierHandover($id, is_array($items) ? $items : null, $note, $userId, $request->header('Idempotency-Key'));
            $emailOutboxId = $dto['emailOutboxId'] ?? null;
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order_do', 'recordKey' => (string) $id];
        });

        self::attemptEmailAfterCommit($emailOutboxId, $userId, $request);
    }

    /**
     * Runs ONLY after Database::transaction() (inside Idempotency::handle())
     * has actually committed — see Mail\ShipmentEmailService::attemptSend()'s
     * own docblock for why an SMTP failure here can NEVER roll back the
     * dispatch that just succeeded. Best-effort: never turns an
     * already-successful departure/handover into an error response the
     * caller would see as failed.
     */
    private static function attemptEmailAfterCommit(?int $emailOutboxId, int $userId, Request $request): void
    {
        if ($emailOutboxId === null) {
            return;
        }
        $emailService = new ShipmentEmailService();
        try {
            $emailService->attemptSend($emailOutboxId, $userId, $request->header('Idempotency-Key'));
        } catch (\Throwable $e) {
            error_log('ShipmentEmailService::attemptSend failed for outboxId=' . $emailOutboxId . ': ' . $e->getMessage());
        }
    }

    private static function requireInt(mixed $v, string $field): int
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            throw new ApiException(400, 'INVALID_INPUT', "{$field} is required and must be numeric");
        }
        return (int) $v;
    }
}
