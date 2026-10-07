<?php

declare(strict_types=1);

namespace Amor\Api\StorePortal;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Repositories\ProductRepository;
use Amor\Api\Services\DocumentSequenceService;
use PDO;

/**
 * Permanent Bakery Portal — "Retur" menu (migration 0017). "Barang sudah
 * diterima BAIK, belakangan tidak terjual" — reported later by the store.
 * NOT reject/shortage/production-reject (those stay inside Konfirmasi
 * Penerimaan / Replacement Reject, entirely untouched by this class).
 *
 * TWO RULES ARE LOCKED BY THE TASK and enforced here simply by what this
 * class never does:
 *   - FINANCIAL: 100% store burden. This class never reads or writes any
 *     invoice/billing table (none even exists on this branch yet) — there
 *     is no code path here that could reduce an invoice or create a
 *     credit note.
 *   - STOCK: operational record only. This class never calls StockLedger
 *     or touches stock_balance — Factory FG stock can never be auto-
 *     increased by a Retur submission. A later PHYSICAL return would be a
 *     SEPARATE, explicit receipt/QC event this class has no part in.
 *
 * Photo evidence is MANDATORY (>=1) at submission — enforced here, same
 * "app-layer invariant over DB CHECK constraint" convention as every
 * other evidence-required rule in this codebase (e.g. ReceiptService::
 * validateReceiptLines()'s own EVIDENCE_REQUIRED).
 */
