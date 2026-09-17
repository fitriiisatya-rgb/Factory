<?php

declare(strict_types=1);

/**
 * Amor Factory — Phase 5 fast-track Draft DO / staged Shipment UAT wizard.
 *
 * TEMPORARY, like api/_import-po/, api/_production-uat/ and api/_fg-uat/
 * were for their phases. Delete this whole directory once Phase 5 is
 * reviewed and accepted.
 *
 * Requires a real ADMIN login session — see api/_admin-login/. CSRF is the
 * session's own csrf_token, carried as a hidden form field (plain HTML
 * form wizard, not fetch()-based — usable from an iPad/Safari with no JS).
 *
 * Uses ONLY the normal runtime connection (Amor\Api\Database::pdo()).
 * Draft DO is ordinary INSERT/UPDATE/SELECT against
 * delivery_order/delivery_order_item (0001 schema, extended additively by
 * migration 0006), sourced read-only from Phase 2's po_batch/po_item via
 * Amor\Api\Delivery\DoTargetService. Shipment is the ONLY stock-writing
 * action in this phase — it posts stock_ledger/stock_balance exactly like
 * FG's production_in write, but with event_type='shipment_out'. This page
 * never writes to po_batch/po_item, production_run/production_item, or
 * fg_batch/fg_item — Phase 5 here only reads them and writes its own
 * delivery_order/delivery_order_item + shipment/shipment_item + ledger.
 *
 * No persisted "draft shipment" row exists anywhere (task section 14) —
 * the shipment-preview action below is a pure read/compute against
 * ShipmentService::preview(), never a write; only "Konfirmasi KIRIM"
 * commits, via ShipmentService::ship() inside one transaction.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Delivery\DoRepository;
use Amor\Api\Delivery\DoService;
use Amor\Api\Delivery\ShipmentService;

$markerFile = __DIR__ . '/.disabled';
if (is_file($markerFile) || Config::get('DO_UAT_ENABLED', true) === false) {
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

function doStatusBadgeClass(string $status): string
{
    return ['draft' => 'b-info', 'preprinted' => 'b-warn', 'shipped' => 'b-ok', 'cancelled' => 'b-bad'][$status] ?? 'b-neutral';
}

function doStatusLabel(string $status): string
{
    return [
        'draft' => 'Draft', 'preprinted' => 'Preprinted', 'shipped' => 'Terkirim Penuh', 'cancelled' => 'Dibatalkan',
    ][$status] ?? strtoupper($status);
}

/** Item/DO progress badge — text label always carries the meaning, color is a secondary cue only. */
function itemStatusBadgeClass(string $code): string
{
    return ['belum_dikirim' => 'b-neutral', 'sebagian_dikirim' => 'b-warn', 'terkirim_penuh' => 'b-ok'][$code] ?? 'b-neutral';
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
$shipmentPreviewResult = null;
$postedShipmentGroup = null;
$postedShipmentItems = [];

if ($action !== '' && !hash_equals($csrfToken, $postedCsrf)) {
    $actionResult = ['ok' => false, 'title' => 'CSRF token tidak cocok', 'message' => 'Muat ulang halaman dan coba lagi.'];
    $action = '';
}

/** @return array<int,array{productId:int,actualQty:float,notes:?string}> */
function collectShipmentItemsFromPost(): array
{
    $items = [];
    foreach ((array) ($_POST['qty'] ?? []) as $productId => $val) {
        $qty = (float) str_replace(',', '.', (string) $val);
        if ($qty <= 0.0001) {
            continue;
        }
        $items[] = [
            'productId' => (int) $productId,
            'actualQty' => $qty,
            'notes' => (string) ($_POST['notes'][$productId] ?? ''),
        ];
    }
    return $items;
}

// ---------------------------------------------------------------------
// Mutating (and the read-only preview) actions — every write goes through
// DoService/ShipmentService inside a fresh transaction, exactly like
// _production-uat/index.php and _fg-uat/index.php.
// ---------------------------------------------------------------------
if ($action === 'generate_draft') {
    $tanggal = (string) ($_POST['tanggal'] ?? '');
    $storeId = (int) ($_POST['storeId'] ?? 0);
    try {
        $dto = Database::transaction(function ($txPdo) use ($tanggal, $storeId, $userId) {
            return (new DoService($txPdo))->createDraft($tanggal, $storeId, $userId);
        });
        $redirectTo = '?doId=' . $dto['doId'];
        $actionResult = ['ok' => true, 'title' => 'Draft DO siap', 'message' => "DO {$dto['docNo']} (#{$dto['doId']}) siap diproses."];
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal membuat/membuka draft DO', 'message' => $e->getMessage()];
    }
}

if ($action === 'generate_bulk') {
    $tanggal = (string) ($_POST['tanggal'] ?? '');
    $factoryId = (int) ($_POST['factoryId'] ?? 0);
    try {
        $summary = Database::transaction(function ($txPdo) use ($tanggal, $factoryId, $userId) {
            return (new DoService($txPdo))->generateBulk($tanggal, $factoryId, $userId);
        });
        $errText = $summary['errors'] !== [] ? ' Gagal: ' . count($summary['errors']) . ' toko (lihat detail di bawah).' : '';
        $actionResult = [
            'ok' => true, 'title' => 'Generate DO massal selesai',
            'message' => "Dibuat: {$summary['created']}, sudah ada: {$summary['alreadyExisted']}, dari {$summary['storesConsidered']} toko.{$errText}",
        ];
        if ($summary['errors'] !== []) {
            $actionResult['message'] .= ' ' . implode('; ', array_map(
                static fn ($e) => $e['storeName'] . ': ' . $e['message'],
                $summary['errors']
            ));
        }
        $redirectTo = '?tanggal=' . urlencode($tanggal) . '&factoryId=' . $factoryId;
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal generate DO massal', 'message' => $e->getMessage()];
    }
}

if ($action === 'preprint') {
    $doId = (int) ($_POST['doId'] ?? 0);
    $expectedVersion = (int) ($_POST['expectedVersion'] ?? 0);
    try {
        $dto = Database::transaction(function ($txPdo) use ($doId, $expectedVersion, $userId) {
            return (new DoService($txPdo))->preprint($doId, $expectedVersion, $userId, null);
        });
        $actionResult = ['ok' => true, 'title' => 'Ditandai Preprinted', 'message' => "Status sekarang: preprinted (versi {$dto['version']})."];
        $redirectTo = '?doId=' . $doId;
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal menandai preprinted', 'message' => $e->getMessage()];
    }
}

if ($action === 'refresh_po') {
    $doId = (int) ($_POST['doId'] ?? 0);
    $expectedVersion = (int) ($_POST['expectedVersion'] ?? 0);
    try {
        $dto = Database::transaction(function ($txPdo) use ($doId, $expectedVersion, $userId) {
            return (new DoService($txPdo))->refreshFromPo($doId, $expectedVersion, $userId, null);
        });
        $actionResult = ['ok' => true, 'title' => 'Disegarkan dari PO', 'message' => "Planned qty disegarkan dari PO terbaru (versi {$dto['version']})."];
        $redirectTo = '?doId=' . $doId;
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal menyegarkan dari PO', 'message' => $e->getMessage()];
    }
}

if ($action === 'cancel_do') {
    $doId = (int) ($_POST['doId'] ?? 0);
    $expectedVersion = (int) ($_POST['expectedVersion'] ?? 0);
    $reason = (string) ($_POST['reason'] ?? '');
    try {
        $dto = Database::transaction(function ($txPdo) use ($doId, $expectedVersion, $reason, $userId) {
            return (new DoService($txPdo))->cancel($doId, $expectedVersion, $reason, $userId, null);
        });
        $actionResult = ['ok' => true, 'title' => 'DO dibatalkan', 'message' => "Status sekarang: cancelled (versi {$dto['version']})."];
        $redirectTo = '?doId=' . $doId;
    } catch (\Throwable $e) {
        $actionResult = ['ok' => false, 'title' => 'Gagal membatalkan DO', 'message' => $e->getMessage()];
    }
}

if ($action === 'preview_shipment' || $action === 'ship_confirm') {
    $doId = (int) ($_POST['doId'] ?? 0);
    $postedShipmentGroup = (string) ($_POST['shipmentGroup'] ?? 'MAIN');
    $postedShipmentItems = collectShipmentItemsFromPost();

    if ($action === 'preview_shipment') {
        try {
            $service = new ShipmentService($pdo);
            $shipmentPreviewResult = $service->preview($doId, $postedShipmentItems);
        } catch (\Throwable $e) {
            $actionResult = ['ok' => false, 'title' => 'Gagal memuat pratinjau pengiriman', 'message' => $e->getMessage()];
        }
    } else {
        $expectedVersion = (int) ($_POST['expectedVersion'] ?? 0);
        try {
            $dto = Database::transaction(function ($txPdo) use ($doId, $expectedVersion, $postedShipmentGroup, $postedShipmentItems, $userId) {
                return (new ShipmentService($txPdo))->ship($doId, $expectedVersion, $postedShipmentGroup, $postedShipmentItems, $userId, null);
            });
            $extra = $dto['doFullyFulfilled']
                ? ' DO ini sekarang berstatus SHIPPED (terkirim penuh).'
                : " Sisa yang belum dikirim: {$dto['doTotalRemaining']}.";
            $actionResult = ['ok' => true, 'title' => 'Pengiriman #' . $dto['shipmentId'] . ' terkonfirmasi', 'message' => "Grup {$dto['shipmentGroup']}.{$extra}"];
            $redirectTo = '?doId=' . $doId;
        } catch (\Throwable $e) {
            $actionResult = ['ok' => false, 'title' => 'Gagal mengonfirmasi pengiriman', 'message' => $e->getMessage()];
        }
    }
}

if ($action === 'disable') {
    @file_put_contents($markerFile, 'disabled at ' . gmdate('c') . ' by ' . $resolvedByLabel . "\n");
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>DO UAT wizard dinonaktifkan</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:2rem auto;">'
        . '<h1 style="color:#080;">Sudah dinonaktifkan</h1><p>Halaman ini sekarang mengembalikan 404.</p>'
        . '</body></html>';
    exit;
}

if ($redirectTo !== null && $actionResult !== null && $actionResult['ok']) {
    $_SESSION['do_uat_flash'] = $actionResult;
    header('Location: ' . $redirectTo);
    exit;
}
if (isset($_SESSION['do_uat_flash'])) {
    $actionResult = $_SESSION['do_uat_flash'];
    unset($_SESSION['do_uat_flash']);
}

// ---------------------------------------------------------------------
// Read state for the current screen.
// ---------------------------------------------------------------------
$factories = $pdo->query('SELECT factory_id, name FROM factory ORDER BY name')->fetchAll();
$allStores = $pdo->query('SELECT store_id, canonical_name FROM store ORDER BY canonical_name')->fetchAll();

$tanggal = (string) ($_GET['tanggal'] ?? '');
$factoryIdParam = isset($_GET['factoryId']) && $_GET['factoryId'] !== '' ? (int) $_GET['factoryId'] : null;
$doIdParam = isset($_GET['doId']) ? (int) $_GET['doId'] : null;

$service = new DoService($pdo);
$repo = new DoRepository();

$storesWithPo = [];
$dosForFactory = [];
$viewError = null;
if ($tanggal !== '' && $factoryIdParam !== null) {
    try {
        $storesWithPo = $service->storesWithPo($tanggal, $factoryIdParam);
        $dosForFactory = $service->listDosForFactory($tanggal, $factoryIdParam);
    } catch (\Throwable $e) {
        $viewError = $e->getMessage();
    }
}
$existingDoByStore = [];
foreach ($dosForFactory as $d) {
    $existingDoByStore[$d['storeId']] = $d;
}

$doView = null;
if ($doIdParam !== null) {
    try {
        $doView = $service->getDo($doIdParam);
    } catch (\Throwable $e) {
        $viewError = $e->getMessage();
    }
}

$shipmentHistory = [];
if ($doView !== null) {
    foreach ($repo->findShipmentsForDo($pdo, $doView['doId']) as $sh) {
        $sh['items'] = $repo->findShipmentItems($pdo, (int) $sh['shipment_id']);
        $shipmentHistory[] = $sh;
    }
}

$history = [];
if ($doView !== null) {
    $stmt = $pdo->prepare(
        "SELECT h.* FROM audit_log h WHERE h.record_type = 'delivery_order' AND h.record_key = ? ORDER BY h.event_at DESC LIMIT 30"
    );
    $stmt->execute([(string) $doView['doId']]);
    $history = $stmt->fetchAll();
}

// Search / filter section
$searchTanggal = (string) ($_GET['sTanggal'] ?? '');
$searchStoreId = isset($_GET['sStoreId']) && $_GET['sStoreId'] !== '' ? (int) $_GET['sStoreId'] : null;
$searchStatus = isset($_GET['sStatus']) && $_GET['sStatus'] !== '' ? (string) $_GET['sStatus'] : null;
$searchResults = [];
$searchTriggered = $searchTanggal !== '' || $searchStoreId !== null || $searchStatus !== null;
if ($searchTriggered) {
    try {
        $searchResults = $service->listDos($searchTanggal !== '' ? $searchTanggal : null, $searchStoreId, $searchStatus);
    } catch (\Throwable $e) {
        $viewError ??= $e->getMessage();
    }
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Amor Factory — Draft DO / Pengiriman (Phase 5)</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:1200px;margin:2rem auto;padding:0 1rem;color:#1a1a1a;line-height:1.5;}
h1{font-size:1.4rem;} h2{font-size:1.1rem;margin-top:2rem;border-bottom:2px solid #eee;padding-bottom:.3rem;}
.box{border:1px solid #ddd;border-radius:8px;padding:1rem;margin:1rem 0;}
.warn{background:#fff3cd;border:1px solid #f0c36d;padding:.75rem;border-radius:6px;}
.result-ok{background:#e6ffe6;border:1px solid #8c8;padding:.75rem;border-radius:6px;}
.result-error{background:#ffe6e6;border:1px solid #e99;padding:.75rem;border-radius:6px;}
button{padding:.5rem 1.2rem;background:#0a5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:bold;}
button.secondary{background:#888;} button.danger{background:#b00;} button.alt{background:#06c;}
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
a.btn{display:inline-block;padding:.4rem .9rem;background:#333;color:#fff;border-radius:6px;text-decoration:none;font-size:.85em;}
</style>
</head>
<body>

<h1>Amor Factory — Draft DO / Staged Shipment (Phase 5 Fast-Track)</h1>
<p>Login sebagai: <strong><?= esc($resolvedByLabel) ?></strong> &middot; <a href="../_admin-login/">logout</a>.
Satu toko = SATU DO per tanggal, boleh dikirim bertahap (beberapa Shipment per DO). Stok FG HANYA berkurang saat
KIRIM sungguhan dikonfirmasi — draft/preprinted/pratinjau TIDAK PERNAH menyentuh stok. Belum ada Invoice,
Pembayaran, Retur, atau konfirmasi terima toko di sini.</p>

<?php if ($actionResult !== null): ?>
<div class="<?= $actionResult['ok'] ? 'result-ok' : 'result-error' ?>">
  <strong><?= esc($actionResult['title']) ?></strong>
  <p><?= esc($actionResult['message']) ?></p>
</div>
<?php endif; ?>
<?php if ($viewError !== null): ?>
<div class="result-error"><strong>Gagal memuat</strong><p><?= esc($viewError) ?></p></div>
<?php endif; ?>

<h2>1-2. Pilih Tanggal &amp; Pabrik</h2>
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
    <button type="submit">3. Muat Toko dengan PO</button>
  </form>
</div>

<?php if ($tanggal !== '' && $factoryIdParam !== null): ?>
<h2>3-5. Toko dengan PO — <?= esc($tanggal) ?></h2>
<div class="box">
  <form method="post" style="margin-bottom:1rem;">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="generate_bulk">
    <input type="hidden" name="tanggal" value="<?= esc($tanggal) ?>">
    <input type="hidden" name="factoryId" value="<?= $factoryIdParam ?>">
    <button type="submit" class="alt" onclick="return confirm('Generate Draft DO untuk SEMUA toko pada tanggal ini? Toko yang sudah punya DO terbuka akan dilewati (aman/idempotent).');">Generate Draft DO untuk Semua Toko</button>
  </form>
  <?php if ($storesWithPo === []): ?>
  <p style="color:#666;">Tidak ada PO untuk pabrik &amp; tanggal ini.</p>
  <?php else: ?>
  <table><tr><th>Toko</th><th>DO</th><th>Aksi</th></tr>
  <?php foreach ($storesWithPo as $s): $existing = $existingDoByStore[$s['storeId']] ?? null; ?>
  <tr>
    <td><?= esc($s['storeName']) ?></td>
    <td><?php if ($existing !== null): ?>
      <?= esc($existing['docNo']) ?> <span class="badge <?= doStatusBadgeClass($existing['status']) ?>"><?= esc(doStatusLabel($existing['status'])) ?></span>
    <?php else: ?><span style="color:#999;">belum ada</span><?php endif; ?></td>
    <td>
      <?php if ($existing !== null): ?>
      <a class="btn" href="?doId=<?= (int) $existing['doId'] ?>">Lihat DO</a>
      <?php else: ?>
      <form method="post" class="inline">
        <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
        <input type="hidden" name="action" value="generate_draft">
        <input type="hidden" name="tanggal" value="<?= esc($tanggal) ?>">
        <input type="hidden" name="storeId" value="<?= (int) $s['storeId'] ?>">
        <button type="submit">5. Generate/Buka Draft DO</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<h2>Daftar DO — <?= esc($tanggal) ?> &middot; <?= esc((string) ($factories[array_search($factoryIdParam, array_column($factories, 'factory_id'))]['name'] ?? '')) ?></h2>
<div class="box">
  <?php if ($dosForFactory !== []): ?>
  <p><a class="btn" href="print-bulk.php?tanggal=<?= urlencode($tanggal) ?>&factoryId=<?= $factoryIdParam ?>" target="_blank">Print Semua (Bulk)</a></p>
  <?php endif; ?>
  <?php if ($dosForFactory === []): ?>
  <p style="color:#666;">Belum ada DO untuk tanggal &amp; pabrik ini.</p>
  <?php else: ?>
  <table>
    <tr><th>Toko</th><th>No DO</th><th>Status</th><th>Planned</th><th>Shipped</th><th>Sisa</th><th>Pengiriman Terakhir</th><th>Aksi</th></tr>
    <?php foreach ($dosForFactory as $d): ?>
    <tr>
      <td><?= esc($d['storeName']) ?></td>
      <td><?= esc($d['docNo']) ?></td>
      <td><span class="badge <?= doStatusBadgeClass($d['status']) ?>"><?= esc(doStatusLabel($d['status'])) ?></span></td>
      <td><?= fmtNum($d['totalPlanned']) ?></td>
      <td><?= fmtNum($d['totalShipped']) ?></td>
      <td><?= fmtNum($d['totalRemaining']) ?></td>
      <td><?= $d['lastShipmentAt'] !== null ? esc((string) $d['lastShipmentAt']) . ' (' . esc((string) $d['lastShipmentGroup']) . ')' : '-' ?></td>
      <td>
        <a class="btn" href="?doId=<?= (int) $d['doId'] ?>">Lihat</a>
        <a class="btn" href="print.php?doId=<?= (int) $d['doId'] ?>" target="_blank">Print</a>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($doView !== null): ?>
<?php $editableDo = in_array($doView['status'], ['draft', 'preprinted'], true); ?>
<h2>6-16. DO <?= esc($doView['docNo']) ?> — <?= esc($doView['tanggal']) ?> &middot; <?= esc((string) $doView['storeName']) ?></h2>
<div class="box">
  <p>Status: <span class="badge <?= doStatusBadgeClass($doView['status']) ?>"><?= esc(doStatusLabel($doView['status'])) ?></span>
  &middot; Versi: <?= (int) $doView['version'] ?>
  <?php if ($doView['preprintedAt']): ?> &middot; Preprinted: <?= esc((string) $doView['preprintedAt']) ?><?php endif; ?>
  <?php if ($doView['shippedAt']): ?> &middot; Terkirim penuh: <?= esc((string) $doView['shippedAt']) ?><?php endif; ?>
  <?php if ($doView['cancelledAt']): ?> &middot; Dibatalkan: <?= esc((string) $doView['cancelledAt']) ?> (<?= esc((string) $doView['cancelReason']) ?>)<?php endif; ?>
  &middot; <a class="btn" href="print.php?doId=<?= (int) $doView['doId'] ?>" target="_blank">7. Print Preview</a></p>

  <?php if ($doView['sourcePoChanged']): ?>
  <div class="warn"><strong>PO Sumber Berubah</strong> — PO toko ini sudah berubah versi sejak DO ini dibuat/disegarkan
  terakhir kali. Item DO TIDAK diubah otomatis. Gunakan "Segarkan dari PO" di bawah untuk menyamakan.
  <table style="margin-top:.4rem;"><tr><th>Pabrik</th><th>Versi Tercatat</th><th>Versi Sekarang</th></tr>
  <?php foreach ($doView['sourcePoChangedDetails'] as $s): ?>
  <tr><td>#<?= (int) $s['factoryId'] ?></td><td><?= (int) $s['storedVersion'] ?></td><td><?= (int) $s['currentVersion'] ?></td></tr>
  <?php endforeach; ?></table>
  </div>
  <?php endif; ?>

  <div class="summary-grid">
    <div class="summary-cell">Total Planned<br><span class="n"><?= fmtNum($doView['summary']['totalPlanned']) ?></span></div>
    <div class="summary-cell">Total Shipped<br><span class="n"><?= fmtNum($doView['summary']['totalShipped']) ?></span></div>
    <div class="summary-cell">Sisa<br><span class="n"><?= fmtNum($doView['summary']['totalRemaining']) ?></span></div>
    <div class="summary-cell">Belum Dikirim<br><span class="n"><?= $doView['summary']['jumlahBelumDikirim'] ?></span></div>
    <div class="summary-cell">Sebagian Dikirim<br><span class="n"><?= $doView['summary']['jumlahSebagianDikirim'] ?></span></div>
    <div class="summary-cell">Terkirim Penuh<br><span class="n"><?= $doView['summary']['jumlahTerkirimPenuh'] ?></span></div>
  </div>

  <table>
    <tr><th>Produk</th><th>Divisi</th><th>Planned</th><th>Sudah Dikirim</th><th>Sisa</th><th>FG Available</th><th>Status</th></tr>
    <?php foreach ($doView['items'] as $it): ?>
    <tr>
      <td><?= esc($it['productName']) ?></td>
      <td><?= esc((string) $it['divisionName']) ?></td>
      <td><?= fmtNum($it['plannedQty']) ?></td>
      <td><?= fmtNum($it['alreadyShippedQty']) ?></td>
      <td><?= fmtNum($it['remainingToShip']) ?></td>
      <td><?= $it['fgAvailable'] !== null ? fmtNum($it['fgAvailable']) : '-' ?></td>
      <td><span class="badge <?= itemStatusBadgeClass($it['itemStatusCode']) ?>"><?= esc($it['itemStatusLabel']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <?php if ($editableDo): ?>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.6rem;">
    <form method="post" class="inline">
      <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
      <input type="hidden" name="action" value="preprint">
      <input type="hidden" name="doId" value="<?= (int) $doView['doId'] ?>">
      <input type="hidden" name="expectedVersion" value="<?= (int) $doView['version'] ?>">
      <button type="submit" class="secondary">8. Tandai Preprinted</button>
    </form>
    <form method="post" class="inline">
      <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
      <input type="hidden" name="action" value="refresh_po">
      <input type="hidden" name="doId" value="<?= (int) $doView['doId'] ?>">
      <input type="hidden" name="expectedVersion" value="<?= (int) $doView['version'] ?>">
      <button type="submit" class="secondary">Segarkan dari PO</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($editableDo && $doView['summary']['totalShipped'] <= 0.0001): ?>
  <form method="post" style="margin-top:1rem;" onsubmit="return confirm('Batalkan DO ini? Belum ada qty terkirim sama sekali.');">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="cancel_do">
    <input type="hidden" name="doId" value="<?= (int) $doView['doId'] ?>">
    <input type="hidden" name="expectedVersion" value="<?= (int) $doView['version'] ?>">
    <label>Alasan Batal (wajib) <input type="text" name="reason" required style="width:100%;"></label>
    <button type="submit" class="danger">Batalkan DO</button>
  </form>
  <?php endif; ?>
</div>

<?php if ($editableDo && $doView['summary']['totalRemaining'] > 0.0001): ?>
<h2>9-13. Buat Pengiriman (Shipment Baru)</h2>
<div class="box">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="doId" value="<?= (int) $doView['doId'] ?>">
    <input type="hidden" name="expectedVersion" value="<?= (int) $doView['version'] ?>">
    <label>11. Grup Pengiriman
      <select name="shipmentGroup">
        <?php foreach (['MAIN', 'PASTRY', 'OTHER'] as $g): ?>
        <option value="<?= $g ?>" <?= $postedShipmentGroup === $g ? 'selected' : '' ?>><?= $g ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <table>
      <tr><th>Produk</th><th>Sisa</th><th>FG Available</th><th>12. Qty Kirim</th><th>Catatan</th></tr>
      <?php foreach ($doView['items'] as $it): if ($it['remainingToShip'] <= 0.0001) continue;
        $prefill = '';
        foreach ($postedShipmentItems as $pi) { if ($pi['productId'] === $it['productId']) { $prefill = $pi['actualQty']; break; } }
      ?>
      <tr>
        <td><?= esc($it['productName']) ?></td>
        <td><?= fmtNum($it['remainingToShip']) ?></td>
        <td><?= $it['fgAvailable'] !== null ? fmtNum($it['fgAvailable']) : '-' ?></td>
        <td><input type="number" step="0.01" min="0" name="qty[<?= (int) $it['productId'] ?>]" value="<?= $prefill !== '' ? fmtNum((float) $prefill) : '' ?>" style="width:5.5rem;"></td>
        <td><input type="text" name="notes[<?= (int) $it['productId'] ?>]" style="width:7rem;"></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.6rem;">
      <button type="submit" name="action" value="preview_shipment" class="secondary">10. Pratinjau Pengiriman</button>
      <button type="submit" name="action" value="ship_confirm" onclick="return confirm('Konfirmasi KIRIM? Stok FG akan langsung dikurangi dan tidak bisa dibatalkan dari sini.');">13. Konfirmasi KIRIM</button>
    </div>
  </form>

  <?php if ($shipmentPreviewResult !== null): ?>
  <h3 style="margin-top:1rem;">Hasil Pratinjau — <?= $shipmentPreviewResult['ok'] ? '<span style="color:#080;">Semua baris valid</span>' : '<span style="color:#b00;">Ada baris bermasalah</span>' ?></h3>
  <table>
    <tr><th>Produk</th><th>Qty Diminta</th><th>Sisa DO</th><th>FG Available</th><th>Maks Bisa Kirim</th><th>Masalah</th></tr>
    <?php foreach ($shipmentPreviewResult['lines'] as $l): ?>
    <tr>
      <td><?= esc($l['productName']) ?></td>
      <td><?= fmtNum($l['requestedQty']) ?></td>
      <td><?= fmtNum($l['remainingToShip']) ?></td>
      <td><?= fmtNum($l['fgAvailable']) ?></td>
      <td><?= fmtNum($l['maxShippable']) ?></td>
      <td><?= $l['errors'] === [] ? '-' : '<span style="color:#b00;">' . esc(implode(', ', $l['errors'])) . '</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<h2>16. Riwayat Pengiriman</h2>
<div class="box">
  <?php if ($shipmentHistory === []): ?>
  <p style="color:#666;">Belum ada pengiriman untuk DO ini.</p>
  <?php else: ?>
  <?php foreach ($shipmentHistory as $sh): ?>
  <table style="margin-bottom:1rem;">
    <tr><th colspan="3">Shipment #<?= (int) $sh['shipment_id'] ?> &middot; Grup <?= esc((string) $sh['shipment_group']) ?>
      &middot; <span class="badge <?= $sh['status'] === 'active' ? 'b-ok' : 'b-bad' ?>"><?= esc(strtoupper((string) $sh['status'])) ?></span>
      &middot; <?= esc((string) $sh['shipped_at']) ?></th></tr>
    <tr><th>Produk</th><th>Qty</th><th>Catatan</th></tr>
    <?php foreach ($sh['items'] as $it): ?>
    <tr><td><?= esc($it['product_name']) ?></td><td><?= fmtNum((float) $it['qty']) ?></td><td><?= esc((string) ($it['notes'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<h2>Riwayat Aksi (Audit Log)</h2>
<div class="box">
  <?php if ($history === []): ?>
  <p style="color:#666;">Belum ada riwayat untuk DO ini.</p>
  <?php else: ?>
  <table><tr><th>Waktu</th><th>Aksi</th><th>User</th><th>Status</th></tr>
  <?php foreach ($history as $h): ?>
  <tr><td><?= esc((string) $h['event_at']) ?></td><td><?= esc($h['action']) ?></td><td><?= $h['user_id'] !== null ? (int) $h['user_id'] : '-' ?></td><td><?= esc($h['status']) ?></td></tr>
  <?php endforeach; ?></table>
  <?php endif; ?>
</div>
<?php endif; ?>

<h2>Cari / Filter DO</h2>
<div class="box">
  <form method="get">
    <?php if ($tanggal !== ''): ?><input type="hidden" name="tanggal" value="<?= esc($tanggal) ?>"><?php endif; ?>
    <?php if ($factoryIdParam !== null): ?><input type="hidden" name="factoryId" value="<?= $factoryIdParam ?>"><?php endif; ?>
    <?php if ($doIdParam !== null): ?><input type="hidden" name="doId" value="<?= $doIdParam ?>"><?php endif; ?>
    <label>Tanggal (opsional) <input type="date" name="sTanggal" value="<?= esc($searchTanggal) ?>"></label>
    <label>Toko (opsional)
      <select name="sStoreId">
        <option value="">— semua toko —</option>
        <?php foreach ($allStores as $s): ?>
        <option value="<?= (int) $s['store_id'] ?>" <?= $searchStoreId === (int) $s['store_id'] ? 'selected' : '' ?>><?= esc($s['canonical_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Status (opsional)
      <select name="sStatus">
        <option value="">— semua status —</option>
        <?php foreach (['draft', 'preprinted', 'shipped', 'cancelled'] as $st): ?>
        <option value="<?= $st ?>" <?= $searchStatus === $st ? 'selected' : '' ?>><?= esc(doStatusLabel($st)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit">Cari</button>
  </form>

  <?php if ($searchTriggered): ?>
  <?php if ($searchResults === []): ?>
  <p style="color:#666;margin-top:.5rem;">Tidak ada DO yang cocok.</p>
  <?php else: ?>
  <table style="margin-top:.5rem;">
    <tr><th>Tanggal</th><th>Toko</th><th>No DO</th><th>Status</th><th>Planned</th><th>Shipped</th><th>Sisa</th><th>Aksi</th></tr>
    <?php foreach ($searchResults as $d): ?>
    <tr>
      <td><?= esc($d['tanggal']) ?></td>
      <td><?= esc($d['storeName']) ?></td>
      <td><?= esc($d['docNo']) ?></td>
      <td><span class="badge <?= doStatusBadgeClass($d['status']) ?>"><?= esc(doStatusLabel($d['status'])) ?></span></td>
      <td><?= fmtNum($d['totalPlanned']) ?></td>
      <td><?= fmtNum($d['totalShipped']) ?></td>
      <td><?= fmtNum($d['totalRemaining']) ?></td>
      <td><a class="btn" href="?doId=<?= (int) $d['doId'] ?>">Lihat</a> <a class="btn" href="print.php?doId=<?= (int) $d['doId'] ?>" target="_blank">Print</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php endif; ?>
</div>

<h2>Selesai</h2>
<div class="box">
  <form method="post" onsubmit="return confirm('Nonaktifkan halaman UAT DO/Shipment ini sekarang?');">
    <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">
    <input type="hidden" name="action" value="disable">
    <button type="submit" class="danger">Nonaktifkan DO/Shipment UAT Wizard</button>
  </form>
</div>

</body>
</html>
