<?php

declare(strict_types=1);

/**
 * Phase 3 fast-track Production/SPK actual integration test suite
 * (P3-01 .. P3-24). Run via api/tests/run-phase3-production.sh, which
 * stands up a disposable local MariaDB, bootstraps realistic post-Phase-1
 * master data (same _phase2_bootstrap_master.php Phase 2 already uses —
 * real divisions/factories/katalog products, nothing Phase-3-specific
 * about it), applies migrations 0001-0004 for real through the existing
 * api/_upgrade/ wizard, then drives the real Production JSON API end to
 * end against a live `php -S` server.
 *
 * PO fixtures here are seeded DIRECTLY into po_batch/po_item (never through
 * the Phase 2 parser) — Phase 2's own parsing correctness is already
 * covered by Phase2POTest/Phase2NewProductTest/Phase2TotalsTest, so this
 * suite isolates Phase 3's own logic (target aggregation, snapshot
 * semantics, lifecycle, concurrency) from parser concerns entirely. P3-24
 * is the one exception — it exercises the real PO wizard/parser path
 * because it is specifically testing the Phase 2 store-count UI patch.
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8098';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'p3_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpP3
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'p3cookies');
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

/**
 * Seeds po_batch/po_item DIRECTLY (never through the parser — see file
 * header). Bumps po_batch.version on every call for an existing batch,
 * exactly like PoRepository::bumpBatchUploadMeta() does on a real upload,
 * so tests can simulate "the PO was revised" realistically.
 * @param array<int,array{poAwal?:float,poRevisi?:float,pb?:float,kategori?:?string}> $items productId => values
 */
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

function login(HttpP3 $http, string $username, string $password): string
{
    $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    $me = $http->request('GET', '/api/auth/me');
    $csrf = $me['json']['data']['csrfToken'] ?? null;
    expect($csrf !== null, "expected a csrf token after login as {$username}");
    return $csrf;
}

/** Same Karangtengah layout builders Phase2POTest uses, copied here so this suite has no cross-file dependency. */
function karangtengahHeader(): array
{
    return [
        ['NO', 'KATEGORI', 'KODE', 'NAMA PRODUK', 'TSA', 'TSB', 'TOTAL', 'TSA', 'TSB', 'TOTAL', 'TSA', 'TSB', 'TOTAL'],
        ['', '', '', '', 'TSA', 'TSB', '', 'TSA', 'TSB', '', 'TSA', 'TSB', ''],
    ];
}
function karangtengahRow(string $kode, string $nama, float $awalSdrm, float $awalCkle, float $revSdrm, float $revCkle, float $pbSdrm = 0, float $pbCkle = 0): array
{
    return [1, 'TEST', $kode, $nama,
        $awalSdrm, $awalCkle, $awalSdrm + $awalCkle,
        $revSdrm, $revCkle, $revSdrm + $revCkle,
        $pbSdrm, $pbCkle, $pbSdrm + $pbCkle,
    ];
}
function buildCsvBase64(array $matrix): string
{
    $fh = fopen('php://temp', 'r+');
    foreach ($matrix as $row) {
        fputcsv($fh, $row);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return base64_encode($csv);
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

$http = new HttpP3($baseUrl);
$csrf = login($http, $adminUser, $adminPass);

// ---------------------------------------------------------------------
// P3-00 (setup, not part of the required 24): apply migration 0004 for
// real through the existing api/_upgrade/ wizard.
// ---------------------------------------------------------------------
runTest('P3-00 migration 0004 applied via the existing api/_upgrade/ wizard', function () use ($http, $baseUrl) {
    $page = $http->request('GET', '/_upgrade/');
    expect($page['status'] === 200, "expected 200 from _upgrade/, got {$page['status']}");
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $page['body'], $m)) {
        throw new RuntimeException('expected to find a csrf token on the upgrade page');
    }
    expect(str_contains($page['body'], '0004_production_phase3.php'), 'expected migration 0004 to be listed as pending');

    $refl = new ReflectionProperty(HttpP3::class, 'cookieJar');
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
        'expected migration 0004 to apply successfully: ' . substr((string) $body, 0, 500));
});

// ---------------------------------------------------------------------
// Fixtures: real factories/divisions/products from the Phase 1 bootstrap
// (never hand-crafted names — queried fresh from the actually-seeded data).
// ---------------------------------------------------------------------
$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
$cibadakId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Cibadak'")->fetchColumn();
expect($karangtengahId > 0 && $cibadakId > 0, 'expected both factories seeded');

$rotiBollenDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
$basicDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Basic'")->fetchColumn();
$boluDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Bolu'")->fetchColumn();
$fgKarangtengahDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Finishgood & Packing'")->fetchColumn();
$fgCibadakDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name LIKE 'Finishgood & Packing (Cibada%'")->fetchColumn();
expect($rotiBollenDivId > 0 && $basicDivId > 0 && $boluDivId > 0 && $fgKarangtengahDivId > 0 && $fgCibadakDivId > 0, 'expected all 5 test divisions seeded');

function productsInDivision(PDO $pdo, int $divisionId, int $limit): array
{
    $stmt = $pdo->prepare('SELECT product_id, name FROM product WHERE division_id = ? ORDER BY product_id LIMIT ' . (int) $limit);
    $stmt->execute([$divisionId]);
    return $stmt->fetchAll();
}

$rotiProducts = productsInDivision($pdo, $rotiBollenDivId, 3);
$basicProducts = productsInDivision($pdo, $basicDivId, 1);
$boluProducts = productsInDivision($pdo, $boluDivId, 2);
expect(count($rotiProducts) >= 3 && count($basicProducts) >= 1 && count($boluProducts) >= 2, 'expected enough katalog products per division after bootstrap');
[$prodA, $prodB, $prodC] = $rotiProducts;
$prodOtherDivision = $basicProducts[0];
[$prodBoluA, $prodBoluB] = $boluProducts;

$productionUserId = createUser($pdo, 'p3_production_only', 'ProdOnlyPass#123', ['PRODUCTION']);
$ppicUserId = createUser($pdo, 'p3_ppic', 'PpicPass#123', ['PPIC']);
$httpProduction = new HttpP3($baseUrl);
$csrfProduction = login($httpProduction, 'p3_production_only', 'ProdOnlyPass#123');
$httpPpic = new HttpP3($baseUrl);
$csrfPpic = login($httpPpic, 'p3_ppic', 'PpicPass#123');

// ---------------------------------------------------------------------
runTest('P3-01 live target aggregates PO Awal+Revisi across stores, PB excluded', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-02-01';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 70.0, 'poRevisi' => 30.0, 'pb' => 999.0]]);
    $r = $http->request('GET', "/api/production/target?date={$tanggal}&divisionId={$rotiBollenDivId}", null, ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, 'target fetch failed: ' . json_encode($r['json']));
    $item = null;
    foreach ($r['json']['data']['items'] as $it) {
        if ($it['productId'] === $prodA['product_id']) { $item = $it; break; }
    }
    expect($item !== null, 'expected productA in the target list');
    expect((float) $item['target'] === 100.0, 'expected target 100 (70 awal + 30 revisi, PB excluded), got ' . $item['target']);
});

runTest('P3-02 create draft snapshots the live PO target', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-02-02';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodB['product_id'] => ['poAwal' => 50.0, 'poRevisi' => 0.0]]);
    $r = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-02')));
    expect($r['status'] === 200, 'create draft failed: ' . json_encode($r['json']));
    $dto = $r['json']['data'];
    expect($dto['status'] === 'draft', 'expected status draft');
    $item = null;
    foreach ($dto['items'] as $it) { if ($it['productId'] === $prodB['product_id']) { $item = $it; break; } }
    expect($item !== null && (float) $item['targetSnapshot'] === 50.0, 'expected snapshot target 50 at draft creation');
    expect((float) $item['liveTarget'] === 50.0, 'expected live target also 50 right after creation');
});

runTest('P3-03 actual entry is snapshot semantics, never additive (40 -> 45 stays 45)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-02-03';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 200.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-03')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];

    $save1 = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodA['product_id'], 'actualQty' => 40]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-03a')));
    expect($save1['status'] === 200, 'first save failed: ' . json_encode($save1['json']));
    $v = $save1['json']['data']['version'];
    $item = current(array_filter($save1['json']['data']['items'], fn ($i) => $i['productId'] === $prodA['product_id']));
    expect((float) $item['actual'] === 40.0, 'expected actual 40 after first save');

    $save2 = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodA['product_id'], 'actualQty' => 45]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-03b')));
    expect($save2['status'] === 200, 'second save failed: ' . json_encode($save2['json']));
    $item = current(array_filter($save2['json']['data']['items'], fn ($i) => $i['productId'] === $prodA['product_id']));
    expect((float) $item['actual'] === 45.0, 'expected actual REPLACED to 45, not added to 85, got ' . $item['actual']);
});

