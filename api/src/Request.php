<?php

declare(strict_types=1);

namespace Amor\Api;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    /** @var array<string,string> */
    public array $routeParams = [];
    /** @var array<string,mixed> */
    private array $body;
    /** @var array<string,string> */
    private array $query;

    public function __construct(?string $method = null, ?string $path = null, ?string $rawBody = null)
    {
        $this->method = strtoupper($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = $path ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $this->path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') ?: '/';
        $this->query = $_GET ?? [];

        $raw = $rawBody ?? file_get_contents('php://input') ?: '';
        if ($raw === '') {
            $this->body = [];
        } else {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ApiException(400, 'INVALID_JSON', 'Request body is not valid JSON');
            }
            $this->body = is_array($decoded) ? $decoded : [];
        }
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'Content-Type') {
            return $_SERVER['CONTENT_TYPE'] ?? null;
        }
        return $_SERVER[$key] ?? null;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function rawBodyForFingerprint(): string
    {
        // Canonical (key-sorted) JSON so field order never changes the idempotency fingerprint.
        $body = $this->body;
        self::ksortRecursive($body);
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function ksortRecursive(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                self::ksortRecursive($v);
            }
        }
    }
}
