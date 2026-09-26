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
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Produk</th><th class="num">Target FG (Hasil Produksi)</th><th>Verified</th><th class="num">FG Terverifikasi</th><th class="num">Selisih</th><th>Packing</th><th class="num">Packed</th><th class="num">Reject</th><th class="num">Hilang</th><th class="num">Available</th><th>FG Status</th><th>Packing Status</th><th>Catatan</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($batchView['items'] as $it): ?>
    <?php
      $verifiedIsSesuai = $editable && abs($it['fgVerified'] - $it['productionActualSnapshot']) < 0.01 && $it['fgVerified'] > 0;
      $packingIsSesuai = $editable && abs($it['packed'] - $it['fgVerified']) < 0.01 && $it['packed'] > 0;
    ?>
    <tr>
      <td><?= ui_esc($it['productName']) ?></td>
      <td class="num"><?= ui_fmt_num($it['productionActualSnapshot']) ?></td>
      <td>
        <?php if ($editable): ?>
        <div class="btn-group fg-verified-sesuai-group" data-product-id="<?= (int) $it['productId'] ?>" data-target="<?= ui_esc((string) $it['productionActualSnapshot']) ?>" data-sesuai="<?= $verifiedIsSesuai ? '1' : '0' ?>">
          <button type="button" class="btn btn-sm <?= $verifiedIsSesuai ? 'btn-primary' : 'btn-secondary' ?>" data-value="sesuai">Sesuai</button>
          <button type="button" class="btn btn-sm <?= !$verifiedIsSesuai ? 'btn-danger' : 'btn-secondary' ?>" data-value="tidak_sesuai">Tidak Sesuai</button>
        </div>
        <?php else: ?><span style="color:var(--text-faint);">-</span><?php endif; ?>
      </td>
      <td class="num"><?php if ($editable): ?><input type="number" step="0.01" min="0" style="width:5.5rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="fgVerified" value="<?= ui_fmt_num($it['fgVerified']) ?>" <?= $verifiedIsSesuai ? 'disabled' : '' ?>><?php else: ?><?= ui_fmt_num($it['fgVerified']) ?><?php endif; ?></td>
      <td class="num"><?= ui_fmt_num($it['variance']) ?></td>
      <td>
        <?php if ($editable): ?>
        <div class="btn-group fg-packing-sesuai-group" data-product-id="<?= (int) $it['productId'] ?>" data-sesuai="<?= $packingIsSesuai ? '1' : '0' ?>">
          <button type="button" class="btn btn-sm <?= $packingIsSesuai ? 'btn-primary' : 'btn-secondary' ?>" data-value="sesuai">Sesuai</button>
          <button type="button" class="btn btn-sm <?= !$packingIsSesuai ? 'btn-danger' : 'btn-secondary' ?>" data-value="tidak_sesuai">Tidak Sesuai</button>
        </div>
        <?php else: ?><span style="color:var(--text-faint);">-</span><?php endif; ?>
      </td>
      <td class="num"><?php if ($editable): ?><input type="number" step="0.01" min="0" style="width:5.5rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="packed" value="<?= ui_fmt_num($it['packed']) ?>" <?= $packingIsSesuai ? 'disabled' : '' ?>><?php else: ?><?= ui_fmt_num($it['packed']) ?><?php endif; ?></td>
      <td class="num"><?php if ($editable): ?><input type="number" step="0.01" min="0" style="width:4.5rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="reject" value="<?= ui_fmt_num($it['reject'] ?? 0) ?>"><?php else: ?><?= ui_fmt_num($it['reject'] ?? 0) ?><?php endif; ?></td>
      <td class="num"><?php if ($editable): ?><input type="number" step="0.01" min="0" style="width:4.5rem;text-align:right;" data-product-id="<?= (int) $it['productId'] ?>" data-field="hilang" value="<?= ui_fmt_num($it['hilang'] ?? 0) ?>"><?php else: ?><?= ui_fmt_num($it['hilang'] ?? 0) ?><?php endif; ?></td>
      <td class="num"><?= ui_fmt_num($it['available']) ?></td>
      <td><?= ui_badge($it['fgStatusLabel']) ?></td>
      <td><?= ui_badge($it['packingStatusLabel']) ?></td>
      <td><?php if ($editable): ?><input type="text" style="width:8rem;" data-product-id="<?= (int) $it['productId'] ?>" data-field="notes" value="<?= ui_esc((string) ($it['notes'] ?? '')) ?>"><?php else: ?><?= ui_esc((string) ($it['notes'] ?? '')) ?><?php endif; ?></td>
      <td><button type="button" class="btn btn-secondary btn-sm fg-breakdown-btn" data-product-id="<?= (int) $it['productId'] ?>" data-product-name="<?= ui_esc($it['productName']) ?>">Breakdown Toko</button></td>
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

  // Per Produk / Breakdown Toko — Breakdown Toko is a READ-ONLY reference
  // (see FgTargetService::storeBreakdownForProduct()'s own docblock): it
  // never becomes a second place to enter Verified/Packing, so Per Produk
  // and Breakdown Toko can never disagree — there is only ever ONE stored
  // number per product, entered above.
  var modeProduk = document.getElementById('fg-mode-produk');
  var modeToko = document.getElementById('fg-mode-toko');
  var breakdownPanel = document.getElementById('fg-breakdown-panel');
  if (modeProduk && modeToko) {
    modeProduk.addEventListener('click', function () {
      modeProduk.className = 'btn btn-sm btn-primary'; modeToko.className = 'btn btn-sm btn-secondary';
      if (breakdownPanel) breakdownPanel.style.display = 'none';
    });
    modeToko.addEventListener('click', function () {
      modeToko.className = 'btn btn-sm btn-primary'; modeProduk.className = 'btn btn-sm btn-secondary';
      if (breakdownPanel) breakdownPanel.style.display = '';
    });
  }
  document.querySelectorAll('.fg-breakdown-btn').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var pid = btn.getAttribute('data-product-id');
      var pname = btn.getAttribute('data-product-name');
      if (modeToko) modeToko.click();
      if (!breakdownPanel) return;
      breakdownPanel.innerHTML = '<div style="color:var(--text-muted);">Memuat breakdown toko untuk ' + pname + '...</div>';
      try {
        var tanggal = form.getAttribute('data-tanggal');
        var factoryId = form.getAttribute('data-factory-id');
        var data = await Amor.apiFetch('/api/fg/store-breakdown?date=' + encodeURIComponent(tanggal) + '&factoryId=' + factoryId + '&productId=' + pid);
        var rows = (data.stores || []).map(function (s) {
          return '<tr><td>' + s.storeName + '</td><td class="num">' + s.poAwal.toLocaleString('id-ID') + '</td><td class="num">' + s.poRevisi.toLocaleString('id-ID') + '</td><td class="num" style="font-weight:600;">' + s.target.toLocaleString('id-ID') + '</td></tr>';
        }).join('');
        breakdownPanel.innerHTML = '<div class="card-head"><h3 class="card-title" style="font-size:var(--text-md);">Breakdown Toko — ' + pname + '</h3></div>'
          + '<div class="table-scroll"><table class="data-table"><thead><tr><th>Toko</th><th class="num">PO Awal</th><th class="num">PO Revisi</th><th class="num">Target</th></tr></thead>'
          + '<tbody>' + (rows || '<tr><td colspan="4">Tidak ada data PO per toko untuk produk ini.</td></tr>') + '</tbody>'
          + '<tfoot><tr><td colspan="3">Total Target (harus sama dengan Target FG di atas)</td><td class="num">' + data.totalTarget.toLocaleString('id-ID') + '</td></tr></tfoot></table></div>';
      } catch (e) {
        breakdownPanel.innerHTML = '<div class="alert alert-danger">' + e.message + '</div>';
      }
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
