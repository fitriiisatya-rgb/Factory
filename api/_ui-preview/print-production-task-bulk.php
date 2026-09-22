<?php

declare(strict_types=1);

/**
 * "Print Semua Divisi" — one A4 LANDSCAPE Production Task page PER
 * division in the selected factory (task's own explicit "Recommended:
 * one division per page... Do not merge all divisions into one
 * unreadable table"). Reuses the SAME per-division render function as
 * the single-division print (print-production-task.php), just looped —
 * mirrors print-do-bulk.php's own relationship to print-do.php exactly.
 */

require __DIR__ . '/../app/ui/bootstrap.php';
require_once __DIR__ . '/../app/ui/print-production-task-template.php';

use Amor\Api\Production\ProductionTaskService;

$tanggal = (string) ($_GET['tanggal'] ?? date('Y-m-d'));
$factoryId = isset($_GET['factoryId']) ? (int) $_GET['factoryId'] : 0;

$service = new ProductionTaskService($ui['pdo']);
try {
    $factoryData = $service->tasksForFactory($tanggal, $factoryId);
} catch (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:2rem auto;">'
        . '<h1>Gagal memuat Task per Divisi</h1><p>' . ui_esc($e->getMessage()) . '</p></body></html>';
    exit;
}

$printedByName = $ui['fullName'] !== '' ? $ui['fullName'] : $ui['username'];
$pageTotal = count($factoryData['divisions']);

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Production Task — Semua Divisi — <?= ui_esc($factoryData['factoryName']) ?></title>
<link rel="stylesheet" href="/api/assets/css/print.css">
<style>
@page { size: A4 landscape; margin: 12mm 10mm; }
.print-page { max-width: 297mm; min-height: 0; }
</style>
</head>
<body class="print-doc">
<div class="print-toolbar">
  <a class="secondary" href="/api/_ui-preview/?page=produksi-task-per-divisi&tanggal=<?= urlencode($tanggal) ?>&factoryId=<?= $factoryId ?>">&larr; Kembali</a>
  <span class="print-toolbar-title">Production Task — Semua Divisi — <?= ui_esc($factoryData['factoryName']) ?></span>
  <button type="button" class="primary" onclick="window.print()">Cetak Semua</button>
</div>

<?php if ($pageTotal === 0): ?>
<div class="print-page"><p style="text-align:center;color:#888;">Pabrik ini belum punya divisi produksi.</p></div>
<?php else: $pageNum = 1; foreach ($factoryData['divisions'] as $division): ?>
<?php ui_render_production_task_print_document($division, $printedByName, $pageNum, $pageTotal); $pageNum++; ?>
<?php endforeach; endif; ?>
</body>
</html>
