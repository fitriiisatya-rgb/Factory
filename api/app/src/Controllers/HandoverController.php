<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Idempotency;
use Amor\Api\Production\HandoverService;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

/**
 * Serah terima produksi → FG (migration 0025). Divisions create/correct
 * their handovers; FG sees them arrive. See HandoverService for the rules.
 */
final class HandoverController
{
    private const DIVISION_ROLES = ['ADMIN', 'PPIC', 'PRODUCTION'];

    /** GET /api/production/handovers?tanggal=&factoryId= — FG/office see the factory; a division account only its own. */
    public static function index(Request $request): void
    {
        Auth::requireRole('ADMIN', 'PPIC', 'PRODUCTION', 'FG_PACKING');
        $tanggal = (string) $request->query('tanggal', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            throw new ApiException(400, 'INVALID_DATE', 'tanggal harus YYYY-MM-DD');
        }
        $factoryId = (int) $request->query('factoryId', '0');
        $roles = Auth::currentRoles();
        $divisionIds = array_intersect(['ADMIN', 'PPIC', 'FG_PACKING'], $roles) === [] ? Auth::currentDivisionIds() : null;
        Response::json((new HandoverService(Database::pdo()))->listForDay($tanggal, $factoryId, $divisionIds));
    }

    /** POST /api/production/{id}/handovers {items:[{productId,qty}], note} */
    public static function create(Request $request): void
    {
        $userId = Auth::requireRole(...self::DIVISION_ROLES);
        $runId = (int) $request->routeParams['id'];
        $items = (array) $request->input('items', []);
        $note = $request->input('note') !== null ? (string) $request->input('note') : null;
        $operator = Auth::currentOperatorName();
        Idempotency::handle($request, 'POST /api/production/{id}/handovers', function (PDO $pdo) use ($request, $userId, $runId, $items, $note, $operator) {
            $dto = (new HandoverService($pdo))->create($runId, $items, $note, $userId, $operator, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'production_handover', 'recordKey' => (string) $dto['handoverId']];
        });
    }

    /** PATCH /api/production/handovers/{id} {items:[{productId,qty}]} — qty 0 takes a product back. */
    public static function update(Request $request): void
    {
        $userId = Auth::requireRole(...self::DIVISION_ROLES);
        $id = (int) $request->routeParams['id'];
        $items = (array) $request->input('items', []);
        $operator = Auth::currentOperatorName();
        Idempotency::handle($request, 'PATCH /api/production/handovers/{id}', function (PDO $pdo) use ($request, $userId, $id, $items, $operator) {
            $dto = (new HandoverService($pdo))->update($id, $items, $userId, $operator, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'production_handover', 'recordKey' => (string) $id];
        });
    }
}
