<?php

declare(strict_types=1);

/**
 * Production Division + FG & Packing rework integration test suite
 * (PDFG-01..PDFG-20). Run via api/tests/run-production-division-fg-rework.sh,
 * which stands up a disposable local MariaDB, applies ALL migrations
 * 0001-0014 for real, bootstraps realistic post-Phase-1 master data, then
 * drives the real JSON APIs end to end against a live `php -S` server.
 *
 * This suite targets ONLY the NEW logic this rework added — pre-existing
 * Production/FG behavior (target formula, snapshot semantics, reservation
 * safety, etc.) is already covered by Phase3ProductionTest.php and
 * Phase4FgPackingTest.php and is not re-tested here:
 *   - user_division_access / user_factory_access opt-in RBAC scoping
 *     (Auth::requireDivisionAccess/requireFactoryAccess)
 *   - ProductionService::patchDraft's "sesuai" server-side enforcement
 *   - FgService::patchDraft's sesuaiVerified/sesuaiPacking/reject/hilang/
 *     notes-required enforcement, and its backward-compat three-state
 *     handling (absent vs true vs false)
 *   - FgTargetService::storeBreakdownForProduct (read-only store breakdown)
 *   - UserService::updateDivisionAccess/updateFactoryAccess admin flow
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8130';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'pdfg_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpPdfg
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'pdfgcookies');
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

function idemKey(string $tag): array
{
    return ['Idempotency-Key' => $tag . '-' . uniqid('', true)];
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

function login(HttpPdfg $http, string $username, string $password): string
{
    $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    $me = $http->request('GET', '/api/auth/me');
    $csrf = $me['json']['data']['csrfToken'] ?? null;
    expect($csrf !== null, "expected a csrf token after login as {$username}");
    return $csrf;
}

/** @param array<int,array{poAwal?:float,poRevisi?:float,pb?:float}> $items productId => values, also seeds a single-store po_store_item row so FG store-breakdown has real data */
function seedPo(PDO $pdo, string $tanggal, int $factoryId, int $storeId, array $items): int
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
    $upsert = $pdo->prepare(
        'INSERT INTO po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE kategori = VALUES(kategori), po_awal = VALUES(po_awal), po_revisi = VALUES(po_revisi), pb = VALUES(pb)'
    );
    $findItemId = $pdo->prepare('SELECT po_item_id FROM po_item WHERE po_batch_id = ? AND product_id = ?');
    $upsertStore = $pdo->prepare(
        'INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal), po_revisi = VALUES(po_revisi)'
    );
    foreach ($items as $productId => $d) {
        $upsert->execute([$batchId, $productId, 'TEST', $d['poAwal'] ?? 0.0, $d['poRevisi'] ?? 0.0, $d['pb'] ?? 0.0]);
        $findItemId->execute([$batchId, $productId]);
        $itemId = (int) $findItemId->fetchColumn();
        $upsertStore->execute([$itemId, $storeId, $d['poAwal'] ?? 0.0, $d['poRevisi'] ?? 0.0]);
    }
    return $batchId;
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

$http = new HttpPdfg($baseUrl);
$csrf = login($http, $adminUser, $adminPass);

// ---------------------------------------------------------------------
// Fixtures: real factories/divisions/products/store from the Phase 1
// bootstrap (never hand-crafted names).
// ---------------------------------------------------------------------
$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
$cibadakId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Cibadak'")->fetchColumn();
expect($karangtengahId > 0 && $cibadakId > 0, 'expected both factories seeded');

$rotiBollenDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
$basicDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Basic'")->fetchColumn();
expect($rotiBollenDivId > 0 && $basicDivId > 0, 'expected test divisions seeded');

function productsInDivision(PDO $pdo, int $divisionId, int $limit): array
{
    $stmt = $pdo->prepare('SELECT product_id, name FROM product WHERE division_id = ? ORDER BY product_id LIMIT ' . (int) $limit);
    $stmt->execute([$divisionId]);
    return $stmt->fetchAll();
}
$rotiProducts = productsInDivision($pdo, $rotiBollenDivId, 2);
expect(count($rotiProducts) >= 2, 'expected enough katalog products after bootstrap');
[$prodA, $prodB] = $rotiProducts;

