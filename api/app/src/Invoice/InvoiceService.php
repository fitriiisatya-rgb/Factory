<?php

declare(strict_types=1);

namespace Amor\Api\Invoice;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Repositories\StoreRepository;
use PDO;

/**
 * Invoice generation from real DO/Shipment data (migration 0018).
 * Business rules confirmed with the user before this was built:
 *
 *  - Billable qty per product = the store's confirmed-received qty
 *    (receivedGoodQty — already excludes a verified Reject by
 *    construction) across every Shipment in the chosen window that
 *    hasn't already been billed on an earlier Invoice, NET of completed
 *    Mutasi movement: a completed Mutasi moves its qty_received fully
 *    from the SOURCE store's own billable pool to the DESTINATION
 *    store's — never double-billed, never dropped, on either side.
 *  - rate_pct/rate_source/override_reason exist on the underlying Phase 1
 *    tables (schema-v1.sql) but are explicitly NOT used by this phase —
 *    every row this class writes carries rate_pct=100.00 (full price, no
 *    adjustment), a placeholder for a future consignment/discount
 *    feature this phase never computes or surfaces.
 *  - ONE Invoice can bundle MANY Shipments for one store across a chosen
 *    date range (confirmed with the user) — never forced to one
 *    Invoice per Shipment.
 *  - preview() is read-only (never writes, never allocates a number) so
 *    an Admin can review the computed lines before committing — same
 *    "preview then commit" discipline as ShipmentService::preview()/ship().
 *  - generate() always RECOMPUTES the eligible qty server-side inside its
 *    own transaction — it never trusts a client-submitted total or item
 *    list, exactly like every other mutating endpoint in this app.
 */
