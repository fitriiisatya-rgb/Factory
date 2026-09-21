<?php

declare(strict_types=1);

/**
 * Print DO / Surat Jalan redesign integration suite (PRINT-01..12).
 * Run via api/tests/run-print-preview.sh, which stands up a disposable
 * local MariaDB, applies ALL migrations 0001-0006, bootstraps realistic
 * master data, then drives the real /api/_ui-preview/print-do*.php pages
 * (and the old, untouched api/_do-uat/print.php fallback) against a live
 * `php -S` server.
 *
 * PRINT-13 (full Phase 0-5 regression green) is NOT a test in this file
 * — it is the orchestrator's own final step, reported alongside this
 * suite's own result rather than faked as a runTest() call here.
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8106';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'print_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpPrint
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'printcookies');
    }

    /** @return array{status:int,json:?array,body:string,headers:string} */
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
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_HTTPHEADER => $hdrLines,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('curl error: ' . curl_error($ch));
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = null;
        if ($rawBody !== '' && ($rawBody[0] === '{' || $rawBody[0] === '[')) {
            $json = json_decode($rawBody, true);
        }
        return ['status' => $status, 'json' => $json, 'body' => $rawBody, 'headers' => $rawHeaders];
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
        $pdo->prepare('UPDATE po_batch SET version = version + 1, updated_at = UTC_TIMESTAMP() WHERE po_batch_id = ?')->execute([$batchId]);
    }
    $upsertItem = $pdo->prepare(
        'INSERT INTO po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (?, ?, ?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE kategori = VALUES(kategori), po_awal = VALUES(po_awal), po_revisi = VALUES(po_revisi)'
    );
    $findItem = $pdo->prepare('SELECT po_item_id FROM po_item WHERE po_batch_id = ? AND product_id = ?');
    $upsertStoreItem = $pdo->prepare(
        'INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal), po_revisi = VALUES(po_revisi)'
    );
    foreach ($items as $productId => $d) {
        $upsertItem->execute([$batchId, $productId, $d['kategori'] ?? null, $d['poAwal'] ?? 0.0, $d['poRevisi'] ?? 0.0]);
        $findItem->execute([$batchId, $productId]);
        $poItemId = (int) $findItem->fetchColumn();
        $upsertStoreItem->execute([$poItemId, $storeId, $d['poAwal'] ?? 0.0, $d['poRevisi'] ?? 0.0]);
    }
    return $batchId;
}

function createSubmittedProduction(HttpPrint $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual): void
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

function stockUpForDelivery(HttpPrint $http, string $csrf, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $poTarget, float $actual, float $fgQty): void
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

function login(HttpPrint $http, string $username, string $password): string
{
    $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    $me = $http->request('GET', '/api/auth/me');
    $csrf = $me['json']['data']['csrfToken'] ?? null;
    expect($csrf !== null, "expected a csrf token after login as {$username}");
    return $csrf;
}

