<?php

declare(strict_types=1);

// Child process for ALLOC-09 (concurrent "Alokasikan dari FG" race — task's
// own worked example: physical=10, Order A tries 8, Order B tries 8
// simultaneously, total allocation must never exceed 10). Opens its own DB
// connection (deliberately not sharing the parent's PDO) and attempts
// exactly one allocate() call inside a real transaction, mirroring
// Idempotency::handle()'s own Database::transaction() wrapper. The parent
// spawns two of these at once via proc_open (same pattern as
// _seq_allocate_child.php) to get real concurrent connections racing for
// the same stock_balance row lock.
//
// argv: itemId qty userId
// stdout: JSON {ok:bool, allocatedQty?:float, errorCode?:string}

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\SpecialOrder\SpecialOrderFgAllocationService;

Config::load();

$itemId = (int) ($argv[1] ?? 0);
$qty = (float) ($argv[2] ?? 0);
$userId = (int) ($argv[3] ?? 0);

try {
    $dto = Database::transaction(function (\PDO $pdo) use ($itemId, $qty, $userId) {
        $service = new SpecialOrderFgAllocationService($pdo);
        return $service->allocate($itemId, $qty, $userId, null);
    });
    fwrite(STDOUT, json_encode(['ok' => true, 'allocatedFromGeneralFg' => $dto['allocatedFromGeneralFg']]));
} catch (ApiException $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'errorCode' => $e->errorCode]));
} catch (\Throwable $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'errorCode' => 'UNEXPECTED', 'message' => $e->getMessage()]));
}
