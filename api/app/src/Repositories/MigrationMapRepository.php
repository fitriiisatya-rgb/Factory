<?php

declare(strict_types=1);

namespace Amor\Api\Repositories;

use Amor\Api\ApiException;
use PDO;

/**
 * Admin review repository for migration_product_map / migration_store_map
 * (docs/mysql-schema-v1.md §4, review point 2's explicit ask). Human-only
 * resolution — no method here ever sets status='mapped' based on a
 * similarity score; resolve() always requires an explicit targetId from
 * the caller (the authenticated admin), never computes one itself.
 */
final class MigrationMapRepository
{
    private const KINDS = [
        'product' => ['table' => 'migration_product_map', 'targetTable' => 'product', 'targetIdCol' => 'product_id'],
        'store' => ['table' => 'migration_store_map', 'targetTable' => 'store', 'targetIdCol' => 'store_id'],
    ];

    private function config(string $kind): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException("Unknown migration map kind: {$kind}");
        }
        return self::KINDS[$kind];
    }

    public function findAll(PDO $pdo, string $kind, ?string $status, ?string $q): array
    {
        $table = $this->config($kind)['table'];
        $sql = "SELECT id, raw_name, raw_code, source_table, occurrence_count, target_id, status, resolved_by, resolved_at, notes, created_at FROM {$table} WHERE 1=1";
        $params = [];

        if ($status !== null && $status !== '') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        if ($q !== null && $q !== '') {
            $sql .= ' AND (raw_name LIKE ? OR raw_code LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY occurrence_count DESC, raw_name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findById(PDO $pdo, string $kind, int $id): ?array
    {
        $table = $this->config($kind)['table'];
        $stmt = $pdo->prepare("SELECT id, raw_name, raw_code, source_table, occurrence_count, target_id, status, resolved_by, resolved_at, notes, created_at FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @throws ApiException 404 if the mapping row or the target entity doesn't exist,
     *                       409 CONFLICT_ROW_ALREADY_MAPPED if it's already resolved
     */
    public function resolve(PDO $pdo, string $kind, int $id, int $targetId, ?string $notes, string $resolvedBy): array
    {
        $cfg = $this->config($kind);

        $row = $this->findById($pdo, $kind, $id);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Migration mapping row not found');
        }
        if ($row['status'] === 'mapped') {
            throw new ApiException(409, 'ALREADY_MAPPED', 'This mapping was already resolved', ['currentTargetId' => $row['target_id']]);
        }

        $stmt = $pdo->prepare("SELECT {$cfg['targetIdCol']} FROM {$cfg['targetTable']} WHERE {$cfg['targetIdCol']} = ?");
        $stmt->execute([$targetId]);
        if ($stmt->fetchColumn() === false) {
            throw new ApiException(404, 'TARGET_NOT_FOUND', "No {$kind} exists with id {$targetId}");
        }

        $stmt = $pdo->prepare(
            "UPDATE {$cfg['table']} SET target_id = ?, status = 'mapped', resolved_by = ?, resolved_at = UTC_TIMESTAMP(), notes = ? WHERE id = ?"
        );
        $stmt->execute([$targetId, $resolvedBy, $notes, $id]);

        return $this->findById($pdo, $kind, $id);
    }

    /**
     * @throws ApiException 404 if not found, 409 if already resolved
     */
    public function flagConflict(PDO $pdo, string $kind, int $id, string $notes, string $resolvedBy): array
    {
        $cfg = $this->config($kind);
        $row = $this->findById($pdo, $kind, $id);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Migration mapping row not found');
        }
        if ($row['status'] === 'mapped') {
            throw new ApiException(409, 'ALREADY_MAPPED', 'This mapping was already resolved');
        }

        $stmt = $pdo->prepare(
            "UPDATE {$cfg['table']} SET status = 'conflict', resolved_by = ?, notes = ? WHERE id = ?"
        );
        $stmt->execute([$resolvedBy, $notes, $id]);

        return $this->findById($pdo, $kind, $id);
    }
}
