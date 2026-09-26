<?php

declare(strict_types=1);

namespace Amor\Api\Users;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use PDO;

/**
 * Admin User / Driver Account Management (business logic). Sits on the
 * EXISTING users/roles/user_roles tables and the EXISTING
 * Amor\Api\Auth session/password_hash model — this class never touches
 * $_SESSION or invents a parallel auth mechanism, it only manages the rows
 * Auth::attemptLogin() already reads.
 *
 * No optimistic-concurrency `version` column exists on `users` (unlike
 * product/store/delivery_order) and none is added here — this is a
 * low-frequency, single-actor-at-a-time (an admin doing account
 * housekeeping) admin screen, not a shop-floor transactional aggregate, so
 * a `SELECT ... FOR UPDATE` row lock inside one transaction per mutation is
 * sufficient to prevent a lost update; adding a version column purely for
 * symmetry would be schema change for no real correctness gain, and the
 * task's own instruction prefers no migration when the existing schema
 * already supports the required behavior.
 */
final class UserService
{
    private const MIN_PASSWORD_LENGTH = 10; // same threshold as Setup\AdminCreator, for consistency

    private UserRepository $repo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new UserRepository();
    }

    /** @return array<int,array> */
    public function listUsers(?string $q, ?bool $active): array
    {
        return array_map([$this, 'toDto'], $this->repo->findAll($this->pdo, $q, $active));
    }

    /** @return array<int,array{code:string,name:string}> */
    public function listRoles(): array
    {
        return $this->repo->allRoles($this->pdo);
    }

    /** @return array<int,array{divisionId:int,name:string,factoryId:int,factoryName:string}> */
    public function listDivisions(): array
    {
        return $this->repo->allDivisions($this->pdo);
    }

    /** @return array<int,array{factoryId:int,name:string}> */
    public function listFactories(): array
    {
        return $this->repo->allFactories($this->pdo);
    }

    /** @param string[] $roleCodes */
    public function createUser(string $username, string $fullName, string $password, string $passwordConfirm, array $roleCodes, int $actorUserId, ?string $requestId): array
    {
        $username = trim($username);
        $fullName = trim($fullName);
        if ($username === '') {
            throw new ApiException(400, 'MISSING_USERNAME', 'Username wajib diisi');
        }
        if ($fullName === '') {
            throw new ApiException(400, 'MISSING_FULL_NAME', 'Nama wajib diisi');
        }
        $this->assertPasswordValid($password, $passwordConfirm);
        $roleIds = $this->resolveRoleIds($roleCodes);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $userId = $this->repo->create($this->pdo, $username, $fullName, $hash);
        $this->repo->replaceRoles($this->pdo, $userId, array_values($roleIds));

        $dto = $this->repo->findById($this->pdo, $userId);
        Audit::write($this->pdo, $requestId, $actorUserId, 'user.created', 'user', (string) $userId, 'ok', null, null, [
            'username' => $username, 'fullName' => $fullName, 'roles' => array_keys($roleIds),
        ]);
        return $this->toDto($dto);
    }

    public function updateProfile(int $userId, string $fullName, int $actorUserId, ?string $requestId): array
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            throw new ApiException(400, 'MISSING_FULL_NAME', 'Nama wajib diisi');
        }

        $user = $this->repo->lockById($this->pdo, $userId);
        if ($user === null) {
            throw new ApiException(404, 'NOT_FOUND', 'User tidak ditemukan');
        }
        $this->repo->updateFullName($this->pdo, $userId, $fullName);

        $dto = $this->repo->findById($this->pdo, $userId);
        Audit::write($this->pdo, $requestId, $actorUserId, 'user.updated', 'user', (string) $userId, 'ok', null, null, ['fullName' => $fullName]);
        return $this->toDto($dto);
    }

    /** @param string[] $roleCodes */
    public function updateRoles(int $userId, array $roleCodes, int $actorUserId, ?string $requestId): array
    {
        $roleIds = $this->resolveRoleIds($roleCodes);

        $user = $this->repo->lockById($this->pdo, $userId);
        if ($user === null) {
            throw new ApiException(404, 'NOT_FOUND', 'User tidak ditemukan');
        }
        $lockedActiveAdminIds = $this->repo->lockActiveAdminUserIds($this->pdo);
        $wasActiveAdmin = (int) $user['active'] === 1 && in_array($userId, $lockedActiveAdminIds, true);
        $staysAdmin = in_array('ADMIN', array_keys($roleIds), true);
        if ($wasActiveAdmin && !$staysAdmin && count($lockedActiveAdminIds) <= 1) {
            throw new ApiException(409, 'CANNOT_REMOVE_LAST_ADMIN', 'Tidak bisa menghapus peran ADMIN dari satu-satunya admin aktif yang tersisa');
        }

        $this->repo->replaceRoles($this->pdo, $userId, array_values($roleIds));

        $dto = $this->repo->findById($this->pdo, $userId);
        Audit::write($this->pdo, $requestId, $actorUserId, 'user.role_changed', 'user', (string) $userId, 'ok', null, null, ['roles' => array_keys($roleIds)]);
        return $this->toDto($dto);
    }

    /**
     * PRODUCTION-role division scoping (task: "User <-> Production Division
     * access"). Opt-in: an empty $divisionIds set means this user reverts to
     * today's unrestricted role-based access (Auth::requireDivisionAccess()
     * treats zero assignment rows as "not opted into scoping") — never an
     * accidental full lockout. is_verification=1 (FG) divisions are
     * rejected here; FG access is granted via updateFactoryAccess() instead.
     * @param int[] $divisionIds
     */
    public function updateDivisionAccess(int $userId, array $divisionIds, int $actorUserId, ?string $requestId): array
    {
        $user = $this->repo->lockById($this->pdo, $userId);
        if ($user === null) {
            throw new ApiException(404, 'NOT_FOUND', 'User tidak ditemukan');
        }
        $divisionIds = array_values(array_unique(array_map('intval', $divisionIds)));
        $valid = $this->repo->validDivisionIds($this->pdo, $divisionIds);
        $unknown = array_diff($divisionIds, $valid);
        if ($unknown !== []) {
            throw new ApiException(400, 'UNKNOWN_DIVISION', 'Divisi tidak dikenal atau bukan divisi produksi: ' . implode(', ', $unknown));
        }

        $this->repo->replaceDivisionAccess($this->pdo, $userId, $valid);

        Audit::write($this->pdo, $requestId, $actorUserId, 'user.division_access_changed', 'user', (string) $userId, 'ok', null, null, ['divisionIds' => $valid]);
        return $this->toDto($this->repo->findById($this->pdo, $userId));
    }

    /**
     * FG_PACKING-role factory scoping — same opt-in semantics as
     * updateDivisionAccess(). FG operates per-factory (fg_batch has no
     * division_id), so this uses user_factory_access, not
     * user_division_access.
     * @param int[] $factoryIds
     */
    public function updateFactoryAccess(int $userId, array $factoryIds, int $actorUserId, ?string $requestId): array
    {
        $user = $this->repo->lockById($this->pdo, $userId);
        if ($user === null) {
            throw new ApiException(404, 'NOT_FOUND', 'User tidak ditemukan');
        }
        $factoryIds = array_values(array_unique(array_map('intval', $factoryIds)));
        $valid = $this->repo->validFactoryIds($this->pdo, $factoryIds);
        $unknown = array_diff($factoryIds, $valid);
        if ($unknown !== []) {
            throw new ApiException(400, 'UNKNOWN_FACTORY', 'Pabrik tidak dikenal: ' . implode(', ', $unknown));
        }

        $this->repo->replaceFactoryAccess($this->pdo, $userId, $valid);

        Audit::write($this->pdo, $requestId, $actorUserId, 'user.factory_access_changed', 'user', (string) $userId, 'ok', null, null, ['factoryIds' => $valid]);
        return $this->toDto($this->repo->findById($this->pdo, $userId));
    }

    public function resetPassword(int $userId, string $newPassword, string $confirmPassword, int $actorUserId, ?string $requestId): array
    {
        $this->assertPasswordValid($newPassword, $confirmPassword);

        $user = $this->repo->lockById($this->pdo, $userId);
        if ($user === null) {
            throw new ApiException(404, 'NOT_FOUND', 'User tidak ditemukan');
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->repo->updatePasswordHash($this->pdo, $userId, $hash);

        // Never put the password (plaintext OR hash) in the audit payload.
        Audit::write($this->pdo, $requestId, $actorUserId, 'user.password_reset', 'user', (string) $userId, 'ok', null, null, ['resetBy' => $actorUserId]);
        return $this->toDto($this->repo->findById($this->pdo, $userId));
    }

    public function setActive(int $userId, bool $active, int $actorUserId, ?string $requestId): array
    {
        $user = $this->repo->lockById($this->pdo, $userId);
        if ($user === null) {
            throw new ApiException(404, 'NOT_FOUND', 'User tidak ditemukan');
        }

        if (!$active && (int) $user['active'] === 1) {
            $lockedActiveAdminIds = $this->repo->lockActiveAdminUserIds($this->pdo);
            if (in_array($userId, $lockedActiveAdminIds, true) && count($lockedActiveAdminIds) <= 1) {
                throw new ApiException(409, 'CANNOT_DEACTIVATE_LAST_ADMIN', 'Tidak bisa menonaktifkan satu-satunya admin aktif yang tersisa');
            }
        }

        $this->repo->setActive($this->pdo, $userId, $active);

        Audit::write($this->pdo, $requestId, $actorUserId, $active ? 'user.activated' : 'user.deactivated', 'user', (string) $userId, 'ok', null, null, null);
        return $this->toDto($this->repo->findById($this->pdo, $userId));
    }

    private function assertPasswordValid(string $password, string $confirm): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new ApiException(400, 'PASSWORD_TOO_SHORT', 'Password minimal ' . self::MIN_PASSWORD_LENGTH . ' karakter');
        }
        if (!hash_equals($password, $confirm)) {
            throw new ApiException(400, 'PASSWORD_MISMATCH', 'Konfirmasi password tidak cocok');
        }
    }

    /**
     * @param string[] $roleCodes
     * @return array<string,int> role code => role_id, in the SAME order
     *         validated (used by callers as both "resolved ids" and "the
     *         final role-code set" via array_keys())
     * @throws ApiException 400 if empty or any code is not a real role
     */
    private function resolveRoleIds(array $roleCodes): array
    {
        $roleCodes = array_values(array_unique(array_map('strtoupper', array_map('trim', $roleCodes))));
        if ($roleCodes === []) {
            throw new ApiException(400, 'MISSING_ROLE', 'Pilih minimal satu peran');
        }
        $found = $this->repo->roleIdsByCodes($this->pdo, $roleCodes);
        $unknown = array_diff($roleCodes, array_keys($found));
        if ($unknown !== []) {
            throw new ApiException(400, 'UNKNOWN_ROLE', 'Peran tidak dikenal: ' . implode(', ', $unknown));
        }
        // Preserve validated-order-independent, stable output: keyed by code.
        $ordered = [];
        foreach ($roleCodes as $code) {
            $ordered[$code] = $found[$code];
        }
        return $ordered;
    }

    private function toDto(array $row): array
    {
        return [
            'userId' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'fullName' => (string) $row['full_name'],
            'active' => (int) $row['active'] === 1,
            'roles' => $row['roles'],
            'divisionIds' => $row['division_ids'] ?? [],
            'factoryIds' => $row['factory_ids'] ?? [],
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }
}
