<?php

declare(strict_types=1);

/**
 * CLI migration runner. Applies every migrations/NNNN_*.php file (each
 * returns the path to a .sql file to run) that hasn't been applied yet,
 * tracked in a small schema_migrations bookkeeping table.
 *
 * Usage (from repo root or anywhere):
 *   php api/bin/migrate.php
 *
 * This never touches production — Config::load() hard-refuses APP_ENV=production
 * (see src/Config.php), so this script cannot be pointed at a live database
 * by accident.
 */

require __DIR__ . '/../autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;

Config::load();
$pdo = Database::pdo();

fwrite(STDOUT, "Migrating against {$pdo->getAttribute(PDO::ATTR_SERVER_INFO)}\n");
fwrite(STDOUT, "APP_ENV=" . Config::get('APP_ENV') . " DB_NAME=" . Config::get('DB_NAME') . "\n");

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(191) PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$migrationDir = __DIR__ . '/../migrations';
$files = glob($migrationDir . '/*.php');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        fwrite(STDOUT, "SKIP  {$name} (already applied)\n");
        continue;
    }

    $sqlPath = require $file;
    if (!is_file($sqlPath)) {
        fwrite(STDERR, "FAIL  {$name}: SQL file not found at {$sqlPath}\n");
        exit(1);
    }

    fwrite(STDOUT, "APPLY {$name} <- {$sqlPath}\n");
    $sql = file_get_contents($sqlPath);
    foreach (splitSqlStatements($sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }

    $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (?, UTC_TIMESTAMP())');
    $stmt->execute([$name]);
    fwrite(STDOUT, "OK    {$name}\n");
}

fwrite(STDOUT, "Migrations complete.\n");

/** @return string[] */
function splitSqlStatements(string $sql): array
{
    // Strips -- line comments, then splits on statement-terminating semicolons.
    // Adequate for this schema (plain DDL, no stored procedures/triggers/DELIMITER blocks).
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
