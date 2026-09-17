<?php

declare(strict_types=1);

/**
 * Phase 2 fast-track PO module integration test suite (P2-01 .. P2-20). Run
 * via api/tests/run-phase2-po.sh, which stands up a disposable local
 * MariaDB with TWO distinctly-privileged real DB users, bootstraps a
 * realistic post-Phase-1 state (migrations 0001+0002 applied, seeded,
 * ADMIN created, 8 divisions + 472 katalog products + BAKERY CIKOLE
 * imported), applies migration 0003 for real through the existing
 * api/_upgrade/ wizard (same mechanism already proven by the V2 test
 * suite), then drives the real PO JSON API end to end against a live
 * `php -S` server.
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;

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

final class HttpP2
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'p2cookies');
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

/** One product row, single-store (TSA) breakdown, 3 TOTAL blocks (awal/revisi/pb). */
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

$http = new HttpP2($baseUrl);
$http->request('POST', '/api/auth/login', ['username' => $adminUser, 'password' => $adminPass]);
$me = $http->request('GET', '/api/auth/me');
$csrf = $me['json']['data']['csrfToken'] ?? null;
expect($csrf !== null, 'expected a csrf token after admin login');

// ---------------------------------------------------------------------
// P2-00 (setup, not part of the required 20): apply migration 0003 for
// real through the existing api/_upgrade/ wizard — same MigrationRunner
// mechanism the V2 suite already proved end-to-end (friendly missing-
// credentials message, separate MIGRATION_DB_* connection, post-success
// reminder). Not re-tested in full here to avoid duplicating V2 coverage.
// ---------------------------------------------------------------------
runTest('P2-00 migration 0003 applied via the existing api/_upgrade/ wizard', function () use ($http, $baseUrl) {
    $page = $http->request('GET', '/_upgrade/');
    expect($page['status'] === 200, "expected 200 from _upgrade/, got {$page['status']}");
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $page['body'], $m)) {
        throw new RuntimeException('expected to find a csrf token on the upgrade page');
    }
    expect(str_contains($page['body'], '0003_po_phase2.php'), 'expected migration 0003 to be listed as pending');

    // The upgrade page is form-urlencoded, not JSON — post directly with curl,
    // reusing HttpP2's own cookie jar so the same admin session carries over.
    $refl = new ReflectionProperty(HttpP2::class, 'cookieJar');
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
        'expected migration 0003 to apply successfully: ' . substr((string) $body, 0, 500));
});

// Real product/store identities from the Phase 1 katalog already imported
// by the bootstrap script — never hardcoded assumptions about specific
// product names, queried fresh from the actual imported data.
$catalogRows = $pdo->query(
    "SELECT p.product_id, p.name, plc.legacy_code FROM product p
     INNER JOIN product_legacy_code plc ON plc.product_id = p.product_id
     ORDER BY p.product_id LIMIT 3"
)->fetchAll();
expect(count($catalogRows) >= 3, 'expected at least 3 katalog products with legacy codes after bootstrap');
[$productA, $productB, $productC] = $catalogRows;

$tanggal = '2026-01-15';

