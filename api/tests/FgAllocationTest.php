<?php

declare(strict_types=1);

/**
 * "Existing FG Allocation Bridge" (migration 0012's own
 * special_order_fg_allocation table + stock_ledger source_type widening) —
 * ALLOC-01..15, plus ALLOC-GLOBAL-01..12 (the "FINAL BLOCKER FIX"
 * cross-flow reservation pass — Regular PO shipment must never consume
 * FG a special/non-regular order has already reserved, and special
 * dispatch must never trust an allocation as proof physical stock is
 * still there). Run via api/tests/run-fg-allocation.sh, which stands up a
 * disposable local MariaDB, applies migrations 0001-0012, bootstraps
 * realistic master data, then drives the real /api/special-orders/*,
 * /api/special-order-do/*, and /api/do/* JSON APIs end to end against a
 * live `php -S` server — same harness shape as SpecialOrderTest.php/
 * FinalPreliveReworkTest.php/Phase5DoShipmentTest.php.
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8116';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'alloc_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpAlloc
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'alloccookies');
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

function login(HttpAlloc $http, string $username, string $password): string
{
    $r = $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    expect($r['status'] === 200, "login failed for {$username}: " . json_encode($r['json']));
    return $r['json']['data']['csrfToken'];
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

function idemKey(string $tag): array
{
    return ['Idempotency-Key' => 'alloc-test-' . $tag . '-' . bin2hex(random_bytes(6))];
}

/** @return array{divisionId:int,productId:int} */
function productForDivision(PDO $pdo, string $divisionName): array
{
    $divId = (int) $pdo->query("SELECT division_id FROM division WHERE name = " . $pdo->quote($divisionName))->fetchColumn();
    expect($divId > 0, "expected division '{$divisionName}' to be seeded");
    $productId = (int) $pdo->query("SELECT product_id FROM product WHERE division_id = {$divId} AND aktif = 1 LIMIT 1")->fetchColumn();
    expect($productId > 0, "expected at least one active product in division '{$divisionName}'");
    return ['divisionId' => $divId, 'productId' => $productId];
}

/**
 * Seeds a KNOWN General FG stock_balance for one product at one factory,
 * directly via a real (append-only) 'opening_balance' stock_ledger row +
 * matching stock_balance upsert — same shape ShipmentService/DoRepository
 * itself would write, never a raw balance edit (mirrors ORDER-05's own
 * "inserted directly, never through the real pipeline" test-fixture
 * convention for data this suite needs to already exist rather than derive
 * through several unrelated screens).
 */
function seedStockBalance(PDO $pdo, int $productId, int $factoryId, float $qty): int
{
    $locStmt = $pdo->prepare('SELECT location_id FROM location WHERE factory_id = ?');
    $locStmt->execute([$factoryId]);
    $locationId = $locStmt->fetchColumn();
    if ($locationId === false) {
        $factoryName = (string) $pdo->query("SELECT name FROM factory WHERE factory_id = {$factoryId}")->fetchColumn();
        $pdo->prepare('INSERT INTO location (name, factory_id) VALUES (?, ?)')->execute(['GUDANG ' . mb_strtoupper($factoryName), $factoryId]);
        $locationId = (int) $pdo->lastInsertId();
    }
    $locationId = (int) $locationId;
    $ins = $pdo->prepare(
        "INSERT INTO stock_ledger (product_id, location_id, event_type, qty_delta, source_type, source_id, event_date, created_at, created_by, notes)
         VALUES (?, ?, 'opening_balance', ?, 'opening_balance_cutover', NULL, CURDATE(), UTC_TIMESTAMP(), NULL, 'ALLOC test seed')"
    );
    $ins->execute([$productId, $locationId, $qty]);
    $ledgerId = (int) $pdo->lastInsertId();
    $up = $pdo->prepare(
        'INSERT INTO stock_balance (product_id, location_id, qty_on_hand, last_ledger_id, updated_at)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand), last_ledger_id = VALUES(last_ledger_id), updated_at = UTC_TIMESTAMP()'
    );
    $up->execute([$productId, $locationId, $qty, $ledgerId]);
    return $locationId;
}

/** Creates + confirms + sends-to-production one special order, returns [orderId, itemId, version]. */
function createSentOrder(HttpAlloc $http, string $csrf, array $body, string $tag): array
{
    $r = $http->request('POST', '/api/special-orders', $body, array_merge(['X-CSRF-Token' => $csrf], idemKey($tag . 'create')));
    expect($r['status'] === 200, "{$tag}: create failed: " . json_encode($r['json']));
    $order = $r['json']['data'];
    $confirm = $http->request('POST', "/api/special-orders/{$order['orderId']}/confirm", ['expectedVersion' => $order['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey($tag . 'confirm')));
    expect($confirm['status'] === 200, "{$tag}: confirm failed: " . json_encode($confirm['json']));
    $send = $http->request('POST', "/api/special-orders/{$order['orderId']}/send-to-production", ['expectedVersion' => $confirm['json']['data']['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey($tag . 'send')));
    expect($send['status'] === 200, "{$tag}: send-to-production failed: " . json_encode($send['json']));
    return [$order['orderId'], $order['items'][0]['itemId'], $send['json']['data']['version']];
}

function findItemInInbox(HttpAlloc $http, int $itemId): ?array
{
    $inbox = $http->request('GET', '/api/special-orders/production-inbox');
    foreach ($inbox['json']['data']['divisions'] as $div) {
        foreach ($div['items'] as $it) {
            if ((int) $it['itemId'] === $itemId) {
                return $it;
            }
        }
    }
    return null;
}

function findItemInFgEligible(HttpAlloc $http, int $itemId): ?array
{
    $rows = $http->request('GET', '/api/special-orders/fg-eligible')['json']['data'];
    foreach ($rows as $it) {
        if ((int) $it['itemId'] === $itemId) {
            return $it;
        }
    }
    return null;
}

function allocationRow(PDO $pdo, int $itemId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM special_order_fg_allocation WHERE special_order_item_id = ? ORDER BY special_order_fg_allocation_id DESC LIMIT 1');
    $stmt->execute([$itemId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function allocationRowCount(PDO $pdo, int $itemId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM special_order_fg_allocation WHERE special_order_item_id = ?');
    $stmt->execute([$itemId]);
    return (int) $stmt->fetchColumn();
}

function ledgerConsumptionCount(PDO $pdo, int $productId, int $locationId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_ledger WHERE product_id = ? AND location_id = ? AND source_type = 'special_order_fg_allocation'");
    $stmt->execute([$productId, $locationId]);
    return (int) $stmt->fetchColumn();
}

function stockOnHand(PDO $pdo, int $productId, int $locationId): float
{
    $stmt = $pdo->prepare('SELECT qty_on_hand FROM stock_balance WHERE product_id = ? AND location_id = ?');
    $stmt->execute([$productId, $locationId]);
    $v = $stmt->fetchColumn();
    return $v === false ? 0.0 : (float) $v;
}

/** Nth (0-indexed) active product in a division — lets ALLOC-GLOBAL fixtures get a FRESH, never-before-used product id within an already-known division instead of colliding with ALLOC-01..15's own picks. */
function productForDivisionAt(PDO $pdo, string $divisionName, int $offset): array
{
    $divId = (int) $pdo->query("SELECT division_id FROM division WHERE name = " . $pdo->quote($divisionName))->fetchColumn();
    expect($divId > 0, "expected division '{$divisionName}' to be seeded");
    $stmt = $pdo->prepare("SELECT product_id FROM product WHERE division_id = ? AND aktif = 1 ORDER BY product_id LIMIT 1 OFFSET {$offset}");
    $stmt->execute([$divId]);
    $productId = (int) $stmt->fetchColumn();
    expect($productId > 0, "expected an active product at offset {$offset} in division '{$divisionName}'");
    return ['divisionId' => $divId, 'productId' => $productId];
}

/** Seeds po_batch/po_item/po_store_item directly for ONE store's demand (never through the parser) — same convention as Phase5DoShipmentTest.php's own seedStorePo(), needed so a Regular PO DO has a real planned_qty to ship against. */
function seedStorePo(PDO $pdo, string $tanggal, int $factoryId, int $storeId, int $productId, float $poAwal): int
{
    $find = $pdo->prepare('SELECT po_batch_id FROM po_batch WHERE tanggal = ? AND factory_id = ?');
    $find->execute([$tanggal, $factoryId]);
    $batchId = $find->fetchColumn();
    if ($batchId === false) {
        $pdo->prepare('INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())')->execute([$tanggal, $factoryId]);
        $batchId = (int) $pdo->lastInsertId();
    } else {
        $batchId = (int) $batchId;
    }
    $pdo->prepare('INSERT INTO po_item (po_batch_id, product_id, po_awal, po_revisi, pb) VALUES (?, ?, ?, 0, 0) ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal)')
        ->execute([$batchId, $productId, $poAwal]);
    $poItemId = (int) $pdo->query("SELECT po_item_id FROM po_item WHERE po_batch_id = {$batchId} AND product_id = {$productId}")->fetchColumn();
    $pdo->prepare('INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal)')
        ->execute([$poItemId, $storeId, $poAwal]);
    return $batchId;
}

/** Drives the REAL Production API end to end: create draft -> patch actual -> submit (same pattern as Phase5DoShipmentTest.php's own helper). */
function createSubmittedProduction(HttpAlloc $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual): void
{
    seedStorePo($pdo, $tanggal, $factoryId, $storeId, $productId, $poTarget);
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

/** Full chain: submitted Production -> a submitted FG batch with packed=$qty for $productId, leaving physical stock_balance = $qty. Returns the fgBatchId. */
function createSubmittedFgBatch(HttpAlloc $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $qty): int
{
    createSubmittedProduction($http, $csrf, $pdo, $factoryId, $divisionId, $tanggal, $storeId, $productId, $qty, $qty);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $factoryId], array_merge(['X-CSRF-Token' => $csrf], idemKey('fg-create')));
    expect($create['status'] === 200, 'fg create failed: ' . json_encode($create['json']));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $productId, 'fgVerified' => $qty, 'packed' => $qty]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('fg-save')));
    expect($save['status'] === 200, 'fg save failed: ' . json_encode($save['json']));
    $v = $save['json']['data']['version'];
    $submit = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('fg-submit')));
    expect($submit['status'] === 200, 'fg submit failed: ' . json_encode($submit['json']));
    return (int) $batchId;
}

/** Reopens a submitted FG batch, patches ONE product's packed qty (snapshot semantics), and returns the batch's fresh version — does NOT submit. */
function reopenAndCorrectFg(HttpAlloc $http, string $csrf, int $batchId, int $productId, float $fgVerified, float $newPacked, string $tag): int
{
    $get = $http->request('GET', "/api/fg/{$batchId}", null, ['X-CSRF-Token' => $csrf]);
    expect($get['status'] === 200, "{$tag}: fg get failed: " . json_encode($get['json']));
    $reopen = $http->request('POST', "/api/fg/{$batchId}/reopen", ['expectedVersion' => $get['json']['data']['version'], 'reason' => 'ALLOC-GLOBAL test correction'], array_merge(['X-CSRF-Token' => $csrf], idemKey($tag . 'reopen')));
    expect($reopen['status'] === 200, "{$tag}: fg reopen failed: " . json_encode($reopen['json']));
    $patch = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $reopen['json']['data']['version'], 'items' => [['productId' => $productId, 'fgVerified' => $fgVerified, 'packed' => $newPacked]]], array_merge(['X-CSRF-Token' => $csrf], idemKey($tag . 'patch')));
    expect($patch['status'] === 200, "{$tag}: fg patch failed: " . json_encode($patch['json']));
    return (int) $patch['json']['data']['version'];
}

/** Creates a Regular PO Draft DO for tanggal/storeId via the real API (derives its items from po_store_item — seedStorePo() must run first). */
function createRegularDoDraft(HttpAlloc $http, string $csrf, string $tanggal, int $storeId): array
{
    $r = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeId], array_merge(['X-CSRF-Token' => $csrf], idemKey('regdo-create')));
    expect($r['status'] === 200, 'Regular DO create failed: ' . json_encode($r['json']));
    return ['doId' => (int) $r['json']['data']['doId'], 'version' => (int) $r['json']['data']['version']];
}

