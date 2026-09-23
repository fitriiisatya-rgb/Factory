<?php

declare(strict_types=1);

/**
 * Shared Surat Jalan (actual shipment) print document markup. Real-UAT
 * ask: the existing Draft DO print (print-template.php) shows every
 * PLANNED item on the whole DO — correct as a picking/planning document,
 * but wrong as the physical proof-of-goods a Driver carries once a DO has
 * split into several real shipments (1 DO -> many claims -> many
 * shipments). This is a SEPARATE document: it renders ONLY
 * shipment_item rows for ONE real shipment, via
 * DispatchService::shipmentDetail()'s DTO — never delivery_order_item,
 * never dispatch_claim, never PO planned quantity. After departure, the
 * shipment IS the dispatch truth (same principle SESSION-HANDOFF.md
 * already documents for shipment tracing).
 *
 * Read-only: takes an already-built shipmentDetail() DTO and renders it.
 * No business logic lives here — never recomputes qty, never touches
 * stock_ledger/shipment/dispatch_claim.
 *
 * Reuses the EXISTING DO-level receipt QR (ui_do_receipt_qr_svg() from
 * print-template.php) — "1 DO = 1 receipt token" is unchanged; two
 * shipments under the same DO print the SAME QR, by design (the public
 * Store Receipt portal already lists every shipment under that DO
 * separately — see ReceiptService::getPublicView(), untouched by this
 * patch).
 */

require_once __DIR__ . '/print-template.php';

/** @return array{watermark:?string,label:string} */
function ui_print_shipment_status(string $status): array
{
    return [
        'active' => ['watermark' => null, 'label' => 'BERANGKAT'],
        'void' => ['watermark' => 'DIBATALKAN', 'label' => 'DIBATALKAN'],
    ][$status] ?? ['watermark' => null, 'label' => strtoupper($status)];
}

/**
 * @param array $shipment a DispatchService::shipmentDetail() DTO
 * @param string $printedByName the current session user's display name
 * @param PDO $pdo used ONLY to get-or-create the OWNING DO's receipt QR
 *        token (same token every shipment under that DO shares) — no
 *        other read/write happens through it here.
 * @param string $qrSizeClass one of sj-qr-35mm/sj-qr-40mm/sj-qr-50mm —
 *        real-UAT dot-matrix physical print size comparison (task's own
 *        "make QR size configurable... one CSS variable/class is
 *        enough"); default is applied by the caller.
 */
function ui_render_shipment_print_document(array $shipment, string $printedByName, PDO $pdo, string $qrSizeClass = 'sj-qr-40mm'): void
{
    $status = ui_print_shipment_status((string) $shipment['status']);
    ?>
<div class="print-page">
  <?php if ($status['watermark'] !== null): ?>
  <div class="print-watermark"><?= ui_esc($status['watermark']) ?></div>
  <?php endif; ?>

  <div class="print-header">
    <div class="print-brand">
      <img class="print-brand-mark" src="/api/assets/img/amor-logo.png" alt="Amor" width="40" height="40">
      <div>
        <div class="print-brand-name">Amor Cakes &amp; Bakery</div>
        <div class="print-doc-title">SURAT JALAN</div>
      </div>
    </div>
    <div class="print-meta-right">
      <div class="doc-no">No. Shipment: SHP-<?= (int) $shipment['shipmentId'] ?></div>
      <div>Status: <strong><?= ui_esc($status['label']) ?></strong></div>
    </div>
  </div>

  <?php $source = $shipment['source'] ?? null; $isSpecial = $source !== null && $source['type'] !== 'REGULAR_STORE_PO'; ?>
  <div class="print-meta-grid">
    <div><b>No. DO</b><?= ui_esc((string) ($shipment['docNo'] ?? '-')) ?></div>
    <div><b>Tanggal DO</b><?= ui_esc((string) ($shipment['doTanggal'] ?? $shipment['tanggal'] ?? '-')) ?></div>
    <div><b>Tanggal/Jam Berangkat</b><?= ui_esc(ui_fmt_datetime_id($shipment['shippedAt'] ?? null)) ?></div>
    <div><b>Nama Toko/Drop</b><?= ui_esc((string) ($shipment['storeName'] ?? '-')) ?></div>
    <div><b>Factory Asal</b><?= ui_esc((string) ($shipment['factoryName'] ?? '-')) ?></div>
    <?php if ($isSpecial): ?>
    <div><b>Sumber</b><?= ui_esc((string) $source['label']) ?><?= $source['orderNo'] ? ' &middot; ' . ui_esc((string) $source['orderNo']) : '' ?></div>
    <div><b>Metode Pengiriman</b><?= $source['deliveryMethod'] === 'EXTERNAL_COURIER' ? 'Kurir Eksternal' : 'Driver Internal' ?></div>
    <?php endif; ?>
    <div><b><?= $isSpecial && $source['deliveryMethod'] === 'EXTERNAL_COURIER' ? 'Kurir' : 'Driver' ?></b><?= ui_esc((string) ($shipment['driverName'] ?? '-')) ?></div>
    <div><b>Group Pengiriman</b><?= ui_esc((string) ($shipment['shipmentGroup'] ?? '-')) ?></div>
  </div>

  <table class="print-table">
    <thead>
      <tr>
        <th style="width:22px;">No</th>
        <th>Nama Produk</th>
        <th>Divisi</th>
        <th class="num">Qty Kirim</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($shipment['items'] as $i => $it): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= ui_esc((string) $it['productName']) ?></td>
        <td><?= ui_esc((string) ($it['divisionName'] ?? '-')) ?></td>
        <td class="num"><?= ui_fmt_num((float) $it['qty']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3">Total <?= (int) $shipment['summary']['productCount'] ?> Produk</td>
        <td class="num"><?= ui_fmt_num((float) $shipment['summary']['totalQty']) ?> Pcs</td>
      </tr>
    </tfoot>
  </table>

  <?php if ($shipment['status'] !== 'void'): ?>
  <div class="print-receipt-qr">
    <div class="print-receipt-qr-code <?= ui_esc($qrSizeClass) ?>">
      <?= $shipment['doId'] !== null ? ui_do_receipt_qr_svg($pdo, (int) $shipment['doId']) : ui_shipment_receipt_qr_svg($pdo, (int) $shipment['shipmentId']) ?>
    </div>
    <div class="print-receipt-qr-label">
      Scan untuk Konfirmasi Penerimaan Barang<br>
      <?php if ($shipment['doId'] !== null): ?>
      <span class="print-receipt-qr-note">(QR ini sama untuk semua pengiriman pada DO ini — toko memilih pengiriman yang sesuai saat konfirmasi)</span>
      <?php else: ?>
      <span class="print-receipt-qr-note">(QR ini khusus untuk pengiriman SHP-<?= (int) $shipment['shipmentId'] ?> ini)</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="print-sign-grid">
    <div class="print-sign-box"><div class="print-sign-line">Disiapkan Oleh</div></div>
    <div class="print-sign-box"><div class="print-sign-line">Dikirim Oleh / Driver</div></div>
    <div class="print-sign-box"><div class="print-sign-line">Diterima Oleh</div></div>
  </div>

  <div class="print-footer-note">
    <span>Dicetak: <?= ui_esc(date('Y-m-d H:i')) ?> oleh <?= ui_esc($printedByName) ?></span>
    <span>Amor Factory System</span>
  </div>
</div>
<?php
}
