<?php

declare(strict_types=1);

namespace Amor\Api\Services;

use PDO;

/**
 * Server-side sequential document numbering (docs/mysql-schema-v1.md §7).
 * Reusable service only in Phase 0 — not yet wired into a real DO/Invoice
 * creation flow (that's a later phase; see item 19 "DO NOT DO YET").
 *
 * Uses the atomic INSERT ... ON DUPLICATE KEY UPDATE + LAST_INSERT_ID()
 * pattern: a single InnoDB statement, race-free under concurrent callers
 * without needing an explicit SELECT ... FOR UPDATE round trip. This is the
 * "atomic equivalent" §7 allows as an alternative to explicit row locking.
 *
 * Display formatting (e.g. "DO/KRM/{seq:3}/{romawiBulan}/{yyyy}") is
 * deliberately NOT done here — OD-2 in docs/mysql-open-decisions-v1.md is
 * still open on the exact format. This service only guarantees the number
 * itself is unique, sequential, and persisted.
 */
final class DocumentSequenceService
{
    /**
     * Allocates and returns the next sequential number for
     * (documentType, year, month). Call this inside the same DB transaction
     * as the document row it numbers, so a rollback of that transaction also
     * rolls back the allocation (no gap-free guarantee is claimed or needed —
     * only uniqueness and monotonicity within the key).
     */
    public static function allocate(PDO $pdo, string $documentType, int $year, int $month): int
    {
        // LAST_INSERT_ID(expr) only sets the session's LAST_INSERT_ID() when it is actually
        // evaluated. On the very first row for a (document_type, year, month) key, only the
        // VALUES branch runs (no conflict yet) — so LAST_INSERT_ID(1) must appear there too,
        // not just in the ON DUPLICATE KEY UPDATE branch, or the first allocation would read
        // back as 0 instead of 1.
        $stmt = $pdo->prepare(
            'INSERT INTO document_sequence (document_type, year, month, last_number)
             VALUES (?, ?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
        );
        $stmt->execute([$documentType, $year, $month]);

        return (int) $pdo->lastInsertId();
    }

    public static function currentValue(PDO $pdo, string $documentType, int $year, int $month): int
    {
        $stmt = $pdo->prepare(
            'SELECT last_number FROM document_sequence WHERE document_type = ? AND year = ? AND month = ?'
        );
        $stmt->execute([$documentType, $year, $month]);
        $v = $stmt->fetchColumn();
        return $v === false ? 0 : (int) $v;
    }
}
