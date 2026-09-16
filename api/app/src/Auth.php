<?php

declare(strict_types=1);

namespace Amor\Api;

use PDO;

/**
 * Server-side PHP session auth — LOCKED design, docs/mysql-schema-v1.md §15.1.
 * password_hash()/password_verify(), session_regenerate_id(true) on login,
 * Secure/HttpOnly/SameSite cookie, no bearer-token/localStorage mode.
 * No session-support DB table — native PHP file-based session storage.
 */
final class Auth
{
    private const SESSION_NAME = 'amor_staging_session';

    public static function bootSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => (int) Config::get('SESSION_LIFETIME_SECONDS'),
            'path' => '/',
            'secure' => (bool) Config::get('SESSION_SECURE'),
            'httponly' => true,
            'samesite' => (string) Config::get('SESSION_SAMESITE', 'Lax'),
        ]);
        ini_set('session.gc_maxlifetime', (string) Config::get('SESSION_LIFETIME_SECONDS'));
        session_start();
    }

    /**
     * @return array{userId:int,username:string,fullName:string,roles:string[]}
     * @throws ApiException 401 on bad credentials, 403 if account inactive
     */
    public static function attemptLogin(string $username, string $password): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT user_id, username, password_hash, full_name, active FROM users WHERE username = ?'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            throw new ApiException(401, 'INVALID_CREDENTIALS', 'Invalid username or password');
        }
        if ((int) $row['active'] !== 1) {
            throw new ApiException(403, 'ACCOUNT_INACTIVE', 'This account is not active');
        }

        $roles = self::loadRoles($pdo, (int) $row['user_id']);

        // Defeats session fixation — a new session id is issued on every successful login.
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['user_id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['full_name'] = $row['full_name'];
        $_SESSION['roles'] = $roles;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['login_at'] = time();

        return [
            'userId' => (int) $row['user_id'],
            'username' => $row['username'],
            'fullName' => $row['full_name'],
            'roles' => $roles,
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_unset();
        session_destroy();
    }

    public static function currentUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    /** @return string[] */
    public static function currentRoles(): array
    {
        return $_SESSION['roles'] ?? [];
    }

    public static function requireAuth(): int
    {
        $userId = self::currentUserId();
        if ($userId === null) {
            throw new ApiException(401, 'UNAUTHENTICATED', 'Login required');
        }
        return $userId;
    }

    public static function requireRole(string ...$anyOf): int
    {
        $userId = self::requireAuth();
        $roles = self::currentRoles();
        if (array_intersect($anyOf, $roles) === []) {
            throw new ApiException(403, 'FORBIDDEN', 'Insufficient role for this action');
        }
        return $userId;
    }

    public static function me(): array
    {
        self::requireAuth();
        return [
            'userId' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'fullName' => $_SESSION['full_name'],
            'roles' => $_SESSION['roles'],
            'csrfToken' => $_SESSION['csrf_token'],
        ];
    }

    /** @return string[] */
    private static function loadRoles(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT r.code FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.role_id
             WHERE ur.user_id = ?'
        );
        $stmt->execute([$userId]);
        return array_column($stmt->fetchAll(), 'code');
    }
}
