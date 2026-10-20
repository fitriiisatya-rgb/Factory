<?php

declare(strict_types=1);

namespace Amor\Api\Invoice;

use PDO;

/**
 * Persistence for migration 0018's real Invoice generation — wires up the
 * Phase 1 invoice/invoice_item/invoice_shipment tables (schema-v1.sql, zero
 * ALTER, confirmed unused by any live code before this phase) plus this
 * phase's own new invoice_mutasi junction. Never computes anything itself
 * (InvoiceService owns the math) — this class only reads the eligible-qty
 * source rows and writes the generated result.
 */
final class InvoiceRepository
{
    private const ROMAN_MONTHS = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    /** Same numbering machine as DO (Delivery\DoRepository::allocateDoNumber), same format, different prefix. */
    public function allocateInvoiceNumber(PDO $pdo, string $tanggal): string
    {
        [$year, $month] = array_map('intval', explode('-', $tanggal));
        $seq = \Amor\Api\Services\DocumentSequenceService::allocate($pdo, 'INV', $year, $month);
        return sprintf('INV/KRM/%03d/%s/%d', $seq, self::ROMAN_MONTHS[$month - 1], $year);
    }

    /**
     * Gross confirmed-received qty per product for this store in the
     * window — already excludes a verified Reject by construction
     * (received_good_qty + reject_qty + shortage_qty = shipped_qty,
     * migration 0007's own CHECK constraint), and only a shipment whose
     * receipt is confirmed_ok or Admin-verified counts (a still-open
     * confirmed_discrepancy is excluded until Admin resolves it). A
     * shipment already linked into an earlier invoice is excluded so the
     * same qty can never be billed twice.
     *
     * @return array<int,array{productId:int,productName:string,qty:float}>
     */
    public function findEligibleShipmentQtyByProduct(PDO $pdo, int $storeId, string $dateFrom, string $dateTo): array
    {
        $stmt = $pdo->prepare(
            'SELECT sri.product_id, p.name AS product_name, SUM(sri.received_good_qty) AS qty
             FROM shipment_receipt_item sri
             INNER JOIN shipment_receipt sr ON sr.shipment_receipt_id = sri.shipment_receipt_id
             INNER JOIN shipment sh ON sh.shipment_id = sr.shipment_id
             INNER JOIN product p ON p.product_id = sri.product_id
             WHERE sh.store_id = ? AND sh.status = \'active\'
               AND sh.delivery_order_id IS NOT NULL
               AND sr.status IN (\'confirmed_ok\', \'verified\')
               AND sh.tanggal BETWEEN ? AND ?
               AND sri.product_id IS NOT NULL
               AND sh.shipment_id NOT IN (SELECT shipment_id FROM invoice_shipment)
             GROUP BY sri.product_id, p.name'
        );
        $stmt->execute([$storeId, $dateFrom, $dateTo]);
        return array_map(static fn ($r) => [
            'productId' => (int) $r['product_id'], 'productName' => (string) $r['product_name'], 'qty' => (float) $r['qty'],
        ], $stmt->fetchAll());
    }

    /** @return int[] distinct shipment_id list matching the exact same eligibility filter above, to link into invoice_shipment. */
    public function findEligibleShipmentIds(PDO $pdo, int $storeId, string $dateFrom, string $dateTo): array
    {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT sh.shipment_id
             FROM shipment_receipt_item sri
             INNER JOIN shipment_receipt sr ON sr.shipment_receipt_id = sri.shipment_receipt_id
             INNER JOIN shipment sh ON sh.shipment_id = sr.shipment_id
             WHERE sh.store_id = ? AND sh.status = \'active\'
               AND sh.delivery_order_id IS NOT NULL
               AND sr.status IN (\'confirmed_ok\', \'verified\')
               AND sh.tanggal BETWEEN ? AND ?
               AND sh.shipment_id NOT IN (SELECT shipment_id FROM invoice_shipment)'
        );
        $stmt->execute([$storeId, $dateFrom, $dateTo]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Completed Mutasi where this store is the SOURCE — qty_received
     * (the future-invoice-authoritative figure, per migration 0017's own
     * LOCKED rule) moves OUT of this store's billable pool. A mutasi
     * already consumed on the 'out' side by an earlier invoice (either
     * this store's own, or impossible by construction since source_store_id
     * is fixed per row) is excluded.
     *
     * @return array<int,array{productId:int,productName:string,qty:float}>
     */
    public function findEligibleMutasiOutByProduct(PDO $pdo, int $storeId, string $dateFrom, string $dateTo): array
    {
        return $this->findEligibleMutasiByProduct($pdo, 'source_store_id', $storeId, $dateFrom, $dateTo, 'out');
    }

    /** Completed Mutasi where this store is the DESTINATION — qty_received moves IN to this store's billable pool. */
    public function findEligibleMutasiInByProduct(PDO $pdo, int $storeId, string $dateFrom, string $dateTo): array
    {
        return $this->findEligibleMutasiByProduct($pdo, 'destination_store_id', $storeId, $dateFrom, $dateTo, 'in');
    }

    private function findEligibleMutasiByProduct(PDO $pdo, string $storeColumn, int $storeId, string $dateFrom, string $dateTo, string $direction): array
    {
        $stmt = $pdo->prepare(
            "SELECT mr.product_id, p.name AS product_name, SUM(mr.qty_received) AS qty
             FROM mutasi_request mr
             INNER JOIN product p ON p.product_id = mr.product_id
             WHERE mr.{$storeColumn} = ? AND mr.status = 'completed'
               AND DATE(mr.confirmed_at) BETWEEN ? AND ?
               AND mr.mutasi_request_id NOT IN (SELECT mutasi_request_id FROM invoice_mutasi WHERE direction = ?)
               AND (? = 'in' OR mr.mutasi_request_id NOT IN (SELECT mutasi_request_id FROM nota_potongan WHERE mutasi_request_id IS NOT NULL))
             GROUP BY mr.product_id, p.name"
        );
        $stmt->execute([$storeId, $dateFrom, $dateTo, $direction, $direction]);
        return array_map(static fn ($r) => [
            'productId' => (int) $r['product_id'], 'productName' => (string) $r['product_name'], 'qty' => (float) $r['qty'],
        ], $stmt->fetchAll());
    }

    /** @return int[] */
    public function findEligibleMutasiIds(PDO $pdo, string $storeColumn, int $storeId, string $dateFrom, string $dateTo, string $direction): array
    {
        $stmt = $pdo->prepare(
            "SELECT mutasi_request_id FROM mutasi_request mr
             WHERE mr.{$storeColumn} = ? AND mr.status = 'completed'
               AND DATE(mr.confirmed_at) BETWEEN ? AND ?
               AND mr.mutasi_request_id NOT IN (SELECT mutasi_request_id FROM invoice_mutasi WHERE direction = ?)
               AND (? = 'in' OR mr.mutasi_request_id NOT IN (SELECT mutasi_request_id FROM nota_potongan WHERE mutasi_request_id IS NOT NULL))"
        );
        $stmt->execute([$storeId, $dateFrom, $dateTo, $direction, $direction]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Deliveries (regular DO or special DO) that can be invoiced now: at
     * least one not-yet-invoiced active shipment whose receipt is confirmed
     * (confirmed_ok, or Admin-verified), and NO shipment of the same DO
     * still waiting for the store or for Admin — so a DO normally gets
     * exactly one invoice.
     *
     * @param string $column 'delivery_order_id' | 'special_order_do_id'
     * @return array<int,array{refId:int,storeId:int}>
     */
    public function findReadyDeliveries(PDO $pdo, string $column): array
    {
        // Pesanan Non-Toko goods only pass through a drop bakery; they are
        // never billed to it (owner decision Oct 2026).
        $billable = $column === 'special_order_do_id'
            ? "AND EXISTS (SELECT 1 FROM special_order_do sod WHERE sod.special_order_do_id = sh.special_order_do_id AND sod.source_type = 'toko_khusus')"
            : '';
        $rows = $pdo->query(
            "SELECT sh.{$column} AS ref_id, sh.store_id,
                    SUM(CASE WHEN sr.status IN ('confirmed_ok','verified') THEN 1 ELSE 0 END) AS ok_count,
                    SUM(CASE WHEN sr.status IN ('confirmed_ok','verified') THEN 0 ELSE 1 END) AS waiting_count
             FROM shipment sh
             LEFT JOIN shipment_receipt sr ON sr.shipment_id = sh.shipment_id
             WHERE sh.status = 'active' AND sh.{$column} IS NOT NULL
               AND sh.shipment_id NOT IN (SELECT shipment_id FROM invoice_shipment)
               {$billable}
             GROUP BY sh.{$column}, sh.store_id
             HAVING ok_count > 0 AND waiting_count = 0
             ORDER BY MIN(sh.tanggal), sh.{$column}"
        )->fetchAll();
        return array_map(static fn ($r) => ['refId' => (int) $r['ref_id'], 'storeId' => (int) $r['store_id']], $rows);
    }

    /** @return array<int,array{shipment_id:int,tanggal:string}> not-yet-invoiced confirmed shipments of one DO for one store */
    public function findDeliveryShipments(PDO $pdo, string $column, int $refId, int $storeId): array
    {
        $stmt = $pdo->prepare(
            "SELECT sh.shipment_id, sh.tanggal FROM shipment sh
             INNER JOIN shipment_receipt sr ON sr.shipment_id = sh.shipment_id
             WHERE sh.{$column} = ? AND sh.store_id = ? AND sh.status = 'active'
               AND sr.status IN ('confirmed_ok','verified')
               AND sh.shipment_id NOT IN (SELECT shipment_id FROM invoice_shipment)
             ORDER BY sh.tanggal, sh.shipment_id"
        );
        $stmt->execute([$refId, $storeId]);
        return $stmt->fetchAll();
    }

    /** @return array<int,array{productId:int,productName:string,qty:float}> received-good qty per product across the given shipments */
    public function findQtyByProductForShipments(PDO $pdo, array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($shipmentIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT sri.product_id, p.name AS product_name, SUM(sri.received_good_qty) AS qty
             FROM shipment_receipt_item sri
             INNER JOIN shipment_receipt sr ON sr.shipment_receipt_id = sri.shipment_receipt_id
             INNER JOIN product p ON p.product_id = sri.product_id
             WHERE sr.shipment_id IN ({$in}) AND sri.product_id IS NOT NULL
             GROUP BY sri.product_id, p.name"
        );
        $stmt->execute(array_values($shipmentIds));
        return array_map(static fn ($r) => [
            'productId' => (int) $r['product_id'], 'productName' => (string) $r['product_name'], 'qty' => (float) $r['qty'],
        ], $stmt->fetchAll());
    }

    /** Completed Mutasi not yet invoiced to the DESTINATION store. */
    public function findMutasiReadyForInvoice(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT mr.*, p.name AS product_name FROM mutasi_request mr
             INNER JOIN product p ON p.product_id = mr.product_id
             WHERE mr.status = 'completed' AND COALESCE(mr.qty_received, 0) > 0
               AND mr.mutasi_request_id NOT IN (SELECT mutasi_request_id FROM invoice_mutasi WHERE direction = 'in')
             ORDER BY mr.mutasi_request_id"
        )->fetchAll();
    }

    /** Completed Mutasi whose SOURCE store has neither a nota nor a period-invoice deduction yet. */
    public function findMutasiReadyForNota(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT mr.* FROM mutasi_request mr
             WHERE mr.status = 'completed' AND COALESCE(mr.qty_received, 0) > 0
               AND mr.mutasi_request_id NOT IN (SELECT mutasi_request_id FROM invoice_mutasi WHERE direction = 'out')
               AND mr.mutasi_request_id NOT IN (SELECT mutasi_request_id FROM nota_potongan WHERE mutasi_request_id IS NOT NULL)
             ORDER BY mr.mutasi_request_id"
        )->fetchAll();
    }

    /** @return array<int,array> notas issued against this invoice */
    public function findNotasForInvoice(PDO $pdo, int $invoiceId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM nota_potongan WHERE invoice_id = ? ORDER BY nota_potongan_id');
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    /** @return array{id:int} */
    public function insertInvoice(PDO $pdo, string $invoiceNo, string $tanggal, int $storeId, float $total, string $sourceKind = 'periode', ?int $sourceRefId = null): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO invoice (invoice_no, batch, tanggal, store_id, total, rate_pct, rate_source, sumber, legacy_fulfillment_status, source_kind, source_ref_id, version, created_at)
             VALUES (?, ?, ?, ?, ?, 100.00, \'default\', \'kirim\', \'verified\', ?, ?, 1, UTC_TIMESTAMP())'
        );
        // batch reuses invoice_no verbatim (both already globally unique via
        // the same DocumentSequenceService allocation) — see this
        // migration's own schema-v1-0018-invoice.sql docblock for why this
        // never needed a second, separately-meaningful value.
        $stmt->execute([$invoiceNo, $invoiceNo, $tanggal, $storeId, $total, $sourceKind, $sourceRefId]);
        return (int) $pdo->lastInsertId();
    }

    public function insertInvoiceItem(PDO $pdo, int $invoiceId, ?int $productId, float $qtyDo, float $qtyInvoice, float $harga, float $subtotal,
        ?string $itemName = null, ?int $specialOrderItemId = null, ?int $divisionId = null, float $extraAmount = 0.0): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO invoice_item (invoice_id, product_id, item_name, special_order_item_id, division_id, qty_do, qty_invoice, harga, subtotal, extra_amount, rate_pct, rate_source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 100.00, \'default\')'
        );
        $stmt->execute([$invoiceId, $productId, $itemName, $specialOrderItemId, $divisionId, $qtyDo, $qtyInvoice, $harga, $subtotal, $extraAmount]);
    }

    /**
     * Pesanan Khusus Toko lines for these shipments: received-good qty per
     * ORDER LINE, with the order's own price, charge and extra packaging.
     * extra_charged = whether that order line's charge/packaging was already
     * billed on an earlier invoice (it is billed once).
     * @return array<int,array>
     */
    public function findSpecialLinesForShipments(PDO $pdo, array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($shipmentIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT soi.special_order_item_id, soi.product_id, soi.item_name_snapshot, soi.division_id,
                    soi.unit_price, soi.charge, soi.extra_packaging,
                    SUM(sri.received_good_qty) AS qty, SUM(sri.shipped_qty) AS qty_do,
                    EXISTS (SELECT 1 FROM invoice_item ii WHERE ii.special_order_item_id = soi.special_order_item_id) AS extra_charged
             FROM shipment_receipt_item sri
             INNER JOIN shipment_receipt sr ON sr.shipment_receipt_id = sri.shipment_receipt_id
             INNER JOIN special_order_do_shipment_item sdsi ON sdsi.special_order_do_shipment_item_id = sri.special_order_do_shipment_item_id
             INNER JOIN special_order_do_item sdi ON sdi.special_order_do_item_id = sdsi.special_order_do_item_id
             INNER JOIN special_order_item soi ON soi.special_order_item_id = sdi.special_order_item_id
             WHERE sr.shipment_id IN ({$in})
             GROUP BY soi.special_order_item_id, soi.product_id, soi.item_name_snapshot, soi.division_id, soi.unit_price, soi.charge, soi.extra_packaging"
        );
        $stmt->execute(array_values($shipmentIds));
        return $stmt->fetchAll();
    }

    public function linkShipment(PDO $pdo, int $invoiceId, int $shipmentId): void
    {
        $pdo->prepare('INSERT INTO invoice_shipment (invoice_id, shipment_id) VALUES (?, ?)')->execute([$invoiceId, $shipmentId]);
    }

    public function linkMutasi(PDO $pdo, int $invoiceId, int $mutasiRequestId, string $direction, float $qty): void
    {
        $pdo->prepare('INSERT INTO invoice_mutasi (invoice_id, mutasi_request_id, direction, qty) VALUES (?, ?, ?, ?)')
            ->execute([$invoiceId, $mutasiRequestId, $direction, $qty]);
    }

    public function findById(PDO $pdo, int $invoiceId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT i.*, s.canonical_name AS store_name, s.email AS store_email
             FROM invoice i INNER JOIN store s ON s.store_id = i.store_id
             WHERE i.invoice_id = ?'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array> ordered by product name */
    public function findItemsForInvoice(PDO $pdo, int $invoiceId): array
    {
        $stmt = $pdo->prepare(
            'SELECT ii.*, COALESCE(p.name, ii.item_name) AS product_name FROM invoice_item ii
             LEFT JOIN product p ON p.product_id = ii.product_id
             WHERE ii.invoice_id = ? ORDER BY COALESCE(p.name, ii.item_name)'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    /**
     * doc_no doesn't exist directly on shipment — pull the DO's own doc_no
     * when this shipment came from a Regular PO delivery_order; a special/
     * replacement shipment has no delivery_order_id and simply shows no
     * doc_no here (same "optional" contract the print template already
     * expects — see print-invoice-template.php).
     *
     * @return array<int,array>
     */
    public function findShipmentsForInvoice(PDO $pdo, int $invoiceId): array
    {
        $stmt = $pdo->prepare(
            'SELECT sh.shipment_id, sh.tanggal, COALESCE(do2.doc_no, sdo.doc_no) AS do_doc_no
             FROM invoice_shipment iis
             INNER JOIN shipment sh ON sh.shipment_id = iis.shipment_id
             LEFT JOIN delivery_order do2 ON do2.delivery_order_id = sh.delivery_order_id
             LEFT JOIN special_order_do sdo ON sdo.special_order_do_id = sh.special_order_do_id
             WHERE iis.invoice_id = ? ORDER BY sh.tanggal'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    /** @return array<int,array> */
    public function findMutasiForInvoice(PDO $pdo, int $invoiceId): array
    {
        $stmt = $pdo->prepare(
            'SELECT im.direction, im.qty, mr.doc_no, mr.product_id, p.name AS product_name,
                    ss.canonical_name AS source_store_name, ds.canonical_name AS destination_store_name
             FROM invoice_mutasi im
             INNER JOIN mutasi_request mr ON mr.mutasi_request_id = im.mutasi_request_id
             INNER JOIN product p ON p.product_id = mr.product_id
             INNER JOIN store ss ON ss.store_id = mr.source_store_id
             INNER JOIN store ds ON ds.store_id = mr.destination_store_id
             WHERE im.invoice_id = ? ORDER BY mr.doc_no'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    /** @return array<int,array> newest first; $storeId/$dateFrom/$dateTo are optional filters. */
    public function listAll(PDO $pdo, ?int $storeId, ?string $dateFrom, ?string $dateTo): array
    {
        $sql = 'SELECT i.invoice_id, i.invoice_no, i.tanggal, i.store_id, i.total, i.source_kind, i.created_at, s.canonical_name AS store_name,
                       (SELECT COALESCE(SUM(n.total), 0) FROM nota_potongan n WHERE n.invoice_id = i.invoice_id) AS nota_total
                FROM invoice i INNER JOIN store s ON s.store_id = i.store_id WHERE 1=1';
        $params = [];
        if ($storeId !== null) { $sql .= ' AND i.store_id = ?'; $params[] = $storeId; }
        if ($dateFrom !== null) { $sql .= ' AND i.tanggal >= ?'; $params[] = $dateFrom; }
        if ($dateTo !== null) { $sql .= ' AND i.tanggal <= ?'; $params[] = $dateTo; }
        $sql .= ' ORDER BY i.tanggal DESC, i.invoice_id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Hard delete — cascades to invoice_item/invoice_shipment/invoice_mutasi (InvoiceService::void() unlinks Nota Potongan first), releasing their rows back to the eligible pool. See this migration's own schema docblock for why this is safe (nothing references a generated Invoice yet). */
    public function deleteInvoice(PDO $pdo, int $invoiceId): void
    {
        $pdo->prepare('DELETE FROM invoice WHERE invoice_id = ?')->execute([$invoiceId]);
    }
}
