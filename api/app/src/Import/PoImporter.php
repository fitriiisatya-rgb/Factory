<?php

declare(strict_types=1);

namespace Amor\Api\Import;

use PDO;

/**
 * Orchestrates the whole PO upload flow: read file -> parse (PoFileParser)
 * -> resolve product/store identity (PoResolver) -> merge against current
 * state (PoMerger) -> persist (PoRepository). preview() never writes
 * anything; import() re-derives the same plan fresh (never trusts a
 * client-supplied "confirmed" snapshot from an earlier preview call) and
 * writes only when nothing is unresolved and no batch-level rule blocks it.
 */
final class PoImporter
{
    private const FACTORY_NAME_BY_CODE = ['karangtengah' => 'Karangtengah', 'cibadak' => 'Cibadak'];

    private PoResolver $resolver;
    private PoRepository $repo;

    public function __construct(private PDO $pdo)
    {
        $this->resolver = new PoResolver($pdo);
        $this->repo = new PoRepository();
    }

    /**
     * Reads an uploaded file into the 2D row array PoFileParser expects.
     * For .xlsx, mirrors the audited legacy behavior of preferring a sheet
     * whose tab name is a bare day-of-month ("1".."31"), falling back to
     * the first sheet.
     * @throws \RuntimeException on an unsupported extension or unreadable file
     */
    public function readRows(string $tmpFilePath, string $originalFilename): array
    {
        $ext = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            return self::readCsvRows($tmpFilePath);
        }
        if ($ext === 'xlsx') {
            $sheetNames = XlsxReader::listSheetNames($tmpFilePath);
            $dateLike = null;
            foreach ($sheetNames as $n) {
                if (preg_match('/^\d{1,2}$/', trim((string) $n))) {
                    $dateLike = (string) $n;
                    break;
                }
            }
            return XlsxReader::readSheet($tmpFilePath, $dateLike);
        }
        if ($ext === 'xls') {
            throw new \RuntimeException('Format .xls (Excel lama) tidak didukung — simpan ulang sebagai .xlsx atau .csv lalu upload lagi.');
        }
        throw new \RuntimeException("Ekstensi file '.{$ext}' tidak didukung — gunakan .xlsx atau .csv.");
    }

    /** @return array<int,array<int,string>> */
    private static function readCsvRows(string $path): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException('Gagal membuka file CSV.');
        }
        $first = fgets($fh);
        rewind($fh);
        $delimiter = $first !== false && substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $rows = [];
        while (($r = fgetcsv($fh, 0, $delimiter)) !== false) {
            $rows[] = $r;
        }
        fclose($fh);
        return $rows;
    }

    /**
     * The raw (pre-resolution) identity key for one product row — the same
     * key PoResolver's own migration_product_map staging uses conceptually
     * (raw code + raw name), exposed here so callers (the wizard) can name
     * a specific unresolved row to skip via $skipProductKeys below without
     * depending on any resolved product_id (which doesn't exist yet).
     */
    public static function productRawKey(string $rawCode, string $rawName): string
    {
        return trim($rawCode) . "\x1f" . trim($rawName);
    }

    /**
     * Preview only — never writes. Returns the same shape used to decide
     * whether Import may proceed, so the wizard and the API show exactly
     * what will happen before anyone confirms.
     *
     * $skipProductKeys (optional, default none — existing callers are
     * completely unaffected) names raw product rows (via productRawKey())
     * to exclude entirely from this run, e.g. a row an admin explicitly
     * chose to skip during New Product Review — never a way to bypass
     * resolution for a row that is still being imported.
     */
    public function preview(array $rows, string $tanggal, string $uploadType, string $sourceHash, ?string $sourceFilename, array $skipProductKeys = []): array
    {
        return $this->buildPlan($rows, $tanggal, $uploadType, $sourceHash, $sourceFilename, stageUnresolved: false, skipProductKeys: $skipProductKeys);
    }

    /**
     * Re-derives the plan fresh (never trusts a prior preview) and writes
     * only if importable. Always records one po_import history row —
     * including for a rejected attempt — audit trail either way. Must be
     * called with $pdo already inside a transaction (see PoController,
     * which uses Idempotency::handle for this).
     *
     * @return array{ok:bool,code:?string,message:?string,data:?array}
     */
    public function import(array $rows, string $tanggal, string $uploadType, string $sourceHash, ?string $sourceFilename, ?int $uploadedBy, array $skipProductKeys = []): array
    {
        $plan = $this->buildPlan($rows, $tanggal, $uploadType, $sourceHash, $sourceFilename, stageUnresolved: true, skipProductKeys: $skipProductKeys);

        $historyResult = $plan['canImport'] ? 'imported' : ($plan['blockReason'] === 'DUPLICATE_FILE' ? 'duplicate_file' : 'rejected_unresolved');
        $poBatchId = $plan['poBatchId'];
        // Skipped-row info is folded into the existing warnings_json column
        // (no schema change) rather than a dedicated po_import column.
        $historyWarnings = $plan['warnings'];
        foreach ($plan['skippedProductRows'] as $sp) {
            $historyWarnings[] = ['produk' => $sp['nama'], 'tipe' => 'skipped_by_admin', 'pesan' =>
                "Baris ini dilewati oleh admin lewat New Product Review (kode='{$sp['kode']}') — tidak diimpor."];
        }

        if (!$plan['canImport']) {
            $this->repo->recordImportHistory($this->pdo, [
                'poBatchId' => $poBatchId, 'tanggal' => $tanggal, 'factoryId' => $plan['factoryId'],
                'uploadType' => $uploadType, 'sourceFilename' => $sourceFilename, 'sourceHash' => $sourceHash,
                'rowsTotal' => $plan['parsedRowCount'], 'rowsPoAwal' => $plan['rowsPoAwal'], 'rowsPoRevisi' => $plan['rowsPoRevisi'],
                'rowsPbIgnored' => $plan['rowsPbIgnored'],
                'productsMapped' => $plan['productResolution']['mapped'], 'productsUnresolved' => $plan['productResolution']['unresolved'],
                'storesMapped' => $plan['storeResolution']['mapped'], 'storesUnresolved' => $plan['storeResolution']['unresolved'],
                'totalPoAwal' => $plan['totalPoAwal'], 'totalPoRevisi' => $plan['totalPoRevisi'],
                'warnings' => $historyWarnings, 'result' => $historyResult, 'uploadedBy' => $uploadedBy,
            ]);

            return match ($plan['blockReason']) {
                'UNRESOLVED_ROWS' => ['ok' => false, 'code' => 'UNRESOLVED_ROWS', 'message' =>
                    'Masih ada produk/toko yang belum terpetakan — selesaikan mapping-nya dulu lewat antrian review sebelum PO ini bisa diimpor.', 'data' => $plan],
                'INITIAL_PO_ALREADY_EXISTS' => ['ok' => false, 'code' => 'INITIAL_PO_ALREADY_EXISTS', 'message' =>
                    'PO Awal untuk tanggal & pabrik ini sudah pernah ditetapkan dari file yang berbeda — tidak ditimpa otomatis.', 'data' => $plan],
                'NO_INITIAL_YET' => ['ok' => false, 'code' => 'NO_INITIAL_YET', 'message' =>
                    'Belum ada PO Awal untuk tanggal & pabrik ini — upload PO Awal dulu sebelum PO Tambahan/Revisi.', 'data' => $plan],
                default => ['ok' => false, 'code' => 'IMPORT_BLOCKED', 'message' => 'Impor tidak bisa dilanjutkan.', 'data' => $plan],
            };
        }

        $pbByProduct = [];
        foreach ($plan['resolvedProductRows'] as $rp) {
            $pbByProduct[$rp['productId']] = $rp['pb'];
        }
        $this->repo->applyLines($this->pdo, $poBatchId, $plan['mergePreview']['lines'], $pbByProduct);
        $newVersion = $this->repo->bumpBatchUploadMeta($this->pdo, $poBatchId, $uploadType, $sourceFilename, $sourceHash, $uploadedBy);

        $this->repo->recordImportHistory($this->pdo, [
            'poBatchId' => $poBatchId, 'tanggal' => $tanggal, 'factoryId' => $plan['factoryId'],
            'uploadType' => $uploadType, 'sourceFilename' => $sourceFilename, 'sourceHash' => $sourceHash,
            'rowsTotal' => $plan['parsedRowCount'], 'rowsPoAwal' => $plan['rowsPoAwal'], 'rowsPoRevisi' => $plan['rowsPoRevisi'],
            'rowsPbIgnored' => $plan['rowsPbIgnored'],
            'productsMapped' => $plan['productResolution']['mapped'], 'productsUnresolved' => 0,
            'storesMapped' => $plan['storeResolution']['mapped'], 'storesUnresolved' => 0,
            'totalPoAwal' => $plan['totalPoAwal'], 'totalPoRevisi' => $plan['totalPoRevisi'],
            'warnings' => $historyWarnings, 'result' => 'imported', 'uploadedBy' => $uploadedBy,
        ]);

        return ['ok' => true, 'code' => null, 'message' => null, 'data' => [
            'poBatchId' => $poBatchId,
            'version' => $newVersion,
            'factory' => $plan['factory'],
            'tanggal' => $tanggal,
            'uploadType' => $uploadType,
            'linesWritten' => count($plan['mergePreview']['lines']),
            'targetTotal' => $plan['targetTotal'],
            'mergeSummary' => $plan['mergePreview']['summary'],
            'duplicateOfImportId' => $plan['duplicateOf']['po_import_id'] ?? null,
            'skippedProductRows' => $plan['skippedProductRows'],
        ]];
    }

    /**
     * The shared core: parse, resolve, and (if the batch already exists)
     * compute the merge preview against current state — all read-only
     * except for finding-or-creating (and row-locking) the po_batch row
     * itself, which is safe/idempotent and must happen before reading
     * "existing lines" so a concurrent revision upload can never read a
     * stale snapshot (see PoRepository::findOrCreateBatch).
     */
    private function buildPlan(array $rows, string $tanggal, string $uploadType, string $sourceHash, ?string $sourceFilename, bool $stageUnresolved, array $skipProductKeys = []): array
    {
        if (!in_array($uploadType, ['initial', 'revision'], true)) {
            throw new \InvalidArgumentException("uploadType must be 'initial' or 'revision', got '{$uploadType}'.");
        }

        $parsed = PoFileParser::parseAuto($rows);
        $factory = $parsed['factory'];
        $factoryName = self::FACTORY_NAME_BY_CODE[$factory];
        $stmt = $this->pdo->prepare('SELECT factory_id FROM factory WHERE name = ?');
        $stmt->execute([$factoryName]);
        $factoryId = $stmt->fetchColumn();
        if ($factoryId === false) {
            throw new \RuntimeException("Factory '{$factoryName}' not found — Phase 0 seeding must run first.");
        }
        $factoryId = (int) $factoryId;

        // Preview must never write — including creating the po_batch row —
        // so only import() (stageUnresolved=true, always called inside a
        // transaction) locks-or-creates it. Preview does a plain read-only
        // lookup and simply treats a not-yet-existing batch as "no items
        // yet" (correct either way for a brand-new date+factory).
        if ($stageUnresolved) {
            $batch = $this->repo->findOrCreateBatch($this->pdo, $tanggal, $factoryId);
            $poBatchId = (int) $batch['po_batch_id'];
        } else {
            $batch = $this->repo->findBatchByDate($this->pdo, $tanggal, $factoryId);
            $poBatchId = $batch !== null ? (int) $batch['po_batch_id'] : null;
        }
        $hasExistingItems = $poBatchId !== null && $this->repo->hasExistingItems($this->pdo, $poBatchId);
        $duplicateOf = $this->repo->findDuplicateImport($this->pdo, $tanggal, $factoryId, $sourceHash);

        $warnings = [];
        $rowsPoAwal = 0;
        $rowsPoRevisi = 0;
        $rowsPbIgnored = 0;
        $totalPoAwalRaw = 0.0;
        $totalPoRevisiRaw = 0.0;
        $totalPbRaw = 0.0;
        $productMapped = 0;
        $productUnresolved = 0;
        $storeMapped = 0;
        $storeUnresolved = 0;
        // $storeMapped/$storeUnresolved above count ROW OCCURRENCES (once per
        // product x store line in the file — a real file has many rows per
        // store, so this is naturally much larger than the store count a
        // human expects from "toko terpetakan"). These two track DISTINCT
        // stores instead, so the wizard can show both numbers labeled
        // correctly rather than one ambiguous "toko terpetakan" that a real
        // cPanel UAT run showed as 1986 for a file with only ~400 actual
        // stores — a display-clarity fix only; nothing here changes which
        // rows resolve, which lines get written, or any committed total.
        $uniqueStoreIdsMapped = [];
        $uniqueStoreNamesUnresolved = [];
        $unresolvedProductSamples = [];
        $unresolvedStoreSamples = [];
        /** @var array<int,array{productId:int,kategori:?string,pb:float}> */
        $resolvedProductRows = [];
        /** @var array<int,array{productId:int,storeId:int,poAwal:float,poRevisi:float,kategori:?string}> */
        $newLines = [];
        /** @var array<int,array{kode:string,nama:string}> rows explicitly excluded by an admin (New Product Review "skip") */
        $skippedProductRows = [];
        $skipKeySet = array_flip($skipProductKeys);

        foreach ($parsed['rows'] as $row) {
            if (isset($skipKeySet[self::productRawKey($row['kode'], $row['produkAsli'])])) {
                // Explicitly excluded by the admin (New Product Review "Lewati") —
                // never counted, never staged as unresolved, never imported. Kept
                // out of every total below exactly as if this row were absent
                // from the file for this import, per the task's explicit
                // "skip excludes only that product row" requirement.
                $skippedProductRows[] = ['kode' => $row['kode'], 'nama' => $row['produkAsli']];
                continue;
            }
            if ($row['poAwal'] > 0) {
                $rowsPoAwal++;
            }
            if ($row['poRevisi'] > 0) {
                $rowsPoRevisi++;
            }
            if ($row['pb'] > 0) {
                $rowsPbIgnored++;
            }
            $totalPoAwalRaw += $row['poAwal'];
            $totalPoRevisiRaw += $row['poRevisi'];
            $totalPbRaw += $row['pb'];
            foreach ($row['catatan'] as $c) {
                $warnings[] = ['produk' => $row['produkAsli'], 'tipe' => $c['tipe'], 'pesan' => $c['pesan']];
            }

            $productResolution = $this->resolver->resolveProduct($row['kode'], $row['produkAsli']);
            if ($productResolution['status'] !== 'resolved') {
                $productUnresolved++;
                if (count($unresolvedProductSamples) < 25) {
                    $unresolvedProductSamples[] = ['kode' => $row['kode'], 'nama' => $row['produkAsli'], 'kategori' => $row['kategori'] ?: null];
                }
                if ($stageUnresolved) {
                    $this->resolver->stageUnresolvedProduct(
                        $row['produkAsli'], $row['kode'],
                        "Muncul di upload PO ({$factoryName}, {$tanggal}) tapi belum terpetakan ke produk manapun."
                    );
                }
                continue; // an unresolved product blocks its whole row — never guess a store mapping for it either
            }
            $productId = $productResolution['productId'];
            $productMapped++;
            $resolvedProductRows[$productId] = ['productId' => $productId, 'kategori' => $row['kategori'] ?: null, 'pb' => $row['pb']];

            if ($row['stores'] === []) {
                // No per-store breakdown at all for this product row — the whole
                // product-level total is genuinely unallocated demand, attributed
                // to the synthetic non-outlet store rather than left dangling
                // (task item 3/4: every figure must resolve to a store_id).
                if ($row['poAwal'] > 0 || $row['poRevisi'] > 0) {
                    $newLines[] = [
                        'productId' => $productId,
                        'storeId' => $this->resolver->unallocatedStoreId(),
                        'poAwal' => $row['poAwal'],
                        'poRevisi' => $row['poRevisi'],
                        'kategori' => $row['kategori'] ?: null,
                    ];
                }
                continue;
            }

            foreach ($row['stores'] as $s) {
                $storeResolution = $this->resolver->resolveStore($s['toko']);
                if ($storeResolution['status'] !== 'resolved') {
                    $storeUnresolved++;
                    $uniqueStoreNamesUnresolved[mb_strtolower(trim($s['toko']))] = true;
                    if (count($unresolvedStoreSamples) < 25) {
                        $unresolvedStoreSamples[] = $s['toko'];
                    }
                    if ($stageUnresolved) {
                        $this->resolver->stageUnresolvedStore(
                            $s['toko'],
                            "Muncul di upload PO ({$factoryName}, {$tanggal}, produk '{$row['produkAsli']}') tapi belum terpetakan ke toko manapun."
                        );
                    }
                    continue;
                }
                $storeMapped++;
                $uniqueStoreIdsMapped[$storeResolution['storeId']] = true;
                $newLines[] = [
                    'productId' => $productId,
                    'storeId' => $storeResolution['storeId'],
                    'poAwal' => $s['poAwal'],
                    'poRevisi' => $s['poRevisi'],
                    'kategori' => $row['kategori'] ?: null,
                ];
            }
        }

        $blockReason = null;
        if ($productUnresolved > 0 || $storeUnresolved > 0) {
            $blockReason = 'UNRESOLVED_ROWS';
        } elseif ($uploadType === 'initial' && $hasExistingItems
            && !($duplicateOf !== null && $duplicateOf['upload_type'] === 'initial')
        ) {
            $blockReason = 'INITIAL_PO_ALREADY_EXISTS';
        } elseif ($uploadType === 'revision' && !$hasExistingItems) {
            $blockReason = 'NO_INITIAL_YET';
        }
        $canImport = $blockReason === null;

        $mergePreview = ['lines' => [], 'summary' => []];
        $targetTotal = 0.0;
        $committedPoAwal = 0.0;
        $committedPoRevisi = 0.0;
        if ($canImport) {
            $existingLines = $poBatchId !== null ? $this->repo->findExistingLines($this->pdo, $poBatchId) : [];
            $mergePreview = PoMerger::merge($existingLines, $newLines, $uploadType);
            foreach ($mergePreview['lines'] as $line) {
                $committedPoAwal += $line['poAwal'];
                $committedPoRevisi += $line['poRevisi'];
            }
            $targetTotal = $committedPoAwal + $committedPoRevisi;
        }

        return [
            'factory' => $factory,
            'factoryId' => $factoryId,
            'poBatchId' => $poBatchId,
            'tanggal' => $tanggal,
            'uploadType' => $uploadType,
            'parsedRowCount' => count($parsed['rows']),
            'rowsPoAwal' => $rowsPoAwal,
            'rowsPoRevisi' => $rowsPoRevisi,
            'rowsPbIgnored' => $rowsPbIgnored,
            // Raw, as literally read from the file — informational only,
            // never what gets committed (see committedPoAwal/committedPoRevisi
            // below for that).
            'totalPoAwal' => $totalPoAwalRaw,
            'totalPoRevisi' => $totalPoRevisiRaw,
            'totalPb' => $totalPbRaw,
            // What will actually be written if Import is confirmed now —
            // always the authoritative numbers to show as "will be
            // imported", regardless of upload mode (initial: revisi is
            // always 0 here per PoMerger::mergeInitial(); revision:
            // poAwal here is always the EXISTING locked baseline, poRevisi
            // is the new snapshot).
            'committedPoAwal' => $committedPoAwal,
            'committedPoRevisi' => $committedPoRevisi,
            'productResolution' => ['mapped' => $productMapped, 'unresolved' => $productUnresolved, 'samples' => $unresolvedProductSamples],
            // 'mapped'/'unresolved' are row-occurrence counts (unchanged, same
            // meaning as before this patch — po_import.stores_mapped/
            // stores_unresolved history columns keep recording exactly this).
            // 'uniqueMapped'/'uniqueUnresolved' are the new distinct-store
            // counts, additive only, for the wizard's clarified labels.
            'storeResolution' => [
                'mapped' => $storeMapped, 'unresolved' => $storeUnresolved,
                'uniqueMapped' => count($uniqueStoreIdsMapped), 'uniqueUnresolved' => count($uniqueStoreNamesUnresolved),
                'samples' => $unresolvedStoreSamples,
            ],
            'warnings' => $warnings,
            'hasExistingItems' => $hasExistingItems,
            'duplicateOf' => $duplicateOf,
            'canImport' => $canImport,
            'blockReason' => $blockReason,
            'mergePreview' => $mergePreview,
            'targetTotal' => $targetTotal,
            'resolvedProductRows' => array_values($resolvedProductRows),
            'skippedProductRows' => $skippedProductRows,
        ];
    }
}
