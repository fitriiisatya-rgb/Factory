/*
 * Permanent Bakery Portal — "Retur, Reject & Mutasi" (migration 0026).
 * The stores' old Google Apps Script form, inside Factory: one transaction
 * holds many items in three tabs (Retur / Reject / Mutasi), the Head Store
 * approves it, and the store can print proof on A4 or an 80 mm thermal
 * roll (RawBT). Uses the helpers store.js exposes as window.StoreKit.
 * Every number and status shown comes from /api/store/{token}/reports.
 */
(function () {
  'use strict';

  var PRODUCTS = window.STORE_REPORT_PRODUCTS || [];
  var STORES = window.STORE_REPORT_STORES || [];
  var REASONS = window.STORE_REPORT_REASONS || [];
  var OTHER_REASON = '✏️ Alasan lainnya (tulis sendiri)';
  var JENIS = {
    retur: { label: 'Retur', icon: '↩️', hint: 'RETUR = barang masih baik tapi tidak laku. Hanya dicatat, tagihan tetap. Foto boleh, tidak wajib.' },
    reject: { label: 'Reject', icon: '❌', hint: 'REJECT = rusak / berjamur / cacat SEBELUM tanggal expired. Ditanggung pabrik (tagihan dipotong). Alasan & foto wajib.' },
    mutasi: { label: 'Mutasi', icon: '🔁', hint: 'MUTASI = barang dipindah ke toko lain. Toko tujuan akan mengonfirmasi jumlah yang diterima; tagihannya ikut pindah.' },
  };

  function todayLocal() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function rp(n) { return 'Rp ' + Math.round(Number(n) || 0).toLocaleString('id-ID'); }
  function fmtTgl(s) {
    if (!s) return '-';
    var p = String(s).slice(0, 10).split('-');
    var bln = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return p.length === 3 ? (parseInt(p[2], 10) + ' ' + bln[parseInt(p[1], 10) - 1] + ' ' + p[0]) : String(s);
  }
  function productById(id) {
    for (var i = 0; i < PRODUCTS.length; i++) if (PRODUCTS[i].productId === id) return PRODUCTS[i];
    return null;
  }

  /** Search-as-you-type picker: input + floating list. getOptions(q) -> items, label(item) -> html, onPick(item). */
  function combo(input, list, getOptions, label, onPick) {
    function open() {
      var items = getOptions(input.value.trim().toLowerCase());
      list.innerHTML = items.length ? '' : '<div class="sr-combo-empty">Tidak ditemukan</div>';
      items.slice(0, 60).forEach(function (it) {
        var d = document.createElement('div');
        d.className = 'sr-combo-item';
        d.innerHTML = label(it);
        d.addEventListener('mousedown', function (e) { e.preventDefault(); onPick(it); list.style.display = 'none'; });
        list.appendChild(d);
      });
      list.style.display = 'block';
    }
    input.addEventListener('focus', open);
    input.addEventListener('input', open);
    input.addEventListener('blur', function () { setTimeout(function () { list.style.display = 'none'; }, 150); });
  }

  function render(root) {
    var K = window.StoreKit;
    var esc = K.esc;
    var params = new URLSearchParams(location.search);
    var state = { view: params.get('view') === 'riwayat' ? 'riwayat' : 'input', tab: 'retur', editing: null, last: null };
    var blocks = { retur: [], reject: [], mutasi: [] };
    var seq = 0;

    root.innerHTML = '';
    var nav = document.createElement('div');
    nav.className = 'sr-nav';
    nav.innerHTML = '<button type="button" data-view="input">📝 Input Baru</button><button type="button" data-view="riwayat">📜 Riwayat Input</button>';
    root.appendChild(nav);
    var inputView = document.createElement('div');
    var resultView = document.createElement('div');
    var riwayatView = document.createElement('div');
    root.appendChild(inputView);
    root.appendChild(resultView);
    root.appendChild(riwayatView);
    nav.querySelectorAll('button').forEach(function (b) {
      b.addEventListener('click', function () { showView(b.dataset.view); });
    });

    // ---------------------------------------------------------------
    // Input form
    // ---------------------------------------------------------------
    var form = K.card(
      '<div class="driver-card-title" style="margin-bottom:6px;">📦 Input Retur, Reject &amp; Mutasi</div>' +
      '<div class="sr-banner" id="sr-edit" style="display:none"><span>✏️ Mengedit <b id="sr-edit-no"></b></span><button type="button" id="sr-edit-cancel">Batal Edit</button></div>' +
      '<div class="store-field"><label>Tanggal</label><input type="date" id="sr-date"></div>' +
      '<div class="sr-tabs">' +
      ['retur', 'reject', 'mutasi'].map(function (j) {
        return '<button type="button" class="sr-tab ' + j + '" data-tab="' + j + '">' + JENIS[j].icon + ' ' + JENIS[j].label + '<span class="sr-count" data-count="' + j + '">0</span></button>';
      }).join('') + '</div>' +
      ['retur', 'reject', 'mutasi'].map(function (j) {
        return '<div class="sr-panel" data-panel="' + j + '" style="display:none">' +
          '<div class="sr-hint ' + j + '">' + esc(JENIS[j].hint) + '</div>' +
          (j === 'mutasi' ? '<div class="sr-dest store-field"><label>Toko Tujuan</label><select id="sr-dest"><option value="">— Pilih toko tujuan —</option>' +
            STORES.map(function (s) { return '<option value="' + s.storeId + '">' + esc(s.name) + '</option>'; }).join('') + '</select></div>' : '') +
          '<div data-items="' + j + '"></div>' +
          '<div class="sr-empty" data-empty="' + j + '">Belum ada item ' + JENIS[j].label.toLowerCase() + '.</div>' +
          '<button type="button" class="sr-add ' + j + '" data-add="' + j + '">+ Tambah Item ' + JENIS[j].label + '</button></div>';
      }).join('') +
      '<button type="button" class="driver-btn primary" id="sr-save" style="margin-top:10px">Simpan Semua (<span id="sr-total">0</span> Item)</button>'
    );
    inputView.appendChild(form);
    var dateEl = form.querySelector('#sr-date');
    var destEl = form.querySelector('#sr-dest');
    dateEl.value = todayLocal();
    dateEl.max = todayLocal();

    form.querySelectorAll('.sr-tab').forEach(function (b) { b.addEventListener('click', function () { setTab(b.dataset.tab); }); });
    form.querySelectorAll('[data-add]').forEach(function (b) {
      b.addEventListener('click', function () { var blk = addBlock(b.dataset.add); blk.el.querySelector('.sr-product').focus(); });
    });
    form.querySelector('#sr-edit-cancel').addEventListener('click', function () { resetForm(); });
    form.querySelector('#sr-save').addEventListener('click', save);

    function setTab(j) {
      state.tab = j;
      form.querySelectorAll('.sr-tab').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === j); });
      form.querySelectorAll('.sr-panel').forEach(function (p) { p.style.display = p.dataset.panel === j ? 'block' : 'none'; });
    }

    function renumber() {
      var total = 0;
      ['retur', 'reject', 'mutasi'].forEach(function (j) {
        blocks[j].forEach(function (b, i) { b.el.querySelector('.sr-no').textContent = JENIS[j].label + ' #' + (i + 1); });
        form.querySelector('[data-count="' + j + '"]').textContent = blocks[j].length;
        form.querySelector('[data-empty="' + j + '"]').style.display = blocks[j].length ? 'none' : 'block';
        total += blocks[j].length;
      });
      form.querySelector('#sr-total').textContent = total;
    }

    function addBlock(j, data) {
      var id = ++seq;
      var el = document.createElement('div');
      el.className = 'sr-item ' + j;
      el.innerHTML =
        '<div class="sr-item-head"><span class="sr-no"></span><button type="button" class="sr-del">🗑 Hapus</button></div>' +
        '<div class="store-field sr-combo"><label>Nama Produk</label><input type="text" class="sr-product" autocomplete="off" placeholder="Ketik untuk cari produk..."><div class="sr-combo-list"></div></div>' +
        '<div class="sr-info"></div>' +
        '<div class="store-field"><label>Qty (pcs)</label><input type="number" class="sr-qty" min="1" step="1" inputmode="numeric" data-stepper="1"></div>' +
        (j === 'reject'
          ? '<div class="store-field sr-combo"><label>Alasan Reject</label><input type="text" class="sr-reason" autocomplete="off" placeholder="Ketik / pilih alasan..."><div class="sr-combo-list sr-reason-list"></div></div>' +
            '<div class="store-field sr-other" style="display:none"><label>Tulis alasan</label><textarea class="sr-other-text" rows="2"></textarea></div>' +
            '<div class="store-field"><label>Tanggal Expired (di kemasan)</label><input type="date" class="sr-exp"></div>'
          : '') +
        '<div class="sr-ev"></div><div class="store-field-hint sr-ev-kept" style="display:none"></div>';
      var blk = { id: id, jenis: j, el: el, productId: null, reason: '', itemType: null, itemId: null, keptEvidence: 0 };
      var ev = K.buildEvidenceField({
        label: j === 'reject' ? 'Foto Bukti (wajib)' : 'Foto Bukti (opsional)',
        hint: j === 'reject' ? 'Foto barang yang rusak — maksimal 3 foto.' : 'Boleh dikosongkan.',
        required: j === 'reject',
      });
      blk.evidence = ev;
      el.querySelector('.sr-ev').appendChild(ev.element);

      var pInput = el.querySelector('.sr-product');
      var info = el.querySelector('.sr-info');
      function pick(p) {
        blk.productId = p.productId;
        pInput.value = p.name;
        info.innerHTML = esc(p.kategori || '-') + ' · ' + esc(p.pabrik || '-') + ' · <b>' + rp(p.harga) + '</b>/pcs';
      }
      combo(pInput, el.querySelector('.sr-combo-list'), function (q) {
        return PRODUCTS.filter(function (p) { return !q || p.name.toLowerCase().indexOf(q) !== -1; });
      }, function (p) {
        return esc(p.name) + ' <small>(' + esc(p.kategori || '-') + ') — ' + rp(p.harga) + '</small>';
      }, pick);
      pInput.addEventListener('input', function () { blk.productId = null; info.textContent = ''; });
      pInput.addEventListener('blur', function () {
        setTimeout(function () {
          if (blk.productId) return;
          var v = pInput.value.trim().toLowerCase();
          var exact = PRODUCTS.filter(function (p) { return p.name.toLowerCase() === v; });
          if (exact.length === 1) pick(exact[0]);
        }, 200);
      });

      if (j === 'reject') {
        var rInput = el.querySelector('.sr-reason');
        var other = el.querySelector('.sr-other');
        var setReason = function (r) {
          if (r === OTHER_REASON) { blk.reason = OTHER_REASON; rInput.value = OTHER_REASON; other.style.display = 'block'; other.querySelector('textarea').focus(); }
          else { blk.reason = r; rInput.value = r; other.style.display = 'none'; }
        };
        combo(rInput, el.querySelector('.sr-reason-list'), function (q) {
          return REASONS.filter(function (r) { return !q || r.toLowerCase().indexOf(q) !== -1; }).concat([OTHER_REASON]);
        }, function (r) { return esc(r); }, setReason);
        rInput.addEventListener('input', function () { blk.reason = ''; other.style.display = 'none'; });
        rInput.addEventListener('blur', function () {
          setTimeout(function () {
            if (blk.reason) return;
            var v = rInput.value.trim().toLowerCase();
            var exact = REASONS.filter(function (r) { return r.toLowerCase() === v; });
            if (exact.length === 1) setReason(exact[0]);
          }, 200);
        });
        blk.setReason = setReason;
      }

      el.querySelector('.sr-del').addEventListener('click', function () {
        blocks[j] = blocks[j].filter(function (b) { return b.id !== id; });
        el.remove();
        renumber();
      });
      form.querySelector('[data-items="' + j + '"]').appendChild(el);
      blocks[j].push(blk);

      if (data) {
        var p = productById(data.productId) || { productId: data.productId, name: data.productName, kategori: data.kategori, pabrik: data.pabrik, harga: data.harga };
        pick(p);
        el.querySelector('.sr-qty').value = data.qty;
        blk.itemType = data.itemType;
        blk.itemId = data.itemId;
        blk.keptEvidence = data.evidenceCount || 0;
        if (blk.keptEvidence) {
          var kept = el.querySelector('.sr-ev-kept');
          kept.textContent = '📷 ' + blk.keptEvidence + ' foto sudah tersimpan. Tambah foto baru bila perlu.';
          kept.style.display = 'block';
        }
        if (j === 'reject') {
          if (data.alasan && REASONS.indexOf(data.alasan) !== -1) blk.setReason(data.alasan);
          else if (data.alasan) { blk.setReason(OTHER_REASON); el.querySelector('.sr-other-text').value = data.alasan; }
          el.querySelector('.sr-exp').value = data.expDate || '';
        }
      }
      renumber();
      return blk;
    }

    function resetForm() {
      ['retur', 'reject', 'mutasi'].forEach(function (j) { blocks[j].forEach(function (b) { b.el.remove(); }); blocks[j] = []; });
      state.editing = null;
      form.querySelector('#sr-edit').style.display = 'none';
      dateEl.value = todayLocal();
      if (destEl) destEl.value = '';
      renumber();
      setTab('retur');
    }

    function collect() {
      var all = [].concat(blocks.retur, blocks.reject, blocks.mutasi);
      if (!all.length) throw new Error('Tambahkan minimal 1 item (Retur, Reject, atau Mutasi).');
      var dest = destEl ? parseInt(destEl.value, 10) || null : null;
      if (blocks.mutasi.length && !dest) { setTab('mutasi'); throw new Error('Pilih Toko Tujuan untuk item mutasi.'); }
      var items = [];
      var files = [];
      all.forEach(function (b) {
        var name = b.el.querySelector('.sr-product').value || 'item';
        if (!b.productId) { setTab(b.jenis); throw new Error(JENIS[b.jenis].label + ': pilih produk dari daftar (' + name + ').'); }
        var qty = parseFloat(b.el.querySelector('.sr-qty').value);
        if (!(qty > 0)) { setTab(b.jenis); throw new Error(name + ': isi jumlah (Qty).'); }
        var it = { jenis: b.jenis, productId: b.productId, qty: qty };
        if (b.itemId) { it.itemType = b.itemType; it.itemId = b.itemId; }
        if (b.jenis === 'reject') {
          var alasan = b.reason === OTHER_REASON ? b.el.querySelector('.sr-other-text').value.trim() : b.reason;
          if (!alasan) { setTab('reject'); throw new Error(name + ': pilih alasan reject dari daftar.'); }
          it.alasan = alasan;
          it.expDate = b.el.querySelector('.sr-exp').value || '';
          if (!b.evidence.getFiles().length && !b.keptEvidence) { setTab('reject'); throw new Error(name + ': foto bukti wajib untuk Reject.'); }
        }
        items.push(it);
        files.push(b.evidence.getFiles());
      });
      return { payload: { tanggal: dateEl.value, destinationStoreId: dest, items: items }, files: files };
    }

    function save() {
      var data;
      try { data = collect(); } catch (e) { Amor.toast(e.message, 'danger'); return; }
      var n = { retur: blocks.retur.length, reject: blocks.reject.length, mutasi: blocks.mutasi.length };
      var lines = [];
      if (n.retur) lines.push('↩️ Retur: ' + n.retur + ' item (dicatat, tagihan tetap)');
      if (n.reject) lines.push('❌ Reject: ' + n.reject + ' item (rusak sebelum expired, potong tagihan)');
      if (n.mutasi) lines.push('🔁 Mutasi: ' + n.mutasi + ' item ke ' + destEl.options[destEl.selectedIndex].text);
      var saved = null;
      Amor.confirmModal({
        title: state.editing ? 'Simpan perubahan?' : 'Simpan transaksi?',
        body: 'Tanggal ' + fmtTgl(dateEl.value) + '. Pastikan Retur dan Reject tidak tertukar.',
        summaryLines: lines,
        confirmLabel: 'Ya, Simpan',
        pendingLabel: 'Menyimpan...',
        onConfirm: function () {
          var fd = new FormData();
          fd.append('report', JSON.stringify(data.payload));
          data.files.forEach(function (fs, i) { K.appendToForm(fd, 'evidence_' + i + '[]', fs); });
          var path = state.editing ? '/reports/' + state.editing.reportId : '/reports';
          return K.postForm(K.apiPath(path), fd).then(function (d) { saved = d; });
        },
      }).then(function (ok) {
        if (!ok) return;
        var dto = saved;
        var wasEdit = !!state.editing;
        resetForm();
        if (wasEdit) { Amor.toast('Transaksi diperbarui.', 'success'); showView('riwayat'); return; }
        if (dto) showResult(dto);
      });
    }

    // ---------------------------------------------------------------
    // After save: print buttons
    // ---------------------------------------------------------------
    function printUrl(reportId, format) {
      return 'print.php?token=' + encodeURIComponent(window.STORE_TOKEN) + '&id=' + reportId + '&format=' + format;
    }
    function statusLine(r) {
      if (r.status === 'disetujui') return r.approvalMode === 'otomatis' ? '✓ Langsung diteruskan ke pabrik (toko ini tanpa Atasan).' : '✓ Disetujui Atasan.';
      return '⏳ Menunggu persetujuan Atasan. Selama menunggu, transaksi masih bisa diedit di Riwayat Input.';
    }
    function showResult(r) {
      state.last = r;
      resultView.innerHTML = '';
      var c = K.card(
        '<div class="driver-card-title">✅ Data Tersimpan</div>' +
        '<div class="driver-row" style="display:block">' + r.items.length + ' item tersimpan. ID Transaksi: <b>' + esc(r.docNo) + '</b></div>' +
        '<div class="sr-hint">' + esc(statusLine(r)) + '</div>' +
        '<a class="driver-btn primary" target="_blank" href="' + printUrl(r.reportId, 'a4') + '" style="display:block;text-align:center;margin-top:10px">🖨️ Cetak / Download Bukti (A4)</a>' +
        '<a class="driver-btn" target="_blank" href="' + printUrl(r.reportId, 'thermal') + '" style="display:block;text-align:center;margin-top:8px">🖨️ Cetak Thermal (80mm)</a>' +
        '<button type="button" class="driver-btn" id="sr-new" style="margin-top:8px">+ Input Baru</button>'
      );
      c.querySelector('#sr-new').addEventListener('click', function () { showView('input'); });
      resultView.appendChild(c);
      showView('result');
    }

    // ---------------------------------------------------------------
    // Riwayat Input
    // ---------------------------------------------------------------
    var filt = K.card(
      '<div class="driver-card-title" style="margin-bottom:8px">📜 Riwayat Input</div>' +
      '<div style="display:flex;gap:8px"><div class="store-field" style="flex:1"><label>Dari</label><input type="date" id="sr-from"></div>' +
      '<div class="store-field" style="flex:1"><label>Sampai</label><input type="date" id="sr-to"></div></div>' +
      '<div class="store-field"><label>Cari nama produk</label><input type="text" id="sr-q" placeholder="Ketik nama produk..."></div>' +
      '<button type="button" class="driver-btn primary" id="sr-apply">🔍 Terapkan Filter</button>' +
      '<div class="sr-sum"><div class="retur"><b id="sr-s-retur">0</b><small>Retur</small></div><div class="reject"><b id="sr-s-reject">0</b><small>Reject</small></div><div class="mutasi"><b id="sr-s-mutasi">0</b><small>Mutasi</small></div></div>'
    );
    riwayatView.appendChild(filt);
    var listEl = document.createElement('div');
    riwayatView.appendChild(listEl);
    var weekAgo = new Date(Date.now() - 6 * 864e5);
    filt.querySelector('#sr-from').value = weekAgo.getFullYear() + '-' + String(weekAgo.getMonth() + 1).padStart(2, '0') + '-' + String(weekAgo.getDate()).padStart(2, '0');
    filt.querySelector('#sr-to').value = todayLocal();
    filt.querySelector('#sr-apply').addEventListener('click', loadRiwayat);

    function itemStatus(it, r) {
      if (r.status !== 'disetujui') return '';
      if (it.jenis === 'mutasi') {
        var m = { waiting_destination_confirmation: 'menunggu konfirmasi toko tujuan', completed: 'diterima toko tujuan', discrepancy: 'ada selisih, dicek Admin', cancelled: 'dibatalkan' };
        return m[it.itemStatus] || '';
      }
      if (it.validasiStatus === 'menunggu') return 'menunggu dicek pabrik';
      if (it.validasiStatus === 'tidak_sesuai') return 'tidak sesuai' + (it.validasiNote ? ': ' + it.validasiNote : '');
      if (it.jenis === 'reject') return it.tindakLanjut === 'ganti' ? 'sesuai → diganti + tagihan dipotong' : 'sesuai → tagihan dipotong';
      return 'sesuai (dicatat)';
    }

    function loadRiwayat() {
      listEl.innerHTML = '<div class="driver-empty">Memuat...</div>';
      var qs = '?from=' + encodeURIComponent(filt.querySelector('#sr-from').value) + '&to=' + encodeURIComponent(filt.querySelector('#sr-to').value) +
        '&q=' + encodeURIComponent(filt.querySelector('#sr-q').value);
      K.getJson(K.apiPath('/reports' + qs)).then(function (res) {
        ['retur', 'reject', 'mutasi'].forEach(function (j) { filt.querySelector('#sr-s-' + j).textContent = K.fmtNum(res.ringkasan[j] || 0); });
        listEl.innerHTML = '';
        if (!res.list.length) { listEl.appendChild(K.emptyState('Belum ada data untuk filter ini.')); return; }
        res.list.forEach(function (r) {
          var waiting = r.status !== 'disetujui';
          var c = K.card(
            '<div class="driver-card-head"><div><div class="driver-card-title">' + esc(fmtTgl(r.tanggal)) + '</div>' +
            '<div class="driver-card-sub">' + esc(r.docNo) + '</div></div>' +
            '<span class="driver-badge ' + (waiting ? 'warning' : 'success') + '">' + (waiting ? '⏳ Menunggu Atasan' : '✓ Disetujui') + '</span></div>' +
            r.items.map(function (it) {
              var ket = [];
              if (it.alasan) ket.push('Alasan: ' + it.alasan);
              if (it.expDate) ket.push('Exp: ' + fmtTgl(it.expDate));
              if (it.destinationStoreName) ket.push('Ke: ' + it.destinationStoreName);
              var st = itemStatus(it, r);
              if (st) ket.push(st);
              return '<div class="sr-trx-row"><div><span class="sr-jb ' + it.jenis + '">' + JENIS[it.jenis].label + '</span>' + esc(it.productName) +
                (ket.length ? '<small>' + esc(ket.join(' · ')) + '</small>' : '') + '</div><b style="white-space:nowrap">' + K.fmtNum(it.qty) + ' pcs</b></div>';
            }).join('') +
            '<div class="driver-row"><span>Total nilai</span><b>' + rp(r.total) + '</b></div>' +
            '<div class="sr-btn-row">' +
            (waiting ? '<button type="button" class="driver-btn small" data-edit="1">✏️ Edit</button>' : '') +
            '<a class="driver-btn small primary" target="_blank" href="' + printUrl(r.reportId, 'a4') + '">🖨️ Cetak Ulang</a>' +
            '<a class="driver-btn small" target="_blank" href="' + printUrl(r.reportId, 'thermal') + '">🖨️ Thermal</a></div>'
          );
          var eb = c.querySelector('[data-edit]');
          if (eb) eb.addEventListener('click', function () { startEdit(r.reportId); });
          listEl.appendChild(c);
        });
      }).catch(function (err) { listEl.innerHTML = ''; listEl.appendChild(K.emptyState(err.message)); });
    }

    function startEdit(reportId) {
      K.getJson(K.apiPath('/reports/' + reportId)).then(function (r) {
        if (r.status !== 'menunggu_atasan') { Amor.toast('Transaksi sudah disetujui Atasan, tidak bisa diedit lagi.', 'warning'); loadRiwayat(); return; }
        resetForm();
        state.editing = r;
        form.querySelector('#sr-edit').style.display = 'flex';
        form.querySelector('#sr-edit-no').textContent = r.docNo;
        dateEl.value = r.tanggal;
        var first = null;
        r.items.forEach(function (it) { addBlock(it.jenis, it); first = first || it.jenis; });
        if (r.destination && destEl) destEl.value = String(r.destination.storeId);
        setTab(first || 'retur');
        showView('input');
      }).catch(function (err) { Amor.toast('Tidak bisa membuka transaksi: ' + err.message, 'danger'); });
    }

    function showView(v) {
      state.view = v;
      nav.querySelectorAll('button').forEach(function (b) { b.classList.toggle('active', b.dataset.view === (v === 'result' ? 'input' : v)); });
      inputView.style.display = v === 'input' ? 'block' : 'none';
      resultView.style.display = v === 'result' ? 'block' : 'none';
      riwayatView.style.display = v === 'riwayat' ? 'block' : 'none';
      if (v === 'riwayat') loadRiwayat();
      window.scrollTo(0, 0);
    }

    renumber();
    setTab('retur');
    showView(state.view);
  }

  window.StoreReport = { render: render };
})();
