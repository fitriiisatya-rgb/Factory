<?php

declare(strict_types=1);

namespace Amor\Api;

/**
 * Access control for the optional public/_setup/index.php web setup page
 * (Phase 0.5, cPanel easy-install package — for accounts with no SSH/
 * Terminal access, or an operator who prefers a guided UI). The page calls
 * SetupGuard::requireEnabled() first (404 if the tool has been disabled —
 * pretend it doesn't exist), then SetupGuard::authorize() (403 on a bad
 * token/env/db-name — the tool exists, but this caller isn't allowed to use
 * it). Every check exists independently: fixing one without the others
 * still leaves the page unusable.
 */
final class SetupGuard
{
    /**
     * True once the operator has explicitly turned the tool off, via either
     * mechanism: a `.disabled` marker file (written by the page's own
     * "Selesai & Nonaktifkan" button) or SETUP_ENABLED=false in config.
     * Deleting the whole _setup/ directory is the simplest and most robust
     * way to disable it — this check only covers the case where the
     * directory is still present but should no longer respond.
     */
    public static function isDisabled(string $markerPath): bool
    {
        if (is_file($markerPath)) {
            return true;
        }
        $enabled = Config::get('SETUP_ENABLED', true);
        if ($enabled === false || $enabled === '0' || $enabled === 'false') {
            return true;
        }
        return false;
    }

    public static function requireEnabled(string $markerPath): void
    {
        if (self::isDisabled($markerPath)) {
            self::notFound();
        }
    }

    public static function authorize(): void
    {
        $env = (string) Config::get('APP_ENV');
        if (in_array($env, ['production', 'live'], true)) {
            self::deny();
        }

        $token = (string) Config::get('SETUP_TOKEN', '');
        if ($token === '') {
            // Not configured at all = the operator has not opted into this tool.
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

    private static function notFound(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "404 Not Found\n";
        exit;
    }
}
