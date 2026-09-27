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

$selesaiDipacking = 0;
if ($batchView !== null) {
    foreach ($batchView['items'] as $it) {
        if ($it['packingStatusCode'] === 'selesai_dipacking') {
            $selesaiDipacking++;
        }
    }
}
?>
<?= ui_fg_tabs('fg-packing', $uiTanggal, $uiFactoryId) ?>
<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="fg-packing">
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

<div class="card section">
  <div class="card-head">
    <h2 class="card-title">Draft FG #<?= (int) $batchView['fgBatchId'] ?></h2>
    <?= ui_badge(ui_doc_status_label($batchView['status'])) ?>
  </div>
  <?php $editable = in_array($batchView['status'], ['draft', 'reopened'], true); ?>
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
  <div class="alert alert-danger"><strong>Diblokir untuk Submit</strong> — <?= $batchView['summary']['jumlahMelebihiProduksi'] ?> produk punya FG Terverifikasi melebihi Production Actual terbaru (lihat baris berlabel "Melebihi Produksi" pada kolom FG Status). Turunkan FG Terverifikasi produk tersebut dulu sebelum submit — sistem tidak pernah menurunkannya secara otomatis.</div>
  <?php endif; ?>

  <div style="display:flex;gap:var(--space-2);align-items:center;margin-bottom:var(--space-3);">
    <span style="font-size:var(--text-sm);color:var(--text-muted);">Tampilan Data:</span>
    <div class="btn-group">
      <button type="button" class="btn btn-sm btn-primary" id="fg-mode-produk">Per Produk</button>
      <button type="button" class="btn btn-sm btn-secondary" id="fg-mode-toko">Breakdown Toko</button>
    </div>
  </div>

  <form id="fg-form" data-batch-id="<?= (int) $batchView['fgBatchId'] ?>" data-expected-version="<?= (int) $batchView['version'] ?>" data-tanggal="<?= ui_esc($uiTanggal) ?>" data-factory-id="<?= $uiFactoryId ?>">
  <div class="table-scroll" id="fg-perproduk-wrap"><table class="data-table">
    <thead><tr><th>Produk</th><th>Mode</th><th class="num">Target FG (Hasil Produksi)</th><th>Verified</th><th class="num">FG Terverifikasi</th><th class="num">Selisih</th><th>Packing</th><th class="num">Packed</th><th class="num">Reject</th><th class="num">Hilang</th><th class="num">Available</th><th>FG Status</th><th>Packing Status</th><th>Catatan</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($batchView['items'] as $it): ?>
    <?php
      $isBreakdown = ($it['mode'] ?? 'perProduk') === 'breakdownToko';
      // Per Produk cells become DERIVED/read-only the moment a product is
      // exploded into store rows (Option A — see FgService::patchDraft()'s
      // own docblock): the "no double counting" rule is satisfied by
      // construction only if there is never a second place to type a
      // number for the same product at the same time.
      $rowEditable = $editable && !$isBreakdown;
      $verifiedIsSesuai = $rowEditable && abs($it['fgVerified'] - $it['productionActualSnapshot']) < 0.01 && $it['fgVerified'] > 0;
      $packingIsSesuai = $rowEditable && abs($it['packed'] - $it['fgVerified']) < 0.01 && $it['packed'] > 0;
    ?>
    <tr>
      <td><?= ui_esc($it['productName']) ?></td>
      <td><?= ui_badge($isBreakdown ? 'Breakdown Toko (' . (int) $it['storeCount'] . ' Toko)' : 'Per Produk') ?></td>
      <td class="num"><?= ui_fmt_num($it['productionActualSnapshot']) ?></td>
      <td>
        <?php if ($rowEditable): ?>
        <div class="btn-group fg-verified-sesuai-group" data-product-id="<?= (int) $it['productId'] ?>" data-target="<?= ui_esc((string) $it['productionActualSnapshot']) ?>" data-sesuai="<?= $verifiedIsSesuai ? '1' : '0' ?>">
          <button type="button" class="btn btn-sm <?= $verifiedIsSesuai ? 'btn-primary' : 'btn-secondary' ?>" data-value="sesuai">Sesuai</button>
          <button type="button" class="btn btn-sm <?= !$verifiedIsSesuai ? 'btn-danger' : 'btn-secondary' ?>" data-value="tidak_sesuai">Tidak Sesuai</button>
        </div>
        <?php else: ?><span style="color:var(--text-faint);">-</span><?php endif; ?>
      </td>
      <td class="num"><?php if ($rowEditable): ?><input type="number" step="0.01" min="0" style="width:5.5rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="fgVerified" value="<?= ui_fmt_num($it['fgVerified']) ?>" <?= $verifiedIsSesuai ? 'disabled' : '' ?>><?php else: ?><?= ui_fmt_num($it['fgVerified']) ?><?php endif; ?></td>
      <td class="num"><?= ui_fmt_num($it['variance']) ?></td>
      <td>
        <?php if ($rowEditable): ?>
        <div class="btn-group fg-packing-sesuai-group" data-product-id="<?= (int) $it['productId'] ?>" data-sesuai="<?= $packingIsSesuai ? '1' : '0' ?>">
          <button type="button" class="btn btn-sm <?= $packingIsSesuai ? 'btn-primary' : 'btn-secondary' ?>" data-value="sesuai">Sesuai</button>
          <button type="button" class="btn btn-sm <?= !$packingIsSesuai ? 'btn-danger' : 'btn-secondary' ?>" data-value="tidak_sesuai">Tidak Sesuai</button>
        </div>
        <?php else: ?><span style="color:var(--text-faint);">-</span><?php endif; ?>
      </td>
      <td class="num"><?php if ($rowEditable): ?><input type="number" step="0.01" min="0" style="width:5.5rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="packed" value="<?= ui_fmt_num($it['packed']) ?>" <?= $packingIsSesuai ? 'disabled' : '' ?>><?php else: ?><?= ui_fmt_num($it['packed']) ?><?php endif; ?></td>
      <td class="num"><?php if ($rowEditable): ?><input type="number" step="0.01" min="0" style="width:4.5rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="reject" value="<?= ui_fmt_num($it['reject'] ?? 0) ?>"><?php else: ?><?= ui_fmt_num($it['reject'] ?? 0) ?><?php endif; ?></td>
      <td class="num"><?php if ($rowEditable): ?><input type="number" step="0.01" min="0" style="width:4.5rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="hilang" value="<?= ui_fmt_num($it['hilang'] ?? 0) ?>"><?php else: ?><?= ui_fmt_num($it['hilang'] ?? 0) ?><?php endif; ?></td>
      <td class="num"><?= ui_fmt_num($it['available']) ?></td>
      <td><?= ui_badge($it['fgStatusLabel']) ?></td>
      <td><?= ui_badge($it['packingStatusLabel']) ?></td>
      <td><?php if ($rowEditable): ?><input type="text" style="width:8rem;" data-product-id="<?= (int) $it['productId'] ?>" data-field="notes" value="<?= ui_esc((string) ($it['notes'] ?? '')) ?>"><?php else: ?><?= ui_esc((string) ($it['notes'] ?? '')) ?><?php endif; ?></td>
      <td style="white-space:nowrap;">
        <button type="button" class="btn btn-secondary btn-sm fg-breakdown-btn" data-product-id="<?= (int) $it['productId'] ?>" data-product-name="<?= ui_esc($it['productName']) ?>" data-mode="<?= $isBreakdown ? 'breakdownToko' : 'perProduk' ?>">Breakdown Toko</button>
        <?php if ($editable && $isBreakdown): ?>
        <button type="button" class="btn btn-warning btn-sm fg-collapse-btn" data-product-id="<?= (int) $it['productId'] ?>" data-product-name="<?= ui_esc($it['productName']) ?>">Kembali ke Per Produk</button>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div id="fg-breakdown-panel" class="subpanel" style="display:none;margin-top:var(--space-3);"></div>
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
</div>

