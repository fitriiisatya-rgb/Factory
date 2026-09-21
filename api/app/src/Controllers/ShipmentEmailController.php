<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Idempotency;
use Amor\Api\Mail\ShipmentEmailService;
use Amor\Api\Request;
use PDO;

/**
 * Admin-only "Kirim Ulang Email" (Part J). Deliberately thin — all the
 * actual re-send/audit logic lives in Mail\ShipmentEmailService, reused
 * verbatim from the automatic first-send path (Dispatch\DepartureService
 * + Controllers\DispatchController::departures()) so there is exactly ONE
 * code path that ever attempts an SMTP send.
 *
 * Does NOT use Idempotency::handle() — that helper wraps $work in ONE DB
 * transaction for its whole duration, which is right for a pure-DB write
 * but wrong here: ShipmentEmailService::resend() does REAL network I/O
 * (the SMTP round-trip), and holding a DB transaction open for however
 * long that takes would needlessly hold locks/a connection during
 * something that can legitimately take seconds. Instead this method uses
 * Idempotency's own lower-level primitives directly: check-for-replay
 * (DB-only, fast) -> resend() OUTSIDE any transaction (network I/O;
 * ShipmentEmailService's own short internal transactions handle the
 * actual outbox-row update+audit write) -> record the result (DB-only,
 * fast) — never a nested/overlapping transaction, exactly the same shape
 * DispatchController::departures() already uses for the automatic first
 * send.
 */
final class ShipmentEmailController
{
    private const ENDPOINT = 'POST /api/admin/shipments/{shipmentId}/email/resend';

    public static function resend(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN');
        $shipmentId = (int) $request->routeParams['shipmentId'];

        $requestId = Idempotency::requireKey($request);
        $fingerprint = Idempotency::fingerprint($request);

        $replay = Idempotency::checkReplay($requestId, self::ENDPOINT, $fingerprint);
        if ($replay !== null) {
            http_response_code($replay['status']);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($replay['envelope'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        $service = new ShipmentEmailService();
        $result = $service->resend($shipmentId, $userId, $requestId);
        $envelope = ['ok' => true, 'data' => $result];

        Database::transaction(function (PDO $pdo) use ($requestId, $fingerprint, $envelope, $shipmentId) {
            Idempotency::record($pdo, $requestId, self::ENDPOINT, $fingerprint, 200, $envelope, 'shipment_email_delivery', (string) $shipmentId);
        });

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
