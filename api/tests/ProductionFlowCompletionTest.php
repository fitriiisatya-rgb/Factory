<?php

declare(strict_types=1);

/**
 * Migration 0012 — Production Flow Completion integration suite
 * (FLOW-01..42). Run via api/tests/run-production-flow-completion.sh,
 * which stands up a disposable local MariaDB, applies migrations
 * 0001-0012, bootstraps realistic master data, then drives the real
 * /api/production/*, /api/special-orders/*, /api/special-order-do/* JSON
 * APIs end to end against a live `php -S` server.
 *
 * Covers: the Ceklis Produksi notes-field bugfix (FLOW-01/02), Extra
 * Packaging (FLOW-11..16), the special-order Production->FG bridge
 * (FLOW-17..24), and source-specific DO (FLOW-25..42). FLOW-06..10
 * (client-side autocomplete UX) and FLOW-43..47 (full regression) are
 * NOT tests in this file — autocomplete is covered by the real-browser
 * Playwright pass, and full regression is this orchestrator's own final
 * cascade into run-production-task.sh, same convention as every prior
 * phase's own suite (see SpecialOrderTest.php's own docblock on
 * ORDER-18).
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8112';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'flow_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpFlow
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'flowcookies');
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
}

function expect(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

function numEq(mixed $actual, float $expected, float $eps = 0.001): bool
{
    return is_numeric($actual) && abs((float) $actual - $expected) < $eps;
}

function login(HttpFlow $http, string $username, string $password): string
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
    return ['Idempotency-Key' => 'flow-test-' . $tag . '-' . bin2hex(random_bytes(6))];
}

$pdo = new PDO(
    "mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4",
    getenv('TEST_RUNTIME_USER') ?: null,
    getenv('TEST_RUNTIME_PASS') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$adminHttp = new HttpFlow($baseUrl);
$adminCsrf = login($adminHttp, $adminUser, $adminPass);

createUser($pdo, 'flow_driver_unauth', 'FlowDriverPass123', ['DRIVER']);
$driverHttp = new HttpFlow($baseUrl);
$driverCsrf = login($driverHttp, 'flow_driver_unauth', 'FlowDriverPass123');

$storeAId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
expect($storeAId > 0, 'expected P2 TEST STORE A seeded');

$rotiBollenDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
$rotiBollenProductId = (int) $pdo->query("SELECT product_id FROM product WHERE division_id = {$rotiBollenDivId} AND aktif = 1 LIMIT 1")->fetchColumn();
expect($rotiBollenProductId > 0, 'expected at least one active Roti & Bollen product');

$karangtengahFactoryId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
expect($karangtengahFactoryId > 0, 'expected Karangtengah factory seeded');

// PO Reguler fixture for FLOW-01/02 (Ceklis Produksi bugfix regression) —
// same direct-SQL pattern as ProductionTaskTest.php's own fixture: gives
// this run a real target so liveTarget/notes are non-trivial to check.
$flowTanggal = '2026-09-22';
$pdo->exec("INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES ('{$flowTanggal}', {$karangtengahFactoryId}, 1, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE version = version");
$poBatchId = (int) $pdo->query("SELECT po_batch_id FROM po_batch WHERE tanggal = '{$flowTanggal}' AND factory_id = {$karangtengahFactoryId}")->fetchColumn();
$pdo->exec("INSERT INTO po_item (po_batch_id, product_id, po_awal, po_revisi, pb) VALUES ({$poBatchId}, {$rotiBollenProductId}, 50, 0, 0)
            ON DUPLICATE KEY UPDATE po_awal = 50, po_revisi = 0");

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

/** Creates + confirms + sends-to-production one special order with ONE existing-product item, returns [orderId, itemId]. */
function createSentOrder(HttpFlow $http, string $csrf, array $body, string $tag): array
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

// --- FLOW-01/02 (Ceklis Produksi notes-field bugfix) ------------------------

runTest('FLOW-01 the Ceklis Produksi page itself renders without the old $it[\'target\']/$it[\'keterangan\'] key-mismatch warnings (the actual template bugfix)', function () use ($adminHttp, $karangtengahFactoryId, $flowTanggal) {
    $page = $adminHttp->request('GET', "/_ui-preview/?page=produksi&tanggal={$flowTanggal}&factoryId={$karangtengahFactoryId}");
    expect($page['status'] === 200, 'FLOW-01: expected the Ceklis Produksi page to render 200: ' . substr($page['body'], 0, 300));
    expect(!str_contains($page['body'], 'Undefined array key'), 'FLOW-01: expected NO "Undefined array key" PHP warning (the old $it[\'target\']/$it[\'keterangan\'] mismatch) on the page');
});

