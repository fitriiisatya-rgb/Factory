<?php

declare(strict_types=1);

/**
 * Phase 0 integration test suite (P0-01 .. P0-17). Run via api/tests/run.sh,
 * which stands up a disposable local MariaDB + `php -S` dev server first.
 * Do not run this file directly against anything but a disposable test DB —
 * it creates/mutates real rows.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8089';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';

if ($adminPass === '') {
    fwrite(STDERR, "TEST_ADMIN_PASS env var is required.\n");
    exit(1);
}

final class Http
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'p0cookies');
    }

    public function freshSession(): void
    {
        @unlink($this->cookieJar);
    }

    /** @return array{status:int,json:?array,headers:array} */
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

        return ['status' => $status, 'json' => $json, 'headers' => []];
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

// ---------------------------------------------------------------------
runTest('P0-01 DB connection works', function () use ($http) {
    $r = $http->request('GET', '/api/health');
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    expect($r['json']['data']['db'] === 'connected', 'db should be connected: ' . json_encode($r['json']));
});

runTest('P0-02 all 62 tables exist (45 original + po_import from migration 0003 + 6 Phase 5.5 dispatch/receipt tables from migration 0007 + shipment_receipt_evidence from migration 0008 + shipment_email_delivery from migration 0009 + special_order/special_order_item/special_order_catalog from migration 0010 + special_order_do/special_order_do_item/special_order_do_shipment_item/shipment_receipt_token/special_order_fg_allocation from migration 0012 — migrations 0011, 0013, 0014 are additive ALTERs only, no new tables)', function () use ($pdo) {
    $count = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name != 'schema_migrations'"
    )->fetchColumn();
    expect($count === 62, "expected 62 tables, got {$count}");
});

$adminCsrf = null;
runTest('P0-03 login success', function () use ($http, $adminUser, $adminPass, &$adminCsrf) {
    $http->freshSession();
    $r = $http->request('POST', '/api/auth/login', ['username' => $adminUser, 'password' => $adminPass]);
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    expect(!empty($r['json']['data']['csrfToken']), 'csrfToken missing from login response');
    $adminCsrf = $r['json']['data']['csrfToken'];
});

runTest('P0-04 login failure', function () use ($http, $adminUser) {
    $r = $http->request('POST', '/api/auth/login', ['username' => $adminUser, 'password' => 'definitely-wrong']);
    expect($r['status'] === 401, "expected 401, got {$r['status']}");
    expect($r['json']['code'] === 'INVALID_CREDENTIALS', 'wrong error code: ' . json_encode($r['json']));
});

runTest('P0-05 auth/me works', function () use ($http, $adminUser) {
    // Deliberately reuses the session from P0-03 rather than logging in again —
    // a second login would regenerate the session's CSRF token (by design, see
    // Auth::attemptLogin) and invalidate $adminCsrf that later tests depend on.
    $me = $http->request('GET', '/api/auth/me');
    expect($me['status'] === 200, "expected 200, got {$me['status']}: " . json_encode($me['json']));
    expect($me['json']['data']['username'] === $adminUser, 'me() returned wrong username');
});

