<?php

declare(strict_types=1);

namespace Amor\Api\StorePortal;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Services\DocumentSequenceService;
use PDO;

/**
 * Store reports (migration 0026): what the stores' Google Apps Script form
 * did, inside Factory. One transaction = one store, one date, many items
 * of three kinds:
 *
 *   - retur   -> retur_request jenis='retur'        (record only, store burden)
 *   - reject  -> retur_request jenis='reject_toko'  (factory burden, Nota Potongan)
 *   - mutasi  -> mutasi_request                     (destination store confirms)
 *
 * Flow: store submits (status menunggu_atasan) -> the Head Store (ATASAN
 * user mapped to the store in user_store_access) approves -> Admin checks
 * the goods physically per item (Sesuai / Tidak Sesuai + catatan). A store
 * with no Atasan is approved automatically, so nothing ever waits on a
 * person who does not exist. While waiting, the store may still edit the
 * transaction, and its items are invisible to Admin and to the mutasi
 * destination store (see ReturRepository::reportApproved()).
 */
final class StoreReportService
{
    private const MAX_ITEMS = 60;
    private const JENIS = ['retur', 'reject', 'mutasi'];

    private ReturRepository $returRepo;
    private MutasiRepository $mutasiRepo;

    public function __construct(private PDO $pdo)
    {
        $this->returRepo = new ReturRepository();
        $this->mutasiRepo = new MutasiRepository();
    }

    // ------------------------------------------------------------------
    // Master data
    // ------------------------------------------------------------------

    /** @return array<int,array{id:int,name:string,sortOrder:int,active:bool}> */
    public function reasons(bool $activeOnly = true): array
    {
        $sql = 'SELECT reject_reason_id, name, sort_order, active FROM reject_reason' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name';
        return array_map(static fn ($r) => [
            'id' => (int) $r['reject_reason_id'], 'name' => $r['name'], 'sortOrder' => (int) $r['sort_order'], 'active' => (bool) $r['active'],
        ], $this->pdo->query($sql)->fetchAll());
    }

