<?php

declare(strict_types=1);

namespace Amor\Api\Repositories;

use Amor\Api\ApiException;
use Amor\Api\Versioning;
use PDO;
use PDOException;

final class StoreRepository
{
    public function findAll(PDO $pdo, ?string $q, ?string $channel, ?bool $active): array
    {
        $sql = 'SELECT store_id, canonical_name, channel, active, version, created_at, updated_at FROM store WHERE 1=1';
        $params = [];

        if ($q !== null && $q !== '') {
            $sql .= ' AND (canonical_name LIKE ? OR store_id IN (SELECT store_id FROM store_alias WHERE raw_name LIKE ?))';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($channel !== null) {
            $sql .= ' AND channel = ?';
            $params[] = $channel;
        }
        if ($active !== null) {
            $sql .= ' AND active = ?';
            $params[] = $active ? 1 : 0;
        }
        $sql .= ' ORDER BY canonical_name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT store_id, canonical_name, channel, active, version, created_at, updated_at FROM store WHERE store_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByCanonicalName(PDO $pdo, string $name): ?array
    {
        $stmt = $pdo->prepare('SELECT store_id, canonical_name, channel, active, version, created_at, updated_at FROM store WHERE canonical_name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @throws ApiException 409 DUPLICATE_STORE_NAME */
    public function create(PDO $pdo, string $canonicalName, ?string $channel): array
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO store (canonical_name, channel, active, version, created_at) VALUES (?, ?, 1, 1, UTC_TIMESTAMP())'
            );
            $stmt->execute([$canonicalName, $channel]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new ApiException(409, 'DUPLICATE_STORE_NAME', 'A store with this canonical name already exists');
            }
            throw $e;
        }

        return $this->findById($pdo, (int) $pdo->lastInsertId());
    }

    /** @throws ApiException 409 VERSION_CONFLICT | 404 NOT_FOUND */
    public function update(PDO $pdo, int $id, int $expectedVersion, array $fields): array
    {
        if ($this->findById($pdo, $id) === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Store not found');
        }

        $columns = ['canonical_name', 'channel', 'active'];
        $set = [];
        $params = [];
        foreach ($columns as $col) {
            if (array_key_exists($col, $fields)) {
                $set[] = "{$col} = ?";
                $params[] = $col === 'active' ? (($fields[$col]) ? 1 : 0) : $fields[$col];
            }
        }
        if ($set === []) {
            throw new ApiException(400, 'NO_FIELDS_TO_UPDATE', 'No updatable fields supplied');
        }

        try {
            Versioning::update($pdo, 'store', 'store_id', $id, $expectedVersion, implode(', ', $set), $params);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new ApiException(409, 'DUPLICATE_STORE_NAME', 'A store with this canonical name already exists');
            }
            throw $e;
        }

        return $this->findById($pdo, $id);
    }

    /** @throws ApiException 409 ALIAS_ALREADY_MAPPED */
    public function addAlias(PDO $pdo, int $storeId, string $rawName, ?string $factoryHint): array
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO store_alias (store_id, raw_name, factory_hint, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([$storeId, $rawName, $factoryHint]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new ApiException(409, 'ALIAS_ALREADY_MAPPED', 'This raw name already resolves to a different store');
            }
            throw $e;
        }

        return ['storeAliasId' => (int) $pdo->lastInsertId(), 'storeId' => $storeId, 'rawName' => $rawName];
    }
}
