<?php

declare(strict_types=1);

namespace Amor\Api\StorePortal;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Invoice\NotaPotonganService;
use Amor\Api\Repositories\ProductRepository;
use Amor\Api\Services\DocumentSequenceService;
use Amor\Api\SpecialOrder\SpecialOrderService;
use PDO;

/**
 * Permanent Bakery Portal — "Lapor Barang" menu (migrations 0017 + 0020).
 * Two kinds of after-receipt report share this table, told apart by
 * `jenis`, and their financial rules are OPPOSITE:
 *
 *   - 'retur' — "Barang sudah diterima BAIK, belakangan tidak terjual".
 *     100% store burden, record only: never reduces an invoice, never
 *     touches stock.
 *   - 'reject_toko' — goods that spoiled in the store BEFORE their expiry
 *     date (e.g. mold). Factory burden: once Admin verifies it (choosing
 *     'potong' or 'ganti'), a Nota Potongan (migration 0021) credits
 *     qty x harga against the invoice of the delivery the goods came from.
 *     'ganti' additionally creates a Pesanan Khusus Toko replacement,
 *     billed normally on receipt — so the store pays once for goods it
 *     could actually sell.
 *
 * Neither kind ever touches StockLedger / stock_balance — the spoiled or
 * unsold goods never return to Factory FG stock.
 *
 * Reject AT RECEIPT (barang datang rusak) is NOT handled here — it stays in
 * Konfirmasi Penerimaan / Replacement Reject.
 *
 * Photo evidence is MANDATORY (>=1) at submission — enforced here, same
 * "app-layer invariant over DB CHECK constraint" convention as every
 * other evidence-required rule in this codebase (e.g. ReceiptService::
 * validateReceiptLines()'s own EVIDENCE_REQUIRED).
 */
final class ReturService
{
    /** Allowed "kondisi" values for a Reject di Toko — label shown to Admin and in reports. */
    public const KONDISI = [
        'jamur' => 'Berjamur',
        'basi' => 'Basi / bau / asam',
        'keras' => 'Keras / kering',
        'rusak' => 'Rusak / penyok / bocor',
        'lainnya' => 'Lainnya',
    ];

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
        $jenis = (string) ($input['jenis'] ?? 'retur');
        if (!in_array($jenis, ['retur', 'reject_toko'], true)) {
            throw new ApiException(400, 'INVALID_JENIS', 'Jenis laporan tidak dikenal');
        }
        $isReject = $jenis === 'reject_toko';
        $label = $isReject ? 'Reject' : 'Retur';

        if ($evidenceFiles === []) {
            throw new ApiException(400, 'EVIDENCE_REQUIRED', "Foto bukti wajib diunggah untuk pengajuan {$label}");
        }

        $productId = (int) ($input['productId'] ?? 0);
        $product = $productId > 0 ? $this->productRepo->findById($this->pdo, $productId) : null;
        if ($product === null) {
            throw new ApiException(404, 'PRODUCT_NOT_FOUND', 'Produk tidak ditemukan');
        }

        $qty = (float) ($input['qty'] ?? 0);
        if ($qty <= 0) {
            throw new ApiException(400, 'INVALID_QTY', 'Jumlah harus lebih dari 0');
        }

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $returDate = isset($input['returDate']) && trim((string) $input['returDate']) !== ''
            ? (string) $input['returDate']
            : $today;

