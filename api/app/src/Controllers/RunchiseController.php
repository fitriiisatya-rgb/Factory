<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Dispatch\ReceiptService;
use Amor\Api\Integration\RunchiseExportService;
use Amor\Api\Integration\RunchiseMappingService;
use Amor\Api\Request;
use Amor\Api\Response;
use Amor\Api\StorePortal\StorePortalService;

/**
 * Runchise "Impor Stok Masuk" file (migration 0024). The same preview /
 * download pair is reachable three ways, each with the access check its
 * caller already uses: the store's own Portal token (own store only), the
 * QR receipt token on the surat jalan (only the shipments that token
 * covers), and an ADMIN session (any store). Mapping is ADMIN only.
 */
final class RunchiseController
{
    public static function portalPreview(Request $request): void
    {
        [$storeId, $shipmentId] = self::portalTarget($request);
        Response::json(self::publicPreview((new RunchiseExportService(Database::pdo()))->build($storeId, $shipmentId)));
    }

    public static function portalDownload(Request $request): void
    {
        [$storeId, $shipmentId, $storeName] = self::portalTarget($request);
        self::sendFile($storeId, $shipmentId, 'Portal toko ' . $storeName, null, $request);
    }

    public static function qrPreview(Request $request): void
    {
        [$storeId, $shipmentId] = self::qrTarget($request);
        Response::json(self::publicPreview((new RunchiseExportService(Database::pdo()))->build($storeId, $shipmentId)));
    }

    public static function qrDownload(Request $request): void
    {
        [$storeId, $shipmentId] = self::qrTarget($request);
        self::sendFile($storeId, $shipmentId, 'Toko (scan QR)', null, $request);
    }

    public static function adminPreview(Request $request): void
    {
        Auth::requireRole('ADMIN');
        $shipmentId = (int) $request->routeParams['shipmentId'];
        Auth::requireAdminFactory(\Amor\Api\AdminScope::shipmentFactoryId(Database::pdo(), $shipmentId));
        Response::json(self::publicPreview((new RunchiseExportService(Database::pdo()))->build(self::storeOf($shipmentId), $shipmentId)));
    }

    public static function adminDownload(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN');
        $shipmentId = (int) $request->routeParams['shipmentId'];
        Auth::requireAdminFactory(\Amor\Api\AdminScope::shipmentFactoryId(Database::pdo(), $shipmentId));
        $me = Auth::me();
        $who = 'Admin ' . (($me['fullName'] ?? '') !== '' ? $me['fullName'] : $me['username']);
        self::sendFile(self::storeOf($shipmentId), $shipmentId, $who, $userId, $request);
    }

    public static function mapping(Request $request): void
    {
        Auth::requireLeaderAdmin();
        Response::json((new RunchiseMappingService(Database::pdo()))->overview());
    }

    public static function uploadCatalog(Request $request): void
    {
        $userId = Auth::requireLeaderAdmin();
        // The page reads the chosen .csv in the browser and posts its text.
        $text = (string) $request->input('csv', '');
        if (trim($text) === '') {
            throw new ApiException(400, 'CATALOG_REQUIRED', 'Pilih file katalog Runchise (.csv).');
        }
        if (strlen($text) > 5_000_000) {
            throw new ApiException(400, 'CATALOG_TOO_LARGE', 'File katalog terlalu besar.');
        }
        Response::json((new RunchiseMappingService(Database::pdo()))->uploadCatalog($text, $userId, $request->header('Idempotency-Key')));
    }

    public static function setProduct(Request $request): void
    {
        $userId = Auth::requireLeaderAdmin();
        $mode = $request->input('mode');
        Response::json((new RunchiseMappingService(Database::pdo()))->setProduct(
            (int) $request->routeParams['id'], $mode !== null && $mode !== '' ? (string) $mode : null,
            $request->input('sku') !== null ? (string) $request->input('sku') : null, $userId, $request->header('Idempotency-Key')
        ));
    }

    public static function setStore(Request $request): void
    {
        $userId = Auth::requireLeaderAdmin();
        $loc = $request->input('location');
        Response::json((new RunchiseMappingService(Database::pdo()))->setStore(
            (int) $request->routeParams['id'], $loc !== null ? (string) $loc : null, $userId, $request->header('Idempotency-Key')
        ));
    }

    // -----------------------------------------------------------------

    /** @return array{0:int,1:int,2:string} */
    private static function portalTarget(Request $request): array
    {
        $identity = (new StorePortalService(Database::pdo()))->resolvePortalIdentity((string) $request->routeParams['token']);
        return [(int) $identity['storeId'], (int) $request->routeParams['shipmentId'], (string) $identity['storeName']];
    }

    /** @return array{0:int,1:int} only a shipment the QR token itself covers */
    private static function qrTarget(Request $request): array
    {
        $shipmentId = (int) $request->routeParams['shipmentId'];
        $view = (new ReceiptService(Database::pdo()))->getPublicView((string) $request->routeParams['token']);
        foreach ($view['shipments'] ?? [] as $sh) {
            if ((int) $sh['shipmentId'] === $shipmentId) {
                return [self::storeOf($shipmentId), $shipmentId];
            }
        }
        throw new ApiException(404, 'NOT_FOUND', 'Pengiriman tidak ditemukan untuk tautan ini');
    }

    private static function storeOf(int $shipmentId): int
    {
        $st = Database::pdo()->prepare('SELECT store_id FROM shipment WHERE shipment_id = ?');
        $st->execute([$shipmentId]);
        $id = $st->fetchColumn();
        if ($id === false) {
            throw new ApiException(404, 'NOT_FOUND', 'Pengiriman tidak ditemukan');
        }
        return (int) $id;
    }

    private static function publicPreview(array $b): array
    {
        return [
            'ready' => $b['ready'], 'message' => $b['message'], 'location' => $b['location'], 'tanggal' => $b['tanggal'],
            'alasan' => $b['alasan'], 'docNo' => $b['docNo'], 'filename' => $b['filename'] ?? null,
            'productCount' => count($b['lines']), 'totalQty' => $b['totalQty'],
            'lines' => array_map(static fn ($l) => ['name' => $l['name'], 'sku' => $l['sku'], 'qty' => $l['qty'], 'unit' => $l['unit']], $b['lines']),
            'excluded' => $b['excluded'],
            'downloadedAt' => $b['downloadedAt'] !== null ? str_replace(' ', 'T', (string) $b['downloadedAt']) . 'Z' : null,
            'downloadedBy' => $b['downloadedBy'], 'downloadCount' => $b['downloadCount'],
            'changedSinceDownload' => $b['changedSinceDownload'],
        ];
    }

    private static function sendFile(int $storeId, int $shipmentId, string $who, ?int $userId, Request $request): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            [$filename, $csv] = (new RunchiseExportService($pdo))->download($storeId, $shipmentId, $who, $userId, $request->header('X-Request-Id'));
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
    }
}
