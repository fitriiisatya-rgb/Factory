<?php

declare(strict_types=1);

/**
 * Amor Factory — Phase 2 fast-track PO import wizard.
 *
 * TEMPORARY, like api/_import-master/ was for Phase 1. Delete this whole
 * directory once Phase 2's PO module is reviewed and accepted.
 *
 * Requires a real ADMIN login session — see api/_admin-login/. CSRF is the
 * session's own csrf_token, carried as a hidden form field (plain HTML
 * form wizard, not fetch()-based — usable from an iPad/Safari with no
 * JavaScript file-upload trickery).
 *
 * Uses ONLY the normal runtime connection (Amor\Api\Database::pdo(), the
 * DML-only DB_USER) — PO import is ordinary INSERT/UPDATE/SELECT against
 * po_batch/po_item/po_store_item (all part of the original 0001 schema)
 * plus the existing migration_product_map/migration_store_map review queue
 * for anything unresolved. Never uses Database::migrationPdo() — that
 * connection belongs to api/_upgrade/ alone.
 *
 * The uploaded file is staged in the PHP session (base64) between the
 * Preview and Confirm Import steps — never written to a world-reachable
 * path, and self-expiring with the session (SESSION_LIFETIME_SECONDS).
 * No SQL input anywhere on this page, no database reset/truncate, no hard
 * delete — only additive PO writes plus alias creation via the existing
 * ProductRepository::addAlias() / StoreRepository::addAlias() (the same
 * methods POST /api/products/{id}/aliases and POST /api/stores/{id}/aliases
 * use), never a new resolution mechanism.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Import\PoImporter;
use Amor\Api\Import\PoProductCodeConflictException;
use Amor\Api\Import\PoRepository;
use Amor\Api\Import\PoResolver;
use Amor\Api\Repositories\ProductRepository;
use Amor\Api\Repositories\StoreRepository;

$markerFile = __DIR__ . '/.disabled';
if (is_file($markerFile) || Config::get('IMPORT_PO_ENABLED', true) === false) {
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
function fmtNum(float $n): string { return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ','); }

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
$importer = new PoImporter($pdo);
$productRepo = new ProductRepository();
$storeRepo = new StoreRepository();
$poResolver = new PoResolver($pdo);

/** @return string[] raw product keys (PoImporter::productRawKey) the admin chose to skip for this staged file */
function stagedSkipKeys(): array
{
    return array_keys($_SESSION['po_staged']['skippedProductKeys'] ?? []);
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';
$postedCsrf = (string) ($_POST['csrf'] ?? '');
$actionResult = null;

if ($action !== '' && !hash_equals($csrfToken, $postedCsrf)) {
    $actionResult = ['ok' => false, 'title' => 'CSRF token tidak cocok', 'message' => 'Muat ulang halaman dan coba lagi.'];
    $action = '';
}

// ---------------------------------------------------------------------
// Step 1 -> 2: upload + preview (staged in session, never written to a
// world-reachable path; self-expires with the PHP session).
// ---------------------------------------------------------------------
if ($action === 'preview_upload') {
    $tanggal = (string) ($_POST['tanggal'] ?? '');
    $uploadType = (string) ($_POST['uploadType'] ?? '');
    $d = \DateTime::createFromFormat('Y-m-d', $tanggal);
    if ($d === false || $d->format('Y-m-d') !== $tanggal) {
        $actionResult = ['ok' => false, 'title' => 'Tanggal tidak valid', 'message' => 'Isi tanggal berlaku PO dengan benar.'];
    } elseif (!in_array($uploadType, ['initial', 'revision'], true)) {
        $actionResult = ['ok' => false, 'title' => 'Tipe upload belum dipilih', 'message' => 'Pilih PO Awal atau PO Tambahan/Revisi.'];
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $reasons = [
            UPLOAD_ERR_INI_SIZE => 'File terlalu besar untuk batas upload_max_filesize server ini.',
            UPLOAD_ERR_FORM_SIZE => 'File terlalu besar.',
            UPLOAD_ERR_NO_FILE => 'Belum ada file yang dipilih.',
        ];
        $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $actionResult = ['ok' => false, 'title' => 'Upload gagal', 'message' => $reasons[$code] ?? 'Upload file gagal.'];
    } else {
        $fileName = (string) $_FILES['file']['name'];
        $content = file_get_contents($_FILES['file']['tmp_name']);
        try {
            $tmpPath = tempnam(sys_get_temp_dir(), 'po_wizard_');
            file_put_contents($tmpPath, $content);
            $rows = $importer->readRows($tmpPath, $fileName);
            @unlink($tmpPath);

            $hash = hash('sha256', $content);
            $plan = $importer->preview($rows, $tanggal, $uploadType, $hash, $fileName);

            $_SESSION['po_staged'] = [
                'tanggal' => $tanggal, 'uploadType' => $uploadType, 'fileName' => $fileName,
                'hash' => $hash, 'contentBase64' => base64_encode($content),
                'skippedProductKeys' => [], 'revisionReviewPending' => false,
            ];
            $actionResult = ['ok' => true, 'title' => 'File berhasil dibaca', 'message' => "Terbaca {$plan['parsedRowCount']} baris produk."];
        } catch (\Throwable $e) {
            $actionResult = ['ok' => false, 'title' => 'Gagal membaca file', 'message' => $e->getMessage()];
        }
    }
}

if ($action === 'reset_staged') {
    unset($_SESSION['po_staged']);
    $actionResult = ['ok' => true, 'title' => 'Dibatalkan', 'message' => 'Silakan upload file lain.'];
}

if ($action === 'resolve_product_alias' && isset($_SESSION['po_staged'])) {
    $rawName = (string) ($_POST['rawName'] ?? '');
    $targetName = trim((string) ($_POST['targetProductName'] ?? ''));
    try {
        if ($targetName === '') {
            throw new \InvalidArgumentException('Nama produk tujuan wajib diisi.');
        }
        $stmt = $pdo->prepare('SELECT product_id FROM product WHERE UPPER(name) = UPPER(?)');
        $stmt->execute([$targetName]);
        $productId = $stmt->fetchColumn();
        if ($productId === false) {
            throw new \InvalidArgumentException("Tidak ada produk bernama persis '{$targetName}' — cek ejaannya (harus sama persis dengan yang sudah ada di Master Produk).");
        }
        $alias = $productRepo->addAlias($pdo, (int) $productId, $rawName, 'po_import_wizard');
        Audit::write($pdo, null, $userId, 'po.product_alias.create', 'product_alias', (string) $alias['productAliasId'], 'ok', null, 1, $alias);
        $actionResult = ['ok' => true, 'title' => 'Alias produk dibuat', 'message' => "'{$rawName}' sekarang mengarah ke produk '{$targetName}'. Preview akan diperbarui."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal membuat alias produk', 'message' => $e->getMessage()];
    }
}

if ($action === 'resolve_store_alias' && isset($_SESSION['po_staged'])) {
    $rawName = (string) ($_POST['rawName'] ?? '');
    $targetName = trim((string) ($_POST['targetStoreName'] ?? ''));
    try {
        if ($targetName === '') {
            throw new \InvalidArgumentException('Nama toko tujuan wajib diisi.');
        }
        $stmt = $pdo->prepare('SELECT store_id FROM store WHERE UPPER(canonical_name) = UPPER(?)');
        $stmt->execute([$targetName]);
        $storeId = $stmt->fetchColumn();
        if ($storeId === false) {
            throw new \InvalidArgumentException("Tidak ada toko bernama persis '{$targetName}' — cek ejaannya (harus sama persis dengan yang sudah ada di Master Toko).");
        }
        $alias = $storeRepo->addAlias($pdo, (int) $storeId, $rawName, null);
        Audit::write($pdo, null, $userId, 'po.store_alias.create', 'store_alias', (string) $alias['storeAliasId'], 'ok', null, 1, $alias);
        $actionResult = ['ok' => true, 'title' => 'Alias toko dibuat', 'message' => "'{$rawName}' sekarang mengarah ke toko '{$targetName}'. Preview akan diperbarui."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal membuat alias toko', 'message' => $e->getMessage()];
    }
}

if ($action === 'skip_unresolved_product' && isset($_SESSION['po_staged'])) {
    $rawCode = (string) ($_POST['rawCode'] ?? '');
    $rawName = (string) ($_POST['rawName'] ?? '');
    $key = PoImporter::productRawKey($rawCode, $rawName);
    $_SESSION['po_staged']['skippedProductKeys'][$key] = ['rawCode' => $rawCode, 'rawName' => $rawName];
    $actionResult = ['ok' => true, 'title' => 'Produk dilewati', 'message' =>
        "'{$rawName}' (kode '{$rawCode}') dikecualikan dari impor ini — baris lain tidak terpengaruh. Preview akan diperbarui."];
}

if ($action === 'unskip_product' && isset($_SESSION['po_staged'])) {
    $key = (string) ($_POST['key'] ?? '');
    unset($_SESSION['po_staged']['skippedProductKeys'][$key]);
    $actionResult = ['ok' => true, 'title' => 'Lewati dibatalkan', 'message' => 'Produk ini akan direview lagi seperti biasa.'];
}

// New Product Review — "TAMBAH KE MASTER & LANJUTKAN PO". A controlled,
// self-contained transaction (product + product_legacy_code +
// product_alias + audit_log) — see PoResolver::createProductFromUnresolved()
// for exactly what it validates and writes. Never fuzzy, never silent.
if ($action === 'create_new_product_from_import' && isset($_SESSION['po_staged'])) {
    $rawCode = (string) ($_POST['rawCode'] ?? '');
    $rawName = (string) ($_POST['rawName'] ?? '');
    $finalName = trim((string) ($_POST['finalName'] ?? ''));
    $kategori = trim((string) ($_POST['kategori'] ?? '')) ?: null;
    $divisionIdRaw = trim((string) ($_POST['divisionId'] ?? ''));
    $divisionId = $divisionIdRaw !== '' ? (int) $divisionIdRaw : null;
    $harga = (float) ($_POST['harga'] ?? 0);
    try {
        $staged = $_SESSION['po_staged'];
        $result = $poResolver->createProductFromUnresolved(
            $rawCode, $rawName, $finalName, $kategori, $divisionId, $harga,
            $userId, $staged['fileName'], $staged['hash']
        );
        $actionResult = ['ok' => true, 'title' => $result['created'] ? 'Produk baru dibuat' : 'Produk sudah ada (tidak dibuat ulang)', 'message' =>
            "'{$finalName}' sekarang produk_id={$result['productId']}. HPP belum tersedia dan disimpan sementara sebagai 0. Preview akan diperbarui."];
    } catch (PoProductCodeConflictException $e) {
        $actionResult = ['ok' => false, 'title' => 'PRODUCT_CODE_CONFLICT', 'message' => $e->getMessage()];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal membuat produk baru', 'message' => $e->getMessage()];
    }
}

// Small UX patch (does not touch parser/resolver/merge/schema/API contract):
// PO Tambahan/Revisi files can still carry PO Awal columns from the source
// sheet, so committing a revision gets one extra, explicit confirmation step
// — server-rendered (no JS dependency), so it works identically on iPad/
// Safari and is fully driveable over plain HTTP for tests. PO Awal imports
// are entirely unaffected — they still go straight to confirm_import.
if ($action === 'review_revision' && isset($_SESSION['po_staged']) && $_SESSION['po_staged']['uploadType'] === 'revision') {
    $_SESSION['po_staged']['revisionReviewPending'] = true;
}

if ($action === 'cancel_revision_review' && isset($_SESSION['po_staged'])) {
    $_SESSION['po_staged']['revisionReviewPending'] = false;
    $actionResult = ['ok' => true, 'title' => 'Dibatalkan', 'message' => 'Import PO Revisi dibatalkan — tidak ada yang diimpor. File masih tersimpan, silakan periksa lagi sebelum melanjutkan.'];
}

if ($action === 'confirm_import' && isset($_SESSION['po_staged'])) {
    $staged = $_SESSION['po_staged'];
    // Defense in depth: a revision import must have gone through the
    // review_revision confirmation step first — never weakens CSRF (still
    // checked above like every other action here), just adds a second,
    // independent gate specifically for the revision-carries-PO-Awal risk.
    if ($staged['uploadType'] === 'revision' && empty($staged['revisionReviewPending'])) {
        $actionResult = ['ok' => false, 'title' => 'Konfirmasi diperlukan', 'message' =>
            'Klik "Konfirmasi & Impor PO" lagi untuk menampilkan konfirmasi PO Revisi terlebih dahulu.'];
    } else {
        try {
            $content = base64_decode($staged['contentBase64'], true);
            $tmpPath = tempnam(sys_get_temp_dir(), 'po_wizard_');
            file_put_contents($tmpPath, $content);
            $rows = $importer->readRows($tmpPath, $staged['fileName']);
            @unlink($tmpPath);
            $skipKeys = array_keys($staged['skippedProductKeys'] ?? []);

            $result = Database::transaction(function ($txPdo) use ($rows, $staged, $userId, $skipKeys) {
                $txImporter = new PoImporter($txPdo);
                return $txImporter->import($rows, $staged['tanggal'], $staged['uploadType'], $staged['hash'], $staged['fileName'], $userId, $skipKeys);
            });

            if ($result['ok']) {
                unset($_SESSION['po_staged']);
                $d = $result['data'];
                $skippedNote = !empty($d['skippedProductRows']) ? ' (' . count($d['skippedProductRows']) . ' produk dilewati, tidak diimpor).' : '';
                $actionResult = ['ok' => true, 'title' => 'PO berhasil diimpor', 'message' =>
                    "Batch #{$d['poBatchId']} ({$d['tanggal']}, {$d['factory']}) — {$d['linesWritten']} baris ditulis, target total " . fmtNum($d['targetTotal']) . '.' . $skippedNote];
            } else {
                $actionResult = ['ok' => false, 'title' => 'Impor ditolak', 'message' => $result['message']];
            }
        } catch (\Throwable $e) {
            $actionResult = ['ok' => false, 'title' => 'Impor gagal', 'message' => $e->getMessage()];
        }
    }
}

if ($action === 'disable') {
    @file_put_contents($markerFile, 'disabled at ' . gmdate('c') . ' by ' . $resolvedByLabel . "\n");
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Import PO wizard dinonaktifkan</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:2rem auto;">'
        . '<h1 style="color:#080;">Sudah dinonaktifkan</h1><p>Halaman ini sekarang mengembalikan 404. '
        . 'Disarankan tetap menghapus folder <code>api/_import-po/</code> lewat File Manager kapan pun sempat.</p>'
        . '</body></html>';
    exit;
}

// ---------------------------------------------------------------------
// Re-preview the staged file (fresh resolution) whenever we have one —
// after upload, after an alias is created, or on a plain page reload.
// ---------------------------------------------------------------------
$plan = null;
$planError = null;
if (isset($_SESSION['po_staged'])) {
    $staged = $_SESSION['po_staged'];
    try {
        $content = base64_decode($staged['contentBase64'], true);
        $tmpPath = tempnam(sys_get_temp_dir(), 'po_wizard_');
        file_put_contents($tmpPath, $content);
        $rows = $importer->readRows($tmpPath, $staged['fileName']);
        @unlink($tmpPath);
        $plan = $importer->preview($rows, $staged['tanggal'], $staged['uploadType'], $staged['hash'], $staged['fileName'], stagedSkipKeys());
    } catch (\Throwable $e) {
        $planError = $e->getMessage();
        unset($_SESSION['po_staged']);
    }
}

$repo = new PoRepository();
$recentBatches = $repo->findAllBatches($pdo, null, null);
$divisions = $pdo->query('SELECT division_id, name FROM division ORDER BY name')->fetchAll();
$revisionReviewPending = $plan !== null && $plan['uploadType'] === 'revision' && !empty($_SESSION['po_staged']['revisionReviewPending']);

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>Amor Factory — Import PO (Phase 2)</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem;color:#1a1a1a;line-height:1.5;}
h1{font-size:1.4rem;} h2{font-size:1.1rem;margin-top:2rem;border-bottom:2px solid #eee;padding-bottom:.3rem;}
.box{border:1px solid #ddd;border-radius:8px;padding:1rem;margin:1rem 0;}
.warn{background:#fff3cd;border:1px solid #f0c36d;padding:.75rem;border-radius:6px;}
.result-ok{background:#e6ffe6;border:1px solid #8c8;padding:.75rem;border-radius:6px;}
.result-error{background:#ffe6e6;border:1px solid #e99;padding:.75rem;border-radius:6px;}
.gate-complete{background:#080;color:#fff;padding:1rem;border-radius:8px;font-weight:bold;font-size:1.2em;text-align:center;}
.gate-incomplete{background:#e90;color:#fff;padding:1rem;border-radius:8px;font-weight:bold;font-size:1.1em;text-align:center;}
button{padding:.5rem 1.2rem;background:#0a5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:bold;}
button.secondary{background:#888;} button.danger{background:#b00;}
input[type=text],input[type=date],select{padding:.4rem;}
table{border-collapse:collapse;width:100%;margin:.5rem 0;}
td,th{text-align:left;padding:.3rem .6rem;border-bottom:1px solid #eee;font-size:.9em;vertical-align:middle;}
.badge{display:inline-block;padding:1px 8px;border-radius:10px;font-size:.8em;font-weight:bold;color:#fff;}
.b-ok{background:#080;} .b-bad{background:#b00;} .b-info{background:#06c;}
form.inline{display:inline;}
.modal-box{border:3px solid #06c;background:#eef6ff;border-radius:10px;padding:1.2rem;margin:1rem 0;}
.newprod-box{border:1px solid #e0c060;background:#fffdf3;border-radius:8px;padding:.8rem;margin:.6rem 0;}
.newprod-box h4{margin:0 0 .3rem;}
fieldset{border:1px solid #ddd;border-radius:6px;padding:.6rem;margin:.5rem 0;}
fieldset legend{padding:0 .4rem;font-size:.85em;color:#555;}
label{display:block;margin:.5rem 0;}
</style>
</head>
<body>

<h1>Amor Factory — Import PO (Phase 2 Fast-Track)</h1>
<p>Login sebagai: <strong><?= esc($resolvedByLabel) ?></strong> &middot; <a href="../_admin-login/">logout</a>.
Menggunakan koneksi database <strong>runtime</strong> (bukan migrasi) untuk semua operasi di halaman ini.
Tidak ada data Produksi/FG/Packing/DO/Pengiriman/Stok/Invoice yang disentuh — hanya PO (permintaan).</p>

<?php if ($actionResult !== null): ?>
<div class="<?= $actionResult['ok'] ? 'result-ok' : 'result-error' ?>">
  <strong><?= esc($actionResult['title']) ?></strong>
  <p><?= esc($actionResult['message']) ?></p>
</div>
<?php endif; ?>
<?php if ($planError !== null): ?>
<div class="result-error"><strong>Gagal membaca ulang file tersimpan</strong><p><?= esc($planError) ?></p></div>
<?php endif; ?>

<?php if ($revisionReviewPending): ?>
<div class="modal-box">
  <h2 style="margin-top:0;">Konfirmasi PO Revisi</h2>
  <p>File ini masih dapat mengandung nilai PO Awal.</p>
  <p><strong>Sistem TIDAK akan mengubah atau menambahkan ulang PO Awal yang sudah tersimpan.</strong></p>
  <p>Yang akan diperbarui hanya PO Tambahan / Revisi berdasarkan snapshot terbaru pada file ini.</p>
  <p style="background:#f4f4f4;padding:.75rem;border-radius:6px;">Contoh: PO Awal 100 + Revisi lama 20, lalu file
  terbaru Revisi 35 &rarr; target menjadi <strong>135</strong>, bukan 155.</p>
  <p>PO Awal existing (tidak berubah): <strong><?= fmtNum($plan['committedPoAwal']) ?></strong> &middot;
  PO Tambahan terbaru: <strong><?= fmtNum($plan['committedPoRevisi']) ?></strong> &middot;
  Target setelah revisi: <strong><?= fmtNum($plan['targetTotal']) ?></strong> (lihat rincian per baris di bawah).</p>
  <p><strong>Lanjutkan proses PO Revisi?</strong></p>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
    <form method="post"><input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
      <input type="hidden" name="action" value="cancel_revision_review">
      <button type="submit" class="secondary">Batal</button>
    </form>
    <form method="post"><input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
      <input type="hidden" name="action" value="confirm_import">
      <button type="submit">Ya, Proses PO Revisi</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($plan === null): ?>
<h2>1. Upload File PO</h2>
<div class="box">
  <p>Format yang didukung: <code>.xlsx</code> atau <code>.csv</code> — layout Karangtengah (NO/KATEGORI/KODE/NAMA PRODUK)
  atau Cibadak/Bolu (Kategori/Nama Produk) dikenali otomatis dari isi filenya, bukan dari pilihan pabrik manapun.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="preview_upload">
    <label>Tanggal Berlaku PO <input type="date" name="tanggal" required></label>
    <label>Tipe Upload
      <label style="display:inline;margin-right:1rem;"><input type="radio" name="uploadType" value="initial" required> PO Awal</label>
      <label style="display:inline;"><input type="radio" name="uploadType" value="revision"> PO Tambahan / Revisi</label>
    </label>
    <label>File <input type="file" name="file" accept=".xlsx,.csv" required></label>
    <button type="submit">Proses &amp; Preview</button>
  </form>
</div>
<?php else: ?>
<h2>2. Preview — <?= esc($plan['tanggal']) ?> &middot; <?= esc($plan['factory'] === 'cibadak' ? 'Cibadak (Bolu)' : 'Karangtengah') ?> &middot; <?= $plan['uploadType'] === 'initial' ? 'PO Awal' : 'PO Tambahan/Revisi' ?></h2>
<div class="box">
  <form method="post" style="display:inline;">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="reset_staged">
    <button type="submit" class="secondary">Batal / Ganti File</button>
  </form>

  <?php if ($plan['duplicateOf'] !== null): ?>
  <div class="warn" style="margin-top:1rem;"><p><strong>FILE SUDAH PERNAH DIUPLOAD</strong> — file dengan isi persis sama
  sudah diimpor sebelumnya (<?= esc($plan['duplicateOf']['uploaded_at']) ?>, tipe <?= esc($plan['duplicateOf']['upload_type']) ?>).
  Mengimpor lagi aman (tidak akan menggandakan angka — lihat aturan snapshot revisi), tapi kemungkinan Anda tidak sengaja
  mengupload file yang sama dua kali.</p></div>
  <?php endif; ?>

  <table style="margin-top:1rem;">
    <tr><td>Baris produk terbaca</td><td><?= $plan['parsedRowCount'] ?></td></tr>
    <tr><td>Baris dengan PO Awal &gt; 0</td><td><?= $plan['rowsPoAwal'] ?></td></tr>
    <tr><td>Baris dengan PO Revisi &gt; 0</td><td><?= $plan['rowsPoRevisi'] ?></td></tr>
    <tr><td>Baris dengan PB (selalu diabaikan, tidak memengaruhi target)</td><td><?= $plan['rowsPbIgnored'] ?></td></tr>
    <tr><td>Produk terpetakan</td><td><span class="badge b-ok"><?= $plan['productResolution']['mapped'] ?></span></td></tr>
    <tr><td>Produk BELUM terpetakan</td><td><span class="badge <?= $plan['productResolution']['unresolved'] > 0 ? 'b-bad' : 'b-ok' ?>"><?= $plan['productResolution']['unresolved'] ?></span></td></tr>
    <tr><td>Toko unik terpetakan <span style="color:#666;">(jumlah toko berbeda, bukan baris)</span></td><td><span class="badge b-ok"><?= $plan['storeResolution']['uniqueMapped'] ?></span></td></tr>
    <tr><td>Toko unik BELUM terpetakan <span style="color:#666;">(nama toko berbeda)</span></td><td><span class="badge <?= $plan['storeResolution']['uniqueUnresolved'] > 0 ? 'b-bad' : 'b-ok' ?>"><?= $plan['storeResolution']['uniqueUnresolved'] ?></span></td></tr>
    <tr><td>Kemunculan/baris toko terpetakan <span style="color:#666;">(1 toko bisa muncul di banyak baris produk)</span></td><td><span class="badge b-info"><?= $plan['storeResolution']['mapped'] ?></span></td></tr>
    <tr><td>Kemunculan/baris toko BELUM terpetakan</td><td><span class="badge <?= $plan['storeResolution']['unresolved'] > 0 ? 'b-bad' : 'b-info' ?>"><?= $plan['storeResolution']['unresolved'] ?></span></td></tr>
  </table>

  <?php if ($plan['uploadType'] === 'initial'): ?>
  <table>
    <tr><td>PO Awal terdeteksi (dari file)</td><td><strong><?= fmtNum($plan['totalPoAwal']) ?></strong></td></tr>
    <tr><td>PO Tambahan terdeteksi <span style="color:#666;">(file ini juga berisi kolom Revisi, tapi diabaikan sepenuhnya untuk upload PO Awal — tidak pernah ikut tersimpan)</span></td><td><?= fmtNum($plan['totalPoRevisi']) ?></td></tr>
    <tr><td>PB terdeteksi <span style="color:#666;">(selalu diabaikan total)</span></td><td><?= fmtNum($plan['totalPb']) ?></td></tr>
    <tr><td><strong>Akan diimpor sekarang (PO Awal saja)</strong></td><td><strong><?= fmtNum($plan['committedPoAwal']) ?></strong></td></tr>
  </table>
  <?php else: ?>
  <table>
    <tr><td>PO Awal existing <span style="color:#666;">(terkunci, tidak berubah)</span></td><td><?= fmtNum($plan['committedPoAwal']) ?></td></tr>
    <tr><td>PO Tambahan terbaru (dari file ini)</td><td><?= fmtNum($plan['committedPoRevisi']) ?></td></tr>
    <tr><td>PB terdeteksi <span style="color:#666;">(selalu diabaikan total)</span></td><td><?= fmtNum($plan['totalPb']) ?></td></tr>
    <tr><td><strong>Target setelah revisi (PO Awal + PO Tambahan terbaru)</strong></td><td><strong><?= fmtNum($plan['targetTotal']) ?></strong></td></tr>
  </table>
  <?php endif; ?>

  <?php if ($plan['blockReason'] === 'INITIAL_PO_ALREADY_EXISTS'): ?>
  <div class="result-error"><strong>PO Awal sudah ada</strong><p>Tanggal &amp; pabrik ini sudah punya PO Awal dari file
  yang berbeda. Ini tidak ditimpa otomatis — pilih "PO Tambahan / Revisi" jika maksud Anda memperbarui, atau hubungi
  admin lain jika PO Awal ini perlu dikoreksi.</p></div>
  <?php elseif ($plan['blockReason'] === 'NO_INITIAL_YET'): ?>
  <div class="result-error"><strong>Belum ada PO Awal</strong><p>Tanggal &amp; pabrik ini belum punya PO Awal sama
  sekali. Upload PO Awal dulu sebelum PO Tambahan/Revisi.</p></div>
  <?php endif; ?>

  <?php if (!empty($plan['warnings'])): ?>
  <div class="warn"><p><strong><?= count($plan['warnings']) ?> catatan</strong> (selisih TOTAL vs breakdown toko, atau
  produk tanpa alokasi toko) — boleh tetap diimpor, tapi sebaiknya dicek ke sumbernya:</p>
  <ul><?php foreach (array_slice($plan['warnings'], 0, 15) as $w): ?>
    <li><strong><?= esc($w['produk']) ?></strong>: <?= esc($w['pesan']) ?></li>
  <?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php if ($plan['productResolution']['unresolved'] > 0): ?>
  <h3>Produk Baru Terdeteksi</h3>
  <p style="color:#666;">Baris-baris ini tidak cocok dengan produk manapun di Master Produk. Pilih salah satu aksi per
  produk — TIDAK ada yang dibuat otomatis tanpa konfirmasi Anda, dan tidak ada penggabungan otomatis walau namanya mirip.</p>
  <?php foreach ($plan['productResolution']['samples'] as $u):
    $candidates = $poResolver->similarProductCandidates($u['nama']);
    $reason = $poResolver->unresolvedProductReason($u['kode'], $u['nama']);
  ?>
  <div class="newprod-box">
    <h4><?= esc($u['nama']) ?> <span style="font-weight:normal;color:#666;">(kode: <?= $u['kode'] !== '' ? esc($u['kode']) : '—' ?>)</span></h4>
    <p style="margin:.2rem 0;">Kategori terdeteksi: <?= $u['kategori'] ? esc($u['kategori']) : '—' ?> &middot;
    Divisi terdeteksi: — &middot; Harga jual terdeteksi: — <span style="color:#666;">(file PO tidak membawa data ini)</span></p>
    <p style="margin:.2rem 0;color:#666;">Alasan belum terpetakan: <?= esc($reason) ?></p>
    <?php if ($candidates): ?>
    <div class="warn"><p style="margin:0;"><strong>Mungkin mirip dengan produk yang sudah ada</strong> (bukan otomatis,
    hanya info — pilih "Petakan ke Produk Sudah Ada" di bawah kalau memang ini yang dimaksud):</p>
    <ul style="margin:.3rem 0;"><?php foreach ($candidates as $c): ?><li><?= esc($c['name']) ?> (kemiripan <?= $c['similarity'] ?>%)</li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <fieldset>
      <legend>1. Petakan ke Produk yang Sudah Ada</legend>
      <form class="inline" method="post" style="display:flex;gap:.3rem;flex-wrap:wrap;">
        <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
        <input type="hidden" name="action" value="resolve_product_alias">
        <input type="hidden" name="rawName" value="<?= esc($u['nama']) ?>">
        <input type="text" name="targetProductName" placeholder="Nama produk yang sudah ada (persis)" size="28">
        <button type="submit">Petakan ke Produk Sudah Ada</button>
      </form>
    </fieldset>

    <fieldset>
      <legend>2. Tambah ke Master &amp; Lanjutkan PO</legend>
      <form class="inline" method="post" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
        <input type="hidden" name="action" value="create_new_product_from_import">
        <input type="hidden" name="rawCode" value="<?= esc($u['kode']) ?>">
        <input type="hidden" name="rawName" value="<?= esc($u['nama']) ?>">
        <label style="margin:0;">Nama <input type="text" name="finalName" value="<?= esc($u['nama']) ?>" size="24"></label>
        <label style="margin:0;">Kategori <input type="text" name="kategori" value="<?= esc((string) $u['kategori']) ?>" size="12"></label>
        <label style="margin:0;">Divisi
          <select name="divisionId"><option value="">(belum diketahui)</option>
            <?php foreach ($divisions as $d): ?><option value="<?= (int) $d['division_id'] ?>"><?= esc($d['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label style="margin:0;">Harga Jual <input type="text" name="harga" placeholder="0" size="8"></label>
        <button type="submit">Tambah ke Master &amp; Lanjutkan</button>
      </form>
      <p style="margin:.3rem 0 0;color:#a60;font-size:.85em;"><strong>HPP belum tersedia dan disimpan sementara sebagai 0.</strong>
      Angka 0 ini BUKAN data biaya asli.</p>
    </fieldset>

    <fieldset>
      <legend>3. Lewati</legend>
      <form class="inline" method="post">
        <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
        <input type="hidden" name="action" value="skip_unresolved_product">
        <input type="hidden" name="rawCode" value="<?= esc($u['kode']) ?>">
        <input type="hidden" name="rawName" value="<?= esc($u['nama']) ?>">
        <button type="submit" class="secondary">Lewati Produk Ini</button>
      </form>
      <p style="margin:.3rem 0 0;color:#666;font-size:.85em;">Hanya baris produk ini yang dikecualikan — baris lain
      di file yang sama tetap diimpor seperti biasa.</p>
    </fieldset>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($plan['skippedProductRows'])): ?>
  <h3>Produk Dilewati</h3>
  <table><tr><th>Kode</th><th>Nama</th><th></th></tr>
  <?php foreach ($plan['skippedProductRows'] as $sp):
    $key = PoImporter::productRawKey($sp['kode'], $sp['nama']); ?>
  <tr>
    <td><?= esc($sp['kode']) ?></td>
    <td><?= esc($sp['nama']) ?></td>
    <td><form class="inline" method="post">
      <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
      <input type="hidden" name="action" value="unskip_product">
      <input type="hidden" name="key" value="<?= esc($key) ?>">
      <button type="submit" class="secondary">Batalkan Lewati</button>
    </form></td>
  </tr>
  <?php endforeach; ?></table>
  <p style="color:#666;font-size:.9em;">Baris-baris ini TIDAK akan ikut diimpor — dicatat di riwayat upload sebagai
  dilewati oleh admin.</p>
  <?php endif; ?>

  <?php if ($plan['storeResolution']['unresolved'] > 0): ?>
  <h3>Toko belum terpetakan</h3>
  <table><tr><th>Nama di File</th><th>Petakan ke Toko</th></tr>
  <?php foreach ($plan['storeResolution']['samples'] as $rawStoreName): ?>
  <tr>
    <td><?= esc($rawStoreName) ?></td>
    <td>
      <form class="inline" method="post" style="display:flex;gap:.3rem;">
        <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
        <input type="hidden" name="action" value="resolve_store_alias">
        <input type="hidden" name="rawName" value="<?= esc($rawStoreName) ?>">
        <input type="text" name="targetStoreName" placeholder="Nama toko yang sudah ada" size="28">
        <button type="submit">Buat Alias</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?></table>
  <?php endif; ?>

  <?php if ($plan['canImport'] && $revisionReviewPending): ?>
  <p style="color:#06c;"><strong>Lihat kotak "Konfirmasi PO Revisi" di atas halaman ini untuk melanjutkan.</strong></p>
  <?php elseif ($plan['canImport'] && $plan['uploadType'] === 'revision'): ?>
  <form method="post" style="margin-top:1rem;">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="review_revision">
    <button type="submit">Konfirmasi &amp; Impor PO</button>
  </form>
  <?php elseif ($plan['canImport']): ?>
  <form method="post" style="margin-top:1rem;" onsubmit="return confirm('Impor PO ini sekarang?');">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="confirm_import">
    <button type="submit">Konfirmasi &amp; Impor PO</button>
  </form>
  <?php else: ?>
  <p style="color:#b00;"><strong>Belum bisa diimpor</strong> — selesaikan hal di atas dulu.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<h2>3. PO Tersimpan (Ringkasan)</h2>
<div class="box">
  <?php if (!$recentBatches): ?>
  <p>Belum ada PO yang tersimpan.</p>
  <?php else: ?>
  <table><tr><th>Tanggal</th><th>Pabrik</th><th>Tipe Upload Terakhir</th><th>File Terakhir</th><th>Versi</th></tr>
  <?php foreach (array_slice($recentBatches, 0, 20) as $b): ?>
  <tr>
    <td><?= esc($b['tanggal']) ?></td>
    <td><?= esc($b['factory_name']) ?></td>
    <td><?= esc($b['upload_type'] ?? '-') ?></td>
    <td><?= esc($b['source_filename'] ?? '-') ?></td>
    <td><?= (int) $b['version'] ?></td>
  </tr>
  <?php endforeach; ?></table>
  <?php endif; ?>
  <p style="color:#666;">Untuk rincian per toko/produk, gunakan <code>GET /api/po/current</code> (bisa difilter
  <code>date</code>, <code>factoryId</code>, <code>divisionId</code>, <code>storeId</code>, <code>poType</code>).</p>
</div>

<h2>4. Selesai</h2>
<div class="box">
  <form method="post" onsubmit="return confirm('Nonaktifkan halaman import PO ini sekarang?');">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="disable">
    <button type="submit" class="danger">Nonaktifkan Import PO Wizard</button>
  </form>
</div>

</body>
</html>