function regularShipmentPreview(HttpAlloc $http, string $csrf, int $doId, int $productId, float $qty): array
{
    return $http->request('POST', "/api/do/{$doId}/shipment-preview", ['items' => [['productId' => $productId, 'actualQty' => $qty]]], ['X-CSRF-Token' => $csrf]);
}

function regularShip(HttpAlloc $http, string $csrf, int $doId, int $expectedVersion, int $productId, float $qty, string $tag): array
{
    return $http->request('POST', "/api/do/{$doId}/ship", ['expectedVersion' => $expectedVersion, 'shipmentGroup' => 'MAIN', 'items' => [['productId' => $productId, 'actualQty' => $qty]]], array_merge(['X-CSRF-Token' => $csrf], idemKey($tag)));
}

function getRegularDo(HttpAlloc $http, string $csrf, int $doId): array
{
    $r = $http->request('GET', "/api/do/{$doId}", null, ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, 'Regular DO get failed: ' . json_encode($r['json']));
    return $r['json']['data'];
}

$pdo = new PDO(
    "mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4",
    getenv('TEST_RUNTIME_USER') ?: null,
    getenv('TEST_RUNTIME_PASS') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$adminHttp = new HttpAlloc($baseUrl);
$adminCsrf = login($adminHttp, $adminUser, $adminPass);
$adminUserId = (int) $pdo->query("SELECT user_id FROM users WHERE username = " . $pdo->quote($adminUser))->fetchColumn();

createUser($pdo, 'alloc_driver', 'AllocDriverPass123', ['DRIVER']);
$driverHttp = new HttpAlloc($baseUrl);
$driverCsrf = login($driverHttp, 'alloc_driver', 'AllocDriverPass123');

$storeAId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
expect($storeAId > 0, 'expected P2 TEST STORE A seeded');

$karangtengahFactoryId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
$cibadakFactoryId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Cibadak'")->fetchColumn();
expect($karangtengahFactoryId > 0 && $cibadakFactoryId > 0, 'expected both factories seeded');

$rotiBollen = productForDivision($pdo, 'Roti & Bollen');
$pastry = productForDivision($pdo, 'Pastry');
$donatMochiAkb = productForDivision($pdo, 'Donat/Mochi/AKB');
$basic = productForDivision($pdo, 'Basic');
$cookies = productForDivision($pdo, 'Cookies');
$bolu = productForDivision($pdo, 'Bolu');

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

$GLOBALS['po_batch_count_at_start'] = (int) $pdo->query('SELECT COUNT(*) FROM po_batch')->fetchColumn();
$GLOBALS['po_item_count_at_start'] = (int) $pdo->query('SELECT COUNT(*) FROM po_item')->fetchColumn();
$GLOBALS['po_store_item_count_at_start'] = (int) $pdo->query('SELECT COUNT(*) FROM po_store_item')->fetchColumn();

// --- ALLOC-01/02/03 — simple case: order 2, FG 35 -> fully allocatable, need=0, still reaches DO ---

$rotiBollenLocId = seedStockBalance($pdo, $rotiBollen['productId'], $karangtengahFactoryId, 35);

runTest('ALLOC-01 order qty 2 / FG 35 -> allocatable qty is 2 (capped by order, not FG)', function () use ($adminHttp, $adminCsrf, $storeAId, $rotiBollen, $karangtengahFactoryId) {
    [$orderId, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollen['productId'], 'qty' => 2]],
    ], 'alloc01');
    $it = findItemInInbox($adminHttp, $itemId);
    expect($it !== null, 'ALLOC-01: expected item in production-inbox');
    expect(abs($it['fgAvailable'] - 35.0) < 0.01, 'ALLOC-01: expected fgAvailable=35, got ' . $it['fgAvailable']);
    expect(abs($it['maxAllocatable'] - 2.0) < 0.01, 'ALLOC-01: expected maxAllocatable=2 (capped by order qty), got ' . $it['maxAllocatable']);
    expect($it['canAllocateFg'] === true, 'ALLOC-01: expected canAllocateFg=true');
    $GLOBALS['alloc01_orderId'] = $orderId;
    $GLOBALS['alloc01_itemId'] = $itemId;
});

runTest('ALLOC-02 after allocating the full order qty from General FG, production need becomes 0', function () use ($adminHttp, $adminCsrf) {
    $itemId = $GLOBALS['alloc01_itemId'];
    $r = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 2], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc02')));
    expect($r['status'] === 200, 'ALLOC-02: allocate-fg failed: ' . json_encode($r['json']));
    expect(abs($r['json']['data']['productionNeed'] - 0.0) < 0.01, 'ALLOC-02: expected productionNeed=0, got ' . $r['json']['data']['productionNeed']);
    expect(abs($r['json']['data']['allocatedFromGeneralFg'] - 2.0) < 0.01, 'ALLOC-02: expected allocatedFromGeneralFg=2');
    expect($r['json']['data']['status'] === 'Tidak Perlu Produksi', 'ALLOC-02: expected status=Tidak Perlu Produksi, got ' . $r['json']['data']['status']);
});

runTest('ALLOC-03 order may proceed to DO purely from General FG allocation, with ZERO special production', function () use ($adminHttp, $adminCsrf, $karangtengahFactoryId, $storeAId) {
    $orderId = $GLOBALS['alloc01_orderId'];
    $itemId = $GLOBALS['alloc01_itemId'];
    $fg = findItemInFgEligible($adminHttp, $itemId);
    expect($fg !== null, 'ALLOC-03: expected item to appear in FG-eligible inbox despite aktualProduksi=0 (the dead-end bug this feature fixes)');
    expect(abs($fg['dariFgExisting'] - 2.0) < 0.01, 'ALLOC-03: expected dariFgExisting=2, got ' . $fg['dariFgExisting']);
    expect(abs($fg['dariProduksiKhusus'] - 0.0) < 0.01, 'ALLOC-03: expected dariProduksiKhusus=0');
    expect(abs($fg['totalSiapUntukOrder'] - 2.0) < 0.01, 'ALLOC-03: expected totalSiapUntukOrder=2');
    expect($fg['availableForDo'] >= 1.999, 'ALLOC-03: expected availableForDo>=2, got ' . $fg['availableForDo']);

    $do = $adminHttp->request('POST', '/api/special-order-do', [
        'orderId' => $orderId, 'factoryId' => $karangtengahFactoryId, 'deliveryMethod' => 'EXTERNAL_COURIER', 'courierProvider' => 'grab',
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc03do')));
    expect($do['status'] === 200, 'ALLOC-03: expected DO creation to succeed purely from General FG allocation: ' . json_encode($do['json']));
    expect(abs($do['json']['data']['items'][0]['plannedQty'] - 2.0) < 0.01, 'ALLOC-03: expected plannedQty=2 on the new DO');
});

// --- ALLOC-04/05/06/07 — order 40 / FG 35 -> allocate 35, production need 5, mixed-fulfillment dispatch ---

$pastryLocId = seedStockBalance($pdo, $pastry['productId'], $karangtengahFactoryId, 35);

runTest('ALLOC-04 order qty 40 / FG 35 -> allocation 35, production need 5', function () use ($adminHttp, $adminCsrf, $storeAId, $pastry) {
    [$orderId, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'sales_executive', 'customerName' => 'ALLOC-04 Customer',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $pastry['productId'], 'qty' => 40]],
    ], 'alloc04');
    $it = findItemInInbox($adminHttp, $itemId);
    expect($it['normalizedSourceType'] === 'SALES_ORDER', 'ALLOC-04: expected normalizedSourceType=SALES_ORDER (source identity preserved), got ' . $it['normalizedSourceType']);
    expect(abs($it['maxAllocatable'] - 35.0) < 0.01, 'ALLOC-04: expected maxAllocatable=35 (capped by free FG), got ' . $it['maxAllocatable']);

    $alloc = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 35], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc04')));
    expect($alloc['status'] === 200, 'ALLOC-04: allocate-fg 35 failed: ' . json_encode($alloc['json']));
    expect(abs($alloc['json']['data']['productionNeed'] - 5.0) < 0.01, 'ALLOC-04: expected productionNeed=5, got ' . $alloc['json']['data']['productionNeed']);
    expect($alloc['json']['data']['status'] === 'Sudah Dialokasikan', 'ALLOC-04: expected status=Sudah Dialokasikan, got ' . $alloc['json']['data']['status']);

    // Over-cap negative check: order remaining is now exactly 5 (40-35-0) — requesting 6 more must be rejected.
    $overCap = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 6], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc04overcap')));
    expect($overCap['status'] === 409 && $overCap['json']['code'] === 'EXCEEDS_ORDER_REMAINING', 'ALLOC-04: expected 409 EXCEEDS_ORDER_REMAINING for a request beyond the order\'s own remaining need, got ' . json_encode($overCap['json']));

    $GLOBALS['alloc04_orderId'] = $orderId;
    $GLOBALS['alloc04_itemId'] = $itemId;
});

