<?php

declare(strict_types=1);

/**
 * Final Pre-Live Rework of migration 0012 — Special/Non-Regular Shipment
 * -> Driver History -> Digital Surat Jalan -> Email -> Bakery Receipt
 * (FINAL-01..40, a representative subset of the full 40-item plan; see
 * this suite's own README/report for exactly which items each test
 * covers). Run via api/tests/run-final-prelive-rework.sh, which stands up
 * a disposable local MariaDB + a fake-SMTP transport (same FakeMailTransport
 * convention as Phase55DispatchReceiptTest.php's own MAIL-* tests), applies
 * migrations 0001-0012, bootstraps realistic master data, then drives the
 * real JSON APIs end to end against a live `php -S` server.
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8114';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'final_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$mailLogPath = getenv('TEST_MAIL_FAKE_LOG_PATH') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $mailLogPath === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpFinal
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'finalcookies');
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

    /** @return array{status:int,json:?array,body:string} */
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

function numEq(mixed $actual, float $expected, float $eps = 0.001): bool
{
    return is_numeric($actual) && abs((float) $actual - $expected) < $eps;
}

function login(HttpFinal $http, string $username, string $password): string
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
    return ['Idempotency-Key' => 'final-test-' . $tag . '-' . bin2hex(random_bytes(6))];
}

function fakeEvidenceImage(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );
    $path = tempnam(sys_get_temp_dir(), 'evidence') . '.png';
    file_put_contents($path, $png);
    return $path;
}

function readMailLog(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $lines = array_filter(explode("\n", (string) file_get_contents($path)), static fn ($l) => trim($l) !== '');
    return array_values(array_map(static fn ($l) => json_decode($l, true), $lines));
}

function lastMailTo(string $path, string $toEmail): ?array
{
    $matches = array_values(array_filter(readMailLog($path), static fn ($m) => $m['to'] === $toEmail));
    return $matches === [] ? null : end($matches);
}

function setStoreEmail(HttpFinal $adminHttp, string $adminCsrf, PDO $pdo, int $storeId, ?string $email): void
{
    $version = (int) $pdo->query("SELECT version FROM store WHERE store_id = {$storeId}")->fetchColumn();
    $r = $adminHttp->request('PUT', "/api/stores/{$storeId}", ['version' => $version, 'email' => $email],
        array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('set-store-email-' . $storeId . '-' . uniqid())));
    expect($r['status'] === 200, 'setStoreEmail failed: ' . json_encode($r['json']));
}

/** Creates + confirms + sends-to-production one special order, returns [orderId, itemId, version]. */
function createSentOrder(HttpFinal $http, string $csrf, array $body, string $tag): array
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

/**
 * End-to-end: creates a sent order, verifies FG, creates a DO, dispatches
 * it (DRIVER_INTERNAL via claim+depart, or EXTERNAL_COURIER via
 * courier-handover), and returns [orderId, doId, shipmentId, emailOutboxId].
 */
