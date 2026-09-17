<?php

declare(strict_types=1);

/**
 * Phase 2 PO patch test suite: P2-NP01..P2-NP12 (New Product Detection
 * During PO Import) plus the PO Revisi confirmation-step tests. Runs in
 * the SAME disposable environment run-phase2-po.sh already built for
 * Phase2POTest.php (same server, same DB) — appended as an extra step so
 * the whole realistic bootstrap (divisions/472 katalog products/BAKERY
 * CIKOLE/admin) is not paid for twice. Uses dates and product identities
 * Phase2POTest.php never touches, so the two files never collide.
 *
 * Drives api/_import-po/ directly over plain HTTP form posts (multipart
 * for the initial upload, urlencoded for every action after) — exactly
 * how a real browser (including iPad/Safari, which this wizard is built
 * for) would — never Amor\Api\Import\PoImporter/PoResolver directly, so a
 * regression in the actual wizard wiring would be caught here too.
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

final class HttpNP
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'npcookies');
    }

    /** @return array{status:int,body:string} */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** Ordinary <form method="post"> submission ($_POST, urlencoded) — matches every action on the wizard page. */
    public function postForm(string $path, array $fields): array
    {
        return $this->request('POST', $path, $fields);
    }

    /** multipart/form-data submission — matches the wizard's file upload form. */
    /** Always the wizard's "preview_upload" action — the only one that takes a file. */
    public function postMultipart(string $path, array $fields, string $fileField, string $filePath, string $fileName): array
    {
        $fields['action'] = 'preview_upload';
        $fields[$fileField] = new CURLFile($filePath, 'text/csv', $fileName);
        return $this->request('POST', $path, $fields, multipart: true);
    }

    private function request(string $method, string $path, ?array $fields = null, bool $multipart = false): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_HEADER => false,
        ]);
        if ($fields !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
        }
        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException('curl error: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body];
    }
}

function expect(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

function extractCsrf(string $html): ?string
{
    return preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : null;
}

function esc_test(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES);
}

/** @param array<int,array<int,mixed>> $matrix */
function writeCsvFile(array $matrix): string
{
    $path = tempnam(sys_get_temp_dir(), 'np_po_') . '.csv';
    $fh = fopen($path, 'w');
    foreach ($matrix as $row) {
        fputcsv($fh, $row);
    }
    fclose($fh);
    return $path;
}

function karangtengahHeader(): array
{
    return [
        ['NO', 'KATEGORI', 'KODE', 'NAMA PRODUK', 'TSA', 'TSB', 'TOTAL', 'TSA', 'TSB', 'TOTAL', 'TSA', 'TSB', 'TOTAL'],
        ['', '', '', '', 'TSA', 'TSB', '', 'TSA', 'TSB', '', 'TSA', 'TSB', ''],
    ];
}

