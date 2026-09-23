<?php

declare(strict_types=1);

// Child process for ALLOC-GLOBAL-05/06 (real concurrency race between a
// Regular PO shipment and a special-order FG allocation racing for the
// SAME physical stock). Opens its own DB connection (deliberately not
// sharing the parent's PDO) and attempts exactly one Regular PO
// ShipmentService::ship() call inside a real transaction, mirroring
// Idempotency::handle()'s own Database::transaction() wrapper. The parent
// spawns this alongside _fg_allocate_race_child.php via proc_open (same
// pattern as _seq_allocate_child.php) to get real concurrent connections
// racing for the same stock_balance row lock.
//
// argv: doId expectedVersion productId qty userId shipmentGroup
// stdout: JSON {ok:bool, shippedQty?:float, errorCode?:string}

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Delivery\ShipmentService;

Config::load();

$doId = (int) ($argv[1] ?? 0);
$expectedVersion = (int) ($argv[2] ?? 0);
$productId = (int) ($argv[3] ?? 0);
$qty = (float) ($argv[4] ?? 0);
$userId = (int) ($argv[5] ?? 0);
$shipmentGroup = (string) ($argv[6] ?? 'MAIN');

try {
    $dto = Database::transaction(function (\PDO $pdo) use ($doId, $expectedVersion, $productId, $qty, $userId, $shipmentGroup) {
        $service = new ShipmentService($pdo);
        return $service->ship($doId, $expectedVersion, $shipmentGroup, [['productId' => $productId, 'actualQty' => $qty]], $userId, null);
    });
    fwrite(STDOUT, json_encode(['ok' => true, 'shippedQty' => $qty, 'shipmentId' => $dto['shipmentId']]));
} catch (ApiException $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'errorCode' => $e->errorCode]));
} catch (\Throwable $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'errorCode' => 'UNEXPECTED', 'message' => $e->getMessage()]));
}
