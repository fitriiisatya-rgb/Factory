<?php

declare(strict_types=1);

/**
 * Dev/test diagnostic for migration 0013 (LIVE SCHEMA REPAIR for 0012).
 *
 * Reports every schema object current application code expects from
 * migration 0012's final shape as EXISTS / MISSING / INCOMPLETE, by
 * reading information_schema only (never writes anything). Used by
 * test-0013-live-schema-repair.sh to verify each of STATE A-D before and
 * after applying 0013 — this script itself is never run by the operator
 * and 0013 does not depend on it; it exists purely so this repair's own
 * test suite can assert precisely which objects are missing/incomplete
 * at each stage, instead of eyeballing raw information_schema output.
 *
 * Usage: php _diagnose_0012_schema.php <socket> <db_name>
 * Exit code: 0 if every object is EXISTS, 1 if anything is MISSING/INCOMPLETE.
 */

$socket = $argv[1] ?? null;
$dbName = $argv[2] ?? null;
if ($socket === null || $dbName === null) {
    fwrite(STDERR, "Usage: php _diagnose_0012_schema.php <socket> <db_name>\n");
    exit(2);
}

$pdo = new PDO("mysql:unix_socket={$socket};dbname={$dbName};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

function tableExists(PDO $pdo, string $db, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([$db, $table]);
    return ((int) $stmt->fetchColumn()) > 0;
}

/** @return array{exists:bool,nullable:?string,columnType:?string} */
function columnInfo(PDO $pdo, string $db, string $table, string $column): array
{
    $stmt = $pdo->prepare(
        'SELECT IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$db, $table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return ['exists' => false, 'nullable' => null, 'columnType' => null];
    }
    return ['exists' => true, 'nullable' => $row['IS_NULLABLE'], 'columnType' => $row['COLUMN_TYPE']];
}

function constraintExists(PDO $pdo, string $db, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $stmt->execute([$db, $table, $constraint]);
    return ((int) $stmt->fetchColumn()) > 0;
}

function indexExists(PDO $pdo, string $db, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$db, $table, $index]);
    return ((int) $stmt->fetchColumn()) > 0;
}

$results = [];
$anyBad = false;

function report(array &$results, bool &$anyBad, string $label, string $status): void
{
    $results[] = [$label, $status];
    if ($status !== 'EXISTS') {
        $anyBad = true;
    }
}

// 1-2. special_order_item.extra_packaging / fg_verified_qty
foreach (['extra_packaging', 'fg_verified_qty'] as $col) {
    $info = columnInfo($pdo, $dbName, 'special_order_item', $col);
    report($results, $anyBad, "special_order_item.$col", $info['exists'] ? 'EXISTS' : 'MISSING');
}

// 3-5. special_order_do / special_order_do_item / special_order_do_shipment_item
foreach (['special_order_do', 'special_order_do_item', 'special_order_do_shipment_item'] as $table) {
    report($results, $anyBad, $table, tableExists($pdo, $dbName, $table) ? 'EXISTS' : 'MISSING');
}
if (tableExists($pdo, $dbName, 'special_order_do')) {
    foreach (['factory_id', 'drop_store_id', 'delivery_method', 'courier_provider', 'courier_name', 'external_order_reference', 'claimed_by_user_id', 'claimed_at'] as $col) {
        $info = columnInfo($pdo, $dbName, 'special_order_do', $col);
        report($results, $anyBad, "special_order_do.$col", $info['exists'] ? 'EXISTS' : 'MISSING');
    }
}

// 6-12. shipment columns + source_type enum + FK
$shipmentSourceType = columnInfo($pdo, $dbName, 'shipment', 'source_type');
$hasSpecialOrderDoValue = $shipmentSourceType['exists'] && str_contains((string) $shipmentSourceType['columnType'], "'special_order_do'");
report($results, $anyBad, "shipment.source_type includes 'special_order_do'", $hasSpecialOrderDoValue ? 'EXISTS' : 'INCOMPLETE');
foreach (['special_order_do_id', 'delivery_method', 'courier_provider', 'courier_name', 'external_order_reference', 'handover_note'] as $col) {
    $info = columnInfo($pdo, $dbName, 'shipment', $col);
    report($results, $anyBad, "shipment.$col", $info['exists'] ? 'EXISTS' : 'MISSING');
}
report($results, $anyBad, 'FK shipment -> special_order_do (fk_shipment_special_order_do)', constraintExists($pdo, $dbName, 'shipment', 'fk_shipment_special_order_do') ? 'EXISTS' : 'MISSING');

