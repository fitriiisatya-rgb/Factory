<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Dashboard\DashboardService;
use Amor\Api\Database;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\Delivery\DoService;
use Amor\Api\Request;
use Amor\Api\Response;

/**
 * READ-ONLY dashboard aggregation endpoint for the redesigned UI. Never
 * writes transactional data (see DashboardService's own docblock) — this
 * is purely an additive convenience route, safe to remove without
 * affecting any Phase 1-5 document.
 */
final class DashboardController
{
    public static function summary(Request $request): void
    {
        Auth::requireAuth();
        $tanggal = (string) $request->query('date');
        $d = \DateTime::createFromFormat('Y-m-d', $tanggal);
        if ($d === false || $d->format('Y-m-d') !== $tanggal) {
            throw new ApiException(400, 'INVALID_DATE', "date must be a valid 'YYYY-MM-DD' date");
        }
        $factoryId = $request->query('factoryId');
        if ($factoryId === null || $factoryId === '' || !is_numeric($factoryId)) {
            throw new ApiException(400, 'MISSING_FIELD', 'factoryId is required');
        }

        $pdo = Database::pdo();
        $service = new DashboardService($pdo, new DoService($pdo), new DoRepository());
        Response::json($service->summary($tanggal, (int) $factoryId));
    }
}