runTest('P3-04 draft is freely re-editable multiple times before submit', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-02-04';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodB['product_id'] => ['poAwal' => 10.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-04')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    for ($i = 1; $i <= 3; $i++) {
        $r = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'actualQty' => $i]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-04-' . $i)));
        expect($r['status'] === 200, "edit #{$i} failed: " . json_encode($r['json']));
        $v = $r['json']['data']['version'];
    }
    expect($v === $create['json']['data']['version'] + 3, 'expected version to bump on every edit');
});

runTest('P3-05 remaining recomputes live when PO is revised up (no draft edit needed)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-02-05';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 100.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-05')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodA['product_id'], 'actualQty' => 60]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-05b')));
    expect($save['status'] === 200, 'save failed: ' . json_encode($save['json']));

    // PO revised to 120 — task's own worked example (section 4).
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 100.0, 'poRevisi' => 20.0]]);

    $show = $http->request('GET', "/api/production/{$runId}", null, ['X-CSRF-Token' => $csrf]);
    $item = current(array_filter($show['json']['data']['items'], fn ($i) => $i['productId'] === $prodA['product_id']));
    expect((float) $item['liveTarget'] === 120.0, 'expected live target 120 after PO revision, got ' . $item['liveTarget']);
    expect((float) $item['remaining'] === 60.0, 'expected remaining 60 (120-60), got ' . $item['remaining']);
    expect($item['targetChangedSinceDraft'] === true, 'expected targetChangedSinceDraft=true');
    expect($show['json']['data']['targetChangedSincePoRevision'] === true, 'expected document-level targetChangedSincePoRevision=true');
});

runTest('P3-06 PO revised down below actual: remaining floors at 0, overproduction exposed (task section 4 example)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-02-06';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodB['product_id'] => ['poAwal' => 120.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-06')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'actualQty' => 60]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-06b')));

    // PO revised DOWN to 50 (below the 60 actual already recorded).
    seedPo($pdo, $tanggal, $karangtengahId, [$prodB['product_id'] => ['poAwal' => 50.0, 'poRevisi' => 0.0]]);

    $show = $http->request('GET', "/api/production/{$runId}", null, ['X-CSRF-Token' => $csrf]);
    $item = current(array_filter($show['json']['data']['items'], fn ($i) => $i['productId'] === $prodB['product_id']));
    expect((float) $item['liveTarget'] === 50.0, 'expected live target 50');
    expect((float) $item['actual'] === 60.0, 'expected actual UNCHANGED at 60 — never auto-reduced');
    expect((float) $item['remaining'] === 0.0, 'expected remaining floored at 0, got ' . $item['remaining']);
    expect((float) $item['overproduction'] === 10.0, 'expected overproduction 10 (60-50), got ' . $item['overproduction']);
});

runTest('P3-07 production actual writes never mutate po_item/po_batch', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodC) {
    $tanggal = '2026-02-07';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodC['product_id'] => ['poAwal' => 77.0, 'poRevisi' => 3.0]]);
    $before = $pdo->prepare('SELECT po_awal, po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND b.factory_id = ? AND i.product_id = ?');
    $before->execute([$tanggal, $karangtengahId, $prodC['product_id']]);
    $beforeRow = $before->fetch();

    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-07')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodC['product_id'], 'actualQty' => 999]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-07b')));
    $v = $save['json']['data']['version'];
    $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-07c')));

    $after = $pdo->prepare('SELECT po_awal, po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND b.factory_id = ? AND i.product_id = ?');
    $after->execute([$tanggal, $karangtengahId, $prodC['product_id']]);
    $afterRow = $after->fetch();
    expect($beforeRow === $afterRow, 'expected po_item po_awal/po_revisi to be byte-identical before and after production actual writes');
});

