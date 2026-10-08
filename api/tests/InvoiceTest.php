<?php

declare(strict_types=1);

/**
 * Invoice generation integration suite (migration 0018) — INV-01..16. Run
 * via api/tests/run-invoice.sh, which stands up a disposable local
 * MariaDB, applies ALL migrations 0001-0018, bootstraps realistic master
 * data, then drives the real /api/invoices/* JSON API end to end against
 * a live `php -S` server — plus the full existing regression cascade as
 * this orchestrator's own final step.
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;
use Amor\Api\StorePortal\StorePortalService;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8140';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'inv_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpInv
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'invcookies');
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

    /** multipart/form-data — $files maps field name (e.g. "evidence[]") to a local file path. */
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

function fakeEvidenceImage(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );
    $path = tempnam(sys_get_temp_dir(), 'invevidence') . '.png';
    file_put_contents($path, $png);
    return $path;
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

function login(HttpInv $http, string $username, string $password): string
{
    $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    $me = $http->request('GET', '/api/auth/me');
    $csrf = $me['json']['data']['csrfToken'] ?? null;
    expect($csrf !== null, "expected a csrf token after login as {$username}");
    return $csrf;
}

function issuePortalToken(PDO $pdo, int $storeId, int $adminUserId): string
{
    $svc = new StorePortalService($pdo);
    $dto = $svc->issueToken($storeId, $adminUserId, 'inv-test-issue-' . uniqid('', true));
    return $dto['rawToken'];
}

function productsInDivision(PDO $pdo, int $divisionId, int $limit): array
{
    $stmt = $pdo->prepare('SELECT product_id, name, harga FROM product WHERE division_id = ? ORDER BY product_id LIMIT ' . (int) $limit);
    $stmt->execute([$divisionId]);
    return $stmt->fetchAll();
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
    }
    $upsertItem = $pdo->prepare(
        'INSERT INTO po_item (po_batch_id, product_id, po_awal, po_revisi, pb) VALUES (?, ?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal), po_revisi = VALUES(po_revisi)'
    );
    $findItem = $pdo->prepare('SELECT po_item_id FROM po_item WHERE po_batch_id = ? AND product_id = ?');
    $upsertStoreItem = $pdo->prepare(
        'INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal), po_revisi = VALUES(po_revisi)'
    );
    foreach ($items as $productId => $d) {
        $upsertItem->execute([$batchId, $productId, $d['poAwal'] ?? 0.0, $d['poRevisi'] ?? 0.0]);
        $findItem->execute([$batchId, $productId]);
        $poItemId = (int) $findItem->fetchColumn();
        $upsertStoreItem->execute([$poItemId, $storeId, $d['poAwal'] ?? 0.0, $d['poRevisi'] ?? 0.0]);
    }
    return $batchId;
}

function createSubmittedProduction(HttpInv $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual): void
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

function stockUpForDelivery(HttpInv $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual, float $fgQty): void
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

function createDoDraft(HttpInv $http, string $csrf, string $tanggal, int $storeId): array
{
    $r = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeId], array_merge(['X-CSRF-Token' => $csrf], idemKey('do-create')));
    expect($r['status'] === 200, 'DO create failed: ' . json_encode($r['json']));
    return ['doId' => (int) $r['json']['data']['doId'], 'docNo' => $r['json']['data']['docNo'], 'version' => (int) $r['json']['data']['version']];
}

/** Full PO -> Production -> FG -> DO -> manual ship, leaving ONE real shipment. */
function setupShipmentForStore(HttpInv $adminHttp, string $adminCsrf, PDO $pdo, int $factoryId, int $divisionId, int $storeId, string $tanggal, int $productId, float $qty): array
{
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $factoryId, $divisionId, $tanggal, $storeId, $productId, $qty, $qty, $qty);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeId);
    $ship = $adminHttp->request('POST', "/api/do/{$do['doId']}/ship", ['expectedVersion' => $do['version'], 'items' => [['productId' => $productId, 'actualQty' => $qty]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('inv-ship')));
    expect($ship['status'] === 200, 'setup ship failed: ' . json_encode($ship['json']));
    return ['doId' => $do['doId'], 'shipmentId' => (int) $ship['json']['data']['shipmentId']];
}

