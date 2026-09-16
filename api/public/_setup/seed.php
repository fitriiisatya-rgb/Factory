<?php

declare(strict_types=1);

/**
 * OPTIONAL, TEMPORARY web fallback for bin/seed.php — ONLY for cPanel
 * accounts with no SSH/Terminal access (Phase 0.5, section 8).
 *
 * DELETE THIS ENTIRE public/_setup/ DIRECTORY once setup is done.
 * See migrate.php in this same directory for the full security notes
 * (SetupGuard, SETUP_TOKEN) — identical here.
 */

require __DIR__ . '/../../autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\SetupGuard;
use Amor\Api\Setup\Seeder;
use Amor\Api\Setup\WebRunnerPage;

Config::load();
SetupGuard::authorize();

$pdo = Database::pdo();
$dbName = (string) Config::get('DB_NAME');
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $planned = '<strong>Planned:</strong> seed minimum master data (2 factories, 7 roles, the synthetic'
        . ' NON-OUTLET / PERORANGAN store) into <code>' . htmlspecialchars($dbName) . '</code>.'
        . ' Idempotent — safe even if already seeded. No products, historical stores, PO, stock,'
        . ' invoices, or shipments are touched.';
    WebRunnerPage::confirmForm('Seed minimum master data', $token, $planned);
    exit;
}

if (($_POST['confirm'] ?? '') !== 'CONFIRM') {
    WebRunnerPage::confirmForm('Seed minimum master data', $token,
        'Type CONFIRM exactly (case-sensitive) to proceed.', 'Confirmation text did not match — nothing was seeded.');
    exit;
}

try {
    $result = (new Seeder($pdo))->run();
    WebRunnerPage::result('Seed', true,
        '<p>factories: ' . $result['factories'] . '</p>'
        . '<p>roles: ' . $result['roles'] . '</p>'
        . '<p>synthetic store: ' . htmlspecialchars($result['syntheticStore']) . '</p>');
} catch (\Throwable $e) {
    WebRunnerPage::result('Seed', false, '<p>' . htmlspecialchars($e->getMessage()) . '</p>');
}
