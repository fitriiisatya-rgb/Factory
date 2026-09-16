<?php

declare(strict_types=1);

namespace Amor\Api;

use PDO;

/**
 * Reusable optimistic-concurrency pattern (docs/php-api-contract-v1.md §1 rule 5):
 *   UPDATE ... SET ..., version = version + 1 WHERE id = ? AND version = ?
 * 0 rows affected -> 409 VERSION_CONFLICT with {currentVersion} so the client
 * can re-fetch and retry. There is no "version omitted" compatibility mode —
 * callers must pass an explicit expected version.
 */
final class Versioning
{
    /**
     * @param string $setClause e.g. "name = ?, aktif = ?" — never include "version" here,
     *                           it is always appended by this method.
     * @param list<mixed> $setParams bound in the same order as $setClause's placeholders
     * @return int the new version after a successful update
     * @throws ApiException 409 VERSION_CONFLICT
     */
    public static function update(
        PDO $pdo,
        string $table,
        string $idColumn,
        int $id,
        int $expectedVersion,
        string $setClause,
        array $setParams
    ): int {
        $sql = "UPDATE {$table} SET {$setClause}, version = version + 1, updated_at = UTC_TIMESTAMP() "
             . "WHERE {$idColumn} = ? AND version = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([...$setParams, $id, $expectedVersion]);

        if ($stmt->rowCount() === 0) {
            $current = self::currentVersion($pdo, $table, $idColumn, $id);
            throw new ApiException(409, 'VERSION_CONFLICT', 'The record was modified by someone else', [
                'currentVersion' => $current,
            ]);
        }

        return $expectedVersion + 1;
    }

    public static function currentVersion(PDO $pdo, string $table, string $idColumn, int $id): ?int
    {
        $stmt = $pdo->prepare("SELECT version FROM {$table} WHERE {$idColumn} = ?");
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }
}
