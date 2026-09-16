<?php

declare(strict_types=1);

// Child process for P0-17 (concurrent document_sequence allocation). Opens its
// own DB connection (deliberately not sharing the parent's PDO) and allocates
// exactly one number, then prints it. The parent spawns many of these at once
// via proc_open to get real concurrent connections hitting the same row.

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Services\DocumentSequenceService;

Config::load();
$pdo = Database::pdo();

$n = DocumentSequenceService::allocate($pdo, 'test_seq', 2026, 1);
fwrite(STDOUT, (string) $n);
