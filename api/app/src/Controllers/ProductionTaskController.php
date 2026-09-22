<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Production\ProductionTaskService;
use Amor\Api\Request;
use Amor\Api\Response;

/**
 * JSON API for "Task per Divisi" — READ-ONLY (see ProductionTaskService's
 * own docblock for why: it is a consolidated view over data that already
 * has its own write paths elsewhere — Ceklis Produksi for PO Reguler,
 * POST /api/special-orders/{id}/actual for Pesanan Khusus/Non-Toko).
 */
final class ProductionTaskController
{
    public static function forDivision(Request $request): void
    {
        Auth::requireAuth();
        $tanggal = self::requireDate($request->query('tanggal'));
        $divisionId = self::requireInt($request->query('divisionId'), 'divisionId');
        $sourceFilter = $request->query('sourceType');
        $statusFilter = $request->query('status');

        $service = new ProductionTaskService(Database::pdo());
        Response::json($service->tasksForDivision($tanggal, $divisionId, $sourceFilter, $statusFilter));
    }

    public static function forFactory(Request $request): void
    {
        Auth::requireAuth();
        $tanggal = self::requireDate($request->query('tanggal'));
        $factoryId = self::requireInt($request->query('factoryId'), 'factoryId');

        $service = new ProductionTaskService(Database::pdo());
        Response::json($service->tasksForFactory($tanggal, $factoryId));
    }

    private static function requireDate(?string $s): string
    {
        $s = (string) $s;
        $d = \DateTime::createFromFormat('Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s) {
            throw new ApiException(400, 'INVALID_DATE', "tanggal must be a valid 'YYYY-MM-DD' date");
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
