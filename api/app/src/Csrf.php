<?php

declare(strict_types=1);

namespace Amor\Api;

/**
 * CSRF token required on every mutating request, independent of and in
 * addition to Idempotency-Key (docs/php-api-contract-v1.md §1 rule 3, LOCKED).
 * Checked against the token stored server-side in $_SESSION for this session.
 */
final class Csrf
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public static function verify(Request $request): void
    {
        if (!in_array($request->method, self::MUTATING_METHODS, true)) {
            return;
        }

        $sessionToken = $_SESSION['csrf_token'] ?? null;
        $sentToken = $request->header('X-CSRF-Token');

        if ($sessionToken === null || $sentToken === null || !hash_equals($sessionToken, $sentToken)) {
            throw new ApiException(403, 'CSRF_TOKEN_INVALID', 'Missing or invalid CSRF token');
        }
    }
}
