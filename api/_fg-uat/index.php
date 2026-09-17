<?php

declare(strict_types=1);

/**
 * Amor Factory — Phase 4 fast-track FG/Packing UAT wizard.
 *
 * TEMPORARY, like api/_import-po/ and api/_production-uat/ were for their
 * phases. Delete this whole directory once Phase 4 is reviewed and
 * accepted.
 *
 * Requires a real ADMIN login session — see api/_admin-login/. CSRF is the
 * session's own csrf_token, carried as a hidden form field (plain HTML
 * form wizard, not fetch()-based — usable from an iPad/Safari with no JS).
 *
 * Uses ONLY the normal runtime connection (Amor\Api\Database::pdo()) — FG
 * verification/packing is ordinary INSERT/UPDATE/SELECT against
 * fg_batch/fg_batch_source/fg_item (0001 schema, extended additively by
 * migration 0005) plus stock_ledger/stock_balance (the sole stock-truth
 * write in this phase) and a read-only join against Phase 3's
 * production_run/production_item via Amor\Api\Fg\FgTargetService. This
 * page NEVER writes to production_run/production_item, po_batch/po_item,
 * delivery_order, or shipment — FG here only reads submitted Production
 * and writes its own fg_batch/fg_item + stock_ledger.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Fg\FgService;

$markerFile = __DIR__ . '/.disabled';
if (is_file($markerFile) || Config::get('FG_UAT_ENABLED', true) === false) {
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
/** Badge color for an FG/Packing displayStatusCode — text label always carries the meaning, color is a secondary cue only. */
function fgStatusBadgeClass(string $code): string
{
    return [
        'belum_diverifikasi' => 'b-neutral', 'sebagian_terverifikasi' => 'b-warn', 'sesuai_produksi' => 'b-ok',
        'selisih' => 'b-warn', 'melebihi_produksi' => 'b-bad',
        'belum_dipacking' => 'b-neutral', 'sebagian_dipacking' => 'b-warn', 'selesai_dipacking' => 'b-ok',
        'packing_melebihi_fg' => 'b-bad',
    ][$code] ?? 'b-neutral';
}

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
        . '<h1>Akses ditolak</h1><p>Akun Anda login, tapi bukan ADMIN. Halaman UAT sementara ini dibatasi ADMIN saja — '
        . 'role PPIC/PRODUCTION tetap bisa memakai API JSON-nya langsung.</p></body></html>';
    exit;
}

$csrfToken = (string) $_SESSION['csrf_token'];
$userId = Auth::currentUserId();
$resolvedByLabel = (string) $_SESSION['username'];
$pdo = Database::pdo(); // runtime (DML-only) connection — see file header

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';
$postedCsrf = (string) ($_POST['csrf'] ?? '');
$actionResult = null;
$redirectTo = null;

if ($action !== '' && !hash_equals($csrfToken, $postedCsrf)) {
    $actionResult = ['ok' => false, 'title' => 'CSRF token tidak cocok', 'message' => 'Muat ulang halaman dan coba lagi.'];
    $action = '';
}