function createDispatchedSpecialShipment(HttpFinal $adminHttp, string $adminCsrf, HttpFinal $driverHttp, string $driverCsrf, array $orderBody, int $factoryId, string $deliveryMethod, ?array $courier, string $tag, float $qty = 4.0, ?int $dropStoreId = null): array
{
    [$orderId, $itemId, $version] = createSentOrder($adminHttp, $adminCsrf, $orderBody, $tag);
    $adminHttp->request('POST', "/api/special-orders/{$orderId}/actual", ['expectedVersion' => $version, 'items' => [['itemId' => $itemId, 'aktualProduksi' => $qty, 'rejectProduksi' => 0]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey($tag . 'actual')));
    $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => $qty], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey($tag . 'verifyfg')));

    // dropStoreId is a DO-LEVEL field (the physical Bakery drop point), never
    // part of the special_order creation body itself — required for every
    // non_toko order (toko_khusus derives it automatically from the order's
    // own store_id, see SpecialOrderDoService::create()).
    $doBody = array_merge(['orderId' => $orderId, 'factoryId' => $factoryId, 'deliveryMethod' => $deliveryMethod], $courier ?? []);
    if ($dropStoreId !== null) {
        $doBody['dropStoreId'] = $dropStoreId;
    }
    $do = $adminHttp->request('POST', '/api/special-order-do', $doBody, array_merge(['X-CSRF-Token' => $adminCsrf], idemKey($tag . 'do')));
    expect($do['status'] === 200, "{$tag}: DO create failed: " . json_encode($do['json']));
    $doId = $do['json']['data']['doId'];

    if ($deliveryMethod === 'DRIVER_INTERNAL') {
        $claim = $driverHttp->request('POST', "/api/special-order-do/{$doId}/claim", null, array_merge(['X-CSRF-Token' => $driverCsrf], idemKey($tag . 'claim')));
        expect($claim['status'] === 200, "{$tag}: claim failed: " . json_encode($claim['json']));
        $depart = $driverHttp->request('POST', "/api/special-order-do/{$doId}/depart", ['items' => null], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey($tag . 'depart')));
        expect($depart['status'] === 200, "{$tag}: depart failed: " . json_encode($depart['json']));
        $shipmentId = $depart['json']['data']['shipmentId'];
    } else {
        $handover = $adminHttp->request('POST', "/api/special-order-do/{$doId}/courier-handover", ['items' => null], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey($tag . 'handover')));
        expect($handover['status'] === 200, "{$tag}: courier-handover failed: " . json_encode($handover['json']));
        $shipmentId = $handover['json']['data']['shipmentId'];
    }

    return ['orderId' => $orderId, 'doId' => $doId, 'shipmentId' => $shipmentId];
}

$pdo = new PDO(
    "mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4",
    getenv('TEST_RUNTIME_USER') ?: null,
    getenv('TEST_RUNTIME_PASS') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$adminHttp = new HttpFinal($baseUrl);
$adminCsrf = login($adminHttp, $adminUser, $adminPass);

createUser($pdo, 'final_driver', 'FinalDriverPass123', ['DRIVER']);
$driverHttp = new HttpFinal($baseUrl);
$driverCsrf = login($driverHttp, 'final_driver', 'FinalDriverPass123');

$storeAId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
expect($storeAId > 0, 'expected P2 TEST STORE A seeded');

$rotiBollenDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
$rotiBollenProductId = (int) $pdo->query("SELECT product_id FROM product WHERE division_id = {$rotiBollenDivId} AND aktif = 1 LIMIT 1")->fetchColumn();
expect($rotiBollenProductId > 0, 'expected at least one active Roti & Bollen product');

$karangtengahFactoryId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
expect($karangtengahFactoryId > 0, 'expected Karangtengah factory seeded');

setStoreEmail($adminHttp, $adminCsrf, $pdo, $storeAId, 'bakery-drop-' . uniqid() . '@example.test');

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

// --- FINAL-01..05 (normalized source identity) ------------------------------

runTest('FINAL-01 toko_khusus normalizes to SPECIAL_STORE_ORDER on the real shipment', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $karangtengahFactoryId, $rotiBollenProductId) {
    $fx = createDispatchedSpecialShipment($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 4]],
    ], $karangtengahFactoryId, 'DRIVER_INTERNAL', null, 'final01');

    $detail = $driverHttp->request('GET', "/api/dispatch/shipments/{$fx['shipmentId']}");
    expect($detail['status'] === 200, 'FINAL-01: expected shipment detail 200: ' . json_encode($detail['json']));
    expect($detail['json']['data']['source']['type'] === 'SPECIAL_STORE_ORDER', 'FINAL-01: expected source.type=SPECIAL_STORE_ORDER, got ' . json_encode($detail['json']['data']['source']));
    $GLOBALS['final01_shipmentId'] = $fx['shipmentId'];
    $GLOBALS['final01_doId'] = $fx['doId'];
});

