<?php

declare(strict_types=1);

/**
 * "Ringkasan" FG dashboard (owner request Oct 2026): per day/factory, the
 * qty from PO → division production → FG verified → packed, per division
 * and per product, with what needs attention first (production short of
 * the PO, not verified yet) and Stok Sisa (production above the PO).
 * Read-only. Shown as the first tab of FG & Packing, and to division
 * accounts (fg-ringkasan page) limited to their own division(s).
 *
 * @param int[]|null $divisionIds null = every division of the factory
 */
function ui_fg_ringkasan_data(PDO $pdo, string $tanggal, int $factoryId, ?array $divisionIds): array
{
    $divFilter = '';
    $params = [$tanggal, $factoryId, $tanggal, $tanggal, $factoryId, $tanggal, $factoryId];
    if ($divisionIds !== null) {
        if ($divisionIds === []) {
            return ['products' => [], 'divisions' => []];
        }
        $divFilter = ' AND d.division_id IN (' . implode(',', array_map('intval', $divisionIds)) . ')';
    }
    $stmt = $pdo->prepare(
        "SELECT p.product_id, p.name, d.name AS division_name, COALESCE(po.qty, 0) AS po, COALESCE(pr.qty, 0) AS prod,
                COALESCE(fg.ver, 0) AS ver, COALESCE(fg.packed, 0) AS packed,
                COALESCE(mv.made, 0) AS made, COALESCE(mv.handed, 0) AS handed
         FROM product p
         INNER JOIN division d ON d.division_id = p.division_id
         LEFT JOIN (SELECT pi.product_id, SUM(pi.po_awal + pi.po_revisi) AS qty FROM po_item pi
                    INNER JOIN po_batch b ON b.po_batch_id = pi.po_batch_id
                    WHERE b.tanggal = ? AND b.factory_id = ? GROUP BY pi.product_id) po ON po.product_id = p.product_id
         LEFT JOIN (SELECT it.product_id, SUM(it.aktual) AS qty FROM production_item it
                    INNER JOIN production_run r ON r.production_run_id = it.production_run_id
                    WHERE r.tanggal = ? AND r.status IN ('submitted','verified_fg') GROUP BY it.product_id) pr ON pr.product_id = p.product_id
         LEFT JOIN (SELECT fi.product_id, SUM(fi.qty) AS ver, SUM(fi.packed_qty) AS packed FROM fg_item fi
                    INNER JOIN fg_batch fb ON fb.fg_batch_id = fi.fg_batch_id
                    WHERE fb.tanggal = ? AND fb.factory_id = ? GROUP BY fi.product_id) fg ON fg.product_id = p.product_id
         LEFT JOIN (SELECT pi.product_id, SUM(pi.aktual) AS made,
                           SUM(CASE WHEN r.status IN ('submitted','verified_fg') THEN pi.aktual ELSE COALESCE(h.qty, 0) END) AS handed
                    FROM production_run r
                    INNER JOIN production_item pi ON pi.production_run_id = r.production_run_id
                    LEFT JOIN (SELECT ph.production_run_id, phi.product_id, SUM(phi.qty) AS qty
                               FROM production_handover ph INNER JOIN production_handover_item phi ON phi.production_handover_id = ph.production_handover_id
                               GROUP BY ph.production_run_id, phi.product_id) h ON h.production_run_id = r.production_run_id AND h.product_id = pi.product_id
                    WHERE r.tanggal = ? GROUP BY pi.product_id) mv ON mv.product_id = p.product_id
         WHERE d.factory_id = ? {$divFilter}
           AND (po.qty > 0 OR pr.qty > 0 OR fg.ver > 0 OR mv.made > 0)
         ORDER BY d.name, p.name"
    );
    $stmt->execute($params);
    $products = [];
    $divisions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $po = (float) $r['po'];
        $ver = (float) $r['ver'];
        $packed = (float) $r['packed'];
        $made = (float) $r['made'];
        $handed = (float) $r['handed'];
        // "Hasil produksi" for FG = what FG may verify: submitted runs in
        // full, open runs as handed over (reject is part of FG Verifikasi).
        $handed = max($handed, (float) $r['prod']);
        $made = max($made, $handed);
        $prod = $handed;
        $madeAll = $made;
        $state = $madeAll < $po - 0.01 ? 'kurang' : ($ver <= 0.0001 ? 'belum' : ($ver >= $prod - 0.01 ? 'selesai' : 'sebagian'));
        $pos = [
            'diProduksi' => max(0.0, $made - $handed),
            'diFgBelumVerifikasi' => max(0.0, $prod - $ver),
            'verifikasiBelumPacking' => max(0.0, $ver - $packed),
            'dipacking' => $packed,
            'diserahkan' => $handed,
            'sudahJadi' => $made,
        ];
        $products[] = $pos + [
            'id' => (int) $r['product_id'], 'name' => (string) $r['name'], 'division' => (string) $r['division_name'],
            'po' => $po, 'prod' => $prod, 'ver' => $ver, 'packed' => $packed,
            'sisa' => max(0.0, $madeAll - $po), 'short' => max(0.0, $po - $madeAll), 'state' => $state,
        ];
        $dv = &$divisions[(string) $r['division_name']];
        $dv ??= ['products' => 0, 'verifiedProducts' => 0, 'po' => 0.0, 'prod' => 0.0, 'ver' => 0.0, 'packed' => 0.0, 'sisa' => 0.0, 'diProduksi' => 0.0, 'diserahkan' => 0.0, 'sudahJadi' => 0.0];
        foreach (['diProduksi', 'diserahkan', 'sudahJadi'] as $k) {
            $dv[$k] += $pos[$k];
        }
        $dv['products']++;
        $dv['verifiedProducts'] += $ver > 0.0001 ? 1 : 0;
        $dv['po'] += $po;
        $dv['prod'] += $prod;
        $dv['ver'] += $ver;
        $dv['packed'] += $packed;
        $dv['sisa'] += max(0.0, $madeAll - $po);
        unset($dv);
    }
    return ['products' => $products, 'divisions' => $divisions];
}