runTest('ALLOC-05 Task per Divisi shows ONLY the qty that still requires production (5, not 40)', function () use ($adminHttp, $pastry) {
    $tasks = $adminHttp->request('GET', '/api/production-tasks?tanggal=2026-09-25&divisionId=' . $pastry['divisionId'])['json']['data']['tasks'];
    $found = null;
    foreach ($tasks as $t) {
        if ((int) ($t['itemId'] ?? 0) === (int) $GLOBALS['alloc04_itemId']) {
            $found = $t;
        }
    }
    expect($found !== null, 'ALLOC-05: expected the ALLOC-04 item in Task per Divisi');
    expect(abs($found['target'] - 5.0) < 0.01, 'ALLOC-05: expected target=5 (40 order - 35 allocated), got ' . $found['target']);
});

runTest('ALLOC-06 allocate-fg NEVER writes a negative stock_ledger row (earmark only, no physical stock-out)', function () use ($pdo, $pastry, $pastryLocId) {
    expect(ledgerConsumptionCount($pdo, $pastry['productId'], $pastryLocId) === 0, 'ALLOC-06: expected ZERO special_order_fg_allocation-sourced stock_ledger rows before any real dispatch');
    expect(abs(stockOnHand($pdo, $pastry['productId'], $pastryLocId) - 35.0) < 0.01, 'ALLOC-06: expected stock_balance UNCHANGED at 35 after allocation alone');
});

runTest('ALLOC-07 mixed-fulfillment dispatch: shipment qty 40 = 35 from General FG + 5 from special production, ONE real ledger deduction', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $karangtengahFactoryId, $storeAId, $pdo, $pastry, $pastryLocId) {
    $orderId = $GLOBALS['alloc04_orderId'];
    $itemId = $GLOBALS['alloc04_itemId'];

    $order = $adminHttp->request('GET', "/api/special-orders/{$orderId}")['json']['data'];
    $actual = $adminHttp->request('POST', "/api/special-orders/{$orderId}/actual", ['expectedVersion' => $order['version'], 'items' => [['itemId' => $itemId, 'aktualProduksi' => 5, 'rejectProduksi' => 0]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc07actual')));
    expect($actual['status'] === 200, 'ALLOC-07: actual failed: ' . json_encode($actual['json']));
    $verify = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => 5], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc07verify')));
    expect($verify['status'] === 200, 'ALLOC-07: verify-fg failed: ' . json_encode($verify['json']));

    $fg = findItemInFgEligible($adminHttp, $itemId);
    expect(abs($fg['dariFgExisting'] - 35.0) < 0.01, 'ALLOC-07: expected dariFgExisting=35 before dispatch');
    expect(abs($fg['dariProduksiKhusus'] - 5.0) < 0.01, 'ALLOC-07: expected dariProduksiKhusus=5');
    expect(abs($fg['totalSiapUntukOrder'] - 40.0) < 0.01, 'ALLOC-07: expected totalSiapUntukOrder=40 (35 existing + 5 produced), got ' . $fg['totalSiapUntukOrder']);

    // ALLOC-04's order is non_toko — dropStoreId (the physical Bakery drop point) is a DO-LEVEL field, required for every non_toko order (toko_khusus derives it automatically from the order's own store_id).
    $do = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $orderId, 'factoryId' => $karangtengahFactoryId, 'deliveryMethod' => 'DRIVER_INTERNAL', 'dropStoreId' => $storeAId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc07do')));
    expect($do['status'] === 200, 'ALLOC-07: DO create failed: ' . json_encode($do['json']));
    expect(abs($do['json']['data']['items'][0]['plannedQty'] - 40.0) < 0.01, 'ALLOC-07: expected plannedQty=40 on the new DO');
    $doId = $do['json']['data']['doId'];

    $claim = $driverHttp->request('POST', "/api/special-order-do/{$doId}/claim", null, array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('alloc07claim')));
    expect($claim['status'] === 200, 'ALLOC-07: claim failed: ' . json_encode($claim['json']));
    $depart = $driverHttp->request('POST', "/api/special-order-do/{$doId}/depart", ['items' => null], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('alloc07depart')));
    expect($depart['status'] === 200, 'ALLOC-07: depart failed: ' . json_encode($depart['json']));

    expect(ledgerConsumptionCount($pdo, $pastry['productId'], $pastryLocId) === 1, 'ALLOC-07: expected EXACTLY ONE special_order_fg_allocation-sourced stock_ledger row (the combined 35 General FG consumption), never one per line/never double-deducted');
    expect(abs(stockOnHand($pdo, $pastry['productId'], $pastryLocId) - 0.0) < 0.01, 'ALLOC-07: expected stock_balance to drop by exactly 35 (35 -> 0), got ' . stockOnHand($pdo, $pastry['productId'], $pastryLocId));

    $row = allocationRow($pdo, $itemId);
    expect(abs((float) $row['consumed_qty'] - 35.0) < 0.01, 'ALLOC-07: expected the allocation row consumed_qty=35 (fully consumed by this one shipment)');
    expect($row['status'] === 'consumed', 'ALLOC-07: expected allocation status=consumed, got ' . $row['status']);
});

// --- ALLOC-08 — idempotency: double-clicking "Alokasikan dari FG" must not duplicate the allocation ---

seedStockBalance($pdo, $basic['productId'], $karangtengahFactoryId, 100);

runTest('ALLOC-08 a repeated allocate-fg call with the SAME Idempotency-Key creates ONLY ONE allocation row', function () use ($adminHttp, $adminCsrf, $storeAId, $basic, $pdo) {
    [, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $basic['productId'], 'qty' => 10]],
    ], 'alloc08');

    $key = ['Idempotency-Key' => 'alloc-test-alloc08-fixed-key'];
    $first = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 7], array_merge(['X-CSRF-Token' => $adminCsrf], $key));
    expect($first['status'] === 200, 'ALLOC-08: first allocate-fg failed: ' . json_encode($first['json']));
    $second = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 7], array_merge(['X-CSRF-Token' => $adminCsrf], $key));
    expect($second['status'] === 200, 'ALLOC-08: replayed allocate-fg failed: ' . json_encode($second['json']));
    expect($second['json']['data'] === $first['json']['data'], 'ALLOC-08: expected the replayed response to be byte-identical to the first (real Idempotency-Key replay, not a re-execution)');

    expect(allocationRowCount($pdo, $itemId) === 1, 'ALLOC-08: expected exactly ONE special_order_fg_allocation row after two identical-key calls, got ' . allocationRowCount($pdo, $itemId));
});

// --- ALLOC-09 — concurrency: physical=10, Order A tries 8, Order B tries 8 simultaneously ---

$donatLocId = seedStockBalance($pdo, $donatMochiAkb['productId'], $karangtengahFactoryId, 10);

runTest('ALLOC-09 two concurrent allocation attempts against the SAME free FG never over-reserve (physical=10, A=8, B=8 -> total never 16)', function () use ($adminHttp, $adminCsrf, $storeAId, $donatMochiAkb, $adminUserId, $pdo, $donatLocId) {
    [, $itemA] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $donatMochiAkb['productId'], 'qty' => 8]],
    ], 'alloc09a');
    [, $itemB] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $donatMochiAkb['productId'], 'qty' => 8]],
    ], 'alloc09b');

    $childScript = __DIR__ . '/_fg_allocate_race_child.php';
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procA = proc_open(['php', $childScript, (string) $itemA, '8', (string) $adminUserId], $descriptors, $pipesA);
    $procB = proc_open(['php', $childScript, (string) $itemB, '8', (string) $adminUserId], $descriptors, $pipesB);

    $outA = stream_get_contents($pipesA[1]); $errA = stream_get_contents($pipesA[2]);
    fclose($pipesA[1]); fclose($pipesA[2]); $codeA = proc_close($procA);
    $outB = stream_get_contents($pipesB[1]); $errB = stream_get_contents($pipesB[2]);
    fclose($pipesB[1]); fclose($pipesB[2]); $codeB = proc_close($procB);

    expect($codeA === 0, "ALLOC-09: child A exited {$codeA}: {$errA}");
    expect($codeB === 0, "ALLOC-09: child B exited {$codeB}: {$errB}");
    $resA = json_decode($outA, true);
    $resB = json_decode($outB, true);
    expect($resA !== null && $resB !== null, 'ALLOC-09: expected valid JSON from both children, got A=' . $outA . ' B=' . $outB);

    $successCount = ($resA['ok'] ? 1 : 0) + ($resB['ok'] ? 1 : 0);
    expect($successCount === 1, "ALLOC-09: expected EXACTLY ONE of the two concurrent 8-unit requests against 10 free FG to succeed, got A.ok=" . json_encode($resA['ok']) . " B.ok=" . json_encode($resB['ok']));
    $failed = $resA['ok'] ? $resB : $resA;
    expect($failed['errorCode'] === 'EXCEEDS_FREE_FG', 'ALLOC-09: expected the losing request to fail with EXCEEDS_FREE_FG, got ' . json_encode($failed));

    $totalAllocated = (float) $pdo->query("SELECT COALESCE(SUM(allocated_qty - consumed_qty - released_qty),0) FROM special_order_fg_allocation WHERE product_id = {$donatMochiAkb['productId']} AND status IN ('active','partially_consumed')")->fetchColumn();
    expect($totalAllocated <= 10.0 + 0.01, "ALLOC-09: expected TOTAL active allocation across both orders to never exceed physical stock 10, got {$totalAllocated}");
    expect(abs(stockOnHand($pdo, $donatMochiAkb['productId'], $donatLocId) - 10.0) < 0.01, 'ALLOC-09: expected stock_balance to remain UNCHANGED at 10 (allocation is an earmark, never a physical stock-out)');
});

// --- ALLOC-10 — a custom/special_catalog item can NEVER consume general FG ---

