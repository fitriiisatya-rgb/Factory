<?php

declare(strict_types=1);

use Amor\Api\Delivery\ReconciliationService;

/**
 * Rekonsiliasi Kirim vs FG (masa adaptasi). Admin may press Kirim before FG
 * has packed in the system (Pengaturan → "Kirim tanpa menunggu FG"); FG
 * still enters its own real result. This page lines both up per product
 * and per store, next to what the store / Admin confirmed as received.
 * Read-only: invoices follow the receipt; this page only shows where the
 * Kirim figures and the FG figures disagree.
 */

$data = (new ReconciliationService($pdo))->forDay($uiTanggal, $uiFactoryId);
$onlyDiff = ($_GET['beda'] ?? '') === '1';
$labels = [
    'cocok' => ['Cocok', 'success'],
    'kirim_lebih' => ['Kirim lebih dari packing FG', 'danger'],
    'fg_lebih' => ['Packing FG lebih dari Kirim', 'warning'],
    'fg_belum_selesai' => ['FG belum Submit', 'neutral'],
    'fg_belum_input' => ['FG belum input', 'neutral'],
    'lihat_total' => ['FG per produk — lihat total', 'neutral'],
];
$badge = static fn (string $s) => '<span class="badge badge-' . $labels[$s][1] . '">' . ui_esc($labels[$s][0]) . '</span>';
$rows = $onlyDiff ? array_values(array_filter($data['rows'], static fn ($r) => in_array($r['status'], ['kirim_lebih', 'fg_lebih'], true))) : $data['rows'];
$products = $onlyDiff ? array_values(array_filter($data['products'], static fn ($p) => in_array($p['status'], ['kirim_lebih', 'fg_lebih'], true))) : $data['products'];
$diffCount = count(array_filter($data['products'], static fn ($p) => in_array($p['status'], ['kirim_lebih', 'fg_lebih'], true)));
$num = static fn (?float $v) => $v === null ? '-' : ui_fmt_num($v);
?>
<?= ui_do_tabs('rekonsiliasi-kirim', $uiTanggal, $uiFactoryId) ?>

<div class="card section">
  <form method="get" class="filter-bar" style="flex-wrap:wrap;gap:var(--space-3);align-items:flex-end;">
    <input type="hidden" name="page" value="rekonsiliasi-kirim">
    <div class="field"><label>Tanggal</label><input type="date" name="tanggal" value="<?= ui_esc($uiTanggal) ?>"></div>
    <?php if (count($factories) > 1): ?>
    <div class="field"><label>Pabrik</label>
      <select name="factoryId"><?php foreach ($factories as $f): ?><option value="<?= (int) $f['factory_id'] ?>" <?= $uiFactoryId === (int) $f['factory_id'] ? 'selected' : '' ?>><?= ui_esc($f['name']) ?></option><?php endforeach; ?></select>
    </div>
    <?php else: ?><input type="hidden" name="factoryId" value="<?= (int) $uiFactoryId ?>"><?php endif; ?>
    <label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" name="beda" value="1" <?= $onlyDiff ? 'checked' : '' ?>> Hanya yang beda</label>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>
  <p style="color:var(--text-muted);font-size:var(--text-sm);margin-top:var(--space-3);">
    <b>Kirim</b> = yang ditekan Kirim oleh Admin. <b>Packing FG</b> = yang diinput FG di sistem.
    <b>Diterima</b> = konfirmasi penerimaan (dari toko atau diisi Admin dari surat jalan) — invoice selalu mengikuti angka Diterima Baik.
    Kalau Kirim lebih besar dari Packing FG, cek fisik: biasanya Qty Kirim salah ketik atau FG salah hitung; catat selisihnya di konfirmasi penerimaan (Kurang) bila barang memang tidak sampai.
  </p>
  <?php if ($data['fgStatus'] !== 'submitted'): ?>
  <div class="alert alert-info">FG pabrik ini untuk tanggal tersebut <?= $data['fgStatus'] === null ? 'belum diinput' : 'belum Submit' ?> — rekonsiliasi baru final setelah FG Submit.</div>
  <?php elseif ($diffCount === 0 && $data['products'] !== []): ?>
  <div class="alert alert-success">Semua produk cocok: jumlah Kirim sama dengan Packing FG.</div>
  <?php elseif ($diffCount > 0): ?>
  <div class="alert alert-warning"><?= $diffCount ?> produk berbeda antara Kirim dan Packing FG. Lihat rinciannya di bawah.</div>
  <?php endif; ?>
</div>

<?php if ($data['products'] === []): ?>
<?= ui_empty_state('Belum ada data', 'Belum ada Kirim maupun Packing FG untuk tanggal & pabrik ini.') ?>
<?php else: ?>
<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-3);">Per produk (total semua toko)</h3>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Produk</th><th class="num">Kirim</th><th class="num">Packing FG</th><th class="num">Selisih</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): ?>
      <tr><td><?= ui_esc($p['productName']) ?></td><td class="num"><?= $num($p['kirim']) ?></td><td class="num"><?= $num($p['fg']) ?></td>
        <td class="num"><?= abs($p['selisih']) > 0.0001 ? '<strong>' . ($p['selisih'] > 0 ? '+' : '') . ui_fmt_num($p['selisih']) . '</strong>' : '0' ?></td><td><?= $badge($p['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-3);">Per toko</h3>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Toko</th><th>Produk</th><th class="num">Kirim</th><th class="num">Packing FG</th><th class="num">Selisih</th><th class="num">Diterima Baik</th><th class="num">Reject</th><th class="num">Kurang</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= ui_esc($r['storeName']) ?></td><td><?= ui_esc($r['productName']) ?></td>
        <td class="num"><?= $num($r['kirim']) ?></td><td class="num"><?= $num($r['fg']) ?></td>
        <td class="num"><?= $r['selisih'] === null ? '-' : (abs($r['selisih']) > 0.0001 ? '<strong>' . ($r['selisih'] > 0 ? '+' : '') . ui_fmt_num($r['selisih']) . '</strong>' : '0') ?></td>
        <?php if ($r['kirim'] > 0 && $r['confirmed']): ?>
        <td class="num"><?= ui_fmt_num($r['good']) ?></td><td class="num"><?= ui_fmt_num($r['reject']) ?></td><td class="num"><?= ui_fmt_num($r['shortage']) ?></td>
        <?php else: ?>
        <td class="num" colspan="3" style="color:var(--text-faint);"><?= $r['kirim'] > 0 ? 'belum dikonfirmasi' : 'belum dikirim' ?></td>
        <?php endif; ?>
        <td><?= $badge($r['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
