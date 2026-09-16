<?php

declare(strict_types=1);

namespace Amor\Api\Import;

use PDO;

/**
 * Phase 1 fast-track import logic: divisions, the KATALOG_BAWAAN product
 * catalog, and the one confirmed store alias group from the frontend
 * source (see LegacyCatalogSource / dist/tools/extract-legacy-source.php).
 *
 * Every import method is idempotent — safe to call repeatedly, never
 * duplicates a row, never touches a row it didn't create itself when that
 * row is ambiguous. No fuzzy auto-merge anywhere: a name or code collision
 * against something this importer did not itself just create is always
 * REVIEW or CONFLICT, never silently resolved.
 *
 * Never touches PO/production/FG/DO/shipment/stock/invoice/payment/return/
 * sale tables — this class has no code path that writes to any of them.
 */
final class Phase1Importer
{
    public function __construct(private PDO $pdo)
    {
    }

    // ------------------------------------------------------------------
    // Divisions
    // ------------------------------------------------------------------

    /** @return array<int,array{name:string,factory:string,is_verification:bool,state:string}> */
    public function previewDivisions(): array
    {
        $existing = $this->pdo->query('SELECT name FROM division')->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach (LegacyCatalogSource::divisions() as $d) {
            $out[] = [
                'name' => $d['name'],
                'factory' => $d['factory'],
                'is_verification' => $d['is_verification'],
                'state' => in_array($d['name'], $existing, true) ? 'exists' : 'new',
            ];
        }
        return $out;
    }

    /** @return array{created:int,alreadyExisted:int} */
    public function importDivisions(): array
    {
        $factoryIds = $this->factoryIdsByName();
        $created = 0;
        $alreadyExisted = 0;

        $insert = $this->pdo->prepare(
            'INSERT INTO division (name, factory_id, is_verification) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE factory_id = VALUES(factory_id), is_verification = VALUES(is_verification)'
        );
        $countBefore = $this->pdo->prepare('SELECT COUNT(*) FROM division WHERE name = ?');

        foreach (LegacyCatalogSource::divisions() as $d) {
            if (!isset($factoryIds[$d['factory']])) {
                throw new \RuntimeException("Unknown factory '{$d['factory']}' for division '{$d['name']}' — factories must be seeded first.");
            }
            $countBefore->execute([$d['name']]);
            $existed = ((int) $countBefore->fetchColumn()) > 0;

            $insert->execute([$d['name'], $factoryIds[$d['factory']], $d['is_verification'] ? 1 : 0]);
            if ($existed) {
                $alreadyExisted++;
            } else {
                $created++;
            }
        }

        return ['created' => $created, 'alreadyExisted' => $alreadyExisted];
    }