/** Ships + confirms (no reject) a shipment for $storeId/$productId/$qty, leaving it 'confirmed_ok' and immediately invoice-eligible. */
function shipAndConfirmClean(HttpInv $adminHttp, string $adminCsrf, PDO $pdo, int $factoryId, int $divisionId, int $storeId, string $storeToken, string $tanggal, int $productId, float $qty): array
{
    $fx = setupShipmentForStore($adminHttp, $adminCsrf, $pdo, $factoryId, $divisionId, $storeId, $tanggal, $productId, $qty);
    $anon = new HttpInv($GLOBALS['baseUrl']);
    $detail = $anon->request('GET', "/api/store/{$storeToken}/receipts/{$fx['shipmentId']}");
    $itemId = $detail['json']['data']['items'][0]['shipmentItemId'];
    $r = $anon->request('POST', "/api/store/{$storeToken}/receipts/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Test', 'items' => [['shipmentItemId' => $itemId, 'receivedGood' => $qty, 'reject' => 0, 'shortage' => 0]],
    ], idemKey('inv-confirm'));
    expect($r['status'] === 200 && $r['json']['data']['status'] === 'confirmed_ok', 'expected confirmed_ok, got ' . json_encode($r['json']));
    return $fx;
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

// ---------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------
$pdo = new PDO("mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4", $runtimeUser, $runtimePass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$adminHttp = new HttpInv($baseUrl);
$adminCsrf = login($adminHttp, $adminUser, $adminPass);
$adminUserId = (int) $pdo->query("SELECT user_id FROM users WHERE username = " . $pdo->quote($adminUser))->fetchColumn();

$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
expect($karangtengahId > 0, 'expected Karangtengah factory seeded');
$rotiBollenDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
expect($rotiBollenDivId > 0, 'expected Roti & Bollen division seeded');
$storeA = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
expect($storeA > 0, 'expected P2 TEST STORE A fixture seeded');
$storeB = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE B'")->fetchColumn();
expect($storeB > 0, 'expected P2 TEST STORE B fixture seeded');

$tokenA = issuePortalToken($pdo, $storeA, $adminUserId);
$tokenB = issuePortalToken($pdo, $storeB, $adminUserId);

$rotiProducts = productsInDivision($pdo, $rotiBollenDivId, 40);
expect(count($rotiProducts) >= 40, 'expected enough katalog products after bootstrap');
$pool = $rotiProducts;
function nextProduct(): array { global $pool; $p = array_shift($pool); expect($p !== null, 'ran out of pooled test products'); return $p; }

// ===================================================================
// INV-01/02 — empty window
// ===================================================================
runTest('INV-01 Preview with nothing eligible returns empty items/zero counts', function () use ($adminHttp, $storeA) {
    $r = $adminHttp->request('GET', '/api/invoices/preview?storeId=' . $storeA . '&dateFrom=2026-01-01&dateTo=2026-01-02');
    expect($r['status'] === 200, 'expected 200, got ' . json_encode($r['json']));
    expect($r['json']['data']['items'] === [], 'expected empty items');
    expect((float) $r['json']['data']['total'] === 0.0, 'expected total 0');
    expect($r['json']['data']['shipmentCount'] === 0, 'expected shipmentCount 0');
});

runTest('INV-02 Generate with nothing eligible returns 400 EMPTY_INVOICE', function () use ($adminHttp, $adminCsrf, $storeA) {
    $r = $adminHttp->request('POST', '/api/invoices', ['storeId' => $storeA, 'dateFrom' => '2026-01-01', 'dateTo' => '2026-01-02'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('inv02')));
    expect($r['status'] === 400 && ($r['json']['code'] ?? null) === 'EMPTY_INVOICE', 'expected 400 EMPTY_INVOICE, got ' . json_encode($r['json']));
});

// ===================================================================
// INV-03/04 — happy path math + double-billing exclusion
// ===================================================================
$invP1 = null;
$invGeneratedId = null;
runTest('INV-03 Preview reflects a clean confirmed shipment with correct qty/harga/subtotal math', function () use (&$invP1, $adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $tokenA) {
    $p = nextProduct();
    $invP1 = $p;
    shipAndConfirmClean($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $tokenA, '2026-02-01', (int) $p['product_id'], 10.0);
    $r = $adminHttp->request('GET', '/api/invoices/preview?storeId=' . $storeA . '&dateFrom=2026-02-01&dateTo=2026-02-28');
    expect($r['status'] === 200, 'expected 200, got ' . json_encode($r['json']));
    expect($r['json']['data']['shipmentCount'] === 1, 'expected 1 eligible shipment, got ' . json_encode($r['json']['data']));
    $items = $r['json']['data']['items'];
    expect(count($items) === 1, 'expected 1 line item');
    expect((float) $items[0]['qtyDo'] === 10.0 && (float) $items[0]['qtyInvoice'] === 10.0, 'expected qtyDo=qtyInvoice=10, got ' . json_encode($items[0]));
    $expectedSubtotal = round(10.0 * (float) $p['harga'], 2);
    expect(abs((float) $items[0]['subtotal'] - $expectedSubtotal) < 0.01, "expected subtotal {$expectedSubtotal}, got " . json_encode($items[0]));
    expect(abs((float) $r['json']['data']['total'] - $expectedSubtotal) < 0.01, 'expected total to match the single line subtotal');
});

runTest('INV-04 Generate persists the invoice and the SAME shipment is never eligible again (no double-billing)', function () use (&$invGeneratedId, $adminHttp, $adminCsrf, $storeA) {
    $r = $adminHttp->request('POST', '/api/invoices', ['storeId' => $storeA, 'dateFrom' => '2026-02-01', 'dateTo' => '2026-02-28'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('inv04')));
    expect($r['status'] === 200, 'generate failed: ' . json_encode($r['json']));
    expect(preg_match('#^INV/KRM/\d{3}/[IVX]+/\d{4}$#', $r['json']['data']['invoiceNumber']) === 1, 'unexpected invoice number format: ' . $r['json']['data']['invoiceNumber']);
    $invGeneratedId = (int) $r['json']['data']['invoiceId'];

    $after = $adminHttp->request('GET', '/api/invoices/preview?storeId=' . $storeA . '&dateFrom=2026-02-01&dateTo=2026-02-28');
    expect($after['json']['data']['items'] === [], 'expected the just-billed shipment to be excluded from a fresh preview');
    expect($after['json']['data']['shipmentCount'] === 0, 'expected 0 eligible shipments after billing');
});

runTest('INV-05 getDetail() DTO matches the print-template contract (single-shipment invoice carries a populated reference)', function () use (&$invGeneratedId, $adminHttp, &$invP1) {
    $r = $adminHttp->request('GET', "/api/invoices/{$invGeneratedId}");
    expect($r['status'] === 200, 'expected 200, got ' . json_encode($r['json']));
    $dto = $r['json']['data'];
    expect($dto['customer']['storeName'] !== '', 'expected a non-empty storeName');
    expect(count($dto['items']) === 1 && $dto['items'][0]['productName'] === $invP1['name'], 'expected the one invoiced product to appear');
    expect(trim((string) ($dto['reference']['shipmentNumber'] ?? '')) !== '', 'expected a populated reference for a single-shipment invoice');
    expect(isset($dto['summary']['total']), 'expected summary.total');
});

// ===================================================================
// INV-06/07 — Reject excluded until Admin verifies
// ===================================================================
$rejectReceiptId = null;
$rejectShipment = null;
runTest('INV-06 A shipment with Reject (confirmed_discrepancy, not yet Admin-verified) is excluded from preview', function () use (&$rejectReceiptId, &$rejectShipment, $adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $tokenA, $baseUrl) {
    $p = nextProduct();
    $fx = setupShipmentForStore($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-03-01', (int) $p['product_id'], 8.0);
    $anon = new HttpInv($baseUrl);
    $detail = $anon->request('GET', "/api/store/{$tokenA}/receipts/{$fx['shipmentId']}");
    $itemId = $detail['json']['data']['items'][0]['shipmentItemId'];
    $r = $anon->requestMultipart('POST', "/api/store/{$tokenA}/receipts/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Test', 'note' => 'ada reject',
        'items' => json_encode([['shipmentItemId' => $itemId, 'receivedGood' => 6, 'reject' => 2, 'shortage' => 0]]),
    ], ['evidence[]' => fakeEvidenceImage()], idemKey('inv06'));
    expect($r['status'] === 200 && $r['json']['data']['status'] === 'confirmed_discrepancy', 'expected confirmed_discrepancy, got ' . json_encode($r['json']));

    $preview = $adminHttp->request('GET', '/api/invoices/preview?storeId=' . $storeA . '&dateFrom=2026-03-01&dateTo=2026-03-31');
    expect($preview['json']['data']['items'] === [], 'expected the unverified-discrepancy shipment to be excluded from preview');

    $rejectReceiptId = (int) $pdo->query("SELECT shipment_receipt_id FROM shipment_receipt WHERE shipment_id = {$fx['shipmentId']}")->fetchColumn();
    $rejectShipment = $fx;
});

runTest('INV-07 After Admin verifies the discrepancy, the invoice uses received_good_qty net of Reject (Reject automatically reduces the invoice)', function () use (&$rejectReceiptId, $adminHttp, $adminCsrf, $storeA) {
    $verify = $adminHttp->request('POST', "/api/admin/receipts/{$rejectReceiptId}/verify", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('inv07verify')));
    expect($verify['status'] === 200, 'admin verify failed: ' . json_encode($verify['json']));

    $preview = $adminHttp->request('GET', '/api/invoices/preview?storeId=' . $storeA . '&dateFrom=2026-03-01&dateTo=2026-03-31');
    expect(count($preview['json']['data']['items']) === 1, 'expected the now-verified shipment to be eligible, got ' . json_encode($preview['json']['data']));
    // shipped 8, reject 2 -> received_good_qty = 6, so Reject is already excluded by construction.
    expect((float) $preview['json']['data']['items'][0]['qtyInvoice'] === 6.0, 'expected qtyInvoice=6 (8 shipped minus 2 reject), got ' . json_encode($preview['json']['data']['items'][0]));

    $gen = $adminHttp->request('POST', '/api/invoices', ['storeId' => $storeA, 'dateFrom' => '2026-03-01', 'dateTo' => '2026-03-31'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('inv07gen')));
    expect($gen['status'] === 200, 'generate failed: ' . json_encode($gen['json']));
});