runTest('P0-06 unauthenticated protected endpoint rejected', function () use ($baseUrl) {
    $anon = new Http($baseUrl);
    $r = $anon->request('GET', '/api/products');
    expect($r['status'] === 401, "expected 401, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['code'] === 'UNAUTHENTICATED', 'wrong error code: ' . json_encode($r['json']));
});

runTest('P0-07 CSRF missing rejected on mutation', function () use ($http) {
    // reuses the already-logged-in $http session, but omits X-CSRF-Token
    $r = $http->request('POST', '/api/products', ['name' => 'CSRF Probe Product ' . uniqid(), 'hpp' => 1, 'harga' => 1]);
    expect($r['status'] === 403, "expected 403, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['code'] === 'CSRF_TOKEN_INVALID', 'wrong error code: ' . json_encode($r['json']));
});

$productId = null;
$productKey1 = 'p0-08-' . uniqid();
$productName = 'P0 Test Product ' . uniqid();
runTest('P0-08 product create works', function () use ($http, $adminCsrf, $productKey1, $productName, &$productId) {
    $r = $http->request('POST', '/api/products',
        ['name' => $productName, 'kategori' => 'test', 'hpp' => 1000, 'harga' => 1500, 'aktif' => true],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => $productKey1]
    );
    expect($r['status'] === 201, "expected 201, got {$r['status']}: " . json_encode($r['json']));
    expect((int) $r['json']['data']['version'] === 1, 'new product should start at version 1');
    $productId = (int) $r['json']['data']['product_id'];
});

runTest('P0-09 product duplicate name blocked', function () use ($http, $adminCsrf, $productName) {
    $r = $http->request('POST', '/api/products',
        ['name' => $productName, 'hpp' => 1, 'harga' => 1],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p0-09-' . uniqid()]
    );
    expect($r['status'] === 409, "expected 409, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['code'] === 'DUPLICATE_PRODUCT_NAME', 'wrong error code: ' . json_encode($r['json']));
});

runTest('P0-10 version update success', function () use ($http, $adminCsrf, &$productId) {
    $r = $http->request('PUT', "/api/products/{$productId}",
        ['harga' => 1600, 'version' => 1],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p0-10-' . uniqid()]
    );
    expect($r['status'] === 200, "expected 200, got {$r['status']}: " . json_encode($r['json']));
    expect((int) $r['json']['data']['version'] === 2, 'version should now be 2, got ' . json_encode($r['json']));
});

runTest('P0-11 stale version -> VERSION_CONFLICT', function () use ($http, $adminCsrf, $productId) {
    $r = $http->request('PUT', "/api/products/{$productId}",
        ['harga' => 1700, 'version' => 1], // stale — it's already 2
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => 'p0-11-' . uniqid()]
    );
    expect($r['status'] === 409, "expected 409, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['code'] === 'VERSION_CONFLICT', 'wrong error code: ' . json_encode($r['json']));
    expect((int) $r['json']['currentVersion'] === 2, 'currentVersion should be reported as 2');
});

runTest('P0-12 idempotent replay does not duplicate write', function () use ($http, $adminCsrf, $productKey1, $productName, $pdo) {
    $before = (int) $pdo->query("SELECT COUNT(*) FROM product WHERE name = " . $pdo->quote($productName))->fetchColumn();
    $r = $http->request('POST', '/api/products',
        ['name' => $productName, 'kategori' => 'test', 'hpp' => 1000, 'harga' => 1500, 'aktif' => true],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => $productKey1] // same key + same payload as P0-08
    );
    expect($r['status'] === 201, "expected replayed 201, got {$r['status']}: " . json_encode($r['json']));
    $after = (int) $pdo->query("SELECT COUNT(*) FROM product WHERE name = " . $pdo->quote($productName))->fetchColumn();
    expect($before === $after, "row count changed on replay: before={$before} after={$after}");
    expect($before === 1, "expected exactly 1 row for this product name, found {$before}");
});

runTest('P0-13 same key different payload blocked', function () use ($http, $adminCsrf, $productKey1) {
    $r = $http->request('POST', '/api/products',
        ['name' => 'A Totally Different Name ' . uniqid(), 'hpp' => 1, 'harga' => 1],
        ['X-CSRF-Token' => $adminCsrf, 'Idempotency-Key' => $productKey1] // same key, different body
    );
    expect($r['status'] === 409, "expected 409, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['code'] === 'IDEMPOTENCY_KEY_REUSE_MISMATCH', 'wrong error code: ' . json_encode($r['json']));
});

runTest('P0-14 audit log written', function () use ($pdo, $productId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE record_type = 'product' AND record_key = ? AND status = 'ok'");
    $stmt->execute([(string) $productId]);
    $n = (int) $stmt->fetchColumn();
    expect($n >= 2, "expected at least 2 audit_log rows (create + update) for product {$productId}, found {$n}");
});

runTest('P0-15 synthetic non-outlet store exists', function () use ($pdo) {
    $stmt = $pdo->prepare('SELECT active FROM store WHERE canonical_name = ?');
    $stmt->execute(['NON-OUTLET / PERORANGAN']);
    $active = $stmt->fetchColumn();
    expect($active !== false, 'synthetic store row not found');
    expect((int) $active === 1, 'synthetic store should be active');
});

runTest('P0-16 DO open-key DB constraint test', function () use ($pdo) {
    $storeId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'NON-OUTLET / PERORANGAN'")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO delivery_order (tanggal, store_id, shipment_group, status, version, created_at)
         VALUES ('2026-09-20', ?, 'MAIN', 'draft', 1, UTC_TIMESTAMP())"
    )->execute([$storeId]);

    $blocked = false;
    try {
        $pdo->prepare(
            "INSERT INTO delivery_order (tanggal, store_id, shipment_group, status, version, created_at)
             VALUES ('2026-09-20', ?, 'MAIN', 'draft', 1, UTC_TIMESTAMP())"
        )->execute([$storeId]);
    } catch (\PDOException $e) {
        $blocked = (int) $e->getCode() === 23000;
    }
    expect($blocked, 'a second open DO for the same (tanggal, store_id, shipment_group) should have been blocked');
});

runTest('P0-17 document sequence concurrent uniqueness', function () use ($pdo) {
    $pdo->exec("DELETE FROM document_sequence WHERE document_type = 'test_seq'");

    $n = 10;
    $procs = [];
    $pipes = [];
    $childScript = __DIR__ . '/_seq_allocate_child.php';
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    for ($i = 0; $i < $n; $i++) {
        $procs[$i] = proc_open(['php', $childScript], $descriptors, $pipes[$i]);
    }

    $values = [];
    for ($i = 0; $i < $n; $i++) {
        $out = stream_get_contents($pipes[$i][1]);
        $err = stream_get_contents($pipes[$i][2]);
        fclose($pipes[$i][1]);
        fclose($pipes[$i][2]);
        $code = proc_close($procs[$i]);
        expect($code === 0, "child {$i} exited {$code}: {$err}");
        $values[] = (int) trim($out);
    }

    sort($values);
    $unique = array_unique($values);
    expect(count($unique) === $n, 'duplicate sequence numbers allocated under concurrency: ' . json_encode($values));
    expect($values === range(1, $n), 'sequence numbers should be exactly 1..' . $n . ', got ' . json_encode($values));
});

// ---------------------------------------------------------------------
$total = count($results);
$passed = count(array_filter($results));
fwrite(STDOUT, "\n{$passed}/{$total} passed\n");
exit($passed === $total ? 0 : 1);
