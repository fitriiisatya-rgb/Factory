<?php

declare(strict_types=1);

/**
 * "Print Divisi Ini" — single-division A4 LANDSCAPE Production Task
 * worksheet (task's own "Prefer: A4 landscape if required to fit columns
 * cleanly" — 9 columns including a Catatan Khusus/Paraf pair need the
 * extra width). Read-only: ProductionTaskService::tasksForDivision() is
 * a SELECT-only aggregation, this page writes nothing.
 */

require __DIR__ . '/../app/ui/bootstrap.php';
require_once __DIR__ . '/../app/ui/print-production-task-template.php';

use Amor\Api\Production\ProductionTaskService;

$tanggal = (string) ($_GET['tanggal'] ?? date('Y-m-d'));
$divisionId = isset($_GET['divisionId']) ? (int) $_GET['divisionId'] : 0;

$service = new ProductionTaskService($ui['pdo']);
try {
    $division = $service->tasksForDivision($tanggal, $divisionId, null, null);
} catch (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:2rem auto;">'
        . '<h1>Gagal memuat Task per Divisi</h1><p>' . ui_esc($e->getMessage()) . '</p></body></html>';
    exit;
}

$printedByName = $ui['fullName'] !== '' ? $ui['fullName'] : $ui['username'];

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Production Task — <?= ui_esc($division['divisionName']) ?></title>
<link rel="stylesheet" href="/api/assets/css/print.css">
<style>
/* A4 LANDSCAPE override — print.css's shared @page is portrait (fits the
   DO/Surat Jalan documents); this document's 9 columns need the width. */
@page { size: A4 landscape; margin: 12mm 10mm; }
.print-page { max-width: 297mm; min-height: 0; }
</style>
</head>
<body class="print-doc">
<div class="print-toolbar">
  <a class="secondary" href="/api/_ui-preview/?page=produksi-task-per-divisi&tanggal=<?= urlencode($tanggal) ?>&divisionId=<?= $divisionId ?>">&larr; Kembali</a>
  <span class="print-toolbar-title">Production Task — <?= ui_esc($division['divisionName']) ?></span>
  <button type="button" class="primary" onclick="window.print()">Cetak</button>
</div>

<?php ui_render_production_task_print_document($division, $printedByName, 1, 1); ?>
</body>
</html>