$storeStmt = $pdo->prepare("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'");
$storeStmt->execute();
$storeAId = (int) $storeStmt->fetchColumn();
expect($storeAId > 0, 'expected P2 TEST STORE A seeded');

$tanggal = '2026-10-05';
seedPo($pdo, $tanggal, $karangtengahId, $storeAId, [
    (int) $prodA['product_id'] => ['poAwal' => 20.0, 'poRevisi' => 0.0],
    (int) $prodB['product_id'] => ['poAwal' => 10.0, 'poRevisi' => 0.0],
]);

$adminId = createUser($pdo, 'pdfg_admin_role', 'AdminRolePass#123', ['ADMIN']);
$prodOnlyUserId = createUser($pdo, 'pdfg_production_only', 'ProdOnlyPass#123', ['PRODUCTION']);
$prodScopedUserId = createUser($pdo, 'pdfg_production_scoped', 'ProdScopedPass#123', ['PRODUCTION']);
$fgOnlyUserId = createUser($pdo, 'pdfg_fg_only', 'FgOnlyPass#123', ['FG_PACKING']);
$fgScopedUserId = createUser($pdo, 'pdfg_fg_scoped', 'FgScopedPass#123', ['FG_PACKING']);

$httpProdOnly = new HttpPdfg($baseUrl);
$csrfProdOnly = login($httpProdOnly, 'pdfg_production_only', 'ProdOnlyPass#123');
$httpProdScoped = new HttpPdfg($baseUrl);
$csrfProdScoped = login($httpProdScoped, 'pdfg_production_scoped', 'ProdScopedPass#123');
$httpFgOnly = new HttpPdfg($baseUrl);
$csrfFgOnly = login($httpFgOnly, 'pdfg_fg_only', 'FgOnlyPass#123');
$httpFgScoped = new HttpPdfg($baseUrl);
$csrfFgScoped = login($httpFgScoped, 'pdfg_fg_scoped', 'FgScopedPass#123');

// =======================================================================
// PART A — user_division_access / user_factory_access opt-in RBAC
// =======================================================================

runTest('PDFG-01 a PRODUCTION user with ZERO division assignments keeps unrestricted access (backward compat)', function () use ($httpProdOnly, $csrfProdOnly, $tanggal, $rotiBollenDivId) {
    $r = $httpProdOnly->request('GET', "/api/production/target?date={$tanggal}&divisionId={$rotiBollenDivId}", null, ['X-CSRF-Token' => $csrfProdOnly]);
    expect($r['status'] === 200, "expected unassigned PRODUCTION user to read any division freely, got {$r['status']}: " . json_encode($r['json']));
});

runTest('PDFG-02 admin assigns pdfg_production_scoped to Basic ONLY', function () use ($http, $csrf, $prodScopedUserId, $basicDivId) {
    $r = $http->request('PUT', "/api/users/{$prodScopedUserId}/divisions", ['divisionIds' => [$basicDivId]], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-02')));
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['data']['divisionIds'] === [$basicDivId], 'expected divisionIds to be exactly [basicDivId]');
});

runTest('PDFG-04 after re-login, pdfg_production_scoped CAN access its assigned division (Basic)', function () use ($baseUrl, $tanggal, $basicDivId) {
    $http2 = new HttpPdfg($baseUrl);
    $csrf2 = login($http2, 'pdfg_production_scoped', 'ProdScopedPass#123');
    $r = $http2->request('GET', "/api/production/target?date={$tanggal}&divisionId={$basicDivId}", null, ['X-CSRF-Token' => $csrf2]);
    expect($r['status'] === 200, "expected assigned division to be readable, got {$r['status']}: " . json_encode($r['json']));
});