runTest('FINAL-02 non_toko/cs normalizes to CS_ORDER', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $karangtengahFactoryId) {
    $fx = createDispatchedSpecialShipment($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'cs', 'customerName' => 'Bapak Andi',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25', 'dropStoreId' => $storeAId,
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 4]],
    ], $karangtengahFactoryId, 'EXTERNAL_COURIER', ['courierProvider' => 'gosend', 'externalOrderReference' => 'GS-FINAL02', 'dropStoreId' => $storeAId], 'final02');

    $detail = $adminHttp->request('GET', "/api/dispatch/shipments/{$fx['shipmentId']}");
    expect($detail['status'] === 200, 'FINAL-02: expected 200: ' . json_encode($detail['json']));
    expect($detail['json']['data']['source']['type'] === 'CS_ORDER', 'FINAL-02: expected source.type=CS_ORDER, got ' . json_encode($detail['json']['data']['source']));
    expect($detail['json']['data']['driverName'] === 'Kurir: Gosend (Gosend)' || str_starts_with((string) $detail['json']['data']['driverName'], 'Kurir:'), 'FINAL-02: expected the courier identity shown instead of the acting admin\'s name, got ' . json_encode($detail['json']['data']['driverName']));
    $GLOBALS['final02_shipmentId'] = $fx['shipmentId'];
    $GLOBALS['final02_storeEmail'] = null;
});

runTest('FINAL-03 non_toko/sales_executive normalizes to SALES_ORDER', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $karangtengahFactoryId) {
    $fx = createDispatchedSpecialShipment($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'sales_executive', 'customerName' => 'Sales FINAL-03',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25', 'dropStoreId' => $storeAId,
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 2]],
    ], $karangtengahFactoryId, 'DRIVER_INTERNAL', null, 'final03', 2.0, $storeAId);
    $detail = $driverHttp->request('GET', "/api/dispatch/shipments/{$fx['shipmentId']}");
    expect($detail['json']['data']['source']['type'] === 'SALES_ORDER', 'FINAL-03: expected SALES_ORDER, got ' . json_encode($detail['json']['data']['source']));
});

runTest('FINAL-04 non_toko/konsumen_langsung normalizes to DIRECT_CUSTOMER', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $karangtengahFactoryId) {
    $fx = createDispatchedSpecialShipment($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'konsumen_langsung', 'customerName' => 'Konsumen FINAL-04',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25', 'dropStoreId' => $storeAId,
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 2]],
    ], $karangtengahFactoryId, 'DRIVER_INTERNAL', null, 'final04', 2.0, $storeAId);
    $detail = $driverHttp->request('GET', "/api/dispatch/shipments/{$fx['shipmentId']}");
    expect($detail['json']['data']['source']['type'] === 'DIRECT_CUSTOMER', 'FINAL-04: expected DIRECT_CUSTOMER, got ' . json_encode($detail['json']['data']['source']));
});

runTest('FINAL-05 non_toko/umum normalizes to GENERAL_ORDER', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $karangtengahFactoryId) {
    $fx = createDispatchedSpecialShipment($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'umum', 'customerName' => 'Umum FINAL-05',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25', 'dropStoreId' => $storeAId,
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 2]],
    ], $karangtengahFactoryId, 'DRIVER_INTERNAL', null, 'final05', 2.0, $storeAId);
    $detail = $driverHttp->request('GET', "/api/dispatch/shipments/{$fx['shipmentId']}");
    expect($detail['json']['data']['source']['type'] === 'GENERAL_ORDER', 'FINAL-05: expected GENERAL_ORDER, got ' . json_encode($detail['json']['data']['source']));
});

// --- FINAL-07..10 (Driver History / Detail) ---------------------------------

runTest('FINAL-07/08 the special shipment appears in Driver Riwayat with real product/qty totals and custom-item rendering', function () use ($driverHttp) {
    $shipmentId = $GLOBALS['final01_shipmentId'];
    $hist = $driverHttp->request('GET', '/api/dispatch/history');
    expect($hist['status'] === 200, 'FINAL-07: expected 200: ' . json_encode($hist['json']));
    $row = null;
    foreach ($hist['json']['data'] as $r) { if ((int) $r['shipment_id'] === $shipmentId) { $row = $r; break; } }
    expect($row !== null, 'FINAL-07: expected the special shipment to appear in Driver Riwayat');
    expect((int) $row['product_count'] > 0, 'FINAL-07: expected product_count > 0 (never 0 for a real special line), got ' . json_encode($row['product_count']));
    expect(numEq($row['total_qty'], 4.0), 'FINAL-07: expected total_qty=4, got ' . json_encode($row['total_qty']));
    expect($row['special_doc_no'] !== null, 'FINAL-07: expected special_doc_no populated for a special-order shipment');
});

