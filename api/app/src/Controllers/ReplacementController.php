<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Idempotency;
use Amor\Api\Replacement\ReplacementDoService;
use Amor\Api\Replacement\ReplacementService;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

/**
 * HTTP layer for Replacement Reject (migration 0016). Role gates mirror
 * the existing precedent exactly: disposition decisions are an Admin-
 * level call (same tier as ReceiptController::adminVerify()'s own ADMIN
 * gate, widened to PPIC per this app's existing "PPIC == ADMIN for every
 * gate" convention — see Auth::requireRole()'s own docblock); production
 * entry/FG verify reuse Production's own EDITOR_ROLES; DO/shipment reuse
 * DoController's own EDITOR_ROLES. Server-side authorization is
 * authoritative regardless of what any client UI shows or hides.
 */
final class ReplacementController
{
    private const DISPOSITION_ROLES = ['ADMIN', 'PPIC'];
    private const PRODUCTION_ROLES = ['ADMIN', 'PPIC', 'PRODUCTION'];
    private const FG_ROLES = ['ADMIN', 'PPIC', 'PRODUCTION', 'FG_PACKING'];
    private const DO_ROLES = ['ADMIN', 'PPIC', 'PRODUCTION'];

    public static function pendingDisposition(Request $request): void
    {
        Auth::requireRole(...self::DISPOSITION_ROLES);
        $tanggal = $request->query('tanggal');
        $service = new ReplacementService(Database::pdo());
        Response::json($service->pendingDisposition($tanggal));
    }

    public static function disposeReject(Request $request): void
    {
        $userId = Auth::requireRole(...self::DISPOSITION_ROLES);
        $receiptItemId = (int) $request->routeParams['id'];
        $disposition = (string) $request->input('disposition', '');
        $approvedQty = (float) ($request->input('approvedQty') ?? 0);
        $reason = $request->input('reason') !== null ? (string) $request->input('reason') : null;

        Idempotency::handle($request, 'POST /api/replacement/receipt-items/{id}/disposition', function (PDO $pdo) use ($userId, $receiptItemId, $disposition, $approvedQty, $reason, $request) {
            $service = new ReplacementService($pdo);
            $dto = $service->disposeReject($receiptItemId, $disposition, $approvedQty, $reason, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'shipment_receipt_item', 'recordKey' => (string) $receiptItemId];
        });
    }

    public static function index(Request $request): void
    {
        Auth::requireRole(...self::DISPOSITION_ROLES);
        $filters = [];
        if ($request->query('storeId') !== null) {
            $filters['storeId'] = (int) $request->query('storeId');
        }
        if ($request->query('status') !== null) {
            $filters['status'] = (string) $request->query('status');
        }
        if ($request->query('factoryId') !== null) {
            $filters['factoryId'] = (int) $request->query('factoryId');
        }
        $service = new ReplacementService(Database::pdo());
        Response::json($service->listDemands($filters));
    }

    public static function show(Request $request): void
    {
        Auth::requireRole(...self::DISPOSITION_ROLES);
        $id = (int) $request->routeParams['id'];
        $service = new ReplacementService(Database::pdo());
        Response::json($service->getDemand($id));
    }

    public static function updateProductionActual(Request $request): void
    {
        $userId = Auth::requireRole(...self::PRODUCTION_ROLES);
        $id = (int) $request->routeParams['id'];
        $aktual = (float) ($request->input('aktualProduksi') ?? 0);
        $reject = (float) ($request->input('rejectProduksi') ?? 0);

        Idempotency::handle($request, 'POST /api/replacement-demands/{id}/production-actual', function (PDO $pdo) use ($userId, $id, $aktual, $reject, $request) {
            $service = new ReplacementService($pdo);
            $dto = $service->updateProductionActual($id, $aktual, $reject, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'replacement_demand', 'recordKey' => (string) $id];
        });
    }

    public static function verifyFg(Request $request): void
    {
        $userId = Auth::requireRole(...self::FG_ROLES);
        $id = (int) $request->routeParams['id'];
        $qty = (float) ($request->input('fgVerifiedQty') ?? 0);

        Idempotency::handle($request, 'POST /api/replacement-demands/{id}/verify-fg', function (PDO $pdo) use ($userId, $id, $qty, $request) {
            $service = new ReplacementService($pdo);
            $dto = $service->verifyFg($id, $qty, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'replacement_demand', 'recordKey' => (string) $id];
        });
    }

    public static function createDo(Request $request): void
    {
        $userId = Auth::requireRole(...self::DO_ROLES);
        $id = (int) $request->routeParams['id'];

        Idempotency::handle($request, 'POST /api/replacement-demands/{id}/do', function (PDO $pdo) use ($userId, $id, $request) {
            $service = new ReplacementDoService($pdo);
            $dto = $service->create($id, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'replacement_do', 'recordKey' => (string) $id];
        });
    }

    public static function showDo(Request $request): void
    {
        Auth::requireRole(...self::DO_ROLES);
        $id = (int) $request->routeParams['id'];
        $service = new ReplacementDoService(Database::pdo());
        Response::json($service->getDo($id));
    }

    public static function ship(Request $request): void
    {
        $userId = Auth::requireRole(...self::DO_ROLES);
        $id = (int) $request->routeParams['id'];
        $qtyRaw = $request->input('qty');
        $qty = $qtyRaw !== null && $qtyRaw !== '' ? (float) $qtyRaw : null;

        Idempotency::handle($request, 'POST /api/replacement-do/{id}/ship', function (PDO $pdo) use ($userId, $id, $qty, $request) {
            $service = new ReplacementDoService($pdo);
            $dto = $service->ship($id, $qty, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'replacement_do', 'recordKey' => (string) $id];
        });
    }
}