runTest('PDFG-05 after re-login, pdfg_production_scoped is DENIED on Roti & Bollen (not assigned)', function () use ($baseUrl, $tanggal, $rotiBollenDivId) {
    $http2 = new HttpPdfg($baseUrl);
    $csrf2 = login($http2, 'pdfg_production_scoped', 'ProdScopedPass#123');
    $r = $http2->request('GET', "/api/production/target?date={$tanggal}&divisionId={$rotiBollenDivId}", null, ['X-CSRF-Token' => $csrf2]);
    expect($r['status'] === 403 && $r['json']['code'] === 'DIVISION_ACCESS_DENIED', "expected 403 DIVISION_ACCESS_DENIED, got {$r['status']}: " . json_encode($r['json']));
});

runTest('PDFG-06 ADMIN role always bypasses division scoping even with assignments', function () use ($http, $csrf, $tanggal, $rotiBollenDivId) {
    $r = $http->request('GET', "/api/production/target?date={$tanggal}&divisionId={$rotiBollenDivId}", null, ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, "expected ADMIN to always read any division, got {$r['status']}");
});

runTest('PDFG-07 a FG_PACKING user with ZERO factory assignments keeps unrestricted access (backward compat)', function () use ($httpFgOnly, $csrfFgOnly, $tanggal, $karangtengahId) {
    $r = $httpFgOnly->request('GET', "/api/fg/target?date={$tanggal}&factoryId={$karangtengahId}", null, ['X-CSRF-Token' => $csrfFgOnly]);
    expect($r['status'] === 200, "expected unassigned FG_PACKING user to read any factory freely, got {$r['status']}: " . json_encode($r['json']));
});

