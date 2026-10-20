<?php

declare(strict_types=1);

/**
 * Print DO per divisi (owner request Oct 2026): some divisions ship first,
 * so the planning DO can be printed for chosen division(s) and chosen
 * store(s) — still one page per store, only that division's products,
 * labelled "BAGIAN: <divisi>", QR kept (it points at the same DO; the store
 * confirms only what actually arrived). Print-only: shipping is already
 * recorded per product by the driver, never by this document.
 */

/**
 * Per DO of the date/factory: store name and, per division, product count
 * and planned pcs; plus the per-division totals.
 * @return array{divisions:array<string,array{products:int,pcs:float,stores:int}>,dos:array<int,array{store:string,divs:array<string,array{n:int,pcs:float}>}>}
 */
function ui_do_division_map(PDO $pdo, string $tanggal, int $factoryId): array
{
    $stmt = $pdo->prepare(
        "SELECT o.delivery_order_id, s.canonical_name AS store_name, d.name AS division_name, COUNT(*) AS n, SUM(oi.planned_qty) AS pcs
         FROM delivery_order o
         INNER JOIN store s ON s.store_id = o.store_id
         INNER JOIN delivery_order_item oi ON oi.delivery_order_id = o.delivery_order_id
         INNER JOIN product p ON p.product_id = oi.product_id
         INNER JOIN division d ON d.division_id = p.division_id
         WHERE o.tanggal = ? AND o.status <> 'cancelled'
           AND o.delivery_order_id IN (SELECT oi2.delivery_order_id FROM delivery_order_item oi2
                INNER JOIN product p2 ON p2.product_id = oi2.product_id INNER JOIN division d2 ON d2.division_id = p2.division_id WHERE d2.factory_id = ?)
         GROUP BY o.delivery_order_id, s.canonical_name, d.name
         ORDER BY s.canonical_name, d.name"
    );
    $stmt->execute([$tanggal, $factoryId]);
    $dos = [];
    $divisions = [];
    $productsPerDiv = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int) $r['delivery_order_id'];
        $dv = (string) $r['division_name'];
        $dos[$id]['store'] = (string) $r['store_name'];
        $dos[$id]['divs'][$dv] = ['n' => (int) $r['n'], 'pcs' => (float) $r['pcs']];
        $divisions[$dv] ??= ['products' => 0, 'pcs' => 0.0, 'stores' => 0];
        $divisions[$dv]['pcs'] += (float) $r['pcs'];
        $divisions[$dv]['stores']++;
    }
    $p = $pdo->prepare(
        "SELECT d.name, COUNT(DISTINCT oi.product_id) FROM delivery_order o
         INNER JOIN delivery_order_item oi ON oi.delivery_order_id = o.delivery_order_id
         INNER JOIN product p ON p.product_id = oi.product_id INNER JOIN division d ON d.division_id = p.division_id
         WHERE o.tanggal = ? AND o.status <> 'cancelled' AND d.factory_id = ? GROUP BY d.name"
    );
    $p->execute([$tanggal, $factoryId]);
    foreach ($p->fetchAll(PDO::FETCH_NUM) as [$name, $count]) {
        if (isset($divisions[$name])) {
            $divisions[$name]['products'] = (int) $count;
        }
    }
    ksort($divisions);
    return ['divisions' => $divisions, 'dos' => $dos];
}

/**
 * Keeps only the items of $divisions in a DoService::getDo() DTO and
 * recomputes its totals. Returns null when nothing is left.
 * @param string[] $divisions
 */
function ui_do_filter_divisions(array $do, array $divisions): ?array
{
    $items = array_values(array_filter($do['items'], static fn ($it) => in_array((string) $it['divisionName'], $divisions, true)));
    if ($items === []) {
        return null;
    }
    $do['items'] = $items;
    $do['summary']['totalPlanned'] = array_sum(array_map(static fn ($it) => (float) $it['plannedQty'], $items));
    $do['summary']['totalShipped'] = array_sum(array_map(static fn ($it) => (float) $it['alreadyShippedQty'], $items));
    $do['summary']['totalRemaining'] = array_sum(array_map(static fn ($it) => (float) $it['remainingToShip'], $items));
    return $do;
}

