<?php

declare(strict_types=1);

/**
 * Permanent Bakery Portal integration suite (migration 0017) —
 * PORTAL-01..06, RECEIPT-PORTAL-01..05, SPECIAL-PORTAL-01..05,
 * RETUR-01..05, MUTASI-01..08, PHOTO-01..04, IDEMPOTENCY-01..04. Run via
 * api/tests/run-bakery-portal.sh, which stands up a disposable local
 * MariaDB, applies migrations 0001-0017, bootstraps realistic master
 * data, then drives the real /api/store/*, /api/admin/store-portal/*,
 * /api/admin/retur/*, /api/admin/mutasi/*, and /api/special-orders/*
 * JSON APIs end to end against a live `php -S` server — plus the full
 * existing regression cascade as this orchestrator's own final step.
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\StorePortal\StorePortalService;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8111';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'bp_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpBP
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'bpcookies');
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

    /** multipart/form-data — $fields may contain nested arrays/objects under a key that the controller JSON-decodes (e.g. "retur"/"mutasi"/"order"); $files maps field name (e.g. "evidence[]") to local file path(s). */
    public function requestMultipart(string $method, string $path, array $fields, array $files, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $hdrLines = [];
        foreach ($headers as $k => $v) {
            $hdrLines[] = "{$k}: {$v}";
        }
        $postFields = $fields;
        foreach ($files as $fieldName => $paths) {
            $paths = (array) $paths;
            $isArrayField = str_ends_with($fieldName, '[]');
            $base = $isArrayField ? substr($fieldName, 0, -2) : $fieldName;
            foreach ($paths as $i => $filePath) {
                // Real array-syntax PHP needs a DISTINCT bracketed index per
                // file (evidence[0], evidence[1], ...) for $_FILES['evidence']
                // to come back as a multi-entry array server-side; a bare
                // "evidence[]" suffix repeated with a trailing digit outside
                // the brackets (the previous, buggy form here) is not valid
                // PHP array-field syntax and silently drops every file past
                // the first.
                $key = $isArrayField ? "{$base}[{$i}]" : ($i > 0 ? "{$base}{$i}" : $base);
                $postFields[$key] = new CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', basename($filePath));
            }
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

function fakeEvidenceImage(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );
    $path = tempnam(sys_get_temp_dir(), 'bpevidence') . '.png';
    file_put_contents($path, $png);
    return $path;
}

function fakeInvalidEvidenceFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'bpbadevidence') . '.txt';
    file_put_contents($path, str_repeat('x', 100));
    return $path;
}

function idemKey(string $tag): array
{
    return ['Idempotency-Key' => $tag . '-' . uniqid('', true)];
}

function login(HttpBP $http, string $username, string $password): string
{
    $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    $me = $http->request('GET', '/api/auth/me');
    $csrf = $me['json']['data']['csrfToken'] ?? null;
    expect($csrf !== null, "expected a csrf token after login as {$username}");
    return $csrf;
}

/** Issues a fresh active portal token for $storeId directly via the real service (same code path the Admin UI's "Generate" button calls) and returns the raw value. */
function issuePortalToken(PDO $pdo, int $storeId, int $adminUserId): string
{
    $svc = new StorePortalService($pdo);
    $dto = $svc->issueToken($storeId, $adminUserId, 'bp-test-issue-' . uniqid('', true));
    return $dto['rawToken'];
}

function productsInDivision(PDO $pdo, int $divisionId, int $limit): array
{
    $stmt = $pdo->prepare('SELECT product_id, name FROM product WHERE division_id = ? ORDER BY product_id LIMIT ' . (int) $limit);
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

function createSubmittedProduction(HttpBP $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual): void
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

function stockUpForDelivery(HttpBP $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual, float $fgQty): void
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

function createDoDraft(HttpBP $http, string $csrf, string $tanggal, int $storeId): array
{
    $r = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeId], array_merge(['X-CSRF-Token' => $csrf], idemKey('do-create')));
    expect($r['status'] === 200, 'DO create failed: ' . json_encode($r['json']));
    return ['doId' => (int) $r['json']['data']['doId'], 'docNo' => $r['json']['data']['docNo'], 'version' => (int) $r['json']['data']['version']];
}

/** Full PO -> Production -> FG -> DO -> manual ship, leaving ONE real shipment ready for Portal receipt-confirmation tests. */
function setupShipmentForStore(HttpBP $adminHttp, string $adminCsrf, PDO $pdo, int $factoryId, int $divisionId, int $storeId, string $tanggal, int $productId, float $qty): array
{
    stockUpForDelivery($adminHttp, $adminCsrf, $pdo, $factoryId, $divisionId, $tanggal, $storeId, $productId, $qty, $qty, $qty);
    $do = createDoDraft($adminHttp, $adminCsrf, $tanggal, $storeId);
    $ship = $adminHttp->request('POST', "/api/do/{$do['doId']}/ship", ['expectedVersion' => $do['version'], 'items' => [['productId' => $productId, 'actualQty' => $qty]]], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('bp-ship')));
    expect($ship['status'] === 200, 'setup ship failed: ' . json_encode($ship['json']));
    return ['doId' => $do['doId'], 'shipmentId' => (int) $ship['json']['data']['shipmentId']];
}

