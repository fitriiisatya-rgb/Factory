<?php

declare(strict_types=1);

use Amor\Api\Fg\FgService;

$service = new FgService($pdo);

$existing = $pdo->prepare('SELECT fg_batch_id FROM fg_batch WHERE tanggal = ? AND factory_id = ?');
$existing->execute([$uiTanggal, $uiFactoryId]);
$existingId = $existing->fetchColumn();

$batchView = null;
$targetView = null;
$viewError = null;
try {
    if ($existingId !== false) {
        $batchView = $service->getBatch((int) $existingId);
    } else {
        $targetView = $service->loadTarget($uiTanggal, $uiFactoryId, null);
    }
} catch (\Throwable $e) {
    $viewError = $e->getMessage();
}

// MOBILE-FIRST REWORK — "FG Verifikasi" (per product, default) and "FG
// Packing" (per Toko, default) are now separate steps/views (task's own
// explicit "Split FG into two clear operational stages" requirement),
// switched via a plain query param (a real page navigation, not a hidden
// client-side state machine — simplest, most robust, and each step is
// independently bookmarkable/shareable). Packing operates on the SAME
// canonical fg_item rows Verifikasi's own Breakdown Toko already writes
// (GET/PATCH /api/fg/{id}/items/{productId}/stores — completely
// unchanged backend, see FgService::batchProductStores()'s own docblock)
// — there is still only ONE stored number per store row, so splitting the
// UI into two steps can never double-count or duplicate data.
$fgStep = (($_GET['step'] ?? 'verifikasi') === 'packing') ? 'packing' : 'verifikasi';

$selesaiDipacking = 0;
if ($batchView !== null) {
    foreach ($batchView['items'] as $it) {
        if ($it['packingStatusCode'] === 'selesai_dipacking') {
            $selesaiDipacking++;
        }
    }
}

function fgStepUrl(string $step, string $tanggal, int $factoryId): string
{
    return '/api/_ui-preview/?page=fg-packing&tanggal=' . urlencode($tanggal) . '&factoryId=' . $factoryId . '&step=' . $step;
}
?>
<?= ui_fg_tabs('fg-packing', $uiTanggal, $uiFactoryId) ?>
<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="fg-packing">
    <input type="hidden" name="step" value="<?= ui_esc($fgStep) ?>">
    <div class="field"><label>Tanggal</label><input type="date" name="tanggal" value="<?= ui_esc($uiTanggal) ?>"></div>
    <div class="field"><label>Pabrik</label>
      <select name="factoryId">
        <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['factory_id'] ?>" <?= $uiFactoryId === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>
</div>

<?php if ($viewError !== null): ?>
<div class="alert alert-danger"><?= ui_esc($viewError) ?></div>
<?php endif; ?>

<?php
$rejectTotal = 0.0;
$hilangTotal = 0.0;
foreach ($batchView !== null ? $batchView['items'] : [] as $it) {
    $rejectTotal += $it['reject'] ?? 0.0;
    $hilangTotal += $it['hilang'] ?? 0.0;
}
?>
<?php if ($batchView !== null): ?>
<div class="kpi-grid">
  <?= ui_kpi_card(['label' => 'Hasil Produksi', 'value' => ui_fmt_num($batchView['summary']['productionActualTotal']), 'icon' => 'factory', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'FG Terverifikasi', 'value' => ui_fmt_num($batchView['summary']['fgVerifiedTotal']), 'icon' => 'box', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'Sudah Dipacking', 'value' => ui_fmt_num($batchView['summary']['packedTotal']), 'icon' => 'box', 'color' => 'success']) ?>
  <?= ui_kpi_card(['label' => 'Reject FG', 'value' => ui_fmt_num($rejectTotal), 'icon' => 'file', 'color' => $rejectTotal > 0 ? 'danger' : 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'Hilang', 'value' => ui_fmt_num($hilangTotal), 'icon' => 'file', 'color' => $hilangTotal > 0 ? 'danger' : 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'Selisih Produksi ke FG', 'value' => ui_fmt_num($batchView['summary']['varianceTotal']), 'icon' => 'file', 'color' => $batchView['summary']['varianceTotal'] < 0 ? 'warning' : 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'Belum Diverifikasi', 'value' => (string) $batchView['summary']['jumlahBelumDiverifikasi'], 'icon' => 'file', 'color' => 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'Sebagian Terverifikasi', 'value' => (string) $batchView['summary']['jumlahSebagianTerverifikasi'], 'icon' => 'file', 'color' => 'warning']) ?>
  <?= ui_kpi_card(['label' => 'Sesuai Produksi', 'value' => (string) $batchView['summary']['jumlahSesuaiProduksi'], 'icon' => 'file', 'color' => 'success']) ?>
  <?= ui_kpi_card(['label' => 'Selesai Dipacking', 'value' => (string) $selesaiDipacking, 'icon' => 'box', 'color' => 'success']) ?>
</div>

<div class="filter-bar">
  <div class="btn-group">
    <a class="btn btn-sm <?= $fgStep === 'verifikasi' ? 'btn-primary' : 'btn-secondary' ?>" href="<?= ui_esc(fgStepUrl('verifikasi', $uiTanggal, $uiFactoryId)) ?>">FG Verifikasi</a>
    <a class="btn btn-sm <?= $fgStep === 'packing' ? 'btn-primary' : 'btn-secondary' ?>" href="<?= ui_esc(fgStepUrl('packing', $uiTanggal, $uiFactoryId)) ?>">FG Packing</a>
  </div>
</div>

