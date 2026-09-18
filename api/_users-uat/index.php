<?php

declare(strict_types=1);

/**
 * ADMIN-ONLY User / Driver Account Management. A standalone side-by-side
 * admin tool — same convention as api/_driver-uat/, api/_do-uat/ etc. —
 * deliberately NOT wired into api/app/ui/layout.php's sidebar yet (task's
 * own "do not redesign root app" instruction), reached directly at
 * /api/_users-uat/.
 *
 * Reuses app/ui/bootstrap.php's EXISTING session/CSRF model as-is (it
 * already redirects an unauthenticated visitor to api/_admin-login/, which
 * is exactly right here — this tool needs a real ADMIN, not a driver or
 * any other role). The one thing bootstrap.php does NOT check is role, so
 * this file adds its own explicit ADMIN-only gate below, same shape as
 * every other role-gated controller in this app.
 *
 * The user list itself is rendered server-side via the REAL UserService —
 * never a second copy of the query. Every mutating action (create, edit,
 * role change, password reset, activate/deactivate) goes through the real
 * JSON API via assets/js/users.js + Amor.apiFetch, same as every other
 * admin page in this app.
 */

require __DIR__ . '/../app/ui/bootstrap.php';
require_once __DIR__ . '/../app/ui/components.php';
require_once __DIR__ . '/../app/ui/labels.php';

use Amor\Api\Users\UserService;

if (!in_array('ADMIN', $ui['roles'], true)) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Akses ditolak</title></head><body style="font-family:sans-serif;max-width:420px;margin:3rem auto;padding:0 1rem;">'
        . '<h1>Akses ditolak</h1><p>Halaman ini hanya untuk akun dengan peran Admin.</p></body></html>';
    exit;
}

$service = new UserService($ui['pdo']);
$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$activeParam = $_GET['active'] ?? '';
$active = $activeParam === '1' ? true : ($activeParam === '0' ? false : null);
$users = $service->listUsers($q !== '' ? $q : null, $active);
$roles = $service->listRoles();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Manajemen User — Amor Factory System</title>
<link rel="stylesheet" href="/api/assets/css/tokens.css">
<link rel="stylesheet" href="/api/assets/css/app.css">
</head>
<body>
<div style="max-width:1100px;margin:0 auto;padding:var(--space-5) var(--space-4);">

  <div style="display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-5);">
    <a href="/api/_ui-preview/" class="btn btn-secondary btn-sm">&larr; Kembali</a>
    <div style="flex:1;">
      <h1 style="margin:0;font-size:var(--text-xl);">Manajemen User &amp; Akun Driver</h1>
      <div style="color:var(--text-muted);font-size:var(--text-sm);">Kelola akun login, peran (role), dan status aktif/nonaktif. Khusus Admin.</div>
    </div>
    <div style="color:var(--text-muted);font-size:var(--text-sm);"><?= ui_esc($ui['fullName'] !== '' ? $ui['fullName'] : $ui['username']) ?></div>
  </div>

  <div class="filter-bar">
    <form method="get" style="display:flex;gap:var(--space-3);align-items:flex-end;flex-wrap:wrap;flex:1;">
      <div class="field"><label>Cari</label><input type="text" name="q" value="<?= ui_esc($q) ?>" placeholder="Username atau nama..."></div>
      <div class="field"><label>Status</label>
        <select name="active">
          <option value="">Semua Status</option>
          <option value="1" <?= $activeParam === '1' ? 'selected' : '' ?>>Aktif</option>
          <option value="0" <?= $activeParam === '0' ? 'selected' : '' ?>>Nonaktif</option>
        </select>
      </div>
      <button type="submit" class="btn btn-secondary">Terapkan</button>
    </form>
    <button type="button" class="btn btn-primary" id="btn-add-user">+ Tambah User</button>
  </div>

  <?php if ($users === []): ?>
  <?= ui_empty_state('Belum ada user', 'Tidak ada user yang cocok dengan filter ini.') ?>
  <?php else: ?>
  <div class="card section">
    <div class="table-scroll"><table class="data-table">
      <thead><tr>
        <th>Username</th><th>Nama</th><th>Peran</th><th>Status</th><th>Dibuat</th><th>Aksi</th>
      </tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
      <tr data-user-row="<?= (int) $u['userId'] ?>">
        <td><?= ui_esc($u['username']) ?></td>
        <td><?= ui_esc($u['fullName']) ?></td>
        <td><?= $u['roles'] === [] ? '<span style="color:var(--text-faint);">-</span>' : ui_esc(implode(', ', $u['roles'])) ?></td>
        <td><?= ui_badge($u['active'] ? 'Aktif' : 'Nonaktif') ?></td>
        <td><?= ui_esc(substr($u['createdAt'], 0, 10)) ?></td>
        <td style="white-space:nowrap;">
          <button type="button" class="btn btn-secondary btn-sm"
            data-edit-user="<?= (int) $u['userId'] ?>"
            data-username="<?= ui_esc($u['username']) ?>"
            data-fullname="<?= ui_esc($u['fullName']) ?>"
            data-roles="<?= ui_esc(implode(',', $u['roles'])) ?>">Edit</button>
          <button type="button" class="btn btn-secondary btn-sm" data-reset-password="<?= (int) $u['userId'] ?>" data-username="<?= ui_esc($u['username']) ?>">Reset Password</button>
          <?php if ($u['active']): ?>
          <button type="button" class="btn btn-danger btn-sm" data-confirm-action data-danger
            data-method="POST" data-url="/api/users/<?= (int) $u['userId'] ?>/deactivate"
            data-confirm-title="Nonaktifkan user?"
            data-confirm-body="<?= ui_esc('Akun "' . $u['username'] . '" tidak akan bisa login sampai diaktifkan kembali.') ?>"
            data-confirm-label="Ya, nonaktifkan" data-success-message="User dinonaktifkan" data-reload="1">Nonaktifkan</button>
          <?php else: ?>
          <button type="button" class="btn btn-secondary btn-sm" data-confirm-action
            data-method="POST" data-url="/api/users/<?= (int) $u['userId'] ?>/activate"
            data-confirm-title="Aktifkan user?"
            data-confirm-body="<?= ui_esc('Akun "' . $u['username'] . '" akan bisa login kembali.') ?>"
            data-confirm-label="Ya, aktifkan" data-success-message="User diaktifkan" data-reload="1">Aktifkan</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

</div>

<div id="user-modal-root"
     data-roles='<?= json_encode($roles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>'></div>

<script>window.AMOR = <?= json_encode(['csrfToken' => $ui['csrfToken'], 'userId' => $ui['userId'], 'username' => $ui['username']], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/api/assets/js/app.js"></script>
<script src="/api/assets/js/users.js"></script>
</body>
</html>
