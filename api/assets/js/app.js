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
  //
  // opts: { title, body, confirmLabel, danger, summaryLines: string[],
  //         onConfirm: () => Promise, pendingLabel }
  //
  // Real-UAT bug fix (Driver Portal "Konfirmasi Berangkat"): a modal
  // opened while one is ALREADY open used to append a second (third...)
  // unstyled backdrop to <body>, stacking visibly at the bottom of the
  // page — never a real overlay. moduleActiveBackdrop is a process-wide
  // singleton guard: a call made while one is open is treated as a no-op
  // cancel instead of ever creating a second DOM node.
  //
  // opts.onConfirm (optional): when the confirmed action itself is an
  // async server call, pass it here instead of awaiting confirmModal()'s
  // own resolution and calling apiFetch afterward — this keeps the modal
  // OPEN while the request is in flight, disables both buttons, shows
  // opts.pendingLabel ("Memproses..." by default) on the confirm button,
  // blocks repeat clicks/backdrop-click/Escape while pending, and on
  // failure re-enables the buttons and shows the friendly error INSIDE
  // the dialog (never silently swallowed, never a fresh identical prompt)
  // so the user can retry or cancel. This is a UX safeguard only — the
  // server's own Idempotency-Key handling remains the real double-submit
  // protection (see apiFetch's genKey()).
  // -----------------------------------------------------------------
  let moduleActiveBackdrop = null;

  function confirmModal(opts) {
    opts = opts || {};
    if (moduleActiveBackdrop) {
      return Promise.resolve(false);
    }
    return new Promise(function (resolve) {
      const backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop open';
      backdrop.innerHTML =
        '<div class="modal" role="dialog" aria-modal="true">' +
        '<div class="modal-title"></div>' +
        '<div class="modal-body"></div>' +
        '<div class="modal-summary"></div>' +
        '<div class="modal-error" hidden></div>' +
        '<div class="modal-actions">' +
        '<button type="button" class="btn btn-secondary" data-act="cancel">Batal</button>' +
        '<button type="button" class="btn" data-act="confirm"></button>' +
        '</div></div>';
      backdrop.querySelector('.modal-title').textContent = opts.title || 'Konfirmasi';
      const bodyEl = backdrop.querySelector('.modal-body');
      bodyEl.textContent = opts.body || 'Lanjutkan?';
      bodyEl.style.whiteSpace = 'pre-line';

      const summaryEl = backdrop.querySelector('.modal-summary');
      if (opts.summaryLines && opts.summaryLines.length) {
        opts.summaryLines.forEach(function (line) {
          const row = document.createElement('div');
          row.textContent = line;
          summaryEl.appendChild(row);
        });
      } else {
        summaryEl.remove();
      }

      const errorEl = backdrop.querySelector('.modal-error');
      const cancelBtn = backdrop.querySelector('[data-act="cancel"]');
      const confirmBtn = backdrop.querySelector('[data-act="confirm"]');
      const confirmLabel = opts.confirmLabel || 'Ya, lanjutkan';
      confirmBtn.textContent = confirmLabel;
      confirmBtn.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');

      let pending = false;

      function escHandler(e) {
        if (e.key === 'Escape' && !pending) close(false);
      }
      function close(result) {
        document.removeEventListener('keydown', escHandler);
        backdrop.remove();
        moduleActiveBackdrop = null;
        resolve(result);
      }
      cancelBtn.addEventListener('click', function () { if (!pending) close(false); });
      backdrop.addEventListener('click', function (e) { if (e.target === backdrop && !pending) close(false); });
      document.addEventListener('keydown', escHandler);

      confirmBtn.addEventListener('click', function () {
        if (pending) return; // block repeat clicks
        if (!opts.onConfirm) { close(true); return; }
        pending = true;
        errorEl.hidden = true;
        confirmBtn.disabled = true;
        cancelBtn.disabled = true;
        confirmBtn.textContent = opts.pendingLabel || 'Memproses...';
        Promise.resolve().then(opts.onConfirm).then(function () {
          close(true);
        }).catch(function (err) {
          pending = false;
          confirmBtn.disabled = false;
          cancelBtn.disabled = false;
          confirmBtn.textContent = confirmLabel;
          errorEl.textContent = (err && err.message) || 'Terjadi kesalahan. Coba lagi.';
          errorEl.hidden = false;
        });
      });

      document.body.appendChild(backdrop);
      moduleActiveBackdrop = backdrop;
      confirmBtn.focus();
    });
  }

  // -----------------------------------------------------------------
  // imageLightbox — bounded full-size preview for a thumbnail (e.g. Admin
  // Konfirmasi Toko Detail's "Bukti Foto dari Toko"). Reuses the same
  // singleton-backdrop-guard / Escape-to-close / click-outside-to-close
  // pattern as confirmModal() above, but with its own guard variable (a
  // lightbox and a confirm dialog are never open at the same time in this
  // UI, but sharing one guard would be an accidental coupling). The image
  // itself is always bounded (max-width:90vw / max-height:80vh, object-
  // fit:contain via CSS) — this NEVER renders the original photo at its
  // natural resolution, which is the actual bug this exists to fix.
  // -----------------------------------------------------------------
  let activeLightbox = null;

  function imageLightbox(src) {
    if (activeLightbox) return;
    const backdrop = document.createElement('div');
    backdrop.className = 'image-lightbox-backdrop open';
    backdrop.innerHTML =
      '<div class="image-lightbox">' +
      '<button type="button" class="image-lightbox-close" aria-label="Tutup">&times;</button>' +
      '<img alt="Bukti foto (perbesar)">' +
      '</div>';
    backdrop.querySelector('img').src = src;

    function escHandler(e) { if (e.key === 'Escape') close(); }
    function close() {
      document.removeEventListener('keydown', escHandler);
      backdrop.remove();
      activeLightbox = null;
    }
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
    backdrop.querySelector('.image-lightbox-close').addEventListener('click', close);
    document.addEventListener('keydown', escHandler);

    document.body.appendChild(backdrop);
    activeLightbox = backdrop;
  }

  // -----------------------------------------------------------------
  // createAutocomplete — real searchable dropdown over a preloaded item
  // array (never a native <datalist>, which is unreliable on iPad/mobile
  // Safari: no touch-filtering, inconsistent option tap targets). Used by
  // Pesanan Khusus Toko / Pesanan Non-Toko's "Produk Existing" item input
  // (task's own B: "existing-product autocomplete... product_id
  // authoritative, no free text submittable, works on iPad/mobile, no
  // native datalist, invalidates selection if text changes after
  // selecting").
  //
  // input: a plain <input type="text"> already in the DOM.
  // opts: { items: Array, getLabel: fn(item)=>string, onSelect: fn(item|null),
  //         minChars (default 0), maxResults (default 20) }
  //
  // Selection is authoritative: setting input.value programmatically
  // (picking an option) never fires the browser's native 'input' event,
  // so the selected item stays valid until the user actually types again
  // — at which point onSelect(null) fires immediately, invalidating the
  // prior pick exactly as required.
  //
  // Touch safety: the option list handles 'mousedown'/'touchstart' (not
  // 'click') with preventDefault(), so a tap registers BEFORE the input's
  // own 'blur' (which would otherwise close the menu first on iOS Safari
  // and swallow the tap).
  // -----------------------------------------------------------------
  function createAutocomplete(input, opts) {
    opts = opts || {};
    var items = opts.items || [];
    var getLabel = opts.getLabel || function (it) { return String(it.label || ''); };
    var onSelect = opts.onSelect || function () {};
    var minChars = opts.minChars || 0;
    var maxResults = opts.maxResults || 20;

    input.setAttribute('autocomplete', 'off');
    input.classList.add('ac-input');

    var wrap = document.createElement('div');
    wrap.className = 'ac-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var menu = document.createElement('div');
    menu.className = 'ac-menu';
    menu.hidden = true;
    wrap.appendChild(menu);

    var selected = null;
    var filtered = [];
    var activeIndex = -1;

    function setSelected(item) {
      selected = item;
      onSelect(item);
    }

    function filterItems(q) {
      q = (q || '').toLowerCase().trim();
      if (q.length < minChars) return items.slice(0, maxResults);
      return items.filter(function (it) { return getLabel(it).toLowerCase().indexOf(q) !== -1; }).slice(0, maxResults);
    }

    function highlight() {
      Array.prototype.forEach.call(menu.querySelectorAll('.ac-option'), function (el, i) {
        el.classList.toggle('active', i === activeIndex);
      });
    }

    function render(list) {
      filtered = list;
      activeIndex = -1;
      if (list.length === 0) {
        menu.innerHTML = '<div class="ac-empty">Tidak ditemukan</div>';
      } else {
        menu.innerHTML = list.map(function (it, i) {
          return '<div class="ac-option" data-index="' + i + '">' + getLabel(it).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</div>';
        }).join('');
      }
      menu.hidden = false;
    }

    function close() {
      menu.hidden = true;
      activeIndex = -1;
    }

    input.addEventListener('input', function () {
      if (selected) setSelected(null);
      render(filterItems(input.value));
    });
    input.addEventListener('focus', function () { render(filterItems(input.value)); });
    input.addEventListener('blur', function () { setTimeout(close, 150); });

    function pickAt(idx) {
      var item = filtered[idx];
      if (!item) return;
      input.value = getLabel(item);
      setSelected(item);
      close();
    }
    function handlePointer(e) {
      var opt = e.target.closest('.ac-option');
      if (!opt) return;
      e.preventDefault();
      pickAt(parseInt(opt.getAttribute('data-index'), 10));
    }
    menu.addEventListener('mousedown', handlePointer);
    menu.addEventListener('touchstart', handlePointer, { passive: false });

    input.addEventListener('keydown', function (e) {
      if (menu.hidden) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = Math.min(activeIndex + 1, filtered.length - 1); highlight(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = Math.max(activeIndex - 1, 0); highlight(); }
      else if (e.key === 'Enter') { if (activeIndex >= 0) { e.preventDefault(); pickAt(activeIndex); } }
      else if (e.key === 'Escape') { close(); }
    });

    return {
      setItems: function (list) { items = list; },
      getSelected: function () { return selected; },
      clear: function () { input.value = ''; setSelected(null); },
    };
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
    FG_BELOW_SHIPPED: 'FG Terverifikasi tidak boleh dikurangi di bawah jumlah yang sudah dikirim.',
    NO_FG_VERIFIED_DEMAND: 'Belum ada item yang terverifikasi FG untuk pesanan/pabrik ini.',
    EXCEEDS_AVAILABLE_FOR_DO: 'Jumlah melebihi yang tersedia untuk DO baru (sudah dialokasikan ke DO lain).',
    ALREADY_CLAIMED: 'DO ini sudah diambil driver lain.',
    NOT_CLAIMANT: 'Anda belum mengambil (claim) DO ini.',
    WRONG_DELIVERY_METHOD: 'Aksi ini tidak berlaku untuk metode pengiriman DO ini.',
    DELIVERY_METHOD_LOCKED: 'Metode pengiriman terkunci setelah pengiriman aktual pertama.',
    DROP_STORE_REQUIRED: 'Toko/Bakery tujuan pengiriman fisik wajib dipilih.',
    EMPTY_SHIPMENT: 'Tidak ada jumlah yang bisa dikirim sekarang.',
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

  // -----------------------------------------------------------------
  // fmtRupiah — "Rp46.000" style (task's own C: "Currency display
  // formatting"). Display-only; the caller's underlying numeric value/
  // input is never touched by this.
  // -----------------------------------------------------------------
  function fmtRupiah(n) {
    return 'Rp' + Math.round(n || 0).toLocaleString('id-ID');
  }

  window.Amor = {
    toast: toast,
    confirmModal: confirmModal,
    imageLightbox: imageLightbox,
    apiFetch: apiFetch,
    friendlyError: friendlyError,
    toggleSidebar: toggleSidebar,
    toggleTheme: toggleTheme,
    createAutocomplete: createAutocomplete,
    fmtRupiah: fmtRupiah,
  };

  document.addEventListener('DOMContentLoaded', function () {
    applyStoredSidebar();
    const toggleBtn = document.querySelector('[data-action="toggle-sidebar"]');
    if (toggleBtn) toggleBtn.addEventListener('click', toggleSidebar);
    const themeBtn = document.querySelector('[data-action="toggle-theme"]');
    if (themeBtn) themeBtn.addEventListener('click', toggleTheme);

    // Generic image thumbnail -> bounded lightbox (e.g. Admin Konfirmasi
    // Toko Detail's evidence thumbnails): <a class="evidence-thumb"
    // href="/api/.../evidence/{id}" data-lightbox="image"><img ...></a>.
    // The href stays as a plain, same-tab fallback if JS fails to load —
    // but the default UX (JS present) is always the bounded in-page
    // preview below, never a raw full-resolution image navigation.
    document.addEventListener('click', function (e) {
      const link = e.target.closest('[data-lightbox="image"]');
      if (!link) return;
      e.preventDefault();
      imageLightbox(link.getAttribute('href'));
    });

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
