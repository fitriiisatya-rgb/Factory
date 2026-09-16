<?php

declare(strict_types=1);

namespace Amor\Api;

/**
 * Access control for the optional public/_setup/*.php web fallback runners
 * (Phase 0.5, section 8 — for cPanel accounts with no SSH/Terminal access).
 * Every _setup script calls SetupGuard::authorize() before doing anything
 * else. All failures respond identically (plain 403, generic message) so an
 * unauthorized caller learns nothing about which check failed.
 */
final class SetupGuard
{
    public static function authorize(): void
    {
        $env = (string) Config::get('APP_ENV');
        if (in_array($env, ['production', 'live'], true)) {
            self::deny();
        }

        $token = (string) Config::get('SETUP_TOKEN', '');
        if ($token === '') {
            // Not configured at all = the operator has not opted into this fallback.
            self::deny();
        }

        $provided = $_GET['token'] ?? $_POST['token'] ?? '';
        if (!is_string($provided) || $provided === '' || !hash_equals($token, $provided)) {
            self::deny();
        }

        $dbName = (string) Config::get('DB_NAME');
        $expected = (string) Config::get('EXPECTED_DB_NAME', '');
        if ($expected === '' || $expected !== $dbName) {
            self::deny();
        }
    }

    private static function deny(): never
    {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden\n";
        exit;
    }
}