runTest('ALLOC-10 a custom (special_catalog) item is rejected from General FG allocation', function () use ($adminHttp, $adminCsrf, $storeAId) {
    [, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 1]],
    ], 'alloc10');
    $r = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 1], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc10')));
    expect($r['status'] === 400 && $r['json']['code'] === 'CUSTOM_ITEM_NOT_ALLOCATABLE', 'ALLOC-10: expected 400 CUSTOM_ITEM_NOT_ALLOCATABLE for a special_catalog item, got ' . json_encode($r['json']));
});

// --- ALLOC-11 — wrong-factory FG can never be allocated (Bolu -> Cibadak only) ---

runTest('ALLOC-11 stock seeded at the WRONG factory location can never be allocated to a Cibadak-routed (Bolu) item', function () use ($adminHttp, $adminCsrf, $storeAId, $bolu, $karangtengahFactoryId, $pdo) {
    // Deliberately seed FG for this Bolu product at Karangtengah (the WRONG
    // factory — Bolu always routes to Cibadak) — the item's own true-free-FG
    // calculation must read ONLY its own routed factory's location, so this
    // stock must be entirely invisible to it.
    seedStockBalance($pdo, $bolu['productId'], $karangtengahFactoryId, 50);

    [, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $bolu['productId'], 'qty' => 5]],
    ], 'alloc11');
    $it = findItemInInbox($adminHttp, $itemId);
    expect(abs($it['fgAvailable'] - 0.0) < 0.01, 'ALLOC-11: expected fgAvailable=0 at the item\'s own (Cibadak) factory despite 50 seeded at Karangtengah, got ' . $it['fgAvailable']);

    $r = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 5], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc11')));
    expect($r['status'] === 409 && $r['json']['code'] === 'EXCEEDS_FREE_FG', 'ALLOC-11: expected 409 EXCEEDS_FREE_FG (cross-factory stock invisible), got ' . json_encode($r['json']));
});

// --- ALLOC-12 — cancel BEFORE shipment releases the unused active allocation ---

runTest('ALLOC-12 cancelling an order before shipment releases its unused General FG allocation back to the free pool', function () use ($adminHttp, $adminCsrf, $storeAId, $basic, $pdo, $karangtengahFactoryId) {
    [$orderId, $itemId, $version] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $basic['productId'], 'qty' => 5]],
    ], 'alloc12');

    $alloc = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 5], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc12')));
    expect($alloc['status'] === 200, 'ALLOC-12: allocate-fg failed: ' . json_encode($alloc['json']));

    $order = $adminHttp->request('GET', "/api/special-orders/{$orderId}")['json']['data'];
    $cancel = $adminHttp->request('POST', "/api/special-orders/{$orderId}/cancel", ['expectedVersion' => $order['version'], 'reason' => 'ALLOC-12 test cancel'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc12cancel')));
    expect($cancel['status'] === 200, 'ALLOC-12: cancel failed: ' . json_encode($cancel['json']));

    $row = allocationRow($pdo, $itemId);
    expect($row['status'] === 'released', 'ALLOC-12: expected the allocation row status=released after order cancellation, got ' . $row['status']);
    expect(abs((float) $row['released_qty'] - 5.0) < 0.01, 'ALLOC-12: expected released_qty=5 (the full unused reservation)');
    expect(abs((float) $row['consumed_qty'] - 0.0) < 0.01, 'ALLOC-12: expected consumed_qty=0 (never physically shipped, so no stock_ledger reversal is needed)');

    // A NEW order on the same product+factory can now allocate the released qty again.
    [, $newItemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $basic['productId'], 'qty' => 5]],
    ], 'alloc12b');
    $reAlloc = $adminHttp->request('POST', "/api/special-orders/items/{$newItemId}/allocate-fg", ['qty' => 5], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc12reAlloc')));
    expect($reAlloc['status'] === 200, 'ALLOC-12: expected the released FG to be re-allocatable by a different order, got ' . json_encode($reAlloc['json']));
});

// --- ALLOC-13 — partial shipment: allocated general=35, special production=5, ready=40, shipment A=20, remaining=20 ---

$cookiesLocId = seedStockBalance($pdo, $cookies['productId'], $karangtengahFactoryId, 35);

runTest('ALLOC-13 a partial shipment consumes only the dispatched qty, correctly preserving remaining allocation for a later shipment', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $cookies, $karangtengahFactoryId, $pdo, $cookiesLocId) {
    [$orderId, $itemId, $version] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $cookies['productId'], 'qty' => 40]],
    ], 'alloc13');

    // Negative check folded in: requesting 36 (more than the 35 free FG, but within the 40 order cap) must be rejected.
    $overFree = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 36], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc13overfree')));
    expect($overFree['status'] === 409 && $overFree['json']['code'] === 'EXCEEDS_FREE_FG', 'ALLOC-13: expected 409 EXCEEDS_FREE_FG for a request beyond true free FG, got ' . json_encode($overFree['json']));

    $alloc = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 35], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc13alloc')));
    expect($alloc['status'] === 200, 'ALLOC-13: allocate-fg 35 failed: ' . json_encode($alloc['json']));

    $order = $adminHttp->request('GET', "/api/special-orders/{$orderId}")['json']['data'];
    $actual = $adminHttp->request('POST', "/api/special-orders/{$orderId}/actual", ['expectedVersion' => $order['version'], 'items' => [['itemId' => $itemId, 'aktualProduksi' => 5, 'rejectProduksi' => 0]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc13actual')));
    expect($actual['status'] === 200, 'ALLOC-13: actual failed: ' . json_encode($actual['json']));
    $verify = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => 5], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc13verify')));
    expect($verify['status'] === 200, 'ALLOC-13: verify-fg failed: ' . json_encode($verify['json']));

    $do = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $orderId, 'factoryId' => $karangtengahFactoryId, 'deliveryMethod' => 'DRIVER_INTERNAL'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('alloc13do')));
    expect($do['status'] === 200, 'ALLOC-13: DO create failed: ' . json_encode($do['json']));
    $doId = $do['json']['data']['doId'];
    $doItemId = $do['json']['data']['items'][0]['doItemId'];

    $claim = $driverHttp->request('POST', "/api/special-order-do/{$doId}/claim", null, array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('alloc13claim')));
    expect($claim['status'] === 200, 'ALLOC-13: claim failed: ' . json_encode($claim['json']));

    // Shipment A: partial 20 -- consumption order is General FG FIRST, so this comes entirely from the allocation, none from special production yet.
    $departA = $driverHttp->request('POST', "/api/special-order-do/{$doId}/depart", ['items' => [['doItemId' => $doItemId, 'qty' => 20]]], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('alloc13departA')));
    expect($departA['status'] === 200, 'ALLOC-13: partial depart A failed: ' . json_encode($departA['json']));

    $row = allocationRow($pdo, $itemId);
    expect(abs((float) $row['consumed_qty'] - 20.0) < 0.01, 'ALLOC-13: expected consumed_qty=20 after the 20-unit partial shipment, got ' . $row['consumed_qty']);
    $remainingAfterA = (float) $row['allocated_qty'] - (float) $row['consumed_qty'] - (float) $row['released_qty'];
    expect(abs($remainingAfterA - 15.0) < 0.01, 'ALLOC-13: expected 15 still-active General FG remaining after shipment A, got ' . $remainingAfterA);

    // Shipment B: the remaining 20 -- 15 more from General FG (its headroom), then 5 from special production, mixed in one shipment.
    $departB = $driverHttp->request('POST', "/api/special-order-do/{$doId}/depart", ['items' => null], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('alloc13departB')));
    expect($departB['status'] === 200, 'ALLOC-13: remaining depart B failed: ' . json_encode($departB['json']));

    $rowFinal = allocationRow($pdo, $itemId);
    expect(abs((float) $rowFinal['consumed_qty'] - 35.0) < 0.01, 'ALLOC-13: expected consumed_qty=35 (fully consumed) after both shipments, got ' . $rowFinal['consumed_qty']);
    expect($rowFinal['status'] === 'consumed', 'ALLOC-13: expected final allocation status=consumed');
    expect(abs(stockOnHand($pdo, $cookies['productId'], $cookiesLocId) - 0.0) < 0.01, 'ALLOC-13: expected stock_balance fully consumed (35 -> 0) across both shipments combined');
    expect(ledgerConsumptionCount($pdo, $cookies['productId'], $cookiesLocId) === 2, 'ALLOC-13: expected exactly TWO special_order_fg_allocation ledger rows — one per real dispatch (20, then 15) — never one per do_item or a single merged write across separate physical departures');
});

// --- ALLOC-14 — authorization: a non-ADMIN/PPIC role cannot allocate FG ---

runTest('ALLOC-14 an unauthorized (DRIVER) user cannot allocate General FG', function () use ($driverHttp, $driverCsrf) {
    $itemId = $GLOBALS['alloc01_itemId'];
    $r = $driverHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 1], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('alloc14')));
    expect($r['status'] === 403, "ALLOC-14: expected 403 for a DRIVER trying to allocate FG, got {$r['status']}: " . json_encode($r['json']));
});

// --- ALLOC-15 — Regular PO (po_batch/po_item/po_store_item) is completely unchanged ---

runTest('ALLOC-15 Regular PO remains completely unchanged by the Existing FG Allocation Bridge', function () use ($pdo) {
    $poBatchCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM po_batch')->fetchColumn();
    $poItemCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM po_item')->fetchColumn();
    $poStoreItemCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM po_store_item')->fetchColumn();
    expect($GLOBALS['po_batch_count_at_start'] === $poBatchCountAfter, 'ALLOC-15: expected po_batch row count UNCHANGED');
    expect($GLOBALS['po_item_count_at_start'] === $poItemCountAfter, 'ALLOC-15: expected po_item row count UNCHANGED');
    expect($GLOBALS['po_store_item_count_at_start'] === $poStoreItemCountAfter, 'ALLOC-15: expected po_store_item row count UNCHANGED');
});

// ============================================================================
// ALLOC-GLOBAL-01..12 — "FINAL BLOCKER FIX" cross-flow reservation bridge:
// Regular PO shipment must NEVER be able to consume FG a special/non-
// regular order has already reserved, and special dispatch must NEVER
// trust an allocation as proof physical stock is still there.
// ============================================================================

