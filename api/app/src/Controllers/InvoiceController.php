<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Idempotency;
use Amor\Api\Invoice\InvoiceService;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

/**
 * Invoice generation from real DO/Shipment data (migration 0018) —
 * ADMIN-only throughout, same role/session/CSRF/Idempotency-Key model as
 * every other admin endpoint in this app. See InvoiceService's own
 * docblock for the full business-rule rationale.
 */
final class InvoiceController
{
    private const ADMIN_ROLES = ['ADMIN'];

    public static function index(Request $request): void
    {
        Auth::requireRole(...self::ADMIN_ROLES);
        $service = new InvoiceService(Database::pdo());
        $storeId = $request->query('storeId') !== null ? (int) $request->query('storeId') : null;
        $dateFrom = $request->query('dateFrom');
        $dateTo = $request->query('dateTo');
        Response::json($service->listAll($storeId, $dateFrom, $dateTo));
    }

    /** GET — read-only computation of what a generate() call would produce, never writes anything. */
    public static function preview(Request $request): void
    {
        Auth::requireRole(...self::ADMIN_ROLES);
        $storeId = (int) $request->query('storeId');
        $dateFrom = (string) $request->query('dateFrom');
        $dateTo = (string) $request->query('dateTo');
        $service = new InvoiceService(Database::pdo());
        Response::json($service->preview($storeId, $dateFrom, $dateTo));
    }

    public static function generate(Request $request): void
    {
        $userId = Auth::requireRole(...self::ADMIN_ROLES);
        $storeId = (int) $request->input('storeId');
        $dateFrom = (string) $request->input('dateFrom');
        $dateTo = (string) $request->input('dateTo');

        Idempotency::handle($request, 'POST /api/invoices', function (PDO $pdo) use ($userId, $request, $storeId, $dateFrom, $dateTo) {
            $service = new InvoiceService($pdo);
            $dto = $service->generate($storeId, $dateFrom, $dateTo, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'invoice', 'recordKey' => (string) $dto['invoiceId']];
        });
    }

    public static function show(Request $request): void
    {
        Auth::requireRole(...self::ADMIN_ROLES);
        $invoiceId = (int) $request->routeParams['id'];
        $service = new InvoiceService(Database::pdo());
        Response::json($service->getDetail($invoiceId));
    }

    public static function void(Request $request): void
    {
        $userId = Auth::requireRole(...self::ADMIN_ROLES);
        $invoiceId = (int) $request->routeParams['id'];
        $reason = (string) $request->input('reason', '');

        Idempotency::handle($request, 'POST /api/invoices/{id}/void', function (PDO $pdo) use ($userId, $request, $invoiceId, $reason) {
            $service = new InvoiceService($pdo);
            $service->void($invoiceId, $userId, $reason, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => ['invoiceId' => $invoiceId, 'voided' => true]], 'recordType' => 'invoice', 'recordKey' => (string) $invoiceId];
        });
    }
}