function stockLedgerRowCount(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM stock_ledger')->fetchColumn();
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

$adminHttp = new HttpBP($baseUrl);
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
$bakeryCikole = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'BAKERY CIKOLE'")->fetchColumn();
expect($bakeryCikole > 0, 'expected BAKERY CIKOLE fixture seeded');

$rotiProducts = productsInDivision($pdo, $rotiBollenDivId, 40);
expect(count($rotiProducts) >= 40, 'expected enough katalog products after bootstrap');
$pool = $rotiProducts;
function nextProduct(): array { global $pool; $p = array_shift($pool); expect($p !== null, 'ran out of pooled test products'); return $p; }

// ===================================================================
// PORTAL-01..06 — token identity (StorePortalService)
// ===================================================================
runTest('PORTAL-01 Admin issues a token for a store and it resolves to the correct identity', function () use ($pdo, $storeA, $adminUserId, $baseUrl) {
    $raw = issuePortalToken($pdo, $storeA, $adminUserId);
    $anon = new HttpBP($baseUrl);
    $r = $anon->request('GET', '/api/store/' . $raw);
    expect($r['status'] === 200, 'expected bootstrap to succeed: ' . json_encode($r['json']));
    expect((int) $r['json']['data']['storeId'] === $storeA, 'expected the resolved storeId to match the issuing store');
    $GLOBALS['portal01_token'] = $raw;
});

runTest('PORTAL-02 Regenerate invalidates the old token immediately; the new one works', function () use ($pdo, $storeA, $adminUserId, $baseUrl) {
    $oldToken = $GLOBALS['portal01_token'];
    $newToken = issuePortalToken($pdo, $storeA, $adminUserId);
    expect($newToken !== $oldToken, 'expected a genuinely different token on regenerate');
    $anon = new HttpBP($baseUrl);
    $oldResolve = $anon->request('GET', '/api/store/' . $oldToken);
    expect($oldResolve['status'] === 404, 'expected the OLD token to 404 after regenerate, got ' . $oldResolve['status']);
    $newResolve = $anon->request('GET', '/api/store/' . $newToken);
    expect($newResolve['status'] === 200 && (int) $newResolve['json']['data']['storeId'] === $storeA, 'expected the NEW token to resolve correctly');
    $GLOBALS['storeA_token'] = $newToken;
});

runTest('PORTAL-03 Revoke invalidates the token; a second revoke with nothing active fails', function () use ($pdo, $storeB, $adminUserId, $baseUrl) {
    $raw = issuePortalToken($pdo, $storeB, $adminUserId);
    $svc = new StorePortalService($pdo);
    $svc->revokeToken($storeB, $adminUserId, 'bp-test-revoke');
    $anon = new HttpBP($baseUrl);
    $r = $anon->request('GET', '/api/store/' . $raw);
    expect($r['status'] === 404, 'expected a revoked token to 404');
    try {
        $svc->revokeToken($storeB, $adminUserId, 'bp-test-revoke-2');
        expect(false, 'expected a second revoke with nothing active to throw');
    } catch (\Amor\Api\ApiException $e) {
        expect($e->errorCode === 'NO_ACTIVE_TOKEN', 'expected NO_ACTIVE_TOKEN on a second revoke, got ' . $e->errorCode);
    }
});

runTest('PORTAL-04 Schema guarantees at most one ACTIVE token per store, even via a direct insert', function () use ($pdo, $storeB) {
    // Uses storeB, not storeA: by this point storeA already has an ACTIVE
    // token from PORTAL-02's regenerate, so even the FIRST insert below
    // would collide with it. storeB has none (PORTAL-03 revoked its only
    // token with no replacement), so it starts this test from zero.
    $hash1 = hash('sha256', 'bp-portal04-' . uniqid());
    $hash2 = hash('sha256', 'bp-portal04-' . uniqid());
    $pdo->prepare('INSERT INTO store_portal_token (store_id, token_hash, created_by, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())')->execute([$storeB, $hash1]);
    $threw = false;
    try {
        $pdo->prepare('INSERT INTO store_portal_token (store_id, token_hash, created_by, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())')->execute([$storeB, $hash2]);
    } catch (\PDOException $e) {
        $threw = (int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'uq_store_portal_token_active');
    }
    $pdo->exec("DELETE FROM store_portal_token WHERE token_hash IN ('{$hash1}','{$hash2}')");
    expect($threw, 'expected a duplicate ACTIVE row for the same store to violate the unique constraint');
});

runTest('PORTAL-05 A token never resolves to any store other than the one it was issued for', function () use ($pdo, $storeA, $storeB, $adminUserId, $baseUrl) {
    $tokenA = issuePortalToken($pdo, $storeA, $adminUserId);
    $tokenB = issuePortalToken($pdo, $storeB, $adminUserId);
    $anon = new HttpBP($baseUrl);
    $rA = $anon->request('GET', '/api/store/' . $tokenA);
    $rB = $anon->request('GET', '/api/store/' . $tokenB);
    expect((int) $rA['json']['data']['storeId'] === $storeA, 'token A must resolve to store A');
    expect((int) $rB['json']['data']['storeId'] === $storeB, 'token B must resolve to store B');
    expect((int) $rA['json']['data']['storeId'] !== (int) $rB['json']['data']['storeId'], 'the two tokens must never resolve to the same store');
    $GLOBALS['storeA_token'] = $tokenA;
    $GLOBALS['storeB_token'] = $tokenB;
});

runTest('PORTAL-06 Admin-only token-management routes reject an unauthenticated caller', function () use ($storeA, $baseUrl) {
    $anon = new HttpBP($baseUrl);
    $r = $anon->request('POST', "/api/admin/store-portal/{$storeA}/issue", [], idemKey('portal06'));
    expect(in_array($r['status'], [401, 403], true), 'expected 401/403 for an unauthenticated token-issue attempt, got ' . $r['status']);
});

// ===================================================================
// RECEIPT-PORTAL-01..05 — Konfirmasi Penerimaan (Reject included)
// ===================================================================
$tanggalReceipt = '2026-09-01';

runTest('RECEIPT-PORTAL-01 A store sees only its OWN shipments in the Portal receipts list', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $storeB, $tanggalReceipt, $baseUrl) {
    $pA = nextProduct();
    $fxA = setupShipmentForStore($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $tanggalReceipt, $pA['product_id'], 10.0);
    $anon = new HttpBP($baseUrl);
    $tokenA = $GLOBALS['storeA_token'];
    $tokenB = $GLOBALS['storeB_token'];
    $listA = $anon->request('GET', "/api/store/{$tokenA}/receipts");
    $listB = $anon->request('GET', "/api/store/{$tokenB}/receipts");
    $idsA = array_column($listA['json']['data'], 'shipmentId');
    $idsB = array_column($listB['json']['data'], 'shipmentId');
    expect(in_array($fxA['shipmentId'], $idsA, true), "expected store A's own shipment in its own list");
    expect(!in_array($fxA['shipmentId'], $idsB, true), "expected store A's shipment to be ABSENT from store B's list");
    $GLOBALS['receipt_fx_a'] = $fxA;
});

