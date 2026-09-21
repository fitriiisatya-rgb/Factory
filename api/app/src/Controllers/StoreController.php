<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Idempotency;
use Amor\Api\Repositories\StoreRepository;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

final class StoreController
{
    public static function index(Request $request): void
    {
        Auth::requireAuth();
        $repo = new StoreRepository();
        $activeParam = $request->query('active');
        $active = $activeParam === null ? null : in_array($activeParam, ['1', 'true'], true);

        $rows = $repo->findAll(\Amor\Api\Database::pdo(), $request->query('q'), $request->query('channel'), $active);
        Response::json($rows);
    }

    public static function show(Request $request): void
    {
        Auth::requireAuth();
        $id = (int) $request->routeParams['id'];
        $store = (new StoreRepository())->findById(\Amor\Api\Database::pdo(), $id);
        if ($store === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Store not found');
        }
        Response::json($store);
    }

    public static function create(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN');

        $name = trim((string) $request->input('canonicalName', ''));
        if ($name === '') {
            throw new ApiException(400, 'MISSING_CANONICAL_NAME', 'canonicalName is required');
        }

        Idempotency::handle($request, 'POST /api/stores', function (PDO $pdo) use ($request, $userId, $name) {
            $repo = new StoreRepository();
            $store = $repo->create($pdo, $name, $request->input('channel'));

            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, 'store.create', 'store', (string) $store['store_id'], 'ok', null, 1, $store);

            return [
                'status' => 201,
                'envelope' => ['ok' => true, 'data' => $store],
                'recordType' => 'store',
                'recordKey' => (string) $store['store_id'],
            ];
        });
    }

    public static function update(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN');
        $id = (int) $request->routeParams['id'];

        $version = $request->input('version');
        if ($version === null) {
            throw new ApiException(400, 'MISSING_VERSION', 'version is required for this update');
        }

        // Real-UAT finalization ask: Master Store must be able to hold one
        // official receiving email per Bakery, used by the automatic
        // shipment-departure email. Validated here (never at the mail-send
        // layer) so a typo is caught at edit time, not silently swallowed
        // as a later send failure. An empty string clears the field back
        // to NULL — a store can legitimately have no email yet.
        if (array_key_exists('email', $request->all())) {
            $rawEmail = trim((string) $request->input('email', ''));
            if ($rawEmail !== '' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new ApiException(400, 'INVALID_EMAIL_FORMAT', 'Format email tidak valid');
            }
        }

        Idempotency::handle($request, 'PUT /api/stores/{id}', function (PDO $pdo) use ($request, $userId, $id, $version) {
            $repo = new StoreRepository();
            $mapped = [];
            if ($request->input('canonicalName') !== null) $mapped['canonical_name'] = $request->input('canonicalName');
            if (array_key_exists('channel', $request->all())) $mapped['channel'] = $request->input('channel');
            if (array_key_exists('active', $request->all())) $mapped['active'] = $request->input('active');
            if (array_key_exists('email', $request->all())) {
                $rawEmail = trim((string) $request->input('email', ''));
                $mapped['email'] = $rawEmail !== '' ? $rawEmail : null;
            }

            $store = $repo->update($pdo, $id, (int) $version, $mapped);

            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, 'store.update', 'store', (string) $id, 'ok', (int) $version, $store['version'], $store);

            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $store],
                'recordType' => 'store',
                'recordKey' => (string) $id,
            ];
        });
    }

    public static function addAlias(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN');
        $storeId = (int) $request->routeParams['id'];
        $rawName = trim((string) $request->input('rawName', ''));
        if ($rawName === '') {
            throw new ApiException(400, 'MISSING_RAW_NAME', 'rawName is required');
        }

        Idempotency::handle($request, 'POST /api/stores/{id}/aliases', function (PDO $pdo) use ($request, $userId, $storeId, $rawName) {
            $repo = new StoreRepository();
            $alias = $repo->addAlias($pdo, $storeId, $rawName, $request->input('factoryHint'));

            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, 'store.alias.create', 'store_alias', (string) $alias['storeAliasId'], 'ok', null, null, $alias);

            return [
                'status' => 201,
                'envelope' => ['ok' => true, 'data' => $alias],
                'recordType' => 'store_alias',
                'recordKey' => (string) $alias['storeAliasId'],
            ];
        });
    }
}
