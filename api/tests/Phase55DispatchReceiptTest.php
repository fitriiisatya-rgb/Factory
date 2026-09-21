<?php

declare(strict_types=1);

/**
 * Phase 5.5 Dispatch Pool / Driver Claim / Store Receipt integration suite
 * (P55-01..23, plus P55-MF01/MF02 covering multi-factory departure claim-
 * >shipment mapping and its rollback safety). Run via
 * api/tests/run-phase55-dispatch-receipt.sh, which
 * stands up a disposable local MariaDB, applies migrations 0001-0007,
 * bootstraps realistic master data, then drives the real
 * /api/dispatch/*, /api/receive/*, and /api/admin/receipts/* JSON API end
 * to end against a live `php -S` server — plus the EXISTING Production/FG/
 * DO/Shipment APIs, since dispatch claims/departures are built directly on
 * top of those, never a parallel implementation.
 *
 * P55-24 (full Phase 0-5 regression green) is NOT a test in this file — it
 * is the orchestrator's own final step, same pattern as every prior
 * phase's own suite (see PhasePrintTest.php's PRINT-13, PhaseInvoiceUiTest
 * .php's INV-UI15).
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8109';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'p55_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class Http55
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'p55cookies');
    }

    public function cookieJarPath(): string
    {
        return $this->cookieJar;
    }

    /** @return array{status:int,json:?array,body:string} */
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
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_HTTPHEADER => $hdrLines,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('curl error: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = $raw === '' ? null : json_decode($raw, true);
        return ['status' => $status, 'json' => $json, 'body' => $raw];
    }

    /**
     * multipart/form-data variant — the ONLY way this app accepts a file
     * (Store Receipt photo evidence). $files maps form field name (e.g.
     * "evidence[]") to a local file path; curl sends it as a real
     * multipart upload (CURLFile), exactly like a browser's FormData.
     * @return array{status:int,json:?array,body:string}
     */
    public function requestMultipart(string $method, string $path, array $fields, array $files, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $hdrLines = [];
        foreach ($headers as $k => $v) {
            $hdrLines[] = "{$k}: {$v}";
        }
        $postFields = $fields;
        foreach ($files as $fieldName => $filePath) {
            $postFields[$fieldName] = new CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', basename($filePath));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_HTTPHEADER => $hdrLines,
            CURLOPT_POSTFIELDS => $postFields,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('curl error: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = $raw === '' ? null : json_decode($raw, true);
        return ['status' => $status, 'json' => $json, 'body' => $raw];
    }
}

function expect(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

/**
 * Writes a minimal-but-genuinely-valid 1x1 PNG to a temp file and returns
 * its path — real image bytes so getimagesize()/finfo pass exactly like a
 * real photo would (PHOTO-* tests need EvidenceUploader's REAL content
 * validation to see a real image, never a fake/renamed file).
 */
function fakeEvidenceImage(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );
    $path = tempnam(sys_get_temp_dir(), 'evidence') . '.png';
    file_put_contents($path, $png);
    return $path;
}

/** A file that will NEVER pass EvidenceUploader's validation — for PHOTO-05/06/08 negative tests. */
function fakeInvalidEvidenceFile(string $extension = 'txt', int $sizeBytes = 100): string
{
    $path = tempnam(sys_get_temp_dir(), 'badevidence') . '.' . $extension;
    file_put_contents($path, str_repeat('x', $sizeBytes));
    return $path;
}

function idemKey(string $tag): array
{
    return ['Idempotency-Key' => $tag . '-' . uniqid('', true)];
}

/** @param array<int,array{poAwal?:float,poRevisi?:float,kategori?:string}> $items keyed by product_id */
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

function createSubmittedProduction(Http55 $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual): void
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
}

/** Production submitted -> FG created/verified/submitted, leaving $fgQty available FG for $productId at $factoryId. */
function stockUpForDelivery(Http55 $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual, float $fgQty): void
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

/** @param array<int,array{0:int,1:int,2:float,3:float}> $productions [divisionId,productId,poTarget,actual] @param array<int,float> $fgQtyByProduct */
function stockUpMultiForDelivery(Http55 $http, string $csrf, PDO $pdo, int $factoryId, string $tanggal, int $storeId, array $productions, array $fgQtyByProduct): void
{
    foreach ($productions as [$divisionId, $productId, $poTarget, $actual]) {
        createSubmittedProduction($http, $csrf, $pdo, $factoryId, $divisionId, $tanggal, $storeId, $productId, $poTarget, $actual);
    }
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $factoryId], array_merge(['X-CSRF-Token' => $csrf], idemKey('fg-create-multi')));
    expect($create['status'] === 200, 'fg create failed: ' . json_encode($create['json']));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $items = [];
    foreach ($fgQtyByProduct as $productId => $qty) {
        $items[] = ['productId' => $productId, 'fgVerified' => $qty, 'packed' => $qty];
    }
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => $items], array_merge(['X-CSRF-Token' => $csrf], idemKey('fg-save-multi')));
    expect($save['status'] === 200, 'fg save failed: ' . json_encode($save['json']));
    $v = $save['json']['data']['version'];
    $submit = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('fg-submit-multi')));
    expect($submit['status'] === 200, 'fg submit failed: ' . json_encode($submit['json']));
}

function createDoDraft(Http55 $http, string $csrf, string $tanggal, int $storeId): array
{
    $r = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeId], array_merge(['X-CSRF-Token' => $csrf], idemKey('do-create')));
    expect($r['status'] === 200, 'DO create failed: ' . json_encode($r['json']));
    return ['doId' => (int) $r['json']['data']['doId'], 'docNo' => $r['json']['data']['docNo'], 'version' => (int) $r['json']['data']['version']];
}

function doItemIdFor(PDO $pdo, int $doId, int $productId): int
{
    $stmt = $pdo->prepare('SELECT delivery_order_item_id FROM delivery_order_item WHERE delivery_order_id = ? AND product_id = ?');
    $stmt->execute([$doId, $productId]);
    $id = $stmt->fetchColumn();
    expect($id !== false, "no delivery_order_item for do={$doId} product={$productId}");
    return (int) $id;
}

function createUser(PDO $pdo, string $username, string $password, array $roleCodes): int
{
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $username]);
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $userId = (int) $stmt->fetchColumn();
    $roleStmt = $pdo->prepare('SELECT role_id FROM roles WHERE code = ?');
    $insertRole = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)');
    foreach ($roleCodes as $code) {
        $roleStmt->execute([$code]);
        $roleId = $roleStmt->fetchColumn();
        expect($roleId !== false, "role {$code} not seeded");
        $insertRole->execute([$userId, (int) $roleId]);
    }
    return $userId;
}

function login(Http55 $http, string $username, string $password): string
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

function shipmentOutRows(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM stock_ledger WHERE event_type = 'shipment_out'")->fetchColumn();
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

$adminHttp = new Http55($baseUrl);
$adminCsrf = login($adminHttp, $adminUser, $adminPass);

$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
expect($karangtengahId > 0, 'expected Karangtengah factory seeded');
$rotiBollenDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
expect($rotiBollenDivId > 0, 'expected Roti & Bollen division seeded');
// A second, DIFFERENT division at the same factory — needed wherever a test
// puts two distinct products into one fg_batch/production_run pair, since
// production_run identity is (tanggal,divisionId): two products in the SAME
// division must share one create->patch->submit call, never two.
$secondDivStmt = $pdo->prepare('SELECT division_id FROM division WHERE factory_id = (SELECT factory_id FROM division WHERE division_id = ?) AND division_id != ? LIMIT 1');
$secondDivStmt->execute([$rotiBollenDivId, $rotiBollenDivId]);
$secondDivId = (int) $secondDivStmt->fetchColumn();
expect($secondDivId > 0, 'expected a second division at the same factory');
$storeA = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
expect($storeA > 0, 'expected P2 TEST STORE A fixture seeded');
$storeB = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE B'")->fetchColumn();
expect($storeB > 0, 'expected P2 TEST STORE B fixture seeded');

// Real-UAT patch (Store Receipt Photo Evidence + Admin Detail) added
// several more single-product fixtures — bumped from 40 to 70 so the
// shared pool never runs out mid-suite.
$rotiProducts = productsInDivision($pdo, $rotiBollenDivId, 70);
expect(count($rotiProducts) >= 70, 'expected enough katalog products after bootstrap');
$pool = $rotiProducts;
function nextProduct(): array { global $pool; $p = array_shift($pool); expect($p !== null, 'ran out of pooled test products'); return $p; }

$secondDivProducts = productsInDivision($pdo, $secondDivId, 5);
expect(count($secondDivProducts) >= 1, 'expected at least one product in the second division');
$secondDivPool = $secondDivProducts;
function nextProductFromSecondDivision(): array { global $secondDivPool; $p = array_shift($secondDivPool); expect($p !== null, 'ran out of pooled second-division test products'); return $p; }

// A genuinely different FACTORY (Cibadak, via its Bolu division) — needed
// for the multi-factory departure regression tests below. Mirrors the same
// cross-factory-single-DO fixture pattern already proven in
// Phase5DoShipmentTest.php's own P5-05.
$cibadakId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Cibadak'")->fetchColumn();
expect($cibadakId > 0, 'expected Cibadak factory seeded');
$boluDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Bolu'")->fetchColumn();
expect($boluDivId > 0, 'expected Bolu division seeded (Cibadak factory)');
$boluProducts = productsInDivision($pdo, $boluDivId, 5);
expect(count($boluProducts) >= 1, 'expected at least one Cibadak/Bolu product for multi-factory tests');
$boluPool = $boluProducts;
function nextProductFromBolu(): array { global $boluPool; $p = array_shift($boluPool); expect($p !== null, 'ran out of pooled Bolu (Cibadak) test products'); return $p; }

$driverAId = createUser($pdo, 'p55_driver_a', 'DriverAPass123', ['DRIVER']);
$driverBId = createUser($pdo, 'p55_driver_b', 'DriverBPass123', ['DRIVER']);
$httpA = new Http55($baseUrl);
$csrfA = login($httpA, 'p55_driver_a', 'DriverAPass123');
$httpB = new Http55($baseUrl);
$csrfB = login($httpB, 'p55_driver_b', 'DriverBPass123');

// ---------------------------------------------------------------------
// P55-01
// ---------------------------------------------------------------------
runTest('P55-01 dispatch tasks derived correctly from open DO items', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-07-01';
    $p1 = nextProduct();
    $p2 = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p1['product_id'] => ['poAwal' => 10.0], $p2['product_id'] => ['poAwal' => 4.0]]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);

    $avail = $adminHttp->request('GET', "/api/dispatch/available?tanggal={$tanggal}");
    expect($avail['status'] === 200, 'available failed: ' . json_encode($avail['json']));
    $items = $avail['json']['data']['items'];
    $found1 = null; $found2 = null;
    foreach ($items as $it) {
        if ($it['doId'] === $do['doId'] && $it['productId'] === $p1['product_id']) $found1 = $it;
        if ($it['doId'] === $do['doId'] && $it['productId'] === $p2['product_id']) $found2 = $it;
    }
    expect($found1 !== null && $found2 !== null, 'expected both DO items to appear in the dispatch pool');
    expect(abs($found1['availableToClaim'] - 10.0) < 0.001, 'expected item1 availableToClaim=10');
    expect(abs($found2['availableToClaim'] - 4.0) < 0.001, 'expected item2 availableToClaim=4');
    expect($found1['shippedQty'] == 0 && $found1['activeClaimedQty'] == 0, 'expected zero shipped/claimed before any action');
});

// ---------------------------------------------------------------------
// P55-02
// ---------------------------------------------------------------------
runTest('P55-02 claiming a task writes ZERO stock_ledger rows', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-07-02';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 8.0]]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $doItemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);

    $before = shipmentOutRows($pdo);
    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $doItemId, 'qty' => 8.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-02-claim')));
    expect($claim['status'] === 200, 'claim failed: ' . json_encode($claim['json']));
    $after = shipmentOutRows($pdo);
    expect($before === $after, "expected zero new shipment_out rows from claiming, before={$before} after={$after}");

    $row = $pdo->query("SELECT * FROM dispatch_claim WHERE delivery_order_item_id = {$doItemId}")->fetch();
    expect($row !== false && (float) $row['claimed_qty'] === 8.0 && $row['status'] === 'active', 'expected an active dispatch_claim row with claimed_qty=8');
});

// ---------------------------------------------------------------------
// P55-03
// ---------------------------------------------------------------------
runTest('P55-03 the same DO can be split across two different drivers (different products)', function () use ($httpA, $csrfA, $httpB, $csrfB, $pdo, $karangtengahId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-07-03';
    $p1 = nextProduct();
    $p2 = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p1['product_id'] => ['poAwal' => 6.0], $p2['product_id'] => ['poAwal' => 9.0]]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $item1 = doItemIdFor($pdo, $do['doId'], $p1['product_id']);
    $item2 = doItemIdFor($pdo, $do['doId'], $p2['product_id']);

    $claimA = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $item1, 'qty' => 6.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-03-a')));
    expect($claimA['status'] === 200, 'driver A claim failed: ' . json_encode($claimA['json']));
    $claimB = $httpB->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $item2, 'qty' => 9.0]]], array_merge(['X-CSRF-Token' => $csrfB], idemKey('p55-03-b')));
    expect($claimB['status'] === 200, 'driver B claim failed: ' . json_encode($claimB['json']));

    $mineA = $httpA->request('GET', "/api/dispatch/mine?tanggal={$tanggal}");
    $mineB = $httpB->request('GET', "/api/dispatch/mine?tanggal={$tanggal}");
    $productsA = array_column($mineA['json']['data']['stores'][0]['items'] ?? [], 'productId');
    $productsB = array_column($mineB['json']['data']['stores'][0]['items'] ?? [], 'productId');
    expect(in_array($p1['product_id'], $productsA, true) && !in_array($p2['product_id'], $productsA, true), 'driver A should only see product1');
    expect(in_array($p2['product_id'], $productsB, true) && !in_array($p1['product_id'], $productsB, true), 'driver B should only see product2');
});

// ---------------------------------------------------------------------
// P55-04
// ---------------------------------------------------------------------
runTest('P55-04 the same product line can be split across two drivers by quantity', function () use ($httpA, $csrfA, $httpB, $csrfB, $pdo, $karangtengahId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-07-04';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 30.0]]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);

    $claimA = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 10.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-04-a')));
    expect($claimA['status'] === 200, 'driver A claim failed: ' . json_encode($claimA['json']));
    $claimB = $httpB->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 20.0]]], array_merge(['X-CSRF-Token' => $csrfB], idemKey('p55-04-b')));
    expect($claimB['status'] === 200, 'driver B claim failed: ' . json_encode($claimB['json']));

    $sum = (float) $pdo->query("SELECT SUM(active_qty) FROM dispatch_claim WHERE delivery_order_item_id = {$itemId} AND status = 'active'")->fetchColumn();
    expect(abs($sum - 30.0) < 0.001, "expected 30 total claimed across both drivers, got {$sum}");

    $extra = $adminHttp->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 1.0]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('p55-04-over')));
    expect($extra['status'] === 409, "expected 409 once fully claimed, got {$extra['status']}");
});

