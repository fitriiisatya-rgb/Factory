<?php

declare(strict_types=1);

/**
 * UI/UX redesign smoke + regression suite (UI-01..UI-19). Run via
 * api/tests/run-ui-preview.sh, which stands up a disposable local
 * MariaDB, applies ALL migrations 0001-0006 for real (the redesigned UI
 * spans every phase), bootstraps realistic master data, then drives the
 * real /api/_ui-preview/ pages AND the underlying JSON API (the same one
 * those pages call from their own JS) against a live `php -S` server.
 *
 * UI-20 (full Phase 0-5 regression green) is NOT a test in this file —
 * it is the orchestrator's own final step (running every existing
 * run-phase*.sh in sequence after this suite passes), reported alongside
 * this suite's own result rather than faked as a runTest() call here.
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8104';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'ui_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpUI
{
    private string $cookieJar;

    public function __construct(private string $baseUrl, bool $freshCookies = true)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'uicookies');
    }

    /** @return array{status:int,json:?array,body:string,headers:string} */
    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $hdrLines = [];
        foreach ($headers as $k => $v) {
            $hdrLines[] = "{$k}: {$v}";
        }
        $hdrLines[] = 'Content-Type: application/json';

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_HTTPHEADER => $hdrLines,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('curl error: ' . curl_error($ch));
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = null;
        if ($rawBody !== '' && ($rawBody[0] === '{' || $rawBody[0] === '[')) {
            $json = json_decode($rawBody, true);
        }
        return ['status' => $status, 'json' => $json, 'body' => $rawBody, 'headers' => $rawHeaders];
    }
}

function expect(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

function idemKey(string $tag): array
{
    return ['Idempotency-Key' => $tag . '-' . uniqid('', true)];
}

function seedStorePo(PDO $pdo, string $tanggal, int $factoryId, int $storeId, array $items): int
{
    $find = $pdo->prepare('SELECT po_batch_id FROM po_batch WHERE tanggal = ? AND factory_id = ?');
    $find->execute([$tanggal, $factoryId]);
    $batchId = $find->fetchColumn();
    if ($batchId === false) {
        $pdo->prepare('INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())')
            ->execute([$tanggal, $factoryId]);
        $batchId = (int) $pdo->lastInsertId();
    } else {
        $batchId = (int) $batchId;
        $pdo->prepare('UPDATE po_batch SET version = version + 1, updated_at = UTC_TIMESTAMP() WHERE po_batch_id = ?')->execute([$batchId]);
    }
    $upsertItem = $pdo->prepare(
        'INSERT INTO po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (?, ?, ?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE kategori = VALUES(kategori), po_awal = VALUES(po_awal), po_revisi = VALUES(po_revisi)'
    );
    $findItem = $pdo->prepare('SELECT po_item_id FROM po_item WHERE po_batch_id = ? AND product_id = ?');
    $upsertStoreItem = $pdo->prepare(
        'INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal), po_revisi = VALUES(po_revisi)'
    );
    foreach ($items as $productId => $d) {
        $upsertItem->execute([$batchId, $productId, $d['kategori'] ?? null, $d['poAwal'] ?? 0.0, $d['poRevisi'] ?? 0.0]);
        $findItem->execute([$batchId, $productId]);
        $poItemId = (int) $findItem->fetchColumn();
        $upsertStoreItem->execute([$poItemId, $storeId, $d['poAwal'] ?? 0.0, $d['poRevisi'] ?? 0.0]);
    }
    return $batchId;
}

function createSubmittedProduction(HttpUI $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual): array
{
    seedStorePo($pdo, $tanggal, $factoryId, $storeId, [$productId => ['poAwal' => $poTarget, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $divisionId], array_merge(['X-CSRF-Token' => $csrf], idemKey('prod-create')));
    expect($create['status'] === 200, 'production create failed: ' . json_encode($create['json']));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $productId, 'actualQty' => $actual]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('prod-patch')));
    expect($save['status'] === 200, 'production patch failed: ' . json_encode($save['json']));
    $v = $save['json']['data']['version'];
    $submit = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('prod-submit')));
    expect($submit['status'] === 200, 'production submit failed: ' . json_encode($submit['json']));
    return ['productionRunId' => (int) $runId, 'version' => (int) $submit['json']['data']['version']];
}