/** @param int[]|null $divisionIds */
function ui_render_fg_ringkasan(PDO $pdo, string $tanggal, int $factoryId, ?array $divisionIds, ?string $verifyUrl): void
{
    ui_render_handover_inbox($pdo, $tanggal, $factoryId, $divisionIds);
    $data = ui_fg_ringkasan_data($pdo, $tanggal, $factoryId, $divisionIds);
    $products = $data['products'];
    if ($products === []) {
        echo ui_empty_state('Belum ada data', 'Belum ada PO atau hasil produksi untuk tanggal ini.');
        return;
    }
    $sum = static fn (string $k) => array_sum(array_column($products, $k));
    $pct = static fn (float $a, float $b) => $b > 0 ? (int) round($a * 100 / $b) : 0;
    $cnt = static fn (string $state) => count(array_filter($products, static fn ($p) => $p['state'] === $state));
    $verifiedProducts = count(array_filter($products, static fn ($p) => $p['ver'] > 0.0001));
    $sisaProducts = count(array_filter($products, static fn ($p) => $p['sisa'] > 0.0001));
    $total = count($products);
    $verPct = $pct($sum('ver'), $sum('prod'));
    // Attention first: short of PO, then not verified, then the rest.
    $order = ['kurang' => 0, 'belum' => 1, 'sebagian' => 2, 'selesai' => 3];
    usort($products, static fn ($a, $b) => [$order[$a['state']], $a['division'], $a['name']] <=> [$order[$b['state']], $b['division'], $b['name']]);
    $n = static fn (float $v) => ui_fmt_num($v);
    ?>
<style>
.rk{--rk-line:var(--border,#24304a)}
.rk-card{background:var(--surface,#151c2c);border:1px solid var(--rk-line);border-radius:14px;padding:12px 14px;margin-bottom:12px}
.rk-hero{display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap}.rk-hero b{font-size:22px}.rk-hero span{color:var(--text-muted);font-size:12.5px}
.rk-bar{flex:1;height:8px;border-radius:5px;background:var(--surface-2,#243049);overflow:hidden;min-width:60px}.rk-bar i{display:block;height:100%;background:var(--success,#22c55e)}
.rk-big .rk-bar{height:10px;margin:6px 0}
.rk-line{font-size:12.5px;color:var(--text-muted);margin-bottom:8px}.rk-line b{color:var(--text)}
.rk-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}.rk-stat{background:var(--bg,#0f1626);border:1px solid transparent;border-radius:9px;padding:7px 9px;text-align:left;color:inherit;font:inherit}
.rk-stat b{display:block;font-size:15px}.rk-stat small{color:var(--text-muted);font-size:11px}button.rk-stat{cursor:pointer;border-color:var(--primary,#3b82f6)}
.rk-alert{margin-top:6px;border-radius:8px;padding:6px 9px;font-size:12.5px;font-weight:600;background:rgba(239,68,68,.12);color:#f87171}.rk-alert.y{background:rgba(245,158,11,.12);color:#fbbf24}
.rk h3{font-size:14px;margin:0 0 6px;display:flex;justify-content:space-between;gap:8px}.rk h3 small{font-weight:500;color:var(--text-muted);font-size:11.5px}
.rk details{border-top:1px solid var(--rk-line)}.rk details:first-of-type{border-top:0}
.rk summary{list-style:none;display:flex;align-items:center;gap:8px;padding:7px 0;cursor:pointer}.rk summary::-webkit-details-marker{display:none}
.rk-dn{width:130px;font-weight:600;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.rk-dn small,.rk-pn small{display:block;font-weight:500;color:var(--text-muted);font-size:11px}
.rk-q{font-weight:700;font-size:13px;white-space:nowrap}.rk-q small{color:var(--text-muted);font-weight:500}
.rk-dd{font-size:12px;color:var(--text-muted);padding:0 0 8px}.rk-dd b{color:var(--text)}.rk-red{color:#f87171}.rk-yel{color:#fbbf24}
.rk-dot{width:9px;height:9px;border-radius:50%;flex:0 0 9px}.st-kurang .rk-dot{background:#ef4444}.st-belum .rk-dot{background:#f59e0b}.st-sebagian .rk-dot{background:#3b82f6}.st-selesai .rk-dot{background:#22c55e}
.rk-pn{flex:1;font-weight:600;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rk-tools{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px}.rk-tools input,.rk-tools select{background:var(--bg,#0f1626);color:inherit;border:1px solid var(--rk-line);border-radius:8px;padding:7px 9px;font-size:13px}.rk-tools input{flex:1;min-width:150px}
.rk-chips{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;margin-bottom:4px}.rk-chip{flex:0 0 auto;border:1px solid var(--rk-line);border-radius:999px;padding:4px 11px;font-size:12px;background:none;color:var(--text-muted);cursor:pointer}.rk-chip.on{border-color:var(--primary,#3b82f6);color:var(--primary,#60a5fa)}
.rk-more{display:block;width:100%;background:none;border:0;color:var(--primary,#60a5fa);font-weight:600;padding:8px 0 2px;cursor:pointer;font-size:13px}
.rk-sheet-back{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:900;display:none}.rk-sheet-back.open{display:block}
.rk-sheet{position:fixed;left:50%;transform:translateX(-50%);bottom:0;width:100%;max-width:720px;max-height:84vh;overflow:auto;background:var(--surface,#151c2c);border-radius:18px 18px 0 0;padding:6px 14px 16px;z-index:901;display:none}.rk-sheet.open{display:block}
.rk-grab{width:42px;height:4px;border-radius:3px;background:var(--rk-line);margin:4px auto 8px}.rk-sh-h{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}.rk-sh-h b{font-size:15px}.rk-x{background:none;border:0;color:var(--text-muted);font-size:24px;cursor:pointer}
.rk-sheet .rk-stats{grid-template-columns:repeat(3,1fr);margin-bottom:8px}
.rk-lr{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--rk-line)}.rk-ic{width:20px;height:20px;border-radius:50%;flex:0 0 20px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}
.rk-ic.ok{background:rgba(34,197,94,.2);color:#4ade80}.rk-ic.part{background:rgba(59,130,246,.2);color:#60a5fa}.rk-ic.no{background:var(--surface-2,#243049);color:var(--text-muted)}
.rk-lq{text-align:right;font-size:12.5px;white-space:nowrap}.rk-lq b{font-size:13px}.rk-lq small{display:block;color:var(--text-muted);font-size:11px}
@media(min-width:900px){.rk-cols{display:grid;grid-template-columns:5fr 7fr;gap:12px;align-items:start}.rk-dn{width:170px}}
@media(max-width:600px){.rk-stats{grid-template-columns:repeat(2,1fr)}.rk-dn{width:104px}}
</style>
<div class="rk">
  <div class="rk-card rk-big">
    <div class="rk-hero"><span>Terverifikasi FG</span><b><?= $n($sum('ver')) ?><span> / <?= $n($sum('prod')) ?> pcs · <?= $verPct ?>%</span></b></div>
    <div class="rk-bar"><i style="width:<?= max(1, min(100, $verPct)) ?>%"></i></div>
    <div class="rk-line"><b><?= $total ?> produk</b> · <?= $verifiedProducts ?> sudah masuk FG · <?= $total - $verifiedProducts ?> belum</div>
    <div class="rk-stats">
      <button type="button" class="rk-stat" data-rk-open><b><?= $total ?></b><small>Total produk ›</small></button>
      <div class="rk-stat"><b><?= $n($sum('po')) ?></b><small>Total PO (pcs)</small></div>
      <div class="rk-stat"><b><?= $n($sum('prod')) ?></b><small>Hasil produksi (pcs)</small></div>
      <div class="rk-stat"><b><?= $n($sum('packed')) ?></b><small>Dipacking (pcs)</small></div>
    </div>
    <?php if ($cnt('kurang') > 0): ?><div class="rk-alert">⚠ <?= $cnt('kurang') ?> produk produksi kurang dari PO — bagi per toko di Breakdown Toko</div><?php endif; ?>
    <?php if ($sisaProducts > 0): ?><div class="rk-alert y">+ <?= $sisaProducts ?> produk produksi lebih dari PO · Stok Sisa <?= $n($sum('sisa')) ?> pcs</div><?php endif; ?>
  </div>
  <?php
  $posTotal = $sum('diProduksi') + $sum('diFgBelumVerifikasi') + $sum('verifikasiBelumPacking') + $sum('dipacking');
  $seg = static fn (float $v) => $posTotal > 0 ? round($v * 100 / $posTotal, 1) : 0;
  ?>
  <div class="rk-card">
    <h3>Posisi barang hari ini <small>pcs</small></h3>
    <div class="rk-bar" style="display:flex;height:11px;margin:4px 0 8px;">
      <i style="width:<?= $seg($sum('diProduksi')) ?>%;background:#64748b"></i><i style="width:<?= $seg($sum('diFgBelumVerifikasi')) ?>%;background:#f59e0b"></i><i style="width:<?= $seg($sum('verifikasiBelumPacking')) ?>%;background:#3b82f6"></i><i style="width:<?= $seg($sum('dipacking')) ?>%;background:#22c55e"></i>
    </div>
    <div class="rk-stats">
      <div class="rk-stat"><b><?= $n($sum('diProduksi')) ?></b><small>&#9679; Masih di produksi</small></div>
      <div class="rk-stat"><b><?= $n($sum('diFgBelumVerifikasi')) ?></b><small style="color:#fbbf24">&#9679; Di FG, belum diverifikasi</small></div>
      <div class="rk-stat"><b><?= $n($sum('verifikasiBelumPacking')) ?></b><small style="color:#60a5fa">&#9679; Terverifikasi, belum dipacking</small></div>
      <div class="rk-stat"><b><?= $n($sum('dipacking')) ?></b><small style="color:#4ade80">&#9679; Dipacking</small></div>
    </div>
  </div>

  <div class="rk-cols">
  <div class="rk-card">
    <h3>Per Divisi <small>pcs terverifikasi / produksi</small></h3>
    <?php foreach ($data['divisions'] as $name => $d): $p = $pct($d['ver'], $d['prod']); ?>
    <details>
      <summary><span class="rk-dn"><?= ui_esc($name) ?><small><?= $d['verifiedProducts'] ?>/<?= $d['products'] ?> produk</small></span>
        <span class="rk-bar"><i style="width:<?= max(1, min(100, $p)) ?>%"></i></span>
        <span class="rk-q"><?= $n($d['ver']) ?><small>/<?= $n($d['prod']) ?></small></span></summary>
      <div class="rk-dd">Sudah jadi <b><?= $n($d['sudahJadi']) ?></b> · Masih di produksi <b><?= $n($d['diProduksi']) ?></b> · Diserahkan <b><?= $n($d['diserahkan']) ?></b><br>PO <b><?= $n($d['po']) ?></b> · Produksi <b><?= $n($d['prod']) ?></b> · Terverifikasi <b><?= $n($d['ver']) ?></b> · Dipacking <b><?= $n($d['packed']) ?></b><?= $d['sisa'] > 0.0001 ? ' · <span class="rk-yel">Stok Sisa ' . $n($d['sisa']) . '</span>' : '' ?></div>
    </details>
    <?php endforeach; ?>
  </div>

  <div class="rk-card" id="rk-products">
    <h3>Per Produk <small>produksi / PO</small></h3>
    <div class="rk-tools">
      <input type="search" class="rk-q-in" placeholder="Cari produk...">
      <?php if (count($data['divisions']) > 1): ?>
      <select class="rk-dv-in"><option value="">Semua divisi</option><?php foreach (array_keys($data['divisions']) as $name): ?><option><?= ui_esc($name) ?></option><?php endforeach; ?></select>
      <?php endif; ?>
    </div>
    <div class="rk-chips">
      <button type="button" class="rk-chip on" data-f="perhatian">Perlu perhatian</button>
      <button type="button" class="rk-chip" data-f="kurang">Kurang (<?= $cnt('kurang') ?>)</button>
      <button type="button" class="rk-chip" data-f="belum">Belum (<?= $cnt('belum') ?>)</button>
      <button type="button" class="rk-chip" data-f="sisa">Lebih (<?= $sisaProducts ?>)</button>
      <button type="button" class="rk-chip" data-f="">Semua (<?= $total ?>)</button>
    </div>
    <?php foreach ($products as $p): ?>
    <details class="rk-p st-<?= $p['state'] ?>" data-name="<?= ui_esc(mb_strtolower($p['name'])) ?>" data-div="<?= ui_esc($p['division']) ?>" data-state="<?= $p['state'] ?>" data-sisa="<?= $p['sisa'] > 0.0001 ? '1' : '0' ?>">
      <summary><span class="rk-dot"></span><span class="rk-pn"><?= ui_esc($p['name']) ?></span><span class="rk-q"><?= $n($p['prod']) ?><small>/<?= $n($p['po']) ?> PO</small></span></summary>
      <div class="rk-dd"><?= ui_esc($p['division']) ?> · Terverifikasi <b><?= $n($p['ver']) ?></b> · Dipacking <b><?= $n($p['packed']) ?></b>
        <?= $p['short'] > 0.0001 ? ' · <span class="rk-red">Produksi kurang ' . $n($p['short']) . ' — bagi per toko</span>' : '' ?>
        <?= $p['sisa'] > 0.0001 ? ' · <span class="rk-yel">Stok Sisa ' . $n($p['sisa']) . '</span>' : '' ?>
        <?= $verifyUrl !== null && $p['state'] !== 'selesai' ? ' · <a href="' . ui_esc($verifyUrl) . '">Verifikasi →</a>' : '' ?></div>
    </details>
    <?php endforeach; ?>
    <div class="rk-empty" style="display:none;color:var(--text-muted);font-size:12.5px;padding:8px 0;">Tidak ada produk untuk filter ini.</div>
    <button type="button" class="rk-more" style="display:none;"></button>
  </div>
  </div>

  <div class="rk-sheet-back"></div>
  <div class="rk-sheet" role="dialog" aria-label="Produk masuk FG">
    <div class="rk-grab"></div>
    <div class="rk-sh-h"><b>Produk masuk FG</b><button type="button" class="rk-x" aria-label="Tutup">&times;</button></div>
    <div class="rk-stats">
      <div class="rk-stat"><b><?= $total ?></b><small>Total produk</small></div>
      <div class="rk-stat"><b><?= $verifiedProducts ?></b><small>Sudah masuk FG</small></div>
      <div class="rk-stat"><b><?= $n($sum('ver')) ?></b><small>pcs masuk FG · <?= $n($sum('packed')) ?> dipacking</small></div>
    </div>
    <div class="rk-tools"><input type="search" class="rk-sq" placeholder="Cari produk..."></div>
    <div class="rk-chips">
      <button type="button" class="rk-chip on" data-sf="">Semua (<?= $total ?>)</button>
      <button type="button" class="rk-chip" data-sf="1">Sudah masuk (<?= $verifiedProducts ?>)</button>
      <button type="button" class="rk-chip" data-sf="0">Belum (<?= $total - $verifiedProducts ?>)</button>
    </div>
    <?php
    $byIn = $products;
    usort($byIn, static fn ($a, $b) => [$b['ver'] > 0.0001, $b['ver'], $a['name']] <=> [$a['ver'] > 0.0001, $a['ver'], $b['name']]);
    foreach ($byIn as $p):
        $ic = $p['ver'] <= 0.0001 ? 'no' : ($p['ver'] >= $p['prod'] - 0.01 ? 'ok' : 'part');
    ?>
    <div class="rk-lr" data-name="<?= ui_esc(mb_strtolower($p['name'])) ?>" data-in="<?= $p['ver'] > 0.0001 ? '1' : '0' ?>">
      <span class="rk-ic <?= $ic ?>"><?= ['ok' => '&#10003;', 'part' => '&#9680;', 'no' => '&ndash;'][$ic] ?></span>
      <span class="rk-pn"><?= ui_esc($p['name']) ?><small><?= ui_esc($p['division']) ?></small></span>
      <span class="rk-lq"><b><?= $n($p['ver']) ?></b>/<?= $n($p['prod']) ?> pcs<small>dipacking <?= $n($p['packed']) ?></small></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<script>
(function () {
  var root = document.querySelector('.rk');
  if (!root) return;
  var box = document.getElementById('rk-products');
  var rows = Array.prototype.slice.call(box.querySelectorAll('.rk-p'));
  var more = box.querySelector('.rk-more'), empty = box.querySelector('.rk-empty');
  var filter = 'perhatian', showAll = false, LIMIT = 10;
  function apply() {
    var q = (box.querySelector('.rk-q-in').value || '').toLowerCase().trim();
    var dvSel = box.querySelector('.rk-dv-in'); var dv = dvSel ? dvSel.value : '';
    var shown = 0, matched = 0;
    rows.forEach(function (r) {
      var st = r.getAttribute('data-state');
      var ok = (!q || r.getAttribute('data-name').indexOf(q) !== -1) && (!dv || r.getAttribute('data-div') === dv)
        && (filter === '' || (filter === 'perhatian' ? (st === 'kurang' || st === 'belum' || st === 'sebagian') : filter === 'sisa' ? r.getAttribute('data-sisa') === '1' : st === filter));
      if (ok) matched++;
      var vis = ok && (showAll || q !== '' || shown < LIMIT);
      if (vis) shown++;
      r.style.display = vis ? '' : 'none';
    });
    empty.style.display = matched === 0 ? '' : 'none';
    more.style.display = matched > shown ? '' : 'none';
    more.textContent = 'Lihat ' + (matched - shown) + ' produk lainnya';
  }
  box.querySelectorAll('[data-f]').forEach(function (c) {
    c.addEventListener('click', function () {
      box.querySelectorAll('[data-f]').forEach(function (x) { x.classList.toggle('on', x === c); });
      filter = c.getAttribute('data-f'); showAll = false; apply();
    });
  });
  box.querySelector('.rk-q-in').addEventListener('input', apply);
  var dvSel = box.querySelector('.rk-dv-in'); if (dvSel) dvSel.addEventListener('change', apply);
  more.addEventListener('click', function () { showAll = true; apply(); });
  apply();

  var back = root.querySelector('.rk-sheet-back'), sheet = root.querySelector('.rk-sheet'), sf = '';
  var lrows = Array.prototype.slice.call(sheet.querySelectorAll('.rk-lr'));
  function sheetApply() {
    var q = (sheet.querySelector('.rk-sq').value || '').toLowerCase().trim();
    lrows.forEach(function (r) { r.style.display = (!q || r.getAttribute('data-name').indexOf(q) !== -1) && (sf === '' || r.getAttribute('data-in') === sf) ? '' : 'none'; });
  }
  function openSheet(o) { back.classList.toggle('open', o); sheet.classList.toggle('open', o); document.body.style.overflow = o ? 'hidden' : ''; }
  root.querySelector('[data-rk-open]').addEventListener('click', function () { openSheet(true); });
  back.addEventListener('click', function () { openSheet(false); });
  sheet.querySelector('.rk-x').addEventListener('click', function () { openSheet(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') openSheet(false); });
  sheet.querySelectorAll('[data-sf]').forEach(function (c) {
    c.addEventListener('click', function () {
      sheet.querySelectorAll('[data-sf]').forEach(function (x) { x.classList.toggle('on', x === c); });
      sf = c.getAttribute('data-sf'); sheetApply();
    });
  });
  sheet.querySelector('.rk-sq').addEventListener('input', sheetApply);
})();
</script>
    <?php
}


/**
 * "Serah terima masuk": what divisions handed over today, newest first.
 * It counts for FG at once; checking and reject are done in FG Verifikasi.
 * @param int[]|null $divisionIds
 */
function ui_render_handover_inbox(PDO $pdo, string $tanggal, int $factoryId, ?array $divisionIds): void
{
    $list = (new \Amor\Api\Production\HandoverService($pdo))->listForDay($tanggal, $factoryId, $divisionIds);
    if ($list === []) {
        return;
    }
    $n = static fn (float $v) => ui_fmt_num($v);
    $total = array_sum(array_column($list, 'totalQty'));
    $shown = array_slice(array_reverse($list), 0, 3);
    $rest = array_slice(array_reverse($list), 3);
    $row = static function (array $h) use ($n): string {
        $items = implode(' · ', array_map(static fn ($it) => $it['productName'] . ' ' . $n($it['qty']), $h['items']));
        return '<div style="padding:7px 0;border-top:1px solid var(--border,#24304a)"><b>' . ui_esc($h['divisionName']) . ' #' . (int) $h['seq'] . '</b>'
            . ' <small style="color:var(--text-muted)">· ' . ui_esc(ui_fmt_datetime_id((string) $h['createdAt'])) . ($h['createdBy'] ? ' · ' . ui_esc((string) $h['createdBy']) : '') . ' · ' . $n($h['totalQty']) . ' pcs</small>'
            . '<br><small style="color:var(--text-muted)">' . ui_esc($items) . '</small></div>';
    };
    ?>
<div class="rk-card" id="ho-inbox" style="background:var(--surface,#151c2c);border:1px solid var(--border,#24304a);border-radius:14px;padding:12px 14px;margin-bottom:12px">
  <h3 style="font-size:14px;margin:0 0 6px;display:flex;justify-content:space-between">Serah terima masuk dari produksi <small style="font-weight:500;color:var(--text-muted)"><?= count($list) ?>× · <?= $n($total) ?> pcs</small></h3>
  <p style="font-size:12.5px;color:var(--text-muted);margin:0 0 4px">Barang yang diserahkan langsung bisa diverifikasi di FG Verifikasi (cek &amp; reject di sana).</p>
  <?php foreach ($shown as $h) { echo $row($h); } ?>
  <?php if ($rest !== []): ?>
  <details style="border-top:1px solid var(--border,#24304a)"><summary style="cursor:pointer;padding:7px 0;font-size:13px;color:var(--text-muted)">Lihat <?= count($rest) ?> lainnya</summary>
    <?php foreach ($rest as $h) { echo $row($h); } ?>
  </details>
  <?php endif; ?>
</div>
    <?php
}
