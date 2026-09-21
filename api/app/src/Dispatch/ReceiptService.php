<?php

declare(strict_types=1);

namespace Amor\Api\Dispatch;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\Users\UserRepository;
use PDO;

/**
 * DO Receipt QR + Store Receipt Confirmation + Admin Verification (Phase
 * 5.5, Parts G/H/I). The public-facing half of this class (getPublicView /
 * confirmReceipt) is reachable with NO session/auth at all — the
 * high-entropy token IS the access control (ReceiptRepository::
 * findDoIdByToken is the ONLY way any of this resolves a DO; a raw
 * delivery_order_id is never accepted from an unauthenticated caller).
 *
 * Read-only guarantee: getReceiptToken()/getPublicView() never touch
 * delivery_order.version, never create a shipment, never post to
 * stock_ledger — the only write getReceiptToken() can do is lazily insert
 * the token row itself on first use (task's own allowance: printing a
 * Draft/Preprint DO's QR must not create a shipment/change the DO/change
 * its version — issuing the token satisfies all three).
 */
final class ReceiptService
{
    private ReceiptRepository $repo;
    private DoRepository $doRepo;
    private UserRepository $userRepo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new ReceiptRepository();
        $this->doRepo = new DoRepository();
        $this->userRepo = new UserRepository();
    }

    /** Used by the print template — get-or-create, never regenerates an existing token. */
    public function getReceiptToken(int $doId): string
    {
        return $this->repo->getOrCreateToken($this->pdo, $doId);
    }

    /**
     * GET /api/receive/{token} — public. If no shipment has departed yet,
     * returns an empty shipments[] list so the UI can show "Pengiriman
     * belum dikonfirmasi berangkat." (Part G/QR-before-departure behavior).
     */
    public function getPublicView(string $token): array
    {
        $doId = $this->repo->findDoIdByToken($this->pdo, $token);
        if ($doId === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Tautan tidak valid atau sudah tidak berlaku');
        }
        $do = $this->doRepo->findDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Tautan tidak valid atau sudah tidak berlaku');
        }

        $shipments = $this->doRepo->findShipmentsForDo($this->pdo, $doId);
        $out = [];
        foreach ($shipments as $sh) {
            if ($sh['status'] !== 'active') {
                continue; // a voided shipment was never actually received — nothing to confirm
            }
            $shipmentId = (int) $sh['shipment_id'];
            $items = $this->doRepo->findShipmentItems($this->pdo, $shipmentId);
            $receipt = $this->repo->findReceiptForShipment($this->pdo, $shipmentId);
            // Real-UAT Surat Jalan print ask: the store-facing QR portal
            // must be able to tell shipments under the same DO apart at a
            // glance (task's own "SHP-2 — MAIN — Driver A" wireframe) —
            // display-only enrichment, never changes what confirmReceipt()
            // accepts/validates.
            $driver = $sh['shipped_by'] !== null ? $this->userRepo->findById($this->pdo, (int) $sh['shipped_by']) : null;
            $out[] = [
                'shipmentId' => $shipmentId,
                'shipmentGroup' => $sh['shipment_group'],
                'driverName' => $driver !== null
                    ? (($driver['full_name'] ?? '') !== '' ? $driver['full_name'] : $driver['username'])
                    : null,
                'departedAt' => $sh['shipped_at'],
                'items' => array_map(static fn ($it) => [
                    'shipmentItemId' => (int) $it['shipment_item_id'],
                    'productId' => (int) $it['product_id'],
                    'productName' => $it['product_name'],
                    'shippedQty' => (float) $it['qty'],
                ], $items),
                'receiptStatus' => $receipt['status'] ?? 'pending',
                'confirmedAt' => $receipt['confirmed_at'] ?? null,
                'receiverName' => $receipt['receiver_name'] ?? null,
            ];
        }

        return [
            'docNo' => $do['doc_no'],
            'storeName' => $do['store_name'],
            'tanggal' => $do['tanggal'],
            'shipments' => $out,
        ];
    }

    /**
     * POST /api/receive/{token}/shipments/{shipmentId}/confirm — public.
     * If this shipment already has a confirmation, returns the EXISTING one
     * unchanged (task's own "must also be protected from accidental
     * double-submit ... show existing confirmation. Do not double-count."),
     * on top of the generic Idempotency-Key replay the controller already
     * provides for an exact retry.
     *
     * @param array<int,array{shipmentItemId:int,receivedGood:float,reject:float,shortage:float,reason?:string}> $items
     */
    /**
     * $evidenceFiles is the ALREADY validated-and-moved-to-disk list from
     * EvidenceUploader::validateAndStore() (called by the controller
     * before this method, since that upload is a filesystem side effect
     * that must never happen inside a DB transaction retry). Real-UAT
     * rule: at least one photo is REQUIRED once any item has
     * reject/shortage > 0 — server-side, never trusting the client to
     * have enforced it. On any failure in this method AFTER files were
     * already moved (e.g. RECEIPT_MATH_INVALID racing a concurrent
     * request), the controller is responsible for calling
     * EvidenceUploader::deleteStoredFiles($evidenceFiles) so a rejected
     * submission never leaves orphan files on disk — see
     * ReceiptController::confirm()'s own try/catch.
     * @param array<int,array{filePath:string,mimeType:string,fileSize:int,originalName:?string}> $evidenceFiles
     */
    public function confirmReceipt(string $token, int $shipmentId, ?string $receiverName, ?string $note, array $items, array $evidenceFiles, ?string $requestId): array
    {
        $doId = $this->repo->findDoIdByToken($this->pdo, $token);
        if ($doId === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Tautan tidak valid atau sudah tidak berlaku');
        }

        $shipments = $this->doRepo->findShipmentsForDo($this->pdo, $doId);
        $shipment = null;
        foreach ($shipments as $sh) {
            if ((int) $sh['shipment_id'] === $shipmentId) {
                $shipment = $sh;
                break;
            }
        }
        if ($shipment === null || $shipment['status'] !== 'active') {
            throw new ApiException(404, 'NOT_FOUND', 'Pengiriman tidak ditemukan untuk tautan ini');
        }

        $existing = $this->repo->lockReceiptForShipment($this->pdo, $shipmentId);
        if ($existing !== null) {
            return $this->buildReceiptDto($existing);
        }

        $shipmentItems = $this->doRepo->findShipmentItems($this->pdo, $shipmentId);
        $byId = [];
        foreach ($shipmentItems as $it) {
            $byId[(int) $it['shipment_item_id']] = $it;
        }
        if (count($items) !== count($byId)) {
            throw new ApiException(400, 'INCOMPLETE_RECEIPT', 'Semua produk pada pengiriman ini harus dikonfirmasi');
        }

        $rows = [];
        $anyDiscrepancy = false;
        foreach ($items as $line) {
            $shipmentItemId = (int) ($line['shipmentItemId'] ?? 0);
            if (!isset($byId[$shipmentItemId])) {
                throw new ApiException(400, 'UNKNOWN_SHIPMENT_ITEM', "Item {$shipmentItemId} bukan bagian dari pengiriman ini");
            }
            $shippedQty = (float) $byId[$shipmentItemId]['qty'];
            $good = (float) ($line['receivedGood'] ?? 0);
            $reject = (float) ($line['reject'] ?? 0);
            $shortage = (float) ($line['shortage'] ?? 0);
            if ($good < 0 || $reject < 0 || $shortage < 0) {
                throw new ApiException(400, 'NEGATIVE_QTY', "Item {$shipmentItemId}: jumlah tidak boleh negatif");
            }
            if (abs(($good + $reject + $shortage) - $shippedQty) > 0.0001) {
                throw new ApiException(400, 'RECEIPT_MATH_INVALID',
                    "Item {$shipmentItemId}: Diterima Baik + Reject + Kurang harus sama dengan jumlah dikirim ({$shippedQty})"
                );
            }
            if ($reject > 0.0001 || $shortage > 0.0001) {
                $anyDiscrepancy = true;
            }
            $rows[] = [
                'shipmentItemId' => $shipmentItemId,
                'productId' => (int) $byId[$shipmentItemId]['product_id'],
                'shippedQty' => $shippedQty,
                'good' => $good,
                'reject' => $reject,
                'shortage' => $shortage,
                'reason' => isset($line['reason']) && trim((string) $line['reason']) !== '' ? (string) $line['reason'] : null,
            ];
        }

        // Real-UAT rule: Reject/Kurang > 0 on ANY item requires at least
        // one photo before the submission is even allowed to succeed —
        // server-side, since a client-side-only check is not enough
        // (task's own explicit "Frontend-only validation is NOT enough").
        if ($anyDiscrepancy && $evidenceFiles === []) {
            throw new ApiException(400, 'EVIDENCE_REQUIRED', 'Bukti foto wajib diunggah untuk barang reject/rusak atau kurang.');
        }

        $status = $anyDiscrepancy ? 'confirmed_discrepancy' : 'confirmed_ok';
        $receiptId = $this->repo->insertReceipt($this->pdo, $shipmentId, $status, $receiverName, $note);
        foreach ($rows as $r) {
            $this->repo->insertReceiptItem($this->pdo, $receiptId, $r['shipmentItemId'], $r['productId'], $r['shippedQty'], $r['good'], $r['reject'], $r['shortage'], $r['reason']);
        }
        foreach ($evidenceFiles as $ev) {
            $this->repo->insertEvidence($this->pdo, $receiptId, $ev['filePath'], $ev['mimeType'], $ev['fileSize'], $ev['originalName']);
        }

        // user_id = null: this is a public, unauthenticated confirmation —
        // audit_log.user_id is nullable for exactly this case; the store
        // name/receiver name are captured in the payload summary instead.
        Audit::write(
            $this->pdo, $requestId, null, 'receipt.confirmed', 'shipment_receipt', (string) $receiptId,
            'ok', null, null, ['shipmentId' => $shipmentId, 'status' => $status, 'receiverName' => $receiverName]
        );

        $created = $this->repo->findReceiptForShipment($this->pdo, $shipmentId);
        return $this->buildReceiptDto($created);
    }

    /** GET /api/admin/receipts — Konfirmasi Toko list (Part I). */
    public function adminList(?string $tanggal, ?string $status): array
    {
        $rows = $this->repo->listForAdmin($this->pdo, $tanggal, $status);
        return array_map(static fn ($r) => [
            'shipmentId' => (int) $r['shipment_id'],
            'doId' => $r['delivery_order_id'] !== null ? (int) $r['delivery_order_id'] : null,
            'docNo' => $r['doc_no'],
            'storeId' => (int) $r['store_id'],
            'storeName' => $r['store_name'],
            'tanggal' => $r['tanggal'],
            'shipmentGroup' => $r['shipment_group'],
            'shippedAt' => $r['shipped_at'],
            'totalShipped' => (float) $r['total_shipped'],
            'totalGood' => (float) $r['total_good'],
            'totalReject' => (float) $r['total_reject'],
            'totalShortage' => (float) $r['total_shortage'],
            'receiptId' => $r['shipment_receipt_id'] !== null ? (int) $r['shipment_receipt_id'] : null,
            'status' => $r['receipt_status'] ?? 'belum_dikonfirmasi',
            'receiverName' => $r['receiver_name'],
            'confirmedAt' => $r['confirmed_at'],
            'verifiedAt' => $r['verified_at'],
        ], $rows);
    }

    /**
     * POST /api/admin/receipts/{id}/verify — Part I, admin reviews a
     * discrepancy and marks it verified. Real-UAT rule: if this receipt
     * has ANY reject/shortage qty, it can only be verified once at least
     * one STORE-uploaded photo evidence row exists — protects OLD data
     * too. Role correction (real cPanel UAT): evidence is STORE evidence
     * only — there is deliberately no Admin-side way to attach it, so a
     * legacy pre-patch discrepancy receipt with no store evidence stays
     * PERMANENTLY blocked from verification (never silently grandfathered
     * in, and never "fixed" by Admin fabricating evidence on the store's
     * behalf — see the removed adminAddEvidence()/adminUploadEvidence()
     * in this file's and ReceiptController's git history for why).
     */
    public function adminVerify(int $receiptId, int $adminUserId, ?string $requestId): array
    {
        $receipt = $this->repo->lockReceipt($this->pdo, $receiptId);
        if ($receipt === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Konfirmasi penerimaan tidak ditemukan');
        }
        if (!in_array($receipt['status'], ['confirmed_ok', 'confirmed_discrepancy'], true)) {
            throw new ApiException(409, 'INVALID_RECEIPT_STATUS', 'Konfirmasi ini sudah diverifikasi sebelumnya');
        }
        if ($this->receiptHasDiscrepancy((int) $receipt['shipment_receipt_id'])
            && $this->repo->countEvidenceForReceipt($this->pdo, (int) $receipt['shipment_receipt_id']) === 0) {
            throw new ApiException(409, 'EVIDENCE_REQUIRED_FOR_VERIFY', 'Selisih belum dapat diverifikasi karena bukti foto dari toko belum tersedia.');
        }
        $this->repo->markVerified($this->pdo, $receiptId, $adminUserId);
        Audit::write($this->pdo, $requestId, $adminUserId, 'receipt.verified', 'shipment_receipt', (string) $receiptId, 'ok', null, null, null);

        $updated = $this->repo->findReceiptForShipment($this->pdo, (int) $receipt['shipment_id']);
        return $this->buildReceiptDto($updated);
    }

    private function receiptHasDiscrepancy(int $receiptId): bool
    {
        foreach ($this->repo->findReceiptItems($this->pdo, $receiptId) as $it) {
            if ((float) $it['reject_qty'] > 0.0001 || (float) $it['shortage_qty'] > 0.0001) {
                return true;
            }
        }
        return false;
    }

    private function buildReceiptDto(array $receipt): array
    {
        $items = $this->repo->findReceiptItems($this->pdo, (int) $receipt['shipment_receipt_id']);
        $evidence = $this->repo->findEvidenceForReceipt($this->pdo, (int) $receipt['shipment_receipt_id']);
        return [
            'receiptId' => (int) $receipt['shipment_receipt_id'],
            'shipmentId' => (int) $receipt['shipment_id'],
            'status' => $receipt['status'],
            'receiverName' => $receipt['receiver_name'],
            'note' => $receipt['note'],
            'confirmedAt' => $receipt['confirmed_at'],
            'verifiedAt' => $receipt['verified_at'],
            'verifiedByName' => $this->repo->findVerifierName($this->pdo, $receipt['verified_by'] !== null ? (int) $receipt['verified_by'] : null),
            'items' => array_map(static fn ($it) => [
                'shipmentItemId' => (int) $it['shipment_item_id'],
                'productId' => (int) $it['product_id'],
                'productName' => $it['product_name'],
                'shippedQty' => (float) $it['shipped_qty'],
                'receivedGoodQty' => (float) $it['received_good_qty'],
                'rejectQty' => (float) $it['reject_qty'],
                'shortageQty' => (float) $it['shortage_qty'],
                'reason' => $it['reason'],
            ], $items),
            // Never the raw filesystem path — just enough for the admin
            // detail page to build a thumbnail <img src="/api/admin/
            // receipts/evidence/{evidenceId}"> and to know evidence
            // exists at all (adminVerify()'s own gate reads the count
            // straight from the repository, not from this DTO).
            'evidence' => array_map(static fn ($ev) => [
                'evidenceId' => (int) $ev['shipment_receipt_evidence_id'],
                'mimeType' => $ev['mime_type'],
                'fileSize' => (int) $ev['file_size'],
                'originalName' => $ev['original_name'],
                'uploadedAt' => $ev['uploaded_at'],
            ], $evidence),
        ];
    }
}
