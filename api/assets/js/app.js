/*
 * Amor Factory System — shared UI JS toolkit.
 *
 * Vanilla JS only, no framework/build step (matches the PHP/shared-hosting
 * constraint). Every mutating call goes through apiFetch(), which talks to
 * the EXISTING real JSON API (the same one the old UAT pages and the
 * Phase 1-5 test suites already exercise) — this file never reimplements
 * business logic, it only calls it and renders the result. Server-side
 * validation in that API remains authoritative; anything checked here
 * (max qty, disabled rows, etc.) is a convenience only.
 *
 * window.AMOR is set by layout.php before this file loads:
 *   { csrfToken, userId, username }
 */
(function () {
  'use strict';

  const AMOR = window.AMOR || {};

  // -----------------------------------------------------------------
  // Theme (UI preference only — never transactional data; safe in
  // localStorage per the task's own explicit rule).
  // -----------------------------------------------------------------
  function applyStoredTheme() {
    let theme = 'dark';
    try { theme = localStorage.getItem('amor_theme') || 'dark'; } catch (e) { /* private mode etc. */ }
    document.documentElement.setAttribute('data-theme', theme);
  }
  function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') || 'dark';
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('amor_theme', next); } catch (e) { /* ignore */ }
  }
  applyStoredTheme();

  // -----------------------------------------------------------------
  // Sidebar collapse (UI preference only).
  // -----------------------------------------------------------------
  function applyStoredSidebar() {
    let collapsed = false;
    try { collapsed = localStorage.getItem('amor_sidebar_collapsed') === '1'; } catch (e) { /* ignore */ }
    const shell = document.querySelector('.app-shell');
    if (shell && collapsed) shell.classList.add('sidebar-collapsed');
  }
  function toggleSidebar() {
    const shell = document.querySelector('.app-shell');
    if (!shell) return;
    shell.classList.toggle('sidebar-collapsed');
    try { localStorage.setItem('amor_sidebar_collapsed', shell.classList.contains('sidebar-collapsed') ? '1' : '0'); } catch (e) { /* ignore */ }
  }

  // -----------------------------------------------------------------
  // Toast
  // -----------------------------------------------------------------
  function ensureToastStack() {
    let stack = document.querySelector('.toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'toast-stack';
      document.body.appendChild(stack);
    }
    return stack;
  }
  function toast(message, type) {
    const stack = ensureToastStack();
    const el = document.createElement('div');
    el.className = 'toast' + (type ? ' ' + type : '');
    el.textContent = message;
    stack.appendChild(el);
    setTimeout(function () {
      el.style.transition = 'opacity .3s';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 300);
    }, 4200);
  }

  // -----------------------------------------------------------------
  // Confirmation modal — returns a Promise<boolean>.
  // -----------------------------------------------------------------
  function confirmModal(opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      const backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop open';
      backdrop.innerHTML =
        '<div class="modal" role="dialog" aria-modal="true">' +
        '<div class="modal-title"></div>' +
        '<div class="modal-body"></div>' +
        '<div class="modal-actions">' +
        '<button type="button" class="btn btn-secondary" data-act="cancel">Batal</button>' +
        '<button type="button" class="btn" data-act="confirm"></button>' +
        '</div></div>';
      backdrop.querySelector('.modal-title').textContent = opts.title || 'Konfirmasi';
      backdrop.querySelector('.modal-body').textContent = opts.body || 'Lanjutkan?';
      const confirmBtn = backdrop.querySelector('[data-act="confirm"]');
      confirmBtn.textContent = opts.confirmLabel || 'Ya, lanjutkan';
      confirmBtn.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');

      function close(result) {
        backdrop.remove();
        resolve(result);
      }
      backdrop.querySelector('[data-act="cancel"]').addEventListener('click', function () { close(false); });
      backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(false); });
      confirmBtn.addEventListener('click', function () { close(true); });
      document.addEventListener('keydown', function escHandler(e) {
        if (e.key === 'Escape') { document.removeEventListener('keydown', escHandler); close(false); }
      });
      document.body.appendChild(backdrop);
      confirmBtn.focus();
    });
  }

  // -----------------------------------------------------------------
  // Friendly error mapping — technical detail stays in console/logs,
  // never the default user-facing message.
  // -----------------------------------------------------------------
  const ERROR_MESSAGES = {
    VERSION_CONFLICT: 'Data sudah berubah oleh pengguna lain. Muat ulang halaman sebelum melanjutkan.',
    INSUFFICIENT_FG_AVAILABLE: 'Stok FG tidak mencukupi untuk jumlah pengiriman ini.',
    EXCEEDS_REMAINING: 'Jumlah melebihi sisa yang belum dikirim pada dokumen ini.',
    EXCEEDS_AVAILABLE: 'Jumlah melebihi stok FG yang tersedia.',
    FG_EXCEEDS_PRODUCTION: 'FG Terverifikasi tidak boleh melebihi Hasil Produksi.',
    PACKED_EXCEEDS_VERIFIED: 'Jumlah Packed tidak boleh melebihi FG Terverifikasi.',
    CANNOT_CANCEL_SHIPPED: 'DO ini sudah memiliki pengiriman — tidak bisa dibatalkan.',
    NO_PO_DEMAND: 'Tidak ada PO untuk toko/tanggal ini — tidak ada yang bisa dibuat.',
    NOT_FOUND: 'Data yang diminta tidak ditemukan (mungkin sudah dihapus/berubah).',
    REASON_REQUIRED: 'Alasan wajib diisi.',
    INVALID_STATUS: 'Aksi ini tidak bisa dilakukan pada status dokumen saat ini.',
    MIXED_FACTORY_SHIPMENT: 'Satu pengiriman tidak boleh mencampur produk dari dua pabrik berbeda.',
    UNAUTHENTICATED: 'Sesi Anda sudah berakhir — silakan login kembali.',
    FORBIDDEN: 'Akun Anda tidak memiliki izin untuk aksi ini.',
  };
  function friendlyError(code, fallbackMessage) {
    return ERROR_MESSAGES[code] || fallbackMessage || 'Terjadi kesalahan. Coba lagi, atau hubungi admin jika berulang.';
  }

  // -----------------------------------------------------------------
  // apiFetch — thin wrapper around the REAL JSON API. Adds CSRF token
  // and (for mutating verbs) a fresh Idempotency-Key so a double-tap or
  // retried request can never double-post. Session cookie already
  // carries auth — no parallel token scheme.
  // -----------------------------------------------------------------
  function genKey() {
    return 'ui-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
  }
  async function apiFetch(path, options) {
    options = options || {};
    const method = (options.method || 'GET').toUpperCase();
    const headers = Object.assign({ 'Content-Type': 'application/json' }, options.headers || {});
    if (method !== 'GET') {
      headers['X-CSRF-Token'] = AMOR.csrfToken || '';
      if (!headers['Idempotency-Key']) headers['Idempotency-Key'] = genKey();
    }
    const res = await fetch(path, {
      method: method,
      headers: headers,
      credentials: 'same-origin',
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    });
    let json = null;
    try { json = await res.json(); } catch (e) { /* empty body */ }
    if (!res.ok || (json && json.ok === false)) {
      const code = json && json.code ? json.code : ('HTTP_' + res.status);
      const err = new Error(friendlyError(code, json && json.message));
      err.code = code;
      err.status = res.status;
      err.raw = json;
      throw err;
    }
    return json ? json.data : null;
  }

  window.Amor = {
    toast: toast,
    confirmModal: confirmModal,
    apiFetch: apiFetch,
    friendlyError: friendlyError,
    toggleSidebar: toggleSidebar,
    toggleTheme: toggleTheme,
  };

  document.addEventListener('DOMContentLoaded', function () {
    applyStoredSidebar();
    const toggleBtn = document.querySelector('[data-action="toggle-sidebar"]');
    if (toggleBtn) toggleBtn.addEventListener('click', toggleSidebar);
    const themeBtn = document.querySelector('[data-action="toggle-theme"]');
    if (themeBtn) themeBtn.addEventListener('click', toggleTheme);

    // Generic confirm-then-apiFetch action buttons:
    // <button data-confirm-action data-method data-url data-confirm-title
    //         data-confirm-body data-danger data-success-message
    //         data-reload="1|url">
    document.querySelectorAll('[data-confirm-action]').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        const ok = await confirmModal({
          title: btn.getAttribute('data-confirm-title') || 'Konfirmasi',
          body: btn.getAttribute('data-confirm-body') || 'Lanjutkan?',
          confirmLabel: btn.getAttribute('data-confirm-label') || undefined,
          danger: btn.hasAttribute('data-danger'),
        });
        if (!ok) return;
        btn.disabled = true;
        try {
          const bodyAttr = btn.getAttribute('data-body');
          await apiFetch(btn.getAttribute('data-url'), {
            method: btn.getAttribute('data-method') || 'POST',
            body: bodyAttr ? JSON.parse(bodyAttr) : {},
          });
          toast(btn.getAttribute('data-success-message') || 'Berhasil.', 'success');
          const reload = btn.getAttribute('data-reload');
          if (reload === '1') { setTimeout(function () { location.reload(); }, 500); }
          else if (reload) { setTimeout(function () { location.href = reload; }, 500); }
        } catch (e) {
          toast(e.message, 'danger');
        } finally {
          btn.disabled = false;
        }
      });
    });
  });
})();
