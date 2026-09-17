<?php

declare(strict_types=1);

/**
 * Phase 4 fast-track FG/Packing integration test suite (P4-01 .. P4-32).
 * Run via api/tests/run-phase4-fg-packing.sh, which stands up a disposable
 * local MariaDB, bootstraps realistic post-Phase-3 master data (same
 * _phase2_bootstrap_master.php Phase 2/3 already use), applies migrations
 * 0001-0005 for real through the existing api/_upgrade/ wizard, then
 * drives the real FG JSON API end to end against a live `php -S` server.
 *
 * PO fixtures are seeded directly into po_batch/po_item (never through the
 * parser — same rationale as Phase3ProductionTest.php). Production
 * fixtures, by contrast, are driven through the REAL Production JSON API
 * (create/patch/submit/reopen) — Production is the direct upstream
 * dependency this suite is testing FG's eligibility rules against, so it
 * must be exercised for real, not shortcut.
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8100';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'p4_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpP4
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'p4cookies');
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

function idemKey(string $tag): array
{
    return ['Idempotency-Key' => $tag . '-' . uniqid('', true)];
}

/** Seeds po_batch/po_item directly (never through the parser). Bumps po_batch.version on repeat calls. */
function seedPo(PDO $pdo, string $tanggal, int $factoryId, array $items): int
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
    foreach ($items as $productId => $d) {
        $upsert->execute([$batchId, $productId, $d['kategori'] ?? null, $d['poAwal'] ?? 0.0, $d['poRevisi'] ?? 0.0, $d['pb'] ?? 0.0]);
    }
    return $batchId;
}

/** Drives the REAL Production API end to end: create draft -> patch actual -> submit. @return array{productionRunId:int,version:int} */
function createSubmittedProduction(HttpP4 $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $productId, float $poTarget, float $actual): array
{
    seedPo($pdo, $tanggal, $factoryId, [$productId => ['poAwal' => $poTarget, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $divisionId], array_merge(['X-CSRF-Token' => $csrf], idemKey('prod-create')));
    expect($create['status'] === 200, 'production create failed: ' . json_encode($create['json']));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $productId, 'actualQty' => $actual]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('prod-patch')));
    expect($save['status'] === 200, 'production patch failed: ' . json_encode($save['json']));
    $v = $save['json']['data']['version'];
    $submit = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('prod-submit')));
    expect($submit['status'] === 200, 'production submit failed: ' . json_encode($submit['json']));
    return ['productionRunId' => (int) $runId, 'version' => (int) $submit['json']['data']['version']];
}

