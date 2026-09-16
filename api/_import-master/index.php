<?php

declare(strict_types=1);

/**
 * Amor Factory — Phase 1 fast-track master/identity import wizard.
 *
 * TEMPORARY, like api/_admin-login/. Delete this whole directory once
 * Phase 1 is reviewed and accepted. api/_upgrade/ is the one wizard meant
 * to stay.
 *
 * Requires a real ADMIN login session — see api/_admin-login/. CSRF is the
 * session's own csrf_token, carried as a hidden form field (plain HTML
 * form wizard, not fetch()-based).
 *
 * Uses ONLY the normal runtime connection (Amor\Api\Database::pdo(), the
 * DML-only DB_USER) for every write in this file — divisions, products,
 * store aliases, manual store creation, and store-candidate review are all
 * plain INSERT/UPDATE/SELECT, proving the runtime user's SELECT/INSERT/
 * UPDATE/DELETE-only privileges are sufficient for Phase 1. Never uses
 * Database::migrationPdo() — that connection belongs to api/_upgrade/ alone.
 *
 * Never touches PO/production/FG/DO/shipment/stock/invoice/payment/return/
 * sale tables — see Amor\Api\Import\Phase1Importer, which has no code path
 * that writes to any of them.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Import\Phase1Importer;
use Amor\Api\Repositories\StoreRepository;

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

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

if (Auth::currentUserId() === null) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Login diperlukan</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:2rem auto;">'
        . '<h1>Login diperlukan</h1><p>Buka <a href="../_admin-login/">../_admin-login/</a> dan login sebagai ADMIN dulu, '
        . 'lalu buka ulang halaman ini di browser/tab yang sama.</p></body></html>';
    exit;
}

try {
    Auth::requireRole('ADMIN');
} catch (ApiException $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Akses ditolak</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:2rem auto;">'
        . '<h1>Akses ditolak</h1><p>Akun Anda login, tapi bukan ADMIN.</p></body></html>';
    exit;
}

$csrfToken = (string) $_SESSION['csrf_token'];
$userId = Auth::currentUserId();
$resolvedByLabel = (string) $_SESSION['username'];
$pdo = Database::pdo(); // runtime (DML-only) connection — see file header
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
        Audit::write($pdo, null, $userId, 'phase1.divisions.import', 'division', 'bulk', 'ok', null, null, $result);
        $actionResult = ['ok' => true, 'title' => 'Divisi berhasil diimpor', 'message' => "Baru: {$result['created']}, sudah ada sebelumnya: {$result['alreadyExisted']}."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Impor divisi gagal', 'message' => $e->getMessage()];
    }
}

if ($action === 'import_products' && ($_POST['confirm'] ?? '') === '1') {
    try {
        $result = $importer->importSafeProducts();
        Audit::write($pdo, null, $userId, 'phase1.products.import', 'product', 'bulk', 'ok', null, null, $result);
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
        Audit::write($pdo, null, $userId, 'phase1.store_aliases.import', 'store', 'bulk', 'ok', null, null, $result);
        $actionResult = ['ok' => true, 'title' => 'Alias toko sumber (CKLE/CIKOLE) berhasil diimpor', 'message' =>
            "Toko baru: {$result['storesCreated']}, alias baru: {$result['aliasesCreated']}, "
            . "alias bentrok (dilewati): {$result['aliasesSkippedConflict']}."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Impor alias toko gagal', 'message' => $e->getMessage()];
    }
}

if ($action === 'confirm_store_candidate') {
    $original = (string) ($_POST['originalName'] ?? '');
    $final = trim((string) ($_POST['finalName'] ?? $original));
    try {
        $result = $importer->confirmStoreCandidate($original, $final, $resolvedByLabel);
        Audit::write($pdo, null, $userId, 'phase1.store_candidate.confirm', 'store', (string) $result['storeId'], 'ok', null, null, ['original' => $original, 'final' => $final]);
        $actionResult = ['ok' => true, 'title' => 'Toko dikonfirmasi', 'message' => "'{$result['canonicalName']}' sekarang toko resmi (store_id={$result['storeId']})."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Konfirmasi toko gagal', 'message' => $e->getMessage()];
    }
}

if ($action === 'skip_store_candidate') {
    $name = (string) ($_POST['originalName'] ?? '');
    try {
        $importer->skipStoreCandidate($name, 'dilewati oleh admin lewat wizard', $resolvedByLabel);
        Audit::write($pdo, null, $userId, 'phase1.store_candidate.skip', 'store_candidate', $name, 'ok');
        $actionResult = ['ok' => true, 'title' => 'Kandidat dilewati', 'message' => "'{$name}' dilewati untuk saat ini — tidak dibuat sebagai toko."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal melewati kandidat', 'message' => $e->getMessage()];
    }
}

if ($action === 'add_custom_store') {
    $name = trim((string) ($_POST['canonicalName'] ?? ''));
    $channel = trim((string) ($_POST['channel'] ?? ''));
    $channel = in_array($channel, ['ownership', 'franchise'], true) ? $channel : null;
    $aliasesRaw = trim((string) ($_POST['aliases'] ?? ''));

    try {
        if ($name === '') {
            throw new \InvalidArgumentException('Nama toko wajib diisi.');
        }
        $repo = new StoreRepository();
        $store = $repo->create($pdo, $name, $channel);
        Audit::write($pdo, null, $userId, 'phase1.store.manual_create', 'store', (string) $store['store_id'], 'ok', null, 1, $store);

        $aliasCount = 0;
        if ($aliasesRaw !== '') {
            foreach (array_filter(array_map('trim', explode(',', $aliasesRaw))) as $aliasName) {
                // factory_hint is ENUM('karangtengah','cibadak') in the schema — a manually
                // typed alias has no known factory, so this must stay NULL, never a free-text
                // guess like 'manual' (which strict SQL mode rejects outright).
                $repo->addAlias($pdo, (int) $store['store_id'], $aliasName, null);
                $aliasCount++;
            }
        }
        $actionResult = ['ok' => true, 'title' => 'Toko manual berhasil dibuat', 'message' => "'{$name}' dibuat (store_id={$store['store_id']}), {$aliasCount} alias ditambahkan."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal membuat toko manual', 'message' => $e->getMessage()];
    }
}

if ($action === 'disable') {
    @file_put_contents($markerFile, "disabled at " . gmdate('c') . " by " . $resolvedByLabel . "\n");
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
$storeAliasGroupPreview = $importer->previewStoreAliasGroups();
$storeCandidates = $importer->previewStoreCandidates();
$completion = $importer->completionStatus();

$divisionsNew = count(array_filter($divisionPreview, fn($d) => $d['state'] === 'new'));
$productTotal = count($productPreview['safe']) + count($productPreview['review']) + count($productPreview['conflict']) + $productPreview['alreadyMapped'];

$candidateCounts = ['already_exists' => 0, 'confirmed' => 0, 'skipped' => 0, 'pending' => 0];
foreach ($storeCandidates as $c) {
    $candidateCounts[$c['status']]++;
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>Amor Factory — Import Master (Phase 1)</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem;color:#1a1a1a;line-height:1.5;}
h1{font-size:1.4rem;} h2{font-size:1.1rem;margin-top:2rem;border-bottom:2px solid #eee;padding-bottom:.3rem;}
.box{border:1px solid #ddd;border-radius:8px;padding:1rem;margin:1rem 0;}
.warn{background:#fff3cd;border:1px solid #f0c36d;padding:.75rem;border-radius:6px;}
.result-ok{background:#e6ffe6;border:1px solid #8c8;padding:.75rem;border-radius:6px;}
.result-error{background:#ffe6e6;border:1px solid #e99;padding:.75rem;border-radius:6px;}
.gate-complete{background:#080;color:#fff;padding:1rem;border-radius:8px;font-weight:bold;font-size:1.2em;text-align:center;}
.gate-incomplete{background:#e90;color:#fff;padding:1rem;border-radius:8px;font-weight:bold;font-size:1.1em;text-align:center;}
button{padding:.4rem 1rem;background:#0a5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:bold;}
button.skip{background:#999;} button.danger{background:#b00;}
input[type=text]{padding:.3rem;}
table{border-collapse:collapse;width:100%;margin:.5rem 0;}
td,th{text-align:left;padding:.3rem .6rem;border-bottom:1px solid #eee;font-size:.9em;vertical-align:middle;}
.badge{display:inline-block;padding:1px 8px;border-radius:10px;font-size:.8em;font-weight:bold;color:#fff;}
.b-safe{background:#080;} .b-review{background:#e90;} .b-conflict{background:#b00;} .b-mapped{background:#666;}
.b-pending{background:#06c;} .b-skipped{background:#999;}
form.inline{display:inline;}
</style>
</head>
<body>

<h1>Amor Factory — Import Master &amp; Identity (Phase 1 Fast-Track)</h1>
<p>Login sebagai: <strong><?= esc($resolvedByLabel) ?></strong> &middot; <a href="../_admin-login/">logout</a>.
Menggunakan koneksi database <strong>runtime</strong> (bukan migrasi) untuk semua operasi di halaman ini.
Tidak ada data PO/produksi/FG/DO/pengiriman/stok/invoice/pembayaran/retur/penjualan yang disentuh.</p>

<?php if ($completion['productMasterComplete'] && $completion['storeMasterComplete']): ?>
<div class="gate-complete">PHASE 1 COMPLETE</div>
<?php elseif ($completion['productMasterComplete']): ?>
<div class="gate-incomplete">PRODUCT MASTER COMPLETE — STORE MASTER INCOMPLETE
  (<?= $completion['pendingStoreCandidates'] ?> kandidat toko belum direview)</div>
<?php else: ?>
<div class="gate-incomplete">BELUM SELESAI — lihat status per bagian di bawah</div>
<?php endif; ?>

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
    <tr<?= $d['name'] === 'Cookies' ? ' style="background:#fffbe6;"' : '' ?>>
      <td><?= esc($d['name']) ?><?= $d['name'] === 'Cookies' ? ' <span class="badge b-review">BARU DARI SOURCE</span>' : '' ?></td>
      <td><?= esc($d['factory']) ?></td>
      <td><?= $d['is_verification'] ? 'Ya' : '-' ?></td>
      <td><span class="badge <?= $d['state'] === 'new' ? 'b-safe' : 'b-mapped' ?>"><?= $d['state'] === 'new' ? 'BARU' : 'SUDAH ADA' ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p style="color:#666;"><strong>Cookies</strong> ditemukan langsung dari source code aplikasi (bukan dari daftar
  awal permintaan) — ditampilkan di sini agar terlihat jelas sebelum diimpor, bukan disembunyikan.</p>
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
  <p>Products to create: <strong><?= $productTotal ?></strong></p>
  <table>
    <tr><td><span class="badge b-safe">SAFE</span></td><td><?= count($productPreview['safe']) ?></td></tr>
    <tr><td><span class="badge b-review">REVIEW</span></td><td><?= count($productPreview['review']) ?></td></tr>
    <tr><td><span class="badge b-conflict">CONFLICT</span></td><td><?= count($productPreview['conflict']) ?></td></tr>
    <tr><td><span class="badge b-mapped">SUDAH DIPETAKAN</span></td><td><?= $productPreview['alreadyMapped'] ?></td></tr>
  </table>
  <div class="warn"><p><strong>HPP belum dimigrasikan.</strong> Katalog bawaan hanya berisi harga jual, bukan harga
  pokok produksi — semua produk yang diimpor di sini punya <code>hpp = 0</code>. Angka 0 ini BUKAN data biaya asli
  dan tidak boleh dibaca seolah-olah itu HPP sungguhan.</p></div>
  <?php if (count($productPreview['review']) > 0 || count($productPreview['conflict']) > 0): ?>
  <p><em>Baris REVIEW/CONFLICT tidak pernah diimpor otomatis — selesaikan lewat
  <code>GET /api/admin/migration/products?status=unresolved</code>.</em></p>
  <?php endif; ?>
  <?php if (count($productPreview['safe']) > 0): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="import_products">
    <label><input type="checkbox" name="confirm" value="1" required> Saya konfirmasi <?= count($productPreview['safe']) ?> produk SAFE di atas untuk diimpor sekarang.</label>
    <button type="submit">Import Produk (SAFE)</button>
  </form>
  <?php else: ?>
  <p>Tidak ada baris SAFE yang menunggu.</p>
  <?php endif; ?>
</div>

<h2>3. Toko — Sumber Terkonfirmasi (dari source code)</h2>
<div class="box">
  <div class="warn"><p><strong>Store master source incomplete.</strong> Hanya SATU toko yang benar-benar
  ditemukan tertulis di source code frontend (<code>bootstrapTokoCanonicalDikenal()</code>). Jangan anggap
  Phase 1 toko selesai hanya karena bagian ini berhasil — lihat bagian 4 di bawah untuk daftar kandidat toko
  yang perlu direview satu per satu.</p></div>
  <?php foreach ($storeAliasGroupPreview as $g): ?>
    <p><strong><?= esc($g['canonical']) ?></strong> — <span class="badge <?= $g['storeState'] === 'new' ? 'b-safe' : 'b-mapped' ?>"><?= $g['storeState'] === 'new' ? 'TOKO BARU' : 'SUDAH ADA' ?></span></p>
    <table><tr><th>Alias</th><th>Status</th></tr>
      <?php foreach ($g['aliases'] as $a): ?>
      <tr><td><?= esc($a['alias']) ?></td><td><span class="badge <?= $a['state'] === 'new' ? 'b-safe' : ($a['state'] === 'conflict' ? 'b-conflict' : 'b-mapped') ?>"><?= esc(strtoupper($a['state'])) ?></span></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="import_stores">
    <label><input type="checkbox" name="confirm" value="1" required> Saya ingin memasang toko &amp; alias sumber di atas sekarang.</label>
    <button type="submit">Import Toko Sumber</button>
  </form>
</div>

<h2>4. Toko — Kandidat Review (dari konfirmasi operator, BUKAN dari source code)</h2>
<div class="box">
  <p>Terkonfirmasi: <?= $candidateCounts['confirmed'] + $candidateCounts['already_exists'] ?> &middot;
  Dilewati: <?= $candidateCounts['skipped'] ?> &middot; <strong>Belum direview: <?= $candidateCounts['pending'] ?></strong></p>
  <table>
    <tr><th>Nama Kandidat</th><th>Status</th><th>Catatan</th><th>Aksi</th></tr>
    <?php foreach ($storeCandidates as $c): ?>
    <tr>
      <td><?= esc($c['canonicalName']) ?><?= $c['pendingAliases'] ? ' <span style="color:#666;">(alias: ' . esc(implode(', ', $c['pendingAliases'])) . ')</span>' : '' ?></td>
      <td>
        <?php if ($c['status'] === 'already_exists'): ?><span class="badge b-mapped">SUDAH ADA (store_id=<?= $c['storeId'] ?>)</span>
        <?php elseif ($c['status'] === 'confirmed'): ?><span class="badge b-safe">CONFIRMED (store_id=<?= $c['storeId'] ?>)</span>
        <?php elseif ($c['status'] === 'skipped'): ?><span class="badge b-skipped">SKIPPED</span>
        <?php else: ?><span class="badge b-pending">PENDING</span>
        <?php endif; ?>
      </td>
      <td style="color:#666;font-size:.85em;"><?= $c['note'] ? esc($c['note']) : '' ?></td>
      <td>
        <?php if ($c['status'] === 'pending'): ?>
          <form class="inline" method="post" style="display:flex;gap:.3rem;align-items:center;">
            <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
            <input type="hidden" name="action" value="confirm_store_candidate">
            <input type="hidden" name="originalName" value="<?= esc($c['canonicalName']) ?>">
            <input type="text" name="finalName" value="<?= esc($c['canonicalName']) ?>" size="20">
            <button type="submit">CONFIRM</button>
          </form>
          <form class="inline" method="post">
            <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
            <input type="hidden" name="action" value="skip_store_candidate">
            <input type="hidden" name="originalName" value="<?= esc($c['canonicalName']) ?>">
            <button type="submit" class="skip">SKIP</button>
          </form>
        <?php else: ?>
          —
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p style="color:#666;">Daftar ini berasal dari konteks operasional yang Anda berikan langsung, BUKAN dari
  source code — setiap baris butuh CONFIRM eksplisit sebelum menjadi toko resmi. "Bakery Cikole" otomatis
  dikenali sebagai toko yang sama dengan "BAKERY CIKOLE" (bagian 3) dan tidak akan dobel. Alias SDRM hanya
  akan aktif pada saat "Bakery Sudirman" di-CONFIRM.</p>
</div>

<h2>5. Tambah Toko Manual</h2>
<div class="box">
  <p>Untuk toko yang tidak ada di daftar mana pun di atas.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="add_custom_store">
    <p><label>Nama Toko (Canonical) <input type="text" name="canonicalName" required></label></p>
    <p><label>Channel / Tipe Toko (opsional — kosongkan jika belum tahu)
      <select name="channel"><option value="">(belum diketahui)</option><option value="ownership">Ownership</option><option value="franchise">Franchise</option></select>
    </label></p>
    <p><label>Alias (opsional, pisahkan koma) <input type="text" name="aliases" placeholder="mis. ALIAS1, ALIAS2"></label></p>
    <button type="submit">Tambah Toko</button>
  </form>
</div>

<h2>6. Selesai</h2>
<div class="box">
  <p>Status: Produk <?= $completion['productMasterComplete'] ? 'LENGKAP' : 'BELUM LENGKAP' ?>,
  Toko <?= $completion['storeMasterComplete'] ? 'LENGKAP' : 'BELUM LENGKAP (' . $completion['pendingStoreCandidates'] . ' pending)' ?>.</p>
  <p>Setelah semuanya lengkap, gunakan <code>GET /api/admin/migration/products?status=unresolved</code> dan
  <code>GET /api/admin/migration/stores?status=unresolved</code> untuk memastikan tidak ada sisa.</p>
  <form method="post" onsubmit="return confirm('Nonaktifkan halaman import ini sekarang?');">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="disable">
    <button type="submit" class="danger">Nonaktifkan Import Wizard</button>
  </form>
</div>

</body>
</html>