// --- ALLOC-GLOBAL-01..04 — sequential: allocate 8 of 10, Regular sees only 2 free, ships 2, special owner ships its own 8, physical ends at 0 ---

$g1234 = productForDivisionAt($pdo, 'Roti & Bollen', 1);
$g1234LocId = seedStockBalance($pdo, $g1234['productId'], $karangtengahFactoryId, 10);
$globalTanggal = '2026-09-25';

runTest('ALLOC-GLOBAL-01 Regular Shipment Preview subtracts active special allocation (physical 10, special alloc 8 -> preview shows 2)', function () use ($adminHttp, $adminCsrf, $storeAId, $g1234, $karangtengahFactoryId, $globalTanggal, $pdo) {
    [$orderId, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'cs', 'customerName' => 'ALLOC-GLOBAL CS Customer',
        'orderDate' => '2026-09-22', 'requiredDate' => $globalTanggal,
        'items' => [['itemType' => 'existing_product', 'productId' => $g1234['productId'], 'qty' => 8]],
    ], 'allocglobal01');
    $alloc = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 8], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal01alloc')));
    expect($alloc['status'] === 200, 'ALLOC-GLOBAL-01: allocate-fg 8 failed: ' . json_encode($alloc['json']));

    seedStorePo($pdo, $globalTanggal, $karangtengahFactoryId, $storeAId, $g1234['productId'], 10);
    $do = createRegularDoDraft($adminHttp, $adminCsrf, $globalTanggal, $storeAId);

    $preview = regularShipmentPreview($adminHttp, $adminCsrf, $do['doId'], $g1234['productId'], 2);
    expect($preview['status'] === 200, 'ALLOC-GLOBAL-01: preview failed: ' . json_encode($preview['json']));
    $line = $preview['json']['data']['lines'][0];
    expect(abs($line['fgAvailable'] - 2.0) < 0.01, 'ALLOC-GLOBAL-01: expected Regular FG available=2 (physical 10 - reserved 8), got ' . $line['fgAvailable']);

    // The real DO detail/ship page (pengiriman.php?doId=X) reads fgAvailable
    // straight from GET /api/do/{id} (DoService::getDo()), a SEPARATE code
    // path from ShipmentService::preview() — must show the SAME reserved-
    // aware number, or an operator would see "10 available" on screen and
    // have the server reject their qty.
    $regularDo = getRegularDo($adminHttp, $adminCsrf, $do['doId']);
    $doItemLine = $regularDo['items'][0];
    expect(abs($doItemLine['fgAvailable'] - 2.0) < 0.01, 'ALLOC-GLOBAL-01: expected DO detail/ship page (GET /api/do/{id}) to ALSO show fgAvailable=2, got ' . $doItemLine['fgAvailable']);

    $GLOBALS['g1234_orderId'] = $orderId;
    $GLOBALS['g1234_itemId'] = $itemId;
    $GLOBALS['g1234_doId'] = $do['doId'];
    $GLOBALS['g1234_doVersion'] = $do['version'];
});

runTest('ALLOC-GLOBAL-02 Regular shipment attempt of 3 (exceeding the 2 truly free) is rejected', function () use ($adminHttp, $adminCsrf, $g1234) {
    $r = regularShip($adminHttp, $adminCsrf, $GLOBALS['g1234_doId'], $GLOBALS['g1234_doVersion'], $g1234['productId'], 3, 'allocglobal02');
    expect($r['status'] === 409 && $r['json']['code'] === 'INSUFFICIENT_FG_AVAILABLE', 'ALLOC-GLOBAL-02: expected 409 INSUFFICIENT_FG_AVAILABLE (reserved-FG protection), got ' . json_encode($r['json']));
});

runTest('ALLOC-GLOBAL-03 Regular shipment of 2 (within the truly free amount) succeeds; special allocation stays untouched', function () use ($adminHttp, $adminCsrf, $g1234, $g1234LocId, $pdo) {
    $r = regularShip($adminHttp, $adminCsrf, $GLOBALS['g1234_doId'], $GLOBALS['g1234_doVersion'], $g1234['productId'], 2, 'allocglobal03');
    expect($r['status'] === 200, 'ALLOC-GLOBAL-03: expected the within-free-amount shipment to succeed: ' . json_encode($r['json']));
    expect(abs(stockOnHand($pdo, $g1234['productId'], $g1234LocId) - 8.0) < 0.01, 'ALLOC-GLOBAL-03: expected physical stock 10 -> 8 after Regular ships 2');
    $row = allocationRow($pdo, $GLOBALS['g1234_itemId']);
    expect(abs((float) $row['allocated_qty'] - 8.0) < 0.01 && abs((float) $row['consumed_qty'] - 0.0) < 0.01, 'ALLOC-GLOBAL-03: expected the special allocation to remain fully intact (8 allocated, 0 consumed) — Regular PO never touches it');
    $GLOBALS['g1234_doVersion'] = getRegularDo($adminHttp, $adminCsrf, $GLOBALS['g1234_doId'])['version'];
});

runTest('ALLOC-GLOBAL-04 the special order owner then ships its own reserved 8 -- succeeds, physical ends at exactly 0, never negative', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $karangtengahFactoryId, $g1234, $g1234LocId, $pdo) {
    $orderId = $GLOBALS['g1234_orderId'];
    // ALLOC-GLOBAL-01's order is non_toko (CS) -- dropStoreId is a DO-level field required for every non_toko order.
    $do = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $orderId, 'factoryId' => $karangtengahFactoryId, 'deliveryMethod' => 'DRIVER_INTERNAL', 'dropStoreId' => $storeAId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal04do')));
    expect($do['status'] === 200, 'ALLOC-GLOBAL-04: special DO create failed: ' . json_encode($do['json']));
    $doId = $do['json']['data']['doId'];
    $claim = $driverHttp->request('POST', "/api/special-order-do/{$doId}/claim", null, array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('allocglobal04claim')));
    expect($claim['status'] === 200, 'ALLOC-GLOBAL-04: claim failed: ' . json_encode($claim['json']));
    $depart = $driverHttp->request('POST', "/api/special-order-do/{$doId}/depart", ['items' => null], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('allocglobal04depart')));
    expect($depart['status'] === 200, 'ALLOC-GLOBAL-04: depart failed: ' . json_encode($depart['json']));

    $final = stockOnHand($pdo, $g1234['productId'], $g1234LocId);
    expect(abs($final - 0.0) < 0.01, "ALLOC-GLOBAL-04: expected physical stock to end at exactly 0, got {$final}");
    expect($final >= -0.0001, 'ALLOC-GLOBAL-04: physical stock must never go negative');
});

// --- ALLOC-GLOBAL-05 — REAL concurrency race: physical=10, special allocate(8) vs Regular ship(8) at nearly the same time ---

$g5 = productForDivisionAt($pdo, 'Pastry', 1);
$g5LocId = seedStockBalance($pdo, $g5['productId'], $karangtengahFactoryId, 10);

runTest('ALLOC-GLOBAL-05 real-process race: special allocate(8) vs Regular ship(8) against physical=10 never both succeed', function () use ($adminHttp, $adminCsrf, $storeAId, $g5, $karangtengahFactoryId, $adminUserId, $pdo, $g5LocId) {
    [, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-26',
        'items' => [['itemType' => 'existing_product', 'productId' => $g5['productId'], 'qty' => 8]],
    ], 'allocglobal05');

    seedStorePo($pdo, '2026-09-26', $karangtengahFactoryId, $storeAId, $g5['productId'], 8);
    $do = createRegularDoDraft($adminHttp, $adminCsrf, '2026-09-26', $storeAId);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procAlloc = proc_open(['php', __DIR__ . '/_fg_allocate_race_child.php', (string) $itemId, '8', (string) $adminUserId], $descriptors, $pipesAlloc);
    $procShip = proc_open(['php', __DIR__ . '/_regular_ship_race_child.php', (string) $do['doId'], (string) $do['version'], (string) $g5['productId'], '8', (string) $adminUserId, 'MAIN'], $descriptors, $pipesShip);

    $outAlloc = stream_get_contents($pipesAlloc[1]); $errAlloc = stream_get_contents($pipesAlloc[2]);
    fclose($pipesAlloc[1]); fclose($pipesAlloc[2]); $codeAlloc = proc_close($procAlloc);
    $outShip = stream_get_contents($pipesShip[1]); $errShip = stream_get_contents($pipesShip[2]);
    fclose($pipesShip[1]); fclose($pipesShip[2]); $codeShip = proc_close($procShip);

    expect($codeAlloc === 0, "ALLOC-GLOBAL-05: allocate child exited {$codeAlloc}: {$errAlloc}");
    expect($codeShip === 0, "ALLOC-GLOBAL-05: ship child exited {$codeShip}: {$errShip}");
    $resAlloc = json_decode($outAlloc, true);
    $resShip = json_decode($outShip, true);
    expect($resAlloc !== null && $resShip !== null, 'ALLOC-GLOBAL-05: expected valid JSON from both children, got alloc=' . $outAlloc . ' ship=' . $outShip);

    $successCount = ($resAlloc['ok'] ? 1 : 0) + ($resShip['ok'] ? 1 : 0);
    expect($successCount === 1, 'ALLOC-GLOBAL-05: expected EXACTLY ONE of {special allocate 8, Regular ship 8} to succeed against physical=10, got alloc.ok=' . json_encode($resAlloc['ok']) . ' ship.ok=' . json_encode($resShip['ok']));

    $activeAllocated = (float) $pdo->query("SELECT COALESCE(SUM(allocated_qty - consumed_qty - released_qty),0) FROM special_order_fg_allocation WHERE product_id = {$g5['productId']} AND status IN ('active','partially_consumed')")->fetchColumn();
    $physical = stockOnHand($pdo, $g5['productId'], $g5LocId);
    expect($activeAllocated + (10.0 - $physical) <= 10.0 + 0.01, "ALLOC-GLOBAL-05: committed total (allocated {$activeAllocated} + shipped " . (10.0 - $physical) . ") must never exceed physical 10");
    expect($physical - $activeAllocated >= -0.01, 'ALLOC-GLOBAL-05: true free FG (physical - active allocation) must never go negative');
});

// --- ALLOC-GLOBAL-06 — two special allocations + one Regular shipment racing concurrently cannot over-commit physical stock ---

