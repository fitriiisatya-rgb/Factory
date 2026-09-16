<?php

declare(strict_types=1);

namespace Amor\Api;

use PDO;

/**
 * Every write produces an audit_log row, in the same transaction where
 * practical (docs/php-api-contract-v1.md §1 rule 7).
 */
final class Audit
{
    public static function write(
        PDO $pdo,
        ?string $requestId,
        ?int $userId,
        string $action,
        string $recordType,
        string $recordKey,
        string $status,
        ?int $previousVersion = null,
        ?int $newVersion = null,
        ?array $payloadSummary = null
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log
                (request_id, event_at, user_id, action, record_type, record_key, previous_version, new_version, payload_summary, status)
             VALUES (?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $requestId,
            $userId,
            $action,
            $recordType,
            $recordKey,
            $previousVersion,
            $newVersion,
            $payloadSummary !== null
                ? substr(json_encode($payloadSummary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 2000)
                : null,
            $status,
        ]);
    }
}
