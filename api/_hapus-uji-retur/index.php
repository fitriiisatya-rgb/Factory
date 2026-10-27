<?php

declare(strict_types=1);

/**
 * Amor Factory — HAPUS DATA UJI RETUR/REJECT/MUTASI (one-time tool, shipped
 * separately as amor-factory-alat-hapus-uji-retur.zip, NEVER inside the
 * normal package).
 *
 * Removes the Retur, Reject & Mutasi forms (store_report, migration 0026)
 * that were entered while trying the feature on live, together with
 * everything they created: their retur/reject/mutasi items and photos, the
 * Nota Potongan made for a Reject "Sesuai" or a completed Mutasi, and the
 * Pesanan Khusus "pengganti" made for a Reject "Ganti barang".
 *
 * The operator ticks which forms to remove; forms of the stores bound to
 * the trial Atasan account (atasan_uji) are pre-ticked. Production, FG, DO,
 * deliveries, invoices and master data are never touched.
 *
 * A form is refused (left as is, with the reason shown) when any of its
 * items is already inside an invoice: a mutasi billed to the destination,
 * a Nota Potongan already attached to an invoice, or a pengganti order
 * already allocated, put on a DO or invoiced. Void that invoice first.
 *
 * Safety: ADMIN session + CSRF, exact phrase + the admin's password,
 * a backup of every row it deletes in api/app/backups/hapus-uji-<ts>.sql.gz,
 * one transaction, and the tool deletes itself afterwards.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Dispatch\EvidenceUploader;

const HAPUS_PHRASE = 'HAPUS DATA UJI';
const TRIAL_ATASAN = 'atasan_uji';

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function page(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc($title) . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:860px;margin:2rem auto;padding:0 16px;color:#0f172a}'
        . 'table{border-collapse:collapse;width:100%;font-size:14px}td,th{border:1px solid #cbd5e1;padding:4px 8px;text-align:left;vertical-align:top}'
        . '.scroll{overflow-x:auto}.box{border-radius:10px;padding:12px 16px;margin:12px 0}.red{background:#fee2e2;border:1px solid #ef4444}.ok{background:#dcfce7;border:1px solid #16a34a}.muted{color:#64748b}'
        . 'input[type=text],input[type=password]{width:100%;padding:8px;font-size:16px;margin:4px 0 12px;box-sizing:border-box}'
        . 'button{background:#dc2626;color:#fff;border:0;border-radius:8px;padding:12px 18px;font-size:16px;font-weight:700;cursor:pointer}</style>'
        . '</head><body>' . $body . '</body></html>';
    exit;
}

/** @return int[] */
function col(PDO $pdo, string $sql, array $args = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function inList(array $ids): string
{
    return $ids === [] ? '0' : implode(',', array_map('intval', $ids));
}

try {
    Config::load();
} catch (\Throwable $e) {
    page('Konfigurasi', '<h1>Konfigurasi belum lengkap</h1><p>' . esc($e->getMessage()) . '</p>');
}
Auth::bootSession();
if (Auth::currentUserId() === null) {
    page('Login diperlukan', '<h1>Login diperlukan</h1><p>Login sebagai ADMIN di <a href="../_admin-login/">../_admin-login/</a>, lalu buka ulang halaman ini.</p>');
}
try {
    $adminId = Auth::requireRole('ADMIN');
} catch (ApiException $e) {
    page('Akses ditolak', '<h1>Akses ditolak</h1><p>Halaman ini hanya untuk ADMIN.</p>');
}

$pdo = Database::pdo();
$csrf = (string) $_SESSION['csrf_token'];
if (!in_array('store_report', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), true)) {
    page('Belum perlu', '<h1>Migrasi 0026 belum diterapkan</h1><p>Belum ada data Retur, Reject &amp; Mutasi versi baru.</p>');
}

$trialUserId = (int) ($pdo->query('SELECT user_id FROM users WHERE username = ' . $pdo->quote(TRIAL_ATASAN))->fetchColumn() ?: 0);
$trialStores = $trialUserId > 0 ? col($pdo, 'SELECT store_id FROM user_store_access WHERE user_id = ?', [$trialUserId]) : [];