runTest('FLOW-02 GET /api/production/{id} exposes liveTarget + notes, and PATCH .../notes round-trips (the exact DTO/wire keys produksi.php now reads/writes)', function () use ($adminHttp, $adminCsrf, $rotiBollenDivId, $flowTanggal, $karangtengahFactoryId) {
    $create = $adminHttp->request('POST', '/api/production', ['tanggal' => $flowTanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow02create')));
    expect($create['status'] === 200, 'FLOW-02: expected create 200: ' . json_encode($create['json']));
    $run = $create['json']['data'];
    expect(array_key_exists('items', $run) && $run['items'] !== [], 'FLOW-02: expected at least one item on the run (from the FLOW-01/02 PO fixture)');
    $item = $run['items'][0];
    expect(array_key_exists('liveTarget', $item), 'FLOW-02: expected the DTO to expose "liveTarget" (the key produksi.php now reads, not "target")');
    expect(array_key_exists('notes', $item), 'FLOW-02: expected the DTO to expose "notes" (the key produksi.php now reads/writes, not "keterangan")');
    expect(numEq($item['liveTarget'], 50.0), 'FLOW-02: expected liveTarget=50 from the PO fixture, got ' . json_encode($item['liveTarget']));

    $patch = $adminHttp->request('PATCH', "/api/production/{$run['productionRunId']}", [
        'expectedVersion' => $run['version'],
        'items' => [['productId' => $item['productId'], 'actualQty' => 0, 'rejectQty' => 0, 'notes' => 'FLOW-02 catatan uji']],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow02patch')));
    expect($patch['status'] === 200, 'FLOW-02: expected patch 200: ' . json_encode($patch['json']));
    $patchedItem = $patch['json']['data']['items'][0];
    expect($patchedItem['notes'] === 'FLOW-02 catatan uji', 'FLOW-02: expected the notes to round-trip via the wire field "notes" (the actual server-side JS bugfix — collectItems() used to send "keterangan"), got ' . json_encode($patchedItem['notes']));

    $page = $adminHttp->request('GET', "/_ui-preview/?page=produksi&tanggal={$flowTanggal}&factoryId={$karangtengahFactoryId}&runId={$run['productionRunId']}");
    expect(str_contains($page['body'], 'FLOW-02 catatan uji'), 'FLOW-02: expected the saved notes text to actually appear on the rendered Ceklis Produksi page');
});

// --- FLOW-11..16 (Extra Packaging + money formatting) -----------------------

runTest('FLOW-11 Extra Packaging is added ONCE to the subtotal, never multiplied by qty', function () use ($adminHttp, $adminCsrf, $storeAId, $rotiBollenProductId) {
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 2, 'unitPrice' => 10000, 'charge' => 2000, 'extraPackaging' => 5000]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow11')));
    expect($r['status'] === 200, 'FLOW-11: expected 200: ' . json_encode($r['json']));
    $item = $r['json']['data']['items'][0];
    expect(numEq($item['extraPackaging'], 5000.0), 'FLOW-11: expected extraPackaging=5000, got ' . json_encode($item['extraPackaging']));
    // 2*10000 + 2000 + 5000 = 27000 (NOT 2*(10000+2500)=25000 or extraPackaging*qty)
    expect(numEq($item['subtotal'], 27000.0), 'FLOW-11: expected subtotal=27000 (qty*price+charge+extraPackaging, packaging added once), got ' . json_encode($item['subtotal']));
});

runTest('FLOW-12 a negative Extra Packaging is rejected', function () use ($adminHttp, $adminCsrf, $storeAId, $rotiBollenProductId) {
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 1, 'extraPackaging' => -100]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow12')));
    expect($r['status'] === 400, 'FLOW-12: expected 400 for negative extraPackaging, got ' . $r['status']);
    expect(($r['json']['code'] ?? null) === 'INVALID_EXTRA_PACKAGING', 'FLOW-12: expected code=INVALID_EXTRA_PACKAGING, got ' . json_encode($r['json']));
});

runTest('FLOW-13 Extra Packaging persists through GET order detail (draft/reopen round-trip)', function () use ($adminHttp, $adminCsrf, $storeAId, $rotiBollenProductId) {
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 1, 'extraPackaging' => 3000]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow13')));
    $orderId = $r['json']['data']['orderId'];
    $get = $adminHttp->request('GET', "/api/special-orders/{$orderId}");
    expect(numEq($get['json']['data']['items'][0]['extraPackaging'], 3000.0), 'FLOW-13: expected extraPackaging=3000 to persist on re-read, got ' . json_encode($get['json']['data']['items'][0]['extraPackaging']));
});

runTest('FLOW-14 Extra Packaging also works on a special_catalog (custom) item', function () use ($adminHttp, $adminCsrf) {
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'umum', 'customerName' => 'FLOW-14 Customer',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 1, 'unitPrice' => 50000, 'charge' => 0, 'extraPackaging' => 7500]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow14')));
    expect($r['status'] === 200, 'FLOW-14: expected 200: ' . json_encode($r['json']));
    $item = $r['json']['data']['items'][0];
    expect(numEq($item['extraPackaging'], 7500.0), 'FLOW-14: expected extraPackaging=7500 on a custom item, got ' . json_encode($item['extraPackaging']));
    expect(numEq($item['subtotal'], 57500.0), 'FLOW-14: expected subtotal=57500, got ' . json_encode($item['subtotal']));
});

runTest('FLOW-16 the detail page renders money fields Rp-formatted with a dot thousands separator', function () use ($adminHttp, $adminCsrf, $storeAId, $rotiBollenProductId) {
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 1, 'unitPrice' => 46000]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow16')));
    $orderId = $r['json']['data']['orderId'];
    $page = $adminHttp->request('GET', "/_ui-preview/?page=pesanan-khusus-toko-detail&id={$orderId}");
    expect($page['status'] === 200, 'FLOW-16: expected detail page 200');
    expect(str_contains($page['body'], 'Rp46.000'), 'FLOW-16: expected "Rp46.000" (Rp + dot thousands separator) in the rendered detail page');
});

// --- FLOW-17..24 (Production -> FG bridge) ----------------------------------

$flowOrderId = null;
$flowItemId = null;
runTest('FLOW-17/24 verify-fg is blocked before the order is sent to production', function () use ($adminHttp, $adminCsrf, $storeAId, $rotiBollenProductId, &$flowOrderId, &$flowItemId) {
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 10]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow17')));
    $flowOrderId = $r['json']['data']['orderId'];
    $flowItemId = $r['json']['data']['items'][0]['itemId'];
    $verify = $adminHttp->request('POST', "/api/special-orders/items/{$flowItemId}/verify-fg", ['fgVerifiedQty' => 1], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow17verify')));
    expect($verify['status'] === 400, 'FLOW-24: expected 400 before send-to-production, got ' . $verify['status']);
    expect(($verify['json']['code'] ?? null) === 'INVALID_STATUS', 'FLOW-24: expected code=INVALID_STATUS, got ' . json_encode($verify['json']));
});

