<?php

declare(strict_types=1);

/**
 * Redesigned bulk-print page — one "Surat Jalan" per printed page (page
 * break between each), for a whole date+factory at once. Read-only, same
 * watermark rule as print-do.php, applied per-DO since a bulk-print set
 * can legitimately mix statuses.
 */

require __DIR__ . '/../app/ui/bootstrap.php';
require_once __DIR__ . '/../app/ui/labels.php';

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
  <button type="button" onclick="window.print()">Cetak Semua (<?= count($docs) ?> DO)</button>
  <a class="secondary" href="/api/_ui-preview/?page=delivery-order&tanggal=<?= urlencode($tanggal) ?>&factoryId=<?= $factoryId ?>">&larr; Kembali</a>
</div>

<?php if ($docs === []): ?>
<p style="margin:2rem;color:#666;">Tidak ada DO untuk tanggal &amp; pabrik ini.</p>
<?php endif; ?>

<?php foreach ($docs as $do):
  $watermark = null;
  if ($do['status'] === 'draft') { $watermark = 'DRAFT'; }
  elseif ($do['status'] === 'preprinted') { $watermark = 'PREPRINT'; }
  elseif ($do['status'] === 'cancelled') { $watermark = 'DIBATALKAN'; }
?>
<div class="print-page">
  <?php if ($watermark !== null): ?><div class="print-watermark"><?= ui_esc($watermark) ?></div><?php endif; ?>
  <div class="print-header">
    <div class="print-brand">
      <div class="print-brand-mark">A</div>
      <div>
        <div class="print-brand-name">CV. AMOR GROUP</div>
        <div class="print-brand-sub">Amor Factory System</div>
        <div class="print-doc-title">Surat Jalan / Delivery Order</div>
      </div>
    </div>
    <div class="print-meta-right">
      <div class="doc-no"><?= ui_esc($do['docNo']) ?></div>
      <div><?= ui_esc(strtoupper(ui_do_status_label($do['status']))) ?></div>
    </div>
  </div>
  <div class="print-meta-grid">
    <div><b>Tanggal</b><?= ui_esc($do['tanggal']) ?></div>
    <div><b>Toko</b><?= ui_esc((string) $do['storeName']) ?></div>
    <div><b>Jumlah Produk</b><?= (int) $do['summary']['productCount'] ?></div>
  </div>
  <table class="print-table">
    <thead><tr><th style="width:24px;">No</th><th>Produk</th><th>Divisi</th><th class="num">Qty Rencana</th></tr></thead>
    <tbody>
    <?php foreach ($do['items'] as $i => $it): ?>
    <tr><td><?= $i + 1 ?></td><td><?= ui_esc($it['productName']) ?></td><td><?= ui_esc((string) $it['divisionName']) ?></td><td class="num"><?= ui_fmt_num($it['plannedQty']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="3">Total</td><td class="num"><?= ui_fmt_num($do['summary']['totalPlanned']) ?></td></tr></tfoot>
  </table>
  <div class="print-sign-grid">
    <div class="print-sign-box"><div class="print-sign-line">Disiapkan oleh</div></div>
    <div class="print-sign-box"><div class="print-sign-line">Dikirim oleh</div></div>
    <div class="print-sign-box"><div class="print-sign-line">Diterima oleh</div></div>
  </div>
  <div class="print-footer-note"><span>Dicetak: <?= ui_esc(date('Y-m-d H:i')) ?></span><span>Amor Factory System</span></div>
</div>
<?php endforeach; ?>
</body>
</html>
