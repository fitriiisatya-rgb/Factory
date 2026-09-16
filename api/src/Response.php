<?php

declare(strict_types=1);

namespace Amor\Api;

/**
 * Success/error envelope per docs/php-api-contract-v1.md §1 rule 9:
 * {"ok": true, "data": ...} or {"ok": false, "code": "...", "message": "..."}.
 */
final class Response
{
    public static function json(mixed $data, int $status = 200, array $meta = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $body = ['ok' => true, 'data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function noContent(): void
    {
        http_response_code(204);
    }

    public static function error(int $status, string $code, string $message, array $extra = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            array_merge(['ok' => false, 'code' => $code, 'message' => $message], $extra),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
