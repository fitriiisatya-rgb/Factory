<?php

declare(strict_types=1);

/**
 * Reject di Toko integration suite (migration 0020) — RJT-01..14. Run
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

function notaFor(PDO $pdo, int $returId): ?array
{
    $r = $pdo->query("SELECT * FROM nota_potongan WHERE retur_request_id = {$returId}")->fetch();
    return $r ?: null;
}
function readyFor(HttpInv $http, int $storeId): array
{
    $r = $http->request('GET', '/api/invoices/ready');
    expect($r['status'] === 200, 'ready failed: ' . json_encode($r['json']));
    return $r['json']['data'];
}

runTest('RJT-06 Approving a Reject di Toko issues a Nota Potongan (pending its DO invoice); a verified Retur issues none', function () use (&$rejectId, &$returId, $adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $tokenA, $pShipId, $today) {
    shipAndConfirmClean($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $tokenA, $today, $pShipId, 10.0);
    $ready = readyFor($adminHttp, $storeA);
    expect(count(array_filter($ready['deliveries'], static fn ($d) => $d['storeId'] === $storeA && $d['kind'] === 'do')) === 1, 'expected the confirmed DO to be ready, got ' . json_encode($ready));

    $v = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$rejectId}/verify", ['tindakLanjut' => 'potong']);
    expect($v['status'] === 200 && $v['json']['data']['tindakLanjut'] === 'potong', 'verify failed: ' . json_encode($v['json']));
    expect(str_starts_with((string) $v['json']['data']['notaNo'], 'NP/KRM/'), 'expected a nota number, got ' . json_encode($v['json']['data']));
    $n = notaFor($pdo, $rejectId);
    expect($n !== null && (float) $n['total'] === 10000.0 && $n['invoice_id'] === null && $n['shipment_id'] !== null, 'expected a 10.000 nota pending its invoice, got ' . json_encode($n));

    $v2 = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$returId}/verify", []);
    expect($v2['status'] === 200 && notaFor($pdo, $returId) === null, 'a Retur must never get a nota');
});

$invId = null;
runTest('RJT-07 "Terbitkan semua" bills the DO in full and links the waiting nota to that invoice (tagihan bersih = invoice − nota)', function () use (&$invId, &$rejectId, $adminHttp, $adminCsrf, $pdo, $storeA) {
    $g = adminPost($adminHttp, $adminCsrf, '/api/invoices/generate-ready', []);
    expect($g['status'] === 200, 'generate-ready failed: ' . json_encode($g['json']));
    $mine = array_values(array_filter($g['json']['data']['invoices'], static fn ($i) => $i['sourceKind'] === 'do' && (float) $i['total'] === 50000.0));
    expect(count($mine) === 1, 'expected one 50.000 DO invoice (10 x 5000, reject NOT folded in), got ' . json_encode($g['json']['data']));
    $invId = $mine[0]['invoiceId'];
    $n = notaFor($pdo, $rejectId);
    expect((int) $n['invoice_id'] === $invId, 'nota should now point at the DO invoice');
    $d = $adminHttp->request('GET', "/api/invoices/{$invId}")['json']['data'];
    expect(count($d['notas']) === 1 && (float) $d['netTotal'] === 40000.0, 'detail should show the nota and net 40.000, got ' . json_encode(['notas' => $d['notas'], 'net' => $d['netTotal']]));
    expect(readyFor($adminHttp, $storeA)['deliveries'] === [], 'nothing should be ready any more');
});

runTest('RJT-08 A reject approved AFTER its DO was invoiced gets a nota linked to that invoice straight away', function () use (&$invId, $adminHttp, $adminCsrf, $pdo, $tokenA, $pShipId, $today, $future) {
    $r = lapor($tokenA, ['jenis' => 'reject_toko', 'productId' => $pShipId, 'qty' => 1, 'kondisi' => 'basi', 'expDate' => $future, 'returDate' => $today]);
    $id = (int) $r['json']['data']['returId'];
    $v = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$id}/verify", ['tindakLanjut' => 'potong']);
    expect($v['status'] === 200, 'verify failed');
    $n = notaFor($pdo, $id);
    expect((int) $n['invoice_id'] === $invId && (float) $n['total'] === 5000.0, 'expected a 5.000 nota on the issued invoice, got ' . json_encode($n));
    $inv = (float) $pdo->query("SELECT total FROM invoice WHERE invoice_id = {$invId}")->fetchColumn();
    expect($inv === 50000.0, 'the issued invoice itself must never change');
});

runTest('RJT-09 Voiding the DO invoice keeps its notas; re-issuing re-links them', function () use (&$invId, $adminHttp, $adminCsrf, $pdo, $storeA) {
    $r = adminPost($adminHttp, $adminCsrf, "/api/invoices/{$invId}/void", ['reason' => 'tes']);
    expect($r['status'] === 200, 'void failed: ' . json_encode($r['json']));
    expect((int) $pdo->query("SELECT COUNT(*) FROM nota_potongan WHERE invoice_id IS NULL")->fetchColumn() === 2, 'both notas should be waiting again');
    $g = adminPost($adminHttp, $adminCsrf, '/api/invoices/generate-ready', []);
    $new = array_values(array_filter($g['json']['data']['invoices'], static fn ($i) => $i['sourceKind'] === 'do'));
    expect(count($new) === 1, 'expected the DO to be re-invoiced, got ' . json_encode($g['json']));
    $invId = $new[0]['invoiceId'];
    expect((int) $pdo->query("SELECT COUNT(*) FROM nota_potongan WHERE invoice_id = {$invId}")->fetchColumn() === 2, 'both notas should re-link to the new invoice');
});

runTest('RJT-10 Ganti barang creates a Pesanan Khusus Toko replacement (sent to production) and still issues a nota', function () use ($adminHttp, $adminCsrf, $storeA, $tokenA, $pShipId, $today, $future, $pdo) {
    $r = lapor($tokenA, ['jenis' => 'reject_toko', 'productId' => $pShipId, 'qty' => 4, 'kondisi' => 'rusak', 'expDate' => $future, 'returDate' => $today]);
    $id = (int) $r['json']['data']['returId'];
    $v = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$id}/verify", ['tindakLanjut' => 'ganti', 'requiredDate' => $future]);
    expect($v['status'] === 200, 'verify ganti failed: ' . json_encode($v['json']));
    $orderId = $v['json']['data']['penggantiOrderId'];
    expect(is_int($orderId) && $orderId > 0 && str_starts_with((string) $v['json']['data']['penggantiOrderNo'], 'NPR-'), 'expected a linked replacement order, got ' . json_encode($v['json']['data']));
    $o = $pdo->query("SELECT so.source_type, so.store_id, so.status, so.required_date, si.qty FROM special_order so JOIN special_order_item si ON si.special_order_id = so.special_order_id WHERE so.special_order_id = {$orderId}")->fetch();
    expect($o['source_type'] === 'toko_khusus' && (int) $o['store_id'] === $storeA && $o['status'] === 'sent_to_production' && (float) $o['qty'] === 4.0 && $o['required_date'] === $future, 'bad replacement order ' . json_encode($o));
    $n = notaFor($pdo, $id);
    expect($n !== null && (float) $n['total'] === 20000.0, 'ganti must still issue a 4 x 5000 nota, got ' . json_encode($n));
});

runTest('RJT-11 A decided report cannot be decided again; store Riwayat shows the nota', function () use (&$rejectId, $adminHttp, $adminCsrf, $tokenA) {
    $r = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$rejectId}/verify", ['tindakLanjut' => 'ganti']);
    expect($r['status'] === 409, 'expected 409, got ' . json_encode($r['json']));
    $anon = new HttpInv($GLOBALS['baseUrl']);
    $list = $anon->request('GET', "/api/store/{$tokenA}/retur")['json']['data'];
    $jenis = array_count_values(array_column($list, 'jenis'));
    expect(($jenis['reject_toko'] ?? 0) === 3 && ($jenis['retur'] ?? 0) === 1, 'unexpected store list ' . json_encode($jenis));
    $mine = array_values(array_filter($list, static fn ($x) => $x['returId'] === $rejectId))[0];
    expect($mine['tindakLanjut'] === 'potong' && str_starts_with((string) $mine['notaNo'], 'NP/') && $mine['invoiceNo'] !== null, 'store should see the nota + invoice no, got ' . json_encode($mine));
});

runTest('RJT-12 Voiding a nota lets Admin re-issue it exactly once', function () use (&$rejectId, $adminHttp, $adminCsrf, $pdo) {
    $n = notaFor($pdo, $rejectId);
    $v = adminPost($adminHttp, $adminCsrf, "/api/nota-potongan/{$n['nota_potongan_id']}/void", ['reason' => 'koreksi']);
    expect($v['status'] === 200 && notaFor($pdo, $rejectId) === null, 'void failed: ' . json_encode($v['json']));
    $i = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$rejectId}/nota", []);
    expect($i['status'] === 200 && notaFor($pdo, $rejectId) !== null, 're-issue failed: ' . json_encode($i['json']));
    $i2 = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$rejectId}/nota", []);
    expect($i2['status'] === 409 && ($i2['json']['code'] ?? null) === 'NOTA_EXISTS', 'second re-issue must be refused, got ' . json_encode($i2['json']));
});

runTest('RJT-13 Mutasi: destination gets an invoice, source gets a Nota Potongan (never both deducted twice)', function () use ($adminHttp, $adminCsrf, $pdo, $storeA, $storeB, $tokenA, $tokenB, $pShipId) {
    $anon = new HttpInv($GLOBALS['baseUrl']);
    $c = $anon->requestMultipart('POST', "/api/store/{$tokenA}/mutasi", ['mutasi' => json_encode(['destinationStoreId' => $storeB, 'productId' => $pShipId, 'qty' => 2])], ['evidence[]' => fakeEvidenceImage()], idemKey('mut'));
    expect($c['status'] === 200, 'mutasi create failed: ' . json_encode($c['json']));
    $mid = (int) $c['json']['data']['mutasiId'];
    $f = $anon->requestMultipart('POST', "/api/store/{$tokenB}/mutasi/{$mid}/confirm", ['confirmation' => json_encode(['qtyReceived' => 2])], ['evidence[]' => fakeEvidenceImage()], idemKey('mutc'));
    expect($f['status'] === 200, 'mutasi confirm failed: ' . json_encode($f['json']));
    $g = adminPost($adminHttp, $adminCsrf, '/api/invoices/generate-ready', []);
    $mi = array_values(array_filter($g['json']['data']['invoices'], static fn ($i) => $i['sourceKind'] === 'mutasi'));
    expect(count($mi) === 1 && (float) $mi[0]['total'] === 10000.0, 'expected a 10.000 mutasi invoice for the destination, got ' . json_encode($g['json']['data']));
    $mn = array_values(array_filter($g['json']['data']['notas'], static fn ($n) => $n['alasan'] === 'mutasi_keluar'));
    expect(count($mn) === 1 && $mn[0]['storeId'] === $storeA && (float) $mn[0]['total'] === 10000.0, 'expected a 10.000 nota for the source, got ' . json_encode($g['json']['data']['notas']));
    $p = previewA($adminHttp, $storeA, '2026-01-01', '2099-12-31');
    expect($p['mutasiOutCount'] === 0, 'a period invoice must not deduct the same mutasi again');
    $g2 = adminPost($adminHttp, $adminCsrf, '/api/invoices/generate-ready', []);
    expect($g2['json']['data']['invoices'] === [] && $g2['json']['data']['notas'] === [], 'nothing should be issued twice');
});

runTest('RJT-14 Laporan & Invoice pages show invoice − nota; nota prints as NOTA POTONGAN', function () use ($adminHttp, $pdo) {
    $from = '2026-01-01'; $to = '2099-12-31';
    $lap = $adminHttp->request('GET', "/api/_ui-preview/?page=laporan&omzetDateFrom={$from}&omzetDateTo={$to}&flowDateFrom={$from}&flowDateTo={$to}");
    expect($lap['status'] === 200 && str_contains($lap['body'], 'id="omset-per-toko"') && str_contains($lap['body'], 'Tagihan Bersih'), 'laporan should show the net per-toko table');
    expect(!preg_match('/SQLSTATE|Fatal error|Uncaught/', $lap['body']), 'laporan rendered an error');
    $inv = $adminHttp->request('GET', '/api/_ui-preview/?page=invoice');
    expect($inv['status'] === 200 && str_contains($inv['body'], 'id="nota-potongan"') && !preg_match('/SQLSTATE|Fatal error|Uncaught/', $inv['body']), 'invoice page should list notas');
    $nid = (int) $pdo->query('SELECT MAX(nota_potongan_id) FROM nota_potongan')->fetchColumn();
    $pr = $adminHttp->request('GET', "/api/_ui-preview/print-nota.php?notaId={$nid}");
    expect($pr['status'] === 200 && str_contains($pr['body'], 'NOTA POTONGAN'), 'nota print should render');
    $rt = $adminHttp->request('GET', '/api/_ui-preview/?page=reject-toko&status=');
    expect($rt['status'] === 200 && str_contains($rt['body'], 'NP/KRM/') && !preg_match('/SQLSTATE|Fatal error|Uncaught/', $rt['body']), 'reject-toko page should show nota numbers');
});

runTest('RJT-15 finhub integration key: read-only shipment feed matches what the store confirmed; revoke cuts access', function () use ($adminHttp, $adminCsrf, $pdo, $storeA) {
    $k = adminPost($adminHttp, $adminCsrf, '/api/admin/integration-keys', ['name' => 'finhub']);
    expect($k['status'] === 200 && str_starts_with((string) ($k['json']['data']['rawKey'] ?? ''), 'amk_'), 'issuing a key failed: ' . json_encode($k['json']));
    $raw = $k['json']['data']['rawKey']; $kid = (int) $k['json']['data']['id'];
    $stored = (string) $pdo->query("SELECT key_hash FROM integration_api_key WHERE integration_api_key_id = {$kid}")->fetchColumn();
    expect($stored === hash('sha256', $raw) && !str_contains($stored, $raw), 'only the hash of the key may be stored');
    $list = $adminHttp->request('GET', '/api/admin/integration-keys');
    expect(!str_contains($list['body'], $raw) && !str_contains($list['body'], $stored), 'the key list must never expose the key or its hash');

    $row = $pdo->query("SELECT sh.shipment_id, sh.tanggal FROM shipment sh JOIN shipment_receipt r ON r.shipment_id = sh.shipment_id
        WHERE sh.store_id = {$storeA} AND sh.status = 'active' AND sh.shipped_at IS NOT NULL ORDER BY sh.shipment_id LIMIT 1")->fetch();
    expect($row !== false, 'need a confirmed shipment for store A');
    $q = "/api/integration/v1/shipments?from={$row['tanggal']}&to={$row['tanggal']}&storeId={$storeA}";
    $anon = new HttpInv($GLOBALS['baseUrl']);
    $n = $anon->request('GET', $q);
    expect($n['status'] === 401 && ($n['json']['code'] ?? '') === 'MISSING_API_KEY', 'no key must be refused');
    $w = $anon->request('GET', $q, null, ['X-Api-Key' => 'amk_wrong']);
    expect($w['status'] === 401 && ($w['json']['code'] ?? '') === 'INVALID_API_KEY', 'a wrong key must be refused');
    $ok = $anon->request('GET', $q, null, ['X-Api-Key' => $raw]);
    expect($ok['status'] === 200, 'feed with a valid key failed: ' . $ok['body']);
    $mine = array_values(array_filter($ok['json']['data']['shipments'], static fn ($x) => $x['shipmentId'] === (int) $row['shipment_id']));
    expect(count($mine) === 1 && $mine[0]['store']['id'] === $storeA && $mine[0]['receipt']['confirmed'] === true, 'the confirmed shipment must be in the feed');
    $db = $pdo->query("SELECT COALESCE(SUM(ri.received_good_qty),0) g, COALESCE(SUM(ri.reject_qty),0) rj, COALESCE(SUM(ri.shortage_qty),0) sh
        FROM shipment_receipt r JOIN shipment_receipt_item ri ON ri.shipment_receipt_id = r.shipment_receipt_id WHERE r.shipment_id = {$row['shipment_id']}")->fetch();
    $sum = static fn ($f) => array_sum(array_map(static fn ($i) => (float) $i[$f], $mine[0]['items']));
    expect(abs($sum('receivedGoodQty') - (float) $db['g']) < 0.001 && abs($sum('rejectQty') - (float) $db['rj']) < 0.001 && abs($sum('shortageQty') - (float) $db['sh']) < 0.001,
        'feed quantities must equal the stored receipt: ' . json_encode([$sum('receivedGoodQty'), $sum('rejectQty'), $sum('shortageQty'), $db]));
    $b = $anon->request('GET', '/api/integration/v1/shipments?from=2026-01-01&to=2026-03-01', null, ['X-Api-Key' => $raw]);
    expect($b['status'] === 400 && ($b['json']['code'] ?? '') === 'RANGE_TOO_LONG', 'a range over 31 days must be refused');
    $post = $anon->request('POST', $q, [], ['X-Api-Key' => $raw]);
    expect($post['status'] >= 400, 'the feed must not accept writes');

    $rv = adminPost($adminHttp, $adminCsrf, "/api/admin/integration-keys/{$kid}/revoke", []);
    expect($rv['status'] === 200, 'revoke failed: ' . json_encode($rv['json']));
    $after = $anon->request('GET', $q, null, ['X-Api-Key' => $raw]);
    expect($after['status'] === 401, 'a revoked key must be refused');
    $page = $adminHttp->request('GET', '/api/_ui-preview/?page=integrasi');
    expect($page['status'] === 200 && str_contains($page['body'], 'Buat Kunci Baru') && !preg_match('/SQLSTATE|Fatal error|Uncaught/', $page['body']), 'Integrasi Finhub page should render');
});

function shipSpecialAndConfirm(HttpInv $adminHttp, string $adminCsrf, int $factoryId, string $token, array $orderBody, float $qty, ?int $dropStoreId, string $tag): array
{
    $h = ['X-CSRF-Token' => $adminCsrf];
    $c = $adminHttp->request('POST', '/api/special-orders', $orderBody, array_merge($h, idemKey($tag . 'c')));
    expect($c['status'] === 200, "{$tag}: create failed: " . $c['body']);
    $id = (int) $c['json']['data']['orderId']; $itemId = (int) $c['json']['data']['items'][0]['itemId'];
    $cf = $adminHttp->request('POST', "/api/special-orders/{$id}/confirm", ['expectedVersion' => $c['json']['data']['version']], array_merge($h, idemKey($tag . 'cf')));
    $sd = $adminHttp->request('POST', "/api/special-orders/{$id}/send-to-production", ['expectedVersion' => $cf['json']['data']['version']], array_merge($h, idemKey($tag . 'sd')));
    expect($sd['status'] === 200, "{$tag}: send failed: " . $sd['body']);
    $adminHttp->request('POST', "/api/special-orders/{$id}/actual", ['expectedVersion' => $sd['json']['data']['version'], 'items' => [['itemId' => $itemId, 'aktualProduksi' => $qty + 2, 'rejectProduksi' => 0]]], array_merge($h, idemKey($tag . 'a')));
    $v = $adminHttp->request('POST', "/api/special-orders/items/{$itemId}/verify-fg", ['fgVerifiedQty' => $qty + 2], array_merge($h, idemKey($tag . 'v')));
    expect($v['status'] === 200, "{$tag}: verify-fg failed: " . $v['body']);
    $body = ['orderId' => $id, 'factoryId' => $factoryId, 'deliveryMethod' => 'EXTERNAL_COURIER', 'courierProvider' => 'other', 'courierName' => 'Kurir Test'];
    if ($dropStoreId !== null) { $body['dropStoreId'] = $dropStoreId; }
    $tooMuch = $adminHttp->request('POST', '/api/special-order-do', array_merge($body, ['items' => [['itemId' => $itemId, 'qty' => $qty + 1]]]), array_merge($h, idemKey($tag . 'big')));
    expect($tooMuch['status'] === 400, "{$tag}: a special DO must be capped at the ordered qty, got " . $tooMuch['body']);
    $do = $adminHttp->request('POST', '/api/special-order-do', $body, array_merge($h, idemKey($tag . 'do')));
    expect($do['status'] === 200, "{$tag}: DO failed: " . $do['body']);
    $doQty = array_sum(array_map(static fn ($i) => (float) ($i['plannedQty'] ?? $i['qty'] ?? 0), $do['json']['data']['items'] ?? []));
    expect($doQty === 0.0 || abs($doQty - $qty) < 0.001, "{$tag}: DO should carry exactly the ordered {$qty}, got {$doQty}");
    $ho = $adminHttp->request('POST', "/api/special-order-do/{$do['json']['data']['doId']}/courier-handover", ['items' => null], array_merge($h, idemKey($tag . 'ho')));
    expect($ho['status'] === 200, "{$tag}: handover failed: " . $ho['body']);
    $shipmentId = (int) $ho['json']['data']['shipmentId'];
    $anon = new HttpInv($GLOBALS['baseUrl']);
    $detail = $anon->request('GET', "/api/store/{$token}/receipts/{$shipmentId}");
    $items = array_map(static fn ($i) => ['shipmentItemId' => $i['shipmentItemId'], 'receivedGood' => (float) $i['shippedQty'], 'reject' => 0, 'shortage' => 0], $detail['json']['data']['items'] ?? []);
    expect($items !== [], "{$tag}: the drop store should see the shipment: " . $detail['body']);
    $r = $anon->request('POST', "/api/store/{$token}/receipts/{$shipmentId}/confirm", ['receiverName' => 'Test', 'items' => $items], idemKey($tag . 'rc'));
    expect($r['status'] === 200, "{$tag}: confirm failed: " . $r['body']);
    return ['orderId' => $id, 'doId' => (int) $do['json']['data']['doId'], 'shipmentId' => $shipmentId];
}

runTest('RJT-16 Special orders: toko khusus billed at the ORDER price (+biaya/kemasan once); non-toko never billed to its drop bakery; DO capped; Selesai automatic', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahId, $storeA, $tokenA, $future) {
    $p = nextProduct(); $pid = (int) $p['product_id'];
    $pdo->prepare('UPDATE product SET harga = 5000 WHERE product_id = ?')->execute([$pid]);
    $tk = shipSpecialAndConfirm($adminHttp, $adminCsrf, $karangtengahId, $tokenA, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeA, 'orderDate' => $future, 'requiredDate' => $future,
        'items' => [['itemType' => 'existing_product', 'productId' => $pid, 'qty' => 4, 'unitPrice' => 7000, 'charge' => 2000, 'extraPackaging' => 1000]],
    ], 4.0, null, 'rjt16tk');
    $nt = shipSpecialAndConfirm($adminHttp, $adminCsrf, $karangtengahId, $tokenA, [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'cs', 'customerName' => 'RJT16', 'fulfillmentType' => 'pengiriman', 'orderDate' => $future, 'requiredDate' => $future,
        'items' => [['itemType' => 'existing_product', 'productId' => $pid, 'qty' => 2, 'unitPrice' => 9000]],
    ], 2.0, $storeA, 'rjt16nt');

    foreach ([$tk['orderId'], $nt['orderId']] as $oid) {
        $st = (string) $pdo->query("SELECT status FROM special_order WHERE special_order_id = {$oid}")->fetchColumn();
        expect($st === 'completed', "order {$oid} should be Selesai automatically once fully shipped, got {$st}");
    }
    $ready = readyFor($adminHttp, $storeA)['deliveries'];
    expect(count(array_filter($ready, static fn ($d) => $d['kind'] === 'do_khusus' && $d['refId'] === $nt['doId'])) === 0, 'a non-toko DO must never be ready to bill: ' . json_encode($ready));
    expect(count(array_filter($ready, static fn ($d) => $d['kind'] === 'do_khusus' && $d['refId'] === $tk['doId'])) === 1, 'the toko khusus DO should be ready: ' . json_encode($ready));

    $g = adminPost($adminHttp, $adminCsrf, '/api/invoices/generate-ready', []);
    expect($g['status'] === 200, 'generate-ready failed: ' . json_encode($g['json']));
    $tkInv = $pdo->query("SELECT i.invoice_id, i.total FROM invoice i JOIN invoice_shipment s ON s.invoice_id = i.invoice_id WHERE s.shipment_id = {$tk['shipmentId']}")->fetch();
    expect($tkInv !== false && (float) $tkInv['total'] === 31000.0, 'toko khusus invoice should be 4 x 7.000 + 2.000 + 1.000 = 31.000, got ' . json_encode($tkInv));
    $ntInv = $pdo->query("SELECT COUNT(*) FROM invoice_shipment WHERE shipment_id = {$nt['shipmentId']}")->fetchColumn();
    expect((int) $ntInv === 0, 'the non-toko shipment must never be invoiced to the drop bakery');
    $d = $adminHttp->request('GET', "/api/invoices/{$tkInv['invoice_id']}")['json']['data'];
    expect(count($d['items']) === 1 && (float) $d['items'][0]['unitPrice'] === 7000.0 && (float) $d['items'][0]['extraAmount'] === 3000.0, 'invoice line should carry the order price: ' . json_encode($d['items']));

    $page = $adminHttp->request('GET', '/api/_ui-preview/?page=laporan&omzetDateFrom=' . $future . '&omzetDateTo=' . $future);
    expect($page['status'] === 200 && str_contains($page['body'], 'Rp18.000'), 'Omset Non-Toko should count the shipped 2 x 9.000 = Rp18.000');
});

runTest('RJT-17 Runchise stok masuk file: good qty only, mapped/summed lines, exact CSV format, download recorded, own store only, feed fields', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $tokenA, $tokenB, $today) {
    $today = (new DateTimeImmutable('+9 days', new DateTimeZone('UTC')))->format('Y-m-d'); // own date: no other test produces for it
    $p1 = nextProduct(); $p2 = nextProduct(); $p3 = nextProduct();
    $ins = $pdo->prepare('INSERT INTO product_legacy_code (product_id, legacy_code, first_seen_at, created_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $ins->execute([(int) $p1['product_id'], 'RJT17A']);
    $ins->execute([(int) $p2['product_id'], 'RJT17B']);
    $csv = "\u{FEFF}Nama Lokasi,Tanggal Stok,Alasan,Nama Produk,SKU Produk,Jumlah,Nama Unit Produk,Biaya per unit\r\nLocation Name Here,01/01/2026,Contoh,,,1,PCS,\r\n,,,\"ROTI, SPESIAL\",RJT17A,,PCS,\r\n,,,ROTI B,RJT17B,,PCS,\r\n";
    $u = adminPost($adminHttp, $adminCsrf, '/api/admin/runchise/catalog', ['csv' => $csv]);
    expect($u['status'] === 200 && $u['json']['data']['catalogProducts'] === 2 && $u['json']['data']['autoMapped'] >= 2, 'catalog upload failed: ' . $u['body']);
    // p2 is mapped onto p1's Runchise product too -> summed into one line.
    adminPost($adminHttp, $adminCsrf, '/api/admin/runchise/products/' . (int) $p2['product_id'], ['mode' => 'stok', 'sku' => 'RJT17A']);

    $fx = setupShipmentForStore($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $today, (int) $p1['product_id'], 5.0);
    $sid = $fx['shipmentId'];
    $pdo->prepare('INSERT INTO shipment_item (shipment_id, product_id, qty) SELECT ?, ?, 3 FROM DUAL')->execute([$sid, (int) $p2['product_id']]);
    $pdo->prepare('INSERT INTO shipment_item (shipment_id, product_id, qty) SELECT ?, ?, 2 FROM DUAL')->execute([$sid, (int) $p3['product_id']]);
    $anon = new HttpInv($GLOBALS['baseUrl']);
    $pre = $anon->request('GET', "/api/store/{$tokenA}/receipts/{$sid}/runchise");
    expect($pre['status'] === 200 && $pre['json']['data']['ready'] === false, 'no file before confirmation: ' . $pre['body']);
    $detail = $anon->request('GET', "/api/store/{$tokenA}/receipts/{$sid}")['json']['data'];
    $lines = array_map(static function ($i) use ($p1) {
        $rej = (int) $i['productId'] === (int) $p1['product_id'] ? 1.0 : 0.0;
        return ['shipmentItemId' => $i['shipmentItemId'], 'receivedGood' => (float) $i['shippedQty'] - $rej, 'reject' => $rej, 'shortage' => 0];
    }, $detail['items']);
    $c = $anon->requestMultipart('POST', "/api/store/{$tokenA}/receipts/{$sid}/confirm", ['receiverName' => 'RJT17', 'items' => json_encode($lines)], ['evidence[]' => fakeEvidenceImage()], idemKey('rjt17c'));
    expect($c['status'] === 200, 'confirm failed: ' . $c['body']);

    $pdo->prepare('UPDATE store SET runchise_location = NULL WHERE store_id = ?')->execute([$storeA]);
    $nl = $anon->request('GET', "/api/store/{$tokenA}/receipts/{$sid}/runchise")['json']['data'];
    expect($nl['ready'] === false && str_contains((string) $nl['message'], 'Lokasi Runchise belum diatur'), 'store without location must not get a file');
    $bad = adminPost($adminHttp, $adminCsrf, "/api/admin/runchise/stores/{$storeA}", ['location' => 'bangbarung']);
    expect($bad['status'] === 400, 'location must match a Runchise location exactly');
    adminPost($adminHttp, $adminCsrf, "/api/admin/runchise/stores/{$storeA}", ['location' => 'AMOR CAKES BAKERY BANGBARUNG']);

    $pv = $anon->request('GET', "/api/store/{$tokenA}/receipts/{$sid}/runchise")['json']['data'];
    expect($pv['ready'] === true && count($pv['lines']) === 1 && (float) $pv['lines'][0]['qty'] === 7.0 && $pv['lines'][0]['name'] === 'ROTI, SPESIAL',
        'expected one summed line of 4 + 3 = 7 good pcs: ' . json_encode($pv['lines']));
    expect(count($pv['excluded']) === 1 && (int) round($pv['excluded'][0]['qty']) === 2, 'the unmapped product must be listed as not in the file: ' . json_encode($pv['excluded']));
    expect($anon->request('GET', "/api/store/{$tokenB}/receipts/{$sid}/runchise")['status'] === 404, 'another store must not reach this file');

    $ch = curl_init($GLOBALS['baseUrl'] . "/api/store/{$tokenA}/receipts/{$sid}/runchise/download");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
    $raw = (string) curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $head = substr($raw, 0, $hs); $body = substr($raw, $hs);
    $doNo = (string) $detail['docNo'];
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $today)->format('d/m/Y');
    $expected = "Nama Lokasi,Tanggal Stok,Alasan,Nama Produk,SKU Produk,Jumlah,Nama Unit Produk,Biaya per unit\r\n"
        . "AMOR CAKES BAKERY BANGBARUNG,{$d},DO {$doNo},\"ROTI, SPESIAL\",RJT17A,7,PCS,\r\n";
    expect($code === 200 && $body === $expected, "CSV must match exactly. got:\n" . $body . "\nexpected:\n" . $expected);
    expect(str_contains($head, 'filename="stok_masuk_AMORCAKESBAKERYBANGBARUNG_' . $today . '_'), 'file name: ' . $head);

    $after = $anon->request('GET', "/api/store/{$tokenA}/receipts/{$sid}/runchise")['json']['data'];
    expect($after['downloadedAt'] !== null && $after['downloadCount'] === 1 && $after['changedSinceDownload'] === false, 'download must be recorded: ' . json_encode($after));
    $pdo->prepare('UPDATE shipment_receipt SET updated_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE shipment_id = ?')->execute([$sid]);
    expect($anon->request('GET', "/api/store/{$tokenA}/receipts/{$sid}/runchise")['json']['data']['changedSinceDownload'] === true, 'a later receipt change must warn the old file is stale');

    $k = adminPost($adminHttp, $adminCsrf, '/api/admin/integration-keys', ['name' => 'rjt17']);
    $feed = $anon->request('GET', "/api/integration/v1/shipments?from={$today}&to={$today}&storeId={$storeA}", null, ['X-Api-Key' => $k['json']['data']['rawKey']]);
    $mine = array_values(array_filter($feed['json']['data']['shipments'], static fn ($x) => $x['shipmentId'] === $sid));
    expect(count($mine) === 1 && $mine[0]['runchiseLocation'] === 'AMOR CAKES BAKERY BANGBARUNG' && str_ends_with((string) $mine[0]['runchiseDownloadedAt'], 'Z'), 'feed must carry the Runchise fields: ' . json_encode($mine));
    $page = $adminHttp->request('GET', '/api/_ui-preview/?page=runchise');
    expect($page['status'] === 200 && str_contains($page['body'], 'Upload Katalog') && !preg_match('/SQLSTATE|Fatal error|Uncaught/', $page['body']), 'Mapping Runchise page should render');
});

runTest('RJT-18 Serah terima sebagian: produksi tanpa PO, FG langsung dapat yang diserahkan, koreksi tidak di bawah yang sudah diverifikasi, FG submitted terbuka lagi', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId) {
    $day = (new DateTimeImmutable('+11 days', new DateTimeZone('UTC')))->format('Y-m-d'); // own date, no PO
    $p = (int) nextProduct()['product_id'];
    $post = static fn (HttpInv $h, string $c, string $m, string $path, array $b) => $h->request($m, $path, $b, array_merge(['X-CSRF-Token' => $c], idemKey('rjt18')));
    $snap = static function () use ($pdo, $day, $karangtengahId, $p): float {
        $s = $pdo->prepare('SELECT COALESCE(MAX(i.production_actual_snapshot), -1) FROM fg_item i INNER JOIN fg_batch b ON b.fg_batch_id = i.fg_batch_id WHERE b.tanggal = ? AND b.factory_id = ? AND i.product_id = ?');
        $s->execute([$day, $karangtengahId, $p]);
        return (float) $s->fetchColumn();
    };

    $run = $post($adminHttp, $adminCsrf, 'POST', '/api/production', ['tanggal' => $day, 'divisionId' => $rotiBollenDivId]);
    expect($run['status'] === 200, 'division must be able to start before the PO exists: ' . $run['body']);
    $runId = (int) $run['json']['data']['productionRunId'];
    $save = $post($adminHttp, $adminCsrf, 'PATCH', "/api/production/{$runId}", ['expectedVersion' => $run['json']['data']['version'], 'items' => [['productId' => $p, 'actualQty' => 10]]]);
    expect($save['status'] === 200, 'adding a product without PO must work: ' . $save['body']);

    $h1 = $post($adminHttp, $adminCsrf, 'POST', "/api/production/{$runId}/handovers", ['items' => [['productId' => $p, 'qty' => 6]]]);
    expect($h1['status'] === 200 && $h1['json']['data']['seq'] === 1, 'first handover: ' . $h1['body']);
    $over = $post($adminHttp, $adminCsrf, 'POST', "/api/production/{$runId}/handovers", ['items' => [['productId' => $p, 'qty' => 5]]]);
    expect($over['status'] === 400 && $over['json']['code'] === 'HANDOVER_EXCEEDS_PRODUCED', 'only 4 left to hand over: ' . $over['body']);

    $fg = $post($adminHttp, $adminCsrf, 'POST', '/api/fg', ['tanggal' => $day, 'factoryId' => $karangtengahId]);
    expect($fg['status'] === 200, 'FG must open on handed-over goods of a draft run: ' . $fg['body']);
    expect($snap() === 6.0, 'FG source must be the 6 handed over (no separate check step), got ' . $snap());
    expect($adminHttp->request('POST', '/api/production/handovers/1/check', ['items' => []], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('rjt18x')))['status'] === 404, 'the separate check step is gone');

    // FG verifies 5 of the 6 (1 reject recorded in FG Verifikasi) and submits.
    $batchId = (int) $fg['json']['data']['fgBatchId'];
    $b = $adminHttp->request('GET', "/api/fg/{$batchId}")['json']['data'];
    $fs = $post($adminHttp, $adminCsrf, 'PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $b['version'], 'items' => [['productId' => $p, 'fgVerified' => 5, 'packed' => 5]]]);
    expect($fs['status'] === 200, 'fg save: ' . $fs['body']);
    $sub = $post($adminHttp, $adminCsrf, 'POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $fs['json']['data']['version']]);
    expect($sub['status'] === 200, 'fg submit: ' . $sub['body']);

    // Division account (own division only) hands over the rest.
    $u = 'rjt18_div_' . substr(uniqid(), -6);
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())')->execute([$u, password_hash('Rjt18Pass#1', PASSWORD_DEFAULT), $u]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, role_id FROM roles WHERE code = 'PRODUCTION'")->execute([$uid]);
    $pdo->prepare('INSERT INTO user_division_access (user_id, division_id) VALUES (?, ?)')->execute([$uid, $rotiBollenDivId]);
    $div = new HttpInv($GLOBALS['baseUrl']);
    $dc = login($div, $u, 'Rjt18Pass#1');

    $h2 = $post($div, $dc, 'POST', "/api/production/{$runId}/handovers", ['items' => [['productId' => $p, 'qty' => 4]]]);
    expect($h2['status'] === 200 && $h2['json']['data']['seq'] === 2, 'second handover by the division: ' . $h2['body']);
    $st = $pdo->prepare('SELECT status FROM fg_batch WHERE fg_batch_id = ?'); $st->execute([$batchId]);
    expect($st->fetchColumn() === 'reopened', 'a new handover must reopen the submitted FG document');
    expect($snap() === 10.0, 'FG source 6 + 4 = 10, got ' . $snap());

    $hid = (int) $h1['json']['data']['handoverId'];
    $fix = $post($div, $dc, 'PATCH', "/api/production/handovers/{$hid}", ['items' => [['productId' => $p, 'qty' => 3]]]);
    expect($fix['status'] === 200 && (float) $fix['json']['data']['totalQty'] === 3.0, 'division corrects a handover: ' . $fix['body']);
    expect($snap() === 7.0, 'FG source after correction 3 + 4 = 7, got ' . $snap());
    $below = $post($div, $dc, 'PATCH', "/api/production/handovers/{$hid}", ['items' => [['productId' => $p, 'qty' => 0]]]);
    expect($below['status'] === 409 && $below['json']['code'] === 'HANDOVER_BELOW_VERIFIED', 'cannot take back below the 5 FG verified: ' . $below['body']);
    expect($snap() === 7.0, 'refused correction must change nothing, got ' . $snap());

    $r = $div->request('GET', "/api/production/{$runId}")['json']['data'];
    $low = $post($div, $dc, 'PATCH', "/api/production/{$runId}", ['expectedVersion' => $r['version'], 'items' => [['productId' => $p, 'actualQty' => 6]]]);
    expect($low['status'] === 400 && $low['json']['code'] === 'ACTUAL_BELOW_HANDED_OVER', 'sudah jadi cannot drop under the 7 already handed over: ' . $low['body']);

    $list = $div->request('GET', "/api/production/handovers?tanggal={$day}&factoryId={$karangtengahId}");
    expect($list['status'] === 200 && count($list['json']['data']) === 2, 'division sees its 2 handovers: ' . $list['body']);

    // Access: another division may not hand over on this sheet; FG of
    // another factory may not read it.
    $mk = static function (string $role, string $tbl, string $col, int $id) use ($pdo): array {
        $u = 'rjt18_' . strtolower($role) . '_' . substr(uniqid(), -6);
        $pdo->prepare('INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())')->execute([$u, password_hash('Rjt18Pass#1', PASSWORD_DEFAULT), $u]);
        $uid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, role_id FROM roles WHERE code = ?')->execute([$uid, $role]);
        $pdo->prepare("INSERT INTO {$tbl} (user_id, {$col}) VALUES (?, ?)")->execute([$uid, $id]);
        $h = new HttpInv($GLOBALS['baseUrl']);
        return [$h, login($h, $u, 'Rjt18Pass#1')];
    };
    $otherDiv = (int) $pdo->query("SELECT division_id FROM division WHERE factory_id = {$karangtengahId} AND division_id <> {$rotiBollenDivId} AND is_verification = 0 LIMIT 1")->fetchColumn();
    [$od, $odc] = $mk('PRODUCTION', 'user_division_access', 'division_id', $otherDiv);
    $foreign = $post($od, $odc, 'POST', "/api/production/{$runId}/handovers", ['items' => [['productId' => $p, 'qty' => 1]]]);
    expect($foreign['status'] === 403, 'another division must not hand over on this sheet: ' . $foreign['body']);
    expect($od->request('GET', "/api/production/handovers?tanggal={$day}&factoryId={$karangtengahId}")['json']['data'] === [], 'another division sees none of these handovers');
    $cibadakId = (int) $pdo->query("SELECT factory_id FROM factory WHERE factory_id <> {$karangtengahId} LIMIT 1")->fetchColumn();
    [$ofg] = $mk('FG_PACKING', 'user_factory_access', 'factory_id', $cibadakId);
    expect($ofg->request('GET', "/api/production/handovers?tanggal={$day}&factoryId={$karangtengahId}")['status'] === 403, 'FG of another factory must not read these handovers');

    // Submitting the run hands over the rest (10 made, 7 handed): FG gets
    // all 10 at once; a later identical sync must not reopen a submitted FG.
    $r = $div->request('GET', "/api/production/{$runId}")['json']['data'];
    $sr = $post($div, $dc, 'POST', "/api/production/{$runId}/submit", ['expectedVersion' => $r['version']]);
    expect($sr['status'] === 200, 'production submit: ' . $sr['body']);
    expect($snap() === 10.0, 'after submit FG source is the full 10, got ' . $snap());
    $closed = $post($div, $dc, 'POST', "/api/production/{$runId}/handovers", ['items' => [['productId' => $p, 'qty' => 1]]]);
    expect($closed['status'] === 409 && $closed['json']['code'] === 'RUN_NOT_OPEN', 'no handover on a submitted run: ' . $closed['body']);
    $b2 = $adminHttp->request('GET', "/api/fg/{$batchId}")['json']['data'];
    $fs2 = $post($adminHttp, $adminCsrf, 'PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $b2['version'], 'items' => [['productId' => $p, 'fgVerified' => 9, 'packed' => 9, 'reject' => 1, 'notes' => 'reject 1']]]);
    expect($fs2['status'] === 200, 'fg save 2: ' . $fs2['body']);
    expect($post($adminHttp, $adminCsrf, 'POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $fs2['json']['data']['version']])['status'] === 200, 'fg resubmit');
    (new \Amor\Api\Fg\FgService($pdo))->syncFromHandover($day, $karangtengahId, $uid, null);
    $st->execute([$batchId]);
    expect($st->fetchColumn() === 'submitted', 'a sync without any quantity change must leave a submitted FG document alone');
    $page = $adminHttp->request('GET', "/api/_ui-preview/?page=fg-packing&tanggal={$day}");
    expect($page['status'] === 200 && !preg_match('/SQLSTATE|Fatal error|Uncaught/', $page['body']), 'FG page must render with handovers');
});

runTest('RJT-19 Retur/Reject/Mutasi in one transaction: Atasan approval gate, edit while waiting, Admin validasi, dashboard scope, CSV export, A4/thermal print', function () use ($adminHttp, $adminCsrf, $pdo, $storeA, $storeB, $tokenA, $tokenB) {
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
    $p1 = nextProduct();
    $p2 = nextProduct();
    $p3 = nextProduct();
    $reason = $pdo->query('SELECT name FROM reject_reason WHERE active = 1 ORDER BY sort_order LIMIT 1')->fetchColumn();
    expect($reason === 'Exp H-1 Berjamur', 'master reasons seeded by migration 0026, got ' . var_export($reason, true));
    $send = function (string $token, array $report, array $photos, ?int $id = null): array {
        $anon = new HttpInv($GLOBALS['baseUrl']);
        $files = [];
        foreach ($photos as $i) {
            $files["evidence_{$i}[]"] = fakeEvidenceImage();
        }
        return $anon->requestMultipart('POST', "/api/store/{$token}/reports" . ($id ? "/{$id}" : ''), ['report' => json_encode($report)], $files, idemKey('srep'));
    };
    $items = [
        ['jenis' => 'retur', 'productId' => (int) $p1['product_id'], 'qty' => 3],
        ['jenis' => 'reject', 'productId' => (int) $p2['product_id'], 'qty' => 2, 'alasan' => $reason, 'expDate' => $today],
        ['jenis' => 'mutasi', 'productId' => (int) $p3['product_id'], 'qty' => 4],
    ];

    // Validation: reject needs a photo, a reason, and must not be past expiry; mutasi needs a real other store.
    $r = $send($tokenA, ['tanggal' => $today, 'destinationStoreId' => $storeB, 'items' => $items], []);
    expect($r['status'] === 400 && $r['json']['code'] === 'EVIDENCE_REQUIRED', 'reject without photo refused: ' . $r['body']);
    $bad = $items;
    $bad[1]['expDate'] = '2020-01-01';
    $r = $send($tokenA, ['tanggal' => $today, 'destinationStoreId' => $storeB, 'items' => $bad], [1]);
    expect($r['status'] === 400 && $r['json']['code'] === 'ALREADY_EXPIRED', 'expired goods are a Retur: ' . $r['body']);
    $r = $send($tokenA, ['tanggal' => $today, 'destinationStoreId' => $storeA, 'items' => $items], [1]);
    expect($r['status'] === 400 && $r['json']['code'] === 'SAME_STORE', 'mutasi to own store refused: ' . $r['body']);

    // Store A has no Atasan yet -> approved automatically, items reach Admin and store B at once.
    $r = $send($tokenA, ['tanggal' => $today, 'destinationStoreId' => $storeB, 'items' => $items], [1]);
    expect($r['status'] === 200, 'create transaction: ' . $r['body']);
    $t1 = $r['json']['data'];
    expect($t1['status'] === 'disetujui' && $t1['approvalMode'] === 'otomatis' && count($t1['items']) === 3, 'auto-approved with 3 items: ' . json_encode($t1));
    expect(abs($t1['total'] - (3 * (float) $p1['harga'] + 2 * (float) $p2['harga'] + 4 * (float) $p3['harga'])) < 0.01, 'total = sum qty x harga');
    $incomingB = (new HttpInv($GLOBALS['baseUrl']))->request('GET', "/api/store/{$tokenB}/mutasi/incoming")['json']['data'];
    expect(count(array_filter($incomingB, static fn ($m) => $m['productName'] === $p3['name'])) === 1, 'store B sees the approved mutasi');

    // Atasan for store A.
    $u = 'atasan_' . substr(uniqid(), -6);
    $cu = $adminHttp->request('POST', '/api/users', ['username' => $u, 'fullName' => 'Bu Atasan', 'password' => 'Atasan#2026x', 'passwordConfirm' => 'Atasan#2026x', 'roles' => ['ATASAN']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('u')));
    expect(in_array($cu['status'], [200, 201], true), 'create atasan: ' . $cu['body']);
    $atasanId = (int) $cu['json']['data']['userId'];
    $ps = $adminHttp->request('PUT', "/api/users/{$atasanId}/stores", ['storeIds' => [$storeA]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('us')));
    expect($ps['status'] === 200 && $ps['json']['data']['storeIds'] === [$storeA], 'assign store: ' . $ps['body']);
    $at = new HttpInv($GLOBALS['baseUrl']);
    $atCsrf = login($at, $u, 'Atasan#2026x');

    // Now a transaction waits for the Atasan and is invisible downstream.
    $r = $send($tokenA, ['tanggal' => $today, 'destinationStoreId' => $storeB, 'items' => $items], [1]);
    expect($r['status'] === 200 && $r['json']['data']['status'] === 'menunggu_atasan', 'waits for atasan: ' . $r['body']);
    $t2 = $r['json']['data'];
    $rej2 = array_values(array_filter($t2['items'], static fn ($i) => $i['jenis'] === 'reject'))[0];
    $val = $adminHttp->request('GET', '/api/store-reports/validasi?onlyWaiting=1')['json']['data']['items'];
    expect(array_filter($val, static fn ($i) => $i['reportId'] === $t2['reportId']) === [], 'Admin must not see a transaction still waiting for the Atasan');
    $early = adminPost($adminHttp, $adminCsrf, "/api/admin/retur/{$rej2['itemId']}/verify", ['tindakLanjut' => 'potong']);
    expect($early['status'] === 409 && $early['json']['code'] === 'WAITING_ATASAN', 'no decision before approval: ' . $early['body']);
    $incomingB2 = (new HttpInv($GLOBALS['baseUrl']))->request('GET', "/api/store/{$tokenB}/mutasi/incoming")['json']['data'];
    expect(count($incomingB2) === count($incomingB), 'store B must not see a mutasi before the Atasan approves');

    // Edit while waiting: change qty, drop the retur, keep the reject photo.
    $edit = [
        ['jenis' => 'reject', 'productId' => (int) $p2['product_id'], 'qty' => 5, 'alasan' => 'Tulisan bebas', 'itemType' => 'retur', 'itemId' => $rej2['itemId']],
        ['jenis' => 'mutasi', 'productId' => (int) $p3['product_id'], 'qty' => 1],
    ];
    $r = $send($tokenA, ['tanggal' => $today, 'destinationStoreId' => $storeB, 'items' => $edit], [], $t2['reportId']);
    expect($r['status'] === 200 && count($r['json']['data']['items']) === 2, 'edit: ' . $r['body']);
    $kept = array_values(array_filter($r['json']['data']['items'], static fn ($i) => $i['jenis'] === 'reject'))[0];
    expect($kept['itemId'] === $rej2['itemId'] && (float) $kept['qty'] === 5.0 && $kept['alasan'] === 'Tulisan bebas' && $kept['evidenceCount'] === 1, 'reject item updated in place, photo kept: ' . json_encode($kept));
    expect((int) $pdo->query("SELECT COUNT(*) FROM retur_request WHERE store_report_id = {$t2['reportId']}")->fetchColumn() === 1, 'removed retur row deleted');
    $other = $send($tokenB, ['tanggal' => $today, 'items' => $edit], [], $t2['reportId']);
    expect($other['status'] === 404, 'another store cannot edit it');

    // Atasan approves (only own stores).
    $pend = $at->request('GET', '/api/store-reports/pending')['json']['data'];
    expect(count($pend) === 1 && $pend[0]['reportId'] === $t2['reportId'], 'atasan sees the waiting transaction');
    expect($adminHttp->request('GET', '/api/store-reports/pending')['status'] === 403, 'admin is not an atasan');
    $ap = $at->request('POST', '/api/store-reports/approve', ['reportIds' => [$t2['reportId']]], array_merge(['X-CSRF-Token' => $atCsrf], idemKey('ap')));
    expect($ap['status'] === 200 && $ap['json']['data']['updated'] === 1, 'approve: ' . $ap['body']);
    $late = $send($tokenA, ['tanggal' => $today, 'destinationStoreId' => $storeB, 'items' => $edit], [], $t2['reportId']);
    expect($late['status'] === 409 && $late['json']['code'] === 'ALREADY_APPROVED', 'no edit after approval: ' . $late['body']);

    // Admin validasi: Sesuai -> potong issues the nota; Tidak Sesuai needs a note.
    $val = $adminHttp->request('GET', '/api/store-reports/validasi?onlyWaiting=1&storeId=' . $storeA)['json']['data'];
    $mine = array_values(array_filter($val['items'], static fn ($i) => $i['reportId'] === $t2['reportId']));
    expect(count($mine) === 2, 'both approved items wait for validasi: ' . json_encode($mine));
    $v = adminPost($adminHttp, $adminCsrf, '/api/store-reports/validasi', ['type' => 'retur', 'id' => $kept['itemId'], 'status' => 'sesuai', 'tindakLanjut' => 'potong']);
    expect($v['status'] === 200, 'validasi reject: ' . $v['body']);
    expect(notaFor($pdo, $kept['itemId']) !== null, 'a Sesuai reject issues a Nota Potongan');
    $mut = array_values(array_filter($mine, static fn ($i) => $i['jenis'] === 'mutasi'))[0];
    $nv = adminPost($adminHttp, $adminCsrf, '/api/store-reports/validasi', ['type' => 'mutasi', 'id' => $mut['itemId'], 'status' => 'tidak_sesuai', 'note' => '']);
    expect($nv['status'] === 400 && $nv['json']['code'] === 'NOTE_REQUIRED', 'tidak sesuai needs a note');
    $nv = adminPost($adminHttp, $adminCsrf, '/api/store-reports/validasi', ['type' => 'mutasi', 'id' => $mut['itemId'], 'status' => 'tidak_sesuai', 'note' => 'fisik hanya 0']);
    expect($nv['status'] === 200, 'mutasi validasi: ' . $nv['body']);
    expect($pdo->query("SELECT status FROM mutasi_request WHERE mutasi_request_id = {$mut['itemId']}")->fetchColumn() === 'cancelled', 'Admin Tidak Sesuai cancels the mutasi');
    $bConfirm = (new HttpInv($GLOBALS['baseUrl']))->requestMultipart('POST', "/api/store/{$tokenB}/mutasi/{$mut['itemId']}/confirm", ['confirmation' => json_encode(['qtyReceived' => 1])], [], idemKey('mc'));
    expect(in_array($bConfirm['status'], [404, 409], true), 'a cancelled mutasi can no longer be confirmed by the destination: ' . $bConfirm['body']);
    $again = adminPost($adminHttp, $adminCsrf, '/api/store-reports/validasi', ['type' => 'mutasi', 'id' => $mut['itemId'], 'status' => 'sesuai']);
    expect($again['status'] === 409 && $again['json']['code'] === 'MUTASI_CANCELLED', 'a cancelled mutasi stays cancelled');
    // The auto-approved mutasi (4 pcs): destination confirms, invoices are issued -> Admin can no longer cancel it.
    $mut1 = array_values(array_filter($t1['items'], static fn ($i) => $i['jenis'] === 'mutasi'))[0];
    $c1 = (new HttpInv($GLOBALS['baseUrl']))->requestMultipart('POST', "/api/store/{$tokenB}/mutasi/{$mut1['itemId']}/confirm", ['confirmation' => json_encode(['qtyReceived' => 4])], [], idemKey('mc'));
    expect($c1['status'] === 200, 'destination confirms: ' . $c1['body']);
    $gen = adminPost($adminHttp, $adminCsrf, '/api/invoices/generate-ready', []);
    expect($gen['status'] === 200, 'generate ready: ' . $gen['body']);
    expect((int) $pdo->query("SELECT COUNT(*) FROM invoice_mutasi WHERE mutasi_request_id = {$mut['itemId']}")->fetchColumn() === 0
        && (int) $pdo->query("SELECT COUNT(*) FROM nota_potongan WHERE mutasi_request_id = {$mut['itemId']}")->fetchColumn() === 0, 'a cancelled mutasi is never billed');
    expect((int) $pdo->query("SELECT COUNT(*) FROM invoice_mutasi WHERE mutasi_request_id = {$mut1['itemId']}")->fetchColumn() === 1, 'the confirmed mutasi is billed to the destination');
    $late2 = adminPost($adminHttp, $adminCsrf, '/api/store-reports/validasi', ['type' => 'mutasi', 'id' => $mut1['itemId'], 'status' => 'tidak_sesuai', 'note' => 'telat']);
    expect($late2['status'] === 409 && $late2['json']['code'] === 'MUTASI_ALREADY_INVOICED', 'an invoiced mutasi cannot be cancelled from validasi: ' . $late2['body']);
    $ret1 = array_values(array_filter($t1['items'], static fn ($i) => $i['jenis'] === 'retur'))[0];
    $rv = adminPost($adminHttp, $adminCsrf, '/api/store-reports/validasi', ['type' => 'retur', 'id' => $ret1['itemId'], 'status' => 'tidak_sesuai', 'note' => 'barang tidak ada']);
    expect($rv['status'] === 200, 'retur tidak sesuai: ' . $rv['body']);
    expect($pdo->query("SELECT status FROM retur_request WHERE retur_request_id = {$ret1['itemId']}")->fetchColumn() === 'rejected', 'retur tidak sesuai = rejected record');

    // Store history: search + per-kind totals + item status.
    $hist = (new HttpInv($GLOBALS['baseUrl']))->request('GET', "/api/store/{$tokenA}/reports?from={$today}&to={$today}&q=" . rawurlencode(mb_substr($p2['name'], 0, 6)))['json']['data'];
    expect(count($hist['list']) >= 2 && (float) $hist['ringkasan']['reject'] >= 7.0, 'history filter + totals: ' . json_encode($hist['ringkasan']));

    // Dashboard: Admin sees all, Atasan only store A.
    $dA = $adminHttp->request('GET', "/api/store-reports/dashboard?from={$today}&to={$today}")['json']['data'];
    expect($dA['totalReject'] >= 7.0 && $dA['totalRetur'] >= 3.0 && $dA['topReject'] !== [], 'admin dashboard: ' . json_encode($dA));
    $dB = $at->request('GET', "/api/store-reports/dashboard?from={$today}&to={$today}&storeId={$storeB}")['json']['data'];
    expect((float) $dB['totalReject'] === 0.0 && (float) $dB['totalRetur'] === 0.0, 'atasan cannot read other stores');
    $csv = $adminHttp->request('GET', "/api/store-reports/export?from={$today}&to={$today}&storeId={$storeA}");
    expect($csv['status'] === 200 && str_contains($csv['body'], 'ID Transaksi') && str_contains($csv['body'], $t2['docNo']) && str_contains($csv['body'], 'Tidak Sesuai'), 'CSV export with the sheet columns');

    // Print: A4 and thermal, own store only; admin + atasan print page.
    $pa = (new HttpInv($GLOBALS['baseUrl']))->request('GET', "/api/_store/print.php?token={$tokenA}&id={$t1['reportId']}&format=a4");
    expect($pa['status'] === 200 && str_contains($pa['body'], 'BUKTI RETUR / REJECT PRODUK') && str_contains($pa['body'], 'BUKTI MUTASI PRODUK') && str_contains($pa['body'], 'Pabrik Tujuan') && str_contains($pa['body'], 'Toko Tujuan') && str_contains($pa['body'], 'Tanda tangan penyerah'), 'A4 proof split per destination');
    $pt = (new HttpInv($GLOBALS['baseUrl']))->request('GET', "/api/_store/print.php?token={$tokenA}&id={$t1['reportId']}&format=thermal");
    expect($pt['status'] === 200 && str_contains($pt['body'], '80mm') && str_contains($pt['body'], 'TTD Penerima') && str_contains($pt['body'], 'window.print()'), 'thermal 80 mm proof prints by itself');
    $pb = (new HttpInv($GLOBALS['baseUrl']))->request('GET', "/api/_store/print.php?token={$tokenB}&id={$t1['reportId']}&format=a4");
    expect(!str_contains($pb['body'], $t1['docNo']), 'another store cannot print it');
    $pp = $at->request('GET', "/api/_ui-preview/print-store-report.php?id={$t2['reportId']}&format=a4");
    expect($pp['status'] === 200 && str_contains($pp['body'], 'Disetujui oleh Atasan: Bu Atasan'), 'atasan print shows the approval');
    foreach (['validasi-toko', 'dashboard-retur', 'alasan-reject'] as $pg) {
        $h = $adminHttp->request('GET', "/api/_ui-preview/?page={$pg}");
        expect($h['status'] === 200 && !preg_match('/SQLSTATE|Fatal error|Uncaught|Warning:/', $h['body']), "page {$pg} renders");
    }
    $h = $at->request('GET', '/api/_ui-preview/?page=approval-toko');
    expect($h['status'] === 200 && !preg_match('/SQLSTATE|Fatal error|Uncaught|Warning:/', $h['body']), 'approval page renders');
    expect($at->request('GET', '/api/_ui-preview/?page=invoice')['status'] === 403, 'atasan cannot open invoices');
    $portal = (new HttpInv($GLOBALS['baseUrl']))->request('GET', "/api/_store/index.php?token={$tokenA}&tab=retur");
    expect(str_contains($portal['body'], 'STORE_REPORT_REASONS') && str_contains($portal['body'], 'Exp H-1 Berjamur') && str_contains($portal['body'], 'store-report.js'), 'portal form loads reasons + script');
});

$failed = array_filter($results, static fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests, ' . count($failed) . " failed.\n");
exit(count($failed) === 0 ? 0 : 1);