runTest('P3-08 cross-division product rejected (PRODUCT_DIVISION_MISMATCH)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA, $prodOtherDivision) {
    $tanggal = '2026-02-08';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 10.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-08')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $r = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodOtherDivision['product_id'], 'actualQty' => 5]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-08b')));
    expect($r['status'] === 400, "expected 400, got {$r['status']}: " . json_encode($r['json']));
    expect(in_array($r['json']['code'], ['PRODUCT_DIVISION_MISMATCH', 'UNKNOWN_PRODUCT_FOR_RUN'], true), 'expected a division/scope rejection code, got ' . json_encode($r['json']));
});

runTest('P3-09 unresolved product for this run rejected unless refreshTargets is used', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA, $prodB) {
    $tanggal = '2026-02-09';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 10.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-09')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];

    // productB has no PO demand yet for this date — not part of the run.
    $r = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'actualQty' => 5]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-09b')));
    expect($r['status'] === 400 && $r['json']['code'] === 'UNKNOWN_PRODUCT_FOR_RUN', 'expected 400 UNKNOWN_PRODUCT_FOR_RUN: ' . json_encode($r['json']));
});

runTest('P3-10 refreshTargets pulls in a newly-appearing PO product without resetting existing actuals', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA, $prodB) {
    $tanggal = '2026-02-10';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 10.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-10')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $save1 = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodA['product_id'], 'actualQty' => 7]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-10b')));
    $v = $save1['json']['data']['version'];

    // A revision (still uploadType-agnostic here — seedPo just represents the
    // new live PO state) adds productB as a brand-new demand line.
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 10.0, 'poRevisi' => 0.0], $prodB['product_id'] => ['poAwal' => 15.0, 'poRevisi' => 0.0]]);

    $refresh = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [], 'refreshTargets' => true], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-10c')));
    expect($refresh['status'] === 200, 'refresh failed: ' . json_encode($refresh['json']));
    $items = $refresh['json']['data']['items'];
    $itemA = current(array_filter($items, fn ($i) => $i['productId'] === $prodA['product_id']));
    $itemB = current(array_filter($items, fn ($i) => $i['productId'] === $prodB['product_id']));
    expect($itemB !== false, 'expected productB to be pulled in by refreshTargets');
    expect((float) $itemA['actual'] === 7.0, 'expected productA actual UNCHANGED at 7 after refreshTargets, got ' . $itemA['actual']);
});

runTest('P3-11 Finishgood & Packing divisions are out of Phase 3 scope (both factories)', function () use ($http, $csrf, $fgKarangtengahDivId, $fgCibadakDivId) {
    $tanggal = '2026-02-11';
    foreach ([$fgKarangtengahDivId, $fgCibadakDivId] as $fgDiv) {
        $r = $http->request('GET', "/api/production/target?date={$tanggal}&divisionId={$fgDiv}", null, ['X-CSRF-Token' => $csrf]);
        expect($r['status'] === 400 && $r['json']['code'] === 'DIVISION_OUT_OF_SCOPE', "expected 400 DIVISION_OUT_OF_SCOPE for division {$fgDiv}: " . json_encode($r['json']));

        $r2 = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $fgDiv], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-11-' . $fgDiv)));
        expect($r2['status'] === 400 && $r2['json']['code'] === 'DIVISION_OUT_OF_SCOPE', "expected create-draft to also reject division {$fgDiv}");
    }
});

runTest('P3-12 factory identity is isolated — Cibadak target never sees Karangtengah PO (no cross-factory pollution)', function () use ($http, $csrf, $pdo, $karangtengahId, $cibadakId, $rotiBollenDivId, $boluDivId, $prodA, $prodBoluA) {
    $tanggal = '2026-02-12';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 500.0, 'poRevisi' => 0.0]]);
    seedPo($pdo, $tanggal, $cibadakId, [$prodBoluA['product_id'] => ['poAwal' => 40.0, 'poRevisi' => 0.0]]);

    $ktTarget = $http->request('GET', "/api/production/target?date={$tanggal}&divisionId={$rotiBollenDivId}", null, ['X-CSRF-Token' => $csrf]);
    $cbTarget = $http->request('GET', "/api/production/target?date={$tanggal}&divisionId={$boluDivId}", null, ['X-CSRF-Token' => $csrf]);
    expect($ktTarget['json']['data']['factoryId'] === $karangtengahId, 'expected Karangtengah target response to carry the Karangtengah factoryId');
    expect((float) $ktTarget['json']['data']['summary']['targetProduksi'] === 500.0, 'expected Karangtengah target 500, unaffected by Cibadak data');
    expect((float) $cbTarget['json']['data']['summary']['targetProduksi'] === 40.0, 'expected Cibadak target 40, unaffected by Karangtengah data');
    foreach ($cbTarget['json']['data']['items'] as $it) {
        expect($it['productId'] !== $prodA['product_id'], 'Karangtengah product must never appear in Cibadak target');
    }
});

