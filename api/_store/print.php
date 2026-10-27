<?php

declare(strict_types=1);

/**
 * Permanent Bakery Portal — print a Retur / Reject / Mutasi transaction
 * (A4 or thermal 80 mm). ?token=&id=&format=a4|thermal. The token decides
 * the store; a transaction of another store is never shown.
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/ui/print-store-report.php';

use Amor\Api\Database;
use Amor\Api\StorePortal\StoreReportService;

$format = ($_GET['format'] ?? 'a4') === 'thermal' ? 'thermal' : 'a4';
$id = (int) ($_GET['id'] ?? 0);
try {
    if ($storeErrorMessage !== null) {
        throw new \RuntimeException($storeErrorMessage);
    }
    $report = (new StoreReportService(Database::pdo()))->get($id, (int) $storeIdentity['storeId']);
} catch (\Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
        . '<body style="font-family:sans-serif;max-width:480px;margin:2rem auto;padding:0 1rem;"><h1>Bukti tidak dapat dibuka</h1><p>'
        . store_esc($e->getMessage()) . '</p></body></html>';
    exit;
}
$t = urlencode($storeToken);
render_store_report_print(
    $report,
    $format,
    "index.php?token={$t}&tab=retur&view=riwayat",
    "print.php?token={$t}&id={$id}&format="
);