/** Everything one form created, and why it cannot be removed (if so). */
function plan(PDO $pdo, int $reportId): array
{
    $retur = col($pdo, 'SELECT retur_request_id FROM retur_request WHERE store_report_id = ?', [$reportId]);
    $mutasi = col($pdo, 'SELECT mutasi_request_id FROM mutasi_request WHERE store_report_id = ?', [$reportId]);
    if ($mutasi !== []) {
        $mutasi = array_values(array_unique(array_merge($mutasi,
            col($pdo, 'SELECT mutasi_request_id FROM mutasi_request WHERE reversal_of_mutasi_request_id IN (' . inList($mutasi) . ')'))));
    }
    $nota = col($pdo, 'SELECT nota_potongan_id FROM nota_potongan WHERE retur_request_id IN (' . inList($retur) . ') OR mutasi_request_id IN (' . inList($mutasi) . ')');
    $orders = col($pdo, 'SELECT pengganti_order_id FROM retur_request WHERE pengganti_order_id IS NOT NULL AND retur_request_id IN (' . inList($retur) . ')');
    $orderItems = col($pdo, 'SELECT special_order_item_id FROM special_order_item WHERE special_order_id IN (' . inList($orders) . ')');

    $why = [];
    if (col($pdo, 'SELECT COUNT(*) FROM invoice_mutasi WHERE mutasi_request_id IN (' . inList($mutasi) . ')')[0] > 0) {
        $why[] = 'mutasi sudah masuk invoice toko tujuan';
    }
    if (col($pdo, 'SELECT COUNT(*) FROM nota_potongan WHERE invoice_id IS NOT NULL AND nota_potongan_id IN (' . inList($nota) . ')')[0] > 0) {
        $why[] = 'Nota Potongan sudah menempel di invoice';
    }
    if ($orders !== []) {
        $used = col($pdo, 'SELECT COUNT(*) FROM special_order_fg_allocation WHERE special_order_id IN (' . inList($orders) . ')')[0]
            + col($pdo, 'SELECT COUNT(*) FROM special_order_do WHERE special_order_id IN (' . inList($orders) . ')')[0]
            + col($pdo, 'SELECT COUNT(*) FROM invoice_item WHERE special_order_item_id IN (' . inList($orderItems) . ')')[0];
        if ($used > 0) {
            $why[] = 'pesanan pengganti sudah dialokasikan FG / masuk DO / invoice';
        }
    }
    return ['retur' => $retur, 'mutasi' => $mutasi, 'nota' => $nota, 'orders' => $orders, 'why' => $why];
}

