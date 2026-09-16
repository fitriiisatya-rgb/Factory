<?php

declare(strict_types=1);

namespace Amor\Api;

/**
 * Loads config/config.php (gitignored, real values) if present, then lets
 * any real environment variable of the same name override it. Throws on
 * boot if a required key is missing — no silent defaulting of DB
 * credentials, ever.
 */
final class Config
{
    private static ?array $values = null;

    private const REQUIRED = ['APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    public static function load(): void
    {
        if (self::$values !== null) {
            return;
        }

        $file = __DIR__ . '/../config/config.php';
        $base = is_file($file) ? require $file : [];
        if (!is_array($base)) {
            throw new \RuntimeException('config/config.php must return an array');
        }

        $defaults = [
            'APP_ENV' => 'staging',
            'APP_DEBUG' => false,
            'APP_TIMEZONE' => 'Asia/Jakarta',
            'DB_PORT' => '3306',
            'SESSION_SECURE' => true,
            'SESSION_SAMESITE' => 'Lax',
            'SESSION_LIFETIME_SECONDS' => 8 * 3600,
        ];

        $values = array_merge($defaults, $base);

        foreach (array_keys($values) as $key) {
            $env = getenv($key);
            if ($env !== false && $env !== '') {
                $values[$key] = $env;
            }
        }
        // Allow env-only keys that were never in config.php/defaults (e.g. CI).
        foreach (self::REQUIRED as $key) {
            $env = getenv($key);
            if ($env !== false && $env !== '' && !isset($values[$key])) {
                $values[$key] = $env;
            }
        }

        $values['APP_DEBUG'] = self::toBool($values['APP_DEBUG']);
        $values['SESSION_SECURE'] = self::toBool($values['SESSION_SECURE']);

        // DB_PASS may legitimately be an empty string (e.g. a local root account with no
        // password during disposable testing) — only require that the key was actually
        // set, not that it's non-empty. Every other required key must be non-empty.
        $missing = [];
        foreach (self::REQUIRED as $key) {
            if ($key === 'DB_PASS') {
                if (!array_key_exists($key, $values)) {
                    $missing[] = $key;
                }
                continue;
            }
            if (!isset($values[$key]) || $values[$key] === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException(
                'Missing required config: ' . implode(', ', $missing)
                . '. Copy api/config/config.example.php to api/config/config.php and fill it in,'
                . ' or set these as environment variables.'
            );
        }

        if ($values['APP_ENV'] === 'production') {
            // Phase 0 explicitly never runs against production. This is a hard stop,
            // not a warning, so a misconfigured deploy cannot silently point at prod.
            throw new \RuntimeException(
                'APP_ENV=production is not permitted in this Phase 0 skeleton. '
                . 'This codebase has not been through a production-readiness review.'
            );
        }

        self::$values = $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$values[$key] ?? $default;
    }

    public static function all(): array
    {
        self::load();
        return self::$values;
    }

    private static function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }
}
