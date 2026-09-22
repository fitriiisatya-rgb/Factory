<?php

declare(strict_types=1);

/**
 * Migration 0011 — Task per Divisi + Production Actual/Reject integration
 * suite (TASK-01..24). Run via api/tests/run-production-task.sh, which
 * stands up a disposable local MariaDB, applies migrations 0001-0011,
 * bootstraps realistic master data, then drives the real
 * /api/production-tasks + /api/production + /api/special-orders JSON
 * APIs end to end against a live `php -S` server, plus the two new print
 * pages via real HTTP GET. TASK-25 (full regression green) is NOT a test
 * in this file — it is the orchestrator's own final step, same pattern
 * as every prior phase's own suite.
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8110';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'task_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpTask
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'taskcookies');
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

/**
 * json_decode() gives a bare int for a whole-number JSON value (PHP's
 * json_encode() drops the trailing ".0" for a whole-number float) — a
 * strict `$decoded === 100.0` comparison is FALSE for a wire value of
 * `100` even though it is numerically identical. Every numeric API
 * response in this suite is compared through this helper instead of a
 * literal `===` against a float, so that decode quirk never masquerades
 * as a real product bug.
 */
function numEq(mixed $actual, float $expected, float $eps = 0.001): bool
{
    return is_numeric($actual) && abs((float) $actual - $expected) < $eps;
}

function login(HttpTask $http, string $username, string $password): string
{
    $r = $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    expect($r['status'] === 200, "login failed for {$username}: " . json_encode($r['json']));
    return $r['json']['data']['csrfToken'];
}

function idemKey(string $tag): array
{
    return ['Idempotency-Key' => 'task-test-' . $tag . '-' . bin2hex(random_bytes(6))];
}