// ---------------------------------------------------------------------
runTest('P2-01 initial PO imports correctly', function () use ($http, $csrf, $productA, $tanggal) {
    $csv = buildCsvBase64([...karangtengahHeader(), karangtengahRow($productA['legacy_code'], $productA['name'], 100, 0, 0, 0, 999, 0)]);
    $r = $http->request('POST', '/api/po/preview', ['tanggal' => $tanggal, 'uploadType' => 'initial', 'fileName' => 'awal.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, 'preview failed: ' . json_encode($r['json']));
    expect($r['json']['data']['canImport'] === true, 'expected canImport=true: ' . json_encode($r['json']['data']));
    expect((float) $r['json']['data']['targetTotal'] === 100.0, 'expected target 100, got ' . $r['json']['data']['targetTotal']);

    $r2 = $http->request('POST', '/api/po/import', ['tanggal' => $tanggal, 'uploadType' => 'initial', 'fileName' => 'awal.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-01-' . uniqid()]);
    expect($r2['status'] === 200, 'import failed: ' . json_encode($r2['json']));
    expect((float) $r2['json']['data']['targetTotal'] === 100.0, 'expected target 100 after import');
});

runTest('P2-02 same initial file reupload does not duplicate', function () use ($http, $csrf, $productA, $tanggal, $pdo) {
    $csv = buildCsvBase64([...karangtengahHeader(), karangtengahRow($productA['legacy_code'], $productA['name'], 100, 0, 0, 0, 999, 0)]);
    $r = $http->request('POST', '/api/po/import', ['tanggal' => $tanggal, 'uploadType' => 'initial', 'fileName' => 'awal.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-02-' . uniqid()]);
    expect($r['status'] === 200, 'expected the identical initial file to be accepted as an idempotent replay: ' . json_encode($r['json']));
    expect((float) $r['json']['data']['targetTotal'] === 100.0, 'target must not double on re-upload of the same initial file');

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM po_store_item si INNER JOIN po_item i ON i.po_item_id = si.po_item_id WHERE i.product_id = ?');
    $stmt->execute([$productA['product_id']]);
    expect((int) $stmt->fetchColumn() === 1, 'expected exactly 1 po_store_item row for productA+TSA, no duplicate row created');
});

runTest('P2-03 a different second initial file is blocked (INITIAL_PO_ALREADY_EXISTS)', function () use ($http, $csrf, $productA, $tanggal, $pdo) {
    $csv = buildCsvBase64([...karangtengahHeader(), karangtengahRow($productA['legacy_code'], $productA['name'], 999, 0, 0, 0)]);
    $r = $http->request('POST', '/api/po/import', ['tanggal' => $tanggal, 'uploadType' => 'initial', 'fileName' => 'awal-lain.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-03-' . uniqid()]);
    expect($r['status'] === 409, "expected 409, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['code'] === 'INITIAL_PO_ALREADY_EXISTS', 'wrong error code: ' . json_encode($r['json']));

    $stmt = $pdo->prepare(
        'SELECT si.po_awal FROM po_store_item si INNER JOIN po_item i ON i.po_item_id = si.po_item_id
         INNER JOIN store s ON s.store_id = si.store_id WHERE i.product_id = ? AND s.canonical_name = (SELECT canonical_name FROM store WHERE store_id = (SELECT store_id FROM store_alias WHERE raw_name = "TSA"))'
    );
    $stmt->execute([$productA['product_id']]);
    expect((float) $stmt->fetchColumn() === 100.0, 'existing PO Awal must remain 100, not overwritten by the rejected file');
});

runTest('P2-04 revision full-snapshot semantics (100 + 20 = 120)', function () use ($http, $csrf, $productA, $tanggal) {
    $csv = buildCsvBase64([...karangtengahHeader(), karangtengahRow($productA['legacy_code'], $productA['name'], 0, 0, 20, 0)]);
    $r = $http->request('POST', '/api/po/import', ['tanggal' => $tanggal, 'uploadType' => 'revision', 'fileName' => 'revisi1.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-04-' . uniqid()]);
    expect($r['status'] === 200, 'revision import failed: ' . json_encode($r['json']));
    expect((float) $r['json']['data']['targetTotal'] === 120.0, 'expected target 120 (100 awal + 20 revisi), got ' . $r['json']['data']['targetTotal']);
});

runTest('P2-05 same revision reupload stays unchanged (still 120)', function () use ($http, $csrf, $productA, $tanggal) {
    $csv = buildCsvBase64([...karangtengahHeader(), karangtengahRow($productA['legacy_code'], $productA['name'], 0, 0, 20, 0)]);
    $r = $http->request('POST', '/api/po/import', ['tanggal' => $tanggal, 'uploadType' => 'revision', 'fileName' => 'revisi1.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-05-' . uniqid()]);
    expect($r['status'] === 200, 'revision re-upload failed: ' . json_encode($r['json']));
    expect((float) $r['json']['data']['targetTotal'] === 120.0, 'target must stay 120, not grow to 140, on identical revision re-upload');
});

runTest('P2-06 revision changed 20 -> 35 updates target to 135', function () use ($http, $csrf, $productA, $tanggal) {
    $csv = buildCsvBase64([...karangtengahHeader(), karangtengahRow($productA['legacy_code'], $productA['name'], 0, 0, 35, 0)]);
    $r = $http->request('POST', '/api/po/import', ['tanggal' => $tanggal, 'uploadType' => 'revision', 'fileName' => 'revisi2.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-06-' . uniqid()]);
    expect($r['status'] === 200, 'revision update failed: ' . json_encode($r['json']));
    expect((float) $r['json']['data']['targetTotal'] === 135.0, 'expected target 135 (100 awal + 35 revisi), got ' . $r['json']['data']['targetTotal']);
});

runTest('P2-07 a product appearing only in a revision file gets poAwal=0', function () use ($http, $csrf, $productC, $tanggal, $pdo) {
    $csv = buildCsvBase64([...karangtengahHeader(), karangtengahRow($productC['legacy_code'], $productC['name'], 0, 0, 7, 0)]);
    $r = $http->request('POST', '/api/po/import', ['tanggal' => $tanggal, 'uploadType' => 'revision', 'fileName' => 'revisi-newprod.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-07-' . uniqid()]);
    expect($r['status'] === 200, 'revision-only new product import failed: ' . json_encode($r['json']));

    $stmt = $pdo->prepare('SELECT si.po_awal, si.po_revisi FROM po_store_item si INNER JOIN po_item i ON i.po_item_id = si.po_item_id WHERE i.product_id = ?');
    $stmt->execute([$productC['product_id']]);
    $row = $stmt->fetch();
    expect($row !== false, 'expected a po_store_item row for the new revision-only product');
    expect((float) $row['po_awal'] === 0.0, 'a revision-only product must never establish its own PO Awal, got ' . $row['po_awal']);
    expect((float) $row['po_revisi'] === 7.0, 'expected po_revisi=7, got ' . $row['po_revisi']);
});

runTest('P2-08 PB is parsed but never affects target/awal/revisi', function () use ($http, $csrf, $pdo) {
    // Self-contained: a fresh product/date with a large PB figure, checked in
    // the same upload where it's set (pb is display/compat-only, never
    // locked across uploads the way po_awal is — a later upload naturally
    // overwrites it with whatever that file says, same as kategori).
    $row = $pdo->query(
        "SELECT p.product_id, p.name, plc.legacy_code FROM product p
         INNER JOIN product_legacy_code plc ON plc.product_id = p.product_id
         ORDER BY p.product_id DESC LIMIT 1"
    )->fetch();

    $csv = buildCsvBase64([...karangtengahHeader(), karangtengahRow($row['legacy_code'], $row['name'], 10, 0, 0, 0, 500, 0)]);
    $r = $http->request('POST', '/api/po/import', ['tanggal' => '2026-01-25', 'uploadType' => 'initial', 'fileName' => 'pb.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-08-' . uniqid()]);
    expect($r['status'] === 200, 'import failed: ' . json_encode($r['json']));
    expect((float) $r['json']['data']['targetTotal'] === 10.0, 'PB (500) must never be added into the target total, got ' . $r['json']['data']['targetTotal']);

    $stmt = $pdo->prepare('SELECT pb, po_awal, po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND i.product_id = ?');
    $stmt->execute(['2026-01-25', $row['product_id']]);
    $item = $stmt->fetch();
    expect((float) $item['pb'] === 500.0, 'expected pb to be recorded as-is (500, for display/compat only), got ' . $item['pb']);
    expect((float) $item['po_awal'] === 10.0 && (float) $item['po_revisi'] === 0.0, 'PB must never leak into po_awal/po_revisi');
});

runTest('P2-09 an unmapped product blocks the whole authoritative row (all-or-nothing, matches audited legacy behavior)', function () use ($http, $csrf, $productA) {
    $tgl = '2026-01-16';
    $csv = buildCsvBase64([
        ...karangtengahHeader(),
        karangtengahRow($productA['legacy_code'], $productA['name'], 10, 0, 0, 0),
        karangtengahRow('999999', 'PRODUK TIDAK DIKENAL PHASE2 TEST', 5, 0, 0, 0),
    ]);
    $r = $http->request('POST', '/api/po/import', ['tanggal' => $tgl, 'uploadType' => 'initial', 'fileName' => 'unmapped.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-09-' . uniqid()]);
    expect($r['status'] === 400, "expected 400, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['code'] === 'UNRESOLVED_ROWS', 'wrong error code: ' . json_encode($r['json']));

    global $pdo;
    $n = (int) $pdo->query("SELECT COUNT(*) FROM po_batch WHERE tanggal = '2026-01-16'")->fetchColumn();
    // a batch row may exist (created for locking) but must have zero items — the
    // resolved productA row in the SAME file must NOT have been written either.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ?');
    $stmt->execute(['2026-01-16']);
    expect((int) $stmt->fetchColumn() === 0, 'no po_item row must be written when any row in the file is unresolved, including the otherwise-valid productA row');

    $stmt = $pdo->prepare("SELECT status FROM migration_product_map WHERE raw_name = ? AND source_table = 'po_import'");
    $stmt->execute(['PRODUK TIDAK DIKENAL PHASE2 TEST']);
    expect($stmt->fetchColumn() === 'unresolved', 'expected the unmapped product to be staged in the existing migration_product_map review queue');
});

runTest('P2-10 an unmapped store blocks the whole authoritative row', function () use ($http, $csrf, $productB) {
    $tgl = '2026-01-17';
    $header = karangtengahHeader();
    $header[0][4] = 'TOKO_ASING'; // replace TSA's header with an unknown store code
    $header[1][4] = 'TOKO_ASING';
    $csv = buildCsvBase64([...$header, karangtengahRow($productB['legacy_code'], $productB['name'], 10, 0, 0, 0)]);
    $r = $http->request('POST', '/api/po/import', ['tanggal' => $tgl, 'uploadType' => 'initial', 'fileName' => 'unmapped-store.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-10-' . uniqid()]);
    expect($r['status'] === 400, "expected 400, got {$r['status']}: " . json_encode($r['json']));
    expect($r['json']['code'] === 'UNRESOLVED_ROWS', 'wrong error code: ' . json_encode($r['json']));

    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ?');
    $stmt->execute([$tgl]);
    expect((int) $stmt->fetchColumn() === 0, 'no po_item row must be written when a store cannot be resolved');

    $stmt = $pdo->prepare("SELECT status FROM migration_store_map WHERE raw_name = 'TOKO_ASING' AND source_table = 'po_import'");
    $stmt->execute();
    expect($stmt->fetchColumn() === 'unresolved', 'expected the unmapped store to be staged in the existing migration_store_map review queue');
});

runTest('P2-11 a confirmed product alias resolves correctly on the next upload', function () use ($http, $csrf, $productB, $pdo) {
    $pdo->prepare("INSERT INTO product_alias (product_id, raw_name, source, created_at) VALUES (?, 'PRODUK ALIAS PHASE2 TEST', 'test', UTC_TIMESTAMP())")->execute([$productB['product_id']]);

    $tgl = '2026-01-18';
    // Karangtengah layout requires a non-empty KODE cell for a row to be
    // treated as a product row at all (matches the audited legacy parser's
    // own filter) — a code that resolves to nothing is fine here, since
    // resolution must fall through to the alias tier on the name regardless.
    $csv = buildCsvBase64([...karangtengahHeader(), karangtengahRow('ALIASCODE', 'PRODUK ALIAS PHASE2 TEST', 8, 0, 0, 0)]);
    $r = $http->request('POST', '/api/po/import', ['tanggal' => $tgl, 'uploadType' => 'initial', 'fileName' => 'alias.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-11-' . uniqid()]);
    expect($r['status'] === 200, 'expected the aliased product to resolve and import: ' . json_encode($r['json']));

    $stmt = $pdo->prepare('SELECT po_awal FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND i.product_id = ?');
    $stmt->execute([$tgl, $productB['product_id']]);
    expect((float) $stmt->fetchColumn() === 8.0, 'expected the alias to resolve to productB with po_awal=8');
});

runTest('P2-12 an ambiguous legacy code (claimed by two products) never fuzzy-resolves', function () use ($http, $csrf, $productA, $productB, $pdo) {
    $ambiguousCode = 'AMBIG' . uniqid();
    $pdo->prepare('INSERT INTO product_legacy_code (product_id, legacy_code, created_at) VALUES (?, ?, UTC_TIMESTAMP())')->execute([$productA['product_id'], $ambiguousCode]);
    $pdo->prepare('INSERT INTO product_legacy_code (product_id, legacy_code, created_at) VALUES (?, ?, UTC_TIMESTAMP())')->execute([$productB['product_id'], $ambiguousCode]);

    $tgl = '2026-01-19';
    $csv = buildCsvBase64([...karangtengahHeader(), karangtengahRow($ambiguousCode, 'NAMA TIDAK COCOK DENGAN KEDUANYA', 3, 0, 0, 0)]);
    $r = $http->request('POST', '/api/po/import', ['tanggal' => $tgl, 'uploadType' => 'initial', 'fileName' => 'ambiguous.csv', 'fileContentBase64' => $csv], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => 'p2-12-' . uniqid()]);
    expect($r['status'] === 400 && $r['json']['code'] === 'UNRESOLVED_ROWS', 'an ambiguous code must be treated as unresolved, never guessed at: ' . json_encode($r['json']));
});

runTest('P2-13 poType=initial filter shows only rows with po_awal > 0', function () use ($http, $csrf, $productC, $tanggal) {
    $r = $http->request('GET', "/api/po/current?date={$tanggal}&poType=initial", null, ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, 'current(initial) failed: ' . json_encode($r['json']));
    foreach ($r['json']['data'] as $row) {
        expect((float) $row['po_awal'] > 0, 'poType=initial must only return rows with po_awal>0: ' . json_encode($row));
        expect((int) $row['product_id'] !== (int) $productC['product_id'], 'the revision-only product (po_awal=0) must not appear under poType=initial');
    }
});

runTest('P2-14 poType=revision filter shows only rows with po_revisi > 0', function () use ($http, $csrf, $tanggal) {
    $r = $http->request('GET', "/api/po/current?date={$tanggal}&poType=revision", null, ['X-CSRF-Token' => $csrf]);
    expect($r['status'] === 200, 'current(revision) failed: ' . json_encode($r['json']));
    expect(count($r['json']['data']) > 0, 'expected at least one row with po_revisi>0');
    foreach ($r['json']['data'] as $row) {
        expect((float) $row['po_revisi'] > 0, 'poType=revision must only return rows with po_revisi>0: ' . json_encode($row));
    }
});

runTest('P2-15 filtering is presentation-only and never mutates stored PO state', function () use ($http, $csrf, $productA, $tanggal, $pdo) {
    $before = $pdo->query("SELECT po_awal, po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id=i.po_batch_id WHERE b.tanggal='{$tanggal}' AND i.product_id={$productA['product_id']}")->fetch();
    $http->request('GET', "/api/po/current?date={$tanggal}&poType=initial", null, ['X-CSRF-Token' => $csrf]);
    $http->request('GET', "/api/po/current?date={$tanggal}&poType=revision", null, ['X-CSRF-Token' => $csrf]);
    $http->request('GET', "/api/po/current?date={$tanggal}&poType=all", null, ['X-CSRF-Token' => $csrf]);
    $after = $pdo->query("SELECT po_awal, po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id=i.po_batch_id WHERE b.tanggal='{$tanggal}' AND i.product_id={$productA['product_id']}")->fetch();
    expect($before === $after, 'GET /api/po/current with any poType filter must never change stored po_awal/po_revisi');
});

runTest('P2-16 a successful import writes an audit_log row', function () use ($pdo, $tanggal) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'po.import' AND record_type = 'po_batch'");
    $stmt->execute();
    expect((int) $stmt->fetchColumn() >= 1, 'expected at least one po.import audit_log row from the imports run so far');
});

runTest('P2-17 idempotent replay (same key, same payload) does not duplicate', function () use ($http, $csrf, $productA, $pdo) {
    $tgl = '2026-01-20';
    $csv = buildCsvBase64([...karangtengahHeader(), karangtengahRow($productA['legacy_code'], $productA['name'], 12, 0, 0, 0)]);
    $key = 'p2-17-' . uniqid();
    $body = ['tanggal' => $tgl, 'uploadType' => 'initial', 'fileName' => 'replay.csv', 'fileContentBase64' => $csv];

    $before = (int) $pdo->query('SELECT COUNT(*) FROM po_import')->fetchColumn();
    $r1 = $http->request('POST', '/api/po/import', $body, ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => $key]);
    expect($r1['status'] === 200, 'first call failed: ' . json_encode($r1['json']));
    $afterFirst = (int) $pdo->query('SELECT COUNT(*) FROM po_import')->fetchColumn();
    expect($afterFirst === $before + 1, 'expected exactly one new po_import history row after the first call');

    $r2 = $http->request('POST', '/api/po/import', $body, ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => $key]);
    expect($r2['status'] === 200, 'replay call failed: ' . json_encode($r2['json']));
    expect($r2['json'] === $r1['json'], 'a replayed call with the same key+payload must return the exact same response');
    $afterReplay = (int) $pdo->query('SELECT COUNT(*) FROM po_import')->fetchColumn();
    expect($afterReplay === $afterFirst, 'a replayed call must not write a second po_import history row');
});

