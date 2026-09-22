<?php

declare(strict_types=1);

/**
 * "Task per Divisi" — Production's consolidated task list combining PO
 * Reguler + Pesanan Khusus Toko + Pesanan Non-Toko + Replacement Reject
 * (task's own "Business Goal"). READ-ONLY aggregation — see
 * ProductionTaskService's own docblock for why: PO Reguler rows are
 * edited only via Ceklis Produksi (this page just displays the SAME
 * production_item.aktual/reject), Pesanan Khusus/Non-Toko rows are
 * edited here (their only home) via POST /api/special-orders/{id}/actual.
 *
 * Search/Sumber/Status are CLIENT-SIDE filters (task's own "keep simple")
 * over the already-rendered rows — Tanggal/Pabrik/Divisi are the real
 * server-side filters (a different division/date is a different dataset
 * entirely, so those reload the page; a different source/status is just
 * a view of the SAME dataset already on the page).
 */

use Amor\Api\Production\ProductionTaskService;

$divisionIdParam = isset($_GET['divisionId']) && $_GET['divisionId'] !== '' ? (int) $_GET['divisionId'] : null;

$divisions = $pdo->prepare('SELECT division_id, name FROM division WHERE factory_id = ? AND is_verification = 0 ORDER BY name');
$divisions->execute([$uiFactoryId]);
$divisionRows = $divisions->fetchAll();
if ($divisionIdParam === null && $divisionRows !== []) {
    $divisionIdParam = (int) $divisionRows[0]['division_id'];
}

$service = new ProductionTaskService($pdo);
$data = null;
$loadError = null;
if ($divisionIdParam !== null) {
    try {
        $data = $service->tasksForDivision($uiTanggal, $divisionIdParam, null, null);
    } catch (\Throwable $e) {
        $loadError = $e->getMessage();
    }
}
?>
<?= ui_produksi_tabs('produksi-task-per-divisi', $uiTanggal, $uiFactoryId) ?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:var(--space-3);margin-bottom:var(--space-4);">
  <div>
    <h2 class="card-title" style="font-size:var(--text-xl);">Task per Divisi</h2>
    <p class="page-subtitle">Lihat target, input realisasi produksi, dan pantau progress per divisi.</p>
  </div>
</div>