// ---------------------------------------------------------------------
// P55-05
// ---------------------------------------------------------------------
runTest('P55-05 cannot claim more than the DO item\'s remaining qty', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-07-05';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);

    $claim1 = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 6.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-05-a')));
    expect($claim1['status'] === 200, 'first claim failed: ' . json_encode($claim1['json']));
    $claim2 = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 5.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-05-b')));
    expect($claim2['status'] === 409, "expected 409 EXCEEDS remaining (only 4 left), got {$claim2['status']}: " . json_encode($claim2['json']));
    expect($claim2['json']['code'] === 'DISPATCH_ALREADY_CLAIMED', 'expected DISPATCH_ALREADY_CLAIMED code');
});

// ---------------------------------------------------------------------
// P55-06 — real concurrency via curl_multi: two drivers race for the SAME
// final unit; exactly one must win, the other must get a clean 409, and
// the sum actually reserved must never exceed what was available.
// ---------------------------------------------------------------------
runTest('P55-06 concurrent claims for the last unit are safe (no double-reservation)', function () use ($pdo, $karangtengahId, $storeA, $adminHttp, $adminCsrf, $httpA, $httpB, $csrfA, $csrfB) {
    $tanggal = '2026-07-06';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);

    // Leave exactly 1 unit remaining, then fire two simultaneous claims of qty=1 each.
    $pre = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 9.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-06-pre')));
    expect($pre['status'] === 200, 'pre-claim failed: ' . json_encode($pre['json']));

    $mh = curl_multi_init();
    $reqs = [];
    foreach ([[$httpA, $csrfA, 'race-a'], [$httpB, $csrfB, 'race-b']] as [$h, $csrf, $tag]) {
        $ch = curl_init("{$GLOBALS['baseUrl']}/api/dispatch/claim");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => $h->cookieJarPath(),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: {$csrf}", 'Idempotency-Key: ' . $tag . '-' . uniqid('', true)],
            CURLOPT_POSTFIELDS => json_encode(['lines' => [['doItemId' => $itemId, 'qty' => 1.0]]]),
        ]);
        curl_multi_add_handle($mh, $ch);
        $reqs[] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    $statuses = [];
    foreach ($reqs as $ch) {
        $statuses[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    $okCount = count(array_filter($statuses, fn ($s) => $s === 200));
    $conflictCount = count(array_filter($statuses, fn ($s) => $s === 409));
    expect($okCount === 1, 'expected exactly one of the two concurrent claims to succeed, got statuses ' . json_encode($statuses));
    expect($conflictCount === 1, 'expected exactly one 409 conflict, got statuses ' . json_encode($statuses));

    $sum = (float) $pdo->query("SELECT SUM(active_qty) FROM dispatch_claim WHERE delivery_order_item_id = {$itemId} AND status = 'active'")->fetchColumn();
    expect(abs($sum - 10.0) < 0.001, "expected total active claims to be exactly 10 (never overbooked), got {$sum}");
});

// ---------------------------------------------------------------------
// P55-07
// ---------------------------------------------------------------------
runTest('P55-07 releasing a claim frees the qty back to the pool', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-07-07';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);

    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 5.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-07-claim')));
    expect($claim['status'] === 200, 'claim failed: ' . json_encode($claim['json']));
    $claimId = $claim['json']['data']['claims'][0]['claimId'];

    $release = $httpA->request('POST', "/api/dispatch/claims/{$claimId}/release", [], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-07-release')));
    expect($release['status'] === 200, 'release failed: ' . json_encode($release['json']));

    $row = $pdo->query("SELECT * FROM dispatch_claim WHERE dispatch_claim_id = {$claimId}")->fetch();
    expect($row['status'] === 'released' && (float) $row['active_qty'] === 0.0 && (float) $row['released_qty'] === 5.0, 'expected claim to be fully released');

    $avail = $adminHttp->request('GET', "/api/dispatch/available?tanggal={$tanggal}");
    $found = null;
    foreach ($avail['json']['data']['items'] as $it) {
        if ($it['doItemId'] === $itemId) $found = $it;
    }
    expect($found !== null && abs($found['availableToClaim'] - 5.0) < 0.001, 'expected the released qty to be claimable again');
});

// ---------------------------------------------------------------------
// P55-08
// ---------------------------------------------------------------------
runTest('P55-08 driver route ordering can be reordered and persists', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $storeA, $storeB, $adminHttp, $adminCsrf) {
    $tanggal = '2026-07-08';
    $p1 = nextProduct();
    $p2 = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p1['product_id'] => ['poAwal' => 3.0]]);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeB, [$p2['product_id'] => ['poAwal' => 3.0]]);
    $doA = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $doB = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeB);
    $itemA = doItemIdFor($pdo, $doA['doId'], $p1['product_id']);
    $itemB = doItemIdFor($pdo, $doB['doId'], $p2['product_id']);

    $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemA, 'qty' => 3.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-08-a')));
    $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemB, 'qty' => 3.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-08-b')));

    $route = $httpA->request('GET', "/api/dispatch/route?tanggal={$tanggal}");
    expect($route['status'] === 200, 'route fetch failed: ' . json_encode($route['json']));
    $stops = $route['json']['data']['stops'];
    expect(count($stops) === 2, 'expected 2 route stops (storeA then storeB, in claim order)');
    expect($stops[0]['storeId'] === $storeA && $stops[1]['storeId'] === $storeB, 'expected storeA before storeB initially');

    $reorder = $httpA->request('POST', '/api/dispatch/route/reorder', ['tanggal' => $tanggal, 'storeIds' => [$storeB, $storeA]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-08-reorder')));
    expect($reorder['status'] === 200, 'reorder failed: ' . json_encode($reorder['json']));
    $stopsAfter = $reorder['json']['data']['stops'];
    expect($stopsAfter[0]['storeId'] === $storeB && $stopsAfter[1]['storeId'] === $storeA, 'expected storeB now first after reorder');

    $routeAgain = $httpA->request('GET', "/api/dispatch/route?tanggal={$tanggal}");
    expect($routeAgain['json']['data']['stops'][0]['storeId'] === $storeB, 'expected reorder to persist across a fresh fetch');
});

// ---------------------------------------------------------------------
// P55-09
// ---------------------------------------------------------------------
runTest('P55-09 a driver never sees another driver\'s claims/route', function () use ($httpA, $csrfA, $httpB, $csrfB, $pdo, $karangtengahId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-07-09';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 4.0]]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);

    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 4.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-09')));
    expect($claim['status'] === 200, 'claim failed: ' . json_encode($claim['json']));

    $mineB = $httpB->request('GET', "/api/dispatch/mine?tanggal={$tanggal}");
    expect($mineB['json']['data']['stores'] === [], 'expected driver B to see NO stores/claims belonging to driver A');
    $routeB = $httpB->request('GET', "/api/dispatch/route?tanggal={$tanggal}");
    expect($routeB['json']['data']['stops'] === [], 'expected driver B to have an empty route (A\'s stop must not leak)');
});

// ---------------------------------------------------------------------
// P55-10 / P55-11
// ---------------------------------------------------------------------
runTest('P55-10 / P55-11 confirm departure creates a real shipment and deducts stock ONLY then', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-07-10';
    $p = nextProduct();
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);

    $before = shipmentOutRows($pdo);
    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 10.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-10-claim')));
    expect($claim['status'] === 200, 'claim failed: ' . json_encode($claim['json']));
    $claimId = $claim['json']['data']['claims'][0]['claimId'];
    expect(shipmentOutRows($pdo) === $before, 'claiming must not touch stock_ledger');

    $stop = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    expect($stop['status'] === 200, 'stop detail failed: ' . json_encode($stop['json']));
    expect(shipmentOutRows($pdo) === $before, 'viewing the departure detail screen must not touch stock_ledger');
    $doVersion = $stop['json']['data']['doVersion'];

    $depart = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $doVersion, 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimId, 'actualQty' => 10.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-10-depart')));
    expect($depart['status'] === 200, 'departure failed: ' . json_encode($depart['json']));
    expect($depart['json']['data']['shipmentsCreated'] === 1, 'expected exactly one shipment created');
    $shipmentId = $depart['json']['data']['shipments'][0]['shipmentId'];

    $after = shipmentOutRows($pdo);
    expect($after === $before + 1, "expected exactly one new shipment_out row from departure, before={$before} after={$after}");

    $shRow = $pdo->query("SELECT * FROM shipment WHERE shipment_id = {$shipmentId}")->fetch();
    expect($shRow !== false && $shRow['status'] === 'active' && (int) $shRow['delivery_order_id'] === $do['doId'], 'expected a real active shipment row linked to this DO');

    $claimRow = $pdo->query("SELECT * FROM dispatch_claim WHERE dispatch_claim_id = {$claimId}")->fetch();
    expect($claimRow['status'] === 'departed' && (float) $claimRow['departed_qty'] === 10.0, 'expected the claim to be resolved as fully departed');
});

// ---------------------------------------------------------------------
// P55-12
// ---------------------------------------------------------------------
runTest('P55-12 a stale expectedVersion at departure causes ZERO stock change', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-07-12';
    $p = nextProduct();
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);
    $staleVersion = $do['version'];

    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 10.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-12-claim')));
    $claimId = $claim['json']['data']['claims'][0]['claimId'];

    // Bump the DO's version out from under the driver via preprint (a real Phase 5 action).
    $preprint = $adminHttp->request('POST', "/api/do/{$do['doId']}/preprint", ['expectedVersion' => $staleVersion], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('p55-12-preprint')));
    expect($preprint['status'] === 200, 'preprint failed: ' . json_encode($preprint['json']));

    $before = shipmentOutRows($pdo);
    $depart = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $staleVersion, 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimId, 'actualQty' => 10.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-12-depart')));
    expect($depart['status'] === 409, "expected 409 VERSION_CONFLICT, got {$depart['status']}: " . json_encode($depart['json']));
    expect(shipmentOutRows($pdo) === $before, 'a rejected stale-version departure must leave stock_ledger untouched');

    $claimRow = $pdo->query("SELECT * FROM dispatch_claim WHERE dispatch_claim_id = {$claimId}")->fetch();
    expect($claimRow['status'] === 'active', 'the claim must remain active — a failed departure must not resolve it');
});

// ---------------------------------------------------------------------
// P55-13
// ---------------------------------------------------------------------
runTest('P55-13 departure is rejected when it would exceed live FG availability', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-07-13';
    $p = nextProduct();
    // Only 3 units of real FG stock, but the driver claims (and the DO plans) 10.
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 3.0);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);

    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 10.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-13-claim')));
    $claimId = $claim['json']['data']['claims'][0]['claimId'];

    $before = shipmentOutRows($pdo);
    $depart = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $do['version'], 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimId, 'actualQty' => 10.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-13-depart')));
    expect($depart['status'] === 409, "expected 409 INSUFFICIENT_FG_AVAILABLE, got {$depart['status']}: " . json_encode($depart['json']));
    expect($depart['json']['code'] === 'INSUFFICIENT_FG_AVAILABLE', 'expected INSUFFICIENT_FG_AVAILABLE code');
    expect(shipmentOutRows($pdo) === $before, 'a rejected over-FG departure must leave stock_ledger untouched');

    $claimRow = $pdo->query("SELECT * FROM dispatch_claim WHERE dispatch_claim_id = {$claimId}")->fetch();
    expect($claimRow['status'] === 'active', 'the claim must remain active after a rejected departure');
});

// ---------------------------------------------------------------------
// P55-14
// ---------------------------------------------------------------------
runTest('P55-14 QR/receipt token generation is read-only and idempotent', function () use ($pdo, $karangtengahId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-07-14';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);

    $service = new \Amor\Api\Dispatch\ReceiptService($pdo);
    $doVersionBefore = (int) $pdo->query("SELECT version FROM delivery_order WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    $doStatusBefore = (string) $pdo->query("SELECT status FROM delivery_order WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    $shipmentsBefore = (int) $pdo->query("SELECT COUNT(*) FROM shipment WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    $ledgerBefore = shipmentOutRows($pdo);

    $token1 = $service->getReceiptToken($do['doId']);
    $token2 = $service->getReceiptToken($do['doId']);
    expect($token1 === $token2, 'expected the SAME token on repeated calls, never regenerated');
    expect(strlen($token1) === 64, 'expected a 64-hex-char (32-byte) high-entropy token');

    $tokenRows = (int) $pdo->query("SELECT COUNT(*) FROM delivery_receipt_token WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    expect($tokenRows === 1, 'expected exactly one token row, even after two get-or-create calls');

    $doVersionAfter = (int) $pdo->query("SELECT version FROM delivery_order WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    $doStatusAfter = (string) $pdo->query("SELECT status FROM delivery_order WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    $shipmentsAfter = (int) $pdo->query("SELECT COUNT(*) FROM shipment WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    expect($doVersionBefore === $doVersionAfter, 'token generation must never bump delivery_order.version');
    expect($doStatusBefore === $doStatusAfter, 'token generation must never change delivery_order.status');
    expect($shipmentsBefore === $shipmentsAfter, 'token generation must never create a shipment');
    expect(shipmentOutRows($pdo) === $ledgerBefore, 'token generation must never touch stock_ledger');
});

// ---------------------------------------------------------------------
// P55-15 / P55-16 / P55-17
// ---------------------------------------------------------------------
runTest('P55-15 QR scanned before any departure shows a pending/empty state', function () use ($pdo, $karangtengahId, $storeA, $adminHttp, $adminCsrf, $baseUrl) {
    $tanggal = '2026-07-15';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $service = new \Amor\Api\Dispatch\ReceiptService($pdo);
    $token = $service->getReceiptToken($do['doId']);

    $anon = new Http55($baseUrl);
    $view = $anon->request('GET', "/api/receive/{$token}");
    expect($view['status'] === 200, 'public receive view failed: ' . json_encode($view['json']));
    expect($view['json']['data']['shipments'] === [], 'expected an empty shipments list before any departure');
});

runTest('P55-16 / P55-17 QR shows one shipment after one departure, and BOTH after a second driver departs separately', function () use ($httpA, $csrfA, $httpB, $csrfB, $pdo, $karangtengahId, $rotiBollenDivId, $secondDivId, $storeA, $adminHttp, $adminCsrf, $baseUrl) {
    $tanggal = '2026-07-16';
    $p1 = nextProduct();
    $p2 = nextProductFromSecondDivision();
    stockUpMultiForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $tanggal, $storeA,
        [[$rotiBollenDivId, $p1['product_id'], 5.0, 5.0], [$secondDivId, $p2['product_id'], 4.0, 4.0]],
        [$p1['product_id'] => 5.0, $p2['product_id'] => 4.0]
    );
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $item1 = doItemIdFor($pdo, $do['doId'], $p1['product_id']);
    $item2 = doItemIdFor($pdo, $do['doId'], $p2['product_id']);
    $service = new \Amor\Api\Dispatch\ReceiptService($pdo);
    $token = $service->getReceiptToken($do['doId']);
    $anon = new Http55($baseUrl);

    // Driver A departs with product1 only.
    $claimA = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $item1, 'qty' => 5.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-16-a')));
    $claimIdA = $claimA['json']['data']['claims'][0]['claimId'];
    $stopA = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $departA = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stopA['json']['data']['doVersion'], 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimIdA, 'actualQty' => 5.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-16-depart-a')));
    expect($departA['status'] === 200, 'driver A departure failed: ' . json_encode($departA['json']));

    $viewAfterOne = $anon->request('GET', "/api/receive/{$token}");
    expect(count($viewAfterOne['json']['data']['shipments']) === 1, 'expected exactly 1 shipment after driver A departs');

    // Driver B departs separately with product2 (a distinct shipment).
    $claimB = $httpB->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $item2, 'qty' => 4.0]]], array_merge(['X-CSRF-Token' => $csrfB], idemKey('p55-16-b')));
    $claimIdB = $claimB['json']['data']['claims'][0]['claimId'];
    $stopB = $httpB->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $departB = $httpB->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stopB['json']['data']['doVersion'], 'shipmentGroup' => 'PASTRY',
        'items' => [['claimId' => $claimIdB, 'actualQty' => 4.0]],
    ], array_merge(['X-CSRF-Token' => $csrfB], idemKey('p55-16-depart-b')));
    expect($departB['status'] === 200, 'driver B departure failed: ' . json_encode($departB['json']));

    $viewAfterTwo = $anon->request('GET', "/api/receive/{$token}");
    $shipments = $viewAfterTwo['json']['data']['shipments'];
    expect(count($shipments) === 2, 'expected BOTH shipments listed under the same DO/token');
    $groups = array_column($shipments, 'shipmentGroup');
    expect(in_array('MAIN', $groups, true) && in_array('PASTRY', $groups, true), 'expected one MAIN and one PASTRY shipment listed');

    $GLOBALS['p55_multi_ship_do'] = $do['doId'];
    $GLOBALS['p55_multi_ship_token'] = $token;
    $GLOBALS['p55_multi_ship_ids'] = array_column($shipments, 'shipmentId');
});

