<?php

declare(strict_types=1);

/**
 * One-shot bootstrap for run-phase2-po.sh: imports the same Phase 1 master
 * data a real deployment would already have (8 divisions, all 472 katalog
 * products, the BAKERY CIKOLE alias group) via the real Phase1Importer —
 * not hand-crafted fixtures — plus two dedicated test-only stores (TSA/TSB)
 * so Phase2POTest.php's PO file fixtures have real, resolvable store
 * identities without depending on Phase 1's operator-provided 24-candidate
 * review list (SDRM in particular is deliberately NOT source-confirmed —
 * see Phase1Test.php's P1-10 — so it must never be assumed pre-resolved
 * here; TSA/TSB are this suite's own, unambiguous fixtures instead).
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Import\Phase1Importer;

Config::load();
$pdo = Database::pdo();

$importer = new Phase1Importer($pdo);
$divResult = $importer->importDivisions();
fwrite(STDOUT, "divisions: created={$divResult['created']} alreadyExisted={$divResult['alreadyExisted']}\n");

$prodResult = $importer->importSafeProducts();
fwrite(STDOUT, "products: imported={$prodResult['imported']} staged={$prodResult['staged']} alreadyMapped={$prodResult['alreadyMapped']}\n");
if ($prodResult['staged'] > 0) {
    fwrite(STDERR, "unexpected REVIEW/CONFLICT rows during bootstrap — the katalog import should be clean on a fresh DB\n");
    exit(1);
}

$storeResult = $importer->importStoreAliasGroups();
fwrite(STDOUT, "store alias groups: storesCreated={$storeResult['storesCreated']} aliasesCreated={$storeResult['aliasesCreated']}\n");

// Two dedicated test stores + aliases, purely for PO file fixtures — never
// reuses a real-world name/alias from the Phase 1 store review saga.
foreach ([['P2 TEST STORE A', 'TSA'], ['P2 TEST STORE B', 'TSB']] as [$name, $alias]) {
    $pdo->prepare(
        'INSERT INTO store (canonical_name, channel, active, version, created_at) VALUES (?, NULL, 1, 1, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE canonical_name = VALUES(canonical_name)'
    )->execute([$name]);
    $stmt = $pdo->prepare('SELECT store_id FROM store WHERE canonical_name = ?');
    $stmt->execute([$name]);
    $storeId = (int) $stmt->fetchColumn();
    $pdo->prepare(
        "INSERT INTO store_alias (store_id, raw_name, factory_hint, created_at) VALUES (?, ?, NULL, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE store_id = store_id"
    )->execute([$storeId, $alias]);
    fwrite(STDOUT, "test fixture store: {$name} (store_id={$storeId}, alias {$alias})\n");
}

fwrite(STDOUT, "Phase 2 bootstrap complete.\n");
