<?php

declare(strict_types=1);

namespace Amor\Api\SpecialOrder;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use PDO;

/**
 * Business orchestration for special_order_do — the SEPARATE,
 * source-specific DO for Pesanan Khusus Toko / Pesanan Non-Toko (task's
 * own approved rule: "DIFFERENT DEMAND SOURCES MUST HAVE SEPARATE DO...
 * DO NOT MERGE DIFFERENT SOURCE TYPES INTO ONE DO"). Mirrors
 * Delivery\DoService's own createDraft/ship/cancel shape, but reads its
 * demand from FG-VERIFIED special-order items (fg_verified_qty, migration
 * 0012's Production→FG bridge — see SpecialOrderService::verifyItemFg())
 * rather than live PO, and never touches the shared shipment table in
 * this phase (see migration 0012's own docblock item 4 for why).
 */
final class SpecialOrderDoService
{
    private SpecialOrderDoRepository $repo;
    private SpecialOrderRepository $orderRepo;

    public function __construct(private PDO $pdo)
    {
        $this->repo = new SpecialOrderDoRepository();
        $this->orderRepo = new SpecialOrderRepository();
    }

    /**
     * POST /api/special-order-do — creates a DO for one special order's
     * currently FG-verified quantities. Idempotent at the "one open DO
     * per order" identity level, same convention as Regular DO's
     * (tanggal,storeId) identity in DoService::createDraft().
     */
    public function createDraft(int $orderId, int $userId, ?string $requestId): array
    {
        $order = $this->orderRepo->findOrderById($this->pdo, $orderId);
        if ($order === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Pesanan tidak ditemukan');
        }

        $existing = $this->repo->findOpenDoForOrder($this->pdo, $orderId);
        if ($existing !== null) {
            return $this->buildDoDto((int) $existing['special_order_do_id']);
        }

        $items = $this->orderRepo->findProductionDemandItems($this->pdo, ['orderId' => $orderId]);
        $eligible = array_values(array_filter($items, fn ($it) => (float) $it['fg_verified_qty'] > 0.0001));
        if ($eligible === []) {
            throw new ApiException(400, 'NO_FG_VERIFIED_DEMAND', 'Belum ada item yang terverifikasi FG untuk pesanan ini — tidak ada yang bisa dibuat DO.');
        }

        $tanggal = (string) $order['required_date'];
        $docNo = $this->repo->allocateDoNumber($this->pdo, $tanggal);
        $doId = $this->repo->createDo(
            $this->pdo,
            $docNo,
            $tanggal,
            $orderId,
            (string) $order['source_type'],
            $order['store_id'] !== null ? (int) $order['store_id'] : null,
            $order['customer_name'],
            $order['customer_contact'],
            $order['delivery_address'],
            $userId
        );
        foreach ($eligible as $it) {
            $this->repo->insertDoItem($this->pdo, $doId, (int) $it['special_order_item_id'], (float) $it['fg_verified_qty']);
        }

        Audit::write($this->pdo, $requestId, $userId, 'special_order_do.create', 'special_order_do', (string) $doId, 'ok', null, null, ['orderId' => $orderId, 'docNo' => $docNo]);

        return $this->buildDoDto($doId);
    }

    public function getDo(int $doId): array
    {
        return $this->buildDoDto($doId);
    }

    /** @return array<int,array> */
    public function listDos(array $filters): array
    {
        $rows = $this->repo->findDos($this->pdo, $filters);
        return array_map(function ($r) {
            return [
                'doId' => (int) $r['special_order_do_id'],
                'docNo' => $r['doc_no'],
                'tanggal' => $r['tanggal'],
                'orderId' => (int) $r['special_order_id'],
                'orderNo' => $r['order_no'],
                'sourceType' => $r['source_type'],
                'sourceLabel' => $r['source_type'] === 'toko_khusus' ? 'Pesanan Khusus Toko' : 'Pesanan Non-Toko',
                'status' => $r['status'],
                'storeOrCustomerName' => $r['source_type'] === 'toko_khusus' ? ($r['store_name'] ?? '-') : ($r['customer_name'] ?? '-'),
            ];
        }, $rows);
    }

