<?php

declare(strict_types=1);

/**
 * Phase 5 fast-track Draft DO / staged Shipment integration test suite
 * (P5-01 .. P5-36). Run via api/tests/run-phase5-do-shipment.sh, which
 * stands up a disposable local MariaDB, bootstraps realistic post-Phase-4
 * master data (same _phase2_bootstrap_master.php Phase 2/3/4 already use —
 * including the P2 TEST STORE A/B fixtures this suite needs for
 * store-level PO), applies migrations 0001-0006 for real through the
 * existing api/_upgrade/ wizard, then drives the real DO/Shipment JSON API
 * end to end against a live `php -S` server.
 *
 * PO fixtures are seeded directly into po_batch/po_item/po_store_item
 * (never through the parser — same rationale as every prior phase's test
 * suite). Production and FG, by contrast, are driven through the REAL
 * Production and FG JSON APIs (create/patch/submit) since they are the
 * direct upstream dependency this suite's shipments actually draw stock
 * from — shortcutting them would test against fake ledger state.
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8102';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'p5_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpP5
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'p5cookies');
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

/**
 * Seeds po_batch/po_item/po_store_item directly (never through the
 * parser) for ONE store's demand — mirrors what a real store-level PO
 * import produces (po_item.po_awal/po_revisi as the aggregate, matched
 * here to the single store since every fixture in this suite orders
 * through exactly one store per item). Bumps po_batch.version on repeat
 * calls, exactly like Phase 2-4's plain seedPo() did for po_item alone.
 * @param array<int,array{poAwal?:float,poRevisi?:float,kategori?:string}> $items keyed by product_id
 */
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

/** Drives the REAL Production API end to end: create draft -> patch actual -> submit. @return array{productionRunId:int,version:int} */
function createSubmittedProduction(HttpP5 $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual): array
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

/** Full chain: Production submitted -> FG created/verified/submitted, leaving exactly $fgQty available FG stock for $productId at $factoryId. */
function stockUpForDelivery(HttpP5 $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual, float $fgQty): void
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

/**
 * Same chain as stockUpForDelivery(), but for SEVERAL products across
 * several divisions sharing ONE fg_batch (create/patch/submit called once,
 * with every product's line) — fg_batch identity is (tanggal,factoryId)
 * only, so calling stockUpForDelivery() twice for the same tanggal+factory
 * would hit the SAME batch a second time after it's already submitted.
 * @param array<int,array{0:int,1:int,2:float,3:float}> $productions list of [divisionId, productId, poTarget, actual]
 * @param array<int,float> $fgQtyByProduct productId => fgVerified/packed qty
 */
function stockUpMultiForDelivery(HttpP5 $http, string $csrf, PDO $pdo, int $factoryId, string $tanggal, int $storeId, array $productions, array $fgQtyByProduct): void
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

/** Creates (or opens the existing open) DO for tanggal/storeId via the real API. @return array{doId:int,docNo:string,version:int} */
function createDoDraft(HttpP5 $http, string $csrf, string $tanggal, int $storeId): array
{
    $r = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeId], array_merge(['X-CSRF-Token' => $csrf], idemKey('do-create')));
    expect($r['status'] === 200, 'DO create failed: ' . json_encode($r['json']));
    return ['doId' => (int) $r['json']['data']['doId'], 'docNo' => $r['json']['data']['docNo'], 'version' => (int) $r['json']['data']['version']];
}

function getDo(HttpP5 $http, string $csrf, int $doId): array
{
    $r = $http->request('GET', "/api/do/{$doId}", null, ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, 'DO get failed: ' . json_encode($r['json']));
    return $r['json']['data'];
}