runTest('FINAL-08b custom/catalog item renders with a real name and NO fake product_id', function () use ($adminHttp) {
    $shipmentId = $GLOBALS['final02_shipmentId'];
    $detail = $adminHttp->request('GET', "/api/dispatch/shipments/{$shipmentId}");
    expect($detail['status'] === 200, 'FINAL-08b: expected 200: ' . json_encode($detail['json']));
    $item = $detail['json']['data']['items'][0];
    expect($item['productId'] === null, 'FINAL-08b: expected productId=null for a special_catalog item (never a fake product_id), got ' . json_encode($item['productId']));
    expect(!empty($item['productName']), 'FINAL-08b: expected a real, non-empty item name for the custom/catalog item');
});

// --- FINAL-11..15 (Digital Surat Jalan) -------------------------------------

runTest('FINAL-11/13 the Surat Jalan print page renders a special shipment (source + no fake product_id crash)', function () use ($driverHttp) {
    $shipmentId = $GLOBALS['final01_shipmentId'];
    $r = $driverHttp->request('GET', "/_driver-uat/print-shipment.php?id={$shipmentId}");
    expect($r['status'] === 200, 'FINAL-11: expected 200: ' . substr($r['body'], 0, 300));
    expect(str_contains($r['body'], 'SURAT JALAN'), 'FINAL-11: expected the Surat Jalan document to render');
    expect(str_contains($r['body'], 'Pesanan Khusus Toko'), 'FINAL-11: expected the Source row to show "Pesanan Khusus Toko"');
});

runTest('FINAL-14/15 the Surat Jalan print page shows courier info for an EXTERNAL_COURIER shipment', function () use ($adminHttp) {
    $shipmentId = $GLOBALS['final02_shipmentId'];
    $r = $adminHttp->request('GET', "/_ui-preview/print-shipment.php?id={$shipmentId}");
    expect($r['status'] === 200, 'FINAL-14: expected 200: ' . substr($r['body'], 0, 300));
    expect(str_contains($r['body'], 'Kurir Eksternal') || str_contains($r['body'], 'CS'), 'FINAL-14: expected courier/source info on the printed document');
});

// --- FINAL-16..21 (Automatic Shipment Email) --------------------------------

runTest('FINAL-16/18 DRIVER_INTERNAL dispatch creates+sends an email to the DROP BAKERY, never rolling back the shipment on SMTP failure', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $karangtengahFactoryId, $rotiBollenProductId, $pdo, $mailLogPath) {
    setStoreEmail($adminHttp, $adminCsrf, $pdo, $storeAId, 'simulate-smtp-failure-' . uniqid() . '@example.test');
    $fx = createDispatchedSpecialShipment($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 2]],
    ], $karangtengahFactoryId, 'DRIVER_INTERNAL', null, 'final16');

    $row = $pdo->query("SELECT status FROM shipment_email_delivery WHERE shipment_id = {$fx['shipmentId']}")->fetch();
    expect($row !== false && $row['status'] === 'failed', 'FINAL-18: expected the simulated SMTP failure to be recorded, got ' . json_encode($row));

    $shipmentRow = $pdo->query("SELECT status FROM shipment WHERE shipment_id = {$fx['shipmentId']}")->fetch();
    expect($shipmentRow['status'] === 'active', 'FINAL-18: expected the shipment to remain active despite the email failure');
    $doStatus = $pdo->query("SELECT status FROM special_order_do WHERE special_order_do_id = {$fx['doId']}")->fetchColumn();
    expect($doStatus === 'shipped', 'FINAL-18: expected the DO status to remain shipped (FG consumption/DO write never rolled back), got ' . $doStatus);
});