/** Leaves the source production_run as a fresh, un-submitted DRAFT (task's "not eligible" case). @return int productionRunId */
function createDraftProduction(HttpP4 $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $productId, float $poTarget): int
{
    seedPo($pdo, $tanggal, $factoryId, [$productId => ['poAwal' => $poTarget, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $divisionId], array_merge(['X-CSRF-Token' => $csrf], idemKey('prod-draft')));
    expect($create['status'] === 200, 'production draft create failed: ' . json_encode($create['json']));
    return (int) $create['json']['data']['productionRunId'];
}

function reopenProduction(HttpP4 $http, string $csrf, int $runId, int $expectedVersion, string $reason): array
{
    $r = $http->request('POST', "/api/production/{$runId}/reopen", ['expectedVersion' => $expectedVersion, 'reason' => $reason], array_merge(['X-CSRF-Token' => $csrf], idemKey('prod-reopen')));
    expect($r['status'] === 200, 'production reopen failed: ' . json_encode($r['json']));
    return $r['json']['data'];
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

function login(HttpP4 $http, string $username, string $password): string
{
    $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    $me = $http->request('GET', '/api/auth/me');
    $csrf = $me['json']['data']['csrfToken'] ?? null;
    expect($csrf !== null, "expected a csrf token after login as {$username}");
    return $csrf;
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

$http = new HttpP4($baseUrl);
$csrf = login($http, $adminUser, $adminPass);

// ---------------------------------------------------------------------
// P4-00 (setup, not part of the required 32): apply migration 0005.
// ---------------------------------------------------------------------
runTest('P4-00 migration 0005 applied via the existing api/_upgrade/ wizard', function () use ($http, $baseUrl) {
    $page = $http->request('GET', '/_upgrade/');
    expect($page['status'] === 200, "expected 200 from _upgrade/, got {$page['status']}");
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $page['body'], $m)) {
        throw new RuntimeException('expected to find a csrf token on the upgrade page');
    }
    expect(str_contains($page['body'], '0005_fg_packing_phase4.php'), 'expected migration 0005 to be listed as pending');

    $refl = new ReflectionProperty(HttpP4::class, 'cookieJar');
    $refl->setAccessible(true);
    $jar = $refl->getValue($http);

    $ch = curl_init(rtrim($baseUrl, '/') . '/_upgrade/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['csrf' => $m[1], 'action' => 'apply', 'confirm' => '1']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    expect(str_contains((string) $body, 'Migrasi berhasil diterapkan') || str_contains((string) $body, 'Tidak ada yang perlu diterapkan'),
        'expected migration 0005 to apply successfully: ' . substr((string) $body, 0, 500));
});

// ---------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------
$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
$cibadakId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Cibadak'")->fetchColumn();
expect($karangtengahId > 0 && $cibadakId > 0, 'expected both factories seeded');

$rotiBollenDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
$basicDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Basic'")->fetchColumn();
$boluDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Bolu'")->fetchColumn();
expect($rotiBollenDivId > 0 && $basicDivId > 0 && $boluDivId > 0, 'expected test divisions seeded');

function productsInDivision(PDO $pdo, int $divisionId, int $limit): array
{
    $stmt = $pdo->prepare('SELECT product_id, name FROM product WHERE division_id = ? ORDER BY product_id LIMIT ' . (int) $limit);
    $stmt->execute([$divisionId]);
    return $stmt->fetchAll();
}

$rotiProducts = productsInDivision($pdo, $rotiBollenDivId, 11);
$basicProducts = productsInDivision($pdo, $basicDivId, 2);
$boluProducts = productsInDivision($pdo, $boluDivId, 1);
expect(count($rotiProducts) >= 11 && count($basicProducts) >= 2 && count($boluProducts) >= 1, 'expected enough katalog products per division after bootstrap');
[$prodA, $prodB, $prodC, $prodD, $prodE, $prodF, $prodG, $prodH, $bigBanana, $prodVar1, $prodVar2] = $rotiProducts;
$prodBasic = $basicProducts[0];
$prodBasic2 = $basicProducts[1];
$prodBolu = $boluProducts[0];
// "BIG BANANA CHOCOCHEESE" (the task's own worked UAT example name) is not
// present in the real Phase 1 katalog import — substituting a real,
// otherwise-untouched katalog product for the identical numeric scenario
// (target=5, actual=4, FG 3 -> 4), documented here rather than silently
// assumed. Kept out of every other test's fixture pool (prodA..prodH) so
// its stock_ledger history stays exclusively P4-31/32's own.

$ppicUserId = createUser($pdo, 'p4_ppic', 'PpicPass#123', ['PPIC']);
$httpPpic = new HttpP4($baseUrl);
$csrfPpic = login($httpPpic, 'p4_ppic', 'PpicPass#123');

function locationIdForFactory(PDO $pdo, int $factoryId): int
{
    $stmt = $pdo->prepare('SELECT location_id FROM location WHERE factory_id = ?');
    $stmt->execute([$factoryId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : 0;
}

function ledgerRows(PDO $pdo, int $productId, int $locationId): array
{
    $stmt = $pdo->prepare('SELECT * FROM stock_ledger WHERE product_id = ? AND location_id = ? ORDER BY stock_ledger_id');
    $stmt->execute([$productId, $locationId]);
    return $stmt->fetchAll();
}

function balanceRow(PDO $pdo, int $productId, int $locationId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM stock_balance WHERE product_id = ? AND location_id = ?');
    $stmt->execute([$productId, $locationId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------------
runTest('P4-01 only SUBMITTED Production is eligible as an FG source', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-03-05';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodA['product_id'], 10.0, 7.0);
    $r = $http->request('GET', "/api/fg/target?date={$tanggal}&factoryId={$karangtengahId}", null, ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, 'fg target fetch failed: ' . json_encode($r['json']));
    $item = current(array_filter($r['json']['data']['items'], fn ($i) => $i['productId'] === $prodA['product_id']));
    expect($item !== false && (float) $item['actual'] === 7.0, 'expected SUBMITTED production actual 7 to be eligible');
});

runTest('P4-02 reopened/draft Production is NOT eligible as a new FG source', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $basicDivId, $prodB, $prodBasic2) {
    $tanggal = '2026-03-06';
    // prodB (Roti & Bollen division's own run): left as a draft, never submitted.
    createDraftProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodB['product_id'], 10.0);
    // prodBasic2 (a SEPARATE division/run, so reopening it can never touch
    // prodB's still-draft run): submitted, then reopened.
    $run = createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $basicDivId, $tanggal, $prodBasic2['product_id'], 10.0, 6.0);
    reopenProduction($http, $csrf, $run['productionRunId'], $run['version'], 'testing not-eligible-while-reopened');

    $r = $http->request('GET', "/api/fg/target?date={$tanggal}&factoryId={$karangtengahId}", null, ['X-CSRF-Token' => $csrf]);
    $ids = array_column($r['json']['data']['items'], 'productId');
    expect(!in_array($prodB['product_id'], $ids, true), 'expected draft production NOT eligible');
    expect(!in_array($prodBasic2['product_id'], $ids, true), 'expected reopened production NOT eligible');
});

runTest('P4-03 FG draft source linkage (fg_batch_source) is correct', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $basicDivId, $prodD, $prodBasic) {
    $tanggal = '2026-03-07';
    $run1 = createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodD['product_id'], 10.0, 5.0);
    $run2 = createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $basicDivId, $tanggal, $prodBasic['product_id'], 8.0, 8.0);

    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-03')));
    expect($create['status'] === 200, 'fg create failed: ' . json_encode($create['json']));
    $batchId = $create['json']['data']['fgBatchId'];

    $stmt = $pdo->prepare('SELECT production_run_id, source_version FROM fg_batch_source WHERE fg_batch_id = ? ORDER BY production_run_id');
    $stmt->execute([$batchId]);
    $rows = $stmt->fetchAll();
    $byRunId = [];
    foreach ($rows as $r) { $byRunId[(int) $r['production_run_id']] = (int) $r['source_version']; }
    expect(isset($byRunId[$run1['productionRunId']]) && $byRunId[$run1['productionRunId']] === $run1['version'], 'expected run1 linked with correct source_version');
    expect(isset($byRunId[$run2['productionRunId']]) && $byRunId[$run2['productionRunId']] === $run2['version'], 'expected run2 linked with correct source_version');
});

