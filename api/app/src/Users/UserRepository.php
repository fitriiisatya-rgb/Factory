<?php

declare(strict_types=1);

namespace Amor\Api\Users;

use Amor\Api\ApiException;
use PDO;
use PDOException;

/**
 * Persistence for the User Management module. Reuses the EXISTING
 * users/roles/user_roles tables from migration 0001 as-is — no new table,
 * no new column. users.active (default 1) already gates login in
 * Auth::attemptLogin(); username already has a UNIQUE constraint; roles is
 * the same table Phase 5.5 added the DRIVER row to. This class only adds
 * the CRUD/query surface an admin screen needs on top of what already
 * exists.
 */
final class UserRepository
{
    /** @return array<int,array> one row per user, each with a 'roles' string[] */
    public function findAll(PDO $pdo, ?string $q, ?bool $active): array
    {
        $sql = 'SELECT user_id, username, full_name, active, created_at, updated_at FROM users WHERE 1=1';
        $params = [];
        if ($q !== null && $q !== '') {
            $sql .= ' AND (username LIKE ? OR full_name LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($active !== null) {
            $sql .= ' AND active = ?';
            $params[] = $active ? 1 : 0;
        }
        $sql .= ' ORDER BY username';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        $userIds = array_map(static fn ($r) => (int) $r['user_id'], $rows);
        $rolesByUser = $this->rolesForUsers($pdo, $userIds);
        $divisionsByUser = $this->divisionsForUsers($pdo, $userIds);
        $factoriesByUser = $this->factoriesForUsers($pdo, $userIds);
        foreach ($rows as &$r) {
            $r['roles'] = $rolesByUser[(int) $r['user_id']] ?? [];
            $r['division_ids'] = $divisionsByUser[(int) $r['user_id']] ?? [];
            $r['factory_ids'] = $factoriesByUser[(int) $r['user_id']] ?? [];
        }
        return $rows;
    }

    public function findById(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT user_id, username, full_name, active, created_at, updated_at FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['roles'] = $this->rolesForUser($pdo, $userId);
        $row['division_ids'] = $this->divisionsForUser($pdo, $userId);
        $row['factory_ids'] = $this->factoriesForUser($pdo, $userId);
        return $row;
    }