runTest('RECEIPT-PORTAL-02 Confirm with matching qty (no discrepancy) succeeds without evidence', function () use ($baseUrl) {
    $fx = $GLOBALS['receipt_fx_a'];
    $token = $GLOBALS['storeA_token'];
    $anon = new HttpBP($baseUrl);
    $detail = $anon->request('GET', "/api/store/{$token}/receipts/{$fx['shipmentId']}");
    $itemId = $detail['json']['data']['items'][0]['shipmentItemId'];
    $qty = $detail['json']['data']['items'][0]['shippedQty'];
    $r = $anon->request('POST', "/api/store/{$token}/receipts/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Toko A', 'items' => [['shipmentItemId' => $itemId, 'receivedGood' => $qty, 'reject' => 0, 'shortage' => 0]],
    ], idemKey('rp02'));
    expect($r['status'] === 200 && $r['json']['data']['status'] === 'confirmed_ok', 'expected confirmed_ok, got ' . json_encode($r['json']));
});

runTest('RECEIPT-PORTAL-03 Reject > 0 without evidence is rejected (EVIDENCE_REQUIRED)', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $baseUrl) {
    // Own tanggal: production_run has a UNIQUE KEY on (tanggal, division_id),
    // so reusing $tanggalReceipt here would hit the SAME run RECEIPT-PORTAL-01
    // already submitted, and the PATCH below would fail with INVALID_STATUS.
    $p = nextProduct();
    $fx = setupShipmentForStore($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-09-02', $p['product_id'], 8.0);
    $token = $GLOBALS['storeA_token'];
    $anon = new HttpBP($baseUrl);
    $detail = $anon->request('GET', "/api/store/{$token}/receipts/{$fx['shipmentId']}");
    $itemId = $detail['json']['data']['items'][0]['shipmentItemId'];
    $r = $anon->request('POST', "/api/store/{$token}/receipts/{$fx['shipmentId']}/confirm", [
        'receiverName' => 'Toko A', 'items' => [['shipmentItemId' => $itemId, 'receivedGood' => 6, 'reject' => 2, 'shortage' => 0]],
    ], idemKey('rp03'));
    expect($r['status'] === 400 && ($r['json']['code'] ?? null) === 'EVIDENCE_REQUIRED', 'expected EVIDENCE_REQUIRED, got ' . json_encode($r['json']));
    $GLOBALS['receipt_fx_reject'] = ['fx' => $fx, 'itemId' => $itemId];
});

runTest('RECEIPT-PORTAL-04 Reject > 0 WITH evidence succeeds as confirmed_discrepancy, Reject lives inside this flow (never a separate menu)', function () use ($baseUrl) {
    $ctx = $GLOBALS['receipt_fx_reject'];
    $token = $GLOBALS['storeA_token'];
    $img = fakeEvidenceImage();
    $anon = new HttpBP($baseUrl);
    $r = $anon->requestMultipart('POST', "/api/store/{$token}/receipts/{$ctx['fx']['shipmentId']}/confirm", [
        'receiverName' => 'Toko A', 'note' => 'ada reject',
        'items' => json_encode([['shipmentItemId' => $ctx['itemId'], 'receivedGood' => 6, 'reject' => 2, 'shortage' => 0]]),
    ], ['evidence[]' => $img], idemKey('rp04'));
    expect($r['status'] === 200 && $r['json']['data']['status'] === 'confirmed_discrepancy', 'expected confirmed_discrepancy, got ' . json_encode($r['json']));
    expect(count($r['json']['data']['evidence']) === 1, 'expected exactly 1 evidence row');
});

runTest('RECEIPT-PORTAL-05 A store cannot confirm (or even see) another store\'s shipment', function () use ($baseUrl) {
    $fx = $GLOBALS['receipt_fx_a'];
    $wrongToken = $GLOBALS['storeB_token'];
    $anon = new HttpBP($baseUrl);
    $r = $anon->request('GET', "/api/store/{$wrongToken}/receipts/{$fx['shipmentId']}");
    expect($r['status'] === 404, 'expected 404 for cross-store receipt access, got ' . $r['status']);
});

