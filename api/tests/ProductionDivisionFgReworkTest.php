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
$rotiProducts = productsInDivision($pdo, $rotiBollenDivId, 7);
expect(count($rotiProducts) >= 7, 'expected enough katalog products after bootstrap');
[$prodA, $prodB, $prodC, $prodD, $prodE, $prodF, $prodG] = $rotiProducts;

$storeStmt = $pdo->prepare("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'");
$storeStmt->execute();
$storeAId = (int) $storeStmt->fetchColumn();
expect($storeAId > 0, 'expected P2 TEST STORE A seeded');

$storeStmtB = $pdo->prepare("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE B'");
$storeStmtB->execute();
$storeBId = (int) $storeStmtB->fetchColumn();
expect($storeBId > 0, 'expected P2 TEST STORE B seeded');

/** @param array<int,array{poAwal?:float,poRevisi?:float}> $storeAmounts store_id => amounts — writes ONE po_item split across MULTIPLE po_store_item rows (unlike seedPo(), which writes only one store per product). po_item.pb is left at 0 unless a caller upserts it separately (see FG-STORE-12's PB-zero-contribution check). */
function seedPoStoreSplit(PDO $pdo, string $tanggal, int $factoryId, int $productId, array $storeAmounts): void
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
    $totalAwal = array_sum(array_column($storeAmounts, 'poAwal'));
    $totalRevisi = array_sum(array_column($storeAmounts, 'poRevisi'));
    $pdo->prepare(
        'INSERT INTO po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (?, ?, ?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE kategori = VALUES(kategori), po_awal = VALUES(po_awal), po_revisi = VALUES(po_revisi)'
    )->execute([$batchId, $productId, 'TEST', $totalAwal, $totalRevisi]);
    $findItemId = $pdo->prepare('SELECT po_item_id FROM po_item WHERE po_batch_id = ? AND product_id = ?');
    $findItemId->execute([$batchId, $productId]);
    $itemId = (int) $findItemId->fetchColumn();
    $upsertStore = $pdo->prepare(
        'INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal), po_revisi = VALUES(po_revisi)'
    );
    foreach ($storeAmounts as $storeId => $d) {
        $upsertStore->execute([$itemId, $storeId, $d['poAwal'] ?? 0.0, $d['poRevisi'] ?? 0.0]);
    }
}

