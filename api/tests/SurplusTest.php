<?php

declare(strict_types=1);

/**
 * Packing-based shipping + Stok Sisa — SURPLUS-01..07.
 *
 * Business rules (owner, Oct 2026): what FG packs for a store is what is
 * shipped and may be above the PO; production above what the stores get
 * is kept as Stok Sisa, visible to Admin and usable for a Pesanan
 * Khusus/Non-Toko — never taken from FG already packed for a store.
 *
 * Scenario (Karangtengah, 2026-12-10, division Roti & Bollen):
 *   P: PO A=4, B=2 (6) — produced 10 -> A 4, B 2, Stok Sisa 4
 *   Q: PO A=5        — produced 3  -> A 3 (short)
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;

Config::load();

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8160';
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
$ktg = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
$roti = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
$storeA = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
$storeB = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE B'")->fetchColumn();
$unalloc = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'NON-OUTLET / PERORANGAN'")->fetchColumn();
[$P, $Q] = array_map('intval', $pdo->query("SELECT product_id FROM product WHERE division_id = {$roti} ORDER BY product_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
expect($ktg > 0 && $roti > 0 && $storeA > 0 && $storeB > 0 && $unalloc > 0 && $P > 0 && $Q > 0, 'fixtures missing');
$T = '2026-12-10';
seedStorePo($pdo, $T, $ktg, $storeA, [$P => 4, $Q => 5]);
seedStorePo($pdo, $T, $ktg, $storeB, [$P => 2]);

$a = new HttpOp($baseUrl);
$a->form('/_admin-login/', ['action' => 'login', 'username' => $adminUser, 'password' => $adminPass]);
$csrf = csrfOf($a);
$h = fn (string $tag) => array_merge(['X-CSRF-Token' => $csrf], idemKey($tag));
$fgId = 0;
$fgVersion = 0;

runTest('SURPLUS-01 Production above the PO is accepted and submitted', function () use ($a, $h, $T, $roti, $P, $Q) {
    $r = $a->json('POST', '/api/production', ['tanggal' => $T, 'divisionId' => $roti], $h('sp-prod'));
    expect($r['status'] === 200, 'create run failed: ' . $r['body']);
    $runId = (int) $r['json']['data']['productionRunId'];
    $p = $a->json('PATCH', "/api/production/{$runId}", ['expectedVersion' => $r['json']['data']['version'], 'items' => [
        ['productId' => $P, 'actualQty' => 10, 'rejectQty' => 0, 'notes' => '1 loyang penuh'],
        ['productId' => $Q, 'actualQty' => 3, 'rejectQty' => 0, 'notes' => 'adonan kurang'],
    ]], $h('sp-prod-patch'));
    expect($p['status'] === 200, 'patch failed: ' . $p['body']);
    $s = $a->json('POST', "/api/production/{$runId}/submit", ['expectedVersion' => $p['json']['data']['version']], $h('sp-prod-submit'));
    expect($s['status'] === 200, 'submit failed: ' . $s['body']);
});

runTest('SURPLUS-02 FG splits P per store at the PO and keeps the rest as Stok Sisa', function () use ($a, $h, $T, $ktg, $P, $Q, $storeA, $storeB, $unalloc, &$fgId, &$fgVersion) {
    $c = $a->json('POST', '/api/fg', ['tanggal' => $T, 'factoryId' => $ktg], $h('sp-fg'));
    expect($c['status'] === 200, 'fg create failed: ' . $c['body']);
    $fgId = (int) $c['json']['data']['fgBatchId'];
    $r = $a->json('PATCH', "/api/fg/{$fgId}", ['expectedVersion' => $c['json']['data']['version'], 'storeItems' => [
        ['productId' => $P, 'rows' => [
            ['storeId' => $storeA, 'fgVerified' => 4, 'packed' => 0, 'sesuaiVerified' => true],
            ['storeId' => $storeB, 'fgVerified' => 2, 'packed' => 0, 'sesuaiVerified' => true],
            ['storeId' => $unalloc, 'fgVerified' => 4, 'packed' => 4, 'notes' => 'Stok Sisa produksi'],
        ]],
        ['productId' => $Q, 'rows' => [
            ['storeId' => $storeA, 'fgVerified' => 3, 'packed' => 0, 'sesuaiVerified' => false, 'notes' => 'produksi kurang'],
        ]],
    ]], $h('sp-fg-split'));
    expect($r['status'] === 200, 'split failed: ' . $r['body']);
    $fgVersion = (int) $r['json']['data']['version'];
    $st = $a->json('GET', "/api/fg/{$fgId}/items/{$P}/stores", null);
    expect(abs($st['json']['data']['surplus'] - 4) < 0.01, 'expected Stok Sisa 4, got ' . json_encode($st['json']['data']['surplus'] ?? null));
});

runTest('SURPLUS-03 A store may get MORE than its PO; the extra comes out of Stok Sisa', function () use ($a, $h, $P, $storeA, &$fgId, &$fgVersion) {
    $r = $a->json('PATCH', "/api/fg/{$fgId}", ['expectedVersion' => $fgVersion, 'storeItems' => [
        ['productId' => $P, 'rows' => [['storeId' => $storeA, 'fgVerified' => 6, 'packed' => 0, 'notes' => 'toko minta lebih']]],
    ]], $h('sp-fg-more'));
    expect($r['status'] === 200, 'raising store A above its PO must be allowed: ' . $r['body']);
    $fgVersion = (int) $r['json']['data']['version'];
    $st = $a->json('GET', "/api/fg/{$fgId}/items/{$P}/stores", null)['json']['data'];
    $rowA = array_values(array_filter($st['stores'], fn ($s) => $s['storeId'] === $storeA))[0];
    expect(abs($rowA['fgVerified'] - 6) < 0.01, 'expected store A 6');
    expect(abs($st['surplus'] - 2) < 0.01, 'expected Stok Sisa reduced to 2, got ' . $st['surplus']);
});

runTest('SURPLUS-04 Pack every store and submit FG', function () use ($a, $h, $P, $Q, $storeA, $storeB, &$fgId, &$fgVersion) {
    foreach ([$storeA => [[$P, 6], [$Q, 3]], $storeB => [[$P, 2]]] as $store => $lines) {
        $rows = array_map(fn ($l) => ['productId' => $l[0], 'storeId' => $store, 'fgVerified' => $l[1], 'packed' => $l[1], 'sesuaiPacking' => true], $lines);
        $r = $a->json('POST', "/api/fg/{$fgId}/packing-submit", ['expectedVersion' => $fgVersion, 'storeId' => $store, 'rows' => $rows], $h('sp-pack-' . $store));
        expect($r['status'] === 200, "packing store {$store} failed: " . $r['body']);
        $fgVersion = (int) $r['json']['data']['version'];
    }
    $s = $a->json('POST', "/api/fg/{$fgId}/submit", ['expectedVersion' => $fgVersion], $h('sp-fg-submit'));
    expect($s['status'] === 200, 'fg submit failed: ' . $s['body']);
});

runTest('SURPLUS-05 DO follows packing: above the PO for P, below it for Q', function () use ($a, $h, $pdo, $T, $ktg, $P, $Q, $storeA, $storeB) {
    $g = $a->json('POST', '/api/do/generate-bulk', ['tanggal' => $T, 'factoryId' => $ktg], $h('sp-do'));
    expect($g['status'] === 200, 'bulk DO failed: ' . $g['body']);
    $planned = function (int $store, int $product) use ($pdo, $T): ?float {
        $st = $pdo->prepare('SELECT di.planned_qty FROM delivery_order_item di INNER JOIN delivery_order d ON d.delivery_order_id = di.delivery_order_id WHERE d.tanggal = ? AND d.store_id = ? AND di.product_id = ?');
        $st->execute([$T, $store, $product]);
        $v = $st->fetchColumn();
        return $v === false ? null : (float) $v;
    };
    expect($planned($storeA, $P) === 6.0, 'store A / P: expected 6 (packed, PO was 4), got ' . var_export($planned($storeA, $P), true));
    expect($planned($storeA, $Q) === 3.0, 'store A / Q: expected 3 (packed, PO was 5), got ' . var_export($planned($storeA, $Q), true));
    expect($planned($storeB, $P) === 2.0, 'store B / P: expected 2, got ' . var_export($planned($storeB, $P), true));
});

runTest('SURPLUS-06 Stok Sisa shows P = 2 and a Pesanan Non-Toko can take it, but never store-packed FG', function () use ($a, $h, $pdo, $ktg, $P, $Q) {
    $rows = (new \Amor\Api\Fg\StokSisaService($pdo))->list($ktg);
    $byProduct = array_column($rows, 'sisa', 'productId');
    expect(abs(($byProduct[$P] ?? 0) - 2) < 0.01, 'expected Stok Sisa P=2, got ' . json_encode($byProduct));
    expect(!isset($byProduct[$Q]), 'Q has no surplus');
    $c = $a->json('POST', '/api/special-orders', [
        'sourceType' => 'non_toko', 'nonStoreSource' => 'cs', 'customerName' => 'Sisa', 'fulfillmentType' => 'pickup',
        'orderDate' => '2026-12-10', 'requiredDate' => '2026-12-10',
        'items' => [['itemType' => 'existing_product', 'productId' => $P, 'qty' => 5, 'unitPrice' => 1000]],
    ], $h('sp-so'));
    expect($c['status'] === 200, 'order create failed: ' . $c['body']);
    $oid = (int) $c['json']['data']['orderId'];
    $itemId = (int) $c['json']['data']['items'][0]['itemId'];
    $cf = $a->json('POST', "/api/special-orders/{$oid}/confirm", ['expectedVersion' => $c['json']['data']['version']], $h('sp-so-c'));
    $a->json('POST', "/api/special-orders/{$oid}/send-to-production", ['expectedVersion' => $cf['json']['data']['version']], $h('sp-so-s'));
    $over = $a->json('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 3], $h('sp-so-a1'));
    expect($over['status'] === 409 && ($over['json']['code'] ?? '') === 'EXCEEDS_FREE_FG', 'taking 3 must fail: only 2 are free, the rest is packed for stores — got ' . $over['body']);
    $ok = $a->json('POST', "/api/special-orders/items/{$itemId}/allocate-fg", ['qty' => 2], $h('sp-so-a2'));
    expect($ok['status'] === 200, 'taking the 2 Stok Sisa must work: ' . $ok['body']);
    $after = array_column((new \Amor\Api\Fg\StokSisaService($pdo))->list($ktg), 'sisa', 'productId');
    expect(!isset($after[$P]), 'Stok Sisa P must be 0 after the allocation, got ' . json_encode($after));
});

runTest('SURPLUS-07 Stok Sisa page: Admin 200, divisi account 403', function () use ($baseUrl, $pdo, $a) {
    $page = $a->get('/api/_ui-preview/?page=stok-sisa');
    expect($page['status'] === 200 && str_contains($page['body'], 'Stok Sisa'), 'expected the Stok Sisa page for Admin, got ' . $page['status']);
    makeUser($pdo, 'sp_divisi', 'Divisi Sisa', 'DivisiSisa#2026', ['PRODUCTION'], [(int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn()]);
    $d = new HttpOp($baseUrl);
    $d->form('/_admin-login/', ['action' => 'login', 'username' => 'sp_divisi', 'password' => 'DivisiSisa#2026']);
    expect($d->get('/api/_ui-preview/?page=stok-sisa')['status'] === 403, 'a divisi account must not open Stok Sisa');
});

$failed = array_filter($results, static fn ($ok) => !$ok);
fwrite(STDOUT, "\n" . count($results) . ' tests, ' . count($failed) . " failed.\n");
exit(count($failed) === 0 ? 0 : 1);