function karangtengahRow(string $kode, string $nama, float $awalA, float $awalB, float $revA, float $revB): array
{
    return [1, 'TEST', $kode, $nama, $awalA, $awalB, $awalA + $awalB, $revA, $revB, $revA + $revB, 0, 0, 0];
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

// Distinct real katalog products this suite owns exclusively (the tail of
// the katalog, never touched by Phase2POTest.php's own LIMIT 3 head pick).
$catalogRows = $pdo->query(
    "SELECT p.product_id, p.name, plc.legacy_code FROM product p
     INNER JOIN product_legacy_code plc ON plc.product_id = p.product_id
     ORDER BY p.product_id DESC LIMIT 2"
)->fetchAll();
expect(count($catalogRows) >= 2, 'expected at least 2 katalog products with legacy codes');
[$knownProductA, $knownProductB] = $catalogRows;

$http = new HttpNP($baseUrl);
$login = $http->postForm('/_admin-login/', ['action' => 'login', 'username' => $adminUser, 'password' => $adminPass]);
expect(str_contains($login['body'], 'Login berhasil'), 'admin login failed: ' . substr($login['body'], 0, 300));

function freshCsrf(HttpNP $http): string
{
    $page = $http->get('/_import-po/');
    $csrf = extractCsrf($page['body']);
    if ($csrf === null) {
        throw new RuntimeException('expected a csrf token on the import-po page: ' . substr($page['body'], 0, 300));
    }
    return $csrf;
}

function resetStaged(HttpNP $http): void
{
    $csrf = freshCsrf($http);
    $http->postForm('/_import-po/', ['csrf' => $csrf, 'action' => 'reset_staged']);
}

// ---------------------------------------------------------------------
$newProductRawName = 'PRODUK BARU PHASE2 PATCH TEST';
$newProductRawCode = 'NEWCODE001';

runTest('P2-NP01 unknown product is shown in the New Product Review section', function () use ($http, $knownProductA, $newProductRawCode, $newProductRawName) {
    resetStaged($http);
    $csrf = freshCsrf($http);
    $csv = writeCsvFile([...karangtengahHeader(),
        karangtengahRow($knownProductA['legacy_code'], $knownProductA['name'], 10, 0, 0, 0),
        karangtengahRow($newProductRawCode, $newProductRawName, 5, 0, 0, 0),
    ]);
    $r = $http->postMultipart('/_import-po/', ['csrf' => $csrf, 'tanggal' => '2026-02-01', 'uploadType' => 'initial'], 'file', $csv, 'np01.csv');
    unlink($csv);
    expect($r['status'] === 200, 'upload failed: ' . substr($r['body'], 0, 400));
    expect(str_contains($r['body'], 'Produk Baru Terdeteksi'), 'expected the New Product Review section to appear');
    expect(str_contains($r['body'], $newProductRawName), 'expected the raw unresolved product name to be shown');
    expect(str_contains($r['body'], $newProductRawCode), 'expected the raw unresolved product code to be shown');
});

runTest('P2-NP02 nothing is auto-created before explicit confirmation', function () use ($pdo, $newProductRawName) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM product WHERE name = ?');
    $stmt->execute([$newProductRawName]);
    expect((int) $stmt->fetchColumn() === 0, 'the unresolved product must not exist yet — preview alone must never create it');
});

$createdProductId = null;
runTest('P2-NP03 confirming "Tambah ke Master" creates a product with a numeric product_id', function () use (&$createdProductId, $http, $newProductRawCode, $newProductRawName) {
    $csrf = freshCsrf($http);
    $r = $http->postForm('/_import-po/', [
        'csrf' => $csrf, 'action' => 'create_new_product_from_import',
        'rawCode' => $newProductRawCode, 'rawName' => $newProductRawName, 'finalName' => $newProductRawName,
        'kategori' => 'TEST', 'divisionId' => '', 'harga' => '',
    ]);
    expect(str_contains($r['body'], 'Produk baru dibuat'), 'expected a success message: ' . substr($r['body'], 0, 400));
    if (!preg_match('/produk_id=(\d+)/', $r['body'], $m)) {
        throw new RuntimeException('expected the new numeric product_id to be shown in the result message');
    }
    $createdProductId = (int) $m[1];
    expect($createdProductId > 0, 'expected a positive numeric product_id');
});

runTest('P2-NP04 a product_legacy_code mapping was created for the new product', function () use ($pdo, &$createdProductId, $newProductRawCode) {
    $stmt = $pdo->prepare('SELECT product_id FROM product_legacy_code WHERE legacy_code = ?');
    $stmt->execute([$newProductRawCode]);
    expect((int) $stmt->fetchColumn() === $createdProductId, 'expected product_legacy_code to map the new code to the new product');
});

