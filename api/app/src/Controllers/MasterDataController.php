<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Idempotency;
use Amor\Api\Request;
use Amor\Api\Services\MasterDataService;
use PDO;

/** Master Data add / edit / delete (Leader Admin only). See MasterDataService. */
final class MasterDataController
{
    public static function deleteProduct(Request $request): void { self::delete($request, 'product'); }
    public static function deleteStore(Request $request): void { self::delete($request, 'store'); }
    public static function deleteDivision(Request $request): void { self::delete($request, 'division'); }
    public static function deleteFactory(Request $request): void { self::delete($request, 'factory'); }

    /** DELETE /api/{products|stores|divisions|factories}/{id} */
    private static function delete(Request $request, string $entity): void
    {
        $userId = Auth::requireLeaderAdmin();
        $id = (int) $request->routeParams['id'];
        self::run($request, "DELETE /api/{$entity}/{id}", $entity, (string) $id, static function (PDO $pdo) use ($entity, $id, $userId, $request) {
            $out = (new MasterDataService($pdo))->delete($entity, $id);
            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, "{$entity}.delete", $entity, (string) $id, 'ok', null, null, null);
            return $out;
        });
    }

    /** POST /api/divisions — {name, factoryId, isVerification} */
    public static function createDivision(Request $request): void
    {
        $userId = Auth::requireLeaderAdmin();
        self::run($request, 'POST /api/divisions', 'division', 'new', static function (PDO $pdo) use ($request, $userId) {
            $d = (new MasterDataService($pdo))->createDivision((string) $request->input('name', ''), (int) $request->input('factoryId', 0), (bool) $request->input('isVerification', false));
            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, 'division.create', 'division', (string) $d['division_id'], 'ok', null, null, $d);
            return $d;
        });
    }

    /** PUT /api/divisions/{id} — {name} */
    public static function updateDivision(Request $request): void
    {
        $userId = Auth::requireLeaderAdmin();
        $id = (int) $request->routeParams['id'];
        self::run($request, 'PUT /api/divisions/{id}', 'division', (string) $id, static function (PDO $pdo) use ($request, $userId, $id) {
            $d = (new MasterDataService($pdo))->renameDivision($id, (string) $request->input('name', ''));
            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, 'division.update', 'division', (string) $id, 'ok', null, null, $d);
            return $d;
        });
    }

    /** POST /api/factories — {code, name} */
    public static function createFactory(Request $request): void
    {
        $userId = Auth::requireLeaderAdmin();
        self::run($request, 'POST /api/factories', 'factory', 'new', static function (PDO $pdo) use ($request, $userId) {
            $f = (new MasterDataService($pdo))->createFactory((string) $request->input('code', ''), (string) $request->input('name', ''));
            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, 'factory.create', 'factory', (string) $f['factory_id'], 'ok', null, null, $f);
            return $f;
        });
    }

    /** PUT /api/factories/{id} — {name} */
    public static function updateFactory(Request $request): void
    {
        $userId = Auth::requireLeaderAdmin();
        $id = (int) $request->routeParams['id'];
        self::run($request, 'PUT /api/factories/{id}', 'factory', (string) $id, static function (PDO $pdo) use ($request, $userId, $id) {
            $f = (new MasterDataService($pdo))->renameFactory($id, (string) $request->input('name', ''));
            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, 'factory.update', 'factory', (string) $id, 'ok', null, null, $f);
            return $f;
        });
    }

    /** PUT /api/store-aliases/{id} — {rawName, storeId, factoryHint} */
    public static function updateStoreAlias(Request $request): void
    {
        $userId = Auth::requireLeaderAdmin();
        $id = (int) $request->routeParams['id'];
        $hint = $request->input('factoryHint');
        self::run($request, 'PUT /api/store-aliases/{id}', 'store_alias', (string) $id, static function (PDO $pdo) use ($request, $userId, $id, $hint) {
            $a = (new MasterDataService($pdo))->updateStoreAlias($id, (string) $request->input('rawName', ''), (int) $request->input('storeId', 0), $hint === null || $hint === '' ? null : (string) $hint);
            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, 'store.alias.update', 'store_alias', (string) $id, 'ok', null, null, $a);
            return $a;
        });
    }

    /** DELETE /api/store-aliases/{id} */
    public static function deleteStoreAlias(Request $request): void
    {
        $userId = Auth::requireLeaderAdmin();
        $id = (int) $request->routeParams['id'];
        self::run($request, 'DELETE /api/store-aliases/{id}', 'store_alias', (string) $id, static function (PDO $pdo) use ($request, $userId, $id) {
            (new MasterDataService($pdo))->deleteStoreAlias($id);
            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, 'store.alias.delete', 'store_alias', (string) $id, 'ok', null, null, null);
            return ['deleted' => true];
        });
    }

    private static function run(Request $request, string $route, string $recordType, string $recordKey, callable $fn): void
    {
        Idempotency::handle($request, $route, static function (PDO $pdo) use ($fn, $recordType, $recordKey) {
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $fn($pdo)], 'recordType' => $recordType, 'recordKey' => $recordKey];
        });
    }
}