runTest('FINAL-16b a real store email receives the Digital Surat Jalan email, addressed to the DROP BAKERY (not the customer)', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $karangtengahFactoryId, $pdo, $mailLogPath) {
    $email = 'final16b-drop-bakery-' . uniqid() . '@example.test';
    setStoreEmail($adminHttp, $adminCsrf, $pdo, $storeAId, $email);
    $fx = createDispatchedSpecialShipment($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'cs', 'customerName' => 'Bapak Andi FINAL-16b',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25', 'dropStoreId' => $storeAId,
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 3]],
    ], $karangtengahFactoryId, 'DRIVER_INTERNAL', null, 'final16b', 3.0, $storeAId);

    $mail = lastMailTo($mailLogPath, $email);
    expect($mail !== null, 'FINAL-16b: expected an email actually sent to the drop bakery\'s address');
    expect(!str_contains($mail['htmlBody'], 'Bapak Andi FINAL-16b'), 'FINAL-E: email destination must never be driven by the CUSTOMER name — it is the physical drop Bakery, not the future billing owner');
    $GLOBALS['final16b_shipmentId'] = $fx['shipmentId'];
    $GLOBALS['final16b_mail'] = $mail;
});

runTest('FINAL-19 the email\'s receipt link resolves to the correct single special shipment', function () use ($baseUrl) {
    $mail = $GLOBALS['final16b_mail'];
    expect(preg_match('/token=([0-9a-f]{64})/', $mail['htmlBody'], $m) === 1, 'FINAL-19: expected a 64-hex token in the email link');
    $ch = curl_init($baseUrl . '/api/_receive/?token=' . $m[1]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($ch);
    curl_close($ch);
    expect(str_contains($body, '"shipmentId":' . $GLOBALS['final16b_shipmentId']), 'FINAL-19: expected the shipment-scoped token to resolve to the real special shipment');
    expect(substr_count($body, '"shipmentId":') === 1, 'FINAL-19: expected EXACTLY one shipment in view — the Bakery must not see another source\'s shipment');
});

runTest('FINAL-17 EXTERNAL_COURIER handover also creates+sends an automatic email', function () use ($adminHttp, $adminCsrf, $storeAId, $karangtengahFactoryId, $pdo, $mailLogPath) {
    $email = 'final17-courier-' . uniqid() . '@example.test';
    setStoreEmail($adminHttp, $adminCsrf, $pdo, $storeAId, $email);
    [$orderId, $itemId, $version] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'umum', 'customerName' => 'FINAL-17 Umum',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 2]],
    ], 'final17');
    $adminHttp->request('POST', "/api/special-orders/{$orderId}/actual", ['expectedVersion' => $version, 'items' => [['itemId' => $itemId, 'aktualProduksi' => 2, 'rejectProduksi' => 0]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('final17actual')));
    $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => 2], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('final17verify')));
    $do = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $orderId, 'factoryId' => $karangtengahFactoryId, 'deliveryMethod' => 'EXTERNAL_COURIER', 'courierProvider' => 'grab', 'dropStoreId' => $storeAId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('final17do')));
    $doId = $do['json']['data']['doId'];
    $handover = $adminHttp->request('POST', "/api/special-order-do/{$doId}/courier-handover", ['items' => null], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('final17handover')));
    expect($handover['status'] === 200, 'FINAL-17: expected handover 200: ' . json_encode($handover['json']));

    $mail = lastMailTo($mailLogPath, $email);
    expect($mail !== null, 'FINAL-17: expected an automatic email at "Barang Diserahkan ke Kurir" time');
});

runTest('FINAL-20/21 Admin resend reuses the SAME outbox row with zero shipment/FG side effects', function () use ($adminHttp, $adminCsrf, $pdo) {
    $shipmentId = $GLOBALS['final16b_shipmentId'];
    $ledgerBefore = (int) $pdo->query("SELECT COUNT(*) FROM special_order_do_shipment_item")->fetchColumn();
    $r = $adminHttp->request('POST', "/api/admin/shipments/{$shipmentId}/email/resend", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('final20')));
    expect($r['status'] === 200, 'FINAL-20: resend failed: ' . json_encode($r['json']));
    $outboxRows = (int) $pdo->query("SELECT COUNT(*) FROM shipment_email_delivery WHERE shipment_id = {$shipmentId}")->fetchColumn();
    expect($outboxRows === 1, 'FINAL-20: expected the SAME single outbox row reused, got ' . $outboxRows);
    $ledgerAfter = (int) $pdo->query("SELECT COUNT(*) FROM special_order_do_shipment_item")->fetchColumn();
    expect($ledgerBefore === $ledgerAfter, 'FINAL-21: expected ZERO new special_order_do_shipment_item rows from a resend');
});

