<?php

declare(strict_types=1);

namespace Amor\Api\Production;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Fg\FgService;
use PDO;

/**
 * Serah terima (migration 0025): a division hands part of what it has
 * produced to FG, several times a day. What is handed over is FG source at
 * once (see FgService::syncFromHandover()); checking and reject happen in
 * FG Verifikasi as before (owner decision: "verifikasi itu sudah termasuk
 * proses cek, reject dll").
 *
 * Rules (owner decisions Oct 2026):
 *  - a handover can never exceed what is "sudah jadi" (production actual)
 *    minus what was already handed over for that product;
 *  - the division may correct or take back a handover while its run is
 *    open, but never below what FG has already verified of that product;
 *  - only a draft/reopened run hands over (a submitted run already counts
 *    in full for FG).
 */
final class HandoverService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<int,array{productId:int,qty:float}> $items */
    public function create(int $runId, array $items, ?string $note, int $userId, ?string $operator, ?string $requestId): array
    {
        $run = $this->lockRun($runId);
        Auth::requireDivisionAccess((int) $run['division_id']);
        if (!in_array($run['status'], ['draft', 'reopened'], true)) {
            throw new ApiException(409, 'RUN_NOT_OPEN', 'Produksi ini sudah disubmit, jadi semua hasilnya sudah terhitung di FG.');
        }
        $lines = $this->validateLines($run, $items, null);
        if ($lines === []) {
            throw new ApiException(400, 'EMPTY_HANDOVER', 'Isi jumlah yang diserahkan ke FG.');
        }
        $seq = (int) $this->scalar('SELECT COALESCE(MAX(seq), 0) + 1 FROM production_handover WHERE production_run_id = ?', [$runId]);
        $this->pdo->prepare(
            'INSERT INTO production_handover (production_run_id, seq, status, note, created_by, created_operator_name, created_at)
             VALUES (?, ?, \'diserahkan\', ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$runId, $seq, $this->clip($note), $userId, $operator]);
        $handoverId = (int) $this->pdo->lastInsertId();
        $ins = $this->pdo->prepare('INSERT INTO production_handover_item (production_handover_id, product_id, qty) VALUES (?, ?, ?)');
        foreach ($lines as $productId => $qty) {
            $ins->execute([$handoverId, $productId, $qty]);
        }
        Audit::write($this->pdo, $requestId, $userId, 'production.handover.create', 'production_handover', (string) $handoverId, 'ok', null, null,
            ['runId' => $runId, 'seq' => $seq, 'items' => $lines, 'operatorName' => $operator]);
        $this->syncFg($run, $userId, $requestId);
        return $this->get($handoverId);
    }

    /** Correct (or take back with qty 0) a handover. */
    public function update(int $handoverId, array $items, int $userId, ?string $operator, ?string $requestId): array
    {
        $h = $this->lockHandover($handoverId);
        $run = $this->lockRun((int) $h['production_run_id']);
        $this->assertCanCorrect($run);
        $lines = $this->validateLines($run, $items, $handoverId, allowZero: true);
        foreach ($lines as $productId => $qty) {
            if ($qty <= 0.0001) {
                $this->pdo->prepare('DELETE FROM production_handover_item WHERE production_handover_id = ? AND product_id = ?')->execute([$handoverId, $productId]);
                continue;
            }
            $this->pdo->prepare(
                'INSERT INTO production_handover_item (production_handover_id, product_id, qty) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE qty = VALUES(qty)'
            )->execute([$handoverId, $productId, $qty]);
        }
        $left = (int) $this->scalar('SELECT COUNT(*) FROM production_handover_item WHERE production_handover_id = ?', [$handoverId]);
        if ($left === 0) {
            $this->pdo->prepare('DELETE FROM production_handover WHERE production_handover_id = ?')->execute([$handoverId]);
        } else {
            $this->pdo->prepare('UPDATE production_handover SET updated_at = UTC_TIMESTAMP() WHERE production_handover_id = ?')->execute([$handoverId]);
        }
        $this->assertNotBelowVerified($run, array_keys($lines));
        Audit::write($this->pdo, $requestId, $userId, $left === 0 ? 'production.handover.cancel' : 'production.handover.correct', 'production_handover', (string) $handoverId, 'ok', null, null,
            ['items' => $lines, 'operatorName' => $operator]);
        $this->syncFg($run, $userId, $requestId);
        return $left === 0 ? ['handoverId' => $handoverId, 'deleted' => true] : $this->get($handoverId);
    }

    public function get(int $handoverId): array
    {
        $list = $this->listWhere('ph.production_handover_id = ?', [$handoverId]);
        if ($list === []) {
            throw new ApiException(404, 'NOT_FOUND', 'Serah terima tidak ditemukan');
        }
        return $list[0];
    }

    public function listForRun(int $runId): array
    {
        return $this->listWhere('ph.production_run_id = ?', [$runId]);
    }

    /** @param int[]|null $divisionIds */
    public function listForDay(string $tanggal, int $factoryId, ?array $divisionIds = null): array
    {
        $where = 'r.tanggal = ? AND d.factory_id = ?';
        $params = [$tanggal, $factoryId];
        if ($divisionIds !== null) {
            if ($divisionIds === []) {
                return [];
            }
            $where .= ' AND d.division_id IN (' . implode(',', array_map('intval', $divisionIds)) . ')';
        }
        return $this->listWhere($where, $params);
    }

    /** @return array<int,float> product_id => qty handed over so far by this run (optionally without one handover) */
    public function handedByProduct(int $runId, ?int $exceptHandoverId = null): array
    {
        $sql = 'SELECT phi.product_id, SUM(phi.qty) FROM production_handover ph
                INNER JOIN production_handover_item phi ON phi.production_handover_id = ph.production_handover_id
                WHERE ph.production_run_id = ?';
        $params = [$runId];
        if ($exceptHandoverId !== null) {
            $sql .= ' AND ph.production_handover_id <> ?';
            $params[] = $exceptHandoverId;
        }
        $stmt = $this->pdo->prepare($sql . ' GROUP BY phi.product_id');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$pid, $qty]) {
            $out[(int) $pid] = (float) $qty;
        }
        return $out;
    }

    // -----------------------------------------------------------------

    private function listWhere(string $where, array $params): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ph.*, r.tanggal, r.division_id, d.name AS division_name, d.factory_id,
                    phi.product_id, p.name AS product_name, phi.qty
             FROM production_handover ph
             INNER JOIN production_run r ON r.production_run_id = ph.production_run_id
             INNER JOIN division d ON d.division_id = r.division_id
             INNER JOIN production_handover_item phi ON phi.production_handover_id = ph.production_handover_id
             INNER JOIN product p ON p.product_id = phi.product_id
             WHERE {$where}
             ORDER BY ph.created_at, ph.production_handover_id, p.name"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['production_handover_id'];
            $out[$id] ??= [
                'handoverId' => $id, 'runId' => (int) $r['production_run_id'], 'seq' => (int) $r['seq'],
                'tanggal' => $r['tanggal'], 'divisionId' => (int) $r['division_id'], 'divisionName' => $r['division_name'],
                'note' => $r['note'], 'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at'], 'createdBy' => $r['created_operator_name'],
                'totalQty' => 0.0, 'items' => [],
            ];
            $out[$id]['items'][] = [
                'productId' => (int) $r['product_id'], 'productName' => $r['product_name'], 'qty' => (float) $r['qty'],
            ];
            $out[$id]['totalQty'] += (float) $r['qty'];
        }
        return array_values($out);
    }

    /** @return array<int,float> validated product_id => qty */
    private function validateLines(array $run, array $items, ?int $exceptHandoverId, bool $allowZero = false): array
    {
        $actual = [];
        $stmt = $this->pdo->prepare('SELECT pi.product_id, pi.aktual, p.name FROM production_item pi INNER JOIN product p ON p.product_id = pi.product_id WHERE pi.production_run_id = ?');
        $stmt->execute([(int) $run['production_run_id']]);
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $actual[(int) $r['product_id']] = (float) $r['aktual'];
            $names[(int) $r['product_id']] = $r['name'];
        }
        $handed = $this->handedByProduct((int) $run['production_run_id'], $exceptHandoverId);
        $out = [];
        foreach ($items as $line) {
            $productId = (int) ($line['productId'] ?? 0);
            $qty = round((float) ($line['qty'] ?? 0), 2);
            if (!isset($actual[$productId])) {
                throw new ApiException(400, 'UNKNOWN_PRODUCT_FOR_RUN', 'Produk ini belum ada di lembar produksi divisi ini.');
            }
            if ($qty < 0 || (!$allowZero && $qty <= 0.0001)) {
                if ($qty < 0) {
                    throw new ApiException(400, 'INVALID_QTY', 'Jumlah tidak boleh negatif.');
                }
                continue;
            }
            $left = $actual[$productId] - ($handed[$productId] ?? 0.0);
            if ($qty > $left + 0.0001) {
                throw new ApiException(400, 'HANDOVER_EXCEEDS_PRODUCED', "{$names[$productId]}: yang diserahkan ({$qty}) melebihi yang sudah jadi dan belum diserahkan (" . max(0, round($left, 2)) . '). Simpan dulu jumlah "sudah jadi".');
            }
            $out[$productId] = $qty;
        }
        return $out;
    }

    private function assertCanCorrect(array $run): void
    {
        Auth::requireDivisionAccess((int) $run['division_id']);
        if (!in_array($run['status'], ['draft', 'reopened'], true)) {
            throw new ApiException(409, 'RUN_NOT_OPEN', 'Produksi ini sudah disubmit. Buka kembali dulu untuk mengoreksi serah terima.');
        }
    }

    /**
     * A correction may not take away goods FG has already verified — the
     * FG ceiling would drop under the verified qty.
     * @param int[] $productIds
     */
    private function assertNotBelowVerified(array $run, array $productIds): void
    {
        if ($productIds === []) {
            return;
        }
        $source = (new \Amor\Api\Fg\FgTargetService())->productionActualByProduct($this->pdo, (string) $run['tanggal'], (int) $run['factory_id']);
        $stmt = $this->pdo->prepare(
            'SELECT fi.product_id, p.name, SUM(fi.qty) FROM fg_item fi
             INNER JOIN fg_batch b ON b.fg_batch_id = fi.fg_batch_id
             INNER JOIN product p ON p.product_id = fi.product_id
             WHERE b.tanggal = ? AND b.factory_id = ? AND fi.product_id IN (' . implode(',', array_map('intval', $productIds)) . ')
             GROUP BY fi.product_id, p.name'
        );
        $stmt->execute([(string) $run['tanggal'], (int) $run['factory_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$pid, $name, $verified]) {
            $left = (float) ($source[(int) $pid]['actual'] ?? 0.0);
            if ((float) $verified > $left + 0.0001) {
                throw new ApiException(409, 'HANDOVER_BELOW_VERIFIED', "{$name}: FG sudah memverifikasi " . round((float) $verified, 2) . ' pcs, jadi serah terima tidak bisa dikurangi di bawah itu. Minta FG mengoreksi verifikasinya dulu.');
            }
        }
    }

    private function syncFg(array $run, int $userId, ?string $requestId): void
    {
        (new FgService($this->pdo))->syncFromHandover((string) $run['tanggal'], (int) $run['factory_id'], $userId, $requestId);
    }

    private function lockRun(int $runId): array
    {
        $stmt = $this->pdo->prepare('SELECT r.*, d.factory_id FROM production_run r INNER JOIN division d ON d.division_id = r.division_id WHERE r.production_run_id = ? FOR UPDATE');
        $stmt->execute([$runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($run === false) {
            throw new ApiException(404, 'NOT_FOUND', 'Production document not found');
        }
        return $run;
    }

    private function lockHandover(int $handoverId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM production_handover WHERE production_handover_id = ? FOR UPDATE');
        $stmt->execute([$handoverId]);
        $h = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($h === false) {
            throw new ApiException(404, 'NOT_FOUND', 'Serah terima tidak ditemukan');
        }
        return $h;
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function clip(?string $s): ?string
    {
        $s = $s !== null ? trim($s) : '';
        return $s === '' ? null : mb_substr($s, 0, 255);
    }
}