// ===================================================================
// INV-08/09 — Mutasi moves billable qty between the two stores' invoices
// ===================================================================
$mutasiCtx = null;
// mutasi_request.confirmed_at is a REAL wall-clock DATETIME (set at the
// moment the destination confirms), unlike shipment.tanggal which is a
// plain business-date field the fixture sets directly — so the window
// below must cover BOTH the fixture shipment date AND the real "now"
// moment this test actually runs, or the Mutasi side would never be
// eligible. +1 day guards against a UTC_TIMESTAMP()/local-date boundary
// mismatch right around midnight.
$mutasiWindowFrom = '2026-04-01';
$mutasiWindowTo = date('Y-m-d', strtotime('+1 day'));
if ($mutasiWindowTo < '2026-04-30') {
    $mutasiWindowTo = '2026-04-30';
}
runTest('INV-08 A completed Mutasi reduces the SOURCE store\'s invoice and adds to the DESTINATION store\'s invoice (no shipment on the destination side)', function () use (&$mutasiCtx, $adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $storeB, $tokenA, $tokenB, $baseUrl, $mutasiWindowFrom, $mutasiWindowTo) {
    $p = nextProduct();
    shipAndConfirmClean($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $tokenA, '2026-04-01', (int) $p['product_id'], 20.0);

    $anon = new HttpInv($baseUrl);
    $create = $anon->requestMultipart('POST', "/api/store/{$tokenA}/mutasi", [
        'mutasi' => json_encode(['destinationStoreId' => $storeB, 'productId' => $p['product_id'], 'qty' => 5]),
    ], ['evidence[]' => fakeEvidenceImage()], idemKey('inv08create'));
    expect($create['status'] === 200, 'mutasi create failed: ' . json_encode($create['json']));
    $mutasiId = $create['json']['data']['mutasiId'];
    $confirm = $anon->request('POST', "/api/store/{$tokenB}/mutasi/{$mutasiId}/confirm", ['qtyReceived' => 5], idemKey('inv08confirm'));
    expect($confirm['status'] === 200 && $confirm['json']['data']['status'] === 'completed', 'expected mutasi completed on exact-match confirm, got ' . json_encode($confirm['json']));

    $previewA = $adminHttp->request('GET', '/api/invoices/preview?storeId=' . $storeA . '&dateFrom=' . $mutasiWindowFrom . '&dateTo=' . $mutasiWindowTo);
    expect($previewA['json']['data']['mutasiOutCount'] === 1, 'expected 1 mutasi-out for store A, got ' . json_encode($previewA['json']['data']));
    expect((float) $previewA['json']['data']['items'][0]['qtyDo'] === 20.0 && (float) $previewA['json']['data']['items'][0]['qtyInvoice'] === 15.0, 'expected store A qtyDo=20 qtyInvoice=15 (20 shipped - 5 mutated out), got ' . json_encode($previewA['json']['data']['items'][0]));

    $previewB = $adminHttp->request('GET', '/api/invoices/preview?storeId=' . $storeB . '&dateFrom=' . $mutasiWindowFrom . '&dateTo=' . $mutasiWindowTo);
    expect($previewB['json']['data']['mutasiInCount'] === 1, 'expected 1 mutasi-in for store B, got ' . json_encode($previewB['json']['data']));
    expect($previewB['json']['data']['shipmentCount'] === 0, 'expected 0 shipments for store B (mutasi-only)');
    expect((float) $previewB['json']['data']['items'][0]['qtyDo'] === 0.0 && (float) $previewB['json']['data']['items'][0]['qtyInvoice'] === 5.0, 'expected store B qtyDo=0 qtyInvoice=5 (mutasi-in only), got ' . json_encode($previewB['json']['data']['items'][0]));

    $mutasiCtx = ['productId' => (int) $p['product_id'], 'mutasiId' => $mutasiId];
});

