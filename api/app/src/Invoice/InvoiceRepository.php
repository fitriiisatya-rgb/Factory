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
             GROUP BY mr.product_id, p.name"
        );
        $stmt->execute([$storeId, $dateFrom, $dateTo, $direction]);
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
               AND mr.mutasi_request_id NOT IN (SELECT mutasi_request_id FROM invoice_mutasi WHERE direction = ?)"
        );
        $stmt->execute([$storeId, $dateFrom, $dateTo, $direction]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array{id:int} */
    public function insertInvoice(PDO $pdo, string $invoiceNo, string $tanggal, int $storeId, float $total): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO invoice (invoice_no, batch, tanggal, store_id, total, rate_pct, rate_source, sumber, legacy_fulfillment_status, version, created_at)
             VALUES (?, ?, ?, ?, ?, 100.00, \'default\', \'kirim\', \'verified\', 1, UTC_TIMESTAMP())'
        );
        // batch reuses invoice_no verbatim (both already globally unique via
        // the same DocumentSequenceService allocation) — see this
        // migration's own schema-v1-0018-invoice.sql docblock for why this
        // never needed a second, separately-meaningful value.
        $stmt->execute([$invoiceNo, $invoiceNo, $tanggal, $storeId, $total]);
        return (int) $pdo->lastInsertId();
    }

    public function insertInvoiceItem(PDO $pdo, int $invoiceId, int $productId, float $qtyDo, float $qtyInvoice, float $harga, float $subtotal): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO invoice_item (invoice_id, product_id, qty_do, qty_invoice, harga, subtotal, rate_pct, rate_source)
             VALUES (?, ?, ?, ?, ?, ?, 100.00, \'default\')'
        );
        $stmt->execute([$invoiceId, $productId, $qtyDo, $qtyInvoice, $harga, $subtotal]);
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
            'SELECT ii.*, p.name AS product_name FROM invoice_item ii
             INNER JOIN product p ON p.product_id = ii.product_id
             WHERE ii.invoice_id = ? ORDER BY p.name'
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
            'SELECT sh.shipment_id, sh.tanggal, do2.doc_no AS do_doc_no
             FROM invoice_shipment iis
             INNER JOIN shipment sh ON sh.shipment_id = iis.shipment_id
             LEFT JOIN delivery_order do2 ON do2.delivery_order_id = sh.delivery_order_id
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
        $sql = 'SELECT i.invoice_id, i.invoice_no, i.tanggal, i.store_id, i.total, i.created_at, s.canonical_name AS store_name
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

    /** Hard delete — cascades to invoice_item/invoice_shipment/invoice_mutasi, releasing their rows back to the eligible pool. See this migration's own schema docblock for why this is safe (nothing references a generated Invoice yet). */
    public function deleteInvoice(PDO $pdo, int $invoiceId): void
    {
        $pdo->prepare('DELETE FROM invoice WHERE invoice_id = ?')->execute([$invoiceId]);
    }
}