        $kondisi = null;
        $expDate = null;
        if ($isReject) {
            $kondisi = (string) ($input['kondisi'] ?? '');
            if (!isset(self::KONDISI[$kondisi])) {
                throw new ApiException(400, 'KONDISI_REQUIRED', 'Pilih kondisi barang (berjamur, basi, dll.)');
            }
            $expDate = trim((string) ($input['expDate'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expDate)) {
                throw new ApiException(400, 'EXP_DATE_REQUIRED', 'Tanggal expired di kemasan wajib diisi');
            }
            // Spoiled AFTER its expiry date is not a factory defect — the
            // goods simply weren't sold in time, which is a Retur.
            if ($expDate < $returDate) {
                throw new ApiException(400, 'ALREADY_EXPIRED', 'Barang sudah lewat tanggal expired — ini bukan Reject. Laporkan sebagai Retur.');
            }
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '' && $isReject) {
            $reason = self::KONDISI[$kondisi];
        }
        if ($reason === '') {
            throw new ApiException(400, 'REASON_REQUIRED', 'Alasan retur wajib diisi');
        }

        $notes = isset($input['notes']) && trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $prefix = $isReject ? 'RJT' : 'RETUR';
        $docNo = sprintf('%s-%s-%03d', $prefix, $now->format('Ymd'), DocumentSequenceService::allocate($this->pdo, $prefix, (int) $now->format('Y'), (int) $now->format('n')));

        $returId = $this->repo->insert($this->pdo, $docNo, $storeId, $productId, $qty, $reason, $notes, $returDate, $submittedViaTokenId, $jenis, $kondisi, $expDate);
        foreach ($evidenceFiles as $ev) {
            $this->repo->insertEvidence($this->pdo, $returId, $ev['filePath'], $ev['mimeType'], $ev['fileSize'], $ev['originalName']);
        }

        // user_id = null: the Portal identifies a BAKERY (a token, never a
        // users row) — same "no users FK for an unauthenticated store
        // actor" discipline as every public receipt path (Dispatch\
        // ReceiptService). storeId is captured in the payload instead.
        Audit::write(
            $this->pdo, $requestId, null, $isReject ? 'reject_toko.created' : 'retur.created', 'retur_request', (string) $returId,
            'ok', null, 1, ['storeId' => $storeId, 'productId' => $productId, 'qty' => $qty, 'docNo' => $docNo, 'jenis' => $jenis]
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

    /** Admin review queue (ADMIN/PPIC) — every store, optional status / jenis filter. */
    public function adminList(?string $status, ?string $jenis = null): array
    {
        if ($jenis !== null && !in_array($jenis, ['retur', 'reject_toko'], true)) {
            $jenis = null;
        }
        $rows = $this->repo->listForAdmin($this->pdo, $status, $jenis);
        if (\Amor\Api\Auth::isFactoryAdmin()) {
            $rows = array_values(array_filter($rows, fn ($r) => \Amor\Api\AdminScope::visible(\Amor\Api\AdminScope::productFactoryId($this->pdo, (int) $r['product_id']))));
        }
        return array_map(fn ($r) => $this->buildSummaryDto($r), $rows);
    }

    /** Admin Pabrik: a retur / reject belongs to the factory of its product. */
    private function guardFactory(array $row): void
    {
        \Amor\Api\AdminScope::requireAny(array_filter([\Amor\Api\AdminScope::productFactoryId($this->pdo, (int) $row['product_id'])]));
    }

    public function getForAdmin(int $returId): array
    {
        $row = $this->repo->findById($this->pdo, $returId);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Pengajuan retur tidak ditemukan');
        }
        $this->guardFactory($row);
        return $this->buildDto($row);
    }

    /**
     * Retur: just marks it verified (record only).
     * Reject di Toko: $tindakLanjut is REQUIRED — 'potong' (deduct from the
     * next invoice) or 'ganti' (deduct AND create a Pesanan Khusus Toko
     * replacement, confirmed and sent to Production so it can be produced
     * or allocated from Stok Sisa on its detail page).
     *
     * @throws ApiException 400 TINDAK_LANJUT_REQUIRED | 404 NOT_FOUND | 409 INVALID_RETUR_STATUS
     */
    public function adminVerify(int $returId, int $adminUserId, ?string $requestId, ?string $tindakLanjut = null, ?string $requiredDate = null): array
    {
        $row = $this->repo->lockById($this->pdo, $returId);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Pengajuan retur tidak ditemukan');
        }
        $this->guardFactory($row);
        if ($row['status'] !== 'waiting_admin_verification') {
            throw new ApiException(409, 'INVALID_RETUR_STATUS', 'Pengajuan ini sudah diproses sebelumnya');
        }
        if (!ReturRepository::reportApproved($this->pdo, isset($row['store_report_id']) ? (int) $row['store_report_id'] : null)) {
            throw new ApiException(409, 'WAITING_ATASAN', 'Laporan ini masih menunggu persetujuan Atasan toko');
        }

        if (($row['jenis'] ?? 'retur') !== 'reject_toko') {
            $this->repo->markVerified($this->pdo, $returId, $adminUserId);
            Audit::write($this->pdo, $requestId, $adminUserId, 'retur.verified', 'retur_request', (string) $returId, 'ok', 1, 2, null);
            return $this->buildDto($this->repo->findById($this->pdo, $returId));
        }

        if (!in_array($tindakLanjut, ['potong', 'ganti'], true)) {
            throw new ApiException(400, 'TINDAK_LANJUT_REQUIRED', 'Pilih tindak lanjut: Potong invoice atau Ganti barang');
        }

        $orderId = null;
        if ($tindakLanjut === 'ganti') {
            $date = $requiredDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requiredDate)
                ? $requiredDate
                : (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
            $orders = new SpecialOrderService($this->pdo);
            $order = $orders->createOrder([
                'sourceType' => 'toko_khusus',
                'storeId' => (int) $row['store_id'],
                'orderDate' => $date,
                'requiredDate' => $date,
                'generalNote' => 'Pengganti Reject di Toko ' . $row['doc_no'],
                'items' => [['itemType' => 'existing_product', 'productId' => (int) $row['product_id'], 'qty' => (float) $row['qty']]],
            ], $adminUserId, $requestId);
            $order = $orders->confirmOrder((int) $order['orderId'], (int) $order['version'], $adminUserId, $requestId);
            $order = $orders->sendToProduction((int) $order['orderId'], (int) $order['version'], $adminUserId, $requestId);
            $orderId = (int) $order['orderId'];
        }

        $this->repo->markRejectTokoVerified($this->pdo, $returId, $adminUserId, $tindakLanjut, $orderId);
        (new NotaPotonganService($this->pdo))->createForRejectToko($this->repo->lockById($this->pdo, $returId), $adminUserId, $requestId);
        Audit::write(
            $this->pdo, $requestId, $adminUserId, 'reject_toko.verified', 'retur_request', (string) $returId, 'ok', 1, 2,
            ['tindakLanjut' => $tindakLanjut, 'penggantiOrderId' => $orderId]
        );
        return $this->buildDto($this->repo->findById($this->pdo, $returId));
    }

