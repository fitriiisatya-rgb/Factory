<?php

declare(strict_types=1);

namespace Amor\Api\Setup;

use PDO;

/**
 * Creates or resets a staging/preproduction admin user, shared by
 * bin/create_admin.php (CLI) and the optional public/_setup/create_admin.php
 * web fallback. Never accepts a hardcoded password — the caller is
 * responsible for sourcing it (interactive prompt, ADMIN_PASSWORD env var,
 * or a one-time web form field), never a literal in source.
 */
final class AdminCreator
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @throws \RuntimeException if the ADMIN role is missing (seed not run yet) */
    public function createOrReset(string $username, string $fullName, string $password): int
    {
        if (strlen($password) < 10) {
            throw new \InvalidArgumentException('Password must be at least 10 characters.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (username, password_hash, full_name, active, created_at)
                 VALUES (?, ?, ?, 1, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), full_name = VALUES(full_name), active = 1, updated_at = UTC_TIMESTAMP()'
            );
            $stmt->execute([$username, $hash, $fullName]);

            $idStmt = $this->pdo->prepare('SELECT user_id FROM users WHERE username = ?');
            $idStmt->execute([$username]);
            $userId = (int) $idStmt->fetchColumn();

            $roleStmt = $this->pdo->prepare("SELECT role_id FROM roles WHERE code = 'ADMIN'");
            $roleStmt->execute();
            $roleId = (int) $roleStmt->fetchColumn();
            if ($roleId === 0) {
                throw new \RuntimeException("ADMIN role not found — run the seed step first.");
            }

            $this->pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $userId;
    }
}
