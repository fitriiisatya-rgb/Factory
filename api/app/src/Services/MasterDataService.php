<?php

declare(strict_types=1);

namespace Amor\Api\Services;

use Amor\Api\ApiException;
use PDO;
use PDOException;

/**
 * Master Data: add / edit / delete for divisions, factories and store
 * aliases, and delete for products and stores (their add/edit stay on the
 * existing Product/StoreController endpoints).
 *
 * Delete is only allowed while nothing references the record: every table
 * in this database that has a column pointing at it (found through
 * information_schema, so a table added later is covered too) must have
 * zero rows for it. Rows that only configure the record (aliases, portal
 * links, user access) are removed with it. A product or store that is
 * already used is never deleted — it can be set Nonaktif instead, so
 * history, invoices and reports stay intact.
 */
final class MasterDataService
{
    /** Columns that point at each master table, and the tables that are removed together with the record. */
    private const ENTITIES = [
        'product' => ['table' => 'product', 'pk' => 'product_id', 'columns' => ['product_id'], 'owned' => ['product_alias', 'product_legacy_code'], 'label' => 'Produk'],
        'store' => ['table' => 'store', 'pk' => 'store_id', 'columns' => ['store_id', 'source_store_id', 'destination_store_id', 'drop_store_id', 'from_store_id', 'to_store_id'], 'owned' => ['store_alias', 'store_portal_token', 'user_store_access'], 'label' => 'Toko'],
        'division' => ['table' => 'division', 'pk' => 'division_id', 'columns' => ['division_id'], 'owned' => ['user_division_access'], 'label' => 'Divisi'],
        'factory' => ['table' => 'factory', 'pk' => 'factory_id', 'columns' => ['factory_id'], 'owned' => ['user_factory_access'], 'label' => 'Pabrik'],
    ];