runTest('P2-18 same idempotency key with a different payload is rejected (409)', function () use ($http, $csrf, $productA) {
    $tgl = '2026-01-21';
    $key = 'p2-18-' . uniqid();
    $csv1 = buildCsvBase64([...karangtengahHeader(), karangtengahRow($productA['legacy_code'], $productA['name'], 1, 0, 0, 0)]);
    $csv2 = buildCsvBase64([...karangtengahHeader(), karangtengahRow($productA['legacy_code'], $productA['name'], 2, 0, 0, 0)]);

    $r1 = $http->request('POST', '/api/po/import', ['tanggal' => $tgl, 'uploadType' => 'initial', 'fileName' => 'a.csv', 'fileContentBase64' => $csv1], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => $key]);
    expect($r1['status'] === 200, 'first call failed: ' . json_encode($r1['json']));

    $r2 = $http->request('POST', '/api/po/import', ['tanggal' => $tgl, 'uploadType' => 'initial', 'fileName' => 'b.csv', 'fileContentBase64' => $csv2], ['X-CSRF-Token' => $csrf, 'Idempotency-Key' => $key]);
    expect($r2['status'] === 409, "expected 409, got {$r2['status']}: " . json_encode($r2['json']));
    expect($r2['json']['code'] === 'IDEMPOTENCY_KEY_REUSE_MISMATCH', 'wrong error code: ' . json_encode($r2['json']));
});