$pdo = new PDO(
    "mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4",
    getenv('TEST_RUNTIME_USER') ?: null,
    getenv('TEST_RUNTIME_PASS') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$adminHttp = new HttpTask($baseUrl);
$adminCsrf = login($adminHttp, $adminUser, $adminPass);

$tanggal = '2026-09-22';
$emptyTanggal = '2030-01-01'; // deliberately far away — no data ever seeded for it

$storeAId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
expect($storeAId > 0, 'expected P2 TEST STORE A seeded');

$karangtengahFactoryId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
$cibadakFactoryId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Cibadak'")->fetchColumn();
expect($karangtengahFactoryId > 0 && $cibadakFactoryId > 0, 'expected both factories seeded');

$rotiBollenDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
expect($rotiBollenDivId > 0, 'expected Roti & Bollen division seeded');
$rotiBollenProduct = $pdo->query("SELECT product_id, name FROM product WHERE division_id = {$rotiBollenDivId} AND aktif = 1 LIMIT 1")->fetch();
expect($rotiBollenProduct !== false, 'expected at least one active Roti & Bollen product');
$rotiBollenProductId = (int) $rotiBollenProduct['product_id'];

$boluDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Bolu'")->fetchColumn();
expect($boluDivId > 0, 'expected Bolu division seeded');
$boluProductId = (int) $pdo->query("SELECT product_id FROM product WHERE division_id = {$boluDivId} AND aktif = 1 LIMIT 1")->fetchColumn();
expect($boluProductId > 0, 'expected at least one active Bolu product');

// --- PO Reguler fixture: direct SQL, exactly as PoImporter would leave
// it after a real import (po_item.po_awal is ALREADY the cross-store
// aggregate — see ProductionTargetService, which never reads
// po_store_item at all). Never goes through the CSV-import HTTP flow
// itself (already covered exhaustively by Phase2POTest.php) — this
// suite only needs a realistic, already-aggregated PO target to prove
// Task per Divisi surfaces it correctly.
$pdo->exec("INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES ('{$tanggal}', {$karangtengahFactoryId}, 1, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE version = version");
$poBatchId = (int) $pdo->query("SELECT po_batch_id FROM po_batch WHERE tanggal = '{$tanggal}' AND factory_id = {$karangtengahFactoryId}")->fetchColumn();
$pdo->exec("INSERT INTO po_item (po_batch_id, product_id, po_awal, po_revisi, pb) VALUES ({$poBatchId}, {$rotiBollenProductId}, 120, 0, 0)
            ON DUPLICATE KEY UPDATE po_awal = 120, po_revisi = 0");

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

// --- TASK-01: page renders ---------------------------------------------

runTest('TASK-01 Task per Divisi tab renders', function () use ($adminHttp, $tanggal, $karangtengahFactoryId, $rotiBollenDivId) {
    $r = $adminHttp->request('GET', "/_ui-preview/?page=produksi-task-per-divisi&tanggal={$tanggal}&factoryId={$karangtengahFactoryId}&divisionId={$rotiBollenDivId}");
    expect($r['status'] === 200, 'TASK-01: expected 200');
    expect(str_contains($r['body'], 'Task per Divisi'), 'TASK-01: expected the page to render "Task per Divisi"');
});

// --- TASK-02: filters ----------------------------------------------------

runTest('TASK-02 filter date/factory/division works', function () use ($adminHttp, $tanggal, $emptyTanggal, $rotiBollenDivId) {
    $r = $adminHttp->request('GET', "/api/production-tasks?tanggal={$tanggal}&divisionId={$rotiBollenDivId}");
    expect($r['status'] === 200, 'TASK-02: expected 200 for the real date: ' . json_encode($r['json']));
    expect($r['json']['data']['divisionName'] === 'Roti & Bollen', 'TASK-02: expected divisionName=Roti & Bollen');

    $rEmpty = $adminHttp->request('GET', "/api/production-tasks?tanggal={$emptyTanggal}&divisionId={$rotiBollenDivId}");
    expect($rEmpty['status'] === 200, 'TASK-02: expected 200 for an empty date (no data is not an error)');
    expect($rEmpty['json']['data']['tasks'] === [], 'TASK-02: expected zero tasks for a date with no data');
});

// --- TASK-03: PO Reguler demand appears + TASK-07/09 aggregation/target -

runTest('TASK-03/07/09 PO Reguler demand appears as exactly ONE already-aggregated row with the correct target', function () use ($adminHttp, $tanggal, $rotiBollenDivId, $rotiBollenProduct) {
    $r = $adminHttp->request('GET', "/api/production-tasks?tanggal={$tanggal}&divisionId={$rotiBollenDivId}");
    expect($r['status'] === 200, 'TASK-03: expected 200');
    $poRows = array_values(array_filter($r['json']['data']['tasks'], fn ($t) => $t['source'] === 'po_reguler' && $t['taskName'] === $rotiBollenProduct['name']));
    expect(count($poRows) === 1, 'TASK-07: expected exactly ONE PO Reguler row for this product (already-aggregated, never duplicated), got ' . count($poRows));
    expect(abs($poRows[0]['target'] - 120.0) < 0.001, 'TASK-09: expected PO Reguler target=120 (po_awal+po_revisi), got ' . $poRows[0]['target']);
    expect($poRows[0]['editable'] === false, 'TASK-24: expected a PO Reguler row to be read-only in Task per Divisi (no duplicate data-entry source)');
});

// --- TASK-04/05/06/08/09/18: special-order rows -------------------------

$khususOrderId = null;
$khususItemAId = null;
$khususItemBId = null;
runTest('TASK-04/08/09/18 Pesanan Khusus demand appears, stays separate per distinct note, target/notes correct', function () use ($adminHttp, $adminCsrf, $storeAId, $tanggal, $rotiBollenProductId, &$khususOrderId, &$khususItemAId, &$khususItemBId) {
    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeAId, 'orderDate' => $tanggal, 'requiredDate' => $tanggal,
        'items' => [
            ['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 8, 'specialNote' => 'Untuk event internal'],
            ['itemType' => 'existing_product', 'productId' => $rotiBollenProductId, 'qty' => 3, 'specialNote' => 'Packing terpisah'],
        ],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('task04create')));
    expect($r['status'] === 200, 'TASK-04: expected 200: ' . json_encode($r['json']));
    $order = $r['json']['data'];
    $khususOrderId = $order['orderId'];
    $khususItemAId = $order['items'][0]['itemId'];
    $khususItemBId = $order['items'][1]['itemId'];

    $confirm = $adminHttp->request('POST', "/api/special-orders/{$khususOrderId}/confirm", ['expectedVersion' => $order['version']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('task04confirm')));
    expect($confirm['status'] === 200, 'TASK-04: expected confirm 200');
    $send = $adminHttp->request('POST', "/api/special-orders/{$khususOrderId}/send-to-production", ['expectedVersion' => $confirm['json']['data']['version']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('task04send')));
    expect($send['status'] === 200, 'TASK-04: expected send-to-production 200');

    $tasksR = $adminHttp->request('GET', "/api/production-tasks?tanggal={$tanggal}&divisionId=" . $order['items'][0]['divisionId']);
    $khususRows = array_values(array_filter($tasksR['json']['data']['tasks'], fn ($t) => $t['source'] === 'pesanan_khusus'));
    expect(count($khususRows) === 2, 'TASK-08: expected TWO separate task rows (same product, different Catatan Khusus, never merged), got ' . count($khususRows));
    $notes = array_column($khususRows, 'catatanKhusus');
    sort($notes);
    expect($notes === ['Packing terpisah', 'Untuk event internal'], 'TASK-18: expected both distinct special notes visible, got ' . json_encode($notes));
    foreach ($khususRows as $row) {
        expect($row['editable'] === true, 'TASK-04: expected a Pesanan Khusus row to be editable here (its only home for actual/reject entry)');
    }
    $targets = array_map('floatval', array_column($khususRows, 'target'));
    sort($targets);
    expect($targets === [3.0, 8.0], 'TASK-09: expected targets [3,8] from qty, got ' . json_encode($targets));
});

$nonTokoOrderId = null;
runTest('TASK-05/06 Pesanan Non-Toko demand appears with correct source traceability', function () use ($adminHttp, $adminCsrf, $tanggal, $rotiBollenDivId, &$nonTokoOrderId) {
    $catalog = $adminHttp->request('GET', '/api/special-orders/catalog');
    $catalogId = $catalog['json']['data'][0]['catalogId'];

    $r = $adminHttp->request('POST', '/api/special-orders', [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'cs', 'customerName' => 'Pelanggan Task Test',
        'orderDate' => $tanggal, 'requiredDate' => $tanggal,
        'items' => [['itemType' => 'special_catalog', 'specialCatalogId' => $catalogId, 'qty' => 1, 'specialNote' => 'Tema Spiderman, tulisan HBD Raka']],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('task05create')));
    expect($r['status'] === 200, 'TASK-05: expected 200: ' . json_encode($r['json']));
    $order = $r['json']['data'];
    $nonTokoOrderId = $order['orderId'];

    $confirm = $adminHttp->request('POST', "/api/special-orders/{$nonTokoOrderId}/confirm", ['expectedVersion' => $order['version']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('task05confirm')));
    $send = $adminHttp->request('POST', "/api/special-orders/{$nonTokoOrderId}/send-to-production", ['expectedVersion' => $confirm['json']['data']['version']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('task05send')));
    expect($send['status'] === 200, 'TASK-05: expected send-to-production 200');

    $divisionId = $order['items'][0]['divisionId'];
    $tasksR = $adminHttp->request('GET', "/api/production-tasks?tanggal={$tanggal}&divisionId={$divisionId}");
    $nonTokoRows = array_values(array_filter($tasksR['json']['data']['tasks'], fn ($t) => $t['source'] === 'pesanan_non_toko'));
    expect(count($nonTokoRows) === 1, 'TASK-05: expected exactly 1 Pesanan Non-Toko row');
    expect(str_contains((string) $nonTokoRows[0]['reference'], $order['orderNo']), 'TASK-06: expected the task reference to include the order number for traceability');
    expect(str_contains((string) $nonTokoRows[0]['reference'], 'Pelanggan Task Test'), 'TASK-06: expected the task reference to include the customer name');
});

// --- TASK-10/11/12/13/14/15/23: PO Reguler actual/reject/sisa/status ----

function findPoRowByName(array $tasks, string $name): array
{
    foreach ($tasks as $t) {
        if ($t['source'] === 'po_reguler' && $t['taskName'] === $name) {
            return $t;
        }
    }
    throw new RuntimeException("PO Reguler row for '{$name}' not found");
}

$rotiBollenRunId = null;
$rotiBollenRunVersion = null;
runTest('TASK-15a status=Belum Diproduksi when actual=0', function () use ($adminHttp, $adminCsrf, $tanggal, $rotiBollenDivId, $rotiBollenProduct, &$rotiBollenRunId, &$rotiBollenRunVersion) {
    $create = $adminHttp->request('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiBollenDivId], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('task15create')));
    expect($create['status'] === 200, 'TASK-15a: expected 200: ' . json_encode($create['json']));
    $rotiBollenRunId = $create['json']['data']['productionRunId'];
    $rotiBollenRunVersion = $create['json']['data']['version'];

    $tasksR = $adminHttp->request('GET', "/api/production-tasks?tanggal={$tanggal}&divisionId={$rotiBollenDivId}");
    $row = findPoRowByName($tasksR['json']['data']['tasks'], $rotiBollenProduct['name']);
    expect($row['statusCode'] === 'belum_diproduksi', 'TASK-15a: expected belum_diproduksi, got ' . $row['statusCode']);
    expect(numEq($row['aktual'], 0.0), 'TASK-10a: expected aktual=0 initially');
});

runTest('TASK-10b/11/12/13/15b actual=partial: Actual=good only, Reject separate, Sisa=Target-Actual (never Target-(Actual+Reject)), status=Belum Selesai', function () use ($adminHttp, $adminCsrf, $tanggal, $rotiBollenDivId, $rotiBollenProduct, $rotiBollenProductId, &$rotiBollenRunId, &$rotiBollenRunVersion) {
    $patch = $adminHttp->request('PATCH', "/api/production/{$rotiBollenRunId}", [
        'expectedVersion' => $rotiBollenRunVersion,
        'items' => [['productId' => $rotiBollenProductId, 'actualQty' => 100, 'rejectQty' => 5]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('task15b')));
    expect($patch['status'] === 200, 'TASK-10b: expected PATCH 200: ' . json_encode($patch['json']));
    $rotiBollenRunVersion = $patch['json']['data']['version'];

    $tasksR = $adminHttp->request('GET', "/api/production-tasks?tanggal={$tanggal}&divisionId={$rotiBollenDivId}");
    $row = findPoRowByName($tasksR['json']['data']['tasks'], $rotiBollenProduct['name']);
    expect(numEq($row['aktual'], 100.0), 'TASK-10b: expected aktual=100 (good output only), got ' . $row['aktual']);
    expect(numEq($row['reject'], 5.0), 'TASK-11: expected reject=5 tracked separately, got ' . $row['reject']);
    expect(numEq($row['sisa'], 20.0), "TASK-12/13: expected sisa=Target(120)-Actual(100)=20 (NOT 120-(100+5)=15), got {$row['sisa']}");
    expect($row['statusCode'] === 'belum_selesai', 'TASK-15b: expected belum_selesai, got ' . $row['statusCode']);
});

runTest('TASK-15c/23 actual=target: status=Selesai, and the SAME number Ceklis Produksi itself shows (authoritative, no duplicate source)', function () use ($adminHttp, $adminCsrf, $tanggal, $rotiBollenDivId, $rotiBollenProduct, $rotiBollenProductId, &$rotiBollenRunId, &$rotiBollenRunVersion) {
    $patch = $adminHttp->request('PATCH', "/api/production/{$rotiBollenRunId}", [
        'expectedVersion' => $rotiBollenRunVersion,
        'items' => [['productId' => $rotiBollenProductId, 'actualQty' => 120, 'rejectQty' => 5]],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('task15c')));
    expect($patch['status'] === 200, 'TASK-15c: expected PATCH 200');
    $rotiBollenRunVersion = $patch['json']['data']['version'];

    // The SAME production run, read directly via Ceklis Produksi's own
    // GET /api/production/{id} — must show the identical actual value
    // Task per Divisi just displayed (TASK-23: existing Production
    // actual remains the one authoritative source, never a second copy).
    $runR = $adminHttp->request('GET', "/api/production/{$rotiBollenRunId}");
    $ceklisActual = null;
    foreach ($runR['json']['data']['items'] as $it) {
        if ((int) $it['productId'] === $rotiBollenProductId) {
            $ceklisActual = $it['actual'];
        }
    }

    $tasksR = $adminHttp->request('GET', "/api/production-tasks?tanggal={$tanggal}&divisionId={$rotiBollenDivId}");
    $row = findPoRowByName($tasksR['json']['data']['tasks'], $rotiBollenProduct['name']);
    expect($row['statusCode'] === 'selesai', 'TASK-15c: expected selesai when actual>=target, got ' . $row['statusCode']);
    expect($row['aktual'] === $ceklisActual, "TASK-23: expected Task per Divisi's aktual ({$row['aktual']}) to exactly match Ceklis Produksi's own aktual ({$ceklisActual}) — same authoritative source");
});

// --- TASK-14: progress % ------------------------------------------------

runTest('TASK-14 progress uses Total Actual / Total Target (reject excluded)', function () use ($adminHttp, $tanggal, $rotiBollenDivId) {
    $tasksR = $adminHttp->request('GET', "/api/production-tasks?tanggal={$tanggal}&divisionId={$rotiBollenDivId}");
    $summary = $tasksR['json']['data']['summary'];
    $expectedPct = $summary['totalTarget'] > 0 ? round(($summary['totalAktual'] / $summary['totalTarget']) * 100, 1) : 0.0;
    expect(abs($summary['progressPct'] - $expectedPct) < 0.01, "TASK-14: expected progressPct={$expectedPct}, got {$summary['progressPct']}");
});

// --- TASK-16/17: factory/division routing -------------------------------

runTest('TASK-16 Bolu appears only under Cibadak', function () use ($adminHttp, $tanggal, $boluDivId, $cibadakFactoryId) {
    $r = $adminHttp->request('GET', "/api/production-tasks?tanggal={$tanggal}&divisionId={$boluDivId}");
    expect($r['status'] === 200, 'TASK-16: expected 200');
    expect($r['json']['data']['factoryId'] === $cibadakFactoryId, 'TASK-16: expected Bolu division to resolve to the Cibadak factory');
    expect($r['json']['data']['factoryName'] === 'Cibadak', 'TASK-16: expected factoryName=Cibadak');
});

runTest('TASK-17 Non-Bolu (Roti & Bollen) appears under Karangtengah', function () use ($adminHttp, $tanggal, $rotiBollenDivId, $karangtengahFactoryId) {
    $r = $adminHttp->request('GET', "/api/production-tasks?tanggal={$tanggal}&divisionId={$rotiBollenDivId}");
    expect($r['status'] === 200, 'TASK-17: expected 200');
    expect($r['json']['data']['factoryId'] === $karangtengahFactoryId, 'TASK-17: expected Roti & Bollen division to resolve to the Karangtengah factory');
    expect($r['json']['data']['factoryName'] === 'Karangtengah', 'TASK-17: expected factoryName=Karangtengah');
});

// --- TASK-19/20/21/22: print pages ---------------------------------------

runTest('TASK-19/21 Print Divisi Ini works and is A4 landscape', function () use ($adminHttp, $tanggal, $rotiBollenDivId) {
    $r = $adminHttp->request('GET', "/_ui-preview/print-production-task.php?tanggal={$tanggal}&divisionId={$rotiBollenDivId}");
    expect($r['status'] === 200, 'TASK-19: expected 200: ' . substr($r['body'], 0, 300));
    expect(str_contains($r['body'], 'Production Task'), 'TASK-19: expected the print document title');
    expect(str_contains($r['body'], 'Roti &amp; Bollen') || str_contains($r['body'], 'Roti & Bollen'), 'TASK-19: expected the division name in the print header');
    expect(str_contains($r['body'], 'size: A4 landscape'), 'TASK-21: expected an A4 landscape @page override for readability with 9 columns');
});

runTest('TASK-20 Print Semua Divisi works (one division per page)', function () use ($adminHttp, $tanggal, $karangtengahFactoryId, $pdo) {
    $r = $adminHttp->request('GET', "/_ui-preview/print-production-task-bulk.php?tanggal={$tanggal}&factoryId={$karangtengahFactoryId}");
    expect($r['status'] === 200, 'TASK-20: expected 200');
    $divisionCount = (int) $pdo->query("SELECT COUNT(*) FROM division WHERE factory_id = {$karangtengahFactoryId} AND is_verification = 0")->fetchColumn();
    $pageCount = substr_count($r['body'], 'class="print-page"');
    expect($pageCount === $divisionCount, "TASK-20: expected exactly {$divisionCount} print-page divs (one per division), got {$pageCount}");
});

runTest('TASK-22 print output is printer-friendly (uses print.css, not the dark app shell)', function () use ($adminHttp, $tanggal, $rotiBollenDivId) {
    $r = $adminHttp->request('GET', "/_ui-preview/print-production-task.php?tanggal={$tanggal}&divisionId={$rotiBollenDivId}");
    expect(str_contains($r['body'], '/api/assets/css/print.css'), 'TASK-22: expected the print stylesheet, not the dark app shell CSS');
    expect(!str_contains($r['body'], 'class="app-shell"'), 'TASK-22: expected NO dark navy Admin shell markup on a print page');
});

// TASK-25 (full regression green) is NOT a test in this file — it is the
// orchestrator's (run-production-task.sh) own final step.

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