runTest('INV-09 Generating both stores\' invoices consumes the Mutasi exactly once per direction (never double-counted on either side)', function () use (&$mutasiCtx, $adminHttp, $adminCsrf, $storeA, $storeB, $pdo, $mutasiWindowFrom, $mutasiWindowTo) {
    $genA = $adminHttp->request('POST', '/api/invoices', ['storeId' => $storeA, 'dateFrom' => $mutasiWindowFrom, 'dateTo' => $mutasiWindowTo], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('inv09a')));
    expect($genA['status'] === 200, 'generate A failed: ' . json_encode($genA['json']));
    $genB = $adminHttp->request('POST', '/api/invoices', ['storeId' => $storeB, 'dateFrom' => $mutasiWindowFrom, 'dateTo' => $mutasiWindowTo], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('inv09b')));
    expect($genB['status'] === 200, 'generate B failed: ' . json_encode($genB['json']));

    $outRows = (int) $pdo->query("SELECT COUNT(*) FROM invoice_mutasi WHERE mutasi_request_id = {$mutasiCtx['mutasiId']} AND direction = 'out'")->fetchColumn();
    $inRows = (int) $pdo->query("SELECT COUNT(*) FROM invoice_mutasi WHERE mutasi_request_id = {$mutasiCtx['mutasiId']} AND direction = 'in'")->fetchColumn();
    expect($outRows === 1 && $inRows === 1, "expected exactly 1 'out' and 1 'in' invoice_mutasi row, got out={$outRows} in={$inRows}");

    // Re-previewing either store for the same window must now show this mutasi gone from the eligible pool.
    $previewA = $adminHttp->request('GET', '/api/invoices/preview?storeId=' . $storeA . '&dateFrom=' . $mutasiWindowFrom . '&dateTo=' . $mutasiWindowTo);
    expect($previewA['json']['data']['mutasiOutCount'] === 0, 'expected the mutasi to no longer be eligible for store A after billing');
});

