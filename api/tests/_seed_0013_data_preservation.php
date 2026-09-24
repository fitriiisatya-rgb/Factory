<?php

declare(strict_types=1);

/**
 * Dev/test-only seed for migration 0013's DATA PRESERVATION test
 * (test-0013-live-schema-repair.sh). Inserts exactly ONE real, valid,
 * fully-linked business row into each of the table families the task's
 * own instructions name: Regular PO, Production, FG, DO, Shipment,
 * Special Order (Users/Stores/Products already exist from the normal
 * seed.php + _phase2_bootstrap_master.php master-data bootstrap this
 * script runs after). Every FK below resolves against that real master
 * data — never a fabricated id.
 *
 * Used to prove 0013 (pure additive DDL — no INSERT/UPDATE/DELETE
 * anywhere in it) never touches a single existing business row: the
 * companion _snapshot_0013_tables.php script hashes every affected
 * table's full content before and after 0013 runs, and the two hashes
 * must match exactly.
 *
 * Usage: php _seed_0013_data_preservation.php <socket> <db_name>
 */

$socket = $argv[1] ?? null;
$dbName = $argv[2] ?? null;
if ($socket === null || $dbName === null) {
    fwrite(STDERR, "Usage: php _seed_0013_data_preservation.php <socket> <db_name>\n");
    exit(2);
}

$pdo = new PDO("mysql:unix_socket={$socket};dbname={$dbName};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$factoryId = (int) $pdo->query("SELECT factory_id FROM factory WHERE code = 'KTG'")->fetchColumn();
$divisionId = (int) $pdo->query('SELECT division_id FROM division ORDER BY division_id LIMIT 1')->fetchColumn();
$productId = (int) $pdo->query("SELECT product_id FROM product WHERE division_id = {$divisionId} ORDER BY product_id LIMIT 1")->fetchColumn();
$storeId = (int) $pdo->query("SELECT store_id FROM store WHERE canonical_name = 'P2 TEST STORE A'")->fetchColumn();
$userId = (int) $pdo->query('SELECT user_id FROM users ORDER BY user_id LIMIT 1')->fetchColumn();

if ($factoryId === 0 || $divisionId === 0 || $productId === 0 || $storeId === 0 || $userId === 0) {
    fwrite(STDERR, "Master data not found (factory={$factoryId} division={$divisionId} product={$productId} store={$storeId} user={$userId}) — run seed.php + _phase2_bootstrap_master.php first.\n");
    exit(1);
}

$tanggal = '2026-08-01';

$pdo->beginTransaction();

// --- Regular PO -------------------------------------------------------------
$pdo->prepare('INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())')
    ->execute([$tanggal, $factoryId]);
$poBatchId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$poBatchId, $productId, 'DATA-PRESERVATION-TEST', 12, 12, 0]);
$poItemId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, ?, ?)')
    ->execute([$poItemId, $storeId, 12, 12]);

// --- Production ---------------------------------------------------------------
$pdo->prepare("INSERT INTO production_run (tanggal, division_id, status, version, created_at) VALUES (?, ?, 'submitted', 1, UTC_TIMESTAMP())")
    ->execute([$tanggal, $divisionId]);
$productionRunId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO production_item (production_run_id, product_id, target, status, aktual, reject, keterangan) VALUES (?, ?, ?, 'sesuai', ?, 0, 'DATA-PRESERVATION-TEST')")
    ->execute([$productionRunId, $productId, 12, 12]);

// --- FG -------------------------------------------------------------------
$pdo->prepare('INSERT INTO fg_batch (tanggal, factory_id, version, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())')
    ->execute([$tanggal, $factoryId]);
$fgBatchId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO fg_batch_source (fg_batch_id, production_run_id, source_version) VALUES (?, ?, 1)')
    ->execute([$fgBatchId, $productionRunId]);
$pdo->prepare("INSERT INTO fg_item (fg_batch_id, product_id, store_id, qty, status, keterangan) VALUES (?, ?, ?, 12, 'sudah_dicek', 'DATA-PRESERVATION-TEST')")
    ->execute([$fgBatchId, $productId, $storeId]);

// --- Delivery Order + Shipment ----------------------------------------------
$pdo->prepare("INSERT INTO delivery_order (doc_no, tanggal, store_id, shipment_group, status, created_by, version, created_at) VALUES (?, ?, ?, 'MAIN', 'shipped', ?, 1, UTC_TIMESTAMP())")
    ->execute(['DPT-TEST-DO-1', $tanggal, $storeId, $userId]);
$doId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO delivery_order_item (delivery_order_id, product_id, planned_qty, available_qty, actual_ship_qty) VALUES (?, ?, 12, 12, 12)')
    ->execute([$doId, $productId]);

$pdo->prepare("INSERT INTO shipment (batch, tanggal, store_id, shipment_group, source_type, delivery_order_id, status, version, created_at) VALUES (?, ?, ?, 'MAIN', 'delivery_order', ?, 'active', 1, UTC_TIMESTAMP())")
    ->execute(['DPT-TEST-BATCH-1', $tanggal, $storeId, $doId]);
$shipmentId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO shipment_item (shipment_id, product_id, qty) VALUES (?, ?, 12)')
    ->execute([$shipmentId, $productId]);

// --- Special Order ------------------------------------------------------------
$pdo->prepare("INSERT INTO special_order (order_no, source_type, order_date, store_id, factory_id, required_date, pic_user_id, status, version, created_by, created_at) VALUES (?, 'toko_khusus', ?, ?, ?, ?, ?, 'draft', 1, ?, UTC_TIMESTAMP())")
    ->execute(['DPT-TEST-SO-1', $tanggal, $storeId, $factoryId, $tanggal, $userId, $userId]);
$specialOrderId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO special_order_item (special_order_id, item_type, product_id, division_id, item_name_snapshot, qty, unit_price, charge, subtotal, created_at) VALUES (?, 'existing_product', ?, ?, 'DATA-PRESERVATION-TEST', 12, 1000, 0, 12000, UTC_TIMESTAMP())")
    ->execute([$specialOrderId, $productId, $divisionId]);

$pdo->commit();

fwrite(STDOUT, "Seeded: po_batch={$poBatchId} production_run={$productionRunId} fg_batch={$fgBatchId} delivery_order={$doId} shipment={$shipmentId} special_order={$specialOrderId}\n");
