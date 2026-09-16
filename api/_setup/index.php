<?php

declare(strict_types=1);

/**
 * Amor Factory — single-page cPanel setup wizard (Phase 0.5 easy-install
 * package). Replaces the earlier three separate migrate.php/seed.php/
 * create_admin.php fallback scripts with one guided page, because a
 * non-technical operator should never have to guess what order to run
 * three unlabeled files in.
 *
 * TEMPORARY. Delete this whole _setup/ directory (or at minimum click
 * "Selesai & Nonaktifkan" at the end) once setup is done — see
 * dist/README-FIRST-CPANEL.md.
 *
 * Every mutating action (schema install, seed, create admin, disable) is a
 * same-page POST guarded by SetupGuard::authorize() (SETUP_TOKEN + non-
 * production APP_ENV + EXPECTED_DB_NAME match — this token doubles as this
 * tool's CSRF protection, since the page has no login session of its own)
 * and requires an explicit confirmation checkbox. Nothing runs on page
 * load except read-only status checks.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\SetupGuard;
use Amor\Api\Setup\AdminCreator;
use Amor\Api\Setup\MigrationRunner;
use Amor\Api\Setup\Seeder;

$markerFile = __DIR__ . '/.disabled';
SetupGuard::requireEnabled($markerFile);

// Config may not be filled in correctly yet — that's an expected first-visit
// state, not a crash. Show a friendly instruction page instead of a 500.
try {
    Config::load();
} catch (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    $msg = htmlspecialchars($e->getMessage());
    echo <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Amor Factory — Setup</title>
<style>body{font-family:sans-serif;max-width:640px;margin:2rem auto;color:#222;}
code{background:#f4f4f4;padding:2px 6px;border-radius:4px;}</style></head>
<body>
<h1>Konfigurasi belum lengkap</h1>
<p>Halaman setup belum bisa jalan karena konfigurasi belum diisi dengan benar:</p>
<p><code>{$msg}</code></p>
<p>Buka <code>app/config/config.example.php</code>, salin isinya ke file baru bernama
<code>app/config/config.php</code> di folder yang sama, lalu isi nilai yang diperlukan
(terutama <code>DB_PASS</code> dan <code>SETUP_TOKEN</code>). Setelah itu muat ulang
halaman ini. Lihat <strong>dist/README-FIRST-CPANEL.md</strong> untuk panduan langkah
demi langkah.</p>
</body></html>
HTML;
    exit;
}

SetupGuard::authorize();

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$dbName = (string) Config::get('DB_NAME');
$expectedDbName = (string) Config::get('EXPECTED_DB_NAME', '');
$appEnv = (string) Config::get('APP_ENV');

// ---------------------------------------------------------------------
// Read-only checks (safe to run on every page load, no side effects)
// ---------------------------------------------------------------------

$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$pdoMysqlOk = extension_loaded('pdo_mysql');

$pdo = null;
$dbConnected = false;
$dbVersion = null;
$connectError = null;
if ($pdoMysqlOk) {
    try {
        $pdo = Database::pdo();
        $dbVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $dbConnected = true;
    } catch (\Throwable $e) {
        // Never show the raw exception (may include DSN fragments) — a generic
        // message is enough for the operator to know something is wrong with
        // config/config.php's DB_* values.
        $connectError = 'Tidak bisa konek ke database. Periksa DB_HOST/DB_NAME/DB_USER/DB_PASS di app/config/config.php.';
    }
}

$dbNameMatches = $dbConnected && $expectedDbName !== '' && $expectedDbName === $dbName;

$businessTableCount = 0;
$migrationApplied = false;
$factoryCount = 0;
$roleCount = 0;
$syntheticStoreCount = 0;
$adminCount = 0;

if ($dbConnected) {
    try {
        $runner = new MigrationRunner($pdo, $dbName);
        // ensureBookkeepingTable() only CREATE TABLE IF NOT EXISTS — harmless, read-safe.
        $runner->ensureBookkeepingTable();
        $businessTableCount = $runner->businessTableCount();
        $migrationApplied = in_array('0001_schema_v1.php', $runner->appliedMigrations(), true);

        if ($businessTableCount >= 45) {
            $factoryCount = (int) $pdo->query('SELECT COUNT(*) FROM factory')->fetchColumn();
            $roleCount = (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM store WHERE canonical_name = 'NON-OUTLET / PERORANGAN'");
            $stmt->execute();
            $syntheticStoreCount = (int) $stmt->fetchColumn();
            $adminCount = (int) $pdo->query(
                "SELECT COUNT(*) FROM users u INNER JOIN user_roles ur ON ur.user_id = u.user_id
                 INNER JOIN roles r ON r.role_id = ur.role_id WHERE r.code = 'ADMIN' AND u.active = 1"
            )->fetchColumn();
        }
    } catch (\Throwable $e) {
        // Leave counts at 0 / migrationApplied=false — the status table below
        // will correctly show these steps as not yet done.
    }
}

$seedOk = $factoryCount === 2 && $roleCount === 7 && $syntheticStoreCount === 1;
$schemaOk = $businessTableCount === 45 && $migrationApplied;
$adminOk = $adminCount >= 1;
// $verificationOk is (re)computed just before rendering, below — not here — because
// the action handlers right below this point can change $schemaOk/$seedOk/$adminOk,
// and this must reflect their final values, not a snapshot from before any action ran.

// ---------------------------------------------------------------------
// Handle POST actions
// ---------------------------------------------------------------------

$actionResult = null; // ['ok' => bool, 'title' => string, 'message' => string]
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';

if ($action === 'migrate' && ($_POST['confirm'] ?? '') === '1') {
    try {
        if ($businessTableCount > 0 && !$migrationApplied) {
            throw new \RuntimeException(
                'Database sudah punya tabel tapi belum tercatat sebagai hasil instalasi ini. '
                . 'Berhenti demi keamanan — jangan ditimpa begitu saja. Hubungi developer.'
            );
        }
        $runner = new MigrationRunner($pdo, $dbName);
        $result = $runner->applyPending();
        $businessTableCount = $runner->businessTableCount();
        $migrationApplied = in_array('0001_schema_v1.php', $runner->appliedMigrations(), true);
        $schemaOk = $businessTableCount === 45 && $migrationApplied;
        $actionResult = [
            'ok' => true,
            'title' => 'Instalasi struktur database berhasil',
            'message' => $result['applied'] === []
                ? 'Struktur database sudah terpasang sebelumnya — tidak ada yang diubah.'
                : 'Berhasil membuat ' . $businessTableCount . ' tabel bisnis di database.',
        ];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Instalasi struktur database gagal', 'message' => $e->getMessage()];
    }
}

if ($action === 'seed' && ($_POST['confirm'] ?? '') === '1') {
    try {
        $result = (new Seeder($pdo))->run();
        $factoryCount = (int) $pdo->query('SELECT COUNT(*) FROM factory')->fetchColumn();
        $roleCount = (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM store WHERE canonical_name = 'NON-OUTLET / PERORANGAN'");
        $stmt->execute();
        $syntheticStoreCount = (int) $stmt->fetchColumn();
        $seedOk = $factoryCount === 2 && $roleCount === 7 && $syntheticStoreCount === 1;
        $actionResult = [
            'ok' => true,
            'title' => 'Data awal berhasil dipasang',
            'message' => "Pabrik: {$factoryCount}, Peran: {$roleCount}, Toko sintetis: {$syntheticStoreCount}.",
        ];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Pemasangan data awal gagal', 'message' => $e->getMessage()];
    }
}

if ($action === 'create_admin') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $fullName = trim((string) ($_POST['fullName'] ?? '')) ?: $username;
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['passwordConfirm'] ?? '');

    try {
        if ($username === '') {
            throw new \InvalidArgumentException('Username wajib diisi.');
        }
        if ($password !== $passwordConfirm) {
            throw new \InvalidArgumentException('Password dan konfirmasi password tidak sama.');
        }
        (new AdminCreator($pdo))->createOrReset($username, $fullName, $password);
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM users u INNER JOIN user_roles ur ON ur.user_id = u.user_id
             INNER JOIN roles r ON r.role_id = ur.role_id WHERE r.code = 'ADMIN' AND u.active = 1"
        );
        $stmt->execute();
        $adminCount = (int) $stmt->fetchColumn();
        $adminOk = $adminCount >= 1;
        $actionResult = [
            'ok' => true,
            'title' => 'Admin berhasil dibuat',
            'message' => "Pengguna '" . htmlspecialchars($username) . "' sekarang menjadi ADMIN. Password TIDAK ditampilkan atau disimpan di log.",
        ];
        // Password variables deliberately not referenced again below.
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Pembuatan admin gagal', 'message' => $e->getMessage()];
    }
}

if ($action === 'disable') {
    @file_put_contents($markerFile, "disabled at " . gmdate('c') . "\n");
    $disabledJustNow = is_file($markerFile);
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    if ($disabledJustNow) {
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Setup dinonaktifkan</title>'
            . '<style>body{font-family:sans-serif;max-width:640px;margin:2rem auto;}</style></head><body>'
            . '<h1 style="color:#080;">Setup sudah dinonaktifkan</h1>'
            . '<p>Halaman ini dan semua aksinya sekarang mengembalikan 404 Not Found.</p>'
            . '<p><strong>Langkah terakhir yang tetap disarankan:</strong> hapus seluruh folder '
            . '<code>api/_setup/</code> lewat File Manager cPanel, kapan pun sempat.</p>'
            . '</body></html>';
    } else {
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Gagal menonaktifkan</title></head><body>'
            . '<h1 style="color:#b00;">Tidak bisa menulis file penanda nonaktif</h1>'
            . '<p>Server tidak mengizinkan PHP menulis file di folder ini. Nonaktifkan secara manual: '
            . 'hapus seluruh folder <code>api/_setup/</code> lewat File Manager cPanel sekarang.</p>'
            . '</body></html>';
    }
    exit;
}

// ---------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------

function statusBadge(string $state): string
{
    $colors = ['BELUM' => '#999', 'READY' => '#06c', 'BERHASIL' => '#080', 'ERROR' => '#b00'];
    $color = $colors[$state] ?? '#999';
    return "<span style=\"display:inline-block;padding:2px 10px;border-radius:12px;background:{$color};color:#fff;font-size:.85em;font-weight:bold;\">{$state}</span>";
}

$verificationOk = $dbConnected && $dbNameMatches && $schemaOk && $seedOk && $adminOk;

$s1 = ($phpOk && $pdoMysqlOk && $dbConnected) ? 'BERHASIL' : 'ERROR';
$s2 = !$dbConnected ? 'BELUM' : ($dbNameMatches ? 'BERHASIL' : 'ERROR');
$s3 = $s2 !== 'BERHASIL' ? 'BELUM' : ($schemaOk ? 'BERHASIL' : 'READY');
$s4 = $s3 !== 'BERHASIL' ? 'BELUM' : ($seedOk ? 'BERHASIL' : 'READY');
$s5 = $s4 !== 'BERHASIL' ? 'BELUM' : ($adminOk ? 'BERHASIL' : 'READY');
$s6 = $s5 !== 'BERHASIL' ? 'BELUM' : ($verificationOk ? 'BERHASIL' : 'ERROR');
$s7 = $s6 !== 'BERHASIL' ? 'BELUM' : 'READY';

$tokenAttr = htmlspecialchars($token, ENT_QUOTES);

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>Amor Factory — Setup Database (Preproduksi)</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:760px;margin:2rem auto;padding:0 1rem;color:#1a1a1a;line-height:1.5;}
h1{font-size:1.4rem;} h2{font-size:1.1rem;margin-top:2rem;border-bottom:2px solid #eee;padding-bottom:.3rem;}
.step{border:1px solid #ddd;border-radius:8px;padding:1rem;margin:1rem 0;}
.step.done{border-color:#8c8;background:#f6fff6;}
.step.error{border-color:#e99;background:#fff6f6;}
code{background:#f4f4f4;padding:2px 6px;border-radius:4px;}
label{display:block;margin:.5rem 0;}
input[type=text],input[type=password]{width:100%;max-width:320px;padding:.4rem;box-sizing:border-box;}
button{padding:.5rem 1.2rem;background:#0a5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:bold;}
button.danger{background:#b00;}
button:disabled{background:#ccc;cursor:not-allowed;}
.warn{background:#fff3cd;border:1px solid #f0c36d;padding:.75rem;border-radius:6px;}
.result-ok{background:#e6ffe6;border:1px solid #8c8;padding:.75rem;border-radius:6px;}
.result-error{background:#ffe6e6;border:1px solid #e99;padding:.75rem;border-radius:6px;}
table{border-collapse:collapse;width:100%;}
td,th{text-align:left;padding:.3rem .6rem;border-bottom:1px solid #eee;}
</style>
</head>
<body>

<h1>Amor Factory — Setup Database (Preproduksi)</h1>
<p>Halaman ini HANYA untuk pemasangan awal. Domain: <code>factory.amorgroup.id</code>.
Database target: <code><?= htmlspecialchars($dbName) ?></code>. Lingkungan: <code><?= htmlspecialchars($appEnv) ?></code>.
Tidak ada aksi yang berjalan otomatis — setiap langkah butuh klik tombol + centang konfirmasi.</p>

<?php if ($actionResult !== null): ?>
<div class="<?= $actionResult['ok'] ? 'result-ok' : 'result-error' ?>">
  <strong><?= htmlspecialchars($actionResult['title']) ?></strong>
  <p><?= htmlspecialchars($actionResult['message']) ?></p>
</div>
<?php endif; ?>

<table>
<tr><th>#</th><th>Langkah</th><th>Status</th></tr>
<tr><td>1</td><td>Koneksi</td><td><?= statusBadge($s1) ?></td></tr>
<tr><td>2</td><td>Kompatibilitas Database</td><td><?= statusBadge($s2) ?></td></tr>
<tr><td>3</td><td>Instalasi Struktur Database</td><td><?= statusBadge($s3) ?></td></tr>
<tr><td>4</td><td>Data Awal</td><td><?= statusBadge($s4) ?></td></tr>
<tr><td>5</td><td>Buat Admin</td><td><?= statusBadge($s5) ?></td></tr>
<tr><td>6</td><td>Verifikasi</td><td><?= statusBadge($s6) ?></td></tr>
<tr><td>7</td><td>Selesai</td><td><?= statusBadge($s7) ?></td></tr>
</table>

<h2>1. Koneksi</h2>
<div class="step <?= $s1 === 'BERHASIL' ? 'done' : 'error' ?>">
  <p>PHP &ge; 8.1: <?= $phpOk ? 'OK (' . PHP_VERSION . ')' : 'GAGAL (' . PHP_VERSION . ')' ?></p>
  <p>Ekstensi PDO MySQL: <?= $pdoMysqlOk ? 'OK' : 'GAGAL — hubungi hosting untuk mengaktifkan pdo_mysql' ?></p>
  <p>Koneksi database: <?= $dbConnected ? 'TERHUBUNG' : 'GAGAL' ?></p>
  <?php if ($connectError): ?><p style="color:#b00;"><?= htmlspecialchars($connectError) ?></p><?php endif; ?>
</div>

<h2>2. Kompatibilitas Database</h2>
<div class="step <?= $s2 === 'BERHASIL' ? 'done' : ($s2 === 'ERROR' ? 'error' : '') ?>">
  <p>Versi MariaDB: <?= $dbVersion ? htmlspecialchars($dbVersion) : '(belum terhubung)' ?></p>
  <p>Nama database aktif: <code><?= htmlspecialchars($dbName) ?></code></p>
  <p>Nama database yang diharapkan: <code><?= htmlspecialchars($expectedDbName ?: '(belum diisi)') ?></code></p>
  <p>Cocok: <?= $dbNameMatches ? 'YA' : 'TIDAK' ?></p>
</div>

<h2>3. Instalasi Struktur Database</h2>
<div class="step <?= $s3 === 'BERHASIL' ? 'done' : '' ?>">
  <p>Tabel bisnis saat ini: <?= $businessTableCount ?> (target: 45)</p>
  <p>Tercatat sebagai terpasang: <?= $migrationApplied ? 'YA' : 'BELUM' ?></p>
  <?php if ($s3 === 'READY'): ?>
    <div class="warn">
      <p>Akan membuat struktur database (45 tabel bisnis + 1 tabel <code>schema_migrations</code>)
      di database <code><?= htmlspecialchars($dbName) ?></code>. Ini TIDAK menghapus apa pun —
      database saat ini kosong.</p>
    </div>
    <form method="post">
      <input type="hidden" name="token" value="<?= $tokenAttr ?>">
      <input type="hidden" name="action" value="migrate">
      <label><input type="checkbox" name="confirm" value="1" required> Saya mengerti dan ingin memasang struktur database sekarang.</label>
      <button type="submit">Install Database Schema</button>
    </form>
  <?php elseif ($s3 === 'BERHASIL'): ?>
    <p>Sudah terpasang. Menjalankan ulang langkah ini tidak akan membuat data ganda.</p>
  <?php else: ?>
    <p><em>Menunggu langkah sebelumnya selesai.</em></p>
  <?php endif; ?>
</div>

<h2>4. Data Awal</h2>
<div class="step <?= $s4 === 'BERHASIL' ? 'done' : '' ?>">
  <p>Pabrik: <?= $factoryCount ?>/2 &nbsp; Peran: <?= $roleCount ?>/7 &nbsp; Toko sintetis: <?= $syntheticStoreCount ?>/1</p>
  <?php if ($s4 === 'READY'): ?>
    <div class="warn"><p>Akan memasang data minimum: 2 pabrik (Karangtengah, Cibadak), 7 peran pengguna,
    dan 1 toko sintetis (NON-OUTLET / PERORANGAN). Tidak ada data produk/transaksi yang dipasang.</p></div>
    <form method="post">
      <input type="hidden" name="token" value="<?= $tokenAttr ?>">
      <input type="hidden" name="action" value="seed">
      <label><input type="checkbox" name="confirm" value="1" required> Saya ingin memasang data awal sekarang.</label>
      <button type="submit">Install Data Awal</button>
    </form>
  <?php elseif ($s4 === 'BERHASIL'): ?>
    <p>Sudah terpasang. Menjalankan ulang langkah ini tidak akan membuat data ganda.</p>
  <?php else: ?>
    <p><em>Menunggu langkah sebelumnya selesai.</em></p>
  <?php endif; ?>
</div>

<h2>5. Buat Admin</h2>
<div class="step <?= $s5 === 'BERHASIL' ? 'done' : '' ?>">
  <p>Akun ADMIN aktif saat ini: <?= $adminCount ?></p>
  <?php if ($s5 === 'READY'): ?>
    <form method="post">
      <input type="hidden" name="token" value="<?= $tokenAttr ?>">
      <input type="hidden" name="action" value="create_admin">
      <label>Username <input type="text" name="username" required autocomplete="off"></label>
      <label>Nama Lengkap <input type="text" name="fullName" autocomplete="off"></label>
      <label>Password (minimal 10 karakter) <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
      <label>Konfirmasi Password <input type="password" name="passwordConfirm" required minlength="10" autocomplete="new-password"></label>
      <p><em>Password tidak akan ditampilkan lagi setelah ini dan tidak dicatat di log manapun.</em></p>
      <button type="submit">Buat Admin</button>
    </form>
  <?php elseif ($s5 === 'BERHASIL'): ?>
    <p>Sudah ada admin. Mengisi form ini lagi dengan username yang sama akan RESET password admin tersebut
    (berguna kalau lupa password) — bukan membuat duplikat.</p>
    <form method="post">
      <input type="hidden" name="token" value="<?= $tokenAttr ?>">
      <input type="hidden" name="action" value="create_admin">
      <label>Username <input type="text" name="username" required autocomplete="off"></label>
      <label>Nama Lengkap <input type="text" name="fullName" autocomplete="off"></label>
      <label>Password baru (minimal 10 karakter) <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
      <label>Konfirmasi Password <input type="password" name="passwordConfirm" required minlength="10" autocomplete="new-password"></label>
      <button type="submit">Buat / Reset Admin</button>
    </form>
  <?php else: ?>
    <p><em>Menunggu langkah sebelumnya selesai.</em></p>
  <?php endif; ?>
</div>

<h2>6. Verifikasi</h2>
<div class="step <?= $s6 === 'BERHASIL' ? 'done' : ($s6 === 'ERROR' ? 'error' : '') ?>">
  <?php if ($s6 !== 'BELUM'): ?>
    <table>
      <tr><td>Database Connected</td><td><?= $dbConnected ? 'YA' : 'TIDAK' ?></td></tr>
      <tr><td>MariaDB Version</td><td><?= htmlspecialchars((string) $dbVersion) ?></td></tr>
      <tr><td>Schema Installed</td><td><?= $schemaOk ? 'YA' : 'TIDAK' ?></td></tr>
      <tr><td>Business Tables</td><td><?= $businessTableCount ?> / 45</td></tr>
      <tr><td>Migration Metadata</td><td><?= $migrationApplied ? 'OK' : 'TIDAK ADA' ?></td></tr>
      <tr><td>Factories</td><td><?= $factoryCount ?> / 2</td></tr>
      <tr><td>Roles</td><td><?= $roleCount ?> / 7</td></tr>
      <tr><td>Synthetic Store</td><td><?= $syntheticStoreCount ?> / 1</td></tr>
      <tr><td>Admin</td><td><?= $adminOk ? 'CREATED' : 'BELUM' ?></td></tr>
      <tr><td>API Health</td><td><?= $dbConnected ? 'OK' : 'ERROR' ?></td></tr>
    </table>
  <?php else: ?>
    <p><em>Menunggu langkah sebelumnya selesai.</em></p>
  <?php endif; ?>
</div>

<h2>7. Selesai</h2>
<div class="step <?= $s7 !== 'BELUM' ? 'done' : '' ?>">
  <?php if ($s7 === 'READY'): ?>
    <p style="font-size:1.2em;font-weight:bold;color:#080;">SETUP COMPLETE</p>
    <p><strong>TINDAKAN WAJIB SELANJUTNYA (belum dilakukan otomatis oleh halaman ini):</strong></p>
    <ol>
      <li>Buat runtime DB user terpisah di cPanel (lihat dist/README-FIRST-CPANEL.md langkah 9)</li>
      <li>Ganti DB_USER/DB_PASS di <code>app/config/config.php</code> ke runtime user tersebut</li>
      <li>Hapus atau nonaktifkan folder setup ini</li>
      <li>Jalankan smoke test (lihat checklist CP-01..CP-20 di api/DEPLOY-CPANEL-PREPROD.md)</li>
      <li>Buat backup database baseline</li>
    </ol>
    <p><strong>Halaman ini BUKAN pernyataan "siap produksi."</strong> Ini hanya menandakan struktur
    database + data awal + 1 admin sudah berhasil dipasang.</p>
    <form method="post" onsubmit="return confirm('Nonaktifkan halaman setup ini sekarang? Anda tetap bisa menghapus foldernya manual kapan saja.');">
      <input type="hidden" name="token" value="<?= $tokenAttr ?>">
      <input type="hidden" name="action" value="disable">
      <button type="submit" class="danger">Selesai &amp; Nonaktifkan Setup</button>
    </form>
  <?php else: ?>
    <p><em>Menunggu langkah sebelumnya selesai.</em></p>
  <?php endif; ?>
</div>

<p style="color:#666;margin-top:2rem;">Halaman ini tidak pernah menampilkan password database.
Tidak ada tombol reset/hapus database di halaman ini — dan tidak akan pernah ada.</p>

</body>
</html>
