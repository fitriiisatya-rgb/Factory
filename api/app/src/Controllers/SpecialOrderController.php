<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Idempotency;
use Amor\Api\Request;
use Amor\Api\Response;
use Amor\Api\SpecialOrder\SpecialOrderService;
use PDO;

/**
 * JSON API for Pesanan Khusus Toko / Pesanan Non-Toko + Production
 * routing (migration 0010). Mirrors DoController/FgController's shape.
 */
final class SpecialOrderController
{
    private const EDITOR_ROLES = ['ADMIN', 'PPIC'];
    private const STATUS_UPDATE_ROLES = ['ADMIN', 'PPIC', 'PRODUCTION'];

    public static function catalog(Request $request): void
    {
        Auth::requireAuth();
        $service = new SpecialOrderService(Database::pdo());
        Response::json($service->listCatalog());
    }

    public static function index(Request $request): void
    {
        Auth::requireAuth();
        $service = new SpecialOrderService(Database::pdo());
        $filters = array_filter([
            'sourceType' => $request->query('sourceType'),
            'status' => $request->query('status'),
            'tanggal' => $request->query('tanggal'),
            'storeId' => $request->query('storeId') !== null ? (int) $request->query('storeId') : null,
        ], fn ($v) => $v !== null);
        Response::json($service->listOrders($filters));
    }

    public static function show(Request $request): void
    {
        Auth::requireAuth();
        $id = (int) $request->routeParams['id'];
        $service = new SpecialOrderService(Database::pdo());
        Response::json($service->getOrder($id));
    }

    public static function create(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $input = $request->all();

        Idempotency::handle($request, 'POST /api/special-orders', function (PDO $pdo) use ($userId, $input, $request) {
            $service = new SpecialOrderService($pdo);
            $dto = $service->createOrder($input, $userId, $request->header('Idempotency-Key'));
            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'special_order',
                'recordKey' => (string) $dto['orderId'],
            ];
        });
    }

    public static function confirm(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');

        Idempotency::handle($request, 'POST /api/special-orders/{id}/confirm', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion) {
            $service = new SpecialOrderService($pdo);
            $dto = $service->confirmOrder($id, $expectedVersion, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order', 'recordKey' => (string) $id];
        });
    }

    public static function sendToProduction(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');

        Idempotency::handle($request, 'POST /api/special-orders/{id}/send-to-production', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion) {
            $service = new SpecialOrderService($pdo);
            $dto = $service->sendToProduction($id, $expectedVersion, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order', 'recordKey' => (string) $id];
        });
    }

    public static function updateStatus(Request $request): void
    {
        $userId = Auth::requireRole(...self::STATUS_UPDATE_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');
        $newStatus = (string) ($request->input('status') ?? '');

        Idempotency::handle($request, 'POST /api/special-orders/{id}/status', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion, $newStatus) {
            $service = new SpecialOrderService($pdo);
            $dto = $service->updateStatus($id, $expectedVersion, $newStatus, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order', 'recordKey' => (string) $id];
        });
    }

    /**
     * POST /api/special-orders/{id}/actual — Task per Divisi's Actual/
     * Reject Produksi entry for special-order items. Uses
     * STATUS_UPDATE_ROLES (includes PRODUCTION), matching updateStatus()'s
     * own role set — this is the same production-floor action class.
     */
    public static function updateItemsActual(Request $request): void
    {
        $userId = Auth::requireRole(...self::STATUS_UPDATE_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');
        $items = (array) $request->input('items', []);

        Idempotency::handle($request, 'POST /api/special-orders/{id}/actual', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion, $items) {
            $service = new SpecialOrderService($pdo);
            $dto = $service->updateItemsActual($id, $expectedVersion, $items, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order', 'recordKey' => (string) $id];
        });
    }

    public static function cancel(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');
        $reason = (string) ($request->input('reason') ?? '');

        Idempotency::handle($request, 'POST /api/special-orders/{id}/cancel', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion, $reason) {
            $service = new SpecialOrderService($pdo);
            $dto = $service->cancelOrder($id, $expectedVersion, $reason, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order', 'recordKey' => (string) $id];
        });
    }

    /** GET /api/special-orders/production-inbox — Production's "Order Masuk / Demand Tambahan" view. */
    public static function productionInbox(Request $request): void
    {
        Auth::requireAuth();
        $service = new SpecialOrderService(Database::pdo());
        $filters = array_filter([
            'factoryId' => $request->query('factoryId') !== null ? (int) $request->query('factoryId') : null,
            'divisionId' => $request->query('divisionId') !== null ? (int) $request->query('divisionId') : null,
            'tanggal' => $request->query('tanggal'),
            'status' => $request->query('status'),
            'sourceType' => $request->query('sourceType'),
        ], fn ($v) => $v !== null);
        Response::json($service->productionInbox($filters));
    }

    /** GET /api/special-orders/fg-eligible — FG source-verification inbox for special/non-regular orders. */
    public static function fgEligible(Request $request): void
    {
        Auth::requireAuth();
        $service = new SpecialOrderService(Database::pdo());
        $filters = array_filter([
            'factoryId' => $request->query('factoryId') !== null ? (int) $request->query('factoryId') : null,
            'divisionId' => $request->query('divisionId') !== null ? (int) $request->query('divisionId') : null,
            'tanggal' => $request->query('tanggal'),
            'sourceType' => $request->query('sourceType'),
        ], fn ($v) => $v !== null);
        Response::json($service->fgEligibleItems($filters));
    }

    /** POST /api/special-orders/items/{itemId}/verify-fg — Production → FG bridge for special/non-regular orders. */
    public static function verifyItemFg(Request $request): void
    {
        $userId = Auth::requireRole(...self::STATUS_UPDATE_ROLES);
        $itemId = (int) $request->routeParams['itemId'];
        $qty = (float) ($request->input('fgVerifiedQty') ?? 0);

        Idempotency::handle($request, 'POST /api/special-orders/items/{itemId}/verify-fg', function (PDO $pdo) use ($request, $userId, $itemId, $qty) {
            $service = new SpecialOrderService($pdo);
            $dto = $service->verifyItemFg($itemId, $qty, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order_item', 'recordKey' => (string) $itemId];
        });
    }

    private static function requireInt(mixed $v, string $field): int
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            throw new ApiException(400, 'INVALID_INPUT', "{$field} is required and must be numeric");
        }
        return (int) $v;
    }
}