    public function saveReason(?int $id, string $name, int $sortOrder, bool $active, int $userId, ?string $requestId): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 150) {
            throw new ApiException(400, 'INVALID_NAME', 'Nama alasan wajib diisi (maksimal 150 huruf)');
        }
        $dup = $this->pdo->prepare('SELECT reject_reason_id FROM reject_reason WHERE name = ? AND reject_reason_id <> ?');
        $dup->execute([$name, $id ?? 0]);
        if ($dup->fetchColumn() !== false) {
            throw new ApiException(409, 'DUPLICATE_NAME', 'Alasan ini sudah ada');
        }
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO reject_reason (name, sort_order, active, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$name, $sortOrder, $active ? 1 : 0]);
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $st = $this->pdo->prepare('UPDATE reject_reason SET name = ?, sort_order = ?, active = ?, updated_at = UTC_TIMESTAMP() WHERE reject_reason_id = ?');
            $st->execute([$name, $sortOrder, $active ? 1 : 0, $id]);
            if ($st->rowCount() === 0 && !$this->reasonExists($id)) {
                throw new ApiException(404, 'NOT_FOUND', 'Alasan tidak ditemukan');
            }
        }
        Audit::write($this->pdo, $requestId, $userId, 'reject_reason.saved', 'reject_reason', (string) $id, 'ok', null, null, ['name' => $name, 'active' => $active]);
        return ['id' => $id, 'name' => $name, 'sortOrder' => $sortOrder, 'active' => $active];
    }

    private function reasonExists(int $id): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM reject_reason WHERE reject_reason_id = ?');
        $st->execute([$id]);
        return $st->fetchColumn() !== false;
    }

    /** True when at least one active ATASAN account supervises this store. */
    public function storeHasAtasan(int $storeId): bool
    {
        $st = $this->pdo->prepare(
            "SELECT 1 FROM user_store_access usa
             INNER JOIN users u ON u.user_id = usa.user_id AND u.active = 1
             INNER JOIN user_roles ur ON ur.user_id = u.user_id
             INNER JOIN roles r ON r.role_id = ur.role_id AND r.code = 'ATASAN'
             WHERE usa.store_id = ? LIMIT 1"
        );
        $st->execute([$storeId]);
        return $st->fetchColumn() !== false;
    }

    /** @return int[] stores supervised by this Atasan */
    public function storeIdsForAtasan(int $userId): array
    {
        $st = $this->pdo->prepare('SELECT store_id FROM user_store_access WHERE user_id = ? ORDER BY store_id');
        $st->execute([$userId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    // ------------------------------------------------------------------
    // Store portal: create / edit / history
    // ------------------------------------------------------------------

    /**
     * @param array<int,array<int,array{filePath:string,mimeType:string,fileSize:int,originalName:?string}>> $evidenceByIndex item index => stored files
     */
    public function createForPortal(int $storeId, array $input, array $evidenceByIndex, ?int $tokenId, ?string $requestId): array
    {
        [$date, $destinationId, $items] = $this->validateInput($storeId, $input, $evidenceByIndex, []);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $docNo = sprintf('TRX-%s-%03d', $now->format('Ymd'), DocumentSequenceService::allocate($this->pdo, 'TRX', (int) $now->format('Y'), (int) $now->format('n')));
        $auto = !$this->storeHasAtasan($storeId);

        $this->pdo->prepare(
            "INSERT INTO store_report (doc_no, store_id, report_date, status, approval_mode, approved_at, submitted_via_token_id, version, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())"
        )->execute([$docNo, $storeId, $date, $auto ? 'disetujui' : 'menunggu_atasan', $auto ? 'otomatis' : null, $auto ? $now->format('Y-m-d H:i:s') : null, $tokenId]);
        $reportId = (int) $this->pdo->lastInsertId();

        foreach ($items as $i => $it) {
            $this->insertItem($reportId, $storeId, $date, $destinationId, $it, $evidenceByIndex[$i] ?? [], $tokenId);
        }
        Audit::write($this->pdo, $requestId, null, 'store_report.created', 'store_report', (string) $reportId, 'ok', null, 1,
            ['storeId' => $storeId, 'docNo' => $docNo, 'items' => count($items), 'auto' => $auto]);
        return $this->get($reportId);
    }

    public function updateForPortal(int $storeId, int $reportId, array $input, array $evidenceByIndex, ?int $tokenId, ?string $requestId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM store_report WHERE store_report_id = ? FOR UPDATE');
        $st->execute([$reportId]);
        $report = $st->fetch();
        if (!$report || (int) $report['store_id'] !== $storeId) {
            throw new ApiException(404, 'NOT_FOUND', 'Transaksi tidak ditemukan untuk toko ini');
        }
        if ($report['status'] !== 'menunggu_atasan') {
            throw new ApiException(409, 'ALREADY_APPROVED', 'Transaksi sudah disetujui Atasan, tidak bisa diedit lagi');
        }
        $existing = $this->existingItems($reportId);
        [$date, $destinationId, $items] = $this->validateInput($storeId, $input, $evidenceByIndex, $existing);

        $kept = [];
        foreach ($items as $i => $it) {
            $key = $it['existingKey'];
            if ($key !== null && isset($existing[$key])) {
                $this->updateItem($existing[$key], $date, $destinationId, $it, $evidenceByIndex[$i] ?? []);
                $kept[$key] = true;
            } else {
                $this->insertItem($reportId, $storeId, $date, $destinationId, $it, $evidenceByIndex[$i] ?? [], $tokenId);
            }
        }
        foreach ($existing as $key => $row) {
            if (!isset($kept[$key])) {
                $this->deleteItem($row);
            }
        }
        $this->pdo->prepare('UPDATE store_report SET report_date = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE store_report_id = ?')
            ->execute([$date, $reportId]);
        Audit::write($this->pdo, $requestId, null, 'store_report.updated', 'store_report', (string) $reportId, 'ok', (int) $report['version'], (int) $report['version'] + 1,
            ['storeId' => $storeId, 'items' => count($items)]);
        return $this->get($reportId);
    }

    /**
     * @return array{0:string,1:?int,2:array<int,array>} date, destination store, normalized items
     */
    private function validateInput(int $storeId, array $input, array $evidenceByIndex, array $existing): array
    {
        $tz = new \DateTimeZone('Asia/Jakarta');
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $date = trim((string) ($input['tanggal'] ?? ''));
        if ($date === '') {
            $date = $today;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today) {
            throw new ApiException(400, 'INVALID_DATE', 'Tanggal tidak valid (tidak boleh lebih dari hari ini)');
        }
        $rawItems = $input['items'] ?? [];
        if (!is_array($rawItems) || $rawItems === []) {
            throw new ApiException(400, 'NO_ITEMS', 'Tambahkan minimal 1 item (Retur, Reject, atau Mutasi)');
        }
        if (count($rawItems) > self::MAX_ITEMS) {
            throw new ApiException(400, 'TOO_MANY_ITEMS', 'Maksimal ' . self::MAX_ITEMS . ' item per transaksi');
        }

        $products = [];
        $items = [];
        $hasMutasi = false;
        foreach (array_values($rawItems) as $i => $raw) {
            $no = $i + 1;
            $jenis = (string) ($raw['jenis'] ?? '');
            if (!in_array($jenis, self::JENIS, true)) {
                throw new ApiException(400, 'INVALID_JENIS', "Item #{$no}: jenis harus Retur, Reject, atau Mutasi");
            }
            $productId = (int) ($raw['productId'] ?? 0);
            if (!isset($products[$productId])) {
                $p = $this->pdo->prepare('SELECT product_id, name FROM product WHERE product_id = ?');
                $p->execute([$productId]);
                $products[$productId] = $p->fetch() ?: null;
            }
            if ($products[$productId] === null) {
                throw new ApiException(400, 'PRODUCT_NOT_FOUND', "Item #{$no}: pilih produk dari daftar");
            }
            $label = $products[$productId]['name'];
            $qty = (float) ($raw['qty'] ?? 0);
            if ($qty <= 0 || $qty > 100000) {
                throw new ApiException(400, 'INVALID_QTY', "{$label}: jumlah harus lebih dari 0");
            }
            $alasan = trim((string) ($raw['alasan'] ?? ''));
            $expDate = trim((string) ($raw['expDate'] ?? ''));
            $notes = trim((string) ($raw['notes'] ?? ''));
            if (mb_strlen($alasan) > 300 || mb_strlen($notes) > 500) {
                throw new ApiException(400, 'TEXT_TOO_LONG', "{$label}: alasan/catatan terlalu panjang");
            }
            $existingKey = null;
            if (!empty($raw['itemId']) && in_array((string) ($raw['itemType'] ?? ''), ['retur', 'mutasi'], true)) {
                $existingKey = $raw['itemType'] . ':' . (int) $raw['itemId'];
                if (!isset($existing[$existingKey])) {
                    throw new ApiException(409, 'ITEM_CHANGED', 'Data transaksi sudah berubah, muat ulang Riwayat');
                }
                // Changing an item's kind turns it into a new item.
                $oldJenis = $existing[$existingKey]['_jenis'];
                if ($oldJenis !== $jenis) {
                    $existingKey = null;
                }
            }
            $evidenceCount = count($evidenceByIndex[$i] ?? []) + ($existingKey !== null ? $existing[$existingKey]['_evidence'] : 0);

            if ($jenis === 'reject') {
                if ($alasan === '') {
                    throw new ApiException(400, 'REASON_REQUIRED', "{$label}: pilih alasan reject");
                }
                if ($expDate !== '') {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expDate)) {
                        throw new ApiException(400, 'INVALID_EXP_DATE', "{$label}: tanggal expired tidak valid");
                    }
                    if ($expDate < $date) {
                        throw new ApiException(400, 'ALREADY_EXPIRED', "{$label}: sudah lewat tanggal expired — ini bukan Reject, masukkan sebagai Retur");
                    }
                }
                if ($evidenceCount === 0) {
                    throw new ApiException(400, 'EVIDENCE_REQUIRED', "{$label}: foto bukti wajib untuk Reject");
                }
            }
            if ($jenis === 'mutasi') {
                $hasMutasi = true;
            }
            $items[$i] = [
                'jenis' => $jenis, 'productId' => $productId, 'qty' => $qty,
                'alasan' => $alasan, 'expDate' => $expDate !== '' ? $expDate : null,
                'notes' => $notes !== '' ? $notes : null, 'existingKey' => $existingKey,
            ];
        }

        $destinationId = null;
        if ($hasMutasi) {
            $destinationId = (int) ($input['destinationStoreId'] ?? 0);
            if ($destinationId <= 0) {
                throw new ApiException(400, 'DESTINATION_STORE_REQUIRED', 'Pilih Toko Tujuan untuk item mutasi');
            }
            if ($destinationId === $storeId) {
                throw new ApiException(400, 'SAME_STORE', 'Toko Tujuan mutasi tidak boleh sama dengan toko asal');
            }
            $s = $this->pdo->prepare("SELECT 1 FROM store WHERE store_id = ? AND active = 1 AND canonical_name <> 'NON-OUTLET / PERORANGAN'");
            $s->execute([$destinationId]);
            if ($s->fetchColumn() === false) {
                throw new ApiException(400, 'DESTINATION_STORE_NOT_FOUND', 'Toko Tujuan tidak ditemukan atau tidak aktif');
            }
        }
        return [$date, $destinationId, $items];
    }

    private function insertItem(int $reportId, int $storeId, string $date, ?int $destinationId, array $it, array $evidence, ?int $tokenId): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($it['jenis'] === 'mutasi') {
            $docNo = sprintf('MUTASI-%s-%03d', $now->format('Ymd'), DocumentSequenceService::allocate($this->pdo, 'MUTASI', (int) $now->format('Y'), (int) $now->format('n')));
            $id = $this->mutasiRepo->insert($this->pdo, $docNo, $storeId, (int) $destinationId, $it['productId'], $it['qty'], $it['notes'], $tokenId, $reportId);
            foreach ($evidence as $ev) {
                $this->mutasiRepo->insertEvidence($this->pdo, $id, 'request', $ev['filePath'], $ev['mimeType'], $ev['fileSize'], $ev['originalName']);
            }
            return;
        }
        $isReject = $it['jenis'] === 'reject';
        $prefix = $isReject ? 'RJT' : 'RETUR';
        $docNo = sprintf('%s-%s-%03d', $prefix, $now->format('Ymd'), DocumentSequenceService::allocate($this->pdo, $prefix, (int) $now->format('Y'), (int) $now->format('n')));
        $reason = $it['alasan'] !== '' ? $it['alasan'] : 'Tidak laku';
        $id = $this->returRepo->insert($this->pdo, $docNo, $storeId, $it['productId'], $it['qty'], $reason, $it['notes'], $date, $tokenId,
            $isReject ? 'reject_toko' : 'retur', null, $isReject ? $it['expDate'] : null, $reportId);
        foreach ($evidence as $ev) {
            $this->returRepo->insertEvidence($this->pdo, $id, $ev['filePath'], $ev['mimeType'], $ev['fileSize'], $ev['originalName']);
        }
    }

    private function updateItem(array $row, string $date, ?int $destinationId, array $it, array $evidence): void
    {
        if ($row['_type'] === 'mutasi') {
            $this->pdo->prepare('UPDATE mutasi_request SET product_id = ?, qty_requested = ?, request_notes = ?, destination_store_id = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE mutasi_request_id = ?')
                ->execute([$it['productId'], $it['qty'], $it['notes'], (int) $destinationId, (int) $row['mutasi_request_id']]);
            if ($evidence !== []) {
                // New photos replace the previous ones.
                $this->pdo->prepare("DELETE FROM mutasi_request_evidence WHERE mutasi_request_id = ? AND stage = 'request'")->execute([(int) $row['mutasi_request_id']]);
            }
            foreach ($evidence as $ev) {
                $this->mutasiRepo->insertEvidence($this->pdo, (int) $row['mutasi_request_id'], 'request', $ev['filePath'], $ev['mimeType'], $ev['fileSize'], $ev['originalName']);
            }
            return;
        }
        $isReject = $it['jenis'] === 'reject';
        $this->pdo->prepare('UPDATE retur_request SET product_id = ?, qty = ?, reason = ?, notes = ?, retur_date = ?, exp_date = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE retur_request_id = ?')
            ->execute([$it['productId'], $it['qty'], $it['alasan'] !== '' ? $it['alasan'] : 'Tidak laku', $it['notes'], $date, $isReject ? $it['expDate'] : null, (int) $row['retur_request_id']]);
        if ($evidence !== []) {
            // New photos replace the previous ones.
            $this->pdo->prepare('DELETE FROM retur_request_evidence WHERE retur_request_id = ?')->execute([(int) $row['retur_request_id']]);
        }
        foreach ($evidence as $ev) {
            $this->returRepo->insertEvidence($this->pdo, (int) $row['retur_request_id'], $ev['filePath'], $ev['mimeType'], $ev['fileSize'], $ev['originalName']);
        }
    }

    private function deleteItem(array $row): void
    {
        if ($row['_type'] === 'mutasi') {
            $id = (int) $row['mutasi_request_id'];
            $this->pdo->prepare('DELETE FROM mutasi_request_evidence WHERE mutasi_request_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM mutasi_request WHERE mutasi_request_id = ?')->execute([$id]);
            return;
        }
        $id = (int) $row['retur_request_id'];
        $this->pdo->prepare('DELETE FROM retur_request_evidence WHERE retur_request_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM retur_request WHERE retur_request_id = ?')->execute([$id]);
    }

    /** @return array<string,array> "retur:ID" / "mutasi:ID" => row (+ _type, _jenis, _evidence) */
    private function existingItems(int $reportId): array
    {
        $out = [];
        $st = $this->pdo->prepare('SELECT rr.*, (SELECT COUNT(*) FROM retur_request_evidence e WHERE e.retur_request_id = rr.retur_request_id) AS ev FROM retur_request rr WHERE rr.store_report_id = ?');
        $st->execute([$reportId]);
        foreach ($st->fetchAll() as $r) {
            $r['_type'] = 'retur';
            $r['_jenis'] = $r['jenis'] === 'reject_toko' ? 'reject' : 'retur';
            $r['_evidence'] = (int) $r['ev'];
            $out['retur:' . $r['retur_request_id']] = $r;
        }
        $st = $this->pdo->prepare("SELECT mr.*, (SELECT COUNT(*) FROM mutasi_request_evidence e WHERE e.mutasi_request_id = mr.mutasi_request_id AND e.stage = 'request') AS ev FROM mutasi_request mr WHERE mr.store_report_id = ?");
        $st->execute([$reportId]);
        foreach ($st->fetchAll() as $r) {
            $r['_type'] = 'mutasi';
            $r['_jenis'] = 'mutasi';
            $r['_evidence'] = (int) $r['ev'];
            $out['mutasi:' . $r['mutasi_request_id']] = $r;
        }
        return $out;
    }

    /** One transaction with its items, for the portal, print and admin views. */
    public function get(int $reportId, ?int $storeId = null): array
    {
        $st = $this->pdo->prepare(
            "SELECT sr.*, (SELECT COUNT(*) FROM audit_log a WHERE a.record_type = 'store_report' AND a.record_key = CAST(sr.store_report_id AS CHAR) AND a.action = 'store_report.updated') AS edit_count, s.canonical_name AS store_name, u.full_name AS approved_by_name
             FROM store_report sr
             INNER JOIN store s ON s.store_id = sr.store_id
             LEFT JOIN users u ON u.user_id = sr.approved_by
             WHERE sr.store_report_id = ?"
        );
        $st->execute([$reportId]);
        $r = $st->fetch();
        if (!$r || ($storeId !== null && (int) $r['store_id'] !== $storeId)) {
            throw new ApiException(404, 'NOT_FOUND', 'Transaksi tidak ditemukan');
        }
        $items = $this->itemRows(['report' => $reportId]);
        return $this->reportDto($r, $items);
    }

    /** Portal "Riwayat Input": this store's transactions, date range + product search, with per-kind totals. */
    public function listForStore(int $storeId, ?string $from, ?string $to, ?string $q): array
    {
        $sql = "SELECT sr.*, (SELECT COUNT(*) FROM audit_log a WHERE a.record_type = 'store_report' AND a.record_key = CAST(sr.store_report_id AS CHAR) AND a.action = 'store_report.updated') AS edit_count, s.canonical_name AS store_name, u.full_name AS approved_by_name
                FROM store_report sr
                INNER JOIN store s ON s.store_id = sr.store_id
                LEFT JOIN users u ON u.user_id = sr.approved_by
                WHERE sr.store_id = ?";
        $params = [$storeId];
        if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $sql .= ' AND sr.report_date >= ?';
            $params[] = $from;
        }
        if ($to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $sql .= ' AND sr.report_date <= ?';
            $params[] = $to;
        }
        $sql .= ' ORDER BY sr.report_date DESC, sr.store_report_id DESC LIMIT 300';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $reports = $st->fetchAll();
        $byReport = [];
        if ($reports !== []) {
            foreach ($this->itemRows(['reports' => array_map(static fn ($r) => (int) $r['store_report_id'], $reports)]) as $it) {
                $byReport[$it['reportId']][] = $it;
            }
        }
        $q = $q !== null ? mb_strtolower(trim($q)) : '';
        $list = [];
        $sum = ['retur' => 0.0, 'reject' => 0.0, 'mutasi' => 0.0];
        foreach ($reports as $r) {
            $items = $byReport[(int) $r['store_report_id']] ?? [];
            if ($q !== '') {
                $items = array_values(array_filter($items, static fn ($it) => str_contains(mb_strtolower($it['productName']), $q)));
                if ($items === []) {
                    continue;
                }
            }
            foreach ($items as $it) {
                // A mutasi Admin cancelled (Tidak Sesuai) never moved — same rule as the dashboard.
                if ($it['jenis'] === 'mutasi' && $it['itemStatus'] === 'cancelled') {
                    continue;
                }
                $sum[$it['jenis']] += $it['qty'];
            }
            $list[] = $this->reportDto($r, $items);
        }
        return ['list' => $list, 'ringkasan' => $sum];
    }

    // ------------------------------------------------------------------
    // Atasan
    // ------------------------------------------------------------------

    public function pendingForAtasan(int $userId): array
    {
        $storeIds = $this->storeIdsForAtasan($userId);
        if ($storeIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($storeIds), '?'));
        $st = $this->pdo->prepare(
            "SELECT sr.*, s.canonical_name AS store_name, NULL AS approved_by_name
             FROM store_report sr INNER JOIN store s ON s.store_id = sr.store_id
             WHERE sr.status = 'menunggu_atasan' AND sr.store_id IN ({$in})
             ORDER BY sr.report_date DESC, sr.store_report_id DESC"
        );
        $st->execute($storeIds);
        $reports = $st->fetchAll();
        if ($reports === []) {
            return [];
        }
        $byReport = [];
        foreach ($this->itemRows(['reports' => array_map(static fn ($r) => (int) $r['store_report_id'], $reports)]) as $it) {
            $byReport[$it['reportId']][] = $it;
        }
        return array_map(fn ($r) => $this->reportDto($r, $byReport[(int) $r['store_report_id']] ?? []), $reports);
    }

    /** @param int[] $reportIds */
    public function approve(int $userId, array $reportIds, ?string $requestId): array
    {
        $storeIds = $this->storeIdsForAtasan($userId);
        $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds))));
        if ($reportIds === []) {
            throw new ApiException(400, 'NO_SELECTION', 'Pilih minimal satu transaksi');
        }
        $updated = 0;
        foreach ($reportIds as $id) {
            $st = $this->pdo->prepare('SELECT store_id, status, version FROM store_report WHERE store_report_id = ? FOR UPDATE');
            $st->execute([$id]);
            $r = $st->fetch();
            if (!$r || !in_array((int) $r['store_id'], $storeIds, true) || $r['status'] !== 'menunggu_atasan') {
                continue;
            }
            $this->pdo->prepare("UPDATE store_report SET status = 'disetujui', approval_mode = 'atasan', approved_by = ?, approved_at = UTC_TIMESTAMP(), version = version + 1, updated_at = UTC_TIMESTAMP() WHERE store_report_id = ?")
                ->execute([$userId, $id]);
            Audit::write($this->pdo, $requestId, $userId, 'store_report.approved', 'store_report', (string) $id, 'ok', (int) $r['version'], (int) $r['version'] + 1, null);
            $updated++;
        }
        if ($updated === 0) {
            throw new ApiException(409, 'NOTHING_APPROVED', 'Transaksi tidak ditemukan, sudah disetujui, atau bukan toko binaan Anda. Muat ulang daftar.');
        }
        return ['updated' => $updated];
    }

    // ------------------------------------------------------------------
    // Admin: physical validation
    // ------------------------------------------------------------------

    /** @param array{factoryId?:?int,storeId?:?int,jenis?:?string,from?:?string,to?:?string,onlyWaiting?:bool} $f */
    public function validasiList(array $f): array
    {
        $items = $this->itemRows(['approved' => true] + $f);
        $sum = ['menunggu' => 0, 'sesuai' => 0, 'tidakSesuai' => 0];
        foreach ($items as $it) {
            $k = $it['validasiStatus'] === 'tidak_sesuai' ? 'tidakSesuai' : $it['validasiStatus'];
            $sum[$k]++;
        }
        if (!empty($f['onlyWaiting'])) {
            $items = array_values(array_filter($items, static fn ($it) => $it['validasiStatus'] === 'menunggu'));
        }
        return ['items' => $items, 'ringkasan' => $sum];
    }

    /**
     * Sesuai / Tidak Sesuai for one item. Retur and Reject also take the
     * matching decision in the existing flow (Retur verified/rejected;
     * Reject potong|ganti or rejected), so there is one place to work.
     */
    public function validasi(string $type, int $id, string $status, string $note, ?string $tindakLanjut, int $adminUserId, ?string $requestId): array
    {
        if (!in_array($status, ['sesuai', 'tidak_sesuai'], true)) {
            throw new ApiException(400, 'INVALID_STATUS', 'Status validasi tidak valid');
        }
        $note = trim($note);
        if ($status === 'tidak_sesuai' && $note === '') {
            throw new ApiException(400, 'NOTE_REQUIRED', 'Tulis catatan ketidaksesuaian (misalnya selisih qty, barang tidak ada)');
        }
        if ($type === 'mutasi') {
            $row = $this->mutasiRepo->lockById($this->pdo, $id);
            if ($row === null || !ReturRepository::reportApproved($this->pdo, isset($row['store_report_id']) ? (int) $row['store_report_id'] : null)) {
                throw new ApiException(404, 'NOT_FOUND', 'Item tidak ditemukan');
            }
            if ($row['status'] === 'cancelled') {
                throw new ApiException(409, 'MUTASI_CANCELLED', 'Mutasi ini sudah dibatalkan');
            }
            if ($status === 'tidak_sesuai') {
                // Owner decision (Oct 2026): Admin's physical check is the last
                // word — "Tidak Sesuai" cancels the mutasi so the bill never
                // moves to the destination store. Only possible while neither
                // side has been billed for it yet.
                $billed = $this->pdo->prepare(
                    'SELECT (SELECT COUNT(*) FROM invoice_mutasi WHERE mutasi_request_id = ?) + (SELECT COUNT(*) FROM nota_potongan WHERE mutasi_request_id = ?)'
                );
                $billed->execute([$id, $id]);
                if ((int) $billed->fetchColumn() > 0) {
                    throw new ApiException(409, 'MUTASI_ALREADY_INVOICED', 'Mutasi ini sudah masuk invoice / nota potongan. Batalkan invoice-nya dulu di menu Invoice, baru tandai Tidak Sesuai.');
                }
                $this->mutasiRepo->markAdminReviewed($this->pdo, $id, 'cancelled', 'Tidak sesuai fisik: ' . $note, $adminUserId);
            }
            $this->pdo->prepare('UPDATE mutasi_request SET validasi_status = ?, validasi_note = ?, validasi_by = ?, validasi_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE mutasi_request_id = ?')
                ->execute([$status, $status === 'tidak_sesuai' ? $note : null, $adminUserId, $id]);
            Audit::write($this->pdo, $requestId, $adminUserId, 'mutasi.validasi', 'mutasi_request', (string) $id, 'ok', null, null,
                ['status' => $status, 'cancelled' => $status === 'tidak_sesuai']);
            return ['ok' => true];
        }
        if ($type !== 'retur') {
            throw new ApiException(400, 'INVALID_TYPE', 'Jenis item tidak dikenal');
        }
        $row = $this->returRepo->lockById($this->pdo, $id);
        if ($row === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Item tidak ditemukan');
        }
        $this->pdo->prepare('UPDATE retur_request SET validasi_status = ?, validasi_note = ?, validasi_by = ?, validasi_at = UTC_TIMESTAMP() WHERE retur_request_id = ?')
            ->execute([$status, $status === 'tidak_sesuai' ? $note : null, $adminUserId, $id]);
        $svc = new ReturService($this->pdo);
        if ($status === 'tidak_sesuai') {
            $svc->adminReject($id, 'Tidak sesuai fisik: ' . $note, $adminUserId, $requestId);
        } else {
            $svc->adminVerify($id, $adminUserId, $requestId, $row['jenis'] === 'reject_toko' ? $tindakLanjut : null);
        }
        return ['ok' => true];
    }

    // ------------------------------------------------------------------
    // Dashboard & export
    // ------------------------------------------------------------------

    /** @param int[]|null $storeScope null = every store (Admin); [] = none */
    public function dashboard(array $f, ?array $storeScope): array
    {
        $empty = ['totalRetur' => 0.0, 'totalReject' => 0.0, 'totalMutasi' => 0.0, 'nilaiRetur' => 0.0, 'nilaiReject' => 0.0,
            'tanggalTerbanyak' => null, 'tanggalTerbanyakQty' => 0.0, 'topRetur' => [], 'topReject' => [], 'perTanggal' => [], 'perToko' => []];
        if ($storeScope === []) {
            return $empty;
        }
        $items = $this->itemRows(['storeScope' => $storeScope] + $f);
        $out = $empty;
        $topRet = $topRej = $perTgl = $perToko = [];
        foreach ($items as $it) {
            if ($it['jenis'] === 'mutasi') {
                if ($it['itemStatus'] !== 'cancelled') {
                    $out['totalMutasi'] += $it['qty'];
                }
                continue;
            }
            $toko = $it['storeName'];
            $perToko[$toko] ??= ['nama' => $toko, 'retur' => 0.0, 'reject' => 0.0];
            if ($it['jenis'] === 'retur') {
                $out['totalRetur'] += $it['qty'];
                $out['nilaiRetur'] += $it['total'];
                $topRet[$it['productName']] = ($topRet[$it['productName']] ?? 0) + $it['qty'];
                $perTgl[$it['tanggal']] = ($perTgl[$it['tanggal']] ?? 0) + $it['qty'];
                $perToko[$toko]['retur'] += $it['qty'];
            } else {
                $out['totalReject'] += $it['qty'];
                $out['nilaiReject'] += $it['total'];
                $topRej[$it['productName']] = ($topRej[$it['productName']] ?? 0) + $it['qty'];
                $perToko[$toko]['reject'] += $it['qty'];
            }
        }
        $top = static function (array $m): array {
            arsort($m);
            $r = [];
            foreach (array_slice($m, 0, 10, true) as $k => $v) {
                $r[] = ['nama' => (string) $k, 'qty' => $v];
            }
            return $r;
        };
        $out['topRetur'] = $top($topRet);
        $out['topReject'] = $top($topRej);
        ksort($perTgl);
        foreach ($perTgl as $d => $q) {
            $out['perTanggal'][] = ['tanggal' => $d, 'qty' => $q];
            if ($q > $out['tanggalTerbanyakQty']) {
                $out['tanggalTerbanyakQty'] = $q;
                $out['tanggalTerbanyak'] = $d;
            }
        }
        usort($perToko, static fn ($a, $b) => ($b['retur'] + $b['reject']) <=> ($a['retur'] + $a['reject']));
        $out['perToko'] = array_values($perToko);
        return $out;
    }

    /** Same columns as the old "Data Retur Reject" sheet. */
    public function exportRows(array $f, ?array $storeScope): array
    {
        if ($storeScope === []) {
            return [];
        }
        return $this->itemRows(['storeScope' => $storeScope] + $f);
    }

    // ------------------------------------------------------------------
    // Shared item query
    // ------------------------------------------------------------------

    /**
     * Every item (retur_request + mutasi_request rows) matching the filter,
     * as one flat list. Filters: report (id), reports (ids), approved (bool),
     * factoryId, storeId, storeScope (ids), jenis, from, to.
     */
    private function itemRows(array $f): array
    {
        $where = [];
        $params = [];
        $common = function (string $alias, string $dateExpr, string $storeCol) use (&$f): array {
            $w = [];
            $p = [];
            if (isset($f['report'])) {
                $w[] = "{$alias}.store_report_id = ?";
                $p[] = (int) $f['report'];
            }
            if (!empty($f['reports'])) {
                $w[] = "{$alias}.store_report_id IN (" . implode(',', array_map('intval', $f['reports'])) . ')';
            }
            if (!empty($f['approved'])) {
                $w[] = "(sr.store_report_id IS NULL OR sr.status = 'disetujui')";
            }
            if (!empty($f['factoryId'])) {
                $w[] = 'd.factory_id = ?';
                $p[] = (int) $f['factoryId'];
            }
            if (!empty($f['storeId'])) {
                $w[] = "{$storeCol} = ?";
                $p[] = (int) $f['storeId'];
            }
            if (isset($f['storeScope']) && is_array($f['storeScope'])) {
                $w[] = "{$storeCol} IN (" . implode(',', array_map('intval', $f['storeScope'])) . ')';
            }
            if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['from'])) {
                $w[] = "{$dateExpr} >= ?";
                $p[] = $f['from'];
            }
            if (!empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['to'])) {
                $w[] = "{$dateExpr} <= ?";
                $p[] = $f['to'];
            }
            return [$w === [] ? '1=1' : implode(' AND ', $w), $p];
        };
        $jenis = $f['jenis'] ?? null;
        $rows = [];

        if ($jenis === null || $jenis === '' || $jenis === 'retur' || $jenis === 'reject') {
            [$w, $p] = $common('rr', 'rr.retur_date', 'rr.store_id');
            if ($jenis === 'retur' || $jenis === 'reject') {
                $w .= ' AND rr.jenis = ?';
                $p[] = $jenis === 'reject' ? 'reject_toko' : 'retur';
            }
            $st = $this->pdo->prepare(
                "SELECT 'retur' AS item_type, rr.retur_request_id AS item_id, rr.doc_no, rr.store_report_id, sr.doc_no AS report_no, sr.status AS report_status,
                        rr.retur_date AS tanggal, rr.store_id, s.canonical_name AS store_name, rr.product_id, p.name AS product_name, p.kategori, p.harga,
                        f.name AS factory_name, rr.qty, IF(rr.jenis = 'reject_toko', 'reject', 'retur') AS jenis, rr.reason AS alasan, rr.exp_date,
                        rr.notes, NULL AS dest_store_id, NULL AS dest_store_name, rr.status AS item_status, rr.tindak_lanjut,
                        COALESCE(rr.validasi_status, IF(rr.status = 'waiting_admin_verification', 'menunggu', IF(rr.status = 'rejected', 'tidak_sesuai', 'sesuai'))) AS validasi_status,
                        COALESCE(rr.validasi_note, rr.reject_reason) AS validasi_note, vu.full_name AS validasi_by_name, rr.validasi_at,
                        au.full_name AS approved_by_name, sr.approved_at, sr.approval_mode, rr.created_at,
                        (SELECT COUNT(*) FROM retur_request_evidence e WHERE e.retur_request_id = rr.retur_request_id) AS evidence_count,
                        (SELECT MIN(e.retur_request_evidence_id) FROM retur_request_evidence e WHERE e.retur_request_id = rr.retur_request_id) AS first_evidence_id
                 FROM retur_request rr
                 INNER JOIN store s ON s.store_id = rr.store_id
                 INNER JOIN product p ON p.product_id = rr.product_id
                 LEFT JOIN division d ON d.division_id = p.division_id
                 LEFT JOIN factory f ON f.factory_id = d.factory_id
                 LEFT JOIN store_report sr ON sr.store_report_id = rr.store_report_id
                 LEFT JOIN users au ON au.user_id = sr.approved_by
                 LEFT JOIN users vu ON vu.user_id = COALESCE(rr.validasi_by, rr.verified_by)
                 WHERE {$w}"
            );
            $st->execute($p);
            $rows = array_merge($rows, $st->fetchAll());
        }
        if ($jenis === null || $jenis === '' || $jenis === 'mutasi') {
            [$w, $p] = $common('mr', 'COALESCE(sr.report_date, DATE(mr.created_at))', 'mr.source_store_id');
            $st = $this->pdo->prepare(
                "SELECT 'mutasi' AS item_type, mr.mutasi_request_id AS item_id, mr.doc_no, mr.store_report_id, sr.doc_no AS report_no, sr.status AS report_status,
                        COALESCE(sr.report_date, DATE(mr.created_at)) AS tanggal, mr.source_store_id AS store_id, s.canonical_name AS store_name,
                        mr.product_id, p.name AS product_name, p.kategori, p.harga, f.name AS factory_name, mr.qty_requested AS qty, 'mutasi' AS jenis,
                        NULL AS alasan, NULL AS exp_date, mr.request_notes AS notes, mr.destination_store_id AS dest_store_id, ds.canonical_name AS dest_store_name,
                        mr.status AS item_status, NULL AS tindak_lanjut, COALESCE(mr.validasi_status, 'sesuai') AS validasi_status, mr.validasi_note,
                        vu.full_name AS validasi_by_name, mr.validasi_at, au.full_name AS approved_by_name, sr.approved_at, sr.approval_mode, mr.created_at,
                        (SELECT COUNT(*) FROM mutasi_request_evidence e WHERE e.mutasi_request_id = mr.mutasi_request_id) AS evidence_count,
                        NULL AS first_evidence_id
                 FROM mutasi_request mr
                 INNER JOIN store s ON s.store_id = mr.source_store_id
                 INNER JOIN store ds ON ds.store_id = mr.destination_store_id
                 INNER JOIN product p ON p.product_id = mr.product_id
                 LEFT JOIN division d ON d.division_id = p.division_id
                 LEFT JOIN factory f ON f.factory_id = d.factory_id
                 LEFT JOIN store_report sr ON sr.store_report_id = mr.store_report_id
                 LEFT JOIN users au ON au.user_id = sr.approved_by
                 LEFT JOIN users vu ON vu.user_id = mr.validasi_by
                 WHERE {$w} AND (mr.status <> 'cancelled' OR mr.validasi_status = 'tidak_sesuai')"
            );
            $st->execute($p);
            $rows = array_merge($rows, $st->fetchAll());
        }
        usort($rows, static fn ($a, $b) => [$b['tanggal'], $b['created_at']] <=> [$a['tanggal'], $a['created_at']]);
        return array_map(static function ($r) {
            $qty = (float) $r['qty'];
            $harga = (float) ($r['harga'] ?? 0);
            return [
                'itemType' => $r['item_type'], 'itemId' => (int) $r['item_id'], 'docNo' => $r['doc_no'],
                'reportId' => $r['store_report_id'] !== null ? (int) $r['store_report_id'] : null, 'reportNo' => $r['report_no'],
                'reportStatus' => $r['report_status'], 'tanggal' => $r['tanggal'],
                'storeId' => (int) $r['store_id'], 'storeName' => $r['store_name'],
                'productId' => (int) $r['product_id'], 'productName' => $r['product_name'], 'kategori' => $r['kategori'],
                'pabrik' => $r['factory_name'], 'qty' => $qty, 'harga' => $harga, 'total' => $qty * $harga,
                'jenis' => $r['jenis'], 'alasan' => $r['jenis'] === 'retur' && $r['alasan'] === 'Tidak laku' ? null : $r['alasan'],
                'expDate' => $r['exp_date'], 'notes' => $r['notes'],
                'destinationStoreId' => $r['dest_store_id'] !== null ? (int) $r['dest_store_id'] : null, 'destinationStoreName' => $r['dest_store_name'],
                'itemStatus' => $r['item_status'], 'tindakLanjut' => $r['tindak_lanjut'],
                'validasiStatus' => $r['validasi_status'], 'validasiNote' => $r['validasi_note'], 'validasiByName' => $r['validasi_by_name'], 'validasiAt' => $r['validasi_at'],
                'approvedByName' => $r['approved_by_name'], 'approvedAt' => $r['approved_at'], 'approvalMode' => $r['approval_mode'],
                'createdAt' => $r['created_at'], 'evidenceCount' => (int) $r['evidence_count'],
                'firstEvidenceId' => $r['first_evidence_id'] !== null ? (int) $r['first_evidence_id'] : null,
            ];
        }, $rows);
    }

    private function reportDto(array $r, array $items): array
    {
        $total = 0.0;
        foreach ($items as $it) {
            $total += $it['total'];
        }
        $dest = null;
        foreach ($items as $it) {
            if ($it['jenis'] === 'mutasi') {
                $dest = ['storeId' => $it['destinationStoreId'], 'name' => $it['destinationStoreName']];
                break;
            }
        }
        return [
            'reportId' => (int) $r['store_report_id'], 'docNo' => $r['doc_no'], 'storeId' => (int) $r['store_id'], 'storeName' => $r['store_name'],
            'tanggal' => $r['report_date'], 'status' => $r['status'], 'approvalMode' => $r['approval_mode'],
            'approvedByName' => $r['approved_by_name'] ?? null, 'approvedAt' => $r['approved_at'],
            'createdAt' => $r['created_at'], 'updatedAt' => $r['updated_at'], 'version' => (int) $r['version'],
            'destination' => $dest, 'total' => $total, 'items' => $items,
            'editCount' => (int) ($r['edit_count'] ?? 0),
            // The store may edit (replace) a transaction only while it waits for its Atasan.
            'canEdit' => $r['status'] === 'menunggu_atasan',
        ];
    }
}