runTest('FLOW-18/19/20/22 verify-fg happy path: availableToVerify = aktual - fgVerified, reject never counted, source preserved', function () use ($adminHttp, $adminCsrf, $flowOrderId, $flowItemId) {
    $order = $adminHttp->request('GET', "/api/special-orders/{$flowOrderId}");
    $confirm = $adminHttp->request('POST', "/api/special-orders/{$flowOrderId}/confirm", ['expectedVersion' => $order['json']['data']['version']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow18confirm')));
    $send = $adminHttp->request('POST', "/api/special-orders/{$flowOrderId}/send-to-production", ['expectedVersion' => $confirm['json']['data']['version']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow18send')));
    expect($send['status'] === 200, 'FLOW-18: expected send-to-production 200: ' . json_encode($send['json']));

    // Aktual Produksi=7, Reject=1 (out of qty=10).
    $actual = $adminHttp->request('POST', "/api/special-orders/{$flowOrderId}/actual", [
        'expectedVersion' => $send['json']['data']['version'],
        'items' => [['itemId' => $flowItemId, 'aktualProduksi' => 7, 'rejectProduksi' => 1]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow18actual')));
    expect($actual['status'] === 200, 'FLOW-18: expected actual 200: ' . json_encode($actual['json']));

    $eligible = $adminHttp->request('GET', '/api/special-orders/fg-eligible');
    $row = null;
    foreach ($eligible['json']['data'] as $it) {
        if ($it['itemId'] === $flowItemId) { $row = $it; break; }
    }
    expect($row !== null, 'FLOW-19: expected the item with aktualProduksi>0 to appear in fg-eligible');
    expect(numEq($row['aktualProduksi'], 7.0), 'FLOW-19: expected aktualProduksi=7, got ' . json_encode($row['aktualProduksi']));
    expect(numEq($row['availableToVerify'], 7.0), 'FLOW-20: expected availableToVerify=7 (reject=1 never subtracted from it), got ' . json_encode($row['availableToVerify']));
    expect($row['sourceType'] === 'toko_khusus', 'FLOW-22: expected sourceType=toko_khusus preserved');

    $verify = $adminHttp->request('POST', "/api/special-orders/items/{$flowItemId}/verify-fg", ['fgVerifiedQty' => 5], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow18verify')));
    expect($verify['status'] === 200, 'FLOW-18: expected verify-fg 200: ' . json_encode($verify['json']));
    $verifiedItem = null;
    foreach ($verify['json']['data']['items'] as $it) {
        if ($it['itemId'] === $flowItemId) { $verifiedItem = $it; break; }
    }
    expect(numEq($verifiedItem['fgVerifiedQty'], 5.0), 'FLOW-18: expected fgVerifiedQty=5, got ' . json_encode($verifiedItem['fgVerifiedQty']));
    expect(numEq($verifiedItem['availableToVerify'], 2.0), 'FLOW-18: expected availableToVerify=2 (7-5), got ' . json_encode($verifiedItem['availableToVerify']));
});

runTest('FLOW-21 re-verifying with the SAME value is idempotent (snapshot semantics, never additive)', function () use ($adminHttp, $adminCsrf, $flowItemId) {
    $verify1 = $adminHttp->request('POST', "/api/special-orders/items/{$flowItemId}/verify-fg", ['fgVerifiedQty' => 5], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow21a')));
    $verify2 = $adminHttp->request('POST', "/api/special-orders/items/{$flowItemId}/verify-fg", ['fgVerifiedQty' => 5], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow21b')));
    foreach ([$verify1, $verify2] as $v) {
        foreach ($v['json']['data']['items'] as $it) {
            if ($it['itemId'] === $flowItemId) {
                expect(numEq($it['fgVerifiedQty'], 5.0), 'FLOW-21: expected fgVerifiedQty to stay 5 (never 10) after repeating the same verify call, got ' . json_encode($it['fgVerifiedQty']));
            }
        }
    }
});

runTest('FLOW-17b verify-fg rejects a qty greater than Aktual Produksi', function () use ($adminHttp, $adminCsrf, $flowItemId) {
    $verify = $adminHttp->request('POST', "/api/special-orders/items/{$flowItemId}/verify-fg", ['fgVerifiedQty' => 999], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow17b')));
    expect($verify['status'] === 400, 'FLOW-17b: expected 400 for fgVerifiedQty > aktualProduksi, got ' . $verify['status']);
    expect(($verify['json']['code'] ?? null) === 'INVALID_FG_QTY', 'FLOW-17b: expected code=INVALID_FG_QTY, got ' . json_encode($verify['json']));
});

runTest('FLOW-23 a custom (special_catalog) item flows through fg-eligible/verify-fg identically to an existing-product item', function () use ($adminHttp, $adminCsrf) {
    [$orderId, $itemId, $version] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'cs', 'customerName' => 'FLOW-23 Customer',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 4]],
    ], 'flow23');
    $actual = $adminHttp->request('POST', "/api/special-orders/{$orderId}/actual", [
        'expectedVersion' => $version,
        'items' => [['itemId' => $itemId, 'aktualProduksi' => 4, 'rejectProduksi' => 0]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow23actual')));
    expect($actual['status'] === 200, 'FLOW-23: expected actual 200: ' . json_encode($actual['json']));
    $verify = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => 4], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow23verify')));
    expect($verify['status'] === 200, 'FLOW-23: expected verify-fg 200 for a custom item: ' . json_encode($verify['json']));
    $GLOBALS['flow23OrderId'] = $orderId;
});

// --- FLOW-25..42 (source-specific DO — reworked API: factory-scoped
// create, delivery_method, DO-level claim/depart/courier-handover, real
// shipment writes, multi-DO). Deep coverage of the new safety model
// (multi-DO partial fulfillment, driver claim/depart, external courier
// handover, FG-below-shipped guard, mutual exclusion) lives in the
// dedicated SpecialFulfillmentTest.php (FUL-*), run by this same
// orchestrator's own cascade — these FLOW-* tests only re-confirm the
// basic create/cancel/authorization contract still holds. ------------------

runTest('FLOW-25 creating a DO with zero FG-verified items is rejected', function () use ($adminHttp, $adminCsrf, $storeAId, $rotiBollenProductId, $karangtengahFactoryId) {
    [$orderId, ,] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 1]],
    ], 'flow25');
    $r = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $orderId, 'factoryId' => $karangtengahFactoryId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow25do')));
    expect($r['status'] === 400, 'FLOW-25: expected 400 with no FG-verified items, got ' . $r['status']);
    expect(($r['json']['code'] ?? null) === 'NO_FG_VERIFIED_DEMAND', 'FLOW-25: expected code=NO_FG_VERIFIED_DEMAND, got ' . json_encode($r['json']));
});

$flowDoId = null;
$flowDoVersion = null;
runTest('FLOW-26/28 creating a DO after verify-fg succeeds with a DOK- number, delivery_method defaults DRIVER_INTERNAL, and plannedQty = fgVerifiedQty snapshot', function () use ($adminHttp, $adminCsrf, $flowOrderId, $karangtengahFactoryId, &$flowDoId, &$flowDoVersion) {
    $r = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $flowOrderId, 'factoryId' => $karangtengahFactoryId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow26do')));
    expect($r['status'] === 200, 'FLOW-26: expected 200: ' . json_encode($r['json']));
    $do = $r['json']['data'];
    expect(str_starts_with($do['docNo'], 'DOK-'), 'FLOW-26: expected a DOK- doc number, got ' . $do['docNo']);
    expect($do['deliveryMethod'] === 'DRIVER_INTERNAL', 'FLOW-26: expected deliveryMethod to default to DRIVER_INTERNAL, got ' . $do['deliveryMethod']);
    expect($do['status'] === 'open', 'FLOW-26: expected a freshly created DO to be status=open, got ' . $do['status']);
    expect(count($do['items']) === 1, 'FLOW-28: expected exactly 1 DO item');
    expect(numEq($do['items'][0]['plannedQty'], 5.0), 'FLOW-28: expected plannedQty=5 (the fgVerifiedQty snapshot), got ' . json_encode($do['items'][0]['plannedQty']));
    $flowDoId = $do['doId'];
    $flowDoVersion = $do['version'];
});

runTest('FLOW-27 creating a DO again for the SAME order with nothing newly available is rejected (no double-allocation of the same FG)', function () use ($adminHttp, $adminCsrf, $flowOrderId, $karangtengahFactoryId) {
    $r = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $flowOrderId, 'factoryId' => $karangtengahFactoryId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow27do')));
    expect($r['status'] === 400, 'FLOW-27: expected 400 (the 5 already-verified units are already allocated to the first DO), got ' . $r['status'] . ': ' . json_encode($r['json']));
    expect(($r['json']['code'] ?? null) === 'NO_FG_VERIFIED_DEMAND', 'FLOW-27: expected code=NO_FG_VERIFIED_DEMAND, got ' . json_encode($r['json']));
});

runTest('FLOW-32 a separate special order (Pesanan Non-Toko) gets its OWN separate DO, never merged with the toko_khusus one', function () use ($adminHttp, $adminCsrf, $flowDoId, $karangtengahFactoryId, $storeAId) {
    $orderId = $GLOBALS['flow23OrderId'];
    $r = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $orderId, 'factoryId' => $karangtengahFactoryId, 'dropStoreId' => $storeAId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow32do')));
    expect($r['status'] === 200, 'FLOW-32: expected 200: ' . json_encode($r['json']));
    expect($r['json']['data']['doId'] !== $flowDoId, 'FLOW-32: expected a DIFFERENT doId from the toko_khusus order\'s DO — different demand sources must never share one DO');
    expect($r['json']['data']['sourceType'] === 'non_toko', 'FLOW-32: expected sourceType=non_toko on this DO');
});

runTest('FLOW-29 confirming departure creates a REAL shipment and transitions open -> shipped', function () use ($adminHttp, $adminCsrf, $flowDoId) {
    $claim = $adminHttp->request('POST', "/api/special-order-do/{$flowDoId}/claim", null, array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow29claim')));
    expect($claim['status'] === 200, 'FLOW-29: expected claim 200: ' . json_encode($claim['json']));
    $r = $adminHttp->request('POST', "/api/special-order-do/{$flowDoId}/depart", ['items' => null], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow29depart')));
    expect($r['status'] === 200, 'FLOW-29: expected depart 200: ' . json_encode($r['json']));
    expect($r['json']['data']['status'] === 'shipped', 'FLOW-29: expected status=shipped, got ' . $r['json']['data']['status']);
    expect(isset($r['json']['data']['shipmentId']) && $r['json']['data']['shipmentId'] > 0, 'FLOW-29: expected a real shipmentId to be returned');
    expect(numEq($r['json']['data']['items'][0]['shippedQty'], 5.0), 'FLOW-29: expected shippedQty=5 on the DO item, got ' . json_encode($r['json']['data']['items'][0]['shippedQty']));
});

runTest('FLOW-30 a shipped DO cannot be cancelled', function () use ($adminHttp, $adminCsrf, $flowDoId) {
    $get = $adminHttp->request('GET', "/api/special-order-do/{$flowDoId}");
    $r = $adminHttp->request('POST', "/api/special-order-do/{$flowDoId}/cancel", ['expectedVersion' => $get['json']['data']['version'], 'reason' => 'test'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow30')));
    expect($r['status'] === 400, 'FLOW-30: expected 400, got ' . $r['status']);
    expect(($r['json']['code'] ?? null) === 'CANNOT_CANCEL_SHIPPED', 'FLOW-30: expected code=CANNOT_CANCEL_SHIPPED, got ' . json_encode($r['json']));
});

runTest('FLOW-31 cancelling a DO requires a non-empty reason', function () use ($adminHttp, $adminCsrf) {
    $orderId = $GLOBALS['flow23OrderId'];
    $do = $adminHttp->request('GET', '/api/special-order-do?sourceType=non_toko');
    $doId = null;
    foreach ($do['json']['data'] as $d) {
        if ($d['orderId'] === $orderId) { $doId = $d['doId']; break; }
    }
    expect($doId !== null, 'FLOW-31: expected to find the non_toko DO from FLOW-32');
    $detail = $adminHttp->request('GET', "/api/special-order-do/{$doId}");
    $r = $adminHttp->request('POST', "/api/special-order-do/{$doId}/cancel", ['expectedVersion' => $detail['json']['data']['version'], 'reason' => ''], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('flow31')));
    expect($r['status'] === 400, 'FLOW-31: expected 400 for an empty reason, got ' . $r['status']);
    expect(($r['json']['code'] ?? null) === 'REASON_REQUIRED', 'FLOW-31: expected code=REASON_REQUIRED, got ' . json_encode($r['json']));
});

runTest('FLOW-33/34 confirmDeparture creates a real shipment row AND leaves stock_ledger untouched (special-order FG stays order-specific)', function () use ($pdo) {
    $shipmentCount = (int) $pdo->query("SELECT COUNT(*) FROM shipment WHERE source_type = 'special_order_do'")->fetchColumn();
    expect($shipmentCount > 0, 'FLOW-33: expected at least one REAL shipment row from special_order_do dispatch (the reworked CRITICAL DISPATCH RULE), got ' . $shipmentCount);
    $ledgerCount = (int) $pdo->query("SELECT COUNT(*) FROM stock_ledger WHERE source_type NOT IN ('production_run','shipment_item','stock_adjustment','stock_transfer','opening_balance_cutover','historical_replay','reversal')")->fetchColumn();
    expect($ledgerCount === 0, 'FLOW-34: expected stock_ledger source_type ENUM untouched by special-order FG (no special-order source values ever written), got ' . $ledgerCount);
});

runTest('FLOW-36 an unauthorized role cannot create/cancel a special_order_do', function () use ($driverHttp, $driverCsrf, $flowOrderId, $karangtengahFactoryId) {
    $r = $driverHttp->request('POST', '/api/special-order-do', ['orderId' => $flowOrderId, 'factoryId' => $karangtengahFactoryId], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('flow36')));
    expect($r['status'] === 403, 'FLOW-36: expected 403 for DRIVER creating a special_order_do, got ' . $r['status']);
});

runTest('FLOW-37 a special_order_do mutation without a valid CSRF token is rejected', function () use ($baseUrl, $adminHttp, $flowOrderId) {
    $ch = curl_init($baseUrl . '/api/special-order-do');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $adminHttp->cookieJarPath(),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['orderId' => $flowOrderId]),
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    expect($status === 403, "FLOW-37: expected 403 for a mutation with no X-CSRF-Token, got {$status}");
});

runTest('FLOW-40 the dormant invoice/invoice_item tables stay completely untouched', function () use ($pdo) {
    $invoiceCount = (int) $pdo->query('SELECT COUNT(*) FROM invoice')->fetchColumn();
    expect($invoiceCount === 0, 'FLOW-40: expected zero rows in the dormant invoice table (no Phase 6 invoice calculation logic built in this phase), got ' . $invoiceCount);
});

runTest('FLOW-41 DO doc numbers are sequential and unique within the same month', function () use ($pdo) {
    $docNos = $pdo->query("SELECT doc_no FROM special_order_do ORDER BY special_order_do_id")->fetchAll(PDO::FETCH_COLUMN);
    expect(count($docNos) === count(array_unique($docNos)), 'FLOW-41: expected all special_order_do.doc_no values to be unique, got ' . json_encode($docNos));
});

runTest('FLOW-42 GET a non-existent special_order_do returns 404', function () use ($adminHttp) {
    $r = $adminHttp->request('GET', '/api/special-order-do/999999999');
    expect($r['status'] === 404, 'FLOW-42: expected 404, got ' . $r['status']);
});

// =============================================================================
// FUL-* — Special / Non-Regular Fulfillment Completion (Driver Internal +
// External Courier rework). Covers the "MANDATORY SOURCE AUDIT" safety
// invariants the task itself calls out: every physical dispatch creates a
// real shipment, actual_ship_qty is always populated, shipped FG can never
// be reduced, partial orders can get a second DO, one FG unit can never be
// consumed twice, an External Courier DO never reaches the Driver Portal,
// a Bakery drop point never becomes the billing owner, and Regular PO
// stays untouched throughout.
// =============================================================================

runTest('FUL-13 FG Verified cannot be reduced below already-shipped quantity', function () use ($adminHttp, $adminCsrf, $flowItemId) {
    // flowItemId was shipped 5 (FLOW-29's departure) then re-verified to
    // 10 by earlier tests — attempting to drop it back to 3 must fail.
    $r = $adminHttp->request('POST', "/api/special-orders/items/{$flowItemId}/verify-fg", ['fgVerifiedQty' => 3], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('ful13')));
    expect($r['status'] === 400, 'FUL-13: expected 400, got ' . $r['status']);
    expect(($r['json']['code'] ?? null) === 'FG_BELOW_SHIPPED', 'FUL-13: expected code=FG_BELOW_SHIPPED, got ' . json_encode($r['json']));
});

// --- FUL-16/17/18 (multi-DO, partial fulfillment) ---------------------------

$fulMultiOrderId = null;
$fulMultiItemId = null;
runTest('FUL-16/17 an order may have MULTIPLE DOs — a first DO does not block a second one for newly-verified FG', function () use ($adminHttp, $adminCsrf, $storeAId, $rotiBollenProductId, $karangtengahFactoryId, &$fulMultiOrderId, &$fulMultiItemId) {
    [$orderId, $itemId, $version] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 10]],
    ], 'fulmulti');
    $fulMultiOrderId = $orderId;
    $fulMultiItemId = $itemId;
    $actual = $adminHttp->request('POST', "/api/special-orders/{$orderId}/actual", ['expectedVersion' => $version, 'items' => [['itemId' => $itemId, 'aktualProduksi' => 10, 'rejectProduksi' => 0]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fulmultiactual')));
    expect($actual['status'] === 200, 'FUL-16: expected actual 200: ' . json_encode($actual['json']));

    // Verify only 5 of 10 today.
    $verify1 = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => 5], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fulmultiverify1')));
    expect($verify1['status'] === 200, 'FUL-16: expected verify-fg 200: ' . json_encode($verify1['json']));

    $doA = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $orderId, 'factoryId' => $karangtengahFactoryId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fulmultidoA')));
    expect($doA['status'] === 200, 'FUL-16: expected DO-A create 200: ' . json_encode($doA['json']));
    expect(numEq($doA['json']['data']['items'][0]['plannedQty'], 5.0), 'FUL-16: expected DO-A plannedQty=5, got ' . json_encode($doA['json']['data']['items'][0]['plannedQty']));

    // Ship DO-A fully.
    $claimA = $adminHttp->request('POST', "/api/special-order-do/{$doA['json']['data']['doId']}/claim", null, array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fulmulticlaimA')));
    expect($claimA['status'] === 200, 'FUL-16: expected claim DO-A 200: ' . json_encode($claimA['json']));
    $departA = $adminHttp->request('POST', "/api/special-order-do/{$doA['json']['data']['doId']}/depart", ['items' => null], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fulmultidepartA')));
    expect($departA['status'] === 200, 'FUL-16: expected depart DO-A 200: ' . json_encode($departA['json']));
    expect($departA['json']['data']['status'] === 'shipped', 'FUL-16: expected DO-A status=shipped, got ' . $departA['json']['data']['status']);

    // Tomorrow: verify the remaining 5 (total 10) and open a SECOND DO for it.
    $verify2 = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => 10], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fulmultiverify2')));
    expect($verify2['status'] === 200, 'FUL-17: expected re-verify to 10, 200: ' . json_encode($verify2['json']));

    $doB = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $orderId, 'factoryId' => $karangtengahFactoryId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fulmultidoB')));
    expect($doB['status'] === 200, 'FUL-17: expected DO-B create 200 (the first shipped DO must NOT permanently block further fulfillment): ' . json_encode($doB['json']));
    expect($doB['json']['data']['doId'] !== $doA['json']['data']['doId'], 'FUL-17: expected a DIFFERENT doId for DO-B');
    expect(numEq($doB['json']['data']['items'][0]['plannedQty'], 5.0), 'FUL-17: expected DO-B plannedQty=5 (the newly-available remainder), got ' . json_encode($doB['json']['data']['items'][0]['plannedQty']));
});

runTest('FUL-18 requesting more than what is currently available for a new DO is rejected (never FG 10 / DO-A 10 / DO-B 10 double-allocation)', function () use ($adminHttp, $adminCsrf, $fulMultiOrderId, $fulMultiItemId, $karangtengahFactoryId) {
    // Everything (10) is now allocated across DO-A+DO-B — a third attempt
    // asking for even 1 more unit must be rejected, never silently accepted.
    $r = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $fulMultiOrderId, 'factoryId' => $karangtengahFactoryId, 'items' => [['itemId' => $fulMultiItemId, 'qty' => 1]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('ful18')));
    expect($r['status'] === 400, 'FUL-18: expected 400, got ' . $r['status'] . ': ' . json_encode($r['json']));
    expect(($r['json']['code'] ?? null) === 'EXCEEDS_AVAILABLE_FOR_DO', 'FUL-18: expected code=EXCEEDS_AVAILABLE_FOR_DO, got ' . json_encode($r['json']));
});

// --- FUL-19..25 (Driver Internal: claim -> depart -> real shipment) --------

$fulDriverDoId = null;
$fulDriverItemId = null;
runTest('FUL-19/20 a DRIVER_INTERNAL DO appears in the Driver Portal pool and can be claimed', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $rotiBollenProductId, $karangtengahFactoryId, &$fulDriverDoId, &$fulDriverItemId) {
    [$orderId, $itemId, $version] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 6]],
    ], 'fuldriver');
    $fulDriverItemId = $itemId;
    $adminHttp->request('POST', "/api/special-orders/{$orderId}/actual", ['expectedVersion' => $version, 'items' => [['itemId' => $itemId, 'aktualProduksi' => 6, 'rejectProduksi' => 0]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fuldriveractual')));
    $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => 6], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fuldriververify')));
    $do = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $orderId, 'factoryId' => $karangtengahFactoryId, 'deliveryMethod' => 'DRIVER_INTERNAL'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fuldriverdo')));
    expect($do['status'] === 200, 'FUL-19: expected DO create 200: ' . json_encode($do['json']));
    $fulDriverDoId = $do['json']['data']['doId'];

    $pool = $driverHttp->request('GET', '/api/special-order-do/driver-pool');
    expect($pool['status'] === 200, 'FUL-19: expected driver-pool 200: ' . json_encode($pool['json']));
    $found = null;
    foreach ($pool['json']['data'] as $p) { if ($p['doId'] === $fulDriverDoId) { $found = $p; break; } }
    expect($found !== null, 'FUL-19: expected the new DRIVER_INTERNAL DO to appear in the driver pool');
    expect($found['sourceLabel'] === 'Pesanan Khusus Toko', 'FUL-19: expected a clear source badge on the driver card, got ' . json_encode($found['sourceLabel']));

    $claim = $driverHttp->request('POST', "/api/special-order-do/{$fulDriverDoId}/claim", null, array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('fuldriverclaim')));
    expect($claim['status'] === 200, 'FUL-20: expected claim 200: ' . json_encode($claim['json']));
    expect($claim['json']['data']['claimedByUserId'] !== null, 'FUL-20: expected claimedByUserId to be set after claim');
});

