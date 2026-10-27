<?php

declare(strict_types=1);

/**
 * Amor Factory — RESET DATA TRANSAKSI (one-time tool, shipped separately as
 * amor-factory-alat-reset.zip, NEVER inside the normal package).
 *
 * Empties every transactional table (PO, produksi, serah terima, FG, stok, DO, kiriman,
 * konfirmasi toko, lapor barang, mutasi, pesanan khusus, replacement,
 * invoice, nota potongan, nomor dokumen, idempotency log, transactional
 * audit rows) and the uploaded evidence photos, so live can start real
 * operations clean after testing/trial.
 *
 * KEPT untouched: factories, divisions, locations, products + aliases,
 * stores + aliases, users + roles + access, Portal Bakery links (tokens),
 * special-order catalog, master settings, migration maps, schema
 * migrations, and audit rows about those master records.
 *
 * Safety:
 *   - real ADMIN session + CSRF token;
 *   - the operator must type the exact phrase AND re-enter their password;
 *   - before deleting anything, every row of every table it is about to
 *     empty is written to api/app/backups/reset-<timestamp>.sql.gz
 *     (api/app/ is deny-all over HTTP) — restorable via phpMyAdmin Import;
 *   - all deletes run in one transaction;
 *   - after a successful reset this directory deletes itself, so the
 *     button cannot be pressed twice by accident.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Auth;
use Amor\Api\Config;
use Amor\Api\Database;

const RESET_PHRASE = 'HAPUS DATA TRANSAKSI';

/** Order does not matter (FK checks are off inside the reset) but is kept child-first for readability. */
const TRANSACTION_TABLES = [
    'nota_potongan_item', 'nota_potongan', 'payment', 'invoice_mutasi', 'invoice_shipment', 'invoice_item', 'invoice',
    'retur_request_evidence', 'retur_request', 'mutasi_request_evidence', 'mutasi_request',
    'shipment_email_delivery', 'shipment_receipt_evidence', 'shipment_receipt_item', 'shipment_receipt', 'shipment_receipt_token',
    'replacement_do_shipment_item', 'replacement_do', 'replacement_demand_fg_allocation', 'replacement_demand',
    'special_order_do_shipment_item', 'special_order_do_item', 'special_order_do', 'special_order_fg_allocation',
    'special_order_attachment', 'special_order_item', 'special_order',
    'driver_route_stop', 'driver_route', 'dispatch_claim', 'delivery_receipt_token',
    'shipment_item', 'shipment', 'delivery_order_item', 'delivery_order',
    'stock_transfer', 'stock_adjustment', 'stock_ledger', 'stock_balance', 'store_fg_balance',
    'fg_store_packing_submission', 'fg_item', 'fg_batch_source', 'fg_batch',
    'production_handover_item', 'production_handover', 'production_item', 'production_run',
    'po_store_item', 'po_item', 'po_import', 'po_closure', 'po_batch',
    'customer_order_item', 'customer_order', 'retail_sale', 'reject_note', 'return_note',
    'document_sequence', 'idempotency_log',
];

/** audit_log rows about these record types are master-data history and are kept. */
const MASTER_AUDIT_TYPES = ['user', 'users', 'product', 'product_alias', 'store', 'store_alias', 'division', 'factory', 'location', 'store_portal_token', 'master_setting', 'role', 'roles'];

