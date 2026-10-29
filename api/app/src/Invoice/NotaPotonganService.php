<?php

declare(strict_types=1);

namespace Amor\Api\Invoice;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Services\DocumentSequenceService;
use PDO;

/**
 * Nota Potongan (migration 0021) — a credit note against goods a store was
 * (or will be) invoiced for. Invoices are issued per DO and never edited
 * afterwards; anything that later reduces what the store owes is a nota:
 *
 *   - reject_toko   — an approved Reject di Toko (created by
 *                     ReturService::adminVerify()).
 *   - mutasi_keluar — a completed Mutasi, on the SOURCE store's side
 *                     (created by InvoiceService::generateAllReady()).
 *
 * Each nota points at the delivery (shipment) the goods most likely came
 * from — the store's latest delivery of that product on/before the report
 * date — and at that delivery's invoice. If the delivery is not invoiced
 * yet, invoice_id stays NULL and is filled in by linkPendingToInvoice()
 * the moment it is. Price = the price on that invoice line, else the
 * product's current price.
 *
 * Per bakery: tagihan bersih = Σ invoice − Σ nota potongan.
 */
final class NotaPotonganService
{
    private const ROMAN_MONTHS = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array nota DTO */
    public function createForRejectToko(array $retur, ?int $userId, ?string $requestId): array
    {
        return $this->create(
            'reject_toko', (int) $retur['store_id'], (int) $retur['product_id'], (float) $retur['qty'],
            (string) $retur['retur_date'], (int) $retur['retur_request_id'], null,
            'Reject di Toko ' . $retur['doc_no'], $userId, $requestId
        );
    }

    /** @return array nota DTO */
    public function createForMutasiKeluar(array $mutasi, ?int $userId, ?string $requestId): array
    {
        $date = substr((string) ($mutasi['confirmed_at'] ?? $mutasi['created_at']), 0, 10);
        return $this->create(
            'mutasi_keluar', (int) $mutasi['source_store_id'], (int) $mutasi['product_id'], (float) $mutasi['qty_received'],
            $date, null, (int) $mutasi['mutasi_request_id'],
            'Mutasi ' . $mutasi['doc_no'] . ' ke toko lain', $userId, $requestId
        );
    }

    /** Fills invoice_id on notas whose source delivery was just invoiced. */
    public function linkPendingToInvoice(int $invoiceId): void
    {
        $this->pdo->prepare(
            'UPDATE nota_potongan n
             INNER JOIN invoice_shipment iis ON iis.shipment_id = n.shipment_id
             SET n.invoice_id = iis.invoice_id
             WHERE iis.invoice_id = ? AND n.invoice_id IS NULL'
        )->execute([$invoiceId]);
    }