function stockUpForDelivery(HttpUI $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual, float $fgQty): void
{
    createSubmittedProduction($http, $csrf, $pdo, $factoryId, $divisionId, $tanggal, $storeId, $productId, $poTarget, $actual);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $factoryId], array_merge(['X-CSRF-Token' => $csrf], idemKey('fg-create')));
    expect($create['status'] === 200, 'fg create failed: ' . json_encode($create['json']));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $productId, 'fgVerified' => $fgQty, 'packed' => $fgQty]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('fg-save')));
    expect($save['status'] === 200, 'fg save failed: ' . json_encode($save['json']));
    $v = $save['json']['data']['version'];
    $submit = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('fg-submit')));
    expect($submit['status'] === 200, 'fg submit failed: ' . json_encode($submit['json']));
}

function createUser(PDO $pdo, string $username, string $password, array $roleCodes): int
{
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $username]);
    $userId = (int) $pdo->lastInsertId();
    $roleStmt = $pdo->prepare('SELECT role_id FROM roles WHERE code = ?');
    $insertRole = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
    foreach ($roleCodes as $code) {
        $roleStmt->execute([$code]);
        $roleId = $roleStmt->fetchColumn();
        expect($roleId !== false, "role {$code} not seeded");
        $insertRole->execute([$userId, (int) $roleId]);
    }
    return $userId;
}

function login(HttpUI $http, string $username, string $password): string
{
    $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    $me = $http->request('GET', '/api/auth/me');
    $csrf = $me['json']['data']['csrfToken'] ?? null;
    expect($csrf !== null, "expected a csrf token after login as {$username}");
    return $csrf;
}

function productsInDivision(PDO $pdo, int $divisionId, int $limit): array
{
    $stmt = $pdo->prepare('SELECT product_id, name FROM product WHERE division_id = ? ORDER BY product_id LIMIT ' . (int) $limit);
    $stmt->execute([$divisionId]);
    return $stmt->fetchAll();
}

$results = [];
function runTest(string $id, callable $fn): void
{
    global $results;
    try {
        $fn();
        $results[$id] = true;
        fwrite(STDOUT, "PASS {$id}\n");
    } catch (\Throwable $e) {
        $results[$id] = false;
        fwrite(STDOUT, "FAIL {$id}: {$e->getMessage()}\n");
    }
}

