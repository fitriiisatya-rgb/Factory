<?php

declare(strict_types=1);

namespace Amor\Api\Mail;

use PDO;

/**
 * Persistence for shipment_email_delivery (migration 0009) — ONE row per
 * shipment (UNIQUE KEY uq_email_delivery_shipment), created once at
 * departure time and updated in place on every later (re)send attempt.
 * The full attempt-by-attempt history (who triggered it, which recipient
 * was used, success/failure) lives in the EXISTING audit_log table via
 * Audit::write(), never a second bespoke history table here.
 */
final class ShipmentEmailRepository
{
    public function insert(PDO $pdo, int $shipmentId, int $storeId, ?string $recipientEmail, string $subject, string $status): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO shipment_email_delivery
                (shipment_id, store_id, recipient_email, subject, status, attempt_count, created_at)
             VALUES (?, ?, ?, ?, ?, 0, UTC_TIMESTAMP())'
        );
        $stmt->execute([$shipmentId, $storeId, $recipientEmail, $subject, $status]);
        return (int) $pdo->lastInsertId();
    }

    public function findByShipmentId(PDO $pdo, int $shipmentId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM shipment_email_delivery WHERE shipment_id = ?');
        $stmt->execute([$shipmentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM shipment_email_delivery WHERE shipment_email_delivery_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Records the outcome of one send attempt (automatic first send OR a
     * later Admin resend — always this same method, always this same
     * row). attempt_count always increments and last_attempt_at always
     * moves forward; first_attempt_at is set only the first time.
     * recipient_email is overwritten with whatever address THIS attempt
     * actually used (so a corrected Store email is reflected going
     * forward — the earlier attempt's original recipient stays visible
     * in audit_log, never lost).
     */
    public function recordAttempt(PDO $pdo, int $id, string $status, ?string $recipientEmail, ?string $lastError, bool $succeeded): void
    {
        $stmt = $pdo->prepare(
            "UPDATE shipment_email_delivery
                SET status = ?, recipient_email = ?, last_error = ?,
                    attempt_count = attempt_count + 1,
                    first_attempt_at = COALESCE(first_attempt_at, UTC_TIMESTAMP()),
                    last_attempt_at = UTC_TIMESTAMP(),
                    sent_at = " . ($succeeded ? 'UTC_TIMESTAMP()' : 'sent_at') . ",
                    updated_at = UTC_TIMESTAMP()
             WHERE shipment_email_delivery_id = ?"
        );
        $stmt->execute([$status, $recipientEmail, $lastError, $id]);
    }

    /**
     * The "no_email" / "MAIL_ENABLED=false" paths never touch the
     * network, so they update status/recipient WITHOUT incrementing
     * attempt_count (task's own "attempt_count increments" refers to
     * REAL attempts — MAIL-18 — never a same-request re-check that
     * short-circuits before any socket is opened).
     */
    public function recordSkipped(PDO $pdo, int $id, string $status, ?string $recipientEmail, ?string $lastError): void
    {
        $stmt = $pdo->prepare(
            'UPDATE shipment_email_delivery
                SET status = ?, recipient_email = ?, last_error = ?, updated_at = UTC_TIMESTAMP()
             WHERE shipment_email_delivery_id = ?'
        );
        $stmt->execute([$status, $recipientEmail, $lastError, $id]);
    }
}
