<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Idempotency;
use Amor\Api\Production\ProductionService;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

/**
 * JSON API for the Phase 3 production/SPK actual module. Mirrors
 * PoController's shape (auth -> service call -> Response::json for reads,
 * Idempotency::handle wrapping a transaction for writes) so the two
 * authoritative MySQL modules stay consistent for future maintainers.
 */
final class ProductionController
{
    private const EDITOR_ROLES = ['ADMIN', 'PPIC', 'PRODUCTION'];
    // Reopen is intentionally NOT available to plain PRODUCTION operators —
    // task section 8: "reopen submitted restricted."
    private const REOPEN_ROLES = ['ADMIN', 'PPIC'];

    public static function target(Request $request): void
    {
        Auth::requireAuth();
        $tanggal = self::requireDate($request->query('date'));
        $divisionId = self::requireInt($request->query('divisionId'), 'divisionId');
        $service = new ProductionService(Database::pdo());
        Response::json($service->loadTarget($tanggal, $divisionId));
    }

    public static function index(Request $request): void
    {
        Auth::requireAuth();
        $service = new ProductionService(Database::pdo());
        Response::json($service->listRuns(
            $request->query('date'),
            $request->query('factoryId') !== null ? (int) $request->query('factoryId') : null,
            $request->query('divisionId') !== null ? (int) $request->query('divisionId') : null,
            $request->query('status')
        ));
    }

    public static function show(Request $request): void
    {
        Auth::requireAuth();
        $id = (int) $request->routeParams['id'];
        $service = new ProductionService(Database::pdo());
        Response::json($service->getRun($id));
    }

    public static function history(Request $request): void
    {
        Auth::requireAuth();
        $pdo = Database::pdo();
        $limit = $request->query('limit') !== null ? (int) $request->query('limit') : 50;

        $sql = "SELECT h.* FROM audit_log h
                INNER JOIN production_run r ON CAST(h.record_key AS UNSIGNED) = r.production_run_id
                INNER JOIN division d ON d.division_id = r.division_id
                WHERE h.record_type = 'production_run'";
        $params = [];
        if ($request->query('date') !== null) {
            $sql .= ' AND r.tanggal = ?';
            $params[] = $request->query('date');
        }
        if ($request->query('factoryId') !== null) {
            $sql .= ' AND d.factory_id = ?';
            $params[] = (int) $request->query('factoryId');
        }
        if ($request->query('divisionId') !== null) {
            $sql .= ' AND r.division_id = ?';
            $params[] = (int) $request->query('divisionId');
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
        $divisionId = self::requireInt($request->input('divisionId'), 'divisionId');

        Idempotency::handle($request, 'POST /api/production', function (PDO $pdo) use ($request, $userId, $tanggal, $divisionId) {
            $service = new ProductionService($pdo);
            $dto = $service->createDraft($tanggal, $divisionId, $userId);
            Audit::write(
                $pdo, $request->header('Idempotency-Key'), $userId, 'production.draft.create', 'production_run',
                (string) $dto['productionRunId'], 'ok', null, $dto['version'], ['tanggal' => $tanggal, 'divisionId' => $divisionId]
            );
            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'production_run',
                'recordKey' => (string) $dto['productionRunId'],
            ];
        });
    }

    public static function update(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');
        $items = (array) $request->input('items', []);
        $refreshTargets = (bool) $request->input('refreshTargets', false);

        Idempotency::handle($request, 'PATCH /api/production/{id}', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion, $items, $refreshTargets) {
            $service = new ProductionService($pdo);
            $dto = $service->patchDraft($id, $expectedVersion, $items, $refreshTargets, $userId, $request->header('Idempotency-Key'));
            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'production_run',
                'recordKey' => (string) $id,
            ];
        });
    }

    public static function submit(Request $request): void
    {
        $userId = Auth::requireRole(...self::EDITOR_ROLES);
        $id = (int) $request->routeParams['id'];
        $expectedVersion = self::requireInt($request->input('expectedVersion'), 'expectedVersion');

        Idempotency::handle($request, 'POST /api/production/{id}/submit', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion) {
            $service = new ProductionService($pdo);
            $dto = $service->submit($id, $expectedVersion, $userId, $request->header('Idempotency-Key'));
            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'production_run',
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

        Idempotency::handle($request, 'POST /api/production/{id}/reopen', function (PDO $pdo) use ($request, $userId, $id, $expectedVersion, $reason) {
            $service = new ProductionService($pdo);
            $dto = $service->reopen($id, $expectedVersion, $reason, $userId, $request->header('Idempotency-Key'));
            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'production_run',
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