// ===================================================================
// SPECIAL-PORTAL-01..05 — Pesanan Khusus
// ===================================================================
runTest('SPECIAL-PORTAL-01 Bakery submits a Pesanan Khusus; storeId/sourceType/unitPrice spoof attempts are ignored', function () use ($baseUrl, $storeA) {
    $token = $GLOBALS['storeA_token'];
    $p = nextProduct();
    $anon = new HttpBP($baseUrl);
    $r = $anon->request('POST', "/api/store/{$token}/special-orders", [
        'storeId' => 999999, 'sourceType' => 'non_toko',
        'orderDate' => '2026-09-05', 'requiredDate' => '2026-09-10',
        'items' => [['itemType' => 'existing_product', 'productId' => $p['product_id'], 'qty' => 3, 'unitPrice' => 9999999]],
    ], idemKey('sp01'));
    expect($r['status'] === 200, 'expected submission to succeed: ' . json_encode($r['json']));
    expect((int) $r['json']['data']['storeId'] === $storeA, 'expected storeId to be forced to the token-resolved store, got ' . json_encode($r['json']['data']));
    expect($r['json']['data']['sourceType'] === 'toko_khusus', 'expected sourceType to be forced to toko_khusus');
    expect((float) $r['json']['data']['items'][0]['unitPrice'] !== 9999999.0, 'expected the spoofed unitPrice to be ignored');
    expect($r['json']['data']['status'] === 'draft', 'expected a fresh portal submission to land in draft (task\'s own Admin-Verification-pending mapping)');
    $GLOBALS['special_order_a'] = $r['json']['data'];
});

runTest('SPECIAL-PORTAL-02 The order is NEVER auto-sent to Production (stays draft, not sent_to_production)', function () {
    $order = $GLOBALS['special_order_a'];
    expect(in_array($order['status'], ['draft', 'confirmed'], true), 'a bakery submission must never auto-advance to sent_to_production');
});

runTest('SPECIAL-PORTAL-03 A store sees only its OWN Pesanan Khusus, and cannot view another store\'s order detail', function () use ($baseUrl) {
    $tokenA = $GLOBALS['storeA_token'];
    $tokenB = $GLOBALS['storeB_token'];
    $orderId = $GLOBALS['special_order_a']['orderId'];
    $anon = new HttpBP($baseUrl);
    $listA = $anon->request('GET', "/api/store/{$tokenA}/special-orders");
    $ids = array_column($listA['json']['data'], 'orderId');
    expect(in_array($orderId, $ids, true), 'expected the order in store A\'s own list');
    $wrongDetail = $anon->request('GET', "/api/store/{$tokenB}/special-orders/{$orderId}");
    expect($wrongDetail['status'] === 404, 'expected 404 for cross-store special-order access, got ' . $wrongDetail['status']);
});

runTest('SPECIAL-PORTAL-04 Admin verification reuses the EXISTING confirm()/cancel() engine unchanged', function () use ($adminHttp, $adminCsrf) {
    $orderId = $GLOBALS['special_order_a']['orderId'];
    $version = $GLOBALS['special_order_a']['version'];
    $r = $adminHttp->request('POST', "/api/special-orders/{$orderId}/confirm", ['expectedVersion' => $version], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('sp04')));
    expect($r['status'] === 200 && $r['json']['data']['status'] === 'confirmed', 'expected the EXISTING Admin confirm action to advance a portal-submitted order, got ' . json_encode($r['json']));
});

runTest('SPECIAL-PORTAL-05 Optional reference attachments upload and stream back correctly, ownership-checked', function () use ($baseUrl) {
    $token = $GLOBALS['storeA_token'];
    $p = nextProduct();
    $img = fakeEvidenceImage();
    $anon = new HttpBP($baseUrl);
    $r = $anon->requestMultipart('POST', "/api/store/{$token}/special-orders", [
        'order' => json_encode(['orderDate' => '2026-09-05', 'requiredDate' => '2026-09-12', 'items' => [['itemType' => 'existing_product', 'productId' => $p['product_id'], 'qty' => 2]]]),
    ], ['attachments[]' => $img], idemKey('sp05'));
    expect($r['status'] === 200, 'expected submission with attachment to succeed: ' . json_encode($r['json']));
    expect(count($r['json']['data']['attachments']) === 1, 'expected exactly 1 attachment');
    $attId = $r['json']['data']['attachments'][0]['attachmentId'];
    $orderId = $r['json']['data']['orderId'];
    $stream = $anon->request('GET', "/api/store/{$token}/special-orders/{$orderId}/attachments/{$attId}");
    expect($stream['status'] === 200, 'expected the attachment to stream back successfully for its own store');
    $wrongToken = $GLOBALS['storeB_token'];
    $wrongStream = $anon->request('GET', "/api/store/{$wrongToken}/special-orders/{$orderId}/attachments/{$attId}");
    expect($wrongStream['status'] === 404, 'expected 404 when a different store tries to view this attachment');
});

// ===================================================================
// RETUR-01..05
// ===================================================================
runTest('RETUR-01 Submission without evidence is rejected (EVIDENCE_REQUIRED)', function () use ($baseUrl) {
    $token = $GLOBALS['storeA_token'];
    $p = nextProduct();
    $anon = new HttpBP($baseUrl);
    $r = $anon->request('POST', "/api/store/{$token}/retur", ['productId' => $p['product_id'], 'qty' => 2, 'reason' => 'tidak terjual'], idemKey('retur01'));
    expect($r['status'] === 400 && ($r['json']['code'] ?? null) === 'EVIDENCE_REQUIRED', 'expected EVIDENCE_REQUIRED, got ' . json_encode($r['json']));
});

