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
use Amor\Api\StorePortal\StorePortalService;
use Amor\Api\StorePortal\StoreReportService;
use PDO;

/**
 * Store reports (migration 0026): the store's "Retur, Reject & Mutasi"
 * form on the Portal Bakery, Head Store (ATASAN) approval, Admin
 * physical validation, dashboard and export. See StoreReportService.
 */
final class StoreReportController
{
    private const READ_ROLES = ['ADMIN', 'PPIC'];
    private const WRITE_ROLES = ['ADMIN'];

    // ------------------------------------------------------------------
    // Portal (token)
    // ------------------------------------------------------------------

    /** GET /api/store/{token}/reports?from=&to=&q= */
    public static function portalList(Request $request): void
    {
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity((string) $request->routeParams['token']);
        Response::json((new StoreReportService($pdo))->listForStore($identity['storeId'], $request->query('from'), $request->query('to'), $request->query('q')));
    }

    /** GET /api/store/{token}/reports/{id} */
    public static function portalShow(Request $request): void
    {
        $pdo = Database::pdo();
        $identity = (new StorePortalService($pdo))->resolvePortalIdentity((string) $request->routeParams['token']);
        Response::json((new StoreReportService($pdo))->get((int) $request->routeParams['id'], $identity['storeId']));
    }

    /** POST /api/store/{token}/reports — multipart: report (JSON) + evidence_<itemIndex>[] files */
    public static function portalCreate(Request $request): void
    {
        self::portalSave($request, null);
    }

    /** POST /api/store/{token}/reports/{id} — edit while still waiting for the Atasan */
    public static function portalUpdate(Request $request): void
    {
        self::portalSave($request, (int) $request->routeParams['id']);
    }

    private static function portalSave(Request $request, ?int $reportId): void
    {
        $token = (string) $request->routeParams['token'];
        $raw = $request->input('report');
        $input = is_string($raw) ? (array) (json_decode($raw, true) ?? []) : (array) ($raw ?? []);
        $items = is_array($input['items'] ?? null) ? array_values($input['items']) : [];

        $stored = [];
        try {
            foreach ($items as $i => $it) {
                $ctx = ($it['jenis'] ?? '') === 'mutasi' ? 'mutasi-evidence' : 'retur-evidence';
                $files = EvidenceUploader::validateAndStore($request->fileField('evidence_' . $i), $ctx);
                if ($files !== []) {
                    $stored[$i] = ['ctx' => $ctx, 'files' => $files];
                }
            }
            $evidenceByIndex = array_map(static fn ($s) => $s['files'], $stored);
            $endpoint = $reportId === null ? 'POST /api/store/{token}/reports' : 'POST /api/store/{token}/reports/{id}';
            Idempotency::handle($request, $endpoint, function (PDO $pdo) use ($token, $input, $evidenceByIndex, $reportId, $request) {
                $identity = (new StorePortalService($pdo))->resolvePortalIdentity($token);
                $svc = new StoreReportService($pdo);
                $dto = $reportId === null
                    ? $svc->createForPortal($identity['storeId'], $input, $evidenceByIndex, $identity['tokenId'], $request->header('Idempotency-Key'))
                    : $svc->updateForPortal($identity['storeId'], $reportId, $input, $evidenceByIndex, $identity['tokenId'], $request->header('Idempotency-Key'));
                return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'store_report', 'recordKey' => (string) $dto['reportId']];
            });
        } catch (\Throwable $e) {
            foreach ($stored as $s) {
                EvidenceUploader::deleteStoredFiles($s['files'], $s['ctx']);
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Atasan
    // ------------------------------------------------------------------

    /** GET /api/store-reports/pending — the logged-in Atasan's stores, waiting for approval */
    public static function pending(Request $request): void
    {
        $userId = Auth::requireRole('ATASAN');
        Response::json((new StoreReportService(Database::pdo()))->pendingForAtasan($userId));
    }

    /** POST /api/store-reports/approve {reportIds:int[]} */
    public static function approve(Request $request): void
    {
        $userId = Auth::requireRole('ATASAN');
        $ids = $request->input('reportIds', []);
        if (!is_array($ids)) {
            throw new ApiException(400, 'INVALID_IDS', 'reportIds harus berupa daftar');
        }
        Idempotency::handle($request, 'POST /api/store-reports/approve', function (PDO $pdo) use ($userId, $ids, $request) {
            $res = (new StoreReportService($pdo))->approve($userId, $ids, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $res], 'recordType' => 'store_report', 'recordKey' => null];
        });
    }

    // ------------------------------------------------------------------
    // Admin validation
    // ------------------------------------------------------------------

    /** GET /api/store-reports/validasi?factoryId=&storeId=&jenis=&from=&to=&onlyWaiting=1 */
    public static function validasiList(Request $request): void
    {
        Auth::requireRole(...self::READ_ROLES);
        Response::json((new StoreReportService(Database::pdo()))->validasiList(self::filter($request) + [
            'onlyWaiting' => $request->query('onlyWaiting', '1') === '1',
        ]));
    }

    /** POST /api/store-reports/validasi {type:'retur'|'mutasi', id, status:'sesuai'|'tidak_sesuai', note, tindakLanjut?} */
    public static function validasi(Request $request): void
    {
        $userId = Auth::requireRole(...self::WRITE_ROLES);
        $type = (string) $request->input('type', '');
        $id = (int) $request->input('id', 0);
        $status = (string) $request->input('status', '');
        $note = (string) $request->input('note', '');
        $tl = $request->input('tindakLanjut');
        Idempotency::handle($request, 'POST /api/store-reports/validasi', function (PDO $pdo) use ($userId, $type, $id, $status, $note, $tl, $request) {
            $res = (new StoreReportService($pdo))->validasi($type, $id, $status, $note, is_string($tl) ? $tl : null, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $res], 'recordType' => $type === 'mutasi' ? 'mutasi_request' : 'retur_request', 'recordKey' => (string) $id];
        });
    }

