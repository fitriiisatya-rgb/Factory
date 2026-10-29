<?php

declare(strict_types=1);

/**
 * Admin per pabrik suite — AFS-01..08. Leader Admin (ADMIN with no
 * factory rows) vs Admin Cibadak / Admin Karangtengah (ADMIN + one factory).
 * Runs inside run-reject-toko.sh right after RejectTokoTest.php, against
 * the same disposable DB and live `php -S` server (same TEST_* env vars).
 *
 * Do not run this file directly against anything but a disposable test DB.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;
use Amor\Api\StorePortal\StorePortalService;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8140';
$adminUser = getenv('TEST_ADMIN_USER') ?: '';
$adminPass = getenv('TEST_ADMIN_PASS') ?: '';
$dbSocket = getenv('TEST_DB_SOCKET') ?: '';
$dbName = getenv('TEST_DB_NAME') ?: '';
$runtimeUser = getenv('TEST_RUNTIME_USER') ?: '';
$runtimePass = getenv('TEST_RUNTIME_PASS') ?: '';

if ($adminUser === '' || $adminPass === '' || $dbSocket === '' || $dbName === '' || $runtimeUser === '') {
    fwrite(STDERR, "Required TEST_* env vars are missing.\n");
    exit(1);
}

final class HttpAfs
{
    private string $cookieJar;

    public function __construct(private string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'afscookies');
    }

    /** @return array{status:int,json:?array,body:string} */
    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $hdrLines = ['Content-Type: application/json'];
        foreach ($headers as $k => $v) {
            $hdrLines[] = "{$k}: {$v}";
        }
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
        return ['status' => $status, 'json' => $raw === '' ? null : json_decode($raw, true), 'body' => $raw];
    }

    public function requestMultipart(string $path, array $fields, array $files, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $hdrLines = [];
        foreach ($headers as $k => $v) {
            $hdrLines[] = "{$k}: {$v}";
        }
        foreach ($files as $fieldName => $filePath) {
            $fields[$fieldName] = new CURLFile($filePath, 'image/png', basename($filePath));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_HTTPHEADER => $hdrLines,
            CURLOPT_POSTFIELDS => $fields,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'json' => $raw === '' || $raw === false ? null : json_decode($raw, true), 'body' => (string) $raw];
    }
}

/** A signed-in admin: HTTP client + CSRF token. */
final class Actor
{
    public function __construct(public HttpAfs $http, public string $csrf)
    {
    }

    public function get(string $path): array
    {
        return $this->http->request('GET', $path);
    }

    public function sendForm(string $path, array $fields, array $files = []): array
    {
        return $this->http->requestMultipart($path, $fields, $files, ['X-CSRF-Token' => $this->csrf] + idemKey('afs'));
    }

