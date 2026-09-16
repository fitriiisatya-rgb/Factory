<?php

declare(strict_types=1);

/**
 * Seeds the MINIMUM master data Phase 0/0.5 needs — nothing transactional,
 * nothing from legacy data (that's Phase 1's ETL, explicitly out of scope
 * here). Safe to re-run: every insert is upsert-on-conflict (see
 * src/Setup/Seeder.php, shared with the optional public/_setup/seed.php
 * web fallback).
 *
 * Usage: php api/bin/seed.php
 */

require __DIR__ . '/../autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Setup\Seeder;

Config::load();
$pdo = Database::pdo();

fwrite(STDOUT, "Seeding minimum master data into " . Config::get('DB_NAME') . "...\n");

$result = (new Seeder($pdo))->run();

fwrite(STDOUT, "  factories: {$result['factories']} seeded\n");
fwrite(STDOUT, "  roles: {$result['roles']} seeded\n");
fwrite(STDOUT, "  synthetic store: {$result['syntheticStore']} seeded\n");
fwrite(STDOUT, "Seed complete. No transactional/legacy data was touched.\n");