function shipItems(HttpP5 $http, string $csrf, int $doId, int $expectedVersion, string $group, array $items): array
{
    return $http->request('POST', "/api/do/{$doId}/ship", [
        'expectedVersion' => $expectedVersion, 'shipmentGroup' => $group, 'items' => $items,
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('ship')));
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

function login(HttpP5 $http, string $username, string $password): string
{
    $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    $me = $http->request('GET', '/api/auth/me');
    $csrf = $me['json']['data']['csrfToken'] ?? null;
    expect($csrf !== null, "expected a csrf token after login as {$username}");
    return $csrf;
}

function locationIdForFactory(PDO $pdo, int $factoryId): int
{
    $stmt = $pdo->prepare('SELECT location_id FROM location WHERE factory_id = ?');
    $stmt->execute([$factoryId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : 0;
}

function ledgerRows(PDO $pdo, int $productId, int $locationId): array
{
    $stmt = $pdo->prepare("SELECT * FROM stock_ledger WHERE product_id = ? AND location_id = ? AND event_type = 'shipment_out' ORDER BY stock_ledger_id");
    $stmt->execute([$productId, $locationId]);
    return $stmt->fetchAll();
}

function balanceRow(PDO $pdo, int $productId, int $locationId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM stock_balance WHERE product_id = ? AND location_id = ?');
    $stmt->execute([$productId, $locationId]);
    $row = $stmt->fetch();
    return $row ?: null;
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

$http = new HttpP5($baseUrl);
$csrf = login($http, $adminUser, $adminPass);

// ---------------------------------------------------------------------
// P5-00 (setup, not part of the required 36): apply migration 0006.
// ---------------------------------------------------------------------
runTest('P5-00 migration 0006 applied via the existing api/_upgrade/ wizard', function () use ($http, $baseUrl) {
    $page = $http->request('GET', '/_upgrade/');
    expect($page['status'] === 200, "expected 200 from _upgrade/, got {$page['status']}");
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $page['body'], $m)) {
        throw new RuntimeException('expected to find a csrf token on the upgrade page');
    }
    expect(str_contains($page['body'], '0006_do_shipment_phase5.php'), 'expected migration 0006 to be listed as pending');

    $refl = new ReflectionProperty(HttpP5::class, 'cookieJar');
    $refl->setAccessible(true);
    $jar = $refl->getValue($http);

    $ch = curl_init(rtrim($baseUrl, '/') . '/_upgrade/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['csrf' => $m[1], 'action' => 'apply', 'confirm' => '1']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    expect(str_contains((string) $body, 'Migrasi berhasil diterapkan') || str_contains((string) $body, 'Tidak ada yang perlu diterapkan'),
        'expected migration 0006 to apply successfully: ' . substr((string) $body, 0, 500));
});

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------
$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
$cibadakId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Cibadak'")->fetchColumn();
expect($karangtengahId > 0 && $cibadakId > 0, 'expected both factories seeded');

$rotiBollenDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
$basicDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Basic'")->fetchColumn();
$boluDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Bolu'")->fetchColumn();
expect($rotiBollenDivId > 0 && $basicDivId > 0 && $boluDivId > 0, 'expected test divisions seeded');

$rotiProducts = productsInDivision($pdo, $rotiBollenDivId, 40);
$basicProducts = productsInDivision($pdo, $basicDivId, 3);
$boluProducts = productsInDivision($pdo, $boluDivId, 3);
expect(count($rotiProducts) >= 40 && count($basicProducts) >= 3 && count($boluProducts) >= 3, 'expected enough katalog products per division after bootstrap');
$pool = $rotiProducts; // array_shift()'d sequentially, one fresh product per test that needs one
function nextProduct(): array { global $pool; $p = array_shift($pool); expect($p !== null, 'ran out of pooled test products'); return $p; }

$storeA = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
$storeB = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE B'")->fetchColumn();
expect($storeA > 0 && $storeB > 0, 'expected P2 TEST STORE A/B fixtures seeded');

// ---------------------------------------------------------------------
// Group A — Draft DO creation & identity
// ---------------------------------------------------------------------
runTest('P5-01 DO created from live store PO demand, items match po_awal+po_revisi', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-05-01';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0, 'poRevisi' => 2.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $dto = getDo($http, $csrf, $do['doId']);
    $item = current(array_filter($dto['items'], fn ($i) => $i['productId'] === $p['product_id']));
    expect($item !== false && (float) $item['plannedQty'] === 12.0, 'expected planned 12 (10+2), got ' . json_encode($item));
});

runTest('P5-02 DO identity (tanggal,storeId) is idempotent — repeat create returns same doId, no duplicate row', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-02';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $do1 = createDoDraft($http, $csrf, $tanggal, $storeA);
    $do2 = createDoDraft($http, $csrf, $tanggal, $storeA);
    expect($do1['doId'] === $do2['doId'], 'expected the second create to return the SAME doId');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM delivery_order WHERE tanggal = ? AND store_id = ?');
    $stmt->execute([$tanggal, $storeA]);
    expect((int) $stmt->fetchColumn() === 1, 'expected exactly ONE delivery_order row for this (tanggal,storeId)');
});

