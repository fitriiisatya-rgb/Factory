<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Database;
use Amor\Api\Dispatch\EvidenceUploader;
use Amor\Api\Dispatch\ReceiptService;
use Amor\Api\Idempotency;
use Amor\Api\Request;
use Amor\Api\Response;
use Amor\Api\SpecialOrder\SpecialOrderService;
use Amor\Api\StorePortal\MutasiService;
use Amor\Api\StorePortal\ReturService;
use Amor\Api\StorePortal\StorePortalService;
use PDO;

/**
 * The Permanent Bakery Portal (https://.../api/_store/?token=...) — ONE
 * permanent, no-login identity per store. Every action here is PUBLIC (no
 * session, no CSRF token — see App::CSRF_EXEMPT's "/api/store/" prefix
 * check, mirroring the existing "/api/receive/" exemption) and resolves
 * its OWN storeId from the {token} path segment via StorePortalService::
 * resolvePortalIdentity() — never from a route param, query string,
 * request body, or any other client-supplied field. A bakery cannot see
 * or act on another store's data by manipulating a shipmentId or any
 * other id in the URL/payload, because every downstream call is scoped by
 * the server-resolved storeId, never by anything the client sent.
 *
 * Menu 1 (Konfirmasi Penerimaan, Reject included) lives here now;
 * Pesanan Khusus/Retur/Mutasi/Riwayat are added to this same controller as
 * their own tasks land — ONE controller for the whole Portal, not five
 * near-identical ones, since every action shares the exact same token-
 * resolution preamble.
 */
final class StorePortalController
{
    /** GET /api/store/{token} — Portal bootstrap: resolves identity, nothing else. The SPA's first call, so it can show the bakery name prominently before rendering any tab. */
    public static function bootstrap(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $service = new StorePortalService(Database::pdo());
        $identity = $service->resolvePortalIdentity($token);
        Response::json(['storeId' => $identity['storeId'], 'storeName' => $identity['storeName']]);
    }

    /** GET /api/store/{token}/receipts — Konfirmasi Penerimaan list, this store's shipments only (Regular/Special/Replacement alike). */
    public static function receiptList(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
        $receiptService = new ReceiptService($pdo);
        Response::json($receiptService->listForStorePortal($identity['storeId']));
    }

    /** GET /api/store/{token}/receipts/{shipmentId} — one shipment's detail, to drive the Konfirmasi Penerimaan form. */
    public static function receiptDetail(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $shipmentId = (int) $request->routeParams['shipmentId'];
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
        $receiptService = new ReceiptService($pdo);
        Response::json($receiptService->getPortalShipmentDetail($identity['storeId'], $shipmentId));
    }

