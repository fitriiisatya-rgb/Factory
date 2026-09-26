<?php

declare(strict_types=1);

/**
 * Standalone fixture setup for _ui_smoke_shipment_guard.sh — explodes one
 * product into per-store FG rows, submits, creates a Regular DO for one
 * of those stores, then prints "csrf|doId" for the shell script to curl
 * the real pengiriman.php (Kirim) UI page with the same session cookie.
 */

$baseUrl = getenv('UISMOKE_BASE_URL');
$adminUser = getenv('UISMOKE_ADMIN_USER');
$adminPass = getenv('UISMOKE_ADMIN_PASS');
$dbSocket = getenv('UISMOKE_DB_SOCKET');
$dbName = getenv('UISMOKE_DB_NAME');
$dbUser = getenv('UISMOKE_DB_USER');
$dbPass = getenv('UISMOKE_DB_PASS');
$cookieJar = getenv('UISMOKE_COOKIE_JAR');

function req(string $method, string $url, ?array $body, array $headers, string $cookieJar): array
{
    $ch = curl_init($url);
    $hdrLines = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $hdrLines[] = "{$k}: {$v}";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_HTTPHEADER => $hdrLines,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        fwrite(STDERR, "curl error: " . curl_error($ch) . "\n");
        exit(1);
    }
    curl_close($ch);
    return ['json' => json_decode($raw, true), 'raw' => $raw];
}

function idem(string $tag): array
{
    return ['Idempotency-Key' => $tag . '-' . uniqid('', true)];
}

$pdo = new PDO("mysql:unix_socket={$dbSocket};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$login = req('POST', "{$baseUrl}/api/auth/login", ['username' => $adminUser, 'password' => $adminPass], [], $cookieJar);
$csrf = $login['json']['data']['csrfToken'] ?? null;
if ($csrf === null) {
    fwrite(STDERR, "login failed: " . $login['raw'] . "\n");
    exit(1);
}

$factoryId = (int) $pdo->query("SELECT factory_id FROM factory WHERE name = 'Karangtengah'")->fetchColumn();
$divId = (int) $pdo->query("SELECT division_id FROM division WHERE name = 'Roti & Bollen'")->fetchColumn();
$prod = $pdo->query("SELECT product_id FROM product WHERE division_id = {$divId} ORDER BY product_id LIMIT 1")->fetch();
$productId = (int) $prod['product_id'];
$storeA = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
$storeB = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE B'")->fetchColumn();
$tanggal = '2026-11-05';

$pdo->prepare('INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())')->execute([$tanggal, $factoryId]);
$poBatchId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (?, ?, 'TEST', 15, 0, 0)")->execute([$poBatchId, $productId]);
$poItemId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, 9, 0), (?, ?, 6, 0)')->execute([$poItemId, $storeA, $poItemId, $storeB]);

$create = req('POST', "{$baseUrl}/api/production", ['tanggal' => $tanggal, 'divisionId' => $divId], array_merge(['X-CSRF-Token' => $csrf], idem('uismoke-ship-prod-create')), $cookieJar);
$runId = $create['json']['data']['productionRunId'] ?? null;
if ($runId === null) { fwrite(STDERR, "production create failed: " . $create['raw'] . "\n"); exit(1); }
$ver = $create['json']['data']['version'];

$p1 = req('PATCH', "{$baseUrl}/api/production/{$runId}", ['expectedVersion' => $ver, 'items' => [['productId' => $productId, 'actualQty' => 15]]], array_merge(['X-CSRF-Token' => $csrf], idem('uismoke-ship-prod-patch')), $cookieJar);
$ver = $p1['json']['data']['version'] ?? null;
if ($ver === null) { fwrite(STDERR, "production patch failed: " . $p1['raw'] . "\n"); exit(1); }

$sub = req('POST', "{$baseUrl}/api/production/{$runId}/submit", ['expectedVersion' => $ver], array_merge(['X-CSRF-Token' => $csrf], idem('uismoke-ship-prod-submit')), $cookieJar);
if (($sub['json']['ok'] ?? false) !== true) { fwrite(STDERR, "production submit failed: " . $sub['raw'] . "\n"); exit(1); }

$fg = req('POST', "{$baseUrl}/api/fg", ['tanggal' => $tanggal, 'factoryId' => $factoryId], array_merge(['X-CSRF-Token' => $csrf], idem('uismoke-ship-fg-create')), $cookieJar);
$fgBatchId = $fg['json']['data']['fgBatchId'] ?? null;
if ($fgBatchId === null) { fwrite(STDERR, "fg create failed: " . $fg['raw'] . "\n"); exit(1); }
$fgVer = $fg['json']['data']['version'];

$explode = req('PATCH', "{$baseUrl}/api/fg/{$fgBatchId}", [
    'expectedVersion' => $fgVer,
    'storeItems' => [['productId' => $productId, 'rows' => [
        ['storeId' => $storeA, 'fgVerified' => 9, 'packed' => 9, 'sesuaiVerified' => true, 'sesuaiPacking' => true],
        ['storeId' => $storeB, 'fgVerified' => 6, 'packed' => 4, 'sesuaiVerified' => true, 'sesuaiPacking' => false, 'notes' => '2 pcs masih dicek'],
    ]]],
], array_merge(['X-CSRF-Token' => $csrf], idem('uismoke-ship-fg-explode')), $cookieJar);
if (!isset($explode['json']['data']['version'])) { fwrite(STDERR, "fg explode failed: " . $explode['raw'] . "\n"); exit(1); }
$fgVer = $explode['json']['data']['version'];

$submitFg = req('POST', "{$baseUrl}/api/fg/{$fgBatchId}/submit", ['expectedVersion' => $fgVer], array_merge(['X-CSRF-Token' => $csrf], idem('uismoke-ship-fg-submit')), $cookieJar);
if (!isset($submitFg['json']['data']['version'])) { fwrite(STDERR, "fg submit failed: " . $submitFg['raw'] . "\n"); exit(1); }

$doCreate = req('POST', "{$baseUrl}/api/do", ['tanggal' => $tanggal, 'storeId' => $storeA], array_merge(['X-CSRF-Token' => $csrf], idem('uismoke-ship-do-create')), $cookieJar);
$doId = $doCreate['json']['data']['doId'] ?? null;
if ($doId === null) { fwrite(STDERR, "do create failed: " . $doCreate['raw'] . "\n"); exit(1); }

echo "{$csrf}|{$doId}\n";