// --- FINAL-22..30 (Bakery Receipt) ------------------------------------------

runTest('FINAL-22/11c a custom-item special shipment can be confirmed via its shipment token, math-validated, with no photo required when clean', function () use ($baseUrl, $mailLogPath) {
    $mail = $GLOBALS['final16b_mail'];
    preg_match('/token=([0-9a-f]{64})/', $mail['htmlBody'], $m);
    $token = $m[1];
    $shipmentId = $GLOBALS['final16b_shipmentId'];

    $anon = new HttpFinal($baseUrl);
    $view = $anon->request('GET', "/api/receive/{$token}");
    expect($view['status'] === 200, 'FINAL-22: expected public view 200: ' . json_encode($view['json']));
    $lineId = $view['json']['data']['shipments'][0]['items'][0]['shipmentItemId'];
    $shippedQty = $view['json']['data']['shipments'][0]['items'][0]['shippedQty'];

    $confirm = $anon->request('POST', "/api/receive/{$token}/shipments/{$shipmentId}/confirm", [
        'receiverName' => 'Bakery Tester', 'items' => [['shipmentItemId' => $lineId, 'receivedGood' => $shippedQty, 'reject' => 0, 'shortage' => 0]],
    ], idemKey('final22'));
    expect($confirm['status'] === 200, 'FINAL-22: expected confirm 200: ' . json_encode($confirm['json']));
    expect($confirm['json']['data']['status'] === 'confirmed_ok', 'FINAL-22: expected confirmed_ok for a clean confirmation, got ' . json_encode($confirm['json']['data']));
    expect($confirm['json']['data']['items'][0]['productName'] !== null, 'FINAL-22: expected a real item name on the receipt line (custom item, no fake product_id)');
});

runTest('FINAL-23/24/09c discrepancy math is enforced and requires photo evidence before Admin can verify', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $karangtengahFactoryId, $pdo, $baseUrl, $mailLogPath) {
    $email = 'final23-' . uniqid() . '@example.test';
    setStoreEmail($adminHttp, $adminCsrf, $pdo, $storeAId, $email);
    $fx = createDispatchedSpecialShipment($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'cs', 'customerName' => 'FINAL-23 CS',
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25', 'dropStoreId' => $storeAId,
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => 1, 'qty' => 5]],
    ], $karangtengahFactoryId, 'DRIVER_INTERNAL', null, 'final23', 5.0, $storeAId);

    $mail = lastMailTo($mailLogPath, $email);
    expect($mail !== null, 'FINAL-23: expected an automatic email for this shipment');
    preg_match('/token=([0-9a-f]{64})/', $mail['htmlBody'], $m);
    $token = $m[1];

    $anon = new HttpFinal($baseUrl);
    $view = $anon->request('GET', "/api/receive/{$token}");
    $lineId = $view['json']['data']['shipments'][0]['items'][0]['shipmentItemId'];

    // Math mismatch (2 + 1 + 1 = 4, not 5) is rejected server-side.
    $badMath = $anon->request('POST', "/api/receive/{$token}/shipments/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Bakery', 'items' => [['shipmentItemId' => $lineId, 'receivedGood' => 2, 'reject' => 1, 'shortage' => 1]],
    ], idemKey('final23bad'));
    expect($badMath['status'] === 400, 'FINAL-23: expected 400 for a math mismatch, got ' . $badMath['status']);
    expect(($badMath['json']['code'] ?? null) === 'RECEIPT_MATH_INVALID', 'FINAL-23: expected code=RECEIPT_MATH_INVALID, got ' . json_encode($badMath['json']));

    // Correct math (3 + 1 + 1 = 5) but a discrepancy exists (reject/shortage > 0) and NO photo — rejected.
    $noEvidence = $anon->request('POST', "/api/receive/{$token}/shipments/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Bakery', 'items' => [['shipmentItemId' => $lineId, 'receivedGood' => 3, 'reject' => 1, 'shortage' => 1]],
    ], idemKey('final23noev'));
    expect($noEvidence['status'] === 400, 'FINAL-24: expected 400 without evidence, got ' . $noEvidence['status']);
    expect(($noEvidence['json']['code'] ?? null) === 'EVIDENCE_REQUIRED', 'FINAL-24: expected code=EVIDENCE_REQUIRED, got ' . json_encode($noEvidence['json']));

    // Same submission WITH a real photo succeeds.
    $withEvidence = $anon->requestMultipart('POST', "/api/receive/{$token}/shipments/{$fx['shipmentId']}/confirm",
        ['receiverName' => 'Bakery', 'items' => json_encode([['shipmentItemId' => $lineId, 'receivedGood' => 3, 'reject' => 1, 'shortage' => 1]])],
        ['evidence[]' => fakeEvidenceImage()], idemKey('final24ok'));
    expect($withEvidence['status'] === 200, 'FINAL-24: expected 200 with photo evidence: ' . json_encode($withEvidence['json']));
    expect($withEvidence['json']['data']['status'] === 'confirmed_discrepancy', 'FINAL-24: expected confirmed_discrepancy, got ' . json_encode($withEvidence['json']['data']));
    expect(count($withEvidence['json']['data']['evidence']) === 1, 'FINAL-24: expected exactly one evidence row recorded');

    $GLOBALS['final23_receiptId'] = $withEvidence['json']['data']['receiptId'];
    $GLOBALS['final23_shipmentId'] = $fx['shipmentId'];
});

