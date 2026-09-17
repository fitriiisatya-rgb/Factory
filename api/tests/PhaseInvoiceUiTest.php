<?php

declare(strict_types=1);

/**
 * Invoice print/preview template integration suite (INV-UI01..14).
 * Run via api/tests/run-invoice-ui-preview.sh, which stands up a
 * disposable local MariaDB, applies ALL migrations 0001-0006, bootstraps
 * realistic master data, then drives the real
 * /api/_ui-preview/invoice-preview.php page against a live `php -S`
 * server.
 *
 * Phase 6 Invoice backend does not exist — this page renders a fixed mock
 * fixture (api/app/ui/fixtures/invoice-mock.php), so these tests do not
 * seed any invoice data; they only assert the RENDERED HTML/CSS matches
 * the task spec (fields shown, fields intentionally absent, print CSS
 * behavior) and that opening the page never writes to the database.
 *
 * INV-UI15 (full Phase 0-5 + UI + Print regression green) is NOT a test
 * in this file — it is the orchestrator's own final step, reported
 * alongside this suite's own result rather than faked as a runTest() call
 * here (same pattern as PhasePrintTest.php's own PRINT-13).
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8107';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'invoice_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpInvoice
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'invcookies');
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

function login(HttpInvoice $http, string $username, string $password): string
{
    $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    $me = $http->request('GET', '/api/auth/me');
    $csrf = $me['json']['data']['csrfToken'] ?? null;
    expect($csrf !== null, "expected a csrf token after login as {$username}");
    return $csrf;
}

/** @return array<string,int> table => row count, for the tables Phase 6 would eventually write to */
function invoiceRelatedRowCounts(PDO $pdo): array
{
    $counts = [];
    foreach (['invoice', 'invoice_item', 'invoice_shipment', 'payment'] as $table) {
        $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
    return $counts;
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
// Auth guard, checked BEFORE login with a genuinely empty cookie jar —
// the invoice preview page reuses the same bootstrap.php as every other
// /api/_ui-preview/ page, so it must never be reachable anonymously.
// ---------------------------------------------------------------------
runTest('INV-UI01 invoice preview renders (and requires authentication)', function () use ($baseUrl, $adminUser, $adminPass) {
    $anon = new HttpInvoice($baseUrl);
    $r = $anon->request('GET', '/_ui-preview/invoice-preview.php');
    expect(in_array($r['status'], [301, 302, 303, 307, 308], true), "expected a redirect for an unauthenticated request, got {$r['status']}");
    expect(stripos($r['headers'], '_admin-login') !== false, 'expected redirect Location to point at _admin-login');

    $http = new HttpInvoice($baseUrl);
    login($http, $adminUser, $adminPass);
    $r2 = $http->request('GET', '/_ui-preview/invoice-preview.php');
    expect($r2['status'] === 200, "expected 200 once authenticated, got {$r2['status']}");
    expect(str_contains($r2['body'], 'INVOICE'), 'expected the INVOICE title to render');
    expect(str_contains($r2['body'], 'INV/MOCK/'), 'expected the mock invoice number to render');
});

$http = new HttpInvoice($baseUrl);
login($http, $adminUser, $adminPass);
$page = $http->request('GET', '/_ui-preview/invoice-preview.php');
expect($page['status'] === 200, 'setup: expected invoice preview to load 200');
$body = $page['body'];

runTest('INV-UI02 logo/header renders (Amor logo image + Amor Cakes & Bakery brand name)', function () use ($body) {
    expect(str_contains($body, '/api/app/ui/assets/img/amor-logo.png'), 'expected the shared Amor logo image to be referenced');
    expect(str_contains($body, 'Amor Cakes &amp; Bakery') || str_contains($body, 'Amor Cakes & Bakery'), 'expected the Amor Cakes & Bakery brand name');
    expect(!str_contains($body, 'Amor Factory System</div>') && !preg_match('/print-brand-name">\s*Amor Factory System/', $body), 'the printed brand title must not be "Amor Factory System"');
});

runTest('INV-UI03 customer name/address renders', function () use ($body) {
    expect(str_contains($body, 'Informasi Pelanggan'), 'expected the customer info section');
    expect(str_contains($body, 'Toko Contoh'), 'expected the mock store name to render');
    expect(str_contains($body, 'Jl. Contoh Alamat'), 'expected the mock address to render');
});

runTest('INV-UI04 Kode Toko is absent', function () use ($body) {
    expect(!str_contains($body, 'Kode Toko'), 'Kode Toko must never be shown on the invoice');
});

runTest('INV-UI05 Termin Pembayaran is absent', function () use ($body) {
    expect(!str_contains($body, 'Termin'), 'Termin Pembayaran must never be shown on the invoice');
});

runTest('INV-UI06 Jatuh Tempo is absent', function () use ($body) {
    expect(!str_contains($body, 'Jatuh Tempo'), 'Jatuh Tempo must never be shown on the invoice');
});

runTest('INV-UI07 Kode Produk / Divisi columns are absent from the product table', function () use ($body) {
    expect(!str_contains($body, 'Kode Produk'), 'Kode Produk column must never be shown on the invoice');
    expect(!preg_match('/<th[^>]*>\s*Divisi\s*<\/th>/', $body), 'Divisi column must never be shown on the invoice table');
});

runTest('INV-UI08 Catatan/notes section is absent by default (mock fixture has no notes)', function () use ($body) {
    expect(!str_contains($body, 'inv-notes'), 'the mock fixture sets no notes, so the Catatan block must not render at all');
});

runTest('INV-UI09 bank/account section is absent', function () use ($body) {
    foreach (['Bank', 'Rekening', 'No. Rekening', 'a.n.'] as $needle) {
        expect(!str_contains($body, $needle), "bank/account text \"{$needle}\" must never appear on the invoice");
    }
});

runTest('INV-UI10 Total Tagihan only appears once, in the bottom summary box', function () use ($body) {
    $count = substr_count($body, 'Total Tagihan');
    expect($count === 1, "expected exactly one \"Total Tagihan\" occurrence (bottom summary only), got {$count}");
    $headerPos = strpos($body, 'inv-title-block');
    $totalPos = strpos($body, 'Total Tagihan');
    expect($headerPos !== false && $totalPos !== false && $totalPos > $headerPos, 'Total Tagihan must render after (below) the header block, not inside it');
    expect(str_contains($body, 'inv-summary-total'), 'expected the Total Tagihan row to carry the brown-accent summary class');
});

runTest('INV-UI11 quantities and prices are right-aligned with Indonesian Rp formatting', function () use ($body) {
    expect(preg_match('/<td class="num">Rp [0-9.]+<\/td>/', $body) === 1, 'expected at least one right-aligned Rp-formatted table cell');
    expect(str_contains($body, 'Roti Bollen Coklat'), 'expected the mock product rows to render');
});

runTest('INV-UI12 print CSS hides preview toolbar/notice under @media print', function () {
    $css = file_get_contents(__DIR__ . '/../app/ui/assets/css/print-invoice.css');
    expect($css !== false, 'expected print-invoice.css to exist');
    expect(preg_match('/@media\s+print\s*\{(.*)\}\s*$/s', $css, $m) === 1, 'expected an @media print block');
    $printBlock = $m[1];
    expect(str_contains($printBlock, '.print-toolbar') && str_contains($printBlock, '.inv-preview-notice') && str_contains($printBlock, 'display: none'), 'expected @media print to hide both the toolbar and the mock-data notice');
});

runTest('INV-UI13 A4 print layout is declared', function () {
    $css = file_get_contents(__DIR__ . '/../app/ui/assets/css/print-invoice.css');
    expect((bool) preg_match('/@page\s*\{\s*size:\s*A4\s+portrait/', (string) $css), 'expected @page { size: A4 portrait } in print-invoice.css');
});

runTest('INV-UI14 opening the invoice preview writes nothing to the database', function () use ($http, $pdo) {
    $before = invoiceRelatedRowCounts($pdo);
    // Load it a few more times, same as a reviewer refreshing the page.
    for ($i = 0; $i < 3; $i++) {
        $r = $http->request('GET', '/_ui-preview/invoice-preview.php');
        expect($r['status'] === 200, 'expected repeated loads to keep returning 200');
    }
    $after = invoiceRelatedRowCounts($pdo);
    expect($before === $after, 'expected invoice/invoice_item/invoice_shipment/payment row counts to be unchanged: ' . json_encode($before) . ' vs ' . json_encode($after));
    foreach ($after as $table => $count) {
        expect($count === 0, "expected {$table} to have 0 rows (Phase 6 never implemented) even after previewing, got {$count}");
    }
});

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