runTest('P2-19 concurrent revision updates on the same batch are serialized (row lock, no last-write-wins)', function () use ($dbSocket, $dbName, $runtimeUser, $runtimePass, $productA, $tanggal) {
    $connA = new PDO("mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4", $runtimeUser, $runtimePass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $connB = new PDO("mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4", $runtimeUser, $runtimePass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $factoryId = (int) $connA->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();

    $connA->beginTransaction();
    $connA->prepare('SELECT * FROM po_batch WHERE tanggal = ? AND factory_id = ? FOR UPDATE')->execute([$tanggal, $factoryId]);

    $connB->exec('SET SESSION innodb_lock_wait_timeout = 2');
    $blocked = false;
    try {
        $connB->prepare('SELECT * FROM po_batch WHERE tanggal = ? AND factory_id = ? FOR UPDATE')->execute([$tanggal, $factoryId]);
    } catch (\PDOException $e) {
        $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
    }
    $connA->rollBack();
    expect($blocked, 'a concurrent revision update on the same (date,factory) batch must block on the row lock, not interleave silently');

    // Once released, the same lock must succeed normally.
    $connB->beginTransaction();
    $connB->prepare('SELECT * FROM po_batch WHERE tanggal = ? AND factory_id = ? FOR UPDATE')->execute([$tanggal, $factoryId]);
    $connB->commit();
});

runTest('P2-20 no production/FG/DO/shipment/stock/invoice/payment/return/sale rows were created by any PO import', function () use ($pdo) {
    $tables = ['production_run', 'fg_batch', 'delivery_order', 'shipment', 'invoice', 'payment', 'return_note', 'reject_note', 'retail_sale', 'stock_ledger', 'customer_order'];
    foreach ($tables as $t) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        expect($n === 0, "expected {$t} to have 0 rows, found {$n}");
    }
});

// ---------------------------------------------------------------------
$total = count($results);
$passed = count(array_filter($results));
fwrite(STDOUT, "\n{$passed}/{$total} passed\n");
exit($passed === $total ? 0 : 1);
