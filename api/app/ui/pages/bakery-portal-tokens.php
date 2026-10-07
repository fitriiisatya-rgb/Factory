<?php

declare(strict_types=1);

/**
 * Admin "Portal Bakery" token management (migration 0017) — generate/
 * regenerate/revoke the ONE permanent per-store portal link, read-
 * rendered server-side via a plain store list (no business logic here);
 * every mutating action goes through the real StorePortalService via
 * Amor.apiFetch, same discipline as every other admin page.
 *
 * Defense-in-depth role gate (see replacement-reject.php's own docblock
 * for why this exists alongside the router's own check) — token
 * management is more sensitive than most admin actions (a leaked link
 * is a standing, no-login credential), so this is ADMIN-only, never PPIC.
 */

if (array_intersect(['ADMIN'], $ui['roles']) === []) {
    echo ui_empty_state('Akses Ditolak', 'Anda tidak memiliki izin untuk mengakses halaman Portal Bakery.');
    return;
}

$stores = $pdo->query('SELECT store_id, canonical_name, active FROM store WHERE active = 1 ORDER BY canonical_name')->fetchAll();
?>
<div class="card section">
  <h3 class="card-title" style="margin-bottom:var(--space-2);">Link Permanen Portal Bakery</h3>
  <p style="color:var(--text-faint);font-size:.85rem;margin-bottom:var(--space-3);">
    Satu link permanen per toko/bakery, tanpa login — akses dikontrol murni oleh token acak di link tersebut.
    <strong>Generate</strong> membuat link baru (atau <strong>Regenerate</strong> jika sudah ada — link lama langsung tidak berlaku).
    Link hanya ditampilkan SEKALI saat dibuat — salin segera, server tidak pernah menyimpan nilai aslinya.
  </p>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Toko</th><th>Status Link</th><th>Terakhir Dipakai</th><th style="min-width:280px;">Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($stores as $s): ?>
    <tr data-store="<?= (int) $s['store_id'] ?>">
      <td><?= ui_esc((string) $s['canonical_name']) ?></td>
      <td class="col-status"><span style="color:var(--text-faint);">Memuat...</span></td>
      <td class="col-last-used">-</td>
      <td class="col-actions">
        <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;">
          <button type="button" class="btn btn-primary btn-sm btn-issue">Generate / Regenerate</button>
          <button type="button" class="btn btn-secondary btn-sm btn-revoke" disabled>Cabut (Revoke)</button>
          <button type="button" class="btn btn-secondary btn-sm btn-history">Riwayat</button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div id="portal-link-modal-root"></div>

<script>
(function () {
  function rowOf(btn) { return btn.closest('tr'); }

  function renderStatus(tr, status) {
    var statusCell = tr.querySelector('.col-status');
    var lastUsedCell = tr.querySelector('.col-last-used');
    var revokeBtn = tr.querySelector('.btn-revoke');
    if (status.hasActiveToken) {
      statusCell.innerHTML = '<span class="badge badge-success">Aktif sejak ' + (status.issuedAt || '-') + '</span>';
      revokeBtn.disabled = false;
    } else {
      statusCell.innerHTML = '<span class="badge badge-neutral">Belum ada link</span>';
      revokeBtn.disabled = true;
    }
    lastUsedCell.textContent = status.lastUsedAt || '-';
  }

  function loadStatus(tr) {
    var storeId = tr.dataset.store;
    Amor.apiFetch('/api/admin/store-portal/' + storeId + '/status').then(function (status) {
      renderStatus(tr, status);
    }).catch(function () {
      tr.querySelector('.col-status').innerHTML = '<span style="color:var(--danger);">Gagal memuat</span>';
    });
  }

  // Deferred to DOMContentLoaded: this page file's own <script> is
  // emitted BEFORE ui_page_foot()'s <script src="app.js"> tag (every
  // page in this app follows that same order), so calling Amor.* here
  // immediately/synchronously would run before app.js has executed.
  // Every OTHER admin page only calls Amor.* from inside a later click
  // handler (naturally deferred past that point) — this page is the
  // first to need an immediate on-load call, hence the explicit wait.
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('tbody tr[data-store]').forEach(loadStatus);
  });

  function showLinkModal(portalUrl, regenerated) {
    var root = document.getElementById('portal-link-modal-root');
    var backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop open';
    backdrop.innerHTML =
      '<div class="modal">' +
      '<div class="modal-title">' + (regenerated ? 'Link Baru Dibuat (link lama sudah tidak berlaku)' : 'Link Portal Dibuat') + '</div>' +
      '<div class="modal-body">Salin link ini sekarang — tidak akan ditampilkan lagi setelah ditutup.</div>' +
      '<div class="modal-summary"><input type="text" readonly style="width:100%;box-sizing:border-box;background:transparent;border:none;color:var(--text);font-size:.85rem;" value="' + portalUrl + '"></div>' +
      '<div class="modal-actions"><button type="button" class="btn" id="portal-link-close">Tutup</button><button type="button" class="btn btn-primary" id="portal-link-copy">Salin Link</button></div>' +
      '</div>';
    root.appendChild(backdrop);
    var input = backdrop.querySelector('input');
    backdrop.querySelector('#portal-link-copy').addEventListener('click', function () {
      input.select();
      navigator.clipboard && navigator.clipboard.writeText(portalUrl).catch(function () {});
      Amor.toast('Link disalin ke clipboard', 'success');
    });
    backdrop.querySelector('#portal-link-close').addEventListener('click', function () { backdrop.remove(); });
  }

  document.querySelectorAll('.btn-issue').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var tr = rowOf(btn);
      var storeId = tr.dataset.store;
      try {
        var dto = await Amor.apiFetch('/api/admin/store-portal/' + storeId + '/issue', { method: 'POST', body: {} });
        showLinkModal(dto.portalUrl, dto.regenerated);
        loadStatus(tr);
      } catch (e) {
        Amor.toast(e.message, 'danger');
      }
    });
  });

  document.querySelectorAll('.btn-revoke').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var tr = rowOf(btn);
      var storeId = tr.dataset.store;
      var storeName = tr.children[0].textContent;
      var ok = await Amor.confirmModal({
        title: 'Cabut Link Portal',
        body: 'Link portal untuk "' + storeName + '" akan langsung tidak berlaku. Toko tidak bisa akses Portal Bakery sampai link baru dibuat. Lanjutkan?',
        confirmLabel: 'Ya, Cabut',
        danger: true,
      });
      if (!ok) return;
      try {
        await Amor.apiFetch('/api/admin/store-portal/' + storeId + '/revoke', { method: 'POST', body: {} });
        Amor.toast('Link portal dicabut', 'success');
        loadStatus(tr);
      } catch (e) {
        Amor.toast(e.message, 'danger');
      }
    });
  });

  document.querySelectorAll('.btn-history').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var tr = rowOf(btn);
      var storeId = tr.dataset.store;
      try {
        var rows = await Amor.apiFetch('/api/admin/store-portal/' + storeId + '/history');
        var lines = rows.length === 0 ? ['Belum pernah dibuat link untuk toko ini.'] : rows.map(function (r) {
          return (r.active ? 'AKTIF' : 'Dicabut') + ' — dibuat ' + r.createdAt + (r.revokedAt ? (', dicabut ' + r.revokedAt) : '') + (r.lastUsedAt ? (', terakhir dipakai ' + r.lastUsedAt) : '');
        });
        await Amor.confirmModal({ title: 'Riwayat Link Portal', body: lines.join('\n'), confirmLabel: 'Tutup' });
      } catch (e) {
        Amor.toast(e.message, 'danger');
      }
    });
  });
})();
</script>