$pdo = new PDO("mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4", $runtimeUser, $runtimePass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$http = new HttpUI($baseUrl);

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------
$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
expect($karangtengahId > 0, 'expected Karangtengah factory seeded');
$rotiBollenDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
expect($rotiBollenDivId > 0, 'expected Roti & Bollen division seeded');
$rotiProducts = productsInDivision($pdo, $rotiBollenDivId, 20);
expect(count($rotiProducts) >= 20, 'expected enough katalog products after bootstrap');
$pool = $rotiProducts;
function nextProduct(): array { global $pool; return array_shift($pool); }

$storeA = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
expect($storeA > 0, 'expected P2 TEST STORE A fixture seeded');

// ---------------------------------------------------------------------
// UI-19: auth/role protections unchanged — unauthenticated access redirects,
// mutating calls without CSRF are still rejected. Checked BEFORE login so
// the cookie jar is genuinely empty.
// ---------------------------------------------------------------------
runTest('UI-19 unauthenticated access redirects to login, CSRF still enforced on writes', function () use ($baseUrl) {
    $anon = new HttpUI($baseUrl);
    $r = $anon->request('GET', '/_ui-preview/?page=dashboard');
    expect(in_array($r['status'], [301, 302, 303, 307, 308], true), "expected a redirect for an unauthenticated request, got {$r['status']}");
    expect(stripos($r['headers'], '_admin-login') !== false, 'expected redirect Location to point at _admin-login');

    $r2 = $anon->request('POST', '/api/do', ['tanggal' => '2026-01-01', 'storeId' => 1], []);
    expect($r2['status'] === 401 || ($r2['json']['code'] ?? '') === 'CSRF_TOKEN_INVALID' || $r2['status'] === 400,
        'expected an unauthenticated/CSRF-missing write to be rejected, got ' . json_encode($r2['json'] ?? $r2['status']));
});

$csrf = login($http, $adminUser, $adminPass);

// ---------------------------------------------------------------------
runTest('UI-01 authenticated UI shell loads', function () use ($http, $csrf) {
    $r = $http->request('GET', '/_ui-preview/?page=dashboard');
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    expect(str_contains($r['body'], 'Amor Factory System'), 'expected the shell brand name to render');
    expect(!str_contains($r['body'], 'Fatal error') && !str_contains($r['body'], 'Parse error'), 'expected no PHP error leaked into the page');
});

runTest('UI-02 sidebar renders every required menu item', function () use ($http) {
    $r = $http->request('GET', '/_ui-preview/?page=dashboard');
    foreach (['Dashboard', 'Pesanan Toko', 'Produksi', 'FG &amp; Packing', 'Delivery Order', 'Pengiriman', 'Laporan', 'Master Data', 'Pengaturan'] as $label) {
        expect(str_contains($r['body'], $label), "expected sidebar to contain '{$label}'");
    }
    expect(!str_contains($r['body'], 'Phase 1') && !str_contains($r['body'], 'Phase 5') && !str_contains(strtolower($r['body']), '_do-uat'),
        'expected no raw phase/technical module names in the nav');
});

runTest('UI-03 Dashboard reads real, non-zero data for a seeded date/factory', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-07-01';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 8.0, 6.0);
    $r = $http->request('GET', "/_ui-preview/?page=dashboard&tanggal={$tanggal}&factoryId={$karangtengahId}");
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    expect(preg_match('/kpi-value">10/', $r['body']) === 1, 'expected PO Target 10 rendered on the dashboard');
    expect(preg_match('/kpi-value">8/', $r['body']) === 1, 'expected Produksi Aktual 8 rendered on the dashboard');
});

runTest('UI-04 Dashboard has no hardcoded mockup transaction values', function () use ($http) {
    $tanggal = '2026-07-02'; // deliberately unseeded — must read as zero, never a fabricated mockup number
    $r = $http->request('GET', "/_ui-preview/?page=dashboard&tanggal={$tanggal}&factoryId=1");
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    foreach (['12.930', '9.245', '7.890', '12,930', '9,245', '7,890'] as $fabricated) {
        expect(!str_contains($r['body'], $fabricated), "expected the reference mockup's fabricated number '{$fabricated}' to NOT appear for an empty date");
    }
    expect(preg_match('/kpi-value">0<\/div>/', $r['body']) === 1, 'expected at least one genuinely-zero KPI value for an empty date');
});

runTest('UI-05 Produksi page reads real Phase 3 data', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-07-03';
    $p = nextProduct();
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 12.0, 9.0);
    $r = $http->request('GET', "/_ui-preview/?page=produksi&tanggal={$tanggal}&factoryId={$karangtengahId}");
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    expect(str_contains($r['body'], 'Roti &amp; Bollen'), 'expected the real division name rendered');
});

