<?php

declare(strict_types=1);

// Child process for ALLOC-GLOBAL-18/19 (real concurrency race between a
// downward Regular FG correction and a special-order FG allocation, and
// optionally a Regular PO shipment, all racing for the same physical
// stock). Opens its own DB connection (deliberately not sharing the
// parent's PDO) and attempts exactly one FgService::submit() call inside
// a real transaction, mirroring Idempotency::handle()'s own
// Database::transaction() wrapper. The parent spawns this alongside
// _fg_allocate_race_child.php / _regular_ship_race_child.php via
// proc_open (same pattern as _seq_allocate_child.php) to get real
// concurrent connections racing for the same stock_balance row lock.
//
// argv: fgBatchId expectedVersion userId
// stdout: JSON {ok:bool, postings?:array, errorCode?:string}

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Fg\FgService;

Config::load();

$batchId = (int) ($argv[1] ?? 0);
$expectedVersion = (int) ($argv[2] ?? 0);
$userId = (int) ($argv[3] ?? 0);

try {
    $dto = Database::transaction(function (\PDO $pdo) use ($batchId, $expectedVersion, $userId) {
        $service = new FgService($pdo);
        return $service->submit($batchId, $expectedVersion, $userId, null);
    });
    fwrite(STDOUT, json_encode(['ok' => true, 'postings' => $dto['submitPostings']]));
} catch (ApiException $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'errorCode' => $e->errorCode]));
} catch (\Throwable $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'errorCode' => 'UNEXPECTED', 'message' => $e->getMessage()]));
}