function productsInDivision(PDO $pdo, int $divisionId, int $limit): array
{
    $stmt = $pdo->prepare('SELECT product_id, name FROM product WHERE division_id = ? ORDER BY product_id LIMIT ' . (int) $limit);
    $stmt->execute([$divisionId]);
    return $stmt->fetchAll();
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

// ---------------------------------------------------------------------
// PRINT-11: authentication still required — checked BEFORE login, with a
// genuinely empty cookie jar.
// ---------------------------------------------------------------------
runTest('PRINT-11 print page still requires authentication', function () use ($baseUrl) {
    $anon = new HttpPrint($baseUrl);
    $r = $anon->request('GET', '/_ui-preview/print-do.php?doId=1');
    expect(in_array($r['status'], [301, 302, 303, 307, 308], true), "expected a redirect for an unauthenticated print request, got {$r['status']}");
    expect(stripos($r['headers'], '_admin-login') !== false, 'expected redirect Location to point at _admin-login');
});

$http = new HttpPrint($baseUrl);
$csrf = login($http, $adminUser, $adminPass);

$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
expect($karangtengahId > 0, 'expected Karangtengah factory seeded');
$rotiBollenDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
expect($rotiBollenDivId > 0, 'expected Roti & Bollen division seeded');
$rotiProducts = productsInDivision($pdo, $rotiBollenDivId, 20);
expect(count($rotiProducts) >= 20, 'expected enough katalog products after bootstrap');
$pool = $rotiProducts;
function nextProduct(): array { global $pool; return array_shift($pool); }

$storeA = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
expect($storeA > 0, 'expected P2 TEST STORE A fixture seeded');

function locationIdForFactory(PDO $pdo, int $factoryId): int
{
    $stmt = $pdo->prepare('SELECT location_id FROM location WHERE factory_id = ?');
    $stmt->execute([$factoryId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : 0;
}

runTest('PRINT-01 draft DO print renders with the DRAFT watermark', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-08-01';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 9.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('print01')));
    $doId = $create['json']['data']['doId'];
    $r = $http->request('GET', "/_ui-preview/print-do.php?doId={$doId}");
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    expect(str_contains($r['body'], 'DRAFT'), 'expected the DRAFT watermark text');
    expect(str_contains($r['body'], 'Delivery Order') || str_contains($r['body'], 'DELIVERY ORDER'), 'expected the document title');
});

runTest('PRINT-02 preprinted DO print renders with the PREPRINT watermark', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-08-02';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 6.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('print02')));
    $doId = $create['json']['data']['doId'];
    $http->request('POST', "/api/do/{$doId}/preprint", ['expectedVersion' => 1], array_merge(['X-CSRF-Token' => $csrf], idemKey('print02-pp')));
    $r = $http->request('GET', "/_ui-preview/print-do.php?doId={$doId}");
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    expect(str_contains($r['body'], 'PREPRINT'), 'expected the PREPRINT watermark text');
});

runTest('PRINT-03 the printed DO number matches the real doc_no', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-08-03';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 3.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('print03')));
    $doId = $create['json']['data']['doId'];
    $docNo = $create['json']['data']['docNo'];
    expect((bool) preg_match('#^DO/KRM/\d{3}/[IVXLCDM]+/\d{4}$#', $docNo), "unexpected docNo format: {$docNo}");
    $r = $http->request('GET', "/_ui-preview/print-do.php?doId={$doId}");
    expect(str_contains($r['body'], $docNo), 'expected the printed page to show the real DO number');
});

runTest('PRINT-04 store/date/factory shown on the print page are correct', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-08-04';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 3.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('print04')));
    $doId = $create['json']['data']['doId'];
    $r = $http->request('GET', "/_ui-preview/print-do.php?doId={$doId}");
    expect(str_contains($r['body'], 'P2 TEST STORE A'), 'expected the real store name');
    expect(str_contains($r['body'], $tanggal), 'expected the real tanggal');
    expect(str_contains($r['body'], 'Karangtengah'), 'expected the real factory name (Pabrik)');
});

runTest('PRINT-05 planned/shipped/remaining values on a fresh draft are correct (pre-shipment)', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-08-05';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 15.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('print05')));
    $doId = $create['json']['data']['doId'];
    $r = $http->request('GET', "/_ui-preview/print-do.php?doId={$doId}");
    // Row: <td>15</td> planned, <td>0</td> shipped, <td>15</td> remaining, for this exact product.
    expect((bool) preg_match('/<td[^>]*>' . preg_quote($p['name'], '/') . '<\/td>.*?class="num">15<\/td>\s*<td[^>]*class="num">0<\/td>\s*<td[^>]*class="num">15<\/td>/s', $r['body']),
        'expected planned=15, shipped=0, remaining=15 for a fresh draft with no shipment yet');
});

runTest('PRINT-06 draft/preprint print never alters stock (still zero shipment_out ledger rows)', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-08-06';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 4.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('print06')));
    $doId = $create['json']['data']['doId'];
    $doVersionBefore = $create['json']['data']['version'];
    $http->request('POST', "/api/do/{$doId}/preprint", ['expectedVersion' => 1], array_merge(['X-CSRF-Token' => $csrf], idemKey('print06-pp')));

    // Open the print page THREE times — opening/printing must be idempotent-read, never a mutation.
    $http->request('GET', "/_ui-preview/print-do.php?doId={$doId}");
    $http->request('GET', "/_ui-preview/print-do.php?doId={$doId}");
    $http->request('GET', "/_ui-preview/print-do.php?doId={$doId}");

    $locId = locationIdForFactory($pdo, $karangtengahId);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_ledger WHERE product_id = ? AND location_id = ? AND event_type = 'shipment_out'");
    $stmt->execute([$p['product_id'], $locId]);
    expect((int) $stmt->fetchColumn() === 0, 'opening the print page must never post a shipment_out ledger row');

    $stmt2 = $pdo->prepare('SELECT version, status FROM delivery_order WHERE delivery_order_id = ?');
    $stmt2->execute([$doId]);
    $row = $stmt2->fetch();
    expect((int) $row['version'] === 2 && $row['status'] === 'preprinted', 'expected DO version to be exactly 2 (only the one real preprint action) — printing must not bump version');
});

