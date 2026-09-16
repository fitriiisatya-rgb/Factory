<?php

declare(strict_types=1);

namespace Amor\Api;

use PDO;
use PDOException;

/**
 * Single PDO connection per request. Prepared statements only, real
 * placeholders (no emulation), utf8mb4, exceptions on error.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static ?PDO $migrationPdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = Config::get('DB_HOST');
        $port = Config::get('DB_PORT');
        $name = Config::get('DB_NAME');
        $user = Config::get('DB_USER');
        $pass = Config::get('DB_PASS');
        $socket = Config::get('DB_SOCKET');

        // DB_SOCKET is a test/local-only escape hatch (disposable MariaDB started with
        // --skip-networking has no TCP host:port at all). Real staging always uses
        // DB_HOST/DB_PORT — see config/config.example.php.
        $dsn = $socket
            ? "mysql:unix_socket={$socket};dbname={$name};charset=utf8mb4"
            : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        } catch (PDOException $e) {
            // Never leak DSN/credentials into the response — caller (ErrorHandler)
            // decides what the client actually sees.
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    /**
     * A SEPARATE, DDL-capable connection using MIGRATION_DB_* config keys —
     * never DB_USER/DB_PASS (the normal runtime connection above). Used
     * exclusively by api/_upgrade/, so the day-to-day application never
     * needs (and never holds) a connection with schema-altering privileges.
     *
     * @throws MigrationCredentialsMissing if MIGRATION_DB_USER/MIGRATION_DB_PASS
     *         aren't configured — callers (the upgrade wizard) catch this and
     *         show a friendly one-time-setup message, never a raw PHP error.
     */
    public static function migrationPdo(): PDO
    {
        if (self::$migrationPdo !== null) {
            return self::$migrationPdo;
        }

        $user = Config::get('MIGRATION_DB_USER', '');
        $pass = Config::get('MIGRATION_DB_PASS');
        if ($user === '' || $user === null || $pass === null || $pass === '') {
            throw new MigrationCredentialsMissing(
                'MIGRATION_DB_USER / MIGRATION_DB_PASS are not configured in app/config/config.php.'
            );
        }

        $host = Config::get('MIGRATION_DB_HOST', Config::get('DB_HOST'));
        $port = Config::get('MIGRATION_DB_PORT', Config::get('DB_PORT'));
        $name = Config::get('MIGRATION_DB_NAME', Config::get('DB_NAME'));
        $socket = Config::get('MIGRATION_DB_SOCKET', Config::get('DB_SOCKET'));

        $dsn = $socket
            ? "mysql:unix_socket={$socket};dbname={$name};charset=utf8mb4"
            : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        try {
            self::$migrationPdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        } catch (PDOException $e) {
            // Never leak DSN/credentials — same discipline as pdo() above.
            throw new \RuntimeException('Migration database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return self::$migrationPdo;
    }

    /**
     * Runs $fn inside a transaction. Commits on normal return, rolls back
     * and re-throws on any exception. Never leaves a half-applied write.
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** For tests only — forces a fresh connection on next pdo()/migrationPdo() call. */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$migrationPdo = null;
    }
}
