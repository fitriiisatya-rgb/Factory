<?php

declare(strict_types=1);

/**
 * Seeds the MINIMUM master data Phase 0 needs — nothing transactional,
 * nothing from legacy data (that's a later-phase ETL, explicitly out of
 * scope here). Safe to re-run: every insert is upsert-on-conflict.
 *
 * Usage: php api/bin/seed.php
 */

require __DIR__ . '/../autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;

Config::load();
$pdo = Database::pdo();

fwrite(STDOUT, "Seeding minimum master data into " . Config::get('DB_NAME') . "...\n");

// Factories
$factories = [
    ['KTG', 'Karangtengah'],
    ['CBD', 'Cibadak'],
];
$stmt = $pdo->prepare('INSERT INTO factory (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
foreach ($factories as [$code, $name]) {
    $stmt->execute([$code, $name]);
}
fwrite(STDOUT, "  factories: " . count($factories) . " seeded\n");

// Roles (docs/php-api-contract-v1.md §14)
$roles = [
    ['ADMIN', 'Administrator'],
    ['PPIC', 'PPIC'],
    ['PRODUCTION', 'Production'],
    ['FG_PACKING', 'FG Packing'],
    ['DELIVERY', 'Delivery'],
    ['FINANCE', 'Finance'],
    ['MANAGEMENT_VIEWER', 'Management Viewer'],
];
$stmt = $pdo->prepare('INSERT INTO roles (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
foreach ($roles as [$code, $name]) {
    $stmt->execute([$code, $name]);
}
fwrite(STDOUT, "  roles: " . count($roles) . " seeded\n");

// Synthetic non-outlet store (docs/mysql-schema-v1.md §5.8.1, LOCKED — review point 2)
$stmt = $pdo->prepare(
    'INSERT INTO store (canonical_name, channel, active, version, created_at)
     VALUES (?, NULL, 1, 1, UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE active = 1'
);
$stmt->execute(['NON-OUTLET / PERORANGAN']);
fwrite(STDOUT, "  synthetic store: NON-OUTLET / PERORANGAN seeded\n");

fwrite(STDOUT, "Seed complete. No transactional/legacy data was touched.\n");
