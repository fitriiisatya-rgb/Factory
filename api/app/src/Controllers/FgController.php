<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Fg\FgService;
use Amor\Api\Idempotency;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

/**
 * JSON API for the Phase 4 FG/Packing module. Mirrors ProductionController's
 * shape exactly (auth -> service call -> Response::json for reads,
 * Idempotency::handle wrapping a transaction for writes).
 */
final class FgController
{
    private const EDITOR_ROLES = ['ADMIN', 'PPIC', 'PRODUCTION'];
    // Reopen is restricted, same rationale as Production Phase 3.
    private const REOPEN_ROLES = ['ADMIN', 'PPIC'];

    public static function target(Request $request): void
    {
        Auth::requireAuth();
        $tanggal = self::requireDate($request->query('date'));
        $factoryId = self::requireInt($request->query('factoryId'), 'factoryId');
        $divisionId = $request->query('divisionId') !== null ? (int) $request->query('divisionId') : null;
        $service = new FgService(Database::pdo());
        Response::json($service->loadTarget($tanggal, $factoryId, $divisionId));
    }

    public static function availability(Request $request): void
    {
        Auth::requireAuth();
        $factoryId = self::requireInt($request->query('factoryId'), 'factoryId');
        $productId = self::requireInt($request->query('productId'), 'productId');
        $service = new FgService(Database::pdo());
        Response::json($service->availability($factoryId, $productId));
    }

    public static function storeBreakdown(Request $request): void
    {
        Auth::requireAuth();
        $tanggal = self::requireDate($request->query('date'));
        $factoryId = self::requireInt($request->query('factoryId'), 'factoryId');
        $productId = self::requireInt($request->query('productId'), 'productId');
        $service = new FgService(Database::pdo());
        Response::json($service->storeBreakdown($tanggal, $factoryId, $productId));
    }

    public static function index(Request $request): void
    {
        Auth::requireAuth();
        $service = new FgService(Database::pdo());
        Response::json($service->listBatches(
            $request->query('date'),
            $request->query('factoryId') !== null ? (int) $request->query('factoryId') : null,
            $request->query('status')
        ));
    }

    public static function show(Request $request): void
    {
        Auth::requireAuth();
        $id = (int) $request->routeParams['id'];
        $service = new FgService(Database::pdo());
        Response::json($service->getBatch($id));
    }

    public static function history(Request $request): void
    {
        Auth::requireAuth();
        $pdo = Database::pdo();
        $limit = $request->query('limit') !== null ? (int) $request->query('limit') : 50;

        $sql = "SELECT h.* FROM audit_log h
                INNER JOIN fg_batch b ON CAST(h.record_key AS UNSIGNED) = b.fg_batch_id
                WHERE h.record_type = 'fg_batch'";
        $params = [];
        if ($request->query('date') !== null) {
            $sql .= ' AND b.tanggal = ?';
            $params[] = $request->query('date');
        }
        if ($request->query('factoryId') !== null) {
            $sql .= ' AND b.factory_id = ?';
            $params[] = (int) $request->query('factoryId');
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
        $factoryId = self::requireInt($request->input('factoryId'), 'factoryId');

        Idempotency::handle($request, 'POST /api/fg', function (PDO $pdo) use ($request, $userId, $tanggal, $factoryId) {
            $service = new FgService($pdo);
            $dto = $service->createDraft($tanggal, $factoryId, $userId);
            Audit::write(
                $pdo, $request->header('Idempotency-Key'), $userId, 'fg.draft.create', 'fg_batch',
                (string) $dto['fgBatchId'], 'ok', null, $dto['version'], ['tanggal' => $tanggal, 'factoryId' => $factoryId]
            );
            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'fg_batch',
                'recordKey' => (string) $dto['fgBatchId'],
            ];
        });
    }

    public static function update(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');
        $items = (array) $request->input('items', []);
        $refreshSource = (bool) $request->input('refreshSource', false);

        Idempotency::handle($request, 'PATCH /api/fg/{id}', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion, $items, $refreshSource) {
            $service = new FgService($pdo);
            $dto = $service->patchDraft($id, $expectedVersion, $items, $refreshSource, $userId, $request->header('Idempotency-Key'));
            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'fg_batch',
                'recordKey' => (string) $id,
            ];
        });
    }

    /**
     * POST /api/fg/{id}/refresh-source — explicit "Refresh Produksi
     * Terbaru" action for an EXISTING draft/reopened batch. See
     * FgService::refreshProductionSource()'s own docblock: only updates
     * fg_batch_source.source_version and fg_item.production_actual_snapshot,
     * writes ZERO stock_ledger rows, never touches fgVerified/packed_qty.
     */
    public static function refreshSource(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');

        Idempotency::handle($request, 'POST /api/fg/{id}/refresh-source', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion) {
            $service = new FgService($pdo);
            $dto = $service->refreshProductionSource($id, $expectedVersion, $userId, $request->header('Idempotency-Key'));
            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'fg_batch',
                'recordKey' => (string) $id,
            ];
        });
    }

    public static function submit(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');

        Idempotency::handle($request, 'POST /api/fg/{id}/submit', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion) {
            $service = new FgService($pdo);
            $dto = $service->submit($id, $expectedVersion, $userId, $request->header('Idempotency-Key'));
            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'fg_batch',
                'recordKey' => (string) $id,
            ];
        });
    }

    public static function reopen(Request $request): void
    {
        $userId = Auth::requireRole(...self::REOPEN_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');
        $reason = (string) $request->input('reason', '');

        Idempotency::handle($request, 'POST /api/fg/{id}/reopen', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion, $reason) {
            $service = new FgService($pdo);
            $dto = $service->reopen($id, $expectedVersion, $reason, $userId, $request->header('Idempotency-Key'));
            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'fg_batch',
                'recordKey' => (string) $id,
            ];
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
