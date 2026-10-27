<?php

declare(strict_types=1);

namespace Amor\Api\StorePortal;

use PDO;

/**
 * Persistence for retur_request / retur_request_evidence (migrations 0017
 * + 0020). Holds both store after-receipt reports: jenis='retur' (record
 * only, store burden) and jenis='reject_toko' (factory burden, deducted
 * through a Nota Potongan — written by Invoice\NotaPotonganService,
 * never here). No stock_ledger write path anywhere in
 * this class.
 */
final class ReturRepository
{
    public function insert(PDO $pdo, string $docNo, int $storeId, int $productId, float $qty, string $reason, ?string $notes, string $returDate, ?int $submittedViaTokenId, string $jenis = 'retur', ?string $kondisi = null, ?string $expDate = null, ?int $storeReportId = null): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO retur_request
                (doc_no, store_id, product_id, qty, reason, notes, retur_date, status, submitted_via_token_id, jenis, kondisi, exp_date, store_report_id, validasi_status, version, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'waiting_admin_verification\', ?, ?, ?, ?, ?, \'menunggu\', 1, UTC_TIMESTAMP())'
        );
        $stmt->execute([$docNo, $storeId, $productId, $qty, $reason, $notes, $returDate, $submittedViaTokenId, $jenis, $kondisi, $expDate, $storeReportId]);
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
            'SELECT rr.*, p.name AS product_name, s.canonical_name AS store_name, u.full_name AS verified_by_name, p.harga, so.order_no AS pengganti_order_no, inv.invoice_no, np.nota_potongan_id AS nota_id, np.nota_no, np.total AS nota_total
             FROM retur_request rr
             INNER JOIN product p ON p.product_id = rr.product_id
             INNER JOIN store s ON s.store_id = rr.store_id
             LEFT JOIN users u ON u.user_id = rr.verified_by
             LEFT JOIN special_order so ON so.special_order_id = rr.pengganti_order_id
             LEFT JOIN nota_potongan np ON np.retur_request_id = rr.retur_request_id
             LEFT JOIN invoice inv ON inv.invoice_id = np.invoice_id
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
            'SELECT rr.*, p.name AS product_name, p.harga, so.order_no AS pengganti_order_no, inv.invoice_no, np.nota_potongan_id AS nota_id, np.nota_no, np.total AS nota_total
             FROM retur_request rr
             INNER JOIN product p ON p.product_id = rr.product_id
             LEFT JOIN special_order so ON so.special_order_id = rr.pengganti_order_id
             LEFT JOIN nota_potongan np ON np.retur_request_id = rr.retur_request_id
             LEFT JOIN invoice inv ON inv.invoice_id = np.invoice_id
             WHERE rr.store_id = ?
             ORDER BY rr.retur_request_id DESC'
        );
        $stmt->execute([$storeId]);
        return $stmt->fetchAll();
    }

    /** Admin review queue — every store, optional status filter. */
    public function listForAdmin(PDO $pdo, ?string $status, ?string $jenis = null): array
    {
        $sql = 'SELECT rr.*, p.name AS product_name, s.canonical_name AS store_name, p.harga, so.order_no AS pengganti_order_no, inv.invoice_no, np.nota_potongan_id AS nota_id, np.nota_no, np.total AS nota_total
                FROM retur_request rr
                INNER JOIN product p ON p.product_id = rr.product_id
                INNER JOIN store s ON s.store_id = rr.store_id
                LEFT JOIN special_order so ON so.special_order_id = rr.pengganti_order_id
                LEFT JOIN nota_potongan np ON np.retur_request_id = rr.retur_request_id
                LEFT JOIN invoice inv ON inv.invoice_id = np.invoice_id
                LEFT JOIN store_report sr ON sr.store_report_id = rr.store_report_id
                WHERE (sr.store_report_id IS NULL OR sr.status = \'disetujui\')';
        $params = [];
        if ($status !== null) {
            $sql .= ' AND rr.status = ?';
            $params[] = $status;
        }
        if ($jenis !== null) {
            $sql .= ' AND rr.jenis = ?';
            $params[] = $jenis;
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
                    validasi_status = COALESCE(NULLIF(validasi_status, 'menunggu'), 'sesuai'), validasi_by = COALESCE(validasi_by, verified_by), validasi_at = COALESCE(validasi_at, UTC_TIMESTAMP()),
                    version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE retur_request_id = ?"
        );
        $stmt->execute([$adminUserId, $id]);
    }

    public function markRejectTokoVerified(PDO $pdo, int $id, int $adminUserId, string $tindakLanjut, ?int $penggantiOrderId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE retur_request SET status = 'verified', verified_by = ?, verified_at = UTC_TIMESTAMP(),
                    validasi_status = COALESCE(NULLIF(validasi_status, 'menunggu'), 'sesuai'), validasi_by = COALESCE(validasi_by, verified_by), validasi_at = COALESCE(validasi_at, UTC_TIMESTAMP()),
                    tindak_lanjut = ?, pengganti_order_id = ?,
                    version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE retur_request_id = ?"
        );
        $stmt->execute([$adminUserId, $tindakLanjut, $penggantiOrderId, $id]);
    }

    public function markRejected(PDO $pdo, int $id, string $rejectReason, int $adminUserId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE retur_request SET status = 'rejected', reject_reason = ?, verified_by = ?, verified_at = UTC_TIMESTAMP(),
                    validasi_status = 'tidak_sesuai', validasi_note = COALESCE(validasi_note, reject_reason), validasi_by = COALESCE(validasi_by, verified_by), validasi_at = COALESCE(validasi_at, UTC_TIMESTAMP()),
                    version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE retur_request_id = ?"
        );
        $stmt->execute([$rejectReason, $adminUserId, $id]);
    }

    /** True when the item is free for Admin/destination processing: no store report, or its report is approved. */
    public static function reportApproved(PDO $pdo, ?int $storeReportId): bool
    {
        if ($storeReportId === null) {
            return true;
        }
        $stmt = $pdo->prepare('SELECT status FROM store_report WHERE store_report_id = ?');
        $stmt->execute([$storeReportId]);
        return $stmt->fetchColumn() === 'disetujui';
    }
}
