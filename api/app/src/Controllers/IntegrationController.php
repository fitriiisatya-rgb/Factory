<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Integration\IntegrationKeyService;
use Amor\Api\Integration\ShipmentFeedService;
use Amor\Api\Request;
use Amor\Api\Response;

/**
 * Integration API for finhub. Admin key management needs an ADMIN
 * session; the feed itself is authenticated only by an integration key
 * (header X-Api-Key), never a session, and is strictly read-only.
 */
final class IntegrationController
{
    public static function keyList(Request $request): void
    {
        Auth::requireLeaderAdmin();
        Response::json((new IntegrationKeyService(Database::pdo()))->list());
    }

    /** POST — returns the raw key ONCE. */
    public static function keyIssue(Request $request): void
    {
        $userId = Auth::requireLeaderAdmin();
        $service = new IntegrationKeyService(Database::pdo());
        Response::json($service->issue((string) $request->input('name', ''), $userId, $request->header('Idempotency-Key')));
    }

    public static function keyRevoke(Request $request): void
    {
        $userId = Auth::requireLeaderAdmin();
        $service = new IntegrationKeyService(Database::pdo());
        Response::json($service->revoke((int) $request->routeParams['id'], $userId, $request->header('Idempotency-Key')));
    }

    /** GET /api/integration/v1/shipments?from=YYYY-MM-DD&to=YYYY-MM-DD[&storeId=] */
    public static function shipments(Request $request): void
    {
        $pdo = Database::pdo();
        $raw = $request->header('X-Api-Key');
        if ($raw === null) {
            $auth = (string) $request->header('Authorization');
            if (str_starts_with($auth, 'Bearer ')) {
                $raw = substr($auth, 7);
            }
        }
        (new IntegrationKeyService($pdo))->authenticate($raw);
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', $from);
        $storeId = $request->query('storeId');
        Response::json((new ShipmentFeedService($pdo))->list($from, $to, $storeId !== null && $storeId !== '' ? (int) $storeId : null));
    }
}