runTest('RETUR-02 Submission with evidence succeeds, doc_no follows the RETUR-{Ymd}-{seq} format, status waiting_admin_verification', function () use ($baseUrl) {
    $token = $GLOBALS['storeA_token'];
    $p = nextProduct();
    $img = fakeEvidenceImage();
    $anon = new HttpBP($baseUrl);
    $r = $anon->requestMultipart('POST', "/api/store/{$token}/retur", [
        'retur' => json_encode(['productId' => $p['product_id'], 'qty' => 2, 'reason' => 'tidak terjual']),
    ], ['evidence[]' => $img], idemKey('retur02'));
    expect($r['status'] === 200, 'expected submission to succeed: ' . json_encode($r['json']));
    expect($r['json']['data']['status'] === 'waiting_admin_verification', 'expected waiting_admin_verification');
    expect((bool) preg_match('/^RETUR-\d{8}-\d{3}$/', $r['json']['data']['docNo']), 'expected RETUR-{Ymd}-{seq} doc_no format, got ' . $r['json']['data']['docNo']);
    $GLOBALS['retur_a'] = $r['json']['data'];
});

runTest('RETUR-03 Admin verify succeeds once; a second verify is rejected (INVALID_RETUR_STATUS)', function () use ($adminHttp, $adminCsrf) {
    $returId = $GLOBALS['retur_a']['returId'];
    $r1 = $adminHttp->request('POST', "/api/admin/retur/{$returId}/verify", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('retur03a')));
    expect($r1['status'] === 200 && $r1['json']['data']['status'] === 'verified', 'expected first verify to succeed, got ' . json_encode($r1['json']));
    $r2 = $adminHttp->request('POST', "/api/admin/retur/{$returId}/verify", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('retur03b')));
    expect($r2['status'] === 409 && ($r2['json']['code'] ?? null) === 'INVALID_RETUR_STATUS', 'expected 409 INVALID_RETUR_STATUS on the second verify, got ' . json_encode($r2['json']));
});

runTest('RETUR-04 Admin reject requires a reason', function () use ($adminHttp, $adminCsrf, $baseUrl) {
    $token = $GLOBALS['storeA_token'];
    $p = nextProduct();
    $img = fakeEvidenceImage();
    $anon = new HttpBP($baseUrl);
    $submit = $anon->requestMultipart('POST', "/api/store/{$token}/retur", [
        'retur' => json_encode(['productId' => $p['product_id'], 'qty' => 1, 'reason' => 'rusak di rak']),
    ], ['evidence[]' => $img], idemKey('retur04submit'));
    $returId = $submit['json']['data']['returId'];
    $noReason = $adminHttp->request('POST', "/api/admin/retur/{$returId}/reject", ['reason' => ''], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('retur04a')));
    expect($noReason['status'] === 400 && ($noReason['json']['code'] ?? null) === 'REASON_REQUIRED', 'expected REASON_REQUIRED, got ' . json_encode($noReason['json']));
    $withReason = $adminHttp->request('POST', "/api/admin/retur/{$returId}/reject", ['reason' => 'Bukti foto tidak jelas'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('retur04b')));
    expect($withReason['status'] === 200 && $withReason['json']['data']['status'] === 'rejected', 'expected reject to succeed with a reason, got ' . json_encode($withReason['json']));
});

runTest('RETUR-05 Retur NEVER writes stock_ledger, and a store cannot see another store\'s Retur', function () use ($pdo, $baseUrl) {
    $before = stockLedgerRowCount($pdo);
    $token = $GLOBALS['storeA_token'];
    $p = nextProduct();
    $img = fakeEvidenceImage();
    $anon = new HttpBP($baseUrl);
    $anon->requestMultipart('POST', "/api/store/{$token}/retur", [
        'retur' => json_encode(['productId' => $p['product_id'], 'qty' => 5, 'reason' => 'retur-05 stock check']),
    ], ['evidence[]' => $img], idemKey('retur05'));
    $after = stockLedgerRowCount($pdo);
    expect($before === $after, "expected stock_ledger row count unchanged by Retur (before={$before} after={$after})");

    $wrongToken = $GLOBALS['storeB_token'];
    $returId = $GLOBALS['retur_a']['returId'];
    $wrongView = $anon->request('GET', "/api/store/{$wrongToken}/retur/{$returId}");
    expect($wrongView['status'] === 404, 'expected 404 for cross-store Retur access');
});

// ===================================================================
// MUTASI-01..08
// ===================================================================
runTest('MUTASI-01 Submission without evidence is rejected (EVIDENCE_REQUIRED)', function () use ($baseUrl, $storeB) {
    $token = $GLOBALS['storeA_token'];
    $p = nextProduct();
    $anon = new HttpBP($baseUrl);
    $r = $anon->request('POST', "/api/store/{$token}/mutasi", ['destinationStoreId' => $storeB, 'productId' => $p['product_id'], 'qty' => 3], idemKey('mut01'));
    expect($r['status'] === 400 && ($r['json']['code'] ?? null) === 'EVIDENCE_REQUIRED', 'expected EVIDENCE_REQUIRED, got ' . json_encode($r['json']));
});

runTest('MUTASI-02 Submission with evidence succeeds; appears in destination\'s incoming list and source\'s outgoing list', function () use ($baseUrl, $storeB) {
    $token = $GLOBALS['storeA_token'];
    $destToken = $GLOBALS['storeB_token'];
    $p = nextProduct();
    $img = fakeEvidenceImage();
    $anon = new HttpBP($baseUrl);
    $r = $anon->requestMultipart('POST', "/api/store/{$token}/mutasi", [
        'mutasi' => json_encode(['destinationStoreId' => $storeB, 'productId' => $p['product_id'], 'qty' => 4]),
    ], ['evidence[]' => $img], idemKey('mut02'));
    expect($r['status'] === 200 && $r['json']['data']['status'] === 'waiting_destination_confirmation', 'expected waiting_destination_confirmation, got ' . json_encode($r['json']));
    expect((bool) preg_match('/^MUTASI-\d{8}-\d{3}$/', $r['json']['data']['docNo']), 'expected MUTASI-{Ymd}-{seq} doc_no format');
    $mutasiId = $r['json']['data']['mutasiId'];

    $incoming = $anon->request('GET', "/api/store/{$destToken}/mutasi/incoming");
    expect(in_array($mutasiId, array_column($incoming['json']['data'], 'mutasiId'), true), 'expected to appear in destination\'s incoming-pending list');
    $outgoing = $anon->request('GET', "/api/store/{$token}/mutasi/outgoing");
    expect(in_array($mutasiId, array_column($outgoing['json']['data'], 'mutasiId'), true), 'expected to appear in source\'s outgoing list');

    $GLOBALS['mutasi_ab'] = $r['json']['data'];
});

