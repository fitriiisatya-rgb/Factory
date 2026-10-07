<?php

declare(strict_types=1);

namespace Amor\Api\StorePortal;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Config;
use Amor\Api\Repositories\StoreRepository;
use PDO;

/**
 * The ONE permanent, bookmarkable, no-login identity per store
 * (https://factory.amorgroup.id/api/_store/?token=<token>), migration
 * 0017's store_portal_token. This is the SOLE place in the codebase a raw
 * portal token is generated, hashed, or compared — every other Bakery
 * Portal controller (Receipt Confirmation, Pesanan Khusus, Retur, Mutasi,
 * Riwayat) calls resolvePortalIdentity() to turn the URL's ?token= into a
 * server-trusted storeId, and NEVER accepts a storeId from the client in
 * any other way (route param, request body, hidden field) for ANY
 * mutating or reading action on the Portal.
 *
 * SECURITY: the raw token is cryptographically random (32 bytes / 256
 * bits, same entropy as delivery_receipt_token's own precedent — see
 * ReceiptRepository::getOrCreateToken()), and the DATABASE never stores
 * it — only SHA-256(raw token) (store_portal_token.token_hash). The raw
 * value is returned to the caller EXACTLY ONCE, at the moment of
 * issueToken(), and is never recoverable afterward (losing the link means
 * Admin must regenerate — same "secret shown once" discipline as an API
 * key). Audit::write() below never includes the raw token OR the hash in
 * its payloadSummary — "never log plaintext" extends to never logging the
 * hash either, since there is no legitimate reason an audit log reader
 * needs it.
 *
 * "Exactly one ACTIVE identity" is enforced at the schema level
 * (uq_store_portal_token_active, migration 0017) — issueToken() revokes
 * any existing active row for the store BEFORE inserting the new one
 * (same request, same transaction) purely so regenerate reads cleanly as
 * one action; the UNIQUE KEY is what actually prevents two active tokens
 * from ever coexisting, even under a concurrent double-click.
 */
