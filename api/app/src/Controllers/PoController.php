<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Idempotency;
use Amor\Api\Import\PoImporter;
use Amor\Api\Import\PoRepository;
use Amor\Api\Request;
use Amor\Api\Response;
use PDO;

/**
 * JSON API for the authoritative MySQL PO module. This is a PROGRAMMATIC
 * interface (file content travels as base64 in the JSON body, matching
 * Request's JSON-only contract — see Request::__construct) — the human-
 * facing wizard (api/_import-po/) uses a normal multipart HTML form and
 * calls Amor\Api\Import\PoImporter directly, the same way
 * api/_import-master/ calls Phase1Importer directly rather than routing
 * itself through this controller.
 */
final class PoController
{
    public static function index(Request $request): void
    {
        Auth::requireAuth();
        $repo = new PoRepository();
        $factoryId = $request->query('factoryId') !== null ? (int) $request->query('factoryId') : null;
        Response::json($repo->findAllBatches(Database::pdo(), $request->query('date'), $factoryId));
    }

    public static function show(Request $request): void
    {
        Auth::requireAuth();
        $id = (int) $request->routeParams['batchId'];
        $repo = new PoRepository();
        $pdo = Database::pdo();
        $batch = $repo->findBatchById($pdo, $id);
        if ($batch === null) {
            throw new ApiException(404, 'NOT_FOUND', 'PO batch not found');
        }
        $lines = $repo->findCurrent($pdo, $batch['tanggal'], (int) $batch['factory_id'], null, null, 'all');
        Response::json(['batch' => $batch, 'lines' => $lines]);
    }

    public static function current(Request $request): void
    {
        Auth::requireAuth();
        $poType = $request->query('poType', 'all');
        if (!in_array($poType, ['all', 'initial', 'revision'], true)) {
            throw new ApiException(400, 'INVALID_PO_TYPE', "poType must be 'all', 'initial', or 'revision'");
        }
        $repo = new PoRepository();
        $rows = $repo->findCurrent(
            Database::pdo(),
            $request->query('date'),
            $request->query('factoryId') !== null ? (int) $request->query('factoryId') : null,
            $request->query('divisionId') !== null ? (int) $request->query('divisionId') : null,
            $request->query('storeId') !== null ? (int) $request->query('storeId') : null,
            $poType
        );
        Response::json($rows);
    }

    public static function history(Request $request): void
    {
        Auth::requireAuth();
        $repo = new PoRepository();
        $factoryId = $request->query('factoryId') !== null ? (int) $request->query('factoryId') : null;
        $limit = $request->query('limit') !== null ? (int) $request->query('limit') : 50;
        Response::json($repo->findHistory(Database::pdo(), $request->query('date'), $factoryId, $limit));
    }

    public static function preview(Request $request): void
    {
        Auth::requireRole('ADMIN', 'PPIC');
        [$tanggal, $uploadType, $tmpPath, $fileName, $hash] = self::decodeUploadBody($request);

        try {
            $importer = new PoImporter(Database::pdo());
            $rows = $importer->readRows($tmpPath, $fileName);
            $plan = $importer->preview($rows, $tanggal, $uploadType, $hash, $fileName);
            Response::json($plan);
        } catch (\RuntimeException $e) {
            throw new ApiException(400, 'PARSE_FAILED', $e->getMessage());
        } finally {
            @unlink($tmpPath);
        }
    }

    public static function import(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN', 'PPIC');
        [$tanggal, $uploadType, $tmpPath, $fileName, $hash] = self::decodeUploadBody($request);

        try {
            Idempotency::handle($request, 'POST /api/po/import', function (PDO $pdo) use ($request, $userId, $tanggal, $uploadType, $tmpPath, $fileName, $hash) {
                $importer = new PoImporter($pdo);
                try {
                    $rows = $importer->readRows($tmpPath, $fileName);
                } catch (\RuntimeException $e) {
                    throw new ApiException(400, 'PARSE_FAILED', $e->getMessage());
                }
                $result = $importer->import($rows, $tanggal, $uploadType, $hash, $fileName, $userId);

                $status = $result['ok'] ? 200 : match ($result['code']) {
                    'UNRESOLVED_ROWS' => 400,
                    'INITIAL_PO_ALREADY_EXISTS', 'NO_INITIAL_YET' => 409,
                    default => 400,
                };
                $envelope = $result['ok']
                    ? ['ok' => true, 'data' => $result['data']]
                    : ['ok' => false, 'code' => $result['code'], 'message' => $result['message']];

                if ($result['ok']) {
                    Audit::write(
                        $pdo, $request->header('Idempotency-Key'), $userId, 'po.import', 'po_batch',
                        (string) $result['data']['poBatchId'], 'ok', null, $result['data']['version'], $result['data']
                    );
                }

                return [
                    'status' => $status,
                    'envelope' => $envelope,
                    'recordType' => 'po_batch',
                    'recordKey' => $tanggal . '|' . $uploadType,
                ];
            });
        } finally {
            @unlink($tmpPath);
        }
    }

    /** @return array{0:string,1:string,2:string,3:string,4:string} tanggal, uploadType, tmpPath, fileName, sha256Hash */
    private static function decodeUploadBody(Request $request): array
    {
        $tanggal = (string) $request->input('tanggal', '');
        if (!self::isValidDate($tanggal)) {
            throw new ApiException(400, 'INVALID_DATE', "tanggal must be a valid 'YYYY-MM-DD' date");
        }
        $uploadType = (string) $request->input('uploadType', '');
        if (!in_array($uploadType, ['initial', 'revision'], true)) {
            throw new ApiException(400, 'INVALID_UPLOAD_TYPE', "uploadType must be 'initial' or 'revision'");
        }
        $fileName = (string) $request->input('fileName', '');
        if ($fileName === '') {
            throw new ApiException(400, 'MISSING_FILE_NAME', 'fileName is required');
        }
        $base64 = (string) $request->input('fileContentBase64', '');
        if ($base64 === '') {
            throw new ApiException(400, 'MISSING_FILE_CONTENT', 'fileContentBase64 is required');
        }
        $content = base64_decode($base64, true);
        if ($content === false) {
            throw new ApiException(400, 'INVALID_FILE_CONTENT', 'fileContentBase64 is not valid base64');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'po_upload_');
        file_put_contents($tmpPath, $content);
        $hash = hash('sha256', $content);

        return [$tanggal, $uploadType, $tmpPath, $fileName, $hash];
    }

    private static function isValidDate(string $s): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $s);
        return $d !== false && $d->format('Y-m-d') === $s;
    }
}
