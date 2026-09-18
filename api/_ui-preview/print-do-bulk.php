<?php

declare(strict_types=1);

/**
 * Redesigned bulk-print page — one Delivery Order / Surat Jalan per
 * printed page (page break between each), for a whole date+factory at
 * once, using the SAME shared document template as print-do.php (task's
 * own "avoid duplicating print HTML between single print and bulk
 * print"). Read-only, same watermark rule as print-do.php, applied
 * per-DO since a bulk-print set can legitimately mix statuses.
 */

require __DIR__ . '/../app/ui/bootstrap.php';
require_once __DIR__ . '/../app/ui/labels.php';
require_once __DIR__ . '/../app/ui/print-template.php';

use Amor\Api\Delivery\DoService;

$tanggal = (string) ($_GET['tanggal'] ?? '');
$factoryId = isset($_GET['factoryId']) ? (int) $_GET['factoryId'] : 0;
$service = new DoService($ui['pdo']);

try {
    $list = $service->listDosForFactory($tanggal, $factoryId);
    $docs = [];
    foreach ($list as $row) {
        $docs[] = $service->getDo((int) $row['doId']);
    }
} catch (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:2rem auto;">'
        . '<h1>Gagal memuat daftar DO</h1><p>' . ui_esc($e->getMessage()) . '</p></body></html>';
    exit;
}

$factoryNamesById = [];
foreach ($ui['pdo']->query('SELECT factory_id, name FROM factory')->fetchAll() as $f) {
    $factoryNamesById[(int) $f['factory_id']] = $f['name'];
}
$printedByName = $ui['fullName'] !== '' ? $ui['fullName'] : $ui['username'];
$pageTotal = count($docs);

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Print Bulk DO — <?= ui_esc($tanggal) ?></title>
<link rel="stylesheet" href="/api/app/ui/assets/css/print.css">
</head>
<body class="print-doc">
<div class="print-toolbar">
  <a class="secondary" href="/api/_ui-preview/?page=delivery-order&tanggal=<?= urlencode($tanggal) ?>&factoryId=<?= $factoryId ?>">&larr; Kembali</a>
  <span class="print-toolbar-title"><?= ui_esc($tanggal) ?> &middot; <?= count($docs) ?> DO</span>
  <button type="button" class="primary" onclick="window.print()">Cetak Semua</button>
</div>

<?php if ($docs === []): ?>
<p style="margin:2rem;color:#cbd5e1;font-family:sans-serif;">Tidak ada DO untuk tanggal &amp; pabrik ini.</p>
<?php endif; ?>

<?php foreach ($docs as $i => $do): ?>
<?php ui_render_do_print_document($do, ui_print_factory_label($do, $factoryNamesById), $printedByName, $i + 1, $pageTotal, $ui['pdo']); ?>
<?php endforeach; ?>
</body>
</html>