/** Submits a fresh, single-product Production draft for $divisionId/$tanggal with $actual as the sole item's actualQty, and returns nothing — used purely to give FG a SUBMITTED source for a product not otherwise touched by Part B. */
function submitProductionActual(HttpPdfg $http, string $csrf, string $tanggal, int $divisionId, int $productId, float $actual): void
{
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $divisionId], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-prod-create-' . $productId)));
    expect($create['status'] === 200, "expected production draft create 200, got {$create['status']}: " . json_encode($create['json']));
    $runId = (int) $create['json']['data']['productionRunId'];
    $version = (int) $create['json']['data']['version'];
    $patch = $http->request('PATCH', "/api/production/{$runId}", [
        'expectedVersion' => $version,
        'items' => [['productId' => $productId, 'actualQty' => $actual]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-prod-patch-' . $productId)));
    expect($patch['status'] === 200, "expected production draft patch 200, got {$patch['status']}: " . json_encode($patch['json']));
    $version = (int) $patch['json']['data']['version'];
    $submit = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $version], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-prod-submit-' . $productId)));
    expect($submit['status'] === 200, "expected production submit 200, got {$submit['status']}: " . json_encode($submit['json']));
}

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
$ppicUserId = createUser($pdo, 'pdfg_ppic', 'PpicPass#123', ['PPIC']);
// A SECOND user assigned to the SAME division as pdfg_production_scoped
// (Basic), for ACCESS-04 "two users assigned same division share the
// same run" — assigned directly here rather than via the API since it
// only needs to exist before PDFG-13's shared-worksheet check runs.
$prodScopedUser2Id = createUser($pdo, 'pdfg_production_scoped2', 'ProdScoped2Pass#123', ['PRODUCTION']);
$pdo->prepare('INSERT INTO user_division_access (user_id, division_id) VALUES (?, ?)')->execute([$prodScopedUser2Id, $basicDivId]);
// A multi-division user for ACCESS-03 "assigned two divisions, can access both".
$prodMultiUserId = createUser($pdo, 'pdfg_production_multi', 'ProdMultiPass#123', ['PRODUCTION']);
$pdo->prepare('INSERT INTO user_division_access (user_id, division_id) VALUES (?, ?), (?, ?)')
    ->execute([$prodMultiUserId, $rotiBollenDivId, $prodMultiUserId, $basicDivId]);

$httpProdOnly = new HttpPdfg($baseUrl);
$csrfProdOnly = login($httpProdOnly, 'pdfg_production_only', 'ProdOnlyPass#123');
$httpProdScoped = new HttpPdfg($baseUrl);
$csrfProdScoped = login($httpProdScoped, 'pdfg_production_scoped', 'ProdScopedPass#123');
$httpFgOnly = new HttpPdfg($baseUrl);
$csrfFgOnly = login($httpFgOnly, 'pdfg_fg_only', 'FgOnlyPass#123');
$httpFgScoped = new HttpPdfg($baseUrl);
$csrfFgScoped = login($httpFgScoped, 'pdfg_fg_scoped', 'FgScopedPass#123');
$httpPpic = new HttpPdfg($baseUrl);
$csrfPpic = login($httpPpic, 'pdfg_ppic', 'PpicPass#123');
$httpProdScoped2 = new HttpPdfg($baseUrl);
$csrfProdScoped2 = login($httpProdScoped2, 'pdfg_production_scoped2', 'ProdScoped2Pass#123');
$httpProdMulti = new HttpPdfg($baseUrl);
$csrfProdMulti = login($httpProdMulti, 'pdfg_production_multi', 'ProdMultiPass#123');

// =======================================================================
// PART A — user_division_access / user_factory_access opt-in RBAC
// =======================================================================

runTest('PDFG-01 (ACCESS-01) a PRODUCTION user with ZERO division assignments is DENIED — default-deny, never unrestricted', function () use ($httpProdOnly, $csrfProdOnly, $tanggal, $rotiBollenDivId) {
    $r = $httpProdOnly->request('GET', "/api/production/target?date={$tanggal}&divisionId={$rotiBollenDivId}", null, ['X-CSRF-Token' => $csrfProdOnly]);
    expect($r['status'] === 403 && $r['json']['code'] === 'NO_DIVISION_ASSIGNMENT', "expected 403 NO_DIVISION_ASSIGNMENT for an unassigned PRODUCTION user, got {$r['status']}: " . json_encode($r['json']));
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

runTest('PDFG-07 a FG_PACKING user with ZERO factory assignments is DENIED — default-deny, never unrestricted', function () use ($httpFgOnly, $csrfFgOnly, $tanggal, $karangtengahId) {
    $r = $httpFgOnly->request('GET', "/api/fg/target?date={$tanggal}&factoryId={$karangtengahId}", null, ['X-CSRF-Token' => $csrfFgOnly]);
    expect($r['status'] === 403 && $r['json']['code'] === 'NO_FACTORY_ASSIGNMENT', "expected 403 NO_FACTORY_ASSIGNMENT for an unassigned FG_PACKING user, got {$r['status']}: " . json_encode($r['json']));
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

runTest('PDFG-10 clearing division assignments (empty array) reverts the user to FULLY DENIED (never unrestricted)', function () use ($http, $csrf, $baseUrl, $prodScopedUserId, $tanggal, $rotiBollenDivId, $basicDivId) {
    $r = $http->request('PUT', "/api/users/{$prodScopedUserId}/divisions", ['divisionIds' => []], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-10')));
    expect($r['status'] === 200 && $r['json']['data']['divisionIds'] === [], 'expected divisionIds cleared to []');
    $http2 = new HttpPdfg($baseUrl);
    $csrf2 = login($http2, 'pdfg_production_scoped', 'ProdScopedPass#123');
    $r2 = $http2->request('GET', "/api/production/target?date={$tanggal}&divisionId={$rotiBollenDivId}", null, ['X-CSRF-Token' => $csrf2]);
    expect($r2['status'] === 403 && $r2['json']['code'] === 'NO_DIVISION_ASSIGNMENT', "expected 403 NO_DIVISION_ASSIGNMENT after clearing assignments (default-deny), got {$r2['status']}: " . json_encode($r2['json']));
    $r3 = $http2->request('GET', "/api/production/target?date={$tanggal}&divisionId={$basicDivId}", null, ['X-CSRF-Token' => $csrf2]);
    expect($r3['status'] === 403 && $r3['json']['code'] === 'NO_DIVISION_ASSIGNMENT', "expected 403 NO_DIVISION_ASSIGNMENT even on the division it WAS assigned to before clearing, got {$r3['status']}: " . json_encode($r3['json']));

    // Restore the assignment so later tests (which re-login as this user) are unaffected.
    $http->request('PUT', "/api/users/{$prodScopedUserId}/divisions", ['divisionIds' => [$basicDivId]], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-10-restore')));
});

runTest('PDFG-11a (ACCESS-03) a user assigned TWO divisions can access BOTH', function () use ($httpProdMulti, $csrfProdMulti, $tanggal, $rotiBollenDivId, $basicDivId) {
    $r1 = $httpProdMulti->request('GET', "/api/production/target?date={$tanggal}&divisionId={$rotiBollenDivId}", null, ['X-CSRF-Token' => $csrfProdMulti]);
    expect($r1['status'] === 200, "expected multi-assigned user to read Roti & Bollen, got {$r1['status']}: " . json_encode($r1['json']));
    $r2 = $httpProdMulti->request('GET', "/api/production/target?date={$tanggal}&divisionId={$basicDivId}", null, ['X-CSRF-Token' => $csrfProdMulti]);
    expect($r2['status'] === 200, "expected multi-assigned user to read Basic, got {$r2['status']}: " . json_encode($r2['json']));
});

runTest('PDFG-11b (ACCESS-07) PPIC bypasses division scoping exactly like ADMIN', function () use ($httpPpic, $csrfPpic, $tanggal, $rotiBollenDivId) {
    $r = $httpPpic->request('GET', "/api/production/target?date={$tanggal}&divisionId={$rotiBollenDivId}", null, ['X-CSRF-Token' => $csrfPpic]);
    expect($r['status'] === 200, "expected PPIC to read any division without an assignment, got {$r['status']}: " . json_encode($r['json']));
});

runTest('PDFG-11c (ACCESS-04) two users assigned the SAME division share the SAME production_run (no duplicate reports)', function () use ($baseUrl, $tanggal, $basicDivId, $pdo) {
    $tanggalShared = '2026-10-06';
    // Fresh logins are REQUIRED here (not the stale $httpProdScoped from
    // fixture setup) — division_ids are cached in session at login time,
    // and pdfg_production_scoped's assignment only happened later via the
    // PDFG-02 API call, so its original session from fixture setup never
    // picked it up (same reason PDFG-04/05 always re-login).
    $httpA = new HttpPdfg($baseUrl);
    $csrfA = login($httpA, 'pdfg_production_scoped', 'ProdScopedPass#123');
    $httpB = new HttpPdfg($baseUrl);
    $csrfB = login($httpB, 'pdfg_production_scoped2', 'ProdScoped2Pass#123');

    $createA = $httpA->request('POST', '/api/production', ['tanggal' => $tanggalShared, 'divisionId' => $basicDivId], array_merge(['X-CSRF-Token' => $csrfA], idemKey('pdfg-11c-a')));
    expect($createA['status'] === 200, "expected user A to create the shared draft, got {$createA['status']}: " . json_encode($createA['json']));
    $runIdA = (int) $createA['json']['data']['productionRunId'];

    $createB = $httpB->request('POST', '/api/production', ['tanggal' => $tanggalShared, 'divisionId' => $basicDivId], array_merge(['X-CSRF-Token' => $csrfB], idemKey('pdfg-11c-b')));
    expect($createB['status'] === 200, "expected user B to see/reuse the SAME draft, got {$createB['status']}: " . json_encode($createB['json']));
    $runIdB = (int) $createB['json']['data']['productionRunId'];
    expect($runIdA === $runIdB, "expected both users to share the SAME production_run_id, got A={$runIdA} B={$runIdB}");

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM production_run WHERE tanggal = ? AND division_id = ?');
    $countStmt->execute([$tanggalShared, $basicDivId]);
    expect((int) $countStmt->fetchColumn() === 1, 'expected exactly ONE production_run row — no duplicate report was created by the second user');
});

runTest('PDFG-11d (ACCESS-08 / FG role matrix) FG_PACKING can now actually edit FG (fixes the EDITOR_ROLES gap this pass found)', function () use ($http, $csrf, $baseUrl, $fgScopedUserId, $karangtengahId, $tanggal) {
    $assign = $http->request('PUT', "/api/users/{$fgScopedUserId}/factories", ['factoryIds' => [$karangtengahId]], array_merge(['X-CSRF-Token' => $csrf], idemKey('pdfg-11d-assign')));
    expect($assign['status'] === 200, "expected factory assignment to succeed, got {$assign['status']}");
    $http2 = new HttpPdfg($baseUrl);
    $csrf2 = login($http2, 'pdfg_fg_scoped', 'FgScopedPass#123');
    // GET /api/fg/target (a read, requires only Auth::requireAuth + the
    // factory-access gate, not EDITOR_ROLES) confirms factory access;
    // POST /api/fg (a write, requires EDITOR_ROLES) confirms the role fix.
    $read = $http2->request('GET', "/api/fg/target?date={$tanggal}&factoryId={$karangtengahId}", null, ['X-CSRF-Token' => $csrf2]);
    expect($read['status'] === 200, "expected assigned FG_PACKING user to read the factory target, got {$read['status']}: " . json_encode($read['json']));
    $write = $http2->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf2], idemKey('pdfg-11d-write')));
    expect($write['status'] !== 403, "expected FG_PACKING to NOT be blocked by role (EDITOR_ROLES), got {$write['status']}: " . json_encode($write['json']));
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
// PART D — Writable FG Breakdown Toko (FG-STORE-01..12), single source of
// truth = SUM(store rows), Option A mode switching, aggregate-once submit.
// =======================================================================

$tanggalStoreC = '2026-10-07';
seedPoStoreSplit($pdo, $tanggalStoreC, $karangtengahId, (int) $prodC['product_id'], [
    $storeAId => ['poAwal' => 15.0, 'poRevisi' => 0.0],
    $storeBId => ['poAwal' => 10.0, 'poRevisi' => 0.0],
]);
submitProductionActual($http, $csrf, $tanggalStoreC, $rotiBollenDivId, (int) $prodC['product_id'], 25.0);

$fgBatchC = null;
$fgVersionC = null;
runTest('FG-STORE-01 a fresh FG item starts Per Produk (unexploded); GET .../stores shows live per-store target summing to the product target, all-zero entered amounts', function () use ($http, $csrf, $tanggalStoreC, $karangtengahId, $prodC, &$fgBatchC, &$fgVersionC) {
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggalStoreC, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-01-create')));
    expect($create['status'] === 200, "expected 200, got {$create['status']}: " . json_encode($create['json']));
    $fgBatchC = (int) $create['json']['data']['fgBatchId'];
    $fgVersionC = (int) $create['json']['data']['version'];
    $itemC = null;
    foreach ($create['json']['data']['items'] as $it) { if ($it['productId'] === (int) $prodC['product_id']) { $itemC = $it; } }
    expect($itemC !== null && $itemC['mode'] === 'perProduk' && $itemC['storeCount'] === 1, 'expected a fresh FG item to start Per Produk with storeCount=1: ' . json_encode($itemC));

    $stores = $http->request('GET', "/api/fg/{$fgBatchC}/items/{$prodC['product_id']}/stores", null, ['X-CSRF-Token' => $csrf]);
    expect($stores['status'] === 200, "expected 200, got {$stores['status']}: " . json_encode($stores['json']));
    $data = $stores['json']['data'];
    expect($data['exploded'] === false, 'expected exploded=false before any store-level edit');
    expect(numEq($data['totalTarget'], 25.0), "expected store targets to sum to the product's own PO target (15+10=25), got {$data['totalTarget']}");
    expect(numEq($data['totalVerified'], 0.0), 'expected totalVerified=0 before explode');
    expect(count($data['stores']) === 2, 'expected exactly 2 stores (A and B)');
});

runTest('FG-STORE-02 the FIRST storeItems edit explodes the product; all-Sesuai per store aggregates correctly to the product total (product total = SUM(store rows))', function () use ($http, $csrf, &$fgBatchC, &$fgVersionC, $prodC, $storeAId, $storeBId) {
    $r = $http->request('PATCH', "/api/fg/{$fgBatchC}", [
        'expectedVersion' => $fgVersionC,
        'storeItems' => [[
            'productId' => (int) $prodC['product_id'],
            'rows' => [
                ['storeId' => $storeAId, 'fgVerified' => 15.0, 'packed' => 0.0, 'sesuaiVerified' => true],
                ['storeId' => $storeBId, 'fgVerified' => 10.0, 'packed' => 0.0, 'sesuaiVerified' => true],
            ],
        ]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-02')));
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    $fgVersionC = (int) $r['json']['data']['version'];
    $itemC = null;
    foreach ($r['json']['data']['items'] as $it) { if ($it['productId'] === (int) $prodC['product_id']) { $itemC = $it; } }
    expect($itemC !== null && $itemC['mode'] === 'breakdownToko' && $itemC['storeCount'] === 2, 'expected the product to now be exploded into 2 store rows: ' . json_encode($itemC));
    expect(numEq($itemC['fgVerified'], 25.0), "expected aggregate fgVerified = SUM(15,10) = 25, got {$itemC['fgVerified']}");
});

runTest('FG-STORE-03 Tidak Sesuai on one store row requires a numeric input and Keterangan; without notes it is rejected', function () use ($http, $csrf, &$fgBatchC, &$fgVersionC, $prodC, $storeBId) {
    $bad = $http->request('PATCH', "/api/fg/{$fgBatchC}", [
        'expectedVersion' => $fgVersionC,
        'storeItems' => [['productId' => (int) $prodC['product_id'], 'rows' => [
            ['storeId' => $storeBId, 'fgVerified' => 9.0, 'packed' => 0.0, 'sesuaiVerified' => false],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-03a')));
    expect($bad['status'] === 400 && $bad['json']['code'] === 'NOTES_REQUIRED', "expected 400 NOTES_REQUIRED, got {$bad['status']}: " . json_encode($bad['json']));

    $ok = $http->request('PATCH', "/api/fg/{$fgBatchC}", [
        'expectedVersion' => $fgVersionC,
        'storeItems' => [['productId' => (int) $prodC['product_id'], 'rows' => [
            ['storeId' => $storeBId, 'fgVerified' => 9.0, 'packed' => 0.0, 'sesuaiVerified' => false, 'notes' => '1 pcs kurang di Toko B'],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-03b')));
    expect($ok['status'] === 200, "expected 200, got {$ok['status']}: " . json_encode($ok['json']));
    $fgVersionC = (int) $ok['json']['data']['version'];
    $itemC = null;
    foreach ($ok['json']['data']['items'] as $it) { if ($it['productId'] === (int) $prodC['product_id']) { $itemC = $it; } }
    expect(numEq($itemC['fgVerified'], 24.0), "expected aggregate fgVerified = SUM(15,9) = 24, got {$itemC['fgVerified']}");
});

runTest('FG-STORE-04 store-level Sesuai enforcement is against THAT store\'s own live PO target, not the product snapshot', function () use ($http, $csrf, &$fgBatchC, &$fgVersionC, $prodC, $storeAId) {
    $r = $http->request('PATCH', "/api/fg/{$fgBatchC}", [
        'expectedVersion' => $fgVersionC,
        'storeItems' => [['productId' => (int) $prodC['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 12.0, 'packed' => 0.0, 'sesuaiVerified' => true, 'notes' => 'salah klik'],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-04')));
    expect($r['status'] === 400 && $r['json']['code'] === 'SESUAI_VERIFIED_MISMATCH', "expected 400 SESUAI_VERIFIED_MISMATCH (Toko A target is 15, not 12), got {$r['status']}: " . json_encode($r['json']));
});

runTest('FG-STORE-05 Reject/Hilang on a store row require Keterangan, independent of Verified/Packing Sesuai state', function () use ($http, $csrf, &$fgBatchC, &$fgVersionC, $prodC, $storeAId) {
    $bad = $http->request('PATCH', "/api/fg/{$fgBatchC}", [
        'expectedVersion' => $fgVersionC,
        'storeItems' => [['productId' => (int) $prodC['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 15.0, 'packed' => 0.0, 'reject' => 1.0, 'sesuaiVerified' => true],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-05a')));
    expect($bad['status'] === 400 && $bad['json']['code'] === 'NOTES_REQUIRED', "expected 400 NOTES_REQUIRED for reject>0 without notes, got {$bad['status']}: " . json_encode($bad['json']));

    $ok = $http->request('PATCH', "/api/fg/{$fgBatchC}", [
        'expectedVersion' => $fgVersionC,
        'storeItems' => [['productId' => (int) $prodC['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 15.0, 'packed' => 0.0, 'reject' => 1.0, 'sesuaiVerified' => true, 'notes' => '1 reject saat QC di Toko A'],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-05b')));
    expect($ok['status'] === 200, "expected 200, got {$ok['status']}: " . json_encode($ok['json']));
    $fgVersionC = (int) $ok['json']['data']['version'];
});

runTest('FG-STORE-06 product-level totals = SUM(store rows) exactly, across ALL columns (qty/reject), not just fgVerified', function () use ($http, $csrf, &$fgBatchC, $prodC) {
    $batch = $http->request('GET', "/api/fg/{$fgBatchC}", null, ['X-CSRF-Token' => $csrf]);
    $itemC = null;
    foreach ($batch['json']['data']['items'] as $it) { if ($it['productId'] === (int) $prodC['product_id']) { $itemC = $it; } }
    expect($itemC !== null, 'expected prodC item to exist');
    expect(numEq($itemC['fgVerified'], 24.0), "expected fgVerified = SUM(15,9) = 24, got {$itemC['fgVerified']}");
    expect(numEq($itemC['reject'], 1.0), "expected reject = SUM(1,0) = 1, got {$itemC['reject']}");
    expect(numEq($itemC['packed'], 0.0), "expected packed = SUM(0,0) = 0, got {$itemC['packed']}");
});

runTest('FG-STORE-07 while a product is in Breakdown Toko mode, editing it via the Per Produk items[] array is rejected (Option A: derived/read-only)', function () use ($http, $csrf, &$fgBatchC, &$fgVersionC, $prodC) {
    $r = $http->request('PATCH', "/api/fg/{$fgBatchC}", [
        'expectedVersion' => $fgVersionC,
        'items' => [['productId' => (int) $prodC['product_id'], 'fgVerified' => 24.0, 'packed' => 0.0]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-07')));
    expect($r['status'] === 409 && $r['json']['code'] === 'PRODUCT_IN_BREAKDOWN_MODE', "expected 409 PRODUCT_IN_BREAKDOWN_MODE, got {$r['status']}: " . json_encode($r['json']));
});

runTest('FG-STORE-08 switching modes never doubles: collapse back to Per Produk preserves the exact aggregate totals', function () use ($http, $csrf, &$fgBatchC, &$fgVersionC, $prodC) {
    $r = $http->request('PATCH', "/api/fg/{$fgBatchC}", [
        'expectedVersion' => $fgVersionC,
        'collapseProductIds' => [(int) $prodC['product_id']],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-08')));
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    $fgVersionC = (int) $r['json']['data']['version'];
    $itemC = null;
    foreach ($r['json']['data']['items'] as $it) { if ($it['productId'] === (int) $prodC['product_id']) { $itemC = $it; } }
    expect($itemC !== null && $itemC['mode'] === 'perProduk' && $itemC['storeCount'] === 1, 'expected the product to collapse back to ONE Per Produk row: ' . json_encode($itemC));
    expect(numEq($itemC['fgVerified'], 24.0) && numEq($itemC['reject'], 1.0), "expected totals unchanged by the collapse (fgVerified=24, reject=1), got: " . json_encode($itemC));

    // Re-exploding is blocked now that the collapsed Per Produk row is
    // non-zero — this system never guesses how to re-split an aggregate
    // number across stores (see explodeToStores()'s own docblock).
    $reExplode = $http->request('PATCH', "/api/fg/{$fgBatchC}", [
        'expectedVersion' => $fgVersionC,
        'storeItems' => [['productId' => (int) $prodC['product_id'], 'rows' => [['storeId' => $GLOBALS['storeAId'], 'fgVerified' => 24.0, 'packed' => 0.0]]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-08-reexplode')));
    expect($reExplode['status'] === 409 && $reExplode['json']['code'] === 'MODE_SWITCH_REQUIRES_ZERO_PER_PRODUK', "expected 409 MODE_SWITCH_REQUIRES_ZERO_PER_PRODUK, got {$reExplode['status']}: " . json_encode($reExplode['json']));
});

// A SECOND, independent product carries the submit/reopen/PO-revision
// scenarios through to a real stock posting — kept separate from prodC
// (now non-zero Per Produk, no longer explodable) so explode succeeds.
$tanggalStoreD = '2026-10-08';
seedPoStoreSplit($pdo, $tanggalStoreD, $karangtengahId, (int) $prodD['product_id'], [
    $storeAId => ['poAwal' => 12.0, 'poRevisi' => 0.0],
    $storeBId => ['poAwal' => 8.0, 'poRevisi' => 0.0],
]);
submitProductionActual($http, $csrf, $tanggalStoreD, $rotiBollenDivId, (int) $prodD['product_id'], 20.0);

$fgBatchD = null;
$fgVersionD = null;
runTest('FG-STORE-09 submit posts stock exactly ONCE for a Breakdown Toko product (one aggregated ledger row, anchored to its lowest fg_item_id)', function () use ($http, $csrf, $pdo, $tanggalStoreD, $karangtengahId, $prodD, $storeAId, $storeBId, &$fgBatchD, &$fgVersionD) {
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggalStoreD, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-09-create')));
    expect($create['status'] === 200, "expected 200, got {$create['status']}: " . json_encode($create['json']));
    $fgBatchD = (int) $create['json']['data']['fgBatchId'];
    $fgVersionD = (int) $create['json']['data']['version'];

    $explode = $http->request('PATCH', "/api/fg/{$fgBatchD}", [
        'expectedVersion' => $fgVersionD,
        'storeItems' => [['productId' => (int) $prodD['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 12.0, 'packed' => 12.0, 'sesuaiVerified' => true, 'sesuaiPacking' => true],
            ['storeId' => $storeBId, 'fgVerified' => 8.0, 'packed' => 5.0, 'sesuaiVerified' => true, 'sesuaiPacking' => false, 'notes' => 'baru 5 dari 8 yang sudah dipacking'],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-09-explode')));
    expect($explode['status'] === 200, "expected explode 200, got {$explode['status']}: " . json_encode($explode['json']));
    $fgVersionD = (int) $explode['json']['data']['version'];
    $itemD = null;
    foreach ($explode['json']['data']['items'] as $it) { if ($it['productId'] === (int) $prodD['product_id']) { $itemD = $it; } }
    expect(numEq($itemD['fgVerified'], 20.0) && numEq($itemD['packed'], 17.0), "expected aggregate verified=20 packed=17 (12+5), got: " . json_encode($itemD));

    $fgItemIdsStmt = $pdo->prepare('SELECT fg_item_id FROM fg_item WHERE fg_batch_id = ? AND product_id = ? ORDER BY fg_item_id');
    $fgItemIdsStmt->execute([$fgBatchD, (int) $prodD['product_id']]);
    $fgItemIds = array_map('intval', array_column($fgItemIdsStmt->fetchAll(), 'fg_item_id'));
    expect(count($fgItemIds) === 2, 'expected exactly 2 fg_item rows for prodD (one per store)');
    $anchorId = min($fgItemIds);

    $submit = $http->request('POST', "/api/fg/{$fgBatchD}/submit", ['expectedVersion' => $fgVersionD], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-09-submit')));
    expect($submit['status'] === 200, "expected submit 200, got {$submit['status']}: " . json_encode($submit['json']));
    $fgVersionD = (int) $submit['json']['data']['version'];

    $ledgerStmt = $pdo->prepare("SELECT source_id, qty_delta FROM stock_ledger WHERE source_type = 'fg_item' AND source_id IN (?, ?)");
    $ledgerStmt->execute($fgItemIds);
    $ledgerRows = $ledgerStmt->fetchAll();
    expect(count($ledgerRows) === 1, 'expected EXACTLY ONE stock_ledger row across BOTH of this product\'s fg_item rows — never one per store row, never one per product AND one per store');
    expect((int) $ledgerRows[0]['source_id'] === $anchorId, 'expected the single ledger row to be anchored to the LOWEST fg_item_id');
    expect(numEq($ledgerRows[0]['qty_delta'], 17.0), "expected the posted delta to equal the aggregate packed total (12+5=17), got {$ledgerRows[0]['qty_delta']}");
});

runTest('FG-STORE-10 reopen + resubmit posts only the DELTA, never re-posts the whole total (no double stock)', function () use ($http, $csrf, $pdo, &$fgBatchD, &$fgVersionD, $prodD, $storeBId) {
    $reopen = $http->request('POST', "/api/fg/{$fgBatchD}/reopen", ['expectedVersion' => $fgVersionD, 'reason' => 'Toko B belum selesai packing'], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-10-reopen')));
    expect($reopen['status'] === 200, "expected reopen 200, got {$reopen['status']}: " . json_encode($reopen['json']));
    $fgVersionD = (int) $reopen['json']['data']['version'];

    $patch = $http->request('PATCH', "/api/fg/{$fgBatchD}", [
        'expectedVersion' => $fgVersionD,
        'storeItems' => [['productId' => (int) $prodD['product_id'], 'rows' => [
            ['storeId' => $storeBId, 'fgVerified' => 8.0, 'packed' => 8.0, 'sesuaiVerified' => true, 'sesuaiPacking' => true],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-10-patch')));
    expect($patch['status'] === 200, "expected patch 200, got {$patch['status']}: " . json_encode($patch['json']));
    $fgVersionD = (int) $patch['json']['data']['version'];

    $resubmit = $http->request('POST', "/api/fg/{$fgBatchD}/submit", ['expectedVersion' => $fgVersionD], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-10-submit')));
    expect($resubmit['status'] === 200, "expected resubmit 200, got {$resubmit['status']}: " . json_encode($resubmit['json']));
    $fgVersionD = (int) $resubmit['json']['data']['version'];

    $fgItemIdsStmt = $pdo->prepare('SELECT fg_item_id FROM fg_item WHERE fg_batch_id = ? AND product_id = ?');
    $fgItemIdsStmt->execute([$fgBatchD, (int) $prodD['product_id']]);
    $fgItemIds = array_map('intval', array_column($fgItemIdsStmt->fetchAll(), 'fg_item_id'));
    $placeholders = implode(',', array_fill(0, count($fgItemIds), '?'));
    $ledgerStmt = $pdo->prepare("SELECT qty_delta FROM stock_ledger WHERE source_type = 'fg_item' AND source_id IN ({$placeholders}) ORDER BY stock_ledger_id");
    $ledgerStmt->execute($fgItemIds);
    $deltas = array_map('floatval', array_column($ledgerStmt->fetchAll(), 'qty_delta'));
    expect(count($deltas) === 2, "expected exactly 2 ledger rows total (17 then the 3 delta), got " . count($deltas) . ': ' . json_encode($deltas));
    expect(numEq($deltas[0], 17.0) && numEq($deltas[1], 3.0), "expected deltas [17, 3] (never re-posting the full 20), got " . json_encode($deltas));
    expect(numEq(array_sum($deltas), 20.0), "expected total posted stock = 20 exactly (12+8), never double, got " . array_sum($deltas));
});

runTest('FG-STORE-11 a PO revision after explode updates that store\'s live target without touching already-entered store rows or double counting', function () use ($http, $csrf, $tanggalStoreD, $karangtengahId, $pdo, $prodD, $storeAId, $storeBId, &$fgBatchD, &$fgVersionD) {
    seedPoStoreSplit($pdo, $tanggalStoreD, $karangtengahId, (int) $prodD['product_id'], [
        $storeAId => ['poAwal' => 12.0, 'poRevisi' => 3.0],
        $storeBId => ['poAwal' => 8.0, 'poRevisi' => 0.0],
    ]);
    // FG-STORE-10 left this batch 'submitted' — refresh-source (like every
    // other patchDraft-family write) is draft/reopened only, so reopen it
    // first (reopen itself never touches stock — see FgService::reopen()'s
    // own docblock).
    $reopen = $http->request('POST', "/api/fg/{$fgBatchD}/reopen", ['expectedVersion' => $fgVersionD, 'reason' => 'cek revisi PO sebelum refresh'], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-11-reopen')));
    expect($reopen['status'] === 200, "expected reopen 200, got {$reopen['status']}: " . json_encode($reopen['json']));
    $fgVersionD = (int) $reopen['json']['data']['version'];

    $refresh = $http->request('POST', "/api/fg/{$fgBatchD}/refresh-source", ['expectedVersion' => $fgVersionD], array_merge(['X-CSRF-Token' => $csrf], idemKey('fgstore-11-refresh')));
    expect($refresh['status'] === 200, "expected refresh-source 200, got {$refresh['status']}: " . json_encode($refresh['json']));
    $fgVersionD = (int) $refresh['json']['data']['version'];

    $stores = $http->request('GET', "/api/fg/{$fgBatchD}/items/{$prodD['product_id']}/stores", null, ['X-CSRF-Token' => $csrf]);
    expect($stores['status'] === 200, "expected 200, got {$stores['status']}: " . json_encode($stores['json']));
    $byStore = [];
    foreach ($stores['json']['data']['stores'] as $s) { $byStore[$s['storeId']] = $s; }
    expect(numEq($byStore[$storeAId]['target'], 15.0), "expected Toko A's live target to reflect the revision (12+3=15), got {$byStore[$storeAId]['target']}");
    expect(numEq($byStore[$storeAId]['fgVerified'], 12.0), "expected Toko A's already-entered fgVerified to stay untouched at 12 (never auto-grown to match the new target), got {$byStore[$storeAId]['fgVerified']}");
    expect(numEq($byStore[$storeBId]['target'], 8.0), "expected Toko B's target unaffected (8), got {$byStore[$storeBId]['target']}");

    $batch = $http->request('GET', "/api/fg/{$fgBatchD}", null, ['X-CSRF-Token' => $csrf]);
    $itemD = null;
    foreach ($batch['json']['data']['items'] as $it) { if ($it['productId'] === (int) $prodD['product_id']) { $itemD = $it; } }
    expect(numEq($itemD['fgVerified'], 20.0), "expected the product aggregate to stay exactly 20 (12+8) after a target-only revision, got {$itemD['fgVerified']}");
});

runTest('FG-STORE-12 PB (pra-booking) never contributes to the store target — only PO Awal + latest Revisi', function () use ($http, $csrf, $pdo, $tanggalStoreD, $karangtengahId, $prodD, &$fgBatchD) {
    $pdo->prepare('UPDATE po_item SET pb = 999 WHERE po_batch_id = (SELECT po_batch_id FROM po_batch WHERE tanggal = ? AND factory_id = ?) AND product_id = ?')
        ->execute([$tanggalStoreD, $karangtengahId, (int) $prodD['product_id']]);
    $stores = $http->request('GET', "/api/fg/{$fgBatchD}/items/{$prodD['product_id']}/stores", null, ['X-CSRF-Token' => $csrf]);
    expect($stores['status'] === 200, "expected 200, got {$stores['status']}: " . json_encode($stores['json']));
    expect(numEq($stores['json']['data']['totalTarget'], 23.0), "expected totalTarget to stay 15+8=23 despite pb=999 (PB ignored, PO Awal+Revisi only), got {$stores['json']['data']['totalTarget']}");
});

// =======================================================================
// PART E — Store-specific FG reservation for Regular shipment
// (REGSTORE-01..15): "Store A must never consume Store B's ready FG".
// =======================================================================

function createDoForStore(HttpPdfg $http, string $csrf, string $tanggal, int $storeId): array
{
    $r = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeId], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-do-create-' . $storeId . '-' . $tanggal)));
    expect($r['status'] === 200, "expected DO create 200 for store {$storeId}/{$tanggal}, got {$r['status']}: " . json_encode($r['json']));
    return $r['json']['data'];
}

/** ShipmentService::ship()'s own response DTO has no 'version' field (unlike buildDoDto) — re-fetch after every successful ship so the next call's expectedVersion is current. */
function refetchDo(HttpPdfg $http, string $csrf, int $doId): array
{
    $r = $http->request('GET', "/api/do/{$doId}", null, ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, "expected DO refetch 200, got {$r['status']}: " . json_encode($r['json']));
    return $r['json']['data'];
}

// Bring prodD's FG batch back to a clean 'submitted' baseline (Part D left
// it 'reopened' after FG-STORE-11's refresh-source) — packed stays
// storeA=12, storeB=8 (unchanged), now frozen into posted_packed_qty by
// this submit (see FgRepository::markAllItemsPosted()'s own docblock),
// which is what DoRepository::sumPackedForStore() actually reads.
runTest('REGSTORE-00 (setup) resubmit prodD\'s FG batch to a clean baseline: storeA packed=12, storeB packed=8, nothing shipped yet', function () use ($http, $csrf, &$fgBatchD, &$fgVersionD) {
    $r = $http->request('POST', "/api/fg/{$fgBatchD}/submit", ['expectedVersion' => $fgVersionD], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-00')));
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    $fgVersionD = (int) $r['json']['data']['version'];
});

$doA = null;
$doB = null;
runTest('REGSTORE-01 store-ready is visible and correct BEFORE any shipment: storeA=12 (target 15), storeB=8 (target 8)', function () use ($http, $csrf, $tanggalStoreD, $storeAId, $storeBId, $prodD, &$doA, &$doB) {
    $doA = createDoForStore($http, $csrf, $tanggalStoreD, $storeAId);
    $doB = createDoForStore($http, $csrf, $tanggalStoreD, $storeBId);
    $itemA = null;
    foreach ($doA['items'] as $it) { if ($it['productId'] === (int) $prodD['product_id']) { $itemA = $it; } }
    $itemB = null;
    foreach ($doB['items'] as $it) { if ($it['productId'] === (int) $prodD['product_id']) { $itemB = $it; } }
    expect($itemA !== null && numEq($itemA['storeReady'], 12.0), "expected storeA ready=12, got: " . json_encode($itemA));
    expect($itemB !== null && numEq($itemB['storeReady'], 8.0), "expected storeB ready=8, got: " . json_encode($itemB));
    expect(numEq($itemA['plannedQty'], 15.0) && numEq($itemB['plannedQty'], 8.0), 'expected DO planned_qty to reflect the live PO target (15/8), independent of storeReady');
});

runTest('REGSTORE-02 Store A ships 6 (within its own 12 ready) — succeeds', function () use ($http, $csrf, $prodD, &$doA) {
    $r = $http->request('POST', "/api/do/{$doA['doId']}/ship", ['expectedVersion' => $doA['version'], 'items' => [['productId' => (int) $prodD['product_id'], 'actualQty' => 6]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-02')));
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    // ShipmentService::ship()'s own response DTO has no 'version' field —
    // refetch so REGSTORE-03's expectedVersion is current.
    $doA = refetchDo($http, $csrf, $doA['doId']);
});

runTest('REGSTORE-03 Store A then attempts 7 more (only 6 remaining ready) — rejected INSUFFICIENT_STORE_READY_FG, NOT the physical/remaining-to-ship checks', function () use ($http, $csrf, $prodD, &$doA) {
    $r = $http->request('POST', "/api/do/{$doA['doId']}/ship", ['expectedVersion' => $doA['version'], 'items' => [['productId' => (int) $prodD['product_id'], 'actualQty' => 7]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-03')));
    expect($r['status'] === 409 && $r['json']['code'] === 'INSUFFICIENT_STORE_READY_FG', "expected 409 INSUFFICIENT_STORE_READY_FG (7 > 6 remaining ready, even though remaining-to-ship is 9 and physical factory stock is far higher), got {$r['status']}: " . json_encode($r['json']));
});

runTest('REGSTORE-04 Store B ships 3 (within its own 8 ready, fully independent of Store A) — succeeds', function () use ($http, $csrf, $prodD, &$doB) {
    $r = $http->request('POST', "/api/do/{$doB['doId']}/ship", ['expectedVersion' => $doB['version'], 'items' => [['productId' => (int) $prodD['product_id'], 'actualQty' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-04')));
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    $doB = refetchDo($http, $csrf, $doB['doId']);
});

runTest('REGSTORE-05 Store B\'s own remaining ready (5) is NEVER pooled into Store A — Store A\'s 7-unit attempt still fails', function () use ($http, $csrf, $prodD, &$doA) {
    $r = $http->request('POST', "/api/do/{$doA['doId']}/ship", ['expectedVersion' => $doA['version'], 'items' => [['productId' => (int) $prodD['product_id'], 'actualQty' => 7]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-05')));
    expect($r['status'] === 409 && $r['json']['code'] === 'INSUFFICIENT_STORE_READY_FG', "expected 409 INSUFFICIENT_STORE_READY_FG again — Store B freeing up capacity must never help Store A, got {$r['status']}: " . json_encode($r['json']));
});

$tanggalStoreD2 = '2026-10-09';
$doA2 = null;
runTest('REGSTORE-06 two DOs for the SAME store share ONE ready balance (no duplicated availability)', function () use ($http, $csrf, $tanggalStoreD2, $karangtengahId, $pdo, $storeAId, $prodD, &$doA, &$doA2) {
    // A second PO/DO for Store A on a DIFFERENT date — store-ready is
    // accumulated across ALL submitted fg_batches for this store+product+
    // factory (never scoped to one date), so this DO draws from the SAME
    // remaining pool as $doA (currently 6 remaining after REGSTORE-02).
    seedPoStoreSplit($pdo, $tanggalStoreD2, $karangtengahId, (int) $prodD['product_id'], [$storeAId => ['poAwal' => 4.0, 'poRevisi' => 0.0]]);
    $doA2 = createDoForStore($http, $csrf, $tanggalStoreD2, $storeAId);

    $ship1 = $http->request('POST', "/api/do/{$doA2['doId']}/ship", ['expectedVersion' => $doA2['version'], 'items' => [['productId' => (int) $prodD['product_id'], 'actualQty' => 4]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-06a')));
    expect($ship1['status'] === 200, "expected the SECOND DO's 4-unit ship to succeed (6 remaining covers it), got {$ship1['status']}: " . json_encode($ship1['json']));

    // Only 2 remains now (6 - 4) — the FIRST DO ($doA) must see that
    // shared depletion, not think 6 is still available to it.
    $ship2 = $http->request('POST', "/api/do/{$doA['doId']}/ship", ['expectedVersion' => $doA['version'], 'items' => [['productId' => (int) $prodD['product_id'], 'actualQty' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-06b')));
    expect($ship2['status'] === 409 && $ship2['json']['code'] === 'INSUFFICIENT_STORE_READY_FG', "expected the FIRST DO's 3-unit ship to fail (only 2 left in the SHARED pool), got {$ship2['status']}: " . json_encode($ship2['json']));
});

$tanggalStoreD3 = '2026-10-10';
$doB2 = null;
runTest('REGSTORE-07 concurrent shipment: two real processes race for Store B\'s remaining 5 (3+3) via TWO DIFFERENT DOs (same shared store pool), at most ONE succeeds', function () use ($http, $csrf, $adminId, &$doB, $prodD, $pdo, $tanggalStoreD3, $karangtengahId, $storeBId, &$doB2) {
    // Two DIFFERENT DOs (not the same DO twice) — otherwise the DO's own
    // delivery_order.version optimistic lock would serialize the two
    // requests and the loser would fail with VERSION_CONFLICT before ever
    // reaching the store-ready check this test actually wants to race.
    // Both DOs still draw from the SAME store_fg_balance-locked pool
    // (Store B + prodD + this factory — see REGSTORE-06's own proof of
    // that sharing), so this is still a genuine race for the same 5 units.
    seedPoStoreSplit($pdo, $tanggalStoreD3, $karangtengahId, (int) $prodD['product_id'], [$storeBId => ['poAwal' => 3.0, 'poRevisi' => 0.0]]);
    $doB2 = createDoForStore($http, $csrf, $tanggalStoreD3, $storeBId);

    $childScript = __DIR__ . '/_regular_ship_race_child.php';
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procA = proc_open(['php', $childScript, (string) $doB['doId'], (string) $doB['version'], (string) $prodD['product_id'], '3', (string) $adminId, 'MAIN'], $descriptors, $pipesA);
    $procB = proc_open(['php', $childScript, (string) $doB2['doId'], (string) $doB2['version'], (string) $prodD['product_id'], '3', (string) $adminId, 'MAIN'], $descriptors, $pipesB);

    $outA = stream_get_contents($pipesA[1]); $errA = stream_get_contents($pipesA[2]);
    fclose($pipesA[1]); fclose($pipesA[2]); $codeA = proc_close($procA);
    $outB = stream_get_contents($pipesB[1]); $errB = stream_get_contents($pipesB[2]);
    fclose($pipesB[1]); fclose($pipesB[2]); $codeB = proc_close($procB);

    expect($codeA === 0, "REGSTORE-07: child A exited {$codeA}: {$errA}");
    expect($codeB === 0, "REGSTORE-07: child B exited {$codeB}: {$errB}");
    $resA = json_decode($outA, true);
    $resB = json_decode($outB, true);
    expect($resA !== null && $resB !== null, 'REGSTORE-07: expected valid JSON from both children, got A=' . $outA . ' B=' . $outB);

    $successCount = ($resA['ok'] ? 1 : 0) + ($resB['ok'] ? 1 : 0);
    expect($successCount === 1, 'REGSTORE-07: expected EXACTLY ONE of the two concurrent 3-unit requests against 5 remaining ready to succeed, got A.ok=' . json_encode($resA['ok']) . ' B.ok=' . json_encode($resB['ok']));
    $failed = $resA['ok'] ? $resB : $resA;
    expect($failed['errorCode'] === 'INSUFFICIENT_STORE_READY_FG', 'REGSTORE-07: expected the losing request to fail with INSUFFICIENT_STORE_READY_FG, got ' . json_encode($failed));
});

runTest('REGSTORE-08 physical stock_ledger posts EXACTLY ONCE per shipment_item — the store-ready guard adds no second ledger write', function () use ($pdo, $prodD) {
    $countStmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM shipment_item si INNER JOIN shipment sh ON sh.shipment_id = si.shipment_id WHERE si.product_id = ? AND sh.source_type = 'delivery_order') AS shipment_items,
            (SELECT COUNT(*) FROM stock_ledger WHERE product_id = ? AND event_type = 'shipment_out') AS ledger_rows"
    );
    $countStmt->execute([(int) $prodD['product_id'], (int) $prodD['product_id']]);
    $counts = $countStmt->fetch();
    expect((int) $counts['shipment_items'] === (int) $counts['ledger_rows'], "expected exactly 1 stock_ledger 'shipment_out' row per shipment_item row, got {$counts['shipment_items']} shipment_items vs {$counts['ledger_rows']} ledger rows — a store-level double-deduction would show up here as ledger_rows > shipment_items");
});

// Store A's CUMULATIVE shipped by this point is 10, not 6 — REGSTORE-02
// shipped 6 via $doA, and REGSTORE-06 shipped a FURTHER 4 via $doA2 (a
// SECOND DO for the SAME store, proving the shared-pool rule) — 6+4=10.
// A correction down to 5 or 8 would ALSO trip the pre-existing physical
// GLOBAL FG RESERVATION SAFETY check first (only 4 units are still
// physically on the shelf out of 20 posted, 16 already shipped across
// both stores) — so these two tests use 8 (below the true 10 shipped,
// still within the 4-unit physical headroom) and 11 (at/above 10
// shipped, still within headroom) to cleanly isolate the NEW
// STORE_PACKED_BELOW_SHIPPED guard from the pre-existing physical one.
runTest('REGSTORE-09 FG reopen: Store A\'s packed CANNOT be corrected below what has already been shipped to it (10)', function () use ($http, $csrf, &$fgBatchD, &$fgVersionD, $prodD, $storeAId) {
    $reopen = $http->request('POST', "/api/fg/{$fgBatchD}/reopen", ['expectedVersion' => $fgVersionD, 'reason' => 'cek batas turun packed Store A'], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-09-reopen')));
    expect($reopen['status'] === 200, "expected reopen 200, got {$reopen['status']}: " . json_encode($reopen['json']));
    $fgVersionD = (int) $reopen['json']['data']['version'];

    $patch = $http->request('PATCH', "/api/fg/{$fgBatchD}", [
        'expectedVersion' => $fgVersionD,
        'storeItems' => [['productId' => (int) $prodD['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 12.0, 'packed' => 8.0, 'sesuaiPacking' => false, 'notes' => 'test turun di bawah shipped'],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-09-patch')));
    expect($patch['status'] === 200, "expected the DRAFT patch itself to succeed (409 only fires at submit), got {$patch['status']}: " . json_encode($patch['json']));
    $fgVersionD = (int) $patch['json']['data']['version'];

    $submit = $http->request('POST', "/api/fg/{$fgBatchD}/submit", ['expectedVersion' => $fgVersionD], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-09-submit')));
    expect($submit['status'] === 409 && $submit['json']['code'] === 'STORE_PACKED_BELOW_SHIPPED', "expected 409 STORE_PACKED_BELOW_SHIPPED (packed 8 < shipped 10 for Store A), got {$submit['status']}: " . json_encode($submit['json']));
});

runTest('REGSTORE-10 FG reopen: Store A\'s packed CAN be corrected to 11 (still >= shipped 10) — succeeds, remaining ready becomes 1', function () use ($http, $csrf, &$fgBatchD, &$fgVersionD, $prodD, $storeAId, $tanggalStoreD, $karangtengahId) {
    $patch = $http->request('PATCH', "/api/fg/{$fgBatchD}", [
        'expectedVersion' => $fgVersionD,
        'storeItems' => [['productId' => (int) $prodD['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 12.0, 'packed' => 11.0, 'sesuaiPacking' => false, 'notes' => 'turun ke 11, masih di atas shipped'],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-10-patch')));
    expect($patch['status'] === 200, "expected 200, got {$patch['status']}: " . json_encode($patch['json']));
    $fgVersionD = (int) $patch['json']['data']['version'];

    $submit = $http->request('POST', "/api/fg/{$fgBatchD}/submit", ['expectedVersion' => $fgVersionD], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore-10-submit')));
    expect($submit['status'] === 200, "expected 200 (11 >= shipped 10), got {$submit['status']}: " . json_encode($submit['json']));
    $fgVersionD = (int) $submit['json']['data']['version'];

    $stores = $http->request('GET', "/api/fg/{$fgBatchD}/items/{$prodD['product_id']}/stores", null, ['X-CSRF-Token' => $csrf]);
    $byStore = [];
    foreach ($stores['json']['data']['stores'] as $s) { $byStore[$s['storeId']] = $s; }
    expect(numEq($byStore[$storeAId]['packed'], 11.0), "expected Store A packed=11 after correction, got {$byStore[$storeAId]['packed']}");
});

runTest('REGSTORE-11 PO revision cannot erase shipped ownership/history — lowering Store A\'s target below its shipped qty (10) sets needsReview, never silently reclaims stock', function () use ($http, $csrf, $pdo, $tanggalStoreD, $karangtengahId, $prodD, $storeAId, $storeBId) {
    seedPoStoreSplit($pdo, $tanggalStoreD, $karangtengahId, (int) $prodD['product_id'], [
        $storeAId => ['poAwal' => 4.0, 'poRevisi' => 0.0],
        $storeBId => ['poAwal' => 8.0, 'poRevisi' => 0.0],
    ]);
    $stores = $http->request('GET', "/api/fg/{$GLOBALS['fgBatchD']}/items/{$prodD['product_id']}/stores", null, ['X-CSRF-Token' => $csrf]);
    expect($stores['status'] === 200, "expected 200, got {$stores['status']}: " . json_encode($stores['json']));
    $byStore = [];
    foreach ($stores['json']['data']['stores'] as $s) { $byStore[$s['storeId']] = $s; }
    expect((float) $byStore[$storeAId]['target'] === 4.0, "expected Store A's target to reflect the (lower) revision, got {$byStore[$storeAId]['target']}");
    expect($byStore[$storeAId]['needsReview'] === true, 'expected needsReview=true (target 4 < shipped 10) — Perlu Review Ulang, never a silent reclaim');
    expect(numEq($byStore[$storeAId]['packed'], 11.0), 'expected Store A packed to remain UNCHANGED at 11 — a PO revision never touches already-packed/already-shipped stock');
});

runTest('REGSTORE-12/13 (documented via full regression, not re-tested here) special reservation safety and cross-source (CS/Sales/Direct/General) flows are untouched', function () {
    // ShipmentService is instantiated ONLY by DoController (confirmed by
    // static grep during this task's own architecture audit) — Special/
    // CS/Sales/Direct/General orders ship through SpecialOrderDoService
    // instead, which never touches store_fg_balance/sumPackedForStore/
    // sumShippedForStore at all. Regression proof lives in re-running
    // FgAllocationTest.php's ALLOC-GLOBAL-01..20 (special reservation
    // safety) and run-final-prelive-rework.sh's FINAL-01..40 (Special/
    // Non-Regular Shipment end to end) — both already re-run as part of
    // this same suite's own cascade (see run-production-division-fg-
    // rework.sh), not duplicated here.
    expect(true, 'documented, not a runtime assertion');
});

runTest('REGSTORE-14/15 (documented, code-audited, not re-tested here) Receipt never restores store allocation; no shipment reversal path exists to preserve', function () {
    // Dispatch\ReceiptService's own docblock (audited this task): "Read-
    // only guarantee: ... never post to stock_ledger" — confirmed by
    // reading the class in full; it has NO write path to stock_ledger,
    // stock_balance, or store_fg_balance at any point, shortage/reject
    // included. Separately: Delivery\DoService::cancel() explicitly
    // REFUSES once ANY shipment exists ('CANNOT_CANCEL_SHIPPED'), and no
    // "void shipment" action exists anywhere in the codebase (confirmed by
    // grep) — there is no reversal path today, so there is nothing this
    // rework needs to keep consistent on both sides of a reversal; this
    // is the EXISTING, unchanged rule, preserved as-is per this task's own
    // explicit allowance ("if shipment cannot legally be reversed after
    // departure, preserve current rule and document it").
    expect(true, 'documented, not a runtime assertion');
});

// =======================================================================
// PART F — FINAL ATOMICITY PATCH: mode-transition vs shipment race
// (REGSTORE-16..19). See FgService::submit()'s and ShipmentService's own
// docblocks for the chosen fix: stock_balance is now locked for EVERY
// product a submit touches (not just negative-delta ones), reusing the
// SAME lock ShipmentService::ship() already holds first — no new
// schema/table was needed.
// =======================================================================

function createSentOrder(HttpPdfg $http, string $csrf, array $body, string $tag): int
{
    $r = $http->request('POST', '/api/special-orders', $body, array_merge(['X-CSRF-Token' => $csrf], idemKey($tag . 'create')));
    expect($r['status'] === 200, "{$tag}: create failed: " . json_encode($r['json']));
    $order = $r['json']['data'];
    $confirm = $http->request('POST', "/api/special-orders/{$order['orderId']}/confirm", ['expectedVersion' => $order['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey($tag . 'confirm')));
    expect($confirm['status'] === 200, "{$tag}: confirm failed: " . json_encode($confirm['json']));
    $send = $http->request('POST', "/api/special-orders/{$order['orderId']}/send-to-production", ['expectedVersion' => $confirm['json']['data']['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey($tag . 'send')));
    expect($send['status'] === 200, "{$tag}: send-to-production failed: " . json_encode($send['json']));
    return (int) $order['items'][0]['itemId'];
}

// --- REGSTORE-16 setup: prodE, Day 1 (Per Produk, physical=15 posted) ---
$tanggalR16a = '2026-10-11';
$tanggalR16b = '2026-10-12';
seedPoStoreSplit($pdo, $tanggalR16a, $karangtengahId, (int) $prodE['product_id'], [$storeAId => ['poAwal' => 15.0, 'poRevisi' => 0.0]]);
submitProductionActual($http, $csrf, $tanggalR16a, $rotiBollenDivId, (int) $prodE['product_id'], 15.0);

runTest('REGSTORE-16 (setup 1/2) prodE Day 1: Per Produk submit establishes 15 physical stock, no store allocation yet', function () use ($http, $csrf, $tanggalR16a, $karangtengahId, $prodE) {
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggalR16a, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore16a-fg-create')));
    expect($create['status'] === 200, "expected 200, got {$create['status']}: " . json_encode($create['json']));
    $fgId = (int) $create['json']['data']['fgBatchId'];
    $ver = (int) $create['json']['data']['version'];
    $patch = $http->request('PATCH', "/api/fg/{$fgId}", [
        'expectedVersion' => $ver,
        'items' => [['productId' => (int) $prodE['product_id'], 'fgVerified' => 15.0, 'packed' => 15.0, 'sesuaiVerified' => true, 'sesuaiPacking' => true]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore16a-fg-patch')));
    expect($patch['status'] === 200, "expected 200, got {$patch['status']}: " . json_encode($patch['json']));
    $ver = (int) $patch['json']['data']['version'];
    $submit = $http->request('POST', "/api/fg/{$fgId}/submit", ['expectedVersion' => $ver], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore16a-fg-submit')));
    expect($submit['status'] === 200, "expected 200, got {$submit['status']}: " . json_encode($submit['json']));
});

// --- REGSTORE-16 setup: prodE, Day 2 (explode into store rows, DRAFT only — not yet submitted) ---
seedPoStoreSplit($pdo, $tanggalR16b, $karangtengahId, (int) $prodE['product_id'], [
    // Store A's own PO target is deliberately HIGHER (15) than what gets
    // packed for it (9) — this keeps the DO's own planned_qty/remaining-
    // to-ship check from masking the store-ready guard this test exists
    // to exercise (a 10-unit request must reach the STORE check, not be
    // rejected earlier by EXCEEDS_REMAINING).
    $storeAId => ['poAwal' => 15.0, 'poRevisi' => 0.0],
    $storeBId => ['poAwal' => 6.0, 'poRevisi' => 0.0],
]);
submitProductionActual($http, $csrf, $tanggalR16b, $rotiBollenDivId, (int) $prodE['product_id'], 15.0);

$fgR16b = null;
$fgR16bVer = null;
$doR16 = null;
runTest('REGSTORE-16 (setup 2/2) prodE Day 2: explode into store rows (packed A=9,B=6) as a DRAFT only — hasAnyStoreAllocation still false until submit', function () use ($http, $csrf, $tanggalR16b, $karangtengahId, $prodE, $storeAId, $storeBId, &$fgR16b, &$fgR16bVer, &$doR16) {
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggalR16b, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore16b-fg-create')));
    expect($create['status'] === 200, "expected 200, got {$create['status']}: " . json_encode($create['json']));
    $fgR16b = (int) $create['json']['data']['fgBatchId'];
    $fgR16bVer = (int) $create['json']['data']['version'];
    $explode = $http->request('PATCH', "/api/fg/{$fgR16b}", [
        'expectedVersion' => $fgR16bVer,
        'storeItems' => [['productId' => (int) $prodE['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 9.0, 'packed' => 9.0, 'sesuaiVerified' => false, 'sesuaiPacking' => true, 'notes' => 'target PO 15, baru siap 9'],
            ['storeId' => $storeBId, 'fgVerified' => 6.0, 'packed' => 6.0, 'sesuaiVerified' => true, 'sesuaiPacking' => true],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore16b-explode')));
    expect($explode['status'] === 200, "expected explode 200, got {$explode['status']}: " . json_encode($explode['json']));
    $fgR16bVer = (int) $explode['json']['data']['version'];

    $doR16 = createDoForStore($http, $csrf, $tanggalR16b, $storeAId);
});

runTest('REGSTORE-16 MODE TRANSITION VS SHIPMENT: submit (establishing Store A=9 allocation) races a 10-unit Store A shipment — never both succeed inconsistently', function () use ($adminId, $prodE, &$fgR16b, &$fgR16bVer, &$doR16) {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procSubmit = proc_open(['php', __DIR__ . '/_fg_correction_race_child.php', (string) $fgR16b, (string) $fgR16bVer, (string) $adminId], $descriptors, $pipesSubmit);
    $procShip = proc_open(['php', __DIR__ . '/_regular_ship_race_child.php', (string) $doR16['doId'], (string) $doR16['version'], (string) $prodE['product_id'], '10', (string) $adminId, 'MAIN'], $descriptors, $pipesShip);

    $outSubmit = stream_get_contents($pipesSubmit[1]); $errSubmit = stream_get_contents($pipesSubmit[2]);
    fclose($pipesSubmit[1]); fclose($pipesSubmit[2]); $codeSubmit = proc_close($procSubmit);
    $outShip = stream_get_contents($pipesShip[1]); $errShip = stream_get_contents($pipesShip[2]);
    fclose($pipesShip[1]); fclose($pipesShip[2]); $codeShip = proc_close($procShip);

    expect($codeSubmit === 0, "REGSTORE-16: submit child exited {$codeSubmit}: {$errSubmit}");
    expect($codeShip === 0, "REGSTORE-16: ship child exited {$codeShip}: {$errShip}");
    $resSubmit = json_decode($outSubmit, true);
    $resShip = json_decode($outShip, true);
    expect($resSubmit !== null && $resShip !== null, 'REGSTORE-16: expected valid JSON from both children, got submit=' . $outSubmit . ' ship=' . $outShip);

    $bothOk = ($resSubmit['ok'] ?? false) && ($resShip['ok'] ?? false);
    expect(!$bothOk, 'REGSTORE-16: submit and ship must NEVER both succeed here (submit establishes Store A=9 ready, ship requests 10) — got: ' . json_encode(['submit' => $resSubmit, 'ship' => $resShip]));

    if ($resShip['ok'] ?? false) {
        // Ship won the race BEFORE store allocation existed — correctly
        // used the OLD state (no store ownership yet, pure physical rule).
        // The submit must then correctly REFUSE to establish Store A=9
        // while Store A has already been shipped 10 — never silently
        // finalizing an inconsistent allocation.
        expect(($resSubmit['ok'] ?? true) === false && ($resSubmit['errorCode'] ?? null) === 'STORE_PACKED_BELOW_SHIPPED',
            'REGSTORE-16: ship won first — expected the submit to then fail STORE_PACKED_BELOW_SHIPPED, got ' . json_encode($resSubmit));
    } else {
        // Submit won the race — store allocation is now authoritative.
        // Ship must then correctly see and enforce Store A's real 9-unit
        // ready quantity, rejecting the 10-unit request. It must NEVER
        // have skipped the guard just because it started checking before
        // the transition (this is the exact bug the atomicity patch closes).
        expect(($resSubmit['ok'] ?? false) === true, 'REGSTORE-16: expected submit to succeed when ship did not, got ' . json_encode($resSubmit));
        expect(($resShip['errorCode'] ?? null) === 'INSUFFICIENT_STORE_READY_FG',
            'REGSTORE-16: submit won first — expected ship to fail INSUFFICIENT_STORE_READY_FG (never EXCEEDS_AVAILABLE/EXCEEDS_REMAINING, which would mean the store guard was skipped), got ' . json_encode($resShip));
    }
});

// --- REGSTORE-17 setup: prodF, same shape as REGSTORE-16 but the ship
// child is spawned FIRST (reverse race order) ---
$tanggalR17a = '2026-10-13';
$tanggalR17b = '2026-10-14';
seedPoStoreSplit($pdo, $tanggalR17a, $karangtengahId, (int) $prodF['product_id'], [$storeAId => ['poAwal' => 15.0, 'poRevisi' => 0.0]]);
submitProductionActual($http, $csrf, $tanggalR17a, $rotiBollenDivId, (int) $prodF['product_id'], 15.0);

runTest('REGSTORE-17 (setup 1/2) prodF Day 1: Per Produk submit establishes 15 physical stock', function () use ($http, $csrf, $tanggalR17a, $karangtengahId, $prodF) {
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggalR17a, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore17a-fg-create')));
    expect($create['status'] === 200, "expected 200, got {$create['status']}: " . json_encode($create['json']));
    $fgId = (int) $create['json']['data']['fgBatchId'];
    $ver = (int) $create['json']['data']['version'];
    $patch = $http->request('PATCH', "/api/fg/{$fgId}", [
        'expectedVersion' => $ver,
        'items' => [['productId' => (int) $prodF['product_id'], 'fgVerified' => 15.0, 'packed' => 15.0, 'sesuaiVerified' => true, 'sesuaiPacking' => true]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore17a-fg-patch')));
    expect($patch['status'] === 200, "expected 200, got {$patch['status']}: " . json_encode($patch['json']));
    $ver = (int) $patch['json']['data']['version'];
    $submit = $http->request('POST', "/api/fg/{$fgId}/submit", ['expectedVersion' => $ver], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore17a-fg-submit')));
    expect($submit['status'] === 200, "expected 200, got {$submit['status']}: " . json_encode($submit['json']));
});

seedPoStoreSplit($pdo, $tanggalR17b, $karangtengahId, (int) $prodF['product_id'], [
    // Same reasoning as REGSTORE-16's own setup — Store A's PO target
    // (15) stays higher than what gets packed for it (9).
    $storeAId => ['poAwal' => 15.0, 'poRevisi' => 0.0],
    $storeBId => ['poAwal' => 6.0, 'poRevisi' => 0.0],
]);
submitProductionActual($http, $csrf, $tanggalR17b, $rotiBollenDivId, (int) $prodF['product_id'], 15.0);

$fgR17b = null;
$fgR17bVer = null;
$doR17 = null;
runTest('REGSTORE-17 (setup 2/2) prodF Day 2: explode into store rows (packed A=9,B=6) as a DRAFT only', function () use ($http, $csrf, $tanggalR17b, $karangtengahId, $prodF, $storeAId, $storeBId, &$fgR17b, &$fgR17bVer, &$doR17) {
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggalR17b, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore17b-fg-create')));
    expect($create['status'] === 200, "expected 200, got {$create['status']}: " . json_encode($create['json']));
    $fgR17b = (int) $create['json']['data']['fgBatchId'];
    $fgR17bVer = (int) $create['json']['data']['version'];
    $explode = $http->request('PATCH', "/api/fg/{$fgR17b}", [
        'expectedVersion' => $fgR17bVer,
        'storeItems' => [['productId' => (int) $prodF['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 9.0, 'packed' => 9.0, 'sesuaiVerified' => false, 'sesuaiPacking' => true, 'notes' => 'target PO 15, baru siap 9'],
            ['storeId' => $storeBId, 'fgVerified' => 6.0, 'packed' => 6.0, 'sesuaiVerified' => true, 'sesuaiPacking' => true],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore17b-explode')));
    expect($explode['status'] === 200, "expected explode 200, got {$explode['status']}: " . json_encode($explode['json']));
    $fgR17bVer = (int) $explode['json']['data']['version'];

    $doR17 = createDoForStore($http, $csrf, $tanggalR17b, $storeAId);
});

runTest('REGSTORE-17 REVERSE RACE ORDER: ship spawned FIRST, submit spawned second — same safety invariant holds regardless of spawn order', function () use ($adminId, $prodF, &$fgR17b, &$fgR17bVer, &$doR17) {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    // Reverse of REGSTORE-16's spawn order.
    $procShip = proc_open(['php', __DIR__ . '/_regular_ship_race_child.php', (string) $doR17['doId'], (string) $doR17['version'], (string) $prodF['product_id'], '10', (string) $adminId, 'MAIN'], $descriptors, $pipesShip);
    $procSubmit = proc_open(['php', __DIR__ . '/_fg_correction_race_child.php', (string) $fgR17b, (string) $fgR17bVer, (string) $adminId], $descriptors, $pipesSubmit);

    $outShip = stream_get_contents($pipesShip[1]); $errShip = stream_get_contents($pipesShip[2]);
    fclose($pipesShip[1]); fclose($pipesShip[2]); $codeShip = proc_close($procShip);
    $outSubmit = stream_get_contents($pipesSubmit[1]); $errSubmit = stream_get_contents($pipesSubmit[2]);
    fclose($pipesSubmit[1]); fclose($pipesSubmit[2]); $codeSubmit = proc_close($procSubmit);

    expect($codeShip === 0, "REGSTORE-17: ship child exited {$codeShip}: {$errShip}");
    expect($codeSubmit === 0, "REGSTORE-17: submit child exited {$codeSubmit}: {$errSubmit}");
    $resShip = json_decode($outShip, true);
    $resSubmit = json_decode($outSubmit, true);
    expect($resSubmit !== null && $resShip !== null, 'REGSTORE-17: expected valid JSON from both children, got ship=' . $outShip . ' submit=' . $outSubmit);

    $bothOk = ($resSubmit['ok'] ?? false) && ($resShip['ok'] ?? false);
    expect(!$bothOk, 'REGSTORE-17: submit and ship must NEVER both succeed here — got: ' . json_encode(['submit' => $resSubmit, 'ship' => $resShip]));

    if ($resShip['ok'] ?? false) {
        expect(($resSubmit['ok'] ?? true) === false && ($resSubmit['errorCode'] ?? null) === 'STORE_PACKED_BELOW_SHIPPED',
            'REGSTORE-17: ship won — expected submit to then fail STORE_PACKED_BELOW_SHIPPED, got ' . json_encode($resSubmit));
    } else {
        expect(($resSubmit['ok'] ?? false) === true, 'REGSTORE-17: expected submit to succeed when ship did not, got ' . json_encode($resSubmit));
        expect(($resShip['errorCode'] ?? null) === 'INSUFFICIENT_STORE_READY_FG',
            'REGSTORE-17: submit won — expected ship to fail INSUFFICIENT_STORE_READY_FG, got ' . json_encode($resShip));
    }
});

// --- REGSTORE-18: a product that NEVER enters Breakdown Toko ships
// normally under the pre-existing physical-only rule — the atomicity
// patch must not accidentally require store allocation for everyone.
// Part C's own FG batch (prodA/prodB) is patched but NEVER submitted
// (only reads/PATCHes exercise it there), so no real physical stock
// exists yet for prodB — this test establishes its own, self-contained
// Per Produk submit first. ---
$tanggalR18 = '2026-10-15';
seedPoStoreSplit($pdo, $tanggalR18, $karangtengahId, (int) $prodB['product_id'], [$storeAId => ['poAwal' => 3.0, 'poRevisi' => 0.0]]);
submitProductionActual($http, $csrf, $tanggalR18, $rotiBollenDivId, (int) $prodB['product_id'], 5.0);

runTest('REGSTORE-18 NO FALSE BLOCK FOR PURE PER-PRODUCT MODE: prodB (never exploded) ships normally', function () use ($http, $csrf, $tanggalR18, $karangtengahId, $storeAId, $prodB) {
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggalR18, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore18-fg-create')));
    expect($create['status'] === 200, "expected 200, got {$create['status']}: " . json_encode($create['json']));
    $fgId = (int) $create['json']['data']['fgBatchId'];
    $ver = (int) $create['json']['data']['version'];
    $patch = $http->request('PATCH', "/api/fg/{$fgId}", [
        'expectedVersion' => $ver,
        'items' => [['productId' => (int) $prodB['product_id'], 'fgVerified' => 5.0, 'packed' => 5.0, 'sesuaiVerified' => true, 'sesuaiPacking' => true]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore18-fg-patch')));
    expect($patch['status'] === 200, "expected 200, got {$patch['status']}: " . json_encode($patch['json']));
    $ver = (int) $patch['json']['data']['version'];
    $submit = $http->request('POST', "/api/fg/{$fgId}/submit", ['expectedVersion' => $ver], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore18-fg-submit')));
    expect($submit['status'] === 200, "expected 200, got {$submit['status']}: " . json_encode($submit['json']));

    $do = createDoForStore($http, $csrf, $tanggalR18, $storeAId);
    $r = $http->request('POST', "/api/do/{$do['doId']}/ship", ['expectedVersion' => $do['version'], 'items' => [['productId' => (int) $prodB['product_id'], 'actualQty' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore18-ship')));
    expect($r['status'] === 200, "expected 200 (Per Produk products remain unrestricted by store), got {$r['status']}: " . json_encode($r['json']));
});

// --- REGSTORE-19: 3-way contention — FG submit + Regular shipment +
// Special allocation racing for overlapping product/location. Expects
// no deadlock, no negative stock, no duplicate ledger, no store
// ownership leak. Reuses prodG, a fresh product exploded once
// (storeA=12, storeB=8, physical=20) then reopened (no value change,
// just to put it back in a submittable state for the race). ---
$tanggalR19 = '2026-10-16';
seedPoStoreSplit($pdo, $tanggalR19, $karangtengahId, (int) $prodG['product_id'], [
    $storeAId => ['poAwal' => 12.0, 'poRevisi' => 0.0],
    $storeBId => ['poAwal' => 8.0, 'poRevisi' => 0.0],
]);
submitProductionActual($http, $csrf, $tanggalR19, $rotiBollenDivId, (int) $prodG['product_id'], 20.0);

$fgR19 = null;
$fgR19Ver = null;
$doR19 = null;
$specialItemR19 = null;
runTest('REGSTORE-19 (setup) prodG: explode+submit (storeA=12,storeB=8), then reopen for the race, plus one DO and one special order for the SAME product/factory', function () use ($http, $csrf, $tanggalR19, $karangtengahId, $prodG, $storeAId, $storeBId, &$fgR19, &$fgR19Ver, &$doR19, &$specialItemR19) {
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggalR19, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore19-fg-create')));
    expect($create['status'] === 200, "expected 200, got {$create['status']}: " . json_encode($create['json']));
    $fgR19 = (int) $create['json']['data']['fgBatchId'];
    $fgR19Ver = (int) $create['json']['data']['version'];
    $explode = $http->request('PATCH', "/api/fg/{$fgR19}", [
        'expectedVersion' => $fgR19Ver,
        'storeItems' => [['productId' => (int) $prodG['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 12.0, 'packed' => 12.0, 'sesuaiVerified' => true, 'sesuaiPacking' => true],
            ['storeId' => $storeBId, 'fgVerified' => 8.0, 'packed' => 8.0, 'sesuaiVerified' => true, 'sesuaiPacking' => true],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore19-explode')));
    expect($explode['status'] === 200, "expected explode 200, got {$explode['status']}: " . json_encode($explode['json']));
    $fgR19Ver = (int) $explode['json']['data']['version'];
    $submit = $http->request('POST', "/api/fg/{$fgR19}/submit", ['expectedVersion' => $fgR19Ver], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore19-submit')));
    expect($submit['status'] === 200, "expected submit 200, got {$submit['status']}: " . json_encode($submit['json']));
    $fgR19Ver = (int) $submit['json']['data']['version'];

    $reopen = $http->request('POST', "/api/fg/{$fgR19}/reopen", ['expectedVersion' => $fgR19Ver, 'reason' => 'siapkan race REGSTORE-19'], array_merge(['X-CSRF-Token' => $csrf], idemKey('regstore19-reopen')));
    expect($reopen['status'] === 200, "expected reopen 200, got {$reopen['status']}: " . json_encode($reopen['json']));
    $fgR19Ver = (int) $reopen['json']['data']['version'];

    $doR19 = createDoForStore($http, $csrf, $tanggalR19, $storeAId);

    $specialItemR19 = createSentOrder($http, $csrf, [
        'sourceType' => 'toko_khusus', 'storeId' => $storeBId,
        'orderDate' => $tanggalR19, 'requiredDate' => $tanggalR19,
        'items' => [['itemType' => 'existing_product', 'productId' => (int) $prodG['product_id'], 'qty' => 1]],
    ], 'regstore19-');
});

runTest('REGSTORE-19 CONTENTION/DEADLOCK: FG submit + Regular shipment + Special allocation race for the SAME product/factory — no deadlock, no negative stock, no duplicate ledger, no ownership leak', function () use ($adminId, $prodG, $pdo, &$fgR19, &$fgR19Ver, &$doR19, &$specialItemR19, $karangtengahId, $storeBId) {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procSubmit = proc_open(['php', __DIR__ . '/_fg_correction_race_child.php', (string) $fgR19, (string) $fgR19Ver, (string) $adminId], $descriptors, $pipesSubmit);
    $procShip = proc_open(['php', __DIR__ . '/_regular_ship_race_child.php', (string) $doR19['doId'], (string) $doR19['version'], (string) $prodG['product_id'], '1', (string) $adminId, 'MAIN'], $descriptors, $pipesShip);
    $procAlloc = proc_open(['php', __DIR__ . '/_fg_allocate_race_child.php', (string) $specialItemR19, '1', (string) $adminId], $descriptors, $pipesAlloc);

    $outSubmit = stream_get_contents($pipesSubmit[1]); $errSubmit = stream_get_contents($pipesSubmit[2]);
    fclose($pipesSubmit[1]); fclose($pipesSubmit[2]); $codeSubmit = proc_close($procSubmit);
    $outShip = stream_get_contents($pipesShip[1]); $errShip = stream_get_contents($pipesShip[2]);
    fclose($pipesShip[1]); fclose($pipesShip[2]); $codeShip = proc_close($procShip);
    $outAlloc = stream_get_contents($pipesAlloc[1]); $errAlloc = stream_get_contents($pipesAlloc[2]);
    fclose($pipesAlloc[1]); fclose($pipesAlloc[2]); $codeAlloc = proc_close($procAlloc);

    // No hang/deadlock: every child process must exit cleanly (a real
    // InnoDB deadlock would surface as a thrown exception inside the
    // child, still caught and reported as JSON — never a hang, since
    // MariaDB's own deadlock detector kills one side automatically; a
    // process that never returns at all would make proc_close block
    // forever, which this test's own completion already disproves).
    expect($codeSubmit === 0, "REGSTORE-19: submit child exited {$codeSubmit}: {$errSubmit}");
    expect($codeShip === 0, "REGSTORE-19: ship child exited {$codeShip}: {$errShip}");
    expect($codeAlloc === 0, "REGSTORE-19: alloc child exited {$codeAlloc}: {$errAlloc}");
    $resSubmit = json_decode($outSubmit, true);
    $resShip = json_decode($outShip, true);
    $resAlloc = json_decode($outAlloc, true);
    expect($resSubmit !== null && $resShip !== null && $resAlloc !== null, 'REGSTORE-19: expected valid JSON from all three children, got submit=' . $outSubmit . ' ship=' . $outShip . ' alloc=' . $outAlloc);

    $locationId = (int) $pdo->query("SELECT location_id FROM location WHERE factory_id = {$karangtengahId}")->fetchColumn();
    $balanceStmt = $pdo->prepare('SELECT qty_on_hand FROM stock_balance WHERE product_id = ? AND location_id = ?');
    $balanceStmt->execute([(int) $prodG['product_id'], $locationId]);
    $qtyOnHand = (float) $balanceStmt->fetchColumn();
    expect($qtyOnHand >= -0.0001, "REGSTORE-19: stock_balance must never go negative, got {$qtyOnHand}");

    $ledgerStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_ledger WHERE product_id = ? AND event_type = 'shipment_out'");
    $ledgerStmt->execute([(int) $prodG['product_id']]);
    $ledgerCount = (int) $ledgerStmt->fetchColumn();
    $shipItemStmt = $pdo->prepare("SELECT COUNT(*) FROM shipment_item si INNER JOIN shipment sh ON sh.shipment_id = si.shipment_id WHERE si.product_id = ? AND sh.source_type = 'delivery_order'");
    $shipItemStmt->execute([(int) $prodG['product_id']]);
    $shipmentItemCount = (int) $shipItemStmt->fetchColumn();
    expect($ledgerCount === $shipmentItemCount, "REGSTORE-19: expected exactly one 'shipment_out' ledger row per shipment_item row (no duplicate ledger), got {$ledgerCount} ledger rows vs {$shipmentItemCount} shipment_item rows");

    // No ownership leak: Store B was never touched by this race (only
    // Store A shipped, and the special allocation is a completely
    // separate reservation, never a store_fg_balance consumer) — its
    // own packed/shipped bookkeeping must be untouched.
    $storeBShippedStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(si.qty),0) FROM shipment_item si INNER JOIN shipment sh ON sh.shipment_id = si.shipment_id
         WHERE sh.store_id = ? AND si.product_id = ? AND sh.factory_id = ? AND sh.source_type = 'delivery_order'"
    );
    $storeBShippedStmt->execute([$storeBId, (int) $prodG['product_id'], $karangtengahId]);
    $storeBShippedQty = (float) $storeBShippedStmt->fetchColumn();
    expect(numEq($storeBShippedQty, 0.0), "REGSTORE-19: expected Store B to have zero shipped qty for this product (no ownership leak from Store A's shipment or the special allocation), got {$storeBShippedQty}");
});

// =======================================================================
// Part G — HOTFIX LIVE UAT (2026-09-26 Karangtengah): "FG target 0
// products" + "Breakdown Toko not rendering" (FG-UI-01..12).
//
// Reproduces the real cPanel bug with the SAME real katalog products the
// live screenshot named (never hand-crafted names) — BOLLEN LILIT COKLAT
// and CHOCO CUBE 12 (the only two with a real production actual that day),
// plus BOLLEN COKLAT and BLACKFOREST CHOCO CASTLE 16 as the "template row
// exists, nothing was ever produced" padding products (submitted with
// actualQty=0 — see FgTargetService::productionActualByProduct()'s own
// docblock for why this happens for a normal, real division). A third
// store (P2 TEST STORE C, target 0 for both products) proves FG-UI-05
// ("only store rows with target > 0 appear") is a REAL filter, not a
// coincidence of the two seeded stores both happening to have a target.
//
// FG-UI-01/02/05/06 are verified here at the API/query layer (the exact
// layer BUG 1 was fixed at). FG-UI-03/04/07/08/09/10/11/12 are real
// browser/JS behavior — the exact layer BUG 2 was fixed at, and the exact
// layer that hides a regression from an API-only test (BUG 2 shipped
// despite every backend test passing) — so this suite deliberately leaves
// the FG batch below in DRAFT status and hands off to a REAL headless
// browser (run-ui-smoke-fg-breakdown.mjs, invoked by
// run-production-division-fg-rework.sh right after this file exits 0,
// against this SAME live server+DB+batch) to click the actual "Breakdown
// Toko" button and inspect the actual rendered DOM.
// =======================================================================
$tanggalUiHotfix = '2026-09-26';
$pastryDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Pastry'")->fetchColumn();
expect($pastryDivId > 0, 'expected Pastry division seeded');

function productByName(PDO $pdo, string $name): array
{
    $stmt = $pdo->prepare('SELECT product_id, name FROM product WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    expect($row !== false, "expected katalog product '{$name}' to exist after Phase 1 bootstrap");
    return $row;
}

$uiBollen = productByName($pdo, 'BOLLEN LILIT COKLAT');
$uiBollenPad = productByName($pdo, 'BOLLEN COKLAT');
$uiChococube = productByName($pdo, 'CHOCO CUBE 12');
$uiChococubePad = productByName($pdo, 'BLACKFOREST CHOCO CASTLE 16');

$pdo->prepare(
    "INSERT INTO store (canonical_name, channel, active, version, created_at) VALUES ('P2 TEST STORE C', NULL, 1, 1, UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE canonical_name = VALUES(canonical_name)"
)->execute();
$storeCId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE C'")->fetchColumn();
expect($storeCId > 0, 'expected P2 TEST STORE C created');

$uiFgBatchId = null;
$uiFgVersion = null;
$uiTargetPreview = null;

runTest('FG-UI-00 (setup) two divisions submit real production actual for BOLLEN LILIT COKLAT=25/CHOCO CUBE 12=14, plus a zero-actual padding product each (reproducing the live "template row exists, nothing produced" case) + PO store split (target>0 for A/B, target=0 for C)', function () use (
    $http, $csrf, $pdo, $tanggalUiHotfix, $rotiBollenDivId, $pastryDivId, $uiBollen, $uiBollenPad, $uiChococube, $uiChococubePad,
    $karangtengahId, $storeAId, $storeBId, $storeCId
) {
    // PO must exist BEFORE the production draft is created — a division's
    // production_item rows are seeded from ITS OWN live PO target for that
    // date (ProductionTargetService, read-only over Phase 2 PO) at
    // createDraft() time; a product added to the PO afterwards would need
    // an explicit refreshTargets to be pulled in. Seeding the padding
    // products' PO target here (with SOME target, but actual left 0 below)
    // is exactly the live bug's real-world root cause — a product that is
    // part of the day's PO demand but simply wasn't produced.
    seedPoStoreSplit($pdo, $tanggalUiHotfix, $karangtengahId, (int) $uiBollen['product_id'], [
        $storeAId => ['poAwal' => 15, 'poRevisi' => 0],
        $storeBId => ['poAwal' => 8, 'poRevisi' => 0],
        $storeCId => ['poAwal' => 0, 'poRevisi' => 0],
    ]);
    seedPoStoreSplit($pdo, $tanggalUiHotfix, $karangtengahId, (int) $uiChococube['product_id'], [
        $storeAId => ['poAwal' => 8, 'poRevisi' => 0],
        $storeBId => ['poAwal' => 4, 'poRevisi' => 0],
        $storeCId => ['poAwal' => 0, 'poRevisi' => 0],
    ]);
    seedPo($pdo, $tanggalUiHotfix, $karangtengahId, $storeAId, [
        (int) $uiBollenPad['product_id'] => ['poAwal' => 5, 'poRevisi' => 0],
        (int) $uiChococubePad['product_id'] => ['poAwal' => 5, 'poRevisi' => 0],
    ]);

    $createRoti = $http->request('POST', '/api/production', ['tanggal' => $tanggalUiHotfix, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('fguihotfix-roti-create')));
    expect($createRoti['status'] === 200, 'FG-UI-00: expected Roti & Bollen production draft create 200, got ' . $createRoti['status'] . ': ' . json_encode($createRoti['json']));
    $runRoti = (int) $createRoti['json']['data']['productionRunId'];
    $verRoti = (int) $createRoti['json']['data']['version'];
    $patchRoti = $http->request('PATCH', "/api/production/{$runRoti}", [
        'expectedVersion' => $verRoti,
        'items' => [
            ['productId' => (int) $uiBollen['product_id'], 'actualQty' => 25],
            ['productId' => (int) $uiBollenPad['product_id'], 'actualQty' => 0],
        ],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fguihotfix-roti-patch')));
    expect($patchRoti['status'] === 200, 'FG-UI-00: expected Roti & Bollen production patch 200, got ' . $patchRoti['status'] . ': ' . json_encode($patchRoti['json']));
    $verRoti = (int) $patchRoti['json']['data']['version'];
    $submitRoti = $http->request('POST', "/api/production/{$runRoti}/submit", ['expectedVersion' => $verRoti], array_merge(['X-CSRF-Token' => $csrf], idemKey('fguihotfix-roti-submit')));
    expect($submitRoti['status'] === 200, 'FG-UI-00: expected Roti & Bollen production submit 200, got ' . $submitRoti['status'] . ': ' . json_encode($submitRoti['json']));

    $createPastry = $http->request('POST', '/api/production', ['tanggal' => $tanggalUiHotfix, 'divisionId' => $pastryDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('fguihotfix-pastry-create')));
    expect($createPastry['status'] === 200, 'FG-UI-00: expected Pastry production draft create 200, got ' . $createPastry['status'] . ': ' . json_encode($createPastry['json']));
    $runPastry = (int) $createPastry['json']['data']['productionRunId'];
    $verPastry = (int) $createPastry['json']['data']['version'];
    $patchPastry = $http->request('PATCH', "/api/production/{$runPastry}", [
        'expectedVersion' => $verPastry,
        'items' => [
            ['productId' => (int) $uiChococube['product_id'], 'actualQty' => 14],
            ['productId' => (int) $uiChococubePad['product_id'], 'actualQty' => 0],
        ],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('fguihotfix-pastry-patch')));
    expect($patchPastry['status'] === 200, 'FG-UI-00: expected Pastry production patch 200, got ' . $patchPastry['status'] . ': ' . json_encode($patchPastry['json']));
    $verPastry = (int) $patchPastry['json']['data']['version'];
    $submitPastry = $http->request('POST', "/api/production/{$runPastry}/submit", ['expectedVersion' => $verPastry], array_merge(['X-CSRF-Token' => $csrf], idemKey('fguihotfix-pastry-submit')));
    expect($submitPastry['status'] === 200, 'FG-UI-00: expected Pastry production submit 200, got ' . $submitPastry['status'] . ': ' . json_encode($submitPastry['json']));
});

runTest('FG-UI-02 "Produksi Submitted Tersedia" (GET /api/fg/target) hides target=0 products — only BOLLEN LILIT COKLAT=25 and CHOCO CUBE 12=14 appear, never the zero-actual padding products', function () use ($http, $csrf, $tanggalUiHotfix, $karangtengahId, $uiBollen, $uiBollenPad, $uiChococube, $uiChococubePad, &$uiTargetPreview) {
    $r = $http->request('GET', "/api/fg/target?date={$tanggalUiHotfix}&factoryId={$karangtengahId}", null, ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, 'FG-UI-02: expected 200, got ' . $r['status'] . ': ' . json_encode($r['json']));
    $items = $r['json']['data']['items'];
    $uiTargetPreview = $items;
    expect(count($items) === 2, 'FG-UI-02: expected exactly 2 products with target > 0, got ' . count($items) . ': ' . json_encode($items));
    $byId = [];
    foreach ($items as $it) {
        $byId[$it['productId']] = $it;
    }
    expect(isset($byId[(int) $uiBollen['product_id']]) && numEq($byId[(int) $uiBollen['product_id']]['actual'], 25.0), 'FG-UI-02: expected BOLLEN LILIT COKLAT actual=25');
    expect(isset($byId[(int) $uiChococube['product_id']]) && numEq($byId[(int) $uiChococube['product_id']]['actual'], 14.0), 'FG-UI-02: expected CHOCO CUBE 12 actual=14');
    expect(!isset($byId[(int) $uiBollenPad['product_id']]), 'FG-UI-02: BOLLEN COKLAT (actual=0) must never appear in Produksi Submitted Tersedia');
    expect(!isset($byId[(int) $uiChococubePad['product_id']]), 'FG-UI-02: BLACKFOREST CHOCO CASTLE 16 (actual=0) must never appear in Produksi Submitted Tersedia');
});

runTest('FG-UI-01 Draft FG Per Produk (GET /api/fg/{id}) hides target=0 rows — batch created from the SAME filtered source, so it materializes exactly 2 fg_item rows, never one per padding product', function () use ($http, $csrf, $tanggalUiHotfix, $karangtengahId, $uiBollen, $uiBollenPad, $uiChococube, $uiChococubePad, &$uiFgBatchId, &$uiFgVersion) {
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggalUiHotfix, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('fguihotfix-fg-create')));
    expect($create['status'] === 200, 'FG-UI-01: expected FG draft create 200, got ' . $create['status'] . ': ' . json_encode($create['json']));
    $uiFgBatchId = (int) $create['json']['data']['fgBatchId'];
    $uiFgVersion = (int) $create['json']['data']['version'];

    $get = $http->request('GET', "/api/fg/{$uiFgBatchId}");
    expect($get['status'] === 200, 'FG-UI-01: expected FG get 200, got ' . $get['status']);
    $items = $get['json']['data']['items'];
    expect(count($items) === 2, 'FG-UI-01: expected exactly 2 rows in Draft FG Per Produk, got ' . count($items) . ': ' . json_encode(array_column($items, 'productName')));
    $names = array_column($items, 'productName');
    expect(in_array('BOLLEN LILIT COKLAT', $names, true), 'FG-UI-01: expected BOLLEN LILIT COKLAT row');
    expect(in_array('CHOCO CUBE 12', $names, true), 'FG-UI-01: expected CHOCO CUBE 12 row');
    expect(!in_array('BOLLEN COKLAT', $names, true), 'FG-UI-01: BOLLEN COKLAT (target 0) must not appear');
    expect(!in_array('BLACKFOREST CHOCO CASTLE 16', $names, true), 'FG-UI-01: BLACKFOREST CHOCO CASTLE 16 (target 0) must not appear');
});

runTest('FG-UI-05/FG-UI-06 Breakdown Toko store rows (GET /api/fg/{id}/items/{productId}/stores) only include stores with target > 0, and their targets sum to the product\'s authoritative PO target', function () use ($http, $uiBollen, $uiChococube, $storeAId, $storeBId, $storeCId, &$uiFgBatchId) {
    $rBollen = $http->request('GET', "/api/fg/{$uiFgBatchId}/items/{$uiBollen['product_id']}/stores");
    expect($rBollen['status'] === 200, 'FG-UI-05: expected 200 for BOLLEN LILIT COKLAT stores, got ' . $rBollen['status']);
    $storesBollen = $rBollen['json']['data']['stores'];
    $storeIdsBollen = array_column($storesBollen, 'storeId');
    expect(count($storesBollen) === 2, 'FG-UI-05: expected exactly 2 store rows (A and B) for BOLLEN LILIT COKLAT, got ' . count($storesBollen));
    expect(in_array($storeAId, $storeIdsBollen, true) && in_array($storeBId, $storeIdsBollen, true), 'FG-UI-05: expected Store A and Store B to be present');
    expect(!in_array($storeCId, $storeIdsBollen, true), 'FG-UI-05: Store C (target 0) must never appear');
    expect(numEq($rBollen['json']['data']['totalTarget'], 23.0), 'FG-UI-06: expected BOLLEN LILIT COKLAT store targets to sum to 23 (15+8), got ' . $rBollen['json']['data']['totalTarget']);

    $rChococube = $http->request('GET', "/api/fg/{$uiFgBatchId}/items/{$uiChococube['product_id']}/stores");
    expect($rChococube['status'] === 200, 'FG-UI-05: expected 200 for CHOCO CUBE 12 stores, got ' . $rChococube['status']);
    $storesChococube = $rChococube['json']['data']['stores'];
    $storeIdsChococube = array_column($storesChococube, 'storeId');
    expect(count($storesChococube) === 2, 'FG-UI-05: expected exactly 2 store rows (A and B) for CHOCO CUBE 12, got ' . count($storesChococube));
    expect(!in_array($storeCId, $storeIdsChococube, true), 'FG-UI-05: Store C (target 0) must never appear for CHOCO CUBE 12 either');
    expect(numEq($rChococube['json']['data']['totalTarget'], 12.0), 'FG-UI-06: expected CHOCO CUBE 12 store targets to sum to 12 (8+4), got ' . $rChococube['json']['data']['totalTarget']);
});

// Deliberately no submit() here — the FG batch is handed to
// run-ui-smoke-fg-breakdown.mjs below in DRAFT status, exactly matching
// the real cPanel UAT screenshot (a batch still being worked on), and so
// the Sesuai/Tidak Sesuai/actual-input editable controls FG-UI-07..10
// checks are still rendered.
fwrite(STDOUT, "FG_UI_HOTFIX_FACTORY_ID={$karangtengahId}\n");
fwrite(STDOUT, "FG_UI_HOTFIX_TANGGAL={$tanggalUiHotfix}\n");

// =======================================================================
// Part H — RECONCILIATION AUDIT (RECON-01..08): "product target" vs
// "store target sum" for Regular PO demand.
//
// Triggered by a report-clarity concern: the Part G delivery report used
// the word "target" for TWO DIFFERENT, pre-existing (long before this
// session) canonical numbers without always naming which one:
//   - Production Actual (fg_item.production_actual_snapshot, sourced from
//     Phase 3 production_item.aktual — "how much was actually produced")
//   - Regular PO Demand Target (po_item.po_awal + po_revisi, PB excluded —
//     "how much the stores ordered", read-only, NEVER written by FG) —
//     the SAME number FgTargetService::storeBreakdownForProduct() sums
//     per-store and ProductionTargetService::targetsByProduct() reads
//     per-product; both are plain aggregates over the SAME po_item/
//     po_store_item rows, so they reconcile with each other BY
//     CONSTRUCTION (PoRepository::applyLines keeps po_item.po_awal/
//     po_revisi as the sum of that product's po_store_item rows on every
//     write — see its own docblock) — but NEITHER of them is the same
//     number as Production Actual, which is a wholly separate, legitimately
//     independent quantity from a different phase (Production, not PO).
//     A factory can produce more OR less than stores ordered; this suite
//     uses a DELIBERATELY different Production Actual (25) from Regular PO
//     Demand Target (23) specifically so the two are never mistakable for
//     the same figure. This part proves the reconciliation invariant that
//     DOES hold (product PO target == SUM(store PO targets)) and the one
//     that must NEVER be assumed (PO target == Production Actual), using a
//     fresh, isolated product/date (never touching Part G's own BOLLEN
//     LILIT COKLAT / CHOCO CUBE 12 batch, which run-ui-smoke-fg-breakdown-
//     toko.mjs still inspects in a specific pristine shape afterward).
// =======================================================================
$reconTanggal1 = '2026-10-17';
$reconTanggal2 = '2026-10-18';

runTest('RECON-01/02 Regular product PO target = SUM(store PO targets), PO Awal only (po_revisi=0) reconciles, and this is a DIFFERENT number from Production Actual', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA, $storeAId, $storeBId, $storeCId, $reconTanggal1) {
    seedPoStoreSplit($pdo, $reconTanggal1, $karangtengahId, (int) $prodA['product_id'], [
        $storeAId => ['poAwal' => 15, 'poRevisi' => 0],
        $storeBId => ['poAwal' => 8, 'poRevisi' => 0],
        $storeCId => ['poAwal' => 0, 'poRevisi' => 0],
    ]);
    submitProductionActual($http, $csrf, $reconTanggal1, $rotiBollenDivId, (int) $prodA['product_id'], 25.0);

    // Direct DB truth: po_item is the per-product ROLLUP PoRepository::
    // applyLines() keeps in sync with the SUM of its own po_store_item
    // rows on every write — this is the "product PO target" number.
    $poItemStmt = $pdo->prepare(
        'SELECT i.po_awal, i.po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id
         WHERE b.tanggal = ? AND b.factory_id = ? AND i.product_id = ?'
    );
    $poItemStmt->execute([$reconTanggal1, $karangtengahId, (int) $prodA['product_id']]);
    $poItemRow = $poItemStmt->fetch();
    $poItemTarget = (float) $poItemRow['po_awal'] + (float) $poItemRow['po_revisi'];
    expect(numEq($poItemTarget, 23.0), "RECON-01: expected po_item-level product PO target 23 (15+8+0), got {$poItemTarget}");
    expect(numEq((float) $poItemRow['po_revisi'], 0.0), 'RECON-02: expected po_revisi=0 for a PO-Awal-only product (no revision uploaded yet)');

    // The SAME number, read via the REAL API (storeBreakdown() preview —
    // FgTargetService::storeBreakdownForProduct(), the exact source
    // batchProductStores()/explodeToStores() also use).
    $preview = $http->request('GET', "/api/fg/store-breakdown?date={$reconTanggal1}&factoryId={$karangtengahId}&productId={$prodA['product_id']}");
    expect($preview['status'] === 200, 'RECON-01: expected store-breakdown preview 200, got ' . $preview['status']);
    expect(numEq($preview['json']['data']['totalTarget'], 23.0), "RECON-01: expected store-breakdown totalTarget 23 (SUM of store PO targets), got {$preview['json']['data']['totalTarget']}");
    expect(count($preview['json']['data']['stores']) === 2, 'RECON-01: expected exactly 2 store rows (A, B — C is target=0)');

    // Explicit, concrete proof these are TWO DIFFERENT canonical numbers —
    // never assume "target" alone means the same thing in both places.
    $target = $http->request('GET', "/api/fg/target?date={$reconTanggal1}&factoryId={$karangtengahId}");
    $prodAActual = current(array_filter($target['json']['data']['items'], fn ($i) => $i['productId'] === (int) $prodA['product_id']));
    expect($prodAActual !== false && numEq($prodAActual['actual'], 25.0), 'RECON-01: expected Production Actual 25 for prodA');
    expect(abs(25.0 - 23.0) > 0.0001, 'RECON-01: Production Actual (25) and Regular PO Demand Target (23) are deliberately DIFFERENT numbers here — this is expected, not a bug');
});

runTest('RECON-08 excess Production Actual above Regular store demand is represented as variance (Selisih), never silently attributed to any one store', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA, $storeAId, $storeBId, $reconTanggal1) {
    // Same fixture as RECON-01/02: Production Actual 25, Regular PO
    // Demand Target 23 (Store A=15, Store B=8). Explodes into store rows,
    // verifies each store at EXACTLY its own PO target (Sesuai — the
    // legitimate ceiling per store), then proves the un-verifiable excess
    // (25 - 23 = 2) surfaces as this product's own variance/Selisih, is
    // never force-fit into either store, and a store can never be pushed
    // past its OWN target to "absorb" it (STORE_FG_EXCEEDS_TARGET).
    $create = $http->request('POST', '/api/fg', ['tanggal' => $reconTanggal1, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('recon08-create')));
    expect($create['status'] === 200, 'RECON-08: expected FG create 200, got ' . $create['status'] . ': ' . json_encode($create['json']));
    $batchId = (int) $create['json']['data']['fgBatchId'];
    $version = (int) $create['json']['data']['version'];

    $overAllocate = $http->request('PATCH', "/api/fg/{$batchId}", [
        'expectedVersion' => $version,
        'storeItems' => [['productId' => (int) $prodA['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 16, 'packed' => 0, 'sesuaiVerified' => false, 'notes' => 'attempting to exceed store A own target'],
            ['storeId' => $storeBId, 'fgVerified' => 0, 'packed' => 0],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('recon08-over')));
    expect($overAllocate['status'] === 400 && ($overAllocate['json']['code'] ?? null) === 'STORE_FG_EXCEEDS_TARGET', 'RECON-08: expected STORE_FG_EXCEEDS_TARGET when a store is pushed past its OWN PO target (16 > 15), got ' . json_encode($overAllocate));

    $save = $http->request('PATCH', "/api/fg/{$batchId}", [
        'expectedVersion' => $version,
        'storeItems' => [['productId' => (int) $prodA['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 15, 'packed' => 0, 'sesuaiVerified' => true],
            ['storeId' => $storeBId, 'fgVerified' => 8, 'packed' => 0, 'sesuaiVerified' => true],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('recon08-save')));
    expect($save['status'] === 200, 'RECON-08: expected save at each store\'s own target 200, got ' . $save['status'] . ': ' . json_encode($save['json']));

    $show = $http->request('GET', "/api/fg/{$batchId}");
    $item = current(array_filter($show['json']['data']['items'], fn ($i) => $i['productId'] === (int) $prodA['product_id']));
    expect($item !== false, 'RECON-08: expected prodA item present');
    expect(numEq($item['fgVerified'], 23.0), "RECON-08: expected aggregate FG Verified 23 (15+8, the SUM of what each store could legitimately take), got {$item['fgVerified']}");
    expect(numEq($item['productionActualSnapshot'], 25.0), 'RECON-08: expected Production Actual snapshot still 25 (unchanged, read-only)');
    expect(numEq($item['variance'], 2.0), "RECON-08: expected variance/Selisih = 25 - 23 = 2 (the excess Production Actual above Regular store demand) — it must show up here, NEVER silently pushed into either store's own fgVerified, got {$item['variance']}");
});

runTest('RECON-03 a PO revision reconciles WITHOUT cumulative double-counting (latest snapshot only, never awal+revisi stacked on top of a previous revisi)', function () use ($http, $pdo, $karangtengahId, $prodA, $storeAId, $storeBId, $storeCId, $reconTanggal1) {
    // Store A gets a +3 revision (15 -> 18); Store B/C untouched. If this
    // system ever summed po_revisi cumulatively across uploads instead of
    // treating each upload as the new snapshot (see PoRepository's own
    // "UPDATE po_store_item SET po_awal = ?, po_revisi = ?" — an
    // unconditional overwrite, never an addition), the new total would be
    // wrong (e.g. 23 + 18 = 41) instead of the correct 26 (18+8+0).
    seedPoStoreSplit($pdo, $reconTanggal1, $karangtengahId, (int) $prodA['product_id'], [
        $storeAId => ['poAwal' => 15, 'poRevisi' => 3],
        $storeBId => ['poAwal' => 8, 'poRevisi' => 0],
        $storeCId => ['poAwal' => 0, 'poRevisi' => 0],
    ]);
    $poItemStmt = $pdo->prepare(
        'SELECT i.po_awal, i.po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id
         WHERE b.tanggal = ? AND b.factory_id = ? AND i.product_id = ?'
    );
    $poItemStmt->execute([$reconTanggal1, $karangtengahId, (int) $prodA['product_id']]);
    $poItemRow = $poItemStmt->fetch();
    $poItemTarget = (float) $poItemRow['po_awal'] + (float) $poItemRow['po_revisi'];
    expect(numEq($poItemTarget, 26.0), "RECON-03: expected po_item target 26 after revision (18+8+0), NOT 49 (double-counted), got {$poItemTarget}");

    $preview = $http->request('GET', "/api/fg/store-breakdown?date={$reconTanggal1}&factoryId={$karangtengahId}&productId={$prodA['product_id']}");
    expect(numEq($preview['json']['data']['totalTarget'], 26.0), "RECON-03: expected store-breakdown totalTarget 26 after revision, got {$preview['json']['data']['totalTarget']}");
});

runTest('RECON-04 PB (pra-booking) contributes ZERO to either the product-level or store-level target — structurally impossible to leak (po_store_item has no pb column at all)', function () use ($pdo, $karangtengahId, $http, $prodA, $reconTanggal1) {
    $pdo->prepare(
        'UPDATE po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id
         SET i.pb = 999 WHERE b.tanggal = ? AND b.factory_id = ? AND i.product_id = ?'
    )->execute([$reconTanggal1, $karangtengahId, (int) $prodA['product_id']]);

    $poItemStmt = $pdo->prepare(
        'SELECT i.po_awal, i.po_revisi, i.pb FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id
         WHERE b.tanggal = ? AND b.factory_id = ? AND i.product_id = ?'
    );
    $poItemStmt->execute([$reconTanggal1, $karangtengahId, (int) $prodA['product_id']]);
    $poItemRow = $poItemStmt->fetch();
    expect(numEq((float) $poItemRow['pb'], 999.0), 'RECON-04: expected pb=999 to actually be stored (sanity check on the test itself)');
    $poItemTarget = (float) $poItemRow['po_awal'] + (float) $poItemRow['po_revisi'];
    expect(numEq($poItemTarget, 26.0), "RECON-04: expected po_item target to remain 26 — pb=999 must contribute ZERO, got {$poItemTarget}");

    $preview = $http->request('GET', "/api/fg/store-breakdown?date={$reconTanggal1}&factoryId={$karangtengahId}&productId={$prodA['product_id']}");
    expect(numEq($preview['json']['data']['totalTarget'], 26.0), "RECON-04: expected store-breakdown totalTarget to remain 26 despite pb=999 (po_store_item has no pb column — structurally cannot leak in), got {$preview['json']['data']['totalTarget']}");
});

runTest('RECON-05/06 zero-target-store cleanup hides ONLY the truly-zero store, never a positive-target one', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB, $storeAId, $storeBId, $storeCId, $reconTanggal2) {
    seedPoStoreSplit($pdo, $reconTanggal2, $karangtengahId, (int) $prodB['product_id'], [
        $storeAId => ['poAwal' => 10, 'poRevisi' => 0],
        $storeBId => ['poAwal' => 0, 'poRevisi' => 0],
        $storeCId => ['poAwal' => 0, 'poRevisi' => 0],
    ]);
    submitProductionActual($http, $csrf, $reconTanggal2, $rotiBollenDivId, (int) $prodB['product_id'], 10.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $reconTanggal2, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('recon0506-create')));
    expect($create['status'] === 200, 'RECON-05/06: expected FG create 200, got ' . $create['status'] . ': ' . json_encode($create['json']));
    $batchId = (int) $create['json']['data']['fgBatchId'];

    $stores = $http->request('GET', "/api/fg/{$batchId}/items/{$prodB['product_id']}/stores");
    expect($stores['status'] === 200, 'RECON-05/06: expected stores 200');
    $storeIds = array_column($stores['json']['data']['stores'], 'storeId');
    expect(!in_array($storeBId, $storeIds, true), 'RECON-05: Store B (target=0) must be hidden');
    expect(!in_array($storeCId, $storeIds, true), 'RECON-05: Store C (target=0) must be hidden');
    expect(in_array($storeAId, $storeIds, true), 'RECON-06: Store A (target=10, positive) must NEVER be hidden by the zero-target cleanup');
    expect(count($stores['json']['data']['stores']) === 1, 'RECON-06: expected exactly 1 visible store (only the positive-target one)');
});

runTest('RECON-07 Per Produk -> Breakdown Toko -> Per Produk preserves the same Regular demand total (round trip never drops or duplicates it, and never writes to po_item/po_store_item)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeAId) {
    // Dedicated product + date, exclusively for this one test — sumShippedForStore()
    // (behind CANNOT_COLLAPSE_STORE_ALREADY_SHIPPED) is scoped by product+store+
    // factory only, NEVER by date/batch (shipment/stock are not date-scoped — see
    // Delivery\ShipmentService's own docblock), so reusing a product+store pair
    // that some OTHER test in this file has ever really shipped (e.g. REGSTORE-18
    // ships prodB via Store A) would wrongly trip that guard here. A product/store
    // pair this suite has never shipped anything for is required for a clean
    // round-trip check.
    $reconTanggal3 = '2026-10-19';
    $prodRecon07 = productsInDivision($pdo, $rotiBollenDivId, 8)[7];
    seedPoStoreSplit($pdo, $reconTanggal3, $karangtengahId, (int) $prodRecon07['product_id'], [$storeAId => ['poAwal' => 10, 'poRevisi' => 0]]);
    submitProductionActual($http, $csrf, $reconTanggal3, $rotiBollenDivId, (int) $prodRecon07['product_id'], 10.0);

    $poItemStmt = $pdo->prepare(
        'SELECT i.po_awal, i.po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id
         WHERE b.tanggal = ? AND b.factory_id = ? AND i.product_id = ?'
    );
    $poItemStmt->execute([$reconTanggal3, $karangtengahId, (int) $prodRecon07['product_id']]);
    $before = $poItemStmt->fetch();

    $create = $http->request('POST', '/api/fg', ['tanggal' => $reconTanggal3, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('recon07-fgcreate')));
    expect($create['status'] === 200, 'RECON-07: expected FG create 200, got ' . $create['status'] . ': ' . json_encode($create['json']));
    $batchId = (int) $create['json']['data']['fgBatchId'];
    $version = (int) $create['json']['data']['version'];

    $explode = $http->request('PATCH', "/api/fg/{$batchId}", [
        'expectedVersion' => $version,
        'storeItems' => [['productId' => (int) $prodRecon07['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 10, 'packed' => 0, 'sesuaiVerified' => true],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('recon07-explode')));
    expect($explode['status'] === 200, 'RECON-07: expected explode 200, got ' . json_encode($explode['json']));
    $version = (int) $explode['json']['data']['version'];

    $collapse = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $version, 'collapseProductIds' => [(int) $prodRecon07['product_id']]], array_merge(['X-CSRF-Token' => $csrf], idemKey('recon07-collapse')));
    expect($collapse['status'] === 200, 'RECON-07: expected collapse 200, got ' . json_encode($collapse['json']));

    $show = $http->request('GET', "/api/fg/{$batchId}");
    $item = current(array_filter($show['json']['data']['items'], fn ($i) => $i['productId'] === (int) $prodRecon07['product_id']));
    expect($item !== false && numEq($item['fgVerified'], 10.0), "RECON-07: expected FG Verified still 10 after the explode->collapse round trip, got " . json_encode($item));

    $poItemStmt->execute([$reconTanggal3, $karangtengahId, (int) $prodRecon07['product_id']]);
    $after = $poItemStmt->fetch();
    expect($before['po_awal'] === $after['po_awal'] && $before['po_revisi'] === $after['po_revisi'], 'RECON-07: expected po_item (Regular demand target) COMPLETELY untouched by explode/collapse — FG never writes to Phase 2 PO tables');
});

// =======================================================================
// Summary
// =======================================================================
// Part I — MOBILE-FIRST FG VERIFIKASI + PACKING PER TOKO (setup only).
//
// Real headless-browser mobile checks (MOBILE-FG-01..18) run separately
// (run-ui-smoke-mobile-fg.mjs, invoked by run-production-division-fg-
// rework.sh right after this file exits 0) against a DEDICATED fixture,
// exclusive tanggal (never Part G's own 2026-09-26 batch, which its own
// smoke check still inspects in an unexploded shape):
//   - prodMobileA: verified in DEFAULT Per Produk mode (actual=20, never
//     exploded) — exercises FG Verifikasi's own Sesuai/Tidak Sesuai/
//     Reject/Hilang cards (MOBILE-FG-03/04/05).
//   - BOLLEN LILIT COKLAT (actual=25) + CHOCO CUBE 12 (actual=14) — the
//     task's own named realistic fixture — EXPLODED into Breakdown Toko
//     with REAL per-store numbers (Store A's Verified DELIBERATELY BELOW
//     its own PO target for BOLLEN, so Packing's "Sesuai auto-fills to
//     Ready Verified, never the raw Target" rule (MOBILE-FG-10) is
//     actually exercised, not just coincidentally true because the two
//     numbers happen to match).
//
// This explode requirement is itself a real, load-bearing architectural
// fact worth stating plainly (see the delivery report): Packing per Toko
// needs real per-store fgVerified rows to exist, which only happens once
// a product has been explicitly exploded via FG Verifikasi's Breakdown
// Toko (explodeToStores() refuses to explode a Per Produk row that
// already has a nonzero fgVerified/packed/reject/hilang — "do not invent
// a new business formula for how to split an existing aggregate number
// across stores" — task's own pre-existing rule, unchanged, never
// bypassed here). A product verified in the DEFAULT Per Produk mode
// stays Per-Produk-only and simply never appears in the Packing view —
// exactly prodMobileA's role in this fixture, and the reason Packing's
// own store chip list can legitimately be a subset of all visible
// products.
// =======================================================================
$mobileFgTanggal = '2026-10-20';
$prodMobileA = $prodC;

runTest('MOBILE-FG-00 (setup) prodMobileA verified in DEFAULT Per Produk mode (never exploded); BOLLEN LILIT COKLAT + CHOCO CUBE 12 verified via Breakdown Toko with Store A DELIBERATELY below its own PO target (Ready Verified != Target, for MOBILE-FG-10)', function () use ($http, $csrf, $pdo, $mobileFgTanggal, $karangtengahId, $rotiBollenDivId, $pastryDivId, $prodMobileA, $uiBollen, $uiChococube, $storeAId, $storeBId, $storeCId) {
    seedPoStoreSplit($pdo, $mobileFgTanggal, $karangtengahId, (int) $uiBollen['product_id'], [
        $storeAId => ['poAwal' => 15, 'poRevisi' => 0],
        $storeBId => ['poAwal' => 8, 'poRevisi' => 0],
        $storeCId => ['poAwal' => 0, 'poRevisi' => 0],
    ]);
    seedPoStoreSplit($pdo, $mobileFgTanggal, $karangtengahId, (int) $uiChococube['product_id'], [
        $storeAId => ['poAwal' => 8, 'poRevisi' => 0],
        $storeBId => ['poAwal' => 4, 'poRevisi' => 0],
        $storeCId => ['poAwal' => 0, 'poRevisi' => 0],
    ]);
    // prodMobileA also needs a PO entry — Production's own production_item
    // rows are seeded from THIS date's PO demand at draft-creation time
    // (ProductionTargetService), regardless of whether the product will
    // ever be store-split for FG purposes.
    seedPo($pdo, $mobileFgTanggal, $karangtengahId, $storeAId, [(int) $prodMobileA['product_id'] => ['poAwal' => 20, 'poRevisi' => 0]]);

    $createRoti = $http->request('POST', '/api/production', ['tanggal' => $mobileFgTanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('mobilefg-roti-create')));
    expect($createRoti['status'] === 200, 'MOBILE-FG-00: expected Roti & Bollen production draft create 200, got ' . $createRoti['status'] . ': ' . json_encode($createRoti['json']));
    $runRoti = (int) $createRoti['json']['data']['productionRunId'];
    $verRoti = (int) $createRoti['json']['data']['version'];
    $patchRoti = $http->request('PATCH', "/api/production/{$runRoti}", [
        'expectedVersion' => $verRoti,
        'items' => [
            ['productId' => (int) $prodMobileA['product_id'], 'actualQty' => 20],
            ['productId' => (int) $uiBollen['product_id'], 'actualQty' => 25],
        ],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('mobilefg-roti-patch')));
    expect($patchRoti['status'] === 200, 'MOBILE-FG-00: expected Roti & Bollen production patch 200, got ' . $patchRoti['status'] . ': ' . json_encode($patchRoti['json']));
    $verRoti = (int) $patchRoti['json']['data']['version'];
    $submitRoti = $http->request('POST', "/api/production/{$runRoti}/submit", ['expectedVersion' => $verRoti], array_merge(['X-CSRF-Token' => $csrf], idemKey('mobilefg-roti-submit')));
    expect($submitRoti['status'] === 200, 'MOBILE-FG-00: expected Roti & Bollen production submit 200, got ' . $submitRoti['status'] . ': ' . json_encode($submitRoti['json']));

    $createPastry = $http->request('POST', '/api/production', ['tanggal' => $mobileFgTanggal, 'divisionId' => $pastryDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('mobilefg-pastry-create')));
    expect($createPastry['status'] === 200, 'MOBILE-FG-00: expected Pastry production draft create 200, got ' . $createPastry['status'] . ': ' . json_encode($createPastry['json']));
    $runPastry = (int) $createPastry['json']['data']['productionRunId'];
    $verPastry = (int) $createPastry['json']['data']['version'];
    $patchPastry = $http->request('PATCH', "/api/production/{$runPastry}", [
        'expectedVersion' => $verPastry,
        'items' => [['productId' => (int) $uiChococube['product_id'], 'actualQty' => 14]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('mobilefg-pastry-patch')));
    expect($patchPastry['status'] === 200, 'MOBILE-FG-00: expected Pastry production patch 200, got ' . $patchPastry['status'] . ': ' . json_encode($patchPastry['json']));
    $verPastry = (int) $patchPastry['json']['data']['version'];
    $submitPastry = $http->request('POST', "/api/production/{$runPastry}/submit", ['expectedVersion' => $verPastry], array_merge(['X-CSRF-Token' => $csrf], idemKey('mobilefg-pastry-submit')));
    expect($submitPastry['status'] === 200, 'MOBILE-FG-00: expected Pastry production submit 200, got ' . $submitPastry['status'] . ': ' . json_encode($submitPastry['json']));

    $create = $http->request('POST', '/api/fg', ['tanggal' => $mobileFgTanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('mobilefg-fgcreate')));
    expect($create['status'] === 200, 'MOBILE-FG-00: expected FG create 200, got ' . $create['status'] . ': ' . json_encode($create['json']));
    $batchId = (int) $create['json']['data']['fgBatchId'];
    $version = (int) $create['json']['data']['version'];

    // prodMobileA stays DEFAULT Per Produk — verified Sesuai (=20), never touched via storeItems.
    $verifyA = $http->request('PATCH', "/api/fg/{$batchId}", [
        'expectedVersion' => $version,
        'items' => [['productId' => (int) $prodMobileA['product_id'], 'fgVerified' => 20, 'packed' => 0, 'sesuaiVerified' => true]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('mobilefg-verifya')));
    expect($verifyA['status'] === 200, 'MOBILE-FG-00: expected prodMobileA verify 200, got ' . json_encode($verifyA['json']));
    $version = (int) $verifyA['json']['data']['version'];

    // BOLLEN LILIT COKLAT explodes into Breakdown Toko — Store A verified
    // BELOW its own target (12 < 15, Tidak Sesuai + notes) so Ready
    // Verified genuinely differs from Target for MOBILE-FG-10's proof;
    // Store B verified AT its own target (8, Sesuai).
    $explodeBollen = $http->request('PATCH', "/api/fg/{$batchId}", [
        'expectedVersion' => $version,
        'storeItems' => [['productId' => (int) $uiBollen['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 12, 'packed' => 0, 'sesuaiVerified' => false, 'notes' => 'baru 12 pcs siap, sisanya menyusul'],
            ['storeId' => $storeBId, 'fgVerified' => 8, 'packed' => 0, 'sesuaiVerified' => true],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('mobilefg-explode-bollen')));
    expect($explodeBollen['status'] === 200, 'MOBILE-FG-00: expected BOLLEN explode 200, got ' . json_encode($explodeBollen['json']));
    $version = (int) $explodeBollen['json']['data']['version'];

    $explodeChococube = $http->request('PATCH', "/api/fg/{$batchId}", [
        'expectedVersion' => $version,
        'storeItems' => [['productId' => (int) $uiChococube['product_id'], 'rows' => [
            ['storeId' => $storeAId, 'fgVerified' => 8, 'packed' => 0, 'sesuaiVerified' => true],
            ['storeId' => $storeBId, 'fgVerified' => 4, 'packed' => 0, 'sesuaiVerified' => true],
        ]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('mobilefg-explode-chococube')));
    expect($explodeChococube['status'] === 200, 'MOBILE-FG-00: expected CHOCO CUBE 12 explode 200, got ' . json_encode($explodeChococube['json']));
});

fwrite(STDOUT, "MOBILE_FG_FACTORY_ID={$karangtengahId}\n");
fwrite(STDOUT, "MOBILE_FG_TANGGAL={$mobileFgTanggal}\n");
fwrite(STDOUT, "MOBILE_FG_PRODMOBILEA_NAME={$prodMobileA['name']}\n");

// =======================================================================
// Part J — SECURITY HOTFIX fixture: a store name, a product name, and a
// Keterangan/notes value that are each a RAW, unescaped XSS payload
// (task's own "escape ALL dynamic FG UI output" hotfix). Real browser
// checks (FG-XSS-01..05, run-ui-smoke-fg-xss.mjs, invoked right after
// this file exits 0) load the real fg-packing.php page against this
// fixture and assert the payloads render as inert, literal text — never
// as executed markup — in both FG Verifikasi's Breakdown Toko and FG
// Packing's per-Toko view.
// =======================================================================
$xssTanggal = '2026-10-22';
$xssStoreName = '<script>alert(1)</script>';
$xssProductName = '<img src=x onerror=alert(1)>';
$xssNotes = '"><img src=x onerror=alert(1)>';

$pdo->prepare(
    'INSERT INTO store (canonical_name, channel, active, version, created_at) VALUES (?, NULL, 1, 1, UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE canonical_name = VALUES(canonical_name)'
)->execute([$xssStoreName]);
$findXssStore = $pdo->prepare('SELECT store_id FROM store WHERE canonical_name = ?');
$findXssStore->execute([$xssStoreName]);
$xssStoreId = (int) $findXssStore->fetchColumn();
expect($xssStoreId > 0, 'FG-XSS setup: expected XSS-payload store created');

$pdo->prepare(
    "INSERT INTO product (name, kategori, division_id, hpp, harga, aktif, version, created_at) VALUES (?, 'TEST', ?, 0, 0, 1, 1, UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE division_id = VALUES(division_id)"
)->execute([$xssProductName, $rotiBollenDivId]);
$findXssProduct = $pdo->prepare('SELECT product_id FROM product WHERE name = ?');
$findXssProduct->execute([$xssProductName]);
$xssProductId = (int) $findXssProduct->fetchColumn();
expect($xssProductId > 0, 'FG-XSS setup: expected XSS-payload product created');

runTest('FG-XSS-00 (setup) store name / product name / keterangan each hold a raw XSS payload, exploded into Breakdown Toko so both FG Verifikasi and FG Packing render them; a second, entirely normal product+notes rides along for FG-XSS-05 (normal text must render unchanged)', function () use (
    $http, $csrf, $pdo, $xssTanggal, $karangtengahId, $rotiBollenDivId, $xssStoreId, $xssProductId, $xssNotes, $uiBollen
) {
    seedPoStoreSplit($pdo, $xssTanggal, $karangtengahId, $xssProductId, [
        $xssStoreId => ['poAwal' => 10, 'poRevisi' => 0],
    ]);
    seedPoStoreSplit($pdo, $xssTanggal, $karangtengahId, (int) $uiBollen['product_id'], [
        $xssStoreId => ['poAwal' => 5, 'poRevisi' => 0],
    ]);
    // Both products share ONE division/day, hence ONE production_run —
    // submitProductionActual() (a single-item create+submit helper) would
    // have its SECOND call reuse the run its FIRST call already
    // submitted, and fail to PATCH an already-submitted run (409
    // INVALID_STATUS). Both items go into the SAME draft instead.
    $createProd = $http->request('POST', '/api/production', ['tanggal' => $xssTanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('xssfg-prod-create')));
    expect($createProd['status'] === 200, 'FG-XSS-00: expected production draft create 200, got ' . $createProd['status'] . ': ' . json_encode($createProd['json']));
    $prodRunId = (int) $createProd['json']['data']['productionRunId'];
    $prodVersion = (int) $createProd['json']['data']['version'];
    $patchProd = $http->request('PATCH', "/api/production/{$prodRunId}", [
        'expectedVersion' => $prodVersion,
        'items' => [
            ['productId' => $xssProductId, 'actualQty' => 10],
            ['productId' => (int) $uiBollen['product_id'], 'actualQty' => 5],
        ],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('xssfg-prod-patch')));
    expect($patchProd['status'] === 200, 'FG-XSS-00: expected production draft patch 200, got ' . $patchProd['status'] . ': ' . json_encode($patchProd['json']));
    $prodVersion = (int) $patchProd['json']['data']['version'];
    $submitProd = $http->request('POST', "/api/production/{$prodRunId}/submit", ['expectedVersion' => $prodVersion], array_merge(['X-CSRF-Token' => $csrf], idemKey('xssfg-prod-submit')));
    expect($submitProd['status'] === 200, 'FG-XSS-00: expected production draft submit 200, got ' . $submitProd['status'] . ': ' . json_encode($submitProd['json']));

    $create = $http->request('POST', '/api/fg', ['tanggal' => $xssTanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('xssfg-create')));
    expect($create['status'] === 200, 'FG-XSS-00: expected FG create 200, got ' . $create['status'] . ': ' . json_encode($create['json']));
    $batchId = (int) $create['json']['data']['fgBatchId'];
    $version = (int) $create['json']['data']['version'];

    // Explode straight from the fresh zero baseline (never verified Per
    // Produk first) — same pattern MOBILE-FG-00 uses for BOLLEN/CHOCO
    // CUBE, since explodeToStores() refuses to explode a row that
    // already has a nonzero Per Produk aggregate.
    $explode = $http->request('PATCH', "/api/fg/{$batchId}", [
        'expectedVersion' => $version,
        'storeItems' => [
            ['productId' => $xssProductId, 'rows' => [
                ['storeId' => $xssStoreId, 'fgVerified' => 10, 'packed' => 0, 'sesuaiVerified' => true, 'notes' => $xssNotes],
            ]],
            ['productId' => (int) $uiBollen['product_id'], 'rows' => [
                ['storeId' => $xssStoreId, 'fgVerified' => 5, 'packed' => 0, 'sesuaiVerified' => true, 'notes' => 'Sudah pas & lengkap, tidak ada masalah'],
            ]],
        ],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('xssfg-explode')));
    expect($explode['status'] === 200, 'FG-XSS-00: expected explode 200, got ' . json_encode($explode['json']));
});

fwrite(STDOUT, "XSS_FG_FACTORY_ID={$karangtengahId}\n");
fwrite(STDOUT, "XSS_FG_TANGGAL={$xssTanggal}\n");

// =======================================================================
// Summary
// =======================================================================
$total = count($results);
$failed = count(array_filter($results, static fn ($ok) => !$ok));
fwrite(STDOUT, "\n{$total} tests run, {$failed} failed.\n");
exit($failed > 0 ? 1 : 0);
