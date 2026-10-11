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
        // A fresh login must never inherit the previous person's
        // "Siapa Anda?" answer from the same browser session.
        unset($_SESSION['operator_name']);

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

    /**
     * Shared Division Login (migration 0019): each production division
     * logs in with ONE shared account, so after login the operator types
     * their own name ("Siapa Anda?"). Kept in the session only — re-asked
     * on every new login, recorded on every production create/edit/submit.
     */
    public static function currentOperatorName(): ?string
    {
        return $_SESSION['operator_name'] ?? null;
    }

    public static function setOperatorName(string $name): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (mb_strlen($name) > 150) {
            $name = mb_substr($name, 0, 150);
        }
        $_SESSION['operator_name'] = $name !== '' ? $name : null;
    }

    public static function clearOperatorName(): void
    {
        unset($_SESSION['operator_name']);
    }

    /**
     * True only for a PRODUCTION shared account that has not yet named
     * itself this session. Scoped TIGHTLY: ADMIN/PPIC log in individually
     * and are never asked (a broader "every non-admin role" version was a
     * real bug caught by regression tests in an earlier build of this
     * feature).
     */
    public static function needsOperatorName(): bool
    {
        if (self::currentUserId() === null) {
            return false;
        }
        $roles = self::currentRoles();
        if (array_intersect(['ADMIN', 'PPIC'], $roles) !== []) {
            return false;
        }
        if (!in_array('PRODUCTION', $roles, true)) {
            return false;
        }
        return self::currentOperatorName() === null;
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
     * Division-level access gate for the Production module. DEFAULT-DENY:
     * a non-ADMIN/PPIC user MUST have at least one user_division_access row
     * to reach ANY division worksheet — this is the approved business rule
     * ("Production users log in for their assigned division(s)... A
     * production user must ONLY see and edit divisions explicitly assigned
     * to them"). A user with zero assignment rows is denied outright with a
     * clear NO_DIVISION_ASSIGNMENT error, never silently given unrestricted
     * access. ADMIN/PPIC always bypass (task's own "Admin: sees all...
     * PPIC: all divisions if this is existing approved behavior" —
     * documented as-is; PPIC has always been treated identically to ADMIN
     * for every existing role gate in this codebase, e.g.
     * ProductionController::EDITOR_ROLES/REOPEN_ROLES, so this preserves
     * that established behavior rather than introducing a new PPIC-specific
     * rule).
     *
     * This intentionally replaced an earlier opt-in version of this method
     * (zero assignments = unrestricted) — that version was a deliberate,
     * disclosed backward-compatibility bridge for the initial RBAC-wiring
     * pass; the business has since confirmed default-deny is the actually
     * approved rule, so every pre-existing PRODUCTION-role test fixture was
     * updated with explicit assignments rather than left to rely on the
     * old bypass.
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
            throw new ApiException(403, 'NO_DIVISION_ASSIGNMENT', 'User belum memiliki assignment divisi produksi.');
        }
        if (!in_array($divisionId, $assigned, true)) {
            throw new ApiException(403, 'DIVISION_ACCESS_DENIED', 'You are not assigned to this division');
        }
    }

    /**
     * Factory-level access gate for the FG & Packing module. Same
     * DEFAULT-DENY semantics as requireDivisionAccess(), applied for
     * consistency: leaving FG_PACKING/PRODUCTION opt-in while Production
     * divisions were made default-deny would be an inconsistent access
     * policy across the two modules the business explicitly compared in
     * the same request. ADMIN/PPIC always bypass.
     */
    public static function requireFactoryAccess(int $factoryId): void
    {
        self::requireAuth();
        $roles = self::currentRoles();
        if (array_intersect(['ADMIN', 'PPIC'], $roles) !== []) {
            return;
        }
        $assigned = self::currentFactoryIds();
        if ($assigned === []) {
            throw new ApiException(403, 'NO_FACTORY_ASSIGNMENT', 'User belum memiliki assignment pabrik FG & Packing.');
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
            'operatorName' => self::currentOperatorName(),
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