<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="produksi-task-per-divisi">
    <div class="field"><label>Tanggal</label><input type="date" name="tanggal" value="<?= ui_esc($uiTanggal) ?>"></div>
    <div class="field"><label>Pabrik</label>
      <select name="factoryId">
        <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['factory_id'] ?>" <?= $uiFactoryId === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Divisi</label>
      <select name="divisionId">
        <?php foreach ($divisionRows as $d): ?>
        <option value="<?= (int) $d['division_id'] ?>" <?= $divisionIdParam === (int) $d['division_id'] ? 'selected' : '' ?>><?= ui_esc($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Terapkan</button>
    <?php if ($data !== null): ?>
    <a class="btn btn-secondary" target="_blank" href="/api/_ui-preview/print-production-task.php?tanggal=<?= urlencode($uiTanggal) ?>&divisionId=<?= $divisionIdParam ?>">&#128438; Print Divisi Ini</a>
    <a class="btn btn-primary" target="_blank" href="/api/_ui-preview/print-production-task-bulk.php?tanggal=<?= urlencode($uiTanggal) ?>&factoryId=<?= $uiFactoryId ?>">&#128438; Print Semua Divisi</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($divisionRows === []): ?>
<div class="card section"><?= ui_empty_state('Pabrik ini belum punya divisi produksi', '') ?></div>
<?php elseif ($loadError !== null): ?>
<div class="alert alert-danger"><?= ui_esc($loadError) ?></div>
<?php elseif ($data !== null): ?>

<div class="kpi-grid" style="grid-template-columns:repeat(5,minmax(0,1fr));">
  <?= ui_kpi_card(['label' => 'Total Produk / Task', 'value' => (string) $data['summary']['totalProdukTask'], 'icon' => 'box', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'Total Target', 'value' => ui_fmt_num($data['summary']['totalTarget']), 'icon' => 'cart', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'Total Aktual', 'value' => ui_fmt_num($data['summary']['totalAktual']), 'icon' => 'file', 'color' => 'success', 'progressPct' => (int) round($data['summary']['progressPct'])]) ?>
  <?= ui_kpi_card(['label' => 'Total Reject Produksi', 'value' => ui_fmt_num($data['summary']['totalReject']), 'icon' => 'bolt', 'color' => 'danger']) ?>
  <?= ui_kpi_card(['label' => 'Sisa Target', 'value' => ui_fmt_num($data['summary']['sisaTarget']), 'icon' => 'box', 'color' => 'warning']) ?>
</div>

<div class="table-card section">
  <div class="card-head" style="padding:var(--space-4) var(--space-4) 0;flex-wrap:wrap;gap:var(--space-3);">
    <div>
      <h2 class="card-title">Daftar Task Produksi</h2>
      <p style="color:var(--text-muted);font-size:var(--text-sm);margin:2px 0 0;">Seluruh kebutuhan produksi berdasarkan PO Reguler, Pesanan Khusus, Pesanan Non-Toko, dan Replacement Reject.</p>
    </div>
    <div style="display:flex;gap:var(--space-2);align-items:center;flex-wrap:wrap;">
      <input type="text" id="ptd-search" placeholder="Cari produk atau catatan..." style="min-width:220px;">
      <select id="ptd-source-filter">
        <option value="">Semua Sumber</option>
        <option value="po_reguler">PO Reguler</option>
        <option value="pesanan_khusus">Pesanan Khusus</option>
        <option value="pesanan_non_toko">Pesanan Non-Toko</option>
        <option value="replacement_reject">Replacement Reject</option>
      </select>
      <select id="ptd-status-filter">
        <option value="">Semua Status</option>
        <option value="belum_diproduksi">Belum Diproduksi</option>
        <option value="belum_selesai">Belum Selesai</option>
        <option value="selesai">Selesai</option>
      </select>
    </div>
  </div>
  <div class="table-scroll"><table class="data-table" id="ptd-table">
    <thead><tr>
      <th>No</th><th>Produk / Task</th><th>Sumber Demand</th><th class="num">Target</th>
      <th class="num">Aktual</th><th class="num">Reject Produksi</th><th class="num">Sisa Target</th>
      <th>Catatan Khusus</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php if ($data['tasks'] === []): ?>
    <tr><td colspan="9"><?= ui_empty_state('Belum ada task produksi', 'Tidak ada PO Reguler maupun Pesanan Khusus/Non-Toko untuk tanggal dan divisi ini.') ?></td></tr>
    <?php else: $no = 1; foreach ($data['tasks'] as $t): ?>
    <tr class="ptd-row" data-source="<?= ui_esc($t['source']) ?>" data-status="<?= ui_esc($t['statusCode']) ?>" data-search="<?= ui_esc(mb_strtolower($t['taskName'] . ' ' . (string) ($t['catatanKhusus'] ?? ''))) ?>">
      <td><?= $no++ ?></td>
      <td><?= ui_esc($t['taskName']) ?><?php if ($t['reference'] !== null): ?><div style="color:var(--text-muted);font-size:var(--text-xs);"><?= ui_esc($t['reference']) ?></div><?php endif; ?></td>
      <td><?= ui_task_source_badge($t['source'], $t['sourceLabel']) ?></td>
      <td class="num"><?= ui_fmt_num($t['target']) ?></td>
      <td class="num">
        <?php if ($t['editable']): ?>
        <input type="number" class="ptd-aktual" min="0" step="0.01" style="width:5.5rem;text-align:right;" value="<?= ui_fmt_num($t['aktual']) ?>"
               data-item-id="<?= (int) $t['itemId'] ?>" data-order-id="<?= (int) $t['orderId'] ?>" data-order-version="<?= (int) $t['orderVersion'] ?>">
        <?php else: ?><?= ui_fmt_num($t['aktual']) ?><?php endif; ?>
      </td>
      <td class="num">
        <?php if ($t['editable']): ?>
        <input type="number" class="ptd-reject" min="0" step="0.01" style="width:5.5rem;text-align:right;" value="<?= ui_fmt_num($t['reject']) ?>"
               data-item-id="<?= (int) $t['itemId'] ?>" data-order-id="<?= (int) $t['orderId'] ?>">
        <?php else: ?><?= ui_fmt_num($t['reject']) ?><?php endif; ?>
      </td>
      <td class="num"><?= ui_fmt_num($t['sisa']) ?></td>
      <td style="max-width:220px;overflow-wrap:anywhere;"><?= $t['catatanKhusus'] ? ui_esc($t['catatanKhusus']) : '-' ?></td>
      <td><?= ui_badge($t['statusLabel']) ?></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <?php if ($data['tasks'] !== []): ?>
    <tfoot><tr>
      <td colspan="3">Total</td>
      <td class="num"><?= ui_fmt_num($data['summary']['totalTarget']) ?></td>
      <td class="num"><?= ui_fmt_num($data['summary']['totalAktual']) ?></td>
      <td class="num"><?= ui_fmt_num($data['summary']['totalReject']) ?></td>
      <td class="num"><?= ui_fmt_num($data['summary']['sisaTarget']) ?></td>
      <td colspan="2">-</td>
    </tr></tfoot>
    <?php endif; ?>
  </table></div>
  <?php if (array_filter($data['tasks'], fn ($t) => $t['editable'])): ?>
  <div style="padding:0 var(--space-4) var(--space-4);">
    <button type="button" class="btn btn-primary" id="ptd-save-actual">Simpan Aktual &amp; Reject</button>
    <span id="ptd-save-error" style="color:var(--danger);margin-left:var(--space-3);"></span>
  </div>
  <?php endif; ?>
</div>

<div class="card section">
  <div style="display:flex;gap:var(--space-4);flex-wrap:wrap;align-items:center;">
    <span style="display:flex;align-items:center;gap:6px;font-size:var(--text-sm);"><span class="badge badge-primary" style="padding:3px 8px;">&nbsp;</span> PO Reguler</span>
    <span style="display:flex;align-items:center;gap:6px;font-size:var(--text-sm);"><span class="badge badge-warning" style="padding:3px 8px;">&nbsp;</span> Pesanan Khusus</span>
    <span style="display:flex;align-items:center;gap:6px;font-size:var(--text-sm);"><span class="badge badge-success" style="padding:3px 8px;">&nbsp;</span> Pesanan Non-Toko</span>
    <span style="display:flex;align-items:center;gap:6px;font-size:var(--text-sm);"><span class="badge badge-danger" style="padding:3px 8px;">&nbsp;</span> Replacement Reject</span>
  </div>
  <div class="alert alert-warning" style="margin-top:var(--space-3);margin-bottom:0;">
    <b>Sisa Target = Target - Aktual (baik).</b><br>
    Aktual + Reject Produksi = Total diproduksi.<br>
    Pastikan sisa target = 0 sebelum proses packing/FG.
  </div>
</div>

<script>
(function () {
  function applyFilters() {
    var q = (document.getElementById('ptd-search').value || '').toLowerCase().trim();
    var src = document.getElementById('ptd-source-filter').value;
    var st = document.getElementById('ptd-status-filter').value;
    document.querySelectorAll('#ptd-table tbody tr.ptd-row').forEach(function (tr) {
      var matchQ = q === '' || tr.getAttribute('data-search').indexOf(q) !== -1;
      var matchSrc = src === '' || tr.getAttribute('data-source') === src;
      var matchSt = st === '' || tr.getAttribute('data-status') === st;
      tr.style.display = (matchQ && matchSrc && matchSt) ? '' : 'none';
    });
  }
  var searchEl = document.getElementById('ptd-search');
  if (searchEl) {
    searchEl.addEventListener('input', applyFilters);
    document.getElementById('ptd-source-filter').addEventListener('change', applyFilters);
    document.getElementById('ptd-status-filter').addEventListener('change', applyFilters);
  }

  var saveBtn = document.getElementById('ptd-save-actual');
  if (saveBtn) {
    saveBtn.addEventListener('click', async function () {
      var errEl = document.getElementById('ptd-save-error');
      errEl.textContent = '';
      var byOrder = {};
      document.querySelectorAll('.ptd-aktual').forEach(function (input) {
        var orderId = input.getAttribute('data-order-id');
        var itemId = input.getAttribute('data-item-id');
        var version = parseInt(input.getAttribute('data-order-version'), 10);
        var rejectInput = document.querySelector('.ptd-reject[data-item-id="' + itemId + '"]');
        if (!byOrder[orderId]) byOrder[orderId] = { expectedVersion: version, items: [] };
        byOrder[orderId].items.push({
          itemId: parseInt(itemId, 10),
          aktualProduksi: parseFloat(input.value || '0'),
          rejectProduksi: parseFloat(rejectInput ? (rejectInput.value || '0') : '0'),
        });
      });
      saveBtn.disabled = true;
      try {
        for (var orderId in byOrder) {
          if (!byOrder.hasOwnProperty(orderId)) continue;
          await Amor.apiFetch('/api/special-orders/' + orderId + '/actual', { method: 'POST', body: byOrder[orderId] });
        }
        Amor.toast('Aktual & Reject Produksi disimpan.', 'success');
        setTimeout(function () { location.reload(); }, 500);
      } catch (e) {
        errEl.textContent = e.message;
        saveBtn.disabled = false;
      }
    });
  }
})();
</script>
<?php endif; ?>