runTest('P5-03 createDraft with zero PO demand is rejected', function () use ($http, $csrf, $storeA) {
    $tanggal = '2026-05-03';
    $r = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('do-empty')));
    expect($r['status'] === 400 && $r['json']['code'] === 'NO_PO_DEMAND', 'expected 400 NO_PO_DEMAND: ' . json_encode($r['json']));
});

runTest('P5-04 DO items reflect the PO revision (po_awal+po_revisi), not po_awal alone', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-04';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 20.0, 'poRevisi' => -3.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $dto = getDo($http, $csrf, $do['doId']);
    $item = current(array_filter($dto['items'], fn ($i) => $i['productId'] === $p['product_id']));
    expect((float) $item['plannedQty'] === 17.0, 'expected planned 17 (20-3), got ' . json_encode($item));
});

runTest('P5-05 a store\'s demand spanning two factories (Karangtengah+Cibadak) becomes ONE DO', function () use ($http, $csrf, $pdo, $karangtengahId, $cibadakId, $storeA) {
    $tanggal = '2026-05-05';
    $pK = nextProduct();
    $pC = $GLOBALS['boluProducts'][0];
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$pK['product_id'] => ['poAwal' => 8.0]]);
    seedStorePo($pdo, $tanggal, $cibadakId, $storeA, [$pC['product_id'] => ['poAwal' => 4.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $dto = getDo($http, $csrf, $do['doId']);
    $ids = array_column($dto['items'], 'productId');
    expect(in_array($pK['product_id'], $ids, true) && in_array($pC['product_id'], $ids, true), 'expected ONE DO with items from both factories: ' . json_encode($ids));
});

runTest('P5-06 DO number matches the legacy format DO/KRM/{seq:3}/{romanMonth}/{yyyy}', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-06';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 3.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    expect((bool) preg_match('#^DO/KRM/\d{3}/[IVXLCDM]+/2026$#', $do['docNo']), 'unexpected DO number format: ' . $do['docNo']);
});

// ---------------------------------------------------------------------
// Group B — DO numbering sequence
// ---------------------------------------------------------------------
runTest('P5-07 DO numbers increment sequentially across different stores in the same month', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA, $storeB) {
    $tanggal = '2026-05-07';
    $p1 = nextProduct();
    $p2 = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p1['product_id'] => ['poAwal' => 3.0]]);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeB, [$p2['product_id'] => ['poAwal' => 3.0]]);
    $do1 = createDoDraft($http, $csrf, $tanggal, $storeA);
    $do2 = createDoDraft($http, $csrf, $tanggal, $storeB);
    preg_match('#/(\d{3})/#', $do1['docNo'], $m1);
    preg_match('#/(\d{3})/#', $do2['docNo'], $m2);
    expect((int) $m2[1] > (int) $m1[1], "expected increasing sequence, got {$do1['docNo']} then {$do2['docNo']}");
});

runTest('P5-08 a cancelled DO\'s number is never reused by a later DO', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA, $storeB) {
    $tanggal = '2026-05-08';
    $p1 = nextProduct();
    $p2 = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p1['product_id'] => ['poAwal' => 3.0]]);
    $do1 = createDoDraft($http, $csrf, $tanggal, $storeA);
    $cancel = $http->request('POST', "/api/do/{$do1['doId']}/cancel", ['expectedVersion' => $do1['version'], 'reason' => 'test cancel'], array_merge(['X-CSRF-Token' => $csrf], idemKey('do-cancel')));
    expect($cancel['status'] === 200, 'cancel failed: ' . json_encode($cancel['json']));

    seedStorePo($pdo, $tanggal, $karangtengahId, $storeB, [$p2['product_id'] => ['poAwal' => 3.0]]);
    $do2 = createDoDraft($http, $csrf, $tanggal, $storeB);
    preg_match('#/(\d{3})/#', $do1['docNo'], $m1);
    preg_match('#/(\d{3})/#', $do2['docNo'], $m2);
    expect((int) $m2[1] > (int) $m1[1], 'expected the cancelled DO\'s number to never be reused');
});