<div class="card section">
  <div class="card-head">
    <h2 class="card-title">Draft FG #<?= (int) $batchView['fgBatchId'] ?></h2>
    <?= ui_badge(ui_doc_status_label($batchView['status'])) ?>
  </div>
  <?php $editable = in_array($batchView['status'], ['draft', 'reopened'], true); ?>

  <?php if ($fgStep === 'verifikasi'): ?>
  <?php if ($batchView['sourceInconsistency']): ?>
  <div class="alert alert-warning">
    <strong>Ketidaksesuaian Sumber Produksi</strong> — salah satu Produksi sumber sudah berubah versi/dibuka kembali sejak FG ini dibuat/disegarkan. Data FG yang sudah diisi (FG Terverifikasi/Packed) TIDAK dihapus atau diubah otomatis.
    <div class="table-scroll" style="margin-top:var(--space-2);"><table class="data-table">
      <thead><tr><th>Production Run</th><th>Versi Tercatat</th><th>Versi Sekarang</th><th>Status Sekarang</th></tr></thead>
      <tbody>
      <?php foreach ($batchView['sourceInconsistencyDetails'] as $s): ?>
      <tr><td>#<?= (int) $s['productionRunId'] ?></td><td><?= (int) $s['storedVersion'] ?></td><td><?= (int) $s['currentVersion'] ?></td><td><?= ui_esc($s['currentStatus']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($editable): ?>
    <button type="button" class="btn btn-primary" style="margin-top:var(--space-2);" id="btn-refresh-fg-source">Refresh Produksi Terbaru</button>
    <?php else: ?>
    <p style="margin-top:var(--space-2);color:var(--text-muted);">Dokumen ini sudah <strong>submitted</strong> — klik "Buka Kembali / Reopen" dulu sebelum bisa Refresh Produksi Terbaru.</p>
    <?php endif; ?>
  </div>
  <?php elseif ($editable): ?>
  <button type="button" class="btn btn-secondary" style="margin-bottom:var(--space-3);" id="btn-refresh-fg-source">Refresh Produksi Terbaru</button>
  <?php endif; ?>

  <?php if ($batchView['summary']['jumlahMelebihiProduksi'] > 0): ?>
  <div class="alert alert-danger"><strong>Diblokir untuk Submit</strong> — <?= $batchView['summary']['jumlahMelebihiProduksi'] ?> produk punya FG Terverifikasi melebihi Production Actual terbaru (lihat status "Melebihi Produksi"). Turunkan FG Terverifikasi produk tersebut dulu sebelum submit — sistem tidak pernah menurunkannya secara otomatis.</div>
  <?php endif; ?>

  <div style="display:flex;gap:var(--space-2);align-items:center;margin-bottom:var(--space-3);">
    <span style="font-size:var(--text-sm);color:var(--text-muted);">Tampilan:</span>
    <div class="btn-group">
      <button type="button" class="btn btn-sm btn-primary" id="fg-mode-produk">Per Produk</button>
      <button type="button" class="btn btn-sm btn-secondary" id="fg-mode-toko">Breakdown Toko</button>
    </div>
  </div>

  <form id="fg-form" data-batch-id="<?= (int) $batchView['fgBatchId'] ?>" data-expected-version="<?= (int) $batchView['version'] ?>" data-tanggal="<?= ui_esc($uiTanggal) ?>" data-factory-id="<?= $uiFactoryId ?>">
  <div class="fg-card-list fg-card-list-2col" id="fg-perproduk-wrap">
    <?php foreach ($batchView['items'] as $it): ?>
    <?php
      $isBreakdown = ($it['mode'] ?? 'perProduk') === 'breakdownToko';
      // Per Produk cells become DERIVED/read-only the moment a product is
      // exploded into store rows (Option A — see FgService::patchDraft()'s
      // own docblock) — "no double counting" is satisfied by construction
      // only if there is never a second place to type a number for the
      // same product at the same time.
      $rowEditable = $editable && !$isBreakdown;
      $verifiedIsSesuai = $rowEditable && abs($it['fgVerified'] - $it['productionActualSnapshot']) < 0.01 && $it['fgVerified'] > 0;
    ?>
    <div class="fg-card" data-product-id="<?= (int) $it['productId'] ?>">
      <div class="fg-card-title"><?= ui_esc($it['productName']) ?></div>
      <div class="fg-card-sub">
        Target FG (Hasil Produksi): <b><?= ui_fmt_num($it['productionActualSnapshot']) ?></b>
        · Available: <?= ui_fmt_num($it['available']) ?>
        · <?= ui_badge($isBreakdown ? 'Breakdown Toko (' . (int) $it['storeCount'] . ' Toko)' : 'Per Produk') ?>
      </div>

      <div class="fg-card-row">
        <span class="fg-card-row-label">Verifikasi</span>
        <?php if ($rowEditable): ?>
        <div class="btn-group fg-verified-sesuai-group" data-product-id="<?= (int) $it['productId'] ?>" data-target="<?= ui_esc((string) $it['productionActualSnapshot']) ?>" data-sesuai="<?= $verifiedIsSesuai ? '1' : '0' ?>">
          <button type="button" class="btn btn-sm <?= $verifiedIsSesuai ? 'btn-primary' : 'btn-secondary' ?>" data-value="sesuai">Sesuai</button>
          <button type="button" class="btn btn-sm <?= !$verifiedIsSesuai ? 'btn-danger' : 'btn-secondary' ?>" data-value="tidak_sesuai">Tidak Sesuai</button>
        </div>
        <?php else: ?><span style="color:var(--text-faint);">-</span><?php endif; ?>
      </div>
      <div class="fg-card-row">
        <span class="fg-card-row-label">FG Terverifikasi</span>
        <?php if ($rowEditable): ?><input type="number" step="0.01" min="0" class="fg-card-input" data-product-id="<?= (int) $it['productId'] ?>" data-field="fgVerified" value="<?= ui_fmt_num($it['fgVerified']) ?>" <?= $verifiedIsSesuai ? 'disabled' : '' ?>>
        <?php else: ?><b><?= ui_fmt_num($it['fgVerified']) ?></b><?php endif; ?>
      </div>
      <div class="fg-card-row"><span class="fg-card-row-label">Selisih</span><span><?= ui_fmt_num($it['variance']) ?></span></div>
      <div class="fg-card-row">
        <span class="fg-card-row-label">Reject</span>
        <?php if ($rowEditable): ?><input type="number" step="0.01" min="0" class="fg-card-input" data-product-id="<?= (int) $it['productId'] ?>" data-field="reject" value="<?= ui_fmt_num($it['reject'] ?? 0) ?>">
        <?php else: ?><?= ui_fmt_num($it['reject'] ?? 0) ?><?php endif; ?>
      </div>
      <div class="fg-card-row">
        <span class="fg-card-row-label">Hilang</span>
        <?php if ($rowEditable): ?><input type="number" step="0.01" min="0" class="fg-card-input" data-product-id="<?= (int) $it['productId'] ?>" data-field="hilang" value="<?= ui_fmt_num($it['hilang'] ?? 0) ?>">
        <?php else: ?><?= ui_fmt_num($it['hilang'] ?? 0) ?><?php endif; ?>
      </div>
      <div class="fg-card-row">
        <span class="fg-card-row-label">Catatan</span>
        <?php if ($rowEditable): ?><input type="text" class="fg-card-input" data-product-id="<?= (int) $it['productId'] ?>" data-field="notes" value="<?= ui_esc((string) ($it['notes'] ?? '')) ?>">
        <?php else: ?><?= ui_esc((string) ($it['notes'] ?? '')) ?><?php endif; ?>
      </div>
      <div class="fg-card-row"><span class="fg-card-row-label">FG Status</span><?= ui_badge($it['fgStatusLabel']) ?></div>
      <!-- "packed" is a FG PACKING concern now (see the separate "FG Packing" step/tab)
           — hidden here so Verifikasi's own PATCH never needs to show it, but still
           ECHOED BACK unchanged (patchDraft()'s items[] line REPLACES packed_qty,
           defaulting to 0 if absent — see FgService::patchDraft()'s own docblock —
           so this hidden field is what stops a Verifikasi-only save from wiping out
           already-packed work). -->
      <input type="hidden" data-product-id="<?= (int) $it['productId'] ?>" data-field="packed" value="<?= ui_fmt_num($it['packed']) ?>">
      <div class="fg-card-actions">
        <button type="button" class="btn btn-secondary btn-sm fg-breakdown-btn" data-product-id="<?= (int) $it['productId'] ?>" data-product-name="<?= ui_esc($it['productName']) ?>">Breakdown Toko</button>
        <?php if ($editable && $isBreakdown): ?>
        <button type="button" class="btn btn-warning btn-sm fg-collapse-btn" data-product-id="<?= (int) $it['productId'] ?>" data-product-name="<?= ui_esc($it['productName']) ?>">Kembali ke Per Produk</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div id="fg-breakdown-panel" class="fg-card-list" style="display:none;margin-top:var(--space-3);"></div>
  <?php if ($editable): ?>
  <label style="display:flex;align-items:center;gap:8px;margin-top:var(--space-3);font-size:var(--text-sm);color:var(--text-muted);">
    <input type="checkbox" id="fg-refresh-source"> Segarkan sumber dari Produksi SUBMITTED terbaru (tidak mengubah angka yang sudah diisi)
  </label>
  <div class="btn-group" style="margin-top:var(--space-3);">
    <button type="button" class="btn btn-primary" id="btn-save-fg">Simpan Draft</button>
    <button type="button" class="btn btn-success" id="btn-submit-fg">Submit FG</button>
  </div>
  <?php elseif ($batchView['status'] === 'submitted'): ?>
  <div class="btn-group" style="margin-top:var(--space-3);">
    <button type="button" class="btn btn-warning" id="btn-reopen-fg">Buka Kembali / Reopen</button>
  </div>
  <?php endif; ?>
  </form>

  <?php else /* $fgStep === 'packing' */: ?>
  <div id="fg-packing-header" style="margin-bottom:var(--space-3);">
    <div style="color:var(--text-muted);font-size:var(--text-sm);">Memuat status packing per toko...</div>
  </div>
  <div class="fg-store-chip-row" id="fg-store-chip-row"></div>
  <div id="fg-packing-detail" style="margin-top:var(--space-3);"></div>
  <?php if ($editable): ?>
  <div class="btn-group" style="margin-top:var(--space-4);">
    <button type="button" class="btn btn-success" id="btn-submit-fg-packing">Submit FG (Semua Toko)</button>
  </div>
  <?php elseif ($batchView['status'] === 'submitted'): ?>
  <div class="btn-group" style="margin-top:var(--space-4);">
    <button type="button" class="btn btn-warning" id="btn-reopen-fg-packing">Buka Kembali / Reopen</button>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function () {
  var batchId = <?= (int) $batchView['fgBatchId'] ?>;
  var version = <?= (int) $batchView['version'] ?>;
  var editableHere = <?= $editable ? 'true' : 'false' ?>;
  var fgStep = <?= json_encode($fgStep) ?>;
  // Server-filtered list (target > 0 only — see FgService::buildBatchDto()'s
  // isVisibleItem() gate) — the single source of truth both steps fetch
  // per-store data for.
  var visibleProducts = <?= json_encode(array_map(static fn ($it) => ['productId' => $it['productId'], 'productName' => $it['productName']], $batchView['items'])) ?>;

  // Sesuai/Tidak Sesuai — same auto-fill/lock convenience and same "server
  // always re-derives, never trusts a disabled input" rule everywhere it's
  // used (FG Verifikasi's own fields, Breakdown Toko's Verified, and FG
  // Packing's Actual Packing — see FgService::patchDraft()'s own
  // sesuaiVerified/sesuaiPacking check).
  function wireSesuaiGroup(group, valueInput, targetProvider) {
    group.querySelectorAll('button').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var isSesuai = btn.getAttribute('data-value') === 'sesuai';
        group.querySelectorAll('button').forEach(function (b) {
          b.className = 'btn btn-sm ' + (b === btn ? (isSesuai ? 'btn-primary' : 'btn-danger') : 'btn-secondary');
        });
        group.setAttribute('data-sesuai', isSesuai ? '1' : '0');
        if (isSesuai) {
          valueInput.value = targetProvider();
          valueInput.disabled = true;
        } else {
          valueInput.disabled = false;
        }
      });
    });
  }

  async function fetchStoreData(pid) {
    return Amor.apiFetch('/api/fg/' + batchId + '/items/' + pid + '/stores');
  }
  async function fetchAllStoreRows() {
    return Promise.all(visibleProducts.map(function (p) {
      return fetchStoreData(p.productId).then(function (data) { return { productId: p.productId, productName: p.productName, data: data }; });
    }));
  }

  if (fgStep === 'verifikasi') {
    document.querySelectorAll('.fg-verified-sesuai-group').forEach(function (group) {
      var pid = group.getAttribute('data-product-id');
      var input = document.querySelector('#fg-form [data-field="fgVerified"][data-product-id="' + pid + '"]');
      if (input) wireSesuaiGroup(group, input, function () { return group.getAttribute('data-target'); });
    });

    var modeProduk = document.getElementById('fg-mode-produk');
    var modeToko = document.getElementById('fg-mode-toko');
    var breakdownPanel = document.getElementById('fg-breakdown-panel');
    var perProdukWrap = document.getElementById('fg-perproduk-wrap');

    // Builds ONE product's store-breakdown card block — Verifikasi's
    // Breakdown Toko is Verified/Reject/Hilang/Keterangan ONLY (Packing
    // now lives in its own step/tab, see below); "packed" is echoed back
    // unchanged via a hidden field for the same reason as the Per Produk
    // card above.
    function buildVerifikasiStoreBlock(pid, pname, data) {
      var block = document.createElement('div');
      block.className = 'fg-card';
      block.setAttribute('data-product-id', pid);
      var head = '<div class="fg-card-title">' + pname + '</div>'
        + (data.exploded ? '' : '<div class="alert alert-warning" style="margin:var(--space-2) 0;">Produk ini masih mode Per Produk — mengisi baris di bawah dan Simpan akan memecahnya ke per-Toko.</div>');
      var rowsHtml = (data.stores || []).map(function (s) {
        var verifiedSesuai = editableHere && Math.abs(s.fgVerified - s.target) < 0.01 && s.fgVerified > 0;
        var reviewBadge = s.needsReview ? ' <span class="badge badge-danger">Perlu Review Ulang</span>' : '';
        var body = '<div class="fg-card-sub" style="margin-top:var(--space-3);margin-bottom:0;"><b>' + s.storeName + '</b>' + reviewBadge + ' &middot; Target Toko: ' + s.target.toLocaleString('id-ID') + '</div>';
        if (!editableHere) {
          return body
            + '<div class="fg-card-row"><span class="fg-card-row-label">Actual Verified</span><span>' + s.fgVerified.toLocaleString('id-ID') + '</span></div>'
            + '<div class="fg-card-row"><span class="fg-card-row-label">Reject</span><span>' + s.reject.toLocaleString('id-ID') + '</span></div>'
            + '<div class="fg-card-row"><span class="fg-card-row-label">Hilang</span><span>' + s.hilang.toLocaleString('id-ID') + '</span></div>'
            + '<div class="fg-card-row"><span class="fg-card-row-label">Keterangan</span><span>' + (s.notes || '') + '</span></div>'
            + '<div class="fg-card-row"><span class="fg-card-row-label">Status</span><span>' + (s.status || '') + '</span></div>';
        }
        return body
          + '<div class="fg-card-row" data-store-id="' + s.storeId + '"><span class="fg-card-row-label">Verified Result</span>'
          +   '<div class="btn-group bt-verified-sesuai" data-target="' + s.target + '" data-sesuai="' + (verifiedSesuai ? '1' : '0') + '">'
          +     '<button type="button" class="btn btn-sm ' + (verifiedSesuai ? 'btn-primary' : 'btn-secondary') + '" data-value="sesuai">Sesuai</button>'
          +     '<button type="button" class="btn btn-sm ' + (!verifiedSesuai ? 'btn-danger' : 'btn-secondary') + '" data-value="tidak_sesuai">Tidak Sesuai</button>'
          +   '</div></div>'
          + '<div class="fg-card-row" data-store-id="' + s.storeId + '"><span class="fg-card-row-label">Actual Verified</span><input type="number" step="0.01" min="0" class="fg-card-input" data-bt-field="fgVerified" value="' + s.fgVerified + '" ' + (verifiedSesuai ? 'disabled' : '') + '></div>'
          + '<div class="fg-card-row" data-store-id="' + s.storeId + '"><span class="fg-card-row-label">Reject</span><input type="number" step="0.01" min="0" class="fg-card-input" data-bt-field="reject" value="' + s.reject + '"></div>'
          + '<div class="fg-card-row" data-store-id="' + s.storeId + '"><span class="fg-card-row-label">Hilang</span><input type="number" step="0.01" min="0" class="fg-card-input" data-bt-field="hilang" value="' + s.hilang + '"></div>'
          + '<div class="fg-card-row" data-store-id="' + s.storeId + '"><span class="fg-card-row-label">Keterangan</span><input type="text" class="fg-card-input" data-bt-field="notes" value="' + (s.notes || '').replace(/"/g, '&quot;') + '"></div>'
          + '<input type="hidden" data-store-id="' + s.storeId + '" data-bt-field="packed" value="' + s.packed + '">'
          + '<div class="fg-card-row" data-store-id="' + s.storeId + '"><span class="fg-card-row-label">Status</span><span>' + (s.status || '') + '</span></div>';
      }).join('');
      var actions = editableHere ? '<div class="fg-card-actions"><button type="button" class="btn btn-primary btn-sm bt-save">Simpan Breakdown Toko</button></div>' : '';
      block.innerHTML = head
        + (data.stores.length === 0 ? '<p style="color:var(--text-muted);">Tidak ada Toko dengan target &gt; 0 untuk produk ini.</p>' : rowsHtml)
        + (data.stores.length === 0 ? '' : '<div class="fg-card-row"><span class="fg-card-row-label"><b>Total</b></span><span>Target ' + data.totalTarget.toLocaleString('id-ID') + ' &middot; Verified ' + data.totalVerified.toLocaleString('id-ID') + '</span></div>')
        + actions;

      block.querySelectorAll('.bt-verified-sesuai').forEach(function (group) {
        var storeId = group.closest('[data-store-id]').getAttribute('data-store-id');
        var input = block.querySelector('[data-store-id="' + storeId + '"] [data-bt-field="fgVerified"]');
        wireSesuaiGroup(group, input, function () { return group.getAttribute('data-target'); });
      });

      var saveBtn = block.querySelector('.bt-save');
      if (saveBtn) saveBtn.addEventListener('click', async function () {
        saveBtn.disabled = true;
        var rowsOut = [];
        var storeIds = [];
        block.querySelectorAll('[data-store-id]').forEach(function (el) {
          var sid = el.getAttribute('data-store-id');
          if (storeIds.indexOf(sid) === -1) storeIds.push(sid);
        });
        // A field lives either ON the matched [data-store-id] element itself
        // (the standalone hidden "packed" echo-back input) or nested INSIDE
        // one of the several [data-store-id] row divs for this store (every
        // visible input) — this selector covers both without guessing which.
        storeIds.forEach(function (sid) {
          var field = function (name) {
            return block.querySelector('[data-store-id="' + sid + '"][data-bt-field="' + name + '"], [data-store-id="' + sid + '"] [data-bt-field="' + name + '"]');
          };
          var vGroup = block.querySelector('[data-store-id="' + sid + '"] .bt-verified-sesuai');
          rowsOut.push({
            storeId: parseInt(sid, 10),
            fgVerified: parseFloat((field('fgVerified') || { value: '0' }).value || '0'),
            packed: parseFloat((field('packed') || { value: '0' }).value || '0'),
            reject: parseFloat((field('reject') || { value: '0' }).value || '0'),
            hilang: parseFloat((field('hilang') || { value: '0' }).value || '0'),
            notes: (field('notes') || { value: '' }).value,
            sesuaiVerified: vGroup ? vGroup.getAttribute('data-sesuai') === '1' : false,
          });
        });
        try {
          var saved = await Amor.apiFetch('/api/fg/' + batchId, { method: 'PATCH', body: { expectedVersion: version, storeItems: [{ productId: parseInt(pid, 10), rows: rowsOut }] } });
          version = saved.version;
          Amor.toast('Breakdown Toko disimpan.', 'success');
          setTimeout(function () { location.reload(); }, 600);
        } catch (e) { Amor.toast(e.message, 'danger'); saveBtn.disabled = false; }
      });
      return block;
    }

    async function enterBreakdownMode(focusProductId) {
      if (!breakdownPanel) return;
      modeToko.className = 'btn btn-sm btn-primary'; modeProduk.className = 'btn btn-sm btn-secondary';
      if (perProdukWrap) perProdukWrap.style.display = 'none';
      breakdownPanel.style.display = '';
      breakdownPanel.innerHTML = '<div style="color:var(--text-muted);">Memuat Breakdown Toko...</div>';
      if (visibleProducts.length === 0) {
        breakdownPanel.innerHTML = '<p style="color:var(--text-muted);">Tidak ada produk dengan target &gt; 0.</p>';
        return;
      }
      try {
        var results = await fetchAllStoreRows();
        breakdownPanel.innerHTML = '';
        results.forEach(function (r) {
          breakdownPanel.appendChild(buildVerifikasiStoreBlock(r.productId, r.productName, r.data));
        });
        if (focusProductId) {
          var target = breakdownPanel.querySelector('[data-product-id="' + focusProductId + '"]');
          if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      } catch (e) {
        breakdownPanel.innerHTML = '<div class="alert alert-danger">' + e.message + '</div>';
      }
    }
    function exitBreakdownMode() {
      modeProduk.className = 'btn btn-sm btn-primary'; modeToko.className = 'btn btn-sm btn-secondary';
      if (perProdukWrap) perProdukWrap.style.display = '';
      if (breakdownPanel) { breakdownPanel.style.display = 'none'; breakdownPanel.innerHTML = ''; }
    }
    if (modeProduk && modeToko) {
      modeProduk.addEventListener('click', exitBreakdownMode);
      modeToko.addEventListener('click', function () { enterBreakdownMode(null); });
    }
    document.querySelectorAll('.fg-breakdown-btn').forEach(function (btn) {
      btn.addEventListener('click', function () { enterBreakdownMode(btn.getAttribute('data-product-id')); });
    });
    document.querySelectorAll('.fg-collapse-btn').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        var pid = btn.getAttribute('data-product-id');
        var pname = btn.getAttribute('data-product-name');
        var ok = await Amor.confirmModal({ title: 'Kembali ke Per Produk?', body: 'Baris per-Toko untuk ' + pname + ' akan digabung kembali menjadi satu baris Per Produk (total tidak berubah). Detail per-Toko akan hilang.', confirmLabel: 'Ya, Gabungkan' });
        if (!ok) return;
        btn.disabled = true;
        try {
          var data = await Amor.apiFetch('/api/fg/' + batchId, { method: 'PATCH', body: { expectedVersion: version, collapseProductIds: [parseInt(pid, 10)] } });
          version = data.version;
          Amor.toast('Digabung kembali ke Per Produk.', 'success');
          setTimeout(function () { location.reload(); }, 600);
        } catch (e) { Amor.toast(e.message, 'danger'); btn.disabled = false; }
      });
    });

    function collectItems() {
      var items = [];
      document.querySelectorAll('#fg-form [data-field="fgVerified"]').forEach(function (input) {
        var pid = input.getAttribute('data-product-id');
        var packedInput = document.querySelector('#fg-form [data-field="packed"][data-product-id="' + pid + '"]');
        var rejectInput = document.querySelector('#fg-form [data-field="reject"][data-product-id="' + pid + '"]');
        var hilangInput = document.querySelector('#fg-form [data-field="hilang"][data-product-id="' + pid + '"]');
        var notesInput = document.querySelector('#fg-form [data-field="notes"][data-product-id="' + pid + '"]');
        var verifiedGroup = document.querySelector('.fg-verified-sesuai-group[data-product-id="' + pid + '"]');
        items.push({
          productId: parseInt(pid, 10),
          fgVerified: parseFloat(input.value || '0'),
          packed: packedInput ? parseFloat(packedInput.value || '0') : 0,
          reject: rejectInput ? parseFloat(rejectInput.value || '0') : 0,
          hilang: hilangInput ? parseFloat(hilangInput.value || '0') : 0,
          notes: notesInput ? notesInput.value : '',
          sesuaiVerified: verifiedGroup ? verifiedGroup.getAttribute('data-sesuai') === '1' : false,
        });
      });
      return items;
    }
    var form = document.getElementById('fg-form');
    var refreshBox = document.getElementById('fg-refresh-source');

    var saveBtn = document.getElementById('btn-save-fg');
    if (saveBtn) saveBtn.addEventListener('click', async function () {
      saveBtn.disabled = true;
      try {
        var data = await Amor.apiFetch('/api/fg/' + batchId, { method: 'PATCH', body: { expectedVersion: version, items: collectItems(), refreshSource: refreshBox && refreshBox.checked } });
        Amor.toast('Draft FG disimpan.', 'success');
        version = data.version;
        form.setAttribute('data-expected-version', version);
      } catch (e) { Amor.toast(e.message, 'danger'); }
      saveBtn.disabled = false;
    });

    var submitBtn = document.getElementById('btn-submit-fg');
    if (submitBtn) submitBtn.addEventListener('click', async function () {
      var ok = await Amor.confirmModal({ title: 'Submit FG?', body: 'Stok FG akan bertambah sesuai angka Packed yang disubmit. Pastikan sudah benar.', confirmLabel: 'Ya, Submit' });
      if (!ok) return;
      submitBtn.disabled = true;
      try {
        var saved = await Amor.apiFetch('/api/fg/' + batchId, { method: 'PATCH', body: { expectedVersion: version, items: collectItems(), refreshSource: refreshBox && refreshBox.checked } });
        await Amor.apiFetch('/api/fg/' + batchId + '/submit', { method: 'POST', body: { expectedVersion: saved.version } });
        Amor.toast('FG berhasil disubmit.', 'success');
        setTimeout(function () { location.reload(); }, 600);
      } catch (e) { Amor.toast(e.message, 'danger'); submitBtn.disabled = false; }
    });

    var refreshSourceBtn = document.getElementById('btn-refresh-fg-source');
    if (refreshSourceBtn) refreshSourceBtn.addEventListener('click', async function () {
      refreshSourceBtn.disabled = true;
      try {
        var data = await Amor.apiFetch('/api/fg/' + batchId + '/refresh-source', { method: 'POST', body: { expectedVersion: version } });
        version = data.version;
        var changed = (data.refreshChangedSnapshots || []).length;
        var blocking = (data.verifiedExceedsProductionBlocking || []).length;
        var msg = 'Sumber Produksi disegarkan. ' + changed + ' produk diperbarui angkanya.';
        if (blocking > 0) msg += ' PERINGATAN: ' + blocking + ' produk sekarang punya FG Terverifikasi melebihi Production Actual terbaru.';
        Amor.toast(msg, blocking > 0 ? 'warning' : 'success');
        setTimeout(function () { location.reload(); }, 800);
      } catch (e) { Amor.toast(e.message, 'danger'); refreshSourceBtn.disabled = false; }
    });

    var reopenBtn = document.getElementById('btn-reopen-fg');
    if (reopenBtn) reopenBtn.addEventListener('click', async function () {
      var reason = prompt('Alasan membuka kembali FG ini (wajib):');
      if (!reason) return;
      reopenBtn.disabled = true;
      try {
        await Amor.apiFetch('/api/fg/' + batchId + '/reopen', { method: 'POST', body: { expectedVersion: version, reason: reason } });
        Amor.toast('FG dibuka kembali.', 'success');
        setTimeout(function () { location.reload(); }, 600);
      } catch (e) { Amor.toast(e.message, 'danger'); reopenBtn.disabled = false; }
    });
    return; // end Verifikasi step wiring
  }

  // ---------------------------------------------------------------------
  // FG PACKING — per Toko (default/only packing workflow — task's own
  // "Packing should default to PER TOKO" requirement). Reuses the EXACT
  // SAME store-row data Verifikasi's Breakdown Toko already reads/writes
  // (GET/PATCH /api/fg/{id}/items/{productId}/stores) — grouped by STORE
  // here instead of by product. A product still entirely in Per Produk
  // mode (never exploded during Verifikasi) has no store identity yet, so
  // it cannot appear here — see this task's own delivery report for why
  // that is a deliberate limitation, not an oversight.
  // ---------------------------------------------------------------------
  var storeGroups = [];
  var currentStoreId = null;

  function groupByStore(results) {
    var byStore = {};
    var order = [];
    results.forEach(function (r) {
      (r.data.stores || []).forEach(function (s) {
        if (!byStore[s.storeId]) { byStore[s.storeId] = { storeId: s.storeId, storeName: s.storeName, rows: [] }; order.push(s.storeId); }
        byStore[s.storeId].rows.push({
          productId: r.productId, productName: r.productName,
          // A product with real Regular PO store-level demand shows up
          // here (batchProductStores() always renders live PO-target rows,
          // whether or not this product has ever been exploded — Phase 2
          // PO import is already store-split independently of Phase 4 FG's
          // own explode step) EVEN IF it is still being verified in
          // default Per Produk mode. r.data.exploded is per-PRODUCT (same
          // value on every one of that product's rows here) — a still-
          // unexploded product's fgVerified is always 0 at every store
          // (nothing has been assigned to any one store yet), so there is
          // nothing real to pack for it yet; it is shown read-only, never
          // included in a "Submit Packing" payload (see renderDetail()) —
          // attempting to would try to explodeToStores() a row that still
          // has a real (nonzero) Per Produk aggregate, which the backend
          // correctly refuses (MODE_SWITCH_REQUIRES_ZERO_PER_PRODUK) since
          // it cannot guess how to split that number across stores.
          exploded: r.data.exploded,
          target: s.target, fgVerified: s.fgVerified, packed: s.packed,
          reject: s.reject, hilang: s.hilang, notes: s.notes, status: s.status,
        });
      });
    });
    return order.map(function (id) { return byStore[id]; });
  }

  // Store status — a NEW UI-only concept (no pre-existing backend rule to
  // preserve here), documented plainly: "Selesai" once every unit of this
  // store's OWN PO target has been packed; "Belum Mulai" if nothing has;
  // otherwise "Sebagian" when packing has caught up to everything
  // CURRENTLY verified-ready (capped only by verification elsewhere still
  // being incomplete — nothing more this store can pack right now) vs
  // "Sedang Dikerjakan" when there is more ready-to-pack quantity than
  // what has been packed so far (still actively workable).
  function storeStatus(group) {
    var targetTotal = 0, readyTotal = 0, packedTotal = 0;
    // A never-exploded row (see groupByStore()'s own docblock) can never
    // be packed at all yet — counting its target in this store's own
    // progress denominator would understate real completion (a store
    // fully packed for everything actually ready would never show
    // "Selesai"/100%, forever short by an amount nothing here can act on
    // yet). Only rows this store can actually submit packing for count.
    group.rows.forEach(function (r) { if (r.exploded) { targetTotal += r.target; readyTotal += r.fgVerified; packedTotal += r.packed; } });
    var eps = 0.0001;
    var code, label;
    if (packedTotal <= eps) { code = 'belum_mulai'; label = 'Belum Mulai'; }
    else if (packedTotal >= targetTotal - eps) { code = 'selesai'; label = 'Selesai'; }
    else if (packedTotal >= readyTotal - eps) { code = 'sebagian'; label = 'Sebagian'; }
    else { code = 'sedang_dikerjakan'; label = 'Sedang Dikerjakan'; }
    return { code: code, label: label, targetTotal: targetTotal, readyTotal: readyTotal, packedTotal: packedTotal };
  }

  function renderHeader() {
    var header = document.getElementById('fg-packing-header');
    var totalStores = storeGroups.length;
    var doneStores = 0, packedSum = 0, targetSum = 0;
    storeGroups.forEach(function (g) {
      var st = storeStatus(g);
      if (st.code === 'selesai') doneStores++;
      packedSum += st.packedTotal; targetSum += st.targetTotal;
    });
    var pct = targetSum > 0 ? Math.min(100, Math.round((packedSum / targetSum) * 100)) : 0;
    header.innerHTML = '<div class="fg-card-row"><span class="fg-card-row-label">Toko Selesai</span><b>' + doneStores + ' / ' + totalStores + ' toko</b></div>'
      + '<div class="fg-card-row"><span class="fg-card-row-label">Packed pcs</span><b>' + packedSum.toLocaleString('id-ID') + ' / ' + targetSum.toLocaleString('id-ID') + ' pcs</b></div>'
      + '<div class="fg-progress-track"><div class="fg-progress-fill" style="width:' + pct + '%;"></div></div>';
  }

  function renderChips() {
    var row = document.getElementById('fg-store-chip-row');
    if (storeGroups.length === 0) {
      row.innerHTML = '<p style="color:var(--text-muted);">Belum ada Toko dengan target &gt; 0 untuk produk manapun (jalankan Breakdown Toko di FG Verifikasi dulu).</p>';
      return;
    }
    row.innerHTML = '';
    storeGroups.forEach(function (g) {
      var st = storeStatus(g);
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'fg-store-chip' + (g.storeId === currentStoreId ? ' active' : '');
      chip.setAttribute('data-store-id', g.storeId);
      chip.innerHTML = '<div class="fg-store-chip-name">' + g.storeName + '</div>'
        + '<div class="fg-store-chip-meta">' + g.rows.length + ' produk &bull; ' + st.targetTotal.toLocaleString('id-ID') + ' pcs</div>'
        + '<div class="fg-store-chip-meta">' + st.label + '</div>';
      chip.addEventListener('click', function () { currentStoreId = g.storeId; renderChips(); renderDetail(); });
      row.appendChild(chip);
    });
  }

  function renderDetail() {
    var detail = document.getElementById('fg-packing-detail');
    var group = storeGroups.filter(function (g) { return g.storeId === currentStoreId; })[0];
    if (!group) { detail.innerHTML = ''; return; }
    var cardsHtml = group.rows.map(function (r) {
      var packingSesuai = editableHere && Math.abs(r.packed - r.fgVerified) < 0.01 && r.packed > 0;
      var body = '<div class="fg-card fg-packing-row" data-product-id="' + r.productId + '" data-exploded="' + (r.exploded ? '1' : '0') + '">'
        + '<div class="fg-card-title">' + r.productName + '</div>'
        + '<div class="fg-card-sub">Target Toko: ' + r.target.toLocaleString('id-ID') + ' &middot; Ready Verified: ' + r.fgVerified.toLocaleString('id-ID') + '</div>';
      if (!r.exploded) {
        // Still verified in default Per Produk mode — no real per-store
        // FG Verified exists yet, so there is nothing this store can
        // legitimately pack for this product (Ready Verified is always 0
        // here). Shown for visibility only; never part of a submit
        // payload (see the submit handler below) — attempting to would
        // try to split an existing nonzero Per Produk aggregate across
        // stores, which the backend correctly refuses to guess at.
        return body
          + '<div class="alert alert-warning" style="margin:var(--space-2) 0;">Belum di-Breakdown Toko — verifikasi per Toko dulu di FG Verifikasi sebelum bisa dipacking di sini.</div>'
          + '</div>';
      }
      if (!editableHere) {
        body += '<div class="fg-card-row"><span class="fg-card-row-label">Actual Packing</span><span>' + r.packed.toLocaleString('id-ID') + '</span></div>'
          + '<div class="fg-card-row"><span class="fg-card-row-label">Reject</span><span>' + r.reject.toLocaleString('id-ID') + '</span></div>'
          + '<div class="fg-card-row"><span class="fg-card-row-label">Hilang</span><span>' + r.hilang.toLocaleString('id-ID') + '</span></div>'
          + '<div class="fg-card-row"><span class="fg-card-row-label">Keterangan</span><span>' + (r.notes || '') + '</span></div>'
          + '<div class="fg-card-row"><span class="fg-card-row-label">Status</span><span>' + (r.status || '') + '</span></div>'
          + '<input type="hidden" data-pk-field="fgVerified" value="' + r.fgVerified + '">'
          + '</div>';
        return body;
      }
      // CRITICAL PACKING RULE: Sesuai auto-fills to Ready Verified (r.fgVerified —
      // what THIS store actually has ready), never the raw Target — the
      // server's own PACKED_EXCEEDS_VERIFIED check (packed <= fgVerified,
      // unchanged, pre-existing rule) is what actually enforces this; the
      // auto-fill below simply mirrors it so a real over-target tap can never
      // even be typed by clicking "Sesuai".
      body += '<div class="fg-card-row"><span class="fg-card-row-label">Packing Result</span>'
        + '<div class="btn-group pk-sesuai" data-sesuai="' + (packingSesuai ? '1' : '0') + '">'
        +   '<button type="button" class="btn btn-sm ' + (packingSesuai ? 'btn-primary' : 'btn-secondary') + '" data-value="sesuai">Sesuai</button>'
        +   '<button type="button" class="btn btn-sm ' + (!packingSesuai ? 'btn-danger' : 'btn-secondary') + '" data-value="tidak_sesuai">Tidak Sesuai</button>'
        + '</div></div>'
        + '<div class="fg-card-row"><span class="fg-card-row-label">Actual Packing</span><input type="number" step="0.01" min="0" max="' + r.fgVerified + '" class="fg-card-input" data-pk-field="packed" value="' + r.packed + '" ' + (packingSesuai ? 'disabled' : '') + '></div>'
        + '<div class="fg-card-row"><span class="fg-card-row-label">Reject</span><input type="number" step="0.01" min="0" class="fg-card-input" data-pk-field="reject" value="' + r.reject + '"></div>'
        + '<div class="fg-card-row"><span class="fg-card-row-label">Hilang</span><input type="number" step="0.01" min="0" class="fg-card-input" data-pk-field="hilang" value="' + r.hilang + '"></div>'
        + '<div class="fg-card-row"><span class="fg-card-row-label">Keterangan</span><input type="text" class="fg-card-input" data-pk-field="notes" value="' + (r.notes || '').replace(/"/g, '&quot;') + '"></div>'
        + '<input type="hidden" data-pk-field="fgVerified" value="' + r.fgVerified + '">'
        + '</div>';
      return body;
    }).join('');
    var actions = editableHere
      ? '<div class="fg-card-actions" style="margin-top:var(--space-3);"><button type="button" class="btn btn-primary" id="fg-submit-packing-store">Submit Packing ' + group.storeName + '</button></div>'
      : '';
    detail.innerHTML = '<h3 class="card-title" style="font-size:var(--text-md);margin-bottom:var(--space-2);">Packing — ' + group.storeName + '</h3>'
      + '<div class="fg-card-list">' + cardsHtml + '</div>' + actions;

    detail.querySelectorAll('.pk-sesuai').forEach(function (group2) {
      var card = group2.closest('.fg-packing-row');
      var packedInput = card.querySelector('[data-pk-field="packed"]');
      var readyInput = card.querySelector('[data-pk-field="fgVerified"]');
      wireSesuaiGroup(group2, packedInput, function () { return readyInput.value || '0'; });
    });

    var submitStoreBtn = document.getElementById('fg-submit-packing-store');
    if (submitStoreBtn) submitStoreBtn.addEventListener('click', async function () {
      submitStoreBtn.disabled = true;
      var storeItems = [];
      // A still-unexploded product (data-exploded="0") is deliberately
      // left OUT of this store's own storeItems payload entirely — see
      // this card's own rendering above for why.
      detail.querySelectorAll('.fg-packing-row[data-exploded="1"]').forEach(function (card) {
        var pid = card.getAttribute('data-product-id');
        var field = function (name) { var el = card.querySelector('[data-pk-field="' + name + '"]'); return el ? el.value : ''; };
        var sesuaiGroup = card.querySelector('.pk-sesuai');
        storeItems.push({
          productId: parseInt(pid, 10),
          rows: [{
            storeId: currentStoreId,
            fgVerified: parseFloat(field('fgVerified') || '0'),
            packed: parseFloat(field('packed') || '0'),
            reject: parseFloat(field('reject') || '0'),
            hilang: parseFloat(field('hilang') || '0'),
            notes: field('notes'),
            sesuaiPacking: sesuaiGroup ? sesuaiGroup.getAttribute('data-sesuai') === '1' : false,
          }],
        });
      });
      try {
        // Submitting ONE store's packing PATCHes only THAT store's rows —
        // every other store's own storeItems entry is left out of this
        // call entirely, so it is never touched (task's own explicit
        // "Submitting one store must not automatically submit another
        // store" rule).
        var saved = await Amor.apiFetch('/api/fg/' + batchId, { method: 'PATCH', body: { expectedVersion: version, storeItems: storeItems } });
        version = saved.version;
        Amor.toast('Packing ' + group.storeName + ' disimpan.', 'success');
        await reloadStoreGroups();
        renderHeader(); renderChips(); renderDetail();
        submitStoreBtn.disabled = false;
      } catch (e) { Amor.toast(e.message, 'danger'); submitStoreBtn.disabled = false; }
    });
  }

  async function reloadStoreGroups() {
    var results = await fetchAllStoreRows();
    storeGroups = groupByStore(results);
    if (currentStoreId === null && storeGroups.length > 0) currentStoreId = storeGroups[0].storeId;
  }

  async function initPacking() {
    try {
      await reloadStoreGroups();
      renderHeader();
      renderChips();
      renderDetail();
    } catch (e) {
      document.getElementById('fg-packing-header').innerHTML = '<div class="alert alert-danger">' + e.message + '</div>';
    }
  }
  // window.Amor is defined by assets/js/app.js, whose <script> tag is
  // rendered by ui_page_foot() AFTER this page's own inline <script> in
  // the HTML — a plain non-deferred <script src> still blocks the parser
  // until it loads, but only for what comes AFTER it, so calling
  // Amor.apiFetch immediately here (a plain top-level call, unlike
  // Verifikasi's own Amor calls, which only ever run inside a LATER click
  // handler) would run before app.js has ever been requested. The Packing
  // step's very first fetch is deferred to 'load' (fires once every
  // resource, including that later script, has finished) for exactly
  // this reason.
  window.addEventListener('load', initPacking);

  var submitAllBtn = document.getElementById('btn-submit-fg-packing');
  if (submitAllBtn) submitAllBtn.addEventListener('click', async function () {
    var ok = await Amor.confirmModal({ title: 'Submit FG?', body: 'Stok FG akan bertambah sesuai angka Packed yang sudah disimpan per Toko. Pastikan semua Toko sudah benar.', confirmLabel: 'Ya, Submit' });
    if (!ok) return;
    submitAllBtn.disabled = true;
    try {
      await Amor.apiFetch('/api/fg/' + batchId + '/submit', { method: 'POST', body: { expectedVersion: version } });
      Amor.toast('FG berhasil disubmit.', 'success');
      setTimeout(function () { location.reload(); }, 600);
    } catch (e) { Amor.toast(e.message, 'danger'); submitAllBtn.disabled = false; }
  });

  var reopenBtn2 = document.getElementById('btn-reopen-fg-packing');
  if (reopenBtn2) reopenBtn2.addEventListener('click', async function () {
    var reason = prompt('Alasan membuka kembali FG ini (wajib):');
    if (!reason) return;
    reopenBtn2.disabled = true;
    try {
      await Amor.apiFetch('/api/fg/' + batchId + '/reopen', { method: 'POST', body: { expectedVersion: version, reason: reason } });
      Amor.toast('FG dibuka kembali.', 'success');
      setTimeout(function () { location.reload(); }, 600);
    } catch (e) { Amor.toast(e.message, 'danger'); reopenBtn2.disabled = false; }
  });
})();
</script>

