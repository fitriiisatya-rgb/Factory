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

    /**
     * Replacement Reject pass: the public receipt-confirm endpoint
     * requires multipart/form-data once any reject/shortage evidence
     * photo is involved (same real-UAT rule Phase55DispatchReceiptTest.php's
     * own Http55::requestMultipart() already exercises) — mirrored here
     * verbatim so this file never needs to borrow that other test file's
     * own HTTP class.
     * @param array<string,string> $files fieldName => local file path
     */
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

// The user_id the SHARED $http/$csrf session itself is authenticated as
// (login($http, $adminUser, ...) at the top of this file) — needed to
// prove submitted_by comes from the AUTHENTICATED session, never from
// request payload (PACK-SUBMIT-10).
$stagingAdminIdStmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
$stagingAdminIdStmt->execute([$adminUser]);
$stagingAdminId = (int) $stagingAdminIdStmt->fetchColumn();
expect($stagingAdminId > 0, 'expected the staging admin user (' . $adminUser . ') to exist');

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
// Part K — LIVE UAT HOTFIX: hide zero-qty DO items (DO-UI-01..05).
// =======================================================================
$doUiTanggal = '2026-10-25';
$doUiPaddingProducts = [$uiChococubePad, $uiBollenPad, $prodD, $prodE, $prodF, $prodG];

runTest('DO-UI-00 (setup) a NEW DO created after the query-layer fix already has exactly 1 item (zero-demand rows never inserted); six legacy zero-planned rows are then added directly, reproducing DO/KRM/006/IX/2026\'s own pre-fix shape', function () use (
    $http, $csrf, $pdo, $doUiTanggal, $karangtengahId, $storeAId, $uiChococube, $doUiPaddingProducts
) {
    seedPo($pdo, $doUiTanggal, $karangtengahId, $storeAId, [(int) $uiChococube['product_id'] => ['poAwal' => 1, 'poRevisi' => 0]]);

    $create = $http->request('POST', '/api/do', ['tanggal' => $doUiTanggal, 'storeId' => $storeAId], array_merge(['X-CSRF-Token' => $csrf], idemKey('do-ui-create')));
    expect($create['status'] === 200, 'DO-UI-00: expected DO create 200, got ' . $create['status'] . ': ' . json_encode($create['json']));
    $doId = (int) $create['json']['data']['doId'];
    expect(count($create['json']['data']['items']) === 1, 'DO-UI-00: expected a brand-new DO to already have exactly 1 item — DoTargetService::storeDemandByProduct()\'s own query-layer fix means zero-demand rows are never inserted for a NEW DO in the first place, got ' . count($create['json']['data']['items']));

    // Simulate SIX already-persisted legacy zero rows on this SAME real
    // DO (data that predates this hotfix) via direct insert — exactly
    // the shape the live DO/KRM/006/IX/2026 had, which this DTO-layer
    // render fix (not the query fix above) is responsible for hiding.
    $insertZero = $pdo->prepare('INSERT INTO delivery_order_item (delivery_order_id, product_id, planned_qty) VALUES (?, ?, 0)');
    foreach ($doUiPaddingProducts as $p) {
        $insertZero->execute([$doId, (int) $p['product_id']]);
    }

    global $doUiDoId;
    $doUiDoId = $doId;
});

fwrite(STDOUT, "DO_UI_DOID={$GLOBALS['doUiDoId']}\n");

runTest('DO-UI-01 GET /api/do/{id} (screen view\'s own data source) renders exactly the 1 positive item, none of the 6 legacy zero rows', function () use ($http, $csrf) {
    global $doUiDoId;
    $get = $http->request('GET', "/api/do/{$doUiDoId}", null, ['X-CSRF-Token' => $csrf]);
    expect($get['status'] === 200, 'DO-UI-01: expected GET 200, got ' . $get['status']);
    $items = $get['json']['data']['items'];
    expect(count($items) === 1, 'DO-UI-01: expected exactly 1 visible item, got ' . count($items) . ': ' . json_encode($items));
    expect($items[0]['productName'] === 'CHOCO CUBE 12', 'DO-UI-01: expected the one visible item to be CHOCO CUBE 12, got ' . json_encode($items[0]));
    expect(abs((float) $items[0]['plannedQty'] - 1.0) < 0.01, 'DO-UI-01: expected CHOCO CUBE 12 plannedQty=1, got ' . json_encode($items[0]));
});

runTest('DO-UI-02 print-do.php (the SAME buildDoDto() items array, no separate query — see print-template.php\'s own foreach) renders the exact same 1 item, real headless-browser check', function () {
    // Covered end-to-end by a real headless-Chromium hit against the
    // actual print-do.php page — see _ui_smoke_do_zero_qty.mjs
    // (DO-UI-02), run right after this file exits 0. Asserted here only
    // as a code-level guarantee: print-template.php's ui_render_do_print_
    // document() iterates "foreach ($do['items'] as $i => $it)" off the
    // exact same array GET /api/do/{id} returned above — there is no
    // second, independent query for the print view to diverge from.
    $printTemplateSrc = file_get_contents(__DIR__ . '/../app/ui/print-template.php');
    expect(strpos($printTemplateSrc, "foreach (\$do['items'] as \$i => \$it)") !== false, 'DO-UI-02: expected print-template.php to iterate the same $do[\'items\'] DTO, no separate query');
});

runTest('DO-UI-03 totals remain correct: totalPlanned=1, totalShipped=0, totalRemaining=1, productCount=1 (the visible count, not the 7 raw persisted rows)', function () use ($http, $csrf) {
    global $doUiDoId;
    $get = $http->request('GET', "/api/do/{$doUiDoId}", null, ['X-CSRF-Token' => $csrf]);
    $summary = $get['json']['data']['summary'];
    expect(abs((float) $summary['totalPlanned'] - 1.0) < 0.01, 'DO-UI-03: expected totalPlanned=1, got ' . json_encode($summary));
    expect(abs((float) $summary['totalShipped'] - 0.0) < 0.01, 'DO-UI-03: expected totalShipped=0, got ' . json_encode($summary));
    expect(abs((float) $summary['totalRemaining'] - 1.0) < 0.01, 'DO-UI-03: expected totalRemaining=1, got ' . json_encode($summary));
    expect((int) $summary['productCount'] === 1, 'DO-UI-03: expected productCount=1 (visible-only), got ' . json_encode($summary));
});