$reports = $pdo->query(
    'SELECT sr.store_report_id, sr.doc_no, sr.report_date, sr.status, sr.store_id, s.canonical_name AS store_name, sr.created_at,
            (SELECT COUNT(*) FROM retur_request r WHERE r.store_report_id = sr.store_report_id AND r.jenis = \'reject_toko\') AS n_reject,
            (SELECT COUNT(*) FROM retur_request r WHERE r.store_report_id = sr.store_report_id AND r.jenis <> \'reject_toko\') AS n_retur,
            (SELECT COUNT(*) FROM mutasi_request m WHERE m.store_report_id = sr.store_report_id) AS n_mutasi
       FROM store_report sr JOIN store s ON s.store_id = sr.store_id
      ORDER BY sr.store_report_id'
)->fetchAll(PDO::FETCH_ASSOC);
$plans = [];
foreach ($reports as $r) {
    $plans[(int) $r['store_report_id']] = plan($pdo, (int) $r['store_report_id']);
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $picked = array_values(array_intersect(array_map('intval', (array) ($_POST['report'] ?? [])), array_keys($plans)));
    $okCsrf = hash_equals($csrf, (string) ($_POST['csrf'] ?? ''));
    $phrase = trim((string) ($_POST['phrase'] ?? ''));
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
    $stmt->execute([$adminId]);
    $okPass = password_verify((string) ($_POST['password'] ?? ''), (string) $stmt->fetchColumn());
    $blocked = array_values(array_filter($picked, static fn ($id) => $plans[$id]['why'] !== []));
    $offAtasan = !empty($_POST['nonaktif_atasan']) && $trialUserId > 0;
    if (!$okCsrf) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman.';
    } elseif ($picked === [] && !$offAtasan) {
        $error = 'Belum ada yang dipilih.';
    } elseif ($blocked !== []) {
        $error = 'Ada form yang tidak bisa dihapus (lihat kolom Keterangan). Hilangkan centangnya atau batalkan invoice-nya dulu.';
    } elseif ($phrase !== HAPUS_PHRASE) {
        $error = 'Kalimat konfirmasi tidak sama persis.';
    } elseif (!$okPass) {
        $error = 'Password salah.';
    } else {
        $ids = ['store_report' => $picked, 'retur_request' => [], 'mutasi_request' => [], 'nota_potongan' => [], 'special_order' => []];
        foreach ($picked as $id) {
            $ids['retur_request'] = array_merge($ids['retur_request'], $plans[$id]['retur']);
            $ids['mutasi_request'] = array_merge($ids['mutasi_request'], $plans[$id]['mutasi']);
            $ids['nota_potongan'] = array_merge($ids['nota_potongan'], $plans[$id]['nota']);
            $ids['special_order'] = array_merge($ids['special_order'], $plans[$id]['orders']);
        }
        /** table => WHERE, child-first so the delete order is also FK-safe. */
        $targets = [
            'nota_potongan_item' => 'nota_potongan_id IN (' . inList($ids['nota_potongan']) . ')',
            'nota_potongan' => 'nota_potongan_id IN (' . inList($ids['nota_potongan']) . ')',
            'retur_request_evidence' => 'retur_request_id IN (' . inList($ids['retur_request']) . ')',
            'retur_request' => 'retur_request_id IN (' . inList($ids['retur_request']) . ')',
            'special_order_attachment' => 'special_order_id IN (' . inList($ids['special_order']) . ')',
            'special_order_item' => 'special_order_id IN (' . inList($ids['special_order']) . ')',
            'special_order' => 'special_order_id IN (' . inList($ids['special_order']) . ')',
            'mutasi_request_evidence' => 'mutasi_request_id IN (' . inList($ids['mutasi_request']) . ')',
            'mutasi_request' => 'mutasi_request_id IN (' . inList($ids['mutasi_request']) . ')',
            'store_report' => 'store_report_id IN (' . inList($ids['store_report']) . ')',
        ];
        $files = [];
        foreach ([['retur_request_evidence', 'retur_request', 'retur-evidence'], ['mutasi_request_evidence', 'mutasi_request', 'mutasi-evidence']] as [$t, $k, $ctx]) {
            foreach ($pdo->query("SELECT file_path FROM {$t} WHERE {$targets[$t]}")->fetchAll(PDO::FETCH_COLUMN) as $f) {
                $files[] = EvidenceUploader::absolutePath((string) $f, $ctx);
            }
        }
        foreach ($pdo->query('SELECT file_path FROM special_order_attachment WHERE ' . $targets['special_order_attachment'])->fetchAll(PDO::FETCH_COLUMN) as $f) {
            $files[] = EvidenceUploader::absolutePath((string) $f, 'special-order-attachment');
        }

        // 1. Backup every row about to be deleted.
        $dir = __DIR__ . '/../app/backups';
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            page('Gagal', '<h1>Gagal membuat folder cadangan</h1><p>Tidak ada data yang dihapus.</p>');
        }
        $stamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Ymd-His');
        $backupPath = "{$dir}/hapus-uji-{$stamp}.sql.gz";
        $gz = gzopen($backupPath, 'wb6');
        if ($gz === false) {
            page('Gagal', '<h1>Gagal menulis cadangan</h1><p>Tidak ada data yang dihapus.</p>');
        }
        gzwrite($gz, "-- Amor Factory: cadangan sebelum Hapus Data Uji Retur/Reject/Mutasi {$stamp}\nSET FOREIGN_KEY_CHECKS=0;\n");
        $dump = static function (string $table, string $where) use ($pdo, $gz): void {
            $q = $pdo->query("SELECT * FROM `{$table}` WHERE {$where}");
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $cols = implode(',', array_map(static fn ($c) => "`{$c}`", array_keys($row)));
                $vals = implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row)));
                gzwrite($gz, "REPLACE INTO `{$table}` ({$cols}) VALUES ({$vals});\n");
            }
        };
        foreach (array_reverse($targets, true) as $t => $w) {
            $dump($t, $w);
        }
        if ($offAtasan) {
            $dump('users', 'user_id = ' . $trialUserId);
            $dump('user_store_access', 'user_id = ' . $trialUserId);
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);

        // 2. Delete, in one transaction (child-first; reversal mutasi rows first).
        $deleted = [];
        try {
            $pdo->beginTransaction();
            foreach ($targets as $t => $w) {
                if ($t === 'mutasi_request') {
                    $deleted[$t] = (int) $pdo->exec("DELETE FROM mutasi_request WHERE reversal_of_mutasi_request_id IS NOT NULL AND {$w}");
                }
                $deleted[$t] = ($deleted[$t] ?? 0) + (int) $pdo->exec("DELETE FROM `{$t}` WHERE {$w}");
            }
            if ($offAtasan) {
                $deleted['user_store_access'] = (int) $pdo->exec('DELETE FROM user_store_access WHERE user_id = ' . $trialUserId);
                $pdo->exec('UPDATE users SET active = 0 WHERE user_id = ' . $trialUserId);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            page('Gagal', '<h1>Penghapusan dibatalkan</h1><p>' . esc($e->getMessage()) . '</p><p>Tidak ada data yang dihapus (transaksi dibatalkan).</p>');
        }

        // 3. Photos.
        $removed = 0;
        foreach (array_unique($files) as $f) {
            if (is_file($f) && @unlink($f)) { $removed++; }
        }

        Audit::write($pdo, null, $adminId, 'system.hapus_uji_retur', 'system', $stamp, 'ok', null, null,
            ['backup' => basename($backupPath), 'forms' => $picked, 'rows' => array_sum($deleted), 'files' => $removed, 'atasanUjiOff' => $offAtasan]);

        // 4. Remove this tool.
        @unlink(__FILE__);
        @rmdir(__DIR__);

        $rows = '';
        foreach ($deleted as $t => $n) {
            if ($n > 0) { $rows .= '<tr><td>' . esc($t) . '</td><td>' . $n . '</td></tr>'; }
        }
        page('Selesai', '<h1>Data uji sudah dihapus</h1>'
            . '<div class="box ok">' . count($picked) . ' form, ' . array_sum($deleted) . ' baris dan ' . $removed . ' foto dihapus.'
            . ($offAtasan ? ' Akun <b>' . TRIAL_ATASAN . '</b> dinonaktifkan.' : '') . '</div>'
            . '<p>Cadangan: <code>api/app/backups/' . esc(basename($backupPath)) . '</code> (bisa di-Import lewat phpMyAdmin bila perlu).</p>'
            . '<p>Alat ini sudah menghapus dirinya sendiri.</p>'
            . ($rows !== '' ? '<table><tr><th>Tabel</th><th>Baris dihapus</th></tr>' . $rows . '</table>' : '')
            . '<p><a href="../_ui-preview/?page=validasi-toko">Kembali ke Validasi Toko</a></p>');
    }
}