// ---------------------------------------------------------------------
// Group C — Lifecycle: preprint
// ---------------------------------------------------------------------
runTest('P5-09 preprint moves draft -> preprinted', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-09';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 3.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = $http->request('POST', "/api/do/{$do['doId']}/preprint", ['expectedVersion' => $do['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey('preprint')));
    expect($r['status'] === 200 && $r['json']['data']['status'] === 'preprinted', 'expected status preprinted: ' . json_encode($r['json']));
});

runTest('P5-10 re-preprinting an already-preprinted DO is allowed and does not create a new DO', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-10';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 3.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r1 = $http->request('POST', "/api/do/{$do['doId']}/preprint", ['expectedVersion' => $do['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey('preprint1')));
    $v = $r1['json']['data']['version'];
    $r2 = $http->request('POST', "/api/do/{$do['doId']}/preprint", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('preprint2')));
    expect($r2['status'] === 200 && $r2['json']['data']['status'] === 'preprinted', 're-preprint should succeed: ' . json_encode($r2['json']));
    expect($r2['json']['data']['doId'] === $do['doId'], 'expected the SAME doId, not a new DO');
});

runTest('P5-11 preprint with a stale expectedVersion is rejected', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-11';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 3.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = $http->request('POST', "/api/do/{$do['doId']}/preprint", ['expectedVersion' => $do['version'] + 99], array_merge(['X-CSRF-Token' => $csrf], idemKey('preprint-stale')));
    expect($r['status'] === 409 && $r['json']['code'] === 'VERSION_CONFLICT', 'expected 409 VERSION_CONFLICT: ' . json_encode($r['json']));
});

runTest('P5-12 preprint on a cancelled DO is rejected', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-12';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 3.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $cancel = $http->request('POST', "/api/do/{$do['doId']}/cancel", ['expectedVersion' => $do['version'], 'reason' => 'test'], array_merge(['X-CSRF-Token' => $csrf], idemKey('cancel')));
    $v = $cancel['json']['data']['version'];
    $r = $http->request('POST', "/api/do/{$do['doId']}/preprint", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('preprint-cancelled')));
    expect($r['status'] === 409 && $r['json']['code'] === 'INVALID_STATUS', 'expected 409 INVALID_STATUS: ' . json_encode($r['json']));
});

// ---------------------------------------------------------------------
// Group D — refresh-from-PO
// ---------------------------------------------------------------------
runTest('P5-13 refresh-po picks up a PO revision increase', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-13';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0, 'poRevisi' => 5.0]]);
    $r = $http->request('POST', "/api/do/{$do['doId']}/refresh-po", ['expectedVersion' => $do['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey('refresh')));
    expect($r['status'] === 200, 'refresh failed: ' . json_encode($r['json']));
    $item = current(array_filter($r['json']['data']['items'], fn ($i) => $i['productId'] === $p['product_id']));
    expect((float) $item['plannedQty'] === 15.0, 'expected planned refreshed to 15, got ' . json_encode($item));
});

runTest('P5-14 refresh-po never drops planned_qty below already-shipped', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-05-14';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $ship = shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 8.0]]);
    expect($ship['status'] === 200, 'ship failed: ' . json_encode($ship['json']));
    $dto = getDo($http, $csrf, $do['doId']);

    // Live PO now drops to 3 (below the already-shipped 8) — refresh must floor at 8, never lower.
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 3.0]]);
    $r = $http->request('POST', "/api/do/{$do['doId']}/refresh-po", ['expectedVersion' => $dto['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey('refresh-floor')));
    expect($r['status'] === 200, 'refresh failed: ' . json_encode($r['json']));
    $item = current(array_filter($r['json']['data']['items'], fn ($i) => $i['productId'] === $p['product_id']));
    expect((float) $item['plannedQty'] === 8.0, 'expected planned floored at 8 (already shipped), got ' . json_encode($item));
});

runTest('P5-15 refresh-po on a shipped DO is rejected', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-05-15';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 5.0, 5.0, 5.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $ship = shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 5.0]]);
    expect($ship['status'] === 200 && $ship['json']['data']['doFullyFulfilled'] === true, 'expected fully fulfilled ship: ' . json_encode($ship['json']));
    $v = $ship['json']['data'] ? getDo($http, $csrf, $do['doId'])['version'] : null;

    $r = $http->request('POST', "/api/do/{$do['doId']}/refresh-po", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('refresh-shipped')));
    expect($r['status'] === 409 && $r['json']['code'] === 'INVALID_STATUS', 'expected 409 INVALID_STATUS: ' . json_encode($r['json']));
});

