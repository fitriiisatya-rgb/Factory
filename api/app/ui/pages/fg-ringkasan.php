<?php

declare(strict_types=1);

/**
 * "Ringkasan FG" for division accounts: the same read-only dashboard as
 * the FG & Packing "Ringkasan" tab, limited to the account's own
 * division(s) and their factory. ADMIN/PPIC/FG see the whole factory.
 */

require_once __DIR__ . '/../fg-ringkasan.php';

$isWide = array_intersect(['ADMIN', 'PPIC', 'FG_PACKING'], $ui['roles']) !== [];
$divisionIds = $isWide ? null : \Amor\Api\Auth::currentDivisionIds();
$factoryId = $uiFactoryId;
if (!$isWide) {
    if ($divisionIds === []) {
        echo ui_empty_state('Belum ada divisi', 'Akun ini belum terhubung ke divisi mana pun. Minta Admin mengaturnya di Manajemen User.');
        return;
    }
    // A division account always looks at its own division's factory.
    $f = $pdo->prepare('SELECT factory_id FROM division WHERE division_id = ?');
    $f->execute([(int) $divisionIds[0]]);
    $factoryId = (int) ($f->fetchColumn() ?: $uiFactoryId);
}
?>
<div class="filter-bar">
  <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="fg-ringkasan">
    <div class="field"><label>Tanggal</label><input type="date" name="tanggal" value="<?= ui_esc($uiTanggal) ?>"></div>
    <?php if ($isWide): ?>
    <div class="field"><label>Pabrik</label>
      <select name="factoryId">
        <?php foreach ($factories as $fa): ?>
        <option value="<?= (int) $fa['factory_id'] ?>" <?= $factoryId === (int) $fa['factory_id'] ? 'selected' : '' ?>><?= ui_esc($fa['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">Terapkan</button>
  </form>
</div>
<?php ui_render_fg_ringkasan($pdo, $uiTanggal, $factoryId, $divisionIds, null);