// ---------------------------------------------------------------------
// P55-18 / P55-19 / P55-20
// ---------------------------------------------------------------------
runTest('P55-18 / P55-19 / P55-20 store receipt: valid math accepted, invalid rejected, double-submit idempotent', function () use ($pdo, $karangtengahId, $rotiBollenDivId, $storeA, $adminHttp, $adminCsrf, $httpA, $csrfA, $baseUrl) {
    $tanggal = '2026-07-18';
    $p = nextProduct();
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);
    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 10.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-18-claim')));
    $claimId = $claim['json']['data']['claims'][0]['claimId'];
    $stop = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $depart = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stop['json']['data']['doVersion'], 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimId, 'actualQty' => 10.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-18-depart')));
    $shipmentId = $depart['json']['data']['shipments'][0]['shipmentId'];

    $service = new \Amor\Api\Dispatch\ReceiptService($pdo);
    $token = $service->getReceiptToken($do['doId']);
    $anon = new Http55($baseUrl);

    $view = $anon->request('GET', "/api/receive/{$token}");
    $shipmentItemId = $view['json']['data']['shipments'][0]['items'][0]['shipmentItemId'];

    // P55-19 first: invalid math (good+reject+shortage != shipped=10).
    $bad = $anon->request('POST', "/api/receive/{$token}/shipments/{$shipmentId}/confirm", [
        'receiverName' => 'Budi', 'items' => [['shipmentItemId' => $shipmentItemId, 'receivedGood' => 8, 'reject' => 1, 'shortage' => 0]],
    ], idemKey('p55-19-bad'));
    expect($bad['status'] === 400, "expected 400 RECEIPT_MATH_INVALID, got {$bad['status']}: " . json_encode($bad['json']));
    expect($bad['json']['code'] === 'RECEIPT_MATH_INVALID', 'expected RECEIPT_MATH_INVALID code');
    $countBefore = (int) $pdo->query("SELECT COUNT(*) FROM shipment_receipt WHERE shipment_id = {$shipmentId}")->fetchColumn();
    expect($countBefore === 0, 'an invalid-math attempt must not create a receipt row');

    // P55-18: valid math accepted. Has a reject qty, so real-UAT photo
    // evidence rule applies — attach one real (valid) evidence photo.
    $good = $anon->requestMultipart('POST', "/api/receive/{$token}/shipments/{$shipmentId}/confirm", [
        'receiverName' => 'Budi', 'items' => json_encode([['shipmentItemId' => $shipmentItemId, 'receivedGood' => 9, 'reject' => 1, 'shortage' => 0]]),
    ], ['evidence[]' => fakeEvidenceImage()], idemKey('p55-18-good'));
    expect($good['status'] === 200, 'valid confirm failed: ' . json_encode($good['json']));
    expect($good['json']['data']['status'] === 'confirmed_discrepancy', 'expected confirmed_discrepancy status (a reject was reported)');

    // P55-20: double-submit with DIFFERENT data must return the EXISTING confirmation unchanged, never double-count.
    $again = $anon->request('POST', "/api/receive/{$token}/shipments/{$shipmentId}/confirm", [
        'receiverName' => 'Someone Else', 'items' => [['shipmentItemId' => $shipmentItemId, 'receivedGood' => 10, 'reject' => 0, 'shortage' => 0]],
    ], idemKey('p55-20-again'));
    expect($again['status'] === 200, 'double-submit should not error: ' . json_encode($again['json']));
    expect($again['json']['data']['receiptId'] === $good['json']['data']['receiptId'], 'expected the SAME receipt returned, not a new one');
    expect((float) $again['json']['data']['items'][0]['receivedGoodQty'] === 9.0, 'expected the ORIGINAL confirmed values to be preserved, not overwritten');
    $countAfter = (int) $pdo->query("SELECT COUNT(*) FROM shipment_receipt WHERE shipment_id = {$shipmentId}")->fetchColumn();
    expect($countAfter === 1, 'expected exactly one shipment_receipt row even after two confirm attempts');

    $GLOBALS['p55_receipt_id'] = $good['json']['data']['receiptId'];
});

// ---------------------------------------------------------------------
// P55-21
// ---------------------------------------------------------------------
runTest('P55-21 a DO\'s public token cannot be used to reach a different DO\'s shipment', function () use ($pdo, $karangtengahId, $storeA, $storeB, $adminHttp, $adminCsrf, $baseUrl) {
    $tanggal = '2026-07-21';
    $p1 = nextProduct();
    $p2 = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p1['product_id'] => ['poAwal' => 3.0]]);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeB, [$p2['product_id'] => ['poAwal' => 3.0]]);
    $doA = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $doB = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeB);
    $service = new \Amor\Api\Dispatch\ReceiptService($pdo);
    $tokenA = $service->getReceiptToken($doA['doId']);
    expect(strlen($tokenA) === 64, 'sanity: token looks right');

    $anon = new Http55($baseUrl);
    $bogus = $anon->request('GET', '/api/receive/not-a-real-token-at-all');
    expect($bogus['status'] === 404, "expected 404 for a garbage token, got {$bogus['status']}");

    // DO B's own view must be reachable only by DO B's own token, never DO A's.
    $tokenB = $service->getReceiptToken($doB['doId']);
    expect($tokenA !== $tokenB, 'sanity: two different DOs must never share a token');
    $viewWithWrongToken = $anon->request('GET', "/api/receive/{$tokenA}");
    expect(stripos($viewWithWrongToken['json']['data']['storeName'] ?? '', 'STORE B') === false, 'DO A\'s token must never reveal store B\'s data');
});

// ---------------------------------------------------------------------
// P55-22
// ---------------------------------------------------------------------
runTest('P55-22 admin can verify a discrepancy receipt exactly once', function () use ($pdo, $adminHttp, $adminCsrf) {
    $receiptId = $GLOBALS['p55_receipt_id'] ?? null;
    expect($receiptId !== null, 'expected P55-18/19/20 to have run first and set a receiptId');

    $list = $adminHttp->request('GET', '/api/admin/receipts');
    expect($list['status'] === 200, 'admin list failed: ' . json_encode($list['json']));
    $found = null;
    foreach ($list['json']['data'] as $row) {
        if ($row['receiptId'] === $receiptId) $found = $row;
    }
    expect($found !== null && $found['status'] === 'confirmed_discrepancy', 'expected the receipt to show as confirmed_discrepancy in the admin list');

    $verify = $adminHttp->request('POST', "/api/admin/receipts/{$receiptId}/verify", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('p55-22-verify')));
    expect($verify['status'] === 200, 'verify failed: ' . json_encode($verify['json']));
    expect($verify['json']['data']['status'] === 'verified', 'expected status=verified after admin verification');

    $verifyAgain = $adminHttp->request('POST', "/api/admin/receipts/{$receiptId}/verify", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('p55-22-verify-again')));
    expect($verifyAgain['status'] === 409, "expected 409 on a second verify attempt, got {$verifyAgain['status']}");
});

// ---------------------------------------------------------------------
// P55-23
// ---------------------------------------------------------------------
runTest('P55-23 the existing (non-dispatch) Phase 5 manual ship() flow still works unchanged', function () use ($pdo, $karangtengahId, $rotiBollenDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-07-23';
    $p = nextProduct();
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 6.0, 6.0, 6.0);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);

    $ship = $adminHttp->request('POST', "/api/do/{$do['doId']}/ship", [
        'expectedVersion' => $do['version'], 'shipmentGroup' => 'MAIN', 'items' => [['productId' => $p['product_id'], 'actualQty' => 6.0]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('p55-23-ship')));
    expect($ship['status'] === 200, 'plain Phase 5 ship() failed: ' . json_encode($ship['json']));
    expect($ship['json']['data']['doFullyFulfilled'] === true, 'expected the DO to be fully fulfilled');
});

