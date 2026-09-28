<?php

declare(strict_types=1);

namespace Amor\Api\Replacement;

use PDO;

/**
 * Data access for the Replacement Reject disposition + demand lifecycle
 * (migration 0016) — the Admin's per-line reject disposition living on
 * shipment_receipt_item, plus replacement_demand itself. Mirrors
 * SpecialOrder\SpecialOrderRepository's own shape (plain prepared
 * statements, no ORM).
 */
final class ReplacementRepository
{
    // ------------------------------------------------------------------
    // Disposition — lives directly on shipment_receipt_item (migration
    // 0016's own ALTER), never a separate parallel table, since the
    // reject qty itself already lives here.
    // ------------------------------------------------------------------

    /**
     * Row-locked (FOR UPDATE) receipt item, joined with its parent
     * receipt (status, verified_at) and the shipment it belongs to
     * (store_id, tanggal, factory_id) — everything a disposition decision
     * needs in one read, under one lock.
     */
    public function lockReceiptItemWithContext(PDO $pdo, int $receiptItemId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT ri.*, r.status AS receipt_status, r.verified_at, r.shipment_id,
                    sh.store_id, sh.tanggal, sh.factory_id AS shipment_factory_id
             FROM shipment_receipt_item ri
             INNER JOIN shipment_receipt r ON r.shipment_receipt_id = ri.shipment_receipt_id
             INNER JOIN shipment sh ON sh.shipment_id = r.shipment_id
             WHERE ri.shipment_receipt_item_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$receiptItemId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findReceiptItemWithContext(PDO $pdo, int $receiptItemId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT ri.*, r.status AS receipt_status, r.verified_at, r.shipment_id,
                    sh.store_id, sh.tanggal, sh.factory_id AS shipment_factory_id,
                    COALESCE(p.name, ri.item_name_snapshot) AS product_name
             FROM shipment_receipt_item ri
             INNER JOIN shipment_receipt r ON r.shipment_receipt_id = ri.shipment_receipt_id
             INNER JOIN shipment sh ON sh.shipment_id = r.shipment_id
             LEFT JOIN product p ON p.product_id = ri.product_id
             WHERE ri.shipment_receipt_item_id = ?'
        );
        $stmt->execute([$receiptItemId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Writes the disposition — guarded by `AND disposition = 'pending'` so
     * a race between two concurrent disposition requests for the SAME
     * line can only ever have one winner (rowCount()>0 tells the caller
     * which); never overwrites an already-decided line.
     */
    public function applyDisposition(
        PDO $pdo,
        int $receiptItemId,
        string $disposition,
        float $approvedRejectQty,
        ?string $reason,
        int $userId
    ): bool {
        $stmt = $pdo->prepare(
            "UPDATE shipment_receipt_item
                SET approved_reject_qty = ?, disposition = ?, disposition_reason = ?,
                    disposition_by = ?, disposition_at = UTC_TIMESTAMP()
             WHERE shipment_receipt_item_id = ? AND disposition = 'pending'"
        );
        $stmt->execute([$approvedRejectQty, $disposition, $reason, $userId, $receiptItemId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Every receipt line with a real reject (>0) still awaiting an
     * explicit disposition — Admin's "Tindak Lanjut Reject" worklist, only
     * ever populated from ALREADY-verified receipts (task's own "Admin
     * verifies the Reject. Admin must then choose disposition" — the two
     * are sequential, never combined into one click).
     */
    public function findPendingDispositionItems(PDO $pdo, ?string $tanggal): array
    {
        $sql = "SELECT ri.*, r.verified_at, r.shipment_id, sh.store_id, sh.tanggal,
                       s.canonical_name AS store_name,
                       COALESCE(p.name, ri.item_name_snapshot) AS product_name
                FROM shipment_receipt_item ri
                INNER JOIN shipment_receipt r ON r.shipment_receipt_id = ri.shipment_receipt_id
                INNER JOIN shipment sh ON sh.shipment_id = r.shipment_id
                INNER JOIN store s ON s.store_id = sh.store_id
                LEFT JOIN product p ON p.product_id = ri.product_id
                WHERE r.status = 'verified' AND ri.reject_qty > 0.0001 AND ri.disposition = 'pending'";
        $params = [];
        if ($tanggal !== null) {
            $sql .= ' AND sh.tanggal = ?';
            $params[] = $tanggal;
        }
        $sql .= ' ORDER BY r.verified_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Resolves the replacement_demand a given receipt item's OWN shipment
     * line was created to fulfill — i.e. "is this receipt item itself a
     * receipt for a Replacement DO shipment, and if so, of which demand?"
     * Returns null when the line traces to an ORIGINAL (Regular/Special)
     * shipment, never a replacement one — the caller's own "self-root,
     * no parent" branch (see ReplacementService::disposeReject()).
     */
    public function findDemandForReplacementReceiptLine(PDO $pdo, int $receiptItemId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT rd.* FROM shipment_receipt_item ri
             INNER JOIN replacement_do_shipment_item rdsi ON rdsi.replacement_do_shipment_item_id = ri.replacement_do_shipment_item_id
             INNER JOIN replacement_do rdo ON rdo.replacement_do_id = rdsi.replacement_do_id
             INNER JOIN replacement_demand rd ON rd.replacement_demand_id = rdo.replacement_demand_id
             WHERE ri.shipment_receipt_item_id = ? AND ri.replacement_do_shipment_item_id IS NOT NULL'
        );
        $stmt->execute([$receiptItemId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ------------------------------------------------------------------
    // replacement_demand
    // ------------------------------------------------------------------

    public function insertDemand(
        PDO $pdo,
        int $shipmentReceiptItemId,
        int $rootShipmentReceiptItemId,
        ?int $parentReplacementDemandId,
        int $storeId,
        int $productId,
        int $factoryId,
        float $approvedQty,
        int $userId
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO replacement_demand
                (shipment_receipt_item_id, root_shipment_receipt_item_id, parent_replacement_demand_id,
                 store_id, product_id, factory_id, approved_qty, status, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $shipmentReceiptItemId, $rootShipmentReceiptItemId, $parentReplacementDemandId,
            $storeId, $productId, $factoryId, $approvedQty,
            // Always starts 'need_production' — the caller (ReplacementService
            // ::disposeReject()) calls refreshReadiness() right after this
            // insert, in the SAME transaction, once FG allocation has run,
            // so this never has to guess the post-allocation outcome here.
            'need_production',
            $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function findDemandById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT rd.*, s.canonical_name AS store_name, p.name AS product_name, f.name AS factory_name
             FROM replacement_demand rd
             INNER JOIN store s ON s.store_id = rd.store_id
             INNER JOIN product p ON p.product_id = rd.product_id
             INNER JOIN factory f ON f.factory_id = rd.factory_id
             WHERE rd.replacement_demand_id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function lockDemandById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM replacement_demand WHERE replacement_demand_id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array> */
    public function findDemands(PDO $pdo, array $filters): array
    {
        $sql = 'SELECT rd.*, s.canonical_name AS store_name, p.name AS product_name, f.name AS factory_name,
                       rdo.doc_no AS replacement_do_doc_no, rdo.replacement_do_id
                FROM replacement_demand rd
                INNER JOIN store s ON s.store_id = rd.store_id
                INNER JOIN product p ON p.product_id = rd.product_id
                INNER JOIN factory f ON f.factory_id = rd.factory_id
                LEFT JOIN replacement_do rdo ON rdo.replacement_demand_id = rd.replacement_demand_id
                WHERE 1=1';
        $params = [];
        if (isset($filters['storeId'])) {
            $sql .= ' AND rd.store_id = ?';
            $params[] = $filters['storeId'];
        }
        if (isset($filters['status'])) {
            $sql .= ' AND rd.status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['factoryId'])) {
            $sql .= ' AND rd.factory_id = ?';
            $params[] = $filters['factoryId'];
        }
        $sql .= ' ORDER BY rd.replacement_demand_id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Snapshot semantics — REPLACES the stored value, matching special_order_item's own aktual_produksi/reject_produksi convention. */
    public function updateProductionActual(PDO $pdo, int $demandId, float $aktual, float $reject): void
    {
        $stmt = $pdo->prepare('UPDATE replacement_demand SET production_aktual = ?, production_reject = ? WHERE replacement_demand_id = ?');
        $stmt->execute([$aktual, $reject, $demandId]);
    }

    /** Snapshot semantics — REPLACES fg_verified_qty, matching special_order_item's own updateFgVerifiedQty() convention. */
    public function updateFgVerifiedQty(PDO $pdo, int $demandId, float $qty): void
    {
        $stmt = $pdo->prepare('UPDATE replacement_demand SET production_fg_verified_qty = ? WHERE replacement_demand_id = ?');
        $stmt->execute([$qty, $demandId]);
    }

    /**
     * Recomputes and writes status purely from the real allocation +
     * production numbers (never hand-set) — 'need_production' while a
     * real shortage remains, 'ready' once it doesn't, UNLESS the demand
     * has already progressed further (do_created/shipped/... — this
     * method never regresses a demand that already has a DO, matching
     * special_order_do's own refreshStatus() "recompute forward, never
     * regress a real dispatch" convention).
     */
    public function refreshReadiness(PDO $pdo, int $demandId, float $allocatedFromFg): string
    {
        $demand = $this->lockDemandById($pdo, $demandId);
        if ($demand === null) {
            throw new \RuntimeException("replacement_demand {$demandId} not found");
        }
        if (!in_array($demand['status'], ['need_production', 'ready'], true)) {
            return (string) $demand['status'];
        }
        $productionNeed = max(0.0, (float) $demand['approved_qty'] - $allocatedFromFg - (float) $demand['production_fg_verified_qty']);
        $status = $productionNeed <= 0.0001 ? 'ready' : 'need_production';
        $stmt = $pdo->prepare('UPDATE replacement_demand SET status = ?, updated_at = UTC_TIMESTAMP() WHERE replacement_demand_id = ?');
        $stmt->execute([$status, $demandId]);
        return $status;
    }

    /** Forward-only status write (do_created/partially_shipped/shipped/received_partial/received_good/completed) — never touches version. */
    public function setStatus(PDO $pdo, int $demandId, string $status): void
    {
        $stmt = $pdo->prepare('UPDATE replacement_demand SET status = ?, updated_at = UTC_TIMESTAMP() WHERE replacement_demand_id = ?');
        $stmt->execute([$status, $demandId]);
    }

    /**
     * Production\ProductionTaskService's own "Task per Divisi" read
     * hook — every demand still genuinely needing production for one
     * division (joined via product.division_id, since replacement_demand
     * itself is factory-scoped, not division-scoped). Deliberately NOT
     * date-filtered: a Replacement demand has no natural "tanggal" of its
     * own (it is created whenever a reject is verified, not tied to a
     * production day) — it stays visible here for as long as it
     * genuinely needs production, regardless of which date Task per
     * Divisi is currently viewing.
     */
    public function findNeedProductionForDivision(PDO $pdo, int $divisionId): array
    {
        $stmt = $pdo->prepare(
            "SELECT rd.*, p.name AS product_name, p.division_id, s.canonical_name AS store_name
             FROM replacement_demand rd
             INNER JOIN product p ON p.product_id = rd.product_id
             INNER JOIN store s ON s.store_id = rd.store_id
             WHERE p.division_id = ? AND rd.status = 'need_production'
             ORDER BY rd.replacement_demand_id"
        );
        $stmt->execute([$divisionId]);
        return $stmt->fetchAll();
    }

    /** Resolves the replacement_demand a given SHIPMENT (not receipt item) belongs to — used right after a store confirms receipt of a replacement shipment, to update that demand's own status. Null for any non-replacement shipment. */
    public function findDemandIdForShipment(PDO $pdo, int $shipmentId): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT rdo.replacement_demand_id
             FROM replacement_do_shipment_item rdsi
             INNER JOIN replacement_do rdo ON rdo.replacement_do_id = rdsi.replacement_do_id
             WHERE rdsi.shipment_id = ?
             LIMIT 1'
        );
        $stmt->execute([$shipmentId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** Total received_good_qty across EVERY shipment ever made against one demand's own Replacement DO — the "has this demand's full approved qty come back GOOD yet" figure REPL-19 needs. */
    public function sumReceivedGoodForDemand(PDO $pdo, int $demandId): float
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(ri.received_good_qty), 0)
             FROM shipment_receipt_item ri
             INNER JOIN replacement_do_shipment_item rdsi ON rdsi.replacement_do_shipment_item_id = ri.replacement_do_shipment_item_id
             INNER JOIN replacement_do rdo ON rdo.replacement_do_id = rdsi.replacement_do_id
             WHERE rdo.replacement_demand_id = ?"
        );
        $stmt->execute([$demandId]);
        return (float) $stmt->fetchColumn();
    }

    /** The product's own production division — used to resolve which division/factory scope a Replacement demand's production side belongs to (Auth::requireDivisionAccess()'s own argument), mirroring Production\ProductionService's own row-then-check pattern. */
    public function findProductDivisionId(PDO $pdo, int $productId): ?int
    {
        $stmt = $pdo->prepare('SELECT division_id FROM product WHERE product_id = ?');
        $stmt->execute([$productId]);
        $id = $stmt->fetchColumn();
        return $id !== false && $id !== null ? (int) $id : null;
    }

    /** True chain traceability: walks up parent_replacement_demand_id to the FIRST-generation demand's own store name, for the admin list "root reject" column. */
    public function findChainForDemand(PDO $pdo, int $demandId): array
    {
        $chain = [];
        $currentId = $demandId;
        $guard = 0;
        while ($currentId !== null && $guard < 20) {
            $stmt = $pdo->prepare('SELECT replacement_demand_id, parent_replacement_demand_id, status, approved_qty FROM replacement_demand WHERE replacement_demand_id = ?');
            $stmt->execute([$currentId]);
            $row = $stmt->fetch();
            if ($row === false) {
                break;
            }
            $chain[] = $row;
            $currentId = $row['parent_replacement_demand_id'] !== null ? (int) $row['parent_replacement_demand_id'] : null;
            $guard++;
        }
        return array_reverse($chain);
    }
}