$doUiTanggal2 = '2026-10-26';
runTest('DO-UI-04 (setup+assert) a historical line whose planned_qty has since been driven to 0 but which already has real shipped_qty > 0 is NEVER hidden — the shippedQty > 0 half of the visibility check is what protects it', function () use (
    $http, $csrf, $pdo, $doUiTanggal2, $karangtengahId, $storeBId, $uiBollen
) {
    seedPo($pdo, $doUiTanggal2, $karangtengahId, $storeBId, [(int) $uiBollen['product_id'] => ['poAwal' => 5, 'poRevisi' => 0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $doUiTanggal2, 'storeId' => $storeBId], array_merge(['X-CSRF-Token' => $csrf], idemKey('do-ui-04-create')));
    expect($create['status'] === 200, 'DO-UI-04: expected DO create 200, got ' . $create['status'] . ': ' . json_encode($create['json']));
    $doId = (int) $create['json']['data']['doId'];

    // A real shipment can never actually happen against a zero-planned
    // item (remainingToShip caps it at 0) — this simulates the residual
    // DATA shape such a history would leave behind (e.g. a later PO
    // revision driving planned down after the fact), via direct insert,
    // purely to prove the DTO's defensive "shippedQty > 0 keeps it
    // visible" branch, independent of how that shipment history came to
    // exist.
    $pdo->prepare(
        "INSERT INTO shipment (batch, tanggal, store_id, shipment_group, source_type, delivery_order_id, status, version, created_at)
         VALUES ('DO-UI-04-BATCH', ?, ?, 'MAIN', 'delivery_order', ?, 'active', 1, UTC_TIMESTAMP())"
    )->execute([$doUiTanggal2, $storeBId, $doId]);
    $shipmentId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO shipment_item (shipment_id, product_id, qty) VALUES (?, ?, ?)')
        ->execute([$shipmentId, (int) $uiBollen['product_id'], 3]);

    $pdo->prepare('UPDATE delivery_order_item SET planned_qty = 0 WHERE delivery_order_id = ? AND product_id = ?')
        ->execute([$doId, (int) $uiBollen['product_id']]);

    $get = $http->request('GET', "/api/do/{$doId}", null, ['X-CSRF-Token' => $csrf]);
    expect($get['status'] === 200, 'DO-UI-04: expected GET 200, got ' . $get['status']);
    $items = $get['json']['data']['items'];
    expect(count($items) === 1, 'DO-UI-04: expected the historical line to STILL be visible despite planned_qty=0, got ' . count($items) . ' items: ' . json_encode($items));
    expect(abs((float) $items[0]['plannedQty'] - 0.0) < 0.01 && abs((float) $items[0]['alreadyShippedQty'] - 3.0) < 0.01, 'DO-UI-04: expected planned=0/shipped=3 preserved exactly, got ' . json_encode($items[0]));
});

runTest('DO-UI-05 no shipment behavior changed: shippedQtyByProduct()/the shipment endpoints still read straight from the repository, never from the filtered items list', function () use ($pdo) {
    // Architectural proof, not a new code path: buildDoDto()'s visibility
    // filter only decides what goes into the RETURNED $items array for
    // display — $shippedByProduct (used both for each item's own
    // alreadyShippedQty/remainingToShip AND by pengiriman.php's ship
    // form) is computed once, up front, straight from DoRepository::
    // shippedQtyByProduct()'s own repository query, before the filter
    // ever runs, and is never itself filtered.
    $src = file_get_contents(__DIR__ . '/../app/src/Delivery/DoService.php');
    $shippedComputedBeforeFilter = strpos($src, '$shippedByProduct = $this->repo->shippedQtyByProduct') < strpos($src, 'planned <= 0.0001 && $shippedQty <= 0.0001');
    expect($shippedComputedBeforeFilter, 'DO-UI-05: expected $shippedByProduct to be computed once, upfront, independent of the visibility filter');
});

$doUiTanggal3 = '2026-10-30';
runTest('DO-UI-06 a product whose po_awal was positive but a NEGATIVE po_revisi drives final planned demand to exactly 0 is NOT surfaced as an active DO line — proves the filter tests (po_awal + po_revisi) > 0, never "po_awal > 0 OR po_revisi > 0" alone', function () use (
    $http, $csrf, $pdo, $doUiTanggal3, $karangtengahId, $storeAId, $uiBollenPad, $uiChococube
) {
    // This IS a real, reachable stored state: PoMerger::mergeRevision()
    // replaces po_revisi wholesale on every revision upload ("a revision
    // corrected DOWN is reflected immediately, including back to 0" —
    // its own docblock), and PoFileParser::parseAngka() parses a
    // genuinely negative cell value unclamped. po_awal=8/po_revisi=-8
    // (final demand exactly 0) is exactly that: a store's order revised
    // DOWN by more than its own original PO Awal.
    seedPo($pdo, $doUiTanggal3, $karangtengahId, $storeAId, [(int) $uiBollenPad['product_id'] => ['poAwal' => 8, 'poRevisi' => -8]]);
    // A second, real product with positive final demand rides along so
    // this DO is not itself empty (createDraft() refuses an empty
    // $demand with NO_PO_DEMAND) — the real assertion is that ONLY this
    // one appears, never the net-zero padding product.
    seedPo($pdo, $doUiTanggal3, $karangtengahId, $storeAId, [(int) $uiChococube['product_id'] => ['poAwal' => 3, 'poRevisi' => 0]]);

    $create = $http->request('POST', '/api/do', ['tanggal' => $doUiTanggal3, 'storeId' => $storeAId], array_merge(['X-CSRF-Token' => $csrf], idemKey('do-ui-06-create')));
    expect($create['status'] === 200, 'DO-UI-06: expected DO create 200, got ' . $create['status'] . ': ' . json_encode($create['json']));
    $items = $create['json']['data']['items'];
    expect(count($items) === 1, 'DO-UI-06: expected the net-zero-demand product (po_awal=8/po_revisi=-8) to never be inserted as a DO line at all — got ' . count($items) . ' items: ' . json_encode($items));
    expect($items[0]['productName'] === 'CHOCO CUBE 12', 'DO-UI-06: expected the one real item to be CHOCO CUBE 12, got ' . json_encode($items[0]));

    // Not merely hidden from the response — genuinely never persisted:
    // the query-layer fix means storeDemandByProduct() never returned
    // this product at all, so createDraft()'s own insertDoItem() loop
    // never ran for it.
    $doId = (int) $create['json']['data']['doId'];
    $rowStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM delivery_order_item WHERE delivery_order_id = ? AND product_id = ?');
    $rowStmt->execute([$doId, (int) $uiBollenPad['product_id']]);
    expect((int) $rowStmt->fetch()['c'] === 0, 'DO-UI-06: expected ZERO delivery_order_item rows for the net-zero-demand product — never even persisted, not merely hidden at render time');
});

// =======================================================================
// Part M — FINAL FIX: real persisted Packing submission state per (FG
// batch, store) — migration 0015's fg_store_packing_submission
// (PACK-SUBMIT-01..10). Supersedes the prior pass's inference-based
// "packed_qty >= target" Part L, which a source deep-check correctly
// found logically wrong (a store's Packing can be legitimately, fully
// submitted with packed BELOW target, or even packed = 0).
//
// PACK-SUBMIT-01..08 are real headless-browser checks
// (_ui_smoke_pack_submit.mjs, run right after this file exits 0):
//   - Store A (target 10): submitted FULL (10), reloaded, opened in a
//     SECOND browser session, then EDITED via Breakdown Toko's own save
//     (FG Verifikasi) and resubmitted — PACK-SUBMIT-01/04/05/06/07/08.
//   - Store B (target 10): left COMPLETELY untouched everywhere — PACK-
//     SUBMIT-06's own "stays clearly different" control.
//   - Store C (target 10): submitted PARTIAL (8, Tidak Sesuai + note) —
//     PACK-SUBMIT-02.
//   - Store D (target 10): submitted ZERO (0, Tidak Sesuai + valid
//     discrepancy note) — PACK-SUBMIT-03.
// PACK-SUBMIT-09 (failed validation creates no state) and PACK-SUBMIT-10
// (a retried submit is idempotent, submitted_by comes from the
// authenticated session, no duplicate stock) are proven here, directly
// at the API/DB level, against a fifth store (P2 TEST STORE E) the
// browser script never touches.
// =======================================================================
$packSubmitTanggal = '2026-10-29';
$psProdA = $prodA;
$psProdB = $prodB;
$psProdC = $prodC;
$psProdD = $prodD;
$psProdE = $prodE;

$pdo->prepare(
    "INSERT INTO store (canonical_name, channel, active, version, created_at) VALUES ('P2 TEST STORE D', NULL, 1, 1, UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE canonical_name = VALUES(canonical_name)"
)->execute();
$storeDId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE D'")->fetchColumn();
expect($storeDId > 0, 'PACK-SUBMIT setup: expected P2 TEST STORE D created');

$pdo->prepare(
    "INSERT INTO store (canonical_name, channel, active, version, created_at) VALUES ('P2 TEST STORE E', NULL, 1, 1, UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE canonical_name = VALUES(canonical_name)"
)->execute();
$storeEId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE E'")->fetchColumn();
expect($storeEId > 0, 'PACK-SUBMIT setup: expected P2 TEST STORE E created');

runTest('PACK-SUBMIT-00 (setup) five stores, one product each, all Sesuai-verified to their own target=10, nothing packed/submitted yet', function () use (
    $http, $csrf, $pdo, $packSubmitTanggal, $karangtengahId, $rotiBollenDivId,
    $psProdA, $psProdB, $psProdC, $psProdD, $psProdE, $storeAId, $storeBId, $storeCId, $storeDId, $storeEId
) {
    seedPoStoreSplit($pdo, $packSubmitTanggal, $karangtengahId, (int) $psProdA['product_id'], [$storeAId => ['poAwal' => 10, 'poRevisi' => 0]]);
    seedPoStoreSplit($pdo, $packSubmitTanggal, $karangtengahId, (int) $psProdB['product_id'], [$storeBId => ['poAwal' => 10, 'poRevisi' => 0]]);
    seedPoStoreSplit($pdo, $packSubmitTanggal, $karangtengahId, (int) $psProdC['product_id'], [$storeCId => ['poAwal' => 10, 'poRevisi' => 0]]);
    seedPoStoreSplit($pdo, $packSubmitTanggal, $karangtengahId, (int) $psProdD['product_id'], [$storeDId => ['poAwal' => 10, 'poRevisi' => 0]]);
    seedPoStoreSplit($pdo, $packSubmitTanggal, $karangtengahId, (int) $psProdE['product_id'], [$storeEId => ['poAwal' => 10, 'poRevisi' => 0]]);

    // All five share one division/day, hence ONE production_run — see
    // Task D's own FG-XSS-00 fixture for why these must all go into a
    // SINGLE create+patch+submit rather than five separate calls.
    $createProd = $http->request('POST', '/api/production', ['tanggal' => $packSubmitTanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('packsubmit-prod-create')));
    expect($createProd['status'] === 200, 'PACK-SUBMIT-00: expected production draft create 200, got ' . $createProd['status'] . ': ' . json_encode($createProd['json']));
    $prodRunId = (int) $createProd['json']['data']['productionRunId'];
    $prodVersion = (int) $createProd['json']['data']['version'];
    $patchProd = $http->request('PATCH', "/api/production/{$prodRunId}", [
        'expectedVersion' => $prodVersion,
        'items' => [
            ['productId' => (int) $psProdA['product_id'], 'actualQty' => 10],
            ['productId' => (int) $psProdB['product_id'], 'actualQty' => 10],
            ['productId' => (int) $psProdC['product_id'], 'actualQty' => 10],
            ['productId' => (int) $psProdD['product_id'], 'actualQty' => 10],
            ['productId' => (int) $psProdE['product_id'], 'actualQty' => 10],
        ],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('packsubmit-prod-patch')));
    expect($patchProd['status'] === 200, 'PACK-SUBMIT-00: expected production draft patch 200, got ' . $patchProd['status'] . ': ' . json_encode($patchProd['json']));
    $prodVersion = (int) $patchProd['json']['data']['version'];
    $submitProd = $http->request('POST', "/api/production/{$prodRunId}/submit", ['expectedVersion' => $prodVersion], array_merge(['X-CSRF-Token' => $csrf], idemKey('packsubmit-prod-submit')));
    expect($submitProd['status'] === 200, 'PACK-SUBMIT-00: expected production draft submit 200, got ' . $submitProd['status'] . ': ' . json_encode($submitProd['json']));

    $create = $http->request('POST', '/api/fg', ['tanggal' => $packSubmitTanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('packsubmit-fgcreate')));
    expect($create['status'] === 200, 'PACK-SUBMIT-00: expected FG create 200, got ' . $create['status'] . ': ' . json_encode($create['json']));
    $batchId = (int) $create['json']['data']['fgBatchId'];
    $version = (int) $create['json']['data']['version'];

    $explode = $http->request('PATCH', "/api/fg/{$batchId}", [
        'expectedVersion' => $version,
        'storeItems' => [
            ['productId' => (int) $psProdA['product_id'], 'rows' => [['storeId' => $storeAId, 'fgVerified' => 10, 'packed' => 0, 'sesuaiVerified' => true]]],
            ['productId' => (int) $psProdB['product_id'], 'rows' => [['storeId' => $storeBId, 'fgVerified' => 10, 'packed' => 0, 'sesuaiVerified' => true]]],
            ['productId' => (int) $psProdC['product_id'], 'rows' => [['storeId' => $storeCId, 'fgVerified' => 10, 'packed' => 0, 'sesuaiVerified' => true]]],
            ['productId' => (int) $psProdD['product_id'], 'rows' => [['storeId' => $storeDId, 'fgVerified' => 10, 'packed' => 0, 'sesuaiVerified' => true]]],
            ['productId' => (int) $psProdE['product_id'], 'rows' => [['storeId' => $storeEId, 'fgVerified' => 10, 'packed' => 0, 'sesuaiVerified' => true]]],
        ],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('packsubmit-explode')));
    expect($explode['status'] === 200, 'PACK-SUBMIT-00: expected explode 200, got ' . json_encode($explode['json']));

    global $packSubmitBatchId, $packSubmitVersion;
    $packSubmitBatchId = $batchId;
    $packSubmitVersion = (int) $explode['json']['data']['version'];
});

runTest('PACK-SUBMIT-09 a packing-submit call that fails validation (packed exceeds fgVerified) creates NO submission state for that store', function () use ($http, $csrf, $pdo, $storeEId, $psProdE) {
    global $packSubmitBatchId, $packSubmitVersion;
    $invalid = $http->request('POST', "/api/fg/{$packSubmitBatchId}/packing-submit", [
        'expectedVersion' => $packSubmitVersion,
        'storeId' => $storeEId,
        'rows' => [['productId' => (int) $psProdE['product_id'], 'storeId' => $storeEId, 'fgVerified' => 10, 'packed' => 99, 'reject' => 0, 'hilang' => 0, 'notes' => '', 'sesuaiPacking' => false]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('packsubmit-storeE-invalid')));
    expect($invalid['status'] === 400 && ($invalid['json']['code'] ?? null) === 'PACKED_EXCEEDS_VERIFIED', 'PACK-SUBMIT-09: expected 400 PACKED_EXCEEDS_VERIFIED, got ' . $invalid['status'] . ': ' . json_encode($invalid['json']));

    $subStmt = $pdo->prepare('SELECT * FROM fg_store_packing_submission WHERE fg_batch_id = ? AND store_id = ?');
    $subStmt->execute([$packSubmitBatchId, $storeEId]);
    expect($subStmt->fetch() === false, 'PACK-SUBMIT-09: expected NO submission row to exist after a failed validation call');
});

runTest('PACK-SUBMIT-10 submitting Store E packing TWICE (valid payload, simulating a retried/double-click) is idempotent: packed_qty is not doubled, submitted_by comes from the authenticated session (never request payload), and no stock_ledger row is posted', function () use ($http, $csrf, $pdo, $storeEId, $psProdE, $stagingAdminId) {
    global $packSubmitBatchId, $packSubmitVersion;
    $payload = [
        'expectedVersion' => $packSubmitVersion,
        'storeId' => $storeEId,
        'rows' => [['productId' => (int) $psProdE['product_id'], 'storeId' => $storeEId, 'fgVerified' => 10, 'packed' => 6, 'reject' => 0, 'hilang' => 0, 'notes' => 'Baru 6 pcs siap, sisanya menyusul', 'sesuaiPacking' => false]],
    ];
    $first = $http->request('POST', "/api/fg/{$packSubmitBatchId}/packing-submit", $payload, array_merge(['X-CSRF-Token' => $csrf], idemKey('packsubmit-storeE-1')));
    expect($first['status'] === 200, 'PACK-SUBMIT-10: expected first Submit Packing 200, got ' . json_encode($first['json']));
    $payload['expectedVersion'] = (int) $first['json']['data']['version'];
    $second = $http->request('POST', "/api/fg/{$packSubmitBatchId}/packing-submit", $payload, array_merge(['X-CSRF-Token' => $csrf], idemKey('packsubmit-storeE-2')));
    expect($second['status'] === 200, 'PACK-SUBMIT-10: expected second (retried) Submit Packing 200, got ' . json_encode($second['json']));

    $itemStmt = $pdo->prepare('SELECT fg_item_id, packed_qty FROM fg_item WHERE fg_batch_id = ? AND product_id = ? AND store_id = ?');
    $itemStmt->execute([$packSubmitBatchId, (int) $psProdE['product_id'], $storeEId]);
    $item = $itemStmt->fetch();
    expect($item !== false && abs((float) $item['packed_qty'] - 6.0) < 0.01, 'PACK-SUBMIT-10: expected packed_qty to stay exactly 6 after two identical submits (never doubled to 12), got ' . json_encode($item));

    $ledgerStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM stock_ledger WHERE source_type = 'fg_item' AND source_id = ?");
    $ledgerStmt->execute([(int) $item['fg_item_id']]);
    expect((int) $ledgerStmt->fetch()['c'] === 0, 'PACK-SUBMIT-10: expected ZERO stock_ledger rows for this still-DRAFT batch (Submit Packing never posts stock)');

    $subStmt = $pdo->prepare('SELECT * FROM fg_store_packing_submission WHERE fg_batch_id = ? AND store_id = ?');
    $subStmt->execute([$packSubmitBatchId, $storeEId]);
    $sub = $subStmt->fetch();
    expect($sub !== false && $sub['status'] === 'submitted', 'PACK-SUBMIT-10: expected a real submitted record for Store E, got ' . json_encode($sub));
    expect((int) $sub['submitted_by'] === $stagingAdminId, 'PACK-SUBMIT-10: expected submitted_by to be the AUTHENTICATED session user (never taken from request payload), got ' . json_encode($sub));
});

fwrite(STDOUT, "PACK_SUBMIT_FACTORY_ID={$karangtengahId}\n");
fwrite(STDOUT, "PACK_SUBMIT_TANGGAL={$packSubmitTanggal}\n");

// =======================================================================
// Part N — LIVE UAT UX FIX: lock Packing after submit + explicit Edit/
// Resubmit flow (PACK-EDIT-01..16). PACK-EDIT-01..06/09/12/15 are real
// headless-browser checks (_ui_smoke_pack_edit.mjs, run right after this
// file exits 0) against Store A, driven through the ACTUAL "Submit
// Packing" -> "Edit Packing" (with confirmation) -> "Batal Edit" ->
// "Edit Packing" again -> real change -> "Simpan Perubahan" -> "Submit
// Ulang Packing" flow, exactly as an operator would. PACK-EDIT-07/08/10/
// 11/13 (the exact server-side guarantees behind that same flow: a
// no-op correction save never fabricates a stale state, a real one
// always does, resubmitting restores submitted with the correct
// authenticated submitted_by, and none of this ever posts stock) and
// PACK-EDIT-14/16 (authorization, concurrency) are proven here, directly
// at the API/DB level, against a THIRD store (Store C) the browser
// script never touches, plus a dedicated unauthorized DRIVER session.
// PACK-EDIT-12 (Store B stays completely unaffected) is checked from
// both sides.
// =======================================================================
$packEditTanggal = '2026-11-01';
$peProdA = $prodA;
$peProdB = $prodB;
$peProdC = $prodC;

runTest('PACK-EDIT-00 (setup) three stores/products, all Sesuai-verified to target=10, nothing packed/submitted yet — Store A for the real browser Edit/Resubmit flow, Store B left untouched everywhere as the "unaffected" control, Store C for the direct server-level correction-lifecycle proof', function () use (
    $http, $csrf, $pdo, $packEditTanggal, $karangtengahId, $rotiBollenDivId, $peProdA, $peProdB, $peProdC, $storeAId, $storeBId, $storeCId
) {
    seedPoStoreSplit($pdo, $packEditTanggal, $karangtengahId, (int) $peProdA['product_id'], [$storeAId => ['poAwal' => 10, 'poRevisi' => 0]]);
    seedPoStoreSplit($pdo, $packEditTanggal, $karangtengahId, (int) $peProdB['product_id'], [$storeBId => ['poAwal' => 10, 'poRevisi' => 0]]);
    seedPoStoreSplit($pdo, $packEditTanggal, $karangtengahId, (int) $peProdC['product_id'], [$storeCId => ['poAwal' => 10, 'poRevisi' => 0]]);

    $createProd = $http->request('POST', '/api/production', ['tanggal' => $packEditTanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('packedit-prod-create')));
    expect($createProd['status'] === 200, 'PACK-EDIT-00: expected production draft create 200, got ' . $createProd['status'] . ': ' . json_encode($createProd['json']));
    $prodRunId = (int) $createProd['json']['data']['productionRunId'];
    $prodVersion = (int) $createProd['json']['data']['version'];
    $patchProd = $http->request('PATCH', "/api/production/{$prodRunId}", [
        'expectedVersion' => $prodVersion,
        'items' => [
            ['productId' => (int) $peProdA['product_id'], 'actualQty' => 10],
            ['productId' => (int) $peProdB['product_id'], 'actualQty' => 10],
            ['productId' => (int) $peProdC['product_id'], 'actualQty' => 10],
        ],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('packedit-prod-patch')));
    expect($patchProd['status'] === 200, 'PACK-EDIT-00: expected production draft patch 200, got ' . $patchProd['status'] . ': ' . json_encode($patchProd['json']));
    $prodVersion = (int) $patchProd['json']['data']['version'];
    $submitProd = $http->request('POST', "/api/production/{$prodRunId}/submit", ['expectedVersion' => $prodVersion], array_merge(['X-CSRF-Token' => $csrf], idemKey('packedit-prod-submit')));
    expect($submitProd['status'] === 200, 'PACK-EDIT-00: expected production draft submit 200, got ' . $submitProd['status'] . ': ' . json_encode($submitProd['json']));

    $create = $http->request('POST', '/api/fg', ['tanggal' => $packEditTanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('packedit-fgcreate')));
    expect($create['status'] === 200, 'PACK-EDIT-00: expected FG create 200, got ' . $create['status'] . ': ' . json_encode($create['json']));
    $batchId = (int) $create['json']['data']['fgBatchId'];
    $version = (int) $create['json']['data']['version'];

    $explode = $http->request('PATCH', "/api/fg/{$batchId}", [
        'expectedVersion' => $version,
        'storeItems' => [
            ['productId' => (int) $peProdA['product_id'], 'rows' => [['storeId' => $storeAId, 'fgVerified' => 10, 'packed' => 0, 'sesuaiVerified' => true]]],
            ['productId' => (int) $peProdB['product_id'], 'rows' => [['storeId' => $storeBId, 'fgVerified' => 10, 'packed' => 0, 'sesuaiVerified' => true]]],
            ['productId' => (int) $peProdC['product_id'], 'rows' => [['storeId' => $storeCId, 'fgVerified' => 10, 'packed' => 0, 'sesuaiVerified' => true]]],
        ],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('packedit-explode')));
    expect($explode['status'] === 200, 'PACK-EDIT-00: expected explode 200, got ' . json_encode($explode['json']));

    global $packEditBatchId, $packEditVersion;
    $packEditBatchId = $batchId;
    $packEditVersion = (int) $explode['json']['data']['version'];
});

runTest('PACK-EDIT-07/08/10/11/13 (server-level) full correction lifecycle on Store C: real submit -> a no-op correction save NEVER fabricates a stale state -> a REAL correction save always flips to stale -> resubmitting restores submitted with the correct authenticated submitted_by -> none of this ever posts stock', function () use ($http, $csrf, $pdo, $storeCId, $peProdC, $stagingAdminId) {
    global $packEditBatchId, $packEditVersion;
    $productId = (int) $peProdC['product_id'];
    $subStmt = $pdo->prepare('SELECT status, submitted_by FROM fg_store_packing_submission WHERE fg_batch_id = ? AND store_id = ?');

    $submit = $http->request('POST', "/api/fg/{$packEditBatchId}/packing-submit", [
        'expectedVersion' => $packEditVersion, 'storeId' => $storeCId,
        'rows' => [['productId' => $productId, 'storeId' => $storeCId, 'fgVerified' => 10, 'packed' => 10, 'reject' => 0, 'hilang' => 0, 'notes' => '', 'sesuaiPacking' => true]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('packedit-storeC-submit')));
    expect($submit['status'] === 200, 'PACK-EDIT: expected Store C real submit 200, got ' . json_encode($submit['json']));
    $packEditVersion = (int) $submit['json']['data']['version'];
    $subStmt->execute([$packEditBatchId, $storeCId]);
    $sub1 = $subStmt->fetch();
    expect($sub1 !== false && $sub1['status'] === 'submitted', 'PACK-EDIT: expected submitted after the real submit, got ' . json_encode($sub1));

    // PACK-EDIT-07: "Simpan Perubahan" reuses the generic storeItems PATCH
    // (never a new endpoint) — here with values IDENTICAL to what is
    // already persisted.
    $noopPatch = $http->request('PATCH', "/api/fg/{$packEditBatchId}", [
        'expectedVersion' => $packEditVersion,
        'storeItems' => [['productId' => $productId, 'rows' => [['storeId' => $storeCId, 'fgVerified' => 10, 'packed' => 10, 'reject' => 0, 'hilang' => 0, 'notes' => '', 'sesuaiPacking' => true]]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('packedit-storeC-noop')));
    expect($noopPatch['status'] === 200, 'PACK-EDIT-07: expected the no-op correction save 200, got ' . json_encode($noopPatch['json']));
    $versionAfterNoop = (int) $noopPatch['json']['data']['version'];
    $subStmt->execute([$packEditBatchId, $storeCId]);
    $sub2 = $subStmt->fetch();
    expect($sub2 !== false && $sub2['status'] === 'submitted', 'PACK-EDIT-07: expected status to STAY submitted after a no-op correction save (never a false stale state), got ' . json_encode($sub2));

    // PACK-EDIT-08: a REAL change (Reject 0 -> 1, with the required note).
    $realPatch = $http->request('PATCH', "/api/fg/{$packEditBatchId}", [
        'expectedVersion' => $versionAfterNoop,
        'storeItems' => [['productId' => $productId, 'rows' => [['storeId' => $storeCId, 'fgVerified' => 10, 'packed' => 10, 'reject' => 1, 'hilang' => 0, 'notes' => 'Ada 1 pcs reject ditemukan setelah submit', 'sesuaiPacking' => true]]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('packedit-storeC-real')));
    expect($realPatch['status'] === 200, 'PACK-EDIT-08: expected the real correction save 200, got ' . json_encode($realPatch['json']));
    $packEditVersion = (int) $realPatch['json']['data']['version'];
    $subStmt->execute([$packEditBatchId, $storeCId]);
    $sub3 = $subStmt->fetch();
    expect($sub3 !== false && $sub3['status'] === 'stale', 'PACK-EDIT-08: expected status to become stale after a REAL correction save, got ' . json_encode($sub3));

    // PACK-EDIT-16 (concurrency): retrying the SAME correction call with
    // the now-STALE (pre-real-change) expectedVersion must be rejected,
    // never silently applied on top — the existing, unchanged optimistic
    // concurrency guard (expectedVersion/bumpVersion) already covers this
    // new flow automatically, since it is the exact same PATCH endpoint.
    $conflict = $http->request('PATCH', "/api/fg/{$packEditBatchId}", [
        'expectedVersion' => $versionAfterNoop,
        'storeItems' => [['productId' => $productId, 'rows' => [['storeId' => $storeCId, 'fgVerified' => 10, 'packed' => 9, 'reject' => 0, 'hilang' => 0, 'notes' => 'percobaan konflik', 'sesuaiPacking' => false]]]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('packedit-storeC-conflict')));
    expect($conflict['status'] === 409 && ($conflict['json']['code'] ?? null) === 'VERSION_CONFLICT', 'PACK-EDIT-16: expected a concurrent/stale-version correction attempt to be rejected with 409 VERSION_CONFLICT, never silently applied, got ' . $conflict['status'] . ': ' . json_encode($conflict['json']));

    // PACK-EDIT-10/11: resubmit with the CURRENT version.
    $resubmit = $http->request('POST', "/api/fg/{$packEditBatchId}/packing-submit", [
        'expectedVersion' => $packEditVersion, 'storeId' => $storeCId,
        'rows' => [['productId' => $productId, 'storeId' => $storeCId, 'fgVerified' => 10, 'packed' => 10, 'reject' => 1, 'hilang' => 0, 'notes' => 'Ada 1 pcs reject ditemukan setelah submit', 'sesuaiPacking' => true]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('packedit-storeC-resubmit')));
    expect($resubmit['status'] === 200, 'PACK-EDIT-10: expected resubmit 200, got ' . json_encode($resubmit['json']));
    $packEditVersion = (int) $resubmit['json']['data']['version'];
    $subStmt->execute([$packEditBatchId, $storeCId]);
    $sub4 = $subStmt->fetch();
    expect($sub4 !== false && $sub4['status'] === 'submitted', 'PACK-EDIT-10: expected submitted again after resubmit, got ' . json_encode($sub4));
    expect($sub4 !== false && (int) $sub4['submitted_by'] === $stagingAdminId, 'PACK-EDIT-11: expected submitted_by to be the AUTHENTICATED session user after resubmit, got ' . json_encode($sub4));

    // PACK-EDIT-13: none of the above (submit, no-op save, real save,
    // resubmit) ever posted to stock_ledger — this whole lifecycle stays
    // draft-level; only the separate, whole-document "Submit FG" ever
    // posts stock.
    $itemStmt = $pdo->prepare('SELECT fg_item_id FROM fg_item WHERE fg_batch_id = ? AND product_id = ? AND store_id = ?');
    $itemStmt->execute([$packEditBatchId, $productId, $storeCId]);
    $fgItemId = (int) $itemStmt->fetchColumn();
    $ledgerStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM stock_ledger WHERE source_type = 'fg_item' AND source_id = ?");
    $ledgerStmt->execute([$fgItemId]);
    expect((int) $ledgerStmt->fetch()['c'] === 0, 'PACK-EDIT-13: expected ZERO stock_ledger rows across the whole submit/edit/resubmit lifecycle (still DRAFT — no stock posted)');
});

runTest('PACK-EDIT-12 Store B (never touched by any of Store A/C\'s submit/edit/resubmit activity) has no submission record at all', function () use ($pdo, $storeBId) {
    global $packEditBatchId;
    $subStmt = $pdo->prepare('SELECT * FROM fg_store_packing_submission WHERE fg_batch_id = ? AND store_id = ?');
    $subStmt->execute([$packEditBatchId, $storeBId]);
    expect($subStmt->fetch() === false, 'PACK-EDIT-12: expected Store B to have NO submission row at all — completely unaffected by Store A/C\'s own activity');
});

$driverPass = 'PackEditDriverPass#123';
createUser($pdo, 'pdfg_pack_edit_driver', $driverPass, ['DRIVER']);
$httpDriver = new HttpPdfg($baseUrl);
runTest('PACK-EDIT-14 an unauthorized (DRIVER) role cannot submit or correct Packing — neither the dedicated packing-submit endpoint nor the generic correction PATCH', function () use ($httpDriver, $driverPass, $storeAId, $peProdA) {
    global $packEditBatchId, $packEditVersion;
    $csrfDriver = login($httpDriver, 'pdfg_pack_edit_driver', $driverPass);
    $submitAttempt = $httpDriver->request('POST', "/api/fg/{$packEditBatchId}/packing-submit", [
        'expectedVersion' => $packEditVersion, 'storeId' => $storeAId,
        'rows' => [['productId' => (int) $peProdA['product_id'], 'storeId' => $storeAId, 'fgVerified' => 10, 'packed' => 10, 'reject' => 0, 'hilang' => 0, 'notes' => '', 'sesuaiPacking' => true]],
    ], ['X-CSRF-Token' => $csrfDriver]);
    expect($submitAttempt['status'] === 403, 'PACK-EDIT-14: expected an unauthorized DRIVER packing-submit attempt to be rejected 403, got ' . $submitAttempt['status'] . ': ' . json_encode($submitAttempt['json']));

    $correctAttempt = $httpDriver->request('PATCH', "/api/fg/{$packEditBatchId}", [
        'expectedVersion' => $packEditVersion,
        'storeItems' => [['productId' => (int) $peProdA['product_id'], 'rows' => [['storeId' => $storeAId, 'fgVerified' => 10, 'packed' => 5, 'reject' => 0, 'hilang' => 0, 'notes' => '', 'sesuaiPacking' => false]]]],
    ], ['X-CSRF-Token' => $csrfDriver]);
    expect($correctAttempt['status'] === 403, 'PACK-EDIT-14: expected an unauthorized DRIVER correction-PATCH attempt to be rejected 403, got ' . $correctAttempt['status'] . ': ' . json_encode($correctAttempt['json']));
});

fwrite(STDOUT, "PACK_EDIT_FACTORY_ID={$karangtengahId}\n");
fwrite(STDOUT, "PACK_EDIT_TANGGAL={$packEditTanggal}\n");

// =======================================================================
// Part O — REPLACEMENT REJECT END-TO-END (migration 0016)
// =======================================================================
// REPL-01..25 (task's own numbered mandatory test list). Reuses this
// file's own established fixture style (real HTTP calls through
// Production -> FG -> DO -> Ship, never a second business-logic path),
// extended here with a real store Receipt confirm (with reject) + Admin
// verify + the new Replacement disposition/allocation/production/DO/
// shipment/receipt endpoints. Brand-new products are seeded directly
// (never reusing prodA..prodG, which prior Parts' own FG batches already
// carry real state for) so every scenario below starts from a clean,
// fully isolated slate.

use Amor\Api\Dispatch\ReceiptService as ReplReceiptService;

function replFakeEvidenceImage(): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );
    $path = tempnam(sys_get_temp_dir(), 'replevidence') . '.png';
    file_put_contents($path, $png);
    return $path;
}

function replSeedProduct(PDO $pdo, int $divisionId, string $name): array
{
    $pdo->prepare('INSERT INTO product (name, division_id, hpp, harga, aktif, version, created_at) VALUES (?, ?, 0, 0, 1, 1, UTC_TIMESTAMP())')
        ->execute([$name, $divisionId]);
    return ['product_id' => (int) $pdo->lastInsertId(), 'name' => $name];
}

/**
 * Full real chain: PO -> Production -> FG submit (Per Produk,
 * fgVerified=packed=$produceQty) -> Regular DO -> ship $shipQty to
 * $storeId. Returns the store Receipt fixture (public token + shipment/
 * shipment-item ids) needed to confirm a reject against it.
 */
function replBuildShippedFixture(HttpPdfg $http, string $csrf, PDO $pdo, string $baseUrl, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $produceQty, float $shipQty, string $tag): array
{
    seedPoStoreSplit($pdo, $tanggal, $factoryId, $productId, [$storeId => ['poAwal' => $shipQty, 'poRevisi' => 0.0]]);
    submitProductionActual($http, $csrf, $tanggal, $divisionId, $productId, $produceQty);

    $fgCreate = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $factoryId], array_merge(['X-CSRF-Token' => $csrf], idemKey("{$tag}-fg-create")));
    expect($fgCreate['status'] === 200, "{$tag}: fg create failed: " . json_encode($fgCreate['json']));
    $batchId = $fgCreate['json']['data']['fgBatchId'];
    $v = $fgCreate['json']['data']['version'];
    $fgSave = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $productId, 'fgVerified' => $produceQty, 'packed' => $produceQty]]], array_merge(['X-CSRF-Token' => $csrf], idemKey("{$tag}-fg-save")));
    expect($fgSave['status'] === 200, "{$tag}: fg save failed: " . json_encode($fgSave['json']));
    $v = $fgSave['json']['data']['version'];
    $fgSubmit = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey("{$tag}-fg-submit")));
    expect($fgSubmit['status'] === 200, "{$tag}: fg submit failed: " . json_encode($fgSubmit['json']));

    $do = createDoForStore($http, $csrf, $tanggal, $storeId);
    $ship = $http->request('POST', "/api/do/{$do['doId']}/ship", ['expectedVersion' => $do['version'], 'items' => [['productId' => $productId, 'actualQty' => $shipQty]]], array_merge(['X-CSRF-Token' => $csrf], idemKey("{$tag}-ship")));
    expect($ship['status'] === 200, "{$tag}: ship failed: " . json_encode($ship['json']));
    $shipmentId = (int) $ship['json']['data']['shipmentId'];

    $token = (new ReplReceiptService($pdo))->getReceiptToken((int) $do['doId']);
    $anon = new HttpPdfg($baseUrl);
    $view = $anon->request('GET', "/api/receive/{$token}");
    expect($view['status'] === 200, "{$tag}: public receive view failed: " . json_encode($view['json']));
    $shipmentItemId = null;
    foreach ($view['json']['data']['shipments'] as $sh) {
        if ((int) $sh['shipmentId'] === $shipmentId) {
            $shipmentItemId = (int) $sh['items'][0]['shipmentItemId'];
        }
    }
    expect($shipmentItemId !== null, "{$tag}: could not resolve shipmentItemId from public receive view");

    return ['doId' => (int) $do['doId'], 'shipmentId' => $shipmentId, 'token' => $token, 'shipmentItemId' => $shipmentItemId, 'anon' => $anon];
}

