/**
 * User / Driver Account Management — client-side interactivity only.
 * Every mutation goes through the REAL JSON API via Amor.apiFetch (CSRF +
 * Idempotency-Key handled there already) — this file never reimplements
 * validation that the server already owns; anything checked here (e.g.
 * password match) is a convenience, the server re-checks everything.
 *
 * The user list table itself is server-rendered (see index.php) — this
 * file only drives the Create/Edit/Reset-Password modals and reloads the
 * page after a successful mutation, same "simplest correct thing" pattern
 * as every other admin page's JS in this app. Activate/Deactivate use the
 * app.js's own generic [data-confirm-action] mechanism and need no code
 * here at all.
 */
(function () {
  'use strict';

  var rolesRoot = document.getElementById('user-modal-root');
  var ROLES = [];
  try { ROLES = JSON.parse((rolesRoot && rolesRoot.getAttribute('data-roles')) || '[]'); } catch (e) { ROLES = []; }
  var DIVISIONS = [];
  try { DIVISIONS = JSON.parse((rolesRoot && rolesRoot.getAttribute('data-divisions')) || '[]'); } catch (e) { DIVISIONS = []; }
  var FACTORIES = [];
  try { FACTORIES = JSON.parse((rolesRoot && rolesRoot.getAttribute('data-factories')) || '[]'); } catch (e) { FACTORIES = []; }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function roleCheckboxesHtml(checkedCodes) {
    checkedCodes = checkedCodes || [];
    return ROLES.map(function (r) {
      var checked = checkedCodes.indexOf(r.code) !== -1 ? 'checked' : '';
      return '<label style="display:flex;align-items:center;gap:8px;padding:4px 0;">'
        + '<input type="checkbox" name="roles" value="' + esc(r.code) + '" ' + checked + '>'
        + '<span>' + esc(r.name) + ' <span style="color:var(--text-faint);">(' + esc(r.code) + ')</span></span>'
        + '</label>';
    }).join('');
  }

  // Division access (PRODUCTION role scoping) and factory access
  // (FG_PACKING role scoping) are OPT-IN restrictions — leaving every box
  // unchecked keeps that user's current unrestricted role-based access
  // (see Auth::requireDivisionAccess()'s own docblock server-side), never
  // an accidental full lockout. Divisions are grouped by factory since
  // that's how the assignment reads in the task ("Karangtengah -> Roti &
  // Bollen").
  function divisionCheckboxesHtml(checkedIds) {
    checkedIds = checkedIds || [];
    var byFactory = {};
    DIVISIONS.forEach(function (d) {
      (byFactory[d.factoryName] = byFactory[d.factoryName] || []).push(d);
    });
    return Object.keys(byFactory).map(function (factoryName) {
      var rows = byFactory[factoryName].map(function (d) {
        var checked = checkedIds.indexOf(d.divisionId) !== -1 ? 'checked' : '';
        return '<label style="display:flex;align-items:center;gap:8px;padding:2px 0 2px 12px;">'
          + '<input type="checkbox" name="divisionIds" value="' + d.divisionId + '" ' + checked + '>'
          + '<span>' + esc(d.name) + '</span></label>';
      }).join('');
      return '<div style="margin-bottom:4px;"><div style="font-weight:600;font-size:var(--text-sm);">' + esc(factoryName) + '</div>' + rows + '</div>';
    }).join('');
  }

  function factoryCheckboxesHtml(checkedIds) {
    checkedIds = checkedIds || [];
    return FACTORIES.map(function (f) {
      var checked = checkedIds.indexOf(f.factoryId) !== -1 ? 'checked' : '';
      return '<label style="display:flex;align-items:center;gap:8px;padding:4px 0;">'
        + '<input type="checkbox" name="factoryIds" value="' + f.factoryId + '" ' + checked + '>'
        + '<span>' + esc(f.name) + '</span></label>';
    }).join('');
  }

  function openModal(html, title) {
    var backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop open';
    backdrop.innerHTML = '<div class="modal" role="dialog" aria-modal="true">'
      + '<div class="modal-title">' + esc(title) + '</div>'
      + '<div class="modal-body">' + html + '</div>'
      + '</div>';
    function close() { backdrop.remove(); }
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
    document.addEventListener('keydown', function escHandler(e) {
      if (e.key === 'Escape') { document.removeEventListener('keydown', escHandler); close(); }
    });
    document.body.appendChild(backdrop);
    return { root: backdrop.querySelector('.modal'), close: close };
  }

  function fieldHtml(label, inputHtml) {
    return '<div class="field" style="margin-bottom:12px;"><label>' + esc(label) + '</label>' + inputHtml + '</div>';
  }

  // -----------------------------------------------------------------
  // Create / Edit User
  // -----------------------------------------------------------------
  function openUserForm(existing) {
    var isEdit = !!existing;
    var body = ''
      + (isEdit
          ? '<div style="margin-bottom:12px;color:var(--text-muted);">Username: <strong>' + esc(existing.username) + '</strong> (tidak bisa diubah)</div>'
          : fieldHtml('Username', '<input type="text" id="uf-username" required autocomplete="off">'))
      + fieldHtml('Nama Lengkap', '<input type="text" id="uf-fullname" required value="' + (isEdit ? esc(existing.fullname) : '') + '">')
      + (isEdit ? '' :
          fieldHtml('Password', '<input type="password" id="uf-password" required autocomplete="new-password">')
          + fieldHtml('Konfirmasi Password', '<input type="password" id="uf-password-confirm" required autocomplete="new-password">'))
      + '<div style="margin-bottom:12px;"><label style="display:block;margin-bottom:6px;">Peran</label>'
      + '<div id="uf-roles">' + roleCheckboxesHtml(isEdit ? existing.roles.split(',').filter(Boolean) : []) + '</div></div>'
      + '<div style="margin-bottom:12px;"><label style="display:block;margin-bottom:6px;">Divisi Produksi (opsional — kosong = akses semua divisi sesuai peran)</label>'
      + '<div id="uf-divisions" style="max-height:160px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px;">' + divisionCheckboxesHtml(isEdit ? existing.divisionIds : []) + '</div></div>'
      + '<div style="margin-bottom:12px;"><label style="display:block;margin-bottom:6px;">Pabrik FG &amp; Packing (opsional — kosong = akses semua pabrik sesuai peran)</label>'
      + '<div id="uf-factories">' + factoryCheckboxesHtml(isEdit ? existing.factoryIds : []) + '</div></div>'
      + '<div id="uf-error" style="color:var(--danger);font-size:var(--text-sm);margin-bottom:8px;display:none;"></div>'
      + '<div class="modal-actions">'
      + '<button type="button" class="btn btn-secondary" id="uf-cancel">Batal</button>'
      + '<button type="button" class="btn btn-primary" id="uf-save">Simpan</button>'
      + '</div>';

    var modal = openModal(body, isEdit ? 'Edit User' : 'Tambah User');
    modal.root.querySelector('#uf-cancel').addEventListener('click', modal.close);

    modal.root.querySelector('#uf-save').addEventListener('click', async function () {
      var errEl = modal.root.querySelector('#uf-error');
      errEl.style.display = 'none';
      var fullName = modal.root.querySelector('#uf-fullname').value.trim();
      var selectedRoles = Array.prototype.map.call(
        modal.root.querySelectorAll('#uf-roles input[type=checkbox]:checked'), function (cb) { return cb.value; }
      );
      var selectedDivisionIds = Array.prototype.map.call(
        modal.root.querySelectorAll('#uf-divisions input[type=checkbox]:checked'), function (cb) { return parseInt(cb.value, 10); }
      );
      var selectedFactoryIds = Array.prototype.map.call(
        modal.root.querySelectorAll('#uf-factories input[type=checkbox]:checked'), function (cb) { return parseInt(cb.value, 10); }
      );
      if (fullName === '') { errEl.textContent = 'Nama wajib diisi.'; errEl.style.display = 'block'; return; }
      if (selectedRoles.length === 0) { errEl.textContent = 'Pilih minimal satu peran.'; errEl.style.display = 'block'; return; }

      var saveBtn = modal.root.querySelector('#uf-save');
      saveBtn.disabled = true;
      try {
        if (isEdit) {
          await Amor.apiFetch('/api/users/' + existing.id, { method: 'PUT', body: { fullName: fullName } });
          await Amor.apiFetch('/api/users/' + existing.id + '/roles', { method: 'PUT', body: { roles: selectedRoles } });
          await Amor.apiFetch('/api/users/' + existing.id + '/divisions', { method: 'PUT', body: { divisionIds: selectedDivisionIds } });
          await Amor.apiFetch('/api/users/' + existing.id + '/factories', { method: 'PUT', body: { factoryIds: selectedFactoryIds } });
          Amor.toast('User diperbarui', 'success');
        } else {
          var username = modal.root.querySelector('#uf-username').value.trim();
          var password = modal.root.querySelector('#uf-password').value;
          var passwordConfirm = modal.root.querySelector('#uf-password-confirm').value;
          if (username === '') { throw { message: 'Username wajib diisi.' }; }
          if (password !== passwordConfirm) { throw { message: 'Konfirmasi password tidak cocok.' }; }
          var created = await Amor.apiFetch('/api/users', {
            method: 'POST',
            body: { username: username, fullName: fullName, password: password, passwordConfirm: passwordConfirm, roles: selectedRoles },
          });
          var newUserId = created && created.userId;
          if (newUserId && (selectedDivisionIds.length || selectedFactoryIds.length)) {
            await Amor.apiFetch('/api/users/' + newUserId + '/divisions', { method: 'PUT', body: { divisionIds: selectedDivisionIds } });
            await Amor.apiFetch('/api/users/' + newUserId + '/factories', { method: 'PUT', body: { factoryIds: selectedFactoryIds } });
          }
          Amor.toast('User dibuat', 'success');
        }
        modal.close();
        setTimeout(function () { location.reload(); }, 400);
      } catch (e) {
        errEl.textContent = e.message || 'Terjadi kesalahan.';
        errEl.style.display = 'block';
      } finally {
        saveBtn.disabled = false;
      }
    });
  }

  // -----------------------------------------------------------------
  // Reset Password
  // -----------------------------------------------------------------
  function openResetPassword(userId, username) {
    var body = fieldHtml('Password Baru', '<input type="password" id="rp-password" required autocomplete="new-password">')
      + fieldHtml('Konfirmasi Password Baru', '<input type="password" id="rp-password-confirm" required autocomplete="new-password">')
      + '<div id="rp-error" style="color:var(--danger);font-size:var(--text-sm);margin-bottom:8px;display:none;"></div>'
      + '<div class="modal-actions">'
      + '<button type="button" class="btn btn-secondary" id="rp-cancel">Batal</button>'
      + '<button type="button" class="btn btn-primary" id="rp-save">Reset Password</button>'
      + '</div>';
    var modal = openModal(body, 'Reset Password — ' + username);
    modal.root.querySelector('#rp-cancel').addEventListener('click', modal.close);
    modal.root.querySelector('#rp-save').addEventListener('click', async function () {
      var errEl = modal.root.querySelector('#rp-error');
      errEl.style.display = 'none';
      var password = modal.root.querySelector('#rp-password').value;
      var passwordConfirm = modal.root.querySelector('#rp-password-confirm').value;
      if (password !== passwordConfirm) { errEl.textContent = 'Konfirmasi password tidak cocok.'; errEl.style.display = 'block'; return; }
      var saveBtn = modal.root.querySelector('#rp-save');
      saveBtn.disabled = true;
      try {
        await Amor.apiFetch('/api/users/' + userId + '/reset-password', { method: 'POST', body: { password: password, passwordConfirm: passwordConfirm } });
        Amor.toast('Password berhasil direset', 'success');
        modal.close();
      } catch (e) {
        errEl.textContent = e.message || 'Terjadi kesalahan.';
        errEl.style.display = 'block';
      } finally {
        saveBtn.disabled = false;
      }
    });
  }

  // -----------------------------------------------------------------
  // Wire up buttons rendered by index.php.
  // -----------------------------------------------------------------
  document.addEventListener('DOMContentLoaded', function () {
    var addBtn = document.getElementById('btn-add-user');
    if (addBtn) addBtn.addEventListener('click', function () { openUserForm(null); });

    document.querySelectorAll('[data-edit-user]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openUserForm({
          id: btn.getAttribute('data-edit-user'),
          username: btn.getAttribute('data-username'),
          fullname: btn.getAttribute('data-fullname'),
          roles: btn.getAttribute('data-roles') || '',
          divisionIds: (btn.getAttribute('data-division-ids') || '').split(',').filter(Boolean).map(function (s) { return parseInt(s, 10); }),
          factoryIds: (btn.getAttribute('data-factory-ids') || '').split(',').filter(Boolean).map(function (s) { return parseInt(s, 10); }),
        });
      });
    });

    document.querySelectorAll('[data-reset-password]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openResetPassword(btn.getAttribute('data-reset-password'), btn.getAttribute('data-username'));
      });
    });
  });
})();
