<?php

declare(strict_types=1);

/**
 * ADMIN User / Driver Account Management integration suite (UM-01..16).
 * Run via api/tests/run-user-mgmt.sh, which stands up a disposable local
 * MariaDB, applies migrations 0001-0007 (NO new migration in this phase —
 * users/roles/user_roles already supported everything needed), bootstraps
 * realistic master data, then drives the real /api/users/* JSON API AND
 * the real /api/_driver-uat/login.php and /api/_admin-login/ HTML login
 * pages end to end against a live `php -S` server.
 *
 * UM-17 (full Phase 0-5.5 regression green) is NOT a test in this file —
 * it is the orchestrator's own final step, same pattern as every prior
 * phase's own suite (see Phase55DispatchReceiptTest.php's own P55-24).
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8111';
$adminUser = getenv('TEST_ADMIN_USER') ?: 'um_staging_admin';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';

if ($adminPass === '' || $dbSocket === '' || $dbName === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpUM
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'umcookies');
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

    /** Plain HTML-form POST (application/x-www-form-urlencoded), for the real Driver/Admin login PAGES — never JSON. */
    public function requestForm(string $path, array $fields): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $raw, 'location' => $location];
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

function login(HttpUM $http, string $username, string $password): string
{
    $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    $me = $http->request('GET', '/api/auth/me');
    $csrf = $me['json']['data']['csrfToken'] ?? null;
    expect($csrf !== null, "expected a csrf token after login as {$username}");
    return $csrf;
}

