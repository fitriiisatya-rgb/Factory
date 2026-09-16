<?php

declare(strict_types=1);

namespace Amor\Api\Controllers\Admin;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Idempotency;
use Amor\Api\Repositories\MigrationMapRepository;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

/**
 * Admin review API for migration_product_map / migration_store_map
 * (Phase 1, item 10). ADMIN only. Every mutating action is transactional,
 * idempotency-key protected, and audited. No endpoint here can set
 * status='mapped' from a fuzzy match — resolve() always requires an
 * explicit targetId supplied by the caller.
 */
final class MigrationController
{
    public static function indexProducts(Request $request): void
    {
        self::index($request, 'product');
    }

    public static function indexStores(Request $request): void
    {
        self::index($request, 'store');
    }

    private static function index(Request $request, string $kind): void
    {
        Auth::requireRole('ADMIN');
        $repo = new MigrationMapRepository();
        $rows = $repo->findAll(Database::pdo(), $kind, $request->query('status'), $request->query('q'));
        Response::json($rows);
    }

    public static function resolveProduct(Request $request): void
    {
        self::resolve($request, 'product');
    }

    public static function resolveStore(Request $request): void
    {
        self::resolve($request, 'store');
    }

    private static function resolve(Request $request, string $kind): void
    {
        $userId = Auth::requireRole('ADMIN');
        $id = (int) $request->routeParams['id'];
        $targetId = $request->input('targetId');
        if ($targetId === null) {
            throw new ApiException(400, 'MISSING_TARGET_ID', 'targetId is required');
        }

        Idempotency::handle($request, "POST /api/admin/migration/{$kind}s/{id}/resolve", function (PDO $pdo) use ($request, $userId, $kind, $id, $targetId) {
            $repo = new MigrationMapRepository();
            $resolvedByLabel = (string) ($_SESSION['username'] ?? ('user#' . $userId));

            $row = $repo->resolve($pdo, $kind, $id, (int) $targetId, $request->input('notes'), $resolvedByLabel);

            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, "migration.{$kind}.resolve", "migration_{$kind}_map", (string) $id, 'ok', null, null, $row);

            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $row],
                'recordType' => "migration_{$kind}_map",
                'recordKey' => (string) $id,
            ];
        });
    }

    public static function flagConflictProduct(Request $request): void
    {
        self::flagConflict($request, 'product');
    }

    public static function flagConflictStore(Request $request): void
    {
        self::flagConflict($request, 'store');
    }

    private static function flagConflict(Request $request, string $kind): void
    {
        $userId = Auth::requireRole('ADMIN');
        $id = (int) $request->routeParams['id'];
        $notes = trim((string) $request->input('notes', ''));
        if ($notes === '') {
            throw new ApiException(400, 'MISSING_NOTES', 'notes is required to flag a conflict');
        }

        Idempotency::handle($request, "POST /api/admin/migration/{$kind}s/{id}/flag-conflict", function (PDO $pdo) use ($request, $userId, $kind, $id, $notes) {
            $repo = new MigrationMapRepository();
            $resolvedByLabel = (string) ($_SESSION['username'] ?? ('user#' . $userId));

            $row = $repo->flagConflict($pdo, $kind, $id, $notes, $resolvedByLabel);

            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, "migration.{$kind}.flag_conflict", "migration_{$kind}_map", (string) $id, 'ok', null, null, $row);

            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $row],
                'recordType' => "migration_{$kind}_map",
                'recordKey' => (string) $id,
            ];
        });
    }
}