$g6 = productForDivisionAt($pdo, 'Donat/Mochi/AKB', 1);
$g6LocId = seedStockBalance($pdo, $g6['productId'], $karangtengahFactoryId, 10);

runTest('ALLOC-GLOBAL-06 two concurrent special allocations (5 each) plus a concurrent Regular ship(5) never over-commit physical=10', function () use ($adminHttp, $adminCsrf, $storeAId, $g6, $karangtengahFactoryId, $adminUserId, $pdo, $g6LocId) {
    [, $itemX] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-27',
        'items' => [['itemType' => 'existing_product', 'productId' => $g6['productId'], 'qty' => 5]],
    ], 'allocglobal06x');
    [, $itemY] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-27',
        'items' => [['itemType' => 'existing_product', 'productId' => $g6['productId'], 'qty' => 5]],
    ], 'allocglobal06y');
    seedStorePo($pdo, '2026-09-27', $karangtengahFactoryId, $storeAId, $g6['productId'], 5);
    $do = createRegularDoDraft($adminHttp, $adminCsrf, '2026-09-27', $storeAId);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procX = proc_open(['php', __DIR__ . '/_fg_allocate_race_child.php', (string) $itemX, '5', (string) $adminUserId], $descriptors, $pipesX);
    $procY = proc_open(['php', __DIR__ . '/_fg_allocate_race_child.php', (string) $itemY, '5', (string) $adminUserId], $descriptors, $pipesY);
    $procShip = proc_open(['php', __DIR__ . '/_regular_ship_race_child.php', (string) $do['doId'], (string) $do['version'], (string) $g6['productId'], '5', (string) $adminUserId, 'MAIN'], $descriptors, $pipesShip);

    $outX = stream_get_contents($pipesX[1]); fclose($pipesX[1]); fclose($pipesX[2]); $codeX = proc_close($procX);
    $outY = stream_get_contents($pipesY[1]); fclose($pipesY[1]); fclose($pipesY[2]); $codeY = proc_close($procY);
    $outShip = stream_get_contents($pipesShip[1]); fclose($pipesShip[1]); fclose($pipesShip[2]); $codeShip = proc_close($procShip);
    expect($codeX === 0 && $codeY === 0 && $codeShip === 0, "ALLOC-GLOBAL-06: a child process failed (codes X={$codeX} Y={$codeY} ship={$codeShip})");

    $resX = json_decode($outX, true); $resY = json_decode($outY, true); $resShip = json_decode($outShip, true);
    $activeAllocated = (float) $pdo->query("SELECT COALESCE(SUM(allocated_qty - consumed_qty - released_qty),0) FROM special_order_fg_allocation WHERE product_id = {$g6['productId']} AND status IN ('active','partially_consumed')")->fetchColumn();
    $physical = stockOnHand($pdo, $g6['productId'], $g6LocId);
    $shippedQty = 10.0 - $physical;
    expect($activeAllocated + $shippedQty <= 10.0 + 0.01, "ALLOC-GLOBAL-06: total committed (allocated {$activeAllocated} + shipped {$shippedQty}) must never exceed physical 10 -- results X=" . json_encode($resX) . " Y=" . json_encode($resY) . " ship=" . json_encode($resShip));
    expect($physical - $activeAllocated >= -0.01, 'ALLOC-GLOBAL-06: true free FG must never go negative after the 3-way race');
});

// --- ALLOC-GLOBAL-07 — Regular PO with NO special reservations behaves exactly as before ---

$g7 = productForDivisionAt($pdo, 'Basic', 1);
$g7LocId = seedStockBalance($pdo, $g7['productId'], $karangtengahFactoryId, 10);

runTest('ALLOC-GLOBAL-07 Regular PO with zero special reservations ships its full physical stock exactly as before', function () use ($adminHttp, $adminCsrf, $storeAId, $g7, $karangtengahFactoryId, $g7LocId, $pdo) {
    seedStorePo($pdo, '2026-09-28', $karangtengahFactoryId, $storeAId, $g7['productId'], 10);
    $do = createRegularDoDraft($adminHttp, $adminCsrf, '2026-09-28', $storeAId);
    $preview = regularShipmentPreview($adminHttp, $adminCsrf, $do['doId'], $g7['productId'], 10);
    expect(abs($preview['json']['data']['lines'][0]['fgAvailable'] - 10.0) < 0.01, 'ALLOC-GLOBAL-07: expected FG available=10 with no reservations, got ' . $preview['json']['data']['lines'][0]['fgAvailable']);
    $ship = regularShip($adminHttp, $adminCsrf, $do['doId'], $do['version'], $g7['productId'], 10, 'allocglobal07');
    expect($ship['status'] === 200, 'ALLOC-GLOBAL-07: expected the full-stock shipment to succeed unchanged: ' . json_encode($ship['json']));
    expect(abs(stockOnHand($pdo, $g7['productId'], $g7LocId) - 0.0) < 0.01, 'ALLOC-GLOBAL-07: expected physical stock 10 -> 0');
});

// --- ALLOC-GLOBAL-08 — pure General FG special order (order 2, allocation 2, special production 0) ships successfully, fgVerifiedQty stays valid at 0 ---

$g8 = productForDivisionAt($pdo, 'Cookies', 1);
seedStockBalance($pdo, $g8['productId'], $karangtengahFactoryId, 10);

runTest('ALLOC-GLOBAL-08 pure General-FG order (2/2, zero special production) ships successfully and fgVerifiedQty remains valid at 0', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $g8, $karangtengahFactoryId) {
    [$orderId, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-29',
        'items' => [['itemType' => 'existing_product', 'productId' => $g8['productId'], 'qty' => 2]],
    ], 'allocglobal08');
    $alloc = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 2], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal08alloc')));
    expect($alloc['status'] === 200, 'ALLOC-GLOBAL-08: allocate-fg failed: ' . json_encode($alloc['json']));

    $do = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $orderId, 'factoryId' => $karangtengahFactoryId, 'deliveryMethod' => 'DRIVER_INTERNAL'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal08do')));
    expect($do['status'] === 200, 'ALLOC-GLOBAL-08: DO create failed: ' . json_encode($do['json']));
    $doId = $do['json']['data']['doId'];
    $claim = $driverHttp->request('POST', "/api/special-order-do/{$doId}/claim", null, array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('allocglobal08claim')));
    expect($claim['status'] === 200, 'ALLOC-GLOBAL-08: claim failed: ' . json_encode($claim['json']));
    $depart = $driverHttp->request('POST', "/api/special-order-do/{$doId}/depart", ['items' => null], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('allocglobal08depart')));
    expect($depart['status'] === 200, 'ALLOC-GLOBAL-08: expected shipment of a pure-General-FG order to succeed: ' . json_encode($depart['json']));

    $item = $adminHttp->request('GET', "/api/special-orders/{$orderId}")['json']['data']['items'][0];
    expect(abs($item['fgVerifiedQty'] - 0.0) < 0.01, 'ALLOC-GLOBAL-08: expected fgVerifiedQty to remain validly 0 (nothing shipped from special production) after a fully General-FG-fulfilled shipment');
});

// --- ALLOC-GLOBAL-09/10/11 — FG Verified guard tracks shippedFromSpecial ONLY, reusing ALLOC-07's own mixed-fulfillment fixture (order 40, general shipped 35, special shipped 5) ---

runTest('ALLOC-GLOBAL-09 after mixed fulfillment (general 35 + special 5), fgVerifiedQty minimum is 5, NOT 40', function () use ($adminHttp, $adminCsrf) {
    $itemId = $GLOBALS['alloc04_itemId'];
    // Attempting to drop fgVerifiedQty to 0 (which would be required if the guard still used total-shipped=40) must fail specifically because of the 5-unit special-production floor, not a 40-unit one.
    $r = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => 0], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal09')));
    expect($r['status'] === 400 && $r['json']['code'] === 'FG_BELOW_SHIPPED', 'ALLOC-GLOBAL-09: expected 400 FG_BELOW_SHIPPED, got ' . json_encode($r['json']));
    expect(str_contains((string) $r['json']['message'], '5'), 'ALLOC-GLOBAL-09: expected the error message to cite the 5-unit special-production floor, got ' . json_encode($r['json']['message']));
});

runTest('ALLOC-GLOBAL-10 reducing fgVerifiedQty below shippedFromSpecial (4 < 5) fails', function () use ($adminHttp, $adminCsrf) {
    $itemId = $GLOBALS['alloc04_itemId'];
    $r = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => 4], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal10')));
    expect($r['status'] === 400 && $r['json']['code'] === 'FG_BELOW_SHIPPED', 'ALLOC-GLOBAL-10: expected 400 FG_BELOW_SHIPPED for 4 < shippedFromSpecial 5, got ' . json_encode($r['json']));
});

runTest('ALLOC-GLOBAL-11 reducing fgVerifiedQty to EXACTLY shippedFromSpecial (5) succeeds', function () use ($adminHttp, $adminCsrf) {
    $itemId = $GLOBALS['alloc04_itemId'];
    $r = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => 5], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal11')));
    expect($r['status'] === 200, 'ALLOC-GLOBAL-11: expected reducing to exactly the special-production floor (5) to succeed: ' . json_encode($r['json']));
});

// --- ALLOC-GLOBAL-12 — stock_balance can never go negative anywhere touched by this suite ---

runTest('ALLOC-GLOBAL-12 stock_balance never goes negative for any product this suite touched', function () use ($pdo) {
    $negative = (int) $pdo->query('SELECT COUNT(*) FROM stock_balance WHERE qty_on_hand < 0')->fetchColumn();
    expect($negative === 0, "ALLOC-GLOBAL-12: expected ZERO stock_balance rows with negative qty_on_hand, found {$negative}");
});

// ============================================================================
// ALLOC-GLOBAL-13..20 — "FINAL FG RESERVATION SAFETY BLOCKER": a downward
// Regular FG batch resubmission (FgService::submit() with delta<0) must
// never drop physical stock below what an active special-order
// allocation reserves.
// ============================================================================

