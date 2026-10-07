<?php

declare(strict_types=1);

namespace Amor\Api\StorePortal;

use PDO;

/**
 * Persistence for retur_request / retur_request_evidence (migration
 * 0017). "Barang sudah diterima BAIK, belakangan tidak terjual" — reported
 * later by the store, never auto-created by any other flow. See that
 * migration's own docblock for the LOCKED financial/stock rules this
 * table's shape enforces by omission (no invoice/billing link at all, no
 * stock_ledger write path anywhere in this class).
 */
final class ReturRepository
{
    public function insert(PDO $pdo, string $docNo, int $storeId, int $productId, float $qty, string $reason, ?string $notes, string $returDate, ?int $submittedViaTokenId): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO retur_request
                (doc_no, store_id, product_id, qty, reason, notes, retur_date, status, submitted_via_token_id, version, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'waiting_admin_verification\', ?, 1, UTC_TIMESTAMP())'
        );
        $stmt->execute([$docNo, $storeId, $productId, $qty, $reason, $notes, $returDate, $submittedViaTokenId]);
        return (int) $pdo->lastInsertId();
    }

    public function insertEvidence(PDO $pdo, int $returRequestId, string $filePath, string $mimeType, int $fileSize, ?string $originalName): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO retur_request_evidence
                (retur_request_id, file_path, mime_type, file_size, original_name, uploaded_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$returRequestId, $filePath, $mimeType, $fileSize, $originalName]);
    }

    public function findById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT rr.*, p.name AS product_name, s.canonical_name AS store_name, u.full_name AS verified_by_name
             FROM retur_request rr
             INNER JOIN product p ON p.product_id = rr.product_id
             INNER JOIN store s ON s.store_id = rr.store_id
             LEFT JOIN users u ON u.user_id = rr.verified_by
             WHERE rr.retur_request_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Row-locked (FOR UPDATE) — used inside the admin verify/reject transaction to serialize concurrent decisions on the same request. */
    public function lockById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM retur_request WHERE retur_request_id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findEvidenceForRequest(PDO $pdo, int $returRequestId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM retur_request_evidence WHERE retur_request_id = ? ORDER BY retur_request_evidence_id');
        $stmt->execute([$returRequestId]);
        return $stmt->fetchAll();
    }

    public function findEvidenceById(PDO $pdo, int $evidenceId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM retur_request_evidence WHERE retur_request_evidence_id = ?');
        $stmt->execute([$evidenceId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Permanent Bakery Portal — "Riwayat" tab's Retur list, this store's own submissions only, newest first. */
    public function listForStore(PDO $pdo, int $storeId): array
    {
        $stmt = $pdo->prepare(
            'SELECT rr.*, p.name AS product_name
             FROM retur_request rr
             INNER JOIN product p ON p.product_id = rr.product_id
             WHERE rr.store_id = ?
             ORDER BY rr.retur_request_id DESC'
        );
        $stmt->execute([$storeId]);
        return $stmt->fetchAll();
    }

    /** Admin review queue — every store, optional status filter. */
    public function listForAdmin(PDO $pdo, ?string $status): array
    {
        $sql = 'SELECT rr.*, p.name AS product_name, s.canonical_name AS store_name
                FROM retur_request rr
                INNER JOIN product p ON p.product_id = rr.product_id
                INNER JOIN store s ON s.store_id = rr.store_id
                WHERE 1=1';
        $params = [];
        if ($status !== null) {
            $sql .= ' AND rr.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY rr.retur_request_id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function markVerified(PDO $pdo, int $id, int $adminUserId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE retur_request SET status = 'verified', verified_by = ?, verified_at = UTC_TIMESTAMP(),
                    version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE retur_request_id = ?"
        );
        $stmt->execute([$adminUserId, $id]);
    }

    public function markRejected(PDO $pdo, int $id, string $rejectReason, int $adminUserId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE retur_request SET status = 'rejected', reject_reason = ?, verified_by = ?, verified_at = UTC_TIMESTAMP(),
                    version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE retur_request_id = ?"
        );
        $stmt->execute([$rejectReason, $adminUserId, $id]);
    }
}