runTest('PDFG-08 admin assigns pdfg_fg_scoped to Cibadak ONLY, then it is denied on Karangtengah', function () use ($http, $csrf, $baseUrl, $fgScopedUserId, $cibadakId, $tanggal, $karangtengahId) {
    $r = $http->request('PUT', "/api/users/{$fgScopedUserId}/factories", ['factoryIds' => [$cibadakId]], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-08')));
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    $http2 = new HttpPdfg($baseUrl);
    $csrf2 = login($http2, 'pdfg_fg_scoped', 'FgScopedPass#123');
    $r2 = $http2->request('GET', "/api/fg/target?date={$tanggal}&factoryId={$karangtengahId}", null, ['X-CSRF-Token' => $csrf2]);
    expect($r2['status'] === 403 && $r2['json']['code'] === 'FACTORY_ACCESS_DENIED', "expected 403 FACTORY_ACCESS_DENIED, got {$r2['status']}: " . json_encode($r2['json']));
});

runTest('PDFG-09 an FG (is_verification=1) division is rejected by updateDivisionAccess', function () use ($http, $csrf, $prodScopedUserId, $karangtengahId) {
    $stmt = $GLOBALS['pdo']->prepare('SELECT division_id FROM division WHERE factory_id = ? AND is_verification = 1 LIMIT 1');
    $stmt->execute([$karangtengahId]);
    $fgDivId = (int) $stmt->fetchColumn();
    expect($fgDivId > 0, 'expected an FG division to exist for this factory');
    $r = $http->request('PUT', "/api/users/{$prodScopedUserId}/divisions", ['divisionIds' => [$fgDivId]], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-09')));
    expect($r['status'] === 400 && $r['json']['code'] === 'UNKNOWN_DIVISION', "expected 400 UNKNOWN_DIVISION for an FG division, got {$r['status']}: " . json_encode($r['json']));
});

runTest('PDFG-10 clearing division assignments (empty array) reverts the user to unrestricted access', function () use ($http, $csrf, $baseUrl, $prodScopedUserId, $tanggal, $rotiBollenDivId) {
    $r = $http->request('PUT', "/api/users/{$prodScopedUserId}/divisions", ['divisionIds' => []], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-10')));
    expect($r['status'] === 200 && $r['json']['data']['divisionIds'] === [], 'expected divisionIds cleared to []');
    $http2 = new HttpPdfg($baseUrl);
    $csrf2 = login($http2, 'pdfg_production_scoped', 'ProdScopedPass#123');
    $r2 = $http2->request('GET', "/api/production/target?date={$tanggal}&divisionId={$rotiBollenDivId}", null, ['X-CSRF-Token' => $csrf2]);
    expect($r2['status'] === 200, "expected unrestricted access again after clearing assignments, got {$r2['status']}");
});

// =======================================================================
// PART B — Production Sesuai/Tidak Sesuai server-side enforcement
// =======================================================================

$runId = null;
$runVersion = null;
runTest('PDFG-11 create a Production draft for Roti & Bollen and read its live target', function () use ($http, $csrf, $tanggal, $rotiBollenDivId, &$runId, &$runVersion, $prodA) {
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-11')));
    expect($create['status'] === 200, "expected 200, got {$create['status']}: " . json_encode($create['json']));
    $runId = (int) $create['json']['data']['productionRunId'];
    $runVersion = (int) $create['json']['data']['version'];
    $items = $create['json']['data']['items'];
    $itemA = null;
    foreach ($items as $it) { if ($it['productId'] === (int) $prodA['product_id']) { $itemA = $it; } }
    expect($itemA !== null && numEq($itemA['liveTarget'], 20.0), 'expected prodA live target 20');
});

runTest('PDFG-12 sesuai=true with actualQty MATCHING the live target is accepted', function () use ($http, $csrf, &$runId, &$runVersion, $prodA) {
    $r = $http->request('PATCH', "/api/production/{$runId}", [
        'expectedVersion' => $runVersion,
        'items' => [['productId' => (int) $prodA['product_id'], 'actualQty' => 20.0, 'sesuai' => true]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-12')));
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    $runVersion = (int) $r['json']['data']['version'];
});

runTest('PDFG-13 sesuai=true with actualQty NOT matching the live target is rejected (server never trusts a disabled UI input alone)', function () use ($http, $csrf, &$runId, &$runVersion, $prodB) {
    $r = $http->request('PATCH', "/api/production/{$runId}", [
        'expectedVersion' => $runVersion,
        'items' => [['productId' => (int) $prodB['product_id'], 'actualQty' => 3.0, 'sesuai' => true]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-13')));
    expect($r['status'] === 400 && $r['json']['code'] === 'SESUAI_ACTUAL_MISMATCH', "expected 400 SESUAI_ACTUAL_MISMATCH, got {$r['status']}: " . json_encode($r['json']));
});

runTest('PDFG-14 sesuai=false (Tidak Sesuai) allows any non-negative actualQty, no target-match required', function () use ($http, $csrf, &$runId, &$runVersion, $prodB) {
    $r = $http->request('PATCH', "/api/production/{$runId}", [
        'expectedVersion' => $runVersion,
        'items' => [['productId' => (int) $prodB['product_id'], 'actualQty' => 6.0, 'sesuai' => false]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-14')));
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    $runVersion = (int) $r['json']['data']['version'];
});

runTest('PDFG-15 a caller that never sends "sesuai" at all (legacy payload shape) is completely unaffected', function () use ($http, $csrf, &$runId, &$runVersion, $prodB) {
    $r = $http->request('PATCH', "/api/production/{$runId}", [
        'expectedVersion' => $runVersion,
        'items' => [['productId' => (int) $prodB['product_id'], 'actualQty' => 7.0]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-15')));
    expect($r['status'] === 200, "expected legacy payload (no sesuai key) to keep working, got {$r['status']}: " . json_encode($r['json']));
    $runVersion = (int) $r['json']['data']['version'];
});

// =======================================================================
// PART C — FG Sesuai/Tidak Sesuai, Reject, Hilang, store breakdown
// =======================================================================

runTest('PDFG-16 submit the Production draft so FG has an eligible source', function () use ($http, $csrf, &$runId, &$runVersion) {
    $r = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $runVersion], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-16')));
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
});

$fgBatchId = null;
$fgVersion = null;
runTest('PDFG-17 create an FG draft and confirm reject_qty/hilang_qty default to 0 on a fresh item', function () use ($http, $csrf, $tanggal, $karangtengahId, &$fgBatchId, &$fgVersion, $prodA) {
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-17')));
    expect($create['status'] === 200, "expected 200, got {$create['status']}: " . json_encode($create['json']));
    $fgBatchId = (int) $create['json']['data']['fgBatchId'];
    $fgVersion = (int) $create['json']['data']['version'];
    $itemA = null;
    foreach ($create['json']['data']['items'] as $it) { if ($it['productId'] === (int) $prodA['product_id']) { $itemA = $it; } }
    expect($itemA !== null && numEq($itemA['reject'], 0.0) && numEq($itemA['hilang'], 0.0), 'expected fresh FG item reject/hilang to default to 0');
});

runTest('PDFG-18 sesuaiVerified=true auto-target-matching FG Verified is accepted; sesuaiPacking=true auto-matching Packed is accepted', function () use ($http, $csrf, &$fgBatchId, &$fgVersion, $prodA) {
    // prodA's production actual snapshot is 20 (set in PDFG-12).
    $r = $http->request('PATCH', "/api/fg/{$fgBatchId}", [
        'expectedVersion' => $fgVersion,
        'items' => [['productId' => (int) $prodA['product_id'], 'fgVerified' => 20.0, 'packed' => 20.0, 'sesuaiVerified' => true, 'sesuaiPacking' => true]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-18')));
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    $fgVersion = (int) $r['json']['data']['version'];
});

runTest('PDFG-19 sesuaiVerified=true with a MISMATCHED fgVerified is rejected (SESUAI_VERIFIED_MISMATCH)', function () use ($http, $csrf, &$fgBatchId, &$fgVersion, $prodA) {
    $r = $http->request('PATCH', "/api/fg/{$fgBatchId}", [
        'expectedVersion' => $fgVersion,
        'items' => [['productId' => (int) $prodA['product_id'], 'fgVerified' => 15.0, 'packed' => 15.0, 'sesuaiVerified' => true, 'notes' => 'test']],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-19')));
    expect($r['status'] === 400 && $r['json']['code'] === 'SESUAI_VERIFIED_MISMATCH', "expected 400 SESUAI_VERIFIED_MISMATCH, got {$r['status']}: " . json_encode($r['json']));
});

runTest('PDFG-20 sesuaiVerified=false (Tidak Sesuai) WITHOUT notes is rejected (NOTES_REQUIRED)', function () use ($http, $csrf, &$fgBatchId, &$fgVersion, $prodA) {
    $r = $http->request('PATCH', "/api/fg/{$fgBatchId}", [
        'expectedVersion' => $fgVersion,
        'items' => [['productId' => (int) $prodA['product_id'], 'fgVerified' => 18.0, 'packed' => 18.0, 'sesuaiVerified' => false]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-20')));
    expect($r['status'] === 400 && $r['json']['code'] === 'NOTES_REQUIRED', "expected 400 NOTES_REQUIRED, got {$r['status']}: " . json_encode($r['json']));
});

runTest('PDFG-21 sesuaiVerified=false WITH notes succeeds', function () use ($http, $csrf, &$fgBatchId, &$fgVersion, $prodA) {
    $r = $http->request('PATCH', "/api/fg/{$fgBatchId}", [
        'expectedVersion' => $fgVersion,
        'items' => [['productId' => (int) $prodA['product_id'], 'fgVerified' => 18.0, 'packed' => 18.0, 'sesuaiVerified' => false, 'notes' => '2 pcs masih di line produksi']],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-21')));
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    $fgVersion = (int) $r['json']['data']['version'];
});

runTest('PDFG-22 reject>0 without notes is rejected; reject>0 WITH notes persists reject_qty independently from Actual', function () use ($http, $csrf, &$fgBatchId, &$fgVersion, $prodB) {
    // Uses prodB, which has never been touched in FG yet (no prior
    // keterangan to fall back on) — prodA already carries a note from
    // PDFG-21, which would make an "omit notes" payload silently reuse
    // that OLD note (correct, documented fallback behavior — see
    // FgService::patchDraft()'s own "isset($line['notes']) ? ... :
    // existing keterangan" — never a bug), so it can't isolate this check.
    $bad = $http->request('PATCH', "/api/fg/{$fgBatchId}", [
        'expectedVersion' => $fgVersion,
        'items' => [['productId' => (int) $prodB['product_id'], 'fgVerified' => 5.0, 'packed' => 5.0, 'reject' => 2.0]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-22a')));
    expect($bad['status'] === 400 && $bad['json']['code'] === 'NOTES_REQUIRED', "expected 400 NOTES_REQUIRED for reject>0 without notes, got {$bad['status']}: " . json_encode($bad['json']));

    $ok = $http->request('PATCH', "/api/fg/{$fgBatchId}", [
        'expectedVersion' => $fgVersion,
        'items' => [['productId' => (int) $prodB['product_id'], 'fgVerified' => 5.0, 'packed' => 5.0, 'reject' => 2.0, 'hilang' => 1.0, 'notes' => '2 reject saat QC, 1 hilang saat pindah rak']],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-22b')));
    expect($ok['status'] === 200, "expected 200, got {$ok['status']}: " . json_encode($ok['json']));
    $fgVersion = (int) $ok['json']['data']['version'];

    $batch = $http->request('GET', "/api/fg/{$fgBatchId}", null, ['X-CSRF-Token' => $csrf]);
    $itemB = null;
    foreach ($batch['json']['data']['items'] as $it) { if ($it['productId'] === (int) $prodB['product_id']) { $itemB = $it; } }
    expect($itemB !== null && numEq($itemB['reject'], 2.0) && numEq($itemB['hilang'], 1.0) && numEq($itemB['fgVerified'], 5.0),
        'expected reject=2, hilang=1, fgVerified=5 all independently stored: ' . json_encode($itemB));
});

runTest('PDFG-23 GET /api/fg/store-breakdown returns the real po_store_item data, summing to the product\'s own PO target', function () use ($http, $csrf, $tanggal, $karangtengahId, $prodA) {
    $r = $http->request('GET', "/api/fg/store-breakdown?date={$tanggal}&factoryId={$karangtengahId}&productId={$prodA['product_id']}", null, ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    $data = $r['json']['data'];
    expect(count($data['stores']) === 1 && $data['stores'][0]['storeName'] === 'P2 TEST STORE A', 'expected exactly the one seeded store');
    expect(numEq($data['totalTarget'], 20.0), "expected total store-breakdown target 20 (matches prodA's own PO target), got {$data['totalTarget']}");
});

runTest('PDFG-24 store-breakdown never writes anything (still read-only after being called repeatedly)', function () use ($http, $csrf, $tanggal, $karangtengahId, $prodA, &$fgBatchId) {
    $before = $http->request('GET', "/api/fg/{$fgBatchId}", null, ['X-CSRF-Token' => $csrf]);
    $http->request('GET', "/api/fg/store-breakdown?date={$tanggal}&factoryId={$karangtengahId}&productId={$prodA['product_id']}", null, ['X-CSRF-Token' => $csrf]);
    $http->request('GET', "/api/fg/store-breakdown?date={$tanggal}&factoryId={$karangtengahId}&productId={$prodA['product_id']}", null, ['X-CSRF-Token' => $csrf]);
    $after = $http->request('GET', "/api/fg/{$fgBatchId}", null, ['X-CSRF-Token' => $csrf]);
    expect($before['json']['data'] === $after['json']['data'], 'expected the FG batch to be byte-identical before/after repeated store-breakdown reads — no double counting, no side effects, single source of truth stays the one fg_item row');
});

// =======================================================================
// Summary
// =======================================================================
$total = count($results);
$failed = count(array_filter($results, static fn ($ok) => !$ok));
fwrite(STDOUT, "\n{$total} tests run, {$failed} failed.\n");
exit($failed > 0 ? 1 : 0);
