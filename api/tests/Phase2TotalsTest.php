<?php

declare(strict_types=1);

/**
 * Phase 2 PO totals regression suite (P2-TOTAL01..P2-TOTAL08) — added
 * after a real cPanel UAT upload showed "Target hasil = 12,930" for a PO
 * AWAL upload, when only the PO Awal total should have been shown/
 * committed. Root cause: PoMerger::mergeInitial() passed the file's own
 * PO Revisi column values straight through into what gets committed AND
 * previewed, because a genuine real-world Karangtengah file can carry PO
 * Awal + PO Revisi + PB in the very same sheet even when the operator is
 * uploading it as "PO Awal" — see PoMerger::mergeInitial()'s own comment
 * for the full story. Fixed there; this suite proves the fix and guards
 * against it regressing.
 *
 * The exact historical reference figures from the task (PO Awal=9,533,
 * PO Tambahan=58, final target=9,591, PB=5,920) are NOT hardcoded into
 * application logic anywhere — they are only reproduced here as a
 * synthetic fixture's own engineered totals (no real source file was
 * available), so this suite doubles as a literal regression anchor for
 * those specific reference numbers without the app itself knowing them.
 *
 * Runs in the same disposable environment as Phase2POTest.php (appended
 * to run-phase2-po.sh) — same server, same bootstrapped master data, own
 * dedicated dates/products so it never collides with the other suites.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8097';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'p2_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpTotals
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'totalscookies');
    }

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
        return ['status' => $status, 'json' => $json];
    }
}

function expect(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

/** @param array<int,array<int,mixed>> $matrix */
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

function karangtengahHeader(): array
{
    // Three TOTAL blocks: PO Awal / PO Revisi / PB — exactly the real
    // layout that exposed the bug (both PO Awal and PO Revisi populated
    // in the very same sheet).
    return [
        ['NO', 'KATEGORI', 'KODE', 'NAMA PRODUK', 'TTA', 'TTB', 'TOTAL', 'TTA', 'TTB', 'TOTAL', 'TTA', 'TTB', 'TOTAL'],
        ['', '', '', '', 'TTA', 'TTB', '', 'TTA', 'TTB', '', 'TTA', 'TTB', ''],
    ];
}

