<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Request;
use Amor\Api\Response;
use Amor\Api\System\BackupService;

/** Pengaturan → Sistem: database backups (ADMIN only). */
final class SystemController
{
    /** POST /api/admin/system/backups — take a backup now. */
    public static function backupNow(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN');
        try {
            $r = (new BackupService(Database::pdo()))->run();
        } catch (\RuntimeException $e) {
            throw new ApiException(409, 'BACKUP_FAILED', $e->getMessage());
        }
        Audit::write(Database::pdo(), $request->header('Idempotency-Key'), $userId, 'system.backup', 'backup', $r['file'], 'ok', null, null, $r);
        Response::json($r);
    }

    /** GET /api/admin/system/backup-download?name= — download one backup file. */
    public static function download(Request $request): void
    {
        $userId = Auth::requireRole('ADMIN');
        $name = (string) $request->query('name', '');
        $path = (new BackupService(Database::pdo()))->path($name);
        if ($path === null) {
            throw new ApiException(404, 'NOT_FOUND', 'File backup tidak ditemukan');
        }
        Audit::write(Database::pdo(), null, $userId, 'system.backup.download', 'backup', $name, 'ok', null, null, null);
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="amor-factory-' . $name . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }
}
