<?php

declare(strict_types=1);

/**
 * Reject di Toko integration suite (migration 0020) — RJT-01..11. Run
 * via api/tests/run-reject-toko.sh, which stands up a disposable local
 * MariaDB, applies ALL migrations 0001-0020, bootstraps realistic master
 * data, then drives the real Portal "Lapor Barang" + admin + invoice API against
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


$today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
$future = (new DateTimeImmutable('+5 days', new DateTimeZone('UTC')))->format('Y-m-d');
$past = (new DateTimeImmutable('-1 day', new DateTimeZone('UTC')))->format('Y-m-d');
$winFrom = '2026-01-01';
$winTo = (new DateTimeImmutable('+30 days', new DateTimeZone('UTC')))->format('Y-m-d');

function lapor(string $token, array $payload, bool $withPhoto = true): array
{
    $anon = new HttpInv($GLOBALS['baseUrl']);
    return $anon->requestMultipart('POST', "/api/store/{$token}/retur", ['retur' => json_encode($payload)], $withPhoto ? ['evidence[]' => fakeEvidenceImage()] : [], idemKey('lapor'));
}
function adminPost(HttpInv $http, string $csrf, string $path, array $body): array
{
    return $http->request('POST', $path, $body, array_merge(['X-CSRF-Token' => $csrf], idemKey('adm')));
}
function previewA(HttpInv $http, int $storeId, string $from, string $to): array
{
    $r = $http->request('GET', "/api/invoices/preview?storeId={$storeId}&dateFrom={$from}&dateTo={$to}");
    expect($r['status'] === 200, 'preview failed: ' . json_encode($r['json']));
    return $r['json']['data'];
}
function lineFor(array $preview, int $productId): ?array
{
    foreach ($preview['items'] as $it) if ((int) $it['productId'] === $productId) return $it;
    return null;
}

$pShip = nextProduct();
$pShipId = (int) $pShip['product_id'];
$pdo->prepare('UPDATE product SET harga = 5000 WHERE product_id = ?')->execute([$pShipId]);

runTest('RJT-01 Reject di Toko without expDate is refused (EXP_DATE_REQUIRED)', function () use ($tokenA, $pShipId, $today) {
    $r = lapor($tokenA, ['jenis' => 'reject_toko', 'productId' => $pShipId, 'qty' => 2, 'kondisi' => 'jamur', 'returDate' => $today]);
    expect($r['status'] === 400 && ($r['json']['code'] ?? null) === 'EXP_DATE_REQUIRED', 'got ' . json_encode($r['json']));
});

runTest('RJT-02 Reject di Toko already past its expiry is refused (ALREADY_EXPIRED → use Retur)', function () use ($tokenA, $pShipId, $today, $past) {
    $r = lapor($tokenA, ['jenis' => 'reject_toko', 'productId' => $pShipId, 'qty' => 2, 'kondisi' => 'jamur', 'expDate' => $past, 'returDate' => $today]);
    expect($r['status'] === 400 && ($r['json']['code'] ?? null) === 'ALREADY_EXPIRED', 'got ' . json_encode($r['json']));
    $r2 = lapor($tokenA, ['jenis' => 'reject_toko', 'productId' => $pShipId, 'qty' => 2, 'kondisi' => 'jamur', 'expDate' => $past, 'returDate' => $today], false);
    expect($r2['status'] === 400 && ($r2['json']['code'] ?? null) === 'EVIDENCE_REQUIRED', 'photo still mandatory, got ' . json_encode($r2['json']));
});

$rejectId = null;
$returId = null;
runTest('RJT-03 Valid Reject di Toko and Retur are both stored, told apart by jenis and doc prefix', function () use (&$rejectId, &$returId, $tokenA, $pShipId, $today, $future) {
    $r = lapor($tokenA, ['jenis' => 'reject_toko', 'productId' => $pShipId, 'qty' => 2, 'kondisi' => 'jamur', 'expDate' => $future, 'returDate' => $today]);
    expect($r['status'] === 200, 'reject create failed: ' . json_encode($r['json']));
    $d = $r['json']['data'];
    expect($d['jenis'] === 'reject_toko' && str_starts_with($d['docNo'], 'RJT-') && $d['kondisi'] === 'jamur' && $d['expDate'] === $future, 'bad dto ' . json_encode($d));
    expect($d['reason'] === 'Berjamur', 'reason should default to the kondisi label');
    $rejectId = (int) $d['returId'];

    $r2 = lapor($tokenA, ['productId' => $pShipId, 'qty' => 3, 'reason' => 'Tidak laku', 'returDate' => $today]);
    expect($r2['status'] === 200 && $r2['json']['data']['jenis'] === 'retur' && str_starts_with($r2['json']['data']['docNo'], 'RETUR-'), 'retur create failed: ' . json_encode($r2['json']));
    $returId = (int) $r2['json']['data']['returId'];
});

runTest('RJT-04 Admin list filters by jenis (Reject page never shows Retur and vice versa)', function () use (&$rejectId, &$returId, $adminHttp) {
    $rj = $adminHttp->request('GET', '/api/admin/retur?jenis=reject_toko')['json']['data'];
    $rt = $adminHttp->request('GET', '/api/admin/retur?jenis=retur')['json']['data'];
    $rjIds = array_column($rj, 'returId');
    $rtIds = array_column($rt, 'returId');
    expect(in_array($rejectId, $rjIds, true) && !in_array($returId, $rjIds, true), 'reject list wrong: ' . json_encode($rjIds));
    expect(in_array($returId, $rtIds, true) && !in_array($rejectId, $rtIds, true), 'retur list wrong: ' . json_encode($rtIds));
});

runTest('RJT-05 Approving a Reject di Toko requires a follow-up (potong/ganti)', function () use (&$rejectId, $adminHttp, $adminCsrf) {
    $r = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$rejectId}/verify", []);
    expect($r['status'] === 400 && ($r['json']['code'] ?? null) === 'TINDAK_LANJUT_REQUIRED', 'got ' . json_encode($r['json']));
});

runTest('RJT-06 Verified Reject (potong) is deducted from the invoice; a verified Retur is NOT', function () use (&$rejectId, &$returId, $adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $tokenA, $pShipId, $today, $winFrom, $winTo) {
    shipAndConfirmClean($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $tokenA, $today, $pShipId, 10.0);
    $before = lineFor(previewA($adminHttp, $storeA, $winFrom, $winTo), $pShipId);
    expect($before !== null && (float) $before['qtyInvoice'] === 10.0, 'expected 10 billable before any decision, got ' . json_encode($before));

    $v = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$rejectId}/verify", ['tindakLanjut' => 'potong']);
    expect($v['status'] === 200 && $v['json']['data']['tindakLanjut'] === 'potong' && $v['json']['data']['penggantiOrderId'] === null, 'verify failed: ' . json_encode($v['json']));
    $v2 = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$returId}/verify", []);
    expect($v2['status'] === 200, 'retur verify failed: ' . json_encode($v2['json']));

    $pv = previewA($adminHttp, $storeA, $winFrom, $winTo);
    $line = lineFor($pv, $pShipId);
    expect($line !== null && (float) $line['qtyInvoice'] === 8.0, 'expected 10 - 2 reject = 8 (retur 3 NOT deducted), got ' . json_encode($line));
    expect((float) $line['subtotal'] === 40000.0, 'expected 8 x 5000, got ' . json_encode($line));
    expect(count($pv['rejectToko']) === 1 && (float) $pv['rejectTokoTotal'] === 10000.0, 'expected one 10.000 reject deduction, got ' . json_encode($pv['rejectToko']));
});

$invId = null;
runTest('RJT-07 Generate consumes the reject exactly once (invoice detail lists it, next preview does not)', function () use (&$invId, &$rejectId, $adminHttp, $adminCsrf, $pdo, $storeA, $winFrom, $winTo) {
    $g = adminPost($adminHttp, $adminCsrf, '/api/invoices', ['storeId' => $storeA, 'dateFrom' => $winFrom, 'dateTo' => $winTo]);
    expect($g['status'] === 200, 'generate failed: ' . json_encode($g['json']));
    $invId = (int) $g['json']['data']['invoiceId'];
    expect((float) $g['json']['data']['summary']['total'] === 40000.0, 'expected total 40.000, got ' . json_encode($g['json']['data']['summary']));
    expect(count($g['json']['data']['rejectToko']) === 1, 'detail should list the reject');
    $linked = (int) $pdo->query("SELECT invoice_id FROM retur_request WHERE retur_request_id = {$rejectId}")->fetchColumn();
    expect($linked === $invId, 'reject should be linked to the invoice');
    expect(previewA($adminHttp, $storeA, $winFrom, $winTo)['rejectToko'] === [], 'reject must not be deducted twice');
});

runTest('RJT-08 Voiding the invoice releases the reject back for the next invoice', function () use (&$invId, &$rejectId, $adminHttp, $adminCsrf, $pdo, $storeA, $winFrom, $winTo) {
    $r = adminPost($adminHttp, $adminCsrf, "/api/invoices/{$invId}/void", ['reason' => 'tes']);
    expect($r['status'] === 200, 'void failed: ' . json_encode($r['json']));
    $linked = $pdo->query("SELECT invoice_id FROM retur_request WHERE retur_request_id = {$rejectId}")->fetchColumn();
    expect($linked === null, 'invoice_id should be NULL after void, got ' . var_export($linked, true));
    expect(count(previewA($adminHttp, $storeA, $winFrom, $winTo)['rejectToko']) === 1, 'reject should be eligible again');
    $g = adminPost($adminHttp, $adminCsrf, '/api/invoices', ['storeId' => $storeA, 'dateFrom' => $winFrom, 'dateTo' => $winTo]);
    expect($g['status'] === 200, 're-generate failed: ' . json_encode($g['json']));
});

runTest('RJT-09 Reject verified AFTER the invoice was issued carries into the next invoice (even with no new delivery)', function () use ($adminHttp, $adminCsrf, $storeA, $tokenA, $pShipId, $today, $future, $winFrom, $winTo) {
    $r = lapor($tokenA, ['jenis' => 'reject_toko', 'productId' => $pShipId, 'qty' => 1, 'kondisi' => 'basi', 'expDate' => $future, 'returDate' => $today]);
    expect($r['status'] === 200, 'create failed: ' . json_encode($r['json']));
    $id = (int) $r['json']['data']['returId'];
    $v = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$id}/verify", ['tindakLanjut' => 'potong']);
    expect($v['status'] === 200, 'verify failed');
    $pv = previewA($adminHttp, $storeA, $winFrom, $winTo);
    $line = lineFor($pv, $pShipId);
    expect($line !== null && (float) $line['qtyInvoice'] === -1.0 && (float) $pv['total'] === -5000.0, 'expected a -1 credit line, got ' . json_encode($pv));
    $g = adminPost($adminHttp, $adminCsrf, '/api/invoices', ['storeId' => $storeA, 'dateFrom' => $winFrom, 'dateTo' => $winTo]);
    expect($g['status'] === 200 && (float) $g['json']['data']['summary']['total'] === -5000.0, 'expected a credit invoice of -5.000, got ' . json_encode($g['json']));
});

runTest('RJT-10 Ganti barang creates a Pesanan Khusus Toko replacement (sent to production) and is still deducted', function () use ($adminHttp, $adminCsrf, $storeA, $tokenA, $pShipId, $today, $future, $winFrom, $winTo, $pdo) {
    $r = lapor($tokenA, ['jenis' => 'reject_toko', 'productId' => $pShipId, 'qty' => 4, 'kondisi' => 'rusak', 'expDate' => $future, 'returDate' => $today]);
    $id = (int) $r['json']['data']['returId'];
    $v = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$id}/verify", ['tindakLanjut' => 'ganti', 'requiredDate' => $future]);
    expect($v['status'] === 200, 'verify ganti failed: ' . json_encode($v['json']));
    $orderId = $v['json']['data']['penggantiOrderId'];
    expect(is_int($orderId) && $orderId > 0 && str_starts_with((string) $v['json']['data']['penggantiOrderNo'], 'NPR-'), 'expected a linked replacement order, got ' . json_encode($v['json']['data']));
    $o = $pdo->query("SELECT so.source_type, so.store_id, so.status, so.required_date, si.qty FROM special_order so JOIN special_order_item si ON si.special_order_id = so.special_order_id WHERE so.special_order_id = {$orderId}")->fetch();
    expect($o['source_type'] === 'toko_khusus' && (int) $o['store_id'] === $storeA && $o['status'] === 'sent_to_production' && (float) $o['qty'] === 4.0 && $o['required_date'] === $future, 'bad replacement order ' . json_encode($o));
    $pv = previewA($adminHttp, $storeA, $winFrom, $winTo);
    expect(count($pv['rejectToko']) === 1 && (float) $pv['rejectToko'][0]['qty'] === 4.0, 'ganti must still deduct, got ' . json_encode($pv['rejectToko']));
});

runTest('RJT-11 A decided report cannot be decided again; store Riwayat shows jenis + follow-up', function () use (&$rejectId, $adminHttp, $adminCsrf, $tokenA) {
    $r = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$rejectId}/verify", ['tindakLanjut' => 'ganti']);
    expect($r['status'] === 409, 'expected 409, got ' . json_encode($r['json']));
    $anon = new HttpInv($GLOBALS['baseUrl']);
    $list = $anon->request('GET', "/api/store/{$tokenA}/retur")['json']['data'];
    $jenis = array_count_values(array_column($list, 'jenis'));
    expect(($jenis['reject_toko'] ?? 0) === 3 && ($jenis['retur'] ?? 0) === 1, 'unexpected store list ' . json_encode($jenis));
    $mine = array_values(array_filter($list, static fn ($x) => $x['returId'] === $rejectId))[0];
    expect($mine['tindakLanjut'] === 'potong' && $mine['invoiceNo'] !== null, 'store should see potong + invoice no, got ' . json_encode($mine));
});

$failed = array_filter($results, static fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests, ' . count($failed) . " failed.\n");
exit(count($failed) === 0 ? 0 : 1);