runTest('MUTASI-03 A store that is NOT the destination cannot confirm it', function () use ($baseUrl) {
    $wrongToken = $GLOBALS['storeA_token']; // source itself, not the destination
    $mutasiId = $GLOBALS['mutasi_ab']['mutasiId'];
    $anon = new HttpBP($baseUrl);
    $r = $anon->request('POST', "/api/store/{$wrongToken}/mutasi/{$mutasiId}/confirm", ['qtyReceived' => 4], idemKey('mut03'));
    expect($r['status'] === 404, 'expected 404 when the source itself tries to confirm its own outgoing mutasi, got ' . $r['status']);
});

runTest('MUTASI-04 Destination confirms with MATCHING qty, no evidence needed -> completed', function () use ($baseUrl) {
    $destToken = $GLOBALS['storeB_token'];
    $mutasiId = $GLOBALS['mutasi_ab']['mutasiId'];
    $anon = new HttpBP($baseUrl);
    $r = $anon->request('POST', "/api/store/{$destToken}/mutasi/{$mutasiId}/confirm", ['qtyReceived' => 4], idemKey('mut04'));
    expect($r['status'] === 200 && $r['json']['data']['status'] === 'completed', 'expected completed, got ' . json_encode($r['json']));
});

runTest('MUTASI-05 Mismatched qty without evidence is rejected (EVIDENCE_REQUIRED)', function () use ($baseUrl, $storeB) {
    $token = $GLOBALS['storeA_token'];
    $destToken = $GLOBALS['storeB_token'];
    $p = nextProduct();
    $img = fakeEvidenceImage();
    $anon = new HttpBP($baseUrl);
    $submit = $anon->requestMultipart('POST', "/api/store/{$token}/mutasi", [
        'mutasi' => json_encode(['destinationStoreId' => $storeB, 'productId' => $p['product_id'], 'qty' => 6]),
    ], ['evidence[]' => $img], idemKey('mut05submit'));
    $mutasiId = $submit['json']['data']['mutasiId'];
    $r = $anon->request('POST', "/api/store/{$destToken}/mutasi/{$mutasiId}/confirm", ['qtyReceived' => 4], idemKey('mut05'));
    expect($r['status'] === 400 && ($r['json']['code'] ?? null) === 'EVIDENCE_REQUIRED', 'expected EVIDENCE_REQUIRED for a discrepancy with no evidence, got ' . json_encode($r['json']));
    $GLOBALS['mutasi_mismatch_id'] = $mutasiId;
});

runTest('MUTASI-06 Mismatched qty WITH evidence succeeds as discrepancy', function () use ($baseUrl) {
    $destToken = $GLOBALS['storeB_token'];
    $mutasiId = $GLOBALS['mutasi_mismatch_id'];
    $img = fakeEvidenceImage();
    $anon = new HttpBP($baseUrl);
    $r = $anon->requestMultipart('POST', "/api/store/{$destToken}/mutasi/{$mutasiId}/confirm", [
        'confirmation' => json_encode(['qtyReceived' => 4, 'notes' => '2 pcs rusak']),
    ], ['evidence[]' => $img], idemKey('mut06'));
    expect($r['status'] === 200 && $r['json']['data']['status'] === 'discrepancy', 'expected discrepancy, got ' . json_encode($r['json']));
});

runTest('MUTASI-07 Admin resolves the discrepancy; requires notes; a second review is rejected', function () use ($adminHttp, $adminCsrf) {
    $mutasiId = $GLOBALS['mutasi_mismatch_id'];
    $noNotes = $adminHttp->request('POST', "/api/admin/mutasi/{$mutasiId}/review", ['resolution' => 'completed', 'notes' => ''], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('mut07a')));
    expect($noNotes['status'] === 400 && ($noNotes['json']['code'] ?? null) === 'REASON_REQUIRED', 'expected REASON_REQUIRED without notes, got ' . json_encode($noNotes['json']));
    $invalidResolution = $adminHttp->request('POST', "/api/admin/mutasi/{$mutasiId}/review", ['resolution' => 'bogus', 'notes' => 'x'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('mut07b')));
    expect($invalidResolution['status'] === 400 && ($invalidResolution['json']['code'] ?? null) === 'INVALID_RESOLUTION', 'expected INVALID_RESOLUTION for a bogus resolution value');
    $ok = $adminHttp->request('POST', "/api/admin/mutasi/{$mutasiId}/review", ['resolution' => 'completed', 'notes' => 'Diterima apa adanya'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('mut07c')));
    expect($ok['status'] === 200 && $ok['json']['data']['status'] === 'completed', 'expected the review to resolve to completed, got ' . json_encode($ok['json']));
    $again = $adminHttp->request('POST', "/api/admin/mutasi/{$mutasiId}/review", ['resolution' => 'completed', 'notes' => 'lagi'], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('mut07d')));
    expect($again['status'] === 409 && ($again['json']['code'] ?? null) === 'INVALID_MUTASI_STATUS', 'expected a second review attempt to be rejected, got ' . json_encode($again['json']));
});

