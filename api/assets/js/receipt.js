/*
 * Amor Factory System — public Store Receipt portal client logic.
 * Standalone (this page never loads app.js / has no session/CSRF token —
 * see App::CSRF_EXEMPT for the public /api/receive/* routes), but follows
 * the same discipline: every write goes through the real JSON API, this
 * file never invents totals — it only renders what the API returns and
 * validates the good+reject+shortage=shipped arithmetic locally as a
 * convenience before submitting (the server re-validates authoritatively).
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
  function genKey() {
    return 'rc-' + Date.now() + '-' + Math.random().toString(36).slice(2);
  }

  function statusBadge(status) {
    var map = {
      pending: ['pending', 'Menunggu Konfirmasi'],
      confirmed_ok: ['ok', 'Diterima Sesuai'],
      confirmed_discrepancy: ['discrepancy', 'Ada Selisih'],
      verified: ['verified', 'Diverifikasi Admin'],
    };
    var m = map[status] || ['pending', status];
    return '<span class="rc-badge ' + m[0] + '">' + esc(m[1]) + '</span>';
  }

  var MAX_EVIDENCE_FILES = 3;
  var MAX_EVIDENCE_SIZE = 5 * 1024 * 1024;

  function renderShipmentCard(view, sh) {
    var card = document.createElement('div');
    card.className = 'rc-card';
    if (window.RECEIPT_FOCUS_SHIPMENT_ID && sh.shipmentId === window.RECEIPT_FOCUS_SHIPMENT_ID) {
      // Display-only focus from an email's ?shipment= hint (Part H) — the
      // token already authorized every shipment in `view`, so this never
      // grants access to anything; it only decides where the page scrolls.
      card.className += ' rc-card-focus';
      card.id = 'rc-focus-shipment';
    }

    var head = document.createElement('div');
    head.innerHTML =
      '<div class="rc-card-title">SHP-' + sh.shipmentId + ' &middot; ' + esc(sh.shipmentGroup) + '</div>' +
      '<div class="rc-card-sub">Driver: ' + esc(sh.driverName || '-') + '</div>' +
      '<div class="rc-card-sub">Berangkat: ' + esc(sh.departedAt || '-') + '</div>' +
      '<div style="margin-top:6px;">' + statusBadge(sh.receiptStatus) + '</div>';
    card.appendChild(head);

    var table = document.createElement('table');
    table.className = 'rc-table';
    var editable = sh.receiptStatus === 'pending';
    table.innerHTML =
      '<thead><tr><th>Produk</th><th class="num">Dikirim</th>' +
      (editable ? '<th class="num">Diterima Baik</th><th class="num">Reject</th><th class="num">Kurang</th>' : '<th class="num">Baik</th><th class="num">Reject</th><th class="num">Kurang</th>') +
      '</tr></thead><tbody>' +
      // Real-UAT mobile fix: data-label on every <td> — the narrow-
      // viewport CSS (receipt.css) turns each row into a stacked card
      // using these as row-field captions, so "Kurang" (and every other
      // column) can never be clipped off-screen on a phone again.
      sh.items.map(function (it) {
        if (editable) {
          return '<tr data-shipment-item-id="' + it.shipmentItemId + '" data-shipped="' + it.shippedQty + '">' +
            '<td data-label="Produk">' + esc(it.productName) + '</td>' +
            '<td class="num" data-label="Dikirim">' + fmtNum(it.shippedQty) + '</td>' +
            '<td class="num" data-label="Diterima Baik"><input class="rc-num-input" data-field="good" type="number" min="0" step="0.01" value="' + it.shippedQty + '"></td>' +
            '<td class="num" data-label="Reject"><input class="rc-num-input" data-field="reject" type="number" min="0" step="0.01" value="0"></td>' +
            '<td class="num" data-label="Kurang"><input class="rc-num-input" data-field="shortage" type="number" min="0" step="0.01" value="0"></td>' +
            '</tr>';
        }
        var g = it.receivedGoodQty, r = it.rejectQty, s = it.shortageQty;
        return '<tr><td data-label="Produk">' + esc(it.productName) + '</td><td class="num" data-label="Dikirim">' + fmtNum(it.shippedQty) + '</td>' +
          '<td class="num" data-label="Baik">' + fmtNum(g) + '</td><td class="num" data-label="Reject">' + fmtNum(r) + '</td><td class="num" data-label="Kurang">' + fmtNum(s) + '</td></tr>';
      }).join('') +
      '</tbody>';
    card.appendChild(table);

    if (editable) {
      var mathError = document.createElement('div');
      mathError.className = 'rc-error-math';
      mathError.textContent = 'Diterima Baik + Reject + Kurang harus sama dengan jumlah dikirim.';
      card.appendChild(mathError);

      var nameField = document.createElement('div');
      nameField.className = 'rc-field';
      nameField.innerHTML = '<label>Nama Penerima</label><input type="text" id="receiver-name-' + sh.shipmentId + '" placeholder="Nama Anda">';
      card.appendChild(nameField);

      var noteField = document.createElement('div');
      noteField.className = 'rc-field';
      noteField.innerHTML = '<label>Catatan (opsional)</label><textarea id="note-' + sh.shipmentId + '" rows="2" placeholder="Contoh: ada 2 pcs rusak pada kemasan"></textarea>';
      card.appendChild(noteField);

      // --- Bukti Foto (real-UAT: wajib jika ada Reject/Kurang) ---
      var selectedFiles = [];
      var evidenceField = document.createElement('div');
      evidenceField.className = 'rc-field';
      evidenceField.innerHTML =
        '<label>Bukti Foto</label>' +
        '<div class="rc-evidence-hint">Wajib jika ada barang reject/rusak atau kurang.</div>' +
        // No `capture` attribute: combined with `multiple` this is a known
        // cross-browser (notably iOS Safari) quirk that can make the file
        // input behave erratically. Leaving it off still lets the native
        // picker offer "Take Photo" alongside the gallery on iOS/Android.
        '<input type="file" accept="image/*" multiple class="rc-evidence-input">' +
        '<div class="rc-evidence-error" style="display:none;"></div>' +
        '<div class="rc-evidence-previews"></div>';
      card.appendChild(evidenceField);
      var evidenceInput = evidenceField.querySelector('.rc-evidence-input');
      var evidenceErrorEl = evidenceField.querySelector('.rc-evidence-error');
      var previewsEl = evidenceField.querySelector('.rc-evidence-previews');

      function showEvidenceError(msg) {
        evidenceErrorEl.textContent = msg;
        evidenceErrorEl.style.display = msg ? 'block' : 'none';
      }

      function openLightbox(src) {
        var overlay = document.createElement('div');
        overlay.className = 'rc-evidence-lightbox';
        var img = document.createElement('img');
        img.src = src;
        overlay.appendChild(img);
        overlay.addEventListener('click', function () { overlay.remove(); });
        document.body.appendChild(overlay);
      }

      function renderPreviews() {
        previewsEl.innerHTML = '';
        selectedFiles.forEach(function (file, i) {
          var thumb = document.createElement('div');
          thumb.className = 'rc-evidence-thumb';
          var img = document.createElement('img');
          img.src = URL.createObjectURL(file);
          img.title = 'Ketuk untuk memperbesar';
          img.addEventListener('click', function () { openLightbox(img.src); });
          thumb.appendChild(img);
          var removeBtn = document.createElement('button');
          removeBtn.type = 'button';
          removeBtn.className = 'rc-evidence-remove';
          removeBtn.title = 'Hapus foto';
          removeBtn.setAttribute('aria-label', 'Hapus foto');
          removeBtn.textContent = '×';
          removeBtn.addEventListener('click', function () {
            selectedFiles.splice(i, 1);
            renderPreviews();
          });
          thumb.appendChild(removeBtn);
          previewsEl.appendChild(thumb);
        });
        // Removing a photo can turn a valid discrepancy receipt back into
        // an invalid one (evidence_count back to 0) — re-run validate() on
        // every re-render so the submit button reflects it immediately,
        // not just whenever the file input's own 'change' last fired.
        validate();
      }

      evidenceInput.addEventListener('change', function () {
        showEvidenceError('');
        var incoming = Array.prototype.slice.call(evidenceInput.files || []);
        incoming.forEach(function (file) {
          if (!/^image\//.test(file.type)) {
            showEvidenceError('Hanya file gambar (JPEG/PNG/WEBP) yang diperbolehkan.');
            return;
          }
          if (file.size > MAX_EVIDENCE_SIZE) {
            showEvidenceError('Ukuran foto maksimal 5 MB.');
            return;
          }
          if (selectedFiles.length >= MAX_EVIDENCE_FILES) {
            showEvidenceError('Maksimal ' + MAX_EVIDENCE_FILES + ' foto bukti.');
            return;
          }
          selectedFiles.push(file);
        });
        evidenceInput.value = '';
        renderPreviews();
      });

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'rc-btn primary';
      btn.textContent = 'Simpan Konfirmasi';
      card.appendChild(btn);

      function hasDiscrepancy() {
        var any = false;
        table.querySelectorAll('tbody tr').forEach(function (tr) {
          var reject = parseFloat(tr.querySelector('[data-field=reject]').value) || 0;
          var shortage = parseFloat(tr.querySelector('[data-field=shortage]').value) || 0;
          if (reject > 0.0001 || shortage > 0.0001) any = true;
        });
        return any;
      }

      function validate() {
        var ok = true;
        table.querySelectorAll('tbody tr').forEach(function (tr) {
          var shipped = parseFloat(tr.dataset.shipped);
          var good = parseFloat(tr.querySelector('[data-field=good]').value) || 0;
          var reject = parseFloat(tr.querySelector('[data-field=reject]').value) || 0;
          var shortage = parseFloat(tr.querySelector('[data-field=shortage]').value) || 0;
          if (Math.abs(good + reject + shortage - shipped) > 0.001) ok = false;
        });
        mathError.style.display = ok ? 'none' : 'block';
        // Client-side convenience only — the server re-validates
        // authoritatively (task's own "Frontend-only validation is NOT
        // enough"); this just gives the store immediate feedback instead
        // of a round-trip.
        var evidenceOk = !hasDiscrepancy() || selectedFiles.length > 0;
        evidenceField.querySelector('.rc-evidence-hint').style.color = evidenceOk ? '' : 'var(--rc-danger)';
        btn.disabled = !ok || !evidenceOk;
        return ok && evidenceOk;
      }
      table.addEventListener('input', validate);
      evidenceInput.addEventListener('change', validate);
      validate();

      btn.addEventListener('click', function () {
        if (!validate()) {
          if (hasDiscrepancy() && selectedFiles.length === 0) {
            showEvidenceError('Bukti foto wajib diunggah untuk barang reject/rusak atau kurang.');
          }
          return;
        }
        var items = [];
        table.querySelectorAll('tbody tr').forEach(function (tr) {
          items.push({
            shipmentItemId: parseInt(tr.dataset.shipmentItemId, 10),
            receivedGood: parseFloat(tr.querySelector('[data-field=good]').value) || 0,
            reject: parseFloat(tr.querySelector('[data-field=reject]').value) || 0,
            shortage: parseFloat(tr.querySelector('[data-field=shortage]').value) || 0,
          });
        });
        btn.disabled = true;
        btn.textContent = 'Menyimpan...';
        var form = new FormData();
        form.append('receiverName', document.getElementById('receiver-name-' + sh.shipmentId).value || '');
        form.append('note', document.getElementById('note-' + sh.shipmentId).value || '');
        form.append('items', JSON.stringify(items));
        selectedFiles.forEach(function (file) { form.append('evidence[]', file, file.name); });
        fetch('/api/receive/' + encodeURIComponent(window.RECEIPT_TOKEN) + '/shipments/' + sh.shipmentId + '/confirm', {
          method: 'POST',
          headers: { 'Idempotency-Key': genKey() },
          body: form,
        }).then(function (res) { return res.json().then(function (json) { return { res: res, json: json }; }); })
          .then(function (r) {
            if (!r.res.ok || r.json.ok === false) {
              throw new Error((r.json && r.json.message) || 'Gagal menyimpan konfirmasi');
            }
            reload();
          }).catch(function (err) {
            alert(err.message);
            btn.disabled = false;
            btn.textContent = 'Simpan Konfirmasi';
          });
      });
    }

    return card;
  }

  function reload() {
    window.location.reload();
  }

  function render() {
    var root = document.getElementById('receipt-app');
    var view = window.RECEIPT_VIEW;
    if (!view) return;

    var header = document.createElement('div');
    header.className = 'rc-card';
    header.innerHTML =
      '<div class="rc-card-title">' + esc(view.docNo || '-') + '</div>' +
      '<div class="rc-card-sub">' + esc(view.storeName || '-') + ' &middot; ' + esc(view.tanggal || '-') + '</div>';
    root.appendChild(header);

    if (!view.shipments || view.shipments.length === 0) {
      var empty = document.createElement('div');
      empty.className = 'rc-card rc-empty';
      empty.textContent = 'Pengiriman belum dikonfirmasi berangkat.';
      root.appendChild(empty);
      return;
    }

    var title = document.createElement('div');
    title.style.padding = '0 14px 6px';
    title.style.fontWeight = '700';
    title.style.fontSize = '.85rem';
    title.textContent = view.shipments.length > 1 ? 'Pengiriman untuk DO ini' : '';
    if (title.textContent) root.appendChild(title);

    view.shipments.forEach(function (sh) {
      root.appendChild(renderShipmentCard(view, sh));
    });

    if (window.RECEIPT_FOCUS_SHIPMENT_ID) {
      var focusEl = document.getElementById('rc-focus-shipment');
      if (focusEl) {
        focusEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }
  }

  document.addEventListener('DOMContentLoaded', render);
})();