    /**
     * POST /api/store/{token}/receipts/{shipmentId}/confirm — Konfirmasi
     * Penerimaan submit. Reject lives here (task's own LOCKED rule: never
     * a standalone Portal menu) — a line with reject/shortage > 0 requires
     * at least one evidence photo, enforced server-side by the exact same
     * ReceiptService::validateReceiptLines() every other receipt path
     * uses, never a second copy of that rule.
     */
    public static function receiptConfirm(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $shipmentId = (int) $request->routeParams['shipmentId'];
        $receiverName = $request->input('receiverName') !== null ? (string) $request->input('receiverName') : null;
        $note = $request->input('note') !== null ? (string) $request->input('note') : null;
        $itemsRaw = $request->input('items', []);
        $items = is_string($itemsRaw) ? (array) (json_decode($itemsRaw, true) ?? []) : (array) $itemsRaw;

        // Validated/moved to disk BEFORE the transaction — same ordering
        // (and same orphan-file cleanup on any later failure, including an
        // invalid/revoked token) as ReceiptController::confirm().
        $evidenceFiles = EvidenceUploader::validateAndStore($request->fileField('evidence'));

        try {
            Idempotency::handle($request, 'POST /api/store/{token}/receipts/{shipmentId}/confirm', function (PDO $pdo) use ($token, $shipmentId, $receiverName, $note, $items, $evidenceFiles, $request) {
                $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
                $receiptService = new ReceiptService($pdo);
                $dto = $receiptService->confirmReceiptForStorePortal($identity['storeId'], $shipmentId, $receiverName, $note, $items, $evidenceFiles, $request->header('Idempotency-Key'));
                return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'shipment_receipt', 'recordKey' => (string) $shipmentId];
            });
        } catch (\Throwable $e) {
            EvidenceUploader::deleteStoredFiles($evidenceFiles);
            throw $e;
        }
    }

    /**
     * POST /api/store/{token}/special-orders — Pesanan Khusus/Custom
     * submission. Stays in the Special/Non-Regular Order source
     * (SpecialOrderService::createOrderForStorePortal() forces
     * sourceType=toko_khusus + the token-resolved storeId — never
     * whatever the client sent); reference photos are optional/multiple,
     * so an empty upload is not an error (unlike Receipt/Retur/Mutasi
     * evidence, which this controller's other actions require).
     */
    public static function specialOrderCreate(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        // Multipart (attachment files present): the order header+items
        // payload cannot be nested form fields, so the client sends it as
        // ONE JSON-encoded string field "order" — same idiom as
        // ReceiptController::confirm()'s own "items" field. No attachment
        // files at all: a plain JSON body, same as the existing Admin
        // create() endpoint's own $request->all().
        $orderRaw = $request->input('order');
        $input = is_string($orderRaw) ? (array) (json_decode($orderRaw, true) ?? []) : $request->all();
        $attachmentFiles = EvidenceUploader::validateAndStore($request->fileField('attachments'), 'special-order-attachment');

        try {
            Idempotency::handle($request, 'POST /api/store/{token}/special-orders', function (PDO $pdo) use ($token, $input, $attachmentFiles, $request) {
                $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
                $service = new SpecialOrderService($pdo);
                $dto = $service->createOrderForStorePortal($identity['storeId'], $input, $attachmentFiles, $request->header('Idempotency-Key'));
                return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'special_order', 'recordKey' => (string) $dto['orderId']];
            });
        } catch (\Throwable $e) {
            EvidenceUploader::deleteStoredFiles($attachmentFiles, 'special-order-attachment');
            throw $e;
        }
    }

    /** GET /api/store/{token}/special-orders — Riwayat tab's Pesanan Khusus list, this store's own submissions only. */
    public static function specialOrderList(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
        $service = new SpecialOrderService($pdo);
        Response::json($service->listOrdersForStorePortal($identity['storeId']));
    }

    /** GET /api/store/{token}/special-orders/{orderId} — one order's detail, ownership-checked. */
    public static function specialOrderDetail(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $orderId = (int) $request->routeParams['orderId'];
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
        $service = new SpecialOrderService($pdo);
        Response::json($service->getOrderForStorePortal($identity['storeId'], $orderId));
    }

    /** GET /api/store/{token}/special-orders/{orderId}/attachments/{attachmentId} — streams one of the bakery's OWN uploaded reference photos. Ownership-checked on both ids (see SpecialOrderService::getAttachmentForStorePortal()). */
    public static function specialOrderAttachment(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $orderId = (int) $request->routeParams['orderId'];
        $attachmentId = (int) $request->routeParams['attachmentId'];
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
        $service = new SpecialOrderService($pdo);
        $attachment = $service->getAttachmentForStorePortal($identity['storeId'], $orderId, $attachmentId);
        $path = EvidenceUploader::absolutePath($attachment['file_path'], 'special-order-attachment');
        if (!is_file($path)) {
            throw new ApiException(404, 'NOT_FOUND', 'Berkas lampiran tidak ditemukan di server');
        }
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    /** GET /api/store/{token}/retur — Riwayat tab's Retur list, this store's own submissions only. */
    public static function returList(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
        Response::json((new ReturService($pdo))->listForStorePortal($identity['storeId']));
    }

    /** GET /api/store/{token}/retur/{returId} — one Retur submission's detail, ownership-checked. */
    public static function returDetail(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $returId = (int) $request->routeParams['returId'];
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
        Response::json((new ReturService($pdo))->getForStorePortal($identity['storeId'], $returId));
    }

    /**
     * POST /api/store/{token}/retur — Retur submission. Photo evidence is
     * MANDATORY (>=1) here — task's own LOCKED rule — enforced by
     * ReturService::createForStorePortal() before anything is written.
     */
    public static function returCreate(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $inputRaw = $request->input('retur');
        $input = is_string($inputRaw) ? (array) (json_decode($inputRaw, true) ?? []) : $request->all();
        $evidenceFiles = EvidenceUploader::validateAndStore($request->fileField('evidence'), 'retur-evidence');

        try {
            Idempotency::handle($request, 'POST /api/store/{token}/retur', function (PDO $pdo) use ($token, $input, $evidenceFiles, $request) {
                $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
                $service = new ReturService($pdo);
                $dto = $service->createForStorePortal($identity['storeId'], $input, $evidenceFiles, $identity['tokenId'], $request->header('Idempotency-Key'));
                return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'retur_request', 'recordKey' => (string) $dto['returId']];
            });
        } catch (\Throwable $e) {
            EvidenceUploader::deleteStoredFiles($evidenceFiles, 'retur-evidence');
            throw $e;
        }
    }

    /** GET /api/store/{token}/mutasi/incoming — "Mutasi Produk" tab's action list: pending confirmations, this store as destination only. */
    public static function mutasiIncomingPending(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
        Response::json((new MutasiService($pdo))->listIncomingPendingForStorePortal($identity['storeId']));
    }

    /** GET /api/store/{token}/mutasi/outgoing — Riwayat tab's "Mutasi Keluar", this store as source, every status. */
    public static function mutasiOutgoing(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
        Response::json((new MutasiService($pdo))->listOutgoingForStorePortal($identity['storeId']));
    }

    /** GET /api/store/{token}/mutasi/incoming-history — Riwayat tab's "Mutasi Masuk", this store as destination, every status. */
    public static function mutasiIncomingHistory(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
        Response::json((new MutasiService($pdo))->listIncomingForStorePortal($identity['storeId']));
    }

    /** GET /api/store/{token}/mutasi/{mutasiId} — one Mutasi's detail, visible to EITHER party (source or destination), ownership-checked. */
    public static function mutasiDetail(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $mutasiId = (int) $request->routeParams['mutasiId'];
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
        Response::json((new MutasiService($pdo))->getForStorePortal($identity['storeId'], $mutasiId));
    }

    /**
     * POST /api/store/{token}/mutasi — source store submits a Mutasi
     * request. Evidence MANDATORY (>=1) here — task's own LOCKED rule.
     */
    public static function mutasiCreate(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $inputRaw = $request->input('mutasi');
        $input = is_string($inputRaw) ? (array) (json_decode($inputRaw, true) ?? []) : $request->all();
        $evidenceFiles = EvidenceUploader::validateAndStore($request->fileField('evidence'), 'mutasi-evidence');

        try {
            Idempotency::handle($request, 'POST /api/store/{token}/mutasi', function (PDO $pdo) use ($token, $input, $evidenceFiles, $request) {
                $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
                $service = new MutasiService($pdo);
                $dto = $service->createForStorePortal($identity['storeId'], $input, $evidenceFiles, $identity['tokenId'], $request->header('Idempotency-Key'));
                return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'mutasi_request', 'recordKey' => (string) $dto['mutasiId']];
            });
        } catch (\Throwable $e) {
            EvidenceUploader::deleteStoredFiles($evidenceFiles, 'mutasi-evidence');
            throw $e;
        }
    }

    /**
     * POST /api/store/{token}/mutasi/{mutasiId}/confirm — destination
     * store confirms Qty Diterima. Evidence is optional UNLESS the
     * reported qty differs from what was requested, in which case it
     * becomes mandatory — enforced by MutasiService itself.
     */
    public static function mutasiConfirm(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $mutasiId = (int) $request->routeParams['mutasiId'];
        $inputRaw = $request->input('confirmation');
        $input = is_string($inputRaw) ? (array) (json_decode($inputRaw, true) ?? []) : $request->all();
        $evidenceFiles = EvidenceUploader::validateAndStore($request->fileField('evidence'), 'mutasi-evidence');

        try {
            Idempotency::handle($request, 'POST /api/store/{token}/mutasi/{mutasiId}/confirm', function (PDO $pdo) use ($token, $mutasiId, $input, $evidenceFiles, $request) {
                $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
                $service = new MutasiService($pdo);
                $dto = $service->confirmForStorePortal($identity['storeId'], $mutasiId, $input, $evidenceFiles, $identity['tokenId'], $request->header('Idempotency-Key'));
                return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'mutasi_request', 'recordKey' => (string) $mutasiId];
            });
        } catch (\Throwable $e) {
            EvidenceUploader::deleteStoredFiles($evidenceFiles, 'mutasi-evidence');
            throw $e;
        }
    }
}