runTest('P3-13 lifecycle: no row (not_started) -> draft -> submitted', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-02-13';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodB['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);

    $list0 = $http->request('GET', "/api/production?date={$tanggal}&divisionId={$rotiBollenDivId}", null, ['X-CSRF-Token' => $csrf]);
    expect($list0['json']['data'] === [], 'expected no production_run row before any draft is created (not_started)');

    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-13')));
    $runId = $create['json']['data']['productionRunId'];
    expect($create['json']['data']['status'] === 'draft', 'expected status draft after creation');

    $v = $create['json']['data']['version'];
    $submit = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-13b')));
    expect($submit['status'] === 200 && $submit['json']['data']['status'] === 'submitted', 'expected status submitted after submit: ' . json_encode($submit['json']));
});

runTest('P3-14 a submitted document cannot be PATCHed directly (409 INVALID_STATUS)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-02-14';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-14')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $submit = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-14b')));
    $v = $submit['json']['data']['version'];

    $r = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodA['product_id'], 'actualQty' => 1]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-14c')));
    expect($r['status'] === 409 && $r['json']['code'] === 'INVALID_STATUS', 'expected 409 INVALID_STATUS on editing a submitted doc: ' . json_encode($r['json']));
});

runTest('P3-15 reopen requires a non-blank reason (400 REASON_REQUIRED)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-02-15';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodB['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-15')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $submit = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-15b')));
    $v = $submit['json']['data']['version'];

    $r = $http->request('POST', "/api/production/{$runId}/reopen", ['expectedVersion' => $v, 'reason' => ''], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-15c')));
    expect($r['status'] === 400 && $r['json']['code'] === 'REASON_REQUIRED', 'expected 400 REASON_REQUIRED: ' . json_encode($r['json']));
});

runTest('P3-16 reopen preserves submittedAt/submittedBy as historical trail', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodC) {
    $tanggal = '2026-02-16';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodC['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-16')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $submit = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-16b')));
    $submittedAt = $submit['json']['data']['submittedAt'];
    $submittedBy = $submit['json']['data']['submittedBy'];
    expect($submittedAt !== null, 'expected submittedAt set after submit');
    $v = $submit['json']['data']['version'];

    $reopen = $http->request('POST', "/api/production/{$runId}/reopen", ['expectedVersion' => $v, 'reason' => 'koreksi angka aktual'], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-16c')));
    expect($reopen['status'] === 200 && $reopen['json']['data']['status'] === 'reopened', 'expected status reopened: ' . json_encode($reopen['json']));
    expect($reopen['json']['data']['submittedAt'] === $submittedAt, 'expected submittedAt PRESERVED across reopen');
    expect($reopen['json']['data']['submittedBy'] === $submittedBy, 'expected submittedBy PRESERVED across reopen');
    expect($reopen['json']['data']['reopenReason'] === 'koreksi angka aktual', 'expected reopen reason recorded');
});

runTest('P3-17 reopen is role-restricted: PRODUCTION-only 403s, ADMIN/PPIC succeed', function () use ($http, $csrf, $httpProduction, $csrfProduction, $httpPpic, $csrfPpic, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-02-17';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-17')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $submit = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-17b')));
    $v = $submit['json']['data']['version'];

    $deniedTry = $httpProduction->request('POST', "/api/production/{$runId}/reopen", ['expectedVersion' => $v, 'reason' => 'coba'], array_merge(['X-CSRF-Token' => $csrfProduction], idemKey('p3-17c')));
    expect($deniedTry['status'] === 403, "expected 403 for PRODUCTION-only role, got {$deniedTry['status']}: " . json_encode($deniedTry['json']));

    $allowedTry = $httpPpic->request('POST', "/api/production/{$runId}/reopen", ['expectedVersion' => $v, 'reason' => 'PPIC boleh'], array_merge(['X-CSRF-Token' => $csrfPpic], idemKey('p3-17d')));
    expect($allowedTry['status'] === 200, 'expected PPIC to be allowed to reopen: ' . json_encode($allowedTry['json']));
});