runTest('P4-04 production actual snapshot is captured on FG draft creation', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodE) {
    $tanggal = '2026-03-08';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodE['product_id'], 10.0, 6.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-04')));
    $item = current(array_filter($create['json']['data']['items'], fn ($i) => $i['productId'] === $prodE['product_id']));
    expect($item !== false && (float) $item['productionActualSnapshot'] === 6.0, 'expected snapshot 6 captured, got ' . json_encode($item));
});

runTest('P4-05 FG Verified save uses snapshot semantics (3 -> 2 stays 2)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodF) {
    $tanggal = '2026-03-09';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodF['product_id'], 10.0, 10.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-05')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];

    $save1 = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodF['product_id'], 'fgVerified' => 3, 'packed' => 0]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-05b')));
    expect($save1['status'] === 200, 'save1 failed: ' . json_encode($save1['json']));
    $v = $save1['json']['data']['version'];
    $save2 = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodF['product_id'], 'fgVerified' => 2, 'packed' => 0]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-05c')));
    expect($save2['status'] === 200, 'save2 failed: ' . json_encode($save2['json']));
    $item = current(array_filter($save2['json']['data']['items'], fn ($i) => $i['productId'] === $prodF['product_id']));
    expect((float) $item['fgVerified'] === 2.0, 'expected FG verified REPLACED to 2, not accumulated, got ' . $item['fgVerified']);
});

runTest('P4-06 Packing save uses snapshot semantics (2 -> 1 stays 1)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodG) {
    $tanggal = '2026-03-10';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodG['product_id'], 10.0, 10.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-06')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];

    $save1 = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodG['product_id'], 'fgVerified' => 5, 'packed' => 2]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-06b')));
    $v = $save1['json']['data']['version'];
    $save2 = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodG['product_id'], 'fgVerified' => 5, 'packed' => 1]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-06c')));
    $item = current(array_filter($save2['json']['data']['items'], fn ($i) => $i['productId'] === $prodG['product_id']));
    expect((float) $item['packed'] === 1.0, 'expected packed REPLACED to 1, not accumulated, got ' . $item['packed']);
});

runTest('P4-07 packed > FG Verified is blocked', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-03-11';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodA['product_id'], 10.0, 10.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-07')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $r = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodA['product_id'], 'fgVerified' => 3, 'packed' => 5]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-07b')));
    expect($r['status'] === 400 && $r['json']['code'] === 'PACKED_EXCEEDS_VERIFIED', 'expected 400 PACKED_EXCEEDS_VERIFIED: ' . json_encode($r['json']));
});

runTest('P4-08 FG Verified > Production actual is blocked', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-03-12';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodB['product_id'], 10.0, 4.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-08')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $r = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'fgVerified' => 5, 'packed' => 0]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-08b')));
    expect($r['status'] === 400 && $r['json']['code'] === 'FG_EXCEEDS_PRODUCTION', 'expected 400 FG_EXCEEDS_PRODUCTION: ' . json_encode($r['json']));
});

runTest('P4-09 variance is computed correctly (production_actual - FG Verified)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodC) {
    $tanggal = '2026-03-13';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodC['product_id'], 10.0, 5.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-09')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $r = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodC['product_id'], 'fgVerified' => 3, 'packed' => 0]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-09b')));
    $item = current(array_filter($r['json']['data']['items'], fn ($i) => $i['productId'] === $prodC['product_id']));
    expect((float) $item['variance'] === 2.0, 'expected variance +2 (5-3, production not yet verified into FG), got ' . $item['variance']);
});