<script>
(function () {
  // Sesuai/Tidak Sesuai for Verified and Packing — same auto-fill/lock
  // convenience and same "server always re-derives, never trusts a
  // disabled input" rule as Ceklis Produksi's own sesuai buttons (see
  // FgService::patchDraft()'s sesuaiVerified/sesuaiPacking check).
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
  document.querySelectorAll('.fg-verified-sesuai-group').forEach(function (group) {
    var pid = group.getAttribute('data-product-id');
    var input = document.querySelector('#fg-form [data-field="fgVerified"][data-product-id="' + pid + '"]');
    if (input) wireSesuaiGroup(group, input, function () { return group.getAttribute('data-target'); });
  });
  document.querySelectorAll('.fg-packing-sesuai-group').forEach(function (group) {
    var pid = group.getAttribute('data-product-id');
    var packedInput = document.querySelector('#fg-form [data-field="packed"][data-product-id="' + pid + '"]');
    var verifiedInput = document.querySelector('#fg-form [data-field="fgVerified"][data-product-id="' + pid + '"]');
    if (packedInput && verifiedInput) wireSesuaiGroup(group, packedInput, function () { return verifiedInput.value || '0'; });
  });

  // Per Produk / Breakdown Toko — the top-level "Tampilan Data" toggle now
  // actually SWITCHES the table's own data source/renderer (HOTFIX —
  // previously it only changed the two buttons' CSS classes and revealed
  // an empty panel, since nothing ever populated it unless a per-row
  // "Breakdown Toko" button had separately been clicked). Breakdown Toko
  // stays a pure VIEW over the SAME underlying fg_item rows Per Produk
  // reads (FgService::batchProductStores(), the writable data source — see
  // its own docblock): there is still only ONE stored number per store
  // row, so switching modes can never double-count or duplicate data
  // (task's own explicit "switching modes must not create duplicate
  // data"/"both modes must reflect the same canonical FG truth" rule).
  var modeProduk = document.getElementById('fg-mode-produk');
  var modeToko = document.getElementById('fg-mode-toko');
  var breakdownPanel = document.getElementById('fg-breakdown-panel');
  var perProdukWrap = document.getElementById('fg-perproduk-wrap');
  var editableHere = <?= $editable ? 'true' : 'false' ?>;
  // Server-filtered list (BUG 1 fix already applied to $batchView['items']
  // itself — see FgService::buildBatchDto()) — this is exactly "every
  // product with target > 0" the whole-table Breakdown Toko view must
  // fetch store rows for, never a second/looser list.
  var visibleProducts = <?= json_encode(array_map(static fn ($it) => ['productId' => $it['productId'], 'productName' => $it['productName']], $batchView['items'])) ?>;

  // Builds ONE product's store-breakdown block as a detached DOM node —
  // shared by the whole-table "Breakdown Toko" mode (one block per visible
  // product, stacked) and the per-row quick-view button (a single block).
  // Every element inside is scoped via closures over THIS node (never a
  // global id/getElementById), so any number of these can be on screen at
  // once without id collisions.
  function buildStoreBlock(pid, pname, data) {
    var rowsHtml = (data.stores || []).map(function (s, idx) {
      var verifiedSesuai = editableHere && Math.abs(s.fgVerified - s.target) < 0.01 && s.fgVerified > 0;
      var packingSesuai = editableHere && Math.abs(s.packed - s.fgVerified) < 0.01 && s.packed > 0;
      // "Perlu Review Ulang" — a PO revision lowered this store's target
      // below what's already entered/shipped against the OLD target;
      // non-blocking, purely informational (see FgService::
      // batchProductStores()'s own docblock — never silently reclaims
      // already-packed/already-shipped stock).
      var reviewBadge = s.needsReview ? ' <span class="badge badge-danger">Perlu Review Ulang</span>' : '';
      if (!editableHere) {
        return '<tr><td>' + (idx + 1) + '</td><td>' + pname + '</td><td>' + s.storeName + '</td><td class="num">' + s.target.toLocaleString('id-ID') + '</td>'
          + '<td class="num">' + s.fgVerified.toLocaleString('id-ID') + '</td><td class="num">' + s.packed.toLocaleString('id-ID') + '</td>'
          + '<td class="num">' + s.reject.toLocaleString('id-ID') + '</td><td class="num">' + s.hilang.toLocaleString('id-ID') + '</td>'
          + '<td>' + (s.notes || '') + '</td><td>' + (s.status || '') + reviewBadge + '</td></tr>';
      }
      return '<tr data-store-id="' + s.storeId + '">'
        + '<td>' + (idx + 1) + '</td><td>' + pname + '</td><td>' + s.storeName + reviewBadge + '</td><td class="num">' + s.target.toLocaleString('id-ID') + '</td>'
        + '<td><div class="btn-group bt-verified-sesuai" data-target="' + s.target + '" data-sesuai="' + (verifiedSesuai ? '1' : '0') + '">'
        +   '<button type="button" class="btn btn-sm ' + (verifiedSesuai ? 'btn-primary' : 'btn-secondary') + '" data-value="sesuai">Sesuai</button>'
        +   '<button type="button" class="btn btn-sm ' + (!verifiedSesuai ? 'btn-danger' : 'btn-secondary') + '" data-value="tidak_sesuai">Tidak Sesuai</button>'
        + '</div></td>'
        + '<td class="num"><input type="number" step="0.01" min="0" style="width:5rem;text-align:right;" data-bt-field="fgVerified" value="' + s.fgVerified + '" ' + (verifiedSesuai ? 'disabled' : '') + '></td>'
        + '<td><div class="btn-group bt-packing-sesuai" data-sesuai="' + (packingSesuai ? '1' : '0') + '">'
        +   '<button type="button" class="btn btn-sm ' + (packingSesuai ? 'btn-primary' : 'btn-secondary') + '" data-value="sesuai">Sesuai</button>'
        +   '<button type="button" class="btn btn-sm ' + (!packingSesuai ? 'btn-danger' : 'btn-secondary') + '" data-value="tidak_sesuai">Tidak Sesuai</button>'
        + '</div></td>'
        + '<td class="num"><input type="number" step="0.01" min="0" style="width:5rem;text-align:right;" data-bt-field="packed" value="' + s.packed + '" ' + (packingSesuai ? 'disabled' : '') + '></td>'
        + '<td class="num"><input type="number" step="0.01" min="0" style="width:4rem;text-align:right;" data-bt-field="reject" value="' + s.reject + '"></td>'
        + '<td class="num"><input type="number" step="0.01" min="0" style="width:4rem;text-align:right;" data-bt-field="hilang" value="' + s.hilang + '"></td>'
        + '<td><input type="text" style="width:8rem;" data-bt-field="notes" value="' + (s.notes || '').replace(/"/g, '&quot;') + '"></td>'
        + '<td>' + (s.status || '') + '</td></tr>';
    }).join('');
    var head = '<tr><th>No</th><th>Produk</th><th>Toko</th><th class="num">Target Toko</th><th>Verified Result</th><th class="num">Actual Verified</th><th>Packing Result</th><th class="num">Actual Packing</th><th class="num">Reject</th><th class="num">Hilang</th><th>Keterangan</th><th>Status</th></tr>';
    var actions = editableHere
      ? '<div class="btn-group" style="margin-top:var(--space-2);"><button type="button" class="btn btn-primary btn-sm bt-save">Simpan Breakdown Toko</button></div>'
      : '';

    var block = document.createElement('div');
    block.className = 'card section';
    block.setAttribute('data-product-id', pid);
    block.innerHTML = '<div class="card-head"><h3 class="card-title" style="font-size:var(--text-md);">Breakdown Toko — ' + pname + '</h3></div>'
      + (data.exploded ? '' : '<div class="alert alert-warning" style="margin-bottom:var(--space-2);">Produk ini masih mode Per Produk — mengisi baris di bawah dan Simpan akan memecahnya ke per-Toko.</div>')
      + (data.stores.length === 0 ? '<p style="color:var(--text-muted);">Tidak ada Toko dengan target &gt; 0 untuk produk ini pada tanggal/pabrik ini.</p>' : (
        '<div class="table-scroll"><table class="data-table"><thead>' + head + '</thead><tbody>' + rowsHtml + '</tbody>'
        + '<tfoot><tr><td colspan="3">Total</td><td class="num">' + data.totalTarget.toLocaleString('id-ID') + '</td><td></td><td class="num">' + data.totalVerified.toLocaleString('id-ID') + '</td><td></td><td class="num">' + data.totalPacked.toLocaleString('id-ID') + '</td><td colspan="3"></td></tr></tfoot></table></div>'
      ))
      + actions;

    block.querySelectorAll('.bt-verified-sesuai').forEach(function (group) {
      var input = group.closest('tr').querySelector('[data-bt-field="fgVerified"]');
      wireSesuaiGroup(group, input, function () { return group.getAttribute('data-target'); });
    });
    block.querySelectorAll('.bt-packing-sesuai').forEach(function (group) {
      var tr = group.closest('tr');
      var packedInput = tr.querySelector('[data-bt-field="packed"]');
      var verifiedInput = tr.querySelector('[data-bt-field="fgVerified"]');
      wireSesuaiGroup(group, packedInput, function () { return verifiedInput.value || '0'; });
    });

    var saveBtn = block.querySelector('.bt-save');
    if (saveBtn) saveBtn.addEventListener('click', async function () {
      saveBtn.disabled = true;
      var rowsOut = [];
      block.querySelectorAll('tbody tr[data-store-id]').forEach(function (tr) {
        var vGroup = tr.querySelector('.bt-verified-sesuai');
        var pGroup = tr.querySelector('.bt-packing-sesuai');
        rowsOut.push({
          storeId: parseInt(tr.getAttribute('data-store-id'), 10),
          fgVerified: parseFloat(tr.querySelector('[data-bt-field="fgVerified"]').value || '0'),
          packed: parseFloat(tr.querySelector('[data-bt-field="packed"]').value || '0'),
          reject: parseFloat(tr.querySelector('[data-bt-field="reject"]').value || '0'),
          hilang: parseFloat(tr.querySelector('[data-bt-field="hilang"]').value || '0'),
          notes: tr.querySelector('[data-bt-field="notes"]').value,
          sesuaiVerified: vGroup ? vGroup.getAttribute('data-sesuai') === '1' : false,
          sesuaiPacking: pGroup ? pGroup.getAttribute('data-sesuai') === '1' : false,
        });
      });
      try {
        var saved = await Amor.apiFetch('/api/fg/' + batchId, {
          method: 'PATCH',
          body: { expectedVersion: version, storeItems: [{ productId: parseInt(pid, 10), rows: rowsOut }] },
        });
        version = saved.version;
        form.setAttribute('data-expected-version', version);
        Amor.toast('Breakdown Toko disimpan.', 'success');
        setTimeout(function () { location.reload(); }, 600);
      } catch (e) { Amor.toast(e.message, 'danger'); saveBtn.disabled = false; }
    });
    return block;
  }

  async function fetchStoreData(pid) {
    return Amor.apiFetch('/api/fg/' + batchId + '/items/' + pid + '/stores');
  }

  // Whole-table mode switch: fetches EVERY visible product's store rows in
  // parallel and stacks their blocks in place of the Per Produk table —
  // "click Breakdown Toko -> rows appear directly", no manual per-product
  // "explode" step required first (batchProductStores() already renders
  // 0-rows from the live PO target before a product is ever exploded).
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
      var results = await Promise.all(visibleProducts.map(function (p) {
        return fetchStoreData(p.productId).then(function (data) { return { p: p, data: data }; });
      }));
      breakdownPanel.innerHTML = '';
      results.forEach(function (r) {
        breakdownPanel.appendChild(buildStoreBlock(r.p.productId, r.p.productName, r.data));
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
    btn.addEventListener('click', function () {
      var pid = btn.getAttribute('data-product-id');
      enterBreakdownMode(pid);
    });
  });

  // Explicit "Kembali ke Per Produk" — collapseProductIds merges the store
  // rows back into ONE row (SUM preserved exactly, see
  // FgService::collapseToProduct()'s own docblock) — never implicit, since
  // it drops per-store detail.
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
      var packingGroup = document.querySelector('.fg-packing-sesuai-group[data-product-id="' + pid + '"]');
      items.push({
        productId: parseInt(pid, 10),
        fgVerified: parseFloat(input.value || '0'),
        packed: packedInput ? parseFloat(packedInput.value || '0') : 0,
        reject: rejectInput ? parseFloat(rejectInput.value || '0') : 0,
        hilang: hilangInput ? parseFloat(hilangInput.value || '0') : 0,
        notes: notesInput ? notesInput.value : '',
        sesuaiVerified: verifiedGroup ? verifiedGroup.getAttribute('data-sesuai') === '1' : false,
        sesuaiPacking: packingGroup ? packingGroup.getAttribute('data-sesuai') === '1' : false,
      });
    });
    return items;
  }
  var form = document.getElementById('fg-form');
  if (!form) return;
  var batchId = form.getAttribute('data-batch-id');
  var version = parseInt(form.getAttribute('data-expected-version'), 10);
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
      form.setAttribute('data-expected-version', version);
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
