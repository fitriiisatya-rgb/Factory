<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\Delivery\DoService;
use Amor\Api\Delivery\ShipmentService;
use Amor\Api\Idempotency;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

/**
 * JSON API for the Phase 5 Draft DO / staged Shipment module. Mirrors
 * ProductionController/FgController's shape (auth -> service call ->
 * Response::json for reads, Idempotency::handle wrapping a transaction for
 * writes).
 */
final class DoController
{
    private const EDITOR_ROLES = ['ADMIN', 'PPIC', 'PRODUCTION'];

    public static function preview(Request $request): void
    {
        Auth::requireAuth();
        $tanggal = self::requireDate($request->query('date'));
        $storeId = self::requireInt($request->query('storeId'), 'storeId');
        $service = new DoService(Database::pdo());
        Response::json($service->loadPreview($tanggal, $storeId));
    }

    public static function stores(Request $request): void
    {
        Auth::requireAuth();
        $tanggal = self::requireDate($request->query('date'));
        $factoryId = self::requireInt($request->query('factoryId'), 'factoryId');
        $service = new DoService(Database::pdo());
        Response::json($service->storesWithPo($tanggal, $factoryId));
    }

    public static function index(Request $request): void
    {
        Auth::requireAuth();
        $service = new DoService(Database::pdo());
        $tanggal = $request->query('date');
        $factoryId = $request->query('factoryId') !== null ? (int) $request->query('factoryId') : null;
        if ($tanggal !== null && $factoryId !== null) {
            Response::json($service->listDosForFactory($tanggal, $factoryId));
            return;
        }
        Response::json($service->listDos(
            $tanggal,
            $request->query('storeId') !== null ? (int) $request->query('storeId') : null,
            $request->query('status')
        ));
    }

    public static function show(Request $request): void
    {
        Auth::requireAuth();
        $id = (int) $request->routeParams['id'];
        $service = new DoService(Database::pdo());
        Response::json($service->getDo($id));
    }

    public static function shipments(Request $request): void
    {
        Auth::requireAuth();
        $id = (int) $request->routeParams['id'];
        $pdo = Database::pdo();
        $repo = new DoRepository();
        $shipments = $repo->findShipmentsForDo($pdo, $id);
        $out = [];
        foreach ($shipments as $sh) {
            $sh['items'] = $repo->findShipmentItems($pdo, (int) $sh['shipment_id']);
            $out[] = $sh;
        }
        Response::json($out);
    }

    public static function history(Request $request): void
    {
        Auth::requireAuth();
        $pdo = Database::pdo();
        $limit = $request->query('limit') !== null ? (int) $request->query('limit') : 50;

        $sql = "SELECT h.* FROM audit_log h
                INNER JOIN delivery_order o ON CAST(h.record_key AS UNSIGNED) = o.delivery_order_id
                WHERE h.record_type = 'delivery_order'";
        $params = [];
        if ($request->query('date') !== null) {
            $sql .= ' AND o.tanggal = ?';
            $params[] = $request->query('date');
        }
        if ($request->query('storeId') !== null) {
            $sql .= ' AND o.store_id = ?';
            $params[] = (int) $request->query('storeId');
        }
        $sql .= ' ORDER BY h.event_at DESC LIMIT ' . max(1, min(500, $limit));

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        Response::json($stmt->fetchAll());
    }

    public static function create(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $tanggal = self::requireDate($request->input('tanggal'));
        $storeId = self::requireInt($request->input('storeId'), 'storeId');

        Idempotency::handle($request, 'POST /api/do', function (PDO $pdo) use ($userId, $tanggal, $storeId) {
            $service = new DoService($pdo);
            $dto = $service->createDraft($tanggal, $storeId, $userId);
            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'delivery_order',
                'recordKey' => (string) $dto['doId'],
            ];
        });
    }

    public static function generateBulk(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $tanggal = self::requireDate($request->input('tanggal'));
        $factoryId = self::requireInt($request->input('factoryId'), 'factoryId');

        Idempotency::handle($request, 'POST /api/do/generate-bulk', function (PDO $pdo) use ($userId, $tanggal, $factoryId) {
            $service = new DoService($pdo);
            $dto = $service->generateBulk($tanggal, $factoryId, $userId);
            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'delivery_order_bulk',
                'recordKey' => $tanggal . '|' . $factoryId,
            ];
        });
    }

    public static function preprint(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');

        Idempotency::handle($request, 'POST /api/do/{id}/preprint', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion) {
            $service = new DoService($pdo);
            $dto = $service->preprint($id, $expectedVersion, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'delivery_order', 'recordKey' => (string) $id];
        });
    }

    public static function refreshPo(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');

        Idempotency::handle($request, 'POST /api/do/{id}/refresh-po', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion) {
            $service = new DoService($pdo);
            $dto = $service->refreshFromPo($id, $expectedVersion, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'delivery_order', 'recordKey' => (string) $id];
        });
    }

    public static function cancel(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');
        $reason = (string) $request->input('reason', '');

        Idempotency::handle($request, 'POST /api/do/{id}/cancel', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion, $reason) {
            $service = new DoService($pdo);
            $dto = $service->cancel($id, $expectedVersion, $reason, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'delivery_order', 'recordKey' => (string) $id];
        });
    }

    public static function shipmentPreview(Request $request): void
    {
        Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $items = (array) $request->input('items', []);
        $service = new ShipmentService(Database::pdo());
        Response::json($service->preview($id, $items));
    }

    public static function ship(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');
        $shipmentGroup = (string) $request->input('shipmentGroup', 'MAIN');
        $items = (array) $request->input('items', []);

        Idempotency::handle($request, 'POST /api/do/{id}/ship', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion, $shipmentGroup, $items) {
            $service = new ShipmentService($pdo);
            $dto = $service->ship($id, $expectedVersion, $shipmentGroup, $items, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'delivery_order', 'recordKey' => (string) $id];
        });
    }

    private static function requireDate(?string $s): string
    {
        $s = (string) $s;
        $d = \DateTime::createFromFormat('Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s) {
            throw new ApiException(400, 'INVALID_DATE', "date must be a valid 'YYYY-MM-DD' date");
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