runTest('FUL-25 a non-claimant cannot depart this DO', function () use ($adminHttp, $adminCsrf, $fulDriverDoId) {
    $r = $adminHttp->request('POST', "/api/special-order-do/{$fulDriverDoId}/depart", ['items' => null], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('ful25')));
    expect($r['status'] === 403, 'FUL-25: expected 403 for a non-claimant departure attempt, got ' . $r['status']);
    expect(($r['json']['code'] ?? null) === 'NOT_CLAIMANT', 'FUL-25: expected code=NOT_CLAIMANT, got ' . json_encode($r['json']));
});

runTest('FUL-21/22/23/24 departing PARTIAL qty creates a real shipment, populates actual_ship_qty, reduces FG atomically, and updates DO status to PARTIAL', function () use ($driverHttp, $driverCsrf, $pdo, $fulDriverDoId, $fulDriverItemId) {
    $doItems = $driverHttp->request('GET', "/api/special-order-do/{$fulDriverDoId}");
    $doItemId = $doItems['json']['data']['items'][0]['doItemId'];

    $r = $driverHttp->request('POST', "/api/special-order-do/{$fulDriverDoId}/depart", ['items' => [['doItemId' => $doItemId, 'qty' => 4]]], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('ful21')));
    expect($r['status'] === 200, 'FUL-21: expected depart 200: ' . json_encode($r['json']));
    expect(isset($r['json']['data']['shipmentId']) && $r['json']['data']['shipmentId'] > 0, 'FUL-22: expected a real shipmentId (actual_ship_qty is never "-" after a shipment)');
    expect($r['json']['data']['status'] === 'partial', 'FUL-24: expected DO status=partial (planned 6, shipped 4), got ' . $r['json']['data']['status']);
    $item = $r['json']['data']['items'][0];
    expect(numEq($item['shippedQty'], 4.0), 'FUL-22: expected shippedQty=4, got ' . json_encode($item['shippedQty']));
    expect(numEq($item['remainingQty'], 2.0), 'FUL-21: expected remainingQty=2, got ' . json_encode($item['remainingQty']));

    $shipmentDoId = (int) $pdo->query("SELECT special_order_do_id FROM shipment WHERE source_type='special_order_do' ORDER BY shipment_id DESC LIMIT 1")->fetchColumn();
    expect($shipmentDoId === $fulDriverDoId, 'FUL-23: expected the real shipment row to reference this DO, got special_order_do_id=' . $shipmentDoId);
});

