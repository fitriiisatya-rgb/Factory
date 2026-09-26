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
        $divisionIds = self::loadDivisionIds($pdo, (int) $row['user_id']);
        $factoryIds = self::loadFactoryIds($pdo, (int) $row['user_id']);

        // Defeats session fixation — a new session id is issued on every successful login.
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['user_id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['full_name'] = $row['full_name'];
        $_SESSION['roles'] = $roles;
        $_SESSION['division_ids'] = $divisionIds;
        $_SESSION['factory_ids'] = $factoryIds;
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

    /** @return int[] division_ids this user is assigned to via user_division_access (empty = not opted into division scoping) */
    public static function currentDivisionIds(): array
    {
        return $_SESSION['division_ids'] ?? [];
    }

    /** @return int[] factory_ids this user is assigned to via user_factory_access (empty = not opted into factory scoping) */
    public static function currentFactoryIds(): array
    {
        return $_SESSION['factory_ids'] ?? [];
    }

    /**
     * Division-level access gate for the Production module. Opt-in scoping:
     * user_division_access existed in the schema since migration 0001 as
     * unused "future scope" — wiring it in now must not silently lock out
     * every existing PRODUCTION-role user who has never been assigned a
     * division (a real regression risk, since every current such user has
     * zero rows in that table and today freely edits any division). So a
     * user with NO assignment rows keeps today's unrestricted role-based
     * behavior; only once an admin assigns them to specific divisions does
     * this become a real allowlist. ADMIN/PPIC always bypass (task section
     * "Admin: sees all... manages assignment").
     */
    public static function requireDivisionAccess(int $divisionId): void
    {
        self::requireAuth();
        $roles = self::currentRoles();
        if (array_intersect(['ADMIN', 'PPIC'], $roles) !== []) {
            return;
        }
        $assigned = self::currentDivisionIds();
        if ($assigned === []) {
            return;
        }
        if (!in_array($divisionId, $assigned, true)) {
            throw new ApiException(403, 'DIVISION_ACCESS_DENIED', 'You are not assigned to this division');
        }
    }

    /** Factory-level access gate for the FG & Packing module. Same opt-in semantics as requireDivisionAccess(). */
    public static function requireFactoryAccess(int $factoryId): void
    {
        self::requireAuth();
        $roles = self::currentRoles();
        if (array_intersect(['ADMIN', 'PPIC'], $roles) !== []) {
            return;
        }
        $assigned = self::currentFactoryIds();
        if ($assigned === []) {
            return;
        }
        if (!in_array($factoryId, $assigned, true)) {
            throw new ApiException(403, 'FACTORY_ACCESS_DENIED', 'You are not assigned to this factory');
        }
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

    /** @return int[] */
    private static function loadDivisionIds(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT division_id FROM user_division_access WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'division_id'));
    }

    /** @return int[] */
    private static function loadFactoryIds(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT factory_id FROM user_factory_access WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'factory_id'));
    }
}
