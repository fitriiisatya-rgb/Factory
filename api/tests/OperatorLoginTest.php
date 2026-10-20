<?php

declare(strict_types=1);

/**
 * Shared Division Login + Operator Name (migration 0019) — OPLOGIN-01..19.
 *
 * Drives the REAL HTML login page (api/_admin-login/) with form posts, not
 * the JSON login API, because the "Siapa Anda?" prompt, the ?return=
 * redirect and the role widening all live in that page. Production writes
 * then go through the real JSON API with the same session cookie, so the
 * operator-name stamping is checked end to end (session -> controller ->
 * service -> production_run columns -> DTO -> produksi.php).
 *
 * Covers the ordering bug from an earlier build of this feature: the
 * operator-name gate must run AFTER the per-page 403 gates, so a PRODUCTION
 * account that has not named itself yet gets 403 (not a 302 to the prompt)
 * on a page it may not open (OPLOGIN-12).
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8150';
$adminUser = getenv('TEST_ADMIN_USER') ?: '';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpOp
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'opcookies');
    }

    /** @return array{status:int,json:?array,body:string,location:?string} */
    private function exec(string $method, string $path, $postFields, array $hdrLines): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_HTTPHEADER => $hdrLines,
        ]);
        if ($postFields !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('curl error: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $location = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : null;
        $json = $body === '' ? null : json_decode($body, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $body, 'location' => $location];
    }

    public function get(string $path): array
    {
        return $this->exec('GET', $path, null, []);
    }

    public function form(string $path, array $fields): array
    {
        return $this->exec('POST', $path, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
    }

    public function json(string $method, string $path, ?array $body, array $headers = []): array
    {
        $hdr = ['Content-Type: application/json'];
        foreach ($headers as $k => $v) {
            $hdr[] = "{$k}: {$v}";
        }
        return $this->exec($method, $path, $body !== null ? json_encode($body) : null, $hdr);
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

/** CSRF token for the current session, via the JSON me endpoint. */
function csrfOf(HttpOp $http): string
{
    $me = $http->json('GET', '/api/auth/me', null);
    $csrf = $me['json']['data']['csrfToken'] ?? null;
    expect($csrf !== null, 'expected a csrf token for the current session');
    return $csrf;
}

/** Hidden csrf_token field from the rendered "Siapa Anda?" form. */
function formCsrf(string $html): string
{
    expect(preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m) === 1, 'expected a csrf_token hidden field in the operator form');
    return $m[1];
}

function makeUser(PDO $pdo, string $username, string $fullName, string $password, array $roles, array $divisionIds = []): int
{
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName]);
    $userId = (int) $pdo->lastInsertId();
    foreach ($roles as $role) {
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, role_id FROM roles WHERE code = ?')->execute([$userId, $role]);
    }
    foreach ($divisionIds as $d) {
        $pdo->prepare('INSERT INTO user_division_access (user_id, division_id) VALUES (?, ?)')->execute([$userId, $d]);
    }
    return $userId;
}

function seedStorePo(PDO $pdo, string $tanggal, int $factoryId, int $storeId, array $items): void
{
    $find = $pdo->prepare('SELECT po_batch_id FROM po_batch WHERE tanggal = ? AND factory_id = ?');
    $find->execute([$tanggal, $factoryId]);
    $batchId = $find->fetchColumn();
    if ($batchId === false) {
        $pdo->prepare('INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())')
            ->execute([$tanggal, $factoryId]);
        $batchId = (int) $pdo->lastInsertId();
    }
    $upsertItem = $pdo->prepare(
        'INSERT INTO po_item (po_batch_id, product_id, po_awal, po_revisi, pb) VALUES (?, ?, ?, 0, 0)
         ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal)'
    );
    $findItem = $pdo->prepare('SELECT po_item_id FROM po_item WHERE po_batch_id = ? AND product_id = ?');
    $upsertStoreItem = $pdo->prepare(
        'INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal)'
    );
    foreach ($items as $productId => $qty) {
        $upsertItem->execute([$batchId, $productId, $qty]);
        $findItem->execute([$batchId, $productId]);
        $upsertStoreItem->execute([(int) $findItem->fetchColumn(), $storeId, $qty]);
    }
}