runTest('FUL-45/46 shipping the remaining qty completes the DO (PARTIAL -> SHIPPED)', function () use ($driverHttp, $driverCsrf, $fulDriverDoId) {
    $r = $driverHttp->request('POST', "/api/special-order-do/{$fulDriverDoId}/depart", ['items' => null], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('ful46')));
    expect($r['status'] === 200, 'FUL-46: expected depart 200: ' . json_encode($r['json']));
    expect($r['json']['data']['status'] === 'shipped', 'FUL-46: expected DO status=shipped after the remaining qty ships, got ' . $r['json']['data']['status']);
});

runTest('FUL-25b double departure after full shipment is rejected (never a duplicate shipment)', function () use ($driverHttp, $driverCsrf, $fulDriverDoId) {
    $r = $driverHttp->request('POST', "/api/special-order-do/{$fulDriverDoId}/depart", ['items' => null], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('ful25b')));
    expect($r['status'] === 400, 'FUL-25b: expected 400 (already fully shipped), got ' . $r['status']);
    expect(($r['json']['code'] ?? null) === 'INVALID_STATUS', 'FUL-25b: expected code=INVALID_STATUS (a fully-shipped DO is rejected before ever reaching the empty-shipment check), got ' . json_encode($r['json']));
});

// --- FUL-26..35 (External Courier) ------------------------------------------