<?php elseif ($targetView !== null): ?>
<div class="card section">
  <div class="card-head"><h2 class="card-title">Produksi Submitted Tersedia</h2></div>
  <?php if ($targetView['items'] === []): ?>
    <?= ui_empty_state('Belum ada Produksi SUBMITTED', 'Buka halaman Produksi dan submit produksinya dulu untuk tanggal & pabrik ini.') ?>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Produk</th><th class="num">Production Actual</th></tr></thead>
    <tbody>
    <?php foreach ($targetView['items'] as $it): ?>
    <tr><td><?= ui_esc($it['productName']) ?></td><td class="num"><?= ui_fmt_num($it['actual']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <button type="button" class="btn btn-primary" style="margin-top:var(--space-4);" id="btn-create-fg">Buat/Buka Draft FG</button>
  <script>
  document.getElementById('btn-create-fg').addEventListener('click', async function () {
    this.disabled = true;
    try {
      await Amor.apiFetch('/api/fg', { method: 'POST', body: { tanggal: <?= json_encode($uiTanggal) ?>, factoryId: <?= $uiFactoryId ?> } });
      Amor.toast('Draft FG dibuat.', 'success');
      setTimeout(function () { location.reload(); }, 500);
    } catch (e) { Amor.toast(e.message, 'danger'); this.disabled = false; }
  });
  </script>
  <?php endif; ?>
</div>
<?php endif; ?>
