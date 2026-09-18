<?php

declare(strict_types=1);

namespace Amor\Api\Setup;

use PDO;

/**
 * Minimum master-data seed, shared by bin/seed.php (CLI) and the optional
 * public/_setup/seed.php web fallback. Every insert is upsert-on-conflict,
 * so running this twice never duplicates a row (Phase 0.5, section 9).
 *
 * Deliberately does NOT seed products, historical stores, PO, stock,
 * invoices, or shipments — none of that is in scope until the Phase 1
 * identity-migration work.
 */
final class Seeder
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{factories:int,roles:int,syntheticStore:string} */
    public function run(): array
    {
        $factories = [
            ['KTG', 'Karangtengah'],
            ['CBD', 'Cibadak'],
        ];
        $stmt = $this->pdo->prepare('INSERT INTO factory (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
        foreach ($factories as [$code, $name]) {
            $stmt->execute([$code, $name]);
        }

        // Roles (docs/php-api-contract-v1.md §14)
        $roles = [
            ['ADMIN', 'Administrator'],
            ['PPIC', 'PPIC'],
            ['PRODUCTION', 'Production'],
            ['FG_PACKING', 'FG Packing'],
            ['DELIVERY', 'Delivery'],
            ['FINANCE', 'Finance'],
            ['MANAGEMENT_VIEWER', 'Management Viewer'],
            ['DRIVER', 'Driver'],
        ];
        $stmt = $this->pdo->prepare('INSERT INTO roles (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
        foreach ($roles as [$code, $name]) {
            $stmt->execute([$code, $name]);
        }

        // Synthetic non-outlet store (docs/mysql-schema-v1.md §5.8.1, LOCKED — review point 2)
        $stmt = $this->pdo->prepare(
            'INSERT INTO store (canonical_name, channel, active, version, created_at)
             VALUES (?, NULL, 1, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE active = 1'
        );
        $stmt->execute(['NON-OUTLET / PERORANGAN']);

        return [
            'factories' => count($factories),
            'roles' => count($roles),
            'syntheticStore' => 'NON-OUTLET / PERORANGAN',
        ];
    }
}
