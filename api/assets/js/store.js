/*
 * Permanent Bakery Portal (migration 0017) — client logic for all five
 * menus (Konfirmasi Penerimaan/Reject, Pesanan Khusus, Retur, Mutasi
 * Produk, Riwayat). Loaded AFTER app.js (api/_store/bootstrap.php), so
 * Amor.toast/confirmModal/imageLightbox/friendlyError are reused rather
 * than reimplemented — this file is standalone otherwise (no session, no
 * CSRF token to send; the permanent ?token= IS the access control, same
 * discipline as receipt.js for /api/receive/). Every write goes through
 * the real JSON API (/api/store/{token}/...) — this file never invents a
 * total or a status, it only renders what the API returns.
 */
(function () {
  'use strict';

  var TOKEN = window.STORE_TOKEN || '';
  var TAB = window.STORE_TAB || 'konfirmasi';
  var LOOKUP_PRODUCTS = window.STORE_LOOKUP_PRODUCTS || [];
  var LOOKUP_STORES = window.STORE_LOOKUP_STORES || [];
  var MAX_EVIDENCE_FILES = 3;
  var MAX_EVIDENCE_SIZE = 5 * 1024 * 1024;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmtNum(n) {
    n = Number(n) || 0;
    return (Math.round(n * 100) / 100).toString().replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
  }
  function genKey() {
    return 'store-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
  }
  function apiPath(suffix) {
    return '/api/store/' + encodeURIComponent(TOKEN) + suffix;
  }
  function tabUrl(tab, extra) {
    var qs = 'token=' + encodeURIComponent(TOKEN) + '&tab=' + tab;
    if (extra) qs += '&' + extra;
    return 'index.php?' + qs;
  }

  function getJson(path) {
    return fetch(path, { method: 'GET' }).then(function (res) {
      return res.json().then(function (json) {
        if (!res.ok || (json && json.ok === false)) {
          var code = json && json.code ? json.code : ('HTTP_' + res.status);
          throw new Error((window.Amor && Amor.friendlyError(code, json && json.message)) || (json && json.message) || 'Gagal memuat data');
        }
        return json.data;
      });
    });
  }

  /** POST with a FormData body (works whether or not it carries files) + a fresh Idempotency-Key, same contract every /api/store/* mutating route expects. */
  function postForm(path, form) {
    return fetch(path, { method: 'POST', headers: { 'Idempotency-Key': genKey() }, body: form }).then(function (res) {
      return res.json().then(function (json) {
        if (!res.ok || (json && json.ok === false)) {
          var code = json && json.code ? json.code : ('HTTP_' + res.status);
          var err = new Error((window.Amor && Amor.friendlyError(code, json && json.message)) || (json && json.message) || 'Gagal menyimpan');
          err.code = code;
          throw err;
        }
        return json.data;
      });
    });
  }

  function card(innerHtml, extraClass) {
    var el = document.createElement('div');
    el.className = 'driver-card' + (extraClass ? ' ' + extraClass : '');
    el.innerHTML = innerHtml;
    return el;
  }
  function emptyState(text) {
    return card('<div class="driver-empty">' + esc(text) + '</div>');
  }
  function backLink(tab) {
    var a = document.createElement('a');
    a.className = 'driver-back-link';
    a.href = tabUrl(tab);
    a.textContent = '← Kembali ke daftar';
    return a;
  }
  function sectionTitle(text) {
    var el = document.createElement('div');
    el.className = 'store-section-title';
    el.textContent = text;
    return el;
  }

  // -----------------------------------------------------------------
  // Shared evidence (photo) upload widget — one reusable mechanism for
  // Konfirmasi Penerimaan (Reject)/Retur/Mutasi, dark-navy port of
  // receipt.js's own proven thumbnail-grid pattern. `required` toggles
  // the hint styling only; the SERVER is always the real enforcement —
  // this is a UX convenience, same "frontend validation is not enough"
  // discipline as every other form in this app.
  // -----------------------------------------------------------------
  function buildEvidenceField(opts) {
    opts = opts || {};
    var selectedFiles = [];
    var wrap = document.createElement('div');
    wrap.className = 'store-field';
    wrap.innerHTML =
      '<label>' + esc(opts.label || 'Foto Bukti') + '</label>' +
      '<div class="store-evidence-hint' + (opts.required ? ' required' : '') + '">' + esc(opts.hint || '') + '</div>' +
      '<input type="file" accept="image/*" multiple class="store-evidence-input">' +
      '<div class="store-evidence-error" style="display:none;"></div>' +
      '<div class="store-evidence-previews"></div>';
    var input = wrap.querySelector('.store-evidence-input');
    var errorEl = wrap.querySelector('.store-evidence-error');
    var previewsEl = wrap.querySelector('.store-evidence-previews');
    var onChangeCb = function () {};

    function showError(msg) {
      errorEl.textContent = msg;
      errorEl.style.display = msg ? 'block' : 'none';
    }
    function renderPreviews() {
      previewsEl.innerHTML = '';
      selectedFiles.forEach(function (file, i) {
        var thumb = document.createElement('div');
        thumb.className = 'store-evidence-thumb';
        var img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.title = 'Ketuk untuk memperbesar';
        img.addEventListener('click', function () { if (window.Amor) Amor.imageLightbox(img.src); });
        thumb.appendChild(img);
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'store-evidence-remove';
        removeBtn.setAttribute('aria-label', 'Hapus foto');
        removeBtn.textContent = '×';
        removeBtn.addEventListener('click', function () { selectedFiles.splice(i, 1); renderPreviews(); });
        thumb.appendChild(removeBtn);
        previewsEl.appendChild(thumb);
      });
      onChangeCb();
    }
    input.addEventListener('change', function () {
      showError('');
      Array.prototype.slice.call(input.files || []).forEach(function (file) {
        if (!/^image\//.test(file.type)) { showError('Hanya file gambar (JPEG/PNG/WEBP) yang diperbolehkan.'); return; }
        if (file.size > MAX_EVIDENCE_SIZE) { showError('Ukuran foto maksimal 5 MB.'); return; }
        if (selectedFiles.length >= MAX_EVIDENCE_FILES) { showError('Maksimal ' + MAX_EVIDENCE_FILES + ' foto.'); return; }
        selectedFiles.push(file);
      });
      input.value = '';
      renderPreviews();
    });

    return {
      element: wrap,
      getFiles: function () { return selectedFiles; },
      onChange: function (cb) { onChangeCb = cb; },
      showError: showError,
    };
  }

  function appendToForm(form, fieldName, files) {
    files.forEach(function (f) { form.append(fieldName, f, f.name); });
  }

  // ===================================================================
  // KONFIRMASI PENERIMAAN (Reject lives here — never a standalone menu)
  // ===================================================================
  var RECEIPT_STATUS_MAP = {
    belum_dikonfirmasi: ['warning', 'Belum Dikonfirmasi'],
    confirmed_ok: ['success', 'Diterima Sesuai'],
    confirmed_discrepancy: ['danger', 'Ada Selisih'],
    verified: ['info', 'Diverifikasi Admin'],
  };
  function receiptBadge(status) {
    var m = RECEIPT_STATUS_MAP[status] || ['', status];
    return '<span class="driver-badge ' + m[0] + '">' + esc(m[1]) + '</span>';
  }

  function renderKonfirmasiList(root) {
    getJson(apiPath('/receipts')).then(function (rows) {
      root.innerHTML = '';
      if (!rows.length) { root.appendChild(emptyState('Belum ada pengiriman untuk toko ini.')); return; }
      rows.forEach(function (r) {
        var el = card(
          '<div class="driver-card-head">' +
          '<div><div class="driver-card-title">' + esc(r.docNo || ('SHP-' + r.shipmentId)) + '</div>' +
          '<div class="driver-card-sub">' + esc(r.tanggal || '-') + ' &middot; ' + esc(r.driverName || '-') + '</div></div>' +
          receiptBadge(r.status) +
          '</div>' +
          '<div class="driver-row"><span>Sumber</span><b>' + esc((r.source && r.source.label) || '-') + '</b></div>' +
          '<div class="driver-row"><span>Jumlah Dikirim</span><b>' + fmtNum(r.totalShipped) + ' pcs</b></div>' +
          (r.totalReject > 0 ? '<div class="driver-row"><span>Reject</span><b>' + fmtNum(r.totalReject) + ' pcs</b></div>' : '')
        );
        el.style.cursor = 'pointer';
        el.addEventListener('click', function () { window.location.href = tabUrl('konfirmasi', 'shipment=' + r.shipmentId); });
        root.appendChild(el);
      });
    }).catch(function (err) { root.innerHTML = ''; root.appendChild(emptyState(err.message)); });
  }

  function renderKonfirmasiDetail(root, shipmentId) {
    root.appendChild(backLink('konfirmasi'));
    getJson(apiPath('/receipts/' + shipmentId)).then(function (sh) {
      var head = card(
        '<div class="driver-card-title">' + esc(sh.docNo || ('SHP-' + shipmentId)) + ' &middot; ' + esc(sh.shipmentGroup || '') + '</div>' +
        '<div class="driver-card-sub">Driver: ' + esc(sh.driverName || '-') + '</div>' +
        '<div class="driver-card-sub">Berangkat: ' + esc(sh.departedAt || '-') + '</div>' +
        '<div style="margin-top:6px;">' + receiptBadge(sh.receiptStatus) + '</div>'
      );
      root.appendChild(head);

      var editable = sh.receiptStatus === 'pending' || sh.receiptStatus === 'belum_dikonfirmasi';
      var formCard = card('');
      formCard.innerHTML = '';
      var table = document.createElement('table');
      table.className = 'driver-table-plain';
      var rowsHtml = sh.items.map(function (it) {
        if (editable) {
          return '<div class="store-item-row" data-shipment-item-id="' + it.shipmentItemId + '" data-shipped="' + it.shippedQty + '">' +
            '<div class="driver-row"><b>' + esc(it.productName) + '</b><span>Dikirim: ' + fmtNum(it.shippedQty) + '</span></div>' +
            '<div class="store-field"><label>Diterima Baik</label><input type="number" min="0" step="0.01" class="rc-field-good" value="' + it.shippedQty + '"></div>' +
            '<div class="store-field"><label>Reject</label><input type="number" min="0" step="0.01" class="rc-field-reject" value="0"></div>' +
            '<div class="store-field"><label>Kurang</label><input type="number" min="0" step="0.01" class="rc-field-shortage" value="0"></div>' +
            '</div>';
        }
        return '<div class="driver-row"><b>' + esc(it.productName) + '</b></div>' +
          '<div class="driver-row"><span>Dikirim</span><span>' + fmtNum(it.shippedQty) + '</span></div>' +
          '<div class="driver-row"><span>Baik / Reject / Kurang</span><span>' + fmtNum(it.receivedGoodQty) + ' / ' + fmtNum(it.rejectQty) + ' / ' + fmtNum(it.shortageQty) + '</span></div>';
      }).join('<hr style="border-color:var(--border);opacity:.4;margin:10px 0;">');
      formCard.innerHTML = '<div class="store-section-title">Produk</div>' + rowsHtml;
      root.appendChild(formCard);

      if (!editable) {
        if (sh.receiverName) {
          root.appendChild(card('<div class="driver-row"><span>Diterima oleh</span><b>' + esc(sh.receiverName) + '</b></div>'));
        }
        return;
      }

      var mathErr = document.createElement('div');
      mathErr.className = 'store-field-error';
      mathErr.style.display = 'none';
      mathErr.textContent = 'Diterima Baik + Reject + Kurang harus sama dengan jumlah dikirim pada setiap produk.';
      formCard.appendChild(mathErr);

      var nameField = document.createElement('div');
      nameField.className = 'store-field';
      nameField.innerHTML = '<label>Nama Penerima</label><input type="text" class="rc-receiver-name" placeholder="Nama Anda">';
      formCard.appendChild(nameField);
      var noteField = document.createElement('div');
      noteField.className = 'store-field';
      noteField.innerHTML = '<label>Catatan (opsional)</label><textarea class="rc-note" rows="2" placeholder="Contoh: ada 2 pcs rusak pada kemasan"></textarea>';
      formCard.appendChild(noteField);

      var evidence = buildEvidenceField({ label: 'Bukti Foto', hint: 'Wajib jika ada barang Reject atau Kurang.', required: true });
      formCard.appendChild(evidence.element);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'driver-btn primary';
      btn.textContent = 'Simpan Konfirmasi';
      formCard.appendChild(btn);

      function hasDiscrepancy() {
        var any = false;
        formCard.querySelectorAll('.store-item-row').forEach(function (row) {
          var reject = parseFloat(row.querySelector('.rc-field-reject').value) || 0;
          var shortage = parseFloat(row.querySelector('.rc-field-shortage').value) || 0;
          if (reject > 0.0001 || shortage > 0.0001) any = true;
        });
        return any;
      }
      function validate() {
        var ok = true;
        formCard.querySelectorAll('.store-item-row').forEach(function (row) {
          var shipped = parseFloat(row.dataset.shipped);
          var good = parseFloat(row.querySelector('.rc-field-good').value) || 0;
          var reject = parseFloat(row.querySelector('.rc-field-reject').value) || 0;
          var shortage = parseFloat(row.querySelector('.rc-field-shortage').value) || 0;
          if (Math.abs(good + reject + shortage - shipped) > 0.001) ok = false;
        });
        mathErr.style.display = ok ? 'none' : 'block';
        var evidenceOk = !hasDiscrepancy() || evidence.getFiles().length > 0;
        btn.disabled = !ok || !evidenceOk;
        return ok && evidenceOk;
      }
      formCard.addEventListener('input', validate);
      evidence.onChange(validate);
      validate();

      btn.addEventListener('click', function () {
        if (!validate()) {
          if (hasDiscrepancy() && evidence.getFiles().length === 0) evidence.showError('Bukti foto wajib diunggah untuk barang reject/rusak atau kurang.');
          return;
        }
        var items = [];
        formCard.querySelectorAll('.store-item-row').forEach(function (row) {
          items.push({
            shipmentItemId: parseInt(row.dataset.shipmentItemId, 10),
            receivedGood: parseFloat(row.querySelector('.rc-field-good').value) || 0,
            reject: parseFloat(row.querySelector('.rc-field-reject').value) || 0,
            shortage: parseFloat(row.querySelector('.rc-field-shortage').value) || 0,
          });
        });
        btn.disabled = true;
        btn.textContent = 'Menyimpan...';
        var form = new FormData();
        form.append('receiverName', formCard.querySelector('.rc-receiver-name').value || '');
        form.append('note', formCard.querySelector('.rc-note').value || '');
        form.append('items', JSON.stringify(items));
        appendToForm(form, 'evidence[]', evidence.getFiles());
        postForm(apiPath('/receipts/' + shipmentId + '/confirm'), form).then(function () {
          if (window.Amor) Amor.toast('Konfirmasi penerimaan tersimpan.', 'success');
          window.location.href = tabUrl('konfirmasi', 'shipment=' + shipmentId);
        }).catch(function (err) {
          if (window.Amor) Amor.toast(err.message, 'danger'); else alert(err.message);
          btn.disabled = false;
          btn.textContent = 'Simpan Konfirmasi';
        });
      });
    }).catch(function (err) {
      root.appendChild(emptyState(err.message));
    });
  }

  // ===================================================================
  // PESANAN KHUSUS / CUSTOM
  // ===================================================================
  var SPECIAL_STATUS_MAP = {
    draft: ['warning', 'Menunggu Verifikasi Admin'],
    confirmed: ['info', 'Disetujui Admin'],
    sent_to_production: ['info', 'Diproses Produksi'],
    in_production: ['info', 'Diproses Produksi'],
    ready: ['success', 'Siap Kirim'],
    completed: ['success', 'Selesai'],
    cancelled: ['danger', 'Ditolak'],
  };
  function specialBadge(status) {
    var m = SPECIAL_STATUS_MAP[status] || ['', status];
    return '<span class="driver-badge ' + m[0] + '">' + esc(m[1]) + '</span>';
  }

  function buildSpecialOrderForm(onSuccess) {
    var wrap = document.createElement('div');
    wrap.className = 'driver-card';
    wrap.innerHTML =
      '<div class="driver-card-title" style="margin-bottom:10px;">Ajukan Pesanan Khusus</div>' +
      '<div class="store-field"><label>Nama Pelanggan (opsional)</label><input type="text" class="so-customer-name"></div>' +
      '<div class="store-field"><label>Kontak Pelanggan (opsional)</label><input type="text" class="so-customer-contact"></div>' +
      '<div class="store-field"><label>Tanggal Dibutuhkan</label><input type="date" class="so-required-date"></div>' +
      '<div class="store-field"><label>Jam Dibutuhkan (opsional)</label><input type="time" class="so-required-time"></div>' +
      '<div class="store-field"><label>Catatan / Permintaan Khusus (opsional)</label><textarea class="so-general-note" rows="2"></textarea></div>';
    var itemsWrap = document.createElement('div');
    wrap.appendChild(sectionTitle('Produk Dipesan'));
    wrap.appendChild(itemsWrap);

    var productOptions = '<option value="">Pilih produk...</option>' + LOOKUP_PRODUCTS.map(function (p) {
      return '<option value="' + p.productId + '">' + esc(p.name) + '</option>';
    }).join('');

    function addItemRow() {
      var row = document.createElement('div');
      row.className = 'store-item-row so-item-row';
      row.innerHTML =
        '<button type="button" class="store-item-remove">×</button>' +
        '<div class="store-field"><label>Produk</label><select class="so-item-product">' + productOptions + '</select></div>' +
        '<div class="store-field"><label>Qty</label><input type="number" min="0.01" step="0.01" class="so-item-qty"></div>' +
        '<div class="store-field"><label>Extra Packaging (opsional, Rp)</label><input type="number" min="0" step="1" class="so-item-packaging" value="0"></div>' +
        '<div class="store-field"><label>Catatan Produk (opsional)</label><input type="text" class="so-item-note"></div>';
      row.querySelector('.store-item-remove').addEventListener('click', function () {
        if (itemsWrap.querySelectorAll('.so-item-row').length > 1) row.remove();
      });
      itemsWrap.appendChild(row);
    }
    addItemRow();

    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'store-add-item-btn';
    addBtn.textContent = '+ Tambah Produk';
    addBtn.addEventListener('click', addItemRow);
    wrap.appendChild(addBtn);

    var attachments = buildEvidenceField({ label: 'Foto Referensi (opsional)', hint: 'Boleh lebih dari satu, misalnya contoh desain/karakter yang diinginkan.' });
    wrap.appendChild(attachments.element);

    var errBox = document.createElement('div');
    errBox.className = 'store-field-error';
    errBox.style.display = 'none';
    wrap.appendChild(errBox);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'driver-btn primary';
    btn.textContent = 'Kirim Pesanan';
    wrap.appendChild(btn);

    btn.addEventListener('click', function () {
      var items = [];
      var rowsEl = itemsWrap.querySelectorAll('.so-item-row');
      var valid = true;
      rowsEl.forEach(function (row) {
        var productId = parseInt(row.querySelector('.so-item-product').value, 10);
        var qty = parseFloat(row.querySelector('.so-item-qty').value);
        if (!productId || !qty || qty <= 0) { valid = false; return; }
        items.push({
          itemType: 'existing_product',
          productId: productId,
          qty: qty,
          extraPackaging: parseFloat(row.querySelector('.so-item-packaging').value) || 0,
          specialNote: row.querySelector('.so-item-note').value || null,
        });
      });
      var requiredDate = wrap.querySelector('.so-required-date').value;
      if (!valid || items.length === 0 || !requiredDate) {
        errBox.textContent = 'Pilih produk + qty untuk setiap baris, dan isi Tanggal Dibutuhkan.';
        errBox.style.display = 'block';
        return;
      }
      errBox.style.display = 'none';
      btn.disabled = true;
      btn.textContent = 'Mengirim...';
      var payload = {
        orderDate: new Date().toISOString().slice(0, 10),
        requiredDate: requiredDate,
        requiredTime: wrap.querySelector('.so-required-time').value || null,
        customerName: wrap.querySelector('.so-customer-name').value || null,
        customerContact: wrap.querySelector('.so-customer-contact').value || null,
        generalNote: wrap.querySelector('.so-general-note').value || null,
        items: items,
      };
      var form = new FormData();
      form.append('order', JSON.stringify(payload));
      appendToForm(form, 'attachments[]', attachments.getFiles());
      postForm(apiPath('/special-orders'), form).then(function () {
        if (window.Amor) Amor.toast('Pesanan khusus berhasil dikirim.', 'success');
        onSuccess();
      }).catch(function (err) {
        errBox.textContent = err.message;
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Kirim Pesanan';
      });
    });

    return wrap;
  }

  function renderKhususList(root) {
    var showForm = false;
    function draw() {
      root.innerHTML = '';
      var toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'driver-btn primary';
      toggleBtn.style.marginBottom = '12px';
      toggleBtn.textContent = showForm ? 'Tutup Form' : '+ Ajukan Pesanan Khusus Baru';
      toggleBtn.addEventListener('click', function () { showForm = !showForm; draw(); });
      root.appendChild(toggleBtn);

      if (showForm) {
        root.appendChild(buildSpecialOrderForm(function () { showForm = false; draw(); loadList(); }));
      }
      root.appendChild(sectionTitle('Daftar Pesanan'));
      root.appendChild(listHolder);
    }
    var listHolder = document.createElement('div');
    function loadList() {
      listHolder.innerHTML = '<div class="driver-empty">Memuat...</div>';
      getJson(apiPath('/special-orders')).then(function (rows) {
        listHolder.innerHTML = '';
        if (!rows.length) { listHolder.appendChild(emptyState('Belum ada pesanan khusus.')); return; }
        rows.forEach(function (r) {
          var el = card(
            '<div class="driver-card-head">' +
            '<div><div class="driver-card-title">' + esc(r.orderNo) + '</div>' +
            '<div class="driver-card-sub">Dibutuhkan: ' + esc(r.requiredDate || '-') + '</div></div>' +
            specialBadge(r.status) + '</div>' +
            (r.customerName ? '<div class="driver-row"><span>Pelanggan</span><b>' + esc(r.customerName) + '</b></div>' : '')
          );
          el.style.cursor = 'pointer';
          el.addEventListener('click', function () { window.location.href = tabUrl('khusus', 'order=' + r.orderId); });
          listHolder.appendChild(el);
        });
      }).catch(function (err) { listHolder.innerHTML = ''; listHolder.appendChild(emptyState(err.message)); });
    }
    draw();
    loadList();
  }

  function renderKhususDetail(root, orderId) {
    root.appendChild(backLink('khusus'));
    getJson(apiPath('/special-orders/' + orderId)).then(function (o) {
      var html = '<div class="driver-card-head"><div><div class="driver-card-title">' + esc(o.orderNo) + '</div>' +
        '<div class="driver-card-sub">Dibutuhkan: ' + esc(o.requiredDate || '-') + ' ' + esc(o.requiredTime || '') + '</div></div>' +
        specialBadge(o.status) + '</div>';
      if (o.customerName) html += '<div class="driver-row"><span>Pelanggan</span><b>' + esc(o.customerName) + '</b></div>';
      if (o.customerContact) html += '<div class="driver-row"><span>Kontak</span><b>' + esc(o.customerContact) + '</b></div>';
      if (o.generalNote) html += '<div class="driver-row"><span>Catatan</span><b>' + esc(o.generalNote) + '</b></div>';
      if (o.status === 'cancelled' && o.cancelReason) html += '<div class="driver-row"><span>Alasan Ditolak</span><b>' + esc(o.cancelReason) + '</b></div>';
      root.appendChild(card(html));

      root.appendChild(sectionTitle('Produk'));
      o.items.forEach(function (it) {
        root.appendChild(card(
          '<div class="driver-row"><b>' + esc(it.itemName) + '</b><span>' + fmtNum(it.qty) + ' pcs</span></div>' +
          (it.specialNote ? '<div class="driver-row"><span>Catatan</span><span>' + esc(it.specialNote) + '</span></div>' : '')
        ));
      });

      if (o.attachments && o.attachments.length) {
        root.appendChild(sectionTitle('Foto Referensi'));
        var grid = document.createElement('div');
        grid.className = 'store-evidence-previews';
        o.attachments.forEach(function (a) {
          grid.innerHTML += '<div class="store-evidence-thumb"><img src="' + apiPath('/special-orders/' + orderId + '/attachments/' + a.attachmentId) + '" alt="Lampiran"></div>';
        });
        root.appendChild(grid);
      }
    }).catch(function (err) { root.appendChild(emptyState(err.message)); });
  }

  // ===================================================================
  // RETUR
  // ===================================================================
  var RETUR_STATUS_MAP = {
    waiting_admin_verification: ['warning', 'Menunggu Verifikasi Admin'],
    verified: ['success', 'Terverifikasi'],
    rejected: ['danger', 'Ditolak'],
  };
  function returBadge(status) {
    var m = RETUR_STATUS_MAP[status] || ['', status];
    return '<span class="driver-badge ' + m[0] + '">' + esc(m[1]) + '</span>';
  }

  function buildReturForm(onSuccess) {
    var wrap = document.createElement('div');
    wrap.className = 'driver-card';
    var productOptions = '<option value="">Pilih produk...</option>' + LOOKUP_PRODUCTS.map(function (p) {
      return '<option value="' + p.productId + '">' + esc(p.name) + '</option>';
    }).join('');
    wrap.innerHTML =
      '<div class="driver-card-title" style="margin-bottom:10px;">Ajukan Retur</div>' +
      '<div class="store-field-hint" style="margin-bottom:10px;">Retur = barang sudah diterima BAIK, belakangan tidak terjual. Bukan barang reject/rusak saat kirim (itu ada di menu Konfirmasi Penerimaan).</div>' +
      '<div class="store-field"><label>Produk</label><select class="rt-product">' + productOptions + '</select></div>' +
      '<div class="store-field"><label>Jumlah</label><input type="number" min="0.01" step="0.01" class="rt-qty"></div>' +
      '<div class="store-field"><label>Alasan</label><input type="text" class="rt-reason" placeholder="Contoh: tidak terjual, lewat tanggal"></div>' +
      '<div class="store-field"><label>Catatan (opsional)</label><textarea class="rt-notes" rows="2"></textarea></div>';
    var evidence = buildEvidenceField({ label: 'Foto Bukti', hint: 'Wajib diunggah, minimal 1 foto.', required: true });
    wrap.appendChild(evidence.element);

    var errBox = document.createElement('div');
    errBox.className = 'store-field-error';
    errBox.style.display = 'none';
    wrap.appendChild(errBox);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'driver-btn primary';
    btn.textContent = 'Kirim Retur';
    wrap.appendChild(btn);

    btn.addEventListener('click', function () {
      var productId = parseInt(wrap.querySelector('.rt-product').value, 10);
      var qty = parseFloat(wrap.querySelector('.rt-qty').value);
      var reason = wrap.querySelector('.rt-reason').value.trim();
      if (!productId || !qty || qty <= 0 || !reason) {
        errBox.textContent = 'Pilih produk, isi jumlah, dan isi alasan.';
        errBox.style.display = 'block';
        return;
      }
      if (evidence.getFiles().length === 0) {
        errBox.textContent = 'Foto bukti wajib diunggah untuk pengajuan Retur.';
        errBox.style.display = 'block';
        return;
      }
      errBox.style.display = 'none';
      btn.disabled = true;
      btn.textContent = 'Mengirim...';
      var payload = { productId: productId, qty: qty, reason: reason, notes: wrap.querySelector('.rt-notes').value || null };
      var form = new FormData();
      form.append('retur', JSON.stringify(payload));
      appendToForm(form, 'evidence[]', evidence.getFiles());
      postForm(apiPath('/retur'), form).then(function () {
        if (window.Amor) Amor.toast('Pengajuan Retur terkirim.', 'success');
        onSuccess();
      }).catch(function (err) {
        errBox.textContent = err.message;
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Kirim Retur';
      });
    });

    return wrap;
  }

  function renderReturList(root) {
    var showForm = false;
    var listHolder = document.createElement('div');
    function draw() {
      root.innerHTML = '';
      var toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'driver-btn primary';
      toggleBtn.style.marginBottom = '12px';
      toggleBtn.textContent = showForm ? 'Tutup Form' : '+ Ajukan Retur Baru';
      toggleBtn.addEventListener('click', function () { showForm = !showForm; draw(); });
      root.appendChild(toggleBtn);
      if (showForm) root.appendChild(buildReturForm(function () { showForm = false; draw(); loadList(); }));
      root.appendChild(sectionTitle('Daftar Retur'));
      root.appendChild(listHolder);
    }
    function loadList() {
      listHolder.innerHTML = '<div class="driver-empty">Memuat...</div>';
      getJson(apiPath('/retur')).then(function (rows) {
        listHolder.innerHTML = '';
        if (!rows.length) { listHolder.appendChild(emptyState('Belum ada pengajuan Retur.')); return; }
        rows.forEach(function (r) {
          listHolder.appendChild(card(
            '<div class="driver-card-head">' +
            '<div><div class="driver-card-title">' + esc(r.docNo) + '</div>' +
            '<div class="driver-card-sub">' + esc(r.productName) + ' &middot; ' + fmtNum(r.qty) + ' pcs</div></div>' +
            returBadge(r.status) + '</div>'
          ));
        });
      }).catch(function (err) { listHolder.innerHTML = ''; listHolder.appendChild(emptyState(err.message)); });
    }
    draw();
    loadList();
  }

  // ===================================================================
  // MUTASI PRODUK
  // ===================================================================
  var MUTASI_STATUS_MAP = {
    waiting_destination_confirmation: ['warning', 'Menunggu Konfirmasi Tujuan'],
    completed: ['success', 'Selesai'],
    discrepancy: ['danger', 'Selisih — Menunggu Admin'],
    cancelled: ['danger', 'Dibatalkan'],
  };
  function mutasiBadge(status) {
    var m = MUTASI_STATUS_MAP[status] || ['', status];
    return '<span class="driver-badge ' + m[0] + '">' + esc(m[1]) + '</span>';
  }

  function buildMutasiForm(onSuccess) {
    var wrap = document.createElement('div');
    wrap.className = 'driver-card';
    var productOptions = '<option value="">Pilih produk...</option>' + LOOKUP_PRODUCTS.map(function (p) {
      return '<option value="' + p.productId + '">' + esc(p.name) + '</option>';
    }).join('');
    var storeOptions = '<option value="">Pilih toko tujuan...</option>' + LOOKUP_STORES.map(function (s) {
      return '<option value="' + s.storeId + '">' + esc(s.name) + '</option>';
    }).join('');
    wrap.innerHTML =
      '<div class="driver-card-title" style="margin-bottom:10px;">Ajukan Mutasi</div>' +
      '<div class="store-field"><label>Toko Tujuan</label><select class="mt-dest-store">' + storeOptions + '</select></div>' +
      '<div class="store-field"><label>Produk</label><select class="mt-product">' + productOptions + '</select></div>' +
      '<div class="store-field"><label>Jumlah</label><input type="number" min="0.01" step="0.01" class="mt-qty"></div>' +
      '<div class="store-field"><label>Catatan (opsional)</label><textarea class="mt-notes" rows="2"></textarea></div>';
    var evidence = buildEvidenceField({ label: 'Foto Bukti', hint: 'Wajib diunggah, minimal 1 foto.', required: true });
    wrap.appendChild(evidence.element);

    var errBox = document.createElement('div');
    errBox.className = 'store-field-error';
    errBox.style.display = 'none';
    wrap.appendChild(errBox);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'driver-btn primary';
    btn.textContent = 'Kirim Mutasi';
    wrap.appendChild(btn);

    btn.addEventListener('click', function () {
      var destinationStoreId = parseInt(wrap.querySelector('.mt-dest-store').value, 10);
      var productId = parseInt(wrap.querySelector('.mt-product').value, 10);
      var qty = parseFloat(wrap.querySelector('.mt-qty').value);
      if (!destinationStoreId || !productId || !qty || qty <= 0) {
        errBox.textContent = 'Pilih toko tujuan, produk, dan isi jumlah.';
        errBox.style.display = 'block';
        return;
      }
      if (evidence.getFiles().length === 0) {
        errBox.textContent = 'Foto bukti wajib diunggah saat mengajukan Mutasi.';
        errBox.style.display = 'block';
        return;
      }
      errBox.style.display = 'none';
      btn.disabled = true;
      btn.textContent = 'Mengirim...';
      var payload = { destinationStoreId: destinationStoreId, productId: productId, qty: qty, notes: wrap.querySelector('.mt-notes').value || null };
      var form = new FormData();
      form.append('mutasi', JSON.stringify(payload));
      appendToForm(form, 'evidence[]', evidence.getFiles());
      postForm(apiPath('/mutasi'), form).then(function () {
        if (window.Amor) Amor.toast('Pengajuan Mutasi terkirim.', 'success');
        onSuccess();
      }).catch(function (err) {
        errBox.textContent = err.message;
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Kirim Mutasi';
      });
    });

    return wrap;
  }

  function buildMutasiConfirmInline(row, onSuccess) {
    var wrap = document.createElement('div');
    wrap.className = 'store-item-row';
    wrap.innerHTML =
      '<div class="store-field"><label>Qty Diterima</label><input type="number" min="0" step="0.01" class="mc-qty" value="' + row.qtyRequested + '"></div>' +
      '<div class="store-field"><label>Catatan (opsional)</label><textarea class="mc-notes" rows="2"></textarea></div>';
    var evidence = buildEvidenceField({ label: 'Foto Bukti', hint: 'Wajib diunggah HANYA jika jumlah diterima berbeda dari jumlah dikirim.' });
    wrap.appendChild(evidence.element);
    var errBox = document.createElement('div');
    errBox.className = 'store-field-error';
    errBox.style.display = 'none';
    wrap.appendChild(errBox);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'driver-btn success';
    btn.textContent = 'Konfirmasi Penerimaan';
    wrap.appendChild(btn);

    btn.addEventListener('click', function () {
      var qtyReceived = parseFloat(wrap.querySelector('.mc-qty').value);
      if (isNaN(qtyReceived) || qtyReceived < 0) {
        errBox.textContent = 'Isi jumlah yang benar-benar diterima.';
        errBox.style.display = 'block';
        return;
      }
      errBox.style.display = 'none';
      btn.disabled = true;
      btn.textContent = 'Menyimpan...';
      var payload = { qtyReceived: qtyReceived, notes: wrap.querySelector('.mc-notes').value || null };
      var form = new FormData();
      form.append('confirmation', JSON.stringify(payload));
      appendToForm(form, 'evidence[]', evidence.getFiles());
      postForm(apiPath('/mutasi/' + row.mutasiId + '/confirm'), form).then(function () {
        if (window.Amor) Amor.toast('Konfirmasi Mutasi tersimpan.', 'success');
        onSuccess();
      }).catch(function (err) {
        if (window.Amor) Amor.toast(err.message, 'danger');
        errBox.textContent = err.message;
        errBox.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Konfirmasi Penerimaan';
      });
    });
    return wrap;
  }

  function renderMutasiTab(root) {
    var mode = 'incoming'; // incoming | create
    var listHolder = document.createElement('div');

    function draw() {
      root.innerHTML = '';
      var subtabs = document.createElement('div');
      subtabs.className = 'store-subtabs';
      subtabs.innerHTML =
        '<div class="store-subtab' + (mode === 'incoming' ? ' active' : '') + '" data-mode="incoming">Perlu Konfirmasi</div>' +
        '<div class="store-subtab' + (mode === 'create' ? ' active' : '') + '" data-mode="create">Ajukan Mutasi</div>';
      subtabs.querySelectorAll('.store-subtab').forEach(function (el) {
        el.addEventListener('click', function () { mode = el.dataset.mode; draw(); if (mode === 'incoming') loadIncoming(); });
      });
      root.appendChild(subtabs);

      if (mode === 'create') {
        root.appendChild(buildMutasiForm(function () { mode = 'incoming'; draw(); loadIncoming(); }));
        return;
      }
      root.appendChild(listHolder);
      loadIncoming();
    }

    function loadIncoming() {
      listHolder.innerHTML = '<div class="driver-empty">Memuat...</div>';
      getJson(apiPath('/mutasi/incoming')).then(function (rows) {
        listHolder.innerHTML = '';
        if (!rows.length) { listHolder.appendChild(emptyState('Tidak ada Mutasi masuk yang perlu dikonfirmasi.')); return; }
        rows.forEach(function (r) {
          var el = card(
            '<div class="driver-card-head">' +
            '<div><div class="driver-card-title">' + esc(r.docNo) + '</div>' +
            '<div class="driver-card-sub">Dari: ' + esc(r.sourceStoreName || '-') + '</div></div>' +
            mutasiBadge(r.status) + '</div>' +
            '<div class="driver-row"><span>Produk</span><b>' + esc(r.productName) + '</b></div>' +
            '<div class="driver-row"><span>Jumlah Dikirim</span><b>' + fmtNum(r.qtyRequested) + ' pcs</b></div>'
          );
          var confirmHolder = document.createElement('div');
          var toggle = document.createElement('button');
          toggle.type = 'button';
          toggle.className = 'driver-btn primary small';
          toggle.style.marginTop = '8px';
          toggle.textContent = 'Konfirmasi';
          toggle.addEventListener('click', function () {
            confirmHolder.innerHTML = '';
            confirmHolder.appendChild(buildMutasiConfirmInline(r, loadIncoming));
            toggle.style.display = 'none';
          });
          el.appendChild(toggle);
          el.appendChild(confirmHolder);
          listHolder.appendChild(el);
        });
      }).catch(function (err) { listHolder.innerHTML = ''; listHolder.appendChild(emptyState(err.message)); });
    }

    draw();
  }

  // ===================================================================
  // RIWAYAT — read-only aggregate across all 5 categories, this store only.
  // ===================================================================
  function renderRiwayat(root) {
    var sections = [
      { title: 'Pengiriman & Konfirmasi', path: '/receipts', render: function (r) {
        return '<div class="driver-card-head"><div><div class="driver-card-title">' + esc(r.docNo) + '</div>' +
          '<div class="driver-card-sub">' + esc(r.tanggal || '-') + '</div></div>' + receiptBadge(r.status) + '</div>';
      } },
      { title: 'Pesanan Khusus', path: '/special-orders', render: function (r) {
        return '<div class="driver-card-head"><div><div class="driver-card-title">' + esc(r.orderNo) + '</div>' +
          '<div class="driver-card-sub">Dibutuhkan: ' + esc(r.requiredDate || '-') + '</div></div>' + specialBadge(r.status) + '</div>';
      } },
      { title: 'Retur', path: '/retur', render: function (r) {
        return '<div class="driver-card-head"><div><div class="driver-card-title">' + esc(r.docNo) + '</div>' +
          '<div class="driver-card-sub">' + esc(r.productName) + ' &middot; ' + fmtNum(r.qty) + ' pcs</div></div>' + returBadge(r.status) + '</div>';
      } },
      { title: 'Mutasi Keluar', path: '/mutasi/outgoing', render: function (r) {
        return '<div class="driver-card-head"><div><div class="driver-card-title">' + esc(r.docNo) + '</div>' +
          '<div class="driver-card-sub">Ke: ' + esc(r.destinationStoreName || '-') + ' &middot; ' + esc(r.productName) + '</div></div>' + mutasiBadge(r.status) + '</div>';
      } },
      { title: 'Mutasi Masuk', path: '/mutasi/incoming-history', render: function (r) {
        return '<div class="driver-card-head"><div><div class="driver-card-title">' + esc(r.docNo) + '</div>' +
          '<div class="driver-card-sub">Dari: ' + esc(r.sourceStoreName || '-') + ' &middot; ' + esc(r.productName) + '</div></div>' + mutasiBadge(r.status) + '</div>';
      } },
    ];
    sections.forEach(function (s) {
      root.appendChild(sectionTitle(s.title));
      var holder = document.createElement('div');
      holder.innerHTML = '<div class="driver-empty">Memuat...</div>';
      root.appendChild(holder);
      getJson(apiPath(s.path)).then(function (rows) {
        holder.innerHTML = '';
        if (!rows.length) { holder.appendChild(emptyState('Belum ada data.')); return; }
        rows.slice(0, 20).forEach(function (r) { holder.appendChild(card(s.render(r))); });
      }).catch(function (err) { holder.innerHTML = ''; holder.appendChild(emptyState(err.message)); });
    });
  }

  // -----------------------------------------------------------------
  function render() {
    var root = document.getElementById('store-app');
    if (!root) return;
    if (TAB === 'konfirmasi') {
      if (window.STORE_FOCUS_SHIPMENT_ID) renderKonfirmasiDetail(root, window.STORE_FOCUS_SHIPMENT_ID);
      else renderKonfirmasiList(root);
    } else if (TAB === 'khusus') {
      if (window.STORE_FOCUS_ORDER_ID) renderKhususDetail(root, window.STORE_FOCUS_ORDER_ID);
      else renderKhususList(root);
    } else if (TAB === 'retur') {
      renderReturList(root);
    } else if (TAB === 'mutasi') {
      renderMutasiTab(root);
    } else if (TAB === 'riwayat') {
      renderRiwayat(root);
    }
  }

  document.addEventListener('DOMContentLoaded', render);
})();
