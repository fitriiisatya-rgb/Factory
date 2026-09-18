<?php

declare(strict_types=1);

namespace Amor\Api\Dispatch;

use PDO;

/**
 * Persistence for Phase 5.5 Parts G/H/I — the DO receipt QR token and the
 * store's shipment_receipt/shipment_receipt_item confirmation. Never reads
 * or writes delivery_order/shipment/shipment_item themselves beyond a plain
 * SELECT (those stay Delivery\DoRepository's job); this class only owns the
 * two new tables layered on top.
 */
final class ReceiptRepository
{
    /** Returns the existing token for this DO, or mints a brand-new high-entropy one. Never regenerates an existing token. */
    public function getOrCreateToken(PDO $pdo, int $doId): string
    {
        $stmt = $pdo->prepare('SELECT token FROM delivery_receipt_token WHERE delivery_order_id = ?');
        $stmt->execute([$doId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (string) $existing;
        }

        // 32 random bytes -> 64 hex chars: non-sequential, non-guessable
        // (task's own "high entropy / non-sequential / not guessable").
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = bin2hex(random_bytes(32));
            try {
                $ins = $pdo->prepare('INSERT INTO delivery_receipt_token (delivery_order_id, token, created_at) VALUES (?, ?, UTC_TIMESTAMP())');
                $ins->execute([$doId, $token]);
                return $token;
            } catch (\PDOException $e) {
                if ((int) $e->getCode() !== 23000) {
                    throw $e;
                }
                // Duplicate key: either another request just inserted this DO's
                // token (re-check), or an astronomically unlikely token
                // collision (retry with a fresh random value either way).
                $stmt->execute([$doId]);
                $existing = $stmt->fetchColumn();
                if ($existing !== false) {
                    return (string) $existing;
                }
            }
        }
        throw new \RuntimeException('Could not allocate a unique receipt token after 5 attempts');
    }

    /** Public lookup: token -> delivery_order_id, or null. The ONLY way any receipt endpoint resolves a DO. */
    public function findDoIdByToken(PDO $pdo, string $token): ?int
    {
        $stmt = $pdo->prepare('SELECT delivery_order_id FROM delivery_receipt_token WHERE token = ?');
        $stmt->execute([$token]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    public function findReceiptForShipment(PDO $pdo, int $shipmentId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM shipment_receipt WHERE shipment_id = ?');
        $stmt->execute([$shipmentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Row-locked (FOR UPDATE) — used inside the confirm transaction to serialize double-submits. */
    public function lockReceiptForShipment(PDO $pdo, int $shipmentId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM shipment_receipt WHERE shipment_id = ? FOR UPDATE');
        $stmt->execute([$shipmentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int,array> shipment_receipt_item rows for one receipt, product name joined */
    public function findReceiptItems(PDO $pdo, int $receiptId): array
    {
        $stmt = $pdo->prepare(
            'SELECT ri.*, p.name AS product_name FROM shipment_receipt_item ri
             INNER JOIN product p ON p.product_id = ri.product_id
             WHERE ri.shipment_receipt_id = ? ORDER BY p.name'
        );
        $stmt->execute([$receiptId]);
        return $stmt->fetchAll();
    }

    public function insertReceipt(PDO $pdo, int $shipmentId, string $status, ?string $receiverName, ?string $note): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO shipment_receipt (shipment_id, status, receiver_name, note, confirmed_at, version, created_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), 1, UTC_TIMESTAMP())"
        );
        $stmt->execute([$shipmentId, $status, $receiverName, $note]);
        return (int) $pdo->lastInsertId();
    }

    public function insertReceiptItem(PDO $pdo, int $receiptId, int $shipmentItemId, int $productId, float $shippedQty, float $good, float $reject, float $shortage, ?string $reason): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO shipment_receipt_item
                (shipment_receipt_id, shipment_item_id, product_id, shipped_qty, received_good_qty, reject_qty, shortage_qty, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$receiptId, $shipmentItemId, $productId, $shippedQty, $good, $reject, $shortage, $reason]);
    }

    /**
     * @return array<int,array> every shipment that has departed (i.e. exists at all — a
     * shipment row is only ever created by a real ship() commit) for a factory/date/status
     * filter, joined with its receipt (if any) — for the Admin "Konfirmasi Toko" page.
     */
    public function listForAdmin(PDO $pdo, ?string $tanggal, ?string $status): array
    {
        $sql = "SELECT sh.shipment_id, sh.tanggal, sh.store_id, sh.shipment_group, sh.shipped_at, sh.delivery_order_id,
                       s.canonical_name AS store_name, o.doc_no,
                       r.shipment_receipt_id, r.status AS receipt_status, r.receiver_name, r.confirmed_at, r.verified_at,
                       (SELECT COALESCE(SUM(qty), 0) FROM shipment_item WHERE shipment_id = sh.shipment_id) AS total_shipped,
                       (SELECT COALESCE(SUM(received_good_qty), 0) FROM shipment_receipt_item WHERE shipment_receipt_id = r.shipment_receipt_id) AS total_good,
                       (SELECT COALESCE(SUM(reject_qty), 0) FROM shipment_receipt_item WHERE shipment_receipt_id = r.shipment_receipt_id) AS total_reject,
                       (SELECT COALESCE(SUM(shortage_qty), 0) FROM shipment_receipt_item WHERE shipment_receipt_id = r.shipment_receipt_id) AS total_shortage
                FROM shipment sh
                INNER JOIN store s ON s.store_id = sh.store_id
                LEFT JOIN delivery_order o ON o.delivery_order_id = sh.delivery_order_id
                LEFT JOIN shipment_receipt r ON r.shipment_id = sh.shipment_id
                WHERE sh.status = 'active'";
        $params = [];
        if ($tanggal !== null) {
            $sql .= ' AND sh.tanggal = ?';
            $params[] = $tanggal;
        }
        if ($status === 'belum_dikonfirmasi') {
            $sql .= ' AND r.shipment_receipt_id IS NULL';
        } elseif ($status !== null) {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY sh.shipment_id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Row-locked (FOR UPDATE) for the admin verify transaction. */
    public function lockReceipt(PDO $pdo, int $receiptId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM shipment_receipt WHERE shipment_receipt_id = ? FOR UPDATE');
        $stmt->execute([$receiptId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markVerified(PDO $pdo, int $receiptId, int $adminUserId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE shipment_receipt SET status = 'verified', verified_by = ?, verified_at = UTC_TIMESTAMP(),
                    version = version + 1, updated_at = UTC_TIMESTAMP()
             WHERE shipment_receipt_id = ?"
        );
        $stmt->execute([$adminUserId, $receiptId]);
    }
}