// ---------------------------------------------------------------------
// P55-MF01 / P55-MF02 — multi-factory departure claim->shipment mapping.
//
// Regression for a real bug found by audit: DepartureService used to
// stamp EVERY resolved claim with whichever factory group's ship() call
// happened to run LAST ("lastShipmentId"), instead of the shipment that
// actually contains that claim's own product. A driver claiming from two
// factories in one departure got two real, correctly-separated shipments
// (that part was always right), but BOTH claims were pointing at only the
// second one — dispatch_claim.shipment_id was simply wrong for the first
// factory's claim. See DepartureService::confirmDeparture's per-factory
// $shipmentIdByClaimId map, which replaced the single trailing variable.
// ---------------------------------------------------------------------
runTest('P55-MF01 multi-factory departure maps each claim to its OWN factory shipment (never cross-linked)', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $cibadakId, $rotiBollenDivId, $boluDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-08-01';
    $pK = nextProduct();
    $pC = nextProductFromBolu();
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $pK['product_id'], 3.0, 3.0, 3.0);
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $cibadakId, $boluDivId, $tanggal, $storeA, $pC['product_id'], 4.0, 4.0, 4.0);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemK = doItemIdFor($pdo, $do['doId'], $pK['product_id']);
    $itemC = doItemIdFor($pdo, $do['doId'], $pC['product_id']);

    $claim = $httpA->request('POST', '/api/dispatch/claim', [
        'lines' => [['doItemId' => $itemK, 'qty' => 3.0], ['doItemId' => $itemC, 'qty' => 4.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-mf01-claim')));
    expect($claim['status'] === 200, 'multi-factory claim failed: ' . json_encode($claim['json']));
    $claimK = null; $claimC = null;
    foreach ($claim['json']['data']['claims'] as $c) {
        if ($c['productId'] === $pK['product_id']) $claimK = $c['claimId'];
        if ($c['productId'] === $pC['product_id']) $claimC = $c['claimId'];
    }
    expect($claimK !== null && $claimC !== null, 'expected one claim per product');

    $stop = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    expect($stop['status'] === 200, 'stop detail failed: ' . json_encode($stop['json']));

    $ledgerBefore = shipmentOutRows($pdo);
    $shipmentsBefore = (int) $pdo->query("SELECT COUNT(*) FROM shipment WHERE delivery_order_id = {$do['doId']}")->fetchColumn();

    // Request order is K then C — Karangtengah's ship() call runs first,
    // Cibadak's second, exercising the exact "last group wins" bug shape.
    $depart = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stop['json']['data']['doVersion'], 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimK, 'actualQty' => 3.0], ['claimId' => $claimC, 'actualQty' => 4.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-mf01-depart')));
    expect($depart['status'] === 200, 'multi-factory departure failed: ' . json_encode($depart['json']));
    expect($depart['json']['data']['shipmentsCreated'] === 2, 'expected exactly 2 shipments (one per factory)');

    $shipmentIdK = null; $shipmentIdC = null;
    foreach ($depart['json']['data']['shipments'] as $sh) {
        foreach ($sh['items'] as $it) {
            if ($it['productId'] === $pK['product_id']) $shipmentIdK = $sh['shipmentId'];
            if ($it['productId'] === $pC['product_id']) $shipmentIdC = $sh['shipmentId'];
        }
    }
    expect($shipmentIdK !== null && $shipmentIdC !== null && $shipmentIdK !== $shipmentIdC, 'expected two DISTINCT shipments, one per product/factory');

    // The core regression assertion: each claim's own shipment_id must
    // point to the shipment that actually contains that claim's product —
    // never to the other factory's shipment.
    $rowK = $pdo->query("SELECT shipment_id FROM dispatch_claim WHERE dispatch_claim_id = {$claimK}")->fetch();
    $rowC = $pdo->query("SELECT shipment_id FROM dispatch_claim WHERE dispatch_claim_id = {$claimC}")->fetch();
    expect((int) $rowK['shipment_id'] === $shipmentIdK, "claim K must point to its own factory's shipment ({$shipmentIdK}), got " . $rowK['shipment_id']);
    expect((int) $rowC['shipment_id'] === $shipmentIdC, "claim C must point to its own factory's shipment ({$shipmentIdC}), got " . $rowC['shipment_id']);
    expect((int) $rowK['shipment_id'] !== (int) $rowC['shipment_id'], 'claim K and claim C must NEVER be cross-linked to the same shipment');

    // Stock ledger correctness: each factory's stock_ledger row must trace
    // back (via shipment_item) to the RIGHT shipment for the RIGHT product.
    $ledgerRowK = $pdo->query(
        "SELECT sl.stock_ledger_id FROM stock_ledger sl
         INNER JOIN shipment_item si ON si.shipment_item_id = sl.source_id AND sl.source_type = 'shipment_item'
         WHERE sl.event_type = 'shipment_out' AND si.shipment_id = {$shipmentIdK} AND si.product_id = {$pK['product_id']}"
    )->fetch();
    $ledgerRowC = $pdo->query(
        "SELECT sl.stock_ledger_id FROM stock_ledger sl
         INNER JOIN shipment_item si ON si.shipment_item_id = sl.source_id AND sl.source_type = 'shipment_item'
         WHERE sl.event_type = 'shipment_out' AND si.shipment_id = {$shipmentIdC} AND si.product_id = {$pC['product_id']}"
    )->fetch();
    expect($ledgerRowK !== false, "expected a stock_ledger shipment_out row tracing to shipment K ({$shipmentIdK}) for product K");
    expect($ledgerRowC !== false, "expected a stock_ledger shipment_out row tracing to shipment C ({$shipmentIdC}) for product C");

    expect(shipmentOutRows($pdo) === $ledgerBefore + 2, 'expected exactly 2 new shipment_out ledger rows (one per factory)');
    $shipmentsAfter = (int) $pdo->query("SELECT COUNT(*) FROM shipment WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    expect($shipmentsAfter === $shipmentsBefore + 2, 'expected exactly 2 new shipment rows');
});

runTest('P55-MF02 multi-factory departure rolls back COMPLETELY if the second factory group fails', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $cibadakId, $rotiBollenDivId, $boluDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-08-02';
    $pK = nextProduct();
    $pC = nextProductFromBolu();
    // Factory A (Karangtengah) has plenty of FG — its ship() call would
    // succeed internally. Factory B (Cibadak) is deliberately starved of
    // FG (only 1 unit on hand vs 4 claimed/requested) so ITS ship() call
    // throws INSUFFICIENT_FG_AVAILABLE, forcing the whole departure to
    // fail AFTER the first factory group already wrote its shipment/
    // shipment_item/stock_ledger rows inside the same open transaction.
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $pK['product_id'], 3.0, 3.0, 3.0);
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $cibadakId, $boluDivId, $tanggal, $storeA, $pC['product_id'], 4.0, 4.0, 1.0);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemK = doItemIdFor($pdo, $do['doId'], $pK['product_id']);
    $itemC = doItemIdFor($pdo, $do['doId'], $pC['product_id']);

    $claim = $httpA->request('POST', '/api/dispatch/claim', [
        'lines' => [['doItemId' => $itemK, 'qty' => 3.0], ['doItemId' => $itemC, 'qty' => 4.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-mf02-claim')));
    expect($claim['status'] === 200, 'multi-factory claim failed: ' . json_encode($claim['json']));
    $claimK = null; $claimC = null;
    foreach ($claim['json']['data']['claims'] as $c) {
        if ($c['productId'] === $pK['product_id']) $claimK = $c['claimId'];
        if ($c['productId'] === $pC['product_id']) $claimC = $c['claimId'];
    }
    expect($claimK !== null && $claimC !== null, 'expected one claim per product');

    $stop = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $doVersionBefore = $stop['json']['data']['doVersion'];

    $ledgerBefore = shipmentOutRows($pdo);
    $shipmentsBefore = (int) $pdo->query("SELECT COUNT(*) FROM shipment WHERE delivery_order_id = {$do['doId']}")->fetchColumn();

    $depart = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $doVersionBefore, 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimK, 'actualQty' => 3.0], ['claimId' => $claimC, 'actualQty' => 4.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('p55-mf02-depart')));
    expect($depart['status'] === 409, "expected 409 INSUFFICIENT_FG_AVAILABLE from the second (Cibadak) group, got {$depart['status']}: " . json_encode($depart['json']));
    expect($depart['json']['code'] === 'INSUFFICIENT_FG_AVAILABLE', 'expected INSUFFICIENT_FG_AVAILABLE code');

    // Nothing from the FIRST (successful-until-rollback) factory group may
    // survive — the whole departure is one transaction (Idempotency::handle
    // wraps confirmDeparture in Database::transaction; ShipmentService::ship()
    // never begins its own nested transaction, so both ship() calls share
    // this one).
    expect(shipmentOutRows($pdo) === $ledgerBefore, 'a failed multi-factory departure must leave ZERO new stock_ledger rows, including from the factory that succeeded internally');
    $shipmentsAfter = (int) $pdo->query("SELECT COUNT(*) FROM shipment WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    expect($shipmentsAfter === $shipmentsBefore, 'a failed multi-factory departure must leave ZERO new shipment rows, including from the factory that succeeded internally');

    $rowK = $pdo->query("SELECT * FROM dispatch_claim WHERE dispatch_claim_id = {$claimK}")->fetch();
    $rowC = $pdo->query("SELECT * FROM dispatch_claim WHERE dispatch_claim_id = {$claimC}")->fetch();
    expect($rowK['status'] === 'active' && (float) $rowK['active_qty'] === 3.0 && $rowK['shipment_id'] === null, 'claim K must remain fully active/unresolved/unlinked after the rollback');
    expect($rowC['status'] === 'active' && (float) $rowC['active_qty'] === 4.0 && $rowC['shipment_id'] === null, 'claim C must remain fully active/unresolved/unlinked after the rollback');

    $doVersionAfter = (int) $pdo->query("SELECT version FROM delivery_order WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    expect($doVersionAfter === $doVersionBefore, 'the DO version must not have been bumped by a departure that ultimately failed');

    // The claims are still fully claimable/departable again afterward —
    // "recoverable", not stuck in a half-resolved state.
    $stopAgain = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    expect($stopAgain['status'] === 200, 'expected the stop/claims to still be usable after a rolled-back departure');
});

// ---------------------------------------------------------------------
// DPT-05, DPT-07..19 — Driver Portal UX + Shipment Tracing patch. Real-UAT
// case: DO/KRM/004/IX/2026, Bakery Abdul Gani, Driver A, AVOCADO RING 5 +
// BOLLEN KOMBINASI 2 (2 produk, 7 pcs). DPT-01..04/06 (confirm-modal DOM
// behavior: single centered overlay, no stacking, Batal closes cleanly,
// button disables while pending, success screen renders real data) are
// pure client-side DOM/CSS behavior with no server-observable effect
// beyond DPT-05's own idempotency — verified separately via code review
// of app.js/driver.js/driver.css plus a manual/browser check, per this
// task's own "Add tests / browser checks where practical" allowance; see
// the final report's own test section for that write-up.
// ---------------------------------------------------------------------
runTest('DPT-05 a double-submitted departure (same Idempotency-Key) results in exactly one shipment', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-08-05';
    $p = nextProduct();
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);
    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 10.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt05-claim')));
    $claimId = $claim['json']['data']['claims'][0]['claimId'];

    $key = 'dpt05-depart-' . uniqid('', true);
    $body = ['doId' => $do['doId'], 'expectedVersion' => $do['version'], 'shipmentGroup' => 'MAIN', 'items' => [['claimId' => $claimId, 'actualQty' => 10.0]]];
    $first = $httpA->request('POST', '/api/dispatch/departures', $body, ['X-CSRF-Token' => $csrfA, 'Idempotency-Key' => $key]);
    $second = $httpA->request('POST', '/api/dispatch/departures', $body, ['X-CSRF-Token' => $csrfA, 'Idempotency-Key' => $key]);
    expect($first['status'] === 200 && $second['status'] === 200, 'expected both replayed departures to return 200');
    expect($first['json'] === $second['json'], 'expected the replayed response to be byte-identical (frontend double-click protection is UX only — this is the real backend guarantee)');

    $count = (int) $pdo->query("SELECT COUNT(*) FROM shipment WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    expect($count === 1, 'expected exactly one shipment row despite the replayed double-submit, got ' . $count);
});

runTest('DPT-07 before departure, route shows active-claim totals', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-08-07';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);
    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 7.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt07-claim')));
    expect($claim['status'] === 200, 'claim failed: ' . json_encode($claim['json']));

    $route = $httpA->request('GET', "/api/dispatch/route?tanggal={$tanggal}");
    expect($route['status'] === 200, 'route fetch failed: ' . json_encode($route['json']));
    $stop = current(array_filter($route['json']['data']['stops'], fn ($s) => $s['storeId'] === $storeA));
    expect($stop !== false, 'expected a route stop for storeA');
    expect($stop['productCount'] === 1 && abs($stop['totalQty'] - 7.0) < 0.001, 'expected active-claim totals 1 produk/7 pcs before departure, got ' . json_encode($stop));
    expect($stop['departureStatus'] === 'belum_berangkat', 'expected belum_berangkat before departure');
});

runTest('DPT-08/DPT-09 after departure, route shows REAL shipment totals — never 0 produk / 0 pcs', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $secondDivId, $storeA, $adminHttp, $adminCsrf) {
    // The exact real-UAT shape: 2 products, 7 pcs total (AVOCADO RING 5 + BOLLEN KOMBINASI 2).
    // Two DIFFERENT divisions, since a production_run is keyed by
    // (tanggal,divisionId) — two products in the SAME division must share
    // one create->patch->submit call (see stockUpMultiForDelivery's own
    // caller convention elsewhere in this file).
    $tanggal = '2026-08-08';
    $p1 = nextProduct();
    $p2 = nextProductFromSecondDivision();
    stockUpMultiForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $tanggal, $storeA,
        [[$rotiBollenDivId, $p1['product_id'], 5.0, 5.0], [$secondDivId, $p2['product_id'], 2.0, 2.0]],
        [$p1['product_id'] => 5.0, $p2['product_id'] => 2.0]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $item1 = doItemIdFor($pdo, $do['doId'], $p1['product_id']);
    $item2 = doItemIdFor($pdo, $do['doId'], $p2['product_id']);

    $claim1 = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $item1, 'qty' => 5.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt08-c1')));
    $claim2 = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $item2, 'qty' => 2.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt08-c2')));
    $claimId1 = $claim1['json']['data']['claims'][0]['claimId'];
    $claimId2 = $claim2['json']['data']['claims'][0]['claimId'];

    $stopBefore = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $depart = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stopBefore['json']['data']['doVersion'], 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimId1, 'actualQty' => 5.0], ['claimId' => $claimId2, 'actualQty' => 2.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt08-depart')));
    expect($depart['status'] === 200, 'departure failed: ' . json_encode($depart['json']));

    $route = $httpA->request('GET', "/api/dispatch/route?tanggal={$tanggal}");
    $stop = current(array_filter($route['json']['data']['stops'], fn ($s) => $s['storeId'] === $storeA));
    expect($stop !== false, 'expected the stop to still be listed after departure');
    expect($stop['departureStatus'] === 'sudah_berangkat', 'expected sudah_berangkat after departure');
    expect($stop['productCount'] === 2 && abs($stop['totalQty'] - 7.0) < 0.001,
        'DPT-09: expected the REAL shipment totals 2 produk / 7 pcs after departure — NOT 0/0 (the real-UAT bug), got ' . json_encode($stop));
});

runTest('DPT-10 a partial departure (claim 5, ship 3) shows the ACTUAL shipped qty on the route, not the original claim', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-08-10';
    $p = nextProduct();
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 5.0, 5.0, 5.0);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);
    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 5.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt10-claim')));
    $claimId = $claim['json']['data']['claims'][0]['claimId'];

    $stop = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $depart = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stop['json']['data']['doVersion'], 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimId, 'actualQty' => 3.0]], // ships only 3 of the 5 claimed
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt10-depart')));
    expect($depart['status'] === 200, 'departure failed: ' . json_encode($depart['json']));

    $route = $httpA->request('GET', "/api/dispatch/route?tanggal={$tanggal}");
    $routeStop = current(array_filter($route['json']['data']['stops'], fn ($s) => $s['storeId'] === $storeA));
    expect(abs($routeStop['totalQty'] - 3.0) < 0.001, 'expected route to show the actual shipped 3, not the claimed 5, got ' . json_encode($routeStop));

    $detail = $httpA->request('GET', '/api/dispatch/shipments/' . $depart['json']['data']['shipments'][0]['shipmentId']);
    expect(abs($detail['json']['data']['summary']['totalQty'] - 3.0) < 0.001, 'expected shipment detail to also show 3, not 5, got ' . json_encode($detail['json']['data']['summary']));
});

runTest('DPT-11/DPT-12/DPT-13 history + shipment detail expose the real Shipment ID/DO/Driver/Group and product totals matching shipment_item', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-08-11';
    $p = nextProduct();
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 4.0, 4.0, 4.0);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);
    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 4.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt11-claim')));
    $claimId = $claim['json']['data']['claims'][0]['claimId'];
    $stop = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $depart = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stop['json']['data']['doVersion'], 'shipmentGroup' => 'PASTRY',
        'items' => [['claimId' => $claimId, 'actualQty' => 4.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt11-depart')));
    $shipmentId = $depart['json']['data']['shipments'][0]['shipmentId'];

    // DPT-11: history row carries the shipment_id (the href a clickable
    // card needs) plus the aggregated product_count/total_qty.
    $history = $httpA->request('GET', '/api/dispatch/history');
    expect($history['status'] === 200, 'history failed: ' . json_encode($history['json']));
    $row = current(array_filter($history['json']['data'], fn ($r) => (int) $r['shipment_id'] === $shipmentId));
    expect($row !== false, 'expected the new shipment to appear in history');
    expect((int) $row['product_count'] === 1 && abs((float) $row['total_qty'] - 4.0) < 0.001, 'expected history row to carry product_count=1/total_qty=4, got ' . json_encode($row));

    // DPT-12/13: shipment detail carries the real identity + matching item totals.
    $detail = $httpA->request('GET', '/api/dispatch/shipments/' . $shipmentId);
    expect($detail['status'] === 200, 'shipment detail failed: ' . json_encode($detail['json']));
    $d = $detail['json']['data'];
    expect($d['shipmentId'] === $shipmentId, 'expected the real shipmentId echoed back');
    expect($d['docNo'] === $do['docNo'], 'expected the real DO doc_no');
    expect($d['driverName'] === 'p55_driver_a', 'expected the real driver name/username, got ' . json_encode($d['driverName']));
    expect($d['shipmentGroup'] === 'PASTRY', 'expected the real shipment group PASTRY');
    expect(count($d['items']) === 1 && abs($d['items'][0]['qty'] - 4.0) < 0.001, 'expected item qty to match shipment_item exactly, got ' . json_encode($d['items']));
    expect(abs($d['summary']['totalQty'] - 4.0) < 0.001 && $d['summary']['productCount'] === 1, 'expected summary totals to match');

    $GLOBALS['dpt_shipment_id'] = $shipmentId;
    $GLOBALS['dpt_do_id'] = $do['doId'];
});

runTest('DPT-14 receipt pending is shown correctly (no receipt yet)', function () use ($httpA) {
    $shipmentId = $GLOBALS['dpt_shipment_id'] ?? null;
    expect($shipmentId !== null, 'DPT-14 depends on DPT-11/12/13 having run first');
    $detail = $httpA->request('GET', '/api/dispatch/shipments/' . $shipmentId);
    expect($detail['status'] === 200, 'detail failed: ' . json_encode($detail['json']));
    expect($detail['json']['data']['receipt'] === null, 'expected receipt=null before any store confirmation');
});

runTest('DPT-15/DPT-16 receipt completed + discrepancy quantities shown correctly', function () use ($httpA, $pdo, $baseUrl) {
    $shipmentId = $GLOBALS['dpt_shipment_id'] ?? null;
    $doId = $GLOBALS['dpt_do_id'] ?? null;
    expect($shipmentId !== null && $doId !== null, 'depends on DPT-11/12/13 having run first');

    $service = new \Amor\Api\Dispatch\ReceiptService($pdo);
    $token = $service->getReceiptToken($doId);
    $anon = new Http55($baseUrl);
    $view = $anon->request('GET', "/api/receive/{$token}");
    $shipmentItemId = $view['json']['data']['shipments'][0]['items'][0]['shipmentItemId'];

    // Confirm with a discrepancy: shipped 4, good 3, reject 1 — real-UAT
    // photo evidence rule applies, so attach a real (valid) evidence photo.
    $confirm = $anon->requestMultipart('POST', "/api/receive/{$token}/shipments/{$shipmentId}/confirm", [
        'receiverName' => 'Budi', 'items' => json_encode([['shipmentItemId' => $shipmentItemId, 'receivedGood' => 3, 'reject' => 1, 'shortage' => 0]]),
    ], ['evidence[]' => fakeEvidenceImage()], idemKey('dpt15-confirm'));
    expect($confirm['status'] === 200, 'confirm failed: ' . json_encode($confirm['json']));

    $detail = $httpA->request('GET', '/api/dispatch/shipments/' . $shipmentId);
    $receipt = $detail['json']['data']['receipt'];
    expect($receipt !== null, 'expected a receipt to now be present (DPT-15)');
    expect($receipt['status'] === 'confirmed_discrepancy', 'expected confirmed_discrepancy status');
    expect($receipt['receiverName'] === 'Budi', 'expected receiver name Budi');
    $item = $receipt['items'][0];
    expect(abs($item['shippedQty'] - 4.0) < 0.001 && abs($item['receivedGoodQty'] - 3.0) < 0.001 && abs($item['rejectQty'] - 1.0) < 0.001 && abs($item['shortageQty'] - 0.0) < 0.001,
        'DPT-16: expected discrepancy quantities shipped=4/good=3/reject=1/shortage=0, got ' . json_encode($item));
});