// ---------------------------------------------------------------------
// P4-VAR01..04 — variance sign fix (variance_fg = production_actual -
// fg_verified). Root cause was a reversed subtraction in
// FgService::buildItemDto (backend calculation, not just display) — see
// commit message for the full audit.
// ---------------------------------------------------------------------
runTest('P4-VAR01 production 4, FG 3 => variance +1', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodVar1) {
    $tanggal = '2026-04-02';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodVar1['product_id'], 5.0, 4.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-var01')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $r = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodVar1['product_id'], 'fgVerified' => 3, 'packed' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-var01b')));
    expect($r['status'] === 200, 'save failed: ' . json_encode($r['json']));
    $item = current(array_filter($r['json']['data']['items'], fn ($i) => $i['productId'] === $prodVar1['product_id']));
    expect((float) $item['variance'] === 1.0, 'expected variance +1 (production 4 - FG 3), got ' . $item['variance']);
});

runTest('P4-VAR02 production 4, FG 4 => variance 0', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodVar2) {
    $tanggal = '2026-04-03';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodVar2['product_id'], 5.0, 4.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-var02')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $r = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodVar2['product_id'], 'fgVerified' => 4, 'packed' => 4]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-var02b')));
    expect($r['status'] === 200, 'save failed: ' . json_encode($r['json']));
    $item = current(array_filter($r['json']['data']['items'], fn ($i) => $i['productId'] === $prodVar2['product_id']));
    expect((float) $item['variance'] === 0.0, 'expected variance 0 (production 4 - FG 4), got ' . $item['variance']);
});

runTest('P4-VAR03 save draft still creates no stock ledger movement', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodVar1) {
    $tanggal = '2026-04-04';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodVar1['product_id'], 5.0, 4.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-var03')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $r = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodVar1['product_id'], 'fgVerified' => 3, 'packed' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-var03b')));
    expect($r['status'] === 200, 'save failed: ' . json_encode($r['json']));

    $locId = locationIdForFactory($pdo, $karangtengahId);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM stock_ledger WHERE product_id = ? AND location_id = ? AND event_date = ?');
    $stmt->execute([$prodVar1['product_id'], $locId, $tanggal]);
    expect((int) $stmt->fetchColumn() === 0, 'expected zero stock_ledger rows for this date after a draft-only save (variance fix must not touch stock)');
});

runTest('P4-VAR04 packed value remains unchanged when only FG Verified is edited', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodVar2) {
    $tanggal = '2026-04-05';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodVar2['product_id'], 8.0, 6.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-var04')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save1 = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodVar2['product_id'], 'fgVerified' => 3, 'packed' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-var04b')));
    $item1 = current(array_filter($save1['json']['data']['items'], fn ($i) => $i['productId'] === $prodVar2['product_id']));
    expect((float) $item1['packed'] === 3.0, 'expected packed 3 after first save');
    $v = $save1['json']['data']['version'];

    // Edit FG Verified only (packed resent at the same value, 3, not omitted
    // — the API has no partial-field update, so "unchanged" here means the
    // operator kept the same packed number while the variance recalculates).
    $save2 = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodVar2['product_id'], 'fgVerified' => 4, 'packed' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-var04c')));
    expect($save2['status'] === 200, 'second save failed: ' . json_encode($save2['json']));
    $item2 = current(array_filter($save2['json']['data']['items'], fn ($i) => $i['productId'] === $prodVar2['product_id']));
    expect((float) $item2['packed'] === 3.0, 'expected packed to remain 3 (unaffected by the FG Verified edit / variance fix), got ' . $item2['packed']);
    expect((float) $item2['variance'] === 2.0, 'expected variance to recompute to +2 (production 6 - FG 4), got ' . $item2['variance']);
});

runTest('P4-10 draft save creates NO stock movement', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodD) {
    $tanggal = '2026-03-14';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodD['product_id'], 10.0, 5.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-10')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodD['product_id'], 'fgVerified' => 3, 'packed' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-10b')));

    $locId = locationIdForFactory($pdo, $karangtengahId);
    expect(ledgerRows($pdo, $prodD['product_id'], $locId) === [], 'expected zero stock_ledger rows after a draft-only save');
});