    // ------------------------------------------------------------------
    // Shared: dashboard, export, detail, evidence (Admin/PPIC all stores; Atasan own stores)
    // ------------------------------------------------------------------

    /** GET /api/store-reports/dashboard?factoryId=&storeId=&from=&to= */
    public static function dashboard(Request $request): void
    {
        $pdo = Database::pdo();
        $scope = self::scope($pdo);
        Response::json((new StoreReportService($pdo))->dashboard(self::filter($request), $scope));
    }

    /** GET /api/store-reports/export?factoryId=&storeId=&jenis=&from=&to= — CSV with the old sheet's columns */
    public static function export(Request $request): void
    {
        $pdo = Database::pdo();
        $scope = self::scope($pdo);
        $rows = (new StoreReportService($pdo))->exportRows(self::filter($request), $scope);
        $name = 'retur-reject-mutasi-' . ($request->query('from') ?: 'awal') . '-sd-' . ($request->query('to') ?: 'akhir') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $name) . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Waktu Input', 'ID Transaksi', 'Tanggal', 'Nama Toko', 'Kategori', 'Nama Produk', 'Pabrik', 'Qty', 'Harga Satuan', 'Total', 'Jenis',
            'Alasan', 'Tanggal Expired', 'Status Approval', 'Disetujui Oleh', 'Waktu Approval', 'Toko Tujuan', 'Status Validasi', 'Divalidasi Oleh',
            'Waktu Validasi', 'Catatan Ketidaksesuaian', 'No Dokumen']);
        $jenis = ['retur' => 'Retur', 'reject' => 'Reject', 'mutasi' => 'Mutasi'];
        $val = ['menunggu' => 'Menunggu Validasi', 'sesuai' => 'Sesuai', 'tidak_sesuai' => 'Tidak Sesuai'];
        foreach ($rows as $r) {
            $approval = $r['reportStatus'] === 'menunggu_atasan' ? 'Menunggu' : 'Disetujui';
            $by = $r['approvalMode'] === 'otomatis' ? '(otomatis, toko tanpa Atasan)' : ($r['approvedByName'] ?? '');
            fputcsv($out, [
                self::wib($r['createdAt']), $r['reportNo'] ?? $r['docNo'], $r['tanggal'], $r['storeName'], $r['kategori'], $r['productName'], $r['pabrik'],
                $r['qty'], $r['harga'], $r['total'], $jenis[$r['jenis']] ?? $r['jenis'], $r['alasan'], $r['expDate'], $approval, $by, self::wib($r['approvedAt']),
                $r['destinationStoreName'], $val[$r['validasiStatus']] ?? $r['validasiStatus'], $r['validasiByName'], self::wib($r['validasiAt']),
                $r['validasiStatus'] === 'tidak_sesuai' ? $r['validasiNote'] : '', $r['docNo'],
            ]);
        }
        fclose($out);
    }

    /** GET /api/store-reports/{id} */
    public static function show(Request $request): void
    {
        $pdo = Database::pdo();
        $scope = self::scope($pdo);
        $dto = (new StoreReportService($pdo))->get((int) $request->routeParams['id']);
        if ($scope !== null && !in_array($dto['storeId'], $scope, true)) {
            throw new ApiException(404, 'NOT_FOUND', 'Transaksi tidak ditemukan');
        }
        Response::json($dto);
    }

    /** GET /api/store-reports/items/{type}/{id}/evidence — evidence ids of one item */
    public static function itemEvidence(Request $request): void
    {
        $pdo = Database::pdo();
        $scope = self::scope($pdo);
        [$type, $id] = [(string) $request->routeParams['type'], (int) $request->routeParams['id']];
        if ($type === 'mutasi') {
            $st = $pdo->prepare('SELECT e.mutasi_request_evidence_id AS id, e.stage, m.source_store_id AS store_id FROM mutasi_request_evidence e INNER JOIN mutasi_request m ON m.mutasi_request_id = e.mutasi_request_id WHERE e.mutasi_request_id = ? ORDER BY e.mutasi_request_evidence_id');
        } else {
            $st = $pdo->prepare("SELECT e.retur_request_evidence_id AS id, 'request' AS stage, r.store_id FROM retur_request_evidence e INNER JOIN retur_request r ON r.retur_request_id = e.retur_request_id WHERE e.retur_request_id = ? ORDER BY e.retur_request_evidence_id");
        }
        $st->execute([$id]);
        $rows = array_values(array_filter($st->fetchAll(), static fn ($r) => $scope === null || in_array((int) $r['store_id'], $scope, true)));
        Response::json(array_map(static fn ($r) => ['id' => (int) $r['id'], 'stage' => $r['stage'], 'url' => '/api/store-reports/evidence/' . ($type === 'mutasi' ? 'mutasi' : 'retur') . '/' . (int) $r['id']], $rows));
    }

    /** GET /api/store-reports/evidence/{type}/{id} — the photo itself */
    public static function evidence(Request $request): void
    {
        $pdo = Database::pdo();
        $scope = self::scope($pdo);
        [$type, $id] = [(string) $request->routeParams['type'], (int) $request->routeParams['id']];
        if ($type === 'mutasi') {
            $st = $pdo->prepare('SELECT e.*, m.source_store_id AS owner_store_id FROM mutasi_request_evidence e INNER JOIN mutasi_request m ON m.mutasi_request_id = e.mutasi_request_id WHERE e.mutasi_request_evidence_id = ?');
            $ctx = 'mutasi-evidence';
        } else {
            $st = $pdo->prepare('SELECT e.*, r.store_id AS owner_store_id FROM retur_request_evidence e INNER JOIN retur_request r ON r.retur_request_id = e.retur_request_id WHERE e.retur_request_evidence_id = ?');
            $ctx = 'retur-evidence';
        }
        $st->execute([$id]);
        $ev = $st->fetch();
        if (!$ev || ($scope !== null && !in_array((int) $ev['owner_store_id'], $scope, true))) {
            throw new ApiException(404, 'NOT_FOUND', 'Bukti foto tidak ditemukan');
        }
        StorePortalAdminController::streamEvidence($ev, $ctx);
    }

    // ------------------------------------------------------------------
    // Reject reasons (master)
    // ------------------------------------------------------------------

    /** GET /api/reject-reasons?all=1 */
    public static function reasons(Request $request): void
    {
        Auth::requireAuth();
        Response::json((new StoreReportService(Database::pdo()))->reasons($request->query('all') !== '1'));
    }

    /** POST /api/reject-reasons {name, sortOrder, active} — PUT /api/reject-reasons/{id} */
    public static function reasonSave(Request $request): void
    {
        $userId = Auth::requireRole(...self::WRITE_ROLES);
        $id = isset($request->routeParams['id']) ? (int) $request->routeParams['id'] : null;
        $name = (string) $request->input('name', '');
        $sort = (int) $request->input('sortOrder', 0);
        $active = (bool) $request->input('active', true);
        Idempotency::handle($request, $id === null ? 'POST /api/reject-reasons' : 'PUT /api/reject-reasons/{id}', function (PDO $pdo) use ($id, $name, $sort, $active, $userId, $request) {
            $dto = (new StoreReportService($pdo))->saveReason($id, $name, $sort, $active, $userId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'reject_reason', 'recordKey' => (string) $dto['id']];
        });
    }

    // ------------------------------------------------------------------

    /** @return int[]|null null = every store (Admin/PPIC); Atasan = own stores */
    private static function scope(PDO $pdo): ?array
    {
        $userId = Auth::requireRole('ADMIN', 'PPIC', 'ATASAN');
        if (array_intersect(self::READ_ROLES, Auth::currentRoles()) !== []) {
            return null;
        }
        return (new StoreReportService($pdo))->storeIdsForAtasan($userId);
    }

    private static function filter(Request $request): array
    {
        $jenis = $request->query('jenis');
        return [
            'factoryId' => (int) ($request->query('factoryId') ?? 0) ?: null,
            'storeId' => (int) ($request->query('storeId') ?? 0) ?: null,
            'jenis' => in_array($jenis, ['retur', 'reject', 'mutasi'], true) ? $jenis : null,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];
    }

    private static function wib(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i');
    }
}
