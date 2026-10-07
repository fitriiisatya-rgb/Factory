<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Dispatch\EvidenceUploader;
use Amor\Api\Idempotency;
use Amor\Api\Request;
use Amor\Api\Response;
use Amor\Api\StorePortal\MutasiRepository;
use Amor\Api\StorePortal\MutasiService;
use Amor\Api\StorePortal\ReturRepository;
use Amor\Api\StorePortal\ReturService;
use Amor\Api\StorePortal\StorePortalService;
use PDO;

/**
 * ADMIN-only side of the Permanent Bakery Portal (migration 0017) — token
 * management, Retur verification, Mutasi discrepancy review. Deliberately
 * a SEPARATE class from StorePortalController (that one is PUBLIC/
 * token-gated, no session at all — see its own docblock); every action
 * here requires a real ADMIN session, same role/session/CSRF/Idempotency-
 * Key model as every other admin endpoint in this app. Pesanan Khusus
 * has NO new admin surface here at all — a portal-submitted order is a
 * perfectly ordinary toko_khusus special_order row, so the EXISTING
 * SpecialOrderController::confirm()/cancel() already serve as its
 * "Admin Verification"/"APPROVED"/"REJECTED(reason)" actions (task's own
 * "after approval reuse EXISTING engine").
 */
final class StorePortalAdminController
{
    private const ADMIN_ROLES = ['ADMIN'];

    // ------------------------------------------------------------------
    // Store Portal token management
    // ------------------------------------------------------------------

    public static function tokenStatus(Request $request): void
    {
        Auth::requireRole(...self::ADMIN_ROLES);
        $storeId = (int) $request->routeParams['storeId'];
        $service = new StorePortalService(Database::pdo());
        Response::json($service->getStatus($storeId));
    }

    public static function tokenHistory(Request $request): void
    {
        Auth::requireRole(...self::ADMIN_ROLES);
        $storeId = (int) $request->routeParams['storeId'];
        $service = new StorePortalService(Database::pdo());
        Response::json($service->listHistory($storeId));
    }

    /** POST — covers BOTH "Generate" (no active token yet) and "Regenerate" (one already exists) — see StorePortalService::issueToken()'s own docblock for why this is one action. Returns the raw token/portal URL ONCE. */
    public static function tokenIssue(Request $request): void
    {
        $userId = Auth::requireRole(...self::ADMIN_ROLES);
        $storeId = (int) $request->routeParams['storeId'];
        Idempotency::handle($request, 'POST /api/admin/store-portal/{storeId}/issue', function (PDO $pdo) use ($userId, $storeId, $request) {
            $service = new StorePortalService($pdo);
            $dto = $service->issueToken($storeId, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'store_portal_token', 'recordKey' => (string) $dto['tokenId']];
        });
    }

    public static function tokenRevoke(Request $request): void
    {
        $userId = Auth::requireRole(...self::ADMIN_ROLES);
        $storeId = (int) $request->routeParams['storeId'];
        Idempotency::handle($request, 'POST /api/admin/store-portal/{storeId}/revoke', function (PDO $pdo) use ($userId, $storeId, $request) {
            $service = new StorePortalService($pdo);
            $dto = $service->revokeToken($storeId, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'store_portal_token', 'recordKey' => (string) $storeId];
        });
    }

    // ------------------------------------------------------------------
    // Retur review
    // ------------------------------------------------------------------

    public static function returList(Request $request): void
    {
        Auth::requireRole(...self::ADMIN_ROLES);
        $service = new ReturService(Database::pdo());
        Response::json($service->adminList($request->query('status')));
    }

    public static function returShow(Request $request): void
    {
        Auth::requireRole(...self::ADMIN_ROLES);
        $id = (int) $request->routeParams['id'];
        $service = new ReturService(Database::pdo());
        Response::json($service->getForAdmin($id));
    }