final class ReturService
{
    private ReturRepository $repo;
    private ProductRepository $productRepo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new ReturRepository();
        $this->productRepo = new ProductRepository();
    }

    /**
     * @param array<int,array{filePath:string,mimeType:string,fileSize:int,originalName:?string}> $evidenceFiles already validated/moved to disk by the controller (EvidenceUploader::validateAndStore(..., 'retur-evidence')) BEFORE this call — mandatory, must be non-empty.
     * @throws ApiException 400 EVIDENCE_REQUIRED | PRODUCT_NOT_FOUND | INVALID_QTY | REASON_REQUIRED
     */
    public function createForStorePortal(int $storeId, array $input, array $evidenceFiles, ?int $submittedViaTokenId, ?string $requestId): array
    {
        if ($evidenceFiles === []) {
            throw new ApiException(400, 'EVIDENCE_REQUIRED', 'Foto bukti wajib diunggah untuk pengajuan Retur');
        }

        $productId = (int) ($input['productId'] ?? 0);
        $product = $productId > 0 ? $this->productRepo->findById($this->pdo, $productId) : null;
        if ($product === null) {
            throw new ApiException(404, 'PRODUCT_NOT_FOUND', 'Produk tidak ditemukan');
        }

        $qty = (float) ($input['qty'] ?? 0);
        if ($qty <= 0) {
            throw new ApiException(400, 'INVALID_QTY', 'Jumlah retur harus lebih dari 0');
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiException(400, 'REASON_REQUIRED', 'Alasan retur wajib diisi');
        }

        $notes = isset($input['notes']) && trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null;
        $returDate = isset($input['returDate']) && trim((string) $input['returDate']) !== ''
            ? (string) $input['returDate']
            : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $docNo = sprintf('RETUR-%s-%03d', $now->format('Ymd'), DocumentSequenceService::allocate($this->pdo, 'RETUR', (int) $now->format('Y'), (int) $now->format('n')));

        $returId = $this->repo->insert($this->pdo, $docNo, $storeId, $productId, $qty, $reason, $notes, $returDate, $submittedViaTokenId);
        foreach ($evidenceFiles as $ev) {
            $this->repo->insertEvidence($this->pdo, $returId, $ev['filePath'], $ev['mimeType'], $ev['fileSize'], $ev['originalName']);
        }

        // user_id = null: the Portal identifies a BAKERY (a token, never a
        // users row) — same "no users FK for an unauthenticated store
        // actor" discipline as every public receipt path (Dispatch\
        // ReceiptService). storeId is captured in the payload instead.
        Audit::write(
            $this->pdo, $requestId, null, 'retur.created', 'retur_request', (string) $returId,
            'ok', null, 1, ['storeId' => $storeId, 'productId' => $productId, 'qty' => $qty, 'docNo' => $docNo]
        );

        return $this->buildDto($this->repo->findById($this->pdo, $returId));
    }

    /** @throws ApiException 404 NOT_FOUND if the request doesn't exist or doesn't belong to $storeId */
    public function getForStorePortal(int $storeId, int $returId): array
    {
        $row = $this->repo->findById($this->pdo, $returId);
        if ($row === null || (int) $row['store_id'] !== $storeId) {
            throw new ApiException(404, 'NOT_FOUND', 'Pengajuan retur tidak ditemukan untuk toko ini');
        }
        return $this->buildDto($row);
    }

    /** Permanent Bakery Portal — "Riwayat" tab's Retur list, this store's own submissions only. */
    public function listForStorePortal(int $storeId): array
    {
        $rows = $this->repo->listForStore($this->pdo, $storeId);
        return array_map(fn ($r) => $this->buildSummaryDto($r), $rows);
    }

    /** Admin review queue (ADMIN/PPIC) — every store, optional status filter. */
    public function adminList(?string $status): array
    {
        $rows = $this->repo->listForAdmin($this->pdo, $status);
        return array_map(fn ($r) => $this->buildSummaryDto($r), $rows);
    }

    public function getForAdmin(int $returId): array
    {
        $row = $this->repo->findById($this->pdo, $returId);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Pengajuan retur tidak ditemukan');
        }
        return $this->buildDto($row);
    }

    /** @throws ApiException 404 NOT_FOUND | 409 INVALID_RETUR_STATUS */
    public function adminVerify(int $returId, int $adminUserId, ?string $requestId): array
    {
        $row = $this->repo->lockById($this->pdo, $returId);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Pengajuan retur tidak ditemukan');
        }
        if ($row['status'] !== 'waiting_admin_verification') {
            throw new ApiException(409, 'INVALID_RETUR_STATUS', 'Pengajuan retur ini sudah diproses sebelumnya');
        }
        $this->repo->markVerified($this->pdo, $returId, $adminUserId);
        Audit::write($this->pdo, $requestId, $adminUserId, 'retur.verified', 'retur_request', (string) $returId, 'ok', 1, 2, null);
        return $this->buildDto($this->repo->findById($this->pdo, $returId));
    }

    /** @throws ApiException 400 REASON_REQUIRED | 404 NOT_FOUND | 409 INVALID_RETUR_STATUS */
    public function adminReject(int $returId, string $reason, int $adminUserId, ?string $requestId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ApiException(400, 'REASON_REQUIRED', 'Alasan penolakan wajib diisi');
        }
        $row = $this->repo->lockById($this->pdo, $returId);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Pengajuan retur tidak ditemukan');
        }
        if ($row['status'] !== 'waiting_admin_verification') {
            throw new ApiException(409, 'INVALID_RETUR_STATUS', 'Pengajuan retur ini sudah diproses sebelumnya');
        }
        $this->repo->markRejected($this->pdo, $returId, $reason, $adminUserId);
        Audit::write($this->pdo, $requestId, $adminUserId, 'retur.rejected', 'retur_request', (string) $returId, 'ok', 1, 2, ['reason' => $reason]);
        return $this->buildDto($this->repo->findById($this->pdo, $returId));
    }

    private function buildDto(array $r): array
    {
        $evidence = $this->repo->findEvidenceForRequest($this->pdo, (int) $r['retur_request_id']);
        return [
            'returId' => (int) $r['retur_request_id'],
            'docNo' => $r['doc_no'],
            'storeId' => (int) $r['store_id'],
            'storeName' => $r['store_name'] ?? null,
            'productId' => (int) $r['product_id'],
            'productName' => $r['product_name'],
            'qty' => (float) $r['qty'],
            'reason' => $r['reason'],
            'notes' => $r['notes'],
            'returDate' => $r['retur_date'],
            'status' => $r['status'],
            'rejectReason' => $r['reject_reason'],
            'verifiedByName' => $r['verified_by_name'] ?? null,
            'verifiedAt' => $r['verified_at'],
            'version' => (int) $r['version'],
            'createdAt' => $r['created_at'],
            'evidence' => array_map(static fn ($ev) => [
                'evidenceId' => (int) $ev['retur_request_evidence_id'],
                'mimeType' => $ev['mime_type'],
                'fileSize' => (int) $ev['file_size'],
                'originalName' => $ev['original_name'],
                'uploadedAt' => $ev['uploaded_at'],
            ], $evidence),
        ];
    }

    private function buildSummaryDto(array $r): array
    {
        return [
            'returId' => (int) $r['retur_request_id'],
            'docNo' => $r['doc_no'],
            'storeName' => $r['store_name'] ?? null,
            'productName' => $r['product_name'],
            'qty' => (float) $r['qty'],
            'returDate' => $r['retur_date'],
            'status' => $r['status'],
            'createdAt' => $r['created_at'],
        ];
    }
}
