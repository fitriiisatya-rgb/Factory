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

  // Display-only: a "YYYY-MM-DD HH:MM:SS" DB timestamp (always UTC, per
  // this app's UTC_TIMESTAMP() convention) rendered Indonesian-friendly in
  // Asia/Jakarta — "21 Sep 2026 · 10:20" instead of the raw DB string.
  // Never changes what is stored or sent back to the server.
  var ID_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  function fmtDateTimeId(s) {
    if (!s) return '-';
    var str = String(s);
    var iso = str.indexOf('T') === -1 ? str.replace(' ', 'T') : str;
    if (iso.indexOf('Z') === -1 && iso.indexOf('+') === -1) iso += 'Z';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return str;
    try {
      var parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Jakarta', day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false
      }).formatToParts(d);
      var map = {};
      parts.forEach(function (p) { map[p.type] = p.value; });
      return map.day + ' ' + ID_MONTHS[parseInt(map.month, 10) - 1] + ' ' + map.year + ' · ' + map.hour + ':' + map.minute;
    } catch (e) {
      return str;
    }
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
        // Real-UAT navigation fix: a departed stop has no active claims
        // left, so it must NEVER link to stop.php ("Konfirmasi Berangkat")
        // — that screen correctly shows "Tidak ada klaim aktif Anda untuk
        // toko ini." once every claim has resolved, which is not a bug in
        // stop.php, just the wrong destination for an already-departed
        // stop. A departed stop instead links straight to its real
        // shipment (shipmentIds.length === 1) or to the chooser page when
        // it split into more than one (MAIN + PASTRY — see DPT-19). Falls
        // back to stop.php only if shipmentIds is unexpectedly empty for a
        // "departed" stop — never worse than the pre-fix behavior.
        var stopHref;
        if (s.departureStatus === 'sudah_berangkat' && s.shipmentIds && s.shipmentIds.length === 1) {
          stopHref = 'shipment.php?id=' + s.shipmentIds[0];
        } else if (s.departureStatus === 'sudah_berangkat' && s.shipmentIds && s.shipmentIds.length > 1) {
          stopHref = 'shipment.php?storeId=' + s.storeId + '&tanggal=' + encodeURIComponent(getTanggal());
        } else {
          stopHref = 'stop.php?storeId=' + s.storeId + '&tanggal=' + encodeURIComponent(getTanggal());
        }
        return '' +
          '<div class="driver-route-stop">' +
          '<div class="driver-route-seq">' + s.sequence + '</div>' +
          '<a class="driver-route-body" style="text-decoration:none;color:inherit;" href="' + stopHref + '">' +
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
          '<a class="driver-card driver-card-link" href="shipment.php?id=' + r.shipment_id + '">' +
          '<div class="driver-card-head"><div>' +
          '<div class="driver-card-title">' + esc(r.store_name) + '<span class="driver-card-chevron">&rsaquo;</span></div>' +
          '<div class="driver-card-sub">' + esc(r.doc_no || '-') + '</div>' +
          '</div><span class="driver-badge ' + groupBadgeClass(r.shipment_group) + '">' + esc(r.shipment_group) + '</span></div>' +
          '<div class="driver-row"><span>Berangkat</span><b>' + fmtDateTimeId(r.shipped_at || r.created_at) + '</b></div>' +
          '<div class="driver-row"><span>' + Number(r.product_count || 0) + ' produk &middot; ' + fmtNum(r.total_qty) + ' pcs</span>' +
          '<span class="driver-badge success">Sudah Berangkat</span></div>' +
          '</a>';
      }).join('');
    }).catch(function (err) { root.innerHTML = emptyState(err.message); });
  }

  // -----------------------------------------------------------------
  // Detail Pengiriman (read-only shipment tracing)
  // -----------------------------------------------------------------
  function renderShipmentDetail(root, shipmentId) {
    root.innerHTML = '<div class="driver-empty">Memuat...</div>';
    Amor.apiFetch('/api/dispatch/shipments/' + shipmentId).then(function (d) {
      var itemsHtml = d.items.map(function (it) {
        return '<div class="driver-row"><span>' + esc(it.productName) + '</span><b>' + fmtNum(it.qty) + '</b></div>';
      }).join('');

      var receiptHtml = renderReceiptSection(d.receipt, d.summary.totalQty);
      var timelineHtml = renderTimeline(d);

      root.innerHTML =
        '<a href="riwayat-back" class="driver-back-link" id="btn-back-riwayat">&larr; Kembali ke Riwayat</a>' +
        '<div class="driver-card">' +
        '<div class="driver-card-title">' + esc(d.storeName) + '</div>' +
        '<div class="driver-row"><span>Shipment</span><b>SHP-' + d.shipmentId + '</b></div>' +
        '<div class="driver-row"><span>No. DO</span><b>' + esc(d.docNo || '-') + '</b></div>' +
        '<div class="driver-row"><span>Tanggal DO</span><b>' + esc(d.doTanggal || d.tanggal || '-') + '</b></div>' +
        '<div class="driver-row"><span>Berangkat</span><b>' + fmtDateTimeId(d.shippedAt) + '</b></div>' +
        '<div class="driver-row"><span>Driver</span><b>' + esc(d.driverName || '-') + '</b></div>' +
        '<div class="driver-row"><span>Grup</span><span class="driver-badge ' + groupBadgeClass(d.shipmentGroup) + '">' + esc(d.shipmentGroup) + '</span></div>' +
        '<div class="driver-row"><span>Factory asal</span><b>' + esc(d.factoryName || '-') + '</b></div>' +
        '<div class="driver-row"><span>Status</span><span class="driver-badge success">Sudah Berangkat</span></div>' +
        '</div>' +
        '<div class="driver-card">' +
        '<div class="driver-card-title" style="margin-bottom:6px;">Produk Dikirim</div>' +
        itemsHtml +
        '<div class="driver-row" style="border-top:1px solid var(--border);margin-top:6px;padding-top:8px;">' +
        '<span><b>Total</b></span><b>' + d.items.length + ' Produk &middot; ' + fmtNum(d.summary.totalQty) + ' Pcs</b></div>' +
        '</div>' +
        receiptHtml +
        timelineHtml;

      document.getElementById('btn-back-riwayat').addEventListener('click', function (e) {
        e.preventDefault();
        window.location.href = 'index.php?tab=riwayat';
      });
    }).catch(function (err) {
      root.innerHTML =
        '<a href="index.php?tab=riwayat" class="driver-back-link">&larr; Kembali ke Riwayat</a>' +
        emptyState(err.message);
    });
  }

  // Real-UAT navigation fix: shown at shipment.php?storeId=&tanggal=
  // (no ?id=) when a departed route stop has more than one shipment (e.g.
  // MAIN + PASTRY under the same DO — DPT-19/DR-NAV04). Never guesses
  // which one to open — exactly one shipment opens it directly, more than
  // one shows this chooser, matching the task's own worked example.
  function renderShipmentChooser(root, storeId, tanggal) {
    root.innerHTML = '<div class="driver-empty">Memuat...</div>';
    Amor.apiFetch('/api/dispatch/route/stops/' + storeId + '/shipments?tanggal=' + encodeURIComponent(tanggal)).then(function (data) {
      if (data.shipments.length === 0) {
        root.innerHTML =
          '<a href="index.php?tab=rute" class="driver-back-link">&larr; Kembali ke Rute</a>' +
          emptyState('Belum ada pengiriman untuk toko ini pada tanggal ini.');
        return;
      }
      if (data.shipments.length === 1) {
        renderShipmentDetail(root, data.shipments[0].shipmentId);
        return;
      }
      root.innerHTML =
        '<a href="index.php?tab=rute" class="driver-back-link">&larr; Kembali ke Rute</a>' +
        '<div class="driver-card-title" style="margin:8px 0;">Pengiriman untuk ' + esc(data.storeName) + '</div>' +
        data.shipments.map(function (sh) {
          return '' +
            '<a class="driver-card driver-card-link" href="shipment.php?id=' + sh.shipmentId + '">' +
            '<div class="driver-card-head"><div>' +
            '<div class="driver-card-title">SHP-' + sh.shipmentId + '<span class="driver-card-chevron">&rsaquo;</span></div>' +
            '<div class="driver-card-sub">' + fmtDateTimeId(sh.shippedAt) + '</div>' +
            '</div><span class="driver-badge ' + groupBadgeClass(sh.shipmentGroup) + '">' + esc(sh.shipmentGroup) + '</span></div>' +
            '<div class="driver-row"><span>' + sh.productCount + ' produk &middot; ' + fmtNum(sh.totalQty) + ' pcs</span></div>' +
            '</a>';
        }).join('');
    }).catch(function (err) {
      root.innerHTML = '<a href="index.php?tab=rute" class="driver-back-link">&larr; Kembali ke Rute</a>' + emptyState(err.message);
    });
  }

  function receiptStatusLabel(status) {
    if (status === 'confirmed_ok') return 'Diterima Sesuai';
    if (status === 'confirmed_discrepancy') return 'Ada Selisih';
    if (status === 'verified') return 'Diverifikasi Admin';
    return 'Belum Dikonfirmasi';
  }

  function renderReceiptSection(receipt, totalShipped) {
    if (!receipt) {
      return '' +
        '<div class="driver-card">' +
        '<div class="driver-card-title" style="margin-bottom:6px;">Penerimaan Toko</div>' +
        '<div class="driver-row"><span>Status</span><span class="driver-badge">Belum Dikonfirmasi</span></div>' +
        '</div>';
    }
    var goodQty = 0, rejectQty = 0, shortQty = 0;
    receipt.items.forEach(function (ri) {
      goodQty += ri.receivedGoodQty; rejectQty += ri.rejectQty; shortQty += ri.shortageQty;
    });
    var badgeClass = receipt.status === 'confirmed_discrepancy' ? 'danger' : 'success';
    var reasons = receipt.items.filter(function (ri) { return ri.reason; })
      .map(function (ri) { return esc(ri.productName) + ': ' + esc(ri.reason); }).join('; ');
    return '' +
      '<div class="driver-card">' +
      '<div class="driver-card-title" style="margin-bottom:6px;">Penerimaan Toko</div>' +
      '<div class="driver-row"><span>Dikirim</span><b>' + fmtNum(totalShipped) + '</b></div>' +
      '<div class="driver-row"><span>Diterima Baik</span><b>' + fmtNum(goodQty) + '</b></div>' +
      '<div class="driver-row"><span>Reject</span><b>' + fmtNum(rejectQty) + '</b></div>' +
      '<div class="driver-row"><span>Kurang</span><b>' + fmtNum(shortQty) + '</b></div>' +
      '<div class="driver-row"><span>Status</span><span class="driver-badge ' + badgeClass + '">' + receiptStatusLabel(receipt.status) + '</span></div>' +
      (receipt.receiverName ? '<div class="driver-row"><span>Dikonfirmasi oleh</span><b>' + esc(receipt.receiverName) + '</b></div>' : '') +
      (receipt.confirmedAt ? '<div class="driver-row"><span>Waktu</span><b>' + fmtDateTimeId(receipt.confirmedAt) + '</b></div>' : '') +
      (receipt.verifiedByName ? '<div class="driver-row"><span>Diverifikasi oleh</span><b>' + esc(receipt.verifiedByName) + '</b></div>' : '') +
      (receipt.verifiedAt ? '<div class="driver-row"><span>Waktu Verifikasi</span><b>' + fmtDateTimeId(receipt.verifiedAt) + '</b></div>' : '') +
      (reasons ? '<div class="driver-notice" style="margin-top:8px;margin-bottom:0;">' + reasons + '</div>' : '') +
      '</div>';
  }

  function renderTimeline(d) {
    var steps = [];
    if (d.claim) {
      steps.push({ done: true, title: 'Driver Claim', sub: esc(d.claim.driverName || '-') + ' &middot; ' + fmtDateTimeId(d.claim.claimedAt) });
    }
    steps.push({ done: true, title: 'Berangkat', sub: 'Shipment SHP-' + d.shipmentId + ' &middot; ' + fmtDateTimeId(d.shippedAt) });
    if (d.receipt) {
      steps.push({ done: true, title: 'Konfirmasi Toko', sub: esc(d.receipt.receiverName || '-') + ' &middot; ' + fmtDateTimeId(d.receipt.confirmedAt) });
      if (d.receipt.verifiedAt) {
        steps.push({ done: true, title: 'Diverifikasi Admin', sub: esc(d.receipt.verifiedByName || '-') + ' &middot; ' + fmtDateTimeId(d.receipt.verifiedAt) });
      } else {
        steps.push({ done: false, title: 'Diverifikasi Admin', sub: 'Belum diverifikasi' });
      }
    } else {
      steps.push({ done: false, title: 'Konfirmasi Toko', sub: 'Belum dikonfirmasi' });
    }
    return '' +
      '<div class="driver-card">' +
      '<div class="driver-card-title" style="margin-bottom:8px;">Riwayat Proses</div>' +
      steps.map(function (s) {
        return '<div class="driver-timeline-step' + (s.done ? ' done' : '') + '">' +
          '<span class="driver-timeline-mark">' + (s.done ? '&#10003;' : '&#9675;') + '</span>' +
          '<div><div class="driver-timeline-title">' + esc(s.title) + '</div><div class="driver-card-sub">' + s.sub + '</div></div>' +
          '</div>';
      }).join('') +
      '</div>';
  }

  // -----------------------------------------------------------------
  // Stop detail (Konfirmasi Berangkat)
  // -----------------------------------------------------------------
  function renderStopDetail(root, storeId, tanggal) {
    root.innerHTML = '<div class="driver-empty">Memuat...</div>';
    Amor.apiFetch('/api/dispatch/route/stops/' + storeId + '?tanggal=' + encodeURIComponent(tanggal)).then(function (data) {
      if (data.items.length === 0) {
        // Real-UAT UX fix: reached only via a stale/bookmarked URL now
        // that the Rute Saya card itself never links here once a stop has
        // departed (see renderRute()'s own comment) — but if it IS
        // reached, a contextual message + a real link beats a dead-end
        // "no active claim" message.
        if (data.shipments && data.shipments.length > 0) {
          var link = data.shipments.length === 1
            ? 'shipment.php?id=' + data.shipments[0].shipmentId
            : 'shipment.php?storeId=' + storeId + '&tanggal=' + encodeURIComponent(tanggal);
          root.innerHTML =
            '<div class="driver-empty">' + Amor_icon() + '<div>Pengiriman ini sudah diberangkatkan.</div>' +
            '<a class="driver-btn primary" style="margin-top:12px;display:inline-block;" href="' + link + '">Lihat Detail Pengiriman</a>' +
            '</div>';
          return;
        }
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
        var items = [];
        root.querySelectorAll('[data-claim-id]').forEach(function (card) {
          var claimId = parseInt(card.dataset.claimId, 10);
          var qtyInput = card.querySelector('[data-actual]');
          var actualQty = parseFloat(qtyInput.value) || 0;
          items.push({ claimId: claimId, actualQty: actualQty });
        });
        var shipmentGroup = document.getElementById('ship-group').value;
        var productCount = items.filter(function (it) { return it.actualQty > 0; }).length;
        var totalQty = items.reduce(function (a, it) { return a + it.actualQty; }, 0);
        var departureResult = null;

        // Real-UAT fix: this used to call Amor.confirmModal() with a plain
        // STRING argument — confirmModal only ever reads opts.title/opts.body
        // off an OPTIONS OBJECT, so that string was silently ignored and the
        // dialog always showed the generic fallback text, on top of the
        // missing CSS that made it render unstyled/stacked at the bottom of
        // the page (see driver.css's own docblock for that half of the fix).
        // onConfirm keeps the dialog open (with a "Memproses..." pending
        // state on its own button) for the actual API call, so a second tap
        // on "Ya, Konfirmasi Berangkat" can never fire twice.
        Amor.confirmModal({
          title: 'Konfirmasi Keberangkatan',
          body: 'Pastikan jumlah barang yang dikirim sudah sesuai.\nSetelah dikonfirmasi, stok FG akan berkurang dan shipment akan dibuat.',
          summaryLines: [data.storeName, productCount + ' produk', fmtNum(totalQty) + ' pcs'],
          confirmLabel: 'Ya, Konfirmasi Berangkat',
          onConfirm: function () {
            return Amor.apiFetch('/api/dispatch/departures', {
              method: 'POST',
              body: { doId: data.doId, expectedVersion: data.doVersion, shipmentGroup: shipmentGroup, items: items },
            }).then(function (result) {
              departureResult = result;
              // ShipmentService's own DTO carries no timestamp (and this
              // patch must not touch ShipmentService) — read the REAL
              // shipped_at back from the shipment we just created, via the
              // new read-only detail endpoint, rather than ever showing the
              // client's own clock as if it were the server's.
              var firstId = result.shipments && result.shipments[0] ? result.shipments[0].shipmentId : null;
              if (!firstId) return;
              return Amor.apiFetch('/api/dispatch/shipments/' + firstId).then(function (detail) {
                departureResult.shippedAt = detail.shippedAt;
              }).catch(function () { /* non-fatal — success screen just omits the exact time */ });
            });
          },
        }).then(function (ok) {
          if (ok) renderDepartureSuccess(root, data.storeName, departureResult);
        });
      });
    }).catch(function (err) { root.innerHTML = emptyState(err.message); });
  }

  function renderDepartureSuccess(root, storeName, result) {
    var totalQty = 0;
    var productCount = 0;
    var shipmentIds = [];
    var shippedAt = result.shippedAt || null;
    (result.shipments || []).forEach(function (sh) {
      shipmentIds.push(sh.shipmentId);
      (sh.items || []).forEach(function (it) { totalQty += it.actualQty; productCount++; });
    });
    // Real created shipment identity only — never a fabricated number (task's
    // own "Do NOT invent a shipment number if the backend response does not
    // provide one"). shipmentId IS the real, server-assigned identity;
    // "SHP-" is purely a display prefix, same numeric id underneath.
    var shipmentLine = shipmentIds.length === 1
      ? 'SHP-' + shipmentIds[0]
      : shipmentIds.map(function (id) { return 'SHP-' + id; }).join(', ');
    var detailHref = shipmentIds.length === 1 ? 'shipment.php?id=' + shipmentIds[0] : null;

    root.innerHTML =
      '<div class="driver-success-box">' +
      '<div class="driver-success-icon"><svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-6"/></svg></div>' +
      '<h2>Pengiriman Berhasil Dikonfirmasi</h2>' +
      '<div class="driver-card-title">' + esc(storeName) + '</div>' +
      (shipmentIds.length ? '<div class="driver-card-sub">Shipment: ' + esc(shipmentLine) + '</div>' : '') +
      '<div class="driver-card-sub">' + productCount + ' produk &middot; ' + fmtNum(totalQty) + ' pcs</div>' +
      (shippedAt ? '<div class="driver-card-sub">Waktu Berangkat: ' + fmtDateTimeId(shippedAt) + '</div>' : '') +
      (detailHref ? '<a class="driver-btn primary" style="margin-top:16px;" href="' + detailHref + '">Lihat Detail Pengiriman</a>' : '') +
      '<a class="driver-btn' + (detailHref ? '' : ' primary') + '" style="margin-top:8px;" href="index.php?tab=rute">Kembali ke Rute</a>' +
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
    var shipmentRoot = document.getElementById('driver-shipment-app');
    if (shipmentRoot) {
      var directId = parseInt(shipmentRoot.dataset.shipmentId, 10) || 0;
      if (directId > 0) {
        renderShipmentDetail(shipmentRoot, directId);
      } else {
        renderShipmentChooser(shipmentRoot, shipmentRoot.dataset.storeId, shipmentRoot.dataset.tanggal);
      }
    }

    var logoutBtn = document.getElementById('btn-driver-logout');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', function () {
        Amor.confirmModal({ title: 'Logout?', body: 'Anda akan keluar dari sesi ini.', confirmLabel: 'Ya, Logout' }).then(function (ok) {
          if (!ok) return;
          Amor.apiFetch('/api/auth/logout', { method: 'POST' })
            .catch(function () { /* logout errors are non-fatal — still redirect */ })
            .then(function () { window.location.href = 'login.php'; });
        });
      });
    }
  });
})();