    /** Friendly names for "sudah dipakai di …". */
    private const TABLE_LABELS = [
        'po_item' => 'PO', 'po_store_item' => 'PO', 'po_batch' => 'PO', 'po_import' => 'PO', 'po_closure' => 'PO',
        'production_item' => 'Produksi', 'production_run' => 'Produksi', 'production_handover_item' => 'Serah terima',
        'fg_item' => 'FG & Packing', 'fg_batch' => 'FG & Packing', 'fg_store_packing_submission' => 'FG & Packing',
        'stock_ledger' => 'Stok', 'stock_balance' => 'Stok', 'store_fg_balance' => 'Stok', 'stock_adjustment' => 'Stok', 'stock_transfer' => 'Stok',
        'delivery_order' => 'DO', 'delivery_order_item' => 'DO', 'shipment' => 'Pengiriman', 'shipment_item' => 'Pengiriman',
        'shipment_receipt_item' => 'Konfirmasi toko', 'shipment_email_delivery' => 'Pengiriman', 'dispatch_claim' => 'Pengiriman', 'driver_route_stop' => 'Pengiriman',
        'invoice' => 'Invoice', 'invoice_item' => 'Invoice', 'nota_potongan' => 'Nota potongan', 'nota_potongan_item' => 'Nota potongan',
        'retur_request' => 'Retur/Reject', 'mutasi_request' => 'Mutasi', 'store_report' => 'Retur/Reject',
        'special_order' => 'Pesanan khusus', 'special_order_item' => 'Pesanan khusus', 'special_order_do' => 'Pesanan khusus', 'special_order_catalog' => 'Katalog pesanan khusus',
        'special_order_fg_allocation' => 'Pesanan khusus', 'replacement_demand' => 'Kirim ulang', 'replacement_do' => 'Kirim ulang', 'replacement_demand_fg_allocation' => 'Kirim ulang',
        'product' => 'Produk', 'division' => 'Divisi', 'location' => 'Lokasi stok',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{deleted:bool} */
    public function delete(string $entity, int $id): array
    {
        $e = self::ENTITIES[$entity];
        $row = $this->pdo->prepare("SELECT * FROM {$e['table']} WHERE {$e['pk']} = ? FOR UPDATE");
        $row->execute([$id]);
        $record = $row->fetch();
        if ($record === false) {
            throw new ApiException(404, 'NOT_FOUND', "{$e['label']} tidak ditemukan.");
        }
        $this->guardProtected($entity, $record);

        $used = $this->usage($entity, $id);
        if ($used !== []) {
            $where = implode(', ', array_map(static fn ($l, $n) => "{$l} ({$n})", array_keys($used), $used));
            $hint = in_array($entity, ['product', 'store'], true) ? ' Nonaktifkan saja (Edit → Status: Nonaktif) supaya riwayatnya tetap utuh.' : '';
            throw new ApiException(409, 'MASTER_IN_USE', "{$e['label']} ini sudah dipakai di {$where} — tidak bisa dihapus.{$hint}");
        }

        foreach ($e['owned'] as $t) {
            if ($this->tableExists($t)) {
                $this->pdo->prepare("DELETE FROM {$t} WHERE {$e['pk']} = ?")->execute([$id]);
            }
        }
        if ($entity === 'factory') {
            $this->pdo->prepare('DELETE FROM location WHERE factory_id = ?')->execute([$id]);
        }
        $this->pdo->prepare("DELETE FROM {$e['table']} WHERE {$e['pk']} = ?")->execute([$id]);
        return ['deleted' => true];
    }

    /** @return array<string,int> friendly label => row count of everything still pointing at this record */
    public function usage(string $entity, int $id): array
    {
        $e = self::ENTITIES[$entity];
        $placeholders = implode(',', array_fill(0, count($e['columns']), '?'));
        $cols = $this->pdo->prepare(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND column_name IN ({$placeholders})"
        );
        $cols->execute($e['columns']);
        $skip = array_merge([$e['table']], $e['owned'], $entity === 'factory' ? ['location'] : []);
        $used = [];
        foreach ($cols->fetchAll(PDO::FETCH_NUM) as [$table, $column]) {
            if (in_array($table, $skip, true)) {
                continue;
            }
            $n = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?");
            $n->execute([$id]);
            $count = (int) $n->fetchColumn();
            if ($count > 0) {
                $label = self::TABLE_LABELS[$table] ?? $table;
                $used[$label] = ($used[$label] ?? 0) + $count;
            }
        }
        if ($entity === 'factory') {
            // Stock rows point at the factory's FG location, not at the factory itself.
            $n = $this->pdo->prepare('SELECT COUNT(*) FROM stock_ledger sl INNER JOIN location l ON l.location_id = sl.location_id WHERE l.factory_id = ?');
            $n->execute([$id]);
            if (($c = (int) $n->fetchColumn()) > 0) {
                $used['Stok'] = ($used['Stok'] ?? 0) + $c;
            }
        }
        return $used;
    }

    public function createDivision(string $name, int $factoryId, bool $isVerification): array
    {
        $name = $this->requireName($name, 'Nama divisi');
        $this->requireFactory($factoryId);
        try {
            $this->pdo->prepare('INSERT INTO division (name, factory_id, is_verification) VALUES (?, ?, ?)')->execute([$name, $factoryId, $isVerification ? 1 : 0]);
        } catch (PDOException $ex) {
            $this->duplicate($ex, 'Nama divisi ini sudah ada.');
        }
        return $this->division((int) $this->pdo->lastInsertId());
    }

    public function renameDivision(int $id, string $name): array
    {
        $name = $this->requireName($name, 'Nama divisi');
        $d = $this->division($id);
        if ($d['name'] === 'Cake & Custom' && $name !== $d['name']) {
            throw new ApiException(409, 'MASTER_PROTECTED', 'Nama divisi "Cake & Custom" dipakai sistem untuk Pesanan Khusus — tidak bisa diganti.');
        }
        try {
            $this->pdo->prepare('UPDATE division SET name = ? WHERE division_id = ?')->execute([$name, $id]);
        } catch (PDOException $ex) {
            $this->duplicate($ex, 'Nama divisi ini sudah ada.');
        }
        return $this->division($id);
    }

    public function createFactory(string $code, string $name): array
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{2,10}$/', $code) !== 1) {
            throw new ApiException(400, 'INVALID_CODE', 'Kode pabrik harus 2–10 huruf, misalnya KTG.');
        }
        $name = $this->requireName($name, 'Nama pabrik');
        try {
            $this->pdo->prepare('INSERT INTO factory (code, name) VALUES (?, ?)')->execute([$code, $name]);
        } catch (PDOException $ex) {
            $this->duplicate($ex, 'Kode atau nama pabrik ini sudah ada.');
        }
        return $this->requireFactory((int) $this->pdo->lastInsertId());
    }

    /** Name only: the code is printed on documents (DO/…-KTG) and identifies the factory to the PO importer. */
    public function renameFactory(int $id, string $name): array
    {
        $name = $this->requireName($name, 'Nama pabrik');
        $this->requireFactory($id);
        try {
            $this->pdo->prepare('UPDATE factory SET name = ? WHERE factory_id = ?')->execute([$name, $id]);
        } catch (PDOException $ex) {
            $this->duplicate($ex, 'Nama pabrik ini sudah ada.');
        }
        return $this->requireFactory($id);
    }

    public function updateStoreAlias(int $id, string $rawName, int $storeId, ?string $factoryHint): array
    {
        $rawName = $this->requireName($rawName, 'Nama alias');
        if ($factoryHint !== null && !in_array($factoryHint, ['karangtengah', 'cibadak'], true)) {
            throw new ApiException(400, 'INVALID_FACTORY_HINT', 'Petunjuk pabrik harus karangtengah, cibadak, atau kosong.');
        }
        $s = $this->pdo->prepare('SELECT store_id FROM store WHERE store_id = ?');
        $s->execute([$storeId]);
        if ($s->fetchColumn() === false) {
            throw new ApiException(404, 'NOT_FOUND', 'Toko tujuan alias tidak ditemukan.');
        }
        try {
            $st = $this->pdo->prepare('UPDATE store_alias SET raw_name = ?, store_id = ?, factory_hint = ? WHERE store_alias_id = ?');
            $st->execute([$rawName, $storeId, $factoryHint, $id]);
        } catch (PDOException $ex) {
            $this->duplicate($ex, 'Nama alias ini sudah dipakai untuk toko lain.');
        }
        if ($st->rowCount() === 0 && $this->alias($id) === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Alias tidak ditemukan.');
        }
        return $this->alias($id);
    }

    public function deleteStoreAlias(int $id): void
    {
        if ($this->alias($id) === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Alias tidak ditemukan.');
        }
        $this->pdo->prepare('DELETE FROM store_alias WHERE store_alias_id = ?')->execute([$id]);
    }

    private function guardProtected(string $entity, array $record): void
    {
        if ($entity === 'store' && $record['canonical_name'] === 'NON-OUTLET / PERORANGAN') {
            throw new ApiException(409, 'MASTER_PROTECTED', 'Toko "NON-OUTLET / PERORANGAN" dipakai sistem untuk stok yang belum milik toko — tidak bisa dihapus.');
        }
        if ($entity === 'division' && ($record['name'] === 'Cake & Custom' || (int) $record['is_verification'] === 1)) {
            throw new ApiException(409, 'MASTER_PROTECTED', "Divisi \"{$record['name']}\" dipakai sistem (FG / Pesanan Khusus) — tidak bisa dihapus.");
        }
    }

    private function requireName(string $name, string $what): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException(400, 'MISSING_NAME', "{$what} wajib diisi.");
        }
        return $name;
    }

    private function requireFactory(int $id): array
    {
        $st = $this->pdo->prepare('SELECT factory_id, code, name FROM factory WHERE factory_id = ?');
        $st->execute([$id]);
        $f = $st->fetch();
        if ($f === false) {
            throw new ApiException(404, 'NOT_FOUND', 'Pabrik tidak ditemukan.');
        }
        return $f;
    }

    private function division(int $id): array
    {
        $st = $this->pdo->prepare('SELECT division_id, name, factory_id, is_verification FROM division WHERE division_id = ?');
        $st->execute([$id]);
        $d = $st->fetch();
        if ($d === false) {
            throw new ApiException(404, 'NOT_FOUND', 'Divisi tidak ditemukan.');
        }
        return $d;
    }

    private function alias(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT store_alias_id, raw_name, store_id, factory_hint FROM store_alias WHERE store_alias_id = ?');
        $st->execute([$id]);
        $a = $st->fetch();
        return $a === false ? null : $a;
    }

    private function tableExists(string $table): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$table]);
        return $st->fetchColumn() !== false;
    }

    private function duplicate(PDOException $ex, string $message): never
    {
        if ((int) $ex->getCode() === 23000) {
            throw new ApiException(409, 'DUPLICATE_NAME', $message);
        }
        throw $ex;
    }
}
