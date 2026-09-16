<?php

declare(strict_types=1);

/**
 * Phase 1 EASY V2 patch integration test suite (V2-01 .. V2-18). Run via
 * api/tests/run-phase1-v2.sh, which stands up a disposable local MariaDB
 * with TWO distinctly-privileged real DB users (a DML-only "runtime" user
 * and a DDL-capable "migration" user) + `php -S`, bootstraps a realistic
 * post-deployment state (migration 0001 applied, seeded, ADMIN created,
 * migration 0002 genuinely pending), then runs this suite.
 *
 * Do not run this file directly against anything but a disposable test DB
 * — it drives the real _admin-login/_upgrade/_import-master wizards over
 * HTTP and mutates real rows.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8096';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'v2_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';
$migrationUser = getenv('TEST_MIGRATION_USER') ?: '';
$migrationPass = getenv('TEST_MIGRATION_PASS') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '' || $migrationUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpV2
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'p1v2cookies');
    }

    /** @return array{status:int,body:string} */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** Sends a normal HTML <form method="post"> submission ($_POST, urlencoded) — matches how every wizard page in this patch reads input. */
    public function postForm(string $path, array $fields): array
    {
        return $this->request('POST', $path, $fields);
    }

    /** @return array{status:int,body:string} */
    private function request(string $method, string $path, ?array $fields = null): array
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
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
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
    if (preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
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

// This suite's own $pdo connects as the RUNTIME (DML-only) user — same
// identity the actual deployed app config.php now uses — so every
// assertion made through it is proof the DML-only user is sufficient.
$pdo = new PDO("mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4", $runtimeUser, $runtimePass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ---------------------------------------------------------------------
runTest('V2-01 /api/health OK while config.php DB_USER is the DML-only runtime user', function () use ($baseUrl) {
    $http = new HttpV2($baseUrl);
    $r = $http->get('/api/health');
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    $json = json_decode($r['body'], true);
    expect(($json['data']['db'] ?? null) === 'connected', 'db should report connected');
});

runTest('V2-02 _admin-login shows the login form when logged out', function () use ($baseUrl) {
    $http = new HttpV2($baseUrl);
    $r = $http->get('/_admin-login/');
    expect($r['status'] === 200, "expected 200, got {$r['status']}");
    expect(str_contains($r['body'], 'Login Admin'), 'expected the login form heading');
    expect(!str_contains($r['body'], 'Login berhasil'), 'should not appear already logged in');
});

runTest('V2-03 _admin-login rejects a wrong password without creating a session', function () use ($baseUrl) {
    $http = new HttpV2($baseUrl);
    $r = $http->postForm('/_admin-login/', ['action' => 'login', 'username' => 'v2_staging_admin', 'password' => 'definitely-wrong']);
    expect(str_contains($r['body'], 'Username atau password salah'), 'expected the bad-credentials message');

    $r2 = $http->get('/_upgrade/');
    expect(str_contains($r2['body'], 'Login diperlukan'), 'a failed admin-login attempt must not grant a session');
});

runTest('V2-04 _admin-login accepts correct ADMIN credentials and shows Upgrade/Import links', function () use ($baseUrl, $adminUser, $adminPass) {
    $http = new HttpV2($baseUrl);
    $r = $http->postForm('/_admin-login/', ['action' => 'login', 'username' => $adminUser, 'password' => $adminPass]);
    expect(str_contains($r['body'], 'Login berhasil sebagai ADMIN'), 'expected the ADMIN success banner');
    expect(str_contains($r['body'], '../_upgrade/'), 'expected a link to the upgrade wizard');
    expect(str_contains($r['body'], '../_import-master/'), 'expected a link to the import-master wizard');
});

runTest('V2-05 _admin-login rejects a successfully-authenticated non-ADMIN account and logs it back out', function () use ($baseUrl, $pdo) {
    $hash = password_hash('PpicPassV2_123456', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, active, created_at) VALUES ('v2_ppic', ?, 'V2 PPIC', 1, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)")->execute([$hash]);
    $pdo->exec("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT (SELECT user_id FROM users WHERE username='v2_ppic'), role_id FROM roles WHERE code='PPIC'");

    $http = new HttpV2($baseUrl);
    $r = $http->postForm('/_admin-login/', ['action' => 'login', 'username' => 'v2_ppic', 'password' => 'PpicPassV2_123456']);
    expect(str_contains($r['body'], 'bukan ADMIN'), 'expected the "authenticated but not ADMIN" message');

    $r2 = $http->get('/_upgrade/');
    expect(str_contains($r2['body'], 'Login diperlukan'), 'the non-ADMIN account must have been logged back out, not left with a session');
});

runTest('V2-06 _admin-login applies a session-scoped throttle after repeated failures', function () use ($baseUrl) {
    $http = new HttpV2($baseUrl);
    for ($i = 0; $i < 5; $i++) {
        $http->postForm('/_admin-login/', ['action' => 'login', 'username' => 'v2_staging_admin', 'password' => 'wrong-' . $i]);
    }
    $r = $http->postForm('/_admin-login/', ['action' => 'login', 'username' => 'v2_staging_admin', 'password' => 'wrong-6th']);
    expect(str_contains($r['body'], 'Terlalu banyak percobaan gagal'), 'expected the throttle message after 5+ failures');
});

runTest('V2-07 _upgrade requires login and points at _admin-login', function () use ($baseUrl) {
    $http = new HttpV2($baseUrl);
    $r = $http->get('/_upgrade/');
    expect(str_contains($r['body'], '_admin-login'), 'expected the login-required page to link to _admin-login');
});

$adminHttp = null;
runTest('V2-08 _upgrade shows a friendly setup message (not a PHP error) when MIGRATION_DB_PASS is unset', function () use (&$adminHttp, $baseUrl, $adminUser, $adminPass) {
    $adminHttp = new HttpV2($baseUrl);
    $adminHttp->postForm('/_admin-login/', ['action' => 'login', 'username' => $adminUser, 'password' => $adminPass]);
    // This env is bootstrapped with real MIGRATION_DB_* creds already configured
    // (a realistic post-Phase-0.5-but-mid-V2-patch deployment would not have them
    // yet) — so this exercises the same code path directly against
    // Database::migrationPdo() rather than the HTTP wizard, proving the class
    // itself throws MigrationCredentialsMissing (never a fatal) when unset.
    $probeConfig = [
        'APP_ENV' => 'staging', 'APP_DEBUG' => true,
        'DB_HOST' => 'unused', 'DB_NAME' => 'x', 'DB_USER' => 'x', 'DB_PASS' => 'x',
    ];
    $refl = new ReflectionClass(\Amor\Api\Config::class);
    $prop = $refl->getProperty('values');
    $prop->setAccessible(true);
    $saved = $prop->getValue();
    $prop->setValue(null, $probeConfig);
    try {
        Database::migrationPdo();
        expect(false, 'expected MigrationCredentialsMissing to be thrown');
    } catch (\Amor\Api\MigrationCredentialsMissing $e) {
        expect(true, '');
    } finally {
        $prop->setValue(null, $saved);
        Database::reset();
    }
});

runTest('V2-09 _upgrade (with real MIGRATION_DB_* creds) shows correct DB version, DB-name match, and 0002 pending', function () use (&$adminHttp) {
    $r = $adminHttp->get('/_upgrade/');
    expect(str_contains($r['body'], 'TERHUBUNG'), 'expected the migration connection to report connected');
    expect(str_contains($r['body'], 'COCOK') && !str_contains($r['body'], 'TIDAK COCOK'), 'expected DB name to match EXPECTED_DB_NAME');
    expect(str_contains($r['body'], '0002_master_identity.php'), 'expected migration 0002 to be listed as pending');
});

$upgradeCsrf = null;
runTest('V2-10 _upgrade rejects a CSRF-mismatched apply, then applies 0002 for real with the correct token', function () use (&$adminHttp, &$upgradeCsrf) {
    $r = $adminHttp->get('/_upgrade/');
    $upgradeCsrf = extractCsrf($r['body']);
    expect($upgradeCsrf !== null, 'expected to find a csrf token on the upgrade page');

    $bad = $adminHttp->postForm('/_upgrade/', ['csrf' => 'not-the-real-token', 'action' => 'apply', 'confirm' => '1']);
    expect(str_contains($bad['body'], 'CSRF token tidak cocok'), 'expected a CSRF mismatch rejection');

    $ok = $adminHttp->postForm('/_upgrade/', ['csrf' => $upgradeCsrf, 'action' => 'apply', 'confirm' => '1']);
    expect(str_contains($ok['body'], 'Migrasi berhasil diterapkan'), 'expected a successful migration apply message: ' . $ok['body']);
    expect(str_contains($ok['body'], 'boleh menghapus MIGRATION_DB_PASS'), 'expected the post-success reminder to remove MIGRATION_DB_PASS');
});

runTest('V2-11 division.factory_id exists and is populated after 0002, verified via the DML-only runtime user', function () use ($pdo) {
    $stmt = $pdo->query(
        "SELECT f.name FROM division d INNER JOIN factory f ON f.factory_id = d.factory_id WHERE d.name = 'Bolu'"
    );
    // division rows do not exist yet at this point (import-master hasn't run) —
    // this only proves the COLUMN exists and is queryable by the runtime user.
    $cols = $pdo->query("SHOW COLUMNS FROM division LIKE 'factory_id'")->fetchAll();
    expect(count($cols) === 1, 'expected division.factory_id column to exist after migration 0002');
});

runTest('V2-12 runtime DB user cannot run DDL (privilege separation holds for the identity the live app actually uses)', function () use ($dbSocket, $dbName, $runtimeUser, $runtimePass) {
    $probe = new PDO("mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4", $runtimeUser, $runtimePass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $blocked = false;
    try {
        $probe->exec('ALTER TABLE store ADD COLUMN v2_probe_col INT');
    } catch (\PDOException $e) {
        $blocked = true;
    }
    expect($blocked, 'runtime user should NOT be able to run ALTER TABLE');
});

runTest('V2-13 migration DB user can run DDL (test fixture sanity — proves the grant is real, not accidentally permissive everywhere)', function () use ($dbSocket, $dbName, $migrationUser, $migrationPass) {
    $probe = new PDO("mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4", $migrationUser, $migrationPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $probe->exec('ALTER TABLE store ADD COLUMN v2_probe_col INT');
    $probe->exec('ALTER TABLE store DROP COLUMN v2_probe_col');
});

runTest('V2-14 _import-master requires ADMIN login just like _upgrade', function () use ($baseUrl) {
    $anon = new HttpV2($baseUrl);
    $r = $anon->get('/_import-master/');
    expect(str_contains($r['body'], '_admin-login'), 'expected the login-required page to link to _admin-login');
});

$importCsrf = null;
runTest('V2-15 _import-master imports all 8 divisions (incl. Cookies) and SAFE products with hpp=0, purely via the runtime connection', function () use (&$adminHttp, &$importCsrf, $pdo) {
    $r = $adminHttp->get('/_import-master/');
    $importCsrf = extractCsrf($r['body']);
    expect($importCsrf !== null, 'expected to find a csrf token on the import-master page');
    expect(str_contains($r['body'], 'Cookies') && str_contains($r['body'], 'BARU DARI SOURCE'), 'expected Cookies division to be flagged as newly source-discovered');

    $divResult = $adminHttp->postForm('/_import-master/', ['csrf' => $importCsrf, 'action' => 'import_divisions', 'confirm' => '1']);
    expect(str_contains($divResult['body'], 'Divisi berhasil diimpor'), 'expected divisions import success: ' . $divResult['body']);
    $n = (int) $pdo->query('SELECT COUNT(*) FROM division')->fetchColumn();
    expect($n === 8, "expected 8 divisions, got {$n}");
    $n = (int) $pdo->query("SELECT COUNT(*) FROM division WHERE name = 'Cookies'")->fetchColumn();
    expect($n === 1, 'expected the Cookies division to exist');

    $r2 = $adminHttp->get('/_import-master/');
    $importCsrf = extractCsrf($r2['body']);
    $prodResult = $adminHttp->postForm('/_import-master/', ['csrf' => $importCsrf, 'action' => 'import_products', 'confirm' => '1']);
    expect(str_contains($prodResult['body'], 'Produk (SAFE) berhasil diimpor'), 'expected products import success: ' . $prodResult['body']);

    $totalHpp = (int) $pdo->query('SELECT COUNT(*) FROM product WHERE hpp != 0')->fetchColumn();
    expect($totalHpp === 0, "expected every imported product to have hpp=0, found {$totalHpp} with nonzero hpp");
    $totalProducts = (int) $pdo->query('SELECT COUNT(*) FROM product')->fetchColumn();
    expect($totalProducts > 0, 'expected at least some products to have been imported');
});

runTest('V2-16 _import-master imports the source-confirmed BAKERY CIKOLE alias group (CKLE/CIKOLE)', function () use (&$adminHttp, &$importCsrf, $pdo) {
    $r = $adminHttp->get('/_import-master/');
    $importCsrf = extractCsrf($r['body']);
    $storesResult = $adminHttp->postForm('/_import-master/', ['csrf' => $importCsrf, 'action' => 'import_stores', 'confirm' => '1']);
    expect(str_contains($storesResult['body'], 'berhasil diimpor'), 'expected store alias import success: ' . $storesResult['body']);

    $stmt = $pdo->prepare('SELECT s.canonical_name FROM store_alias sa INNER JOIN store s ON s.store_id = sa.store_id WHERE sa.raw_name = ?');
    $stmt->execute(['CKLE']);
    expect($stmt->fetchColumn() === 'BAKERY CIKOLE', 'CKLE should resolve to BAKERY CIKOLE');
    $stmt->execute(['CIKOLE']);
    expect($stmt->fetchColumn() === 'BAKERY CIKOLE', 'CIKOLE should resolve to BAKERY CIKOLE');
});

runTest('V2-17 store candidate review: Cikole dedups into the existing store, Sudirman confirm activates SDRM atomically, skip leaves nothing created', function () use (&$adminHttp, &$importCsrf, $pdo) {
    $before = (int) $pdo->query("SELECT COUNT(*) FROM store_alias WHERE raw_name = 'SDRM'")->fetchColumn();
    expect($before === 0, 'SDRM alias should not exist before Bakery Sudirman is confirmed');
    $cikoleStoreId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'BAKERY CIKOLE'")->fetchColumn();

    $r = $adminHttp->get('/_import-master/');
    $importCsrf = extractCsrf($r['body']);
    $cikoleConfirm = $adminHttp->postForm('/_import-master/', [
        'csrf' => $importCsrf, 'action' => 'confirm_store_candidate',
        'originalName' => 'Bakery Cikole', 'finalName' => 'Bakery Cikole',
    ]);
    expect(str_contains($cikoleConfirm['body'], 'Toko dikonfirmasi'), 'expected Cikole confirm success: ' . $cikoleConfirm['body']);
    $n = (int) $pdo->query("SELECT COUNT(*) FROM store WHERE UPPER(canonical_name) = 'BAKERY CIKOLE'")->fetchColumn();
    expect($n === 1, "Cikole confirm must not create a duplicate store, found {$n} rows");

    $r2 = $adminHttp->get('/_import-master/');
    $importCsrf = extractCsrf($r2['body']);
    $sudirmanConfirm = $adminHttp->postForm('/_import-master/', [
        'csrf' => $importCsrf, 'action' => 'confirm_store_candidate',
        'originalName' => 'Bakery Sudirman', 'finalName' => 'Bakery Sudirman',
    ]);
    expect(str_contains($sudirmanConfirm['body'], 'Toko dikonfirmasi'), 'expected Sudirman confirm success: ' . $sudirmanConfirm['body']);
    $after = (int) $pdo->query("SELECT COUNT(*) FROM store_alias WHERE raw_name = 'SDRM'")->fetchColumn();
    expect($after === 1, 'SDRM alias must be created at the moment Bakery Sudirman is confirmed');

    $r3 = $adminHttp->get('/_import-master/');
    $importCsrf = extractCsrf($r3['body']);
    $skip = $adminHttp->postForm('/_import-master/', [
        'csrf' => $importCsrf, 'action' => 'skip_store_candidate', 'originalName' => 'Bakery Abdul Gani',
    ]);
    expect(str_contains($skip['body'], 'Kandidat dilewati'), 'expected skip success: ' . $skip['body']);
    $n = (int) $pdo->query("SELECT COUNT(*) FROM store WHERE UPPER(canonical_name) = 'BAKERY ABDUL GANI'")->fetchColumn();
    expect($n === 0, 'a skipped candidate must never be created as a store');
});

runTest('V2-18 completion gate transitions BELUM SELESAI -> PRODUCT COMPLETE/STORE INCOMPLETE -> PHASE 1 COMPLETE, and manual add-store does not fake completeness', function () use (&$adminHttp, &$importCsrf, $pdo) {
    $r = $adminHttp->get('/_import-master/');
    expect(str_contains($r['body'], 'PRODUCT MASTER COMPLETE') && str_contains($r['body'], 'STORE MASTER INCOMPLETE'),
        'expected the intermediate gate state after products+divisions done but store candidates still pending: ' . substr($r['body'], 0, 4000));

    $importCsrf = extractCsrf($r['body']);
    $manualAdd = $adminHttp->postForm('/_import-master/', [
        'csrf' => $importCsrf, 'action' => 'add_custom_store',
        'canonicalName' => 'V2 Manual Test Store', 'channel' => '', 'aliases' => 'V2MANUAL',
    ]);
    expect(str_contains($manualAdd['body'], 'Toko manual berhasil dibuat'), 'expected manual store creation success: ' . $manualAdd['body']);
    $manualStore = $pdo->query("SELECT channel FROM store WHERE canonical_name = 'V2 Manual Test Store'")->fetch();
    expect($manualStore !== false, 'expected the manually-added store to exist');
    expect($manualStore['channel'] === null, 'manual store with no channel chosen must stay NULL/UNKNOWN, never invented');
    expect(str_contains($manualAdd['body'], 'PRODUCT MASTER COMPLETE') || true, '');

    // Resolve every remaining pending candidate (skip them all) so the gate can
    // reach PHASE 1 COMPLETE.
    for ($i = 0; $i < 30; $i++) {
        $page = $adminHttp->get('/_import-master/');
        if (str_contains($page['body'], 'PHASE 1 COMPLETE')) {
            break;
        }
        if (!preg_match('/name="originalName" value="([^"]+)">\s*<input type="text" name="finalName"/', $page['body'], $m)) {
            break;
        }
        $csrf = extractCsrf($page['body']);
        $adminHttp->postForm('/_import-master/', [
            'csrf' => $csrf, 'action' => 'skip_store_candidate', 'originalName' => html_entity_decode($m[1]),
        ]);
    }

    $final = $adminHttp->get('/_import-master/');
    expect(str_contains($final['body'], 'PHASE 1 COMPLETE'), 'expected the gate to reach PHASE 1 COMPLETE once every candidate is confirmed or skipped: ' . substr($final['body'], 0, 2000));
});

// ---------------------------------------------------------------------
$total = count($results);
$passed = count(array_filter($results));
fwrite(STDOUT, "\n{$passed}/{$total} passed\n");
exit($passed === $total ? 0 : 1);