runTest('DPT-17 Driver A cannot open Driver B\'s shipment detail', function () use ($httpB) {
    $shipmentId = $GLOBALS['dpt_shipment_id'] ?? null;
    expect($shipmentId !== null, 'depends on DPT-11/12/13 having run first (Driver A\'s own shipment)');
    $asB = $httpB->request('GET', '/api/dispatch/shipments/' . $shipmentId);
    expect($asB['status'] === 403, "expected 403 FORBIDDEN when Driver B opens Driver A's shipment, got {$asB['status']}: " . json_encode($asB['json']));
    expect($asB['json']['code'] === 'FORBIDDEN', 'expected FORBIDDEN code');
    // Must not leak store/item/receipt data in the error response either.
    expect(!isset($asB['json']['data']), 'expected no data payload leaked in a 403 response');
});

runTest('DPT-18 Asia/Jakarta display formatting is UTC+7 with no DST, matching both the PHP and JS helpers\' algorithm', function () {
    // Both ui_fmt_datetime_id() (api/app/ui/bootstrap.php) and driver.js's
    // fmtDateTimeId() convert a stored UTC timestamp to Asia/Jakarta before
    // formatting "j M Y \xC2\xB7 H:i" — this proves the underlying timezone
    // math they both rely on (Indonesia has no daylight saving) is correct,
    // without re-requiring the whole page-bootstrap file into this API-only
    // test process.
    $dt = new \DateTime('2026-09-05 03:20:00', new \DateTimeZone('UTC'));
    $dt->setTimezone(new \DateTimeZone('Asia/Jakarta'));
    expect($dt->format('Y-m-d H:i') === '2026-09-05 10:20', 'expected UTC 03:20 -> Asia/Jakarta 10:20 (UTC+7), got ' . $dt->format('Y-m-d H:i'));

    $dtNewYear = new \DateTime('2026-01-01 20:00:00', new \DateTimeZone('UTC'));
    $dtNewYear->setTimezone(new \DateTimeZone('Asia/Jakarta'));
    expect($dtNewYear->format('Y-m-d H:i') === '2026-01-02 03:00', 'expected the +7 offset to hold across a date boundary too, got ' . $dtNewYear->format('Y-m-d H:i'));
});

runTest('DPT-19 history stays correct after multiple shipments under the same DO', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $secondDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-08-19';
    $p1 = nextProduct();
    $p2 = nextProductFromSecondDivision();
    // Two SEPARATE products (two DIFFERENT divisions, same reason as
    // DPT-08/09 above) with two SEPARATE departures (MAIN then PASTRY)
    // under the SAME delivery_order — two distinct shipment rows, one DO.
    stockUpMultiForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $tanggal, $storeA,
        [[$rotiBollenDivId, $p1['product_id'], 3.0, 3.0], [$secondDivId, $p2['product_id'], 6.0, 6.0]],
        [$p1['product_id'] => 3.0, $p2['product_id'] => 6.0]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $item1 = doItemIdFor($pdo, $do['doId'], $p1['product_id']);
    $item2 = doItemIdFor($pdo, $do['doId'], $p2['product_id']);

    $claim1 = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $item1, 'qty' => 3.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt19-c1')));
    $claimId1 = $claim1['json']['data']['claims'][0]['claimId'];
    $stop1 = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $depart1 = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stop1['json']['data']['doVersion'], 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimId1, 'actualQty' => 3.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt19-depart1')));
    expect($depart1['status'] === 200, 'first departure failed: ' . json_encode($depart1['json']));
    $shipment1 = $depart1['json']['data']['shipments'][0]['shipmentId'];

    $claim2 = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $item2, 'qty' => 6.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt19-c2')));
    $claimId2 = $claim2['json']['data']['claims'][0]['claimId'];
    $stop2 = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $depart2 = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stop2['json']['data']['doVersion'], 'shipmentGroup' => 'PASTRY',
        'items' => [['claimId' => $claimId2, 'actualQty' => 6.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('dpt19-depart2')));
    expect($depart2['status'] === 200, 'second departure failed: ' . json_encode($depart2['json']));
    $shipment2 = $depart2['json']['data']['shipments'][0]['shipmentId'];
    expect($shipment1 !== $shipment2, 'sanity: expected two distinct shipment rows');

    $history = $httpA->request('GET', '/api/dispatch/history');
    $row1 = current(array_filter($history['json']['data'], fn ($r) => (int) $r['shipment_id'] === $shipment1));
    $row2 = current(array_filter($history['json']['data'], fn ($r) => (int) $r['shipment_id'] === $shipment2));
    expect($row1 !== false && $row2 !== false, 'expected BOTH shipments to appear as separate history rows under the same DO');
    expect(abs((float) $row1['total_qty'] - 3.0) < 0.001 && abs((float) $row2['total_qty'] - 6.0) < 0.001,
        'expected each shipment\'s own total to stay correct/unmerged: 3 and 6, got ' . json_encode([$row1['total_qty'], $row2['total_qty']]));

    // The Rute Saya summary for this store must reflect BOTH shipments combined (9 pcs, 2 produk).
    $route = $httpA->request('GET', "/api/dispatch/route?tanggal={$tanggal}");
    $routeStop = current(array_filter($route['json']['data']['stops'], fn ($s) => $s['storeId'] === $storeA));
    expect($routeStop['productCount'] === 2 && abs($routeStop['totalQty'] - 9.0) < 0.001,
        'expected the route summary to aggregate BOTH shipments (2 produk / 9 pcs), got ' . json_encode($routeStop));
});

// ---------------------------------------------------------------------
// DR-NAV01..04 / DR-HIST01..03 / DR-LOG01..04 — Driver Route Navigation /
// History / Logout real-UAT hotfix. Real-UAT report: a departed "Rute
// Saya" stop opened the "Konfirmasi Berangkat" screen (which correctly
// says "Tidak ada klaim aktif Anda untuk toko ini." once every claim has
// resolved — see myRoute()'s docblock), Riwayat cards and the Logout
// button appeared correct in code review but were reported inert on real
// cPanel. Code audit found the click-navigation/logout JS already
// correct in this repo; DR-LOG01/03/DR-CACHE01 (served-asset checks) are
// covered by dist/validate-driver-navigation-logout-apache.sh instead,
// since they need a real HTTP server, not this php -S API-only harness.
// ---------------------------------------------------------------------

runTest('DR-NAV01 an active (not yet departed) route stop has shipmentIds=[] and departureStatus=belum_berangkat', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-08-20';
    $p1 = nextProduct();
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p1['product_id'], 5.0, 5.0, 5.0);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p1['product_id']);
    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 5.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('drnav01-claim')));
    expect($claim['status'] === 200, 'claim failed: ' . json_encode($claim['json']));

    $route = $httpA->request('GET', "/api/dispatch/route?tanggal={$tanggal}");
    $stop = current(array_filter($route['json']['data']['stops'], fn ($s) => $s['storeId'] === $storeA));
    expect($stop !== false, 'expected storeA on the route');
    expect($stop['departureStatus'] === 'belum_berangkat', 'expected belum_berangkat before departure, got ' . $stop['departureStatus']);
    expect($stop['shipmentIds'] === [], 'expected shipmentIds=[] before departure, got ' . json_encode($stop['shipmentIds']));
});

runTest('DR-NAV02 a departed route stop with exactly one shipment carries that real shipmentId', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-08-21';
    $p1 = nextProduct();
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p1['product_id'], 5.0, 5.0, 5.0);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $itemId = doItemIdFor($pdo, $do['doId'], $p1['product_id']);
    $claim = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => 5.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('drnav02-claim')));
    $claimId = $claim['json']['data']['claims'][0]['claimId'];
    $stopBefore = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $depart = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stopBefore['json']['data']['doVersion'], 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimId, 'actualQty' => 5.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('drnav02-depart')));
    expect($depart['status'] === 200, 'departure failed: ' . json_encode($depart['json']));
    $shipmentId = $depart['json']['data']['shipments'][0]['shipmentId'];
    $GLOBALS['dr_nav_shipment_id'] = $shipmentId;
    $GLOBALS['dr_nav_store'] = $storeA;
    $GLOBALS['dr_nav_tanggal'] = $tanggal;

    $route = $httpA->request('GET', "/api/dispatch/route?tanggal={$tanggal}");
    $stop = current(array_filter($route['json']['data']['stops'], fn ($s) => $s['storeId'] === $storeA));
    expect($stop['departureStatus'] === 'sudah_berangkat', 'expected sudah_berangkat after departure');
    expect($stop['shipmentIds'] === [$shipmentId], 'expected shipmentIds=[real id], got ' . json_encode($stop['shipmentIds']));
});

runTest('DR-NAV03 a departed stop\'s Konfirmasi Berangkat data has zero active items but exposes the real shipment for the friendly fallback', function () use ($httpA, $pdo) {
    $shipmentId = $GLOBALS['dr_nav_shipment_id'] ?? null;
    $storeId = $GLOBALS['dr_nav_store'] ?? null;
    $tanggal = $GLOBALS['dr_nav_tanggal'] ?? null;
    expect($shipmentId !== null, 'depends on DR-NAV02 having run first');

    $stop = $httpA->request('GET', "/api/dispatch/route/stops/{$storeId}?tanggal={$tanggal}");
    expect($stop['status'] === 200, 'stopDetail failed: ' . json_encode($stop['json']));
    expect($stop['json']['data']['items'] === [], 'expected zero active claim items for an already-departed stop (this is correct backend behavior, never a bug) — a normal route click must never reach this endpoint for a departed stop any more (see DR-NAV02)');
    $shipments = $stop['json']['data']['shipments'];
    expect(count($shipments) === 1 && $shipments[0]['shipmentId'] === $shipmentId,
        'expected stopDetail to expose the real departed shipment for the "Pengiriman ini sudah diberangkatkan" fallback, got ' . json_encode($shipments));
});

runTest('DR-NAV04 a route stop that split into MAIN + PASTRY exposes BOTH shipmentIds (chooser case, never picks one arbitrarily)', function () use ($httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $secondDivId, $storeA, $adminHttp, $adminCsrf) {
    $tanggal = '2026-08-22';
    $p1 = nextProduct();
    $p2 = nextProductFromSecondDivision();
    stockUpMultiForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $tanggal, $storeA,
        [[$rotiBollenDivId, $p1['product_id'], 3.0, 3.0], [$secondDivId, $p2['product_id'], 6.0, 6.0]],
        [$p1['product_id'] => 3.0, $p2['product_id'] => 6.0]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $item1 = doItemIdFor($pdo, $do['doId'], $p1['product_id']);
    $item2 = doItemIdFor($pdo, $do['doId'], $p2['product_id']);

    $claim1 = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $item1, 'qty' => 3.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('drnav04-c1')));
    $claimId1 = $claim1['json']['data']['claims'][0]['claimId'];
    $stop1 = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $depart1 = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stop1['json']['data']['doVersion'], 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimId1, 'actualQty' => 3.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('drnav04-depart1')));
    $shipment1 = $depart1['json']['data']['shipments'][0]['shipmentId'];

    $claim2 = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $item2, 'qty' => 6.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('drnav04-c2')));
    $claimId2 = $claim2['json']['data']['claims'][0]['claimId'];
    $stop2 = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $depart2 = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stop2['json']['data']['doVersion'], 'shipmentGroup' => 'PASTRY',
        'items' => [['claimId' => $claimId2, 'actualQty' => 6.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('drnav04-depart2')));
    $shipment2 = $depart2['json']['data']['shipments'][0]['shipmentId'];
    expect($shipment1 !== $shipment2, 'sanity: expected two distinct shipments');

    $route = $httpA->request('GET', "/api/dispatch/route?tanggal={$tanggal}");
    $stop = current(array_filter($route['json']['data']['stops'], fn ($s) => $s['storeId'] === $storeA));
    sort($stop['shipmentIds']);
    $expected = [$shipment1, $shipment2];
    sort($expected);
    expect($stop['shipmentIds'] === $expected, 'expected myRoute to expose BOTH shipment ids for the chooser, got ' . json_encode($stop['shipmentIds']));

    $chooser = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}/shipments?tanggal={$tanggal}");
    expect($chooser['status'] === 200, 'stopShipments failed: ' . json_encode($chooser['json']));
    $chooserIds = array_map(fn ($s) => $s['shipmentId'], $chooser['json']['data']['shipments']);
    sort($chooserIds);
    expect($chooserIds === $expected, 'expected the chooser endpoint to list both real shipments (never arbitrarily pick one), got ' . json_encode($chooserIds));
    $groups = array_column($chooser['json']['data']['shipments'], 'shipmentGroup');
    sort($groups);
    expect($groups === ['MAIN', 'PASTRY'], 'expected the chooser to distinguish MAIN vs PASTRY, got ' . json_encode($groups));
});

runTest('DR-HIST01 a Riwayat history row carries the real backend shipment_id used as the click target', function () use ($httpA) {
    $shipmentId = $GLOBALS['dr_nav_shipment_id'] ?? null;
    expect($shipmentId !== null, 'depends on DR-NAV02 having run first');
    $history = $httpA->request('GET', '/api/dispatch/history');
    $row = current(array_filter($history['json']['data'], fn ($r) => (int) $r['shipment_id'] === $shipmentId));
    expect($row !== false, 'expected the DR-NAV02 shipment to appear in history with its real shipment_id');
});

runTest('DR-HIST02 clicking a history row (its real shipment_id) opens the matching shipment detail', function () use ($httpA) {
    $shipmentId = $GLOBALS['dr_nav_shipment_id'] ?? null;
    expect($shipmentId !== null, 'depends on DR-NAV02 having run first');
    $detail = $httpA->request('GET', '/api/dispatch/shipments/' . $shipmentId);
    expect($detail['status'] === 200, 'shipmentDetail failed: ' . json_encode($detail['json']));
    expect($detail['json']['data']['shipmentId'] === $shipmentId, 'expected the detail to be for the exact shipment the history row pointed at');
});

runTest('DR-HIST03 Driver A cannot open Driver B\'s shipment via the history-derived link', function () use ($httpB) {
    $shipmentId = $GLOBALS['dr_nav_shipment_id'] ?? null;
    expect($shipmentId !== null, 'depends on DR-NAV02 having run first (Driver A\'s own shipment)');
    $asB = $httpB->request('GET', '/api/dispatch/shipments/' . $shipmentId);
    expect($asB['status'] === 403, "expected 403 when Driver B opens Driver A's shipment via the same link driver.js would use, got {$asB['status']}");
});

runTest('DR-LOG02 logging out destroys the current driver session (a subsequent authenticated call is rejected)', function () use ($pdo, $baseUrl) {
    $driverCId = createUser($pdo, 'p55_driver_logout', 'DriverLogoutPass123', ['DRIVER']);
    $httpC = new Http55($baseUrl);
    $csrfC = login($httpC, 'p55_driver_logout', 'DriverLogoutPass123');

    $before = $httpC->request('GET', '/api/dispatch/history');
    expect($before['status'] === 200, 'expected the fresh driver session to work before logout');

    $logout = $httpC->request('POST', '/api/auth/logout', null, ['X-CSRF-Token' => $csrfC]);
    expect($logout['status'] === 204, 'expected 204 No Content from logout, got ' . $logout['status']);

    $after = $httpC->request('GET', '/api/dispatch/history');
    expect($after['status'] === 401, 'expected 401 UNAUTHENTICATED for the SAME session cookie after logout, got ' . $after['status']);
});