$fulCourierDoId = null;
runTest('FUL-26/27/36/37 an EXTERNAL_COURIER DO (Grab/GoSend/Lalamove) never appears in the Driver Portal pool, and a CS order can drop to a Bakery while keeping its CS source', function () use ($adminHttp, $adminCsrf, $driverHttp, $storeAId, $karangtengahFactoryId, &$fulCourierDoId) {
    [$orderId, $itemId, $version] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'cs', 'customerName' => 'Bapak Andi',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 3]],
    ], 'fulcourier');
    $adminHttp->request('POST', "/api/special-orders/{$orderId}/actual", ['expectedVersion' => $version, 'items' => [['itemId' => $itemId, 'aktualProduksi' => 3, 'rejectProduksi' => 0]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fulcourieractual')));
    $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => 3], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fulcourierverify')));

    $do = $adminHttp->request('POST', '/api/special-order-do', [
        'orderId' => $orderId, 'factoryId' => $karangtengahFactoryId, 'deliveryMethod' => 'EXTERNAL_COURIER',
        'courierProvider' => 'gosend', 'externalOrderReference' => 'GS-123456', 'dropStoreId' => $storeAId,
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('fulcourierdo')));
    expect($do['status'] === 200, 'FUL-28: expected DO create 200 with provider=gosend: ' . json_encode($do['json']));
    expect($do['json']['data']['courierProvider'] === 'gosend', 'FUL-28: expected courierProvider=gosend persisted');
    expect($do['json']['data']['sourceType'] === 'non_toko', 'FUL-37: expected sourceType to remain non_toko (CS) even though the physical drop is a store/Bakery');
    expect($do['json']['data']['dropStoreName'] !== null, 'FUL-36: expected a real drop-store (Bakery) name on the DO');
    $fulCourierDoId = $do['json']['data']['doId'];

    $pool = $driverHttp->request('GET', '/api/special-order-do/driver-pool');
    $foundInPool = false;
    foreach ($pool['json']['data'] as $p) { if ($p['doId'] === $fulCourierDoId) { $foundInPool = true; } }
    expect(!$foundInPool, 'FUL-26: expected the EXTERNAL_COURIER DO to be ABSENT from the Driver Portal pool (mutual exclusion)');
});

