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

<?php if (in_array('ADMIN', $ui['roles'], true) && !\Amor\Api\Auth::isFactoryAdmin()): ?>
<?php $storeEmailOn = \Amor\Api\System\StoreEmailSwitch::enabled($pdo); ?>
<div class="card section">
  <div class="card-head"><h2 class="card-title">Email Surat Jalan ke Toko</h2><?= ui_badge($storeEmailOn ? 'Aktif' : 'Dibekukan') ?></div>
  <p style="color:var(--text-muted);font-size:var(--text-sm);">
    <?= $storeEmailOn
        ? 'Setiap kali Admin menekan Kirim, toko yang emailnya terisi menerima email surat jalan.'
        : 'Masa adaptasi: email surat jalan ke toko TIDAK dikirim. Email toko di Master Data tetap tersimpan. Saat diaktifkan lagi, kiriman lama tidak dikirim ulang otomatis.' ?>
    Notifikasi error ke Admin tidak terpengaruh.
  </p>
  <button type="button" class="btn <?= $storeEmailOn ? 'btn-secondary' : 'btn-primary' ?>" id="btn-store-email" data-enable="<?= $storeEmailOn ? '0' : '1' ?>"><?= $storeEmailOn ? 'Bekukan Email ke Toko' : 'Aktifkan Email ke Toko' ?></button>
</div>
<script>
(function () {
  var b = document.getElementById('btn-store-email');
  b.addEventListener('click', async function () {
    var on = b.dataset.enable === '1';
    var ok = await Amor.confirmModal({
      title: on ? 'Aktifkan email ke toko?' : 'Bekukan email ke toko?',
      body: on ? 'Mulai kiriman berikutnya, toko yang emailnya terisi akan menerima email surat jalan.' : 'Kiriman berikutnya tidak akan mengirim email ke toko sampai diaktifkan lagi.',
      confirmLabel: on ? 'Ya, Aktifkan' : 'Ya, Bekukan',
    });
    if (!ok) return;
    try {
      await Amor.apiFetch('/api/admin/system/store-email', { method: 'POST', body: { enabled: on } });
      window.location.reload();
    } catch (e) { Amor.toast(e.message, 'error'); }
  });
})();
</script>
<div class="card section">
  <div class="card-head"><h2 class="card-title">Sistem</h2></div>
  <p style="color:var(--text-muted);font-size:var(--text-sm);">Backup database (unduh untuk disimpan di luar server) dan catatan error.</p>
  <a class="btn btn-secondary" href="/api/_ui-preview/?page=sistem">Buka Sistem</a>
</div>
<div class="card section">
  <div class="card-head"><h2 class="card-title">Integrasi finhub</h2></div>
  <p style="color:var(--text-muted);font-size:var(--text-sm);">Kunci agar finhub bisa membaca data kiriman dan konfirmasi toko (hanya baca).</p>
  <a class="btn btn-secondary" href="/api/_ui-preview/?page=integrasi">Buka Integrasi Finhub</a>
</div>
<div class="card section">
  <div class="card-head"><h2 class="card-title">Mapping Runchise</h2></div>
  <p style="color:var(--text-muted);font-size:var(--text-sm);">Katalog produk Runchise, lokasi tiap toko, dan file stok masuk per surat jalan yang sudah dikonfirmasi.</p>
  <a class="btn btn-secondary" href="/api/_ui-preview/?page=runchise">Buka Mapping Runchise</a>
</div>
<?php endif; ?>

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