runTest('ALLOC-GLOBAL-13 a downward FG correction that stays AT/ABOVE the active reservation succeeds (10 -> 8, reserved 8)', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $storeAId) {
    $product = productForDivisionAt($pdo, 'Roti & Bollen', 2);
    $batchId = createSubmittedFgBatch($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $product['divisionId'], '2026-09-30', $storeAId, $product['productId'], 10);
    $locationId = (int) $pdo->query("SELECT location_id FROM location WHERE factory_id = {$karangtengahFactoryId}")->fetchColumn();
    expect(abs(stockOnHand($pdo, $product['productId'], $locationId) - 10.0) < 0.01, 'ALLOC-GLOBAL-13: expected physical=10 after initial FG submit');

    [, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-30',
        'items' => [['itemType' => 'existing_product', 'productId' => $product['productId'], 'qty' => 8]],
    ], 'allocglobal13');
    $alloc = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 8], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal13alloc')));
    expect($alloc['status'] === 200, 'ALLOC-GLOBAL-13: allocate-fg 8 failed: ' . json_encode($alloc['json']));

    $version = reopenAndCorrectFg($adminHttp, $adminCsrf, $batchId, $product['productId'], 10, 8, 'allocglobal13');
    $submit = $adminHttp->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $version], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal13submit')));
    expect($submit['status'] === 200, 'ALLOC-GLOBAL-13: expected the -2 correction (10->8, exactly at the 8-unit reservation) to succeed: ' . json_encode($submit['json']));
    expect(abs(stockOnHand($pdo, $product['productId'], $locationId) - 8.0) < 0.01, 'ALLOC-GLOBAL-13: expected physical=8 after the correction');
});

runTest('ALLOC-GLOBAL-14 a downward FG correction that would drop BELOW the active reservation is rejected (10 -> 7, reserved 8)', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $storeAId) {
    $product = productForDivisionAt($pdo, 'Pastry', 2);
    $batchId = createSubmittedFgBatch($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $product['divisionId'], '2026-09-29', $storeAId, $product['productId'], 10);
    $locationId = (int) $pdo->query("SELECT location_id FROM location WHERE factory_id = {$karangtengahFactoryId}")->fetchColumn();

    [, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-29',
        'items' => [['itemType' => 'existing_product', 'productId' => $product['productId'], 'qty' => 8]],
    ], 'allocglobal14');
    $alloc = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 8], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal14alloc')));
    expect($alloc['status'] === 200, 'ALLOC-GLOBAL-14: allocate-fg 8 failed: ' . json_encode($alloc['json']));

    $version = reopenAndCorrectFg($adminHttp, $adminCsrf, $batchId, $product['productId'], 10, 7, 'allocglobal14');
    $submit = $adminHttp->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $version], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal14submit')));
    expect($submit['status'] === 409 && $submit['json']['code'] === 'FG_CORRECTION_BELOW_RESERVED', 'ALLOC-GLOBAL-14: expected 409 FG_CORRECTION_BELOW_RESERVED for a -3 correction (10->7) against an 8-unit reservation, got ' . json_encode($submit['json']));
    expect(abs(stockOnHand($pdo, $product['productId'], $locationId) - 10.0) < 0.01, 'ALLOC-GLOBAL-14: expected physical to remain UNCHANGED at 10 (no partial write on a blocked submit)');
});

runTest('ALLOC-GLOBAL-15 a multi-item FG batch with ONE invalid negative correction fails ENTIRELY — no partial write', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $storeAId) {
    $productA = productForDivisionAt($pdo, 'Donat/Mochi/AKB', 2);
    $productB = productForDivisionAt($pdo, 'Basic', 2);
    $tanggal = '2026-10-01';
    createSubmittedProduction($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $productA['divisionId'], $tanggal, $storeAId, $productA['productId'], 10, 10);
    createSubmittedProduction($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $productB['divisionId'], $tanggal, $storeAId, $productB['productId'], 10, 10);
    $create = $adminHttp->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahFactoryId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal15create')));
    expect($create['status'] === 200, 'ALLOC-GLOBAL-15: fg create failed: ' . json_encode($create['json']));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $adminHttp->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [
        ['productId' => $productA['productId'], 'fgVerified' => 10, 'packed' => 10],
        ['productId' => $productB['productId'], 'fgVerified' => 10, 'packed' => 10],
    ]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal15save')));
    expect($save['status'] === 200, 'ALLOC-GLOBAL-15: fg save failed: ' . json_encode($save['json']));
    $v = $save['json']['data']['version'];
    $submit = $adminHttp->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal15submit')));
    expect($submit['status'] === 200, 'ALLOC-GLOBAL-15: initial fg submit failed: ' . json_encode($submit['json']));

    // Reserve 8 of Product B only -- Product A has no reservation at all.
    [, $itemBId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => $tanggal,
        'items' => [['itemType' => 'existing_product', 'productId' => $productB['productId'], 'qty' => 8]],
    ], 'allocglobal15b');
    $allocB = $adminHttp->request('POST', "/api/special-orders/items/{$itemBId}/allocate-fg", ['qty' => 8], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal15allocb')));
    expect($allocB['status'] === 200, 'ALLOC-GLOBAL-15: allocate-fg on B failed: ' . json_encode($allocB['json']));

    // Reopen the SAME batch, correct A down by 1 (valid, no reservation) and B down by 5 (INVALID -- would drop B to 5 < reserved 8).
    $get = $adminHttp->request('GET', "/api/fg/{$batchId}", null, ['X-CSRF-Token' => $adminCsrf]);
    $reopen = $adminHttp->request('POST', "/api/fg/{$batchId}/reopen", ['expectedVersion' => $get['json']['data']['version'], 'reason' => 'ALLOC-GLOBAL-15 test'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal15reopen')));
    expect($reopen['status'] === 200, 'ALLOC-GLOBAL-15: reopen failed: ' . json_encode($reopen['json']));
    $patch = $adminHttp->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $reopen['json']['data']['version'], 'items' => [
        ['productId' => $productA['productId'], 'fgVerified' => 9, 'packed' => 9],
        ['productId' => $productB['productId'], 'fgVerified' => 5, 'packed' => 5],
    ]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal15patch')));
    expect($patch['status'] === 200, 'ALLOC-GLOBAL-15: patch failed: ' . json_encode($patch['json']));

    $locationId = (int) $pdo->query("SELECT location_id FROM location WHERE factory_id = {$karangtengahFactoryId}")->fetchColumn();
    $ledgerCountABefore = (int) $pdo->query("SELECT COUNT(*) FROM stock_ledger WHERE product_id = {$productA['productId']} AND location_id = {$locationId}")->fetchColumn();
    $ledgerCountBBefore = (int) $pdo->query("SELECT COUNT(*) FROM stock_ledger WHERE product_id = {$productB['productId']} AND location_id = {$locationId}")->fetchColumn();

    $finalSubmit = $adminHttp->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $patch['json']['data']['version']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal15finalsubmit')));
    expect($finalSubmit['status'] === 409 && $finalSubmit['json']['code'] === 'FG_CORRECTION_BELOW_RESERVED', 'ALLOC-GLOBAL-15: expected the WHOLE batch submit to fail because of Product B alone, got ' . json_encode($finalSubmit['json']));

    $ledgerCountAAfter = (int) $pdo->query("SELECT COUNT(*) FROM stock_ledger WHERE product_id = {$productA['productId']} AND location_id = {$locationId}")->fetchColumn();
    $ledgerCountBAfter = (int) $pdo->query("SELECT COUNT(*) FROM stock_ledger WHERE product_id = {$productB['productId']} AND location_id = {$locationId}")->fetchColumn();
    expect($ledgerCountAAfter === $ledgerCountABefore, 'ALLOC-GLOBAL-15: expected ZERO new ledger rows for Product A (the VALID line) -- the whole batch must fail atomically, never partially');
    expect($ledgerCountBAfter === $ledgerCountBBefore, 'ALLOC-GLOBAL-15: expected ZERO new ledger rows for Product B (the INVALID line) either');
    expect(abs(stockOnHand($pdo, $productA['productId'], $locationId) - 10.0) < 0.01, 'ALLOC-GLOBAL-15: expected Product A physical to remain 10 (unposted)');
    expect(abs(stockOnHand($pdo, $productB['productId'], $locationId) - 10.0) < 0.01, 'ALLOC-GLOBAL-15: expected Product B physical to remain 10 (unposted)');
});

runTest('ALLOC-GLOBAL-16 a POSITIVE FG correction works normally even with an active reservation (10 -> 15, reserved 8)', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $storeAId) {
    $product = productForDivisionAt($pdo, 'Cookies', 2);
    $tanggal = '2026-10-02';
    // Production actual is 15 (headroom for the later +5 correction) but only 10 is initially packed/submitted -- FG verified/packed can never exceed the production snapshot.
    createSubmittedProduction($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $product['divisionId'], $tanggal, $storeAId, $product['productId'], 15, 15);
    $create = $adminHttp->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahFactoryId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal16create')));
    expect($create['status'] === 200, 'ALLOC-GLOBAL-16: fg create failed: ' . json_encode($create['json']));
    $batchId = $create['json']['data']['fgBatchId'];
    $save = $adminHttp->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $create['json']['data']['version'], 'items' => [['productId' => $product['productId'], 'fgVerified' => 10, 'packed' => 10]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal16save')));
    expect($save['status'] === 200, 'ALLOC-GLOBAL-16: fg save failed: ' . json_encode($save['json']));
    $initialSubmit = $adminHttp->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $save['json']['data']['version']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal16initsubmit')));
    expect($initialSubmit['status'] === 200, 'ALLOC-GLOBAL-16: initial fg submit failed: ' . json_encode($initialSubmit['json']));
    $locationId = (int) $pdo->query("SELECT location_id FROM location WHERE factory_id = {$karangtengahFactoryId}")->fetchColumn();

    [, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-10-02',
        'items' => [['itemType' => 'existing_product', 'productId' => $product['productId'], 'qty' => 8]],
    ], 'allocglobal16');
    $alloc = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 8], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal16alloc')));
    expect($alloc['status'] === 200, 'ALLOC-GLOBAL-16: allocate-fg 8 failed: ' . json_encode($alloc['json']));

    $version = reopenAndCorrectFg($adminHttp, $adminCsrf, $batchId, $product['productId'], 15, 15, 'allocglobal16');
    $submit = $adminHttp->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $version], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal16submit')));
    expect($submit['status'] === 200, 'ALLOC-GLOBAL-16: expected a +5 correction (10->15) to succeed regardless of the reservation: ' . json_encode($submit['json']));
    expect(abs(stockOnHand($pdo, $product['productId'], $locationId) - 15.0) < 0.01, 'ALLOC-GLOBAL-16: expected physical=15');
});

