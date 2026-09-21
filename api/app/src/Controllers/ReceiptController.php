<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Dispatch\EvidenceUploader;
use Amor\Api\Dispatch\ReceiptRepository;
use Amor\Api\Dispatch\ReceiptService;
use Amor\Api\Idempotency;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

/**
 * Two very different audiences share this controller:
 *   - publicView()/confirm() — PUBLIC, no session, no CSRF token (there is
 *     none to check — see App::CSRF_EXEMPT). Access control is entirely the
 *     high-entropy token in the URL; every DB lookup goes through
 *     ReceiptRepository::findDoIdByToken, never a raw ID from the request.
 *   - adminList()/adminVerify() — ADMIN only, same session/CSRF/role model
 *     as every other admin endpoint in this app.
 */
final class ReceiptController
{
    public static function publicView(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $service = new ReceiptService(Database::pdo());
        Response::json($service->getPublicView($token));
    }

    public static function confirm(Request $request): void
    {
        $token = (string) $request->routeParams['token'];
        $shipmentId = (int) $request->routeParams['shipmentId'];
        $receiverName = $request->input('receiverName') !== null ? (string) $request->input('receiverName') : null;
        $note = $request->input('note') !== null ? (string) $request->input('note') : null;
        // Real-UAT ask (photo evidence): the store submits this as
        // multipart/form-data once evidence files are involved (see
        // Request::__construct()'s multipart branch) — "items" cannot be
        // a nested array in that encoding, so the client sends it as a
        // JSON string and this is the one place that decodes it. A plain
        // JSON request (no photos ever involved for this DO) still works
        // exactly as before: $itemsRaw is already an array there.
        $itemsRaw = $request->input('items', []);
        $items = is_string($itemsRaw) ? (array) (json_decode($itemsRaw, true) ?? []) : (array) $itemsRaw;

        // Validated and moved to disk BEFORE the DB transaction — file I/O
        // is not part of Database::transaction()'s rollback, so any
        // failure from here on must explicitly clean these up (catch
        // below). Never inside a replay: Idempotency::checkReplay() short-
        // circuits an EXACT retry before this closure runs at all, so a
        // true idempotent retry's freshly-uploaded files are the one
        // known, accepted edge case where a handful of orphan files can
        // remain — never a duplicated DB row (see EvidenceUploader's and
        // this method's own docblocks for the full reasoning).
        $evidenceFiles = EvidenceUploader::validateAndStore($request->fileField('evidence'));

        try {
            Idempotency::handle($request, 'POST /api/receive/{token}/shipments/{shipmentId}/confirm', function (PDO $pdo) use ($token, $shipmentId, $receiverName, $note, $items, $evidenceFiles, $request) {
                $service = new ReceiptService($pdo);
                $dto = $service->confirmReceipt($token, $shipmentId, $receiverName, $note, $items, $evidenceFiles, $request->header('Idempotency-Key'));
                return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'shipment_receipt', 'recordKey' => (string) $shipmentId];
            });
        } catch (\Throwable $e) {
            // The DB transaction (if it ran at all) already rolled back —
            // never leave the files it would have referenced behind.
            EvidenceUploader::deleteStoredFiles($evidenceFiles);
            throw $e;
        }
    }

    /**
     * GET /api/admin/receipts/evidence/{id} — streams ONE evidence photo's
     * bytes. ADMIN only. The real filesystem path is never exposed to the
     * client — this is the ONLY way an uploaded evidence file is ever
     * served (api/uploads/receipt-evidence/ itself is deny-all, same as
     * api/app/).
     *
     * Role correction (real cPanel UAT): photo evidence is STORE evidence —
     * only confirm() (the public token-gated route) ever writes an
     * evidence row. An earlier revision of this patch also had a
     * POST /api/admin/receipts/{id}/evidence Admin-upload endpoint for
     * attaching evidence to legacy pre-patch receipts; that endpoint was
     * REMOVED (not just hidden) because Admin fabricating/uploading
     * "store" evidence on the store's behalf breaks the audit trail this
     * whole feature exists to create. A legacy discrepancy receipt with
     * no store evidence now stays permanently un-verifiable — see
     * ReceiptService::adminVerify()'s own docblock.
     */
    public static function adminEvidence(Request $request): void
    {
        Auth::requireRole('ADMIN');
        $evidenceId = (int) $request->routeParams['id'];
        $repo = new ReceiptRepository();
        $evidence = $repo->findEvidenceById(Database::pdo(), $evidenceId);
        if ($evidence === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Bukti foto tidak ditemukan');
        }
        $path = EvidenceUploader::absolutePath($evidence['file_path']);
        if (!is_file($path)) {
            throw new ApiException(404, 'NOT_FOUND', 'Berkas bukti foto tidak ditemukan di server');
        }
        header('Content-Type: ' . $evidence['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    public static function adminList(Request $request): void
    {
        Auth::requireRole('ADMIN');
        $tanggal = $request->query('tanggal');
        $status = $request->query('status');
        $service = new ReceiptService(Database::pdo());
        Response::json($service->adminList($tanggal, $status));
    }

    public static function adminVerify(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN');
        $receiptId = (int) $request->routeParams['id'];

        Idempotency::handle($request, 'POST /api/admin/receipts/{id}/verify', function (PDO $pdo) use ($userId, $receiptId, $request) {
            $service = new ReceiptService($pdo);
            $dto = $service->adminVerify($receiptId, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'shipment_receipt', 'recordKey' => (string) $receiptId];
        });
    }
}
