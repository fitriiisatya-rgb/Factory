<?php

declare(strict_types=1);

/**
 * Dev/test-only helper for migration 0013's DATA PRESERVATION test. Prints
 * one line per business table as "table|row_count|content_hash", where
 * content_hash is an order-independent MD5 over every row's every column
 * (NULL-safe). Run once before 0013 and once after — the two outputs must
 * be byte-for-byte identical for every table 0013's DDL touches, proving
 * the migration never inserted, updated, or deleted a single business row.
 *
 * Usage: php _snapshot_0013_tables.php <socket> <db_name>
 */

$socket = $argv[1] ?? null;
$dbName = $argv[2] ?? null;
if ($socket === null || $dbName === null) {
    fwrite(STDERR, "Usage: php _snapshot_0013_tables.php <socket> <db_name>\n");
    exit(2);
}

$pdo = new PDO("mysql:unix_socket={$socket};dbname={$dbName};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$tables = [
    'users', 'store', 'product', 'division', 'factory',
    'po_batch', 'po_item', 'po_store_item',
    'production_run', 'production_item',
    'fg_batch', 'fg_batch_source', 'fg_item',
    'delivery_order', 'delivery_order_item',
    'shipment', 'shipment_item',
    'special_order', 'special_order_item',
];

foreach ($tables as $table) {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    // MD5 per row (all columns, NULL-safe via a fixed separator that cannot
    // appear in a numeric/date/enum value), then an order-independent
    // combine (sorted list of per-row hashes, joined and hashed again) so
    // AUTO_INCREMENT id reuse or read-order differences never cause a false
    // mismatch — only actual row content differences do.
    $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table}' ORDER BY ORDINAL_POSITION")
        ->fetchAll(PDO::FETCH_COLUMN);
    $concatExpr = implode(", '\\u0001', ", array_map(fn ($c) => "COALESCE(`{$c}`, '\\u0002NULL\\u0002')", $cols));
    $rowHashes = $pdo->query("SELECT MD5(CONCAT_WS('', {$concatExpr})) FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    sort($rowHashes);
    $combined = md5(implode('|', $rowHashes));
    fwrite(STDOUT, "{$table}|{$count}|{$combined}\n");
}