/** "Pastry (12 produk), Roti & Bollen (11 produk)" — the divisions of $do not on this page. */
function ui_do_other_divisions_note(array $do, array $printed): ?string
{
    $other = [];
    foreach ($do['items'] as $it) {
        $dv = (string) $it['divisionName'];
        if (!in_array($dv, $printed, true)) {
            $other[$dv] = ($other[$dv] ?? 0) + 1;
        }
    }
    if ($other === []) {
        return null;
    }
    ksort($other);
    $parts = [];
    foreach ($other as $dv => $n) {
        $parts[] = $dv . ' (' . $n . ' produk)';
    }
    return 'Divisi lain untuk toko ini dicetak terpisah: ' . implode(', ', $parts) . '. Total DO lengkap: ' . count($do['items']) . ' produk.';
}

/** The "Print per Divisi" button + chooser (divisions, all/some stores, options). */
function ui_render_do_split_print(PDO $pdo, string $tanggal, int $factoryId, string $buttonClass = 'btn btn-secondary'): void
{
    $map = ui_do_division_map($pdo, $tanggal, $factoryId);
    if ($map['dos'] === []) {
        return;
    }
    $payload = ['tanggal' => $tanggal, 'factoryId' => $factoryId, 'divisions' => $map['divisions'], 'dos' => []];
    foreach ($map['dos'] as $id => $d) {
        $payload['dos'][] = ['id' => $id, 'store' => $d['store'], 'divs' => $d['divs']];
    }
    ?>
<button type="button" class="<?= ui_esc($buttonClass) ?>" id="btn-do-split-print">&#128424; Print per Divisi</button>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var D = <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var btn = document.getElementById('btn-do-split-print');
  if (!btn) return;
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function num(n) { return (Math.round((Number(n) || 0) * 100) / 100).toLocaleString('id-ID'); }
  btn.addEventListener('click', function () {
    var names = Object.keys(D.divisions);
    var back = document.createElement('div');
    back.className = 'modal-backdrop open';
    back.innerHTML = '<div class="modal" style="max-width:560px;max-height:92vh;overflow:auto">'
      + '<div class="modal-title">Print DO per Divisi</div>'
      + '<div style="font-weight:700;font-size:13px;margin:4px 0 6px">1. Divisi</div>'
      + '<div class="dsp-divs" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:6px">'
      + names.map(function (n) { var d = D.divisions[n]; return '<label style="display:flex;align-items:center;gap:8px;padding:8px;border:1px solid var(--border);border-radius:10px;cursor:pointer"><input type="checkbox" value="' + esc(n) + '"><span><b style="font-size:13px">' + esc(n) + '</b><br><small style="color:var(--text-muted)">' + d.products + ' produk · ' + d.stores + ' toko · ' + num(d.pcs) + ' pcs</small></span></label>'; }).join('')
      + '</div>'
      + '<div style="font-weight:700;font-size:13px;margin:12px 0 6px">2. Toko</div>'
      + '<div style="display:flex;gap:6px;margin-bottom:6px;flex-wrap:wrap">'
      + '<label style="flex:1;min-width:180px;display:flex;gap:8px;align-items:center;padding:8px;border:1px solid var(--border);border-radius:10px;cursor:pointer"><input type="radio" name="dsp-mode" value="all" checked><span><b style="font-size:13px">Semua toko</b><br><small style="color:var(--text-muted)">' + D.dos.length + ' toko yang punya DO</small></span></label>'
      + '<label style="flex:1;min-width:180px;display:flex;gap:8px;align-items:center;padding:8px;border:1px solid var(--border);border-radius:10px;cursor:pointer"><input type="radio" name="dsp-mode" value="some"><span><b style="font-size:13px">Pilih toko tertentu</b><br><small class="dsp-some-sub" style="color:var(--text-muted)">centang toko yang mau dicetak</small></span></label></div>'
      + '<div class="dsp-stores" style="display:none;border:1px solid var(--border);border-radius:10px;padding:8px">'
      + '<div style="display:flex;gap:6px;margin-bottom:4px;flex-wrap:wrap"><input type="search" class="dsp-q" placeholder="Cari toko..." style="flex:1;min-width:140px;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:6px 8px;color:inherit;font-size:13px">'
      + '<button type="button" class="btn btn-secondary btn-sm dsp-all">Pilih semua</button><button type="button" class="btn btn-secondary btn-sm dsp-none">Kosongkan</button></div>'
      + '<div style="max-height:260px;overflow:auto">' + D.dos.map(function (o) { return '<label class="dsp-store" data-name="' + esc(o.store.toLowerCase()) + '" style="display:flex;align-items:center;gap:8px;padding:6px 4px;border-bottom:1px solid var(--border);font-size:13px;cursor:pointer"><input type="checkbox" value="' + o.id + '"><span style="flex:1">' + esc(o.store) + '</span><small class="dsp-cnt" data-id="' + o.id + '" style="color:var(--text-muted)"></small></label>'; }).join('') + '</div></div>'
      + '<div style="font-weight:700;font-size:13px;margin:12px 0 4px">3. Opsi</div>'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13px;margin:2px 0"><input type="checkbox" class="dsp-skip" checked> Lewati toko yang tidak punya produk divisi ini</label>'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13px;margin:2px 0 10px"><input type="checkbox" class="dsp-split"> Pisahkan tiap divisi di halaman sendiri</label>'
      + '<div class="modal-actions"><button type="button" class="btn btn-secondary dsp-cancel">Batal</button><button type="button" class="btn btn-primary dsp-go" disabled>Pilih divisi dulu</button></div></div>';
    document.body.appendChild(back);
    var $ = function (s) { return back.querySelector(s); };
    function selDivs() { return Array.prototype.map.call(back.querySelectorAll('.dsp-divs input:checked'), function (i) { return i.value; }); }
    function mode() { return back.querySelector('input[name="dsp-mode"]:checked').value; }
    function selStores() { return Array.prototype.map.call(back.querySelectorAll('.dsp-store input:checked'), function (i) { return parseInt(i.value, 10); }); }
    function pages() {
      var divs = selDivs(), split = $('.dsp-split').checked, skip = $('.dsp-skip').checked;
      var ids = mode() === 'all' ? D.dos.map(function (o) { return o.id; }) : selStores();
      var total = 0;
      D.dos.forEach(function (o) {
        if (ids.indexOf(o.id) === -1) return;
        var hit = divs.filter(function (d) { return o.divs[d]; }).length;
        if (split) total += hit > 0 ? hit : (skip ? 0 : 1); else total += hit > 0 || !skip ? 1 : 0;
      });
      return total;
    }
    function refresh() {
      var divs = selDivs();
      back.querySelectorAll('.dsp-divs label').forEach(function (l) { var on = l.querySelector('input').checked; l.style.borderColor = on ? '#3b82f6' : ''; l.style.background = on ? 'rgba(59,130,246,.08)' : ''; });
      back.querySelectorAll('.dsp-cnt').forEach(function (c) {
        var o = D.dos.filter(function (x) { return String(x.id) === c.getAttribute('data-id'); })[0];
        var n = divs.reduce(function (a, d) { return a + (o.divs[d] ? o.divs[d].n : 0); }, 0);
        c.textContent = divs.length ? n + ' produk' : '';
      });
      $('.dsp-stores').style.display = mode() === 'some' ? '' : 'none';
      $('.dsp-some-sub').textContent = mode() === 'some' ? selStores().length + ' toko dipilih' : 'centang toko yang mau dicetak';
      var go = $('.dsp-go'), p = pages();
      if (!divs.length) { go.disabled = true; go.textContent = 'Pilih divisi dulu'; }
      else if (mode() === 'some' && !selStores().length) { go.disabled = true; go.textContent = 'Pilih toko dulu'; }
      else { go.disabled = p === 0; go.textContent = p === 0 ? 'Tidak ada yang dicetak' : 'Print (' + p + ' halaman)'; }
    }
    back.addEventListener('change', refresh);
    $('.dsp-q').addEventListener('input', function () { var q = this.value.toLowerCase().trim(); back.querySelectorAll('.dsp-store').forEach(function (l) { l.style.display = !q || l.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none'; }); });
    $('.dsp-all').addEventListener('click', function () { back.querySelectorAll('.dsp-store').forEach(function (l) { if (l.style.display !== 'none') l.querySelector('input').checked = true; }); refresh(); });
    $('.dsp-none').addEventListener('click', function () { back.querySelectorAll('.dsp-store input').forEach(function (i) { i.checked = false; }); refresh(); });
    $('.dsp-cancel').addEventListener('click', function () { back.remove(); });
    back.addEventListener('click', function (e) { if (e.target === back) back.remove(); });
    $('.dsp-go').addEventListener('click', function () {
      var qs = 'tanggal=' + encodeURIComponent(D.tanggal) + '&factoryId=' + D.factoryId;
      selDivs().forEach(function (d) { qs += '&div[]=' + encodeURIComponent(d); });
      if (mode() === 'some') selStores().forEach(function (id) { qs += '&do[]=' + id; });
      if ($('.dsp-split').checked) qs += '&split=1';
      if (!$('.dsp-skip').checked) qs += '&keepEmpty=1';
      window.open('/api/_ui-preview/print-do-bulk.php?' + qs, '_blank');
      back.remove();
    });
    refresh();
  });
});
</script>
    <?php
}