    /**
     * POST /api/special-order-do/{id}/ship — single-step dispatch (task's
     * own "simple single-step dispatch" guidance). Never writes
     * stock_ledger or shipment — special-order FG stays order-specific
     * (migration 0012's architecture decision); this only marks the
     * document itself as shipped.
     */
    public function ship(int $doId, int $expectedVersion, int $userId, ?string $requestId): array
    {
        $do = $this->requireDo($doId);
        $this->checkVersion($do, $expectedVersion);
        if (!in_array($do['status'], ['draft', 'ready'], true)) {
            throw new ApiException(400, 'INVALID_STATUS', 'DO ini sudah dikirim atau dibatalkan — tidak bisa dikirim lagi.');
        }
        $this->repo->markStatus($this->pdo, $doId, 'shipped', $userId);
        Audit::write($this->pdo, $requestId, $userId, 'special_order_do.ship', 'special_order_do', (string) $doId, 'ok', null, null, null);
        return $this->buildDoDto($doId);
    }

    public function cancel(int $doId, int $expectedVersion, string $reason, int $userId, ?string $requestId): array
    {
        $do = $this->requireDo($doId);
        $this->checkVersion($do, $expectedVersion);
        if ($do['status'] === 'shipped') {
            throw new ApiException(400, 'CANNOT_CANCEL_SHIPPED', 'DO ini sudah dikirim — tidak bisa dibatalkan.');
        }
        if ($do['status'] === 'cancelled') {
            throw new ApiException(400, 'INVALID_STATUS', 'DO ini sudah dibatalkan.');
        }
        if (trim($reason) === '') {
            throw new ApiException(400, 'REASON_REQUIRED', 'Alasan wajib diisi.');
        }
        $this->repo->markStatus($this->pdo, $doId, 'cancelled', $userId, $reason);
        Audit::write($this->pdo, $requestId, $userId, 'special_order_do.cancel', 'special_order_do', (string) $doId, 'ok', null, null, ['reason' => $reason]);
        return $this->buildDoDto($doId);
    }

    private function requireDo(int $doId): array
    {
        $do = $this->repo->findDoById($this->pdo, $doId);
        if ($do === null) {
            throw new ApiException(404, 'NOT_FOUND', 'DO tidak ditemukan');
        }
        return $do;
    }

    private function checkVersion(array $do, int $expectedVersion): void
    {
        if ((int) $do['version'] !== $expectedVersion) {
            throw new ApiException(409, 'VERSION_CONFLICT', 'Dokumen sudah diubah oleh pengguna lain', ['currentVersion' => (int) $do['version']]);
        }
    }

    private function buildDoDto(int $doId): array
    {
        $do = $this->requireDo($doId);
        $items = $this->repo->findDoItems($this->pdo, $doId);
        return [
            'doId' => (int) $do['special_order_do_id'],
            'docNo' => $do['doc_no'],
            'tanggal' => $do['tanggal'],
            'orderId' => (int) $do['special_order_id'],
            'orderNo' => $do['order_no'],
            'sourceType' => $do['source_type'],
            'sourceLabel' => $do['source_type'] === 'toko_khusus' ? 'Pesanan Khusus Toko' : 'Pesanan Non-Toko',
            'status' => $do['status'],
            'storeId' => $do['store_id'] !== null ? (int) $do['store_id'] : null,
            'storeName' => $do['store_name'],
            'customerName' => $do['customer_name'],
            'customerContact' => $do['customer_contact'],
            'deliveryAddress' => $do['delivery_address'],
            'version' => (int) $do['version'],
            'createdByName' => $do['created_by_name'],
            'createdAt' => $do['created_at'],
            'shippedAt' => $do['shipped_at'],
            'cancelledAt' => $do['cancelled_at'],
            'cancelReason' => $do['cancel_reason'],
            'items' => array_map(function ($it) {
                return [
                    'doItemId' => (int) $it['special_order_do_item_id'],
                    'itemId' => (int) $it['special_order_item_id'],
                    'itemType' => $it['item_type'],
                    'itemName' => $it['item_name_snapshot'],
                    'divisionName' => $it['division_name'],
                    'factoryName' => $it['factory_name'],
                    'plannedQty' => (float) $it['planned_qty'],
                    'actualShipQty' => $it['actual_ship_qty'] !== null ? (float) $it['actual_ship_qty'] : null,
                ];
            }, $items),
        ];
    }
}
