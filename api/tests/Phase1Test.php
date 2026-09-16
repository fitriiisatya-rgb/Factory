<?php

declare(strict_types=1);

/**
 * Phase 1 fast-track integration test suite (P1-01 .. P1-20). Run via
 * api/tests/run-phase1.sh, which stands up a disposable local MariaDB +
 * `php -S` dev server first, applies migrations 0001+0002, seeds, creates
 * an admin, then runs this suite. Do not run this file directly against
 * anything but a disposable test DB — it creates/mutates real rows.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Import\Phase1Importer;
use Amor\Api\Repositories\MigrationMapRepository;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8089';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '') {
    fwrite(STDERR, "TEST_ADMIN_PASS, TEST_DB_SOCKET, TEST_DB_NAME env vars are required.\n");
    exit(1);
}

final class Http
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'p1cookies');
    }

    public function freshSession(): void
    {
        @unlink($this->cookieJar);
    }

    /** @return array{status:int,json:?array} */
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
            CURLOPT_HEADER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('curl error: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawBody = substr($raw, $headerSize);
        $json = $rawBody === '' ? null : json_decode($rawBody, true);

        return ['status' => $status, 'json' => $json];
    }
}

function expect(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
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

$pdo = Database::pdo();
$http = new Http($baseUrl);
$importer = new Phase1Importer($pdo);

// --- Login once, reused across tests that need an authenticated session ---
$http->request('POST', '/api/auth/login', ['username' => $adminUser, 'password' => $adminPass]);
$me = $http->request('GET', '/api/auth/me');
$adminCsrf = $me['json']['data']['csrfToken'] ?? null;

// ---------------------------------------------------------------------
runTest('P1-01 factory rows unchanged 2', function () use ($pdo) {
    $n = (int) $pdo->query('SELECT COUNT(*) FROM factory')->fetchColumn();
    expect($n === 2, "expected 2 factories, got {$n}");
});

runTest('P1-02 divisions imported correctly', function () use ($importer, $pdo) {
    $importer->importDivisions();
    $n = (int) $pdo->query('SELECT COUNT(*) FROM division')->fetchColumn();
    expect($n === 8, "expected 8 divisions, got {$n}");

    $stmt = $pdo->prepare(
        'SELECT f.name FROM division d INNER JOIN factory f ON f.factory_id = d.factory_id WHERE d.name = ?'
    );
    $stmt->execute(['Bolu']);
    expect($stmt->fetchColumn() === 'Cibadak', 'Bolu should map to Cibadak');

    $stmt->execute(['Roti & Bollen']);
    expect($stmt->fetchColumn() === 'Karangtengah', 'Roti & Bollen should map to Karangtengah');

    $stmt = $pdo->prepare('SELECT is_verification FROM division WHERE name = ?');
    $stmt->execute(['Finishgood & Packing']);
    expect((int) $stmt->fetchColumn() === 1, 'Finishgood & Packing should be is_verification=1');
});

runTest('P1-03 product create numeric ID', function () use ($http, $adminCsrf) {
    $r = $http->request('POST', '/api/products',
        ['name' => 'P1 Direct Create Test ' . uniqid(), 'hpp' => 100, 'harga' => 200],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p1-03-' . uniqid()]
    );
    expect($r['status'] === 201, "expected 201, got {$r['status']}: " . json_encode($r['json']));
    expect(is_int($r['json']['data']['product_id']), 'product_id should be numeric/int');
});

runTest('P1-04 duplicate normalized product conflict detected (name collision -> review)', function () use ($pdo, $importer) {
    // A product with this exact name already exists but under a DIFFERENT
    // legacy_code than any katalog row -> must classify as 'review', never silently merged.
    $pdo->prepare(
        "INSERT INTO product (name, kategori, hpp, harga, aktif, version, created_at) VALUES (?, 'TEST', 0, 0, 1, 1, UTC_TIMESTAMP())"
    )->execute(['LEMPER AYAM 2']); // matches a real katalog row's name, code 100265, deliberately not linked here

    $preview = $importer->previewProducts();
    $found = null;
    foreach ($preview['review'] as $item) {
        if ($item['row']['n'] === 'LEMPER AYAM 2') {
            $found = $item;
            break;
        }
    }
    expect($found !== null, 'expected LEMPER AYAM 2 to be classified as review due to name collision');
});

runTest('P1-05 duplicate legacy code conflict detected', function () use ($pdo, $importer) {
    // legacy_code 100255 belongs to "PUDDING ITALIA ALPENLIEBE" in the real katalog.
    // Attach that exact code to a DIFFERENT product name -> must classify as conflict.
    $stmt = $pdo->prepare(
        "INSERT INTO product (name, kategori, hpp, harga, aktif, version, created_at) VALUES (?, 'TEST', 0, 0, 1, 1, UTC_TIMESTAMP())"
    );
    $stmt->execute(['SOME OTHER PRODUCT ENTIRELY']);
    $productId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO product_legacy_code (product_id, legacy_code, created_at) VALUES (?, '100255', UTC_TIMESTAMP())"
    )->execute([$productId]);

    $preview = $importer->previewProducts();
    $found = null;
    foreach ($preview['conflict'] as $item) {
        if ($item['row']['kd'] === '100255') {
            $found = $item;
            break;
        }
    }
    expect($found !== null, 'expected legacy_code 100255 to be classified as conflict due to code collision');
});