// ---------------------------------------------------------------------
// Group E — Cancel
// ---------------------------------------------------------------------
runTest('P5-16 cancel a draft DO with zero shipped succeeds', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-16';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 3.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = $http->request('POST', "/api/do/{$do['doId']}/cancel", ['expectedVersion' => $do['version'], 'reason' => 'salah input'], array_merge(['X-CSRF-Token' => $csrf], idemKey('cancel')));
    expect($r['status'] === 200 && $r['json']['data']['status'] === 'cancelled', 'expected cancelled: ' . json_encode($r['json']));
});

runTest('P5-17 cancel is blocked once ANY qty has shipped', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-05-17';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $ship = shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 4.0]]);
    expect($ship['status'] === 200, 'ship failed: ' . json_encode($ship['json']));
    $dto = getDo($http, $csrf, $do['doId']);

    $r = $http->request('POST', "/api/do/{$do['doId']}/cancel", ['expectedVersion' => $dto['version'], 'reason' => 'coba batal'], array_merge(['X-CSRF-Token' => $csrf], idemKey('cancel-blocked')));
    expect($r['status'] === 409 && $r['json']['code'] === 'CANNOT_CANCEL_SHIPPED', 'expected 409 CANNOT_CANCEL_SHIPPED: ' . json_encode($r['json']));
});

runTest('P5-18 cancel requires a non-blank reason', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-18';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 3.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = $http->request('POST', "/api/do/{$do['doId']}/cancel", ['expectedVersion' => $do['version'], 'reason' => ''], array_merge(['X-CSRF-Token' => $csrf], idemKey('cancel-blank')));
    expect($r['status'] === 400 && $r['json']['code'] === 'REASON_REQUIRED', 'expected 400 REASON_REQUIRED: ' . json_encode($r['json']));
});

// ---------------------------------------------------------------------
// Group F — Shipment preview (read-only)
// ---------------------------------------------------------------------
runTest('P5-19 shipment preview reports ok and writes NOTHING to stock_ledger', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-05-19';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = $http->request('POST', "/api/do/{$do['doId']}/shipment-preview", ['items' => [['productId' => $p['product_id'], 'actualQty' => 4.0]]], ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200 && $r['json']['data']['ok'] === true, 'expected ok preview: ' . json_encode($r['json']));
    $line = $r['json']['data']['lines'][0];
    expect((float) $line['maxShippable'] === 10.0, 'expected maxShippable 10 (min of remaining 10 and available 10), got ' . json_encode($line));

    $locId = locationIdForFactory($pdo, $karangtengahId);
    expect(ledgerRows($pdo, $p['product_id'], $locId) === [], 'preview must never write shipment_out ledger rows');
});

runTest('P5-20 shipment preview flags EXCEEDS_AVAILABLE when requested exceeds live FG stock', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-05-20';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 2.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = $http->request('POST', "/api/do/{$do['doId']}/shipment-preview", ['items' => [['productId' => $p['product_id'], 'actualQty' => 6.0]]], ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200 && $r['json']['data']['ok'] === false, 'expected preview not ok: ' . json_encode($r['json']));
    expect(in_array('EXCEEDS_AVAILABLE', $r['json']['data']['lines'][0]['errors'], true), 'expected EXCEEDS_AVAILABLE: ' . json_encode($r['json']));
});

// ---------------------------------------------------------------------
// Group G — Ship validation
// ---------------------------------------------------------------------
runTest('P5-21 ship rejects a negative quantity', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-21';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $p['product_id'], 'actualQty' => -1.0]]);
    expect($r['status'] === 400 && $r['json']['code'] === 'NEGATIVE_QTY', 'expected 400 NEGATIVE_QTY: ' . json_encode($r['json']));
});

runTest('P5-22 ship rejects a quantity exceeding the DO\'s remaining', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-22';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 9.0]]);
    expect($r['status'] === 400 && $r['json']['code'] === 'EXCEEDS_REMAINING', 'expected 400 EXCEEDS_REMAINING: ' . json_encode($r['json']));
});

runTest('P5-23 ship rejects insufficient FG stock', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-23';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 5.0]]);
    expect($r['status'] === 409 && $r['json']['code'] === 'INSUFFICIENT_FG_AVAILABLE', 'expected 409 INSUFFICIENT_FG_AVAILABLE: ' . json_encode($r['json']));
});