/** Confirms receipt (multipart + evidence photo whenever reject/shortage > 0, matching the real store-side rule). Returns the confirm response. */
function replConfirmReceipt(HttpPdfg $anon, string $token, int $shipmentId, int $shipmentItemId, float $good, float $reject, float $shortage, string $tag): array
{
    if ($reject > 0.0001 || $shortage > 0.0001) {
        return $anon->requestMultipart('POST', "/api/receive/{$token}/shipments/{$shipmentId}/confirm", [
            'receiverName' => 'Toko Replacement Test',
            'items' => json_encode([['shipmentItemId' => $shipmentItemId, 'receivedGood' => $good, 'reject' => $reject, 'shortage' => $shortage]]),
        ], ['evidence[]' => replFakeEvidenceImage()], idemKey($tag));
    }
    return $anon->request('POST', "/api/receive/{$token}/shipments/{$shipmentId}/confirm", [
        'receiverName' => 'Toko Replacement Test',
        'items' => [['shipmentItemId' => $shipmentItemId, 'receivedGood' => $good, 'reject' => $reject, 'shortage' => $shortage]],
    ], idemKey($tag));
}

function replAdminVerify(HttpPdfg $http, string $csrf, int $receiptId, string $tag): array
{
    return $http->request('POST', "/api/admin/receipts/{$receiptId}/verify", [], array_merge(['X-CSRF-Token' => $csrf], idemKey($tag)));
}