runTest('P3-18 resubmit after reopen: authoritative actual is the latest edit, not summed (45 -> 48 stays 48)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-02-18';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodB['product_id'] => ['poAwal' => 100.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-18')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'actualQty' => 45]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-18b')));
    $v = $save['json']['data']['version'];
    $submit1 = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-18c')));
    $v = $submit1['json']['data']['version'];

    $reopen = $http->request('POST', "/api/production/{$runId}/reopen", ['expectedVersion' => $v, 'reason' => 'edit lagi'], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-18d')));
    $v = $reopen['json']['data']['version'];
    $edit = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'actualQty' => 48]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-18e')));
    $v = $edit['json']['data']['version'];
    $submit2 = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-18f')));
    expect($submit2['status'] === 200 && $submit2['json']['data']['status'] === 'submitted', 'expected resubmit to succeed');
    $item = current(array_filter($submit2['json']['data']['items'], fn ($i) => $i['productId'] === $prodB['product_id']));
    expect((float) $item['actual'] === 48.0, 'expected authoritative actual 48 (not 45+48=93) after resubmit, got ' . $item['actual']);
});

runTest('P3-19 stale expectedVersion on PATCH returns 409 VERSION_CONFLICT', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-02-19';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-19')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodA['product_id'], 'actualQty' => 1]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-19b')));

    $stale = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodA['product_id'], 'actualQty' => 2]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-19c')));
    expect($stale['status'] === 409 && $stale['json']['code'] === 'VERSION_CONFLICT', 'expected 409 VERSION_CONFLICT on stale version: ' . json_encode($stale['json']));
    expect(isset($stale['json']['currentVersion']), 'expected currentVersion hint in the conflict response');
});

runTest('P3-20 stale expectedVersion on submit returns 409 VERSION_CONFLICT', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-02-20';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodB['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-20')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'actualQty' => 1]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-20b')));

    $stale = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-20c')));
    expect($stale['status'] === 409 && $stale['json']['code'] === 'VERSION_CONFLICT', 'expected 409 VERSION_CONFLICT on submit with stale version: ' . json_encode($stale['json']));
});

runTest('P3-21 Idempotency-Key replay on create returns the identical response, no duplicate document', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodC) {
    $tanggal = '2026-02-21';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodC['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $key = 'p3-21-' . uniqid('', true);
    $r1 = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => $key]);
    $r2 = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => $key]);
    expect($r1['json']['data']['productionRunId'] === $r2['json']['data']['productionRunId'], 'expected the replayed request to return the SAME productionRunId');

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM production_run WHERE tanggal = ? AND division_id = ?');
    $stmt->execute([$tanggal, $rotiBollenDivId]);
    expect((int) $stmt->fetchColumn() === 1, 'expected exactly one production_run row, no duplicate created by the replay');
});

runTest('P3-22 missing Idempotency-Key on submit is rejected (400 MISSING_IDEMPOTENCY_KEY)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-02-22';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-22')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];

    $r = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], ['X-CSRF-Token' => $csrf]); // no Idempotency-Key
    expect($r['status'] === 400 && $r['json']['code'] === 'MISSING_IDEMPOTENCY_KEY', 'expected 400 MISSING_IDEMPOTENCY_KEY: ' . json_encode($r['json']));
});

runTest('P3-23 audit_log records the full lifecycle and is queryable via GET /api/production/history', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-02-23';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodB['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-23')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'actualQty' => 1]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-23b')));
    $v = $save['json']['data']['version'];
    $submit = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-23c')));
    $v = $submit['json']['data']['version'];
    $http->request('POST', "/api/production/{$runId}/reopen", ['expectedVersion' => $v, 'reason' => 'audit check'], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-23d')));

    $hist = $http->request('GET', "/api/production/history?date={$tanggal}&divisionId={$rotiBollenDivId}", null, ['X-CSRF-Token' => $csrf]);
    expect($hist['status'] === 200, 'history fetch failed: ' . json_encode($hist['json']));
    $actions = array_column($hist['json']['data'], 'action');
    foreach (['production.draft.create', 'production.draft.edit', 'production.submit', 'production.reopen'] as $expectedAction) {
        expect(in_array($expectedAction, $actions, true), "expected audit_log action '{$expectedAction}' present, got " . json_encode($actions));
    }
});