runTest('UI-06 Produksi save/submit drives the unmodified Production API (stale version still 409s)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-07-04';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui06-create')));
    expect($create['status'] === 200, 'create failed: ' . json_encode($create['json']));
    $runId = $create['json']['data']['productionRunId'];
    $stale = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => 999, 'items' => [['productId' => $p['product_id'], 'actualQty' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui06-stale')));
    expect($stale['status'] === 409 && $stale['json']['code'] === 'VERSION_CONFLICT', 'expected the UI-driven save to still hit real optimistic-concurrency checks: ' . json_encode($stale['json']));

    $save = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => 1, 'items' => [['productId' => $p['product_id'], 'actualQty' => 5]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui06-save')));
    expect($save['status'] === 200, 'save failed: ' . json_encode($save['json']));
    $submit = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => 2], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui06-submit')));
    expect($submit['status'] === 200 && $submit['json']['data']['status'] === 'submitted', 'expected submit to succeed: ' . json_encode($submit['json']));
});

runTest('UI-07 FG & Packing page reads real Phase 4 data', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-07-05';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 6.0, 6.0, 4.0);
    $r = $http->request('GET', "/_ui-preview/?page=fg-packing&tanggal={$tanggal}&factoryId={$karangtengahId}");
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    expect(str_contains($r['body'], $p['name']), 'expected the real product name rendered');
});

runTest('UI-08 FG submit via the API still posts exactly one stock_ledger row (unmodified Phase 4 semantics)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-07-06';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 5.0, 5.0, 4.0);
    $locId = (int) $pdo->query('SELECT location_id FROM location WHERE factory_id = ' . $karangtengahId)->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_ledger WHERE product_id = ? AND location_id = ? AND event_type = 'production_in' AND source_type = 'fg_item'");
    $stmt->execute([$p['product_id'], $locId]);
    expect((int) $stmt->fetchColumn() === 1, 'expected exactly 1 production_in/fg_item ledger row after one FG submit');
});

runTest('UI-09 Delivery Order detail page reads real Phase 5 data', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-07-07';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 7.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui09')));
    expect($create['status'] === 200, 'DO create failed: ' . json_encode($create['json']));
    $doId = $create['json']['data']['doId'];
    $r = $http->request('GET', "/_ui-preview/?page=delivery-order-detail&doId={$doId}");
    expect($r['status'] === 200 && str_contains($r['body'], $create['json']['data']['docNo']), 'expected the real DO number rendered');
});

runTest('UI-10 Draft DO creation and preprint still post ZERO stock movement', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-07-08';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 4.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui10')));
    $doId = $create['json']['data']['doId'];
    $http->request('POST', "/api/do/{$doId}/preprint", ['expectedVersion' => 1], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui10-preprint')));

    $locId = (int) $pdo->query('SELECT location_id FROM location WHERE factory_id = ' . $karangtengahId)->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_ledger WHERE product_id = ? AND location_id = ? AND event_type = 'shipment_out'");
    $stmt->execute([$p['product_id'], $locId]);
    expect((int) $stmt->fetchColumn() === 0, 'draft/preprint must never post a shipment_out ledger row');
});

