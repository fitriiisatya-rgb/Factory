<?php

declare(strict_types=1);

namespace Amor\Api\StorePortal;

use PDO;

/**
 * Persistence for mutasi_request / mutasi_request_evidence (migration
 * 0017) — store-to-store RESPONSIBILITY transfer. MUTATION CORE RULE
 * (LOCKED): never changes Factory stock — this class has no code path
 * that writes stock_ledger/stock_balance, only ShipmentService::ship()
 * and FgService::submit() may ever do that.
 */
final class MutasiRepository
{
    public function insert(PDO $pdo, string $docNo, int $sourceStoreId, int $destinationStoreId, int $productId, float $qtyRequested, ?string $requestNotes, ?int $requestedViaTokenId): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO mutasi_request
                (doc_no, source_store_id, destination_store_id, product_id, qty_requested, request_notes, status, requested_via_token_id, version, created_at)
             VALUES (?, ?, ?, ?, ?, ?, \'waiting_destination_confirmation\', ?, 1, UTC_TIMESTAMP())'
        );
        $stmt->execute([$docNo, $sourceStoreId, $destinationStoreId, $productId, $qtyRequested, $requestNotes, $requestedViaTokenId]);
        return (int) $pdo->lastInsertId();
    }

    public function insertEvidence(PDO $pdo, int $mutasiRequestId, string $stage, string $filePath, string $mimeType, int $fileSize, ?string $originalName): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO mutasi_request_evidence
                (mutasi_request_id, stage, file_path, mime_type, file_size, original_name, uploaded_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$mutasiRequestId, $stage, $filePath, $mimeType, $fileSize, $originalName]);
    }

    public function findById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT mr.*, p.name AS product_name,
                    ss.canonical_name AS source_store_name, ds.canonical_name AS destination_store_name,
                    u.full_name AS admin_reviewed_by_name
             FROM mutasi_request mr
             INNER JOIN product p ON p.product_id = mr.product_id
             INNER JOIN store ss ON ss.store_id = mr.source_store_id
             INNER JOIN store ds ON ds.store_id = mr.destination_store_id
             LEFT JOIN users u ON u.user_id = mr.admin_reviewed_by
             WHERE mr.mutasi_request_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Row-locked (FOR UPDATE) — used inside the confirm/admin-review transaction to serialize concurrent decisions on the same request. */
    public function lockById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM mutasi_request WHERE mutasi_request_id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findEvidenceForRequest(PDO $pdo, int $mutasiRequestId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM mutasi_request_evidence WHERE mutasi_request_id = ? ORDER BY mutasi_request_evidence_id');
        $stmt->execute([$mutasiRequestId]);
        return $stmt->fetchAll();
    }

    public function findEvidenceById(PDO $pdo, int $evidenceId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM mutasi_request_evidence WHERE mutasi_request_evidence_id = ?');
        $stmt->execute([$evidenceId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Permanent Bakery Portal — "Riwayat" tab's "Mutasi Keluar", this store as SOURCE only, every status, newest first. */
    public function listOutgoingForStore(PDO $pdo, int $storeId): array
    {
        $stmt = $pdo->prepare(
            'SELECT mr.*, p.name AS product_name, ds.canonical_name AS destination_store_name
             FROM mutasi_request mr
             INNER JOIN product p ON p.product_id = mr.product_id
             INNER JOIN store ds ON ds.store_id = mr.destination_store_id
             WHERE mr.source_store_id = ?
             ORDER BY mr.mutasi_request_id DESC'
        );
        $stmt->execute([$storeId]);
        return $stmt->fetchAll();
    }

    /** Permanent Bakery Portal — "Mutasi Produk" incoming list + "Riwayat" tab's "Mutasi Masuk", this store as DESTINATION only. $onlyPending restricts to the action-needed tab (waiting_destination_confirmation); false returns full history for Riwayat. */
    public function listIncomingForStore(PDO $pdo, int $storeId, bool $onlyPending): array
    {
        $sql = 'SELECT mr.*, p.name AS product_name, ss.canonical_name AS source_store_name
                FROM mutasi_request mr
                INNER JOIN product p ON p.product_id = mr.product_id
                INNER JOIN store ss ON ss.store_id = mr.source_store_id
                WHERE mr.destination_store_id = ?';
        $params = [$storeId];
        if ($onlyPending) {
            $sql .= " AND mr.status = 'waiting_destination_confirmation'";
        }
        $sql .= ' ORDER BY mr.mutasi_request_id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Admin review queue — every store, optional status filter. */
    public function listForAdmin(PDO $pdo, ?string $status): array
    {
        $sql = 'SELECT mr.*, p.name AS product_name, ss.canonical_name AS source_store_name, ds.canonical_name AS destination_store_name
                FROM mutasi_request mr
                INNER JOIN product p ON p.product_id = mr.product_id
                INNER JOIN store ss ON ss.store_id = mr.source_store_id
                INNER JOIN store ds ON ds.store_id = mr.destination_store_id
                WHERE 1=1';
        $params = [];
        if ($status !== null) {
            $sql .= ' AND mr.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY mr.mutasi_request_id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function markConfirmed(PDO $pdo, int $id, string $status, float $qtyReceived, ?string $destinationNotes, ?int $confirmedViaTokenId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE mutasi_request SET status = ?, qty_received = ?, destination_notes = ?,
                    confirmed_via_token_id = ?, confirmed_at = UTC_TIMESTAMP(),
                    version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE mutasi_request_id = ?"
        );
        $stmt->execute([$status, $qtyReceived, $destinationNotes, $confirmedViaTokenId, $id]);
    }

    public function markAdminReviewed(PDO $pdo, int $id, string $resolutionStatus, string $adminReviewNotes, int $adminUserId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE mutasi_request SET status = ?, admin_review_notes = ?, admin_reviewed_by = ?, admin_reviewed_at = UTC_TIMESTAMP(),
                    version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE mutasi_request_id = ?"
        );
        $stmt->execute([$resolutionStatus, $adminReviewNotes, $adminUserId, $id]);
    }
}