// ---------------------------------------------------------------------
// Mutating actions — every write goes through FgService inside a fresh
// transaction, exactly like _production-uat/index.php.
// ---------------------------------------------------------------------
if ($action === 'create_draft') {
    $tanggal = (string) ($_POST['tanggal'] ?? '');
    $factoryId = (int) ($_POST['factoryId'] ?? 0);
    try {
        $dto = Database::transaction(function ($txPdo) use ($tanggal, $factoryId, $userId) {
            return (new FgService($txPdo))->createDraft($tanggal, $factoryId, $userId);
        });
        $redirectTo = '?batchId=' . $dto['fgBatchId'];
        $actionResult = ['ok' => true, 'title' => 'Draft FG dibuka', 'message' => "Draft FG #{$dto['fgBatchId']} siap diverifikasi."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal membuka draft FG', 'message' => $e->getMessage()];
    }
}

if ($action === 'save_draft' || $action === 'submit_batch') {
    $batchId = (int) ($_POST['batchId'] ?? 0);
    $expectedVersion = (int) ($_POST['expectedVersion'] ?? 0);
    $items = [];
    foreach ((array) ($_POST['fgVerified'] ?? []) as $productId => $val) {
        $items[] = [
            'productId' => (int) $productId,
            'fgVerified' => (float) str_replace(',', '.', (string) $val),
            'packed' => (float) str_replace(',', '.', (string) ($_POST['packed'][$productId] ?? '0')),
            'notes' => (string) ($_POST['notes'][$productId] ?? ''),
        ];
    }
    try {
        if ($action === 'save_draft') {
            $refreshSource = isset($_POST['refreshSource']);
            $dto = Database::transaction(function ($txPdo) use ($batchId, $expectedVersion, $items, $refreshSource, $userId) {
                return (new FgService($txPdo))->patchDraft($batchId, $expectedVersion, $items, $refreshSource, $userId, null);
            });
            $actionResult = ['ok' => true, 'title' => 'Draft FG disimpan', 'message' => "Tersimpan (versi {$dto['version']})."];
        } else {
            $dto = Database::transaction(function ($txPdo) use ($batchId, $expectedVersion, $items, $userId) {
                $svc = new FgService($txPdo);
                $afterSave = $items !== [] ? $svc->patchDraft($batchId, $expectedVersion, $items, false, $userId, null) : null;
                $v = $afterSave !== null ? $afterSave['version'] : $expectedVersion;
                return $svc->submit($batchId, $v, $userId, null);
            });
            $extra = '';
            if ($dto['sourceInconsistency']) {
                $extra .= ' PERINGATAN: sumber Produksi berubah sejak draft FG dibuat/disegarkan — periksa "Ketidaksesuaian Sumber" di bawah.';
            }
            $actionResult = ['ok' => true, 'title' => 'FG disubmit', 'message' => "Status: submitted.{$extra}"];
        }
        $redirectTo = '?batchId=' . $batchId;
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal menyimpan', 'message' => $e->getMessage()];
    }
}

if ($action === 'reopen_batch') {
    $batchId = (int) ($_POST['batchId'] ?? 0);
    $expectedVersion = (int) ($_POST['expectedVersion'] ?? 0);
    $reason = (string) ($_POST['reason'] ?? '');
    try {
        Auth::requireRole('ADMIN', 'PPIC');
        $dto = Database::transaction(function ($txPdo) use ($batchId, $expectedVersion, $reason, $userId) {
            return (new FgService($txPdo))->reopen($batchId, $expectedVersion, $reason, $userId, null);
        });
        $actionResult = ['ok' => true, 'title' => 'Dibuka kembali', 'message' => "Status sekarang: reopened (versi {$dto['version']})."];
        $redirectTo = '?batchId=' . $batchId;
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal membuka kembali', 'message' => $e->getMessage()];
    }
}

if ($action === 'disable') {
    @file_put_contents($markerFile, 'disabled at ' . gmdate('c') . ' by ' . $resolvedByLabel . "\n");
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>FG UAT wizard dinonaktifkan</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:2rem auto;">'
        . '<h1 style="color:#080;">Sudah dinonaktifkan</h1><p>Halaman ini sekarang mengembalikan 404.</p>'
        . '</body></html>';
    exit;
}

if ($redirectTo !== null && $actionResult !== null && $actionResult['ok']) {
    $_SESSION['fg_uat_flash'] = $actionResult;
    header('Location: ' . $redirectTo);
    exit;
}
if (isset($_SESSION['fg_uat_flash'])) {
    $actionResult = $_SESSION['fg_uat_flash'];
    unset($_SESSION['fg_uat_flash']);
}

// ---------------------------------------------------------------------
// Read state for the current screen.
// ---------------------------------------------------------------------
$factories = $pdo->query('SELECT factory_id, name FROM factory ORDER BY name')->fetchAll();
$divisions = $pdo->query(
    "SELECT d.division_id, d.name, d.factory_id FROM division d WHERE d.is_verification = 0 ORDER BY d.name"
)->fetchAll();

$tanggal = (string) ($_GET['tanggal'] ?? '');
$factoryIdParam = isset($_GET['factoryId']) ? (int) $_GET['factoryId'] : null;
$divisionIdParam = isset($_GET['divisionId']) && $_GET['divisionId'] !== '' ? (int) $_GET['divisionId'] : null;
$batchIdParam = isset($_GET['batchId']) ? (int) $_GET['batchId'] : null;

$targetView = null;
$batchView = null;
$viewError = null;
$service = new FgService($pdo);

if ($batchIdParam !== null) {
    try {
        $batchView = $service->getBatch($batchIdParam);
    } catch (\Throwable $e) {
        $viewError = $e->getMessage();
    }
} elseif ($tanggal !== '' && $factoryIdParam !== null) {
    try {
        $existing = $pdo->prepare('SELECT fg_batch_id FROM fg_batch WHERE tanggal = ? AND factory_id = ?');
        $existing->execute([$tanggal, $factoryIdParam]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            $batchView = $service->getBatch((int) $existingId);
        } else {
            $targetView = $service->loadTarget($tanggal, $factoryIdParam, $divisionIdParam);
        }
    } catch (\Throwable $e) {
        $viewError = $e->getMessage();
    }
}

$history = [];
if ($tanggal !== '' || $batchView !== null) {
    $histTanggal = $batchView['tanggal'] ?? $tanggal;
    $histFactoryId = $batchView['factoryId'] ?? $factoryIdParam;
    $sql = "SELECT h.* FROM audit_log h
            INNER JOIN fg_batch b ON CAST(h.record_key AS UNSIGNED) = b.fg_batch_id
            WHERE h.record_type = 'fg_batch' AND b.tanggal = ?" . ($histFactoryId !== null ? ' AND b.factory_id = ?' : '');
    $stmt = $pdo->prepare($sql . ' ORDER BY h.event_at DESC LIMIT 30');
    $stmt->execute($histFactoryId !== null ? [$histTanggal, $histFactoryId] : [$histTanggal]);
    $history = $stmt->fetchAll();
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Amor Factory — FG/Packing (Phase 4)</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#1a1a1a;line-height:1.5;}
h1{font-size:1.4rem;} h2{font-size:1.1rem;margin-top:2rem;border-bottom:2px solid #eee;padding-bottom:.3rem;}
.box{border:1px solid #ddd;border-radius:8px;padding:1rem;margin:1rem 0;}
.warn{background:#fff3cd;border:1px solid #f0c36d;padding:.75rem;border-radius:6px;}
.result-ok{background:#e6ffe6;border:1px solid #8c8;padding:.75rem;border-radius:6px;}
.result-error{background:#ffe6e6;border:1px solid #e99;padding:.75rem;border-radius:6px;}
button{padding:.5rem 1.2rem;background:#0a5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:bold;}
button.secondary{background:#888;} button.danger{background:#b00;}
input[type=text],input[type=date],input[type=number],select{padding:.4rem;font-size:1em;}
table{border-collapse:collapse;width:100%;margin:.5rem 0;}
td,th{text-align:left;padding:.3rem .6rem;border-bottom:1px solid #eee;font-size:.85em;vertical-align:middle;}
.badge{display:inline-block;padding:1px 8px;border-radius:10px;font-size:.8em;font-weight:bold;color:#fff;}
.b-ok{background:#080;} .b-bad{background:#b00;} .b-info{background:#06c;} .b-warn{background:#e90;} .b-neutral{background:#888;}
form.inline{display:inline;}
label{display:block;margin:.5rem 0;}
.summary-grid{display:flex;gap:1rem;flex-wrap:wrap;margin:.5rem 0;}
.summary-cell{background:#f4f4f4;border-radius:8px;padding:.6rem 1rem;min-width:140px;}
.summary-cell .n{font-size:1.3em;font-weight:bold;}
</style>
</head>
<body>

<h1>Amor Factory — FG / Packing (Phase 4 Fast-Track)</h1>
<p>Login sebagai: <strong><?= esc($resolvedByLabel) ?></strong> &middot; <a href="../_admin-login/">logout</a>.
Hanya Produksi berstatus <strong>SUBMITTED</strong> yang bisa jadi sumber FG baru. FG TIDAK PERNAH mengubah data
Produksi/PO. Belum ada DO, Pengiriman, Invoice, Pembayaran, atau Retur di sini.</p>

<?php if ($actionResult !== null): ?>
<div class="<?= $actionResult['ok'] ? 'result-ok' : 'result-error' ?>">
  <strong><?= esc($actionResult['title']) ?></strong>
  <p><?= esc($actionResult['message']) ?></p>
</div>
<?php endif; ?>
<?php if ($viewError !== null): ?>
<div class="result-error"><strong>Gagal memuat</strong><p><?= esc($viewError) ?></p></div>
<?php endif; ?>

<h2>1-3. Pilih Tanggal, Pabrik &amp; Filter Divisi (opsional)</h2>
<div class="box">
  <form method="get">
    <label>Tanggal <input type="date" name="tanggal" value="<?= esc($tanggal) ?>" required></label>
    <label>Pabrik
      <select name="factoryId" required>
        <option value="">— pilih pabrik —</option>
        <?php foreach ($factories as $f): ?>
        <option value="<?= (int) $f['factory_id'] ?>" <?= $factoryIdParam === (int) $f['factory_id'] ? 'selected' : '' ?>><?= esc($f['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Filter Divisi Sumber (opsional, hanya utk pratinjau sebelum draft dibuat)
      <select name="divisionId">
        <option value="">— semua divisi pabrik ini —</option>
        <?php foreach ($divisions as $d): if ($factoryIdParam !== null && (int) $d['factory_id'] !== $factoryIdParam) continue; ?>
        <option value="<?= (int) $d['division_id'] ?>" <?= $divisionIdParam === (int) $d['division_id'] ? 'selected' : '' ?>><?= esc($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit">4. Muat Produksi Submitted</button>
  </form>
</div>

<?php if ($targetView !== null): ?>
<h2>4-5. Produksi Submitted yang Tersedia — <?= esc($targetView['tanggal']) ?> &middot; <?= esc($targetView['factoryName']) ?></h2>
<div class="box">
  <div class="summary-grid">
    <div class="summary-cell">Jumlah Produk<br><span class="n"><?= $targetView['summary']['productCount'] ?></span></div>
    <div class="summary-cell">Divisi Submitted<br><span class="n"><?= $targetView['summary']['divisionCount'] ?></span></div>
  </div>
  <table><tr><th>Divisi (SUBMITTED)</th><th>Versi</th></tr>
  <?php foreach ($targetView['eligibleRuns'] as $r): ?>
  <tr><td><?= esc($r['divisionName']) ?></td><td><?= (int) $r['version'] ?></td></tr>
  <?php endforeach; ?></table>
  <table><tr><th>Produk</th><th>Production Actual</th></tr>
  <?php foreach ($targetView['items'] as $it): ?>
  <tr><td><?= esc($it['productName']) ?></td><td><strong><?= fmtNum($it['actual']) ?></strong></td></tr>
  <?php endforeach; ?>
  </table>
  <?php if ($targetView['items'] === []): ?>
  <p style="color:#666;">Belum ada Produksi SUBMITTED untuk tanggal &amp; pabrik ini — buka
  <a href="../_production-uat/">../_production-uat/</a> dulu dan submit produksinya.</p>
  <?php else: ?>
  <form method="post" style="margin-top:1rem;">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="create_draft">
    <input type="hidden" name="tanggal" value="<?= esc($targetView['tanggal']) ?>">
    <input type="hidden" name="factoryId" value="<?= (int) $targetView['factoryId'] ?>">
    <button type="submit">6. Buat / Buka Draft FG</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($batchView !== null): ?>
<?php
  $editable = in_array($batchView['status'], ['draft', 'reopened'], true);
  $statusBadge = ['draft' => 'b-info', 'submitted' => 'b-ok', 'reopened' => 'b-warn'][$batchView['status']] ?? 'b-info';
?>
<h2>6-11. Draft FG #<?= (int) $batchView['fgBatchId'] ?> — <?= esc($batchView['tanggal']) ?> &middot; <?= esc($batchView['factoryName']) ?></h2>
<div class="box">
  <p>Status: <span class="badge <?= $statusBadge ?>"><?= esc(strtoupper($batchView['status'])) ?></span> &middot; Versi: <?= (int) $batchView['version'] ?>
  <?php if ($batchView['submittedAt']): ?> &middot; Disubmit: <?= esc((string) $batchView['submittedAt']) ?><?php endif; ?>
  <?php if ($batchView['status'] === 'reopened'): ?> &middot; Alasan dibuka kembali: <?= esc((string) $batchView['reopenReason']) ?><?php endif; ?></p>

  <?php if ($batchView['sourceInconsistency']): ?>
  <div class="warn"><strong>Ketidaksesuaian Sumber Produksi</strong> — salah satu Produksi sumber batch FG ini sudah
  berubah versi atau dibuka kembali (reopened) SETELAH FG ini dibuat/disegarkan. Data FG yang sudah diisi TIDAK
  dihapus atau diubah otomatis. Periksa produksinya kembali sebelum melanjutkan.
  <table style="margin-top:.4rem;"><tr><th>Production Run</th><th>Versi Tercatat</th><th>Versi Sekarang</th><th>Status Sekarang</th></tr>
  <?php foreach ($batchView['sourceInconsistencyDetails'] as $s): ?>
  <tr><td>#<?= (int) $s['productionRunId'] ?></td><td><?= (int) $s['storedVersion'] ?></td><td><?= (int) $s['currentVersion'] ?></td><td><?= esc($s['currentStatus']) ?></td></tr>
  <?php endforeach; ?></table>
  </div>
  <?php endif; ?>

  <div class="summary-grid">
    <div class="summary-cell">Production Actual<br><span class="n"><?= fmtNum($batchView['summary']['productionActualTotal']) ?></span></div>
    <div class="summary-cell">FG Verified<br><span class="n"><?= fmtNum($batchView['summary']['fgVerifiedTotal']) ?></span></div>
    <div class="summary-cell">Packed<br><span class="n"><?= fmtNum($batchView['summary']['packedTotal']) ?></span></div>
    <div class="summary-cell">Variance<br><span class="n" style="<?= $batchView['summary']['varianceTotal'] != 0 ? 'color:#b00;' : '' ?>"><?= fmtNum($batchView['summary']['varianceTotal']) ?></span></div>
  </div>
  <div class="summary-grid">
    <div class="summary-cell">Belum Diverifikasi<br><span class="n"><?= $batchView['summary']['jumlahBelumDiverifikasi'] ?></span></div>
    <div class="summary-cell">Sebagian Terverifikasi<br><span class="n"><?= $batchView['summary']['jumlahSebagianTerverifikasi'] ?></span></div>
    <div class="summary-cell">Sesuai Produksi<br><span class="n"><?= $batchView['summary']['jumlahSesuaiProduksi'] ?></span></div>
    <div class="summary-cell">Selisih<br><span class="n"><?= $batchView['summary']['jumlahSelisih'] ?></span></div>
    <div class="summary-cell">Melebihi Produksi<br><span class="n" style="<?= $batchView['summary']['jumlahMelebihiProduksi'] > 0 ? 'color:#b00;' : '' ?>"><?= $batchView['summary']['jumlahMelebihiProduksi'] ?></span></div>
  </div>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="batchId" value="<?= (int) $batchView['fgBatchId'] ?>">
    <input type="hidden" name="expectedVersion" value="<?= (int) $batchView['version'] ?>">
    <table>
      <tr><th>Produk</th><th>Production Actual</th><th>FG Verified</th><th>Variance</th><th>Packed</th><th>Available</th><th>FG Status</th><th>Packing Status</th><th>Catatan</th></tr>
      <?php foreach ($batchView['items'] as $it): ?>
      <tr>
        <td><?= esc($it['productName']) ?></td>
        <td><?= fmtNum($it['productionActualSnapshot']) ?></td>
        <td><?php if ($editable): ?><input type="number" step="0.01" min="0" name="fgVerified[<?= (int) $it['productId'] ?>]" value="<?= fmtNum($it['fgVerified']) ?>" style="width:5.5rem;"><?php else: ?><?= fmtNum($it['fgVerified']) ?><?php endif; ?></td>
        <td><?= fmtNum($it['variance']) ?></td>
        <td><?php if ($editable): ?><input type="number" step="0.01" min="0" name="packed[<?= (int) $it['productId'] ?>]" value="<?= fmtNum($it['packed']) ?>" style="width:5.5rem;"><?php else: ?><?= fmtNum($it['packed']) ?><?php endif; ?></td>
        <td><?= fmtNum($it['available']) ?></td>
        <td><span class="badge <?= fgStatusBadgeClass($it['fgStatusCode']) ?>"><?= esc($it['fgStatusLabel']) ?></span></td>
        <td><span class="badge <?= fgStatusBadgeClass($it['packingStatusCode']) ?>"><?= esc($it['packingStatusLabel']) ?></span></td>
        <td><?php if ($editable): ?><input type="text" name="notes[<?= (int) $it['productId'] ?>]" value="<?= esc((string) $it['notes']) ?>" style="width:7rem;"><?php else: ?><?= esc((string) $it['notes']) ?><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($editable): ?>
    <label style="display:inline-block;"><input type="checkbox" name="refreshSource" value="1"> Segarkan sumber dari Produksi SUBMITTED terbaru (tidak mengubah angka yang sudah diisi)</label>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.6rem;">
      <button type="submit" name="action" value="save_draft">7-8. Simpan Draft</button>
      <button type="submit" name="action" value="submit_batch">9. Submit FG</button>
    </div>
    <?php else: ?>
    <p style="color:#666;">Sudah disubmit — tidak bisa diedit langsung. Gunakan "Buka Kembali" di bawah kalau perlu koreksi.</p>
    <?php endif; ?>
  </form>

  <?php if ($batchView['status'] === 'submitted'): ?>
  <form method="post" style="margin-top:1rem;" onsubmit="return confirm('Buka kembali dokumen FG ini untuk koreksi?');">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="reopen_batch">
    <input type="hidden" name="batchId" value="<?= (int) $batchView['fgBatchId'] ?>">
    <input type="hidden" name="expectedVersion" value="<?= (int) $batchView['version'] ?>">
    <label>Alasan Buka Kembali (wajib) <input type="text" name="reason" required style="width:100%;"></label>
    <button type="submit" class="secondary">10-11. Buka Kembali (Reopen)</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<h2>12. Riwayat</h2>
<div class="box">
  <?php if ($history === []): ?>
  <p style="color:#666;">Belum ada riwayat untuk konteks ini.</p>
  <?php else: ?>
  <table><tr><th>Waktu</th><th>Aksi</th><th>User</th><th>Status</th></tr>
  <?php foreach ($history as $h): ?>
  <tr><td><?= esc((string) $h['event_at']) ?></td><td><?= esc($h['action']) ?></td><td><?= $h['user_id'] !== null ? (int) $h['user_id'] : '-' ?></td><td><?= esc($h['status']) ?></td></tr>
  <?php endforeach; ?></table>
  <?php endif; ?>
</div>

<h2>Selesai</h2>
<div class="box">
  <form method="post" onsubmit="return confirm('Nonaktifkan halaman UAT FG/Packing ini sekarang?');">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="disable">
    <button type="submit" class="danger">Nonaktifkan FG/Packing UAT Wizard</button>
  </form>
</div>

</body>
</html>
