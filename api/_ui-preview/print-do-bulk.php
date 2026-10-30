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
require_once __DIR__ . '/../app/ui/do-split-print.php';

use Amor\Api\Delivery\DoService;

$tanggal = (string) ($_GET['tanggal'] ?? '');
$factoryId = isset($_GET['factoryId']) ? (int) $_GET['factoryId'] : 0;
$service = new DoService($ui['pdo']);

try {
    $list = $service->listDosForFactory($tanggal, $factoryId);
    // Print per divisi: ?div[]=<divisi>&do[]=<doId>&split=1&keepEmpty=1
    // (one page per store as always; only the chosen divisions' products).
    $divs = array_values(array_filter(array_map('strval', (array) ($_GET['div'] ?? [])), static fn ($d) => $d !== ''));
    $onlyDos = array_map('intval', (array) ($_GET['do'] ?? []));
    $split = ($_GET['split'] ?? '') === '1';
    $keepEmpty = ($_GET['keepEmpty'] ?? '') === '1';
    $docs = [];
    foreach ($list as $row) {
        if ($onlyDos !== [] && !in_array((int) $row['doId'], $onlyDos, true)) {
            continue;
        }
        $full = $service->getDo((int) $row['doId']);
        if ($divs === []) {
            $docs[] = ['do' => $full, 'bagian' => null, 'note' => null];
            continue;
        }
        $groups = $split ? array_map(static fn ($d) => [$d], $divs) : [$divs];
        $printedAny = false;
        foreach ($groups as $g) {
            $part = ui_do_filter_divisions($full, $g);
            if ($part === null) {
                continue;
            }
            $printedAny = true;
            $docs[] = ['do' => $part, 'bagian' => implode(' + ', array_values(array_intersect($g, array_unique(array_column($part['items'], 'divisionName'))))), 'note' => ui_do_other_divisions_note($full, $g)];
        }
        if (!$printedAny && $keepEmpty) {
            $empty = $full;
            $empty['items'] = [];
            $empty['summary'] = ['totalPlanned' => 0, 'totalShipped' => 0, 'totalRemaining' => 0] + $full['summary'];
            $docs[] = ['do' => $empty, 'bagian' => implode(' + ', $divs), 'note' => 'Toko ini tidak punya produk dari divisi yang dipilih.'];
        }
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
<link rel="stylesheet" href="/api/assets/css/print.css?v=20261030-do">
</head>
<body class="print-doc">
<div class="print-toolbar">
  <a class="secondary" href="/api/_ui-preview/?page=delivery-order&tanggal=<?= urlencode($tanggal) ?>&factoryId=<?= $factoryId ?>">&larr; Kembali</a>
  <span class="print-toolbar-title"><?= ui_esc($tanggal) ?> &middot; <?= count($docs) ?> halaman<?= $divs !== [] ? ' &middot; ' . ui_esc(implode(', ', $divs)) : '' ?></span>
  <button type="button" class="primary" onclick="window.print()">Cetak Semua</button>
</div>

<?php if ($docs === []): ?>
<p style="margin:2rem;color:#cbd5e1;font-family:sans-serif;">Tidak ada DO untuk tanggal &amp; pabrik ini.</p>
<?php endif; ?>

<?php foreach ($docs as $i => $doc): ?>
<?php ui_render_do_print_document($doc['do'], ui_print_factory_label($doc['do'], $factoryNamesById), $printedByName, $i + 1, $pageTotal, $ui['pdo'], $doc['bagian'], $doc['note']); ?>
<?php endforeach; ?>
</body>
</html>
