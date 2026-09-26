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

    // Portaled to <body> (never a child of .ac-wrap) with viewport-fixed
    // positioning, computed from the input's own getBoundingClientRect()
    // each time it opens/repositions. This is REQUIRED for the compact
    // order-item table (Pesanan Khusus Toko / Pesanan Non-Toko UI/UX
    // rework): an autocomplete menu nested inside a bounded, scrollable
    // table container (.order-item-table-wrap, overflow-y/x:auto for
    // 50+ row scalability) would otherwise be clipped/misplaced by that
    // ancestor's own overflow box if it stayed absolutely-positioned
    // inside .ac-wrap. Harmless for every other existing caller (a plain
    // page body has no competing scroll container, so this is visually
    // identical there).
    var menu = document.createElement('div');
    menu.className = 'ac-menu';
    menu.hidden = true;
    document.body.appendChild(menu);

    function positionMenu() {
      var r = input.getBoundingClientRect();
      menu.style.position = 'fixed';
      menu.style.left = r.left + 'px';
      menu.style.top = (r.bottom + 4) + 'px';
      menu.style.width = r.width + 'px';
    }
    function repositionIfOpen() { if (!menu.hidden) positionMenu(); }
    // capture:true so a scroll on ANY nested scrollable ancestor (e.g.
    // .order-item-table-wrap) is caught too — 'scroll' does not bubble,
    // but a capture-phase window listener still sees it on the way down.
    window.addEventListener('scroll', repositionIfOpen, true);
    window.addEventListener('resize', repositionIfOpen);

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
      positionMenu();
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
      // Removes the body-portaled menu + its window listeners — required
      // whenever the input itself is removed from the DOM (e.g. deleting
      // a row from the order-item table), since the menu is no longer a
      // descendant of the input and so is never cleaned up automatically.
      destroy: function () {
        window.removeEventListener('scroll', repositionIfOpen, true);
        window.removeEventListener('resize', repositionIfOpen);
        menu.remove();
      },
    };
  }

  // -----------------------------------------------------------------
  // createOrderItemGrid — compact, scrollable spreadsheet-like table for
  // Pesanan Khusus Toko / Pesanan Non-Toko item entry (UI/UX rework:
  // replaces the old one-card-per-item layout, which does not scale to a
  // real order with 50+ items). Columns: No / Item / Tipe / Divisi /
  // Factory / Qty / Harga / Charge / Extra Packaging / Subtotal /
  // Catatan / Aksi — the same fields and payload semantics the card
  // layout had; only the presentation changed. product_id safety is
  // UNCHANGED: an existing-product row still goes through
  // createAutocomplete() above, which already invalidates the selected
  // product the instant its text is edited again — this never
  // introduces a second, less-safe text-matching path.
  //
  // container: an empty element already in the DOM.
  // opts: {
  //   products: Array<{productId, name, harga, divisionName, factoryName}>,
  //   catalog: Array<{catalogId, name, defaultPrice, defaultCharge, divisionName, factoryName}>,
  //   onChange: fn() — called after any row is added/edited/removed, so
  //     the caller can refresh its own order-level summary/routing panel.
  // }
  // Returns { addRow(itemType), getItems() } — getItems() returns
  // { items, rowError } exactly like the old readCards() did.
  // -----------------------------------------------------------------
  function createOrderItemGrid(container, opts) {
    opts = opts || {};
    var products = opts.products || [];
    var catalog = opts.catalog || [];
    var onChange = opts.onChange || function () {};

    var wrap = document.createElement('div');
    wrap.className = 'order-item-table-wrap';
    wrap.innerHTML =
      '<table class="order-item-table">' +
        '<thead><tr>' +
          '<th class="oit-col-no">No</th>' +
          '<th class="oit-col-item">Item</th>' +
          '<th>Tipe</th>' +
          '<th>Divisi</th>' +
          '<th>Factory</th>' +
          '<th class="num">Qty</th>' +
          '<th class="num">Harga</th>' +
          '<th class="num">Charge</th>' +
          '<th class="num">Extra Packaging</th>' +
          '<th class="num">Subtotal</th>' +
          '<th>Catatan</th>' +
          '<th>Aksi</th>' +
        '</tr></thead>' +
        '<tbody></tbody>' +
      '</table>';
    container.innerHTML = '';
    container.appendChild(wrap);
    var tbody = wrap.querySelector('tbody');

    var rows = [];

    function fmtRp(n) { return fmtRupiah(n); }

    function catalogOptions() {
      return '<option value="">— Pilih —</option>' + catalog.map(function (c) {
        return '<option value="' + c.catalogId + '">' + String(c.name).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</option>';
      }).join('');
    }

    function renumber() {
      rows.forEach(function (s, i) { s.noCell.textContent = String(i + 1); });
    }

    function refreshRowSubtotal(state) {
      var qty = parseFloat(state.qtyInput.value) || 0;
      var unitPrice = parseFloat(state.priceInput.value) || 0;
      var charge = parseFloat(state.chargeInput.value) || 0;
      var extraPackaging = parseFloat(state.extraInput.value) || 0;
      state.subtotalCell.textContent = fmtRp(qty * unitPrice + charge + extraPackaging);
    }

    function updateNoteButton(state) {
      state.noteBtn.textContent = state.note ? 'Catatan ✓' : '+ Catatan';
      state.noteBtn.title = state.note || '';
    }

    // Compact Catatan field: a short note fits directly, a long one opens
    // this small popover/editor (task's own "if a long note is needed,
    // allow modal/popover or expandable editor") — reuses the same
    // .modal-backdrop/.modal classes confirmModal() uses, for visual
    // consistency, but is its own lightweight free-text editor (never a
    // yes/no confirm).
    function openNotePopover(state) {
      var backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop open';
      backdrop.innerHTML =
        '<div class="modal" role="dialog" aria-modal="true">' +
          '<div class="modal-title">Catatan Khusus</div>' +
          '<div class="modal-body"><textarea rows="6" style="width:100%;"></textarea></div>' +
          '<div class="modal-actions">' +
            '<button type="button" class="btn btn-secondary" data-act="cancel">Batal</button>' +
            '<button type="button" class="btn btn-primary" data-act="save">Simpan</button>' +
          '</div>' +
        '</div>';
      var ta = backdrop.querySelector('textarea');
      ta.value = state.note || '';
      ta.placeholder = 'Contoh: Tema Spiderman, tulisan HBD Raka, dominan warna biru...';
      function close() { backdrop.remove(); }
      backdrop.querySelector('[data-act="cancel"]').addEventListener('click', close);
      backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
      backdrop.querySelector('[data-act="save"]').addEventListener('click', function () {
        state.note = ta.value.trim();
        updateNoteButton(state);
        close();
        onChange();
      });
      document.body.appendChild(backdrop);
      ta.focus();
    }

    // Enter on a numeric cell advances to the same cell on the next row,
    // adding one (of the same item type) if this is the last row — task's
    // own "Enter may advance to the next row / add row when appropriate."
    // Never attached to the Item input itself: createAutocomplete() above
    // already gives Enter its own native meaning there (confirm the
    // highlighted suggestion), and this must never fight that.
    function focusNextRowQty(currentState) {
      var idx = rows.indexOf(currentState);
      var next = rows[idx + 1];
      if (next) {
        next.qtyInput.focus();
        next.qtyInput.select();
        return;
      }
      addRow(currentState.itemType);
      var added = rows[rows.length - 1];
      if (added) { added.itemInput.focus(); }
    }

    function addRow(itemType) {
      var tr = document.createElement('tr');
      tr.className = 'order-item-row';

      var noTd = document.createElement('td'); noTd.className = 'oit-col-no';
      var itemTd = document.createElement('td'); itemTd.className = 'oit-col-item';
      var typeTd = document.createElement('td');
      var divTd = document.createElement('td');
      var facTd = document.createElement('td');
      var qtyTd = document.createElement('td'); qtyTd.className = 'num';
      var priceTd = document.createElement('td'); priceTd.className = 'num';
      var chargeTd = document.createElement('td'); chargeTd.className = 'num';
      var extraTd = document.createElement('td'); extraTd.className = 'num';
      var subtotalTd = document.createElement('td'); subtotalTd.className = 'num oit-subtotal';
      var noteTd = document.createElement('td');
      var actionTd = document.createElement('td');

      typeTd.innerHTML = itemType === 'existing_product'
        ? '<span class="badge badge-success">Produk Existing</span>'
        : '<span class="badge badge-warning">Item Khusus</span>';
      divTd.innerHTML = '<span class="badge badge-neutral">—</span>';
      facTd.innerHTML = '<span class="badge badge-neutral">—</span>';

      var itemInput;
      if (itemType === 'existing_product') {
        itemInput = document.createElement('input');
        itemInput.type = 'text';
        itemInput.className = 'oit-item-input';
        itemInput.placeholder = 'Ketik nama produk...';
      } else {
        itemInput = document.createElement('select');
        itemInput.className = 'oit-item-input';
        itemInput.innerHTML = catalogOptions();
      }
      itemTd.appendChild(itemInput);

      var qtyInput = document.createElement('input');
      qtyInput.type = 'number'; qtyInput.className = 'oit-num-input'; qtyInput.min = '0.01'; qtyInput.step = '0.01'; qtyInput.value = '1';
      qtyTd.appendChild(qtyInput);

      var priceInput = document.createElement('input');
      priceInput.type = 'number'; priceInput.className = 'oit-num-input'; priceInput.min = '0'; priceInput.step = '1'; priceInput.value = '0';
      priceTd.appendChild(priceInput);

      var chargeInput = document.createElement('input');
      chargeInput.type = 'number'; chargeInput.className = 'oit-num-input'; chargeInput.min = '0'; chargeInput.step = '1'; chargeInput.value = '0';
      chargeTd.appendChild(chargeInput);

      var extraInput = document.createElement('input');
      extraInput.type = 'number'; extraInput.className = 'oit-num-input'; extraInput.min = '0'; extraInput.step = '1'; extraInput.value = '0';
      extraTd.appendChild(extraInput);

      subtotalTd.textContent = 'Rp0';

      var noteBtn = document.createElement('button');
      noteBtn.type = 'button';
      noteBtn.className = 'btn btn-secondary btn-sm oit-note-btn';
      noteTd.appendChild(noteBtn);

      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'btn btn-secondary btn-sm';
      removeBtn.textContent = 'Hapus';
      actionTd.appendChild(removeBtn);

      tr.appendChild(noTd); tr.appendChild(itemTd); tr.appendChild(typeTd); tr.appendChild(divTd);
      tr.appendChild(facTd); tr.appendChild(qtyTd); tr.appendChild(priceTd); tr.appendChild(chargeTd);
      tr.appendChild(extraTd); tr.appendChild(subtotalTd); tr.appendChild(noteTd); tr.appendChild(actionTd);
      tbody.appendChild(tr);

      var state = {
        itemType: itemType, tr: tr, noCell: noTd, itemInput: itemInput,
        qtyInput: qtyInput, priceInput: priceInput, chargeInput: chargeInput, extraInput: extraInput,
        subtotalCell: subtotalTd, noteBtn: noteBtn, note: '',
        divisionName: null, factoryName: null, selectedProduct: null, autocomplete: null,
      };
      rows.push(state);
      updateNoteButton(state);

      function applyRouting(divisionName, factoryName) {
        state.divisionName = divisionName;
        state.factoryName = factoryName;
        divTd.innerHTML = divisionName ? '<span class="badge badge-primary">' + divisionName + '</span>' : '<span class="badge badge-neutral">—</span>';
        facTd.innerHTML = factoryName ? '<span class="badge badge-neutral">' + factoryName + '</span>' : '<span class="badge badge-neutral">—</span>';
        refreshRowSubtotal(state);
        onChange();
      }

      if (itemType === 'existing_product') {
        state.autocomplete = createAutocomplete(itemInput, {
          items: products,
          getLabel: function (p) { return p.name; },
          onSelect: function (p) {
            state.selectedProduct = p;
            if (p) {
              priceInput.value = p.harga;
              applyRouting(p.divisionName, p.factoryName);
            } else {
              applyRouting(null, null);
            }
          },
        });
      } else {
        itemInput.addEventListener('change', function () {
          var c = catalog.filter(function (x) { return String(x.catalogId) === itemInput.value; })[0];
          if (c) {
            priceInput.value = c.defaultPrice || 0;
            chargeInput.value = c.defaultCharge || 0;
            applyRouting(c.divisionName, c.factoryName);
          } else {
            applyRouting(null, null);
          }
        });
      }

      [qtyInput, priceInput, chargeInput, extraInput].forEach(function (inp) {
        inp.addEventListener('input', function () { refreshRowSubtotal(state); onChange(); });
        inp.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') { e.preventDefault(); focusNextRowQty(state); }
        });
      });

      noteBtn.addEventListener('click', function () { openNotePopover(state); });

      removeBtn.addEventListener('click', function () {
        if (state.autocomplete) state.autocomplete.destroy();
        rows = rows.filter(function (s) { return s !== state; });
        tr.remove();
        renumber();
        onChange();
      });

      renumber();
      refreshRowSubtotal(state);
      onChange();
    }

    function getItems() {
      var items = [];
      var rowError = null;
      rows.forEach(function (s) {
        var qty = parseFloat(s.qtyInput.value) || 0;
        var unitPrice = parseFloat(s.priceInput.value) || 0;
        var charge = parseFloat(s.chargeInput.value) || 0;
        var extraPackaging = parseFloat(s.extraInput.value) || 0;
        var note = s.note || null;
        // Extra Packaging: added ONCE per line, never multiplied by qty —
        // unlike unitPrice, which IS multiplied by qty.
        var subtotal = qty * unitPrice + charge + extraPackaging;
        if (s.itemType === 'existing_product') {
          var p = s.selectedProduct;
          if (!p) { rowError = 'Setiap item "Produk Existing" harus memilih produk yang valid dari daftar pencarian.'; return; }
          items.push({ itemType: 'existing_product', productId: p.productId, qty: qty, unitPrice: unitPrice, charge: charge, extraPackaging: extraPackaging, subtotal: subtotal, specialNote: note, divisionName: p.divisionName, factoryName: p.factoryName });
        } else {
          var catalogId = s.itemInput.value;
          if (!catalogId) { rowError = 'Setiap item "Item Khusus" harus memilih item dari katalog.'; return; }
          var c = catalog.filter(function (x) { return String(x.catalogId) === catalogId; })[0];
          items.push({ itemType: 'special_catalog', specialCatalogId: parseInt(catalogId, 10), qty: qty, unitPrice: unitPrice, charge: charge, extraPackaging: extraPackaging, subtotal: subtotal, specialNote: note, divisionName: c ? c.divisionName : null, factoryName: c ? c.factoryName : null });
        }
      });
      return { items: items, rowError: rowError };
    }

    return { addRow: addRow, getItems: getItems };
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
    createOrderItemGrid: createOrderItemGrid,
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