runTest('P2-NP05 the PO row imports using the new product_id (initial import, no unresolved rows left)', function () use ($http, $pdo, &$createdProductId) {
    $page = $http->get('/_import-po/');
    expect(!str_contains($page['body'], 'Produk Baru Terdeteksi'), 'the row should now resolve automatically — no more unresolved section');
    $csrf = extractCsrf($page['body']);
    $r = $http->postForm('/_import-po/', ['csrf' => $csrf, 'action' => 'confirm_import']);
    expect(str_contains($r['body'], 'PO berhasil diimpor'), 'expected the import to succeed: ' . substr($r['body'], 0, 400));

    $stmt = $pdo->prepare('SELECT si.po_awal FROM po_store_item si INNER JOIN po_item i ON i.po_item_id = si.po_item_id
        INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND i.product_id = ?');
    $stmt->execute(['2026-02-01', $createdProductId]);
    expect((float) $stmt->fetchColumn() === 5.0, 'expected the new product row to import with po_awal=5');
});

runTest('P2-NP06 re-running the same raw identity in a new file does not create a duplicate product', function () use ($http, $pdo, $newProductRawCode, $newProductRawName, $knownProductB) {
    resetStaged($http);
    $csrf = freshCsrf($http);
    $csv = writeCsvFile([...karangtengahHeader(),
        karangtengahRow($knownProductB['legacy_code'], $knownProductB['name'], 3, 0, 0, 0),
        karangtengahRow($newProductRawCode, $newProductRawName, 8, 0, 0, 0),
    ]);
    $r = $http->postMultipart('/_import-po/', ['csrf' => $csrf, 'tanggal' => '2026-02-02', 'uploadType' => 'initial'], 'file', $csv, 'np06.csv');
    unlink($csv);
    expect(!str_contains($r['body'], 'Produk Baru Terdeteksi'), 'the already-created product must resolve automatically on a later file, not show as new again');

    $csrf2 = extractCsrf($r['body']);
    $r2 = $http->postForm('/_import-po/', ['csrf' => $csrf2, 'action' => 'confirm_import']);
    expect(str_contains($r2['body'], 'PO berhasil diimpor'), 'expected the second import to succeed: ' . substr($r2['body'], 0, 400));

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM product WHERE name = ?');
    $stmt->execute([$newProductRawName]);
    expect((int) $stmt->fetchColumn() === 1, 'expected exactly one product row — no duplicate created by the second file');
});

runTest('P2-NP07 a similar (not exact) existing product name is shown as a hint but never auto-merged', function () use ($http, $knownProductA) {
    resetStaged($http);
    $csrf = freshCsrf($http);
    // Deliberately one character off from a real product name — close enough
    // to surface as a similarity hint, but must never auto-resolve.
    $almostName = $knownProductA['name'] . ' X';
    $csv = writeCsvFile([...karangtengahHeader(), karangtengahRow('ALMOSTCODE1', $almostName, 4, 0, 0, 0)]);
    $r = $http->postMultipart('/_import-po/', ['csrf' => $csrf, 'tanggal' => '2026-02-03', 'uploadType' => 'initial'], 'file', $csv, 'np07.csv');
    unlink($csv);
    expect(str_contains($r['body'], 'Produk Baru Terdeteksi'), 'a near-miss name must still be unresolved, never auto-matched');
    expect(str_contains($r['body'], 'Mungkin mirip dengan produk yang sudah ada'), 'expected a similarity hint to be shown');
    expect(str_contains($r['body'], esc_test($knownProductA['name'])), 'expected the real close-match product name to be listed as a candidate');
});

runTest('P2-NP08 a conflicting legacy code blocks automatic creation (PRODUCT_CODE_CONFLICT)', function () use ($http, $newProductRawCode) {
    // $newProductRawCode already belongs to the product created in P2-NP03 —
    // attempting to create ANOTHER product with the same code must be blocked.
    $csrf = freshCsrf($http);
    $r = $http->postForm('/_import-po/', [
        'csrf' => $csrf, 'action' => 'create_new_product_from_import',
        'rawCode' => $newProductRawCode, 'rawName' => 'PRODUK LAIN DENGAN KODE BENTROK',
        'finalName' => 'PRODUK LAIN DENGAN KODE BENTROK', 'kategori' => '', 'divisionId' => '', 'harga' => '',
    ]);
    expect(str_contains($r['body'], 'PRODUCT_CODE_CONFLICT'), 'expected a PRODUCT_CODE_CONFLICT result: ' . substr($r['body'], 0, 400));

    global $pdo;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM product WHERE name = ?');
    $stmt->execute(['PRODUK LAIN DENGAN KODE BENTROK']);
    expect((int) $stmt->fetchColumn() === 0, 'the conflicting product must not have been created');
});

runTest('P2-NP09 skipping a product excludes only that row, with a clear warning, and imports the rest', function () use ($http, $pdo, $knownProductA) {
    resetStaged($http);
    $csrf = freshCsrf($http);
    $skipRawName = 'PRODUK YANG AKAN DILEWATI TEST';
    $csv = writeCsvFile([...karangtengahHeader(),
        karangtengahRow($knownProductA['legacy_code'], $knownProductA['name'], 6, 0, 0, 0),
        karangtengahRow('SKIPCODE1', $skipRawName, 9, 0, 0, 0),
    ]);
    $r = $http->postMultipart('/_import-po/', ['csrf' => $csrf, 'tanggal' => '2026-02-04', 'uploadType' => 'initial'], 'file', $csv, 'np09.csv');
    unlink($csv);
    $csrf2 = extractCsrf($r['body']);

    $skip = $http->postForm('/_import-po/', ['csrf' => $csrf2, 'action' => 'skip_unresolved_product', 'rawCode' => 'SKIPCODE1', 'rawName' => $skipRawName]);
    expect(str_contains($skip['body'], 'Produk dilewati'), 'expected the skip action to succeed: ' . substr($skip['body'], 0, 400));
    expect(str_contains($skip['body'], 'Produk Dilewati'), 'expected the "Produk Dilewati" review list to appear');
    expect(!str_contains($skip['body'], 'Produk Baru Terdeteksi'), 'no unresolved products should remain — the only one was just skipped');

    $csrf3 = extractCsrf($skip['body']);
    $import = $http->postForm('/_import-po/', ['csrf' => $csrf3, 'action' => 'confirm_import']);
    expect(str_contains($import['body'], 'PO berhasil diimpor'), 'expected import to succeed with the skip applied: ' . substr($import['body'], 0, 400));
    expect(str_contains($import['body'], 'produk dilewati'), 'expected the result message to note the skipped product count');

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM product WHERE name = ?');
    $stmt->execute([$skipRawName]);
    expect((int) $stmt->fetchColumn() === 0, 'a skipped product must never be created');

    $stmt2 = $pdo->prepare('SELECT si.po_awal FROM po_store_item si INNER JOIN po_item i ON i.po_item_id = si.po_item_id
        INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND i.product_id = ?');
    $stmt2->execute(['2026-02-04', $knownProductA['product_id']]);
    expect((float) $stmt2->fetchColumn() === 6.0, 'the resolved row in the same file must still import normally');
});

runTest('P2-NP10 canceling the import (Batal/Ganti File) writes nothing at all', function () use ($http, $pdo) {
    resetStaged($http);
    $csrf = freshCsrf($http);
    $rawName = 'PRODUK DIBATALKAN TOTAL TEST';
    $csv = writeCsvFile([...karangtengahHeader(), karangtengahRow('CANCELCODE1', $rawName, 7, 0, 0, 0)]);
    $http->postMultipart('/_import-po/', ['csrf' => $csrf, 'tanggal' => '2026-02-05', 'uploadType' => 'initial'], 'file', $csv, 'np10.csv');
    unlink($csv);

    resetStaged($http); // "Batal / Ganti File" == action=reset_staged

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM product WHERE name = ?');
    $stmt->execute([$rawName]);
    expect((int) $stmt->fetchColumn() === 0, 'canceling must never create the product');

    $n = (int) $pdo->query("SELECT COUNT(*) FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = '2026-02-05'")->fetchColumn();
    expect($n === 0, 'canceling must never write any po_item row');
});

runTest('P2-NP11 a new product introduced via a revision file still gets poAwal=0 (existing rule intact)', function () use ($http, $pdo, $knownProductA) {
    // Establish a real initial PO first.
    resetStaged($http);
    $csrf = freshCsrf($http);
    $csv = writeCsvFile([...karangtengahHeader(), karangtengahRow($knownProductA['legacy_code'], $knownProductA['name'], 50, 0, 0, 0)]);
    $http->postMultipart('/_import-po/', ['csrf' => $csrf, 'tanggal' => '2026-02-06', 'uploadType' => 'initial'], 'file', $csv, 'np11-initial.csv');
    unlink($csv);
    $csrf2 = freshCsrf($http);
    $http->postForm('/_import-po/', ['csrf' => $csrf2, 'action' => 'confirm_import']);

    // Now a revision file introducing a brand-new product.
    resetStaged($http);
    $csrf3 = freshCsrf($http);
    $newRawName = 'PRODUK BARU DI REVISI TEST';
    $csvRev = writeCsvFile([...karangtengahHeader(), karangtengahRow('REVNEWCODE1', $newRawName, 0, 0, 15, 0)]);
    $r = $http->postMultipart('/_import-po/', ['csrf' => $csrf3, 'tanggal' => '2026-02-06', 'uploadType' => 'revision'], 'file', $csvRev, 'np11-revision.csv');
    unlink($csvRev);
    expect(str_contains($r['body'], 'Produk Baru Terdeteksi'), 'expected the new revision-only product to appear in review');

    $csrf4 = extractCsrf($r['body']);
    $create = $http->postForm('/_import-po/', [
        'csrf' => $csrf4, 'action' => 'create_new_product_from_import',
        'rawCode' => 'REVNEWCODE1', 'rawName' => $newRawName, 'finalName' => $newRawName,
        'kategori' => '', 'divisionId' => '', 'harga' => '',
    ]);
    if (!preg_match('/produk_id=(\d+)/', $create['body'], $m)) {
        throw new RuntimeException('expected the new product_id in the create result: ' . substr($create['body'], 0, 400));
    }
    $newProductId = (int) $m[1];

    // Revision uploads require the extra confirmation step.
    $csrf5 = freshCsrf($http);
    $review = $http->postForm('/_import-po/', ['csrf' => $csrf5, 'action' => 'review_revision']);
    expect(str_contains($review['body'], 'Konfirmasi PO Revisi'), 'expected the revision confirmation box');
    $csrf6 = extractCsrf($review['body']);
    $import = $http->postForm('/_import-po/', ['csrf' => $csrf6, 'action' => 'confirm_import']);
    expect(str_contains($import['body'], 'PO berhasil diimpor'), 'expected the revision import to succeed: ' . substr($import['body'], 0, 400));

    $stmt = $pdo->prepare('SELECT si.po_awal, si.po_revisi FROM po_store_item si INNER JOIN po_item i ON i.po_item_id = si.po_item_id
        INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND i.product_id = ?');
    $stmt->execute(['2026-02-06', $newProductId]);
    $row = $stmt->fetch();
    expect((float) $row['po_awal'] === 0.0, 'a product introduced only in a revision must get poAwal=0, got ' . $row['po_awal']);
    expect((float) $row['po_revisi'] === 15.0, 'expected po_revisi=15, got ' . $row['po_revisi']);

    $stmt2 = $pdo->prepare('SELECT si.po_awal FROM po_store_item si INNER JOIN po_item i ON i.po_item_id = si.po_item_id
        INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND i.product_id = ?');
    $stmt2->execute(['2026-02-06', $knownProductA['product_id']]);
    expect((float) $stmt2->fetchColumn() === 50.0, 'the existing PO Awal (50) for the original product must remain locked/unchanged');
});

runTest('P2-NP12 creating a new product from an unresolved PO row writes an audit_log row', function () use ($pdo, $newProductRawCode) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'po.product.create_from_import' AND record_type = 'product'");
    $stmt->execute();
    expect((int) $stmt->fetchColumn() >= 1, 'expected at least one po.product.create_from_import audit_log row');
});

// ---------------------------------------------------------------------
// PO Revisi confirmation step (the small UX patch) — separate from the
// New Product Detection feature above, tested end to end over plain HTTP.
// ---------------------------------------------------------------------
runTest('P2-REV01 an initial PO import is completely unaffected — no confirmation box, direct commit', function () use ($http, $knownProductB) {
    resetStaged($http);
    $csrf = freshCsrf($http);
    $csv = writeCsvFile([...karangtengahHeader(), karangtengahRow($knownProductB['legacy_code'], $knownProductB['name'], 20, 0, 0, 0)]);
    $r = $http->postMultipart('/_import-po/', ['csrf' => $csrf, 'tanggal' => '2026-02-07', 'uploadType' => 'initial'], 'file', $csv, 'rev01.csv');
    unlink($csv);
    expect(!str_contains($r['body'], 'Konfirmasi PO Revisi'), 'an initial upload must never show the revision confirmation box');
    $csrf2 = extractCsrf($r['body']);
    $import = $http->postForm('/_import-po/', ['csrf' => $csrf2, 'action' => 'confirm_import']);
    expect(str_contains($import['body'], 'PO berhasil diimpor'), 'expected the initial import to commit directly, no modal: ' . substr($import['body'], 0, 400));
});

runTest('P2-REV02 a revision import shows the confirmation box, and confirm_import alone (skipping it) is rejected', function () use ($http, $knownProductB) {
    resetStaged($http);
    $csrf = freshCsrf($http);
    $csv = writeCsvFile([...karangtengahHeader(), karangtengahRow($knownProductB['legacy_code'], $knownProductB['name'], 20, 0, 4, 0)]);
    $r = $http->postMultipart('/_import-po/', ['csrf' => $csrf, 'tanggal' => '2026-02-07', 'uploadType' => 'revision'], 'file', $csv, 'rev02.csv');
    unlink($csv);
    expect(!str_contains($r['body'], 'Konfirmasi PO Revisi'), 'the box only appears after clicking the commit button, not right after preview');

    $csrf2 = extractCsrf($r['body']);
    $bypass = $http->postForm('/_import-po/', ['csrf' => $csrf2, 'action' => 'confirm_import']);
    expect(str_contains($bypass['body'], 'Konfirmasi diperlukan'), 'directly posting confirm_import for a revision without review_revision first must be rejected');

    global $pdo;
    $stmt = $pdo->prepare('SELECT po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND i.product_id = ?');
    $stmt->execute(['2026-02-07', $GLOBALS['knownProductB']['product_id']]);
    expect((float) $stmt->fetchColumn() === 0.0, 'the bypassed confirm_import must not have written anything — po_revisi should still be 0 from the initial upload');
});

runTest('P2-REV03 review_revision shows the exact required copy, cancel writes nothing, then confirming commits', function () use ($http) {
    $csrf = freshCsrf($http);
    $review = $http->postForm('/_import-po/', ['csrf' => $csrf, 'action' => 'review_revision']);
    expect(str_contains($review['body'], 'Konfirmasi PO Revisi'), 'expected the modal title');
    expect(str_contains($review['body'], 'File ini masih dapat mengandung nilai PO Awal.'), 'expected the required body copy');
    expect(str_contains($review['body'], 'Sistem TIDAK akan mengubah atau menambahkan ulang PO Awal'), 'expected the required PO Awal guarantee copy');
    expect(str_contains($review['body'], 'target menjadi'), 'expected the worked example');
    expect(str_contains($review['body'], 'Lanjutkan proses PO Revisi?'), 'expected the required closing question');

    $csrf2 = extractCsrf($review['body']);
    $cancel = $http->postForm('/_import-po/', ['csrf' => $csrf2, 'action' => 'cancel_revision_review']);
    expect(!str_contains($cancel['body'], 'PO berhasil diimpor'), 'canceling must not import anything');

    global $pdo;
    $stmt = $pdo->prepare('SELECT po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND i.product_id = ?');
    $stmt->execute(['2026-02-07', $GLOBALS['knownProductB']['product_id']]);
    expect((float) $stmt->fetchColumn() === 0.0, 'canceling the revision confirmation must leave po_revisi unchanged at 0');

    $csrf3 = extractCsrf($cancel['body']);
    $review2 = $http->postForm('/_import-po/', ['csrf' => $csrf3, 'action' => 'review_revision']);
    $csrf4 = extractCsrf($review2['body']);
    $confirm = $http->postForm('/_import-po/', ['csrf' => $csrf4, 'action' => 'confirm_import']);
    expect(str_contains($confirm['body'], 'PO berhasil diimpor'), 'expected the revision to actually commit once explicitly confirmed: ' . substr($confirm['body'], 0, 400));

    $stmt2 = $pdo->prepare('SELECT po_revisi FROM po_item i INNER JOIN po_batch b ON b.po_batch_id = i.po_batch_id WHERE b.tanggal = ? AND i.product_id = ?');
    $stmt2->execute(['2026-02-07', $GLOBALS['knownProductB']['product_id']]);
    expect((float) $stmt2->fetchColumn() === 4.0, 'expected po_revisi=4 after the confirmed revision import');
});

// ---------------------------------------------------------------------
$total = count($results);
$passed = count(array_filter($results));
fwrite(STDOUT, "\n{$passed}/{$total} passed\n");
exit($passed === $total ? 0 : 1);
