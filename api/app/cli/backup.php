<?php

declare(strict_types=1);

/**
 * Database backup for a cPanel cron job, e.g. daily at 02:00:
 *   php /home/<user>/public_html/factory/api/app/cli/backup.php
 * Writes api/app/storage/backups/backup-*.sql.gz and keeps the newest 14.
 */

require __DIR__ . '/../autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\System\BackupService;
use Amor\Api\System\ErrorLog;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
Config::load();
try {
    $r = (new BackupService(Database::pdo()))->run();
    fwrite(STDOUT, sprintf("OK %s (%d tabel, %d baris, %.1f MB, %.1f detik)\n", $r['file'], $r['tables'], $r['rows'], $r['bytes'] / 1048576, $r['seconds']));
} catch (\Throwable $e) {
    ErrorLog::record('backup', $e->getMessage(), $e->getFile(), $e->getLine());
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
