<?php

declare(strict_types=1);

namespace Amor\Api;

use PDO;

/**
 * idempotency_log behavior per docs/mysql-schema-v1.md §13:
 * same request_id + same fingerprint -> replay stored response.
 * same request_id + different fingerprint -> 409 IDEMPOTENCY_KEY_REUSE_MISMATCH.
 * Retention: 90 days (cleanup job not built in Phase 0 — see OD-6/§9 in the design docs).
 */
final class Idempotency
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Call at the top of a mutating handler. Throws ApiException(400) if the
     * header is missing, or replays a stored response by throwing a special
     * ReplayResponse signal the front controller catches and emits verbatim.
     */
    public static function requireKey(Request $request): string
    {
        if (!in_array($request->method, self::MUTATING_METHODS, true)) {
            return '';
        }
        $key = $request->header('Idempotency-Key');
        if ($key === null || trim($key) === '') {
            throw new ApiException(400, 'MISSING_IDEMPOTENCY_KEY', 'Idempotency-Key header is required');
        }
        return $key;
    }

    public static function fingerprint(Request $request): string
    {
        return hash('sha256', $request->method . ' ' . $request->path . ' ' . $request->rawBodyForFingerprint());
    }

    /**
     * @return array{status:int,envelope:array}|null Stored response to replay, or null if this is a fresh key.
     */
    public static function checkReplay(string $requestId, string $endpoint, string $fingerprint): ?array
    {
        if ($requestId === '') {
            return null;
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT request_fingerprint, response_body FROM idempotency_log WHERE request_id = ?'
        );
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        if (!hash_equals($row['request_fingerprint'], $fingerprint)) {
            throw new ApiException(409, 'IDEMPOTENCY_KEY_REUSE_MISMATCH', 'Idempotency-Key was reused with a different request');
        }

        $stored = json_decode($row['response_body'], true);
        return ['status' => (int) $stored['httpStatus'], 'envelope' => $stored['envelope']];
    }

    /**
     * Persists the response for this key. Call inside the same DB transaction
     * as the write it protects, so a rollback undoes both together.
     *
     * The idempotency_log.response_status column is the coarse
     * ENUM('ok','error','conflict') the schema defines; the exact HTTP
     * status is preserved inside response_body (under "httpStatus") so a
     * replay can reproduce it precisely, since the enum alone can't.
     */
    public static function record(
        PDO $pdo,
        string $requestId,
        string $endpoint,
        string $fingerprint,
        int $httpStatus,
        array $envelope,
        ?string $recordType = null,
        ?string $recordKey = null
    ): void {
        if ($requestId === '') {
            return;
        }
        $responseStatus = match (true) {
            $httpStatus === 409 => 'conflict',
            $httpStatus >= 400 => 'error',
            default => 'ok',
        };
        $stmt = $pdo->prepare(
            'INSERT INTO idempotency_log
                (request_id, endpoint, request_fingerprint, response_status, response_body, record_type, record_key, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $requestId,
            $endpoint,
            $fingerprint,
            $responseStatus,
            json_encode(['httpStatus' => $httpStatus, 'envelope' => $envelope], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $recordType,
            $recordKey,
        ]);
    }

    /**
     * Full mutating-endpoint flow: replay if this key was seen before with the
     * same payload, 409 if reused with a different payload, otherwise run
     * $work in one transaction and persist its response for future replay.
     *
     * $work receives the PDO connection (already inside a transaction) and
     * must return ['status' => int, 'envelope' => array (the exact JSON body
     * to send), 'recordType' => ?string, 'recordKey' => ?string].
     */
    // Note on error paths: if $work throws (ApiException or otherwise), Database::transaction
    // rolls back and the exception propagates — no idempotency_log row is written for that
    // attempt. That's intentional: nothing committed, so there is nothing that needs replay
    // protection, and a retry is free to re-evaluate the same business rule (e.g. a since-fixed
    // VERSION_CONFLICT) rather than being stuck replaying a stale failure forever.
    public static function handle(Request $request, string $endpoint, callable $work): void
    {
        $requestId = self::requireKey($request);
        $fingerprint = self::fingerprint($request);

        $replay = self::checkReplay($requestId, $endpoint, $fingerprint);
        if ($replay !== null) {
            http_response_code($replay['status']);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($replay['envelope'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        $result = Database::transaction(function (PDO $pdo) use ($work, $requestId, $endpoint, $fingerprint) {
            $result = $work($pdo);
            self::record(
                $pdo,
                $requestId,
                $endpoint,
                $fingerprint,
                $result['status'],
                $result['envelope'],
                $result['recordType'] ?? null,
                $result['recordKey'] ?? null
            );
            return $result;
        });

        http_response_code($result['status']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result['envelope'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