final class InvoiceService
{
    private InvoiceRepository $repo;
    private StoreRepository $storeRepo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new InvoiceRepository();
        $this->storeRepo = new StoreRepository();
    }

    /**
     * Read-only computation of what a generate() call for this store/
     * window would produce — never writes anything.
     *
     * @return array{storeId:int,storeName:string,dateFrom:string,dateTo:string,items:array,total:float,shipmentCount:int,mutasiOutCount:int,mutasiInCount:int}
     */
    public function preview(int $storeId, string $dateFrom, string $dateTo): array
    {
        $store = $this->requireStore($storeId);
        $this->requireValidRange($dateFrom, $dateTo);

        $lines = $this->computeLines($storeId, $dateFrom, $dateTo);
        $shipmentIds = $this->repo->findEligibleShipmentIds($this->pdo, $storeId, $dateFrom, $dateTo);
        $mutasiOutIds = $this->repo->findEligibleMutasiIds($this->pdo, 'source_store_id', $storeId, $dateFrom, $dateTo, 'out');
        $mutasiInIds = $this->repo->findEligibleMutasiIds($this->pdo, 'destination_store_id', $storeId, $dateFrom, $dateTo, 'in');

        return [
            'storeId' => $storeId,
            'storeName' => (string) $store['canonical_name'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'items' => $lines['items'],
            'total' => $lines['total'],
            'shipmentCount' => count($shipmentIds),
            'mutasiOutCount' => count($mutasiOutIds),
            'mutasiInCount' => count($mutasiInIds),
        ];
    }

    /**
     * Transactional commit — recomputes the exact same lines preview()
     * would show (never trusts anything the caller sent), inserts the
     * invoice + invoice_item rows, and links every consumed shipment/
     * mutasi so none of it can ever be billed again.
     *
     * @throws ApiException 400 EMPTY_INVOICE if there is nothing eligible to bill
     * @return array full getDetail() DTO for the newly created invoice
     */
    public function generate(int $storeId, string $dateFrom, string $dateTo, int $adminUserId, ?string $requestId): array
    {
        $this->requireStore($storeId);
        $this->requireValidRange($dateFrom, $dateTo);

        $pdo = $this->pdo;
        $lines = $this->computeLines($storeId, $dateFrom, $dateTo);
        if ($lines['items'] === []) {
            throw new ApiException(400, 'EMPTY_INVOICE', 'Tidak ada pengiriman/mutasi yang bisa ditagihkan untuk toko dan periode ini.');
        }

        $invoiceNo = $this->repo->allocateInvoiceNumber($pdo, $dateTo);
        $invoiceId = $this->repo->insertInvoice($pdo, $invoiceNo, $dateTo, $storeId, $lines['total']);

        foreach ($lines['items'] as $item) {
            $this->repo->insertInvoiceItem($pdo, $invoiceId, $item['productId'], $item['qtyDo'], $item['qtyInvoice'], $item['harga'], $item['subtotal']);
        }

        $shipmentIds = $this->repo->findEligibleShipmentIds($pdo, $storeId, $dateFrom, $dateTo);
        foreach ($shipmentIds as $shipmentId) {
            $this->repo->linkShipment($pdo, $invoiceId, $shipmentId);
        }

        foreach ($this->fetchMutasiRows($pdo, 'source_store_id', $storeId, $dateFrom, $dateTo, 'out') as $m) {
            $this->repo->linkMutasi($pdo, $invoiceId, $m['mutasi_request_id'], 'out', (float) $m['qty_received']);
        }
        foreach ($this->fetchMutasiRows($pdo, 'destination_store_id', $storeId, $dateFrom, $dateTo, 'in') as $m) {
            $this->repo->linkMutasi($pdo, $invoiceId, $m['mutasi_request_id'], 'in', (float) $m['qty_received']);
        }

        Audit::write(
            $pdo, $requestId, $adminUserId, 'invoice.generated', 'invoice', (string) $invoiceId, 'ok', null, null,
            ['storeId' => $storeId, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'total' => $lines['total'], 'shipmentCount' => count($shipmentIds)]
        );

        return $this->buildDetailDto($pdo, $invoiceId);
    }

    /** @throws ApiException 404 NOT_FOUND */
    public function getDetail(int $invoiceId): array
    {
        return $this->buildDetailDto($this->pdo, $invoiceId);
    }

    /** @return array<int,array> */
    public function listAll(?int $storeId, ?string $dateFrom, ?string $dateTo): array
    {
        return array_map(static fn ($r) => [
            'invoiceId' => (int) $r['invoice_id'],
            'invoiceNo' => (string) $r['invoice_no'],
            'tanggal' => (string) $r['tanggal'],
            'storeId' => (int) $r['store_id'],
            'storeName' => (string) $r['store_name'],
            'total' => (float) $r['total'],
            'createdAt' => (string) $r['created_at'],
        ], $this->repo->listAll($this->pdo, $storeId, $dateFrom, $dateTo));
    }

    /**
     * Hard-deletes the invoice (cascades to its items/shipment-links/
     * mutasi-links), releasing every shipment/mutasi it had consumed back
     * into the eligible pool for a future Invoice. See this migration's
     * own schema docblock for why a hard delete is safe at this stage
     * (nothing — no Payment — references a generated Invoice yet).
     *
     * @throws ApiException 404 NOT_FOUND
     */
    public function void(int $invoiceId, int $adminUserId, string $reason, ?string $requestId): void
    {
        $existing = $this->repo->findById($this->pdo, $invoiceId);
        if ($existing === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Invoice tidak ditemukan');
        }

        $this->repo->deleteInvoice($this->pdo, $invoiceId);
        Audit::write(
            $this->pdo, $requestId, $adminUserId, 'invoice.voided', 'invoice', (string) $invoiceId, 'ok', null, null,
            ['invoiceNo' => $existing['invoice_no'], 'storeId' => (int) $existing['store_id'], 'total' => (float) $existing['total'], 'reason' => $reason]
        );
    }

    // -----------------------------------------------------------------

    private function requireStore(int $storeId): array
    {
        $store = $this->storeRepo->findById($this->pdo, $storeId);
        if ($store === null || !(bool) $store['active']) {
            throw new ApiException(404, 'NOT_FOUND', 'Toko tidak ditemukan');
        }
        return $store;
    }

    private function requireValidRange(string $dateFrom, string $dateTo): void
    {
        if ($dateFrom > $dateTo) {
            throw new ApiException(400, 'INVALID_RANGE', 'Tanggal awal harus sebelum atau sama dengan tanggal akhir');
        }
    }

    /**
     * Merges gross shipment qty with completed-Mutasi in/out deltas into
     * one net billable qty per product. A product with a net qty of
     * exactly zero is dropped (nothing to bill); a negative net (a store
     * mutated out more than it billed-in this window, e.g. re-mutating
     * stock received and already invoiced in an earlier period) is kept
     * as a legitimate credit line — this is never blocked, only surfaced.
     *
     * @return array{items:array,total:float}
     */
    private function computeLines(int $storeId, string $dateFrom, string $dateTo): array
    {
        $shipmentRows = $this->repo->findEligibleShipmentQtyByProduct($this->pdo, $storeId, $dateFrom, $dateTo);
        $mutasiOutRows = $this->repo->findEligibleMutasiOutByProduct($this->pdo, $storeId, $dateFrom, $dateTo);
        $mutasiInRows = $this->repo->findEligibleMutasiInByProduct($this->pdo, $storeId, $dateFrom, $dateTo);

        $qtyDo = [];
        $names = [];
        foreach ($shipmentRows as $r) { $qtyDo[$r['productId']] = ($qtyDo[$r['productId']] ?? 0.0) + $r['qty']; $names[$r['productId']] = $r['productName']; }

        $net = $qtyDo;
        foreach ($mutasiOutRows as $r) { $net[$r['productId']] = ($net[$r['productId']] ?? 0.0) - $r['qty']; $names[$r['productId']] = $r['productName']; }
        foreach ($mutasiInRows as $r) { $net[$r['productId']] = ($net[$r['productId']] ?? 0.0) + $r['qty']; $names[$r['productId']] = $r['productName']; }

        $hargaStmt = $this->pdo->prepare('SELECT harga FROM product WHERE product_id = ?');

        $items = [];
        $total = 0.0;
        foreach ($net as $productId => $netQty) {
            if (abs($netQty) < 0.0001) {
                continue;
            }
            $hargaStmt->execute([$productId]);
            $harga = (float) $hargaStmt->fetchColumn();
            $subtotal = round($netQty * $harga, 2);
            $items[] = [
                'productId' => $productId,
                'productName' => $names[$productId],
                'qtyDo' => $qtyDo[$productId] ?? 0.0,
                'qtyInvoice' => $netQty,
                'harga' => $harga,
                'subtotal' => $subtotal,
            ];
            $total += $subtotal;
        }
        usort($items, static fn ($a, $b) => strcmp($a['productName'], $b['productName']));

        return ['items' => $items, 'total' => round($total, 2)];
    }

    private function fetchMutasiRows(PDO $pdo, string $storeColumn, int $storeId, string $dateFrom, string $dateTo, string $direction): array
    {
        $ids = $this->repo->findEligibleMutasiIds($pdo, $storeColumn, $storeId, $dateFrom, $dateTo, $direction);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT mutasi_request_id, qty_received FROM mutasi_request WHERE mutasi_request_id IN ({$placeholders})");
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    /** Builds the exact DTO shape print-invoice-template.php's own docblock expects (invoiceNumber/invoiceDate/customer/reference/items/summary/metadata). */
    private function buildDetailDto(PDO $pdo, int $invoiceId): array
    {
        $invoice = $this->repo->findById($pdo, $invoiceId);
        if ($invoice === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Invoice tidak ditemukan');
        }
        $items = $this->repo->findItemsForInvoice($pdo, $invoiceId);
        $shipments = $this->repo->findShipmentsForInvoice($pdo, $invoiceId);
        $mutasi = $this->repo->findMutasiForInvoice($pdo, $invoiceId);

        $reference = [];
        if (count($shipments) === 1) {
            $reference = ['doNumber' => $shipments[0]['do_doc_no'], 'shipmentNumber' => 'SHP-' . $shipments[0]['shipment_id'], 'shipDate' => $shipments[0]['tanggal']];
        }

        return [
            'invoiceId' => (int) $invoice['invoice_id'],
            'invoiceNumber' => (string) $invoice['invoice_no'],
            'invoiceDate' => (string) $invoice['tanggal'],
            'customer' => ['storeName' => (string) $invoice['store_name'], 'storeId' => (int) $invoice['store_id'], 'storeEmail' => $invoice['store_email']],
            'reference' => $reference,
            'items' => array_map(static fn ($it) => [
                'productId' => (int) $it['product_id'],
                'productName' => (string) $it['product_name'],
                'qtyDo' => (float) $it['qty_do'],
                'qty' => (float) $it['qty_invoice'],
                'unitPrice' => (float) $it['harga'],
                'subtotal' => (float) $it['subtotal'],
            ], $items),
            'shipments' => array_map(static fn ($s) => [
                'shipmentId' => (int) $s['shipment_id'], 'tanggal' => (string) $s['tanggal'], 'doDocNo' => $s['do_doc_no'],
            ], $shipments),
            'mutasi' => array_map(static fn ($m) => [
                'direction' => (string) $m['direction'], 'docNo' => (string) $m['doc_no'], 'qty' => (float) $m['qty'],
                'productName' => (string) $m['product_name'], 'sourceStoreName' => (string) $m['source_store_name'], 'destinationStoreName' => (string) $m['destination_store_name'],
            ], $mutasi),
            'summary' => ['subtotal' => (float) $invoice['total'], 'discount' => 0.0, 'total' => (float) $invoice['total']],
            'metadata' => ['printedAt' => date('Y-m-d H:i'), 'generatedBy' => 'Amor Factory System', 'createdAt' => (string) $invoice['created_at']],
        ];
    }
}