    /** Row-locked (FOR UPDATE) — must be called inside an open transaction. */
    public function lockById(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT user_id, username, full_name, active FROM users WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByUsername(PDO $pdo, string $username): ?array
    {
        $stmt = $pdo->prepare('SELECT user_id, username, full_name, active FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @throws ApiException 409 DUPLICATE_USERNAME */
    public function create(PDO $pdo, string $username, string $fullName, string $passwordHash): int
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())'
            );
            $stmt->execute([$username, $passwordHash, $fullName]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new ApiException(409, 'DUPLICATE_USERNAME', 'Username ini sudah dipakai');
            }
            throw $e;
        }
        return (int) $pdo->lastInsertId();
    }

    public function updateFullName(PDO $pdo, int $userId, string $fullName): void
    {
        $pdo->prepare('UPDATE users SET full_name = ?, updated_at = UTC_TIMESTAMP() WHERE user_id = ?')->execute([$fullName, $userId]);
    }

    public function updatePasswordHash(PDO $pdo, int $userId, string $passwordHash): void
    {
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE user_id = ?')->execute([$passwordHash, $userId]);
    }

    public function setActive(PDO $pdo, int $userId, bool $active): void
    {
        $pdo->prepare('UPDATE users SET active = ?, updated_at = UTC_TIMESTAMP() WHERE user_id = ?')->execute([$active ? 1 : 0, $userId]);
    }

    /** @return array<string,int> role code => role_id, only for codes that actually exist */
    public function roleIdsByCodes(PDO $pdo, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $pdo->prepare("SELECT code, role_id FROM roles WHERE code IN ({$placeholders})");
        $stmt->execute($codes);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['code']] = (int) $r['role_id'];
        }
        return $out;
    }

    /** @return array<int,array{code:string,name:string}> every role, for the create/edit UI's checkbox list */
    public function allRoles(PDO $pdo): array
    {
        return $pdo->query('SELECT code, name FROM roles ORDER BY name')->fetchAll();
    }

    /** @return string[] role codes for one user */
    public function rolesForUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT r.code FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.role_id WHERE ur.user_id = ? ORDER BY r.code'
        );
        $stmt->execute([$userId]);
        return array_column($stmt->fetchAll(), 'code');
    }

    /** @param int[] $userIds @return array<int,string[]> user_id => role codes */
    private function rolesForUsers(PDO $pdo, array $userIds): array
    {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT ur.user_id, r.code FROM user_roles ur
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id IN ({$placeholders}) ORDER BY r.code"
        );
        $stmt->execute($userIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['user_id']][] = $row['code'];
        }
        return $out;
    }

    /**
     * Replaces a user's entire role set (delete-then-insert, inside the
     * caller's own transaction) — simplest correct implementation for a
     * low-frequency admin action; no partial-add/remove API is needed.
     * @param int[] $roleIds
     */
    public function replaceRoles(PDO $pdo, int $userId, array $roleIds): void
    {
        $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
        if ($roleIds === []) {
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        foreach ($roleIds as $roleId) {
            $stmt->execute([$userId, $roleId]);
        }
    }

    /**
     * Row-locks (FOR UPDATE) every currently-active user who holds the
     * ADMIN role, and returns their ids. Used by the "never zero active
     * ADMIN" safeguard: locking this set serializes any two concurrent
     * admin-demoting/deactivating requests against each other, so the
     * count they each compute is never stale.
     * @return int[]
     */
    public function lockActiveAdminUserIds(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT u.user_id FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.user_id
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE r.code = 'ADMIN' AND u.active = 1
             FOR UPDATE"
        );
        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }

    /**
     * The following methods wire up user_division_access/user_factory_access
     * — many-to-many tables that have existed since the original migration
     * 0001 schema as unused "future scope" — into the same admin
     * assign/replace pattern already used for user_roles (see
     * replaceRoles() above). No new table, no new column.
     */

    /** @return array<int,array{divisionId:int,name:string,factoryId:int,factoryName:string}> every non-FG production division, for the assignment UI's checkbox list */
    public function allDivisions(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT d.division_id, d.name, d.factory_id, f.name AS factory_name
             FROM division d INNER JOIN factory f ON f.factory_id = d.factory_id
             WHERE d.is_verification = 0
             ORDER BY f.name, d.name"
        )->fetchAll();
        return array_map(static fn ($r) => [
            'divisionId' => (int) $r['division_id'],
            'name' => $r['name'],
            'factoryId' => (int) $r['factory_id'],
            'factoryName' => $r['factory_name'],
        ], $rows);
    }

    /** @return array<int,array{factoryId:int,name:string}> every factory, for the assignment UI's checkbox list */
    public function allFactories(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT factory_id, name FROM factory ORDER BY name')->fetchAll();
        return array_map(static fn ($r) => ['factoryId' => (int) $r['factory_id'], 'name' => $r['name']], $rows);
    }

    /** @return int[] */
    public function divisionsForUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT division_id FROM user_division_access WHERE user_id = ? ORDER BY division_id');
        $stmt->execute([$userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'division_id'));
    }

    /** @return int[] */
    public function factoriesForUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT factory_id FROM user_factory_access WHERE user_id = ? ORDER BY factory_id');
        $stmt->execute([$userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'factory_id'));
    }

    /** @param int[] $userIds @return array<int,int[]> user_id => division_ids */
    public function divisionsForUsers(PDO $pdo, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("SELECT user_id, division_id FROM user_division_access WHERE user_id IN ({$placeholders}) ORDER BY division_id");
        $stmt->execute($userIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['user_id']][] = (int) $row['division_id'];
        }
        return $out;
    }

    /** @param int[] $userIds @return array<int,int[]> user_id => factory_ids */
    public function factoriesForUsers(PDO $pdo, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("SELECT user_id, factory_id FROM user_factory_access WHERE user_id IN ({$placeholders}) ORDER BY factory_id");
        $stmt->execute($userIds);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['user_id']][] = (int) $row['factory_id'];
        }
        return $out;
    }

    /**
     * Replaces a user's entire division-access set (delete-then-insert,
     * inside the caller's own transaction) — same low-frequency admin
     * action pattern as replaceRoles() above.
     * @param int[] $divisionIds
     */
    public function replaceDivisionAccess(PDO $pdo, int $userId, array $divisionIds): void
    {
        $pdo->prepare('DELETE FROM user_division_access WHERE user_id = ?')->execute([$userId]);
        if ($divisionIds === []) {
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO user_division_access (user_id, division_id) VALUES (?, ?)');
        foreach ($divisionIds as $divisionId) {
            $stmt->execute([$userId, $divisionId]);
        }
    }

    /**
     * Replaces a user's entire factory-access set. Same pattern as
     * replaceDivisionAccess() above.
     * @param int[] $factoryIds
     */
    public function replaceFactoryAccess(PDO $pdo, int $userId, array $factoryIds): void
    {
        $pdo->prepare('DELETE FROM user_factory_access WHERE user_id = ?')->execute([$userId]);
        if ($factoryIds === []) {
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO user_factory_access (user_id, factory_id) VALUES (?, ?)');
        foreach ($factoryIds as $factoryId) {
            $stmt->execute([$userId, $factoryId]);
        }
    }

    /** @param int[] $divisionIds @return int[] only the ids that actually exist as non-FG production divisions */
    public function validDivisionIds(PDO $pdo, array $divisionIds): array
    {
        if ($divisionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($divisionIds), '?'));
        $stmt = $pdo->prepare("SELECT division_id FROM division WHERE division_id IN ({$placeholders}) AND is_verification = 0");
        $stmt->execute($divisionIds);
        return array_map('intval', array_column($stmt->fetchAll(), 'division_id'));
    }

    /** @param int[] $factoryIds @return int[] only the ids that actually exist */
    public function validFactoryIds(PDO $pdo, array $factoryIds): array
    {
        if ($factoryIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($factoryIds), '?'));
        $stmt = $pdo->prepare("SELECT factory_id FROM factory WHERE factory_id IN ({$placeholders})");
        $stmt->execute($factoryIds);
        return array_map('intval', array_column($stmt->fetchAll(), 'factory_id'));
    }
}