runTest('P5-24 ship rejects a product that is not part of the DO', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-24';
    $p = nextProduct();
    $unrelated = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $unrelated['product_id'], 'actualQty' => 1.0]]);
    expect($r['status'] === 400 && $r['json']['code'] === 'UNKNOWN_PRODUCT_FOR_DO', 'expected 400 UNKNOWN_PRODUCT_FOR_DO: ' . json_encode($r['json']));
});

// ---------------------------------------------------------------------
// Group H — Ship commit & stock
// ---------------------------------------------------------------------
runTest('P5-25 a successful ship posts exactly one shipment_out ledger row per product', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-05-25';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 6.0]]);
    expect($r['status'] === 200, 'ship failed: ' . json_encode($r['json']));

    $locId = locationIdForFactory($pdo, $karangtengahId);
    $rows = ledgerRows($pdo, $p['product_id'], $locId);
    expect(count($rows) === 1, 'expected exactly 1 shipment_out ledger row, got ' . count($rows));
    expect((float) $rows[0]['qty_delta'] === -6.0, 'expected qty_delta -6, got ' . $rows[0]['qty_delta']);
    expect($rows[0]['source_type'] === 'shipment_item', 'expected source_type=shipment_item');
});

runTest('P5-26 a successful ship reduces stock_balance accordingly', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-05-26';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 7.0]]);

    $locId = locationIdForFactory($pdo, $karangtengahId);
    $balance = balanceRow($pdo, $p['product_id'], $locId);
    expect($balance !== null && (float) $balance['qty_on_hand'] === 3.0, 'expected balance 3 (10-7), got ' . json_encode($balance));
});

runTest('P5-27 draft/preprinted DO never writes to stock_ledger', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-05-27';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $http->request('POST', "/api/do/{$do['doId']}/preprint", ['expectedVersion' => $do['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey('preprint')));

    $locId = locationIdForFactory($pdo, $karangtengahId);
    expect(ledgerRows($pdo, $p['product_id'], $locId) === [], 'draft/preprint must never post shipment_out ledger rows');
});

runTest('P5-28 ship with a stale expectedVersion is rejected before any stock is touched', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-05-28';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = shipItems($http, $csrf, $do['doId'], $do['version'] + 99, 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 5.0]]);
    expect($r['status'] === 409 && $r['json']['code'] === 'VERSION_CONFLICT', 'expected 409 VERSION_CONFLICT: ' . json_encode($r['json']));

    $locId = locationIdForFactory($pdo, $karangtengahId);
    expect(ledgerRows($pdo, $p['product_id'], $locId) === [], 'a rejected ship() must post ZERO ledger rows');
});

// ---------------------------------------------------------------------
// Group I — Partial / staged shipments
// ---------------------------------------------------------------------
runTest('P5-29 a partial shipment leaves the DO NOT shipped, with correct remaining', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-05-29';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 4.0]]);
    expect($r['status'] === 200 && $r['json']['data']['doFullyFulfilled'] === false, 'expected NOT fully fulfilled: ' . json_encode($r['json']));
    expect((float) $r['json']['data']['doTotalRemaining'] === 6.0, 'expected remaining 6, got ' . json_encode($r['json']['data']));
    $dto = getDo($http, $csrf, $do['doId']);
    expect($dto['status'] !== 'shipped', 'expected DO status to stay draft/preprinted after a partial shipment');
});

runTest('P5-30 a second staged shipment (different group) sums cumulatively against the first', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-05-30';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r1 = shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 4.0]]);
    expect($r1['status'] === 200, 'first ship failed: ' . json_encode($r1['json']));
    $dto = getDo($http, $csrf, $do['doId']);
    $r2 = shipItems($http, $csrf, $do['doId'], $dto['version'], 'PASTRY', [['productId' => $p['product_id'], 'actualQty' => 3.0]]);
    expect($r2['status'] === 200, 'second ship failed: ' . json_encode($r2['json']));
    expect((float) $r2['json']['data']['doTotalShipped'] === 7.0, 'expected cumulative shipped 7 (4+3), got ' . json_encode($r2['json']['data']));
    expect($r2['json']['data']['shipmentId'] !== $r1['json']['data']['shipmentId'], 'expected two DISTINCT shipment rows');
});