final class StorePortalService
{
    private StorePortalRepository $repo;
    private StoreRepository $storeRepo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new StorePortalRepository();
        $this->storeRepo = new StoreRepository();
    }

    /**
     * Admin action — covers BOTH "Generate" (no active token exists yet)
     * and "Regenerate" (an active token already exists: it is revoked in
     * the same breath a new one is issued, so the old link stops working
     * immediately). Returns the raw token/portal URL — THE ONLY TIME this
     * value is ever available again.
     *
     * @return array{storeId:int,tokenId:int,rawToken:string,portalUrl:string,regenerated:bool}
     * @throws ApiException 404 NOT_FOUND if the store itself doesn't exist
     */
    public function issueToken(int $storeId, int $adminUserId, ?string $requestId): array
    {
        $store = $this->storeRepo->findById($this->pdo, $storeId);
        if ($store === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Toko tidak ditemukan');
        }

        $revokedCount = $this->repo->revokeActiveForStore($this->pdo, $storeId, $adminUserId);

        $rawToken = self::generateRawToken();
        $tokenId = $this->repo->insert($this->pdo, $storeId, self::hashToken($rawToken), $adminUserId);

        Audit::write(
            $this->pdo, $requestId, $adminUserId, 'store_portal.token_issued', 'store_portal_token',
            (string) $tokenId, 'ok', null, null,
            ['storeId' => $storeId, 'regenerated' => $revokedCount > 0]
        );

        return [
            'storeId' => $storeId,
            'tokenId' => $tokenId,
            'rawToken' => $rawToken,
            'portalUrl' => self::buildPortalUrl($rawToken),
            'regenerated' => $revokedCount > 0,
        ];
    }

    /**
     * Admin action — revoke without issuing a replacement (e.g. the
     * relationship with this bakery has ended). Idempotent in effect but
     * NOT silently: a second call with nothing active to revoke is a
     * genuine no-op the caller should be told about, same "tell the truth"
     * discipline as every other admin action in this codebase.
     *
     * @throws ApiException 404 NO_ACTIVE_TOKEN
     */
    public function revokeToken(int $storeId, int $adminUserId, ?string $requestId): array
    {
        $revokedCount = $this->repo->revokeActiveForStore($this->pdo, $storeId, $adminUserId);
        if ($revokedCount === 0) {
            throw new ApiException(404, 'NO_ACTIVE_TOKEN', 'Toko ini tidak memiliki token portal yang aktif');
        }

        Audit::write(
            $this->pdo, $requestId, $adminUserId, 'store_portal.token_revoked', 'store_portal_token',
            (string) $storeId, 'ok', null, null, ['storeId' => $storeId]
        );

        return ['storeId' => $storeId, 'revoked' => true];
    }

    /**
     * Admin's own token-management view for one store — never returns the
     * hash or any value that could be replayed as a token. "hasActiveToken"
     * plus timestamps is enough for Admin to decide whether to Generate,
     * Regenerate, Revoke, or just Copy Link again (copying again requires
     * a fresh issueToken() call — the raw value is never retrievable from
     * storage, by design).
     */
    public function getStatus(int $storeId): array
    {
        $active = $this->repo->findActiveByStoreId($this->pdo, $storeId);
        return [
            'storeId' => $storeId,
            'hasActiveToken' => $active !== null,
            'issuedAt' => $active['created_at'] ?? null,
            'lastUsedAt' => $active['last_used_at'] ?? null,
        ];
    }

    /** Admin's audit history for one store — active row plus every revoked predecessor, newest first. Never includes a hash or any replayable value. */
    public function listHistory(int $storeId): array
    {
        $rows = $this->repo->listHistoryForStore($this->pdo, $storeId);
        return array_map(static fn (array $r): array => [
            'tokenId' => (int) $r['store_portal_token_id'],
            'createdBy' => (int) $r['created_by'],
            'createdAt' => $r['created_at'],
            'lastUsedAt' => $r['last_used_at'],
            'revokedAt' => $r['revoked_at'],
            'revokedBy' => $r['revoked_by'] !== null ? (int) $r['revoked_by'] : null,
            'active' => $r['revoked_at'] === null,
        ], $rows);
    }

    /**
     * THE core access-control function for the entire Bakery Portal. Every
     * Portal controller (Receipt Confirmation, Pesanan Khusus, Retur,
     * Mutasi, Riwayat) calls this FIRST and uses ONLY the returned storeId
     * for every subsequent query/write — never a storeId from the route,
     * query string, request body, or any client-supplied field. A token
     * that is unknown, already revoked, or whose store has since been
     * deactivated all produce the exact same generic error — this method
     * never reveals WHICH of those three is true to an unauthenticated
     * caller (same "don't leak why" discipline as ReceiptService's public
     * error handling).
     *
     * @return array{storeId:int,storeName:string,tokenId:int}
     * @throws ApiException 404 INVALID_PORTAL_TOKEN
     */
    public function resolvePortalIdentity(string $rawToken): array
    {
        $tokenHash = self::hashToken($rawToken);
        $tokenRow = $this->repo->findActiveByTokenHash($this->pdo, $tokenHash);
        if ($tokenRow === null) {
            throw new ApiException(404, 'INVALID_PORTAL_TOKEN', 'Link portal tidak ditemukan atau sudah tidak aktif');
        }

        $storeId = (int) $tokenRow['store_id'];
        $store = $this->storeRepo->findById($this->pdo, $storeId);
        if ($store === null || !(bool) $store['active']) {
            throw new ApiException(404, 'INVALID_PORTAL_TOKEN', 'Link portal tidak ditemukan atau sudah tidak aktif');
        }

        $this->repo->touchLastUsed($this->pdo, (int) $tokenRow['store_portal_token_id']);

        return [
            'storeId' => $storeId,
            'storeName' => (string) $store['canonical_name'],
            'tokenId' => (int) $tokenRow['store_portal_token_id'],
        ];
    }

    /**
     * Used ONLY by the automatic Bakery email (Mail\ShipmentEmailService) —
     * task's own "EMAIL CHANGE: CTA points to the permanent portal". A
     * store's FIRST ever notification doubles as its onboarding: if it has
     * no active token yet, one is issued here (system-triggered, same
     * lazily-provisioned system-user actor as SpecialOrderService's own
     * portal-submitted orders) and the raw value is returned so THIS ONE
     * email can embed a real, working deep link — captured fresh because
     * we just created it. Every later email to the SAME store returns null
     * here on purpose: an existing active token's raw value is permanently
     * unrecoverable by design (see this class's own docblock), and this
     * method never regenerates one just to re-embed a link — doing so
     * would invalidate whatever the store already has bookmarked, exactly
     * the "unexpectedly broken link" the task's own backward-compatibility
     * rule forbids. The caller falls back to a bare portal-URL reminder
     * (no token) in that case.
     */
    public function getOrIssueTokenForEmailOnboarding(int $storeId, ?string $requestId): ?string
    {
        if ($this->repo->findActiveByStoreId($this->pdo, $storeId) !== null) {
            return null;
        }
        $systemUserId = $this->repo->findOrCreateSystemUserId($this->pdo);
        $issued = $this->issueToken($storeId, $systemUserId, $requestId);
        return $issued['rawToken'];
    }

    private static function generateRawToken(): string
    {
        // 32 random bytes -> 64 hex chars: same entropy as delivery_receipt_token's
        // own precedent (ReceiptRepository::getOrCreateToken()), but this one is
        // PERMANENT/bookmarked rather than per-shipment, so the stored value is a
        // hash, never the raw token itself (see this class's own docblock).
        return bin2hex(random_bytes(32));
    }

    private static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    private static function buildPortalUrl(string $rawToken): string
    {
        $baseUrl = rtrim((string) Config::get('APP_BASE_URL', 'https://factory.amorgroup.id'), '/');
        return $baseUrl . '/api/_store/?token=' . urlencode($rawToken);
    }
}