    /**
     * Re-issues the Nota Potongan of an approved Reject di Toko whose nota was
     * voided (e.g. to correct it).
     *
     * @throws ApiException 404 NOT_FOUND | 409 INVALID_RETUR_STATUS | NOTA_EXISTS
     */
    public function issueNota(int $returId, int $adminUserId, ?string $requestId): array
    {
        $row = $this->repo->lockById($this->pdo, $returId);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Pengajuan tidak ditemukan');
        }
        $this->guardFactory($row);
        if (($row['jenis'] ?? '') !== 'reject_toko' || $row['status'] !== 'verified') {
            throw new ApiException(409, 'INVALID_RETUR_STATUS', 'Nota potongan hanya untuk Reject di Toko yang sudah disetujui');
        }
        $has = $this->pdo->prepare('SELECT COUNT(*) FROM nota_potongan WHERE retur_request_id = ?');
        $has->execute([$returId]);
        if ((int) $has->fetchColumn() > 0) {
            throw new ApiException(409, 'NOTA_EXISTS', 'Nota potongan untuk reject ini sudah ada');
        }
        (new NotaPotonganService($this->pdo))->createForRejectToko($row, $adminUserId, $requestId);
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
        $this->guardFactory($row);
        if ($row['status'] !== 'waiting_admin_verification') {
            throw new ApiException(409, 'INVALID_RETUR_STATUS', 'Pengajuan ini sudah diproses sebelumnya');
        }
        if (!ReturRepository::reportApproved($this->pdo, isset($row['store_report_id']) ? (int) $row['store_report_id'] : null)) {
            throw new ApiException(409, 'WAITING_ATASAN', 'Laporan ini masih menunggu persetujuan Atasan toko');
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
            'jenis' => $r['jenis'] ?? 'retur',
            'kondisi' => $r['kondisi'] ?? null,
            'kondisiLabel' => isset($r['kondisi']) ? (self::KONDISI[$r['kondisi']] ?? $r['kondisi']) : null,
            'expDate' => $r['exp_date'] ?? null,
            'tindakLanjut' => $r['tindak_lanjut'] ?? null,
            'penggantiOrderId' => isset($r['pengganti_order_id']) ? (int) $r['pengganti_order_id'] : null,
            'penggantiOrderNo' => $r['pengganti_order_no'] ?? null,
            'notaId' => isset($r['nota_id']) ? (int) $r['nota_id'] : null,
            'notaNo' => $r['nota_no'] ?? null,
            'notaTotal' => isset($r['nota_total']) ? (float) $r['nota_total'] : null,
            'invoiceNo' => $r['invoice_no'] ?? null,
            'harga' => isset($r['harga']) ? (float) $r['harga'] : null,
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
            'reason' => $r['reason'] ?? null,
            'jenis' => $r['jenis'] ?? 'retur',
            'kondisiLabel' => isset($r['kondisi']) ? (self::KONDISI[$r['kondisi']] ?? $r['kondisi']) : null,
            'expDate' => $r['exp_date'] ?? null,
            'tindakLanjut' => $r['tindak_lanjut'] ?? null,
            'penggantiOrderNo' => $r['pengganti_order_no'] ?? null,
            'notaNo' => $r['nota_no'] ?? null,
            'notaTotal' => isset($r['nota_total']) ? (float) $r['nota_total'] : null,
            'invoiceNo' => $r['invoice_no'] ?? null,
            'rejectReason' => $r['reject_reason'] ?? null,
            'returDate' => $r['retur_date'],
            'status' => $r['status'],
            'createdAt' => $r['created_at'],
        ];
    }
}
