<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Idempotency;
use Amor\Api\Request;
use Amor\Api\Response;
use Amor\Api\SpecialOrder\SpecialOrderDoService;
use PDO;

/**
 * JSON API for special_order_do — the source-specific DO for Pesanan
 * Khusus Toko / Pesanan Non-Toko (migration 0012). Mirrors
 * DoController's own shape.
 */
final class SpecialOrderDoController
{
    private const EDITOR_ROLES = ['ADMIN', 'PPIC'];

    public static function index(Request $request): void
    {
        Auth::requireAuth();
        $service = new SpecialOrderDoService(Database::pdo());
        $filters = array_filter([
            'sourceType' => $request->query('sourceType'),
            'status' => $request->query('status'),
            'tanggal' => $request->query('tanggal'),
        ], fn ($v) => $v !== null);
        Response::json($service->listDos($filters));
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

        Idempotency::handle($request, 'POST /api/special-order-do', function (PDO $pdo) use ($userId, $orderId, $request) {
            $service = new SpecialOrderDoService($pdo);
            $dto = $service->createDraft($orderId, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order_do', 'recordKey' => (string) $dto['doId']];
        });
    }

    public static function ship(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');

        Idempotency::handle($request, 'POST /api/special-order-do/{id}/ship', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion) {
            $service = new SpecialOrderDoService($pdo);
            $dto = $service->ship($id, $expectedVersion, $userId, $request->header('Idempotency-Key'));
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

    private static function requireInt(mixed $v, string $field): int
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            throw new ApiException(400, 'INVALID_INPUT', "{$field} is required and must be numeric");
        }
        return (int) $v;
    }
}