const UPLOAD_DIRS = ['receipt-evidence', 'retur-evidence', 'mutasi-evidence', 'special-order-attachment'];

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
function page(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc($title) . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:760px;margin:2rem auto;padding:0 16px;color:#0f172a}'
        . 'table{border-collapse:collapse;width:100%;font-size:14px}td,th{border:1px solid #cbd5e1;padding:4px 8px;text-align:left}'
        . '.box{border-radius:10px;padding:12px 16px;margin:12px 0}.red{background:#fee2e2;border:1px solid #ef4444}.ok{background:#dcfce7;border:1px solid #16a34a}'
        . 'input[type=text],input[type=password]{width:100%;padding:8px;font-size:16px;margin:4px 0 12px;box-sizing:border-box}'
        . 'button{background:#dc2626;color:#fff;border:0;border-radius:8px;padding:12px 18px;font-size:16px;font-weight:700;cursor:pointer}</style>'
        . '</head><body>' . $body . '</body></html>';
    exit;
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
$existing = array_flip($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
$tables = array_values(array_filter(TRANSACTION_TABLES, static fn ($t) => isset($existing[$t])));
$auditWhere = 'record_type NOT IN (' . implode(',', array_map(static fn ($t) => $pdo->quote($t), MASTER_AUDIT_TYPES)) . ')';

$counts = [];
foreach ($tables as $t) {
    $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
}
$counts['audit_log (transaksi)'] = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE {$auditWhere}")->fetchColumn();
$files = 0;
foreach (UPLOAD_DIRS as $d) {
    foreach (glob(__DIR__ . "/../uploads/{$d}/*") ?: [] as $f) {
        if (is_file($f) && basename($f) !== '.htaccess') { $files++; }
    }
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $okCsrf = hash_equals($csrf, (string) ($_POST['csrf'] ?? ''));
    $phrase = trim((string) ($_POST['phrase'] ?? ''));
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
    $stmt->execute([$adminId]);
    $okPass = password_verify((string) ($_POST['password'] ?? ''), (string) $stmt->fetchColumn());
    if (!$okCsrf) {
        $error = 'Sesi kedaluwarsa. Muat ulang halaman.';
    } elseif ($phrase !== RESET_PHRASE) {
        $error = 'Kalimat konfirmasi tidak sama persis.';
    } elseif (!$okPass) {
        $error = 'Password salah.';
    } else {
        // 1. Backup everything we are about to delete.
        $dir = __DIR__ . '/../app/backups';
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            page('Gagal', '<h1>Gagal membuat folder cadangan</h1><p>Tidak ada data yang dihapus.</p>');
        }
        $stamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Ymd-His');
        $backupPath = "{$dir}/reset-{$stamp}.sql.gz";
        $gz = gzopen($backupPath, 'wb6');
        if ($gz === false) {
            page('Gagal', '<h1>Gagal menulis cadangan</h1><p>Tidak ada data yang dihapus.</p>');
        }
        gzwrite($gz, "-- Amor Factory: cadangan sebelum Reset Data Transaksi {$stamp}\nSET FOREIGN_KEY_CHECKS=0;\n");
        $dump = static function (string $table, string $where) use ($pdo, $gz): void {
            $q = $pdo->query("SELECT * FROM `{$table}`" . ($where !== '' ? " WHERE {$where}" : ''));
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $cols = implode(',', array_map(static fn ($c) => "`{$c}`", array_keys($row)));
                $vals = implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row)));
                gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES ({$vals});\n");
            }
        };
        foreach ($tables as $t) {
            $dump($t, '');
        }
        $dump('audit_log', $auditWhere);
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);

        // 2. Delete, in one transaction.
        $deleted = [];
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $pdo->beginTransaction();
            foreach ($tables as $t) {
                $deleted[$t] = $pdo->exec("DELETE FROM `{$t}`");
            }
            $deleted['audit_log (transaksi)'] = $pdo->exec("DELETE FROM audit_log WHERE {$auditWhere}");
            $pdo->commit();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            page('Gagal', '<h1>Reset dibatalkan</h1><p>' . esc($e->getMessage()) . '</p><p>Tidak ada data yang dihapus (transaksi dibatalkan).</p>');
        }

        // 3. Uploaded evidence photos.
        $removed = 0;
        foreach (UPLOAD_DIRS as $d) {
            foreach (glob(__DIR__ . "/../uploads/{$d}/*") ?: [] as $f) {
                if (is_file($f) && basename($f) !== '.htaccess' && @unlink($f)) { $removed++; }
            }
        }

        Audit::write($pdo, null, $adminId, 'system.reset_transaksi', 'system', $stamp, 'ok', null, null,
            ['backup' => basename($backupPath), 'rows' => array_sum($deleted), 'files' => $removed]);

        // 4. Remove this tool.
        @unlink(__FILE__);
        @rmdir(__DIR__);

        $rows = '';
        foreach ($deleted as $t => $n) {
            if ($n > 0) { $rows .= '<tr><td>' . esc($t) . '</td><td>' . (int) $n . '</td></tr>'; }
        }
        page('Reset selesai', '<h1>Reset data transaksi selesai</h1>'
            . '<div class="box ok">Terhapus ' . array_sum($deleted) . ' baris dan ' . $removed . ' foto bukti. Master data, akun, dan link Portal Bakery tetap.</div>'
            . '<p>Cadangan: <code>api/app/backups/' . esc(basename($backupPath)) . '</code> (bisa di-Import lewat phpMyAdmin bila perlu).</p>'
            . '<p>Alat ini sudah menghapus dirinya sendiri.</p><table><tr><th>Tabel</th><th>Baris dihapus</th></tr>' . $rows . '</table>'
            . '<p><a href="../_ui-preview/?page=dashboard">Kembali ke Dashboard</a></p>');
    }
}

$rows = '';
$total = 0;
foreach ($counts as $t => $n) {
    $total += $n;
    if ($n > 0) { $rows .= '<tr><td>' . esc($t) . '</td><td>' . $n . '</td></tr>'; }
}
page('Reset Data Transaksi', '<h1>Reset Data Transaksi</h1>'
    . '<div class="box red"><b>Semua data transaksi akan dihapus:</b> PO, produksi, serah terima, FG &amp; stok, DO, kiriman, konfirmasi toko, lapor barang, mutasi, pesanan khusus, invoice, nota potongan, nomor dokumen, dan foto bukti.<br>'
    . '<b>Tetap ada:</b> pabrik, divisi, produk, toko, akun &amp; password, link Portal Bakery.<br>'
    . 'Sebelum menghapus, alat ini otomatis membuat cadangan. Tetap disarankan Export database lewat phpMyAdmin dulu.</div>'
    . ($error !== null ? '<div class="box red"><b>' . esc($error) . '</b></div>' : '')
    . '<p>Akan dihapus: <b>' . $total . ' baris</b> dan <b>' . $files . ' foto</b>.</p>'
    . ($rows !== '' ? '<table><tr><th>Tabel</th><th>Baris</th></tr>' . $rows . '</table>' : '<p>Tidak ada data transaksi.</p>')
    . '<form method="post" style="margin-top:20px">'
    . '<input type="hidden" name="csrf" value="' . esc($csrf) . '">'
    . '<label>Ketik persis: <b>' . esc(RESET_PHRASE) . '</b></label><input type="text" name="phrase" autocomplete="off">'
    . '<label>Password akun Admin Anda</label><input type="password" name="password" autocomplete="current-password">'
    . '<button type="submit">Hapus semua data transaksi</button></form>');