runTest('DR-LOG04 logging out does not alter any dispatch/shipment record', function () use ($pdo, $baseUrl) {
    $shipmentsBefore = (int) $pdo->query('SELECT COUNT(*) FROM shipment')->fetchColumn();
    $claimsBefore = (int) $pdo->query('SELECT COUNT(*) FROM dispatch_claim')->fetchColumn();

    $httpD = new Http55($baseUrl);
    $csrfD = login($httpD, 'p55_driver_logout', 'DriverLogoutPass123');
    $httpD->request('POST', '/api/auth/logout', null, ['X-CSRF-Token' => $csrfD]);

    $shipmentsAfter = (int) $pdo->query('SELECT COUNT(*) FROM shipment')->fetchColumn();
    $claimsAfter = (int) $pdo->query('SELECT COUNT(*) FROM dispatch_claim')->fetchColumn();
    expect($shipmentsBefore === $shipmentsAfter, 'expected logout to leave the shipment table untouched');
    expect($claimsBefore === $claimsAfter, 'expected logout to leave the dispatch_claim table untouched');
});

// ---------------------------------------------------------------------
// SJ-02..16 — Draft DO vs Actual Shipment Surat Jalan separation.
// Real-UAT report: the existing DO print showed ALL 1,034 planned pcs
// for Bakery Abdul Gani, when the actual proof-of-goods for one Driver's
// shipment was only 7 pcs (SHP-2, MAIN) or 4 pcs (SHP-3, PASTRY). This
// scenario mirrors that exact shape: one DO, two products in two
// divisions (so ONE fg_batch create/patch/submit call per SESSION-
// HANDOFF.md §7.9), Driver A claims+departs product 1 (MAIN), Driver B
// claims+departs product 2 (PASTRY) — two real, distinct shipments under
// the same DO. SJ-01 (Draft DO still prints everything, unambiguous
// title) lives in PhasePrintTest.php instead, alongside its own existing
// Draft-DO-print fixtures. SJ-17 (full regression green) is the
// orchestrator's own final step, not a test in this file (same
// convention as P55-24/DPT-19 above).
// ---------------------------------------------------------------------

runTest('SJ-02..16 Surat Jalan (actual shipment) print shows ONLY that shipment\'s real items, driver, group, DO reference, and DO-level QR', function () use ($httpA, $csrfA, $httpB, $csrfB, $pdo, $karangtengahId, $rotiBollenDivId, $secondDivId, $storeA, $adminHttp, $adminCsrf, $baseUrl) {
    $tanggal = '2026-08-23';
    $pMain = nextProduct();
    $pPastry = nextProductFromSecondDivision();
    stockUpMultiForDelivery($adminHttp, $adminCsrf, $pdo, $karangtengahId, $tanggal, $storeA,
        [[$rotiBollenDivId, $pMain['product_id'], 5.0, 5.0], [$secondDivId, $pPastry['product_id'], 4.0, 4.0]],
        [$pMain['product_id'] => 5.0, $pPastry['product_id'] => 4.0]);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeA);
    $docNo = $do['docNo'];
    $itemMain = doItemIdFor($pdo, $do['doId'], $pMain['product_id']);
    $itemPastry = doItemIdFor($pdo, $do['doId'], $pPastry['product_id']);

    // Driver A claims + departs the MAIN product (SHP-2 equivalent).
    $claimA = $httpA->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemMain, 'qty' => 5.0]]], array_merge(['X-CSRF-Token' => $csrfA], idemKey('sj-claimA')));
    $claimIdA = $claimA['json']['data']['claims'][0]['claimId'];
    $stopA = $httpA->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $departA = $httpA->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stopA['json']['data']['doVersion'], 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimIdA, 'actualQty' => 5.0]],
    ], array_merge(['X-CSRF-Token' => $csrfA], idemKey('sj-departA')));
    expect($departA['status'] === 200, 'Driver A departure failed: ' . json_encode($departA['json']));
    $shpMain = $departA['json']['data']['shipments'][0]['shipmentId'];

    // Driver B claims + departs the PASTRY product (SHP-3 equivalent).
    $claimB = $httpB->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemPastry, 'qty' => 4.0]]], array_merge(['X-CSRF-Token' => $csrfB], idemKey('sj-claimB')));
    $claimIdB = $claimB['json']['data']['claims'][0]['claimId'];
    $stopB = $httpB->request('GET', "/api/dispatch/route/stops/{$storeA}?tanggal={$tanggal}");
    $departB = $httpB->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stopB['json']['data']['doVersion'], 'shipmentGroup' => 'PASTRY',
        'items' => [['claimId' => $claimIdB, 'actualQty' => 4.0]],
    ], array_merge(['X-CSRF-Token' => $csrfB], idemKey('sj-departB')));
    expect($departB['status'] === 200, 'Driver B departure failed: ' . json_encode($departB['json']));
    $shpPastry = $departB['json']['data']['shipments'][0]['shipmentId'];
    expect($shpMain !== $shpPastry, 'sanity: expected two distinct shipments');

    $stockLedgerBefore = shipmentOutRows($pdo);
    $doVersionBefore = (string) $pdo->query("SELECT CONCAT(version, ':', status) FROM delivery_order WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    $shipmentMainRowBefore = $pdo->query("SELECT version FROM shipment WHERE shipment_id = {$shpMain}")->fetchColumn();
    $shipmentPastryRowBefore = $pdo->query("SELECT version FROM shipment WHERE shipment_id = {$shpPastry}")->fetchColumn();

    // --- SJ-02/03/05/06/07/08/09: Driver A prints SHP-main (their own) ---
    $printMain = $httpA->request('GET', "/_driver-uat/print-shipment.php?id={$shpMain}");
    expect($printMain['status'] === 200, "print SHP-main failed: {$printMain['status']}");
    expect(str_contains($printMain['body'], 'SURAT JALAN'), 'SJ-02: expected the SURAT JALAN document title');
    expect(str_contains($printMain['body'], (string) $pMain['name']), 'SJ-02: expected the MAIN product to print');
    expect(!str_contains($printMain['body'], (string) $pPastry['name']), 'SJ-03: expected the PASTRY-only product to NOT print on the MAIN shipment\'s Surat Jalan');
    $mainRowPattern = '#<td>' . preg_quote((string) $pMain['name'], '#') . '</td>\s*<td>[^<]*</td>\s*<td class="num">5</td>#';
    expect((bool) preg_match($mainRowPattern, $printMain['body']),
        'SJ-05: expected the printed row for the shipped product to show qty=5 (the real shipment_item qty), not a planned/claimed figure');
    expect((bool) preg_match('/Total 1 Produk[\s\S]{0,40}<td class="num">5 Pcs<\/td>/', $printMain['body']),
        'SJ-05: expected the summary footer to show Total 1 Produk / 5 Pcs');
    expect(str_contains($printMain['body'], 'MAIN'), 'SJ-07: expected the real shipment group (MAIN) to print');
    expect(str_contains($printMain['body'], $docNo), 'SJ-08: expected the printed document to reference the original DO number');
    expect(str_contains($printMain['body'], 'print-receipt-qr-code') && str_contains($printMain['body'], '<svg'),
        'SJ-09: expected the existing DO receipt QR to be embedded');

    // --- SJ-04: Driver B prints SHP-pastry (their own), exactly its 2-side items ---
    $printPastry = $httpB->request('GET', "/_driver-uat/print-shipment.php?id={$shpPastry}");
    expect($printPastry['status'] === 200, "print SHP-pastry failed: {$printPastry['status']}");
    expect(str_contains($printPastry['body'], (string) $pPastry['name']), 'SJ-04: expected the PASTRY product to print');
    expect(!str_contains($printPastry['body'], (string) $pMain['name']), 'SJ-04: expected the MAIN-only product to NOT print on the PASTRY shipment\'s Surat Jalan');
    expect(str_contains($printPastry['body'], 'PASTRY'), 'SJ-07: expected the real shipment group (PASTRY) to print on the other shipment');

    // --- SJ-06: real driver name (not a placeholder) ---
    $driverAFullName = (string) $pdo->query("SELECT full_name FROM users WHERE username = 'p55_driver_a'")->fetchColumn();
    $driverBFullName = (string) $pdo->query("SELECT full_name FROM users WHERE username = 'p55_driver_b'")->fetchColumn();
    $expectedA = $driverAFullName !== '' ? $driverAFullName : 'p55_driver_a';
    $expectedB = $driverBFullName !== '' ? $driverBFullName : 'p55_driver_b';
    expect(str_contains($printMain['body'], $expectedA), 'SJ-06: expected the real Driver A name on the MAIN shipment print');
    expect(str_contains($printPastry['body'], $expectedB), 'SJ-06: expected the real Driver B name on the PASTRY shipment print');

    // --- SJ-10: SHP-main and SHP-pastry print the SAME DO-level QR (same token) ---
    preg_match('#<div class="print-receipt-qr-code[^"]*">(.*?)</div>\s*<div class="print-receipt-qr-label"#s', $printMain['body'], $mMain);
    preg_match('#<div class="print-receipt-qr-code[^"]*">(.*?)</div>\s*<div class="print-receipt-qr-label"#s', $printPastry['body'], $mPastry);
    expect(isset($mMain[1], $mPastry[1]) && $mMain[1] !== '', 'SJ-10: expected to find a QR SVG block on both prints');
    expect($mMain[1] === $mPastry[1], 'SJ-10: expected the SAME DO-level QR SVG on both shipments\' Surat Jalan (1 DO = 1 receipt token, never a new one per shipment)');

    // --- SJ-11/12/13: printing is a pure read ---
    expect(shipmentOutRows($pdo) === $stockLedgerBefore, 'SJ-11: expected printing to create ZERO new stock_ledger rows');
    $doVersionAfter = (string) $pdo->query("SELECT CONCAT(version, ':', status) FROM delivery_order WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    expect($doVersionAfter === $doVersionBefore, 'SJ-12: expected DO version/status to be unchanged by printing');
    expect($pdo->query("SELECT version FROM shipment WHERE shipment_id = {$shpMain}")->fetchColumn() === $shipmentMainRowBefore, 'SJ-13: expected the MAIN shipment row to be unchanged by printing');
    expect($pdo->query("SELECT version FROM shipment WHERE shipment_id = {$shpPastry}")->fetchColumn() === $shipmentPastryRowBefore, 'SJ-13: expected the PASTRY shipment row to be unchanged by printing');

    // --- SJ-14: Driver A cannot print Driver B's shipment (and vice versa) ---
    $forbiddenA = $httpA->request('GET', "/_driver-uat/print-shipment.php?id={$shpPastry}");
    expect($forbiddenA['status'] === 403, "SJ-14: expected 403 when Driver A prints Driver B's shipment, got {$forbiddenA['status']}");
    $forbiddenB = $httpB->request('GET', "/_driver-uat/print-shipment.php?id={$shpMain}");
    expect($forbiddenB['status'] === 403, "SJ-14: expected 403 when Driver B prints Driver A's shipment, got {$forbiddenB['status']}");

    // --- SJ-15/16: the SAME token the printed QR encodes (get-or-created
    // lazily by ui_do_receipt_qr_svg() on first print, exactly like the
    // Draft DO print page already did) still resolves the correct public
    // receipt portal, listing BOTH shipments. The QR itself is a grid of
    // <rect> modules, not readable text, so this reads the token the
    // print run just created the same way the app does — directly from
    // delivery_receipt_token — rather than trying to OCR/decode the SVG.
    $token = (string) $pdo->query("SELECT token FROM delivery_receipt_token WHERE delivery_order_id = {$do['doId']}")->fetchColumn();
    expect(strlen($token) === 64, 'SJ-15: expected a real 64-char receipt token to have been get-or-created by printing');
    $anon = new Http55($baseUrl);
    $publicView = $anon->request('GET', "/api/receive/{$token}");
    expect($publicView['status'] === 200, 'SJ-15: expected the QR token to resolve a valid public receipt view: ' . json_encode($publicView['json']));
    expect($publicView['json']['data']['docNo'] === $docNo, 'SJ-15: expected the public view to reference the correct DO');
    $shipmentIdsInView = array_map(fn ($s) => $s['shipmentId'], $publicView['json']['data']['shipments']);
    expect(in_array($shpMain, $shipmentIdsInView, true) && in_array($shpPastry, $shipmentIdsInView, true),
        'SJ-16: expected the public Store Receipt portal to list BOTH SHP-main and SHP-pastry under this DO, never merged');
});

// ---------------------------------------------------------------------
// PHOTO-01..10 / RCPT-ADM01..15 / MOBILE-01..02 — Store Receipt Photo
// Evidence + Admin Konfirmasi Toko Detail + Verifikasi Selisih. Real-UAT
// ask: a discrepancy (Reject/Kurang > 0) confirmation requires at least
// one photo before Admin can verify it; the Admin summary list needed a
// working Detail page + Verifikasi action; the Store Receipt table
// clipped the "Kurang" column on a narrow phone viewport.
// ---------------------------------------------------------------------

/**
 * One fresh DO -> claim -> depart -> single-item shipment, ready for a
 * Store Receipt confirm attempt. Returns everything a PHOTO or RCPT-ADM
 * test needs. $qty is both the planned/actual/shipped quantity (kept
 * simple — these tests are about the RECEIPT/evidence layer, not
 * multi-product shipments, already covered by SJ-02..16/DPT-19).
 */
function setupSingleItemShipment(Http55 $adminHttp, string $adminCsrf, Http55 $driverHttp, string $driverCsrf, PDO $pdo, int $factoryId, int $divisionId, int $storeId, string $tanggal, float $qty): array
{
    $p = nextProduct();
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $factoryId, $divisionId, $tanggal, $storeId, $p['product_id'], $qty, $qty, $qty);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeId);
    $itemId = doItemIdFor($pdo, $do['doId'], $p['product_id']);
    $claim = $driverHttp->request('POST', '/api/dispatch/claim', ['lines' => [['doItemId' => $itemId, 'qty' => $qty]]], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('sish-claim-' . $tanggal)));
    expect($claim['status'] === 200, 'setup claim failed: ' . json_encode($claim['json']));
    $claimId = $claim['json']['data']['claims'][0]['claimId'];
    $stop = $driverHttp->request('GET', "/api/dispatch/route/stops/{$storeId}?tanggal={$tanggal}");
    $depart = $driverHttp->request('POST', '/api/dispatch/departures', [
        'doId' => $do['doId'], 'expectedVersion' => $stop['json']['data']['doVersion'], 'shipmentGroup' => 'MAIN',
        'items' => [['claimId' => $claimId, 'actualQty' => $qty]],
    ], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('sish-depart-' . $tanggal)));
    expect($depart['status'] === 200, 'setup departure failed: ' . json_encode($depart['json']));
    $shipmentId = $depart['json']['data']['shipments'][0]['shipmentId'];

    $service = new \Amor\Api\Dispatch\ReceiptService($pdo);
    $token = $service->getReceiptToken($do['doId']);
    return ['doId' => $do['doId'], 'docNo' => $do['docNo'], 'shipmentId' => $shipmentId, 'token' => $token, 'qty' => $qty, 'productName' => $p['name']];
}