// ===================================================================
// INV-10 — Void releases the pool
// ===================================================================
runTest('INV-10 Void hard-deletes the invoice and releases its shipment back into the eligible pool', function () use (&$invGeneratedId, $adminHttp, $adminCsrf, $storeA) {
    $void = $adminHttp->request('POST', "/api/invoices/{$invGeneratedId}/void", ['reason' => 'test void'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('inv10void')));
    expect($void['status'] === 200 && $void['json']['data']['voided'] === true, 'void failed: ' . json_encode($void['json']));

    $show = $adminHttp->request('GET', "/api/invoices/{$invGeneratedId}");
    expect($show['status'] === 404, 'expected 404 after void, got ' . $show['status']);

    $preview = $adminHttp->request('GET', '/api/invoices/preview?storeId=' . $storeA . '&dateFrom=2026-02-01&dateTo=2026-02-28');
    expect(count($preview['json']['data']['items']) === 1, 'expected the voided invoice\'s shipment to be eligible again, got ' . json_encode($preview['json']['data']));
});

runTest('INV-11 Void of a nonexistent invoice returns 404', function () use ($adminHttp, $adminCsrf) {
    $r = $adminHttp->request('POST', '/api/invoices/999999999/void', ['reason' => 'x'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('inv11')));
    expect($r['status'] === 404, 'expected 404, got ' . $r['status']);
});