runTest('FUL-31 booking/creating an EXTERNAL_COURIER DO alone does NOT reduce FG', function () use ($adminHttp, $fulCourierDoId) {
    $eligible = $adminHttp->request('GET', '/api/special-orders/fg-eligible');
    $found = null;
    foreach ($eligible['json']['data'] as $it) {
        // The courier order's item — shippedQty must still be 0 (booking alone never ships).
        if ((float) $it['fgVerifiedQty'] === 3.0 && $it['sourceType'] === 'non_toko') { $found = $it; }
    }
    expect($found !== null, 'FUL-31: expected to find the courier order item in fg-eligible');
    expect(numEq($found['shippedQty'], 0.0), 'FUL-31: expected shippedQty=0 before any handover — DO creation alone must never reduce FG, got ' . json_encode($found['shippedQty']));
});

runTest('FUL-29/30/32/33/34 "Barang Diserahkan ke Kurir" creates a real shipment and reduces FG atomically', function () use ($adminHttp, $adminCsrf, $fulCourierDoId) {
    $r = $adminHttp->request('POST', "/api/special-order-do/{$fulCourierDoId}/courier-handover", ['items' => null, 'handoverNote' => 'Diambil kurir GoSend'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('ful29')));
    expect($r['status'] === 200, 'FUL-29/32: expected courier-handover 200: ' . json_encode($r['json']));
    expect($r['json']['data']['status'] === 'shipped', 'FUL-29: expected status=shipped, got ' . $r['json']['data']['status']);
    expect(isset($r['json']['data']['shipmentId']) && $r['json']['data']['shipmentId'] > 0, 'FUL-34: expected a real shipmentId (actual_ship_qty populated)');
    expect(numEq($r['json']['data']['items'][0]['shippedQty'], 3.0), 'FUL-33: expected shippedQty=3 after handover (FG reduced), got ' . json_encode($r['json']['data']['items'][0]['shippedQty']));

    $eligible = $adminHttp->request('GET', '/api/special-orders/fg-eligible');
    $found = null;
    foreach ($eligible['json']['data'] as $it) {
        if ((float) $it['fgVerifiedQty'] === 3.0 && $it['sourceType'] === 'non_toko') { $found = $it; }
    }
    expect($found !== null && numEq($found['shippedQty'], 3.0), 'FUL-33: expected the item-level shippedQty to reflect the courier handover');
});

