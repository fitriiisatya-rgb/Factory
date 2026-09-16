<?php

declare(strict_types=1);

/**
 * OPTIONAL, TEMPORARY web fallback for bin/migrate.php — ONLY for cPanel
 * accounts with no SSH/Terminal access (Phase 0.5, section 8).
 *
 * DELETE THIS ENTIRE public/_setup/ DIRECTORY once setup is done. It must
 * not live permanently on any reachable host. Prefer the CLI
 * (api/bin/migrate.php) whenever SSH/Terminal is available.
 *
 * Requires a SETUP_TOKEN configured in config/config.php (never committed)
 * — this refuses everything if SETUP_TOKEN is unset. See SetupGuard.
 */

require __DIR__ . '/../../autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\SetupGuard;
use Amor\Api\Setup\MigrationRunner;
use Amor\Api\Setup\WebRunnerPage;

Config::load();
SetupGuard::authorize();

$pdo = Database::pdo();
$dbName = (string) Config::get('DB_NAME');
$runner = new MigrationRunner($pdo, $dbName);
$runner->ensureBookkeepingTable();

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

$state = $runner->checkKnownState();
if (!$state['ok']) {
    WebRunnerPage::result('Migration refused', false, '<p>' . htmlspecialchars($state['reason']) . '</p>');
    exit;
}

$pending = $runner->pendingMigrations();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($pending === []) {
        WebRunnerPage::result('Migration', true, '<p>Nothing to do — all migrations already applied to <code>'
            . htmlspecialchars($dbName) . '</code>. ' . $runner->businessTableCount() . ' business table(s) present.</p>');
        exit;
    }
    $planned = '<strong>Planned:</strong> apply ' . count($pending) . ' migration(s) to database <code>'
        . htmlspecialchars($dbName) . '</code>: '
        . htmlspecialchars(implode(', ', array_map('basename', $pending)))
        . '. Current state: ' . $runner->businessTableCount() . ' business table(s).';
    WebRunnerPage::confirmForm('Apply schema migration', $token, $planned);
    exit;
}

if (($_POST['confirm'] ?? '') !== 'CONFIRM') {
    WebRunnerPage::confirmForm('Apply schema migration', $token,
        'Type CONFIRM exactly (case-sensitive) to proceed.', 'Confirmation text did not match — nothing was applied.');
    exit;
}

try {
    $result = $runner->applyPending();
    $finalCount = $runner->businessTableCount();
    WebRunnerPage::result('Migration', true,
        '<p>Applied: ' . htmlspecialchars(implode(', ', $result['applied'])) . '</p>'
        . '<p>' . $finalCount . ' business table(s) now present in <code>' . htmlspecialchars($dbName) . '</code>.</p>');
} catch (\Throwable $e) {
    WebRunnerPage::result('Migration', false, '<p>' . htmlspecialchars($e->getMessage()) . '</p>');
}
