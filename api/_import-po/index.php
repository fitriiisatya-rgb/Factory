<?php

declare(strict_types=1);

/**
 * Amor Factory — Import / Revisi PO Toko wizard (Phase 2's PO import
 * pipeline). UI/UX rework: this page now renders inside the shared Amor
 * Factory dark navy shell (see the ui_page_head()/ui_page_foot() require
 * below) instead of its own bare white page — the internal wizard/parser
 * logic below is unchanged.
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
// UI/UX rework: this page now renders inside the SAME Amor Factory dark
// navy shell every /api/_ui-preview/ page uses (sidebar, topbar, cards,
// data-table, badges) instead of its own bare white Phase-2 styling —
// layout.php only defines functions/a constant (no Config::load()/
// Auth::bootSession()/redirect side effects), so requiring it here never
// interferes with this file's OWN auth flow below (which stays exactly
// as it was: session + ADMIN role + CSRF, never bootstrap.php's
// login-redirect variant, since this page's friendly "Login diperlukan"/
// "Akses ditolak" messages and its non-ADMIN-role rejection are
// deliberately preserved).
require __DIR__ . '/../app/ui/layout.php';

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
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:sans-serif;max-width:640px;margin:2rem auto;background:#0b1220;color:#eef2f8;">'
        . '<h1>Konfigurasi belum lengkap</h1><p>' . htmlspecialchars($e->getMessage()) . '</p></body></html>';
    exit;
}

Auth::bootSession();

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function fmtNum(float $n): string { return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ','); }
// Aliases so layout.php's shared ui_page_head()/ui_page_foot()/ui_icon()
// (which call ui_esc()/ui_fmt_num() internally) work without this file
// going through app/ui/bootstrap.php's own separate auth/redirect flow —
// identical logic to esc()/fmtNum() above, never a second implementation.
function ui_esc(string $s): string { return esc($s); }
function ui_fmt_num(float $n): string { return fmtNum($n); }
function ui_fmt_money(float $n): string { return 'Rp' . number_format($n, 0, ',', '.'); }

// Minimal dark-themed gate screen (no sidebar/topbar — there is no logged-
// in identity yet to show one for) for the pre-auth states below, so even
// these never fall back to a plain white page (task's own "no legacy
// white Phase 2 visual remains").
function importPoSimplePage(string $title, string $bodyHtml): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . ui_esc($title) . ' — Amor Factory System</title>'
        . '<link rel="stylesheet" href="/api/assets/css/tokens.css"><link rel="stylesheet" href="/api/assets/css/app.css">'
        . '<style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;}'
        . '.gate-card{max-width:480px;margin:1.5rem;}</style></head><body>'
        . '<div class="card gate-card"><h1 class="card-title" style="margin-bottom:var(--space-3);">' . ui_esc($title) . '</h1>'
        . $bodyHtml . '</div></body></html>';
    exit;
}

if (Auth::currentUserId() === null) {
    importPoSimplePage('Login diperlukan', '<p style="color:var(--text-muted);">Buka <a href="../_admin-login/">../_admin-login/</a> dan login sebagai ADMIN dulu, '
        . 'lalu buka ulang halaman ini di browser/tab yang sama.</p>');
}

try {
    Auth::requireRole('ADMIN');
} catch (ApiException $e) {
    importPoSimplePage('Akses ditolak', '<p style="color:var(--text-muted);">Akun Anda login, tapi bukan ADMIN.</p>');
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
                    "Batch #{$d['poBatchId']} ({$d['tanggal']}, {$d['factory']}) — {$d['linesWritten']} baris ditulis, target total " . fmtNum($d['targetTotal']) . '.' . $skippedNote,
                    // Additive: feeds the richer "PO berhasil disimpan"
                    // success card (task's own success-page requirement) —
                    // never changes what $actionResult['message'] already
                    // said above, purely extra display data.
                    'successData' => $d];
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
    importPoSimplePage('Import PO wizard dinonaktifkan', '<p style="color:var(--success);font-weight:700;">Sudah dinonaktifkan.</p>'
        . '<p style="color:var(--text-muted);">Halaman ini sekarang mengembalikan 404. '
        . 'Disarankan tetap menghapus folder <code>api/_import-po/</code> lewat File Manager kapan pun sempat.</p>');
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

$ui = [
    'pdo' => $pdo,
    'userId' => $userId,
    'username' => $resolvedByLabel,
    'fullName' => $resolvedByLabel,
    'roles' => Auth::currentRoles(),
    'csrfToken' => $csrfToken,
];
ui_page_head($ui, 'pesanan-toko', 'Import / Revisi PO Toko', 'Upload PO Awal atau PO Tambahan/Revisi dan periksa hasil sebelum disimpan.');
?>
<a class="btn btn-secondary" style="margin-bottom:var(--space-3);" href="/api/_ui-preview/?page=pesanan-toko">&larr; Kembali ke Pesanan Toko</a>

<?php if ($actionResult !== null): ?>
<div class="alert <?= $actionResult['ok'] ? 'alert-success' : 'alert-danger' ?>">
  <strong><?= ui_esc($actionResult['title']) ?></strong>
  <p style="margin:4px 0 0;"><?= ui_esc($actionResult['message']) ?></p>
</div>
<?php endif; ?>
<?php if ($planError !== null): ?>
<div class="alert alert-danger"><strong>Gagal membaca ulang file tersimpan</strong><p style="margin:4px 0 0;"><?= ui_esc($planError) ?></p></div>
<?php endif; ?>

<?php if ($actionResult !== null && $actionResult['ok'] && isset($actionResult['successData'])):
  $d = $actionResult['successData']; ?>
<div class="card section">
  <div class="card-head"><h2 class="card-title">PO berhasil disimpan</h2><span class="badge badge-success">Selesai</span></div>
  <div class="kpi-grid kpi-grid-4">
    <?= ui_kpi_card(['label' => 'Pabrik', 'value' => $d['factory'] === 'cibadak' ? 'Cibadak (Bolu)' : 'Karangtengah', 'icon' => 'building', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Tanggal', 'value' => $d['tanggal'], 'icon' => 'calendar', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Tipe', 'value' => $d['uploadType'] === 'initial' ? 'PO Awal' : 'PO Tambahan/Revisi', 'icon' => 'file', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Jumlah Produk', 'value' => (string) $d['linesWritten'], 'icon' => 'box', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Jumlah Toko', 'value' => (string) $d['storesMapped'], 'icon' => 'user', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Total PO Awal', 'value' => ui_fmt_num($d['committedPoAwal']), 'icon' => 'chart', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Total Revisi', 'value' => ui_fmt_num($d['committedPoRevisi']), 'icon' => 'chart', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Target Akhir', 'value' => ui_fmt_num($d['targetTotal']), 'icon' => 'chart', 'color' => 'primary', 'detail' => true]) ?>
  </div>
  <?php if (!empty($d['skippedProductRows'])): ?>
  <div class="alert alert-warning" style="margin-top:var(--space-3);"><?= count($d['skippedProductRows']) ?> produk dilewati, tidak diimpor.</div>
  <?php endif; ?>
  <div class="btn-group" style="margin-top:var(--space-4);">
    <a class="btn btn-primary" href="/api/_ui-preview/?page=pesanan-toko">Lihat PO Toko</a>
    <a class="btn btn-secondary" href="/api/_import-po/">Import PO Lain</a>
  </div>
</div>
<?php endif; ?>

<?php if ($revisionReviewPending): ?>
<div class="card section" style="border-color:var(--primary);">
  <h2 class="card-title" style="margin-bottom:var(--space-3);">Konfirmasi PO Revisi</h2>
  <p>File ini masih dapat mengandung nilai PO Awal.</p>
  <p><strong>Sistem TIDAK akan mengubah atau menambahkan ulang PO Awal yang sudah tersimpan.</strong></p>
  <p>Yang akan diperbarui hanya PO Tambahan / Revisi berdasarkan snapshot terbaru pada file ini.</p>
  <div class="subpanel">Contoh: PO Awal 100 + Revisi lama 20, lalu file terbaru Revisi 35 &rarr; target menjadi <strong>135</strong>, bukan 155.</div>
  <p style="margin-top:var(--space-3);">PO Awal existing (tidak berubah): <strong><?= ui_fmt_num($plan['committedPoAwal']) ?></strong> &middot;
  PO Tambahan terbaru: <strong><?= ui_fmt_num($plan['committedPoRevisi']) ?></strong> &middot;
  Target setelah revisi: <strong><?= ui_fmt_num($plan['targetTotal']) ?></strong> (lihat rincian per baris di bawah).</p>
  <p><strong>Lanjutkan proses PO Revisi?</strong></p>
  <div class="btn-group">
    <form method="post"><input type="hidden" name="csrf" value="<?= ui_esc($csrfToken) ?>">
      <input type="hidden" name="action" value="cancel_revision_review">
      <button type="submit" class="btn btn-secondary">Batal</button>
    </form>
    <form method="post"><input type="hidden" name="csrf" value="<?= ui_esc($csrfToken) ?>">
      <input type="hidden" name="action" value="confirm_import">
      <button type="submit" class="btn btn-primary">Ya, Proses PO Revisi</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($plan === null): ?>
<div class="card section">
  <div class="card-head"><h2 class="card-title">Upload File PO</h2><span class="badge badge-primary">Langkah 1</span></div>
  <div class="alert alert-info">
    Format yang didukung: <code>.xlsx</code> atau <code>.csv</code>.<br>
    <strong>Karangtengah:</strong> NO / KATEGORI / KODE / NAMA PRODUK / kolom toko / TOTAL / blok revisi / blok PB.<br>
    <strong>Cibadak/Bolu:</strong> Kategori / Nama Produk / kolom toko / TOTAL PO.<br>
    Pabrik dikenali otomatis dari isi file — tidak ada pilihan pabrik manual.
  </div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= ui_esc($csrfToken) ?>">
    <input type="hidden" name="action" value="preview_upload">
    <div class="kpi-grid kpi-grid-3" style="margin-bottom:var(--space-3);">
      <div class="field"><label>Tanggal Berlaku PO</label><input type="date" name="tanggal" required></div>
      <div class="field"><label>Tipe Upload</label>
        <select name="uploadType" required>
          <option value="">— Pilih —</option>
          <option value="initial">PO Awal</option>
          <option value="revision">PO Tambahan / Revisi</option>
        </select>
      </div>
      <div class="field"><label>File</label><input type="file" name="file" accept=".xlsx,.csv" required></div>
    </div>
    <button type="submit" class="btn btn-primary">Proses &amp; Preview</button>
  </form>
</div>
<?php else: ?>
<div class="card section">
  <div class="card-head">
    <div>
      <h2 class="card-title">Preview — <?= ui_esc($plan['tanggal']) ?></h2>
      <div class="page-subtitle" style="margin-top:4px;"><?= ui_esc($plan['factory'] === 'cibadak' ? 'Cibadak (Bolu)' : 'Karangtengah') ?> &middot; <?= $plan['uploadType'] === 'initial' ? 'PO Awal' : 'PO Tambahan/Revisi' ?></div>
    </div>
    <span class="badge badge-primary">Langkah 2</span>
  </div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= ui_esc($csrfToken) ?>">
    <input type="hidden" name="action" value="reset_staged">
    <button type="submit" class="btn btn-secondary btn-sm">Batal / Ganti File</button>
  </form>

  <?php if ($plan['duplicateOf'] !== null): ?>
  <div class="alert alert-warning" style="margin-top:var(--space-3);"><strong>FILE SUDAH PERNAH DIUPLOAD</strong> — file dengan isi persis sama
  sudah diimpor sebelumnya (<?= ui_esc($plan['duplicateOf']['uploaded_at']) ?>, tipe <?= ui_esc($plan['duplicateOf']['upload_type']) ?>).
  Mengimpor lagi aman (tidak akan menggandakan angka — lihat aturan snapshot revisi), tapi kemungkinan Anda tidak sengaja
  mengupload file yang sama dua kali.</div>
  <?php endif; ?>

  <div class="kpi-grid kpi-grid-4" style="margin-top:var(--space-4);">
    <?= ui_kpi_card(['label' => 'Pabrik Terdeteksi', 'value' => $plan['factory'] === 'cibadak' ? 'Cibadak (Bolu)' : 'Karangtengah', 'icon' => 'building', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Tanggal PO', 'value' => $plan['tanggal'], 'icon' => 'calendar', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Tipe Upload', 'value' => $plan['uploadType'] === 'initial' ? 'PO Awal' : 'PO Tambahan/Revisi', 'icon' => 'file', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Jumlah Produk', 'value' => (string) $plan['parsedRowCount'], 'icon' => 'box', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Jumlah Toko', 'value' => (string) $plan['storeResolution']['uniqueMapped'], 'icon' => 'user', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'PO Awal', 'value' => ui_fmt_num($plan['uploadType'] === 'initial' ? $plan['totalPoAwal'] : $plan['committedPoAwal']), 'icon' => 'chart', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'PO Revisi', 'value' => ui_fmt_num($plan['uploadType'] === 'initial' ? $plan['totalPoRevisi'] : $plan['committedPoRevisi']), 'icon' => 'chart', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'PB Diabaikan', 'value' => ui_fmt_num($plan['totalPb']), 'icon' => 'box', 'color' => 'neutral', 'detail' => true]) ?>
    <?= ui_kpi_card(['label' => 'Target yang Akan Tersimpan', 'value' => ui_fmt_num($plan['uploadType'] === 'initial' ? $plan['committedPoAwal'] : $plan['targetTotal']), 'icon' => 'chart', 'color' => 'primary', 'detail' => true]) ?>
  </div>

  <div class="table-scroll" style="margin-top:var(--space-4);"><table class="data-table">
    <thead><tr><th>Ukuran Resolusi</th><th class="num">Jumlah</th></tr></thead>
    <tbody>
      <tr><td>Produk terpetakan</td><td class="num"><span class="badge badge-success"><?= $plan['productResolution']['mapped'] ?></span></td></tr>
      <tr><td>Produk BELUM terpetakan</td><td class="num"><span class="badge <?= $plan['productResolution']['unresolved'] > 0 ? 'badge-danger' : 'badge-success' ?>"><?= $plan['productResolution']['unresolved'] ?></span></td></tr>
      <tr><td>Toko unik terpetakan <span style="color:var(--text-faint);">(jumlah toko berbeda, bukan baris)</span></td><td class="num"><span class="badge badge-success"><?= $plan['storeResolution']['uniqueMapped'] ?></span></td></tr>
      <tr><td>Toko unik BELUM terpetakan <span style="color:var(--text-faint);">(nama toko berbeda)</span></td><td class="num"><span class="badge <?= $plan['storeResolution']['uniqueUnresolved'] > 0 ? 'badge-danger' : 'badge-success' ?>"><?= $plan['storeResolution']['uniqueUnresolved'] ?></span></td></tr>
      <tr><td>Kemunculan/baris toko terpetakan <span style="color:var(--text-faint);">(1 toko bisa muncul di banyak baris produk)</span></td><td class="num"><span class="badge badge-neutral"><?= $plan['storeResolution']['mapped'] ?></span></td></tr>
      <tr><td>Kemunculan/baris toko BELUM terpetakan</td><td class="num"><span class="badge <?= $plan['storeResolution']['unresolved'] > 0 ? 'badge-danger' : 'badge-neutral' ?>"><?= $plan['storeResolution']['unresolved'] ?></span></td></tr>
    </tbody>
  </table></div>

  <?php if ($plan['blockReason'] === 'INITIAL_PO_ALREADY_EXISTS'): ?>
  <div class="alert alert-danger" style="margin-top:var(--space-3);"><strong>PO Awal sudah ada</strong><p style="margin:4px 0 0;">Tanggal &amp; pabrik ini sudah punya PO Awal dari file
  yang berbeda. Ini tidak ditimpa otomatis — pilih "PO Tambahan / Revisi" jika maksud Anda memperbarui, atau hubungi
  admin lain jika PO Awal ini perlu dikoreksi.</p></div>
  <?php elseif ($plan['blockReason'] === 'NO_INITIAL_YET'): ?>
  <div class="alert alert-danger" style="margin-top:var(--space-3);"><strong>Belum ada PO Awal</strong><p style="margin:4px 0 0;">Tanggal &amp; pabrik ini belum punya PO Awal sama
  sekali. Upload PO Awal dulu sebelum PO Tambahan/Revisi.</p></div>
  <?php endif; ?>

  <?php if (!empty($plan['warnings'])): ?>
  <div class="alert alert-warning" style="margin-top:var(--space-3);"><strong><?= count($plan['warnings']) ?> catatan</strong> (selisih TOTAL vs breakdown toko, atau
  produk tanpa alokasi toko) — boleh tetap diimpor, tapi sebaiknya dicek ke sumbernya:
  <ul style="margin:var(--space-2) 0 0;padding-left:1.2em;"><?php foreach (array_slice($plan['warnings'], 0, 15) as $w): ?>
    <li><strong><?= ui_esc($w['produk']) ?></strong>: <?= ui_esc($w['pesan']) ?></li>
  <?php endforeach; ?></ul></div>
  <?php endif; ?>

  <?php if ($plan['productResolution']['unresolved'] > 0): ?>
  <h3 class="card-title" style="margin:var(--space-4) 0 4px;">Produk Baru Terdeteksi</h3>
  <p style="color:var(--text-muted);font-size:var(--text-sm);">Baris-baris ini tidak cocok dengan produk manapun di Master Produk. Pilih salah satu aksi per
  produk — TIDAK ada yang dibuat otomatis tanpa konfirmasi Anda, dan tidak ada penggabungan otomatis walau namanya mirip.</p>
  <?php foreach ($plan['productResolution']['samples'] as $u):
    $candidates = $poResolver->similarProductCandidates($u['nama']);
    $reason = $poResolver->unresolvedProductReason($u['kode'], $u['nama']);
  ?>
  <div class="subpanel" style="margin-bottom:var(--space-3);">
    <div class="subpanel-title"><?= ui_esc($u['nama']) ?> <span style="font-weight:normal;color:var(--text-muted);">(kode: <?= $u['kode'] !== '' ? ui_esc($u['kode']) : '—' ?>)</span></div>
    <p style="margin:2px 0;font-size:var(--text-sm);">Kategori terdeteksi: <?= $u['kategori'] ? ui_esc($u['kategori']) : '—' ?> &middot;
    Divisi terdeteksi: — &middot; Harga jual terdeteksi: — <span style="color:var(--text-faint);">(file PO tidak membawa data ini)</span></p>
    <p style="margin:2px 0;font-size:var(--text-sm);color:var(--text-muted);">Alasan belum terpetakan: <?= ui_esc($reason) ?></p>
    <?php if ($candidates): ?>
    <div class="alert alert-warning" style="margin:var(--space-2) 0;"><strong>Mungkin mirip dengan produk yang sudah ada</strong> (bukan otomatis,
    hanya info — pilih "Petakan ke Produk Sudah Ada" di bawah kalau memang ini yang dimaksud):
    <ul style="margin:var(--space-1) 0 0;padding-left:1.2em;"><?php foreach ($candidates as $c): ?><li><?= ui_esc($c['name']) ?> (kemiripan <?= $c['similarity'] ?>%)</li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="subpanel" style="margin:var(--space-2) 0;">
      <div class="subpanel-title">1. Petakan ke Produk yang Sudah Ada</div>
      <form method="post" style="display:flex;gap:var(--space-2);flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="csrf" value="<?= ui_esc($csrfToken) ?>">
        <input type="hidden" name="action" value="resolve_product_alias">
        <input type="hidden" name="rawName" value="<?= ui_esc($u['nama']) ?>">
        <div class="field" style="margin:0;"><input type="text" name="targetProductName" placeholder="Nama produk yang sudah ada (persis)"></div>
        <button type="submit" class="btn btn-secondary btn-sm">Petakan ke Produk Sudah Ada</button>
      </form>
    </div>

    <div class="subpanel" style="margin:var(--space-2) 0;">
      <div class="subpanel-title">2. Tambah ke Master &amp; Lanjutkan PO</div>
      <form method="post" style="display:flex;gap:var(--space-2);flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="csrf" value="<?= ui_esc($csrfToken) ?>">
        <input type="hidden" name="action" value="create_new_product_from_import">
        <input type="hidden" name="rawCode" value="<?= ui_esc($u['kode']) ?>">
        <input type="hidden" name="rawName" value="<?= ui_esc($u['nama']) ?>">
        <div class="field" style="margin:0;"><label>Nama</label><input type="text" name="finalName" value="<?= ui_esc($u['nama']) ?>"></div>
        <div class="field" style="margin:0;"><label>Kategori</label><input type="text" name="kategori" value="<?= ui_esc((string) $u['kategori']) ?>"></div>
        <div class="field" style="margin:0;"><label>Divisi</label>
          <select name="divisionId"><option value="">(belum diketahui)</option>
            <?php foreach ($divisions as $d): ?><option value="<?= (int) $d['division_id'] ?>"><?= ui_esc($d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="margin:0;"><label>Harga Jual</label><input type="text" name="harga" placeholder="0"></div>
        <button type="submit" class="btn btn-primary btn-sm">Tambah ke Master &amp; Lanjutkan</button>
      </form>
      <p style="margin:var(--space-2) 0 0;color:var(--warning);font-size:var(--text-xs);"><strong>HPP belum tersedia dan disimpan sementara sebagai 0.</strong>
      Angka 0 ini BUKAN data biaya asli.</p>
    </div>

    <div class="subpanel" style="margin:var(--space-2) 0;">
      <div class="subpanel-title">3. Lewati</div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= ui_esc($csrfToken) ?>">
        <input type="hidden" name="action" value="skip_unresolved_product">
        <input type="hidden" name="rawCode" value="<?= ui_esc($u['kode']) ?>">
        <input type="hidden" name="rawName" value="<?= ui_esc($u['nama']) ?>">
        <button type="submit" class="btn btn-secondary btn-sm">Lewati Produk Ini</button>
      </form>
      <p style="margin:var(--space-2) 0 0;color:var(--text-faint);font-size:var(--text-xs);">Hanya baris produk ini yang dikecualikan — baris lain
      di file yang sama tetap diimpor seperti biasa.</p>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($plan['skippedProductRows'])): ?>
  <h3 class="card-title" style="margin:var(--space-4) 0 4px;">Produk Dilewati</h3>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Kode</th><th>Nama</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($plan['skippedProductRows'] as $sp):
      $key = PoImporter::productRawKey($sp['kode'], $sp['nama']); ?>
    <tr>
      <td><?= ui_esc($sp['kode']) ?></td>
      <td><?= ui_esc($sp['nama']) ?></td>
      <td><form method="post">
        <input type="hidden" name="csrf" value="<?= ui_esc($csrfToken) ?>">
        <input type="hidden" name="action" value="unskip_product">
        <input type="hidden" name="key" value="<?= ui_esc($key) ?>">
        <button type="submit" class="btn btn-secondary btn-sm">Batalkan Lewati</button>
      </form></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p style="color:var(--text-muted);font-size:var(--text-sm);">Baris-baris ini TIDAK akan ikut diimpor — dicatat di riwayat upload sebagai
  dilewati oleh admin.</p>
  <?php endif; ?>

  <?php if ($plan['storeResolution']['unresolved'] > 0): ?>
  <h3 class="card-title" style="margin:var(--space-4) 0 4px;">Toko belum terpetakan</h3>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Nama di File</th><th>Petakan ke Toko</th></tr></thead>
    <tbody>
    <?php foreach ($plan['storeResolution']['samples'] as $rawStoreName): ?>
    <tr>
      <td><?= ui_esc($rawStoreName) ?></td>
      <td>
        <form method="post" style="display:flex;gap:var(--space-2);">
          <input type="hidden" name="csrf" value="<?= ui_esc($csrfToken) ?>">
          <input type="hidden" name="action" value="resolve_store_alias">
          <input type="hidden" name="rawName" value="<?= ui_esc($rawStoreName) ?>">
          <input type="text" name="targetStoreName" placeholder="Nama toko yang sudah ada">
          <button type="submit" class="btn btn-secondary btn-sm">Buat Alias</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <div style="margin-top:var(--space-4);">
  <?php if ($plan['canImport'] && $revisionReviewPending): ?>
  <p style="color:var(--primary);"><strong>Lihat kotak "Konfirmasi PO Revisi" di atas halaman ini untuk melanjutkan.</strong></p>
  <?php elseif ($plan['canImport'] && $plan['uploadType'] === 'revision'): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= ui_esc($csrfToken) ?>">
    <input type="hidden" name="action" value="review_revision">
    <button type="submit" class="btn btn-primary">Konfirmasi &amp; Simpan Revisi</button>
  </form>
  <?php elseif ($plan['canImport']): ?>
  <form method="post" onsubmit="return confirm('Impor PO ini sekarang?');">
    <input type="hidden" name="csrf" value="<?= ui_esc($csrfToken) ?>">
    <input type="hidden" name="action" value="confirm_import">
    <button type="submit" class="btn btn-primary">Konfirmasi &amp; Simpan PO Awal</button>
  </form>
  <?php else: ?>
  <p style="color:var(--danger);"><strong>Belum bisa diimpor</strong> — selesaikan hal di atas dulu.</p>
  <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="table-card section">
  <div class="card-head" style="padding:var(--space-4) var(--space-4) 0;">
    <h2 class="card-title">PO Tersimpan (Ringkasan)</h2>
  </div>
  <?php if (!$recentBatches): ?>
  <div style="padding:0 var(--space-4) var(--space-4);"><?= ui_empty_state('Belum ada PO yang tersimpan', '') ?></div>
  <?php else: ?>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Tanggal</th><th>Pabrik</th><th>Tipe Upload Terakhir</th><th>File Terakhir</th><th class="num">Versi</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($recentBatches, 0, 20) as $b): ?>
    <tr>
      <td><?= ui_esc($b['tanggal']) ?></td>
      <td><?= ui_esc($b['factory_name']) ?></td>
      <td><?= ui_esc($b['upload_type'] ?? '-') ?></td>
      <td><?= ui_esc($b['source_filename'] ?? '-') ?></td>
      <td class="num"><?= (int) $b['version'] ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <p style="padding:0 var(--space-4) var(--space-4);color:var(--text-faint);font-size:var(--text-xs);">Untuk rincian per toko/produk, gunakan <code>GET /api/po/current</code> (bisa difilter
  <code>date</code>, <code>factoryId</code>, <code>divisionId</code>, <code>storeId</code>, <code>poType</code>).</p>
</div>

<div class="card section">
  <div class="card-head"><h2 class="card-title">Nonaktifkan Wizard Ini</h2></div>
  <form method="post" onsubmit="return confirm('Nonaktifkan halaman import PO ini sekarang?');">
    <input type="hidden" name="csrf" value="<?= ui_esc($csrfToken) ?>">
    <input type="hidden" name="action" value="disable">
    <button type="submit" class="btn btn-danger">Nonaktifkan Import PO Wizard</button>
  </form>
</div>

<?php ui_page_foot(); ?>
