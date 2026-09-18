<?php

declare(strict_types=1);

/**
 * Shared DO/Surat Jalan print document markup — the ONE place this HTML
 * is written, called once by print-do.php (single) and once per DO in a
 * loop by print-do-bulk.php, so the two never drift apart (task's own
 * "avoid duplicating print HTML between single print and bulk print").
 *
 * Read-only: takes an already-built DoService::getDo() DTO and renders
 * it. Never recomputes planned/shipped/remaining/status with a different
 * rule than the service already used — no business logic lives here.
 */

use Amor\Api\Dispatch\ReceiptService;
use Amor\Api\Ui\QrEncoder;

/**
 * Phase 5.5, Part G/J — a stable receipt QR printed on Draft/Preprint (and
 * any non-cancelled) DO, pointing at the public Store Receipt portal. The
 * token is get-or-created lazily on first print (ReceiptService::
 * getReceiptToken() — see its own docblock for why this one write is
 * safe): it never touches delivery_order itself, never changes its
 * version/status, never creates a shipment, never consumes FG. Cancelled
 * DOs get no QR — there is nothing for a store to ever receive against.
 */
function ui_do_receipt_qr_svg(PDO $pdo, int $doId): string
{
    $token = (new ReceiptService($pdo))->getReceiptToken($doId);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url = "{$scheme}://{$host}/api/_receive/?token={$token}";
    return QrEncoder::toSvg($url, 3);
}

/**
 * Distinct factory name(s) for a DO's items, joined — a DO can legitimately
 * span two factories (locked cross-factory-store design), so this is never
 * a single lookup. $factoryNamesById is a cheap map built once per print
 * job (see callers), not one query per item.
 * @param array $do a DoService::getDo() DTO
 * @param array<int,string> $factoryNamesById
 */
function ui_print_factory_label(array $do, array $factoryNamesById): string
{
    $names = [];
    foreach ($do['items'] as $it) {
        $fid = $it['factoryId'] ?? null;
        if ($fid !== null && isset($factoryNamesById[$fid]) && !in_array($factoryNamesById[$fid], $names, true)) {
            $names[] = $factoryNamesById[$fid];
        }
    }
    return $names === [] ? '-' : implode(' + ', $names);
}

/** @return array{watermark:?string,label:string} */
function ui_print_status(string $status): array
{
    return [
        'draft' => ['watermark' => 'DRAFT', 'label' => 'DRAFT'],
        'preprinted' => ['watermark' => 'PREPRINT', 'label' => 'PREPRINT'],
        'shipped' => ['watermark' => null, 'label' => 'TERKIRIM'],
        'cancelled' => ['watermark' => 'DIBATALKAN', 'label' => 'DIBATALKAN'],
    ][$status] ?? ['watermark' => null, 'label' => strtoupper($status)];
}

/**
 * @param array $do a DoService::getDo() DTO
 * @param string $factoryLabel factory name(s) this DO's items belong to — precomputed by the
 *        caller (a DO can span two factories per the locked cross-factory-store design, so
 *        this is a distinct-name join, not a single lookup; see print-do.php/print-do-bulk.php)
 * @param string $printedByName the current session user's display name (task section 8 — safely available, shown small, signature box stays either way)
 * @param int $pageNum 1-based page number within this print job
 * @param int $pageTotal total pages in this print job
 * @param PDO $pdo used ONLY to get-or-create this DO's receipt QR token (Phase 5.5, Part G/J) —
 *        no other read/write happens through it here; every other field above already came
 *        from the caller's own DoService::getDo() DTO.
 */
function ui_render_do_print_document(array $do, string $factoryLabel, string $printedByName, int $pageNum, int $pageTotal, PDO $pdo): void
{
    $status = ui_print_status($do['status']);
    ?>
<div class="print-page">
  <?php if ($status['watermark'] !== null): ?>
  <div class="print-watermark"><?= ui_esc($status['watermark']) ?></div>
  <?php endif; ?>

  <div class="print-header">
    <div class="print-brand">
      <img class="print-brand-mark" src="/api/assets/img/amor-logo.png" alt="Amor" width="40" height="40">
      <div>
        <div class="print-brand-name">Amorcakes &amp; Bakery</div>
        <div class="print-doc-title">Delivery Order / Surat Jalan</div>
      </div>
    </div>
    <div class="print-meta-right">
      <div class="doc-no">No. DO: <?= ui_esc((string) $do['docNo']) ?></div>
      <div>Status: <strong><?= ui_esc($status['label']) ?></strong></div>
    </div>
  </div>

  <div class="print-meta-grid">
    <div><b>Tanggal</b><?= ui_esc((string) $do['tanggal']) ?></div>
    <div><b>Toko</b><?= ui_esc((string) $do['storeName']) ?></div>
    <div><b>Pabrik</b><?= ui_esc($factoryLabel) ?></div>
    <div><b>Status</b><?= ui_esc($status['label']) ?></div>
  </div>

  <table class="print-table">
    <thead>
      <tr>
        <th style="width:22px;">No</th>
        <th>Produk</th>
        <th>Divisi</th>
        <th class="num">Qty Rencana</th>
        <th class="num">Sudah Dikirim</th>
        <th class="num">Sisa</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($do['items'] as $i => $it): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= ui_esc((string) $it['productName']) ?></td>
        <td><?= ui_esc((string) $it['divisionName']) ?></td>
        <td class="num"><?= ui_fmt_num((float) $it['plannedQty']) ?></td>
        <td class="num"><?= ui_fmt_num((float) $it['alreadyShippedQty']) ?></td>
        <td class="num"><?= ui_fmt_num((float) $it['remainingToShip']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3">Total</td>
        <td class="num"><?= ui_fmt_num((float) $do['summary']['totalPlanned']) ?></td>
        <td class="num"><?= ui_fmt_num((float) $do['summary']['totalShipped']) ?></td>
        <td class="num"><?= ui_fmt_num((float) $do['summary']['totalRemaining']) ?></td>
      </tr>
    </tfoot>
  </table>

  <div class="print-notes">
    <b>Catatan</b>
    <div class="print-notes-body"><?= $do['catatan'] !== null && trim((string) $do['catatan']) !== '' ? nl2br(ui_esc((string) $do['catatan'])) : '&nbsp;' ?></div>
  </div>

  <?php if ($do['status'] !== 'cancelled'): ?>
  <div class="print-receipt-qr">
    <div class="print-receipt-qr-code"><?= ui_do_receipt_qr_svg($pdo, (int) $do['doId']) ?></div>
    <div class="print-receipt-qr-label">Scan untuk Konfirmasi<br>Penerimaan Barang</div>
  </div>
  <?php endif; ?>

  <div class="print-sign-grid">
    <div class="print-sign-box"><div class="print-sign-line">Disiapkan Oleh</div></div>
    <div class="print-sign-box"><div class="print-sign-line">Driver / Pengirim</div></div>
    <div class="print-sign-box"><div class="print-sign-line">Diterima Oleh</div></div>
  </div>

  <div class="print-footer-note">
    <span>Dicetak: <?= ui_esc(date('Y-m-d H:i')) ?> oleh <?= ui_esc($printedByName) ?></span>
    <span>Halaman <?= $pageNum ?> dari <?= $pageTotal ?></span>
    <span>Amor Factory System</span>
  </div>
</div>
<?php
}
