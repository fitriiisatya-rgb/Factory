<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Idempotency;
use Amor\Api\Repositories\ProductRepository;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

final class ProductController
{
    public static function index(Request $request): void
    {
        Auth::requireAuth();
        $repo = new ProductRepository();
        $aktifParam = $request->query('aktif');
        $aktif = $aktifParam === null ? null : in_array($aktifParam, ['1', 'true'], true);

        $rows = $repo->findAll(
            \Amor\Api\Database::pdo(),
            $request->query('q'),
            $request->query('divisionId') !== null ? (int) $request->query('divisionId') : null,
            $aktif
        );
        Response::json($rows);
    }

    public static function create(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN', 'PPIC');

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            throw new ApiException(400, 'MISSING_NAME', 'name is required');
        }

        Idempotency::handle($request, 'POST /api/products', function (PDO $pdo) use ($request, $userId, $name) {
            $repo = new ProductRepository();
            $product = $repo->create(
                $pdo,
                $name,
                $request->input('kategori'),
                $request->input('divisionId') !== null ? (int) $request->input('divisionId') : null,
                (float) $request->input('hpp', 0),
                (float) $request->input('harga', 0),
                (bool) $request->input('aktif', true)
            );

            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, 'product.create', 'product', (string) $product['product_id'], 'ok', null, 1, $product);

            return [
                'status' => 201,
                'envelope' => ['ok' => true, 'data' => $product],
                'recordType' => 'product',
                'recordKey' => (string) $product['product_id'],
            ];
        });
    }

    public static function update(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN', 'PPIC');
        $id = (int) $request->routeParams['id'];

        $version = $request->input('version');
        if ($version === null) {
            throw new ApiException(400, 'MISSING_VERSION', 'version is required for this update');
        }

        Idempotency::handle($request, 'PUT /api/products/{id}', function (PDO $pdo) use ($request, $userId, $id, $version) {
            $repo = new ProductRepository();
            $body = $request->all();
            $inputToColumn = ['name' => 'name', 'kategori' => 'kategori', 'divisionId' => 'division_id', 'hpp' => 'hpp', 'harga' => 'harga', 'aktif' => 'aktif'];
            $mapped = [];
            foreach ($inputToColumn as $inputKey => $column) {
                if (array_key_exists($inputKey, $body)) {
                    $mapped[$column] = $body[$inputKey];
                }
            }

            $product = $repo->update($pdo, $id, (int) $version, $mapped);

            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, 'product.update', 'product', (string) $id, 'ok', (int) $version, $product['version'], $product);

            return [
                'status' => 200,
                'envelope' => ['ok' => true, 'data' => $product],
                'recordType' => 'product',
                'recordKey' => (string) $id,
            ];
        });
    }

    public static function addAlias(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN', 'PPIC');
        $productId = (int) $request->routeParams['id'];
        $rawName = trim((string) $request->input('rawName', ''));
        if ($rawName === '') {
            throw new ApiException(400, 'MISSING_RAW_NAME', 'rawName is required');
        }

        Idempotency::handle($request, 'POST /api/products/{id}/aliases', function (PDO $pdo) use ($request, $userId, $productId, $rawName) {
            $repo = new ProductRepository();
            $alias = $repo->addAlias($pdo, $productId, $rawName, 'manual');

            Audit::write($pdo, $request->header('Idempotency-Key'), $userId, 'product.alias.create', 'product_alias', (string) $alias['productAliasId'], 'ok', null, null, $alias);

            return [
                'status' => 201,
                'envelope' => ['ok' => true, 'data' => $alias],
                'recordType' => 'product_alias',
                'recordKey' => (string) $alias['productAliasId'],
            ];
        });
    }
}