    /** Factory of a nota: a Reject di Toko nota follows its product; a Mutasi nota has none (visible to every admin). */
    private const NOTA_FACTORY_SQL = '(SELECT d.factory_id FROM retur_request r2 INNER JOIN product p2 ON p2.product_id = r2.product_id
        INNER JOIN division d ON d.division_id = p2.division_id WHERE r2.retur_request_id = n.retur_request_id)';

    /** @return array<int,array> newest first */
    public function list(?int $storeId, ?string $dateFrom, ?string $dateTo): array
    {
        $sql = 'SELECT n.*, s.canonical_name AS store_name, i.invoice_no
                FROM nota_potongan n
                INNER JOIN store s ON s.store_id = n.store_id
                LEFT JOIN invoice i ON i.invoice_id = n.invoice_id
                WHERE 1=1';
        $params = [];
        if ($storeId !== null) { $sql .= ' AND n.store_id = ?'; $params[] = $storeId; }
        if ($dateFrom !== null) { $sql .= ' AND n.tanggal >= ?'; $params[] = $dateFrom; }
        if ($dateTo !== null) { $sql .= ' AND n.tanggal <= ?'; $params[] = $dateTo; }
        if (\Amor\Api\Auth::isFactoryAdmin()) {
            $sql .= ' AND (n.retur_request_id IS NULL OR ' . \Amor\Api\Auth::adminFactorySql(self::NOTA_FACTORY_SQL) . ')';
        }
        $sql .= ' ORDER BY n.tanggal DESC, n.nota_potongan_id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(fn ($r) => $this->summary($r), $stmt->fetchAll());
    }

    /** @throws ApiException 404 NOT_FOUND */
    public function get(int $notaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.*, s.canonical_name AS store_name, s.email AS store_email, i.invoice_no, i.tanggal AS invoice_tanggal,
                    rr.doc_no AS retur_doc_no, rr.kondisi, rr.exp_date, rr.retur_date, mr.doc_no AS mutasi_doc_no,
                    ds.canonical_name AS mutasi_destination
             FROM nota_potongan n
             INNER JOIN store s ON s.store_id = n.store_id
             LEFT JOIN invoice i ON i.invoice_id = n.invoice_id
             LEFT JOIN retur_request rr ON rr.retur_request_id = n.retur_request_id
             LEFT JOIN mutasi_request mr ON mr.mutasi_request_id = n.mutasi_request_id
             LEFT JOIN store ds ON ds.store_id = mr.destination_store_id
             WHERE n.nota_potongan_id = ?'
        );
        $stmt->execute([$notaId]);
        $n = $stmt->fetch();
        if ($n === false) {
            throw new ApiException(404, 'NOT_FOUND', 'Nota potongan tidak ditemukan');
        }
        if ($n['retur_request_id'] !== null) {
            $f = $this->pdo->prepare('SELECT ' . str_replace('n.retur_request_id', '?', self::NOTA_FACTORY_SQL));
            $f->execute([(int) $n['retur_request_id']]);
            $fid = $f->fetchColumn();
            \Amor\Api\AdminScope::requireAny($fid !== false && $fid !== null ? [(int) $fid] : [], true);
        }
        $items = $this->pdo->prepare(
            'SELECT it.*, p.name AS product_name FROM nota_potongan_item it
             INNER JOIN product p ON p.product_id = it.product_id
             WHERE it.nota_potongan_id = ? ORDER BY it.nota_potongan_item_id'
        );
        $items->execute([$notaId]);
        return $this->summary($n) + [
            'storeEmail' => $n['store_email'],
            'invoiceTanggal' => $n['invoice_tanggal'],
            'returDocNo' => $n['retur_doc_no'],
            'kondisi' => $n['kondisi'],
            'expDate' => $n['exp_date'],
            'mutasiDocNo' => $n['mutasi_doc_no'],
            'mutasiDestination' => $n['mutasi_destination'],
            'items' => array_map(static fn ($it) => [
                'productId' => (int) $it['product_id'], 'productName' => (string) $it['product_name'],
                'qty' => (float) $it['qty'], 'harga' => (float) $it['harga'], 'subtotal' => (float) $it['subtotal'],
            ], $items->fetchAll()),
        ];
    }

    /**
     * Hard delete (no payment references it yet — piutang is on hold). The
     * reject/mutasi it came from becomes "nota belum terbit" again.
     *
     * @throws ApiException 404 NOT_FOUND
     */
    public function void(int $notaId, int $userId, string $reason, ?string $requestId): void
    {
        $n = $this->get($notaId);
        $this->pdo->prepare('DELETE FROM nota_potongan WHERE nota_potongan_id = ?')->execute([$notaId]);
        Audit::write($this->pdo, $requestId, $userId, 'nota_potongan.voided', 'nota_potongan', (string) $notaId, 'ok', null, null,
            ['notaNo' => $n['notaNo'], 'storeId' => $n['storeId'], 'total' => $n['total'], 'reason' => $reason]);
    }

    // -----------------------------------------------------------------

    private function create(string $alasan, int $storeId, int $productId, float $qty, string $refDate, ?int $returId, ?int $mutasiId, string $keterangan, ?int $userId, ?string $requestId): array
    {
        if ($qty <= 0) {
            throw new ApiException(400, 'INVALID_QTY', 'Jumlah potongan harus lebih dari 0');
        }

        // The delivery the goods most likely came from: the store's latest
        // active delivery of this product on/before the report date.
        $ship = $this->pdo->prepare(
            "SELECT sh.shipment_id, iis.invoice_id, ii.harga AS invoice_harga
             FROM shipment sh
             INNER JOIN shipment_receipt sr ON sr.shipment_id = sh.shipment_id
             INNER JOIN shipment_receipt_item sri ON sri.shipment_receipt_id = sr.shipment_receipt_id AND sri.product_id = ?
             LEFT JOIN invoice_shipment iis ON iis.shipment_id = sh.shipment_id
             LEFT JOIN invoice_item ii ON ii.invoice_id = iis.invoice_id AND ii.product_id = sri.product_id
             WHERE sh.store_id = ? AND sh.status = 'active' AND sh.tanggal <= ? AND sri.received_good_qty > 0
               -- only deliveries the store is billed for (regular DO, or a
               -- Pesanan Khusus Toko DO): never a free replacement or a
               -- Non-Toko pass-through, whose invoice would never come
               AND (sh.delivery_order_id IS NOT NULL OR EXISTS (SELECT 1 FROM special_order_do sod
                    WHERE sod.special_order_do_id = sh.special_order_do_id AND sod.source_type = 'toko_khusus'))
             ORDER BY sh.tanggal DESC, sh.shipment_id DESC
             LIMIT 1"
        );
        $ship->execute([$productId, $storeId, $refDate]);
        $src = $ship->fetch() ?: ['shipment_id' => null, 'invoice_id' => null, 'invoice_harga' => null];

        $harga = $src['invoice_harga'] !== null
            ? (float) $src['invoice_harga']
            : (float) $this->pdo->query('SELECT harga FROM product WHERE product_id = ' . (int) $productId)->fetchColumn();
        $subtotal = round($qty * $harga, 2);

        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
        [$y, $m] = array_map('intval', explode('-', $today));
        $seq = DocumentSequenceService::allocate($this->pdo, 'NP', $y, $m);
        $notaNo = sprintf('NP/KRM/%03d/%s/%d', $seq, self::ROMAN_MONTHS[$m - 1], $y);

        $this->pdo->prepare(
            'INSERT INTO nota_potongan
                (nota_no, tanggal, store_id, invoice_id, shipment_id, alasan, retur_request_id, mutasi_request_id, total, keterangan, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([
            $notaNo, $today, $storeId, $src['invoice_id'], $src['shipment_id'], $alasan, $returId, $mutasiId, $subtotal, $keterangan, $userId,
        ]);
        $notaId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO nota_potongan_item (nota_potongan_id, product_id, qty, harga, subtotal) VALUES (?, ?, ?, ?, ?)'
        )->execute([$notaId, $productId, $qty, $harga, $subtotal]);

        Audit::write($this->pdo, $requestId, $userId, 'nota_potongan.created', 'nota_potongan', (string) $notaId, 'ok', null, 1,
            ['notaNo' => $notaNo, 'alasan' => $alasan, 'storeId' => $storeId, 'total' => $subtotal, 'invoiceId' => $src['invoice_id']]);

        return $this->get($notaId);
    }

    private function summary(array $r): array
    {
        return [
            'notaId' => (int) $r['nota_potongan_id'],
            'notaNo' => (string) $r['nota_no'],
            'tanggal' => (string) $r['tanggal'],
            'storeId' => (int) $r['store_id'],
            'storeName' => (string) $r['store_name'],
            'invoiceId' => $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null,
            'invoiceNo' => $r['invoice_no'] ?? null,
            'alasan' => (string) $r['alasan'],
            'alasanLabel' => $r['alasan'] === 'reject_toko' ? 'Reject di Toko' : 'Mutasi keluar',
            'returId' => $r['retur_request_id'] !== null ? (int) $r['retur_request_id'] : null,
            'mutasiId' => $r['mutasi_request_id'] !== null ? (int) $r['mutasi_request_id'] : null,
            'total' => (float) $r['total'],
            'keterangan' => $r['keterangan'],
            'createdAt' => (string) $r['created_at'],
        ];
    }
}
