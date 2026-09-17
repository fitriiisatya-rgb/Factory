<?php

declare(strict_types=1);

namespace Amor\Api\Import;

use PDO;

/**
 * Persistence for the authoritative PO state (po_batch/po_item/
 * po_store_item, all from the original 0001 schema — no parallel PO truth
 * table was created for this phase) plus the new po_import immutable
 * upload-history table (migration 0003).
 *
 * po_batch is CURRENT STATE, one row per (tanggal, factory_id) — every
 * upload updates the SAME row (locked with SELECT ... FOR UPDATE inside the
 * caller's transaction before any read of existing lines, so two admins
 * uploading around the same time serialize instead of interleaving; see
 * PoImporter). po_import is separate, immutable, append-only history.
 */
final class PoRepository
{
    /**
     * Plain read-only lookup, no lock, never creates — for preview only
     * (which must never write, not even to create an empty batch row).
     */
    public function findBatchByDate(PDO $pdo, string $tanggal, int $factoryId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM po_batch WHERE tanggal = ? AND factory_id = ?');
        $stmt->execute([$tanggal, $factoryId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array|null the po_batch row, row-locked (FOR UPDATE) if found */
    public function lockExistingBatch(PDO $pdo, string $tanggal, int $factoryId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM po_batch WHERE tanggal = ? AND factory_id = ? FOR UPDATE'
        );
        $stmt->execute([$tanggal, $factoryId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Finds the batch (locked) or creates it if this is genuinely the first
     * upload ever for this (tanggal, factory_id) — guarded against a
     * concurrent create race by po_batch's own UNIQUE KEY: if two requests
     * both see "not found" and both try to INSERT, the loser's INSERT fails
     * with a duplicate-key error, which is caught here and turned into a
     * re-lock of the winner's row instead of a crash.
     */
    public function findOrCreateBatch(PDO $pdo, string $tanggal, int $factoryId): array
    {
        $existing = $this->lockExistingBatch($pdo, $tanggal, $factoryId);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO po_batch (tanggal, factory_id, version, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())'
            );
            $stmt->execute([$tanggal, $factoryId]);
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $row = $this->lockExistingBatch($pdo, $tanggal, $factoryId);
                if ($row !== null) {
                    return $row;
                }
            }
            throw $e;
        }

        return $this->lockExistingBatch($pdo, $tanggal, $factoryId);
    }

    public function findBatchById(PDO $pdo, int $poBatchId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT b.*, f.name AS factory_name FROM po_batch b
             INNER JOIN factory f ON f.factory_id = b.factory_id
             WHERE b.po_batch_id = ?'
        );
        $stmt->execute([$poBatchId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array> */
    public function findAllBatches(PDO $pdo, ?string $tanggal, ?int $factoryId): array
    {
        $sql = 'SELECT b.*, f.name AS factory_name FROM po_batch b
                INNER JOIN factory f ON f.factory_id = b.factory_id WHERE 1=1';
        $params = [];
        if ($tanggal !== null) {
            $sql .= ' AND b.tanggal = ?';
            $params[] = $tanggal;
        }
        if ($factoryId !== null) {
            $sql .= ' AND b.factory_id = ?';
            $params[] = $factoryId;
        }
        $sql .= ' ORDER BY b.tanggal DESC, f.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function hasExistingItems(PDO $pdo, int $poBatchId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM po_item WHERE po_batch_id = ? LIMIT 1');
        $stmt->execute([$poBatchId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Flattened (product,store) lines currently on record for this batch —
     * the "existing lines" input to PoMerger::merge().
     * @return array<int,array{productId:int,storeId:int,poAwal:float,poRevisi:float,kategori:?string}>
     */
    public function findExistingLines(PDO $pdo, int $poBatchId): array
    {
        $stmt = $pdo->prepare(
            'SELECT i.product_id, i.kategori, si.store_id, si.po_awal, si.po_revisi
             FROM po_item i
             INNER JOIN po_store_item si ON si.po_item_id = i.po_item_id
             WHERE i.po_batch_id = ?'
        );
        $stmt->execute([$poBatchId]);
        return array_map(static fn ($r) => [
            'productId' => (int) $r['product_id'],
            'storeId' => (int) $r['store_id'],
            'poAwal' => (float) $r['po_awal'],
            'poRevisi' => (float) $r['po_revisi'],
            'kategori' => $r['kategori'],
        ], $stmt->fetchAll());
    }

    /** @return array<int,float> product_id -> pb currently on record for this batch */
    public function findExistingPb(PDO $pdo, int $poBatchId): array
    {
        $stmt = $pdo->prepare('SELECT product_id, pb FROM po_item WHERE po_batch_id = ?');
        $stmt->execute([$poBatchId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['product_id']] = (float) $r['pb'];
        }
        return $out;
    }

    /**
     * Writes the FULL resulting (product,store) line set (as computed by
     * PoMerger::merge()) plus each product's pb value. Upserts po_item (one
     * row per product, po_awal/po_revisi = the SUM of that product's store
     * lines, so po_item is always a derived rollup, never a second
     * independent source of truth) and po_store_item (one row per
     * product+store pair). Never deletes a row — a (product,store) pair
     * absent from $lines this call simply isn't touched, per the "never
     * silently lose a preserved line" rule; $lines is expected to already
     * include every line that must exist (PoMerger guarantees this).
     *
     * @param array<int,array{productId:int,storeId:int,poAwal:float,poRevisi:float,kategori:?string}> $lines
     * @param array<int,float> $pbByProduct
     */
    public function applyLines(PDO $pdo, int $poBatchId, array $lines, array $pbByProduct): void
    {
        $byProduct = [];
        foreach ($lines as $line) {
            $byProduct[$line['productId']][] = $line;
        }

        $findItem = $pdo->prepare('SELECT po_item_id FROM po_item WHERE po_batch_id = ? AND product_id = ?');
        $insertItem = $pdo->prepare(
            'INSERT INTO po_item (po_batch_id, product_id, kategori, po_awal, po_revisi, pb) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $updateItem = $pdo->prepare(
            'UPDATE po_item SET kategori = ?, po_awal = ?, po_revisi = ?, pb = ? WHERE po_item_id = ?'
        );
        $findStoreItem = $pdo->prepare('SELECT po_store_item_id FROM po_store_item WHERE po_item_id = ? AND store_id = ?');
        $insertStoreItem = $pdo->prepare(
            'INSERT INTO po_store_item (po_item_id, store_id, po_awal, po_revisi) VALUES (?, ?, ?, ?)'
        );
        $updateStoreItem = $pdo->prepare(
            'UPDATE po_store_item SET po_awal = ?, po_revisi = ? WHERE po_store_item_id = ?'
        );

        foreach ($byProduct as $productId => $productLines) {
            $poAwalSum = array_sum(array_map(static fn ($l) => $l['poAwal'], $productLines));
            $poRevisiSum = array_sum(array_map(static fn ($l) => $l['poRevisi'], $productLines));
            $kategori = null;
            foreach ($productLines as $l) {
                if (!empty($l['kategori'])) {
                    $kategori = $l['kategori'];
                }
            }
            $pb = $pbByProduct[$productId] ?? 0.0;

            $findItem->execute([$poBatchId, $productId]);
            $itemId = $findItem->fetchColumn();
            if ($itemId === false) {
                $insertItem->execute([$poBatchId, $productId, $kategori, $poAwalSum, $poRevisiSum, $pb]);
                $itemId = (int) $pdo->lastInsertId();
            } else {
                $itemId = (int) $itemId;
                $updateItem->execute([$kategori, $poAwalSum, $poRevisiSum, $pb, $itemId]);
            }

            foreach ($productLines as $line) {
                $findStoreItem->execute([$itemId, $line['storeId']]);
                $storeItemId = $findStoreItem->fetchColumn();
                if ($storeItemId === false) {
                    $insertStoreItem->execute([$itemId, $line['storeId'], $line['poAwal'], $line['poRevisi']]);
                } else {
                    $updateStoreItem->execute([$line['poAwal'], $line['poRevisi'], $storeItemId]);
                }
            }
        }
    }

    public function bumpBatchUploadMeta(
        PDO $pdo,
        int $poBatchId,
        string $uploadType,
        ?string $sourceFilename,
        string $sourceHash,
        ?int $uploadedBy
    ): int {
        $stmt = $pdo->prepare(
            'UPDATE po_batch
             SET upload_type = ?, source_filename = ?, source_hash = ?, uploaded_by = ?,
                 version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE po_batch_id = ?'
        );
        $stmt->execute([$uploadType, $sourceFilename, $sourceHash, $uploadedBy, $poBatchId]);
        return (int) $pdo->query('SELECT version FROM po_batch WHERE po_batch_id = ' . (int) $poBatchId)->fetchColumn();
    }

    /** Duplicate-file detection: an already-IMPORTED upload with this exact hash for this date+factory. */
    public function findDuplicateImport(PDO $pdo, string $tanggal, int $factoryId, string $sourceHash): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM po_import WHERE tanggal = ? AND factory_id = ? AND source_hash = ? AND result = 'imported'
             ORDER BY uploaded_at DESC LIMIT 1"
        );
        $stmt->execute([$tanggal, $factoryId, $sourceHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function recordImportHistory(PDO $pdo, array $data): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO po_import
                (po_batch_id, tanggal, factory_id, upload_type, source_filename, source_hash,
                 rows_total, rows_po_awal, rows_po_revisi, rows_pb_ignored,
                 products_mapped, products_unresolved, stores_mapped, stores_unresolved,
                 total_po_awal, total_po_revisi, warnings_json, result, uploaded_by, uploaded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $data['poBatchId'], $data['tanggal'], $data['factoryId'], $data['uploadType'],
            $data['sourceFilename'], $data['sourceHash'],
            $data['rowsTotal'], $data['rowsPoAwal'], $data['rowsPoRevisi'], $data['rowsPbIgnored'],
            $data['productsMapped'], $data['productsUnresolved'], $data['storesMapped'], $data['storesUnresolved'],
            $data['totalPoAwal'], $data['totalPoRevisi'],
            $data['warnings'] !== [] ? json_encode($data['warnings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            $data['result'], $data['uploadedBy'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int,array> */
    public function findHistory(PDO $pdo, ?string $tanggal, ?int $factoryId, int $limit): array
    {
        $sql = 'SELECT h.*, f.name AS factory_name, u.username AS uploaded_by_username
                FROM po_import h
                INNER JOIN factory f ON f.factory_id = h.factory_id
                LEFT JOIN users u ON u.user_id = h.uploaded_by
                WHERE 1=1';
        $params = [];
        if ($tanggal !== null) {
            $sql .= ' AND h.tanggal = ?';
            $params[] = $tanggal;
        }
        if ($factoryId !== null) {
            $sql .= ' AND h.factory_id = ?';
            $params[] = $factoryId;
        }
        $sql .= ' ORDER BY h.uploaded_at DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * GET /api/po/current — current authoritative demand with display
     * names. poType filters PRESENTATION ONLY (task section 15): it never
     * changes what is stored, only which rows this call returns.
     * @return array<int,array>
     */
    public function findCurrent(
        PDO $pdo,
        ?string $tanggal,
        ?int $factoryId,
        ?int $divisionId,
        ?int $storeId,
        string $poType
    ): array {
        $sql = 'SELECT b.tanggal, b.factory_id, f.name AS factory_name,
                       i.po_item_id, i.product_id, p.name AS product_name, p.division_id, d.name AS division_name,
                       i.kategori, i.pb,
                       si.po_store_item_id, si.store_id, s.canonical_name AS store_name,
                       si.po_awal, si.po_revisi
                FROM po_batch b
                INNER JOIN factory f ON f.factory_id = b.factory_id
                INNER JOIN po_item i ON i.po_batch_id = b.po_batch_id
                INNER JOIN product p ON p.product_id = i.product_id
                LEFT JOIN division d ON d.division_id = p.division_id
                INNER JOIN po_store_item si ON si.po_item_id = i.po_item_id
                INNER JOIN store s ON s.store_id = si.store_id
                WHERE 1=1';
        $params = [];
        if ($tanggal !== null) {
            $sql .= ' AND b.tanggal = ?';
            $params[] = $tanggal;
        }
        if ($factoryId !== null) {
            $sql .= ' AND b.factory_id = ?';
            $params[] = $factoryId;
        }
        if ($divisionId !== null) {
            $sql .= ' AND p.division_id = ?';
            $params[] = $divisionId;
        }
        if ($storeId !== null) {
            $sql .= ' AND si.store_id = ?';
            $params[] = $storeId;
        }
        if ($poType === 'initial') {
            $sql .= ' AND si.po_awal > 0';
        } elseif ($poType === 'revision') {
            $sql .= ' AND si.po_revisi > 0';
        }
        $sql .= ' ORDER BY b.tanggal DESC, f.name, p.name, s.canonical_name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