// 14. shipment_receipt_token
report($results, $anyBad, 'shipment_receipt_token', tableExists($pdo, $dbName, 'shipment_receipt_token') ? 'EXISTS' : 'MISSING');

// 15-19. shipment_receipt_item widening
$sriShipmentItemId = columnInfo($pdo, $dbName, 'shipment_receipt_item', 'shipment_item_id');
report($results, $anyBad, 'shipment_receipt_item.shipment_item_id nullable', ($sriShipmentItemId['exists'] && $sriShipmentItemId['nullable'] === 'YES') ? 'EXISTS' : 'INCOMPLETE');
$sriProductId = columnInfo($pdo, $dbName, 'shipment_receipt_item', 'product_id');
report($results, $anyBad, 'shipment_receipt_item.product_id nullable', ($sriProductId['exists'] && $sriProductId['nullable'] === 'YES') ? 'EXISTS' : 'INCOMPLETE');
$sriSpecialLine = columnInfo($pdo, $dbName, 'shipment_receipt_item', 'special_order_do_shipment_item_id');
report($results, $anyBad, 'shipment_receipt_item.special_order_do_shipment_item_id', $sriSpecialLine['exists'] ? 'EXISTS' : 'MISSING');
$sriNameSnapshot = columnInfo($pdo, $dbName, 'shipment_receipt_item', 'item_name_snapshot');
report($results, $anyBad, 'shipment_receipt_item.item_name_snapshot', $sriNameSnapshot['exists'] ? 'EXISTS' : 'MISSING');
report($results, $anyBad, 'FK shipment_receipt_item -> special_order_do_shipment_item (fk_sri_special_line)', constraintExists($pdo, $dbName, 'shipment_receipt_item', 'fk_sri_special_line') ? 'EXISTS' : 'MISSING');

// 20-21. special_order_fg_allocation + indexes/FKs
report($results, $anyBad, 'special_order_fg_allocation', tableExists($pdo, $dbName, 'special_order_fg_allocation') ? 'EXISTS' : 'MISSING');
if (tableExists($pdo, $dbName, 'special_order_fg_allocation')) {
    foreach (['ix_sofa_item', 'ix_sofa_order', 'ix_sofa_product_factory', 'ix_sofa_status'] as $ix) {
        report($results, $anyBad, "special_order_fg_allocation index $ix", indexExists($pdo, $dbName, 'special_order_fg_allocation', $ix) ? 'EXISTS' : 'MISSING');
    }
    foreach (['fk_sofa_item', 'fk_sofa_order', 'fk_sofa_product', 'fk_sofa_factory', 'fk_sofa_created_by'] as $fk) {
        report($results, $anyBad, "special_order_fg_allocation FK $fk", constraintExists($pdo, $dbName, 'special_order_fg_allocation', $fk) ? 'EXISTS' : 'MISSING');
    }
}

// 22. stock_ledger.source_type enum
$stockLedgerSourceType = columnInfo($pdo, $dbName, 'stock_ledger', 'source_type');
$hasFgItem = $stockLedgerSourceType['exists'] && str_contains((string) $stockLedgerSourceType['columnType'], "'fg_item'");
$hasSofa = $stockLedgerSourceType['exists'] && str_contains((string) $stockLedgerSourceType['columnType'], "'special_order_fg_allocation'");
report($results, $anyBad, "stock_ledger.source_type includes 'fg_item'", $hasFgItem ? 'EXISTS' : 'INCOMPLETE');
report($results, $anyBad, "stock_ledger.source_type includes 'special_order_fg_allocation'", $hasSofa ? 'EXISTS' : 'INCOMPLETE');

foreach ($results as [$label, $status]) {
    fwrite(STDOUT, sprintf("%-70s = %s\n", $label, $status));
}

exit($anyBad ? 1 : 0);
