<?php

declare(strict_types=1);

namespace Amor\Api\Repositories;

use Amor\Api\ApiException;
use Amor\Api\Versioning;
use PDO;
use PDOException;

final class ProductRepository
{
    public function findAll(PDO $pdo, ?string $q, ?int $divisionId, ?bool $aktif): array
    {
        $sql = 'SELECT product_id, name, kategori, division_id, hpp, harga, aktif, version, created_at, updated_at FROM product WHERE 1=1';
        $params = [];

        if ($q !== null && $q !== '') {
            $sql .= ' AND (name LIKE ? OR product_id IN (SELECT product_id FROM product_alias WHERE raw_name LIKE ?))';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($divisionId !== null) {
            $sql .= ' AND division_id = ?';
            $params[] = $divisionId;
        }
        if ($aktif !== null) {
            $sql .= ' AND aktif = ?';
            $params[] = $aktif ? 1 : 0;
        }
        $sql .= ' ORDER BY name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @throws ApiException 409 DUPLICATE_PRODUCT_NAME */
    public function create(PDO $pdo, string $name, ?string $kategori, ?int $divisionId, float $hpp, float $harga, bool $aktif): array
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO product (name, kategori, division_id, hpp, harga, aktif, version, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())'
            );
            $stmt->execute([$name, $kategori, $divisionId, $hpp, $harga, $aktif ? 1 : 0]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new ApiException(409, 'DUPLICATE_PRODUCT_NAME', 'A product with this name already exists', [
                    'hint' => 'Use POST /api/products/{id}/aliases if this is meant to be an alias of an existing product',
                ]);
            }
            throw $e;
        }

        return $this->findById($pdo, (int) $pdo->lastInsertId());
    }

    public function findById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT product_id, name, kategori, division_id, hpp, harga, aktif, version, created_at, updated_at FROM product WHERE product_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @throws ApiException 409 VERSION_CONFLICT | 404 NOT_FOUND | 409 DUPLICATE_PRODUCT_NAME */
    public function update(PDO $pdo, int $id, int $expectedVersion, array $fields): array
    {
        if ($this->findById($pdo, $id) === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Product not found');
        }

        $columns = ['name', 'kategori', 'division_id', 'hpp', 'harga', 'aktif'];
        $set = [];
        $params = [];
        foreach ($columns as $col) {
            if (array_key_exists($col, $fields)) {
                $set[] = "{$col} = ?";
                $params[] = $col === 'aktif' ? (($fields[$col]) ? 1 : 0) : $fields[$col];
            }
        }
        if ($set === []) {
            throw new ApiException(400, 'NO_FIELDS_TO_UPDATE', 'No updatable fields supplied');
        }

        try {
            Versioning::update($pdo, 'product', 'product_id', $id, $expectedVersion, implode(', ', $set), $params);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new ApiException(409, 'DUPLICATE_PRODUCT_NAME', 'A product with this name already exists');
            }
            throw $e;
        }

        return $this->findById($pdo, $id);
    }

    /** @throws ApiException 409 ALIAS_ALREADY_MAPPED */
    public function addAlias(PDO $pdo, int $productId, string $rawName, ?string $source): array
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO product_alias (product_id, raw_name, source, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([$productId, $rawName, $source]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new ApiException(409, 'ALIAS_ALREADY_MAPPED', 'This raw name already resolves to a different product');
            }
            throw $e;
        }

        return ['productAliasId' => (int) $pdo->lastInsertId(), 'productId' => $productId, 'rawName' => $rawName];
    }
}
