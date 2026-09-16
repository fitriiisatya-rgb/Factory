<?php

declare(strict_types=1);

/**
 * Amor Factory — Phase 1 fast-track master/identity import wizard.
 *
 * TEMPORARY, like api/_setup/index.php. Delete this whole directory once
 * Phase 1 is reviewed and accepted — see item 12 of the Phase 1 request.
 *
 * Unlike _setup (pre-auth, SETUP_TOKEN-gated), this wizard requires a real
 * ADMIN login session (POST /api/auth/login first, in the same browser) —
 * it is a normal authenticated admin action, not a bootstrap tool. CSRF is
 * the session's own csrf_token (issued at login), carried as a hidden form
 * field since this is a plain HTML form wizard, not a fetch()-based client.
 *
 * Imports ONLY: divisions, the KATALOG_BAWAAN product catalog (SAFE rows —
 * REVIEW/CONFLICT rows are staged into migration_product_map for the admin
 * review API, never auto-resolved), and the one confirmed store alias group
 * bundled from the frontend source. Never touches PO/production/FG/DO/
 * shipment/stock/invoice/payment/return/sale tables — see
 * Amor\Api\Import\Phase1Importer, which has no code path that writes to
 * any of them.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Import\Phase1Importer;

$markerFile = __DIR__ . '/.disabled';
if (is_file($markerFile) || Config::get('IMPORT_MASTER_ENABLED', true) === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    exit;
}

try {
    Config::load();
} catch (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
        . '<h1>Konfigurasi belum lengkap</h1><p>' . htmlspecialchars($e->getMessage()) . '</p></body></html>';
    exit;
}

Auth::bootSession();

if (Auth::currentUserId() === null) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Login diperlukan</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:2rem auto;">'
        . '<h1>Login diperlukan</h1><p>Halaman ini butuh sesi ADMIN yang sudah login. '
        . 'Login dulu lewat aplikasi utama (atau <code>POST /api/auth/login</code>), lalu buka '
        . 'ulang halaman ini di browser/tab yang sama.</p></body></html>';
    exit;
}

try {
    Auth::requireRole('ADMIN');
} catch (ApiException $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Akses ditolak</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:2rem auto;">'
        . '<h1>Akses ditolak</h1><p>Akun Anda login, tapi bukan ADMIN. Halaman ini hanya untuk ADMIN.</p>'
        . '</body></html>';
    exit;
}

$csrfToken = (string) $_SESSION['csrf_token'];
$pdo = Database::pdo();
$importer = new Phase1Importer($pdo);

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';
$postedCsrf = (string) ($_POST['csrf'] ?? '');
$actionResult = null;

if ($action !== '' && !hash_equals($csrfToken, $postedCsrf)) {
    $actionResult = ['ok' => false, 'title' => 'CSRF token tidak cocok', 'message' => 'Muat ulang halaman dan coba lagi.'];
    $action = '';
}

if ($action === 'import_divisions' && ($_POST['confirm'] ?? '') === '1') {
    try {
        $result = $importer->importDivisions();
        $actionResult = ['ok' => true, 'title' => 'Divisi berhasil diimpor', 'message' => "Baru: {$result['created']}, sudah ada sebelumnya: {$result['alreadyExisted']}."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Impor divisi gagal', 'message' => $e->getMessage()];
    }
}

if ($action === 'import_products' && ($_POST['confirm'] ?? '') === '1') {
    try {
        $result = $importer->importSafeProducts();
        $actionResult = ['ok' => true, 'title' => 'Produk (SAFE) berhasil diimpor', 'message' =>
            "Diimpor baru: {$result['imported']}, sudah pernah dipetakan: {$result['alreadyMapped']}, "
            . "dimasukkan ke antrian review/conflict: {$result['staged']}."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Impor produk gagal', 'message' => $e->getMessage()];
    }
}

if ($action === 'import_stores' && ($_POST['confirm'] ?? '') === '1') {
    try {
        $result = $importer->importStoreAliasGroups();
        $actionResult = ['ok' => true, 'title' => 'Toko & alias berhasil diimpor', 'message' =>
            "Toko baru: {$result['storesCreated']}, alias baru: {$result['aliasesCreated']}, "
            . "alias bentrok (dilewati, butuh review manual): {$result['aliasesSkippedConflict']}."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Impor toko gagal', 'message' => $e->getMessage()];
    }
}

if ($action === 'disable') {
    @file_put_contents($markerFile, "disabled at " . gmdate('c') . " by " . ($_SESSION['username'] ?? '?') . "\n");
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Import wizard dinonaktifkan</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:2rem auto;">'
        . '<h1 style="color:#080;">Sudah dinonaktifkan</h1><p>Halaman ini sekarang mengembalikan 404. '
        . 'Disarankan tetap menghapus folder <code>api/_import-master/</code> lewat File Manager kapan pun sempat.</p>'
        . '</body></html>';
    exit;
}

// ---------------------------------------------------------------------
// Live previews (read-only, safe to compute on every load)
// ---------------------------------------------------------------------
$divisionPreview = $importer->previewDivisions();
$productPreview = $importer->previewProducts();
$storePreview = $importer->previewStoreAliasGroups();

$divisionsNew = count(array_filter($divisionPreview, fn($d) => $d['state'] === 'new'));
$storeAliasesNew = 0;
$storeAliasesConflict = 0;
foreach ($storePreview as $g) {
    foreach ($g['aliases'] as $a) {
        if ($a['state'] === 'new') $storeAliasesNew++;
        if ($a['state'] === 'conflict') $storeAliasesConflict++;
    }
}

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>Amor Factory — Import Master (Phase 1)</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:820px;margin:2rem auto;padding:0 1rem;color:#1a1a1a;line-height:1.5;}
h1{font-size:1.4rem;} h2{font-size:1.1rem;margin-top:2rem;border-bottom:2px solid #eee;padding-bottom:.3rem;}
.box{border:1px solid #ddd;border-radius:8px;padding:1rem;margin:1rem 0;}
.warn{background:#fff3cd;border:1px solid #f0c36d;padding:.75rem;border-radius:6px;}
.result-ok{background:#e6ffe6;border:1px solid #8c8;padding:.75rem;border-radius:6px;}
.result-error{background:#ffe6e6;border:1px solid #e99;padding:.75rem;border-radius:6px;}
button{padding:.5rem 1.2rem;background:#0a5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:bold;}
button.danger{background:#b00;}
table{border-collapse:collapse;width:100%;margin:.5rem 0;}
td,th{text-align:left;padding:.3rem .6rem;border-bottom:1px solid #eee;font-size:.9em;}
.badge{display:inline-block;padding:1px 8px;border-radius:10px;font-size:.8em;font-weight:bold;color:#fff;}
.b-safe{background:#080;} .b-review{background:#e90;} .b-conflict{background:#b00;} .b-mapped{background:#666;}
</style>
</head>
<body>

<h1>Amor Factory — Import Master &amp; Identity (Phase 1 Fast-Track)</h1>
<p>Login sebagai: <strong><?= esc((string) $_SESSION['username']) ?></strong>. Tidak ada data PO/produksi/FG/DO/
pengiriman/stok/invoice/pembayaran/retur/penjualan yang disentuh oleh halaman ini.</p>

<?php if ($actionResult !== null): ?>
<div class="<?= $actionResult['ok'] ? 'result-ok' : 'result-error' ?>">
  <strong><?= esc($actionResult['title']) ?></strong>
  <p><?= esc($actionResult['message']) ?></p>
</div>
<?php endif; ?>

<h2>1. Divisi + Pabrik</h2>
<div class="box">
  <table>
    <tr><th>Nama Divisi</th><th>Pabrik</th><th>Verifikasi FG?</th><th>Status</th></tr>
    <?php foreach ($divisionPreview as $d): ?>
    <tr>
      <td><?= esc($d['name']) ?></td>
      <td><?= esc($d['factory']) ?></td>
      <td><?= $d['is_verification'] ? 'Ya' : '-' ?></td>
      <td><span class="badge <?= $d['state'] === 'new' ? 'b-safe' : 'b-mapped' ?>"><?= $d['state'] === 'new' ? 'BARU' : 'SUDAH ADA' ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php if ($divisionsNew > 0): ?>
  <div class="warn"><p>Akan membuat <?= $divisionsNew ?> divisi baru (dari total 8). Aman dijalankan berkali-kali.</p></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="import_divisions">
    <label><input type="checkbox" name="confirm" value="1" required> Saya ingin memasang divisi sekarang.</label>
    <button type="submit">Import Divisi</button>
  </form>
  <?php else: ?>
  <p>Semua 8 divisi sudah terpasang.</p>
  <?php endif; ?>
</div>

<h2>2. Produk (Katalog Bawaan)</h2>
<div class="box">
  <p>Total baris katalog: <?= count($productPreview['safe']) + count($productPreview['review']) + count($productPreview['conflict']) + $productPreview['alreadyMapped'] ?></p>
  <table>
    <tr><td><span class="badge b-mapped">SUDAH DIPETAKAN</span></td><td><?= $productPreview['alreadyMapped'] ?></td></tr>
    <tr><td><span class="badge b-safe">SAFE (siap diimpor)</span></td><td><?= count($productPreview['safe']) ?></td></tr>
    <tr><td><span class="badge b-review">REVIEW (butuh admin)</span></td><td><?= count($productPreview['review']) ?></td></tr>
    <tr><td><span class="badge b-conflict">CONFLICT (butuh admin)</span></td><td><?= count($productPreview['conflict']) ?></td></tr>
  </table>
  <?php if (count($productPreview['review']) > 0 || count($productPreview['conflict']) > 0): ?>
  <p><em>Baris REVIEW/CONFLICT tidak akan pernah diimpor otomatis — akan dimasukkan ke antrian
  <code>GET /api/admin/migration/products?status=unresolved</code> untuk diselesaikan manual.</em></p>
  <?php endif; ?>
  <?php if (count($productPreview['safe']) > 0): ?>
  <div class="warn"><p>Akan membuat <?= count($productPreview['safe']) ?> produk baru + kode legacy-nya. Tidak menimpa produk yang sudah ada.</p></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="import_products">
    <label><input type="checkbox" name="confirm" value="1" required> Saya ingin mengimpor produk SAFE sekarang.</label>
    <button type="submit">Import Produk (SAFE)</button>
  </form>
  <?php else: ?>
  <p>Tidak ada baris SAFE yang menunggu — semua sudah dipetakan, atau butuh review manual.</p>
  <?php endif; ?>
</div>

<h2>3. Toko &amp; Alias</h2>
<div class="box">
  <?php foreach ($storePreview as $g): ?>
    <p><strong><?= esc($g['canonical']) ?></strong> — <span class="badge <?= $g['storeState'] === 'new' ? 'b-safe' : 'b-mapped' ?>"><?= $g['storeState'] === 'new' ? 'TOKO BARU' : 'SUDAH ADA' ?></span></p>
    <table>
      <tr><th>Alias</th><th>Status</th></tr>
      <?php foreach ($g['aliases'] as $a): ?>
      <tr><td><?= esc($a['alias']) ?></td><td><span class="badge <?= $a['state'] === 'new' ? 'b-safe' : ($a['state'] === 'conflict' ? 'b-conflict' : 'b-mapped') ?>"><?= esc(strtoupper($a['state'])) ?></span></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>
  <p style="color:#666;">Ini SATU-SATUNYA grup alias toko yang benar-benar ditemukan di source code frontend
  (<code>bootstrapTokoCanonicalDikenal()</code>). Tidak ada daftar toko/outlet lain yang bisa diambil otomatis
  dari source ini — lihat laporan Phase 1 untuk detail.</p>
  <?php if ($storeAliasesNew > 0): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="import_stores">
    <label><input type="checkbox" name="confirm" value="1" required> Saya ingin memasang toko &amp; alias di atas sekarang.</label>
    <button type="submit">Import Toko &amp; Alias</button>
  </form>
  <?php else: ?>
  <p>Tidak ada yang baru untuk diimpor.</p>
  <?php endif; ?>
</div>

<h2>4. Selesai</h2>
<div class="box">
  <p>Setelah semua bagian di atas dijalankan (atau memang tidak ada lagi yang baru), gunakan
  <code>GET /api/admin/migration/products?status=unresolved</code> dan
  <code>GET /api/admin/migration/stores?status=unresolved</code> untuk menyelesaikan sisa baris REVIEW/CONFLICT
  satu per satu lewat <code>POST .../{id}/resolve</code>.</p>
  <form method="post" onsubmit="return confirm('Nonaktifkan halaman import ini sekarang?');">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="disable">
    <button type="submit" class="danger">Nonaktifkan Import Wizard</button>
  </form>
</div>

</body>
</html>
