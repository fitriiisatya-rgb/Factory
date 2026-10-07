<?php

declare(strict_types=1);

namespace Amor\Api\StorePortal;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Repositories\ProductRepository;
use Amor\Api\Repositories\StoreRepository;
use Amor\Api\Services\DocumentSequenceService;
use PDO;

/**
 * Permanent Bakery Portal — "Mutasi Produk" menu (migration 0017). A
 * store requests a transfer of RESPONSIBILITY for a product to another
 * store — never a physical/Factory stock movement.
 *
 * MUTATION CORE RULE (LOCKED): does NOT change Factory stock, only
 * transfers which store is responsible for the product. Enforced simply
 * by what this class never does — no call anywhere in this file to
 * StockLedger, stock_balance, FgService, or ShipmentService. Only
 * ShipmentService::ship() (shipment_out) and FgService::submit()
 * (production_in) may ever write stock_ledger in this codebase.
 *
 * DUAL CONFIRMATION: the source store (A) submits — status
 * waiting_destination_confirmation. The destination store (B) later
 * confirms Qty Diterima/Catatan/evidence through its OWN portal token
 * (never A's). Qty matches -> completed. Qty differs (damage/mismatch)
 * -> discrepancy, routed to Admin Review (adminReviewDiscrepancy()).
 *
 * MUTATION INVOICE RULE (LOCKED): qty_received (never qty_requested) is
 * the one authoritative figure a future Invoice phase must read, and only
 * once status=completed — this class builds no invoice effect at all, it
 * only PERSISTS that data. "Do NOT build a fake invoice engine."
 *
 * MUTATION IMMUTABILITY (LOCKED): once a request reaches completed/
 * discrepancy/cancelled, this class has NO update path back to
 * waiting_destination_confirmation and no "edit qty" method at all — a
 * correction is always a brand-new mutasi_request row (schema already
 * carries reversal_of_mutasi_request_id for that), never a mutation of
 * history.
 */