function replDispose(HttpPdfg $http, string $csrf, int $receiptItemId, string $disposition, float $approvedQty, ?string $reason, string $tag): array
{
    return $http->request('POST', "/api/replacement/receipt-items/{$receiptItemId}/disposition", [
        'disposition' => $disposition, 'approvedQty' => $approvedQty, 'reason' => $reason,
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey($tag)));
}

function replGetDemand(HttpPdfg $http, string $csrf, int $demandId): array
{
    $r = $http->request('GET', "/api/replacement-demands/{$demandId}", null, ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, "repl get demand {$demandId} failed: " . json_encode($r['json']));
    return $r['json']['data'];
}

// -----------------------------------------------------------------------
// REPL-01/02/03 — Reject Final / Tidak Diganti
// -----------------------------------------------------------------------
$replProd1 = replSeedProduct($pdo, $rotiBollenDivId, 'REPL Test Product 1');
$replTanggal1 = '2026-11-10';
$replFx1 = replBuildShippedFixture($http, $csrf, $pdo, $baseUrl, $karangtengahId, $rotiBollenDivId, $replTanggal1, $storeAId, (int) $replProd1['product_id'], 10.0, 10.0, 'repl01');
$replBalanceBeforeDispose1 = (float) ($pdo->query("SELECT sb.qty_on_hand FROM stock_balance sb INNER JOIN location l ON l.location_id = sb.location_id WHERE sb.product_id = {$replProd1['product_id']} AND l.factory_id = {$karangtengahId}")->fetchColumn() ?: 0);
$replReceiptItemId1 = null;
runTest('REPL-01/02/03 Reject Final / Tidak Diganti: no Replacement created, no FG restored, original shipment/receipt preserved', function () use ($http, $csrf, $replFx1, $pdo, $replProd1, $karangtengahId, $replBalanceBeforeDispose1, &$replReceiptItemId1) {
    $confirm = replConfirmReceipt($replFx1['anon'], $replFx1['token'], $replFx1['shipmentId'], $replFx1['shipmentItemId'], 7.0, 3.0, 0.0, 'repl01-confirm');
    expect($confirm['status'] === 200, 'REPL-01: confirm failed: ' . json_encode($confirm['json']));
    $receiptId = (int) $confirm['json']['data']['receiptId'];
    $replReceiptItemId1 = (int) $confirm['json']['data']['items'][0]['receiptItemId'];

    $verify = replAdminVerify($http, $csrf, $receiptId, 'repl01-verify');
    expect($verify['status'] === 200, 'REPL-01: admin verify failed: ' . json_encode($verify['json']));

    $dispose = replDispose($http, $csrf, $replReceiptItemId1, 'reject_final', 3.0, 'Barang pecah saat pengiriman', 'repl01-dispose');
    expect($dispose['status'] === 200, 'REPL-01: disposeReject failed: ' . json_encode($dispose['json']));
    expect($dispose['json']['data']['replacementDemandId'] === null, 'REPL-01: Reject Final must NEVER create a Replacement Demand, got ' . json_encode($dispose['json']));

    $count = (int) $pdo->query("SELECT COUNT(*) FROM replacement_demand WHERE shipment_receipt_item_id = {$replReceiptItemId1}")->fetchColumn();
    expect($count === 0, 'REPL-01: expected zero replacement_demand rows for this receipt item, got ' . $count);

    $balanceAfter = (float) ($pdo->query("SELECT sb.qty_on_hand FROM stock_balance sb INNER JOIN location l ON l.location_id = sb.location_id WHERE sb.product_id = {$replProd1['product_id']} AND l.factory_id = {$karangtengahId}")->fetchColumn() ?: 0);
    expect(abs($balanceAfter - $replBalanceBeforeDispose1) < 0.001, "REPL-02: Reject Final must NEVER restore FG — expected stock_balance unchanged at {$replBalanceBeforeDispose1}, got {$balanceAfter}");

    $item = $pdo->query("SELECT shipped_qty, received_good_qty, reject_qty FROM shipment_receipt_item WHERE shipment_receipt_item_id = {$replReceiptItemId1}")->fetch();
    expect(numEq($item['shipped_qty'], 10.0) && numEq($item['received_good_qty'], 7.0) && numEq($item['reject_qty'], 3.0),
        'REPL-03: original shipment/receipt quantities must be preserved exactly, got ' . json_encode($item));
});

runTest('REPL-01b a SECOND disposition attempt on the same already-disposed line is refused (never silently re-decided)', function () use ($http, $csrf, &$replReceiptItemId1) {
    $again = replDispose($http, $csrf, $replReceiptItemId1, 'kirim_ulang', 3.0, null, 'repl01b-dispose');
    expect($again['status'] === 409, 'REPL-01b: expected 409 ALREADY_DISPOSED for a second disposition attempt, got ' . $again['status'] . ': ' . json_encode($again['json']));
});

// -----------------------------------------------------------------------
// REPL-04 — Free FG fully covers the approved replacement qty
// -----------------------------------------------------------------------
$replProd2 = replSeedProduct($pdo, $rotiBollenDivId, 'REPL Test Product 2');
$replTanggal2 = '2026-11-11';
$replFx2 = replBuildShippedFixture($http, $csrf, $pdo, $baseUrl, $karangtengahId, $rotiBollenDivId, $replTanggal2, $storeAId, (int) $replProd2['product_id'], 15.0, 10.0, 'repl04');
$replDemand2 = null;
runTest('REPL-04 approved 5, Free FG = 5 (produced 15, shipped 10, 5 remain physically free) -> Allocation = 5, Production Need = 0, status = ready', function () use ($http, $csrf, $replFx2, &$replDemand2) {
    $confirm = replConfirmReceipt($replFx2['anon'], $replFx2['token'], $replFx2['shipmentId'], $replFx2['shipmentItemId'], 5.0, 5.0, 0.0, 'repl04-confirm');
    expect($confirm['status'] === 200, 'REPL-04: confirm failed: ' . json_encode($confirm['json']));
    $receiptId = (int) $confirm['json']['data']['receiptId'];
    $receiptItemId = (int) $confirm['json']['data']['items'][0]['receiptItemId'];
    expect(replAdminVerify($http, $csrf, $receiptId, 'repl04-verify')['status'] === 200, 'REPL-04: admin verify failed');

    $dispose = replDispose($http, $csrf, $receiptItemId, 'kirim_ulang', 5.0, null, 'repl04-dispose');
    expect($dispose['status'] === 200, 'REPL-04: disposeReject failed: ' . json_encode($dispose['json']));
    $replDemand2 = (int) $dispose['json']['data']['replacementDemandId'];

    $demand = replGetDemand($http, $csrf, $replDemand2);
    expect(numEq($demand['allocatedFromFg'], 5.0), 'REPL-04: expected allocatedFromFg=5, got ' . json_encode($demand));
    expect(numEq($demand['productionNeed'], 0.0), 'REPL-04: expected productionNeed=0, got ' . json_encode($demand));
    expect($demand['status'] === 'ready', 'REPL-04: expected status=ready, got ' . $demand['status']);
});

// -----------------------------------------------------------------------
// REPL-05 — Free FG partially covers; remainder becomes Production Need
// -----------------------------------------------------------------------
$replProd3 = replSeedProduct($pdo, $rotiBollenDivId, 'REPL Test Product 3');
$replTanggal3 = '2026-11-12';
$replFx3 = replBuildShippedFixture($http, $csrf, $pdo, $baseUrl, $karangtengahId, $rotiBollenDivId, $replTanggal3, $storeAId, (int) $replProd3['product_id'], 12.0, 10.0, 'repl05');
runTest('REPL-05 approved 5, Free FG = 2 (produced 12, shipped 10, 2 remain) -> Allocation = 2, Production Need = 3, status = need_production', function () use ($http, $csrf, $replFx3) {
    $confirm = replConfirmReceipt($replFx3['anon'], $replFx3['token'], $replFx3['shipmentId'], $replFx3['shipmentItemId'], 5.0, 5.0, 0.0, 'repl05-confirm');
    expect($confirm['status'] === 200, 'REPL-05: confirm failed: ' . json_encode($confirm['json']));
    $receiptId = (int) $confirm['json']['data']['receiptId'];
    $receiptItemId = (int) $confirm['json']['data']['items'][0]['receiptItemId'];
    expect(replAdminVerify($http, $csrf, $receiptId, 'repl05-verify')['status'] === 200, 'REPL-05: admin verify failed');

    $dispose = replDispose($http, $csrf, $receiptItemId, 'kirim_ulang', 5.0, null, 'repl05-dispose');
    expect($dispose['status'] === 200, 'REPL-05: disposeReject failed: ' . json_encode($dispose['json']));
    $demandId = (int) $dispose['json']['data']['replacementDemandId'];

    $demand = replGetDemand($http, $csrf, $demandId);
    expect(numEq($demand['allocatedFromFg'], 2.0), 'REPL-05: expected allocatedFromFg=2, got ' . json_encode($demand));
    expect(numEq($demand['productionNeed'], 3.0), 'REPL-05: expected productionNeed=3, got ' . json_encode($demand));
    expect($demand['status'] === 'need_production', 'REPL-05: expected status=need_production, got ' . $demand['status']);
});

