<?php

declare(strict_types=1);

/**
 * Shared "Production Task" (Task per Divisi) print document markup — the
 * ONE place this HTML is written, called once by print-production-task.php
 * (single division) and once per division in a loop by
 * print-production-task-bulk.php (task's own "Print Semua Divisi" ->
 * "one division per page"), so the two never drift apart. Mirrors
 * print-template.php's ui_render_do_print_document() convention exactly.
 *
 * Read-only: takes an already-built ProductionTaskService::
 * tasksForDivision() DTO and renders it. No business logic here — target/
 * actual/reject/sisa/status are all already computed by the service.
 *
 * PRINT IS A PHYSICAL BACKUP / WORKSHEET ONLY (task's own explicit rule)
 * — it is never a second source of truth; nothing here writes anything.
 */

/**
 * @param array $division a ProductionTaskService::tasksForDivision() DTO
 * @param string $printedByName the current session user's display name
 * @param int $pageNum 1-based page number within this print job
 * @param int $pageTotal total pages in this print job
 */
function ui_render_production_task_print_document(array $division, string $printedByName, int $pageNum, int $pageTotal): void
{
    $printedAt = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('d M Y, H:i');
    ?>
<div class="print-page">
  <div class="print-header">
    <div class="print-brand">
      <img class="print-brand-mark" src="/api/assets/img/amor-logo.png" alt="Amor" width="40" height="40">
      <div>
        <div class="print-brand-name">Amorcakes &amp; Bakery</div>
        <div class="print-doc-title">Production Task — <?= ui_esc($division['divisionName']) ?></div>
      </div>
    </div>
    <div class="print-meta-right">
      <div class="doc-no">Halaman <?= $pageNum ?> / <?= $pageTotal ?></div>
    </div>
  </div>

  <div class="print-meta-grid">
    <div><b>Tanggal</b><?= ui_esc($division['tanggal']) ?></div>
    <div><b>Pabrik</b><?= ui_esc($division['factoryName']) ?></div>
    <div><b>Divisi</b><?= ui_esc($division['divisionName']) ?></div>
    <div><b>Dicetak Pada</b><?= ui_esc($printedAt) ?></div>
    <div><b>Dicetak Oleh</b><?= ui_esc($printedByName) ?></div>
  </div>

  <table class="print-table">
    <thead><tr>
      <th style="width:24px;">No</th>
      <th>Produk / Task</th>
      <th>Sumber Demand</th>
      <th class="num">Target</th>
      <th class="num">Aktual</th>
      <th class="num">Reject Produksi</th>
      <th class="num">Sisa</th>
      <th>Catatan Khusus</th>
      <th style="width:70px;">Paraf</th>
    </tr></thead>
    <tbody>
    <?php if ($division['tasks'] === []): ?>
    <tr><td colspan="9" style="text-align:center;color:#888;">Tidak ada task produksi untuk divisi dan tanggal ini.</td></tr>
    <?php else: $no = 1; foreach ($division['tasks'] as $t): ?>
    <tr>
      <td><?= $no++ ?></td>
      <td><?= ui_esc($t['taskName']) ?></td>
      <td><?= ui_esc($t['sourceLabel']) ?></td>
      <td class="num"><?= ui_fmt_num($t['target']) ?></td>
      <td class="num"><?= ui_fmt_num($t['aktual']) ?></td>
      <td class="num"><?= ui_fmt_num($t['reject']) ?></td>
      <td class="num"><?= ui_fmt_num($t['sisa']) ?></td>
      <td><?= $t['catatanKhusus'] ? ui_esc($t['catatanKhusus']) : '-' ?></td>
      <td></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <?php if ($division['tasks'] !== []): ?>
    <tfoot><tr>
      <td colspan="3">Total</td>
      <td class="num"><?= ui_fmt_num($division['summary']['totalTarget']) ?></td>
      <td class="num"><?= ui_fmt_num($division['summary']['totalAktual']) ?></td>
      <td class="num"><?= ui_fmt_num($division['summary']['totalReject']) ?></td>
      <td class="num"><?= ui_fmt_num($division['summary']['sisaTarget']) ?></td>
      <td colspan="2"></td>
    </tr></tfoot>
    <?php endif; ?>
  </table>

  <div class="print-notes">
    <b>Catatan</b>
    <div class="print-notes-body">
      Task ini mencakup PO Reguler, Pesanan Khusus, Pesanan Non-Toko, dan Replacement Reject.<br>
      Produksi Actual &amp; Reject diisi melalui sistem.<br>
      Jika ada perubahan target, gunakan data terbaru dari sistem.
    </div>
  </div>

  <div class="print-footer-note">
    <span>Good Products · Better People · A Stronger Amor</span>
    <span>Dokumen ini adalah cadangan fisik (worksheet) — bukan sumber data resmi. Sistem tetap menjadi acuan utama.</span>
  </div>
</div>
    <?php
}