runTest('UI-11 the Pengiriman page\'s ship action drives the real, unmodified ShipmentService commit', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-07-09';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 5.0, 5.0, 5.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui11')));
    $doId = $create['json']['data']['doId'];
    $ship = $http->request('POST', "/api/do/{$doId}/ship", ['expectedVersion' => 1, 'shipmentGroup' => 'MAIN', 'items' => [['productId' => $p['product_id'], 'actualQty' => 5]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui11-ship')));
    expect($ship['status'] === 200 && $ship['json']['data']['doFullyFulfilled'] === true, 'expected ship to fully fulfill the DO: ' . json_encode($ship['json']));

    $r = $http->request('GET', "/_ui-preview/?page=pengiriman&tanggal={$tanggal}&factoryId={$karangtengahId}");
    expect($r['status'] === 200 && str_contains($r['body'], '#' . $ship['json']['data']['shipmentId']), 'expected the new shipment to appear in the Pengiriman list');
});

runTest('UI-12 ship still rejects a qty exceeding the DO remaining (unweakened validation)', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-07-10';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 3.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui12')));
    $doId = $create['json']['data']['doId'];
    $ship = $http->request('POST', "/api/do/{$doId}/ship", ['expectedVersion' => 1, 'shipmentGroup' => 'MAIN', 'items' => [['productId' => $p['product_id'], 'actualQty' => 99]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui12-ship')));
    expect($ship['status'] === 400 && $ship['json']['code'] === 'EXCEEDS_REMAINING', 'expected 400 EXCEEDS_REMAINING: ' . json_encode($ship['json']));
});

runTest('UI-13 ship still rejects a qty exceeding FG available (unweakened validation)', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-07-11';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui13')));
    $doId = $create['json']['data']['doId'];
    $ship = $http->request('POST', "/api/do/{$doId}/ship", ['expectedVersion' => 1, 'shipmentGroup' => 'MAIN', 'items' => [['productId' => $p['product_id'], 'actualQty' => 5]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui13-ship')));
    expect($ship['status'] === 409 && $ship['json']['code'] === 'INSUFFICIENT_FG_AVAILABLE', 'expected 409 INSUFFICIENT_FG_AVAILABLE: ' . json_encode($ship['json']));
});

runTest('UI-14 responsive: no forced horizontal page scroll, tablet breakpoints present', function () use ($http) {
    $css = $http->request('GET', '/api/assets/css/app.css');
    expect($css['status'] === 200, 'expected app.css to be servable');
    expect(str_contains($css['body'], '@media (max-width: 900px)'), 'expected a tablet-width breakpoint in app.css');
    expect(str_contains($css['body'], 'overflow-x: hidden'), 'expected the page to never force whole-page horizontal scroll');
    expect(str_contains($css['body'], '.table-scroll'), 'expected tables to scroll within their own container, not the page');
});

runTest('UI-15 no raw internal status codes leak into the main UI', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-07-12';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 5.0, 5.0, 5.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui15')));
    $doId = $create['json']['data']['doId'];

    $pages = [
        "/_ui-preview/?page=dashboard&tanggal={$tanggal}&factoryId={$karangtengahId}",
        "/_ui-preview/?page=fg-packing&tanggal={$tanggal}&factoryId={$karangtengahId}",
        "/_ui-preview/?page=delivery-order-detail&doId={$doId}",
    ];
    $forbiddenCodes = [
        'not_produced', 'below_target', 'on_target', 'belum_diverifikasi', 'sebagian_terverifikasi',
        'sesuai_produksi', 'melebihi_produksi', 'belum_dipacking', 'sebagian_dipacking', 'selesai_dipacking',
        'packing_melebihi_fg', 'belum_dikirim', 'sebagian_dikirim', 'terkirim_penuh',
    ];
    foreach ($pages as $path) {
        $r = $http->request('GET', $path);
        expect($r['status'] === 200, "expected 200 for {$path}");
        foreach ($forbiddenCodes as $code) {
            expect(!str_contains($r['body'], $code), "expected raw status code '{$code}' to NOT appear in {$path}");
        }
    }
});

runTest('UI-16 redesigned DO print page renders with the correct watermark', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-07-13';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 2.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('ui16')));
    $doId = $create['json']['data']['doId'];
    $r = $http->request('GET', "/_ui-preview/print-do.php?doId={$doId}");
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    expect(str_contains($r['body'], 'Surat Jalan') || str_contains($r['body'], 'SURAT JALAN'), 'expected the print document title');
    expect(str_contains($r['body'], 'DRAFT'), 'expected a DRAFT watermark for a freshly-created DO');
});

runTest('UI-17 print stylesheet forces a white background regardless of dark mode', function () use ($http) {
    $css = $http->request('GET', '/api/assets/css/print.css');
    expect($css['status'] === 200, 'expected print.css to be servable');
    expect(str_contains($css['body'], '@page'), 'expected an @page rule for A4 sizing');
    expect(preg_match('/background:\s*#fff/', $css['body']) === 1, 'expected the print document to force a white background');
});

runTest('UI-18 every old UAT wizard remains accessible, untouched', function () use ($http) {
    foreach (['/_import-po/', '/_production-uat/', '/_fg-uat/', '/_do-uat/', '/_admin-login/', '/_upgrade/'] as $path) {
        $r = $http->request('GET', $path);
        expect($r['status'] === 200, "expected {$path} to still return 200, got {$r['status']}");
    }
});

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