runTest('P1-06 confirmed alias maps correctly (product alias)', function () use ($http, $adminCsrf) {
    $name = 'P1 Alias Base Product ' . uniqid();
    $r = $http->request('POST', '/api/products', ['name' => $name, 'hpp' => 1, 'harga' => 1],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p1-06-create-' . uniqid()]);
    $productId = $r['json']['data']['product_id'];

    $r = $http->request('POST', "/api/products/{$productId}/aliases", ['rawName' => $name . ' (alias)'],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p1-06-alias-' . uniqid()]);
    expect($r['status'] === 201, "expected 201, got {$r['status']}: " . json_encode($r['json']));
});

runTest('P1-07 alias reassignment blocked', function () use ($pdo, $http, $adminCsrf) {
    $stmt = $pdo->query('SELECT product_id FROM product ORDER BY product_id LIMIT 1');
    $productA = (int) $stmt->fetchColumn();
    $stmt = $pdo->query('SELECT product_id FROM product ORDER BY product_id DESC LIMIT 1');
    $productB = (int) $stmt->fetchColumn();
    expect($productA !== $productB, 'need two distinct products for this test');

    $aliasName = 'P1 Reassignment Probe ' . uniqid();
    $r1 = $http->request('POST', "/api/products/{$productA}/aliases", ['rawName' => $aliasName],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p1-07-a-' . uniqid()]);
    expect($r1['status'] === 201, 'first alias creation should succeed: ' . json_encode($r1['json']));

    $r2 = $http->request('POST', "/api/products/{$productB}/aliases", ['rawName' => $aliasName],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p1-07-b-' . uniqid()]);
    expect($r2['status'] === 409, "expected 409, got {$r2['status']}: " . json_encode($r2['json']));
    expect($r2['json']['code'] === 'ALIAS_ALREADY_MAPPED', 'wrong error code: ' . json_encode($r2['json']));
});

runTest('P1-08 store create numeric ID', function () use ($http, $adminCsrf) {
    $r = $http->request('POST', '/api/stores', ['canonicalName' => 'P1 Test Store ' . uniqid()],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p1-08-' . uniqid()]);
    expect($r['status'] === 201, "expected 201, got {$r['status']}: " . json_encode($r['json']));
    expect(is_int($r['json']['data']['store_id']), 'store_id should be numeric/int');
});

runTest('P1-09 confirmed store alias maps correctly (import result)', function () use ($importer, $pdo) {
    $importer->importStoreAliasGroups();
    $stmt = $pdo->prepare(
        'SELECT s.canonical_name FROM store_alias sa INNER JOIN store s ON s.store_id = sa.store_id WHERE sa.raw_name = ?'
    );
    $stmt->execute(['CKLE']);
    expect($stmt->fetchColumn() === 'BAKERY CIKOLE', "CKLE should resolve to BAKERY CIKOLE");
    $stmt->execute(['CIKOLE']);
    expect($stmt->fetchColumn() === 'BAKERY CIKOLE', "CIKOLE should resolve to BAKERY CIKOLE");
});

runTest('P1-10 unresolved store stays unresolved (nothing fabricated)', function () use ($pdo) {
    // SDRM / BAKERY SUDIRMAN were mentioned in the Phase 1 request as a "known example"
    // but do not appear anywhere in the actual frontend source (verified during the
    // extraction pass) -- this importer must never have invented them.
    $n = (int) $pdo->query("SELECT COUNT(*) FROM store WHERE canonical_name LIKE '%SUDIRMAN%'")->fetchColumn();
    expect($n === 0, 'BAKERY SUDIRMAN should not exist — it was never source-confirmed');
    $n = (int) $pdo->query("SELECT COUNT(*) FROM store_alias WHERE raw_name = 'SDRM'")->fetchColumn();
    expect($n === 0, 'SDRM alias should not exist — it was never source-confirmed');
});

runTest('P1-11 migration product mapping resolution audited', function () use ($pdo, $http, $adminCsrf) {
    $pdo->prepare(
        "INSERT INTO migration_product_map (raw_name, raw_code, source_table, occurrence_count, status, created_at)
         VALUES ('P1 TEST UNRESOLVED PRODUCT', '900001', 'test', 1, 'unresolved', UTC_TIMESTAMP())"
    )->execute();
    $mapId = (int) $pdo->lastInsertId();
    $targetProductId = (int) $pdo->query('SELECT product_id FROM product ORDER BY product_id LIMIT 1')->fetchColumn();

    $r = $http->request('POST', "/api/admin/migration/products/{$mapId}/resolve",
        ['targetId' => $targetProductId, 'notes' => 'test'],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p1-11-' . uniqid()]);
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE record_type = 'migration_product_map' AND record_key = ? AND status='ok'");
    $stmt->execute([(string) $mapId]);
    expect((int) $stmt->fetchColumn() >= 1, 'expected an audit_log row for the resolve action');
});

runTest('P1-12 migration store mapping resolution audited', function () use ($pdo, $http, $adminCsrf) {
    $pdo->prepare(
        "INSERT INTO migration_store_map (raw_name, raw_code, source_table, occurrence_count, status, created_at)
         VALUES ('P1 TEST UNRESOLVED STORE', '', 'test', 1, 'unresolved', UTC_TIMESTAMP())"
    )->execute();
    $mapId = (int) $pdo->lastInsertId();
    $targetStoreId = (int) $pdo->query('SELECT store_id FROM store ORDER BY store_id LIMIT 1')->fetchColumn();

    $r = $http->request('POST', "/api/admin/migration/stores/{$mapId}/resolve",
        ['targetId' => $targetStoreId, 'notes' => 'test'],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p1-12-' . uniqid()]);
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE record_type = 'migration_store_map' AND record_key = ? AND status='ok'");
    $stmt->execute([(string) $mapId]);
    expect((int) $stmt->fetchColumn() >= 1, 'expected an audit_log row for the resolve action');
});

$staleProductId = null;
runTest('P1-13 stale version conflict', function () use ($http, $adminCsrf, &$staleProductId) {
    $r = $http->request('POST', '/api/products', ['name' => 'P1 Version Conflict Test ' . uniqid(), 'hpp' => 1, 'harga' => 1],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p1-13-create-' . uniqid()]);
    $staleProductId = $r['json']['data']['product_id'];

    $r1 = $http->request('PUT', "/api/products/{$staleProductId}", ['harga' => 5, 'version' => 1],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p1-13-u1-' . uniqid()]);
    expect($r1['status'] === 200, 'first update should succeed: ' . json_encode($r1['json']));

    $r2 = $http->request('PUT', "/api/products/{$staleProductId}", ['harga' => 6, 'version' => 1],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p1-13-u2-' . uniqid()]);
    expect($r2['status'] === 409, "expected 409, got {$r2['status']}: " . json_encode($r2['json']));
    expect($r2['json']['code'] === 'VERSION_CONFLICT', 'wrong error code: ' . json_encode($r2['json']));
});

runTest('P1-14 idempotent import rerun does not duplicate', function () use ($importer, $pdo) {
    // First call is the REAL import (nothing before this point has run importSafeProducts()
    // yet — P1-04/P1-05 only staged collision fixtures). This establishes the baseline.
    $first = $importer->importSafeProducts();
    expect($first['imported'] > 0, 'expected the first real product import to import something, got 0');

    $before = (int) $pdo->query('SELECT COUNT(*) FROM product')->fetchColumn();
    $beforeLegacy = (int) $pdo->query('SELECT COUNT(*) FROM product_legacy_code')->fetchColumn();

    // Second call is the actual rerun under test.
    $result = $importer->importSafeProducts();
    $after = (int) $pdo->query('SELECT COUNT(*) FROM product')->fetchColumn();
    $afterLegacy = (int) $pdo->query('SELECT COUNT(*) FROM product_legacy_code')->fetchColumn();
    expect($before === $after, "product count changed on rerun: {$before} -> {$after}");
    expect($beforeLegacy === $afterLegacy, "product_legacy_code count changed on rerun: {$beforeLegacy} -> {$afterLegacy}");
    expect($result['imported'] === 0, 'rerun should import 0 new rows, got ' . $result['imported']);
});

runTest('P1-15 non-admin migration review blocked', function () use ($http, $pdo, $baseUrl) {
    $hash = password_hash('PpicPass123456', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES ('p1_ppic', ?, 'PPIC', 1, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)")->execute([$hash]);
    $pdo->exec("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT (SELECT user_id FROM users WHERE username='p1_ppic'), role_id FROM roles WHERE code='PPIC'");

    $ppicHttp = new Http($baseUrl);
    $ppicHttp->request('POST', '/api/auth/login', ['username' => 'p1_ppic', 'password' => 'PpicPass123456']);
    $r = $ppicHttp->request('GET', '/api/admin/migration/products?status=unresolved');
    expect($r['status'] === 403, "expected 403, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['code'] === 'FORBIDDEN', 'wrong error code: ' . json_encode($r['json']));
});

runTest('P1-16 no transaction tables populated by Phase 1', function () use ($pdo) {
    $tables = ['po_batch', 'production_run', 'fg_batch', 'delivery_order', 'shipment', 'invoice', 'payment', 'return_note', 'reject_note', 'retail_sale', 'stock_ledger'];
    foreach ($tables as $t) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        expect($n === 0, "expected {$t} to have 0 rows, found {$n}");
    }
});

runTest('P1-17 existing /api/health remains OK', function () use ($http) {
    $r = $http->request('GET', '/api/health');
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    expect($r['json']['data']['db'] === 'connected', 'db should be connected');
});

runTest('P1-18 existing admin login still works', function () use ($baseUrl, $adminUser, $adminPass) {
    $fresh = new Http($baseUrl);
    $r = $fresh->request('POST', '/api/auth/login', ['username' => $adminUser, 'password' => $adminPass]);
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
});

runTest('P1-19 runtime DB user (SELECT/INSERT/UPDATE/DELETE only) can do normal master CRUD', function () use ($dbSocket, $dbName) {
    $pdo = new PDO("mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4", 'p1_runtime_user', 'RuntimeUserPass123', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("INSERT INTO factory (code, name) VALUES ('TST', 'P1 Runtime Test Factory')");
    $id = $pdo->lastInsertId();
    $pdo->exec("UPDATE factory SET name = 'P1 Runtime Test Factory Updated' WHERE factory_id = {$id}");
    $name = $pdo->query("SELECT name FROM factory WHERE factory_id = {$id}")->fetchColumn();
    expect($name === 'P1 Runtime Test Factory Updated', 'runtime user CRUD did not work as expected');
    $pdo->exec("DELETE FROM factory WHERE factory_id = {$id}");
});

runTest('P1-20 runtime DB user cannot run DDL (migration path requires the separate migration user)', function () use ($dbSocket, $dbName) {
    $pdo = new PDO("mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4", 'p1_runtime_user', 'RuntimeUserPass123', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $blocked = false;
    try {
        $pdo->exec('ALTER TABLE division ADD COLUMN p1_probe_col INT');
    } catch (\PDOException $e) {
        $blocked = true;
    }
    expect($blocked, 'runtime user should NOT be able to run ALTER TABLE — privilege separation is broken');
});

// ---------------------------------------------------------------------
$total = count($results);
$passed = count(array_filter($results));
fwrite(STDOUT, "\n{$passed}/{$total} passed\n");
exit($passed === $total ? 0 : 1);
