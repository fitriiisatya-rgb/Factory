<?php

declare(strict_types=1);

namespace Amor\Api\Import;

use PDO;

/**
 * Resolves a PO file row's raw product/store identity against the Phase 1
 * master (product/store + their confirmed aliases) — NEVER by fuzzy
 * similarity, matching the task's explicit instruction (the legacy
 * frontend's cocokProduk() fuzzy "suggest" tier is deliberately NOT ported;
 * an unresolved row here always stops at "unresolved", never a guess).
 *
 * Product resolution priority:
 *   1. product_legacy_code exact code match (Phase 1 katalog import) — only
 *      when the code resolves to exactly ONE product_id; a code claimed by
 *      more than one product is a genuine data problem, never guessed at.
 *   2. product_alias exact raw_name match (an admin-confirmed alias).
 *   3. product.name exact match, normalized the same way the audited
 *      legacy parser normalizes names (trim + uppercase + collapse
 *      whitespace — see PoFileParser::up()).
 *   4. migration_product_map WHERE status='mapped' for this exact raw
 *      (name, code) pair — this is what makes a PO row someone already
 *      resolved via the existing admin migration-review endpoint
 *      (POST /api/admin/migration/products/{id}/resolve) resolve
 *      automatically on every later re-upload, instead of blocking again
 *      forever. No new endpoint needed — the existing one already writes
 *      exactly this row.
 *   5. Unresolved — staged into migration_product_map (source_table
 *      'po_import') for that same existing admin review queue, never a
 *      new parallel one.
 *
 * Store resolution priority: store_alias exact raw_name match, then
 * store.canonical_name normalized match, then migration_store_map
 * status='mapped' for the same raw name, then unresolved (staged the same
 * way, source_table 'po_import').
 */
final class PoResolver
{
    private ?int $unallocatedStoreId = null;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{status:string,productId:?int,method:?string} */
    public function resolveProduct(string $rawCode, string $rawName): array
    {
        $rawCode = trim($rawCode);
        $rawName = trim($rawName);
        $normName = PoFileParser::up($rawName);

        if ($rawCode !== '') {
            $stmt = $this->pdo->prepare('SELECT DISTINCT product_id FROM product_legacy_code WHERE legacy_code = ?');
            $stmt->execute([$rawCode]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) === 1) {
                return ['status' => 'resolved', 'productId' => (int) $ids[0], 'method' => 'legacy_code'];
            }
        }

        $stmt = $this->pdo->prepare('SELECT product_id FROM product_alias WHERE raw_name = ?');
        $stmt->execute([$rawName]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return ['status' => 'resolved', 'productId' => (int) $id, 'method' => 'alias'];
        }

        $stmt = $this->pdo->prepare('SELECT product_id FROM product WHERE UPPER(name) = ?');
        $stmt->execute([$normName]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return ['status' => 'resolved', 'productId' => (int) $id, 'method' => 'exact_name'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT target_id FROM migration_product_map WHERE raw_name = ? AND raw_code = ? AND status = 'mapped'"
        );
        $stmt->execute([$rawName, $rawCode]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return ['status' => 'resolved', 'productId' => (int) $id, 'method' => 'previously_resolved'];
        }

        return ['status' => 'unresolved', 'productId' => null, 'method' => null];
    }

    /** @return array{status:string,storeId:?int,method:?string} */
    public function resolveStore(string $rawName): array
    {
        $rawName = trim($rawName);
        $normName = PoFileParser::up($rawName);

        $stmt = $this->pdo->prepare('SELECT store_id FROM store_alias WHERE raw_name = ?');
        $stmt->execute([$rawName]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return ['status' => 'resolved', 'storeId' => (int) $id, 'method' => 'alias'];
        }

        $stmt = $this->pdo->prepare('SELECT store_id FROM store WHERE UPPER(canonical_name) = ?');
        $stmt->execute([$normName]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return ['status' => 'resolved', 'storeId' => (int) $id, 'method' => 'canonical_name'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT target_id FROM migration_store_map WHERE raw_name = ? AND source_table = 'po_import' AND status = 'mapped'"
        );
        $stmt->execute([$rawName]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return ['status' => 'resolved', 'storeId' => (int) $id, 'method' => 'previously_resolved'];
        }

        return ['status' => 'unresolved', 'storeId' => null, 'method' => null];
    }

    /**
     * The synthetic non-outlet store seeded in Phase 0 (Setup\Seeder) — used
     * ONLY for genuinely unallocated product-level demand (a PO row whose
     * TOTAL is nonzero but whose per-store breakdown is completely empty,
     * the "tanpa_alokasi" case PoFileParser already flags as a warning).
     * Never used for a row that has a real but unresolved store name — that
     * stays unresolved and blocks the row, per the task's explicit
     * instruction not to silently attach demand to the wrong place.
     *
     * @throws \RuntimeException if Phase 0 seeding was never run
     */
    public function unallocatedStoreId(): int
    {
        if ($this->unallocatedStoreId !== null) {
            return $this->unallocatedStoreId;
        }
        $stmt = $this->pdo->prepare('SELECT store_id FROM store WHERE canonical_name = ?');
        $stmt->execute(['NON-OUTLET / PERORANGAN']);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException(
                "Synthetic store 'NON-OUTLET / PERORANGAN' not found — Phase 0 seeding (bin/seed.php) must run before PO import."
            );
        }
        return $this->unallocatedStoreId = (int) $id;
    }

    /**
     * Stages (or bumps the occurrence count of) an unresolved product raw
     * identity for admin review via the EXISTING
     * GET/POST /api/admin/migration/products endpoints — no new review
     * queue. Deliberately reuses the same upsert shape as
     * Phase1Importer::upsertMigrationMap(): the UPDATE path only bumps
     * occurrence_count, so a row an admin already resolved (status=
     * 'mapped') is never silently reset back to 'unresolved' just because
     * the same raw name/code showed up again in a later PO file — see
     * resolveProduct()'s tier 4 above, which is exactly what lets that
     * resolution take effect on the next upload.
     */
    public function stageUnresolvedProduct(string $rawName, string $rawCode, string $reason): void
    {
        $rawName = trim($rawName);
        $rawCode = trim($rawCode);
        $stmt = $this->pdo->prepare(
            'INSERT INTO migration_product_map (raw_name, raw_code, source_table, occurrence_count, status, notes, created_at)
             VALUES (?, ?, ?, 1, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                occurrence_count = occurrence_count + 1,
                notes = IF(status = ?, notes, VALUES(notes))'
        );
        $stmt->execute([$rawName, $rawCode, 'po_import', 'unresolved', $reason, 'mapped']);
    }

    public function stageUnresolvedStore(string $rawName, string $reason): void
    {
        $rawName = trim($rawName);
        $stmt = $this->pdo->prepare(
            "INSERT INTO migration_store_map (raw_name, raw_code, source_table, occurrence_count, status, notes, created_at)
             VALUES (?, '', ?, 1, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                occurrence_count = occurrence_count + 1,
                notes = IF(status = ?, notes, VALUES(notes))"
        );
        $stmt->execute([$rawName, 'po_import', 'unresolved', $reason, 'mapped']);
    }
}