// ===================================================================
// INV-12 — Cross-store isolation
// ===================================================================
runTest('INV-12 Store A\'s eligible shipment never appears in Store B\'s preview', function () use ($adminHttp, $storeB) {
    $r = $adminHttp->request('GET', '/api/invoices/preview?storeId=' . $storeB . '&dateFrom=2026-02-01&dateTo=2026-02-28');
    expect($r['json']['data']['items'] === [], 'expected store B to see NONE of store A\'s re-released shipment, got ' . json_encode($r['json']['data']));
});

// ===================================================================
// INV-13 — Idempotency
// ===================================================================
runTest('INV-13 Generate replays the same response for a reused Idempotency-Key (never double-generates)', function () use ($adminHttp, $adminCsrf, $storeA, $pdo) {
    $before = (int) $pdo->query('SELECT COUNT(*) FROM invoice')->fetchColumn();
    $key = idemKey('inv13');
    $r1 = $adminHttp->request('POST', '/api/invoices', ['storeId' => $storeA, 'dateFrom' => '2026-02-01', 'dateTo' => '2026-02-28'], array_merge(['X-CSRF-Token' => $adminCsrf], $key));
    expect($r1['status'] === 200, 'first generate failed: ' . json_encode($r1['json']));
    $r2 = $adminHttp->request('POST', '/api/invoices', ['storeId' => $storeA, 'dateFrom' => '2026-02-01', 'dateTo' => '2026-02-28'], array_merge(['X-CSRF-Token' => $adminCsrf], $key));
    expect($r2['status'] === 200, 'replayed generate failed: ' . json_encode($r2['json']));
    expect($r1['json']['data']['invoiceId'] === $r2['json']['data']['invoiceId'], 'expected the replayed response to carry the SAME invoiceId');
    $after = (int) $pdo->query('SELECT COUNT(*) FROM invoice')->fetchColumn();
    expect($after === $before + 1, "expected exactly 1 new invoice row, before={$before} after={$after}");
});

// ===================================================================
// INV-14 — List filter
// ===================================================================
runTest('INV-14 GET /api/invoices filters by storeId/date range', function () use ($adminHttp, $storeA, $storeB) {
    $onlyA = $adminHttp->request('GET', '/api/invoices?storeId=' . $storeA);
    expect($onlyA['status'] === 200, 'list failed: ' . json_encode($onlyA['json']));
    foreach ($onlyA['json']['data'] as $row) {
        expect((int) $row['storeId'] === $storeA, 'expected every row to belong to store A, got ' . json_encode($row));
    }
    $outOfRange = $adminHttp->request('GET', '/api/invoices?storeId=' . $storeA . '&dateFrom=2099-01-01&dateTo=2099-01-02');
    expect($outOfRange['json']['data'] === [], 'expected no rows for a date range with nothing in it');
});

// ===================================================================
// INV-15 — Role gate
// ===================================================================
runTest('INV-15 A non-ADMIN role (PRODUCTION) is forbidden from every /api/invoices endpoint', function () use ($adminHttp, $adminCsrf, $baseUrl, $storeA) {
    $username = 'inv_prod_' . uniqid('', true);
    $password = 'ProdPassword123!';
    $create = $adminHttp->request('POST', '/api/users', [
        'username' => $username, 'fullName' => 'Invoice Test Production', 'password' => $password, 'passwordConfirm' => $password, 'roles' => ['PRODUCTION'],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('inv15create')));
    expect($create['status'] === 201, 'user create failed: ' . json_encode($create['json']));

    $prodHttp = new HttpInv($baseUrl);
    login($prodHttp, $username, $password);
    $r = $prodHttp->request('GET', '/api/invoices');
    expect($r['status'] === 403, 'expected 403 for a PRODUCTION-role user, got ' . $r['status']);
    $r2 = $prodHttp->request('GET', '/api/invoices/preview?storeId=' . $storeA . '&dateFrom=2026-02-01&dateTo=2026-02-28');
    expect($r2['status'] === 403, 'expected 403 on preview for a PRODUCTION-role user, got ' . $r2['status']);
});

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
$failed = array_filter($results, static fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests, ' . count($failed) . " failed.\n");
exit(count($failed) === 0 ? 0 : 1);