runTest('P5-31 DO flips to shipped only once EVERY item reaches its planned qty', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-05-31';
    $pX = nextProduct();
    // pY comes from a SEPARATE division (Basic) so its own createSubmittedProduction()
    // call creates a distinct production_run — reusing rotiBollenDivId for both would hit
    // the same (tanggal,divisionId) run twice, and the second call's PATCH would fail
    // since the run is already SUBMITTED from the first call (the exact P4-02 fixture bug).
    $pY = $GLOBALS['basicProducts'][0];
    stockUpMultiForDelivery($http, $csrf, $pdo, $karangtengahId, $tanggal, $storeA, [
        [$rotiBollenDivId, $pX['product_id'], 5.0, 5.0],
        [$GLOBALS['basicDivId'], $pY['product_id'], 5.0, 5.0],
    ], [$pX['product_id'] => 5.0, $pY['product_id'] => 5.0]);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$pX['product_id'] => ['poAwal' => 5.0], $pY['product_id'] => ['poAwal' => 5.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);

    $r1 = shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $pX['product_id'], 'actualQty' => 5.0]]);
    expect($r1['status'] === 200 && $r1['json']['data']['doFullyFulfilled'] === false, 'expected NOT fulfilled after only pX shipped: ' . json_encode($r1['json']));
    $dto = getDo($http, $csrf, $do['doId']);
    expect($dto['status'] !== 'shipped', 'DO must not be shipped after only the first item is complete');

    $r2 = shipItems($http, $csrf, $do['doId'], $dto['version'], 'PASTRY', [['productId' => $pY['product_id'], 'actualQty' => 5.0]]);
    expect($r2['status'] === 200 && $r2['json']['data']['doFullyFulfilled'] === true, 'expected fulfilled once pY also completes: ' . json_encode($r2['json']));
    $dtoFinal = getDo($http, $csrf, $do['doId']);
    expect($dtoFinal['status'] === 'shipped', 'expected DO status shipped, got ' . $dtoFinal['status']);
});

runTest('P5-32 shipped-qty-by-product is derived live across shipments (never a stale cache)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-06-01';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 4.0]]);
    $dto1 = getDo($http, $csrf, $do['doId']);
    $item1 = current(array_filter($dto1['items'], fn ($i) => $i['productId'] === $p['product_id']));
    expect((float) $item1['alreadyShippedQty'] === 4.0, 'expected alreadyShippedQty 4 after first ship, got ' . json_encode($item1));

    shipItems($http, $csrf, $do['doId'], $dto1['version'], 'PASTRY', [['productId' => $p['product_id'], 'actualQty' => 2.0]]);
    $dto2 = getDo($http, $csrf, $do['doId']);
    $item2 = current(array_filter($dto2['items'], fn ($i) => $i['productId'] === $p['product_id']));
    expect((float) $item2['alreadyShippedQty'] === 6.0, 'expected alreadyShippedQty 6 after second ship, got ' . json_encode($item2));
});

// ---------------------------------------------------------------------
// Group J — Concurrency / cross-cutting
// ---------------------------------------------------------------------
runTest('P5-33 mixing products from two different factories in ONE ship call is rejected', function () use ($http, $csrf, $pdo, $karangtengahId, $cibadakId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-06-02';
    $pK = nextProduct();
    $pC = $GLOBALS['boluProducts'][1];
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $pK['product_id'], 5.0, 5.0, 5.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$pK['product_id'] => ['poAwal' => 5.0]]);
    seedStorePo($pdo, $tanggal, $cibadakId, $storeA, [$pC['product_id'] => ['poAwal' => 3.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $r = shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [
        ['productId' => $pK['product_id'], 'actualQty' => 2.0],
        ['productId' => $pC['product_id'], 'actualQty' => 1.0],
    ]);
    expect($r['status'] === 400 && $r['json']['code'] === 'MIXED_FACTORY_SHIPMENT', 'expected 400 MIXED_FACTORY_SHIPMENT: ' . json_encode($r['json']));
});

