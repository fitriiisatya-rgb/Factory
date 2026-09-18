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

  function renderShipmentCard(view, sh) {
    var card = document.createElement('div');
    card.className = 'rc-card';

    var head = document.createElement('div');
    head.innerHTML =
      '<div class="rc-card-title">Pengiriman ' + esc(sh.shipmentGroup) + '</div>' +
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
      sh.items.map(function (it) {
        if (editable) {
          return '<tr data-shipment-item-id="' + it.shipmentItemId + '" data-shipped="' + it.shippedQty + '">' +
            '<td>' + esc(it.productName) + '</td>' +
            '<td class="num">' + fmtNum(it.shippedQty) + '</td>' +
            '<td class="num"><input class="rc-num-input" data-field="good" type="number" min="0" step="0.01" value="' + it.shippedQty + '"></td>' +
            '<td class="num"><input class="rc-num-input" data-field="reject" type="number" min="0" step="0.01" value="0"></td>' +
            '<td class="num"><input class="rc-num-input" data-field="shortage" type="number" min="0" step="0.01" value="0"></td>' +
            '</tr>';
        }
        var g = it.receivedGoodQty, r = it.rejectQty, s = it.shortageQty;
        return '<tr><td>' + esc(it.productName) + '</td><td class="num">' + fmtNum(it.shippedQty) + '</td>' +
          '<td class="num">' + fmtNum(g) + '</td><td class="num">' + fmtNum(r) + '</td><td class="num">' + fmtNum(s) + '</td></tr>';
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

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'rc-btn primary';
      btn.textContent = 'Simpan Konfirmasi';
      card.appendChild(btn);

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
        btn.disabled = !ok;
        return ok;
      }
      table.addEventListener('input', validate);
      validate();

      btn.addEventListener('click', function () {
        if (!validate()) return;
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
        fetch('/api/receive/' + encodeURIComponent(window.RECEIPT_TOKEN) + '/shipments/' + sh.shipmentId + '/confirm', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Idempotency-Key': genKey() },
          body: JSON.stringify({
            receiverName: document.getElementById('receiver-name-' + sh.shipmentId).value || null,
            note: document.getElementById('note-' + sh.shipmentId).value || null,
            items: items,
          }),
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
  }

  document.addEventListener('DOMContentLoaded', render);
})();