runTest('ALLOC-GLOBAL-17 a zero-delta FG resubmit posts nothing and leaves physical unchanged', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $storeAId) {
    $product = productForDivisionAt($pdo, 'Bolu', 1);
    $factoryId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Cibadak'")->fetchColumn();
    $batchId = createSubmittedFgBatch($adminHttp, $adminCsrf, $pdo, $factoryId, $product['divisionId'], '2026-10-03', $storeAId, $product['productId'], 10);
    $locationId = (int) $pdo->query("SELECT location_id FROM location WHERE factory_id = {$factoryId}")->fetchColumn();
    $ledgerCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM stock_ledger WHERE product_id = {$product['productId']} AND location_id = {$locationId}")->fetchColumn();

    $version = reopenAndCorrectFg($adminHttp, $adminCsrf, $batchId, $product['productId'], 10, 10, 'allocglobal17');
    $submit = $adminHttp->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $version], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal17submit')));
    expect($submit['status'] === 200, 'ALLOC-GLOBAL-17: expected a same-value resubmit to succeed: ' . json_encode($submit['json']));
    expect($submit['json']['data']['submitPostings'] === [], 'ALLOC-GLOBAL-17: expected ZERO postings for a zero delta, got ' . json_encode($submit['json']['data']['submitPostings']));

    $ledgerCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM stock_ledger WHERE product_id = {$product['productId']} AND location_id = {$locationId}")->fetchColumn();
    expect($ledgerCountAfter === $ledgerCountBefore, 'ALLOC-GLOBAL-17: expected NO new ledger row for a zero-delta resubmit');
    expect(abs(stockOnHand($pdo, $product['productId'], $locationId) - 10.0) < 0.01, 'ALLOC-GLOBAL-17: expected physical to remain 10');
});

// --- ALLOC-GLOBAL-18 -- REAL concurrency race: FG downward correction (-6) vs special allocate(8) against physical=10 ---

runTest('ALLOC-GLOBAL-18 real-process race: FG correction -6 vs special allocate(8) against physical=10 never both commit (physical never falls below active reservation)', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $storeAId, $adminUserId) {
    $product = productForDivisionAt($pdo, 'Roti & Bollen', 3);
    $batchId = createSubmittedFgBatch($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $product['divisionId'], '2026-10-04', $storeAId, $product['productId'], 10);
    $locationId = (int) $pdo->query("SELECT location_id FROM location WHERE factory_id = {$karangtengahFactoryId}")->fetchColumn();

    [, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-10-04',
        'items' => [['itemType' => 'existing_product', 'productId' => $product['productId'], 'qty' => 8]],
    ], 'allocglobal18');

    // Reopen + patch the correction (packed 10 -> 4, pending delta -6) but do NOT submit yet -- the submit itself is what races against the allocation.
    $version = reopenAndCorrectFg($adminHttp, $adminCsrf, $batchId, $product['productId'], 4, 4, 'allocglobal18');

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procFg = proc_open(['php', __DIR__ . '/_fg_correction_race_child.php', (string) $batchId, (string) $version, (string) $adminUserId], $descriptors, $pipesFg);
    $procAlloc = proc_open(['php', __DIR__ . '/_fg_allocate_race_child.php', (string) $itemId, '8', (string) $adminUserId], $descriptors, $pipesAlloc);

    $outFg = stream_get_contents($pipesFg[1]); $errFg = stream_get_contents($pipesFg[2]);
    fclose($pipesFg[1]); fclose($pipesFg[2]); $codeFg = proc_close($procFg);
    $outAlloc = stream_get_contents($pipesAlloc[1]); $errAlloc = stream_get_contents($pipesAlloc[2]);
    fclose($pipesAlloc[1]); fclose($pipesAlloc[2]); $codeAlloc = proc_close($procAlloc);

    expect($codeFg === 0, "ALLOC-GLOBAL-18: fg-correction child exited {$codeFg}: {$errFg}");
    expect($codeAlloc === 0, "ALLOC-GLOBAL-18: allocate child exited {$codeAlloc}: {$errAlloc}");
    $resFg = json_decode($outFg, true);
    $resAlloc = json_decode($outAlloc, true);
    expect($resFg !== null && $resAlloc !== null, 'ALLOC-GLOBAL-18: expected valid JSON from both children, got fg=' . $outFg . ' alloc=' . $outAlloc);

    $successCount = ($resFg['ok'] ? 1 : 0) + ($resAlloc['ok'] ? 1 : 0);
    expect($successCount === 1, 'ALLOC-GLOBAL-18: expected EXACTLY ONE of {FG correction -6, special allocate 8} to succeed against physical=10, got fg.ok=' . json_encode($resFg['ok']) . ' alloc.ok=' . json_encode($resAlloc['ok']));

    $physical = stockOnHand($pdo, $product['productId'], $locationId);
    $activeReserved = (float) $pdo->query("SELECT COALESCE(SUM(allocated_qty - consumed_qty - released_qty),0) FROM special_order_fg_allocation WHERE product_id = {$product['productId']} AND status IN ('active','partially_consumed')")->fetchColumn();
    expect($physical >= -0.0001, "ALLOC-GLOBAL-18: physical must never go negative, got {$physical}");
    expect($physical - $activeReserved >= -0.01, "ALLOC-GLOBAL-18: physical ({$physical}) must never fall below active reservation ({$activeReserved})");
});

// --- ALLOC-GLOBAL-19 -- REAL 3-way concurrency race: FG correction, special allocation, and a Regular shipment all racing the same physical stock ---

runTest('ALLOC-GLOBAL-19 real 3-way race (FG correction -6, special allocate 8, Regular ship 8) never lets physical fall below active reservation or below zero', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $storeAId, $adminUserId) {
    $product = productForDivisionAt($pdo, 'Pastry', 3);
    $batchId = createSubmittedFgBatch($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $product['divisionId'], '2026-10-05', $storeAId, $product['productId'], 10);
    $locationId = (int) $pdo->query("SELECT location_id FROM location WHERE factory_id = {$karangtengahFactoryId}")->fetchColumn();

    [, $itemId] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-10-05',
        'items' => [['itemType' => 'existing_product', 'productId' => $product['productId'], 'qty' => 8]],
    ], 'allocglobal19');
    $version = reopenAndCorrectFg($adminHttp, $adminCsrf, $batchId, $product['productId'], 4, 4, 'allocglobal19');

    seedStorePo($pdo, '2026-10-05', $karangtengahFactoryId, $storeAId, $product['productId'], 8);
    $do = createRegularDoDraft($adminHttp, $adminCsrf, '2026-10-05', $storeAId);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procFg = proc_open(['php', __DIR__ . '/_fg_correction_race_child.php', (string) $batchId, (string) $version, (string) $adminUserId], $descriptors, $pipesFg);
    $procAlloc = proc_open(['php', __DIR__ . '/_fg_allocate_race_child.php', (string) $itemId, '8', (string) $adminUserId], $descriptors, $pipesAlloc);
    $procShip = proc_open(['php', __DIR__ . '/_regular_ship_race_child.php', (string) $do['doId'], (string) $do['version'], (string) $product['productId'], '8', (string) $adminUserId, 'MAIN'], $descriptors, $pipesShip);

    $outFg = stream_get_contents($pipesFg[1]); fclose($pipesFg[1]); fclose($pipesFg[2]); $codeFg = proc_close($procFg);
    $outAlloc = stream_get_contents($pipesAlloc[1]); fclose($pipesAlloc[1]); fclose($pipesAlloc[2]); $codeAlloc = proc_close($procAlloc);
    $outShip = stream_get_contents($pipesShip[1]); fclose($pipesShip[1]); fclose($pipesShip[2]); $codeShip = proc_close($procShip);
    expect($codeFg === 0 && $codeAlloc === 0 && $codeShip === 0, "ALLOC-GLOBAL-19: a child process failed (codes fg={$codeFg} alloc={$codeAlloc} ship={$codeShip})");

    $resFg = json_decode($outFg, true); $resAlloc = json_decode($outAlloc, true); $resShip = json_decode($outShip, true);
    $physical = stockOnHand($pdo, $product['productId'], $locationId);
    $activeReserved = (float) $pdo->query("SELECT COALESCE(SUM(allocated_qty - consumed_qty - released_qty),0) FROM special_order_fg_allocation WHERE product_id = {$product['productId']} AND status IN ('active','partially_consumed')")->fetchColumn();
    expect($physical >= -0.0001, "ALLOC-GLOBAL-19: physical must never go negative, got {$physical} -- results fg=" . json_encode($resFg) . " alloc=" . json_encode($resAlloc) . " ship=" . json_encode($resShip));
    expect($physical - $activeReserved >= -0.01, "ALLOC-GLOBAL-19: physical ({$physical}) must never fall below active reservation ({$activeReserved}) -- results fg=" . json_encode($resFg) . " alloc=" . json_encode($resAlloc) . " ship=" . json_encode($resShip));
});

// --- ALLOC-GLOBAL-20 -- Regular FG Phase 4 behavior (positive posting, zero-delta idempotency) remains unaffected when no reservation exists ---

runTest('ALLOC-GLOBAL-20 Regular FG submit with no active reservation anywhere behaves exactly as before (unaffected by this safety guard)', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $storeAId) {
    $product = productForDivisionAt($pdo, 'Basic', 3);
    $batchId = createSubmittedFgBatch($adminHttp, $adminCsrf, $pdo, $karangtengahFactoryId, $product['divisionId'], '2026-10-06', $storeAId, $product['productId'], 10);
    $locationId = (int) $pdo->query("SELECT location_id FROM location WHERE factory_id = {$karangtengahFactoryId}")->fetchColumn();

    $version = reopenAndCorrectFg($adminHttp, $adminCsrf, $batchId, $product['productId'], 3, 3, 'allocglobal20');
    $submit = $adminHttp->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $version], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('allocglobal20submit')));
    expect($submit['status'] === 200, 'ALLOC-GLOBAL-20: expected a downward correction with NO reservation to succeed exactly as before this safety guard existed: ' . json_encode($submit['json']));
    expect(abs(stockOnHand($pdo, $product['productId'], $locationId) - 3.0) < 0.01, 'ALLOC-GLOBAL-20: expected physical=3');
});

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