    public static function returVerify(Request $request): void
    {
        $userId = Auth::requireRole(...self::ADMIN_ROLES);
        $id = (int) $request->routeParams['id'];
        Idempotency::handle($request, 'POST /api/admin/retur/{id}/verify', function (PDO $pdo) use ($userId, $id, $request) {
            $service = new ReturService($pdo);
            $dto = $service->adminVerify($id, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'retur_request', 'recordKey' => (string) $id];
        });
    }

    public static function returReject(Request $request): void
    {
        $userId = Auth::requireRole(...self::ADMIN_ROLES);
        $id = (int) $request->routeParams['id'];
        $reason = (string) ($request->input('reason') ?? '');
        Idempotency::handle($request, 'POST /api/admin/retur/{id}/reject', function (PDO $pdo) use ($userId, $id, $reason, $request) {
            $service = new ReturService($pdo);
            $dto = $service->adminReject($id, $reason, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'retur_request', 'recordKey' => (string) $id];
        });
    }

    /** GET /api/admin/retur/evidence/{id} — streams one Retur evidence photo. Same discipline as ReceiptController::adminEvidence(): real filesystem path never exposed, no Admin-upload counterpart (evidence is STORE evidence only). */
    public static function returEvidence(Request $request): void
    {
        Auth::requireRole(...self::ADMIN_ROLES);
        $evidenceId = (int) $request->routeParams['id'];
        $repo = new ReturRepository();
        $evidence = $repo->findEvidenceById(Database::pdo(), $evidenceId);
        if ($evidence === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Bukti foto tidak ditemukan');
        }
        self::streamEvidence($evidence, 'retur-evidence');
    }

    // ------------------------------------------------------------------
    // Mutasi discrepancy review
    // ------------------------------------------------------------------

    public static function mutasiList(Request $request): void
    {
        Auth::requireRole(...self::ADMIN_ROLES);
        $service = new MutasiService(Database::pdo());
        Response::json($service->adminList($request->query('status')));
    }

    public static function mutasiShow(Request $request): void
    {
        Auth::requireRole(...self::ADMIN_ROLES);
        $id = (int) $request->routeParams['id'];
        $service = new MutasiService(Database::pdo());
        Response::json($service->getForAdmin($id));
    }

    /** POST — body: { resolution: 'completed'|'cancelled', notes: string }. The ONLY next step for a 'discrepancy' Mutasi — see MutasiService::adminReviewDiscrepancy()'s own docblock. */
    public static function mutasiReview(Request $request): void
    {
        $userId = Auth::requireRole(...self::ADMIN_ROLES);
        $id = (int) $request->routeParams['id'];
        $resolution = (string) ($request->input('resolution') ?? '');
        $notes = (string) ($request->input('notes') ?? '');
        Idempotency::handle($request, 'POST /api/admin/mutasi/{id}/review', function (PDO $pdo) use ($userId, $id, $resolution, $notes, $request) {
            $service = new MutasiService($pdo);
            $dto = $service->adminReviewDiscrepancy($id, $resolution, $notes, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'mutasi_request', 'recordKey' => (string) $id];
        });
    }

    /** GET /api/admin/mutasi/evidence/{id} — streams one Mutasi evidence photo (either stage: request or confirmation). */
    public static function mutasiEvidence(Request $request): void
    {
        Auth::requireRole(...self::ADMIN_ROLES);
        $evidenceId = (int) $request->routeParams['id'];
        $repo = new MutasiRepository();
        $evidence = $repo->findEvidenceById(Database::pdo(), $evidenceId);
        if ($evidence === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Bukti foto tidak ditemukan');
        }
        self::streamEvidence($evidence, 'mutasi-evidence');
    }

    private static function streamEvidence(array $evidence, string $context): void
    {
        $path = EvidenceUploader::absolutePath($evidence['file_path'], $context);
        if (!is_file($path)) {
            throw new ApiException(404, 'NOT_FOUND', 'Berkas bukti foto tidak ditemukan di server');
        }
        header('Content-Type: ' . $evidence['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }
}
