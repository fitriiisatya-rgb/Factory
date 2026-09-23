<?php

declare(strict_types=1);

namespace Amor\Api\Dispatch;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\SpecialOrder\NormalizedSourceType;
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
    private ShipmentLineResolver $lineResolver;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new ReceiptRepository();
        $this->doRepo = new DoRepository();
        $this->userRepo = new UserRepository();
        $this->lineResolver = new ShipmentLineResolver();
    }

    /** Used by the print template — get-or-create, never regenerates an existing token. Regular DO-keyed only. */
    public function getReceiptToken(int $doId): string
    {
        return $this->repo->getOrCreateToken($this->pdo, $doId);
    }

    /**
     * Used by the special-order Surat Jalan print page / automatic email —
     * get-or-create the SHIPMENT-scoped token (migration 0012's
     * shipment_receipt_token — see this file's own docblock and
     * ReceiptRepository::getOrCreateShipmentToken()'s for why this is a
     * separate table from the Regular DO token rather than an overload of
     * it).
     */
    public function getOrCreateShipmentToken(int $shipmentId): string
    {
        return $this->repo->getOrCreateShipmentToken($this->pdo, $shipmentId);
    }

    /**
     * GET /api/receive/{token} — public. Resolves the incoming token
     * against EITHER of the two token tables (task's own Section F —
     * "shipment-capable receipt token abstraction... WITHOUT breaking
     * existing Regular receipt URLs"): a Regular DO's token
     * (delivery_receipt_token) is tried FIRST, byte-for-byte the same
     * lookup/behavior as before this rework, so every link already sent
     * by email keeps working; only when that lookup finds nothing does a
     * special-order shipment token (shipment_receipt_token) get tried.
     * The two token spaces never collide (each is its own random 64-hex
     * value; a value minted for one table has no way to also exist as a
     * row in the other).
     */
    public function getPublicView(string $token): array
    {
        $doId = $this->repo->findDoIdByToken($this->pdo, $token);
        if ($doId !== null) {
            return $this->getPublicViewForDo($doId);
        }
        $shipmentId = $this->repo->findShipmentIdByToken($this->pdo, $token);
        if ($shipmentId !== null) {
            return $this->getPublicViewForShipmentToken($shipmentId);
        }
        throw new ApiException(404, 'NOT_FOUND', 'Tautan tidak valid atau sudah tidak berlaku');
    }

    /** Regular PO — unchanged from before this rework. If no shipment has departed yet, returns an empty shipments[] list (Part G/QR-before-departure behavior). */
    private function getPublicViewForDo(int $doId): array
    {
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
     * Special/non-regular — a shipment-scoped token always resolves to
     * EXACTLY ONE shipment (never a DO's whole list — there is no DO-level
     * concept on this path), wrapped in the SAME `shipments: [...]` shape
     * the existing receipt.js already renders, so the public portal's
     * client-side code needs zero changes (task's own "reuse existing
     * architecture where safe"). Bakery never sees another source's
     * shipment: the token IS that one shipment's only key.
     */
    private function getPublicViewForShipmentToken(int $shipmentId): array
    {
        $sh = $this->doRepo->findShipmentById($this->pdo, $shipmentId);
        if ($sh === null || $sh['status'] !== 'active') {
            throw new ApiException(404, 'NOT_FOUND', 'Tautan tidak valid atau sudah tidak berlaku');
        }
        $lines = $this->lineResolver->linesForShipment($this->pdo, $sh);
        $receipt = $this->repo->findReceiptForShipment($this->pdo, $shipmentId);
        $isSpecial = ($sh['source_type'] ?? null) === 'special_order_do';
        $docNo = $isSpecial ? $sh['special_doc_no'] : $sh['doc_no'];

        return [
            'docNo' => $docNo,
            'storeName' => $sh['store_name'],
            'tanggal' => $sh['tanggal'],
            'shipments' => [[
                'shipmentId' => $shipmentId,
                'shipmentGroup' => $sh['shipment_group'],
                'driverName' => $this->shipmentDriverLabel($sh),
                'departedAt' => $sh['shipped_at'],
                'items' => array_map(static fn ($l) => [
                    'shipmentItemId' => $l['lineId'],
                    'productId' => $l['productId'],
                    'productName' => $l['itemName'],
                    'shippedQty' => $l['qtyShipped'],
                ], $lines),
                'receiptStatus' => $receipt['status'] ?? 'pending',
                'confirmedAt' => $receipt['confirmed_at'] ?? null,
                'receiverName' => $receipt['receiver_name'] ?? null,
            ]],
        ];
    }

    /** EXTERNAL_COURIER has no internal Driver account carrying the goods — show the courier identity instead (same rule as DispatchService::shipmentDetail()). */
    private function shipmentDriverLabel(array $sh): ?string
    {
        if (($sh['delivery_method'] ?? null) === 'EXTERNAL_COURIER') {
            $providerLabel = ucfirst((string) ($sh['courier_provider'] ?? 'kurir'));
            return 'Kurir: ' . ($sh['courier_name'] ?? $providerLabel) . ' (' . $providerLabel . ')';
        }
        if ($sh['shipped_by'] === null) {
            return null;
        }
        $driver = $this->userRepo->findById($this->pdo, (int) $sh['shipped_by']);
        return $driver !== null ? (($driver['full_name'] ?? '') !== '' ? $driver['full_name'] : $driver['username']) : null;
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
        if ($doId !== null) {
            return $this->confirmReceiptForDo($doId, $shipmentId, $receiverName, $note, $items, $evidenceFiles, $requestId);
        }
        $tokenShipmentId = $this->repo->findShipmentIdByToken($this->pdo, $token);
        if ($tokenShipmentId === null || $tokenShipmentId !== $shipmentId) {
            throw new ApiException(404, 'NOT_FOUND', 'Tautan tidak valid atau sudah tidak berlaku');
        }
        return $this->confirmReceiptForShipmentToken($shipmentId, $receiverName, $note, $items, $evidenceFiles, $requestId);
    }

    private function confirmReceiptForDo(int $doId, int $shipmentId, ?string $receiverName, ?string $note, array $items, array $evidenceFiles, ?string $requestId): array
    {
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
        $shippedQtyByLineId = [];
        $byId = [];
        foreach ($shipmentItems as $it) {
            $lineId = (int) $it['shipment_item_id'];
            $shippedQtyByLineId[$lineId] = (float) $it['qty'];
            $byId[$lineId] = $it;
        }
        $validated = $this->validateReceiptLines($items, $shippedQtyByLineId, $evidenceFiles);
        $status = $validated['anyDiscrepancy'] ? 'confirmed_discrepancy' : 'confirmed_ok';
        $receiptId = $this->repo->insertReceipt($this->pdo, $shipmentId, $status, $receiverName, $note);
        foreach ($validated['rows'] as $r) {
            $this->repo->insertReceiptItem($this->pdo, $receiptId, $r['lineId'], (int) $byId[$r['lineId']]['product_id'], $r['shippedQty'], $r['good'], $r['reject'], $r['shortage'], $r['reason']);
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

    /**
     * Special/non-regular — same math/evidence rules, same idempotent
     * "already confirmed -> return existing" guard, same audit write; the
     * only difference from confirmReceiptForDo() is which table each
     * receipt line/product name comes from (task's own Section G: "Do NOT
     * fabricate shipment_item" — this writes real
     * special_order_do_shipment_item-keyed rows via
     * insertReceiptItemForSpecialLine(), never a regular shipment_item
     * row).
     */
    private function confirmReceiptForShipmentToken(int $shipmentId, ?string $receiverName, ?string $note, array $items, array $evidenceFiles, ?string $requestId): array
    {
        $shipment = $this->doRepo->findShipmentById($this->pdo, $shipmentId);
        if ($shipment === null || $shipment['status'] !== 'active') {
            throw new ApiException(404, 'NOT_FOUND', 'Pengiriman tidak ditemukan untuk tautan ini');
        }

        $existing = $this->repo->lockReceiptForShipment($this->pdo, $shipmentId);
        if ($existing !== null) {
            return $this->buildReceiptDto($existing);
        }

        $lines = $this->lineResolver->linesForShipment($this->pdo, $shipment);
        $shippedQtyByLineId = [];
        $byId = [];
        foreach ($lines as $l) {
            $shippedQtyByLineId[$l['lineId']] = $l['qtyShipped'];
            $byId[$l['lineId']] = $l;
        }
        $validated = $this->validateReceiptLines($items, $shippedQtyByLineId, $evidenceFiles);
        $status = $validated['anyDiscrepancy'] ? 'confirmed_discrepancy' : 'confirmed_ok';
        $receiptId = $this->repo->insertReceipt($this->pdo, $shipmentId, $status, $receiverName, $note);
        foreach ($validated['rows'] as $r) {
            $line = $byId[$r['lineId']];
            $this->repo->insertReceiptItemForSpecialLine($this->pdo, $receiptId, $r['lineId'], $line['productId'], $line['itemName'], $r['shippedQty'], $r['good'], $r['reject'], $r['shortage'], $r['reason']);
        }
        foreach ($evidenceFiles as $ev) {
            $this->repo->insertEvidence($this->pdo, $receiptId, $ev['filePath'], $ev['mimeType'], $ev['fileSize'], $ev['originalName']);
        }

        Audit::write(
            $this->pdo, $requestId, null, 'receipt.confirmed', 'shipment_receipt', (string) $receiptId,
            'ok', null, null, ['shipmentId' => $shipmentId, 'status' => $status, 'receiverName' => $receiverName]
        );

        $created = $this->repo->findReceiptForShipment($this->pdo, $shipmentId);
        return $this->buildReceiptDto($created);
    }

    /**
     * Shared math/evidence validation for BOTH confirm paths — server-side
     * always (task's own explicit "Frontend-only validation is NOT
     * enough"). $shippedQtyByLineId keys every valid line id (a regular
     * shipment_item_id or a special special_order_do_shipment_item_id —
     * opaque to this method, it never cares which).
     * @param array<int,array{shipmentItemId:int,receivedGood:float,reject:float,shortage:float,reason?:string}> $items
     * @param array<int,float> $shippedQtyByLineId
     * @param array<int,array> $evidenceFiles
     * @return array{rows:array<int,array{lineId:int,shippedQty:float,good:float,reject:float,shortage:float,reason:?string}>,anyDiscrepancy:bool}
     */
    private function validateReceiptLines(array $items, array $shippedQtyByLineId, array $evidenceFiles): array
    {
        if (count($items) !== count($shippedQtyByLineId)) {
            throw new ApiException(400, 'INCOMPLETE_RECEIPT', 'Semua produk pada pengiriman ini harus dikonfirmasi');
        }

        $rows = [];
        $anyDiscrepancy = false;
        foreach ($items as $line) {
            $lineId = (int) ($line['shipmentItemId'] ?? 0);
            if (!array_key_exists($lineId, $shippedQtyByLineId)) {
                throw new ApiException(400, 'UNKNOWN_SHIPMENT_ITEM', "Item {$lineId} bukan bagian dari pengiriman ini");
            }
            $shippedQty = $shippedQtyByLineId[$lineId];
            $good = (float) ($line['receivedGood'] ?? 0);
            $reject = (float) ($line['reject'] ?? 0);
            $shortage = (float) ($line['shortage'] ?? 0);
            if ($good < 0 || $reject < 0 || $shortage < 0) {
                throw new ApiException(400, 'NEGATIVE_QTY', "Item {$lineId}: jumlah tidak boleh negatif");
            }
            if (abs(($good + $reject + $shortage) - $shippedQty) > 0.0001) {
                throw new ApiException(400, 'RECEIPT_MATH_INVALID',
                    "Item {$lineId}: Diterima Baik + Reject + Kurang harus sama dengan jumlah dikirim ({$shippedQty})"
                );
            }
            if ($reject > 0.0001 || $shortage > 0.0001) {
                $anyDiscrepancy = true;
            }
            $rows[] = [
                'lineId' => $lineId,
                'shippedQty' => $shippedQty,
                'good' => $good,
                'reject' => $reject,
                'shortage' => $shortage,
                'reason' => isset($line['reason']) && trim((string) $line['reason']) !== '' ? (string) $line['reason'] : null,
            ];
        }

        // Real-UAT rule: Reject/Kurang > 0 on ANY item requires at least
        // one photo before the submission is even allowed to succeed.
        if ($anyDiscrepancy && $evidenceFiles === []) {
            throw new ApiException(400, 'EVIDENCE_REQUIRED', 'Bukti foto wajib diunggah untuk barang reject/rusak atau kurang.');
        }

        return ['rows' => $rows, 'anyDiscrepancy' => $anyDiscrepancy];
    }

    /**
     * GET /api/admin/receipts — Konfirmasi Toko list (Part I). Every row
     * (Regular or special/non-regular) now carries an explicit normalized
     * `source` — task's own Section H: "special/non-regular shipments must
     * appear with a clear source badge... and show Delivery Method."
     */
    public function adminList(?string $tanggal, ?string $status): array
    {
        $rows = $this->repo->listForAdmin($this->pdo, $tanggal, $status);
        return array_map(static function ($r) {
            $isSpecial = ($r['source_type'] ?? null) === 'special_order_do';
            $sourceType = $isSpecial
                ? NormalizedSourceType::fromSpecialOrder((string) $r['special_source_type'], $r['special_non_store_source'] ?? null)
                : NormalizedSourceType::REGULAR_STORE_PO;
            $deliveryMethod = $r['delivery_method'] ?? 'DRIVER_INTERNAL';
            $driverName = ($deliveryMethod === 'EXTERNAL_COURIER')
                ? 'Kurir: ' . ($r['courier_name'] ?? ucfirst((string) ($r['courier_provider'] ?? 'kurir')))
                : (($r['driver_full_name'] ?? '') !== '' ? $r['driver_full_name'] : $r['driver_username']);
            return [
            'shipmentId' => (int) $r['shipment_id'],
            'doId' => $r['delivery_order_id'] !== null ? (int) $r['delivery_order_id'] : null,
            'docNo' => $r['doc_no'] ?? $r['special_doc_no'],
            'storeId' => (int) $r['store_id'],
            'storeName' => $r['store_name'],
            'tanggal' => $r['tanggal'],
            'shipmentGroup' => $r['shipment_group'],
            'shippedAt' => $r['shipped_at'],
            'driverName' => $driverName,
            'source' => ['type' => $sourceType, 'label' => NormalizedSourceType::label($sourceType), 'orderNo' => $r['special_order_no'] ?? null],
            'deliveryMethod' => $deliveryMethod,
            'emailStatus' => $r['email_status'] ?? null,
            'totalShipped' => (float) $r['total_shipped'],
            'totalGood' => (float) $r['total_good'],
            'totalReject' => (float) $r['total_reject'],
            'totalShortage' => (float) $r['total_shortage'],
            'receiptId' => $r['shipment_receipt_id'] !== null ? (int) $r['shipment_receipt_id'] : null,
            'status' => $r['receipt_status'] ?? 'belum_dikonfirmasi',
            'receiverName' => $r['receiver_name'],
            'confirmedAt' => $r['confirmed_at'],
            'verifiedAt' => $r['verified_at'],
            ];
        }, $rows);
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
                'shipmentItemId' => $it['shipment_item_id'] !== null ? (int) $it['shipment_item_id'] : (int) $it['special_order_do_shipment_item_id'],
                'productId' => $it['product_id'] !== null ? (int) $it['product_id'] : null,
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
