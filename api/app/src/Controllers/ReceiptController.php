<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Dispatch\ReceiptService;
use Amor\Api\Idempotency;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

/**
 * Two very different audiences share this controller:
 *   - publicView()/confirm() — PUBLIC, no session, no CSRF token (there is
 *     none to check — see App::CSRF_EXEMPT). Access control is entirely the
 *     high-entropy token in the URL; every DB lookup goes through
 *     ReceiptRepository::findDoIdByToken, never a raw ID from the request.
 *   - adminList()/adminVerify() — ADMIN only, same session/CSRF/role model
 *     as every other admin endpoint in this app.
 */
final class ReceiptController
{
    public static function publicView(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $service = new ReceiptService(Database::pdo());
        Response::json($service->getPublicView($token));
    }

    public static function confirm(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $shipmentId = (int) $request->routeParams['shipmentId'];
        $receiverName = $request->input('receiverName') !== null ? (string) $request->input('receiverName') : null;
        $note = $request->input('note') !== null ? (string) $request->input('note') : null;
        $items = (array) $request->input('items', []);

        Idempotency::handle($request, 'POST /api/receive/{token}/shipments/{shipmentId}/confirm', function (PDO $pdo) use ($token, $shipmentId, $receiverName, $note, $items, $request) {
            $service = new ReceiptService($pdo);
            $dto = $service->confirmReceipt($token, $shipmentId, $receiverName, $note, $items, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'shipment_receipt', 'recordKey' => (string) $shipmentId];
        });
    }

    public static function adminList(Request $request): void
    {
        Auth::requireRole('ADMIN');
        $tanggal = $request->query('tanggal');
        $status = $request->query('status');
        $service = new ReceiptService(Database::pdo());
        Response::json($service->adminList($tanggal, $status));
    }

    public static function adminVerify(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN');
        $receiptId = (int) $request->routeParams['id'];

        Idempotency::handle($request, 'POST /api/admin/receipts/{id}/verify', function (PDO $pdo) use ($userId, $receiptId, $request) {
            $service = new ReceiptService($pdo);
            $dto = $service->adminVerify($receiptId, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'shipment_receipt', 'recordKey' => (string) $receiptId];
        });
    }
}