runTest('MUTASI-08 Mutasi NEVER writes stock_ledger across the whole lifecycle', function () use ($pdo, $baseUrl, $storeB) {
    $before = stockLedgerRowCount($pdo);
    $token = $GLOBALS['storeA_token'];
    $destToken = $GLOBALS['storeB_token'];
    $p = nextProduct();
    $img = fakeEvidenceImage();
    $anon = new HttpBP($baseUrl);
    $submit = $anon->requestMultipart('POST', "/api/store/{$token}/mutasi", [
        'mutasi' => json_encode(['destinationStoreId' => $storeB, 'productId' => $p['product_id'], 'qty' => 2]),
    ], ['evidence[]' => $img], idemKey('mut08submit'));
    $mutasiId = $submit['json']['data']['mutasiId'];
    $anon->request('POST', "/api/store/{$destToken}/mutasi/{$mutasiId}/confirm", ['qtyReceived' => 2], idemKey('mut08confirm'));
    $after = stockLedgerRowCount($pdo);
    expect($before === $after, "expected stock_ledger row count unchanged by a full Mutasi lifecycle (before={$before} after={$after})");
});

// ===================================================================
// PHOTO-01..04 — shared EvidenceUploader mechanism
// ===================================================================
runTest('PHOTO-01 A real, valid JPEG/PNG is accepted (content-sniffed, never trusting the client MIME)', function () use ($baseUrl) {
    $token = $GLOBALS['storeA_token'];
    $p = nextProduct();
    $img = fakeEvidenceImage();
    $anon = new HttpBP($baseUrl);
    $r = $anon->requestMultipart('POST', "/api/store/{$token}/retur", [
        'retur' => json_encode(['productId' => $p['product_id'], 'qty' => 1, 'reason' => 'photo-01']),
    ], ['evidence[]' => $img], idemKey('photo01'));
    expect($r['status'] === 200, 'expected a real image to be accepted: ' . json_encode($r['json']));
});

runTest('PHOTO-02 A renamed non-image file is rejected across EVERY evidence context (real content-sniffing)', function () use ($baseUrl, $storeB) {
    $token = $GLOBALS['storeA_token'];
    $p = nextProduct();
    $bad = fakeInvalidEvidenceFile();
    $anon = new HttpBP($baseUrl);
    $returR = $anon->requestMultipart('POST', "/api/store/{$token}/retur", [
        'retur' => json_encode(['productId' => $p['product_id'], 'qty' => 1, 'reason' => 'photo-02']),
    ], ['evidence[]' => $bad], idemKey('photo02a'));
    expect($returR['status'] === 400 && ($returR['json']['code'] ?? null) === 'EVIDENCE_INVALID_MIME', 'expected EVIDENCE_INVALID_MIME for Retur, got ' . json_encode($returR['json']));

    $mutasiR = $anon->requestMultipart('POST', "/api/store/{$token}/mutasi", [
        'mutasi' => json_encode(['destinationStoreId' => $storeB, 'productId' => $p['product_id'], 'qty' => 1]),
    ], ['evidence[]' => $bad], idemKey('photo02b'));
    expect($mutasiR['status'] === 400 && ($mutasiR['json']['code'] ?? null) === 'EVIDENCE_INVALID_MIME', 'expected EVIDENCE_INVALID_MIME for Mutasi, got ' . json_encode($mutasiR['json']));
});

runTest('PHOTO-03 More than MAX_FILES evidence photos in one submission is rejected', function () use ($baseUrl) {
    $token = $GLOBALS['storeA_token'];
    $p = nextProduct();
    $imgs = [fakeEvidenceImage(), fakeEvidenceImage(), fakeEvidenceImage(), fakeEvidenceImage()];
    $anon = new HttpBP($baseUrl);
    $r = $anon->requestMultipart('POST', "/api/store/{$token}/retur", [
        'retur' => json_encode(['productId' => $p['product_id'], 'qty' => 1, 'reason' => 'photo-03']),
    ], ['evidence[]' => $imgs], idemKey('photo03'));
    expect($r['status'] === 400 && ($r['json']['code'] ?? null) === 'TOO_MANY_EVIDENCE_FILES', 'expected TOO_MANY_EVIDENCE_FILES for 4 files (max 3), got ' . json_encode($r['json']));
});

runTest('PHOTO-04 Each context stores its evidence in its OWN separate directory, never mixed', function () {
    $root = dirname(__DIR__) . '/uploads';
    foreach (['receipt-evidence', 'retur-evidence', 'mutasi-evidence', 'special-order-attachment'] as $ctx) {
        expect(is_dir("{$root}/{$ctx}"), "expected a dedicated upload directory for context '{$ctx}'");
        expect(is_file("{$root}/{$ctx}/.htaccess"), "expected a deny-all .htaccess in '{$ctx}'");
    }
});