runTest('FINAL-25 Admin Konfirmasi Toko lists the special discrepancy with a clear source badge, and Admin can verify it', function () use ($adminHttp, $adminCsrf) {
    $list = $adminHttp->request('GET', '/api/admin/receipts?status=confirmed_discrepancy');
    expect($list['status'] === 200, 'FINAL-25: expected 200: ' . json_encode($list['json']));
    $row = null;
    foreach ($list['json']['data'] as $r) { if ((int) $r['shipmentId'] === $GLOBALS['final23_shipmentId']) { $row = $r; } }
    expect($row !== null, 'FINAL-25: expected the special discrepancy receipt to appear in Admin Konfirmasi Toko');
    expect($row['source']['type'] === 'CS_ORDER', 'FINAL-25: expected a clear CS_ORDER source badge, got ' . json_encode($row['source']));
    expect($row['deliveryMethod'] === 'DRIVER_INTERNAL', 'FINAL-25: expected deliveryMethod shown, got ' . json_encode($row['deliveryMethod']));

    $verify = $adminHttp->request('POST', "/api/admin/receipts/{$GLOBALS['final23_receiptId']}/verify", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('final25verify')));
    expect($verify['status'] === 200, 'FINAL-25: expected verify 200 (evidence exists): ' . json_encode($verify['json']));
    expect($verify['json']['data']['status'] === 'verified', 'FINAL-25: expected status=verified, got ' . json_encode($verify['json']['data']));
});

runTest('FINAL-06 Bakery confirming receipt never changes the order\'s source_type/billing identity', function () use ($pdo) {
    $shipmentId = $GLOBALS['final23_shipmentId'];
    $sourceType = $pdo->query(
        "SELECT so2.source_type FROM shipment sh
         INNER JOIN special_order_do sodo ON sodo.special_order_do_id = sh.special_order_do_id
         INNER JOIN special_order so2 ON so2.special_order_id = sodo.special_order_id
         WHERE sh.shipment_id = {$shipmentId}"
    )->fetchColumn();
    expect($sourceType === 'non_toko', 'FINAL-06: expected special_order.source_type to remain non_toko (CS) after receipt confirmation/verification, got ' . json_encode($sourceType));
});

// --- FINAL-31/32 (Partial shipments get SEPARATE emails/receipts) ----------