final class MutasiService
{
    private MutasiRepository $repo;
    private ProductRepository $productRepo;
    private StoreRepository $storeRepo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new MutasiRepository();
        $this->productRepo = new ProductRepository();
        $this->storeRepo = new StoreRepository();
    }

    /**
     * Source store (A) submission. Evidence is MANDATORY (>=1) at this
     * stage (task's own LOCKED rule) — enforced before anything is
     * written. destinationStoreId/productId/qty come from the client but
     * are fully validated; sourceStoreId is NEVER taken from the client —
     * always the token-resolved identity.
     *
     * @param array<int,array{filePath:string,mimeType:string,fileSize:int,originalName:?string}> $evidenceFiles already validated/moved to disk by the controller (EvidenceUploader::validateAndStore(..., 'mutasi-evidence')) — mandatory, must be non-empty.
     * @throws ApiException 400 EVIDENCE_REQUIRED | INVALID_QTY | SAME_STORE | DESTINATION_STORE_REQUIRED | 404 PRODUCT_NOT_FOUND | DESTINATION_STORE_NOT_FOUND
     */
    public function createForStorePortal(int $sourceStoreId, array $input, array $evidenceFiles, ?int $requestedViaTokenId, ?string $requestId): array
    {
        if ($evidenceFiles === []) {
            throw new ApiException(400, 'EVIDENCE_REQUIRED', 'Foto bukti wajib diunggah saat mengajukan Mutasi');
        }

        $destinationStoreId = (int) ($input['destinationStoreId'] ?? 0);
        if ($destinationStoreId <= 0) {
            throw new ApiException(400, 'DESTINATION_STORE_REQUIRED', 'Toko tujuan wajib dipilih');
        }
        if ($destinationStoreId === $sourceStoreId) {
            throw new ApiException(400, 'SAME_STORE', 'Toko tujuan tidak boleh sama dengan toko asal');
        }
        $destinationStore = $this->storeRepo->findById($this->pdo, $destinationStoreId);
        if ($destinationStore === null || !(bool) $destinationStore['active']) {
            throw new ApiException(404, 'DESTINATION_STORE_NOT_FOUND', 'Toko tujuan tidak ditemukan atau tidak aktif');
        }

        $productId = (int) ($input['productId'] ?? 0);
        $product = $productId > 0 ? $this->productRepo->findById($this->pdo, $productId) : null;
        if ($product === null) {
            throw new ApiException(404, 'PRODUCT_NOT_FOUND', 'Produk tidak ditemukan');
        }

        $qty = (float) ($input['qty'] ?? 0);
        if ($qty <= 0) {
            throw new ApiException(400, 'INVALID_QTY', 'Jumlah mutasi harus lebih dari 0');
        }

        $notes = isset($input['notes']) && trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $docNo = sprintf('MUTASI-%s-%03d', $now->format('Ymd'), DocumentSequenceService::allocate($this->pdo, 'MUTASI', (int) $now->format('Y'), (int) $now->format('n')));

        $mutasiId = $this->repo->insert($this->pdo, $docNo, $sourceStoreId, $destinationStoreId, $productId, $qty, $notes, $requestedViaTokenId);
        foreach ($evidenceFiles as $ev) {
            $this->repo->insertEvidence($this->pdo, $mutasiId, 'request', $ev['filePath'], $ev['mimeType'], $ev['fileSize'], $ev['originalName']);
        }

        Audit::write(
            $this->pdo, $requestId, null, 'mutasi.created', 'mutasi_request', (string) $mutasiId,
            'ok', null, 1, ['sourceStoreId' => $sourceStoreId, 'destinationStoreId' => $destinationStoreId, 'productId' => $productId, 'qty' => $qty, 'docNo' => $docNo]
        );

        return $this->buildDto($this->repo->findById($this->pdo, $mutasiId));
    }

    /**
     * Destination store (B) confirmation. qtyReceived matching
     * qtyRequested -> completed; any difference -> discrepancy (Admin
     * Review). Evidence is optional UNLESS there is a discrepancy, in
     * which case it becomes mandatory (>=1) — task's own exact wording
     * ("required-if-discrepancy").
     *
     * @param array<int,array{filePath:string,mimeType:string,fileSize:int,originalName:?string}> $evidenceFiles
     * @throws ApiException 400 EVIDENCE_REQUIRED | INVALID_QTY | 404 NOT_FOUND | 409 INVALID_MUTASI_STATUS
     */
    public function confirmForStorePortal(int $destinationStoreId, int $mutasiId, array $input, array $evidenceFiles, ?int $confirmedViaTokenId, ?string $requestId): array
    {
        $row = $this->repo->lockById($this->pdo, $mutasiId);
        if ($row === null || (int) $row['destination_store_id'] !== $destinationStoreId) {
            throw new ApiException(404, 'NOT_FOUND', 'Mutasi masuk tidak ditemukan untuk toko ini');
        }
        if ($row['status'] !== 'waiting_destination_confirmation') {
            throw new ApiException(409, 'INVALID_MUTASI_STATUS', 'Mutasi ini sudah dikonfirmasi atau diproses sebelumnya');
        }

        $qtyReceived = isset($input['qtyReceived']) ? (float) $input['qtyReceived'] : -1.0;
        if ($qtyReceived < 0) {
            throw new ApiException(400, 'INVALID_QTY', 'Jumlah diterima tidak boleh kosong atau negatif');
        }
        $destinationNotes = isset($input['notes']) && trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null;

        $isDiscrepancy = abs($qtyReceived - (float) $row['qty_requested']) > 0.0001;
        if ($isDiscrepancy && $evidenceFiles === []) {
            throw new ApiException(400, 'EVIDENCE_REQUIRED', 'Bukti foto wajib diunggah karena jumlah diterima berbeda dari jumlah dikirim');
        }
        $resolutionStatus = $isDiscrepancy ? 'discrepancy' : 'completed';

        $this->repo->markConfirmed($this->pdo, $mutasiId, $resolutionStatus, $qtyReceived, $destinationNotes, $confirmedViaTokenId);
        foreach ($evidenceFiles as $ev) {
            $this->repo->insertEvidence($this->pdo, $mutasiId, 'confirmation', $ev['filePath'], $ev['mimeType'], $ev['fileSize'], $ev['originalName']);
        }

        Audit::write(
            $this->pdo, $requestId, null, 'mutasi.confirmed', 'mutasi_request', (string) $mutasiId,
            'ok', (int) $row['version'], (int) $row['version'] + 1,
            ['destinationStoreId' => $destinationStoreId, 'qtyRequested' => (float) $row['qty_requested'], 'qtyReceived' => $qtyReceived, 'resolution' => $resolutionStatus]
        );

        return $this->buildDto($this->repo->findById($this->pdo, $mutasiId));
    }

    /** @throws ApiException 404 NOT_FOUND if the request doesn't exist or $storeId is neither its source nor its destination */
    public function getForStorePortal(int $storeId, int $mutasiId): array
    {
        $row = $this->repo->findById($this->pdo, $mutasiId);
        if ($row === null || ((int) $row['source_store_id'] !== $storeId && (int) $row['destination_store_id'] !== $storeId)) {
            throw new ApiException(404, 'NOT_FOUND', 'Mutasi tidak ditemukan untuk toko ini');
        }
        return $this->buildDto($row);
    }

    /** Permanent Bakery Portal — "Mutasi Produk" tab's own incoming action list: pending confirmations only, this store as destination. */
    public function listIncomingPendingForStorePortal(int $storeId): array
    {
        return array_map(fn ($r) => $this->buildSummaryDto($r), $this->repo->listIncomingForStore($this->pdo, $storeId, true));
    }

    /** Permanent Bakery Portal — "Riwayat" tab's "Mutasi Keluar", this store as source, every status. */
    public function listOutgoingForStorePortal(int $storeId): array
    {
        return array_map(fn ($r) => $this->buildSummaryDto($r), $this->repo->listOutgoingForStore($this->pdo, $storeId));
    }

    /** Permanent Bakery Portal — "Riwayat" tab's "Mutasi Masuk", this store as destination, every status (not just pending). */
    public function listIncomingForStorePortal(int $storeId): array
    {
        return array_map(fn ($r) => $this->buildSummaryDto($r), $this->repo->listIncomingForStore($this->pdo, $storeId, false));
    }

    /** Admin review queue (ADMIN/PPIC) — every store pair, optional status filter (typically 'discrepancy'). */
    public function adminList(?string $status): array
    {
        return array_map(fn ($r) => $this->buildSummaryDto($r), $this->repo->listForAdmin($this->pdo, $status));
    }

    public function getForAdmin(int $mutasiId): array
    {
        $row = $this->repo->findById($this->pdo, $mutasiId);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Mutasi tidak ditemukan');
        }
        return $this->buildDto($row);
    }

    /**
     * Admin Review — the ONLY next step for a 'discrepancy' request
     * (task's own "Mismatch/damage -> DISCREPANCY -> Admin Review").
     * $resolution is 'completed' (Admin accepts the destination's reported
     * qty_received as final — the mutation proceeds with that qty) or
     * 'cancelled' (Admin voids the mutation entirely — responsibility
     * never transfers). Never 'waiting_destination_confirmation' again —
     * MUTATION IMMUTABILITY means there is no path back to re-confirm; a
     * genuine do-over is a brand-new mutasi_request.
     *
     * @throws ApiException 400 INVALID_RESOLUTION | REASON_REQUIRED | 404 NOT_FOUND | 409 INVALID_MUTASI_STATUS
     */
    public function adminReviewDiscrepancy(int $mutasiId, string $resolution, string $notes, int $adminUserId, ?string $requestId): array
    {
        if (!in_array($resolution, ['completed', 'cancelled'], true)) {
            throw new ApiException(400, 'INVALID_RESOLUTION', "resolution must be 'completed' or 'cancelled'");
        }
        $notes = trim($notes);
        if ($notes === '') {
            throw new ApiException(400, 'REASON_REQUIRED', 'Catatan keputusan Admin wajib diisi');
        }
        $row = $this->repo->lockById($this->pdo, $mutasiId);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Mutasi tidak ditemukan');
        }
        if ($row['status'] !== 'discrepancy') {
            throw new ApiException(409, 'INVALID_MUTASI_STATUS', 'Mutasi ini tidak sedang menunggu keputusan Admin');
        }

        $this->repo->markAdminReviewed($this->pdo, $mutasiId, $resolution, $notes, $adminUserId);
        Audit::write(
            $this->pdo, $requestId, $adminUserId, 'mutasi.admin_reviewed', 'mutasi_request', (string) $mutasiId,
            'ok', (int) $row['version'], (int) $row['version'] + 1, ['resolution' => $resolution, 'notes' => $notes]
        );

        return $this->buildDto($this->repo->findById($this->pdo, $mutasiId));
    }

    private function buildDto(array $r): array
    {
        $evidence = $this->repo->findEvidenceForRequest($this->pdo, (int) $r['mutasi_request_id']);
        return [
            'mutasiId' => (int) $r['mutasi_request_id'],
            'docNo' => $r['doc_no'],
            'sourceStoreId' => (int) $r['source_store_id'],
            'sourceStoreName' => $r['source_store_name'],
            'destinationStoreId' => (int) $r['destination_store_id'],
            'destinationStoreName' => $r['destination_store_name'],
            'productId' => (int) $r['product_id'],
            'productName' => $r['product_name'],
            'qtyRequested' => (float) $r['qty_requested'],
            'requestNotes' => $r['request_notes'],
            'qtyReceived' => $r['qty_received'] !== null ? (float) $r['qty_received'] : null,
            'destinationNotes' => $r['destination_notes'],
            'status' => $r['status'],
            'adminReviewNotes' => $r['admin_review_notes'],
            'adminReviewedByName' => $r['admin_reviewed_by_name'] ?? null,
            'adminReviewedAt' => $r['admin_reviewed_at'],
            'confirmedAt' => $r['confirmed_at'],
            'reversalOfMutasiId' => $r['reversal_of_mutasi_request_id'] !== null ? (int) $r['reversal_of_mutasi_request_id'] : null,
            'version' => (int) $r['version'],
            'createdAt' => $r['created_at'],
            'evidence' => array_map(static fn ($ev) => [
                'evidenceId' => (int) $ev['mutasi_request_evidence_id'],
                'stage' => $ev['stage'],
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
            'mutasiId' => (int) $r['mutasi_request_id'],
            'docNo' => $r['doc_no'],
            'sourceStoreName' => $r['source_store_name'] ?? null,
            'destinationStoreName' => $r['destination_store_name'] ?? null,
            'productName' => $r['product_name'],
            'qtyRequested' => (float) $r['qty_requested'],
            'qtyReceived' => $r['qty_received'] !== null ? (float) $r['qty_received'] : null,
            'status' => $r['status'],
            'createdAt' => $r['created_at'],
        ];
    }
}