function runRow(PDO $pdo, int $runId): array
{
    $stmt = $pdo->prepare('SELECT * FROM production_run WHERE production_run_id = ?');
    $stmt->execute([$runId]);
    return $stmt->fetch();
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

// ---------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------
$pdo = new PDO("mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4", $runtimeUser, $runtimePass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$karangtengahId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
expect($karangtengahId > 0, 'expected Karangtengah factory seeded');
$rotiDivId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
expect($rotiDivId > 0, 'expected Roti & Bollen division seeded');
$storeA = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
expect($storeA > 0, 'expected P2 TEST STORE A fixture seeded');
$products = $pdo->query("SELECT product_id FROM product WHERE division_id = {$rotiDivId} ORDER BY product_id LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
expect(count($products) === 4, 'expected Roti & Bollen products');

$tanggal = '2026-11-03';
$tanggalAdmin = '2026-11-04';
seedStorePo($pdo, $tanggal, $karangtengahId, $storeA, [(int) $products[0] => 20.0, (int) $products[1] => 10.0]);
seedStorePo($pdo, $tanggalAdmin, $karangtengahId, $storeA, [(int) $products[2] => 5.0]);

$prodPass = 'DivisiRoti#2026';
makeUser($pdo, 'divisi_roti', 'Divisi Roti & Bollen', $prodPass, ['PRODUCTION'], [$rotiDivId]);
$ppicPass = 'PpicOpLogin#2026';
makeUser($pdo, 'oplogin_ppic', 'PPIC Satu', $ppicPass, ['PPIC']);
$fgPass = 'FgOpLogin#2026';
makeUser($pdo, 'oplogin_fg', 'FG Packing Satu', $fgPass, ['FG_PACKING']);

$prod = new HttpOp($baseUrl);
$runId = 0;
$runVersion = 0;

// ===================================================================
// Login + "Siapa Anda?" prompt
// ===================================================================
runTest('OPLOGIN-01 PRODUCTION shared account logs in through the real HTML form and is asked "Siapa Anda?"', function () use ($prod, $prodPass) {
    $r = $prod->form('/_admin-login/', ['action' => 'login', 'username' => 'divisi_roti', 'password' => $prodPass, 'return' => '/api/_ui-preview/?page=produksi']);
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    expect(str_contains($r['body'], 'Siapa Anda?'), 'expected the operator-name prompt');
    expect(str_contains($r['body'], 'name="return" value="/api/_ui-preview/?page=produksi"'), 'expected the return target carried into the prompt form');
});

runTest('OPLOGIN-02 Before naming itself, a production page redirects back to the prompt with ?return=', function () use ($prod) {
    $r = $prod->get('/api/_ui-preview/?page=produksi');
    expect($r['status'] === 302, "expected 302, got {$r['status']}");
    expect(str_contains((string) $r['location'], '/api/_admin-login/?return='), 'expected redirect to the login/prompt page, got ' . $r['location']);
    expect(str_contains(urldecode((string) $r['location']), 'page=produksi'), 'expected the original page in ?return=');
    $prompt = $prod->get('/_admin-login/?return=' . urlencode('/api/_ui-preview/?page=produksi'));
    expect(str_contains($prompt['body'], 'Siapa Anda?'), 'expected GET of the login page to show the prompt again');
});

runTest('OPLOGIN-03 Prompt rejects a missing CSRF token and an empty name, and stores nothing', function () use ($prod) {
    $r = $prod->form('/_admin-login/', ['action' => 'set_operator_name', 'operatorName' => 'Penyusup']);
    expect(str_contains($r['body'], 'Sesi tidak valid'), 'expected a CSRF rejection');
    $prompt = $prod->get('/_admin-login/');
    $r2 = $prod->form('/_admin-login/', ['action' => 'set_operator_name', 'operatorName' => '  ', 'csrf_token' => formCsrf($prompt['body'])]);
    expect(str_contains($r2['body'], 'Nama wajib diisi'), 'expected an empty-name rejection');
    $me = $prod->json('GET', '/api/auth/me', null);
    expect(($me['status'] === 200 && array_key_exists('operatorName', $me['json']['data'] ?? []) && $me['json']['data']['operatorName'] === null), 'expected no operator name stored yet');
});

runTest('OPLOGIN-04 Naming redirects to ?return= and the topbar shows "{role} · {name} (ganti)"', function () use ($prod) {
    $prompt = $prod->get('/_admin-login/');
    $r = $prod->form('/_admin-login/', ['action' => 'set_operator_name', 'operatorName' => 'Budi', 'csrf_token' => formCsrf($prompt['body']), 'return' => '/api/_ui-preview/?page=produksi']);
    expect($r['status'] === 302 && $r['location'] === '/api/_ui-preview/?page=produksi', "expected 302 to the return page, got {$r['status']} " . $r['location']);
    $page = $prod->get('/api/_ui-preview/?page=produksi');
    expect($page['status'] === 200, "expected produksi page 200, got {$page['status']}");
    expect(str_contains($page['body'], 'PRODUCTION &middot; Budi'), 'expected role + operator name in the topbar');
    expect(str_contains($page['body'], 'action=change_operator'), 'expected the (ganti) link');
});

runTest('OPLOGIN-05 ?return= only accepts /api/_ui-preview/ targets (no open redirect)', function () use ($baseUrl, $prodPass) {
    foreach (['https://evil.example/', '//evil.example/x', '/api/_upgrade/'] as $bad) {
        $h = new HttpOp($baseUrl);
        $h->form('/_admin-login/', ['action' => 'login', 'username' => 'divisi_roti', 'password' => $prodPass, 'return' => $bad]);
        $prompt = $h->get('/_admin-login/');
        $r = $h->form('/_admin-login/', ['action' => 'set_operator_name', 'operatorName' => 'Tes', 'csrf_token' => formCsrf($prompt['body']), 'return' => $bad]);
        expect($r['status'] === 302 && $r['location'] === '/api/_ui-preview/', "expected fallback to /api/_ui-preview/ for {$bad}, got " . $r['location']);
    }
});

// ===================================================================
// Stamping on create / patch / submit
// ===================================================================
runTest('OPLOGIN-06 Creating a draft stamps created_by/last_updated_by operator name', function () use ($prod, $pdo, $tanggal, $rotiDivId, &$runId, &$runVersion) {
    $csrf = csrfOf($prod);
    $r = $prod->json('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $rotiDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('op-create')));
    expect($r['status'] === 200, 'create failed: ' . $r['body']);
    $runId = (int) $r['json']['data']['productionRunId'];
    $runVersion = (int) $r['json']['data']['version'];
    expect($r['json']['data']['createdByOperatorName'] === 'Budi', 'expected DTO createdByOperatorName=Budi');
    $row = runRow($pdo, $runId);
    expect($row['created_by_operator_name'] === 'Budi' && $row['last_updated_by_operator_name'] === 'Budi', 'expected Budi stamped on create, got ' . json_encode($row));
    expect($row['submitted_by_operator_name'] === null, 'expected submitted_by_operator_name still NULL');
});

runTest('OPLOGIN-07 "(ganti)" switches the operator; an edit stamps the new name, creator unchanged', function () use ($prod, $pdo, $products, &$runId, &$runVersion) {
    $g = $prod->get('/_admin-login/?action=change_operator&return=' . urlencode('/api/_ui-preview/?page=produksi'));
    expect(str_contains($g['body'], 'Siapa Anda?') && str_contains($g['body'], 'value="Budi"'), 'expected the prompt prefilled with the current name');
    $me = $prod->json('GET', '/api/auth/me', null);
    expect(($me['json']['data']['operatorName'] ?? null) === 'Budi', 'a GET of (ganti) must not change the stored name');
    $r = $prod->form('/_admin-login/', ['action' => 'set_operator_name', 'operatorName' => 'Siti', 'csrf_token' => formCsrf($g['body'])]);
    expect($r['status'] === 302, 'expected redirect after renaming');

    $csrf = csrfOf($prod);
    $p = $prod->json('PATCH', "/api/production/{$runId}", ['expectedVersion' => $runVersion, 'items' => [['productId' => (int) $products[0], 'actualQty' => 18]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('op-patch')));
    expect($p['status'] === 200, 'patch failed: ' . $p['body']);
    $runVersion = (int) $p['json']['data']['version'];
    $row = runRow($pdo, $runId);
    expect($row['created_by_operator_name'] === 'Budi', 'creator must stay Budi');
    expect($row['last_updated_by_operator_name'] === 'Siti', 'expected last_updated_by=Siti, got ' . $row['last_updated_by_operator_name']);
});

runTest('OPLOGIN-08 Detail card shows "Dibuat oleh" and "Terakhir diupdate oleh" when they differ', function () use ($prod, $tanggal, $karangtengahId, &$runId) {
    $page = $prod->get("/api/_ui-preview/?page=produksi&tanggal={$tanggal}&factoryId={$karangtengahId}&runId={$runId}");
    expect($page['status'] === 200, "expected 200, got {$page['status']}");
    expect(str_contains($page['body'], 'Dibuat oleh: <strong>Budi</strong>'), 'expected creator line');
    expect(str_contains($page['body'], 'Terakhir diupdate oleh: <strong>Siti</strong>'), 'expected last-updated line');
});

runTest('OPLOGIN-09 Submit stamps submitted_by operator name; summary "Disubmit Oleh" shows it', function () use ($prod, $pdo, $tanggal, $karangtengahId, &$runId, &$runVersion) {
    $csrf = csrfOf($prod);
    $s = $prod->json('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $runVersion], array_merge(['X-CSRF-Token' => $csrf], idemKey('op-submit')));
    expect($s['status'] === 200, 'submit failed: ' . $s['body']);
    expect($s['json']['data']['submittedByOperatorName'] === 'Siti', 'expected DTO submittedByOperatorName=Siti');
    $row = runRow($pdo, $runId);
    expect($row['submitted_by_operator_name'] === 'Siti', 'expected submitted_by_operator_name=Siti');
    $page = $prod->get("/api/_ui-preview/?page=produksi&tanggal={$tanggal}&factoryId={$karangtengahId}");
    // A single-division account gets the short page: its own day card, and
    // that day's document opens by itself (no per-division summary table).
    expect(str_contains($page['body'], 'class="card section day-card"'), 'expected the division day card');
    expect(str_contains($page['body'], 'Disubmit oleh: <strong>Siti</strong>'), 'expected the day\'s document to open by itself with its submitter');
    $detail = $prod->get("/api/_ui-preview/?page=produksi&tanggal={$tanggal}&factoryId={$karangtengahId}&runId={$runId}");
    expect(str_contains($detail['body'], 'Disubmit oleh: <strong>Siti</strong>'), 'expected submitter line on the detail card');
});

// ===================================================================
// ADMIN / PPIC are never asked; other roles still rejected
// ===================================================================
runTest('OPLOGIN-10 ADMIN never sees the prompt; ADMIN writes leave operator names NULL and the summary falls back to the account name', function () use ($baseUrl, $adminUser, $adminPass, $pdo, $tanggalAdmin, $rotiDivId, $karangtengahId, $products) {
    $a = new HttpOp($baseUrl);
    $r = $a->form('/_admin-login/', ['action' => 'login', 'username' => $adminUser, 'password' => $adminPass]);
    expect(str_contains($r['body'], 'Login berhasil sebagai ADMIN') && !str_contains($r['body'], 'Siapa Anda?'), 'expected the plain ADMIN success page');
    expect(str_contains($r['body'], '../_ui-preview/'), 'expected a link into the main UI');
    $r2 = $a->form('/_admin-login/', ['action' => 'login', 'username' => $adminUser, 'password' => $adminPass, 'return' => '/api/_ui-preview/?page=laporan']);
    expect($r2['status'] === 302 && $r2['location'] === '/api/_ui-preview/?page=laporan', 'expected ADMIN to be sent straight to ?return=');

    $csrf = csrfOf($a);
    $c = $a->json('POST', '/api/production', ['tanggal' => $tanggalAdmin, 'divisionId' => $rotiDivId], array_merge(['X-CSRF-Token' => $csrf], idemKey('op-admin-create')));
    expect($c['status'] === 200, 'admin create failed: ' . $c['body']);
    $id = (int) $c['json']['data']['productionRunId'];
    $p = $a->json('PATCH', "/api/production/{$id}", ['expectedVersion' => $c['json']['data']['version'], 'items' => [['productId' => (int) $products[2], 'actualQty' => 5]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('op-admin-patch')));
    $s = $a->json('POST', "/api/production/{$id}/submit", ['expectedVersion' => $p['json']['data']['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey('op-admin-submit')));
    expect($s['status'] === 200, 'admin submit failed: ' . $s['body']);
    $row = runRow($pdo, $id);
    expect($row['created_by_operator_name'] === null && $row['submitted_by_operator_name'] === null, 'expected NULL operator names for ADMIN');
    $page = $a->get("/api/_ui-preview/?page=produksi&tanggal={$tanggalAdmin}&factoryId={$karangtengahId}");
    expect($page['status'] === 200, "expected 200, got {$page['status']}");
    $fullName = (string) $pdo->query('SELECT full_name FROM users WHERE username = ' . $pdo->quote($adminUser))->fetchColumn();
    expect(preg_match('#<td[^>]*>' . preg_quote(htmlspecialchars($fullName, ENT_QUOTES), '#') . '</td>#', $page['body']) === 1, 'expected the ADMIN account name as Disubmit Oleh fallback');
});

runTest('OPLOGIN-11 PPIC can now log in, is never prompted, and lands in the main UI', function () use ($baseUrl, $ppicPass) {
    $h = new HttpOp($baseUrl);
    $r = $h->form('/_admin-login/', ['action' => 'login', 'username' => 'oplogin_ppic', 'password' => $ppicPass]);
    expect($r['status'] === 302 && $r['location'] === '/api/_ui-preview/', "expected 302 to the main UI, got {$r['status']} " . $r['location']);
    $page = $h->get('/api/_ui-preview/?page=laporan');
    expect($page['status'] === 200, "expected PPIC to open Laporan as before, got {$page['status']}");
    expect(!str_contains($page['body'], 'Siapa Anda?'), 'PPIC must never be prompted');
});

runTest('OPLOGIN-12 Gate ordering: an UNNAMED production account gets 403 (not a 302 to the prompt) on pages it may not open', function () use ($baseUrl, $prodPass) {
    $h = new HttpOp($baseUrl);
    $h->form('/_admin-login/', ['action' => 'login', 'username' => 'divisi_roti', 'password' => $prodPass]);
    foreach (['invoice', 'replacement-reject', 'bakery-portal-tokens', 'laporan', 'master-data', 'dashboard', 'pesanan-toko'] as $p) {
        $r = $h->get('/api/_ui-preview/?page=' . $p);
        expect($r['status'] === 403, "expected 403 on page={$p} for an unnamed production account, got {$r['status']} " . $r['location']);
    }
    $r = $h->get('/api/_ui-preview/');
    expect($r['status'] === 302 && str_contains((string) $r['location'], '_admin-login'), 'expected the default page (produksi) to go to the prompt');
});

runTest('OPLOGIN-13 A fresh login in the same browser never inherits the previous operator name', function () use ($prod, $prodPass) {
    $r = $prod->form('/_admin-login/', ['action' => 'login', 'username' => 'divisi_roti', 'password' => $prodPass]);
    expect(str_contains($r['body'], 'Siapa Anda?'), 'expected the prompt again after re-login');
    $me = $prod->json('GET', '/api/auth/me', null);
    expect(($me['status'] === 200 && array_key_exists('operatorName', $me['json']['data'] ?? []) && $me['json']['data']['operatorName'] === null), 'expected the old name (Siti) cleared on login');
});

runTest('OPLOGIN-14 Sidebar: ADMIN gets "Kelola User" -> /api/_users-uat/; a production account sees only Produksi/FG', function () use ($baseUrl, $adminUser, $adminPass, $prod) {
    $a = new HttpOp($baseUrl);
    $a->form('/_admin-login/', ['action' => 'login', 'username' => $adminUser, 'password' => $adminPass]);
    $page = $a->get('/api/_ui-preview/?page=dashboard');
    expect(str_contains($page['body'], 'href="/api/_users-uat/"') && str_contains($page['body'], 'Kelola User'), 'expected Kelola User nav for ADMIN');

    $prompt = $prod->get('/_admin-login/');
    $prod->form('/_admin-login/', ['action' => 'set_operator_name', 'operatorName' => 'Andi', 'csrf_token' => formCsrf($prompt['body'])]);
    $pp = $prod->get('/api/_ui-preview/?page=produksi');
    expect($pp['status'] === 200, "expected 200, got {$pp['status']}");
    expect(!str_contains($pp['body'], '_users-uat'), 'production account must not see Kelola User');
    expect(!str_contains($pp['body'], '?page=laporan') && !str_contains($pp['body'], '?page=master-data') && !str_contains($pp['body'], '?page=invoice'), 'production account nav must not link Laporan/Master Data/Invoice');
    expect(str_contains($pp['body'], '?page=fg-packing'), 'expected FG & Packing in the production nav');
    $users = $prod->get('/_users-uat/');
    expect($users['status'] === 403 || str_contains($users['body'], 'Akses ditolak'), 'the users tool itself must still deny a production account');
});

runTest('OPLOGIN-15 Migration 0019 columns are NULLable VARCHAR(150) after updated_at; FG_PACKING logs in to FG only; other roles still refused', function () use ($pdo, $baseUrl, $fgPass) {
    $cols = $pdo->query("SELECT column_name, is_nullable, character_maximum_length, ordinal_position FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = 'production_run' ORDER BY ordinal_position")->fetchAll();
    $byName = [];
    foreach ($cols as $c) {
        $byName[$c['column_name']] = $c;
    }
    foreach (['created_by_operator_name', 'submitted_by_operator_name', 'last_updated_by_operator_name'] as $n) {
        expect(isset($byName[$n]), "expected column {$n}");
        expect($byName[$n]['is_nullable'] === 'YES' && (int) $byName[$n]['character_maximum_length'] === 150, "expected {$n} NULL VARCHAR(150)");
    }
    expect((int) $byName['created_by_operator_name']['ordinal_position'] === (int) $byName['updated_at']['ordinal_position'] + 1, 'expected created_by_operator_name right after updated_at');

    // FG_PACKING logs in (no "Siapa Anda?" prompt) and lands on FG & Packing only.
    $h = new HttpOp($baseUrl);
    $r = $h->form('/_admin-login/', ['action' => 'login', 'username' => 'oplogin_fg', 'password' => $fgPass]);
    expect($r['status'] === 302 && $r['location'] === '/api/_ui-preview/', 'expected FG_PACKING to be admitted, got ' . $r['status'] . ' ' . $r['location']);
    $fgHome = $h->get('/api/_ui-preview/');
    expect($fgHome['status'] === 200 && str_contains($fgHome['body'], '?page=fg-packing'), 'expected FG & Packing as the FG account home, got ' . $fgHome['status']);
    expect($h->get('/api/_ui-preview/?page=produksi')['status'] === 403, 'FG account must not open Produksi');
    expect($h->get('/api/_ui-preview/?page=laporan')['status'] === 403, 'FG account must not open Laporan');

    // A role that is still not admitted (MANAGEMENT_VIEWER) is refused and logged out.
    makeUser($pdo, 'oplogin_viewer', 'Viewer Satu', 'ViewerOpLogin#2026', ['MANAGEMENT_VIEWER']);
    $v = new HttpOp($baseUrl);
    $r = $v->form('/_admin-login/', ['action' => 'login', 'username' => 'oplogin_viewer', 'password' => 'ViewerOpLogin#2026']);
    expect($r['status'] === 200 && str_contains($r['body'], 'bukan ADMIN'), 'expected MANAGEMENT_VIEWER to be refused');
    $me = $v->json('GET', '/api/auth/me', null);
    expect($me['status'] === 401, 'expected the refused account to be logged out');
});

runTest('OPLOGIN-16 A DRIVER session (Driver Portal login) can no longer open Laporan/Master Data/Dashboard/Produksi in the Admin UI', function () use ($pdo, $baseUrl) {
    makeUser($pdo, 'oplogin_driver', 'Driver Satu', 'DriverOpLogin#2026', ['DRIVER']);
    $h = new HttpOp($baseUrl);
    $r = $h->form('/_driver-uat/login.php', ['username' => 'oplogin_driver', 'password' => 'DriverOpLogin#2026', 'return' => 'index.php']);
    expect($r['status'] === 302, "expected the driver portal login to succeed, got {$r['status']}");
    foreach (['laporan', 'master-data', 'dashboard', 'pesanan-toko', 'produksi', 'delivery-order'] as $p) {
        $page = $h->get('/api/_ui-preview/?page=' . $p);
        expect($page['status'] === 403, "expected 403 for DRIVER on page={$p}, got {$page['status']}");
    }
    $portal = $h->get('/_driver-uat/index.php?tab=tersedia');
    expect($portal['status'] === 200, 'the Driver Portal itself must keep working, got ' . $portal['status']);
});

runTest('OPLOGIN-17 Pesanan Khusus Actual entered by a named division account records the operator name (ADMIN leaves it NULL)', function () use ($baseUrl, $adminUser, $adminPass, $pdo, $storeA, $products, $prodPass) {
    $a = new HttpOp($baseUrl);
    $a->form('/_admin-login/', ['action' => 'login', 'username' => $adminUser, 'password' => $adminPass]);
    $acsrf = csrfOf($a);
    $c = $a->json('POST', '/api/special-orders', [
        'sourceType' => 'toko_khusus', 'storeId' => $storeA, 'orderDate' => '2026-11-05', 'requiredDate' => '2026-11-06',
        'items' => [['itemType' => 'existing_product', 'productId' => (int) $products[3], 'qty' => 4]],
    ], array_merge(['X-CSRF-Token' => $acsrf], idemKey('op-so-create')));
    expect($c['status'] === 200, 'special order create failed: ' . $c['body']);
    $orderId = (int) $c['json']['data']['orderId'];
    $cf = $a->json('POST', "/api/special-orders/{$orderId}/confirm", ['expectedVersion' => $c['json']['data']['version']], array_merge(['X-CSRF-Token' => $acsrf], idemKey('op-so-confirm')));
    $sd = $a->json('POST', "/api/special-orders/{$orderId}/send-to-production", ['expectedVersion' => $cf['json']['data']['version']], array_merge(['X-CSRF-Token' => $acsrf], idemKey('op-so-send')));
    expect($sd['status'] === 200, 'send-to-production failed: ' . $sd['body']);
    $itemId = (int) $pdo->query("SELECT special_order_item_id FROM special_order_item WHERE special_order_id = {$orderId}")->fetchColumn();

    $p = new HttpOp($baseUrl);
    $p->form('/_admin-login/', ['action' => 'login', 'username' => 'divisi_roti', 'password' => $prodPass]);
    $prompt = $p->get('/_admin-login/');
    $p->form('/_admin-login/', ['action' => 'set_operator_name', 'operatorName' => 'Dewi', 'csrf_token' => formCsrf($prompt['body'])]);
    $pcsrf = csrfOf($p);
    $r = $p->json('POST', "/api/special-orders/{$orderId}/actual", ['expectedVersion' => $sd['json']['data']['version'], 'items' => [['itemId' => $itemId, 'aktualProduksi' => 4, 'rejectProduksi' => 0]]], array_merge(['X-CSRF-Token' => $pcsrf], idemKey('op-so-actual')));
    expect($r['status'] === 200, 'actual entry failed: ' . $r['body']);
    $name = $pdo->query("SELECT actual_updated_by_operator_name FROM special_order_item WHERE special_order_item_id = {$itemId}")->fetchColumn();
    expect($name === 'Dewi', 'expected Dewi recorded on the special order item, got ' . var_export($name, true));

    $r2 = $a->json('POST', "/api/special-orders/{$orderId}/actual", ['expectedVersion' => $r['json']['data']['version'], 'items' => [['itemId' => $itemId, 'aktualProduksi' => 4, 'rejectProduksi' => 0]]], array_merge(['X-CSRF-Token' => $acsrf], idemKey('op-so-actual-admin')));
    expect($r2['status'] === 200, 'admin actual entry failed: ' . $r2['body']);
    $name2 = $pdo->query("SELECT actual_updated_by_operator_name FROM special_order_item WHERE special_order_item_id = {$itemId}")->fetchColumn();
    expect($name2 === null, 'expected NULL after an ADMIN write, got ' . var_export($name2, true));

    $cols = $pdo->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE()
        AND column_name IN ('actual_updated_by_operator_name', 'production_actual_updated_by_operator_name')")->fetchAll();
    expect(count($cols) === 2, 'expected both migration-0019 columns on special_order_item/replacement_demand, got ' . json_encode($cols));
});

runTest('OPLOGIN-18 Riwayat: a division account sees only its own divisions\' production history, FG sees FG history, a driver is refused', function () use ($pdo, $baseUrl, $prodPass, $fgPass) {
    $basicId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Basic'")->fetchColumn();
    expect($basicId > 0, 'expected the Basic division seeded');
    $pdo->prepare("INSERT INTO production_run (tanggal, division_id, status, version, created_at) VALUES ('2026-11-20', ?, 'draft', 1, UTC_TIMESTAMP())")->execute([$basicId]);
    $range = '&from=2000-01-01&to=2099-12-31';

    $p = new HttpOp($baseUrl);
    $p->form('/_admin-login/', ['action' => 'login', 'username' => 'divisi_roti', 'password' => $prodPass]);
    $prompt = $p->get('/_admin-login/');
    $p->form('/_admin-login/', ['action' => 'set_operator_name', 'operatorName' => 'Rudi', 'csrf_token' => formCsrf($prompt['body'])]);
    $r = $p->get('/api/_ui-preview/?page=riwayat' . $range);
    expect($r['status'] === 200, "expected 200 for the division Riwayat, got {$r['status']}");
    expect(str_contains($r['body'], 'Riwayat Produksi') && !str_contains($r['body'], 'Riwayat FG'), 'a division account sees production history only');
    expect(str_contains($r['body'], '<td>Roti &amp; Bollen</td>'), 'expected its own division rows');
    expect(!str_contains($r['body'], '<td>Basic</td>'), 'must never list another division\'s documents');
    expect(str_contains($r['body'], '?page=riwayat'), 'expected Riwayat in the division nav');

    $f = new HttpOp($baseUrl);
    $f->form('/_admin-login/', ['action' => 'login', 'username' => 'oplogin_fg', 'password' => $fgPass]);
    $fr = $f->get('/api/_ui-preview/?page=riwayat' . $range);
    expect($fr['status'] === 200 && str_contains($fr['body'], 'Riwayat FG') && !str_contains($fr['body'], 'Riwayat Produksi'), 'an FG account sees FG history only, got ' . $fr['status']);

    $d = new HttpOp($baseUrl);
    $d->form('/_driver-uat/login.php', ['username' => 'oplogin_driver', 'password' => 'DriverOpLogin#2026', 'return' => 'index.php']);
    expect($d->get('/api/_ui-preview/?page=riwayat')['status'] === 403, 'a DRIVER uses the Driver Portal Riwayat, not this page');
});

runTest('OPLOGIN-19 Laporan Omset: Non-Toko counts what was handed over; Selesai cannot be forced before shipment', function () use ($baseUrl, $adminUser, $adminPass, $products) {
    $a = new HttpOp($baseUrl);
    $a->form('/_admin-login/', ['action' => 'login', 'username' => $adminUser, 'password' => $adminPass]);
    $csrf = csrfOf($a);
    $make = function (string $source, string $fulfillment, float $qty) use ($a, $csrf, $products): array {
        $c = $a->json('POST', '/api/special-orders', [
            'sourceType' => 'non_toko', 'nonStoreSource' => $source, 'customerName' => 'Omset ' . $source,
            'fulfillmentType' => $fulfillment, 'orderDate' => '2026-12-01', 'requiredDate' => '2026-12-05',
            'items' => [['itemType' => 'existing_product', 'productId' => (int) $products[3], 'qty' => $qty, 'unitPrice' => 10000, 'charge' => 2500]],
        ], array_merge(['X-CSRF-Token' => $csrf], idemKey('omset-create')));
        expect($c['status'] === 200, 'non-toko order create failed: ' . $c['body']);
        $id = (int) $c['json']['data']['orderId'];
        $itemId = (int) $c['json']['data']['items'][0]['itemId'];
        $cf = $a->json('POST', "/api/special-orders/{$id}/confirm", ['expectedVersion' => $c['json']['data']['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey('omset-confirm')));
        $sd = $a->json('POST', "/api/special-orders/{$id}/send-to-production", ['expectedVersion' => $cf['json']['data']['version']], array_merge(['X-CSRF-Token' => $csrf], idemKey('omset-send')));
        expect($sd['status'] === 200, 'send-to-production failed: ' . $sd['body']);
        return [$id, $itemId, (int) $sd['json']['data']['version']];
    };
    $complete = function (int $id) use ($a, $csrf): array {
        $v = (int) $a->json('GET', "/api/special-orders/{$id}", null)['json']['data']['version'];
        return $a->json('POST', "/api/special-orders/{$id}/status", ['expectedVersion' => $v, 'status' => 'completed'], array_merge(['X-CSRF-Token' => $csrf], idemKey('omset-done')));
    };

    // Pickup (collected at the factory): Selesai only once FG-ready, then 3 x 10.000 + 2.500 = Rp32.500 counts.
    [$pickId, $pickItem, $pickV] = $make('cs', 'pickup', 3);
    $early = $complete($pickId);
    expect($early['status'] === 400 && ($early['json']['code'] ?? '') === 'NOT_FG_READY', 'a pickup order must not be Selesai before FG is ready: ' . $early['body']);
    $act = $a->json('POST', "/api/special-orders/{$pickId}/actual", ['expectedVersion' => $pickV, 'items' => [['itemId' => $pickItem, 'aktualProduksi' => 3, 'rejectProduksi' => 0]]], array_merge(['X-CSRF-Token' => $csrf], idemKey('omset-actual')));
    expect($act['status'] === 200, 'actual failed: ' . $act['body']);
    $ver = $a->json('POST', "/api/special-orders/items/{$pickItem}/verify-fg", ['fgVerifiedQty' => 3], array_merge(['X-CSRF-Token' => $csrf], idemKey('omset-verify')));
    expect($ver['status'] === 200, 'verify-fg failed: ' . $ver['body']);
    $done = $complete($pickId);
    expect($done['status'] === 200, 'marking a FG-ready pickup order Selesai failed: ' . $done['body']);

    // Pengiriman: never shipped -> Selesai refused, never counted.
    [$shipId] = $make('sales_executive', 'pengiriman', 5);
    $refused = $complete($shipId);
    expect($refused['status'] === 400 && ($refused['json']['code'] ?? '') === 'NOT_FULLY_SHIPPED', 'Selesai must be refused before shipment: ' . $refused['body']);

    $page = $a->get('/api/_ui-preview/?page=laporan&omzetDateFrom=2026-12-01&omzetDateTo=2026-12-31');
    expect($page['status'] === 200, "expected 200, got {$page['status']}");
    expect(str_contains($page['body'], 'Omset Non-Toko') && str_contains($page['body'], 'id="omset-non-toko"'), 'expected the Non-Toko omset block');
    expect(str_contains($page['body'], 'Rp32.500'), 'expected Rp32.500 non-toko omset');
    expect(str_contains($page['body'], '<td>CS</td>'), 'expected the CS row');
    expect(!str_contains($page['body'], '<td>Sales Eksekutif</td>'), 'an unshipped order must never count as omset');
});

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
$failed = array_filter($results, static fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests, ' . count($failed) . " failed.\n");
exit(count($failed) === 0 ? 0 : 1);