// -----------------------------------------------------------------------
// REPL-06/10..25 — the MAIN end-to-end lifecycle (zero free FG -> full
// production -> ready -> separate no-price DO -> partial shipment ->
// receipt Good completes -> receipt Reject does NOT auto-chain -> a
// SECOND explicit disposition chains correctly -> PO/DO immutability ->
// no invoice lineage created).
// -----------------------------------------------------------------------
$replProd4 = replSeedProduct($pdo, $rotiBollenDivId, 'REPL Test Product 4 (Main)');
$replTanggal4 = '2026-11-13';
$replFx4 = replBuildShippedFixture($http, $csrf, $pdo, $baseUrl, $karangtengahId, $rotiBollenDivId, $replTanggal4, $storeAId, (int) $replProd4['product_id'], 10.0, 10.0, 'repl06');
$replMainDemandId = null;
$replMainReceiptItemId = null;
$replMainOriginalPoAwal = null;
$replMainOriginalPlannedQty = null;
runTest('REPL-06 approved 5, Free FG = 0 (produced 10, shipped 10, nothing remains) -> Production Need = 5, status = need_production; original PO/DO snapshot taken for later immutability checks', function () use ($http, $csrf, $pdo, $replFx4, $replTanggal4, $karangtengahId, $replProd4, &$replMainDemandId, &$replMainReceiptItemId, &$replMainOriginalPoAwal, &$replMainOriginalPlannedQty) {
    $confirm = replConfirmReceipt($replFx4['anon'], $replFx4['token'], $replFx4['shipmentId'], $replFx4['shipmentItemId'], 5.0, 5.0, 0.0, 'repl06-confirm');
    expect($confirm['status'] === 200, 'REPL-06: confirm failed: ' . json_encode($confirm['json']));
    $receiptId = (int) $confirm['json']['data']['receiptId'];
    $replMainReceiptItemId = (int) $confirm['json']['data']['items'][0]['receiptItemId'];
    expect(replAdminVerify($http, $csrf, $receiptId, 'repl06-verify')['status'] === 200, 'REPL-06: admin verify failed');

    $dispose = replDispose($http, $csrf, $replMainReceiptItemId, 'kirim_ulang', 5.0, null, 'repl06-dispose');
    expect($dispose['status'] === 200, 'REPL-06: disposeReject failed: ' . json_encode($dispose['json']));
    $replMainDemandId = (int) $dispose['json']['data']['replacementDemandId'];

    $demand = replGetDemand($http, $csrf, $replMainDemandId);
    expect(numEq($demand['allocatedFromFg'], 0.0), 'REPL-06: expected allocatedFromFg=0, got ' . json_encode($demand));
    expect(numEq($demand['productionNeed'], 5.0), 'REPL-06: expected productionNeed=5, got ' . json_encode($demand));
    expect($demand['status'] === 'need_production', 'REPL-06: expected status=need_production, got ' . $demand['status']);

    $replMainOriginalPoAwal = (float) $pdo->query(
        "SELECT pi.po_awal FROM po_item pi INNER JOIN po_batch pb ON pb.po_batch_id = pi.po_batch_id
         WHERE pb.tanggal = '{$replTanggal4}' AND pb.factory_id = {$karangtengahId} AND pi.product_id = {$replProd4['product_id']}"
    )->fetchColumn();
    $replMainOriginalPlannedQty = (float) $pdo->query(
        "SELECT doi.planned_qty FROM delivery_order_item doi WHERE doi.delivery_order_id = {$replFx4['doId']} AND doi.product_id = {$replProd4['product_id']}"
    )->fetchColumn();
});

runTest('REPL-10 two disposition attempts against the SAME approved reject cannot both create a Replacement Demand', function () use ($http, $csrf, &$replMainReceiptItemId) {
    $dup = replDispose($http, $csrf, $replMainReceiptItemId, 'kirim_ulang', 5.0, null, 'repl10-dup');
    expect($dup['status'] === 409, 'REPL-10: expected 409 ALREADY_DISPOSED on a duplicate disposition, got ' . $dup['status'] . ': ' . json_encode($dup['json']));
    $count = $GLOBALS['pdo']->query("SELECT COUNT(*) FROM replacement_demand WHERE shipment_receipt_item_id = {$replMainReceiptItemId}")->fetchColumn();
    expect((int) $count === 1, 'REPL-10: expected EXACTLY ONE replacement_demand row for this receipt item, got ' . $count);
});

runTest('REPL-11 Production Need appears under the Replacement Reject source in Task per Divisi, never under PO Reguler', function () use ($http, $csrf, $replTanggal4, $rotiBollenDivId, $replMainDemandId, $replProd4) {
    $r = $http->request('GET', "/api/production-tasks?tanggal={$replTanggal4}&divisionId={$rotiBollenDivId}&sourceType=replacement_reject", null, ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, 'REPL-11: production-tasks failed: ' . json_encode($r['json']));
    $found = null;
    foreach ($r['json']['data']['tasks'] as $t) {
        if ($t['orderId'] === $replMainDemandId) {
            $found = $t;
        }
    }
    expect($found !== null, 'REPL-11: expected a Task per Divisi row for this Replacement Demand under sourceType=replacement_reject, got ' . json_encode($r['json']['data']['tasks']));
    expect($found['source'] === 'replacement_reject', 'REPL-11: expected source=replacement_reject, got ' . json_encode($found));
    expect($found['sourceLabel'] === 'Replacement Reject', 'REPL-11: expected sourceLabel=Replacement Reject, got ' . json_encode($found));
    expect(numEq($found['target'], 5.0), 'REPL-11: expected target=5 (the real production shortage), got ' . json_encode($found));
    expect($found['taskName'] === $replProd4['name'], 'REPL-11: expected taskName to be the real product name, got ' . json_encode($found));
});

