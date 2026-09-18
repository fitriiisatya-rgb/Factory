/*
 * Amor Factory System — Driver portal client logic (Phase 5.5).
 * Vanilla JS, no framework/build step, same discipline as app.js: every
 * mutating action goes through Amor.apiFetch() against the real JSON API
 * (/api/dispatch/*, /api/receive/*) — nothing here recomputes qty/stock
 * rules the server doesn't already enforce; numbers shown are always
 * whatever the API just returned, never hardcoded.
 */
(function () {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmtNum(n) {
    n = Number(n) || 0;
    return (Math.round(n * 100) / 100).toString().replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
  }
  function todayStr() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function getTanggal() {
    try {
      return localStorage.getItem('amor_driver_tanggal') || todayStr();
    } catch (e) { return todayStr(); }
  }
  function setTanggal(v) {
    try { localStorage.setItem('amor_driver_tanggal', v); } catch (e) { /* ignore */ }
  }

  function dateBar(onChange) {
    var wrap = document.createElement('div');
    wrap.className = 'driver-select-row';
    wrap.innerHTML = '<input type="date" class="driver-search" style="margin-bottom:0" value="' + esc(getTanggal()) + '">';
    var input = wrap.querySelector('input');
    input.addEventListener('change', function () {
      setTanggal(input.value);
      onChange(input.value);
    });
    return wrap;
  }

  function groupBadgeClass(g) {
    return g === 'PASTRY' ? 'pastry' : (g === 'MAIN' ? 'main' : '');
  }

  function emptyState(text) {
    return '<div class="driver-empty">' + Amor_icon() + '<div>' + esc(text) + '</div></div>';
  }
  function Amor_icon() {
    return '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 8L12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/></svg>';
  }

  // -----------------------------------------------------------------
  // TAB: Tersedia
  // -----------------------------------------------------------------
  function renderTersedia(root) {
    root.innerHTML = '';
    root.appendChild(dateBar(function () { renderTersedia(root); }));

    var filterRow = document.createElement('div');
    filterRow.className = 'driver-filter-row';
    var groups = ['Semua', 'MAIN', 'PASTRY', 'OTHER'];
    var activeGroup = 'Semua';
    groups.forEach(function (g) {
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'driver-chip' + (g === activeGroup ? ' active' : '');
      chip.textContent = g;
      chip.dataset.group = g;
      filterRow.appendChild(chip);
    });
    root.appendChild(filterRow);

    var search = document.createElement('input');
    search.type = 'search';
    search.className = 'driver-search';
    search.placeholder = 'Cari toko atau produk...';
    root.appendChild(search);

    var list = document.createElement('div');
    root.appendChild(list);

    var stickyBar = document.createElement('div');
    stickyBar.className = 'driver-sticky-bar';
    stickyBar.style.display = 'none';
    stickyBar.innerHTML = '<button type="button" class="driver-btn primary" id="btn-ambil">Ambil Pengiriman</button>';
    root.appendChild(stickyBar);

    var allItems = [];

    function updateStickyBar() {
      var checked = list.querySelectorAll('input[type=checkbox]:checked');
      stickyBar.style.display = checked.length > 0 ? 'block' : 'none';
      var btn = stickyBar.querySelector('#btn-ambil');
      if (btn) btn.textContent = 'Ambil Pengiriman (' + checked.length + ' dipilih)';
    }

    function draw() {
      var q = search.value.trim().toLowerCase();
      var filtered = allItems.filter(function (it) {
        if (activeGroup !== 'Semua' && it.suggestedGroup !== activeGroup) return false;
        if (q && (it.storeName + ' ' + it.productName).toLowerCase().indexOf(q) === -1) return false;
        return true;
      });
      if (filtered.length === 0) {
        list.innerHTML = emptyState('Tidak ada pengiriman tersedia.');
        updateStickyBar();
        return;
      }
      list.innerHTML = filtered.map(function (it) {
        return '' +
          '<div class="driver-card">' +
          '<div class="driver-select-row">' +
          '<input type="checkbox" data-do-item-id="' + it.doItemId + '" data-max="' + it.availableToClaim + '">' +
          '<div style="flex:1">' +
          '<div class="driver-card-title">' + esc(it.storeName) + '</div>' +
          '<div class="driver-card-sub">' + esc(it.docNo) + '</div>' +
          '</div>' +
          '<span class="driver-badge ' + groupBadgeClass(it.suggestedGroup) + '">' + esc(it.suggestedGroup) + '</span>' +
          '</div>' +
          '<div class="driver-row"><span>' + esc(it.productName) + '</span><b>Tersedia: ' + fmtNum(it.availableToClaim) + '</b></div>' +
          '<div class="driver-row" style="align-items:center;margin-top:6px;"><span>Qty Ambil</span>' +
          '<input type="number" class="driver-qty-input" data-qty-for="' + it.doItemId + '" min="0" max="' + it.availableToClaim + '" step="0.01" value="' + it.availableToClaim + '"></div>' +
          '</div>';
      }).join('');
      list.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
        cb.addEventListener('change', updateStickyBar);
      });
      updateStickyBar();
    }

    filterRow.addEventListener('click', function (e) {
      var chip = e.target.closest('.driver-chip');
      if (!chip) return;
      activeGroup = chip.dataset.group;
      filterRow.querySelectorAll('.driver-chip').forEach(function (c) { c.classList.toggle('active', c === chip); });
      draw();
    });
    search.addEventListener('input', draw);

    stickyBar.addEventListener('click', function (e) {
      if (!e.target.closest('#btn-ambil')) return;
      var lines = [];
      list.querySelectorAll('input[type=checkbox]:checked').forEach(function (cb) {
        var doItemId = parseInt(cb.dataset.doItemId, 10);
        var qtyInput = list.querySelector('input[data-qty-for="' + doItemId + '"]');
        var qty = parseFloat(qtyInput ? qtyInput.value : '0') || 0;
        if (qty > 0) lines.push({ doItemId: doItemId, qty: qty });
      });
      if (lines.length === 0) return;
      Amor.apiFetch('/api/dispatch/claim', { method: 'POST', body: { lines: lines } }).then(function () {
        Amor.toast('Pengiriman berhasil diambil', 'success');
        load();
      }).catch(function (err) { Amor.toast(err.message, 'error'); load(); });
    });

    function load() {
      list.innerHTML = '<div class="driver-empty">Memuat...</div>';
      Amor.apiFetch('/api/dispatch/available?tanggal=' + encodeURIComponent(getTanggal())).then(function (data) {
        allItems = data.items;
        draw();
      }).catch(function (err) { list.innerHTML = emptyState(err.message); });
    }
    load();
  }

  // -----------------------------------------------------------------
  // TAB: Pengiriman Saya
  // -----------------------------------------------------------------
  function renderSaya(root) {
    root.innerHTML = '';
    root.appendChild(dateBar(function () { renderSaya(root); }));
    var list = document.createElement('div');
    root.appendChild(list);

    function load() {
      list.innerHTML = '<div class="driver-empty">Memuat...</div>';
      Amor.apiFetch('/api/dispatch/mine?tanggal=' + encodeURIComponent(getTanggal())).then(function (data) {
        if (data.stores.length === 0) {
          list.innerHTML = emptyState('Anda belum mengambil pengiriman apa pun untuk tanggal ini.');
          return;
        }
        list.innerHTML = data.stores.map(function (s) {
          var itemsHtml = s.items.map(function (it) {
            return '<div class="driver-row"><span>' + esc(it.productName) + ' <span class="driver-badge ' + groupBadgeClass(it.suggestedGroup) + '" style="margin-left:4px;">' + esc(it.suggestedGroup) + '</span></span>' +
              '<b>' + fmtNum(it.claimedQty) + '</b></div>' +
              '<div style="text-align:right;margin-bottom:6px;"><button type="button" class="driver-btn danger small" data-release="' + it.claimId + '">Lepas</button></div>';
          }).join('');
          return '' +
            '<div class="driver-card">' +
            '<div class="driver-card-head"><div>' +
            '<div class="driver-card-title">' + esc(s.storeName) + '</div>' +
            '<div class="driver-card-sub">' + esc(s.docNo) + '</div>' +
            '</div></div>' +
            itemsHtml +
            '<a class="driver-btn primary" style="margin-top:8px;" href="stop.php?storeId=' + s.storeId + '&tanggal=' + encodeURIComponent(getTanggal()) + '">Detail Keberangkatan</a>' +
            '</div>';
        }).join('');
        list.querySelectorAll('[data-release]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            Amor.confirmModal('Lepas klaim ini kembali ke pool?').then(function (ok) {
              if (!ok) return;
              Amor.apiFetch('/api/dispatch/claims/' + btn.dataset.release + '/release', { method: 'POST' }).then(function () {
                Amor.toast('Klaim dilepas', 'success');
                load();
              }).catch(function (err) { Amor.toast(err.message, 'error'); });
            });
          });
        });
      }).catch(function (err) { list.innerHTML = emptyState(err.message); });
    }
    load();
  }

  // -----------------------------------------------------------------
  // TAB: Rute Saya
  // -----------------------------------------------------------------
  function renderRute(root) {
    root.innerHTML = '';
    root.appendChild(dateBar(function () { renderRute(root); }));
    var summary = document.createElement('div');
    summary.className = 'driver-summary-strip';
    root.appendChild(summary);
    var list = document.createElement('div');
    root.appendChild(list);

    var stops = [];

    function draw() {
      summary.innerHTML =
        '<div class="driver-summary-item"><div class="driver-summary-value">' + stops.length + '</div><div class="driver-summary-label">Toko</div></div>' +
        '<div class="driver-summary-item"><div class="driver-summary-value">' + fmtNum(stops.reduce(function (a, s) { return a + s.productCount; }, 0)) + '</div><div class="driver-summary-label">Produk</div></div>' +
        '<div class="driver-summary-item"><div class="driver-summary-value">' + fmtNum(stops.reduce(function (a, s) { return a + s.totalQty; }, 0)) + '</div><div class="driver-summary-label">Pcs</div></div>';

      if (stops.length === 0) {
        list.innerHTML = emptyState('Rute Anda kosong — ambil pengiriman dari tab Tersedia dulu.');
        return;
      }
      list.innerHTML = stops.map(function (s, i) {
        var statusBadge = s.departureStatus === 'sudah_berangkat'
          ? '<span class="driver-badge success">Sudah Berangkat</span>'
          : '<span class="driver-badge">Belum Berangkat</span>';
        return '' +
          '<div class="driver-route-stop">' +
          '<div class="driver-route-seq">' + s.sequence + '</div>' +
          '<a class="driver-route-body" style="text-decoration:none;color:inherit;" href="stop.php?storeId=' + s.storeId + '&tanggal=' + encodeURIComponent(getTanggal()) + '">' +
          '<div class="driver-card-title">' + esc(s.storeName) + '</div>' +
          '<div class="driver-card-sub">' + s.productCount + ' produk &middot; ' + fmtNum(s.totalQty) + ' pcs</div>' +
          '</a>' + statusBadge +
          '<div class="driver-route-actions">' +
          '<button type="button" class="driver-route-move" data-move="up" data-i="' + i + '"' + (i === 0 ? ' disabled' : '') + '>&uarr;</button>' +
          '<button type="button" class="driver-route-move" data-move="down" data-i="' + i + '"' + (i === stops.length - 1 ? ' disabled' : '') + '>&darr;</button>' +
          '</div>' +
          '</div>';
      }).join('');

      list.querySelectorAll('[data-move]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var i = parseInt(btn.dataset.i, 10);
          var j = btn.dataset.move === 'up' ? i - 1 : i + 1;
          if (j < 0 || j >= stops.length) return;
          var tmp = stops[i]; stops[i] = stops[j]; stops[j] = tmp;
          draw();
          var storeIds = stops.map(function (s) { return s.storeId; });
          Amor.apiFetch('/api/dispatch/route/reorder', { method: 'POST', body: { tanggal: getTanggal(), storeIds: storeIds } })
            .catch(function (err) { Amor.toast(err.message, 'error'); load(); });
        });
      });
    }

    function load() {
      list.innerHTML = '<div class="driver-empty">Memuat...</div>';
      Amor.apiFetch('/api/dispatch/route?tanggal=' + encodeURIComponent(getTanggal())).then(function (data) {
        stops = data.stops;
        draw();
      }).catch(function (err) { list.innerHTML = emptyState(err.message); });
    }
    load();
  }

  // -----------------------------------------------------------------
  // TAB: Riwayat
  // -----------------------------------------------------------------
  function renderRiwayat(root) {
    root.innerHTML = '<div class="driver-empty">Memuat...</div>';
    Amor.apiFetch('/api/dispatch/history').then(function (rows) {
      if (rows.length === 0) {
        root.innerHTML = emptyState('Belum ada riwayat pengiriman.');
        return;
      }
      root.innerHTML = rows.map(function (r) {
        return '' +
          '<div class="driver-card">' +
          '<div class="driver-card-head"><div>' +
          '<div class="driver-card-title">' + esc(r.store_name) + '</div>' +
          '<div class="driver-card-sub">' + esc(r.doc_no || '-') + ' &middot; ' + esc(r.tanggal) + '</div>' +
          '</div><span class="driver-badge ' + groupBadgeClass(r.shipment_group) + '">' + esc(r.shipment_group) + '</span></div>' +
          '<div class="driver-row"><span>Berangkat</span><b>' + esc(r.shipped_at) + '</b></div>' +
          '</div>';
      }).join('');
    }).catch(function (err) { root.innerHTML = emptyState(err.message); });
  }

  // -----------------------------------------------------------------
  // Stop detail (Konfirmasi Berangkat)
  // -----------------------------------------------------------------
  function renderStopDetail(root, storeId, tanggal) {
    root.innerHTML = '<div class="driver-empty">Memuat...</div>';
    Amor.apiFetch('/api/dispatch/route/stops/' + storeId + '?tanggal=' + encodeURIComponent(tanggal)).then(function (data) {
      if (data.items.length === 0) {
        root.innerHTML = emptyState('Tidak ada klaim aktif Anda untuk toko ini.');
        return;
      }
      var groupOptions = ['MAIN', 'PASTRY', 'OTHER'];
      var defaultGroup = data.items[0].suggestedGroup || 'MAIN';

      root.innerHTML =
        '<div class="driver-card">' +
        '<div class="driver-card-title">' + esc(data.storeName) + '</div>' +
        '<div class="driver-card-sub">' + esc(data.docNo) + '</div>' +
        '</div>' +
        '<div class="driver-card">' +
        '<div class="driver-row"><span>Grup Pengiriman</span></div>' +
        '<select class="driver-search" id="ship-group" style="margin-bottom:0;">' +
        groupOptions.map(function (g) { return '<option value="' + g + '"' + (g === defaultGroup ? ' selected' : '') + '>' + g + '</option>'; }).join('') +
        '</select>' +
        '</div>' +
        data.items.map(function (it) {
          return '' +
            '<div class="driver-card" data-claim-id="' + it.claimId + '">' +
            '<div class="driver-card-title">' + esc(it.productName) + '</div>' +
            '<div class="driver-row"><span>Sisa DO</span><b>' + fmtNum(it.doRemaining) + '</b></div>' +
            '<div class="driver-row"><span>Qty Saya (diklaim)</span><b>' + fmtNum(it.claimedQty) + '</b></div>' +
            '<div class="driver-row"><span>FG Ready</span><b>' + fmtNum(it.fgAvailable) + '</b></div>' +
            '<div class="driver-row" style="align-items:center;margin-top:6px;"><span>Qty Kirim</span>' +
            '<input type="number" class="driver-qty-input" data-actual data-max="' + Math.min(it.claimedQty, it.doRemaining, it.fgAvailable) + '" min="0" step="0.01" value="' + it.defaultActualQty + '"></div>' +
            '</div>';
        }).join('') +
        '<div class="driver-notice">Stok FG akan berkurang setelah pengiriman dikonfirmasi.</div>' +
        '<button type="button" class="driver-btn success" id="btn-berangkat">KONFIRMASI BERANGKAT</button>';

      document.getElementById('btn-berangkat').addEventListener('click', function () {
        Amor.confirmModal('Konfirmasi keberangkatan? Stok FG akan berkurang setelah ini.').then(function (ok) {
          if (!ok) return;
          var items = [];
          root.querySelectorAll('[data-claim-id]').forEach(function (card) {
            var claimId = parseInt(card.dataset.claimId, 10);
            var qtyInput = card.querySelector('[data-actual]');
            var actualQty = parseFloat(qtyInput.value) || 0;
            items.push({ claimId: claimId, actualQty: actualQty });
          });
          var shipmentGroup = document.getElementById('ship-group').value;
          Amor.apiFetch('/api/dispatch/departures', {
            method: 'POST',
            body: { doId: data.doId, expectedVersion: data.doVersion, shipmentGroup: shipmentGroup, items: items },
          }).then(function (result) {
            renderDepartureSuccess(root, data.storeName, result);
          }).catch(function (err) { Amor.toast(err.message, 'error'); });
        });
      });
    }).catch(function (err) { root.innerHTML = emptyState(err.message); });
  }

  function renderDepartureSuccess(root, storeName, result) {
    var totalQty = 0;
    var productCount = 0;
    (result.shipments || []).forEach(function (sh) {
      (sh.items || []).forEach(function (it) { totalQty += it.actualQty; productCount++; });
    });
    root.innerHTML =
      '<div class="driver-success-box">' +
      '<div class="driver-success-icon"><svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-6"/></svg></div>' +
      '<h2>Pengiriman Berhasil Dikonfirmasi</h2>' +
      '<div class="driver-card-title">' + esc(storeName) + '</div>' +
      '<div class="driver-card-sub">' + productCount + ' produk &middot; ' + fmtNum(totalQty) + ' pcs</div>' +
      '<a class="driver-btn primary" style="margin-top:16px;" href="index.php?tab=rute">Kembali ke Rute</a>' +
      '</div>';
  }

  document.addEventListener('DOMContentLoaded', function () {
    var appRoot = document.getElementById('driver-app');
    if (appRoot) {
      var tab = appRoot.dataset.tab;
      if (tab === 'tersedia') renderTersedia(appRoot);
      else if (tab === 'saya') renderSaya(appRoot);
      else if (tab === 'rute') renderRute(appRoot);
      else if (tab === 'riwayat') renderRiwayat(appRoot);
    }
    var stopRoot = document.getElementById('driver-stop-app');
    if (stopRoot) {
      renderStopDetail(stopRoot, stopRoot.dataset.storeId, stopRoot.dataset.tanggal);
    }
  });
})();
