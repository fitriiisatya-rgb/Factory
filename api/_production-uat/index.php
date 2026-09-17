<?php

declare(strict_types=1);

/**
 * Amor Factory — Phase 3 fast-track Production/SPK actual UAT wizard.
 *
 * TEMPORARY, like api/_import-po/ and api/_import-master/ were for their
 * phases. Delete this whole directory once Phase 3 is reviewed and accepted.
 *
 * Requires a real ADMIN login session — see api/_admin-login/. CSRF is the
 * session's own csrf_token, carried as a hidden form field (plain HTML form
 * wizard, not fetch()-based — usable from an iPad/Safari with no JS).
 *
 * Uses ONLY the normal runtime connection (Amor\Api\Database::pdo()) —
 * production actual is ordinary INSERT/UPDATE/SELECT against
 * production_run/production_item (0001 schema, extended additively by
 * migration 0004) plus a read-only join against the Phase 2 PO tables via
 * Amor\Api\Production\ProductionTargetService. This page NEVER writes to
 * po_batch/po_item/po_store_item — production actual must never mutate PO
 * target (task rule). No hard delete anywhere on this page.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Production\ProductionService;

$markerFile = __DIR__ . '/.disabled';
if (is_file($markerFile) || Config::get('PRODUCTION_UAT_ENABLED', true) === false) {
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
/** Badge color for a product line's displayStatusCode — text label always carries the meaning, color is a secondary cue only. */
function productionStatusBadgeClass(string $code): string
{
    return ['not_produced' => 'b-neutral', 'below_target' => 'b-warn', 'on_target' => 'b-ok', 'overproduction' => 'b-bad'][$code] ?? 'b-neutral';
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
// Mutating actions — every write goes through ProductionService inside a
// fresh transaction, exactly like _import-po/index.php's confirm_import.
// ---------------------------------------------------------------------
if ($action === 'create_draft') {
    $tanggal = (string) ($_POST['tanggal'] ?? '');
    $divisionId = (int) ($_POST['divisionId'] ?? 0);
    try {
        $dto = Database::transaction(function ($txPdo) use ($tanggal, $divisionId, $userId) {
            return (new ProductionService($txPdo))->createDraft($tanggal, $divisionId, $userId);
        });
        $redirectTo = '?runId=' . $dto['productionRunId'];
        $actionResult = ['ok' => true, 'title' => 'Draft dibuka', 'message' => "Draft produksi #{$dto['productionRunId']} siap diisi."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal membuka draft', 'message' => $e->getMessage()];
    }
}

if ($action === 'save_draft' || $action === 'submit_run') {
    $runId = (int) ($_POST['runId'] ?? 0);
    $expectedVersion = (int) ($_POST['expectedVersion'] ?? 0);
    $items = [];
    foreach ((array) ($_POST['actual'] ?? []) as $productId => $val) {
        $items[] = [
            'productId' => (int) $productId,
            'actualQty' => (float) str_replace(',', '.', (string) $val),
            'notes' => (string) ($_POST['notes'][$productId] ?? ''),
        ];
    }
    try {
        if ($action === 'save_draft') {
            $refreshTargets = isset($_POST['refreshTargets']);
            $dto = Database::transaction(function ($txPdo) use ($runId, $expectedVersion, $items, $refreshTargets, $userId) {
                return (new ProductionService($txPdo))->patchDraft($runId, $expectedVersion, $items, $refreshTargets, $userId, null);
            });
            $actionResult = ['ok' => true, 'title' => 'Draft disimpan', 'message' => "Tersimpan (versi {$dto['version']})."];
        } else {
            // Submit always applies whatever is currently in the "actual" inputs
            // first (same values already on screen), then submits — one click,
            // matching the UAT flow's step 9, never a second hidden save.
            $dto = Database::transaction(function ($txPdo) use ($runId, $expectedVersion, $items, $userId) {
                $svc = new ProductionService($txPdo);
                $afterSave = $items !== [] ? $svc->patchDraft($runId, $expectedVersion, $items, false, $userId, null) : null;
                $v = $afterSave !== null ? $afterSave['version'] : $expectedVersion;
                return $svc->submit($runId, $v, $userId, null);
            });
            $warn = $dto['submitWarnings'];
            $extra = '';
            if (!empty($warn['targetChanged'])) $extra .= ' PERINGATAN: target PO berubah sejak draft dibuat untuk ' . count($warn['targetChanged']) . ' produk.';
            if (!empty($warn['overproduction'])) $extra .= ' PERINGATAN: overproduction pada ' . count($warn['overproduction']) . ' produk.';
            $actionResult = ['ok' => true, 'title' => 'Produksi disubmit', 'message' => "Status: submitted.{$extra}"];
        }
        $redirectTo = '?runId=' . $runId;
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal menyimpan', 'message' => $e->getMessage()];
    }
}

if ($action === 'reopen_run') {
    $runId = (int) ($_POST['runId'] ?? 0);
    $expectedVersion = (int) ($_POST['expectedVersion'] ?? 0);
    $reason = (string) ($_POST['reason'] ?? '');
    try {
        Auth::requireRole('ADMIN', 'PPIC');
        $dto = Database::transaction(function ($txPdo) use ($runId, $expectedVersion, $reason, $userId) {
            return (new ProductionService($txPdo))->reopen($runId, $expectedVersion, $reason, $userId, null);
        });
        $actionResult = ['ok' => true, 'title' => 'Dibuka kembali', 'message' => "Status sekarang: reopened (versi {$dto['version']})."];
        $redirectTo = '?runId=' . $runId;
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal membuka kembali', 'message' => $e->getMessage()];
    }
}

if ($action === 'disable') {
    @file_put_contents($markerFile, 'disabled at ' . gmdate('c') . ' by ' . $resolvedByLabel . "\n");
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Production UAT wizard dinonaktifkan</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:2rem auto;">'
        . '<h1 style="color:#080;">Sudah dinonaktifkan</h1><p>Halaman ini sekarang mengembalikan 404.</p>'
        . '</body></html>';
    exit;
}

if ($redirectTo !== null && $actionResult !== null && $actionResult['ok']) {
    $_SESSION['production_uat_flash'] = $actionResult;
    header('Location: ' . $redirectTo);
    exit;
}
if (isset($_SESSION['production_uat_flash'])) {
    $actionResult = $_SESSION['production_uat_flash'];
    unset($_SESSION['production_uat_flash']);
}

// ---------------------------------------------------------------------
// Read state for the current screen — steps 1-3 (date/factory/division)
// come from the query string so the page is a plain bookmarkable GET.
// ---------------------------------------------------------------------
$divisions = $pdo->query(
    "SELECT d.division_id, d.name, d.factory_id, f.name AS factory_name
     FROM division d INNER JOIN factory f ON f.factory_id = d.factory_id
     WHERE d.is_verification = 0 ORDER BY f.name, d.name"
)->fetchAll();
// Finishgood & Packing divisions (is_verification=1) are intentionally
// excluded above — see ProductionService::requireProductionScopedDivision's
// docblock: that workflow is receiving-verification, not Phase 3's
// production actual, per the audited legacy isFG branch.

$tanggal = (string) ($_GET['tanggal'] ?? '');
$divisionIdParam = isset($_GET['divisionId']) ? (int) $_GET['divisionId'] : null;
$runIdParam = isset($_GET['runId']) ? (int) $_GET['runId'] : null;

$targetView = null;
$runView = null;
$viewError = null;
$service = new ProductionService($pdo);

if ($runIdParam !== null) {
    try {
        $runView = $service->getRun($runIdParam);
    } catch (\Throwable $e) {
        $viewError = $e->getMessage();
    }
} elseif ($tanggal !== '' && $divisionIdParam !== null) {
    try {
        $existing = $pdo->prepare('SELECT production_run_id FROM production_run WHERE tanggal = ? AND division_id = ?');
        $existing->execute([$tanggal, $divisionIdParam]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            $runView = $service->getRun((int) $existingId);
        } else {
            $targetView = $service->loadTarget($tanggal, $divisionIdParam);
        }
    } catch (\Throwable $e) {
        $viewError = $e->getMessage();
    }
}

$history = [];
if ($tanggal !== '' || $runView !== null) {
    $histTanggal = $runView['tanggal'] ?? $tanggal;
    $histDivisionId = $runView['divisionId'] ?? $divisionIdParam;
    $sql = "SELECT h.* FROM audit_log h
            INNER JOIN production_run r ON CAST(h.record_key AS UNSIGNED) = r.production_run_id
            WHERE h.record_type = 'production_run' AND r.tanggal = ?" . ($histDivisionId !== null ? ' AND r.division_id = ?' : '');
    $stmt = $pdo->prepare($sql . ' ORDER BY h.event_at DESC LIMIT 30');
    $stmt->execute($histDivisionId !== null ? [$histTanggal, $histDivisionId] : [$histTanggal]);
    $history = $stmt->fetchAll();
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Amor Factory — Production/SPK Actual (Phase 3)</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:1000px;margin:2rem auto;padding:0 1rem;color:#1a1a1a;line-height:1.5;}
h1{font-size:1.4rem;} h2{font-size:1.1rem;margin-top:2rem;border-bottom:2px solid #eee;padding-bottom:.3rem;}
.box{border:1px solid #ddd;border-radius:8px;padding:1rem;margin:1rem 0;}
.warn{background:#fff3cd;border:1px solid #f0c36d;padding:.75rem;border-radius:6px;}
.result-ok{background:#e6ffe6;border:1px solid #8c8;padding:.75rem;border-radius:6px;}
.result-error{background:#ffe6e6;border:1px solid #e99;padding:.75rem;border-radius:6px;}
button{padding:.5rem 1.2rem;background:#0a5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:bold;}
button.secondary{background:#888;} button.danger{background:#b00;}
input[type=text],input[type=date],input[type=number],select{padding:.4rem;font-size:1em;}
table{border-collapse:collapse;width:100%;margin:.5rem 0;}
td,th{text-align:left;padding:.3rem .6rem;border-bottom:1px solid #eee;font-size:.9em;vertical-align:middle;}
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

<h1>Amor Factory — Production / SPK Actual (Phase 3 Fast-Track)</h1>
<p>Login sebagai: <strong><?= esc($resolvedByLabel) ?></strong> &middot; <a href="../_admin-login/">logout</a>.
Halaman ini TIDAK PERNAH mengubah target PO — target selalu dibaca langsung dan hidup dari modul PO Phase 2.
Belum ada verifikasi FG, Packing, DO, Pengiriman, Invoice, Pembayaran, atau Retur di sini.</p>

<?php if ($actionResult !== null): ?>
<div class="<?= $actionResult['ok'] ? 'result-ok' : 'result-error' ?>">
  <strong><?= esc($actionResult['title']) ?></strong>
  <p><?= esc($actionResult['message']) ?></p>
</div>
<?php endif; ?>
<?php if ($viewError !== null): ?>
<div class="result-error"><strong>Gagal memuat</strong><p><?= esc($viewError) ?></p></div>
<?php endif; ?>

<h2>1-3. Pilih Tanggal &amp; Divisi</h2>
<div class="box">
  <form method="get">
    <label>Tanggal Produksi <input type="date" name="tanggal" value="<?= esc($tanggal) ?>" required></label>
    <label>Divisi (Pabrik otomatis dari divisi)
      <select name="divisionId" required>
        <option value="">— pilih divisi —</option>
        <?php $curFactory = null; foreach ($divisions as $d): if ($curFactory !== $d['factory_name']): if ($curFactory !== null) echo '</optgroup>'; $curFactory = $d['factory_name']; echo '<optgroup label="' . esc($curFactory) . '">'; endif; ?>
        <option value="<?= (int) $d['division_id'] ?>" <?= $divisionIdParam === (int) $d['division_id'] ? 'selected' : '' ?>><?= esc($d['name']) ?></option>
        <?php endforeach; if ($curFactory !== null) echo '</optgroup>'; ?>
      </select>
    </label>
    <p style="color:#666;font-size:.85em;">Divisi Finishgood &amp; Packing tidak ditampilkan di sini — itu bagian dari
    modul FG/Packing terpisah di masa depan, bukan produksi aktual Phase 3.</p>
    <button type="submit">Muat Target dari PO</button>
  </form>
</div>

<?php if ($targetView !== null): ?>
<h2>4-5. Target dari PO — <?= esc($targetView['tanggal']) ?> &middot; <?= esc($targetView['divisionName']) ?> (<?= esc($targetView['factoryName']) ?>)</h2>
<div class="box">
  <div class="summary-grid">
    <div class="summary-cell">Target Produksi<br><span class="n"><?= fmtNum($targetView['summary']['targetProduksi']) ?></span></div>
    <div class="summary-cell">Jumlah Produk (baris)<br><span class="n"><?= $targetView['summary']['productCount'] ?></span></div>
  </div>
  <table><tr><th>Produk</th><th>PO Awal</th><th>PO Revisi</th><th>Target (Awal+Revisi)</th></tr>
  <?php foreach ($targetView['items'] as $it): ?>
  <tr><td><?= esc($it['productName']) ?></td><td><?= fmtNum($it['poAwal']) ?></td><td><?= fmtNum($it['poRevisi']) ?></td><td><strong><?= fmtNum($it['target']) ?></strong></td></tr>
  <?php endforeach; ?>
  </table>
  <?php if ($targetView['items'] === []): ?>
  <p style="color:#666;">Belum ada PO untuk tanggal &amp; divisi ini — upload PO dulu lewat <a href="../_import-po/">../_import-po/</a>.</p>
  <?php else: ?>
  <form method="post" style="margin-top:1rem;">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="create_draft">
    <input type="hidden" name="tanggal" value="<?= esc($targetView['tanggal']) ?>">
    <input type="hidden" name="divisionId" value="<?= (int) $targetView['divisionId'] ?>">
    <button type="submit">6. Buat / Buka Draft Produksi</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($runView !== null): ?>
<?php
  $editable = in_array($runView['status'], ['draft', 'reopened'], true);
  $statusBadge = ['draft' => 'b-info', 'submitted' => 'b-ok', 'reopened' => 'b-warn', 'not_started' => 'b-info', 'verified_fg' => 'b-ok'][$runView['status']] ?? 'b-info';
?>
<h2>6-9. Draft Produksi #<?= (int) $runView['productionRunId'] ?> — <?= esc($runView['tanggal']) ?> &middot; <?= esc($runView['divisionName']) ?> (<?= esc($runView['factoryName']) ?>)</h2>
<div class="box">
  <p>Status: <span class="badge <?= $statusBadge ?>"><?= esc(strtoupper($runView['status'])) ?></span> &middot; Versi: <?= (int) $runView['version'] ?>
  <?php if ($runView['submittedAt']): ?> &middot; Disubmit: <?= esc((string) $runView['submittedAt']) ?><?php endif; ?>
  <?php if ($runView['status'] === 'reopened'): ?> &middot; Alasan dibuka kembali: <?= esc((string) $runView['reopenReason']) ?><?php endif; ?></p>

  <?php if ($runView['targetChangedSincePoRevision']): ?>
  <div class="warn"><strong>Target berubah sejak draft dibuat</strong> — PO untuk tanggal/divisi ini sudah direvisi
  setelah draft ini dibuat/terakhir disegarkan. Kolom "Target (hidup)" di bawah sudah menunjukkan angka PO terbaru;
  angka aktual yang sudah diisi TIDAK hilang atau berubah. Centang "Segarkan target" saat menyimpan kalau ingin
  snapshot target ikut diperbarui.</div>
  <?php endif; ?>

  <div class="summary-grid">
    <div class="summary-cell">Target Produksi<br><span class="n"><?= fmtNum($runView['summary']['targetProduksi']) ?></span></div>
    <div class="summary-cell">Actual Produksi<br><span class="n"><?= fmtNum($runView['summary']['actualProduksi']) ?></span></div>
    <div class="summary-cell">Sisa Produksi<br><span class="n"><?= fmtNum($runView['summary']['sisaProduksi']) ?></span></div>
    <div class="summary-cell">Overproduction<br><span class="n" style="<?= $runView['summary']['overproduction'] > 0 ? 'color:#b00;' : '' ?>"><?= fmtNum($runView['summary']['overproduction']) ?></span></div>
  </div>
  <div class="summary-grid">
    <div class="summary-cell">Jumlah Belum Diproduksi<br><span class="n"><?= $runView['summary']['jumlahBelumDiproduksi'] ?></span></div>
    <div class="summary-cell">Jumlah Belum Sesuai Target<br><span class="n"><?= $runView['summary']['jumlahBelumSesuaiTarget'] ?></span></div>
    <div class="summary-cell">Jumlah Sesuai Target<br><span class="n"><?= $runView['summary']['jumlahSesuaiTarget'] ?></span></div>
    <div class="summary-cell">Jumlah Overproduction<br><span class="n" style="<?= $runView['summary']['jumlahOverproduction'] > 0 ? 'color:#b00;' : '' ?>"><?= $runView['summary']['jumlahOverproduction'] ?></span></div>
  </div>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="runId" value="<?= (int) $runView['productionRunId'] ?>">
    <input type="hidden" name="expectedVersion" value="<?= (int) $runView['version'] ?>">
    <table>
      <tr><th>Produk</th><th>Target (hidup)</th><th>Actual</th><th>Sisa</th><th>Overproduction</th><th>Status</th><th>Catatan</th></tr>
      <?php foreach ($runView['items'] as $it): ?>
      <tr>
        <td><?= esc($it['productName']) ?><?= $it['targetChangedSinceDraft'] ? ' <span class="badge b-warn">target berubah</span>' : '' ?></td>
        <td><?= fmtNum($it['liveTarget']) ?><?php if ($it['targetChangedSinceDraft']): ?><br><span style="color:#666;font-size:.85em;">snapshot draft: <?= fmtNum($it['targetSnapshot']) ?></span><?php endif; ?></td>
        <td><?php if ($editable): ?><input type="number" step="0.01" min="0" name="actual[<?= (int) $it['productId'] ?>]" value="<?= fmtNum($it['actual']) ?>" style="width:6rem;"><?php else: ?><?= fmtNum($it['actual']) ?><?php endif; ?></td>
        <td><?= fmtNum($it['remaining']) ?></td>
        <td><?= $it['overproduction'] > 0 ? '<span class="badge b-bad">' . fmtNum($it['overproduction']) . '</span>' : '0' ?></td>
        <td><span class="badge <?= productionStatusBadgeClass($it['displayStatusCode']) ?>"><?= esc($it['displayStatusLabel']) ?></span></td>
        <td><?php if ($editable): ?><input type="text" name="notes[<?= (int) $it['productId'] ?>]" value="<?= esc((string) $it['notes']) ?>" style="width:8rem;"><?php else: ?><?= esc((string) $it['notes']) ?><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($editable): ?>
    <label style="display:inline-block;"><input type="checkbox" name="refreshTargets" value="1"> Segarkan target dari PO terbaru (tidak mengubah angka actual)</label>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.6rem;">
      <button type="submit" name="action" value="save_draft">7-8. Simpan Draft</button>
      <button type="submit" name="action" value="submit_run">9. Submit Produksi</button>
    </div>
    <?php else: ?>
    <p style="color:#666;">Sudah disubmit — tidak bisa diedit langsung. Gunakan "Buka Kembali" di bawah kalau perlu koreksi.</p>
    <?php endif; ?>
  </form>

  <?php if ($runView['status'] === 'submitted'): ?>
  <form method="post" style="margin-top:1rem;" onsubmit="return confirm('Buka kembali dokumen produksi ini untuk koreksi?');">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="reopen_run">
    <input type="hidden" name="runId" value="<?= (int) $runView['productionRunId'] ?>">
    <input type="hidden" name="expectedVersion" value="<?= (int) $runView['version'] ?>">
    <label>Alasan Buka Kembali (wajib) <input type="text" name="reason" required style="width:100%;"></label>
    <button type="submit" class="secondary">Buka Kembali (Reopen)</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<h2>10. Riwayat</h2>
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
  <form method="post" onsubmit="return confirm('Nonaktifkan halaman UAT Production ini sekarang?');">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="disable">
    <button type="submit" class="danger">Nonaktifkan Production UAT Wizard</button>
  </form>
</div>

</body>
</html>
