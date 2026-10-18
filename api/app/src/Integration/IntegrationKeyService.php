<?php

declare(strict_types=1);

namespace Amor\Api\Integration;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use PDO;

/**
 * Server-to-server keys for the read-only integration API (finhub).
 * The raw key is returned once, at creation; only SHA-256(raw key) is
 * stored (migration 0022), same model as store_portal_token.
 */
final class IntegrationKeyService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{id:int,name:string,rawKey:string,keyPrefix:string} */
    public function issue(string $name, int $adminUserId, ?string $requestId): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException(400, 'MISSING_NAME', 'Nama kunci wajib diisi (misalnya "finhub").');
        }
        if (mb_strlen($name) > 80) {
            throw new ApiException(400, 'NAME_TOO_LONG', 'Nama kunci maksimal 80 karakter.');
        }
        $rawKey = 'amk_' . bin2hex(random_bytes(24));
        $prefix = substr($rawKey, 0, 8);
        $this->pdo->prepare(
            'INSERT INTO integration_api_key (name, key_prefix, key_hash, created_by, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$name, $prefix, hash('sha256', $rawKey), $adminUserId]);
        $id = (int) $this->pdo->lastInsertId();
        Audit::write($this->pdo, $requestId, $adminUserId, 'integration_key.issued', 'integration_api_key', (string) $id, 'ok', null, null, ['name' => $name]);
        return ['id' => $id, 'name' => $name, 'rawKey' => $rawKey, 'keyPrefix' => $prefix];
    }

    public function revoke(int $id, int $adminUserId, ?string $requestId): array
    {
        $stmt = $this->pdo->prepare('UPDATE integration_api_key SET revoked_at = UTC_TIMESTAMP(), revoked_by = ? WHERE integration_api_key_id = ? AND revoked_at IS NULL');
        $stmt->execute([$adminUserId, $id]);
        if ($stmt->rowCount() === 0) {
            throw new ApiException(404, 'NO_ACTIVE_KEY', 'Kunci tidak ditemukan atau sudah dicabut.');
        }
        Audit::write($this->pdo, $requestId, $adminUserId, 'integration_key.revoked', 'integration_api_key', (string) $id, 'ok', null, null, null);
        return ['id' => $id, 'revoked' => true];
    }

    /** @return array<int,array> newest first, never the hash */
    public function list(): array
    {
        $rows = $this->pdo->query(
            'SELECT k.integration_api_key_id, k.name, k.key_prefix, k.created_at, k.last_used_at, k.revoked_at, u.username AS created_by_username
             FROM integration_api_key k LEFT JOIN users u ON u.user_id = k.created_by
             ORDER BY k.integration_api_key_id DESC'
        )->fetchAll();
        return array_map(static fn ($r) => [
            'id' => (int) $r['integration_api_key_id'],
            'name' => $r['name'],
            'keyPrefix' => $r['key_prefix'],
            'createdAt' => $r['created_at'],
            'createdBy' => $r['created_by_username'],
            'lastUsedAt' => $r['last_used_at'],
            'revokedAt' => $r['revoked_at'],
            'active' => $r['revoked_at'] === null,
        ], $rows);
    }

    /**
     * Resolves a raw key from a request header to its active row, or 401.
     * @return array{id:int,name:string}
     */
    public function authenticate(?string $rawKey): array
    {
        $rawKey = trim((string) $rawKey);
        if ($rawKey === '') {
            throw new ApiException(401, 'MISSING_API_KEY', 'Kunci API tidak dikirim (header X-Api-Key).');
        }
        $stmt = $this->pdo->prepare('SELECT integration_api_key_id, name, last_used_at FROM integration_api_key WHERE key_hash = ? AND revoked_at IS NULL');
        $stmt->execute([hash('sha256', $rawKey)]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ApiException(401, 'INVALID_API_KEY', 'Kunci API tidak valid atau sudah dicabut.');
        }
        // Touch at most once a minute: a polling client must not turn every read into a write.
        if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at'] . ' UTC') < time() - 60) {
            $this->pdo->prepare('UPDATE integration_api_key SET last_used_at = UTC_TIMESTAMP() WHERE integration_api_key_id = ?')
                ->execute([(int) $row['integration_api_key_id']]);
        }
        return ['id' => (int) $row['integration_api_key_id'], 'name' => $row['name']];
    }
}