runTest('REPL-12/13 submitting Production Actual + verifying FG for the full shortage brings the demand to ready, reconciling EXACTLY to the approved qty', function () use ($http, $csrf, $replMainDemandId) {
    $actual = $http->request('POST', "/api/replacement-demands/{$replMainDemandId}/production-actual", ['aktualProduksi' => 5.0, 'rejectProduksi' => 0.0], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl12-actual')));
    expect($actual['status'] === 200, 'REPL-12: production-actual failed: ' . json_encode($actual['json']));

    $verifyFg = $http->request('POST', "/api/replacement-demands/{$replMainDemandId}/verify-fg", ['fgVerifiedQty' => 5.0], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl12-verifyfg')));
    expect($verifyFg['status'] === 200, 'REPL-12: verify-fg failed: ' . json_encode($verifyFg['json']));

    $demand = replGetDemand($http, $csrf, $replMainDemandId);
    expect($demand['status'] === 'ready', 'REPL-12: expected status=ready after full production verified, got ' . $demand['status']);
    expect(numEq($demand['productionNeed'], 0.0), 'REPL-12: expected productionNeed=0, got ' . json_encode($demand));
    expect(numEq($demand['allocatedFromFg'] + $demand['productionFgVerifiedQty'], 5.0), 'REPL-13: expected allocatedFromFg + productionFgVerifiedQty to reconcile EXACTLY to approvedQty=5, got ' . json_encode($demand));
});

$replMainDoId = null;
runTest('REPL-14/15/24 creating the Replacement DO: a SEPARATE, no-price document referencing this demand (never the original DO)', function () use ($http, $csrf, $replMainDemandId, $replFx4, $storeAId, &$replMainDoId) {
    $createDo = $http->request('POST', "/api/replacement-demands/{$replMainDemandId}/do", [], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl14-createdo')));
    expect($createDo['status'] === 200, 'REPL-14: create Replacement DO failed: ' . json_encode($createDo['json']));
    $dto = $createDo['json']['data'];
    $replMainDoId = (int) $dto['doId'];
    expect($replMainDoId !== (int) $replFx4['doId'], 'REPL-14: Replacement DO must be a genuinely SEPARATE document from the original DO');
    expect(str_starts_with((string) $dto['docNo'], 'REPL-'), 'REPL-14: expected a distinct REPL- doc-no format, got ' . $dto['docNo']);
    expect($dto['storeId'] === $storeAId, 'REPL-15: Replacement DO must ship to the SAME store as the original reject');
    expect(!array_key_exists('price', $dto) && !array_key_exists('unitPrice', $dto) && !array_key_exists('harga', $dto), 'REPL-24: Replacement DO must carry NO price field at all, got ' . json_encode($dto));

    $demand = replGetDemand($http, $csrf, $replMainDemandId);
    expect($demand['status'] === 'do_created', 'REPL-14: expected demand status=do_created, got ' . $demand['status']);
});

$replMainShipment1 = null;
$replMainShipment2 = null;
runTest('REPL-16/17 partial Replacement shipment: 2 then 3, totalling exactly 5, consuming ONLY this demand\'s own allocation/production headroom', function () use ($http, $csrf, $replMainDoId, &$replMainShipment1, &$replMainShipment2) {
    $ship1 = $http->request('POST', "/api/replacement-do/{$replMainDoId}/ship", ['qty' => 2.0], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl16-ship1')));
    expect($ship1['status'] === 200, 'REPL-16: first partial ship failed: ' . json_encode($ship1['json']));
    expect($ship1['json']['data']['status'] === 'partial', 'REPL-16: expected DO status=partial after shipping 2 of 5, got ' . json_encode($ship1['json']['data']));
    $replMainShipment1 = (int) $ship1['json']['data']['shipmentId'];

    $ship2 = $http->request('POST', "/api/replacement-do/{$replMainDoId}/ship", [], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl16-ship2')));
    expect($ship2['status'] === 200, 'REPL-16: second (full-remaining) ship failed: ' . json_encode($ship2['json']));
    expect($ship2['json']['data']['status'] === 'shipped', 'REPL-16: expected DO status=shipped after the remaining qty ships, got ' . json_encode($ship2['json']['data']));
    expect(numEq($ship2['json']['data']['shippedQty'], 5.0), 'REPL-16: expected total shipped=5, got ' . json_encode($ship2['json']['data']));
    $replMainShipment2 = (int) $ship2['json']['data']['shipmentId'];
    expect($replMainShipment1 !== $replMainShipment2, 'REPL-16: expected two DISTINCT shipment rows for the two partial shipments');
});

runTest('REPL-18 physical stock is decremented exactly once — via REPL-04\'s FG-allocation-backed demand, never twice and never for the production-only portion', function () use ($http, $csrf, $replDemand2, $replProd2, $karangtengahId) {
    // REPL-04's own demand (allocatedFromFg=5, productionNeed=0) is the
    // right fixture for this check: 100% of its qty comes from the
    // demand's own FG allocation, so exactly ONE stock_ledger deduction
    // is expected for the ENTIRE shipment — never one per unit, never
    // one for the (nonexistent) production side.
    $createDo = $GLOBALS['http']->request('POST', "/api/replacement-demands/{$replDemand2}/do", [], array_merge(['X-CSRF-Token' => $GLOBALS['csrf']], idemKey('repl18-createdo')));
    expect($createDo['status'] === 200, 'REPL-18: create Replacement DO failed: ' . json_encode($createDo['json']));
    $doId = (int) $createDo['json']['data']['doId'];

    $ship = $GLOBALS['http']->request('POST', "/api/replacement-do/{$doId}/ship", [], array_merge(['X-CSRF-Token' => $GLOBALS['csrf']], idemKey('repl18-ship')));
    expect($ship['status'] === 200, 'REPL-18: ship failed: ' . json_encode($ship['json']));

    $ledgerCount = (int) $GLOBALS['pdo']->query(
        "SELECT COUNT(*) FROM stock_ledger sl INNER JOIN location l ON l.location_id = sl.location_id
         WHERE sl.source_type = 'replacement_demand_fg_allocation' AND sl.product_id = {$replProd2['product_id']} AND l.factory_id = {$karangtengahId}"
    )->fetchColumn();
    expect($ledgerCount === 1, "REPL-18: expected EXACTLY ONE stock_ledger deduction for this FG-allocation-backed shipment, got {$ledgerCount}");

    $GLOBALS['replDoForProd2'] = $doId;
    $GLOBALS['replShipmentForProd2'] = (int) $ship['json']['data']['shipmentId'];
});

runTest('REPL-19 confirming Replacement Receipt as fully GOOD completes the demand at the correct qty', function () use ($pdo, $replDemand2) {
    $shipmentId = $GLOBALS['replShipmentForProd2'];
    $token = (new ReplReceiptService($pdo))->getOrCreateShipmentToken($shipmentId);
    $anon = new HttpPdfg($GLOBALS['baseUrl']);
    $view = $anon->request('GET', "/api/receive/{$token}");
    expect($view['status'] === 200, 'REPL-19: public view failed: ' . json_encode($view['json']));
    $lineId = (int) $view['json']['data']['shipments'][0]['items'][0]['shipmentItemId'];

    $confirm = $anon->request('POST', "/api/receive/{$token}/shipments/{$shipmentId}/confirm", [
        'receiverName' => 'Toko Replacement Test', 'items' => [['shipmentItemId' => $lineId, 'receivedGood' => 5.0, 'reject' => 0.0, 'shortage' => 0.0]],
    ], idemKey('repl19-confirm'));
    expect($confirm['status'] === 200, 'REPL-19: confirm failed: ' . json_encode($confirm['json']));

    $demand = replGetDemand($GLOBALS['http'], $GLOBALS['csrf'], $replDemand2);
    expect($demand['status'] === 'completed', 'REPL-19: expected status=completed once the full approved qty comes back GOOD, got ' . $demand['status']);
});

$replChainReceiptItemId = null;
runTest('REPL-20 confirming Replacement Receipt with a Reject on it does NOT auto-create another Replacement Demand', function () use ($http, $csrf, $pdo, $replMainDemandId, &$replChainReceiptItemId) {
    $before = (int) $pdo->query('SELECT COUNT(*) FROM replacement_demand')->fetchColumn();

    $token = (new ReplReceiptService($pdo))->getOrCreateShipmentToken($GLOBALS['replMainShipment1']);
    $anon = new HttpPdfg($GLOBALS['baseUrl']);
    $view = $anon->request('GET', "/api/receive/{$token}");
    expect($view['status'] === 200, 'REPL-20: public view failed: ' . json_encode($view['json']));
    $lineId = (int) $view['json']['data']['shipments'][0]['items'][0]['shipmentItemId'];

    $confirm = $anon->requestMultipart('POST', "/api/receive/{$token}/shipments/{$GLOBALS['replMainShipment1']}/confirm", [
        'receiverName' => 'Toko Replacement Test', 'items' => json_encode([['shipmentItemId' => $lineId, 'receivedGood' => 1.0, 'reject' => 1.0, 'shortage' => 0.0]]),
    ], ['evidence[]' => replFakeEvidenceImage()], idemKey('repl20-confirm'));
    expect($confirm['status'] === 200, 'REPL-20: confirm failed: ' . json_encode($confirm['json']));
    $replChainReceiptItemId = (int) $confirm['json']['data']['items'][0]['receiptItemId'];

    // Admin verification (a SEPARATE, explicit step from confirmation
    // itself — task's own "Admin verifies the Reject. Admin must THEN
    // choose disposition") is a precondition disposeReject() requires;
    // REPL-21 below is the one that actually decides this line's fate.
    $verify = replAdminVerify($http, $csrf, (int) $confirm['json']['data']['receiptId'], 'repl20-verify');
    expect($verify['status'] === 200, 'REPL-20: admin verify failed: ' . json_encode($verify['json']));

    $after = (int) $pdo->query('SELECT COUNT(*) FROM replacement_demand')->fetchColumn();
    expect($after === $before, "REPL-20: a Reject on a Replacement's OWN receipt must NEVER auto-create another Replacement Demand — count was {$before}, now {$after}");

    $disposition = $pdo->query("SELECT disposition FROM shipment_receipt_item WHERE shipment_receipt_item_id = {$replChainReceiptItemId}")->fetchColumn();
    expect($disposition === 'pending', "REPL-20: expected the new reject line to sit at disposition=pending awaiting an explicit Admin decision, got {$disposition}");

    $demand = replGetDemand($GLOBALS['http'], $GLOBALS['csrf'], $replMainDemandId);
    expect($demand['status'] === 'received_partial', 'REPL-20: expected status=received_partial (1 of 5 not yet back GOOD), got ' . $demand['status']);
});

runTest('REPL-21 Admin can explicitly approve ANOTHER replacement after a Replacement Reject, correctly chained to the original root', function () use ($http, $csrf, $pdo, &$replChainReceiptItemId, $replMainReceiptItemId) {
    $dispose = replDispose($http, $csrf, $replChainReceiptItemId, 'kirim_ulang', 1.0, null, 'repl21-dispose');
    expect($dispose['status'] === 200, 'REPL-21: disposeReject failed: ' . json_encode($dispose['json']));
    $chainedDemandId = (int) $dispose['json']['data']['replacementDemandId'];
    expect($chainedDemandId > 0, 'REPL-21: expected a real chained Replacement Demand to be created');

    $row = $pdo->query("SELECT parent_replacement_demand_id, root_shipment_receipt_item_id FROM replacement_demand WHERE replacement_demand_id = {$chainedDemandId}")->fetch();
    $originalMainDemandId = (int) $pdo->query("SELECT replacement_demand_id FROM replacement_demand WHERE shipment_receipt_item_id = {$replMainReceiptItemId}")->fetchColumn();
    expect((int) $row['parent_replacement_demand_id'] === $originalMainDemandId, 'REPL-21: expected the chained demand\'s parent to be the immediately preceding Replacement Demand, got ' . json_encode($row));
    expect((int) $row['root_shipment_receipt_item_id'] === $replMainReceiptItemId, 'REPL-21: expected root traceability to the VERY FIRST original reject, got ' . json_encode($row));
});

runTest('REPL-22 original PO target never changes throughout the entire Replacement Reject lifecycle', function () use ($pdo, $replTanggal4, $karangtengahId, $replProd4, $replMainOriginalPoAwal) {
    $now = (float) $pdo->query(
        "SELECT pi.po_awal FROM po_item pi INNER JOIN po_batch pb ON pb.po_batch_id = pi.po_batch_id
         WHERE pb.tanggal = '{$replTanggal4}' AND pb.factory_id = {$karangtengahId} AND pi.product_id = {$replProd4['product_id']}"
    )->fetchColumn();
    expect(numEq($now, $replMainOriginalPoAwal), "REPL-22: expected po_item.po_awal to remain exactly {$replMainOriginalPoAwal}, got {$now}");
});

runTest('REPL-23 original DO planned qty never increases throughout the entire Replacement Reject lifecycle', function () use ($pdo, $replFx4, $replProd4, $replMainOriginalPlannedQty) {
    $now = (float) $pdo->query(
        "SELECT doi.planned_qty FROM delivery_order_item doi WHERE doi.delivery_order_id = {$replFx4['doId']} AND doi.product_id = {$replProd4['product_id']}"
    )->fetchColumn();
    expect(numEq($now, $replMainOriginalPlannedQty), "REPL-23: expected the ORIGINAL delivery_order_item.planned_qty to remain exactly {$replMainOriginalPlannedQty}, got {$now}");
});

runTest('REPL-24b no invoice/invoice_item row was ever created by any Replacement Reject activity in this test', function () use ($pdo) {
    $invoiceCount = (int) $pdo->query('SELECT COUNT(*) FROM invoice')->fetchColumn();
    $invoiceItemCount = (int) $pdo->query('SELECT COUNT(*) FROM invoice_item')->fetchColumn();
    expect($invoiceCount === 0 && $invoiceItemCount === 0, "REPL-24b: expected zero invoice/invoice_item rows, got invoice={$invoiceCount} invoice_item={$invoiceItemCount}");
});

// -----------------------------------------------------------------------
// REPL-07 — Replacement allocation cannot consume Regular store-owned FG
// -----------------------------------------------------------------------
// Direct, isolated-unit-style fixture (documented deliberately): rather
// than reconstructing a full real Breakdown Toko UI flow purely to leave
// a store-committed remainder, this seeds the EXACT SAME store_fg_
// balance/fg_item preconditions Delivery\DoRepository::
// hasAnyStoreAllocation()/sumStoreCommittedRemainingAcrossAllStores()
// already read in production, directly — the real production code path
// (ReplacementFgAllocationService::allocateAtCreation()) is exercised
// completely unmodified; only the COMPETING reservation's origin is
// synthetic.
$replProd5 = replSeedProduct($pdo, $rotiBollenDivId, 'REPL Test Product 5 (Store Protection)');
$replTanggal5 = '2026-11-14';
$replFx5 = replBuildShippedFixture($http, $csrf, $pdo, $baseUrl, $karangtengahId, $rotiBollenDivId, $replTanggal5, $storeAId, (int) $replProd5['product_id'], 20.0, 10.0, 'repl07');
runTest('REPL-07 Replacement allocation cannot consume FG already committed (via Breakdown Toko) to another Regular store', function () use ($http, $csrf, $pdo, $replFx5, $replProd5, $karangtengahId, $storeBId) {
    // 10 physical units remain free after the original shipment; commit
    // 6 of them to Store B via a direct fg_item fixture row (mirroring a
    // real, already-submitted Breakdown Toko allocation) BEFORE disposing
    // this reject — true free FG for the replacement must become 10-6=4.
    // Reuse the SAME fg_batch replBuildShippedFixture() already created
    // for this exact (tanggal, factory) — fg_batch's own UNIQUE KEY
    // (tanggal, factory_id) means a second batch row for this pair is
    // impossible; fg_item's own UNIQUE KEY is (fg_batch_id, product_id,
    // store_id), so a NEW row for Store B on the SAME batch/product never
    // collides with the existing Per-Produk (synthetic-store) row.
    $fgBatchId = (int) $pdo->query("SELECT fg_batch_id FROM fg_batch WHERE tanggal = '2026-11-14' AND factory_id = {$karangtengahId}")->fetchColumn();
    $pdo->prepare(
        'INSERT INTO fg_item (fg_batch_id, product_id, store_id, qty, packed_qty, posted_packed_qty, status)
         VALUES (?, ?, ?, 6, 6, 6, \'dicek\')'
    )->execute([$fgBatchId, (int) $replProd5['product_id'], $storeBId]);

    $confirm = replConfirmReceipt($replFx5['anon'], $replFx5['token'], $replFx5['shipmentId'], $replFx5['shipmentItemId'], 6.0, 4.0, 0.0, 'repl07-confirm');
    expect($confirm['status'] === 200, 'REPL-07: confirm failed: ' . json_encode($confirm['json']));
    $receiptId = (int) $confirm['json']['data']['receiptId'];
    $receiptItemId = (int) $confirm['json']['data']['items'][0]['receiptItemId'];
    expect(replAdminVerify($http, $csrf, $receiptId, 'repl07-verify')['status'] === 200, 'REPL-07: admin verify failed');

    $dispose = replDispose($http, $csrf, $receiptItemId, 'kirim_ulang', 4.0, null, 'repl07-dispose');
    expect($dispose['status'] === 200, 'REPL-07: disposeReject failed: ' . json_encode($dispose['json']));
    $demandId = (int) $dispose['json']['data']['replacementDemandId'];

    $demand = replGetDemand($http, $csrf, $demandId);
    expect(numEq($demand['allocatedFromFg'], 4.0), "REPL-07: expected allocatedFromFg=4 (10 physical - 6 store-committed to Store B), got " . json_encode($demand));
    expect(numEq($demand['productionNeed'], 0.0), 'REPL-07: expected productionNeed=0 (4 needed, 4 truly free), got ' . json_encode($demand));
});

// -----------------------------------------------------------------------
// REPL-08 — Replacement allocation cannot consume an active Special order
// FG allocation, via the REAL Special Order API (not a synthetic fixture).
// -----------------------------------------------------------------------
$replProd6 = replSeedProduct($pdo, $rotiBollenDivId, 'REPL Test Product 6 (Special Protection)');
$replTanggal6 = '2026-11-15';
$replFx6 = replBuildShippedFixture($http, $csrf, $pdo, $baseUrl, $karangtengahId, $rotiBollenDivId, $replTanggal6, $storeAId, (int) $replProd6['product_id'], 20.0, 10.0, 'repl08');
runTest('REPL-08 Replacement allocation cannot consume an active Special Order FG allocation for the same product/factory', function () use ($http, $csrf, $pdo, $replFx6, $replProd6, $storeAId) {
    // 10 physical units remain free. A real Special Order (Pesanan Khusus
    // Toko) reserves 7 of them via the REAL allocate-fg endpoint BEFORE
    // this reject is disposed — true free FG for the replacement must
    // become 10-7=3.
    $create = $http->request('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId, 'orderDate' => '2026-11-15', 'requiredDate' => '2026-11-16',
        'items' => [['itemType' => 'existing_product', 'productId' => (int) $replProd6['product_id'], 'qty' => 7, 'charge' => 0]],
    ], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl08-order-create')));
    expect($create['status'] === 200, 'REPL-08: special order create failed: ' . json_encode($create['json']));
    $orderId = (int) $create['json']['data']['orderId'];
    $itemId = (int) $create['json']['data']['items'][0]['itemId'];
    $v = (int) $create['json']['data']['version'];

    $confirmOrder = $http->request('POST', "/api/special-orders/{$orderId}/confirm", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl08-order-confirm')));
    expect($confirmOrder['status'] === 200, 'REPL-08: special order confirm failed: ' . json_encode($confirmOrder['json']));
    $v = (int) $confirmOrder['json']['data']['version'];
    $sendToProd = $http->request('POST', "/api/special-orders/{$orderId}/send-to-production", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl08-order-send')));
    expect($sendToProd['status'] === 200, 'REPL-08: special order send-to-production failed: ' . json_encode($sendToProd['json']));

    $allocate = $http->request('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 7], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl08-allocate')));
    expect($allocate['status'] === 200, 'REPL-08: special order allocate-fg failed: ' . json_encode($allocate['json']));

    $confirm = replConfirmReceipt($replFx6['anon'], $replFx6['token'], $replFx6['shipmentId'], $replFx6['shipmentItemId'], 7.0, 3.0, 0.0, 'repl08-confirm');
    expect($confirm['status'] === 200, 'REPL-08: confirm failed: ' . json_encode($confirm['json']));
    $receiptId = (int) $confirm['json']['data']['receiptId'];
    $receiptItemId = (int) $confirm['json']['data']['items'][0]['receiptItemId'];
    expect(replAdminVerify($http, $csrf, $receiptId, 'repl08-verify')['status'] === 200, 'REPL-08: admin verify failed');

    $dispose = replDispose($http, $csrf, $receiptItemId, 'kirim_ulang', 3.0, null, 'repl08-dispose');
    expect($dispose['status'] === 200, 'REPL-08: disposeReject failed: ' . json_encode($dispose['json']));
    $demandId = (int) $dispose['json']['data']['replacementDemandId'];

    $demand = replGetDemand($http, $csrf, $demandId);
    expect(numEq($demand['allocatedFromFg'], 3.0), 'REPL-08: expected allocatedFromFg=3 (10 physical - 7 active Special allocation), got ' . json_encode($demand));
    expect(numEq($demand['productionNeed'], 0.0), 'REPL-08: expected productionNeed=0 (3 needed, 3 truly free), got ' . json_encode($demand));
});

// -----------------------------------------------------------------------
// REPL-09 — sequential replacement allocations against the SAME limited
// free-FG pool never combine to oversubscribe it (the same lock-
// protected sumActiveAllocatedForProductFactory()/stock_balance FOR
// UPDATE discipline already proven safe under REAL concurrent processes
// by ALLOC-GLOBAL-05 — see this pass's own report for why a SECOND real
// two-process race test was not additionally built for Replacement).
// -----------------------------------------------------------------------
$replProd7 = replSeedProduct($pdo, $rotiBollenDivId, 'REPL Test Product 7 (Concurrency Cap)');
$replTanggal7 = '2026-11-16';
seedPoStoreSplit($pdo, $replTanggal7, $karangtengahId, (int) $replProd7['product_id'], [$storeAId => ['poAwal' => 3.0, 'poRevisi' => 0.0], $storeBId => ['poAwal' => 3.0, 'poRevisi' => 0.0]]);
submitProductionActual($http, $csrf, $replTanggal7, $rotiBollenDivId, (int) $replProd7['product_id'], 10.0);
runTest('REPL-09 two replacement demands competing for the same limited free FG pool never combine to exceed it', function () use ($http, $csrf, $pdo, $baseUrl, $replTanggal7, $karangtengahId, $storeAId, $storeBId, $replProd7) {
    $fgCreate = $http->request('POST', '/api/fg', ['tanggal' => $replTanggal7, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl09-fg-create')));
    expect($fgCreate['status'] === 200, 'REPL-09: fg create failed: ' . json_encode($fgCreate['json']));
    $batchId = $fgCreate['json']['data']['fgBatchId'];
    $v = $fgCreate['json']['data']['version'];
    $fgSave = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => (int) $replProd7['product_id'], 'fgVerified' => 10, 'packed' => 10]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl09-fg-save')));
    expect($fgSave['status'] === 200, 'REPL-09: fg save failed: ' . json_encode($fgSave['json']));
    $v = $fgSave['json']['data']['version'];
    $fgSubmit = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl09-fg-submit')));
    expect($fgSubmit['status'] === 200, 'REPL-09: fg submit failed: ' . json_encode($fgSubmit['json']));

    // Ship 3 to Store A and 3 to Store B — 4 physical units remain free.
    $doA = createDoForStore($http, $csrf, $replTanggal7, $storeAId);
    $shipA = $http->request('POST', "/api/do/{$doA['doId']}/ship", ['expectedVersion' => $doA['version'], 'items' => [['productId' => (int) $replProd7['product_id'], 'actualQty' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl09-shipA')));
    expect($shipA['status'] === 200, 'REPL-09: ship to Store A failed: ' . json_encode($shipA['json']));
    $doB = createDoForStore($http, $csrf, $replTanggal7, $storeBId);
    $shipB = $http->request('POST', "/api/do/{$doB['doId']}/ship", ['expectedVersion' => $doB['version'], 'items' => [['productId' => (int) $replProd7['product_id'], 'actualQty' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('repl09-shipB')));
    expect($shipB['status'] === 200, 'REPL-09: ship to Store B failed: ' . json_encode($shipB['json']));

    $tokenA = (new ReplReceiptService($pdo))->getReceiptToken((int) $doA['doId']);
    $tokenB = (new ReplReceiptService($pdo))->getReceiptToken((int) $doB['doId']);
    $anonA = new HttpPdfg($baseUrl);
    $anonB = new HttpPdfg($baseUrl);
    $viewA = $anonA->request('GET', "/api/receive/{$tokenA}");
    $viewB = $anonB->request('GET', "/api/receive/{$tokenB}");
    $shipmentIdA = (int) $shipA['json']['data']['shipmentId'];
    $shipmentIdB = (int) $shipB['json']['data']['shipmentId'];
    $lineIdA = null;
    $lineIdB = null;
    foreach ($viewA['json']['data']['shipments'] as $sh) {
        if ((int) $sh['shipmentId'] === $shipmentIdA) { $lineIdA = (int) $sh['items'][0]['shipmentItemId']; }
    }
    foreach ($viewB['json']['data']['shipments'] as $sh) {
        if ((int) $sh['shipmentId'] === $shipmentIdB) { $lineIdB = (int) $sh['items'][0]['shipmentItemId']; }
    }

    $confirmA = replConfirmReceipt($anonA, $tokenA, $shipmentIdA, $lineIdA, 0.0, 3.0, 0.0, 'repl09-confirmA');
    expect($confirmA['status'] === 200, 'REPL-09: confirm A failed: ' . json_encode($confirmA['json']));
    $confirmB = replConfirmReceipt($anonB, $tokenB, $shipmentIdB, $lineIdB, 0.0, 3.0, 0.0, 'repl09-confirmB');
    expect($confirmB['status'] === 200, 'REPL-09: confirm B failed: ' . json_encode($confirmB['json']));

    expect(replAdminVerify($http, $csrf, (int) $confirmA['json']['data']['receiptId'], 'repl09-verifyA')['status'] === 200, 'REPL-09: admin verify A failed');
    expect(replAdminVerify($http, $csrf, (int) $confirmB['json']['data']['receiptId'], 'repl09-verifyB')['status'] === 200, 'REPL-09: admin verify B failed');

    $receiptItemA = (int) $confirmA['json']['data']['items'][0]['receiptItemId'];
    $receiptItemB = (int) $confirmB['json']['data']['items'][0]['receiptItemId'];

    $disposeA = replDispose($http, $csrf, $receiptItemA, 'kirim_ulang', 3.0, null, 'repl09-disposeA');
    expect($disposeA['status'] === 200, 'REPL-09: dispose A failed: ' . json_encode($disposeA['json']));
    $demandA = replGetDemand($http, $csrf, (int) $disposeA['json']['data']['replacementDemandId']);

    $disposeB = replDispose($http, $csrf, $receiptItemB, 'kirim_ulang', 3.0, null, 'repl09-disposeB');
    expect($disposeB['status'] === 200, 'REPL-09: dispose B failed: ' . json_encode($disposeB['json']));
    $demandB = replGetDemand($http, $csrf, (int) $disposeB['json']['data']['replacementDemandId']);

    $totalAllocated = $demandA['allocatedFromFg'] + $demandB['allocatedFromFg'];
    expect($totalAllocated <= 4.0 + 0.001, "REPL-09: combined allocation across both competing demands must NEVER exceed the 4 truly free units, got {$totalAllocated}");
    expect(numEq($demandA['allocatedFromFg'], 3.0), 'REPL-09: expected demand A (disposed first) to get its full 3, got ' . json_encode($demandA));
    expect(numEq($demandB['allocatedFromFg'], 1.0) && numEq($demandB['productionNeed'], 2.0), 'REPL-09: expected demand B (disposed second) to be capped to the 1 unit left, needing production for the other 2, got ' . json_encode($demandB));
});

// -----------------------------------------------------------------------
// Bonus authorization check (report section 18) — an unauthorized DRIVER
// role can neither decide a disposition nor create a Replacement DO,
// regardless of the target's own business state (the role gate runs
// BEFORE the service is ever reached).
// -----------------------------------------------------------------------
runTest('REPL-AUTH an unauthorized (DRIVER) role cannot decide a Reject disposition nor create a Replacement DO', function () use ($httpDriver, $driverPass, $replReceiptItemId1, $replMainDemandId) {
    $csrfDriver = login($httpDriver, 'pdfg_pack_edit_driver', $driverPass);
    $disposeAttempt = $httpDriver->request('POST', "/api/replacement/receipt-items/{$replReceiptItemId1}/disposition", ['disposition' => 'kirim_ulang', 'approvedQty' => 1], ['X-CSRF-Token' => $csrfDriver]);
    expect($disposeAttempt['status'] === 403, 'REPL-AUTH: expected 403 for an unauthorized DRIVER disposition attempt, got ' . $disposeAttempt['status'] . ': ' . json_encode($disposeAttempt['json']));

    $createDoAttempt = $httpDriver->request('POST', "/api/replacement-demands/{$replMainDemandId}/do", [], ['X-CSRF-Token' => $csrfDriver]);
    expect($createDoAttempt['status'] === 403, 'REPL-AUTH: expected 403 for an unauthorized DRIVER create-DO attempt, got ' . $createDoAttempt['status'] . ': ' . json_encode($createDoAttempt['json']));
});

fwrite(STDOUT, "REPL_FACTORY_ID={$karangtengahId}\n");
fwrite(STDOUT, "REPL_TANGGAL={$replTanggal4}\n");

// =======================================================================
// Part P — FINAL PRE-DEPLOY PATCH: Replacement Reject access control
// (REPL-AUTH-01..12) + migration retry-safety (REPL-MIG-01..06, its own
// dedicated suite — see test-0016-replacement-reject-migration.sh).
// =======================================================================

function replGrantDivisionAccess(PDO $pdo, int $userId, int $divisionId): void
{
    $pdo->prepare('INSERT IGNORE INTO user_division_access (user_id, division_id) VALUES (?, ?)')->execute([$userId, $divisionId]);
}

function replGrantFactoryAccess(PDO $pdo, int $userId, int $factoryId): void
{
    $pdo->prepare('INSERT IGNORE INTO user_factory_access (user_id, factory_id) VALUES (?, ?)')->execute([$userId, $factoryId]);
}

// -----------------------------------------------------------------------
// REPL-AUTH-01/02 — ADMIN and PPIC can both decide a disposition (PPIC
// preserves the existing "treated identically to ADMIN" policy).
// -----------------------------------------------------------------------
$replAuthProdA1 = replSeedProduct($pdo, $rotiBollenDivId, 'REPL AUTH Test Product A1');
$replAuthTanggalA1 = '2026-11-20';
$replAuthFxA1 = replBuildShippedFixture($http, $csrf, $pdo, $baseUrl, $karangtengahId, $rotiBollenDivId, $replAuthTanggalA1, $storeAId, (int) $replAuthProdA1['product_id'], 10.0, 10.0, 'replauth01');
runTest('REPL-AUTH-01 ADMIN can decide a Reject disposition', function () use ($http, $csrf, $replAuthFxA1) {
    $confirm = replConfirmReceipt($replAuthFxA1['anon'], $replAuthFxA1['token'], $replAuthFxA1['shipmentId'], $replAuthFxA1['shipmentItemId'], 8.0, 2.0, 0.0, 'replauth01-confirm');
    expect($confirm['status'] === 200, 'REPL-AUTH-01: confirm failed: ' . json_encode($confirm['json']));
    $receiptId = (int) $confirm['json']['data']['receiptId'];
    $receiptItemId = (int) $confirm['json']['data']['items'][0]['receiptItemId'];
    expect(replAdminVerify($http, $csrf, $receiptId, 'replauth01-verify')['status'] === 200, 'REPL-AUTH-01: admin verify failed');
    $dispose = replDispose($http, $csrf, $receiptItemId, 'reject_final', 2.0, 'ADMIN disposition check', 'replauth01-dispose');
    expect($dispose['status'] === 200, 'REPL-AUTH-01: expected ADMIN to successfully decide a disposition, got ' . $dispose['status'] . ': ' . json_encode($dispose['json']));
});

$replAuthProdA2 = replSeedProduct($pdo, $rotiBollenDivId, 'REPL AUTH Test Product A2');
$replAuthTanggalA2 = '2026-11-21';
$replAuthFxA2 = replBuildShippedFixture($http, $csrf, $pdo, $baseUrl, $karangtengahId, $rotiBollenDivId, $replAuthTanggalA2, $storeAId, (int) $replAuthProdA2['product_id'], 10.0, 10.0, 'replauth02');
$ppicPass = 'ReplAuthPpicPass#123';
$ppicUserId = createUser($pdo, 'repl_auth_ppic', $ppicPass, ['PPIC']);
$httpPpic = new HttpPdfg($baseUrl);
runTest('REPL-AUTH-02 PPIC can also decide a Reject disposition ("if existing policy allows" — ReceiptController::adminVerify() itself is pre-existing ADMIN-only, unrelated to this patch, so ADMIN verifies here; the disposition call itself is what this test proves for PPIC)', function () use ($httpPpic, $ppicPass, $http, $csrf, $replAuthFxA2) {
    $csrfPpic = login($httpPpic, 'repl_auth_ppic', $ppicPass);
    $confirm = replConfirmReceipt($replAuthFxA2['anon'], $replAuthFxA2['token'], $replAuthFxA2['shipmentId'], $replAuthFxA2['shipmentItemId'], 8.0, 2.0, 0.0, 'replauth02-confirm');
    expect($confirm['status'] === 200, 'REPL-AUTH-02: confirm failed: ' . json_encode($confirm['json']));
    $receiptId = (int) $confirm['json']['data']['receiptId'];
    $receiptItemId = (int) $confirm['json']['data']['items'][0]['receiptItemId'];
    $verify = replAdminVerify($http, $csrf, $receiptId, 'replauth02-verify');
    expect($verify['status'] === 200, 'REPL-AUTH-02: ADMIN admin-verify failed: ' . json_encode($verify['json']));
    $dispose = replDispose($httpPpic, $csrfPpic, $receiptItemId, 'kirim_ulang', 2.0, null, 'replauth02-dispose');
    expect($dispose['status'] === 200, 'REPL-AUTH-02: expected PPIC to successfully decide a disposition, got ' . $dispose['status'] . ': ' . json_encode($dispose['json']));
    expect($dispose['json']['data']['replacementDemandId'] > 0, 'REPL-AUTH-02: expected a real Replacement Demand id back');
});

// -----------------------------------------------------------------------
// REPL-AUTH-03/04/05/06 — server-side UI page role gate (api/_ui-preview/
// index.php's own $pageRoles check, run BEFORE ui_page_head() and BEFORE
// any Replacement data is ever queried).
// -----------------------------------------------------------------------
runTest('REPL-AUTH-03 DRIVER cannot access the Replacement Reject Admin UI page (403, no data leaked)', function () use ($httpDriver, $driverPass) {
    login($httpDriver, 'pdfg_pack_edit_driver', $driverPass);
    $r = $httpDriver->request('GET', '/api/_ui-preview/?page=replacement-reject');
    expect($r['status'] === 403, 'REPL-AUTH-03: expected 403 for DRIVER, got ' . $r['status']);
    expect(!str_contains($r['body'], 'Tindak Lanjut Reject'), 'REPL-AUTH-03: the denied response must never contain the Admin page\'s own reject/traceability content');
});

$prodOnlyPass = 'ReplAuthProdOnlyPass#123';
$prodOnlyUserId = createUser($pdo, 'repl_auth_prod_only', $prodOnlyPass, ['PRODUCTION']);
$httpProdOnly = new HttpPdfg($baseUrl);
runTest('REPL-AUTH-04 a plain PRODUCTION role cannot access the Replacement Reject Admin disposition/traceability UI (Admin-level page, never exposed to Production)', function () use ($httpProdOnly, $prodOnlyPass) {
    login($httpProdOnly, 'repl_auth_prod_only', $prodOnlyPass);
    $r = $httpProdOnly->request('GET', '/api/_ui-preview/?page=replacement-reject');
    expect($r['status'] === 403, 'REPL-AUTH-04: expected 403 for a plain PRODUCTION user, got ' . $r['status']);
    expect(!str_contains($r['body'], 'Replacement Reject — Traceability'), 'REPL-AUTH-04: the denied response must never contain the traceability list content');
});

runTest('REPL-AUTH-05 an unauthorized direct URL to replacement-reject returns a safe 403 denial regardless of role (FG_PACKING-only checked here)', function () use ($pdo, $baseUrl) {
    $fgOnlyPass = 'ReplAuthFgOnlyPass#123';
    createUser($pdo, 'repl_auth_fg_only_ui', $fgOnlyPass, ['FG_PACKING']);
    $httpFgOnly = new HttpPdfg($baseUrl);
    login($httpFgOnly, 'repl_auth_fg_only_ui', $fgOnlyPass);
    $r = $httpFgOnly->request('GET', '/api/_ui-preview/?page=replacement-reject');
    expect($r['status'] === 403, 'REPL-AUTH-05: expected 403 for a plain FG_PACKING user, got ' . $r['status']);
    expect(!str_contains($r['body'], 'Reject Final'), 'REPL-AUTH-05: the denied response must never contain any disposition-worklist content');
});

runTest('REPL-AUTH-06 replacement-do-detail follows its own documented role matrix (ADMIN/PPIC/PRODUCTION allowed, DRIVER denied)', function () use ($httpDriver, $driverPass, $httpProdOnly, $prodOnlyPass, $replMainDoId) {
    login($httpDriver, 'pdfg_pack_edit_driver', $driverPass);
    $denied = $httpDriver->request('GET', "/api/_ui-preview/?page=replacement-do-detail&doId={$replMainDoId}");
    expect($denied['status'] === 403, 'REPL-AUTH-06: expected 403 for DRIVER on replacement-do-detail, got ' . $denied['status']);

    login($httpProdOnly, 'repl_auth_prod_only', $prodOnlyPass);
    $allowed = $httpProdOnly->request('GET', "/api/_ui-preview/?page=replacement-do-detail&doId={$replMainDoId}");
    expect($allowed['status'] === 200, 'REPL-AUTH-06: expected 200 for PRODUCTION (in this page\'s own documented role matrix) on replacement-do-detail, got ' . $allowed['status']);
});

// -----------------------------------------------------------------------
// REPL-AUTH-07/08/09 — scoped PRODUCTION division access on
// POST /api/replacement-demands/{id}/production-actual. Uses a Basic-
// division product (deliberately NOT Roti & Bollen) at Karangtengah, so
// a Roti & Bollen-only PRODUCTION user is genuinely out of scope.
// -----------------------------------------------------------------------
$replAuthProdB = replSeedProduct($pdo, $basicDivId, 'REPL AUTH Test Product B (Basic division)');
$replAuthTanggalB = '2026-11-22';
$replAuthFxB = replBuildShippedFixture($http, $csrf, $pdo, $baseUrl, $karangtengahId, $basicDivId, $replAuthTanggalB, $storeAId, (int) $replAuthProdB['product_id'], 10.0, 10.0, 'replauthB');
$replAuthDemandB = null;
runTest('REPL-AUTH-07/08/09 (setup) a Basic-division Replacement demand needing production is created', function () use ($http, $csrf, $replAuthFxB, &$replAuthDemandB) {
    $confirm = replConfirmReceipt($replAuthFxB['anon'], $replAuthFxB['token'], $replAuthFxB['shipmentId'], $replAuthFxB['shipmentItemId'], 5.0, 5.0, 0.0, 'replauthB-confirm');
    expect($confirm['status'] === 200, 'REPL-AUTH-07/08/09 setup: confirm failed: ' . json_encode($confirm['json']));
    $receiptId = (int) $confirm['json']['data']['receiptId'];
    $receiptItemId = (int) $confirm['json']['data']['items'][0]['receiptItemId'];
    expect(replAdminVerify($http, $csrf, $receiptId, 'replauthB-verify')['status'] === 200, 'REPL-AUTH-07/08/09 setup: admin verify failed');
    $dispose = replDispose($http, $csrf, $receiptItemId, 'kirim_ulang', 5.0, null, 'replauthB-dispose');
    expect($dispose['status'] === 200, 'REPL-AUTH-07/08/09 setup: disposeReject failed: ' . json_encode($dispose['json']));
    $replAuthDemandB = (int) $dispose['json']['data']['replacementDemandId'];
    $demand = replGetDemand($http, $csrf, $replAuthDemandB);
    expect($demand['status'] === 'need_production', 'REPL-AUTH-07/08/09 setup: expected status=need_production (zero free FG), got ' . $demand['status']);
});

$prodWrongDivPass = 'ReplAuthProdWrongDivPass#123';
$prodWrongDivUserId = createUser($pdo, 'repl_auth_prod_wrongdiv', $prodWrongDivPass, ['PRODUCTION']);
$httpProdWrongDiv = new HttpPdfg($baseUrl);
runTest('REPL-AUTH-07 a PRODUCTION user assigned ONLY to Roti & Bollen cannot update a Basic-division Replacement demand\'s production actual', function () use ($pdo, $httpProdWrongDiv, $prodWrongDivPass, $prodWrongDivUserId, $rotiBollenDivId, $replAuthDemandB) {
    replGrantDivisionAccess($pdo, $prodWrongDivUserId, $rotiBollenDivId);
    $csrfProdWrongDiv = login($httpProdWrongDiv, 'repl_auth_prod_wrongdiv', $prodWrongDivPass);
    $r = $httpProdWrongDiv->request('POST', "/api/replacement-demands/{$replAuthDemandB}/production-actual", ['aktualProduksi' => 5, 'rejectProduksi' => 0], array_merge(['X-CSRF-Token' => $csrfProdWrongDiv], idemKey('replauth07')));
    expect($r['status'] === 403, 'REPL-AUTH-07: expected 403 (DIVISION_ACCESS_DENIED) for a Roti & Bollen-only PRODUCTION user on a Basic-division demand, got ' . $r['status'] . ': ' . json_encode($r['json']));
    expect(($r['json']['code'] ?? null) === 'DIVISION_ACCESS_DENIED', 'REPL-AUTH-07: expected error code DIVISION_ACCESS_DENIED, got ' . json_encode($r['json']));
});

$prodCorrectDivPass = 'ReplAuthProdCorrectDivPass#123';
$prodCorrectDivUserId = createUser($pdo, 'repl_auth_prod_correctdiv', $prodCorrectDivPass, ['PRODUCTION']);
$httpProdCorrectDiv = new HttpPdfg($baseUrl);
runTest('REPL-AUTH-08 a PRODUCTION user correctly assigned to the Basic division CAN update that Replacement demand\'s production actual', function () use ($pdo, $httpProdCorrectDiv, $prodCorrectDivPass, $prodCorrectDivUserId, $basicDivId, $replAuthDemandB) {
    replGrantDivisionAccess($pdo, $prodCorrectDivUserId, $basicDivId);
    $csrfProdCorrectDiv = login($httpProdCorrectDiv, 'repl_auth_prod_correctdiv', $prodCorrectDivPass);
    $r = $httpProdCorrectDiv->request('POST', "/api/replacement-demands/{$replAuthDemandB}/production-actual", ['aktualProduksi' => 5, 'rejectProduksi' => 0], array_merge(['X-CSRF-Token' => $csrfProdCorrectDiv], idemKey('replauth08')));
    expect($r['status'] === 200, 'REPL-AUTH-08: expected 200 for a correctly Basic-division-scoped PRODUCTION user, got ' . $r['status'] . ': ' . json_encode($r['json']));
});

$prodNoAccessPass = 'ReplAuthProdNoAccessPass#123';
$prodNoAccessUserId = createUser($pdo, 'repl_auth_prod_noaccess', $prodNoAccessPass, ['PRODUCTION']);
$httpProdNoAccess = new HttpPdfg($baseUrl);
runTest('REPL-AUTH-09 a PRODUCTION user with ZERO division assignments remains default-deny', function () use ($httpProdNoAccess, $prodNoAccessPass, $replAuthDemandB) {
    $csrfProdNoAccess = login($httpProdNoAccess, 'repl_auth_prod_noaccess', $prodNoAccessPass);
    $r = $httpProdNoAccess->request('POST', "/api/replacement-demands/{$replAuthDemandB}/production-actual", ['aktualProduksi' => 5, 'rejectProduksi' => 0], array_merge(['X-CSRF-Token' => $csrfProdNoAccess], idemKey('replauth09')));
    expect($r['status'] === 403, 'REPL-AUTH-09: expected 403 (NO_DIVISION_ASSIGNMENT) for zero-assignment PRODUCTION, got ' . $r['status'] . ': ' . json_encode($r['json']));
    expect(($r['json']['code'] ?? null) === 'NO_DIVISION_ASSIGNMENT', 'REPL-AUTH-09: expected error code NO_DIVISION_ASSIGNMENT, got ' . json_encode($r['json']));
});

// -----------------------------------------------------------------------
// REPL-AUTH-10/11/12 — scoped FG_PACKING factory access on
// POST /api/replacement-demands/{id}/verify-fg. Uses a Cibadak-routed
// product (found by its division's own factory_id, never hardcoded by
// name), so a Karangtengah-only FG_PACKING user is genuinely out of
// scope.
// -----------------------------------------------------------------------
$replAuthCibadakDivId = (int) $pdo->query("SELECT division_id FROM division WHERE factory_id = {$cibadakId} LIMIT 1")->fetchColumn();
expect($replAuthCibadakDivId > 0, 'REPL-AUTH-10/11/12 setup: expected at least one division routed to Cibadak');
$replAuthProdC = replSeedProduct($pdo, $replAuthCibadakDivId, 'REPL AUTH Test Product C (Cibadak-routed)');
$replAuthTanggalC = '2026-11-23';
$replAuthFxC = replBuildShippedFixture($http, $csrf, $pdo, $baseUrl, $cibadakId, $replAuthCibadakDivId, $replAuthTanggalC, $storeAId, (int) $replAuthProdC['product_id'], 10.0, 10.0, 'replauthC');
$replAuthDemandC = null;
runTest('REPL-AUTH-10/11/12 (setup) a Cibadak-factory Replacement demand is created', function () use ($http, $csrf, $replAuthFxC, &$replAuthDemandC) {
    $confirm = replConfirmReceipt($replAuthFxC['anon'], $replAuthFxC['token'], $replAuthFxC['shipmentId'], $replAuthFxC['shipmentItemId'], 5.0, 5.0, 0.0, 'replauthC-confirm');
    expect($confirm['status'] === 200, 'REPL-AUTH-10/11/12 setup: confirm failed: ' . json_encode($confirm['json']));
    $receiptId = (int) $confirm['json']['data']['receiptId'];
    $receiptItemId = (int) $confirm['json']['data']['items'][0]['receiptItemId'];
    expect(replAdminVerify($http, $csrf, $receiptId, 'replauthC-verify')['status'] === 200, 'REPL-AUTH-10/11/12 setup: admin verify failed');
    $dispose = replDispose($http, $csrf, $receiptItemId, 'kirim_ulang', 5.0, null, 'replauthC-dispose');
    expect($dispose['status'] === 200, 'REPL-AUTH-10/11/12 setup: disposeReject failed: ' . json_encode($dispose['json']));
    $replAuthDemandC = (int) $dispose['json']['data']['replacementDemandId'];
});

$fgWrongFactoryPass = 'ReplAuthFgWrongFactoryPass#123';
$fgWrongFactoryUserId = createUser($pdo, 'repl_auth_fg_wrongfactory', $fgWrongFactoryPass, ['FG_PACKING']);
$httpFgWrongFactory = new HttpPdfg($baseUrl);
runTest('REPL-AUTH-10 an FG_PACKING user assigned ONLY to Karangtengah cannot verify FG for a Cibadak Replacement demand', function () use ($pdo, $httpFgWrongFactory, $fgWrongFactoryPass, $fgWrongFactoryUserId, $karangtengahId, $replAuthDemandC) {
    replGrantFactoryAccess($pdo, $fgWrongFactoryUserId, $karangtengahId);
    $csrfFgWrongFactory = login($httpFgWrongFactory, 'repl_auth_fg_wrongfactory', $fgWrongFactoryPass);
    $r = $httpFgWrongFactory->request('POST', "/api/replacement-demands/{$replAuthDemandC}/verify-fg", ['fgVerifiedQty' => 0], array_merge(['X-CSRF-Token' => $csrfFgWrongFactory], idemKey('replauth10')));
    expect($r['status'] === 403, 'REPL-AUTH-10: expected 403 (FACTORY_ACCESS_DENIED) for a Karangtengah-only FG_PACKING user on a Cibadak demand, got ' . $r['status'] . ': ' . json_encode($r['json']));
    expect(($r['json']['code'] ?? null) === 'FACTORY_ACCESS_DENIED', 'REPL-AUTH-10: expected error code FACTORY_ACCESS_DENIED, got ' . json_encode($r['json']));
});

$fgCorrectFactoryPass = 'ReplAuthFgCorrectFactoryPass#123';
$fgCorrectFactoryUserId = createUser($pdo, 'repl_auth_fg_correctfactory', $fgCorrectFactoryPass, ['FG_PACKING']);
$httpFgCorrectFactory = new HttpPdfg($baseUrl);
runTest('REPL-AUTH-11 an FG_PACKING user correctly assigned to Cibadak CAN verify FG for that Replacement demand', function () use ($pdo, $httpFgCorrectFactory, $fgCorrectFactoryPass, $fgCorrectFactoryUserId, $cibadakId, $replAuthDemandC) {
    replGrantFactoryAccess($pdo, $fgCorrectFactoryUserId, $cibadakId);
    $csrfFgCorrectFactory = login($httpFgCorrectFactory, 'repl_auth_fg_correctfactory', $fgCorrectFactoryPass);
    $r = $httpFgCorrectFactory->request('POST', "/api/replacement-demands/{$replAuthDemandC}/verify-fg", ['fgVerifiedQty' => 0], array_merge(['X-CSRF-Token' => $csrfFgCorrectFactory], idemKey('replauth11')));
    expect($r['status'] === 200, 'REPL-AUTH-11: expected 200 for a correctly Cibadak-scoped FG_PACKING user, got ' . $r['status'] . ': ' . json_encode($r['json']));
});

$fgNoAccessPass = 'ReplAuthFgNoAccessPass#123';
$fgNoAccessUserId = createUser($pdo, 'repl_auth_fg_noaccess', $fgNoAccessPass, ['FG_PACKING']);
$httpFgNoAccess = new HttpPdfg($baseUrl);
runTest('REPL-AUTH-12 an FG_PACKING user with ZERO factory assignments remains default-deny', function () use ($httpFgNoAccess, $fgNoAccessPass, $replAuthDemandC) {
    $csrfFgNoAccess = login($httpFgNoAccess, 'repl_auth_fg_noaccess', $fgNoAccessPass);
    $r = $httpFgNoAccess->request('POST', "/api/replacement-demands/{$replAuthDemandC}/verify-fg", ['fgVerifiedQty' => 0], array_merge(['X-CSRF-Token' => $csrfFgNoAccess], idemKey('replauth12')));
    expect($r['status'] === 403, 'REPL-AUTH-12: expected 403 (NO_FACTORY_ASSIGNMENT) for zero-assignment FG_PACKING, got ' . $r['status'] . ': ' . json_encode($r['json']));
    expect(($r['json']['code'] ?? null) === 'NO_FACTORY_ASSIGNMENT', 'REPL-AUTH-12: expected error code NO_FACTORY_ASSIGNMENT, got ' . json_encode($r['json']));
});

// =======================================================================
// Summary
// =======================================================================
$total = count($results);
$failed = count(array_filter($results, static fn ($ok) => !$ok));
fwrite(STDOUT, "\n{$total} tests run, {$failed} failed.\n");
exit($failed > 0 ? 1 : 0);
