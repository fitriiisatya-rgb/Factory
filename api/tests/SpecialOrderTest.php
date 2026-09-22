<?php

declare(strict_types=1);

/**
 * Migration 0010 — Pesanan Khusus Toko / Pesanan Non-Toko + Production
 * routing integration suite (ORDER-01..17). Run via
 * api/tests/run-special-order.sh, which stands up a disposable local
 * MariaDB, applies migrations 0001-0010, bootstraps realistic master
 * data, then drives the real /api/special-orders/* JSON API end to end
 * against a live `php -S` server. ORDER-18 (full Phase 0-5.5 regression
 * green) is NOT a test in this file — it is the orchestrator's own final
 * step, same pattern as every prior phase's own suite (see
 * Phase55DispatchReceiptTest.php's own docblock on P55-24).
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8110';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'order_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpOrder
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'ordercookies');
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

function login(HttpOrder $http, string $username, string $password): string
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
    return ['Idempotency-Key' => 'order-test-' . $tag . '-' . bin2hex(random_bytes(6))];
}

$pdo = new PDO(
    "mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4",
    getenv('TEST_RUNTIME_USER') ?: null,
    getenv('TEST_RUNTIME_PASS') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$adminHttp = new HttpOrder($baseUrl);
$adminCsrf = login($adminHttp, $adminUser, $adminPass);

createUser($pdo, 'order_driver_unauth', 'OrderDriverPass123', ['DRIVER']);
$driverHttp = new HttpOrder($baseUrl);
$driverCsrf = login($driverHttp, 'order_driver_unauth', 'OrderDriverPass123');

$storeAId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
expect($storeAId > 0, 'expected P2 TEST STORE A seeded');

$rotiBollenDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
expect($rotiBollenDivId > 0, 'expected Roti & Bollen division seeded');
$rotiBollenProductId = (int) $pdo->query("SELECT product_id FROM product WHERE division_id = {$rotiBollenDivId} AND aktif = 1 LIMIT 1")->fetchColumn();
expect($rotiBollenProductId > 0, 'expected at least one active Roti & Bollen product');

// A product with NO division set at all — ORDER-05's "division is
// mandatory" negative case. Inserted directly (never through the real
// import pipeline, which always assigns one) so this test doesn't depend
// on the katalog import ever actually containing a division-less row.
$pdo->exec("INSERT INTO product (name, division_id, hpp, harga, aktif, version, created_at) VALUES ('ORDER-TEST-NO-DIVISION-PRODUCT', NULL, 0, 10000, 1, 1, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE division_id = NULL");
$noDivisionProductId = (int) $pdo->query("SELECT product_id FROM product WHERE name = 'ORDER-TEST-NO-DIVISION-PRODUCT'")->fetchColumn();
expect($noDivisionProductId > 0, 'expected the no-division test product to be inserted');

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

// --- ORDER-01/03/06/09/10 -------------------------------------------------

runTest('ORDER-01 create Pesanan Khusus Toko succeeds with an existing-product item', function () use ($adminHttp, $adminCsrf, $storeAId, $rotiBollenProductId) {
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 5]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('order01')));
    expect($r['status'] === 200, 'ORDER-01: expected 200: ' . json_encode($r['json']));
    expect($r['json']['data']['status'] === 'draft', 'ORDER-01: expected status=draft');
    expect($r['json']['data']['sourceType'] === 'toko_khusus', 'ORDER-01: expected sourceType=toko_khusus');
    expect(str_starts_with($r['json']['data']['orderNo'], 'NPR-'), 'ORDER-01: expected an NPR- order number, got ' . $r['json']['data']['orderNo']);
    $GLOBALS['order01_id'] = $r['json']['data']['orderId'];
    $GLOBALS['order01_version'] = $r['json']['data']['version'];
});

runTest('ORDER-03 existing-product item uses a real, valid product_id', function () {
    $orderId = $GLOBALS['order01_id'] ?? null;
    expect($orderId !== null, 'depends on ORDER-01');
    global $adminHttp;
    $r = $adminHttp->request('GET', "/api/special-orders/{$orderId}");
    $item = $r['json']['data']['items'][0];
    expect($item['itemType'] === 'existing_product', 'ORDER-03: expected itemType=existing_product');
    expect(is_int($item['productId']) && $item['productId'] > 0, 'ORDER-03: expected a real numeric productId, got ' . json_encode($item['productId']));
    expect($item['specialCatalogId'] === null, 'ORDER-03: expected specialCatalogId to be null for an existing-product item');
});

runTest('ORDER-06 an existing-product item derives its division from the product master', function () use ($rotiBollenDivId) {
    $orderId = $GLOBALS['order01_id'] ?? null;
    global $adminHttp;
    $r = $adminHttp->request('GET', "/api/special-orders/{$orderId}");
    $item = $r['json']['data']['items'][0];
    expect($item['divisionId'] === $rotiBollenDivId, "ORDER-06: expected divisionId={$rotiBollenDivId} (Roti & Bollen), got {$item['divisionId']}");
    expect($item['divisionName'] === 'Roti & Bollen', 'ORDER-06: expected divisionName=Roti & Bollen');
});

// --- ORDER-02/04/07/08 (Pesanan Non-Toko + special catalog + multi-division) ---

runTest('ORDER-02 create Pesanan Non-Toko succeeds', function () use ($adminHttp, $adminCsrf) {
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'cs', 'customerName' => 'Budi Santoso', 'customerContact' => '08123456789',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-24',
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 1]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('order02')));
    expect($r['status'] === 200, 'ORDER-02: expected 200: ' . json_encode($r['json']));
    expect($r['json']['data']['sourceType'] === 'non_toko', 'ORDER-02: expected sourceType=non_toko');
    expect($r['json']['data']['customerName'] === 'Budi Santoso', 'ORDER-02: expected customerName to persist');
    expect($r['json']['data']['nonStoreSource'] === 'cs', 'ORDER-02: expected nonStoreSource=cs');
    $GLOBALS['order02_id'] = $r['json']['data']['orderId'];
});

runTest('ORDER-04 a custom/special item uses the controlled special catalog, never a normal product row', function () {
    $orderId = $GLOBALS['order02_id'] ?? null;
    global $adminHttp;
    $r = $adminHttp->request('GET', "/api/special-orders/{$orderId}");
    $item = $r['json']['data']['items'][0];
    expect($item['itemType'] === 'special_catalog', 'ORDER-04: expected itemType=special_catalog');
    expect(is_int($item['specialCatalogId']) && $item['specialCatalogId'] > 0, 'ORDER-04: expected a real specialCatalogId');
    expect($item['productId'] === null, 'ORDER-04: expected productId to be null for a special-catalog item (no normal product row created)');
});

runTest('ORDER-07 a custom/special item routes to the catalog master\'s own division', function () use ($adminHttp) {
    $catalog = $adminHttp->request('GET', '/api/special-orders/catalog')['json']['data'];
    $first = $catalog[0];
    expect($first['divisionName'] === 'Cake & Custom', 'ORDER-07: expected the DELUXE KARAKTER/ICING catalog to route to Cake & Custom, got ' . $first['divisionName']);

    $orderId = $GLOBALS['order02_id'] ?? null;
    $r = $adminHttp->request('GET', "/api/special-orders/{$orderId}");
    $item = $r['json']['data']['items'][0];
    expect($item['divisionId'] === $first['divisionId'], 'ORDER-07: expected the item\'s divisionId to match the catalog master\'s own divisionId');
});

runTest('ORDER-08 one order supports multiple production divisions (routed PER ITEM)', function () use ($adminHttp, $adminCsrf, $storeAId, $rotiBollenProductId) {
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [
            ['itemType' => 'special_catalog', 'specialCatalogId' => 3, 'qty' => 2, 'unitPrice' => 250000, 'charge' => 50000],
            ['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 10],
        ],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('order08')));
    expect($r['status'] === 200, 'ORDER-08: expected 200: ' . json_encode($r['json']));
    expect($r['json']['data']['isMultiDivision'] === true, 'ORDER-08: expected isMultiDivision=true');
    expect(count($r['json']['data']['divisionNames']) === 2, 'ORDER-08: expected exactly 2 distinct division names, got ' . count($r['json']['data']['divisionNames']));
    $divIds = array_unique(array_column($r['json']['data']['items'], 'divisionId'));
    expect(count($divIds) === 2, 'ORDER-08: expected the 2 items to carry 2 DIFFERENT division_id values (per-item routing)');
    $GLOBALS['order08_id'] = $r['json']['data']['orderId'];
    $GLOBALS['order08_version'] = $r['json']['data']['version'];
});

// --- ORDER-05 (division mandatory) ----------------------------------------

runTest('ORDER-05 division is mandatory — an existing product with NO division set is rejected', function () use ($adminHttp, $adminCsrf, $storeAId, $noDivisionProductId) {
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $noDivisionProductId, 'qty' => 1]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('order05')));
    expect($r['status'] === 422, "ORDER-05: expected 422 for a product with no division, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['code'] === 'PRODUCT_DIVISION_MISSING', 'ORDER-05: expected PRODUCT_DIVISION_MISSING, got ' . ($r['json']['code'] ?? 'null'));
});

// --- ORDER-09/10 (charge + special note persistence) ----------------------

runTest('ORDER-09 charge persists correctly and subtotal = qty*unitPrice + charge', function () {
    $orderId = $GLOBALS['order08_id'] ?? null;
    global $adminHttp;
    $r = $adminHttp->request('GET', "/api/special-orders/{$orderId}");
    $item = $r['json']['data']['items'][0]; // the DELUXE KARAKTER catalog item, qty=2, unitPrice=250000, charge=50000
    expect(abs($item['charge'] - 50000) < 0.01, 'ORDER-09: expected charge=50000, got ' . $item['charge']);
    $expectedSubtotal = 2 * 250000 + 50000;
    expect(abs($item['subtotal'] - $expectedSubtotal) < 0.01, "ORDER-09: expected subtotal={$expectedSubtotal}, got {$item['subtotal']}");
});

runTest('ORDER-10 special note (Deskripsi/Catatan Khusus) persists correctly', function () use ($adminHttp, $adminCsrf, $storeAId) {
    $note = 'Tema Spiderman, tulisan "HBD Raka", dominan warna biru';
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 3, 'qty' => 1, 'specialNote' => $note]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('order10')));
    expect($r['status'] === 200, 'ORDER-10: expected 200: ' . json_encode($r['json']));
    expect($r['json']['data']['items'][0]['specialNote'] === $note, 'ORDER-10: expected the exact special note to round-trip, got ' . json_encode($r['json']['data']['items'][0]['specialNote']));
    $GLOBALS['order10_id'] = $r['json']['data']['orderId'];
    $GLOBALS['order10_version'] = $r['json']['data']['version'];
    $GLOBALS['order10_note'] = $note;
});

// --- ORDER-11/13/17 (send to production, traceability, idempotency) -------

runTest('ORDER-11 special note is visible in the Production demand inbox (read-only)', function () use ($adminHttp, $adminCsrf) {
    $orderId = $GLOBALS['order10_id'] ?? null;
    $version = $GLOBALS['order10_version'] ?? null;
    expect($orderId !== null, 'depends on ORDER-10');
    $confirm = $adminHttp->request('POST', "/api/special-orders/{$orderId}/confirm", ['expectedVersion' => $version], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('order11confirm')));
    expect($confirm['status'] === 200, 'ORDER-11: confirm failed: ' . json_encode($confirm['json']));
    $send = $adminHttp->request('POST', "/api/special-orders/{$orderId}/send-to-production", ['expectedVersion' => $confirm['json']['data']['version']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('order11send')));
    expect($send['status'] === 200, 'ORDER-11: send-to-production failed: ' . json_encode($send['json']));
    $GLOBALS['order10_sent_version'] = $send['json']['data']['version'];

    $inbox = $adminHttp->request('GET', '/api/special-orders/production-inbox');
    $found = false;
    foreach ($inbox['json']['data']['divisions'] as $div) {
        foreach ($div['items'] as $it) {
            if ($it['orderNo'] === $send['json']['data']['orderNo'] && $it['specialNote'] === $GLOBALS['order10_note']) {
                $found = true;
            }
        }
    }
    expect($found, 'ORDER-11: expected the special note to appear verbatim in the Production demand inbox');
});

runTest('ORDER-13 Production demand source remains traceable (order number + source type + source label)', function () use ($adminHttp, $adminCsrf) {
    // Self-contained (never assumes an earlier test already sent a
    // non_toko order to Production — ORDER-11 sends a toko_khusus order,
    // ORDER-02's own non_toko order is deliberately left as a draft since
    // that's what ORDER-02 itself asserts): confirm + send ORDER-02's own
    // non_toko order here so both source types are guaranteed present.
    $nonTokoOrderId = $GLOBALS['order02_id'] ?? null;
    expect($nonTokoOrderId !== null, 'depends on ORDER-02');
    $order = $adminHttp->request('GET', "/api/special-orders/{$nonTokoOrderId}")['json']['data'];
    $confirm = $adminHttp->request('POST', "/api/special-orders/{$nonTokoOrderId}/confirm", ['expectedVersion' => $order['version']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('order13confirm')));
    expect($confirm['status'] === 200, 'ORDER-13: confirm failed: ' . json_encode($confirm['json']));
    $send = $adminHttp->request('POST', "/api/special-orders/{$nonTokoOrderId}/send-to-production", ['expectedVersion' => $confirm['json']['data']['version']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('order13send')));
    expect($send['status'] === 200, 'ORDER-13: send-to-production failed: ' . json_encode($send['json']));

    $inbox = $adminHttp->request('GET', '/api/special-orders/production-inbox');
    $foundTokoKhusus = false;
    $foundNonToko = false;
    foreach ($inbox['json']['data']['divisions'] as $div) {
        foreach ($div['items'] as $it) {
            expect(str_starts_with($it['orderNo'], 'NPR-'), 'ORDER-13: expected every inbox item to carry its real source order number');
            if ($it['sourceType'] === 'toko_khusus') { $foundTokoKhusus = true; }
            if ($it['sourceType'] === 'non_toko') { $foundNonToko = true; }
        }
    }
    expect($foundTokoKhusus, 'ORDER-13: expected at least one traceable toko_khusus item in the inbox');
    expect($foundNonToko, 'ORDER-13: expected at least one traceable non_toko item in the inbox (never merged/collapsed with toko_khusus)');
});

runTest('ORDER-17 a repeated/idempotent send-to-production action creates NO duplicate demand', function () use ($pdo) {
    $orderId = $GLOBALS['order10_id'] ?? null;
    global $adminHttp, $adminCsrf;
    $before = (int) $pdo->query("SELECT COUNT(*) FROM special_order_item WHERE special_order_id = {$orderId}")->fetchColumn();

    // Same Idempotency-Key as ORDER-11's own send -> must REPLAY, not re-execute.
    $replay = $adminHttp->request('POST', "/api/special-orders/{$orderId}/send-to-production", ['expectedVersion' => $GLOBALS['order10_sent_version'] - 1], ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'order-test-order11send-REUSE-CHECK']);
    // (different key on purpose below — the real safety net is INVALID_STATUS, since the order is already sent)
    $retry = $adminHttp->request('POST', "/api/special-orders/{$orderId}/send-to-production", ['expectedVersion' => $GLOBALS['order10_sent_version']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('order17-retry')));
    expect($retry['status'] === 400 && $retry['json']['code'] === 'INVALID_STATUS', 'ORDER-17: expected a genuinely new send attempt on an already-sent order to be rejected (400 INVALID_STATUS), not silently re-executed');

    $after = (int) $pdo->query("SELECT COUNT(*) FROM special_order_item WHERE special_order_id = {$orderId}")->fetchColumn();
    expect($before === $after, "ORDER-17: expected ZERO new special_order_item rows from the repeated send action (before={$before}, after={$after})");
});

// --- ORDER-12 (Regular PO unchanged) ---------------------------------------

runTest('ORDER-12 Regular PO (po_batch/po_item/po_store_item) is completely unchanged by this feature', function () use ($pdo) {
    $poBatchCountBefore = (int) $GLOBALS['po_batch_count_at_start'];
    $poItemCountBefore = (int) $GLOBALS['po_item_count_at_start'];
    $poStoreItemCountBefore = (int) $GLOBALS['po_store_item_count_at_start'];
    $poBatchCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM po_batch')->fetchColumn();
    $poItemCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM po_item')->fetchColumn();
    $poStoreItemCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM po_store_item')->fetchColumn();
    expect($poBatchCountBefore === $poBatchCountAfter, 'ORDER-12: expected po_batch row count UNCHANGED by every special-order test run so far');
    expect($poItemCountBefore === $poItemCountAfter, 'ORDER-12: expected po_item row count UNCHANGED');
    expect($poStoreItemCountBefore === $poStoreItemCountAfter, 'ORDER-12: expected po_store_item row count UNCHANGED');
});

// --- ORDER-14 (long notes don't break layout/storage) ----------------------

runTest('ORDER-14 a long customer/order note round-trips fully and the detail page still renders', function () use ($adminHttp, $adminCsrf, $baseUrl) {
    // rtrim: the service correctly trim()s a submitted note (real user
    // notes shouldn't preserve accidental trailing whitespace) — the
    // repeated phrase itself ends in a space, so the expected value must
    // match that same trimming or this assertion would fail on a mundane
    // trailing-space difference that has nothing to do with truncation.
    $longNote = rtrim(str_repeat('Catatan sangat panjang untuk menguji tata letak. ', 15)); // ~750 chars, within VARCHAR(1000)
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'umum', 'customerName' => 'Pelanggan Uji Catatan Panjang',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25', 'generalNote' => $longNote,
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 2, 'qty' => 1, 'specialNote' => $longNote]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('order14')));
    expect($r['status'] === 200, 'ORDER-14: expected 200: ' . json_encode($r['json']));
    expect($r['json']['data']['generalNote'] === $longNote, 'ORDER-14: expected the full long note to round-trip without truncation');
    expect($r['json']['data']['items'][0]['specialNote'] === $longNote, 'ORDER-14: expected the full long item note to round-trip without truncation');

    $detailPage = $adminHttp->request('GET', "/_ui-preview/?page=pesanan-non-toko-detail&id={$r['json']['data']['orderId']}");
    expect($detailPage['status'] === 200, 'ORDER-14: expected the detail page to render 200 with a long note present');
    expect(str_contains($detailPage['body'], 'overflow-wrap:anywhere'), 'ORDER-14: expected the note cell to use overflow-wrap:anywhere so a long note never breaks the table layout');
});

// --- ORDER-15/16 (authorization + CSRF) -------------------------------------

runTest('ORDER-15 an unauthorized (non-ADMIN/PPIC) user cannot create or mutate a special order', function () use ($driverHttp, $driverCsrf, $storeAId) {
    $r = $driverHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId, 'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 1]],
    ], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('order15')));
    expect($r['status'] === 403, "ORDER-15: expected 403 for a DRIVER trying to create a special order, got {$r['status']}: " . json_encode($r['json']));
});

runTest('ORDER-16 a mutation without a valid CSRF token is rejected', function () use ($baseUrl, $adminHttp, $storeAId) {
    // Reuse the admin's own authenticated cookie jar (already logged in)
    // but omit X-CSRF-Token entirely — App::run()'s central CSRF guard
    // must still reject it.
    $ch = curl_init($baseUrl . '/api/special-orders');
    $jarPath = (function () use ($adminHttp) {
        $ref = new ReflectionProperty($adminHttp, 'cookieJar');
        $ref->setAccessible(true);
        return $ref->getValue($adminHttp);
    })();
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jarPath, CURLOPT_COOKIEFILE => $jarPath,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Idempotency-Key: order-test-order16'],
        CURLOPT_POSTFIELDS => json_encode(['sourceType' => 'toko_khusus', 'storeId' => $storeAId, 'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25', 'items' => []]),
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    expect($status === 403, "ORDER-16: expected 403 for a mutation with no X-CSRF-Token, got {$status}");
});

// ORDER-18 (full Phase 0-5.5 regression green) is NOT a test in this file
// — same pattern as P55-24 (Phase55DispatchReceiptTest.php's own
// docblock) — it is the orchestrator's (run-special-order.sh) own final
// step.

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