    /** @return array<string,int> factory name -> factory_id */
    private function factoryIdsByName(): array
    {
        $rows = $this->pdo->query('SELECT factory_id, name FROM factory')->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[$r['name']] = (int) $r['factory_id'];
        }
        return $map;
    }

    // ------------------------------------------------------------------
    // Products (KATALOG_BAWAAN)
    // ------------------------------------------------------------------

    /**
     * @return array{safe:array,review:array,conflict:array,alreadyMapped:int}
     */
    public function previewProducts(): array
    {
        $safe = [];
        $review = [];
        $conflict = [];
        $alreadyMapped = 0;

        foreach (LegacyCatalogSource::products() as $row) {
            $classification = $this->classifyProductRow($row);
            switch ($classification['type']) {
                case 'already_mapped':
                    $alreadyMapped++;
                    break;
                case 'safe':
                    $safe[] = $classification;
                    break;
                case 'review':
                    $review[] = $classification;
                    break;
                case 'conflict':
                    $conflict[] = $classification;
                    break;
            }
        }

        return ['safe' => $safe, 'review' => $review, 'conflict' => $conflict, 'alreadyMapped' => $alreadyMapped];
    }

    /**
     * Classifies one catalog row against CURRENT database state.
     * @return array{type:string,row:array,reason:?string,existingProductId:?int}
     */
    private function classifyProductRow(array $row): array
    {
        $name = trim((string) $row['n']);
        $code = trim((string) $row['kd']);

        $stmt = $this->pdo->prepare('SELECT status, target_id FROM migration_product_map WHERE raw_name = ? AND raw_code = ?');
        $stmt->execute([$name, $code]);
        $mapRow = $stmt->fetch();
        if ($mapRow && $mapRow['status'] === 'mapped') {
            return ['type' => 'already_mapped', 'row' => $row, 'reason' => null, 'existingProductId' => (int) $mapRow['target_id']];
        }
        if ($mapRow && $mapRow['status'] !== 'mapped') {
            // Already triaged as review/conflict on a prior run — report its current
            // status rather than re-deriving, so re-running the import never flip-flops
            // a row a human may already be looking at.
            return ['type' => $mapRow['status'], 'row' => $row, 'reason' => 'Previously staged, still awaiting admin resolution.', 'existingProductId' => null];
        }

        // Code collision: this exact legacy_code already belongs to a product with a
        // DIFFERENT name than this row's name.
        $stmt = $this->pdo->prepare(
            'SELECT p.product_id, p.name FROM product_legacy_code plc
             INNER JOIN product p ON p.product_id = plc.product_id
             WHERE plc.legacy_code = ?'
        );
        $stmt->execute([$code]);
        $codeOwner = $stmt->fetch();
        if ($codeOwner && $codeOwner['name'] !== $name) {
            return [
                'type' => 'conflict', 'row' => $row,
                'reason' => "legacy_code '{$code}' already belongs to product '{$codeOwner['name']}' (product_id={$codeOwner['product_id']})",
                'existingProductId' => (int) $codeOwner['product_id'],
            ];
        }
        if ($codeOwner && $codeOwner['name'] === $name) {
            // Same code, same name — this product+code pair already exists (e.g. a
            // prior import run without a migration_product_map row for some reason).
            // Safe to just record the mapping, not create a duplicate product.
            return ['type' => 'safe', 'row' => $row, 'reason' => 'Matches existing product+legacy_code exactly.', 'existingProductId' => (int) $codeOwner['product_id']];
        }

        // Name collision: a product with this exact name exists, but under a
        // different legacy_code than this row's code.
        $stmt = $this->pdo->prepare('SELECT product_id FROM product WHERE name = ?');
        $stmt->execute([$name]);
        $nameOwner = $stmt->fetch();
        if ($nameOwner) {
            return [
                'type' => 'review', 'row' => $row,
                'reason' => "product name '{$name}' already exists (product_id={$nameOwner['product_id']}) under a different legacy_code — confirm whether this is an additional historical code for the same product.",
                'existingProductId' => (int) $nameOwner['product_id'],
            ];
        }

        return ['type' => 'safe', 'row' => $row, 'reason' => null, 'existingProductId' => null];
    }

    /**
     * Imports every currently-SAFE row (per previewProducts()) in one
     * transaction per row, and stages REVIEW/CONFLICT rows into
     * migration_product_map for admin resolution — never auto-resolves those.
     *
     * @return array{imported:int,staged:int,alreadyMapped:int}
     */
    public function importSafeProducts(): array
    {
        $preview = $this->previewProducts();
        $imported = 0;

        foreach ($preview['safe'] as $item) {
            $this->pdo->beginTransaction();
            try {
                $productId = $item['existingProductId'] ?? $this->createProductFromRow($item['row']);
                $this->ensureLegacyCode($productId, trim((string) $item['row']['kd']));
                $this->upsertMigrationMap(
                    'product',
                    trim((string) $item['row']['n']),
                    trim((string) $item['row']['kd']),
                    'KATALOG_BAWAAN',
                    'mapped',
                    $productId,
                    'system:katalog_bawaan_import'
                );
                $this->pdo->commit();
                $imported++;
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        $staged = 0;
        foreach ([...$preview['review'], ...$preview['conflict']] as $item) {
            // Only stage a migration_product_map row if one doesn't already exist for
            // this raw pair (classifyProductRow already returns early for existing
            // non-mapped rows, so this is only reached for brand-new review/conflict
            // findings this run).
            $stmt = $this->pdo->prepare('SELECT id FROM migration_product_map WHERE raw_name = ? AND raw_code = ?');
            $stmt->execute([trim((string) $item['row']['n']), trim((string) $item['row']['kd'])]);
            if ($stmt->fetch()) {
                continue;
            }
            $this->upsertMigrationMap(
                'product',
                trim((string) $item['row']['n']),
                trim((string) $item['row']['kd']),
                'KATALOG_BAWAAN',
                $item['type'], // 'review' rows are stored as 'unresolved' — see upsertMigrationMap's mapping
                null,
                null,
                $item['reason']
            );
            $staged++;
        }

        return ['imported' => $imported, 'staged' => $staged, 'alreadyMapped' => $preview['alreadyMapped']];
    }

    private function createProductFromRow(array $row): int
    {
        $name = trim((string) $row['n']);
        $kategori = trim((string) ($row['k'] ?? '')) ?: null;
        $harga = (float) ($row['h'] ?? 0);
        $divisionName = trim((string) ($row['d'] ?? ''));

        $divisionId = null;
        if ($divisionName !== '') {
            $stmt = $this->pdo->prepare('SELECT division_id FROM division WHERE name = ?');
            $stmt->execute([$divisionName]);
            $divisionId = $stmt->fetchColumn();
            $divisionId = $divisionId === false ? null : (int) $divisionId;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO product (name, kategori, division_id, hpp, harga, aktif, version, created_at)
             VALUES (?, ?, ?, 0, ?, 1, 1, UTC_TIMESTAMP())'
        );
        // hpp is deliberately 0: KATALOG_BAWAAN only carries a selling price ("h"),
        // never a cost/HPP figure — see the Phase 1 report on this. Never invented here.
        $stmt->execute([$name, $kategori, $divisionId, $harga]);
        return (int) $this->pdo->lastInsertId();
    }

    private function ensureLegacyCode(int $productId, string $code): void
    {
        if ($code === '') {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM product_legacy_code WHERE product_id = ? AND legacy_code = ?');
        $stmt->execute([$productId, $code]);
        if ($stmt->fetch()) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_legacy_code (product_id, legacy_code, first_seen_at, created_at) VALUES (?, ?, NULL, UTC_TIMESTAMP())'
        );
        $stmt->execute([$productId, $code]);
    }

    // ------------------------------------------------------------------
    // Store alias groups
    // ------------------------------------------------------------------

    /** @return array */
    public function previewStoreAliasGroups(): array
    {
        $out = [];
        foreach (LegacyCatalogSource::storeAliasGroups() as $group) {
            $stmt = $this->pdo->prepare('SELECT store_id FROM store WHERE canonical_name = ?');
            $stmt->execute([$group['canonical']]);
            $storeId = $stmt->fetchColumn();

            $aliasStates = [];
            foreach ($group['aliases'] as $alias) {
                if ($alias === $group['canonical']) {
                    continue; // canonical name needs no alias row pointing at itself
                }
                $stmt = $this->pdo->prepare('SELECT store_id FROM store_alias WHERE raw_name = ?');
                $stmt->execute([$alias]);
                $existingStoreId = $stmt->fetchColumn();
                if ($existingStoreId === false) {
                    $aliasStates[] = ['alias' => $alias, 'state' => 'new'];
                } elseif ($storeId !== false && (int) $existingStoreId === (int) $storeId) {
                    $aliasStates[] = ['alias' => $alias, 'state' => 'already_mapped'];
                } else {
                    $aliasStates[] = ['alias' => $alias, 'state' => 'conflict'];
                }
            }

            $out[] = [
                'canonical' => $group['canonical'],
                'storeState' => $storeId === false ? 'new' : 'exists',
                'aliases' => $aliasStates,
            ];
        }
        return $out;
    }

    /** @return array{storesCreated:int,aliasesCreated:int,aliasesSkippedConflict:int} */
    public function importStoreAliasGroups(): array
    {
        $storesCreated = 0;
        $aliasesCreated = 0;
        $aliasesSkippedConflict = 0;

        foreach (LegacyCatalogSource::storeAliasGroups() as $group) {
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare('SELECT store_id FROM store WHERE canonical_name = ?');
                $stmt->execute([$group['canonical']]);
                $storeId = $stmt->fetchColumn();

                if ($storeId === false) {
                    $stmt = $this->pdo->prepare(
                        'INSERT INTO store (canonical_name, channel, active, version, created_at) VALUES (?, NULL, 1, 1, UTC_TIMESTAMP())'
                    );
                    $stmt->execute([$group['canonical']]);
                    $storeId = (int) $this->pdo->lastInsertId();
                    $storesCreated++;
                } else {
                    $storeId = (int) $storeId;
                }

                foreach ($group['aliases'] as $alias) {
                    if ($alias === $group['canonical']) {
                        continue;
                    }
                    $stmt = $this->pdo->prepare('SELECT store_id FROM store_alias WHERE raw_name = ?');
                    $stmt->execute([$alias]);
                    $existingStoreId = $stmt->fetchColumn();

                    if ($existingStoreId !== false && (int) $existingStoreId !== $storeId) {
                        // Alias already resolves to a DIFFERENT store — never overwritten.
                        $aliasesSkippedConflict++;
                        continue;
                    }
                    if ($existingStoreId !== false) {
                        continue; // already correctly mapped
                    }

                    $stmt = $this->pdo->prepare(
                        'INSERT INTO store_alias (store_id, raw_name, factory_hint, created_at) VALUES (?, ?, NULL, UTC_TIMESTAMP())'
                    );
                    $stmt->execute([$storeId, $alias]);
                    $aliasesCreated++;

                    // raw_code='' (not NULL) for stores: migration_store_map's UNIQUE KEY is
                    // (raw_name, raw_code), and MySQL/MariaDB treats multiple NULLs in a unique
                    // index as non-conflicting (confirmed empirically) — a NULL here would let
                    // ON DUPLICATE KEY UPDATE never trigger, silently growing duplicate rows on
                    // every import rerun. Empty string is comparable, so the upsert works.
                    $this->upsertMigrationMap('store', $alias, '', 'bootstrapTokoCanonicalDikenal', 'mapped', $storeId, 'system:legacy_alias_import');
                }

                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        return ['storesCreated' => $storesCreated, 'aliasesCreated' => $aliasesCreated, 'aliasesSkippedConflict' => $aliasesSkippedConflict];
    }

    // ------------------------------------------------------------------
    // Shared
    // ------------------------------------------------------------------

    private function upsertMigrationMap(
        string $kind, // 'product' | 'store'
        string $rawName,
        ?string $rawCode,
        string $sourceTable,
        string $classification, // 'mapped' | 'review' | 'conflict'
        ?int $targetId,
        ?string $resolvedBy,
        ?string $notes = null
    ): void {
        $table = $kind === 'product' ? 'migration_product_map' : 'migration_store_map';
        // The DB enum only knows 'mapped'/'unresolved'/'conflict' — this importer's
        // internal 'review' classification is stored as 'unresolved' (a human still
        // needs to look at it; 'review' vs 'unresolved' is a code-level distinction
        // for why it's unresolved, not a separate DB state).
        $status = $classification === 'mapped' ? 'mapped' : ($classification === 'conflict' ? 'conflict' : 'unresolved');
        $resolvedAtSql = $status === 'mapped' ? 'UTC_TIMESTAMP()' : 'NULL';

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$table} (raw_name, raw_code, source_table, occurrence_count, target_id, status, resolved_by, resolved_at, notes, created_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, {$resolvedAtSql}, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                occurrence_count = occurrence_count + 1,
                target_id = COALESCE(VALUES(target_id), target_id)"
        );
        $stmt->execute([$rawName, $rawCode, $sourceTable, $targetId, $status, $resolvedBy, $notes]);
    }
}
