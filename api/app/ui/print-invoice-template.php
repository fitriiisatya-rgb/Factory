<?php

declare(strict_types=1);

/**
 * Shared Invoice print document markup — the ONE place this HTML is
 * written (mirrors print-template.php's DO pattern). Phase 6 Invoice
 * backend/business logic does not exist yet, so this function only
 * RENDERS an already-built invoice DTO; it never computes totals, never
 * queries the DB, and never writes anything. The DTO shape is the
 * conceptual contract from the task spec:
 *
 *   invoiceNumber, invoiceDate,
 *   customer: {storeName, address, npwp},
 *   reference: {doNumber, shipmentNumber, shipDate},   // all optional
 *   items: [{productName, qty, unitPrice, subtotal}, ...],
 *   summary: {subtotal, discount, total, ppn?},         // ppn optional
 *   notes: ?string,                                      // optional, absent by default
 *   metadata: {printedAt, generatedBy}
 *
 * When Phase 6 is built, a real InvoiceService::getInvoice() DTO with this
 * same shape can be passed straight into this function — nothing here is
 * mock-specific (the mock-only pieces live in fixtures/invoice-mock.php).
 */

function ui_fmt_rupiah(float $n): string
{
    return 'Rp ' . number_format($n, 0, ',', '.');
}

function ui_render_invoice_print_document(array $invoice, int $pageNum = 1, int $pageTotal = 1): void
{
    $customer = $invoice['customer'] ?? [];
    $reference = $invoice['reference'] ?? [];
    $summary = $invoice['summary'] ?? [];
    $metadata = $invoice['metadata'] ?? [];
    $notes = isset($invoice['notes']) ? trim((string) $invoice['notes']) : '';

    $hasReference = trim((string) ($reference['doNumber'] ?? '')) !== ''
        || trim((string) ($reference['shipmentNumber'] ?? '')) !== ''
        || trim((string) ($reference['shipDate'] ?? '')) !== '';
    ?>
<div class="print-page inv-page">
  <div class="print-header inv-header">
    <div class="print-brand">
      <img class="print-brand-mark" src="/api/app/ui/assets/img/amor-logo.png" alt="Amor" width="44" height="44">
      <div>
        <div class="print-brand-name">Amor Cakes &amp; Bakery</div>
        <?php if (trim((string) ($invoice['companyAddress'] ?? '')) !== ''): ?>
        <div class="inv-company-address"><?= ui_esc((string) $invoice['companyAddress']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="inv-title-block">
      <div class="inv-title">INVOICE</div>
      <div class="inv-title-meta">No. <?= ui_esc((string) $invoice['invoiceNumber']) ?></div>
      <div class="inv-title-meta">Tanggal <?= ui_esc((string) $invoice['invoiceDate']) ?></div>
    </div>
  </div>

  <div class="inv-info-grid">
    <div class="inv-info-box">
      <div class="inv-info-title">Informasi Pelanggan</div>
      <div class="inv-info-row"><b>Toko</b><span><?= ui_esc((string) ($customer['storeName'] ?? '-')) ?></span></div>
      <div class="inv-info-row"><b>Alamat</b><span><?= ui_esc(trim((string) ($customer['address'] ?? '')) !== '' ? (string) $customer['address'] : '-') ?></span></div>
      <?php if (trim((string) ($customer['npwp'] ?? '')) !== ''): ?>
      <div class="inv-info-row"><b>NPWP</b><span><?= ui_esc((string) $customer['npwp']) ?></span></div>
      <?php endif; ?>
    </div>
    <div class="inv-info-box">
      <div class="inv-info-title">Informasi Invoice</div>
      <div class="inv-info-row"><b>No. Invoice</b><span><?= ui_esc((string) $invoice['invoiceNumber']) ?></span></div>
      <div class="inv-info-row"><b>Tanggal Invoice</b><span><?= ui_esc((string) $invoice['invoiceDate']) ?></span></div>
      <?php if ($hasReference): ?>
        <?php if (trim((string) ($reference['doNumber'] ?? '')) !== ''): ?>
        <div class="inv-info-row"><b>No. DO</b><span><?= ui_esc((string) $reference['doNumber']) ?></span></div>
        <?php endif; ?>
        <?php if (trim((string) ($reference['shipmentNumber'] ?? '')) !== ''): ?>
        <div class="inv-info-row"><b>No. Pengiriman</b><span><?= ui_esc((string) $reference['shipmentNumber']) ?></span></div>
        <?php endif; ?>
        <?php if (trim((string) ($reference['shipDate'] ?? '')) !== ''): ?>
        <div class="inv-info-row"><b>Tanggal Kirim</b><span><?= ui_esc((string) $reference['shipDate']) ?></span></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <table class="print-table inv-table">
    <thead>
      <tr>
        <th style="width:22px;">No</th>
        <th>Nama Produk</th>
        <th class="num">Qty</th>
        <th class="num">Harga Satuan</th>
        <th class="num">Subtotal</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach (($invoice['items'] ?? []) as $i => $it): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= ui_esc((string) $it['productName']) ?></td>
        <td class="num"><?= ui_fmt_num((float) $it['qty']) ?></td>
        <td class="num"><?= ui_fmt_rupiah((float) $it['unitPrice']) ?></td>
        <td class="num"><?= ui_fmt_rupiah((float) $it['subtotal']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="inv-summary-wrap">
    <div class="inv-summary-box">
      <div class="inv-summary-row"><span>Subtotal</span><span><?= ui_fmt_rupiah((float) ($summary['subtotal'] ?? 0)) ?></span></div>
      <div class="inv-summary-row"><span>Diskon</span><span><?= ui_fmt_rupiah((float) ($summary['discount'] ?? 0)) ?></span></div>
      <?php if (isset($summary['ppn'])): ?>
      <div class="inv-summary-row"><span>PPN</span><span><?= ui_fmt_rupiah((float) $summary['ppn']) ?></span></div>
      <?php endif; ?>
      <div class="inv-summary-row inv-summary-total"><span>Total Tagihan</span><span><?= ui_fmt_rupiah((float) ($summary['total'] ?? 0)) ?></span></div>
    </div>
  </div>

  <?php if ($notes !== ''): ?>
  <div class="print-notes inv-notes">
    <b>Catatan</b>
    <div class="print-notes-body"><?= nl2br(ui_esc($notes)) ?></div>
  </div>
  <?php endif; ?>

  <div class="inv-thanks">
    <div class="inv-thanks-title">Terima Kasih</div>
    <div class="inv-thanks-sub">Atas kepercayaan Anda</div>
  </div>

  <div class="print-sign-grid inv-sign-grid">
    <div class="print-sign-box"><div class="print-sign-line">Dibuat Oleh</div></div>
    <div class="print-sign-box"><div class="print-sign-line">Diperiksa Oleh</div></div>
    <div class="print-sign-box"><div class="print-sign-line">Disetujui Oleh</div></div>
  </div>

  <div class="print-footer-note inv-footer">
    <span>Amor Cakes &amp; Bakery</span>
    <span>Halaman <?= $pageNum ?> dari <?= $pageTotal ?></span>
    <span>Generated by Amor Factory System<?= trim((string) ($metadata['printedAt'] ?? '')) !== '' ? ' &middot; ' . ui_esc((string) $metadata['printedAt']) : '' ?></span>
  </div>
</div>
<?php
}