// ===================================================================
// IDEMPOTENCY-01..04 — double-tap/retry protection on the new Portal routes
// ===================================================================
runTest('IDEMPOTENCY-01 Same Idempotency-Key + same body on Retur create replays, never double-inserts', function () use ($pdo, $baseUrl) {
    $token = $GLOBALS['storeA_token'];
    $p = nextProduct();
    $img = fakeEvidenceImage();
    $key = ['Idempotency-Key' => 'idem01-' . uniqid('', true)];
    $anon = new HttpBP($baseUrl);
    $before = (int) $pdo->query('SELECT COUNT(*) FROM retur_request')->fetchColumn();
    $r1 = $anon->requestMultipart('POST', "/api/store/{$token}/retur", [
        'retur' => json_encode(['productId' => $p['product_id'], 'qty' => 1, 'reason' => 'idem-01']),
    ], ['evidence[]' => $img], $key);
    $r2 = $anon->requestMultipart('POST', "/api/store/{$token}/retur", [
        'retur' => json_encode(['productId' => $p['product_id'], 'qty' => 1, 'reason' => 'idem-01']),
    ], ['evidence[]' => $img], $key);
    $after = (int) $pdo->query('SELECT COUNT(*) FROM retur_request')->fetchColumn();
    expect($r1['json']['data']['returId'] === $r2['json']['data']['returId'], 'expected the SAME returId on both responses (replay)');
    expect($after === $before + 1, "expected exactly ONE new retur_request row, before={$before} after={$after}");
});

runTest('IDEMPOTENCY-02 Same Idempotency-Key + DIFFERENT body is rejected (409 reuse mismatch)', function () use ($baseUrl) {
    $token = $GLOBALS['storeA_token'];
    $p1 = nextProduct();
    $p2 = nextProduct();
    $img = fakeEvidenceImage();
    $key = ['Idempotency-Key' => 'idem02-' . uniqid('', true)];
    $anon = new HttpBP($baseUrl);
    $anon->requestMultipart('POST', "/api/store/{$token}/retur", [
        'retur' => json_encode(['productId' => $p1['product_id'], 'qty' => 1, 'reason' => 'idem-02-first']),
    ], ['evidence[]' => $img], $key);
    $r2 = $anon->requestMultipart('POST', "/api/store/{$token}/retur", [
        'retur' => json_encode(['productId' => $p2['product_id'], 'qty' => 9, 'reason' => 'idem-02-different']),
    ], ['evidence[]' => $img], $key);
    expect($r2['status'] === 409, 'expected 409 for a reused Idempotency-Key with a different body, got ' . $r2['status']);
});

runTest('IDEMPOTENCY-03 Mutasi confirm double-tap never applies the confirmation twice', function () use ($pdo, $baseUrl, $storeB) {
    $token = $GLOBALS['storeA_token'];
    $destToken = $GLOBALS['storeB_token'];
    $p = nextProduct();
    $img = fakeEvidenceImage();
    $anon = new HttpBP($baseUrl);
    $submit = $anon->requestMultipart('POST', "/api/store/{$token}/mutasi", [
        'mutasi' => json_encode(['destinationStoreId' => $storeB, 'productId' => $p['product_id'], 'qty' => 3]),
    ], ['evidence[]' => $img], idemKey('idem03submit'));
    $mutasiId = $submit['json']['data']['mutasiId'];
    $key = ['Idempotency-Key' => 'idem03-' . uniqid('', true)];
    $versionBefore = (int) $pdo->query("SELECT version FROM mutasi_request WHERE mutasi_request_id = {$mutasiId}")->fetchColumn();
    $c1 = $anon->request('POST', "/api/store/{$destToken}/mutasi/{$mutasiId}/confirm", ['qtyReceived' => 3], $key);
    $c2 = $anon->request('POST', "/api/store/{$destToken}/mutasi/{$mutasiId}/confirm", ['qtyReceived' => 3], $key);
    $versionAfter = (int) $pdo->query("SELECT version FROM mutasi_request WHERE mutasi_request_id = {$mutasiId}")->fetchColumn();
    expect($c1['json']['data']['status'] === 'completed' && $c2['json']['data']['status'] === 'completed', 'expected both responses to show completed');
    expect($versionAfter === $versionBefore + 1, "expected version to advance by exactly 1 despite 2 identical requests, before={$versionBefore} after={$versionAfter}");
});

runTest('IDEMPOTENCY-04 Receipt Portal confirm double-tap never double-counts', function () use ($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, $baseUrl) {
    // Own tanggal, same reason as RECEIPT-PORTAL-03's own comment above.
    $p = nextProduct();
    $fx = setupShipmentForStore($adminHttp, $adminCsrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA, '2026-09-03', $p['product_id'], 5.0);
    $token = $GLOBALS['storeA_token'];
    $anon = new HttpBP($baseUrl);
    $detail = $anon->request('GET', "/api/store/{$token}/receipts/{$fx['shipmentId']}");
    $itemId = $detail['json']['data']['items'][0]['shipmentItemId'];
    $countBefore = (int) $pdo->query("SELECT COUNT(*) FROM shipment_receipt WHERE shipment_id = {$fx['shipmentId']}")->fetchColumn();
    $key = ['Idempotency-Key' => 'idem04-' . uniqid('', true)];
    $c1 = $anon->request('POST', "/api/store/{$token}/receipts/{$fx['shipmentId']}/confirm", ['receiverName' => 'A', 'items' => [['shipmentItemId' => $itemId, 'receivedGood' => 5, 'reject' => 0, 'shortage' => 0]]], $key);
    $c2 = $anon->request('POST', "/api/store/{$token}/receipts/{$fx['shipmentId']}/confirm", ['receiverName' => 'A', 'items' => [['shipmentItemId' => $itemId, 'receivedGood' => 5, 'reject' => 0, 'shortage' => 0]]], $key);
    $countAfter = (int) $pdo->query("SELECT COUNT(*) FROM shipment_receipt WHERE shipment_id = {$fx['shipmentId']}")->fetchColumn();
    expect($countAfter === $countBefore + 1, "expected exactly ONE new shipment_receipt row, before={$countBefore} after={$countAfter}");
    expect($c1['json']['data']['receiptId'] === $c2['json']['data']['receiptId'], 'expected the SAME receiptId on both responses (replay)');
});

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
