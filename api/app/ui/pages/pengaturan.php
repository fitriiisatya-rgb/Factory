<?php

declare(strict_types=1);
?>
<div class="grid-2">
  <div class="card">
    <div class="card-head"><h2 class="card-title">Akun</h2></div>
    <table class="data-table">
      <tbody>
        <tr><td style="width:160px;color:var(--text-muted);">Nama</td><td><?= ui_esc($ui['fullName'] !== '' ? $ui['fullName'] : $ui['username']) ?></td></tr>
        <tr><td style="color:var(--text-muted);">Username</td><td><?= ui_esc($ui['username']) ?></td></tr>
        <tr><td style="color:var(--text-muted);">Role</td><td><?php foreach ($ui['roles'] as $r): ?><?= ui_badge($r) ?> <?php endforeach; ?></td></tr>
      </tbody>
    </table>
    <form method="post" action="/api/auth/logout" style="margin-top:var(--space-4);" onsubmit="return false;">
      <button type="button" class="btn btn-danger" id="btn-logout">Logout</button>
    </form>
  </div>

  <div class="card">
    <div class="card-head"><h2 class="card-title">Tampilan</h2></div>
    <p style="color:var(--text-muted);font-size:var(--text-sm);">Tema tersimpan otomatis di perangkat ini (bukan data operasional).</p>
    <button type="button" class="btn btn-secondary" data-action="toggle-theme">Ganti Tema Gelap/Terang</button>
  </div>
</div>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Pabrik &amp; Tanggal Default</h2></div>
  <p style="color:var(--text-muted);font-size:var(--text-sm);">
    Pabrik dan tanggal yang dipilih di setiap halaman (<?= ui_esc($uiFactoryName) ?>, <?= ui_esc($uiTanggal) ?>) otomatis
    terbawa saat berpindah menu di sidebar, sehingga tidak perlu memilih ulang setiap kali.
  </p>
</div>

<script>
document.getElementById('btn-logout').addEventListener('click', async function () {
  var ok = await Amor.confirmModal({ title: 'Logout?', body: 'Anda akan keluar dari sesi ini.', confirmLabel: 'Ya, Logout' });
  if (!ok) return;
  try {
    await Amor.apiFetch('/api/auth/logout', { method: 'POST' });
  } catch (e) { /* logout errors are non-fatal — still redirect */ }
  location.href = '/api/_admin-login/';
});
</script>
