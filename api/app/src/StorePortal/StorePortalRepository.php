<?php

declare(strict_types=1);

namespace Amor\Api\StorePortal;

use PDO;

/**
 * Persistence for store_portal_token (migration 0017) — the ONE permanent,
 * no-login identity per store. Every method here takes/returns token_hash,
 * never a raw token: generating the raw value and deciding how to hash it
 * is StorePortalService's job (the crypto decision belongs to the service
 * layer, same separation as every other Service/Repository pair in this
 * codebase) — this class only ever sees/stores the hash, never the secret
 * itself, so even a full dump of this table cannot be used to impersonate
 * a store.
 */
final class StorePortalRepository
{
    private const SYSTEM_USERNAME = 'bakery_portal_system';

    /**
     * Lazily finds-or-creates ONE role-less, inactive, unloggable system
     * user representing "the Bakery Portal" as an actor — used ONLY as
     * special_order.created_by when a bakery submits a Pesanan Khusus/
     * Custom order through its own token (SpecialOrderService::
     * createOrder()'s signature is intentionally left untouched — task's
     * own "reuse this engine verbatim" — and that column is NOT NULL
     * today). This is NOT a loss of audit fidelity: the REAL actor for a
     * portal-submitted order is, and always was, special_order.store_id —
     * this user_id is exactly as meaningful as it would be for any other
     * automated/system-originated insert elsewhere in this codebase.
     * password_hash is a random value discarded immediately after hashing
     * (never stored anywhere but this one irreversible hash) and active=0,
     * so this account can never successfully log in even if its username
     * were guessed — belt-and-suspenders over the unguessable hash alone.
     * Same "find-or-create guarded by a UNIQUE key, handle the race" shape
     * as SpecialOrderRepository::findOrCreateCakeCustomDivision() (migration
     * 0010) — this is NOT a new pattern in this codebase.
     */
    public function findOrCreateSystemUserId(PDO $pdo): int
    {
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([self::SYSTEM_USERNAME]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        try {
            $ins = $pdo->prepare(
                'INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES (?, ?, ?, 0, UTC_TIMESTAMP())'
            );
            $ins->execute([
                self::SYSTEM_USERNAME,
                password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'Bakery Portal (Sistem)',
            ]);
            return (int) $pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ((int) $e->getCode() !== 23000) {
                throw $e;
            }
            // Another concurrent request just created it — re-select.
            $stmt->execute([self::SYSTEM_USERNAME]);
            return (int) $stmt->fetchColumn();
        }
    }

    /** The store's current active (non-revoked) token row, or null if none has ever been issued or the last one was revoked without a replacement. */
    public function findActiveByStoreId(PDO $pdo, int $storeId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM store_portal_token WHERE store_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$storeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Public-portal lookup: token_hash -> active token row, or null. A revoked hash NEVER resolves, even the instant after regenerate. */
    public function findActiveByTokenHash(PDO $pdo, string $tokenHash): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM store_portal_token WHERE token_hash = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function insert(PDO $pdo, int $storeId, string $tokenHash, int $createdBy): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO store_portal_token (store_id, token_hash, created_by, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$storeId, $tokenHash, $createdBy]);
        return (int) $pdo->lastInsertId();
    }

    /** Revokes the store's current active row, if any — "old invalidates immediately". Returns the number of rows revoked (0 or 1, uq_store_portal_token_active guarantees never more than 1). */
    public function revokeActiveForStore(PDO $pdo, int $storeId, int $revokedBy): int
    {
        $stmt = $pdo->prepare(
            'UPDATE store_portal_token SET revoked_at = UTC_TIMESTAMP(), revoked_by = ? WHERE store_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$revokedBy, $storeId]);
        return $stmt->rowCount();
    }

    public function touchLastUsed(PDO $pdo, int $tokenId): void
    {
        $stmt = $pdo->prepare('UPDATE store_portal_token SET last_used_at = UTC_TIMESTAMP() WHERE store_portal_token_id = ?');
        $stmt->execute([$tokenId]);
    }

    /** Full history for one store (the active row, if any, plus every revoked predecessor), newest first — Admin's own audit view. Never includes token_hash in any DTO built from this — see StorePortalService. */
    public function listHistoryForStore(PDO $pdo, int $storeId): array
    {
        $stmt = $pdo->prepare(
            'SELECT store_portal_token_id, store_id, created_by, created_at, last_used_at, revoked_at, revoked_by
             FROM store_portal_token WHERE store_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$storeId]);
        return $stmt->fetchAll();
    }
}