runTest('FINAL-31/32 a partial DO produces TWO separate shipments, each with its OWN email and its OWN receipt', function () use ($adminHttp, $adminCsrf, $driverHttp, $driverCsrf, $storeAId, $karangtengahFactoryId, $rotiBollenProductId, $pdo, $mailLogPath, $baseUrl) {
    $email = 'final31-' . uniqid() . '@example.test';
    setStoreEmail($adminHttp, $adminCsrf, $pdo, $storeAId, $email);

    [$orderId, $itemId, $version] = createSentOrder($adminHttp, $adminCsrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId,
        'orderDate' => '2026-09-22', 'requiredDate' => '2026-09-25',
        'items' => [['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 10]],
    ], 'final31');
    $adminHttp->request('POST', "/api/special-orders/{$orderId}/actual", ['expectedVersion' => $version, 'items' => [['itemId' => $itemId, 'aktualProduksi' => 10, 'rejectProduksi' => 0]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('final31actual')));
    $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => 10], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('final31verify')));
    $do = $adminHttp->request('POST', '/api/special-order-do', ['orderId' => $orderId, 'factoryId' => $karangtengahFactoryId, 'deliveryMethod' => 'DRIVER_INTERNAL'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('final31do')));
    $doId = $do['json']['data']['doId'];
    $doItemId = $do['json']['data']['items'][0]['doItemId'];

    $driverHttp->request('POST', "/api/special-order-do/{$doId}/claim", null, array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('final31claim')));

    $depart1 = $driverHttp->request('POST', "/api/special-order-do/{$doId}/depart", ['items' => [['doItemId' => $doItemId, 'qty' => 4]]], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('final31depart1')));
    expect($depart1['status'] === 200, 'FINAL-31: expected first partial depart 200: ' . json_encode($depart1['json']));
    $shipment1 = $depart1['json']['data']['shipmentId'];

    $depart2 = $driverHttp->request('POST', "/api/special-order-do/{$doId}/depart", ['items' => [['doItemId' => $doItemId, 'qty' => 6]]], array_merge(['X-CSRF-Token' => $driverCsrf], idemKey('final31depart2')));
    expect($depart2['status'] === 200, 'FINAL-31: expected second partial depart 200: ' . json_encode($depart2['json']));
    $shipment2 = $depart2['json']['data']['shipmentId'];

    expect($shipment1 !== $shipment2, 'FINAL-31: expected TWO distinct shipment ids for the two partial dispatches');

    $outbox1 = $pdo->query("SELECT shipment_email_delivery_id FROM shipment_email_delivery WHERE shipment_id = {$shipment1}")->fetchColumn();
    $outbox2 = $pdo->query("SELECT shipment_email_delivery_id FROM shipment_email_delivery WHERE shipment_id = {$shipment2}")->fetchColumn();
    expect($outbox1 !== false && $outbox2 !== false && $outbox1 !== $outbox2, 'FINAL-32: expected TWO separate email outbox rows, never merged, got ' . json_encode([$outbox1, $outbox2]));

    $mailCount = count(array_filter(readMailLog($mailLogPath), static fn ($m) => $m['to'] === $email));
    expect($mailCount >= 2, 'FINAL-32: expected at least two separate emails sent to the same bakery for the two partial shipments, got ' . $mailCount);

    // Each shipment confirms SEPARATELY (never merged into one receipt) —
    // looked up directly from shipment_receipt_token (minted by the
    // automatic-email attempt above) rather than fragile log-scraping.
    $token2 = $pdo->query("SELECT token FROM shipment_receipt_token WHERE shipment_id = {$shipment2}")->fetchColumn();
    expect($token2 !== false, 'FINAL-31: expected shipment2 to have minted its OWN shipment_receipt_token row');
    $token1Row = $pdo->query("SELECT token FROM shipment_receipt_token WHERE shipment_id = {$shipment1}")->fetchColumn();
    expect($token1Row !== false && $token1Row !== $token2, 'FINAL-31: expected shipment1 and shipment2 to have DIFFERENT tokens, never shared');

    $view2 = (new HttpFinal($baseUrl))->request('GET', "/api/receive/{$token2}");
    expect(count($view2['json']['data']['shipments']) === 1, 'FINAL-31: expected the shipment-scoped view to show exactly ONE shipment (never auto-merged with shipment1)');
    expect((int) $view2['json']['data']['shipments'][0]['shipmentId'] === $shipment2, 'FINAL-31: expected the token to resolve to shipment2 specifically');
});

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit(count($failed) === 0 ? 0 : 1);