runTest('P4-11 first submit posts FG stock exactly once', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodE) {
    $tanggal = '2026-03-15';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodE['product_id'], 10.0, 5.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-11')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodE['product_id'], 'fgVerified' => 3, 'packed' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-11b')));
    $v = $save['json']['data']['version'];
    $submit = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-11c')));
    expect($submit['status'] === 200, 'submit failed: ' . json_encode($submit['json']));

    $locId = locationIdForFactory($pdo, $karangtengahId);
    $rows = ledgerRows($pdo, $prodE['product_id'], $locId);
    expect(count($rows) === 1, 'expected exactly 1 stock_ledger row, got ' . count($rows));
    expect((float) $rows[0]['qty_delta'] === 3.0, 'expected qty_delta +3, got ' . $rows[0]['qty_delta']);
    expect($rows[0]['event_type'] === 'production_in' && $rows[0]['source_type'] === 'fg_item', 'expected event_type=production_in, source_type=fg_item');
});

runTest('P4-12 duplicate retry (Idempotency-Key replay) does not double post', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodF) {
    $tanggal = '2026-03-16';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodF['product_id'], 10.0, 5.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-12')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodF['product_id'], 'fgVerified' => 3, 'packed' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-12b')));
    $v = $save['json']['data']['version'];

    $key = 'p4-12-submit-' . uniqid('', true);
    $submit1 = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => $key]);
    $submit2 = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => $key]);
    expect($submit1['status'] === 200 && $submit2['status'] === 200, 'expected both replayed submits to return 200');
    expect($submit1['json'] === $submit2['json'], 'expected the replayed response to be byte-identical');

    $locId = locationIdForFactory($pdo, $karangtengahId);
    expect(count(ledgerRows($pdo, $prodF['product_id'], $locId)) === 1, 'expected exactly 1 stock_ledger row despite the replayed retry');
});

runTest('P4-13 available stock is correct after submit', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodG) {
    $tanggal = '2026-03-17';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodG['product_id'], 10.0, 6.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-13')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodG['product_id'], 'fgVerified' => 6, 'packed' => 5]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-13b')));
    $v = $save['json']['data']['version'];
    $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-13c')));

    $avail = $http->request('GET', "/api/fg/availability?factoryId={$karangtengahId}&productId={$prodG['product_id']}", null, ['X-CSRF-Token' => $csrf]);
    expect((float) $avail['json']['data']['available'] === 5.0, 'expected available 5, got ' . json_encode($avail['json']));
});

runTest('P4-14 reopen requires a non-blank reason', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodH) {
    $tanggal = '2026-03-18';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodH['product_id'], 10.0, 5.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-14')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodH['product_id'], 'fgVerified' => 3, 'packed' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-14b')));
    $v = $save['json']['data']['version'];
    $submit = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-14c')));
    $v = $submit['json']['data']['version'];

    $r = $http->request('POST', "/api/fg/{$batchId}/reopen", ['expectedVersion' => $v, 'reason' => ''], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-14d')));
    expect($r['status'] === 400 && $r['json']['code'] === 'REASON_REQUIRED', 'expected 400 REASON_REQUIRED: ' . json_encode($r['json']));
});

// ---------------------------------------------------------------------
// P4-15..18: the compensating-correction cycle (reopen -> resubmit).
// ---------------------------------------------------------------------
runTest('P4-15/16/17 reopen does not change stock; resubmit 3->4 posts only +1; net becomes 4 not 7', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-03-19';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodA['product_id'], 10.0, 4.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-15')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save1 = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodA['product_id'], 'fgVerified' => 3, 'packed' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-15b')));
    $v = $save1['json']['data']['version'];
    $submit1 = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-15c')));
    $v = $submit1['json']['data']['version'];

    $locId = locationIdForFactory($pdo, $karangtengahId);
    expect(count(ledgerRows($pdo, $prodA['product_id'], $locId)) === 1, 'expected 1 ledger row after first submit');
    $balanceBeforeReopen = balanceRow($pdo, $prodA['product_id'], $locId)['qty_on_hand'];

    // P4-15: reopen itself must not touch stock.
    $reopen = $http->request('POST', "/api/fg/{$batchId}/reopen", ['expectedVersion' => $v, 'reason' => 'koreksi angka'], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-15d')));
    expect($reopen['status'] === 200, 'reopen failed: ' . json_encode($reopen['json']));
    $v = $reopen['json']['data']['version'];
    expect(count(ledgerRows($pdo, $prodA['product_id'], $locId)) === 1, 'expected still 1 ledger row right after reopen (P4-15)');
    expect((float) balanceRow($pdo, $prodA['product_id'], $locId)['qty_on_hand'] === (float) $balanceBeforeReopen, 'expected balance unchanged by reopen (P4-15)');

    // P4-16/17: correct 3 -> 4, resubmit — must post only +1, net = 4.
    $save2 = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodA['product_id'], 'fgVerified' => 4, 'packed' => 4]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-16')));
    $v = $save2['json']['data']['version'];
    $submit2 = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-16b')));
    expect($submit2['status'] === 200, 'resubmit failed: ' . json_encode($submit2['json']));

    $rows = ledgerRows($pdo, $prodA['product_id'], $locId);
    expect(count($rows) === 2, 'expected exactly 2 ledger rows after resubmit (P4-16), got ' . count($rows));
    expect((float) $rows[1]['qty_delta'] === 1.0, 'expected the second posting to be exactly +1 (3->4 correction), got ' . $rows[1]['qty_delta']);
    $balance = balanceRow($pdo, $prodA['product_id'], $locId);
    expect((float) $balance['qty_on_hand'] === 4.0, 'expected net stock 4, NOT 3+4=7 (P4-17), got ' . $balance['qty_on_hand']);
});