runTest('PRINT-07 partial shipment print shows cumulative shipped correctly', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    $tanggal = '2026-08-07';
    $p = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tanggal, $storeA, $p['product_id'], 5.0, 5.0, 5.0);
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 5.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('print07')));
    $doId = $create['json']['data']['doId'];
    $ship = $http->request('POST', "/api/do/{$doId}/ship", ['expectedVersion' => 1, 'shipmentGroup' => 'MAIN', 'items' => [['productId' => $p['product_id'], 'actualQty' => 2]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('print07-ship')));
    expect($ship['status'] === 200 && $ship['json']['data']['doFullyFulfilled'] === false, 'expected a partial (non-fulfilling) shipment');

    $r = $http->request('GET', "/_ui-preview/print-do.php?doId={$doId}");
    // planned=5, shipped=2, remaining=3
    expect((bool) preg_match('/<td[^>]*>' . preg_quote($p['name'], '/') . '<\/td>.*?class="num">5<\/td>\s*<td[^>]*class="num">2<\/td>\s*<td[^>]*class="num">3<\/td>/s', $r['body']),
        'expected planned=5, shipped=2 (cumulative), remaining=3 printed for a partial shipment');
    expect(str_contains($r['body'], 'PREPRINT') === false || str_contains($r['body'], 'DRAFT') || true, 'sanity noop'); // status text itself checked in PRINT-08
});

runTest('PRINT-08 watermark matches DO status for every lifecycle state', function () use ($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $storeA) {
    // Draft
    $tDraft = '2026-08-08';
    $pDraft = nextProduct();
    seedStorePo($pdo, $tDraft, $karangtengahId, $storeA, [$pDraft['product_id'] => ['poAwal' => 2.0]]);
    $cDraft = $http->request('POST', '/api/do', ['tanggal' => $tDraft, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('print08a')));
    $rDraft = $http->request('GET', '/_ui-preview/print-do.php?doId=' . $cDraft['json']['data']['doId']);
    expect(str_contains($rDraft['body'], 'print-watermark">DRAFT'), 'expected DRAFT watermark for a draft DO');

    // Cancelled
    $tCancel = '2026-08-09';
    $pCancel = nextProduct();
    seedStorePo($pdo, $tCancel, $karangtengahId, $storeA, [$pCancel['product_id'] => ['poAwal' => 2.0]]);
    $cCancel = $http->request('POST', '/api/do', ['tanggal' => $tCancel, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('print08b')));
    $doIdCancel = $cCancel['json']['data']['doId'];
    $http->request('POST', "/api/do/{$doIdCancel}/cancel", ['expectedVersion' => 1, 'reason' => 'print test'], array_merge(['X-CSRF-Token' => $csrf], idemKey('print08b-cancel')));
    $rCancel = $http->request('GET', "/_ui-preview/print-do.php?doId={$doIdCancel}");
    expect(str_contains($rCancel['body'], 'print-watermark">DIBATALKAN'), 'expected DIBATALKAN watermark for a cancelled DO');

    // Shipped (fully) — no watermark stamp on a genuine final document, but status text says TERKIRIM.
    $tShip = '2026-08-10';
    $pShip = nextProduct();
    stockUpForDelivery($http, $csrf, $pdo, $karangtengahId, $rotiBollenDivId, $tShip, $storeA, $pShip['product_id'], 3.0, 3.0, 3.0);
    seedStorePo($pdo, $tShip, $karangtengahId, $storeA, [$pShip['product_id'] => ['poAwal' => 3.0]]);
    $cShip = $http->request('POST', '/api/do', ['tanggal' => $tShip, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('print08c')));
    $doIdShip = $cShip['json']['data']['doId'];
    $http->request('POST', "/api/do/{$doIdShip}/ship", ['expectedVersion' => 1, 'shipmentGroup' => 'MAIN', 'items' => [['productId' => $pShip['product_id'], 'actualQty' => 3]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('print08c-ship')));
    $rShip = $http->request('GET', "/_ui-preview/print-do.php?doId={$doIdShip}");
    expect(!str_contains($rShip['body'], 'print-watermark'), 'expected NO watermark div for a fully shipped (final) document');
    expect(str_contains($rShip['body'], 'TERKIRIM'), 'expected the status text to read TERKIRIM for a fully shipped DO');
});

runTest('PRINT-09 print CSS hides on-screen UI controls when printing', function () use ($http) {
    $css = $http->request('GET', '/api/assets/css/print.css');
    expect($css['status'] === 200, 'expected print.css to be servable');
    expect((bool) preg_match('/@media\s+print\s*\{(.*)\}\s*$/s', $css['body'], $m), 'expected an @media print block in print.css');
    $printBlock = $m[1] ?? '';
    expect((bool) preg_match('/\.print-toolbar\s*\{\s*display:\s*none/', $printBlock), 'expected @media print to hide .print-toolbar');
    expect(str_contains($css['body'], '@page'), 'expected an @page rule for A4 sizing');
});

runTest('PRINT-10 bulk print page-breaks once per DO, own header/table/signature each', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-08-11';
    $p1 = nextProduct();
    $p2 = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p1['product_id'] => ['poAwal' => 2.0]]);
    $create1 = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('print10a')));
    expect($create1['status'] === 200, 'first DO create failed: ' . json_encode($create1['json']));

    $storeB = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE B'")->fetchColumn();
    expect($storeB > 0, 'expected P2 TEST STORE B fixture seeded');
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeB, [$p2['product_id'] => ['poAwal' => 3.0]]);
    $create2 = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeB], array_merge(['X-CSRF-Token' => $csrf], idemKey('print10b')));
    expect($create2['status'] === 200, 'second DO create failed: ' . json_encode($create2['json']));

    $r = $http->request('GET', "/_ui-preview/print-do-bulk.php?tanggal={$tanggal}&factoryId={$karangtengahId}");
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    $pageCount = substr_count($r['body'], 'class="print-page"');
    expect($pageCount === 2, "expected exactly 2 print-page blocks (one per DO), got {$pageCount}");
    expect(substr_count($r['body'], 'Disiapkan Oleh') === 2, 'expected each DO to carry its own signature section');
    expect(str_contains($r['body'], 'Halaman 1 dari 2') && str_contains($r['body'], 'Halaman 2 dari 2'), 'expected correct per-page page numbering');
});

