<?php

declare(strict_types=1);

/**
 * Proof of a store's Retur / Reject / Mutasi transaction (migration 0026),
 * the same document the stores used to print from their Google Apps Script
 * form: one section per destination — the factory for Retur/Reject items,
 * the destination store for Mutasi items — each with item lines, value,
 * signature lines and the Atasan approval status.
 *
 *   format=a4       A4 portrait, one destination per page.
 *   format=thermal  80 mm roll (RawBT / Android print), sections one after
 *                   another with a cut line.
 *
 * The page opens the browser print dialog by itself (on Android: pick
 * RawBT or the printer), unless ?noprint=1.
 */
function render_store_report_print(array $report, string $format, string $backUrl, string $switchUrlBase): void
{
    $thermal = $format === 'thermal';
    $esc = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    $rp = static fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $num = static fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $jenisLabel = ['retur' => 'Retur', 'reject' => 'Reject', 'mutasi' => 'Mutasi'];

    $groups = [];
    foreach ($report['items'] as $it) {
        $key = $it['jenis'] === 'mutasi'
            ? 'Toko: ' . ($it['destinationStoreName'] ?? '(Tanpa Tujuan)')
            : ($it['pabrik'] ?? '(Tanpa Pabrik)');
        $groups[$key][] = $it;
    }

    $wib = static function (?string $utc): string {
        if ($utc === null || $utc === '') {
            return '';
        }
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i');
    };
    if ($report['status'] !== 'disetujui') {
        $approval = $thermal ? 'Menunggu Approval' : 'Menunggu Persetujuan Atasan';
    } elseif ($report['approvalMode'] === 'otomatis') {
        $approval = $thermal ? 'Disetujui (otomatis)' : 'Disetujui otomatis (toko tanpa Atasan)';
    } else {
        $approval = ($thermal ? 'Disetujui: ' : 'Disetujui oleh Atasan: ') . ($report['approvedByName'] ?? '-') . ($thermal ? '' : ' (' . $wib($report['approvedAt']) . ')');
    }
    $autoPrint = !isset($_GET['noprint']);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Bukti <?= $esc($report['docNo']) ?></title>
<style>
<?php if ($thermal): ?>
  @page { size: 80mm auto; margin: 3mm; }
  body { font-family: Arial, Helvetica, sans-serif; color: #000; width: 74mm; margin: 0 auto; font-size: 9pt; }
  .h { text-align: center; font-size: 12pt; font-weight: bold; }
  .sm { font-size: 8pt; } .xs { font-size: 7pt; }
  hr { border: none; border-top: 1px dashed #000; margin: 6px 0; }
  .it { margin-top: 6px; } .it b { font-size: 9pt; }
  .tot { font-size: 10pt; font-weight: bold; }
  .cut { border-top: 1px dashed #000; margin: 14px 0 10px; text-align: center; font-size: 7pt; }
<?php else: ?>
  @page { size: A4; margin: 20mm; }
  body { font-family: Arial, Helvetica, sans-serif; color: #000; max-width: 170mm; margin: 0 auto; font-size: 11pt; }
  .h { font-size: 18px; font-weight: bold; margin-bottom: 10px; }
  hr { border: none; border-top: 1px solid #999; margin: 14px 0; }
  .it { margin-top: 14px; } .it b { font-size: 14px; } .it div { font-size: 12px; }
  .tot { font-size: 15px; font-weight: bold; }
  .sign { margin-top: 24px; } .sign + .sign { margin-top: 12px; }
  .page { page-break-after: always; } .page:last-of-type { page-break-after: auto; }
  @media screen { .page { border-bottom: 2px dashed #bbb; padding-bottom: 18px; margin-bottom: 18px; } }
<?php endif; ?>
  .bar { position: sticky; top: 0; background: #f3f5f9; border-bottom: 1px solid #d7dce6; padding: 8px; display: flex; gap: 6px; flex-wrap: wrap; font-family: Arial, sans-serif; }
  .bar a, .bar button { font: 600 13px Arial, sans-serif; padding: 9px 12px; border-radius: 8px; border: 1px solid #c9d1e0; background: #fff; color: #1a1f2b; text-decoration: none; cursor: pointer; }
  .bar .pri { background: #1a73e8; border-color: #1a73e8; color: #fff; }
  .hint { font: 12px Arial, sans-serif; color: #555; padding: 6px 8px; }
  @media print { .bar, .hint { display: none !important; } }
</style>
</head>
<body>
<div class="bar">
  <button type="button" class="pri" onclick="window.print()">🖨️ Cetak</button>
  <a href="<?= $esc($switchUrlBase . ($thermal ? 'a4' : 'thermal')) ?>&noprint=1"><?= $thermal ? 'Ganti ke A4' : 'Ganti ke Thermal 80mm' ?></a>
  <a href="<?= $esc($backUrl) ?>">← Kembali</a>
</div>
<div class="hint"><?= $thermal ? 'Printer thermal (Android): tekan Cetak, lalu pilih RawBT / printer Bluetooth.' : 'Tekan Cetak, lalu pilih printer atau "Simpan sebagai PDF".' ?></div>
<?php foreach ($groups as $tujuan => $items):
    $kinds = [];
    foreach ($items as $it) {
        $kinds[strtoupper($jenisLabel[$it['jenis']])] = true;
    }
    $isToko = str_starts_with($tujuan, 'Toko: ');
    $total = 0.0;
    ?>
<div class="page">
<?php if ($thermal): ?>
  <div class="h">BUKTI <?= $esc(implode('/', array_keys($kinds))) ?></div>
  <div>Toko: <?= $esc($report['storeName']) ?></div>
  <div>Tgl: <?= $esc($report['tanggal']) ?></div>
  <div class="xs">ID: <?= $esc($report['docNo']) ?></div>
  <div><?= $esc($isToko ? $tujuan : 'Pabrik: ' . $tujuan) ?></div>
  <hr>
  <?php foreach ($items as $i => $it): $total += $it['total']; ?>
  <div class="it">
    <b><?= $i + 1 ?>. <?= $esc($it['productName']) ?> [<?= $esc($jenisLabel[$it['jenis']]) ?>]</b>
    <div class="sm"><?= $num($it['qty']) ?> x <?= $rp($it['harga']) ?> = <?= $rp($it['total']) ?></div>
    <?php if (!empty($it['expDate'])): ?><div class="sm">Exp: <?= $esc($it['expDate']) ?></div><?php endif; ?>
    <?php if (!empty($it['alasan'])): ?><div class="sm">Alasan: <?= $esc($it['alasan']) ?></div><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <hr>
  <div class="tot">TOTAL: <?= $rp($total) ?></div>
  <div class="sm"><?= $esc($approval) ?></div>
  <div class="sm" style="margin-top:8px">TTD Penyerah: ___________</div>
  <div class="sm">TTD Penerima: ___________</div>
  <div class="cut">- - - potong di sini - - -</div>
<?php else: ?>
  <div class="h">BUKTI <?= $esc(implode(' / ', array_keys($kinds))) ?> PRODUK</div>
  <div>Toko: <?= $esc($report['storeName']) ?></div>
  <div>Tanggal: <?= $esc($report['tanggal']) ?></div>
  <div>ID Transaksi: <?= $esc($report['docNo']) ?></div>
  <div><?= $esc($isToko ? 'Toko Tujuan: ' . substr($tujuan, 6) : 'Pabrik Tujuan: ' . $tujuan) ?></div>
  <hr>
  <?php foreach ($items as $i => $it): $total += $it['total']; ?>
  <div class="it">
    <b><?= $i + 1 ?>. <?= $esc($it['productName']) ?> [<?= $esc($jenisLabel[$it['jenis']]) ?>]</b>
    <div>Kategori: <?= $esc($it['kategori'] ?: '-') ?></div>
    <div>Qty: <?= $num($it['qty']) ?> &nbsp; Harga Satuan: <?= $rp($it['harga']) ?></div>
    <div>Subtotal: <?= $rp($it['total']) ?></div>
    <?php if (!empty($it['expDate'])): ?><div>Tanggal Expired: <?= $esc($it['expDate']) ?></div><?php endif; ?>
    <?php if (!empty($it['alasan'])): ?><div>Alasan: <?= $esc($it['alasan']) ?></div><?php endif; ?>
    <?php if (!empty($it['notes'])): ?><div>Catatan: <?= $esc($it['notes']) ?></div><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <hr>
  <div class="tot">TOTAL NILAI: <?= $rp($total) ?></div>
  <div class="sign">Tanda tangan penyerah: ______________________</div>
  <div class="sign">Tanda tangan penerima (PIC): ______________________</div>
  <div style="margin-top:18px"><?= $esc($approval) ?></div>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { try { window.print(); } catch (e) {} }, 400); });</script>
<?php endif; ?>
</body>
</html>
<?php
}