runTest('P4-18 downward correction posts a negative delta only', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-03-20';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodB['product_id'], 10.0, 5.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-18')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save1 = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'fgVerified' => 5, 'packed' => 5]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-18b')));
    $v = $save1['json']['data']['version'];
    $submit1 = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-18c')));
    $v = $submit1['json']['data']['version'];

    $reopen = $http->request('POST', "/api/fg/{$batchId}/reopen", ['expectedVersion' => $v, 'reason' => 'koreksi turun'], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-18d')));
    $v = $reopen['json']['data']['version'];
    $save2 = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'fgVerified' => 3, 'packed' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-18e')));
    $v = $save2['json']['data']['version'];
    $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-18f')));

    $locId = locationIdForFactory($pdo, $karangtengahId);
    $rows = ledgerRows($pdo, $prodB['product_id'], $locId);
    expect(count($rows) === 2, 'expected 2 ledger rows');
    expect((float) $rows[1]['qty_delta'] === -2.0, 'expected the correction to post -2 (5->3), got ' . $rows[1]['qty_delta']);
    expect((float) balanceRow($pdo, $prodB['product_id'], $locId)['qty_on_hand'] === 3.0, 'expected net balance 3');
});

runTest('P4-19/20 production reopened after FG submitted flags inconsistency, FG itself stays unchanged', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodC) {
    $tanggal = '2026-03-21';
    $run = createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodC['product_id'], 10.0, 4.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-19')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodC['product_id'], 'fgVerified' => 4, 'packed' => 4]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-19b')));
    $v = $save['json']['data']['version'];
    $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-19c')));

    // Production is reopened AFTER FG already submitted.
    reopenProduction($http, $csrf, $run['productionRunId'], $run['version'], 'koreksi produksi setelah FG submit');

    $show = $http->request('GET', "/api/fg/{$batchId}", null, ['X-CSRF-Token' => $csrf]);
    expect($show['json']['data']['sourceInconsistency'] === true, 'expected sourceInconsistency=true (P4-19): ' . json_encode($show['json']['data']));
    $item = current(array_filter($show['json']['data']['items'], fn ($i) => $i['productId'] === $prodC['product_id']));
    expect((float) $item['fgVerified'] === 4.0 && (float) $item['packed'] === 4.0, 'expected FG values UNCHANGED after source production reopen (P4-20)');
});

runTest('P4-21 factory isolation — FG target never mixes Karangtengah and Cibadak', function () use ($http, $csrf, $pdo, $karangtengahId, $cibadakId, $rotiBollenDivId, $boluDivId, $prodD, $prodBolu) {
    $tanggal = '2026-03-22';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodD['product_id'], 50.0, 40.0);
    createSubmittedProduction($http, $csrf, $pdo, $cibadakId, $boluDivId, $tanggal, $prodBolu['product_id'], 20.0, 15.0);

    $kt = $http->request('GET', "/api/fg/target?date={$tanggal}&factoryId={$karangtengahId}", null, ['X-CSRF-Token' => $csrf]);
    $cb = $http->request('GET', "/api/fg/target?date={$tanggal}&factoryId={$cibadakId}", null, ['X-CSRF-Token' => $csrf]);
    $ktIds = array_column($kt['json']['data']['items'], 'productId');
    $cbIds = array_column($cb['json']['data']['items'], 'productId');
    expect(in_array($prodD['product_id'], $ktIds, true) && !in_array($prodD['product_id'], $cbIds, true), 'expected Karangtengah product only in Karangtengah target');
    expect(in_array($prodBolu['product_id'], $cbIds, true) && !in_array($prodBolu['product_id'], $ktIds, true), 'expected Cibadak product only in Cibadak target');
});

runTest('P4-22 stale expectedVersion is version-conflict protected', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodE) {
    $tanggal = '2026-03-23';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodE['product_id'], 10.0, 5.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-22')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodE['product_id'], 'fgVerified' => 1, 'packed' => 0]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-22b')));

    $stale = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodE['product_id'], 'fgVerified' => 2, 'packed' => 0]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-22c')));
    expect($stale['status'] === 409 && $stale['json']['code'] === 'VERSION_CONFLICT', 'expected 409 VERSION_CONFLICT: ' . json_encode($stale['json']));
    expect(isset($stale['json']['currentVersion']), 'expected currentVersion hint in the conflict response');
});