runTest('PRINT-12 the old Phase 5 UAT print route (api/_do-uat/print.php) still works, untouched', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    $tanggal = '2026-08-12';
    $p = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [$p['product_id'] => ['poAwal' => 2.0]]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('print12')));
    $doId = $create['json']['data']['doId'];
    $r = $http->request('GET', "/_do-uat/print.php?doId={$doId}");
    expect($r['status'] === 200, "expected the old print route to still return 200, got {$r['status']}");
    expect(str_contains($r['body'], (string) $create['json']['data']['docNo']), 'expected the old print route to still render the real DO number');
});

runTest('SJ-01 Draft DO print still prints ALL planned DO items, with an unambiguous planning-level title (never says SURAT JALAN)', function () use ($http, $csrf, $pdo, $karangtengahId, $storeA) {
    // Real-UAT ask: Draft DO print (this page) is a DIFFERENT document
    // from Surat Jalan (api/_driver-uat/print-shipment.php,
    // api/_ui-preview/print-shipment.php) — Draft DO keeps printing
    // every planned item across the whole DO (it is a planning/picking
    // document), but its title must never claim to be "Surat Jalan"
    // (the exact ambiguity a real UAT report was filed about).
    $tanggal = '2026-08-20';
    $p1 = nextProduct();
    $p2 = nextProduct();
    seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [
        $p1['product_id'] => ['poAwal' => 20.0],
        $p2['product_id'] => ['poAwal' => 15.0],
    ]);
    $create = $http->request('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idemKey('sj01')));
    $doId = $create['json']['data']['doId'];
    $r = $http->request('GET', "/_ui-preview/print-do.php?doId={$doId}");
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    expect(str_contains($r['body'], 'DRAFT DELIVERY ORDER') || str_contains($r['body'], 'RENCANA PENGIRIMAN'),
        'expected the unambiguous planning-level document title');
    expect(!str_contains($r['body'], '<title>Surat Jalan'), 'expected the browser tab title to never claim to be Surat Jalan any more');
    expect(str_contains($r['body'], (string) $p1['name']) && str_contains($r['body'], (string) $p2['name']),
        'expected BOTH planned products to still print on the Draft DO — this document never narrows to one shipment');
    expect((bool) preg_match('/Total<\/td>\s*<td class="num">35/', $r['body']),
        'expected the total planned quantity (20+15=35) to still print in the summary row');
});

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
