<?php

declare(strict_types=1);

namespace Amor\Api\System;

use PDO;

/**
 * Daily database backup without shell access (cPanel hosting often has
 * exec()/mysqldump disabled): every table's CREATE TABLE plus its rows as
 * INSERTs, gzip-compressed, into api/app/storage/backups/. The newest
 * KEEP files are kept. Restore = import the .sql.gz in phpMyAdmin.
 *
 * Runs (1) from cron: php api/bin/backup.php, (2) by itself once a day
 * after an Admin page view (maybeDaily), (3) on demand from Pengaturan →
 * Sistem. A lock file stops two runs at once.
 */
final class BackupService
{
    public const KEEP = 14;
    private const DAILY_AFTER_HOURS = 20;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{file:string,bytes:int,tables:int,rows:int,seconds:float} */
    public function run(): array
    {
        $dir = Storage::dir('backups');
        $lock = fopen($dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Backup sedang berjalan.');
        }
        @set_time_limit(600);
        $t0 = microtime(true);
        $name = 'backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql.gz';
        $tmp = $dir . '/.' . $name . '.part';
        $gz = gzopen($tmp, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Folder backup tidak bisa ditulis.');
        }
        $db = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        gzwrite($gz, "-- Amor Factory backup of `{$db}` at " . date('c') . "\n-- Restore: phpMyAdmin → Import this file.\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $rowsTotal = 0;
        foreach ($tables as [$table]) {
            $create = $this->pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n");
            $stmt = $this->pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
            $batch = [];
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                $batch[] = '(' . implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $this->pdo->quote((string) $v), $row)) . ')';
                $rowsTotal++;
                if (count($batch) >= 200) {
                    gzwrite($gz, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                gzwrite($gz, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- end of backup\n");
        gzclose($gz);
        rename($tmp, $dir . '/' . $name);
        $this->prune($dir);
        flock($lock, LOCK_UN);
        fclose($lock);
        return ['file' => $name, 'bytes' => (int) filesize($dir . '/' . $name), 'tables' => count($tables), 'rows' => $rowsTotal, 'seconds' => round(microtime(true) - $t0, 1)];
    }

    /** Runs a backup when the newest one is older than ~a day. Never throws. */
    public function maybeDaily(): ?array
    {
        try {
            $latest = $this->list()[0] ?? null;
            if ($latest !== null && time() - $latest['mtime'] < self::DAILY_AFTER_HOURS * 3600) {
                return null;
            }
            return $this->run();
        } catch (\Throwable $e) {
            ErrorLog::record('backup', $e->getMessage(), $e->getFile(), $e->getLine());
            return null;
        }
    }

    /** @return array<int,array{name:string,bytes:int,mtime:int}> newest first */
    public function list(): array
    {
        $out = [];
        foreach (glob(Storage::dir('backups') . '/backup-*.sql.gz') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'bytes' => (int) filesize($f), 'mtime' => (int) filemtime($f)];
        }
        usort($out, static fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    /** Absolute path of one backup file, or null (only our own file names). */
    public function path(string $name): ?string
    {
        if (!preg_match('/^backup-\d{8}-\d{6}-[0-9a-f]{8}\.sql\.gz$/', $name)) {
            return null;
        }
        $p = Storage::dir('backups') . '/' . $name;
        return is_file($p) ? $p : null;
    }

    private function prune(string $dir): void
    {
        foreach (array_slice($this->list(), self::KEEP) as $old) {
            @unlink($dir . '/' . $old['name']);
        }
    }
}