runTest('P4-23 missing Idempotency-Key on submit is rejected', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodF) {
    $tanggal = '2026-03-24';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodF['product_id'], 10.0, 5.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-23')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $r = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], ['X-CSRF-Token' => $csrf]); // no Idempotency-Key
    expect($r['status'] === 400 && $r['json']['code'] === 'MISSING_IDEMPOTENCY_KEY', 'expected 400 MISSING_IDEMPOTENCY_KEY: ' . json_encode($r['json']));
});

runTest('P4-24 audit_log records the full lifecycle', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodG) {
    $tanggal = '2026-03-25';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodG['product_id'], 10.0, 5.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-24')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodG['product_id'], 'fgVerified' => 3, 'packed' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-24b')));
    $v = $save['json']['data']['version'];
    $submit = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-24c')));
    $v = $submit['json']['data']['version'];
    $http->request('POST', "/api/fg/{$batchId}/reopen", ['expectedVersion' => $v, 'reason' => 'audit check'], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-24d')));

    $hist = $http->request('GET', "/api/fg/history?date={$tanggal}&factoryId={$karangtengahId}", null, ['X-CSRF-Token' => $csrf]);
    $actions = array_column($hist['json']['data'], 'action');
    foreach (['fg.draft.create', 'fg.draft.edit', 'fg.submit', 'fg.reopen'] as $expectedAction) {
        expect(in_array($expectedAction, $actions, true), "expected audit_log action '{$expectedAction}' present, got " . json_encode($actions));
    }
});

runTest('P4-25 PO data is byte-identical before and after the full FG flow', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodH) {
    $tanggal = '2026-03-26';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodH['product_id'], 10.0, 5.0);
    $before = $pdo->prepare('SELECT po_awal, po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND b.factory_id = ? AND i.product_id = ?');
    $before->execute([$tanggal, $karangtengahId, $prodH['product_id']]);
    $beforeRow = $before->fetch();

    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-25')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodH['product_id'], 'fgVerified' => 5, 'packed' => 5]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-25b')));
    $v = $save['json']['data']['version'];
    $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-25c')));

    $after = $pdo->prepare('SELECT po_awal, po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND b.factory_id = ? AND i.product_id = ?');
    $after->execute([$tanggal, $karangtengahId, $prodH['product_id']]);
    expect($beforeRow === $after->fetch(), 'expected po_item unchanged by the FG flow');
});

runTest('P4-26 Production data is byte-identical before and after the full FG flow', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-03-27';
    $run = createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodA['product_id'], 10.0, 6.0);
    $before = $pdo->prepare('SELECT target, aktual, status FROM production_item WHERE production_run_id = ? AND product_id = ?');
    $before->execute([$run['productionRunId'], $prodA['product_id']]);
    $beforeRow = $before->fetch();

    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-26')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodA['product_id'], 'fgVerified' => 6, 'packed' => 6]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-26b')));
    $v = $save['json']['data']['version'];
    $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-26c')));

    $after = $pdo->prepare('SELECT target, aktual, status FROM production_item WHERE production_run_id = ? AND product_id = ?');
    $after->execute([$run['productionRunId'], $prodA['product_id']]);
    expect($beforeRow === $after->fetch(), 'expected production_item unchanged by the FG flow');
});

runTest('P4-27 no delivery_order rows were created by any FG action', function () use ($pdo) {
    expect((int) $pdo->query('SELECT COUNT(*) FROM delivery_order')->fetchColumn() === 0, 'expected zero delivery_order rows — Phase 4 must stay independent of DO/Shipment');
});

runTest('P4-28 no shipment rows were created by any FG action', function () use ($pdo) {
    expect((int) $pdo->query('SELECT COUNT(*) FROM shipment')->fetchColumn() === 0, 'expected zero shipment rows — Phase 4 must stay independent of DO/Shipment');
});

runTest('P4-29 ledger rebuild (SUM of stock_ledger) equals the maintained stock_balance cache', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-03-28';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodB['product_id'], 10.0, 7.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-29')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'fgVerified' => 7, 'packed' => 6]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-29b')));
    $v = $save['json']['data']['version'];
    $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-29c')));

    $locId = locationIdForFactory($pdo, $karangtengahId);
    $rebuilt = $pdo->prepare('SELECT COALESCE(SUM(qty_delta),0) FROM stock_ledger WHERE product_id = ? AND location_id = ?');
    $rebuilt->execute([$prodB['product_id'], $locId]);
    $rebuiltValue = (float) $rebuilt->fetchColumn();
    $cached = (float) balanceRow($pdo, $prodB['product_id'], $locId)['qty_on_hand'];
    expect($rebuiltValue === $cached, "expected rebuild ({$rebuiltValue}) to equal cached balance ({$cached})");
});

