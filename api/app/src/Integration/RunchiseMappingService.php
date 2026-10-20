<?php

declare(strict_types=1);

namespace Amor\Api\Integration;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use PDO;

/**
 * Admin "Mapping Runchise" (migration 0024): the Runchise catalog upload,
 * store → location and product → Runchise product mapping that
 * RunchiseExportService reads. Every mapping is stored and edited by a
 * person; uploading the catalog only fills products that are still
 * unmapped and whose factory product code equals a Runchise SKU.
 */
final class RunchiseMappingService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function overview(): array
    {
        $stores = $this->pdo->query("SELECT store_id, canonical_name, runchise_location FROM store WHERE active = 1 ORDER BY canonical_name")->fetchAll(PDO::FETCH_ASSOC);
        $products = $this->pdo->query(
            "SELECT p.product_id, p.name, p.runchise_sku, p.runchise_name, p.runchise_unit, p.runchise_mode,
                    (SELECT GROUP_CONCAT(l.legacy_code ORDER BY l.legacy_code SEPARATOR ', ') FROM product_legacy_code l WHERE l.product_id = p.product_id) AS codes
             FROM product p WHERE p.aktif = 1 ORDER BY (p.runchise_mode IS NULL) DESC, p.name"
        )->fetchAll(PDO::FETCH_ASSOC);
        $catalog = $this->pdo->query('SELECT sku, name, unit FROM runchise_catalog ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $uploadedAt = $this->pdo->query('SELECT MAX(uploaded_at) FROM runchise_catalog')->fetchColumn();
        return [
            'stores' => array_map(static fn ($s) => ['storeId' => (int) $s['store_id'], 'name' => $s['canonical_name'], 'location' => $s['runchise_location']], $stores),
            'products' => array_map(static fn ($p) => [
                'productId' => (int) $p['product_id'], 'name' => $p['name'], 'codes' => $p['codes'],
                'sku' => $p['runchise_sku'], 'runchiseName' => $p['runchise_name'], 'unit' => $p['runchise_unit'], 'mode' => $p['runchise_mode'],
            ], $products),
            'catalog' => $catalog,
            'catalogUploadedAt' => $uploadedAt ?: null,
            'locations' => RunchiseExportService::LOCATIONS,
        ];
    }

    /**
     * Replaces the catalog with the uploaded Runchise "impor_template_stock_ins.csv"
     * (columns Nama Produk, SKU Produk, Nama Unit Produk; rows without a SKU
     * — e.g. the "Location Name Here" example row — are skipped), then maps
     * every still-unmapped product whose code is a catalog SKU.
     */
    public function uploadCatalog(string $csvText, int $userId, ?string $requestId): array
    {
        $csvText = preg_replace('/^\xEF\xBB\xBF/', '', $csvText) ?? $csvText;
        $firstLine = strtok($csvText, "\n") ?: '';
        $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csvText);
        rewind($fh);
        $header = fgetcsv($fh, 0, $delim, '"', '');
        if (!is_array($header)) {
            throw new ApiException(400, 'CATALOG_EMPTY', 'File katalog kosong.');
        }
        $idx = [];
        foreach ($header as $i => $h) {
            $idx[strtolower(trim((string) $h))] = $i;
        }
        foreach (['nama produk', 'sku produk'] as $need) {
            if (!isset($idx[$need])) {
                throw new ApiException(400, 'CATALOG_COLUMNS', 'Kolom "Nama Produk" dan "SKU Produk" tidak ditemukan. Pakai file impor_template_stock_ins.csv dari Runchise.');
            }
        }
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $sku = trim((string) ($r[$idx['sku produk']] ?? ''));
            $name = trim((string) ($r[$idx['nama produk']] ?? ''));
            if ($sku === '' || $name === '') {
                continue;
            }
            $unit = isset($idx['nama unit produk']) ? trim((string) ($r[$idx['nama unit produk']] ?? '')) : '';
            $rows[$sku] = [$sku, $name, $unit !== '' ? $unit : 'PCS'];
        }
        fclose($fh);
        if ($rows === []) {
            throw new ApiException(400, 'CATALOG_EMPTY', 'Tidak ada produk ber-SKU di file ini.');
        }

        $this->pdo->exec('DELETE FROM runchise_catalog');
        $ins = $this->pdo->prepare('INSERT INTO runchise_catalog (sku, name, unit, uploaded_at) VALUES (?, ?, ?, UTC_TIMESTAMP())');
        foreach ($rows as $row) {
            $ins->execute($row);
        }
        // Keep names/units of already-mapped products in line with the catalog.
        $this->pdo->exec(
            "UPDATE product p INNER JOIN runchise_catalog c ON c.sku = p.runchise_sku
             SET p.runchise_name = c.name, p.runchise_unit = c.unit WHERE p.runchise_mode = 'stok'"
        );
        $mapped = $this->pdo->exec(
            "UPDATE product p
             INNER JOIN (SELECT l.product_id, MIN(c.sku) AS sku FROM product_legacy_code l
                         INNER JOIN runchise_catalog c ON c.sku = l.legacy_code GROUP BY l.product_id) m ON m.product_id = p.product_id
             INNER JOIN runchise_catalog c ON c.sku = m.sku
             SET p.runchise_sku = c.sku, p.runchise_name = c.name, p.runchise_unit = c.unit, p.runchise_mode = 'stok'
             WHERE p.runchise_mode IS NULL"
        );
        Audit::write($this->pdo, $requestId, $userId, 'runchise.catalog_uploaded', 'runchise_catalog', 'all', 'ok', null, null, ['products' => count($rows), 'autoMapped' => (int) $mapped]);
        return ['catalogProducts' => count($rows), 'autoMapped' => (int) $mapped];
    }

    public function setProduct(int $productId, ?string $mode, ?string $sku, int $userId, ?string $requestId): array
    {
        if ($mode !== null && !in_array($mode, ['stok', 'abaikan', 'topping'], true)) {
            throw new ApiException(400, 'INVALID_MODE', 'Pilihan harus stok, abaikan, atau topping.');
        }
        $name = null;
        $unit = null;
        if ($mode === 'stok') {
            $c = $this->pdo->prepare('SELECT sku, name, unit FROM runchise_catalog WHERE sku = ?');
            $c->execute([trim((string) $sku)]);
            $cat = $c->fetch(PDO::FETCH_ASSOC);
            if ($cat === false) {
                throw new ApiException(400, 'SKU_NOT_IN_CATALOG', 'SKU tidak ada di katalog Runchise. Upload katalog terbaru dulu.');
            }
            [$sku, $name, $unit] = [$cat['sku'], $cat['name'], $cat['unit']];
        } else {
            $sku = null;
        }
        $st = $this->pdo->prepare('UPDATE product SET runchise_mode = ?, runchise_sku = ?, runchise_name = ?, runchise_unit = ? WHERE product_id = ?');
        $st->execute([$mode, $sku, $name, $unit, $productId]);
        if ($st->rowCount() === 0 && !$this->exists('product', 'product_id', $productId)) {
            throw new ApiException(404, 'NOT_FOUND', 'Produk tidak ditemukan');
        }
        Audit::write($this->pdo, $requestId, $userId, 'runchise.product_mapped', 'product', (string) $productId, 'ok', null, null, ['mode' => $mode, 'sku' => $sku]);
        return ['productId' => $productId, 'mode' => $mode, 'sku' => $sku, 'runchiseName' => $name, 'unit' => $unit];
    }

    public function setStore(int $storeId, ?string $location, int $userId, ?string $requestId): array
    {
        $location = $location !== null && trim($location) !== '' ? trim($location) : null;
        if ($location !== null && !in_array($location, RunchiseExportService::LOCATIONS, true)) {
            throw new ApiException(400, 'INVALID_LOCATION', 'Lokasi harus salah satu nama lokasi Runchise (huruf persis).');
        }
        if (!$this->exists('store', 'store_id', $storeId)) {
            throw new ApiException(404, 'NOT_FOUND', 'Toko tidak ditemukan');
        }
        $this->pdo->prepare('UPDATE store SET runchise_location = ? WHERE store_id = ?')->execute([$location, $storeId]);
        Audit::write($this->pdo, $requestId, $userId, 'runchise.store_mapped', 'store', (string) $storeId, 'ok', null, null, ['location' => $location]);
        return ['storeId' => $storeId, 'location' => $location];
    }

    private function exists(string $table, string $col, int $id): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE {$col} = ?");
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }
}