runTest('P3-24 Phase 2 store-count UI patch: unique stores vs row occurrences are correctly distinct', function () use ($http, $csrf, $pdo) {
    // Two DIFFERENT products, same file, both allocated to the SAME store
    // (TSA) — a real Karangtengah file with hundreds of products repeating
    // the same store column many times, which is exactly the pattern that
    // made the real cPanel UAT preview show 1986 "toko terpetakan" for a
    // file with far fewer actual distinct stores.
    $rows = $pdo->query(
        "SELECT p.product_id, p.name, plc.legacy_code FROM product p
         INNER JOIN product_legacy_code plc ON plc.product_id = p.product_id
         ORDER BY p.product_id LIMIT 5"
    )->fetchAll();
    expect(count($rows) >= 2, 'expected at least 2 katalog products with legacy codes');
    [$pX, $pY] = $rows;

    $tanggal = '2026-02-24';
    $csv = buildCsvBase64([
        ...karangtengahHeader(),
        karangtengahRow($pX['legacy_code'], $pX['name'], 10, 0, 0, 0),
        karangtengahRow($pY['legacy_code'], $pY['name'], 20, 0, 0, 0),
    ]);
    $r = $http->request('POST', '/api/po/preview', ['tanggal' => $tanggal, 'uploadType' => 'initial', 'fileName' => 'p3-24.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, 'preview failed: ' . json_encode($r['json']));
    $sr = $r['json']['data']['storeResolution'];
    expect(isset($sr['uniqueMapped'], $sr['uniqueUnresolved']), 'expected new uniqueMapped/uniqueUnresolved fields present');
    expect($sr['mapped'] === 2, 'expected row-occurrence mapped count = 2 (one TSA line per product row), got ' . $sr['mapped']);
    expect($sr['uniqueMapped'] === 1, 'expected unique store count = 1 (both rows use the same TSA store), got ' . $sr['uniqueMapped']);
});

// ---------------------------------------------------------------------
// P3-UX01..06 — display-status label patch (presentation layer only, see
// ProductionService::classifyDisplayStatus). None of these touch the
// business rules already covered by P3-01..24 above; they only check the
// NEW displayStatusCode/displayStatusLabel fields and that the OLD 'status'
// DB-backed field is still present unchanged.
// ---------------------------------------------------------------------
runTest('P3-UX01 actual 0 -> Belum Diproduksi', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-02-25';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux01')));
    $item = current(array_filter($create['json']['data']['items'], fn ($i) => $i['productId'] === $prodA['product_id']));
    expect((float) $item['actual'] === 0.0, 'expected fresh draft actual to be 0');
    expect($item['displayStatusCode'] === 'not_produced', 'expected code not_produced, got ' . $item['displayStatusCode']);
    expect($item['displayStatusLabel'] === 'Belum Diproduksi', 'expected label "Belum Diproduksi", got ' . $item['displayStatusLabel']);
});

runTest('P3-UX02 0<actual<target -> Belum Sesuai Target', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-02-26';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodB['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux02')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'actualQty' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux02b')));
    $item = current(array_filter($save['json']['data']['items'], fn ($i) => $i['productId'] === $prodB['product_id']));
    expect($item['displayStatusCode'] === 'below_target', 'expected code below_target, got ' . $item['displayStatusCode']);
    expect($item['displayStatusLabel'] === 'Belum Sesuai Target', 'expected label "Belum Sesuai Target", got ' . $item['displayStatusLabel']);
});

runTest('P3-UX03 actual=target -> Sesuai Target', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodC) {
    $tanggal = '2026-02-27';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodC['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux03')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodC['product_id'], 'actualQty' => 5]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux03b')));
    $item = current(array_filter($save['json']['data']['items'], fn ($i) => $i['productId'] === $prodC['product_id']));
    expect($item['displayStatusCode'] === 'on_target', 'expected code on_target, got ' . $item['displayStatusCode']);
    expect($item['displayStatusLabel'] === 'Sesuai Target', 'expected label "Sesuai Target", got ' . $item['displayStatusLabel']);
});