runTest('P4-30 availability API matches the direct stock_balance value', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodC) {
    $tanggal = '2026-03-29';
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $prodC['product_id'], 10.0, 8.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-30')));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodC['product_id'], 'fgVerified' => 8, 'packed' => 7]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-30b')));
    $v = $save['json']['data']['version'];
    $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-30c')));

    $locId = locationIdForFactory($pdo, $karangtengahId);
    $direct = (float) balanceRow($pdo, $prodC['product_id'], $locId)['qty_on_hand'];
    $avail = $http->request('GET', "/api/fg/availability?factoryId={$karangtengahId}&productId={$prodC['product_id']}", null, ['X-CSRF-Token' => $csrf]);
    expect((float) $avail['json']['data']['available'] === $direct, "expected API available ({$avail['json']['data']['available']}) to match direct balance ({$direct})");
    expect($avail['json']['data']['lastMovement'] !== null, 'expected lastMovement to be populated');
});

runTest('P4-31 worked UAT example: production actual 4 -> FG verified/packed 3 -> available 3', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $bigBanana) {
    $tanggal = '2026-09-05'; // the real Phase 3 UAT business date named in the task
    createSubmittedProduction($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $bigBanana['product_id'], 5.0, 4.0);
    $create = $http->request('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $karangtengahId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-31')));
    expect($create['status'] === 200, 'fg create failed: ' . json_encode($create['json']));
    $batchId = $create['json']['data']['fgBatchId'];
    $v = $create['json']['data']['version'];
    $item = current(array_filter($create['json']['data']['items'], fn ($i) => $i['productId'] === $bigBanana['product_id']));
    expect((float) $item['productionActualSnapshot'] === 4.0, 'expected production actual snapshot 4');

    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $bigBanana['product_id'], 'fgVerified' => 3, 'packed' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-31b')));
    expect($save['status'] === 200, 'save failed: ' . json_encode($save['json']));
    $preSubmitLocId = locationIdForFactory($pdo, $karangtengahId);
    expect(ledgerRows($pdo, $bigBanana['product_id'], $preSubmitLocId) === [], 'expected no stock movement before submit');
    $v = $save['json']['data']['version'];

    $submit = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-31c')));
    expect($submit['status'] === 200, 'submit failed: ' . json_encode($submit['json']));

    $avail = $http->request('GET', "/api/fg/availability?factoryId={$karangtengahId}&productId={$bigBanana['product_id']}", null, ['X-CSRF-Token' => $csrf]);
    expect((float) $avail['json']['data']['available'] === 3.0, 'expected available 3, got ' . json_encode($avail['json']));

    // Stash batchId/version for P4-32 via a global (simplest way to chain
    // these two tests, matching how the task presents them as one flow).
    $GLOBALS['p4_31_batch_id'] = $batchId;
    $GLOBALS['p4_31_version'] = $submit['json']['data']['version'];
});

runTest('P4-32 continuing the UAT example: reopen, edit 3->4, resubmit -> available becomes 4 (not 7)', function () use ($http, $csrf, $pdo, $karangtengahId, $bigBanana) {
    expect(isset($GLOBALS['p4_31_batch_id']), 'P4-32 depends on P4-31 having run first');
    $batchId = $GLOBALS['p4_31_batch_id'];
    $v = $GLOBALS['p4_31_version'];

    $reopen = $http->request('POST', "/api/fg/{$batchId}/reopen", ['expectedVersion' => $v, 'reason' => 'koreksi angka packing UAT'], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-32')));
    expect($reopen['status'] === 200, 'reopen failed: ' . json_encode($reopen['json']));
    $v = $reopen['json']['data']['version'];

    $save = $http->request('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $v, 'items' => [['productId' => $bigBanana['product_id'], 'fgVerified' => 4, 'packed' => 4]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-32b')));
    expect($save['status'] === 200, 'save failed: ' . json_encode($save['json']));
    $v = $save['json']['data']['version'];

    $submit = $http->request('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p4-32c')));
    expect($submit['status'] === 200, 'resubmit failed: ' . json_encode($submit['json']));

    $avail = $http->request('GET', "/api/fg/availability?factoryId={$karangtengahId}&productId={$bigBanana['product_id']}", null, ['X-CSRF-Token' => $csrf]);
    expect((float) $avail['json']['data']['available'] === 4.0, 'expected available 4 (NOT 3+4=7), got ' . json_encode($avail['json']));

    $locId = locationIdForFactory($pdo, $karangtengahId);
    $rows = ledgerRows($pdo, $bigBanana['product_id'], $locId);
    expect(count($rows) === 2, 'expected exactly 2 ledger rows total for this product (initial +3, correction +1)');
});

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