function karangtengahRow(string $kode, string $nama, float $awalA, float $awalB, float $revA, float $revB, float $pbA, float $pbB): array
{
    return [1, 'TEST', $kode, $nama, $awalA, $awalB, $awalA + $awalB, $revA, $revB, $revA + $revB, $pbA, $pbB, $pbA + $pbB];
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

// Dedicated stores + products for this suite alone (never touched by any
// other Phase 2 test file), so totals are exact and never polluted by
// state other suites left behind.
$pdo->prepare("INSERT INTO store (canonical_name, channel, active, version, created_at) VALUES ('P2 TOTALS STORE A', NULL, 1, 1, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE canonical_name = VALUES(canonical_name)")->execute();
$pdo->prepare("INSERT INTO store (canonical_name, channel, active, version, created_at) VALUES ('P2 TOTALS STORE B', NULL, 1, 1, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE canonical_name = VALUES(canonical_name)")->execute();
$storeAId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TOTALS STORE A'")->fetchColumn();
$storeBId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TOTALS STORE B'")->fetchColumn();
$pdo->prepare("INSERT INTO store_alias (store_id, raw_name, factory_hint, created_at) VALUES (?, 'TTA', NULL, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE store_id = store_id")->execute([$storeAId]);
$pdo->prepare("INSERT INTO store_alias (store_id, raw_name, factory_hint, created_at) VALUES (?, 'TTB', NULL, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE store_id = store_id")->execute([$storeBId]);

$catalogRows = $pdo->query(
    "SELECT p.product_id, p.name, plc.legacy_code FROM product p
     INNER JOIN product_legacy_code plc ON plc.product_id = p.product_id
     ORDER BY p.product_id ASC LIMIT 20 OFFSET 6" // distinct slice, avoids every other Phase 2 suite's picks
)->fetchAll();
expect(count($catalogRows) >= 3, 'expected at least 3 unused katalog products for this suite');
[$p1, $p2, $p3] = $catalogRows;

$http = new HttpTotals($baseUrl);
$http->request('POST', '/api/auth/login', ['username' => $adminUser, 'password' => $adminPass]);
$me = $http->request('GET', '/api/auth/me');
$csrf = $me['json']['data']['csrfToken'] ?? null;
expect($csrf !== null, 'expected a csrf token after admin login');

$tanggal = '2026-03-01';

// Engineered fixture whose product-level totals reproduce the task's own
// historical reference figures exactly (9,533 / 58 / 9,591 / 5,920) — see
// the file header comment for why this is safe (never hardcoded into the
// app itself, only into this test's own input data).
// P1: awal 6,000+3,533=9,533 partial... see per-row breakdown below.
$fixtureRows = [
    karangtengahRow($p1['legacy_code'], $p1['name'], 3000, 1000, 30, 0, 3000, 0),  // awal 4000, rev 30, pb 3000
    karangtengahRow($p2['legacy_code'], $p2['name'], 2000, 1533, 0, 28, 2000, 0),  // awal 3533, rev 28, pb 2000
    karangtengahRow($p3['legacy_code'], $p3['name'], 1000, 1000, 0, 0, 920, 0),    // awal 2000, rev 0,  pb 920
];
// Totals: awal = 4000+3533+2000 = 9533. revisi = 30+28+0 = 58. pb = 3000+2000+920 = 5920.
$initialCsv = buildCsvBase64([...karangtengahHeader(), ...$fixtureRows]);

runTest('P2-TOTAL01 PO Awal mode excludes po_revisi from what is committed', function () use ($http, $csrf, $tanggal, $initialCsv) {
    $r = $http->request('POST', '/api/po/preview', ['tanggal' => $tanggal, 'uploadType' => 'initial', 'fileName' => 'total1.csv', 'fileContentBase64' => $initialCsv], ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, 'preview failed: ' . json_encode($r['json']));
    $d = $r['json']['data'];
    expect((float) $d['committedPoRevisi'] === 0.0, 'PO Awal mode must never commit any po_revisi, got ' . $d['committedPoRevisi']);
});

runTest('P2-TOTAL02 PO Awal mode excludes PB entirely from the committed/target total', function () use ($http, $csrf, $tanggal, $initialCsv) {
    $r = $http->request('POST', '/api/po/preview', ['tanggal' => $tanggal, 'uploadType' => 'initial', 'fileName' => 'total2.csv', 'fileContentBase64' => $initialCsv], ['X-CSRF-Token' => $csrf]);
    $d = $r['json']['data'];
    expect((float) $d['totalPb'] === 5920.0, 'expected raw PB total 5920 (informational only), got ' . $d['totalPb']);
    expect((float) $d['committedPoAwal'] === 9533.0, 'PB must never leak into the committed PO Awal total, expected 9533, got ' . $d['committedPoAwal']);
    expect((float) $d['targetTotal'] === 9533.0, "PB (5920) must never be added into the PO Awal target, expected 9533, got {$d['targetTotal']}");
});

runTest('P2-TOTAL03 PO Awal expected total is exactly 9533 for the fixture (matches the task\'s own reference figure)', function () use ($http, $csrf, $tanggal, $initialCsv) {
    $r = $http->request('POST', '/api/po/preview', ['tanggal' => $tanggal, 'uploadType' => 'initial', 'fileName' => 'total3.csv', 'fileContentBase64' => $initialCsv], ['X-CSRF-Token' => $csrf]);
    $d = $r['json']['data'];
    expect((float) $d['totalPoAwal'] === 9533.0, 'expected raw PO Awal total 9533, got ' . $d['totalPoAwal']);
    expect((float) $d['committedPoAwal'] === 9533.0, 'expected committed PO Awal total 9533, got ' . $d['committedPoAwal']);
    expect((float) $d['targetTotal'] === 9533.0, "expected PO Awal preview target to be exactly PO Awal (9533), got {$d['targetTotal']} (this is the exact bug reported on real cPanel UAT — 12,930 instead of ~9,533)");

    // Actually commit it — subsequent P2-TOTAL tests build on this baseline.
    $r2 = $http->request('POST', '/api/po/import', ['tanggal' => $tanggal, 'uploadType' => 'initial', 'fileName' => 'total3.csv', 'fileContentBase64' => $initialCsv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-total03-' . uniqid()]);
    expect($r2['status'] === 200, 'commit failed: ' . json_encode($r2['json']));
    expect((float) $r2['json']['data']['targetTotal'] === 9533.0, 'expected the committed target to be exactly 9533, got ' . $r2['json']['data']['targetTotal']);
});

runTest('P2-TOTAL04 revision mode preserves the PO Awal baseline exactly (locked, not re-derived from the file)', function () use ($http, $csrf, $tanggal, $p1, $p2, $p3) {
    $revisionRows = [
        karangtengahRow($p1['legacy_code'], $p1['name'], 9999, 9999, 30, 0, 0, 0), // awal columns present but must be ignored/locked
        karangtengahRow($p2['legacy_code'], $p2['name'], 9999, 9999, 0, 28, 0, 0),
        karangtengahRow($p3['legacy_code'], $p3['name'], 9999, 9999, 0, 0, 0, 0),
    ];
    $csv = buildCsvBase64([...karangtengahHeader(), ...$revisionRows]);
    $r = $http->request('POST', '/api/po/preview', ['tanggal' => $tanggal, 'uploadType' => 'revision', 'fileName' => 'total4.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf]);
    $d = $r['json']['data'];
    expect((float) $d['committedPoAwal'] === 9533.0, "revision upload's own (deliberately wrong, 9999) PO Awal columns must never overwrite the locked baseline — expected 9533, got {$d['committedPoAwal']}");
});

runTest('P2-TOTAL05 revision total 58 gives a final target of exactly 9591', function () use ($http, $csrf, $tanggal, $p1, $p2, $p3) {
    $revisionRows = [
        karangtengahRow($p1['legacy_code'], $p1['name'], 0, 0, 30, 0, 0, 0),
        karangtengahRow($p2['legacy_code'], $p2['name'], 0, 0, 0, 28, 0, 0),
        karangtengahRow($p3['legacy_code'], $p3['name'], 0, 0, 0, 0, 0, 0),
    ];
    $csv = buildCsvBase64([...karangtengahHeader(), ...$revisionRows]);
    $r = $http->request('POST', '/api/po/import', ['tanggal' => $tanggal, 'uploadType' => 'revision', 'fileName' => 'total5.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-total05-' . uniqid()]);
    expect($r['status'] === 200, 'revision commit failed: ' . json_encode($r['json']));
    $d = $r['json']['data'];
    expect((float) $d['targetTotal'] === 9591.0, "expected final target 9591 (9533 awal + 58 revisi), got {$d['targetTotal']}");
});

runTest('P2-TOTAL06 row-level totals are not double-counted with the per-store breakdown', function () use ($pdo, $tanggal, $p1, $p2, $p3) {
    // sum(po_store_item.po_awal) for this batch must equal the row-level
    // 9533 exactly — never 2x from also adding a row-level TOTAL on top of
    // (or instead of double-adding) the per-store breakdown.
    $ids = [$p1['product_id'], $p2['product_id'], $p3['product_id']];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT SUM(si.po_awal) FROM po_store_item si INNER JOIN po_item i ON i.po_item_id = si.po_item_id
         INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id
         WHERE b.tanggal = ? AND i.product_id IN ({$in})"
    );
    $stmt->execute([$tanggal, ...$ids]);
    expect((float) $stmt->fetchColumn() === 9533.0, 'expected the sum of all po_store_item.po_awal rows to be exactly 9533, no doubling');
});

runTest('P2-TOTAL07 reloading the preview (GET) repeatedly never changes any total', function () use ($http, $csrf, $tanggal, $initialCsv) {
    $first = $http->request('POST', '/api/po/preview', ['tanggal' => $tanggal, 'uploadType' => 'initial', 'fileName' => 'total7.csv', 'fileContentBase64' => $initialCsv], ['X-CSRF-Token' => $csrf]);
    $before = $first['json']['data']['committedPoAwal'];
    for ($i = 0; $i < 3; $i++) {
        $r = $http->request('POST', '/api/po/preview', ['tanggal' => $tanggal, 'uploadType' => 'initial', 'fileName' => 'total7.csv', 'fileContentBase64' => $initialCsv], ['X-CSRF-Token' => $csrf]);
        expect((float) $r['json']['data']['committedPoAwal'] === (float) $before, 'repeated preview calls must never accumulate or change the total');
    }
});

runTest('P2-TOTAL08 resolving an unmapped product via alias does not duplicate its quantity in the total', function () use ($http, $csrf, $pdo, $p1) {
    $tgl = '2026-03-02';
    $aliasRawName = 'PRODUK ALIAS TOTALS TEST';
    $rows = [
        karangtengahRow('', $aliasRawName, 500, 0, 0, 0, 0, 0),
    ];
    // Fix the row's kode to a non-empty placeholder (Karangtengah layout
    // requires a non-empty KODE for the row to be parsed as a product row).
    $rows[0][2] = 'ALIASCODE9';
    $csv = buildCsvBase64([...karangtengahHeader(), ...$rows]);

    $preview1 = $http->request('POST', '/api/po/preview', ['tanggal' => $tgl, 'uploadType' => 'initial', 'fileName' => 'total8a.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf]);
    expect((int) $preview1['json']['data']['productResolution']['unresolved'] === 1, 'expected the row to be unresolved before an alias exists');

    $pdo->prepare("INSERT INTO product_alias (product_id, raw_name, source, created_at) VALUES (?, ?, 'test', UTC_TIMESTAMP())")->execute([$p1['product_id'], $aliasRawName]);

    $preview2 = $http->request('POST', '/api/po/preview', ['tanggal' => $tgl, 'uploadType' => 'initial', 'fileName' => 'total8b.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf]);
    $d = $preview2['json']['data'];
    expect((int) $d['productResolution']['unresolved'] === 0, 'expected the row to resolve via the alias');
    expect((float) $d['committedPoAwal'] === 500.0, "expected exactly 500 (the row's own quantity, not duplicated), got {$d['committedPoAwal']}");

    // Confirm again — must still be exactly 500, not 500+500 from any
    // double-resolution path.
    $preview3 = $http->request('POST', '/api/po/preview', ['tanggal' => $tgl, 'uploadType' => 'initial', 'fileName' => 'total8c.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf]);
    expect((float) $preview3['json']['data']['committedPoAwal'] === 500.0, 'expected the total to remain exactly 500 on a second preview, no duplication');
});

// ---------------------------------------------------------------------
$total = count($results);
$passed = count(array_filter($results));
fwrite(STDOUT, "\n{$passed}/{$total} passed\n");
exit($passed === $total ? 0 : 1);