$label = ['menunggu_atasan' => 'Menunggu Atasan', 'disetujui' => 'Disetujui'];
$rows = '';
foreach ($reports as $r) {
    $id = (int) $r['store_report_id'];
    $p = $plans[$id];
    $checked = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? in_array($id, array_map('intval', (array) ($_POST['report'] ?? [])), true)
        : in_array((int) $r['store_id'], $trialStores, true) && $p['why'] === [];
    $isi = [];
    foreach (['n_retur' => 'retur', 'n_reject' => 'reject', 'n_mutasi' => 'mutasi'] as $k => $w) {
        if ((int) $r[$k] > 0) { $isi[] = (int) $r[$k] . ' ' . $w; }
    }
    $extra = [];
    if ($p['nota'] !== []) { $extra[] = count($p['nota']) . ' Nota Potongan'; }
    if ($p['orders'] !== []) { $extra[] = count($p['orders']) . ' pesanan pengganti'; }
    $rows .= '<tr><td><input type="checkbox" name="report[]" value="' . $id . '"' . ($checked ? ' checked' : '') . ($p['why'] !== [] ? ' disabled' : '') . '></td>'
        . '<td>' . esc((string) $r['doc_no']) . '<br><span class="muted">' . esc((string) $r['report_date']) . '</span></td>'
        . '<td>' . esc((string) $r['store_name']) . '</td>'
        . '<td>' . esc(implode(', ', $isi) ?: '—') . '<br><span class="muted">' . esc($label[$r['status']] ?? (string) $r['status']) . '</span></td>'
        . '<td>' . ($p['why'] !== [] ? '<b style="color:#b91c1c">Tidak bisa: ' . esc(implode('; ', $p['why'])) . '</b>' : esc($extra !== [] ? 'ikut terhapus: ' . implode(', ', $extra) : '')) . '</td></tr>';
}

page('Hapus Data Uji Retur', '<h1>Hapus Data Uji Retur, Reject &amp; Mutasi</h1>'
    . '<div class="box red"><b>Yang dihapus:</b> form Retur, Reject &amp; Mutasi yang dicentang, beserta foto, Nota Potongan, dan pesanan pengganti yang dibuat dari form itu.<br>'
    . '<b>Tidak tersentuh:</b> PO, produksi, FG, DO, kiriman, invoice, master data, dan form yang tidak dicentang.<br>'
    . 'Form toko binaan akun <b>' . TRIAL_ATASAN . '</b> sudah dicentang otomatis. Periksa lagi sebelum menekan tombol. Sebelum menghapus, alat ini otomatis membuat cadangan.</div>'
    . ($error !== null ? '<div class="box red"><b>' . esc($error) . '</b></div>' : '')
    . '<form method="post" style="margin-top:20px">'
    . '<input type="hidden" name="csrf" value="' . esc($csrf) . '">'
    . ($rows !== ''
        ? '<div class="scroll"><table><tr><th></th><th>No. form</th><th>Toko</th><th>Isi</th><th>Keterangan</th></tr>' . $rows . '</table></div>'
        : '<p>Belum ada form Retur, Reject &amp; Mutasi.</p>')
    . ($trialUserId > 0 ? '<p><label><input type="checkbox" name="nonaktif_atasan" value="1" checked> Nonaktifkan juga akun <b>' . TRIAL_ATASAN . '</b></label></p>' : '')
    . '<label>Ketik persis: <b>' . esc(HAPUS_PHRASE) . '</b></label><input type="text" name="phrase" autocomplete="off">'
    . '<label>Password akun Admin Anda</label><input type="password" name="password" autocomplete="current-password">'
    . '<button type="submit">Hapus data uji yang dicentang</button></form>');