/** shipmentItemId (the RECEIPT-side one, from the public view) is resolved separately since it's not the same id as the DO item id. */
function shipmentItemIdFromToken(Http55 $anon, string $token, int $shipmentId): int
{
    $view = $anon->request('GET', "/api/receive/{$token}");
    foreach ($view['json']['data']['shipments'] as $sh) {
        if ($sh['shipmentId'] === $shipmentId) {
            return (int) $sh['items'][0]['shipmentItemId'];
        }
    }
    throw new RuntimeException('shipment not found in public view');
}

runTest('STORE-EVID-01 a clean receipt (no reject/shortage) can be submitted WITHOUT any photo', function () use ($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $baseUrl) {
    $fx = setupSingleItemShipment($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-08-24', 5.0);
    $anon = new Http55($baseUrl);
    $sii = shipmentItemIdFromToken($anon, $fx['token'], $fx['shipmentId']);
    $r = $anon->request('POST', "/api/receive/{$fx['token']}/shipments/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Toko A', 'items' => [['shipmentItemId' => $sii, 'receivedGood' => 5.0, 'reject' => 0, 'shortage' => 0]],
    ], idemKey('photo01'));
    expect($r['status'] === 200, 'STORE-EVID-01: expected a clean receipt (no discrepancy) to succeed without a photo: ' . json_encode($r['json']));
    expect($r['json']['data']['status'] === 'confirmed_ok', 'expected confirmed_ok status');
});

runTest('STORE-EVID-02 Store Reject > 0 WITHOUT any photo is rejected', function () use ($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $baseUrl) {
    $fx = setupSingleItemShipment($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-08-25', 4.0);
    $anon = new Http55($baseUrl);
    $sii = shipmentItemIdFromToken($anon, $fx['token'], $fx['shipmentId']);
    $r = $anon->request('POST', "/api/receive/{$fx['token']}/shipments/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Toko A', 'items' => [['shipmentItemId' => $sii, 'receivedGood' => 3.0, 'reject' => 1.0, 'shortage' => 0]],
    ], idemKey('photo02'));
    expect($r['status'] === 400, "STORE-EVID-02: expected 400 EVIDENCE_REQUIRED, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['code'] === 'EVIDENCE_REQUIRED', 'expected EVIDENCE_REQUIRED code');
    $count = (int) $pdo->query("SELECT COUNT(*) FROM shipment_receipt WHERE shipment_id = {$fx['shipmentId']}")->fetchColumn();
    expect($count === 0, 'a blocked submission must not create a receipt row');
});

runTest('STORE-EVID-03 Store Kurang > 0 WITHOUT any photo is rejected', function () use ($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $baseUrl) {
    $fx = setupSingleItemShipment($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-08-26', 4.0);
    $anon = new Http55($baseUrl);
    $sii = shipmentItemIdFromToken($anon, $fx['token'], $fx['shipmentId']);
    $r = $anon->request('POST', "/api/receive/{$fx['token']}/shipments/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Toko A', 'items' => [['shipmentItemId' => $sii, 'receivedGood' => 3.0, 'reject' => 0, 'shortage' => 1.0]],
    ], idemKey('photo03'));
    expect($r['status'] === 400, "STORE-EVID-03: expected 400 EVIDENCE_REQUIRED, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['code'] === 'EVIDENCE_REQUIRED', 'expected EVIDENCE_REQUIRED code');
});

runTest('STORE-EVID-04 Store discrepancy WITH a valid photo succeeds', function () use ($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $baseUrl) {
    $fx = setupSingleItemShipment($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-08-27', 4.0);
    $anon = new Http55($baseUrl);
    $sii = shipmentItemIdFromToken($anon, $fx['token'], $fx['shipmentId']);
    $r = $anon->requestMultipart('POST', "/api/receive/{$fx['token']}/shipments/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Toko A', 'items' => json_encode([['shipmentItemId' => $sii, 'receivedGood' => 3.0, 'reject' => 1.0, 'shortage' => 0]]),
    ], ['evidence[]' => fakeEvidenceImage()], idemKey('photo04'));
    expect($r['status'] === 200, 'STORE-EVID-04: expected success with a valid photo: ' . json_encode($r['json']));
    expect($r['json']['data']['status'] === 'confirmed_discrepancy', 'expected confirmed_discrepancy');
    $GLOBALS['photo_receipt_id'] = $r['json']['data']['receiptId'];
    $GLOBALS['photo_shipment_id'] = $fx['shipmentId'];
    $GLOBALS['photo_do_id'] = $fx['doId'];
});

runTest('PHOTO-05 an invalid (non-image) file is rejected', function () use ($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $baseUrl) {
    $fx = setupSingleItemShipment($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-08-28', 4.0);
    $anon = new Http55($baseUrl);
    $sii = shipmentItemIdFromToken($anon, $fx['token'], $fx['shipmentId']);
    $r = $anon->requestMultipart('POST', "/api/receive/{$fx['token']}/shipments/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Toko A', 'items' => json_encode([['shipmentItemId' => $sii, 'receivedGood' => 3.0, 'reject' => 1.0, 'shortage' => 0]]),
    ], ['evidence[]' => fakeInvalidEvidenceFile('txt')], idemKey('photo05'));
    expect($r['status'] === 400, "PHOTO-05: expected 400 for a non-image file, got {$r['status']}: " . json_encode($r['json']));
    expect(in_array($r['json']['code'], ['EVIDENCE_INVALID_MIME', 'EVIDENCE_INVALID_FILE'], true), 'expected an evidence-validation error code, got ' . $r['json']['code']);
    $count = (int) $pdo->query("SELECT COUNT(*) FROM shipment_receipt WHERE shipment_id = {$fx['shipmentId']}")->fetchColumn();
    expect($count === 0, 'a rejected upload must not create a receipt row either');
});

runTest('PHOTO-06 an oversized file (> 5 MB) is rejected', function () use ($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $baseUrl) {
    $fx = setupSingleItemShipment($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-08-29', 4.0);
    $anon = new Http55($baseUrl);
    $sii = shipmentItemIdFromToken($anon, $fx['token'], $fx['shipmentId']);
    $oversized = fakeInvalidEvidenceFile('jpg', 6 * 1024 * 1024);
    $r = $anon->requestMultipart('POST', "/api/receive/{$fx['token']}/shipments/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Toko A', 'items' => json_encode([['shipmentItemId' => $sii, 'receivedGood' => 3.0, 'reject' => 1.0, 'shortage' => 0]]),
    ], ['evidence[]' => $oversized], idemKey('photo06'));
    expect($r['status'] === 400, "PHOTO-06: expected 400 for an oversized file, got {$r['status']}: " . json_encode($r['json']));
    // Two valid rejection paths depending on the server's own php.ini
    // upload_max_filesize/post_max_size (outside this app's control,
    // varies per host): EVIDENCE_TOO_LARGE is EvidenceUploader's own
    // 5 MB check; EVIDENCE_UPLOAD_ERROR is PHP itself refusing the
    // upload earlier (UPLOAD_ERR_INI_SIZE) before that check even runs.
    // Either way the file must never be accepted.
    expect(in_array($r['json']['code'], ['EVIDENCE_TOO_LARGE', 'EVIDENCE_UPLOAD_ERROR'], true), 'expected an oversized-file rejection code, got ' . $r['json']['code']);
});

runTest('PHOTO-07 the stored evidence filename is server-generated/randomized, never the original filename', function () use ($pdo) {
    $receiptId = $GLOBALS['photo_receipt_id'] ?? null;
    expect($receiptId !== null, 'depends on STORE-EVID-04 having run first');
    $row = $pdo->query("SELECT file_path, original_name FROM shipment_receipt_evidence WHERE shipment_receipt_id = {$receiptId} LIMIT 1")->fetch();
    expect($row !== false, 'expected an evidence row for the STORE-EVID-04 receipt');
    expect((bool) preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $row['file_path']), "expected a random hex filename, got '{$row['file_path']}'");
    expect($row['file_path'] !== $row['original_name'], 'stored filename must never equal the client-supplied original filename');
});

runTest('PHOTO-08 the stored evidence file is saved with an image extension only, never .php or any executable extension', function () use ($pdo) {
    $receiptId = $GLOBALS['photo_receipt_id'] ?? null;
    expect($receiptId !== null, 'depends on STORE-EVID-04 having run first');
    $row = $pdo->query("SELECT file_path FROM shipment_receipt_evidence WHERE shipment_receipt_id = {$receiptId} LIMIT 1")->fetch();
    expect((bool) preg_match('/\.(jpg|png|webp)$/', $row['file_path']), 'expected an image extension only');
    expect(!preg_match('/\.(php\d?|phtml|phar|cgi|pl|sh)$/i', $row['file_path']), 'expected NO executable extension ever');
    // The real "cannot execute as a script" guarantee is the deny-all
    // .htaccess on api/uploads/receipt-evidence/ itself — proven under a
    // REAL Apache server by dist/validate-receipt-evidence-apache.sh
    // (php -S, used by this test harness, ignores .htaccess entirely).
});

runTest('PHOTO-09 a double-submit (same Idempotency-Key, same files) does not duplicate the receipt or its evidence', function () use ($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $baseUrl) {
    $fx = setupSingleItemShipment($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-08-30', 4.0);
    $anon = new Http55($baseUrl);
    $sii = shipmentItemIdFromToken($anon, $fx['token'], $fx['shipmentId']);
    $key = idemKey('photo09-samekey');
    $first = $anon->requestMultipart('POST', "/api/receive/{$fx['token']}/shipments/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Toko A', 'items' => json_encode([['shipmentItemId' => $sii, 'receivedGood' => 3.0, 'reject' => 1.0, 'shortage' => 0]]),
    ], ['evidence[]' => fakeEvidenceImage()], $key);
    expect($first['status'] === 200, 'first submit failed: ' . json_encode($first['json']));
    $second = $anon->requestMultipart('POST', "/api/receive/{$fx['token']}/shipments/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Toko A', 'items' => json_encode([['shipmentItemId' => $sii, 'receivedGood' => 3.0, 'reject' => 1.0, 'shortage' => 0]]),
    ], ['evidence[]' => fakeEvidenceImage()], $key);
    expect($second['status'] === 200, 'idempotent replay should not error: ' . json_encode($second['json']));
    expect($second['json']['data']['receiptId'] === $first['json']['data']['receiptId'], 'expected the exact same receipt on replay');
    $receiptCount = (int) $pdo->query("SELECT COUNT(*) FROM shipment_receipt WHERE shipment_id = {$fx['shipmentId']}")->fetchColumn();
    expect($receiptCount === 1, 'PHOTO-09: expected exactly one receipt row after a same-key double-submit');
    $evidenceCount = (int) $pdo->query("SELECT COUNT(*) FROM shipment_receipt_evidence WHERE shipment_receipt_id = {$first['json']['data']['receiptId']}")->fetchColumn();
    expect($evidenceCount === 1, 'PHOTO-09: expected exactly one evidence row, never duplicated by the replay');
});