runTest('P5-34 bulk-generate is idempotent — a second run for the same date/factory creates zero new DOs', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA, $storeB) {
    $tanggal = '2026-06-03';
    $p1 = nextProduct();
    $p2 = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p1['product_id'] => ['poAwal' => 3.0]]);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeB, [$p2['product_id'] => ['poAwal' => 3.0]]);
    $r1 = $http->request('POST', '/api/do/generate-bulk', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('bulk1')));
    expect($r1['status'] === 200 && (int) $r1['json']['data']['created'] === 2, 'expected 2 created on first run: ' . json_encode($r1['json']));

    $r2 = $http->request('POST', '/api/do/generate-bulk', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('bulk2')));
    expect($r2['status'] === 200 && (int) $r2['json']['data']['created'] === 0 && (int) $r2['json']['data']['alreadyExisted'] === 2, 'expected 0 created / 2 alreadyExisted on second run: ' . json_encode($r2['json']));
});

runTest('P5-35 DO list aggregates (planned/shipped/remaining) match the underlying items', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-06-04';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 10.0, 10.0, 10.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 10.0]]);
    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    shipItems($http, $csrf, $do['doId'], $do['version'], 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 4.0]]);

    $list = $http->request('GET', "/api/do?date={$tanggal}&status=preprinted", null, ['X-CSRF-Token' => $csrf]);
    // status filter deliberately mismatched (still draft) — fall back to unfiltered list to find the row.
    $list = $http->request('GET', "/api/do?date={$tanggal}", null, ['X-CSRF-Token' => $csrf]);
    expect($list['status'] === 200, 'list failed: ' . json_encode($list['json']));
    $row = current(array_filter($list['json']['data'], fn ($d) => $d['doId'] === $do['doId']));
    expect($row !== false, 'expected the DO in the list');
    expect((float) $row['totalPlanned'] === 10.0 && (float) $row['totalShipped'] === 4.0 && (float) $row['totalRemaining'] === 6.0,
        'expected list aggregates planned=10/shipped=4/remaining=6, got ' . json_encode($row));
});

runTest('P5-36 full worked scenario: partial->staged shipment reaches shipped, FG stock ends at zero', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-09-05';
    $p = nextProduct(); // stands in for the task's "BIG BANANA CHOCOCHEESE" worked example — not in the real katalog, substituting a real product for the identical numeric scenario (documented here, as Phase 4 did for the same product name).
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 4.0, 4.0, 4.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 4.0]]);

    $do = createDoDraft($http, $csrf, $tanggal, $storeA);
    $preprint = $http->request('POST', "/api/do/{$do['doId']}/preprint", ['expectedVersion' => $do['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey('do36-preprint')));
    expect($preprint['status'] === 200, 'preprint failed: ' . json_encode($preprint['json']));
    $v = $preprint['json']['data']['version'];

    $ship1 = shipItems($http, $csrf, $do['doId'], $v, 'MAIN', [['productId' => $p['product_id'], 'actualQty' => 3.0]]);
    expect($ship1['status'] === 200 && $ship1['json']['data']['doFullyFulfilled'] === false, 'expected first partial ship not fulfilled: ' . json_encode($ship1['json']));
    $dto = getDo($http, $csrf, $do['doId']);
    expect((float) $dto['summary']['totalRemaining'] === 1.0, 'expected remaining 1 after first ship, got ' . json_encode($dto['summary']));

    $ship2 = shipItems($http, $csrf, $do['doId'], $dto['version'], 'PASTRY', [['productId' => $p['product_id'], 'actualQty' => 1.0]]);
    expect($ship2['status'] === 200 && $ship2['json']['data']['doFullyFulfilled'] === true, 'expected second ship completes the DO: ' . json_encode($ship2['json']));

    $final = getDo($http, $csrf, $do['doId']);
    expect($final['status'] === 'shipped', 'expected final DO status shipped, got ' . $final['status']);

    $avail = $http->request('GET', "/api/fg/availability?factoryId={$karangtengahId}&productId={$p['product_id']}", null, ['X-CSRF-Token' => $csrf]);
    expect((float) $avail['json']['data']['available'] === 0.0, 'expected FG available 0 after both shipments, got ' . json_encode($avail['json']));

    $locId = locationIdForFactory($pdo, $karangtengahId);
    $rows = ledgerRows($pdo, $p['product_id'], $locId);
    expect(count($rows) === 2, 'expected exactly 2 shipment_out ledger rows (3, then 1), got ' . count($rows));
    expect((float) $rows[0]['qty_delta'] === -3.0 && (float) $rows[1]['qty_delta'] === -1.0, 'expected deltas -3 then -1, got ' . json_encode($rows));
});

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