runTest('P3-UX04 actual>target -> Overproduction', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodA) {
    $tanggal = '2026-02-28';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodA['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux04')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodA['product_id'], 'actualQty' => 6]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux04b')));
    $item = current(array_filter($save['json']['data']['items'], fn ($i) => $i['productId'] === $prodA['product_id']));
    expect($item['displayStatusCode'] === 'overproduction', 'expected code overproduction, got ' . $item['displayStatusCode']);
    expect($item['displayStatusLabel'] === 'Overproduction', 'expected label "Overproduction", got ' . $item['displayStatusLabel']);
});

runTest('P3-UX05 lifecycle status draft/submitted/reopened does not change the classification logic', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodB) {
    $tanggal = '2026-03-01';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodB['product_id'] => ['poAwal' => 5.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux05')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodB['product_id'], 'actualQty' => 6]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux05b')));
    $v = $save['json']['data']['version'];
    $itemDraft = current(array_filter($save['json']['data']['items'], fn ($i) => $i['productId'] === $prodB['product_id']));
    expect($itemDraft['displayStatusCode'] === 'overproduction', 'expected overproduction while draft');

    $submit = $http->request('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $v], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux05c')));
    $v = $submit['json']['data']['version'];
    $itemSubmitted = current(array_filter($submit['json']['data']['items'], fn ($i) => $i['productId'] === $prodB['product_id']));
    expect($submit['json']['data']['status'] === 'submitted', 'expected run status submitted');
    expect($itemSubmitted['displayStatusCode'] === 'overproduction', 'expected classification unchanged (still overproduction) after submit — lifecycle status must not affect it');

    $reopen = $http->request('POST', "/api/production/{$runId}/reopen", ['expectedVersion' => $v, 'reason' => 'cek label'], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux05d')));
    $itemReopened = current(array_filter($reopen['json']['data']['items'], fn ($i) => $i['productId'] === $prodB['product_id']));
    expect($reopen['json']['data']['status'] === 'reopened', 'expected run status reopened');
    expect($itemReopened['displayStatusCode'] === 'overproduction', 'expected classification still unchanged (overproduction) after reopen — same actual/target, same label regardless of run lifecycle status');
});

runTest('P3-UX06 no schema/API business-contract regression (internal status kept, business fields unaffected)', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $prodC) {
    $tanggal = '2026-03-02';
    seedPo($pdo, $tanggal, $karangtengahId, [$prodC['product_id'] => ['poAwal' => 10.0, 'poRevisi' => 0.0]]);
    $create = $http->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux06')));
    $runId = $create['json']['data']['productionRunId'];
    $v = $create['json']['data']['version'];
    $save = $http->request('PATCH', "/api/production/{$runId}", ['expectedVersion' => $v, 'items' => [['productId' => $prodC['product_id'], 'actualQty' => 4]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('p3-ux06b')));
    $item = current(array_filter($save['json']['data']['items'], fn ($i) => $i['productId'] === $prodC['product_id']));

    // The OLD internal field must still be present and unchanged in shape
    // (still the DB enum 'sesuai'/'tidak_sesuai') — this patch is additive only.
    expect(isset($item['status']), 'expected the pre-existing "status" field to still be present in the API response');
    expect(in_array($item['status'], ['sesuai', 'tidak_sesuai'], true), 'expected internal status to still be one of the original DB enum values, got ' . $item['status']);
    expect((float) $item['remaining'] === 6.0, 'expected remaining formula unchanged (max(0, target-actual) = 10-4 = 6), got ' . $item['remaining']);
    expect((float) $item['overproduction'] === 0.0, 'expected overproduction formula unchanged, got ' . $item['overproduction']);
    expect((float) $item['liveTarget'] === 10.0, 'expected liveTarget unaffected by the label patch');

    // production_item.status DB column itself must be untouched by this patch —
    // still whatever ProductionRepository::updateItemActual already computed
    // before this patch existed (unchanged logic: target>0 && actual>=target ? sesuai : tidak_sesuai).
    $dbStatus = $pdo->prepare(
        'SELECT pi.status FROM production_item pi INNER JOIN production_run pr ON pr.production_run_id = pi.production_run_id
         WHERE pr.production_run_id = ? AND pi.product_id = ?'
    );
    $dbStatus->execute([$runId, $prodC['product_id']]);
    expect((string) $dbStatus->fetchColumn() === 'tidak_sesuai', 'expected DB status column unchanged by this patch (4 < 10 -> tidak_sesuai)');
});

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