runTest('PHOTO-10 more than 3 evidence files is rejected', function () use ($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $baseUrl) {
    $fx = setupSingleItemShipment($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-08-31', 4.0);
    $anon = new Http55($baseUrl);
    $sii = shipmentItemIdFromToken($anon, $fx['token'], $fx['shipmentId']);
    $ch = curl_init($baseUrl . "/api/receive/{$fx['token']}/shipments/{$fx['shipmentId']}/confirm");
    $jar = tempnam(sys_get_temp_dir(), 'p10jar');
    $fields = ['receiverName' => 'Toko A', 'items' => json_encode([['shipmentItemId' => $sii, 'receivedGood' => 3.0, 'reject' => 1.0, 'shortage' => 0]])];
    for ($i = 0; $i < 4; $i++) {
        $fields['evidence[' . $i . ']'] = new CURLFile(fakeEvidenceImage(), 'image/png', "ev{$i}.png");
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['Idempotency-Key: ' . idemKey('photo10')['Idempotency-Key']],
        CURLOPT_POSTFIELDS => $fields,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($raw, true);
    expect($status === 400, "PHOTO-10: expected 400 for 4 evidence files, got {$status}: {$raw}");
    expect($json['code'] === 'TOO_MANY_EVIDENCE_FILES', 'expected TOO_MANY_EVIDENCE_FILES code, got ' . ($json['code'] ?? 'null'));
});

// --- RCPT-ADM01..15 ---

runTest('RCPT-ADM01 admin summary (GET /api/admin/receipts) shows the STORE-EVID-04 row', function () use ($adminHttp, $adminCsrf) {
    $shipmentId = $GLOBALS['photo_shipment_id'] ?? null;
    expect($shipmentId !== null, 'depends on STORE-EVID-04 having run first');
    $list = $adminHttp->request('GET', '/api/admin/receipts');
    expect($list['status'] === 200, 'admin list failed');
    $found = current(array_filter($list['json']['data'], fn ($r) => $r['shipmentId'] === $shipmentId));
    expect($found !== false, 'RCPT-ADM01: expected the STORE-EVID-04 shipment to appear in the admin summary');
});

runTest('RCPT-ADM02/03/04/05/06 the Konfirmasi Toko Detail page renders the correct shipment, items, receiver/note/time, and evidence thumbnail', function () use ($adminHttp, $pdo) {
    $shipmentId = $GLOBALS['photo_shipment_id'] ?? null;
    $receiptId = $GLOBALS['photo_receipt_id'] ?? null;
    expect($shipmentId !== null && $receiptId !== null, 'depends on STORE-EVID-04 having run first');
    $r = $adminHttp->request('GET', "/_ui-preview/?page=konfirmasi-toko-detail&shipmentId={$shipmentId}");
    expect($r['status'] === 200, "RCPT-ADM02: detail page failed: {$r['status']}");
    expect(str_contains($r['body'], 'SHP-' . $shipmentId), 'RCPT-ADM03: expected the real shipment id on the page');
    expect(str_contains($r['body'], 'Toko A'), 'RCPT-ADM05: expected the receiver name Toko A');
    expect(str_contains($r['body'], '/api/admin/receipts/evidence/'), 'RCPT-ADM06: expected an evidence thumbnail reference');
    $evidenceRow = $pdo->query("SELECT shipment_receipt_evidence_id FROM shipment_receipt_evidence WHERE shipment_receipt_id = {$receiptId} LIMIT 1")->fetch();
    expect($evidenceRow !== false && str_contains($r['body'], '/api/admin/receipts/evidence/' . $evidenceRow['shipment_receipt_evidence_id']), 'RCPT-ADM06: expected the EXACT evidence id referenced on the page');
});

runTest('RCPT-ADM07 a clean (Diterima Sesuai) receipt detail is read-only (no editable qty inputs)', function () use ($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $baseUrl) {
    $fx = setupSingleItemShipment($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-09-03', 5.0);
    $anon = new Http55($baseUrl);
    $sii = shipmentItemIdFromToken($anon, $fx['token'], $fx['shipmentId']);
    $confirm = $anon->request('POST', "/api/receive/{$fx['token']}/shipments/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Toko Bersih', 'items' => [['shipmentItemId' => $sii, 'receivedGood' => 5.0, 'reject' => 0, 'shortage' => 0]],
    ], idemKey('rcptadm07'));
    expect($confirm['status'] === 200, 'confirm failed');

    $r = $adminHttp->request('GET', "/_ui-preview/?page=konfirmasi-toko-detail&shipmentId={$fx['shipmentId']}");
    expect($r['status'] === 200, 'detail page failed');
    // The shared admin shell always has its own global-search <input> in
    // the topbar (layout.php) — scoped instead to what would make THIS
    // page non-read-only: an editable numeric quantity field.
    expect(!str_contains($r['body'], 'type="number"'), 'RCPT-ADM07: expected a read-only detail page (no editable numeric qty <input>)');
    expect(str_contains($r['body'], 'Diterima Sesuai') || str_contains($r['body'], 'ok'), 'expected the clean status to show');
});

runTest('RCPT-ADM08 a discrepancy receipt detail exposes the Verifikasi Selisih action', function () use ($adminHttp) {
    $shipmentId = $GLOBALS['photo_shipment_id'] ?? null;
    expect($shipmentId !== null, 'depends on STORE-EVID-04 having run first');
    $r = $adminHttp->request('GET', "/_ui-preview/?page=konfirmasi-toko-detail&shipmentId={$shipmentId}");
    expect(str_contains($r['body'], 'Verifikasi Selisih'), 'RCPT-ADM08: expected the Verifikasi Selisih button to appear for a discrepancy receipt');
});

// --- Role correction: photo evidence is STORE evidence. Admin's role is
// view + verify ONLY — never upload/replace. ADM-EVID-01..05. ---

runTest('ADM-EVID-01 Admin detail can view Store evidence', function () use ($adminHttp, $pdo) {
    $shipmentId = $GLOBALS['photo_shipment_id'] ?? null;
    $receiptId = $GLOBALS['photo_receipt_id'] ?? null;
    expect($shipmentId !== null && $receiptId !== null, 'depends on STORE-EVID-04 having run first');
    $r = $adminHttp->request('GET', "/_ui-preview/?page=konfirmasi-toko-detail&shipmentId={$shipmentId}");
    expect($r['status'] === 200, "ADM-EVID-01: detail page failed: {$r['status']}");
    expect(str_contains($r['body'], 'Bukti Foto dari Toko'), 'ADM-EVID-01: expected the "Bukti Foto dari Toko" heading for a receipt WITH store evidence');
    expect(str_contains($r['body'], 'evidence-thumb'), 'ADM-EVID-01: expected an evidence thumbnail to render');
    $evidenceRow = $pdo->query("SELECT shipment_receipt_evidence_id FROM shipment_receipt_evidence WHERE shipment_receipt_id = {$receiptId} LIMIT 1")->fetch();
    expect($evidenceRow !== false && str_contains($r['body'], '/api/admin/receipts/evidence/' . $evidenceRow['shipment_receipt_evidence_id']), 'ADM-EVID-01: expected the real evidence id to be referenced via the admin-authenticated streaming route');
});

runTest('ADM-EVID-02 Admin cannot upload/replace Store evidence from the UI', function () use ($adminHttp, $adminCsrf, $baseUrl) {
    $shipmentId = $GLOBALS['photo_shipment_id'] ?? null;
    $receiptId = $GLOBALS['photo_receipt_id'] ?? null;
    expect($shipmentId !== null && $receiptId !== null, 'depends on STORE-EVID-04 having run first');

    // The admin-upload endpoint from the earlier revision of this patch
    // was REMOVED entirely (not just hidden) — a POST to it must 404
    // through the front controller like any other unknown route, never
    // silently accept a file.
    $ch = curl_init($baseUrl . "/api/admin/receipts/{$receiptId}/evidence");
    $jar = tempnam(sys_get_temp_dir(), 'admevgone');
    copy($adminHttp->cookieJarPath(), $jar);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $adminCsrf, 'Idempotency-Key: ' . idemKey('adm-evid-02')['Idempotency-Key']],
        CURLOPT_POSTFIELDS => ['evidence[]' => new CURLFile(fakeEvidenceImage(), 'image/png', 'sneaky.png')],
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    expect($status === 404, "ADM-EVID-02: expected the removed admin-upload endpoint to 404, got {$status}: {$raw}");

    // And the UI itself must never even offer the control.
    $r = $adminHttp->request('GET', "/_ui-preview/?page=konfirmasi-toko-detail&shipmentId={$shipmentId}");
    expect(!str_contains($r['body'], 'Unggah Bukti Foto'), 'ADM-EVID-02: expected NO "Unggah Bukti Foto" control anywhere on the admin detail page');
    expect(!str_contains($r['body'], 'admin-evidence-submit'), 'ADM-EVID-02: expected NO admin-evidence-submit upload button in the page markup');
    expect(!str_contains($r['body'], 'admin-evidence-input'), 'ADM-EVID-02: expected NO admin evidence file-chooser input in the page markup');
});

runTest('ADM-EVID-03 legacy discrepancy without Store evidence cannot be verified', function () use ($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $baseUrl) {
    // A legacy-shaped receipt: confirm math directly at the repository
    // layer would bypass the evidence gate — instead this proves the
    // real-world "old data" case (real UAT's actual SHP-3) by simulating
    // it precisely: insert a discrepancy receipt the same way
    // confirmReceipt() would have BEFORE this patch existed (no evidence
    // rows), then verify the admin verify gate still blocks it. There is
    // NO admin-side way to attach evidence any more (see ADM-EVID-02) —
    // this receipt stays permanently un-verifiable, by design, exactly
    // like real legacy SHP-3 (never repaired, never fabricated).
    $fx = setupSingleItemShipment($adminHttp, $adminCsrf, $httpA, $csrfA, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-09-04', 4.0);
    $repo = new \Amor\Api\Dispatch\ReceiptRepository();
    $receiptId = $repo->insertReceipt($pdo, $fx['shipmentId'], 'confirmed_discrepancy', 'Toko Lama', null);
    $view = (new \Amor\Api\Dispatch\ReceiptService($pdo))->getPublicView($fx['token']);
    $sii = $view['shipments'][0]['items'][0]['shipmentItemId'];
    $repo->insertReceiptItem($pdo, $receiptId, $sii, $view['shipments'][0]['items'][0]['productId'], 4.0, 3.0, 1.0, 0.0, null);

    $verify = $adminHttp->request('POST', "/api/admin/receipts/{$receiptId}/verify", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('rcptadm09')));
    expect($verify['status'] === 409, "ADM-EVID-03: expected 409, got {$verify['status']}: " . json_encode($verify['json']));
    expect($verify['json']['code'] === 'EVIDENCE_REQUIRED_FOR_VERIFY', 'expected EVIDENCE_REQUIRED_FOR_VERIFY, got ' . $verify['json']['code']);
    expect($verify['json']['message'] === 'Selisih belum dapat diverifikasi karena bukti foto dari toko belum tersedia.', 'ADM-EVID-03: expected the exact required message, got: ' . $verify['json']['message']);

    // Admin detail must show it as legacy, never as "clean"/repaired.
    $detail = $adminHttp->request('GET', "/_ui-preview/?page=konfirmasi-toko-detail&shipmentId={$fx['shipmentId']}");
    expect(str_contains($detail['body'], 'Tidak tersedia'), 'ADM-EVID-03: expected the detail page to show evidence as Tidak tersedia for this legacy receipt');
    expect(str_contains($detail['body'], 'sebelum aturan bukti foto diberlakukan'), 'ADM-EVID-03: expected the legacy explanatory note on the detail page');

    $GLOBALS['legacy_receipt_id'] = $receiptId;
    $GLOBALS['legacy_shipment_id'] = $fx['shipmentId'];
});

runTest('ADM-EVID-04 discrepancy WITH Store evidence can be verified', function () use ($adminHttp, $adminCsrf, $pdo) {
    // Uses the STORE-EVID-04 receipt — its evidence was uploaded by the
    // Store through the public confirm() route, never by Admin.
    $receiptId = $GLOBALS['photo_receipt_id'] ?? null;
    expect($receiptId !== null, 'depends on STORE-EVID-04 having run first');

    $qtyBefore = (string) $pdo->query("SELECT CONCAT(received_good_qty,':',reject_qty,':',shortage_qty) FROM shipment_receipt_item WHERE shipment_receipt_id = {$receiptId}")->fetchColumn();
    $evidenceCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM shipment_receipt_evidence WHERE shipment_receipt_id = {$receiptId}")->fetchColumn();
    $ledgerBefore = shipmentOutRows($pdo);

    $verifyKey = idemKey('adm-evid-04-verify');
    $GLOBALS['adm_evid04_verify_key'] = $verifyKey;
    $verify = $adminHttp->request('POST', "/api/admin/receipts/{$receiptId}/verify", [], array_merge(['X-CSRF-Token' => $adminCsrf], $verifyKey));
    expect($verify['status'] === 200, "ADM-EVID-04: expected verify to succeed once store evidence exists: " . json_encode($verify['json']));
    expect($verify['json']['data']['status'] === 'verified', 'ADM-EVID-04: expected status=verified');

    $GLOBALS['adm_evid04_qty_before'] = $qtyBefore;
    $GLOBALS['adm_evid04_evidence_count_before'] = $evidenceCountBefore;
    $GLOBALS['adm_evid04_ledger_before'] = $ledgerBefore;
    $GLOBALS['adm_evid04_ledger_after'] = shipmentOutRows($pdo);
});

runTest('ADM-EVID-05 verification does not alter evidence or quantities', function () use ($adminHttp, $pdo) {
    $shipmentId = $GLOBALS['photo_shipment_id'] ?? null;
    $receiptId = $GLOBALS['photo_receipt_id'] ?? null;
    $qtyBefore = $GLOBALS['adm_evid04_qty_before'] ?? null;
    $evidenceCountBefore = $GLOBALS['adm_evid04_evidence_count_before'] ?? null;
    expect($shipmentId !== null && $receiptId !== null && $qtyBefore !== null && $evidenceCountBefore !== null, 'depends on STORE-EVID-04/ADM-EVID-04 having run first');

    $qtyAfter = (string) $pdo->query("SELECT CONCAT(received_good_qty,':',reject_qty,':',shortage_qty) FROM shipment_receipt_item WHERE shipment_receipt_id = {$receiptId}")->fetchColumn();
    expect($qtyAfter === $qtyBefore, "ADM-EVID-05: expected receipt item quantities UNCHANGED by verification (before={$qtyBefore}, after={$qtyAfter})");
    $evidenceCount = (int) $pdo->query("SELECT COUNT(*) FROM shipment_receipt_evidence WHERE shipment_receipt_id = {$receiptId}")->fetchColumn();
    expect($evidenceCount === $evidenceCountBefore, 'ADM-EVID-05: expected the evidence row count UNCHANGED by verification (Admin never adds/replaces it)');
    expect($evidenceCount === 1, 'ADM-EVID-05: expected the ONE store-uploaded evidence row to still be the only one after verification (Admin never adds/replaces it)');

    $r = $adminHttp->request('GET', "/_ui-preview/?page=konfirmasi-toko-detail&shipmentId={$shipmentId}");
    expect(str_contains($r['body'], 'Diverifikasi Admin') || str_contains($r['body'], 'Diverifikasi oleh'), 'ADM-EVID-05: expected the verified status to show on the detail page');
    expect(str_contains($r['body'], 'Bukti Foto dari Toko'), 'ADM-EVID-05: expected the store evidence to remain visible after verification');
});

runTest('RCPT-ADM13 admin verify creates ZERO stock_ledger rows', function () {
    $before = $GLOBALS['adm_evid04_ledger_before'] ?? null;
    $after = $GLOBALS['adm_evid04_ledger_after'] ?? null;
    expect($before !== null && $after !== null, 'depends on ADM-EVID-04 having run first');
    expect($before === $after, "RCPT-ADM13: expected admin verify to create ZERO stock_ledger rows (before={$before}, after={$after})");
});

runTest('RCPT-ADM14 a non-admin (Driver) gets 403 on the admin verify endpoint', function () use ($httpA, $csrfA) {
    $r = $httpA->request('POST', '/api/admin/receipts/999999/verify', [], array_merge(['X-CSRF-Token' => $csrfA], idemKey('rcptadm14')));
    expect($r['status'] === 403, "RCPT-ADM14: expected 403 for a non-admin caller, got {$r['status']}");
});

runTest('RCPT-ADM15a repeating the EXACT SAME verify request (same Idempotency-Key) replays the stored result, never re-executes', function () use ($adminHttp, $adminCsrf, $pdo) {
    $receiptId = $GLOBALS['photo_receipt_id'] ?? null;
    $verifyKey = $GLOBALS['adm_evid04_verify_key'] ?? null;
    expect($receiptId !== null && $verifyKey !== null, 'depends on ADM-EVID-04 having run first (already verified)');
    $countBefore = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE record_type = 'shipment_receipt' AND record_key = '{$receiptId}' AND action = 'receipt.verified'")->fetchColumn();
    $replay = $adminHttp->request('POST', "/api/admin/receipts/{$receiptId}/verify", [], array_merge(['X-CSRF-Token' => $adminCsrf], $verifyKey));
    expect($replay['status'] === 200, 'RCPT-ADM15a: expected an exact-key replay to return the stored 200, not error: ' . json_encode($replay['json']));
    expect($replay['json']['data']['status'] === 'verified', 'expected the replayed response to still show verified');
    $countAfter = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE record_type = 'shipment_receipt' AND record_key = '{$receiptId}' AND action = 'receipt.verified'")->fetchColumn();
    expect($countAfter === $countBefore, 'RCPT-ADM15a: a replayed request must never write a second receipt.verified audit entry');
});

runTest('RCPT-ADM15b a genuinely NEW verify attempt (different Idempotency-Key) on an already-verified receipt is rejected, never silently re-verified', function () use ($adminHttp, $adminCsrf) {
    $receiptId = $GLOBALS['photo_receipt_id'] ?? null;
    expect($receiptId !== null, 'depends on ADM-EVID-04 having run first (already verified)');
    $again = $adminHttp->request('POST', "/api/admin/receipts/{$receiptId}/verify", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('rcptadm15b-fresh')));
    expect($again['status'] === 409, "RCPT-ADM15b: expected 409 for a genuinely new verify attempt on an already-verified receipt, got {$again['status']}");
});

runTest('MOBILE-01 the Store Receipt page ships CSS that stacks the receipt table on a narrow viewport (Kurang is never clipped)', function () use ($baseUrl) {
    $ch = curl_init($baseUrl . '/api/assets/css/receipt.css');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $css = curl_exec($ch);
    curl_close($ch);
    expect((bool) preg_match('/@media\s*\(max-width:\s*480px\)/', $css), 'MOBILE-01: expected a narrow-viewport breakpoint in receipt.css');
    expect(str_contains($css, 'data-label'), 'MOBILE-01: expected the responsive rule to key off data-label (every column, including Kurang, gets a caption when stacked)');
});

runTest('MOBILE-02 the Store Receipt page JS renders a photo upload control usable in a mobile browser', function () use ($baseUrl) {
    $ch = curl_init($baseUrl . '/api/assets/js/receipt.js');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $js = curl_exec($ch);
    curl_close($ch);
    expect(str_contains($js, 'accept="image/*"'), 'MOBILE-02: expected an image-only file input');
    expect(str_contains($js, 'capture='), 'MOBILE-02: expected a capture attribute so a mobile browser offers the camera directly');
    expect(str_contains($js, 'multiple'), 'MOBILE-02: expected the file input to allow choosing more than one photo');
});

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