    public function send(string $method, string $path, array $body): array
    {
        return $this->http->request($method, $path, $body, ['X-CSRF-Token' => $this->csrf] + idemKey('afs'));
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

function signIn(string $username, string $password): Actor
{
    $http = new HttpAfs($GLOBALS['baseUrl']);
    $http->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
    $csrf = $http->request('GET', '/api/auth/me')['json']['data']['csrfToken'] ?? null;
    expect($csrf !== null, "expected a csrf token after login as {$username}");
    return new Actor($http, $csrf);
}

function code(array $r): ?string
{
    return $r['json']['code'] ?? ($r['json']['error']['code'] ?? null);
}

function expectDenied(array $r, string $code, string $what): void
{
    expect($r['status'] === 403 && code($r) === $code, "{$what}: expected 403 {$code}, got {$r['status']} " . $r['body']);
}

function fakeEvidenceImage(): string
{
    $path = tempnam(sys_get_temp_dir(), 'afsevidence') . '.png';
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    return $path;
}

function seedStorePo(PDO $pdo, string $tanggal, int $factoryId, int $storeId, int $productId, float $qty): void
{
    $find = $pdo->prepare('SELECT po_batch_id FROM po_batch WHERE tanggal = ? AND factory_id = ?');
    $find->execute([$tanggal, $factoryId]);
    $batchId = $find->fetchColumn();
    if ($batchId === false) {
        $pdo->prepare('INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())')->execute([$tanggal, $factoryId]);
        $batchId = (int) $pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO po_item (po_batch_id, product_id, po_awal, po_revisi, pb) VALUES (?, ?, ?, 0, 0) ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal)')
        ->execute([$batchId, $productId, $qty]);
    $item = $pdo->prepare('SELECT po_item_id FROM po_item WHERE po_batch_id = ? AND product_id = ?');
    $item->execute([$batchId, $productId]);
    $pdo->prepare('INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE po_awal = VALUES(po_awal)')
        ->execute([(int) $item->fetchColumn(), $storeId, $qty]);
}

/** PO -> production submitted -> FG packed & submitted, so the product is ready to ship. */
function stockUp(Actor $a, PDO $pdo, int $factoryId, int $divisionId, string $tanggal, int $storeId, int $productId, float $qty): void
{
    seedStorePo($pdo, $tanggal, $factoryId, $storeId, $productId, $qty);
    $c = $a->send('POST', '/api/production', ['tanggal' => $tanggal, 'divisionId' => $divisionId]);
    expect($c['status'] === 200, 'production create failed: ' . $c['body']);
    $runId = $c['json']['data']['productionRunId'];
    $s = $a->send('PATCH', "/api/production/{$runId}", ['expectedVersion' => $c['json']['data']['version'], 'items' => [['productId' => $productId, 'actualQty' => $qty]]]);
    expect($s['status'] === 200, 'production patch failed: ' . $s['body']);
    $sub = $a->send('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $s['json']['data']['version']]);
    expect($sub['status'] === 200, 'production submit failed: ' . $sub['body']);

    $f = $a->send('POST', '/api/fg', ['tanggal' => $tanggal, 'factoryId' => $factoryId]);
    expect($f['status'] === 200, 'fg create failed: ' . $f['body']);
    $batchId = $f['json']['data']['fgBatchId'];
    $fs = $a->send('PATCH', "/api/fg/{$batchId}", ['expectedVersion' => $f['json']['data']['version'], 'items' => [['productId' => $productId, 'fgVerified' => $qty, 'packed' => $qty]]]);
    expect($fs['status'] === 200, 'fg save failed: ' . $fs['body']);
    $fsub = $a->send('POST', "/api/fg/{$batchId}/submit", ['expectedVersion' => $fs['json']['data']['version']]);
    expect($fsub['status'] === 200, 'fg submit failed: ' . $fsub['body']);
}

function confirmAtStore(string $token, int $shipmentId): void
{
    $anon = new HttpAfs($GLOBALS['baseUrl']);
    $detail = $anon->request('GET', "/api/store/{$token}/receipts/{$shipmentId}");
    $items = array_map(static fn ($it) => ['shipmentItemId' => $it['shipmentItemId'], 'receivedGood' => $it['shippedQty'], 'reject' => 0, 'shortage' => 0], $detail['json']['data']['items']);
    $r = $anon->request('POST', "/api/store/{$token}/receipts/{$shipmentId}/confirm", ['receiverName' => 'Test', 'items' => $items], idemKey('afs-confirm'));
    expect($r['status'] === 200 && $r['json']['data']['status'] === 'confirmed_ok', 'expected confirmed_ok, got ' . $r['body']);
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

$leader = signIn($adminUser, $adminPass);
$leaderId = (int) $pdo->query('SELECT user_id FROM users WHERE username = ' . $pdo->quote($adminUser))->fetchColumn();

$ktg = (int) $pdo->query("SELECT factory_id FROM factory WHERE code = 'KTG'")->fetchColumn();
$cbd = (int) $pdo->query("SELECT factory_id FROM factory WHERE code = 'CBD'")->fetchColumn();
expect($ktg > 0 && $cbd > 0, 'expected both factories seeded');

/** A production division of $factoryId with enough priced products, plus one of its products. */
function divisionAndProduct(PDO $pdo, int $factoryId, int $offset): array
{
    $st = $pdo->prepare(
        'SELECT d.division_id, p.product_id FROM division d INNER JOIN product p ON p.division_id = d.division_id
         WHERE d.factory_id = ? AND d.is_verification = 0
         ORDER BY d.division_id, p.product_id DESC LIMIT 1 OFFSET ' . (int) $offset
    );
    $st->execute([$factoryId]);
    $r = $st->fetch();
    expect($r !== false, "expected a production division with products for factory {$factoryId}");
    $pdo->prepare('UPDATE product SET harga = 4000 WHERE product_id = ?')->execute([(int) $r['product_id']]);
    return [(int) $r['division_id'], (int) $r['product_id']];
}
[$ktgDiv, $ktgProd] = divisionAndProduct($pdo, $ktg, 0);
[$cbdDiv, $cbdProd] = divisionAndProduct($pdo, $cbd, 0);

$store = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE B'")->fetchColumn();
expect($store > 0, 'expected P2 TEST STORE B fixture seeded');
$token = (new StorePortalService($pdo))->issueToken($store, $leaderId, 'afs-' . uniqid('', true))['rawToken'];

$day = (new DateTimeImmutable('+13 days', new DateTimeZone('UTC')))->format('Y-m-d'); // own date: no other suite produces for it
$pw = 'AdminPabrik#' . bin2hex(random_bytes(4));

/** @var array<string,Actor> $admins */
$admins = [];
runTest('AFS-01 Leader creates Admin Cibadak / Admin Karangtengah (Administrator + one factory)', function () use ($leader, $ktg, $cbd, $pw, &$admins) {
    foreach (['afs_admin_ktg' => $ktg, 'afs_admin_cbd' => $cbd] as $username => $factoryId) {
        $c = $leader->send('POST', '/api/users', ['username' => $username, 'fullName' => "Admin {$username}", 'password' => $pw, 'passwordConfirm' => $pw, 'roles' => ['ADMIN']]);
        expect($c['status'] === 201, 'user create failed: ' . $c['body']);
        $f = $leader->send('PUT', "/api/users/{$c['json']['data']['userId']}/factories", ['factoryIds' => [$factoryId]]);
        expect($f['status'] === 200, 'factory access failed: ' . $f['body']);
        $admins[$username] = signIn($username, $pw);
    }
});
$aKtg = $admins['afs_admin_ktg'] ?? null;
$aCbd = $admins['afs_admin_cbd'] ?? null;
if ($aKtg === null || $aCbd === null) {
    fwrite(STDOUT, "\nAdmin accounts could not be created — remaining AFS tests skipped.\n");
    exit(1);
}

runTest('AFS-02 Leader-only actions are refused for Admin pabrik (LEADER_ONLY), Leader keeps them', function () use ($leader, $aCbd, $ktgDiv) {
    expectDenied($aCbd->get('/api/users'), 'LEADER_ONLY', 'Kelola User');
    expectDenied($aCbd->send('POST', '/api/users', ['username' => 'x_afs', 'fullName' => 'x', 'password' => 'xxxxxxxxxxxx', 'passwordConfirm' => 'xxxxxxxxxxxx', 'roles' => ['ADMIN']]), 'LEADER_ONLY', 'create user');
    expectDenied($aCbd->send('POST', '/api/products', ['name' => 'AFS PRODUK', 'divisionId' => $ktgDiv, 'harga' => 1000]), 'LEADER_ONLY', 'master produk');
    expectDenied($aCbd->send('POST', '/api/stores', ['canonicalName' => 'AFS TOKO']), 'LEADER_ONLY', 'master toko');
    expect($leader->get('/api/users')['status'] === 200, 'Leader must still open Kelola User');
});

runTest('AFS-03 factoryId of another factory is refused (read and write); own factory and Leader open normally', function () use ($aCbd, $ktg, $cbd, $leader, $day) {
    expectDenied($aCbd->get("/api/fg?factoryId={$ktg}"), 'FACTORY_ACCESS_DENIED', 'FG list of Karangtengah');
    expectDenied($aCbd->send('POST', '/api/fg', ['tanggal' => $day, 'factoryId' => $ktg]), 'FACTORY_ACCESS_DENIED', 'FG batch for Karangtengah');
    $own = $aCbd->get("/api/fg?factoryId={$cbd}");
    expect($own['status'] === 200, 'own factory list must open: ' . $own['body']);
    expect($leader->get("/api/fg?factoryId={$ktg}")['status'] === 200, 'Leader opens any factory');
});

// One DO for one store holding goods of BOTH factories.
$doId = 0;
$baseDocNo = '';
runTest('AFS-04 Mixed DO: each Admin pabrik sees only own lines, doc no carries -KTG/-CBD, Leader sees all', function () use ($leader, $aKtg, $aCbd, $pdo, $ktg, $cbd, $ktgDiv, $cbdDiv, $ktgProd, $cbdProd, $store, $day, &$doId, &$baseDocNo) {
    stockUp($leader, $pdo, $ktg, $ktgDiv, $day, $store, $ktgProd, 6.0);
    stockUp($leader, $pdo, $cbd, $cbdDiv, $day, $store, $cbdProd, 4.0);
    $c = $leader->send('POST', '/api/do', ['tanggal' => $day, 'storeId' => $store]);
    expect($c['status'] === 200, 'DO create failed: ' . $c['body']);
    $doId = (int) $c['json']['data']['doId'];
    $baseDocNo = (string) $c['json']['data']['docNo'];

    $all = array_column($leader->get("/api/do/{$doId}")['json']['data']['items'], 'productId');
    expect(in_array($ktgProd, $all, true) && in_array($cbdProd, $all, true), 'Leader must see both lines: ' . json_encode($all));

    foreach ([[$aKtg, $ktgProd, $cbdProd, 'KTG'], [$aCbd, $cbdProd, $ktgProd, 'CBD']] as [$a, $mine, $theirs, $code]) {
        $d = $a->get("/api/do/{$doId}");
        expect($d['status'] === 200, "Admin {$code} must open the shared DO: " . $d['body']);
        $ids = array_column($d['json']['data']['items'], 'productId');
        expect(in_array($mine, $ids, true) && !in_array($theirs, $ids, true), "Admin {$code} lines wrong: " . json_encode($ids));
        expect($d['json']['data']['docNo'] === "{$baseDocNo}-{$code}", "Admin {$code} doc no: " . $d['json']['data']['docNo']);
    }
    expect($leader->get("/api/do/{$doId}")['json']['data']['docNo'] === $baseDocNo, 'Leader sees the plain doc no');
});

runTest('AFS-05 Admin pabrik cannot cancel a 2-factory DO nor ship the other factory\'s goods; ships own goods', function () use ($aKtg, $aCbd, $ktgProd, $cbdProd, &$doId) {
    $v = $aCbd->get("/api/do/{$doId}")['json']['data']['version'];
    expectDenied($aCbd->send('POST', "/api/do/{$doId}/cancel", ['expectedVersion' => $v, 'reason' => 'uji']), 'LEADER_ONLY', 'cancel mixed DO');
    expectDenied($aCbd->send('POST', "/api/do/{$doId}/ship", ['expectedVersion' => $v, 'items' => [['productId' => $ktgProd, 'actualQty' => 6]]]), 'FACTORY_ACCESS_DENIED', 'ship Karangtengah goods');

    foreach ([[$aKtg, $ktgProd, 6.0], [$aCbd, $cbdProd, 4.0]] as [$a, $productId, $qty]) {
        $v = $a->get("/api/do/{$doId}")['json']['data']['version'];
        $s = $a->send('POST', "/api/do/{$doId}/ship", ['expectedVersion' => $v, 'items' => [['productId' => $productId, 'actualQty' => $qty]]]);
        expect($s['status'] === 200, 'own ship failed: ' . $s['body']);
    }
});

$shipments = [];
runTest('AFS-06 Each Admin pabrik sees only own shipment on the DO', function () use ($leader, $aKtg, $aCbd, $pdo, $ktg, $cbd, &$doId, &$shipments) {
    $rows = $pdo->query("SELECT shipment_id, factory_id FROM shipment WHERE delivery_order_id = {$doId} AND status = 'active'")->fetchAll();
    foreach ($rows as $r) {
        $shipments[(int) $r['factory_id']] = (int) $r['shipment_id'];
    }
    expect(isset($shipments[$ktg], $shipments[$cbd]), 'expected one shipment per factory: ' . json_encode($rows));
    $shipIds = static fn (Actor $a) => array_map('intval', array_column($a->get("/api/do/{$doId}/shipments")['json']['data'] ?? [], 'shipment_id'));
    expect($shipIds($aCbd) === [$shipments[$cbd]], 'Admin CBD shipments: ' . json_encode($shipIds($aCbd)));
    expect($shipIds($aKtg) === [$shipments[$ktg]], 'Admin KTG shipments: ' . json_encode($shipIds($aKtg)));
    expect(count($shipIds($leader)) === 2, 'Leader sees both shipments');
});

$cbdInvoiceId = 0;
runTest('AFS-07 Invoices split per factory: Admin CBD issues only the Cibadak invoice; Admin KTG cannot open it', function () use ($leader, $aKtg, $aCbd, $token, $ktg, $cbd, $cbdProd, $ktgProd, &$doId, &$shipments, &$baseDocNo, &$cbdInvoiceId) {
    foreach ($shipments as $sid) {
        confirmAtStore($token, $sid);
    }
    $mine = static fn (Actor $a) => array_values(array_filter($a->get('/api/invoices/ready')['json']['data']['deliveries'] ?? [], static fn ($d) => $d['kind'] === 'do' && (int) $d['refId'] === $doId));
    $leaderReady = $mine($leader);
    expect(count($leaderReady) === 2, 'Leader must see 2 ready invoices (one per factory) for the mixed DO: ' . json_encode($leaderReady));
    $cbdReady = $mine($aCbd);
    expect(count($cbdReady) === 1 && (int) $cbdReady[0]['factoryId'] === $cbd && $cbdReady[0]['docNo'] === "{$baseDocNo}-CBD", 'Admin CBD ready: ' . json_encode($cbdReady));

    $g = $aCbd->send('POST', '/api/invoices/generate-ready', []);
    expect($g['status'] === 200, 'generate-ready failed: ' . $g['body']);
    $mineInv = array_values(array_filter($g['json']['data']['invoices'], static fn ($i) => $i['sourceKind'] === 'do'));
    expect(count($mineInv) === 1, 'Admin CBD must issue exactly the Cibadak invoice: ' . json_encode($g['json']['data']));
    $invId = (int) $mineInv[0]['invoiceId'];
    $cbdInvoiceId = $invId;
    $d = $aCbd->get("/api/invoices/{$invId}")['json']['data'];
    $lines = array_map('intval', array_column($d['items'], 'productId'));
    expect($lines === [$cbdProd] && ($d['factory']['code'] ?? null) === 'CBD', 'Cibadak invoice content: ' . json_encode($d));

    expectDenied($aKtg->get("/api/invoices/{$invId}"), 'FACTORY_ACCESS_DENIED', 'Admin KTG opens Cibadak invoice');
    $ktgList = array_map('intval', array_column($aKtg->get('/api/invoices')['json']['data'] ?? [], 'invoiceId'));
    expect(!in_array($invId, $ktgList, true), 'Cibadak invoice must not be in Admin KTG list');

    expect(count($mine($aKtg)) === 1, 'Karangtengah part must still be waiting for its own invoice');
    $g2 = $aKtg->send('POST', '/api/invoices/generate-ready', []);
    $ktgInv = array_values(array_filter($g2['json']['data']['invoices'] ?? [], static fn ($i) => $i['sourceKind'] === 'do'));
    expect(count($ktgInv) === 1, 'Admin KTG issues its own invoice: ' . $g2['body']);
    $d2 = $leader->get("/api/invoices/{$ktgInv[0]['invoiceId']}")['json']['data'];
    expect(array_map('intval', array_column($d2['items'], 'productId')) === [$ktgProd], 'Karangtengah invoice content: ' . json_encode($d2['items']));
});

runTest('AFS-08 Retur of a Cibadak product: Admin CBD opens it, Admin KTG is refused, Mutasi list opens for both', function () use ($leader, $aKtg, $aCbd, $token, $cbdProd) {
    $anon = new HttpAfs($GLOBALS['baseUrl']);
    $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    $r = $anon->requestMultipart("/api/store/{$token}/retur", ['retur' => json_encode(['productId' => $cbdProd, 'qty' => 1, 'reason' => 'Tidak laku', 'returDate' => $today])], ['evidence[]' => fakeEvidenceImage()], idemKey('afs-lapor'));
    expect($r['status'] === 200, 'retur create failed: ' . $r['body']);
    $returId = (int) $r['json']['data']['returId'];

    expect($aCbd->get("/api/admin/retur/{$returId}")['status'] === 200, 'Admin CBD must open its retur');
    expectDenied($aKtg->get("/api/admin/retur/{$returId}"), 'FACTORY_ACCESS_DENIED', 'Admin KTG opens Cibadak retur');
    $ktgList = array_map('intval', array_column($aKtg->get('/api/admin/retur?jenis=retur')['json']['data'] ?? [], 'returId'));
    expect(!in_array($returId, $ktgList, true), 'Cibadak retur must not be in Admin KTG list');
    expect($leader->get("/api/admin/retur/{$returId}")['status'] === 200, 'Leader opens any retur');
    foreach ([$aKtg, $aCbd] as $a) {
        expect($a->get('/api/admin/mutasi')['status'] === 200, 'Mutasi list must open for every admin');
    }
});

runTest('AFS-09 UI pages render for Admin pabrik and Leader; Leader-only pages and other-factory documents stay closed', function () use ($leader, $aKtg, $aCbd, &$doId, &$cbdInvoiceId, &$baseDocNo) {
    $page = static fn (Actor $a, string $q) => $a->get('/api/_ui-preview/index.php?' . $q);
    $clean = static function (array $r, string $what): void {
        expect($r['status'] === 200, "{$what}: HTTP {$r['status']}");
        foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $bad) {
            expect(!str_contains($r['body'], $bad), "{$what}: page shows '{$bad}'");
        }
    };
    $pages = ['hari-ini', 'dashboard', 'produksi', 'fg-packing', 'delivery-order', 'pengiriman', 'konfirmasi-toko', 'invoice', 'validasi-toko',
        'reject-toko', 'retur-review', 'mutasi-review', 'dashboard-retur', 'stok-sisa', 'laporan', 'pesanan-toko', 'replacement-reject',
        'master-data', 'pengaturan', 'bakery-portal-tokens', 'riwayat'];
    foreach ([['Leader', $leader], ['Admin CBD', $aCbd]] as [$who, $a]) {
        foreach ($pages as $p) {
            $clean($page($a, "page={$p}"), "{$who} page {$p}");
        }
    }
    foreach (['sistem', 'runchise', 'integrasi', 'alasan-reject'] as $p) {
        expect(str_contains($page($aCbd, "page={$p}")['body'], 'Khusus Leader Admin'), "Admin CBD must be kept out of {$p}");
        $clean($page($leader, "page={$p}"), "Leader page {$p}");
    }
    $nav = $page($aCbd, 'page=hari-ini')['body'];
    expect(!str_contains($nav, 'Kelola User'), 'Kelola User must be hidden from Admin pabrik');
    expect(str_contains($page($leader, 'page=hari-ini')['body'], 'Kelola User'), 'Leader keeps Kelola User');

    $doCbd = $page($aCbd, "page=delivery-order-detail&doId={$doId}");
    $clean($doCbd, 'Admin CBD DO detail');
    expect(str_contains($doCbd['body'], "{$baseDocNo}-CBD"), 'Admin CBD DO detail must show the -CBD doc no');
    $clean($page($aCbd, "page=invoice-detail&invoiceId={$cbdInvoiceId}"), 'Admin CBD invoice detail');
    // Surat jalan of a mixed DO carries the factory code, also when the Leader prints it.
    foreach ($GLOBALS['shipments'] as $factoryId => $sid) {
        $code = $factoryId === $GLOBALS['cbd'] ? 'CBD' : 'KTG';
        $sj = $leader->get("/api/_ui-preview/print-shipment.php?id={$sid}");
        $clean($sj, "Leader surat jalan {$code}");
        expect(str_contains($sj['body'], "{$baseDocNo}-{$code}"), "Leader surat jalan must show {$baseDocNo}-{$code}");
    }
    expect($aKtg->get("/api/_ui-preview/print-shipment.php?id={$GLOBALS['shipments'][$GLOBALS['cbd']]}")['status'] === 403, 'Admin KTG must not print the Cibadak surat jalan');
    $other = $page($aKtg, "page=invoice-detail&invoiceId={$cbdInvoiceId}");
    expect(str_contains($other['body'], 'pabrik lain') || $other['status'] === 403, 'Admin KTG must not see the Cibadak invoice page');
});

/** One Cibadak-only DO for $store on $tanggal, shipped by Admin CBD; returns [doId, shipmentId]. */
function cbdShipment(Actor $leader, Actor $aCbd, PDO $pdo, int $cbd, int $div, int $store, string $tanggal, int $productId, float $qty): array
{
    stockUp($leader, $pdo, $cbd, $div, $tanggal, $store, $productId, $qty);
    $c = $leader->send('POST', '/api/do', ['tanggal' => $tanggal, 'storeId' => $store]);
    expect($c['status'] === 200, 'DO create failed: ' . $c['body']);
    $doId = (int) $c['json']['data']['doId'];
    $v = $aCbd->get("/api/do/{$doId}")['json']['data']['version'];
    $s = $aCbd->send('POST', "/api/do/{$doId}/ship", ['expectedVersion' => $v, 'items' => [['productId' => $productId, 'actualQty' => $qty]]]);
    expect($s['status'] === 200, 'ship failed: ' . $s['body']);
    return [$doId, (int) $s['json']['data']['shipmentId']];
}
function readyHasDo(Actor $a, int $doId): bool
{
    foreach ($a->get('/api/invoices/ready')['json']['data']['deliveries'] ?? [] as $d) {
        if ($d['kind'] === 'do' && (int) $d['refId'] === $doId) return true;
    }
    return false;
}

runTest('AFS-10 Tahap 1: Admin marks shipments "Diterima semua sesuai SJ" (own factory only) and the invoice becomes ready', function () use ($leader, $aKtg, $aCbd, $pdo, $cbd, $cbdDiv, $store) {
    [, $p2] = divisionAndProduct($pdo, $cbd, 1);
    $day = (new DateTimeImmutable('+15 days', new DateTimeZone('UTC')))->format('Y-m-d');
    [$doId, $sid] = cbdShipment($leader, $aCbd, $pdo, $cbd, $cbdDiv, $store, $day, $p2, 5.0);
    expect(!readyHasDo($aCbd, $doId), 'not invoice-ready before confirmation');
    $list = $aCbd->get("/api/_ui-preview/index.php?page=konfirmasi-toko&tanggal={$day}");
    expect(str_contains($list['body'], 'Diterima semua sesuai SJ'), 'list page must offer the bulk button');

    expectDenied($aKtg->send('POST', '/api/admin/shipments/confirm-all-good', ['shipmentIds' => [$sid], 'receiverName' => 'x']), 'FACTORY_ACCESS_DENIED', 'Admin KTG confirms Cibadak shipment');
    $r = $aCbd->send('POST', '/api/admin/shipments/confirm-all-good', ['shipmentIds' => [$sid], 'receiverName' => 'Sesuai TTD surat jalan']);
    expect($r['status'] === 200 && $r['json']['data']['confirmed'] === [$sid], 'bulk confirm: ' . $r['body']);
    $again = $aCbd->send('POST', '/api/admin/shipments/confirm-all-good', ['shipmentIds' => [$sid], 'receiverName' => 'Sesuai TTD surat jalan']);
    expect($again['json']['data']['skipped'] === [$sid], 'second confirm must be skipped: ' . $again['body']);
    $st = $pdo->query("SELECT status FROM shipment_receipt WHERE shipment_id = {$sid}")->fetchColumn();
    expect($st === 'confirmed_ok', "receipt status {$st}");
    expect(readyHasDo($aCbd, $doId), 'invoice must be ready after Admin confirmation');
});

runTest('AFS-11 Tahap 1: Admin fills one surat jalan with Reject — needs receiver name and SJ photo, then Verifikasi makes it invoice-ready', function () use ($leader, $aCbd, $pdo, $cbd, $cbdDiv, $store) {
    [, $p3] = divisionAndProduct($pdo, $cbd, 2);
    $day = (new DateTimeImmutable('+16 days', new DateTimeZone('UTC')))->format('Y-m-d');
    [$doId, $sid] = cbdShipment($leader, $aCbd, $pdo, $cbd, $cbdDiv, $store, $day, $p3, 6.0);
    $page = $aCbd->get("/api/_ui-preview/index.php?page=konfirmasi-toko-detail&shipmentId={$sid}");
    expect(str_contains($page['body'], 'Isi Penerimaan dari Surat Jalan'), 'detail page must offer the SJ form');
    $lineId = (int) $pdo->query("SELECT shipment_item_id FROM shipment_item WHERE shipment_id = {$sid}")->fetchColumn();
    $items = json_encode([['shipmentItemId' => $lineId, 'receivedGood' => 5, 'reject' => 1, 'shortage' => 0]]);

    $noName = $aCbd->sendForm("/api/admin/shipments/{$sid}/confirm", ['items' => $items]);
    expect($noName['status'] === 400 && code($noName) === 'RECEIVER_REQUIRED', 'receiver required: ' . $noName['body']);
    $noPhoto = $aCbd->sendForm("/api/admin/shipments/{$sid}/confirm", ['items' => $items, 'receiverName' => 'Bu Sari']);
    expect($noPhoto['status'] === 400 && code($noPhoto) === 'EVIDENCE_REQUIRED', 'photo required: ' . $noPhoto['body']);
    $ok = $aCbd->sendForm("/api/admin/shipments/{$sid}/confirm", ['items' => $items, 'receiverName' => 'Bu Sari', 'note' => '1 penyok'], ['evidence[]' => fakeEvidenceImage()]);
    expect($ok['status'] === 200, 'confirm with photo: ' . $ok['body']);
    $rc = $pdo->query("SELECT shipment_receipt_id, status, note FROM shipment_receipt WHERE shipment_id = {$sid}")->fetch();
    expect($rc['status'] === 'confirmed_discrepancy' && str_contains((string) $rc['note'], 'Diisi Admin dari surat jalan'), 'receipt: ' . json_encode($rc));
    $audit = $pdo->query("SELECT user_id FROM audit_log WHERE action = 'receipt.confirmed' AND record_key = '{$rc['shipment_receipt_id']}'")->fetchColumn();
    expect($audit !== false && $audit !== null, 'audit must name the Admin who filled it');
    expect(!readyHasDo($aCbd, $doId), 'discrepancy waits for Verifikasi');

    $v = $aCbd->send('POST', "/api/admin/receipts/{$rc['shipment_receipt_id']}/verify", []);
    expect($v['status'] === 200, 'verify: ' . $v['body']);
    expect(readyHasDo($aCbd, $doId), 'invoice ready after Verifikasi');
    $g = $aCbd->send('POST', '/api/invoices/generate-ready', []);
    $inv = array_values(array_filter($g['json']['data']['invoices'] ?? [], static fn ($i) => $i['sourceKind'] === 'do'));
    $qty = $pdo->query('SELECT SUM(qty_invoice) FROM invoice_item WHERE invoice_id = ' . (int) ($inv[0]['invoiceId'] ?? 0))->fetchColumn();
    expect((float) $qty === 5.0, "invoice must bill the 5 good pcs, got {$qty}");
});

$failed = count(array_filter($results, static fn ($ok) => !$ok));
fwrite(STDOUT, "\n" . count($results) . " tests, {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
