<?php

declare(strict_types=1);

/**
 * Single-DO print/preview page for the Phase 5 Draft DO wizard.
 *
 * Read-only — never writes anything. Shows a DRAFT/PREPRINT watermark
 * whenever the document isn't actually shipped yet (task section 5/9/27:
 * "never show a fake 'shipped' look before it's actually shipped").
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Delivery\DoService;

if (is_file(__DIR__ . '/.disabled') || Config::get('DO_UAT_ENABLED', true) === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    exit;
}

try {
    Config::load();
} catch (\Throwable $e) {
    http_response_code(200);
    echo 'Konfigurasi belum lengkap: ' . htmlspecialchars($e->getMessage());
    exit;
}

Auth::bootSession();

if (!function_exists('esc')) {
    function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
}
if (!function_exists('fmtNum')) {
    function fmtNum(float $n): string { return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ','); }
}

if (Auth::currentUserId() === null) {
    echo 'Login diperlukan — buka <a href="../_admin-login/">../_admin-login/</a> dulu.';
    exit;
}
try {
    Auth::requireRole('ADMIN');
} catch (ApiException $e) {
    echo 'Akses ditolak — akun Anda bukan ADMIN.';
    exit;
}

$doId = isset($_GET['doId']) ? (int) $_GET['doId'] : 0;
$pdo = Database::pdo();
$service = new DoService($pdo);

try {
    $do = $service->getDo($doId);
} catch (\Throwable $e) {
    http_response_code(200);
    echo 'Gagal memuat DO: ' . htmlspecialchars($e->getMessage());
    exit;
}

$watermark = null;
if ($do['status'] === 'draft') {
    $watermark = 'DRAFT — BELUM DICETAK RESMI';
} elseif ($do['status'] === 'preprinted') {
    $watermark = 'PREPRINT — BELUM DIKIRIM';
} elseif ($do['status'] === 'cancelled') {
    $watermark = 'DIBATALKAN';
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Draft DO <?= esc($do['docNo']) ?></title>
<style>
body{font-family:Arial,sans-serif;max-width:800px;margin:1.5rem auto;padding:0 1rem;color:#111;position:relative;}
.doc-header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #111;padding-bottom:.6rem;margin-bottom:1rem;}
.doc-title{font-size:1.3rem;font-weight:bold;} .doc-sub{font-size:.85rem;color:#333;}
.meta-grid{display:flex;gap:2rem;margin-bottom:1rem;font-size:.9rem;}
table{border-collapse:collapse;width:100%;margin:.5rem 0;}
td,th{text-align:left;padding:.4rem .6rem;border:1px solid #999;font-size:.85rem;}
th{background:#eee;}
.sig-grid{display:flex;justify-content:space-between;margin-top:3rem;text-align:center;}
.sig-box{width:30%;} .sig-line{margin-top:3.5rem;border-top:1px solid #333;padding-top:.3rem;}
.watermark{position:fixed;top:40%;left:0;right:0;text-align:center;font-size:2.6rem;font-weight:bold;color:rgba(200,0,0,.18);
  transform:rotate(-25deg);pointer-events:none;z-index:0;text-transform:uppercase;}
.no-print{margin:1rem 0;}
@media print { .no-print{display:none;} body{margin:0;} }
</style>
</head>
<body>
<?php if ($watermark !== null): ?><div class="watermark"><?= esc($watermark) ?></div><?php endif; ?>

<div class="no-print"><button onclick="window.print()">Cetak</button> <a href="index.php?doId=<?= (int) $do['doId'] ?>">&larr; Kembali</a></div>

<div class="doc-header">
  <div>
    <div class="doc-title">CV. AMOR GROUP</div>
    <div class="doc-sub">SURAT JALAN / DELIVERY ORDER</div>
  </div>
  <div style="text-align:right;">
    <div><strong>No: <?= esc($do['docNo']) ?></strong></div>
    <div class="doc-sub">Status: <?= esc(strtoupper($do['status'])) ?></div>
  </div>
</div>

<div class="meta-grid">
  <div><strong>Tanggal:</strong> <?= esc($do['tanggal']) ?></div>
  <div><strong>Toko:</strong> <?= esc((string) $do['storeName']) ?></div>
</div>

<table>
  <tr><th>Produk</th><th>Divisi</th><th>Qty Rencana</th></tr>
  <?php foreach ($do['items'] as $it): ?>
  <tr><td><?= esc($it['productName']) ?></td><td><?= esc((string) $it['divisionName']) ?></td><td><?= fmtNum($it['plannedQty']) ?></td></tr>
  <?php endforeach; ?>
  <tr><td colspan="2" style="text-align:right;"><strong>Total</strong></td><td><strong><?= fmtNum($do['summary']['totalPlanned']) ?></strong></td></tr>
</table>

<div class="sig-grid">
  <div class="sig-box"><div class="sig-line">Disiapkan oleh</div></div>
  <div class="sig-box"><div class="sig-line">Dikirim oleh</div></div>
  <div class="sig-box"><div class="sig-line">Diterima oleh</div></div>
</div>

</body>
</html>
