<?php

declare(strict_types=1);

/**
 * FG Sumber Khusus / Non-Toko — the Production → FG bridge for
 * Pesanan Khusus Toko / Pesanan Non-Toko (task's own scope E/F, migration
 * 0012). Deliberately a SEPARATE page/tab from fg-packing.php (Regular
 * PO's own FG flow, entirely untouched) — special-order FG stays
 * order-specific here (special_order_item.fg_verified_qty), never posted
 * to the shared stock_ledger/fg_batch tables (see the migration's own
 * docblock for the full architecture decision). "FG Terverifikasi" is a
 * snapshot value (same convention as Actual/Reject Produksi) — each
 * submit REPLACES the stored total, it never adds to it.
 */

use Amor\Api\SpecialOrder\SpecialOrderService;

$service = new SpecialOrderService($pdo);
$sourceTypeFilter = (string) ($_GET['sourceType'] ?? '');
$items = $service->fgEligibleItems(array_filter([
    'factoryId' => $uiFactoryId,
    'sourceType' => $sourceTypeFilter !== '' ? $sourceTypeFilter : null,
]));

$belumTerverifikasi = 0;
$sebagianTerverifikasi = 0;
$sudahLengkap = 0;
foreach ($items as $it) {
    if ($it['fgVerifiedQty'] <= 0.0001) {
        $belumTerverifikasi++;
    } elseif ($it['availableToVerify'] > 0.0001) {
        $sebagianTerverifikasi++;
    } else {
        $sudahLengkap++;
    }
}
?>
<?= ui_fg_tabs('fg-khusus-non-toko', $uiTanggal, $uiFactoryId) ?>

<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="fg-khusus-non-toko">
    <input type="hidden" name="tanggal" value="<?= ui_esc($uiTanggal) ?>">
    <div class="field"><label>Pabrik</label>
      <select name="factoryId" onchange="this.form.submit()">
        <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['factory_id'] ?>" <?= $uiFactoryId === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Sumber</label>
      <select name="sourceType" onchange="this.form.submit()">
        <option value="">Semua</option>
        <option value="toko_khusus" <?= $sourceTypeFilter === 'toko_khusus' ? 'selected' : '' ?>>Pesanan Khusus Toko</option>
        <option value="non_toko" <?= $sourceTypeFilter === 'non_toko' ? 'selected' : '' ?>>Pesanan Non-Toko</option>
      </select>
    </div>
  </form>
</div>

<div class="kpi-grid">
  <?= ui_kpi_card(['label' => 'Total Item Produksi', 'value' => (string) count($items), 'icon' => 'factory', 'color' => 'primary']) ?>
  <?= ui_kpi_card(['label' => 'Belum Diverifikasi', 'value' => (string) $belumTerverifikasi, 'icon' => 'file', 'color' => 'neutral']) ?>
  <?= ui_kpi_card(['label' => 'Sebagian Terverifikasi', 'value' => (string) $sebagianTerverifikasi, 'icon' => 'file', 'color' => 'warning']) ?>
  <?= ui_kpi_card(['label' => 'Sudah Lengkap', 'value' => (string) $sudahLengkap, 'icon' => 'box', 'color' => 'success']) ?>
</div>

<div class="table-card section">
  <div class="card-head"><h2 class="card-title">Verifikasi FG — Pesanan Khusus Toko &amp; Pesanan Non-Toko</h2></div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr>
      <th>No. Pesanan</th><th>Sumber</th><th>Toko/Customer</th><th>Item</th><th>Divisi</th>
      <th class="num">Aktual Produksi</th><th class="num">Reject</th><th class="num">Sudah Terverifikasi</th>
      <th class="num">Dialokasikan ke DO</th><th class="num">Sudah Dikirim</th>
      <th class="num">Tersedia</th><th>Verifikasi FG</th>
    </tr></thead>
    <tbody id="fg-source-rows">
    <?php if ($items === []): ?>
    <tr><td colspan="12"><?= ui_empty_state('Belum ada item produksi Khusus/Non-Toko untuk diverifikasi', 'Item akan muncul di sini setelah Aktual Produksi diisi pada Task per Divisi.') ?></td></tr>
    <?php else: foreach ($items as $it): ?>
    <tr data-item-id="<?= (int) $it['itemId'] ?>">
      <td><?= ui_esc($it['orderNo']) ?></td>
      <td><?= ui_normalized_source_badge($it['normalizedSourceType'], $it['sourceLabel']) ?></td>
      <td><?= ui_esc((string) $it['storeOrCustomerName']) ?></td>
      <td><?= ui_esc($it['itemName']) ?></td>
      <td><span class="badge badge-primary"><?= ui_esc($it['divisionName']) ?></span></td>
      <td class="num"><?= ui_fmt_num($it['aktualProduksi']) ?></td>
      <td class="num"><?= ui_fmt_num($it['rejectProduksi']) ?></td>
      <td class="num fg-verified-cell"><?= ui_fmt_num($it['fgVerifiedQty']) ?></td>
      <td class="num"><?= ui_fmt_num($it['allocatedQty']) ?></td>
      <td class="num"><?= ui_fmt_num($it['shippedQty']) ?></td>
      <td class="num fg-available-cell"><?= ui_fmt_num($it['availableToVerify']) ?></td>
      <td>
        <div style="display:flex;gap:var(--space-2);align-items:center;">
          <input type="number" class="fg-verify-input" min="0" step="0.01" max="<?= ui_esc((string) $it['aktualProduksi']) ?>" value="<?= ui_esc((string) $it['fgVerifiedQty']) ?>" style="width:100px;">
          <button type="button" class="btn btn-primary btn-sm fg-verify-btn">Simpan</button>
        </div>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<script>
(function () {
  document.querySelectorAll('.fg-verify-btn').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var row = btn.closest('tr');
      var itemId = row.getAttribute('data-item-id');
      var input = row.querySelector('.fg-verify-input');
      var qty = parseFloat(input.value);
      if (isNaN(qty) || qty < 0) { Amor.toast('Qty FG terverifikasi tidak valid.', 'danger'); return; }
      btn.disabled = true;
      try {
        var data = await Amor.apiFetch('/api/special-orders/items/' + itemId + '/verify-fg', {
          method: 'POST',
          body: { fgVerifiedQty: qty },
        });
        Amor.toast('FG terverifikasi disimpan.', 'success');
        window.location.reload();
      } catch (err) {
        Amor.toast(err.message, 'danger');
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
</script>
