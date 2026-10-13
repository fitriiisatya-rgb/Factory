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
 *  - Invoices are normally issued PER DO (migration 0021, the owner's
 *    "sesuai DO"): generateAllReady() bills every confirmed delivery (regular
 *    DO or special DO) and every completed Mutasi's destination store.
 *    Anything that later reduces what a store owes on an issued invoice —
 *    an approved Reject di Toko, the SOURCE side of a Mutasi — is a separate
 *    Nota Potongan (NotaPotonganService), never an edit of the invoice.
 *    A Retur (unsold goods, store burden) is never credited.
 *  - The per-store/period generate() below still exists (and still nets
 *    Mutasi in/out itself, skipping any Mutasi a nota already covers).
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
        (new NotaPotonganService($pdo))->linkPendingToInvoice($invoiceId);

        Audit::write(
            $pdo, $requestId, $adminUserId, 'invoice.generated', 'invoice', (string) $invoiceId, 'ok', null, null,
            ['storeId' => $storeId, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'total' => $lines['total'], 'shipmentCount' => count($shipmentIds)]
        );

        return $this->buildDetailDto($pdo, $invoiceId);
    }

    /**
     * What generateAllReady() would issue right now — read-only.
     *
     * @return array{deliveries:array,mutasiInvoices:array,mutasiNotas:array}
     */
    public function readySummary(): array
    {
        $deliveries = [];
        foreach (['do' => 'delivery_order_id', 'do_khusus' => 'special_order_do_id'] as $kind => $col) {
            foreach ($this->repo->findReadyDeliveries($this->pdo, $col) as $d) {
                $deliveries[] = $d + ['kind' => $kind, 'docNo' => $this->deliveryDocNo($kind, $d['refId']), 'storeName' => $this->storeName($d['storeId'])];
            }
        }
        $mi = array_map(fn ($m) => ['mutasiId' => (int) $m['mutasi_request_id'], 'docNo' => $m['doc_no'], 'storeName' => $this->storeName((int) $m['destination_store_id'])], $this->repo->findMutasiReadyForInvoice($this->pdo));
        $mn = array_map(fn ($m) => ['mutasiId' => (int) $m['mutasi_request_id'], 'docNo' => $m['doc_no'], 'storeName' => $this->storeName((int) $m['source_store_id'])], $this->repo->findMutasiReadyForNota($this->pdo));
        return ['deliveries' => $deliveries, 'mutasiInvoices' => $mi, 'mutasiNotas' => $mn];
    }

    /**
     * Issues every document that is ready, in one transaction: one invoice
     * per confirmed DO / special DO (per store), one invoice per completed
     * Mutasi for its destination store, and one Nota Potongan per completed
     * Mutasi for its source store. Nota Potongan already waiting for a
     * delivery that gets invoiced here are linked to that invoice.
     *
     * @return array{invoices:array<int,array>,notas:array<int,array>}
     */
    public function generateAllReady(int $adminUserId, ?string $requestId): array
    {
        $pdo = $this->pdo;
        $invoices = [];
        foreach (['do' => 'delivery_order_id', 'do_khusus' => 'special_order_do_id'] as $kind => $col) {
            foreach ($this->repo->findReadyDeliveries($pdo, $col) as $d) {
                $ships = $this->repo->findDeliveryShipments($pdo, $col, $d['refId'], $d['storeId']);
                $ids = array_map(static fn ($r) => (int) $r['shipment_id'], $ships);
                $rows = $this->repo->findQtyByProductForShipments($pdo, $ids);
                $lines = $this->priceLines($rows);
                if ($lines['items'] === []) {
                    continue; // e.g. custom catalog items only — nothing billable by product
                }
                $tanggal = (string) end($ships)['tanggal'];
                $invoiceId = $this->insertWithLines($tanggal, $d['storeId'], $lines, $kind, $d['refId']);
                foreach ($ids as $sid) {
                    $this->repo->linkShipment($pdo, $invoiceId, $sid);
                }
                (new NotaPotonganService($pdo))->linkPendingToInvoice($invoiceId);
                Audit::write($pdo, $requestId, $adminUserId, 'invoice.generated', 'invoice', (string) $invoiceId, 'ok', null, null,
                    ['kind' => $kind, 'refId' => $d['refId'], 'storeId' => $d['storeId'], 'total' => $lines['total'], 'shipmentCount' => count($ids)]);
                $invoices[] = $this->listRow($invoiceId);
            }
        }
        foreach ($this->repo->findMutasiReadyForInvoice($pdo) as $m) {
            $lines = $this->priceLines([['productId' => (int) $m['product_id'], 'productName' => (string) $m['product_name'], 'qty' => (float) $m['qty_received']]]);
            $tanggal = substr((string) ($m['confirmed_at'] ?? $m['created_at']), 0, 10);
            $invoiceId = $this->insertWithLines($tanggal, (int) $m['destination_store_id'], $lines, 'mutasi', (int) $m['mutasi_request_id']);
            $this->repo->linkMutasi($pdo, $invoiceId, (int) $m['mutasi_request_id'], 'in', (float) $m['qty_received']);
            Audit::write($pdo, $requestId, $adminUserId, 'invoice.generated', 'invoice', (string) $invoiceId, 'ok', null, null,
                ['kind' => 'mutasi', 'mutasiId' => (int) $m['mutasi_request_id'], 'total' => $lines['total']]);
            $invoices[] = $this->listRow($invoiceId);
        }
        $notas = [];
        $notaService = new NotaPotonganService($pdo);
        foreach ($this->repo->findMutasiReadyForNota($pdo) as $m) {
            $notas[] = $notaService->createForMutasiKeluar($m, $adminUserId, $requestId);
        }
        return ['invoices' => $invoices, 'notas' => $notas];
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
            'notaTotal' => (float) ($r['nota_total'] ?? 0),
            'sourceKind' => (string) ($r['source_kind'] ?? 'periode'),
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

        // Notas against this invoice stay valid; they re-link automatically
        // when their delivery is invoiced again.
        $this->pdo->prepare('UPDATE nota_potongan SET invoice_id = NULL WHERE invoice_id = ?')->execute([$invoiceId]);
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

        $rows = [];
        foreach ($net as $productId => $netQty) {
            $rows[] = ['productId' => $productId, 'productName' => $names[$productId], 'qty' => $netQty, 'qtyDo' => $qtyDo[$productId] ?? 0.0];
        }
        return $this->priceLines($rows);
    }

    /**
     * Prices rows at the product's current harga. qtyDo defaults to qty.
     *
     * @param array<int,array{productId:int,productName:string,qty:float,qtyDo?:float}> $rows
     * @return array{items:array,total:float}
     */
    private function priceLines(array $rows): array
    {
        $hargaStmt = $this->pdo->prepare('SELECT harga FROM product WHERE product_id = ?');
        $items = [];
        $total = 0.0;
        foreach ($rows as $r) {
            if (abs($r['qty']) < 0.0001) {
                continue;
            }
            $hargaStmt->execute([$r['productId']]);
            $harga = (float) $hargaStmt->fetchColumn();
            $subtotal = round($r['qty'] * $harga, 2);
            $items[] = [
                'productId' => $r['productId'], 'productName' => $r['productName'],
                'qtyDo' => $r['qtyDo'] ?? $r['qty'], 'qtyInvoice' => $r['qty'], 'harga' => $harga, 'subtotal' => $subtotal,
            ];
            $total += $subtotal;
        }
        usort($items, static fn ($a, $b) => strcmp($a['productName'], $b['productName']));
        return ['items' => $items, 'total' => round($total, 2)];
    }

    private function insertWithLines(string $tanggal, int $storeId, array $lines, string $kind, ?int $refId): int
    {
        $invoiceNo = $this->repo->allocateInvoiceNumber($this->pdo, $tanggal);
        $invoiceId = $this->repo->insertInvoice($this->pdo, $invoiceNo, $tanggal, $storeId, $lines['total'], $kind, $refId);
        foreach ($lines['items'] as $item) {
            $this->repo->insertInvoiceItem($this->pdo, $invoiceId, $item['productId'], $item['qtyDo'], $item['qtyInvoice'], $item['harga'], $item['subtotal']);
        }
        return $invoiceId;
    }

    private function listRow(int $invoiceId): array
    {
        $r = $this->repo->findById($this->pdo, $invoiceId);
        return ['invoiceId' => $invoiceId, 'invoiceNo' => (string) $r['invoice_no'], 'tanggal' => (string) $r['tanggal'],
            'storeName' => (string) $r['store_name'], 'total' => (float) $r['total'], 'sourceKind' => (string) $r['source_kind']];
    }

    private function deliveryDocNo(string $kind, int $refId): string
    {
        $sql = $kind === 'do'
            ? 'SELECT doc_no FROM delivery_order WHERE delivery_order_id = ?'
            : 'SELECT doc_no FROM special_order_do WHERE special_order_do_id = ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$refId]);
        return (string) ($stmt->fetchColumn() ?: '#' . $refId);
    }

    private function storeName(int $storeId): string
    {
        $s = $this->storeRepo->findById($this->pdo, $storeId);
        return $s !== null ? (string) $s['canonical_name'] : '#' . $storeId;
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
        $notas = $this->repo->findNotasForInvoice($pdo, $invoiceId);
        $notaTotal = array_sum(array_map(static fn ($n) => (float) $n['total'], $notas));

        $reference = [];
        $doNos = array_values(array_unique(array_filter(array_map(static fn ($sh) => $sh['do_doc_no'], $shipments))));
        if (count($shipments) === 1) {
            $reference = ['doNumber' => $shipments[0]['do_doc_no'], 'shipmentNumber' => 'SHP-' . $shipments[0]['shipment_id'], 'shipDate' => $shipments[0]['tanggal']];
        } elseif (count($doNos) === 1) {
            $reference = ['doNumber' => $doNos[0], 'shipDate' => $shipments[0]['tanggal']];
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
            'sourceKind' => (string) ($invoice['source_kind'] ?? 'periode'),
            'notas' => array_map(static fn ($n) => [
                'notaId' => (int) $n['nota_potongan_id'], 'notaNo' => (string) $n['nota_no'], 'tanggal' => (string) $n['tanggal'],
                'alasan' => (string) $n['alasan'], 'total' => (float) $n['total'], 'keterangan' => $n['keterangan'],
            ], $notas),
            'notaTotal' => round($notaTotal, 2),
            'netTotal' => round((float) $invoice['total'] - $notaTotal, 2),
            'summary' => ['subtotal' => (float) $invoice['total'], 'discount' => 0.0, 'total' => (float) $invoice['total']],
            'metadata' => ['printedAt' => date('Y-m-d H:i'), 'generatedBy' => 'Amor Factory System', 'createdAt' => (string) $invoice['created_at']],
        ];
    }
}
