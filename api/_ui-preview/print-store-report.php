<?php

declare(strict_types=1);

/**
 * Admin / PPIC / Atasan — print a store's Retur / Reject / Mutasi
 * transaction (A4 or thermal 80 mm). An Atasan only sees their own stores.
 */

require __DIR__ . '/../app/ui/bootstrap.php';
require_once __DIR__ . '/../app/ui/print-store-report.php';

use Amor\Api\StorePortal\StoreReportService;

$format = ($_GET['format'] ?? 'a4') === 'thermal' ? 'thermal' : 'a4';
$id = (int) ($_GET['id'] ?? 0);
$back = (string) ($_GET['back'] ?? '/api/_ui-preview/');
if (!str_starts_with($back, '/api/')) {
    $back = '/api/_ui-preview/';
}
try {
    if (array_intersect(['ADMIN', 'PPIC', 'ATASAN'], $ui['roles']) === []) {
        throw new \RuntimeException('Anda tidak memiliki izin untuk membuka bukti ini.');
    }
    $svc = new StoreReportService($ui['pdo']);
    $report = $svc->get($id);
    if (array_intersect(['ADMIN', 'PPIC'], $ui['roles']) === [] && !in_array($report['storeId'], $svc->storeIdsForAtasan((int) $ui['userId']), true)) {
        throw new \RuntimeException('Transaksi tidak ditemukan.');
    }
} catch (\Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:2rem auto;"><h1>Bukti tidak dapat dibuka</h1><p>'
        . ui_esc($e->getMessage()) . '</p></body></html>';
    exit;
}
render_store_report_print($report, $format, $back, '/api/_ui-preview/print-store-report.php?id=' . $id . '&back=' . urlencode($back) . '&format=');
