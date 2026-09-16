<?php

declare(strict_types=1);

/**
 * CLI migration runner — with a database safety gate (Phase 0.5, section 3).
 *
 * Applies every migrations/NNNN_*.php file (each returns the path to a .sql
 * file to run) that hasn't been applied yet, tracked in a small
 * schema_migrations bookkeeping table. Idempotent: re-running after a
 * migration is already applied reports it and stops cleanly — it never
 * blindly re-runs the 45 CREATE TABLE statements.
 *
 * Core apply logic lives in app/src/Setup/MigrationRunner.php, shared with
 * the optional _setup/index.php web setup wizard — both paths apply
 * exactly the same gate and the same migrations.
 *
 * Usage:
 *   php api/bin/migrate.php                 # interactive — asks for confirmation
 *   php api/bin/migrate.php --yes           # non-interactive (CI/automation)
 *   MIGRATE_YES=1 php api/bin/migrate.php   # same, via env var
 *
 * Safety gate (all of these must pass before anything is written):
 *   - APP_ENV must resolve (Config::load() already refuses APP_ENV=production
 *     outright — this script never runs against a "production" config at all).
 *   - Connection user/host/DB name are printed — NEVER the password.
 *   - If EXPECTED_DB_NAME is configured (required whenever APP_ENV is not
 *     'staging'), it must exactly equal DB_NAME, or this refuses to run.
 *   - If the target database already has business tables but no recorded
 *     migration, this refuses rather than guessing at the state.
 *   - Interactive runs require the operator to type the exact DB name to
 *     confirm before ANY new migration is applied. --yes/MIGRATE_YES=1 skips
 *     this for scripted/CI use (e.g. the disposable local test suite).
 *
 * This script never issues DROP DATABASE, DROP TABLE, or TRUNCATE. It has
 * no code path that can do so.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Setup\MigrationRunner;

Config::load();
$pdo = Database::pdo();

$appEnv = (string) Config::get('APP_ENV');
$dbHost = (string) Config::get('DB_HOST');
$dbName = (string) Config::get('DB_NAME');
$dbUser = (string) Config::get('DB_USER');
$expectedDbName = (string) Config::get('EXPECTED_DB_NAME', '');

fwrite(STDOUT, "=== Amor Factory migration runner ===\n");
fwrite(STDOUT, "APP_ENV=$appEnv  DB_HOST=$dbHost  DB_NAME=$dbName  DB_USER=$dbUser\n");
fwrite(STDOUT, "(password never printed)\n");
fwrite(STDOUT, "Server: {$pdo->getAttribute(PDO::ATTR_SERVER_VERSION)}\n");

// --- Safety gate 1: EXPECTED_DB_NAME must match DB_NAME -------------------
if ($appEnv !== 'staging' && $expectedDbName === '') {
    fwrite(STDERR, "REFUSED: APP_ENV=$appEnv requires EXPECTED_DB_NAME to be set in config"
        . " (see api/app/config/config.example.php) — refusing to run without it as a guard"
        . " against a misconfigured DB_NAME.\n");
    exit(1);
}
if ($expectedDbName !== '' && $expectedDbName !== $dbName) {
    fwrite(STDERR, "REFUSED: configured DB_NAME ('$dbName') does not match EXPECTED_DB_NAME"
        . " ('$expectedDbName'). This is the safety gate working as intended — fix"
        . " config.php, do not bypass this.\n");
    exit(1);
}

$runner = new MigrationRunner($pdo, $dbName);
$runner->ensureBookkeepingTable();

// --- Safety gate 2: never guess at an unknown non-empty database ----------
$state = $runner->checkKnownState();
if (!$state['ok']) {
    fwrite(STDERR, "REFUSED: {$state['reason']} Verify manually (and, if this really is a"
        . " fresh apply of 0001, either drop those tables by hand first or seed"
        . " schema_migrations with the correct row yourself) before re-running.\n");
    exit(1);
}

fwrite(STDOUT, "Database state: {$runner->businessTableCount()} business table(s), "
    . count($runner->appliedMigrations()) . " migration(s) recorded as applied.\n");

$pending = $runner->pendingMigrations();
if ($pending === []) {
    $total = count($runner->appliedMigrations());
    fwrite(STDOUT, "Nothing to do — all {$total} migration(s) already applied. Stopping cleanly.\n");
    exit(0);
}

fwrite(STDOUT, "Planned action: apply " . count($pending) . " migration(s) to '$dbName':\n");
foreach ($pending as $file) {
    fwrite(STDOUT, "  - " . basename($file) . "\n");
}

// --- Safety gate 3: explicit confirmation before any write -----------------
$bypassConfirm = in_array('--yes', $argv, true) || getenv('MIGRATE_YES') === '1';
if (!$bypassConfirm) {
    // function_exists guard: the posix extension is sometimes disabled on shared hosting.
    // If we can't positively confirm an interactive TTY, the safe default is to refuse
    // rather than silently apply — same outcome as a real non-interactive run.
    $isInteractive = function_exists('posix_isatty') && posix_isatty(STDIN);
    if (!$isInteractive) {
        fwrite(STDERR, "REFUSED: not running interactively (or unable to confirm a TTY — the posix"
            . " extension may be unavailable) and --yes/MIGRATE_YES=1 was not given."
            . " Re-run with --yes if this is an intentional scripted/CI apply.\n");
        exit(1);
    }
    fwrite(STDOUT, "\nType the database name ('$dbName') exactly to confirm, or anything else to abort: ");
    $typed = trim((string) fgets(STDIN));
    if ($typed !== $dbName) {
        fwrite(STDERR, "Aborted — confirmation did not match. Nothing was applied.\n");
        exit(1);
    }
}

// --- Apply ------------------------------------------------------------------
try {
    $result = $runner->applyPending();
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAIL  ' . $e->getMessage() . "\n");
    exit(1);
}
foreach ($result['applied'] as $name) {
    fwrite(STDOUT, "OK    {$name}\n");
}

fwrite(STDOUT, "Migrations complete. {$runner->businessTableCount()} business table(s) now present"
    . " (schema_migrations itself is infrastructure metadata, not counted here).\n");
