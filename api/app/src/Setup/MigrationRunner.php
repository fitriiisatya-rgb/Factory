<?php

declare(strict_types=1);

namespace Amor\Api\Setup;

use PDO;

/**
 * Core migration logic shared by bin/migrate.php (CLI) and the optional
 * public/_setup/migrate.php web fallback (Phase 0.5, sections 3 & 8), so
 * both paths apply exactly the same safety gate and the same migrations —
 * never two slightly-different implementations of "apply the schema."
 *
 * This class only plans/applies migrations/NNNN_*.php files against
 * schema_migrations bookkeeping. It has no CLI-specific I/O (no prompts,
 * no STDOUT) and no HTTP-specific I/O (no HTML) — those are each caller's
 * job. It never issues DROP DATABASE, DROP TABLE, or TRUNCATE.
 */
final class MigrationRunner
{
    public function __construct(private PDO $pdo, private string $dbName)
    {
    }

    public function ensureBookkeepingTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(191) PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** @return string[] migration file basenames already recorded as applied */
    public function appliedMigrations(): array
    {
        return $this->pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function businessTableCount(): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name != 'schema_migrations'"
        );
        $stmt->execute([$this->dbName]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{ok:bool,reason:?string} ok=false means "refuse to run" —
     * database has tables but no migration record, an unknown/foreign state
     * this runner will not guess at.
     */
    public function checkKnownState(): array
    {
        if ($this->businessTableCount() > 0 && $this->appliedMigrations() === []) {
            return [
                'ok' => false,
                'reason' => "Database '{$this->dbName}' already has "
                    . $this->businessTableCount()
                    . " table(s) but schema_migrations has no record of any migration"
                    . ' having been applied here. Refusing to guess its state.',
            ];
        }
        return ['ok' => true, 'reason' => null];
    }

    /** @return string[] absolute paths of migrations/*.php files not yet applied, sorted */
    public function pendingMigrations(): array
    {
        $applied = $this->appliedMigrations();
        $files = glob(__DIR__ . '/../../migrations/*.php');
        sort($files);
        return array_values(array_filter($files, fn($f) => !in_array(basename($f), $applied, true)));
    }

    /**
     * Applies every pending migration in one pass. Caller must have already
     * confirmed this is wanted (CLI interactive prompt / web token+confirm) —
     * this method does not ask.
     *
     * @return array{applied:string[]} basenames of migrations actually applied
     */
    public function applyPending(): array
    {
        $appliedNow = [];
        foreach ($this->pendingMigrations() as $file) {
            $name = basename($file);
            $sqlPath = require $file;
            if (!is_file($sqlPath)) {
                throw new \RuntimeException("{$name}: SQL file not found at {$sqlPath}");
            }

            $sql = file_get_contents($sqlPath);
            foreach (self::splitSqlStatements($sql) as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }
                $this->pdo->exec($statement);
            }

            $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (?, UTC_TIMESTAMP())');
            $stmt->execute([$name]);
            $appliedNow[] = $name;
        }
        return ['applied' => $appliedNow];
    }

    /** @return string[] */
    public static function splitSqlStatements(string $sql): array
    {
        // Strips -- line comments, then splits on statement-terminating semicolons.
        // Adequate for this schema (plain DDL, no stored procedures/triggers/DELIMITER
        // blocks, and never a DROP/TRUNCATE statement — this class has no code path for either).
        $lines = explode("\n", $sql);
        $clean = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $clean[] = $line;
        }
        return explode(';', implode("\n", $clean));
    }
}