$pdo = new PDO("mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$adminHttp = new HttpUM($baseUrl);
$adminCsrf = login($adminHttp, $adminUser, $adminPass);
$adminMe = $adminHttp->request('GET', '/api/auth/me');
$adminUserId = (int) $adminMe['json']['data']['userId'];

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

function uniqueUsername(string $prefix): string
{
    return $prefix . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
}

// ---------------------------------------------------------------------
// UM-01
// ---------------------------------------------------------------------
runTest('UM-01 admin can list users', function () use ($adminHttp) {
    $r = $adminHttp->request('GET', '/api/users');
    expect($r['status'] === 200, 'list users failed: ' . json_encode($r['json']));
    expect(isset($r['json']['data']['users']) && is_array($r['json']['data']['users']), 'expected a users array');
    expect(isset($r['json']['data']['roles']) && is_array($r['json']['data']['roles']), 'expected a roles array for the create/edit UI');
    $codes = array_column($r['json']['data']['roles'], 'code');
    expect(in_array('ADMIN', $codes, true) && in_array('DRIVER', $codes, true), 'expected ADMIN and DRIVER in the role list');
});

// ---------------------------------------------------------------------
// UM-03 / UM-04 / UM-06 (create DRIVER user, verify hash + real M:N role row)
// ---------------------------------------------------------------------
$driverUsername = uniqueUsername('um_driver_a');
$driverPassword = 'DriverPass12345';
$driverUserId = null;
runTest('UM-03 / UM-04 / UM-06 create DRIVER user, password stored hashed, role assigned via user_roles', function () use ($adminHttp, $adminCsrf, $pdo, $driverUsername, $driverPassword, &$driverUserId) {
    $create = $adminHttp->request('POST', '/api/users', [
        'username' => $driverUsername, 'fullName' => 'UM Driver A', 'password' => $driverPassword, 'passwordConfirm' => $driverPassword, 'roles' => ['DRIVER'],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-03-create')));
    expect($create['status'] === 201, 'create driver failed: ' . json_encode($create['json']));
    $dto = $create['json']['data'];
    expect($dto['username'] === $driverUsername && $dto['active'] === true, 'unexpected create response: ' . json_encode($dto));
    expect($dto['roles'] === ['DRIVER'], 'expected roles=[DRIVER] in response, got ' . json_encode($dto['roles']));
    $driverUserId = (int) $dto['userId'];

    $row = $pdo->query("SELECT password_hash FROM users WHERE user_id = {$driverUserId}")->fetch();
    expect($row !== false, 'expected the new user row to exist');
    expect($row['password_hash'] !== $driverPassword, 'password must never be stored as plaintext');
    expect(password_verify($driverPassword, $row['password_hash']), 'stored hash must verify against the original password');

    // UM-06: the role assignment must be a REAL row in the M:N user_roles
    // table (never a hardcoded column on users) — verify the join directly.
    $roleRow = $pdo->query(
        "SELECT r.code FROM user_roles ur INNER JOIN roles r ON r.role_id = ur.role_id WHERE ur.user_id = {$driverUserId}"
    )->fetch();
    expect($roleRow !== false && $roleRow['code'] === 'DRIVER', 'expected a real user_roles row linking this user to the DRIVER role');
});

// ---------------------------------------------------------------------
// UM-05
// ---------------------------------------------------------------------
runTest('UM-05 duplicate username rejected', function () use ($adminHttp, $adminCsrf, $driverUsername) {
    $dup = $adminHttp->request('POST', '/api/users', [
        'username' => $driverUsername, 'fullName' => 'Someone Else', 'password' => 'AnotherPass123', 'passwordConfirm' => 'AnotherPass123', 'roles' => ['DRIVER'],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-05-dup')));
    expect($dup['status'] === 409, "expected 409 on duplicate username, got {$dup['status']}: " . json_encode($dup['json']));
    expect($dup['json']['code'] === 'DUPLICATE_USERNAME', 'expected DUPLICATE_USERNAME code');
});

// ---------------------------------------------------------------------
// UM-02
// ---------------------------------------------------------------------
runTest('UM-02 non-admin denied', function () use ($baseUrl, $driverUsername, $driverPassword) {
    $driverHttp = new HttpUM($baseUrl);
    login($driverHttp, $driverUsername, $driverPassword);
    $r = $driverHttp->request('GET', '/api/users');
    expect($r['status'] === 403, "expected 403 FORBIDDEN for a non-admin listing users, got {$r['status']}");
    expect($r['json']['code'] === 'FORBIDDEN', 'expected FORBIDDEN code');
});

// ---------------------------------------------------------------------
// UM-07 / UM-08 (real driver-portal login page + real admin-login rejection)
// ---------------------------------------------------------------------
runTest('UM-07 DRIVER can login through the real /_driver-uat/login.php page', function () use ($baseUrl, $driverUsername, $driverPassword) {
    $anon = new HttpUM($baseUrl);
    $r = $anon->requestForm('/_driver-uat/login.php', ['username' => $driverUsername, 'password' => $driverPassword, 'return' => 'index.php']);
    expect($r['status'] === 302, "expected a 302 redirect on successful driver-portal login, got {$r['status']}: " . substr($r['body'], 0, 300));
    $portal = $anon->request('GET', '/_driver-uat/index.php?tab=tersedia');
    expect($portal['status'] === 200, "expected 200 from the driver portal after real login, got {$portal['status']}");
});

runTest('UM-08 DRIVER cannot use the ADMIN-only login', function () use ($baseUrl, $driverUsername, $driverPassword) {
    $anon = new HttpUM($baseUrl);
    $r = $anon->requestForm('/_admin-login/', ['username' => $driverUsername, 'password' => $driverPassword]);
    expect($r['status'] === 200, 'expected the admin-login page to re-render the form (not redirect) on a non-admin attempt');
    $me = $anon->request('GET', '/api/auth/me');
    expect($me['status'] === 401, "expected the session to be logged out (401) after a rejected non-admin admin-login attempt, got {$me['status']}");
});

// ---------------------------------------------------------------------
// UM-09 / UM-10 (deactivate blocks login, reactivate restores it)
// ---------------------------------------------------------------------
runTest('UM-09 / UM-10 deactivate blocks login, reactivate restores it', function () use ($adminHttp, $adminCsrf, $baseUrl, $driverUserId, $driverUsername, $driverPassword) {
    $deactivate = $adminHttp->request('POST', "/api/users/{$driverUserId}/deactivate", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-09-deactivate')));
    expect($deactivate['status'] === 200, 'deactivate failed: ' . json_encode($deactivate['json']));
    expect($deactivate['json']['data']['active'] === false, 'expected active=false after deactivate');

    $blocked = new HttpUM($baseUrl);
    $loginAttempt = $blocked->request('POST', '/api/auth/login', ['username' => $driverUsername, 'password' => $driverPassword]);
    expect($loginAttempt['status'] === 403, "expected 403 ACCOUNT_INACTIVE for a deactivated user, got {$loginAttempt['status']}");
    expect($loginAttempt['json']['code'] === 'ACCOUNT_INACTIVE', 'expected ACCOUNT_INACTIVE code');

    $reactivate = $adminHttp->request('POST', "/api/users/{$driverUserId}/activate", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-10-activate')));
    expect($reactivate['status'] === 200 && $reactivate['json']['data']['active'] === true, 'reactivate failed: ' . json_encode($reactivate['json']));

    $restored = new HttpUM($baseUrl);
    $loginAgain = $restored->request('POST', '/api/auth/login', ['username' => $driverUsername, 'password' => $driverPassword]);
    expect($loginAgain['status'] === 200, "expected login to succeed again after reactivation, got {$loginAgain['status']}");
});

// ---------------------------------------------------------------------
// UM-11
// ---------------------------------------------------------------------
runTest('UM-11 password reset works (old password stops working, new one works)', function () use ($adminHttp, $adminCsrf, $baseUrl, $driverUserId, $driverUsername, $driverPassword) {
    $newPassword = 'BrandNewPass456';
    $reset = $adminHttp->request('POST', "/api/users/{$driverUserId}/reset-password", [
        'password' => $newPassword, 'passwordConfirm' => $newPassword,
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-11-reset')));
    expect($reset['status'] === 200, 'password reset failed: ' . json_encode($reset['json']));

    $oldAttempt = new HttpUM($baseUrl);
    $oldResult = $oldAttempt->request('POST', '/api/auth/login', ['username' => $driverUsername, 'password' => $driverPassword]);
    expect($oldResult['status'] === 401, "expected the OLD password to now be rejected, got {$oldResult['status']}");

    $newAttempt = new HttpUM($baseUrl);
    $newResult = $newAttempt->request('POST', '/api/auth/login', ['username' => $driverUsername, 'password' => $newPassword]);
    expect($newResult['status'] === 200, "expected the NEW password to work, got {$newResult['status']}");
});

// ---------------------------------------------------------------------
// UM-12
// ---------------------------------------------------------------------
runTest('UM-12 role change works (old roles removed, new roles applied)', function () use ($adminHttp, $adminCsrf, $pdo, $driverUserId) {
    $change = $adminHttp->request('PUT', "/api/users/{$driverUserId}/roles", ['roles' => ['DRIVER', 'PPIC']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-12-roles')));
    expect($change['status'] === 200, 'role change failed: ' . json_encode($change['json']));
    $roles = $change['json']['data']['roles'];
    sort($roles);
    expect($roles === ['DRIVER', 'PPIC'], 'expected roles=[DRIVER,PPIC], got ' . json_encode($roles));

    $dbRoles = $pdo->query(
        "SELECT r.code FROM user_roles ur INNER JOIN roles r ON r.role_id = ur.role_id WHERE ur.user_id = {$driverUserId} ORDER BY r.code"
    )->fetchAll(PDO::FETCH_COLUMN);
    expect($dbRoles === ['DRIVER', 'PPIC'], 'expected the DB user_roles rows to exactly match the new set, got ' . json_encode($dbRoles));

    // Revert back to DRIVER-only so later tests (e.g. UM-14's admin-count logic) aren't affected.
    $adminHttp->request('PUT', "/api/users/{$driverUserId}/roles", ['roles' => ['DRIVER']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-12-revert')));
});

// ---------------------------------------------------------------------
// UM-13
// ---------------------------------------------------------------------
runTest('UM-13 unknown role rejected', function () use ($adminHttp, $adminCsrf, $driverUserId) {
    $bad = $adminHttp->request('PUT', "/api/users/{$driverUserId}/roles", ['roles' => ['NOT_A_REAL_ROLE']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-13-badrole')));
    expect($bad['status'] === 400, "expected 400 UNKNOWN_ROLE, got {$bad['status']}: " . json_encode($bad['json']));
    expect($bad['json']['code'] === 'UNKNOWN_ROLE', 'expected UNKNOWN_ROLE code');
});

// ---------------------------------------------------------------------
// UM-14 — the "never zero active ADMIN" safeguard, both via deactivate
// and via role removal.
// ---------------------------------------------------------------------
runTest('UM-14 cannot leave the system with zero active ADMIN users', function () use ($adminHttp, $adminCsrf, $adminUserId) {
    // At this point in the suite, um_staging_admin is the ONLY active ADMIN.
    $deactivateSelf = $adminHttp->request('POST', "/api/users/{$adminUserId}/deactivate", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-14-deact-self')));
    expect($deactivateSelf['status'] === 409, "expected 409 CANNOT_DEACTIVATE_LAST_ADMIN, got {$deactivateSelf['status']}: " . json_encode($deactivateSelf['json']));
    expect($deactivateSelf['json']['code'] === 'CANNOT_DEACTIVATE_LAST_ADMIN', 'expected CANNOT_DEACTIVATE_LAST_ADMIN code');

    $removeAdminRole = $adminHttp->request('PUT', "/api/users/{$adminUserId}/roles", ['roles' => ['PPIC']], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-14-derole-self')));
    expect($removeAdminRole['status'] === 409, "expected 409 CANNOT_REMOVE_LAST_ADMIN, got {$removeAdminRole['status']}: " . json_encode($removeAdminRole['json']));
    expect($removeAdminRole['json']['code'] === 'CANNOT_REMOVE_LAST_ADMIN', 'expected CANNOT_REMOVE_LAST_ADMIN code');

    // Now prove the safeguard is scoped correctly, not just "never allow
    // any deactivate/role-change on an admin": once a SECOND active admin
    // exists, the same actions on the FIRST admin must succeed.
    $secondAdminUsername = uniqueUsername('um_admin_b');
    $secondAdminPassword = 'SecondAdminPass123';
    $create = $adminHttp->request('POST', '/api/users', [
        'username' => $secondAdminUsername, 'fullName' => 'UM Second Admin', 'password' => $secondAdminPassword, 'passwordConfirm' => $secondAdminPassword, 'roles' => ['ADMIN'],
    ], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-14-create-second-admin')));
    expect($create['status'] === 201, 'creating a second admin failed: ' . json_encode($create['json']));

    $deactivateFirstNow = $adminHttp->request('POST', "/api/users/{$adminUserId}/deactivate", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-14-deact-first-ok')));
    expect($deactivateFirstNow['status'] === 200, 'expected deactivate to succeed once a second active admin exists: ' . json_encode($deactivateFirstNow['json']));

    // Restore state for any later use of $adminHttp in this same run.
    $adminHttp->request('POST', "/api/users/{$adminUserId}/activate", [], array_merge(['X-CSRF-Token' => $adminCsrf], idemKey('um-14-reactivate-first')));
});

// ---------------------------------------------------------------------
// UM-15
// ---------------------------------------------------------------------
runTest('UM-15 CSRF required for mutations', function () use ($adminHttp) {
    $noCsrf = $adminHttp->request('POST', '/api/users', [
        'username' => uniqueUsername('um_nocsrf'), 'fullName' => 'No Csrf', 'password' => 'SomePassword123', 'passwordConfirm' => 'SomePassword123', 'roles' => ['DRIVER'],
    ], idemKey('um-15-nocsrf'));
    expect($noCsrf['status'] === 403, "expected 403 CSRF_TOKEN_INVALID without a CSRF header, got {$noCsrf['status']}");
    expect($noCsrf['json']['code'] === 'CSRF_TOKEN_INVALID', 'expected CSRF_TOKEN_INVALID code');
});

// ---------------------------------------------------------------------
// UM-16
// ---------------------------------------------------------------------
runTest('UM-16 no plaintext password (or hash) ever exposed by the API', function () use ($adminHttp, $driverPassword) {
    $list = $adminHttp->request('GET', '/api/users');
    expect($list['status'] === 200, 'list failed: ' . json_encode($list['json']));
    expect(!str_contains($list['body'], 'password'), 'the /api/users response must never contain the word "password" (hash or field name) at all');
    expect(!str_contains($list['body'], $driverPassword), 'the /api/users response must never leak a plaintext password string');
});

$failed = array_filter($results, fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests run, ' . count($failed) . " failed.\n");
exit($failed === [] ? 0 : 1);
