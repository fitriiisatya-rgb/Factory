<?php

declare(strict_types=1);

namespace Amor\Api\Import;

use Amor\Api\Audit;
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

    /**
     * A plain human-readable explanation of why resolveProduct() returned
     * 'unresolved' for this exact raw pair — display only, computed by
     * literally re-checking each tier so it can never drift from the real
     * resolution logic above.
     */
    public function unresolvedProductReason(string $rawCode, string $rawName): string
    {
        $rawCode = trim($rawCode);
        $rawName = trim($rawName);
        $parts = [];
        if ($rawCode !== '') {
            $stmt = $this->pdo->prepare('SELECT COUNT(DISTINCT product_id) FROM product_legacy_code WHERE legacy_code = ?');
            $stmt->execute([$rawCode]);
            $n = (int) $stmt->fetchColumn();
            $parts[] = $n > 1
                ? "kode '{$rawCode}' dipakai oleh {$n} produk berbeda (ambigu, tidak ditebak)"
                : "kode '{$rawCode}' tidak ditemukan di master produk";
        } else {
            $parts[] = 'file ini tidak menyertakan kode produk';
        }
        $parts[] = "tidak ada alias atau nama produk yang persis sama dengan '{$rawName}'";
        return ucfirst(implode('; ', $parts)) . '.';
    }

    /**
     * Products whose normalized name is a CLOSE (not exact — exact would
     * already have resolved) match to $rawName — display-only hints so an
     * admin can notice "maybe this is a typo of an existing product"
     * before choosing to create a brand-new one. Never auto-applied; never
     * used by resolveProduct() itself.
     * @return array<int,array{productId:int,name:string,similarity:float}>
     */
    public function similarProductCandidates(string $rawName, float $minSimilarity = 70.0, int $limit = 5): array
    {
        $rawName = trim($rawName);
        if ($rawName === '') {
            return [];
        }
        $stmt = $this->pdo->query('SELECT product_id, name FROM product');
        $candidates = [];
        foreach ($stmt->fetchAll() as $row) {
            similar_text(PoFileParser::up($rawName), PoFileParser::up($row['name']), $pct);
            if ($pct >= $minSimilarity) {
                $candidates[] = ['productId' => (int) $row['product_id'], 'name' => $row['name'], 'similarity' => round($pct, 1)];
            }
        }
        usort($candidates, static fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($candidates, 0, $limit);
    }

    /**
     * Controlled creation of a brand-new product directly from an
     * unresolved PO row, per the New Product Review flow (never a fuzzy
     * auto-merge — the admin explicitly confirms $finalName). Runs its own
     * short transaction (product + product_legacy_code + product_alias +
     * audit_log together), separate from the PO commit itself — the
     * product becomes real master data immediately regardless of whether
     * the admin goes on to actually commit the PO, exactly like
     * api/_import-master/'s existing "Tambah Toko Manual" always has for
     * stores. Marked traceable/reconcilable via product_alias.source and
     * audit_log.action rather than folded into one giant transaction with
     * the PO write, since the two are reviewed as separate admin steps by
     * design (New Product Review happens before the PO is even ready to
     * commit).
     *
     * Idempotent: if $finalName already matches an existing product (e.g.
     * a double-submit, or this exact row was already created earlier),
     * returns that product's id instead of erroring or duplicating —
     * needed so re-running the same file never creates a second product.
     *
     * @throws \InvalidArgumentException if $finalName is blank
     * @throws PoProductCodeConflictException if $rawCode already belongs
     *         to a different, already-existing product
     */
    public function createProductFromUnresolved(
        string $rawCode,
        string $rawName,
        string $finalName,
        ?string $kategori,
        ?int $divisionId,
        float $harga,
        int $userId,
        ?string $sourceFilename,
        string $sourceHash
    ): array {
        $rawCode = trim($rawCode);
        $rawName = trim($rawName);
        $finalName = trim($finalName);
        if ($finalName === '') {
            throw new \InvalidArgumentException('Nama produk tujuan wajib diisi.');
        }

        $stmt = $this->pdo->prepare('SELECT product_id FROM product WHERE UPPER(name) = UPPER(?)');
        $stmt->execute([$finalName]);
        $existingId = $stmt->fetchColumn();
        if ($existingId !== false) {
            return ['productId' => (int) $existingId, 'created' => false];
        }

        if ($rawCode !== '') {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_legacy_code WHERE legacy_code = ?');
            $stmt->execute([$rawCode]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new PoProductCodeConflictException(
                    "Kode legacy '{$rawCode}' sudah dipakai oleh produk lain — tidak bisa dipakai untuk membuat produk baru ini."
                );
            }
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                // hpp is deliberately 0 — a PO upload never carries an authoritative
                // cost figure, matching the same discipline Phase1Importer's katalog
                // import already applies (see its own createProductFromRow()).
                'INSERT INTO product (name, kategori, division_id, hpp, harga, aktif, version, created_at)
                 VALUES (?, ?, ?, 0, ?, 1, 1, UTC_TIMESTAMP())'
            );
            $stmt->execute([$finalName, $kategori !== '' ? $kategori : null, $divisionId, $harga]);
            $productId = (int) $this->pdo->lastInsertId();

            if ($rawCode !== '') {
                $this->pdo->prepare(
                    'INSERT INTO product_legacy_code (product_id, legacy_code, created_at) VALUES (?, ?, UTC_TIMESTAMP())'
                )->execute([$productId, $rawCode]);
            }
            if ($rawName !== '' && strcasecmp($rawName, $finalName) !== 0) {
                $this->pdo->prepare(
                    'INSERT INTO product_alias (product_id, raw_name, source, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
                )->execute([$productId, $rawName, 'po_import_new_product']);
            }

            Audit::write($this->pdo, null, $userId, 'po.product.create_from_import', 'product', (string) $productId, 'ok', null, 1, [
                'rawCode' => $rawCode, 'rawName' => $rawName, 'finalName' => $finalName,
                'sourceFilename' => $sourceFilename, 'sourceHash' => $sourceHash,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['productId' => $productId, 'created' => true];
    }
}
