<?php

declare(strict_types=1);

/**
 * "Existing FG Allocation Bridge" (migration 0012's own
 * special_order_fg_allocation table + stock_ledger source_type widening) —
 * ALLOC-01..17, run via api/tests/run-fg-allocation.sh, which stands up a
 * disposable local MariaDB, applies migrations 0001-0012, bootstraps
 * realistic master data, then drives the real /api/special-orders/* and
 * /api/special-order-do/* JSON APIs end to end against a live `php -S`
 * server — same harness shape as SpecialOrderTest.php/
 * FinalPreliveReworkTest.php.
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

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