runTest('FUL-35 a second handover attempt is idempotent-safe (nothing left, never a duplicate shipment)', function () use ($adminHttp, $adminCsrf, $fulCourierDoId) {
    $r = $adminHttp->request('POST', "/api/special-order-do/{$fulCourierDoId}/courier-handover", ['items' => null], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('ful35')));
    expect($r['status'] === 400, 'FUL-35: expected 400 (already fully shipped), got ' . $r['status']);
    expect(($r['json']['code'] ?? null) === 'INVALID_STATUS', 'FUL-35: expected code=INVALID_STATUS (a fully-shipped DO is rejected before ever reaching the empty-shipment check), got ' . json_encode($r['json']));
});

// --- Delivery method locking + driver claiming a courier DO is blocked -----

runTest('a DRIVER cannot claim an EXTERNAL_COURIER DO directly (defense in depth, not just pool filtering)', function () use ($driverHttp, $driverCsrf, $fulCourierDoId) {
    $r = $driverHttp->request('POST', "/api/special-order-do/{$fulCourierDoId}/claim", null, array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('ful-courier-claim-block')));
    expect($r['status'] === 400, 'expected 400 for a driver trying to claim an EXTERNAL_COURIER DO, got ' . $r['status']);
    expect(($r['json']['code'] ?? null) === 'WRONG_DELIVERY_METHOD', 'expected code=WRONG_DELIVERY_METHOD, got ' . json_encode($r['json']));
});

runTest('delivery method is locked once a real shipment exists', function () use ($adminHttp, $adminCsrf, $fulCourierDoId) {
    $get = $adminHttp->request('GET', "/api/special-order-do/{$fulCourierDoId}");
    $r = $adminHttp->request('POST', "/api/special-order-do/{$fulCourierDoId}/delivery-method", ['expectedVersion' => $get['json']['data']['version'], 'deliveryMethod' => 'DRIVER_INTERNAL'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('ful-method-lock')));
    expect($r['status'] === 400, 'expected 400, got ' . $r['status']);
    expect(($r['json']['code'] ?? null) === 'DELIVERY_METHOD_LOCKED', 'expected code=DELIVERY_METHOD_LOCKED, got ' . json_encode($r['json']));
});

runTest('FUL-48/49/50 Regular PO delivery_order/shipment tables stay completely untouched by all of the above', function () use ($pdo) {
    $doCount = (int) $pdo->query('SELECT COUNT(*) FROM delivery_order')->fetchColumn();
    expect($doCount === 0, 'FUL-48: expected zero Regular delivery_order rows — this feature never writes to it, got ' . $doCount);
    $regularShipmentCount = (int) $pdo->query("SELECT COUNT(*) FROM shipment WHERE source_type = 'delivery_order'")->fetchColumn();
    expect($regularShipmentCount === 0, 'FUL-49/50: expected zero Regular-sourced shipment rows in this suite\'s own DB, got ' . $regularShipmentCount);
});

// FLOW-43..47 (full regression green) are NOT tests in this file — they
// are the orchestrator's (run-production-flow-completion.sh) own final
// cascade into run-production-task.sh.

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
